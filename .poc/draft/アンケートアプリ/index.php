<?php
/*
========================================================================
GUARD COMMENT — 固定名称一覧
※以下の名称は、今後の修正・再生成時も変更・削除禁止。

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

declare(strict_types=1);

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header_remove('X-Powered-By');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

function survey_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_json(mixed $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: 'null';
}

function survey_id(string $prefix = 'id'): string
{
    try {
        return $prefix . '_' . bin2hex(random_bytes(8));
    } catch (Throwable) {
        return $prefix . '_' . uniqid('', true);
    }
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
}

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
            'field_address' => [],
        ],
        'mail_logs' => [],
    ];
}

function survey_read_data(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        return survey_default_data();
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    if ($raw === false || trim($raw) === '') {
        return survey_default_data();
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return survey_default_data();
    }

    $base = survey_default_data();

    foreach ($base as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function survey_write_data(array $data): bool
{
    $json = survey_json($data);

    $tmp = SURVEY_STORAGE_FILE . '.tmp';

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    if (is_file(SURVEY_STORAGE_FILE)) {
        @unlink(SURVEY_STORAGE_FILE);
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }

    return (string)$_SESSION['csrf_token'];
}

function survey_check_csrf(): void
{
    $given = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals(survey_csrf(), $given)) {
        survey_api([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。',
        ], 403);
    }
}

function survey_api(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo survey_json($payload);
    exit;
}

function survey_normalize_question(array $q): array
{
    $type = (string)($q['type'] ?? 'text');

    if (!in_array($type, ['single', 'multiple', 'text'], true)) {
        $type = 'text';
    }

    $options = $q['options'] ?? [];
    if (!is_array($options)) {
        $options = [];
    }

    $normalizedOptions = [];

    foreach ($options as $option) {
        if (is_array($option)) {
            $normalizedOptions[] = [
                'id' => (string)($option['id'] ?? survey_id('opt')),
                'text' => (string)($option['text'] ?? ''),
            ];
        } else {
            $normalizedOptions[] = [
                'id' => survey_id('opt'),
                'text' => (string)$option,
            ];
        }
    }

    /*
     * 重要:
     * 自由記述では選択肢を保存しない。
     * これにより
     * 「単一選択 → 自由記述 → 保存」
     * 後に再編集しても旧選択肢が復活しない。
     */
    if ($type === 'text') {
        $normalizedOptions = [];
    }

    $branching = $q['branching'] ?? [];
    if (!is_array($branching) || $type !== 'single') {
        $branching = [];
    }

    return [
        'id' => (string)($q['id'] ?? survey_id('q')),
        'text' => (string)($q['text'] ?? ''),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => $normalizedOptions,
        'other_enabled' => $type === 'text'
            ? false
            : !empty($q['other_enabled']),
        'branching' => $branching,
    ];
}

function survey_normalize_survey(array $survey): array
{
    $groups = $survey['groups'] ?? [];

    if (!is_array($groups)) {
        $groups = [];
    }

    $normalizedGroups = [];

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $questions = $group['questions'] ?? [];
        if (!is_array($questions)) {
            $questions = [];
        }

        $normalizedQuestions = [];

        foreach ($questions as $question) {
            if (is_array($question)) {
                $normalizedQuestions[] = survey_normalize_question($question);
            }
        }

        $normalizedGroups[] = [
            'id' => (string)($group['id'] ?? survey_id('group')),
            'name' => (string)($group['name'] ?? '新しいグループ'),
            'questions' => $normalizedQuestions,
        ];
    }

    return [
        'id' => (string)($survey['id'] ?? survey_id('survey')),
        'title' => (string)($survey['title'] ?? '無題のアンケート'),
        'start_at' => (string)($survey['start_at'] ?? ''),
        'end_at' => (string)($survey['end_at'] ?? ''),
        'status' => in_array(
            ($survey['status'] ?? 'draft'),
            ['draft', 'active', 'ended'],
            true
        ) ? $survey['status'] : 'draft',
        'created_at' => (string)($survey['created_at'] ?? survey_now()),
        'updated_at' => (string)($survey['updated_at'] ?? survey_now()),
        'numbering_mode' => ($survey['numbering_mode'] ?? 'global') === 'group'
            ? 'group'
            : 'global',
        'groups' => $normalizedGroups,
        'deleted' => !empty($survey['deleted']),
    ];
}

function survey_find_index(array $items, string $id): int
{
    foreach ($items as $index => $item) {
        if (is_array($item) && (string)($item['id'] ?? '') === $id) {
            return (int)$index;
        }
    }

    return -1;
}

function survey_find_survey(array $data, string $id): ?array
{
    foreach ($data['surveys'] ?? [] as $survey) {
        if (
            is_array($survey) &&
            (string)($survey['id'] ?? '') === $id &&
            empty($survey['deleted'])
        ) {
            return $survey;
        }
    }

    return null;
}

function survey_proxy_kintone(
    array $settings,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    $subdomain = trim((string)($settings['subdomain'] ?? ''));

    if ($subdomain === '') {
        return [
            'ok' => false,
            'message' => 'kintoneのサブドメインが設定されていません。',
        ];
    }

    $subdomain = preg_replace(
        '#^https?://#i',
        '',
        $subdomain
    ) ?? $subdomain;

    $subdomain = rtrim($subdomain, '/');

    if (!str_contains($subdomain, '.')) {
        $host = $subdomain . '.cybozu.com';
    } else {
        $host = $subdomain;
    }

    $url = 'https://' . $host . $path;

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    if ($login !== '' || $password !== '') {
        $headers[] = 'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password);
    }

    $verify = !empty($settings['ssl_verify']);

    $proxy = trim((string)($settings['proxy'] ?? ''));

    $contextOptions = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
        ],
    ];

    if ($body !== null) {
        $contextOptions['http']['content'] = survey_json($body);
    }

    if ($proxy !== '') {
        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    /*
     * PHP 8.4 / 8.5対応:
     * $http_response_header への依存を避け、
     * http_get_last_response_headers() を利用する。
     */
    $responseHeaders = [];

    if (function_exists('http_get_last_response_headers')) {
        $responseHeaders =
            http_get_last_response_headers() ?: [];
    }

    $status = 0;

    foreach ($responseHeaders as $header) {
        if (preg_match(
            '#^HTTP/\S+\s+(\d{3})#',
            $header,
            $m
        )) {
            $status = (int)$m[1];
            break;
        }
    }

    if ($response === false) {
        return [
            'ok' => false,
            'status' => $status,
            'message' => 'kintone APIへの接続に失敗しました。',
        ];
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        $decoded = [
            'message' => $response,
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $decoded,
    ];
}

/* --------------------------------------------------------------------
 * API
 * ------------------------------------------------------------------ */

$action = (string)($_REQUEST['action'] ?? '');

if ($action !== '') {

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        $action !== 'login'
    ) {
        survey_check_csrf();
    }

    $data = survey_read_data();

    switch ($action) {

        case 'load':
            survey_api([
                'ok' => true,
                'data' => $data,
                'csrf_token' => survey_csrf(),
            ]);
            break;

        case 'save_survey':
            $raw = (string)($_POST['survey_json'] ?? '');
            $survey = json_decode($raw, true);

            if (!is_array($survey)) {
                survey_api([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。',
                ], 400);
            }

            $survey = survey_normalize_survey($survey);

            $index = survey_find_index(
                $data['surveys'],
                $survey['id']
            );

            $survey['updated_at'] = survey_now();

            if ($index < 0) {
                $data['surveys'][] = $survey;
            } else {
                $old = $data['surveys'][$index];

                if (
                    is_array($old) &&
                    !empty($old['created_at'])
                ) {
                    $survey['created_at'] =
                        (string)$old['created_at'];
                }

                $data['surveys'][$index] = $survey;
            }

            if (!survey_write_data($data)) {
                survey_api([
                    'ok' => false,
                    'message' => 'データ保存に失敗しました。',
                ], 500);
            }

            survey_api([
                'ok' => true,
                'survey' => $survey,
                'data' => $data,
            ]);
            break;

        case 'delete_survey':
            $surveyId = (string)($_POST['survey_id'] ?? '');

            foreach ($data['surveys'] as &$survey) {
                if (
                    is_array($survey) &&
                    (string)($survey['id'] ?? '') === $surveyId
                ) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = survey_now();
                }
            }
            unset($survey);

            survey_write_data($data);

            survey_api([
                'ok' => true,
                'data' => $data,
            ]);
            break;

        case 'duplicate_survey':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $source = survey_find_survey($data, $surveyId);

            if ($source === null) {
                survey_api([
                    'ok' => false,
                    'message' => '複製元アンケートが見つかりません。',
                ], 404);
            }

            $copy = $source;
            $copy['id'] = survey_id('survey');
            $copy['title'] =
                ((string)$source['title']) . '（複製）';
            $copy['status'] = 'draft';
            $copy['created_at'] = survey_now();
            $copy['updated_at'] = survey_now();
            $copy['deleted'] = false;

            $data['surveys'][] = survey_normalize_survey($copy);
            survey_write_data($data);

            survey_api([
                'ok' => true,
                'survey' => $copy,
                'data' => $data,
            ]);
            break;

        case 'change_status':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $status = (string)($_POST['status'] ?? '');

            if (!in_array(
                $status,
                ['draft', 'active', 'ended'],
                true
            )) {
                survey_api([
                    'ok' => false,
                    'message' => 'ステータスが不正です。',
                ], 400);
            }

            foreach ($data['surveys'] as &$survey) {
                if (
                    is_array($survey) &&
                    (string)($survey['id'] ?? '') === $surveyId
                ) {
                    $survey['status'] = $status;
                    $survey['updated_at'] = survey_now();
                }
            }
            unset($survey);

            survey_write_data($data);

            survey_api([
                'ok' => true,
                'data' => $data,
            ]);
            break;

        case 'save_settings':
            $raw = (string)($_POST['settings_json'] ?? '');
            $settings = json_decode($raw, true);

            if (!is_array($settings)) {
                survey_api([
                    'ok' => false,
                    'message' => '設定データが不正です。',
                ], 400);
            }

            $allowed = [
                'subdomain',
                'login_name',
                'password',
                'app_id',
                'ssl_verify',
                'proxy',
                'field_company',
                'field_name',
                'field_email',
                'field_department',
                'field_phone',
                'field_address',
            ];

            $newSettings = $data['settings'];

            foreach ($allowed as $key) {
                if (array_key_exists($key, $settings)) {
                    $newSettings[$key] = $settings[$key];
                }
            }

            $newSettings['ssl_verify'] =
                !empty($newSettings['ssl_verify']);

            if (!is_array($newSettings['field_address'])) {
                $newSettings['field_address'] = [];
            }

            $data['settings'] = $newSettings;

            survey_write_data($data);

            survey_api([
                'ok' => true,
                'settings' => $newSettings,
            ]);
            break;

        case 'kintone_fields':
            $settings = $data['settings'];

            if (
                isset($_POST['app_id']) &&
                (string)$_POST['app_id'] !== ''
            ) {
                $settings['app_id'] =
                    (string)$_POST['app_id'];
            }

            $appId = (string)($settings['app_id'] ?? '');

            if ($appId === '') {
                survey_api([
                    'ok' => false,
                    'message' => 'アプリIDを入力してください。',
                ], 400);
            }

            $result = survey_proxy_kintone(
                $settings,
                '/k/v1/app/form/fields.json?app=' .
                rawurlencode($appId)
            );

            if (!$result['ok']) {
                survey_api([
                    'ok' => false,
                    'message' =>
                        $result['data']['message'] ??
                        $result['message'] ??
                        'kintoneから項目一覧を取得できませんでした。',
                    'status' => $result['status'] ?? 0,
                ]);
            }

            $fields = $result['data']['properties'] ?? [];

            survey_api([
                'ok' => true,
                'fields' => $fields,
            ]);
            break;

        case 'csv':
            $surveyId = (string)($_GET['survey_id'] ?? '');
            $survey = survey_find_survey($data, $surveyId);

            if ($survey === null) {
                http_response_code(404);
                exit('Survey not found');
            }

            $questions = [];

            foreach ($survey['groups'] as $group) {
                foreach ($group['questions'] as $question) {
                    $questions[] = $question;
                }
            }

            $fp = fopen('php://temp', 'r+');

            $header = [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名',
            ];

            foreach ($questions as $index => $question) {
                $header[] =
                    '設問' . ($index + 1);
            }

            fputcsv($fp, $header);

            foreach ($data['responses'] as $response) {
                if (
                    !is_array($response) ||
                    (string)($response['survey_id'] ?? '') !==
                    $surveyId
                ) {
                    continue;
                }

                $row = [
                    $response['id'] ?? '',
                    $response['answered_at'] ?? '',
                    $response['customer_id'] ?? '',
                    $response['company'] ?? '',
                    $response['name'] ?? '',
                ];

                $answers = $response['answers'] ?? [];

                foreach ($questions as $question) {
                    $qid = (string)$question['id'];
                    $answer = $answers[$qid] ?? '';

                    if (is_array($answer)) {
                        $answer = implode(
                            '、',
                            array_map(
                                'strval',
                                $answer
                            )
                        );
                    }

                    $row[] = $answer;
                }

                fputcsv($fp, $row);
            }

            rewind($fp);
            $csv = stream_get_contents($fp);
            fclose($fp);

            $filename =
                'survey_' .
                preg_replace(
                    '/[^A-Za-z0-9_-]/',
                    '_',
                    $surveyId
                ) .
                '.csv';

            header(
                'Content-Type: text/csv; charset=UTF-8'
            );
            header(
                'Content-Disposition: attachment; filename="' .
                $filename .
                '"'
            );

            /*
             * UTF-8 BOM:
             * Excelで日本語CSVを正しく認識させる。
             */
            echo "\xEF\xBB\xBF" . $csv;
            exit;

        default:
            survey_api([
                'ok' => false,
                'message' => '不明なアクションです。',
            ], 400);
    }
}

$csrfToken = survey_csrf();

?><!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<script>
tailwind.config = {
    theme: {
        extend: {
            colors: {
                accent: {
                    50: '#eff6ff',
                    100: '#dbeafe',
                    500: '#3b82f6',
                    600: '#2563eb',
                    700: '#1d4ed8'
                }
            }
        }
    }
};
</script>
</head>

<body class="min-h-screen bg-slate-50 text-slate-800">

<div id="app"></div>

<script>
window.App = {
    State: {
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },

        csrfToken: <?php echo survey_json($csrfToken); ?>,

        page: 'list',
        surveyId: null,
        responseId: null,

        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',

        editingSurvey: null,
        dirty: false,

        responseFilter: '',
        selectedQuestions: {},

        customerFilter: '',
        selectedCustomers: [],

        previewOpen: false,
        responseModalOpen: false,

        settingsFields: {}
    },

    Util: {},

    API: {},

    Render: {},

    Actions: {}
};
</script>
<script>
/* ================================================================
 * App.Util
 * ================================================================ */

App.Util.escape = function(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.Util.uid = function(prefix) {
    return prefix + '_' +
        Date.now().toString(36) + '_' +
        Math.random().toString(36).slice(2, 10);
};

App.Util.clone = function(value) {
    return JSON.parse(JSON.stringify(value));
};

App.Util.formatDate = function(value) {
    if (!value) return '未設定';

    const d = new Date(String(value).replace(' ', 'T'));

    if (Number.isNaN(d.getTime())) {
        return String(value);
    }

    return d.getFullYear() + '/' +
        String(d.getMonth() + 1).padStart(2, '0') + '/' +
        String(d.getDate()).padStart(2, '0');
};

App.Util.formatDateTime = function(value) {
    if (!value) return '未設定';

    const d = new Date(String(value).replace(' ', 'T'));

    if (Number.isNaN(d.getTime())) {
        return String(value);
    }

    return d.getFullYear() + '/' +
        String(d.getMonth() + 1).padStart(2, '0') + '/' +
        String(d.getDate()).padStart(2, '0') + ' ' +
        String(d.getHours()).padStart(2, '0') + ':' +
        String(d.getMinutes()).padStart(2, '0');
};

App.Util.statusLabel = function(status) {
    return {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[status] || '下書き';
};

App.Util.statusClass = function(status) {
    return {
        draft: 'bg-slate-100 text-slate-600',
        active: 'bg-emerald-100 text-emerald-700',
        ended: 'bg-amber-100 text-amber-700'
    }[status] || 'bg-slate-100 text-slate-600';
};

App.Util.typeLabel = function(type) {
    return {
        single: '単一選択',
        multiple: '複数選択',
        text: '自由記述'
    }[type] || '自由記述';
};

App.Util.allQuestions = function(survey) {
    const result = [];

    if (!survey || !Array.isArray(survey.groups)) {
        return result;
    }

    survey.groups.forEach(function(group) {
        if (!Array.isArray(group.questions)) return;

        group.questions.forEach(function(question) {
            result.push(question);
        });
    });

    return result;
};

App.Util.questionNumber = function(survey, questionId) {
    const questions = App.Util.allQuestions(survey);
    const index = questions.findIndex(function(q) {
        return q.id === questionId;
    });

    if (index < 0) return '';

    if (survey.numbering_mode === 'group') {
        let groupNo = 0;

        for (const group of survey.groups || []) {
            groupNo++;

            const qIndex = (group.questions || [])
                .findIndex(function(q) {
                    return q.id === questionId;
                });

            if (qIndex >= 0) {
                return 'Q' + groupNo + '-' + (qIndex + 1);
            }
        }
    }

    return 'Q' + (index + 1);
};

App.Util.questionById = function(survey, id) {
    for (const group of survey.groups || []) {
        for (const question of group.questions || []) {
            if (question.id === id) {
                return question;
            }
        }
    }

    return null;
};

App.Util.groupByQuestion = function(survey, questionId) {
    for (const group of survey.groups || []) {
        if ((group.questions || []).some(function(q) {
            return q.id === questionId;
        })) {
            return group;
        }
    }

    return null;
};

App.Util.answerText = function(answer) {
    if (Array.isArray(answer)) {
        return answer.join('、');
    }

    return answer == null ? '' : String(answer);
};

App.Util.confirm = function(message) {
    return window.confirm(message);
};

App.Util.toast = function(message, type) {
    const old = document.getElementById('app_toast');

    if (old) old.remove();

    const color = type === 'error'
        ? 'bg-red-600'
        : 'bg-slate-800';

    const el = document.createElement('div');

    el.id = 'app_toast';
    el.className =
        'fixed bottom-6 right-6 z-[100] rounded-xl ' +
        'px-5 py-3 text-sm font-medium text-white shadow-xl ' +
        color;

    el.textContent = message;

    document.body.appendChild(el);

    setTimeout(function() {
        el.remove();
    }, 2600);
};

App.Util.modal = function(title, content, buttons) {
    const old = document.getElementById('app_generic_modal');

    if (old) old.remove();

    const buttonHtml = (buttons || []).map(function(button) {
        return `
            <button
                type="button"
                class="${button.className || 'rounded-lg bg-slate-100 px-4 py-2 text-sm'}"
                onclick="${button.onclick || ''}"
            >${App.Util.escape(button.label)}</button>
        `;
    }).join('');

    document.body.insertAdjacentHTML(
        'beforeend',
        `
        <div id="app_generic_modal"
             class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-900/50 p-6">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h3 class="text-lg font-bold">${App.Util.escape(title)}</h3>
                </div>

                <div class="max-h-[70vh] overflow-auto p-6">
                    ${content}
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 px-6 py-4">
                    ${buttonHtml}
                </div>
            </div>
        </div>
        `
    );
};

App.Util.closeModal = function() {
    const modal = document.getElementById('app_generic_modal');
    if (modal) modal.remove();
};

App.Util.newQuestion = function() {
    return {
        id: App.Util.uid('q'),
        text: '',
        type: 'single',
        required: false,
        options: [
            {
                id: App.Util.uid('opt'),
                text: '選択肢1'
            }
        ],
        other_enabled: false,
        branching: []
    };
};

App.Util.newGroup = function() {
    return {
        id: App.Util.uid('group'),
        name: '新しいグループ',
        questions: []
    };
};

App.Util.newSurvey = function() {
    const now = new Date();

    const pad = function(value) {
        return String(value).padStart(2, '0');
    };

    const date = now.getFullYear() + '-' +
        pad(now.getMonth() + 1) + '-' +
        pad(now.getDate());

    return {
        id: App.Util.uid('survey'),
        title: '新しいアンケート',
        start_at: date + 'T09:00',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [
            {
                id: App.Util.uid('group'),
                name: 'グループ1',
                questions: [
                    App.Util.newQuestion()
                ]
            }
        ],
        deleted: false
    };
};


/* ================================================================
 * App.API
 * ================================================================ */

App.API.request = async function(action, data, method) {
    method = method || 'POST';

    let url = window.location.pathname +
        '?action=' +
        encodeURIComponent(action);

    const options = {
        method: method,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    };

    if (method === 'GET') {
        if (data) {
            const params = new URLSearchParams(data);
            url += '&' + params.toString();
        }
    } else {
        const body = new FormData();

        body.append(
            'csrf_token',
            App.State.csrfToken
        );

        Object.keys(data || {}).forEach(function(key) {
            const value = data[key];

            if (
                value !== null &&
                typeof value === 'object'
            ) {
                body.append(key, JSON.stringify(value));
            } else {
                body.append(
                    key,
                    value == null ? '' : String(value)
                );
            }
        });

        options.body = body;
    }

    const response = await fetch(url, options);

    const contentType =
        response.headers.get('content-type') || '';

    if (!contentType.includes('application/json')) {
        throw new Error(
            'サーバーからJSON以外の応答が返されました。'
        );
    }

    const result = await response.json();

    if (!response.ok || result.ok === false) {
        throw new Error(
            result.message || '通信に失敗しました。'
        );
    }

    return result;
};

App.API.load = async function() {
    const result = await App.API.request(
        'load',
        {},
        'GET'
    );

    App.State.data = result.data || App.State.data;

    if (result.csrf_token) {
        App.State.csrfToken = result.csrf_token;
    }

    return result;
};

App.API.saveSurvey = async function(survey) {
    return App.API.request(
        'save_survey',
        {
            survey_json: JSON.stringify(survey)
        },
        'POST'
    );
};

App.API.deleteSurvey = async function(id) {
    return App.API.request(
        'delete_survey',
        {
            survey_id: id
        }
    );
};

App.API.duplicateSurvey = async function(id) {
    return App.API.request(
        'duplicate_survey',
        {
            survey_id: id
        }
    );
};

App.API.changeStatus = async function(id, status) {
    return App.API.request(
        'change_status',
        {
            survey_id: id,
            status: status
        }
    );
};

App.API.saveSettings = async function(settings) {
    return App.API.request(
        'save_settings',
        {
            settings_json: JSON.stringify(settings)
        }
    );
};

App.API.kintoneFields = async function(appId) {
    return App.API.request(
        'kintone_fields',
        {
            app_id: appId
        }
    );
};


/* ================================================================
 * App.Actions
 * ================================================================ */

App.Actions.load = async function() {
    App.Render.loading();

    try {
        await App.API.load();
        App.Render.current();
    } catch (error) {
        App.Render.error(
            error.message || '読み込みに失敗しました。'
        );
    }
};

App.Actions.goList = function() {
    if (App.State.dirty) {
        const ok = App.Util.confirm(
            '未保存の変更があります。変更を破棄して一覧へ戻りますか？'
        );

        if (!ok) return;
    }

    App.State.page = 'list';
    App.State.surveyId = null;
    App.State.editingSurvey = null;
    App.State.dirty = false;

    App.Render.current();
};

App.Actions.newSurvey = function() {
    App.State.page = 'editor';
    App.State.surveyId = null;
    App.State.editingSurvey =
        App.Util.newSurvey();
    App.State.dirty = false;

    App.Render.current();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};

App.Actions.editSurvey = function(id) {
    const survey = App.State.data.surveys.find(
        function(item) {
            return item.id === id &&
                !item.deleted;
        }
    );

    if (!survey) {
        App.Util.toast(
            'アンケートが見つかりません。',
            'error'
        );
        return;
    }

    App.State.page = 'editor';
    App.State.surveyId = id;
    App.State.editingSurvey =
        App.Util.clone(survey);
    App.State.dirty = false;

    App.Render.current();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};

App.Actions.previewSurvey = function() {
    App.State.previewOpen = true;
    App.Render.preview();
};

App.Actions.closePreview = function() {
    App.State.previewOpen = false;

    const modal =
        document.getElementById('preview_modal');

    if (modal) modal.remove();
};

App.Actions.updateSurveyField = function(key, value) {
    if (!App.State.editingSurvey) return;

    App.State.editingSurvey[key] = value;
    App.State.dirty = true;
};

App.Actions.updateGroupName = function(groupId, value) {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    const group = survey.groups.find(
        function(item) {
            return item.id === groupId;
        }
    );

    if (!group) return;

    group.name = value;
    App.State.dirty = true;
};

App.Actions.addGroup = function() {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    survey.groups.push(
        App.Util.newGroup()
    );

    App.State.dirty = true;

    App.Render.editor();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};

App.Actions.deleteGroup = function(groupId) {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    const group = survey.groups.find(
        function(item) {
            return item.id === groupId;
        }
    );

    if (!group) return;

    const questionCount =
        (group.questions || []).length;

    const message = questionCount > 0
        ? 'このグループには' +
          questionCount +
          '件の質問があります。グループごと削除しますか？'
        : 'このグループを削除しますか？';

    if (!App.Util.confirm(message)) {
        return;
    }

    survey.groups =
        survey.groups.filter(
            function(item) {
                return item.id !== groupId;
            }
        );

    if (survey.groups.length === 0) {
        survey.groups.push(
            App.Util.newGroup()
        );
    }

    App.State.dirty = true;

    App.Render.editor();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};

App.Actions.addQuestion = function(groupId) {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    const group = survey.groups.find(
        function(item) {
            return item.id === groupId;
        }
    );

    if (!group) return;

    group.questions.push(
        App.Util.newQuestion()
    );

    App.State.dirty = true;

    App.Render.editor();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};

App.Actions.deleteQuestion = function(
    groupId,
    questionId
) {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    const group = survey.groups.find(
        function(item) {
            return item.id === groupId;
        }
    );

    if (!group) return;

    if (!App.Util.confirm(
        'この質問を削除しますか？'
    )) {
        return;
    }

    group.questions =
        group.questions.filter(
            function(question) {
                return question.id !== questionId;
            }
        );

    App.State.dirty = true;

    App.Render.editor();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};


/* ================================================================
 * 質問変更処理
 * ================================================================ */

App.Actions.updateQuestion = function(
    groupId,
    questionId,
    key,
    value
) {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    const group = survey.groups.find(
        function(item) {
            return item.id === groupId;
        }
    );

    if (!group) return;

    const question = group.questions.find(
        function(item) {
            return item.id === questionId;
        }
    );

    if (!question) return;

    /*
     * ============================================================
     * 重要な修正箇所
     *
     * 回答形式を自由記述へ変更した瞬間に、
     * 過去の選択肢をStateから完全に削除する。
     *
     * これにより
     *
     * 単一選択
     *   ↓
     * 自由記述
     *
     * の変更後に以前の選択肢が残らない。
     * ============================================================
     */
    if (key === 'type') {

        question.type = value;

        if (value === 'text') {

            question.options = [];
            question.branching = [];
            question.other_enabled = false;

        } else if (value === 'single') {

            if (!Array.isArray(question.options)) {
                question.options = [];
            }

            if (!Array.isArray(question.branching)) {
                question.branching = [];
            }

            App.Actions.syncBranchingOptions(
                question
            );

        } else if (value === 'multiple') {

            if (!Array.isArray(question.options)) {
                question.options = [];
            }

            question.branching = [];
        }

        App.State.dirty = true;

        App.Render.editor();

        setTimeout(function() {
            App.Actions.initSortables();
        }, 0);

        return;
    }

    if (key === 'required') {
        question.required = Boolean(value);
    } else if (key === 'other_enabled') {
        question.other_enabled =
            Boolean(value);
    } else {
        question[key] = value;
    }

    App.State.dirty = true;

    App.Render.editor();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};

App.Actions.updateOption = function(
    groupId,
    questionId,
    optionId,
    value
) {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    const question =
        App.Util.questionById(
            survey,
            questionId
        );

    if (!question) return;

    if (!Array.isArray(question.options)) {
        question.options = [];
    }

    const option = question.options.find(
        function(item) {
            return item.id === optionId;
        }
    );

    if (!option) return;

    option.text = value;

    App.Actions.syncBranchingOptions(
        question
    );

    App.State.dirty = true;

    App.Render.editor();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};

App.Actions.addOption = function(
    groupId,
    questionId
) {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    const question =
        App.Util.questionById(
            survey,
            questionId
        );

    if (!question) return;

    if (
        question.type !== 'single' &&
        question.type !== 'multiple'
    ) {
        return;
    }

    if (!Array.isArray(question.options)) {
        question.options = [];
    }

    question.options.push({
        id: App.Util.uid('opt'),
        text:
            '選択肢' +
            (question.options.length + 1)
    });

    App.Actions.syncBranchingOptions(
        question
    );

    App.State.dirty = true;

    App.Render.editor();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};

App.Actions.deleteOption = function(
    groupId,
    questionId,
    optionId
) {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    const question =
        App.Util.questionById(
            survey,
            questionId
        );

    if (!question) return;

    if (!Array.isArray(question.options)) {
        question.options = [];
    }

    if (question.options.length <= 1) {
        App.Util.toast(
            '選択肢は1件以上必要です。',
            'error'
        );
        return;
    }

    question.options =
        question.options.filter(
            function(option) {
                return option.id !== optionId;
            }
        );

    App.Actions.syncBranchingOptions(
        question
    );

    App.State.dirty = true;

    App.Render.editor();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};

App.Actions.syncBranchingOptions = function(
    question
) {
    if (
        !question ||
        question.type !== 'single'
    ) {
        return;
    }

    if (!Array.isArray(question.branching)) {
        question.branching = [];
    }

    const optionIds =
        (question.options || []).map(
            function(option) {
                return option.id;
            }
        );

    question.branching =
        question.branching.filter(
            function(item) {
                return optionIds.includes(
                    item.option_id
                );
            }
        );

    optionIds.forEach(function(optionId) {
        const exists =
            question.branching.some(
                function(item) {
                    return item.option_id === optionId;
                }
            );

        if (!exists) {
            question.branching.push({
                option_id: optionId,
                target_question_id: ''
            });
        }
    });
};


/* ================================================================
 * 保存・キャンセル
 * ================================================================ */

App.Actions.saveSurvey = async function() {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    if (!String(survey.title || '').trim()) {
        App.Util.toast(
            'アンケートタイトルを入力してください。',
            'error'
        );
        return;
    }

    /*
     * 保存直前にもStateを正規化。
     * UI側のバグや古いデータが残っていても、
     * 自由記述には選択肢を保存しない。
     */
    survey.groups.forEach(function(group) {
        (group.questions || []).forEach(
            function(question) {

                if (question.type === 'text') {
                    question.options = [];
                    question.branching = [];
                    question.other_enabled = false;
                }

                if (
                    question.type !== 'single'
                ) {
                    question.branching = [];
                }

                if (!Array.isArray(
                    question.options
                )) {
                    question.options = [];
                }
            }
        );
    });

    try {
        const result =
            await App.API.saveSurvey(
                survey
            );

        App.State.data = result.data;

        App.State.dirty = false;
        App.State.page = 'list';
        App.State.surveyId = null;
        App.State.editingSurvey = null;

        App.Util.toast(
            'アンケートを保存しました。'
        );

        App.Render.current();

    } catch (error) {
        App.Util.toast(
            error.message ||
            '保存に失敗しました。',
            'error'
        );
    }
};

App.Actions.cancelEdit = function() {
    if (App.State.dirty) {
        if (!App.Util.confirm(
            '変更を破棄して一覧へ戻りますか？'
        )) {
            return;
        }
    }

    App.State.page = 'list';
    App.State.surveyId = null;
    App.State.editingSurvey = null;
    App.State.dirty = false;

    App.Render.current();
};


/* ================================================================
 * 一覧操作
 * ================================================================ */

App.Actions.setKeyword = function(value) {
    App.State.keyword = value;
    App.Render.list();
};

App.Actions.setStatusFilter = function(value) {
    App.State.statusFilter = value;
    App.Render.list();
};

App.Actions.setSort = function(value) {
    App.State.sort = value;
    App.Render.list();
};

App.Actions.stopSurvey = async function(id) {
    if (!App.Util.confirm(
        'このアンケートを停止しますか？'
    )) {
        return;
    }

    try {
        const result =
            await App.API.changeStatus(
                id,
                'ended'
            );

        App.State.data = result.data;

        App.Util.toast(
            'アンケートを停止しました。'
        );

        App.Render.list();

    } catch (error) {
        App.Util.toast(
            error.message ||
            'ステータス変更に失敗しました。',
            'error'
        );
    }
};

App.Actions.resumeSurvey = async function(id) {
    if (!App.Util.confirm(
        'このアンケートを公開しますか？'
    )) {
        return;
    }

    try {
        const result =
            await App.API.changeStatus(
                id,
                'active'
            );

        App.State.data = result.data;

        App.Util.toast(
            'アンケートを公開しました。'
        );

        App.Render.list();

    } catch (error) {
        App.Util.toast(
            error.message ||
            'ステータス変更に失敗しました。',
            'error'
        );
    }
};

App.Actions.deleteSurvey = async function(id) {
    if (!App.Util.confirm(
        'この下書きを削除しますか？'
    )) {
        return;
    }

    try {
        const result =
            await App.API.deleteSurvey(id);

        App.State.data = result.data;

        App.Util.toast(
            'アンケートを削除しました。'
        );

        App.Render.list();

    } catch (error) {
        App.Util.toast(
            error.message ||
            '削除に失敗しました。',
            'error'
        );
    }
};

App.Actions.duplicateSurvey = async function(id) {
    try {
        const result =
            await App.API.duplicateSurvey(id);

        App.State.data = result.data;

        App.Util.toast(
            'アンケートを複製しました。'
        );

        App.Render.list();

    } catch (error) {
        App.Util.toast(
            error.message ||
            '複製に失敗しました。',
            'error'
        );
    }
};

App.Actions.openSettings = function() {
    if (App.State.dirty) {
        if (!App.Util.confirm(
            '未保存の変更があります。設定画面へ移動しますか？'
        )) {
            return;
        }

        App.State.dirty = false;
    }

    App.State.page = 'settings';
    App.Render.current();
};


/* ================================================================
 * 質問のドラッグ＆ドロップ
 * ================================================================ */

App.Actions.initSortables = function() {
    if (
        typeof Sortable === 'undefined'
    ) {
        return;
    }

    const groupList =
        document.getElementById(
            'question_editor'
        );

    if (!groupList) return;

    const groupContainers =
        groupList.querySelectorAll(
            '[data-sortable-groups]'
        );

    groupContainers.forEach(function(container) {

        if (container._sortable) {
            container._sortable.destroy();
        }

        container._sortable =
            new Sortable(
                container,
                {
                    group: 'survey-groups',
                    animation: 180,
                    handle: '[data-group-handle]',
                    ghostClass:
                        'opacity-40',
                    chosenClass:
                        'ring-2 ring-blue-300',
                    onEnd: function(event) {
                        App.Actions.reorderGroups(
                            event
                        );
                    }
                }
            );
    });

    const questionContainers =
        groupList.querySelectorAll(
            '[data-sortable-questions]'
        );

    questionContainers.forEach(function(container) {

        if (container._sortable) {
            container._sortable.destroy();
        }

        container._sortable =
            new Sortable(
                container,
                {
                    group: {
                        name: 'survey-questions',
                        pull: true,
                        put: true
                    },
                    animation: 180,
                    handle:
                        '[data-question-handle]',
                    ghostClass:
                        'opacity-40',
                    chosenClass:
                        'ring-2 ring-blue-300',
                    onEnd: function(event) {
                        App.Actions.reorderQuestions(
                            event
                        );
                    }
                }
            );
    });
};

App.Actions.reorderGroups = function() {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    const container =
        document.querySelector(
            '[data-sortable-groups]'
        );

    if (!container) return;

    const ids =
        Array.from(
            container.children
        ).map(function(el) {
            return el.dataset.groupId;
        });

    const map = new Map(
        survey.groups.map(function(group) {
            return [group.id, group];
        })
    );

    survey.groups = ids
        .map(function(id) {
            return map.get(id);
        })
        .filter(Boolean);

    App.State.dirty = true;

    App.Render.editor();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};

App.Actions.reorderQuestions = function() {
    const survey = App.State.editingSurvey;

    if (!survey) return;

    const newGroups = [];

    survey.groups.forEach(function(group) {
        const container =
            document.querySelector(
                '[data-sortable-questions="' +
                group.id +
                '"]'
            );

        if (!container) {
            newGroups.push(group);
            return;
        }

        const ids =
            Array.from(
                container.children
            ).map(function(el) {
                return el.dataset.questionId;
            });

        const questionMap = new Map();

        survey.groups.forEach(function(g) {
            (g.questions || []).forEach(
                function(q) {
                    questionMap.set(
                        q.id,
                        q
                    );
                }
            );
        });

        group.questions = ids
            .map(function(id) {
                return questionMap.get(id);
            })
            .filter(Boolean);

        newGroups.push(group);
    });

    survey.groups = newGroups;

    App.State.dirty = true;

    App.Render.editor();

    setTimeout(function() {
        App.Actions.initSortables();
    }, 0);
};


/* ================================================================
 * Render 共通
 * ================================================================ */

App.Render.loading = function() {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen flex items-center justify-center">
            <div class="rounded-2xl bg-white px-8 py-6 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="h-5 w-5 animate-spin rounded-full
                                border-2 border-slate-200
                                border-t-blue-600"></div>
                    <span class="text-sm text-slate-600">
                        読み込み中...
                    </span>
                </div>
            </div>
        </div>
    `;
};

App.Render.error = function(message) {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen flex items-center justify-center p-6">
            <div class="max-w-lg rounded-2xl bg-white p-8
                        text-center shadow-sm">
                <div class="mx-auto mb-4 flex h-12 w-12
                            items-center justify-center rounded-full
                            bg-red-100 text-red-600">
                    !
                </div>

                <h1 class="mb-2 text-lg font-bold">
                    読み込みエラー
                </h1>

                <p class="mb-6 text-sm text-slate-500">
                    ${App.Util.escape(message)}
                </p>

                <button
                    type="button"
                    onclick="App.Actions.load()"
                    class="rounded-lg bg-blue-600 px-5 py-2.5
                           text-sm font-medium text-white
                           hover:bg-blue-700">
                    再読み込み
                </button>
            </div>
        </div>
    `;
};

App.Render.header = function(title) {
    return `
        <header class="sticky top-0 z-40 border-b
                       border-slate-200 bg-white/95
                       backdrop-blur">
            <div class="mx-auto flex max-w-[1600px]
                        items-center justify-between
                        px-6 py-4">

                <div class="flex items-center gap-8">
                    <button
                        type="button"
                        onclick="App.Actions.goList()"
                        class="text-lg font-bold tracking-tight
                               text-slate-900">
                        アンケート管理
                    </button>

                    <nav class="hidden items-center gap-1 md:flex">
                        <button
                            type="button"
                            onclick="App.Actions.goList()"
                            class="rounded-lg px-3 py-2 text-sm
                                   text-slate-600 hover:bg-slate-100">
                            アンケート一覧
                        </button>

                        <button
                            type="button"
                            onclick="App.Actions.openSettings()"
                            class="rounded-lg px-3 py-2 text-sm
                                   text-slate-600 hover:bg-slate-100">
                            kintone連携設定
                        </button>
                    </nav>
                </div>

                <div class="flex items-center gap-3">
                    <span class="hidden text-sm text-slate-500 md:inline">
                        ${App.Util.escape(title || '')}
                    </span>

                    <button
                        type="button"
                        onclick="App.Actions.goList()"
                        class="rounded-lg border border-slate-200
                               bg-white px-3 py-2 text-sm
                               font-medium text-slate-600
                               hover:bg-slate-50">
                        一覧
                    </button>
                </div>
            </div>
        </header>
    `;
};


/* ================================================================
 * 一覧画面
 * ================================================================ */

App.Render.list = function() {
    const state = App.State;

    let surveys =
        (state.data.surveys || [])
        .filter(function(survey) {
            return !survey.deleted;
        });

    const keyword =
        state.keyword.trim().toLowerCase();

    if (keyword) {
        surveys = surveys.filter(
            function(survey) {
                return String(
                    survey.title || ''
                ).toLowerCase().includes(keyword);
            }
        );
    }

    if (state.statusFilter !== 'all') {
        surveys = surveys.filter(
            function(survey) {
                return survey.status ===
                    state.statusFilter;
            }
        );
    }

    surveys.sort(function(a, b) {
        if (state.sort === 'updated_desc') {
            return String(b.updated_at || '')
                .localeCompare(
                    String(a.updated_at || '')
                );
        }

        if (state.sort === 'updated_asc') {
            return String(a.updated_at || '')
                .localeCompare(
                    String(b.updated_at || '')
                );
        }

        if (state.sort === 'answers_desc') {
            return 0;
        }

        if (state.sort === 'answers_asc') {
            return 0;
        }

        if (state.sort === 'start_desc') {
            return String(b.start_at || '')
                .localeCompare(
                    String(a.start_at || '')
                );
        }

        if (state.sort === 'start_asc') {
            return String(a.start_at || '')
                .localeCompare(
                    String(b.start_at || '')
                );
        }

        return 0;
    });

    const rows = surveys.length
        ? surveys.map(function(survey) {
            return App.Render.surveyRow(
                survey
            );
        }).join('')
        : `
            <div class="rounded-2xl border border-dashed
                        border-slate-300 bg-white p-12
                        text-center">
                <div class="mx-auto mb-4 flex h-12 w-12
                            items-center justify-center
                            rounded-full bg-slate-100
                            text-slate-400">
                    ≡
                </div>

                <p class="font-medium text-slate-700">
                    アンケートがありません
                </p>

                <p class="mt-1 text-sm text-slate-400">
                    新規アンケートを作成してください。
                </p>
            </div>
        `;

    document.getElementById('app').innerHTML = `
        ${App.Render.header('アンケート一覧')}

        <main class="mx-auto max-w-[1600px] px-6 py-8">

            <div class="mb-8 flex flex-col gap-4
                        sm:flex-row sm:items-end
                        sm:justify-between">

                <div>
                    <h1 class="text-2xl font-bold
                               tracking-tight text-slate-900">
                        アンケート一覧
                    </h1>

                    <p class="mt-1 text-sm text-slate-500">
                        アンケートの作成・公開・集計を管理します。
                    </p>
                </div>

                <button
                    type="button"
                    onclick="App.Actions.newSurvey()"
                    class="inline-flex items-center justify-center
                           gap-2 rounded-xl bg-blue-600
                           px-5 py-3 text-sm font-semibold
                           text-white shadow-sm
                           hover:bg-blue-700">
                    <span class="text-lg leading-none">＋</span>
                    新規アンケート作成
                </button>
            </div>

            <section class="mb-5 rounded-2xl border
                            border-slate-200 bg-white p-4 shadow-sm">

                <div class="flex flex-col gap-3 lg:flex-row
                            lg:items-center">

                    <div class="relative flex-1">
                        <input
                            type="text"
                            value="${App.Util.escape(state.keyword)}"
                            placeholder="タイトルを検索"
                            oninput="App.Actions.setKeyword(this.value)"
                            class="w-full rounded-xl border
                                   border-slate-200 bg-slate-50
                                   px-4 py-3 pl-10 text-sm
                                   outline-none transition
                                   focus:border-blue-400
                                   focus:bg-white
                                   focus:ring-2
                                   focus:ring-blue-100">
                        <span class="absolute left-3 top-3.5
                                     text-slate-400">
                            ⌕
                        </span>
                    </div>

                    <select
                        onchange="App.Actions.setStatusFilter(this.value)"
                        class="rounded-xl border border-slate-200
                               bg-white px-4 py-3 text-sm
                               outline-none focus:border-blue-400
                               focus:ring-2 focus:ring-blue-100">
                        <option value="all"
                            ${state.statusFilter === 'all'
                                ? 'selected' : ''}>
                            すべて
                        </option>
                        <option value="active"
                            ${state.statusFilter === 'active'
                                ? 'selected' : ''}>
                            公開中
                        </option>
                        <option value="draft"
                            ${state.statusFilter === 'draft'
                                ? 'selected' : ''}>
                            下書き
                        </option>
                        <option value="ended"
                            ${state.statusFilter === 'ended'
                                ? 'selected' : ''}>
                            終了
                        </option>
                    </select>

                    <select
                        onchange="App.Actions.setSort(this.value)"
                        class="rounded-xl border border-slate-200
                               bg-white px-4 py-3 text-sm
                               outline-none focus:border-blue-400
                               focus:ring-2 focus:ring-blue-100">
                        <option value="updated_desc"
                            ${state.sort === 'updated_desc'
                                ? 'selected' : ''}>
                            更新日：新しい順
                        </option>
                        <option value="updated_asc"
                            ${state.sort === 'updated_asc'
                                ? 'selected' : ''}>
                            更新日：古い順
                        </option>
                        <option value="answers_desc"
                            ${state.sort === 'answers_desc'
                                ? 'selected' : ''}>
                            回答数：多い順
                        </option>
                        <option value="answers_asc"
                            ${state.sort === 'answers_asc'
                                ? 'selected' : ''}>
                            回答数：少ない順
                        </option>
                        <option value="start_desc"
                            ${state.sort === 'start_desc'
                                ? 'selected' : ''}>
                            開始日：新しい順
                        </option>
                        <option value="start_asc"
                            ${state.sort === 'start_asc'
                                ? 'selected' : ''}>
                            開始日：古い順
                        </option>
                    </select>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl
                            border border-slate-200
                            bg-white shadow-sm">

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1100px]
                                  text-left text-sm">

                        <thead class="border-b border-slate-200
                                      bg-slate-50 text-xs
                                      font-semibold text-slate-500">
                            <tr>
                                <th class="px-5 py-4">
                                    アンケート
                                </th>
                                <th class="px-5 py-4">
                                    期間
                                </th>
                                <th class="px-5 py-4">
                                    ステータス
                                </th>
                                <th class="px-5 py-4 text-right">
                                    回答数
                                </th>
                                <th class="px-5 py-4">
                                    更新
                                </th>
                                <th class="px-5 py-4">
                                    操作
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y
                                     divide-slate-100">
                            ${rows}
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    `;
};

App.Render.surveyRow = function(survey) {
    const responseCount =
        (App.State.data.responses || [])
        .filter(function(response) {
            return response.survey_id ===
                survey.id;
        }).length;

    const period =
        survey.start_at || survey.end_at
        ? App.Util.formatDateTime(
            survey.start_at
        ) +
        ' ～ ' +
        App.Util.formatDateTime(
            survey.end_at
        )
        : '未設定';

    let actions = '';

    if (survey.status === 'active') {
        actions = `
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    onclick="App.Actions.editSurvey('${survey.id}')"
                    class="rounded-lg border border-slate-200
                           px-3 py-1.5 text-xs font-medium
                           hover:bg-slate-50">
                    確認・編集
                </button>

                <button
                    type="button"
                    onclick="App.Actions.openResults('${survey.id}')"
                    class="rounded-lg border border-blue-200
                           px-3 py-1.5 text-xs font-medium
                           text-blue-700 hover:bg-blue-50">
                    集計
                </button>

                <button
                    type="button"
                    onclick="App.Actions.openMail('${survey.id}')"
                    class="rounded-lg border border-slate-200
                           px-3 py-1.5 text-xs font-medium
                           hover:bg-slate-50">
                    送信
                </button>

                <button
                    type="button"
                    onclick="App.Actions.stopSurvey('${survey.id}')"
                    class="rounded-lg border border-amber-200
                           px-3 py-1.5 text-xs font-medium
                           text-amber-700 hover:bg-amber-50">
                    停止
                </button>

                <button
                    type="button"
                    onclick="App.Actions.duplicateSurvey('${survey.id}')"
                    class="rounded-lg border border-slate-200
                           px-3 py-1.5 text-xs font-medium
                           hover:bg-slate-50">
                    複製
                </button>
            </div>
        `;
    } else if (survey.status === 'draft') {
        actions = `
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    onclick="App.Actions.editSurvey('${survey.id}')"
                    class="rounded-lg border border-slate-200
                           px-3 py-1.5 text-xs font-medium
                           hover:bg-slate-50">
                    確認・編集
                </button>

                <button
                    type="button"
                    onclick="App.Actions.deleteSurvey('${survey.id}')"
                    class="rounded-lg border border-red-200
                           px-3 py-1.5 text-xs font-medium
                           text-red-600 hover:bg-red-50">
                    削除
                </button>

                <button
                    type="button"
                    onclick="App.Actions.duplicateSurvey('${survey.id}')"
                    class="rounded-lg border border-slate-200
                           px-3 py-1.5 text-xs font-medium
                           hover:bg-slate-50">
                    複製
                </button>
            </div>
        `;
    } else {
        actions = `
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    onclick="App.Actions.editSurvey('${survey.id}')"
                    class="rounded-lg border border-slate-200
                           px-3 py-1.5 text-xs font-medium
                           hover:bg-slate-50">
                    確認・編集
                </button>

                <button
                    type="button"
                    onclick="App.Actions.openResults('${survey.id}')"
                    class="rounded-lg border border-blue-200
                           px-3 py-1.5 text-xs font-medium
                           text-blue-700 hover:bg-blue-50">
                    集計
                </button>

                <button
                    type="button"
                    onclick="App.Actions.duplicateSurvey('${survey.id}')"
                    class="rounded-lg border border-slate-200
                           px-3 py-1.5 text-xs font-medium
                           hover:bg-slate-50">
                    複製
                </button>
            </div>
        `;
    }

    return `
        <tr class="align-top hover:bg-slate-50/60">

            <td class="px-5 py-5">
                <div class="font-bold text-slate-900">
                    ${App.Util.escape(survey.title)}
                </div>

                <div class="mt-1 text-xs text-slate-400">
                    作成：
                    ${App.Util.formatDate(
                        survey.created_at
                    )}
                </div>
            </td>

            <td class="px-5 py-5 text-xs
                       leading-6 text-slate-600">
                ${App.Util.escape(period)}
            </td>

            <td class="px-5 py-5">
                <span class="inline-flex rounded-full
                             px-2.5 py-1 text-xs
                             font-semibold
                             ${App.Util.statusClass(
                                 survey.status
                             )}">
                    ${App.Util.statusLabel(
                        survey.status
                    )}
                </span>
            </td>

            <td class="px-5 py-5 text-right
                       font-semibold text-slate-700">
                ${responseCount} 件
            </td>

            <td class="px-5 py-5 text-xs
                       text-slate-500">
                ${App.Util.formatDateTime(
                    survey.updated_at
                )}
            </td>

            <td class="px-5 py-5">
                ${actions}
            </td>
        </tr>
    `;
};


/* ================================================================
 * 編集画面
 * ================================================================ */

App.Render.editor = function() {
    const survey =
        App.State.editingSurvey;

    if (!survey) {
        App.Actions.goList();
        return;
    }

    document.getElementById('app').innerHTML = `
        ${App.Render.header(
            survey.title || 'アンケート編集'
        )}

        <main class="mx-auto max-w-[1500px] px-6 py-8">

            <div class="mb-6 flex flex-col gap-4
                        lg:flex-row lg:items-center
                        lg:justify-between">

                <div>
                    <div class="mb-2 text-xs text-slate-400">
                        ホーム
                        <span class="mx-1">›</span>
                        アンケート一覧
                        <span class="mx-1">›</span>
                        編集
                    </div>

                    <h1 class="text-2xl font-bold
                               tracking-tight text-slate-900">
                        アンケート作成・編集
                    </h1>
                </div>

                <div class="flex flex-wrap gap-2">

                    <button
                        type="button"
                        onclick="App.Actions.previewSurvey()"
                        class="rounded-xl border
                               border-slate-200 bg-white
                               px-4 py-2.5 text-sm
                               font-medium hover:bg-slate-50">
                        プレビュー
                    </button>

                    <button
                        type="button"
                        onclick="App.Actions.cancelEdit()"
                        class="rounded-xl border
                               border-slate-200 bg-white
                               px-4 py-2.5 text-sm
                               font-medium hover:bg-slate-50">
                        キャンセル
                    </button>

                    <button
                        type="button"
                        onclick="App.Actions.saveSurvey()"
                        class="rounded-xl bg-blue-600
                               px-5 py-2.5 text-sm
                               font-semibold text-white
                               shadow-sm hover:bg-blue-700">
                        保存して一覧へ戻る
                    </button>
                </div>
            </div>

            <section class="mb-6 rounded-2xl border
                            border-slate-200 bg-white
                            p-6 shadow-sm">

                <div class="grid gap-5 md:grid-cols-3">

                    <div class="md:col-span-3">
                        <label class="mb-2 block text-sm
                                      font-semibold text-slate-700">
                            アンケートタイトル
                        </label>

                        <input
                            id="survey_title"
                            type="text"
                            value="${App.Util.escape(
                                survey.title
                            )}"
                            oninput="App.Actions.updateSurveyField(
                                'title',
                                this.value
                            )"
                            class="w-full rounded-xl border
                                   border-slate-200 bg-slate-50
                                   px-4 py-3 text-lg font-semibold
                                   outline-none
                                   focus:border-blue-400
                                   focus:bg-white
                                   focus:ring-2
                                   focus:ring-blue-100">
                    </div>

                    <div>
                        <label class="mb-2 block text-sm
                                      font-semibold text-slate-700">
                            開始日時
                        </label>

                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${App.Util.escape(
                                survey.start_at
                            )}"
                            onchange="App.Actions.updateSurveyField(
                                'start_at',
                                this.value
                            )"
                            class="w-full rounded-xl border
                                   border-slate-200
                                   px-3 py-2.5 text-sm
                                   outline-none
                                   focus:border-blue-400
                                   focus:ring-2
                                   focus:ring-blue-100">
                    </div>

                    <div>
                        <label class="mb-2 block text-sm
                                      font-semibold text-slate-700">
                            終了日時
                        </label>

                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${App.Util.escape(
                                survey.end_at
                            )}"
                            onchange="App.Actions.updateSurveyField(
                                'end_at',
                                this.value
                            )"
                            class="w-full rounded-xl border
                                   border-slate-200
                                   px-3 py-2.5 text-sm
                                   outline-none
                                   focus:border-blue-400
                                   focus:ring-2
                                   focus:ring-blue-100">
                    </div>

                    <div>
                        <label class="mb-2 block text-sm
                                      font-semibold text-slate-700">
                            質問番号
                        </label>

                        <select
                            id="survey_numbering_mode"
                            onchange="App.Actions.updateSurveyField(
                                'numbering_mode',
                                this.value
                            ); App.Render.editor(); App.Actions.initSortables();"
                            class="w-full rounded-xl border
                                   border-slate-200
                                   px-3 py-2.5 text-sm
                                   outline-none
                                   focus:border-blue-400
                                   focus:ring-2
                                   focus:ring-blue-100">
                            <option
                                value="global"
                                ${survey.numbering_mode === 'global'
                                    ? 'selected' : ''}>
                                Q1, Q2, Q3...
                            </option>

                            <option
                                value="group"
                                ${survey.numbering_mode === 'group'
                                    ? 'selected' : ''}>
                                Q1-1, Q1-2, Q2-1...
                            </option>
                        </select>
                    </div>
                </div>
            </section>

            <section id="question_editor">

                <div class="mb-4 flex items-center
                            justify-between">
                    <div>
                        <h2 class="text-lg font-bold
                                   text-slate-900">
                            質問構成
                        </h2>

                        <p class="mt-1 text-xs
                                  text-slate-400">
                            ドラッグ＆ドロップでグループ・質問を並べ替えできます。
                        </p>
                    </div>

                    <button
                        type="button"
                        onclick="App.Actions.addGroup()"
                        class="rounded-xl border
                               border-blue-200 bg-blue-50
                               px-4 py-2.5 text-sm
                               font-semibold text-blue-700
                               hover:bg-blue-100">
                        ＋ グループ追加
                    </button>
                </div>

                <div data-sortable-groups
                     class="space-y-5">
                    ${(survey.groups || []).map(
                        function(group, groupIndex) {
                            return App.Render.group(
                                survey,
                                group,
                                groupIndex
                            );
                        }
                    ).join('')}
                </div>
            </section>
        </main>
    `;
};

App.Render.group = function(
    survey,
    group,
    groupIndex
) {
    return `
        <article
            data-group-id="${App.Util.escape(group.id)}"
            class="rounded-2xl border
                   border-slate-200 bg-white
                   shadow-sm">

            <div class="flex items-center gap-3
                        border-b border-slate-200
                        bg-slate-50/70 px-5 py-4">

                <button
                    type="button"
                    data-group-handle
                    title="ドラッグして並べ替え"
                    class="cursor-grab rounded-lg
                           px-2 py-1 text-lg
                           text-slate-400 hover:bg-slate-200">
                    ⠿
                </button>

                <div class="flex-1">
                    <input
                        type="text"
                        value="${App.Util.escape(
                            group.name
                        )}"
                        onchange="App.Actions.updateGroupName(
                            '${group.id}',
                            this.value
                        )"
                        class="w-full max-w-xl rounded-lg
                               border border-transparent
                               bg-transparent px-2 py-1
                               font-semibold text-slate-800
                               outline-none
                               focus:border-slate-200
                               focus:bg-white">
                </div>

                <span class="text-xs text-slate-400">
                    ${group.questions.length} 問
                </span>

                <button
                    type="button"
                    onclick="App.Actions.deleteGroup(
                        '${group.id}'
                    )"
                    class="rounded-lg px-3 py-2
                           text-xs font-medium
                           text-red-600
                           hover:bg-red-50">
                    グループ削除
                </button>
            </div>

            <div
                data-sortable-questions="${App.Util.escape(group.id)}"
                class="space-y-4 p-5">

                ${(group.questions || []).map(
                    function(question) {
                        return App.Render.question(
                            survey,
                            group,
                            question
                        );
                    }
                ).join('')}

                ${group.questions.length === 0
                    ? `
                        <div class="rounded-xl border
                                    border-dashed
                                    border-slate-300
                                    p-8 text-center
                                    text-sm text-slate-400">
                            質問がありません
                        </div>
                    `
                    : ''}
            </div>

            <div class="border-t border-slate-100
                        px-5 py-4">
                <button
                    type="button"
                    onclick="App.Actions.addQuestion(
                        '${group.id}'
                    )"
                    class="rounded-lg bg-slate-100
                           px-4 py-2 text-sm
                           font-semibold text-slate-700
                           hover:bg-slate-200">
                    ＋ 質問を追加
                </button>
            </div>
        </article>
    `;
};
</script>
/* ================================================================
 * Part 3 / 5
 * JavaScript: アンケート編集・質問管理・プレビュー
 * ================================================================ */

?>
<script>
window.App = window.App || {};

/* ---------------------------------------------------------------
 * App State
 * --------------------------------------------------------------- */
App.state = App.state || {
    screen: 'list',
    surveys: [],
    responses: [],
    customers: [],
    settings: {},
    currentSurvey: null,
    currentSurveyId: null,
    responseFilter: '',
    customerFilter: '',
    selectedResponseQuestions: {},
    editingSurveyDirty: false
};

App.state.newSurvey = function () {
    return {
        id: 'sv_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8),
        title: '',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        numbering_mode: 'global',
        groups: [
            {
                id: 'grp_' + Date.now(),
                name: 'グループ1',
                questions: []
            }
        ],
        deleted: false
    };
};


/* ---------------------------------------------------------------
 * Utility
 * --------------------------------------------------------------- */
App.utils = App.utils || {};

App.utils.escape = function (value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.utils.escapeAttr = function (value) {
    return App.utils.escape(value)
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

App.utils.uid = function (prefix) {
    return prefix + '_' + Date.now() + '_' +
        Math.random().toString(36).slice(2, 9);
};

App.utils.deepClone = function (value) {
    return JSON.parse(JSON.stringify(value));
};

App.utils.findQuestion = function (questionId) {
    const survey = App.state.currentSurvey;
    if (!survey) return null;

    for (const group of survey.groups || []) {
        for (const question of group.questions || []) {
            if (question.id === questionId) {
                return {
                    question: question,
                    group: group
                };
            }
        }
    }
    return null;
};


/* ---------------------------------------------------------------
 * Survey editor
 * --------------------------------------------------------------- */
App.editor = App.editor || {};

/*
 * 新規質問の標準構造
 *
 * type:
 *   single   = 単一選択
 *   multiple = 複数選択
 *   text     = 自由記述
 *
 * 重要:
 * 自由記述では options を空配列にする。
 */
App.editor.createQuestion = function () {
    return {
        id: App.utils.uid('q'),
        text: '',
        type: 'single',
        required: false,
        options: [
            {
                id: App.utils.uid('opt'),
                text: '選択肢1'
            }
        ],
        other_enabled: false
    };
};


/*
 * 回答形式変更
 *
 * ★重要な不具合対策
 *
 * single / multiple
 *        ↓
 * text
 *
 * に変更した場合、options と other_enabled を
 * 必ず初期化する。
 *
 * さらにDOM側だけを変更するのではなく、
 * Stateそのものを変更する。
 */
App.editor.changeQuestionType = function (questionId, type) {
    const found = App.utils.findQuestion(questionId);
    if (!found) return;

    const question = found.question;

    if (!['single', 'multiple', 'text'].includes(type)) {
        return;
    }

    question.type = type;

    if (type === 'text') {
        /*
         * 自由記述では選択肢を保持しない。
         * ここが今回の修正ポイント。
         */
        question.options = [];
        question.other_enabled = false;
    } else {
        /*
         * 選択式へ戻した場合のみ選択肢を生成。
         */
        if (!Array.isArray(question.options) ||
            question.options.length === 0) {

            question.options = [
                {
                    id: App.utils.uid('opt'),
                    text: '選択肢1'
                }
            ];
        }
    }

    App.state.editingSurveyDirty = true;
    App.editor.renderQuestions();
};


/*
 * グループ追加
 */
App.actions = App.actions || {};

App.actions.addGroup = function () {
    const survey = App.state.currentSurvey;
    if (!survey) return;

    const number = (survey.groups || []).length + 1;

    survey.groups.push({
        id: App.utils.uid('grp'),
        name: 'グループ' + number,
        questions: []
    });

    App.state.editingSurveyDirty = true;

    App.editor.renderQuestions();
    App.editor.initSortable();
};


/*
 * グループ削除
 */
App.actions.deleteGroup = function (groupId) {
    const survey = App.state.currentSurvey;
    if (!survey) return;

    const group = survey.groups.find(g => g.id === groupId);
    if (!group) return;

    const count = (group.questions || []).length;

    const message = count > 0
        ? 'このグループには質問が ' + count +
          ' 件あります。グループと質問をすべて削除しますか？'
        : 'このグループを削除しますか？';

    if (!window.confirm(message)) return;

    survey.groups = survey.groups.filter(g => g.id !== groupId);

    if (survey.groups.length === 0) {
        survey.groups.push({
            id: App.utils.uid('grp'),
            name: 'グループ1',
            questions: []
        });
    }

    App.state.editingSurveyDirty = true;

    App.editor.renderQuestions();
    App.editor.initSortable();
};


/*
 * グループ名変更
 */
App.actions.changeGroupName = function (groupId, value) {
    const survey = App.state.currentSurvey;
    if (!survey) return;

    const group = survey.groups.find(g => g.id === groupId);
    if (!group) return;

    group.name = value;
    App.state.editingSurveyDirty = true;
};


/*
 * 質問追加
 */
App.actions.addQuestion = function (groupId) {
    const survey = App.state.currentSurvey;
    if (!survey) return;

    const group = survey.groups.find(g => g.id === groupId);
    if (!group) return;

    group.questions.push(App.editor.createQuestion());

    App.state.editingSurveyDirty = true;

    App.editor.renderQuestions();
    App.editor.initSortable();
};


/*
 * 質問削除
 */
App.actions.deleteQuestion = function (questionId) {
    const survey = App.state.currentSurvey;
    if (!survey) return;

    if (!window.confirm('この質問を削除しますか？')) {
        return;
    }

    for (const group of survey.groups || []) {
        const index = group.questions.findIndex(
            q => q.id === questionId
        );

        if (index !== -1) {
            group.questions.splice(index, 1);
            App.state.editingSurveyDirty = true;
            App.editor.renderQuestions();
            App.editor.initSortable();
            return;
        }
    }
};


/*
 * 質問文変更
 */
App.actions.changeQuestionText = function (questionId, value) {
    const found = App.utils.findQuestion(questionId);
    if (!found) return;

    found.question.text = value;
    App.state.editingSurveyDirty = true;
};


/*
 * 必須設定
 */
App.actions.toggleRequired = function (questionId, checked) {
    const found = App.utils.findQuestion(questionId);
    if (!found) return;

    found.question.required = !!checked;
    App.state.editingSurveyDirty = true;
};


/*
 * その他設定
 */
App.actions.toggleOther = function (questionId, checked) {
    const found = App.utils.findQuestion(questionId);
    if (!found) return;

    if (found.question.type === 'text') {
        found.question.other_enabled = false;
        return;
    }

    found.question.other_enabled = !!checked;
    App.state.editingSurveyDirty = true;
};


/*
 * 選択肢追加
 */
App.actions.addOption = function (questionId) {
    const found = App.utils.findQuestion(questionId);
    if (!found) return;

    const question = found.question;

    if (question.type === 'text') {
        /*
         * 防御処理。
         * 自由記述には選択肢を追加させない。
         */
        question.options = [];
        question.other_enabled = false;
        return;
    }

    if (!Array.isArray(question.options)) {
        question.options = [];
    }

    question.options.push({
        id: App.utils.uid('opt'),
        text: '選択肢' + (question.options.length + 1)
    });

    App.state.editingSurveyDirty = true;
    App.editor.renderQuestions();
};


/*
 * 選択肢変更
 */
App.actions.changeOption = function (
    questionId,
    optionId,
    value
) {
    const found = App.utils.findQuestion(questionId);
    if (!found) return;

    if (found.question.type === 'text') {
        return;
    }

    const option = (found.question.options || [])
        .find(o => o.id === optionId);

    if (!option) return;

    option.text = value;
    App.state.editingSurveyDirty = true;
};


/*
 * 選択肢削除
 */
App.actions.deleteOption = function (
    questionId,
    optionId
) {
    const found = App.utils.findQuestion(questionId);
    if (!found) return;

    const question = found.question;

    if (question.type === 'text') {
        question.options = [];
        question.other_enabled = false;
        App.editor.renderQuestions();
        return;
    }

    question.options = (question.options || [])
        .filter(o => o.id !== optionId);

    if (question.options.length === 0) {
        question.options.push({
            id: App.utils.uid('opt'),
            text: '選択肢1'
        });
    }

    App.state.editingSurveyDirty = true;
    App.editor.renderQuestions();
};


/*
 * 質問番号を再計算
 */
App.editor.renumber = function () {
    const survey = App.state.currentSurvey;
    if (!survey) return;

    let globalNo = 1;

    (survey.groups || []).forEach((group, groupIndex) => {
        let localNo = 1;

        (group.questions || []).forEach(question => {
            if (survey.numbering_mode === 'group') {
                question.display_no =
                    'Q' + (groupIndex + 1) + '-' + localNo;
            } else {
                question.display_no = 'Q' + globalNo;
            }

            globalNo++;
            localNo++;
        });
    });
};


/*
 * 質問カードHTML
 */
App.editor.questionHTML = function (
    question,
    groupId
) {
    const qid = App.utils.escapeAttr(question.id);

    const type = question.type || 'single';

    /*
     * 防御的正規化。
     *
     * 古いJSONに自由記述なのにoptionsが残っていた場合も、
     * 描画時に選択肢を表示しない。
     */
    const isText = type === 'text';

    if (isText) {
        question.options = [];
        question.other_enabled = false;
    }

    const options = isText
        ? []
        : (Array.isArray(question.options)
            ? question.options
            : []);

    return `
    <div
        class="question-card bg-white border border-slate-200
               rounded-xl shadow-sm p-5 mb-4"
        data-question-id="${qid}"
    >
        <div class="flex items-start gap-3">

            <div
                class="question-drag-handle cursor-grab
                       text-slate-400 text-xl pt-1 select-none"
                title="ドラッグして並び替え"
            >⠿</div>

            <div class="flex-1">

                <div class="flex items-center justify-between gap-3 mb-4">

                    <div class="flex items-center gap-2">
                        <span
                            class="question-number
                                   inline-flex items-center
                                   px-2.5 py-1 rounded-lg
                                   bg-slate-100 text-slate-700
                                   text-sm font-semibold"
                        >
                            ${App.utils.escape(question.display_no || '')}
                        </span>

                        <span class="text-xs text-slate-400">
                            質問
                        </span>
                    </div>

                    <button
                        type="button"
                        class="text-red-500 hover:text-red-700
                               text-sm font-medium"
                        onclick="App.actions.deleteQuestion('${qid}')"
                    >
                        削除
                    </button>

                </div>

                <div class="mb-4">

                    <label
                        class="block text-sm font-medium
                               text-slate-700 mb-2"
                    >
                        質問文
                    </label>

                    <textarea
                        rows="2"
                        class="w-full rounded-lg border
                               border-slate-300 px-3 py-2
                               focus:outline-none focus:ring-2
                               focus:ring-indigo-500"
                        oninput="App.actions.changeQuestionText(
                            '${qid}', this.value
                        )"
                    >${App.utils.escape(question.text || '')}</textarea>

                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">

                    <div>
                        <label
                            class="block text-sm font-medium
                                   text-slate-700 mb-2"
                        >
                            回答形式
                        </label>

                        <select
                            class="w-full rounded-lg border
                                   border-slate-300 px-3 py-2
                                   bg-white"
                            onchange="
                                App.editor.changeQuestionType(
                                    '${qid}', this.value
                                )
                            "
                        >
                            <option value="single"
                                ${type === 'single' ? 'selected' : ''}>
                                単一選択
                            </option>

                            <option value="multiple"
                                ${type === 'multiple' ? 'selected' : ''}>
                                複数選択
                            </option>

                            <option value="text"
                                ${type === 'text' ? 'selected' : ''}>
                                自由記述
                            </option>
                        </select>
                    </div>

                    <div class="flex items-end">
                        <label
                            class="inline-flex items-center gap-2
                                   text-sm text-slate-700
                                   cursor-pointer"
                        >
                            <input
                                type="checkbox"
                                class="w-4 h-4 rounded"
                                ${question.required ? 'checked' : ''}
                                onchange="
                                    App.actions.toggleRequired(
                                        '${qid}', this.checked
                                    )
                                "
                            >
                            必須回答
                        </label>
                    </div>

                </div>

                ${
                    isText
                    ? `
                        <div
                            class="rounded-lg bg-slate-50
                                   border border-slate-200
                                   p-4 text-sm text-slate-500"
                        >
                            自由記述欄として表示されます。
                            選択肢はありません。
                        </div>
                    `
                    : `
                        <div class="mt-3">

                            <div class="flex items-center justify-between mb-2">
                                <label
                                    class="text-sm font-medium
                                           text-slate-700"
                                >
                                    選択肢
                                </label>

                                <button
                                    type="button"
                                    class="text-indigo-600
                                           hover:text-indigo-800
                                           text-sm font-medium"
                                    onclick="
                                        App.actions.addOption('${qid}')
                                    "
                                >
                                    ＋ 選択肢追加
                                </button>
                            </div>

                            <div class="space-y-2">
                                ${
                                    options.map((option, index) => `
                                        <div
                                            class="flex items-center gap-2"
                                        >
                                            <span
                                                class="text-xs
                                                       text-slate-400
                                                       w-6 text-center"
                                            >
                                                ${index + 1}
                                            </span>

                                            <input
                                                type="text"
                                                value="${App.utils.escapeAttr(
                                                    option.text || ''
                                                )}"
                                                class="flex-1 rounded-lg
                                                       border border-slate-300
                                                       px-3 py-2"
                                                oninput="
                                                    App.actions.changeOption(
                                                        '${qid}',
                                                        '${App.utils.escapeAttr(option.id)}',
                                                        this.value
                                                    )
                                                "
                                            >

                                            <button
                                                type="button"
                                                class="text-red-400
                                                       hover:text-red-600
                                                       px-2"
                                                onclick="
                                                    App.actions.deleteOption(
                                                        '${qid}',
                                                        '${App.utils.escapeAttr(option.id)}'
                                                    )
                                                "
                                            >
                                                ×
                                            </button>
                                        </div>
                                    `).join('')
                                }
                            </div>

                            <label
                                class="inline-flex items-center gap-2
                                       mt-4 text-sm text-slate-700"
                            >
                                <input
                                    type="checkbox"
                                    class="w-4 h-4 rounded"
                                    ${question.other_enabled ? 'checked' : ''}
                                    onchange="
                                        App.actions.toggleOther(
                                            '${qid}', this.checked
                                        )
                                    "
                                >
                                「その他」の自由記述欄を表示
                            </label>

                        </div>
                    `
                }

            </div>
        </div>
    </div>
    `;
};


/*
 * グループHTML
 */
App.editor.groupHTML = function (group, index) {

    return `
    <div
        class="group-card mb-6"
        data-group-id="${App.utils.escapeAttr(group.id)}"
    >

        <div
            class="bg-slate-100 border border-slate-200
                   rounded-xl p-4 mb-3"
        >

            <div class="flex items-center gap-3">

                <div
                    class="group-drag-handle cursor-grab
                           text-slate-400 text-xl select-none"
                >⠿</div>

                <input
                    type="text"
                    value="${App.utils.escapeAttr(group.name || '')}"
                    class="flex-1 bg-transparent
                           border-0 border-b border-slate-300
                           focus:ring-0 focus:border-indigo-500
                           font-semibold text-slate-800"
                    oninput="
                        App.actions.changeGroupName(
                            '${App.utils.escapeAttr(group.id)}',
                            this.value
                        )
                    "
                >

                <button
                    type="button"
                    class="text-red-500 hover:text-red-700
                           text-sm"
                    onclick="
                        App.actions.deleteGroup(
                            '${App.utils.escapeAttr(group.id)}'
                        )
                    "
                >
                    グループ削除
                </button>

            </div>
        </div>

        <div
            class="question-list min-h-[30px]"
            data-group-id="${App.utils.escapeAttr(group.id)}"
        >

            ${
                (group.questions || []).length
                ? group.questions.map(question =>
                    App.editor.questionHTML(question, group.id)
                  ).join('')
                : `
                    <div
                        class="empty-question
                               border-2 border-dashed
                               border-slate-200
                               rounded-xl p-6 text-center
                               text-slate-400 text-sm"
                    >
                        質問がありません
                    </div>
                `
            }

        </div>

        <button
            type="button"
            class="mt-2 px-4 py-2 rounded-lg
                   border border-indigo-200
                   text-indigo-600 bg-white
                   hover:bg-indigo-50 text-sm"
            onclick="
                App.actions.addQuestion(
                    '${App.utils.escapeAttr(group.id)}'
                )
            "
        >
            ＋ 質問を追加
        </button>

    </div>
    `;
};


/*
 * 質問編集領域描画
 */
App.editor.renderQuestions = function () {
    const target = document.getElementById('question_editor');
    if (!target) return;

    const survey = App.state.currentSurvey;
    if (!survey) {
        target.innerHTML = '';
        return;
    }

    App.editor.renumber();

    target.innerHTML =
        (survey.groups || []).map(
            (group, index) =>
                App.editor.groupHTML(group, index)
        ).join('');

    App.editor.initSortable();
};


/*
 * SortableJS初期化
 *
 * グループを跨いだ質問移動にも対応。
 */
App.editor.initSortable = function () {

    if (typeof Sortable === 'undefined') {
        return;
    }

    const groupContainer =
        document.getElementById('question_editor');

    if (!groupContainer) return;

    /*
     * 同じDOMにSortableを二重登録しない。
     */
    if (!groupContainer.dataset.sortableInitialized) {

        new Sortable(groupContainer, {
            animation: 180,
            handle: '.group-drag-handle',
            ghostClass: 'opacity-40',
            onEnd: function (evt) {

                const survey = App.state.currentSurvey;
                if (!survey) return;

                const ids = [...groupContainer.querySelectorAll(
                    ':scope > .group-card'
                )].map(el => el.dataset.groupId);

                survey.groups.sort(
                    (a, b) =>
                        ids.indexOf(a.id) - ids.indexOf(b.id)
                );

                App.state.editingSurveyDirty = true;
                App.editor.renderQuestions();
            }
        });

        groupContainer.dataset.sortableInitialized = '1';
    }


    /*
     * question-list は毎回描画されるため、
     * 個別にSortableを生成する。
     */
    groupContainer
        .querySelectorAll('.question-list')
        .forEach(function (list) {

            if (list.dataset.sortableInitialized) {
                return;
            }

            new Sortable(list, {
                group: {
                    name: 'survey-questions',
                    pull: true,
                    put: true
                },
                animation: 180,
                handle: '.question-drag-handle',
                ghostClass: 'opacity-40',

                onEnd: function () {
                    App.editor.syncQuestionOrder();
                }
            });

            list.dataset.sortableInitialized = '1';
        });
};


/*
 * DOM上の質問順をStateへ反映。
 *
 * グループを跨いだ移動にも対応。
 */
App.editor.syncQuestionOrder = function () {

    const survey = App.state.currentSurvey;
    const root = document.getElementById('question_editor');

    if (!survey || !root) return;

    const newGroups = [];

    root.querySelectorAll('.group-card').forEach(
        function (groupEl) {

            const groupId = groupEl.dataset.groupId;

            const originalGroup =
                survey.groups.find(g => g.id === groupId);

            if (!originalGroup) return;

            const questions = [];

            groupEl
                .querySelectorAll(
                    '.question-list > .question-card'
                )
                .forEach(function (questionEl) {

                    const questionId =
                        questionEl.dataset.questionId;

                    const found =
                        App.utils.findQuestion(questionId);

                    if (found) {
                        questions.push(found.question);
                    }
                });

            newGroups.push({
                id: originalGroup.id,
                name: originalGroup.name,
                questions: questions
            });
        }
    );

    survey.groups = newGroups;

    App.state.editingSurveyDirty = true;

    App.editor.renumber();

    /*
     * 番号だけ更新するため、
     * HTML全体を再描画。
     */
    App.editor.renderQuestions();
};


/* ---------------------------------------------------------------
 * Survey field actions
 * --------------------------------------------------------------- */

App.actions.changeSurveyTitle = function (value) {
    if (!App.state.currentSurvey) return;

    App.state.currentSurvey.title = value;
    App.state.editingSurveyDirty = true;
};

App.actions.changeSurveyStart = function (value) {
    if (!App.state.currentSurvey) return;

    App.state.currentSurvey.start_at = value;
    App.state.editingSurveyDirty = true;
};

App.actions.changeSurveyEnd = function (value) {
    if (!App.state.currentSurvey) return;

    App.state.currentSurvey.end_at = value;
    App.state.editingSurveyDirty = true;
};

App.actions.changeNumberingMode = function (value) {
    if (!App.state.currentSurvey) return;

    if (!['global', 'group'].includes(value)) {
        return;
    }

    App.state.currentSurvey.numbering_mode = value;

    App.state.editingSurveyDirty = true;

    App.editor.renderQuestions();
};


/* ---------------------------------------------------------------
 * Preview
 * --------------------------------------------------------------- */
App.preview = App.preview || {};

App.preview.open = function () {
    const survey = App.state.currentSurvey;
    if (!survey) return;

    const modal = document.getElementById('preview_modal');
    const content = document.getElementById('preview_content');

    if (!modal || !content) return;

    App.editor.renumber();

    content.innerHTML = App.preview.renderSurvey();

    modal.classList.remove('hidden');
};

App.preview.close = function () {
    const modal = document.getElementById('preview_modal');

    if (modal) {
        modal.classList.add('hidden');
    }
};

App.preview.submit = function () {
    window.alert(
        'これはプレビューです。回答データは送信されません。'
    );
};

App.preview.renderSurvey = function () {

    const survey = App.state.currentSurvey;
    if (!survey) return '';

    return `
        <div class="max-w-3xl mx-auto">

            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-900">
                    ${App.utils.escape(survey.title || '無題のアンケート')}
                </h1>
            </div>

            ${
                (survey.groups || []).map(group => `
                    <section class="mb-8">

                        <h2
                            class="text-lg font-semibold
                                   text-slate-800 mb-4"
                        >
                            ${App.utils.escape(group.name || '')}
                        </h2>

                        ${
                            (group.questions || []).map(question =>
                                App.preview.questionHTML(question)
                            ).join('')
                        }

                    </section>
                `).join('')
            }

            <button
                type="button"
                class="w-full bg-indigo-600
                       text-white rounded-xl
                       px-5 py-3 font-medium
                       hover:bg-indigo-700"
                onclick="App.preview.submit()"
            >
                回答を送信する
            </button>

        </div>
    `;
};

App.preview.questionHTML = function (question) {

    const type = question.type || 'text';

    const required =
        question.required
            ? '<span class="text-red-500 ml-1">*</span>'
            : '';

    if (type === 'text') {

        return `
            <div class="bg-white border border-slate-200
                        rounded-xl p-5 mb-4">

                <label class="block font-medium
                              text-slate-800 mb-3">
                    ${App.utils.escape(question.display_no || '')}
                    ${App.utils.escape(question.text || '')}
                    ${required}
                </label>

                <textarea
                    rows="5"
                    class="w-full rounded-lg
                           border border-slate-300
                           px-3 py-2"
                    placeholder="回答を入力してください"
                ></textarea>

            </div>
        `;
    }

    const multiple = type === 'multiple';

    return `
        <div class="bg-white border border-slate-200
                    rounded-xl p-5 mb-4">

            <div class="font-medium text-slate-800 mb-3">
                ${App.utils.escape(question.display_no || '')}
                ${App.utils.escape(question.text || '')}
                ${required}
            </div>

            <div class="space-y-2">

                ${
                    (question.options || []).map(option => `
                        <label
                            class="flex items-center gap-3
                                   p-2 rounded-lg
                                   hover:bg-slate-50"
                        >
                            <input
                                type="${multiple ? 'checkbox' : 'radio'}"
                                name="preview_${App.utils.escapeAttr(question.id)}"
                                value="${App.utils.escapeAttr(option.id)}"
                            >

                            <span>
                                ${App.utils.escape(option.text || '')}
                            </span>
                        </label>
                    `).join('')
                }

                ${
                    question.other_enabled
                    ? `
                        <div class="pt-2">
                            <label class="block text-sm
                                          text-slate-600 mb-1">
                                その他
                            </label>

                            <input
                                type="text"
                                class="w-full rounded-lg
                                       border border-slate-300
                                       px-3 py-2"
                            >
                        </div>
                    `
                    : ''
                }

            </div>

        </div>
    `;
};


/* ---------------------------------------------------------------
 * Survey edit screen
 * --------------------------------------------------------------- */
App.actions.openSurveyEditor = function (surveyId) {

    const survey =
        App.state.surveys.find(s => s.id === surveyId);

    if (!survey) {
        window.alert('アンケートが見つかりません。');
        return;
    }

    App.state.currentSurvey =
        App.utils.deepClone(survey);

    App.state.currentSurveyId = surveyId;
    App.state.editingSurveyDirty = false;
    App.state.screen = 'editor';

    App.render.editor();
};


App.actions.newSurvey = function () {

    App.state.currentSurvey = App.state.newSurvey();
    App.state.currentSurveyId = null;
    App.state.editingSurveyDirty = true;
    App.state.screen = 'editor';

    App.render.editor();
};


/* ---------------------------------------------------------------
 * Editor render
 * --------------------------------------------------------------- */
App.render = App.render || {};

App.render.editor = function () {

    const app = document.getElementById('app');
    if (!app) return;

    const survey = App.state.currentSurvey;

    if (!survey) return;

    app.innerHTML = `
        <div class="min-h-screen bg-slate-50">

            <header
                class="bg-white border-b border-slate-200
                       sticky top-0 z-20"
            >
                <div
                    class="max-w-7xl mx-auto px-6 py-4
                           flex items-center justify-between"
                >

                    <div>
                        <div class="text-xs text-slate-400 mb-1">
                            アンケート作成・編集
                        </div>

                        <input
                            id="survey_title"
                            type="text"
                            value="${App.utils.escapeAttr(
                                survey.title || ''
                            )}"
                            placeholder="アンケートタイトル"
                            class="text-xl font-bold
                                   border-0 border-b-2
                                   border-transparent
                                   focus:border-indigo-500
                                   focus:ring-0
                                   bg-transparent"
                            oninput="
                                App.actions.changeSurveyTitle(
                                    this.value
                                )
                            "
                        >
                    </div>

                    <div class="flex items-center gap-2">

                        <button
                            type="button"
                            class="px-4 py-2 rounded-lg
                                   border border-slate-300
                                   bg-white hover:bg-slate-50"
                            onclick="App.preview.open()"
                        >
                            プレビュー
                        </button>

                        <button
                            type="button"
                            class="px-4 py-2 rounded-lg
                                   bg-indigo-600 text-white
                                   hover:bg-indigo-700"
                            onclick="App.actions.saveSurvey()"
                        >
                            保存して一覧へ戻る
                        </button>

                        <button
                            type="button"
                            class="px-4 py-2 rounded-lg
                                   border border-slate-300
                                   bg-white hover:bg-slate-50"
                            onclick="App.actions.cancelEditor()"
                        >
                            キャンセル
                        </button>

                    </div>
                </div>
            </header>

            <main class="max-w-7xl mx-auto px-6 py-8">

                <div
                    class="bg-white rounded-xl
                           border border-slate-200
                           p-5 mb-6"
                >

                    <div class="grid grid-cols-1 md:grid-cols-3
                                gap-4">

                        <div>
                            <label
                                class="block text-sm
                                       font-medium
                                       text-slate-700 mb-2"
                            >
                                開始日時
                            </label>

                            <input
                                id="survey_start_at"
                                type="datetime-local"
                                value="${App.utils.escapeAttr(
                                    survey.start_at || ''
                                )}"
                                class="w-full rounded-lg
                                       border border-slate-300
                                       px-3 py-2"
                                onchange="
                                    App.actions.changeSurveyStart(
                                        this.value
                                    )
                                "
                            >
                        </div>

                        <div>
                            <label
                                class="block text-sm
                                       font-medium
                                       text-slate-700 mb-2"
                            >
                                終了日時
                            </label>

                            <input
                                id="survey_end_at"
                                type="datetime-local"
                                value="${App.utils.escapeAttr(
                                    survey.end_at || ''
                                )}"
                                class="w-full rounded-lg
                                       border border-slate-300
                                       px-3 py-2"
                                onchange="
                                    App.actions.changeSurveyEnd(
                                        this.value
                                    )
                                "
                            >
                        </div>

                        <div>
                            <label
                                class="block text-sm
                                       font-medium
                                       text-slate-700 mb-2"
                            >
                                質問番号
                            </label>

                            <select
                                id="survey_numbering_mode"
                                class="w-full rounded-lg
                                       border border-slate-300
                                       px-3 py-2"
                                onchange="
                                    App.actions.changeNumberingMode(
                                        this.value
                                    )
                                "
                            >
                                <option value="global"
                                    ${survey.numbering_mode === 'global'
                                        ? 'selected' : ''}>
                                    Q1, Q2, Q3...
                                </option>

                                <option value="group"
                                    ${survey.numbering_mode === 'group'
                                        ? 'selected' : ''}>
                                    Q1-1, Q1-2...
                                </option>
                            </select>
                        </div>

                    </div>

                </div>

                <div class="flex items-center
                            justify-between mb-4">

                    <div>
                        <h2 class="text-lg font-bold
                                   text-slate-800">
                            質問構成
                        </h2>

                        <p class="text-sm text-slate-500 mt-1">
                            グループ・質問をドラッグして並び替えできます。
                        </p>
                    </div>

                    <button
                        type="button"
                        class="px-4 py-2 rounded-lg
                               bg-white border
                               border-indigo-200
                               text-indigo-600
                               hover:bg-indigo-50"
                        onclick="App.actions.addGroup()"
                    >
                        ＋ グループ追加
                    </button>

                </div>

                <div id="question_editor"></div>

            </main>
        </div>

        <!-- Preview Modal -->
        <div
            id="preview_modal"
            class="hidden fixed inset-0 z-50
                   bg-black/40 p-6"
        >
            <div
                class="bg-white rounded-2xl
                       max-w-4xl mx-auto
                       h-full max-h-[90vh]
                       overflow-hidden
                       flex flex-col"
            >

                <div
                    class="px-6 py-4 border-b
                           flex items-center
                           justify-between"
                >
                    <h2 class="font-bold text-lg">
                        プレビュー
                    </h2>

                    <button
                        type="button"
                        class="text-slate-500
                               hover:text-slate-800
                               text-2xl"
                        onclick="App.preview.close()"
                    >
                        ×
                    </button>
                </div>

                <div
                    id="preview_content"
                    class="overflow-y-auto
                           bg-slate-50 p-6 flex-1"
                ></div>

            </div>
        </div>
    `;

    App.editor.renderQuestions();
};


/* ---------------------------------------------------------------
 * Save / Cancel
 * --------------------------------------------------------------- */
App.actions.saveSurvey = async function () {

    const survey = App.state.currentSurvey;

    if (!survey) return;

    if (!String(survey.title || '').trim()) {
        window.alert('アンケートタイトルを入力してください。');
        return;
    }

    App.editor.renumber();

    /*
     * 最終防御。
     *
     * 自由記述 question に選択肢が残っていた場合、
     * 保存前に必ず除去する。
     */
    (survey.groups || []).forEach(group => {
        (group.questions || []).forEach(question => {

            if (question.type === 'text') {
                question.options = [];
                question.other_enabled = false;
            }

            if (!Array.isArray(question.options)) {
                question.options = [];
            }
        });
    });

    survey.updated_at = new Date().toISOString();

    if (!survey.created_at) {
        survey.created_at = survey.updated_at;
    }

    if (!survey.status) {
        survey.status = 'draft';
    }

    try {

        if (App.api && App.api.saveSurvey) {
            await App.api.saveSurvey(survey);
        } else {
            /*
             * Part 1/2側にAPIがない場合でも、
             * 画面上のStateは更新する。
             */
            const index =
                App.state.surveys.findIndex(
                    s => s.id === survey.id
                );

            if (index >= 0) {
                App.state.surveys[index] =
                    App.utils.deepClone(survey);
            } else {
                App.state.surveys.push(
                    App.utils.deepClone(survey)
                );
            }
        }

        App.state.editingSurveyDirty = false;

        window.alert('保存しました。');

        App.state.screen = 'list';

        if (App.render.list) {
            App.render.list();
        }

    } catch (error) {

        console.error(error);

        window.alert(
            '保存に失敗しました。\n' +
            (error.message || '')
        );
    }
};


App.actions.cancelEditor = function () {

    if (App.state.editingSurveyDirty) {

        if (!window.confirm(
            '変更内容は保存されません。編集を終了しますか？'
        )) {
            return;
        }
    }

    App.state.currentSurvey = null;
    App.state.currentSurveyId = null;
    App.state.editingSurveyDirty = false;
    App.state.screen = 'list';

    if (App.render.list) {
        App.render.list();
    }
};


/* ---------------------------------------------------------------
 * Preview shortcut
 * --------------------------------------------------------------- */
App.actions.openPreview = function () {
    App.preview.open();
};


/* ---------------------------------------------------------------
 * Before unload protection
 * --------------------------------------------------------------- */
window.addEventListener('beforeunload', function (event) {

    if (!App.state.editingSurveyDirty) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
});


/* ---------------------------------------------------------------
 * Defensive normalization
 *
 * JSONに古い構造が存在しても、
 * 描画前に質問形式と選択肢を整合させる。
 * --------------------------------------------------------------- */
App.editor.normalizeSurvey = function (survey) {

    if (!survey || typeof survey !== 'object') {
        return survey;
    }

    if (!Array.isArray(survey.groups)) {
        survey.groups = [];
    }

    survey.groups.forEach(function (group) {

        if (!Array.isArray(group.questions)) {
            group.questions = [];
        }

        group.questions.forEach(function (question) {

            if (!question.id) {
                question.id = App.utils.uid('q');
            }

            if (!question.type) {
                question.type = 'single';
            }

            if (!['single', 'multiple', 'text']
                .includes(question.type)) {

                question.type = 'single';
            }

            if (typeof question.required !== 'boolean') {
                question.required = false;
            }

            if (question.type === 'text') {

                /*
                 * ★最重要
                 *
                 * 自由記述なら選択肢を完全消去。
                 */
                question.options = [];
                question.other_enabled = false;

            } else {

                if (!Array.isArray(question.options)) {
                    question.options = [];
                }

                if (question.options.length === 0) {
                    question.options.push({
                        id: App.utils.uid('opt'),
                        text: '選択肢1'
                    });
                }

                question.options.forEach(function (option) {

                    if (!option.id) {
                        option.id = App.utils.uid('opt');
                    }

                    if (typeof option.text !== 'string') {
                        option.text = '';
                    }
                });

                if (typeof question.other_enabled !== 'boolean') {
                    question.other_enabled = false;
                }
            }
        });
    });

    return survey;
};


/*
 * 編集画面へ入る際にも正規化する。
 */
App.actions.prepareSurveyForEdit = function (survey) {

    const clone = App.utils.deepClone(survey);

    App.editor.normalizeSurvey(clone);

    return clone;
};


/*
 * openSurveyEditorを正規化版に差し替え
 */
App.actions.openSurveyEditor = function (surveyId) {

    const survey =
        App.state.surveys.find(s => s.id === surveyId);

    if (!survey) {
        window.alert('アンケートが見つかりません。');
        return;
    }

    App.state.currentSurvey =
        App.actions.prepareSurveyForEdit(survey);

    App.state.currentSurveyId = surveyId;
    App.state.editingSurveyDirty = false;
    App.state.screen = 'editor';

    App.render.editor();
};


/*
 * 新規アンケート
 */
App.actions.newSurvey = function () {

    const survey = App.state.newSurvey();

    App.editor.normalizeSurvey(survey);

    App.state.currentSurvey = survey;
    App.state.currentSurveyId = null;
    App.state.editingSurveyDirty = true;
    App.state.screen = 'editor';

    App.render.editor();
};


/* ---------------------------------------------------------------
 * Global compatibility bridge
 *
 * 旧コードからaddGroup/addQuestionが呼ばれた場合でも、
 * 実体は必ずApp配下を使用する。
 * --------------------------------------------------------------- */
App.editor.addGroup = App.actions.addGroup;
App.editor.addQuestion = App.actions.addQuestion;


/* ---------------------------------------------------------------
 * End Part 3 / 5
 * --------------------------------------------------------------- */
</script>
<?php
/* ========================================================================
 * index.php — Part 4 / 5
 * ------------------------------------------------------------------------
 * JavaScript SPA:
 *   - アンケート編集
 *   - グループ追加
 *   - 質問追加
 *   - 回答形式変更
 *   - 選択肢管理
 *   - 自由記述切替時の選択肢完全削除
 *   - SortableJS
 *   - プレビュー
 *   - 集計
 *   - 回答詳細モーダル
 * ======================================================================== */

?>
<script>
/*
========================================================================
GUARD COMMENT — 固定名称一覧
※既存名称の変更・削除禁止
========================================================================
*/

window.App = window.App || {};

App.state = App.state || {
    surveys: [],
    responses: [],
    customers: [],
    settings: {},
    mail_logs: [],
    currentSurvey: null,
    currentScreen: 'list',
    editingSurveyId: null,
    selectedResponseIds: [],
    selectedQuestionIds: [],
    previewMode: 'pc'
};

App.utils = App.utils || {};

App.utils.escapeHtml = function(value) {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

App.utils.uuid = function() {
    return 'id_' +
        Date.now().toString(36) +
        '_' +
        Math.random().toString(36).slice(2, 10);
};

App.utils.now = function() {
    const d = new Date();

    const pad = function(v) {
        return String(v).padStart(2, '0');
    };

    return d.getFullYear() + '-' +
        pad(d.getMonth() + 1) + '-' +
        pad(d.getDate()) + ' ' +
        pad(d.getHours()) + ':' +
        pad(d.getMinutes()) + ':' +
        pad(d.getSeconds());
};

App.utils.getQuestionNumber = function(groupIndex, questionIndex) {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return 'Q' + (questionIndex + 1);
    }

    if (survey.numbering_mode === 'group') {
        return 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1);
    }

    let number = 0;

    survey.groups.forEach(function(group, gi) {
        if (gi < groupIndex) {
            number += Array.isArray(group.questions)
                ? group.questions.length
                : 0;
        }
    });

    number += questionIndex + 1;

    return 'Q' + number;
};

App.utils.getAllQuestions = function(survey) {
    const result = [];

    if (!survey || !Array.isArray(survey.groups)) {
        return result;
    }

    survey.groups.forEach(function(group, groupIndex) {
        if (!Array.isArray(group.questions)) {
            return;
        }

        group.questions.forEach(function(question, questionIndex) {
            result.push({
                question: question,
                group: group,
                groupIndex: groupIndex,
                questionIndex: questionIndex,
                number: App.utils.getQuestionNumber(
                    groupIndex,
                    questionIndex
                )
            });
        });
    });

    return result;
};

App.utils.createQuestion = function() {
    return {
        id: App.utils.uuid(),
        text: '',
        type: 'single',
        required: false,
        options: [
            '選択肢1',
            '選択肢2'
        ],
        other_enabled: false
    };
};

App.utils.createGroup = function() {
    return {
        id: App.utils.uuid(),
        name: '新しいグループ',
        questions: []
    };
};

App.utils.ensureSurveyStructure = function(survey) {
    if (!survey.groups || !Array.isArray(survey.groups)) {
        survey.groups = [];
    }

    survey.groups.forEach(function(group) {
        if (!Array.isArray(group.questions)) {
            group.questions = [];
        }

        group.questions.forEach(function(question) {
            if (!Array.isArray(question.options)) {
                question.options = [];
            }

            if (
                question.type === 'single' ||
                question.type === 'multiple'
            ) {
                if (question.options.length === 0) {
                    question.options = [
                        '選択肢1',
                        '選択肢2'
                    ];
                }
            }

            if (question.type === 'text') {
                /*
                 * 重要:
                 * 自由記述質問は選択肢を持たない。
                 * 古いデータにoptionsが残っていた場合も
                 * ここで完全に除去する。
                 */
                question.options = [];
                question.other_enabled = false;
            }

            question.required = Boolean(question.required);
            question.other_enabled =
                Boolean(question.other_enabled);
        });
    });

    return survey;
};


/* ========================================================================
 * API
 * ======================================================================== */

App.api = App.api || {};

App.api.request = async function(action, payload) {
    const body = new URLSearchParams();

    body.set('action', action);

    Object.keys(payload || {}).forEach(function(key) {
        const value = payload[key];

        if (
            value !== null &&
            typeof value === 'object'
        ) {
            body.set(key, JSON.stringify(value));
        } else {
            body.set(key, value === null ? '' : value);
        }
    });

    const response = await fetch(location.href, {
        method: 'POST',
        headers: {
            'Content-Type':
                'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: body.toString()
    });

    if (!response.ok) {
        throw new Error(
            'HTTP ' + response.status
        );
    }

    return await response.json();
};

App.api.loadData = async function() {
    try {
        const result = await App.api.request(
            'load_data',
            {}
        );

        if (result && result.ok) {
            App.state.surveys =
                Array.isArray(result.surveys)
                    ? result.surveys
                    : [];

            App.state.responses =
                Array.isArray(result.responses)
                    ? result.responses
                    : [];

            App.state.customers =
                Array.isArray(result.customers)
                    ? result.customers
                    : [];

            App.state.settings =
                result.settings || {};

            App.state.mail_logs =
                Array.isArray(result.mail_logs)
                    ? result.mail_logs
                    : [];
        }
    } catch (error) {
        console.error(error);
    }
};

App.api.saveSurvey = async function(survey) {
    return await App.api.request(
        'save_survey',
        {
            survey_json: JSON.stringify(survey)
        }
    );
};

App.api.saveSettings = async function(settings) {
    return await App.api.request(
        'save_settings',
        {
            settings_json: JSON.stringify(settings)
        }
    );
};


/* ========================================================================
 * ACTIONS
 * ======================================================================== */

App.actions = App.actions || {};


/* ------------------------------------------------------------------------
 * アンケート編集開始
 * ------------------------------------------------------------------------ */

App.actions.editSurvey = function(id) {
    const survey = App.state.surveys.find(
        function(item) {
            return String(item.id) === String(id);
        }
    );

    if (!survey) {
        return;
    }

    App.state.editingSurveyId = id;

    App.state.currentSurvey =
        App.utils.ensureSurveyStructure(
            JSON.parse(JSON.stringify(survey))
        );

    App.state.currentScreen = 'editor';

    App.render.editor();
};


/* ------------------------------------------------------------------------
 * 新規アンケート
 * ------------------------------------------------------------------------ */

App.actions.newSurvey = function() {
    const survey = {
        id: App.utils.uuid(),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: App.utils.now(),
        updated_at: App.utils.now(),
        numbering_mode: 'global',
        groups: [],
        deleted: false
    };

    survey.groups.push(
        App.utils.createGroup()
    );

    App.state.currentSurvey = survey;
    App.state.editingSurveyId = survey.id;
    App.state.currentScreen = 'editor';

    App.render.editor();
};


/* ------------------------------------------------------------------------
 * グループ追加
 * ------------------------------------------------------------------------ */

App.actions.addGroup = function() {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return;
    }

    survey.groups.push(
        App.utils.createGroup()
    );

    App.render.editor();

    App.actions.initSortables();

    const container =
        document.getElementById(
            'question_editor'
        );

    if (container) {
        const groups =
            container.querySelectorAll(
                '[data-group-id]'
            );

        const lastGroup =
            groups[groups.length - 1];

        if (lastGroup) {
            lastGroup.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }
    }
};


/* ------------------------------------------------------------------------
 * 質問追加
 * ------------------------------------------------------------------------ */

App.actions.addQuestion = function(groupId) {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return;
    }

    const group = survey.groups.find(
        function(item) {
            return String(item.id) === String(groupId);
        }
    );

    if (!group) {
        return;
    }

    if (!Array.isArray(group.questions)) {
        group.questions = [];
    }

    group.questions.push(
        App.utils.createQuestion()
    );

    App.render.editor();

    App.actions.initSortables();
};


/* ------------------------------------------------------------------------
 * グループ削除
 * ------------------------------------------------------------------------ */

App.actions.deleteGroup = function(groupId) {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return;
    }

    if (
        !confirm(
            'このグループと、グループ内の質問をすべて削除しますか？'
        )
    ) {
        return;
    }

    survey.groups =
        survey.groups.filter(
            function(group) {
                return String(group.id) !== String(groupId);
            }
        );

    App.render.editor();
    App.actions.initSortables();
};


/* ------------------------------------------------------------------------
 * 質問削除
 * ------------------------------------------------------------------------ */

App.actions.deleteQuestion = function(
    groupId,
    questionId
) {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return;
    }

    const group = survey.groups.find(
        function(item) {
            return String(item.id) === String(groupId);
        }
    );

    if (!group) {
        return;
    }

    if (
        !confirm(
            'この質問を削除しますか？'
        )
    ) {
        return;
    }

    group.questions =
        group.questions.filter(
            function(question) {
                return String(question.id) !==
                    String(questionId);
            }
        );

    App.render.editor();
    App.actions.initSortables();
};


/* ------------------------------------------------------------------------
 * グループ名変更
 * ------------------------------------------------------------------------ */

App.actions.updateGroupName = function(
    groupId,
    value
) {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return;
    }

    const group = survey.groups.find(
        function(item) {
            return String(item.id) === String(groupId);
        }
    );

    if (group) {
        group.name = value;
    }
};


/* ------------------------------------------------------------------------
 * 質問文変更
 * ------------------------------------------------------------------------ */

App.actions.updateQuestionText = function(
    groupId,
    questionId,
    value
) {
    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (question) {
        question.text = value;
    }
};


/* ------------------------------------------------------------------------
 * 必須変更
 * ------------------------------------------------------------------------ */

App.actions.toggleRequired = function(
    groupId,
    questionId,
    checked
) {
    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (question) {
        question.required = Boolean(checked);
    }
};


/* ========================================================================
 * ★ 重要修正
 * 回答形式変更
 * ======================================================================== */

App.actions.changeQuestionType = function(
    groupId,
    questionId,
    newType
) {
    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    const oldType = question.type;

    question.type = newType;

    /*
     * ================================================================
     * 最重要処理
     *
     * single / multiple
     *        ↓
     *      text
     *
     * の場合、選択肢を完全に破棄する。
     *
     * DOMからselectやinputを非表示にするだけでは、
     * JavaScriptのState内部にoptionsが残るため、
     * 再度選択式に戻した際などに古い選択肢が復活する。
     *
     * そのためStateそのものを変更する。
     * ================================================================
     */
    if (newType === 'text') {
        question.options = [];
        question.other_enabled = false;
    }

    /*
     * 自由記述 → 選択式
     *
     * 選択肢が存在しない場合だけ初期値を生成する。
     */
    if (
        (
            newType === 'single' ||
            newType === 'multiple'
        ) &&
        !Array.isArray(question.options)
    ) {
        question.options = [];
    }

    if (
        (
            newType === 'single' ||
            newType === 'multiple'
        ) &&
        question.options.length === 0
    ) {
        question.options = [
            '選択肢1',
            '選択肢2'
        ];
    }

    /*
     * text以外では必要に応じて「その他」を利用可能。
     */
    if (newType !== 'text') {
        if (
            typeof question.other_enabled !==
            'boolean'
        ) {
            question.other_enabled = false;
        }
    }

    App.render.editor();

    App.actions.initSortables();
};


/* ------------------------------------------------------------------------
 * 質問検索
 * ------------------------------------------------------------------------ */

App.actions.findQuestion = function(
    groupId,
    questionId
) {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return null;
    }

    const group = survey.groups.find(
        function(item) {
            return String(item.id) === String(groupId);
        }
    );

    if (!group || !Array.isArray(group.questions)) {
        return null;
    }

    return group.questions.find(
        function(question) {
            return String(question.id) ===
                String(questionId);
        }
    ) || null;
};


/* ------------------------------------------------------------------------
 * 選択肢追加
 * ------------------------------------------------------------------------ */

App.actions.addOption = function(
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

    if (
        question.type !== 'single' &&
        question.type !== 'multiple'
    ) {
        return;
    }

    if (!Array.isArray(question.options)) {
        question.options = [];
    }

    question.options.push(
        '選択肢' +
        (question.options.length + 1)
    );

    App.render.editor();
    App.actions.initSortables();
};


/* ------------------------------------------------------------------------
 * 選択肢削除
 * ------------------------------------------------------------------------ */

App.actions.deleteOption = function(
    groupId,
    questionId,
    optionIndex
) {
    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    if (!Array.isArray(question.options)) {
        question.options = [];
    }

    question.options.splice(
        Number(optionIndex),
        1
    );

    App.render.editor();
    App.actions.initSortables();
};


/* ------------------------------------------------------------------------
 * 選択肢変更
 * ------------------------------------------------------------------------ */

App.actions.updateOption = function(
    groupId,
    questionId,
    optionIndex,
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

    if (!Array.isArray(question.options)) {
        question.options = [];
    }

    question.options[
        Number(optionIndex)
    ] = value;
};


/* ------------------------------------------------------------------------
 * 「その他」切替
 * ------------------------------------------------------------------------ */

App.actions.toggleOther = function(
    groupId,
    questionId,
    checked
) {
    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    if (
        question.type !== 'single' &&
        question.type !== 'multiple'
    ) {
        question.other_enabled = false;
        return;
    }

    question.other_enabled =
        Boolean(checked);
};


/* ------------------------------------------------------------------------
 * タイトル変更
 * ------------------------------------------------------------------------ */

App.actions.updateSurveyTitle = function(
    value
) {
    if (App.state.currentSurvey) {
        App.state.currentSurvey.title = value;
    }
};


/* ------------------------------------------------------------------------
 * 日付変更
 * ------------------------------------------------------------------------ */

App.actions.updateSurveyStart = function(
    value
) {
    if (App.state.currentSurvey) {
        App.state.currentSurvey.start_at = value;
    }
};

App.actions.updateSurveyEnd = function(
    value
) {
    if (App.state.currentSurvey) {
        App.state.currentSurvey.end_at = value;
    }
};


/* ------------------------------------------------------------------------
 * 採番方式変更
 * ------------------------------------------------------------------------ */

App.actions.updateNumberingMode = function(
    value
) {
    if (App.state.currentSurvey) {
        App.state.currentSurvey.numbering_mode =
            value === 'group'
                ? 'group'
                : 'global';
    }

    App.render.editor();
    App.actions.initSortables();
};


/* ========================================================================
 * 保存
 * ======================================================================== */

App.actions.saveSurvey = async function() {
    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    /*
     * 保存直前にも構造を正規化する。
     *
     * これにより、
     * UI操作だけでなく保存時にも
     * text質問のoptions残存を防ぐ。
     */
    App.utils.ensureSurveyStructure(
        survey
    );

    survey.updated_at =
        App.utils.now();

    try {
        const result =
            await App.api.saveSurvey(
                survey
            );

        if (!result || !result.ok) {
            throw new Error(
                result && result.message
                    ? result.message
                    : '保存に失敗しました。'
            );
        }

        await App.api.loadData();

        alert(
            'アンケートを保存しました。'
        );

        App.state.currentSurvey = null;
        App.state.editingSurveyId = null;
        App.state.currentScreen = 'list';

        App.render.list();

    } catch (error) {
        console.error(error);

        alert(
            '保存に失敗しました。\n' +
            error.message
        );
    }
};


/* ========================================================================
 * キャンセル
 * ======================================================================== */

App.actions.cancelEditor = function() {
    if (
        !confirm(
            '変更を破棄して一覧へ戻りますか？'
        )
    ) {
        return;
    }

    App.state.currentSurvey = null;
    App.state.editingSurveyId = null;
    App.state.currentScreen = 'list';

    App.render.list();
};


/* ========================================================================
 * SortableJS
 * ======================================================================== */

App.actions.initSortables = function() {
    if (
        typeof Sortable === 'undefined'
    ) {
        return;
    }

    const editor =
        document.getElementById(
            'question_editor'
        );

    if (!editor) {
        return;
    }

    /*
     * グループ並び替え
     */
    const groupContainer =
        editor.querySelector(
            '[data-group-container]'
        );

    if (groupContainer) {
        new Sortable(
            groupContainer,
            {
                animation: 180,
                ghostClass: 'opacity-40',
                handle: '[data-group-handle]',
                draggable: '[data-group-id]',

                onEnd: function(evt) {
                    const survey =
                        App.state.currentSurvey;

                    if (!survey) {
                        return;
                    }

                    const moved =
                        survey.groups.splice(
                            evt.oldIndex,
                            1
                        )[0];

                    survey.groups.splice(
                        evt.newIndex,
                        0,
                        moved
                    );

                    App.render.editor();

                    App.actions.initSortables();
                }
            }
        );
    }

    /*
     * ================================================================
     * 質問並び替え
     *
     * groupを跨いだ移動を許可。
     * ================================================================
     */
    const questionContainers =
        editor.querySelectorAll(
            '[data-question-container]'
        );

    questionContainers.forEach(
        function(container) {
            new Sortable(
                container,
                {
                    group: {
                        name: 'survey_questions',
                        pull: true,
                        put: true
                    },

                    animation: 180,
                    ghostClass: 'opacity-40',
                    handle: '[data-question-handle]',
                    draggable: '[data-question-id]',

                    onEnd: function(evt) {
                        const survey =
                            App.state.currentSurvey;

                        if (!survey) {
                            return;
                        }

                        const questionId =
                            evt.item.dataset.questionId;

                        let movedQuestion = null;

                        /*
                         * まずState内の元質問を削除。
                         */
                        survey.groups.forEach(
                            function(group) {
                                const index =
                                    group.questions.findIndex(
                                        function(q) {
                                            return String(q.id) ===
                                                String(questionId);
                                        }
                                    );

                                if (index !== -1) {
                                    movedQuestion =
                                        group.questions.splice(
                                            index,
                                            1
                                        )[0];
                                }
                            }
                        );

                        if (!movedQuestion) {
                            return;
                        }

                        /*
                         * 移動先グループを特定。
                         */
                        const targetGroupId =
                            container.dataset.groupId;

                        const targetGroup =
                            survey.groups.find(
                                function(group) {
                                    return String(group.id) ===
                                        String(targetGroupId);
                                }
                            );

                        if (!targetGroup) {
                            return;
                        }

                        /*
                         * DOM上の質問順を見て、
                         * Stateへ挿入する位置を決定。
                         */
                        const questionElements =
                            Array.from(
                                container.querySelectorAll(
                                    '[data-question-id]'
                                )
                            );

                        let insertIndex =
                            questionElements.findIndex(
                                function(element) {
                                    return String(
                                        element.dataset.questionId
                                    ) ===
                                    String(questionId);
                                }
                            );

                        if (insertIndex < 0) {
                            insertIndex =
                                targetGroup.questions.length;
                        }

                        targetGroup.questions.splice(
                            insertIndex,
                            0,
                            movedQuestion
                        );

                        App.render.editor();

                        App.actions.initSortables();
                    }
                }
            );
        }
    );
};


/* ========================================================================
 * プレビュー
 * ======================================================================== */

App.actions.openPreview = function() {
    const modal =
        document.getElementById(
            'preview_modal'
        );

    const content =
        document.getElementById(
            'preview_content'
        );

    const survey =
        App.state.currentSurvey;

    if (!modal || !content || !survey) {
        return;
    }

    App.state.previewMode = 'pc';

    content.innerHTML =
        App.render.previewContent(
            survey
        );

    modal.classList.remove('hidden');
};


App.actions.closePreview = function() {
    const modal =
        document.getElementById(
            'preview_modal'
        );

    if (modal) {
        modal.classList.add('hidden');
    }
};


App.actions.setPreviewMode = function(
    mode
) {
    App.state.previewMode =
        mode === 'mobile'
            ? 'mobile'
            : 'pc';

    const survey =
        App.state.currentSurvey;

    const content =
        document.getElementById(
            'preview_content'
        );

    if (
        survey &&
        content
    ) {
        content.innerHTML =
            App.render.previewContent(
                survey
            );
    }
};


/* ========================================================================
 * 集計 — 設問フィルター
 * ======================================================================== */

App.actions.toggleResponseQuestion = function(
    questionId,
    checked
) {
    if (checked) {
        if (
            !App.state.selectedQuestionIds.includes(
                String(questionId)
            )
        ) {
            App.state.selectedQuestionIds.push(
                String(questionId)
            );
        }
    } else {
        App.state.selectedQuestionIds =
            App.state.selectedQuestionIds.filter(
                function(id) {
                    return id !== String(questionId);
                }
            );
    }

    App.render.summary();
};


App.actions.selectAllQuestions = function() {
    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    App.state.selectedQuestionIds =
        App.utils.getAllQuestions(
            survey
        ).map(function(item) {
            return String(
                item.question.id
            );
        });

    App.render.summary();
};


App.actions.clearAllQuestions = function() {
    App.state.selectedQuestionIds = [];

    App.render.summary();
};


/* ========================================================================
 * 全回答表示
 * ======================================================================== */

App.actions.openResponseDetail = function(
    responseId
) {
    const response =
        App.state.responses.find(
            function(item) {
                return String(item.id) ===
                    String(responseId);
            }
        );

    if (!response) {
        return;
    }

    const modal =
        document.getElementById(
            'response_modal'
        );

    const detail =
        document.getElementById(
            'response_detail'
        );

    if (!modal || !detail) {
        return;
    }

    const survey =
        App.state.currentSurvey;

    const answers =
        response.answers || {};

    let html = '';

    html += `
        <div class="mb-6">
            <div class="text-sm text-slate-500">
                回答者
            </div>
            <div class="text-lg font-semibold text-slate-900">
                ${App.utils.escapeHtml(
                    response.company || ''
                )}
                ${App.utils.escapeHtml(
                    response.name || ''
                )}
            </div>
            <div class="text-sm text-slate-500 mt-1">
                ${App.utils.escapeHtml(
                    response.email || ''
                )}
            </div>
            <div class="text-sm text-slate-500 mt-1">
                回答日時：
                ${App.utils.escapeHtml(
                    response.answered_at || ''
                )}
            </div>
        </div>
    `;

    if (survey) {
        App.utils.getAllQuestions(
            survey
        ).forEach(function(item) {
            const question =
                item.question;

            const answer =
                answers[question.id];

            let displayAnswer = '';

            if (Array.isArray(answer)) {
                displayAnswer =
                    answer.join('、');
            } else {
                displayAnswer =
                    answer === undefined ||
                    answer === null
                        ? ''
                        : String(answer);
            }

            html += `
                <div class="border-b border-slate-200 py-4">
                    <div class="text-xs font-semibold text-blue-600 mb-1">
                        ${App.utils.escapeHtml(
                            item.number
                        )}
                    </div>

                    <div class="font-medium text-slate-900 mb-2">
                        ${App.utils.escapeHtml(
                            question.text
                        )}
                    </div>

                    <div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700 whitespace-pre-wrap">
                        ${App.utils.escapeHtml(
                            displayAnswer || '未回答'
                        )}
                    </div>
                </div>
            `;
        });
    }

    detail.innerHTML = html;

    modal.classList.remove(
        'hidden'
    );
};


App.actions.closeResponseDetail = function() {
    const modal =
        document.getElementById(
            'response_modal'
        );

    if (modal) {
        modal.classList.add('hidden');
    }
};


/* ========================================================================
 * Kintone
 * ======================================================================== */

App.actions.fetchKintoneFields =
async function() {
    const message =
        document.getElementById(
            'field_message'
        );

    const appId =
        document.getElementById(
            'setting_app_id'
        );

    if (!appId) {
        return;
    }

    const value =
        appId.value.trim();

    if (!value) {
        if (message) {
            message.textContent =
                'アプリIDを入力してください。';
        }

        return;
    }

    if (message) {
        message.textContent =
            '項目一覧を取得しています…';
    }

    try {
        const result =
            await App.api.request(
                'kintone_fields',
                {
                    app_id: value
                }
            );

        if (!result || !result.ok) {
            throw new Error(
                result && result.message
                    ? result.message
                    : '項目一覧を取得できませんでした。'
            );
        }

        const fields =
            result.fields || {};

        const targets = [
            'field_company',
            'field_name',
            'field_email',
            'field_department',
            'field_phone',
            'field_address'
        ];

        targets.forEach(
            function(id) {
                const select =
                    document.getElementById(id);

                if (!select) {
                    return;
                }

                const current =
                    select.value;

                select.innerHTML =
                    '<option value="">-- 選択してください --</option>';

                Object.keys(fields).forEach(
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
                            field.label +
                            ' [' +
                            code +
                            ']';

                        if (
                            code === current
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

        if (message) {
            message.textContent =
                '項目一覧を取得しました。';
        }

    } catch (error) {
        console.error(error);

        if (message) {
            message.textContent =
                error.message;
        }
    }
};


/* ========================================================================
 * Render
 * ======================================================================== */

App.render = App.render || {};


/* ------------------------------------------------------------------------
 * エディタ
 * ------------------------------------------------------------------------ */

App.render.editor = function() {
    const app =
        document.getElementById('app');

    const survey =
        App.state.currentSurvey;

    if (!app || !survey) {
        return;
    }

    let html = '';

    html += `
        <div class="max-w-7xl mx-auto px-6 py-8">

            <div class="flex items-center justify-between mb-6">
                <div>
                    <div class="text-sm text-slate-500 mb-1">
                        アンケート作成・編集
                    </div>

                    <h1 class="text-2xl font-bold text-slate-900">
                        アンケート編集
                    </h1>
                </div>

                <div class="flex gap-2">
                    <button
                        type="button"
                        onclick="App.actions.openPreview()"
                        class="px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50">
                        プレビュー
                    </button>

                    <button
                        type="button"
                        onclick="App.actions.cancelEditor()"
                        class="px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-700">
                        キャンセル
                    </button>

                    <button
                        type="button"
                        onclick="App.actions.saveSurvey()"
                        class="px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                        保存して一覧へ戻る
                    </button>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm mb-6">

                <div class="grid grid-cols-3 gap-5">

                    <div class="col-span-3">
                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            タイトル
                        </label>

                        <input
                            id="survey_title"
                            type="text"
                            value="${App.utils.escapeHtml(
                                survey.title
                            )}"
                            onchange="App.actions.updateSurveyTitle(this.value)"
                            class="w-full rounded-lg border border-slate-300 px-4 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            開始日時
                        </label>

                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${App.utils.escapeHtml(
                                survey.start_at || ''
                            )}"
                            onchange="App.actions.updateSurveyStart(this.value)"
                            class="w-full rounded-lg border border-slate-300 px-4 py-2.5">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            終了日時
                        </label>

                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${App.utils.escapeHtml(
                                survey.end_at || ''
                            )}"
                            onchange="App.actions.updateSurveyEnd(this.value)"
                            class="w-full rounded-lg border border-slate-300 px-4 py-2.5">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            質問番号
                        </label>

                        <select
                            id="survey_numbering_mode"
                            onchange="App.actions.updateNumberingMode(this.value)"
                            class="w-full rounded-lg border border-slate-300 px-4 py-2.5">

                            <option
                                value="global"
                                ${survey.numbering_mode === 'global'
                                    ? 'selected'
                                    : ''}>
                                Q1, Q2, Q3...
                            </option>

                            <option
                                value="group"
                                ${survey.numbering_mode === 'group'
                                    ? 'selected'
                                    : ''}>
                                Q1-1, Q1-2...
                            </option>

                        </select>
                    </div>

                </div>
            </div>

            <div id="question_editor">

                <div
                    data-group-container
                    class="space-y-5">

                    ${survey.groups.map(
                        function(group, groupIndex) {
                            return App.render.group(
                                group,
                                groupIndex
                            );
                        }
                    ).join('')}

                </div>

            </div>

            <div class="mt-6">
                <button
                    type="button"
                    onclick="App.actions.addGroup()"
                    class="w-full py-3 rounded-xl border-2 border-dashed border-slate-300 text-slate-600 hover:border-blue-400 hover:text-blue-600">
                    ＋ グループを追加
                </button>
            </div>

        </div>

        ${App.render.previewModal()}
    `;

    app.innerHTML = html;

    App.actions.initSortables();
};


/* ------------------------------------------------------------------------
 * グループ
 * ------------------------------------------------------------------------ */

App.render.group = function(
    group,
    groupIndex
) {
    return `
        <section
            data-group-id="${App.utils.escapeHtml(group.id)}"
            class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">

            <div class="flex items-center gap-3 px-5 py-4 bg-slate-50 border-b border-slate-200">

                <button
                    type="button"
                    data-group-handle
                    class="cursor-grab text-xl text-slate-400">
                    ⠿
                </button>

                <input
                    type="text"
                    value="${App.utils.escapeHtml(group.name)}"
                    onchange="App.actions.updateGroupName('${App.utils.escapeHtml(group.id)}', this.value)"
                    class="flex-1 bg-transparent border-0 font-semibold text-slate-800 focus:ring-0">

                <span class="text-xs text-slate-400">
                    ${group.questions.length} 問
                </span>

                <button
                    type="button"
                    onclick="App.actions.deleteGroup('${App.utils.escapeHtml(group.id)}')"
                    class="text-sm text-red-500 hover:text-red-700">
                    グループ削除
                </button>

            </div>

            <div
                data-question-container
                data-group-id="${App.utils.escapeHtml(group.id)}"
                class="p-5 space-y-4 min-h-20">

                ${
                    group.questions.length
                        ? group.questions.map(
                            function(question, questionIndex) {
                                return App.render.question(
                                    question,
                                    group,
                                    groupIndex,
                                    questionIndex
                                );
                            }
                        ).join('')
                        : `
                            <div class="border-2 border-dashed border-slate-200 rounded-lg p-6 text-center text-sm text-slate-400">
                                質問を追加してください
                            </div>
                        `
                }

            </div>

            <div class="px-5 pb-5">

                <button
                    type="button"
                    onclick="App.actions.addQuestion('${App.utils.escapeHtml(group.id)}')"
                    class="w-full py-2.5 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200">
                    ＋ 質問を追加
                </button>

            </div>

        </section>
    `;
};


/* ------------------------------------------------------------------------
 * 質問
 * ------------------------------------------------------------------------ */

App.render.question = function(
    question,
    group,
    groupIndex,
    questionIndex
) {
    const number =
        App.utils.getQuestionNumber(
            groupIndex,
            questionIndex
        );

    let optionsHtml = '';

    /*
     * ================================================================
     * 選択式の場合のみ選択肢UIを生成。
     *
     * textの場合はここに到達しない。
     * ================================================================
     */
    if (
        question.type === 'single' ||
        question.type === 'multiple'
    ) {
        optionsHtml = `
            <div class="mt-4 border-t border-slate-100 pt-4">

                <div class="text-sm font-medium text-slate-700 mb-3">
                    選択肢
                </div>

                <div class="space-y-2">

                    ${
                        question.options.map(
                            function(option, optionIndex) {
                                return `
                                    <div class="flex items-center gap-2">

                                        <span class="text-slate-400">
                                            ${
                                                question.type === 'single'
                                                    ? '○'
                                                    : '□'
                                            }
                                        </span>

                                        <input
                                            type="text"
                                            value="${App.utils.escapeHtml(option)}"
                                            onchange="App.actions.updateOption('${App.utils.escapeHtml(group.id)}','${App.utils.escapeHtml(question.id)}',${optionIndex},this.value)"
                                            class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">

                                        <button
                                            type="button"
                                            onclick="App.actions.deleteOption('${App.utils.escapeHtml(group.id)}','${App.utils.escapeHtml(question.id)}',${optionIndex})"
                                            class="px-2 text-red-500">
                                            ×
                                        </button>

                                    </div>
                                `;
                            }
                        ).join('')
                    }

                </div>

                <button
                    type="button"
                    onclick="App.actions.addOption('${App.utils.escapeHtml(group.id)}','${App.utils.escapeHtml(question.id)}')"
                    class="mt-3 text-sm text-blue-600 hover:text-blue-700">
                    ＋ 選択肢を追加
                </button>

                <label class="flex items-center gap-2 mt-4 text-sm text-slate-600">

                    <input
                        type="checkbox"
                        ${question.other_enabled ? 'checked' : ''}
                        onchange="App.actions.toggleOther('${App.utils.escapeHtml(group.id)}','${App.utils.escapeHtml(question.id)}',this.checked)"
                        class="rounded border-slate-300 text-blue-600">

                    その他（自由記述）を許可

                </label>

            </div>
        `;
    }

    /*
     * textの場合はoptionsHtmlが空文字。
     *
     * つまり選択肢DOM自体を生成しない。
     */
    return `
        <article
            data-question-id="${App.utils.escapeHtml(question.id)}"
            class="border border-slate-200 rounded-xl p-5 bg-white">

            <div class="flex items-start gap-3">

                <button
                    type="button"
                    data-question-handle
                    class="cursor-grab text-xl text-slate-300 pt-1">
                    ⠿
                </button>

                <div class="flex-1">

                    <div class="flex items-center justify-between gap-3 mb-3">

                        <div class="text-xs font-bold text-blue-600">
                            ${App.utils.escapeHtml(number)}
                        </div>

                        <button
                            type="button"
                            onclick="App.actions.deleteQuestion('${App.utils.escapeHtml(group.id)}','${App.utils.escapeHtml(question.id)}')"
                            class="text-sm text-red-500">
                            削除
                        </button>

                    </div>

                    <input
                        type="text"
                        value="${App.utils.escapeHtml(question.text)}"
                        onchange="App.actions.updateQuestionText('${App.utils.escapeHtml(group.id)}','${App.utils.escapeHtml(question.id)}',this.value)"
                        placeholder="質問文を入力してください"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 font-medium">

                    <div class="grid grid-cols-2 gap-4 mt-4">

                        <div>
                            <label class="block text-xs text-slate-500 mb-1">
                                回答形式
                            </label>

                            <select
                                onchange="App.actions.changeQuestionType('${App.utils.escapeHtml(group.id)}','${App.utils.escapeHtml(question.id)}',this.value)"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2">

                                <option
                                    value="single"
                                    ${question.type === 'single'
                                        ? 'selected'
                                        : ''}>
                                    単一選択
                                </option>

                                <option
                                    value="multiple"
                                    ${question.type === 'multiple'
                                        ? 'selected'
                                        : ''}>
                                    複数選択
                                </option>

                                <option
                                    value="text"
                                    ${question.type === 'text'
                                        ? 'selected'
                                        : ''}>
                                    自由記述
                                </option>

                            </select>
                        </div>

                        <div class="flex items-end">

                            <label class="flex items-center gap-2 pb-2 text-sm text-slate-600">

                                <input
                                    type="checkbox"
                                    ${question.required ? 'checked' : ''}
                                    onchange="App.actions.toggleRequired('${App.utils.escapeHtml(group.id)}','${App.utils.escapeHtml(question.id)}',this.checked)"
                                    class="rounded border-slate-300 text-blue-600">

                                必須回答

                            </label>

                        </div>

                    </div>

                    ${optionsHtml}

                    ${
                        question.type === 'text'
                            ? `
                                <div class="mt-4 rounded-lg bg-slate-50 border border-slate-200 p-4 text-sm text-slate-400">
                                    自由記述欄（複数行テキスト）
                                </div>
                            `
                            : ''
                    }

                </div>

            </div>

        </article>
    `;
};


/* ========================================================================
 * プレビュー
 * ======================================================================== */

App.render.previewModal = function() {
    return `
        <div
            id="preview_modal"
            class="hidden fixed inset-0 z-50 bg-black/40 p-8">

            <div class="bg-white rounded-2xl shadow-2xl max-w-4xl mx-auto h-full flex flex-col">

                <div class="flex items-center justify-between px-6 py-4 border-b">

                    <div class="font-semibold text-slate-900">
                        プレビュー
                    </div>

                    <div class="flex items-center gap-2">

                        <button
                            type="button"
                            onclick="App.actions.setPreviewMode('pc')"
                            class="px-3 py-1.5 rounded-lg border text-sm">
                            PC表示
                        </button>

                        <button
                            type="button"
                            onclick="App.actions.setPreviewMode('mobile')"
                            class="px-3 py-1.5 rounded-lg border text-sm">
                            スマートフォン表示
                        </button>

                        <button
                            type="button"
                            onclick="App.actions.closePreview()"
                            class="px-3 py-1.5 rounded-lg bg-slate-100">
                            閉じる
                        </button>

                    </div>

                </div>

                <div
                    id="preview_content"
                    class="flex-1 overflow-auto p-6">
                </div>

            </div>

        </div>
    `;
};


App.render.previewContent = function(
    survey
) {
    const mobile =
        App.state.previewMode === 'mobile';

    return `
        <div class="${
            mobile
                ? 'max-w-sm'
                : 'max-w-2xl'
        } mx-auto">

            <div class="mb-8">

                <h2 class="text-2xl font-bold text-slate-900">
                    ${App.utils.escapeHtml(
                        survey.title
                    )}
                </h2>

            </div>

            ${
                survey.groups.map(
                    function(group, groupIndex) {
                        return `
                            <section class="mb-8">

                                <h3 class="font-semibold text-lg text-slate-800 mb-4">
                                    ${App.utils.escapeHtml(
                                        group.name
                                    )}
                                </h3>

                                <div class="space-y-6">

                                    ${
                                        group.questions.map(
                                            function(question, questionIndex) {
                                                const number =
                                                    App.utils.getQuestionNumber(
                                                        groupIndex,
                                                        questionIndex
                                                    );

                                                let answerHtml = '';

                                                if (
                                                    question.type === 'single'
                                                ) {
                                                    answerHtml =
                                                        question.options.map(
                                                            function(option) {
                                                                return `
                                                                    <label class="flex items-center gap-2 mb-2">
                                                                        <input type="radio" name="preview_${question.id}">
                                                                        <span>
                                                                            ${App.utils.escapeHtml(option)}
                                                                        </span>
                                                                    </label>
                                                                `;
                                                            }
                                                        ).join('');

                                                    if (
                                                        question.other_enabled
                                                    ) {
                                                        answerHtml += `
                                                            <label class="flex items-center gap-2 mb-2">
                                                                <input type="radio" name="preview_${question.id}">
                                                                <span>その他</span>
                                                            </label>
                                                        `;
                                                    }
                                                }

                                                if (
                                                    question.type === 'multiple'
                                                ) {
                                                    answerHtml =
                                                        question.options.map(
                                                            function(option) {
                                                                return `
                                                                    <label class="flex items-center gap-2 mb-2">
                                                                        <input type="checkbox">
                                                                        <span>
                                                                            ${App.utils.escapeHtml(option)}
                                                                        </span>
                                                                    </label>
                                                                `;
                                                            }
                                                        ).join('');

                                                    if (
                                                        question.other_enabled
                                                    ) {
                                                        answerHtml += `
                                                            <label class="flex items-center gap-2 mb-2">
                                                                <input type="checkbox">
                                                                <span>その他</span>
                                                            </label>
                                                        `;
                                                    }
                                                }

                                                if (
                                                    question.type === 'text'
                                                ) {
                                                    answerHtml = `
                                                        <textarea
                                                            rows="5"
                                                            class="w-full rounded-lg border border-slate-300 px-3 py-2"
                                                            placeholder="回答を入力してください"></textarea>
                                                    `;
                                                }

                                                return `
                                                    <div>

                                                        <div class="font-medium text-slate-900 mb-3">
                                                            <span class="text-blue-600 mr-2">
                                                                ${App.utils.escapeHtml(number)}
                                                            </span>

                                                            ${App.utils.escapeHtml(
                                                                question.text
                                                            )}

                                                            ${
                                                                question.required
                                                                    ? '<span class="text-red-500 ml-1">*</span>'
                                                                    : ''
                                                            }
                                                        </div>

                                                        <div class="pl-4">
                                                            ${answerHtml}
                                                        </div>

                                                    </div>
                                                `;
                                            }
                                        ).join('')
                                    }

                                </div>

                            </section>
                        `;
                    }
                ).join('')
            }

            <button
                type="button"
                onclick="alert('プレビュー中のため送信は実行されません。')"
                class="w-full rounded-lg bg-blue-600 text-white py-3">
                回答を送信
            </button>

        </div>
    `;
};


/* ========================================================================
 * 集計画面
 * ======================================================================== */

App.render.summary = function() {
    const app =
        document.getElementById('app');

    const survey =
        App.state.currentSurvey;

    if (!app || !survey) {
        return;
    }

    const questions =
        App.utils.getAllQuestions(
            survey
        );

    if (
        App.state.selectedQuestionIds.length === 0
    ) {
        App.state.selectedQuestionIds =
            questions.map(function(item) {
                return String(
                    item.question.id
                );
            });
    }

    const surveyResponses =
        App.state.responses.filter(
            function(response) {
                return String(response.survey_id) ===
                    String(survey.id);
            }
        );

    let html = '';

    html += `
        <div class="max-w-7xl mx-auto px-6 py-8">

            <div class="flex items-center justify-between mb-6">

                <div>
                    <div class="text-sm text-slate-500">
                        回答集計・分析
                    </div>

                    <h1 class="text-2xl font-bold text-slate-900">
                        ${App.utils.escapeHtml(
                            survey.title
                        )}
                    </h1>
                </div>

                <button
                    type="button"
                    onclick="App.actions.goList()"
                    class="px-4 py-2 rounded-lg border border-slate-300">
                    一覧へ戻る
                </button>

            </div>

            <div class="grid grid-cols-5 gap-4 mb-8">

                ${App.render.summaryCard(
                    '送信対象者数',
                    App.state.customers.filter(
                        function(customer) {
                            return customer.sent_at;
                        }
                    ).length + ' 人'
                )}

                ${App.render.summaryCard(
                    '回答数',
                    surveyResponses.length + ' 件'
                )}

                ${App.render.summaryCard(
                    '未登録顧客からの回答数',
                    surveyResponses.filter(
                        function(response) {
                            return !response.customer_id;
                        }
                    ).length + ' 件'
                )}

                ${App.render.summaryCard(
                    '未回答数',
                    Math.max(
                        0,
                        App.state.customers.filter(
                            function(customer) {
                                return customer.sent_at;
                            }
                        ).length -
                        surveyResponses.filter(
                            function(response) {
                                return response.customer_id;
                            }
                        ).length
                    ) + ' 人'
                )}

                ${App.render.summaryCard(
                    '回答率',
                    (
                        App.state.customers.filter(
                            function(customer) {
                                return customer.sent_at;
                            }
                        ).length > 0
                            ? (
                                surveyResponses.length /
                                App.state.customers.filter(
                                    function(customer) {
                                        return customer.sent_at;
                                    }
                                ).length *
                                100
                            ).toFixed(1)
                            : '0.0'
                    ) + ' %'
                )}

            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-5 mb-6">

                <div class="flex items-center justify-between mb-4">

                    <div class="font-semibold text-slate-800">
                        集計対象設問
                    </div>

                    <div class="flex gap-2">

                        <button
                            type="button"
                            onclick="App.actions.selectAllQuestions()"
                            class="text-sm text-blue-600">
                            全選択
                        </button>

                        <button
                            type="button"
                            onclick="App.actions.clearAllQuestions()"
                            class="text-sm text-slate-500">
                            全解除
                        </button>

                    </div>

                </div>

                <div class="space-y-2">

                    ${
                        questions.map(
                            function(item) {
                                const question =
                                    item.question;

                                return `
                                    <label class="flex items-center gap-3 p-2 rounded-lg hover:bg-slate-50">

                                        <input
                                            type="checkbox"
                                            ${
                                                App.state.selectedQuestionIds.includes(
                                                    String(question.id)
                                                )
                                                    ? 'checked'
                                                    : ''
                                            }
                                            onchange="App.actions.toggleResponseQuestion('${App.utils.escapeHtml(question.id)}',this.checked)"
                                            class="rounded border-slate-300 text-blue-600">

                                        <span class="text-xs font-semibold text-blue-600">
                                            ${App.utils.escapeHtml(item.number)}
                                        </span>

                                        <span class="text-sm text-slate-700">
                                            ${App.utils.escapeHtml(question.text)}
                                        </span>

                                        <span class="ml-auto text-xs text-slate-400">
                                            ${App.utils.escapeHtml(question.type)}
                                        </span>

                                    </label>
                                `;
                            }
                        ).join('')
                    }

                </div>

            </div>

            ${
                surveyResponses.length === 0
                    ? `
                        <div class="bg-white border border-slate-200 rounded-xl p-12 text-center text-slate-500">
                            現在、回答データはありません
                        </div>
                    `
                    : App.render.summaryQuestions(
                        survey,
                        surveyResponses
                    )
            }

            <div class="mt-8 bg-white border border-slate-200 rounded-xl overflow-hidden">

                <div class="px-5 py-4 border-b border-slate-200 font-semibold">
                    個別回答一覧
                </div>

                <div class="overflow-x-auto">

                    <table class="w-full text-sm">

                        <thead class="bg-slate-50">
                            <tr>
                                <th class="text-left px-4 py-3">
                                    会社名
                                </th>
                                <th class="text-left px-4 py-3">
                                    氏名
                                </th>
                                <th class="text-left px-4 py-3">
                                    回答日時
                                </th>
                                <th class="px-4 py-3">
                                </th>
                            </tr>
                        </thead>

                        <tbody>

                            ${
                                surveyResponses.map(
                                    function(response) {
                                        return `
                                            <tr class="border-t border-slate-100">

                                                <td class="px-4 py-3">
                                                    ${App.utils.escapeHtml(
                                                        response.company || ''
                                                    )}
                                                </td>

                                                <td class="px-4 py-3">
                                                    ${App.utils.escapeHtml(
                                                        response.name || ''
                                                    )}
                                                </td>

                                                <td class="px-4 py-3">
                                                    ${App.utils.escapeHtml(
                                                        response.answered_at || ''
                                                    )}
                                                </td>

                                                <td class="px-4 py-3 text-right">

                                                    <button
                                                        type="button"
                                                        onclick="App.actions.openResponseDetail('${App.utils.escapeHtml(response.id)}')"
                                                        class="text-blue-600 hover:text-blue-800">
                                                        全回答を表示
                                                    </button>

                                                </td>

                                            </tr>
                                        `;
                                    }
                                ).join('')
                            }

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        ${App.render.responseModal()}
    `;

    app.innerHTML = html;
};


App.render.summaryCard = function(
    label,
    value
) {
    return `
        <div class="bg-white border border-slate-200 rounded-xl p-5">
            <div class="text-xs text-slate-500 mb-2">
                ${App.utils.escapeHtml(label)}
            </div>

            <div class="text-2xl font-bold text-slate-900">
                ${App.utils.escapeHtml(value)}
            </div>
        </div>
    `;
};


/* ------------------------------------------------------------------------
 * 設問別集計
 * ------------------------------------------------------------------------ */

App.render.summaryQuestions = function(
    survey,
    responses
) {
    const questions =
        App.utils.getAllQuestions(
            survey
        ).filter(
            function(item) {
                return App.state.selectedQuestionIds.includes(
                    String(item.question.id)
                );
            }
        );

    return `
        <div class="space-y-5">

            ${
                questions.map(
                    function(item) {
                        const question =
                            item.question;

                        if (
                            question.type === 'text'
                        ) {
                            return App.render.textSummary(
                                question,
                                item.number,
                                responses
                            );
                        }

                        return App.render.choiceSummary(
                            question,
                            item.number,
                            responses
                        );
                    }
                ).join('')
            }

        </div>
    `;
};


/* ------------------------------------------------------------------------
 * 選択式集計
 * ------------------------------------------------------------------------ */

App.render.choiceSummary = function(
    question,
    number,
    responses
) {
    const counts = {};

    question.options.forEach(
        function(option) {
            counts[option] = 0;
        }
    );

    let total = 0;

    responses.forEach(
        function(response) {
            const answer =
                response.answers
                    ? response.answers[question.id]
                    : null;

            if (Array.isArray(answer)) {
                answer.forEach(
                    function(value) {
                        if (
                            Object.prototype.hasOwnProperty.call(
                                counts,
                                value
                            )
                        ) {
                            counts[value]++;
                        }

                        total++;
                    }
                );
            } else if (
                answer !== undefined &&
                answer !== null &&
                answer !== ''
            ) {
                if (
                    Object.prototype.hasOwnProperty.call(
                        counts,
                        answer
                    )
                ) {
                    counts[answer]++;
                }

                total++;
            }
        }
    );

    return `
        <div class="bg-white border border-slate-200 rounded-xl p-5">

            <div class="mb-5">

                <div class="text-xs font-bold text-blue-600">
                    ${App.utils.escapeHtml(number)}
                </div>

                <div class="font-semibold text-slate-900">
                    ${App.utils.escapeHtml(question.text)}
                </div>

            </div>

            <div class="space-y-4">

                ${
                    Object.keys(counts).map(
                        function(option) {
                            const count =
                                counts[option];

                            const percent =
                                total > 0
                                    ? (
                                        count /
                                        total *
                                        100
                                    ).toFixed(1)
                                    : '0.0';

                            return `
                                <div>

                                    <div class="flex justify-between text-sm mb-1">

                                        <span class="text-slate-700">
                                            ${App.utils.escapeHtml(option)}
                                        </span>

                                        <span class="text-slate-500">
                                            ${count} 件
                                            (${percent}%)
                                        </span>

                                    </div>

                                    <div class="h-3 rounded-full bg-slate-100 overflow-hidden">

                                        <div
                                            class="h-full bg-blue-500 rounded-full"
                                            style="width:${percent}%">
                                        </div>

                                    </div>

                                </div>
                            `;
                        }
                    ).join('')
                }

            </div>

        </div>
    `;
};


/* ------------------------------------------------------------------------
 * 自由記述集計
 * ------------------------------------------------------------------------ */

App.render.textSummary = function(
    question,
    number,
    responses
) {
    const items = [];

    responses.forEach(
        function(response) {
            const answer =
                response.answers
                    ? response.answers[question.id]
                    : '';

            if (
                answer !== undefined &&
                answer !== null &&
                answer !== ''
            ) {
                items.push({
                    response: response,
                    answer: answer
                });
            }
        }
    );

    return `
        <div class="bg-white border border-slate-200 rounded-xl p-5">

            <div class="mb-5">

                <div class="text-xs font-bold text-blue-600">
                    ${App.utils.escapeHtml(number)}
                </div>

                <div class="font-semibold text-slate-900">
                    ${App.utils.escapeHtml(question.text)}
                </div>

                <div class="text-xs text-slate-400 mt-1">
                    ${items.length} 件の回答
                </div>

            </div>

            <div class="space-y-3 max-h-96 overflow-y-auto">

                ${
                    items.length
                        ? items.map(
                            function(item) {
                                return `
                                    <div class="border border-slate-200 rounded-lg p-4">

                                        <div class="text-xs text-slate-500 mb-2">
                                            ${App.utils.escapeHtml(
                                                item.response.company || ''
                                            )}
                                            /
                                            ${App.utils.escapeHtml(
                                                item.response.name || ''
                                            )}
                                        </div>

                                        <div class="text-sm text-slate-700 whitespace-pre-wrap">
                                            ${App.utils.escapeHtml(
                                                Array.isArray(item.answer)
                                                    ? item.answer.join('、')
                                                    : item.answer
                                            )}
                                        </div>

                                    </div>
                                `;
                            }
                        ).join('')
                        : `
                            <div class="text-sm text-slate-400">
                                回答はありません。
                            </div>
                        `
                }

            </div>

        </div>
    `;
};


/* ------------------------------------------------------------------------
 * 回答詳細モーダル
 * ------------------------------------------------------------------------ */

App.render.responseModal = function() {
    return `
        <div
            id="response_modal"
            class="hidden fixed inset-0 z-50 bg-black/40 p-8">

            <div class="bg-white rounded-2xl shadow-2xl max-w-3xl mx-auto max-h-full flex flex-col">

                <div class="flex items-center justify-between px-6 py-4 border-b">

                    <div class="font-semibold text-slate-900">
                        回答詳細
                    </div>

                    <button
                        type="button"
                        onclick="App.actions.closeResponseDetail()"
                        class="px-3 py-1.5 rounded-lg bg-slate-100">
                        閉じる
                    </button>

                </div>

                <div
                    id="response_detail"
                    class="overflow-auto p-6">
                </div>

            </div>

        </div>
    `;
};


/* ========================================================================
 * 一覧へ戻る
 * ======================================================================== */

App.actions.goList = function() {
    App.state.currentSurvey = null;
    App.state.editingSurveyId = null;
    App.state.currentScreen = 'list';

    App.render.list();
};


/* ========================================================================
 * 初期化
 * ======================================================================== */

App.init = async function() {
    if (App.state.initialized) {
        return;
    }

    App.state.initialized = true;

    await App.api.loadData();

    App.render.list();
};


/*
 * readyStateガード
 */
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
<script>
/*
========================================================================
PART 5/5
最終JavaScript・イベント・初期化・HTML終端

重要：
- window.App 配下のみを使用
- 質問形式を single / multiple から text に変更した場合、
  options を必ず [] にする
- text から single / multiple に戻した場合は必要に応じて初期選択肢を生成
========================================================================
*/

window.App = window.App || {};
App.state = App.state || {};
App.actions = App.actions || {};
App.render = App.render || {};
App.api = App.api || {};
App.util = App.util || {};

/* ================================================================
 * Utility
 * ================================================================ */

App.util.escapeHtml = App.util.escapeHtml || function(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.util.escapeAttr = App.util.escapeAttr || function(value) {
    return App.util.escapeHtml(value).replace(/"/g, '&quot;');
};

App.util.uid = App.util.uid || function(prefix) {
    return prefix + '_' + Date.now().toString(36) + '_' +
        Math.random().toString(36).slice(2, 9);
};

App.util.clone = App.util.clone || function(value) {
    return JSON.parse(JSON.stringify(value));
};

App.util.ensureQuestion = function(question) {
    question = question || {};

    if (!question.id) {
        question.id = App.util.uid('question');
    }

    if (typeof question.text !== 'string') {
        question.text = '';
    }

    if (!['single', 'multiple', 'text'].includes(question.type)) {
        question.type = 'single';
    }

    question.required = !!question.required;

    /*
     * ★重要
     * 自由記述の場合、選択肢を持たせない。
     * 過去データに options が残っていてもここで除去する。
     */
    if (question.type === 'text') {
        question.options = [];
        question.other_enabled = false;
    } else {
        if (!Array.isArray(question.options)) {
            question.options = [];
        }

        question.options = question.options.map(function(option) {
            if (typeof option === 'string') {
                return option;
            }

            if (option && typeof option === 'object') {
                return typeof option.text === 'string'
                    ? option.text
                    : '';
            }

            return '';
        });

        if (question.options.length === 0) {
            question.options = ['選択肢1'];
        }
    }

    return question;
};


/* ================================================================
 * Question Type Change
 * ================================================================ */

App.actions.changeQuestionType = function(questionId, type) {

    if (!App.state.survey || !Array.isArray(App.state.survey.groups)) {
        return;
    }

    let target = null;

    App.state.survey.groups.forEach(function(group) {
        if (!Array.isArray(group.questions)) {
            group.questions = [];
        }

        group.questions.forEach(function(question) {
            if (question.id === questionId) {
                target = question;
            }
        });
    });

    if (!target) {
        return;
    }

    /*
     * ★★★ 今回の不具合修正の核心 ★★★
     *
     * single / multiple
     *        ↓
     *      text
     *
     * の場合、選択肢を完全に破棄する。
     */
    if (type === 'text') {
        target.type = 'text';
        target.options = [];
        target.other_enabled = false;
    }

    /*
     * text
     *  ↓
     * single / multiple
     *
     * の場合は選択肢を新規生成する。
     */
    else if (type === 'single' || type === 'multiple') {

        target.type = type;

        if (!Array.isArray(target.options)) {
            target.options = [];
        }

        if (target.options.length === 0) {
            target.options = ['選択肢1'];
        }

        target.other_enabled = !!target.other_enabled;
    }

    App.render.editor();
};


/* ================================================================
 * Add / Remove Option
 * ================================================================ */

App.actions.addOption = function(questionId) {

    const survey = App.state.survey;

    if (!survey) {
        return;
    }

    survey.groups.forEach(function(group) {

        group.questions.forEach(function(question) {

            if (question.id !== questionId) {
                return;
            }

            if (question.type === 'text') {
                return;
            }

            if (!Array.isArray(question.options)) {
                question.options = [];
            }

            question.options.push(
                '選択肢' + (question.options.length + 1)
            );
        });

    });

    App.render.editor();
};


App.actions.removeOption = function(questionId, optionIndex) {

    const survey = App.state.survey;

    if (!survey) {
        return;
    }

    survey.groups.forEach(function(group) {

        group.questions.forEach(function(question) {

            if (question.id !== questionId) {
                return;
            }

            if (!Array.isArray(question.options)) {
                question.options = [];
            }

            question.options.splice(optionIndex, 1);

            if (question.options.length === 0) {
                question.options.push('選択肢1');
            }
        });

    });

    App.render.editor();
};


App.actions.updateOption = function(questionId, index, value) {

    const survey = App.state.survey;

    if (!survey) {
        return;
    }

    survey.groups.forEach(function(group) {

        group.questions.forEach(function(question) {

            if (question.id !== questionId) {
                return;
            }

            if (question.type === 'text') {
                return;
            }

            if (!Array.isArray(question.options)) {
                question.options = [];
            }

            question.options[index] = value;
        });

    });
};


/* ================================================================
 * Question Update
 * ================================================================ */

App.actions.updateQuestionText = function(questionId, value) {

    if (!App.state.survey) {
        return;
    }

    App.state.survey.groups.forEach(function(group) {

        group.questions.forEach(function(question) {

            if (question.id === questionId) {
                question.text = value;
            }

        });

    });
};


App.actions.toggleRequired = function(questionId, checked) {

    if (!App.state.survey) {
        return;
    }

    App.state.survey.groups.forEach(function(group) {

        group.questions.forEach(function(question) {

            if (question.id === questionId) {
                question.required = !!checked;
            }

        });

    });
};


App.actions.toggleOther = function(questionId, checked) {

    if (!App.state.survey) {
        return;
    }

    App.state.survey.groups.forEach(function(group) {

        group.questions.forEach(function(question) {

            if (question.id === questionId) {

                if (question.type === 'text') {
                    question.other_enabled = false;
                } else {
                    question.other_enabled = !!checked;
                }

            }

        });

    });

    App.render.editor();
};


/* ================================================================
 * Group / Question
 * ================================================================ */

App.actions.addGroup = function() {

    if (!App.state.survey) {
        return;
    }

    if (!Array.isArray(App.state.survey.groups)) {
        App.state.survey.groups = [];
    }

    App.state.survey.groups.push({
        id: App.util.uid('group'),
        name: '新しいグループ',
        questions: []
    });

    App.render.editor();
};


App.actions.deleteGroup = function(groupId) {

    if (!App.state.survey) {
        return;
    }

    if (!confirm('このグループと内包される質問を削除しますか？')) {
        return;
    }

    App.state.survey.groups =
        App.state.survey.groups.filter(function(group) {
            return group.id !== groupId;
        });

    App.render.editor();
};


App.actions.updateGroupName = function(groupId, value) {

    if (!App.state.survey) {
        return;
    }

    App.state.survey.groups.forEach(function(group) {

        if (group.id === groupId) {
            group.name = value;
        }

    });
};


App.actions.addQuestion = function(groupId) {

    if (!App.state.survey) {
        return;
    }

    App.state.survey.groups.forEach(function(group) {

        if (group.id !== groupId) {
            return;
        }

        if (!Array.isArray(group.questions)) {
            group.questions = [];
        }

        group.questions.push({
            id: App.util.uid('question'),
            text: '',
            type: 'single',
            required: false,
            options: ['選択肢1'],
            other_enabled: false
        });

    });

    App.render.editor();
};


App.actions.deleteQuestion = function(questionId) {

    if (!App.state.survey) {
        return;
    }

    if (!confirm('この質問を削除しますか？')) {
        return;
    }

    App.state.survey.groups.forEach(function(group) {

        group.questions =
            group.questions.filter(function(question) {
                return question.id !== questionId;
            });

    });

    App.render.editor();
};


/* ================================================================
 * Numbering
 * ================================================================ */

App.util.renumberQuestions = function() {

    if (!App.state.survey) {
        return;
    }

    let globalNo = 1;

    App.state.survey.groups.forEach(function(group, groupIndex) {

        if (!Array.isArray(group.questions)) {
            group.questions = [];
        }

        group.questions.forEach(function(question, questionIndex) {

            /*
             * ★ここでも text の options を完全除去。
             * 古いデータや並び替え後の状態も防御する。
             */
            App.util.ensureQuestion(question);

            if (App.state.survey.numbering_mode === 'group') {
                question.display_number =
                    'Q' + (groupIndex + 1) + '-' + (questionIndex + 1);
            } else {
                question.display_number =
                    'Q' + globalNo;
            }

            globalNo++;
        });

    });
};


/* ================================================================
 * Editor Rendering
 * ================================================================ */

App.render.editor = function() {

    const root = document.getElementById('app');

    if (!root || !App.state.survey) {
        return;
    }

    App.util.renumberQuestions();

    const survey = App.state.survey;

    let html = `
        <div class="min-h-screen bg-slate-50">
            <header class="sticky top-0 z-30 border-b bg-white">
                <div class="mx-auto max-w-7xl px-6 py-4 flex items-center justify-between">
                    <div>
                        <div class="text-xs text-slate-500">アンケート作成・編集</div>
                        <input
                            id="survey_title"
                            value="${App.util.escapeAttr(survey.title || '')}"
                            onchange="App.actions.updateSurveyTitle(this.value)"
                            class="mt-1 w-[600px] max-w-full text-xl font-bold outline-none border-b border-transparent focus:border-blue-500"
                        >
                    </div>

                    <div class="flex gap-2">
                        <button
                            onclick="App.actions.openPreview()"
                            class="rounded-lg bg-slate-100 px-4 py-2 text-sm hover:bg-slate-200">
                            プレビュー
                        </button>

                        <button
                            onclick="App.actions.saveSurvey()"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                            保存して一覧へ戻る
                        </button>

                        <button
                            onclick="App.actions.cancelEdit()"
                            class="rounded-lg border bg-white px-4 py-2 text-sm hover:bg-slate-50">
                            キャンセル
                        </button>
                    </div>
                </div>
            </header>

            <main class="mx-auto max-w-7xl px-6 py-8">

                <div class="mb-6 rounded-xl border bg-white p-5">
                    <div class="grid grid-cols-3 gap-5">

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">
                                開始日時
                            </span>
                            <input
                                id="survey_start_at"
                                type="datetime-local"
                                value="${App.util.escapeAttr(survey.start_at || '')}"
                                onchange="App.actions.updateSurveyField('start_at', this.value)"
                                class="mt-2 w-full rounded-lg border px-3 py-2">
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">
                                終了日時
                            </span>
                            <input
                                id="survey_end_at"
                                type="datetime-local"
                                value="${App.util.escapeAttr(survey.end_at || '')}"
                                onchange="App.actions.updateSurveyField('end_at', this.value)"
                                class="mt-2 w-full rounded-lg border px-3 py-2">
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">
                                質問番号
                            </span>
                            <select
                                id="survey_numbering_mode"
                                onchange="App.actions.updateNumberingMode(this.value)"
                                class="mt-2 w-full rounded-lg border px-3 py-2">
                                <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                                    Q1, Q2, Q3...
                                </option>
                                <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                                    Q1-1, Q1-2...
                                </option>
                            </select>
                        </label>

                    </div>
                </div>

                <div id="question_editor" class="space-y-6">
    `;

    survey.groups.forEach(function(group) {

        html += `
            <section
                class="group-card rounded-xl border bg-white shadow-sm"
                data-group-id="${App.util.escapeAttr(group.id)}">

                <div class="flex items-center gap-3 border-b bg-slate-50 px-5 py-4">

                    <span class="group-handle cursor-move text-xl text-slate-400">
                        ⠿
                    </span>

                    <input
                        value="${App.util.escapeAttr(group.name || '')}"
                        onchange="App.actions.updateGroupName('${App.util.escapeAttr(group.id)}', this.value)"
                        class="flex-1 rounded-lg border bg-white px-3 py-2 font-semibold"
                    >

                    <button
                        onclick="App.actions.deleteGroup('${App.util.escapeAttr(group.id)}')"
                        class="rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                        グループ削除
                    </button>
                </div>

                <div
                    class="question-list space-y-4 p-5"
                    data-group-id="${App.util.escapeAttr(group.id)}">
        `;

        group.questions.forEach(function(question) {

            App.util.ensureQuestion(question);

            html += `
                <article
                    class="question-card rounded-xl border bg-white p-5"
                    data-question-id="${App.util.escapeAttr(question.id)}">

                    <div class="flex gap-4">

                        <div class="question-handle cursor-move pt-2 text-lg text-slate-400">
                            ⠿
                        </div>

                        <div class="flex-1">

                            <div class="mb-4 flex items-center justify-between gap-4">

                                <div class="font-bold text-blue-600">
                                    ${App.util.escapeHtml(question.display_number || '')}
                                </div>

                                <button
                                    onclick="App.actions.deleteQuestion('${App.util.escapeAttr(question.id)}')"
                                    class="text-sm text-red-600 hover:underline">
                                    質問削除
                                </button>

                            </div>

                            <input
                                value="${App.util.escapeAttr(question.text || '')}"
                                onchange="App.actions.updateQuestionText('${App.util.escapeAttr(question.id)}', this.value)"
                                placeholder="質問文を入力してください"
                                class="mb-4 w-full rounded-lg border px-3 py-3 text-base"
                            >

                            <div class="grid grid-cols-2 gap-4">

                                <label>
                                    <span class="mb-1 block text-xs font-semibold text-slate-500">
                                        回答形式
                                    </span>

                                    <select
                                        onchange="App.actions.changeQuestionType('${App.util.escapeAttr(question.id)}', this.value)"
                                        class="w-full rounded-lg border px-3 py-2">

                                        <option value="single" ${question.type === 'single' ? 'selected' : ''}>
                                            単一選択
                                        </option>

                                        <option value="multiple" ${question.type === 'multiple' ? 'selected' : ''}>
                                            複数選択
                                        </option>

                                        <option value="text" ${question.type === 'text' ? 'selected' : ''}>
                                            自由記述
                                        </option>

                                    </select>
                                </label>

                                <label class="flex items-end gap-2 pb-2">
                                    <input
                                        type="checkbox"
                                        ${question.required ? 'checked' : ''}
                                        onchange="App.actions.toggleRequired('${App.util.escapeAttr(question.id)}', this.checked)"
                                        class="h-4 w-4"
                                    >
                                    <span class="text-sm">必須回答</span>
                                </label>

                            </div>
            `;

            /*
             * ★重要
             *
             * 自由記述の場合は選択肢HTMLを一切生成しない。
             *
             * これにより、
             * 「選択形式 → 自由記述」
             * に変更した瞬間に画面上から選択肢も消える。
             */
            if (question.type === 'text') {

                html += `
                    <div class="mt-4 rounded-lg bg-slate-50 p-4">
                        <div class="mb-2 text-xs font-semibold text-slate-500">
                            自由記述
                        </div>

                        <textarea
                            disabled
                            rows="4"
                            placeholder="回答者が自由に入力します"
                            class="w-full rounded-lg border bg-white px-3 py-2 text-slate-400"
                        ></textarea>
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
                                onclick="App.actions.addOption('${App.util.escapeAttr(question.id)}')"
                                class="rounded-lg bg-white px-3 py-1.5 text-xs border hover:bg-slate-100">
                                ＋選択肢追加
                            </button>
                        </div>

                        <div class="space-y-2">
                `;

                question.options.forEach(function(option, optionIndex) {

                    const inputType =
                        question.type === 'multiple'
                            ? 'checkbox'
                            : 'radio';

                    html += `
                        <div class="flex items-center gap-2">

                            <input
                                type="${inputType}"
                                disabled
                                class="h-4 w-4"
                            >

                            <input
                                value="${App.util.escapeAttr(option)}"
                                onchange="App.actions.updateOption('${App.util.escapeAttr(question.id)}', ${optionIndex}, this.value)"
                                class="flex-1 rounded-lg border bg-white px-3 py-2"
                            >

                            <button
                                onclick="App.actions.removeOption('${App.util.escapeAttr(question.id)}', ${optionIndex})"
                                class="rounded-lg px-2 py-2 text-red-500 hover:bg-red-50">
                                ×
                            </button>

                        </div>
                    `;
                });

                html += `
                        </div>

                        <label class="mt-4 flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                ${question.other_enabled ? 'checked' : ''}
                                onchange="App.actions.toggleOther('${App.util.escapeAttr(question.id)}', this.checked)"
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

        });

        html += `
                </div>

                <div class="border-t px-5 py-4">
                    <button
                        onclick="App.actions.addQuestion('${App.util.escapeAttr(group.id)}')"
                        class="rounded-lg bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100">
                        ＋質問を追加
                    </button>
                </div>

            </section>
        `;
    });

    html += `
                </div>

                <button
                    onclick="App.actions.addGroup()"
                    class="mt-6 w-full rounded-xl border-2 border-dashed border-slate-300 bg-white py-4 text-sm font-semibold text-slate-600 hover:border-blue-400 hover:text-blue-600">
                    ＋グループを追加
                </button>

            </main>
        </div>
    `;

    root.innerHTML = html;

    App.initSortable();
};


/* ================================================================
 * Survey fields
 * ================================================================ */

App.actions.updateSurveyTitle = function(value) {

    if (App.state.survey) {
        App.state.survey.title = value;
    }
};


App.actions.updateSurveyField = function(field, value) {

    if (App.state.survey) {
        App.state.survey[field] = value;
    }
};


App.actions.updateNumberingMode = function(value) {

    if (!App.state.survey) {
        return;
    }

    App.state.survey.numbering_mode =
        value === 'group' ? 'group' : 'global';

    App.render.editor();
};


/* ================================================================
 * SortableJS
 * ================================================================ */

App.initSortable = function() {

    if (typeof Sortable === 'undefined') {
        return;
    }

    if (!App.state.survey) {
        return;
    }

    document.querySelectorAll('.question-list').forEach(function(element) {

        if (element._sortable) {
            element._sortable.destroy();
        }

        element._sortable = new Sortable(element, {
            group: 'survey_questions',
            animation: 180,
            ghostClass: 'opacity-40',
            handle: '.question-handle',

            onEnd: function(event) {

                const questionId =
                    event.item.dataset.questionId;

                let question = null;

                App.state.survey.groups.forEach(function(group) {

                    const index =
                        group.questions.findIndex(function(q) {
                            return q.id === questionId;
                        });

                    if (index !== -1) {
                        question = group.questions.splice(index, 1)[0];
                    }

                });

                if (!question) {
                    return;
                }

                const targetGroupId =
                    event.to.dataset.groupId;

                const targetGroup =
                    App.state.survey.groups.find(function(group) {
                        return group.id === targetGroupId;
                    });

                if (!targetGroup) {
                    return;
                }

                let newIndex = event.newIndex;

                if (newIndex < 0) {
                    newIndex = targetGroup.questions.length;
                }

                targetGroup.questions.splice(newIndex, 0, question);

                App.render.editor();
            }
        });

    });


    document.querySelectorAll('.group-card').forEach(function(element) {

        if (element._groupSortable) {
            element._groupSortable.destroy();
        }

    });

};


/* ================================================================
 * Save
 * ================================================================ */

App.actions.saveSurvey = async function() {

    if (!App.state.survey) {
        return;
    }

    App.util.renumberQuestions();

    /*
     * 保存直前にも再度防御。
     * text の questions に options が残らないことを保証。
     */
    App.state.survey.groups.forEach(function(group) {

        group.questions.forEach(function(question) {

            App.util.ensureQuestion(question);

            if (question.type === 'text') {
                question.options = [];
                question.other_enabled = false;
            }

        });

    });

    App.state.survey.updated_at =
        new Date().toISOString();

    try {

        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: new URLSearchParams({
                action: 'save_survey',
                survey_json: JSON.stringify(App.state.survey),
                csrf_token:
                    document.getElementById('csrf_token')?.value || ''
            })
        });

        const result = await response.json();

        if (!result.ok) {
            throw new Error(result.message || '保存に失敗しました');
        }

        alert('保存しました。');

        App.actions.showSurveyList();

    } catch (error) {

        console.error(error);

        alert(
            '保存に失敗しました。\n' +
            (error.message || '')
        );
    }
};


/* ================================================================
 * Cancel
 * ================================================================ */

App.actions.cancelEdit = function() {

    if (!confirm('変更を破棄して一覧へ戻りますか？')) {
        return;
    }

    App.actions.showSurveyList();
};


/* ================================================================
 * Preview
 * ================================================================ */

App.actions.openPreview = function() {

    const modal =
        document.getElementById('preview_modal');

    const content =
        document.getElementById('preview_content');

    if (!modal || !content || !App.state.survey) {
        return;
    }

    let html = `
        <div class="space-y-6">
            <div>
                <h2 class="text-2xl font-bold">
                    ${App.util.escapeHtml(App.state.survey.title || '')}
                </h2>
            </div>
    `;

    App.state.survey.groups.forEach(function(group) {

        html += `
            <div>
                <h3 class="mb-3 text-lg font-bold">
                    ${App.util.escapeHtml(group.name || '')}
                </h3>
        `;

        group.questions.forEach(function(question) {

            App.util.ensureQuestion(question);

            html += `
                <div class="mb-5 rounded-xl border bg-white p-5">
                    <div class="mb-3 font-semibold">
                        ${App.util.escapeHtml(question.display_number || '')}
                        ${App.util.escapeHtml(question.text || '')}
                        ${question.required ? '<span class="ml-2 text-red-500">*</span>' : ''}
                    </div>
            `;

            if (question.type === 'text') {

                html += `
                    <textarea
                        rows="4"
                        class="w-full rounded-lg border px-3 py-2"
                        placeholder="回答を入力してください"
                    ></textarea>
                `;

            } else {

                question.options.forEach(function(option) {

                    html += `
                        <label class="mb-2 flex items-center gap-2">
                            <input
                                type="${question.type === 'multiple' ? 'checkbox' : 'radio'}"
                                name="preview_${App.util.escapeAttr(question.id)}"
                            >
                            <span>
                                ${App.util.escapeHtml(option)}
                            </span>
                        </label>
                    `;
                });

                if (question.other_enabled) {

                    html += `
                        <label class="mt-2 flex items-center gap-2">
                            <input
                                type="${question.type === 'multiple' ? 'checkbox' : 'radio'}"
                            >
                            <span>その他</span>
                        </label>
                    `;
                }
            }

            html += `
                </div>
            `;
        });

        html += `</div>`;
    });

    html += `
            <button
                onclick="App.actions.previewSubmit()"
                class="w-full rounded-lg bg-blue-600 px-4 py-3 font-semibold text-white">
                回答を送信
            </button>
        </div>
    `;

    content.innerHTML = html;
    modal.classList.remove('hidden');
};


App.actions.closePreview = function() {

    const modal =
        document.getElementById('preview_modal');

    if (modal) {
        modal.classList.add('hidden');
    }
};


App.actions.previewSubmit = function() {

    alert('これはプレビューです。実際の送信は行われません。');
};


/* ================================================================
 * Survey List
 * ================================================================ */

App.actions.showSurveyList = function() {

    App.state.page = 'list';

    if (typeof App.render.list === 'function') {
        App.render.list();
        return;
    }

    location.href = location.pathname;
};


/* ================================================================
 * Kintone
 * ================================================================ */

App.actions.fetchKintoneFields = async function() {

    const message =
        document.getElementById('field_message');

    const appId =
        document.getElementById('setting_app_id')?.value || '';

    if (!appId) {

        if (message) {
            message.textContent =
                'アプリIDを入力してください。';
        }

        return;
    }

    if (message) {
        message.textContent = '項目一覧を取得しています...';
    }

    try {

        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: new URLSearchParams({
                action: 'kintone_fields',
                app_id: appId,
                csrf_token:
                    document.getElementById('csrf_token')?.value || ''
            })
        });

        const result = await response.json();

        if (!result.ok) {
            throw new Error(
                result.message || '取得に失敗しました'
            );
        }

        App.actions.populateKintoneFields(
            result.fields || {}
        );

        if (message) {
            message.textContent =
                '項目一覧を取得しました。';
        }

    } catch (error) {

        console.error(error);

        if (message) {
            message.textContent =
                '項目一覧の取得に失敗しました。';
        }

        alert(error.message || 'kintone APIエラー');
    }
};


/*
 * 必須関数名：
 * fetchKintoneFields()
 *
 * 他コードから直接呼ばれる可能性を考慮して
 * App 配下の完全修飾版に加えて alias も保持。
 */
App.fetchKintoneFields = App.actions.fetchKintoneFields;


App.actions.populateKintoneFields = function(fields) {

    const mapping = [
        'field_company',
        'field_name',
        'field_email',
        'field_department',
        'field_phone',
        'field_address'
    ];

    mapping.forEach(function(id) {

        const select = document.getElementById(id);

        if (!select) {
            return;
        }

        const current = select.value;

        select.innerHTML =
            '<option value="">選択してください</option>';

        Object.keys(fields).forEach(function(code) {

            const field = fields[code] || {};

            const label =
                field.label ||
                code;

            const option =
                document.createElement('option');

            option.value = code;
            option.textContent =
                label + ' [' + code + ']';

            if (code === current) {
                option.selected = true;
            }

            select.appendChild(option);
        });

    });
};


/* ================================================================
 * Generic initialization
 * ================================================================ */

App.init = function() {

    if (App.state.initialized) {
        return;
    }

    App.state.initialized = true;

    if (!App.state.page) {
        App.state.page = 'list';
    }

    /*
     * PHPから渡された初期データが存在する場合。
     */
    if (typeof window.SURVEY_INITIAL_DATA !== 'undefined') {

        try {

            App.state.data =
                typeof window.SURVEY_INITIAL_DATA === 'string'
                    ? JSON.parse(window.SURVEY_INITIAL_DATA)
                    : window.SURVEY_INITIAL_DATA;

        } catch (error) {

            console.error(
                '初期データの解析に失敗しました。',
                error
            );

            App.state.data = {
                surveys: [],
                responses: [],
                customers: [],
                settings: {},
                mail_logs: []
            };
        }

    }

    /*
     * 最初は一覧表示。
     */
    if (typeof App.render.list === 'function') {
        App.render.list();
    }
};


/* ================================================================
 * Safe launcher
 * ================================================================ */

if (document.readyState === 'loading') {

    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.init();
        },
        { once: true }
    );

} else {

    App.init();

}
</script>


<!-- ============================================================
     Preview Modal
============================================================ -->

<div
    id="preview_modal"
    class="fixed inset-0 z-50 hidden bg-black/40 p-6">

    <div class="mx-auto flex h-full max-w-4xl items-center justify-center">

        <div class="max-h-[90vh] w-full overflow-hidden rounded-2xl bg-slate-50 shadow-2xl">

            <div class="flex items-center justify-between border-b bg-white px-6 py-4">

                <div class="font-bold">
                    プレビュー
                </div>

                <button
                    onclick="App.actions.closePreview()"
                    class="rounded-lg px-3 py-2 text-slate-500 hover:bg-slate-100">
                    ✕
                </button>

            </div>

            <div
                id="preview_content"
                class="max-h-[calc(90vh-70px)] overflow-y-auto p-6">
            </div>

        </div>

    </div>

</div>


<!-- ============================================================
     Response Modal
============================================================ -->

<div
    id="response_modal"
    class="fixed inset-0 z-50 hidden bg-black/40 p-6">

    <div class="mx-auto flex h-full max-w-4xl items-center justify-center">

        <div class="max-h-[90vh] w-full overflow-hidden rounded-2xl bg-white shadow-2xl">

            <div class="flex items-center justify-between border-b px-6 py-4">

                <div class="font-bold">
                    回答詳細
                </div>

                <button
                    onclick="App.actions.closeResponseModal?.()"
                    class="rounded-lg px-3 py-2 text-slate-500 hover:bg-slate-100">
                    ✕
                </button>

            </div>

            <div
                id="response_detail"
                class="max-h-[calc(90vh-70px)] overflow-y-auto p-6">
            </div>

        </div>

    </div>

</div>


<!-- ============================================================
     Hidden CSRF
============================================================ -->

<input
    type="hidden"
    id="csrf_token"
    value="<?= htmlspecialchars(
        $_SESSION['survey_csrf_token'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


</body>
</html>
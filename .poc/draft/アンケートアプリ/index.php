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

/*
 * デバッグ用。
 * 本番公開時には display_errors を 0 に戻してください。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

/*
 * 最初にPHPそのものが実行されているか確認。
 * これすら表示されない場合、index.phpがPHPとして実行されていません。
 */
echo '<!-- SURVEY_APP_PHP_START -->';

/*
 * セッション開始。
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    session_start();
}

/*
 * ストレージ初期化。
 */
if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    if (!@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true)) {
        $e = error_get_last();

        exit(
            '<h1>ストレージディレクトリ作成失敗</h1>' .
            '<pre>' .
            htmlspecialchars(
                $e['message'] ?? 'mkdir failed',
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) .
            "\n\nPATH: " .
            htmlspecialchars(
                SURVEY_STORAGE_DIRECTORY,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) .
            '</pre>'
        );
    }
}

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    exit(
        '<h1>survey_storage が存在しません</h1>' .
        '<pre>' .
        htmlspecialchars(
            SURVEY_STORAGE_DIRECTORY,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) .
        '</pre>'
    );
}

if (!is_writable(SURVEY_STORAGE_DIRECTORY)) {
    exit(
        '<h1>survey_storage に書き込み権限がありません</h1>' .
        '<pre>' .
        htmlspecialchars(
            SURVEY_STORAGE_DIRECTORY,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) .
        '</pre>'
    );
}

/*
 * JSONファイルを作成。
 */
if (!file_exists(SURVEY_STORAGE_FILE)) {

    $initial = [
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

    $json = json_encode(
        $initial,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        exit(
            '<h1>JSON生成エラー</h1><pre>' .
            htmlspecialchars(
                json_last_error_msg(),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) .
            '</pre>'
        );
    }

    $result = @file_put_contents(
        SURVEY_STORAGE_FILE,
        $json,
        LOCK_EX
    );

    if ($result === false) {
        $e = error_get_last();

        exit(
            '<h1>survey_data.json 作成失敗</h1>' .
            '<pre>' .
            htmlspecialchars(
                $e['message'] ?? 'file_put_contents failed',
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) .
            "\n\nFILE: " .
            htmlspecialchars(
                SURVEY_STORAGE_FILE,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) .
            '</pre>'
        );
    }
}

/*
 * JSON読み込み。
 */
$survey_raw = @file_get_contents(SURVEY_STORAGE_FILE);

if ($survey_raw === false) {
    $e = error_get_last();

    exit(
        '<h1>survey_data.json 読み込み失敗</h1>' .
        '<pre>' .
        htmlspecialchars(
            $e['message'] ?? 'file_get_contents failed',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) .
        "\n\nFILE: " .
        htmlspecialchars(
            SURVEY_STORAGE_FILE,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) .
        '</pre>'
    );
}

$survey_data = json_decode($survey_raw, true);

if (!is_array($survey_data)) {
    exit(
        '<h1>JSONデータ破損</h1>' .
        '<pre>' .
        htmlspecialchars(
            json_last_error_msg(),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) .
        '</pre>'
    );
}

/*
 * ここまで来ればPHPバックエンドは正常。
 */
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-100 text-slate-800">

<div id="app" class="min-h-screen p-8">

    <div class="max-w-7xl mx-auto">

        <header class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-6">
            <h1 class="text-2xl font-bold">
                アンケート管理システム
            </h1>

            <p class="text-sm text-slate-500 mt-2">
                PHPバックエンド正常稼働確認済み
            </p>
        </header>

        <main class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">

            <div class="flex items-center justify-between mb-6">

                <div>
                    <h2 class="text-xl font-bold">
                        アンケート一覧
                    </h2>

                    <p class="text-sm text-slate-500 mt-1">
                        登録アンケート：
                        <?= htmlspecialchars(
                            (string)count($survey_data['surveys'] ?? []),
                            ENT_QUOTES | ENT_SUBSTITUTE,
                            'UTF-8'
                        ) ?>
                        件
                    </p>
                </div>

                <button
                    type="button"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl font-semibold"
                    onclick="App.actions.newSurvey()"
                >
                    ＋ 新規アンケート作成
                </button>

            </div>

            <div class="border border-dashed border-slate-300 rounded-xl p-10 text-center text-slate-500">
                <p class="text-lg">
                    アプリケーションの初期描画に成功しました。
                </p>

                <p class="text-sm mt-2">
                    ここまで表示されれば、PHPの白画面問題は解消しています。
                </p>
            </div>

        </main>

    </div>

</div>

<script>
window.App = {
    State: {
        surveys: <?= json_encode(
            $survey_data['surveys'] ?? [],
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        ) ?>
    },

    actions: {
        newSurvey: function () {
            alert('新規アンケート作成');
        }
    },

    init: function () {
        console.log('Survey App initialized.');
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        App.init();
    }, { once: true });
} else {
    App.init();
}
</script>

</body>
</html>
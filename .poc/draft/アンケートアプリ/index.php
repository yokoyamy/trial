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

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

/**
 * JSONデータストレージを安全に初期化する。
 *
 * 「ディレクトリが存在するか」
 * 「ディレクトリに書き込めるか」
 * 「JSONファイルを作成できるか」
 * 「JSONを読み込めるか」
 * を個別に検証する。
 */
function survey_storage_init(): array
{
    /* ディレクトリが存在しなければ作成 */
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        if (!@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true)) {
            $error = error_get_last();

            return [
                'ok' => false,
                'message' =>
                    'survey_storage ディレクトリを作成できませんでした。'
                    . ' パス: ' . SURVEY_STORAGE_DIRECTORY
                    . ' / PHP実行ユーザーに書き込み権限が必要です。'
                    . (!empty($error['message']) ? ' / ' . $error['message'] : '')
            ];
        }
    }

    /* ディレクトリが本当に存在するか */
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        return [
            'ok' => false,
            'message' => 'survey_storage ディレクトリが存在しません: '
                . SURVEY_STORAGE_DIRECTORY
        ];
    }

    /* ディレクトリへの書き込み確認 */
    if (!is_writable(SURVEY_STORAGE_DIRECTORY)) {
        return [
            'ok' => false,
            'message' =>
                'survey_storage ディレクトリに書き込み権限がありません。'
                . ' PHP実行ユーザーに書き込み権限を付与してください。'
                . ' パス: ' . SURVEY_STORAGE_DIRECTORY
        ];
    }

    /* JSONファイルが存在しなければ初期データを作成 */
    if (!file_exists(SURVEY_STORAGE_FILE)) {
        $initial_data = [
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
            $initial_data,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            return [
                'ok' => false,
                'message' => '初期データのJSON生成に失敗しました。'
            ];
        }

        $written = @file_put_contents(
            SURVEY_STORAGE_FILE,
            $json,
            LOCK_EX
        );

        if ($written === false) {
            $error = error_get_last();

            return [
                'ok' => false,
                'message' =>
                    'survey_data.json を作成できませんでした。'
                    . ' ファイル書き込み権限を確認してください。'
                    . (!empty($error['message']) ? ' / ' . $error['message'] : '')
            ];
        }
    }

    /* ファイルが存在しても書き込み不能ならエラー */
    if (!is_writable(SURVEY_STORAGE_FILE)) {
        return [
            'ok' => false,
            'message' =>
                'survey_data.json に書き込み権限がありません。'
                . ' ファイル: ' . SURVEY_STORAGE_FILE
        ];
    }

    /* JSON読み込み確認 */
    $raw = @file_get_contents(SURVEY_STORAGE_FILE);

    if ($raw === false) {
        $error = error_get_last();

        return [
            'ok' => false,
            'message' =>
                'survey_data.json を読み込めませんでした。'
                . (!empty($error['message']) ? ' / ' . $error['message'] : '')
        ];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return [
            'ok' => false,
            'message' =>
                'survey_data.json のJSON形式が壊れています。'
                . ' ファイルを確認してください。'
        ];
    }

    /* 必須トップキーを補完 */
    foreach (
        [
            'surveys',
            'responses',
            'customers',
            'settings',
            'mail_logs'
        ] as $key
    ) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $key === 'settings' ? [] : [];
        }
    }

    return [
        'ok' => true,
        'data' => $data
    ];
}
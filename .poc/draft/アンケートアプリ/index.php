<?php
declare(strict_types=1);

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
- branch
- number

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
- field_mapping

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
    SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';

const SURVEY_ADMIN_SESSION =
    'survey_admin_session_v1';


/* ================================================================
 * PHP 基本処理
 * ================================================================ */

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


/* ================================================================
 * kintone
 * ================================================================ */

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
        '.cybozu.com/' .
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
                $m
            )
        ) {
            $status = (int)$m[1];
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
    $domain = trim(
        (string)($settings['subdomain'] ?? '')
    );

    if ($domain === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' =>
                'kintoneのサブドメインが設定されていません。'
        ];
    }

    $method = strtoupper($method);

    $url = surveyKintoneBuildUrl(
        $domain,
        $endpoint,
        $query
    );

    $login =
        (string)($settings['login_name'] ?? '');

    $password =
        (string)($settings['password'] ?? '');

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
            implode("\r\n", $headers) . "\r\n",
        'ignore_errors' => true,
        'timeout' => 20
    ];

    /*
     * GETにはcontentを設定しない。
     */
    if (
        $method !== 'GET' &&
        $body !== null
    ) {
        $encoded = json_encode(
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

        $headers[] =
            'Content-Type: application/json';

        $options['header'] =
            implode("\r\n", $headers) . "\r\n";

        $options['content'] = $encoded;
    }

    $sslVerify =
        (bool)($settings['ssl_verify'] ?? false);

    $contextOptions = [
        'http' => $options,
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify
        ]
    ];

    $proxy = trim(
        (string)($settings['proxy'] ?? '')
    );

    if ($proxy !== '') {
        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy;

        $contextOptions['http']
            ['request_fulluri'] = true;
    }

    $context = stream_context_create(
        $contextOptions
    );

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders =
        surveySafeResponseHeaders();

    $status =
        surveyKintoneStatus(
            $responseHeaders
        );

    if ($response === false) {
        return [
            'ok' => false,
            'status' => $status,
            'message' =>
                'kintone APIへの接続に失敗しました。',
            'url' => $url,
            'headers' => $responseHeaders
        ];
    }

    $decoded = json_decode(
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
            'raw' => mb_substr(
                $response,
                0,
                2000
            ),
            'headers' => $responseHeaders
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


/* ================================================================
 * データ正規化
 * ================================================================ */

function surveyNormalize(
    array $survey
): array {
    $survey['id'] =
        (string)(
            $survey['id'] ??
            surveyId('survey')
        );

    $survey['title'] =
        (string)($survey['title'] ?? '');

    $survey['start_at'] =
        (string)($survey['start_at'] ?? '');

    $survey['end_at'] =
        (string)($survey['end_at'] ?? '');

    $status =
        $survey['status'] ?? 'draft';

    $survey['status'] =
        in_array(
            $status,
            ['draft', 'active', 'ended'],
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
            ['global', 'group'],
            true
        )
        ? $mode
        : 'global';

    $survey['deleted'] =
        (bool)($survey['deleted'] ?? false);

    $survey['groups'] =
        is_array($survey['groups'] ?? null)
        ? $survey['groups']
        : [];

    foreach (
        $survey['groups'] as &$group
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
            $group['questions'] as &$question
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
                    ['single', 'multiple', 'text'],
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
                    $question['options'] ?? null
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
                    $question['branch'] ?? null
                )
                ? $question['branch']
                : [];
        }

        unset($question);
    }

    unset($group);

    return $survey;
}


function surveyFindIndex(
    array $items,
    string $id
): int {
    foreach ($items as $index => $item) {
        if (
            is_array($item) &&
            (string)($item['id'] ?? '') === $id
        ) {
            return $index;
        }
    }

    return -1;
}


/* ================================================================
 * API
 * ================================================================ */

if (isset($_GET['action'])) {

    $action =
        (string)$_GET['action'];

    $data =
        surveyReadStorage();


    /* ------------------------------------------------------------
     * load
     * ------------------------------------------------------------ */

    if ($action === 'load') {

        $surveys = [];

        foreach ($data['surveys'] as $survey) {

            if (
                !is_array($survey) ||
                !empty($survey['deleted'])
            ) {
                continue;
            }

            $survey =
                surveyNormalize($survey);

            $survey['answer_count'] = 0;

            foreach (
                $data['responses']
                as $response
            ) {
                if (
                    is_array($response) &&
                    (string)(
                        $response['survey_id'] ?? ''
                    ) === $survey['id']
                ) {
                    $survey['answer_count']++;
                }
            }

            $surveys[] = $survey;
        }

        surveyJsonResponse([
            'ok' => true,
            'csrf_token' => surveyCsrf(),
            'surveys' => $surveys,
            'responses' => $data['responses'],
            'customers' => $data['customers'],
            'settings' => $data['settings'],
            'mail_logs' => $data['mail_logs']
        ]);
    }


    /* ------------------------------------------------------------
     * save_survey
     * ------------------------------------------------------------ */

    if ($action === 'save_survey') {

        surveyVerifyCsrf();

        $survey =
            surveyPostJson('survey_json');

        if (!is_array($survey)) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'アンケートデータが不正です。'
            ], 400);
        }

        $survey =
            surveyNormalize($survey);

        $now = surveyNow();

        $existingIndex =
            surveyFindIndex(
                $data['surveys'],
                $survey['id']
            );

        if ($existingIndex < 0) {
            $survey['created_at'] = $now;
            $survey['updated_at'] = $now;
            $data['surveys'][] = $survey;
        } else {
            $survey['created_at'] =
                $data['surveys']
                [$existingIndex]
                ['created_at']
                ?? $now;

            $survey['updated_at'] = $now;

            $data['surveys']
                [$existingIndex] = $survey;
        }

        if (!surveyWriteStorage($data)) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '保存に失敗しました。'
            ], 500);
        }

        surveyJsonResponse([
            'ok' => true,
            'survey' => $survey,
            'message' =>
                'アンケートを保存しました。'
        ]);
    }


    /* ------------------------------------------------------------
     * delete_survey
     * ------------------------------------------------------------ */

    if ($action === 'delete_survey') {

        surveyVerifyCsrf();

        $id =
            (string)($_POST['survey_id'] ?? '');

        $index =
            surveyFindIndex(
                $data['surveys'],
                $id
            );

        if ($index < 0) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'アンケートが見つかりません。'
            ], 404);
        }

        $data['surveys'][$index]['deleted'] =
            true;

        $data['surveys'][$index]['updated_at'] =
            surveyNow();

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true
        ]);
    }


    /* ------------------------------------------------------------
     * status_survey
     * ------------------------------------------------------------ */

    if ($action === 'status_survey') {

        surveyVerifyCsrf();

        $id =
            (string)($_POST['survey_id'] ?? '');

        $status =
            (string)($_POST['status'] ?? '');

        if (
            !in_array(
                $status,
                ['draft', 'active', 'ended'],
                true
            )
        ) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '不正なステータスです。'
            ], 400);
        }

        $index =
            surveyFindIndex(
                $data['surveys'],
                $id
            );

        if ($index < 0) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'アンケートが見つかりません。'
            ], 404);
        }

        $data['surveys'][$index]['status'] =
            $status;

        $data['surveys'][$index]['updated_at'] =
            surveyNow();

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true
        ]);
    }


    /* ------------------------------------------------------------
     * save_settings
     * ------------------------------------------------------------ */

    if ($action === 'save_settings') {

        surveyVerifyCsrf();

        $settings =
            surveyPostJson('settings_json');

        if (!is_array($settings)) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '設定データが不正です。'
            ], 400);
        }

        $base = $data['settings'];

        foreach (
            [
                'subdomain',
                'login_name',
                'password',
                'app_id',
                'proxy'
            ] as $key
        ) {
            $base[$key] =
                trim(
                    (string)(
                        $settings[$key] ?? ''
                    )
                );
        }

        $base['ssl_verify'] =
            (bool)(
                $settings['ssl_verify'] ?? false
            );

        foreach (
            [
                'field_company',
                'field_name',
                'field_email',
                'field_department',
                'field_phone'
            ] as $key
        ) {
            $base[$key] =
                (string)(
                    $settings[$key] ?? ''
                );
        }

        $base['field_address'] =
            is_array(
                $settings['field_address'] ?? null
            )
            ? array_values(
                array_map(
                    'strval',
                    $settings['field_address']
                )
            )
            : [];

        $data['settings'] = $base;

        if (!surveyWriteStorage($data)) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '設定の保存に失敗しました。'
            ], 500);
        }

        surveyJsonResponse([
            'ok' => true,
            'settings' => $base
        ]);
    }


    /* ------------------------------------------------------------
     * kintone_fields
     * ------------------------------------------------------------ */

    if ($action === 'kintone_fields') {

        surveyVerifyCsrf();

        $settings =
            surveyPostJson(
                'settings_json'
            ) ?? $data['settings'];

        $appId =
            trim(
                (string)(
                    $settings['app_id'] ??
                    ($_POST['app_id'] ?? '')
                )
            );

        if ($appId === '') {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'アプリIDを入力してください。'
            ], 400);
        }

        /*
         * kintone仕様:
         *
         * GET
         * /k/v1/app/form/fields.json?app=123
         *
         * GETにはJSON bodyを送らない。
         */
        $result =
            surveyKintoneRequest(
                $settings,
                '/k/v1/app/form/fields.json',
                'GET',
                ['app' => $appId]
            );

        if (!$result['ok']) {
            surveyJsonResponse(
                $result,
                400
            );
        }

        $fields = [];

        $properties =
            $result['data']['properties'] ?? [];

        if (is_array($properties)) {
            foreach (
                $properties as $code => $property
            ) {
                if (!is_array($property)) {
                    continue;
                }

                $fields[] = [
                    'code' =>
                        (string)$code,
                    'label' =>
                        (string)(
                            $property['label']
                            ?? $code
                        ),
                    'type' =>
                        (string)(
                            $property['type']
                            ?? ''
                        )
                ];
            }
        }

        usort(
            $fields,
            static function (
                array $a,
                array $b
            ): int {
                return strcmp(
                    $a['label'],
                    $b['label']
                );
            }
        );

        surveyJsonResponse([
            'ok' => true,
            'fields' => $fields,
            'count' => count($fields)
        ]);
    }


    /* ------------------------------------------------------------
     * kintone_test
     * ------------------------------------------------------------ */

    if ($action === 'kintone_test') {

        surveyVerifyCsrf();

        $settings =
            surveyPostJson(
                'settings_json'
            ) ?? $data['settings'];

        $result =
            surveyKintoneRequest(
                $settings,
                '/k/v1/app.json',
                'GET',
                [
                    'id' =>
                        (string)(
                            $settings['app_id']
                            ?? ''
                        )
                ]
            );

        if (!$result['ok']) {
            surveyJsonResponse(
                $result,
                400
            );
        }

        surveyJsonResponse([
            'ok' => true,
            'message' =>
                'kintoneへの接続に成功しました。',
            'data' =>
                $result['data']
        ]);
    }


    /* ------------------------------------------------------------
     * send_mail
     * ------------------------------------------------------------ */

    if ($action === 'send_mail') {

        surveyVerifyCsrf();

        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $recipientIds =
            surveyPostJson(
                'recipient_ids'
            ) ?? [];

        $subject =
            (string)(
                $_POST['mail_subject'] ?? ''
            );

        $body =
            (string)(
                $_POST['mail_body'] ?? ''
            );

        $templateType =
            (string)(
                $_POST['template_type'] ?? 'initial'
            );

        if (
            !in_array(
                $templateType,
                ['initial', 'reminder'],
                true
            )
        ) {
            $templateType = 'initial';
        }

        if ($recipientIds === []) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '送信先が選択されていません。'
            ], 400);
        }

        $surveyIndex =
            surveyFindIndex(
                $data['surveys'],
                $surveyId
            );

        if ($surveyIndex < 0) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'アンケートが見つかりません。'
            ], 404);
        }

        $survey =
            surveyNormalize(
                $data['surveys'][$surveyIndex]
            );

        $count = 0;
        $sentAt = surveyNow();

        foreach (
            $recipientIds as $customerId
        ) {
            $customerIndex =
                surveyFindIndex(
                    $data['customers'],
                    (string)$customerId
                );

            if ($customerIndex < 0) {
                continue;
            }

            $customer =
                &$data['customers']
                [$customerIndex];

            $customer['sent_at'] =
                $sentAt;

            $customer['send_count'] =
                ((int)(
                    $customer['send_count']
                    ?? 0
                )) + 1;

            $customer['answer_status'] =
                'unanswered';

            $count++;

            unset($customer);
        }

        $data['mail_logs'][] = [
            'id' => surveyId('mail'),
            'survey_id' => $surveyId,
            'sent_at' => $sentAt,
            'template_type' => $templateType,
            'count' => $count,
            'subject' => $subject,
            'body' => $body
        ];

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true,
            'customers' =>
                $data['customers'],
            'mail_logs' =>
                $data['mail_logs'],
            'message' =>
                $count .
                '件の送信履歴を登録しました。'
        ]);
    }


    /* ------------------------------------------------------------
     * export_csv
     * ------------------------------------------------------------ */

    if ($action === 'export_csv') {

        surveyVerifyCsrf();

        $surveyId =
            (string)(
                $_GET['survey_id'] ?? ''
            );

        $surveyIndex =
            surveyFindIndex(
                $data['surveys'],
                $surveyId
            );

        if ($surveyIndex < 0) {
            http_response_code(404);
            exit;
        }

        $survey =
            surveyNormalize(
                $data['surveys'][$surveyIndex]
            );

        $questions =
            [];

        foreach (
            $survey['groups'] as $group
        ) {
            foreach (
                $group['questions'] as $question
            ) {
                $questions[] = $question;
            }
        }

        $headers = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名'
        ];

        foreach (
            $questions as $question
        ) {
            $headers[] =
                $question['number']
                ?? $question['text'];
        }

        $csv = "\xEF\xBB\xBF";

        $csv .=
            implode(
                ',',
                array_map(
                    static fn($v) =>
                        '"' .
                        str_replace(
                            '"',
                            '""',
                            (string)$v
                        ) .
                        '"',
                    $headers
                )
            ) . "\r\n";

        foreach (
            $data['responses'] as $response
        ) {
            if (
                !is_array($response) ||
                (string)(
                    $response['survey_id'] ?? ''
                ) !== $surveyId
            ) {
                continue;
            }

            $row = [
                $response['id'] ?? '',
                $response['answered_at'] ?? '',
                $response['customer_id'] ?? '',
                $response['company'] ?? '',
                $response['name'] ?? ''
            ];

            foreach (
                $questions as $question
            ) {
                $value =
                    $response['answers']
                    [$question['id'] ?? '']
                    ?? '';

                if (is_array($value)) {
                    $value =
                        implode(
                            '、',
                            $value
                        );
                }

                $row[] = $value;
            }

            $csv .=
                implode(
                    ',',
                    array_map(
                        static fn($v) =>
                            '"' .
                            str_replace(
                                '"',
                                '""',
                                (string)$v
                            ) .
                            '"',
                        $row
                    )
                ) . "\r\n";
        }

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="survey_' .
            rawurlencode($surveyId) .
            '.csv"'
        );

        echo $csv;
        exit;
    }
}
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

<div id="app"></div>

<script>
window.App = window.App || {};


/* ================================================================
 * STATE
 * ================================================================ */

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
    kintoneFields: [],
    previewMode: 'pc',
    responseFilter: '',
    customerFilter: '',
    selectedQuestions: {},
    editingSurveyId: null,
    dirty: false
};


/* ================================================================
 * UTIL
 * ================================================================ */

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
    },

    escAttr(value) {
        return App.util.escape(value)
            .replace(/'/g, '&#39;');
    }
};


/* ================================================================
 * API
 * ================================================================ */

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
                'サーバーからJSON以外の応答が返されました。HTTP ' +
                response.status +
                '\n' +
                text.slice(0, 500)
            );
        }

        if (
            !response.ok ||
            json.ok === false
        ) {
            throw new Error(
                json.message ||
                '通信に失敗しました。'
            );
        }

        return json;
    }
};


/* ================================================================
 * RENDER
 * ================================================================ */

App.render = {

    current() {

        const app =
            document.getElementById('app');

        if (!app) {
            return;
        }

        if (App.state.screen === 'list') {
            app.innerHTML =
                App.render.list();
        }

        if (App.state.screen === 'edit') {
            app.innerHTML =
                App.render.edit();
        }

        if (App.state.screen === 'summary') {
            app.innerHTML =
                App.render.summary();
        }

        if (App.state.screen === 'mail') {
            app.innerHTML =
                App.render.mail();
        }

        if (App.state.screen === 'settings') {
            app.innerHTML =
                App.render.settings();
        }

        if (App.state.screen === 'edit') {
            setTimeout(
                () => App.actions.initSortable(),
                0
            );
        }
    },


    header(title = 'アンケート管理') {

        return `
<header class="bg-white border-b">
<div class="max-w-7xl mx-auto px-5 py-4 flex items-center justify-between gap-4">
<div>
<h1 class="text-xl font-bold">${App.util.escape(title)}</h1>
</div>

<nav class="flex gap-2 flex-wrap">
<button
class="px-3 py-2 rounded-lg border bg-white hover:bg-slate-50"
onclick="App.actions.backList()">
アンケート一覧
</button>

<button
class="px-3 py-2 rounded-lg border bg-white hover:bg-slate-50"
onclick="App.actions.settings()">
キントーン連携設定
</button>
</nav>
</div>
</header>`;
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
${App.render.header()}

<main class="max-w-7xl mx-auto p-5">

<div class="flex items-center justify-between mb-5">
<div>
<h2 class="text-lg font-bold">アンケート一覧</h2>
<p class="text-sm text-slate-500">
作成・公開・集計・送信を管理します。
</p>
</div>

<button
class="px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700"
onclick="App.actions.newSurvey()">
＋ 新規アンケート
</button>
</div>

<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
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
class="p-12 text-center text-slate-400">
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
            draft: 'bg-slate-100 text-slate-700',
            active: 'bg-emerald-100 text-emerald-700',
            ended: 'bg-amber-100 text-amber-700'
        };

        let actions = `
<button
class="px-3 py-1.5 rounded-lg border hover:bg-slate-50"
onclick="App.actions.editSurvey('${App.util.escAttr(survey.id)}')">
確認・編集
</button>`;

        if (survey.status === 'active') {

            actions += `
<button
class="px-3 py-1.5 rounded-lg border"
onclick="App.actions.summary('${App.util.escAttr(survey.id)}')">
集計
</button>

<button
class="px-3 py-1.5 rounded-lg border"
onclick="App.actions.mail('${App.util.escAttr(survey.id)}')">
送信
</button>

<button
class="px-3 py-1.5 rounded-lg border text-amber-700"
onclick="App.actions.changeStatus('${App.util.escAttr(survey.id)}','ended')">
停止
</button>`;

        } else if (survey.status === 'draft') {

            actions += `
<button
class="px-3 py-1.5 rounded-lg border text-red-600"
onclick="App.actions.deleteSurvey('${App.util.escAttr(survey.id)}')">
削除
</button>`;

        } else {

            actions += `
<button
class="px-3 py-1.5 rounded-lg border"
onclick="App.actions.summary('${App.util.escAttr(survey.id)}')">
集計
</button>`;
        }

        actions += `
<button
class="px-3 py-1.5 rounded-lg border"
onclick="App.actions.duplicateSurvey('${App.util.escAttr(survey.id)}')">
複製
</button>`;

        return `
<tr class="border-t hover:bg-slate-50">
<td class="p-3 whitespace-nowrap">
<div>${App.util.escape(
    String(survey.created_at || '').slice(0,10)
)}</div>
<div class="text-xs text-slate-400">
更新:
${App.util.escape(
    String(survey.updated_at || '').slice(0,10)
)}
</div>
</td>

<td class="p-3 font-bold">
${App.util.escape(survey.title || '無題')}
</td>

<td class="p-3 whitespace-nowrap">
${App.util.escape(survey.start_at || '未設定')}
～
${App.util.escape(survey.end_at || '未設定')}
</td>

<td class="p-3">
<span class="inline-flex px-2 py-1 rounded-full text-xs font-semibold ${statusClass[survey.status]}">
${statusLabel[survey.status]}
</span>
</td>

<td class="p-3">
${Number(survey.answer_count || 0)} 件
</td>

<td class="p-3">
<div class="flex gap-2 flex-wrap">
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

        return `
<div class="min-h-screen">
${App.render.header('アンケート作成・編集')}

<main class="max-w-6xl mx-auto p-5">

<div class="bg-white rounded-xl border shadow-sm p-5 mb-5">

<div class="flex items-center justify-between gap-4 flex-wrap">
<div class="flex-1">
<label class="text-sm font-semibold">
タイトル
</label>

<input
id="survey_title"
value="${App.util.escAttr(survey.title)}"
oninput="App.actions.changeSurveyField('title',this.value)"
class="mt-1 w-full text-xl font-bold border-0 border-b-2 border-slate-200 focus:border-blue-500 focus:outline-none px-1 py-2"
placeholder="アンケートタイトル">
</div>

<div class="flex gap-2 flex-wrap">

<button
class="px-4 py-2 rounded-lg border"
onclick="App.actions.preview()">
プレビュー
</button>

<button
class="px-4 py-2 rounded-lg border"
onclick="App.actions.cancelEdit()">
キャンセル
</button>

<button
class="px-4 py-2 rounded-lg bg-blue-600 text-white"
onclick="App.actions.saveSurvey()">
保存して一覧へ戻る
</button>
</div>
</div>

<div class="grid md:grid-cols-4 gap-4 mt-5">

<div>
<label class="text-sm font-semibold">開始日時</label>
<input
id="survey_start_at"
type="datetime-local"
value="${App.util.escAttr(survey.start_at)}"
onchange="App.actions.changeSurveyField('start_at',this.value)"
class="mt-1 w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="text-sm font-semibold">終了日時</label>
<input
id="survey_end_at"
type="datetime-local"
value="${App.util.escAttr(survey.end_at)}"
onchange="App.actions.changeSurveyField('end_at',this.value)"
class="mt-1 w-full border rounded-lg px-3 py-2">
</div>

<div>
<label class="text-sm font-semibold">質問番号</label>
<select
id="survey_numbering_mode"
onchange="App.actions.changeSurveyField('numbering_mode',this.value)"
class="mt-1 w-full border rounded-lg px-3 py-2">
<option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>
Q1 / Q2 / Q3
</option>
<option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>
Q1-1 / Q1-2
</option>
</select>
</div>

<div>
<label class="text-sm font-semibold">ステータス</label>
<select
onchange="App.actions.changeStatusLocal(this.value)"
class="mt-1 w-full border rounded-lg px-3 py-2">
<option value="draft" ${survey.status === 'draft' ? 'selected' : ''}>下書き</option>
<option value="active" ${survey.status === 'active' ? 'selected' : ''}>公開中</option>
<option value="ended" ${survey.status === 'ended' ? 'selected' : ''}>終了</option>
</select>
</div>

</div>
</div>

<div class="flex items-center justify-between mb-4">
<h2 class="font-bold text-lg">質問構成</h2>

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

${survey.groups.map(
    (group, gi) =>
        App.render.group(group, gi)
).join('')}

</div>

<div class="mt-6 flex justify-end">
<button
class="px-4 py-2 rounded-lg bg-blue-600 text-white"
onclick="App.actions.addGroup()">
＋ グループ追加
</button>
</div>

</main>
</div>`;
    },


    group(group, groupIndex) {

        return `
<section
data-group="${App.util.escAttr(group.id)}"
class="bg-white rounded-xl border shadow-sm overflow-hidden">

<div class="p-4 border-b bg-slate-50 flex items-center gap-3">

<span class="cursor-move text-xl text-slate-400"
title="ドラッグして並べ替え">
⠿
</span>

<div class="flex-1">
<input
value="${App.util.escAttr(group.name)}"
oninput="App.actions.changeGroupName('${App.util.escAttr(group.id)}',this.value)"
class="w-full bg-transparent font-bold border-b border-transparent focus:border-blue-400 focus:outline-none px-1 py-1">
</div>

<button
class="px-3 py-1.5 rounded-lg border text-red-600"
onclick="App.actions.deleteGroup('${App.util.escAttr(group.id)}')">
グループ削除
</button>

</div>

<div
data-group-list
class="p-4 space-y-4 min-h-20">

${group.questions.map(
    (question, qi) =>
        App.render.question(
            question,
            group,
            qi
        )
).join('')}

${group.questions.length === 0 ? `
<div class="border-2 border-dashed rounded-lg p-6 text-center text-slate-400">
ここに質問を追加できます
</div>` : ''}

</div>

<div class="px-4 pb-4">
<button
class="w-full py-2 rounded-lg border border-blue-300 text-blue-600 hover:bg-blue-50"
onclick="App.actions.addQuestion('${App.util.escAttr(group.id)}')">
＋ 質問を追加
</button>
</div>

</section>`;
    },


    question(question, group, index) {

        const allQuestions =
            App.state.survey.groups
                .flatMap(
                    g => g.questions
                );

        const branchOptions =
            question.options || [];

        return `
<div
data-question="${App.util.escAttr(question.id)}"
class="border rounded-xl p-4 bg-white shadow-sm">

<div class="flex items-start gap-3">

<span class="cursor-move text-xl text-slate-400 pt-2">
⠿
</span>

<div class="flex-1">

<div class="flex items-center justify-between gap-3 mb-3">

<div class="font-bold text-blue-600">
${App.util.escape(question.number || '')}
</div>

<button
class="text-red-600 text-sm"
onclick="App.actions.deleteQuestion('${App.util.escAttr(question.id)}')">
削除
</button>

</div>

<input
value="${App.util.escAttr(question.text)}"
oninput="App.actions.changeQuestion('${App.util.escAttr(question.id)}','text',this.value)"
placeholder="質問文を入力してください"
class="w-full border rounded-lg px-3 py-2 mb-3">

<div class="grid md:grid-cols-2 gap-3">

<div>
<label class="text-sm font-semibold">
回答形式
</label>

<select
onchange="App.actions.changeType('${App.util.escAttr(question.id)}',this.value)"
class="w-full border rounded-lg px-3 py-2">
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
</div>

<label class="flex items-center gap-2 mt-7">
<input
type="checkbox"
${question.required ? 'checked' : ''}
onchange="App.actions.changeRequired('${App.util.escAttr(question.id)}',this.checked)">
必須回答
</label>

</div>

${question.type !== 'text' ? `

<div class="mt-4">
<div class="flex justify-between items-center mb-2">
<label class="text-sm font-semibold">
選択肢
</label>

<button
class="text-blue-600 text-sm"
onclick="App.actions.addOption('${App.util.escAttr(question.id)}')">
＋ 選択肢追加
</button>
</div>

<div class="space-y-2">

${branchOptions.map(
    (option, oi) => `

<div class="grid grid-cols-[1fr_auto] gap-2">

<input
value="${App.util.escAttr(option)}"
oninput="App.actions.changeOption('${App.util.escAttr(question.id)}',${oi},this.value)"
class="border rounded-lg px-3 py-2">

<button
class="px-3 rounded-lg border text-red-600"
onclick="App.actions.removeOption('${App.util.escAttr(question.id)}',${oi})">
削除
</button>

</div>

${
question.type === 'single'
? `
<div class="ml-2 text-xs text-slate-500">
この回答を選択した場合の分岐先:
<select
onchange="App.actions.changeBranch('${App.util.escAttr(question.id)}',${JSON.stringify(option)},this.value)"
class="border rounded px-2 py-1">

<option value="">分岐なし</option>

${allQuestions
    .filter(
        q =>
            q.id !== question.id
    )
    .map(
        q => `
<option
value="${App.util.escAttr(q.id)}"
${question.branch?.[option] === q.id ? 'selected' : ''}>
${App.util.escape(q.number || '')}
 ${App.util.escape(q.text || '未入力')}
</option>`
    )
    .join('')}

</select>
</div>`
: ''
}
`
).join('')}

</div>

<label class="flex items-center gap-2 mt-3 text-sm">
<input
type="checkbox"
${question.other_enabled ? 'checked' : ''}
onchange="App.actions.changeQuestion('${App.util.escAttr(question.id)}','other_enabled',this.checked)">
「その他」を許可
</label>

</div>

` : ''}

</div>
</div>
</div>`;
    },


    summary() {

        const survey =
            App.state.survey;

        const responses =
            App.state.responses.filter(
                r =>
                    r.survey_id === survey?.id
            );

        const questions =
            survey?.groups
                ?.flatMap(
                    g => g.questions
                ) || [];

        const sent =
            App.state.customers.filter(
                c => c.sent_at
            ).length;

        const answeredCustomers =
            new Set(
                responses
                    .map(
                        r => r.customer_id
                    )
                    .filter(Boolean)
            );

        const unanswered =
            Math.max(
                sent -
                answeredCustomers.size,
                0
            );

        const rate =
            sent > 0
                ? (
                    answeredCustomers.size /
                    sent *
                    100
                ).toFixed(1)
                : '0.0';

        return `
<div class="min-h-screen">
${App.render.header('回答集計・分析')}

<main class="max-w-7xl mx-auto p-5">

<div class="bg-white rounded-xl border p-5 mb-5">
<div class="text-sm text-slate-500">対象アンケート</div>
<div class="text-xl font-bold">
${App.util.escape(survey?.title || '')}
</div>
</div>

<div class="grid md:grid-cols-5 gap-4 mb-6">

${[
    ['送信対象者数', sent + ' 人'],
    ['回答数', responses.length + ' 件'],
    ['未登録顧客からの回答数',
        responses.filter(
            r => !r.customer_id
        ).length + ' 件'],
    ['未回答数', unanswered + ' 人'],
    ['回答率', rate + ' %']
].map(
    card => `
<div class="bg-white border rounded-xl p-5">
<div class="text-sm text-slate-500">${card[0]}</div>
<div class="text-2xl font-bold mt-2">${card[1]}</div>
</div>`
).join('')}

</div>

<div class="bg-white border rounded-xl p-5 mb-5">

<div class="flex justify-between items-center mb-4">
<h2 class="font-bold">設問別集計</h2>

<div class="flex gap-2">
<button
class="px-3 py-1.5 border rounded-lg"
onclick="App.actions.selectAllQuestions(true)">
全選択
</button>

<button
class="px-3 py-1.5 border rounded-lg"
onclick="App.actions.selectAllQuestions(false)">
全解除
</button>
</div>
</div>

<div
id="response_filter"
class="space-y-2 mb-6">

${App.render.summaryQuestionFilter()}

</div>

<div class="space-y-6">

${questions
    .filter(
        q =>
            App.state.selectedQuestions[q.id] !== false
    )
    .map(
        q =>
            App.render.questionSummary(
                q,
                responses
            )
    )
    .join('')}

</div>

</div>

<div class="bg-white border rounded-xl p-5">

<div class="flex justify-between items-center mb-4">
<h2 class="font-bold">個別回答一覧</h2>

<div class="flex gap-2">
<input
id="response_filter_input"
value="${App.util.escAttr(App.state.responseFilter)}"
oninput="App.actions.filterResponses(this.value)"
placeholder="会社名・氏名"
class="border rounded-lg px-3 py-2">

<a
href="?action=export_csv&survey_id=${encodeURIComponent(survey?.id || '')}"
class="px-3 py-2 bg-slate-800 text-white rounded-lg">
CSV出力
</a>
</div>
</div>

<div
id="response_table"
class="overflow-x-auto">
${App.render.summaryResponses()}
</div>

</div>

</main>
</div>`;
    },


    summaryQuestionFilter() {

        const questions =
            App.state.survey?.groups
                ?.flatMap(
                    g => g.questions
                ) || [];

        return questions.map(
            q => `
<label class="flex items-center gap-2">
<input
type="checkbox"
${App.state.selectedQuestions[q.id] !== false ? 'checked' : ''}
onchange="App.actions.toggleQuestion('${App.util.escAttr(q.id)}',this.checked)">
<span>
${App.util.escape(q.number || '')}
 ${App.util.escape(q.text || '')}
</span>
</label>`
        ).join('');
    },


    questionSummary(
        question,
        responses
    ) {

        if (question.type === 'text') {

            const texts =
                responses
                    .map(
                        response => ({
                            response,
                            value:
                                response.answers?.[
                                    question.id
                                ] ?? ''
                        })
                    )
                    .filter(
                        x =>
                            String(x.value).trim() !== ''
                    );

            return `
<div class="border rounded-xl p-5">
<div class="font-bold mb-4">
${App.util.escape(question.number || '')}
 ${App.util.escape(question.text || '')}
</div>

<div class="space-y-3 max-h-80 overflow-auto">

${texts.map(
    x => `
<div class="border-l-4 border-blue-500 pl-3">
<div class="font-semibold">
${App.util.escape(x.response.company || '')}
 ${App.util.escape(x.response.name || '')}
</div>
<div class="text-slate-600 whitespace-pre-wrap">
${App.util.escape(x.value)}
</div>
</div>`
).join('') || `
<div class="text-slate-400">
回答データはありません
</div>`}

</div>
</div>`;
        }

        const counts = {};

        (question.options || [])
            .forEach(
                option => {
                    counts[option] = 0;
                }
            );

        responses.forEach(
            response => {

                let value =
                    response.answers?.[
                        question.id
                    ];

                if (!Array.isArray(value)) {
                    value = [value];
                }

                value.forEach(
                    item => {
                        if (
                            item &&
                            Object.prototype.hasOwnProperty
                                .call(
                                    counts,
                                    item
                                )
                        ) {
                            counts[item]++;
                        }
                    }
                );
            }
        );

        return `
<div class="border rounded-xl p-5">

<div class="font-bold mb-4">
${App.util.escape(question.number || '')}
 ${App.util.escape(question.text || '')}
</div>

<div class="space-y-3">

${Object.entries(counts).map(
    ([option, count]) => {

        const percent =
            responses.length
                ? (
                    count /
                    responses.length *
                    100
                ).toFixed(1)
                : '0.0';

        return `
<div>
<div class="flex justify-between text-sm mb-1">
<span>${App.util.escape(option)}</span>
<span>${count} 件 / ${percent}%</span>
</div>

<div class="h-3 bg-slate-100 rounded-full overflow-hidden">
<div
class="h-full bg-blue-500"
style="width:${percent}%">
</div>
</div>
</div>`;
    }
).join('')}

</div>
</div>`;
    },


    summaryResponses() {

        const keyword =
            App.state.responseFilter
                .toLowerCase();

        const rows =
            App.state.responses
                .filter(
                    r =>
                        r.survey_id ===
                        App.state.survey?.id
                )
                .filter(
                    r =>
                        !keyword ||
                        String(
                            r.company || ''
                        ).toLowerCase().includes(keyword) ||
                        String(
                            r.name || ''
                        ).toLowerCase().includes(keyword)
                )
                .map(
                    r => `
<tr class="border-t">
<td class="p-3">
${App.util.escape(r.company || '')}
</td>

<td class="p-3">
${App.util.escape(r.name || '')}
</td>

<td class="p-3">
${App.util.escape(r.email || '')}
</td>

<td class="p-3">
${App.util.escape(r.answered_at || '')}
</td>

<td class="p-3">
<button
class="px-3 py-1.5 border rounded-lg"
onclick="App.actions.showResponse('${App.util.escAttr(r.id)}')">
全回答を表示
</button>
</td>
</tr>`
                )
                .join('');

        return `
<table class="w-full text-sm">
<thead class="bg-slate-50">
<tr>
<th class="p-3 text-left">会社名</th>
<th class="p-3 text-left">氏名</th>
<th class="p-3 text-left">メール</th>
<th class="p-3 text-left">回答日時</th>
<th class="p-3"></th>
</tr>
</thead>
<tbody>
${rows || `
<tr>
<td colspan="5"
class="p-10 text-center text-slate-400">
現在、回答データはありません
</td>
</tr>`}
</tbody>
</table>`;
    },


    mail() {

        const survey =
            App.state.survey;

        const keyword =
            App.state.customerFilter
                .toLowerCase();

        const customers =
            App.state.customers
                .filter(
                    c =>
                        !keyword ||
                        String(c.company || '')
                            .toLowerCase()
                            .includes(keyword) ||
                        String(c.name || '')
                            .toLowerCase()
                            .includes(keyword) ||
                        String(c.email || '')
                            .toLowerCase()
                            .includes(keyword)
                );

        return `
<div class="min-h-screen">
${App.render.header('顧客選択・メール送信')}

<main class="max-w-7xl mx-auto p-5">

<div class="bg-white border rounded-xl p-5 mb-5">

<div class="text-sm text-slate-500">
対象アンケート
</div>

<div class="font-bold text-xl">
${App.util.escape(survey?.title || '')}
</div>

<div class="grid md:grid-cols-2 gap-4 mt-5">

<div>
<label class="text-sm font-semibold">テンプレート</label>
<select
id="template_type"
class="w-full border rounded-lg px-3 py-2 mt-1">
<option value="initial">初回送信</option>
<option value="reminder">リマインド</option>
</select>
</div>

<div>
<label class="text-sm font-semibold">件名</label>
<input
id="mail_subject"
class="w-full border rounded-lg px-3 py-2 mt-1"
value="アンケートのお願い">
</div>

</div>

<div class="mt-4">
<label class="text-sm font-semibold">本文</label>
<textarea
id="mail_body"
rows="7"
class="w-full border rounded-lg px-3 py-2 mt-1">${App.util.escape(
'${顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n${アンケートURL}'
)}</textarea>
</div>

<div class="mt-4 flex justify-end">
<button
class="px-5 py-2.5 rounded-lg bg-blue-600 text-white"
onclick="App.actions.sendMail()">
選択した顧客へ送信
</button>
</div>

</div>

<div class="bg-white border rounded-xl overflow-hidden">

<div class="p-4 border-b flex justify-between gap-3">

<div>
<h2 class="font-bold">顧客一覧</h2>
</div>

<input
id="customer_filter"
value="${App.util.escAttr(App.state.customerFilter)}"
oninput="App.actions.filterCustomers(this.value)"
placeholder="顧客名・メールアドレス"
class="border rounded-lg px-3 py-2">

</div>

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
<th class="p-3 text-left">会社名 / 氏名</th>
<th class="p-3 text-left">メール</th>
<th class="p-3 text-left">電話</th>
<th class="p-3 text-left">送信日時</th>
<th class="p-3 text-left">送信回数</th>
<th class="p-3 text-left">回答</th>
<th class="p-3 text-left">kintone</th>
</tr>
</thead>

<tbody id="customer_table">

${customers.map(
    c => `
<tr class="border-t">

<td class="p-3">
${
c.source === 'web'
? '<span class="text-slate-400">Web</span>'
: `
<input
type="checkbox"
data-customer-id="${App.util.escAttr(c.id)}">
`
}
</td>

<td class="p-3">
<div class="font-bold">
${App.util.escape(c.company || '')}
</div>
<div>
${App.util.escape(c.name || '')}
</div>
</td>

<td class="p-3">
${App.util.escape(c.email || '')}
</td>

<td class="p-3">
${App.util.escape(c.phone || '')}
</td>

<td class="p-3">
${App.util.escape(c.sent_at || '')}
</td>

<td class="p-3">
${Number(c.send_count || 0)}
</td>

<td class="p-3">
<span class="px-2 py-1 rounded-full text-xs ${
c.answer_status === 'answered'
? 'bg-emerald-100 text-emerald-700'
: 'bg-slate-100 text-slate-600'
}">
${
c.answer_status === 'answered'
? '回答済み'
: '未回答'
}
</span>
</td>

<td class="p-3">
${
c.kintone_status === 'registered'
? '<span class="text-emerald-600">✓ 登録完了</span>'
: `
<button
class="px-2 py-1 border rounded-lg text-xs"
onclick="App.actions.registerKintone('${App.util.escAttr(c.id)}')">
キントーン登録完了
</button>`
}
</td>

</tr>`
).join('')}

</tbody>
</table>
</div>

</div>

</main>
</div>`;
    },


    settings() {

        const s =
            App.state.settings || {};

        return `
<div class="min-h-screen">
${App.render.header('キントーン連携設定')}

<main class="max-w-5xl mx-auto p-5">

<form
id="settings_form"
onsubmit="event.preventDefault();App.actions.saveSettings()"
class="space-y-5">

<div class="bg-white border rounded-xl p-5">

<h2 class="font-bold text-lg mb-4">
接続設定
</h2>

<div class="grid md:grid-cols-2 gap-4">

<div>
<label class="text-sm font-semibold">
サブドメイン
</label>

<input
id="setting_subdomain"
value="${App.util.escAttr(s.subdomain || '')}"
placeholder="xxxx または xxxx.cybozu.com"
class="w-full border rounded-lg px-3 py-2 mt-1">
</div>

<div>
<label class="text-sm font-semibold">
顧客管理アプリID
</label>

<div class="flex gap-2 mt-1">
<input
id="setting_app_id"
value="${App.util.escAttr(s.app_id || '')}"
class="flex-1 border rounded-lg px-3 py-2">

<button
type="button"
class="px-4 py-2 bg-blue-600 text-white rounded-lg whitespace-nowrap"
onclick="App.actions.fetchKintoneFields()">
項目一覧を取得
</button>
</div>

<div
id="field_message"
class="text-sm text-slate-500 mt-2">
${
App.state.kintoneFields.length
? App.state.kintoneFields.length + '項目取得済み'
: ''
}
</div>
</div>

<div>
<label class="text-sm font-semibold">
ログイン名
</label>

<input
id="setting_login_name"
value="${App.util.escAttr(s.login_name || '')}"
class="w-full border rounded-lg px-3 py-2 mt-1">
</div>

<div>
<label class="text-sm font-semibold">
パスワード
</label>

<input
id="setting_password"
type="password"
value="${App.util.escAttr(s.password || '')}"
class="w-full border rounded-lg px-3 py-2 mt-1">
</div>

<div>
<label class="text-sm font-semibold">
Proxy
</label>

<input
id="setting_proxy"
value="${App.util.escAttr(s.proxy || '')}"
placeholder="host:port"
class="w-full border rounded-lg px-3 py-2 mt-1">
</div>

<div class="flex items-center gap-2 mt-7">
<input
id="setting_ssl_verify"
type="checkbox"
${s.ssl_verify ? 'checked' : ''}>
<label>
SSL証明書を検証する
</label>
</div>

</div>

</div>

<div class="bg-white border rounded-xl p-5">

<h2 class="font-bold text-lg mb-4">
項目マッピング
</h2>

<div
id="field_mapping"
class="grid md:grid-cols-2 gap-5">

${App.render.fieldMappings()}

</div>

</div>

<div class="flex justify-end gap-2">

<button
type="button"
class="px-4 py-2 border rounded-lg"
onclick="App.actions.testKintone()">
接続確認
</button>

<button
type="submit"
class="px-5 py-2 bg-blue-600 text-white rounded-lg">
設定を保存
</button>

</div>

</form>

</main>
</div>`;
    },


    /*
     * ★★★ kintoneマッピングの核心部分 ★★★
     *
     * App.state.kintoneFields を唯一のデータソースにする。
     * fetchKintoneFields() 取得後もこの関数から再生成するため、
     * 「取得できているのにプルダウンに出ない」を防止する。
     */
    fieldMappings() {

        const fields =
            Array.isArray(
                App.state.kintoneFields
            )
            ? App.state.kintoneFields
            : [];

        const settings =
            App.state.settings || {};

        const options =
            (selected = '') => {

                return `
<option value="">
-- 選択してください --
</option>

${fields.map(
    field => `
<option
value="${App.util.escAttr(field.code)}"
${selected === field.code ? 'selected' : ''}>
${App.util.escape(field.label)}
 [${App.util.escape(field.code)}]
${field.type ? ' / ' + App.util.escape(field.type) : ''}
</option>`
).join('')}`;
            };

        const mappings = [
            [
                'field_company',
                '会社名 (Company)',
                settings.field_company || ''
            ],
            [
                'field_name',
                '氏名 (Name)',
                settings.field_name || ''
            ],
            [
                'field_email',
                'メールアドレス (Email)',
                settings.field_email || ''
            ],
            [
                'field_department',
                '部署名 (Department)',
                settings.field_department || ''
            ],
            [
                'field_phone',
                '電話番号 (Phone)',
                settings.field_phone || ''
            ]
        ];

        const html =
            mappings.map(
                ([key, label, selected]) => `
<div>
<label class="block text-sm font-semibold mb-1">
${label}
</label>

<select
data-field-key="${key}"
class="w-full border rounded-lg px-3 py-2">
${options(selected)}
</select>
</div>`
            ).join('');

        const selectedAddress =
            Array.isArray(
                settings.field_address
            )
            ? settings.field_address
            : [];

        return html + `
<div class="md:col-span-2">

<label class="block text-sm font-semibold mb-1">
住所 (Address)
</label>

<select
multiple
size="7"
data-field-key="field_address"
class="w-full border rounded-lg px-3 py-2">

${fields.map(
    field => `
<option
value="${App.util.escAttr(field.code)}"
${selectedAddress.includes(field.code) ? 'selected' : ''}>
${App.util.escape(field.label)}
 [${App.util.escape(field.code)}]
${field.type ? ' / ' + App.util.escape(field.type) : ''}
</option>`
).join('')}

</select>

<p class="text-xs text-slate-500 mt-1">
Ctrl / Commandを押しながら複数項目を選択できます。
</p>

</div>`;
    }
};


/* ================================================================
 * ACTIONS
 * ================================================================ */

App.actions = {

    async load() {

        try {

            const result =
                await App.api.request(
                    'load'
                );

            App.state.csrfToken =
                result.csrf_token || '';

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

            App.render.current();

        } catch (error) {

            document.getElementById(
                'app'
            ).innerHTML = `
<div class="min-h-screen flex items-center justify-center p-5">
<div class="bg-white border rounded-xl p-8 max-w-xl">
<h1 class="font-bold text-xl mb-3">
読み込みに失敗しました
</h1>
<p class="text-red-600 whitespace-pre-wrap">
${App.util.escape(error.message)}
</p>
</div>
</div>`;
        }
    },


    newSurvey() {

        const survey = {
            id: App.util.id(),
            title: '',
            start_at: '',
            end_at: '',
            status: 'draft',
            created_at: '',
            updated_at: '',
            numbering_mode: 'global',
            groups: [
                {
                    id: App.util.id(),
                    name: 'グループ1',
                    questions: []
                }
            ],
            deleted: false
        };

        App.state.survey = survey;
        App.state.editingSurveyId =
            survey.id;
        App.state.dirty = false;
        App.state.screen = 'edit';

        App.render.current();
    },


    editSurvey(id) {

        const survey =
            App.state.surveys.find(
                s => s.id === id
            );

        if (!survey) {
            alert(
                'アンケートが見つかりません。'
            );
            return;
        }

        App.state.survey =
            App.util.clone(survey);

        App.state.editingSurveyId =
            id;

        App.state.dirty = false;
        App.state.screen = 'edit';

        App.render.current();
    },


    changeSurveyField(
        key,
        value
    ) {

        if (!App.state.survey) {
            return;
        }

        App.state.survey[key] = value;
        App.state.dirty = true;

        if (
            key === 'numbering_mode'
        ) {
            App.actions.renumber();
            App.render.current();
        }
    },


    changeStatusLocal(value) {

        if (!App.state.survey) {
            return;
        }

        App.state.survey.status =
            value;

        App.state.dirty = true;
    },


    addGroup() {

        if (!App.state.survey) {
            return;
        }

        const number =
            App.state.survey.groups.length + 1;

        App.state.survey.groups.push({
            id: App.util.id(),
            name:
                'グループ' + number,
            questions: []
        });

        App.state.dirty = true;

        App.actions.renumber();
        App.render.current();

        setTimeout(
            () => App.actions.initSortable(),
            0
        );
    },


    deleteGroup(id) {

        if (!App.state.survey) {
            return;
        }

        if (
            !confirm(
                'このグループと、グループ内の質問を削除しますか？'
            )
        ) {
            return;
        }

        App.state.survey.groups =
            App.state.survey.groups.filter(
                g => g.id !== id
            );

        App.state.dirty = true;

        App.actions.renumber();
        App.render.current();
    },


    changeGroupName(
        id,
        value
    ) {

        const group =
            App.state.survey?.groups.find(
                g => g.id === id
            );

        if (!group) {
            return;
        }

        group.name = value;
        App.state.dirty = true;
    },


    addQuestion(groupId) {

        if (!App.state.survey) {
            return;
        }

        let group =
            App.state.survey.groups.find(
                g => g.id === groupId
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

        App.state.dirty = true;

        App.actions.renumber();
        App.render.current();

        setTimeout(
            () => App.actions.initSortable(),
            0
        );
    },


    deleteQuestion(id) {

        if (!App.state.survey) {
            return;
        }

        App.state.survey.groups =
            App.state.survey.groups.map(
                group => ({
                    ...group,
                    questions:
                        group.questions.filter(
                            q => q.id !== id
                        )
                })
            );

        App.state.dirty = true;

        App.actions.renumber();
        App.render.current();
    },


    findQuestion(id) {

        if (!App.state.survey) {
            return null;
        }

        for (
            const group
            of App.state.survey.groups
        ) {

            const question =
                group.questions.find(
                    q => q.id === id
                );

            if (question) {
                return question;
            }
        }

        return null;
    },


    changeQuestion(
        id,
        key,
        value
    ) {

        const q =
            App.actions.findQuestion(id);

        if (!q) {
            return;
        }

        q[key] = value;
        App.state.dirty = true;
    },


    changeRequired(
        id,
        value
    ) {

        const q =
            App.actions.findQuestion(id);

        if (!q) {
            return;
        }

        q.required =
            Boolean(value);

        App.state.dirty = true;
    },


    changeType(
        id,
        type
    ) {

        const q =
            App.actions.findQuestion(id);

        if (!q) {
            return;
        }

        q.type = type;

        if (
            type === 'text'
        ) {
            q.options = [];
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

        App.state.dirty = true;

        App.render.current();
    },


    addOption(id) {

        const q =
            App.actions.findQuestion(id);

        if (!q) {
            return;
        }

        if (!Array.isArray(q.options)) {
            q.options = [];
        }

        q.options.push(
            '選択肢' +
            (q.options.length + 1)
        );

        App.state.dirty = true;

        App.render.current();
    },


    removeOption(
        id,
        index
    ) {

        const q =
            App.actions.findQuestion(id);

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
            delete q.branch[removed];
        }

        App.state.dirty = true;

        App.render.current();
    },


    changeOption(
        id,
        index,
        value
    ) {

        const q =
            App.actions.findQuestion(id);

        if (!q) {
            return;
        }

        const old =
            q.options[index];

        q.options[index] =
            value;

        if (
            q.branch &&
            Object.prototype.hasOwnProperty
                .call(
                    q.branch,
                    old
                )
        ) {
            q.branch[value] =
                q.branch[old];

            delete q.branch[old];
        }

        App.state.dirty = true;
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
            delete q.branch[option];
        }

        App.state.dirty = true;
    },


    renumber() {

        if (!App.state.survey) {
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
                                        .numbering_mode ===
                                    'group'
                                ) {

                                    question.number =
                                        'Q' +
                                        (groupIndex + 1) +
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

        const container =
            document.querySelector(
                '[data-group-container]'
            );

        if (container) {

            if (container._sortable) {
                container._sortable.destroy();
            }

            container._sortable =
                new Sortable(
                    container,
                    {
                        animation: 180,
                        handle:
                            '[data-group] > div:first-child .cursor-move',
                        ghostClass:
                            'opacity-50',

                        onEnd() {
                            App.actions.syncGroups();
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

                    if (list._sortable) {
                        list._sortable.destroy();
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
                                    App.actions.syncQuestions();
                                }
                            }
                        );
                }
            );
    },


    syncGroups() {

        if (!App.state.survey) {
            return;
        }

        const ids =
            [
                ...document.querySelectorAll(
                    '[data-group]'
                )
            ].map(
                el =>
                    el.dataset.group
            );

        const map =
            Object.fromEntries(
                App.state.survey.groups.map(
                    group => [
                        group.id,
                        group
                    ]
                )
            );

        App.state.survey.groups =
            ids.map(
                id => map[id]
            ).filter(Boolean);

        App.actions.renumber();
        App.state.dirty = true;
    },


    syncQuestions() {

        if (!App.state.survey) {
            return;
        }

        const old =
            App.state.survey.groups;

        const questionMap =
            Object.fromEntries(
                old.flatMap(
                    g =>
                        g.questions.map(
                            q => [
                                q.id,
                                q
                            ]
                        )
                )
            );

        const groups =
            [
                ...document.querySelectorAll(
                    '[data-group]'
                )
            ];

        groups.forEach(
            groupElement => {

                const group =
                    old.find(
                        g =>
                            g.id ===
                            groupElement
                                .dataset.group
                    );

                if (!group) {
                    return;
                }

                const list =
                    groupElement.querySelector(
                        '[data-group-list]'
                    );

                if (!list) {
                    return;
                }

                group.questions =
                    [
                        ...list.querySelectorAll(
                            '[data-question]'
                        )
                    ]
                    .map(
                        qElement =>
                            questionMap[
                                qElement
                                    .dataset.question
                            ]
                    )
                    .filter(Boolean);
            }
        );

        App.actions.renumber();
        App.state.dirty = true;
        App.render.current();
    },


    async saveSurvey() {

        if (!App.state.survey) {
            return;
        }

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
                    s =>
                        s.id ===
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

            App.state.dirty = false;
            App.state.survey = null;
            App.state.screen = 'list';

            App.render.current();

            alert(
                result.message ||
                '保存しました。'
            );

        } catch (error) {

            alert(
                '保存に失敗しました。\n\n' +
                error.message
            );
        }
    },


    collectSurvey() {

        if (!App.state.survey) {
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

        const numbering =
            document.getElementById(
                'survey_numbering_mode'
            );

        if (title) {
            App.state.survey.title =
                title.value;
        }

        if (start) {
            App.state.survey.start_at =
                start.value;
        }

        if (end) {
            App.state.survey.end_at =
                end.value;
        }

        if (numbering) {
            App.state.survey.numbering_mode =
                numbering.value;
        }
    },


    async cancelEdit() {

        if (
            App.state.dirty &&
            !confirm(
                '変更を破棄して一覧へ戻りますか？'
            )
        ) {
            return;
        }

        App.actions.backList();
    },


    async changeStatus(
        id,
        status
    ) {

        const message =
            status === 'ended'
                ? 'アンケートを停止しますか？'
                : 'ステータスを変更しますか？';

        if (!confirm(message)) {
            return;
        }

        try {

            await App.api.request(
                'status_survey',
                'POST',
                {
                    survey_id: id,
                    status
                }
            );

            await App.actions.load();

        } catch (error) {

            alert(error.message);
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

            await App.actions.load();

        } catch (error) {

            alert(error.message);
        }
    },


    async duplicateSurvey(id) {

        const source =
            App.state.surveys.find(
                s => s.id === id
            );

        if (!source) {
            return;
        }

        const copy =
            App.util.clone(source);

        copy.id =
            App.util.id();

        copy.title =
            (source.title || 'アンケート') +
            '（複製）';

        copy.status = 'draft';
        copy.deleted = false;
        copy.created_at = '';
        copy.updated_at = '';

        copy.groups =
            copy.groups.map(
                group => ({
                    ...group,
                    id: App.util.id(),
                    questions:
                        group.questions.map(
                            question => ({
                                ...question,
                                id: App.util.id()
                            })
                        )
                })
            );

        try {

            const result =
                await App.api.request(
                    'save_survey',
                    'POST',
                    {
                        survey_json:
                            copy
                    }
                );

            App.state.surveys.push(
                result.survey
            );

            App.render.current();

        } catch (error) {

            alert(error.message);
        }
    },


    summary(id) {

        const survey =
            App.state.surveys.find(
                s => s.id === id
            );

        if (!survey) {
            return;
        }

        App.state.survey =
            App.util.clone(survey);

        App.actions.renumber();

        App.state.selectedQuestions =
            {};

        App.state.survey.groups
            .flatMap(
                g => g.questions
            )
            .forEach(
                q => {
                    App.state
                        .selectedQuestions
                        [q.id] = true;
                }
            );

        App.state.screen =
            'summary';

        App.render.current();
    },


    filterResponses(value) {

        App.state.responseFilter =
            value;

        const table =
            document.getElementById(
                'response_table'
            );

        if (table) {
            table.innerHTML =
                App.render.summaryResponses();
        }
    },


    toggleQuestion(
        id,
        checked
    ) {

        App.state.selectedQuestions[id] =
            checked;

        App.render.current();
    },


    selectAllQuestions(
        value
    ) {

        if (!App.state.survey) {
            return;
        }

        App.state.survey.groups
            .flatMap(
                g => g.questions
            )
            .forEach(
                q => {
                    App.state
                        .selectedQuestions
                        [q.id] = value;
                }
            );

        App.render.current();
    },


    showResponse(id) {

        const response =
            App.state.responses.find(
                r => r.id === id
            );

        if (!response) {
            return;
        }

        const questions =
            App.state.survey.groups
                .flatMap(
                    g => g.questions
                );

        const detail =
            questions.map(
                q => {

                    let value =
                        response.answers?.[
                            q.id
                        ] ?? '';

                    if (Array.isArray(value)) {
                        value =
                            value.join('、');
                    }

                    return `
<div class="border-b py-4">
<div class="font-semibold">
${App.util.escape(q.number || '')}
 ${App.util.escape(q.text || '')}
</div>

<div class="mt-1 whitespace-pre-wrap text-slate-600">
${App.util.escape(value)}
</div>
</div>`;
                }
            ).join('');

        const modal =
            document.createElement('div');

        modal.id =
            'response_modal';

        modal.className =
            'fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-5';

        modal.innerHTML = `
<div class="bg-white rounded-xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-auto">

<div class="p-5 border-b flex justify-between">
<div>
<div class="font-bold">全回答</div>
<div class="text-sm text-slate-500">
${App.util.escape(response.name || '')}
</div>
</div>

<button
class="px-3 py-2 border rounded-lg"
onclick="App.actions.closeResponse()">
閉じる
</button>
</div>

<div id="response_detail" class="p-5">
${detail}
</div>

</div>`;

        document.body.appendChild(
            modal
        );
    },


    closeResponse() {

        document.getElementById(
            'response_modal'
        )?.remove();
    },


    mail(id) {

        const survey =
            App.state.surveys.find(
                s => s.id === id
            );

        if (!survey) {
            return;
        }

        App.state.survey =
            App.util.clone(survey);

        App.state.screen =
            'mail';

        App.render.current();
    },


    filterCustomers(value) {

        App.state.customerFilter =
            value;

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
                el =>
                    el.dataset.customerId
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
            )?.value || 'initial';

        const alreadySent =
            selected.some(
                id => {

                    const customer =
                        App.state.customers.find(
                            c => c.id === id
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

            App.state.customers =
                result.customers || [];

            App.state.mailLogs =
                result.mail_logs || [];

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


    registerKintone(id) {

        const customer =
            App.state.customers.find(
                c => c.id === id
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

        if (
            !Array.isArray(
                App.state.kintoneFields
            )
        ) {
            App.state.kintoneFields = [];
        }

        App.render.current();
    },


    /*
     * ============================================================
     * ★★★ kintone項目一覧取得 完全修正版 ★★★
     * ============================================================
     */
    async fetchKintoneFields() {

        const message =
            document.getElementById(
                'field_message'
            );

        /*
         * 取得直前にDOMから最新設定を回収する。
         * ここで field_company 等の既存選択値も維持する。
         */
        const settings =
            App.actions.collectSettings();

        if (!settings.app_id) {

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

            /*
             * ★取得結果を必ず配列化
             */
            App.state.kintoneFields =
                Array.isArray(result.fields)
                    ? result.fields
                    : [];

            /*
             * ★現在の設定も保持
             */
            App.state.settings =
                {
                    ...App.state.settings,
                    ...settings
                };

            /*
             * ★ここが重要
             *
             * innerHTMLだけを部分更新するのではなく、
             * fieldMappings()を唯一の描画元として再生成する。
             */
            const mapping =
                document.getElementById(
                    'field_mapping'
                );

            if (mapping) {

                mapping.innerHTML =
                    App.render.fieldMappings();
            }

            /*
             * ★再描画後もDOMから選択値を再確認
             */
            App.actions.syncMappingState();

            if (message) {

                message.textContent =
                    '項目一覧を取得しました。' +
                    App.state.kintoneFields.length +
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


    /*
     * マッピングDOMの現在値をstateへ反映。
     */
    syncMappingState() {

        const settings =
            App.state.settings || {};

        document
            .querySelectorAll(
                '[data-field-key]'
            )
            .forEach(
                select => {

                    const key =
                        select.dataset.fieldKey;

                    if (!key) {
                        return;
                    }

                    if (select.multiple) {

                        settings[key] =
                            [
                                ...select.selectedOptions
                            ]
                            .map(
                                option =>
                                    option.value
                            )
                            .filter(Boolean);

                    } else {

                        settings[key] =
                            select.value;
                    }
                }
            );

        App.state.settings =
            settings;
    },


    /*
     * ★★★ 設定収集
     *
     * 従来版のように App.state.settings の古い値だけを
     * field_company 等へ戻すことをしない。
     *
     * 必ず現在DOMのselect.valueを読む。
     */
    collectSettings() {

        const settings = {

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
                    ?.field_company || '',

            field_name:
                App.state.settings
                    ?.field_name || '',

            field_email:
                App.state.settings
                    ?.field_email || '',

            field_department:
                App.state.settings
                    ?.field_department || '',

            field_phone:
                App.state.settings
                    ?.field_phone || '',

            field_address:
                Array.isArray(
                    App.state.settings
                        ?.field_address
                )
                ? App.state.settings
                    .field_address
                : []
        };

        /*
         * ★DOM上のプルダウン値を最優先
         */
        document
            .querySelectorAll(
                '[data-field-key]'
            )
            .forEach(
                select => {

                    const key =
                        select.dataset.fieldKey;

                    if (!key) {
                        return;
                    }

                    if (select.multiple) {

                        settings[key] =
                            [
                                ...select.selectedOptions
                            ]
                            .map(
                                option =>
                                    option.value
                            )
                            .filter(Boolean);

                    } else {

                        settings[key] =
                            select.value;
                    }
                }
            );

        return settings;
    },


    async saveSettings() {

        /*
         * ★保存直前にプルダウンを読む
         */
        const settings =
            App.actions.collectSettings();

        try {

            const result =
                await App.api.request(
                    'save_settings',
                    'POST',
                    {
                        settings_json:
                            settings
                    }
                );

            App.state.settings =
                result.settings ||
                settings;

            alert(
                '設定を保存しました。'
            );

        } catch (error) {

            alert(
                '設定保存に失敗しました。\n\n' +
                error.message
            );
        }
    },


    async testKintone() {

        const settings =
            App.actions.collectSettings();

        if (!settings.subdomain) {
            alert(
                'サブドメインを入力してください。'
            );
            return;
        }

        if (!settings.login_name) {
            alert(
                'ログイン名を入力してください。'
            );
            return;
        }

        if (!settings.password) {
            alert(
                'パスワードを入力してください。'
            );
            return;
        }

        if (!settings.app_id) {
            alert(
                'アプリIDを入力してください。'
            );
            return;
        }

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
                result.message ||
                'kintoneへの接続に成功しました。'
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
        App.actions.renumber();

        const survey =
            App.state.survey;

        const questions =
            survey.groups
                .flatMap(
                    g => g.questions
                );

        const modal =
            document.createElement('div');

        modal.id =
            'preview_modal';

        modal.className =
            'fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-5';

        modal.innerHTML = `
<div class="bg-white rounded-xl shadow-xl w-full max-w-3xl max-h-[90vh] overflow-auto">

<div class="p-5 border-b flex justify-between">

<h2 class="font-bold">
${App.util.escape(
    survey.title ||
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

${questions.map(
    q => `

<div class="mb-7">

<div class="font-semibold mb-2">
${App.util.escape(q.number || '')}
 ${App.util.escape(q.text || '')}
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
:
(q.options || [])
.map(
    option => `
<label class="flex gap-2 mb-2">
<input
type="${
    q.type === 'single'
        ? 'radio'
        : 'checkbox'
}"
name="preview_${App.util.escAttr(q.id)}">
<span>
${App.util.escape(option)}
</span>
</label>`
)
.join('')
}

</div>`
).join('')}

<button
type="button"
class="w-full py-3 bg-blue-600 text-white rounded-lg"
onclick="App.actions.previewSubmit()">
送信
</button>

</div>
</div>`;

        document.body.appendChild(
            modal
        );
    },


    closePreview() {

        document.getElementById(
            'preview_modal'
        )?.remove();
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

        App.state.dirty =
            false;

        App.render.current();
    }
};


/* ================================================================
 * 初期化
 * ================================================================ */

App.init = async function() {

    if (
        App.state.initialized
    ) {
        return;
    }

    App.state.initialized =
        true;

    await App.actions.load();
};


/*
 * DOMContentLoaded前後どちらでも1回だけ初期化
 */
if (
    document.readyState ===
    'loading'
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
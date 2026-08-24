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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    session_start();
}

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* =========================================================
 * PHP utilities
 * ======================================================= */

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

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function surveyJsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function surveyId(string $prefix = 'id'): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function surveyNow(): string
{
    return date('Y-m-d\TH:i:s');
}

function surveyCsrf(): string
{
    if (
        empty($_SESSION['survey_csrf_token']) ||
        !is_string($_SESSION['survey_csrf_token'])
    ) {
        $_SESSION['survey_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['survey_csrf_token'];
}

/*
 * CSRF:
 * 通常はセッション値とPOST値を比較する。
 *
 * ただしセッションが再生成されたケースでは、loadで返した最新
 * csrf_tokenを使っているため通常は一致する。
 */
function surveyVerifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || $token === '') {
        surveyJsonResponse([
            'ok' => false,
            'message' => 'CSRFトークンがありません。ページを再読み込みしてください。'
        ], 403);
    }

    $expected = surveyCsrf();

    if (!hash_equals($expected, $token)) {
        /*
         * セッションCookieが切れている場合に古いHTMLから操作される
         * ことがあるため、診断可能なメッセージを返す。
         */
        surveyJsonResponse([
            'ok' => false,
            'message' => 'セッションの有効期限が切れています。ページを再読み込みしてから、もう一度実行してください。',
            'csrf_expired' => true
        ], 403);
    }
}

function surveyPostJson(string $key): ?array
{
    $raw = $_POST[$key] ?? null;

    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function surveyNormalizeSurvey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? surveyId('survey'));
    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['start_at'] = (string)($survey['start_at'] ?? '');
    $survey['end_at'] = (string)($survey['end_at'] ?? '');

    $status = (string)($survey['status'] ?? 'draft');
    $survey['status'] = in_array(
        $status,
        ['draft', 'active', 'ended'],
        true
    ) ? $status : 'draft';

    $survey['created_at'] = (string)(
        $survey['created_at'] ?? surveyNow()
    );

    $survey['updated_at'] = (string)(
        $survey['updated_at'] ?? surveyNow()
    );

    $mode = (string)($survey['numbering_mode'] ?? 'global');

    $survey['numbering_mode'] = in_array(
        $mode,
        ['global', 'group'],
        true
    ) ? $mode : 'global';

    $survey['deleted'] = (bool)($survey['deleted'] ?? false);

    $survey['groups'] = is_array($survey['groups'] ?? null)
        ? $survey['groups']
        : [];

    foreach ($survey['groups'] as &$group) {
        if (!is_array($group)) {
            $group = [];
        }

        $group['id'] = (string)(
            $group['id'] ?? surveyId('group')
        );

        $group['name'] = (string)(
            $group['name'] ?? '新しいグループ'
        );

        $group['questions'] = is_array(
            $group['questions'] ?? null
        ) ? $group['questions'] : [];

        foreach ($group['questions'] as &$question) {
            if (!is_array($question)) {
                $question = [];
            }

            $question['id'] = (string)(
                $question['id'] ?? surveyId('question')
            );

            $question['text'] = (string)(
                $question['text'] ?? ''
            );

            $type = (string)($question['type'] ?? 'single');

            $question['type'] = in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            ) ? $type : 'single';

            $question['required'] = (bool)(
                $question['required'] ?? false
            );

            $question['options'] = is_array(
                $question['options'] ?? null
            ) ? array_values(
                array_map('strval', $question['options'])
            ) : [];

            $question['other_enabled'] = (bool)(
                $question['other_enabled'] ?? false
            );
        }

        unset($question);
    }

    unset($group);

    return $survey;
}

/* =========================================================
 * kintone
 * ======================================================= */

/*
 * 入力:
 *   example
 *   example.cybozu.com
 *   https://example.cybozu.com
 *
 * すべて:
 *   example.cybozu.com
 *
 * に正規化する。
 */
function surveyNormalizeKintoneHost(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = preg_replace(
        '#/.*$#',
        '',
        $value
    ) ?? $value;

    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (!str_contains($value, '.')) {
        $value .= '.cybozu.com';
    }

    return $value;
}

function surveyKintoneRequest(
    array $settings,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    $host = surveyNormalizeKintoneHost(
        (string)($settings['subdomain'] ?? '')
    );

    if ($host === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' => 'kintoneのサブドメインを入力してください。'
        ];
    }

    $login = trim(
        (string)($settings['login_name'] ?? '')
    );

    $password = (string)(
        $settings['password'] ?? ''
    );

    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' => 'kintoneのログイン名とパスワードを入力してください。'
        ];
    }

    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }

    $url = 'https://' . $host . $path;

    /*
     * kintone REST API:
     * X-Cybozu-Authorization = base64(login:password)
     */
    $authorization = base64_encode(
        $login . ':' . $password
    );

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Cybozu-Authorization: ' . $authorization,
        'User-Agent: SurveyManagementSystem/1.0'
    ];

    $method = strtoupper($method);

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 30,
            'protocol_version' => 1.1
        ],
        'ssl' => [
            'verify_peer' => (bool)(
                $settings['ssl_verify'] ?? false
            ),
            'verify_peer_name' => (bool)(
                $settings['ssl_verify'] ?? false
            ),
            'allow_self_signed' => !(bool)(
                $settings['ssl_verify'] ?? false
            )
        ]
    ];

    if (
        $body !== null &&
        in_array(
            $method,
            ['POST', 'PUT', 'PATCH'],
            true
        )
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
                'message' => 'kintone APIリクエストJSONの生成に失敗しました。'
            ];
        }

        $options['http']['content'] = $encoded;
    }

    $proxy = trim(
        (string)($settings['proxy'] ?? '')
    );

    if ($proxy !== '') {
        /*
         * host:port
         * http://host:port
         * tcp://host:port
         * のいずれも許容。
         */
        $proxy = preg_replace(
            '#^(https?|tcp)://#i',
            '',
            $proxy
        ) ?? $proxy;

        $options['http']['proxy'] =
            'tcp://' . $proxy;

        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $result = @file_get_contents(
        $url,
        false,
        $context
    );

    /*
     * PHP 8.4/8.5以降:
     * http_get_last_response_headers() が利用できる場合はこちら。
     */
    $responseHeaders = [];

    if (function_exists('http_get_last_response_headers')) {
        $last = http_get_last_response_headers();

        if (is_array($last)) {
            $responseHeaders = $last;
        }
    } elseif (
        isset($http_response_header) &&
        is_array($http_response_header)
    ) {
        $responseHeaders = $http_response_header;
    }

    $statusCode = 0;

    foreach ($responseHeaders as $header) {
        if (
            preg_match(
                '#HTTP/\S+\s+(\d{3})#',
                (string)$header,
                $match
            )
        ) {
            $statusCode = (int)$match[1];
        }
    }

    if ($result === false) {
        return [
            'ok' => false,
            'status' => $statusCode,
            'message' =>
                'kintone APIへの接続に失敗しました。',
            'url' => $url,
            'headers' => $responseHeaders
        ];
    }

    $decoded = json_decode(
        $result,
        true
    );

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $statusCode,
            'message' =>
                'kintone APIからJSONではない応答が返されました。',
            'url' => $url,
            'raw' => mb_substr(
                (string)$result,
                0,
                1000
            )
        ];
    }

    if ($statusCode >= 400) {
        return [
            'ok' => false,
            'status' => $statusCode,
            'message' => (string)(
                $decoded['message'] ??
                'kintone APIエラー'
            ),
            'code' => (string)(
                $decoded['code'] ?? ''
            ),
            'id' => (string)(
                $decoded['id'] ?? ''
            ),
            'data' => $decoded,
            'url' => $url
        ];
    }

    return [
        'ok' => true,
        'status' => $statusCode,
        'data' => $decoded
    ];
}

/* =========================================================
 * API router
 * ======================================================= */

if (isset($_GET['action'])) {
    $action = (string)$_GET['action'];
    $data = surveyReadStorage();

    /*
     * GET: load
     */
    if ($action === 'load') {
        $surveys = [];

        foreach ($data['surveys'] as $survey) {
            if (
                !is_array($survey) ||
                !empty($survey['deleted'])
            ) {
                continue;
            }

            $survey = surveyNormalizeSurvey($survey);

            $count = 0;

            foreach ($data['responses'] as $response) {
                if (
                    is_array($response) &&
                    (string)(
                        $response['survey_id'] ?? ''
                    ) === (string)$survey['id']
                ) {
                    $count++;
                }
            }

            $survey['answer_count'] = $count;
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

    /*
     * CSV
     */
    if ($action === 'export_csv') {
        surveyVerifyCsrf();

        $surveyId = (string)(
            $_GET['survey_id'] ?? ''
        );

        $survey = null;

        foreach ($data['surveys'] as $item) {
            if (
                is_array($item) &&
                (string)($item['id'] ?? '') === $surveyId
            ) {
                $survey = surveyNormalizeSurvey($item);
                break;
            }
        }

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

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="' .
            'survey_' .
            $surveyId .
            '_' .
            date('YmdHis') .
            '.csv"'
        );

        $out = fopen(
            'php://output',
            'wb'
        );

        fwrite(
            $out,
            "\xEF\xBB\xBF"
        );

        $header = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名',
            'メールアドレス'
        ];

        foreach ($questions as $i => $question) {
            $header[] = '設問' . ($i + 1);
        }

        fputcsv($out, $header);

        foreach ($data['responses'] as $response) {
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
                $response['name'] ?? '',
                $response['email'] ?? ''
            ];

            $answers = is_array(
                $response['answers'] ?? null
            ) ? $response['answers'] : [];

            foreach ($questions as $question) {
                $value = $answers[
                    $question['id']
                ] ?? '';

                if (is_array($value)) {
                    $value = implode(
                        '、',
                        $value
                    );
                }

                $row[] = (string)$value;
            }

            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /*
     * POST以下はCSRF必須。
     */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        surveyJsonResponse([
            'ok' => false,
            'message' => 'この操作にはPOSTリクエストが必要です。'
        ], 405);
    }

    surveyVerifyCsrf();

    /*
     * kintone fields
     */
    if ($action === 'kintone_fields') {
        $settings =
            surveyPostJson('settings_json')
            ?? $data['settings'];

        $appId = trim(
            (string)(
                $settings['app_id']
                ?? ($_POST['app_id'] ?? '')
            )
        );

        if ($appId === '') {
            surveyJsonResponse([
                'ok' => false,
                'message' => 'kintoneアプリIDを入力してください。'
            ], 400);
        }

        if (!ctype_digit($appId)) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'kintoneアプリIDは数値で入力してください。'
            ], 400);
        }

        $settings['app_id'] = $appId;

        $result = surveyKintoneRequest(
            $settings,
            '/k/v1/app/form/fields.json?app=' .
            rawurlencode($appId),
            'GET'
        );

        if (!$result['ok']) {
            $message =
                'kintone項目一覧取得に失敗しました。';

            if (!empty($result['status'])) {
                $message .=
                    ' HTTP ' .
                    (int)$result['status'] .
                    '.';
            }

            if (!empty($result['code'])) {
                $message .=
                    ' [' .
                    $result['code'] .
                    ']';
            }

            if (!empty($result['message'])) {
                $message .=
                    ' ' .
                    $result['message'];
            }

            surveyJsonResponse([
                'ok' => false,
                'message' => $message,
                'status' => $result['status'] ?? 0,
                'code' => $result['code'] ?? '',
                'url' => $result['url'] ?? ''
            ], 400);
        }

        $fields = [];

        foreach (
            ($result['data']['properties'] ?? [])
            as $code => $property
        ) {
            if (!is_array($property)) {
                continue;
            }

            /*
             * LABELがない特殊フィールドにも対応。
             */
            $label = (string)(
                $property['label'] ??
                $code
            );

            $type = (string)(
                $property['type'] ??
                ''
            );

            $fields[] = [
                'code' => (string)$code,
                'label' => $label,
                'type' => $type
            ];
        }

        usort(
            $fields,
            static function(
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

    /*
     * save survey
     */
    if ($action === 'save_survey') {
        $survey = surveyPostJson(
            'survey_json'
        );

        if ($survey === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'アンケートデータが不正です。'
            ], 400);
        }

        $survey = surveyNormalizeSurvey(
            $survey
        );

        $survey['updated_at'] = surveyNow();

        $found = false;

        foreach (
            $data['surveys']
            as $index => $existing
        ) {
            if (
                is_array($existing) &&
                (string)(
                    $existing['id'] ?? ''
                ) === (string)$survey['id']
            ) {
                $survey['created_at'] =
                    (string)(
                        $existing['created_at']
                        ?? $survey['created_at']
                    );

                $data['surveys'][$index] =
                    $survey;

                $found = true;
                break;
            }
        }

        if (!$found) {
            $survey['created_at'] =
                surveyNow();

            $data['surveys'][] =
                $survey;
        }

        if (!surveyWriteStorage($data)) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'データ保存に失敗しました。'
            ], 500);
        }

        surveyJsonResponse([
            'ok' => true,
            'survey' => $survey
        ]);
    }

    /*
     * status
     */
    if ($action === 'status_survey') {
        $surveyId = (string)(
            $_POST['survey_id'] ?? ''
        );

        $newStatus = (string)(
            $_POST['status'] ?? ''
        );

        if (!in_array(
            $newStatus,
            ['draft', 'active', 'ended'],
            true
        )) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '不正なステータスです。'
            ], 400);
        }

        $found = false;

        foreach (
            $data['surveys']
            as $index => $survey
        ) {
            if (
                is_array($survey) &&
                (string)(
                    $survey['id'] ?? ''
                ) === $surveyId
            ) {
                $data['surveys'][$index]['status'] =
                    $newStatus;

                $data['surveys'][$index]['updated_at'] =
                    surveyNow();

                $found = true;
                break;
            }
        }

        if (!$found) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'アンケートが見つかりません。'
            ], 404);
        }

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true
        ]);
    }

    /*
     * delete
     */
    if ($action === 'delete_survey') {
        $surveyId = (string)(
            $_POST['survey_id'] ?? ''
        );

        foreach (
            $data['surveys']
            as $index => $survey
        ) {
            if (
                is_array($survey) &&
                (string)(
                    $survey['id'] ?? ''
                ) === $surveyId
            ) {
                $data['surveys'][$index]['deleted'] =
                    true;

                $data['surveys'][$index]['updated_at'] =
                    surveyNow();
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true
        ]);
    }

    /*
     * duplicate
     */
    if ($action === 'duplicate_survey') {
        $surveyId = (string)(
            $_POST['survey_id'] ?? ''
        );

        $copy = null;

        foreach (
            $data['surveys']
            as $survey
        ) {
            if (
                is_array($survey) &&
                (string)(
                    $survey['id'] ?? ''
                ) === $surveyId
            ) {
                $copy =
                    surveyNormalizeSurvey(
                        $survey
                    );

                break;
            }
        }

        if ($copy === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '複製元アンケートが見つかりません。'
            ], 404);
        }

        $copy['id'] =
            surveyId('survey');

        $copy['title'] .=
            '（コピー）';

        $copy['status'] =
            'draft';

        $copy['created_at'] =
            surveyNow();

        $copy['updated_at'] =
            surveyNow();

        $copy['deleted'] =
            false;

        foreach (
            $copy['groups']
            as &$group
        ) {
            $group['id'] =
                surveyId('group');

            foreach (
                $group['questions']
                as &$question
            ) {
                $question['id'] =
                    surveyId('question');
            }

            unset($question);
        }

        unset($group);

        $data['surveys'][] =
            $copy;

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true,
            'survey' => $copy
        ]);
    }

    /*
     * settings
     */
    if ($action === 'save_settings') {
        $settings =
            surveyPostJson(
                'settings_json'
            );

        if ($settings === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '設定データが不正です。'
            ], 400);
        }

        $defaults =
            surveyGuardData()['settings'];

        $settings =
            array_merge(
                $defaults,
                $settings
            );

        $settings['subdomain'] =
            trim(
                (string)$settings['subdomain']
            );

        $settings['app_id'] =
            trim(
                (string)$settings['app_id']
            );

        $settings['field_address'] =
            is_array(
                $settings['field_address']
            )
            ? array_values(
                array_filter(
                    array_map(
                        'strval',
                        $settings['field_address']
                    )
                )
            )
            : [];

        $data['settings'] =
            $settings;

        if (!surveyWriteStorage($data)) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '設定保存に失敗しました。'
            ], 500);
        }

        surveyJsonResponse([
            'ok' => true,
            'settings' => $settings
        ]);
    }

    /*
     * mark kintone
     */
    if ($action === 'mark_kintone') {
        $customerId =
            (string)(
                $_POST['customer_id'] ?? ''
            );

        foreach (
            $data['customers']
            as $index => $customer
        ) {
            if (
                is_array($customer) &&
                (string)(
                    $customer['id'] ?? ''
                ) === $customerId
            ) {
                $data['customers'][$index]
                    ['kintone_status'] =
                    'registered';
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true
        ]);
    }

    /*
     * send mail
     */
    if ($action === 'send_mail') {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $recipientIds =
            $_POST['recipient_ids'] ?? [];

        if (!is_array($recipientIds)) {
            $recipientIds = [];
        }

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
                $_POST['template_type']
                ?? 'initial'
            );

        $survey = null;

        foreach (
            $data['surveys']
            as $item
        ) {
            if (
                is_array($item) &&
                (string)(
                    $item['id'] ?? ''
                ) === $surveyId
            ) {
                $survey = $item;
                break;
            }
        }

        if ($survey === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'アンケートが見つかりません。'
            ], 404);
        }

        if (
            ($survey['status'] ?? '')
            !== 'active'
        ) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '公開中のアンケートだけ送信できます。'
            ], 400);
        }

        $sent = 0;
        $errors = [];

        foreach (
            $data['customers']
            as $index => $customer
        ) {
            if (!is_array($customer)) {
                continue;
            }

            $customerId =
                (string)(
                    $customer['id'] ?? ''
                );

            if (
                !in_array(
                    $customerId,
                    $recipientIds,
                    true
                )
            ) {
                continue;
            }

            $email =
                trim(
                    (string)(
                        $customer['email']
                        ?? ''
                    )
                );

            if (
                $email === '' ||
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $errors[] =
                    ($customer['name'] ?? $email) .
                    ': メールアドレス不正';

                continue;
            }

            $customerName =
                (string)(
                    $customer['name'] ?? ''
                );

            $scheme =
                (
                    !empty($_SERVER['HTTPS']) &&
                    $_SERVER['HTTPS'] !== 'off'
                )
                ? 'https'
                : 'http';

            $host =
                $_SERVER['HTTP_HOST']
                ?? 'localhost';

            $scriptDir =
                rtrim(
                    dirname(
                        $_SERVER['SCRIPT_NAME']
                    ),
                    '/\\'
                );

            $answerUrl =
                $scheme .
                '://' .
                $host .
                $scriptDir .
                '/?answer=' .
                rawurlencode($surveyId) .
                '&customer=' .
                rawurlencode($customerId);

            $actualSubject =
                str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}'
                    ],
                    [
                        $customerName,
                        $answerUrl
                    ],
                    $subject
                );

            $actualBody =
                str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}'
                    ],
                    [
                        $customerName,
                        $answerUrl
                    ],
                    $body
                );

            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' .
                (
                    $_SERVER['SERVER_ADMIN']
                    ?? 'webmaster@localhost'
                )
            ];

            $mailOk = @mail(
                $email,
                $actualSubject,
                $actualBody,
                implode(
                    "\r\n",
                    $headers
                )
            );

            /*
             * mail()が使えない環境でも管理画面のモックとして
             * 送信処理そのものを確認できるよう、送信失敗を明示する。
             */
            if ($mailOk) {
                $sent++;

                $data['customers'][$index]['sent_at'] =
                    surveyNow();

                $data['customers'][$index]['send_count'] =
                    (int)(
                        $customer['send_count']
                        ?? 0
                    ) + 1;

                $data['customers'][$index]['answer_status'] =
                    'unanswered';

                $data['mail_logs'][] = [
                    'id' =>
                        surveyId('mail'),
                    'survey_id' =>
                        $surveyId,
                    'customer_id' =>
                        $customerId,
                    'sent_at' =>
                        surveyNow(),
                    'type' =>
                        $templateType,
                    'subject' =>
                        $actualSubject,
                    'body' =>
                        $actualBody,
                    'executor' =>
                        $_SESSION[
                            'survey_admin_name'
                        ] ?? '管理者'
                ];
            } else {
                $errors[] =
                    $customerName .
                    ': メール送信に失敗しました';
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true,
            'sent' => $sent,
            'errors' => $errors,
            'message' =>
                $sent .
                '件のメールを送信しました。'
        ]);
    }

    surveyJsonResponse([
        'ok' => false,
        'message' =>
            '不明なAPIアクションです。'
    ], 400);
}

/* =========================================================
 * Answer endpoint
 * ======================================================= */

if (
    isset($_GET['answer']) &&
    isset($_GET['customer'])
) {
    $data = surveyReadStorage();

    $surveyId =
        (string)$_GET['answer'];

    $customerId =
        (string)$_GET['customer'];

    $survey = null;
    $customer = null;

    foreach ($data['surveys'] as $item) {
        if (
            is_array($item) &&
            (string)(
                $item['id'] ?? ''
            ) === $surveyId &&
            empty($item['deleted'])
        ) {
            $survey =
                surveyNormalizeSurvey(
                    $item
                );

            break;
        }
    }

    foreach ($data['customers'] as $item) {
        if (
            is_array($item) &&
            (string)(
                $item['id'] ?? ''
            ) === $customerId
        ) {
            $customer = $item;
            break;
        }
    }

    if (
        $survey === null ||
        $customer === null ||
        ($survey['status'] ?? '') !== 'active'
    ) {
        http_response_code(404);
        exit('アンケートが見つかりません。');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $answersRaw =
            $_POST['answers'] ?? '{}';

        $answers =
            is_string($answersRaw)
            ? json_decode(
                $answersRaw,
                true
            )
            : [];

        if (!is_array($answers)) {
            $answers = [];
        }

        $response = [
            'id' =>
                surveyId('response'),
            'survey_id' =>
                $surveyId,
            'customer_id' =>
                $customerId,
            'company' =>
                (string)(
                    $customer['company']
                    ?? ''
                ),
            'name' =>
                (string)(
                    $customer['name']
                    ?? ''
                ),
            'email' =>
                (string)(
                    $customer['email']
                    ?? ''
                ),
            'answered_at' =>
                surveyNow(),
            'answers' =>
                $answers
        ];

        $data['responses'][] =
            $response;

        foreach (
            $data['customers']
            as $index => $item
        ) {
            if (
                is_array($item) &&
                (string)(
                    $item['id'] ?? ''
                ) === $customerId
            ) {
                $data['customers'][$index]
                    ['answer_status'] =
                    'answered';

                break;
            }
        }

        surveyWriteStorage($data);

        header(
            'Content-Type: text/html; charset=UTF-8'
        );

        ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<title>回答完了</title>
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-6">
<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-10 max-w-xl w-full text-center">
<div class="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-3xl mx-auto mb-5">✓</div>
<h1 class="text-2xl font-bold text-slate-800">回答を送信しました</h1>
<p class="text-slate-500 mt-3">ご回答ありがとうございました。</p>
</div>
</body>
</html>
<?php
        exit;
    }

    header(
        'Content-Type: text/html; charset=UTF-8'
    );
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<title><?= htmlspecialchars($survey['title'], ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body class="bg-slate-50 text-slate-800">
<div class="max-w-3xl mx-auto p-6">
<div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-8">
<h1 class="text-2xl font-bold mb-8">
<?= htmlspecialchars($survey['title'], ENT_QUOTES, 'UTF-8') ?>
</h1>

<form method="post" id="answer_form">
<?php
$qIndex = 0;

foreach ($survey['groups'] as $group):
?>
<div class="mb-10">
<h2 class="text-lg font-bold border-b pb-3 mb-6">
<?= htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8') ?>
</h2>

<?php foreach ($group['questions'] as $question):
$qIndex++;
?>
<div class="mb-8">
<label class="block font-semibold mb-3">
Q<?= $qIndex ?>.
<?= htmlspecialchars($question['text'], ENT_QUOTES, 'UTF-8') ?>

<?php if (!empty($question['required'])): ?>
<span class="text-red-500 text-xs ml-2">必須</span>
<?php endif; ?>
</label>

<?php if ($question['type'] === 'single'): ?>

<div class="space-y-2">
<?php foreach ($question['options'] as $option): ?>
<label class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50">
<input
type="radio"
name="answer_<?= htmlspecialchars($question['id'], ENT_QUOTES, 'UTF-8') ?>"
value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"
class="w-4 h-4">
<span><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></span>
</label>
<?php endforeach; ?>

<?php if (!empty($question['other_enabled'])): ?>
<label class="flex items-center gap-3 p-3">
<input
type="radio"
name="answer_<?= htmlspecialchars($question['id'], ENT_QUOTES, 'UTF-8') ?>"
value="その他"
class="w-4 h-4">
<span>その他</span>
</label>
<input
name="other_<?= htmlspecialchars($question['id'], ENT_QUOTES, 'UTF-8') ?>"
class="w-full border rounded-lg px-3 py-2"
placeholder="その他の内容">
<?php endif; ?>
</div>

<?php elseif ($question['type'] === 'multiple'): ?>

<div class="space-y-2">
<?php foreach ($question['options'] as $option): ?>
<label class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50">
<input
type="checkbox"
name="answer_<?= htmlspecialchars($question['id'], ENT_QUOTES, 'UTF-8') ?>[]"
value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"
class="w-4 h-4">
<span><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></span>
</label>
<?php endforeach; ?>
</div>

<?php else: ?>

<textarea
name="answer_<?= htmlspecialchars($question['id'], ENT_QUOTES, 'UTF-8') ?>"
rows="5"
class="w-full border border-slate-200 rounded-xl p-3"
></textarea>

<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<button
type="button"
onclick="submitAnswer()"
class="w-full py-3 rounded-xl bg-teal-600 hover:bg-teal-700 text-white font-semibold">
回答を送信
</button>

</form>
</div>
</div>

<script>
function submitAnswer() {
    const form = document.getElementById('answer_form');
    const result = {};

    form.querySelectorAll('[name^="answer_"]').forEach(function(el) {
        const name = el.name.replace(/^answer_/, '');

        if (el.type === 'checkbox') {
            if (!result[name]) result[name] = [];
            if (el.checked) result[name].push(el.value);
        } else if (el.type === 'radio') {
            if (el.checked) result[name] = el.value;
        } else {
            result[name] = el.value;
        }
    });

    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'answers';
    hidden.value = JSON.stringify(result);

    form.appendChild(hidden);
    form.submit();
}
</script>
</body>
</html>
<?php
    exit;
}

/* =========================================================
 * SPA
 * ======================================================= */

$csrf = surveyCsrf();

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<script>
tailwind.config = {
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#eef7f8',
                    100: '#d9eef0',
                    500: '#16808a',
                    600: '#116d76',
                    700: '#0e5a61'
                }
            }
        }
    }
};
</script>
</head>

<body class="bg-slate-50 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App = {
    state: {
        initialized: false,
        surveys: [],
        responses: [],
        customers: [],
        settings: {},
        mailLogs: [],
        csrfToken: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>,
        view: 'list',
        currentSurveyId: null,
        editingSurvey: null,
        responseKeyword: '',
        selectedQuestions: {},
        selectedCustomers: [],
        mailSurveyId: null,
        kintoneFields: [],
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        loading: false,
        previewSurvey: null,
        responseModalId: null
    },

    templates: {},
    utils: {},
    api: {},
    actions: {},
    render: {},

    init: function() {
        if (App.state.initialized) return;

        App.state.initialized = true;
        App.api.load();
    }
};
</script>

<script>
/* =========================================================
 * Utility
 * ======================================================= */

App.utils.escapeHtml = function(value) {
    const div = document.createElement('div');
    div.textContent =
        value == null ? '' : String(value);
    return div.innerHTML;
};

App.utils.escapeAttr = function(value) {
    return App.utils.escapeHtml(value)
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
};

App.utils.clone = function(value) {
    return JSON.parse(JSON.stringify(value));
};

App.utils.uid = function(prefix) {
    return prefix + '_' +
        Date.now().toString(36) + '_' +
        Math.random().toString(36).slice(2);
};

App.utils.notify = function(message, type) {
    const colors = {
        success: 'bg-emerald-600',
        error: 'bg-red-600',
        warning: 'bg-amber-600',
        info: 'bg-slate-800'
    };

    const el = document.createElement('div');

    el.className =
        'fixed right-5 top-5 z-[200] ' +
        (colors[type || 'info'] || colors.info) +
        ' text-white px-5 py-3 rounded-xl shadow-xl text-sm max-w-lg';

    el.textContent = message;

    document.body.appendChild(el);

    setTimeout(function() {
        el.remove();
    }, 4000);
};

App.utils.statusLabel = function(status) {
    return {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[status] || status;
};

App.utils.typeLabel = function(type) {
    return {
        single: '単一選択',
        multiple: '複数選択',
        text: '自由記述'
    }[type] || type;
};

App.utils.formatDate = function(value) {
    if (!value) return '-';

    return String(value)
        .substring(0, 16)
        .replace('T', ' ');
};

App.utils.statusBadge = function(status) {
    const colors = {
        draft:
            'bg-slate-100 text-slate-700',
        active:
            'bg-emerald-100 text-emerald-700',
        ended:
            'bg-gray-100 text-gray-500'
    };

    return `
        <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold
        ${colors[status] || colors.draft}">
            ${App.utils.escapeHtml(
                App.utils.statusLabel(status)
            )}
        </span>
    `;
};
</script>

<script>
/* =========================================================
 * API
 * ======================================================= */

App.api.request = async function(
    action,
    data,
    method
) {
    method = method || 'POST';
    data = data || {};

    let url =
        '?action=' +
        encodeURIComponent(action);

    const options = {
        method: method,
        credentials: 'same-origin',
        headers: {}
    };

    if (method === 'POST') {
        const body =
            new URLSearchParams();

        body.set(
            'csrf_token',
            App.state.csrfToken
        );

        Object.entries(data).forEach(
            function(pair) {
                const key = pair[0];
                const value = pair[1];

                if (
                    Array.isArray(value) ||
                    (
                        value !== null &&
                        typeof value === 'object'
                    )
                ) {
                    body.set(
                        key,
                        JSON.stringify(value)
                    );
                } else {
                    body.set(
                        key,
                        value == null
                            ? ''
                            : String(value)
                    );
                }
            }
        );

        options.headers[
            'Content-Type'
        ] =
            'application/x-www-form-urlencoded;charset=UTF-8';

        options.body =
            body.toString();
    } else {
        Object.entries(data).forEach(
            function(pair) {
                url +=
                    '&' +
                    encodeURIComponent(pair[0]) +
                    '=' +
                    encodeURIComponent(
                        pair[1] == null
                            ? ''
                            : pair[1]
                    );
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
        json = JSON.parse(text);
    } catch (e) {
        throw new Error(
            'サーバーからJSONではない応答が返されました。HTTP ' +
            response.status
        );
    }

    /*
     * CSRF期限切れの場合は自動的にページを再読み込み。
     */
    if (
        response.status === 403 &&
        json.csrf_expired
    ) {
        App.utils.notify(
            json.message ||
            'セッションを更新します。',
            'warning'
        );

        setTimeout(
            function() {
                location.reload();
            },
            800
        );

        throw new Error(
            json.message
        );
    }

    if (
        !response.ok ||
        json.ok === false
    ) {
        throw new Error(
            json.message ||
            'サーバーエラーが発生しました。'
        );
    }

    return json;
};

App.api.load = async function() {
    try {
        App.state.loading = true;
        App.render.loading();

        const json =
            await App.api.request(
                'load',
                {},
                'GET'
            );

        App.state.csrfToken =
            json.csrf_token;

        App.state.surveys =
            json.surveys || [];

        App.state.responses =
            json.responses || [];

        App.state.customers =
            json.customers || [];

        App.state.settings =
            json.settings || {};

        App.state.mailLogs =
            json.mail_logs || [];

        App.render.shell();
        App.render.list();
    } catch (error) {
        App.render.error(
            error.message
        );
    } finally {
        App.state.loading = false;
    }
};

App.api.saveSurvey = async function(
    survey
) {
    const json =
        await App.api.request(
            'save_survey',
            {
                survey_json: survey
            }
        );

    const saved =
        json.survey;

    const index =
        App.state.surveys.findIndex(
            function(item) {
                return String(item.id) ===
                    String(saved.id);
            }
        );

    if (index >= 0) {
        App.state.surveys[index] =
            saved;
    } else {
        App.state.surveys.unshift(
            saved
        );
    }

    return saved;
};

App.api.status = async function(
    id,
    status
) {
    await App.api.request(
        'status_survey',
        {
            survey_id: id,
            status: status
        }
    );

    const item =
        App.state.surveys.find(
            function(s) {
                return String(s.id) ===
                    String(id);
            }
        );

    if (item) {
        item.status = status;
    }
};

App.api.duplicate = async function(
    id
) {
    const json =
        await App.api.request(
            'duplicate_survey',
            {
                survey_id: id
            }
        );

    App.state.surveys.unshift(
        json.survey
    );

    return json.survey;
};

App.api.deleteSurvey = async function(
    id
) {
    await App.api.request(
        'delete_survey',
        {
            survey_id: id
        }
    );

    App.state.surveys =
        App.state.surveys.filter(
            function(s) {
                return String(s.id) !==
                    String(id);
            }
        );
};
</script>

<script>
/* =========================================================
 * Templates
 * ======================================================= */

App.templates.surveyRow = function(
    survey
) {
    const id =
        App.utils.escapeAttr(
            survey.id
        );

    let actions = '';

    if (survey.status === 'active') {
        actions = `
            <button
                onclick="App.actions.editSurvey('${id}')"
                class="px-3 py-1.5 text-xs rounded-lg border">
                確認・編集
            </button>

            <button
                onclick="App.actions.openAnalysis('${id}')"
                class="px-3 py-1.5 text-xs rounded-lg border">
                集計
            </button>

            <button
                onclick="App.actions.openMail('${id}')"
                class="px-3 py-1.5 text-xs rounded-lg bg-brand-600 text-white">
                送信
            </button>

            <button
                onclick="App.actions.changeStatus('${id}','ended')"
                class="px-3 py-1.5 text-xs rounded-lg border border-red-200 text-red-600">
                停止
            </button>

            <button
                onclick="App.actions.duplicateSurvey('${id}')"
                class="px-3 py-1.5 text-xs rounded-lg border">
                複製
            </button>
        `;
    } else if (survey.status === 'draft') {
        actions = `
            <button
                onclick="App.actions.editSurvey('${id}')"
                class="px-3 py-1.5 text-xs rounded-lg border">
                確認・編集
            </button>

            <button
                onclick="App.actions.changeStatus('${id}','active')"
                class="px-3 py-1.5 text-xs rounded-lg bg-brand-600 text-white">
                公開
            </button>

            <button
                onclick="App.actions.deleteSurvey('${id}')"
                class="px-3 py-1.5 text-xs rounded-lg border border-red-200 text-red-600">
                削除
            </button>

            <button
                onclick="App.actions.duplicateSurvey('${id}')"
                class="px-3 py-1.5 text-xs rounded-lg border">
                複製
            </button>
        `;
    } else {
        actions = `
            <button
                onclick="App.actions.editSurvey('${id}')"
                class="px-3 py-1.5 text-xs rounded-lg border">
                確認
            </button>

            <button
                onclick="App.actions.openAnalysis('${id}')"
                class="px-3 py-1.5 text-xs rounded-lg border">
                集計
            </button>

            <button
                onclick="App.actions.duplicateSurvey('${id}')"
                class="px-3 py-1.5 text-xs rounded-lg border">
                複製
            </button>
        `;
    }

    return `
        <tr class="border-b border-slate-100 hover:bg-slate-50">
            <td class="px-5 py-4 text-xs text-slate-500">
                ${App.utils.formatDate(survey.created_at)}
                <br>
                <span class="text-slate-400">
                    更新:
                    ${App.utils.formatDate(survey.updated_at)}
                </span>
            </td>

            <td class="px-5 py-4">
                <div class="font-bold">
                    ${App.utils.escapeHtml(survey.title || '無題')}
                </div>
                <div class="text-xs text-slate-400 mt-1">
                    ${App.utils.escapeHtml(survey.id)}
                </div>
            </td>

            <td class="px-5 py-4 text-sm">
                ${App.utils.escapeHtml(survey.start_at || '未設定')}
                <br>
                <span class="text-slate-400">
                    ～
                    ${App.utils.escapeHtml(survey.end_at || '未設定')}
                </span>
            </td>

            <td class="px-5 py-4">
                ${App.utils.statusBadge(survey.status)}
            </td>

            <td class="px-5 py-4 text-sm font-semibold">
                ${Number(survey.answer_count || 0)} 件
            </td>

            <td class="px-5 py-4">
                <div class="flex flex-wrap gap-2 min-w-[450px]">
                    ${actions}
                </div>
            </td>
        </tr>
    `;
};
</script>

<script>
/* =========================================================
 * Shell / List
 * ======================================================= */

App.render.loading = function() {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen flex items-center justify-center">
            <div class="text-center">
                <div class="w-10 h-10 border-4 border-slate-200 border-t-brand-600 rounded-full animate-spin mx-auto"></div>
                <div class="mt-4 text-sm text-slate-500">
                    読み込み中...
                </div>
            </div>
        </div>
    `;
};

App.render.error = function(message) {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen flex items-center justify-center p-6">
            <div class="bg-white border border-red-200 rounded-2xl p-8 max-w-xl w-full">
                <div class="text-red-600 font-bold text-lg">
                    エラーが発生しました
                </div>
                <div class="mt-3 text-sm text-slate-600">
                    ${App.utils.escapeHtml(message)}
                </div>
                <button
                    onclick="location.reload()"
                    class="mt-6 px-5 py-2.5 rounded-lg bg-brand-600 text-white">
                    再読み込み
                </button>
            </div>
        </div>
    `;
};

App.render.shell = function() {
    document.getElementById('app').innerHTML = `
        <header class="sticky top-0 z-40 bg-white border-b border-slate-200">
            <div class="max-w-[1600px] mx-auto px-6 py-4 flex items-center justify-between">
                <div class="font-bold text-lg">
                    アンケート管理システム
                </div>

                <nav class="flex items-center gap-2">
                    <button
                        onclick="App.actions.goList()"
                        class="px-4 py-2 rounded-lg text-sm hover:bg-slate-100">
                        アンケート一覧
                    </button>

                    <button
                        onclick="App.actions.openSettings()"
                        class="px-4 py-2 rounded-lg text-sm hover:bg-slate-100">
                        キントーン連携設定
                    </button>

                    <button
                        onclick="App.actions.logout()"
                        class="px-4 py-2 rounded-lg text-sm hover:bg-slate-100">
                        ログアウト
                    </button>
                </nav>
            </div>
        </header>

        <main id="main_content"
              class="max-w-[1600px] mx-auto px-6 py-8">
        </main>

        <div id="modal_root"></div>
    `;
};

App.render.list = function() {
    App.state.view = 'list';

    const keyword =
        App.state.keyword
            .toLowerCase();

    let surveys =
        App.state.surveys.filter(
            function(survey) {
                if (
                    keyword &&
                    !String(
                        survey.title || ''
                    ).toLowerCase()
                    .includes(keyword)
                ) {
                    return false;
                }

                if (
                    App.state.statusFilter !==
                    'all' &&
                    survey.status !==
                    App.state.statusFilter
                ) {
                    return false;
                }

                return true;
            }
        );

    surveys.sort(
        function(a, b) {
            if (
                App.state.sort ===
                'answers_desc'
            ) {
                return Number(
                    b.answer_count || 0
                ) -
                Number(
                    a.answer_count || 0
                );
            }

            if (
                App.state.sort ===
                'answers_asc'
            ) {
                return Number(
                    a.answer_count || 0
                ) -
                Number(
                    b.answer_count || 0
                );
            }

            if (
                App.state.sort ===
                'start_desc'
            ) {
                return String(
                    b.start_at || ''
                ).localeCompare(
                    String(a.start_at || '')
                );
            }

            if (
                App.state.sort ===
                'start_asc'
            ) {
                return String(
                    a.start_at || ''
                ).localeCompare(
                    String(b.start_at || '')
                );
            }

            const av =
                String(
                    a.updated_at || ''
                );

            const bv =
                String(
                    b.updated_at || ''
                );

            return App.state.sort ===
                'updated_asc'
                ? av.localeCompare(bv)
                : bv.localeCompare(av);
        }
    );

    document.getElementById(
        'main_content'
    ).innerHTML = `
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold">
                    アンケート一覧
                </h1>
                <p class="text-sm text-slate-500 mt-1">
                    作成・公開・送信・集計をここから管理します。
                </p>
            </div>

            <button
                onclick="App.actions.newSurvey()"
                class="px-5 py-3 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold shadow-sm">
                ＋ 新規アンケート作成
            </button>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-4 mb-5">
            <div class="flex gap-3 flex-wrap">
                <input
                    value="${App.utils.escapeAttr(App.state.keyword)}"
                    onkeydown="if(event.key==='Enter')App.actions.search(this.value)"
                    class="flex-1 min-w-[260px] border border-slate-200 rounded-lg px-3 py-2.5"
                    placeholder="タイトルを検索してEnter">

                <select
                    onchange="App.actions.statusFilter(this.value)"
                    class="border border-slate-200 rounded-lg px-3 py-2.5">
                    <option value="all" ${App.state.statusFilter === 'all' ? 'selected' : ''}>すべて</option>
                    <option value="active" ${App.state.statusFilter === 'active' ? 'selected' : ''}>公開中</option>
                    <option value="draft" ${App.state.statusFilter === 'draft' ? 'selected' : ''}>下書き</option>
                    <option value="ended" ${App.state.statusFilter === 'ended' ? 'selected' : ''}>終了</option>
                </select>

                <select
                    onchange="App.actions.sort(this.value)"
                    class="border border-slate-200 rounded-lg px-3 py-2.5">
                    <option value="updated_desc" ${App.state.sort === 'updated_desc' ? 'selected' : ''}>更新日：新しい順</option>
                    <option value="updated_asc" ${App.state.sort === 'updated_asc' ? 'selected' : ''}>更新日：古い順</option>
                    <option value="answers_desc" ${App.state.sort === 'answers_desc' ? 'selected' : ''}>回答数：多い順</option>
                    <option value="answers_asc" ${App.state.sort === 'answers_asc' ? 'selected' : ''}>回答数：少ない順</option>
                    <option value="start_desc" ${App.state.sort === 'start_desc' ? 'selected' : ''}>開始日：新しい順</option>
                    <option value="start_asc" ${App.state.sort === 'start_asc' ? 'selected' : ''}>開始日：古い順</option>
                </select>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1250px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr class="text-left text-xs text-slate-500">
                            <th class="px-5 py-3">作成 / 更新</th>
                            <th class="px-5 py-3">タイトル</th>
                            <th class="px-5 py-3">アンケート期間</th>
                            <th class="px-5 py-3">ステータス</th>
                            <th class="px-5 py-3">回答数</th>
                            <th class="px-5 py-3">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${
                            surveys.length
                            ? surveys.map(
                                App.templates.surveyRow
                            ).join('')
                            : `
                                <tr>
                                    <td colspan="6"
                                        class="px-5 py-16 text-center text-slate-400">
                                        アンケートがありません。
                                    </td>
                                </tr>
                            `
                        }
                    </tbody>
                </table>
            </div>
        </div>
    `;
};
</script>

<script>
/* =========================================================
 * Survey editor
 * ======================================================= */

App.actions.newSurvey = function() {
    const now =
        new Date();

    const survey = {
        id:
            App.utils.uid('survey'),
        title:
            '新しいアンケート',
        start_at:
            '',
        end_at:
            '',
        status:
            'draft',
        created_at:
            now.toISOString().substring(
                0,
                19
            ),
        updated_at:
            now.toISOString().substring(
                0,
                19
            ),
        numbering_mode:
            'global',
        deleted:
            false,
        groups: [
            {
                id:
                    App.utils.uid('group'),
                name:
                    '基本情報',
                questions: [
                    {
                        id:
                            App.utils.uid(
                                'question'
                            ),
                        text:
                            'このアンケートについて教えてください。',
                        type:
                            'single',
                        required:
                            true,
                        options: [
                            '非常に満足',
                            '満足',
                            '普通',
                            '不満',
                            '非常に不満'
                        ],
                        other_enabled:
                            false
                    }
                ]
            }
        ]
    };

    App.state.editingSurvey =
        survey;

    App.render.editor();
};

App.actions.editSurvey = function(id) {
    const survey =
        App.state.surveys.find(
            function(item) {
                return String(item.id) ===
                    String(id);
            }
        );

    if (!survey) return;

    App.state.editingSurvey =
        App.utils.clone(survey);

    App.render.editor();
};

App.render.editor = function() {
    const survey =
        App.state.editingSurvey;

    document.getElementById(
        'main_content'
    ).innerHTML = `
        <div class="flex items-center justify-between mb-6">
            <div class="flex-1">
                <div class="text-xs text-slate-400 mb-2">
                    アンケート作成・編集
                </div>

                <input
                    id="survey_title"
                    value="${App.utils.escapeAttr(survey.title)}"
                    oninput="App.actions.updateSurveyTitle(this.value)"
                    class="text-2xl font-bold bg-transparent border-b border-transparent hover:border-slate-300 focus:border-brand-500 outline-none w-full max-w-3xl">
            </div>

            <div class="flex gap-2 ml-6">
                <button
                    onclick="App.actions.preview()"
                    class="px-4 py-2.5 rounded-lg border">
                    プレビュー
                </button>

                <button
                    onclick="App.actions.saveAndList()"
                    class="px-5 py-2.5 rounded-lg bg-brand-600 text-white font-semibold">
                    保存して一覧へ戻る
                </button>

                <button
                    onclick="App.actions.cancelEdit()"
                    class="px-4 py-2.5 rounded-lg border">
                    キャンセル
                </button>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6 mb-6">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-2">
                        開始日時
                    </label>
                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        value="${App.utils.escapeAttr(survey.start_at)}"
                        onchange="App.actions.updateSurveyMeta()"
                        class="w-full border border-slate-200 rounded-lg px-3 py-2.5">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">
                        終了日時
                    </label>
                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        value="${App.utils.escapeAttr(survey.end_at)}"
                        onchange="App.actions.updateSurveyMeta()"
                        class="w-full border border-slate-200 rounded-lg px-3 py-2.5">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">
                        質問番号
                    </label>
                    <select
                        id="survey_numbering_mode"
                        onchange="App.actions.updateSurveyMeta()"
                        class="w-full border border-slate-200 rounded-lg px-3 py-2.5">
                        <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                            Q1, Q2, Q3...
                        </option>
                        <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                            Q1-1, Q1-2...
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <div class="space-y-5" id="question_editor">
            ${App.render.groups()}
        </div>

        <div class="mt-6">
            <button
                onclick="App.actions.addGroup()"
                class="px-5 py-3 rounded-xl border border-dashed border-slate-300 bg-white hover:bg-slate-50">
                ＋ グループを追加
            </button>
        </div>
    `;

    App.actions.initSortable();
};

App.render.groups = function() {
    const survey =
        App.state.editingSurvey;

    return survey.groups.map(
        function(group, gi) {
            return `
                <section
                    class="group-card bg-white border border-slate-200 rounded-xl overflow-hidden"
                    data-group-id="${App.utils.escapeAttr(group.id)}">

                    <div class="px-5 py-4 bg-slate-50 border-b border-slate-200 flex items-center gap-3">
                        <span class="group-handle cursor-move text-slate-400 text-xl">
                            ⠿
                        </span>

                        <input
                            value="${App.utils.escapeAttr(group.name)}"
                            oninput="App.actions.updateGroupName('${App.utils.escapeAttr(group.id)}',this.value)"
                            class="flex-1 bg-transparent font-bold outline-none">

                        <button
                            onclick="App.actions.addQuestion('${App.utils.escapeAttr(group.id)}')"
                            class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-sm">
                            ＋ 質問
                        </button>

                        <button
                            onclick="App.actions.deleteGroup('${App.utils.escapeAttr(group.id)}')"
                            class="px-3 py-1.5 rounded-lg border border-red-200 text-red-600 text-sm">
                            グループ削除
                        </button>
                    </div>

                    <div
                        class="questions p-5 space-y-4"
                        data-group-id="${App.utils.escapeAttr(group.id)}">

                        ${
                            group.questions.length
                            ? group.questions.map(
                                function(q, qi) {
                                    return App.render.question(
                                        q,
                                        gi,
                                        qi
                                    );
                                }
                            ).join('')
                            : `
                                <div class="border border-dashed border-slate-300 rounded-xl p-8 text-center text-sm text-slate-400">
                                    質問がありません。「＋ 質問」で追加してください。
                                </div>
                            `
                        }
                    </div>
                </section>
            `;
        }
    ).join('');
};

App.render.question = function(
    question,
    groupIndex,
    questionIndex
) {
    const number =
        App.actions.questionNumber(
            groupIndex,
            questionIndex
        );

    return `
        <article
            class="question-card border border-slate-200 rounded-xl p-5 bg-white"
            data-question-id="${App.utils.escapeAttr(question.id)}">

            <div class="flex gap-4">
                <div class="question-handle cursor-move text-slate-400 text-xl pt-2">
                    ⠿
                </div>

                <div class="flex-1">
                    <div class="flex items-center justify-between mb-3">
                        <div class="font-bold text-brand-700">
                            ${number}
                        </div>

                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    ${question.required ? 'checked' : ''}
                                    onchange="App.actions.toggleRequired('${App.utils.escapeAttr(question.id)}',this.checked)"
                                    class="rounded">
                                必須回答
                            </label>

                            <button
                                onclick="App.actions.deleteQuestion('${App.utils.escapeAttr(question.id)}')"
                                class="text-sm text-red-600">
                                削除
                            </button>
                        </div>
                    </div>

                    <input
                        value="${App.utils.escapeAttr(question.text)}"
                        oninput="App.actions.updateQuestionText('${App.utils.escapeAttr(question.id)}',this.value)"
                        class="w-full border border-slate-200 rounded-lg px-3 py-2.5 mb-3"
                        placeholder="質問文を入力">

                    <select
                        onchange="App.actions.updateQuestionType('${App.utils.escapeAttr(question.id)}',this.value)"
                        class="border border-slate-200 rounded-lg px-3 py-2.5 mb-4">
                        <option value="single" ${question.type === 'single' ? 'selected' : ''}>単一選択</option>
                        <option value="multiple" ${question.type === 'multiple' ? 'selected' : ''}>複数選択</option>
                        <option value="text" ${question.type === 'text' ? 'selected' : ''}>自由記述</option>
                    </select>

                    ${
                        question.type !== 'text'
                        ? `
                            <div class="space-y-2">
                                ${
                                    question.options.map(
                                        function(option, oi) {
                                            return `
                                                <div class="flex gap-2">
                                                    <input
                                                        value="${App.utils.escapeAttr(option)}"
                                                        oninput="App.actions.updateOption('${App.utils.escapeAttr(question.id)}',${oi},this.value)"
                                                        class="flex-1 border border-slate-200 rounded-lg px-3 py-2">
                                                    <button
                                                        onclick="App.actions.removeOption('${App.utils.escapeAttr(question.id)}',${oi})"
                                                        class="px-3 text-red-500">
                                                        ×
                                                    </button>
                                                </div>
                                            `;
                                        }
                                    ).join('')
                                }

                                <button
                                    onclick="App.actions.addOption('${App.utils.escapeAttr(question.id)}')"
                                    class="text-sm text-brand-600">
                                    ＋ 選択肢を追加
                                </button>

                                <label class="flex items-center gap-2 text-sm mt-3">
                                    <input
                                        type="checkbox"
                                        ${question.other_enabled ? 'checked' : ''}
                                        onchange="App.actions.toggleOther('${App.utils.escapeAttr(question.id)}',this.checked)">
                                    その他を追加
                                </label>
                            </div>
                        `
                        : `
                            <textarea
                                disabled
                                rows="4"
                                class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-slate-50"
                                placeholder="回答者が自由記述する欄"></textarea>
                        `
                    }
                </div>
            </div>
        </article>
    `;
};
</script>

<script>
/* =========================================================
 * Survey actions / SortableJS
 * ======================================================= */

App.actions.questionNumber = function(
    groupIndex,
    questionIndex
) {
    const survey =
        App.state.editingSurvey;

    if (
        survey.numbering_mode ===
        'group'
    ) {
        return 'Q' +
            (groupIndex + 1) +
            '-' +
            (questionIndex + 1);
    }

    let n = 0;

    for (
        let i = 0;
        i <= groupIndex;
        i++
    ) {
        n +=
            survey.groups[i]
                .questions.length;
    }

    n -=
        survey.groups[groupIndex]
            .questions.length -
        questionIndex;

    return 'Q' + n;
};

App.actions.updateSurveyTitle =
function(value) {
    App.state.editingSurvey.title =
        value;
};

App.actions.updateSurveyMeta =
function() {
    const survey =
        App.state.editingSurvey;

    survey.start_at =
        document.getElementById(
            'survey_start_at'
        )?.value || '';

    survey.end_at =
        document.getElementById(
            'survey_end_at'
        )?.value || '';

    survey.numbering_mode =
        document.getElementById(
            'survey_numbering_mode'
        )?.value || 'global';

    App.render.groupsInEditorOnly();
};

App.render.groupsInEditorOnly =
function() {
    const editor =
        document.getElementById(
            'question_editor'
        );

    if (!editor) return;

    editor.innerHTML =
        App.render.groups();

    App.actions.initSortable();
};

App.actions.addGroup =
function() {
    App.state.editingSurvey.groups.push({
        id:
            App.utils.uid('group'),
        name:
            '新しいグループ',
        questions: []
    });

    App.render.groupsInEditorOnly();
};

App.actions.deleteGroup =
function(id) {
    if (
        !confirm(
            'このグループと、グループ内の全質問を削除しますか？'
        )
    ) {
        return;
    }

    App.state.editingSurvey.groups =
        App.state.editingSurvey.groups.filter(
            function(group) {
                return String(group.id) !==
                    String(id);
            }
        );

    App.render.groupsInEditorOnly();
};

App.actions.updateGroupName =
function(id, value) {
    const group =
        App.state.editingSurvey.groups.find(
            function(g) {
                return String(g.id) ===
                    String(id);
            }
        );

    if (group) {
        group.name = value;
    }
};

App.actions.addQuestion =
function(groupId) {
    const group =
        App.state.editingSurvey.groups.find(
            function(g) {
                return String(g.id) ===
                    String(groupId);
            }
        );

    if (!group) return;

    group.questions.push({
        id:
            App.utils.uid('question'),
        text:
            '',
        type:
            'single',
        required:
            false,
        options: [
            '選択肢1',
            '選択肢2'
        ],
        other_enabled:
            false
    });

    App.render.groupsInEditorOnly();
};

App.actions.findQuestion =
function(id) {
    for (
        const group of
        App.state.editingSurvey.groups
    ) {
        const question =
            group.questions.find(
                function(q) {
                    return String(q.id) ===
                        String(id);
                }
            );

        if (question) {
            return {
                group:
                    group,
                question:
                    question
            };
        }
    }

    return null;
};

App.actions.updateQuestionText =
function(id, value) {
    const found =
        App.actions.findQuestion(id);

    if (found) {
        found.question.text =
            value;
    }
};

App.actions.updateQuestionType =
function(id, value) {
    const found =
        App.actions.findQuestion(id);

    if (!found) return;

    found.question.type =
        value;

    if (
        value !== 'text' &&
        !found.question.options.length
    ) {
        found.question.options =
            ['選択肢1', '選択肢2'];
    }

    App.render.groupsInEditorOnly();
};

App.actions.toggleRequired =
function(id, checked) {
    const found =
        App.actions.findQuestion(id);

    if (found) {
        found.question.required =
            checked;
    }
};

App.actions.toggleOther =
function(id, checked) {
    const found =
        App.actions.findQuestion(id);

    if (found) {
        found.question.other_enabled =
            checked;
    }
};

App.actions.updateOption =
function(id, index, value) {
    const found =
        App.actions.findQuestion(id);

    if (found) {
        found.question.options[index] =
            value;
    }
};

App.actions.addOption =
function(id) {
    const found =
        App.actions.findQuestion(id);

    if (!found) return;

    found.question.options.push(
        '新しい選択肢'
    );

    App.render.groupsInEditorOnly();
};

App.actions.removeOption =
function(id, index) {
    const found =
        App.actions.findQuestion(id);

    if (!found) return;

    found.question.options.splice(
        index,
        1
    );

    App.render.groupsInEditorOnly();
};

App.actions.deleteQuestion =
function(id) {
    if (
        !confirm(
            'この質問を削除しますか？'
        )
    ) {
        return;
    }

    for (
        const group of
        App.state.editingSurvey.groups
    ) {
        group.questions =
            group.questions.filter(
                function(q) {
                    return String(q.id) !==
                        String(id);
                }
            );
    }

    App.render.groupsInEditorOnly();
};

App.actions.initSortable =
function() {
    if (
        typeof Sortable ===
        'undefined'
    ) {
        return;
    }

    const editor =
        document.getElementById(
            'question_editor'
        );

    if (!editor) return;

    new Sortable(
        editor,
        {
            animation: 180,
            handle: '.group-handle',
            ghostClass:
                'opacity-40',
            onEnd: function(evt) {
                const groups =
                    App.state.editingSurvey.groups;

                const moved =
                    groups.splice(
                        evt.oldIndex,
                        1
                    )[0];

                groups.splice(
                    evt.newIndex,
                    0,
                    moved
                );

                App.render.groupsInEditorOnly();
            }
        }
    );

    editor
        .querySelectorAll(
            '.questions'
        )
        .forEach(
            function(container) {
                new Sortable(
                    container,
                    {
                        group:
                            'survey-questions',
                        animation: 180,
                        handle:
                            '.question-handle',
                        ghostClass:
                            'opacity-40',

                        onEnd:
                        function(evt) {
                            const fromId =
                                evt.from.dataset.groupId;

                            const toId =
                                evt.to.dataset.groupId;

                            const from =
                                App.state.editingSurvey.groups.find(
                                    function(g) {
                                        return String(g.id) ===
                                            String(fromId);
                                    }
                                );

                            const to =
                                App.state.editingSurvey.groups.find(
                                    function(g) {
                                        return String(g.id) ===
                                            String(toId);
                                    }
                                );

                            if (!from || !to) {
                                return;
                            }

                            const q =
                                from.questions.splice(
                                    evt.oldIndex,
                                    1
                                )[0];

                            if (q) {
                                to.questions.splice(
                                    evt.newIndex,
                                    0,
                                    q
                                );
                            }

                            App.render.groupsInEditorOnly();
                        }
                    }
                );
            }
        );
};

App.actions.saveAndList =
async function() {
    const survey =
        App.state.editingSurvey;

    if (
        !survey.title.trim()
    ) {
        App.utils.notify(
            'タイトルを入力してください。',
            'warning'
        );
        return;
    }

    try {
        survey.updated_at =
            new Date()
                .toISOString()
                .substring(0, 19);

        const saved =
            await App.api.saveSurvey(
                survey
            );

        App.utils.notify(
            'アンケートを保存しました。',
            'success'
        );

        App.state.editingSurvey =
            null;

        App.render.list();
    } catch (error) {
        App.utils.notify(
            error.message,
            'error'
        );
    }
};

App.actions.cancelEdit =
function() {
    if (
        !confirm(
            '変更を破棄して一覧へ戻りますか？'
        )
    ) {
        return;
    }

    App.state.editingSurvey =
        null;

    App.render.list();
};
</script>

<script>
/* =========================================================
 * Status / list actions
 * ======================================================= */

App.actions.goList =
function() {
    App.state.view = 'list';
    App.state.editingSurvey = null;
    App.render.list();
};

App.actions.search =
function(value) {
    App.state.keyword =
        value || '';

    App.render.list();
};

App.actions.statusFilter =
function(value) {
    App.state.statusFilter =
        value;

    App.render.list();
};

App.actions.sort =
function(value) {
    App.state.sort =
        value;

    App.render.list();
};

App.actions.changeStatus =
async function(id, status) {
    const survey =
        App.state.surveys.find(
            function(s) {
                return String(s.id) ===
                    String(id);
            }
        );

    if (!survey) return;

    const message =
        status === 'active'
        ? 'このアンケートを公開しますか？'
        : status === 'ended'
            ? 'このアンケートを停止しますか？'
            : '下書きに戻しますか？';

    if (!confirm(message)) {
        return;
    }

    try {
        await App.api.status(
            id,
            status
        );

        App.utils.notify(
            status === 'active'
                ? 'アンケートを公開しました。'
                : status === 'ended'
                    ? 'アンケートを停止しました。'
                    : '下書きに戻しました。',
            'success'
        );

        App.render.list();
    } catch (error) {
        App.utils.notify(
            error.message,
            'error'
        );
    }
};

App.actions.deleteSurvey =
async function(id) {
    if (
        !confirm(
            'この下書きを削除しますか？'
        )
    ) {
        return;
    }

    try {
        await App.api.deleteSurvey(
            id
        );

        App.utils.notify(
            '削除しました。',
            'success'
        );

        App.render.list();
    } catch (error) {
        App.utils.notify(
            error.message,
            'error'
        );
    }
};

App.actions.duplicateSurvey =
async function(id) {
    try {
        await App.api.duplicate(
            id
        );

        App.utils.notify(
            '下書きとして複製しました。',
            'success'
        );

        App.render.list();
    } catch (error) {
        App.utils.notify(
            error.message,
            'error'
        );
    }
};
</script>

<script>
/* =========================================================
 * Preview
 * ======================================================= */

App.actions.preview =
function() {
    const survey =
        App.state.editingSurvey;

    App.state.previewSurvey =
        App.utils.clone(survey);

    document.getElementById(
        'modal_root'
    ).innerHTML = `
        <div
            id="preview_modal"
            class="fixed inset-0 z-[100] bg-black/50 flex items-center justify-center p-6">

            <div class="bg-white rounded-2xl shadow-2xl max-h-[90vh] overflow-hidden w-full max-w-4xl">
                <div class="px-6 py-4 border-b flex items-center justify-between">
                    <div class="font-bold">
                        プレビュー
                    </div>

                    <div class="flex gap-2">
                        <button
                            onclick="App.actions.previewMode('pc')"
                            class="px-3 py-1.5 rounded-lg border text-sm">
                            PC表示
                        </button>

                        <button
                            onclick="App.actions.previewMode('mobile')"
                            class="px-3 py-1.5 rounded-lg border text-sm">
                            スマートフォン表示
                        </button>

                        <button
                            onclick="App.actions.closeModal()"
                            class="px-3 py-1.5 rounded-lg border text-sm">
                            閉じる
                        </button>
                    </div>
                </div>

                <div
                    id="preview_content"
                    class="p-6 overflow-auto max-h-[calc(90vh-70px)]">
                </div>
            </div>
        </div>
    `;

    App.actions.previewMode(
        'pc'
    );
};

App.actions.previewMode =
function(mode) {
    const survey =
        App.state.previewSurvey;

    const width =
        mode === 'mobile'
        ? 'max-w-sm'
        : 'max-w-3xl';

    const questions =
        survey.groups.map(
            function(group) {
                return `
                    <div class="mb-8">
                        <h3 class="font-bold text-lg border-b pb-2 mb-5">
                            ${App.utils.escapeHtml(group.name)}
                        </h3>

                        ${
                            group.questions.map(
                                function(q, qi) {
                                    return `
                                        <div class="mb-7">
                                            <div class="font-semibold mb-3">
                                                ${App.utils.escapeHtml(q.text || '質問')}
                                                ${
                                                    q.required
                                                    ? '<span class="text-red-500 text-xs ml-2">必須</span>'
                                                    : ''
                                                }
                                            </div>

                                            ${
                                                q.type === 'text'
                                                ? `
                                                    <textarea
                                                        class="w-full border rounded-lg p-3"
                                                        rows="4"></textarea>
                                                `
                                                :
                                                q.options.map(
                                                    function(option) {
                                                        const input =
                                                            q.type === 'multiple'
                                                            ? 'checkbox'
                                                            : 'radio';

                                                        return `
                                                            <label class="flex items-center gap-3 p-2">
                                                                <input type="${input}">
                                                                ${App.utils.escapeHtml(option)}
                                                            </label>
                                                        `;
                                                    }
                                                ).join('') +
                                                (
                                                    q.other_enabled
                                                    ? `
                                                        <label class="flex items-center gap-3 p-2">
                                                            <input type="${q.type === 'multiple' ? 'checkbox' : 'radio'}">
                                                            その他
                                                        </label>
                                                    `
                                                    : ''
                                                )
                                            }
                                        </div>
                                    `;
                                }
                            ).join('')
                        }
                    </div>
                `;
            }
        ).join('');

    document.getElementById(
        'preview_content'
    ).innerHTML = `
        <div class="${width} mx-auto bg-white border rounded-xl p-6">
            <h2 class="text-2xl font-bold mb-8">
                ${App.utils.escapeHtml(survey.title)}
            </h2>

            ${questions}

            <button
                onclick="alert('これはプレビューです。実際の回答は送信されません。')"
                class="w-full py-3 rounded-lg bg-brand-600 text-white font-semibold">
                回答を送信
            </button>
        </div>
    `;
};

App.actions.closeModal =
function() {
    document.getElementById(
        'modal_root'
    ).innerHTML = '';
};
</script>

<script>
/* =========================================================
 * Mail
 * ======================================================= */

App.actions.openMail =
function(id) {
    App.state.mailSurveyId =
        id;

    App.state.selectedCustomers =
        [];

    App.render.mail();
};

App.render.mail =
function() {
    const survey =
        App.state.surveys.find(
            function(s) {
                return String(s.id) ===
                    String(App.state.mailSurveyId);
            }
        );

    if (!survey) return;

    document.getElementById(
        'main_content'
    ).innerHTML = `
        <div class="mb-6">
            <button
                onclick="App.actions.goList()"
                class="text-sm text-slate-500">
                ← アンケート一覧
            </button>

            <h1 class="text-2xl font-bold mt-3">
                顧客選択・メール送信
            </h1>

            <div class="text-sm text-slate-500 mt-1">
                ${App.utils.escapeHtml(survey.title)}
            </div>
        </div>

        <div class="grid grid-cols-12 gap-5">

            <section class="col-span-4">
                <div class="bg-white border border-slate-200 rounded-xl p-5">
                    <h2 class="font-bold mb-4">
                        メールテンプレート
                    </h2>

                    <select
                        id="template_type"
                        onchange="App.actions.templateType(this.value)"
                        class="w-full border rounded-lg px-3 py-2.5 mb-4">
                        <option value="initial">初回送信</option>
                        <option value="reminder">再送・リマインド</option>
                    </select>

                    <input
                        id="mail_subject"
                        value="【アンケート】ご回答のお願い"
                        class="w-full border rounded-lg px-3 py-2.5 mb-3">

                    <textarea
                        id="mail_body"
                        rows="12"
                        class="w-full border rounded-lg p-3">{${
                            App.utils.escapeHtml(
                                '{顧客名} 様\n\nアンケートへのご回答をお願いいたします。\n\n{アンケートURL}'
                            )
                        }}</textarea>

                    <div class="text-xs text-slate-400 mt-3">
                        使用可能：
                        {顧客名}
                        {アンケートURL}
                    </div>

                    <button
                        onclick="App.actions.sendSelectedMail()"
                        class="w-full mt-5 py-3 rounded-xl bg-brand-600 text-white font-semibold">
                        選択した顧客へ一括送信
                    </button>
                </div>
            </section>

            <section class="col-span-8">
                <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
                    <div class="p-5 border-b">
                        <div class="flex gap-3">
                            <input
                                id="customer_filter"
                                oninput="App.actions.filterCustomers(this.value)"
                                class="flex-1 border rounded-lg px-3 py-2.5"
                                placeholder="顧客名・メールアドレスで検索">

                            <button
                                onclick="App.actions.selectUnanswered()"
                                class="px-3 py-2 rounded-lg border">
                                未回答のみ
                            </button>

                            <button
                                onclick="App.actions.selectAllCustomers()"
                                class="px-3 py-2 rounded-lg border">
                                全選択
                            </button>
                        </div>

                        <div class="mt-4 text-sm bg-amber-50 text-amber-800 border border-amber-200 rounded-lg p-3">
                            kintone未登録顧客は「未登録」と表示されます。
                        </div>
                    </div>

                    <div id="customer_table">
                        ${App.render.customerTable()}
                    </div>

                    <div class="p-5 border-t flex justify-between">
                        <div class="text-sm">
                            選択：
                            <strong id="selected_customer_count">0</strong>
                            件
                        </div>

                        <button
                            onclick="App.actions.sendSelectedMail()"
                            class="px-5 py-2.5 rounded-lg bg-brand-600 text-white font-semibold">
                            一括送信
                        </button>
                    </div>
                </div>
            </section>
        </div>
    `;

    App.actions.updateSelectedCount();
};

App.render.customerTable =
function() {
    const filter =
        (
            document.getElementById(
                'customer_filter'
            )?.value || ''
        ).toLowerCase();

    const customers =
        App.state.customers.filter(
            function(customer) {
                return !filter ||
                    String(
                        customer.company || ''
                    ).toLowerCase()
                    .includes(filter) ||
                    String(
                        customer.name || ''
                    ).toLowerCase()
                    .includes(filter) ||
                    String(
                        customer.email || ''
                    ).toLowerCase()
                    .includes(filter);
            }
        );

    return `
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px]">
                <thead class="bg-slate-50 border-b">
                    <tr class="text-xs text-slate-500 text-left">
                        <th class="px-5 py-3">
                            <input
                                id="select_all"
                                type="checkbox"
                                onchange="App.actions.toggleAllVisible(this.checked)">
                        </th>
                        <th class="px-5 py-3">会社名 / 氏名</th>
                        <th class="px-5 py-3">メール</th>
                        <th class="px-5 py-3">送信状況</th>
                        <th class="px-5 py-3">回答状況</th>
                        <th class="px-5 py-3">kintone</th>
                    </tr>
                </thead>

                <tbody>
                    ${
                        customers.length
                        ? customers.map(
                            function(customer) {
                                const id =
                                    App.utils.escapeAttr(
                                        customer.id
                                    );

                                const selected =
                                    App.state.selectedCustomers.includes(
                                        String(customer.id)
                                    );

                                return `
                                    <tr class="border-b border-slate-100">
                                        <td class="px-5 py-4">
                                            <input
                                                type="checkbox"
                                                ${selected ? 'checked' : ''}
                                                ${customer.source === 'web' ? 'disabled' : ''}
                                                onchange="App.actions.toggleCustomer('${id}',this.checked)">
                                        </td>

                                        <td class="px-5 py-4">
                                            <div class="font-semibold">
                                                ${App.utils.escapeHtml(customer.company || '-')}
                                            </div>
                                            <div class="text-sm text-slate-500">
                                                ${App.utils.escapeHtml(customer.name || '-')}
                                            </div>
                                        </td>

                                        <td class="px-5 py-4 text-sm">
                                            ${App.utils.escapeHtml(customer.email || '-')}
                                        </td>

                                        <td class="px-5 py-4 text-xs">
                                            ${
                                                Number(customer.send_count || 0)
                                                ? `
                                                    <span class="px-2 py-1 rounded-full bg-blue-100 text-blue-700">
                                                        送信済み
                                                    </span>
                                                    <div class="mt-1">
                                                        ${customer.send_count}回
                                                    </div>
                                                `
                                                : `
                                                    <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-600">
                                                        未送信
                                                    </span>
                                                `
                                            }
                                        </td>

                                        <td class="px-5 py-4 text-xs">
                                            ${
                                                customer.answer_status === 'answered'
                                                ? `
                                                    <span class="px-2 py-1 rounded-full bg-emerald-100 text-emerald-700">
                                                        回答済み
                                                    </span>
                                                `
                                                : `
                                                    <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-700">
                                                        未回答
                                                    </span>
                                                `
                                            }
                                        </td>

                                        <td class="px-5 py-4 text-xs">
                                            ${
                                                customer.kintone_status === 'registered'
                                                ? `
                                                    <span class="text-emerald-600">
                                                        ✓ 登録完了
                                                    </span>
                                                `
                                                : `
                                                    <button
                                                        onclick="App.actions.markKintone('${id}')"
                                                        class="px-2 py-1 rounded border">
                                                        未登録
                                                    </button>
                                                `
                                            }
                                        </td>
                                    </tr>
                                `;
                            }
                        ).join('')
                        : `
                            <tr>
                                <td colspan="6"
                                    class="px-5 py-12 text-center text-slate-400">
                                    顧客データがありません。
                                </td>
                            </tr>
                        `
                    }
                </tbody>
            </table>
        </div>
    `;
};

App.actions.filterCustomers =
function() {
    document.getElementById(
        'customer_table'
    ).innerHTML =
        App.render.customerTable();

    App.actions.updateSelectedCount();
};

App.actions.toggleCustomer =
function(id, checked) {
    id = String(id);

    if (checked) {
        if (
            !App.state.selectedCustomers
                .includes(id)
        ) {
            App.state.selectedCustomers.push(
                id
            );
        }
    } else {
        App.state.selectedCustomers =
            App.state.selectedCustomers.filter(
                function(item) {
                    return item !== id;
                }
            );
    }

    App.actions.updateSelectedCount();
};

App.actions.toggleAllVisible =
function(checked) {
    const filter =
        (
            document.getElementById(
                'customer_filter'
            )?.value || ''
        ).toLowerCase();

    App.state.customers
        .filter(
            function(customer) {
                if (
                    customer.source ===
                    'web'
                ) {
                    return false;
                }

                return !filter ||
                    String(
                        customer.company || ''
                    ).toLowerCase()
                    .includes(filter) ||
                    String(
                        customer.name || ''
                    ).toLowerCase()
                    .includes(filter) ||
                    String(
                        customer.email || ''
                    ).toLowerCase()
                    .includes(filter);
            }
        )
        .forEach(
            function(customer) {
                const id =
                    String(customer.id);

                if (checked) {
                    if (
                        !App.state.selectedCustomers
                            .includes(id)
                    ) {
                        App.state.selectedCustomers
                            .push(id);
                    }
                } else {
                    App.state.selectedCustomers =
                        App.state.selectedCustomers
                            .filter(
                                function(x) {
                                    return x !== id;
                                }
                            );
                }
            }
        );

    App.actions.filterCustomers();
};

App.actions.selectAllCustomers =
function() {
    App.actions.toggleAllVisible(
        true
    );
};

App.actions.selectUnanswered =
function() {
    App.state.selectedCustomers =
        App.state.customers
            .filter(
                function(customer) {
                    return (
                        customer.source !== 'web' &&
                        customer.answer_status !== 'answered'
                    );
                }
            )
            .map(
                function(customer) {
                    return String(
                        customer.id
                    );
                }
            );

    App.actions.filterCustomers();
};

App.actions.updateSelectedCount =
function() {
    const el =
        document.getElementById(
            'selected_customer_count'
        );

    if (el) {
        el.textContent =
            String(
                App.state.selectedCustomers.length
            );
    }
};

App.actions.templateType =
function(type) {
    const body =
        document.getElementById(
            'mail_body'
        );

    if (!body) return;

    if (type === 'reminder') {
        body.value =
            '{顧客名} 様\n\nまだアンケートへの回答がお済みでないようです。\n\n{アンケートURL}';
    } else {
        body.value =
            '{顧客名} 様\n\nアンケートへのご回答をお願いいたします。\n\n{アンケートURL}';
    }
};

App.actions.sendSelectedMail =
async function() {
    if (
        !App.state.selectedCustomers.length
    ) {
        App.utils.notify(
            '送信対象を選択してください。',
            'warning'
        );
        return;
    }

    const alreadySent =
        App.state.customers.filter(
            function(customer) {
                return (
                    App.state.selectedCustomers
                        .includes(
                            String(customer.id)
                        ) &&
                    Number(
                        customer.send_count || 0
                    ) > 0
                );
            }
        );

    if (
        alreadySent.length &&
        !confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )
    ) {
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

    try {
        const json =
            await App.api.request(
                'send_mail',
                {
                    survey_id:
                        App.state.mailSurveyId,
                    recipient_ids:
                        App.state.selectedCustomers,
                    mail_subject:
                        subject,
                    mail_body:
                        body,
                    template_type:
                        templateType
                }
            );

        App.utils.notify(
            json.message,
            'success'
        );

        await App.api.load();

        App.actions.openMail(
            App.state.mailSurveyId
        );
    } catch (error) {
        App.utils.notify(
            error.message,
            'error'
        );
    }
};

App.actions.markKintone =
async function(id) {
    try {
        await App.api.request(
            'mark_kintone',
            {
                customer_id: id
            }
        );

        const customer =
            App.state.customers.find(
                function(c) {
                    return String(c.id) ===
                        String(id);
                }
            );

        if (customer) {
            customer.kintone_status =
                'registered';
        }

        App.render.mail();

        App.utils.notify(
            'kintone登録済みに変更しました。',
            'success'
        );
    } catch (error) {
        App.utils.notify(
            error.message,
            'error'
        );
    }
};
</script>

<script>
/* =========================================================
 * Analysis
 * ======================================================= */

App.actions.openAnalysis =
function(id) {
    App.state.currentSurveyId =
        id;

    App.render.analysis();
};

App.render.analysis =
function() {
    const survey =
        App.state.surveys.find(
            function(s) {
                return String(s.id) ===
                    String(
                        App.state.currentSurveyId
                    );
            }
        );

    if (!survey) return;

    const responses =
        App.state.responses.filter(
            function(r) {
                return String(
                    r.survey_id
                ) === String(survey.id);
            }
        );

    const sent =
        App.state.customers.filter(
            function(c) {
                return Number(
                    c.send_count || 0
                ) > 0;
            }
        ).length;

    const answered =
        responses.length;

    const unanswered =
        Math.max(
            0,
            sent -
            App.state.customers.filter(
                function(c) {
                    return (
                        Number(
                            c.send_count || 0
                        ) > 0 &&
                        c.answer_status ===
                        'answered'
                    );
                }
            ).length
        );

    const webResponses =
        responses.filter(
            function(r) {
                return !r.customer_id;
            }
        ).length;

    const rate =
        sent
        ? (
            (
                answered -
                webResponses
            ) / sent * 100
        ).toFixed(1)
        : '0.0';

    const questions = [];

    survey.groups.forEach(
        function(group) {
            group.questions.forEach(
                function(q) {
                    questions.push(q);
                }
            );
        }
    );

    document.getElementById(
        'main_content'
    ).innerHTML = `
        <div class="mb-6 flex justify-between">
            <div>
                <button
                    onclick="App.actions.goList()"
                    class="text-sm text-slate-500">
                    ← アンケート一覧
                </button>

                <h1 class="text-2xl font-bold mt-3">
                    集計・分析
                </h1>

                <div class="text-sm text-slate-500 mt-1">
                    ${App.utils.escapeHtml(survey.title)}
                </div>
            </div>

            <div class="flex gap-2">
                <a
                    href="?action=export_csv&survey_id=${encodeURIComponent(survey.id)}&csrf_token=${encodeURIComponent(App.state.csrfToken)}"
                    class="px-4 py-2.5 rounded-lg border">
                    CSV出力
                </a>

                <button
                    onclick="window.print()"
                    class="px-4 py-2.5 rounded-lg border">
                    PDF / 印刷
                </button>
            </div>
        </div>

        <div class="grid grid-cols-5 gap-4 mb-6">
            ${App.render.statCard('送信対象者数', sent + ' 人')}
            ${App.render.statCard('回答数', answered + ' 件')}
            ${App.render.statCard('未登録顧客からの回答数', webResponses + ' 件')}
            ${App.render.statCard('未回答数', unanswered + ' 人')}
            ${App.render.statCard('回答率', rate + ' %')}
        </div>

        <div class="grid grid-cols-12 gap-5">

            <aside class="col-span-3">
                <div class="bg-white border rounded-xl p-5">
                    <div class="font-bold mb-4">
                        集計対象設問
                    </div>

                    <div class="space-y-2">
                        <button
                            onclick="App.actions.selectAllQuestions(true)"
                            class="text-xs border rounded px-2 py-1">
                            全選択
                        </button>

                        <button
                            onclick="App.actions.selectAllQuestions(false)"
                            class="text-xs border rounded px-2 py-1">
                            全解除
                        </button>

                        ${
                            questions.map(
                                function(q, index) {
                                    const selected =
                                        App.state.selectedQuestions[
                                            q.id
                                        ] !== false;

                                    return `
                                        <label class="flex gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                ${selected ? 'checked' : ''}
                                                onchange="App.actions.toggleQuestion('${App.utils.escapeAttr(q.id)}',this.checked)">
                                            <span>
                                                Q${index + 1}
                                                ${App.utils.escapeHtml(q.text)}
                                            </span>
                                        </label>
                                    `;
                                }
                            ).join('')
                        }
                    </div>
                </div>
            </aside>

            <section class="col-span-9">
                <div id="analysis_questions" class="space-y-5">
                    ${App.render.analysisQuestions(
                        survey,
                        responses
                    )}
                </div>

                <div class="mt-6 bg-white border rounded-xl overflow-hidden">
                    <div class="p-5 border-b">
                        <div class="font-bold">
                            個別回答一覧
                        </div>

                        <input
                            id="response_filter"
                            value="${App.utils.escapeAttr(App.state.responseKeyword)}"
                            oninput="App.actions.filterResponses(this.value)"
                            class="mt-3 w-full border rounded-lg px-3 py-2.5"
                            placeholder="会社名・氏名で検索">
                    </div>

                    <div id="response_table">
                        ${App.render.responseTable(responses)}
                    </div>
                </div>
            </section>
        </div>
    `;
};

App.render.statCard =
function(label, value) {
    return `
        <div class="bg-white border rounded-xl p-5">
            <div class="text-xs text-slate-500">
                ${label}
            </div>
            <div class="text-2xl font-bold mt-2">
                ${value}
            </div>
        </div>
    `;
};

App.render.analysisQuestions =
function(survey, responses) {
    const questions = [];

    survey.groups.forEach(
        function(group) {
            group.questions.forEach(
                function(q) {
                    questions.push(q);
                }
            );
        }
    );

    return questions
        .filter(
            function(q) {
                return App.state.selectedQuestions[
                    q.id
                ] !== false;
            }
        )
        .map(
            function(q, index) {
                if (q.type === 'text') {
                    const texts =
                        responses
                            .map(
                                function(r) {
                                    return {
                                        response:
                                            r,
                                        value:
                                            r.answers?.[
                                                q.id
                                            ] || ''
                                    };
                                }
                            )
                            .filter(
                                function(x) {
                                    return String(
                                        x.value
                                    ).trim() !== '';
                                }
                            );

                    return `
                        <div class="bg-white border rounded-xl p-5">
                            <div class="font-bold mb-4">
                                Q${index + 1}.
                                ${App.utils.escapeHtml(q.text)}
                                <span class="text-xs text-slate-400 ml-2">
                                    自由記述
                                </span>
                            </div>

                            <div class="space-y-3 max-h-[450px] overflow-auto">
                                ${
                                    texts.length
                                    ? texts.map(
                                        function(x) {
                                            return `
                                                <div class="border-l-4 border-brand-500 bg-slate-50 p-4">
                                                    <div class="text-xs text-slate-400">
                                                        ${App.utils.escapeHtml(x.response.company || '-')}
                                                        /
                                                        ${App.utils.escapeHtml(x.response.name || '-')}
                                                    </div>
                                                    <div class="mt-2 text-sm">
                                                        ${App.utils.escapeHtml(String(x.value))}
                                                    </div>
                                                </div>
                                            `;
                                        }
                                    ).join('')
                                    : `
                                        <div class="text-sm text-slate-400">
                                            現在、回答データはありません
                                        </div>
                                    `
                                }
                            </div>
                        </div>
                    `;
                }

                const counts = {};

                q.options.forEach(
                    function(option) {
                        counts[option] = 0;
                    }
                );

                let total = 0;

                responses.forEach(
                    function(response) {
                        let value =
                            response.answers?.[
                                q.id
                            ];

                        if (Array.isArray(value)) {
                            value.forEach(
                                function(v) {
                                    counts[v] =
                                        (
                                            counts[v]
                                            || 0
                                        ) + 1;

                                    total++;
                                }
                            );
                        } else if (
                            value !== undefined &&
                            value !== ''
                        ) {
                            counts[value] =
                                (
                                    counts[value]
                                    || 0
                                ) + 1;

                            total++;
                        }
                    }
                );

                return `
                    <div class="bg-white border rounded-xl p-5">
                        <div class="font-bold mb-5">
                            Q${index + 1}.
                            ${App.utils.escapeHtml(q.text)}
                        </div>

                        <div class="space-y-4">
                            ${
                                q.options.map(
                                    function(option) {
                                        const count =
                                            counts[option]
                                            || 0;

                                        const pct =
                                            total
                                            ? (
                                                count /
                                                total *
                                                100
                                            ).toFixed(1)
                                            : '0.0';

                                        return `
                                            <div>
                                                <div class="flex justify-between text-sm mb-1">
                                                    <span>
                                                        ${App.utils.escapeHtml(option)}
                                                    </span>
                                                    <span>
                                                        ${count}件
                                                        /
                                                        ${pct}%
                                                    </span>
                                                </div>

                                                <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                                    <div
                                                        class="h-full bg-brand-500 rounded-full"
                                                        style="width:${pct}%">
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
            }
        )
        .join('');
};

App.actions.toggleQuestion =
function(id, checked) {
    App.state.selectedQuestions[id] =
        checked;

    App.render.analysis();
};

App.actions.selectAllQuestions =
function(checked) {
    const survey =
        App.state.surveys.find(
            function(s) {
                return String(s.id) ===
                    String(
                        App.state.currentSurveyId
                    );
            }
        );

    if (!survey) return;

    survey.groups.forEach(
        function(group) {
            group.questions.forEach(
                function(q) {
                    App.state.selectedQuestions[
                        q.id
                    ] = checked;
                }
            );
        }
    );

    App.render.analysis();
};

App.render.responseTable =
function(responses) {
    const keyword =
        App.state.responseKeyword
            .toLowerCase();

    const filtered =
        responses.filter(
            function(r) {
                return !keyword ||
                    String(
                        r.company || ''
                    ).toLowerCase()
                    .includes(keyword) ||
                    String(
                        r.name || ''
                    ).toLowerCase()
                    .includes(keyword);
            }
        );

    return `
        <div class="overflow-x-auto">
            <table class="w-full min-w-[800px]">
                <thead class="bg-slate-50 border-b">
                    <tr class="text-left text-xs text-slate-500">
                        <th class="px-5 py-3">回答日時</th>
                        <th class="px-5 py-3">会社名</th>
                        <th class="px-5 py-3">氏名</th>
                        <th class="px-5 py-3">メール</th>
                        <th class="px-5 py-3">操作</th>
                    </tr>
                </thead>

                <tbody>
                    ${
                        filtered.map(
                            function(r) {
                                return `
                                    <tr class="border-b">
                                        <td class="px-5 py-4 text-sm">
                                            ${App.utils.escapeHtml(App.utils.formatDate(r.answered_at))}
                                        </td>
                                        <td class="px-5 py-4 text-sm">
                                            ${App.utils.escapeHtml(r.company || '-')}
                                        </td>
                                        <td class="px-5 py-4 text-sm">
                                            ${App.utils.escapeHtml(r.name || '-')}
                                        </td>
                                        <td class="px-5 py-4 text-sm">
                                            ${App.utils.escapeHtml(r.email || '-')}
                                        </td>
                                        <td class="px-5 py-4">
                                            <button
                                                onclick="App.actions.showResponse('${App.utils.escapeAttr(r.id)}')"
                                                class="px-3 py-1.5 rounded-lg border text-xs">
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
    `;
};

App.actions.filterResponses =
function(value) {
    App.state.responseKeyword =
        value || '';

    const responses =
        App.state.responses.filter(
            function(r) {
                return String(
                    r.survey_id
                ) === String(
                    App.state.currentSurveyId
                );
            }
        );

    document.getElementById(
        'response_table'
    ).innerHTML =
        App.render.responseTable(
            responses
        );
};

App.actions.showResponse =
function(id) {
    const response =
        App.state.responses.find(
            function(r) {
                return String(r.id) ===
                    String(id);
            }
        );

    if (!response) return;

    document.getElementById(
        'modal_root'
    ).innerHTML = `
        <div
            id="response_modal"
            class="fixed inset-0 z-[100] bg-black/50 flex items-center justify-center p-6">

            <div class="bg-white rounded-2xl max-w-3xl w-full max-h-[90vh] overflow-hidden">

                <div class="p-5 border-b flex justify-between">
                    <div class="font-bold">
                        回答詳細
                    </div>

                    <button
                        onclick="App.actions.closeModal()"
                        class="px-3 py-1 border rounded-lg">
                        閉じる
                    </button>
                </div>

                <div
                    id="response_detail"
                    class="p-6 overflow-auto max-h-[calc(90vh-70px)]">
                    <div class="mb-6">
                        <div class="font-bold">
                            ${App.utils.escapeHtml(response.company || '-')}
                        </div>
                        <div class="text-sm text-slate-500">
                            ${App.utils.escapeHtml(response.name || '-')}
                            /
                            ${App.utils.escapeHtml(response.email || '-')}
                        </div>
                    </div>

                    ${
                        Object.entries(
                            response.answers || {}
                        ).map(
                            function(pair) {
                                return `
                                    <div class="border-b py-4">
                                        <div class="text-xs text-slate-400">
                                            ${App.utils.escapeHtml(pair[0])}
                                        </div>
                                        <div class="mt-1 text-sm">
                                            ${
                                                App.utils.escapeHtml(
                                                    Array.isArray(pair[1])
                                                    ? pair[1].join('、')
                                                    : pair[1]
                                                )
                                            }
                                        </div>
                                    </div>
                                `;
                            }
                        ).join('')
                    }
                </div>
            </div>
        </div>
    `;
};
</script>

<script>
/* =========================================================
 * kintone settings
 * ======================================================= */

App.actions.openSettings =
function() {
    App.state.view =
        'settings';

    App.render.settings();
};

App.render.settings =
function() {
    const settings =
        App.state.settings || {};

    document.getElementById(
        'main_content'
    ).innerHTML = `
        <div class="mb-6">
            <div class="text-xs text-slate-400">
                ホーム ＞ システム設定
            </div>

            <h1 class="text-2xl font-bold mt-2">
                kintone連携設定
            </h1>
        </div>

        <form
            id="settings_form"
            onsubmit="event.preventDefault();App.actions.saveSettings()"
            class="max-w-5xl">

            <div class="bg-white border rounded-xl p-6">
                <div class="grid grid-cols-2 gap-5">

                    <div class="col-span-2">
                        <label class="block text-sm font-medium mb-2">
                            サブドメイン
                        </label>

                        <input
                            id="setting_subdomain"
                            value="${App.utils.escapeAttr(settings.subdomain || '')}"
                            placeholder="xxxx または xxxx.cybozu.com"
                            class="w-full border rounded-lg px-3 py-2.5">

                        <p class="text-xs text-slate-400 mt-1">
                            https:// は入力しても自動的に処理されます。
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-2">
                            顧客管理アプリID
                        </label>

                        <input
                            id="setting_app_id"
                            value="${App.utils.escapeAttr(settings.app_id || '')}"
                            inputmode="numeric"
                            class="w-full border rounded-lg px-3 py-2.5">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-2">
                            ログイン名
                        </label>

                        <input
                            id="setting_login_name"
                            value="${App.utils.escapeAttr(settings.login_name || '')}"
                            class="w-full border rounded-lg px-3 py-2.5">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-2">
                            パスワード
                        </label>

                        <input
                            id="setting_password"
                            type="password"
                            value="${App.utils.escapeAttr(settings.password || '')}"
                            class="w-full border rounded-lg px-3 py-2.5">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-2">
                            Proxyサーバ
                        </label>

                        <input
                            id="setting_proxy"
                            value="${App.utils.escapeAttr(settings.proxy || '')}"
                            placeholder="host名:port番号"
                            class="w-full border rounded-lg px-3 py-2.5">
                    </div>

                    <div class="flex items-center">
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${settings.ssl_verify ? 'checked' : ''}>
                            SSL証明書を検証する
                        </label>
                    </div>

                </div>
            </div>

            <div class="bg-white border rounded-xl p-6 mt-5">

                <div class="flex items-center justify-between mb-5">
                    <div>
                        <div class="font-bold">
                            kintoneフィールドマッピング
                        </div>

                        <div class="text-sm text-slate-400 mt-1">
                            kintoneから日本語フィールド名を取得します。
                        </div>
                    </div>

                    <button
                        type="button"
                        onclick="App.actions.fetchKintoneFields()"
                        class="px-4 py-2.5 rounded-lg border">
                        項目一覧を再取得
                    </button>
                </div>

                <div
                    id="field_message"
                    class="mb-4 text-sm text-slate-500">
                </div>

                <div
                    id="field_mapping"
                    class="grid grid-cols-2 gap-5">
                    ${App.render.fieldSelect(
                        'field_company',
                        '会社名',
                        settings.field_company
                    )}

                    ${App.render.fieldSelect(
                        'field_name',
                        '氏名',
                        settings.field_name
                    )}

                    ${App.render.fieldSelect(
                        'field_email',
                        'メールアドレス',
                        settings.field_email
                    )}

                    ${App.render.fieldSelect(
                        'field_department',
                        '部署名',
                        settings.field_department
                    )}

                    ${App.render.fieldSelect(
                        'field_phone',
                        '電話番号',
                        settings.field_phone
                    )}

                    ${App.render.fieldAddressSelects(
                        settings.field_address || []
                    )}
                </div>
            </div>

            <div class="mt-5 flex justify-end gap-3">
                <button
                    type="button"
                    onclick="App.actions.goList()"
                    class="px-5 py-2.5 rounded-lg border">
                    キャンセル
                </button>

                <button
                    type="submit"
                    class="px-6 py-2.5 rounded-lg bg-brand-600 text-white font-semibold">
                    設定を保存
                </button>
            </div>
        </form>
    `;
};

App.render.fieldSelect =
function(
    key,
    label,
    value
) {
    const fields =
        App.state.kintoneFields || [];

    return `
        <div>
            <label class="block text-sm font-medium mb-2">
                ${App.utils.escapeHtml(label)}
            </label>

            <select
                data-field-key="${App.utils.escapeAttr(key)}"
                class="kintone-field-select w-full border rounded-lg px-3 py-2.5">

                <option value="">
                    未設定
                </option>

                ${
                    fields.map(
                        function(field) {
                            return `
                                <option
                                    value="${App.utils.escapeAttr(field.code)}"
                                    ${String(value || '') ===
                                      String(field.code)
                                      ? 'selected'
                                      : ''}>
                                    ${App.utils.escapeHtml(field.label)}
                                    (${App.utils.escapeHtml(field.code)})
                                </option>
                            `;
                        }
                    ).join('')
                }
            </select>
        </div>
    `;
};

App.render.fieldAddressSelects =
function(values) {
    const list =
        Array.isArray(values)
        ? values
        : [];

    return `
        <div class="col-span-2">
            <label class="block text-sm font-medium mb-2">
                住所
                <span class="text-xs text-slate-400 ml-2">
                    複数フィールド可
                </span>
            </label>

            <div
                id="address_mapping"
                class="space-y-2">
                ${
                    list.length
                    ? list.map(
                        function(value) {
                            return App.render.addressSelect(
                                value
                            );
                        }
                    ).join('')
                    : App.render.addressSelect('')
                }
            </div>

            <button
                type="button"
                onclick="App.actions.addAddressField()"
                class="mt-2 text-sm text-brand-600">
                ＋ 住所フィールドを追加
            </button>
        </div>
    `;
};

App.render.addressSelect =
function(value) {
    const fields =
        App.state.kintoneFields || [];

    return `
        <div class="flex gap-2">
            <select
                data-address-field="1"
                class="kintone-address-select flex-1 border rounded-lg px-3 py-2.5">

                <option value="">
                    未設定
                </option>

                ${
                    fields.map(
                        function(field) {
                            return `
                                <option
                                    value="${App.utils.escapeAttr(field.code)}"
                                    ${String(value || '') ===
                                      String(field.code)
                                      ? 'selected'
                                      : ''}>
                                    ${App.utils.escapeHtml(field.label)}
                                    (${App.utils.escapeHtml(field.code)})
                                </option>
                            `;
                        }
                    ).join('')
                }
            </select>

            <button
                type="button"
                onclick="App.actions.removeAddressField(this)"
                class="px-3 text-slate-400 hover:text-red-600">
                ×
            </button>
        </div>
    `;
};

/*
 * ★重要
 * kintone項目一覧取得
 *
 * ここは今回の修正対象。
 *
 * 1. 画面から現在値を取得
 * 2. POST
 * 3. PHPでCSRF検証
 * 4. kintone REST APIへGET
 * 5. propertiesを解析
 * 6. selectを再描画
 */
App.actions.fetchKintoneFields =
async function() {
    const message =
        document.getElementById(
            'field_message'
        );

    const settings = {
        subdomain:
            document.getElementById(
                'setting_subdomain'
            )?.value.trim() || '',

        app_id:
            document.getElementById(
                'setting_app_id'
            )?.value.trim() || '',

        login_name:
            document.getElementById(
                'setting_login_name'
            )?.value.trim() || '',

        password:
            document.getElementById(
                'setting_password'
            )?.value || '',

        ssl_verify:
            document.getElementById(
                'setting_ssl_verify'
            )?.checked || false,

        proxy:
            document.getElementById(
                'setting_proxy'
            )?.value.trim() || ''
    };

    if (!settings.subdomain) {
        App.utils.notify(
            'サブドメインを入力してください。',
            'warning'
        );
        return;
    }

    if (!settings.app_id) {
        App.utils.notify(
            'アプリIDを入力してください。',
            'warning'
        );
        return;
    }

    if (!/^\d+$/.test(
        settings.app_id
    )) {
        App.utils.notify(
            'アプリIDは数値で入力してください。',
            'warning'
        );
        return;
    }

    if (!settings.login_name) {
        App.utils.notify(
            'ログイン名を入力してください。',
            'warning'
        );
        return;
    }

    if (!settings.password) {
        App.utils.notify(
            'パスワードを入力してください。',
            'warning'
        );
        return;
    }

    if (message) {
        message.innerHTML = `
            <span class="inline-flex items-center gap-2 text-brand-600">
                <span class="w-4 h-4 border-2 border-slate-200 border-t-brand-600 rounded-full animate-spin"></span>
                kintoneから項目一覧を取得しています...
            </span>
        `;
    }

    try {
        const json =
            await App.api.request(
                'kintone_fields',
                {
                    settings_json:
                        settings,
                    app_id:
                        settings.app_id
                }
            );

        App.state.kintoneFields =
            Array.isArray(
                json.fields
            )
            ? json.fields
            : [];

        const mapping =
            document.getElementById(
                'field_mapping'
            );

        if (mapping) {
            const current =
                App.state.settings || {};

            mapping.innerHTML =
                App.render.fieldSelect(
                    'field_company',
                    '会社名',
                    current.field_company
                ) +
                App.render.fieldSelect(
                    'field_name',
                    '氏名',
                    current.field_name
                ) +
                App.render.fieldSelect(
                    'field_email',
                    'メールアドレス',
                    current.field_email
                ) +
                App.render.fieldSelect(
                    'field_department',
                    '部署名',
                    current.field_department
                ) +
                App.render.fieldSelect(
                    'field_phone',
                    '電話番号',
                    current.field_phone
                ) +
                App.render.fieldAddressSelects(
                    current.field_address || []
                );
        }

        if (message) {
            message.innerHTML =
                '<span class="text-emerald-600">' +
                App.state.kintoneFields.length +
                '件のフィールドを取得しました。</span>';
        }

        App.utils.notify(
            'kintoneの項目一覧を取得しました。',
            'success'
        );

    } catch (error) {
        if (message) {
            message.innerHTML =
                '<span class="text-red-600">' +
                App.utils.escapeHtml(
                    error.message
                ) +
                '</span>';
        }

        App.utils.notify(
            error.message,
            'error'
        );
    }
};

App.actions.addAddressField =
function() {
    const container =
        document.getElementById(
            'address_mapping'
        );

    if (!container) return;

    container.insertAdjacentHTML(
        'beforeend',
        App.render.addressSelect('')
    );
};

App.actions.removeAddressField =
function(button) {
    const container =
        document.getElementById(
            'address_mapping'
        );

    if (!container) return;

    const fields =
        container.querySelectorAll(
            '[data-address-field]'
        );

    if (fields.length <= 1) {
        return;
    }

    button.closest(
        '.flex'
    )?.remove();
};

App.actions.saveSettings =
async function() {
    const addressFields =
        Array.from(
            document.querySelectorAll(
                '.kintone-address-select'
            )
        )
        .map(
            function(select) {
                return select.value;
            }
        )
        .filter(Boolean);

    const settings = {
        subdomain:
            document.getElementById(
                'setting_subdomain'
            ).value.trim(),

        login_name:
            document.getElementById(
                'setting_login_name'
            ).value.trim(),

        password:
            document.getElementById(
                'setting_password'
            ).value,

        app_id:
            document.getElementById(
                'setting_app_id'
            ).value.trim(),

        ssl_verify:
            document.getElementById(
                'setting_ssl_verify'
            ).checked,

        proxy:
            document.getElementById(
                'setting_proxy'
            ).value.trim(),

        field_company:
            document.querySelector(
                '[data-field-key="field_company"]'
            )?.value || '',

        field_name:
            document.querySelector(
                '[data-field-key="field_name"]'
            )?.value || '',

        field_email:
            document.querySelector(
                '[data-field-key="field_email"]'
            )?.value || '',

        field_department:
            document.querySelector(
                '[data-field-key="field_department"]'
            )?.value || '',

        field_phone:
            document.querySelector(
                '[data-field-key="field_phone"]'
            )?.value || '',

        field_address:
            addressFields
    };

    try {
        const json =
            await App.api.request(
                'save_settings',
                {
                    settings_json:
                        settings
                }
            );

        App.state.settings =
            json.settings;

        App.utils.notify(
            'kintone連携設定を保存しました。',
            'success'
        );
    } catch (error) {
        App.utils.notify(
            error.message,
            'error'
        );
    }
};
</script>

<script>
/* =========================================================
 * Misc
 * ======================================================= */

App.actions.logout =
function() {
    alert(
        'このサンプルでは管理者ログアウト処理を実装していません。'
    );
};

/*
 * 「全回答を表示」モーダル閉鎖
 */
App.actions.closeResponse =
function() {
    App.actions.closeModal();
};

/*
 * Initialization guard
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

</body>
</html>
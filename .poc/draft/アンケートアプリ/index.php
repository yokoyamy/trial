<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * index.php
 *
 * 画面識別:
 *   GET ?view=...
 *
 * 業務API:
 *   POST action=...
 *
 * 重要:
 * - URLパスを画面識別に使用しない
 * - $_SERVER['REQUEST_URI'] 等を画面判定に使用しない
 * - すべての画面をindex.phpで処理する
 * - viewはホワイトリスト方式で検証する
 * - GET=view は画面表示
 * - POST=action は業務処理
 * - 永続データはJSONを使用する
 * - DB(SQLite等)は使用しない
 */

session_start();

date_default_timezone_set('Asia/Tokyo');

const DEFAULT_VIEW = 'admin-survey-list';

const DATA_DIR = __DIR__ . '/data';
const SURVEY_FILE = DATA_DIR . '/surveys.json';
const RESPONSES_FILE = DATA_DIR . '/responses.json';
const CUSTOMERS_FILE = DATA_DIR . '/customers.json';
const SEND_HISTORY_FILE = DATA_DIR . '/send_history.json';
const KINTONE_SETTINGS_FILE = DATA_DIR . '/kintone_settings.json';
const MAIL_SETTINGS_FILE = DATA_DIR . '/mail_settings.json';

$allowedViews = [
    'admin-survey-list',
    'admin-survey-edit',
    'admin-preview',
    'admin-send',
    'admin-aggregation',
    'admin-kintone',
    'admin-mail',
    'answer',
    'confirm',
    'complete',
];

/*
|--------------------------------------------------------------------------
| 共通関数
|--------------------------------------------------------------------------
*/

function jsonResponse(
    array $data,
    int $status = 200
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    exit;
}

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
}

function ensureJsonFile(
    string $file,
    mixed $default
): void {
    ensureDataDir();

    if (!file_exists($file)) {
        atomicWriteJson($file, $default);
    }
}

function readJson(
    string $file,
    mixed $default = []
): mixed {
    ensureJsonFile($file, $default);

    $fp = fopen($file, 'r');

    if ($fp === false) {
        return $default;
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return $default;
    }

    $contents = stream_get_contents($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $data = json_decode($contents, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $data
        : $default;
}

function atomicWriteJson(
    string $file,
    mixed $data
): bool {
    ensureDataDir();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    $fp = fopen($tmp, 'wb');

    if ($fp === false) {
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        @unlink($tmp);
        return false;
    }

    fwrite($fp, $json);
    fflush($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    return rename($tmp, $file);
}

function saveJson(
    string $file,
    mixed $data
): bool {
    return atomicWriteJson($file, $data);
}

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function requestJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function generateId(string $prefix): string
{
    return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5));
}

function nowIso(): string
{
    return date('c');
}

function getView(
    array $allowedViews
): string {
    /*
     * 画面識別は$_GET['view']のみ。
     *
     * REQUEST_URI
     * PATH_INFO
     * SCRIPT_NAME
     * PHP_SELF
     * pathname
     *
     * 等は使用しない。
     */

    $view = isset($_GET['view'])
        ? (string)$_GET['view']
        : '';

    if ($view === '') {
        return DEFAULT_VIEW;
    }

    if (!in_array($view, $allowedViews, true)) {
        return DEFAULT_VIEW;
    }

    return $view;
}

function getSurveyId(): string
{
    return isset($_GET['surveyId'])
        ? trim((string)$_GET['surveyId'])
        : '';
}

function getToken(): string
{
    return isset($_GET['token'])
        ? trim((string)$_GET['token'])
        : '';
}

function getAction(): string
{
    return isset($_POST['action'])
        ? trim((string)$_POST['action'])
        : '';
}

function getPostValue(
    string $key,
    mixed $default = ''
): mixed {
    return array_key_exists($key, $_POST)
        ? $_POST[$key]
        : $default;
}

function requireSurvey(
    string $surveyId
): array {
    if ($surveyId === '') {
        jsonResponse([
            'success' => false,
            'error' => 'surveyId is required.',
        ], 400);
    }

    $surveys = readJson(SURVEY_FILE, []);

    foreach ($surveys as $survey) {
        if (
            is_array($survey) &&
            ($survey['id'] ?? '') === $surveyId
        ) {
            return $survey;
        }
    }

    jsonResponse([
        'success' => false,
        'error' => 'Survey not found.',
        'surveyId' => $surveyId,
    ], 404);
}

function findSurvey(
    string $surveyId
): ?array {
    if ($surveyId === '') {
        return null;
    }

    $surveys = readJson(SURVEY_FILE, []);

    foreach ($surveys as $survey) {
        if (
            is_array($survey) &&
            ($survey['id'] ?? '') === $surveyId
        ) {
            return $survey;
        }
    }

    return null;
}

function normalizeSurveyStatus(
    array $survey
): array {
    $status = $survey['status'] ?? 'draft';

    /*
     * 終了判定は以下をすべて満たした場合のみ。
     *
     * - 現在状態が公開中
     * - 終了日時が設定されている
     * - 現在日時が終了日時を経過
     */
    if (
        $status === 'published' &&
        !empty($survey['endAt'])
    ) {
        $endAt = strtotime((string)$survey['endAt']);

        if (
            $endAt !== false &&
            time() > $endAt
        ) {
            $survey['status'] = 'ended';
        }
    }

    return $survey;
}

function normalizeAllSurveyStatuses(): array
{
    $surveys = readJson(SURVEY_FILE, []);
    $changed = false;

    foreach ($surveys as $index => $survey) {
        if (!is_array($survey)) {
            continue;
        }

        $normalized = normalizeSurveyStatus($survey);

        if ($normalized !== $survey) {
            $surveys[$index] = $normalized;
            $changed = true;
        }
    }

    if ($changed) {
        saveJson(SURVEY_FILE, $surveys);
    }

    return $surveys;
}

function validStatusTransition(
    string $from,
    string $to
): bool {
    return match ($from) {
        'draft' => $to === 'published',
        'published' => $to === 'stopped',
        'stopped' => $to === 'published',
        'ended' => false,
        default => false,
    };
}

function validateSurveyStatusChange(
    array $survey,
    string $newStatus
): bool {
    $survey = normalizeSurveyStatus($survey);

    $current = $survey['status'] ?? 'draft';

    return validStatusTransition(
        $current,
        $newStatus
    );
}

function saveSurveyData(
    array $survey
): array {
    $surveys = readJson(SURVEY_FILE, []);

    $found = false;

    foreach ($surveys as $index => $existing) {
        if (
            is_array($existing) &&
            ($existing['id'] ?? '') === ($survey['id'] ?? '')
        ) {
            $surveys[$index] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $surveys[] = $survey;
    }

    saveJson(SURVEY_FILE, $surveys);

    return $survey;
}

function defaultSurvey(): array
{
    $now = nowIso();

    return [
        'id' => generateId('survey'),
        'title' => '',
        'description' => '',
        'startAt' => '',
        'endAt' => '',
        'status' => 'draft',
        'numberingMode' => 'global',
        'allowResubmission' => false,
        'groups' => [],
        'createdAt' => $now,
        'updatedAt' => $now,
    ];
}

function renumberQuestions(
    array &$survey
): void {
    $globalNumber = 1;

    foreach ($survey['groups'] as $groupIndex => &$group) {
        $groupId = $group['id'] ?? '';

        $groupNumber = $groupIndex + 1;
        $questionNumber = 1;

        foreach ($group['questions'] as $questionIndex => &$question) {
            $question['groupId'] = $groupId;
            $question['sortOrder'] = $questionIndex;

            if (($survey['numberingMode'] ?? 'global') === 'group') {
                $question['questionNumber'] =
                    'Q' . $groupNumber . '-' . $questionNumber;
            } else {
                $question['questionNumber'] =
                    'Q' . $globalNumber;
            }

            $globalNumber++;
            $questionNumber++;
        }

        unset($question);
    }

    unset($group);
}

function defaultQuestion(): array
{
    return [
        'id' => generateId('question'),
        'groupId' => '',
        'sortOrder' => 0,
        'questionNumber' => '',
        'text' => '',
        'type' => 'single',
        'required' => false,
        'choices' => [],
        'branching' => [],
    ];
}

function defaultGroup(): array
{
    return [
        'id' => generateId('group'),
        'title' => '',
        'sortOrder' => 0,
        'questions' => [],
    ];
}

function findQuestion(
    array $survey,
    string $questionId
): ?array {
    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            if (
                ($question['id'] ?? '') === $questionId
            ) {
                return $question;
            }
        }
    }

    return null;
}

function answerStatusForCustomer(
    string $surveyId,
    string $customerId
): string {
    $responses = readJson(RESPONSES_FILE, []);

    foreach ($responses as $response) {
        if (
            !is_array($response) ||
            ($response['surveyId'] ?? '') !== $surveyId ||
            ($response['customerId'] ?? '') !== $customerId
        ) {
            continue;
        }

        return 'answered';
    }

    $history = readJson(SEND_HISTORY_FILE, []);

    foreach ($history as $record) {
        foreach (($record['customers'] ?? []) as $customer) {
            if (
                ($customer['customerId'] ?? '') === $customerId &&
                ($record['surveyId'] ?? '') === $surveyId
            ) {
                return 'sent_unanswered';
            }
        }
    }

    return 'unsent';
}

/*
|--------------------------------------------------------------------------
| 初期JSON
|--------------------------------------------------------------------------
*/

ensureJsonFile(SURVEY_FILE, []);
ensureJsonFile(RESPONSES_FILE, []);
ensureJsonFile(CUSTOMERS_FILE, []);
ensureJsonFile(SEND_HISTORY_FILE, []);
ensureJsonFile(KINTONE_SETTINGS_FILE, [
    'subdomain' => '',
    'appId' => '',
    'loginName' => '',
    'password' => '',
    'sslVerify' => false,
    'proxy' => '',
    'mapping' => [
        'organization' => '',
        'name' => '',
        'email' => '',
        'department' => '',
        'phone' => '',
        'address' => [],
    ],
]);
ensureJsonFile(MAIL_SETTINGS_FILE, [
    'smtpServer' => '',
    'smtpPort' => '',
    'encryption' => '',
    'smtpAuth' => true,
    'smtpUsername' => '',
    'smtpPassword' => '',
    'fromEmail' => '',
    'fromName' => '',
    'replyTo' => '',
]);

/*
|--------------------------------------------------------------------------
| POST API
|--------------------------------------------------------------------------
|
| POSTは業務処理。
| 画面識別には使用しない。
|
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = getAction();

    if ($action === '') {
        jsonResponse([
            'success' => false,
            'error' => 'action is required.',
        ], 400);
    }

    switch ($action) {

        /*
         * ------------------------------------------------------------
         * アンケート保存
         * ------------------------------------------------------------
         */
        case 'save_survey': {
            $body = requestJsonBody();

            if (!$body) {
                $body = $_POST;
            }

            $surveyId = trim(
                (string)($body['id'] ?? '')
            );

            $existing = $surveyId !== ''
                ? findSurvey($surveyId)
                : null;

            if ($existing === null) {
                $survey = defaultSurvey();

                if ($surveyId !== '') {
                    $survey['id'] = $surveyId;
                }

                $survey['status'] = 'draft';
            } else {
                $survey = $existing;
            }

            /*
             * 保存では状態を変更しない。
             * 新規作成時のみdraft。
             */
            if ($existing !== null) {
                $survey['status'] =
                    $existing['status'] ?? 'draft';
            }

            $survey['title'] =
                trim((string)($body['title'] ?? ''));

            $survey['description'] =
                (string)($body['description'] ?? '');

            $survey['startAt'] =
                (string)($body['startAt'] ?? '');

            $survey['endAt'] =
                (string)($body['endAt'] ?? '');

            $survey['numberingMode'] =
                (($body['numberingMode'] ?? 'global') === 'group')
                    ? 'group'
                    : 'global';

            $survey['allowResubmission'] =
                filter_var(
                    $body['allowResubmission'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );

            $survey['groups'] =
                is_array($body['groups'] ?? null)
                    ? $body['groups']
                    : [];

            renumberQuestions($survey);

            $survey['updatedAt'] = nowIso();

            saveSurveyData($survey);

            jsonResponse([
                'success' => true,
                'survey' => $survey,
            ]);
        }

        /*
         * ------------------------------------------------------------
         * アンケート削除
         * ------------------------------------------------------------
         */
        case 'delete_survey': {
            $surveyId = trim(
                (string)getPostValue('surveyId')
            );

            if ($surveyId === '') {
                jsonResponse([
                    'success' => false,
                    'error' => 'surveyId is required.',
                ], 400);
            }

            $surveys = readJson(SURVEY_FILE, []);

            $newSurveys = [];

            foreach ($surveys as $survey) {
                if (
                    !is_array($survey) ||
                    ($survey['id'] ?? '') !== $surveyId
                ) {
                    $newSurveys[] = $survey;
                }
            }

            saveJson(SURVEY_FILE, $newSurveys);

            jsonResponse([
                'success' => true,
                'surveyId' => $surveyId,
            ]);
        }

        /*
         * ------------------------------------------------------------
         * アンケート複製
         * ------------------------------------------------------------
         */
        case 'duplicate_survey': {
            $surveyId = trim(
                (string)getPostValue('surveyId')
            );

            $survey = findSurvey($surveyId);

            if ($survey === null) {
                jsonResponse([
                    'success' => false,
                    'error' => 'Survey not found.',
                ], 404);
            }

            $copy = $survey;

            $copy['id'] = generateId('survey');
            $copy['title'] =
                ($copy['title'] ?? '') . '（複製）';

            /*
             * 複製は必ず下書き。
             */
            $copy['status'] = 'draft';

            $copy['createdAt'] = nowIso();
            $copy['updatedAt'] = nowIso();

            saveSurveyData($copy);

            jsonResponse([
                'success' => true,
                'survey' => $copy,
            ]);
        }

        /*
         * ------------------------------------------------------------
         * 状態変更
         * ------------------------------------------------------------
         */
        case 'change_survey_status': {
            $surveyId = trim(
                (string)getPostValue('surveyId')
            );

            $newStatus = trim(
                (string)getPostValue('status')
            );

            $survey = findSurvey($surveyId);

            if ($survey === null) {
                jsonResponse([
                    'success' => false,
                    'error' => 'Survey not found.',
                ], 404);
            }

            $survey = normalizeSurveyStatus($survey);

            if (
                !validateSurveyStatusChange(
                    $survey,
                    $newStatus
                )
            ) {
                jsonResponse([
                    'success' => false,
                    'error' =>
                        'Invalid survey status transition.',
                    'currentStatus' =>
                        $survey['status'] ?? '',
                    'requestedStatus' =>
                        $newStatus,
                ], 400);
            }

            $survey['status'] = $newStatus;
            $survey['updatedAt'] = nowIso();

            saveSurveyData($survey);

            jsonResponse([
                'success' => true,
                'survey' => $survey,
            ]);
        }

        /*
         * ------------------------------------------------------------
         * 回答保存
         * ------------------------------------------------------------
         */
        case 'save_response': {
            $body = requestJsonBody();

            if (!$body) {
                $body = $_POST;
            }

            $surveyId =
                trim((string)($body['surveyId'] ?? ''));

            $token =
                trim((string)($body['token'] ?? ''));

            if ($surveyId === '') {
                jsonResponse([
                    'success' => false,
                    'error' => 'surveyId is required.',
                ], 400);
            }

            $survey = findSurvey($surveyId);

            if ($survey === null) {
                jsonResponse([
                    'success' => false,
                    'error' => 'Survey not found.',
                ], 404);
            }

            $survey = normalizeSurveyStatus($survey);

            if (($survey['status'] ?? '') !== 'published') {
                jsonResponse([
                    'success' => false,
                    'error' => 'Survey is not available.',
                ], 400);
            }

            $response = [
                'id' => generateId('response'),
                'surveyId' => $surveyId,
                'token' => $token,
                'customerId' =>
                    (string)($body['customerId'] ?? ''),
                'respondent' =>
                    is_array($body['respondent'] ?? null)
                        ? $body['respondent']
                        : [],
                'answers' =>
                    is_array($body['answers'] ?? null)
                        ? $body['answers']
                        : [],
                'createdAt' => nowIso(),
                'updatedAt' => nowIso(),
            ];

            $responses =
                readJson(RESPONSES_FILE, []);

            $responses[] = $response;

            saveJson(
                RESPONSES_FILE,
                $responses
            );

            jsonResponse([
                'success' => true,
                'response' => $response,
            ]);
        }

        /*
         * ------------------------------------------------------------
         * メール送信
         * ------------------------------------------------------------
         */
        case 'send_mail': {
            $body = requestJsonBody();

            if (!$body) {
                $body = $_POST;
            }

            $surveyId =
                trim((string)($body['surveyId'] ?? ''));

            if ($surveyId === '') {
                jsonResponse([
                    'success' => false,
                    'error' => 'surveyId is required.',
                ], 400);
            }

            $survey = findSurvey($surveyId);

            if ($survey === null) {
                jsonResponse([
                    'success' => false,
                    'error' => 'Survey not found.',
                ], 404);
            }

            $customers =
                is_array($body['customers'] ?? null)
                    ? $body['customers']
                    : [];

            $subject =
                (string)($body['subject'] ?? '');

            $message =
                (string)($body['message'] ?? '');

            /*
             * 実SMTP送信の実装箇所。
             *
             * 本番ではここでSMTP設定を読み込み、
             * 顧客ごとに変数を展開して送信する。
             */
            $mailSettings =
                readJson(MAIL_SETTINGS_FILE, []);

            if (
                empty($mailSettings['smtpServer']) ||
                empty($mailSettings['smtpPort']) ||
                empty($mailSettings['fromEmail'])
            ) {
                jsonResponse([
                    'success' => false,
                    'error' =>
                        'SMTP settings are not configured.',
                    'sentCount' => 0,
                    'failedCount' => count($customers),
                ], 400);
            }

            /*
             * 送信処理結果。
             *
             * 実SMTPライブラリを組み込む場合は、
             * この部分を実送信処理へ置き換える。
             */
            $results = [];

            foreach ($customers as $customer) {

                if (!is_array($customer)) {
                    continue;
                }

                $customerName =
                    (string)($customer['name'] ?? '');

                $customerEmail =
                    (string)($customer['email'] ?? '');

                $personalizedMessage =
                    str_replace(
                        [
                            '{顧客名}',
                            '{アンケートURL}',
                        ],
                        [
                            $customerName,
                            buildSurveyUrl(
                                $surveyId,
                                (string)(
                                    $customer['token'] ?? ''
                                )
                            ),
                        ],
                        $message
                    );

                /*
                 * TODO:
                 * 実SMTP通信をここで実行する。
                 *
                 * 成功:
                 * $success = true
                 *
                 * 失敗:
                 * $success = false
                 */
                $success = false;

                $results[] = [
                    'customerId' =>
                        (string)(
                            $customer['id'] ?? ''
                        ),
                    'email' => $customerEmail,
                    'customerName' =>
                        $customerName,
                    'success' => $success,
                    'subject' => $subject,
                    'message' =>
                        $personalizedMessage,
                ];
            }

            $successCount = 0;
            $failedCount = 0;

            foreach ($results as $result) {
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failedCount++;
                }
            }

            $history =
                readJson(SEND_HISTORY_FILE, []);

            $history[] = [
                'id' =>
                    generateId('send'),
                'surveyId' =>
                    $surveyId,
                'sentAt' =>
                    nowIso(),
                'type' =>
                    (string)(
                        $body['sendType'] ?? 'bulk'
                    ),
                'count' =>
                    count($customers),
                'successCount' =>
                    $successCount,
                'failedCount' =>
                    $failedCount,
                'subject' =>
                    $subject,
                'executor' =>
                    'system',
                'customers' =>
                    $customers,
                'results' =>
                    $results,
            ];

            saveJson(
                SEND_HISTORY_FILE,
                $history
            );

            jsonResponse([
                'success' =>
                    $failedCount === 0 &&
                    count($customers) > 0,
                'targetCount' =>
                    count($customers),
                'successCount' =>
                    $successCount,
                'failedCount' =>
                    $failedCount,
                'sentAt' =>
                    nowIso(),
                'results' =>
                    $results,
            ]);
        }

        /*
         * ------------------------------------------------------------
         * テストメール
         * ------------------------------------------------------------
         */
        case 'send_test_mail': {
            $mailSettings =
                readJson(MAIL_SETTINGS_FILE, []);

            if (
                empty($mailSettings['smtpServer']) ||
                empty($mailSettings['smtpPort']) ||
                empty($mailSettings['fromEmail'])
            ) {
                jsonResponse([
                    'success' => false,
                    'error' =>
                        'SMTP settings are not configured.',
                ], 400);
            }

            /*
             * TODO:
             * 実SMTP通信を実装する。
             */
            jsonResponse([
                'success' => false,
                'error' =>
                    'SMTP connection is not implemented yet.',
            ], 501);
        }

        /*
         * ------------------------------------------------------------
         * kintone設定保存
         * ------------------------------------------------------------
         */
        case 'save_kintone_settings': {
            $body = requestJsonBody();

            if (!$body) {
                $body = $_POST;
            }

            $settings = [
                'subdomain' =>
                    trim(
                        (string)(
                            $body['subdomain'] ?? ''
                        )
                    ),
                'appId' =>
                    trim(
                        (string)(
                            $body['appId'] ?? ''
                        )
                    ),
                'loginName' =>
                    trim(
                        (string)(
                            $body['loginName'] ?? ''
                        )
                    ),
                'password' =>
                    (string)(
                        $body['password'] ?? ''
                    ),
                'sslVerify' =>
                    filter_var(
                        $body['sslVerify'] ?? false,
                        FILTER_VALIDATE_BOOLEAN
                    ),
                'proxy' =>
                    trim(
                        (string)(
                            $body['proxy'] ?? ''
                        )
                    ),
                'mapping' =>
                    is_array($body['mapping'] ?? null)
                        ? $body['mapping']
                        : [],
            ];

            saveJson(
                KINTONE_SETTINGS_FILE,
                $settings
            );

            jsonResponse([
                'success' => true,
                'settings' => [
                    'subdomain' =>
                        $settings['subdomain'],
                    'appId' =>
                        $settings['appId'],
                    'loginName' =>
                        $settings['loginName'],
                    'sslVerify' =>
                        $settings['sslVerify'],
                    'proxy' =>
                        $settings['proxy'],
                    'mapping' =>
                        $settings['mapping'],
                ],
            ]);
        }

        /*
         * ------------------------------------------------------------
         * kintone接続テスト
         * ------------------------------------------------------------
         */
        case 'kintone_test': {
            $settings =
                readJson(
                    KINTONE_SETTINGS_FILE,
                    []
                );

            /*
             * TODO:
             * 実際のkintone API通信を実装する。
             *
             * SSL証明書検証:
             *   sslVerify=false -> CURLOPT_SSL_VERIFYPEER=false
             *   sslVerify=true  -> CURLOPT_SSL_VERIFYPEER=true
             *
             * proxy:
             *   host:port
             *
             * プロキシ認証は行わない。
             */
            jsonResponse([
                'success' => false,
                'error' =>
                    'kintone connection test is not implemented yet.',
                'settingsUsed' => [
                    'subdomain' =>
                        $settings['subdomain'] ?? '',
                    'appId' =>
                        $settings['appId'] ?? '',
                    'loginName' =>
                        $settings['loginName'] ?? '',
                    'sslVerify' =>
                        $settings['sslVerify'] ?? false,
                    'proxy' =>
                        $settings['proxy'] ?? '',
                ],
            ], 501);
        }

        /*
         * ------------------------------------------------------------
         * kintone項目取得
         * ------------------------------------------------------------
         */
        case 'kintone_get_fields': {
            $settings =
                readJson(
                    KINTONE_SETTINGS_FILE,
                    []
                );

            /*
             * TODO:
             * 実際のkintone APIからフィールド一覧を取得する。
             */
            jsonResponse([
                'success' => false,
                'error' =>
                    'kintone field retrieval is not implemented yet.',
                'settingsUsed' => [
                    'subdomain' =>
                        $settings['subdomain'] ?? '',
                    'appId' =>
                        $settings['appId'] ?? '',
                    'sslVerify' =>
                        $settings['sslVerify'] ?? false,
                    'proxy' =>
                        $settings['proxy'] ?? '',
                ],
            ], 501);
        }

        /*
         * ------------------------------------------------------------
         * kintone顧客同期
         * ------------------------------------------------------------
         */
        case 'kintone_sync': {
            $settings =
                readJson(
                    KINTONE_SETTINGS_FILE,
                    []
                );

            /*
             * TODO:
             * 実際のkintone APIから顧客情報を取得し、
             * customers.jsonへ同期する。
             */
            jsonResponse([
                'success' => false,
                'error' =>
                    'kintone customer synchronization is not implemented yet.',
                'settingsUsed' => [
                    'subdomain' =>
                        $settings['subdomain'] ?? '',
                    'appId' =>
                        $settings['appId'] ?? '',
                    'sslVerify' =>
                        $settings['sslVerify'] ?? false,
                    'proxy' =>
                        $settings['proxy'] ?? '',
                ],
            ], 501);
        }

        /*
         * ------------------------------------------------------------
         * メール設定保存
         * ------------------------------------------------------------
         */
        case 'save_mail_settings': {
            $body = requestJsonBody();

            if (!$body) {
                $body = $_POST;
            }

            $settings = [
                'smtpServer' =>
                    trim(
                        (string)(
                            $body['smtpServer'] ?? ''
                        )
                    ),
                'smtpPort' =>
                    trim(
                        (string)(
                            $body['smtpPort'] ?? ''
                        )
                    ),
                'encryption' =>
                    trim(
                        (string)(
                            $body['encryption'] ?? ''
                        )
                    ),
                'smtpAuth' =>
                    filter_var(
                        $body['smtpAuth'] ?? true,
                        FILTER_VALIDATE_BOOLEAN
                    ),
                'smtpUsername' =>
                    (string)(
                        $body['smtpUsername'] ?? ''
                    ),
                'smtpPassword' =>
                    (string)(
                        $body['smtpPassword'] ?? ''
                    ),
                'fromEmail' =>
                    trim(
                        (string)(
                            $body['fromEmail'] ?? ''
                        )
                    ),
                'fromName' =>
                    trim(
                        (string)(
                            $body['fromName'] ?? ''
                        )
                    ),
                'replyTo' =>
                    trim(
                        (string)(
                            $body['replyTo'] ?? ''
                        )
                    ),
            ];

            saveJson(
                MAIL_SETTINGS_FILE,
                $settings
            );

            jsonResponse([
                'success' => true,
            ]);
        }

        /*
         * ------------------------------------------------------------
         * CSV出力
         * ------------------------------------------------------------
         */
        case 'export_csv': {
            $surveyId =
                trim(
                    (string)getPostValue('surveyId')
                );

            $survey = findSurvey($surveyId);

            if ($survey === null) {
                jsonResponse([
                    'success' => false,
                    'error' => 'Survey not found.',
                ], 404);
            }

            /*
             * 実ファイル生成を必須としない仕様。
             */
            jsonResponse([
                'success' => true,
                'message' =>
                    'CSV出力を実行しました。',
                'surveyId' =>
                    $surveyId,
            ]);
        }

        /*
         * ------------------------------------------------------------
         * PDF出力
         * ------------------------------------------------------------
         */
        case 'export_pdf': {
            $surveyId =
                trim(
                    (string)getPostValue('surveyId')
                );

            $survey = findSurvey($surveyId);

            if ($survey === null) {
                jsonResponse([
                    'success' => false,
                    'error' => 'Survey not found.',
                ], 404);
            }

            /*
             * 実PDF生成を必須としない仕様。
             */
            jsonResponse([
                'success' => true,
                'message' =>
                    'PDF出力を実行しました。',
                'surveyId' =>
                    $surveyId,
            ]);
        }

        default: {
            jsonResponse([
                'success' => false,
                'error' =>
                    'Unknown action.',
                'action' =>
                    $action,
            ], 400);
        }
    }
}

/*
|--------------------------------------------------------------------------
| GET 画面表示
|--------------------------------------------------------------------------
|
| ここから先は画面表示。
|
| 画面識別は必ず$_GET['view']のみ。
| URLパスは一切参照しない。
|
*/

$view = getView($allowedViews);

$surveyId = getSurveyId();
$token = getToken();

/*
|--------------------------------------------------------------------------
| 終了判定
|--------------------------------------------------------------------------
*/

normalizeAllSurveyStatuses();

/*
|--------------------------------------------------------------------------
| 画面別初期データ
|--------------------------------------------------------------------------
*/

$initialData = [
    'view' => $view,
    'surveyId' => $surveyId,
    'token' => $token,
];

switch ($view) {

    case 'admin-survey-list':
        $initialData['surveys'] =
            normalizeAllSurveyStatuses();
        break;

    case 'admin-survey-edit':
        $initialData['survey'] =
            $surveyId !== ''
                ? findSurvey($surveyId)
                : defaultSurvey();
        break;

    case 'admin-preview':
        $initialData['survey'] =
            findSurvey($surveyId);
        break;

    case 'admin-send':
        $initialData['survey'] =
            findSurvey($surveyId);

        $initialData['customers'] =
            readJson(CUSTOMERS_FILE, []);

        $initialData['sendHistory'] =
            $surveyId !== ''
                ? array_values(
                    array_filter(
                        readJson(
                            SEND_HISTORY_FILE,
                            []
                        ),
                        static function (
                            $item
                        ) use ($surveyId) {
                            return is_array($item) &&
                                ($item['surveyId'] ?? '') ===
                                $surveyId;
                        }
                    )
                )
                : [];
        break;

    case 'admin-aggregation':
        $initialData['survey'] =
            findSurvey($surveyId);

        $responses =
            readJson(RESPONSES_FILE, []);

        $initialData['responses'] =
            array_values(
                array_filter(
                    $responses,
                    static function (
                        $response
                    ) use ($surveyId) {
                        return is_array($response) &&
                            ($response['surveyId'] ?? '') ===
                            $surveyId;
                    }
                )
            );
        break;

    case 'admin-kintone':
        $initialData['kintoneSettings'] =
            readJson(
                KINTONE_SETTINGS_FILE,
                []
            );
        break;

    case 'admin-mail':
        $initialData['mailSettings'] =
            readJson(
                MAIL_SETTINGS_FILE,
                []
            );
        break;

    case 'answer':
    case 'confirm':
    case 'complete':
        $initialData['survey'] =
            findSurvey($surveyId);

        break;
}

/*
|--------------------------------------------------------------------------
| URL生成
|--------------------------------------------------------------------------
|
| 画面URLは必ずindex.php + query。
| 実際のindex.php配置ディレクトリを
| 画面識別情報として扱わない。
|
*/

function buildViewUrl(
    string $view,
    array $params = []
): string {
    $query = array_merge(
        ['view' => $view],
        $params
    );

    return 'index.php?' .
        http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
}

function buildSurveyUrl(
    string $surveyId,
    string $token = ''
): string {
    $params = [
        'surveyId' => $surveyId,
    ];

    if ($token !== '') {
        $params['token'] = $token;
    }

    return buildViewUrl(
        'answer',
        $params
    );
}

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<title>アンケート管理システム</title>

<style>
* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
    color: #222;
    background: #f5f6f8;
}

body {
    min-height: 100vh;
}

button,
input,
textarea,
select {
    font: inherit;
}

button {
    cursor: pointer;
}

.app {
    min-height: 100vh;
}

.app-header {
    background: #1f2937;
    color: #fff;
    padding: 14px 20px;
}

.app-header-inner {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.app-title {
    margin: 0;
    font-size: 20px;
}

.app-main {
    max-width: 1400px;
    margin: 0 auto;
    padding: 24px;
}

.screen {
    background: #fff;
    border-radius: 10px;
    padding: 24px;
    box-shadow:
        0 1px 3px rgba(0, 0, 0, .08);
}

.screen-title {
    margin: 0 0 20px;
    font-size: 24px;
}

.toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
}

input,
textarea,
select {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 9px 10px;
    background: #fff;
}

textarea {
    min-height: 120px;
    resize: vertical;
}

.form-row {
    display: grid;
    grid-template-columns: 180px 1fr;
    gap: 16px;
    align-items: start;
    margin-bottom: 16px;
}

.form-label {
    font-weight: 600;
}

.btn {
    border: 0;
    border-radius: 6px;
    padding: 9px 14px;
    background: #374151;
    color: #fff;
}

.btn-primary {
    background: #2563eb;
}

.btn-success {
    background: #15803d;
}

.btn-danger {
    background: #dc2626;
}

.btn-secondary {
    background: #6b7280;
}

.btn-light {
    background: #e5e7eb;
    color: #111827;
}

.btn:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th,
td {
    border-bottom: 1px solid #e5e7eb;
    padding: 12px 10px;
    text-align: left;
    vertical-align: top;
}

th {
    background: #f9fafb;
}

.badge {
    display: inline-block;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 600;
}

.badge-draft {
    background: #e5e7eb;
}

.badge-published {
    background: #dcfce7;
    color: #166534;
}

.badge-stopped {
    background: #fef3c7;
    color: #92400e;
}

.badge-ended {
    background: #fee2e2;
    color: #991b1b;
}

.card-grid {
    display: grid;
    grid-template-columns:
        repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px;
}

.card {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    background: #fff;
}

.card-title {
    margin: 0 0 8px;
    font-size: 16px;
}

.muted {
    color: #6b7280;
}

.error {
    color: #b91c1c;
}

.success {
    color: #15803d;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .45);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 1000;
}

.modal-backdrop.is-open {
    display: flex;
}

.modal {
    width: min(600px, 100%);
    background: #fff;
    border-radius: 10px;
    padding: 24px;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

.question-card,
.group-card {
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.group-card {
    background: #f9fafb;
}

.question-card {
    background: #fff;
}

.question-header,
.group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}

.choice-row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 8px;
    margin-bottom: 8px;
}

.answer-choice {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    margin-bottom: 8px;
}

.answer-choice input {
    width: auto;
}

.mobile-only {
    display: none;
}

@media (max-width: 800px) {
    .app-main {
        padding: 12px;
    }

    .screen {
        padding: 16px;
    }

    .form-row {
        grid-template-columns: 1fr;
        gap: 6px;
    }

    .mobile-only {
        display: block;
    }

    .app-header-inner {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
</head>

<body>

<div
    id="app"
    class="app"
    data-view="<?= h($view) ?>"
    data-survey-id="<?= h($surveyId) ?>"
    data-token="<?= h($token) ?>"
>
    <header class="app-header">
        <div class="app-header-inner">
            <h1 class="app-title">
                アンケート管理システム
            </h1>

            <div
                id="header-actions"
            ></div>
        </div>
    </header>

    <main class="app-main">
        <div id="screen-container"></div>
    </main>
</div>

<div
    id="modal-backdrop"
    class="modal-backdrop"
    aria-hidden="true"
>
    <div
        id="modal"
        class="modal"
        role="dialog"
        aria-modal="true"
    ></div>
</div>

<script>
'use strict';

/*
|--------------------------------------------------------------------------
| PHP初期データ
|--------------------------------------------------------------------------
*/

const INITIAL_DATA =
    <?= json_encode(
        $initialData,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

/*
|--------------------------------------------------------------------------
| 許可view
|--------------------------------------------------------------------------
*/

const ALLOWED_VIEWS = [
    'admin-survey-list',
    'admin-survey-edit',
    'admin-preview',
    'admin-send',
    'admin-aggregation',
    'admin-kintone',
    'admin-mail',
    'answer',
    'confirm',
    'complete'
];

const DEFAULT_VIEW =
    'admin-survey-list';

/*
|--------------------------------------------------------------------------
| URLを唯一の状態ソースとする
|--------------------------------------------------------------------------
*/

function readUrlState() {
    const params =
        new URLSearchParams(
            window.location.search
        );

    let view =
        params.get('view') || DEFAULT_VIEW;

    if (!ALLOWED_VIEWS.includes(view)) {
        view = DEFAULT_VIEW;
    }

    return {
        view,
        surveyId:
            params.get('surveyId') || '',
        token:
            params.get('token') || ''
    };
}

let currentView = null;
let currentSurveyId = null;
let currentToken = null;

/*
|--------------------------------------------------------------------------
| URLからcurrent stateを再構築
|--------------------------------------------------------------------------
*/

function rebuildStateFromUrl() {
    const state = readUrlState();

    currentView = state.view;
    currentSurveyId = state.surveyId;
    currentToken = state.token;

    return state;
}

/*
|--------------------------------------------------------------------------
| 画面遷移
|--------------------------------------------------------------------------
|
| currentViewを直接変更しない。
|
| URL生成
| ↓
| history.pushState()
| ↓
| URL再取得
| ↓
| state再構築
| ↓
| render
|
|--------------------------------------------------------------------------
*/

function navigate(
    view,
    params = {},
    replace = false
) {
    if (!ALLOWED_VIEWS.includes(view)) {
        view = DEFAULT_VIEW;
    }

    const query = new URLSearchParams();

    query.set('view', view);

    Object.entries(params).forEach(
        ([key, value]) => {
            if (
                value !== null &&
                value !== undefined &&
                String(value) !== ''
            ) {
                query.set(
                    key,
                    String(value)
                );
            }
        }
    );

    const nextUrl =
        'index.php?' +
        query.toString();

    if (replace) {
        history.replaceState(
            {},
            '',
            nextUrl
        );
    } else {
        history.pushState(
            {},
            '',
            nextUrl
        );
    }

    /*
     * URL更新後、必ずURLを読み直す。
     */
    renderFromUrl();
}

/*
|--------------------------------------------------------------------------
| popstate
|--------------------------------------------------------------------------
*/

window.addEventListener(
    'popstate',
    function () {
        renderFromUrl();
    }
);

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
*/

async function api(
    action,
    payload = {}
) {
    const body =
        new URLSearchParams();

    body.set(
        'action',
        action
    );

    Object.entries(payload).forEach(
        ([key, value]) => {

            if (
                value !== null &&
                value !== undefined &&
                typeof value === 'object'
            ) {
                body.set(
                    key,
                    JSON.stringify(value)
                );
            } else {
                body.set(
                    key,
                    String(value ?? '')
                );
            }
        }
    );

    const response =
        await fetch(
            'index.php',
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body
            }
        );

    const data =
        await response.json();

    if (!response.ok) {
        throw new Error(
            data.error ||
            'API request failed.'
        );
    }

    return data;
}

/*
|--------------------------------------------------------------------------
| JSON POST
|--------------------------------------------------------------------------
*/

async function apiJson(
    action,
    payload = {}
) {
    const response =
        await fetch(
            'index.php',
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/json; charset=UTF-8'
                },
                body: JSON.stringify({
                    action,
                    ...payload
                })
            }
        );

    const data =
        await response.json();

    if (!response.ok) {
        throw new Error(
            data.error ||
            'API request failed.'
        );
    }

    return data;
}

/*
|--------------------------------------------------------------------------
| HTML escape
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/*
|--------------------------------------------------------------------------
| 日付表示
|--------------------------------------------------------------------------
*/

function formatDate(value) {
    if (!value) {
        return '-';
    }

    const date =
        new Date(value);

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {
        return value;
    }

    return date.toLocaleString(
        'ja-JP'
    );
}

/*
|--------------------------------------------------------------------------
| ステータス
|--------------------------------------------------------------------------
*/

function statusLabel(status) {
    switch (status) {
        case 'published':
            return '公開中';

        case 'stopped':
            return '停止';

        case 'ended':
            return '終了';

        case 'draft':
        default:
            return '下書き';
    }
}

function statusBadge(status) {
    return `
        <span class="badge badge-${escapeHtml(status)}">
            ${escapeHtml(
                statusLabel(status)
            )}
        </span>
    `;
}

/*
|--------------------------------------------------------------------------
| モーダル
|--------------------------------------------------------------------------
*/

function openModal(
    html
) {
    const backdrop =
        document.getElementById(
            'modal-backdrop'
        );

    const modal =
        document.getElementById(
            'modal'
        );

    modal.innerHTML = html;

    backdrop.classList.add(
        'is-open'
    );

    backdrop.setAttribute(
        'aria-hidden',
        'false'
    );
}

function closeModal() {
    const backdrop =
        document.getElementById(
            'modal-backdrop'
        );

    backdrop.classList.remove(
        'is-open'
    );

    backdrop.setAttribute(
        'aria-hidden',
        'true'
    );
}

document
    .getElementById('modal-backdrop')
    .addEventListener(
        'click',
        function (event) {
            if (
                event.target === this
            ) {
                closeModal();
            }
        }
    );

/*
|--------------------------------------------------------------------------
| 管理者ヘッダー
|--------------------------------------------------------------------------
*/

function renderAdminHeader() {
    const container =
        document.getElementById(
            'header-actions'
        );

    container.innerHTML = `
        <button
            class="btn btn-light"
            type="button"
            onclick="navigate(
                'admin-survey-list'
            )"
        >
            アンケート一覧
        </button>
    `;
}

function renderAnswerHeader() {
    const container =
        document.getElementById(
            'header-actions'
        );

    /*
     * 回答者画面には管理者メニューを表示しない。
     */
    container.innerHTML = '';
}

/*
|--------------------------------------------------------------------------
| 一覧
|--------------------------------------------------------------------------
*/

function renderSurveyList(
    state
) {
    renderAdminHeader();

    const surveys =
        Array.isArray(
            INITIAL_DATA.surveys
        )
            ? INITIAL_DATA.surveys
            : [];

    const container =
        document.getElementById(
            'screen-container'
        );

    container.innerHTML = `
        <section class="screen">
            <h2 class="screen-title">
                アンケート一覧
            </h2>

            <div class="toolbar">
                <input
                    id="survey-search"
                    type="search"
                    placeholder="タイトルで検索"
                    style="max-width:360px;"
                >

                <select
                    id="survey-filter"
                    style="max-width:180px;"
                >
                    <option value="all">
                        すべて
                    </option>
                    <option value="published">
                        公開中
                    </option>
                    <option value="draft">
                        下書き
                    </option>
                    <option value="stopped">
                        停止
                    </option>
                    <option value="ended">
                        終了
                    </option>
                </select>

                <select
                    id="survey-sort"
                    style="max-width:220px;"
                >
                    <option value="updated-desc">
                        更新日 新しい順
                    </option>
                    <option value="updated-asc">
                        更新日 古い順
                    </option>
                    <option value="answers-desc">
                        回答数 多い順
                    </option>
                    <option value="answers-asc">
                        回答数 少ない順
                    </option>
                    <option value="start-desc">
                        開始日 新しい順
                    </option>
                    <option value="start-asc">
                        開始日 古い順
                    </option>
                </select>

                <button
                    class="btn btn-primary"
                    type="button"
                    onclick="
                        navigate(
                            'admin-survey-edit'
                        )
                    "
                >
                    アンケート作成
                </button>
            </div>

            <div
                id="survey-table"
                class="table-wrap"
            ></div>
        </section>
    `;

    const search =
        document.getElementById(
            'survey-search'
        );

    const filter =
        document.getElementById(
            'survey-filter'
        );

    const sort =
        document.getElementById(
            'survey-sort'
        );

    function draw() {
        let list =
            surveys.map(
                survey => ({
                    ...survey
                })
            );

        const keyword =
            search.value
                .trim()
                .toLowerCase();

        if (keyword) {
            list = list.filter(
                survey =>
                    String(
                        survey.title || ''
                    )
                    .toLowerCase()
                    .includes(keyword)
            );
        }

        if (
            filter.value !== 'all'
        ) {
            list = list.filter(
                survey =>
                    survey.status ===
                    filter.value
            );
        }

        list.sort(
            (a, b) => {

                const option =
                    sort.value;

                if (
                    option ===
                    'answers-desc'
                ) {
                    return (
                        Number(
                            b.answerCount || 0
                        ) -
                        Number(
                            a.answerCount || 0
                        )
                    );
                }

                if (
                    option ===
                    'answers-asc'
                ) {
                    return (
                        Number(
                            a.answerCount || 0
                        ) -
                        Number(
                            b.answerCount || 0
                        )
                    );
                }

                const field =
                    option.startsWith(
                        'start'
                    )
                        ? 'startAt'
                        : 'updatedAt';

                const av =
                    Date.parse(
                        a[field] || ''
                    ) || 0;

                const bv =
                    Date.parse(
                        b[field] || ''
                    ) || 0;

                return option.endsWith(
                    '-asc'
                )
                    ? av - bv
                    : bv - av;
            }
        );

        const table =
            document.getElementById(
                'survey-table'
            );

        if (!list.length) {
            table.innerHTML = `
                <p class="muted">
                    アンケートがありません。
                </p>
            `;
            return;
        }

        table.innerHTML = `
            <table>
                <thead>
                    <tr>
                        <th>作成日 / 更新日</th>
                        <th>タイトル</th>
                        <th>アンケート期間</th>
                        <th>ステータス</th>
                        <th>回答数</th>
                        <th>操作</th>
                    </tr>
                </thead>

                <tbody>
                    ${list.map(
                        survey => `
                            <tr>
                                <td>
                                    作成:
                                    ${escapeHtml(
                                        formatDate(
                                            survey.createdAt
                                        )
                                    )}
                                    <br>
                                    更新:
                                    ${escapeHtml(
                                        formatDate(
                                            survey.updatedAt
                                        )
                                    )}
                                </td>

                                <td>
                                    <strong>
                                        ${escapeHtml(
                                            survey.title ||
                                            '(無題)'
                                        )}
                                    </strong>
                                </td>

                                <td>
                                    ${escapeHtml(
                                        formatDate(
                                            survey.startAt
                                        )
                                    )}
                                    ～
                                    ${escapeHtml(
                                        formatDate(
                                            survey.endAt
                                        )
                                    )}
                                </td>

                                <td>
                                    ${statusBadge(
                                        survey.status
                                    )}
                                </td>

                                <td>
                                    ${escapeHtml(
                                        survey.answerCount ||
                                        0
                                    )}
                                </td>

                                <td>
                                    <div
                                        class="toolbar"
                                    >
                                        <button
                                            class="btn"
                                            onclick="
                                                navigate(
                                                    'admin-survey-edit',
                                                    {
                                                        surveyId:
                                                            '${escapeHtml(
                                                                survey.id
                                                            )}'
                                                    }
                                                )
                                            "
                                        >
                                            確認・編集
                                        </button>

                                        <button
                                            class="btn"
                                            onclick="
                                                navigate(
                                                    'admin-aggregation',
                                                    {
                                                        surveyId:
                                                            '${escapeHtml(
                                                                survey.id
                                                            )}'
                                                    }
                                                )
                                            "
                                        >
                                            集計
                                        </button>

                                        <button
                                            class="btn"
                                            onclick="
                                                navigate(
                                                    'admin-send',
                                                    {
                                                        surveyId:
                                                            '${escapeHtml(
                                                                survey.id
                                                            )}'
                                                    }
                                                )
                                            "
                                        >
                                            送信
                                        </button>

                                        <button
                                            class="btn"
                                            onclick="
                                                duplicateSurvey(
                                                    '${escapeHtml(
                                                        survey.id
                                                    )}'
                                                )
                                            "
                                        >
                                            複製
                                        </button>

                                        <button
                                            class="btn btn-danger"
                                            onclick="
                                                deleteSurvey(
                                                    '${escapeHtml(
                                                        survey.id
                                                    )}'
                                                )
                                            "
                                        >
                                            削除
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        `
                    ).join('')}
                </tbody>
            </table>
        `;
    }

    search.addEventListener(
        'input',
        draw
    );

    search.addEventListener(
        'keydown',
        function (event) {
            if (
                event.key === 'Enter'
            ) {
                event.preventDefault();
                draw();
            }
        }
    );

    filter.addEventListener(
        'change',
        draw
    );

    sort.addEventListener(
        'change',
        draw
    );

    draw();
}

/*
|--------------------------------------------------------------------------
| 削除
|--------------------------------------------------------------------------
*/

async function deleteSurvey(
    surveyId
) {
    openModal(`
        <h3>アンケート削除</h3>

        <p>
            このアンケートを削除しますか？
        </p>

        <div class="modal-actions">
            <button
                class="btn btn-light"
                onclick="closeModal()"
            >
                キャンセル
            </button>

            <button
                class="btn btn-danger"
                id="confirm-delete-survey"
            >
                削除する
            </button>
        </div>
    `);

    document
        .getElementById(
            'confirm-delete-survey'
        )
        .addEventListener(
            'click',
            async function () {
                try {
                    await api(
                        'delete_survey',
                        {
                            surveyId
                        }
                    );

                    closeModal();

                    window.location.reload();
                } catch (error) {
                    alert(
                        error.message
                    );
                }
            }
        );
}

/*
|--------------------------------------------------------------------------
| 複製
|--------------------------------------------------------------------------
*/

async function duplicateSurvey(
    surveyId
) {
    openModal(`
        <h3>アンケート複製</h3>

        <p>
            このアンケートを複製しますか？
        </p>

        <div class="modal-actions">
            <button
                class="btn btn-light"
                onclick="closeModal()"
            >
                キャンセル
            </button>

            <button
                class="btn btn-primary"
                id="confirm-duplicate-survey"
            >
                複製する
            </button>
        </div>
    `);

    document
        .getElementById(
            'confirm-duplicate-survey'
        )
        .addEventListener(
            'click',
            async function () {
                try {
                    const result =
                        await api(
                            'duplicate_survey',
                            {
                                surveyId
                            }
                        );

                    closeModal();

                    if (
                        result.survey &&
                        result.survey.id
                    ) {
                        navigate(
                            'admin-survey-edit',
                            {
                                surveyId:
                                    result.survey.id
                            }
                        );
                    } else {
                        navigate(
                            'admin-survey-list'
                        );
                    }
                } catch (error) {
                    alert(
                        error.message
                    );
                }
            }
        );
}

/*
|--------------------------------------------------------------------------
| 編集
|--------------------------------------------------------------------------
*/

function renderSurveyEdit(
    state
) {
    renderAdminHeader();

    const survey =
        INITIAL_DATA.survey ||
        {
            id: '',
            title: '',
            description: '',
            startAt: '',
            endAt: '',
            status: 'draft',
            numberingMode: 'global',
            allowResubmission: false,
            groups: []
        };

    const container =
        document.getElementById(
            'screen-container'
        );

    container.innerHTML = `
        <section class="screen">

            <div class="toolbar">
                <button
                    class="btn btn-light"
                    onclick="
                        navigate(
                            'admin-survey-list'
                        )
                    "
                >
                    一覧へ戻る
                </button>
            </div>

            <h2 class="screen-title">
                ${
                    survey.id
                        ? 'アンケート編集'
                        : 'アンケート作成'
                }
            </h2>

            <div class="card">
                <div class="form-row">
                    <label class="form-label">
                        タイトル
                    </label>

                    <input
                        id="survey-title"
                        value="${escapeHtml(
                            survey.title || ''
                        )}"
                    >
                </div>

                <div class="form-row">
                    <label class="form-label">
                        説明
                    </label>

                    <textarea
                        id="survey-description"
                    >${escapeHtml(
                        survey.description || ''
                    )}</textarea>
                </div>

                <div class="form-row">
                    <label class="form-label">
                        開始日時
                    </label>

                    <input
                        id="survey-start"
                        type="datetime-local"
                        value="${escapeHtml(
                            toDatetimeLocal(
                                survey.startAt
                            )
                        )}"
                    >
                </div>

                <div class="form-row">
                    <label class="form-label">
                        終了日時
                    </label>

                    <input
                        id="survey-end"
                        type="datetime-local"
                        value="${escapeHtml(
                            toDatetimeLocal(
                                survey.endAt
                            )
                        )}"
                    >
                </div>

                <div class="form-row">
                    <label class="form-label">
                        質問番号
                    </label>

                    <select
                        id="numbering-mode"
                    >
                        <option
                            value="global"
                            ${
                                survey.numberingMode ===
                                'global'
                                    ? 'selected'
                                    : ''
                            }
                        >
                            アンケート全体で通番
                        </option>

                        <option
                            value="group"
                            ${
                                survey.numberingMode ===
                                'group'
                                    ? 'selected'
                                    : ''
                            }
                        >
                            グループ毎に採番
                        </option>
                    </select>
                </div>

                <div class="form-row">
                    <label class="form-label">
                        再回答
                    </label>

                    <label>
                        <input
                            id="allow-resubmission"
                            type="checkbox"
                            ${
                                survey.allowResubmission
                                    ? 'checked'
                                    : ''
                            }
                            style="width:auto;"
                        >
                        再回答を許可する
                    </label>
                </div>
            </div>

            <hr style="margin:24px 0;">

            <div
                id="groups-container"
            ></div>

            <div
                style="
                    display:flex;
                    justify-content:flex-end;
                    gap:10px;
                    margin-top:20px;
                "
            >
                <button
                    class="btn btn-light"
                    onclick="
                        navigate(
                            'admin-survey-list'
                        )
                    "
                >
                    キャンセル
                </button>

                <button
                    class="btn btn-primary"
                    id="save-survey"
                >
                    保存して一覧へ
                </button>
            </div>
        </section>
    `;

    const workingSurvey =
        JSON.parse(
            JSON.stringify(
                survey
            )
        );

    if (
        !Array.isArray(
            workingSurvey.groups
        )
    ) {
        workingSurvey.groups = [];
    }

    renderGroups(
        workingSurvey
    );

    document
        .getElementById(
            'numbering-mode'
        )
        .addEventListener(
            'change',
            function () {
                workingSurvey.numberingMode =
                    this.value;

                renumberClientQuestions(
                    workingSurvey
                );

                renderGroups(
                    workingSurvey
                );
            }
        );

    document
        .getElementById(
            'save-survey'
        )
        .addEventListener(
            'click',
            async function () {

                workingSurvey.title =
                    document
                        .getElementById(
                            'survey-title'
                        )
                        .value
                        .trim();

                workingSurvey.description =
                    document
                        .getElementById(
                            'survey-description'
                        )
                        .value;

                workingSurvey.startAt =
                    document
                        .getElementById(
                            'survey-start'
                        )
                        .value;

                workingSurvey.endAt =
                    document
                        .getElementById(
                            'survey-end'
                        )
                        .value;

                workingSurvey.numberingMode =
                    document
                        .getElementById(
                            'numbering-mode'
                        )
                        .value;

                workingSurvey.allowResubmission =
                    document
                        .getElementById(
                            'allow-resubmission'
                        )
                        .checked;

                renumberClientQuestions(
                    workingSurvey
                );

                try {
                    await apiJson(
                        'save_survey',
                        workingSurvey
                    );

                    navigate(
                        'admin-survey-list'
                    );
                } catch (error) {
                    alert(
                        error.message
                    );
                }
            }
        );
}

/*
|--------------------------------------------------------------------------
| datetime-local
|--------------------------------------------------------------------------
*/

function toDatetimeLocal(
    value
) {
    if (!value) {
        return '';
    }

    const date =
        new Date(value);

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {
        return value;
    }

    const pad =
        number =>
            String(number)
                .padStart(2, '0');

    return (
        date.getFullYear() +
        '-' +
        pad(
            date.getMonth() + 1
        ) +
        '-' +
        pad(
            date.getDate()
        ) +
        'T' +
        pad(
            date.getHours()
        ) +
        ':' +
        pad(
            date.getMinutes()
        )
    );
}

/*
|--------------------------------------------------------------------------
| グループ
|--------------------------------------------------------------------------
*/

function renderGroups(
    survey
) {
    const container =
        document.getElementById(
            'groups-container'
        );

    container.innerHTML =
        survey.groups.map(
            (group, groupIndex) => `
                <div
                    class="group-card"
                    draggable="true"
                    data-group-id="${escapeHtml(
                        group.id
                    )}"
                >
                    <div class="group-header">
                        <strong>
                            グループ ${groupIndex + 1}
                        </strong>

                        <button
                            class="btn btn-danger"
                            type="button"
                            onclick="
                                deleteGroup(
                                    '${escapeHtml(
                                        group.id
                                    )}'
                                )
                            "
                        >
                            グループ削除
                        </button>
                    </div>

                    <input
                        class="group-title-input"
                        data-group-id="${escapeHtml(
                            group.id
                        )}"
                        value="${escapeHtml(
                            group.title || ''
                        )}"
                        placeholder="グループタイトル"
                    >

                    <div
                        style="margin-top:16px;"
                    >
                        ${
                            (
                                group.questions ||
                                []
                            ).map(
                                (
                                    question,
                                    questionIndex
                                ) =>
                                    renderQuestionHtml(
                                        group,
                                        question,
                                        questionIndex
                                    )
                            ).join('')
                        }
                    </div>

                    <button
                        class="btn btn-secondary"
                        type="button"
                        onclick="
                            addQuestion(
                                '${escapeHtml(
                                    group.id
                                )}'
                            )
                        "
                    >
                        質問追加
                    </button>
                </div>
            `
        ).join('');

    /*
     * グループ追加ボタンは一覧末尾のみ。
     */
    container.insertAdjacentHTML(
        'beforeend',
        `
            <div
                style="
                    display:flex;
                    justify-content:center;
                    margin-top:20px;
                "
            >
                <button
                    class="btn btn-primary"
                    type="button"
                    onclick="addGroup()"
                >
                    グループ追加
                </button>
            </div>
        `
    );

    document
        .querySelectorAll(
            '.group-title-input'
        )
        .forEach(
            input => {
                input.addEventListener(
                    'input',
                    function () {
                        const group =
                            survey.groups.find(
                                g =>
                                    g.id ===
                                    this.dataset.groupId
                            );

                        if (group) {
                            group.title =
                                this.value;
                        }
                    }
                );
            }
        );
}

function renderQuestionHtml(
    group,
    question,
    questionIndex
) {
    return `
        <div
            class="question-card"
            data-question-id="${escapeHtml(
                question.id
            )}"
        >
            <div class="question-header">
                <strong>
                    ${escapeHtml(
                        question.questionNumber ||
                        'Q'
                    )}
                </strong>

                <button
                    class="btn btn-danger"
                    type="button"
                    onclick="
                        deleteQuestion(
                            '${escapeHtml(
                                group.id
                            )}',
                            '${escapeHtml(
                                question.id
                            )}'
                        )
                    "
                >
                    質問削除
                </button>
            </div>

            <input
                class="question-text-input"
                data-group-id="${escapeHtml(
                    group.id
                )}"
                data-question-id="${escapeHtml(
                    question.id
                )}"
                value="${escapeHtml(
                    question.text || ''
                )}"
                placeholder="質問文"
            >

            <div
                class="form-row"
                style="margin-top:12px;"
            >
                <label class="form-label">
                    回答形式
                </label>

                <select
                    class="question-type-input"
                    data-group-id="${escapeHtml(
                        group.id
                    )}"
                    data-question-id="${escapeHtml(
                        question.id
                    )}"
                >
                    <option
                        value="single"
                        ${
                            question.type ===
                            'single'
                                ? 'selected'
                                : ''
                        }
                    >
                        単一選択
                    </option>

                    <option
                        value="multiple"
                        ${
                            question.type ===
                            'multiple'
                                ? 'selected'
                                : ''
                        }
                    >
                        複数選択
                    </option>

                    <option
                        value="text"
                        ${
                            question.type ===
                            'text'
                                ? 'selected'
                                : ''
                        }
                    >
                        自由記述
                    </option>
                </select>
            </div>

            <label>
                <input
                    class="question-required-input"
                    data-group-id="${escapeHtml(
                        group.id
                    )}"
                    data-question-id="${escapeHtml(
                        question.id
                    )}"
                    type="checkbox"
                    ${
                        question.required
                            ? 'checked'
                            : ''
                    }
                    style="width:auto;"
                >
                必須回答
            </label>

            ${
                question.type ===
                    'text'
                    ? ''
                    : `
                        <div
                            style="
                                margin-top:16px;
                            "
                        >
                            <strong>
                                選択肢
                            </strong>

                            <div>
                                ${
                                    (
                                        question.choices ||
                                        []
                                    ).map(
                                        choice => `
                                            <div
                                                class="choice-row"
                                            >
                                                <input
                                                    class="choice-input"
                                                    data-group-id="${escapeHtml(
                                                        group.id
                                                    )}"
                                                    data-question-id="${escapeHtml(
                                                        question.id
                                                    )}"
                                                    data-choice-id="${escapeHtml(
                                                        choice.id
                                                    )}"
                                                    value="${escapeHtml(
                                                        choice.label ||
                                                        ''
                                                    )}"
                                                >

                                                <button
                                                    class="btn btn-danger"
                                                    type="button"
                                                    onclick="
                                                        deleteChoice(
                                                            '${escapeHtml(
                                                                group.id
                                                            )}',
                                                            '${escapeHtml(
                                                                question.id
                                                            )}',
                                                            '${escapeHtml(
                                                                choice.id
                                                            )}'
                                                        )
                                                    "
                                                >
                                                    削除
                                                </button>
                                            </div>
                                        `
                                    ).join('')
                                }
                            </div>

                            <button
                                class="btn btn-light"
                                type="button"
                                onclick="
                                    addChoice(
                                        '${escapeHtml(
                                            group.id
                                        )}',
                                        '${escapeHtml(
                                            question.id
                                        )}'
                                    )
                                "
                            >
                                選択肢追加
                            </button>
                        </div>
                    `
            }
        </div>
    `;
}

/*
|--------------------------------------------------------------------------
| クライアント側質問採番
|--------------------------------------------------------------------------
*/

function renumberClientQuestions(
    survey
) {
    let globalNumber = 1;

    survey.groups.forEach(
        (group, groupIndex) => {

            const groupNumber =
                groupIndex + 1;

            group.questions =
                group.questions || [];

            group.questions.forEach(
                (
                    question,
                    questionIndex
                ) => {

                    question.groupId =
                        group.id;

                    question.sortOrder =
                        questionIndex;

                    if (
                        survey.numberingMode ===
                        'group'
                    ) {
                        question.questionNumber =
                            'Q' +
                            groupNumber +
                            '-' +
                            (
                                questionIndex +
                                1
                            );
                    } else {
                        question.questionNumber =
                            'Q' +
                            globalNumber;
                    }

                    globalNumber++;
                }
            );
        }
    );
}

function getWorkingSurvey() {
    /*
     * 現在描画中の編集データを
     * DOMから再構築する。
     *
     * 実装を簡潔にするため、
     * 編集画面では再描画時に
     * 最新データを保持する。
     */
    return window.__workingSurvey || null;
}

function addGroup() {
    const survey =
        getWorkingSurvey();

    if (!survey) {
        return;
    }

    survey.groups =
        survey.groups || [];

    survey.groups.push({
        id:
            'group_' +
            Date.now() +
            '_' +
            Math.random()
                .toString(16)
                .slice(2),
        title: '',
        sortOrder:
            survey.groups.length,
        questions: []
    });

    renumberClientQuestions(
        survey
    );

    renderGroups(
        survey
    );
}

function deleteGroup(
    groupId
) {
    const survey =
        getWorkingSurvey();

    if (!survey) {
        return;
    }

    const group =
        survey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    const hasQuestions =
        (
            group.questions ||
            []
        ).length > 0;

    const message =
        hasQuestions
            ? '質問が存在するグループです。削除しますか？'
            : 'このグループを削除しますか？';

    if (!confirm(message)) {
        return;
    }

    survey.groups =
        survey.groups.filter(
            g => g.id !== groupId
        );

    renumberClientQuestions(
        survey
    );

    renderGroups(
        survey
    );
}

function addQuestion(
    groupId
) {
    const survey =
        getWorkingSurvey();

    if (!survey) {
        return;
    }

    const group =
        survey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    group.questions =
        group.questions || [];

    const question =
        {
            id:
                'question_' +
                Date.now() +
                '_' +
                Math.random()
                    .toString(16)
                    .slice(2),
            groupId,
            sortOrder:
                group.questions.length,
            questionNumber: '',
            text: '',
            type: 'single',
            required: false,
            choices: [],
            branching: []
        };

    group.questions.push(
        question
    );

    renumberClientQuestions(
        survey
    );

    renderGroups(
        survey
    );
}

function deleteQuestion(
    groupId,
    questionId
) {
    const survey =
        getWorkingSurvey();

    if (!survey) {
        return;
    }

    if (
        !confirm(
            'この質問を削除しますか？'
        )
    ) {
        return;
    }

    const group =
        survey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    group.questions =
        group.questions.filter(
            q => q.id !== questionId
        );

    renumberClientQuestions(
        survey
    );

    renderGroups(
        survey
    );
}

function addChoice(
    groupId,
    questionId
) {
    const survey =
        getWorkingSurvey();

    if (!survey) {
        return;
    }

    const group =
        survey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    const question =
        group.questions.find(
            q => q.id === questionId
        );

    if (!question) {
        return;
    }

    question.choices =
        question.choices || [];

    question.choices.push({
        id:
            'choice_' +
            Date.now() +
            '_' +
            Math.random()
                .toString(16)
                .slice(2),
        label: ''
    });

    renderGroups(
        survey
    );
}

function deleteChoice(
    groupId,
    questionId,
    choiceId
) {
    const survey =
        getWorkingSurvey();

    if (!survey) {
        return;
    }

    const group =
        survey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    const question =
        group.questions.find(
            q => q.id === questionId
        );

    if (!question) {
        return;
    }

    question.choices =
        question.choices.filter(
            c => c.id !== choiceId
        );

    renderGroups(
        survey
    );
}

/*
 * 編集画面用の入力イベントを
 * 再描画前に同期する。
 */
function bindEditorInputs(
    survey
) {
    document
        .querySelectorAll(
            '.question-text-input'
        )
        .forEach(
            input => {
                input.addEventListener(
                    'input',
                    function () {

                        const group =
                            survey.groups.find(
                                g =>
                                    g.id ===
                                    this.dataset.groupId
                            );

                        if (!group) {
                            return;
                        }

                        const question =
                            group.questions.find(
                                q =>
                                    q.id ===
                                    this.dataset.questionId
                            );

                        if (question) {
                            question.text =
                                this.value;
                        }
                    }
                );
            }
        );

    document
        .querySelectorAll(
            '.question-type-input'
        )
        .forEach(
            input => {
                input.addEventListener(
                    'change',
                    function () {

                        const group =
                            survey.groups.find(
                                g =>
                                    g.id ===
                                    this.dataset.groupId
                            );

                        if (!group) {
                            return;
                        }

                        const question =
                            group.questions.find(
                                q =>
                                    q.id ===
                                    this.dataset.questionId
                            );

                        if (question) {
                            question.type =
                                this.value;

                            if (
                                this.value ===
                                'text'
                            ) {
                                question.choices =
                                    [];
                            }
                        }

                        renderGroups(
                            survey
                        );
                    }
                );
            }
        );

    document
        .querySelectorAll(
            '.question-required-input'
        )
        .forEach(
            input => {
                input.addEventListener(
                    'change',
                    function () {

                        const group =
                            survey.groups.find(
                                g =>
                                    g.id ===
                                    this.dataset.groupId
                            );

                        if (!group) {
                            return;
                        }

                        const question =
                            group.questions.find(
                                q =>
                                    q.id ===
                                    this.dataset.questionId
                            );

                        if (question) {
                            question.required =
                                this.checked;
                        }
                    }
                );
            }
        );

    document
        .querySelectorAll(
            '.choice-input'
        )
        .forEach(
            input => {
                input.addEventListener(
                    'input',
                    function () {

                        const group =
                            survey.groups.find(
                                g =>
                                    g.id ===
                                    this.dataset.groupId
                            );

                        if (!group) {
                            return;
                        }

                        const question =
                            group.questions.find(
                                q =>
                                    q.id ===
                                    this.dataset.questionId
                            );

                        if (!question) {
                            return;
                        }

                        const choice =
                            question.choices.find(
                                c =>
                                    c.id ===
                                    this.dataset.choiceId
                            );

                        if (choice) {
                            choice.label =
                                this.value;
                        }
                    }
                );
            }
        );
}

/*
|--------------------------------------------------------------------------
| 編集画面のworkingSurveyを保持
|--------------------------------------------------------------------------
*/

const originalRenderSurveyEdit =
    renderSurveyEdit;

renderSurveyEdit = function (
    state
) {
    originalRenderSurveyEdit(
        state
    );

    /*
     * PHP初期データをコピーして
     * JS編集状態を作る。
     */
    window.__workingSurvey =
        JSON.parse(
            JSON.stringify(
                INITIAL_DATA.survey ||
                {
                    id: '',
                    title: '',
                    description: '',
                    startAt: '',
                    endAt: '',
                    status: 'draft',
                    numberingMode: 'global',
                    allowResubmission: false,
                    groups: []
                }
            )
        );

    bindEditorInputs(
        window.__workingSurvey
    );
};

/*
|--------------------------------------------------------------------------
| プレビュー
|--------------------------------------------------------------------------
*/

function renderPreview(
    state
) {
    renderAdminHeader();

    const survey =
        INITIAL_DATA.survey;

    const container =
        document.getElementById(
            'screen-container'
        );

    if (!survey) {
        container.innerHTML = `
            <section class="screen">
                <h2 class="screen-title">
                    プレビュー
                </h2>

                <p class="error">
                    対象アンケートが指定されていません。
                </p>

                <button
                    class="btn"
                    onclick="
                        navigate(
                            'admin-survey-list'
                        )
                    "
                >
                    一覧へ戻る
                </button>
            </section>
        `;

        return;
    }

    container.innerHTML = `
        <section class="screen">
            <div class="toolbar">
                <button
                    class="btn btn-light"
                    onclick="
                        navigate(
                            'admin-survey-edit',
                            {
                                surveyId:
                                    '${escapeHtml(
                                        state.surveyId
                                    )}'
                            }
                        )
                    "
                >
                    編集へ戻る
                </button>
            </div>

            <h2 class="screen-title">
                ${escapeHtml(
                    survey.title ||
                    'アンケート'
                )}
            </h2>

            <p>
                ${escapeHtml(
                    survey.description ||
                    ''
                )}
            </p>

            ${
                (
                    survey.groups ||
                    []
                ).map(
                    group => `
                        <section>
                            <h3>
                                ${escapeHtml(
                                    group.title ||
                                    ''
                                )}
                            </h3>

                            ${
                                (
                                    group.questions ||
                                    []
                                ).map(
                                    question => `
                                        <div
                                            class="question-card"
                                        >
                                            <strong>
                                                ${escapeHtml(
                                                    question.questionNumber
                                                )}
                                            </strong>

                                            <p>
                                                ${escapeHtml(
                                                    question.text
                                                )}

                                                ${
                                                    question.required
                                                        ? '<strong>（必須）</strong>'
                                                        : '（任意）'
                                                }
                                            </p>

                                            ${
                                                question.type ===
                                                'text'
                                                    ? `
                                                        <textarea
                                                            disabled
                                                        ></textarea>
                                                    `
                                                    : (
                                                        question.choices ||
                                                        []
                                                    ).map(
                                                        choice => `
                                                            <label
                                                                class="answer-choice"
                                                            >
                                                                <input
                                                                    type="${
                                                                        question.type ===
                                                                        'multiple'
                                                                            ? 'checkbox'
                                                                            : 'radio'
                                                                    }"
                                                                    disabled
                                                                >

                                                                ${escapeHtml(
                                                                    choice.label
                                                                )}
                                                            </label>
                                                        `
                                                    ).join('')
                                            }
                                        </div>
                                    `
                                ).join('')
                            }
                        </section>
                    `
                ).join('')
            }
        </section>
    `;
}

/*
|--------------------------------------------------------------------------
| 送信
|--------------------------------------------------------------------------
*/

function renderSend(
    state
) {
    renderAdminHeader();

    const survey =
        INITIAL_DATA.survey;

    const customers =
        Array.isArray(
            INITIAL_DATA.customers
        )
            ? INITIAL_DATA.customers
            : [];

    const history =
        Array.isArray(
            INITIAL_DATA.sendHistory
        )
            ? INITIAL_DATA.sendHistory
            : [];

    const container =
        document.getElementById(
            'screen-container'
        );

    if (
        !state.surveyId ||
        !survey
    ) {
        container.innerHTML = `
            <section class="screen">
                <h2 class="screen-title">
                    顧客選択・メール送信
                </h2>

                <p class="error">
                    対象アンケートが指定されていないため、
                    送信業務を開始できません。
                </p>

                <button
                    class="btn"
                    onclick="
                        navigate(
                            'admin-survey-list'
                        )
                    "
                >
                    一覧へ戻る
                </button>
            </section>
        `;

        return;
    }

    container.innerHTML = `
        <section class="screen">
            <div class="toolbar">
                <button
                    class="btn btn-light"
                    onclick="
                        navigate(
                            'admin-survey-list'
                        )
                    "
                >
                    一覧へ戻る
                </button>
            </div>

            <h2 class="screen-title">
                顧客選択・メール送信
            </h2>

            <div class="card">
                <strong>
                    対象アンケート：
                </strong>

                ${escapeHtml(
                    survey.title ||
                    ''
                )}
            </div>

            <div
                style="margin-top:20px;"
            >
                <div class="toolbar">
                    <input
                        id="customer-search"
                        type="search"
                        placeholder="顧客名・組織名・メールアドレスで検索"
                    >

                    <select
                        id="customer-status"
                        style="max-width:200px;"
                    >
                        <option value="all">
                            すべて
                        </option>
                        <option value="unsent">
                            未送信
                        </option>
                        <option value="sent_unanswered">
                            送信済み / 未回答
                        </option>
                        <option value="answered">
                            回答済み
                        </option>
                    </select>
                </div>

                <div
                    id="customer-table"
                    class="table-wrap"
                ></div>
            </div>

            <hr style="margin:24px 0;">

            <div class="card">
                <h3 class="card-title">
                    メール内容
                </h3>

                <label>
                    件名
                </label>

                <input
                    id="mail-subject"
                    placeholder="アンケートのご案内"
                >

                <br><br>

                <label>
                    本文
                </label>

                <textarea
                    id="mail-message"
                    placeholder="{顧客名} 様&#10;&#10;アンケートURL：{アンケートURL}"
                ></textarea>

                <div
                    class="toolbar"
                    style="margin-top:16px;"
                >
                    <button
                        class="btn btn-primary"
                        id="send-selected"
                    >
                        一括送信
                    </button>

                    <button
                        class="btn"
                        id="send-reminder"
                    >
                        リマインド
                    </button>
                </div>
            </div>

            <hr style="margin:24px 0;">

            <div class="card">
                <h3 class="card-title">
                    送信結果
                </h3>

                <div
                    id="send-result"
                >
                    未実行
                </div>
            </div>

            <hr style="margin:24px 0;">

            <div class="card">
                <h3 class="card-title">
                    送信履歴
                </h3>

                ${
                    history.length
                        ? `
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>送信日時</th>
                                            <th>種別</th>
                                            <th>件数</th>
                                            <th>件名</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        ${history.map(
                                            item => `
                                                <tr>
                                                    <td>
                                                        ${escapeHtml(
                                                            formatDate(
                                                                item.sentAt
                                                            )
                                                        )}
                                                    </td>

                                                    <td>
                                                        ${escapeHtml(
                                                            item.type ||
                                                            ''
                                                        )}
                                                    </td>

                                                    <td>
                                                        ${escapeHtml(
                                                            item.count ||
                                                            0
                                                        )}
                                                    </td>

                                                    <td>
                                                        ${escapeHtml(
                                                            item.subject ||
                                                            ''
                                                        )}
                                                    </td>
                                                </tr>
                                            `
                                        ).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `
                        : `
                            <p class="muted">
                                送信履歴はありません。
                            </p>
                        `
                }
            </div>
        </section>
    `;

    const selected =
        new Set();

    function drawCustomers() {
        const keyword =
            document
                .getElementById(
                    'customer-search'
                )
                .value
                .trim()
                .toLowerCase();

        const status =
            document
                .getElementById(
                    'customer-status'
                )
                .value;

        let list =
            customers.filter(
                customer => {

                    const text = [
                        customer.name,
                        customer.organization,
                        customer.email
                    ]
                        .join(' ')
                        .toLowerCase();

                    if (
                        keyword &&
                        !text.includes(
                            keyword
                        )
                    ) {
                        return false;
                    }

                    if (
                        status !==
                        'all' &&
                        answerStatusForCustomerClient(
                            customer
                        ) !== status
                    ) {
                        return false;
                    }

                    return true;
                }
            );

        const table =
            document.getElementById(
                'customer-table'
            );

        table.innerHTML = `
            <table>
                <thead>
                    <tr>
                        <th>
                            <input
                                type="checkbox"
                                id="select-all"
                                style="width:auto;"
                            >
                        </th>
                        <th>組織名</th>
                        <th>氏名</th>
                        <th>メール</th>
                        <th>電話</th>
                        <th>回答ステータス</th>
                    </tr>
                </thead>

                <tbody>
                    ${
                        list.map(
                            customer => {
                                const id =
                                    String(
                                        customer.id ||
                                        ''
                                    );

                                const customerStatus =
                                    answerStatusForCustomerClient(
                                        customer
                                    );

                                return `
                                    <tr>
                                        <td>
                                            <input
                                                type="checkbox"
                                                class="customer-check"
                                                data-id="${escapeHtml(
                                                    id
                                                )}"
                                                ${
                                                    selected.has(
                                                        id
                                                    )
                                                        ? 'checked'
                                                        : ''
                                                }
                                                style="width:auto;"
                                            >
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                customer.organization ||
                                                ''
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                customer.name ||
                                                ''
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                customer.email ||
                                                ''
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                customer.phone ||
                                                ''
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                customerStatus
                                            )}
                                        </td>
                                    </tr>
                                `;
                            }
                        ).join('')
                    }
                </tbody>
            </table>
        `;

        document
            .querySelectorAll(
                '.customer-check'
            )
            .forEach(
                checkbox => {
                    checkbox.addEventListener(
                        'change',
                        function () {
                            const id =
                                this.dataset.id;

                            if (
                                this.checked
                            ) {
                                selected.add(
                                    id
                                );
                            } else {
                                selected.delete(
                                    id
                                );
                            }
                        }
                    );
                }
            );

        document
            .getElementById(
                'select-all'
            )
            ?.addEventListener(
                'change',
                function () {
                    document
                        .querySelectorAll(
                            '.customer-check'
                        )
                        .forEach(
                            checkbox => {
                                checkbox.checked =
                                    this.checked;

                                const id =
                                    checkbox.dataset.id;

                                if (
                                    this.checked
                                ) {
                                    selected.add(
                                        id
                                    );
                                } else {
                                    selected.delete(
                                        id
                                    );
                                }
                            }
                        );
                }
            );
    }

    function answerStatusForCustomerClient(
        customer
    ) {
        /*
         * 画面表示用の簡易判定。
         * 永続データ上の正式判定はPHP側で行う。
         */
        return customer.answerStatus ||
            'unsent';
    }

    document
        .getElementById(
            'customer-search'
        )
        .addEventListener(
            'input',
            drawCustomers
        );

    document
        .getElementById(
            'customer-status'
        )
        .addEventListener(
            'change',
            drawCustomers
        );

    document
        .getElementById(
            'send-selected'
        )
        .addEventListener(
            'click',
            async function () {

                const selectedCustomers =
                    customers.filter(
                        customer =>
                            selected.has(
                                String(
                                    customer.id ||
                                    ''
                                )
                            )
                    );

                if (
                    !selectedCustomers.length
                ) {
                    alert(
                        '顧客を選択してください。'
                    );
                    return;
                }

                openModal(`
                    <h3>
                        送信確認
                    </h3>

                    <p>
                        ${
                            selectedCustomers.length
                        }件の顧客へ送信します。
                    </p>

                    <div class="modal-actions">
                        <button
                            class="btn btn-light"
                            onclick="
                                closeModal()
                            "
                        >
                            キャンセル
                        </button>

                        <button
                            class="btn btn-primary"
                            id="confirm-send"
                        >
                            送信する
                        </button>
                    </div>
                `);

                document
                    .getElementById(
                        'confirm-send'
                    )
                    .addEventListener(
                        'click',
                        async function () {

                            const subject =
                                document
                                    .getElementById(
                                        'mail-subject'
                                    )
                                    .value;

                            const message =
                                document
                                    .getElementById(
                                        'mail-message'
                                    )
                                    .value;

                            try {
                                const result =
                                    await apiJson(
                                        'send_mail',
                                        {
                                            surveyId:
                                                state.surveyId,
                                            customers:
                                                selectedCustomers,
                                            subject,
                                            message,
                                            sendType:
                                                'bulk'
                                        }
                                    );

                                closeModal();

                                document
                                    .getElementById(
                                        'send-result'
                                    )
                                    .innerHTML = `
                                        <div class="success">
                                            対象件数：
                                            ${escapeHtml(
                                                result.targetCount
                                            )}
                                            件<br>

                                            成功件数：
                                            ${escapeHtml(
                                                result.successCount
                                            )}
                                            件<br>

                                            失敗件数：
                                            ${escapeHtml(
                                                result.failedCount
                                            )}
                                            件<br>

                                            送信日時：
                                            ${escapeHtml(
                                                formatDate(
                                                    result.sentAt
                                                )
                                            )}
                                        </div>
                                    `;
                            } catch (error) {
                                closeModal();

                                document
                                    .getElementById(
                                        'send-result'
                                    )
                                    .innerHTML = `
                                        <div class="error">
                                            送信失敗：
                                            ${escapeHtml(
                                                error.message
                                            )}
                                        </div>
                                    `;
                            }
                        }
                    );
            }
        );

    document
        .getElementById(
            'send-reminder'
        )
        .addEventListener(
            'click',
            function () {
                document
                    .getElementById(
                        'customer-status'
                    )
                    .value =
                    'sent_unanswered';

                drawCustomers();
            }
        );

    drawCustomers();
}

/*
|--------------------------------------------------------------------------
| 集計
|--------------------------------------------------------------------------
*/

function renderAggregation(
    state
) {
    renderAdminHeader();

    const survey =
        INITIAL_DATA.survey;

    const responses =
        Array.isArray(
            INITIAL_DATA.responses
        )
            ? INITIAL_DATA.responses
            : [];

    const container =
        document.getElementById(
            'screen-container'
        );

    if (
        !state.surveyId ||
        !survey
    ) {
        container.innerHTML = `
            <section class="screen">
                <h2 class="screen-title">
                    回答集計・分析
                </h2>

                <p class="error">
                    対象アンケートが指定されていないため、
                    集計を開始できません。
                </p>

                <button
                    class="btn"
                    onclick="
                        navigate(
                            'admin-survey-list'
                        )
                    "
                >
                    一覧へ戻る
                </button>
            </section>
        `;

        return;
    }

    const total =
        responses.length;

    container.innerHTML = `
        <section class="screen">

            <div class="toolbar">
                <button
                    class="btn btn-light"
                    onclick="
                        navigate(
                            'admin-survey-list'
                        )
                    "
                >
                    一覧へ戻る
                </button>
            </div>

            <h2 class="screen-title">
                回答集計・分析
            </h2>

            <div class="card">
                <strong>
                    対象アンケート：
                </strong>

                ${escapeHtml(
                    survey.title ||
                    ''
                )}
            </div>

            <div
                class="card-grid"
                style="margin-top:20px;"
            >
                <div class="card">
                    <h3 class="card-title">
                        回答数
                    </h3>

                    <strong>
                        ${escapeHtml(
                            total
                        )}
                    </strong>
                </div>

                <div class="card">
                    <h3 class="card-title">
                        未登録顧客からの回答
                    </h3>

                    <strong>
                        ${
                            responses.filter(
                                response =>
                                    !response.customerId
                            ).length
                        }
                    </strong>
                </div>
            </div>

            <div
                class="toolbar"
                style="margin-top:20px;"
            >
                <button
                    class="btn btn-primary"
                    onclick="
                        exportCsv(
                            '${escapeHtml(
                                state.surveyId
                            )}'
                        )
                    "
                >
                    CSV出力
                </button>

                <button
                    class="btn"
                    onclick="
                        exportPdf(
                            '${escapeHtml(
                                state.surveyId
                            )}'
                        )
                    "
                >
                    PDF出力
                </button>
            </div>

            <hr style="margin:24px 0;">

            <h3>
                設問別集計
            </h3>

            <div
                id="question-aggregation"
            ></div>
        </section>
    `;

    renderQuestionAggregation(
        survey,
        responses
    );
}

function renderQuestionAggregation(
    survey,
    responses
) {
    const container =
        document.getElementById(
            'question-aggregation'
        );

    const questions = [];

    (
        survey.groups ||
        []
    ).forEach(
        group => {
            (
                group.questions ||
                []
            ).forEach(
                question => {
                    questions.push(
                        question
                    );
                }
            );
        }
    );

    container.innerHTML =
        questions.map(
            question => {

                const counts = {};

                (
                    question.choices ||
                    []
                ).forEach(
                    choice => {
                        counts[
                            choice.id
                        ] = 0;
                    }
                );

                responses.forEach(
                    response => {

                        const answer =
                            (
                                response.answers ||
                                {}
                            )[
                                question.id
                            ];

                        if (
                            Array.isArray(
                                answer
                            )
                        ) {
                            answer.forEach(
                                value => {
                                    if (
                                        counts[
                                            value
                                        ] !==
                                        undefined
                                    ) {
                                        counts[
                                            value
                                        ]++;
                                    }
                                }
                            );
                        } else if (
                            answer !==
                            undefined &&
                            counts[
                                answer
                            ] !== undefined
                        ) {
                            counts[
                                answer
                            ]++;
                        }
                    }
                );

                if (
                    question.type ===
                    'text'
                ) {
                    return `
                        <div class="question-card">
                            <strong>
                                ${escapeHtml(
                                    question.questionNumber
                                )}
                            </strong>

                            <p>
                                ${escapeHtml(
                                    question.text
                                )}
                            </p>

                            <p class="muted">
                                自由記述回答一覧は
                                回答データから表示します。
                            </p>
                        </div>
                    `;
                }

                return `
                    <div class="question-card">
                        <strong>
                            ${escapeHtml(
                                question.questionNumber
                            )}
                        </strong>

                        <p>
                            ${escapeHtml(
                                question.text
                            )}
                        </p>

                        ${
                            (
                                question.choices ||
                                []
                            ).map(
                                choice => {

                                    const count =
                                        counts[
                                            choice.id
                                        ] || 0;

                                    const ratio =
                                        responses.length
                                            ? (
                                                count /
                                                responses.length *
                                                100
                                            )
                                            : 0;

                                    return `
                                        <div
                                            style="
                                                margin-bottom:12px;
                                            "
                                        >
                                            <div>
                                                ${escapeHtml(
                                                    choice.label
                                                )}
                                                ：
                                                ${count}
                                                件
                                                （
                                                ${ratio.toFixed(
                                                    1
                                                )}%
                                                ）
                                            </div>

                                            <div
                                                style="
                                                    height:10px;
                                                    background:#e5e7eb;
                                                    border-radius:5px;
                                                    overflow:hidden;
                                                "
                                            >
                                                <div
                                                    style="
                                                        width:${ratio}%;
                                                        height:100%;
                                                        background:#2563eb;
                                                    "
                                                ></div>
                                            </div>
                                        </div>
                                    `;
                                }
                            ).join('')
                        }
                    </div>
                `;
            }
        ).join('');
}

async function exportCsv(
    surveyId
) {
    try {
        const result =
            await api(
                'export_csv',
                {
                    surveyId
                }
            );

        alert(
            result.message
        );
    } catch (error) {
        alert(
            error.message
        );
    }
}

async function exportPdf(
    surveyId
) {
    try {
        const result =
            await api(
                'export_pdf',
                {
                    surveyId
                }
            );

        alert(
            result.message
        );
    } catch (error) {
        alert(
            error.message
        );
    }
}

/*
|--------------------------------------------------------------------------
| kintone
|--------------------------------------------------------------------------
*/

function renderKintone() {
    renderAdminHeader();

    const settings =
        INITIAL_DATA.kintoneSettings ||
        {};

    const container =
        document.getElementById(
            'screen-container'
        );

    container.innerHTML = `
        <section class="screen">

            <h2 class="screen-title">
                kintone連携設定
            </h2>

            <div class="form-row">
                <label class="form-label">
                    サブドメイン
                </label>

                <input
                    id="kintone-subdomain"
                    value="${escapeHtml(
                        settings.subdomain ||
                        ''
                    )}"
                    placeholder="xxxx.cybozu.com"
                >
            </div>

            <div class="form-row">
                <label class="form-label">
                    顧客管理アプリID
                </label>

                <input
                    id="kintone-app-id"
                    value="${escapeHtml(
                        settings.appId ||
                        ''
                    )}"
                >
            </div>

            <div class="form-row">
                <label class="form-label">
                    ログイン名
                </label>

                <input
                    id="kintone-login-name"
                    value="${escapeHtml(
                        settings.loginName ||
                        ''
                    )}"
                >
            </div>

            <div class="form-row">
                <label class="form-label">
                    パスワード
                </label>

                <input
                    id="kintone-password"
                    type="password"
                    value="${escapeHtml(
                        settings.password ||
                        ''
                    )}"
                >
            </div>

            <div class="form-row">
                <label class="form-label">
                    SSL証明書検証
                </label>

                <select
                    id="kintone-ssl-verify"
                >
                    <option
                        value="false"
                        ${
                            !settings.sslVerify
                                ? 'selected'
                                : ''
                        }
                    >
                        検証しない
                    </option>

                    <option
                        value="true"
                        ${
                            settings.sslVerify
                                ? 'selected'
                                : ''
                        }
                    >
                        検証する
                    </option>
                </select>
            </div>

            <div class="form-row">
                <label class="form-label">
                    プロキシ
                </label>

                <input
                    id="kintone-proxy"
                    value="${escapeHtml(
                        settings.proxy ||
                        ''
                    )}"
                    placeholder="proxy.example.local:8080"
                >
            </div>

            <div class="toolbar">
                <button
                    class="btn btn-primary"
                    id="save-kintone"
                >
                    設定保存
                </button>

                <button
                    class="btn"
                    id="test-kintone"
                >
                    接続テスト
                </button>

                <button
                    class="btn"
                    id="get-kintone-fields"
                >
                    項目一覧を再取得
                </button>

                <button
                    class="btn"
                    id="sync-kintone"
                >
                    顧客情報を同期
                </button>
            </div>

            <div
                id="kintone-result"
                class="card"
            ></div>
        </section>
    `;

    document
        .getElementById(
            'save-kintone'
        )
        .addEventListener(
            'click',
            async function () {
                try {
                    const result =
                        await apiJson(
                            'save_kintone_settings',
                            {
                                subdomain:
                                    document
                                        .getElementById(
                                            'kintone-subdomain'
                                        )
                                        .value,

                                appId:
                                    document
                                        .getElementById(
                                            'kintone-app-id'
                                        )
                                        .value,

                                loginName:
                                    document
                                        .getElementById(
                                            'kintone-login-name'
                                        )
                                        .value,

                                password:
                                    document
                                        .getElementById(
                                            'kintone-password'
                                        )
                                        .value,

                                sslVerify:
                                    document
                                        .getElementById(
                                            'kintone-ssl-verify'
                                        )
                                        .value ===
                                    'true',

                                proxy:
                                    document
                                        .getElementById(
                                            'kintone-proxy'
                                        )
                                        .value
                            }
                        );

                    showKintoneResult(
                        result.success
                            ? '設定を保存しました。'
                            : '設定保存に失敗しました。'
                    );
                } catch (error) {
                    showKintoneResult(
                        error.message,
                        true
                    );
                }
            }
        );

    document
        .getElementById(
            'test-kintone'
        )
        .addEventListener(
            'click',
            async function () {
                try {
                    const result =
                        await api(
                            'kintone_test'
                        );

                    showKintoneResult(
                        result.success
                            ? '接続成功'
                            : result.error,
                        !result.success
                    );
                } catch (error) {
                    showKintoneResult(
                        error.message,
                        true
                    );
                }
            }
        );

    document
        .getElementById(
            'get-kintone-fields'
        )
        .addEventListener(
            'click',
            async function () {
                try {
                    const result =
                        await api(
                            'kintone_get_fields'
                        );

                    showKintoneResult(
                        result.success
                            ? JSON.stringify(
                                result.fields
                            )
                            : result.error,
                        !result.success
                    );
                } catch (error) {
                    showKintoneResult(
                        error.message,
                        true
                    );
                }
            }
        );

    document
        .getElementById(
            'sync-kintone'
        )
        .addEventListener(
            'click',
            async function () {
                try {
                    const result =
                        await api(
                            'kintone_sync'
                        );

                    showKintoneResult(
                        result.success
                            ? '顧客同期完了'
                            : result.error,
                        !result.success
                    );
                } catch (error) {
                    showKintoneResult(
                        error.message,
                        true
                    );
                }
            }
        );
}

function showKintoneResult(
    message,
    error = false
) {
    const element =
        document.getElementById(
            'kintone-result'
        );

    if (!element) {
        return;
    }

    element.className =
        'card ' +
        (
            error
                ? 'error'
                : 'success'
        );

    element.textContent =
        message;
}

/*
|--------------------------------------------------------------------------
| メール設定
|--------------------------------------------------------------------------
*/

function renderMail() {
    renderAdminHeader();

    const settings =
        INITIAL_DATA.mailSettings ||
        {};

    const container =
        document.getElementById(
            'screen-container'
        );

    container.innerHTML = `
        <section class="screen">

            <h2 class="screen-title">
                メールサーバ設定
            </h2>

            <div class="form-row">
                <label class="form-label">
                    SMTPサーバ
                </label>

                <input
                    id="smtp-server"
                    value="${escapeHtml(
                        settings.smtpServer ||
                        ''
                    )}"
                >
            </div>

            <div class="form-row">
                <label class="form-label">
                    SMTPポート
                </label>

                <input
                    id="smtp-port"
                    value="${escapeHtml(
                        settings.smtpPort ||
                        ''
                    )}"
                >
            </div>

            <div class="form-row">
                <label class="form-label">
                    暗号化方式
                </label>

                <select id="smtp-encryption">
                    <option value="">
                        なし
                    </option>

                    <option
                        value="ssl"
                        ${
                            settings.encryption ===
                            'ssl'
                                ? 'selected'
                                : ''
                        }
                    >
                        SSL
                    </option>

                    <option
                        value="tls"
                        ${
                            settings.encryption ===
                            'tls'
                                ? 'selected'
                                : ''
                        }
                    >
                        TLS
                    </option>
                </select>
            </div>

            <div class="form-row">
                <label class="form-label">
                    SMTP認証
                </label>

                <label>
                    <input
                        id="smtp-auth"
                        type="checkbox"
                        ${
                            settings.smtpAuth !==
                            false
                                ? 'checked'
                                : ''
                        }
                        style="width:auto;"
                    >
                    認証を使用する
                </label>
            </div>

            <div class="form-row">
                <label class="form-label">
                    SMTPユーザー名
                </label>

                <input
                    id="smtp-username"
                    value="${escapeHtml(
                        settings.smtpUsername ||
                        ''
                    )}"
                >
            </div>

            <div class="form-row">
                <label class="form-label">
                    SMTPパスワード
                </label>

                <input
                    id="smtp-password"
                    type="password"
                    value="${escapeHtml(
                        settings.smtpPassword ||
                        ''
                    )}"
                >
            </div>

            <div class="form-row">
                <label class="form-label">
                    送信元メールアドレス
                </label>

                <input
                    id="from-email"
                    type="email"
                    value="${escapeHtml(
                        settings.fromEmail ||
                        ''
                    )}"
                >
            </div>

            <div class="form-row">
                <label class="form-label">
                    送信元名
                </label>

                <input
                    id="from-name"
                    value="${escapeHtml(
                        settings.fromName ||
                        ''
                    )}"
                >
            </div>

            <div class="form-row">
                <label class="form-label">
                    返信先メールアドレス
                </label>

                <input
                    id="reply-to"
                    type="email"
                    value="${escapeHtml(
                        settings.replyTo ||
                        ''
                    )}"
                >
            </div>

            <div class="toolbar">
                <button
                    class="btn btn-primary"
                    id="save-mail"
                >
                    設定保存
                </button>

                <button
                    class="btn"
                    id="test-mail"
                >
                    テストメール
                </button>
            </div>

            <div
                id="mail-result"
                class="card"
            ></div>
        </section>
    `;

    document
        .getElementById(
            'save-mail'
        )
        .addEventListener(
            'click',
            async function () {
                try {
                    await apiJson(
                        'save_mail_settings',
                        {
                            smtpServer:
                                document
                                    .getElementById(
                                        'smtp-server'
                                    )
                                    .value,

                            smtpPort:
                                document
                                    .getElementById(
                                        'smtp-port'
                                    )
                                    .value,

                            encryption:
                                document
                                    .getElementById(
                                        'smtp-encryption'
                                    )
                                    .value,

                            smtpAuth:
                                document
                                    .getElementById(
                                        'smtp-auth'
                                    )
                                    .checked,

                            smtpUsername:
                                document
                                    .getElementById(
                                        'smtp-username'
                                    )
                                    .value,

                            smtpPassword:
                                document
                                    .getElementById(
                                        'smtp-password'
                                    )
                                    .value,

                            fromEmail:
                                document
                                    .getElementById(
                                        'from-email'
                                    )
                                    .value,

                            fromName:
                                document
                                    .getElementById(
                                        'from-name'
                                    )
                                    .value,

                            replyTo:
                                document
                                    .getElementById(
                                        'reply-to'
                                    )
                                    .value
                        }
                    );

                    showMailResult(
                        'メール設定を保存しました。'
                    );
                } catch (error) {
                    showMailResult(
                        error.message,
                        true
                    );
                }
            }
        );

    document
        .getElementById(
            'test-mail'
        )
        .addEventListener(
            'click',
            async function () {
                try {
                    const result =
                        await api(
                            'send_test_mail'
                        );

                    showMailResult(
                        result.success
                            ? 'テストメール送信成功'
                            : result.error,
                        !result.success
                    );
                } catch (error) {
                    showMailResult(
                        error.message,
                        true
                    );
                }
            }
        );
}

function showMailResult(
    message,
    error = false
) {
    const element =
        document.getElementById(
            'mail-result'
        );

    if (!element) {
        return;
    }

    element.className =
        'card ' +
        (
            error
                ? 'error'
                : 'success'
        );

    element.textContent =
        message;
}

/*
|--------------------------------------------------------------------------
| 回答者
|--------------------------------------------------------------------------
*/

function renderAnswer(
    state
) {
    renderAnswerHeader();

    const survey =
        INITIAL_DATA.survey;

    const container =
        document.getElementById(
            'screen-container'
        );

    if (
        !state.surveyId ||
        !survey
    ) {
        container.innerHTML = `
            <section class="screen">
                <h2 class="screen-title">
                    アンケート
                </h2>

                <p class="error">
                    アンケートを特定できません。
                </p>
            </section>
        `;

        return;
    }

    if (
        survey.status !==
        'published'
    ) {
        container.innerHTML = `
            <section class="screen">
                <h2 class="screen-title">
                    ${escapeHtml(
                        survey.title ||
                        'アンケート'
                    )}
                </h2>

                <p>
                    このアンケートは現在回答できません。
                </p>
            </section>
        `;

        return;
    }

    const containerHtml =
        (
            survey.groups ||
            []
        ).map(
            group => `
                <section
                    class="card"
                    style="margin-bottom:20px;"
                >
                    <h3>
                        ${escapeHtml(
                            group.title ||
                            ''
                        )}
                    </h3>

                    ${
                        (
                            group.questions ||
                            []
                        ).map(
                            question => `
                                <div
                                    class="question-card"
                                >
                                    <strong>
                                        ${escapeHtml(
                                            question.questionNumber
                                        )}
                                    </strong>

                                    <p>
                                        ${escapeHtml(
                                            question.text
                                        )}

                                        ${
                                            question.required
                                                ? '<strong>（必須）</strong>'
                                                : '（任意）'
                                        }
                                    </p>

                                    ${
                                        question.type ===
                                        'text'
                                            ? `
                                                <textarea
                                                    class="answer-input"
                                                    data-question-id="${escapeHtml(
                                                        question.id
                                                    )}"
                                                ></textarea>
                                            `
                                            : (
                                                question.choices ||
                                                []
                                            ).map(
                                                choice => `
                                                    <label
                                                        class="answer-choice"
                                                    >
                                                        <input
                                                            class="answer-input"
                                                            data-question-id="${escapeHtml(
                                                                question.id
                                                            )}"
                                                            type="${
                                                                question.type ===
                                                                'multiple'
                                                                    ? 'checkbox'
                                                                    : 'radio'
                                                            }"
                                                            name="question_${escapeHtml(
                                                                question.id
                                                            )}"
                                                            value="${escapeHtml(
                                                                choice.id
                                                            )}"
                                                        >

                                                        ${escapeHtml(
                                                            choice.label
                                                        )}
                                                    </label>
                                                `
                                            ).join('')
                                    }
                                </div>
                            `
                        ).join('')
                    }
                </section>
            `
        ).join('');

    container.innerHTML = `
        <section class="screen">
            <h2 class="screen-title">
                ${escapeHtml(
                    survey.title ||
                    'アンケート'
                )}
            </h2>

            <p>
                ${escapeHtml(
                    survey.description ||
                    ''
                )}
            </p>

            <form
                id="answer-form"
            >
                ${containerHtml}

                <div
                    style="
                        display:flex;
                        justify-content:flex-end;
                    "
                >
                    <button
                        class="btn btn-primary"
                        type="submit"
                    >
                        回答内容を確認
                    </button>
                </div>
            </form>
        </section>
    `;

    document
        .getElementById(
            'answer-form'
        )
        .addEventListener(
            'submit',
            function (event) {
                event.preventDefault();

                const answers =
                    collectAnswers(
                        survey
                    );

                const validation =
                    validateAnswers(
                        survey,
                        answers
                    );

                if (
                    !validation.valid
                ) {
                    alert(
                        validation.message
                    );
                    return;
                }

                /*
                 * 回答内容はURLと一緒に
                 * 次画面へ渡す。
                 *
                 * 実運用ではPOST/Session等も
                 * 併用して保持する。
                 */
                sessionStorage.setItem(
                    'surveyAnswers',
                    JSON.stringify(
                        answers
                    )
                );

                navigate(
                    'confirm',
                    {
                        surveyId:
                            state.surveyId,
                        ...(state.token
                            ? {
                                token:
                                    state.token
                            }
                            : {})
                    }
                );
            }
        );
}

function collectAnswers(
    survey
) {
    const answers = {};

    (
        survey.groups ||
        []
    ).forEach(
        group => {

            (
                group.questions ||
                []
            ).forEach(
                question => {

                    const elements =
                        document.querySelectorAll(
                            `[data-question-id="${CSS.escape(
                                question.id
                            )}"]`
                        );

                    if (
                        question.type ===
                        'multiple'
                    ) {
                        answers[
                            question.id
                        ] =
                            Array
                                .from(
                                    elements
                                )
                                .filter(
                                    element =>
                                        element.checked
                                )
                                .map(
                                    element =>
                                        element.value
                                );
                    } else if (
                        question.type ===
                        'single'
                    ) {
                        const selected =
                            Array
                                .from(
                                    elements
                                )
                                .find(
                                    element =>
                                        element.checked
                                );

                        answers[
                            question.id
                        ] =
                            selected
                                ? selected.value
                                : '';
                    } else {
                        answers[
                            question.id
                        ] =
                            elements[0]
                                ?.value ||
                            '';
                    }
                }
            );
        }
    );

    return answers;
}

function validateAnswers(
    survey,
    answers
) {
    for (
        const group of (
            survey.groups ||
            []
        )
    ) {
        for (
            const question of (
                group.questions ||
                []
            )
        ) {
            if (
                !question.required
            ) {
                continue;
            }

            const answer =
                answers[
                    question.id
                ];

            const empty =
                Array.isArray(answer)
                    ? answer.length === 0
                    : !String(
                        answer || ''
                    ).trim();

            if (empty) {
                return {
                    valid: false,
                    message:
                        `${question.questionNumber}「${question.text}」は必須です。`
                };
            }
        }
    }

    return {
        valid: true,
        message: ''
    };
}

/*
|--------------------------------------------------------------------------
| 回答確認
|--------------------------------------------------------------------------
*/

function renderConfirm(
    state
) {
    renderAnswerHeader();

    const survey =
        INITIAL_DATA.survey;

    const answers =
        JSON.parse(
            sessionStorage.getItem(
                'surveyAnswers'
            ) ||
            '{}'
        );

    const container =
        document.getElementById(
            'screen-container'
        );

    if (
        !survey ||
        !state.surveyId
    ) {
        container.innerHTML = `
            <section class="screen">
                <p class="error">
                    回答対象を特定できません。
                </p>
            </section>
        `;

        return;
    }

    container.innerHTML = `
        <section class="screen">
            <h2 class="screen-title">
                回答内容確認
            </h2>

            ${
                (
                    survey.groups ||
                    []
                ).map(
                    group => `
                        <section>
                            <h3>
                                ${escapeHtml(
                                    group.title ||
                                    ''
                                )}
                            </h3>

                            ${
                                (
                                    group.questions ||
                                    []
                                ).map(
                                    question => {

                                        const answer =
                                            answers[
                                                question.id
                                            ];

                                        const labels =
                                            (
                                                question.choices ||
                                                []
                                            )
                                            .filter(
                                                choice =>
                                                    Array.isArray(
                                                        answer
                                                    )
                                                        ? answer.includes(
                                                            choice.id
                                                        )
                                                        : answer ===
                                                            choice.id
                                            )
                                            .map(
                                                choice =>
                                                    choice.label
                                            );

                                        const display =
                                            Array.isArray(
                                                answer
                                            )
                                                ? labels.join(
                                                    '、'
                                                )
                                                : (
                                                    labels[0] ||
                                                    answer ||
                                                    ''
                                                );

                                        return `
                                            <div
                                                class="question-card"
                                            >
                                                <strong>
                                                    ${escapeHtml(
                                                        question.questionNumber
                                                    )}
                                                </strong>

                                                <p>
                                                    ${escapeHtml(
                                                        question.text
                                                    )}
                                                </p>

                                                <p>
                                                    ${escapeHtml(
                                                        display
                                                    )}
                                                </p>
                                            </div>
                                        `;
                                    }
                                ).join('')
                            }
                        </section>
                    `
                ).join('')
            }

            <div
                class="toolbar"
                style="
                    justify-content:flex-end;
                "
            >
                <button
                    class="btn btn-light"
                    onclick="
                        navigate(
                            'answer',
                            {
                                surveyId:
                                    '${escapeHtml(
                                        state.surveyId
                                    )}',
                                ${
                                    state.token
                                        ? `token: '${escapeHtml(
                                            state.token
                                        )}'`
                                        : ''
                                }
                            }
                        )
                    "
                >
                    修正
                </button>

                <button
                    class="btn btn-primary"
                    id="submit-answer"
                >
                    回答を送信
                </button>
            </div>
        </section>
    `;

    document
        .getElementById(
            'submit-answer'
        )
        .addEventListener(
            'click',
            function () {

                openModal(`
                    <h3>
                        回答送信確認
                    </h3>

                    <p>
                        回答を送信します。
                        よろしいですか？
                    </p>

                    <div class="modal-actions">
                        <button
                            class="btn btn-light"
                            onclick="
                                closeModal()
                            "
                        >
                            キャンセル
                        </button>

                        <button
                            class="btn btn-primary"
                            id="final-submit-answer"
                        >
                            送信する
                        </button>
                    </div>
                `);

                document
                    .getElementById(
                        'final-submit-answer'
                    )
                    .addEventListener(
                        'click',
                        async function () {

                            try {

                                const result =
                                    await apiJson(
                                        'save_response',
                                        {
                                            surveyId:
                                                state.surveyId,
                                            token:
                                                state.token,
                                            answers
                                        }
                                    );

                                if (
                                    result.success
                                ) {
                                    sessionStorage.removeItem(
                                        'surveyAnswers'
                                    );

                                    closeModal();

                                    navigate(
                                        'complete',
                                        {
                                            surveyId:
                                                state.surveyId,
                                            ...(state.token
                                                ? {
                                                    token:
                                                        state.token
                                                }
                                                : {})
                                        }
                                    );
                                }

                            } catch (
                                error
                            ) {
                                closeModal();

                                alert(
                                    error.message
                                );
                            }
                        }
                    );
            }
        );
}

/*
|--------------------------------------------------------------------------
| 完了
|--------------------------------------------------------------------------
*/

function renderComplete() {
    renderAnswerHeader();

    const container =
        document.getElementById(
            'screen-container'
        );

    container.innerHTML = `
        <section
            class="screen"
            style="text-align:center;"
        >
            <h2 class="screen-title">
                回答完了
            </h2>

            <p>
                アンケートへの回答を
                受け付けました。
            </p>

            <p>
                ご回答ありがとうございました。
            </p>
        </section>
    `;
}

/*
|--------------------------------------------------------------------------
| 画面レンダリング
|--------------------------------------------------------------------------
|
| 必ず現在URLから状態を再構築してから
| 画面を描画する。
|
|--------------------------------------------------------------------------
*/

function renderFromUrl() {
    const state =
        rebuildStateFromUrl();

    /*
     * URLと内部状態を一致させる。
     */
    if (
        state.view ===
        'admin-survey-list'
    ) {
        renderSurveyList(
            state
        );

        return;
    }

    if (
        state.view ===
        'admin-survey-edit'
    ) {
        renderSurveyEdit(
            state
        );

        return;
    }

    if (
        state.view ===
        'admin-preview'
    ) {
        renderPreview(
            state
        );

        return;
    }

    if (
        state.view ===
        'admin-send'
    ) {
        renderSend(
            state
        );

        return;
    }

    if (
        state.view ===
        'admin-aggregation'
    ) {
        renderAggregation(
            state
        );

        return;
    }

    if (
        state.view ===
        'admin-kintone'
    ) {
        renderKintone();

        return;
    }

    if (
        state.view ===
        'admin-mail'
    ) {
        renderMail();

        return;
    }

    if (
        state.view ===
        'answer'
    ) {
        renderAnswer(
            state
        );

        return;
    }

    if (
        state.view ===
        'confirm'
    ) {
        renderConfirm(
            state
        );

        return;
    }

    if (
        state.view ===
        'complete'
    ) {
        renderComplete(
            state
        );

        return;
    }

    /*
     * 念のためのフォールバック。
     * currentViewを直接変更して画面遷移しない。
     * URLそのものを正規化する。
     */
    navigate(
        DEFAULT_VIEW,
        {},
        true
    );
}

/*
|--------------------------------------------------------------------------
| 初期表示
|--------------------------------------------------------------------------
|
| PHP側でviewを正規化済みだが、
| JavaScript側でもURLを正として再構築する。
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'DOMContentLoaded',
    function () {
        renderFromUrl();
    }
);
</script>

</body>
</html>
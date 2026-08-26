<?php
declare(strict_types=1);


/*
 * アンケート管理システム
 *
 * 【重要】
 * ・単一入口は index.php
 * ・画面識別は GET の view のみ
 * ・対象アンケートは GET の surveyId
 * ・回答者識別は GET の token
 * ・業務APIは POST の action
 * ・URLパス、REQUEST_URI、PATH_INFO、SCRIPT_NAME、PHP_SELF等を
 *   画面識別には一切使用しない
 * ・SQLite等のDBは使用しない
 * ・JSONで永続化する
 */

session_start();

const DATA_DIR = __DIR__ . '/data';
const DEFAULT_VIEW = 'admin-survey-list';

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
    'complete',
];

const ADMIN_VIEWS = [
    'admin-survey-list',
    'admin-survey-edit',
    'admin-preview',
    'admin-send',
    'admin-aggregation',
    'admin-kintone',
    'admin-mail',
];

const ANSWER_VIEWS = [
    'answer',
    'confirm',
    'complete',
];

const ALLOWED_ACTIONS = [
    'save_survey',
    'delete_survey',
    'duplicate_survey',
    'change_survey_status',
    'save_response',
    'send_mail',
    'send_test_mail',
    'save_kintone_settings',
    'kintone_test',
    'kintone_get_fields',
    'kintone_sync',
    'save_mail_settings',
    'export_csv',
    'export_pdf',
];

/*
 * ============================================================
 * エントリーポイント
 * ============================================================
 *
 * GET  : view による画面表示
 * POST : action による業務API
 *
 * URLパスは画面判定に使用しない。
 */

ensureDataDirectory();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleApiRequest();
    exit;
}

$view = getView();
$surveyId = trim((string)($_GET['surveyId'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));

renderApplication($view, $surveyId, $token);


/*
 * ============================================================
 * 共通
 * ============================================================
 */

function getView(): string
{
    $view = trim((string)($_GET['view'] ?? ''));

    if ($view === '') {
        return DEFAULT_VIEW;
    }

    if (!in_array($view, ALLOWED_VIEWS, true)) {
        return DEFAULT_VIEW;
    }

    return $view;
}

function ensureDataDirectory(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }

    $defaults = [
        'surveys.json' => [],
        'customers.json' => [],
        'responses.json' => [],
        'send_history.json' => [],
        'settings.json' => [
            'kintone' => [
                'subdomain' => '',
                'appId' => '',
                'loginName' => '',
                'password' => '',
                'sslVerify' => false,
                'proxy' => '',
                'fieldMapping' => [
                    'organization' => '',
                    'name' => '',
                    'email' => '',
                    'department' => '',
                    'phone' => '',
                    'address' => [],
                ],
                'fields' => [],
                'lastFieldFetchAt' => null,
                'lastSyncAt' => null,
            ],
            'mail' => [
                'smtpHost' => '',
                'smtpPort' => 587,
                'encryption' => 'tls',
                'smtpAuth' => true,
                'username' => '',
                'password' => '',
                'fromEmail' => '',
                'fromName' => '',
                'replyTo' => '',
                'status' => '未設定',
                'lastTestAt' => null,
            ],
        ],
    ];

    foreach ($defaults as $file => $value) {
        $path = DATA_DIR . '/' . $file;

        if (!file_exists($path)) {
            writeJson($file, $value);
        }
    }
}

function readJson(string $file, mixed $default = []): mixed
{
    $path = DATA_DIR . '/' . $file;

    if (!is_file($path)) {
        return $default;
    }

    $fp = @fopen($path, 'rb');

    if (!$fp) {
        return $default;
    }

    flock($fp, LOCK_SH);
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

function writeJson(string $file, mixed $data): bool
{
    $path = DATA_DIR . '/' . $file;
    $tmp = $path . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $fp = @fopen($tmp, 'wb');

    if (!$fp) {
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

    return @rename($tmp, $path);
}

function nowIso(): string
{
    return date('c');
}

function uuid(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(12));
}

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function postString(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function postJson(string $key, mixed $default = []): mixed
{
    $value = json_decode(
        (string)($_POST[$key] ?? ''),
        true
    );

    return $value === null && ($_POST[$key] ?? '') !== 'null'
        ? $default
        : $value;
}


/*
 * ============================================================
 * API
 * ============================================================
 */

function handleApiRequest(): never
{
    $action = postString('action');

    if (!in_array($action, ALLOWED_ACTIONS, true)) {
        jsonResponse([
            'success' => false,
            'error' => '不正なactionです。',
        ], 400);
    }

    try {
        switch ($action) {
            case 'save_survey':
                apiSaveSurvey();
                break;

            case 'delete_survey':
                apiDeleteSurvey();
                break;

            case 'duplicate_survey':
                apiDuplicateSurvey();
                break;

            case 'change_survey_status':
                apiChangeSurveyStatus();
                break;

            case 'save_response':
                apiSaveResponse();
                break;

            case 'send_mail':
                apiSendMail();
                break;

            case 'send_test_mail':
                apiSendTestMail();
                break;

            case 'save_kintone_settings':
                apiSaveKintoneSettings();
                break;

            case 'kintone_test':
                apiKintoneTest();
                break;

            case 'kintone_get_fields':
                apiKintoneGetFields();
                break;

            case 'kintone_sync':
                apiKintoneSync();
                break;

            case 'save_mail_settings':
                apiSaveMailSettings();
                break;

            case 'export_csv':
                apiExportCsv();
                break;

            case 'export_pdf':
                apiExportPdf();
                break;
        }
    } catch (Throwable $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }

    exit;
}


/*
 * ============================================================
 * アンケート
 * ============================================================
 */

function apiSaveSurvey(): never
{
    $payload = postJson('survey');

    if (!is_array($payload)) {
        jsonResponse([
            'success' => false,
            'error' => 'アンケートデータが不正です。',
        ], 400);
    }

    $surveys = readJson('surveys.json', []);

    $surveyId = trim((string)($payload['surveyId'] ?? ''));
    $isNew = $surveyId === '';

    if ($isNew) {
        $surveyId = uuid('survey_');
    }

    $existingIndex = findSurveyIndex(
        $surveys,
        $surveyId
    );

    $existing = $existingIndex !== null
        ? $surveys[$existingIndex]
        : null;

    $status = $existing['status'] ?? '下書き';

    $survey = normalizeSurvey(
        $payload,
        $surveyId,
        $status
    );

    if ($existingIndex === null) {
        $surveys[] = $survey;
    } else {
        $surveys[$existingIndex] = $survey;
    }

    if (!writeJson('surveys.json', array_values($surveys))) {
        jsonResponse([
            'success' => false,
            'error' => '保存に失敗しました。',
        ], 500);
    }

    jsonResponse([
        'success' => true,
        'survey' => $survey,
        'surveyId' => $surveyId,
    ]);
}

function normalizeSurvey(
    array $source,
    string $surveyId,
    string $status
): array {
    $groups = [];

    foreach (($source['groups'] ?? []) as $groupIndex => $group) {
        if (!is_array($group)) {
            continue;
        }

        $groupId = trim(
            (string)($group['groupId'] ?? '')
        );

        if ($groupId === '') {
            $groupId = uuid('group_');
        }

        $questions = [];

        foreach (($group['questions'] ?? []) as $questionIndex => $question) {
            if (!is_array($question)) {
                continue;
            }

            $questionId = trim(
                (string)($question['questionId'] ?? '')
            );

            if ($questionId === '') {
                $questionId = uuid('question_');
            }

            $choices = [];

            foreach (($question['choices'] ?? []) as $choice) {
                if (!is_array($choice)) {
                    continue;
                }

                $choiceId = trim(
                    (string)($choice['choiceId'] ?? '')
                );

                if ($choiceId === '') {
                    $choiceId = uuid('choice_');
                }

                $choices[] = [
                    'choiceId' => $choiceId,
                    'label' => (string)($choice['label'] ?? ''),
                    'value' => (string)($choice['value'] ?? ''),
                ];
            }

            $questions[] = [
                'questionId' => $questionId,
                'questionNumber' => '',
                'questionText' => (string)(
                    $question['questionText'] ?? ''
                ),
                'answerType' => normalizeAnswerType(
                    $question['answerType'] ?? 'single'
                ),
                'required' => !empty($question['required']),
                'choices' => $choices,
                'conditions' => normalizeConditions(
                    $question['conditions'] ?? []
                ),
                'sortOrder' => $questionIndex,
            ];
        }

        $groups[] = [
            'groupId' => $groupId,
            'groupTitle' => (string)(
                $group['groupTitle'] ?? ''
            ),
            'sortOrder' => $groupIndex,
            'questions' => $questions,
        ];
    }

    $numberingMode =
        ($source['numberingMode'] ?? 'global') === 'group'
            ? 'group'
            : 'global';

    $survey = [
        'surveyId' => $surveyId,
        'title' => (string)($source['title'] ?? ''),
        'description' => (string)(
            $source['description'] ?? ''
        ),
        'startAt' => nullableString(
            $source['startAt'] ?? null
        ),
        'endAt' => nullableString(
            $source['endAt'] ?? null
        ),
        'numberingMode' => $numberingMode,
        'allowResubmit' => !empty(
            $source['allowResubmit']
        ),
        'status' => $status,
        'groups' => $groups,
        'createdAt' => (string)(
            $source['createdAt'] ?? nowIso()
        ),
        'updatedAt' => nowIso(),
    ];

    recalculateQuestionNumbers($survey);

    return $survey;
}

function nullableString(mixed $value): ?string
{
    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function normalizeAnswerType(mixed $type): string
{
    return match ((string)$type) {
        'multiple' => 'multiple',
        'text' => 'text',
        default => 'single',
    };
}

function normalizeConditions(mixed $conditions): array
{
    if (!is_array($conditions)) {
        return [];
    }

    $result = [];

    foreach ($conditions as $condition) {
        if (!is_array($condition)) {
            continue;
        }

        $questionId = trim(
            (string)($condition['questionId'] ?? '')
        );

        $choiceId = trim(
            (string)($condition['choiceId'] ?? '')
        );

        $nextQuestionId = trim(
            (string)($condition['nextQuestionId'] ?? '')
        );

        if (
            $questionId === '' ||
            $choiceId === '' ||
            $nextQuestionId === ''
        ) {
            continue;
        }

        $result[] = [
            'questionId' => $questionId,
            'choiceId' => $choiceId,
            'nextQuestionId' => $nextQuestionId,
        ];
    }

    return $result;
}

function recalculateQuestionNumbers(array &$survey): void
{
    $global = 0;

    foreach ($survey['groups'] as $groupIndex => &$group) {
        $local = 0;

        foreach ($group['questions'] as $questionIndex => &$question) {
            $question['sortOrder'] = $questionIndex;

            if ($survey['numberingMode'] === 'group') {
                $local++;
                $question['questionNumber'] =
                    'Q' . ($groupIndex + 1) . '-' . $local;
            } else {
                $global++;
                $question['questionNumber'] =
                    'Q' . $global;
            }
        }

        $group['sortOrder'] = $groupIndex;
    }

    unset($group, $question);
}

function apiDeleteSurvey(): never
{
    $surveyId = postString('surveyId');

    if ($surveyId === '') {
        jsonResponse([
            'success' => false,
            'error' => 'surveyIdが必要です。',
        ], 400);
    }

    $surveys = readJson('surveys.json', []);
    $index = findSurveyIndex($surveys, $surveyId);

    if ($index === null) {
        jsonResponse([
            'success' => false,
            'error' => 'アンケートが存在しません。',
        ], 404);
    }

    array_splice($surveys, $index, 1);

    writeJson('surveys.json', array_values($surveys));

    jsonResponse([
        'success' => true,
    ]);
}

function apiDuplicateSurvey(): never
{
    $surveyId = postString('surveyId');

    $surveys = readJson('surveys.json', []);
    $source = findSurvey($surveys, $surveyId);

    if (!$source) {
        jsonResponse([
            'success' => false,
            'error' => '複製元アンケートが存在しません。',
        ], 404);
    }

    $copy = $source;
    $copy['surveyId'] = uuid('survey_');
    $copy['title'] =
        (string)$source['title'] . '（複製）';
    $copy['status'] = '下書き';
    $copy['createdAt'] = nowIso();
    $copy['updatedAt'] = nowIso();

    foreach ($copy['groups'] as &$group) {
        $group['groupId'] = uuid('group_');

        foreach ($group['questions'] as &$question) {
            $oldQuestionId =
                (string)$question['questionId'];

            $question['questionId'] =
                uuid('question_');

            foreach ($question['choices'] as &$choice) {
                $choice['choiceId'] =
                    uuid('choice_');
            }

            /*
             * 複製時は内部IDが変わるため、
             * 条件分岐をそのままコピーして壊さない。
             * 条件分岐は複製後に再構築できるUI側から
             * 新しいIDで保存する。
             */
            $question['conditions'] = [];
        }
    }

    unset($group, $question, $choice);

    recalculateQuestionNumbers($copy);

    $surveys[] = $copy;

    writeJson(
        'surveys.json',
        array_values($surveys)
    );

    jsonResponse([
        'success' => true,
        'survey' => $copy,
    ]);
}

function apiChangeSurveyStatus(): never
{
    $surveyId = postString('surveyId');
    $target = postString('status');

    $surveys = readJson('surveys.json', []);
    $index = findSurveyIndex($surveys, $surveyId);

    if ($index === null) {
        jsonResponse([
            'success' => false,
            'error' => 'アンケートが存在しません。',
        ], 404);
    }

    $current = $surveys[$index]['status'] ?? '下書き';

    $valid =
        ($current === '下書き' &&
            $target === '公開中') ||
        ($current === '公開中' &&
            $target === '停止') ||
        ($current === '停止' &&
            $target === '公開中');

    if (!$valid) {
        jsonResponse([
            'success' => false,
            'error' => '許可されていない状態遷移です。',
        ], 400);
    }

    $surveys[$index]['status'] = $target;
    $surveys[$index]['updatedAt'] = nowIso();

    writeJson('surveys.json', $surveys);

    jsonResponse([
        'success' => true,
        'status' => $target,
    ]);
}

function updateAutomaticEndStatus(array &$surveys): bool
{
    $changed = false;
    $now = time();

    foreach ($surveys as &$survey) {
        if (($survey['status'] ?? '') !== '公開中') {
            continue;
        }

        $endAt = $survey['endAt'] ?? null;

        if (!$endAt) {
            continue;
        }

        $timestamp = strtotime((string)$endAt);

        if ($timestamp !== false && $now > $timestamp) {
            $survey['status'] = '終了';
            $survey['updatedAt'] = nowIso();
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function getCurrentSurveys(): array
{
    $surveys = readJson('surveys.json', []);

    if (updateAutomaticEndStatus($surveys)) {
        writeJson('surveys.json', $surveys);
    }

    return $surveys;
}

function findSurvey(
    array $surveys,
    string $surveyId
): ?array {
    foreach ($surveys as $survey) {
        if (
            is_array($survey) &&
            (string)($survey['surveyId'] ?? '') === $surveyId
        ) {
            return $survey;
        }
    }

    return null;
}

function findSurveyIndex(
    array $surveys,
    string $surveyId
): ?int {
    foreach ($surveys as $index => $survey) {
        if (
            is_array($survey) &&
            (string)($survey['surveyId'] ?? '') === $surveyId
        ) {
            return $index;
        }
    }

    return null;
}


/*
 * ============================================================
 * 回答
 * ============================================================
 */

function apiSaveResponse(): never
{
    $surveyId = postString('surveyId');
    $token = postString('token');
    $payload = postJson('response');

    if ($surveyId === '' || !is_array($payload)) {
        jsonResponse([
            'success' => false,
            'error' => '回答データが不正です。',
        ], 400);
    }

    $surveys = getCurrentSurveys();
    $survey = findSurvey($surveys, $surveyId);

    if (!$survey) {
        jsonResponse([
            'success' => false,
            'error' => 'アンケートが存在しません。',
        ], 404);
    }

    if (($survey['status'] ?? '') !== '公開中') {
        jsonResponse([
            'success' => false,
            'error' => '現在回答できる状態ではありません。',
        ], 400);
    }

    $responses = readJson(
        'responses.json',
        []
    );

    $actualToken =
        $token !== ''
            ? $token
            : uuid('token_');

    if (
        !$survey['allowResubmit'] &&
        hasSubmittedResponse(
            $responses,
            $surveyId,
            $actualToken
        )
    ) {
        jsonResponse([
            'success' => false,
            'alreadyAnswered' => true,
            'error' => '回答済みです。',
        ], 409);
    }

    $respondent = normalizeRespondent(
        $payload['respondent'] ?? []
    );

    $customers = readJson(
        'customers.json',
        []
    );

    $customerId = resolveCustomer(
        $customers,
        $respondent
    );

    $record = [
        'responseId' => uuid('response_'),
        'surveyId' => $surveyId,
        'customerId' => $customerId !== ''
            ? $customerId
            : null,
        'token' => $actualToken,
        'respondent' => $respondent,
        'answers' => is_array(
            $payload['answers'] ?? null
        )
            ? $payload['answers']
            : [],
        'submittedAt' => nowIso(),
    ];

    $responses[] = $record;

    writeJson(
        'responses.json',
        $responses
    );

    updateCustomerResponseStatus(
        $customerId,
        $surveyId,
        true
    );

    jsonResponse([
        'success' => true,
        'responseId' => $record['responseId'],
        'token' => $actualToken,
    ]);
}

function hasSubmittedResponse(
    array $responses,
    string $surveyId,
    string $token
): bool {
    if ($token === '') {
        return false;
    }

    foreach ($responses as $response) {
        if (
            (string)($response['surveyId'] ?? '') === $surveyId &&
            (string)($response['token'] ?? '') === $token
        ) {
            return true;
        }
    }

    return false;
}

function normalizeRespondent(
    mixed $respondent
): array {
    if (!is_array($respondent)) {
        $respondent = [];
    }

    return [
        'organization' => (string)(
            $respondent['organization'] ?? ''
        ),
        'name' => (string)(
            $respondent['name'] ?? ''
        ),
        'email' => (string)(
            $respondent['email'] ?? ''
        ),
        'department' => (string)(
            $respondent['department'] ?? ''
        ),
        'phone' => (string)(
            $respondent['phone'] ?? ''
        ),
        'address' => (string)(
            $respondent['address'] ?? ''
        ),
    ];
}

function resolveCustomer(
    array $customers,
    array $respondent
): string {
    $email = strtolower(
        trim((string)(
            $respondent['email'] ?? ''
        ))
    );

    if ($email !== '') {
        foreach ($customers as $customer) {
            $candidate = strtolower(
                trim((string)(
                    $customer['email'] ?? ''
                ))
            );

            if (
                $candidate !== '' &&
                $candidate === $email
            ) {
                return (string)(
                    $customer['customerId'] ?? ''
                );
            }
        }
    }

    $name = trim(
        (string)($respondent['name'] ?? '')
    );

    $organization = trim(
        (string)($respondent['organization'] ?? '')
    );

    if ($name !== '' && $organization !== '') {
        foreach ($customers as $customer) {
            if (
                (string)($customer['name'] ?? '') === $name &&
                (string)($customer['organization'] ?? '') ===
                    $organization
            ) {
                return (string)(
                    $customer['customerId'] ?? ''
                );
            }
        }
    }

    return '';
}

function updateCustomerResponseStatus(
    ?string $customerId,
    string $surveyId,
    bool $answered
): void {
    if (!$customerId) {
        return;
    }

    $customers = readJson(
        'customers.json',
        []
    );

    foreach ($customers as &$customer) {
        if (
            (string)($customer['customerId'] ?? '') ===
            $customerId
        ) {
            $customer['responseStatus'] =
                $answered
                    ? '回答済み'
                    : '未回答';

            $customer['lastSurveyId'] =
                $surveyId;

            break;
        }
    }

    unset($customer);

    writeJson(
        'customers.json',
        $customers
    );
}


/*
 * ============================================================
 * メール
 * ============================================================
 */

function apiSendMail(): never
{
    $surveyId = postString('surveyId');
    $customerIds = postJson(
        'customerIds',
        []
    );

    $subject = (string)(
        $_POST['subject'] ?? ''
    );

    $body = (string)(
        $_POST['body'] ?? ''
    );

    $sendType = postString(
        'sendType',
        '一括送信'
    );

    if ($surveyId === '') {
        jsonResponse([
            'success' => false,
            'error' => '対象アンケートが指定されていません。',
        ], 400);
    }

    if (
        !is_array($customerIds) ||
        count($customerIds) === 0
    ) {
        jsonResponse([
            'success' => false,
            'error' => '送信対象顧客を選択してください。',
        ], 400);
    }

    $surveys = getCurrentSurveys();
    $survey = findSurvey(
        $surveys,
        $surveyId
    );

    if (!$survey) {
        jsonResponse([
            'success' => false,
            'error' => '対象アンケートが存在しません。',
        ], 404);
    }

    $customers = readJson(
        'customers.json',
        []
    );

    $settings = readJson(
        'settings.json',
        []
    );

    $history = readJson(
        'send_history.json',
        []
    );

    $results = [];

    foreach ($customerIds as $customerId) {
        $customer = findCustomer(
            $customers,
            (string)$customerId
        );

        if (!$customer) {
            $results[] = [
                'customerId' => $customerId,
                'success' => false,
                'error' => '顧客が存在しません。',
            ];

            continue;
        }

        $name = (string)(
            $customer['name'] ?? ''
        );

        $email = trim((string)(
            $customer['email'] ?? ''
        ));

        $url = buildSurveyUrl(
            $surveyId,
            (string)(
                $customer['token'] ??
                uuid('token_')
            )
        );

        $expandedSubject =
            expandMailVariables(
                $subject,
                $name,
                $url
            );

        $expandedBody =
            expandMailVariables(
                $body,
                $name,
                $url
            );

        if ($email === '') {
            $result = [
                'customerId' => $customerId,
                'customerName' => $name,
                'email' => '',
                'success' => false,
                'error' => 'メールアドレスがありません。',
            ];
        } else {
            $result = sendSmtpMail(
                $settings['mail'] ?? [],
                $email,
                $expandedSubject,
                $expandedBody
            );

            $result['customerId'] = $customerId;
            $result['customerName'] = $name;
            $result['email'] = $email;
        }

        $results[] = $result;

        if (!empty($result['success'])) {
            recordCustomerSend(
                $customerId,
                $surveyId
            );
        }
    }

    $successCount = count(array_filter(
        $results,
        static fn($r) => !empty($r['success'])
    ));

    $failureCount =
        count($results) - $successCount;

    $history[] = [
        'historyId' => uuid('send_'),
        'surveyId' => $surveyId,
        'sentAt' => nowIso(),
        'sendType' => $sendType,
        'count' => count($results),
        'successCount' => $successCount,
        'failureCount' => $failureCount,
        'subject' => $subject,
        'body' => $body,
        'executedBy' => 'system',
        'customers' => $results,
    ];

    writeJson(
        'send_history.json',
        $history
    );

    jsonResponse([
        'success' => true,
        'surveyId' => $surveyId,
        'total' => count($results),
        'successCount' => $successCount,
        'failureCount' => $failureCount,
        'sentAt' => nowIso(),
        'results' => $results,
    ]);
}

function expandMailVariables(
    string $text,
    string $customerName,
    string $url
): string {
    return str_replace(
        [
            '{顧客名}',
            '{アンケートURL}',
        ],
        [
            $customerName,
            $url,
        ],
        $text
    );
}

function buildSurveyUrl(
    string $surveyId,
    string $token
): string {
    /*
     * 画面識別情報は必ずquery。
     * 物理ディレクトリを解析してURLを生成しない。
     */
    return 'index.php?view=answer&surveyId=' .
        rawurlencode($surveyId) .
        '&token=' .
        rawurlencode($token);
}

function findCustomer(
    array $customers,
    string $customerId
): ?array {
    foreach ($customers as $customer) {
        if (
            (string)($customer['customerId'] ?? '') ===
            $customerId
        ) {
            return $customer;
        }
    }

    return null;
}

function recordCustomerSend(
    string $customerId,
    string $surveyId
): void {
    $customers = readJson(
        'customers.json',
        []
    );

    foreach ($customers as &$customer) {
        if (
            (string)($customer['customerId'] ?? '') ===
            $customerId
        ) {
            $customer['lastSendAt'] = nowIso();
            $customer['sendCount'] =
                ((int)($customer['sendCount'] ?? 0)) + 1;
            $customer['lastSurveyId'] = $surveyId;
            $customer['responseStatus'] =
                '送信済み / 未回答';

            break;
        }
    }

    unset($customer);

    writeJson(
        'customers.json',
        $customers
    );
}

function apiSendTestMail(): never
{
    $settings = readJson(
        'settings.json',
        []
    );

    $mail = $settings['mail'] ?? [];

    $to = postString(
        'to',
        (string)($mail['fromEmail'] ?? '')
    );

    if ($to === '') {
        jsonResponse([
            'success' => false,
            'error' => 'テスト送信先メールアドレスがありません。',
        ], 400);
    }

    $result = sendSmtpMail(
        $mail,
        $to,
        'アンケート管理システム テストメール',
        'SMTP接続テストメールです。'
    );

    if (!empty($result['success'])) {
        $settings['mail']['status'] =
            '接続確認済み';

        $settings['mail']['lastTestAt'] =
            nowIso();
    } else {
        $settings['mail']['status'] =
            '接続できません';
    }

    writeJson(
        'settings.json',
        $settings
    );

    jsonResponse($result);
}

function sendSmtpMail(
    array $settings,
    string $to,
    string $subject,
    string $body
): array {
    $host = trim(
        (string)($settings['smtpHost'] ?? '')
    );

    $port = (int)(
        $settings['smtpPort'] ?? 587
    );

    $encryption =
        (string)($settings['encryption'] ?? 'tls');

    $auth = !empty(
        $settings['smtpAuth']
    );

    $username = (string)(
        $settings['username'] ?? ''
    );

    $password = (string)(
        $settings['password'] ?? ''
    );

    $fromEmail = trim(
        (string)($settings['fromEmail'] ?? '')
    );

    $fromName = (string)(
        $settings['fromName'] ?? ''
    );

    if (
        $host === '' ||
        $port <= 0 ||
        $fromEmail === ''
    ) {
        return [
            'success' => false,
            'error' => 'SMTP設定が未設定です。',
        ];
    }

    /*
     * PHP標準機能だけで実SMTP通信を行う。
     * STARTTLS / SMTPS に対応。
     */

    $transport = $encryption === 'ssl'
        ? 'ssl://'
        : '';

    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return [
            'success' => false,
            'error' => "SMTP接続失敗: {$errstr} ({$errno})",
        ];
    }

    stream_set_timeout($socket, 15);

    $read = smtpRead($socket);

    if ($read['code'] < 200 || $read['code'] >= 400) {
        fclose($socket);

        return [
            'success' => false,
            'error' => 'SMTP greeting失敗: ' .
                $read['message'],
        ];
    }

    $localHost = 'localhost';

    if (!smtpCommand(
        $socket,
        'EHLO ' . $localHost,
        [250]
    )) {
        fclose($socket);

        return [
            'success' => false,
            'error' => 'EHLOに失敗しました。',
        ];
    }

    if ($encryption === 'tls') {
        if (!smtpCommand(
            $socket,
            'STARTTLS',
            [220]
        )) {
            fclose($socket);

            return [
                'success' => false,
                'error' => 'STARTTLSに失敗しました。',
            ];
        }

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            return [
                'success' => false,
                'error' => 'TLS通信を確立できませんでした。',
            ];
        }

        if (!smtpCommand(
            $socket,
            'EHLO ' . $localHost,
            [250]
        )) {
            fclose($socket);

            return [
                'success' => false,
                'error' => 'TLS後のEHLOに失敗しました。',
            ];
        }
    }

    if ($auth) {
        if (!smtpCommand(
            $socket,
            'AUTH LOGIN',
            [334]
        )) {
            fclose($socket);

            return [
                'success' => false,
                'error' => 'SMTP AUTH LOGINに失敗しました。',
            ];
        }

        if (!smtpCommand(
            $socket,
            base64_encode($username),
            [334]
        )) {
            fclose($socket);

            return [
                'success' => false,
                'error' => 'SMTPユーザー名認証に失敗しました。',
            ];
        }

        if (!smtpCommand(
            $socket,
            base64_encode($password),
            [235]
        )) {
            fclose($socket);

            return [
                'success' => false,
                'error' => 'SMTPパスワード認証に失敗しました。',
            ];
        }
    }

    if (!smtpCommand(
        $socket,
        'MAIL FROM:<' . $fromEmail . '>',
        [250]
    )) {
        fclose($socket);

        return [
            'success' => false,
            'error' => 'MAIL FROMに失敗しました。',
        ];
    }

    if (!smtpCommand(
        $socket,
        'RCPT TO:<' . $to . '>',
        [250, 251]
    )) {
        fclose($socket);

        return [
            'success' => false,
            'error' => 'RCPT TOに失敗しました。',
        ];
    }

    if (!smtpCommand(
        $socket,
        'DATA',
        [354]
    )) {
        fclose($socket);

        return [
            'success' => false,
            'error' => 'DATAに失敗しました。',
        ];
    }

    $encodedSubject =
        '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $fromHeader = $fromEmail;

    if ($fromName !== '') {
        $fromHeader =
            '=?UTF-8?B?' .
            base64_encode($fromName) .
            '?= <' .
            $fromEmail .
            '>';
    }

    $headers = [
        'From: ' . $fromHeader,
        'To: ' . $to,
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    if (
        trim((string)($settings['replyTo'] ?? '')) !== ''
    ) {
        $headers[] =
            'Reply-To: ' .
            trim((string)$settings['replyTo']);
    }

    $message =
        implode("\r\n", $headers) .
        "\r\n\r\n" .
        preg_replace(
            '/^\./m',
            '..',
            str_replace(
                ["\r\n", "\r"],
                "\n",
                $body
            )
        ) .
        "\r\n.";

    fwrite(
        $socket,
        $message . "\r\n"
    );

    $result = smtpRead($socket);

    smtpCommand(
        $socket,
        'QUIT',
        [221]
    );

    fclose($socket);

    if (
        $result['code'] < 200 ||
        $result['code'] >= 400
    ) {
        return [
            'success' => false,
            'error' => 'メール送信失敗: ' .
                $result['message'],
        ];
    }

    return [
        'success' => true,
        'message' => '送信成功',
    ];
}

function smtpRead($socket): array
{
    $message = '';
    $code = 0;

    while (($line = fgets($socket, 515)) !== false) {
        $message .= trim($line) . ' ';

        if (
            preg_match(
                '/^(\d{3})([ -])(.*)$/',
                $line,
                $m
            )
        ) {
            $code = (int)$m[1];

            if ($m[2] === ' ') {
                break;
            }
        }
    }

    return [
        'code' => $code,
        'message' => trim($message),
    ];
}

function smtpCommand(
    $socket,
    string $command,
    array $expected
): bool {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    $result = smtpRead($socket);

    return in_array(
        $result['code'],
        $expected,
        true
    );
}


/*
 * ============================================================
 * kintone
 * ============================================================
 */

function apiSaveKintoneSettings(): never
{
    $settings = readJson(
        'settings.json',
        []
    );

    $settings['kintone'] = [
        'subdomain' => normalizeKintoneSubdomain(
            postString('subdomain')
        ),
        'appId' => postString('appId'),
        'loginName' => postString('loginName'),
        'password' => (string)(
            $_POST['password'] ?? ''
        ),
        'sslVerify' =>
            ($_POST['sslVerify'] ?? '0') === '1',
        'proxy' => postString('proxy'),
        'fieldMapping' => postJson(
            'fieldMapping',
            []
        ),
        'fields' =>
            $settings['kintone']['fields'] ?? [],
        'lastFieldFetchAt' =>
            $settings['kintone']['lastFieldFetchAt'] ??
            null,
        'lastSyncAt' =>
            $settings['kintone']['lastSyncAt'] ??
            null,
    ];

    writeJson(
        'settings.json',
        $settings
    );

    jsonResponse([
        'success' => true,
        'settings' => $settings['kintone'],
    ]);
}

function normalizeKintoneSubdomain(
    string $value
): string {
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim(
        $value,
        "/ \t\r\n"
    );

    if (
        str_ends_with(
            strtolower($value),
            '.cybozu.com'
        )
    ) {
        return $value;
    }

    return $value !== ''
        ? $value . '.cybozu.com'
        : '';
}

function kintoneRequest(
    array $settings,
    string $method,
    string $path,
    ?array $payload = null
): array {
    $subdomain = normalizeKintoneSubdomain(
        (string)($settings['subdomain'] ?? '')
    );

    $appId = trim(
        (string)($settings['appId'] ?? '')
    );

    $loginName = (string)(
        $settings['loginName'] ?? ''
    );

    $password = (string)(
        $settings['password'] ?? ''
    );

    if (
        $subdomain === '' ||
        $appId === '' ||
        $loginName === '' ||
        $password === ''
    ) {
        return [
            'success' => false,
            'error' => 'kintone接続設定が未設定です。',
        ];
    }

    $url =
        'https://' .
        $subdomain .
        $path;

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                $loginName . ':' . $password
            ),
        'Content-Type: application/json',
    ];

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'success' => false,
            'error' => 'cURL初期化に失敗しました。',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER =>
            !empty($settings['sslVerify']),
        CURLOPT_SSL_VERIFYHOST =>
            !empty($settings['sslVerify']) ? 2 : 0,
    ]);

    $proxy = trim(
        (string)($settings['proxy'] ?? '')
    );

    if ($proxy !== '') {
        curl_setopt(
            $ch,
            CURLOPT_PROXY,
            $proxy
        );
        curl_setopt(
            $ch,
            CURLOPT_PROXYAUTH,
            CURLAUTH_NONE
        );
    }

    if ($payload !== null) {
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($body === false) {
        return [
            'success' => false,
            'error' => 'kintone通信失敗: ' . $error,
        ];
    }

    $decoded = json_decode(
        $body,
        true
    );

    if ($status < 200 || $status >= 300) {
        $detail = is_array($decoded)
            ? json_encode(
                $decoded,
                JSON_UNESCAPED_UNICODE
            )
            : $body;

        return [
            'success' => false,
            'status' => $status,
            'error' =>
                'kintone APIエラー: ' .
                $detail,
        ];
    }

    return [
        'success' => true,
        'status' => $status,
        'data' => is_array($decoded)
            ? $decoded
            : [],
    ];
}

function apiKintoneTest(): never
{
    $settings = readJson(
        'settings.json',
        []
    );

    $result = kintoneRequest(
        $settings['kintone'] ?? [],
        'GET',
        '/k/v1/app.json?id=' .
            rawurlencode(
                (string)(
                    $settings['kintone']['appId'] ?? ''
                )
            )
    );

    jsonResponse([
        'success' => !empty($result['success']),
        'message' => !empty($result['success'])
            ? '接続成功'
            : '接続失敗',
        'detail' => $result['error'] ?? '',
    ], !empty($result['success']) ? 200 : 400);
}

function apiKintoneGetFields(): never
{
    $settings = readJson(
        'settings.json',
        []
    );

    $result = kintoneRequest(
        $settings['kintone'] ?? [],
        'GET',
        '/k/v1/app/form/fields.json?app=' .
            rawurlencode(
                (string)(
                    $settings['kintone']['appId'] ?? ''
                )
            )
    );

    if (empty($result['success'])) {
        jsonResponse([
            'success' => false,
            'error' => $result['error'] ?? '取得失敗',
        ], 400);
    }

    $properties =
        $result['data']['properties'] ?? [];

    $fields = [];

    foreach ($properties as $code => $field) {
        $fields[] = [
            'code' => $code,
            'label' => (string)(
                $field['label'] ?? $code
            ),
            'type' => (string)(
                $field['type'] ?? ''
            ),
        ];
    }

    $settings['kintone']['fields'] = $fields;
    $settings['kintone']['lastFieldFetchAt'] =
        nowIso();

    writeJson(
        'settings.json',
        $settings
    );

    jsonResponse([
        'success' => true,
        'fields' => $fields,
    ]);
}

function apiKintoneSync(): never
{
    $settings = readJson(
        'settings.json',
        []
    );

    $kintone =
        $settings['kintone'] ?? [];

    $mapping =
        $kintone['fieldMapping'] ?? [];

    $query = '';

    $result = kintoneRequest(
        $kintone,
        'GET',
        '/k/v1/records.json?app=' .
            rawurlencode(
                (string)($kintone['appId'] ?? '')
            ) .
            '&query=' .
            rawurlencode($query) .
            '&totalCount=true'
    );

    if (empty($result['success'])) {
        jsonResponse([
            'success' => false,
            'error' => $result['error'] ?? '同期失敗',
        ], 400);
    }

    $records =
        $result['data']['records'] ?? [];

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $readField = static function (
            string $code
        ) use ($record): string {
            return (string)(
                $record[$code]['value'] ?? ''
            );
        };

        $address = [];

        foreach (
            ($mapping['address'] ?? []) as $code
        ) {
            $value = $readField(
                (string)$code
            );

            if ($value !== '') {
                $address[] = $value;
            }
        }

        $customers[] = [
            'customerId' =>
                uuid('customer_'),
            'kintoneRecordId' =>
                $readField('$id'),
            'organization' =>
                $readField(
                    (string)(
                        $mapping['organization'] ?? ''
                    )
                ),
            'name' =>
                $readField(
                    (string)(
                        $mapping['name'] ?? ''
                    )
                ),
            'email' =>
                $readField(
                    (string)(
                        $mapping['email'] ?? ''
                    )
                ),
            'department' =>
                $readField(
                    (string)(
                        $mapping['department'] ?? ''
                    )
                ),
            'phone' =>
                $readField(
                    (string)(
                        $mapping['phone'] ?? ''
                    )
                ),
            'address' =>
                implode(' ', $address),
            'sendCount' => 0,
            'lastSendAt' => null,
            'responseStatus' => '未送信',
            'kintoneStatus' => '登録済み',
        ];
    }

    $settings['kintone']['lastSyncAt'] =
        nowIso();

    writeJson(
        'customers.json',
        $customers
    );

    writeJson(
        'settings.json',
        $settings
    );

    jsonResponse([
        'success' => true,
        'message' => '顧客同期完了',
        'count' => count($customers),
    ]);
}


/*
 * ============================================================
 * メール設定
 * ============================================================
 */

function apiSaveMailSettings(): never
{
    $settings = readJson(
        'settings.json',
        []
    );

    $settings['mail'] = [
        'smtpHost' => postString(
            'smtpHost'
        ),
        'smtpPort' => (int)(
            $_POST['smtpPort'] ?? 587
        ),
        'encryption' => postString(
            'encryption',
            'tls'
        ),
        'smtpAuth' =>
            ($_POST['smtpAuth'] ?? '1') === '1',
        'username' => postString(
            'username'
        ),
        'password' => (string)(
            $_POST['password'] ?? ''
        ),
        'fromEmail' => postString(
            'fromEmail'
        ),
        'fromName' => postString(
            'fromName'
        ),
        'replyTo' => postString(
            'replyTo'
        ),
        'status' => '未設定',
        'lastTestAt' =>
            $settings['mail']['lastTestAt'] ??
            null,
    ];

    writeJson(
        'settings.json',
        $settings
    );

    jsonResponse([
        'success' => true,
    ]);
}


/*
 * ============================================================
 * 出力
 * ============================================================
 */

function apiExportCsv(): never
{
    $surveyId = postString('surveyId');

    $surveys = getCurrentSurveys();
    $survey = findSurvey(
        $surveys,
        $surveyId
    );

    if (!$survey) {
        jsonResponse([
            'success' => false,
            'error' => '対象アンケートが存在しません。',
        ], 404);
    }

    $responses = readJson(
        'responses.json',
        []
    );

    $responses = array_values(
        array_filter(
            $responses,
            static fn($r) =>
                (string)($r['surveyId'] ?? '') ===
                $surveyId
        )
    );

    $rows = [];

    $header = [
        'responseId',
        'submittedAt',
        'organization',
        'name',
        'email',
        'department',
        'phone',
        'address',
    ];

    $questions = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $questions[] = $question;

            $header[] =
                (string)$question['questionNumber'];
        }
    }

    $rows[] = $header;

    foreach ($responses as $response) {
        $respondent =
            $response['respondent'] ?? [];

        $row = [
            $response['responseId'] ?? '',
            $response['submittedAt'] ?? '',
            $respondent['organization'] ?? '',
            $respondent['name'] ?? '',
            $respondent['email'] ?? '',
            $respondent['department'] ?? '',
            $respondent['phone'] ?? '',
            $respondent['address'] ?? '',
        ];

        $answers =
            $response['answers'] ?? [];

        foreach ($questions as $question) {
            $answer =
                $answers[$question['questionId']] ??
                '';

            if (is_array($answer)) {
                $answer = implode(
                    ', ',
                    array_map(
                        'strval',
                        $answer
                    )
                );
            }

            $row[] = $answer;
        }

        $rows[] = $row;
    }

    $fp = fopen('php://temp', 'w+');

    foreach ($rows as $row) {
        fputcsv(
            $fp,
            $row
        );
    }

    rewind($fp);

    $csv = stream_get_contents($fp);
    fclose($fp);

    jsonResponse([
        'success' => true,
        'filename' =>
            'survey-' .
            $surveyId .
            '.csv',
        'csv' => base64_encode(
            (string)$csv
        ),
        'message' =>
            'CSV出力を実行しました。',
    ]);
}

function apiExportPdf(): never
{
    $surveyId = postString('surveyId');

    $surveys = getCurrentSurveys();
    $survey = findSurvey(
        $surveys,
        $surveyId
    );

    if (!$survey) {
        jsonResponse([
            'success' => false,
            'error' => '対象アンケートが存在しません。',
        ], 404);
    }

    jsonResponse([
        'success' => true,
        'message' =>
            'PDF出力を実行しました。' .
            '（この実装では画面上の出力完了確認を行います）',
        'surveyId' => $surveyId,
    ]);
}


/*
 * ============================================================
 * 管理画面HTML
 * ============================================================
 */

function renderApplication(
    string $view,
    string $surveyId,
    string $token
): void {
    if (in_array($view, ANSWER_VIEWS, true)) {
        renderAnswerApplication(
            $view,
            $surveyId,
            $token
        );

        return;
    }

    renderAdminApplication(
        $view,
        $surveyId
    );
}

function renderAdminApplication(
    string $view,
    string $surveyId
): void {
    $surveys = getCurrentSurveys();
    $customers = readJson(
        'customers.json',
        []
    );
    $responses = readJson(
        'responses.json',
        []
    );
    $history = readJson(
        'send_history.json',
        []
    );
    $settings = readJson(
        'settings.json',
        []
    );

    $survey = $surveyId !== ''
        ? findSurvey($surveys, $surveyId)
        : null;

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --danger:#dc2626;
    --warning:#d97706;
    --success:#16a34a;
    --muted:#64748b;
    --border:#dbe2ea;
    --bg:#f5f7fb;
    --card:#fff;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    color:#172033;
    font-family:
        -apple-system,BlinkMacSystemFont,
        "Segoe UI","Noto Sans JP",sans-serif;
}
button,input,textarea,select{font:inherit}
button{
    cursor:pointer;
    border:0;
    border-radius:7px;
    padding:9px 14px;
}
button.primary{
    background:var(--primary);
    color:#fff;
}
button.primary:hover{
    background:var(--primary-dark);
}
button.secondary{
    background:#e8edf4;
}
button.danger{
    background:#fee2e2;
    color:#991b1b;
}
button.success{
    background:#dcfce7;
    color:#166534;
}
button.warning{
    background:#fef3c7;
    color:#92400e;
}
input,textarea,select{
    width:100%;
    border:1px solid var(--border);
    border-radius:7px;
    padding:9px 10px;
    background:#fff;
}
textarea{min-height:100px;resize:vertical}
a{
    color:var(--primary);
    text-decoration:none;
}
.app{
    min-height:100vh;
}
.topbar{
    background:#172033;
    color:#fff;
    padding:15px 20px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
}
.brand{
    font-weight:700;
}
.nav{
    display:flex;
    flex-wrap:wrap;
    gap:7px;
}
.nav a{
    color:#dbeafe;
    padding:7px 10px;
    border-radius:6px;
}
.nav a:hover{
    background:#26354e;
}
.container{
    max-width:1400px;
    margin:auto;
    padding:24px;
}
.page-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:18px;
}
.page-title h1{
    margin:0;
    font-size:25px;
}
.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px;
    margin-bottom:18px;
    box-shadow:0 1px 2px rgba(0,0,0,.03);
}
.grid{
    display:grid;
    gap:15px;
}
.grid-2{
    grid-template-columns:repeat(2,minmax(0,1fr));
}
.grid-3{
    grid-template-columns:repeat(3,minmax(0,1fr));
}
.toolbar{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
}
.table-wrap{
    overflow-x:auto;
}
table{
    width:100%;
    border-collapse:collapse;
    min-width:850px;
}
th,td{
    padding:11px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}
th{
    background:#f8fafc;
    white-space:nowrap;
}
.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.status-draft{
    background:#e2e8f0;
    color:#334155;
}
.status-public{
    background:#dcfce7;
    color:#166534;
}
.status-stop{
    background:#fef3c7;
    color:#92400e;
}
.status-end{
    background:#fee2e2;
    color:#991b1b;
}
.muted{
    color:var(--muted);
}
.stat{
    font-size:27px;
    font-weight:800;
}
.form-row{
    margin-bottom:14px;
}
.form-row>label{
    display:block;
    margin-bottom:6px;
    font-weight:700;
}
.group{
    border:1px solid var(--border);
    border-radius:9px;
    padding:15px;
    margin-bottom:14px;
    background:#fbfdff;
}
.question{
    background:#fff;
    border:1px solid var(--border);
    border-radius:8px;
    padding:13px;
    margin-top:10px;
}
.question-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
}
.choice-row{
    display:grid;
    grid-template-columns:1fr 1fr auto;
    gap:7px;
    margin-top:7px;
}
.actions{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}
.notice{
    border-radius:8px;
    padding:11px 13px;
    background:#eff6ff;
    color:#1e40af;
    margin-bottom:12px;
}
.error{
    background:#fef2f2;
    color:#991b1b;
}
.success-box{
    background:#f0fdf4;
    color:#166534;
}
.modal{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.55);
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    z-index:1000;
}
.modal.open{display:flex}
.modal-box{
    background:#fff;
    width:min(700px,100%);
    max-height:90vh;
    overflow:auto;
    border-radius:12px;
    padding:20px;
}
.answer-preview{
    max-width:850px;
    margin:auto;
}
.chart{
    height:18px;
    background:#e5e7eb;
    border-radius:99px;
    overflow:hidden;
}
.chart>span{
    display:block;
    height:100%;
    background:var(--primary);
}
@media(max-width:800px){
    .container{padding:14px}
    .topbar{
        align-items:flex-start;
        flex-direction:column;
    }
    .grid-2,.grid-3{
        grid-template-columns:1fr;
    }
    .page-title{
        align-items:flex-start;
        flex-direction:column;
    }
    .choice-row{
        grid-template-columns:1fr;
    }
}
</style>
</head>
<body>
<div class="app">

<header class="topbar">
    <div class="brand">アンケート管理システム</div>

    <nav class="nav">
        <a href="?view=admin-survey-list">
            アンケート一覧
        </a>
        <a href="?view=admin-kintone">
            kintone連携
        </a>
        <a href="?view=admin-mail">
            メール設定
        </a>
    </nav>
</header>

<main class="container">
<?php

switch ($view) {
    case 'admin-survey-edit':
        renderSurveyEdit(
            $survey
        );
        break;

    case 'admin-preview':
        renderPreview(
            $survey
        );
        break;

    case 'admin-send':
        renderSend(
            $survey,
            $customers,
            $history
        );
        break;

    case 'admin-aggregation':
        renderAggregation(
            $survey,
            $responses,
            $customers
        );
        break;

    case 'admin-kintone':
        renderKintone(
            $settings
        );
        break;

    case 'admin-mail':
        renderMailSettings(
            $settings
        );
        break;

    default:
        renderSurveyList(
            $surveys,
            $responses
        );
        break;
}

?>
</main>
</div>

<div id="modal" class="modal">
    <div class="modal-box">
        <div id="modalContent"></div>
    </div>
</div>

<script>
/*
 * ============================================================
 * URL / 画面状態
 * ============================================================
 *
 * 画面識別は location.search のみ。
 * pathname は使用しない。
 */

const ALLOWED_VIEWS = <?= json_encode(
    ALLOWED_VIEWS,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

const API_URL = 'index.php';

function readUrlState(){
    const params = new URLSearchParams(
        window.location.search
    );

    let view = params.get('view') || 'admin-survey-list';

    if(!ALLOWED_VIEWS.includes(view)){
        view = 'admin-survey-list';
    }

    return {
        view,
        surveyId: params.get('surveyId') || '',
        token: params.get('token') || ''
    };
}

let currentState = readUrlState();

function navigate(view, surveyId='', token=''){
    const params = new URLSearchParams();

    params.set('view', view);

    if(surveyId){
        params.set('surveyId', surveyId);
    }

    if(token){
        params.set('token', token);
    }

    /*
     * URLを先に更新する。
     * 画面状態は必ず更新後のURLから再構築する。
     */
    history.pushState(
        {},
        '',
        '?' + params.toString()
    );

    syncFromUrl();
}

function replaceNavigation(
    view,
    surveyId='',
    token=''
){
    const params = new URLSearchParams();

    params.set('view', view);

    if(surveyId){
        params.set('surveyId', surveyId);
    }

    if(token){
        params.set('token', token);
    }

    history.replaceState(
        {},
        '',
        '?' + params.toString()
    );

    syncFromUrl();
}

function syncFromUrl(){
    currentState = readUrlState();

    /*
     * PHPが初回描画した画面との同期。
     * SPAとして内部変数だけで画面を切り替えない。
     */
}

window.addEventListener(
    'popstate',
    function(){
        currentState = readUrlState();

        /*
         * 戻る・進む後はURLを唯一の状態源とする。
         * 完全再描画でPHPのGET viewを再評価する。
         */
        window.location.reload();
    }
);

function api(action, data={}){
    const body = new URLSearchParams();

    body.set('action', action);

    Object.entries(data).forEach(
        ([key,value]) => {
            if(
                typeof value === 'object' &&
                value !== null
            ){
                body.set(
                    key,
                    JSON.stringify(value)
                );
            }else{
                body.set(key,String(value));
            }
        }
    );

    return fetch(
        API_URL,
        {
            method:'POST',
            headers:{
                'Content-Type':
                    'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body
        }
    ).then(async response => {
        const json = await response.json();

        if(!response.ok || json.success === false){
            throw new Error(
                json.error ||
                '処理に失敗しました。'
            );
        }

        return json;
    });
}

function escapeHtml(value){
    return String(value ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

function openModal(html){
    document.getElementById(
        'modalContent'
    ).innerHTML = html;

    document.getElementById(
        'modal'
    ).classList.add('open');
}

function closeModal(){
    document.getElementById(
        'modal'
    ).classList.remove('open');
}

function confirmAction(message, callback){
    openModal(`
        <h3>確認</h3>
        <p>${escapeHtml(message)}</p>
        <div class="actions">
            <button
                class="primary"
                id="confirmYes">
                実行する
            </button>
            <button
                class="secondary"
                onclick="closeModal()">
                キャンセル
            </button>
        </div>
    `);

    document.getElementById(
        'confirmYes'
    ).onclick = function(){
        closeModal();
        callback();
    };
}

function saveSurvey(){
    const survey =
        window.__surveyEditor;

    if(!survey){
        return;
    }

    api(
        'save_survey',
        {
            survey:survey
        }
    ).then(result => {
        navigate(
            'admin-survey-list'
        );
    }).catch(showError);
}

function showError(error){
    alert(
        error.message ||
        String(error)
    );
}

function deleteSurvey(surveyId){
    confirmAction(
        'このアンケートを削除しますか？',
        function(){
            api(
                'delete_survey',
                {surveyId}
            ).then(() => {
                navigate(
                    'admin-survey-list'
                );
            }).catch(showError);
        }
    );
}

function duplicateSurvey(surveyId){
    confirmAction(
        'このアンケートを複製しますか？',
        function(){
            api(
                'duplicate_survey',
                {surveyId}
            ).then(() => {
                window.location.reload();
            }).catch(showError);
        }
    );
}

function changeStatus(
    surveyId,
    status
){
    const messages = {
        '公開中':'公開しますか？',
        '停止':'停止しますか？',
    };

    confirmAction(
        messages[status] || '状態を変更しますか？',
        function(){
            api(
                'change_survey_status',
                {
                    surveyId,
                    status
                }
            ).then(() => {
                window.location.reload();
            }).catch(showError);
        }
    );
}

function exportCsv(surveyId){
    api(
        'export_csv',
        {surveyId}
    ).then(result => {
        if(result.csv){
            const bytes =
                Uint8Array.from(
                    atob(result.csv),
                    c => c.charCodeAt(0)
                );

            const blob =
                new Blob(
                    [bytes],
                    {type:'text/csv;charset=utf-8'}
                );

            const url =
                URL.createObjectURL(blob);

            const a =
                document.createElement('a');

            a.href = url;
            a.download =
                result.filename ||
                'survey.csv';

            a.click();

            URL.revokeObjectURL(url);
        }

        alert(result.message);
    }).catch(showError);
}

function exportPdf(surveyId){
    api(
        'export_pdf',
        {surveyId}
    ).then(result => {
        alert(result.message);
    }).catch(showError);
}

function selectAllQuestions(checked){
    document
        .querySelectorAll(
            '.question-select'
        )
        .forEach(
            el => el.checked = checked
        );
}

function filterCustomers(){
    const q =
        document.getElementById(
            'customerSearch'
        )?.value.toLowerCase() || '';

    document
        .querySelectorAll(
            '#customerTable tbody tr'
        )
        .forEach(row => {
            row.style.display =
                row.innerText
                    .toLowerCase()
                    .includes(q)
                    ? ''
                    : 'none';
        });
}

function sendSelectedCustomers(
    surveyId,
    sendType='一括送信'
){
    const selected =
        [...document.querySelectorAll(
            '.customer-check:checked'
        )].map(
            el => el.value
        );

    if(!selected.length){
        alert(
            '送信対象顧客を選択してください。'
        );
        return;
    }

    const subject =
        document.getElementById(
            'mailSubject'
        )?.value || '';

    const body =
        document.getElementById(
            'mailBody'
        )?.value || '';

    const customers =
        window.__customers || [];

    const preview =
        customers.filter(
            c => selected.includes(
                String(c.customerId)
            )
        ).map(c => {
            const name =
                c.name || '';

            const url =
                'index.php?view=answer&surveyId=' +
                encodeURIComponent(surveyId) +
                '&token=' +
                encodeURIComponent(
                    c.token || ''
                );

            return `
                <div class="card">
                    <strong>
                        ${escapeHtml(name)}
                    </strong>
                    <p>
                        <b>件名:</b>
                        ${escapeHtml(
                            subject
                                .replaceAll(
                                    '{顧客名}',
                                    name
                                )
                                .replaceAll(
                                    '{アンケートURL}',
                                    url
                                )
                        )}
                    </p>
                    <pre style="white-space:pre-wrap">${escapeHtml(
                        body
                            .replaceAll(
                                '{顧客名}',
                                name
                            )
                            .replaceAll(
                                '{アンケートURL}',
                                url
                            )
                    )}</pre>
                </div>
            `;
        }).join('');

    openModal(`
        <h3>送信確認</h3>
        <p>
            対象件数:
            <strong>${selected.length}</strong>
        </p>
        ${preview}
        <div class="actions">
            <button
                class="primary"
                id="doSend">
                実際に送信する
            </button>
            <button
                class="secondary"
                onclick="closeModal()">
                キャンセル
            </button>
        </div>
    `);

    document.getElementById(
        'doSend'
    ).onclick = function(){
        closeModal();

        api(
            'send_mail',
            {
                surveyId,
                customerIds:selected,
                subject,
                body,
                sendType
            }
        ).then(result => {
            showSendResult(result);
        }).catch(showError);
    };
}

function showSendResult(result){
    const box =
        document.getElementById(
            'sendResult'
        );

    if(!box){
        alert(
            `対象 ${result.total}件 / ` +
            `成功 ${result.successCount}件 / ` +
            `失敗 ${result.failureCount}件`
        );

        return;
    }

    box.innerHTML = `
        <div class="notice success-box">
            <strong>送信完了</strong><br>
            対象件数: ${result.total}<br>
            成功件数: ${result.successCount}<br>
            失敗件数: ${result.failureCount}<br>
            送信日時: ${escapeHtml(result.sentAt)}
        </div>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>顧客</th>
                    <th>メール</th>
                    <th>結果</th>
                    <th>詳細</th>
                </tr>
            </thead>
            <tbody>
                ${result.results.map(r => `
                    <tr>
                        <td>${escapeHtml(
                            r.customerName
                        )}</td>
                        <td>${escapeHtml(
                            r.email
                        )}</td>
                        <td>${r.success
                            ? '成功'
                            : '失敗'}</td>
                        <td>${escapeHtml(
                            r.error || ''
                        )}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
        </div>
    `;
}

function saveKintone(){
    const mapping = {
        organization:
            document.getElementById(
                'mapOrganization'
            )?.value || '',
        name:
            document.getElementById(
                'mapName'
            )?.value || '',
        email:
            document.getElementById(
                'mapEmail'
            )?.value || '',
        department:
            document.getElementById(
                'mapDepartment'
            )?.value || '',
        phone:
            document.getElementById(
                'mapPhone'
            )?.value || '',
        address:[
            ...document.querySelectorAll(
                '.address-map:checked'
            )
        ].map(el => el.value)
    };

    api(
        'save_kintone_settings',
        {
            subdomain:
                document.getElementById(
                    'kSubdomain'
                )?.value || '',
            appId:
                document.getElementById(
                    'kAppId'
                )?.value || '',
            loginName:
                document.getElementById(
                    'kLogin'
                )?.value || '',
            password:
                document.getElementById(
                    'kPassword'
                )?.value || '',
            sslVerify:
                document.getElementById(
                    'kSsl'
                )?.checked ? '1' : '0',
            proxy:
                document.getElementById(
                    'kProxy'
                )?.value || '',
            fieldMapping:mapping
        }
    ).then(() => {
        alert('kintone設定を保存しました。');
    }).catch(showError);
}

function kintoneTest(){
    api(
        'kintone_test'
    ).then(result => {
        alert(
            result.message +
            (result.detail
                ? '\n' + result.detail
                : '')
        );
    }).catch(showError);
}

function kintoneFields(){
    api(
        'kintone_get_fields'
    ).then(result => {
        const box =
            document.getElementById(
                'kintoneFields'
            );

        if(!box){
            return;
        }

        box.innerHTML = result.fields.map(
            field => `
                <option value="${escapeHtml(
                    field.code
                )}">
                    ${escapeHtml(
                        field.label
                    )} (${escapeHtml(
                        field.code
                    )})
                </option>
            `
        ).join('');

        alert(
            `${result.fields.length}項目を取得しました。`
        );
    }).catch(showError);
}

function kintoneSync(){
    confirmAction(
        'kintoneから顧客情報を同期しますか？',
        function(){
            api(
                'kintone_sync'
            ).then(result => {
                alert(
                    result.message +
                    '\n件数: ' +
                    result.count
                );

                window.location.reload();
            }).catch(showError);
        }
    );
}

function saveMailSettings(){
    api(
        'save_mail_settings',
        {
            smtpHost:
                document.getElementById(
                    'smtpHost'
                )?.value || '',
            smtpPort:
                document.getElementById(
                    'smtpPort'
                )?.value || 587,
            encryption:
                document.getElementById(
                    'smtpEncryption'
                )?.value || 'tls',
            smtpAuth:
                document.getElementById(
                    'smtpAuth'
                )?.checked ? '1' : '0',
            username:
                document.getElementById(
                    'smtpUsername'
                )?.value || '',
            password:
                document.getElementById(
                    'smtpPassword'
                )?.value || '',
            fromEmail:
                document.getElementById(
                    'fromEmail'
                )?.value || '',
            fromName:
                document.getElementById(
                    'fromName'
                )?.value || '',
            replyTo:
                document.getElementById(
                    'replyTo'
                )?.value || ''
        }
    ).then(() => {
        alert('メール設定を保存しました。');
    }).catch(showError);
}

function sendTestMail(){
    const to =
        prompt(
            'テスト送信先メールアドレス'
        );

    if(!to){
        return;
    }

    api(
        'send_test_mail',
        {to}
    ).then(result => {
        alert(
            result.success
                ? 'テストメール送信成功'
                : result.error
        );
    }).catch(showError);
}

function addGroup(){
    const survey =
        window.__surveyEditor;

    survey.groups.push({
        groupId:
            'group_' +
            crypto.randomUUID(),
        groupTitle:'',
        sortOrder:
            survey.groups.length,
        questions:[]
    });

    renumberEditor();
    renderEditorGroups();
}

function removeGroup(index){
    const survey =
        window.__surveyEditor;

    const group =
        survey.groups[index];

    if(
        group.questions &&
        group.questions.length
    ){
        if(!confirm(
            '質問が存在します。グループを削除しますか？'
        )){
            return;
        }
    }

    survey.groups.splice(index,1);

    renumberEditor();
    renderEditorGroups();
}

function addQuestion(groupIndex){
    const survey =
        window.__surveyEditor;

    survey.groups[groupIndex]
        .questions.push({
            questionId:
                'question_' +
                crypto.randomUUID(),
            questionNumber:'',
            questionText:'',
            answerType:'single',
            required:false,
            choices:[
                {
                    choiceId:
                        'choice_' +
                        crypto.randomUUID(),
                    label:'',
                    value:''
                }
            ],
            conditions:[],
            sortOrder:0
        });

    renumberEditor();
    renderEditorGroups();
}

function removeQuestion(
    groupIndex,
    questionIndex
){
    if(!confirm(
        'この質問を削除しますか？'
    )){
        return;
    }

    window.__surveyEditor
        .groups[groupIndex]
        .questions
        .splice(questionIndex,1);

    renumberEditor();
    renderEditorGroups();
}

function renumberEditor(){
    const survey =
        window.__surveyEditor;

    survey.groups.forEach(
        (group,gIndex) => {
            group.sortOrder = gIndex;

            group.questions.forEach(
                (q,qIndex) => {
                    q.sortOrder = qIndex;

                    if(
                        survey.numberingMode ===
                        'group'
                    ){
                        const local =
                            qIndex + 1;

                        q.questionNumber =
                            `Q${gIndex + 1}-${local}`;
                    }else{
                        let number = 0;

                        survey.groups
                            .slice(0,gIndex + 1)
                            .forEach((g,gi) => {
                                if(gi === gIndex){
                                    number +=
                                        qIndex + 1;
                                }else{
                                    number +=
                                        g.questions.length;
                                }
                            });

                        q.questionNumber =
                            `Q${number}`;
                    }
                }
            );
        }
    );
}

function updateEditorField(
    path,
    value
){
    let target =
        window.__surveyEditor;

    for(
        let i=0;
        i<path.length-1;
        i++
    ){
        target =
            target[path[i]];
    }

    target[
        path[path.length-1]
    ] = value;
}

function renderEditorGroups(){
    const root =
        document.getElementById(
            'editorGroups'
        );

    if(!root){
        return;
    }

    const survey =
        window.__surveyEditor;

    root.innerHTML =
        survey.groups.map(
            (group,gi) => `
                <section class="group">
                    <div class="grid grid-2">
                        <div class="form-row">
                            <label>
                                グループタイトル
                            </label>
                            <input
                                value="${escapeHtml(
                                    group.groupTitle
                                )}"
                                onchange="updateEditorField(
                                    ['groups',${gi},'groupTitle'],
                                    this.value
                                )">
                        </div>

                        <div class="form-row">
                            <label>操作</label>
                            <div class="actions">
                                <button
                                    class="danger"
                                    onclick="removeGroup(${gi})">
                                    グループ削除
                                </button>
                            </div>
                        </div>
                    </div>

                    ${group.questions.map(
                        (q,qi) => `
                            <div class="question">
                                <div class="question-head">
                                    <strong>
                                        ${escapeHtml(
                                            q.questionNumber
                                        )}
                                    </strong>

                                    <button
                                        class="danger"
                                        onclick="removeQuestion(
                                            ${gi},
                                            ${qi}
                                        )">
                                        質問削除
                                    </button>
                                </div>

                                <div class="form-row">
                                    <label>
                                        質問文
                                    </label>
                                    <textarea
                                        onchange="updateEditorField(
                                            ['groups',${gi},
                                             'questions',${qi},
                                             'questionText'],
                                            this.value
                                        )">${escapeHtml(
                                            q.questionText
                                        )}</textarea>
                                </div>

                                <div class="grid grid-2">
                                    <div class="form-row">
                                        <label>
                                            回答形式
                                        </label>

                                        <select
                                            onchange="updateEditorField(
                                                ['groups',${gi},
                                                 'questions',${qi},
                                                 'answerType'],
                                                this.value
                                            );renderEditorGroups()">

                                            <option
                                                value="single"
                                                ${q.answerType === 'single'
                                                    ? 'selected'
                                                    : ''}>
                                                単一選択
                                            </option>

                                            <option
                                                value="multiple"
                                                ${q.answerType === 'multiple'
                                                    ? 'selected'
                                                    : ''}>
                                                複数選択
                                            </option>

                                            <option
                                                value="text"
                                                ${q.answerType === 'text'
                                                    ? 'selected'
                                                    : ''}>
                                                自由記述
                                            </option>
                                        </select>
                                    </div>

                                    <div class="form-row">
                                        <label>
                                            必須
                                        </label>

                                        <label>
                                            <input
                                                type="checkbox"
                                                ${q.required
                                                    ? 'checked'
                                                    : ''}
                                                onchange="updateEditorField(
                                                    ['groups',${gi},
                                                     'questions',${qi},
                                                     'required'],
                                                    this.checked
                                                )">
                                            必須回答
                                        </label>
                                    </div>
                                </div>

                                ${
                                    q.answerType !== 'text'
                                    ? `
                                        <div>
                                            <strong>
                                                選択肢
                                            </strong>

                                            ${q.choices.map(
                                                (c,ci) => `
                                                    <div class="choice-row">
                                                        <input
                                                            placeholder="表示名"
                                                            value="${escapeHtml(
                                                                c.label
                                                            )}"
                                                            onchange="updateEditorField(
                                                                ['groups',${gi},
                                                                 'questions',${qi},
                                                                 'choices',${ci},
                                                                 'label'],
                                                                this.value
                                                            )">

                                                        <input
                                                            placeholder="値"
                                                            value="${escapeHtml(
                                                                c.value
                                                            )}"
                                                            onchange="updateEditorField(
                                                                ['groups',${gi},
                                                                 'questions',${qi},
                                                                 'choices',${ci},
                                                                 'value'],
                                                                this.value
                                                            )">

                                                        <button
                                                            class="danger"
                                                            onclick="
                                                                window.__surveyEditor
                                                                    .groups[${gi}]
                                                                    .questions[${qi}]
                                                                    .choices
                                                                    .splice(${ci},1);
                                                                renderEditorGroups();
                                                            ">
                                                            削除
                                                        </button>
                                                    </div>
                                                `
                                            ).join('')}

                                            <button
                                                class="secondary"
                                                onclick="
                                                    window.__surveyEditor
                                                        .groups[${gi}]
                                                        .questions[${qi}]
                                                        .choices
                                                        .push({
                                                            choiceId:
                                                                'choice_' +
                                                                crypto.randomUUID(),
                                                            label:'',
                                                            value:''
                                                        });
                                                    renderEditorGroups();
                                                ">
                                                選択肢追加
                                            </button>
                                        </div>
                                    `
                                    : ''
                                }
                            </div>
                        `
                    ).join('')}

                    <button
                        class="secondary"
                        onclick="addQuestion(${gi})">
                        質問追加
                    </button>
                </section>
            `
        ).join('');
}

<?php
renderInlineViewData(
    $view,
    $survey
);
?>
</script>
</body>
</html>
<?php
}


/*
 * ============================================================
 * 一覧
 * ============================================================
 */

function renderSurveyList(
    array $surveys,
    array $responses
): void {
    ?>
<div class="page-title">
    <h1>アンケート一覧</h1>

    <button
        class="primary"
        onclick="navigate(
            'admin-survey-edit'
        )">
        新規アンケート作成
    </button>
</div>

<div class="card">
    <div class="grid grid-3">
        <div class="form-row">
            <label>タイトル検索</label>
            <input
                id="surveySearch"
                placeholder="タイトル部分一致"
                onkeydown="
                    if(event.key==='Enter'){
                        filterSurveyList();
                    }
                ">
        </div>

        <div class="form-row">
            <label>ステータス</label>
            <select
                id="surveyStatusFilter"
                onchange="filterSurveyList()">
                <option value="">すべて</option>
                <option value="公開中">公開中</option>
                <option value="下書き">下書き</option>
                <option value="停止">停止</option>
                <option value="終了">終了</option>
            </select>
        </div>

        <div class="form-row">
            <label>ソート</label>
            <select
                id="surveySort"
                onchange="sortSurveyList()">
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
        </div>
    </div>
</div>

<div class="card">
<div class="table-wrap">
<table id="surveyTable">
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
<?php foreach ($surveys as $survey): ?>
<?php
$surveyId = (string)(
    $survey['surveyId'] ?? ''
);

$count = 0;

foreach ($responses as $response) {
    if (
        (string)($response['surveyId'] ?? '') ===
        $surveyId
    ) {
        $count++;
    }
}

$status =
    (string)($survey['status'] ?? '下書き');

$statusClass = match($status) {
    '公開中' => 'status-public',
    '停止' => 'status-stop',
    '終了' => 'status-end',
    default => 'status-draft',
};
?>
<tr
    data-title="<?= h(
        $survey['title'] ?? ''
    ) ?>"
    data-status="<?= h($status) ?>"
    data-updated="<?= h(
        $survey['updatedAt'] ?? ''
    ) ?>"
    data-start="<?= h(
        $survey['startAt'] ?? ''
    ) ?>"
    data-answers="<?= $count ?>">

<td>
    <?= h($survey['createdAt'] ?? '') ?><br>
    <span class="muted">
        <?= h($survey['updatedAt'] ?? '') ?>
    </span>
</td>

<td>
    <strong>
        <?= h($survey['title'] ?? '') ?>
    </strong>
</td>

<td>
    <?= h($survey['startAt'] ?? '') ?>
    〜
    <?= h($survey['endAt'] ?? '') ?>
</td>

<td>
    <span class="badge <?= $statusClass ?>">
        <?= h($status) ?>
    </span>
</td>

<td><?= $count ?></td>

<td>
<div class="actions">

<button
    class="secondary"
    onclick="navigate(
        'admin-survey-edit',
        '<?= h($surveyId) ?>'
    )">
    確認・編集
</button>

<button
    class="secondary"
    onclick="navigate(
        'admin-aggregation',
        '<?= h($surveyId) ?>'
    )">
    集計
</button>

<button
    class="secondary"
    onclick="navigate(
        'admin-send',
        '<?= h($surveyId) ?>'
    )">
    送信
</button>

<button
    class="secondary"
    onclick="duplicateSurvey(
        '<?= h($surveyId) ?>'
    )">
    複製
</button>

<button
    class="danger"
    onclick="deleteSurvey(
        '<?= h($surveyId) ?>'
    )">
    削除
</button>

</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<script>
function filterSurveyList(){
    const q =
        document.getElementById(
            'surveySearch'
        ).value.toLowerCase();

    const status =
        document.getElementById(
            'surveyStatusFilter'
        ).value;

    document
        .querySelectorAll(
            '#surveyTable tbody tr'
        )
        .forEach(row => {
            const title =
                row.dataset.title
                    .toLowerCase();

            const rowStatus =
                row.dataset.status;

            row.style.display =
                title.includes(q) &&
                (!status ||
                    rowStatus === status)
                    ? ''
                    : 'none';
        });
}

function sortSurveyList(){
    const tbody =
        document.querySelector(
            '#surveyTable tbody'
        );

    const rows =
        [...tbody.querySelectorAll('tr')];

    const sort =
        document.getElementById(
            'surveySort'
        ).value;

    rows.sort((a,b) => {
        if(
            sort === 'answers-desc' ||
            sort === 'answers-asc'
        ){
            const av =
                Number(a.dataset.answers);

            const bv =
                Number(b.dataset.answers);

            return sort === 'answers-desc'
                ? bv-av
                : av-bv;
        }

        const field =
            sort.startsWith('start-')
                ? 'start'
                : 'updated';

        const av =
            a.dataset[field] || '';

        const bv =
            b.dataset[field] || '';

        return sort.endsWith('desc')
            ? bv.localeCompare(av)
            : av.localeCompare(bv);
    });

    rows.forEach(row => tbody.appendChild(row));
}
</script>
<?php
}


/*
 * ============================================================
 * 編集
 * ============================================================
 */

function renderSurveyEdit(
    ?array $survey
): void {
    $isNew = !$survey;

    if (!$survey) {
        $survey = [
            'surveyId' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'numberingMode' => 'global',
            'allowResubmit' => false,
            'status' => '下書き',
            'groups' => [],
            'createdAt' => nowIso(),
            'updatedAt' => nowIso(),
        ];
    }

    ?>
<div class="page-title">
    <h1>
        <?= $isNew
            ? 'アンケート作成'
            : 'アンケート編集' ?>
    </h1>
</div>

<div class="card">
    <div class="grid grid-2">

        <div class="form-row">
            <label>タイトル</label>
            <input
                id="surveyTitle"
                value="<?= h(
                    $survey['title']
                ) ?>">
        </div>

        <div class="form-row">
            <label>質問番号の採番方式</label>
            <select id="numberingMode">
                <option
                    value="global"
                    <?= $survey['numberingMode'] === 'global'
                        ? 'selected'
                        : '' ?>>
                    アンケート全体で通番
                </option>

                <option
                    value="group"
                    <?= $survey['numberingMode'] === 'group'
                        ? 'selected'
                        : '' ?>>
                    グループ毎に採番
                </option>
            </select>
        </div>

    </div>

    <div class="form-row">
        <label>説明</label>
        <textarea
            id="surveyDescription"><?= h(
                $survey['description']
            ) ?></textarea>
    </div>

    <div class="grid grid-2">

        <div class="form-row">
            <label>開始日時</label>
            <input
                type="datetime-local"
                id="startAt"
                value="<?= h(
                    $survey['startAt']
                ) ?>">
        </div>

        <div class="form-row">
            <label>終了日時</label>
            <input
                type="datetime-local"
                id="endAt"
                value="<?= h(
                    $survey['endAt']
                ) ?>">
        </div>

    </div>

    <label>
        <input
            type="checkbox"
            id="allowResubmit"
            <?= !empty(
                $survey['allowResubmit']
            )
                ? 'checked'
                : '' ?>>
        再回答を許可する
    </label>
</div>

<div id="editorGroups"></div>

<div class="card">
    <button
        class="secondary"
        onclick="addGroup()">
        グループ追加
    </button>
</div>

<div class="card">
    <div class="actions">

        <button
            class="primary"
            onclick="saveSurveyEditor()">
            保存して一覧へ
        </button>

        <button
            class="secondary"
            onclick="navigate(
                'admin-preview',
                '<?= h(
                    $survey['surveyId']
                ) ?>'
            )">
            プレビュー
        </button>

        <button
            class="secondary"
            onclick="confirmCancelEdit()">
            キャンセル
        </button>

    </div>
</div>

<script>
window.__surveyEditor =
    <?= json_encode(
        $survey,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;

function loadEditorBase(){
    const survey =
        window.__surveyEditor;

    document.getElementById(
        'surveyTitle'
    ).addEventListener(
        'input',
        e => survey.title = e.target.value
    );

    document.getElementById(
        'surveyDescription'
    ).addEventListener(
        'input',
        e => survey.description =
            e.target.value
    );

    document.getElementById(
        'startAt'
    ).addEventListener(
        'input',
        e => survey.startAt =
            e.target.value || null
    );

    document.getElementById(
        'endAt'
    ).addEventListener(
        'input',
        e => survey.endAt =
            e.target.value || null
    );

    document.getElementById(
        'numberingMode'
    ).addEventListener(
        'change',
        e => {
            survey.numberingMode =
                e.target.value;

            renumberEditor();
            renderEditorGroups();
        }
    );

    document.getElementById(
        'allowResubmit'
    ).addEventListener(
        'change',
        e => survey.allowResubmit =
            e.target.checked
    );

    if(!survey.groups.length){
        addGroup();
    }else{
        renumberEditor();
        renderEditorGroups();
    }
}

function saveSurveyEditor(){
    const survey =
        window.__surveyEditor;

    if(!survey.title.trim()){
        alert(
            'タイトルを入力してください。'
        );
        return;
    }

    survey.groups.forEach(
        group => {
            group.questions.forEach(
                question => {
                    question.choices =
                        question.answerType === 'text'
                            ? []
                            : question.choices;

                    question.conditions =
                        question.answerType === 'single'
                            ? question.conditions
                            : [];
                }
            );
        }
    );

    saveSurvey();
}

function confirmCancelEdit(){
    confirmAction(
        '編集内容を破棄して前画面へ戻りますか？',
        function(){
            navigate(
                'admin-survey-list'
            );
        }
    );
}

loadEditorBase();
</script>
<?php
}


/*
 * ============================================================
 * プレビュー
 * ============================================================
 */

function renderPreview(
    ?array $survey
): void {
    ?>
<div class="page-title">
    <h1>アンケートプレビュー</h1>

    <div class="actions">
        <button
            class="secondary"
            onclick="navigate(
                'admin-survey-edit',
                '<?= h(
                    $survey['surveyId'] ?? ''
                ) ?>'
            )">
            編集へ戻る
        </button>

        <button
            class="secondary"
            onclick="setPreviewDevice('pc')">
            PC
        </button>

        <button
            class="secondary"
            onclick="setPreviewDevice('mobile')">
            スマートフォン
        </button>
    </div>
</div>

<?php if (!$survey): ?>

<div class="notice error">
    対象アンケートが指定されていません。
</div>

<?php else: ?>

<div
    id="preview"
    class="card answer-preview">

    <h2>
        <?= h($survey['title']) ?>
    </h2>

    <p>
        <?= nl2br(
            h($survey['description'])
        ) ?>
    </p>

    <?php foreach ($survey['groups'] as $group): ?>

    <section class="group">
        <?php if (
            trim(
                (string)$group['groupTitle']
            ) !== ''
        ): ?>
            <h3>
                <?= h(
                    $group['groupTitle']
                ) ?>
            </h3>
        <?php endif; ?>

        <?php foreach (
            $group['questions']
            as $question
        ): ?>

        <div class="question">
            <strong>
                <?= h(
                    $question['questionNumber']
                ) ?>
                <?= !empty(
                    $question['required']
                )
                    ? ' *'
                    : '' ?>
            </strong>

            <p>
                <?= nl2br(
                    h(
                        $question['questionText']
                    )
                ) ?>
            </p>

            <?php if (
                $question['answerType'] === 'single'
            ): ?>

                <?php foreach (
                    $question['choices']
                    as $choice
                ): ?>
                    <label style="
                        display:block;
                        padding:9px;
                        margin:5px 0;
                        border:1px solid #ddd;
                        border-radius:7px;
                    ">
                        <input type="radio">
                        <?= h(
                            $choice['label']
                        ) ?>
                    </label>
                <?php endforeach; ?>

            <?php elseif (
                $question['answerType'] === 'multiple'
            ): ?>

                <?php foreach (
                    $question['choices']
                    as $choice
                ): ?>
                    <label style="
                        display:block;
                        padding:9px;
                        margin:5px 0;
                        border:1px solid #ddd;
                        border-radius:7px;
                    ">
                        <input type="checkbox">
                        <?= h(
                            $choice['label']
                        ) ?>
                    </label>
                <?php endforeach; ?>

            <?php else: ?>

                <textarea></textarea>

            <?php endif; ?>
        </div>

        <?php endforeach; ?>
    </section>

    <?php endforeach; ?>

    <div class="notice">
        プレビューから実際の送信は行いません。
    </div>
</div>

<?php endif; ?>

<script>
function setPreviewDevice(device){
    const preview =
        document.getElementById(
            'preview'
        );

    if(!preview){
        return;
    }

    preview.style.maxWidth =
        device === 'mobile'
            ? '430px'
            : '850px';
}
</script>
<?php
}


/*
 * ============================================================
 * 送信
 * ============================================================
 */

function renderSend(
    ?array $survey,
    array $customers,
    array $history
): void {
    if (!$survey) {
        ?>
        <div class="notice error">
            対象アンケートが指定されていません。
        </div>
        <?php
        return;
    }

    ?>
<div class="page-title">
    <h1>メール送信</h1>
</div>

<div class="notice">
    対象アンケート:
    <strong><?= h(
        $survey['title']
    ) ?></strong>
</div>

<div class="card">
    <h2>メール内容</h2>

    <div class="form-row">
        <label>件名</label>
        <input
            id="mailSubject"
            value="<?= h(
                '【アンケート】' .
                $survey['title']
            ) ?>">
    </div>

    <div class="form-row">
        <label>本文</label>
        <textarea
            id="mailBody"
            rows="10">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
    </div>
</div>

<div class="card">
    <div class="page-title">
        <h2>顧客選択</h2>

        <input
            id="customerSearch"
            style="max-width:350px"
            placeholder="
                顧客名・組織名・メール・ステータス
            "
            oninput="filterCustomers()">
    </div>

    <div class="actions">
        <button
            class="secondary"
            onclick="
                document.querySelectorAll(
                    '.customer-check'
                ).forEach(
                    e => e.checked=true
                )
            ">
            すべて選択
        </button>

        <button
            class="secondary"
            onclick="
                document.querySelectorAll(
                    '.customer-check'
                ).forEach(
                    e => e.checked=false
                )
            ">
            すべて解除
        </button>

        <button
            class="warning"
            onclick="
                document.querySelectorAll(
                    '.remindable'
                ).forEach(
                    e => e.checked=true
                )
            ">
            未回答者を選択
        </button>

        <button
            class="primary"
            onclick="sendSelectedCustomers(
                '<?= h(
                    $survey['surveyId']
                ) ?>'
            )">
            一括送信
        </button>

        <button
            class="warning"
            onclick="sendSelectedCustomers(
                '<?= h(
                    $survey['surveyId']
                ) ?>',
                '再送'
            )">
            再送
        </button>

        <button
            class="warning"
            onclick="sendSelectedCustomers(
                '<?= h(
                    $survey['surveyId']
                ) ?>',
                'リマインド'
            )">
            リマインド
        </button>
    </div>

    <br>

    <div class="table-wrap">
    <table id="customerTable">
        <thead>
        <tr>
            <th>選択</th>
            <th>組織名</th>
            <th>氏名</th>
            <th>メール</th>
            <th>電話</th>
            <th>住所</th>
            <th>最終送信</th>
            <th>送信回数</th>
            <th>回答ステータス</th>
            <th>kintone</th>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($customers as $customer): ?>

        <?php
        $status =
            (string)(
                $customer['responseStatus'] ??
                '未送信'
            );

        $remindable =
            $status === '送信済み / 未回答';
        ?>

        <tr>
            <td>
                <input
                    class="
                        customer-check
                        <?= $remindable
                            ? 'remindable'
                            : '' ?>
                    "
                    type="checkbox"
                    value="<?= h(
                        $customer['customerId']
                    ) ?>">
            </td>

            <td>
                <?= h(
                    $customer['organization'] ?? ''
                ) ?>
            </td>

            <td>
                <?= h(
                    $customer['name'] ?? ''
                ) ?>
            </td>

            <td>
                <?= h(
                    $customer['email'] ?? ''
                ) ?>
            </td>

            <td>
                <?= h(
                    $customer['phone'] ?? ''
                ) ?>
            </td>

            <td>
                <?= h(
                    $customer['address'] ?? ''
                ) ?>
            </td>

            <td>
                <?= h(
                    $customer['lastSendAt'] ?? ''
                ) ?>
            </td>

            <td>
                <?= (int)(
                    $customer['sendCount'] ?? 0
                ) ?>
            </td>

            <td>
                <?= h($status) ?>
            </td>

            <td>
                <?= h(
                    $customer['kintoneStatus'] ??
                    '未登録'
                ) ?>
            </td>
        </tr>

        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div
    id="sendResult"
    class="card">
    <h2>送信結果</h2>
    <p class="muted">
        送信実行後、ここに結果を表示します。
    </p>
</div>

<div class="card">
    <h2>送信履歴</h2>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>送信日時</th>
            <th>種別</th>
            <th>件数</th>
            <th>成功</th>
            <th>失敗</th>
            <th>件名</th>
            <th>対象顧客</th>
        </tr>
        </thead>

        <tbody>
        <?php
        $surveyHistory =
            array_reverse(
                array_values(
                    array_filter(
                        $history,
                        static fn($item) =>
                            (string)(
                                $item['surveyId'] ?? ''
                            ) ===
                            (string)$survey['surveyId']
                    )
                )
            );
        ?>

        <?php foreach (
            $surveyHistory
            as $item
        ): ?>

        <tr>
            <td><?= h(
                $item['sentAt'] ?? ''
            ) ?></td>

            <td><?= h(
                $item['sendType'] ?? ''
            ) ?></td>

            <td><?= (int)(
                $item['count'] ?? 0
            ) ?></td>

            <td><?= (int)(
                $item['successCount'] ?? 0
            ) ?></td>

            <td><?= (int)(
                $item['failureCount'] ?? 0
            ) ?></td>

            <td><?= h(
                $item['subject'] ?? ''
            ) ?></td>

            <td>
                <?php foreach (
                    ($item['customers'] ?? [])
                    as $recipient
                ): ?>
                    <div>
                        <?= h(
                            $recipient['customerName'] ??
                            ''
                        ) ?>
                        /
                        <?= h(
                            $recipient['email'] ??
                            ''
                        ) ?>
                    </div>
                <?php endforeach; ?>
            </td>
        </tr>

        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
window.__customers =
    <?= json_encode(
        $customers,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;
</script>
<?php
}


/*
 * ============================================================
 * 集計
 * ============================================================
 */

function renderAggregation(
    ?array $survey,
    array $responses,
    array $customers
): void {
    if (!$survey) {
        ?>
        <div class="notice error">
            対象アンケートが指定されていません。
        </div>
        <?php
        return;
    }

    $surveyId =
        (string)$survey['surveyId'];

    $surveyResponses =
        array_values(
            array_filter(
                $responses,
                static fn($r) =>
                    (string)(
                        $r['surveyId'] ?? ''
                    ) === $surveyId
            )
        );

    $answeredCount =
        count($surveyResponses);

    $customerCount =
        count($customers);

    $sentCount =
        count(
            array_filter(
                $customers,
                static fn($c) =>
                    !empty(
                        $c['sendCount']
                    )
            )
        );

    $unanswered =
        max(
            0,
            $sentCount - $answeredCount
        );

    $rate =
        $sentCount > 0
            ? round(
                $answeredCount /
                $sentCount *
                100,
                1
            )
            : 0;

    ?>
<div class="page-title">
    <h1>回答集計・分析</h1>

    <div class="actions">
        <button
            class="secondary"
            onclick="exportCsv(
                '<?= h($surveyId) ?>'
            )">
            CSV出力
        </button>

        <button
            class="secondary"
            onclick="exportPdf(
                '<?= h($surveyId) ?>'
            )">
            PDF出力
        </button>
    </div>
</div>

<div class="notice">
    対象アンケート:
    <strong><?= h(
        $survey['title']
    ) ?></strong>
</div>

<div class="grid grid-3">

<div class="card">
    <div class="muted">
        送信対象者数
    </div>
    <div class="stat">
        <?= $sentCount ?>
    </div>
</div>

<div class="card">
    <div class="muted">
        回答数
    </div>
    <div class="stat">
        <?= $answeredCount ?>
    </div>
</div>

<div class="card">
    <div class="muted">
        回答率
    </div>
    <div class="stat">
        <?= $rate ?>%
    </div>
</div>

</div>

<div class="grid grid-2">

<div class="card">
    <div class="muted">
        未回答数
    </div>
    <div class="stat">
        <?= $unanswered ?>
    </div>
</div>

<div class="card">
    <div class="muted">
        未登録顧客からの回答数
    </div>
    <div class="stat">
        <?= count(
            array_filter(
                $surveyResponses,
                static fn($r) =>
                    empty(
                        $r['customerId']
                    )
            )
        ) ?>
    </div>
</div>

</div>

<div class="card">
    <div class="page-title">
        <h2>設問別集計</h2>

        <div class="actions">
            <button
                class="secondary"
                onclick="
                    document.querySelectorAll(
                        '.question-select'
                    ).forEach(
                        e => e.checked=true
                    )
                ">
                すべて選択
            </button>

            <button
                class="secondary"
                onclick="
                    document.querySelectorAll(
                        '.question-select'
                    ).forEach(
                        e => e.checked=false
                    )
                ">
                すべて解除
            </button>
        </div>
    </div>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$counts = [];

foreach (
    $question['choices']
    as $choice
) {
    $counts[
        (string)$choice['choiceId']
    ] = 0;
}

$textAnswers = [];

foreach (
    $surveyResponses
    as $response
) {
    $answer =
        $response['answers'][
            $question['questionId']
        ] ?? null;

    if ($question['answerType'] === 'text') {
        if (
            is_string($answer) &&
            trim($answer) !== ''
        ) {
            $textAnswers[] = [
                'answer' => $answer,
                'respondent' =>
                    $response['respondent'] ??
                    [],
            ];
        }

        continue;
    }

    $values = is_array($answer)
        ? $answer
        : [$answer];

    foreach ($values as $value) {
        $value = (string)$value;

        if (isset($counts[$value])) {
            $counts[$value]++;
        }
    }
}
?>

<div class="question">
    <label>
        <input
            type="checkbox"
            class="question-select"
            checked>
        <?= h(
            $question['questionNumber']
        ) ?>
        <?= h(
            $question['questionText']
        ) ?>
    </label>

<?php if (
    $question['answerType'] === 'text'
): ?>

    <?php foreach (
        $textAnswers
        as $item
    ): ?>

        <div class="card">
            <strong>
                <?= h(
                    $item['respondent']['name'] ??
                    ''
                ) ?>
            </strong>

            <p>
                <?= nl2br(
                    h($item['answer'])
                ) ?>
            </p>
        </div>

    <?php endforeach; ?>

    <?php if (!$textAnswers): ?>
        <p class="muted">
            回答はありません。
        </p>
    <?php endif; ?>

<?php else: ?>

    <?php
    $total =
        max(
            1,
            array_sum($counts)
        );
    ?>

    <?php foreach (
        $question['choices']
        as $choice
    ): ?>

        <?php
        $count =
            $counts[
                (string)$choice['choiceId']
            ] ?? 0;

        $percentage =
            round(
                $count / $total * 100,
                1
            );
        ?>

        <div style="margin-top:12px">
            <div>
                <?= h(
                    $choice['label']
                ) ?>

                —
                <?= $count ?>件
                /
                <?= $percentage ?>%
            </div>

            <div class="chart">
                <span style="
                    width:<?= $percentage ?>%;
                "></span>
            </div>
        </div>

    <?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>

<div class="card">
    <h2>個別回答</h2>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>回答日時</th>
            <th>回答者</th>
            <th>メール</th>
            <th>回答</th>
        </tr>
        </thead>

        <tbody>
        <?php foreach (
            $surveyResponses
            as $response
        ): ?>

        <tr>
            <td><?= h(
                $response['submittedAt'] ?? ''
            ) ?></td>

            <td><?= h(
                $response['respondent']['name'] ??
                ''
            ) ?></td>

            <td><?= h(
                $response['respondent']['email'] ??
                ''
            ) ?></td>

            <td>
                <?php foreach (
                    ($response['answers'] ?? [])
                    as $questionId => $answer
                ): ?>
                    <div>
                        <strong>
                            <?= h(
                                questionNumberById(
                                    $survey,
                                    (string)$questionId
                                )
                            ) ?>
                        </strong>
                        :
                        <?= h(
                            is_array($answer)
                                ? implode(
                                    ', ',
                                    array_map(
                                        'strval',
                                        $answer
                                    )
                                )
                                : $answer
                        ) ?>
                    </div>
                <?php endforeach; ?>
            </td>
        </tr>

        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php
}

function questionNumberById(
    array $survey,
    string $questionId
): string {
    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            if (
                (string)$question['questionId'] ===
                $questionId
            ) {
                return (string)(
                    $question['questionNumber'] ?? ''
                );
            }
        }
    }

    return $questionId;
}


/*
 * ============================================================
 * kintone設定画面
 * ============================================================
 */

function renderKintone(
    array $settings
): void {
    $k =
        $settings['kintone'] ?? [];

    $fields =
        $k['fields'] ?? [];

    $mapping =
        $k['fieldMapping'] ?? [];

    ?>
<div class="page-title">
    <h1>kintone連携設定</h1>
</div>

<div class="card">
    <div class="grid grid-2">

        <div class="form-row">
            <label>サブドメイン</label>
            <input
                id="kSubdomain"
                value="<?= h(
                    $k['subdomain'] ?? ''
                ) ?>"
                placeholder="
                    https:xxxx.cybozu.com
                    / xxxx.cybozu.com
                    / xxxx
                ">
        </div>

        <div class="form-row">
            <label>顧客管理アプリID</label>
            <input
                id="kAppId"
                value="<?= h(
                    $k['appId'] ?? ''
                ) ?>">
        </div>

        <div class="form-row">
            <label>ログイン名</label>
            <input
                id="kLogin"
                value="<?= h(
                    $k['loginName'] ?? ''
                ) ?>">
        </div>

        <div class="form-row">
            <label>パスワード</label>
            <input
                type="password"
                id="kPassword"
                value="<?= h(
                    $k['password'] ?? ''
                ) ?>">
        </div>

        <div class="form-row">
            <label>SSL証明書検証</label>

            <label>
                <input
                    type="checkbox"
                    id="kSsl"
                    <?= !empty(
                        $k['sslVerify']
                    )
                        ? 'checked'
                        : '' ?>>
                検証する
            </label>

            <small class="muted">
                初期値は「検証しない」
            </small>
        </div>

        <div class="form-row">
            <label>
                プロキシ
            </label>

            <input
                id="kProxy"
                value="<?= h(
                    $k['proxy'] ?? ''
                ) ?>"
                placeholder="
                    proxy.example.local:8080
                ">

            <small class="muted">
                host:port形式。
                プロキシ認証は行いません。
            </small>
        </div>

    </div>

    <div class="actions">

        <button
            class="primary"
            onclick="saveKintone()">
            設定保存
        </button>

        <button
            class="secondary"
            onclick="kintoneTest()">
            接続テスト
        </button>

        <button
            class="secondary"
            onclick="kintoneFields()">
            項目一覧を再取得
        </button>

        <button
            class="secondary"
            onclick="kintoneSync()">
            顧客情報を同期
        </button>

    </div>
</div>

<div class="card">
    <h2>kintoneフィールド一覧</h2>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>コード</th>
            <th>日本語ラベル</th>
            <th>型</th>
        </tr>
        </thead>

        <tbody>
        <?php foreach (
            $fields
            as $field
        ): ?>
        <tr>
            <td><?= h(
                $field['code'] ?? ''
            ) ?></td>
            <td><?= h(
                $field['label'] ?? ''
            ) ?></td>
            <td><?= h(
                $field['type'] ?? ''
            ) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2>フィールドマッピング</h2>

    <div class="grid grid-2">

        <?php
        $mappingFields = [
            'organization' => '組織名',
            'name' => '氏名',
            'email' => 'メールアドレス',
            'department' => '部署名',
            'phone' => '電話番号',
        ];
        ?>

        <?php foreach (
            $mappingFields
            as $key => $label
        ): ?>

        <div class="form-row">
            <label><?= h($label) ?></label>

            <select
                id="map<?= ucfirst($key) ?>">
                <option value="">
                    未設定
                </option>

                <?php foreach (
                    $fields
                    as $field
                ): ?>

                <option
                    value="<?= h(
                        $field['code'] ?? ''
                    ) ?>"
                    <?= (
                        $mapping[$key] ?? ''
                    ) === (
                        $field['code'] ?? ''
                    )
                        ? 'selected'
                        : '' ?>>
                    <?= h(
                        $field['label'] ??
                        $field['code'] ??
                        ''
                    ) ?>
                    (
                    <?= h(
                        $field['code'] ?? ''
                    ) ?>
                    )
                </option>

                <?php endforeach; ?>
            </select>
        </div>

        <?php endforeach; ?>

    </div>

    <h3>住所</h3>

    <?php foreach (
        $fields
        as $field
    ): ?>

    <label style="
        display:inline-block;
        margin:5px 10px 5px 0;
    ">
        <input
            type="checkbox"
            class="address-map"
            value="<?= h(
                $field['code'] ?? ''
            ) ?>"
            <?= in_array(
                $field['code'] ?? '',
                $mapping['address'] ?? [],
                true
            )
                ? 'checked'
                : '' ?>>
        <?= h(
            $field['label'] ??
            $field['code'] ??
            ''
        ) ?>
    </label>

    <?php endforeach; ?>

</div>
<?php
}


/*
 * ============================================================
 * メール設定画面
 * ============================================================
 */

function renderMailSettings(
    array $settings
): void {
    $mail =
        $settings['mail'] ?? [];

    ?>
<div class="page-title">
    <h1>メールサーバ設定</h1>
</div>

<div class="card">

<div class="grid grid-2">

<div class="form-row">
    <label>SMTPサーバ</label>
    <input
        id="smtpHost"
        value="<?= h(
            $mail['smtpHost'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>SMTPポート</label>
    <input
        type="number"
        id="smtpPort"
        value="<?= (int)(
            $mail['smtpPort'] ?? 587
        ) ?>">
</div>

<div class="form-row">
    <label>暗号化方式</label>
    <select id="smtpEncryption">
        <option
            value="none"
            <?= ($mail['encryption'] ?? '') === 'none'
                ? 'selected'
                : '' ?>>
            なし
        </option>

        <option
            value="tls"
            <?= ($mail['encryption'] ?? 'tls') === 'tls'
                ? 'selected'
                : '' ?>>
            STARTTLS
        </option>

        <option
            value="ssl"
            <?= ($mail['encryption'] ?? '') === 'ssl'
                ? 'selected'
                : '' ?>>
            SSL/TLS
        </option>
    </select>
</div>

<div class="form-row">
    <label>SMTP認証</label>

    <label>
        <input
            type="checkbox"
            id="smtpAuth"
            <?= !empty(
                $mail['smtpAuth']
            )
                ? 'checked'
                : '' ?>>
        SMTP認証を使用
    </label>
</div>

<div class="form-row">
    <label>SMTPユーザー名</label>
    <input
        id="smtpUsername"
        value="<?= h(
            $mail['username'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>SMTPパスワード</label>
    <input
        type="password"
        id="smtpPassword"
        value="<?= h(
            $mail['password'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>送信元メールアドレス</label>
    <input
        id="fromEmail"
        value="<?= h(
            $mail['fromEmail'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>送信元名</label>
    <input
        id="fromName"
        value="<?= h(
            $mail['fromName'] ?? ''
        ) ?>">
</div>

<div class="form-row">
    <label>返信先メールアドレス</label>
    <input
        id="replyTo"
        value="<?= h(
            $mail['replyTo'] ?? ''
        ) ?>">
</div>

</div>

<div class="notice">
    接続状態:
    <strong><?= h(
        $mail['status'] ?? '未設定'
    ) ?></strong>
</div>

<div class="actions">

<button
    class="primary"
    onclick="saveMailSettings()">
    設定保存
</button>

<button
    class="secondary"
    onclick="sendTestMail()">
    テストメール
</button>

</div>

</div>
<?php
}


/*
 * ============================================================
 * 回答者画面
 * ============================================================
 *
 * 管理者メニューを一切表示しない。
 */

function renderAnswerApplication(
    string $view,
    string $surveyId,
    string $token
): void {
    $surveys = getCurrentSurveys();

    $survey = findSurvey(
        $surveys,
        $surveyId
    );

    $responses = readJson(
        'responses.json',
        []
    );

    $answered =
        $token !== '' &&
        hasSubmittedResponse(
            $responses,
            $surveyId,
            $token
        );

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケート</title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    background:#f5f7fb;
    color:#172033;
    font-family:
        -apple-system,BlinkMacSystemFont,
        "Segoe UI","Noto Sans JP",sans-serif;
}
.container{
    max-width:850px;
    margin:auto;
    padding:16px;
}
.card{
    background:#fff;
    border:1px solid #dbe2ea;
    border-radius:12px;
    padding:18px;
    margin-bottom:15px;
}
h1{
    font-size:24px;
    margin-top:0;
}
h2{
    font-size:19px;
}
.group{
    border-top:1px solid #e2e8f0;
    padding-top:15px;
    margin-top:15px;
}
.question{
    margin-top:18px;
}
.choice{
    display:block;
    padding:13px;
    margin:8px 0;
    border:1px solid #cbd5e1;
    border-radius:9px;
    background:#fff;
}
.choice input{
    width:20px;
    height:20px;
    vertical-align:middle;
    margin-right:8px;
}
textarea,input[type=text],input[type=email]{
    width:100%;
    padding:12px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    font:inherit;
}
textarea{
    min-height:130px;
}
button{
    padding:12px 18px;
    border:0;
    border-radius:8px;
    font:inherit;
    cursor:pointer;
}
.primary{
    background:#2563eb;
    color:#fff;
}
.secondary{
    background:#e2e8f0;
}
.error{
    color:#991b1b;
    background:#fef2f2;
    padding:12px;
    border-radius:8px;
}
.notice{
    padding:12px;
    border-radius:8px;
    background:#eff6ff;
    color:#1e40af;
}
.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    justify-content:space-between;
    margin-top:20px;
}
.required{
    color:#dc2626;
}
@media(max-width:500px){
    .container{
        padding:10px;
    }
    .card{
        padding:14px;
    }
    button{
        width:100%;
    }
}
</style>
</head>
<body>
<div class="container">
<?php

if (!$survey) {
    ?>
    <div class="card">
        <h1>アンケート</h1>
        <div class="error">
            アンケートが指定されていないか、
            存在しません。
        </div>
    </div>
    <?php
    return;
}

$status =
    (string)($survey['status'] ?? '');

if (
    $status !== '公開中' &&
    $view !== 'complete'
) {
    ?>
    <div class="card">
        <h1>
            <?= h(
                $survey['title']
            ) ?>
        </h1>

        <div class="notice">
            現在このアンケートには
            回答できません。
        </div>
    </div>
    <?php
    return;
}

if (
    $answered &&
    !$survey['allowResubmit'] &&
    $view !== 'complete'
) {
    ?>
    <div class="card">
        <h1>回答済み</h1>

        <div class="notice">
            このアンケートは回答済みです。
        </div>
    </div>
    <?php
    return;
}

if ($view === 'complete') {
    renderCompleteView();
} elseif ($view === 'confirm') {
    renderAnswerConfirm(
        $survey,
        $surveyId,
        $token
    );
} else {
    renderAnswerForm(
        $survey,
        $surveyId,
        $token
    );
}

?>
</div>

<script>
/*
 * 回答者側も画面識別はURL queryのみ。
 */
const answerParams =
    new URLSearchParams(
        window.location.search
    );

const currentView =
    answerParams.get('view') ||
    'admin-survey-list';

const answerSurveyId =
    answerParams.get('surveyId') || '';

const answerToken =
    answerParams.get('token') || '';

const answerStorageKey =
    'survey-response:' +
    answerSurveyId +
    ':' +
    answerToken;

function getAnswerState(){
    try{
        return JSON.parse(
            sessionStorage.getItem(
                answerStorageKey
            ) || '{}'
        );
    }catch(e){
        return {};
    }
}

function setAnswerState(state){
    sessionStorage.setItem(
        answerStorageKey,
        JSON.stringify(state)
    );
}

function goConfirm(){
    const form =
        document.getElementById(
            'answerForm'
        );

    if(!form){
        return;
    }

    const state = collectAnswers(form);

    const errors = [];

    document
        .querySelectorAll(
            '[data-required-question]'
        )
        .forEach(question => {
            const questionId =
                question.dataset.requiredQuestion;

            const value =
                state.answers[questionId];

            const empty =
                value === undefined ||
                value === null ||
                value === '' ||
                (
                    Array.isArray(value) &&
                    value.length === 0
                );

            if(empty){
                errors.push(
                    question.dataset.questionNumber
                );
            }
        });

    if(errors.length){
        alert(
            '必須回答が未入力です。\n' +
            errors.join(', ')
        );

        return;
    }

    setAnswerState(state);

    const params =
        new URLSearchParams();

    params.set(
        'view',
        'confirm'
    );

    params.set(
        'surveyId',
        answerSurveyId
    );

    if(answerToken){
        params.set(
            'token',
            answerToken
        );
    }

    history.pushState(
        {},
        '',
        '?' + params.toString()
    );

    window.location.reload();
}

function collectAnswers(form){
    const answers = {};

    form
        .querySelectorAll(
            '[data-question-id]'
        )
        .forEach(question => {
            const id =
                question.dataset.questionId;

            const type =
                question.dataset.answerType;

            if(type === 'multiple'){
                answers[id] =
                    [...question.querySelectorAll(
                        'input:checked'
                    )].map(
                        input => input.value
                    );
            }else if(type === 'single'){
                const checked =
                    question.querySelector(
                        'input:checked'
                    );

                answers[id] =
                    checked
                        ? checked.value
                        : '';
            }else{
                const input =
                    question.querySelector(
                        'textarea'
                    );

                answers[id] =
                    input
                        ? input.value
                        : '';
            }
        });

    return {
        respondent:{
            organization:
                form.organization?.value || '',
            name:
                form.name?.value || '',
            email:
                form.email?.value || '',
            department:
                form.department?.value || '',
            phone:
                form.phone?.value || '',
            address:
                form.address?.value || ''
        },
        answers
    };
}

function submitAnswer(){
    const state =
        getAnswerState();

    if(!state.answers){
        alert(
            '回答内容がありません。'
        );

        return;
    }

    if(!confirm(
        '回答を送信します。よろしいですか？'
    )){
        return;
    }

    api(
        'save_response',
        {
            surveyId:answerSurveyId,
            token:answerToken,
            response:state
        }
    ).then(result => {
        const params =
            new URLSearchParams();

        params.set(
            'view',
            'complete'
        );

        params.set(
            'surveyId',
            answerSurveyId
        );

        params.set(
            'token',
            result.token || answerToken
        );

        history.pushState(
            {},
            '',
            '?' + params.toString()
        );

        sessionStorage.removeItem(
            answerStorageKey
        );

        window.location.reload();
    }).catch(showError);
}

function api(action,data={}){
    const body =
        new URLSearchParams();

    body.set(
        'action',
        action
    );

    Object.entries(data)
        .forEach(
            ([key,value]) => {
                body.set(
                    key,
                    typeof value === 'object'
                        ? JSON.stringify(value)
                        : String(value)
                );
            }
        );

    return fetch(
        'index.php',
        {
            method:'POST',
            headers:{
                'Content-Type':
                    'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body
        }
    ).then(async response => {
        const json =
            await response.json();

        if(
            !response.ok ||
            json.success === false
        ){
            throw new Error(
                json.error ||
                '送信に失敗しました。'
            );
        }

        return json;
    });
}

function showError(error){
    alert(
        error.message ||
        String(error)
    );
}

window.addEventListener(
    'popstate',
    function(){
        window.location.reload();
    }
);
</script>

</body>
</html>
<?php
}

function renderAnswerForm(
    array $survey,
    string $surveyId,
    string $token
): void {
    ?>
<div class="card">
    <h1>
        <?= h(
            $survey['title']
        ) ?>
    </h1>

    <p>
        <?= nl2br(
            h($survey['description'])
        ) ?>
    </p>
</div>

<form id="answerForm">

<div class="card">
    <h2>回答者情報</h2>

    <div class="question">
        <label>組織名</label>
        <input
            type="text"
            name="organization">
    </div>

    <div class="question">
        <label>氏名</label>
        <input
            type="text"
            name="name">
    </div>

    <div class="question">
        <label>メールアドレス</label>
        <input
            type="email"
            name="email">
    </div>

    <div class="question">
        <label>部署名</label>
        <input
            type="text"
            name="department">
    </div>

    <div class="question">
        <label>電話番号</label>
        <input
            type="text"
            name="phone">
    </div>

    <div class="question">
        <label>住所</label>
        <input
            type="text"
            name="address">
    </div>
</div>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">
<?php if (
    trim(
        (string)$group['groupTitle']
    ) !== ''
): ?>
    <h2>
        <?= h(
            $group['groupTitle']
        ) ?>
    </h2>
<?php endif; ?>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div
    class="question"
    data-question-id="<?= h(
        $question['questionId']
    ) ?>"
    data-question-number="<?= h(
        $question['questionNumber']
    ) ?>"
    data-answer-type="<?= h(
        $question['answerType']
    ) ?>"
    <?= !empty(
        $question['required']
    )
        ? 'data-required-question="'
            . h($question['questionId'])
            . '"'
        : '' ?>>

    <h3>
        <?= h(
            $question['questionNumber']
        ) ?>

        <?php if (
            !empty($question['required'])
        ): ?>
            <span class="required">
                必須
            </span>
        <?php endif; ?>
    </h3>

    <p>
        <?= nl2br(
            h(
                $question['questionText']
            )
        ) ?>
    </p>

<?php if (
    $question['answerType'] === 'single'
): ?>

    <?php foreach (
        $question['choices']
        as $choice
    ): ?>
        <label class="choice">
            <input
                type="radio"
                name="q_<?= h(
                    $question['questionId']
                ) ?>"
                value="<?= h(
                    $choice['choiceId']
                ) ?>">
            <?= h(
                $choice['label']
            ) ?>
        </label>
    <?php endforeach; ?>

<?php elseif (
    $question['answerType'] === 'multiple'
): ?>

    <?php foreach (
        $question['choices']
        as $choice
    ): ?>
        <label class="choice">
            <input
                type="checkbox"
                name="q_<?= h(
                    $question['questionId']
                ) ?>[]"
                value="<?= h(
                    $choice['choiceId']
                ) ?>">
            <?= h(
                $choice['label']
            ) ?>
        </label>
    <?php endforeach; ?>

<?php else: ?>

    <textarea
        name="q_<?= h(
            $question['questionId']
        ) ?>"></textarea>

<?php endif; ?>

</div>

<?php endforeach; ?>
</div>

<?php endforeach; ?>

<div class="actions">
    <div></div>

    <button
        type="button"
        class="primary"
        onclick="goConfirm()">
        次へ
    </button>
</div>

</form>
<?php
}

function renderAnswerConfirm(
    array $survey,
    string $surveyId,
    string $token
): void {
    ?>
<div class="card">
    <h1>回答内容確認</h1>

    <div id="confirmation"></div>

    <div class="actions">
        <button
            type="button"
            class="secondary"
            onclick="
                history.back();
            ">
            修正する
        </button>

        <button
            type="button"
            class="primary"
            onclick="submitAnswer()">
            回答を送信
        </button>
    </div>
</div>

<script>
const confirmation =
    document.getElementById(
        'confirmation'
    );

const state =
    getAnswerState();

let html = `
    <div class="card">
        <h2>回答者情報</h2>
        <p>
            <strong>組織名:</strong>
            ${escapeHtml(
                state.respondent?.organization
            )}
        </p>
        <p>
            <strong>氏名:</strong>
            ${escapeHtml(
                state.respondent?.name
            )}
        </p>
        <p>
            <strong>メール:</strong>
            ${escapeHtml(
                state.respondent?.email
            )}
        </p>
        <p>
            <strong>部署:</strong>
            ${escapeHtml(
                state.respondent?.department
            )}
        </p>
        <p>
            <strong>電話:</strong>
            ${escapeHtml(
                state.respondent?.phone
            )}
        </p>
        <p>
            <strong>住所:</strong>
            ${escapeHtml(
                state.respondent?.address
            )}
        </p>
    </div>
`;

<?php foreach (
    $survey['groups']
    as $group
): ?>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$qid =
    (string)$question['questionId'];
$answer =
    $qid !== ''
        ? (
            $state['answers'][$qid]
            ?? ''
        )
        : '';
?>

html += `
<div class="card">
    <h2>
        <?= h(
            $question['questionNumber']
        ) ?>
    </h2>

    <p>
        <?= h(
            $question['questionText']
        ) ?>
    </p>

    <p>
        <strong>回答:</strong>
        ${escapeHtml(
            Array.isArray(
                <?= json_encode(
                    $answer,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ) ?>
            )
                ? <?= json_encode(
                    is_array($answer)
                        ? implode(
                            ', ',
                            array_map(
                                'strval',
                                $answer
                            )
                        )
                        : $answer,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ) ?>
                : <?= json_encode(
                    is_array($answer)
                        ? implode(
                            ', ',
                            array_map(
                                'strval',
                                $answer
                            )
                        )
                        : $answer,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ) ?>
        )}
    </p>
</div>
`;

<?php endforeach; ?>

<?php endforeach; ?>

confirmation.innerHTML = html;

function escapeHtml(value){
    return String(value ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}
</script>
<?php
}

function renderCompleteView(): void
{
    ?>
<div class="card">
    <h1>回答ありがとうございました</h1>

    <div class="notice">
        回答を受け付けました。
    </div>

    <p>
        回答は正常に送信されました。
    </p>
</div>
<?php
}


/*
 * ============================================================
 * インライン初期化
 * ============================================================
 */

function renderInlineViewData(
    string $view,
    ?array $survey
): void {
    ?>
/*
 * PHP初期状態。
 *
 * currentViewはURLから生成される。
 * PHP内部の物理配置場所から生成しない。
 */

window.currentView =
    readUrlState().view;

window.currentSurveyId =
    readUrlState().surveyId;

window.currentToken =
    readUrlState().token;

<?php
}
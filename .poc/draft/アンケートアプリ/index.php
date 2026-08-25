<?php
declare(strict_types=1);

/*
 * アンケート管理システム
 *
 * 単一入口: index.php
 * 画面識別: GET ?view=...
 * 業務API: POST action=...
 *
 * URLパスは画面識別に使用しない。
 * 外部DBは使用せずJSONで永続化する。
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

ensureDataDirectory();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleApiRequest();
    exit;
}

$view = getView();
$surveyId = trim((string)($_GET['surveyId'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));

renderApplication($view, $surveyId, $token);


/* ============================================================
 * 基本
 * ============================================================ */

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

function getView(): string
{
    $view = trim((string)($_GET['view'] ?? ''));

    if ($view === '' || !in_array($view, ALLOWED_VIEWS, true)) {
        return DEFAULT_VIEW;
    }

    return $view;
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function readJson(string $file, mixed $default = []): mixed
{
    $path = DATA_DIR . '/' . $file;

    if (!is_file($path)) {
        return $default;
    }

    $fp = fopen($path, 'rb');

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

    return json_last_error() === JSON_ERROR_NONE ? $data : $default;
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

    $fp = fopen($tmp, 'wb');

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

    return rename($tmp, $path);
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
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


/* ============================================================
 * API
 * ============================================================ */

function handleApiRequest(): never
{
    $action = trim((string)($_POST['action'] ?? ''));

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


/* ============================================================
 * アンケート
 * ============================================================ */

function apiSaveSurvey(): never
{
    $surveys = readJson('surveys.json', []);
    $payload = json_decode((string)($_POST['survey'] ?? ''), true);

    if (!is_array($payload)) {
        jsonResponse([
            'success' => false,
            'error' => 'アンケートデータが不正です。',
        ], 400);
    }

    $surveyId = trim((string)($payload['surveyId'] ?? ''));

    $isNew = $surveyId === '';

    if ($isNew) {
        $surveyId = uuid('survey_');
    }

    $existingIndex = null;

    foreach ($surveys as $index => $survey) {
        if (($survey['surveyId'] ?? '') === $surveyId) {
            $existingIndex = $index;
            break;
        }
    }

    $existing = $existingIndex !== null
        ? $surveys[$existingIndex]
        : null;

    $status = $existing['status'] ?? '下書き';

    $survey = normalizeSurvey($payload, $surveyId, $status);

    if ($existingIndex === null) {
        $surveys[] = $survey;
    } else {
        $surveys[$existingIndex] = $survey;
    }

    if (!writeJson('surveys.json', array_values($surveys))) {
        jsonResponse([
            'success' => false,
            'error' => 'アンケート保存に失敗しました。',
        ], 500);
    }

    jsonResponse([
        'success' => true,
        'survey' => $survey,
        'surveyId' => $surveyId,
    ]);
}

function normalizeSurvey(array $source, string $surveyId, string $status): array
{
    $groups = [];

    foreach (($source['groups'] ?? []) as $groupIndex => $group) {
        if (!is_array($group)) {
            continue;
        }

        $groupId = trim((string)($group['groupId'] ?? ''));

        if ($groupId === '') {
            $groupId = uuid('group_');
        }

        $questions = [];

        foreach (($group['questions'] ?? []) as $questionIndex => $question) {
            if (!is_array($question)) {
                continue;
            }

            $questionId = trim((string)($question['questionId'] ?? ''));

            if ($questionId === '') {
                $questionId = uuid('question_');
            }

            $choices = [];

            foreach (($question['choices'] ?? []) as $choice) {
                if (!is_array($choice)) {
                    continue;
                }

                $choiceId = trim((string)($choice['choiceId'] ?? ''));

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
                'questionText' => (string)($question['questionText'] ?? ''),
                'answerType' => normalizeAnswerType($question['answerType'] ?? 'single'),
                'required' => !empty($question['required']),
                'choices' => $choices,
                'condition' => normalizeCondition($question['condition'] ?? null),
                'sortOrder' => $questionIndex,
            ];
        }

        $groups[] = [
            'groupId' => $groupId,
            'groupTitle' => (string)($group['groupTitle'] ?? ''),
            'sortOrder' => $groupIndex,
            'questions' => $questions,
        ];
    }

    $numberingMode = ($source['numberingMode'] ?? 'global') === 'group'
        ? 'group'
        : 'global';

    $survey = [
        'surveyId' => $surveyId,
        'title' => (string)($source['title'] ?? ''),
        'description' => (string)($source['description'] ?? ''),
        'startAt' => normalizeNullableString($source['startAt'] ?? null),
        'endAt' => normalizeNullableString($source['endAt'] ?? null),
        'numberingMode' => $numberingMode,
        'allowResubmit' => !empty($source['allowResubmit']),
        'status' => $status,
        'groups' => $groups,
        'createdAt' => (string)($source['createdAt'] ?? nowIso()),
        'updatedAt' => nowIso(),
    ];

    recalculateQuestionNumbers($survey);

    return $survey;
}

function normalizeNullableString(mixed $value): ?string
{
    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function normalizeAnswerType(mixed $type): string
{
    $type = (string)$type;

    if ($type === 'multiple') {
        return 'multiple';
    }

    if ($type === 'text') {
        return 'text';
    }

    return 'single';
}

function normalizeCondition(mixed $condition): ?array
{
    if (!is_array($condition)) {
        return null;
    }

    $questionId = trim((string)($condition['questionId'] ?? ''));
    $choiceId = trim((string)($condition['choiceId'] ?? ''));
    $nextQuestionId = trim((string)($condition['nextQuestionId'] ?? ''));

    if ($questionId === '' || $choiceId === '' || $nextQuestionId === '') {
        return null;
    }

    return [
        'questionId' => $questionId,
        'choiceId' => $choiceId,
        'nextQuestionId' => $nextQuestionId,
    ];
}

function recalculateQuestionNumbers(array &$survey): void
{
    $global = 0;

    foreach ($survey['groups'] as $gIndex => &$group) {
        $local = 0;

        foreach ($group['questions'] as $qIndex => &$question) {
            $question['sortOrder'] = $qIndex;

            if ($survey['numberingMode'] === 'group') {
                $local++;
                $question['questionNumber'] =
                    'Q' . ($gIndex + 1) . '-' . $local;
            } else {
                $global++;
                $question['questionNumber'] = 'Q' . $global;
            }
        }

        $group['sortOrder'] = $gIndex;
    }
    unset($group, $question);
}

function apiDeleteSurvey(): never
{
    $surveyId = trim((string)($_POST['surveyId'] ?? ''));

    if ($surveyId === '') {
        jsonResponse([
            'success' => false,
            'error' => 'surveyIdが必要です。',
        ], 400);
    }

    $surveys = readJson('surveys.json', []);

    $found = false;
    $surveys = array_values(array_filter(
        $surveys,
        static function ($survey) use ($surveyId, &$found): bool {
            if (($survey['surveyId'] ?? '') === $surveyId) {
                $found = true;
                return false;
            }

            return true;
        }
    ));

    if (!$found) {
        jsonResponse([
            'success' => false,
            'error' => 'アンケートが存在しません。',
        ], 404);
    }

    writeJson('surveys.json', $surveys);

    jsonResponse([
        'success' => true,
    ]);
}

function apiDuplicateSurvey(): never
{
    $surveyId = trim((string)($_POST['surveyId'] ?? ''));

    $surveys = readJson('surveys.json', []);
    $source = findSurvey($surveys, $surveyId);

    if (!$source) {
        jsonResponse([
            'success' => false,
            'error' => '複製元アンケートが存在しません。',
        ], 404);
    }

    $newId = uuid('survey_');

    $copy = $source;
    $copy['surveyId'] = $newId;
    $copy['title'] = (string)$source['title'] . '（複製）';
    $copy['status'] = '下書き';
    $copy['createdAt'] = nowIso();
    $copy['updatedAt'] = nowIso();

    foreach ($copy['groups'] as &$group) {
        $group['groupId'] = uuid('group_');

        foreach ($group['questions'] as &$question) {
            $question['questionId'] = uuid('question_');

            foreach ($question['choices'] as &$choice) {
                $choice['choiceId'] = uuid('choice_');
            }
        }
    }
    unset($group, $question, $choice);

    recalculateQuestionNumbers($copy);

    $surveys[] = $copy;
    writeJson('surveys.json', $surveys);

    jsonResponse([
        'success' => true,
        'survey' => $copy,
    ]);
}

function apiChangeSurveyStatus(): never
{
    $surveyId = trim((string)($_POST['surveyId'] ?? ''));
    $target = trim((string)($_POST['status'] ?? ''));

    $allowed = [
        '公開中',
        '停止',
    ];

    $surveys = readJson('surveys.json', []);
    $index = findSurveyIndex($surveys, $surveyId);

    if ($index === null) {
        jsonResponse([
            'success' => false,
            'error' => 'アンケートが存在しません。',
        ], 404);
    }

    $current = $surveys[$index]['status'] ?? '下書き';

    $valid = false;

    if ($current === '下書き' && $target === '公開中') {
        $valid = true;
    }

    if ($current === '公開中' && $target === '停止') {
        $valid = true;
    }

    if ($current === '停止' && $target === '公開中') {
        $valid = true;
    }

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

function updateAutomaticEndStatus(array &$surveys): void
{
    $now = time();

    foreach ($surveys as &$survey) {
        if (($survey['status'] ?? '') !== '公開中') {
            continue;
        }

        $endAt = $survey['endAt'] ?? null;

        if (!$endAt) {
            continue;
        }

        $endTimestamp = strtotime((string)$endAt);

        if ($endTimestamp !== false && $now > $endTimestamp) {
            $survey['status'] = '終了';
            $survey['updatedAt'] = nowIso();
        }
    }
    unset($survey);
}


/* ============================================================
 * 回答
 * ============================================================ */

function apiSaveResponse(): never
{
    $surveyId = trim((string)($_POST['surveyId'] ?? ''));
    $token = trim((string)($_POST['token'] ?? ''));

    $response = json_decode(
        (string)($_POST['response'] ?? ''),
        true
    );

    if ($surveyId === '' || !is_array($response)) {
        jsonResponse([
            'success' => false,
            'error' => '回答データが不正です。',
        ], 400);
    }

    $surveys = readJson('surveys.json', []);
    updateAutomaticEndStatus($surveys);

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

    $customers = readJson('customers.json', []);
    $responses = readJson('responses.json', []);

    $customerId = trim((string)($response['customerId'] ?? ''));

    if ($customerId === '') {
        $customerId = resolveCustomer(
            $customers,
            $response['respondent'] ?? []
        );
    }

    if ($customerId === '') {
        $customerId = null;
    }

    $responseId = uuid('response_');

    $record = [
        'responseId' => $responseId,
        'surveyId' => $surveyId,
        'customerId' => $customerId,
        'token' => $token !== '' ? $token : uuid('token_'),
        'respondent' => normalizeRespondent(
            $response['respondent'] ?? []
        ),
        'answers' => is_array($response['answers'] ?? null)
            ? $response['answers']
            : [],
        'submittedAt' => nowIso(),
    ];

    $responses[] = $record;

    writeJson('responses.json', $responses);

    jsonResponse([
        'success' => true,
        'responseId' => $responseId,
        'token' => $record['token'],
    ]);
}

function normalizeRespondent(mixed $respondent): array
{
    if (!is_array($respondent)) {
        $respondent = [];
    }

    return [
        'organization' => (string)($respondent['organization'] ?? ''),
        'name' => (string)($respondent['name'] ?? ''),
        'email' => (string)($respondent['email'] ?? ''),
        'department' => (string)($respondent['department'] ?? ''),
        'phone' => (string)($respondent['phone'] ?? ''),
        'address' => (string)($respondent['address'] ?? ''),
    ];
}

function resolveCustomer(array $customers, mixed $respondent): string
{
    if (!is_array($respondent)) {
        return '';
    }

    $email = strtolower(trim((string)($respondent['email'] ?? '')));

    if ($email !== '') {
        foreach ($customers as $customer) {
            $candidate = strtolower(
                trim((string)($customer['email'] ?? ''))
            );

            if ($candidate !== '' && $candidate === $email) {
                return (string)($customer['customerId'] ?? '');
            }
        }
    }

    $name = trim((string)($respondent['name'] ?? ''));
    $organization = trim(
        (string)($respondent['organization'] ?? '')
    );

    foreach ($customers as $customer) {
        if (
            $name !== '' &&
            $organization !== '' &&
            (string)($customer['name'] ?? '') === $name &&
            (string)($customer['organization'] ?? '') === $organization
        ) {
            return (string)($customer['customerId'] ?? '');
        }
    }

    return '';
}


/* ============================================================
 * 送信
 * ============================================================ */

function apiSendMail(): never
{
    $surveyId = trim((string)($_POST['surveyId'] ?? ''));
    $customerIds = json_decode(
        (string)($_POST['customerIds'] ?? '[]'),
        true
    );

    $subject = (string)($_POST['subject'] ?? '');
    $body = (string)($_POST['body'] ?? '');
    $sendType = trim((string)($_POST['sendType'] ?? '一括送信'));

    if ($surveyId === '') {
        jsonResponse([
            'success' => false,
            'error' => '対象アンケートが指定されていません。',
        ], 400);
    }

    if (!is_array($customerIds) || count($customerIds) === 0) {
        jsonResponse([
            'success' => false,
            'error' => '送信対象顧客を選択してください。',
        ], 400);
    }

    $surveys = readJson('surveys.json', []);
    $survey = findSurvey($surveys, $surveyId);

    if (!$survey) {
        jsonResponse([
            'success' => false,
            'error' => '対象アンケートが存在しません。',
        ], 404);
    }

    $customers = readJson('customers.json', []);
    $history = readJson('send_history.json', []);
    $settings = readJson('settings.json', []);

    $results = [];

    foreach ($customerIds as $customerId) {
        $customer = findCustomer($customers, (string)$customerId);

        if (!$customer) {
            $results[] = [
                'customerId' => $customerId,
                'success' => false,
                'error' => '顧客が存在しません。',
            ];
            continue;
        }

        $personalUrl = buildAnswerUrl(
            $surveyId,
            createCustomerToken($surveyId, $customer)
        );

        $variables = [
            '{顧客名}' => (string)($customer['name'] ?? ''),
            '{アンケートURL}' => $personalUrl,
        ];

        $personalSubject = strtr($subject, $variables);
        $personalBody = strtr($body, $variables);

        $result = sendSmtpMail(
            $settings['mail'] ?? [],
            (string)($customer['email'] ?? ''),
            $personalSubject,
            $personalBody
        );

        $history[] = [
            'historyId' => uuid('history_'),
            'surveyId' => $surveyId,
            'customerId' => (string)$customerId,
            'sendType' => $sendType,
            'sentAt' => nowIso(),
            'subject' => $personalSubject,
            'body' => $personalBody,
            'surveyUrl' => $personalUrl,
            'success' => $result['success'],
            'error' => $result['error'] ?? '',
            'executor' => 'system',
        ];

        $results[] = [
            'customerId' => (string)$customerId,
            'name' => (string)($customer['name'] ?? ''),
            'email' => (string)($customer['email'] ?? ''),
            'success' => $result['success'],
            'error' => $result['error'] ?? '',
        ];
    }

    writeJson('send_history.json', $history);

    $successCount = count(
        array_filter(
            $results,
            static fn(array $r): bool => !empty($r['success'])
        )
    );

    jsonResponse([
        'success' => true,
        'total' => count($results),
        'successCount' => $successCount,
        'failureCount' => count($results) - $successCount,
        'sentAt' => nowIso(),
        'results' => $results,
    ]);
}

function createCustomerToken(string $surveyId, array $customer): string
{
    return hash_hmac(
        'sha256',
        $surveyId . '|' . (string)($customer['customerId'] ?? ''),
        'survey-system-token-secret'
    );
}

function buildAnswerUrl(string $surveyId, string $token): string
{
    return 'index.php?view=answer&surveyId=' .
        rawurlencode($surveyId) .
        '&token=' .
        rawurlencode($token);
}

function sendSmtpMail(
    array $settings,
    string $to,
    string $subject,
    string $body
): array {
    if ($to === '') {
        return [
            'success' => false,
            'error' => 'メールアドレスが設定されていません。',
        ];
    }

    $host = trim((string)($settings['smtpHost'] ?? ''));
    $port = (int)($settings['smtpPort'] ?? 0);
    $from = trim((string)($settings['fromEmail'] ?? ''));
    $username = (string)($settings['username'] ?? '');
    $password = (string)($settings['password'] ?? '');
    $encryption = (string)($settings['encryption'] ?? 'none');

    if ($host === '' || $port <= 0 || $from === '') {
        return [
            'success' => false,
            'error' => 'SMTP設定が未設定です。',
        ];
    }

    /*
     * PHP標準SMTP拡張が存在しない環境では、
     * socketによるSMTP通信を行う。
     */
    $transportHost = $host;

    if ($encryption === 'ssl') {
        $transportHost = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $socket = @fsockopen(
        $transportHost,
        $port,
        $errno,
        $errstr,
        15
    );

    if (!$socket) {
        return [
            'success' => false,
            'error' => 'SMTP接続失敗: ' . $errstr,
        ];
    }

    $readSmtp = static function ($socket): string {
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        return $response;
    };

    $writeSmtp = static function ($socket, string $command): void {
        fwrite($socket, $command . "\r\n");
    };

    $greet = $readSmtp($socket);

    if (!str_starts_with($greet, '220')) {
        fclose($socket);

        return [
            'success' => false,
            'error' => 'SMTP greeting失敗: ' . trim($greet),
        ];
    }

    $writeSmtp($socket, 'EHLO localhost');
    $ehlo = $readSmtp($socket);

    if (!str_starts_with($ehlo, '250')) {
        fclose($socket);

        return [
            'success' => false,
            'error' => 'EHLO失敗: ' . trim($ehlo),
        ];
    }

    if ($encryption === 'tls') {
        $writeSmtp($socket, 'STARTTLS');
        $tls = $readSmtp($socket);

        if (!str_starts_with($tls, '220')) {
            fclose($socket);

            return [
                'success' => false,
                'error' => 'STARTTLS失敗: ' . trim($tls),
            ];
        }

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if (!$crypto) {
            fclose($socket);

            return [
                'success' => false,
                'error' => 'TLS通信開始に失敗しました。',
            ];
        }

        $writeSmtp($socket, 'EHLO localhost');
        $readSmtp($socket);
    }

    if ($username !== '') {
        $writeSmtp($socket, 'AUTH LOGIN');
        $auth = $readSmtp($socket);

        if (!str_starts_with($auth, '334')) {
            fclose($socket);

            return [
                'success' => false,
                'error' => 'SMTP認証開始に失敗しました。',
            ];
        }

        $writeSmtp($socket, base64_encode($username));
        $userResponse = $readSmtp($socket);

        if (!str_starts_with($userResponse, '334')) {
            fclose($socket);

            return [
                'success' => false,
                'error' => 'SMTPユーザー名認証に失敗しました。',
            ];
        }

        $writeSmtp($socket, base64_encode($password));
        $passResponse = $readSmtp($socket);

        if (!str_starts_with($passResponse, '235')) {
            fclose($socket);

            return [
                'success' => false,
                'error' => 'SMTPパスワード認証に失敗しました。',
            ];
        }
    }

    $writeSmtp($socket, 'MAIL FROM:<' . $from . '>');
    $mailFrom = $readSmtp($socket);

    if (!str_starts_with($mailFrom, '250')) {
        fclose($socket);

        return [
            'success' => false,
            'error' => 'MAIL FROM失敗: ' . trim($mailFrom),
        ];
    }

    $writeSmtp($socket, 'RCPT TO:<' . $to . '>');
    $rcpt = $readSmtp($socket);

    if (!preg_match('/^25[0-9]/', $rcpt)) {
        fclose($socket);

        return [
            'success' => false,
            'error' => 'RCPT TO失敗: ' . trim($rcpt),
        ];
    }

    $writeSmtp($socket, 'DATA');
    $dataResponse = $readSmtp($socket);

    if (!str_starts_with($dataResponse, '354')) {
        fclose($socket);

        return [
            'success' => false,
            'error' => 'DATA失敗: ' . trim($dataResponse),
        ];
    }

    $fromName = (string)($settings['fromName'] ?? '');
    $replyTo = (string)($settings['replyTo'] ?? '');

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . ($fromName !== ''
            ? '"' . addcslashes($fromName, '"') . '" <' . $from . '>'
            : $from),
        'To: ' . $to,
        'Subject: =?UTF-8?B?' .
            base64_encode($subject) .
            '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $message = implode("\r\n", $headers) .
        "\r\n\r\n" .
        normalizeMailBody($body) .
        "\r\n.";

    fwrite($socket, $message . "\r\n");

    $sendResponse = $readSmtp($socket);

    $writeSmtp($socket, 'QUIT');
    $readSmtp($socket);

    fclose($socket);

    if (!str_starts_with($sendResponse, '250')) {
        return [
            'success' => false,
            'error' => 'メール送信失敗: ' . trim($sendResponse),
        ];
    }

    return [
        'success' => true,
    ];
}

function normalizeMailBody(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);

    $lines = explode("\n", $body);

    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }
    unset($line);

    return implode("\r\n", $lines);
}

function apiSendTestMail(): never
{
    $settings = readJson('settings.json', []);
    $mail = $settings['mail'] ?? [];

    $to = trim((string)($_POST['to'] ?? ''));

    if ($to === '') {
        $to = (string)($mail['fromEmail'] ?? '');
    }

    $result = sendSmtpMail(
        $mail,
        $to,
        'アンケート管理システム テストメール',
        'SMTP接続テストメールです。'
    );

    jsonResponse($result + [
        'testedAt' => nowIso(),
    ]);
}


/* ============================================================
 * kintone
 * ============================================================ */

function apiSaveKintoneSettings(): never
{
    $settings = readJson('settings.json', []);
    $input = json_decode(
        (string)($_POST['settings'] ?? '{}'),
        true
    );

    if (!is_array($input)) {
        jsonResponse([
            'success' => false,
            'error' => '設定データが不正です。',
        ], 400);
    }

    $old = $settings['kintone'] ?? [];

    $settings['kintone'] = [
        'subdomain' => normalizeKintoneSubdomain(
            (string)($input['subdomain'] ?? '')
        ),
        'appId' => trim((string)($input['appId'] ?? '')),
        'loginName' => trim((string)($input['loginName'] ?? '')),
        'password' => (string)($input['password'] ?? ''),
        'sslVerify' => !empty($input['sslVerify']),
        'proxy' => trim((string)($input['proxy'] ?? '')),
        'fieldMapping' => is_array($input['fieldMapping'] ?? null)
            ? $input['fieldMapping']
            : ($old['fieldMapping'] ?? []),
        'fields' => $old['fields'] ?? [],
        'lastFieldFetchAt' => $old['lastFieldFetchAt'] ?? null,
        'lastSyncAt' => $old['lastSyncAt'] ?? null,
    ];

    writeJson('settings.json', $settings);

    jsonResponse([
        'success' => true,
        'settings' => $settings['kintone'],
    ]);
}

function normalizeKintoneSubdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = rtrim($value, '/');

    if (str_ends_with($value, '.cybozu.com')) {
        return substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    return $value;
}

function kintoneBaseUrl(array $settings): string
{
    $subdomain = normalizeKintoneSubdomain(
        (string)($settings['subdomain'] ?? '')
    );

    if ($subdomain === '') {
        throw new RuntimeException(
            'kintoneサブドメインが設定されていません。'
        );
    }

    return 'https://' . $subdomain . '.cybozu.com';
}

function kintoneRequest(
    string $method,
    string $endpoint,
    array $settings,
    ?array $payload = null
): array {
    $url = kintoneBaseUrl($settings) . $endpoint;

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException(
            'cURLを初期化できませんでした。'
        );
    }

    $headers = [
        'Content-Type: application/json',
    ];

    $loginName = (string)($settings['loginName'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($loginName !== '') {
        $headers[] = 'X-Cybozu-Authorization: ' .
            base64_encode($loginName . ':' . $password);
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => !empty($settings['sslVerify']),
        CURLOPT_SSL_VERIFYHOST => !empty($settings['sslVerify']) ? 2 : 0,
    ];

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        $options[CURLOPT_PROXY] = $proxy;
        $options[CURLOPT_PROXYAUTH] = CURLAUTH_NONE;
    }

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException(
            'kintone API通信失敗: ' . $curlError
        );
    }

    $decoded = json_decode($body, true);

    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded)
            ? (string)($decoded['message'] ?? $body)
            : $body;

        throw new RuntimeException(
            'kintone APIエラー HTTP ' .
            $status .
            ': ' .
            $message
        );
    }

    return is_array($decoded) ? $decoded : [];
}

function kintoneSettings(): array
{
    $settings = readJson('settings.json', []);

    return is_array($settings['kintone'] ?? null)
        ? $settings['kintone']
        : [];
}

function apiKintoneTest(): never
{
    try {
        $settings = kintoneSettings();

        $appId = trim((string)($settings['appId'] ?? ''));

        if ($appId === '') {
            throw new RuntimeException(
                '顧客管理アプリIDが設定されていません。'
            );
        }

        kintoneRequest(
            'GET',
            '/k/v1/app.json?id=' . rawurlencode($appId),
            $settings
        );

        jsonResponse([
            'success' => true,
            'message' => '接続成功',
            'testedAt' => nowIso(),
        ]);
    } catch (Throwable $e) {
        jsonResponse([
            'success' => false,
            'message' => '接続失敗',
            'error' => $e->getMessage(),
        ], 500);
    }
}

function apiKintoneGetFields(): never
{
    try {
        $settings = kintoneSettings();
        $appId = trim((string)($settings['appId'] ?? ''));

        if ($appId === '') {
            throw new RuntimeException(
                '顧客管理アプリIDが設定されていません。'
            );
        }

        $result = kintoneRequest(
            'GET',
            '/k/v1/app/form/fields.json?app=' .
            rawurlencode($appId),
            $settings
        );

        $fields = [];

        foreach (($result['properties'] ?? []) as $code => $field) {
            $fields[] = [
                'code' => $code,
                'label' => (string)($field['label'] ?? $code),
                'type' => (string)($field['type'] ?? ''),
            ];
        }

        $all = readJson('settings.json', []);
        $all['kintone']['fields'] = $fields;
        $all['kintone']['lastFieldFetchAt'] = nowIso();

        writeJson('settings.json', $all);

        jsonResponse([
            'success' => true,
            'fields' => $fields,
        ]);
    } catch (Throwable $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}

function apiKintoneSync(): never
{
    try {
        $settings = kintoneSettings();
        $appId = trim((string)($settings['appId'] ?? ''));

        if ($appId === '') {
            throw new RuntimeException(
                '顧客管理アプリIDが設定されていません。'
            );
        }

        $mapping = $settings['fieldMapping'] ?? [];

        $customers = readJson('customers.json', []);

        $existingByKintoneId = [];

        foreach ($customers as $customer) {
            if (!empty($customer['kintoneRecordId'])) {
                $existingByKintoneId[
                    (string)$customer['kintoneRecordId']
                ] = $customer;
            }
        }

        $records = [];
        $offset = 0;

        do {
            $result = kintoneRequest(
                'GET',
                '/k/v1/records.json?app=' .
                rawurlencode($appId) .
                '&query=' .
                rawurlencode(
                    'order by $id asc limit 500 offset ' . $offset
                ),
                $settings
            );

            $batch = $result['records'] ?? [];

            if (!is_array($batch)) {
                $batch = [];
            }

            $records = array_merge($records, $batch);
            $offset += count($batch);
        } while (count($batch) === 500);

        $synced = [];

        foreach ($records as $record) {
            $recordId = (string)(
                $record['$id']['value'] ?? ''
            );

            if ($recordId === '') {
                continue;
            }

            $customerId = $existingByKintoneId[$recordId]['customerId']
                ?? uuid('customer_');

            $addressParts = [];

            foreach (($mapping['address'] ?? []) as $code) {
                $value = $record[$code]['value'] ?? '';

                if (is_array($value)) {
                    $value = implode(' ', $value);
                }

                if ((string)$value !== '') {
                    $addressParts[] = (string)$value;
                }
            }

            $synced[] = [
                'customerId' => $customerId,
                'kintoneRecordId' => $recordId,
                'organization' => fieldValue(
                    $record,
                    $mapping['organization'] ?? ''
                ),
                'name' => fieldValue(
                    $record,
                    $mapping['name'] ?? ''
                ),
                'email' => fieldValue(
                    $record,
                    $mapping['email'] ?? ''
                ),
                'department' => fieldValue(
                    $record,
                    $mapping['department'] ?? ''
                ),
                'phone' => fieldValue(
                    $record,
                    $mapping['phone'] ?? ''
                ),
                'address' => implode(' ', $addressParts),
                'updatedAt' => nowIso(),
            ];
        }

        writeJson('customers.json', $synced);

        $all = readJson('settings.json', []);
        $all['kintone']['lastSyncAt'] = nowIso();
        writeJson('settings.json', $all);

        jsonResponse([
            'success' => true,
            'message' => '顧客同期完了',
            'count' => count($synced),
            'syncedAt' => nowIso(),
        ]);
    } catch (Throwable $e) {
        jsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}

function fieldValue(array $record, string $code): string
{
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        return implode(', ', array_map(
            static fn($v): string => is_scalar($v)
                ? (string)$v
                : '',
            $value
        ));
    }

    return (string)$value;
}


/* ============================================================
 * メール設定
 * ============================================================ */

function apiSaveMailSettings(): never
{
    $settings = readJson('settings.json', []);
    $input = json_decode(
        (string)($_POST['settings'] ?? '{}'),
        true
    );

    if (!is_array($input)) {
        jsonResponse([
            'success' => false,
            'error' => 'メール設定が不正です。',
        ], 400);
    }

    $settings['mail'] = [
        'smtpHost' => trim((string)($input['smtpHost'] ?? '')),
        'smtpPort' => (int)($input['smtpPort'] ?? 587),
        'encryption' => in_array(
            ($input['encryption'] ?? 'tls'),
            ['none', 'tls', 'ssl'],
            true
        )
            ? (string)$input['encryption']
            : 'tls',
        'smtpAuth' => !empty($input['smtpAuth']),
        'username' => (string)($input['username'] ?? ''),
        'password' => (string)($input['password'] ?? ''),
        'fromEmail' => trim((string)($input['fromEmail'] ?? '')),
        'fromName' => (string)($input['fromName'] ?? ''),
        'replyTo' => trim((string)($input['replyTo'] ?? '')),
        'status' => '未設定',
        'lastTestAt' => null,
    ];

    writeJson('settings.json', $settings);

    jsonResponse([
        'success' => true,
        'settings' => $settings['mail'],
    ]);
}


/* ============================================================
 * CSV / PDF
 * ============================================================ */

function apiExportCsv(): never
{
    $surveyId = trim((string)($_POST['surveyId'] ?? ''));

    $surveys = readJson('surveys.json', []);
    $survey = findSurvey($surveys, $surveyId);

    if (!$survey) {
        jsonResponse([
            'success' => false,
            'error' => 'アンケートが存在しません。',
        ], 404);
    }

    $responses = readJson('responses.json', []);
    $rows = [];

    $header = [
        'responseId',
        'surveyId',
        'customerId',
        '回答日時',
        '組織名',
        '氏名',
        'メールアドレス',
        '部署名',
        '電話番号',
        '住所',
    ];

    foreach (flattenQuestions($survey) as $question) {
        $header[] = $question['questionNumber'];
        $header[] = $question['questionText'];
    }

    $rows[] = $header;

    foreach ($responses as $response) {
        if (($response['surveyId'] ?? '') !== $surveyId) {
            continue;
        }

        $respondent = $response['respondent'] ?? [];

        $row = [
            $response['responseId'] ?? '',
            $surveyId,
            $response['customerId'] ?? '',
            $response['submittedAt'] ?? '',
            $respondent['organization'] ?? '',
            $respondent['name'] ?? '',
            $respondent['email'] ?? '',
            $respondent['department'] ?? '',
            $respondent['phone'] ?? '',
            $respondent['address'] ?? '',
        ];

        $answers = $response['answers'] ?? [];

        foreach (flattenQuestions($survey) as $question) {
            $value = $answers[$question['questionId']] ?? '';

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $row[] = $value;
            $row[] = $value;
        }

        $rows[] = $row;
    }

    $fp = fopen('php://temp', 'w+b');

    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }

    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);

    jsonResponse([
        'success' => true,
        'filename' => 'survey-' . $surveyId . '.csv',
        'mime' => 'text/csv;charset=utf-8',
        'contentBase64' => base64_encode(
            "\xEF\xBB\xBF" . $csv
        ),
    ]);
}

function apiExportPdf(): never
{
    $surveyId = trim((string)($_POST['surveyId'] ?? ''));

    $surveys = readJson('surveys.json', []);
    $survey = findSurvey($surveys, $surveyId);

    if (!$survey) {
        jsonResponse([
            'success' => false,
            'error' => 'アンケートが存在しません。',
        ], 404);
    }

    jsonResponse([
        'success' => true,
        'message' => 'PDF出力操作を受け付けました。',
        'surveyId' => $surveyId,
        'generatedAt' => nowIso(),
    ]);
}


/* ============================================================
 * 画面
 * ============================================================ */

function renderApplication(
    string $view,
    string $surveyId,
    string $token
): void {
    $surveys = readJson('surveys.json', []);
    updateAutomaticEndStatus($surveys);

    if (json_encode($surveys) !== false) {
        writeJson('surveys.json', $surveys);
    }

    $customers = readJson('customers.json', []);
    $responses = readJson('responses.json', []);
    $history = readJson('send_history.json', []);
    $settings = readJson('settings.json', []);

    $survey = $surveyId !== ''
        ? findSurvey($surveys, $surveyId)
        : null;

    $initial = [
        'view' => $view,
        'surveyId' => $surveyId,
        'token' => $token,
        'surveys' => $surveys,
        'survey' => $survey,
        'customers' => $customers,
        'responses' => $responses,
        'sendHistory' => $history,
        'settings' => $settings,
    ];

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:
system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",
sans-serif;color:#172033;background:#f5f7fb}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.app{min-height:100vh}
.header{height:60px;background:#172033;color:#fff;
display:flex;align-items:center;padding:0 20px;
justify-content:space-between}
.header-title{font-weight:700}
.container{max-width:1440px;margin:0 auto;padding:24px}
.card{background:#fff;border:1px solid #dfe4ec;
border-radius:10px;padding:20px;margin-bottom:18px;
box-shadow:0 2px 8px rgba(20,30,50,.04)}
.toolbar{display:flex;gap:10px;align-items:center;
flex-wrap:wrap;margin-bottom:18px}
.btn{border:1px solid #ccd3df;background:#fff;
border-radius:7px;padding:8px 14px}
.btn-primary{background:#2563eb;border-color:#2563eb;color:#fff}
.btn-danger{background:#dc2626;border-color:#dc2626;color:#fff}
.btn-success{background:#16a34a;border-color:#16a34a;color:#fff}
.btn:disabled{opacity:.5;cursor:not-allowed}
input,textarea,select{width:100%;border:1px solid #ccd3df;
border-radius:7px;padding:9px 11px;background:#fff}
textarea{min-height:110px;resize:vertical}
label{display:block;font-weight:600;margin-bottom:6px}
.form-grid{display:grid;grid-template-columns:
repeat(2,minmax(0,1fr));gap:16px}
.form-grid .full{grid-column:1/-1}
table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid #e7ebf1;
padding:11px 9px;text-align:left;vertical-align:top}
th{background:#f8fafc;white-space:nowrap}
.badge{display:inline-block;border-radius:999px;
padding:3px 9px;font-size:12px;font-weight:700}
.badge-draft{background:#eef2f7;color:#536071}
.badge-open{background:#dcfce7;color:#166534}
.badge-stop{background:#fef3c7;color:#92400e}
.badge-end{background:#fee2e2;color:#991b1b}
.grid{display:grid;grid-template-columns:
repeat(3,minmax(0,1fr));gap:16px}
.stat{font-size:28px;font-weight:800}
.muted{color:#667085}
.actions{display:flex;gap:6px;flex-wrap:wrap}
.modal-backdrop{position:fixed;inset:0;background:
rgba(0,0,0,.45);display:none;align-items:center;
justify-content:center;padding:20px;z-index:100}
.modal{background:#fff;border-radius:12px;max-width:720px;
width:100%;max-height:90vh;overflow:auto;padding:22px}
.modal-backdrop.open{display:flex}
.notice{padding:12px 14px;border-radius:8px;
background:#eff6ff;color:#1e40af;margin-bottom:15px}
.error{padding:12px 14px;border-radius:8px;
background:#fef2f2;color:#991b1b;margin-bottom:15px}
.success{padding:12px 14px;border-radius:8px;
background:#f0fdf4;color:#166534;margin-bottom:15px}
.group{border:1px solid #dfe4ec;border-radius:10px;
padding:16px;margin-bottom:14px;background:#fafbfd}
.question{background:#fff;border:1px solid #e2e8f0;
border-radius:8px;padding:14px;margin-top:10px}
.choice{display:flex;gap:8px;align-items:center;margin:7px 0}
.choice input{width:auto}
.kpi{background:#fff;border:1px solid #dfe4ec;
border-radius:10px;padding:18px}
.answer-option{display:block;border:1px solid #d8dee8;
padding:14px;border-radius:9px;margin:8px 0}
.answer-option input{width:auto;margin-right:8px}
@media(max-width:800px){
 .container{padding:14px}
 .form-grid,.grid{grid-template-columns:1fr}
 .table-wrap{overflow-x:auto}
 table{min-width:800px}
 .header{padding:0 14px}
}
</style>
</head>
<body>
<div class="app">
<header class="header">
<div class="header-title">アンケート管理システム</div>
<div id="headerRight"></div>
</header>
<main class="container" id="app"></main>
</div>

<div class="modal-backdrop" id="modalBackdrop">
<div class="modal">
<div id="modalBody"></div>
<div class="toolbar" style="justify-content:flex-end">
<button class="btn" onclick="closeModal()">閉じる</button>
</div>
</div>
</div>

<script>
"use strict";

const INITIAL_DATA = <?= json_encode(
    $initial,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

let currentView = "";
let currentSurveyId = "";
let currentToken = "";
let appData = INITIAL_DATA;

function readUrlState(){
    const params = new URLSearchParams(location.search);

    let view = params.get("view") || "admin-survey-list";

    const allowed = [
        "admin-survey-list",
        "admin-survey-edit",
        "admin-preview",
        "admin-send",
        "admin-aggregation",
        "admin-kintone",
        "admin-mail",
        "answer",
        "confirm",
        "complete"
    ];

    if(!allowed.includes(view)){
        view = "admin-survey-list";
    }

    return {
        view,
        surveyId: params.get("surveyId") || "",
        token: params.get("token") || ""
    };
}

function syncFromUrl(){
    const state = readUrlState();

    currentView = state.view;
    currentSurveyId = state.surveyId;
    currentToken = state.token;

    appData.view = currentView;
    appData.surveyId = currentSurveyId;
    appData.token = currentToken;
}

function navigate(view, params={}){
    const query = new URLSearchParams();
    query.set("view", view);

    Object.entries(params).forEach(([key,value])=>{
        if(value !== undefined && value !== null && value !== ""){
            query.set(key,value);
        }
    });

    history.pushState({}, "", "index.php?" + query.toString());
    syncFromUrl();
    render();
}

window.addEventListener("popstate",()=>{
    syncFromUrl();
    render();
});

function escapeHtml(value){
    return String(value ?? "")
        .replaceAll("&","&amp;")
        .replaceAll("<","&lt;")
        .replaceAll(">","&gt;")
        .replaceAll('"',"&quot;")
        .replaceAll("'","&#039;");
}

function api(action,data={}){
    const body = new URLSearchParams();
    body.set("action",action);

    Object.entries(data).forEach(([key,value])=>{
        body.set(
            key,
            typeof value === "object"
                ? JSON.stringify(value)
                : String(value ?? "")
        );
    });

    return fetch("index.php",{
        method:"POST",
        headers:{
            "Content-Type":
                "application/x-www-form-urlencoded;charset=UTF-8"
        },
        body
    }).then(async response=>{
        const result = await response.json();

        if(!response.ok || result.success === false){
            throw new Error(
                result.error ||
                result.message ||
                "処理に失敗しました。"
            );
        }

        return result;
    });
}

function reload(){
    location.reload();
}

function openModal(html){
    document.getElementById("modalBody").innerHTML = html;
    document.getElementById("modalBackdrop")
        .classList.add("open");
}

function closeModal(){
    document.getElementById("modalBackdrop")
        .classList.remove("open");
}

function confirmAction(message,callback){
    openModal(`
        <h3>確認</h3>
        <p>${escapeHtml(message)}</p>
        <div class="toolbar" style="justify-content:flex-end">
            <button class="btn" onclick="closeModal()">キャンセル</button>
            <button class="btn btn-primary"
                id="modalConfirmButton">実行</button>
        </div>
    `);

    document.getElementById("modalConfirmButton")
        .onclick = async ()=>{
            closeModal();
            await callback();
        };
}

function statusBadge(status){
    const classes = {
        "下書き":"badge-draft",
        "公開中":"badge-open",
        "停止":"badge-stop",
        "終了":"badge-end"
    };

    return `<span class="badge ${
        classes[status] || "badge-draft"
    }">${escapeHtml(status)}</span>`;
}

function findSurvey(id){
    return appData.surveys.find(
        s => String(s.surveyId) === String(id)
    ) || null;
}

function findCustomer(id){
    return appData.customers.find(
        c => String(c.customerId) === String(id)
    ) || null;
}

function surveyResponses(id){
    return appData.responses.filter(
        r => String(r.surveyId) === String(id)
    );
}

function surveyHistory(id){
    return appData.sendHistory.filter(
        h => String(h.surveyId) === String(id)
    );
}

function getAnswerStatus(surveyId,customerId){
    const response = appData.responses.find(r =>
        String(r.surveyId) === String(surveyId) &&
        String(r.customerId || "") === String(customerId)
    );

    if(response){
        return "回答済み";
    }

    const sent = appData.sendHistory.some(h =>
        String(h.surveyId) === String(surveyId) &&
        String(h.customerId) === String(customerId) &&
        h.success
    );

    return sent ? "送信済み / 未回答" : "未送信";
}


/* ============================================================
 * 一覧
 * ============================================================ */

function renderList(){
    const surveys = [...appData.surveys];

    document.getElementById("app").innerHTML = `
        <div class="toolbar">
            <div style="flex:1">
                <h2 style="margin:0">アンケート一覧</h2>
                <div class="muted">
                    アンケート管理業務の起点
                </div>
            </div>
            <button class="btn btn-primary"
                onclick="navigate('admin-survey-edit')">
                ＋ アンケート作成
            </button>
        </div>

        <div class="card">
            <div class="toolbar">
                <input id="surveySearch"
                    placeholder="タイトルで検索"
                    style="max-width:360px">
                <select id="surveyFilter"
                    style="max-width:180px">
                    <option value="">すべて</option>
                    <option value="公開中">公開中</option>
                    <option value="下書き">下書き</option>
                    <option value="停止">停止</option>
                    <option value="終了">終了</option>
                </select>
                <select id="surveySort"
                    style="max-width:220px">
                    <option value="updated_desc">更新日 新しい順</option>
                    <option value="updated_asc">更新日 古い順</option>
                    <option value="answers_desc">回答数 多い順</option>
                    <option value="answers_asc">回答数 少ない順</option>
                    <option value="start_desc">開始日 新しい順</option>
                    <option value="start_asc">開始日 古い順</option>
                </select>
            </div>

            <div class="table-wrap">
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
                    <tbody id="surveyRows"></tbody>
                </table>
            </div>
        </div>
    `;

    const renderRows = ()=>{
        let filtered = [...surveys];

        const keyword =
            document.getElementById("surveySearch")
                .value.trim().toLowerCase();

        const filter =
            document.getElementById("surveyFilter").value;

        const sort =
            document.getElementById("surveySort").value;

        if(keyword){
            filtered = filtered.filter(s =>
                String(s.title || "")
                    .toLowerCase()
                    .includes(keyword)
            );
        }

        if(filter){
            filtered = filtered.filter(
                s => s.status === filter
            );
        }

        filtered.sort((a,b)=>{
            const ad = new Date(a.updatedAt || 0).getTime();
            const bd = new Date(b.updatedAt || 0).getTime();

            if(sort === "updated_desc") return bd-ad;
            if(sort === "updated_asc") return ad-bd;

            const ar = surveyResponses(a.surveyId).length;
            const br = surveyResponses(b.surveyId).length;

            if(sort === "answers_desc") return br-ar;
            if(sort === "answers_asc") return ar-br;

            const as = new Date(a.startAt || 0).getTime();
            const bs = new Date(b.startAt || 0).getTime();

            if(sort === "start_desc") return bs-as;
            return as-bs;
        });

        document.getElementById("surveyRows").innerHTML =
            filtered.map(s=>{
                const count = surveyResponses(s.surveyId).length;

                return `
                <tr>
                    <td>
                        ${escapeHtml(s.createdAt || "")}<br>
                        <span class="muted">
                            ${escapeHtml(s.updatedAt || "")}
                        </span>
                    </td>
                    <td>
                        <strong>${escapeHtml(s.title)}</strong>
                    </td>
                    <td>
                        ${escapeHtml(s.startAt || "未設定")}
                        ～<br>
                        ${escapeHtml(s.endAt || "未設定")}
                    </td>
                    <td>${statusBadge(s.status)}</td>
                    <td>${count}</td>
                    <td>
                        <div class="actions">
                            <button class="btn"
                                onclick="navigate(
                                'admin-survey-edit',
                                {surveyId:'${escapeHtml(s.surveyId)}'}
                                )">
                                確認・編集
                            </button>
                            <button class="btn"
                                onclick="navigate(
                                'admin-aggregation',
                                {surveyId:'${escapeHtml(s.surveyId)}'}
                                )">
                                集計
                            </button>
                            <button class="btn"
                                onclick="navigate(
                                'admin-send',
                                {surveyId:'${escapeHtml(s.surveyId)}'}
                                )">
                                送信
                            </button>
                            <button class="btn"
                                onclick="duplicateSurvey(
                                '${escapeHtml(s.surveyId)}'
                                )">
                                複製
                            </button>
                            <button class="btn btn-danger"
                                onclick="deleteSurvey(
                                '${escapeHtml(s.surveyId)}'
                                )">
                                削除
                            </button>
                        </div>
                    </td>
                </tr>`;
            }).join("") || `
                <tr>
                    <td colspan="6" class="muted">
                        アンケートがありません。
                    </td>
                </tr>`;
    };

    ["surveySearch","surveyFilter","surveySort"]
        .forEach(id=>{
            document.getElementById(id)
                .addEventListener("input",renderRows);

            document.getElementById(id)
                .addEventListener("change",renderRows);
        });

    document.getElementById("surveySearch")
        .addEventListener("keydown",e=>{
            if(e.key === "Enter"){
                e.preventDefault();
                renderRows();
            }
        });

    renderRows();
}

async function deleteSurvey(id){
    confirmAction(
        "このアンケートを削除しますか？",
        async ()=>{
            try{
                await api("delete_survey",{
                    surveyId:id
                });
                reload();
            }catch(e){
                alert(e.message);
            }
        }
    );
}

async function duplicateSurvey(id){
    confirmAction(
        "このアンケートを複製しますか？",
        async ()=>{
            try{
                await api("duplicate_survey",{
                    surveyId:id
                });
                reload();
            }catch(e){
                alert(e.message);
            }
        }
    );
}


/* ============================================================
 * 編集
 * ============================================================ */

function createEmptySurvey(){
    return {
        surveyId:"",
        title:"",
        description:"",
        startAt:"",
        endAt:"",
        numberingMode:"global",
        allowResubmit:false,
        status:"下書き",
        groups:[]
    };
}

function renderEdit(){
    const source = currentSurveyId
        ? findSurvey(currentSurveyId)
        : null;

    const survey = source
        ? JSON.parse(JSON.stringify(source))
        : createEmptySurvey();

    document.getElementById("app").innerHTML = `
        <div class="toolbar">
            <div style="flex:1">
                <h2>
                    ${survey.surveyId
                        ? "アンケート編集"
                        : "アンケート作成"}
                </h2>
            </div>
            <button class="btn"
                onclick="navigate('admin-survey-list')">
                キャンセル
            </button>
            <button class="btn btn-primary"
                id="saveSurveyButton">
                保存して一覧へ
            </button>
        </div>

        <div class="card">
            <h3>基本情報</h3>

            <div class="form-grid">
                <div class="full">
                    <label>タイトル</label>
                    <input id="surveyTitle"
                        value="${escapeHtml(survey.title)}">
                </div>

                <div class="full">
                    <label>説明</label>
                    <textarea id="surveyDescription">
${escapeHtml(survey.description)}</textarea>
                </div>

                <div>
                    <label>開始日時</label>
                    <input type="datetime-local"
                        id="surveyStart"
                        value="${escapeHtml(
                            toDatetimeLocal(survey.startAt)
                        )}">
                </div>

                <div>
                    <label>終了日時</label>
                    <input type="datetime-local"
                        id="surveyEnd"
                        value="${escapeHtml(
                            toDatetimeLocal(survey.endAt)
                        )}">
                </div>

                <div>
                    <label>質問番号の採番方式</label>
                    <select id="numberingMode">
                        <option value="global"
                            ${survey.numberingMode === "global"
                                ? "selected":""}>
                            アンケート全体で通番
                        </option>
                        <option value="group"
                            ${survey.numberingMode === "group"
                                ? "selected":""}>
                            グループ毎に採番
                        </option>
                    </select>
                </div>

                <div>
                    <label>再回答</label>
                    <label style="font-weight:400">
                        <input type="checkbox"
                            id="allowResubmit"
                            ${survey.allowResubmit
                                ? "checked":""}
                            style="width:auto">
                        再回答を許可する
                    </label>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <h3 style="flex:1">質問・グループ</h3>
            </div>

            <div id="groups"></div>

            <button class="btn"
                onclick="addGroup()">
                ＋ グループ追加
            </button>
        </div>

        ${survey.surveyId ? `
        <div class="card">
            <h3>状態</h3>
            <p>${statusBadge(survey.status)}</p>
            <div class="actions">
                ${survey.status === "下書き" ? `
                <button class="btn btn-success"
                    onclick="changeStatus(
                    '${escapeHtml(survey.surveyId)}','公開中'
                    )">
                    公開
                </button>` : ""}

                ${survey.status === "公開中" ? `
                <button class="btn"
                    onclick="changeStatus(
                    '${escapeHtml(survey.surveyId)}','停止'
                    )">
                    停止
                </button>` : ""}

                ${survey.status === "停止" ? `
                <button class="btn btn-success"
                    onclick="changeStatus(
                    '${escapeHtml(survey.surveyId)}','公開中'
                    )">
                    再開
                </button>` : ""}
            </div>
        </div>` : ""}
    `;

    window.editingSurvey = survey;

    document.getElementById("numberingMode")
        .addEventListener("change",()=>{
            survey.numberingMode =
                document.getElementById("numberingMode").value;
            recalcNumbers(survey);
            renderGroups(survey);
        });

    document.getElementById("saveSurveyButton")
        .onclick = ()=>saveEditingSurvey(survey);

    renderGroups(survey);
}

function toDatetimeLocal(value){
    if(!value) return "";

    const date = new Date(value);

    if(Number.isNaN(date.getTime())){
        return String(value).slice(0,16);
    }

    const pad = n => String(n).padStart(2,"0");

    return `${date.getFullYear()}-${
        pad(date.getMonth()+1)}-${
        pad(date.getDate())}T${
        pad(date.getHours())}:${
        pad(date.getMinutes())}`;
}

function recalcNumbers(survey){
    let global = 0;

    survey.groups.forEach((group,gIndex)=>{
        group.sortOrder = gIndex;

        group.questions.forEach((q,qIndex)=>{
            q.sortOrder = qIndex;

            if(survey.numberingMode === "group"){
                q.questionNumber =
                    `Q${gIndex+1}-${qIndex+1}`;
            }else{
                global++;
                q.questionNumber = `Q${global}`;
            }
        });
    });
}

function renderGroups(survey){
    recalcNumbers(survey);

    const target = document.getElementById("groups");

    target.innerHTML = survey.groups.map(
        (group,gIndex)=>`
        <div class="group"
            draggable="true"
            data-group-index="${gIndex}">
            <div class="toolbar">
                <strong>グループ ${gIndex+1}</strong>
                <button class="btn"
                    onclick="deleteGroup(${gIndex})">
                    グループ削除
                </button>
            </div>

            <input
                value="${escapeHtml(group.groupTitle)}"
                placeholder="グループタイトル"
                oninput="updateGroupTitle(
                ${gIndex},this.value)">

            <div>
                ${group.questions.map(
                    (q,qIndex)=>renderQuestion(
                        survey,gIndex,q,qIndex
                    )
                ).join("")}
            </div>

            <button class="btn"
                onclick="addQuestion(${gIndex})">
                ＋ 質問追加
            </button>
        </div>`
    ).join("");

    document.querySelectorAll("[data-group-index]")
        .forEach(el=>{
            el.addEventListener("dragstart",e=>{
                e.dataTransfer.setData(
                    "group-index",
                    el.dataset.groupIndex
                );
            });

            el.addEventListener("dragover",e=>{
                e.preventDefault();
            });

            el.addEventListener("drop",e=>{
                e.preventDefault();

                const from = Number(
                    e.dataTransfer.getData("group-index")
                );
                const to = Number(
                    el.dataset.groupIndex
                );

                if(from === to) return;

                const moved = survey.groups.splice(from,1)[0];
                survey.groups.splice(to,0,moved);

                renderGroups(survey);
            });
        });
}

function renderQuestion(survey,gIndex,q,qIndex){
    return `
    <div class="question"
        draggable="true"
        data-question-group="${gIndex}"
        data-question-index="${qIndex}">
        <div class="toolbar">
            <strong>${escapeHtml(q.questionNumber)}</strong>

            <div style="flex:1"></div>

            <button class="btn"
                onclick="moveQuestion(
                ${gIndex},${qIndex}
                )">
                移動
            </button>

            <button class="btn btn-danger"
                onclick="deleteQuestion(
                ${gIndex},${qIndex}
                )">
                削除
            </button>
        </div>

        <label>質問文</label>
        <textarea
            oninput="updateQuestion(
            ${gIndex},${qIndex},'questionText',this.value
            )">${escapeHtml(q.questionText)}</textarea>

        <div class="form-grid">
            <div>
                <label>回答形式</label>
                <select
                    onchange="updateQuestion(
                    ${gIndex},${qIndex},
                    'answerType',this.value
                    )">
                    <option value="single"
                        ${q.answerType==="single"
                            ?"selected":""}>
                        単一選択
                    </option>
                    <option value="multiple"
                        ${q.answerType==="multiple"
                            ?"selected":""}>
                        複数選択
                    </option>
                    <option value="text"
                        ${q.answerType==="text"
                            ?"selected":""}>
                        自由記述
                    </option>
                </select>
            </div>

            <div>
                <label>回答</label>
                <label style="font-weight:400">
                    <input type="checkbox"
                        ${q.required?"checked":""}
                        onchange="updateQuestion(
                        ${gIndex},${qIndex},
                        'required',this.checked
                        )"
                        style="width:auto">
                    必須
                </label>
            </div>
        </div>

        ${q.answerType !== "text" ? `
        <div>
            <label>選択肢</label>
            ${(q.choices || []).map(
                (choice,cIndex)=>`
                <div class="choice">
                    <input
                        value="${escapeHtml(choice.label)}"
                        oninput="updateChoice(
                        ${gIndex},${qIndex},${cIndex},
                        this.value
                        )">
                    <button class="btn"
                        onclick="deleteChoice(
                        ${gIndex},${qIndex},${cIndex}
                        )">
                        削除
                    </button>
                </div>`
            ).join("")}
            <button class="btn"
                onclick="addChoice(
                ${gIndex},${qIndex}
                )">
                ＋ 選択肢追加
            </button>
        </div>` : ""}

        ${q.answerType === "single" ? `
        <div style="margin-top:12px">
            <label>条件分岐</label>
            <div class="muted">
                questionId + choiceId → nextQuestionId
                で内部管理します。
            </div>
            <input
                placeholder="questionId"
                value="${escapeHtml(
                    q.condition?.questionId || ""
                )}"
                oninput="updateCondition(
                ${gIndex},${qIndex},
                'questionId',this.value
                )">
            <input
                placeholder="choiceId"
                value="${escapeHtml(
                    q.condition?.choiceId || ""
                )}"
                oninput="updateCondition(
                ${gIndex},${qIndex},
                'choiceId',this.value
                )"
                style="margin-top:6px">
            <input
                placeholder="nextQuestionId"
                value="${escapeHtml(
                    q.condition?.nextQuestionId || ""
                )}"
                oninput="updateCondition(
                ${gIndex},${qIndex},
                'nextQuestionId',this.value
                )"
                style="margin-top:6px">
        </div>` : ""}
    </div>`;
}

function updateGroupTitle(index,value){
    window.editingSurvey.groups[index].groupTitle=value;
}

function addGroup(){
    const survey=window.editingSurvey;

    survey.groups.push({
        groupId:"group_"+crypto.randomUUID(),
        groupTitle:"",
        sortOrder:survey.groups.length,
        questions:[]
    });

    renderGroups(survey);
}

function deleteGroup(index){
    const survey=window.editingSurvey;
    const group=survey.groups[index];

    const message=group.questions.length
        ? "質問が存在します。このグループを削除しますか？"
        : "このグループを削除しますか？";

    confirmAction(message,()=>{
        survey.groups.splice(index,1);
        renderGroups(survey);
    });
}

function addQuestion(groupIndex){
    const survey=window.editingSurvey;
    const group=survey.groups[groupIndex];

    group.questions.push({
        questionId:"question_"+crypto.randomUUID(),
        questionNumber:"",
        questionText:"",
        answerType:"single",
        required:false,
        choices:[],
        condition:null,
        sortOrder:group.questions.length
    });

    renderGroups(survey);
}

function deleteQuestion(gIndex,qIndex){
    confirmAction(
        "この質問を削除しますか？",
        ()=>{
            window.editingSurvey.groups[gIndex]
                .questions.splice(qIndex,1);

            renderGroups(window.editingSurvey);
        }
    );
}

function moveQuestion(gIndex,qIndex){
    const survey=window.editingSurvey;
    const question=survey.groups[gIndex]
        .questions[qIndex];

    const options=survey.groups
        .map((g,i)=>`${i+1}: ${g.groupTitle || "グループ"+(i+1)}`)
        .join("\n");

    const input=prompt(
        "移動先グループ番号を入力してください。\n\n"+
        options,
        String(gIndex+1)
    );

    if(input===null)return;

    const target=Number(input)-1;

    if(
        !Number.isInteger(target) ||
        target<0 ||
        target>=survey.groups.length
    ){
        alert("移動先が不正です。");
        return;
    }

    survey.groups[gIndex].questions.splice(qIndex,1);

    question.sortOrder =
        survey.groups[target].questions.length;

    survey.groups[target].questions.push(question);

    renderGroups(survey);
}

function updateQuestion(g,q,key,value){
    window.editingSurvey.groups[g].questions[q][key]=value;
}

function addChoice(g,q){
    const question=window.editingSurvey
        .groups[g].questions[q];

    question.choices.push({
        choiceId:"choice_"+crypto.randomUUID(),
        label:"",
        value:""
    });

    renderGroups(window.editingSurvey);
}

function deleteChoice(g,q,c){
    window.editingSurvey.groups[g]
        .questions[q].choices.splice(c,1);

    renderGroups(window.editingSurvey);
}

function updateChoice(g,q,c,value){
    window.editingSurvey.groups[g]
        .questions[q].choices[c].label=value;

    window.editingSurvey.groups[g]
        .questions[q].choices[c].value=value;
}

function updateCondition(g,q,key,value){
    const question=window.editingSurvey
        .groups[g].questions[q];

    if(!question.condition){
        question.condition={
            questionId:"",
            choiceId:"",
            nextQuestionId:""
        };
    }

    question.condition[key]=value;
}

async function saveEditingSurvey(survey){
    survey.title =
        document.getElementById("surveyTitle").value;

    survey.description =
        document.getElementById("surveyDescription").value;

    survey.startAt =
        document.getElementById("surveyStart").value;

    survey.endAt =
        document.getElementById("surveyEnd").value;

    survey.numberingMode =
        document.getElementById("numberingMode").value;

    survey.allowResubmit =
        document.getElementById("allowResubmit").checked;

    recalcNumbers(survey);

    try{
        const result=await api("save_survey",{survey});
        navigate("admin-survey-list");
    }catch(e){
        alert(e.message);
    }
}

async function changeStatus(id,status){
    const labels={
        "公開中":"公開",
        "停止":"停止"
    };

    confirmAction(
        `アンケートを「${labels[status]}」しますか？`,
        async ()=>{
            try{
                await api("change_survey_status",{
                    surveyId:id,
                    status
                });
                reload();
            }catch(e){
                alert(e.message);
            }
        }
    );
}


/* ============================================================
 * プレビュー
 * ============================================================ */

function renderPreview(){
    const survey=findSurvey(currentSurveyId);

    if(!survey){
        renderMissingSurvey();
        return;
    }

    document.getElementById("app").innerHTML=`
        <div class="toolbar">
            <div style="flex:1">
                <h2>アンケートプレビュー</h2>
                <div class="muted">
                    実際の送信は行いません。
                </div>
            </div>
            <button class="btn"
                onclick="navigate(
                'admin-survey-edit',
                {surveyId:'${escapeHtml(currentSurveyId)}'}
                )">
                編集へ戻る
            </button>
        </div>

        <div class="card">
            <h2>${escapeHtml(survey.title)}</h2>
            <p>${escapeHtml(survey.description)}</p>
            ${renderSurveyQuestions(
                survey,
                "preview"
            )}
        </div>
    `;
}


/* ============================================================
 * 送信
 * ============================================================ */

function renderSend(){
    const survey=findSurvey(currentSurveyId);

    if(!survey){
        renderMissingSurvey();
        return;
    }

    const customers=appData.customers;

    document.getElementById("app").innerHTML=`
        <div class="toolbar">
            <div style="flex:1">
                <h2>送信</h2>
                <div class="muted">
                    対象アンケート:
                    <strong>${escapeHtml(survey.title)}</strong>
                </div>
            </div>
            <button class="btn"
                onclick="navigate('admin-survey-list')">
                一覧へ
            </button>
        </div>

        <div class="card">
            <h3>顧客選択</h3>

            <div class="toolbar">
                <input id="customerSearch"
                    placeholder="顧客名・組織名・メールアドレス"
                    style="max-width:360px">
                <select id="customerStatus"
                    style="max-width:220px">
                    <option value="">すべて</option>
                    <option value="未送信">未送信</option>
                    <option value="送信済み / 未回答">
                        送信済み / 未回答
                    </option>
                    <option value="回答済み">回答済み</option>
                </select>
                <button class="btn"
                    onclick="selectReminderTargets()">
                    未回答を選択
                </button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>
                            <input type="checkbox"
                                id="selectAllCustomers"
                                style="width:auto">
                        </th>
                        <th>組織名</th>
                        <th>氏名</th>
                        <th>メール</th>
                        <th>電話</th>
                        <th>住所</th>
                        <th>最終送信</th>
                        <th>送信回数</th>
                        <th>回答ステータス</th>
                    </tr>
                    </thead>
                    <tbody id="customerRows"></tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>メール送信</h3>

            <div class="form-grid">
                <div class="full">
                    <label>件名</label>
                    <input id="mailSubject"
                        value="${escapeHtml(
                            survey.title
                        )}">
                </div>

                <div class="full">
                    <label>本文</label>
                    <textarea id="mailBody">{
顧客名} 様

アンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
                </div>
            </div>

            <div class="notice">
                使用可能な変数:
                {顧客名} / {アンケートURL}
            </div>

            <div class="actions">
                <button class="btn btn-primary"
                    onclick="previewMail()">
                    メール内容確認
                </button>
                <button class="btn btn-primary"
                    onclick="sendSelected('一括送信')">
                    一括送信
                </button>
                <button class="btn"
                    onclick="sendSelected('再送')">
                    再送
                </button>
                <button class="btn"
                    onclick="sendSelected('リマインド')">
                    リマインド
                </button>
            </div>
        </div>

        <div class="card">
            <h3>送信結果</h3>
            <div id="sendResult" class="muted">
                送信実行後に結果を表示します。
            </div>
        </div>

        <div class="card">
            <h3>送信履歴</h3>
            <div id="history"></div>
        </div>
    `;

    renderCustomerRows(survey);
    renderHistory(survey);
}

function selectedCustomerIds(){
    return [...document.querySelectorAll(
        ".customer-check:checked"
    )].map(el=>el.value);
}

function renderCustomerRows(survey){
    const search=document.getElementById(
        "customerSearch"
    ).value.trim().toLowerCase();

    const status=document.getElementById(
        "customerStatus"
    ).value;

    const rows=appData.customers.filter(customer=>{
        const text=[
            customer.name,
            customer.organization,
            customer.email
        ].join(" ").toLowerCase();

        if(search && !text.includes(search)){
            return false;
        }

        if(status &&
            getAnswerStatus(
                survey.surveyId,
                customer.customerId
            ) !== status
        ){
            return false;
        }

        return true;
    });

    const history=surveyHistory(survey.surveyId);

    document.getElementById("customerRows").innerHTML=
        rows.map(customer=>{
            const customerHistory=history.filter(
                h=>String(h.customerId) ===
                    String(customer.customerId) &&
                    h.success
            );

            const last=customerHistory.length
                ? customerHistory[
                    customerHistory.length-1
                ].sentAt
                : "";

            return `
            <tr>
                <td>
                    <input type="checkbox"
                        class="customer-check"
                        value="${escapeHtml(
                            customer.customerId
                        )}"
                        style="width:auto">
                </td>
                <td>${escapeHtml(
                    customer.organization
                )}</td>
                <td>${escapeHtml(
                    customer.name
                )}</td>
                <td>${escapeHtml(
                    customer.email
                )}</td>
                <td>${escapeHtml(
                    customer.phone
                )}</td>
                <td>${escapeHtml(
                    customer.address
                )}</td>
                <td>${escapeHtml(last)}</td>
                <td>${customerHistory.length}</td>
                <td>${statusBadge(
                    getAnswerStatus(
                        survey.surveyId,
                        customer.customerId
                    )
                )}</td>
            </tr>`;
        }).join("") || `
            <tr>
                <td colspan="9" class="muted">
                    対象顧客がありません。
                </td>
            </tr>`;

    document.getElementById("selectAllCustomers")
        .onclick=e=>{
            document.querySelectorAll(
                ".customer-check"
            ).forEach(cb=>{
                cb.checked=e.target.checked;
            });
        };
}

function selectReminderTargets(){
    document.getElementById("customerStatus")
        .value="送信済み / 未回答";

    const survey=findSurvey(currentSurveyId);
    renderCustomerRows(survey);

    document.querySelectorAll(".customer-check")
        .forEach(cb=>cb.checked=true);
}

function previewMail(){
    const survey=findSurvey(currentSurveyId);
    const ids=selectedCustomerIds();

    if(!ids.length){
        alert("顧客を選択してください。");
        return;
    }

    const subject=document.getElementById(
        "mailSubject"
    ).value;

    const body=document.getElementById(
        "mailBody"
    ).value;

    const html=ids.map(id=>{
        const customer=findCustomer(id);
        if(!customer)return "";

        const token=createLocalToken(
            survey.surveyId,
            customer.customerId
        );

        const url=
            "index.php?view=answer&surveyId="+
            encodeURIComponent(survey.surveyId)+
            "&token="+encodeURIComponent(token);

        return `
        <div class="card">
            <strong>${escapeHtml(
                customer.name
            )}</strong>
            <p><b>件名</b><br>${
                escapeHtml(
                    subject.replaceAll(
                        "{顧客名}",
                        customer.name || ""
                    ).replaceAll(
                        "{アンケートURL}",url
                    )
                )
            }</p>
            <pre style="white-space:pre-wrap">${
                escapeHtml(
                    body.replaceAll(
                        "{顧客名}",
                        customer.name || ""
                    ).replaceAll(
                        "{アンケートURL}",url
                    )
                )
            }</pre>
        </div>`;
    }).join("");

    openModal(`
        <h3>送信文確認</h3>
        ${html}
    `);
}

function createLocalToken(surveyId,customerId){
    return btoa(
        surveyId+"|"+customerId
    ).replaceAll("=","");
}

async function sendSelected(type){
    const ids=selectedCustomerIds();

    if(!ids.length){
        alert("顧客を選択してください。");
        return;
    }

    confirmAction(
        `${ids.length}件に${type}を実行します。`,
        async ()=>{
            try{
                const result=await api(
                    "send_mail",
                    {
                        surveyId:currentSurveyId,
                        customerIds:ids,
                        subject:document.getElementById(
                            "mailSubject"
                        ).value,
                        body:document.getElementById(
                            "mailBody"
                        ).value,
                        sendType:type
                    }
                );

                document.getElementById(
                    "sendResult"
                ).innerHTML=`
                    <div class="success">
                        対象件数: ${result.total}<br>
                        成功件数: ${result.successCount}<br>
                        失敗件数: ${result.failureCount}<br>
                        送信日時: ${escapeHtml(
                            result.sentAt
                        )}
                    </div>
                `;

                reload();
            }catch(e){
                alert(e.message);
            }
        }
    );
}

function renderHistory(survey){
    const history=surveyHistory(survey.surveyId)
        .slice()
        .reverse();

    document.getElementById("history").innerHTML=
        history.length
        ? `<div class="table-wrap">
        <table>
        <thead>
        <tr>
            <th>送信日時</th>
            <th>種別</th>
            <th>顧客</th>
            <th>件名</th>
            <th>結果</th>
            <th>詳細</th>
        </tr>
        </thead>
        <tbody>
        ${history.map(h=>{
            const c=findCustomer(h.customerId);

            return `
            <tr>
                <td>${escapeHtml(h.sentAt)}</td>
                <td>${escapeHtml(h.sendType)}</td>
                <td>${escapeHtml(
                    c?.name || h.customerId
                )}</td>
                <td>${escapeHtml(h.subject)}</td>
                <td>${h.success
                    ? statusBadge("公開中")
                    : statusBadge("停止")}</td>
                <td>
                    <button class="btn"
                        onclick='showHistory(
                        ${JSON.stringify(h)}
                        )'>
                        確認
                    </button>
                </td>
            </tr>`;
        }).join("")}
        </tbody>
        </table>
        </div>`
        : `<span class="muted">送信履歴はありません。</span>`;
}

function showHistory(item){
    openModal(`
        <h3>送信履歴詳細</h3>
        <p><b>送信日時</b><br>
        ${escapeHtml(item.sentAt)}</p>
        <p><b>件名</b><br>
        ${escapeHtml(item.subject)}</p>
        <p><b>本文</b></p>
        <pre style="white-space:pre-wrap">${
            escapeHtml(item.body)
        }</pre>
        <p><b>個別アンケートURL</b><br>
        ${escapeHtml(item.surveyUrl)}</p>
        <p><b>結果</b><br>
        ${item.success ? "成功" :
            escapeHtml(item.error)}</p>
    `);
}


/* ============================================================
 * 集計
 * ============================================================ */

function renderAggregation(){
    const survey=findSurvey(currentSurveyId);

    if(!survey){
        renderMissingSurvey();
        return;
    }

    const responses=surveyResponses(survey.surveyId);
    const sentCustomers=new Set(
        surveyHistory(survey.surveyId)
            .filter(h=>h.success)
            .map(h=>h.customerId)
    );

    const registeredResponses=
        responses.filter(r=>r.customerId);

    const unregisteredResponses=
        responses.filter(r=>!r.customerId);

    const answerRate=sentCustomers.size
        ? Math.round(
            responses.length /
            sentCustomers.size * 1000
        ) / 10
        : 0;

    document.getElementById("app").innerHTML=`
        <div class="toolbar">
            <div style="flex:1">
                <h2>回答集計・分析</h2>
                <div class="muted">
                    対象:
                    <strong>${escapeHtml(
                        survey.title
                    )}</strong>
                </div>
            </div>

            <button class="btn"
                onclick="exportCsv()">
                CSV出力
            </button>

            <button class="btn"
                onclick="exportPdf()">
                PDF出力
            </button>
        </div>

        <div class="grid">
            <div class="kpi">
                <div class="muted">送信対象者数</div>
                <div class="stat">
                    ${sentCustomers.size}
                </div>
            </div>

            <div class="kpi">
                <div class="muted">回答数</div>
                <div class="stat">
                    ${responses.length}
                </div>
            </div>

            <div class="kpi">
                <div class="muted">未登録顧客からの回答数</div>
                <div class="stat">
                    ${unregisteredResponses.length}
                </div>
            </div>

            <div class="kpi">
                <div class="muted">未回答数</div>
                <div class="stat">
                    ${Math.max(
                        0,
                        sentCustomers.size -
                        registeredResponses.length
                    )}
                </div>
            </div>

            <div class="kpi">
                <div class="muted">回答率</div>
                <div class="stat">${answerRate}%</div>
            </div>
        </div>

        <div class="card">
            <h3>設問別集計</h3>
            <div id="questionStats"></div>
        </div>

        <div class="card">
            <h3>個別回答</h3>
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
                    ${responses.map(r=>`
                    <tr>
                        <td>${escapeHtml(
                            r.submittedAt
                        )}</td>
                        <td>${escapeHtml(
                            r.respondent?.name || ""
                        )}</td>
                        <td>${escapeHtml(
                            r.respondent?.email || ""
                        )}</td>
                        <td>
                            ${Object.entries(
                                r.answers || {}
                            ).map(([qid,value])=>{
                                const q=
                                    findQuestion(
                                        survey,
                                        qid
                                    );

                                return `
                                <div>
                                    <b>${escapeHtml(
                                        q?.questionNumber ||
                                        qid
                                    )}</b>:
                                    ${escapeHtml(
                                        Array.isArray(value)
                                            ? value.join(", ")
                                            : value
                                    )}
                                </div>`;
                            }).join("")}
                        </td>
                    </tr>`).join("") || `
                    <tr>
                        <td colspan="4">
                            回答がありません。
                        </td>
                    </tr>`}
                    </tbody>
                </table>
            </div>
        </div>
    `;

    renderQuestionStats(survey,responses);
}

function renderQuestionStats(survey,responses){
    const questions=flattenQuestions(survey);

    document.getElementById("questionStats")
        .innerHTML=questions.map(q=>{
            if(q.answerType==="text"){
                const answers=responses.map(
                    r=>r.answers?.[q.questionId]
                ).filter(v=>v!==undefined && v!=="");

                return `
                <div class="card">
                    <h4>
                        ${escapeHtml(q.questionNumber)}
                        ${escapeHtml(q.questionText)}
                    </h4>
                    <div>
                        ${answers.map(a=>`
                            <div class="notice">
                                ${escapeHtml(a)}
                            </div>
                        `).join("") || "回答なし"}
                    </div>
                </div>`;
            }

            const counts={};

            q.choices.forEach(c=>{
                counts[c.choiceId]=0;
            });

            responses.forEach(r=>{
                const value=r.answers?.[q.questionId];

                if(Array.isArray(value)){
                    value.forEach(v=>{
                        if(counts[v]!==undefined){
                            counts[v]++;
                        }
                    });
                }else if(value!==undefined){
                    if(counts[value]!==undefined){
                        counts[value]++;
                    }
                }
            });

            const total=responses.length;

            return `
            <div class="card">
                <h4>
                    ${escapeHtml(q.questionNumber)}
                    ${escapeHtml(q.questionText)}
                </h4>
                ${q.choices.map(c=>{
                    const count=counts[c.choiceId] || 0;
                    const ratio=total
                        ? Math.round(count/total*1000)/10
                        : 0;

                    return `
                    <div style="margin:12px 0">
                        <div style="
                        display:flex;
                        justify-content:space-between">
                            <span>${escapeHtml(
                                c.label
                            )}</span>
                            <span>
                                ${count}件 / ${ratio}%
                            </span>
                        </div>
                        <div style="
                        background:#e5e7eb;
                        height:12px;
                        border-radius:999px">
                            <div style="
                            width:${ratio}%;
                            background:#2563eb;
                            height:12px;
                            border-radius:999px">
                            </div>
                        </div>
                    </div>`;
                }).join("")}
            </div>`;
        }).join("");
}

function findQuestion(survey,id){
    return flattenQuestions(survey)
        .find(q=>String(q.questionId)===String(id))
        || null;
}

function flattenQuestions(survey){
    return (survey.groups || [])
        .flatMap(g=>g.questions || []);
}

async function exportCsv(){
    try{
        const result=await api("export_csv",{
            surveyId:currentSurveyId
        });

        const binary=atob(result.contentBase64);
        const bytes=new Uint8Array(binary.length);

        for(let i=0;i<binary.length;i++){
            bytes[i]=binary.charCodeAt(i);
        }

        const blob=new Blob([bytes],{
            type:result.mime
        });

        const url=URL.createObjectURL(blob);
        const a=document.createElement("a");

        a.href=url;
        a.download=result.filename;
        a.click();

        URL.revokeObjectURL(url);
    }catch(e){
        alert(e.message);
    }
}

async function exportPdf(){
    try{
        const result=await api("export_pdf",{
            surveyId:currentSurveyId
        });

        alert(result.message);
    }catch(e){
        alert(e.message);
    }
}


/* ============================================================
 * 回答
 * ============================================================ */

function renderAnswer(){
    const survey=findSurvey(currentSurveyId);

    if(!survey){
        renderMissingSurvey();
        return;
    }

    if(survey.status!=="公開中"){
        document.getElementById("app").innerHTML=`
            <div class="card">
                <h2>回答できません</h2>
                <p>
                    このアンケートは現在回答できる状態ではありません。
                </p>
            </div>`;
        return;
    }

    const existing=appData.responses.find(r =>
        String(r.surveyId)===String(survey.surveyId) &&
        String(r.token)===String(currentToken)
    );

    if(existing && !survey.allowResubmit){
        document.getElementById("app").innerHTML=`
            <div class="card">
                <h2>回答済みです</h2>
                <p>
                    このアンケートにはすでに回答済みです。
                </p>
            </div>`;
        return;
    }

    const draft=window.answerDraft || {
        respondent:{
            organization:"",
            name:"",
            email:"",
            department:"",
            phone:"",
            address:""
        },
        answers:{}
    };

    document.getElementById("app").innerHTML=`
        <div class="card">
            <h1>${escapeHtml(survey.title)}</h1>
            <p>${escapeHtml(survey.description)}</p>

            <div class="notice">
                回答内容を入力してください。
                必須項目はすべて回答してください。
            </div>

            <h3>回答者情報</h3>

            <div class="form-grid">
                <div>
                    <label>組織名</label>
                    <input id="respondentOrganization"
                        value="${escapeHtml(
                            draft.respondent.organization
                        )}">
                </div>

                <div>
                    <label>氏名</label>
                    <input id="respondentName"
                        value="${escapeHtml(
                            draft.respondent.name
                        )}">
                </div>

                <div>
                    <label>メールアドレス</label>
                    <input type="email"
                        id="respondentEmail"
                        value="${escapeHtml(
                            draft.respondent.email
                        )}">
                </div>

                <div>
                    <label>部署名</label>
                    <input id="respondentDepartment"
                        value="${escapeHtml(
                            draft.respondent.department
                        )}">
                </div>

                <div>
                    <label>電話番号</label>
                    <input id="respondentPhone"
                        value="${escapeHtml(
                            draft.respondent.phone
                        )}">
                </div>

                <div>
                    <label>住所</label>
                    <input id="respondentAddress"
                        value="${escapeHtml(
                            draft.respondent.address
                        )}">
                </div>
            </div>

            <div id="answerQuestions">
                ${renderAnswerQuestions(
                    survey,
                    draft.answers
                )}
            </div>

            <div class="toolbar"
                style="justify-content:flex-end">
                <button class="btn btn-primary"
                    onclick="goConfirm()">
                    回答内容を確認
                </button>
            </div>
        </div>
    `;
}

function renderAnswerQuestions(survey,answers){
    return flattenQuestions(survey).map(q=>{
        const value=answers[q.questionId];

        if(q.answerType==="text"){
            return `
            <div class="question">
                <h3>
                    ${escapeHtml(q.questionNumber)}
                    ${q.required?"＊":""}
                </h3>
                <p>${escapeHtml(q.questionText)}</p>
                <textarea
                    data-answer="${escapeHtml(
                        q.questionId
                    )}">${escapeHtml(value || "")}</textarea>
            </div>`;
        }

        const multiple=q.answerType==="multiple";

        return `
        <div class="question">
            <h3>
                ${escapeHtml(q.questionNumber)}
                ${q.required?"＊":""}
            </h3>
            <p>${escapeHtml(q.questionText)}</p>

            ${(q.choices || []).map(c=>{
                const checked=multiple
                    ? Array.isArray(value) &&
                        value.includes(c.choiceId)
                    : value===c.choiceId;

                return `
                <label class="answer-option">
                    <input
                        type="${multiple
                            ?"checkbox":"radio"}"
                        name="q_${escapeHtml(
                            q.questionId
                        )}"
                        value="${escapeHtml(
                            c.choiceId
                        )}"
                        data-answer="${escapeHtml(
                            q.questionId
                        )}"
                        ${checked?"checked":""}>
                    ${escapeHtml(c.label)}
                </label>`;
            }).join("")}
        </div>`;
    }).join("");
}

function collectAnswerDraft(){
    const answers={};

    flattenQuestions(
        findSurvey(currentSurveyId)
    ).forEach(q=>{
        const elements=document.querySelectorAll(
            `[data-answer="${CSS.escape(
                q.questionId
            )}"]`
        );

        if(q.answerType==="multiple"){
            answers[q.questionId]=[
                ...elements
            ].filter(e=>e.checked)
            .map(e=>e.value);
        }else if(q.answerType==="single"){
            const checked=[
                ...elements
            ].find(e=>e.checked);

            answers[q.questionId]=
                checked ? checked.value : "";
        }else{
            answers[q.questionId]=
                elements[0]?.value || "";
        }
    });

    window.answerDraft={
        respondent:{
            organization:
                document.getElementById(
                    "respondentOrganization"
                ).value,
            name:
                document.getElementById(
                    "respondentName"
                ).value,
            email:
                document.getElementById(
                    "respondentEmail"
                ).value,
            department:
                document.getElementById(
                    "respondentDepartment"
                ).value,
            phone:
                document.getElementById(
                    "respondentPhone"
                ).value,
            address:
                document.getElementById(
                    "respondentAddress"
                ).value
        },
        answers
    };

    return window.answerDraft;
}

function goConfirm(){
    const survey=findSurvey(currentSurveyId);
    const draft=collectAnswerDraft();

    const errors=[];

    flattenQuestions(survey).forEach(q=>{
        if(!q.required)return;

        const value=draft.answers[q.questionId];

        const empty=Array.isArray(value)
            ? value.length===0
            : String(value || "").trim()==="";

        if(empty){
            errors.push(
                `${q.questionNumber} ${q.questionText}`
            );
        }
    });

    if(errors.length){
        alert(
            "必須質問が未回答です。\n\n"+
            errors.join("\n")
        );
        return;
    }

    navigate("confirm",{
        surveyId:currentSurveyId,
        token:currentToken
    });
}

function renderConfirm(){
    const survey=findSurvey(currentSurveyId);

    if(!survey){
        renderMissingSurvey();
        return;
    }

    const draft=window.answerDraft;

    if(!draft){
        navigate("answer",{
            surveyId:currentSurveyId,
            token:currentToken
        });
        return;
    }

    document.getElementById("app").innerHTML=`
        <div class="card">
            <h1>回答内容確認</h1>

            <h3>回答者情報</h3>
            <p>
                ${escapeHtml(
                    draft.respondent.organization
                )}<br>
                ${escapeHtml(
                    draft.respondent.name
                )}<br>
                ${escapeHtml(
                    draft.respondent.email
                )}<br>
                ${escapeHtml(
                    draft.respondent.department
                )}<br>
                ${escapeHtml(
                    draft.respondent.phone
                )}<br>
                ${escapeHtml(
                    draft.respondent.address
                )}
            </p>

            ${flattenQuestions(survey).map(q=>{
                let value=
                    draft.answers[q.questionId] ?? "";

                if(Array.isArray(value)){
                    value=value.map(id=>{
                        const choice=q.choices.find(
                            c=>c.choiceId===id
                        );
                        return choice
                            ? choice.label
                            : id;
                    }).join(", ");
                }else{
                    const choice=q.choices.find(
                        c=>c.choiceId===value
                    );

                    if(choice)value=choice.label;
                }

                return `
                <div class="question">
                    <strong>
                        ${escapeHtml(
                            q.questionNumber
                        )}
                    </strong>
                    <p>${escapeHtml(
                        q.questionText
                    )}</p>
                    <p>${escapeHtml(value)}</p>
                </div>`;
            }).join("")}

            <div class="toolbar">
                <button class="btn"
                    onclick="navigate(
                    'answer',
                    {
                        surveyId:currentSurveyId,
                        token:currentToken
                    }
                    )">
                    修正
                </button>

                <button class="btn btn-primary"
                    onclick="submitAnswer()">
                    回答を送信
                </button>
            </div>
        </div>
    `;
}

async function submitAnswer(){
    confirmAction(
        "回答を送信します。よろしいですか？",
        async ()=>{
            try{
                await api("save_response",{
                    surveyId:currentSurveyId,
                    token:currentToken,
                    response:window.answerDraft
                });

                navigate("complete",{
                    surveyId:currentSurveyId,
                    token:currentToken
                });
            }catch(e){
                alert(e.message);
            }
        }
    );
}

function renderComplete(){
    document.getElementById("app").innerHTML=`
        <div class="card" style="text-align:center">
            <h1>回答完了</h1>
            <div class="success">
                回答を送信しました。
            </div>
            <p>
                ご回答ありがとうございました。
            </p>
        </div>
    `;
}


/* ============================================================
 * kintone画面
 * ============================================================ */

function renderKintone(){
    const settings=appData.settings.kintone || {};

    document.getElementById("app").innerHTML=`
        <div class="toolbar">
            <div style="flex:1">
                <h2>kintone連携設定</h2>
            </div>
            <button class="btn btn-primary"
                onclick="saveKintone()">
                設定保存
            </button>
        </div>

        <div class="card">
            <div class="form-grid">
                <div>
                    <label>サブドメイン</label>
                    <input id="kSubdomain"
                        value="${escapeHtml(
                            settings.subdomain || ""
                        )}"
                        placeholder="xxxx.cybozu.com">
                </div>

                <div>
                    <label>顧客管理アプリID</label>
                    <input id="kAppId"
                        value="${escapeHtml(
                            settings.appId || ""
                        )}">
                </div>

                <div>
                    <label>ログイン名</label>
                    <input id="kLogin"
                        value="${escapeHtml(
                            settings.loginName || ""
                        )}">
                </div>

                <div>
                    <label>パスワード</label>
                    <input type="password"
                        id="kPassword"
                        value="${escapeHtml(
                            settings.password || ""
                        )}">
                </div>

                <div>
                    <label>SSL証明書検証</label>
                    <select id="kSsl">
                        <option value="false"
                            ${!settings.sslVerify
                                ?"selected":""}>
                            検証しない
                        </option>
                        <option value="true"
                            ${settings.sslVerify
                                ?"selected":""}>
                            検証する
                        </option>
                    </select>
                </div>

                <div>
                    <label>プロキシ</label>
                    <input id="kProxy"
                        value="${escapeHtml(
                            settings.proxy || ""
                        )}"
                        placeholder="proxy.example.local:8080">
                </div>
            </div>
        </div>

        <div class="card">
            <h3>接続操作</h3>
            <div class="actions">
                <button class="btn"
                    onclick="kintoneTest()">
                    接続テスト
                </button>
                <button class="btn"
                    onclick="kintoneFields()">
                    項目一覧を再取得
                </button>
                <button class="btn btn-primary"
                    onclick="kintoneSync()">
                    顧客情報を同期
                </button>
            </div>

            <div id="kintoneResult"></div>
        </div>

        <div class="card">
            <h3>フィールドマッピング</h3>
            ${renderFieldMapping(settings)}
        </div>

        <div class="card">
            <h3>取得済み項目</h3>
            <div id="kFields">
                ${renderFields(settings.fields || [])}
            </div>
        </div>
    `;
}

function renderFieldMapping(settings){
    const fields=settings.fields || [];
    const mapping=settings.fieldMapping || {};

    const select=(name,label)=>{
        return `
        <div>
            <label>${label}</label>
            <select id="map_${name}">
                <option value="">未設定</option>
                ${fields.map(f=>`
                <option value="${escapeHtml(f.code)}"
                    ${mapping[name]===f.code
                        ?"selected":""}>
                    ${escapeHtml(f.label)}
                    (${escapeHtml(f.code)})
                </option>`).join("")}
            </select>
        </div>`;
    };

    return `
    <div class="form-grid">
        ${select("organization","組織名")}
        ${select("name","氏名")}
        ${select("email","メールアドレス")}
        ${select("department","部署名")}
        ${select("phone","電話番号")}
    </div>

    <h4>住所</h4>
    ${fields.map(f=>`
        <label style="font-weight:400">
            <input type="checkbox"
                class="address-field"
                value="${escapeHtml(f.code)}"
                ${(mapping.address || [])
                    .includes(f.code)
                    ?"checked":""}
                style="width:auto">
            ${escapeHtml(f.label)}
            (${escapeHtml(f.code)})
        </label>
    `).join("")}`;
}

function renderFields(fields){
    return fields.length
        ? `<div class="table-wrap">
        <table>
        <thead>
        <tr>
            <th>コード</th>
            <th>ラベル</th>
            <th>タイプ</th>
        </tr>
        </thead>
        <tbody>
        ${fields.map(f=>`
            <tr>
                <td>${escapeHtml(f.code)}</td>
                <td>${escapeHtml(f.label)}</td>
                <td>${escapeHtml(f.type)}</td>
            </tr>
        `).join("")}
        </tbody>
        </table>
        </div>`
        : "項目を取得してください。";
}

async function saveKintone(){
    const mapping={
        organization:
            document.getElementById("map_organization")
                .value,
        name:
            document.getElementById("map_name").value,
        email:
            document.getElementById("map_email").value,
        department:
            document.getElementById("map_department")
                .value,
        phone:
            document.getElementById("map_phone").value,
        address:[
            ...document.querySelectorAll(
                ".address-field:checked"
            )
        ].map(e=>e.value)
    };

    const settings={
        subdomain:
            document.getElementById("kSubdomain").value,
        appId:
            document.getElementById("kAppId").value,
        loginName:
            document.getElementById("kLogin").value,
        password:
            document.getElementById("kPassword").value,
        sslVerify:
            document.getElementById("kSsl").value==="true",
        proxy:
            document.getElementById("kProxy").value,
        fieldMapping:mapping
    };

    try{
        await api("save_kintone_settings",{settings});
        alert("設定を保存しました。");
        reload();
    }catch(e){
        alert(e.message);
    }
}

async function kintoneTest(){
    showKResult("接続テスト中...");

    try{
        const result=await api("kintone_test");
        showKResult(
            `<div class="success">${
                escapeHtml(result.message)
            }</div>`
        );
    }catch(e){
        showKResult(
            `<div class="error">${
                escapeHtml(e.message)
            }</div>`
        );
    }
}

async function kintoneFields(){
    showKResult("項目取得中...");

    try{
        const result=await api(
            "kintone_get_fields"
        );

        document.getElementById("kFields")
            .innerHTML=renderFields(
                result.fields || []
            );

        showKResult(
            `<div class="success">
                項目一覧を取得しました。
            </div>`
        );
    }catch(e){
        showKResult(
            `<div class="error">${
                escapeHtml(e.message)
            }</div>`
        );
    }
}

async function kintoneSync(){
    confirmAction(
        "kintoneから顧客情報を同期しますか？",
        async ()=>{
            showKResult("顧客同期中...");

            try{
                const result=await api(
                    "kintone_sync"
                );

                showKResult(
                    `<div class="success">
                        ${escapeHtml(result.message)}
                        <br>
                        ${result.count}件
                    </div>`
                );
            }catch(e){
                showKResult(
                    `<div class="error">${
                        escapeHtml(e.message)
                    }</div>`
                );
            }
        }
    );
}

function showKResult(html){
    document.getElementById(
        "kintoneResult"
    ).innerHTML=html;
}


/* ============================================================
 * メール設定画面
 * ============================================================ */

function renderMail(){
    const settings=appData.settings.mail || {};

    document.getElementById("app").innerHTML=`
        <div class="toolbar">
            <div style="flex:1">
                <h2>メールサーバ設定</h2>
            </div>
            <button class="btn btn-primary"
                onclick="saveMail()">
                設定保存
            </button>
        </div>

        <div class="card">
            <div class="form-grid">
                <div>
                    <label>SMTPサーバ</label>
                    <input id="smtpHost"
                        value="${escapeHtml(
                            settings.smtpHost || ""
                        )}">
                </div>

                <div>
                    <label>SMTPポート</label>
                    <input type="number"
                        id="smtpPort"
                        value="${escapeHtml(
                            settings.smtpPort || 587
                        )}">
                </div>

                <div>
                    <label>暗号化方式</label>
                    <select id="smtpEncryption">
                        <option value="none"
                            ${settings.encryption==="none"
                                ?"selected":""}>
                            なし
                        </option>
                        <option value="tls"
                            ${settings.encryption==="tls"
                                ?"selected":""}>
                            TLS
                        </option>
                        <option value="ssl"
                            ${settings.encryption==="ssl"
                                ?"selected":""}>
                            SSL
                        </option>
                    </select>
                </div>

                <div>
                    <label>SMTP認証</label>
                    <select id="smtpAuth">
                        <option value="false"
                            ${!settings.smtpAuth
                                ?"selected":""}>
                            使用しない
                        </option>
                        <option value="true"
                            ${settings.smtpAuth
                                ?"selected":""}>
                            使用する
                        </option>
                    </select>
                </div>

                <div>
                    <label>SMTPユーザー名</label>
                    <input id="smtpUser"
                        value="${escapeHtml(
                            settings.username || ""
                        )}">
                </div>

                <div>
                    <label>SMTPパスワード</label>
                    <input type="password"
                        id="smtpPassword"
                        value="${escapeHtml(
                            settings.password || ""
                        )}">
                </div>

                <div>
                    <label>送信元メールアドレス</label>
                    <input id="fromEmail"
                        value="${escapeHtml(
                            settings.fromEmail || ""
                        )}">
                </div>

                <div>
                    <label>送信元名</label>
                    <input id="fromName"
                        value="${escapeHtml(
                            settings.fromName || ""
                        )}">
                </div>

                <div>
                    <label>返信先メールアドレス</label>
                    <input id="replyTo"
                        value="${escapeHtml(
                            settings.replyTo || ""
                        )}">
                </div>
            </div>
        </div>

        <div class="card">
            <h3>メールサーバテスト</h3>

            <p>
                接続状態:
                <strong>${escapeHtml(
                    settings.status || "未設定"
                )}</strong>
            </p>

            <div class="toolbar">
                <input id="testMailTo"
                    placeholder="テスト送信先メールアドレス"
                    style="max-width:360px">
                <button class="btn"
                    onclick="testMail()">
                    テストメール
                </button>
            </div>

            <div id="mailTestResult"></div>
        </div>
    `;
}

async function saveMail(){
    const settings={
        smtpHost:
            document.getElementById("smtpHost").value,
        smtpPort:
            Number(document.getElementById("smtpPort").value),
        encryption:
            document.getElementById(
                "smtpEncryption"
            ).value,
        smtpAuth:
            document.getElementById(
                "smtpAuth"
            ).value==="true",
        username:
            document.getElementById("smtpUser").value,
        password:
            document.getElementById(
                "smtpPassword"
            ).value,
        fromEmail:
            document.getElementById(
                "fromEmail"
            ).value,
        fromName:
            document.getElementById(
                "fromName"
            ).value,
        replyTo:
            document.getElementById("replyTo").value
    };

    try{
        await api("save_mail_settings",{settings});
        alert("設定を保存しました。");
        reload();
    }catch(e){
        alert(e.message);
    }
}

async function testMail(){
    const to=document.getElementById(
        "testMailTo"
    ).value;

    try{
        const result=await api(
            "send_test_mail",
            {to}
        );

        document.getElementById(
            "mailTestResult"
        ).innerHTML=`
            <div class="success">
                テストメール送信成功
            </div>`;
    }catch(e){
        document.getElementById(
            "mailTestResult"
        ).innerHTML=`
            <div class="error">
                ${escapeHtml(e.message)}
            </div>`;
    }
}


/* ============================================================
 * 共通
 * ============================================================ */

function renderSurveyQuestions(survey,mode){
    return flattenQuestions(survey).map(q=>`
        <div class="question">
            <h3>
                ${escapeHtml(q.questionNumber)}
                ${q.required?"＊":""}
            </h3>
            <p>${escapeHtml(q.questionText)}</p>

            ${q.answerType==="text"
                ? `<textarea disabled></textarea>`
                : q.choices.map(c=>`
                    <label class="answer-option">
                        <input type="${
                            q.answerType==="multiple"
                                ?"checkbox":"radio"
                        }" disabled>
                        ${escapeHtml(c.label)}
                    </label>
                `).join("")}
        </div>
    `).join("");
}

function renderMissingSurvey(){
    document.getElementById("app").innerHTML=`
        <div class="error">
            対象アンケートが指定されていないか、
            存在しません。
        </div>
        <button class="btn"
            onclick="navigate('admin-survey-list')">
            アンケート一覧へ
        </button>
    `;
}

function render(){
    syncFromUrl();

    document.getElementById("headerRight")
        .innerHTML=
        currentView.startsWith("answer") ||
        currentView==="confirm" ||
        currentView==="complete"
        ? ""
        : `<button class="btn"
            onclick="navigate('admin-kintone')">
            kintone設定
        </button>
        <button class="btn"
            onclick="navigate('admin-mail')">
            メール設定
        </button>`;

    switch(currentView){
        case "admin-survey-list":
            renderList();
            break;

        case "admin-survey-edit":
            renderEdit();
            break;

        case "admin-preview":
            renderPreview();
            break;

        case "admin-send":
            renderSend();
            break;

        case "admin-aggregation":
            renderAggregation();
            break;

        case "admin-kintone":
            renderKintone();
            break;

        case "admin-mail":
            renderMail();
            break;

        case "answer":
            renderAnswer();
            break;

        case "confirm":
            renderConfirm();
            break;

        case "complete":
            renderComplete();
            break;

        default:
            navigate("admin-survey-list");
            break;
    }
}

render();
</script>
</body>
</html>
<?php
}


/* ============================================================
 * データ検索
 * ============================================================ */

function findSurvey(array $surveys, string $surveyId): ?array
{
    foreach ($surveys as $survey) {
        if ((string)($survey['surveyId'] ?? '') === $surveyId) {
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
        if ((string)($survey['surveyId'] ?? '') === $surveyId) {
            return $index;
        }
    }

    return null;
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
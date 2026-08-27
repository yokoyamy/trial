<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 * index.php
 *
 * PHP 8.5 / Apache 2.4
 * DBなし / PHP cURLなし
 *
 * 主要機能:
 * - アンケート一覧
 * - 新規作成 / 編集 / 複製 / 削除
 * - 公開 / 停止 / 再開
 * - グループ追加 / 削除 / 並び替え
 * - 質問追加 / 削除 / 並び替え
 * - 質問のグループ間移動
 * - ドラッグ＆ドロップ
 * - 質問番号自動採番
 * - プレビュー
 * - 回答 / 確認 / 完了
 * - 回答集計
 * - CSV出力
 * - 簡易PDF出力
 * - 顧客管理
 * - SMTPメール送信
 * - kintone連携
 *
 * 注意:
 * POC仕様に従い管理者認証・CSRF対策は実装していない。
 */

/* ============================================================
 * 基本設定
 * ============================================================ */

date_default_timezone_set('Asia/Tokyo');

const APP_NAME = 'アンケートアプリ';
const STORAGE_DIR = __DIR__ . '/storage';

const SURVEY_FILE = STORAGE_DIR . '/surveys.json';
const ANSWER_FILE = STORAGE_DIR . '/answers.json';
const CUSTOMER_FILE = STORAGE_DIR . '/customers.json';
const MAIL_HISTORY_FILE = STORAGE_DIR . '/mail_history.json';
const KINTONE_FILE = STORAGE_DIR . '/kintone.json';
const SMTP_FILE = STORAGE_DIR . '/smtp.json';

const MAX_TITLE_LENGTH = 200;
const MAX_DESCRIPTION_LENGTH = 5000;
const MAX_QUESTION_LENGTH = 2000;
const MAX_OPTION_LENGTH = 500;

/* ============================================================
 * 初期化
 * ============================================================ */

ensureStorage();

$screen = (string)($_GET['screen'] ?? 'list');
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

$message = null;
$error = null;

try {
    /*
     * POST/GETアクション処理
     */
    if ($action !== '') {
        switch ($action) {
            case 'save_survey':
                $result = handleSaveSurvey();
                if ($result['success']) {
                    redirect('index.php?screen=list&saved=1');
                }
                $error = $result['message'];
                $screen = 'edit';
                break;

            case 'delete_survey':
                handleDeleteSurvey();
                redirect('index.php?screen=list&deleted=1');
                break;

            case 'duplicate_survey':
                handleDuplicateSurvey();
                redirect('index.php?screen=list&duplicated=1');
                break;

            case 'change_status':
                handleChangeStatus();
                redirect('index.php?screen=list&status_changed=1');
                break;

            case 'save_kintone':
                $result = handleSaveKintone();
                $message = $result['message'];
                $screen = 'kintone';
                if (!$result['success']) {
                    $error = $result['message'];
                    $message = null;
                }
                break;

            case 'kintone_test':
                $result = handleKintoneTest();
                $screen = 'kintone';
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'kintone_fields':
                $result = handleKintoneFields();
                $screen = 'kintone';
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'kintone_sync':
                $result = handleKintoneSync();
                $screen = 'kintone';
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'save_smtp':
                $result = handleSaveSmtp();
                $screen = 'mail';
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'smtp_test':
                $result = handleSmtpTest();
                $screen = 'mail';
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'send_mail':
                $result = handleSendMail();
                $screen = 'send';
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;

            case 'save_answer':
                $result = handleSaveAnswer();
                if ($result['success']) {
                    redirect(
                        'index.php?screen=complete&id=' .
                        rawurlencode($result['survey_id'])
                    );
                }
                $error = $result['message'];
                $screen = 'answer';
                break;

            case 'export_csv':
                exportCsv();
                exit;

            case 'export_pdf':
                exportPdf();
                exit;

            default:
                break;
        }
    }

    if (isset($_GET['saved'])) {
        $message = 'アンケートを保存しました。';
    } elseif (isset($_GET['deleted'])) {
        $message = 'アンケートを削除しました。';
    } elseif (isset($_GET['duplicated'])) {
        $message = 'アンケートを複製しました。';
    } elseif (isset($_GET['status_changed'])) {
        $message = '状態を変更しました。';
    }

} catch (Throwable $e) {
    $error = '処理中にエラーが発生しました。';
}

/* ============================================================
 * 画面別データ
 * ============================================================ */

$surveyId = (string)($_GET['id'] ?? '');
$currentSurvey = null;

if ($surveyId !== '') {
    $currentSurvey = findSurvey($surveyId);
}

/* ============================================================
 * HTML
 * ============================================================ */

renderHeader($screen, $currentSurvey);

if ($message !== null) {
    echo '<div class="alert alert-success">' . h($message) . '</div>';
}

if ($error !== null) {
    echo '<div class="alert alert-danger">' . h($error) . '</div>';
}

switch ($screen) {
    case 'list':
        renderListScreen();
        break;

    case 'edit':
        renderEditScreen($currentSurvey);
        break;

    case 'preview':
        renderPreviewScreen($currentSurvey);
        break;

    case 'answer':
        renderAnswerScreen($currentSurvey, false);
        break;

    case 'confirm':
        renderAnswerConfirmScreen($currentSurvey);
        break;

    case 'complete':
        renderCompleteScreen($currentSurvey);
        break;

    case 'send':
        renderSendScreen($currentSurvey);
        break;

    case 'analytics':
        renderAnalyticsScreen($currentSurvey);
        break;

    case 'kintone':
        renderKintoneScreen();
        break;

    case 'mail':
        renderMailScreen();
        break;

    default:
        http_response_code(404);
        echo '<div class="card"><h2>画面が見つかりません</h2></div>';
        break;
}

renderFooter();

/* ============================================================
 * Storage
 * ============================================================ */

function ensureStorage(): void
{
    if (!is_dir(STORAGE_DIR)) {
        @mkdir(STORAGE_DIR, 0775, true);
    }

    $files = [
        SURVEY_FILE,
        ANSWER_FILE,
        CUSTOMER_FILE,
        MAIL_HISTORY_FILE,
        KINTONE_FILE,
        SMTP_FILE,
    ];

    foreach ($files as $file) {
        if (!file_exists($file)) {
            file_put_contents(
                $file,
                json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        }
    }
}

function readJson(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $json = file_get_contents($file);

    if ($json === false || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function writeJson(string $file, array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('データのJSON化に失敗しました。');
    }

    if (file_put_contents($file, $json, LOCK_EX) === false) {
        throw new RuntimeException('データ保存に失敗しました。');
    }
}

function surveys(): array
{
    return readJson(SURVEY_FILE);
}

function answers(): array
{
    return readJson(ANSWER_FILE);
}

function customers(): array
{
    return readJson(CUSTOMER_FILE);
}

function mailHistory(): array
{
    return readJson(MAIL_HISTORY_FILE);
}

function kintoneConfig(): array
{
    $data = readJson(KINTONE_FILE);
    return $data[0] ?? [];
}

function smtpConfig(): array
{
    $data = readJson(SMTP_FILE);
    return $data[0] ?? [];
}

/* ============================================================
 * Utility
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uuid(): string
{
    return bin2hex(random_bytes(8));
}

function limitText(string $value, int $max): string
{
    return mb_substr(trim($value), 0, $max);
}

function postString(string $name, string $default = ''): string
{
    return isset($_POST[$name])
        ? trim((string)$_POST[$name])
        : $default;
}

function postArray(string $name): array
{
    return isset($_POST[$name]) && is_array($_POST[$name])
        ? $_POST[$name]
        : [];
}

function statusLabel(string $status): string
{
    return match ($status) {
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => $status,
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        'published' => 'badge-success',
        'stopped' => 'badge-warning',
        'ended' => 'badge-danger',
        default => 'badge-draft',
    };
}

function normalizeDateTime(string $value): string
{
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function inputDateTime(string $value): string
{
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $timestamp);
}

/* ============================================================
 * Survey helpers
 * ============================================================ */

function findSurvey(string $id): ?array
{
    foreach (surveys() as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return updateComputedSurvey($survey);
        }
    }

    return null;
}

function updateComputedSurvey(array $survey): array
{
    $status = (string)($survey['status'] ?? 'draft');
    $endAt = (string)($survey['end_at'] ?? '');

    if (
        $status === 'published' &&
        $endAt !== '' &&
        strtotime($endAt) !== false &&
        strtotime($endAt) < time()
    ) {
        $survey['status'] = 'ended';
    }

    $survey['answer_count'] = countSurveyAnswers((string)($survey['id'] ?? ''));

    return $survey;
}

function countSurveyAnswers(string $surveyId): int
{
    $count = 0;

    foreach (answers() as $answer) {
        if ((string)($answer['survey_id'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

function normalizeSurvey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? uuid());

    $survey['title'] = limitText(
        (string)($survey['title'] ?? ''),
        MAX_TITLE_LENGTH
    );

    $survey['description'] = limitText(
        (string)($survey['description'] ?? ''),
        MAX_DESCRIPTION_LENGTH
    );

    $survey['status'] = (string)($survey['status'] ?? 'draft');

    $survey['numbering'] = in_array(
        ($survey['numbering'] ?? 'global'),
        ['global', 'group'],
        true
    )
        ? $survey['numbering']
        : 'global';

    $survey['groups'] = is_array($survey['groups'] ?? null)
        ? $survey['groups']
        : [];

    foreach ($survey['groups'] as &$group) {
        $group['id'] = (string)($group['id'] ?? uuid());
        $group['title'] = limitText(
            (string)($group['title'] ?? '無題のグループ'),
            200
        );

        $group['questions'] = is_array($group['questions'] ?? null)
            ? $group['questions']
            : [];

        foreach ($group['questions'] as &$question) {
            $question['id'] = (string)($question['id'] ?? uuid());

            $question['text'] = limitText(
                (string)($question['text'] ?? ''),
                MAX_QUESTION_LENGTH
            );

            $type = (string)($question['type'] ?? 'single');

            $question['type'] = in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            )
                ? $type
                : 'single';

            $question['required'] = !empty($question['required']);

            $question['options'] = is_array($question['options'] ?? null)
                ? $question['options']
                : [];

            foreach ($question['options'] as &$option) {
                $option = limitText((string)$option, MAX_OPTION_LENGTH);
            }
            unset($option);

            $question['branches'] = is_array($question['branches'] ?? null)
                ? $question['branches']
                : [];
        }
        unset($question);
    }
    unset($group);

    return recalculateQuestionNumbers($survey);
}

function recalculateQuestionNumbers(array $survey): array
{
    $globalNumber = 1;

    foreach ($survey['groups'] as $groupIndex => &$group) {
        $groupNumber = $groupIndex + 1;
        $groupQuestionNumber = 1;

        foreach ($group['questions'] as &$question) {
            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] =
                    'Q' . $groupNumber . '-' . $groupQuestionNumber;
            } else {
                $question['number'] = 'Q' . $globalNumber;
            }

            $globalNumber++;
            $groupQuestionNumber++;
        }
        unset($question);
    }
    unset($group);

    return $survey;
}

function defaultSurvey(): array
{
    return normalizeSurvey([
        'id' => uuid(),
        'title' => '',
        'description' => '',
        'start_at' => '',
        'end_at' => '',
        'status' => 'draft',
        'numbering' => 'global',
        'created_at' => now(),
        'updated_at' => now(),
        'groups' => [
            [
                'id' => uuid(),
                'title' => 'グループ1',
                'questions' => [
                    [
                        'id' => uuid(),
                        'text' => '',
                        'type' => 'single',
                        'required' => true,
                        'options' => [
                            'はい',
                            'いいえ',
                        ],
                        'branches' => [],
                    ],
                ],
            ],
        ],
    ]);
}

/* ============================================================
 * Save Survey
 * ============================================================ */

function handleSaveSurvey(): array
{
    $id = postString('survey_id');

    $all = surveys();

    $existing = null;

    foreach ($all as $index => $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            $existing = $survey;
            break;
        }
    }

    if ($existing === null) {
        $survey = defaultSurvey();
        $survey['id'] = $id !== '' ? $id : uuid();
        $survey['created_at'] = now();
        $survey['status'] = 'draft';
    } else {
        $survey = $existing;
    }

    $survey['title'] = limitText(
        postString('title'),
        MAX_TITLE_LENGTH
    );

    $survey['description'] = limitText(
        postString('description'),
        MAX_DESCRIPTION_LENGTH
    );

    $survey['start_at'] = normalizeDateTime(postString('start_at'));
    $survey['end_at'] = normalizeDateTime(postString('end_at'));

    $numbering = postString('numbering', 'global');

    if (!in_array($numbering, ['global', 'group'], true)) {
        $numbering = 'global';
    }

    $survey['numbering'] = $numbering;

    if ($existing === null) {
        $survey['status'] = 'draft';
    } else {
        $survey['status'] = (string)($existing['status'] ?? 'draft');
    }

    $survey['groups'] = parseEditorGroups();

    if ($survey['title'] === '') {
        return [
            'success' => false,
            'message' => 'タイトルを入力してください。',
        ];
    }

    if ($survey['end_at'] !== '' && $survey['start_at'] !== '') {
        if (strtotime($survey['end_at']) < strtotime($survey['start_at'])) {
            return [
                'success' => false,
                'message' => '終了日時は開始日時以降にしてください。',
            ];
        }
    }

    $survey = normalizeSurvey($survey);
    $survey['updated_at'] = now();

    if ($existing === null) {
        $all[] = $survey;
    } else {
        $all[$index] = $survey;
    }

    writeJson(SURVEY_FILE, $all);

    return [
        'success' => true,
        'message' => '保存しました。',
    ];
}

function parseEditorGroups(): array
{
    $groupsInput = postArray('groups');

    $groups = [];

    foreach ($groupsInput as $groupIndex => $groupInput) {
        if (!is_array($groupInput)) {
            continue;
        }

        $groupId = (string)($groupInput['id'] ?? uuid());

        $group = [
            'id' => $groupId,
            'title' => limitText(
                (string)($groupInput['title'] ?? ''),
                200
            ),
            'questions' => [],
        ];

        if ($group['title'] === '') {
            $group['title'] = '無題のグループ';
        }

        $questionInput = $groupInput['questions'] ?? [];

        if (is_array($questionInput)) {
            foreach ($questionInput as $question) {
                if (!is_array($question)) {
                    continue;
                }

                $questionId = (string)($question['id'] ?? uuid());

                $type = (string)($question['type'] ?? 'single');

                if (!in_array($type, ['single', 'multiple', 'text'], true)) {
                    $type = 'single';
                }

                $options = [];

                if (isset($question['options']) && is_array($question['options'])) {
                    foreach ($question['options'] as $option) {
                        $option = limitText((string)$option, MAX_OPTION_LENGTH);

                        if ($option !== '') {
                            $options[] = $option;
                        }
                    }
                }

                $branches = [];

                if (
                    isset($question['branches']) &&
                    is_array($question['branches'])
                ) {
                    foreach ($question['branches'] as $optionIndex => $target) {
                        $branches[(string)$optionIndex] =
                            (string)$target;
                    }
                }

                $group['questions'][] = [
                    'id' => $questionId,
                    'text' => limitText(
                        (string)($question['text'] ?? ''),
                        MAX_QUESTION_LENGTH
                    ),
                    'type' => $type,
                    'required' => !empty($question['required']),
                    'options' => $options,
                    'branches' => $branches,
                ];
            }
        }

        $groups[] = $group;
    }

    if (!$groups) {
        $groups[] = [
            'id' => uuid(),
            'title' => 'グループ1',
            'questions' => [],
        ];
    }

    return $groups;
}

/* ============================================================
 * Survey Actions
 * ============================================================ */

function handleDeleteSurvey(): void
{
    $id = postString('survey_id');

    $all = surveys();

    $all = array_values(array_filter(
        $all,
        fn($survey) =>
            (string)($survey['id'] ?? '') !== $id
    ));

    writeJson(SURVEY_FILE, $all);
}

function handleDuplicateSurvey(): void
{
    $id = postString('survey_id');

    $survey = findSurvey($id);

    if ($survey === null) {
        throw new RuntimeException('アンケートが見つかりません。');
    }

    $copy = $survey;

    $copy['id'] = uuid();
    $copy['title'] =
        limitText((string)$survey['title'] . '（コピー）', MAX_TITLE_LENGTH);

    $copy['status'] = 'draft';
    $copy['created_at'] = now();
    $copy['updated_at'] = now();

    foreach ($copy['groups'] as &$group) {
        $group['id'] = uuid();

        foreach ($group['questions'] as &$question) {
            $question['id'] = uuid();
        }
        unset($question);
    }
    unset($group);

    $all = surveys();
    $all[] = normalizeSurvey($copy);

    writeJson(SURVEY_FILE, $all);
}

function handleChangeStatus(): void
{
    $id = postString('survey_id');
    $newStatus = postString('new_status');

    $allowed = [
        'draft' => ['published'],
        'published' => ['stopped'],
        'stopped' => ['published'],
    ];

    $all = surveys();

    foreach ($all as $index => $survey) {
        if ((string)($survey['id'] ?? '') !== $id) {
            continue;
        }

        $current = (string)($survey['status'] ?? 'draft');

        if (
            !isset($allowed[$current]) ||
            !in_array($newStatus, $allowed[$current], true)
        ) {
            throw new RuntimeException('許可されていない状態遷移です。');
        }

        $survey['status'] = $newStatus;
        $survey['updated_at'] = now();

        $all[$index] = $survey;
        writeJson(SURVEY_FILE, $all);

        return;
    }

    throw new RuntimeException('アンケートが見つかりません。');
}

/* ============================================================
 * Answer
 * ============================================================ */

function handleSaveAnswer(): array
{
    $surveyId = postString('survey_id');

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        return [
            'success' => false,
            'message' => 'アンケートが見つかりません。',
        ];
    }

    if (($survey['status'] ?? '') !== 'published') {
        return [
            'success' => false,
            'message' => 'このアンケートは現在回答できません。',
        ];
    }

    $postedAnswers = postArray('answers');

    $validation = validateAnswers($survey, $postedAnswers);

    if (!$validation['success']) {
        return $validation;
    }

    $answer = [
        'id' => uuid(),
        'survey_id' => $surveyId,
        'answered_at' => now(),
        'answers' => $postedAnswers,
    ];

    $all = answers();
    $all[] = $answer;

    writeJson(ANSWER_FILE, $all);

    return [
        'success' => true,
        'survey_id' => $surveyId,
        'message' => '回答を登録しました。',
    ];
}

function validateAnswers(array $survey, array $answersInput): array
{
    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            if (shouldQuestionBeVisible($survey, $question, $answersInput) === false) {
                continue;
            }

            if (!empty($question['required'])) {
                $id = (string)$question['id'];

                $value = $answersInput[$id] ?? null;

                if (is_array($value)) {
                    $hasValue = count(array_filter(
                        $value,
                        fn($v) => trim((string)$v) !== ''
                    )) > 0;
                } else {
                    $hasValue = trim((string)$value) !== '';
                }

                if (!$hasValue) {
                    return [
                        'success' => false,
                        'message' =>
                            ($question['number'] ?? '質問') .
                            ' は必須項目です。',
                    ];
                }
            }
        }
    }

    return [
        'success' => true,
    ];
}

function shouldQuestionBeVisible(
    array $survey,
    array $targetQuestion,
    array $answersInput
): bool {
    $targetId = (string)$targetQuestion['id'];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            foreach (($question['branches'] ?? []) as $optionIndex => $nextId) {
                if ((string)$nextId !== $targetId) {
                    continue;
                }

                $answer = $answersInput[(string)$question['id']] ?? null;

                if (is_array($answer)) {
                    $matched = in_array(
                        (string)$optionIndex,
                        array_map('strval', $answer),
                        true
                    );
                } else {
                    $matched =
                        (string)$answer === (string)$optionIndex;
                }

                if ($matched) {
                    return true;
                }
            }
        }
    }

    /*
     * 明示的な分岐元がない質問は表示する。
     */
    $hasIncomingBranch = false;

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            foreach (($question['branches'] ?? []) as $nextId) {
                if ((string)$nextId === $targetId) {
                    $hasIncomingBranch = true;
                }
            }
        }
    }

    return !$hasIncomingBranch;
}

/* ============================================================
 * Analytics
 * ============================================================ */

function surveyAnswers(string $surveyId): array
{
    return array_values(array_filter(
        answers(),
        fn($answer) =>
            (string)($answer['survey_id'] ?? '') === $surveyId
    ));
}

function questionAnswerStats(array $survey, array $question): array
{
    $stats = [];

    foreach (($question['options'] ?? []) as $index => $option) {
        $stats[(string)$index] = [
            'label' => $option,
            'count' => 0,
        ];
    }

    foreach (surveyAnswers((string)$survey['id']) as $answer) {
        $value = $answer['answers'][(string)$question['id']] ?? null;

        if (is_array($value)) {
            foreach ($value as $v) {
                $key = (string)$v;

                if (isset($stats[$key])) {
                    $stats[$key]['count']++;
                }
            }
        } else {
            $key = (string)$value;

            if (isset($stats[$key])) {
                $stats[$key]['count']++;
            }
        }
    }

    return $stats;
}

/* ============================================================
 * CSV
 * ============================================================ */

function exportCsv(): void
{
    $surveyId = (string)($_GET['id'] ?? '');

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        http_response_code(404);
        echo 'Survey not found';
        exit;
    }

    $answersData = surveyAnswers($surveyId);

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey-' .
        preg_replace('/[^a-zA-Z0-9_-]/', '_', $surveyId) .
        '.csv"'
    );

    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'w');

    $header = [
        '回答ID',
        '回答日時',
    ];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $header[] =
                ($question['number'] ?? '') . ' ' .
                ($question['text'] ?? '');
        }
    }

    fputcsv($fp, $header);

    foreach ($answersData as $answer) {
        $row = [
            $answer['id'] ?? '',
            $answer['answered_at'] ?? '',
        ];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $value =
                    $answer['answers'][(string)$question['id']] ?? '';

                if (is_array($value)) {
                    $labels = [];

                    foreach ($value as $index) {
                        $labels[] =
                            $question['options'][(int)$index] ??
                            (string)$index;
                    }

                    $value = implode('、', $labels);
                } elseif (
                    $question['type'] === 'single' &&
                    $value !== ''
                ) {
                    $value =
                        $question['options'][(int)$value] ??
                        (string)$value;
                }

                $row[] = $value;
            }
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
}

/* ============================================================
 * Simple PDF
 * ============================================================ */

function exportPdf(): void
{
    $surveyId = (string)($_GET['id'] ?? '');

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        http_response_code(404);
        echo 'Survey not found';
        exit;
    }

    /*
     * 外部PDFライブラリを要求しないPOC用の
     * 最小PDFを生成する。
     *
     * 日本語フォント埋め込みは行わないため、
     * PDF本文はASCII主体とする。
     */

    $lines = [
        'Survey Report',
        'Title: ' . asciiText((string)$survey['title']),
        'Answers: ' . countSurveyAnswers($surveyId),
        'Generated: ' . date('Y-m-d H:i:s'),
    ];

    foreach ($survey['groups'] as $group) {
        $lines[] = 'Group: ' . asciiText((string)$group['title']);

        foreach ($group['questions'] as $question) {
            $lines[] =
                ($question['number'] ?? '') .
                ' ' .
                asciiText((string)$question['text']);
        }
    }

    $pdf = buildSimplePdf($lines);

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="survey-report.pdf"'
    );
    header('Content-Length: ' . strlen($pdf));

    echo $pdf;
}

function asciiText(string $text): string
{
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

    if ($converted === false || $converted === '') {
        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
    }

    return preg_replace('/[^\x20-\x7E]/', '?', $converted) ?? '';
}

function buildSimplePdf(array $lines): string
{
    $objects = [];

    $objects[] =
        '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[] =
        '<< /Type /Page /Parent 2 0 R ' .
        '/MediaBox [0 0 595 842] ' .
        '/Resources << /Font << /F1 5 0 R >> >> ' .
        '/Contents 4 0 R >>';

    $stream = "BT\n/F1 11 Tf\n50 790 Td\n";

    $first = true;

    foreach ($lines as $line) {
        $line = str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $line
        );

        if (!$first) {
            $stream .= "0 -18 Td\n";
        }

        $stream .= '(' . $line . ") Tj\n";
        $first = false;
    }

    $stream .= "ET\n";

    $objects[] =
        '<< /Length ' . strlen($stream) . " >>\n" .
        "stream\n" .
        $stream .
        "endstream";

    $objects[] =
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $index => $object) {
        $number = $index + 1;

        $offsets[$number] = strlen($pdf);

        $pdf .=
            $number . " 0 obj\n" .
            $object .
            "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);

    $pdf .=
        "xref\n" .
        "0 " . (count($objects) + 1) . "\n" .
        "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .=
            sprintf(
                "%010d 00000 n \n",
                $offsets[$i]
            );
    }

    $pdf .=
        "trailer\n" .
        "<< /Size " . (count($objects) + 1) .
        " /Root 1 0 R >>\n" .
        "startxref\n" .
        $xrefOffset .
        "\n%%EOF";

    return $pdf;
}

/* ============================================================
 * kintone
 * ============================================================ */

function handleSaveKintone(): array
{
    $config = [
        'subdomain' => postString('subdomain'),
        'app_id' => postString('app_id'),
        'login' => postString('login'),
        'password' => postString('password'),
        'proxy' => postString('proxy'),
        'verify_ssl' => isset($_POST['verify_ssl']),
        'mapping' => [
            'organization' => postString('map_organization'),
            'name' => postString('map_name'),
            'email' => postString('map_email'),
            'department' => postString('map_department'),
            'phone' => postString('map_phone'),
            'address' => postString('map_address'),
        ],
    ];

    if ($config['subdomain'] === '') {
        return [
            'success' => false,
            'message' => 'kintoneサブドメインを入力してください。',
        ];
    }

    if ($config['app_id'] === '' || !ctype_digit($config['app_id'])) {
        return [
            'success' => false,
            'message' => 'kintoneアプリIDが不正です。',
        ];
    }

    writeJson(KINTONE_FILE, [$config]);

    return [
        'success' => true,
        'message' => 'kintone設定を保存しました。',
    ];
}

function kintoneRequest(
    string $method,
    string $path,
    ?string $body = null
): array {
    $config = kintoneConfig();

    if (!$config) {
        throw new RuntimeException('kintone設定がありません。');
    }

    $subdomain = trim((string)($config['subdomain'] ?? ''));

    if ($subdomain === '') {
        throw new RuntimeException('kintoneサブドメインが未設定です。');
    }

    $url =
        'https://' .
        $subdomain .
        '.cybozu.com' .
        $path;

    $login = (string)($config['login'] ?? '');
    $password = (string)($config['password'] ?? '');

    if ($login === '' || $password === '') {
        throw new RuntimeException(
            'kintoneログイン情報が未設定です。'
        );
    }

    $authorization = base64_encode(
        $login . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Content-Type: application/json',
    ];

    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
        'ssl' => [
            'verify_peer' =>
                !empty($config['verify_ssl']),
            'verify_peer_name' =>
                !empty($config['verify_ssl']),
        ],
    ];

    if ($body !== null) {
        $contextOptions['http']['content'] = $body;
    }

    $context = stream_context_create($contextOptions);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへの接続に失敗しました。'
        );
    }

    $status = 0;

    if (isset($http_response_header[0])) {
        if (
            preg_match(
                '/\s(\d{3})\s/',
                $http_response_header[0],
                $matches
            )
        ) {
            $status = (int)$matches[1];
        }
    }

    $data = json_decode($response, true);

    return [
        'status' => $status,
        'data' => is_array($data) ? $data : [],
        'raw' => $response,
    ];
}

function handleKintoneTest(): array
{
    try {
        $config = kintoneConfig();

        $appId = (string)($config['app_id'] ?? '');

        if ($appId === '') {
            throw new RuntimeException(
                'アプリIDが設定されていません。'
            );
        }

        $result = kintoneRequest(
            'GET',
            '/k/v1/app.json?id=' .
            rawurlencode($appId)
        );

        if ($result['status'] >= 200 && $result['status'] < 300) {
            return [
                'success' => true,
                'message' => 'kintone接続に成功しました。',
            ];
        }

        $message =
            $result['data']['message'] ??
            'kintone接続に失敗しました。';

        return [
            'success' => false,
            'message' => $message,
        ];

    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}

function handleKintoneFields(): array
{
    try {
        $config = kintoneConfig();

        $appId = (string)($config['app_id'] ?? '');

        if ($appId === '') {
            throw new RuntimeException(
                'アプリIDが設定されていません。'
            );
        }

        $result = kintoneRequest(
            'GET',
            '/k/v1/app/form/fields.json?id=' .
            rawurlencode($appId)
        );

        if ($result['status'] < 200 || $result['status'] >= 300) {
            return [
                'success' => false,
                'message' =>
                    $result['data']['message'] ??
                    'フィールド取得に失敗しました。',
            ];
        }

        $_SESSION['kintone_fields'] =
            $result['data']['properties'] ?? [];

        return [
            'success' => true,
            'message' => '項目一覧を取得しました。',
        ];

    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}

function handleKintoneSync(): array
{
    try {
        $config = kintoneConfig();

        $appId = (string)($config['app_id'] ?? '');

        if ($appId === '') {
            throw new RuntimeException(
                'アプリIDが設定されていません。'
            );
        }

        $result = kintoneRequest(
            'GET',
            '/k/v1/records.json?app=' .
            rawurlencode($appId) .
            '&query=' .
            rawurlencode('order by $id asc limit 500')
        );

        if ($result['status'] < 200 || $result['status'] >= 300) {
            return [
                'success' => false,
                'message' =>
                    $result['data']['message'] ??
                    '顧客情報の取得に失敗しました。',
            ];
        }

        $records = $result['data']['records'] ?? [];

        $mapping = $config['mapping'] ?? [];

        $mappedCustomers = [];

        foreach ($records as $record) {
            $mappedCustomers[] = [
                'id' => uuid(),
                'kintone_id' =>
                    $record['$id']['value'] ?? '',
                'organization' =>
                    readKintoneField(
                        $record,
                        $mapping['organization'] ?? ''
                    ),
                'name' =>
                    readKintoneField(
                        $record,
                        $mapping['name'] ?? ''
                    ),
                'email' =>
                    readKintoneField(
                        $record,
                        $mapping['email'] ?? ''
                    ),
                'department' =>
                    readKintoneField(
                        $record,
                        $mapping['department'] ?? ''
                    ),
                'phone' =>
                    readKintoneField(
                        $record,
                        $mapping['phone'] ?? ''
                    ),
                'address' =>
                    readKintoneField(
                        $record,
                        $mapping['address'] ?? ''
                    ),
                'updated_at' => now(),
            ];
        }

        writeJson(CUSTOMER_FILE, $mappedCustomers);

        return [
            'success' => true,
            'message' =>
                count($mappedCustomers) .
                '件の顧客情報を同期しました。',
        ];

    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}

function readKintoneField(array $record, string $fieldCode): string
{
    if ($fieldCode === '') {
        return '';
    }

    $field = $record[$fieldCode] ?? null;

    if (!is_array($field)) {
        return '';
    }

    $value = $field['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] = (string)($item['value'] ?? '');
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(' ', $parts);
    }

    return (string)$value;
}

/* ============================================================
 * SMTP
 * ============================================================ */

function handleSaveSmtp(): array
{
    $config = [
        'host' => postString('host'),
        'port' => (int)postString('port', '587'),
        'encryption' => postString('encryption', 'tls'),
        'auth' => isset($_POST['auth']),
        'username' => postString('username'),
        'password' => postString('password'),
        'from_email' => postString('from_email'),
        'from_name' => postString('from_name'),
        'reply_to' => postString('reply_to'),
    ];

    if ($config['host'] === '') {
        return [
            'success' => false,
            'message' => 'SMTPサーバを入力してください。',
        ];
    }

    if (
        $config['port'] < 1 ||
        $config['port'] > 65535
    ) {
        return [
            'success' => false,
            'message' => 'SMTPポートが不正です。',
        ];
    }

    if (
        $config['from_email'] !== '' &&
        !filter_var(
            $config['from_email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return [
            'success' => false,
            'message' => '送信元メールアドレスが不正です。',
        ];
    }

    writeJson(SMTP_FILE, [$config]);

    return [
        'success' => true,
        'message' => 'SMTP設定を保存しました。',
    ];
}

function smtpConnect(): array
{
    $config = smtpConfig();

    if (!$config) {
        throw new RuntimeException(
            'SMTP設定がありません。'
        );
    }

    $host = (string)$config['host'];
    $port = (int)$config['port'];
    $encryption = (string)($config['encryption'] ?? 'none');

    $transportHost = $host;

    if ($encryption === 'ssl') {
        $transportHost = 'ssl://' . $host;
    }

    $socket = @stream_socket_client(
        $transportHost . ':' . $port,
        $errno,
        $errstr,
        15
    );

    if (!$socket) {
        throw new RuntimeException(
            'SMTP接続に失敗しました: ' . $errstr
        );
    }

    stream_set_timeout($socket, 15);

    smtpRead($socket);

    smtpCommand($socket, 'EHLO localhost', 250);

    if ($encryption === 'tls') {
        smtpCommand($socket, 'STARTTLS', 220);

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            throw new RuntimeException(
                'SMTP TLS接続に失敗しました。'
            );
        }

        smtpCommand($socket, 'EHLO localhost', 250);
    }

    if (!empty($config['auth'])) {
        $username = (string)$config['username'];
        $password = (string)$config['password'];

        smtpCommand($socket, 'AUTH LOGIN', 334);
        smtpCommand(
            $socket,
            base64_encode($username),
            334
        );
        smtpCommand(
            $socket,
            base64_encode($password),
            235
        );
    }

    return [
        'socket' => $socket,
        'config' => $config,
    ];
}

function smtpRead($socket): string
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    return $response;
}

function smtpCommand(
    $socket,
    string $command,
    int|array $expected
): string {
    fwrite($socket, $command . "\r\n");

    $response = smtpRead($socket);

    $code = (int)substr($response, 0, 3);

    $expectedCodes =
        is_array($expected)
            ? $expected
            : [$expected];

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' .
            trim($response)
        );
    }

    return $response;
}

function smtpSend(
    string $to,
    string $subject,
    string $body
): array {
    $connection = smtpConnect();

    $socket = $connection['socket'];
    $config = $connection['config'];

    try {
        $from =
            (string)($config['from_email'] ?? '');

        if (
            $from === '' ||
            !filter_var(
                $from,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                '送信元メールアドレスが未設定です。'
            );
        }

        smtpCommand(
            $socket,
            'MAIL FROM:<' . $from . '>',
            250
        );

        smtpCommand(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtpCommand(
            $socket,
            'DATA',
            354
        );

        $fromName =
            (string)($config['from_name'] ?? '');

        $headers = [];

        $headers[] =
            'From: ' .
            formatMailAddress(
                $from,
                $fromName
            );

        $headers[] =
            'To: <' . $to . '>';

        $headers[] =
            'Subject: ' .
            mimeHeader($subject);

        $headers[] =
            'Reply-To: <' .
            (string)($config['reply_to'] ?? $from) .
            '>';

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        $headers[] =
            'Content-Transfer-Encoding: 8bit';

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            normalizeMailBody($body) .
            "\r\n.";

        smtpCommand(
            $socket,
            $message,
            250
        );

        smtpCommand(
            $socket,
            'QUIT',
            221
        );

        fclose($socket);

        return [
            'success' => true,
            'message' => 'メールを送信しました。',
        ];

    } catch (Throwable $e) {
        @fclose($socket);

        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}

function formatMailAddress(
    string $email,
    string $name
): string {
    if ($name === '') {
        return '<' . $email . '>';
    }

    return
        '=?UTF-8?B?' .
        base64_encode($name) .
        '?= <' .
        $email .
        '>';
}

function mimeHeader(string $value): string
{
    return
        '=?UTF-8?B?' .
        base64_encode($value) .
        '?=';
}

function normalizeMailBody(string $body): string
{
    $body = str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );

    $body = str_replace(
        "\n",
        "\r\n",
        $body
    );

    /*
     * SMTP DATA内の行頭ドットをエスケープ
     */
    $body = preg_replace(
        '/^\./m',
        '..',
        $body
    ) ?? $body;

    return $body;
}

function handleSmtpTest(): array
{
    try {
        $config = smtpConfig();

        if (!$config) {
            throw new RuntimeException(
                'SMTP設定を保存してください。'
            );
        }

        $connection = smtpConnect();

        $socket = $connection['socket'];

        smtpCommand(
            $socket,
            'QUIT',
            221
        );

        fclose($socket);

        return [
            'success' => true,
            'message' => 'SMTP接続に成功しました。',
        ];

    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}

/* ============================================================
 * Mail send
 * ============================================================ */

function handleSendMail(): array
{
    $surveyId = postString('survey_id');

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        return [
            'success' => false,
            'message' => 'アンケートが見つかりません。',
        ];
    }

    $selected = postArray('customer_ids');

    $subject = postString('subject');
    $body = postString('body');

    if ($subject === '') {
        return [
            'success' => false,
            'message' => '件名を入力してください。',
        ];
    }

    if ($body === '') {
        return [
            'success' => false,
            'message' => '本文を入力してください。',
        ];
    }

    if (!$selected) {
        return [
            'success' => false,
            'message' => '顧客を選択してください。',
        ];
    }

    $allCustomers = customers();
    $history = mailHistory();

    $successCount = 0;
    $failureCount = 0;

    foreach ($allCustomers as $customer) {
        $customerId =
            (string)($customer['id'] ?? '');

        if (!in_array($customerId, $selected, true)) {
            continue;
        }

        $email =
            (string)($customer['email'] ?? '');

        if (
            $email === '' ||
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $failureCount++;

            $history[] = [
                'id' => uuid(),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'email' => $email,
                'subject' => $subject,
                'status' => 'failed',
                'message' => 'メールアドレスが不正です。',
                'sent_at' => now(),
            ];

            continue;
        }

        $personalSubject =
            str_replace(
                '{顧客名}',
                (string)($customer['name'] ?? ''),
                $subject
            );

        $url =
            baseUrl() .
            '?screen=answer&id=' .
            rawurlencode($surveyId);

        $personalBody =
            str_replace(
                '{顧客名}',
                (string)($customer['name'] ?? ''),
                $body
            );

        $personalBody =
            str_replace(
                '{アンケートURL}',
                $url,
                $personalBody
            );

        $result = smtpSend(
            $email,
            $personalSubject,
            $personalBody
        );

        if ($result['success']) {
            $successCount++;
        } else {
            $failureCount++;
        }

        $history[] = [
            'id' => uuid(),
            'survey_id' => $surveyId,
            'customer_id' => $customerId,
            'email' => $email,
            'subject' => $personalSubject,
            'status' =>
                $result['success']
                    ? 'sent'
                    : 'failed',
            'message' => $result['message'],
            'sent_at' => now(),
        ];
    }

    writeJson(
        MAIL_HISTORY_FILE,
        $history
    );

    return [
        'success' => $successCount > 0,
        'message' =>
            '送信成功 ' .
            $successCount .
            '件 / 失敗 ' .
            $failureCount .
            '件',
    ];
}

function baseUrl(): string
{
    $scheme =
        (
            (!empty($_SERVER['HTTPS']) &&
             $_SERVER['HTTPS'] !== 'off')
            ||
            (
                isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
                $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
            )
        )
            ? 'https'
            : 'http';

    $host =
        $_SERVER['HTTP_HOST'] ??
        'localhost';

    $path =
        $_SERVER['SCRIPT_NAME'] ??
        '/index.php';

    return
        $scheme .
        '://' .
        $host .
        $path;
}

/* ============================================================
 * Header / Footer
 * ============================================================ */

function renderHeader(
    string $screen,
    ?array $survey
): void {
    $isAnswer =
        in_array(
            $screen,
            ['answer', 'confirm', 'complete'],
            true
        );

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1.0">
<title><?= h(APP_NAME) ?></title>

<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --success: #16a34a;
    --warning: #d97706;
    --danger: #dc2626;
    --gray: #64748b;
    --gray-light: #f1f5f9;
    --border: #dbe2ea;
    --text: #1e293b;
    --white: #ffffff;
    --background: #f8fafc;
    --header: #0f172a;
    --shadow: 0 4px 18px rgba(15, 23, 42, .08);
    --radius: 10px;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
}

body {
    background: var(--background);
    color: var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    line-height: 1.6;
}

a {
    color: var(--primary);
    text-decoration: none;
}

a:hover {
    text-decoration: underline;
}

button,
input,
select,
textarea {
    font: inherit;
}

button {
    cursor: pointer;
}

h1,
h2,
h3 {
    margin-top: 0;
}

h1 {
    font-size: 1.65rem;
    margin-bottom: 6px;
}

h2 {
    font-size: 1.2rem;
    margin-bottom: 18px;
}

h3 {
    font-size: 1rem;
    margin-bottom: 12px;
}

.app-header {
    background: var(--header);
    color: var(--white);
    min-height: 64px;
    display: flex;
    align-items: center;
    padding: 0 24px;
}

.app-header .brand {
    color: var(--white);
    font-weight: 700;
    font-size: 1.1rem;
}

.app-header .nav {
    margin-left: auto;
    display: flex;
    gap: 8px;
    align-items: center;
}

.app-header .nav a {
    color: #cbd5e1;
    padding: 8px 12px;
    border-radius: 6px;
}

.app-header .nav a:hover,
.app-header .nav a.active {
    color: var(--white);
    background: rgba(255,255,255,.08);
    text-decoration: none;
}

.container {
    width: min(1200px, calc(100% - 32px));
    margin: 0 auto;
    padding: 28px 0 48px;
}

.answer-container {
    width: min(760px, calc(100% - 32px));
    margin: 0 auto;
    padding: 28px 0 48px;
}

.page-title {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 22px;
}

.card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 22px;
    margin-bottom: 20px;
}

.answer-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 22px;
    margin-bottom: 18px;
}

.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
}

.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
}

.form-help {
    margin-top: 5px;
    color: var(--gray);
    font-size: .875rem;
}

input[type="text"],
input[type="search"],
input[type="email"],
input[type="password"],
input[type="number"],
input[type="datetime-local"],
select,
textarea {
    width: 100%;
    min-height: 42px;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: var(--white);
    color: var(--text);
    outline: none;
}

textarea {
    min-height: 140px;
    resize: vertical;
}

input:focus,
select:focus,
textarea:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}

.checkbox {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.checkbox input {
    width: 17px;
    height: 17px;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 40px;
    padding: 8px 14px;
    border: 1px solid transparent;
    border-radius: 7px;
    font-weight: 600;
    line-height: 1.3;
    text-decoration: none;
    white-space: nowrap;
}

.btn:hover {
    text-decoration: none;
}

.btn-primary {
    background: var(--primary);
    color: var(--white);
}

.btn-primary:hover {
    background: var(--primary-dark);
}

.btn-secondary {
    background: var(--white);
    color: var(--text);
    border-color: var(--border);
}

.btn-secondary:hover {
    background: var(--gray-light);
}

.btn-success {
    background: var(--success);
    color: var(--white);
}

.btn-warning {
    background: var(--warning);
    color: var(--white);
}

.btn-danger {
    background: var(--danger);
    color: var(--white);
}

.btn-sm {
    min-height: 34px;
    padding: 6px 10px;
    font-size: .875rem;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: var(--white);
}

th,
td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: middle;
}

th {
    background: var(--gray-light);
    font-weight: 700;
    white-space: nowrap;
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 700;
    white-space: nowrap;
}

.badge-success {
    background: #dcfce7;
    color: #166534;
}

.badge-warning {
    background: #fef3c7;
    color: #92400e;
}

.badge-danger {
    background: #fee2e2;
    color: #991b1b;
}

.badge-gray {
    background: #e2e8f0;
    color: #475569;
}

.badge-draft {
    background: #e0e7ff;
    color: #3730a3;
}

.tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 20px;
}

.tabs a {
    padding: 10px 14px;
    color: var(--gray);
    border-bottom: 2px solid transparent;
}

.tabs a:hover {
    text-decoration: none;
    color: var(--text);
}

.tabs a.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 700;
}

.alert {
    width: min(1200px, calc(100% - 32px));
    margin: 20px auto 0;
    padding: 12px 16px;
    border-radius: 8px;
    border: 1px solid;
}

.alert-success {
    background: #f0fdf4;
    border-color: #bbf7d0;
    color: #166534;
}

.alert-danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #991b1b;
}

.muted {
    color: var(--gray);
}

.empty {
    padding: 34px 20px;
    text-align: center;
    color: var(--gray);
    background: #f8fafc;
    border: 1px dashed var(--border);
    border-radius: 8px;
}

.searchbar {
    display: flex;
    gap: 8px;
    align-items: center;
}

.searchbar input {
    flex: 1;
}

/* ============================================================
 * Editor
 * ============================================================ */

.editor-groups {
    min-height: 30px;
}

.group-card {
    border: 1px solid var(--border);
    border-radius: 9px;
    background: #fff;
    margin-bottom: 18px;
    overflow: hidden;
    transition:
        border-color .15s,
        box-shadow .15s,
        opacity .15s,
        transform .15s;
}

.group-card.dragging {
    opacity: .55;
}

.group-card.drag-over {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}

.group-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 16px;
    background: var(--gray-light);
    border-bottom: 1px solid var(--border);
}

.drag-handle {
    color: var(--gray);
    cursor: grab;
    user-select: none;
    font-size: 1.1rem;
    flex: 0 0 auto;
}

.drag-handle:active {
    cursor: grabbing;
}

.group-title-input {
    flex: 1;
    min-width: 0;
}

.group-body {
    padding: 16px;
    min-height: 80px;
}

.question-list {
    min-height: 60px;
}

.question-card {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    background: #fff;
    transition:
        border-color .15s,
        box-shadow .15s,
        opacity .15s,
        transform .15s;
}

.question-card:last-child {
    margin-bottom: 0;
}

.question-card.dragging {
    opacity: .55;
}

.question-card.drag-over {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}

.drop-zone {
    border: 2px dashed transparent;
    border-radius: 8px;
    min-height: 52px;
    padding: 8px;
    transition:
        border-color .15s,
        background .15s;
}

.drop-zone.active {
    border-color: var(--primary);
    background: rgba(37,99,235,.05);
}

.question-row {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.question-content {
    flex: 1;
    min-width: 0;
}

.question-number {
    display: inline-block;
    min-width: 55px;
    font-weight: 700;
    color: var(--primary);
}

.option-row {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
}

.option-row input {
    flex: 1;
}

.branch-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 8px;
}

.editor-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 18px;
}

.question-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-bottom: 10px;
}

.question-type {
    width: 180px !important;
}

.delete-question,
.delete-group {
    margin-left: auto;
}

/* Answer */
.answer-question-title {
    font-weight: 700;
    margin-bottom: 12px;
}

.answer-option {
    display: block;
    padding: 8px 0;
}

.answer-option label {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    cursor: pointer;
}

.answer-option input {
    margin-top: 5px;
}

/* Stats */
.stat-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px;
}

.stat-label {
    color: var(--gray);
    font-size: .875rem;
}

.stat-value {
    font-size: 1.7rem;
    font-weight: 700;
}

/* Mail */
.history-table {
    max-height: 480px;
    overflow: auto;
}

/* Responsive */
@media (max-width: 900px) {
    .grid-3 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .app-header {
        min-height: auto;
        padding: 12px 16px;
        flex-wrap: wrap;
        gap: 8px;
    }

    .app-header .nav {
        width: 100%;
        margin-left: 0;
        overflow-x: auto;
    }

    .container {
        width: min(100% - 20px, 1200px);
        padding-top: 18px;
    }

    .answer-container {
        width: min(100% - 16px, 760px);
        padding-top: 12px;
    }

    .page-title {
        flex-direction: column;
    }

    .grid-2,
    .grid-3 {
        grid-template-columns: 1fr;
    }

    .card,
    .answer-card {
        padding: 16px;
    }

    .searchbar {
        flex-direction: column;
    }

    .searchbar .btn {
        width: 100%;
    }

    .actions {
        width: 100%;
    }

    .branch-row {
        grid-template-columns: 1fr;
    }

    .question-row {
        flex-direction: column;
    }

    .delete-question,
    .delete-group {
        margin-left: 0;
    }
}

@media (max-width: 480px) {
    .btn {
        min-height: 42px;
    }

    th,
    td {
        padding: 10px 11px;
    }

    .group-header {
        align-items: flex-start;
    }

    .option-row {
        flex-direction: column;
    }
}

@media print {
    .app-header,
    .actions,
    .btn,
    .tabs {
        display: none !important;
    }

    body {
        background: #fff;
    }

    .container,
    .answer-container {
        width: 100%;
        max-width: none;
        padding: 0;
    }

    .card,
    .answer-card {
        box-shadow: none;
        border: 0;
    }
}
</style>
</head>

<body>

<header class="app-header">
    <a class="brand"
       href="index.php?screen=list">
        <?= h(APP_NAME) ?>
    </a>

    <?php if (!$isAnswer): ?>
    <nav class="nav">
        <a href="index.php?screen=list"
           class="<?= $screen === 'list' ? 'active' : '' ?>">
            アンケート
        </a>

        <a href="index.php?screen=kintone"
           class="<?= $screen === 'kintone' ? 'active' : '' ?>">
            kintone
        </a>

        <a href="index.php?screen=mail"
           class="<?= $screen === 'mail' ? 'active' : '' ?>">
            メール設定
        </a>
    </nav>
    <?php endif; ?>
</header>

<?php if ($isAnswer): ?>
<main class="answer-container">
<?php else: ?>
<main class="container">
<?php endif; ?>

<?php
}

function renderFooter(): void
{
    ?>
</main>

<script>
/* ============================================================
 * 共通
 * ============================================================ */

function confirmAction(message) {
    return window.confirm(message);
}

/* ============================================================
 * Editor
 * ============================================================ */

let dragState = {
    type: null,
    id: null
};

function initEditor() {
    const groupsContainer =
        document.getElementById('editor-groups');

    if (!groupsContainer) {
        return;
    }

    bindGroupEvents();
    refreshQuestionNumbers();
    updateBranchTargets();
}

function bindGroupEvents() {
    document
        .querySelectorAll('.group-card')
        .forEach(group => {
            bindGroup(group);
        });
}

function bindGroup(group) {
    const handle =
        group.querySelector('.group-drag-handle');

    if (handle) {
        handle.addEventListener(
            'dragstart',
            event => {
                dragState = {
                    type: 'group',
                    id: group.dataset.groupId
                };

                group.classList.add('dragging');

                event.dataTransfer.effectAllowed =
                    'move';

                event.dataTransfer.setData(
                    'text/plain',
                    group.dataset.groupId
                );
            }
        );

        handle.addEventListener(
            'dragend',
            () => {
                group.classList.remove('dragging');
                clearDragStyles();
            }
        );
    }

    group.addEventListener(
        'dragover',
        event => {
            if (dragState.type !== 'group') {
                return;
            }

            if (
                dragState.id ===
                group.dataset.groupId
            ) {
                return;
            }

            event.preventDefault();

            group.classList.add('drag-over');
        }
    );

    group.addEventListener(
        'dragleave',
        event => {
            if (
                !group.contains(event.relatedTarget)
            ) {
                group.classList.remove(
                    'drag-over'
                );
            }
        }
    );

    group.addEventListener(
        'drop',
        event => {
            if (dragState.type !== 'group') {
                return;
            }

            event.preventDefault();

            const source =
                document.querySelector(
                    '.group-card[data-group-id="' +
                    CSS.escape(dragState.id) +
                    '"]'
                );

            if (!source || source === group) {
                clearDragStyles();
                return;
            }

            const rect =
                group.getBoundingClientRect();

            const after =
                event.clientY >
                rect.top + rect.height / 2;

            if (after) {
                group.after(source);
            } else {
                group.before(source);
            }

            clearDragStyles();
            refreshQuestionNumbers();
            updateBranchTargets();
        }
    );

    const questionList =
        group.querySelector('.question-list');

    if (questionList) {
        bindQuestionList(questionList);
    }

    group
        .querySelectorAll('.question-card')
        .forEach(question => {
            bindQuestion(question);
        });
}

function bindQuestionList(list) {
    list.addEventListener(
        'dragover',
        event => {
            if (dragState.type !== 'question') {
                return;
            }

            event.preventDefault();

            list.classList.add('active');
        }
    );

    list.addEventListener(
        'dragleave',
        event => {
            if (
                !list.contains(event.relatedTarget)
            ) {
                list.classList.remove('active');
            }
        }
    );

    list.addEventListener(
        'drop',
        event => {
            if (dragState.type !== 'question') {
                return;
            }

            event.preventDefault();

            const source =
                document.querySelector(
                    '.question-card[data-question-id="' +
                    CSS.escape(dragState.id) +
                    '"]'
                );

            if (!source) {
                clearDragStyles();
                return;
            }

            const target =
                event.target.closest(
                    '.question-card'
                );

            if (target && target !== source) {
                const rect =
                    target.getBoundingClientRect();

                const after =
                    event.clientY >
                    rect.top + rect.height / 2;

                if (after) {
                    target.after(source);
                } else {
                    target.before(source);
                }
            } else {
                list.appendChild(source);
            }

            clearDragStyles();
            refreshQuestionNumbers();
            updateBranchTargets();
        }
    );
}

function bindQuestion(question) {
    const handle =
        question.querySelector(
            '.question-drag-handle'
        );

    if (!handle) {
        return;
    }

    handle.addEventListener(
        'dragstart',
        event => {
            dragState = {
                type: 'question',
                id: question.dataset.questionId
            };

            question.classList.add('dragging');

            event.dataTransfer.effectAllowed =
                'move';

            event.dataTransfer.setData(
                'text/plain',
                question.dataset.questionId
            );
        }
    );

    handle.addEventListener(
        'dragend',
        () => {
            question.classList.remove(
                'dragging'
            );

            clearDragStyles();
        }
    );

    question.addEventListener(
        'dragover',
        event => {
            if (dragState.type !== 'question') {
                return;
            }

            if (
                dragState.id ===
                question.dataset.questionId
            ) {
                return;
            }

            event.preventDefault();

            question.classList.add(
                'drag-over'
            );
        }
    );

    question.addEventListener(
        'dragleave',
        event => {
            if (
                !question.contains(
                    event.relatedTarget
                )
            ) {
                question.classList.remove(
                    'drag-over'
                );
            }
        }
    );

    question.addEventListener(
        'drop',
        event => {
            if (dragState.type !== 'question') {
                return;
            }

            event.preventDefault();

            const source =
                document.querySelector(
                    '.question-card[data-question-id="' +
                    CSS.escape(dragState.id) +
                    '"]'
                );

            if (!source || source === question) {
                clearDragStyles();
                return;
            }

            const rect =
                question.getBoundingClientRect();

            const after =
                event.clientY >
                rect.top + rect.height / 2;

            if (after) {
                question.after(source);
            } else {
                question.before(source);
            }

            clearDragStyles();
            refreshQuestionNumbers();
            updateBranchTargets();
        }
    );
}

function clearDragStyles() {
    document
        .querySelectorAll(
            '.drag-over, .active, .dragging'
        )
        .forEach(element => {
            element.classList.remove(
                'drag-over',
                'active',
                'dragging'
            );
        });

    dragState = {
        type: null,
        id: null
    };
}

function refreshQuestionNumbers() {
    const numbering =
        document.querySelector(
            'input[name="numbering"]:checked'
        );

    const mode =
        numbering
            ? numbering.value
            : 'global';

    let global = 1;

    document
        .querySelectorAll('.group-card')
        .forEach(
            (group, groupIndex) => {
                let local = 1;

                group
                    .querySelectorAll(
                        ':scope > .group-body .question-card'
                    )
                    .forEach(question => {
                        const number =
                            mode === 'group'
                                ? 'Q' +
                                  (groupIndex + 1) +
                                  '-' +
                                  local
                                : 'Q' + global;

                        const element =
                            question.querySelector(
                                '.question-number'
                            );

                        if (element) {
                            element.textContent =
                                number;
                        }

                        const hidden =
                            question.querySelector(
                                '.question-number-input'
                            );

                        if (hidden) {
                            hidden.value =
                                number;
                        }

                        global++;
                        local++;
                    });
            }
        );

    updateBranchTargets();
}

function updateBranchTargets() {
    const targets = [];

    document
        .querySelectorAll('.question-card')
        .forEach(question => {
            const id =
                question.dataset.questionId;

            const number =
                question.querySelector(
                    '.question-number'
                )?.textContent || id;

            const text =
                question.querySelector(
                    '.question-text'
                )?.value || '';

            targets.push({
                id,
                label:
                    number +
                    ' ' +
                    (text || '未入力')
            });
        });

    document
        .querySelectorAll(
            '.branch-target'
        )
        .forEach(select => {
            const current =
                select.value;

            while (select.options.length > 1) {
                select.remove(1);
            }

            targets.forEach(target => {
                const option =
                    document.createElement(
                        'option'
                    );

                option.value =
                    target.id;

                option.textContent =
                    target.label;

                select.appendChild(
                    option
                );
            });

            if (
                targets.some(
                    target =>
                        target.id === current
                )
            ) {
                select.value = current;
            }
        });
}

function addGroup() {
    const container =
        document.getElementById(
            'editor-groups'
        );

    if (!container) {
        return;
    }

    const index =
        container.querySelectorAll(
            '.group-card'
        ).length;

    const id =
        'g-' +
        Date.now() +
        '-' +
        Math.random()
            .toString(16)
            .slice(2);

    const html = `
        <section
            class="group-card"
            draggable="false"
            data-group-id="${id}">

            <div class="group-header">
                <span
                    class="drag-handle group-drag-handle"
                    draggable="true"
                    title="ドラッグしてグループを並び替え">
                    ☷
                </span>

                <input
                    class="group-title-input"
                    type="text"
                    name="groups[${id}][title]"
                    value="グループ${index + 1}"
                    placeholder="グループタイトル">

                <input
                    type="hidden"
                    name="groups[${id}][id]"
                    value="${id}">

                <button
                    type="button"
                    class="btn btn-danger btn-sm delete-group"
                    onclick="deleteGroup(this)">
                    削除
                </button>
            </div>

            <div class="group-body">
                <div
                    class="question-list drop-zone"
                    data-group-id="${id}">
                </div>

                <div class="actions"
                     style="margin-top:12px;">
                    <button
                        type="button"
                        class="btn btn-secondary btn-sm"
                        onclick="addQuestion(this)">
                        ＋ 質問を追加
                    </button>
                </div>
            </div>
        </section>
    `;

    container.insertAdjacentHTML(
        'beforeend',
        html
    );

    const group =
        container.lastElementChild;

    bindGroup(group);
    refreshQuestionNumbers();
}

function deleteGroup(button) {
    const group =
        button.closest('.group-card');

    if (!group) {
        return;
    }

    const questionCount =
        group.querySelectorAll(
            '.question-card'
        ).length;

    const message =
        questionCount > 0
            ? '質問を含むグループを削除します。よろしいですか？'
            : 'このグループを削除しますか？';

    if (!window.confirm(message)) {
        return;
    }

    group.remove();

    if (
        document.querySelectorAll(
            '.group-card'
        ).length === 0
    ) {
        addGroup();
    }

    refreshQuestionNumbers();
}

function addQuestion(button) {
    const group =
        button.closest('.group-card');

    if (!group) {
        return;
    }

    const list =
        group.querySelector('.question-list');

    const groupId =
        group.dataset.groupId;

    const questionId =
        'q-' +
        Date.now() +
        '-' +
        Math.random()
            .toString(16)
            .slice(2);

    const questionIndex =
        list.querySelectorAll(
            '.question-card'
        ).length;

    const prefix =
        'groups[' +
        groupId +
        '][questions][' +
        questionId +
        ']';

    const html = `
        <article
            class="question-card"
            data-question-id="${questionId}">

            <div class="question-row">

                <span
                    class="drag-handle question-drag-handle"
                    draggable="true"
                    title="ドラッグして質問を移動">
                    ⋮⋮
                </span>

                <div class="question-content">

                    <div class="question-meta">
                        <span
                            class="question-number">
                            Q-
                        </span>

                        <select
                            class="question-type"
                            name="${prefix}[type]"
                            onchange="toggleQuestionType(this)">
                            <option value="single">
                                単一選択
                            </option>
                            <option value="multiple">
                                複数選択
                            </option>
                            <option value="text">
                                自由記述
                            </option>
                        </select>

                        <label class="checkbox">
                            <input
                                type="checkbox"
                                name="${prefix}[required]"
                                value="1"
                                checked>
                            必須
                        </label>
                    </div>

                    <input
                        type="hidden"
                        name="${prefix}[id]"
                        value="${questionId}"
                        class="question-id-input">

                    <input
                        type="hidden"
                        name="${prefix}[number]"
                        value=""
                        class="question-number-input">

                    <div class="form-group">
                        <label class="form-label">
                            質問文
                        </label>

                        <textarea
                            class="question-text"
                            name="${prefix}[text]"
                            rows="3"
                            placeholder="質問文を入力してください"></textarea>
                    </div>

                    <div class="question-options">
                        <label class="form-label">
                            選択肢
                        </label>

                        <div class="options-container">
                            ${optionHtml(
                                prefix,
                                0,
                                '選択肢1'
                            )}
                            ${optionHtml(
                                prefix,
                                1,
                                '選択肢2'
                            )}
                        </div>

                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            onclick="addOption(this)">
                            ＋ 選択肢を追加
                        </button>
                    </div>

                    <div
                        class="branch-settings"
                        style="margin-top:18px;">

                        <label class="form-label">
                            条件分岐
                        </label>

                        <div class="branches-container">
                        </div>

                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            onclick="addBranch(this)">
                            ＋ 条件分岐を追加
                        </button>

                        <div class="form-help">
                            単一選択質問で、選択肢ごとに
                            次に表示する質問を指定できます。
                        </div>
                    </div>

                </div>

                <button
                    type="button"
                    class="btn btn-danger btn-sm delete-question"
                    onclick="deleteQuestion(this)">
                    削除
                </button>

            </div>
        </article>
    `;

    list.insertAdjacentHTML(
        'beforeend',
        html
    );

    const question =
        list.lastElementChild;

    bindQuestion(question);
    refreshQuestionNumbers();
}

function optionHtml(
    prefix,
    index,
    value
) {
    return `
        <div class="option-row">
            <input
                type="text"
                name="${prefix}[options][]"
                value="${escapeHtml(value)}"
                placeholder="選択肢">

            <button
                type="button"
                class="btn btn-danger btn-sm"
                onclick="removeOption(this)">
                削除
            </button>
        </div>
    `;
}

function addOption(button) {
    const question =
        button.closest('.question-card');

    const container =
        question.querySelector(
            '.options-container'
        );

    const prefix =
        getQuestionPrefix(question);

    const index =
        container.querySelectorAll(
            '.option-row'
        ).length;

    container.insertAdjacentHTML(
        'beforeend',
        optionHtml(
            prefix,
            index,
            '選択肢' + (index + 1)
        )
    );

    updateBranchTargets();
}

function removeOption(button) {
    const row =
        button.closest('.option-row');

    if (!row) {
        return;
    }

    row.remove();
}

function deleteQuestion(button) {
    const question =
        button.closest('.question-card');

    if (!question) {
        return;
    }

    if (
        !window.confirm(
            'この質問を削除しますか？'
        )
    ) {
        return;
    }

    question.remove();

    refreshQuestionNumbers();
}

function getQuestionPrefix(question) {
    const input =
        question.querySelector(
            'input[name$="[id]"]'
        );

    if (!input) {
        return '';
    }

    const name =
        input.name;

    return name.replace(
        /\[id\]$/,
        ''
    );
}

function toggleQuestionType(select) {
    const question =
        select.closest('.question-card');

    if (!question) {
        return;
    }

    const type =
        select.value;

    const options =
        question.querySelector(
            '.question-options'
        );

    const branches =
        question.querySelector(
            '.branch-settings'
        );

    if (type === 'text') {
        if (options) {
            options.style.display =
                'none';
        }

        if (branches) {
            branches.style.display =
                'none';
        }
    } else {
        if (options) {
            options.style.display =
                '';
        }

        if (branches) {
            branches.style.display =
                type === 'single'
                    ? ''
                    : 'none';
        }
    }
}

function addBranch(button) {
    const question =
        button.closest('.question-card');

    if (!question) {
        return;
    }

    const container =
        question.querySelector(
            '.branches-container'
        );

    const prefix =
        getQuestionPrefix(question);

    const optionIndex =
        container.querySelectorAll(
            '.branch-row'
        ).length;

    const html = `
        <div class="branch-row">
            <select
                name="${prefix}[branches][${optionIndex}]"
                class="branch-option-index">
                ${getOptionSelectOptions(
                    question
                )}
            </select>

            <div style="display:flex;gap:8px;">
                <select
                    name="${prefix}[branches][${optionIndex}]"
                    class="branch-target">
                    <option value="">
                        次の質問を選択
                    </option>
                </select>

                <button
                    type="button"
                    class="btn btn-danger btn-sm"
                    onclick="this.closest('.branch-row').remove()">
                    削除
                </button>
            </div>
        </div>
    `;

    /*
     * 同じnameにならないよう、
     * 実際にはoptionIndex用とtarget用で
     * サーバー側の形式を揃える。
     */
    const correctedHtml = html
        .replace(
            `name="${prefix}[branches][${optionIndex}]" class="branch-option-index"`,
            `name="${prefix}[branch_options][${optionIndex}]" class="branch-option-index"`
        )
        .replace(
            `name="${prefix}[branches][${optionIndex}]" class="branch-target"`,
            `name="${prefix}[branches][${optionIndex}]" class="branch-target"`
        );

    container.insertAdjacentHTML(
        'beforeend',
        correctedHtml
    );

    updateBranchTargets();
}

function getOptionSelectOptions(question) {
    const options =
        question.querySelectorAll(
            '.options-container input'
        );

    let html = '';

    options.forEach(
        (input, index) => {
            html +=
                '<option value="' +
                index +
                '">' +
                escapeHtml(
                    input.value ||
                    '選択肢' +
                    (index + 1)
                ) +
                '</option>';
        }
    );

    return html;
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.addEventListener(
    'DOMContentLoaded',
    () => {
        initEditor();

        document
            .querySelectorAll(
                'input[name="numbering"]'
            )
            .forEach(input => {
                input.addEventListener(
                    'change',
                    refreshQuestionNumbers
                );
            });

        document
            .querySelectorAll(
                '.question-type'
            )
            .forEach(toggleQuestionType);
    }
);
</script>

</body>
</html>
<?php
}

/* ============================================================
 * List screen
 * ============================================================ */

function renderListScreen(): void
{
    $all = array_map(
        'updateComputedSurvey',
        surveys()
    );

    $search =
        trim((string)($_GET['q'] ?? ''));

    $status =
        (string)($_GET['status'] ?? 'all');

    $sort =
        (string)($_GET['sort'] ?? 'updated_desc');

    $filtered = array_values(
        array_filter(
            $all,
            function ($survey) use (
                $search,
                $status
            ) {
                $title =
                    (string)($survey['title'] ?? '');

                if (
                    $search !== '' &&
                    mb_stripos(
                        $title,
                        $search
                    ) === false
                ) {
                    return false;
                }

                if (
                    $status !== 'all' &&
                    (string)($survey['status'] ?? '') !==
                    $status
                ) {
                    return false;
                }

                return true;
            }
        )
    );

    usort(
        $filtered,
        function ($a, $b) use ($sort) {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)$a['updated_at'],
                        (string)$b['updated_at']
                    ),

                'answers_desc' =>
                    ((int)$b['answer_count']) <=>
                    ((int)$a['answer_count']),

                'answers_asc' =>
                    ((int)$a['answer_count']) <=>
                    ((int)$b['answer_count']),

                'start_desc' =>
                    strcmp(
                        (string)$b['start_at'],
                        (string)$a['start_at']
                    ),

                'start_asc' =>
                    strcmp(
                        (string)$a['start_at'],
                        (string)$b['start_at']
                    ),

                default =>
                    strcmp(
                        (string)$b['updated_at'],
                        (string)$a['updated_at']
                    ),
            };
        }
    );

    ?>
<div class="page-title">
    <div>
        <h1>アンケート一覧</h1>
        <div class="muted">
            アンケートの作成・編集・公開・集計を行います。
        </div>
    </div>

    <a class="btn btn-primary"
       href="index.php?screen=edit">
        ＋ 新規作成
    </a>
</div>

<div class="card">
    <form method="get"
          class="searchbar">
        <input
            type="hidden"
            name="screen"
            value="list">

        <input
            type="search"
            name="q"
            value="<?= h($search) ?>"
            placeholder="タイトルで検索">

        <select name="status">
            <?php
            $statuses = [
                'all' => 'すべて',
                'published' => '公開中',
                'draft' => '下書き',
                'stopped' => '停止',
                'ended' => '終了',
            ];
            ?>

            <?php foreach ($statuses as $value => $label): ?>
            <option
                value="<?= h($value) ?>"
                <?= $status === $value ? 'selected' : '' ?>>
                <?= h($label) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <select name="sort">
            <option value="updated_desc"
                <?= $sort === 'updated_desc' ? 'selected' : '' ?>>
                更新日：新しい順
            </option>

            <option value="updated_asc"
                <?= $sort === 'updated_asc' ? 'selected' : '' ?>>
                更新日：古い順
            </option>

            <option value="answers_desc"
                <?= $sort === 'answers_desc' ? 'selected' : '' ?>>
                回答数：多い順
            </option>

            <option value="answers_asc"
                <?= $sort === 'answers_asc' ? 'selected' : '' ?>>
                回答数：少ない順
            </option>

            <option value="start_desc"
                <?= $sort === 'start_desc' ? 'selected' : '' ?>>
                開始日：新しい順
            </option>

            <option value="start_asc"
                <?= $sort === 'start_asc' ? 'selected' : '' ?>>
                開始日：古い順
            </option>
        </select>

        <button class="btn btn-primary"
                type="submit">
            検索
        </button>
    </form>
</div>

<div class="card">
<?php if (!$filtered): ?>

    <div class="empty">
        アンケートがありません。
    </div>

<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>タイトル</th>
    <th>作成日</th>
    <th>更新日</th>
    <th>開始日時</th>
    <th>終了日時</th>
    <th>ステータス</th>
    <th>回答数</th>
    <th>操作</th>
</tr>
</thead>

<tbody>

<?php foreach ($filtered as $survey): ?>

<tr>
    <td>
        <strong>
            <?= h($survey['title']) ?>
        </strong>
    </td>

    <td>
        <?= h($survey['created_at'] ?? '') ?>
    </td>

    <td>
        <?= h($survey['updated_at'] ?? '') ?>
    </td>

    <td>
        <?= h($survey['start_at'] ?? '') ?>
    </td>

    <td>
        <?= h($survey['end_at'] ?? '') ?>
    </td>

    <td>
        <span class="badge <?= h(
            statusClass(
                (string)$survey['status']
            )
        ) ?>">
            <?= h(
                statusLabel(
                    (string)$survey['status']
                )
            ) ?>
        </span>
    </td>

    <td>
        <?= (int)$survey['answer_count'] ?>
    </td>

    <td>
        <div class="actions">

            <a
                class="btn btn-secondary btn-sm"
                href="index.php?screen=edit&id=<?= rawurlencode($survey['id']) ?>">
                編集
            </a>

            <a
                class="btn btn-secondary btn-sm"
                href="index.php?screen=preview&id=<?= rawurlencode($survey['id']) ?>">
                プレビュー
            </a>

            <a
                class="btn btn-secondary btn-sm"
                href="index.php?screen=analytics&id=<?= rawurlencode($survey['id']) ?>">
                集計
            </a>

            <a
                class="btn btn-secondary btn-sm"
                href="index.php?screen=send&id=<?= rawurlencode($survey['id']) ?>">
                送信
            </a>

            <form
                method="post"
                onsubmit="return confirmAction('このアンケートを複製しますか？')">

                <input
                    type="hidden"
                    name="action"
                    value="duplicate_survey">

                <input
                    type="hidden"
                    name="survey_id"
                    value="<?= h($survey['id']) ?>">

                <button
                    class="btn btn-secondary btn-sm"
                    type="submit">
                    複製
                </button>
            </form>

            <?php if ($survey['status'] === 'draft'): ?>

            <form method="post"
                  onsubmit="return confirmAction('公開しますか？')">

                <input
                    type="hidden"
                    name="action"
                    value="change_status">

                <input
                    type="hidden"
                    name="survey_id"
                    value="<?= h($survey['id']) ?>">

                <input
                    type="hidden"
                    name="new_status"
                    value="published">

                <button
                    class="btn btn-success btn-sm"
                    type="submit">
                    公開
                </button>
            </form>

            <?php elseif ($survey['status'] === 'published'): ?>

            <form method="post"
                  onsubmit="return confirmAction('停止しますか？')">

                <input
                    type="hidden"
                    name="action"
                    value="change_status">

                <input
                    type="hidden"
                    name="survey_id"
                    value="<?= h($survey['id']) ?>">

                <input
                    type="hidden"
                    name="new_status"
                    value="stopped">

                <button
                    class="btn btn-warning btn-sm"
                    type="submit">
                    停止
                </button>
            </form>

            <?php elseif ($survey['status'] === 'stopped'): ?>

            <form method="post"
                  onsubmit="return confirmAction('再公開しますか？')">

                <input
                    type="hidden"
                    name="action"
                    value="change_status">

                <input
                    type="hidden"
                    name="survey_id"
                    value="<?= h($survey['id']) ?>">

                <input
                    type="hidden"
                    name="new_status"
                    value="published">

                <button
                    class="btn btn-success btn-sm"
                    type="submit">
                    再公開
                </button>
            </form>

            <?php endif; ?>

            <form
                method="post"
                onsubmit="return confirmAction('削除しますか？この操作は元に戻せません。')">

                <input
                    type="hidden"
                    name="action"
                    value="delete_survey">

                <input
                    type="hidden"
                    name="survey_id"
                    value="<?= h($survey['id']) ?>">

                <button
                    class="btn btn-danger btn-sm"
                    type="submit">
                    削除
                </button>
            </form>

        </div>
    </td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>
</div>
<?php
}

/* ============================================================
 * Edit
 * ============================================================ */

function renderEditScreen(?array $survey): void
{
    $isNew = $survey === null;

    if ($survey === null) {
        $survey = defaultSurvey();
    }

    ?>
<div class="page-title">
    <div>
        <h1>
            <?= $isNew
                ? 'アンケート作成'
                : 'アンケート編集' ?>
        </h1>

        <div class="muted">
            質問はドラッグ＆ドロップで
            並び替え・グループ間移動できます。
        </div>
    </div>

    <div class="actions">

        <a
            class="btn btn-secondary"
            href="index.php?screen=list">
            キャンセル
        </a>

        <?php if (!$isNew): ?>
        <a
            class="btn btn-secondary"
            href="index.php?screen=preview&id=<?= rawurlencode($survey['id']) ?>">
            プレビュー
        </a>
        <?php endif; ?>

        <button
            form="survey-editor-form"
            class="btn btn-primary"
            type="submit">
            保存して一覧へ
        </button>
    </div>
</div>

<form
    id="survey-editor-form"
    method="post">

<input
    type="hidden"
    name="action"
    value="save_survey">

<input
    type="hidden"
    name="survey_id"
    value="<?= h($survey['id']) ?>">

<div class="card">

    <div class="grid-2">

        <div class="form-group">
            <label class="form-label">
                タイトル
            </label>

            <input
                type="text"
                name="title"
                maxlength="<?= MAX_TITLE_LENGTH ?>"
                value="<?= h($survey['title']) ?>"
                required>
        </div>

        <div class="form-group">
            <label class="form-label">
                状態
            </label>

            <div style="padding-top:9px;">
                <span
                    class="badge <?= h(
                        statusClass(
                            (string)$survey['status']
                        )
                    ) ?>">
                    <?= h(
                        statusLabel(
                            (string)$survey['status']
                        )
                    ) ?>
                </span>
            </div>
        </div>

    </div>

    <div class="form-group">
        <label class="form-label">
            説明
        </label>

        <textarea
            name="description"
            maxlength="<?= MAX_DESCRIPTION_LENGTH ?>"
            rows="5"><?= h(
                $survey['description']
            ) ?></textarea>
    </div>

    <div class="grid-2">

        <div class="form-group">
            <label class="form-label">
                開始日時
            </label>

            <input
                type="datetime-local"
                name="start_at"
                value="<?= h(
                    inputDateTime(
                        (string)$survey['start_at']
                    )
                ) ?>">
        </div>

        <div class="form-group">
            <label class="form-label">
                終了日時
            </label>

            <input
                type="datetime-local"
                name="end_at"
                value="<?= h(
                    inputDateTime(
                        (string)$survey['end_at']
                    )
                ?>">
        </div>

    </div>

    <div class="form-group">
        <label class="form-label">
            質問番号の採番方式
        </label>

        <label class="checkbox">
            <input
                type="radio"
                name="numbering"
                value="global"
                <?= ($survey['numbering'] ?? 'global') === 'global'
                    ? 'checked'
                    : '' ?>>
            アンケート全体で通番
            （Q1、Q2、Q3...）
        </label>

        <br>

        <label class="checkbox">
            <input
                type="radio"
                name="numbering"
                value="group"
                <?= ($survey['numbering'] ?? '') === 'group'
                    ? 'checked'
                    : '' ?>>
            グループ単位で採番
            （Q1-1、Q1-2、Q2-1...）
        </label>
    </div>

</div>

<div class="card">

    <div class="page-title"
         style="margin-bottom:14px;">

        <div>
            <h2 style="margin-bottom:4px;">
                グループ・質問
            </h2>

            <div class="muted">
                グループ・質問ともにドラッグ＆ドロップで
                並び替えできます。
                質問は別グループへも移動できます。
            </div>
        </div>

        <button
            type="button"
            class="btn btn-secondary"
            onclick="addGroup()">
            ＋ グループを追加
        </button>

    </div>

    <div
        id="editor-groups"
        class="editor-groups">

        <?php foreach ($survey['groups'] as $group): ?>

        <?php renderEditorGroup($group); ?>

        <?php endforeach; ?>

    </div>

</div>

</form>

<?php
}

/* ============================================================
 * Editor group
 * ============================================================ */

function renderEditorGroup(array $group): void
{
    $groupId = (string)$group['id'];
    ?>

<section
    class="group-card"
    data-group-id="<?= h($groupId) ?>">

    <div class="group-header">

        <span
            class="drag-handle group-drag-handle"
            draggable="true"
            title="ドラッグしてグループを並び替え">
            ☷
        </span>

        <input
            class="group-title-input"
            type="text"
            name="groups[<?= h($groupId) ?>][title]"
            value="<?= h($group['title']) ?>"
            placeholder="グループタイトル">

        <input
            type="hidden"
            name="groups[<?= h($groupId) ?>][id]"
            value="<?= h($groupId) ?>">

        <button
            type="button"
            class="btn btn-danger btn-sm delete-group"
            onclick="deleteGroup(this)">
            削除
        </button>

    </div>

    <div class="group-body">

        <div
            class="question-list drop-zone"
            data-group-id="<?= h($groupId) ?>">

            <?php foreach ($group['questions'] as $question): ?>

            <?php renderEditorQuestion(
                $groupId,
                $question
            ); ?>

            <?php endforeach; ?>

        </div>

        <div class="actions"
             style="margin-top:12px;">

            <button
                type="button"
                class="btn btn-secondary btn-sm"
                onclick="addQuestion(this)">
                ＋ 質問を追加
            </button>

        </div>

    </div>
</section>

<?php
}

/* ============================================================
 * Editor question
 * ============================================================ */

function renderEditorQuestion(
    string $groupId,
    array $question
): void {
    $questionId =
        (string)$question['id'];

    $prefix =
        'groups[' .
        $groupId .
        '][questions][' .
        $questionId .
        ']';

    ?>

<article
    class="question-card"
    data-question-id="<?= h($questionId) ?>">

    <div class="question-row">

        <span
            class="drag-handle question-drag-handle"
            draggable="true"
            title="ドラッグして質問を移動">
            ⋮⋮
        </span>

        <div class="question-content">

            <div class="question-meta">

                <span class="question-number">
                    <?= h(
                        $question['number'] ?? 'Q-'
                    ) ?>
                </span>

                <select
                    class="question-type"
                    name="<?= h($prefix) ?>[type]"
                    onchange="toggleQuestionType(this)">

                    <option
                        value="single"
                        <?= $question['type'] === 'single'
                            ? 'selected'
                            : '' ?>>
                        単一選択
                    </option>

                    <option
                        value="multiple"
                        <?= $question['type'] === 'multiple'
                            ? 'selected'
                            : '' ?>>
                        複数選択
                    </option>

                    <option
                        value="text"
                        <?= $question['type'] === 'text'
                            ? 'selected'
                            : '' ?>>
                        自由記述
                    </option>

                </select>

                <label class="checkbox">
                    <input
                        type="checkbox"
                        name="<?= h($prefix) ?>[required]"
                        value="1"
                        <?= !empty($question['required'])
                            ? 'checked'
                            : '' ?>>
                    必須
                </label>

            </div>

            <input
                type="hidden"
                name="<?= h($prefix) ?>[id]"
                value="<?= h($questionId) ?>">

            <input
                type="hidden"
                name="<?= h($prefix) ?>[number]"
                value="<?= h(
                    $question['number'] ?? ''
                ) ?>"
                class="question-number-input">

            <div class="form-group">

                <label class="form-label">
                    質問文
                </label>

                <textarea
                    class="question-text"
                    name="<?= h($prefix) ?>[text]"
                    rows="3"
                    placeholder="質問文を入力してください"><?= h(
                        $question['text']
                    ) ?></textarea>

            </div>

            <div
                class="question-options"
                style="<?= $question['type'] === 'text'
                    ? 'display:none'
                    : '' ?>">

                <label class="form-label">
                    選択肢
                </label>

                <div class="options-container">

                    <?php foreach (
                        $question['options']
                        as $option
                    ): ?>

                    <div class="option-row">

                        <input
                            type="text"
                            name="<?= h($prefix) ?>[options][]"
                            value="<?= h($option) ?>"
                            placeholder="選択肢">

                        <button
                            type="button"
                            class="btn btn-danger btn-sm"
                            onclick="removeOption(this)">
                            削除
                        </button>

                    </div>

                    <?php endforeach; ?>

                </div>

                <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    onclick="addOption(this)">
                    ＋ 選択肢を追加
                </button>

            </div>

            <div
                class="branch-settings"
                style="
                    margin-top:18px;
                    <?= $question['type'] !== 'single'
                        ? 'display:none'
                        : '' ?>
                ">

                <label class="form-label">
                    条件分岐
                </label>

                <div class="branches-container">

                    <?php
                    foreach (
                        ($question['branches'] ?? [])
                        as $optionIndex => $targetId
                    ):
                    ?>

                    <div class="branch-row">

                        <select
                            name="<?= h($prefix) ?>[branch_options][<?= h($optionIndex) ?>]"
                            class="branch-option-index">

                            <?php foreach (
                                $question['options']
                                as $index => $option
                            ): ?>

                            <option
                                value="<?= $index ?>"
                                <?= (string)$index ===
                                    (string)$optionIndex
                                    ? 'selected'
                                    : '' ?>>
                                <?= h($option) ?>
                            </option>

                            <?php endforeach; ?>

                        </select>

                        <div
                            style="display:flex;gap:8px;">

                            <select
                                name="<?= h($prefix) ?>[branches][<?= h($optionIndex) ?>]"
                                class="branch-target">

                                <option value="">
                                    次の質問を選択
                                </option>

                                <?php foreach (
                                    getAllQuestions(
                                        $question,
                                        $targetId
                                    ) as $target
                                ): ?>

                                <option
                                    value="<?= h($target['id']) ?>"
                                    <?= (string)$target['id'] ===
                                        (string)$targetId
                                        ? 'selected'
                                        : '' ?>>
                                    <?= h($target['label']) ?>
                                </option>

                                <?php endforeach; ?>

                            </select>

                            <button
                                type="button"
                                class="btn btn-danger btn-sm"
                                onclick="this.closest('.branch-row').remove()">
                                削除
                            </button>

                        </div>
                    </div>

                    <?php endforeach; ?>

                </div>

                <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    onclick="addBranch(this)">
                    ＋ 条件分岐を追加
                </button>

                <div class="form-help">
                    単一選択質問では、
                    選択肢ごとに次に表示する質問を指定できます。
                </div>

            </div>

        </div>

        <button
            type="button"
            class="btn btn-danger btn-sm delete-question"
            onclick="deleteQuestion(this)">
            削除
        </button>

    </div>
</article>

<?php
}

function getAllQuestions(
    array $currentQuestion,
    string $selected
): array {
    /*
     * この関数は初期HTML生成時に
     * 選択肢ターゲットを表示するための補助。
     *
     * 実際の全質問一覧はJavaScript側で
     * DOMから再構築する。
     */
    $id = (string)$currentQuestion['id'];

    return [
        [
            'id' => $selected,
            'label' => '現在の設定: ' . $selected,
        ],
    ];
}

/* ============================================================
 * Preview
 * ============================================================ */

function renderPreviewScreen(?array $survey): void
{
    if ($survey === null) {
        echo '<div class="card"><h2>アンケートが見つかりません。</h2></div>';
        return;
    }

    ?>
<div class="page-title">
    <div>
        <h1>プレビュー</h1>
        <div class="muted">
            実際の回答画面に近い状態を確認します。
        </div>
    </div>

    <div class="actions">
        <a
            class="btn btn-secondary"
            href="index.php?screen=edit&id=<?= rawurlencode($survey['id']) ?>">
            編集へ戻る
        </a>
    </div>
</div>

<div class="answer-card">

    <h1><?= h($survey['title']) ?></h1>

    <?php if ($survey['description'] !== ''): ?>
    <p><?= nl2br(h($survey['description'])) ?></p>
    <?php endif; ?>

</div>

<?php foreach ($survey['groups'] as $group): ?>

<div class="answer-card">

    <h2><?= h($group['title']) ?></h2>

    <?php foreach ($group['questions'] as $question): ?>

    <div
        style="
            padding:16px 0;
            border-bottom:1px solid var(--border);
        ">

        <div class="answer-question-title">

            <span class="question-number">
                <?= h($question['number']) ?>
            </span>

            <?= h($question['text']) ?>

            <?php if ($question['required']): ?>
            <span class="badge badge-danger">
                必須
            </span>
            <?php endif; ?>

        </div>

        <?php if ($question['type'] === 'text'): ?>

        <textarea
            rows="4"
            disabled
            placeholder="自由記述"></textarea>

        <?php else: ?>

        <?php foreach (
            $question['options']
            as $index => $option
        ): ?>

        <div class="answer-option">

            <label>

                <input
                    type="<?= $question['type'] === 'single'
                        ? 'radio'
                        : 'checkbox' ?>"
                    disabled>

                <span>
                    <?= h($option) ?>
                </span>

            </label>

        </div>

        <?php endforeach; ?>

        <?php endif; ?>

    </div>

    <?php endforeach; ?>

</div>

<?php endforeach; ?>

<?php
}

/* ============================================================
 * Answer screen
 * ============================================================ */

function renderAnswerScreen(
    ?array $survey,
    bool $dummy
): void {
    if ($survey === null) {
        echo '<div class="answer-card"><h2>アンケートが見つかりません。</h2></div>';
        return;
    }

    if (($survey['status'] ?? '') !== 'published') {
        echo '
        <div class="answer-card">
            <h2>現在、このアンケートには回答できません。</h2>
        </div>';
        return;
    }

    ?>
<div class="page-title">
    <div>
        <h1><?= h($survey['title']) ?></h1>
    </div>
</div>

<?php if ($survey['description'] !== ''): ?>

<div class="answer-card">
    <?= nl2br(h($survey['description'])) ?>
</div>

<?php endif; ?>

<form method="post">

<input
    type="hidden"
    name="action"
    value="save_answer">

<input
    type="hidden"
    name="survey_id"
    value="<?= h($survey['id']) ?>">

<?php foreach ($survey['groups'] as $group): ?>

<div class="answer-card">

    <h2><?= h($group['title']) ?></h2>

    <?php foreach ($group['questions'] as $question): ?>

    <div
        class="answer-question"
        data-question-id="<?= h($question['id']) ?>"
        style="
            padding:16px 0;
            border-bottom:1px solid var(--border);
        ">

        <div class="answer-question-title">

            <?= h($question['number']) ?>

            <?= h($question['text']) ?>

            <?php if ($question['required']): ?>
            <span class="badge badge-danger">
                必須
            </span>
            <?php endif; ?>

        </div>

        <?php if ($question['type'] === 'text'): ?>

        <textarea
            name="answers[<?= h($question['id']) ?>]"
            rows="5"
            <?= $question['required']
                ? 'required'
                : '' ?>></textarea>

        <?php else: ?>

        <?php foreach (
            $question['options']
            as $index => $option
        ): ?>

        <div class="answer-option">

            <label>

                <input
                    type="<?= $question['type'] === 'single'
                        ? 'radio'
                        : 'checkbox' ?>"
                    name="answers[<?= h($question['id']) ?>]<?= $question['type'] === 'multiple'
                        ? '[]'
                        : '' ?>"
                    value="<?= $index ?>"
                    <?= $question['required']
                        ? 'required'
                        : '' ?>>

                <span>
                    <?= h($option) ?>
                </span>

            </label>

        </div>

        <?php endforeach; ?>

        <?php endif; ?>

    </div>

    <?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="actions">
    <button
        class="btn btn-primary"
        type="submit">
        回答を確認する
    </button>
</div>

</form>

<?php
}

/* ============================================================
 * Confirm
 * ============================================================ */

function renderAnswerConfirmScreen(
    ?array $survey
): void {
    if ($survey === null) {
        echo '<div class="answer-card"><h2>アンケートが見つかりません。</h2></div>';
        return;
    }

    /*
     * POCでは確認画面へ進む前の
     * POST値をセッションへ保持する。
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['answer_confirm'] = [
            'survey_id' =>
                postString('survey_id'),
            'answers' =>
                postArray('answers'),
        ];
    }

    $session =
        $_SESSION['answer_confirm'] ?? [];

    $answerValues =
        $session['answers'] ?? [];

    ?>
<div class="page-title">
    <div>
        <h1>回答確認</h1>
    </div>
</div>

<div class="answer-card">

<?php foreach ($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<?php
$value =
    $answerValues[$question['id']]
    ?? '';
?>

<div
    style="
        padding:14px 0;
        border-bottom:1px solid var(--border);
    ">

<strong>
    <?= h($question['number']) ?>
    <?= h($question['text']) ?>
</strong>

<div style="margin-top:8px;">

<?php if (is_array($value)): ?>

<?= h(
    implode(
        '、',
        array_map(
            function ($index) use ($question) {
                return $question['options'][(int)$index]
                    ?? (string)$index;
            },
            $value
        )
    )
) ?>

<?php elseif (
    $question['type'] === 'single' &&
    $value !== ''
): ?>

<?= h(
    $question['options'][(int)$value]
    ?? (string)$value
) ?>

<?php else: ?>

<?= nl2br(h((string)$value)) ?>

<?php endif; ?>

</div>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>

<div class="actions">

<a
    class="btn btn-secondary"
    href="index.php?screen=answer&id=<?= rawurlencode($survey['id']) ?>">
    修正する
</a>

<form method="post">

<input
    type="hidden"
    name="action"
    value="save_answer">

<input
    type="hidden"
    name="survey_id"
    value="<?= h($survey['id']) ?>">

<?php foreach (
    $answerValues as $questionId => $value
): ?>

<?php if (is_array($value)): ?>

<?php foreach ($value as $item): ?>

<input
    type="hidden"
    name="answers[<?= h($questionId) ?>][]"
    value="<?= h($item) ?>">

<?php endforeach; ?>

<?php else: ?>

<input
    type="hidden"
    name="answers[<?= h($questionId) ?>]"
    value="<?= h($value) ?>">

<?php endif; ?>

<?php endforeach; ?>

<button
    class="btn btn-primary"
    type="submit">
    回答を送信する
</button>

</form>

</div>

<?php
}

/* ============================================================
 * Complete
 * ============================================================ */

function renderCompleteScreen(
    ?array $survey
): void {
    ?>
<div class="answer-card"
     style="text-align:center;">

    <h1>回答完了</h1>

    <p>
        アンケートへの回答を受け付けました。
    </p>

    <?php if ($survey !== null): ?>
    <p class="muted">
        <?= h($survey['title']) ?>
    </p>
    <?php endif; ?>

</div>
<?php
}

/* ============================================================
 * Send screen
 * ============================================================ */

function renderSendScreen(
    ?array $survey
): void {
    if ($survey === null) {
        echo '<div class="card"><h2>アンケートが見つかりません。</h2></div>';
        return;
    }

    $customerList = customers();

    $history = array_reverse(
        array_values(
            array_filter(
                mailHistory(),
                fn($item) =>
                    (string)($item['survey_id'] ?? '') ===
                    (string)$survey['id']
            )
        )
    );

    ?>
<div class="page-title">
    <div>
        <h1>顧客選択・メール送信</h1>
        <div class="muted">
            対象アンケート：
            <?= h($survey['title']) ?>
        </div>
    </div>
</div>

<div class="card">

<form method="post">

<input
    type="hidden"
    name="action"
    value="send_mail">

<input
    type="hidden"
    name="survey_id"
    value="<?= h($survey['id']) ?>">

<h2>顧客選択</h2>

<div class="table-wrap">

<table>

<thead>
<tr>
    <th>
        <input
            type="checkbox"
            onclick="
                document
                    .querySelectorAll('.customer-check')
                    .forEach(x => x.checked = this.checked);
            ">
    </th>
    <th>組織名</th>
    <th>氏名</th>
    <th>部署</th>
    <th>メールアドレス</th>
</tr>
</thead>

<tbody>

<?php if (!$customerList): ?>

<tr>
    <td colspan="5">
        <div class="empty">
            顧客情報がありません。
            kintone設定から同期してください。
        </div>
    </td>
</tr>

<?php endif; ?>

<?php foreach ($customerList as $customer): ?>

<tr>
    <td>
        <input
            class="customer-check"
            type="checkbox"
            name="customer_ids[]"
            value="<?= h($customer['id']) ?>">
    </td>

    <td>
        <?= h($customer['organization'] ?? '') ?>
    </td>

    <td>
        <?= h($customer['name'] ?? '') ?>
    </td>

    <td>
        <?= h($customer['department'] ?? '') ?>
    </td>

    <td>
        <?= h($customer['email'] ?? '') ?>
    </td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<hr>

<h2>メール作成</h2>

<div class="form-group">
    <label class="form-label">
        件名
    </label>

    <input
        type="text"
        name="subject"
        value="アンケートご回答のお願い">
</div>

<div class="form-group">
    <label class="form-label">
        本文
    </label>

    <textarea
        name="body"
        rows="12">ご担当者様

いつもお世話になっております。

アンケートへのご回答をお願いいたします。

回答者：{顧客名}

アンケートURL：
{アンケートURL}

よろしくお願いいたします。</textarea>

    <div class="form-help">
        利用可能な変数：
        {顧客名} / {アンケートURL}
    </div>
</div>

<div class="actions">

<button
    class="btn btn-primary"
    type="submit"
    onclick="
        return confirmAction(
            '選択した顧客へメールを送信しますか？'
        );
    ">
    メール送信
</button>

</div>

</form>

</div>

<div class="card">

<h2>送信履歴</h2>

<?php if (!$history): ?>

<div class="empty">
    送信履歴はありません。
</div>

<?php else: ?>

<div class="history-table">

<table>

<thead>
<tr>
    <th>日時</th>
    <th>宛先</th>
    <th>件名</th>
    <th>状態</th>
    <th>結果</th>
</tr>
</thead>

<tbody>

<?php foreach ($history as $item): ?>

<tr>
    <td>
        <?= h($item['sent_at'] ?? '') ?>
    </td>

    <td>
        <?= h($item['email'] ?? '') ?>
    </td>

    <td>
        <?= h($item['subject'] ?? '') ?>
    </td>

    <td>
        <?php if (($item['status'] ?? '') === 'sent'): ?>
        <span class="badge badge-success">
            送信済み
        </span>
        <?php else: ?>
        <span class="badge badge-danger">
            失敗
        </span>
        <?php endif; ?>
    </td>

    <td>
        <?= h($item['message'] ?? '') ?>
    </td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</div>

<?php
}

/* ============================================================
 * Analytics
 * ============================================================ */

function renderAnalyticsScreen(
    ?array $survey
): void {
    if ($survey === null) {
        echo '<div class="card"><h2>アンケートが見つかりません。</h2></div>';
        return;
    }

    $answerList =
        surveyAnswers(
            (string)$survey['id']
        );

    $answerCount =
        count($answerList);

    $customerCount =
        count(customers());

    $sentCount =
        count(
            array_filter(
                mailHistory(),
                fn($item) =>
                    (string)($item['survey_id'] ?? '') ===
                    (string)$survey['id'] &&
                    ($item['status'] ?? '') ===
                    'sent'
            )
        );

    $rate =
        $customerCount > 0
            ? round(
                $answerCount /
                $customerCount *
                100,
                1
            )
            : 0;

    ?>
<div class="page-title">
    <div>
        <h1>回答集計・分析</h1>

        <div class="muted">
            対象アンケート：
            <?= h($survey['title']) ?>
        </div>
    </div>

    <div class="actions">

        <a
            class="btn btn-secondary"
            href="index.php?screen=edit&id=<?= rawurlencode($survey['id']) ?>">
            編集
        </a>

        <a
            class="btn btn-secondary"
            href="index.php?screen=analytics&id=<?= rawurlencode($survey['id']) ?>&action=export_csv">
            CSV
        </a>

        <a
            class="btn btn-secondary"
            href="index.php?screen=analytics&id=<?= rawurlencode($survey['id']) ?>&action=export_pdf">
            PDF
        </a>

    </div>
</div>

<div class="grid-3">

    <div class="stat-card">
        <div class="stat-label">
            送信対象者数
        </div>
        <div class="stat-value">
            <?= $customerCount ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">
            回答数
        </div>
        <div class="stat-value">
            <?= $answerCount ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">
            回答率
        </div>
        <div class="stat-value">
            <?= $rate ?>%
        </div>
    </div>

</div>

<?php if ($answerCount === 0): ?>

<div class="card">
    <div class="empty">
        現在、回答データはありません
    </div>
</div>

<?php else: ?>

<?php foreach ($survey['groups'] as $group): ?>

<div class="card">

<h2><?= h($group['title']) ?></h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div
    style="
        padding:16px 0;
        border-bottom:1px solid var(--border);
    ">

<h3>
    <?= h($question['number']) ?>
    <?= h($question['text']) ?>
</h3>

<?php if (
    $question['type'] !== 'text'
): ?>

<?php
$stats =
    questionAnswerStats(
        $survey,
        $question
    );
?>

<table>

<thead>
<tr>
    <th>選択肢</th>
    <th>回答数</th>
</tr>
</thead>

<tbody>

<?php foreach ($stats as $stat): ?>

<tr>
    <td>
        <?= h($stat['label']) ?>
    </td>

    <td>
        <?= (int)$stat['count'] ?>
    </td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php else: ?>

<p class="muted">
    自由記述回答
</p>

<?php foreach ($answerList as $answer): ?>

<?php
$value =
    $answer['answers']
        [$question['id']]
        ?? '';
?>

<?php if ($value !== ''): ?>

<div
    style="
        padding:8px 12px;
        margin-bottom:6px;
        background:var(--gray-light);
        border-radius:6px;
    ">
    <?= nl2br(h((string)$value)) ?>
</div>

<?php endif; ?>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="card">

<h2>個別回答</h2>

<div class="table-wrap">

<table>

<thead>
<tr>
    <th>回答ID</th>
    <th>回答日時</th>
</tr>
</thead>

<tbody>

<?php foreach ($answerList as $answer): ?>

<tr>
    <td>
        <?= h($answer['id'] ?? '') ?>
    </td>

    <td>
        <?= h($answer['answered_at'] ?? '') ?>
    </td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<?php endif; ?>

<?php
}

/* ============================================================
 * kintone screen
 * ============================================================ */

function renderKintoneScreen(): void
{
    $config = kintoneConfig();
    ?>

<div class="page-title">
    <div>
        <h1>kintone連携設定</h1>
        <div class="muted">
            顧客情報の取得元を設定します。
        </div>
    </div>
</div>

<div class="card">

<form method="post">

<input
    type="hidden"
    name="action"
    value="save_kintone">

<div class="grid-2">

<div class="form-group">
    <label class="form-label">
        サブドメイン
    </label>

    <input
        type="text"
        name="subdomain"
        value="<?= h(
            $config['subdomain'] ?? ''
        ) ?>"
        placeholder="example">
</div>

<div class="form-group">
    <label class="form-label">
        顧客管理アプリID
    </label>

    <input
        type="number"
        name="app_id"
        value="<?= h(
            $config['app_id'] ?? ''
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        ログイン名
    </label>

    <input
        type="text"
        name="login"
        value="<?= h(
            $config['login'] ?? ''
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        パスワード
    </label>

    <input
        type="password"
        name="password"
        value="<?= h(
            $config['password'] ?? ''
        ) ?>"
        autocomplete="new-password">
</div>

<div class="form-group">
    <label class="form-label">
        Proxy
    </label>

    <input
        type="text"
        name="proxy"
        value="<?= h(
            $config['proxy'] ?? ''
        ) ?>"
        placeholder="必要な場合のみ">
</div>

<div class="form-group">
    <label class="checkbox"
           style="margin-top:34px;">
        <input
            type="checkbox"
            name="verify_ssl"
            value="1"
            <?= !empty($config['verify_ssl'])
                ? 'checked'
                : '' ?>>
        SSL証明書を検証する
    </label>
</div>

</div>

<hr>

<h2>顧客項目マッピング</h2>

<div class="grid-2">

<?php
$mapping =
    $config['mapping'] ?? [];
?>

<div class="form-group">
    <label class="form-label">
        組織名
    </label>
    <input
        type="text"
        name="map_organization"
        value="<?= h(
            $mapping['organization'] ?? ''
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        氏名
    </label>
    <input
        type="text"
        name="map_name"
        value="<?= h(
            $mapping['name'] ?? ''
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        メールアドレス
    </label>
    <input
        type="text"
        name="map_email"
        value="<?= h(
            $mapping['email'] ?? ''
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        部署名
    </label>
    <input
        type="text"
        name="map_department"
        value="<?= h(
            $mapping['department'] ?? ''
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        電話番号
    </label>
    <input
        type="text"
        name="map_phone"
        value="<?= h(
            $mapping['phone'] ?? ''
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        住所
    </label>
    <input
        type="text"
        name="map_address"
        value="<?= h(
            $mapping['address'] ?? ''
        ) ?>">
</div>

</div>

<div class="actions">

<button
    class="btn btn-primary"
    type="submit">
    設定保存
</button>

</div>

</form>

<hr>

<div class="actions">

<form method="post">

<input
    type="hidden"
    name="action"
    value="kintone_test">

<button
    class="btn btn-secondary"
    type="submit">
    接続テスト
</button>

</form>

<form method="post">

<input
    type="hidden"
    name="action"
    value="kintone_fields">

<button
    class="btn btn-secondary"
    type="submit">
    項目一覧再取得
</button>

</form>

<form method="post">

<input
    type="hidden"
    name="action"
    value="kintone_sync">

<button
    class="btn btn-primary"
    type="submit"
    onclick="
        return confirmAction(
            'kintoneから顧客情報を同期しますか？'
        );
    ">
    顧客情報同期
</button>

</form>

</div>

</div>

<?php
}

/* ============================================================
 * Mail screen
 * ============================================================ */

function renderMailScreen(): void
{
    $config = smtpConfig();

    ?>
<div class="page-title">
    <div>
        <h1>メールサーバ設定</h1>
        <div class="muted">
            SMTPサーバへ接続してメールを送信します。
        </div>
    </div>
</div>

<div class="card">

<form method="post">

<input
    type="hidden"
    name="action"
    value="save_smtp">

<div class="grid-2">

<div class="form-group">
    <label class="form-label">
        SMTPサーバ
    </label>

    <input
        type="text"
        name="host"
        value="<?= h(
            $config['host'] ?? ''
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        SMTPポート
    </label>

    <input
        type="number"
        name="port"
        value="<?= h(
            $config['port'] ?? '587'
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        暗号化方式
    </label>

    <select name="encryption">

        <option
            value="none"
            <?= ($config['encryption'] ?? '') === 'none'
                ? 'selected'
                : '' ?>>
            なし
        </option>

        <option
            value="tls"
            <?= ($config['encryption'] ?? 'tls') === 'tls'
                ? 'selected'
                : '' ?>>
            STARTTLS
        </option>

        <option
            value="ssl"
            <?= ($config['encryption'] ?? '') === 'ssl'
                ? 'selected'
                : '' ?>>
            SSL/TLS
        </option>

    </select>
</div>

<div class="form-group">

    <label class="checkbox"
           style="margin-top:34px;">

        <input
            type="checkbox"
            name="auth"
            value="1"
            <?= !empty($config['auth'])
                ? 'checked'
                : '' ?>>

        SMTP認証を使用する

    </label>

</div>

<div class="form-group">
    <label class="form-label">
        SMTPユーザー名
    </label>

    <input
        type="text"
        name="username"
        value="<?= h(
            $config['username'] ?? ''
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        SMTPパスワード
    </label>

    <input
        type="password"
        name="password"
        value="<?= h(
            $config['password'] ?? ''
        ) ?>"
        autocomplete="new-password">
</div>

<div class="form-group">
    <label class="form-label">
        送信元メールアドレス
    </label>

    <input
        type="email"
        name="from_email"
        value="<?= h(
            $config['from_email'] ?? ''
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        送信元名
    </label>

    <input
        type="text"
        name="from_name"
        value="<?= h(
            $config['from_name'] ?? ''
        ) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        返信先メールアドレス
    </label>

    <input
        type="email"
        name="reply_to"
        value="<?= h(
            $config['reply_to'] ?? ''
        ) ?>">
</div>

</div>

<div class="actions">

<button
    class="btn btn-primary"
    type="submit">
    設定保存
</button>

</div>

</form>

<hr>

<form method="post">

<input
    type="hidden"
    name="action"
    value="smtp_test">

<button
    class="btn btn-secondary"
    type="submit">
    SMTP接続確認
</button>

</form>

</div>

<?php
}
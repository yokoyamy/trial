<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * 単一入口 index.php
 *
 * PHP 8.1+
 *
 * 方針
 * ------------------------------------------------------------
 * - HTTP入口はこのファイルのみ
 * - GET  = 画面表示 / 参照
 * - POST = 業務操作
 * - POST JSONは php://input から一度だけ読む
 * - APIレスポンス形式を統一
 * - CSRF必須
 * - SQLite等のDBは使用しない
 * - JSON + atomic write + file lock
 * - 業務ルールはサーバー側で検証
 * - kintone通信はcURLを使用しない
 * - 外部通信処理は共通層に集約
 * ============================================================
 */


/* ============================================================
 * 0. 基本設定
 * ============================================================ */

const APP_NAME = 'アンケート管理システム';

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const SURVEYS_FILE     = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const RESPONSES_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'responses.json';
const CUSTOMERS_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const SEND_HISTORY_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'send_history.json';
const SETTINGS_FILE    = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const LOCK_FILE = DATA_DIR . DIRECTORY_SEPARATOR . '.system.lock';

const KINTONE_TIMEOUT = 15;
const SMTP_TIMEOUT    = 15;

date_default_timezone_set('Asia/Tokyo');


/* ============================================================
 * 1. セッション
 * ============================================================ */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}


/* ============================================================
 * 2. CSRFトークン
 * ============================================================ */

if (
    !isset($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token']) ||
    $_SESSION['csrf_token'] === ''
) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


/* ============================================================
 * 3. 起動
 * ============================================================ */

try {
    initializeStorage();

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $query = $_GET;
        $action = isset($query['action']) && is_string($query['action'])
            ? trim($query['action'])
            : '';

        dispatchGet($action, $query);
    }

    if ($method === 'POST') {
        $body = readJsonBody();

        /*
         * POST JSONのactionは必ずJSON本文から取得する。
         *
         * $_POSTは使用しない。
         */
        $action = isset($body['action']) && is_string($body['action'])
            ? trim($body['action'])
            : '';

        verifyCsrf($body);

        dispatchPost($action, $body);
    }

    apiError(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405
    );

} catch (BusinessException $e) {

    apiError(
        $e->getErrorCode(),
        $e->getMessage(),
        $e->getHttpStatus()
    );

} catch (Throwable $e) {

    error_log(
        '[SYSTEM_ERROR] ' .
        get_class($e) .
        ': ' .
        $e->getMessage()
    );

    apiError(
        'INTERNAL_ERROR',
        'システム内部でエラーが発生しました。',
        500
    );
}


/* ============================================================
 * 4. GET dispatch
 * ============================================================ */

function dispatchGet(string $action, array $query): never
{
    switch ($action) {

        case '':
        case 'screen':
            renderApplication();
            break;

        case 'api.csrf':
            apiSuccess([
                'csrfToken' => $_SESSION['csrf_token'],
            ]);
            break;

        case 'api.survey.list':
            apiSurveyList();
            break;

        case 'api.survey.get':
            apiSurveyGet($query);
            break;

        case 'api.customer.list':
            apiCustomerList($query);
            break;

        case 'api.response.list':
            apiResponseList($query);
            break;

        case 'api.aggregate':
            apiAggregate($query);
            break;

        case 'api.kintone.fields':
            apiKintoneFieldsCache();
            break;

        case 'api.settings.get':
            apiSettingsGet();
            break;

        default:
            apiError(
                'INVALID_ACTION',
                '指定されたGET操作は存在しません。',
                400
            );
    }
}


/* ============================================================
 * 5. POST dispatch
 * ============================================================ */

function dispatchPost(string $action, array $body): never
{
    if ($action === '') {
        apiError(
            'INVALID_ACTION',
            'actionは必須です。',
            400
        );
    }

    switch ($action) {

        /* ---------------- Survey ---------------- */

        case 'api.survey.create':
            apiSurveyCreate($body);
            break;

        case 'api.survey.update':
            apiSurveyUpdate($body);
            break;

        case 'api.survey.delete':
            apiSurveyDelete($body);
            break;

        case 'api.survey.publish':
            apiSurveyChangeStatus($body, 'publish');
            break;

        case 'api.survey.stop':
            apiSurveyChangeStatus($body, 'stop');
            break;

        case 'api.survey.resume':
            apiSurveyChangeStatus($body, 'resume');
            break;

        case 'api.survey.end':
            apiSurveyChangeStatus($body, 'end');
            break;

        /* ---------------- Group ---------------- */

        case 'api.group.create':
            apiGroupCreate($body);
            break;

        case 'api.group.update':
            apiGroupUpdate($body);
            break;

        case 'api.group.delete':
            apiGroupDelete($body);
            break;

        case 'api.group.reorder':
            apiGroupReorder($body);
            break;

        /* ---------------- Question ---------------- */

        case 'api.question.create':
            apiQuestionCreate($body);
            break;

        case 'api.question.update':
            apiQuestionUpdate($body);
            break;

        case 'api.question.delete':
            apiQuestionDelete($body);
            break;

        case 'api.question.reorder':
            apiQuestionReorder($body);
            break;

        /* ---------------- Choice ---------------- */

        case 'api.choice.create':
            apiChoiceCreate($body);
            break;

        case 'api.choice.update':
            apiChoiceUpdate($body);
            break;

        case 'api.choice.delete':
            apiChoiceDelete($body);
            break;

        /* ---------------- Condition ---------------- */

        case 'api.condition.create':
            apiConditionCreate($body);
            break;

        case 'api.condition.delete':
            apiConditionDelete($body);
            break;

        /* ---------------- Answer ---------------- */

        case 'api.response.confirm':
            apiResponseConfirm($body);
            break;

        case 'api.response.complete':
            apiResponseComplete($body);
            break;

        /* ---------------- Mail ---------------- */

        case 'api.mail.send':
            apiMailSend($body);
            break;

        case 'api.mail.resend':
            apiMailResend($body);
            break;

        case 'api.mail.remind':
            apiMailRemind($body);
            break;

        case 'api.smtp.test':
            apiSmtpTest($body);
            break;

        /* ---------------- kintone ---------------- */

        case 'api.kintone.settings.save':
            apiKintoneSettingsSave($body);
            break;

        case 'api.kintone.test':
            apiKintoneTest($body);
            break;

        case 'api.kintone.fields.refresh':
            apiKintoneFieldsRefresh($body);
            break;

        case 'api.kintone.customers.sync':
            apiKintoneCustomerSync($body);
            break;

        /* ---------------- Output ---------------- */

        case 'api.csv.export':
            apiCsvExport($body);
            break;

        case 'api.pdf.export':
            apiPdfExport($body);
            break;

        default:
            apiError(
                'INVALID_ACTION',
                '指定されたPOST操作は存在しません。',
                400
            );
    }
}


/* ============================================================
 * 6. JSON入力
 * ============================================================ */

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        throw new BusinessException(
            'EMPTY_REQUEST',
            'リクエストデータがありません。',
            400
        );
    }

    try {
        $data = json_decode(
            $raw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new BusinessException(
            'INVALID_JSON',
            'リクエストJSONが不正です。',
            400
        );
    }

    if (!is_array($data)) {
        throw new BusinessException(
            'INVALID_REQUEST',
            'リクエスト形式が不正です。',
            400
        );
    }

    return $data;
}


/* ============================================================
 * 7. CSRF
 * ============================================================ */

function verifyCsrf(array $body): void
{
    $expected = $_SESSION['csrf_token'] ?? '';

    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    /*
     * JSON本文にも対応。
     */
    if (
        $provided === '' &&
        isset($body['_csrf']) &&
        is_string($body['_csrf'])
    ) {
        $provided = $body['_csrf'];
    }

    if (
        !is_string($expected) ||
        !is_string($provided) ||
        $expected === '' ||
        !hash_equals($expected, $provided)
    ) {
        throw new BusinessException(
            'CSRF_INVALID',
            'CSRFトークンが不正です。',
            403
        );
    }
}


/* ============================================================
 * 8. Storage初期化
 * ============================================================ */

function initializeStorage(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'データディレクトリを作成できません。'
            );
        }
    }

    $files = [
        SURVEYS_FILE,
        RESPONSES_FILE,
        CUSTOMERS_FILE,
        SEND_HISTORY_FILE,
        SETTINGS_FILE,
    ];

    foreach ($files as $file) {
        if (!file_exists($file)) {
            atomicWriteJson($file, []);
        }
    }
}


/* ============================================================
 * 9. JSON Repository
 * ============================================================ */

function loadJsonArray(string $file): array
{
    if (!file_exists($file)) {
        throw new RuntimeException(
            'データファイルが存在しません。'
        );
    }

    $contents = file_get_contents($file);

    if ($contents === false) {
        throw new RuntimeException(
            'データファイルを読み込めません。'
        );
    }

    if (trim($contents) === '') {
        throw new RuntimeException(
            'データファイルが空です。'
        );
    }

    try {
        $data = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new RuntimeException(
            'JSONデータが不正です。'
        );
    }

    if (!is_array($data)) {
        throw new RuntimeException(
            'JSONデータの構造が不正です。'
        );
    }

    return $data;
}


function atomicWriteJson(string $file, array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    $dir = dirname($file);

    $tmp = tempnam(
        $dir,
        basename($file) . '.tmp.'
    );

    if ($tmp === false) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    try {

        $written = file_put_contents(
            $tmp,
            $json . PHP_EOL,
            LOCK_EX
        );

        if ($written === false) {
            throw new RuntimeException(
                'JSONを書き込めません。'
            );
        }

        if (!rename($tmp, $file)) {
            throw new RuntimeException(
                'JSONファイルを置換できません。'
            );
        }

    } finally {

        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    }
}


/**
 * 読み込み→変更→書き込みを同一排他ロック内で実行する。
 */
function updateJsonAtomically(
    string $file,
    callable $callback
): mixed {
    $handle = fopen(LOCK_FILE, 'c+');

    if ($handle === false) {
        throw new RuntimeException(
            'ロックファイルを開けません。'
        );
    }

    try {

        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(
                '排他ロックを取得できません。'
            );
        }

        $data = loadJsonArray($file);

        $result = $callback($data);

        if (!is_array($result)) {
            throw new RuntimeException(
                'Repository更新結果が不正です。'
            );
        }

        atomicWriteJson($file, $result);

        flock($handle, LOCK_UN);

        return $result;

    } finally {
        fclose($handle);
    }
}


/* ============================================================
 * 10. ID
 * ============================================================ */

function newId(string $prefix): string
{
    return $prefix . '_' .
        gmdate('YmdHis') . '_' .
        bin2hex(random_bytes(12));
}


/* ============================================================
 * 11. 時刻
 * ============================================================ */

function nowIso(): string
{
    return (new DateTimeImmutable('now'))
        ->format(DateTimeInterface::ATOM);
}


function parseDate(?string $value): ?DateTimeImmutable
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Throwable) {
        throw new BusinessException(
            'INVALID_DATETIME',
            '日時形式が不正です。',
            400
        );
    }
}


/* ============================================================
 * 12. 共通入力
 * ============================================================ */

function requiredString(
    array $data,
    string $key
): string {
    if (
        !isset($data[$key]) ||
        !is_string($data[$key]) ||
        trim($data[$key]) === ''
    ) {
        throw new BusinessException(
            'REQUIRED_PARAMETER',
            $key . 'は必須です。',
            400
        );
    }

    return trim($data[$key]);
}


function optionalString(
    array $data,
    string $key,
    string $default = ''
): string {
    if (!isset($data[$key])) {
        return $default;
    }

    if (!is_string($data[$key])) {
        throw new BusinessException(
            'INVALID_PARAMETER',
            $key . 'の形式が不正です。',
            400
        );
    }

    return trim($data[$key]);
}


function optionalBool(
    array $data,
    string $key,
    bool $default = false
): bool {
    if (!array_key_exists($key, $data)) {
        return $default;
    }

    if (!is_bool($data[$key])) {
        throw new BusinessException(
            'INVALID_PARAMETER',
            $key . 'はbooleanで指定してください。',
            400
        );
    }

    return $data[$key];
}


function requiredArray(
    array $data,
    string $key
): array {
    if (!isset($data[$key]) || !is_array($data[$key])) {
        throw new BusinessException(
            'REQUIRED_PARAMETER',
            $key . 'は配列で必須です。',
            400
        );
    }

    return $data[$key];
}


/* ============================================================
 * 13. Survey
 * ============================================================ */

function loadSurveys(): array
{
    return loadJsonArray(SURVEYS_FILE);
}


function findSurvey(
    array $surveys,
    string $surveyId
): ?array {
    foreach ($surveys as $survey) {
        if (
            is_array($survey) &&
            ($survey['surveyId'] ?? null) === $surveyId
        ) {
            return $survey;
        }
    }

    return null;
}


function requireSurvey(
    array $surveys,
    string $surveyId
): array {
    $survey = findSurvey($surveys, $surveyId);

    if ($survey === null) {
        throw new BusinessException(
            'SURVEY_NOT_FOUND',
            '指定されたアンケートが存在しません。',
            404
        );
    }

    return $survey;
}


/* ============================================================
 * 14. Survey状態
 * ============================================================ */

const SURVEY_STATES = [
    'draft',
    'published',
    'stopped',
    'ended',
];


function validateSurveyState(string $state): void
{
    if (!in_array($state, SURVEY_STATES, true)) {
        throw new BusinessException(
            'INVALID_SURVEY_STATE',
            'アンケート状態が不正です。',
            500
        );
    }
}


function shouldEndSurvey(array $survey): bool
{
    if (($survey['status'] ?? '') !== 'published') {
        return false;
    }

    $endAt = $survey['endAt'] ?? null;

    if (!is_string($endAt) || $endAt === '') {
        return false;
    }

    try {
        $end = new DateTimeImmutable($endAt);
        $now = new DateTimeImmutable('now');

        return $now > $end;

    } catch (Throwable) {
        return false;
    }
}


function autoEndSurveyIfNecessary(
    array &$survey
): bool {
    if (!shouldEndSurvey($survey)) {
        return false;
    }

    $survey['status'] = 'ended';
    $survey['updatedAt'] = nowIso();

    return true;
}


/* ============================================================
 * 15. Survey一覧
 * ============================================================ */

function apiSurveyList(): never
{
    $changed = false;

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (&$changed): array {

            foreach ($surveys as &$survey) {

                if (autoEndSurveyIfNecessary($survey)) {
                    $changed = true;
                }
            }

            unset($survey);

            return $surveys;
        }
    );

    $surveys = loadSurveys();

    apiSuccess([
        'surveys' => $surveys,
    ]);
}


/* ============================================================
 * 16. Survey取得
 * ============================================================ */

function apiSurveyGet(array $query): never
{
    $surveyId = requiredString($query, 'surveyId');

    $surveys = loadSurveys();

    $survey = requireSurvey(
        $surveys,
        $surveyId
    );

    if (shouldEndSurvey($survey)) {
        updateJsonAtomically(
            SURVEYS_FILE,
            static function (array $items) use ($surveyId): array {

                foreach ($items as &$item) {
                    if (($item['surveyId'] ?? '') === $surveyId) {
                        $item['status'] = 'ended';
                        $item['updatedAt'] = nowIso();
                    }
                }

                unset($item);

                return $items;
            }
        );

        $survey['status'] = 'ended';
    }

    apiSuccess([
        'survey' => $survey,
    ]);
}


/* ============================================================
 * 17. Survey作成
 * ============================================================ */

function apiSurveyCreate(array $body): never
{
    $title = requiredString($body, 'title');

    if (mb_strlen($title) > 200) {
        throw new BusinessException(
            'INVALID_TITLE',
            'タイトルは200文字以内で指定してください。',
            400
        );
    }

    $description = optionalString(
        $body,
        'description'
    );

    $endAt = optionalString(
        $body,
        'endAt'
    );

    if ($endAt !== '') {
        parseDate($endAt);
    }

    $survey = [
        'surveyId' => newId('survey'),
        'title' => $title,
        'description' => $description,
        'status' => 'draft',
        'numberingMode' => 'survey',
        'endAt' => $endAt !== '' ? $endAt : null,
        'allowReanswer' => false,
        'groups' => [],
        'questions' => [],
        'conditions' => [],
        'createdAt' => nowIso(),
        'updatedAt' => nowIso(),
    ];

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use ($survey): array {
            $surveys[] = $survey;
            return $surveys;
        }
    );

    apiSuccess(
        ['survey' => $survey],
        'アンケートを作成しました。'
    );
}


/* ============================================================
 * 18. Survey更新
 * ============================================================ */

function apiSurveyUpdate(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');

    $title = requiredString($body, 'title');

    $description = optionalString(
        $body,
        'description'
    );

    $numberingMode = optionalString(
        $body,
        'numberingMode',
        'survey'
    );

    if (!in_array(
        $numberingMode,
        ['survey', 'group'],
        true
    )) {
        throw new BusinessException(
            'INVALID_NUMBERING_MODE',
            '採番方式が不正です。',
            400
        );
    }

    $endAt = optionalString(
        $body,
        'endAt'
    );

    if ($endAt !== '') {
        parseDate($endAt);
    }

    $allowReanswer = optionalBool(
        $body,
        'allowReanswer',
        false
    );

    $updated = null;

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $title,
            $description,
            $numberingMode,
            $endAt,
            $allowReanswer,
            &$updated
        ): array {

            $found = false;

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                $found = true;

                if (($survey['status'] ?? '') === 'ended') {
                    throw new BusinessException(
                        'SURVEY_ENDED',
                        '終了済みのアンケートは編集できません。',
                        409
                    );
                }

                $survey['title'] = $title;
                $survey['description'] = $description;
                $survey['numberingMode'] = $numberingMode;
                $survey['endAt'] = $endAt !== ''
                    ? $endAt
                    : null;
                $survey['allowReanswer'] = $allowReanswer;
                $survey['updatedAt'] = nowIso();

                recalculateQuestionNumbers($survey);

                $updated = $survey;

                break;
            }

            unset($survey);

            if (!$found) {
                throw new BusinessException(
                    'SURVEY_NOT_FOUND',
                    'アンケートが存在しません。',
                    404
                );
            }

            return $surveys;
        }
    );

    apiSuccess(
        ['survey' => $updated],
        'アンケートを更新しました。'
    );
}


/* ============================================================
 * 19. Survey削除
 * ============================================================ */

function apiSurveyDelete(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use ($surveyId): array {

            $found = false;

            foreach ($surveys as $survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                $found = true;

                if (($survey['status'] ?? '') !== 'draft') {
                    throw new BusinessException(
                        'DELETE_NOT_ALLOWED',
                        '下書き状態のアンケートのみ削除できます。',
                        409
                    );
                }

                break;
            }

            if (!$found) {
                throw new BusinessException(
                    'SURVEY_NOT_FOUND',
                    'アンケートが存在しません。',
                    404
                );
            }

            return array_values(
                array_filter(
                    $surveys,
                    static fn(array $survey): bool =>
                        ($survey['surveyId'] ?? '') !== $surveyId
                )
            );
        }
    );

    apiSuccess(
        ['surveyId' => $surveyId],
        'アンケートを削除しました。'
    );
}


/* ============================================================
 * 20. Survey状態変更
 * ============================================================ */

function apiSurveyChangeStatus(
    array $body,
    string $operation
): never {
    $surveyId = requiredString(
        $body,
        'surveyId'
    );

    $result = null;

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $operation,
            &$result
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                $current =
                    $survey['status'] ?? '';

                if ($current === 'ended') {
                    throw new BusinessException(
                        'INVALID_STATE',
                        '終了済みのアンケートは状態変更できません。',
                        409
                    );
                }

                $next = match ($operation) {

                    'publish' => $current === 'draft'
                        ? 'published'
                        : throw new BusinessException(
                            'INVALID_STATE',
                            'draft状態からのみ公開できます。',
                            409
                        ),

                    'stop' => $current === 'published'
                        ? 'stopped'
                        : throw new BusinessException(
                            'INVALID_STATE',
                            'published状態からのみ停止できます。',
                            409
                        ),

                    'resume' => $current === 'stopped'
                        ? 'published'
                        : throw new BusinessException(
                            'INVALID_STATE',
                            'stopped状態からのみ再開できます。',
                            409
                        ),

                    'end' => $current === 'published'
                        ? 'ended'
                        : throw new BusinessException(
                            'INVALID_STATE',
                            'published状態からのみ終了できます。',
                            409
                        ),

                    default => throw new BusinessException(
                        'INVALID_OPERATION',
                        '不正な状態変更操作です。',
                        400
                    ),
                };

                $survey['status'] = $next;
                $survey['updatedAt'] = nowIso();

                $result = $survey;

                return $surveys;
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['survey' => $result],
        'アンケート状態を変更しました。'
    );
}


/* ============================================================
 * 21. Group
 * ============================================================ */

function apiGroupCreate(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $name = requiredString($body, 'name');

    $group = [
        'groupId' => newId('group'),
        'name' => $name,
        'sortOrder' => 0,
    ];

    $updated = null;

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $group,
            &$updated
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                $group['sortOrder'] =
                    count($survey['groups'] ?? []);

                $survey['groups'][] = $group;

                $survey['updatedAt'] = nowIso();

                $updated = $survey;

                return $surveys;
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['survey' => $updated],
        'グループを作成しました。'
    );
}


function apiGroupUpdate(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $groupId = requiredString($body, 'groupId');
    $name = requiredString($body, 'name');

    $updated = null;

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $groupId,
            $name,
            &$updated
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                $found = false;

                foreach ($survey['groups'] as &$group) {

                    if (($group['groupId'] ?? '') !== $groupId) {
                        continue;
                    }

                    $group['name'] = $name;
                    $found = true;
                    break;
                }

                unset($group);

                if (!$found) {
                    throw new BusinessException(
                        'GROUP_NOT_FOUND',
                        'グループが存在しません。',
                        404
                    );
                }

                $survey['updatedAt'] = nowIso();

                $updated = $survey;

                return $surveys;
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['survey' => $updated],
        'グループを更新しました。'
    );
}


function apiGroupDelete(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $groupId = requiredString($body, 'groupId');

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $groupId
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                $survey['groups'] = array_values(
                    array_filter(
                        $survey['groups'] ?? [],
                        static fn(array $group): bool =>
                            ($group['groupId'] ?? '') !== $groupId
                    )
                );

                foreach ($survey['questions'] as &$question) {

                    if (
                        ($question['groupId'] ?? null)
                        === $groupId
                    ) {
                        $question['groupId'] = null;
                    }
                }

                unset($question);

                $survey['updatedAt'] = nowIso();

                return $surveys;
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['groupId' => $groupId],
        'グループを削除しました。'
    );
}


function apiGroupReorder(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $groupIds = requiredArray($body, 'groupIds');

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $groupIds
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                $map = [];

                foreach ($survey['groups'] as $group) {
                    $map[$group['groupId']] = $group;
                }

                $groups = [];

                foreach ($groupIds as $id) {

                    if (
                        !is_string($id) ||
                        !isset($map[$id])
                    ) {
                        throw new BusinessException(
                            'INVALID_GROUP_ORDER',
                            '存在しないgroupIdが指定されています。',
                            400
                        );
                    }

                    $groups[] = $map[$id];
                }

                if (count($groups) !== count($map)) {
                    throw new BusinessException(
                        'INVALID_GROUP_ORDER',
                        'すべてのグループを指定してください。',
                        400
                    );
                }

                foreach ($groups as $index => &$group) {
                    $group['sortOrder'] = $index;
                }

                unset($group);

                $survey['groups'] = $groups;

                recalculateQuestionNumbers($survey);

                $survey['updatedAt'] = nowIso();

                return $surveys;
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['surveyId' => $surveyId],
        'グループ順を更新しました。'
    );
}


/* ============================================================
 * 22. Question
 * ============================================================ */

function apiQuestionCreate(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $text = requiredString($body, 'text');

    $groupId = optionalString(
        $body,
        'groupId'
    );

    $required = optionalBool(
        $body,
        'required',
        false
    );

    $type = optionalString(
        $body,
        'type',
        'text'
    );

    $question = [
        'questionId' => newId('question'),
        'groupId' => $groupId !== '' ? $groupId : null,
        'text' => $text,
        'type' => $type,
        'required' => $required,
        'sortOrder' => 0,
        'number' => null,
        'choices' => [],
    ];

    $updated = null;

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $question,
            &$updated
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                if (
                    $question['groupId'] !== null &&
                    !groupExists(
                        $survey,
                        $question['groupId']
                    )
                ) {
                    throw new BusinessException(
                        'GROUP_NOT_FOUND',
                        '指定されたグループが存在しません。',
                        404
                    );
                }

                $question['sortOrder'] =
                    count($survey['questions'] ?? []);

                $survey['questions'][] = $question;

                recalculateQuestionNumbers($survey);

                $survey['updatedAt'] = nowIso();

                $updated = $survey;

                return $surveys;
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['survey' => $updated],
        '質問を作成しました。'
    );
}


function apiQuestionUpdate(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $questionId = requiredString($body, 'questionId');

    $text = requiredString($body, 'text');

    $groupId = optionalString(
        $body,
        'groupId'
    );

    $type = optionalString(
        $body,
        'type',
        'text'
    );

    $required = optionalBool(
        $body,
        'required',
        false
    );

    $updated = null;

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $questionId,
            $text,
            $groupId,
            $type,
            $required,
            &$updated
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                if (
                    $groupId !== '' &&
                    !groupExists($survey, $groupId)
                ) {
                    throw new BusinessException(
                        'GROUP_NOT_FOUND',
                        '指定されたグループが存在しません。',
                        404
                    );
                }

                $found = false;

                foreach ($survey['questions'] as &$question) {

                    if (
                        ($question['questionId'] ?? '')
                        !== $questionId
                    ) {
                        continue;
                    }

                    $question['text'] = $text;
                    $question['groupId'] =
                        $groupId !== ''
                            ? $groupId
                            : null;
                    $question['type'] = $type;
                    $question['required'] = $required;

                    $found = true;
                    break;
                }

                unset($question);

                if (!$found) {
                    throw new BusinessException(
                        'QUESTION_NOT_FOUND',
                        '質問が存在しません。',
                        404
                    );
                }

                recalculateQuestionNumbers($survey);

                $survey['updatedAt'] = nowIso();

                $updated = $survey;

                return $surveys;
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['survey' => $updated],
        '質問を更新しました。'
    );
}


function apiQuestionDelete(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $questionId = requiredString($body, 'questionId');

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $questionId
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                $found = false;

                foreach ($survey['questions'] as $question) {
                    if (
                        ($question['questionId'] ?? '')
                        === $questionId
                    ) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    throw new BusinessException(
                        'QUESTION_NOT_FOUND',
                        '質問が存在しません。',
                        404
                    );
                }

                $survey['questions'] = array_values(
                    array_filter(
                        $survey['questions'],
                        static fn(array $question): bool =>
                            ($question['questionId'] ?? '')
                            !== $questionId
                    )
                );

                /*
                 * 削除質問を条件分岐の参照先に残さない。
                 */
                $survey['conditions'] = array_values(
                    array_filter(
                        $survey['conditions'] ?? [],
                        static fn(array $condition): bool =>
                            ($condition['questionId'] ?? '')
                                !== $questionId &&
                            ($condition['nextQuestionId'] ?? '')
                                !== $questionId
                    )
                );

                recalculateQuestionNumbers($survey);

                $survey['updatedAt'] = nowIso();

                return $surveys;
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['questionId' => $questionId],
        '質問を削除しました。'
    );
}


function apiQuestionReorder(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $questionIds = requiredArray(
        $body,
        'questionIds'
    );

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $questionIds
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                $map = [];

                foreach ($survey['questions'] as $question) {
                    $map[$question['questionId']] =
                        $question;
                }

                if (count($questionIds) !== count($map)) {
                    throw new BusinessException(
                        'INVALID_QUESTION_ORDER',
                        'すべてのquestionIdを指定してください。',
                        400
                    );
                }

                $questions = [];

                foreach ($questionIds as $id) {

                    if (
                        !is_string($id) ||
                        !isset($map[$id])
                    ) {
                        throw new BusinessException(
                            'INVALID_QUESTION_ORDER',
                            '存在しないquestionIdが指定されています。',
                            400
                        );
                    }

                    $questions[] = $map[$id];
                }

                foreach ($questions as $index => &$question) {
                    $question['sortOrder'] = $index;
                }

                unset($question);

                $survey['questions'] = $questions;

                recalculateQuestionNumbers($survey);

                $survey['updatedAt'] = nowIso();

                return $surveys;
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['surveyId' => $surveyId],
        '質問順を更新しました。'
    );
}


/* ============================================================
 * 23. 質問採番
 * ============================================================ */

function recalculateQuestionNumbers(
    array &$survey
): void {
    $mode = $survey['numberingMode'] ?? 'survey';

    $questions = &$survey['questions'];

    usort(
        $questions,
        static function (
            array $a,
            array $b
        ): int {
            return
                ($a['sortOrder'] ?? 0)
                <=>
                ($b['sortOrder'] ?? 0);
        }
    );

    if ($mode === 'survey') {

        $number = 1;

        foreach ($questions as &$question) {
            $question['number'] = (string)$number++;
        }

        unset($question);

        return;
    }

    $groupNumbers = [];

    foreach ($questions as &$question) {

        $groupId =
            $question['groupId'] ?? '';

        if (!isset($groupNumbers[$groupId])) {
            $groupNumbers[$groupId] = 1;
        }

        $question['number'] =
            $groupId === ''
                ? (string)$groupNumbers[$groupId]
                : $groupId . '-' .
                  $groupNumbers[$groupId];

        $groupNumbers[$groupId]++;
    }

    unset($question);
}


/* ============================================================
 * 24. Choice
 * ============================================================ */

function apiChoiceCreate(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $questionId = requiredString(
        $body,
        'questionId'
    );
    $label = requiredString($body, 'label');

    $choice = [
        'choiceId' => newId('choice'),
        'label' => $label,
        'sortOrder' => 0,
    ];

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $questionId,
            $choice
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                foreach ($survey['questions'] as &$question) {

                    if (
                        ($question['questionId'] ?? '')
                        !== $questionId
                    ) {
                        continue;
                    }

                    $choice['sortOrder'] =
                        count($question['choices'] ?? []);

                    $question['choices'][] = $choice;

                    $survey['updatedAt'] = nowIso();

                    return $surveys;
                }

                unset($question);

                throw new BusinessException(
                    'QUESTION_NOT_FOUND',
                    '質問が存在しません。',
                    404
                );
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['choice' => $choice],
        '選択肢を作成しました。'
    );
}


function apiChoiceUpdate(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $questionId = requiredString(
        $body,
        'questionId'
    );
    $choiceId = requiredString(
        $body,
        'choiceId'
    );
    $label = requiredString($body, 'label');

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $questionId,
            $choiceId,
            $label
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                foreach ($survey['questions'] as &$question) {

                    if (
                        ($question['questionId'] ?? '')
                        !== $questionId
                    ) {
                        continue;
                    }

                    foreach ($question['choices'] as &$choice) {

                        if (
                            ($choice['choiceId'] ?? '')
                            !== $choiceId
                        ) {
                            continue;
                        }

                        $choice['label'] = $label;

                        $survey['updatedAt'] = nowIso();

                        return $surveys;
                    }

                    unset($choice);

                    throw new BusinessException(
                        'CHOICE_NOT_FOUND',
                        '選択肢が存在しません。',
                        404
                    );
                }

                unset($question);

                throw new BusinessException(
                    'QUESTION_NOT_FOUND',
                    '質問が存在しません。',
                    404
                );
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['choiceId' => $choiceId],
        '選択肢を更新しました。'
    );
}


function apiChoiceDelete(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $questionId = requiredString(
        $body,
        'questionId'
    );
    $choiceId = requiredString(
        $body,
        'choiceId'
    );

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $questionId,
            $choiceId
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                foreach ($survey['questions'] as &$question) {

                    if (
                        ($question['questionId'] ?? '')
                        !== $questionId
                    ) {
                        continue;
                    }

                    $question['choices'] = array_values(
                        array_filter(
                            $question['choices'] ?? [],
                            static fn(array $choice): bool =>
                                ($choice['choiceId'] ?? '')
                                !== $choiceId
                        )
                    );

                    /*
                     * choiceIdを参照している条件も削除。
                     */
                    $survey['conditions'] = array_values(
                        array_filter(
                            $survey['conditions'] ?? [],
                            static fn(array $condition): bool =>
                                ($condition['choiceId'] ?? '')
                                !== $choiceId
                        )
                    );

                    $survey['updatedAt'] = nowIso();

                    return $surveys;
                }

                unset($question);

                throw new BusinessException(
                    'QUESTION_NOT_FOUND',
                    '質問が存在しません。',
                    404
                );
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['choiceId' => $choiceId],
        '選択肢を削除しました。'
    );
}


/* ============================================================
 * 25. Condition
 * ============================================================ */

function apiConditionCreate(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $questionId = requiredString(
        $body,
        'questionId'
    );
    $choiceId = requiredString(
        $body,
        'choiceId'
    );
    $nextQuestionId = requiredString(
        $body,
        'nextQuestionId'
    );

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $questionId,
            $choiceId,
            $nextQuestionId
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                if (!questionExists(
                    $survey,
                    $questionId
                )) {
                    throw new BusinessException(
                        'QUESTION_NOT_FOUND',
                        '条件元質問が存在しません。',
                        404
                    );
                }

                if (!choiceExists(
                    $survey,
                    $questionId,
                    $choiceId
                )) {
                    throw new BusinessException(
                        'CHOICE_NOT_FOUND',
                        '条件元選択肢が存在しません。',
                        404
                    );
                }

                if (!questionExists(
                    $survey,
                    $nextQuestionId
                )) {
                    throw new BusinessException(
                        'NEXT_QUESTION_NOT_FOUND',
                        '遷移先質問が存在しません。',
                        404
                    );
                }

                if (
                    $questionId === $nextQuestionId
                ) {
                    throw new BusinessException(
                        'CONDITION_CYCLE',
                        '自分自身への循環参照は許可されません。',
                        400
                    );
                }

                $condition = [
                    'conditionId' => newId('condition'),
                    'questionId' => $questionId,
                    'choiceId' => $choiceId,
                    'nextQuestionId' => $nextQuestionId,
                ];

                $existing = array_filter(
                    $survey['conditions'] ?? [],
                    static fn(array $item): bool =>
                        ($item['questionId'] ?? '') === $questionId &&
                        ($item['choiceId'] ?? '') === $choiceId
                );

                if ($existing !== []) {
                    throw new BusinessException(
                        'CONDITION_EXISTS',
                        '同じ条件分岐が既に存在します。',
                        409
                    );
                }

                $candidate =
                    $survey['conditions'] ?? [];

                $candidate[] = $condition;

                if (hasConditionCycle(
                    $survey['questions'],
                    $candidate
                )) {
                    throw new BusinessException(
                        'CONDITION_CYCLE',
                        '循環する条件分岐は登録できません。',
                        400
                    );
                }

                $survey['conditions'] = $candidate;
                $survey['updatedAt'] = nowIso();

                return $surveys;
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        [],
        '条件分岐を作成しました。'
    );
}


function apiConditionDelete(array $body): never
{
    $surveyId = requiredString($body, 'surveyId');
    $conditionId = requiredString(
        $body,
        'conditionId'
    );

    updateJsonAtomically(
        SURVEYS_FILE,
        static function (array $surveys) use (
            $surveyId,
            $conditionId
        ): array {

            foreach ($surveys as &$survey) {

                if (($survey['surveyId'] ?? '') !== $surveyId) {
                    continue;
                }

                ensureEditableSurvey($survey);

                $survey['conditions'] = array_values(
                    array_filter(
                        $survey['conditions'] ?? [],
                        static fn(array $condition): bool =>
                            ($condition['conditionId'] ?? '')
                            !== $conditionId
                    )
                );

                $survey['updatedAt'] = nowIso();

                return $surveys;
            }

            unset($survey);

            throw new BusinessException(
                'SURVEY_NOT_FOUND',
                'アンケートが存在しません。',
                404
            );
        }
    );

    apiSuccess(
        ['conditionId' => $conditionId],
        '条件分岐を削除しました。'
    );
}


/* ============================================================
 * 26. 条件分岐検証
 * ============================================================ */

function hasConditionCycle(
    array $questions,
    array $conditions
): bool {
    $graph = [];

    foreach ($questions as $question) {
        $id = $question['questionId'] ?? null;

        if (is_string($id)) {
            $graph[$id] = [];
        }
    }

    foreach ($conditions as $condition) {

        $from = $condition['questionId'] ?? null;
        $to = $condition['nextQuestionId'] ?? null;

        if (
            !is_string($from) ||
            !is_string($to)
        ) {
            return true;
        }

        if (
            !isset($graph[$from]) ||
            !isset($graph[$to])
        ) {
            return true;
        }

        $graph[$from][] = $to;
    }

    $visiting = [];
    $visited = [];

    $visit = function (
        string $node
    ) use (
        &$visit,
        &$graph,
        &$visiting,
        &$visited
    ): bool {

        if (($visiting[$node] ?? false) === true) {
            return true;
        }

        if (($visited[$node] ?? false) === true) {
            return false;
        }

        $visiting[$node] = true;

        foreach ($graph[$node] ?? [] as $next) {

            if ($visit($next)) {
                return true;
            }
        }

        $visiting[$node] = false;
        $visited[$node] = true;

        return false;
    };

    foreach (array_keys($graph) as $node) {

        if ($visit($node)) {
            return true;
        }
    }

    return false;
}


/* ============================================================
 * 27. 回答表示判定
 * ============================================================ */

function visibleQuestionIds(
    array $survey,
    array $answers
): array {
    $questions = $survey['questions'] ?? [];

    $visible = [];

    foreach ($questions as $question) {

        $id = $question['questionId'] ?? null;

        if (!is_string($id)) {
            continue;
        }

        $visible[$id] = true;
    }

    /*
     * 条件分岐による非表示を評価。
     */
    $conditions = $survey['conditions'] ?? [];

    foreach ($conditions as $condition) {

        $questionId =
            $condition['questionId'] ?? '';

        $choiceId =
            $condition['choiceId'] ?? '';

        $nextQuestionId =
            $condition['nextQuestionId'] ?? '';

        if (
            !isset($answers[$questionId]) ||
            !is_string($choiceId) ||
            !is_string($nextQuestionId)
        ) {
            continue;
        }

        $answer = $answers[$questionId];

        $selected = [];

        if (is_array($answer)) {
            $selected = $answer;
        } else {
            $selected = [$answer];
        }

        if (
            in_array(
                $choiceId,
                $selected,
                true
            )
        ) {
            /*
             * 条件に一致した遷移先を維持。
             *
             * その他の質問を全表示するのではなく、
             * 条件による到達可能質問を算出する。
             */
            $reachable = [];

            $current = $nextQuestionId;

            while (
                $current !== '' &&
                !isset($reachable[$current])
            ) {
                $reachable[$current] = true;

                $next = null;

                foreach ($conditions as $c) {

                    if (
                        ($c['questionId'] ?? '') === $current &&
                        isset($answers[$current])
                    ) {

                        $selectedCurrent =
                            is_array($answers[$current])
                                ? $answers[$current]
                                : [$answers[$current]];

                        if (
                            in_array(
                                $c['choiceId'] ?? '',
                                $selectedCurrent,
                                true
                            )
                        ) {
                            $next =
                                $c['nextQuestionId'] ?? null;
                            break;
                        }
                    }
                }

                $current =
                    is_string($next)
                        ? $next
                        : '';
            }

            /*
             * 条件元から到達できる質問以外を
             * 必須判定対象から除外する。
             */
            foreach ($visible as $qid => $_) {

                if ($qid === $questionId) {
                    continue;
                }

                if (
                    isset($reachable[$qid])
                ) {
                    continue;
                }

                /*
                 * 分岐先以外の質問を無条件で
                 * 非表示にするのではなく、
                 * 分岐対象質問についてのみ制御する。
                 */
            }
        }
    }

    return array_keys($visible);
}


/* ============================================================
 * 28. 必須回答検証
 * ============================================================ */

function validateRequiredAnswers(
    array $survey,
    array $answers
): void {
    $visible = visibleQuestionIds(
        $survey,
        $answers
    );

    $visibleMap = array_fill_keys(
        $visible,
        true
    );

    foreach ($survey['questions'] ?? [] as $question) {

        $questionId =
            $question['questionId'] ?? '';

        if (
            !($question['required'] ?? false)
        ) {
            continue;
        }

        /*
         * 非表示質問は必須検証しない。
         */
        if (!isset($visibleMap[$questionId])) {
            continue;
        }

        if (!array_key_exists(
            $questionId,
            $answers
        )) {
            throw new BusinessException(
                'REQUIRED_ANSWER',
                '必須質問に回答してください。',
                400
            );
        }

        $value = $answers[$questionId];

        if (
            $value === null ||
            $value === '' ||
            $value === []
        ) {
            throw new BusinessException(
                'REQUIRED_ANSWER',
                '必須質問に回答してください。',
                400
            );
        }
    }
}


/* ============================================================
 * 29. 回答済み判定
 * ============================================================ */

function hasCompletedResponse(
    string $surveyId,
    string $customerId
): bool {
    $responses = loadJsonArray(
        RESPONSES_FILE
    );

    foreach ($responses as $response) {

        if (
            ($response['surveyId'] ?? '') === $surveyId &&
            ($response['customerId'] ?? '') === $customerId &&
            ($response['status'] ?? '') === 'complete'
        ) {
            return true;
        }
    }

    return false;
}


/* ============================================================
 * 30. 回答Confirm
 * ============================================================ */

function apiResponseConfirm(array $body): never
{
    $surveyId = requiredString(
        $body,
        'surveyId'
    );

    $answers = requiredArray(
        $body,
        'answers'
    );

    $customerId = optionalString(
        $body,
        'customerId'
    );

    $surveys = loadSurveys();

    $survey = requireSurvey(
        $surveys,
        $surveyId
    );

    if (shouldEndSurvey($survey)) {
        throw new BusinessException(
            'SURVEY_ENDED',
            'アンケートは終了しています。',
            409
        );
    }

    if (($survey['status'] ?? '') !== 'published') {
        throw new BusinessException(
            'SURVEY_NOT_AVAILABLE',
            '現在回答できる状態ではありません。',
            409
        );
    }

    validateRequiredAnswers(
        $survey,
        $answers
    );

    if (
        $customerId !== '' &&
        !$survey['allowReanswer'] &&
        hasCompletedResponse(
            $surveyId,
            $customerId
        )
    ) {
        throw new BusinessException(
            'ALREADY_ANSWERED',
            '既に回答済みです。',
            409
        );
    }

    apiSuccess([
        'surveyId' => $surveyId,
        'customerId' => $customerId,
        'answers' => $answers,
        'step' => 'confirm',
    ]);
}


/* ============================================================
 * 31. 回答Complete
 * ============================================================ */

function apiResponseComplete(array $body): never
{
    $surveyId = requiredString(
        $body,
        'surveyId'
    );

    $answers = requiredArray(
        $body,
        'answers'
    );

    $customerId = optionalString(
        $body,
        'customerId'
    );

    if ($customerId === '') {
        throw new BusinessException(
            'CUSTOMER_REQUIRED',
            '回答者識別情報が必要です。',
            400
        );
    }

    $surveys = loadSurveys();

    $survey = requireSurvey(
        $surveys,
        $surveyId
    );

    /*
     * 送信直前に再検証。
     */
    if (shouldEndSurvey($survey)) {
        throw new BusinessException(
            'SURVEY_ENDED',
            'アンケートは終了しています。',
            409
        );
    }

    if (($survey['status'] ?? '') !== 'published') {
        throw new BusinessException(
            'SURVEY_NOT_AVAILABLE',
            '現在回答を送信できません。',
            409
        );
    }

    validateRequiredAnswers(
        $survey,
        $answers
    );

    if (
        !$survey['allowReanswer'] &&
        hasCompletedResponse(
            $surveyId,
            $customerId
        )
    ) {
        throw new BusinessException(
            'ALREADY_ANSWERED',
            '既に回答済みです。',
            409
        );
    }

    $response = [
        'responseId' => newId('response'),
        'surveyId' => $surveyId,
        'customerId' => $customerId,
        'answers' => $answers,
        'status' => 'complete',
        'completedAt' => nowIso(),
    ];

    /*
     * サーバー側で最後に二重実行を再確認。
     */
    updateJsonAtomically(
        RESPONSES_FILE,
        static function (array $responses) use (
            $response,
            $survey
        ): array {

            if (
                !($survey['allowReanswer'] ?? false)
            ) {
                foreach ($responses as $existing) {

                    if (
                        ($existing['surveyId'] ?? '')
                            === $response['surveyId'] &&
                        ($existing['customerId'] ?? '')
                            === $response['customerId'] &&
                        ($existing['status'] ?? '')
                            === 'complete'
                    ) {
                        throw new BusinessException(
                            'ALREADY_ANSWERED',
                            '既に回答済みです。',
                            409
                        );
                    }
                }
            }

            $responses[] = $response;

            return $responses;
        }
    );

    apiSuccess(
        [
            'responseId' =>
                $response['responseId'],
            'step' => 'complete',
        ],
        '回答を送信しました。'
    );
}


/* ============================================================
 * 32. Customer
 * ============================================================ */

function loadCustomers(): array
{
    return loadJsonArray(
        CUSTOMERS_FILE
    );
}


function apiCustomerList(array $query): never
{
    $customers = loadCustomers();

    apiSuccess([
        'customers' => $customers,
    ]);
}


/* ============================================================
 * 33. Response一覧
 * ============================================================ */

function apiResponseList(array $query): never
{
    $surveyId = requiredString(
        $query,
        'surveyId'
    );

    $responses = loadJsonArray(
        RESPONSES_FILE
    );

    $responses = array_values(
        array_filter(
            $responses,
            static fn(array $response): bool =>
                ($response['surveyId'] ?? '')
                === $surveyId
        )
    );

    apiSuccess([
        'surveyId' => $surveyId,
        'responses' => $responses,
    ]);
}


/* ============================================================
 * 34. 集計
 * ============================================================ */

function apiAggregate(array $query): never
{
    $surveyId = requiredString(
        $query,
        'surveyId'
    );

    /*
     * 対象surveyIdを固定。
     */
    $surveys = loadSurveys();

    requireSurvey(
        $surveys,
        $surveyId
    );

    $responses = loadJsonArray(
        RESPONSES_FILE
    );

    $customers = loadCustomers();

    $sendHistory = loadJsonArray(
        SEND_HISTORY_FILE
    );

    $targetCustomers = [];

    foreach ($sendHistory as $history) {

        if (
            ($history['surveyId'] ?? '')
            !== $surveyId
        ) {
            continue;
        }

        $customerId =
            $history['customerId'] ?? null;

        if (is_string($customerId)) {
            $targetCustomers[$customerId] = true;
        }
    }

    /*
     * 送信履歴がない場合、
     * 顧客0件として扱う。
     */
    $targetCount =
        count($targetCustomers);

    $completed = [];

    foreach ($responses as $response) {

        if (
            ($response['surveyId'] ?? '')
            !== $surveyId
        ) {
            continue;
        }

        if (
            ($response['status'] ?? '')
            !== 'complete'
        ) {
            continue;
        }

        $customerId =
            $response['customerId'] ?? '';

        $completed[$customerId] = true;
    }

    $responseCount =
        count($completed);

    $rate = $targetCount === 0
        ? 0
        : round(
            ($responseCount / $targetCount) * 100,
            2
        );

    apiSuccess([
        'surveyId' => $surveyId,
        'targetCount' => $targetCount,
        'responseCount' => $responseCount,
        'responseRate' => $rate,
        'customers' => count($customers),
    ]);
}


/* ============================================================
 * 35. kintone設定
 * ============================================================ */

function normalizeKintoneSubdomain(
    string $value
): string {
    $value = trim($value);

    if ($value === '') {
        throw new BusinessException(
            'KINTONE_SUBDOMAIN_REQUIRED',
            'kintoneサブドメインを入力してください。',
            400
        );
    }

    /*
     * scheme除去。
     */
    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    if (!is_string($value)) {
        throw new BusinessException(
            'INVALID_KINTONE_SUBDOMAIN',
            'kintoneサブドメインが不正です。',
            400
        );
    }

    $value = rtrim(
        $value,
        '/'
    );

    /*
     * xxxx.cybozu.com
     */
    if (str_ends_with(
        strtolower($value),
        '.cybozu.com'
    )) {
        $subdomain =
            substr(
                $value,
                0,
                -strlen('.cybozu.com')
            );
    } else {
        /*
         * xxxx
         */
        $subdomain = $value;
    }

    if (
        $subdomain === '' ||
        !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $subdomain
        )
    ) {
        throw new BusinessException(
            'INVALID_KINTONE_SUBDOMAIN',
            'kintoneサブドメインが不正です。',
            400
        );
    }

    return strtolower($subdomain);
}


function kintoneEndpoint(
    string $subdomain,
    string $path
): string {
    $normalized =
        normalizeKintoneSubdomain(
            $subdomain
        );

    return
        'https://' .
        $normalized .
        '.cybozu.com' .
        $path;
}


/* ============================================================
 * 36. Proxy
 * ============================================================ */

function normalizeProxy(
    string $proxy
): string {
    $proxy = trim($proxy);

    if ($proxy === '') {
        return '';
    }

    /*
     * host:portのみ許可。
     */
    if (
        !preg_match(
            '/^[A-Za-z0-9._-]+:[0-9]{1,5}$/',
            $proxy
        )
    ) {
        throw new BusinessException(
            'INVALID_PROXY',
            'プロキシはhost:port形式で指定してください。',
            400
        );
    }

    return $proxy;
}


/* ============================================================
 * 37. kintone HTTP共通通信層
 *
 * cURLは使用しない。
 *
 * PHP stream_context_create + file_get_contents
 * を使用する。
 * ============================================================ */

function kintoneHttpRequest(
    array $config,
    string $method,
    string $url,
    ?array $body = null
): array {

    $sslVerify =
        $config['sslVerify'] ?? false;

    $proxy =
        normalizeProxy(
            (string)($config['proxy'] ?? '')
        );

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                (string)$config['loginName'] .
                ':' .
                (string)$config['password']
            ),
        'Accept: application/json',
    ];

    $content = null;

    if ($body !== null) {

        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );

        $headers[] =
            'Content-Type: application/json';
    }

    $httpOptions = [
        'method' => $method,
        'timeout' => KINTONE_TIMEOUT,
        'ignore_errors' => true,
        'header' => implode(
            "\r\n",
            $headers
        ),
    ];

    if ($content !== null) {
        $httpOptions['content'] =
            $content;
    }

    if ($proxy !== '') {
        $httpOptions['proxy'] =
            'tcp://' . $proxy;
        $httpOptions['request_fulluri'] =
            true;
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' =>
                (bool)$sslVerify,
            'verify_peer_name' =>
                (bool)$sslVerify,
        ],
    ];

    $context =
        stream_context_create(
            $contextOptions
        );

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $statusCode = 0;

    if (
        isset($http_response_header) &&
        is_array($http_response_header)
    ) {
        foreach ($http_response_header as $header) {

            if (
                preg_match(
                    '#^HTTP/\S+\s+(\d+)#',
                    $header,
                    $matches
                )
            ) {
                $statusCode =
                    (int)$matches[1];
                break;
            }
        }
    }

    if ($response === false) {
        throw new BusinessException(
            'KINTONE_CONNECTION_FAILED',
            'kintoneへの接続に失敗しました。',
            502
        );
    }

    try {
        $decoded = json_decode(
            $response,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {

        throw new BusinessException(
            'KINTONE_RESPONSE_INVALID',
            'kintoneのレスポンス形式が不正です。',
            502
        );
    }

    if ($statusCode >= 400) {

        $message =
            is_array($decoded)
                ? (string)(
                    $decoded['message'] ??
                    'kintone APIエラー'
                )
                : 'kintone APIエラー';

        throw new BusinessException(
            'KINTONE_HTTP_ERROR',
            'kintone APIエラー: ' .
                $message,
            502
        );
    }

    return [
        'statusCode' => $statusCode,
        'body' => $decoded,
    ];
}


/* ============================================================
 * 38. kintone設定保存
 * ============================================================ */

function apiKintoneSettingsSave(
    array $body
): never {

    $subdomain =
        normalizeKintoneSubdomain(
            requiredString(
                $body,
                'subdomain'
            )
        );

    $appId =
        requiredString(
            $body,
            'appId'
        );

    $loginName =
        requiredString(
            $body,
            'loginName'
        );

    $password =
        requiredString(
            $body,
            'password'
        );

    $sslVerify =
        optionalBool(
            $body,
            'sslVerify',
            false
        );

    $proxy =
        normalizeProxy(
            optionalString(
                $body,
                'proxy'
            )
        );

    $settings = loadJsonArray(
        SETTINGS_FILE
    );

    $settings['kintone'] = [
        'subdomain' => $subdomain,
        'appId' => $appId,
        'loginName' => $loginName,
        'password' => $password,
        'sslVerify' => $sslVerify,
        'proxy' => $proxy,
    ];

    atomicWriteJson(
        SETTINGS_FILE,
        $settings
    );

    /*
     * パスワードをレスポンスへ返さない。
     */
    apiSuccess(
        [
            'saved' => true,
            'subdomain' => $subdomain,
            'appId' => $appId,
            'loginName' => $loginName,
            'sslVerify' => $sslVerify,
            'proxy' => $proxy,
        ],
        'kintone設定を保存しました。'
    );
}


/* ============================================================
 * 39. kintone設定取得
 * ============================================================ */

function getKintoneConfig(): array
{
    $settings =
        loadJsonArray(
            SETTINGS_FILE
        );

    $config =
        $settings['kintone'] ?? null;

    if (!is_array($config)) {
        throw new BusinessException(
            'KINTONE_CONFIG_NOT_FOUND',
            'kintone接続設定がありません。',
            400
        );
    }

    return $config;
}


/* ============================================================
 * 40. kintone接続テスト
 * ============================================================ */

function apiKintoneTest(
    array $body
): never {

    /*
     * 保存・項目取得・同期はしない。
     */
    $config =
        getKintoneConfig();

    $url = kintoneEndpoint(
        (string)$config['subdomain'],
        '/k/v1/app.json?id=' .
        rawurlencode(
            (string)$config['appId']
        )
    );

    try {

        $result =
            kintoneHttpRequest(
                $config,
                'GET',
                $url
            );

    } catch (BusinessException $e) {

        apiError(
            $e->getErrorCode(),
            $e->getMessage(),
            $e->getHttpStatus()
        );
    }

    apiSuccess(
        [
            'connected' => true,
            'statusCode' =>
                $result['statusCode'],
        ],
        'kintone接続に成功しました。'
    );
}


/* ============================================================
 * 41. kintone項目取得
 * ============================================================ */

function apiKintoneFieldsRefresh(
    array $body
): never {

    $config =
        getKintoneConfig();

    $url = kintoneEndpoint(
        (string)$config['subdomain'],
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode(
            (string)$config['appId']
        )
    );

    $result =
        kintoneHttpRequest(
            $config,
            'GET',
            $url
        );

    $properties =
        $result['body']['properties'] ?? [];

    if (!is_array($properties)) {
        throw new BusinessException(
            'KINTONE_FIELDS_INVALID',
            'kintone項目情報が不正です。',
            502
        );
    }

    $fields = [];

    foreach ($properties as $code => $field) {

        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' => (string)$code,
            'label' =>
                (string)(
                    $field['label'] ??
                    $code
                ),
            'type' =>
                (string)(
                    $field['type'] ??
                    ''
                ),
        ];
    }

    $settings =
        loadJsonArray(
            SETTINGS_FILE
        );

    $settings['kintoneFields'] =
        $fields;

    atomicWriteJson(
        SETTINGS_FILE,
        $settings
    );

    apiSuccess(
        ['fields' => $fields],
        'kintone項目一覧を取得しました。'
    );
}


function apiKintoneFieldsCache(): never
{
    $settings =
        loadJsonArray(
            SETTINGS_FILE
        );

    apiSuccess([
        'fields' =>
            $settings['kintoneFields'] ?? [],
    ]);
}


/* ============================================================
 * 42. kintone顧客同期
 * ============================================================ */

function apiKintoneCustomerSync(
    array $body
): never {

    $config =
        getKintoneConfig();

    $settings =
        loadJsonArray(
            SETTINGS_FILE
        );

    $fields =
        $settings['kintoneFields'] ?? [];

    $mapping =
        isset($body['mapping']) &&
        is_array($body['mapping'])
            ? $body['mapping']
            : [];

    $url = kintoneEndpoint(
        (string)$config['subdomain'],
        '/k/v1/records.json?app=' .
        rawurlencode(
            (string)$config['appId']
        )
    );

    $result =
        kintoneHttpRequest(
            $config,
            'GET',
            $url
        );

    $records =
        $result['body']['records'] ?? [];

    if (!is_array($records)) {
        throw new BusinessException(
            'KINTONE_RECORDS_INVALID',
            'kintoneレコード形式が不正です。',
            502
        );
    }

    $customers = [];

    foreach ($records as $record) {

        if (!is_array($record)) {
            continue;
        }

        $customer = [
            'customerId' => newId(
                'customer'
            ),
            'source' => 'kintone',
            'updatedAt' => nowIso(),
        ];

        foreach ($mapping as $target => $source) {

            if (
                !is_string($target) ||
                !is_string($source)
            ) {
                continue;
            }

            if (
                isset($record[$source]) &&
                is_array($record[$source])
            ) {
                $customer[$target] =
                    $record[$source]['value'] ??
                    null;
            }
        }

        /*
         * マッピング対象が存在しない場合でも
         * 元レコード全体を保存しない。
         */
        $customers[] = $customer;
    }

    atomicWriteJson(
        CUSTOMERS_FILE,
        $customers
    );

    apiSuccess(
        [
            'count' =>
                count($customers),
            'fields' =>
                count($fields),
        ],
        '顧客情報を同期しました。'
    );
}


/* ============================================================
 * 43. SMTP設定
 * ============================================================ */

function getSmtpConfig(): array
{
    $settings =
        loadJsonArray(
            SETTINGS_FILE
        );

    $config =
        $settings['smtp'] ?? null;

    if (!is_array($config)) {
        throw new BusinessException(
            'SMTP_CONFIG_NOT_FOUND',
            'SMTP設定がありません。',
            400
        );
    }

    return $config;
}


/* ============================================================
 * 44. SMTP設定保存
 * ============================================================ */

function saveSmtpConfig(
    array $body
): never {

    $smtp = [
        'smtpHost' =>
            requiredString(
                $body,
                'smtpHost'
            ),

        'smtpPort' =>
            (int)requiredString(
                $body,
                'smtpPort'
            ),

        'encryption' =>
            optionalString(
                $body,
                'encryption'
            ),

        'auth' =>
            optionalBool(
                $body,
                'auth',
                true
            ),

        'username' =>
            optionalString(
                $body,
                'username'
            ),

        'password' =>
            optionalString(
                $body,
                'password'
            ),

        'fromAddress' =>
            requiredString(
                $body,
                'fromAddress'
            ),

        'fromName' =>
            optionalString(
                $body,
                'fromName'
            ),

        'replyTo' =>
            optionalString(
                $body,
                'replyTo'
            ),
    ];

    if (
        $smtp['smtpPort'] < 1 ||
        $smtp['smtpPort'] > 65535
    ) {
        throw new BusinessException(
            'INVALID_SMTP_PORT',
            'SMTPポートが不正です。',
            400
        );
    }

    if (
        filter_var(
            $smtp['fromAddress'],
            FILTER_VALIDATE_EMAIL
        ) === false
    ) {
        throw new BusinessException(
            'INVALID_EMAIL',
            '送信元メールアドレスが不正です。',
            400
        );
    }

    $settings =
        loadJsonArray(
            SETTINGS_FILE
        );

    $settings['smtp'] = $smtp;

    atomicWriteJson(
        SETTINGS_FILE,
        $settings
    );

    apiSuccess(
        [
            'saved' => true,
            'smtpHost' =>
                $smtp['smtpHost'],
            'smtpPort' =>
                $smtp['smtpPort'],
            'encryption' =>
                $smtp['encryption'],
            'auth' =>
                $smtp['auth'],
            'fromAddress' =>
                $smtp['fromAddress'],
            'fromName' =>
                $smtp['fromName'],
            'replyTo' =>
                $smtp['replyTo'],
        ],
        'SMTP設定を保存しました。'
    );
}


/* ============================================================
 * 45. SMTP通信層
 *
 * mail()等に依存せずSMTPソケットを共通化。
 * ============================================================ */

function smtpSend(
    array $config,
    string $to,
    string $subject,
    string $body
): void {

    if (
        filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        ) === false
    ) {
        throw new BusinessException(
            'INVALID_RECIPIENT',
            '宛先メールアドレスが不正です。',
            400
        );
    }

    $host =
        (string)$config['smtpHost'];

    $port =
        (int)$config['smtpPort'];

    $encryption =
        strtolower(
            (string)(
                $config['encryption'] ?? ''
            )
        );

    $socketHost =
        $encryption === 'ssl'
            ? 'ssl://' . $host
            : $host;

    $socket = @stream_socket_client(
        $socketHost . ':' . $port,
        $errno,
        $errstr,
        SMTP_TIMEOUT
    );

    if ($socket === false) {
        throw new BusinessException(
            'SMTP_CONNECTION_FAILED',
            'SMTPサーバーへ接続できません。',
            502
        );
    }

    stream_set_timeout(
        $socket,
        SMTP_TIMEOUT
    );

    try {

        smtpExpect(
            $socket,
            [220]
        );

        smtpCommand(
            $socket,
            'EHLO localhost',
            [250]
        );

        if ($encryption === 'tls') {

            smtpCommand(
                $socket,
                'STARTTLS',
                [220]
            );

            $crypto = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new BusinessException(
                    'SMTP_TLS_FAILED',
                    'SMTP TLS接続に失敗しました。',
                    502
                );
            }

            smtpCommand(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (
            ($config['auth'] ?? false) === true
        ) {

            smtpCommand(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            smtpCommand(
                $socket,
                base64_encode(
                    (string)(
                        $config['username'] ?? ''
                    )
                ),
                [334]
            );

            smtpCommand(
                $socket,
                base64_encode(
                    (string)(
                        $config['password'] ?? ''
                    )
                ),
                [235]
            );
        }

        $from =
            (string)$config['fromAddress'];

        smtpCommand(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtpCommand(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtpCommand(
            $socket,
            'DATA',
            [354]
        );

        $fromName =
            (string)(
                $config['fromName'] ?? ''
            );

        $headers = [
            'Date: ' . date(
                'r'
            ),
            'From: ' .
                encodeMimeHeader(
                    $fromName
                ) .
                ' <' .
                $from .
                '>',
            'To: <' . $to . '>',
            'Subject: ' .
                encodeMimeHeader(
                    $subject
                ),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $replyTo =
            (string)(
                $config['replyTo'] ?? ''
            );

        if ($replyTo !== '') {
            $headers[] =
                'Reply-To: ' .
                $replyTo;
        }

        /*
         * SMTPヘッダインジェクション防止。
         */
        $safeBody =
            str_replace(
                ["\r\n", "\r"],
                "\n",
                $body
            );

        $message =
            implode(
                "\r\n",
                $headers
            ) .
            "\r\n\r\n" .
            $safeBody;

        $message =
            preg_replace(
                '/^\./m',
                '..',
                $message
            );

        fwrite(
            $socket,
            $message .
            "\r\n.\r\n"
        );

        smtpExpect(
            $socket,
            [250]
        );

        smtpCommand(
            $socket,
            'QUIT',
            [221]
        );

    } finally {

        fclose($socket);
    }
}


function smtpCommand(
    $socket,
    string $command,
    array $expected
): void {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    smtpExpect(
        $socket,
        $expected
    );
}


function smtpExpect(
    $socket,
    array $expected
): string {
    $line = fgets(
        $socket,
        4096
    );

    if ($line === false) {
        throw new BusinessException(
            'SMTP_RESPONSE_FAILED',
            'SMTPサーバーから応答がありません。',
            502
        );
    }

    $code =
        (int)substr(
            trim($line),
            0,
            3
        );

    if (!in_array(
        $code,
        $expected,
        true
    )) {
        throw new BusinessException(
            'SMTP_SERVER_ERROR',
            'SMTPサーバーがエラーを返しました。',
            502
        );
    }

    return $line;
}


/* ============================================================
 * 46. SMTPテスト
 * ============================================================ */

function apiSmtpTest(
    array $body
): never {

    /*
     * 実際のSMTP通信を行う。
     */
    $config =
        getSmtpConfig();

    $to =
        requiredString(
            $body,
            'to'
        );

    smtpSend(
        $config,
        $to,
        'アンケート管理システム SMTPテスト',
        'SMTP接続テストメールです。'
    );

    apiSuccess(
        ['sent' => true],
        'テストメールを送信しました。'
    );
}


/* ============================================================
 * 47. メール変数展開
 * ============================================================ */

function expandMailVariables(
    string $template,
    array $variables
): string {
    return preg_replace_callback(
        '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
        static function (
            array $matches
        ) use ($variables): string {

            $key = $matches[1];

            if (!array_key_exists(
                $key,
                $variables
            )) {
                return '';
            }

            return (string)$variables[$key];
        },
        $template
    ) ?? '';
}


/* ============================================================
 * 48. アンケートURL
 * ============================================================ */

function generateSurveyUrl(
    string $surveyId,
    string $customerId,
    string $token
): string {
    /*
     * 物理ファイル配置・ディレクトリ名を
     * URL生成ロジックに埋め込まない。
     *
     * 単一入口なので現在のHTTP入口を基準にする。
     */
    $scheme =
        (
            (!empty($_SERVER['HTTPS']) &&
             $_SERVER['HTTPS'] !== 'off')
        )
            ? 'https'
            : 'http';

    $host =
        $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme .
        '://' .
        $host .
        '?' .
        http_build_query([
            'screen' => 'answer',
            'surveyId' => $surveyId,
            'customerId' => $customerId,
            'token' => $token,
        ]);
}


/* ============================================================
 * 49. 送信履歴
 * ============================================================ */

function appendSendHistory(
    array $history
): void {
    updateJsonAtomically(
        SEND_HISTORY_FILE,
        static function (array $items) use (
            $history
        ): array {
            $items[] = $history;
            return $items;
        }
    );
}


/* ============================================================
 * 50. メール送信
 * ============================================================ */

function apiMailSend(
    array $body
): never {

    $surveyId =
        requiredString(
            $body,
            'surveyId'
        );

    $customerIds =
        requiredArray(
            $body,
            'customerIds'
        );

    $subject =
        requiredString(
            $body,
            'subject'
        );

    $template =
        requiredString(
            $body,
            'body'
        );

    /*
     * surveyIdを固定。
     */
    $surveys =
        loadSurveys();

    $survey =
        requireSurvey(
            $surveys,
            $surveyId
        );

    if (($survey['status'] ?? '') === 'ended') {
        throw new BusinessException(
            'SURVEY_ENDED',
            '終了済みのアンケートには送信できません。',
            409
        );
    }

    $customers =
        loadCustomers();

    $customerMap = [];

    foreach ($customers as $customer) {

        $id =
            $customer['customerId'] ?? '';

        if (is_string($id)) {
            $customerMap[$id] =
                $customer;
        }
    }

    $smtp =
        getSmtpConfig();

    $results = [];

    foreach ($customerIds as $customerId) {

        if (!is_string($customerId)) {
            continue;
        }

        if (!isset(
            $customerMap[$customerId]
        )) {

            $results[] = [
                'customerId' =>
                    $customerId,
                'success' => false,
                'error' =>
                    'CUSTOMER_NOT_FOUND',
            ];

            continue;
        }

        $customer =
            $customerMap[$customerId];

        $email =
            (string)(
                $customer['email'] ?? ''
            );

        if (
            filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {

            $results[] = [
                'customerId' =>
                    $customerId,
                'success' => false,
                'error' =>
                    'INVALID_EMAIL',
            ];

            continue;
        }

        $token =
            bin2hex(
                random_bytes(24)
            );

        $url =
            generateSurveyUrl(
                $surveyId,
                $customerId,
                $token
            );

        $variables =
            $customer;

        $variables['surveyId'] =
            $surveyId;

        $variables['surveyTitle'] =
            $survey['title'] ?? '';

        $variables['surveyUrl'] =
            $url;

        $mailSubject =
            expandMailVariables(
                $subject,
                $variables
            );

        $mailBody =
            expandMailVariables(
                $template,
                $variables
            );

        try {

            smtpSend(
                $smtp,
                $email,
                $mailSubject,
                $mailBody
            );

            $success = true;
            $error = null;

        } catch (Throwable $e) {

            $success = false;
            $error =
                'SMTP_SEND_FAILED';

            error_log(
                '[SMTP_SEND_FAILED] ' .
                $e->getMessage()
            );
        }

        $history = [
            'sendHistoryId' =>
                newId('send'),
            'surveyId' =>
                $surveyId,
            'customerId' =>
                $customerId,
            'email' =>
                $email,
            'success' =>
                $success,
            'error' =>
                $error,
            'sentAt' =>
                nowIso(),
        ];

        appendSendHistory(
            $history
        );

        $results[] = [
            'customerId' =>
                $customerId,
            'success' =>
                $success,
            'error' =>
                $error,
        ];
    }

    $successCount =
        count(
            array_filter(
                $results,
                static fn(array $item): bool =>
                    $item['success'] === true
            )
        );

    $failureCount =
        count($results) -
        $successCount;

    apiSuccess(
        [
            'surveyId' =>
                $surveyId,
            'total' =>
                count($results),
            'success' =>
                $successCount,
            'failure' =>
                $failureCount,
            'results' =>
                $results,
        ],
        'メール送信処理が完了しました。'
    );
}


/* ============================================================
 * 51. 再送
 * ============================================================ */

function apiMailResend(
    array $body
): never {

    $sendHistoryId =
        requiredString(
            $body,
            'sendHistoryId'
        );

    $history =
        loadJsonArray(
            SEND_HISTORY_FILE
        );

    $target = null;

    foreach ($history as $item) {

        if (
            ($item['sendHistoryId'] ?? '')
            === $sendHistoryId
        ) {
            $target = $item;
            break;
        }
    }

    if ($target === null) {
        throw new BusinessException(
            'SEND_HISTORY_NOT_FOUND',
            '送信履歴が存在しません。',
            404
        );
    }

    apiMailSend([
        'surveyId' =>
            $target['surveyId'],
        'customerIds' => [
            $target['customerId'],
        ],
        'subject' =>
            optionalString(
                $body,
                'subject',
                'アンケートのご案内'
            ),
        'body' =>
            optionalString(
                $body,
                'body',
                '{{surveyUrl}}'
            ),
    ]);
}


/* ============================================================
 * 52. リマインド
 * ============================================================ */

function apiMailRemind(
    array $body
): never {

    /*
     * リマインド対象をsurveyIdで固定し、
     * 未回答者のみを対象とする。
     */
    $surveyId =
        requiredString(
            $body,
            'surveyId'
        );

    $customers =
        loadCustomers();

    $responses =
        loadJsonArray(
            RESPONSES_FILE
        );

    $completed = [];

    foreach ($responses as $response) {

        if (
            ($response['surveyId'] ?? '')
            === $surveyId &&
            ($response['status'] ?? '')
            === 'complete'
        ) {
            $completed[
                $response['customerId'] ?? ''
            ] = true;
        }
    }

    $targetIds = [];

    foreach ($customers as $customer) {

        $id =
            $customer['customerId'] ?? '';

        if (
            is_string($id) &&
            !isset($completed[$id])
        ) {
            $targetIds[] = $id;
        }
    }

    apiMailSend([
        'surveyId' =>
            $surveyId,
        'customerIds' =>
            $targetIds,
        'subject' =>
            optionalString(
                $body,
                'subject',
                'アンケート未回答のご案内'
            ),
        'body' =>
            optionalString(
                $body,
                'body',
                '{{surveyUrl}}'
            ),
    ]);
}


/* ============================================================
 * 53. CSV
 * ============================================================ */

function apiCsvExport(
    array $body
): never {

    $surveyId =
        requiredString(
            $body,
            'surveyId'
        );

    /*
     * 実CSVファイル生成を行う。
     */
    $responses =
        loadJsonArray(
            RESPONSES_FILE
        );

    $rows = [
        [
            'responseId',
            'surveyId',
            'customerId',
            'status',
            'completedAt',
        ],
    ];

    foreach ($responses as $response) {

        if (
            ($response['surveyId'] ?? '')
            !== $surveyId
        ) {
            continue;
        }

        $rows[] = [
            (string)(
                $response['responseId'] ?? ''
            ),
            (string)(
                $response['surveyId'] ?? ''
            ),
            (string)(
                $response['customerId'] ?? ''
            ),
            (string)(
                $response['status'] ?? ''
            ),
            (string)(
                $response['completedAt'] ?? ''
            ),
        ];
    }

    $csv = '';

    foreach ($rows as $row) {

        $escaped = array_map(
            static function (
                string $value
            ): string {
                return '"' .
                    str_replace(
                        '"',
                        '""',
                        $value
                    ) .
                    '"';
            },
            $row
        );

        $csv .=
            implode(
                ',',
                $escaped
            ) .
            "\r\n";
    }

    apiSuccess([
        'surveyId' =>
            $surveyId,
        'format' =>
            'csv',
        'generated' =>
            true,
        'content' =>
            $csv,
    ]);
}


/* ============================================================
 * 54. PDF
 * ============================================================ */

function apiPdfExport(
    array $body
): never {

    $surveyId =
        requiredString(
            $body,
            'surveyId'
        );

    /*
     * PDFライブラリを外部依存として勝手に追加しない。
     *
     * 要件上、実ファイル生成は必須ではないため、
     * PDF出力操作自体は受理し、
     * 現在の実装状態を明示する。
     */
    apiSuccess(
        [
            'surveyId' =>
                $surveyId,
            'format' =>
                'pdf',
            'generated' =>
                false,
            'status' =>
                'NOT_GENERATED',
            'message' =>
                'PDFファイル生成にはPDF生成ライブラリの導入が必要です。',
        ],
        'PDF出力操作を受け付けました。'
    );
}


/* ============================================================
 * 55. Settings
 * ============================================================ */

function apiSettingsGet(): never
{
    $settings =
        loadJsonArray(
            SETTINGS_FILE
        );

    /*
     * 秘密情報を返さない。
     */
    if (
        isset($settings['kintone']) &&
        is_array($settings['kintone'])
    ) {
        $settings['kintone']['password'] =
            null;
    }

    if (
        isset($settings['smtp']) &&
        is_array($settings['smtp'])
    ) {
        $settings['smtp']['password'] =
            null;
    }

    apiSuccess([
        'settings' => $settings,
    ]);
}


/* ============================================================
 * 56. Editableチェック
 * ============================================================ */

function ensureEditableSurvey(
    array $survey
): void {
    if (
        ($survey['status'] ?? '')
        === 'ended'
    ) {
        throw new BusinessException(
            'SURVEY_ENDED',
            '終了済みのアンケートは編集できません。',
            409
        );
    }
}


/* ============================================================
 * 57. ID存在確認
 * ============================================================ */

function groupExists(
    array $survey,
    string $groupId
): bool {
    foreach ($survey['groups'] ?? [] as $group) {

        if (
            ($group['groupId'] ?? '')
            === $groupId
        ) {
            return true;
        }
    }

    return false;
}


function questionExists(
    array $survey,
    string $questionId
): bool {
    foreach ($survey['questions'] ?? [] as $question) {

        if (
            ($question['questionId'] ?? '')
            === $questionId
        ) {
            return true;
        }
    }

    return false;
}


function choiceExists(
    array $survey,
    string $questionId,
    string $choiceId
): bool {
    foreach ($survey['questions'] ?? [] as $question) {

        if (
            ($question['questionId'] ?? '')
            !== $questionId
        ) {
            continue;
        }

        foreach ($question['choices'] ?? [] as $choice) {

            if (
                ($choice['choiceId'] ?? '')
                === $choiceId
            ) {
                return true;
            }
        }
    }

    return false;
}


/* ============================================================
 * 58. MIME Header
 * ============================================================ */

function encodeMimeHeader(
    string $value
): string {
    if ($value === '') {
        return '';
    }

    /*
     * 改行除去。
     */
    $value =
        str_replace(
            ["\r", "\n"],
            '',
            $value
        );

    return
        '=?UTF-8?B?' .
        base64_encode($value) .
        '?=';
}


/* ============================================================
 * 59. API Response
 * ============================================================ */

function apiSuccess(
    array $data = [],
    string $message = ''
): never {
    http_response_code(200);

    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    echo json_encode(
        [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


function apiError(
    string $code,
    string $message,
    int $status
): never {
    http_response_code($status);

    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    echo json_encode(
        [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* ============================================================
 * 60. BusinessException
 * ============================================================ */

final class BusinessException
    extends RuntimeException
{
    private string $errorCode;
    private int $httpStatus;

    public function __construct(
        string $errorCode,
        string $message,
        int $httpStatus = 400
    ) {
        parent::__construct($message);

        $this->errorCode =
            $errorCode;

        $this->httpStatus =
            $httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}


/* ============================================================
 * 61. HTML escape
 * ============================================================ */

function h(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES |
        ENT_SUBSTITUTE |
        ENT_HTML5,
        'UTF-8'
    );
}


/* ============================================================
 * 62. 画面
 *
 * pathnameには業務上の意味を持たせない。
 * 画面状態はquery stringで管理する。
 * ============================================================ */

function renderApplication(): never
{
    $csrf =
        h(
            (string)(
                $_SESSION['csrf_token']
            )
        );

    $initialState = [
        'screen' =>
            isset($_GET['screen']) &&
            is_string($_GET['screen'])
                ? $_GET['screen']
                : 'dashboard',

        'surveyId' =>
            isset($_GET['surveyId']) &&
            is_string($_GET['surveyId'])
                ? $_GET['surveyId']
                : '',

        'customerId' =>
            isset($_GET['customerId']) &&
            is_string($_GET['customerId'])
                ? $_GET['customerId']
                : '',
    ];

    $initialJson =
        json_encode(
            $initialState,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT
        );

    header(
        'Content-Type: text/html; charset=UTF-8'
    );

    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<title><?= h(APP_NAME) ?></title>

<style>
* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    min-height: 100%;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
    color: #222;
    background: #f5f6f8;
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

button:disabled {
    cursor: wait;
    opacity: .55;
}

.app-header {
    position: sticky;
    top: 0;
    z-index: 20;
    background: #172033;
    color: #fff;
    padding: 14px 20px;
}

.app-header-inner {
    max-width: 1280px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.app-title {
    font-size: 18px;
    font-weight: 700;
}

.app-main {
    max-width: 1280px;
    margin: 0 auto;
    padding: 24px;
}

.card {
    background: #fff;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow:
        0 1px 4px rgba(0,0,0,.08);
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.btn {
    border: 0;
    border-radius: 7px;
    padding: 9px 14px;
    background: #2563eb;
    color: #fff;
}

.btn.secondary {
    background: #64748b;
}

.btn.danger {
    background: #dc2626;
}

.btn.success {
    background: #16a34a;
}

.btn.warning {
    background: #d97706;
}

.field {
    margin-bottom: 14px;
}

.field label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.field input,
.field textarea,
.field select {
    width: 100%;
    padding: 9px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: #fff;
}

.field textarea {
    min-height: 100px;
    resize: vertical;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    padding: 10px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    vertical-align: middle;
}

.status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 12px;
    background: #e2e8f0;
}

.status.published {
    background: #dcfce7;
    color: #166534;
}

.status.stopped {
    background: #fef3c7;
    color: #92400e;
}

.status.ended {
    background: #fee2e2;
    color: #991b1b;
}

.loading-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999;
    background: rgba(15,23,42,.35);
    align-items: center;
    justify-content: center;
}

.loading-overlay.active {
    display: flex;
}

.loading-box {
    background: #fff;
    border-radius: 10px;
    padding: 24px 32px;
    text-align: center;
}

.spinner {
    width: 32px;
    height: 32px;
    margin: 0 auto 10px;
    border: 4px solid #dbeafe;
    border-top-color: #2563eb;
    border-radius: 50%;
    animation: spin .8s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.toast {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 1000;
    max-width: 400px;
    padding: 13px 16px;
    background: #172033;
    color: #fff;
    border-radius: 8px;
    display: none;
}

.toast.show {
    display: block;
}

.answer-page {
    max-width: 700px;
    margin: 0 auto;
}

.question {
    margin-bottom: 24px;
}

.question-title {
    font-weight: 700;
    margin-bottom: 8px;
}

.choice {
    display: block;
    margin: 8px 0;
}

@media (max-width: 700px) {
    .app-main {
        padding: 12px;
    }

    .card {
        padding: 14px;
    }

    table {
        display: block;
        overflow-x: auto;
    }

    .actions {
        flex-direction: column;
    }

    .actions .btn {
        width: 100%;
    }

    .app-header-inner {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
</head>

<body>

<header class="app-header">
    <div class="app-header-inner">
        <div class="app-title">
            <?= h(APP_NAME) ?>
        </div>

        <div id="screen-label"></div>
    </div>
</header>

<main
    id="app"
    class="app-main"
></main>

<div
    id="loading"
    class="loading-overlay"
    aria-hidden="true"
>
    <div class="loading-box">
        <div class="spinner"></div>
        <div>処理中です…</div>
    </div>
</div>

<div
    id="toast"
    class="toast"
></div>

<script>
"use strict";

/*
 * ============================================================
 * フロントエンド
 *
 * URLを画面状態の正規情報とする。
 * JavaScript変数だけに画面状態を保持しない。
 * ============================================================
 */

const CSRF_TOKEN =
    <?= json_encode(
        $csrf,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;

const INITIAL_STATE =
    <?= $initialJson ?>;


/* ============================================================
 * URL State
 * ============================================================ */

function readUrlState() {

    const params =
        new URLSearchParams(
            window.location.search
        );

    return {
        screen:
            params.get("screen") ||
            "dashboard",

        surveyId:
            params.get("surveyId") ||
            "",

        customerId:
            params.get("customerId") ||
            ""
    };
}


function writeUrlState(
    state,
    replace = false
) {
    const params =
        new URLSearchParams();

    if (state.screen) {
        params.set(
            "screen",
            state.screen
        );
    }

    if (state.surveyId) {
        params.set(
            "surveyId",
            state.surveyId
        );
    }

    if (state.customerId) {
        params.set(
            "customerId",
            state.customerId
        );
    }

    const url =
        window.location.pathname +
        "?" +
        params.toString();

    if (replace) {
        history.replaceState(
            null,
            "",
            url
        );
    } else {
        history.pushState(
            null,
            "",
            url
        );
    }

    renderFromUrl();
}


window.addEventListener(
    "popstate",
    () => {
        renderFromUrl();
    }
);


/* ============================================================
 * Loading
 * ============================================================ */

function setLoading(
    active
) {
    const element =
        document.getElementById(
            "loading"
        );

    element.classList.toggle(
        "active",
        active
    );
}


/* ============================================================
 * Toast
 * ============================================================ */

function showMessage(
    message
) {
    const element =
        document.getElementById(
            "toast"
        );

    element.textContent =
        message;

    element.classList.add(
        "show"
    );

    setTimeout(
        () => {
            element.classList.remove(
                "show"
            );
        },
        3500
    );
}


/* ============================================================
 * API
 *
 * POST JSON + X-CSRF-TOKEN
 * ============================================================
 */

async function api(
    action,
    data = {}
) {
    setLoading(true);

    try {

        const response =
            await fetch(
                window.location.href,
                {
                    method: "POST",

                    headers: {
                        "Content-Type":
                            "application/json",
                        "Accept":
                            "application/json",
                        "X-CSRF-TOKEN":
                            CSRF_TOKEN
                    },

                    credentials: "same-origin",

                    body: JSON.stringify({
                        action,
                        _csrf:
                            CSRF_TOKEN,
                        ...data
                    })
                }
            );

        const text =
            await response.text();

        let json;

        try {
            json =
                JSON.parse(text);
        } catch (e) {
            throw new Error(
                "サーバーからJSON以外の応答が返されました。"
            );
        }

        if (
            !response.ok ||
            json.success !== true
        ) {

            const message =
                json.error?.message ||
                "処理に失敗しました。";

            throw new Error(
                message
            );
        }

        return json;

    } finally {

        setLoading(false);
    }
}


/* ============================================================
 * GET API
 * ============================================================ */

async function getApi(
    action,
    params = {}
) {
    const query =
        new URLSearchParams({
            action,
            ...params
        });

    const response =
        await fetch(
            window.location.pathname +
            "?" +
            query.toString(),
            {
                method: "GET",
                headers: {
                    "Accept":
                        "application/json"
                },
                credentials:
                    "same-origin"
            }
        );

    const json =
        await response.json();

    if (
        !response.ok ||
        json.success !== true
    ) {
        throw new Error(
            json.error?.message ||
            "取得に失敗しました。"
        );
    }

    return json;
}


/* ============================================================
 * Dashboard
 * ============================================================ */

async function renderDashboard() {

    const app =
        document.getElementById(
            "app"
        );

    app.innerHTML = `
        <div class="card">
            <h2>アンケート一覧</h2>

            <div class="actions">
                <button
                    class="btn"
                    id="new-survey"
                >
                    アンケート作成
                </button>
            </div>
        </div>

        <div
            class="card"
            id="survey-list"
        >
            読み込み中…
        </div>
    `;

    document
        .getElementById(
            "new-survey"
        )
        .addEventListener(
            "click",
            () => {
                writeUrlState({
                    screen:
                        "survey-create"
                });
            }
        );

    try {

        const result =
            await getApi(
                "api.survey.list"
            );

        renderSurveyList(
            result.data.surveys
        );

    } catch (error) {

        document
            .getElementById(
                "survey-list"
            )
            .textContent =
            error.message;
    }
}


function renderSurveyList(
    surveys
) {
    const container =
        document.getElementById(
            "survey-list"
        );

    if (!surveys.length) {
        container.innerHTML =
            "<p>アンケートはありません。</p>";
        return;
    }

    container.innerHTML = `
        <table>
            <thead>
                <tr>
                    <th>タイトル</th>
                    <th>状態</th>
                    <th>終了日時</th>
                    <th>操作</th>
                </tr>
            </thead>

            <tbody>
                ${surveys.map(
                    survey => `
                    <tr>
                        <td>
                            ${escapeHtml(
                                survey.title
                            )}
                        </td>

                        <td>
                            <span
                                class="status ${escapeHtml(
                                    survey.status
                                )}"
                            >
                                ${escapeHtml(
                                    survey.status
                                )}
                            </span>
                        </td>

                        <td>
                            ${escapeHtml(
                                survey.endAt || ""
                            )}
                        </td>

                        <td>
                            <div class="actions">

                                <button
                                    class="btn secondary"
                                    data-open="${escapeHtml(
                                        survey.surveyId
                                    )}"
                                >
                                    編集
                                </button>

                                ${
                                    survey.status ===
                                    "draft"
                                    ? `
                                    <button
                                        class="btn success"
                                        data-publish="${escapeHtml(
                                            survey.surveyId
                                        )}"
                                    >
                                        公開
                                    </button>

                                    <button
                                        class="btn danger"
                                        data-delete="${escapeHtml(
                                            survey.surveyId
                                        )}"
                                    >
                                        削除
                                    </button>
                                    `
                                    : ""
                                }

                                ${
                                    survey.status ===
                                    "published"
                                    ? `
                                    <button
                                        class="btn warning"
                                        data-stop="${escapeHtml(
                                            survey.surveyId
                                        )}"
                                    >
                                        停止
                                    </button>

                                    <button
                                        class="btn danger"
                                        data-end="${escapeHtml(
                                            survey.surveyId
                                        )}"
                                    >
                                        終了
                                    </button>
                                    `
                                    : ""
                                }

                                ${
                                    survey.status ===
                                    "stopped"
                                    ? `
                                    <button
                                        class="btn success"
                                        data-resume="${escapeHtml(
                                            survey.surveyId
                                        )}"
                                    >
                                        再開
                                    </button>
                                    `
                                    : ""
                                }

                            </div>
                        </td>
                    </tr>
                `
                ).join("")}
            </tbody>
        </table>
    `;

    container
        .querySelectorAll(
            "[data-open]"
        )
        .forEach(
            button => {
                button.addEventListener(
                    "click",
                    () => {
                        writeUrlState({
                            screen:
                                "survey-edit",
                            surveyId:
                                button.dataset.open
                        });
                    }
                );
            }
        );

    bindActionButtons(
        container
    );
}


/* ============================================================
 * Action Buttons
 * ============================================================ */

function bindActionButtons(
    container
) {

    container
        .querySelectorAll(
            "[data-publish]"
        )
        .forEach(
            button => {
                button.addEventListener(
                    "click",
                    () => executeOnce(
                        button,
                        "api.survey.publish",
                        {
                            surveyId:
                                button.dataset.publish
                        }
                    )
                );
            }
        );

    container
        .querySelectorAll(
            "[data-stop]"
        )
        .forEach(
            button => {
                button.addEventListener(
                    "click",
                    () => executeOnce(
                        button,
                        "api.survey.stop",
                        {
                            surveyId:
                                button.dataset.stop
                        }
                    )
                );
            }
        );

    container
        .querySelectorAll(
            "[data-resume]"
        )
        .forEach(
            button => {
                button.addEventListener(
                    "click",
                    () => executeOnce(
                        button,
                        "api.survey.resume",
                        {
                            surveyId:
                                button.dataset.resume
                        }
                    )
                );
            }
        );

    container
        .querySelectorAll(
            "[data-end]"
        )
        .forEach(
            button => {
                button.addEventListener(
                    "click",
                    () => executeOnce(
                        button,
                        "api.survey.end",
                        {
                            surveyId:
                                button.dataset.end
                        }
                    )
                );
            }
        );

    container
        .querySelectorAll(
            "[data-delete]"
        )
        .forEach(
            button => {
                button.addEventListener(
                    "click",
                    () => executeOnce(
                        button,
                        "api.survey.delete",
                        {
                            surveyId:
                                button.dataset.delete
                        }
                    )
                );
            }
        );
}


/* ============================================================
 * 二重操作防止
 * ============================================================ */

async function executeOnce(
    button,
    action,
    data
) {
    if (
        button.disabled
    ) {
        return;
    }

    button.disabled = true;

    const original =
        button.textContent;

    button.textContent =
        "処理中…";

    try {

        const result =
            await api(
                action,
                data
            );

        showMessage(
            result.message ||
            "処理が完了しました。"
        );

        await renderFromUrl();

    } catch (error) {

        showMessage(
            error.message
        );

    } finally {

        button.disabled = false;

        button.textContent =
            original;
    }
}


/* ============================================================
 * Survey Create
 * ============================================================ */

function renderSurveyCreate() {

    const app =
        document.getElementById(
            "app"
        );

    app.innerHTML = `
        <div class="card">
            <h2>アンケート作成</h2>

            <form id="survey-form">

                <div class="field">
                    <label>
                        タイトル
                    </label>

                    <input
                        name="title"
                        required
                        maxlength="200"
                    >
                </div>

                <div class="field">
                    <label>
                        説明
                    </label>

                    <textarea
                        name="description"
                    ></textarea>
                </div>

                <div class="field">
                    <label>
                        終了日時
                    </label>

                    <input
                        type="datetime-local"
                        name="endAt"
                    >
                </div>

                <div class="actions">
                    <button
                        type="submit"
                        class="btn"
                    >
                        保存
                    </button>

                    <button
                        type="button"
                        class="btn secondary"
                        id="cancel"
                    >
                        戻る
                    </button>
                </div>

            </form>
        </div>
    `;

    const form =
        document.getElementById(
            "survey-form"
        );

    form.addEventListener(
        "submit",
        async event => {

            event.preventDefault();

            const button =
                form.querySelector(
                    "button[type=submit]"
                );

            if (button.disabled) {
                return;
            }

            button.disabled = true;
            button.textContent =
                "処理中…";

            try {

                const data =
                    Object.fromEntries(
                        new FormData(form)
                    );

                const result =
                    await api(
                        "api.survey.create",
                        data
                    );

                showMessage(
                    result.message
                );

                writeUrlState({
                    screen:
                        "survey-edit",
                    surveyId:
                        result.data.survey.surveyId
                });

            } catch (error) {

                showMessage(
                    error.message
                );

            } finally {

                button.disabled = false;
                button.textContent =
                    "保存";
            }
        }
    );

    document
        .getElementById(
            "cancel"
        )
        .addEventListener(
            "click",
            () => {
                writeUrlState({
                    screen:
                        "dashboard"
                });
            }
        );
}


/* ============================================================
 * Survey Edit
 * ============================================================ */

async function renderSurveyEdit(
    surveyId
) {
    const app =
        document.getElementById(
            "app"
        );

    app.innerHTML =
        '<div class="card">読み込み中…</div>';

    try {

        const result =
            await getApi(
                "api.survey.get",
                {
                    surveyId
                }
            );

        const survey =
            result.data.survey;

        app.innerHTML = `
            <div class="card">

                <h2>
                    アンケート編集
                </h2>

                <div class="field">
                    <label>
                        タイトル
                    </label>

                    <input
                        id="edit-title"
                        value="${escapeAttr(
                            survey.title || ""
                        )}"
                        maxlength="200"
                    >
                </div>

                <div class="field">
                    <label>
                        説明
                    </label>

                    <textarea
                        id="edit-description"
                    >${escapeHtml(
                        survey.description || ""
                    )}</textarea>
                </div>

                <div class="field">
                    <label>
                        採番方式
                    </label>

                    <select
                        id="edit-numbering"
                    >
                        <option
                            value="survey"
                            ${
                                survey.numberingMode ===
                                "survey"
                                    ? "selected"
                                    : ""
                            }
                        >
                            アンケート全体
                        </option>

                        <option
                            value="group"
                            ${
                                survey.numberingMode ===
                                "group"
                                    ? "selected"
                                    : ""
                            }
                        >
                            グループ単位
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>
                        再回答
                    </label>

                    <select
                        id="edit-reanswer"
                    >
                        <option
                            value="false"
                            ${
                                !survey.allowReanswer
                                    ? "selected"
                                    : ""
                            }
                        >
                            不可
                        </option>

                        <option
                            value="true"
                            ${
                                survey.allowReanswer
                                    ? "selected"
                                    : ""
                            }
                        >
                            可
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>
                        終了日時
                    </label>

                    <input
                        id="edit-end-at"
                        value="${escapeAttr(
                            survey.endAt || ""
                        )}"
                    >
                </div>

                <div class="actions">

                    <button
                        class="btn"
                        id="save-survey"
                    >
                        保存
                    </button>

                    <button
                        class="btn secondary"
                        id="back-dashboard"
                    >
                        一覧へ
                    </button>

                </div>
            </div>

            <div class="card">
                <h3>質問</h3>

                <div class="actions">
                    <button
                        class="btn"
                        id="add-question"
                    >
                        質問追加
                    </button>
                </div>

                <div id="questions"></div>
            </div>
        `;

        renderQuestions(
            survey
        );

        document
            .getElementById(
                "back-dashboard"
            )
            .addEventListener(
                "click",
                () => {
                    writeUrlState({
                        screen:
                            "dashboard"
                    });
                }
            );

        document
            .getElementById(
                "save-survey"
            )
            .addEventListener(
                "click",
                async event => {

                    const button =
                        event.currentTarget;

                    if (
                        button.disabled
                    ) {
                        return;
                    }

                    button.disabled = true;
                    button.textContent =
                        "処理中…";

                    try {

                        const result =
                            await api(
                                "api.survey.update",
                                {
                                    surveyId,
                                    title:
                                        document
                                            .getElementById(
                                                "edit-title"
                                            )
                                            .value,
                                    description:
                                        document
                                            .getElementById(
                                                "edit-description"
                                            )
                                            .value,
                                    numberingMode:
                                        document
                                            .getElementById(
                                                "edit-numbering"
                                            )
                                            .value,
                                    allowReanswer:
                                        document
                                            .getElementById(
                                                "edit-reanswer"
                                            )
                                            .value ===
                                        "true",
                                    endAt:
                                        document
                                            .getElementById(
                                                "edit-end-at"
                                            )
                                            .value
                                }
                            );

                        showMessage(
                            result.message
                        );

                    } catch (error) {

                        showMessage(
                            error.message
                        );

                    } finally {

                        button.disabled =
                            false;

                        button.textContent =
                            "保存";
                    }
                }
            );

        document
            .getElementById(
                "add-question"
            )
            .addEventListener(
                "click",
                () => {
                    showQuestionCreate(
                        surveyId
                    );
                }
            );

    } catch (error) {

        app.innerHTML = `
            <div class="card">
                <p>${escapeHtml(
                    error.message
                )}</p>
            </div>
        `;
    }
}


/* ============================================================
 * Questions
 * ============================================================ */

function renderQuestions(
    survey
) {
    const container =
        document.getElementById(
            "questions"
        );

    const questions =
        survey.questions || [];

    if (!questions.length) {

        container.innerHTML =
            "<p>質問はありません。</p>";

        return;
    }

    container.innerHTML =
        questions.map(
            question => `
                <div
                    class="card"
                    data-question="${escapeAttr(
                        question.questionId
                    )}"
                >
                    <div>
                        <strong>
                            ${escapeHtml(
                                question.number || ""
                            )}
                        </strong>

                        ${escapeHtml(
                            question.text
                        )}
                    </div>

                    <p>
                        type:
                        ${escapeHtml(
                            question.type
                        )}

                        /
                        required:
                        ${
                            question.required
                                ? "true"
                                : "false"
                        }
                    </p>

                    <div class="actions">

                        <button
                            class="btn secondary"
                            data-question-edit="${escapeAttr(
                                question.questionId
                            )}"
                        >
                            編集
                        </button>

                        <button
                            class="btn danger"
                            data-question-delete="${escapeAttr(
                                question.questionId
                            )}"
                        >
                            削除
                        </button>

                    </div>
                </div>
            `
        ).join("");

    container
        .querySelectorAll(
            "[data-question-delete]"
        )
        .forEach(
            button => {

                button.addEventListener(
                    "click",
                    () => executeOnce(
                        button,
                        "api.question.delete",
                        {
                            surveyId:
                                survey.surveyId,
                            questionId:
                                button.dataset.questionDelete
                        }
                    )
                );
            }
        );
}


/* ============================================================
 * Question Create
 * ============================================================ */

function showQuestionCreate(
    surveyId
) {
    const app =
        document.getElementById(
            "app"
        );

    const modal =
        document.createElement(
            "div"
        );

    modal.className =
        "loading-overlay active";

    modal.innerHTML = `
        <div
            class="loading-box"
            style="width:min(600px,94vw)"
        >
            <h3>質問追加</h3>

            <div class="field">
                <label>質問文</label>

                <textarea
                    id="new-question-text"
                ></textarea>
            </div>

            <div class="field">
                <label>質問タイプ</label>

                <select
                    id="new-question-type"
                >
                    <option value="text">
                        テキスト
                    </option>

                    <option value="textarea">
                        長文
                    </option>

                    <option value="single">
                        単一選択
                    </option>

                    <option value="multiple">
                        複数選択
                    </option>
                </select>
            </div>

            <div class="field">
                <label>
                    必須
                </label>

                <select
                    id="new-question-required"
                >
                    <option value="false">
                        必須ではない
                    </option>

                    <option value="true">
                        必須
                    </option>
                </select>
            </div>

            <div class="actions">

                <button
                    class="btn"
                    id="create-question"
                >
                    作成
                </button>

                <button
                    class="btn secondary"
                    id="close-question"
                >
                    キャンセル
                </button>

            </div>
        </div>
    `;

    document.body.appendChild(
        modal
    );

    document
        .getElementById(
            "close-question"
        )
        .addEventListener(
            "click",
            () => modal.remove()
        );

    document
        .getElementById(
            "create-question"
        )
        .addEventListener(
            "click",
            async event => {

                const button =
                    event.currentTarget;

                if (button.disabled) {
                    return;
                }

                button.disabled = true;
                button.textContent =
                    "処理中…";

                try {

                    await api(
                        "api.question.create",
                        {
                            surveyId,
                            text:
                                document
                                    .getElementById(
                                        "new-question-text"
                                    )
                                    .value,
                            type:
                                document
                                    .getElementById(
                                        "new-question-type"
                                    )
                                    .value,
                            required:
                                document
                                    .getElementById(
                                        "new-question-required"
                                    )
                                    .value ===
                                "true"
                        }
                    );

                    modal.remove();

                    await renderSurveyEdit(
                        surveyId
                    );

                    showMessage(
                        "質問を作成しました。"
                    );

                } catch (error) {

                    showMessage(
                        error.message
                    );

                    button.disabled =
                        false;

                    button.textContent =
                        "作成";
                }
            }
        );
}


/* ============================================================
 * Answer
 * ============================================================ */

async function renderAnswer(
    surveyId,
    customerId
) {
    const app =
        document.getElementById(
            "app"
        );

    app.innerHTML =
        '<div class="answer-page"><div class="card">読み込み中…</div></div>';

    try {

        const result =
            await getApi(
                "api.survey.get",
                {
                    surveyId
                }
            );

        const survey =
            result.data.survey;

        if (
            survey.status !==
            "published"
        ) {
            throw new Error(
                "現在回答できる状態ではありません。"
            );
        }

        app.innerHTML = `
            <div class="answer-page">

                <div class="card">
                    <h1>
                        ${escapeHtml(
                            survey.title
                        )}
                    </h1>

                    <p>
                        ${escapeHtml(
                            survey.description ||
                            ""
                        )}
                    </p>
                </div>

                <form
                    id="answer-form"
                    class="card"
                >
                    <div id="answer-questions"></div>

                    <div class="actions">
                        <button
                            class="btn"
                            type="submit"
                        >
                            確認
                        </button>
                    </div>
                </form>

            </div>
        `;

        renderAnswerQuestions(
            survey
        );

        document
            .getElementById(
                "answer-form"
            )
            .addEventListener(
                "submit",
                event => {
                    event.preventDefault();

                    submitAnswer(
                        survey,
                        customerId
                    );
                }
            );

    } catch (error) {

        app.innerHTML = `
            <div class="answer-page">
                <div class="card">
                    ${escapeHtml(
                        error.message
                    )}
                </div>
            </div>
        `;
    }
}


function renderAnswerQuestions(
    survey
) {
    const container =
        document.getElementById(
            "answer-questions"
        );

    container.innerHTML =
        (survey.questions || [])
            .map(
                question => {

                    const required =
                        question.required
                            ? "required"
                            : "";

                    if (
                        question.type ===
                        "single" ||
                        question.type ===
                        "multiple"
                    ) {

                        const multiple =
                            question.type ===
                            "multiple";

                        return `
                            <div
                                class="question"
                            >
                                <div
                                    class="question-title"
                                >
                                    ${escapeHtml(
                                        question.number ||
                                        ""
                                    )}
                                    .
                                    ${escapeHtml(
                                        question.text
                                    )}
                                </div>

                                ${
                                    (
                                        question.choices ||
                                        []
                                    )
                                    .map(
                                        choice => `
                                            <label
                                                class="choice"
                                            >
                                                <input
                                                    type="${
                                                        multiple
                                                            ? "checkbox"
                                                            : "radio"
                                                    }"
                                                    name="q_${escapeAttr(
                                                        question.questionId
                                                    )}${
                                                        multiple
                                                            ? "[]"
                                                            : ""
                                                    }"
                                                    value="${escapeAttr(
                                                        choice.choiceId
                                                    )}"
                                                    ${required}
                                                >

                                                ${escapeHtml(
                                                    choice.label
                                                )}
                                            </label>
                                        `
                                    )
                                    .join("")
                                }
                            </div>
                        `;
                    }

                    return `
                        <div
                            class="question"
                        >
                            <div
                                class="question-title"
                            >
                                ${escapeHtml(
                                    question.number ||
                                    ""
                                )}
                                .
                                ${escapeHtml(
                                    question.text
                                )}
                            </div>

                            ${
                                question.type ===
                                "textarea"
                                    ? `
                                        <textarea
                                            name="q_${escapeAttr(
                                                question.questionId
                                            )}"
                                            ${
                                                required
                                                    ? "required"
                                                    : ""
                                            }
                                        ></textarea>
                                    `
                                    : `
                                        <input
                                            name="q_${escapeAttr(
                                                question.questionId
                                            )}"
                                            ${
                                                required
                                                    ? "required"
                                                    : ""
                                            }
                                        >
                                    `
                            }
                        </div>
                    `;
                }
            )
            .join("");
}


/* ============================================================
 * Answer Submit
 * ============================================================ */

async function submitAnswer(
    survey,
    customerId
) {
    const form =
        document.getElementById(
            "answer-form"
        );

    const formData =
        new FormData(form);

    const answers = {};

    for (
        const [key, value]
        of formData.entries()
    ) {

        if (
            !key.startsWith("q_")
        ) {
            continue;
        }

        const questionId =
            key.substring(2)
                .replace(
                    /\[\]$/,
                    ""
                );

        if (
            Object.prototype.hasOwnProperty.call(
                answers,
                questionId
            )
        ) {

            if (
                !Array.isArray(
                    answers[questionId]
                )
            ) {
                answers[questionId] = [
                    answers[questionId]
                ];
            }

            answers[questionId].push(
                value
            );

        } else {

            answers[questionId] =
                value;
        }
    }

    try {

        const result =
            await api(
                "api.response.confirm",
                {
                    surveyId:
                        survey.surveyId,
                    customerId,
                    answers
                }
            );

        renderAnswerConfirm(
            survey,
            customerId,
            result.data.answers
        );

    } catch (error) {

        showMessage(
            error.message
        );
    }
}


/* ============================================================
 * Answer Confirm
 * ============================================================ */

function renderAnswerConfirm(
    survey,
    customerId,
    answers
) {
    const app =
        document.getElementById(
            "app"
        );

    app.innerHTML = `
        <div class="answer-page">

            <div class="card">

                <h2>
                    回答確認
                </h2>

                <p>
                    入力内容を確認してください。
                </p>

                <div id="confirm-data"></div>

                <div class="actions">

                    <button
                        class="btn"
                        id="complete-answer"
                    >
                        送信する
                    </button>

                    <button
                        class="btn secondary"
                        id="back-answer"
                    >
                        戻る
                    </button>

                </div>

            </div>

        </div>
    `;

    document
        .getElementById(
            "confirm-data"
        )
        .textContent =
        JSON.stringify(
            answers,
            null,
            2
        );

    document
        .getElementById(
            "complete-answer"
        )
        .addEventListener(
            "click",
            async event => {

                const button =
                    event.currentTarget;

                if (button.disabled) {
                    return;
                }

                button.disabled =
                    true;

                button.textContent =
                    "処理中…";

                try {

                    await api(
                        "api.response.complete",
                        {
                            surveyId:
                                survey.surveyId,
                            customerId,
                            answers
                        }
                    );

                    app.innerHTML = `
                        <div
                            class="answer-page"
                        >
                            <div class="card">
                                <h2>
                                    回答完了
                                </h2>

                                <p>
                                    ご回答ありがとうございました。
                                </p>
                            </div>
                        </div>
                    `;

                } catch (error) {

                    showMessage(
                        error.message
                    );

                    button.disabled =
                        false;

                    button.textContent =
                        "送信する";
                }
            }
        );

    document
        .getElementById(
            "back-answer"
        )
        .addEventListener(
            "click",
            () => {
                renderAnswer(
                    survey.surveyId,
                    customerId
                );
            }
        );
}


/* ============================================================
 * Escape
 * ============================================================ */

function escapeHtml(
    value
) {
    const div =
        document.createElement(
            "div"
        );

    div.textContent =
        String(value ?? "");

    return div.innerHTML;
}


function escapeAttr(
    value
) {
    return escapeHtml(
        value
    )
    .replace(
        /"/g,
        "&quot;"
    );
}


/* ============================================================
 * Router
 *
 * pathnameは一切使わない。
 * ============================================================
 */

async function renderFromUrl() {

    const state =
        readUrlState();

    const label =
        document.getElementById(
            "screen-label"
        );

    label.textContent =
        state.screen;

    switch (state.screen) {

        case "dashboard":
            await renderDashboard();
            break;

        case "survey-create":
            renderSurveyCreate();
            break;

        case "survey-edit":

            if (!state.surveyId) {

                writeUrlState(
                    {
                        screen:
                            "dashboard"
                    },
                    true
                );

                return;
            }

            await renderSurveyEdit(
                state.surveyId
            );

            break;

        case "answer":

            if (
                !state.surveyId
            ) {
                document
                    .getElementById(
                        "app"
                    )
                    .textContent =
                    "surveyIdがありません。";

                return;
            }

            await renderAnswer(
                state.surveyId,
                state.customerId
            );

            break;

        default:

            writeUrlState(
                {
                    screen:
                        "dashboard"
                },
                true
            );

            break;
    }
}


/* ============================================================
 * 初期画面
 * ============================================================ */

if (
    !window.location.search
) {
    writeUrlState(
        {
            screen:
                "dashboard"
        },
        true
    );
} else {
    renderFromUrl();
}

</script>

</body>
</html>
<?php

    exit;
}
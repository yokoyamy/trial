<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 *
 * 実装範囲
 * - 単一HTTP入口
 * - JSON永続化
 * - survey一覧取得
 * - survey取得
 * - survey新規作成
 * - survey更新
 * - survey削除
 * - draft -> published
 * - published -> stopped
 * - stopped -> published
 * - published -> ended
 * - 終了判定
 *
 * 未実装機能を成功扱いにはしない。
 */

/* =========================================================
 * 基本設定
 * ========================================================= */

const APP_ROOT = __DIR__;
const DATA_DIR = APP_ROOT . '/data';
const SURVEYS_FILE = DATA_DIR . '/surveys.json';

date_default_timezone_set('Asia/Tokyo');


/* =========================================================
 * 起動処理
 * ========================================================= */

initializeStorage();

$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!is_string($action)) {
    $action = '';
}

try {
    if ($requestMethod === 'GET') {
        handleGet($action);
    }

    if ($requestMethod === 'POST') {
        handlePost($action);
    }

    errorResponse(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405
    );
} catch (Throwable $e) {
    errorResponse(
        'INTERNAL_ERROR',
        'システム内部でエラーが発生しました。',
        500
    );
}


/* =========================================================
 * GET
 * ========================================================= */

function handleGet(string $action): never
{
    switch ($action) {

        case '':
        case 'screen':
            renderScreen();
            break;

        case 'api.survey.list':
            apiSurveyList();
            break;

        case 'api.survey.get':
            apiSurveyGet();
            break;

        default:
            errorResponse(
                'INVALID_ACTION',
                '指定された操作は存在しません。',
                400
            );
    }
}


/* =========================================================
 * POST
 * ========================================================= */

function handlePost(string $action): never
{
    verifyCsrf();

    switch ($action) {

        case 'api.survey.create':
            apiSurveyCreate();
            break;

        case 'api.survey.update':
            apiSurveyUpdate();
            break;

        case 'api.survey.delete':
            apiSurveyDelete();
            break;

        case 'api.survey.publish':
            apiSurveyPublish();
            break;

        case 'api.survey.stop':
            apiSurveyStop();
            break;

        case 'api.survey.resume':
            apiSurveyResume();
            break;

        case 'api.survey.end':
            apiSurveyEnd();
            break;

        default:
            errorResponse(
                'INVALID_ACTION',
                '指定された操作は存在しません。',
                400
            );
    }
}


/* =========================================================
 * API: survey一覧
 * ========================================================= */

function apiSurveyList(): never
{
    $surveys = loadSurveys();

    /*
     * 公開中かつendAtを過ぎているものは、
     * 参照時にも終了判定を行う。
     */
    $changed = false;

    foreach ($surveys as &$survey) {
        if (shouldEndSurvey($survey)) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = nowIso8601();
            $changed = true;
        }
    }
    unset($survey);

    if ($changed) {
        saveSurveys($surveys);
    }

    successResponse([
        'surveys' => $surveys,
    ]);
}


/* =========================================================
 * API: survey取得
 * ========================================================= */

function apiSurveyGet(): never
{
    $surveyId = requiredString($_GET, 'surveyId');

    $surveys = loadSurveys();

    $survey = findSurvey($surveys, $surveyId);

    if ($survey === null) {
        errorResponse(
            'SURVEY_NOT_FOUND',
            '指定されたアンケートが存在しません。',
            404
        );
    }

    if (shouldEndSurvey($survey)) {
        updateSurveyToEnded($surveyId);

        $survey['status'] = 'ended';
    }

    successResponse([
        'survey' => $survey,
    ]);
}


/* =========================================================
 * API: survey作成
 * ========================================================= */

function apiSurveyCreate(): never
{
    $input = readJsonBody();

    $title = requiredString($input, 'title');

    if (mb_strlen($title) > 200) {
        errorResponse(
            'INVALID_TITLE',
            'アンケートタイトルは200文字以内で指定してください。',
            400
        );
    }

    $description = stringValue($input, 'description', '');

    $endAt = nullableDateTime($input, 'endAt');

    $now = nowIso8601();

    $survey = [
        'surveyId' => generateId('survey'),
        'title' => $title,
        'description' => $description,
        'status' => 'draft',
        'numberingMode' => 'survey',
        'endAt' => $endAt,
        'groups' => [],
        'questions' => [],
        'createdAt' => $now,
        'updatedAt' => $now,
    ];

    updateSurveysAtomically(
        static function (array $surveys) use ($survey): array {
            $surveys[] = $survey;
            return $surveys;
        }
    );

    successResponse(
        ['survey' => $survey],
        'アンケートを作成しました。'
    );
}


/* =========================================================
 * API: survey更新
 * ========================================================= */

function apiSurveyUpdate(): never
{
    $input = readJsonBody();

    $surveyId = requiredString($input, 'surveyId');
    $title = requiredString($input, 'title');

    if (mb_strlen($title) > 200) {
        errorResponse(
            'INVALID_TITLE',
            'アンケートタイトルは200文字以内で指定してください。',
            400
        );
    }

    $description = stringValue($input, 'description', '');

    $endAt = nullableDateTime($input, 'endAt');

    $updatedSurvey = null;

    updateSurveysAtomically(
        static function (array $surveys) use (
            $surveyId,
            $title,
            $description,
            $endAt,
            &$updatedSurvey
        ): array {

            $found = false;

            foreach ($surveys as &$survey) {

                if ($survey['surveyId'] !== $surveyId) {
                    continue;
                }

                $found = true;

                if ($survey['status'] === 'ended') {
                    errorResponse(
                        'SURVEY_ENDED',
                        '終了済みのアンケートは編集できません。',
                        409
                    );
                }

                $survey['title'] = $title;
                $survey['description'] = $description;
                $survey['endAt'] = $endAt;
                $survey['updatedAt'] = nowIso8601();

                $updatedSurvey = $survey;
                break;
            }

            unset($survey);

            if (!$found) {
                errorResponse(
                    'SURVEY_NOT_FOUND',
                    '指定されたアンケートが存在しません。',
                    404
                );
            }

            return $surveys;
        }
    );

    successResponse(
        ['survey' => $updatedSurvey],
        'アンケートを更新しました。'
    );
}


/* =========================================================
 * API: survey削除
 * ========================================================= */

function apiSurveyDelete(): never
{
    $input = readJsonBody();

    $surveyId = requiredString($input, 'surveyId');

    $deleted = false;

    updateSurveysAtomically(
        static function (array $surveys) use (
            $surveyId,
            &$deleted
        ): array {

            foreach ($surveys as $survey) {

                if ($survey['surveyId'] !== $surveyId) {
                    continue;
                }

                if ($survey['status'] !== 'draft') {
                    errorResponse(
                        'SURVEY_DELETE_NOT_ALLOWED',
                        '下書き状態のアンケートのみ削除できます。',
                        409
                    );
                }

                $deleted = true;
                break;
            }

            if (!$deleted) {
                errorResponse(
                    'SURVEY_NOT_FOUND',
                    '指定されたアンケートが存在しません。',
                    404
                );
            }

            return array_values(
                array_filter(
                    $surveys,
                    static fn(array $survey): bool =>
                        $survey['surveyId'] !== $surveyId
                )
            );
        }
    );

    successResponse(
        ['surveyId' => $surveyId],
        'アンケートを削除しました。'
    );
}


/* =========================================================
 * API: 公開
 * ========================================================= */

function apiSurveyPublish(): never
{
    changeSurveyStatus('publish');
}


/* =========================================================
 * API: 停止
 * ========================================================= */

function apiSurveyStop(): never
{
    changeSurveyStatus('stop');
}


/* =========================================================
 * API: 再開
 * ========================================================= */

function apiSurveyResume(): never
{
    changeSurveyStatus('resume');
}


/* =========================================================
 * API: 終了
 * ========================================================= */

function apiSurveyEnd(): never
{
    $input = readJsonBody();

    $surveyId = requiredString($input, 'surveyId');

    $result = null;

    updateSurveysAtomically(
        static function (array $surveys) use (
            $surveyId,
            &$result
        ): array {

            foreach ($surveys as &$survey) {

                if ($survey['surveyId'] !== $surveyId) {
                    continue;
                }

                if ($survey['status'] === 'ended') {
                    errorResponse(
                        'INVALID_STATE',
                        '終了済みのアンケートです。',
                        409
                    );
                }

                if ($survey['status'] !== 'published') {
                    errorResponse(
                        'INVALID_STATE',
                        '公開中のアンケートのみ終了できます。',
                        409
                    );
                }

                $survey['status'] = 'ended';
                $survey['updatedAt'] = nowIso8601();

                $result = $survey;

                return $surveys;
            }

            unset($survey);

            errorResponse(
                'SURVEY_NOT_FOUND',
                '指定されたアンケートが存在しません。',
                404
            );
        }
    );

    successResponse(
        ['survey' => $result],
        'アンケートを終了しました。'
    );
}


/* =========================================================
 * 状態変更
 * ========================================================= */

function changeSurveyStatus(string $operation): never
{
    $input = readJsonBody();

    $surveyId = requiredString($input, 'surveyId');

    $result = null;

    updateSurveysAtomically(
        static function (array $surveys) use (
            $surveyId,
            $operation,
            &$result
        ): array {

            foreach ($surveys as &$survey) {

                if ($survey['surveyId'] !== $surveyId) {
                    continue;
                }

                $current = $survey['status'];

                /*
                 * endedからは絶対に変更しない。
                 */
                if ($current === 'ended') {
                    errorResponse(
                        'INVALID_STATE',
                        '終了済みのアンケートは状態変更できません。',
                        409
                    );
                }

                $next = null;

                switch ($operation) {

                    case 'publish':
                        if ($current !== 'draft') {
                            errorResponse(
                                'INVALID_STATE',
                                '下書き状態からのみ公開できます。',
                                409
                            );
                        }

                        $next = 'published';
                        break;

                    case 'stop':
                        if ($current !== 'published') {
                            errorResponse(
                                'INVALID_STATE',
                                '公開中のアンケートのみ停止できます。',
                                409
                            );
                        }

                        $next = 'stopped';
                        break;

                    case 'resume':
                        if ($current !== 'stopped') {
                            errorResponse(
                                'INVALID_STATE',
                                '停止中のアンケートのみ再開できます。',
                                409
                            );
                        }

                        $next = 'published';
                        break;

                    default:
                        errorResponse(
                            'INVALID_OPERATION',
                            '不正な状態変更操作です。',
                            400
                        );
                }

                $survey['status'] = $next;
                $survey['updatedAt'] = nowIso8601();

                $result = $survey;

                return $surveys;
            }

            unset($survey);

            errorResponse(
                'SURVEY_NOT_FOUND',
                '指定されたアンケートが存在しません。',
                404
            );
        }
    );

    $messages = [
        'publish' => 'アンケートを公開しました。',
        'stop' => 'アンケートを停止しました。',
        'resume' => 'アンケートを再開しました。',
    ];

    successResponse(
        ['survey' => $result],
        $messages[$operation]
    );
}


/* =========================================================
 * 終了判定
 * ========================================================= */

function shouldEndSurvey(array $survey): bool
{
    if (($survey['status'] ?? null) !== 'published') {
        return false;
    }

    $endAt = $survey['endAt'] ?? null;

    if (!is_string($endAt) || $endAt === '') {
        return false;
    }

    try {
        $endDate = new DateTimeImmutable($endAt);
        $now = new DateTimeImmutable('now');

        return $now > $endDate;

    } catch (Throwable) {
        return false;
    }
}


/* =========================================================
 * 終了状態への更新
 * ========================================================= */

function updateSurveyToEnded(string $surveyId): void
{
    updateSurveysAtomically(
        static function (array $surveys) use ($surveyId): array {

            foreach ($surveys as &$survey) {

                if ($survey['surveyId'] !== $surveyId) {
                    continue;
                }

                if (shouldEndSurvey($survey)) {
                    $survey['status'] = 'ended';
                    $survey['updatedAt'] = nowIso8601();
                }

                break;
            }

            unset($survey);

            return $surveys;
        }
    );
}


/* =========================================================
 * JSON Repository
 * ========================================================= */

function initializeStorage(): void
{
    if (!is_dir(DATA_DIR)) {

        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'データディレクトリを作成できません。'
            );
        }
    }

    if (!file_exists(SURVEYS_FILE)) {

        atomicWriteJson(
            SURVEYS_FILE,
            []
        );
    }
}


function loadSurveys(): array
{
    return loadJsonArray(SURVEYS_FILE);
}


function saveSurveys(array $surveys): void
{
    atomicWriteJson(
        SURVEYS_FILE,
        $surveys
    );
}


/**
 * 読み込み→変更→書き込みを同一ロック内で行う。
 */
function updateSurveysAtomically(
    callable $callback
): void {

    $lockFile = SURVEYS_FILE . '.lock';

    $handle = fopen($lockFile, 'c+');

    if ($handle === false) {
        throw new RuntimeException(
            'ロックファイルを開けません。'
        );
    }

    try {

        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(
                'データロックを取得できません。'
            );
        }

        $surveys = loadJsonArray(SURVEYS_FILE);

        $newSurveys = $callback($surveys);

        if (!is_array($newSurveys)) {
            throw new RuntimeException(
                'Repository更新処理の結果が不正です。'
            );
        }

        atomicWriteJson(
            SURVEYS_FILE,
            $newSurveys
        );

        flock($handle, LOCK_UN);

    } finally {

        fclose($handle);
    }
}


/**
 * JSON読み込み。
 *
 * 以下をエラーとして扱う。
 * - ファイル不存在
 * - 空ファイル
 * - 不正JSON
 * - 想定外構造
 */
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

    /*
     * surveys.jsonはsurveyオブジェクトの配列でなければならない。
     */
    foreach ($data as $survey) {

        if (!is_array($survey)) {
            throw new RuntimeException(
                'surveyデータの構造が不正です。'
            );
        }

        if (
            !isset($survey['surveyId']) ||
            !is_string($survey['surveyId']) ||
            $survey['surveyId'] === ''
        ) {
            throw new RuntimeException(
                'surveyIdが存在しないsurveyがあります。'
            );
        }

        if (
            !isset($survey['status']) ||
            !in_array(
                $survey['status'],
                [
                    'draft',
                    'published',
                    'stopped',
                    'ended',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'surveyのstatusが不正です。'
            );
        }
    }

    return array_values($data);
}


/**
 * 一時ファイルへ書き込み後、元ファイルを置換。
 */
function atomicWriteJson(
    string $file,
    array $data
): void {

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
    );

    $directory = dirname($file);

    $temporary = tempnam(
        $directory,
        basename($file) . '.tmp.'
    );

    if ($temporary === false) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    try {

        $result = file_put_contents(
            $temporary,
            $json . PHP_EOL,
            LOCK_EX
        );

        if ($result === false) {
            throw new RuntimeException(
                'JSONデータを書き込めません。'
            );
        }

        if (!rename($temporary, $file)) {
            throw new RuntimeException(
                'JSONファイルを置換できません。'
            );
        }

    } finally {

        if (file_exists($temporary)) {
            @unlink($temporary);
        }
    }
}


/* =========================================================
 * survey検索
 * ========================================================= */

function findSurvey(
    array $surveys,
    string $surveyId
): ?array {

    foreach ($surveys as $survey) {

        if (
            isset($survey['surveyId']) &&
            $survey['surveyId'] === $surveyId
        ) {
            return $survey;
        }
    }

    return null;
}


/* =========================================================
 * CSRF
 * ========================================================= */

function verifyCsrf(): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    if (
        !is_string($sessionToken) ||
        $sessionToken === ''
    ) {
        $sessionToken = bin2hex(
            random_bytes(32)
        );

        $_SESSION['csrf_token'] = $sessionToken;
    }

    $requestToken =
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_POST['_csrf']
        ?? '';

    if (
        !is_string($requestToken) ||
        !hash_equals(
            $sessionToken,
            $requestToken
        )
    ) {
        errorResponse(
            'CSRF_INVALID',
            'CSRFトークンが不正です。',
            403
        );
    }
}


/* =========================================================
 * 入力処理
 * ========================================================= */

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        errorResponse(
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

        errorResponse(
            'INVALID_JSON',
            'リクエストJSONが不正です。',
            400
        );
    }

    if (!is_array($data)) {
        errorResponse(
            'INVALID_REQUEST',
            'リクエスト形式が不正です。',
            400
        );
    }

    return $data;
}


function requiredString(
    array $source,
    string $key
): string {

    if (!array_key_exists($key, $source)) {
        errorResponse(
            'REQUIRED_PARAMETER',
            "{$key}は必須です。",
            400
        );
    }

    $value = $source[$key];

    if (!is_string($value)) {
        errorResponse(
            'INVALID_PARAMETER',
            "{$key}が不正です。",
            400
        );
    }

    $value = trim($value);

    if ($value === '') {
        errorResponse(
            'REQUIRED_PARAMETER',
            "{$key}は必須です。",
            400
        );
    }

    return $value;
}


function stringValue(
    array $source,
    string $key,
    string $default = ''
): string {

    if (!array_key_exists($key, $source)) {
        return $default;
    }

    if (!is_string($source[$key])) {
        errorResponse(
            'INVALID_PARAMETER',
            "{$key}が不正です。",
            400
        );
    }

    return trim($source[$key]);
}


/**
 * endAtをISO 8601として検証。
 */
function nullableDateTime(
    array $source,
    string $key
): ?string {

    if (
        !array_key_exists($key, $source) ||
        $source[$key] === null ||
        $source[$key] === ''
    ) {
        return null;
    }

    if (!is_string($source[$key])) {
        errorResponse(
            'INVALID_DATETIME',
            "{$key}が不正です。",
            400
        );
    }

    try {

        $date = new DateTimeImmutable(
            $source[$key]
        );

    } catch (Throwable) {

        errorResponse(
            'INVALID_DATETIME',
            "{$key}が不正な日時です。",
            400
        );
    }

    return $date->format(DateTimeInterface::ATOM);
}


/* =========================================================
 * ID / 時刻
 * ========================================================= */

function generateId(string $prefix): string
{
    return $prefix
        . '_'
        . bin2hex(random_bytes(16));
}


function nowIso8601(): string
{
    return (new DateTimeImmutable())
        ->format(DateTimeInterface::ATOM);
}


/* =========================================================
 * HTML画面
 * ========================================================= */

function renderScreen(): never
{
    $csrfToken = $_SESSION['csrf_token'] ?? '';

    if ($csrfToken === '') {

        $csrfToken = bin2hex(
            random_bytes(32)
        );

        $_SESSION['csrf_token'] = $csrfToken;
    }

    header('Content-Type: text/html; charset=utf-8');

    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>
<title>アンケート管理システム</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
    background: #f5f6f8;
    color: #222;
}

header {
    background: #1f2937;
    color: #fff;
    padding: 16px 24px;
}

main {
    max-width: 1100px;
    margin: 24px auto;
    padding: 0 16px;
}

.card {
    background: #fff;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
}

h1 {
    font-size: 22px;
    margin: 0;
}

h2 {
    font-size: 18px;
    margin-top: 0;
}

button {
    border: 0;
    border-radius: 6px;
    padding: 9px 14px;
    cursor: pointer;
    background: #2563eb;
    color: #fff;
}

button.secondary {
    background: #6b7280;
}

button.danger {
    background: #dc2626;
}

button:disabled {
    opacity: .5;
    cursor: not-allowed;
}

input,
textarea {
    width: 100%;
    padding: 9px;
    border: 1px solid #d1d5db;
    border-radius: 5px;
}

.form-row {
    margin-bottom: 14px;
}

.form-row label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
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
}

.status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 12px;
    background: #e5e7eb;
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

.actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

#message {
    margin-bottom: 16px;
}

.success {
    padding: 12px;
    background: #dcfce7;
    color: #166534;
    border-radius: 6px;
}

.error {
    padding: 12px;
    background: #fee2e2;
    color: #991b1b;
    border-radius: 6px;
}

.loading {
    opacity: .6;
    pointer-events: none;
}

@media (max-width: 700px) {

    main {
        margin-top: 12px;
    }

    table {
        font-size: 13px;
    }

    th:nth-child(3),
    td:nth-child(3) {
        display: none;
    }
}
</style>
</head>

<body>

<header>
    <h1>アンケート管理システム</h1>
</header>

<main>

<div id="message"></div>

<section class="card">
    <h2>アンケート作成</h2>

    <form id="createForm">

        <div class="form-row">
            <label for="title">タイトル</label>
            <input
                id="title"
                name="title"
                maxlength="200"
                required
            >
        </div>

        <div class="form-row">
            <label for="description">説明</label>
            <textarea
                id="description"
                name="description"
                rows="4"
            ></textarea>
        </div>

        <div class="form-row">
            <label for="endAt">終了日時</label>
            <input
                id="endAt"
                name="endAt"
                type="datetime-local"
            >
        </div>

        <button type="submit">
            作成
        </button>

    </form>
</section>


<section class="card">

    <h2>アンケート一覧</h2>

    <div id="surveyList">
        読み込み中...
    </div>

</section>

</main>


<script>
'use strict';

const CSRF_TOKEN =
    <?= json_encode(
        $csrfToken,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

const app = document.querySelector('main');
const message = document.getElementById('message');
const surveyList = document.getElementById('surveyList');
const createForm = document.getElementById('createForm');


/* =========================================================
 * メッセージ
 * ========================================================= */

function showMessage(text, type = 'success')
{
    message.innerHTML = '';

    const div = document.createElement('div');

    div.className = type;

    div.textContent = text;

    message.appendChild(div);
}


/* =========================================================
 * API
 * ========================================================= */

async function apiGet(action, params = {})
{
    const url = new URL(
        window.location.href
    );

    url.search = '';

    url.searchParams.set(
        'action',
        action
    );

    Object.entries(params).forEach(
        ([key, value]) => {
            url.searchParams.set(
                key,
                value
            );
        }
    );

    const response = await fetch(
        url.toString(),
        {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        }
    );

    const result =
        await response.json();

    if (!result.success) {
        throw new Error(
            result.error?.message
            ?? '処理に失敗しました。'
        );
    }

    return result;
}


async function apiPost(
    action,
    data,
    button = null
)
{
    if (button) {
        button.disabled = true;
        button.dataset.originalText =
            button.textContent;
        button.textContent =
            '処理中...';
    }

    try {

        const response = await fetch(
            window.location.href,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/json',
                    'Accept':
                        'application/json',
                    'X-CSRF-TOKEN':
                        CSRF_TOKEN
                },
                body: JSON.stringify({
                    action,
                    ...data
                })
            }
        );

        const result =
            await response.json();

        if (!result.success) {
            throw new Error(
                result.error?.message
                ?? '処理に失敗しました。'
            );
        }

        return result;

    } finally {

        if (button) {
            button.disabled = false;
            button.textContent =
                button.dataset.originalText
                ?? '実行';
        }
    }
}


/* =========================================================
 * 一覧
 * ========================================================= */

async function loadSurveys()
{
    surveyList.classList.add('loading');

    try {

        const result =
            await apiGet(
                'api.survey.list'
            );

        renderSurveys(
            result.data.surveys
        );

    } catch (error) {

        showMessage(
            error.message,
            'error'
        );

    } finally {

        surveyList.classList.remove(
            'loading'
        );
    }
}


/* =========================================================
 * 一覧表示
 * ========================================================= */

function renderSurveys(surveys)
{
    if (!surveys.length) {

        surveyList.textContent =
            'アンケートはありません。';

        return;
    }

    const table =
        document.createElement('table');

    const thead =
        document.createElement('thead');

    thead.innerHTML = `
        <tr>
            <th>タイトル</th>
            <th>状態</th>
            <th>終了日時</th>
            <th>操作</th>
        </tr>
    `;

    table.appendChild(thead);

    const tbody =
        document.createElement('tbody');

    surveys.forEach(
        survey => {

            const tr =
                document.createElement('tr');

            const title =
                document.createElement('td');

            title.textContent =
                survey.title;

            const status =
                document.createElement('td');

            const statusSpan =
                document.createElement('span');

            statusSpan.className =
                `status ${survey.status}`;

            statusSpan.textContent =
                survey.status;

            status.appendChild(
                statusSpan
            );

            const endAt =
                document.createElement('td');

            endAt.textContent =
                survey.endAt ?? '―';

            const actions =
                document.createElement('td');

            const actionBox =
                document.createElement('div');

            actionBox.className =
                'actions';

            createActionButtons(
                actionBox,
                survey
            );

            actions.appendChild(
                actionBox
            );

            tr.appendChild(title);
            tr.appendChild(status);
            tr.appendChild(endAt);
            tr.appendChild(actions);

            tbody.appendChild(tr);
        }
    );

    table.appendChild(tbody);

    surveyList.innerHTML = '';

    surveyList.appendChild(table);
}


/* =========================================================
 * 操作ボタン
 * ========================================================= */

function createActionButtons(
    container,
    survey
)
{
    if (survey.status === 'draft') {

        addButton(
            container,
            '公開',
            async button => {

                await executeAction(
                    'api.survey.publish',
                    survey.surveyId,
                    button
                );
            }
        );

        addButton(
            container,
            '削除',
            async button => {

                if (
                    !confirm(
                        'このアンケートを削除しますか？'
                    )
                ) {
                    return;
                }

                await executeAction(
                    'api.survey.delete',
                    survey.surveyId,
                    button
                );
            },
            'danger'
        );
    }

    if (survey.status === 'published') {

        addButton(
            container,
            '停止',
            async button => {

                await executeAction(
                    'api.survey.stop',
                    survey.surveyId,
                    button
                );
            },
            'secondary'
        );

        addButton(
            container,
            '終了',
            async button => {

                if (
                    !confirm(
                        'このアンケートを終了しますか？'
                    )
                ) {
                    return;
                }

                await executeAction(
                    'api.survey.end',
                    survey.surveyId,
                    button
                );
            },
            'danger'
        );
    }

    if (survey.status === 'stopped') {

        addButton(
            container,
            '再開',
            async button => {

                await executeAction(
                    'api.survey.resume',
                    survey.surveyId,
                    button
                );
            }
        );
    }
}


/* =========================================================
 * 操作実行
 * ========================================================= */

async function executeAction(
    action,
    surveyId,
    button
)
{
    try {

        await apiPost(
            action,
            {
                surveyId
            },
            button
        );

        showMessage(
            '処理が完了しました。'
        );

        await loadSurveys();

    } catch (error) {

        showMessage(
            error.message,
            'error'
        );
    }
}


/* =========================================================
 * ボタン生成
 * ========================================================= */

function addButton(
    container,
    text,
    handler,
    className = ''
)
{
    const button =
        document.createElement('button');

    button.type = 'button';

    button.textContent = text;

    if (className) {
        button.className = className;
    }

    button.addEventListener(
        'click',
        async () => {
            await handler(button);
        }
    );

    container.appendChild(button);
}


/* =========================================================
 * 作成
 * ========================================================= */

createForm.addEventListener(
    'submit',
    async event => {

        event.preventDefault();

        const button =
            createForm.querySelector(
                'button[type="submit"]'
            );

        const title =
            document
                .getElementById('title')
                .value
                .trim();

        const description =
            document
                .getElementById('description')
                .value
                .trim();

        const endAtInput =
            document
                .getElementById('endAt')
                .value;

        if (!title) {
            showMessage(
                'タイトルを入力してください。',
                'error'
            );
            return;
        }

        let endAt = null;

        if (endAtInput) {

            /*
             * datetime-localはタイムゾーンを持たないため、
             * ブラウザのローカル時刻としてDateへ変換する。
             */
            const date =
                new Date(endAtInput);

            if (
                Number.isNaN(
                    date.getTime()
                )
            ) {
                showMessage(
                    '終了日時が不正です。',
                    'error'
                );
                return;
            }

            endAt =
                date.toISOString();
        }

        try {

            await apiPost(
                'api.survey.create',
                {
                    title,
                    description,
                    endAt
                },
                button
            );

            createForm.reset();

            showMessage(
                'アンケートを作成しました。'
            );

            await loadSurveys();

        } catch (error) {

            showMessage(
                error.message,
                'error'
            );
        }
    }
);


/* =========================================================
 * 初期表示
 * ========================================================= */

loadSurveys();
</script>

</body>
</html>
<?php

    exit;
}


/* =========================================================
 * APIレスポンス
 * ========================================================= */

function successResponse(
    array $data = [],
    string $message = ''
): never {

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}


function errorResponse(
    string $code,
    string $message,
    int $status = 400
): never {

    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}
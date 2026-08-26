<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 *
 * HTTP単一入口
 *
 * 第1工程:
 * - GET / POST の振り分け
 * - action取得
 * - action検証
 * - JSONリクエスト受信
 * - API共通レスポンス
 * - CSRF
 * - HTTPメソッド制御
 *
 * 実行環境:
 * Apache24 + PHP8.4 / 8.5
 * データベースなし
 */

const APP_ROOT = __DIR__;
const DATA_DIR = APP_ROOT . '/data';
const SURVEYS_FILE = DATA_DIR . '/surveys.json';

date_default_timezone_set('Asia/Tokyo');


/* =========================================================
 * セッション
 * ========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/* =========================================================
 * 起動
 * ========================================================= */

try {

    initializeStorage();

    $requestMethod = strtoupper(
        $_SERVER['REQUEST_METHOD'] ?? ''
    );

    /*
     * GET:
     *   action はURLのquery stringから取得する。
     *
     * POST:
     *   application/json を正式な入力形式とする。
     *   actionもJSON本文から取得する。
     */
    if ($requestMethod === 'GET') {

        $action = getGetAction();

        dispatchGet($action);
    }

    if ($requestMethod === 'POST') {

        /*
         * JSON本文を入口で一度だけ解析する。
         * readJsonBody()側でキャッシュするため、
         * 後続APIから再利用できる。
         */
        $requestData = readJsonBody();

        $action = getPostAction($requestData);

        verifyPostAction($action);

        verifyCsrf();

        dispatchPost(
            $action,
            $requestData
        );
    }

    /*
     * GET / POST 以外は禁止。
     */
    errorResponse(
        'METHOD_NOT_ALLOWED',
        'GETまたはPOSTのみ許可されています。',
        405,
        [
            'Allow' => 'GET, POST'
        ]
    );

} catch (Throwable $e) {

    /*
     * APIとして処理できる例外は、
     * 必ず共通JSONレスポンスにする。
     *
     * 内部例外の詳細は画面へ出さない。
     */
    errorResponse(
        'INTERNAL_ERROR',
        'システム内部でエラーが発生しました。',
        500
    );
}


/* =========================================================
 * GET action
 * ========================================================= */

function getGetAction(): string
{
    $action = $_GET['action'] ?? '';

    if (!is_string($action)) {
        errorResponse(
            'INVALID_ACTION',
            'actionが不正です。',
            400
        );
    }

    return trim($action);
}


/* =========================================================
 * POST action
 * ========================================================= */

function getPostAction(array $requestData): string
{
    if (!array_key_exists('action', $requestData)) {
        errorResponse(
            'REQUIRED_ACTION',
            'actionは必須です。',
            400
        );
    }

    if (!is_string($requestData['action'])) {
        errorResponse(
            'INVALID_ACTION',
            'actionが不正です。',
            400
        );
    }

    $action = trim($requestData['action']);

    if ($action === '') {
        errorResponse(
            'REQUIRED_ACTION',
            'actionは必須です。',
            400
        );
    }

    return $action;
}


/* =========================================================
 * GET action検証
 * ========================================================= */

function verifyGetAction(string $action): void
{
    $allowedActions = [
        '',
        'screen',
        'api.survey.list',
        'api.survey.get',
    ];

    if (!in_array($action, $allowedActions, true)) {
        errorResponse(
            'INVALID_ACTION',
            '指定されたGET操作は存在しません。',
            400
        );
    }
}


/* =========================================================
 * POST action検証
 * ========================================================= */

function verifyPostAction(string $action): void
{
    $allowedActions = [
        'api.survey.create',
        'api.survey.update',
        'api.survey.delete',
        'api.survey.publish',
        'api.survey.stop',
        'api.survey.resume',
        'api.survey.end',
    ];

    if (!in_array($action, $allowedActions, true)) {
        errorResponse(
            'INVALID_ACTION',
            '指定されたPOST操作は存在しません。',
            400
        );
    }
}


/* =========================================================
 * GET dispatch
 * ========================================================= */

function dispatchGet(string $action): never
{
    verifyGetAction($action);

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
    }

    errorResponse(
        'INVALID_ACTION',
        '指定されたGET操作を処理できません。',
        400
    );
}


/* =========================================================
 * POST dispatch
 * ========================================================= */

function dispatchPost(
    string $action,
    array $requestData
): never {

    /*
     * $requestData は入口で解析済み。
     *
     * 現在の業務APIは readJsonBody() を使用しているため、
     * readJsonBody()側でキャッシュしたデータを返す。
     */

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
    }

    errorResponse(
        'INVALID_ACTION',
        '指定されたPOST操作を処理できません。',
        400
    );
}
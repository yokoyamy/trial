<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * 単一入口: index.php
 *
 * 実行環境:
 * - Apache24
 * - PHP 8.4 / 8.5
 * - データベースなし
 *
 * 画面・APIともに本ファイルを単一入口として使用する。
 * pathnameには業務上の意味を持たせない。
 */

/* =========================================================
 * 基本設定
 * ========================================================= */

const APP_TIMEZONE = 'Asia/Tokyo';
const API_TIMEOUT_SECONDS = 15;

date_default_timezone_set(APP_TIMEZONE);

/*
 * PHP Warning / Notice等を画面へ直接出力させない。
 *
 * APIレスポンスを壊さないことを優先する。
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

/*
 * エラーログはサーバー側へ残す。
 */
ini_set('log_errors', '1');

/* =========================================================
 * 共通HTTPヘッダー
 * ========================================================= */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* =========================================================
 * 共通レスポンス
 * ========================================================= */

/**
 * API成功レスポンス
 *
 * {
 *   "success": true,
 *   "data": {},
 *   "message": ""
 * }
 */
function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {
    http_response_code($status);

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
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * API失敗レスポンス
 */
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
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * HTMLエスケープ
 */
function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* =========================================================
 * 共通例外処理
 * ========================================================= */

set_exception_handler(
    static function (Throwable $exception): void {

        error_log(
            sprintf(
                '[QUESTIONNAIRE_APP] Unhandled exception: %s in %s:%d',
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            )
        );

        /*
         * 既にHTTPレスポンスを開始している場合でも、
         * APIなら可能な限りJSONを返す。
         */
        $accept =
            $_SERVER['HTTP_ACCEPT'] ?? '';

        $isApi =
            isset($_GET['action'])
            || isset($_POST['action'])
            || (
                isset($_SERVER['CONTENT_TYPE'])
                && str_contains(
                    strtolower(
                        (string)$_SERVER['CONTENT_TYPE']
                    ),
                    'application/json'
                )
            );

        if ($isApi || str_contains(
            strtolower((string)$accept),
            'application/json'
        )) {
            errorResponse(
                'INTERNAL_ERROR',
                'サーバー内部で予期しないエラーが発生しました。',
                500
            );
        }

        http_response_code(500);

        echo '<!doctype html>';
        echo '<html lang="ja">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>アンケート管理システム</title>';
        echo '</head>';
        echo '<body>';
        echo '<main>';
        echo '<h1>アンケート管理システム</h1>';
        echo '<p>サーバー内部でエラーが発生しました。</p>';
        echo '</main>';
        echo '</body>';
        echo '</html>';

        exit;
    }
);

/* =========================================================
 * HTTPメソッド
 * ========================================================= */

$method =
    strtoupper(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
    );

if (!in_array(
    $method,
    ['GET', 'POST'],
    true
)) {
    errorResponse(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405
    );
}

/* =========================================================
 * セッション
 * ========================================================= */

if (
    session_status()
    !== PHP_SESSION_ACTIVE
) {
    session_start(
        [
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]
    );
}

/* =========================================================
 * CSRF
 * ========================================================= */

if (
    !isset($_SESSION['csrf_token'])
    || !is_string($_SESSION['csrf_token'])
    || $_SESSION['csrf_token'] === ''
) {
    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    (string)$_SESSION['csrf_token'];

/**
 * JSON bodyを読み取る。
 *
 * PHPでは php://input は一度読み込むと
 * 再利用しづらいため、最初に読み込んで
 * セッションへ保持する。
 */
function getJsonBody(): array
{
    static $loaded = false;
    static $body = [];

    if ($loaded) {
        return $body;
    }

    $loaded = true;

    $contentType =
        strtolower(
            (string)(
                $_SERVER['CONTENT_TYPE']
                ?? ''
            )
        );

    if (
        !str_contains(
            $contentType,
            'application/json'
        )
    ) {
        return [];
    }

    $raw =
        file_get_contents(
            'php://input'
        );

    if (
        $raw === false
        || trim($raw) === ''
    ) {
        return [];
    }

    $decoded =
        json_decode(
            $raw,
            true
        );

    if (
        !is_array($decoded)
        || json_last_error()
            !== JSON_ERROR_NONE
    ) {
        errorResponse(
            'INVALID_JSON',
            'JSONリクエストを解析できません。',
            400
        );
    }

    $body = $decoded;

    return $body;
}

/**
 * CSRFトークン取得。
 *
 * 優先順位:
 *
 * 1. X-CSRF-Token
 * 2. application/x-www-form-urlencoded
 * 3. JSON body
 */
function getRequestCsrfToken(): string
{
    $headerToken =
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (
        is_string($headerToken)
        && $headerToken !== ''
    ) {
        return $headerToken;
    }

    $postToken =
        $_POST['csrf_token']
        ?? '';

    if (
        is_string($postToken)
        && $postToken !== ''
    ) {
        return $postToken;
    }

    $json =
        getJsonBody();

    $jsonToken =
        $json['csrf_token']
        ?? '';

    if (
        is_string($jsonToken)
        && $jsonToken !== ''
    ) {
        return $jsonToken;
    }

    return '';
}

/**
 * CSRF検証
 */
function validateCsrfToken(): void
{
    $expected =
        $_SESSION['csrf_token']
        ?? '';

    $actual =
        getRequestCsrfToken();

    if (
        !is_string($expected)
        || $expected === ''
        || !is_string($actual)
        || $actual === ''
        || !hash_equals(
            $expected,
            $actual
        )
    ) {
        errorResponse(
            'CSRF_INVALID',
            'CSRFトークンが不正です。',
            403
        );
    }
}

if ($method === 'POST') {
    validateCsrfToken();
}

/* =========================================================
 * action取得
 * ========================================================= */

function getAction(): string
{
    global $method;

    if ($method === 'GET') {

        $action =
            $_GET['action']
            ?? '';

        return is_string($action)
            ? trim($action)
            : '';
    }

    $action =
        $_POST['action']
        ?? '';

    if (
        is_string($action)
        && trim($action) !== ''
    ) {
        return trim($action);
    }

    $json =
        getJsonBody();

    $action =
        $json['action']
        ?? '';

    return is_string($action)
        ? trim($action)
        : '';
}

/* =========================================================
 * POST入力取得
 * ========================================================= */

function getPostInput(): array
{
    global $method;

    if ($method !== 'POST') {
        return [];
    }

    $input =
        $_POST;

    $json =
        getJsonBody();

    if ($json !== []) {
        $input =
            array_merge(
                $input,
                $json
            );
    }

    return $input;
}

/* =========================================================
 * action定義
 * ========================================================= */

$allowedGetActions = [
    '',
    'health',
    'csrf',
    'survey_list',
    'survey_get',
    'response_summary',
    'csv_export',
    'pdf_export',
];

$allowedPostActions = [
    /*
     * API疎通確認用。
     *
     * 実業務では使用しない。
     */
    'post_test',

    'survey_create',
    'survey_update',
    'survey_delete',

    'survey_publish',
    'survey_stop',
    'survey_resume',
    'survey_end',

    'response_confirm',
    'response_complete',

    'customer_save',
    'customer_delete',

    'send_mail',
    'resend_mail',
    'remind_mail',

    'kintone_test',
    'kintone_fields',
    'kintone_sync',

    'smtp_test',

    'settings_save',
];

/* =========================================================
 * action検証
 * ========================================================= */

$action =
    getAction();

if ($method === 'GET') {

    if (
        !in_array(
            $action,
            $allowedGetActions,
            true
        )
    ) {
        errorResponse(
            'INVALID_ACTION',
            'GETでは利用できないactionです。',
            400
        );
    }

} else {

    if (
        !in_array(
            $action,
            $allowedPostActions,
            true
        )
    ) {
        errorResponse(
            'INVALID_ACTION',
            'POSTでは利用できないactionです。',
            400
        );
    }
}

/* =========================================================
 * GET health
 * ========================================================= */

if ($action === 'health') {

    successResponse(
        [
            'status' => 'ok',
            'phpVersion' => PHP_VERSION,
            'time' => date(DATE_ATOM),
            'method' => 'GET',
        ],
        '通信成功'
    );
}

/* =========================================================
 * GET csrf
 * ========================================================= */

if ($action === 'csrf') {

    successResponse(
        [
            'csrfToken' => $csrfToken,
        ],
        'CSRFトークン取得成功'
    );
}

/* =========================================================
 * POST test
 * ========================================================= */

if ($action === 'post_test') {

    $input =
        getPostInput();

    successResponse(
        [
            'status' => 'ok',
            'method' => 'POST',
            'action' => 'post_test',
            'received' => [
                'test' =>
                    isset($input['test'])
                    ? (string)$input['test']
                    : '',
            ],
            'time' =>
                date(DATE_ATOM),
        ],
        'POST通信成功'
    );
}

/* =========================================================
 * GETデフォルト画面
 * ========================================================= */

if (
    $method === 'GET'
    && $action === ''
) {

    $screen =
        isset($_GET['screen'])
        && is_string($_GET['screen'])
            ? trim($_GET['screen'])
            : 'home';

    $surveyId =
        isset($_GET['surveyId'])
        && is_string($_GET['surveyId'])
            ? trim($_GET['surveyId'])
            : '';

    $customerId =
        isset($_GET['customerId'])
        && is_string($_GET['customerId'])
            ? trim($_GET['customerId'])
            : '';

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>アンケート管理システム</title>

<style>
* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
}

body {
    font-family:
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;

    background: #f5f6f8;
    color: #222;
}

main {
    width: min(
        960px,
        calc(100% - 32px)
    );

    margin: 0 auto;
    padding: 32px 0;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 24px;

    box-shadow:
        0 2px 10px
        rgba(0, 0, 0, .06);
}

h1 {
    margin-top: 0;
}

h2 {
    margin-top: 32px;
}

button {
    appearance: none;

    border: 0;
    border-radius: 8px;

    padding: 10px 16px;

    background: #1769aa;
    color: #fff;

    cursor: pointer;

    font-size: 14px;
}

button:hover:not(:disabled) {
    background: #125789;
}

button:disabled {
    opacity: .6;
    cursor: wait;
}

button.secondary {
    background: #555;
}

button.danger {
    background: #b42318;
}

button.success {
    background: #16803c;
}

.toolbar {
    display: flex;
    flex-wrap: wrap;

    gap: 8px;

    margin: 16px 0;
}

.status {
    padding: 12px;

    border-radius: 8px;

    background: #f0f2f5;

    white-space: pre-wrap;

    word-break: break-word;

    min-height: 48px;
}

.status.success {
    background: #e9f7ef;
    color: #14532d;
}

.status.error {
    background: #fff1f0;
    color: #8a1c13;
}

.loading {
    display: none;

    margin-left: 8px;

    color: #555;
}

.loading.active {
    display: inline-block;
}

.spinner {
    display: inline-block;

    width: 14px;
    height: 14px;

    margin-right: 6px;

    border:
        2px solid #ccc;

    border-top-color:
        #1769aa;

    border-radius: 50%;

    animation:
        questionnaire-spin
        .8s linear infinite;

    vertical-align: -2px;
}

@keyframes questionnaire-spin {
    to {
        transform: rotate(360deg);
    }
}

.url-state {
    margin-top: 16px;

    padding: 12px;

    border-radius: 8px;

    background: #fafafa;

    border: 1px solid #ddd;

    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Consolas,
        monospace;

    white-space: pre-wrap;
}

.api-box {
    margin-top: 24px;

    padding-top: 24px;

    border-top:
        1px solid #ddd;
}

label {
    display: block;

    margin:
        12px 0 6px;

    font-weight: 600;
}

input {
    width: 100%;

    padding: 10px 12px;

    border:
        1px solid #bbb;

    border-radius: 8px;

    font: inherit;
}

.notice {
    padding: 12px;

    margin-bottom: 16px;

    border-radius: 8px;

    background: #eef6ff;

    color: #164e7a;
}

@media (max-width: 600px) {

    main {
        width:
            calc(100% - 20px);

        padding: 10px 0;
    }

    .card {
        padding: 16px;
    }

    button {
        width: 100%;
    }

    .toolbar {
        flex-direction: column;
    }
}
</style>
</head>

<body>

<main>

<div class="card">

    <h1>
        アンケート管理システム
    </h1>

    <div class="notice">
        単一入口 index.php の
        API・URL状態・ブラウザ操作確認
    </div>

    <h2>
        現在のURL状態
    </h2>

    <div
        id="urlState"
        class="url-state"
    ></div>

    <div class="toolbar">

        <button
            type="button"
            id="homeButton"
        >
            ホーム
        </button>

        <button
            type="button"
            id="adminButton"
        >
            管理画面
        </button>

        <button
            type="button"
            id="surveyButton"
        >
            アンケート
        </button>

        <button
            type="button"
            id="answerButton"
        >
            回答
        </button>

        <button
            type="button"
            id="completeButton"
        >
            完了
        </button>

    </div>

    <h2>
        GET API
    </h2>

    <div class="toolbar">

        <button
            type="button"
            id="healthButton"
        >
            GET health
        </button>

        <button
            type="button"
            id="csrfButton"
            class="secondary"
        >
            CSRF取得
        </button>

        <span
            id="getLoading"
            class="loading"
            aria-live="polite"
        >
            <span class="spinner"></span>
            通信中…
        </span>

    </div>

    <h2>
        POST API
    </h2>

    <p>
        要件4.16のPOST API実動作確認用です。
    </p>

    <label for="postTestValue">
        テスト値
    </label>

    <input
        id="postTestValue"
        type="text"
        value="browser-test"
        autocomplete="off"
    >

    <div class="toolbar">

        <button
            type="button"
            id="postTestButton"
            class="success"
        >
            POST APIテスト
        </button>

        <span
            id="postLoading"
            class="loading"
            aria-live="polite"
        >
            <span class="spinner"></span>
            通信中…
        </span>

    </div>

    <h2>
        API結果
    </h2>

    <div
        id="result"
        class="status"
        aria-live="polite"
    >
        まだAPI通信を実行していません。
    </div>

</div>

</main>

<script>
(() => {
    'use strict';

    /*
     * =========================================================
     * DOM
     * =========================================================
     */

    const urlState =
        document.getElementById(
            'urlState'
        );

    const result =
        document.getElementById(
            'result'
        );

    const healthButton =
        document.getElementById(
            'healthButton'
        );

    const csrfButton =
        document.getElementById(
            'csrfButton'
        );

    const postTestButton =
        document.getElementById(
            'postTestButton'
        );

    const postTestValue =
        document.getElementById(
            'postTestValue'
        );

    const getLoading =
        document.getElementById(
            'getLoading'
        );

    const postLoading =
        document.getElementById(
            'postLoading'
        );

    /*
     * =========================================================
     * API入口
     * =========================================================
     *
     * 現在のindex.phpを基準にする。
     *
     * /api/xxx
     * /xxx/index.php
     * /アンケートアプリ/api/xxx
     *
     * のような物理パスは生成しない。
     */

    function getApiEntryUrl() {

        const current =
            new URL(
                window.location.href
            );

        current.search = '';
        current.hash = '';

        return current;
    }

    function getApiUrl(action) {

        const url =
            getApiEntryUrl();

        url.searchParams.set(
            'action',
            action
        );

        return url;
    }

    /*
     * =========================================================
     * URL画面状態
     * =========================================================
     */

    function readScreenState() {

        const params =
            new URLSearchParams(
                window.location.search
            );

        return {
            screen:
                params.get('screen')
                || 'home',

            surveyId:
                params.get('surveyId')
                || null,

            customerId:
                params.get('customerId')
                || null
        };
    }

    function renderUrlState() {

        const state =
            readScreenState();

        urlState.textContent =
            JSON.stringify(
                state,
                null,
                2
            );
    }

    function buildScreenUrl(
        screen,
        surveyId = null,
        customerId = null
    ) {

        const url =
            getApiEntryUrl();

        url.searchParams.set(
            'screen',
            screen
        );

        if (
            surveyId !== null
            && surveyId !== ''
        ) {
            url.searchParams.set(
                'surveyId',
                surveyId
            );
        }

        if (
            customerId !== null
            && customerId !== ''
        ) {
            url.searchParams.set(
                'customerId',
                customerId
            );
        }

        return url;
    }

    function navigate(
        screen,
        surveyId = null,
        customerId = null
    ) {

        const url =
            buildScreenUrl(
                screen,
                surveyId,
                customerId
            );

        window.history.pushState(
            {
                screen,
                surveyId,
                customerId
            },
            '',
            url.toString()
        );

        renderUrlState();
    }

    /*
     * =========================================================
     * History API
     * =========================================================
     */

    window.addEventListener(
        'popstate',
        () => {
            renderUrlState();
        }
    );

    /*
     * =========================================================
     * ローディング
     * =========================================================
     */

    let getProcessing = false;
    let postProcessing = false;

    function setGetProcessing(
        value
    ) {

        getProcessing =
            Boolean(value);

        healthButton.disabled =
            getProcessing;

        csrfButton.disabled =
            getProcessing;

        getLoading.classList.toggle(
            'active',
            getProcessing
        );
    }

    function setPostProcessing(
        value
    ) {

        postProcessing =
            Boolean(value);

        postTestButton.disabled =
            postProcessing;

        postTestValue.disabled =
            postProcessing;

        postLoading.classList.toggle(
            'active',
            postProcessing
        );
    }

    /*
     * =========================================================
     * APIエラー
     * =========================================================
     */

    class ApiError extends Error {

        constructor(
            code,
            message,
            options = {}
        ) {

            super(message);

            this.name =
                'ApiError';

            this.code =
                code;

            this.httpStatus =
                options.httpStatus
                || 0;

            this.contentType =
                options.contentType
                || '';

            this.url =
                options.url
                || '';

            this.method =
                options.method
                || '';
        }
    }

    /*
     * =========================================================
     * APIレスポンス解析
     * =========================================================
     */

    async function parseApiResponse(
        response,
        url,
        method
    ) {

        const contentType =
            response.headers.get(
                'Content-Type'
            ) || '';

        const text =
            await response.text();

        if (text === '') {

            throw new ApiError(
                'EMPTY_RESPONSE',
                'サーバーから空のレスポンスが返されました。',
                {
                    httpStatus:
                        response.status,

                    contentType,

                    url:
                        url.toString(),

                    method
                }
            );
        }

        let data;

        try {

            data =
                JSON.parse(text);

        } catch (error) {

            throw new ApiError(
                'INVALID_JSON',
                'サーバーからJSONではないレスポンスが返されました。'
                + '\nHTTP: '
                + response.status
                + '\nContent-Type: '
                + contentType
                + '\nレスポンス先頭: '
                + text.slice(0, 500),
                {
                    httpStatus:
                        response.status,

                    contentType,

                    url:
                        url.toString(),

                    method
                }
            );
        }

        if (!response.ok) {

            throw new ApiError(
                data?.error?.code
                || 'HTTP_ERROR',

                data?.error?.message
                || 'HTTPエラーが発生しました。',

                {
                    httpStatus:
                        response.status,

                    contentType,

                    url:
                        url.toString(),

                    method
                }
            );
        }

        if (
            !data
            || data.success !== true
        ) {

            throw new ApiError(
                data?.error?.code
                || 'API_ERROR',

                data?.error?.message
                || 'API処理に失敗しました。',

                {
                    httpStatus:
                        response.status,

                    contentType,

                    url:
                        url.toString(),

                    method
                }
            );
        }

        return data;
    }

    /*
     * =========================================================
     * GET API
     * =========================================================
     */

    async function apiGet(
        action
    ) {

        const url =
            getApiUrl(action);

        const controller =
            new AbortController();

        const timeout =
            window.setTimeout(
                () => {
                    controller.abort();
                },
                15000
            );

        try {

            /*
             * 重要:
             *
             * same-originを明示。
             * Cookieを送る。
             */
            const response =
                await fetch(
                    url.toString(),
                    {
                        method: 'GET',

                        headers: {
                            'Accept':
                                'application/json'
                        },

                        credentials:
                            'same-origin',

                        cache:
                            'no-store',

                        mode:
                            'same-origin',

                        redirect:
                            'same-origin',

                        signal:
                            controller.signal
                    }
                );

            return await parseApiResponse(
                response,
                url,
                'GET'
            );

        } catch (error) {

            if (
                error instanceof ApiError
            ) {
                throw error;
            }

            if (
                error instanceof
                    DOMException
                && error.name ===
                    'AbortError'
            ) {

                throw new ApiError(
                    'TIMEOUT',
                    'API通信がタイムアウトしました。'
                    + '\nURL: '
                    + url.toString()
                    + '\nHTTPメソッド: GET',
                    {
                        url:
                            url.toString(),

                        method:
                            'GET'
                    }
                );
            }

            const message =
                error instanceof Error
                    ? error.message
                    : String(error);

            throw new ApiError(
                'NETWORK_ERROR',
                'ブラウザからAPIへ接続できませんでした。'
                + '\n\nURL: '
                + url.toString()
                + '\nHTTPメソッド: GET'
                + '\nエラー: '
                + message
                + '\n\n'
                + '確認項目:'
                + '\n・ApacheがこのURLを受け付けているか'
                + '\n・PHPが正常に実行されているか'
                + '\n・PHP Fatal Errorが発生していないか'
                + '\n・HTTPステータスが返っているか'
                + '\n・Content-Typeが返っているか'
                + '\n・HTTPS/HTTP混在になっていないか'
                + '\n・ブラウザのネットワークエラーがないか'
                + '\n・CORS等のブラウザ制約がないか'
                + '\n・証明書エラーが発生していないか',
                {
                    url:
                        url.toString(),

                    method:
                        'GET'
                }
            );
        } finally {

            window.clearTimeout(
                timeout
            );
        }
    }

    /*
     * =========================================================
     * CSRF
     * =========================================================
     */

    let csrfToken = '';

    async function getCsrfToken() {

        const data =
            await apiGet(
                'csrf'
            );

        if (
            !data.data
            || typeof
                data.data.csrfToken
                !== 'string'
            || data.data.csrfToken === ''
        ) {

            throw new ApiError(
                'CSRF_RESPONSE_INVALID',
                'CSRFトークンを含む正常なレスポンスが返されませんでした。',
                {
                    httpStatus: 200,
                    url:
                        getApiUrl(
                            'csrf'
                        ).toString(),
                    method:
                        'GET'
                }
            );
        }

        csrfToken =
            data.data.csrfToken;

        return csrfToken;
    }

    /*
     * =========================================================
     * POST API
     * =========================================================
     */

    async function apiPost(
        action,
        payload = {}
    ) {

        if (
            csrfToken === ''
        ) {
            await getCsrfToken();
        }

        const url =
            getApiUrl(action);

        const controller =
            new AbortController();

        const timeout =
            window.setTimeout(
                () => {
                    controller.abort();
                },
                15000
            );

        try {

            const body = {
                ...payload,

                action,

                csrf_token:
                    csrfToken
            };

            const response =
                await fetch(
                    url.toString(),
                    {
                        method: 'POST',

                        headers: {
                            'Accept':
                                'application/json',

                            'Content-Type':
                                'application/json',

                            'X-CSRF-Token':
                                csrfToken
                        },

                        credentials:
                            'same-origin',

                        cache:
                            'no-store',

                        mode:
                            'same-origin',

                        redirect:
                            'same-origin',

                        body:
                            JSON.stringify(body),

                        signal:
                            controller.signal
                    }
                );

            return await parseApiResponse(
                response,
                url,
                'POST'
            );

        } catch (error) {

            if (
                error instanceof ApiError
            ) {
                throw error;
            }

            if (
                error instanceof
                    DOMException
                && error.name ===
                    'AbortError'
            ) {

                throw new ApiError(
                    'TIMEOUT',
                    'POST API通信がタイムアウトしました。'
                    + '\nURL: '
                    + url.toString(),
                    {
                        url:
                            url.toString(),

                        method:
                            'POST'
                    }
                );
            }

            const message =
                error instanceof Error
                    ? error.message
                    : String(error);

            throw new ApiError(
                'NETWORK_ERROR',
                'ブラウザからPOST APIへ接続できませんでした。'
                + '\n\nURL: '
                + url.toString()
                + '\nHTTPメソッド: POST'
                + '\nエラー: '
                + message
                + '\n\n'
                + '確認項目:'
                + '\n・ApacheがこのURLを受け付けているか'
                + '\n・PHPが正常に実行されているか'
                + '\n・PHP Fatal Errorが発生していないか'
                + '\n・HTTPステータスが返っているか'
                + '\n・Content-Typeが返っているか'
                + '\n・HTTPS/HTTP混在になっていないか'
                + '\n・ブラウザのネットワークエラーがないか'
                + '\n・CORS等のブラウザ制約がないか'
                + '\n・証明書エラーが発生していないか',
                {
                    url:
                        url.toString(),

                    method:
                        'POST'
                }
            );

        } finally {

            window.clearTimeout(
                timeout
            );
        }
    }

    /*
     * =========================================================
     * API結果表示
     * =========================================================
     */

    function showSuccess(
        data
    ) {

        result.className =
            'status success';

        result.textContent =
            (
                data.message
                || '通信成功'
            )
            + '\n\n'
            + JSON.stringify(
                data.data,
                null,
                2
            );
    }

    function showError(
        error
    ) {

        result.className =
            'status error';

        if (
            error instanceof ApiError
        ) {

            result.textContent =
                '通信失敗'
                + '\n\n'
                + error.message
                + '\n\n'
                + '--- 通信診断 ---'
                + '\nAPI URL: '
                + error.url
                + '\nHTTPメソッド: '
                + error.method
                + '\nHTTPステータス: '
                + (
                    error.httpStatus
                    || '取得できませんでした'
                )
                + '\nContent-Type: '
                + (
                    error.contentType
                    || '取得できませんでした'
                )
                + '\nAPIエラーコード: '
                + error.code;

            return;
        }

        result.textContent =
            '予期しないエラーが発生しました。'
            + '\n'
            + (
                error instanceof Error
                    ? error.message
                    : String(error)
            );
    }

    /*
     * =========================================================
     * GET healthボタン
     * =========================================================
     */

    healthButton.addEventListener(
        'click',
        async () => {

            if (getProcessing) {
                return;
            }

            setGetProcessing(
                true
            );

            result.className =
                'status';

            result.textContent =
                'GET API通信中…';

            try {

                const data =
                    await apiGet(
                        'health'
                    );

                showSuccess(
                    data
                );

            } catch (error) {

                showError(
                    error
                );

            } finally {

                setGetProcessing(
                    false
                );
            }
        }
    );

    /*
     * =========================================================
     * CSRFボタン
     * =========================================================
     */

    csrfButton.addEventListener(
        'click',
        async () => {

            if (getProcessing) {
                return;
            }

            setGetProcessing(
                true
            );

            result.className =
                'status';

            result.textContent =
                'CSRFトークン取得中…';

            try {

                const token =
                    await getCsrfToken();

                result.className =
                    'status success';

                result.textContent =
                    'CSRFトークンを取得しました。'
                    + '\n\n'
                    + 'トークン長: '
                    + token.length
                    + '文字';

            } catch (error) {

                showError(
                    error
                );

            } finally {

                setGetProcessing(
                    false
                );
            }
        }
    );

    /*
     * =========================================================
     * POSTテストボタン
     * =========================================================
     */

    postTestButton.addEventListener(
        'click',
        async () => {

            if (postProcessing) {
                return;
            }

            setPostProcessing(
                true
            );

            result.className =
                'status';

            result.textContent =
                'POST API通信中…';

            try {

                const data =
                    await apiPost(
                        'post_test',
                        {
                            test:
                                postTestValue.value
                        }
                    );

                showSuccess(
                    data
                );

            } catch (error) {

                showError(
                    error
                );

            } finally {

                setPostProcessing(
                    false
                );
            }
        }
    );

    /*
     * =========================================================
     * 画面遷移ボタン
     * =========================================================
     */

    document
        .getElementById('homeButton')
        .addEventListener(
            'click',
            () => {
                navigate(
                    'home'
                );
            }
        );

    document
        .getElementById('adminButton')
        .addEventListener(
            'click',
            () => {
                navigate(
                    'admin'
                );
            }
        );

    document
        .getElementById('surveyButton')
        .addEventListener(
            'click',
            () => {
                navigate(
                    'survey'
                );
            }
        );

    document
        .getElementById('answerButton')
        .addEventListener(
            'click',
            () => {
                navigate(
                    'answer'
                );
            }
        );

    document
        .getElementById('completeButton')
        .addEventListener(
            'click',
            () => {
                navigate(
                    'complete'
                );
            }
        );

    /*
     * =========================================================
     * 初期化
     * =========================================================
     *
     * URLから画面状態を復元。
     *
     * JavaScript変数だけを正規状態にしない。
     */

    renderUrlState();

})();
</script>

</body>
</html>
<?php

    exit;
}

/* =========================================================
 * 未実装action
 * ========================================================= */

errorResponse(
    'NOT_IMPLEMENTED',
    'この業務操作はまだ実装されていません。',
    501
);
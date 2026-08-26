<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 *
 * 単一入口:
 *   index.php
 *
 * 実行環境:
 *   Apache24
 *   PHP 8.4 / 8.5
 *   DBなし
 *   JSON永続化
 *
 * 重要:
 *   - pathnameに業務上の意味を持たせない
 *   - 画面/APIはquery stringで識別
 *   - APIは必ずindex.phpを入口とする
 *   - GETは参照
 *   - POSTは変更
 *   - POST JSON bodyのCSRFにも対応
 *   - APIは常にJSONを返す
 *   - Fatal Error / ExceptionをAPIレスポンスへ変換
 */

const APP_TIMEZONE = 'Asia/Tokyo';

date_default_timezone_set(APP_TIMEZONE);

/* =========================================================
 * API判定用の初期情報
 * ========================================================= */

$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    $isApiRequest = true;
} else {
    $isApiRequest = $requestMethod === 'POST'
        || isset($_GET['action']);
}

/* =========================================================
 * 出力バッファ
 *
 * APIでPHP Warning等が出力されてもJSONを壊さないため、
 * APIリクエストでは出力をバッファリングする。
 * ========================================================= */

if ($isApiRequest) {
    ob_start();
}

/* =========================================================
 * 共通ヘッダー
 * ========================================================= */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* =========================================================
 * HTMLエスケープ
 * ========================================================= */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* =========================================================
 * JSONエンコード
 * ========================================================= */

function encodeJson(mixed $data): string
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return '{"success":false,"error":{"code":"JSON_ENCODE_FAILED","message":"JSONレスポンスの生成に失敗しました。"}}';
    }

    return $json;
}

/* =========================================================
 * API共通レスポンス
 * ========================================================= */

function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');

    echo encodeJson([
        'success' => true,
        'data' => $data,
        'message' => $message,
    ]);

    exit;
}

function errorResponse(
    string $code,
    string $message,
    int $status = 400,
    mixed $details = null
): never {
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');

    $error = [
        'code' => $code,
        'message' => $message,
    ];

    if ($details !== null) {
        $error['details'] = $details;
    }

    echo encodeJson([
        'success' => false,
        'error' => $error,
    ]);

    exit;
}

/* =========================================================
 * API例外処理
 * ========================================================= */

set_exception_handler(
    function (Throwable $exception) use ($isApiRequest): void {
        error_log(
            '[survey-app] Unhandled exception: '
            . $exception->getMessage()
        );

        if ($isApiRequest) {
            errorResponse(
                'INTERNAL_ERROR',
                'サーバー内部で予期しないエラーが発生しました。',
                500
            );
        }

        http_response_code(500);

        echo 'サーバー内部エラーが発生しました。';

        exit;
    }
);

/* =========================================================
 * PHP Fatal Error対策
 * ========================================================= */

register_shutdown_function(
    function () use ($isApiRequest): void {
        $error = error_get_last();

        if ($error === null) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
        ];

        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        error_log(
            sprintf(
                '[survey-app] Fatal error: %s in %s:%d',
                $error['message'] ?? '',
                $error['file'] ?? '',
                $error['line'] ?? 0
            )
        );

        if (!$isApiRequest) {
            return;
        }

        if (ob_get_level() > 0) {
            ob_clean();
        }

        http_response_code(500);

        header('Content-Type: application/json; charset=utf-8');

        echo encodeJson([
            'success' => false,
            'error' => [
                'code' => 'PHP_FATAL_ERROR',
                'message' => 'サーバー内部エラーが発生しました。',
            ],
        ]);
    }
);

/* =========================================================
 * HTTPメソッド検証
 * ========================================================= */

if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    errorResponse(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405
    );
}

/* =========================================================
 * セッション
 * ========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionStarted = session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);

    if ($sessionStarted === false) {
        errorResponse(
            'SESSION_START_FAILED',
            'セッションを開始できませんでした。',
            500
        );
    }
}

/* =========================================================
 * CSRFトークン生成
 * ========================================================= */

if (
    !isset($_SESSION['csrf_token'])
    || !is_string($_SESSION['csrf_token'])
    || $_SESSION['csrf_token'] === ''
) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* =========================================================
 * Content-Type判定
 * ========================================================= */

function isJsonRequest(): bool
{
    $contentType = strtolower(
        (string)($_SERVER['CONTENT_TYPE'] ?? '')
    );

    return str_contains(
        $contentType,
        'application/json'
    );
}

/* =========================================================
 * JSON body読み込み
 * ========================================================= */

function getJsonBody(): array
{
    static $loaded = false;
    static $body = [];

    if ($loaded) {
        return $body;
    }

    $loaded = true;

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode(
        $raw,
        true
    );

    if (!is_array($decoded)) {
        errorResponse(
            'INVALID_JSON',
            'JSON形式のリクエストを解析できませんでした。',
            400
        );
    }

    $body = $decoded;

    return $body;
}

/* =========================================================
 * リクエスト値取得
 * ========================================================= */

function getRequestValue(string $name, mixed $default = null): mixed
{
    if ($GLOBALS['requestMethod'] === 'GET') {
        return $_GET[$name] ?? $default;
    }

    if (isJsonRequest()) {
        $body = getJsonBody();

        if (array_key_exists($name, $body)) {
            return $body[$name];
        }
    }

    if (array_key_exists($name, $_POST)) {
        return $_POST[$name];
    }

    return $default;
}

/* =========================================================
 * action取得
 * ========================================================= */

function getAction(): string
{
    $action = getRequestValue('action', '');

    if (!is_string($action)) {
        return '';
    }

    return trim($action);
}

/* =========================================================
 * CSRF取得
 *
 * 対応:
 *   X-CSRF-Token
 *   JSON body csrf_token
 *   POST csrf_token
 * ========================================================= */

function getRequestCsrfToken(): string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        is_string($headerToken)
        && $headerToken !== ''
    ) {
        return $headerToken;
    }

    if (isJsonRequest()) {
        $body = getJsonBody();

        $jsonToken = $body['csrf_token'] ?? '';

        if (
            is_string($jsonToken)
            && $jsonToken !== ''
        ) {
            return $jsonToken;
        }
    }

    $postToken = $_POST['csrf_token'] ?? '';

    if (
        is_string($postToken)
        && $postToken !== ''
    ) {
        return $postToken;
    }

    return '';
}

/* =========================================================
 * CSRF検証
 * ========================================================= */

function validateCsrfToken(): void
{
    $expected = $_SESSION['csrf_token'] ?? '';
    $actual = getRequestCsrfToken();

    if (
        !is_string($expected)
        || $expected === ''
        || !is_string($actual)
        || $actual === ''
    ) {
        errorResponse(
            'CSRF_MISSING',
            'CSRFトークンが指定されていません。',
            403
        );
    }

    if (!hash_equals($expected, $actual)) {
        errorResponse(
            'CSRF_INVALID',
            'CSRFトークンが不正です。',
            403
        );
    }
}

/* =========================================================
 * action
 * ========================================================= */

$action = getAction();

/* =========================================================
 * GET / POST action定義
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
    'api_test_post',

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

if ($requestMethod === 'GET') {
    if (!in_array($action, $allowedGetActions, true)) {
        errorResponse(
            'INVALID_ACTION',
            'GETでは利用できないactionです。',
            400
        );
    }
} else {
    if (!in_array($action, $allowedPostActions, true)) {
        errorResponse(
            'INVALID_ACTION',
            'POSTでは利用できないactionです。',
            400
        );
    }
}

/* =========================================================
 * POST CSRF検証
 *
 * action検証後に実施する。
 * JSON bodyのcsrf_tokenも取得可能。
 * ========================================================= */

if ($requestMethod === 'POST') {
    validateCsrfToken();
}

/* =========================================================
 * GET health
 * ========================================================= */

if (
    $requestMethod === 'GET'
    && $action === 'health'
) {
    successResponse(
        [
            'status' => 'ok',
            'phpVersion' => PHP_VERSION,
            'time' => date(DATE_ATOM),
            'method' => 'GET',
            'endpoint' => $_SERVER['SCRIPT_NAME'] ?? '',
        ],
        'GET API通信成功'
    );
}

/* =========================================================
 * GET CSRF
 * ========================================================= */

if (
    $requestMethod === 'GET'
    && $action === 'csrf'
) {
    successResponse(
        [
            'csrfToken' => $csrfToken,
            'sessionActive' => session_status() === PHP_SESSION_ACTIVE,
        ],
        'CSRFトークン取得成功'
    );
}

/* =========================================================
 * POST APIテスト
 *
 * 実際のPOST API疎通確認用。
 * 業務処理ではない。
 * ========================================================= */

if (
    $requestMethod === 'POST'
    && $action === 'api_test_post'
) {
    $body = [];

    if (isJsonRequest()) {
        $body = getJsonBody();
    } else {
        $body = $_POST;
    }

    unset($body['csrf_token']);

    successResponse(
        [
            'status' => 'ok',
            'method' => 'POST',
            'phpVersion' => PHP_VERSION,
            'time' => date(DATE_ATOM),
            'received' => $body,
        ],
        'POST API通信成功'
    );
}

/* =========================================================
 * GET survey_list
 * ========================================================= */

if (
    $requestMethod === 'GET'
    && $action === 'survey_list'
) {
    successResponse(
        [
            'surveys' => [],
        ],
        'アンケート一覧を取得しました。'
    );
}

/* =========================================================
 * GET survey_get
 * ========================================================= */

if (
    $requestMethod === 'GET'
    && $action === 'survey_get'
) {
    $surveyId = getRequestValue('surveyId', '');

    if (
        !is_string($surveyId)
        || $surveyId === ''
    ) {
        errorResponse(
            'SURVEY_ID_REQUIRED',
            'surveyIdが指定されていません。',
            400
        );
    }

    successResponse(
        [
            'surveyId' => $surveyId,
            'survey' => null,
        ],
        'アンケートを取得しました。'
    );
}

/* =========================================================
 * GET response_summary
 * ========================================================= */

if (
    $requestMethod === 'GET'
    && $action === 'response_summary'
) {
    $surveyId = getRequestValue('surveyId', '');

    if (
        !is_string($surveyId)
        || $surveyId === ''
    ) {
        errorResponse(
            'SURVEY_ID_REQUIRED',
            'surveyIdが指定されていません。',
            400
        );
    }

    successResponse(
        [
            'surveyId' => $surveyId,
            'answerCount' => 0,
            'targetCount' => 0,
            'responseRate' => 0,
        ],
        '集計情報を取得しました。'
    );
}

/* =========================================================
 * CSV
 * ========================================================= */

if (
    $requestMethod === 'GET'
    && $action === 'csv_export'
) {
    $surveyId = getRequestValue('surveyId', '');

    if (
        !is_string($surveyId)
        || $surveyId === ''
    ) {
        errorResponse(
            'SURVEY_ID_REQUIRED',
            'surveyIdが指定されていません。',
            400
        );
    }

    successResponse(
        [
            'surveyId' => $surveyId,
            'implemented' => false,
        ],
        'CSV出力機能は操作受付まで実装しています。'
    );
}

/* =========================================================
 * PDF
 * ========================================================= */

if (
    $requestMethod === 'GET'
    && $action === 'pdf_export'
) {
    $surveyId = getRequestValue('surveyId', '');

    if (
        !is_string($surveyId)
        || $surveyId === ''
    ) {
        errorResponse(
            'SURVEY_ID_REQUIRED',
            'surveyIdが指定されていません。',
            400
        );
    }

    successResponse(
        [
            'surveyId' => $surveyId,
            'implemented' => false,
        ],
        'PDF出力機能は操作受付まで実装しています。'
    );
}

/* =========================================================
 * 画面表示
 *
 * actionなしGETだけが画面表示。
 * ========================================================= */

if (
    $requestMethod === 'GET'
    && $action === ''
) {
    $scriptUrl = $_SERVER['SCRIPT_NAME'] ?? '';

    if ($scriptUrl === '') {
        $scriptUrl = 'index.php';
    }

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
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
}

body {
    font-family:
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;

    background: #f4f6f8;
    color: #222;
}

main {
    width: min(1100px, calc(100% - 32px));
    margin: 0 auto;
    padding: 32px 0;
}

.card {
    background: #fff;
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow:
        0 2px 12px rgba(0, 0, 0, .06);
}

h1,
h2 {
    margin-top: 0;
}

.description {
    color: #555;
    line-height: 1.7;
}

.buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 20px;
}

button {
    appearance: none;
    border: 0;
    border-radius: 8px;
    padding: 11px 16px;

    background: #1769aa;
    color: #fff;

    cursor: pointer;

    font-size: 14px;
    font-weight: 600;
}

button:hover:not(:disabled) {
    background: #12588f;
}

button.secondary {
    background: #59636e;
}

button.danger {
    background: #b42318;
}

button:disabled {
    opacity: .55;
    cursor: wait;
}

.status {
    margin-top: 18px;
    padding: 12px 14px;
    border-radius: 8px;

    background: #eef2f6;
}

.status.success {
    background: #e9f8ef;
    color: #146c3a;
}

.status.error {
    background: #fff0ef;
    color: #9c2117;
}

.loading {
    display: none;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
}

.loading.active {
    display: flex;
}

.spinner {
    width: 18px;
    height: 18px;

    border: 3px solid #d7dce2;
    border-top-color: #1769aa;

    border-radius: 50%;

    animation:
        spin .8s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

pre {
    margin: 0;
    padding: 16px;

    overflow: auto;

    border-radius: 8px;

    background: #111827;
    color: #e5e7eb;

    font-size: 13px;
    line-height: 1.6;
}

.meta {
    display: grid;
    grid-template-columns:
        max-content 1fr;

    gap: 8px 16px;

    margin-top: 16px;

    font-size: 13px;
}

.meta dt {
    font-weight: 700;
}

.meta dd {
    margin: 0;
    word-break: break-all;
}

@media (max-width: 600px) {
    main {
        width: min(
            100% - 20px,
            1100px
        );

        padding: 20px 0;
    }

    .card {
        padding: 18px;
    }

    .buttons {
        flex-direction: column;
    }

    button {
        width: 100%;
    }

    .meta {
        grid-template-columns: 1fr;
        gap: 3px;
    }

    .meta dd {
        margin-bottom: 8px;
    }
}
</style>
</head>

<body>

<main>

<section class="card">

    <h1>アンケート管理システム</h1>

    <p class="description">
        単一入口
        <strong>index.php</strong>
        のHTTP API疎通確認画面です。
    </p>

    <dl class="meta">

        <dt>現在のURL</dt>
        <dd id="currentUrl"></dd>

        <dt>API入口</dt>
        <dd id="apiEndpoint"></dd>

        <dt>HTTPメソッド</dt>
        <dd>GET / POST</dd>

    </dl>

</section>

<section class="card">

    <h2>API疎通テスト</h2>

    <p class="description">
        以下のボタンは実際に現在の
        index.phpへHTTPリクエストを送信します。
    </p>

    <div class="buttons">

        <button
            type="button"
            id="getHealthButton"
        >
            GET APIテスト
        </button>

        <button
            type="button"
            id="csrfButton"
            class="secondary"
        >
            CSRFトークン取得
        </button>

        <button
            type="button"
            id="postButton"
        >
            POST APIテスト
        </button>

    </div>

    <div
        id="loading"
        class="loading"
        aria-live="polite"
    >
        <span class="spinner"></span>
        <span>通信中…</span>
    </div>

    <div
        id="status"
        class="status"
        aria-live="polite"
    >
        テスト待機中
    </div>

</section>

<section class="card">

    <h2>API結果</h2>

    <pre id="result">まだ実行していません。</pre>

</section>

</main>

<script>
(() => {
    'use strict';

    /*
     * ========================================================
     * 単一入口URL
     *
     * window.location.pathnameを直接APIパスとして
     * ハードコードしない。
     *
     * 現在のページURLからindex.phpのURLを作る。
     * ========================================================
     */

    const APP_URL =
        new URL(
            <?= json_encode(
                $scriptUrl,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            ) ?>,
            window.location.origin
        );

    const getHealthButton =
        document.getElementById(
            'getHealthButton'
        );

    const csrfButton =
        document.getElementById(
            'csrfButton'
        );

    const postButton =
        document.getElementById(
            'postButton'
        );

    const loading =
        document.getElementById(
            'loading'
        );

    const status =
        document.getElementById(
            'status'
        );

    const result =
        document.getElementById(
            'result'
        );

    const currentUrl =
        document.getElementById(
            'currentUrl'
        );

    const apiEndpoint =
        document.getElementById(
            'apiEndpoint'
        );

    let csrfToken = '';

    let processing = false;

    /*
     * ========================================================
     * URL表示
     * ========================================================
     */

    currentUrl.textContent =
        window.location.href;

    apiEndpoint.textContent =
        APP_URL.toString();

    /*
     * ========================================================
     * UI状態
     * ========================================================
     */

    function setProcessing(value) {

        processing = value;

        getHealthButton.disabled = value;
        csrfButton.disabled = value;
        postButton.disabled = value;

        loading.classList.toggle(
            'active',
            value
        );
    }

    function setStatus(
        message,
        type = ''
    ) {

        status.textContent = message;

        status.className =
            'status'
            + (
                type
                    ? ' ' + type
                    : ''
            );
    }

    /*
     * ========================================================
     * API URL生成
     *
     * すべてのAPIを現在のindex.phpへ送信する。
     * ========================================================
     */

    function createApiUrl(action) {

        const url =
            new URL(APP_URL.toString());

        url.search = '';

        url.searchParams.set(
            'action',
            action
        );

        return url;
    }

    /*
     * ========================================================
     * APIレスポンス解析
     *
     * Failed to fetchだけで終わらせない。
     *
     * HTTP status
     * Content-Type
     * body
     * JSON解析結果
     * を確認する。
     * ========================================================
     */

    async function parseApiResponse(
        response,
        requestUrl,
        requestMethod
    ) {

        const contentType =
            response.headers.get(
                'content-type'
            ) || '';

        const text =
            await response.text();

        let data = null;

        if (text !== '') {

            try {

                data =
                    JSON.parse(text);

            } catch (error) {

                throw new Error(
                    [
                        'APIレスポンスJSON解析失敗',
                        '',
                        'URL: '
                            + requestUrl,
                        'Method: '
                            + requestMethod,
                        'HTTP: '
                            + response.status,
                        'Content-Type: '
                            + (
                                contentType
                                || '(なし)'
                            ),
                        '',
                        'レスポンス:',
                        text.slice(0, 1000)
                    ].join('\n')
                );
            }

        }

        if (
            !response.ok
            || !data
            || data.success !== true
        ) {

            const errorCode =
                data
                && data.error
                && data.error.code
                    ? data.error.code
                    : 'HTTP_ERROR';

            const errorMessage =
                data
                && data.error
                && data.error.message
                    ? data.error.message
                    : 'API処理に失敗しました。';

            throw new Error(
                [
                    errorMessage,
                    '',
                    'URL: '
                        + requestUrl,
                    'Method: '
                        + requestMethod,
                    'HTTP: '
                        + response.status,
                    'Content-Type: '
                        + (
                            contentType
                            || '(なし)'
                        ),
                    'API Error Code: '
                        + errorCode,
                    '',
                    text
                        ? 'Response:'
                        + '\n'
                        + text.slice(0, 1000)
                        : 'Response: (empty)'
                ].join('\n')
            );
        }

        return data;
    }

    /*
     * ========================================================
     * GET API
     * ========================================================
     */

    async function requestGet(action) {

        const url =
            createApiUrl(action);

        const requestUrl =
            url.toString();

        try {

            const response =
                await fetch(
                    requestUrl,
                    {
                        method: 'GET',

                        headers: {
                            'Accept':
                                'application/json'
                        },

                        credentials:
                            'same-origin',

                        cache: 'no-store'
                    }
                );

            return await parseApiResponse(
                response,
                requestUrl,
                'GET'
            );

        } catch (error) {

            if (
                error instanceof TypeError
                && error.message ===
                    'Failed to fetch'
            ) {

                throw new Error(
                    [
                        'ブラウザからAPIへ接続できませんでした。',
                        '',
                        'URL: '
                            + requestUrl,
                        'HTTP: GET',
                        '',
                        '考えられる原因:',
                        '・ApacheがURLを受け付けていない',
                        '・PHPがレスポンスを返していない',
                        '・ネットワークエラー',
                        '・CORS等のブラウザ制約',
                        '・HTTPS/HTTP混在',
                        '・接続先URLが誤っている'
                    ].join('\n')
                );
            }

            throw error;
        }
    }

    /*
     * ========================================================
     * POST API
     * ========================================================
     */

    async function requestPost(
        action,
        payload = {}
    ) {

        if (!csrfToken) {

            throw new Error(
                'CSRFトークンがありません。'
                + '\n先に「CSRFトークン取得」を実行してください。'
            );
        }

        const url =
            createApiUrl(action);

        const requestUrl =
            url.toString();

        const body = {
            action: action,
            csrf_token: csrfToken,
            ...payload
        };

        try {

            const response =
                await fetch(
                    requestUrl,
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

                        cache: 'no-store',

                        body:
                            JSON.stringify(body)
                    }
                );

            return await parseApiResponse(
                response,
                requestUrl,
                'POST'
            );

        } catch (error) {

            if (
                error instanceof TypeError
                && error.message ===
                    'Failed to fetch'
            ) {

                throw new Error(
                    [
                        'ブラウザからPOST APIへ接続できませんでした。',
                        '',
                        'URL: '
                            + requestUrl,
                        'HTTP: POST',
                        'Content-Type: application/json',
                        '',
                        '考えられる原因:',
                        '・ApacheがPOSTを処理していない',
                        '・PHPがレスポンスを返していない',
                        '・ネットワークエラー',
                        '・CORS等のブラウザ制約',
                        '・HTTPS/HTTP混在',
                        '・接続先URLが誤っている'
                    ].join('\n')
                );
            }

            throw error;
        }
    }

    /*
     * ========================================================
     * テスト実行共通処理
     * ========================================================
     */

    async function runTest(
        callback
    ) {

        if (processing) {
            return;
        }

        setProcessing(true);

        setStatus(
            '処理中…'
        );

        result.textContent =
            '通信中…';

        try {

            const data =
                await callback();

            setStatus(
                '通信成功',
                'success'
            );

            result.textContent =
                JSON.stringify(
                    data,
                    null,
                    2
                );

        } catch (error) {

            setStatus(
                '通信失敗',
                'error'
            );

            result.textContent =
                error instanceof Error
                    ? error.message
                    : String(error);

        } finally {

            setProcessing(false);
        }
    }

    /*
     * ========================================================
     * GET APIテスト
     * ========================================================
     */

    getHealthButton.addEventListener(
        'click',
        () => {

            runTest(
                async () => {

                    return await requestGet(
                        'health'
                    );

                }
            );

        }
    );

    /*
     * ========================================================
     * CSRF取得
     * ========================================================
     */

    csrfButton.addEventListener(
        'click',
        () => {

            runTest(
                async () => {

                    const data =
                        await requestGet(
                            'csrf'
                        );

                    const token =
                        data
                        && data.data
                        && data.data.csrfToken
                            ? data.data.csrfToken
                            : '';

                    if (!token) {

                        throw new Error(
                            'APIは成功しましたが、'
                            + 'CSRFトークンがレスポンスにありません。'
                        );
                    }

                    csrfToken = token;

                    return {
                        ...data,
                        data: {
                            ...data.data,
                            csrfToken:
                                token
                        }
                    };

                }
            );

        }
    );

    /*
     * ========================================================
     * POST APIテスト
     * ========================================================
     */

    postButton.addEventListener(
        'click',
        () => {

            runTest(
                async () => {

                    /*
                     * CSRFが未取得なら、
                     * POSTを実行する前に取得する。
                     *
                     * これにより、
                     * 「POST APIボタンを押したら
                     * CSRFエラーになった」
                     * という状態を避ける。
                     */

                    if (!csrfToken) {

                        const csrfData =
                            await requestGet(
                                'csrf'
                            );

                        csrfToken =
                            csrfData
                            && csrfData.data
                            && csrfData.data.csrfToken
                                ? csrfData.data.csrfToken
                                : '';

                        if (!csrfToken) {

                            throw new Error(
                                'CSRFトークンを取得できませんでした。'
                            );
                        }
                    }

                    return await requestPost(
                        'api_test_post',
                        {
                            test: true,
                            message:
                                'POST API疎通確認'
                        }
                    );

                }
            );

        }
    );

})();
</script>

</body>
</html>
    <?php

    exit;
}

/* =========================================================
 * POST業務処理
 *
 * 現段階では業務処理本体をまだ実装していないものについて
 * 「未実装」であることを明示する。
 * ========================================================= */

if ($requestMethod === 'POST') {

    errorResponse(
        'NOT_IMPLEMENTED',
        'この業務操作はまだ実装されていません。',
        501
    );
}

/* =========================================================
 * 到達不能保護
 * ========================================================= */

errorResponse(
    'UNEXPECTED_REQUEST',
    '予期しないリクエストです。',
    400
);
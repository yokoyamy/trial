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
 * 重要:
 * - pathnameには業務上の意味を持たせない
 * - 画面/APIはquery stringで識別する
 * - APIはこのindex.phpを単一入口とする
 * - GETは参照、POSTは変更処理
 * - POSTにはCSRFを要求する
 */

/* =========================================================
 * 基本設定
 * ========================================================= */

const APP_TIMEZONE = 'Asia/Tokyo';

date_default_timezone_set(APP_TIMEZONE);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* =========================================================
 * 共通JSONレスポンス
 * ========================================================= */

function jsonEncode(mixed $value): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return '{"success":false,"error":{"code":"JSON_ENCODE_ERROR","message":"JSON生成に失敗しました。"}}';
    }

    return $json;
}

function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo jsonEncode([
        'success' => true,
        'data' => $data,
        'message' => $message,
    ]);

    exit;
}

function errorResponse(
    string $code,
    string $message,
    int $status = 400
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo jsonEncode([
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);

    exit;
}

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
 * 例外・Fatal Error共通処理
 * ========================================================= */

function isApiRequest(): bool
{
    return isset($_GET['action'])
        || isset($_POST['action'])
        || (
            isset($_SERVER['CONTENT_TYPE'])
            && str_contains(
                strtolower((string)$_SERVER['CONTENT_TYPE']),
                'application/json'
            )
        );
}

set_exception_handler(
    function (Throwable $e): void {
        error_log(
            'Unhandled exception: '
            . get_class($e)
            . ': '
            . $e->getMessage()
        );

        if (!headers_sent()) {
            errorResponse(
                'INTERNAL_ERROR',
                'サーバー内部で予期しないエラーが発生しました。',
                500
            );
        }

        exit;
    }
);

register_shutdown_function(
    function (): void {
        $error = error_get_last();

        if ($error === null) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
        ];

        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        error_log(
            'Fatal error: '
            . ($error['message'] ?? '')
            . ' at '
            . ($error['file'] ?? '')
            . ':'
            . ($error['line'] ?? '')
        );

        /*
         * すでにレスポンスが開始されている場合でも、
         * API利用時に可能な範囲でJSONを返す。
         */
        if (isApiRequest() && !headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');

            echo jsonEncode([
                'success' => false,
                'error' => [
                    'code' => 'PHP_FATAL_ERROR',
                    'message' => 'サーバー内部エラーが発生しました。',
                ],
            ]);
        }
    }
);

/* =========================================================
 * HTTPメソッド
 * ========================================================= */

$method = strtoupper(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

if (!in_array($method, ['GET', 'POST'], true)) {
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
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

/* =========================================================
 * CSRF
 * ========================================================= */

if (
    !isset($_SESSION['csrf_token'])
    || !is_string($_SESSION['csrf_token'])
    || $_SESSION['csrf_token'] === ''
) {
    $_SESSION['csrf_token'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_token'];

function getJsonBody(): array
{
    $contentType = strtolower(
        (string)($_SERVER['CONTENT_TYPE'] ?? '')
    );

    if (!str_contains($contentType, 'application/json')) {
        return [];
    }

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function getRequestCsrfToken(): string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        is_string($headerToken)
        && $headerToken !== ''
    ) {
        return $headerToken;
    }

    $postToken = $_POST['csrf_token'] ?? '';

    if (
        is_string($postToken)
        && $postToken !== ''
    ) {
        return $postToken;
    }

    $json = getJsonBody();

    if (
        isset($json['csrf_token'])
        && is_string($json['csrf_token'])
    ) {
        return $json['csrf_token'];
    }

    return '';
}

function validateCsrfToken(): void
{
    $expected = $_SESSION['csrf_token'] ?? '';
    $actual = getRequestCsrfToken();

    if (
        !is_string($expected)
        || $expected === ''
        || !is_string($actual)
        || $actual === ''
        || !hash_equals($expected, $actual)
    ) {
        errorResponse(
            'CSRF_INVALID',
            'CSRFトークンが不正です。',
            403
        );
    }
}

/* =========================================================
 * action取得
 * ========================================================= */

function getAction(): string
{
    global $method;

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        return is_string($action)
            ? trim($action)
            : '';
    }

    $action = $_POST['action'] ?? '';

    if (
        is_string($action)
        && $action !== ''
    ) {
        return trim($action);
    }

    $json = getJsonBody();

    if (
        isset($json['action'])
        && is_string($json['action'])
    ) {
        return trim($json['action']);
    }

    return '';
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

$action = getAction();

if ($method === 'GET') {
    if (!in_array($action, $allowedGetActions, true)) {
        errorResponse(
            'INVALID_ACTION',
            'GETでは利用できないactionです。',
            400
        );
    }
}

if ($method === 'POST') {
    if (!in_array($action, $allowedPostActions, true)) {
        errorResponse(
            'INVALID_ACTION',
            'POSTでは利用できないactionです。',
            400
        );
    }

    validateCsrfToken();
}

/* =========================================================
 * GET health
 * ========================================================= */

if ($method === 'GET' && $action === 'health') {
    successResponse(
        [
            'status' => 'ok',
            'phpVersion' => PHP_VERSION,
            'time' => date(DATE_ATOM),
            'method' => $method,
            'requestUri' => $_SERVER['REQUEST_URI'] ?? '',
        ],
        '通信成功'
    );
}

/* =========================================================
 * GET csrf
 * ========================================================= */

if ($method === 'GET' && $action === 'csrf') {
    successResponse(
        [
            'csrfToken' => $csrfToken,
        ],
        'CSRFトークン取得成功'
    );
}

/* =========================================================
 * 画面URL生成
 * ========================================================= */

function appEntryUrl(): string
{
    /*
     * SCRIPT_NAMEは実際にApacheから実行された
     * index.php自身を指す。
     *
     * 物理ディレクトリをハードコードしない。
     */
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

    if (
        !is_string($scriptName)
        || $scriptName === ''
    ) {
        return './index.php';
    }

    return $scriptName;
}

/* =========================================================
 * 画面状態
 * ========================================================= */

function getScreenState(): array
{
    $screen = $_GET['screen'] ?? 'home';
    $surveyId = $_GET['surveyId'] ?? null;
    $customerId = $_GET['customerId'] ?? null;

    $allowedScreens = [
        'home',
        'admin',
        'survey',
        'answer',
        'confirm',
        'complete',
    ];

    if (
        !is_string($screen)
        || !in_array($screen, $allowedScreens, true)
    ) {
        $screen = 'home';
    }

    if (
        !is_string($surveyId)
        || $surveyId === ''
    ) {
        $surveyId = null;
    }

    if (
        !is_string($customerId)
        || $customerId === ''
    ) {
        $customerId = null;
    }

    return [
        'screen' => $screen,
        'surveyId' => $surveyId,
        'customerId' => $customerId,
    ];
}

/* =========================================================
 * 未実装API
 * ========================================================= */

if ($action !== '') {
    errorResponse(
        'NOT_IMPLEMENTED',
        'この業務操作はまだ実装されていません。',
        501
    );
}

/* =========================================================
 * 画面表示
 * ========================================================= */

$screenState = getScreenState();

$screen = $screenState['screen'];
$surveyId = $screenState['surveyId'];
$customerId = $screenState['customerId'];

$entryUrl = appEntryUrl();

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

    background: #f5f6f8;
    color: #222;
}

main {
    width: min(960px, calc(100% - 32px));
    margin: 32px auto;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 24px;
    box-shadow:
        0 2px 12px rgba(0, 0, 0, .08);
}

h1 {
    margin-top: 0;
}

h2 {
    margin-top: 28px;
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
    opacity: .55;
    cursor: wait;
}

button.secondary {
    background: #666;
}

button.danger {
    background: #b42318;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 16px 0;
}

.loading {
    display: none;
    margin-left: 8px;
    color: #1769aa;
}

.loading.active {
    display: inline-block;
}

#result,
#postResult,
#csrfResult,
#urlState {
    margin-top: 16px;
    padding: 14px;
    border-radius: 8px;
    background: #f0f2f5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.success {
    background: #e9f7ef !important;
    color: #14532d;
}

.error {
    background: #fdecec !important;
    color: #991b1b;
}

.status {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    background: #eef2ff;
    color: #3730a3;
    font-size: 13px;
}

pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

@media (max-width: 640px) {
    main {
        width: min(100% - 20px, 960px);
        margin: 10px auto;
    }

    .card {
        padding: 16px;
    }

    .actions {
        flex-direction: column;
    }

    button {
        width: 100%;
    }
}
</style>
</head>

<body>

<main>
<div class="card">

<h1>アンケート管理システム</h1>

<p>
    単一入口:
    <strong><?= h($entryUrl) ?></strong>
</p>

<p>
    現在の画面:
    <span
        id="screenLabel"
        class="status"
    ><?= h($screen) ?></span>
</p>

<h2>URL状態</h2>

<div id="urlState"></div>

<h2>API疎通確認</h2>

<div class="actions">

    <button
        type="button"
        id="healthButton"
    >
        GET APIテスト
    </button>

    <button
        type="button"
        id="csrfButton"
    >
        CSRF取得テスト
    </button>

    <button
        type="button"
        id="postButton"
    >
        POST APIテスト
    </button>

    <span
        id="loading"
        class="loading"
        aria-live="polite"
    >
        処理中…
    </span>

</div>

<div id="result"></div>

<div id="csrfResult"></div>

<div id="postResult"></div>

<h2>画面遷移確認</h2>

<div class="actions">

    <button
        type="button"
        data-screen="home"
    >
        Home
    </button>

    <button
        type="button"
        data-screen="admin"
    >
        Admin
    </button>

    <button
        type="button"
        data-screen="survey"
    >
        Survey
    </button>

    <button
        type="button"
        data-screen="answer"
    >
        Answer
    </button>

    <button
        type="button"
        data-screen="complete"
    >
        Complete
    </button>

</div>

<p>
    「戻る → 進む → 再読み込み」で
    URL状態が維持されることを確認してください。
</p>

</div>
</main>

<script>
(() => {
    'use strict';

    /*
     * ========================================================
     * 基本状態
     * ========================================================
     */

    const APP_ENTRY_URL =
        <?= json_encode(
            $entryUrl,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) ?>;

    const initialState =
        <?= json_encode(
            $screenState,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) ?>;

    const healthButton =
        document.getElementById('healthButton');

    const csrfButton =
        document.getElementById('csrfButton');

    const postButton =
        document.getElementById('postButton');

    const loading =
        document.getElementById('loading');

    const result =
        document.getElementById('result');

    const csrfResult =
        document.getElementById('csrfResult');

    const postResult =
        document.getElementById('postResult');

    const urlState =
        document.getElementById('urlState');

    const screenLabel =
        document.getElementById('screenLabel');

    let processing = false;

    let csrfToken = '';

    /*
     * ========================================================
     * 共通UI
     * ========================================================
     */

    function setProcessing(value) {
        processing = Boolean(value);

        healthButton.disabled = processing;
        csrfButton.disabled = processing;
        postButton.disabled = processing;

        loading.classList.toggle(
            'active',
            processing
        );
    }

    function showSuccess(element, text) {
        element.classList.remove('error');
        element.classList.add('success');
        element.textContent = text;
    }

    function showError(element, text) {
        element.classList.remove('success');
        element.classList.add('error');
        element.textContent = text;
    }

    /*
     * ========================================================
     * URL状態
     * ========================================================
     */

    function readUrlState() {
        const url =
            new URL(window.location.href);

        const screen =
            url.searchParams.get('screen')
            || 'home';

        const surveyId =
            url.searchParams.get('surveyId');

        const customerId =
            url.searchParams.get('customerId');

        return {
            screen: screen,
            surveyId: surveyId || null,
            customerId: customerId || null
        };
    }

    function renderUrlState() {
        const state =
            readUrlState();

        screenLabel.textContent =
            state.screen;

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
        /*
         * 現在のURLを基準にする。
         *
         * pathnameに業務上の意味を持たせない。
         * 物理ディレクトリ名をハードコードしない。
         */

        const url =
            new URL(
                window.location.href
            );

        url.search = '';

        url.searchParams.set(
            'screen',
            screen
        );

        if (surveyId) {
            url.searchParams.set(
                'surveyId',
                surveyId
            );
        }

        if (customerId) {
            url.searchParams.set(
                'customerId',
                customerId
            );
        }

        return url;
    }

    function navigateScreen(
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
            url.href
        );

        renderUrlState();
    }

    /*
     * ========================================================
     * API URL
     * ========================================================
     */

    function buildApiUrl(action) {
        /*
         * 重要:
         *
         * redirect: 'same-origin'
         * は使用しない。
         *
         * RequestRedirectとして
         * 'same-origin'は不正値になるため。
         *
         * API URLはサーバーから渡された
         * 実際のindex.phpを基準にする。
         */

        const url =
            new URL(
                APP_ENTRY_URL,
                window.location.href
            );

        url.search = '';

        url.searchParams.set(
            'action',
            action
        );

        return url.href;
    }

    /*
     * ========================================================
     * API通信共通処理
     * ========================================================
     */

    async function apiRequest(
        action,
        options = {}
    ) {
        const method =
            String(
                options.method || 'GET'
            ).toUpperCase();

        const url =
            buildApiUrl(action);

        const headers = {
            'Accept': 'application/json'
        };

        if (method === 'POST') {
            headers[
                'X-CSRF-Token'
            ] = csrfToken;

            headers[
                'Content-Type'
            ] = 'application/json';
        }

        let body;

        if (method === 'POST') {
            body = JSON.stringify({
                action: action,
                csrf_token: csrfToken
            });
        }

        let response;

        try {
            response = await fetch(
                url,
                {
                    method: method,

                    /*
                     * credentialsの値は
                     * RequestRedirectではない。
                     *
                     * same-originでも動くが、
                     * セッションCookieを確実に
                     * 扱うためincludeを使用する。
                     */
                    credentials: 'include',

                    /*
                     * キャッシュされたAPI応答を
                     * 使用しない。
                     */
                    cache: 'no-store',

                    /*
                     * redirectを指定しない。
                     *
                     * ブラウザ標準のfollow動作を使用する。
                     */
                    headers: headers,

                    body: body
                }
            );
        } catch (error) {
            const message =
                error instanceof Error
                    ? error.message
                    : String(error);

            throw new Error(
                'ブラウザからAPIへ接続できませんでした。'
                + '\n\n'
                + 'URL: '
                + url
                + '\n'
                + 'HTTPメソッド: '
                + method
                + '\n'
                + 'エラー: '
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
                + '\n・証明書エラーが発生していないか'
            );
        }

        const status =
            response.status;

        const contentType =
            response.headers.get(
                'content-type'
            ) || '';

        const text =
            await response.text();

        let data = null;

        if (text.trim() !== '') {
            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error(
                    'APIがJSONではないレスポンスを返しました。'
                    + '\n\n'
                    + 'URL: '
                    + url
                    + '\n'
                    + 'HTTPステータス: '
                    + status
                    + '\n'
                    + 'Content-Type: '
                    + contentType
                    + '\n\n'
                    + 'レスポンス先頭:'
                    + '\n'
                    + text.slice(0, 1000)
                );
            }
        }

        if (!response.ok) {
            const apiCode =
                data
                && data.error
                && data.error.code
                    ? data.error.code
                    : 'HTTP_ERROR';

            const apiMessage =
                data
                && data.error
                && data.error.message
                    ? data.error.message
                    : 'HTTPエラーが発生しました。';

            throw new Error(
                apiMessage
                + '\n\n'
                + 'HTTPステータス: '
                + status
                + '\n'
                + 'Content-Type: '
                + contentType
                + '\n'
                + 'APIエラーコード: '
                + apiCode
            );
        }

        if (
            !data
            || data.success !== true
        ) {
            const apiCode =
                data
                && data.error
                && data.error.code
                    ? data.error.code
                    : 'INVALID_API_RESPONSE';

            const apiMessage =
                data
                && data.error
                && data.error.message
                    ? data.error.message
                    : 'APIレスポンスが不正です。';

            throw new Error(
                apiMessage
                + '\n\n'
                + 'HTTPステータス: '
                + status
                + '\n'
                + 'Content-Type: '
                + contentType
                + '\n'
                + 'APIエラーコード: '
                + apiCode
            );
        }

        return {
            data,
            status,
            contentType,
            url,
            method
        };
    }

    /*
     * ========================================================
     * GET API
     * ========================================================
     */

    async function testHealth() {
        if (processing) {
            return;
        }

        setProcessing(true);

        result.textContent = '';

        try {
            const response =
                await apiRequest(
                    'health',
                    {
                        method: 'GET'
                    }
                );

            showSuccess(
                result,
                'GET API通信成功'
                + '\n\n'
                + 'URL: '
                + response.url
                + '\n'
                + 'HTTPステータス: '
                + response.status
                + '\n'
                + 'Content-Type: '
                + response.contentType
                + '\n\n'
                + JSON.stringify(
                    response.data,
                    null,
                    2
                )
            );
        } catch (error) {
            showError(
                result,
                error instanceof Error
                    ? error.message
                    : String(error)
            );
        } finally {
            setProcessing(false);
        }
    }

    /*
     * ========================================================
     * CSRF取得
     * ========================================================
     */

    async function getCsrf() {
        if (processing) {
            return;
        }

        setProcessing(true);

        csrfResult.textContent = '';

        try {
            const response =
                await apiRequest(
                    'csrf',
                    {
                        method: 'GET'
                    }
                );

            const token =
                response
                    .data
                    ?.data
                    ?.csrfToken;

            if (
                typeof token !== 'string'
                || token === ''
            ) {
                throw new Error(
                    'APIレスポンスにCSRFトークンがありません。'
                );
            }

            csrfToken = token;

            showSuccess(
                csrfResult,
                'CSRFトークン取得成功'
                + '\n\n'
                + 'HTTPステータス: '
                + response.status
                + '\n'
                + 'Content-Type: '
                + response.contentType
                + '\n'
                + 'CSRFトークン: 取得済み'
            );
        } catch (error) {
            showError(
                csrfResult,
                error instanceof Error
                    ? error.message
                    : String(error)
            );
        } finally {
            setProcessing(false);
        }
    }

    /*
     * ========================================================
     * POST API
     * ========================================================
     *
     * テスト用。
     *
     * 実業務処理ではなく、
     * POST + CSRF + JSONレスポンスの
     * 通信基盤を確認するために使用する。
     */

    async function testPost() {
        if (processing) {
            return;
        }

        postResult.textContent = '';

        if (csrfToken === '') {
            showError(
                postResult,
                '先に「CSRF取得テスト」を実行してください。'
            );

            return;
        }

        setProcessing(true);

        try {
            /*
             * 現在のallowedPostActionsに
             * 実際に存在する業務actionを使用する。
             *
             * survey_createは現在未実装なので、
             * API側からNOT_IMPLEMENTEDが返る。
             *
             * 重要なのはPOST通信そのものと、
             * CSRF検証がApache経由で成立すること。
             */
            const response =
                await apiRequest(
                    'survey_create',
                    {
                        method: 'POST'
                    }
                );

            showSuccess(
                postResult,
                'POST API通信成功'
                + '\n\n'
                + 'HTTPステータス: '
                + response.status
                + '\n'
                + 'Content-Type: '
                + response.contentType
                + '\n\n'
                + JSON.stringify(
                    response.data,
                    null,
                    2
                )
            );
        } catch (error) {
            showError(
                postResult,
                error instanceof Error
                    ? error.message
                    : String(error)
            );
        } finally {
            setProcessing(false);
        }
    }

    /*
     * ========================================================
     * history API
     * ========================================================
     */

    window.addEventListener(
        'popstate',
        () => {
            /*
             * ブラウザの
             *
             * 戻る
             * 進む
             *
             * ではURLから再構築する。
             */
            renderUrlState();
        }
    );

    /*
     * ========================================================
     * 画面遷移ボタン
     * ========================================================
     */

    document
        .querySelectorAll(
            '[data-screen]'
        )
        .forEach(
            (button) => {
                button.addEventListener(
                    'click',
                    () => {
                        const screen =
                            button.getAttribute(
                                'data-screen'
                            );

                        if (!screen) {
                            return;
                        }

                        navigateScreen(
                            screen
                        );
                    }
                );
            }
        );

    /*
     * ========================================================
     * イベント登録
     * ========================================================
     */

    healthButton.addEventListener(
        'click',
        testHealth
    );

    csrfButton.addEventListener(
        'click',
        getCsrf
    );

    postButton.addEventListener(
        'click',
        testPost
    );

    /*
     * ========================================================
     * 初期表示
     * ========================================================
     */

    renderUrlState();

})();
</script>

</body>
</html>
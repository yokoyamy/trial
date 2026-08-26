<?php
declare(strict_types=1);

/**
 * =========================================================
 * アンケート管理システム
 * 単一入口: index.php
 * =========================================================
 *
 * 実行環境:
 * - Apache24
 * - PHP 8.4 / 8.5
 * - データベースなし
 *
 * 方針:
 * - 公開PHP入口は原則としてindex.phpのみ
 * - pathnameには業務上の意味を持たせない
 * - 画面/API状態はquery string / POST actionで扱う
 * - GETは参照・画面表示
 * - POSTは変更処理
 * - POST変更処理にはCSRFを要求
 * - APIレスポンスは共通JSON形式
 * - 予期しない例外を利用者へ直接表示しない
 * - PHP Warning / Notice等でJSONレスポンスを破壊しない
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
 * APIリクエスト判定
 * ========================================================= */

/**
 * actionがAPI操作として明示されているかを判定する。
 *
 * GET:
 *   ?action=health
 *
 * POST:
 *   JSON bodyのaction
 *   または form-urlencodedのaction
 */
function isApiRequest(): bool
{
    $method = strtoupper(
        $_SERVER['REQUEST_METHOD'] ?? 'GET'
    );

    if ($method !== 'GET') {
        return true;
    }

    return isset($_GET['action'])
        && is_string($_GET['action'])
        && trim($_GET['action']) !== '';
}


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
 *
 * {
 *   "success": false,
 *   "error": {
 *     "code": "...",
 *     "message": "..."
 *   }
 * }
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
 * 共通例外処理
 * ========================================================= */

/**
 * 予期しない例外を共通APIエラーへ変換する。
 */
function handleUnhandledThrowable(
    Throwable $throwable
): void {
    /*
     * 内部情報を画面へ直接出さない。
     *
     * 本番相当環境では詳細をログへ記録する。
     *
     * パスワード、token、個人情報等を
     * ログへ出力しない。
     */
    error_log(
        sprintf(
            '[survey-app] Unhandled exception: %s in %s:%d',
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine()
        )
    );

    if (isApiRequest()) {
        errorResponse(
            'INTERNAL_ERROR',
            'サーバー内部で予期しないエラーが発生しました。',
            500
        );
    }

    http_response_code(500);

    header(
        'Content-Type: text/html; charset=utf-8'
    );

    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>サーバーエラー</title>';
    echo '</head>';
    echo '<body>';
    echo '<main>';
    echo '<h1>サーバーエラー</h1>';
    echo '<p>サーバー内部で予期しないエラーが発生しました。</p>';
    echo '</main>';
    echo '</body>';
    echo '</html>';

    exit;
}

set_exception_handler(
    'handleUnhandledThrowable'
);


/**
 * PHP Warning / Notice等を例外化する。
 *
 * APIレスポンスへWarning等が直接出力されることを防止する。
 */
set_error_handler(
    static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException(
            $message,
            0,
            $severity,
            $file,
            $line
        );
    }
);


/* =========================================================
 * HTTPメソッド
 * ========================================================= */

$method = strtoupper(
    $_SERVER['REQUEST_METHOD'] ?? 'GET'
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

if (session_status() !== PHP_SESSION_ACTIVE) {
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
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];


/* =========================================================
 * POST JSON body
 * ========================================================= */

/**
 * php://inputは一度だけ読み込む。
 *
 * getAction()とCSRF検証の両方で同じ値を使用する。
 */
$rawRequestBody = '';

$jsonRequestBody = null;

if ($method === 'POST') {
    $raw = file_get_contents(
        'php://input'
    );

    if ($raw === false) {
        $rawRequestBody = '';
    } else {
        $rawRequestBody = $raw;
    }

    $contentType = strtolower(
        $_SERVER['CONTENT_TYPE'] ?? ''
    );

    if (
        str_contains(
            $contentType,
            'application/json'
        )
        && trim($rawRequestBody) !== ''
    ) {
        try {
            $decoded = json_decode(
                $rawRequestBody,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (is_array($decoded)) {
                $jsonRequestBody = $decoded;
            }
        } catch (JsonException) {
            /*
             * JSON形式エラーは、
             * action取得時に明示的に検出する。
             */
            $jsonRequestBody = null;
        }
    }
}


/**
 * リクエストCSRFトークン取得。
 *
 * 優先順位:
 *
 * 1. X-CSRF-Token
 * 2. application/x-www-form-urlencoded
 * 3. JSON csrfToken
 * 4. JSON csrf_token
 */
function getRequestCsrfToken(): string
{
    global $jsonRequestBody;

    $headerToken =
        $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        is_string($headerToken)
        && $headerToken !== ''
    ) {
        return $headerToken;
    }

    $postToken =
        $_POST['csrf_token'] ?? '';

    if (
        is_string($postToken)
        && $postToken !== ''
    ) {
        return $postToken;
    }

    if (is_array($jsonRequestBody)) {
        $jsonToken =
            $jsonRequestBody['csrfToken']
            ?? null;

        if (
            is_string($jsonToken)
            && $jsonToken !== ''
        ) {
            return $jsonToken;
        }

        $jsonToken =
            $jsonRequestBody['csrf_token']
            ?? null;

        if (
            is_string($jsonToken)
            && $jsonToken !== ''
        ) {
            return $jsonToken;
        }
    }

    return '';
}


/**
 * CSRFトークン検証。
 */
function validateCsrfToken(): void
{
    $expected =
        $_SESSION['csrf_token'] ?? '';

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
    global $jsonRequestBody;

    if ($method = strtoupper(
        $_SERVER['REQUEST_METHOD'] ?? 'GET'
    )) {
        if ($method === 'POST') {

            /*
             * form-urlencoded
             */
            $action =
                $_POST['action'] ?? '';

            if (
                is_string($action)
                && trim($action) !== ''
            ) {
                return trim($action);
            }

            /*
             * application/json
             */
            if (is_array($jsonRequestBody)) {
                $action =
                    $jsonRequestBody['action']
                    ?? '';

                return is_string($action)
                    ? trim($action)
                    : '';
            }

            return '';
        }

        $action =
            $_GET['action'] ?? '';

        return is_string($action)
            ? trim($action)
            : '';
    }

    return '';
}


/* =========================================================
 * JSONリクエスト形式検証
 * ========================================================= */

function validateJsonRequestBodyIfNeeded(): void
{
    global $method;
    global $jsonRequestBody;
    global $rawRequestBody;

    if ($method !== 'POST') {
        return;
    }

    $contentType = strtolower(
        $_SERVER['CONTENT_TYPE'] ?? ''
    );

    if (
        !str_contains(
            $contentType,
            'application/json'
        )
    ) {
        return;
    }

    if (
        trim($rawRequestBody) === ''
    ) {
        errorResponse(
            'EMPTY_REQUEST',
            'POSTリクエストのJSONデータがありません。',
            400
        );
    }

    if (!is_array($jsonRequestBody)) {
        errorResponse(
            'INVALID_JSON',
            'POSTリクエストのJSON形式が不正です。',
            400
        );
    }
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

    /*
     * 第1段階の基盤疎通確認専用。
     *
     * 業務データは変更しない。
     * 外部通信も発生させない。
     */
    'post_test',
];


/* =========================================================
 * action取得前のJSON検証
 * ========================================================= */

validateJsonRequestBodyIfNeeded();


/* =========================================================
 * action検証
 * ========================================================= */

$action = getAction();

if ($method === 'GET') {

    if (!in_array(
        $action,
        $allowedGetActions,
        true
    )) {
        errorResponse(
            'INVALID_ACTION',
            'GETでは利用できないactionです。',
            400
        );
    }

} else {

    if (!in_array(
        $action,
        $allowedPostActions,
        true
    )) {
        errorResponse(
            'INVALID_ACTION',
            'POSTでは利用できないactionです。',
            400
        );
    }
}


/* =========================================================
 * 最低限のGET疎通確認
 * ========================================================= */

if ($method === 'GET' && $action === 'health') {

    successResponse(
        [
            'status' => 'ok',
            'phpVersion' => PHP_VERSION,
            'time' => date(DATE_ATOM),
        ],
        '通信成功'
    );
}


/* =========================================================
 * CSRF取得
 * ========================================================= */

if ($method === 'GET' && $action === 'csrf') {

    successResponse(
        [
            'csrfToken' => $csrfToken,
        ]
    );
}


/* =========================================================
 * POST API基盤疎通テスト
 * ========================================================= */

/**
 * このAPIは第1段階の基盤確認専用。
 *
 * 業務データを変更しない。
 * 外部サービスへ通信しない。
 *
 * 確認対象:
 *
 * - POST
 * - JSON
 * - action
 * - CSRF
 * - 共通レスポンス
 * - HTTPステータス
 */
if (
    $method === 'POST'
    && $action === 'post_test'
) {
    if (!is_array($jsonRequestBody)) {
        errorResponse(
            'INVALID_REQUEST',
            'JSON形式のPOSTリクエストが必要です。',
            400
        );
    }

    $testValue =
        $jsonRequestBody['test']
        ?? null;

    successResponse(
        [
            'method' => $method,
            'action' => $action,
            'csrfVerified' => true,
            'received' => [
                'test' => $testValue,
            ],
        ],
        'POST APIの疎通確認に成功しました。'
    );
}


/* =========================================================
 * ここから業務処理
 * =========================================================
 *
 * 現段階では、まず以下の単一入口基盤を確立する。
 *
 * 1. PHP Fatal Error等を共通処理
 * 2. GET / POSTを正しく分離
 * 3. actionを検証
 * 4. 共通JSONレスポンス
 * 5. CSRF検証
 * 6. POST JSONを安全に取得
 *
 * 実業務APIは、この入口から呼び出す。
 */


/* =========================================================
 * GETデフォルト画面
 * ========================================================= */

if (
    $method === 'GET'
    && $action === ''
) {
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

body {
    margin: 0;

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
    max-width: 960px;
    margin: 0 auto;
    padding: 24px;
}

.card {
    background: #fff;

    border-radius: 12px;

    padding: 24px;

    box-shadow:
        0 2px 10px rgba(0,0,0,.06);
}

h1 {
    margin-top: 0;
}

.description {
    color: #555;
    line-height: 1.7;
}

.test-section {
    margin-top: 24px;
}

.test-section h2 {
    font-size: 20px;
    margin-bottom: 12px;
}

.button-group {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
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
    background: #12558b;
}

button:disabled {
    opacity: .6;
    cursor: wait;
}

button.secondary {
    background: #5f6b76;
}

button.secondary:hover:not(:disabled) {
    background: #4d5861;
}

button.danger {
    background: #b42318;
}

button.danger:hover:not(:disabled) {
    background: #8e1b13;
}

.loading {
    display: none;

    margin-top: 14px;

    align-items: center;

    gap: 8px;

    color: #1769aa;

    font-weight: 600;
}

.loading.active {
    display: flex;
}

.spinner {
    width: 16px;
    height: 16px;

    border: 3px solid #d8e7f2;

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

.result-wrapper {
    margin-top: 24px;
}

.result-wrapper h2 {
    font-size: 20px;
}

#result {
    margin-top: 12px;

    padding: 16px;

    border-radius: 8px;

    background: #f0f2f5;

    white-space: pre-wrap;

    overflow-x: auto;

    min-height: 80px;

    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Monaco,
        Consolas,
        monospace;

    font-size: 13px;

    line-height: 1.6;
}

.status {
    margin-top: 12px;

    padding: 10px 12px;

    border-radius: 8px;

    background: #eef6ff;

    color: #174a70;
}

.status.success {
    background: #edf9f0;
    color: #17652b;
}

.status.error {
    background: #fff1f0;
    color: #a61b12;
}

.note {
    margin-top: 20px;

    padding: 12px 14px;

    background: #fff8e6;

    border-radius: 8px;

    color: #664d03;

    line-height: 1.6;
}

@media (max-width: 640px) {

    main {
        padding: 12px;
    }

    .card {
        padding: 16px;
    }

    .button-group {
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

<p class="description">
    単一入口 <code>index.php</code> の
    第1段階・通信基盤確認画面です。
</p>


<div class="test-section">

<h2>GET / 基盤テスト</h2>

<div class="button-group">

<button
    type="button"
    id="healthButton"
>
    GET 通信テスト
</button>

<button
    type="button"
    id="csrfButton"
    class="secondary"
>
    CSRFトークン取得
</button>

</div>

</div>


<div class="test-section">

<h2>POST / 基盤テスト</h2>

<div class="button-group">

<button
    type="button"
    id="postTestButton"
>
    POST APIテスト
</button>

<button
    type="button"
    id="invalidCsrfButton"
    class="danger"
>
    不正CSRFテスト
</button>

<button
    type="button"
    id="invalidActionButton"
    class="danger"
>
    不正actionテスト
</button>

</div>

</div>


<div
    id="loading"
    class="loading"
    aria-live="polite"
    aria-busy="false"
>
    <span
        class="spinner"
        aria-hidden="true"
    ></span>

    <span>
        処理中…
    </span>
</div>


<div
    id="status"
    class="status"
    aria-live="polite"
>
    待機中
</div>


<div class="result-wrapper">

<h2>API結果</h2>

<div
    id="result"
    role="status"
></div>

</div>


<div class="note">

<strong>確認順序</strong><br>

1. GET 通信テスト<br>
2. CSRFトークン取得<br>
3. POST APIテスト<br>
4. 不正CSRFテスト<br>
5. 不正actionテスト

</div>

</div>

</main>


<script>

(() => {

'use strict';


/* =========================================================
 * DOM
 * ========================================================= */

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

const invalidCsrfButton =
    document.getElementById(
        'invalidCsrfButton'
    );

const invalidActionButton =
    document.getElementById(
        'invalidActionButton'
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


/* =========================================================
 * UI状態
 * ========================================================= */

let processing = false;


/**
 * サーバー通信中のUI状態。
 *
 * - ローディング表示
 * - 全テストボタン無効化
 * - 二重クリック防止
 */
function setProcessing(value) {

    processing = value;

    healthButton.disabled = value;

    csrfButton.disabled = value;

    postTestButton.disabled =
        value;

    invalidCsrfButton.disabled =
        value;

    invalidActionButton.disabled =
        value;

    loading.classList.toggle(
        'active',
        value
    );

    loading.setAttribute(
        'aria-busy',
        value ? 'true' : 'false'
    );
}


/**
 * ステータス表示。
 */
function setStatus(
    message,
    type = ''
) {

    status.textContent =
        message;

    status.className =
        'status';

    if (type !== '') {
        status.classList.add(
            type
        );
    }
}


/* =========================================================
 * URL
 * ========================================================= */

/**
 * 現在のindex.phpを基準にする。
 *
 * 物理ディレクトリを
 * JavaScriptへハードコードしない。
 */
function getEntryUrl() {

    const url =
        new URL(
            window.location.href
        );

    /*
     * 現在URLに残っている
     * action等をAPI URLへ持ち込まない。
     */
    url.search = '';

    return url;
}


/* =========================================================
 * APIレスポンス処理
 * ========================================================= */

/**
 * APIレスポンスを安全に解析する。
 *
 * 以下を区別する。
 *
 * - HTTPエラー
 * - 空レスポンス
 * - JSON解析失敗
 * - HTMLレスポンス
 * - 正常JSON
 */
async function parseApiResponse(
    response
) {

    const contentType =
        response.headers.get(
            'Content-Type'
        ) || '';

    const text =
        await response.text();

    if (text.trim() === '') {

        throw new Error(
            'サーバーから空のレスポンスが返されました。'
            + '\nHTTP '
            + response.status
            + '\nContent-Type: '
            + contentType
        );
    }


    let data;

    try {

        data =
            JSON.parse(text);

    } catch (error) {

        throw new Error(
            'サーバーからJSONではない応答が返されました。'
            + '\nHTTP '
            + response.status
            + '\nContent-Type: '
            + contentType
            + '\nレスポンス先頭:'
            + '\n'
            + text.slice(0, 500)
        );
    }


    return {
        response,
        data,
        contentType,
    };
}


/**
 * API失敗を利用者向けメッセージへ変換。
 */
function getApiErrorMessage(
    response,
    data
) {

    if (
        data
        && data.error
        && typeof data.error.message
            === 'string'
    ) {

        return data.error.message;
    }

    return (
        'API通信に失敗しました。'
        + '\nHTTP '
        + response.status
    );
}


/* =========================================================
 * GET health
 * ========================================================= */

async function requestHealth() {

    if (processing) {
        return;
    }

    setProcessing(true);

    setStatus(
        'GET APIを実行しています…'
    );

    result.textContent = '';


    try {

        const url =
            getEntryUrl();

        url.searchParams.set(
            'action',
            'health'
        );


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
                        'same-origin'
                }
            );


        const {
            data,
            contentType
        } =
            await parseApiResponse(
                response
            );


        if (
            !response.ok
            || data.success !== true
        ) {

            throw new Error(
                getApiErrorMessage(
                    response,
                    data
                )
            );
        }


        setStatus(
            'GET API成功'
            + ' / HTTP '
            + response.status,
            'success'
        );


        result.textContent =
            'HTTP: '
            + response.status
            + '\nContent-Type: '
            + contentType
            + '\n\n'
            + JSON.stringify(
                data,
                null,
                2
            );

    } catch (error) {

        setStatus(
            'GET API失敗',
            'error'
        );

        result.textContent =
            '通信失敗\n'
            + (
                error instanceof Error
                    ? error.message
                    : String(error)
            );

    } finally {

        setProcessing(false);
    }
}


/* =========================================================
 * CSRF token
 * ========================================================= */

async function getCsrfToken() {

    const url =
        getEntryUrl();

    url.searchParams.set(
        'action',
        'csrf'
    );


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
                    'same-origin'
            }
        );


    const {
        data,
        contentType
    } =
        await parseApiResponse(
            response
        );


    if (
        !response.ok
        || data.success !== true
    ) {

        throw new Error(
            getApiErrorMessage(
                response,
                data
            )
        );
    }


    if (
        !data.data
        || typeof data.data.csrfToken
            !== 'string'
        || data.data.csrfToken === ''
    ) {

        throw new Error(
            'CSRFトークンがレスポンスに存在しません。'
        );
    }


    return {
        token:
            data.data.csrfToken,

        status:
            response.status,

        contentType,
    };
}


/**
 * 画面上の操作としてCSRF取得。
 */
async function requestCsrf() {

    if (processing) {
        return;
    }

    setProcessing(true);

    setStatus(
        'CSRFトークンを取得しています…'
    );

    result.textContent = '';


    try {

        const {
            token,
            status: httpStatus,
            contentType
        } =
            await getCsrfToken();


        /*
         * tokenそのものを画面へ出さない。
         */
        setStatus(
            'CSRFトークン取得成功'
            + ' / HTTP '
            + httpStatus,
            'success'
        );


        result.textContent =
            'HTTP: '
            + httpStatus
            + '\nContent-Type: '
            + contentType
            + '\n\n'
            + 'CSRFトークンを取得しました。'
            + '\n'
            + '（トークン本体は画面へ表示しません）';

    } catch (error) {

        setStatus(
            'CSRFトークン取得失敗',
            'error'
        );

        result.textContent =
            '通信失敗\n'
            + (
                error instanceof Error
                    ? error.message
                    : String(error)
            );

    } finally {

        setProcessing(false);
    }
}


/* =========================================================
 * POST API
 * ========================================================= */

/**
 * POST APIを実行。
 *
 * invalidCsrf:
 *   不正CSRFテスト
 *
 * invalidAction:
 *   不正actionテスト
 */
async function requestPostTest(
    invalidCsrf = false,
    invalidAction = false
) {

    if (processing) {
        return;
    }

    setProcessing(true);

    setStatus(
        'POST APIを実行しています…'
    );

    result.textContent = '';


    try {

        /*
         * まずGETでCSRFを取得。
         */
        const {
            token
        } =
            await getCsrfToken();


        let requestToken =
            token;


        if (invalidCsrf) {

            requestToken =
                'invalid-csrf-token-for-test';
        }


        const action =
            invalidAction
                ? 'invalid_action_for_test'
                : 'post_test';


        const url =
            getEntryUrl();


        /*
         * POST bodyはJSON。
         *
         * actionとcsrfTokenの両方を
         * JSON bodyへ入れる。
         *
         * さらにX-CSRF-Token headerも送る。
         */
        const requestBody = {

            action,

            csrfToken:
                requestToken,

            test:
                'post-api-test'
        };


        const response =
            await fetch(
                url.toString(),
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json',

                        'Accept':
                            'application/json',

                        'X-CSRF-Token':
                            requestToken
                    },

                    credentials:
                        'same-origin',

                    body:
                        JSON.stringify(
                            requestBody
                        )
                }
            );


        const {
            data,
            contentType
        } =
            await parseApiResponse(
                response
            );


        /*
         * -----------------------------------------
         * 不正CSRFテスト
         * -----------------------------------------
         */
        if (invalidCsrf) {

            if (
                response.status === 403
                && data.success === false
                && data?.error?.code
                    === 'CSRF_INVALID'
            ) {

                setStatus(
                    '不正CSRFテスト成功'
                    + ' / HTTP '
                    + response.status,
                    'success'
                );


                result.textContent =
                    '期待どおりCSRFエラーが返りました。'
                    + '\n\n'
                    + 'HTTP: '
                    + response.status
                    + '\nContent-Type: '
                    + contentType
                    + '\n\n'
                    + JSON.stringify(
                        data,
                        null,
                        2
                    );

                return;
            }


            setStatus(
                '不正CSRFテスト失敗',
                'error'
            );


            result.textContent =
                '期待するCSRFエラーと一致しません。'
                + '\n\n'
                + 'HTTP: '
                + response.status
                + '\nContent-Type: '
                + contentType
                + '\n\n'
                + JSON.stringify(
                    data,
                    null,
                    2
                );

            return;
        }


        /*
         * -----------------------------------------
         * 不正actionテスト
         * -----------------------------------------
         */
        if (invalidAction) {

            if (
                response.status === 400
                && data.success === false
                && data?.error?.code
                    === 'INVALID_ACTION'
            ) {

                setStatus(
                    '不正actionテスト成功'
                    + ' / HTTP '
                    + response.status,
                    'success'
                );


                result.textContent =
                    '期待どおりactionエラーが返りました。'
                    + '\n\n'
                    + 'HTTP: '
                    + response.status
                    + '\nContent-Type: '
                    + contentType
                    + '\n\n'
                    + JSON.stringify(
                        data,
                        null,
                        2
                    );

                return;
            }


            setStatus(
                '不正actionテスト失敗',
                'error'
            );


            result.textContent =
                '期待するactionエラーと一致しません。'
                + '\n\n'
                + 'HTTP: '
                + response.status
                + '\nContent-Type: '
                + contentType
                + '\n\n'
                + JSON.stringify(
                    data,
                    null,
                    2
                );

            return;
        }


        /*
         * -----------------------------------------
         * 正常POST
         * -----------------------------------------
         */
        if (
            response.ok
            && data.success === true
        ) {

            setStatus(
                'POST API成功'
                + ' / HTTP '
                + response.status,
                'success'
            );


            result.textContent =
                data.message
                + '\n\n'
                + 'HTTP: '
                + response.status
                + '\nContent-Type: '
                + contentType
                + '\n\n'
                + JSON.stringify(
                    data,
                    null,
                    2
                );

            return;
        }


        /*
         * -----------------------------------------
         * 想定外のAPIエラー
         * -----------------------------------------
         */

        setStatus(
            'POST APIエラー',
            'error'
        );


        result.textContent =
            getApiErrorMessage(
                response,
                data
            )
            + '\n\n'
            + 'HTTP: '
            + response.status
            + '\nContent-Type: '
            + contentType
            + '\n\n'
            + JSON.stringify(
                data,
                null,
                2
            );

    } catch (error) {

        setStatus(
            'POST API通信失敗',
            'error'
        );

        result.textContent =
            '通信失敗\n'
            + (
                error instanceof Error
                    ? error.message
                    : String(error)
            );

    } finally {

        /*
         * 成功・失敗・例外・タイムアウト等、
         * 最終的に必ずローディング解除。
         */
        setProcessing(false);
    }
}


/* =========================================================
 * HTTP通信イベント
 * ========================================================= */

healthButton.addEventListener(
    'click',
    requestHealth
);


csrfButton.addEventListener(
    'click',
    requestCsrf
);


postTestButton.addEventListener(
    'click',
    () => {
        requestPostTest(
            false,
            false
        );
    }
);


invalidCsrfButton.addEventListener(
    'click',
    () => {
        requestPostTest(
            true,
            false
        );
    }
);


invalidActionButton.addEventListener(
    'click',
    () => {
        requestPostTest(
            false,
            true
        );
    }
);


/* =========================================================
 * Enterキー等による意図しない多重操作対策
 * ========================================================= */

document.addEventListener(
    'keydown',
    (event) => {

        if (
            event.key === 'Enter'
            && processing
        ) {
            event.preventDefault();
        }
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
 * 未実装action
 * ========================================================= */

errorResponse(
    'NOT_IMPLEMENTED',
    'この業務操作はまだ実装されていません。',
    501
);
<?php

declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * 基盤実装版 / index.php 単一入口
 * ============================================================
 *
 * 実行環境:
 *   Apache24
 *   PHP 8.4 / 8.5
 *   DBなし
 *   JSON永続化
 *
 * このファイルで保証するもの:
 *
 *   - 単一入口 index.php
 *   - GET / POST
 *   - query string による action
 *   - 共通JSONレスポンス
 *   - 未処理例外の共通処理
 *   - PHP Fatal Error の共通処理
 *   - CSRF
 *   - セッション
 *   - HTTPメソッド制御
 *   - health API
 *   - csrf API
 *   - test_post API
 *   - fetch通信
 *   - 同一Origin通信
 *   - 配置場所変更耐性
 *   - URL状態
 *   - history API
 *   - popstate
 *   - ローディング
 *   - 二重送信防止
 *
 * 注意:
 *   業務機能を実装済みとして扱わない。
 *   この段階では「通信基盤」を成立させる。
 */


/* ============================================================
 * 0. 基本設定
 * ============================================================ */

const APP_TIMEZONE = 'Asia/Tokyo';

date_default_timezone_set(APP_TIMEZONE);


/* ============================================================
 * 1. 共通HTTPヘッダー
 * ============================================================ */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');


/* ============================================================
 * 2. API判定
 * ============================================================ */

function isApiRequest(): bool
{
    if (isset($_GET['action'])) {
        return true;
    }

    if (isset($_POST['action'])) {
        return true;
    }

    $contentType = strtolower(
        (string)($_SERVER['CONTENT_TYPE'] ?? '')
    );

    return str_contains(
        $contentType,
        'application/json'
    );
}


/* ============================================================
 * 3. JSONレスポンス
 * ============================================================ */

function jsonEncodeSafe(mixed $data): string
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return '{"success":false,"error":{"code":"JSON_ENCODE_ERROR","message":"JSON生成に失敗しました。"}}';
    }

    return $json;
}


/**
 * 成功レスポンス
 */
function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {

    if (headers_sent() === false) {
        http_response_code($status);
        header(
            'Content-Type: application/json; charset=utf-8'
        );
    }

    echo jsonEncodeSafe(
        [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ]
    );

    exit;
}


/**
 * エラーレスポンス
 */
function errorResponse(
    string $code,
    string $message,
    int $status = 400
): never {

    if (headers_sent() === false) {
        http_response_code($status);
        header(
            'Content-Type: application/json; charset=utf-8'
        );
    }

    echo jsonEncodeSafe(
        [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]
    );

    exit;
}


/* ============================================================
 * 4. HTMLエスケープ
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/* ============================================================
 * 5. ログ
 * ============================================================ */

function appLog(
    string $message
): void {

    error_log(
        '[SURVEY_APP] ' . $message
    );
}


/* ============================================================
 * 6. 予期しない例外処理
 * ============================================================ */

set_exception_handler(
    function (Throwable $e): void {

        appLog(
            'EXCEPTION '
            . get_class($e)
            . ' message='
            . $e->getMessage()
            . ' file='
            . $e->getFile()
            . ' line='
            . $e->getLine()
        );

        /*
         * APIなら必ずJSONを返す。
         */
        if (isApiRequest()) {

            errorResponse(
                'INTERNAL_ERROR',
                'サーバー内部で予期しないエラーが発生しました。',
                500
            );
        }

        /*
         * 通常画面。
         */
        http_response_code(500);

        echo <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>サーバーエラー</title>
</head>
<body>
<h1>サーバーエラー</h1>
<p>サーバー内部でエラーが発生しました。</p>
</body>
</html>
HTML;

        exit;
    }
);


/* ============================================================
 * 7. PHP Fatal Error対策
 * ============================================================ */

register_shutdown_function(
    function (): void {

        $lastError = error_get_last();

        if ($lastError === null) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
        ];

        if (!in_array(
            $lastError['type'],
            $fatalTypes,
            true
        )) {
            return;
        }

        /*
         * Fatal Errorは必ずログへ残す。
         */
        error_log(
            '[SURVEY_APP][FATAL] '
            . 'type='
            . $lastError['type']
            . ' message='
            . ($lastError['message'] ?? '')
            . ' file='
            . ($lastError['file'] ?? '')
            . ' line='
            . ($lastError['line'] ?? '')
        );

        /*
         * すでに出力済みなら何もできない。
         */
        if (headers_sent()) {
            return;
        }

        /*
         * APIリクエストならJSONを返す。
         */
        if (isApiRequest()) {

            http_response_code(500);

            header(
                'Content-Type: application/json; charset=utf-8'
            );

            echo jsonEncodeSafe(
                [
                    'success' => false,
                    'error' => [
                        'code' => 'PHP_FATAL_ERROR',
                        'message' =>
                            'サーバー内部エラーが発生しました。',
                    ],
                ]
            );

            return;
        }

        /*
         * 通常画面ならHTML。
         */
        http_response_code(500);

        header(
            'Content-Type: text/html; charset=utf-8'
        );

        echo <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>サーバーエラー</title>
</head>
<body>
<h1>サーバーエラー</h1>
<p>PHPの実行中に内部エラーが発生しました。</p>
</body>
</html>
HTML;
    }
);


/* ============================================================
 * 8. HTTPメソッド
 *
 * ここで $method を必ず定義する。
 *
 * 以前の
 *   Undefined variable $method
 *
 * を発生させない。
 * ============================================================ */

$method = strtoupper(
    (string)(
        $_SERVER['REQUEST_METHOD']
        ?? 'GET'
    )
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


/* ============================================================
 * 9. セッション
 * ============================================================ */

if (session_status() !== PHP_SESSION_ACTIVE) {

    session_start(
        [
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]
    );
}


/* ============================================================
 * 10. CSRFトークン
 * ============================================================ */

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


/* ============================================================
 * 11. CSRF取得
 * ============================================================ */

function getRequestCsrfToken(): string
{
    /*
     * HTTP Header
     */
    $headerToken =
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (
        is_string($headerToken)
        && $headerToken !== ''
    ) {
        return $headerToken;
    }


    /*
     * application/x-www-form-urlencoded
     */
    $postToken =
        $_POST['csrf_token']
        ?? '';

    if (
        is_string($postToken)
        && $postToken !== ''
    ) {
        return $postToken;
    }


    /*
     * application/json
     */
    $contentType =
        strtolower(
            (string)(
                $_SERVER['CONTENT_TYPE']
                ?? ''
            )
        );

    if (
        str_contains(
            $contentType,
            'application/json'
        )
    ) {

        $raw =
            file_get_contents(
                'php://input'
            );

        if (
            is_string($raw)
            && trim($raw) !== ''
        ) {

            $json =
                json_decode(
                    $raw,
                    true
                );

            if (
                is_array($json)
                && isset($json['csrf_token'])
                && is_string(
                    $json['csrf_token']
                )
            ) {

                return $json['csrf_token'];
            }
        }
    }

    return '';
}


/* ============================================================
 * 12. CSRF検証
 * ============================================================ */

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


/* ============================================================
 * 13. action取得
 * ============================================================ */

function getAction(): string
{
    $requestMethod =
        strtoupper(
            (string)(
                $_SERVER['REQUEST_METHOD']
                ?? 'GET'
            )
        );

    /*
     * GET
     */
    if ($requestMethod === 'GET') {

        $action =
            $_GET['action']
            ?? '';

        return is_string($action)
            ? trim($action)
            : '';
    }


    /*
     * POST form
     */
    $action =
        $_POST['action']
        ?? '';

    if (
        is_string($action)
        && trim($action) !== ''
    ) {

        return trim($action);
    }


    /*
     * POST JSON
     */
    $contentType =
        strtolower(
            (string)(
                $_SERVER['CONTENT_TYPE']
                ?? ''
            )
        );

    if (
        str_contains(
            $contentType,
            'application/json'
        )
    ) {

        $raw =
            file_get_contents(
                'php://input'
            );

        if (
            is_string($raw)
            && trim($raw) !== ''
        ) {

            $json =
                json_decode(
                    $raw,
                    true
                );

            if (
                is_array($json)
                && isset($json['action'])
                && is_string(
                    $json['action']
                )
            ) {

                return trim(
                    $json['action']
                );
            }
        }
    }

    return '';
}


/* ============================================================
 * 14. action定義
 * ============================================================ */

$allowedGetActions = [
    '',
    'health',
    'csrf',
];

$allowedPostActions = [
    'test_post',
];


/* ============================================================
 * 15. action判定
 * ============================================================ */

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
            '指定されたGET actionは存在しません。',
            400
        );
    }

} elseif ($method === 'POST') {

    if (
        !in_array(
            $action,
            $allowedPostActions,
            true
        )
    ) {

        errorResponse(
            'INVALID_ACTION',
            '指定されたPOST actionは存在しません。',
            400
        );
    }

    validateCsrfToken();
}


/* ============================================================
 * 16. health API
 * ============================================================ */

if (
    $method === 'GET'
    && $action === 'health'
) {

    successResponse(
        [
            'status' => 'ok',
            'phpVersion' => PHP_VERSION,
            'method' => $method,
            'time' => date(DATE_ATOM),
            'scriptName' =>
                (string)(
                    $_SERVER['SCRIPT_NAME']
                    ?? ''
                ),
            'requestUri' =>
                (string)(
                    $_SERVER['REQUEST_URI']
                    ?? ''
                ),
        ],
        '通信成功'
    );
}


/* ============================================================
 * 17. CSRF API
 * ============================================================ */

if (
    $method === 'GET'
    && $action === 'csrf'
) {

    successResponse(
        [
            'csrfToken' =>
                $csrfToken,
        ],
        'CSRFトークン取得成功'
    );
}


/* ============================================================
 * 18. POST test
 * ============================================================ */

if (
    $method === 'POST'
    && $action === 'test_post'
) {

    successResponse(
        [
            'status' => 'ok',
            'method' => 'POST',
            'csrf' => 'validated',
            'time' => date(DATE_ATOM),
        ],
        'POST API通信成功'
    );
}


/* ============================================================
 * 19. 画面表示
 *
 * 重要:
 *
 * REQUEST_URIをAPI入口生成に使用しない。
 *
 * 以前:
 *
 *   $_SERVER['REQUEST_URI']
 *
 * だと
 *
 *   /アンケートアプリ/
 *
 * のようなDirectoryIndex URLを取得する可能性がある。
 *
 * 今回:
 *
 *   $_SERVER['SCRIPT_NAME']
 *
 * を使う。
 *
 * これにより
 *
 *   /gojacic/.poc/draft/アンケートアプリ/index.php
 *
 * を単一入口として明示する。
 * ============================================================ */

if (
    $method === 'GET'
    && $action === ''
) {

    $scriptName =
        (string)(
            $_SERVER['SCRIPT_NAME']
            ?? ''
        );

    /*
     * SCRIPT_NAMEが取得できない場合は、
     * PHP_SELFをフォールバックとして使用。
     */
    if ($scriptName === '') {

        $scriptName =
            (string)(
                $_SERVER['PHP_SELF']
                ?? ''
            );
    }

    /*
     * それでも空ならindex.php。
     */
    if ($scriptName === '') {
        $scriptName = '/index.php';
    }


    /*
     * query stringは入口URLに含めない。
     */
    $entryUrl =
        $scriptName;


    /*
     * HTMLへ安全に埋め込む。
     */
    $entryUrlJson =
        json_encode(
            $entryUrl,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

    if ($entryUrlJson === false) {
        $entryUrlJson = '"/index.php"';
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

body {
    margin: 0;
    background: #f5f6f8;
    color: #222;
    font-family:
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

main {
    width: min(1000px, 100%);
    margin: 0 auto;
    padding: 24px;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow:
        0 2px 10px rgba(0, 0, 0, .06);
}

h1 {
    margin-top: 0;
}

h2 {
    margin-top: 0;
}

.buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
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
}

button:hover:not(:disabled) {
    background: #0f578d;
}

button.secondary {
    background: #555;
}

button.success {
    background: #218739;
}

button:disabled {
    opacity: .55;
    cursor: wait;
}

.loading {
    display: none;
    margin-left: 8px;
    color: #1769aa;
}

.loading.active {
    display: inline-block;
}

.status {
    margin-top: 16px;
    padding: 12px;
    border-radius: 8px;
    background: #f0f2f5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.status.ok {
    background: #e9f8ee;
    color: #145c29;
}

.status.error {
    background: #fff0f0;
    color: #8b1e1e;
}

pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.small {
    color: #666;
    font-size: 13px;
}

.warning {
    background: #fff7df;
    border-left: 4px solid #d99b00;
    padding: 12px;
    margin-top: 12px;
}

dl {
    display: grid;
    grid-template-columns:
        minmax(180px, max-content)
        1fr;
    gap: 8px 16px;
}

dt {
    font-weight: 700;
}

dd {
    margin: 0;
    overflow-wrap: anywhere;
}

.ok-text {
    color: #145c29;
    font-weight: 700;
}

.error-text {
    color: #8b1e1e;
    font-weight: 700;
}

@media (max-width: 600px) {

    main {
        padding: 12px;
    }

    .card {
        padding: 16px;
    }

    .buttons {
        display: grid;
    }

    button {
        width: 100%;
    }

    dl {
        grid-template-columns: 1fr;
    }
}

</style>

</head>

<body>

<main>

<section class="card">

<h1>
アンケート管理システム
</h1>

<p>
単一入口 <code>index.php</code>
基盤通信テスト
</p>

<p class="small">
現在のAPI入口:
<br>
<code id="entryUrlText"></code>
</p>

<div class="warning">

<strong>重要</strong>

<p>
この画面はApache24経由の実通信を確認するための基盤テストです。
</p>

<p>
ブラウザに
<code>Failed to fetch</code>
が出る場合、PHPの業務処理以前に
HTTPS、証明書、Apache、VirtualHost、
またはブラウザのネットワーク層で失敗している可能性があります。
</p>

</div>

</section>


<section class="card">

<h2>
GET API
</h2>

<div class="buttons">

<button
    type="button"
    id="healthButton"
>
GET API（health）
</button>

<button
    type="button"
    id="csrfButton"
    class="secondary"
>
CSRF取得
</button>

</div>

<span
    id="getLoading"
    class="loading"
    aria-live="polite"
>
処理中…
</span>

<div
    id="getResult"
    class="status"
>
未実行
</div>

</section>


<section class="card">

<h2>
POST API
</h2>

<p class="small">
CSRF取得後にPOSTを実行します。
</p>

<div class="buttons">

<button
    type="button"
    id="postButton"
    class="success"
>
POST APIテスト
</button>

</div>

<span
    id="postLoading"
    class="loading"
    aria-live="polite"
>
処理中…
</span>

<div
    id="postResult"
    class="status"
>
未実行
</div>

</section>


<section class="card">

<h2>
通信診断
</h2>

<dl>

<dt>
API URL
</dt>

<dd id="diagnosticUrl">
-
</dd>


<dt>
HTTPメソッド
</dt>

<dd id="diagnosticMethod">
-
</dd>


<dt>
HTTPステータス
</dt>

<dd id="diagnosticStatus">
-
</dd>


<dt>
レスポンス有無
</dt>

<dd id="diagnosticResponse">
-
</dd>


<dt>
Content-Type
</dt>

<dd id="diagnosticContentType">
-
</dd>


<dt>
APIエラーコード
</dt>

<dd id="diagnosticErrorCode">
-
</dd>


<dt>
ブラウザOrigin
</dt>

<dd id="diagnosticOrigin">
-
</dd>


<dt>
現在URL
</dt>

<dd id="diagnosticCurrentUrl">
-
</dd>


<dt>
API入口
</dt>

<dd id="diagnosticEntry">
-
</dd>

</dl>

<pre id="diagnosticDetail"></pre>

</section>


<section class="card">

<h2>
URL / history API テスト
</h2>

<div class="buttons">

<button
    type="button"
    id="adminUrlButton"
>
screen=admin
</button>

<button
    type="button"
    id="surveyUrlButton"
>
screen=survey
</button>

<button
    type="button"
    id="answerUrlButton"
>
screen=answer
</button>

<button
    type="button"
    id="replaceUrlButton"
    class="secondary"
>
replaceState
</button>

</div>

<div
    id="urlResult"
    class="status"
>
現在URLを確認してください。
</div>

</section>

</main>


<script>
(() => {

    'use strict';


    /* ========================================================
     * 1. サーバーから渡された単一入口
     * ======================================================== */

    const ENTRY_PATH =
        <?= $entryUrlJson ?>;


    /*
     * 現在originを必ず使用する。
     *
     * 例:
     *
     * https://localhost
     *
     * +
     *
     * /gojacic/.poc/draft/アンケートアプリ/index.php
     *
     * =
     *
     * https://localhost/gojacic/.poc/draft/アンケートアプリ/index.php
     */

    const entryUrl =
        new URL(
            ENTRY_PATH,
            window.location.origin
        );


    /* ========================================================
     * 2. API URL生成
     * ======================================================== */

    function apiUrl(action) {

        const url =
            new URL(
                entryUrl.toString()
            );

        url.search = '';

        url.searchParams.set(
            'action',
            action
        );

        return url.toString();
    }


    /* ========================================================
     * 3. DOM
     * ======================================================== */

    const healthButton =
        document.getElementById(
            'healthButton'
        );

    const csrfButton =
        document.getElementById(
            'csrfButton'
        );

    const postButton =
        document.getElementById(
            'postButton'
        );

    const getLoading =
        document.getElementById(
            'getLoading'
        );

    const postLoading =
        document.getElementById(
            'postLoading'
        );

    const getResult =
        document.getElementById(
            'getResult'
        );

    const postResult =
        document.getElementById(
            'postResult'
        );

    const entryUrlText =
        document.getElementById(
            'entryUrlText'
        );

    const diagnosticUrl =
        document.getElementById(
            'diagnosticUrl'
        );

    const diagnosticMethod =
        document.getElementById(
            'diagnosticMethod'
        );

    const diagnosticStatus =
        document.getElementById(
            'diagnosticStatus'
        );

    const diagnosticResponse =
        document.getElementById(
            'diagnosticResponse'
        );

    const diagnosticContentType =
        document.getElementById(
            'diagnosticContentType'
        );

    const diagnosticErrorCode =
        document.getElementById(
            'diagnosticErrorCode'
        );

    const diagnosticOrigin =
        document.getElementById(
            'diagnosticOrigin'
        );

    const diagnosticCurrentUrl =
        document.getElementById(
            'diagnosticCurrentUrl'
        );

    const diagnosticEntry =
        document.getElementById(
            'diagnosticEntry'
        );

    const diagnosticDetail =
        document.getElementById(
            'diagnosticDetail'
        );

    const adminUrlButton =
        document.getElementById(
            'adminUrlButton'
        );

    const surveyUrlButton =
        document.getElementById(
            'surveyUrlButton'
        );

    const answerUrlButton =
        document.getElementById(
            'answerUrlButton'
        );

    const replaceUrlButton =
        document.getElementById(
            'replaceUrlButton'
        );

    const urlResult =
        document.getElementById(
            'urlResult'
        );


    /* ========================================================
     * 4. 初期表示
     * ======================================================== */

    entryUrlText.textContent =
        entryUrl.toString();

    diagnosticOrigin.textContent =
        window.location.origin;

    diagnosticCurrentUrl.textContent =
        window.location.href;

    diagnosticEntry.textContent =
        entryUrl.toString();


    /* ========================================================
     * 5. 状態
     * ======================================================== */

    let getProcessing = false;

    let postProcessing = false;

    let csrfToken = '';


    /* ========================================================
     * 6. ローディング
     * ======================================================== */

    function setGetProcessing(value) {

        getProcessing = value;

        healthButton.disabled = value;

        csrfButton.disabled = value;

        getLoading.classList.toggle(
            'active',
            value
        );
    }


    function setPostProcessing(value) {

        postProcessing = value;

        postButton.disabled = value;

        postLoading.classList.toggle(
            'active',
            value
        );
    }


    /* ========================================================
     * 7. 診断初期化
     * ======================================================== */

    function resetDiagnostic(
        url,
        method
    ) {

        diagnosticUrl.textContent =
            url;

        diagnosticMethod.textContent =
            method;

        diagnosticStatus.textContent =
            '取得中';

        diagnosticResponse.textContent =
            '取得中';

        diagnosticContentType.textContent =
            '取得中';

        diagnosticErrorCode.textContent =
            '-';

        diagnosticOrigin.textContent =
            window.location.origin;

        diagnosticCurrentUrl.textContent =
            window.location.href;

        diagnosticEntry.textContent =
            entryUrl.toString();

        diagnosticDetail.textContent =
            '';
    }


    /* ========================================================
     * 8. 成功診断
     * ======================================================== */

    function setDiagnosticSuccess(
        response,
        data
    ) {

        diagnosticStatus.textContent =
            String(
                response.status
            );

        diagnosticResponse.textContent =
            'あり';

        diagnosticContentType.textContent =
            response.headers.get(
                'content-type'
            )
            || '(なし)';

        diagnosticErrorCode.textContent =
            '-';

        diagnosticDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );
    }


    /* ========================================================
     * 9. APIエラー診断
     * ======================================================== */

    function setDiagnosticApiError(
        response,
        data
    ) {

        diagnosticStatus.textContent =
            String(
                response.status
            );

        diagnosticResponse.textContent =
            'あり';

        diagnosticContentType.textContent =
            response.headers.get(
                'content-type'
            )
            || '(なし)';

        diagnosticErrorCode =
            document.getElementById(
                'diagnosticErrorCode'
            );

        diagnosticErrorCode.textContent =
            data?.error?.code
            || 'API_ERROR';

        diagnosticDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );
    }


    /* ========================================================
     * 10. ネットワークエラー診断
     * ======================================================== */

    function setDiagnosticNetworkError(
        error
    ) {

        diagnosticStatus.textContent =
            '取得できませんでした';

        diagnosticResponse.textContent =
            'なし';

        diagnosticContentType.textContent =
            '取得できませんでした';

        diagnosticErrorCode.textContent =
            'NETWORK_ERROR';

        let detail =
            'Failed to fetch';

        if (
            error
            && typeof error.message === 'string'
            && error.message !== ''
        ) {

            detail =
                error.message;
        }

        detail +=
            '\n\n'
            + 'HTTPレスポンスを取得できていません。'
            + '\n\n'
            + '確認項目:'
            + '\n'
            + '1. Apache24が起動しているか'
            + '\n'
            + '2. HTTPS VirtualHostがlocalhostを受けているか'
            + '\n'
            + '3. HTTPS証明書がブラウザで信頼されているか'
            + '\n'
            + '4. Apache access.log'
            + '\n'
            + '5. Apache error.log'
            + '\n'
            + '6. PHP error log'
            + '\n'
            + '7. DevTools → Network'
            + '\n'
            + '8. URLがindex.phpを指しているか'
            + '\n'
            + '9. localhostのHTTPSポートが正しいか';

        diagnosticDetail.textContent =
            detail;
    }


    /* ========================================================
     * 11. HTTP + JSON通信共通関数
     * ======================================================== */

    async function requestJson(
        url,
        options = {}
    ) {

        const controller =
            new AbortController();

        const timeoutId =
            window.setTimeout(
                () => {
                    controller.abort();
                },
                15000
            );

        try {

            const response =
                await fetch(
                    url,
                    {
                        ...options,

                        credentials:
                            'same-origin',

                        cache:
                            'no-store',

                        signal:
                            controller.signal,
                    }
                );


            /*
             * HTTPレスポンスが存在する。
             *
             * 4xx / 5xxでもここには到達する。
             */
            let rawText = '';

            try {

                rawText =
                    await response.text();

            } catch (readError) {

                throw new Error(
                    'HTTPレスポンス本文の読み取りに失敗しました。'
                );
            }


            let data = null;

            if (
                rawText.trim() !== ''
            ) {

                try {

                    data =
                        JSON.parse(
                            rawText
                        );

                } catch (jsonError) {

                    const contentType =
                        response.headers.get(
                            'content-type'
                        )
                        || '';

                    throw new Error(
                        'JSON解析失敗。'
                        + ' HTTP='
                        + response.status
                        + ' Content-Type='
                        + contentType
                        + ' Body='
                        + rawText.slice(
                            0,
                            500
                        )
                    );
                }

            } else {

                throw new Error(
                    'HTTPレスポンスはありましたが、本文が空です。'
                    + ' HTTP='
                    + response.status
                );
            }


            return {
                response,
                data,
            };

        } finally {

            window.clearTimeout(
                timeoutId
            );
        }
    }


    /* ========================================================
     * 12. health
     * ======================================================== */

    async function runHealth() {

        if (getProcessing) {
            return;
        }

        const url =
            apiUrl(
                'health'
            );

        resetDiagnostic(
            url,
            'GET'
        );

        setGetProcessing(
            true
        );

        getResult.className =
            'status';

        getResult.textContent =
            '通信中…';

        try {

            const result =
                await requestJson(
                    url,
                    {
                        method: 'GET',
                        headers: {
                            'Accept':
                                'application/json',
                        },
                    }
                );

            setDiagnosticSuccess(
                result.response,
                result.data
            );


            if (
                result.data?.success
                === true
            ) {

                getResult.className =
                    'status ok';

                getResult.textContent =
                    JSON.stringify(
                        result.data,
                        null,
                        2
                    );

            } else {

                setDiagnosticApiError(
                    result.response,
                    result.data
                );

                getResult.className =
                    'status error';

                getResult.textContent =
                    JSON.stringify(
                        result.data,
                        null,
                        2
                    );
            }

        } catch (error) {

            setDiagnosticNetworkError(
                error
            );

            getResult.className =
                'status error';

            getResult.textContent =
                error instanceof Error
                    ? error.message
                    : String(error);

        } finally {

            setGetProcessing(
                false
            );
        }
    }


    /* ========================================================
     * 13. CSRF取得
     * ======================================================== */

    async function loadCsrf() {

        if (getProcessing) {
            return;
        }

        const url =
            apiUrl(
                'csrf'
            );

        resetDiagnostic(
            url,
            'GET'
        );

        setGetProcessing(
            true
        );

        getResult.className =
            'status';

        getResult.textContent =
            'CSRF取得中…';

        try {

            const result =
                await requestJson(
                    url,
                    {
                        method: 'GET',
                        headers: {
                            'Accept':
                                'application/json',
                        },
                    }
                );

            setDiagnosticSuccess(
                result.response,
                result.data
            );


            if (
                result.data?.success
                === true
                && typeof
                    result.data?.data?.csrfToken
                    === 'string'
            ) {

                csrfToken =
                    result.data.data.csrfToken;

                getResult.className =
                    'status ok';

                getResult.textContent =
                    'CSRF取得成功';

            } else {

                setDiagnosticApiError(
                    result.response,
                    result.data
                );

                getResult.className =
                    'status error';

                getResult.textContent =
                    JSON.stringify(
                        result.data,
                        null,
                        2
                    );
            }

        } catch (error) {

            setDiagnosticNetworkError(
                error
            );

            getResult.className =
                'status error';

            getResult.textContent =
                error instanceof Error
                    ? error.message
                    : String(error);

        } finally {

            setGetProcessing(
                false
            );
        }
    }


    /* ========================================================
     * 14. POST
     * ======================================================== */

    async function runPost() {

        if (postProcessing) {
            return;
        }

        setPostProcessing(
            true
        );

        postResult.className =
            'status';

        postResult.textContent =
            'CSRF確認中…';

        try {

            /*
             * CSRFがなければ取得。
             */
            if (
                csrfToken === ''
            ) {

                const csrfUrl =
                    apiUrl(
                        'csrf'
                    );

                resetDiagnostic(
                    csrfUrl,
                    'GET'
                );

                const csrfResult =
                    await requestJson(
                        csrfUrl,
                        {
                            method: 'GET',
                            headers: {
                                'Accept':
                                    'application/json',
                            },
                        }
                    );

                setDiagnosticSuccess(
                    csrfResult.response,
                    csrfResult.data
                );

                if (
                    csrfResult.data?.success
                    !== true
                    || typeof
                        csrfResult.data?.data?.csrfToken
                        !== 'string'
                ) {

                    setDiagnosticApiError(
                        csrfResult.response,
                        csrfResult.data
                    );

                    throw new Error(
                        'CSRFトークンを取得できませんでした。'
                    );
                }

                csrfToken =
                    csrfResult.data.data.csrfToken;
            }


            const url =
                apiUrl(
                    'test_post'
                );

            resetDiagnostic(
                url,
                'POST'
            );

            postResult.textContent =
                'POST通信中…';


            const result =
                await requestJson(
                    url,
                    {
                        method: 'POST',

                        credentials:
                            'same-origin',

                        headers: {
                            'Accept':
                                'application/json',

                            'Content-Type':
                                'application/json',

                            'X-CSRF-Token':
                                csrfToken,
                        },

                        body:
                            JSON.stringify(
                                {
                                    action:
                                        'test_post',

                                    csrf_token:
                                        csrfToken,
                                }
                            ),
                    }
                );


            if (
                result.data?.success
                === true
            ) {

                setDiagnosticSuccess(
                    result.response,
                    result.data
                );

                postResult.className =
                    'status ok';

                postResult.textContent =
                    JSON.stringify(
                        result.data,
                        null,
                        2
                    );

            } else {

                setDiagnosticApiError(
                    result.response,
                    result.data
                );

                postResult.className =
                    'status error';

                postResult.textContent =
                    JSON.stringify(
                        result.data,
                        null,
                        2
                    );
            }

        } catch (error) {

            setDiagnosticNetworkError(
                error
            );

            postResult.className =
                'status error';

            postResult.textContent =
                error instanceof Error
                    ? error.message
                    : String(error);

        } finally {

            setPostProcessing(
                false
            );
        }
    }


    /* ========================================================
     * 15. URL状態
     * ======================================================== */

    function getUrlState() {

        const params =
            new URLSearchParams(
                window.location.search
            );

        return {
            screen:
                params.get(
                    'screen'
                ) || '',
            surveyId:
                params.get(
                    'surveyId'
                ) || '',
            customerId:
                params.get(
                    'customerId'
                ) || '',
            questionId:
                params.get(
                    'questionId'
                ) || '',
        };
    }


    function renderUrlState() {

        const state =
            getUrlState();

        urlResult.className =
            'status ok';

        urlResult.textContent =
            JSON.stringify(
                {
                    currentUrl:
                        window.location.href,

                    pathname:
                        window.location.pathname,

                    search:
                        window.location.search,

                    state:
                        state,
                },
                null,
                2
            );
    }


    function updateUrl(
        params,
        mode = 'push'
    ) {

        const url =
            new URL(
                window.location.href
            );

        url.search = '';

        Object.entries(
            params
        ).forEach(
            ([key, value]) => {

                if (
                    value !== null
                    && value !== undefined
                    && value !== ''
                ) {

                    url.searchParams.set(
                        key,
                        String(value)
                    );
                }
            }
        );


        if (mode === 'replace') {

            window.history.replaceState(
                {},
                '',
                url
            );

        } else {

            window.history.pushState(
                {},
                '',
                url
            );
        }

        renderUrlState();
    }


    /* ========================================================
     * 16. history API
     * ======================================================== */

    window.addEventListener(
        'popstate',
        () => {

            renderUrlState();

            diagnosticCurrentUrl.textContent =
                window.location.href;
        }
    );


    /* ========================================================
     * 17. イベント
     * ======================================================== */

    healthButton.addEventListener(
        'click',
        runHealth
    );

    csrfButton.addEventListener(
        'click',
        loadCsrf
    );

    postButton.addEventListener(
        'click',
        runPost
    );


    adminUrlButton.addEventListener(
        'click',
        () => {

            updateUrl(
                {
                    screen:
                        'admin',
                }
            );
        }
    );


    surveyUrlButton.addEventListener(
        'click',
        () => {

            updateUrl(
                {
                    screen:
                        'survey',

                    surveyId:
                        'survey_demo',
                }
            );
        }
    );


    answerUrlButton.addEventListener(
        'click',
        () => {

            updateUrl(
                {
                    screen:
                        'answer',

                    surveyId:
                        'survey_demo',

                    customerId:
                        'customer_demo',
                }
            );
        }
    );


    replaceUrlButton.addEventListener(
        'click',
        () => {

            updateUrl(
                {
                    screen:
                        'admin',
                },
                'replace'
            );
        }
    );


    /* ========================================================
     * 18. 初期URL状態復元
     * ======================================================== */

    renderUrlState();

})();
</script>

</body>
</html>
<?php

    exit;
}


/* ============================================================
 * 20. 到達不能処理
 * ============================================================ */

errorResponse(
    'UNHANDLED_REQUEST',
    'リクエストを処理できませんでした。',
    500
);
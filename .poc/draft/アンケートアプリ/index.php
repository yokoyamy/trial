<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 *
 * 第1段階：単一入口・実行基盤・HTTP通信確認版
 *
 * 実行環境：
 * - Apache24
 * - PHP 8.4 / 8.5
 * - DBなし
 * - JSON永続化
 *
 * 重要：
 * - 公開PHPファイルは index.php のみ
 * - GET / POST は index.php に集約
 * - API URLは REQUEST_URI から生成しない
 * - Apache/PHPが実際に実行している SCRIPT_NAME を使用する
 * - pathnameに業務上の意味を持たせない
 * - GETは参照
 * - POSTは変更
 * - POSTはCSRF必須
 */

const APP_TIMEZONE = 'Asia/Tokyo';
const API_TIMEOUT_MS = 15000;

date_default_timezone_set(APP_TIMEZONE);

/* =========================================================
 * 出力バッファ
 *
 * PHP Warning / Notice等がAPI JSONの前に出力されることを
 * 防止する。
 * ========================================================= */

ob_start();

/* =========================================================
 * 共通HTTPヘッダー
 * ========================================================= */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* =========================================================
 * API判定
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

/* =========================================================
 * ログ
 * ========================================================= */

function appLog(
    string $level,
    string $message
): void {
    error_log(
        '[SURVEY_APP]['
        . $level
        . '] '
        . $message
    );
}

/* =========================================================
 * 共通JSONエンコード
 * ========================================================= */

function encodeJson(mixed $value): string
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

/* =========================================================
 * 共通レスポンス
 * ========================================================= */

function prepareApiResponse(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {
    prepareApiResponse();

    http_response_code($status);

    echo encodeJson(
        [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ]
    );

    exit;
}

function errorResponse(
    string $code,
    string $message,
    int $status = 400
): never {
    prepareApiResponse();

    http_response_code($status);

    echo encodeJson(
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
 * PHP Fatal Error対策
 *
 * set_exception_handler() ではFatal Errorを完全には捕捉できない。
 * そのためshutdown handlerも登録する。
 * ========================================================= */

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
            E_USER_ERROR,
        ];

        if (!in_array(
            $error['type'],
            $fatalTypes,
            true
        )) {
            return;
        }

        $message = (string)(
            $error['message']
            ?? 'Unknown fatal error'
        );

        $file = (string)(
            $error['file']
            ?? ''
        );

        $line = (string)(
            $error['line']
            ?? ''
        );

        appLog(
            'FATAL',
            $message
            . ' file='
            . $file
            . ' line='
            . $line
        );

        if (!isApiRequest()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            http_response_code(500);

            header(
                'Content-Type: text/html; charset=utf-8'
            );

            echo '<!doctype html>';
            echo '<html lang="ja">';
            echo '<head>';
            echo '<meta charset="utf-8">';
            echo '<title>サーバーエラー</title>';
            echo '</head>';
            echo '<body>';
            echo '<h1>サーバーエラー</h1>';
            echo '<p>サーバー内部で予期しないエラーが発生しました。</p>';
            echo '</body>';
            echo '</html>';

            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        header('Cache-Control: no-store');

        echo encodeJson(
            [
                'success' => false,
                'error' => [
                    'code' => 'PHP_FATAL_ERROR',
                    'message' => 'サーバー内部エラーが発生しました。',
                ],
            ]
        );
    }
);

/* =========================================================
 * PHP Warning / Notice等
 *
 * APIレスポンスを壊さない。
 * ログには記録するが、画面には直接出さない。
 * ========================================================= */

set_error_handler(
    function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        appLog(
            'PHP',
            'severity='
            . $severity
            . ' message='
            . $message
            . ' file='
            . $file
            . ' line='
            . $line
        );

        /*
         * APIのJSONレスポンスをWarning等で壊さない。
         */
        return true;
    }
);

/* =========================================================
 * 予期しない例外
 * ========================================================= */

set_exception_handler(
    function (Throwable $e): void {
        appLog(
            'EXCEPTION',
            get_class($e)
            . ': '
            . $e->getMessage()
        );

        if (isApiRequest()) {
            errorResponse(
                'INTERNAL_ERROR',
                'サーバー内部で予期しないエラーが発生しました。',
                500
            );
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);

        header(
            'Content-Type: text/html; charset=utf-8'
        );

        echo '<!doctype html>';
        echo '<html lang="ja">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<title>サーバーエラー</title>';
        echo '</head>';
        echo '<body>';
        echo '<h1>サーバーエラー</h1>';
        echo '<p>サーバー内部で予期しないエラーが発生しました。</p>';
        echo '</body>';
        echo '</html>';

        exit;
    }
);

/* =========================================================
 * HTTPメソッド
 * ========================================================= */

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

/* =========================================================
 * セッション
 * ========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionResult = session_start(
        [
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' =>
                (
                    isset($_SERVER['HTTPS'])
                    && strtolower(
                        (string)$_SERVER['HTTPS']
                    ) !== 'off'
                ),
        ]
    );

    if ($sessionResult === false) {
        errorResponse(
            'SESSION_START_FAILED',
            'セッションを開始できませんでした。',
            500
        );
    }
}

/* =========================================================
 * リクエストボディ
 *
 * JSON POSTの場合は一度だけ読み取り、
 * 以後はこの値を共通利用する。
 * ========================================================= */

$requestJson = null;

$contentType = strtolower(
    (string)(
        $_SERVER['CONTENT_TYPE']
        ?? ''
    )
);

if (
    $method === 'POST'
    && str_contains(
        $contentType,
        'application/json'
    )
) {
    $rawBody = file_get_contents(
        'php://input'
    );

    if (
        is_string($rawBody)
        && trim($rawBody) !== ''
    ) {
        $decoded = json_decode(
            $rawBody,
            true
        );

        if (
            json_last_error() === JSON_ERROR_NONE
            && is_array($decoded)
        ) {
            $requestJson = $decoded;
        }
    }
}

/* =========================================================
 * CSRF
 * ========================================================= */

if (
    !isset($_SESSION['csrf_token'])
    || !is_string(
        $_SESSION['csrf_token']
    )
    || $_SESSION['csrf_token'] === ''
) {
    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken = $_SESSION['csrf_token'];

/* =========================================================
 * CSRF取得
 * ========================================================= */

function getRequestCsrfToken(): string
{
    global $requestJson;

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

    if (
        is_array($requestJson)
        && isset(
            $requestJson['csrf_token']
        )
        && is_string(
            $requestJson['csrf_token']
        )
    ) {
        return $requestJson['csrf_token'];
    }

    return '';
}

/* =========================================================
 * CSRF検証
 * ========================================================= */

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

/* =========================================================
 * action取得
 * ========================================================= */

function getAction(): string
{
    global $requestJson;

    if (
        $method === 'GET'
    ) {
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

    if (
        is_array($requestJson)
        && isset(
            $requestJson['action']
        )
        && is_string(
            $requestJson['action']
        )
    ) {
        return trim(
            $requestJson['action']
        );
    }

    return '';
}

/* =========================================================
 * API入口URL
 *
 * 【重要修正】
 *
 * REQUEST_URIを使用しない。
 *
 * REQUEST_URIは
 *
 * /アンケートアプリ/
 *
 * のようなDirectoryIndex URLになり得る。
 *
 * その場合、
 *
 * /アンケートアプリ/?action=csrf
 *
 * が生成されてしまう。
 *
 * 本システムでは単一入口がindex.phpなので、
 * Apache/PHPが実際に実行している
 * SCRIPT_NAMEを使用する。
 *
 * これにより、
 *
 * /アンケートアプリ/index.php?action=csrf
 *
 * を生成できる。
 * ========================================================= */

function getApplicationEntryPath(): string
{
    $scriptName =
        $_SERVER['SCRIPT_NAME']
        ?? '';

    if (
        !is_string($scriptName)
        || trim($scriptName) === ''
    ) {
        throw new RuntimeException(
            'SCRIPT_NAMEを取得できません。'
        );
    }

    /*
     * query stringはSCRIPT_NAMEには含めない。
     */

    $scriptName = strtok(
        $scriptName,
        '?'
    );

    if (
        !is_string($scriptName)
        || $scriptName === ''
    ) {
        throw new RuntimeException(
            '有効なSCRIPT_NAMEを取得できません。'
        );
    }

    /*
     * 要件上、単一入口はindex.php。
     */
    if (
        basename($scriptName)
        !== 'index.php'
    ) {
        /*
         * Apacheの特殊構成でも、
         * 実行スクリプトがindex.php以外なら
         * API入口として扱わない。
         */
        throw new RuntimeException(
            '実行中のPHP入口がindex.phpではありません。'
        );
    }

    return $scriptName;
}

/* =========================================================
 * 許可action
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
    'test_post',

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
 * action判定
 * ========================================================= */

$action = getAction();

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

    validateCsrfToken();
}

/* =========================================================
 * GET health
 * ========================================================= */

if (
    $method === 'GET'
    && $action === 'health'
) {
    successResponse(
        [
            'status' => 'ok',
            'phpVersion' => PHP_VERSION,
            'time' => date(DATE_ATOM),
            'method' => 'GET',
            'scriptName' =>
                $_SERVER['SCRIPT_NAME']
                ?? null,
        ],
        '通信成功'
    );
}

/* =========================================================
 * GET csrf
 * ========================================================= */

if (
    $method === 'GET'
    && $action === 'csrf'
) {
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

/* =========================================================
 * 画面表示
 * ========================================================= */

if (
    $method === 'GET'
    && $action === ''
) {
    /*
     * 【重要】
     *
     * REQUEST_URIではなくSCRIPT_NAME。
     */
    $entryPath =
        getApplicationEntryPath();

    /*
     * JSONとしてJavaScriptへ安全に渡す。
     */
    $entryPathJson = json_encode(
        $entryPath,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );

    if ($entryPathJson === false) {
        errorResponse(
            'ENTRY_URL_GENERATION_FAILED',
            'API入口URLを生成できません。',
            500
        );
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

button.danger {
    background: #a52b2b;
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

.status.warning {
    background: #fff8e5;
    color: #704d00;
}

pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.small {
    color: #666;
    font-size: 13px;
}

.diagnostic-table {
    width: 100%;
    border-collapse: collapse;
}

.diagnostic-table th,
.diagnostic-table td {
    padding: 10px;

    border-bottom:
        1px solid #ddd;

    text-align: left;

    vertical-align: top;

    overflow-wrap: anywhere;
}

.diagnostic-table th {
    width: 180px;
    background: #f8f9fa;
}

.endpoint {
    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Consolas,
        monospace;

    overflow-wrap: anywhere;
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

    .diagnostic-table th {
        width: 120px;
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
単一入口
<code>index.php</code>
基盤通信テスト
</p>

<p class="small">
この画面はApache経由で実行された
<code>index.php</code>
からAPI入口を生成します。
</p>

<p class="small">
API入口：
<br>
<code
    id="entryUrlText"
    class="endpoint"
></code>
</p>

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
    CSRF取得テスト
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
></div>

</section>

<section class="card">

<h2>
POST API
</h2>

<p class="small">
CSRFトークンが未取得の場合は、
POST処理の前に自動取得します。
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
></div>

</section>

<section class="card">

<h2>
通信診断
</h2>

<table class="diagnostic-table">

<tr>
<th>
API URL
</th>
<td
    id="diagnosticUrl"
    class="endpoint"
>
-
</td>
</tr>

<tr>
<th>
HTTPメソッド
</th>
<td id="diagnosticMethod">
-
</td>
</tr>

<tr>
<th>
HTTPステータス
</th>
<td id="diagnosticStatus">
-
</td>
</tr>

<tr>
<th>
Content-Type
</th>
<td id="diagnosticContentType">
-
</td>
</tr>

<tr>
<th>
APIエラーコード
</th>
<td id="diagnosticErrorCode">
-
</td>
</tr>

<tr>
<th>
レスポンス有無
</th>
<td id="diagnosticResponse">
-
</td>
</tr>

<tr>
<th>
レスポンスサイズ
</th>
<td id="diagnosticResponseSize">
-
</td>
</tr>

<tr>
<th>
ブラウザOrigin
</th>
<td
    id="diagnosticOrigin"
    class="endpoint"
>
-
</td>
</tr>

</table>

<pre
    id="diagnosticDetail"
></pre>

</section>

<section class="card">

<h2>
直接URL確認
</h2>

<p class="small">
fetchだけでなく、同じAPI URLをブラウザで直接開くことで、
Apache/PHP側がAPIを返しているか確認できます。
</p>

<div class="buttons">

<button
    type="button"
    id="openHealthButton"
    class="secondary"
>
    health APIを直接開く
</button>

<button
    type="button"
    id="openCsrfButton"
    class="secondary"
>
    csrf APIを直接開く
</button>

</div>

</section>

</main>

<script>
(() => {
    'use strict';

    /*
     * ========================================================
     * サーバーから明示された単一入口
     *
     * ここはREQUEST_URIではなく、
     * PHPのSCRIPT_NAMEから生成された値。
     * ========================================================
     */

    const ENTRY_PATH =
        <?= $entryPathJson ?>;

    /*
     * 現在のブラウザoriginを使用。
     *
     * pathnameを業務識別には使用しない。
     */

    const entryUrl =
        new URL(
            ENTRY_PATH,
            window.location.origin
        );

    /*
     * ========================================================
     * DOM
     * ========================================================
     */

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

    const openHealthButton =
        document.getElementById(
            'openHealthButton'
        );

    const openCsrfButton =
        document.getElementById(
            'openCsrfButton'
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

    const diagnosticContentType =
        document.getElementById(
            'diagnosticContentType'
        );

    const diagnosticErrorCode =
        document.getElementById(
            'diagnosticErrorCode'
        );

    const diagnosticResponse =
        document.getElementById(
            'diagnosticResponse'
        );

    const diagnosticResponseSize =
        document.getElementById(
            'diagnosticResponseSize'
        );

    const diagnosticOrigin =
        document.getElementById(
            'diagnosticOrigin'
        );

    const diagnosticDetail =
        document.getElementById(
            'diagnosticDetail'
        );

    entryUrlText.textContent =
        entryUrl.toString();

    diagnosticOrigin.textContent =
        window.location.origin;

    /*
     * ========================================================
     * 状態
     * ========================================================
     */

    let getProcessing = false;

    let postProcessing = false;

    let csrfToken = '';

    /*
     * ========================================================
     * API URL
     * ========================================================
     */

    function apiUrl(action) {

        const url =
            new URL(
                entryUrl.toString()
            );

        /*
         * 既存query stringを削除。
         */

        url.search = '';

        url.searchParams.set(
            'action',
            action
        );

        return url.toString();
    }

    /*
     * ========================================================
     * ローディング
     * ========================================================
     */

    function setGetProcessing(
        value
    ) {
        getProcessing = value;

        healthButton.disabled =
            value;

        csrfButton.disabled =
            value;

        openHealthButton.disabled =
            value;

        openCsrfButton.disabled =
            value;

        getLoading.classList.toggle(
            'active',
            value
        );
    }

    function setPostProcessing(
        value
    ) {
        postProcessing = value;

        postButton.disabled =
            value;

        postLoading.classList.toggle(
            'active',
            value
        );
    }

    /*
     * ========================================================
     * 診断情報
     * ========================================================
     */

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

        diagnosticContentType.textContent =
            '取得中';

        diagnosticErrorCode.textContent =
            '-';

        diagnosticResponse.textContent =
            '取得中';

        diagnosticResponseSize.textContent =
            '取得中';

        diagnosticDetail.textContent =
            '';
    }

    function setDiagnosticSuccess(
        response,
        data,
        text
    ) {
        diagnosticStatus.textContent =
            String(
                response.status
            );

        diagnosticContentType.textContent =
            response.headers.get(
                'content-type'
            )
            || '(なし)';

        diagnosticErrorCode.textContent =
            '-';

        diagnosticResponse.textContent =
            'あり';

        diagnosticResponseSize.textContent =
            String(
                text.length
            )
            + ' characters';

        diagnosticDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );
    }

    function setDiagnosticApiError(
        response,
        data,
        text
    ) {
        diagnosticStatus.textContent =
            String(
                response.status
            );

        diagnosticContentType.textContent =
            response.headers.get(
                'content-type'
            )
            || '(なし)';

        diagnosticErrorCode.textContent =
            data?.error?.code
            || 'API_ERROR';

        diagnosticResponse.textContent =
            'あり';

        diagnosticResponseSize.textContent =
            String(
                text.length
            )
            + ' characters';

        diagnosticDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );
    }

    function setDiagnosticNetworkError(
        error,
        extraMessage = ''
    ) {
        diagnosticStatus.textContent =
            '取得できませんでした';

        diagnosticContentType.textContent =
            '取得できませんでした';

        diagnosticErrorCode.textContent =
            'NETWORK_ERROR';

        diagnosticResponse.textContent =
            'なし';

        diagnosticResponseSize.textContent =
            '-';

        let message =
            error instanceof Error
                ? error.message
                : String(error);

        if (
            extraMessage !== ''
        ) {
            message +=
                '\n'
                + extraMessage;
        }

        diagnosticDetail.textContent =
            message;
    }

    /*
     * ========================================================
     * タイムアウト付きfetch
     * ========================================================
     */

    async function fetchWithTimeout(
        url,
        options = {},
        timeoutMs = 15000
    ) {
        const controller =
            new AbortController();

        const timeoutId =
            window.setTimeout(
                () => {
                    controller.abort();
                },
                timeoutMs
            );

        try {
            return await fetch(
                url,
                {
                    ...options,
                    signal:
                        controller.signal
                }
            );
        } catch (error) {

            if (
                error
                && error.name === 'AbortError'
            ) {
                throw new Error(
                    'API通信がタイムアウトしました。'
                    + '\n'
                    + 'タイムアウト: '
                    + timeoutMs
                    + 'ms'
                    + '\n'
                    + 'URL: '
                    + url
                );
            }

            throw error;

        } finally {
            window.clearTimeout(
                timeoutId
            );
        }
    }

    /*
     * ========================================================
     * JSONレスポンス読み込み
     * ========================================================
     */

    async function readJsonResponse(
        response
    ) {
        const text =
            await response.text();

        const contentType =
            response.headers.get(
                'content-type'
            )
            || '';

        if (
            text.trim() === ''
        ) {
            throw new Error(
                'サーバーから空のレスポンスが返されました。'
                + '\nHTTP: '
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
                'JSON解析に失敗しました。'
                + '\nHTTP: '
                + response.status
                + '\nContent-Type: '
                + contentType
                + '\nレスポンス先頭: '
                + text.slice(
                    0,
                    1000
                )
            );
        }

        return {
            data,
            contentType,
            text
        };
    }

    /*
     * ========================================================
     * GET API
     * ========================================================
     */

    async function requestGet(
        action
    ) {
        const url =
            apiUrl(action);

        resetDiagnostic(
            url,
            'GET'
        );

        try {

            const response =
                await fetchWithTimeout(
                    url,
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

                        redirect:
                            'follow'
                    },
                    API_TIMEOUT_MS
                );

            const parsed =
                await readJsonResponse(
                    response
                );

            const data =
                parsed.data;

            if (
                !response.ok
                || data?.success !== true
            ) {

                setDiagnosticApiError(
                    response,
                    data,
                    parsed.text
                );

                const message =
                    data?.error?.message
                    || (
                        'API処理に失敗しました。'
                        + '\nHTTP: '
                        + response.status
                    );

                throw new Error(
                    message
                );
            }

            setDiagnosticSuccess(
                response,
                data,
                parsed.text
            );

            return data;

        } catch (error) {

            /*
             * HTTPレスポンスを受け取った後の
             * APIエラーの場合は、
             * すでに診断情報を設定済み。
             *
             * fetch自体が失敗した場合だけ
             * NETWORK_ERRORとする。
             */

            if (
                diagnosticStatus.textContent
                === '取得中'
            ) {

                let extra =
                    '';

                if (
                    window.location.protocol
                    === 'https:'
                    && url.startsWith(
                        'http:'
                    )
                ) {
                    extra =
                        'HTTPSページからHTTP APIを呼び出しているため、'
                        + 'Mixed Contentでブロックされた可能性があります。';
                }

                setDiagnosticNetworkError(
                    error,
                    extra
                );
            }

            throw error;
        }
    }

    /*
     * ========================================================
     * CSRF取得
     * ========================================================
     */

    async function getCsrfToken() {

        const data =
            await requestGet(
                'csrf'
            );

        const token =
            data?.data?.csrfToken;

        if (
            typeof token !== 'string'
            || token === ''
        ) {
            throw new Error(
                'APIは成功しましたが、'
                + 'CSRFトークンが含まれていません。'
            );
        }

        csrfToken =
            token;

        return csrfToken;
    }

    /*
     * ========================================================
     * POST API
     * ========================================================
     */

    async function requestPost(
        action,
        body = {}
    ) {

        /*
         * CSRF取得。
         *
         * ここでは診断URLをPOSTに変更する前に、
         * GET csrfを実行する。
         */

        if (!csrfToken) {

            try {

                await getCsrfToken();

            } catch (error) {

                /*
                 * CSRF GETの診断情報を維持する。
                 */

                throw error;
            }
        }

        const url =
            apiUrl(action);

        resetDiagnostic(
            url,
            'POST'
        );

        const payload = {
            action,
            csrf_token:
                csrfToken,
            ...body
        };

        try {

            const response =
                await fetchWithTimeout(
                    url,
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

                        redirect:
                            'follow',

                        body:
                            JSON.stringify(
                                payload
                            )
                    },
                    API_TIMEOUT_MS
                );

            const parsed =
                await readJsonResponse(
                    response
                );

            const data =
                parsed.data;

            if (
                !response.ok
                || data?.success !== true
            ) {

                setDiagnosticApiError(
                    response,
                    data,
                    parsed.text
                );

                if (
                    data?.error?.code
                    === 'CSRF_INVALID'
                ) {
                    csrfToken =
                        '';
                }

                const message =
                    data?.error?.message
                    || (
                        'POST API処理に失敗しました。'
                        + '\nHTTP: '
                        + response.status
                    );

                throw new Error(
                    message
                );
            }

            setDiagnosticSuccess(
                response,
                data,
                parsed.text
            );

            return data;

        } catch (error) {

            if (
                diagnosticStatus.textContent
                === '取得中'
            ) {
                setDiagnosticNetworkError(
                    error
                );
            }

            throw error;
        }
    }

    /*
     * ========================================================
     * GET health
     * ========================================================
     */

    async function testHealth() {

        if (getProcessing) {
            return;
        }

        setGetProcessing(
            true
        );

        getResult.className =
            'status';

        getResult.textContent =
            'GET API通信中…';

        try {

            const data =
                await requestGet(
                    'health'
                );

            getResult.className =
                'status ok';

            getResult.textContent =
                'GET API通信成功\n\n'
                + JSON.stringify(
                    data,
                    null,
                    2
                );

        } catch (error) {

            getResult.className =
                'status error';

            getResult.textContent =
                'GET API通信失敗\n\n'
                + (
                    error instanceof Error
                        ? error.message
                        : String(error)
                );

        } finally {

            setGetProcessing(
                false
            );
        }
    }

    /*
     * ========================================================
     * CSRFテスト
     * ========================================================
     */

    async function testCsrf() {

        if (getProcessing) {
            return;
        }

        setGetProcessing(
            true
        );

        getResult.className =
            'status';

        getResult.textContent =
            'CSRFトークン取得中…';

        try {

            const token =
                await getCsrfToken();

            getResult.className =
                'status ok';

            getResult.textContent =
                'CSRFトークン取得成功\n\n'
                + 'トークン長: '
                + token.length
                + '\n'
                + 'トークン本体は画面には表示しません。';

        } catch (error) {

            getResult.className =
                'status error';

            getResult.textContent =
                'CSRFトークン取得失敗\n\n'
                + (
                    error instanceof Error
                        ? error.message
                        : String(error)
                );

        } finally {

            setGetProcessing(
                false
            );
        }
    }

    /*
     * ========================================================
     * POSTテスト
     * ========================================================
     */

    async function testPost() {

        if (postProcessing) {
            return;
        }

        setPostProcessing(
            true
        );

        postResult.className =
            'status';

        postResult.textContent =
            'POST API通信中…';

        try {

            const data =
                await requestPost(
                    'test_post'
                );

            postResult.className =
                'status ok';

            postResult.textContent =
                'POST API通信成功\n\n'
                + JSON.stringify(
                    data,
                    null,
                    2
                );

        } catch (error) {

            postResult.className =
                'status error';

            postResult.textContent =
                'POST API通信失敗\n\n'
                + (
                    error instanceof Error
                        ? error.message
                        : String(error)
                );

        } finally {

            setPostProcessing(
                false
            );
        }
    }

    /*
     * ========================================================
     * 直接URL
     * ========================================================
     *
     * fetchが失敗する場合、
     * 同じURLをブラウザで直接開く。
     *
     * これにより、
     *
     * Apache
     * ↓
     * PHP
     * ↓
     * index.php
     *
     * が実際にAPIを返しているかを確認できる。
     */

    function openApi(
        action
    ) {
        const url =
            apiUrl(action);

        window.open(
            url,
            '_blank',
            'noopener'
        );
    }

    /*
     * ========================================================
     * イベント
     * ========================================================
     */

    healthButton.addEventListener(
        'click',
        testHealth
    );

    csrfButton.addEventListener(
        'click',
        testCsrf
    );

    postButton.addEventListener(
        'click',
        testPost
    );

    openHealthButton.addEventListener(
        'click',
        () => {
            openApi(
                'health'
            );
        }
    );

    openCsrfButton.addEventListener(
        'click',
        () => {
            openApi(
                'csrf'
            );
        }
    );

})();
</script>

</body>
</html>
<?php

    /*
     * 画面表示終了。
     */

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
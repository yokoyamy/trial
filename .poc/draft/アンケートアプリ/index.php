<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * 単一入口 index.php
 *
 * 第1段階・実行基盤修正版
 *
 * 対象環境:
 *   Apache24
 *   PHP 8.4 / 8.5
 *   DBなし
 *
 * 重要方針:
 *   - 公開PHP入口はindex.phpのみ
 *   - GETは参照
 *   - POSTは変更
 *   - POSTはCSRF必須
 *   - APIは必ずJSON
 *   - PHP Warning / Notice / Fatal Errorによる
 *     APIレスポンス破壊を防止
 *   - health APIはセッション等に依存しない
 *   - JavaScriptは物理APIパスをハードコードしない
 *   - 現在のindex.php URLをサーバーから取得する
 *   - fetch失敗時に診断情報を表示する
 * ============================================================
 */

const APP_TIMEZONE = 'Asia/Tokyo';
const API_TIMEOUT_MS = 15000;

date_default_timezone_set(APP_TIMEZONE);

/**
 * ============================================================
 * 初期HTTPヘッダー
 * ============================================================
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/**
 * ============================================================
 * API判定
 * ============================================================
 */

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

    if (str_contains($contentType, 'application/json')) {
        return true;
    }

    return false;
}

/**
 * ============================================================
 * JSONエンコード
 * ============================================================
 */

function encodeJson(mixed $data): string
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return '{"success":false,"error":{"code":"JSON_ENCODE_ERROR","message":"JSONレスポンスの生成に失敗しました。"}}';
    }

    return $json;
}

/**
 * ============================================================
 * 出力バッファ初期化
 *
 * APIレスポンスへWarning等が混入することを防止する。
 * ============================================================
 */

ob_start();

/**
 * ============================================================
 * 共通レスポンス
 * ============================================================
 */

function clearOutputBuffer(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {
    clearOutputBuffer();

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

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
    clearOutputBuffer();

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

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

/**
 * ============================================================
 * HTMLエスケープ
 * ============================================================
 */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * ============================================================
 * PHPエラーを例外化
 *
 * Warning / Notice等をHTMLへ直接出力させない。
 * ============================================================
 */

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

        throw new ErrorException(
            $message,
            0,
            $severity,
            $file,
            $line
        );
    }
);

/**
 * ============================================================
 * 未処理例外
 * ============================================================
 */

set_exception_handler(
    function (Throwable $e): void {
        error_log(
            '[APP_EXCEPTION] '
            . get_class($e)
            . ': '
            . $e->getMessage()
            . ' @ '
            . $e->getFile()
            . ':'
            . $e->getLine()
        );

        if (isApiRequest()) {
            errorResponse(
                'INTERNAL_ERROR',
                'サーバー内部で予期しないエラーが発生しました。',
                500
            );
        }

        clearOutputBuffer();

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');

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

/**
 * ============================================================
 * PHP Fatal Error対策
 *
 * set_exception_handler()ではFatal Errorを捕捉できないため、
 * shutdown handlerを使用する。
 * ============================================================
 */

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

        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        error_log(
            '[APP_FATAL] '
            . ($error['message'] ?? '')
            . ' @ '
            . ($error['file'] ?? '')
            . ':'
            . ($error['line'] ?? '')
        );

        if (!isApiRequest()) {
            return;
        }

        /*
         * shutdown中なので通常のerrorResponse()と同じ処理を
         * 直接行う。
         */
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');

        echo encodeJson(
            [
                'success' => false,
                'error' => [
                    'code' => 'PHP_FATAL_ERROR',
                    'message' => 'サーバー内部エラーが発生しました。PHPエラーログを確認してください。',
                ],
            ]
        );
    }
);

/**
 * ============================================================
 * HTTPメソッド
 * ============================================================
 */

$method = strtoupper(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

/*
 * OPTIONSはブラウザ等から送られた場合でも、
 * HTMLや空レスポンスではなくJSONで終了させる。
 *
 * 同一Originでは通常不要だが、診断時に重要。
 */
if ($method === 'OPTIONS') {
    clearOutputBuffer();

    http_response_code(204);
    header('Content-Type: application/json; charset=utf-8');

    exit;
}

if (!in_array($method, ['GET', 'POST'], true)) {
    errorResponse(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405
    );
}

/**
 * ============================================================
 * action取得
 * ============================================================
 */

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if (!is_string($raw)) {
        return [];
    }

    if (trim($raw) === '') {
        return [];
    }

    try {
        $decoded = json_decode(
            $raw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        errorResponse(
            'INVALID_JSON',
            'JSON形式のリクエストを解析できません。',
            400
        );
    }

    if (!is_array($decoded)) {
        errorResponse(
            'INVALID_JSON_STRUCTURE',
            'JSONリクエストの形式が不正です。',
            400
        );
    }

    return $decoded;
}

$requestJson = [];

function getRequestJson(): array
{
    global $requestJson;

    if ($requestJson !== []) {
        return $requestJson;
    }

    $contentType = strtolower(
        (string)($_SERVER['CONTENT_TYPE'] ?? '')
    );

    if (!str_contains($contentType, 'application/json')) {
        return [];
    }

    $requestJson = readJsonBody();

    return $requestJson;
}

function getAction(): string
{
    $method = strtoupper(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
    );

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        if (!is_string($action)) {
            return '';
        }

        return trim($action);
    }

    $action = $_POST['action'] ?? '';

    if (is_string($action) && trim($action) !== '') {
        return trim($action);
    }

    $json = getRequestJson();

    $action = $json['action'] ?? '';

    if (!is_string($action)) {
        return '';
    }

    return trim($action);
}

/**
 * ============================================================
 * action定義
 * ============================================================
 */

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

/**
 * ============================================================
 * action
 * ============================================================
 */

$action = getAction();

/**
 * ============================================================
 * 最重要:
 *
 * healthはApache/PHP/Fast path確認用なので、
 * session_start()やCSRF等に依存させない。
 * ============================================================
 */

if ($method === 'GET' && $action === 'health') {
    successResponse(
        [
            'status' => 'ok',
            'phpVersion' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'serverSoftware' => (string)(
                $_SERVER['SERVER_SOFTWARE'] ?? ''
            ),
            'https' => (
                (!empty($_SERVER['HTTPS'])
                && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
            ),
            'requestUri' => (string)(
                $_SERVER['REQUEST_URI'] ?? ''
            ),
            'scriptFilename' => (string)(
                $_SERVER['SCRIPT_FILENAME'] ?? ''
            ),
            'scriptName' => (string)(
                $_SERVER['SCRIPT_NAME'] ?? ''
            ),
            'method' => $method,
            'time' => date(DATE_ATOM),
        ],
        '通信成功',
        200
    );
}

/**
 * ============================================================
 * action検証
 * ============================================================
 */

if ($method === 'GET') {
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

/**
 * ============================================================
 * セッション
 *
 * healthでは不要。
 * csrfまたはPOSTでのみ初期化する。
 * ============================================================
 */

function ensureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $result = session_start(
        [
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]
    );

    if ($result !== true) {
        errorResponse(
            'SESSION_START_FAILED',
            'セッションを開始できませんでした。PHPのセッション設定を確認してください。',
            500
        );
    }
}

if (
    ($method === 'GET' && $action === 'csrf')
    || $method === 'POST'
) {
    ensureSession();
}

/**
 * ============================================================
 * CSRF
 * ============================================================
 */

function ensureCsrfToken(): string
{
    ensureSession();

    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || $_SESSION['csrf_token'] === ''
    ) {
        try {
            $_SESSION['csrf_token'] = bin2hex(
                random_bytes(32)
            );
        } catch (Throwable $e) {
            error_log(
                '[CSRF_TOKEN_ERROR] '
                . $e->getMessage()
            );

            errorResponse(
                'CSRF_GENERATION_FAILED',
                'CSRFトークンを生成できませんでした。',
                500
            );
        }
    }

    return $_SESSION['csrf_token'];
}

$csrfToken = '';

if (
    ($method === 'GET' && $action === 'csrf')
    || $method === 'POST'
) {
    $csrfToken = ensureCsrfToken();
}

function getRequestCsrfToken(): string
{
    /*
     * ヘッダーを優先。
     */
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        is_string($headerToken)
        && $headerToken !== ''
    ) {
        return $headerToken;
    }

    /*
     * application/x-www-form-urlencoded
     */
    $postToken = $_POST['csrf_token'] ?? '';

    if (
        is_string($postToken)
        && $postToken !== ''
    ) {
        return $postToken;
    }

    /*
     * JSON
     */
    $json = getRequestJson();

    $jsonToken = $json['csrf_token'] ?? '';

    if (
        is_string($jsonToken)
        && $jsonToken !== ''
    ) {
        return $jsonToken;
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

if ($method === 'POST') {
    validateCsrfToken();
}

/**
 * ============================================================
 * GET API: csrf
 * ============================================================
 */

if ($method === 'GET' && $action === 'csrf') {
    successResponse(
        [
            'csrfToken' => $csrfToken,
        ],
        'CSRFトークン取得成功'
    );
}

/**
 * ============================================================
 * POST API: test_post
 * ============================================================
 */

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

/**
 * ============================================================
 * GET: 画面表示
 *
 * actionなしGETだけが画面表示。
 * ============================================================
 */

if (
    $method === 'GET'
    && $action === ''
) {
    /*
     * REQUEST_URIからquery stringを除去。
     *
     * ここで物理パスをJavaScriptへハードコードしない。
     * 現在実際にアクセスされたindex.php自身を基準とする。
     */
    $requestUri = (string)(
        $_SERVER['REQUEST_URI'] ?? ''
    );

    $queryPosition = strpos(
        $requestUri,
        '?'
    );

    if ($queryPosition !== false) {
        $entryPath = substr(
            $requestUri,
            0,
            $queryPosition
        );
    } else {
        $entryPath = $requestUri;
    }

    if ($entryPath === '') {
        $entryPath = (string)(
            $_SERVER['SCRIPT_NAME'] ?? '/index.php'
        );
    }

    /*
     * HTML/JSへ安全に埋め込む。
     */
    $entryPathJson = encodeJson($entryPath);
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

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
    width: min(1100px, 100%);
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

h1,
h2,
h3 {
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

button.warning {
    background: #9a6700;
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
    color: #745200;
}

pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.small {
    color: #666;
    font-size: 13px;
}

.mono {
    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Monaco,
        Consolas,
        monospace;
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

.diagnostic-grid {
    display: grid;
    grid-template-columns:
        repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.diagnostic-item {
    padding: 12px;
    background: #f7f8fa;
    border-radius: 8px;
}

.diagnostic-label {
    display: block;
    color: #666;
    font-size: 12px;
    margin-bottom: 4px;
}

.diagnostic-value {
    overflow-wrap: anywhere;
}

.api-link {
    display: inline-block;
    margin-top: 8px;
    word-break: break-all;
}

details {
    margin-top: 16px;
}

summary {
    cursor: pointer;
    font-weight: 700;
}

.spinner {
    display: inline-block;
    width: 13px;
    height: 13px;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: spin .7s linear infinite;
    vertical-align: -2px;
    margin-right: 6px;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 700px) {
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

    .diagnostic-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<main>

<section class="card">

<h1>アンケート管理システム</h1>

<p>
単一入口
<code>index.php</code>
基盤通信テスト
</p>

<p class="small">
この画面自身を返したApache/PHPのURLを、
API通信の基準URLとして使用します。
</p>

<p>
<span class="small">
現在の単一入口:
</span>

<br>

<code
    id="entryUrlText"
    class="mono"
></code>
</p>

</section>


<section class="card">

<h2>第1段階・実行基盤テスト</h2>

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

<button
    type="button"
    id="postButton"
    class="success"
>
POST APIテスト
</button>

<button
    type="button"
    id="directHealthButton"
    class="warning"
>
healthを直接開く
</button>

</div>

<span
    id="getLoading"
    class="loading"
    aria-live="polite"
>
<span class="spinner"></span>
GET処理中…
</span>

<span
    id="postLoading"
    class="loading"
    aria-live="polite"
>
<span class="spinner"></span>
POST処理中…
</span>

<div
    id="getResult"
    class="status"
></div>

<div
    id="postResult"
    class="status"
></div>

</section>


<section class="card">

<h2>通信診断</h2>

<div class="diagnostic-grid">

<div class="diagnostic-item">
<span class="diagnostic-label">
API URL
</span>
<div
    id="diagnosticUrl"
    class="diagnostic-value mono"
>
-
</div>
</div>

<div class="diagnostic-item">
<span class="diagnostic-label">
HTTPメソッド
</span>
<div
    id="diagnosticMethod"
    class="diagnostic-value"
>
-
</div>
</div>

<div class="diagnostic-item">
<span class="diagnostic-label">
HTTPステータス
</span>
<div
    id="diagnosticStatus"
    class="diagnostic-value"
>
-
</div>
</div>

<div class="diagnostic-item">
<span class="diagnostic-label">
Content-Type
</span>
<div
    id="diagnosticContentType"
    class="diagnostic-value"
>
-
</div>
</div>

<div class="diagnostic-item">
<span class="diagnostic-label">
APIエラーコード
</span>
<div
    id="diagnosticErrorCode"
    class="diagnostic-value"
>
-
</div>
</div>

<div class="diagnostic-item">
<span class="diagnostic-label">
レスポンス有無
</span>
<div
    id="diagnosticResponse"
    class="diagnostic-value"
>
-
</div>
</div>

<div class="diagnostic-item">
<span class="diagnostic-label">
レスポンスサイズ
</span>
<div
    id="diagnosticSize"
    class="diagnostic-value"
>
-
</div>
</div>

<div class="diagnostic-item">
<span class="diagnostic-label">
ブラウザOrigin
</span>
<div
    id="diagnosticOrigin"
    class="diagnostic-value mono"
>
-
</div>
</div>

<div class="diagnostic-item">
<span class="diagnostic-label">
タイムアウト
</span>
<div
    id="diagnosticTimeout"
    class="diagnostic-value"
>
<?= h((string)API_TIMEOUT_MS) ?> ms
</div>
</div>

</div>

<details>
<summary>
詳細診断情報
</summary>

<pre
    id="diagnosticDetail"
></pre>

</details>

</section>


<section class="card">

<h2>直接API確認</h2>

<p>
まずJavaScriptの
<code>fetch()</code>
を介さず、ブラウザ自身から
health APIを開いて確認できます。
</p>

<a
    id="directHealthLink"
    class="api-link mono"
    href="#"
>
health APIを直接開く
</a>

<p class="small">
このリンクを開いて
<code>success:true</code>
のJSONが表示されるか確認してください。
</p>

</section>


<section class="card">

<h2>URL状態テスト</h2>

<div class="buttons">

<button
    type="button"
    id="screenAdminButton"
>
admin
</button>

<button
    type="button"
    id="screenSurveyButton"
    class="secondary"
>
survey
</button>

<button
    type="button"
    id="screenAnswerButton"
    class="secondary"
>
answer
</button>

<button
    type="button"
    id="clearScreenButton"
    class="warning"
>
URL状態クリア
</button>

</div>

<div
    id="urlStateResult"
    class="status"
></div>

</section>

</main>


<script>
(() => {
    'use strict';

    /**
     * ========================================================
     * サーバーが返した現在のindex.php
     *
     * 物理パスをJavaScriptへハードコードしない。
     * ========================================================
     */

    const ENTRY_PATH =
        <?= $entryPathJson ?>;

    const entryUrl =
        new URL(
            ENTRY_PATH,
            window.location.origin
        );

    /**
     * URL表示
     */
    const entryUrlText =
        document.getElementById(
            'entryUrlText'
        );

    entryUrlText.textContent =
        entryUrl.toString();

    /**
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
         * 現在index.phpへ付いている
         * query stringは捨てる。
         */
        url.search = '';

        url.searchParams.set(
            'action',
            action
        );

        return url.toString();
    }

    /**
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

    const directHealthButton =
        document.getElementById(
            'directHealthButton'
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

    const diagnosticSize =
        document.getElementById(
            'diagnosticSize'
        );

    const diagnosticOrigin =
        document.getElementById(
            'diagnosticOrigin'
        );

    const diagnosticTimeout =
        document.getElementById(
            'diagnosticTimeout'
        );

    const diagnosticDetail =
        document.getElementById(
            'diagnosticDetail'
        );

    const directHealthLink =
        document.getElementById(
            'directHealthLink'
        );

    const urlStateResult =
        document.getElementById(
            'urlStateResult'
        );

    /**
     * ========================================================
     * 状態
     * ========================================================
     */

    let getProcessing = false;
    let postProcessing = false;

    let csrfToken = '';

    /**
     * ========================================================
     * 初期表示
     * ========================================================
     */

    diagnosticOrigin.textContent =
        window.location.origin;

    diagnosticTimeout.textContent =
        String(<?= (int)API_TIMEOUT_MS ?>)
        + ' ms';

    const healthUrl =
        apiUrl('health');

    directHealthLink.href =
        healthUrl;

    /**
     * ========================================================
     * ローディング
     * ========================================================
     */

    function setGetProcessing(value) {
        getProcessing = value;

        healthButton.disabled =
            value;

        csrfButton.disabled =
            value;

        getLoading.classList.toggle(
            'active',
            value
        );
    }

    function setPostProcessing(value) {
        postProcessing = value;

        postButton.disabled =
            value;

        postLoading.classList.toggle(
            'active',
            value
        );
    }

    /**
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
            '待機中';

        diagnosticSize.textContent =
            '-';

        diagnosticDetail.textContent =
            '';
    }

    function setDiagnosticResponse(
        response,
        text,
        data
    ) {
        diagnosticStatus.textContent =
            String(response.status);

        diagnosticContentType.textContent =
            response.headers.get(
                'content-type'
            ) || '(なし)';

        diagnosticResponse.textContent =
            'あり';

        diagnosticSize.textContent =
            String(
                new TextEncoder().encode(text).length
            )
            + ' bytes';

        diagnosticErrorCode.textContent =
            data?.success === true
                ? '-'
                : (
                    data?.error?.code
                    || 'API_ERROR'
                );

        diagnosticDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );
    }

    function setDiagnosticNetworkError(
        error,
        url,
        method
    ) {
        diagnosticUrl.textContent =
            url;

        diagnosticMethod.textContent =
            method;

        diagnosticStatus.textContent =
            '取得できませんでした';

        diagnosticContentType.textContent =
            '取得できませんでした';

        diagnosticErrorCode.textContent =
            'NETWORK_ERROR';

        diagnosticResponse.textContent =
            'なし';

        diagnosticSize.textContent =
            '-';

        let message =
            error instanceof Error
                ? error.message
                : String(error);

        diagnosticDetail.textContent =
            [
                message,
                '',
                'ブラウザOrigin: '
                + window.location.origin,
                'API URL: '
                + url,
                'HTTPメソッド: '
                + method,
                '',
                '考えられる原因:',
                '1. Apache24が該当URLを処理できていない',
                '2. PHPがApacheから実行されていない',
                '3. HTTPS証明書/TLSの問題',
                '4. ApacheのVirtualHost/Alias/Directory設定の問題',
                '5. PHP Fatal Errorで接続が途中終了している',
                '6. ブラウザからAPI URLへ到達できない',
                '7. ブラウザのネットワーク制約',
                '8. fetch前提ではなくAPI URL自体が応答していない'
            ].join('\n');
    }

    /**
     * ========================================================
     * AbortControllerによるタイムアウト
     * ========================================================
     */

    async function fetchWithTimeout(
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
                <?= (int)API_TIMEOUT_MS ?>
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
                error instanceof DOMException
                && error.name === 'AbortError'
            ) {
                throw new Error(
                    'API通信が'
                    + <?= (int)API_TIMEOUT_MS ?>
                    + 'msでタイムアウトしました。'
                );
            }

            throw error;
        } finally {
            window.clearTimeout(
                timeoutId
            );
        }
    }

    /**
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
            ) || '';

        if (text.trim() === '') {
            throw new Error(
                'サーバーから空のレスポンスが返されました。'
                + '\nHTTP: '
                + response.status
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
                + text.slice(0, 1000)
            );
        }

        return {
            data,
            text,
            contentType
        };
    }

    /**
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

                        /*
                         * 同一Originのセッションを使用。
                         */
                        credentials:
                            'same-origin',

                        /*
                         * リダイレクトを
                         * fetch側で不必要に制御しない。
                         */
                        cache: 'no-store'
                    }
                );

            const parsed =
                await readJsonResponse(
                    response
                );

            setDiagnosticResponse(
                response,
                parsed.text,
                parsed.data
            );

            if (
                !response.ok
                || parsed.data?.success !== true
            ) {
                throw new Error(
                    parsed.data?.error?.message
                    || 'API処理に失敗しました。'
                );
            }

            return parsed.data;

        } catch (error) {
            /*
             * HTTPレスポンスを受信できた場合は
             * NETWORK_ERRORへ上書きしない。
             */
            if (
                diagnosticResponse.textContent
                === '待機中'
            ) {
                setDiagnosticNetworkError(
                    error,
                    url,
                    'GET'
                );
            }

            throw error;
        }
    }

    /**
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

    /**
     * ========================================================
     * POST API
     * ========================================================
     */

    async function requestPost(
        action,
        body = {}
    ) {
        const url =
            apiUrl(action);

        resetDiagnostic(
            url,
            'POST'
        );

        try {
            if (!csrfToken) {
                await getCsrfToken();
            }

            const payload = {
                action,
                csrf_token:
                    csrfToken,
                ...body
            };

            /*
             * application/jsonを使用。
             * same-originなのでCORS preflightは発生しない。
             */
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

                        cache: 'no-store',

                        body:
                            JSON.stringify(
                                payload
                            )
                    }
                );

            const parsed =
                await readJsonResponse(
                    response
                );

            setDiagnosticResponse(
                response,
                parsed.text,
                parsed.data
            );

            if (
                !response.ok
                || parsed.data?.success !== true
            ) {
                if (
                    parsed.data?.error?.code
                    === 'CSRF_INVALID'
                ) {
                    csrfToken = '';
                }

                throw new Error(
                    parsed.data?.error?.message
                    || 'POST API処理に失敗しました。'
                );
            }

            return parsed.data;

        } catch (error) {
            if (
                diagnosticResponse.textContent
                === '待機中'
            ) {
                setDiagnosticNetworkError(
                    error,
                    url,
                    'POST'
                );
            }

            throw error;
        }
    }

    /**
     * ========================================================
     * GET health
     * ========================================================
     */

    async function testHealth() {
        if (getProcessing) {
            return;
        }

        setGetProcessing(true);

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
            setGetProcessing(false);
        }
    }

    /**
     * ========================================================
     * CSRF
     * ========================================================
     */

    async function testCsrf() {
        if (getProcessing) {
            return;
        }

        setGetProcessing(true);

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
                + 'トークン本体は表示しません。';

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
            setGetProcessing(false);
        }
    }

    /**
     * ========================================================
     * POST
     * ========================================================
     */

    async function testPost() {
        if (postProcessing) {
            return;
        }

        setPostProcessing(true);

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
            setPostProcessing(false);
        }
    }

    /**
     * ========================================================
     * 直接health
     * ========================================================
     */

    function openDirectHealth() {
        window.location.href =
            apiUrl('health');
    }

    /**
     * ========================================================
     * URL状態
     *
     * pathnameは使用しない。
     * query stringだけを画面状態として使用する。
     * ========================================================
     */

    function readUrlState() {
        const url =
            new URL(
                window.location.href
            );

        return {
            screen:
                url.searchParams.get(
                    'screen'
                ) || '',
            surveyId:
                url.searchParams.get(
                    'surveyId'
                ) || '',
            customerId:
                url.searchParams.get(
                    'customerId'
                ) || ''
        };
    }

    function renderUrlState() {
        const state =
            readUrlState();

        urlStateResult.className =
            'status';

        urlStateResult.textContent =
            '現在URL状態\n\n'
            + JSON.stringify(
                state,
                null,
                2
            )
            + '\n\n'
            + window.location.href;
    }

    function setUrlState(
        params,
        mode = 'push'
    ) {
        const url =
            new URL(
                window.location.href
            );

        /*
         * 業務画面の状態をquery stringだけで管理。
         */
        url.search = '';

        for (
            const [
                key,
                value
            ] of Object.entries(params)
        ) {
            if (
                value !== null
                && value !== undefined
                && value !== ''
            ) {
                url.searchParams.set(
                    key,
                    value
                );
            }
        }

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

    /**
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

    directHealthButton.addEventListener(
        'click',
        openDirectHealth
    );

    directHealthLink.addEventListener(
        'click',
        function (event) {
            event.preventDefault();

            window.location.href =
                apiUrl('health');
        }
    );

    document
        .getElementById(
            'screenAdminButton'
        )
        .addEventListener(
            'click',
            () => {
                setUrlState({
                    screen: 'admin'
                });
            }
        );

    document
        .getElementById(
            'screenSurveyButton'
        )
        .addEventListener(
            'click',
            () => {
                setUrlState({
                    screen: 'survey',
                    surveyId: 'survey_test'
                });
            }
        );

    document
        .getElementById(
            'screenAnswerButton'
        )
        .addEventListener(
            'click',
            () => {
                setUrlState({
                    screen: 'answer',
                    surveyId: 'survey_test',
                    customerId: 'customer_test'
                });
            }
        );

    document
        .getElementById(
            'clearScreenButton'
        )
        .addEventListener(
            'click',
            () => {
                setUrlState(
                    {},
                    'replace'
                );
            }
        );

    /**
     * 戻る・進む
     */
    window.addEventListener(
        'popstate',
        renderUrlState
    );

    /**
     * 再読み込み・直接URLアクセス
     */
    renderUrlState();

})();
</script>

</body>
</html>
<?php

    /*
     * 画面レスポンス終了。
     */
    clearOutputBuffer();

    /*
     * 上のHTMLはすでにob_start()へ入っている。
     * ここで一度だけ出力する。
     */
    exit;
}

/**
 * ============================================================
 * 未実装GET API
 * ============================================================
 */

if ($method === 'GET') {
    errorResponse(
        'NOT_IMPLEMENTED',
        'このGET業務操作はまだ実装されていません。',
        501
    );
}

/**
 * ============================================================
 * 未実装POST API
 * ============================================================
 */

if ($method === 'POST') {
    errorResponse(
        'NOT_IMPLEMENTED',
        'このPOST業務操作はまだ実装されていません。',
        501
    );
}

/**
 * ============================================================
 * 到達不能
 * ============================================================
 */

errorResponse(
    'INTERNAL_ROUTING_ERROR',
    '内部ルーティングエラーです。',
    500
);
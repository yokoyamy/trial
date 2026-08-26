<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * 単一入口: index.php
 *
 * 第1段階：単一入口・実行基盤
 *
 * 実行環境:
 * - Apache24
 * - PHP 8.4 / 8.5
 * - DBなし
 * - JSON永続化
 *
 * このファイルの目的:
 * - Apache + PHP 経由で index.php が確実に実行される
 * - GET API が JSON を返す
 * - POST API が JSON を返す
 * - CSRF が動作する
 * - API入口を物理パスからハードコードしない
 * - fetch の通信障害を可能な限り詳細に切り分ける
 * - PHP Warning / Notice / Exception 等でJSONを破壊しない
 *
 * 注意:
 * このファイル単体では第2段階以降の業務機能は未実装。
 * 未実装actionは 501 JSON を返す。
 * ============================================================
 */

const APP_TIMEZONE = 'Asia/Tokyo';
const APP_NAME = 'アンケート管理システム';
const APP_VERSION = '0.1.0-foundation';

date_default_timezone_set(APP_TIMEZONE);

/**
 * ============================================================
 * PHPエラー設定
 * ============================================================
 *
 * APIレスポンスをWarning/Notice等で破壊しない。
 *
 * 本番では display_errors=Off を推奨。
 * このアプリ自身でも画面出力を抑制する。
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

/**
 * ============================================================
 * 出力バッファ
 * ============================================================
 *
 * PHP Warning等が万一発生しても、
 * APIレスポンスへ直接混入することを防ぐ。
 */
ob_start();

/**
 * ============================================================
 * Request ID
 * ============================================================
 *
 * ブラウザの診断情報とApache/PHPログを照合するために使用。
 */
$requestId = '';

try {
    $requestId = bin2hex(random_bytes(16));
} catch (Throwable) {
    $requestId = uniqid('req_', true);
}

/**
 * ============================================================
 * 共通ログ
 * ============================================================
 */
function appLog(string $message, array $context = []): void
{
    /**
     * 秘密情報・パスワード・token等をログへ出さない。
     */
    $safeContext = [];

    foreach ($context as $key => $value) {
        $keyLower = strtolower((string)$key);

        if (
            str_contains($keyLower, 'password')
            || str_contains($keyLower, 'token')
            || str_contains($keyLower, 'secret')
            || str_contains($keyLower, 'authorization')
        ) {
            $safeContext[$key] = '[REDACTED]';
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $safeContext[$key] = $value;
        } else {
            $safeContext[$key] = '[NON_SCALAR]';
        }
    }

    $line = '[SURVEY_APP] ' . $message;

    if ($safeContext !== []) {
        $json = json_encode(
            $safeContext,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (is_string($json)) {
            $line .= ' ' . $json;
        }
    }

    error_log($line);
}

/**
 * ============================================================
 * JSON出力
 * ============================================================
 */
function sendJson(
    array $payload,
    int $status = 200
): never {
    /**
     * 既に出力されているWarning等を捨てる。
     */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    header('X-Content-Type-Options: nosniff');

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        /**
         * JSON生成自体に失敗した場合。
         */
        http_response_code(500);

        echo '{"success":false,"error":{"code":"JSON_ENCODE_ERROR","message":"JSONレスポンスの生成に失敗しました。"}}';

        exit;
    }

    echo $json;
    exit;
}

/**
 * ============================================================
 * 共通成功レスポンス
 * ============================================================
 */
function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {
    global $requestId;

    sendJson(
        [
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => [
                'requestId' => $requestId,
                'appVersion' => APP_VERSION,
            ],
        ],
        $status
    );
}

/**
 * ============================================================
 * 共通エラーレスポンス
 * ============================================================
 */
function errorResponse(
    string $code,
    string $message,
    int $status = 400,
    array $details = []
): never {
    global $requestId;

    $error = [
        'code' => $code,
        'message' => $message,
    ];

    if ($details !== []) {
        $error['details'] = $details;
    }

    sendJson(
        [
            'success' => false,
            'error' => $error,
            'meta' => [
                'requestId' => $requestId,
                'appVersion' => APP_VERSION,
            ],
        ],
        $status
    );
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
 * APIリクエスト判定
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

    if (
        str_contains(
            $contentType,
            'application/json'
        )
    ) {
        return true;
    }

    return false;
}

/**
 * ============================================================
 * PHP例外共通処理
 * ============================================================
 */
set_exception_handler(
    function (Throwable $e): void {
        global $requestId;

        appLog(
            'Unhandled exception',
            [
                'requestId' => $requestId,
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]
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
        echo '<p>Request ID: ';
        echo h($requestId);
        echo '</p>';
        echo '</body>';
        echo '</html>';

        exit;
    }
);

/**
 * ============================================================
 * PHP Fatal Errorのshutdown監視
 * ============================================================
 *
 * set_exception_handler()ではFatal Errorをすべて捕捉できない。
 * shutdown_functionで最後の状態を確認する。
 */
register_shutdown_function(
    function (): void {
        global $requestId;

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

        if (
            !in_array(
                $lastError['type'],
                $fatalTypes,
                true
            )
        ) {
            return;
        }

        appLog(
            'Fatal PHP error detected',
            [
                'requestId' => $requestId,
                'type' => $lastError['type'],
                'message' => $lastError['message'],
                'file' => $lastError['file'],
                'line' => $lastError['line'],
            ]
        );

        /**
         * すでにレスポンスが送信済みの場合は
         * それ以上書き込まない。
         */
        if (headers_sent()) {
            return;
        }

        /**
         * APIの場合はJSONを返す。
         *
         * ただしE_PARSE/E_COMPILE_ERROR等は
         * このコード自身が実行できないケースもあるため、
         * ここで完全な保証はできない。
         */
        if (isApiRequest()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            http_response_code(500);

            header(
                'Content-Type: application/json; charset=utf-8'
            );

            echo json_encode(
                [
                    'success' => false,
                    'error' => [
                        'code' => 'PHP_FATAL_ERROR',
                        'message' => 'PHP内部エラーによりAPI処理を完了できませんでした。',
                    ],
                    'meta' => [
                        'requestId' => $requestId,
                        'appVersion' => APP_VERSION,
                    ],
                ],
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        }
    }
);

/**
 * ============================================================
 * HTTPメソッド
 * ============================================================
 */
$method = strtoupper(
    (string)(
        $_SERVER['REQUEST_METHOD']
        ?? 'GET'
    )
);

if (
    !in_array(
        $method,
        ['GET', 'POST'],
        true
    )
) {
    errorResponse(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405
    );
}

/**
 * ============================================================
 * 共通HTTPヘッダー
 * ============================================================
 */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

/**
 * ============================================================
 * セッション
 * ============================================================
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secureCookie = (
        isset($_SERVER['HTTPS'])
        && strtolower(
            (string)$_SERVER['HTTPS']
        ) !== 'off'
    );

    session_start(
        [
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => $secureCookie,
            'use_strict_mode' => true,
        ]
    );
}

/**
 * ============================================================
 * CSRFトークン生成
 * ============================================================
 */
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

/**
 * ============================================================
 * JSON Request Body
 * ============================================================
 *
 * php://inputは何度も直接読むのではなく、
 * 一度だけ読み込んで共通利用する。
 */
$requestJson = null;
$requestJsonLoaded = false;

function getJsonRequestBody(): array
{
    global $requestJson;
    global $requestJsonLoaded;

    if ($requestJsonLoaded) {
        return is_array($requestJson)
            ? $requestJson
            : [];
    }

    $requestJsonLoaded = true;

    $contentType = strtolower(
        (string)($_SERVER['CONTENT_TYPE'] ?? '')
    );

    if (
        !str_contains(
            $contentType,
            'application/json'
        )
    ) {
        $requestJson = [];
        return [];
    }

    $raw = file_get_contents(
        'php://input'
    );

    if (
        !is_string($raw)
        || trim($raw) === ''
    ) {
        $requestJson = [];
        return [];
    }

    $decoded = json_decode(
        $raw,
        true
    );

    if (!is_array($decoded)) {
        errorResponse(
            'INVALID_JSON',
            'JSONリクエストの形式が不正です。',
            400
        );
    }

    $requestJson = $decoded;

    return $decoded;
}

/**
 * ============================================================
 * CSRF取得
 * ============================================================
 */
function getRequestCsrfToken(): string
{
    /**
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

    /**
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

    /**
     * application/json
     */
    $json = getJsonRequestBody();

    if (
        isset($json['csrf_token'])
        && is_string($json['csrf_token'])
    ) {
        return $json['csrf_token'];
    }

    return '';
}

/**
 * ============================================================
 * CSRF検証
 * ============================================================
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

/**
 * ============================================================
 * action取得
 * ============================================================
 */
function getAction(): string
{
    global $method;

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        return is_string($action)
            ? trim($action)
            : '';
    }

    /**
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

    /**
     * POST JSON
     */
    $json = getJsonRequestBody();

    if (
        isset($json['action'])
        && is_string($json['action'])
    ) {
        return trim($json['action']);
    }

    return '';
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
 * action判定
 * ============================================================
 */
$action = getAction();

/**
 * GET
 */
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
}

/**
 * POST
 */
if ($method === 'POST') {
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

/**
 * ============================================================
 * GET API: health
 * ============================================================
 */
if (
    $method === 'GET'
    && $action === 'health'
) {
    successResponse(
        [
            'status' => 'ok',
            'method' => 'GET',
            'phpVersion' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'https' => (
                isset($_SERVER['HTTPS'])
                ? (string)$_SERVER['HTTPS']
                : ''
            ),
            'serverName' => (
                string)(
                    $_SERVER['SERVER_NAME']
                    ?? ''
                ),
            'serverPort' => (
                string)(
                    $_SERVER['SERVER_PORT']
                    ?? ''
                ),
            'requestUri' => (
                string)(
                    $_SERVER['REQUEST_URI']
                    ?? ''
                ),
            'scriptName' => (
                string)(
                    $_SERVER['SCRIPT_NAME']
                    ?? ''
                ),
            'documentRoot' => (
                string)(
                    $_SERVER['DOCUMENT_ROOT']
                    ?? ''
                ),
            'time' => date(DATE_ATOM),
        ],
        '通信成功'
    );
}

/**
 * ============================================================
 * GET API: csrf
 * ============================================================
 */
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
 * 画面表示
 * ============================================================
 *
 * actionなしGETだけが画面表示。
 */
if (
    $method === 'GET'
    && $action === ''
) {
    /**
     * ========================================================
     * API入口URL
     * ========================================================
     *
     * ここが今回の重要修正点。
     *
     * REQUEST_URIをそのままAPI入口として使用しない。
     *
     * SCRIPT_NAME:
     *   実際にApache/PHPが実行しているindex.phpのURLパス
     *
     * ブラウザ側ではさらに、
     *
     *   new URL('index.php', document.baseURI)
     *
     * によって現在のアプリケーションディレクトリを基準に
     * index.phpを解決する。
     *
     * これにより、
     *
     *   /gojacic/...
     *
     *   /別ディレクトリ/...
     *
     * のような配置差をJavaScriptへハードコードしない。
     */
    $scriptName =
        (string)(
            $_SERVER['SCRIPT_NAME']
            ?? ''
        );

    if ($scriptName === '') {
        $scriptName = 'index.php';
    }

    /**
     * SCRIPT_NAMEが絶対パスでなければ
     * ブラウザ側で相対解決できるようにする。
     */
    $entryPath = $scriptName;

    $entryPathJson = json_encode(
        $entryPath,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );

    if (
        !is_string($entryPathJson)
        || $entryPathJson === ''
    ) {
        $entryPathJson = '"index.php"';
    }

    /**
     * 現在のサーバー情報も診断画面へ渡す。
     */
    $serverDiagnostics = [
        'scriptName' => $scriptName,
        'requestUri' => (
            string)(
                $_SERVER['REQUEST_URI']
                ?? ''
            ),
        'serverName' => (
            string)(
                $_SERVER['SERVER_NAME']
                ?? ''
            ),
        'serverPort' => (
            string)(
                $_SERVER['SERVER_PORT']
                ?? ''
            ),
        'https' => (
            string)(
                $_SERVER['HTTPS']
                ?? ''
            ),
        'phpVersion' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'requestId' => $requestId,
    ];

    $serverDiagnosticsJson = json_encode(
        $serverDiagnostics,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if (
        !is_string($serverDiagnosticsJson)
    ) {
        $serverDiagnosticsJson = '{}';
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

<meta
    name="robots"
    content="noindex,nofollow"
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
}

body {
    background: #f4f6f8;
    color: #222;
    font-family:
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
    line-height: 1.6;
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
        0 2px 12px rgba(0, 0, 0, .07);
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
    padding: 11px 18px;
    background: #1769aa;
    color: #fff;
    cursor: pointer;
    font-size: 14px;
    min-height: 44px;
}

button:hover:not(:disabled) {
    background: #0e578d;
}

button.secondary {
    background: #555;
}

button.success {
    background: #218739;
}

button.danger {
    background: #b42318;
}

button:disabled {
    opacity: .55;
    cursor: wait;
}

.loading {
    display: none;
    margin-left: 8px;
    color: #1769aa;
    font-weight: 700;
}

.loading.active {
    display: inline-block;
}

.status {
    margin-top: 16px;
    padding: 14px;
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
    background: #fff8e6;
    color: #7a5200;
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
        Consolas,
        monospace;
    word-break: break-all;
}

.diagnostic {
    overflow-wrap: anywhere;
}

dl {
    display: grid;
    grid-template-columns:
        minmax(180px, max-content)
        1fr;
    gap: 8px 18px;
}

dt {
    font-weight: 700;
}

dd {
    margin: 0;
    overflow-wrap: anywhere;
}

pre {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Consolas,
        monospace;
    font-size: 13px;
}

details {
    margin-top: 14px;
}

summary {
    cursor: pointer;
    font-weight: 700;
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

}

</style>

</head>

<body>

<main>

<section class="card">

<h1>
    <?= h(APP_NAME) ?>
</h1>

<p>
    第1段階：単一入口・Apache/PHP通信基盤テスト
</p>

<p class="small">
    この画面は
    <code>index.php</code>
    から表示されています。
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
    CSRFトークンを取得してから
    POST test_post を実行します。
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

<dd
    id="diagnosticUrl"
    class="mono"
>
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
    Request ID
</dt>

<dd
    id="diagnosticRequestId"
    class="mono"
>
    -
</dd>

</dl>

<pre
    id="diagnosticDetail"
    class="diagnostic"
></pre>

</section>


<section class="card">

<h2>
    URL / History API テスト
</h2>

<div class="buttons">

<button
    type="button"
    id="screenAdminButton"
>
    screen=admin
</button>

<button
    type="button"
    id="screenSurveyButton"
>
    screen=survey
</button>

<button
    type="button"
    id="screenAnswerButton"
>
    screen=answer
</button>

<button
    type="button"
    id="replaceStateButton"
    class="secondary"
>
    replaceState
</button>

<button
    type="button"
    id="reloadButton"
    class="secondary"
>
    再読み込み
</button>

</div>

<div
    id="historyResult"
    class="status"
>
    未実行
</div>

</section>


<section class="card">

<h2>
    Apache / PHP 実行情報
</h2>

<pre
    id="serverDiagnostics"
></pre>

</section>


<section class="card">

<h2>
    重要な確認
</h2>

<div class="status warning">

この画面が表示されていても、
API通信が成功しているとは限りません。

特に、

GET API（health）

が

Failed to fetch

になる場合、

・HTTPS
・Apache VirtualHost
・証明書
・Apacheのリクエスト処理
・PHP実行
・URL解決

を切り分ける必要があります。

APIレスポンスが存在する場合は
HTTPステータスとJSON内容を表示します。

fetchそのものがブラウザで失敗した場合は、
HTTPレスポンスを取得できないため、
ブラウザのNetwork情報とApacheログの確認が必要です。

</div>

</section>

</main>


<script>
(() => {

    'use strict';


    /*
     * ============================================================
     * サーバー情報
     * ============================================================
     */

    const SERVER_DIAGNOSTICS =
        <?= $serverDiagnosticsJson ?>;


    /*
     * ============================================================
     * API入口
     * ============================================================
     *
     * 重要:
     *
     * REQUEST_URIからAPI URLを組み立てない。
     *
     * 現在のページURLを基準に
     * index.phpを解決する。
     *
     * 例:
     *
     * https://localhost/gojacic/.../アンケートアプリ/
     *
     * →
     *
     * https://localhost/gojacic/.../アンケートアプリ/index.php
     *
     * また、
     *
     * https://localhost/.../index.php?screen=survey
     *
     * の場合も
     *
     * https://localhost/.../index.php
     *
     * になる。
     */

    const SERVER_ENTRY_PATH =
        <?= $entryPathJson ?>;


    function resolveEntryUrl() {

        /*
         * SCRIPT_NAMEをサーバーから受け取っているが、
         * 同一originであることを確認する。
         */

        const currentUrl =
            new URL(
                window.location.href
            );

        let candidate;

        try {

            candidate =
                new URL(
                    SERVER_ENTRY_PATH,
                    currentUrl
                );

        } catch (error) {

            /*
             * 万一SCRIPT_NAMEをURLとして解決できない場合は、
             * 現在ページからindex.phpを相対解決する。
             */

            candidate =
                new URL(
                    'index.php',
                    currentUrl
                );
        }


        /*
         * 外部originへ飛ばさない。
         */

        if (
            candidate.origin
            !== currentUrl.origin
        ) {

            candidate =
                new URL(
                    'index.php',
                    currentUrl
                );

        }


        /*
         * API入口ではqueryを持たせない。
         */

        candidate.search = '';
        candidate.hash = '';

        return candidate;

    }


    const entryUrl =
        resolveEntryUrl();


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


    /*
     * ============================================================
     * DOM
     * ============================================================
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

    const diagnosticRequestId =
        document.getElementById(
            'diagnosticRequestId'
        );

    const diagnosticDetail =
        document.getElementById(
            'diagnosticDetail'
        );


    const historyResult =
        document.getElementById(
            'historyResult'
        );

    const serverDiagnostics =
        document.getElementById(
            'serverDiagnostics'
        );


    /*
     * ============================================================
     * 初期表示
     * ============================================================
     */

    serverDiagnostics.textContent =
        JSON.stringify(
            {
                ...SERVER_DIAGNOSTICS,
                browserUrl:
                    window.location.href,
                browserOrigin:
                    window.location.origin,
                resolvedEntryUrl:
                    entryUrl.toString(),
            },
            null,
            2
        );


    /*
     * ============================================================
     * 状態
     * ============================================================
     */

    let getProcessing = false;
    let postProcessing = false;

    let csrfToken = '';


    /*
     * ============================================================
     * ローディング
     * ============================================================
     */

    function setGetProcessing(
        value
    ) {

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
     * ============================================================
     * 診断
     * ============================================================
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

        diagnosticResponse.textContent =
            '未確定';

        diagnosticContentType.textContent =
            '取得中';

        diagnosticErrorCode.textContent =
            '-';

        diagnosticRequestId.textContent =
            '-';

        diagnosticDetail.textContent =
            '';

    }


    function setDiagnosticResponse(
        response
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
            ) || '(なし)';

    }


    function setDiagnosticSuccess(
        response,
        data
    ) {

        setDiagnosticResponse(
            response
        );

        diagnosticErrorCode.textContent =
            '-';

        diagnosticRequestId.textContent =
            data?.meta?.requestId
            || '-';

        diagnosticDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );

    }


    function setDiagnosticApiError(
        response,
        data
    ) {

        setDiagnosticResponse(
            response
        );

        diagnosticErrorCode.textContent =
            data?.error?.code
            || 'API_ERROR';

        diagnosticRequestId.textContent =
            data?.meta?.requestId
            || '-';

        diagnosticDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );

    }


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

        diagnosticRequestId.textContent =
            '-';

        let message =
            error instanceof Error
                ? error.message
                : String(error);

        diagnosticDetail.textContent =
            [
                'HTTPレスポンスを取得できていません。',
                '',
                'ブラウザ側エラー:',
                message,
                '',
                'API URL:',
                diagnosticUrl.textContent,
                '',
                '現在URL:',
                window.location.href,
                '',
                'Origin:',
                window.location.origin,
                '',
                '解決されたindex.php:',
                entryUrl.toString(),
                '',
                'この状態ではPHPのJSONエラーを',
                'ブラウザは取得できていません。',
                '',
                'Apache access.log / error.log と',
                'ブラウザDevTools Networkを確認してください。'
            ].join('\n');

    }


    /*
     * ============================================================
     * JSONレスポンス読込
     * ============================================================
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

        if (
            text.trim() === ''
        ) {

            throw new Error(
                [
                    'サーバーから空のレスポンスが返されました。',
                    'HTTP: ' + response.status,
                    'Content-Type: ' + (
                        contentType
                        || '(なし)'
                    )
                ].join('\n')
            );

        }


        let data;

        try {

            data =
                JSON.parse(
                    text
                );

        } catch (error) {

            throw new Error(
                [
                    'JSON解析に失敗しました。',
                    'HTTP: ' + response.status,
                    'Content-Type: ' + (
                        contentType
                        || '(なし)'
                    ),
                    '',
                    'レスポンス先頭:',
                    text.slice(
                        0,
                        1000
                    )
                ].join('\n')
            );

        }


        return {
            data,
            contentType,
            text
        };

    }


    /*
     * ============================================================
     * GET
     * ============================================================
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
                await fetch(
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
                            'no-store'
                    }
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
                    data
                );

                throw new Error(
                    data?.error?.message
                    || 'API処理に失敗しました。'
                );

            }


            setDiagnosticSuccess(
                response,
                data
            );


            return data;

        } catch (error) {

            /*
             * diagnosticStatusが
             * 「取得中」のままなら、
             * fetch自体がレスポンスを返していない。
             */

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
     * ============================================================
     * CSRF
     * ============================================================
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
                'APIは成功しましたが、CSRFトークンが返されませんでした。'
            );

        }


        csrfToken =
            token;


        return csrfToken;

    }


    /*
     * ============================================================
     * POST
     * ============================================================
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


        /*
         * CSRF未取得なら先に取得。
         */

        if (
            csrfToken === ''
        ) {

            try {

                await getCsrfToken();

            } catch (error) {

                /*
                 * getCsrfToken()が
                 * 独自に診断情報を書き換えるため、
                 * ここではそのまま再throw。
                 */

                throw error;

            }

        }


        const payload = {
            action,
            csrf_token:
                csrfToken,
            ...body
        };


        try {

            const response =
                await fetch(
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


            const data =
                parsed.data;


            if (
                !response.ok
                || data?.success !== true
            ) {

                setDiagnosticApiError(
                    response,
                    data
                );


                if (
                    data?.error?.code
                    === 'CSRF_INVALID'
                ) {

                    csrfToken = '';

                }


                throw new Error(
                    data?.error?.message
                    || 'POST API処理に失敗しました。'
                );

            }


            setDiagnosticSuccess(
                response,
                data
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
     * ============================================================
     * GET health
     * ============================================================
     */

    async function testHealth() {

        if (
            getProcessing
        ) {
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
                [
                    'GET API通信成功',
                    '',
                    JSON.stringify(
                        data,
                        null,
                        2
                    )
                ].join('\n');


        } catch (error) {

            getResult.className =
                'status error';

            getResult.textContent =
                [
                    'GET API通信失敗',
                    '',
                    error instanceof Error
                        ? error.message
                        : String(error)
                ].join('\n');

        } finally {

            setGetProcessing(
                false
            );

        }

    }


    /*
     * ============================================================
     * CSRFテスト
     * ============================================================
     */

    async function testCsrf() {

        if (
            getProcessing
        ) {
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
                [
                    'CSRFトークン取得成功',
                    '',
                    'トークン長: '
                    + token.length,
                    '',
                    'トークン本体は表示しません。'
                ].join('\n');


        } catch (error) {

            getResult.className =
                'status error';

            getResult.textContent =
                [
                    'CSRFトークン取得失敗',
                    '',
                    error instanceof Error
                        ? error.message
                        : String(error)
                ].join('\n');

        } finally {

            setGetProcessing(
                false
            );

        }

    }


    /*
     * ============================================================
     * POSTテスト
     * ============================================================
     */

    async function testPost() {

        if (
            postProcessing
        ) {
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
                [
                    'POST API通信成功',
                    '',
                    JSON.stringify(
                        data,
                        null,
                        2
                    )
                ].join('\n');


        } catch (error) {

            postResult.className =
                'status error';

            postResult.textContent =
                [
                    'POST API通信失敗',
                    '',
                    error instanceof Error
                        ? error.message
                        : String(error)
                ].join('\n');

        } finally {

            setPostProcessing(
                false
            );

        }

    }


    /*
     * ============================================================
     * URL / History API
     * ============================================================
     */

    function getScreenState() {

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
                ) || ''
        };

    }


    function showHistoryState() {

        historyResult.textContent =
            JSON.stringify(
                {
                    currentUrl:
                        window.location.href,

                    pathname:
                        window.location.pathname,

                    search:
                        window.location.search,

                    state:
                        getScreenState()
                },
                null,
                2
            );

    }


    function buildScreenUrl(
        screen,
        extra = {}
    ) {

        const url =
            new URL(
                window.location.href
            );

        url.search = '';

        url.searchParams.set(
            'screen',
            screen
        );


        Object.entries(
            extra
        ).forEach(
            (
                [
                    key,
                    value
                ]
            ) => {

                if (
                    value !== ''
                    && value !== null
                    && value !== undefined
                ) {

                    url.searchParams.set(
                        key,
                        String(value)
                    );

                }

            }
        );


        return url;

    }


    function pushScreen(
        screen,
        extra = {}
    ) {

        const url =
            buildScreenUrl(
                screen,
                extra
            );


        const state = {
            screen,
            surveyId:
                extra.surveyId
                || '',
            customerId:
                extra.customerId
                || '',
            questionId:
                extra.questionId
                || ''
        };


        history.pushState(
            state,
            '',
            url
        );


        showHistoryState();

    }


    function replaceScreen(
        screen,
        extra = {}
    ) {

        const url =
            buildScreenUrl(
                screen,
                extra
            );


        const state = {
            screen,
            surveyId:
                extra.surveyId
                || '',
            customerId:
                extra.customerId
                || '',
            questionId:
                extra.questionId
                || ''
        };


        history.replaceState(
            state,
            '',
            url
        );


        showHistoryState();

    }


    /*
     * popstate
     *
     * URLを正規情報として扱う。
     */

    window.addEventListener(
        'popstate',
        () => {

            showHistoryState();

        }
    );


    /*
     * ============================================================
     * イベント
     * ============================================================
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


    document
        .getElementById(
            'screenAdminButton'
        )
        .addEventListener(
            'click',
            () => {

                pushScreen(
                    'admin'
                );

            }
        );


    document
        .getElementById(
            'screenSurveyButton'
        )
        .addEventListener(
            'click',
            () => {

                pushScreen(
                    'survey',
                    {
                        surveyId:
                            'survey_demo'
                    }
                );

            }
        );


    document
        .getElementById(
            'screenAnswerButton'
        )
        .addEventListener(
            'click',
            () => {

                pushScreen(
                    'answer',
                    {
                        surveyId:
                            'survey_demo',
                        customerId:
                            'customer_demo'
                    }
                );

            }
        );


    document
        .getElementById(
            'replaceStateButton'
        )
        .addEventListener(
            'click',
            () => {

                replaceScreen(
                    'survey',
                    {
                        surveyId:
                            'survey_replaced'
                    }
                );

            }
        );


    document
        .getElementById(
            'reloadButton'
        )
        .addEventListener(
            'click',
            () => {

                window.location.reload();

            }
        );


    /*
     * ============================================================
     * 初期history表示
     * ============================================================
     */

    showHistoryState();


})();
</script>

</body>

</html>
<?php

    exit;
}


/**
 * ============================================================
 * 未実装action
 * ============================================================
 *
 * 第1段階では基盤APIのみ実装。
 * 第2段階以降は順番に実装する。
 */

errorResponse(
    'NOT_IMPLEMENTED',
    'この業務操作はまだ実装されていません。',
    501
);
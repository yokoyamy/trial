<?php
declare(strict_types=1);

/**
 * ============================================================
 * Minimal Single Entry PHP Application
 * ============================================================
 *
 * 目的:
 *   Apache24 + PHP 8.4/8.5 上で
 *   「index.php が正常に実行される」
 *   「GET API が JSON を返す」
 *   「POST API が JSON を返す」
 *   「CSRF が動作する」
 *   「fetch が現在の index.php を基準に通信する」
 *   ことだけを確認する。
 *
 * 業務ロジック:
 *   一切なし。
 *
 * 公開PHP:
 *   index.php のみ。
 *
 * ============================================================
 */


/* ============================================================
 * 1. 基本設定
 * ============================================================
 */

const APP_NAME = 'Minimal PHP Application';

date_default_timezone_set('Asia/Tokyo');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

error_reporting(E_ALL);


/* ============================================================
 * 2. Request ID
 * ============================================================
 */

function createRequestId(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable) {
        return uniqid('req_', true);
    }
}

$requestId = createRequestId();


/* ============================================================
 * 3. HTTPレスポンス共通処理
 * ============================================================
 */

function sendJson(
    int $status,
    array $body
): never {
    http_response_code($status);

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


function successResponse(
    mixed $data = [],
    string $message = ''
): never {
    sendJson(
        200,
        [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ]
    );
}


function errorResponse(
    int $status,
    string $code,
    string $message
): never {
    sendJson(
        $status,
        [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]
    );
}


/* ============================================================
 * 4. HTMLエスケープ
 * ============================================================
 */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/* ============================================================
 * 5. API判定
 * ============================================================
 *
 * API:
 *   action が存在する場合
 *
 * 画面:
 *   action が存在しない場合
 */

function isApiRequest(): bool
{
    return isset($_GET['action'])
        && trim((string)$_GET['action']) !== '';
}


/* ============================================================
 * 6. セッション
 * ============================================================
 */

function startApplicationSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_start([
        'use_strict_mode' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}


/* ============================================================
 * 7. CSRF
 * ============================================================
 */

function getCsrfToken(): string
{
    startApplicationSession();

    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || $_SESSION['csrf_token'] === ''
    ) {
        $_SESSION['csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['csrf_token'];
}


function validateCsrfToken(): void
{
    startApplicationSession();

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    $body = file_get_contents('php://input');

    $json = [];

    if (is_string($body) && trim($body) !== '') {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    $bodyToken = $json['csrfToken'] ?? '';

    $receivedToken = '';

    if (is_string($headerToken) && $headerToken !== '') {
        $receivedToken = $headerToken;
    } elseif (
        is_string($bodyToken)
        && $bodyToken !== ''
    ) {
        $receivedToken = $bodyToken;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($sessionToken)
        || $sessionToken === ''
        || $receivedToken === ''
        || !hash_equals(
            $sessionToken,
            $receivedToken
        )
    ) {
        errorResponse(
            403,
            'CSRF_INVALID',
            'CSRFトークンが不正です。'
        );
    }
}


/* ============================================================
 * 8. HTTPメソッド
 * ============================================================
 */

function getRequestMethod(): string
{
    return strtoupper(
        $_SERVER['REQUEST_METHOD'] ?? 'GET'
    );
}


function requireMethod(string $expected): void
{
    $actual = getRequestMethod();

    if ($actual !== strtoupper($expected)) {
        header(
            'Allow: ' . strtoupper($expected)
        );

        errorResponse(
            405,
            'METHOD_NOT_ALLOWED',
            '許可されていないHTTPメソッドです。'
        );
    }
}


/* ============================================================
 * 9. API action
 * ============================================================
 */

function getAction(): string
{
    return trim(
        (string)($_GET['action'] ?? '')
    );
}


/* ============================================================
 * 10. JSON Body
 * ============================================================
 */

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false) {
        errorResponse(
            400,
            'REQUEST_BODY_READ_FAILED',
            'リクエスト本文を読み込めません。'
        );
    }

    if (trim($raw) === '') {
        return [];
    }

    $data = json_decode(
        $raw,
        true
    );

    if (
        json_last_error() !== JSON_ERROR_NONE
        || !is_array($data)
    ) {
        errorResponse(
            400,
            'INVALID_JSON',
            'JSON形式が不正です。'
        );
    }

    return $data;
}


/* ============================================================
 * 11. 現在の index.php URL
 * ============================================================
 *
 * ここが重要。
 *
 * JavaScript側で
 *
 *   /api/xxx
 *   /xxx/index.php
 *   /アンケートアプリ/api/xxx
 *
 * 等をハードコードしない。
 *
 * PHP自身が現在のindex.php URLを生成し、
 * HTMLへ渡す。
 */

function getApplicationUrl(): string
{
    $https = (
        (!empty($_SERVER['HTTPS'])
            && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443')
    );

    $scheme = $https ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? 'localhost';

    $script = $_SERVER['SCRIPT_NAME']
        ?? '/index.php';

    /*
     * SCRIPT_NAME は現在実行中の index.php。
     *
     * 例:
     *
     * /gojacic/.poc/draft/アンケートアプリ/index.php
     *
     * をそのまま利用する。
     */

    return $scheme . '://' . $host . $script;
}


/* ============================================================
 * 12. 共通例外処理
 * ============================================================
 */

function registerExceptionHandler(
    string $requestId
): void {
    set_exception_handler(
        function (Throwable $exception) use ($requestId): void {

            error_log(
                sprintf(
                    '[%s] Unhandled exception: %s in %s:%d',
                    $requestId,
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine()
                )
            );

            /*
             * APIの場合
             */
            if (isApiRequest()) {
                errorResponse(
                    500,
                    'INTERNAL_ERROR',
                    'サーバー内部で予期しないエラーが発生しました。'
                );
            }

            /*
             * HTMLの場合
             */
            http_response_code(500);

            header(
                'Content-Type: text/html; charset=UTF-8'
            );

            echo renderServerErrorPage(
                $requestId
            );

            exit;
        }
    );
}


/* ============================================================
 * 13. Fatal Error処理
 * ============================================================
 */

function registerShutdownHandler(
    string $requestId
): void {
    register_shutdown_function(
        function () use ($requestId): void {

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

            if (
                !in_array(
                    $error['type'],
                    $fatalTypes,
                    true
                )
            ) {
                return;
            }

            error_log(
                sprintf(
                    '[%s] Fatal error: %s in %s:%d',
                    $requestId,
                    $error['message'] ?? '',
                    $error['file'] ?? '',
                    $error['line'] ?? 0
                )
            );
        }
    );
}


/* ============================================================
 * 14. エラー画面
 * ============================================================
 */

function renderServerErrorPage(
    string $requestId
): string {
    $safeRequestId = e($requestId);

    return <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>サーバーエラー</title>
<style>
body {
    font-family:
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
    background: #f5f6f8;
    color: #222;
    margin: 0;
    padding: 40px 20px;
}

.container {
    max-width: 760px;
    margin: 0 auto;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 28px;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
}

h1 {
    margin-top: 0;
}

.request-id {
    background: #f1f3f5;
    padding: 12px;
    border-radius: 6px;
    font-family: monospace;
    word-break: break-all;
}
</style>
</head>
<body>
<div class="container">
<div class="card">
<h1>サーバー内部エラー</h1>

<p>
サーバー内部で予期しないエラーが発生しました。
</p>

<p>Request ID:</p>

<div class="request-id">
{$safeRequestId}
</div>

<p>
サーバーログでこのRequest IDを検索してください。
</p>
</div>
</div>
</body>
</html>
HTML;
}


/* ============================================================
 * 15. API処理
 * ============================================================
 */

function handleApiRequest(): never
{
    $action = getAction();

    /*
     * actionが存在しない
     */
    if ($action === '') {
        errorResponse(
            400,
            'ACTION_REQUIRED',
            'actionを指定してください。'
        );
    }


    /*
     * --------------------------------------------------------
     * health
     * --------------------------------------------------------
     *
     * GET:
     *
     * index.php?action=health
     *
     */

    if ($action === 'health') {

        requireMethod('GET');

        successResponse(
            [
                'status' => 'ok',
                'phpVersion' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'requestMethod' => getRequestMethod(),
                'requestUri' => $_SERVER['REQUEST_URI'] ?? '',
                'scriptName' => $_SERVER['SCRIPT_NAME'] ?? '',
                'https' => (
                    !empty($_SERVER['HTTPS'])
                    && strtolower((string)$_SERVER['HTTPS']) !== 'off'
                ),
                'serverName' => $_SERVER['SERVER_NAME'] ?? '',
                'serverPort' => $_SERVER['SERVER_PORT'] ?? '',
            ],
            'APIは正常に動作しています。'
        );
    }


    /*
     * --------------------------------------------------------
     * csrf
     * --------------------------------------------------------
     *
     * GET:
     *
     * index.php?action=csrf
     *
     */

    if ($action === 'csrf') {

        requireMethod('GET');

        successResponse(
            [
                'csrfToken' => getCsrfToken(),
            ],
            'CSRFトークンを取得しました。'
        );
    }


    /*
     * --------------------------------------------------------
     * echo
     * --------------------------------------------------------
     *
     * POST:
     *
     * index.php?action=echo
     *
     */

    if ($action === 'echo') {

        requireMethod('POST');

        validateCsrfToken();

        $data = readJsonBody();

        successResponse(
            [
                'received' => $data,
                'serverTime' => date(
                    DATE_ATOM
                ),
            ],
            'POST通信は正常に完了しました。'
        );
    }


    /*
     * --------------------------------------------------------
     * phpinfo-lite
     * --------------------------------------------------------
     *
     * 開発確認専用。
     *
     * 認証情報等は表示しない。
     */

    if ($action === 'diagnostic') {

        requireMethod('GET');

        successResponse(
            [
                'app' => APP_NAME,
                'phpVersion' => PHP_VERSION,
                'phpMajor' => PHP_MAJOR_VERSION,
                'phpMinor' => PHP_MINOR_VERSION,
                'phpRelease' => PHP_RELEASE_VERSION,
                'sapi' => PHP_SAPI,

                'scriptFilename' =>
                    $_SERVER['SCRIPT_FILENAME'] ?? '',

                'scriptName' =>
                    $_SERVER['SCRIPT_NAME'] ?? '',

                'requestUri' =>
                    $_SERVER['REQUEST_URI'] ?? '',

                'documentRoot' =>
                    $_SERVER['DOCUMENT_ROOT'] ?? '',

                'serverName' =>
                    $_SERVER['SERVER_NAME'] ?? '',

                'serverPort' =>
                    $_SERVER['SERVER_PORT'] ?? '',

                'https' =>
                    $_SERVER['HTTPS'] ?? '',

                'remoteAddr' =>
                    $_SERVER['REMOTE_ADDR'] ?? '',

                'contentType' =>
                    $_SERVER['CONTENT_TYPE'] ?? '',

                'applicationUrl' =>
                    getApplicationUrl(),
            ],
            '診断情報を取得しました。'
        );
    }


    /*
     * --------------------------------------------------------
     * 未知action
     * --------------------------------------------------------
     */

    errorResponse(
        404,
        'UNKNOWN_ACTION',
        '指定されたactionは存在しません。'
    );
}


/* ============================================================
 * 16. 画面
 * ============================================================
 */

function renderPage(
    string $requestId
): string {

    $applicationUrl = e(
        getApplicationUrl()
    );

    $csrfEndpoint = e(
        getApplicationUrl()
        . '?action=csrf'
    );

    $healthEndpoint = e(
        getApplicationUrl()
        . '?action=health'
    );

    $echoEndpoint = e(
        getApplicationUrl()
        . '?action=echo'
    );

    $diagnosticEndpoint = e(
        getApplicationUrl()
        . '?action=diagnostic'
    );

    $safeRequestId = e($requestId);

    return <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>Minimal PHP Application</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f4f6f8;
    color: #202124;
    font-family:
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

header {
    background: #17202a;
    color: white;
    padding: 20px;
}

header h1 {
    margin: 0;
    font-size: 22px;
}

main {
    max-width: 1100px;
    margin: 0 auto;
    padding: 20px;
}

.card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow:
        0 2px 10px rgba(0,0,0,.06);
}

h2 {
    margin-top: 0;
    font-size: 18px;
}

.endpoint {
    padding: 10px;
    background: #f1f3f4;
    border-radius: 6px;
    font-family: monospace;
    word-break: break-all;
}

button {
    appearance: none;
    border: 0;
    border-radius: 7px;
    background: #1967d2;
    color: white;
    padding: 10px 16px;
    cursor: pointer;
    margin-right: 8px;
    margin-bottom: 8px;
}

button:hover {
    background: #1557b0;
}

button:disabled {
    background: #9aa0a6;
    cursor: not-allowed;
}

pre {
    background: #111827;
    color: #d1fae5;
    padding: 15px;
    border-radius: 7px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
}

.status {
    padding: 12px;
    border-radius: 7px;
    background: #eef2ff;
    margin-top: 10px;
}

.success {
    background: #ecfdf5;
    color: #065f46;
}

.error {
    background: #fef2f2;
    color: #991b1b;
}

.warning {
    background: #fffbeb;
    color: #92400e;
}

.grid {
    display: grid;
    grid-template-columns:
        repeat(auto-fit,minmax(300px,1fr));
    gap: 20px;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    text-align: left;
    padding: 8px;
    border-bottom: 1px solid #ddd;
    vertical-align: top;
}

th {
    width: 220px;
    background: #fafafa;
}

@media(max-width:600px) {

    main {
        padding: 12px;
    }

    .card {
        padding: 15px;
    }

    button {
        width: 100%;
        margin-right: 0;
    }

    th {
        width: 40%;
    }
}

</style>
</head>

<body>

<header>
    <h1>Minimal PHP Application</h1>
</header>

<main>

<div class="card">

<h2>現在の入口</h2>

<div class="endpoint" id="application-url">
{$applicationUrl}
</div>

<p>
この画面自身が実行されている
<strong>index.php</strong>
をAPI入口として使用します。
</p>

</div>


<div class="grid">

<div class="card">

<h2>1. GET health</h2>

<div class="endpoint">
{$healthEndpoint}
</div>

<br>

<button
    type="button"
    id="health-button"
>
GET APIテスト
</button>

</div>


<div class="card">

<h2>2. CSRF</h2>

<div class="endpoint">
{$csrfEndpoint}
</div>

<br>

<button
    type="button"
    id="csrf-button"
>
CSRF取得
</button>

<div
    class="status"
    id="csrf-status"
>
未取得
</div>

</div>


<div class="card">

<h2>3. POST echo</h2>

<div class="endpoint">
{$echoEndpoint}
</div>

<br>

<button
    type="button"
    id="post-button"
>
POST APIテスト
</button>

</div>


<div class="card">

<h2>4. PHP診断</h2>

<div class="endpoint">
{$diagnosticEndpoint}
</div>

<br>

<button
    type="button"
    id="diagnostic-button"
>
PHP環境診断
</button>

</div>

</div>


<div class="card">

<h2>通信結果</h2>

<div
    id="result-status"
    class="status"
>
未実行
</div>

<pre id="result">-</pre>

</div>


<div class="card">

<h2>通信診断</h2>

<table>

<tr>
<th>API URL</th>
<td id="diag-url">-</td>
</tr>

<tr>
<th>HTTPメソッド</th>
<td id="diag-method">-</td>
</tr>

<tr>
<th>HTTPステータス</th>
<td id="diag-status">-</td>
</tr>

<tr>
<th>レスポンス有無</th>
<td id="diag-response">-</td>
</tr>

<tr>
<th>Content-Type</th>
<td id="diag-content-type">-</td>
</tr>

<tr>
<th>JSON解析</th>
<td id="diag-json">-</td>
</tr>

<tr>
<th>エラーコード</th>
<td id="diag-error-code">-</td>
</tr>

<tr>
<th>エラー内容</th>
<td id="diag-error-message">-</td>
</tr>

</table>

</div>


<div class="card">

<h2>URL / History API テスト</h2>

<button
    type="button"
    id="admin-button"
>
screen=admin
</button>

<button
    type="button"
    id="survey-button"
>
screen=survey
</button>

<button
    type="button"
    id="answer-button"
>
screen=answer
</button>

<button
    type="button"
    id="replace-button"
>
replaceState
</button>

<pre id="history-result">-</pre>

</div>


<div class="card">

<h2>Request ID</h2>

<div class="endpoint">
{$safeRequestId}
</div>

</div>

</main>


<script>

/* ============================================================
 * 1. サーバーから渡された情報
 * ============================================================
 */

const APP_URL =
    {$thisJsonApplicationUrl};

const HEALTH_URL =
    {$thisJsonHealthUrl};

const CSRF_URL =
    {$thisJsonCsrfUrl};

const ECHO_URL =
    {$thisJsonEchoUrl};

const DIAGNOSTIC_URL =
    {$thisJsonDiagnosticUrl};


/* ============================================================
 * 2. 状態
 * ============================================================
 */

let csrfToken = '';

let requestRunning = false;


/* ============================================================
 * 3. DOM
 * ============================================================
 */

const resultStatus =
    document.getElementById('result-status');

const result =
    document.getElementById('result');


/* ============================================================
 * 4. 画面表示
 * ============================================================
 */

function setStatus(
    text,
    type = ''
) {
    resultStatus.textContent = text;

    resultStatus.className =
        'status ' + type;
}


function showResult(
    value
) {
    result.textContent =
        typeof value === 'string'
            ? value
            : JSON.stringify(
                value,
                null,
                2
            );
}


/* ============================================================
 * 5. 通信診断
 * ============================================================
 */

function resetDiagnostics(
    url,
    method
) {
    document.getElementById(
        'diag-url'
    ).textContent = url;

    document.getElementById(
        'diag-method'
    ).textContent = method;

    document.getElementById(
        'diag-status'
    ).textContent = '-';

    document.getElementById(
        'diag-response'
    ).textContent = '-';

    document.getElementById(
        'diag-content-type'
    ).textContent = '-';

    document.getElementById(
        'diag-json'
    ).textContent = '-';

    document.getElementById(
        'diag-error-code'
    ).textContent = '-';

    document.getElementById(
        'diag-error-message'
    ).textContent = '-';
}


function updateDiagnostic(
    response,
    contentType
) {
    document.getElementById(
        'diag-status'
    ).textContent =
        String(response.status);

    document.getElementById(
        'diag-response'
    ).textContent =
        response.ok
            ? 'あり'
            : 'HTTPエラー';

    document.getElementById(
        'diag-content-type'
    ).textContent =
        contentType || '(なし)';
}


function updateErrorDiagnostic(
    code,
    message
) {
    document.getElementById(
        'diag-error-code'
    ).textContent =
        code || '-';

    document.getElementById(
        'diag-error-message'
    ).textContent =
        message || '-';
}


/* ============================================================
 * 6. 共通fetch
 * ============================================================
 */

async function apiFetch(
    url,
    options = {}
) {

    if (requestRunning) {
        throw new Error(
            '現在、別の通信を実行中です。'
        );
    }

    requestRunning = true;

    resetDiagnostics(
        url,
        options.method || 'GET'
    );

    setStatus(
        '通信中...',
        'warning'
    );

    try {

        const controller =
            new AbortController();

        const timeoutId =
            setTimeout(
                () => controller.abort(),
                10000
            );


        const response =
            await fetch(
                url,
                {
                    ...options,

                    signal:
                        controller.signal,

                    credentials:
                        'same-origin',

                    headers: {
                        'Accept':
                            'application/json',

                        ...(options.headers || {}),
                    },
                }
            );


        clearTimeout(timeoutId);


        const contentType =
            response.headers.get(
                'content-type'
            ) || '';


        updateDiagnostic(
            response,
            contentType
        );


        const text =
            await response.text();


        if (text === '') {

            document.getElementById(
                'diag-json'
            ).textContent =
                'レスポンスなし';

            throw new Error(
                'HTTPレスポンスが空です。'
            );
        }


        let data;

        try {

            data =
                JSON.parse(text);

            document.getElementById(
                'diag-json'
            ).textContent =
                '成功';

        } catch (error) {

            document.getElementById(
                'diag-json'
            ).textContent =
                '失敗';

            showResult(
                text
            );

            throw new Error(
                'JSONとして解析できませんでした。'
                + '\\n'
                + 'Content-Type: '
                + contentType
            );
        }


        showResult(
            data
        );


        if (!response.ok) {

            const code =
                data?.error?.code
                || 'HTTP_ERROR';

            const message =
                data?.error?.message
                || 'HTTPエラーが発生しました。';

            updateErrorDiagnostic(
                code,
                message
            );

            setStatus(
                'APIエラー: ' + code,
                'error'
            );

            throw new Error(
                message
            );
        }


        if (data?.success !== true) {

            const code =
                data?.error?.code
                || 'API_ERROR';

            const message =
                data?.error?.message
                || 'APIエラーです。';

            updateErrorDiagnostic(
                code,
                message
            );

            setStatus(
                'APIエラー: ' + code,
                'error'
            );

            throw new Error(
                message
            );
        }


        updateErrorDiagnostic(
            '',
            ''
        );


        setStatus(
            data.message
                || '通信成功',
            'success'
        );


        return data;

    } catch (error) {

        if (
            error?.name ===
            'AbortError'
        ) {

            document.getElementById(
                'diag-error-code'
            ).textContent =
                'TIMEOUT';

            document.getElementById(
                'diag-error-message'
            ).textContent =
                '10秒以内に応答がありませんでした。';

            setStatus(
                'タイムアウト',
                'error'
            );

            showResult(
                'タイムアウトしました。'
            );

            throw error;
        }


        /*
         * fetch自体が失敗した場合。
         *
         * ここでは
         * HTTPステータスは存在しない。
         */

        if (
            error instanceof TypeError
        ) {

            document.getElementById(
                'diag-status'
            ).textContent =
                '取得できませんでした';

            document.getElementById(
                'diag-response'
            ).textContent =
                'なし';

            document.getElementById(
                'diag-error-code'
            ).textContent =
                'NETWORK_ERROR';

            document.getElementById(
                'diag-error-message'
            ).textContent =
                error.message;

            setStatus(
                'ネットワーク通信に失敗しました。',
                'error'
            );

            showResult(
                'Failed to fetch\\n\\n'
                + 'URL: '
                + url
                + '\\n\\n'
                + 'ブラウザがHTTPレスポンスを取得できていません。'
                + '\\n'
                + 'HTTPS証明書、Apache、VirtualHost、'
                + 'ポート、DevTools Networkを確認してください。'
            );

        } else {

            document.getElementById(
                'diag-error-code'
            ).textContent =
                'CLIENT_ERROR';

            document.getElementById(
                'diag-error-message'
            ).textContent =
                error?.message
                || String(error);

            setStatus(
                '通信処理でエラーが発生しました。',
                'error'
            );

            showResult(
                error?.message
                || String(error)
            );
        }


        throw error;

    } finally {

        requestRunning = false;
    }
}


/* ============================================================
 * 7. GET health
 * ============================================================
 */

document
    .getElementById('health-button')
    .addEventListener(
        'click',
        async function() {

            try {

                await apiFetch(
                    HEALTH_URL,
                    {
                        method: 'GET',
                    }
                );

            } catch (error) {

                console.error(
                    error
                );
            }
        }
    );


/* ============================================================
 * 8. CSRF取得
 * ============================================================
 */

document
    .getElementById('csrf-button')
    .addEventListener(
        'click',
        async function() {

            const status =
                document.getElementById(
                    'csrf-status'
                );

            try {

                const data =
                    await apiFetch(
                        CSRF_URL,
                        {
                            method: 'GET',
                        }
                    );

                csrfToken =
                    data.data.csrfToken
                    || '';

                status.textContent =
                    csrfToken
                        ? '取得済み'
                        : '取得失敗';

            } catch (error) {

                csrfToken = '';

                status.textContent =
                    '取得失敗';

                console.error(
                    error
                );
            }
        }
    );


/* ============================================================
 * 9. POST echo
 * ============================================================
 */

document
    .getElementById('post-button')
    .addEventListener(
        'click',
        async function() {

            try {

                if (!csrfToken) {

                    const csrf =
                        await apiFetch(
                            CSRF_URL,
                            {
                                method: 'GET',
                            }
                        );

                    csrfToken =
                        csrf.data.csrfToken
                        || '';
                }


                if (!csrfToken) {

                    throw new Error(
                        'CSRFトークンを取得できませんでした。'
                    );
                }


                await apiFetch(
                    ECHO_URL,
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/json',

                            'X-CSRF-Token':
                                csrfToken,
                        },

                        body:
                            JSON.stringify({
                                message:
                                    'hello',

                                number:
                                    123,

                                timestamp:
                                    new Date()
                                        .toISOString(),
                            }),
                    }
                );

            } catch (error) {

                console.error(
                    error
                );
            }
        }
    );


/* ============================================================
 * 10. PHP診断
 * ============================================================
 */

document
    .getElementById(
        'diagnostic-button'
    )
    .addEventListener(
        'click',
        async function() {

            try {

                await apiFetch(
                    DIAGNOSTIC_URL,
                    {
                        method: 'GET',
                    }
                );

            } catch (error) {

                console.error(
                    error
                );
            }
        }
    );


/* ============================================================
 * 11. URL状態
 * ============================================================
 */

function getScreenStateFromUrl() {

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
            || '',

        customerId:
            params.get('customerId')
            || '',

        questionId:
            params.get('questionId')
            || '',
    };
}


function renderUrlState() {

    const state =
        getScreenStateFromUrl();

    document.getElementById(
        'history-result'
    ).textContent =
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
    state,
    replace = false
) {

    const params =
        new URLSearchParams();


    if (state.screen) {

        params.set(
            'screen',
            state.screen
        );
    }


    if (state.surveyId) {

        params.set(
            'surveyId',
            state.surveyId
        );
    }


    if (state.customerId) {

        params.set(
            'customerId',
            state.customerId
        );
    }


    if (state.questionId) {

        params.set(
            'questionId',
            state.questionId
        );
    }


    const url =
        window.location.pathname
        + (
            params.toString()
                ? '?' + params.toString()
                : ''
        );


    if (replace) {

        window.history.replaceState(
            state,
            '',
            url
        );

    } else {

        window.history.pushState(
            state,
            '',
            url
        );
    }


    renderUrlState();
}


/* ============================================================
 * 12. History API
 * ============================================================
 */

document
    .getElementById('admin-button')
    .addEventListener(
        'click',
        function() {

            updateUrl({
                screen: 'admin',
            });
        }
    );


document
    .getElementById('survey-button')
    .addEventListener(
        'click',
        function() {

            updateUrl({
                screen: 'survey',

                surveyId:
                    'survey_demo',
            });
        }
    );


document
    .getElementById('answer-button')
    .addEventListener(
        'click',
        function() {

            updateUrl({
                screen: 'answer',

                surveyId:
                    'survey_demo',

                customerId:
                    'customer_demo',
            });
        }
    );


document
    .getElementById('replace-button')
    .addEventListener(
        'click',
        function() {

            updateUrl(
                {
                    screen: 'survey',

                    surveyId:
                        'survey_replaced',
                },
                true
            );
        }
    );


window.addEventListener(
    'popstate',
    function() {

        renderUrlState();
    }
);


/* ============================================================
 * 13. 初期URL状態
 * ============================================================
 */

renderUrlState();

</script>

</body>
</html>
HTML;
}


/* ============================================================
 * 17. JavaScriptへ渡すJSON
 * ============================================================
 *
 * renderPage()内で使用するための補助。
 *
 * HTML文字列へ直接JS文字列を埋め込まず、
 * json_encode()を使用する。
 */

function jsString(
    string $value
): string {
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ) ?: '""';
}


/* ============================================================
 * 18. 画面描画用関数をラップ
 * ============================================================
 */

function renderApplicationPage(
    string $requestId
): string {

    $html =
        renderPageTemplate(
            $requestId
        );

    return $html;
}


/* ============================================================
 * 19. 実際のHTML生成
 * ============================================================
 *
 * 上記renderPage()では
 * JavaScriptの定数をPHPから安全に渡す必要があるため、
 * テンプレートをここで生成する。
 */

function renderPageTemplate(
    string $requestId
): string {

    $applicationUrl =
        getApplicationUrl();

    $healthUrl =
        $applicationUrl
        . '?action=health';

    $csrfUrl =
        $applicationUrl
        . '?action=csrf';

    $echoUrl =
        $applicationUrl
        . '?action=echo';

    $diagnosticUrl =
        $applicationUrl
        . '?action=diagnostic';


    $appUrlJs =
        jsString($applicationUrl);

    $healthUrlJs =
        jsString($healthUrl);

    $csrfUrlJs =
        jsString($csrfUrl);

    $echoUrlJs =
        jsString($echoUrl);

    $diagnosticUrlJs =
        jsString($diagnosticUrl);


    /*
     * renderPage() のHTMLテンプレートを
     * そのまま利用しつつ、
     * プレースホルダを置換する。
     */

    $html =
        renderPage(
            $requestId
        );


    $html =
        str_replace(
            '{$thisJsonApplicationUrl}',
            $appUrlJs,
            $html
        );

    $html =
        str_replace(
            '{$thisJsonHealthUrl}',
            $healthUrlJs,
            $html
        );

    $html =
        str_replace(
            '{$thisJsonCsrfUrl}',
            $csrfUrlJs,
            $html
        );

    $html =
        str_replace(
            '{$thisJsonEchoUrl}',
            $echoUrlJs,
            $html
        );

    $html =
        str_replace(
            '{$thisJsonDiagnosticUrl}',
            $diagnosticUrlJs,
            $html
        );


    return $html;
}


/* ============================================================
 * 20. 起動処理
 * ============================================================
 *
 * ここがアプリケーションの唯一の入口。
 *
 * 処理順序:
 *
 *   1. Request ID
 *   2. Exception handler
 *   3. Shutdown handler
 *   4. session
 *   5. API / HTML 判定
 *   6. API処理またはHTML表示
 *
 * ============================================================
 */

registerExceptionHandler(
    $requestId
);

registerShutdownHandler(
    $requestId
);


/*
 * セッションを共通入口で初期化。
 *
 * APIでもCSRFを使用するため、
 * API処理より前に開始する。
 */

try {

    startApplicationSession();

} catch (Throwable $exception) {

    error_log(
        sprintf(
            '[%s] Session initialization failed: %s',
            $requestId,
            $exception->getMessage()
        )
    );

    if (isApiRequest()) {

        errorResponse(
            500,
            'SESSION_INIT_FAILED',
            'セッションを初期化できませんでした。'
        );
    }

    http_response_code(500);

    header(
        'Content-Type: text/html; charset=UTF-8'
    );

    echo renderServerErrorPage(
        $requestId
    );

    exit;
}


/*
 * API
 */

if (isApiRequest()) {

    handleApiRequest();
}


/*
 * HTML
 */

http_response_code(200);

header(
    'Content-Type: text/html; charset=UTF-8'
);

header(
    'Cache-Control: no-store'
);

echo renderApplicationPage(
    $requestId
);

exit;
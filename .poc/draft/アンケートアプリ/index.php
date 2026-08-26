<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 *
 * 基盤確認版
 *
 * 必須条件:
 * - Apache24
 * - PHP 8.4 / 8.5
 * - DBなし
 * - 単一入口 index.php
 * - GET = 参照
 * - POST = 変更
 * - POST = CSRF必須
 * - API = JSON
 * - 画面状態 = query string
 */

const APP_TIMEZONE = 'Asia/Tokyo';

date_default_timezone_set(APP_TIMEZONE);

/* =========================================================
 * HTTPヘッダー
 * ========================================================= */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* =========================================================
 * 共通JSONレスポンス
 * ========================================================= */

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
 * API判定
 * ========================================================= */

function isApiRequest(): bool
{
    if (isset($_GET['action'])) {
        return true;
    }

    if (isset($_POST['action'])) {
        return true;
    }

    $contentType = strtolower(
        $_SERVER['CONTENT_TYPE'] ?? ''
    );

    return str_contains(
        $contentType,
        'application/json'
    );
}

/* =========================================================
 * 例外処理
 * ========================================================= */

set_exception_handler(
    function (Throwable $e): void {
        error_log(
            '[APP_EXCEPTION] '
            . get_class($e)
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

        http_response_code(500);

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
    $_SERVER['REQUEST_METHOD'] ?? 'GET'
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
    $_SESSION['csrf_token'] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* =========================================================
 * リクエストJSON
 * ========================================================= */

function readJsonBody(): array
{
    $contentType = strtolower(
        $_SERVER['CONTENT_TYPE'] ?? ''
    );

    if (!str_contains(
        $contentType,
        'application/json'
    )) {
        return [];
    }

    $raw = file_get_contents('php://input');

    if (!is_string($raw)) {
        return [];
    }

    if (trim($raw) === '') {
        return [];
    }

    $data = json_decode(
        $raw,
        true
    );

    return is_array($data)
        ? $data
        : [];
}

/* =========================================================
 * action取得
 * ========================================================= */

function getAction(): string
{
    $method = strtoupper(
        $_SERVER['REQUEST_METHOD'] ?? 'GET'
    );

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        return is_string($action)
            ? trim($action)
            : '';
    }

    $action = $_POST['action'] ?? '';

    if (
        is_string($action)
        && trim($action) !== ''
    ) {
        return trim($action);
    }

    $json = readJsonBody();

    if (
        isset($json['action'])
        && is_string($json['action'])
    ) {
        return trim($json['action']);
    }

    return '';
}

/* =========================================================
 * CSRF取得
 * ========================================================= */

function getRequestCsrfToken(): string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        is_string($header)
        && $header !== ''
    ) {
        return $header;
    }

    $post = $_POST['csrf_token'] ?? '';

    if (
        is_string($post)
        && $post !== ''
    ) {
        return $post;
    }

    $json = readJsonBody();

    if (
        isset($json['csrf_token'])
        && is_string($json['csrf_token'])
    ) {
        return $json['csrf_token'];
    }

    return '';
}

/* =========================================================
 * CSRF検証
 * ========================================================= */

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

/* =========================================================
 * action定義
 * ========================================================= */

$allowedGetActions = [
    '',
    'health',
    'csrf',
];

$allowedPostActions = [
    'test_post',
];

/* =========================================================
 * action
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
}

if ($method === 'POST') {
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

    validateCsrfToken();
}

/* =========================================================
 * GET: health
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
        ],
        '通信成功'
    );
}

/* =========================================================
 * GET: csrf
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
 * POST: test_post
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
 * 画面
 * ========================================================= */

if (
    $method === 'GET'
    && $action === ''
):
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
    width: min(960px, 100%);
    margin: 0 auto;
    padding: 24px;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow:
        0 2px 10px rgba(0,0,0,.06);
}

h1,
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
    margin-top: 10px;
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

dl {
    display: grid;
    grid-template-columns:
        minmax(130px,max-content)
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

<h1>アンケート管理システム</h1>

<p>
単一入口
<code>index.php</code>
基盤通信テスト
</p>

<p class="small">
現在のブラウザURL:
<span id="currentUrl"></span>
</p>

<p class="small">
API入口:
<span id="apiEntry"></span>
</p>

</section>

<section class="card">

<h2>GET API</h2>

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
>
処理中…
</span>

<div
    id="getResult"
    class="status"
></div>

</section>

<section class="card">

<h2>POST API</h2>

<p class="small">
CSRFトークンを未取得でも、
POST実行時に自動取得します。
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
>
処理中…
</span>

<div
    id="postResult"
    class="status"
></div>

</section>

<section class="card">

<h2>通信診断</h2>

<dl>

<dt>API URL</dt>
<dd id="diagnosticUrl">-</dd>

<dt>HTTPメソッド</dt>
<dd id="diagnosticMethod">-</dd>

<dt>HTTPステータス</dt>
<dd id="diagnosticStatus">-</dd>

<dt>Content-Type</dt>
<dd id="diagnosticContentType">-</dd>

<dt>APIエラーコード</dt>
<dd id="diagnosticErrorCode">-</dd>

</dl>

<pre id="diagnosticDetail"></pre>

</section>

</main>

<script>
(() => {

'use strict';

/*
 * =========================================================
 * API入口
 *
 * 重要:
 *
 * PHPからREQUEST_URIを渡さない。
 *
 * 現在ブラウザが表示しているURLを基準にする。
 *
 * redirect指定もしない。
 * =========================================================
 */

function getEntryUrl() {

    const url =
        new URL(
            window.location.href
        );

    /*
     * 現在ページのquery stringを除去。
     *
     * screen等の画面状態があっても
     * API actionだけを設定する。
     */

    url.search = '';

    url.hash = '';

    return url;
}

/*
 * =========================================================
 * API URL
 * =========================================================
 */

function buildApiUrl(action) {

    const url =
        getEntryUrl();

    url.searchParams.set(
        'action',
        action
    );

    return url.toString();
}

/*
 * =========================================================
 * DOM
 * =========================================================
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

const currentUrl =
    document.getElementById(
        'currentUrl'
    );

const apiEntry =
    document.getElementById(
        'apiEntry'
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

const diagnosticDetail =
    document.getElementById(
        'diagnosticDetail'
    );

/*
 * =========================================================
 * 初期表示
 * =========================================================
 */

currentUrl.textContent =
    window.location.href;

apiEntry.textContent =
    getEntryUrl().toString();

/*
 * =========================================================
 * 状態
 * =========================================================
 */

let getProcessing = false;
let postProcessing = false;

let csrfToken = '';

/*
 * =========================================================
 * ローディング
 * =========================================================
 */

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

/*
 * =========================================================
 * 診断
 * =========================================================
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

    diagnosticDetail.textContent =
        '';
}

function setDiagnosticSuccess(
    response,
    data
) {

    diagnosticStatus.textContent =
        String(response.status);

    diagnosticContentType.textContent =
        response.headers.get(
            'content-type'
        ) || '(なし)';

    diagnosticErrorCode.textContent =
        '-';

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

    diagnosticStatus.textContent =
        String(response.status);

    diagnosticContentType.textContent =
        response.headers.get(
            'content-type'
        ) || '(なし)';

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

function setDiagnosticNetworkError(
    error
) {

    diagnosticStatus.textContent =
        '取得できませんでした';

    diagnosticContentType.textContent =
        '取得できませんでした';

    diagnosticErrorCode.textContent =
        'NETWORK_ERROR';

    diagnosticDetail.textContent =
        error instanceof Error
            ? error.message
            : String(error);
}

/*
 * =========================================================
 * JSON読み込み
 * =========================================================
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
            + text.slice(0, 500)
        );
    }

    return data;
}

/*
 * =========================================================
 * GET
 *
 * ここでは credentials も redirect も指定しない。
 *
 * GETのhealth/csrfは同一URLへの単純なGETとする。
 * =========================================================
 */

async function requestGet(
    action
) {

    const url =
        buildApiUrl(action);

    resetDiagnostic(
        url,
        'GET'
    );

    let response;

    try {

        response =
            await fetch(
                url,
                {
                    method: 'GET',
                    headers: {
                        'Accept':
                            'application/json'
                    }
                }
            );

    } catch (error) {

        setDiagnosticNetworkError(
            error
        );

        throw error;
    }

    let data;

    try {

        data =
            await readJsonResponse(
                response
            );

    } catch (error) {

        setDiagnosticApiError(
            response,
            {
                success: false,
                error: {
                    code:
                        'INVALID_RESPONSE',
                    message:
                        error instanceof Error
                            ? error.message
                            : String(error)
                }
            }
        );

        throw error;
    }

    if (
        !response.ok
        || data.success !== true
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
}

/*
 * =========================================================
 * CSRF
 * =========================================================
 */

async function getCsrfToken() {

    const result =
        await requestGet(
            'csrf'
        );

    const token =
        result?.data?.csrfToken;

    if (
        typeof token !== 'string'
        || token === ''
    ) {

        throw new Error(
            'CSRFトークンを取得できませんでした。'
        );
    }

    csrfToken =
        token;

    return token;
}

/*
 * =========================================================
 * POST
 *
 * POSTではセッションCookieが必要なので
 * credentials=same-originを使用。
 *
 * redirectは指定しない。
 * =========================================================
 */

async function requestPost(
    action,
    body = {}
) {

    const url =
        buildApiUrl(action);

    resetDiagnostic(
        url,
        'POST'
    );

    /*
     * CSRFがなければ自動取得。
     */

    if (!csrfToken) {

        await getCsrfToken();
    }

    const payload = {
        action: action,
        csrf_token: csrfToken,
        ...body
    };

    let response;

    try {

        response =
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

                    body:
                        JSON.stringify(
                            payload
                        )
                }
            );

    } catch (error) {

        setDiagnosticNetworkError(
            error
        );

        throw error;
    }

    let data;

    try {

        data =
            await readJsonResponse(
                response
            );

    } catch (error) {

        setDiagnosticApiError(
            response,
            {
                success: false,
                error: {
                    code:
                        'INVALID_RESPONSE',
                    message:
                        error instanceof Error
                            ? error.message
                            : String(error)
                }
            }
        );

        throw error;
    }

    if (
        !response.ok
        || data.success !== true
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
}

/*
 * =========================================================
 * health
 * =========================================================
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

/*
 * =========================================================
 * CSRFテスト
 * =========================================================
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

/*
 * =========================================================
 * POSTテスト
 * =========================================================
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

/*
 * =========================================================
 * イベント
 * =========================================================
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

})();
</script>

</body>
</html>
<?php
exit;
endif;

/* =========================================================
 * 未実装
 * ========================================================= */

errorResponse(
    'NOT_IMPLEMENTED',
    'この業務操作はまだ実装されていません。',
    501
);
<?php
declare(strict_types=1);

/**
 * ============================================================
 * 最小構成・単一入口テスト
 * ============================================================
 *
 * 目的:
 *
 *  1. Apache24 + PHP 8.4 / 8.5 で index.php が動く
 *  2. GET API が動く
 *  3. CSRF が動く
 *  4. POST API が動く
 *  5. JavaScript のボタンが確実に反応する
 *  6. fetch のURLを物理パスからハードコードしない
 *  7. history API が動く
 *
 * この段階では業務ロジックを入れない。
 *
 * 単一入口:
 *
 *     index.php
 *
 * GET:
 *
 *     index.php?action=health
 *     index.php?action=csrf
 *
 * POST:
 *
 *     index.php
 *
 * JSON:
 *
 *     {
 *         "action": "test_post",
 *         "csrf_token": "..."
 *     }
 *
 * ============================================================
 */

const APP_TIMEZONE = 'Asia/Tokyo';

date_default_timezone_set(APP_TIMEZONE);


/* ============================================================
 * 1. 共通HTTPヘッダー
 * ============================================================
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');


/* ============================================================
 * 2. 共通レスポンス
 * ============================================================
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


/* ============================================================
 * 3. HTMLエスケープ
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


/* ============================================================
 * 4. 例外処理
 * ============================================================
 */

function isApiRequest(): bool
{
    return isset($_GET['action'])
        || isset($_POST['action'])
        || str_contains(
            strtolower(
                $_SERVER['CONTENT_TYPE'] ?? ''
            ),
            'application/json'
        );
}


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
        echo '<p>';
        echo 'サーバー内部で予期しないエラーが発生しました。';
        echo '</p>';
        echo '</body>';
        echo '</html>';

        exit;
    }
);


/* ============================================================
 * 5. HTTPメソッド
 * ============================================================
 */

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


/* ============================================================
 * 6. セッション
 * ============================================================
 */

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


/* ============================================================
 * 7. CSRFトークン
 * ============================================================
 */

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
    $_SESSION['csrf_token'];


/* ============================================================
 * 8. JSON POST取得
 * ============================================================
 */

function getJsonBody(): array
{
    $contentType =
        strtolower(
            $_SERVER['CONTENT_TYPE'] ?? ''
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
        !is_string($raw)
        || trim($raw) === ''
    ) {
        return [];
    }

    $data =
        json_decode(
            $raw,
            true
        );

    if (!is_array($data)) {

        errorResponse(
            'INVALID_JSON',
            'POSTデータが正しいJSONではありません。',
            400
        );
    }

    return $data;
}


/* ============================================================
 * 9. action取得
 * ============================================================
 */

function getAction(
    string $method
): string {

    if ($method === 'GET') {

        $action =
            $_GET['action'] ?? '';

        return is_string($action)
            ? trim($action)
            : '';
    }


    /*
     * application/x-www-form-urlencoded
     */
    if (
        isset($_POST['action'])
        && is_string($_POST['action'])
    ) {

        return trim(
            $_POST['action']
        );
    }


    /*
     * application/json
     */
    $json =
        getJsonBody();

    if (
        isset($json['action'])
        && is_string($json['action'])
    ) {

        return trim(
            $json['action']
        );
    }

    return '';
}


/* ============================================================
 * 10. POSTデータ取得
 * ============================================================
 */

function getPostData(): array
{
    if (
        isset($_SERVER['CONTENT_TYPE'])
        && str_contains(
            strtolower(
                $_SERVER['CONTENT_TYPE']
            ),
            'application/json'
        )
    ) {

        return getJsonBody();
    }

    return $_POST;
}


/* ============================================================
 * 11. CSRF取得
 * ============================================================
 */

function getRequestCsrfToken(
    array $postData
): string {

    /*
     * HTTPヘッダー
     */
    $header =
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (
        is_string($header)
        && $header !== ''
    ) {

        return $header;
    }


    /*
     * POST / JSON
     */
    if (
        isset($postData['csrf_token'])
        && is_string(
            $postData['csrf_token']
        )
    ) {

        return $postData['csrf_token'];
    }

    return '';
}


/* ============================================================
 * 12. CSRF検証
 * ============================================================
 */

function validateCsrf(
    array $postData
): void {

    $expected =
        $_SESSION['csrf_token']
        ?? '';

    $actual =
        getRequestCsrfToken(
            $postData
        );

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
 * 13. 単一入口URL
 * ============================================================
 *
 * ここが重要。
 *
 * REQUEST_URI は使わない。
 *
 * REQUEST_URI:
 *
 *     /gojacic/.../アンケートアプリ/
 *
 * のように、index.phpを省略したURLになる可能性がある。
 *
 * そこで SCRIPT_NAME を使う。
 *
 * 例:
 *
 *     /gojacic/.poc/draft/アンケートアプリ/index.php
 *
 * JavaScriptではこの値を基準にAPI URLを作る。
 *
 * ============================================================
 */

function getEntryPath(): string
{
    $scriptName =
        $_SERVER['SCRIPT_NAME']
        ?? '';

    if (
        !is_string($scriptName)
        || $scriptName === ''
    ) {

        return '/index.php';
    }

    return $scriptName;
}


/* ============================================================
 * 14. GET action
 * ============================================================
 */

$allowedGetActions = [
    '',
    'health',
    'csrf',
];


/* ============================================================
 * 15. POST action
 * ============================================================
 */

$allowedPostActions = [
    'test_post',
];


/* ============================================================
 * 16. action判定
 * ============================================================
 */

$action =
    getAction($method);


/* ============================================================
 * 17. GET API
 * ============================================================
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


    /*
     * --------------------------------------------------------
     * GET health
     * --------------------------------------------------------
     */

    if ($action === 'health') {

        successResponse(
            [
                'status' => 'ok',
                'method' => 'GET',
                'phpVersion' => PHP_VERSION,
                'serverSoftware' =>
                    $_SERVER['SERVER_SOFTWARE']
                    ?? '',
                'scriptName' =>
                    $_SERVER['SCRIPT_NAME']
                    ?? '',
                'requestUri' =>
                    $_SERVER['REQUEST_URI']
                    ?? '',
                'https' =>
                    $_SERVER['HTTPS']
                    ?? '',
                'time' =>
                    date(DATE_ATOM),
            ],
            'GET API通信成功'
        );
    }


    /*
     * --------------------------------------------------------
     * GET csrf
     * --------------------------------------------------------
     */

    if ($action === 'csrf') {

        successResponse(
            [
                'csrfToken' =>
                    $csrfToken,
            ],
            'CSRFトークン取得成功'
        );
    }
}


/* ============================================================
 * 18. POST API
 * ============================================================
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


    $postData =
        getPostData();


    /*
     * POSTは必ずCSRF検証。
     */
    validateCsrf(
        $postData
    );


    /*
     * --------------------------------------------------------
     * POST test
     * --------------------------------------------------------
     */

    if ($action === 'test_post') {

        successResponse(
            [
                'status' => 'ok',
                'method' => 'POST',
                'csrf' => 'validated',
                'received' => $postData,
                'time' => date(DATE_ATOM),
            ],
            'POST API通信成功'
        );
    }
}


/* ============================================================
 * 19. 画面表示
 * ============================================================
 */

if (
    $method === 'GET'
    && $action === ''
) {

    /*
     * --------------------------------------------------------
     * JavaScriptへ渡すURL
     * --------------------------------------------------------
     *
     * PHPの変数をJSコードへ直接文字列連結しない。
     *
     * json_encode() を使って安全にJavaScript文字列にする。
     *
     * --------------------------------------------------------
     */

    $entryPath =
        getEntryPath();


    $entryUrl =
        $entryPath;


    $healthUrl =
        $entryPath
        . '?action=health';


    $csrfUrl =
        $entryPath
        . '?action=csrf';


    /*
     * json_encode失敗時もHTMLを壊さない。
     */
    $jsEntryUrl =
        json_encode(
            $entryUrl,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

    $jsHealthUrl =
        json_encode(
            $healthUrl,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

    $jsCsrfUrl =
        json_encode(
            $csrfUrl,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );


    if (
        !is_string($jsEntryUrl)
        || !is_string($jsHealthUrl)
        || !is_string($jsCsrfUrl)
    ) {

        throw new RuntimeException(
            'JavaScript用URLの生成に失敗しました。'
        );
    }


    ?>
<!doctype html>
<html lang="ja">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>
    PHP単一入口 基盤テスト
</title>


<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f4f6f8;
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
        0 2px 10px rgba(0,0,0,.07);
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
    padding: 12px 18px;
    background: #1769aa;
    color: #fff;
    font-size: 15px;
    cursor: pointer;
}

button:hover:not(:disabled) {
    background: #0f578d;
}

button:disabled {
    opacity: .5;
    cursor: wait;
}

button.green {
    background: #198754;
}

button.gray {
    background: #555;
}

.status {
    margin-top: 16px;
    padding: 14px;
    border-radius: 8px;
    background: #f1f3f5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.status.ok {
    background: #e8f7ed;
    color: #146c2e;
}

.status.error {
    background: #fff0f0;
    color: #a11a1a;
}

.status.loading {
    background: #fff8dc;
    color: #775d00;
}

pre {
    background: #111827;
    color: #d1fae5;
    padding: 16px;
    border-radius: 8px;
    overflow: auto;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.endpoint {
    padding: 12px;
    background: #f1f3f5;
    border-radius: 8px;
    word-break: break-all;
    font-family: monospace;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    text-align: left;
    padding: 10px;
    border-bottom: 1px solid #ddd;
    vertical-align: top;
}

th {
    width: 220px;
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

    th {
        width: 40%;
    }
}

</style>

</head>


<body>

<main>


<!-- ========================================================
     基本情報
     ======================================================== -->

<section class="card">

<h1>
    PHP単一入口 基盤テスト
</h1>

<p>
    まずは業務機能を入れず、
    Apache + PHP + JavaScript + fetch
    の基本構造だけを確認します。
</p>

<p>
    <strong>現在のindex.php入口</strong>
</p>

<div
    class="endpoint"
    id="entry-url"
>
    <?= h($entryUrl) ?>
</div>

</section>


<!-- ========================================================
     GET
     ======================================================== -->

<section class="card">

<h2>
    1. GET API
</h2>

<div class="endpoint">
    <?= h($healthUrl) ?>
</div>

<br>

<div class="buttons">

<button
    type="button"
    id="health-button"
>
    GET health
</button>


<button
    type="button"
    id="csrf-button"
    class="gray"
>
    CSRF取得
</button>

</div>

<div
    id="csrf-status"
    class="status"
>
    CSRF未取得
</div>

</section>


<!-- ========================================================
     POST
     ======================================================== -->

<section class="card">

<h2>
    2. POST API
</h2>

<p>
    POSTボタンを押すと、
    CSRF取得 → POST
    の順番で処理します。
</p>

<div class="buttons">

<button
    type="button"
    id="post-button"
    class="green"
>
    POST test
</button>

</div>

</section>


<!-- ========================================================
     結果
     ======================================================== -->

<section class="card">

<h2>
    通信結果
</h2>

<div
    id="status"
    class="status"
>
    未実行
</div>

<pre
    id="result"
>-</pre>

</section>


<!-- ========================================================
     通信診断
     ======================================================== -->

<section class="card">

<h2>
    通信診断
</h2>

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
<th>レスポンス</th>
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
<th>APIエラーコード</th>
<td id="diag-error-code">-</td>
</tr>

<tr>
<th>エラーメッセージ</th>
<td id="diag-error-message">-</td>
</tr>

</table>

</section>


<!-- ========================================================
     History API
     ======================================================== -->

<section class="card">

<h2>
    URL / History API
</h2>

<div class="buttons">

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
    class="gray"
>
    replaceState
</button>


<button
    type="button"
    id="back-button"
    class="gray"
>
    history.back()
</button>

</div>

<pre
    id="history-result"
>-</pre>

</section>


<!-- ========================================================
     JSエラー表示
     ======================================================== -->

<section class="card">

<h2>
    JavaScript状態
</h2>

<div
    id="js-status"
    class="status"
>
    JavaScript初期化中...
</div>

</section>


</main>


<script>
'use strict';

/*
 * ============================================================
 * 1. PHPから渡されたURL
 * ============================================================
 *
 * 重要:
 *
 * PHP側でjson_encode済みなので、
 * JavaScriptの構文を壊さない。
 *
 */

const ENTRY_URL =
    <?= $jsEntryUrl ?>;

const HEALTH_URL =
    <?= $jsHealthUrl ?>;

const CSRF_URL =
    <?= $jsCsrfUrl ?>;


/*
 * ============================================================
 * 2. DOM取得
 * ============================================================
 */

const healthButton =
    document.getElementById(
        'health-button'
    );

const csrfButton =
    document.getElementById(
        'csrf-button'
    );

const postButton =
    document.getElementById(
        'post-button'
    );

const statusElement =
    document.getElementById(
        'status'
    );

const resultElement =
    document.getElementById(
        'result'
    );

const csrfStatusElement =
    document.getElementById(
        'csrf-status'
    );

const jsStatusElement =
    document.getElementById(
        'js-status'
    );


/*
 * ============================================================
 * 3. DOM存在確認
 * ============================================================
 */

const requiredElements = [
    healthButton,
    csrfButton,
    postButton,
    statusElement,
    resultElement,
    csrfStatusElement,
    jsStatusElement
];

if (
    requiredElements.some(
        element => !element
    )
) {

    throw new Error(
        '必要なHTML要素を取得できません。'
    );
}


/*
 * ============================================================
 * 4. 状態
 * ============================================================
 */

let csrfToken = '';

let requestRunning = false;


/*
 * ============================================================
 * 5. 初期化
 * ============================================================
 */

jsStatusElement.textContent =
    'JavaScript初期化成功';

jsStatusElement.className =
    'status ok';


/*
 * ============================================================
 * 6. 結果表示
 * ============================================================
 */

function setStatus(
    message,
    type = ''
) {

    statusElement.textContent =
        message;

    statusElement.className =
        'status ' + type;
}


function showResult(
    value
) {

    if (
        typeof value === 'string'
    ) {

        resultElement.textContent =
            value;

        return;
    }

    resultElement.textContent =
        JSON.stringify(
            value,
            null,
            2
        );
}


/*
 * ============================================================
 * 7. 診断初期化
 * ============================================================
 */

function resetDiagnostic(
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
    ).textContent = '通信中';

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


/*
 * ============================================================
 * 8. 診断更新
 * ============================================================
 */

function setDiagnosticResponse(
    response,
    contentType
) {

    document.getElementById(
        'diag-status'
    ).textContent =
        String(
            response.status
        );

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


function setDiagnosticError(
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


/*
 * ============================================================
 * 9. 共通fetch
 * ============================================================
 */

async function apiFetch(
    url,
    options = {}
) {

    if (requestRunning) {

        throw new Error(
            '別の通信を実行中です。'
        );
    }


    requestRunning = true;


    resetDiagnostic(
        url,
        options.method || 'GET'
    );


    setStatus(
        '通信中...',
        'loading'
    );


    /*
     * ボタンを全部止める。
     */
    healthButton.disabled = true;
    csrfButton.disabled = true;
    postButton.disabled = true;


    const controller =
        new AbortController();


    const timeoutId =
        window.setTimeout(
            () => {
                controller.abort();
            },
            10000
        );


    try {

        const headers = {
            'Accept':
                'application/json'
        };


        /*
         * 呼び出し側のheadersを追加。
         */
        if (
            options.headers
        ) {

            Object.assign(
                headers,
                options.headers
            );
        }


        const response =
            await fetch(
                url,
                {
                    ...options,

                    credentials:
                        'same-origin',

                    headers,

                    signal:
                        controller.signal
                }
            );


        window.clearTimeout(
            timeoutId
        );


        const contentType =
            response.headers.get(
                'content-type'
            ) || '';


        setDiagnosticResponse(
            response,
            contentType
        );


        const text =
            await response.text();


        if (
            text === ''
        ) {

            document.getElementById(
                'diag-response'
            ).textContent =
                '空';

            throw new Error(
                'HTTPレスポンスが空です。'
            );
        }


        let data;


        try {

            data =
                JSON.parse(
                    text
                );

            document.getElementById(
                'diag-json'
            ).textContent =
                '成功';

        } catch (jsonError) {

            document.getElementById(
                'diag-json'
            ).textContent =
                '失敗';

            showResult(
                text
            );

            throw new Error(
                'JSON解析失敗。'
                + '\n'
                + 'Content-Type: '
                + contentType
                + '\n'
                + 'HTTP Status: '
                + response.status
            );
        }


        showResult(
            data
        );


        if (
            !response.ok
        ) {

            const code =
                data?.error?.code
                || 'HTTP_ERROR';

            const message =
                data?.error?.message
                || 'HTTPエラーです。';


            setDiagnosticError(
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


        if (
            data?.success !== true
        ) {

            const code =
                data?.error?.code
                || 'API_ERROR';

            const message =
                data?.error?.message
                || 'APIエラーです。';


            setDiagnosticError(
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


        setStatus(
            data.message
                || '通信成功',
            'ok'
        );


        return data;

    } catch (error) {

        window.clearTimeout(
            timeoutId
        );


        /*
         * タイムアウト
         */
        if (
            error?.name ===
            'AbortError'
        ) {

            setDiagnosticError(
                'TIMEOUT',
                '10秒以内に応答がありません。'
            );

            setStatus(
                'タイムアウト',
                'error'
            );

            showResult(
                '10秒以内にサーバーから応答がありませんでした。'
            );

            throw error;
        }


        /*
         * fetch自体が失敗
         *
         * HTTPレスポンスが存在しない。
         */
        if (
            error instanceof TypeError
        ) {

            setDiagnosticError(
                'NETWORK_ERROR',
                error.message
            );


            setStatus(
                'ネットワーク通信に失敗',
                'error'
            );


            showResult(
                'fetch失敗'
                + '\n\n'
                + 'URL: '
                + url
                + '\n\n'
                + 'HTTPレスポンスを取得できませんでした。'
                + '\n'
                + 'Apache / HTTPS / VirtualHost / '
                + '証明書 / ポート / Networkタブを確認してください。'
            );

            throw error;
        }


        /*
         * その他
         */
        setDiagnosticError(
            'CLIENT_ERROR',
            error?.message
                || String(error)
        );


        setStatus(
            '通信処理エラー',
            'error'
        );


        throw error;

    } finally {

        requestRunning = false;


        /*
         * 必ずボタンを復帰。
         *
         * エラー時もここを通る。
         */
        healthButton.disabled =
            false;

        csrfButton.disabled =
            false;

        postButton.disabled =
            false;
    }
}


/*
 * ============================================================
 * 10. GET health
 * ============================================================
 */

healthButton.addEventListener(
    'click',
    async () => {

        try {

            await apiFetch(
                HEALTH_URL,
                {
                    method: 'GET'
                }
            );

        } catch (error) {

            console.error(
                error
            );
        }
    }
);


/*
 * ============================================================
 * 11. CSRF取得
 * ============================================================
 */

async function fetchCsrf()
{
    const data =
        await apiFetch(
            CSRF_URL,
            {
                method: 'GET'
            }
        );


    csrfToken =
        data?.data?.csrfToken
        || '';


    if (
        csrfToken === ''
    ) {

        csrfStatusElement.textContent =
            'CSRF取得失敗';

        throw new Error(
            'CSRFトークンがレスポンスにありません。'
        );
    }


    csrfStatusElement.textContent =
        'CSRF取得済み';

    return csrfToken;
}


csrfButton.addEventListener(
    'click',
    async () => {

        try {

            await fetchCsrf();

        } catch (error) {

            csrfToken = '';

            csrfStatusElement.textContent =
                'CSRF取得失敗';

            console.error(
                error
            );
        }
    }
);


/*
 * ============================================================
 * 12. POST test
 * ============================================================
 */

postButton.addEventListener(
    'click',
    async () => {

        try {

            /*
             * CSRFがなければ取得。
             */
            if (
                csrfToken === ''
            ) {

                await fetchCsrf();
            }


            /*
             * POST。
             */
            const body =
                JSON.stringify(
                    {
                        action:
                            'test_post',

                        csrf_token:
                            csrfToken,

                        clientTime:
                            new Date()
                                .toISOString()
                    }
                );


            await apiFetch(
                ENTRY_URL,
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json',

                        'X-CSRF-Token':
                            csrfToken
                    },

                    body
                }
            );

        } catch (error) {

            console.error(
                error
            );
        }
    }
);


/*
 * ============================================================
 * 13. URL状態
 * ============================================================
 */

function getScreenState()
{
    const params =
        new URLSearchParams(
            window.location.search
        );


    return {
        screen:
            params.get('screen')
            || '',

        surveyId:
            params.get('surveyId')
            || '',

        customerId:
            params.get('customerId')
            || '',

        questionId:
            params.get('questionId')
            || ''
    };
}


function showHistoryState()
{
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
                    getScreenState()
            },
            null,
            2
        );
}


function makeScreenUrl(
    screen
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


    return url.toString();
}


/*
 * ============================================================
 * 14. pushState
 * ============================================================
 */

document
    .getElementById(
        'admin-button'
    )
    .addEventListener(
        'click',
        () => {

            const url =
                makeScreenUrl(
                    'admin'
                );


            history.pushState(
                {
                    screen: 'admin'
                },
                '',
                url
            );


            showHistoryState();
        }
    );


document
    .getElementById(
        'survey-button'
    )
    .addEventListener(
        'click',
        () => {

            const url =
                makeScreenUrl(
                    'survey'
                );


            history.pushState(
                {
                    screen: 'survey'
                },
                '',
                url
            );


            showHistoryState();
        }
    );


document
    .getElementById(
        'answer-button'
    )
    .addEventListener(
        'click',
        () => {

            const url =
                makeScreenUrl(
                    'answer'
                );


            history.pushState(
                {
                    screen: 'answer'
                },
                '',
                url
            );


            showHistoryState();
        }
    );


/*
 * ============================================================
 * 15. replaceState
 * ============================================================
 */

document
    .getElementById(
        'replace-button'
    )
    .addEventListener(
        'click',
        () => {

            const url =
                makeScreenUrl(
                    'survey'
                );


            history.replaceState(
                {
                    screen: 'survey',
                    replaced: true
                },
                '',
                url
            );


            showHistoryState();
        }
    );


/*
 * ============================================================
 * 16. history.back()
 * ============================================================
 */

document
    .getElementById(
        'back-button'
    )
    .addEventListener(
        'click',
        () => {

            history.back();
        }
    );


/*
 * ============================================================
 * 17. popstate
 * ============================================================
 */

window.addEventListener(
    'popstate',
    () => {

        showHistoryState();
    }
);


/*
 * ============================================================
 * 18. 初期状態表示
 * ============================================================
 */

showHistoryState();


/*
 * ============================================================
 * 19. 起動完了
 * ============================================================
 */

console.log(
    'Application initialized.'
);

console.log(
    'ENTRY_URL:',
    ENTRY_URL
);

console.log(
    'HEALTH_URL:',
    HEALTH_URL
);

console.log(
    'CSRF_URL:',
    CSRF_URL
);

</script>

</body>

</html>

<?php

    exit;
}


/* ============================================================
 * 20. 想定外
 * ============================================================
 */

errorResponse(
    'NO_ROUTE',
    'リクエストを処理できませんでした。',
    500
);
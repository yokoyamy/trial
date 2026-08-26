<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| アンケート管理システム
| 最小通信基盤
|--------------------------------------------------------------------------
|
| 目的：
|   - 単一入口 index.php
|   - GET / POST
|   - action
|   - 共通JSONレスポンス
|   - 共通例外処理
|   - セッション
|   - CSRF
|   - JavaScript callApi()
|   - fetch() は callApi() 内だけ
|   - URL / History API の最小確認
|
| 業務機能はまだ実装しない。
|
*/


/* ==========================================================================
 * 1. 基本設定
 * ========================================================================== */

date_default_timezone_set('Asia/Tokyo');

$isApiRequest = isset($_GET['action']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';


/* ==========================================================================
 * 2. セッション
 * ========================================================================== */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/* ==========================================================================
 * 3. JSONレスポンス
 * ========================================================================== */

function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {
    http_response_code($status);

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode(
        [
            'success' => true,
            'data'    => $data,
            'message' => $message,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


function errorResponse(
    string $code,
    string $message,
    int $status = 400,
    mixed $data = null
): never {
    http_response_code($status);

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $response = [
        'success' => false,
        'error'   => [
            'code'    => $code,
            'message' => $message,
        ],
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


/* ==========================================================================
 * 4. ログ
 * ========================================================================== */

function appLog(string $message, array $context = []): void
{
    /*
     * パスワード、CSRF token、Cookie等はここへ渡さない。
     */

    $line = '[survey-app] ' . $message;

    if ($context !== []) {
        $line .= ' ' . json_encode(
            $context,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    error_log($line);
}


/* ==========================================================================
 * 5. 共通例外処理
 * ========================================================================== */

set_exception_handler(
    function (Throwable $e) use (&$isApiRequest): void {

        appLog(
            'Unhandled exception',
            [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]
        );

        if ($isApiRequest) {
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
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
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


/* ==========================================================================
 * 6. PHP Warning / Notice等
 * ========================================================================== */

set_error_handler(
    function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {

        /*
         * PHP Warning / NoticeをHTMLへ直接出力させない。
         */

        appLog(
            'PHP error',
            [
                'severity' => $severity,
                'message'  => $message,
                'file'     => $file,
                'line'     => $line,
            ]
        );

        /*
         * ErrorExceptionへ変換して共通例外処理へ送る。
         */

        throw new ErrorException(
            $message,
            0,
            $severity,
            $file,
            $line
        );
    }
);


/* ==========================================================================
 * 7. Fatal Error監視
 * ========================================================================== */

register_shutdown_function(
    function () use (&$isApiRequest): void {

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

        appLog(
            'Fatal PHP error',
            [
                'type'    => $error['type'],
                'message' => $error['message'],
                'file'    => $error['file'],
                'line'    => $error['line'],
            ]
        );

        /*
         * すでにレスポンスが開始されている場合、
         * JSONへ完全に戻すことはできない。
         *
         * したがって、Fatal Errorを発生させない構造を
         * 基本とする。
         */
    }
);


/* ==========================================================================
 * 8. CSRF
 * ========================================================================== */

function getCsrfToken(): string
{
    if (
        !isset($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token']) ||
        $_SESSION['csrf_token'] === ''
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}


function requireCsrf(): void
{
    $token = '';

    /*
     * JSON POST
     */

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (str_contains(strtolower($contentType), 'application/json')) {
        $raw = file_get_contents('php://input');

        if ($raw !== false && $raw !== '') {
            $json = json_decode($raw, true);

            if (is_array($json) && isset($json['csrfToken'])) {
                $token = (string)$json['csrfToken'];
            }
        }
    }

    /*
     * 通常POST
     */

    if ($token === '' && isset($_POST['csrfToken'])) {
        $token = (string)$_POST['csrfToken'];
    }

    $sessionToken = getCsrfToken();

    if (
        $token === '' ||
        !hash_equals($sessionToken, $token)
    ) {
        errorResponse(
            'CSRF_INVALID',
            'CSRFトークンが不正です。',
            403
        );
    }
}


/* ==========================================================================
 * 9. JSON Request Body
 * ========================================================================== */

function getJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (!str_contains(strtolower($contentType), 'application/json')) {
        return [];
    }

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $json = json_decode($raw, true);

    if (!is_array($json)) {
        errorResponse(
            'INVALID_JSON',
            'リクエストJSONが不正です。',
            400
        );
    }

    return $json;
}


/* ==========================================================================
 * 10. HTTP Method
 * ========================================================================== */

function getRequestMethod(): string
{
    return strtoupper(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
    );
}


/* ==========================================================================
 * 11. Action
 * ========================================================================== */

function getAction(): string
{
    return trim(
        (string)($_GET['action'] ?? '')
    );
}


/* ==========================================================================
 * 12. API処理
 * ========================================================================== */

function handleApi(): never
{
    $method = getRequestMethod();
    $action = getAction();

    /*
     * actionなし
     */

    if ($action === '') {
        errorResponse(
            'ACTION_REQUIRED',
            'actionを指定してください。',
            400
        );
    }

    /*
     * GET
     */

    if ($method === 'GET') {

        switch ($action) {

            case 'health':

                successResponse(
                    [
                        'status'    => 'ok',
                        'php'       => PHP_VERSION,
                        'method'    => $method,
                        'action'    => $action,
                        'timestamp' => date('c'),
                    ],
                    'APIは正常に動作しています。'
                );


            case 'csrf':

                successResponse(
                    [
                        'csrfToken' => getCsrfToken(),
                    ],
                    'CSRFトークンを取得しました。'
                );


            default:

                errorResponse(
                    'ACTION_NOT_FOUND',
                    '指定されたactionは存在しません。',
                    404
                );
        }
    }


    /*
     * POST
     */

    if ($method === 'POST') {

        /*
         * POSTのJSONを先に取得。
         */

        $body = getJsonBody();

        /*
         * CSRF確認。
         */

        requireCsrf();

        switch ($action) {

            case 'healthPost':

                successResponse(
                    [
                        'status' => 'ok',
                        'method' => $method,
                        'action' => $action,
                        'received' => $body,
                        'timestamp' => date('c'),
                    ],
                    'POST APIは正常に動作しています。'
                );


            default:

                errorResponse(
                    'ACTION_NOT_FOUND',
                    '指定されたPOST actionは存在しません。',
                    404
                );
        }
    }


    /*
     * 許可していないHTTPメソッド
     */

    errorResponse(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405
    );
}


/* ==========================================================================
 * 13. APIか画面かを判定
 * ========================================================================== */

if ($isApiRequest) {
    handleApi();
}


/* ==========================================================================
 * 14. 画面
 * ========================================================================== */

$csrfToken = htmlspecialchars(
    getCsrfToken(),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

?>
<!doctype html>
<html lang="ja">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>アンケート管理システム - 通信基盤テスト</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 24px;
    background: #f4f6f8;
    color: #222;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
}

.container {
    width: min(100%, 960px);
    margin: 0 auto;
}

h1 {
    margin-top: 0;
}

.card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 16px;
}

button {
    appearance: none;
    border: 0;
    border-radius: 6px;
    padding: 10px 16px;
    margin: 4px;
    background: #2563eb;
    color: #fff;
    cursor: pointer;
    font-size: 14px;
}

button:hover:not(:disabled) {
    background: #1d4ed8;
}

button:disabled {
    opacity: .5;
    cursor: not-allowed;
}

button.secondary {
    background: #475569;
}

button.danger {
    background: #dc2626;
}

.status {
    padding: 12px;
    border-radius: 6px;
    margin-top: 12px;
    background: #eef2ff;
    white-space: pre-wrap;
}

.status.success {
    background: #dcfce7;
    color: #166534;
}

.status.error {
    background: #fee2e2;
    color: #991b1b;
}

pre {
    background: #111827;
    color: #e5e7eb;
    padding: 16px;
    border-radius: 8px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
}

.loading {
    display: none;
    margin-left: 8px;
    color: #2563eb;
}

.loading.active {
    display: inline;
}

.meta {
    font-size: 13px;
    color: #64748b;
    line-height: 1.8;
}

.ok {
    color: #15803d;
}

.ng {
    color: #dc2626;
}

</style>

</head>

<body>

<div class="container">

    <div class="card">

        <h1>
            アンケート管理システム
        </h1>

        <p>
            最小通信基盤テスト
        </p>

        <p class="meta">
            PHP: <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?><br>
            現在URL:
            <span id="currentUrl"></span>
        </p>

    </div>


    <!-- ================================================================
         GET
         ================================================================ -->

    <div class="card">

        <h2>GET API</h2>

        <p>
            <code>index.php?action=health</code>
        </p>

        <button
            type="button"
            id="btnGetHealth"
        >
            GET health
        </button>

        <span
            id="loadingGet"
            class="loading"
        >
            通信中...
        </span>

    </div>


    <!-- ================================================================
         CSRF
         ================================================================ -->

    <div class="card">

        <h2>CSRF</h2>

        <button
            type="button"
            id="btnCsrf"
        >
            CSRF取得
        </button>

        <span
            id="loadingCsrf"
            class="loading"
        >
            通信中...
        </span>

    </div>


    <!-- ================================================================
         POST
         ================================================================ -->

    <div class="card">

        <h2>POST API</h2>

        <p>
            CSRF取得後にPOSTを実行します。
        </p>

        <button
            type="button"
            id="btnPostHealth"
        >
            POST health
        </button>

        <span
            id="loadingPost"
            class="loading"
        >
            通信中...
        </span>

    </div>


    <!-- ================================================================
         URL / History
         ================================================================ -->

    <div class="card">

        <h2>URL / History API</h2>

        <button
            type="button"
            data-screen="admin"
        >
            screen=admin
        </button>

        <button
            type="button"
            data-screen="survey"
        >
            screen=survey
        </button>

        <button
            type="button"
            data-screen="answer"
        >
            screen=answer
        </button>

        <button
            type="button"
            id="btnReplace"
            class="secondary"
        >
            replaceState
        </button>

        <button
            type="button"
            id="btnBack"
            class="secondary"
        >
            history.back()
        </button>

    </div>


    <!-- ================================================================
         通信診断
         ================================================================ -->

    <div class="card">

        <h2>通信結果</h2>

        <div
            id="status"
            class="status"
        >
            未実行
        </div>

        <pre id="result">---</pre>

    </div>


    <!-- ================================================================
         現在のURL状態
         ================================================================ -->

    <div class="card">

        <h2>現在のURL状態</h2>

        <pre id="urlState">---</pre>

    </div>

</div>


<script>

/* ==========================================================================
 * 1. PHPから安全にCSRFを受け取る
 * ========================================================================== */

const initialCsrfToken =
    <?= json_encode(
        $csrfToken,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;


/* ==========================================================================
 * 2. CSRF token
 * ========================================================================== */

let csrfToken = initialCsrfToken;


/* ==========================================================================
 * 3. API入口URL
 *
 * 重要：
 *
 * ここでは
 *
 * /gojacic/...
 *
 * /アンケートアプリ/...
 *
 * 等をハードコードしない。
 *
 * 現在表示しているページのURLを基準に
 * index.phpを解決する。
 * ========================================================================== */

function getApiUrl(action) {

    const url = new URL(
        'index.php',
        window.location.href
    );

    url.search = '';

    url.searchParams.set(
        'action',
        action
    );

    return url;
}


/* ==========================================================================
 * 4. API共通通信関数
 *
 * fetch() はここだけ。
 *
 * 画面側からfetch()を直接呼ばない。
 * ========================================================================== */

async function callApi(action, options = {}) {

    const method =
        String(options.method || 'GET').toUpperCase();

    const url =
        getApiUrl(action);

    const startedAt =
        performance.now();


    /*
     * Request
     */

    const headers = {
        'Accept': 'application/json'
    };


    const request = {
        method,
        credentials: 'same-origin',
        headers
    };


    /*
     * POST等
     */

    if (
        method !== 'GET' &&
        method !== 'HEAD'
    ) {

        headers['Content-Type'] =
            'application/json; charset=UTF-8';


        const body = {
            ...(options.body || {}),
            csrfToken
        };


        request.body =
            JSON.stringify(body);
    }


    console.log(
        '[API REQUEST]',
        {
            url: url.toString(),
            method,
            request
        }
    );


    /*
     * fetch
     *
     * このアプリでfetch()を直接呼ぶのはここだけ。
     */

    let response;

    try {

        response = await fetch(
            url.toString(),
            request
        );

    } catch (error) {

        const elapsedMs =
            Math.round(
                performance.now() - startedAt
            );


        console.error(
            '[API NETWORK ERROR]',
            {
                url: url.toString(),
                method,
                elapsedMs,
                error
            }
        );


        throw {
            code: 'NETWORK_ERROR',

            message:
                'HTTPレスポンスを取得できませんでした。',

            detail:
                error instanceof Error
                    ? error.message
                    : String(error),

            url:
                url.toString(),

            method,

            status:
                null,

            contentType:
                null,

            response:
                null
        };
    }


    /*
     * Response
     */

    const contentType =
        response.headers.get(
            'Content-Type'
        ) || '';


    const text =
        await response.text();


    const elapsedMs =
        Math.round(
            performance.now() - startedAt
        );


    console.log(
        '[API RESPONSE]',
        {
            url: url.toString(),
            method,
            status: response.status,
            ok: response.ok,
            contentType,
            elapsedMs,
            text
        }
    );


    /*
     * HTTPエラー
     */

    if (!response.ok) {

        throw {
            code: 'HTTP_ERROR',

            message:
                `HTTP ${response.status} エラー`,

            url:
                url.toString(),

            method,

            status:
                response.status,

            contentType,

            response:
                text
        };
    }


    /*
     * Content-Type確認
     */

    if (
        !contentType
            .toLowerCase()
            .includes('application/json')
    ) {

        throw {
            code: 'INVALID_CONTENT_TYPE',

            message:
                'APIがJSONを返していません。',

            url:
                url.toString(),

            method,

            status:
                response.status,

            contentType,

            response:
                text
        };
    }


    /*
     * JSON解析
     */

    let json;

    try {

        json =
            JSON.parse(text);

    } catch (error) {

        throw {
            code: 'INVALID_JSON',

            message:
                'APIレスポンスのJSON解析に失敗しました。',

            url:
                url.toString(),

            method,

            status:
                response.status,

            contentType,

            response:
                text
        };
    }


    /*
     * API success確認
     */

    if (
        !json ||
        json.success !== true
    ) {

        throw {
            code:
                json?.error?.code ||
                'API_ERROR',

            message:
                json?.error?.message ||
                json?.message ||
                'API処理に失敗しました。',

            url:
                url.toString(),

            method,

            status:
                response.status,

            contentType,

            response:
                json
        };
    }


    /*
     * 正常終了
     */

    return json;
}


/* ==========================================================================
 * 5. UI共通
 * ========================================================================== */

function setStatus(
    message,
    type = ''
) {

    const element =
        document.getElementById(
            'status'
        );

    element.textContent =
        message;

    element.className =
        'status ' + type;
}


function setResult(value) {

    const element =
        document.getElementById(
            'result'
        );


    if (
        typeof value === 'string'
    ) {

        element.textContent =
            value;

        return;
    }


    element.textContent =
        JSON.stringify(
            value,
            null,
            2
        );
}


function setLoading(
    id,
    loading
) {

    const element =
        document.getElementById(id);

    if (!element) {
        return;
    }

    element.classList.toggle(
        'active',
        loading
    );
}


/* ==========================================================================
 * 6. ボタン二重実行防止
 * ========================================================================== */

async function runButton(
    button,
    loadingId,
    operation
) {

    if (button.disabled) {
        return;
    }


    button.disabled =
        true;


    setLoading(
        loadingId,
        true
    );


    try {

        await operation();

    } finally {

        button.disabled =
            false;

        setLoading(
            loadingId,
            false
        );
    }
}


/* ==========================================================================
 * 7. GET health
 * ========================================================================== */

async function testGetHealth() {

    const button =
        document.getElementById(
            'btnGetHealth'
        );


    await runButton(
        button,
        'loadingGet',
        async () => {

            setStatus(
                'GET API通信中...'
            );

            setResult(
                '通信中...'
            );


            try {

                const result =
                    await callApi(
                        'health'
                    );


                setStatus(
                    'GET API 成功',
                    'success'
                );


                setResult(
                    result
                );


            } catch (error) {

                setStatus(
                    'GET API 失敗',
                    'error'
                );


                setResult(
                    normalizeApiError(
                        error
                    )
                );
            }
        }
    );
}


/* ==========================================================================
 * 8. CSRF
 * ========================================================================== */

async function getCsrf() {

    const button =
        document.getElementById(
            'btnCsrf'
        );


    await runButton(
        button,
        'loadingCsrf',
        async () => {

            setStatus(
                'CSRF取得中...'
            );


            try {

                const result =
                    await callApi(
                        'csrf'
                    );


                if (
                    !result.data ||
                    !result.data.csrfToken
                ) {

                    throw {
                        code:
                            'CSRF_RESPONSE_INVALID',

                        message:
                            'CSRFレスポンスが不正です。',

                        response:
                            result
                    };
                }


                csrfToken =
                    result.data.csrfToken;


                setStatus(
                    'CSRF取得成功',
                    'success'
                );


                setResult(
                    {
                        success: true,
                        message:
                            'CSRF tokenを取得しました。',
                        tokenLength:
                            csrfToken.length
                    }
                );


            } catch (error) {

                setStatus(
                    'CSRF取得失敗',
                    'error'
                );


                setResult(
                    normalizeApiError(
                        error
                    )
                );
            }
        }
    );
}


/* ==========================================================================
 * 9. POST health
 * ========================================================================== */

async function testPostHealth() {

    const button =
        document.getElementById(
            'btnPostHealth'
        );


    await runButton(
        button,
        'loadingPost',
        async () => {

            setStatus(
                'POST API通信中...'
            );


            setResult(
                '通信中...'
            );


            try {

                /*
                 * まずCSRFがあるか確認。
                 */

                if (
                    !csrfToken
                ) {

                    throw {
                        code:
                            'CSRF_NOT_AVAILABLE',

                        message:
                            'CSRF tokenがありません。先にCSRF取得を実行してください。'
                    };
                }


                const result =
                    await callApi(
                        'healthPost',
                        {
                            method: 'POST',

                            body: {
                                test: true,
                                clientTime:
                                    new Date().toISOString()
                            }
                        }
                    );


                setStatus(
                    'POST API 成功',
                    'success'
                );


                setResult(
                    result
                );


            } catch (error) {

                setStatus(
                    'POST API 失敗',
                    'error'
                );


                setResult(
                    normalizeApiError(
                        error
                    )
                );
            }
        }
    );
}


/* ==========================================================================
 * 10. エラーを画面表示用に正規化
 * ========================================================================== */

function normalizeApiError(
    error
) {

    if (
        error &&
        typeof error === 'object'
    ) {

        return {
            code:
                error.code ||
                'UNKNOWN_ERROR',

            message:
                error.message ||
                '不明なエラー',

            detail:
                error.detail ||
                null,

            url:
                error.url ||
                null,

            method:
                error.method ||
                null,

            status:
                error.status ??
                null,

            contentType:
                error.contentType ||
                null,

            response:
                error.response ??
                null
        };
    }


    return {
        code:
            'UNKNOWN_ERROR',

        message:
            String(error)
    };
}


/* ==========================================================================
 * 11. URL状態表示
 * ========================================================================== */

function renderUrlState() {

    const url =
        new URL(
            window.location.href
        );


    const state = {
        currentUrl:
            window.location.href,

        pathname:
            url.pathname,

        search:
            url.search,

        hash:
            url.hash,

        screen:
            url.searchParams.get(
                'screen'
            ),

        surveyId:
            url.searchParams.get(
                'surveyId'
            ),

        customerId:
            url.searchParams.get(
                'customerId'
            ),

        questionId:
            url.searchParams.get(
                'questionId'
            ),

        historyState:
            window.history.state
    };


    document.getElementById(
        'currentUrl'
    ).textContent =
        window.location.href;


    document.getElementById(
        'urlState'
    ).textContent =
        JSON.stringify(
            state,
            null,
            2
        );
}


/* ==========================================================================
 * 12. screen URL
 * ========================================================================== */

function changeScreen(
    screen
) {

    const url =
        new URL(
            window.location.href
        );


    url.searchParams.set(
        'screen',
        screen
    );


    /*
     * 業務上の状態はquery string。
     *
     * pathnameは変更しない。
     */

    const state = {
        screen
    };


    window.history.pushState(
        state,
        '',
        url
    );


    renderUrlState();
}


/* ==========================================================================
 * 13. replaceState
 * ========================================================================== */

function replaceScreen(
    screen
) {

    const url =
        new URL(
            window.location.href
        );


    url.searchParams.set(
        'screen',
        screen
    );


    const state = {
        screen,
        replaced: true
    };


    window.history.replaceState(
        state,
        '',
        url
    );


    renderUrlState();
}


/* ==========================================================================
 * 14. popstate
 * ========================================================================== */

window.addEventListener(
    'popstate',
    () => {

        console.log(
            '[HISTORY] popstate',
            window.location.href,
            window.history.state
        );


        renderUrlState();
    }
);


/* ==========================================================================
 * 15. DOMイベント
 * ========================================================================== */

document.addEventListener(
    'DOMContentLoaded',
    () => {

        renderUrlState();


        /*
         * GET
         */

        document
            .getElementById(
                'btnGetHealth'
            )
            .addEventListener(
                'click',
                testGetHealth
            );


        /*
         * CSRF
         */

        document
            .getElementById(
                'btnCsrf'
            )
            .addEventListener(
                'click',
                getCsrf
            );


        /*
         * POST
         */

        document
            .getElementById(
                'btnPostHealth'
            )
            .addEventListener(
                'click',
                testPostHealth
            );


        /*
         * screen buttons
         */

        document
            .querySelectorAll(
                '[data-screen]'
            )
            .forEach(
                button => {

                    button.addEventListener(
                        'click',
                        () => {

                            changeScreen(
                                button.dataset.screen
                            );
                        }
                    );
                }
            );


        /*
         * replaceState
         */

        document
            .getElementById(
                'btnReplace'
            )
            .addEventListener(
                'click',
                () => {

                    replaceScreen(
                        'survey'
                    );
                }
            );


        /*
         * history.back()
         */

        document
            .getElementById(
                'btnBack'
            )
            .addEventListener(
                'click',
                () => {

                    window.history.back();
                }
            );
    }
);

</script>

</body>

</html>
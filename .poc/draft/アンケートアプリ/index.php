<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * 第1段階：単一入口・Apache/PHP・GET/POST基盤
 * ============================================================
 *
 * 対象:
 *   Apache24
 *   PHP 8.4 / 8.5
 *   DBなし
 *
 * このファイルの目的:
 *
 *   1. index.php がApacheから実行できる
 *   2. GET health がJSONを返す
 *   3. POST test_post がJSONを返す
 *   4. PHP Warning/NoticeがJSONを破壊しない
 *   5. PHP Fatal Errorを可能な範囲でJSON化する
 *   6. CSRFを共通化する
 *   7. API URLを物理パスへハードコードしない
 *
 * 重要:
 *
 *   health APIはセッション・CSRF・JSONファイル等に
 *   一切依存しない。
 */


/* ============================================================
 * 1. 基本PHP設定
 * ============================================================
 */

date_default_timezone_set('Asia/Tokyo');

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

error_reporting(E_ALL);


/* ============================================================
 * 2. HTTP基本ヘッダー
 * ============================================================
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');


/* ============================================================
 * 3. API判定
 *
 * ここではまだerrorResponse()を使わない。
 * ============================================================
 */

function isApiRequest(): bool
{
    return isset($_GET['action'])
        || isset($_POST['action'])
        || (
            isset($_SERVER['CONTENT_TYPE'])
            && str_contains(
                strtolower(
                    (string)$_SERVER['CONTENT_TYPE']
                ),
                'application/json'
            )
        );
}


/* ============================================================
 * 4. JSONエンコード
 *
 * Fatal Error処理からも安全に呼べるよう、
 * 他のアプリケーション関数に依存しない。
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
        return
            '{"success":false,"error":{"code":"JSON_ENCODE_ERROR","message":"JSONレスポンス生成に失敗しました。"}}';
    }

    return $json;
}


/* ============================================================
 * 5. 低レベルJSONエラー出力
 *
 * Fatal Error時にも使える。
 *
 * errorResponse()に依存しない。
 * ============================================================
 */

function emitFatalJson(
    string $code,
    string $message,
    int $status
): void {
    /*
     * すでに何か出力されている可能性がある。
     */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo encodeJson([
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);
}


/* ============================================================
 * 6. 共通成功レスポンス
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

    echo encodeJson([
        'success' => true,
        'data' => $data,
        'message' => $message,
    ]);

    exit;
}


/* ============================================================
 * 7. 共通エラーレスポンス
 * ============================================================
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

    echo encodeJson([
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);

    exit;
}


/* ============================================================
 * 8. PHP Exception Handler
 *
 * この時点でerrorResponse()は既に定義済み。
 * ============================================================
 */

set_exception_handler(
    function (Throwable $exception): void {

        error_log(
            '[SURVEY_APP][EXCEPTION] '
            . get_class($exception)
            . ' '
            . $exception->getMessage()
            . ' file='
            . $exception->getFile()
            . ' line='
            . $exception->getLine()
        );

        if (isApiRequest()) {

            emitFatalJson(
                'INTERNAL_ERROR',
                'サーバー内部で予期しないエラーが発生しました。',
                500
            );

            exit;
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


/* ============================================================
 * 9. PHP Fatal Error Handler
 *
 * 重要:
 *
 * ここからerrorResponse()は呼ばない。
 *
 * 前回コードの
 *
 *   Call to undefined function errorResponse()
 *
 * を構造的に防止する。
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
            E_CORE_WARNING,
            E_COMPILE_ERROR,
            E_COMPILE_WARNING,
            E_USER_ERROR,
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
            '[SURVEY_APP][FATAL] '
            . 'severity='
            . ($error['type'] ?? '')
            . ' message='
            . ($error['message'] ?? '')
            . ' file='
            . ($error['file'] ?? '')
            . ' line='
            . ($error['line'] ?? '')
        );

        /*
         * APIリクエストの場合だけJSONを返す。
         */
        if (isApiRequest()) {

            emitFatalJson(
                'PHP_FATAL_ERROR',
                'サーバー内部エラーが発生しました。',
                500
            );

            return;
        }

        /*
         * 通常画面の場合。
         */
        if (
            !headers_sent()
        ) {
            http_response_code(500);
        }
    }
);


/* ============================================================
 * 10. HTTPメソッド取得
 *
 * ここで必ず$methodを定義する。
 *
 * Undefined variable $methodを構造的に防止する。
 * ============================================================
 */

$method = strtoupper(
    (string)(
        $_SERVER['REQUEST_METHOD']
        ?? 'GET'
    )
);


/* ============================================================
 * 11. HTTPメソッド検証
 * ============================================================
 */

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


/* ============================================================
 * 12. action取得
 * ============================================================
 */

function getAction(
    string $method
): string {

    if ($method === 'GET') {

        $value =
            $_GET['action']
            ?? '';

        return is_string($value)
            ? trim($value)
            : '';
    }


    /*
     * application/x-www-form-urlencoded
     */
    if (
        isset($_POST['action'])
        && is_string($_POST['action'])
    ) {
        return trim($_POST['action']);
    }


    /*
     * application/json
     */
    $contentType = strtolower(
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
                && is_string($json['action'])
            ) {

                return trim(
                    $json['action']
                );
            }
        }
    }

    return '';
}


$action = getAction($method);


/* ============================================================
 * 13. GET action
 * ============================================================
 */

$allowedGetActions = [
    '',
    'health',
    'csrf',
];


/* ============================================================
 * 14. POST action
 * ============================================================
 */

$allowedPostActions = [
    'test_post',
];


/* ============================================================
 * 15. health API
 *
 * 最重要。
 *
 * session_start()
 * CSRF
 * JSONファイル
 * 外部API
 *
 * には依存しない。
 *
 * GET:
 *
 * index.php?action=health
 *
 * → HTTP 200 JSON
 * ============================================================
 */

if (
    $method === 'GET'
    && $action === 'health'
) {

    successResponse(
        [
            'status' => 'ok',
            'application' =>
                'アンケート管理システム',
            'phpVersion' =>
                PHP_VERSION,
            'serverSoftware' =>
                (string)(
                    $_SERVER['SERVER_SOFTWARE']
                    ?? ''
                ),
            'requestMethod' =>
                (string)(
                    $_SERVER['REQUEST_METHOD']
                    ?? ''
                ),
            'requestUri' =>
                (string)(
                    $_SERVER['REQUEST_URI']
                    ?? ''
                ),
            'time' =>
                date(DATE_ATOM),
        ],
        '通信成功',
        200
    );
}


/* ============================================================
 * 16. GET action検証
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
}


/* ============================================================
 * 17. actionなしGET
 *
 * index.php
 * ============================================================
 */

if (
    $method === 'GET'
    && $action === ''
) {

    /*
     * 現在のURLからquery stringだけ除去する。
     *
     * これは業務上のpathname識別ではなく、
     * 現在の単一入口自身を取得するためだけに使用する。
     */

    $requestUri =
        (string)(
            $_SERVER['REQUEST_URI']
            ?? ''
        );

    $entryPath =
        $requestUri;

    $questionMark =
        strpos(
            $entryPath,
            '?'
        );

    if ($questionMark !== false) {

        $entryPath =
            substr(
                $entryPath,
                0,
                $questionMark
            );
    }

    if (
        $entryPath === ''
        || $entryPath[0] !== '/'
    ) {
        $entryPath = '/index.php';
    }

    $entryPathJson =
        encodeJson(
            $entryPath
        );

    ?>
<!doctype html>
<html lang="ja">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>アンケート管理システム</title>

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
    margin: auto;
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

button {
    border: 0;
    border-radius: 8px;
    padding: 12px 18px;
    color: #fff;
    background: #1769aa;
    cursor: pointer;
    font-size: 14px;
}

button:disabled {
    opacity: .5;
    cursor: wait;
}

button.secondary {
    background: #555;
}

button.green {
    background: #218739;
}

.buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.loading {
    display: none;
    margin-top: 12px;
    color: #1769aa;
    font-weight: 700;
}

.loading.active {
    display: block;
}

.result {
    margin-top: 16px;
    padding: 14px;
    border-radius: 8px;
    background: #f0f2f5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.result.ok {
    background: #e9f8ee;
    color: #145c29;
}

.result.error {
    background: #fff0f0;
    color: #8b1e1e;
}

.diagnostic {
    display: grid;
    grid-template-columns:
        180px
        1fr;
    gap: 10px 16px;
}

.diagnostic dt {
    font-weight: 700;
}

.diagnostic dd {
    margin: 0;
    overflow-wrap: anywhere;
}

pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.warning {
    background: #fff8df;
    color: #725500;
    padding: 14px;
    border-radius: 8px;
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

    .diagnostic {
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
第1段階：Apache24 + PHP 基盤確認
</p>

<div class="warning">

<strong>最初に確認するもの</strong><br>

<code>GET index.php?action=health</code>

がHTTP 200 / JSONを返すこと。

</div>

</section>


<section class="card">

<h2>GET API</h2>

<div class="buttons">

<button
    type="button"
    id="healthButton"
>
GET health
</button>

<button
    type="button"
    id="csrfButton"
    class="secondary"
>
CSRF取得
</button>

</div>

<div
    id="getLoading"
    class="loading"
>
処理中…
</div>

<div
    id="getResult"
    class="result"
>
未実行
</div>

</section>


<section class="card">

<h2>POST API</h2>

<button
    type="button"
    id="postButton"
    class="green"
>
POST test
</button>

<div
    id="postLoading"
    class="loading"
>
処理中…
</div>

<div
    id="postResult"
    class="result"
>
未実行
</div>

</section>


<section class="card">

<h2>通信診断</h2>

<dl class="diagnostic">

<dt>API URL</dt>
<dd id="dUrl">-</dd>

<dt>HTTPメソッド</dt>
<dd id="dMethod">-</dd>

<dt>HTTPステータス</dt>
<dd id="dStatus">-</dd>

<dt>レスポンス有無</dt>
<dd id="dResponse">-</dd>

<dt>Content-Type</dt>
<dd id="dContentType">-</dd>

<dt>APIエラーコード</dt>
<dd id="dErrorCode">-</dd>

<dt>ブラウザOrigin</dt>
<dd id="dOrigin">-</dd>

<dt>詳細</dt>
<dd>
<pre id="dDetail"></pre>
</dd>

</dl>

</section>

</main>


<script>

(() => {

'use strict';


/*
 * ============================================================
 * 現在のindex.php
 * ============================================================
 */

const ENTRY_PATH =
    <?= $entryPathJson ?>;


/*
 * ============================================================
 * API URL生成
 * ============================================================
 */

function createApiUrl(action) {

    const url =
        new URL(
            ENTRY_PATH,
            window.location.origin
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

const dUrl =
    document.getElementById(
        'dUrl'
    );

const dMethod =
    document.getElementById(
        'dMethod'
    );

const dStatus =
    document.getElementById(
        'dStatus'
    );

const dResponse =
    document.getElementById(
        'dResponse'
    );

const dContentType =
    document.getElementById(
        'dContentType'
    );

const dErrorCode =
    document.getElementById(
        'dErrorCode'
    );

const dOrigin =
    document.getElementById(
        'dOrigin'
    );

const dDetail =
    document.getElementById(
        'dDetail'
    );


/*
 * ============================================================
 * 初期値
 * ============================================================
 */

dOrigin.textContent =
    window.location.origin;


let csrfToken = '';

let getProcessing = false;

let postProcessing = false;


/*
 * ============================================================
 * GETローディング
 * ============================================================
 */

function setGetLoading(value) {

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


/*
 * ============================================================
 * POSTローディング
 * ============================================================
 */

function setPostLoading(value) {

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
 * 診断初期化
 * ============================================================
 */

function resetDiagnostic(
    url,
    method
) {

    dUrl.textContent =
        url;

    dMethod.textContent =
        method;

    dStatus.textContent =
        '取得中';

    dResponse.textContent =
        '取得中';

    dContentType.textContent =
        '取得中';

    dErrorCode.textContent =
        '-';

    dDetail.textContent =
        '';
}


/*
 * ============================================================
 * JSON取得
 * ============================================================
 */

async function readJson(
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
            'レスポンスが空です。'
            + '\nHTTP '
            + response.status
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
            'JSON解析に失敗しました。'
            + '\nHTTP '
            + response.status
            + '\nContent-Type: '
            + contentType
            + '\nレスポンス先頭:\n'
            + text.slice(0, 1000)
        );
    }

    return data;
}


/*
 * ============================================================
 * GET
 * ============================================================
 */

async function apiGet(
    action
) {

    const url =
        createApiUrl(action);

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
                        'no-store',

                    redirect:
                        'follow'
                }
            );


        dStatus.textContent =
            String(
                response.status
            );

        dResponse.textContent =
            'あり';

        dContentType.textContent =
            response.headers.get(
                'content-type'
            ) || '(なし)';


        const data =
            await readJson(
                response
            );


        if (
            data
            && data.success === false
            && data.error
        ) {

            dErrorCode.textContent =
                data.error.code
                || 'API_ERROR';

        } else {

            dErrorCode.textContent =
                '-';
        }


        dDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );


        if (
            !response.ok
            || data.success !== true
        ) {

            throw new Error(
                data?.error?.message
                || 'API処理に失敗しました。'
            );
        }


        return data;

    } catch (error) {

        /*
         * fetchそのものが失敗した場合。
         *
         * HTTP responseが存在しない。
         */
        if (
            dResponse.textContent
            !== 'あり'
        ) {

            dStatus.textContent =
                '取得できませんでした';

            dResponse.textContent =
                'なし';

            dContentType.textContent =
                '取得できませんでした';

            dErrorCode.textContent =
                'NETWORK_ERROR';

            dDetail.textContent =
                (
                    error instanceof Error
                        ? error.message
                        : String(error)
                )
                + '\n\n'
                + 'HTTPレスポンスを取得できていません。'
                + '\n'
                + 'Apacheアクセスログ、PHPエラーログ、'
                + 'HTTPS証明書、VirtualHost、'
                + 'ブラウザDevTools Networkを確認してください。';
        }

        throw error;
    }
}


/*
 * ============================================================
 * POST
 * ============================================================
 */

async function apiPost(
    action
) {

    /*
     * CSRFを先に取得。
     */
    if (!csrfToken) {

        const csrfData =
            await apiGet(
                'csrf'
            );

        csrfToken =
            csrfData
                ?.data
                ?.csrfToken
                || '';

        if (!csrfToken) {

            throw new Error(
                'CSRFトークンを取得できませんでした。'
            );
        }
    }


    const url =
        createApiUrl(action);

    resetDiagnostic(
        url,
        'POST'
    );


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

                    redirect:
                        'follow',

                    body:
                        JSON.stringify({
                            action: action,
                            csrf_token:
                                csrfToken
                        })
                }
            );


        dStatus.textContent =
            String(
                response.status
            );

        dResponse.textContent =
            'あり';

        dContentType.textContent =
            response.headers.get(
                'content-type'
            ) || '(なし)';


        const data =
            await readJson(
                response
            );


        if (
            data
            && data.success === false
            && data.error
        ) {

            dErrorCode.textContent =
                data.error.code
                || 'API_ERROR';

        } else {

            dErrorCode.textContent =
                '-';
        }


        dDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );


        if (
            !response.ok
            || data.success !== true
        ) {

            throw new Error(
                data?.error?.message
                || 'POST API処理に失敗しました。'
            );
        }


        return data;

    } catch (error) {

        if (
            dResponse.textContent
            !== 'あり'
        ) {

            dStatus.textContent =
                '取得できませんでした';

            dResponse.textContent =
                'なし';

            dContentType.textContent =
                '取得できませんでした';

            dErrorCode.textContent =
                'NETWORK_ERROR';

            dDetail.textContent =
                (
                    error instanceof Error
                        ? error.message
                        : String(error)
                )
                + '\n\n'
                + 'HTTPレスポンスを取得できていません。';
        }

        throw error;
    }
}


/*
 * ============================================================
 * health
 * ============================================================
 */

healthButton.addEventListener(
    'click',
    async () => {

        if (getProcessing) {
            return;
        }

        setGetLoading(true);

        getResult.className =
            'result';

        getResult.textContent =
            'GET health 通信中…';

        try {

            const data =
                await apiGet(
                    'health'
                );

            getResult.className =
                'result ok';

            getResult.textContent =
                JSON.stringify(
                    data,
                    null,
                    2
                );

        } catch (error) {

            getResult.className =
                'result error';

            getResult.textContent =
                (
                    error instanceof Error
                        ? error.message
                        : String(error)
                );

        } finally {

            setGetLoading(false);
        }
    }
);


/*
 * ============================================================
 * CSRF
 * ============================================================
 */

csrfButton.addEventListener(
    'click',
    async () => {

        if (getProcessing) {
            return;
        }

        setGetLoading(true);

        getResult.className =
            'result';

        getResult.textContent =
            'CSRF取得中…';

        try {

            const data =
                await apiGet(
                    'csrf'
                );

            csrfToken =
                data
                    ?.data
                    ?.csrfToken
                    || '';

            getResult.className =
                'result ok';

            getResult.textContent =
                'CSRF取得成功\n'
                + 'トークン長: '
                + csrfToken.length;

        } catch (error) {

            getResult.className =
                'result error';

            getResult.textContent =
                (
                    error instanceof Error
                        ? error.message
                        : String(error)
                );

        } finally {

            setGetLoading(false);
        }
    }
);


/*
 * ============================================================
 * POST
 * ============================================================
 */

postButton.addEventListener(
    'click',
    async () => {

        if (postProcessing) {
            return;
        }

        setPostLoading(true);

        postResult.className =
            'result';

        postResult.textContent =
            'POST test 通信中…';

        try {

            const data =
                await apiPost(
                    'test_post'
                );

            postResult.className =
                'result ok';

            postResult.textContent =
                JSON.stringify(
                    data,
                    null,
                    2
                );

        } catch (error) {

            postResult.className =
                'result error';

            postResult.textContent =
                (
                    error instanceof Error
                        ? error.message
                        : String(error)
                );

        } finally {

            setPostLoading(false);
        }
    }
);


/*
 * ============================================================
 * history API
 * ============================================================
 */

window.addEventListener(
    'popstate',
    () => {

        /*
         * 第1段階ではURL状態の読み取りポイントのみ。
         *
         * pathnameには業務上の意味を持たせない。
         */

        const params =
            new URLSearchParams(
                window.location.search
            );

        const screen =
            params.get('screen');

        console.info(
            '[SURVEY_APP] popstate screen=',
            screen
        );
    }
);


/*
 * ============================================================
 * 初期ログ
 * ============================================================
 */

console.info(
    '[SURVEY_APP] entry path:',
    ENTRY_PATH
);

console.info(
    '[SURVEY_APP] health URL:',
    createApiUrl('health')
);

})();
</script>

</body>

</html>
<?php

    exit;
}


/* ============================================================
 * 18. ここからPOST/CSRF系
 *
 * healthはここへ来ない。
 * ============================================================
 */


/* ============================================================
 * 19. session_start
 * ============================================================
 */

if (
    session_status()
    !== PHP_SESSION_ACTIVE
) {

    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' =>
            (
                isset($_SERVER['HTTPS'])
                && strtolower(
                    (string)(
                        $_SERVER['HTTPS']
                    )
                ) !== 'off'
            ),
    ]);
}


/* ============================================================
 * 20. CSRF生成
 * ============================================================
 */

if (
    !isset(
        $_SESSION['csrf_token']
    )
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


/* ============================================================
 * 21. CSRF GET
 * ============================================================
 */

if (
    $method === 'GET'
    && $action === 'csrf'
) {

    successResponse(
        [
            'csrfToken' =>
                $_SESSION['csrf_token'],
        ],
        'CSRFトークン取得成功',
        200
    );
}


/* ============================================================
 * 22. POST action検証
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
}


/* ============================================================
 * 23. POST入力取得
 * ============================================================
 */

function getJsonBody(): array
{
    $contentType =
        strtolower(
            (string)(
                $_SERVER['CONTENT_TYPE']
                ?? ''
            )
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


    $json =
        json_decode(
            $raw,
            true
        );


    if (
        !is_array($json)
    ) {

        errorResponse(
            'INVALID_JSON',
            'JSON形式のリクエストが不正です。',
            400
        );
    }


    return $json;
}


/* ============================================================
 * 24. CSRF取得
 * ============================================================
 */

function getRequestCsrfToken(): string
{
    /*
     * Header
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
     * form
     */
    $post =
        $_POST['csrf_token']
        ?? '';

    if (
        is_string($post)
        && $post !== ''
    ) {

        return $post;
    }


    /*
     * JSON
     */
    $json =
        getJsonBody();

    if (
        isset(
            $json['csrf_token']
        )
        && is_string(
            $json['csrf_token']
        )
    ) {

        return $json['csrf_token'];
    }


    return '';
}


/* ============================================================
 * 25. CSRF検証
 * ============================================================
 */

function validateCsrf(): void
{
    $expected =
        $_SESSION['csrf_token']
        ?? '';

    $actual =
        getRequestCsrfToken();


    if (
        !is_string($expected)
        || $expected === ''
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
 * 26. POST CSRF
 * ============================================================
 */

if ($method === 'POST') {

    validateCsrf();
}


/* ============================================================
 * 27. POST test
 * ============================================================
 */

if (
    $method === 'POST'
    && $action === 'test_post'
) {

    successResponse(
        [
            'status' =>
                'ok',

            'method' =>
                'POST',

            'csrf' =>
                'validated',

            'phpVersion' =>
                PHP_VERSION,

            'time' =>
                date(DATE_ATOM),
        ],
        'POST API通信成功',
        200
    );
}


/* ============================================================
 * 28. 未実装
 * ============================================================
 */

if ($method === 'POST') {

    errorResponse(
        'NOT_IMPLEMENTED',
        'このAPIは第1段階では未実装です。',
        501
    );
}


/* ============================================================
 * 29. 想定外
 * ============================================================
 */

errorResponse(
    'UNEXPECTED_REQUEST',
    '想定外のリクエストです。',
    400
);
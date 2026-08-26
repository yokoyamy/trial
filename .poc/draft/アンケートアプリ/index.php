<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * 基盤実装・第1段階
 * ============================================================
 *
 * 実行環境:
 *   Apache24
 *   PHP 8.4 / 8.5
 *   DBなし
 *   JSON永続化
 *
 * 方針:
 *   - 公開PHP入口は index.php のみ
 *   - GET  = 参照
 *   - POST = 変更
 *   - API = index.php?action=xxx
 *   - 画面状態 = query string
 *   - pathnameに業務上の意味を持たせない
 *   - fetchに物理APIパスをハードコードしない
 *   - health APIはセッション・CSRFに依存しない
 *   - PHP Warning/Notice等でJSONレスポンスを破壊しない
 *   - PHP Fatal Errorをshutdown handlerで捕捉する
 *   - APIエラーは常にJSON
 * ============================================================
 */

const APP_TIMEZONE = 'Asia/Tokyo';
const APP_NAME = 'アンケート管理システム';

date_default_timezone_set(APP_TIMEZONE);

/*
 * PHPのWarning/Notice等を画面へ直接出さない。
 *
 * ただしログには記録する。
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

error_reporting(E_ALL);

/*
 * ============================================================
 * HTTP共通ヘッダー
 * ============================================================
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/*
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

    return str_contains(
        $contentType,
        'application/json'
    );
}

/*
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

/*
 * ============================================================
 * 共通成功レスポンス
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

/*
 * ============================================================
 * 共通エラーレスポンス
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

/*
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

/*
 * ============================================================
 * PHP Fatal Error対応
 *
 * exception_handlerだけではFatal Errorを捕捉できない。
 * そのためshutdown handlerも必ず登録する。
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

        if (!in_array(
            $error['type'],
            $fatalTypes,
            true
        )) {
            return;
        }

        error_log(
            '[APP_FATAL] '
            . ($error['message'] ?? '')
            . ' in '
            . ($error['file'] ?? '')
            . ':'
            . ($error['line'] ?? '')
        );

        /*
         * すでにレスポンスを出力している可能性があるため、
         * APIの場合だけ可能な範囲でJSONへ統一する。
         */
        if (isApiRequest()) {
            /*
             * 既存出力がある場合は破棄。
             * APIレスポンスをHTMLやPHPエラー文字列で
             * 破壊しない。
             */
            if (ob_get_level() > 0) {
                ob_clean();
            }

            http_response_code(500);

            header(
                'Content-Type: application/json; charset=utf-8'
            );

            echo encodeJson([
                'success' => false,
                'error' => [
                    'code' => 'PHP_FATAL_ERROR',
                    'message' =>
                        'サーバー内部エラーが発生しました。'
                        . 'サーバーログを確認してください。',
                ],
            ]);
        }
    }
);

/*
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

/*
 * ============================================================
 * HTTPメソッド
 * ============================================================
 */

$method = strtoupper(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
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

/*
 * ============================================================
 * action取得
 * ============================================================
 */

function getAction(): string
{
    $method = strtoupper(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
    );

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

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
        return trim($_POST['action']);
    }

    /*
     * application/json
     */
    $contentType = strtolower(
        (string)($_SERVER['CONTENT_TYPE'] ?? '')
    );

    if (str_contains(
        $contentType,
        'application/json'
    )) {
        $raw = file_get_contents('php://input');

        if (
            is_string($raw)
            && trim($raw) !== ''
        ) {
            $json = json_decode(
                $raw,
                true
            );

            if (
                is_array($json)
                && isset($json['action'])
                && is_string($json['action'])
            ) {
                return trim($json['action']);
            }
        }
    }

    return '';
}

/*
 * ============================================================
 * action
 * ============================================================
 */

$action = getAction();

/*
 * GETで許可するaction
 */

$allowedGetActions = [
    '',
    'health',
    'csrf',
];

/*
 * POSTで許可するaction
 */

$allowedPostActions = [
    'test_post',
];

/*
 * ============================================================
 * health API
 *
 * 重要:
 *
 * healthは「HTTP通信が成立しているか」を確認する
 * 最小APIなので、セッション・CSRF・JSON入力等に依存させない。
 *
 * これをaction検証直後に実行する。
 * ============================================================
 */

if (
    $method === 'GET'
    && $action === 'health'
) {
    successResponse(
        [
            'status' => 'ok',
            'application' => APP_NAME,
            'phpVersion' => PHP_VERSION,
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
            'https' =>
                (
                    (
                        $_SERVER['HTTPS']
                        ?? ''
                    ) !== ''
                    && strtolower(
                        (string)(
                            $_SERVER['HTTPS']
                            ?? ''
                        )
                    ) !== 'off'
                ),
            'time' => date(DATE_ATOM),
        ],
        '通信成功',
        200
    );
}

/*
 * ============================================================
 * GET action検証
 * ============================================================
 */

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

/*
 * ============================================================
 * 画面表示
 *
 * actionなしGET
 * ============================================================
 */

if (
    $method === 'GET'
    && $action === ''
) {
    /*
     * --------------------------------------------------------
     * 現在のindex.phpのURLを取得
     *
     * REQUEST_URIからquery stringを除去する。
     *
     * ここではpathnameを業務識別には使用していない。
     * 単に「現在表示しているindex.php自身」を取得する
     * ためだけに使用する。
     * --------------------------------------------------------
     */

    $requestUri = (string)(
        $_SERVER['REQUEST_URI']
        ?? ''
    );

    $entryPath = $requestUri;

    $queryPosition = strpos(
        $entryPath,
        '?'
    );

    if ($queryPosition !== false) {
        $entryPath = substr(
            $entryPath,
            0,
            $queryPosition
        );
    }

    if (
        $entryPath === ''
        || $entryPath[0] !== '/'
    ) {
        $entryPath = '/index.php';
    }

    /*
     * JSONとしてJavaScriptへ安全に渡す。
     */
    $entryPathJson = encodeJson(
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

html {
    min-height: 100%;
}

body {
    margin: 0;
    min-height: 100vh;
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

h1,
h2 {
    margin-top: 0;
}

h1 {
    font-size: 28px;
}

h2 {
    font-size: 20px;
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
    margin-top: 12px;
    color: #1769aa;
    font-weight: 700;
}

.loading.active {
    display: block;
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

.small {
    color: #666;
    font-size: 13px;
}

.diagnostic {
    display: grid;
    grid-template-columns:
        minmax(150px, max-content)
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
    padding: 14px;
    border-radius: 8px;
    background: #fff8df;
    color: #725500;
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
        gap: 4px;
    }

}

</style>
</head>

<body>

<main>

<section class="card">

<h1>アンケート管理システム</h1>

<p>
単一入口 <code>index.php</code>
</p>

<p class="small">
第1段階：Apache24 / PHP 基盤通信テスト
</p>

<div class="warning">

<strong>重要</strong><br>

この画面では、まず
<code>GET index.php?action=health</code>
がHTTPレスポンスを返すことだけを確認します。<br>

health APIでは、セッション、CSRF、JSONファイル、
データベース、外部API等を使用しません。

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
    aria-live="polite"
>
    処理中…
</div>

<div
    id="getResult"
    class="status"
></div>

</section>


<section class="card">

<h2>POST API</h2>

<div class="buttons">

<button
    type="button"
    id="postButton"
    class="success"
>
    POST test
</button>

</div>

<div
    id="postLoading"
    class="loading"
    aria-live="polite"
>
    処理中…
</div>

<div
    id="postResult"
    class="status"
></div>

</section>


<section class="card">

<h2>通信診断</h2>

<dl class="diagnostic">

<dt>API URL</dt>
<dd id="diagnosticUrl">-</dd>

<dt>HTTPメソッド</dt>
<dd id="diagnosticMethod">-</dd>

<dt>HTTPステータス</dt>
<dd id="diagnosticStatus">-</dd>

<dt>レスポンス有無</dt>
<dd id="diagnosticResponse">-</dd>

<dt>Content-Type</dt>
<dd id="diagnosticContentType">-</dd>

<dt>APIエラーコード</dt>
<dd id="diagnosticErrorCode">-</dd>

<dt>ブラウザOrigin</dt>
<dd id="diagnosticOrigin">-</dd>

<dt>現在ページURL</dt>
<dd id="diagnosticPageUrl">-</dd>

<dt>詳細</dt>
<dd>
<pre id="diagnosticDetail"></pre>
</dd>

</dl>

</section>

</main>


<script>
(() => {

    'use strict';

    /*
     * ========================================================
     * サーバーから渡された「現在のindex.php」
     * ========================================================
     *
     * 例:
     *
     * /gojacic/.poc/draft/アンケートアプリ/index.php
     *
     * JavaScriptでは、この値をAPI入口としてだけ使用する。
     *
     * /api/xxx
     * /xxx/index.php
     *
     * のような固定APIパスは作らない。
     */

    const ENTRY_PATH =
        <?= $entryPathJson ?>;


    /*
     * ========================================================
     * API URL生成
     * ========================================================
     *
     * 重要:
     *
     * window.location.origin +
     * ENTRY_PATH
     *
     * という構造にする。
     *
     * これにより、現在ページと同じoriginの
     * index.phpをAPI入口として使用する。
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

    const diagnosticOrigin =
        document.getElementById(
            'diagnosticOrigin'
        );

    const diagnosticPageUrl =
        document.getElementById(
            'diagnosticPageUrl'
        );

    const diagnosticDetail =
        document.getElementById(
            'diagnosticDetail'
        );


    /*
     * ========================================================
     * 初期診断情報
     * ========================================================
     */

    diagnosticOrigin.textContent =
        window.location.origin;

    diagnosticPageUrl.textContent =
        window.location.href;


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


    /*
     * ========================================================
     * 診断情報初期化
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

        diagnosticResponse.textContent =
            '取得中';

        diagnosticContentType.textContent =
            '取得中';

        diagnosticErrorCode.textContent =
            '-';

        diagnosticDetail.textContent =
            '';

    }


    /*
     * ========================================================
     * HTTPレスポンス診断
     * ========================================================
     */

    function setResponseDiagnostic(
        response,
        data
    ) {

        diagnosticStatus.textContent =
            String(response.status);

        diagnosticResponse.textContent =
            'あり';

        diagnosticContentType.textContent =
            response.headers.get(
                'content-type'
            ) || '(なし)';

        if (
            data
            && data.success === false
            && data.error
        ) {

            diagnosticErrorCode.textContent =
                data.error.code
                || 'API_ERROR';

        } else {

            diagnosticErrorCode.textContent =
                '-';

        }

        diagnosticDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );

    }


    /*
     * ========================================================
     * ネットワークエラー診断
     * ========================================================
     */

    function setNetworkDiagnostic(
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

        let message =
            error instanceof Error
                ? error.message
                : String(error);

        diagnosticDetail.textContent =
            [
                message,
                '',
                'この状態ではHTTPレスポンスを'
                + 'JavaScriptが取得できていません。',
                '',
                '確認対象:',
                '1. Apacheアクセスログ',
                '2. PHPエラーログ',
                '3. HTTPS証明書',
                '4. Apache VirtualHost',
                '5. ApacheのProxy/Redirect設定',
                '6. ブラウザのDevTools Network',
                '7. 実際のURLへの直接GET'
            ].join('\n');

    }


    /*
     * ========================================================
     * JSONレスポンス読み込み
     * ========================================================
     */

    async function parseJsonResponse(
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

        return data;

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
            createApiUrl(action);

        resetDiagnostic(
            url,
            'GET'
        );

        try {

            /*
             * redirect:
             *
             * 明示的にfollowする。
             *
             * APIがApache設定等によって別URLへ
             * リダイレクトされている場合は、
             * Networkタブで追跡できる。
             */
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

            const data =
                await parseJsonResponse(
                    response
                );

            setResponseDiagnostic(
                response,
                data
            );

            if (
                !response.ok
                || data.success !== true
            ) {

                const message =
                    data?.error?.message
                    || 'API処理に失敗しました。';

                throw new Error(
                    message
                );

            }

            return data;

        } catch (error) {

            /*
             * fetch()そのものが失敗した場合、
             * responseは存在しない。
             *
             * HTTP 4xx/5xxなら上ですでに
             * response診断を設定している。
             */
            if (
                diagnosticResponse.textContent
                !== 'あり'
            ) {

                setNetworkDiagnostic(
                    error
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
                'CSRFトークンが取得できませんでした。'
            );

        }

        csrfToken =
            token;

        return token;

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
         * CSRFがない場合は先にGETで取得。
         */
        if (!csrfToken) {

            await getCsrfToken();

        }

        const url =
            createApiUrl(action);

        resetDiagnostic(
            url,
            'POST'
        );

        const payload = {
            action: action,
            csrf_token: csrfToken,
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

                        redirect:
                            'follow',

                        body:
                            JSON.stringify(
                                payload
                            )
                    }
                );

            const data =
                await parseJsonResponse(
                    response
                );

            setResponseDiagnostic(
                response,
                data
            );

            if (
                !response.ok
                || data.success !== true
            ) {

                if (
                    data?.error?.code
                    === 'CSRF_INVALID'
                ) {

                    csrfToken = '';

                }

                const message =
                    data?.error?.message
                    || 'POST API処理に失敗しました。';

                throw new Error(
                    message
                );

            }

            return data;

        } catch (error) {

            if (
                diagnosticResponse.textContent
                !== 'あり'
            ) {

                setNetworkDiagnostic(
                    error
                );

            }

            throw error;

        }

    }


    /*
     * ========================================================
     * healthテスト
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
            'GET health 通信中…';

        try {

            const data =
                await requestGet(
                    'health'
                );

            getResult.className =
                'status ok';

            getResult.textContent =
                'GET health 成功\n\n'
                + JSON.stringify(
                    data,
                    null,
                    2
                );

        } catch (error) {

            getResult.className =
                'status error';

            getResult.textContent =
                'GET health 失敗\n\n'
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
     * ========================================================
     * CSRFテスト
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
            'CSRF取得中…';

        try {

            const token =
                await getCsrfToken();

            getResult.className =
                'status ok';

            getResult.textContent =
                'CSRF取得成功\n\n'
                + 'トークン長: '
                + token.length
                + '\n'
                + 'トークン本体は表示しません。';

        } catch (error) {

            getResult.className =
                'status error';

            getResult.textContent =
                'CSRF取得失敗\n\n'
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
     * ========================================================
     * POSTテスト
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
            'POST通信中…';

        try {

            const data =
                await requestPost(
                    'test_post'
                );

            postResult.className =
                'status ok';

            postResult.textContent =
                'POST API成功\n\n'
                + JSON.stringify(
                    data,
                    null,
                    2
                );

        } catch (error) {

            postResult.className =
                'status error';

            postResult.textContent =
                'POST API失敗\n\n'
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


    /*
     * ========================================================
     * history API
     *
     * 第1段階では画面状態の基礎だけ実装。
     * pathnameには業務上の意味を持たせない。
     * ========================================================
     */

    window.addEventListener(
        'popstate',
        () => {

            /*
             * 現段階では画面再構築のための
             * URL解析ポイントだけを用意する。
             *
             * 業務識別にはpathnameを使用しない。
             */
            const params =
                new URLSearchParams(
                    window.location.search
                );

            const screen =
                params.get('screen');

            if (screen === null) {
                return;
            }

            /*
             * 後続段階でscreenに応じた
             * 画面再構築をここへ実装する。
             */
        }
    );


    /*
     * ========================================================
     * デバッグ用
     *
     * consoleにもAPI入口を出す。
     * ========================================================
     */

    console.info(
        '[APP] entry path:',
        ENTRY_PATH
    );

    console.info(
        '[APP] health URL:',
        createApiUrl('health')
    );

})();
</script>

</body>
</html>
<?php

    exit;
}

/*
 * ============================================================
 * ここからPOST用共通処理
 *
 * healthはここへ来ない。
 * つまりhealth APIはsession_start()等に依存しない。
 * ============================================================
 */


/*
 * ============================================================
 * セッション開始
 * ============================================================
 */

if (session_status() !== PHP_SESSION_ACTIVE) {

    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' =>
            (
                isset($_SERVER['HTTPS'])
                && strtolower(
                    (string)$_SERVER['HTTPS']
                ) !== 'off'
            ),
    ]);

}


/*
 * ============================================================
 * CSRFトークン生成
 * ============================================================
 */

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

$csrfToken =
    $_SESSION['csrf_token'];


/*
 * ============================================================
 * CSRF取得
 *
 * GET / csrf
 * ============================================================
 */

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


/*
 * ============================================================
 * POST action検証
 * ============================================================
 */

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

}


/*
 * ============================================================
 * CSRFトークン取得
 * ============================================================
 */

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
     * form POST
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
     * JSON POST
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
                && isset(
                    $json['csrf_token']
                )
                && is_string(
                    $json['csrf_token']
                )
            ) {

                return
                    $json['csrf_token'];

            }

        }

    }

    return '';
}


/*
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


/*
 * ============================================================
 * POST CSRF検証
 * ============================================================
 */

if ($method === 'POST') {

    validateCsrfToken();

}


/*
 * ============================================================
 * POST: test_post
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
            'phpVersion' => PHP_VERSION,
            'time' => date(DATE_ATOM),
        ],
        'POST API通信成功'
    );

}


/*
 * ============================================================
 * 未実装POST
 *
 * 現段階では業務機能を未実装として明示する。
 * ============================================================
 */

if ($method === 'POST') {

    errorResponse(
        'NOT_IMPLEMENTED',
        'このAPIは第1段階では未実装です。',
        501
    );

}


/*
 * ============================================================
 * 想定外到達
 * ============================================================
 */

errorResponse(
    'UNEXPECTED_REQUEST',
    '想定外のリクエストです。',
    400
);
<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * 単一入口: index.php
 *
 * 基盤テスト版
 *
 * 実行環境:
 * - Apache24
 * - PHP 8.4 / 8.5
 * - DBなし
 * - JSON永続化予定
 *
 * 重要:
 * - pathnameに業務上の意味を持たせない
 * - APIも画面もこのindex.phpを単一入口とする
 * - GETは参照、POSTは変更
 * - POSTはCSRF必須
 */

const APP_TIMEZONE = 'Asia/Tokyo';

date_default_timezone_set(APP_TIMEZONE);

/* =========================================================
 * 共通HTTPヘッダー
 * ========================================================= */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* =========================================================
 * 共通レスポンス
 * ========================================================= */

function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');

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

    header('Content-Type: application/json; charset=utf-8');

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

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* =========================================================
 * 予期しないエラーの共通処理
 * ========================================================= */

function isApiRequest(): bool
{
    return isset($_GET['action'])
        || isset($_POST['action'])
        || str_contains(
            strtolower($_SERVER['CONTENT_TYPE'] ?? ''),
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
        echo '<html lang="ja"><meta charset="utf-8">';
        echo '<title>サーバーエラー</title>';
        echo '<body>';
        echo '<h1>サーバーエラー</h1>';
        echo '<p>サーバー内部で予期しないエラーが発生しました。</p>';
        echo '</body></html>';

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
    $_SESSION['csrf_token'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_token'];

/**
 * POSTのCSRFトークン取得
 */
function getRequestCsrfToken(): string
{
    $headerToken =
        $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        is_string($headerToken)
        && $headerToken !== ''
    ) {
        return $headerToken;
    }

    $postToken =
        $_POST['csrf_token'] ?? '';

    if (
        is_string($postToken)
        && $postToken !== ''
    ) {
        return $postToken;
    }

    $contentType =
        strtolower(
            $_SERVER['CONTENT_TYPE'] ?? ''
        );

    if (
        str_contains(
            $contentType,
            'application/json'
        )
    ) {
        $raw = file_get_contents(
            'php://input'
        );

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

/**
 * CSRF検証
 */
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

    $contentType =
        strtolower(
            $_SERVER['CONTENT_TYPE'] ?? ''
        );

    if (
        str_contains(
            $contentType,
            'application/json'
        )
    ) {
        $raw = file_get_contents(
            'php://input'
        );

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
                return trim(
                    $json['action']
                );
            }
        }
    }

    return '';
}

/* =========================================================
 * action定義
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

/*
 * GET / POSTのactionを検証。
 *
 * ただし、画面表示時のactionなしGETは許可。
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

    /*
     * POSTだけCSRF検証。
     */
    validateCsrfToken();
}

/* =========================================================
 * GET API: health
 * ========================================================= */

if ($method === 'GET' && $action === 'health') {
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
 * GET API: csrf
 *
 * CSRF取得自体はPOSTではないため、
 * CSRF検証を要求しない。
 * ========================================================= */

if ($method === 'GET' && $action === 'csrf') {
    successResponse(
        [
            'csrfToken' => $csrfToken,
        ],
        'CSRFトークン取得成功'
    );
}

/* =========================================================
 * POST API: test_post
 *
 * 基盤確認専用。
 * CSRF検証が通れば成功する。
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
 * GETの画面表示
 * ========================================================= */

if (
    $method === 'GET'
    && $action === ''
) {
    $currentUrl =
        $_SERVER['REQUEST_URI']
        ?? '/index.php';

    /*
     * 現在のindex.php URLをPHPから明示。
     *
     * JavaScriptでpathnameを業務識別に使わない。
     * 物理ディレクトリ名もハードコードしない。
     */
    $entryUrl = strtok(
        $currentUrl,
        '?'
    );

    if (
        !is_string($entryUrl)
        || $entryUrl === ''
    ) {
        $entryUrl = '/index.php';
    }

    /*
     * ブラウザ側で使用するURL。
     *
     * originは現在ページのoriginを使用する。
     * 外部URLにはしない。
     */
    $entryUrlJson = json_encode(
        $entryUrl,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );

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

dl {
    display: grid;
    grid-template-columns:
        minmax(130px, max-content)
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
単一入口 <code>index.php</code>
基盤通信テスト
</p>

<p class="small">
現在のAPI入口:
<span id="entryUrlText"></span>
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

<h2>POST API</h2>

<p class="small">
先にCSRF取得を実行しなくても、
このボタン自身が必要な場合はCSRFを取得してからPOSTします。
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

<pre
    id="diagnosticDetail"
></pre>

</section>

</main>

<script>
(() => {
    'use strict';

    /*
     * ========================================================
     * サーバーから明示された単一入口
     * ========================================================
     */

    const ENTRY_PATH =
        <?= $entryUrlJson ?>;

    /*
     * 重要:
     *
     * redirect: 'same-origin'
     *
     * は使用しない。
     *
     * credentials: 'same-origin'
     *
     * は使用する。
     */

    const entryUrl =
        new URL(
            ENTRY_PATH,
            window.location.origin
        );

    /*
     * API URL生成を一箇所に集約。
     *
     * pathnameを業務識別には使用しない。
     */
    function apiUrl(action) {
        const url =
            new URL(entryUrl.toString());

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

    const diagnosticDetail =
        document.getElementById(
            'diagnosticDetail'
        );

    entryUrlText.textContent =
        entryUrl.toString();

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
            data = JSON.parse(text);
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

        return {
            data,
            contentType,
            text
        };
    }

    /*
     * ========================================================
     * GET API共通
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
            /*
             * redirectは指定しない。
             *
             * credentialsのみ指定。
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
                            'same-origin'
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
                || data.success !== true
            ) {
                setDiagnosticApiError(
                    response,
                    data
                );

                const message =
                    data?.error?.message
                    || 'API処理に失敗しました。';

                throw new Error(
                    message
                );
            }

            setDiagnosticSuccess(
                response,
                data
            );

            return data;

        } catch (error) {
            /*
             * HTTPレスポンスを受け取った後の
             * APIエラーと、
             * fetch自体のネットワークエラーを
             * 区別する。
             */

            if (
                !(error instanceof Error)
                || !diagnosticDetail.textContent
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

        csrfToken = token;

        return csrfToken;
    }

    /*
     * ========================================================
     * POST API共通
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

        /*
         * CSRFがなければ自動取得。
         *
         * 「先にCSRF取得テストを実行してください」
         * で止めない。
         */
        if (!csrfToken) {
            await getCsrfToken();
        }

        const payload = {
            action,
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
                || data.success !== true
            ) {
                setDiagnosticApiError(
                    response,
                    data
                );

                /*
                 * CSRF期限切れ等の場合は
                 * トークンを破棄。
                 */
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

            setDiagnosticSuccess(
                response,
                data
            );

            return data;

        } catch (error) {

            /*
             * responseが存在しない場合だけ
             * NETWORK_ERRORとして扱う。
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

})();
</script>

</body>
</html>
<?php

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
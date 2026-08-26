<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * 単一入口 index.php
 *
 * 基盤修正版
 *
 * 実行環境:
 *   Apache24
 *   PHP 8.4 / 8.5
 *   DBなし
 *   JSON永続化
 *
 * 重要:
 *   - 公開PHP入口は index.php のみ
 *   - GET = 参照
 *   - POST = 変更
 *   - APIは必ずJSON
 *   - PHPエラーでJSONレスポンスを破壊しない
 *   - JSから物理APIパスをハードコードしない
 *   - URL query stringを画面状態の正規情報とする
 * ============================================================
 */

const APP_TIMEZONE = 'Asia/Tokyo';
const API_TIMEOUT_MS = 15000;

date_default_timezone_set(APP_TIMEZONE);

/**
 * ------------------------------------------------------------
 * API判定
 * ------------------------------------------------------------
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

/**
 * ------------------------------------------------------------
 * 共通HTTPヘッダー
 * ------------------------------------------------------------
 */
function sendCommonHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

/**
 * ------------------------------------------------------------
 * JSONエンコード
 * ------------------------------------------------------------
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
        return '{"success":false,"error":{"code":"JSON_ENCODE_ERROR","message":"JSON生成に失敗しました。"}}';
    }

    return $json;
}

/**
 * ------------------------------------------------------------
 * 成功レスポンス
 * ------------------------------------------------------------
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

/**
 * ------------------------------------------------------------
 * エラーレスポンス
 * ------------------------------------------------------------
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

/**
 * ------------------------------------------------------------
 * HTMLエスケープ
 * ------------------------------------------------------------
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
 * ------------------------------------------------------------
 * 予期しない例外
 * ------------------------------------------------------------
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

/**
 * ------------------------------------------------------------
 * PHP Fatal Error対策
 *
 * set_exception_handler()だけではFatal Errorを
 * すべてAPI JSONへ変換できないためshutdown handlerを使用。
 * ------------------------------------------------------------
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
            . ' at '
            . ($error['file'] ?? '')
            . ':'
            . ($error['line'] ?? '')
        );

        if (!isApiRequest()) {
            return;
        }

        /**
         * すでに何か出力されている場合、
         * JSONレスポンスを破壊するためバッファを消す。
         */
        while (ob_get_level() > 0) {
            ob_end_clean();
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
                    . ' PHPエラーログを確認してください。',
            ],
        ]);
    }
);

/**
 * ------------------------------------------------------------
 * 出力バッファ開始
 *
 * Warning / Notice等がAPI JSONを直接壊すことを防ぐ。
 * ------------------------------------------------------------
 */
ob_start();

/**
 * ------------------------------------------------------------
 * 共通ヘッダー
 * ------------------------------------------------------------
 */
sendCommonHeaders();

/**
 * ------------------------------------------------------------
 * HTTPメソッド
 * ------------------------------------------------------------
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

/**
 * ------------------------------------------------------------
 * JSON POST本文
 * ------------------------------------------------------------
 */
function getJsonBody(): array
{
    static $loaded = false;
    static $body = [];

    if ($loaded) {
        return $body;
    }

    $loaded = true;

    $contentType = strtolower(
        (string)($_SERVER['CONTENT_TYPE'] ?? '')
    );

    if (!str_contains(
        $contentType,
        'application/json'
    )) {
        return [];
    }

    $raw = file_get_contents('php://input');

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    try {
        $decoded = json_decode(
            $raw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable $e) {

        error_log(
            '[JSON_REQUEST_ERROR] '
            . $e->getMessage()
        );

        errorResponse(
            'INVALID_JSON',
            'POSTされたJSONを解析できません。',
            400
        );
    }

    if (!is_array($decoded)) {
        errorResponse(
            'INVALID_JSON',
            'POSTされたJSONの形式が不正です。',
            400
        );
    }

    $body = $decoded;

    return $body;
}

/**
 * ------------------------------------------------------------
 * action取得
 * ------------------------------------------------------------
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
     * application/x-www-form-urlencoded
     */
    if (
        isset($_POST['action'])
        && is_string($_POST['action'])
    ) {
        return trim($_POST['action']);
    }

    /**
     * application/json
     */
    $json = getJsonBody();

    if (
        isset($json['action'])
        && is_string($json['action'])
    ) {
        return trim($json['action']);
    }

    return '';
}

/**
 * ------------------------------------------------------------
 * セッション
 *
 * health等のGET基盤テストではセッションを開始しない。
 * ------------------------------------------------------------
 */
function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $result = session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);

    if ($result === false) {
        errorResponse(
            'SESSION_START_FAILED',
            'セッションを開始できません。',
            500
        );
    }
}

/**
 * ------------------------------------------------------------
 * CSRFトークン
 * ------------------------------------------------------------
 */
function getCsrfToken(): string
{
    startAppSession();

    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || $_SESSION['csrf_token'] === ''
    ) {
        $_SESSION['csrf_token'] =
            bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * ------------------------------------------------------------
 * リクエストCSRF取得
 * ------------------------------------------------------------
 */
function getRequestCsrfToken(): string
{
    /**
     * HTTP header
     */
    $headerToken =
        $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        is_string($headerToken)
        && $headerToken !== ''
    ) {
        return $headerToken;
    }

    /**
     * form
     */
    if (
        isset($_POST['csrf_token'])
        && is_string($_POST['csrf_token'])
    ) {
        return $_POST['csrf_token'];
    }

    /**
     * JSON
     */
    $json = getJsonBody();

    if (
        isset($json['csrf_token'])
        && is_string($json['csrf_token'])
    ) {
        return $json['csrf_token'];
    }

    return '';
}

/**
 * ------------------------------------------------------------
 * CSRF検証
 * ------------------------------------------------------------
 */
function validateCsrf(): void
{
    $expected = getCsrfToken();
    $actual = getRequestCsrfToken();

    if (
        $actual === ''
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
 * ------------------------------------------------------------
 * 許可GET action
 * ------------------------------------------------------------
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

/**
 * ------------------------------------------------------------
 * 許可POST action
 * ------------------------------------------------------------
 */
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
 * ------------------------------------------------------------
 * action
 * ------------------------------------------------------------
 */
$action = getAction();

/**
 * ------------------------------------------------------------
 * actionなしGET
 *
 * => HTML画面
 * ------------------------------------------------------------
 */
if (
    $method === 'GET'
    && $action === ''
) {
    /**
     * HTML画面ではここでセッションを開始。
     */
    $csrfToken = getCsrfToken();

    /**
     * 現在のindex.phpのURL。
     *
     * REQUEST_URIからquery stringだけを除去。
     * JS側ではこの値を業務識別に使用しない。
     */
    $requestUri =
        (string)($_SERVER['REQUEST_URI'] ?? '');

    $entryPath =
        parse_url(
            $requestUri,
            PHP_URL_PATH
        );

    if (
        !is_string($entryPath)
        || $entryPath === ''
    ) {
        $entryPath = '/index.php';
    }

    /**
     * JavaScriptへ渡す。
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
        $entryPathJson = '"/index.php"';
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
    background: #b56a00;
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

.small {
    color: #666;
    font-size: 13px;
}

.diagnostic {
    display: grid;
    grid-template-columns:
        minmax(180px, max-content)
        1fr;
    gap: 8px 16px;
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

.screen {
    display: none;
}

.screen.active {
    display: block;
}

.nav {
    margin-bottom: 20px;
}

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    background: #e8eef5;
    color: #234;
    font-size: 12px;
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
単一入口 <code>index.php</code>
</p>

<p class="small">
基盤通信テスト版
</p>

<p>
<span
    id="currentScreenBadge"
    class="badge"
>
screen=admin
</span>
</p>

</section>

<section class="card nav">

<h2>画面状態</h2>

<div class="buttons">

<button
    type="button"
    data-screen="admin"
>
管理画面
</button>

<button
    type="button"
    data-screen="survey"
>
アンケート
</button>

<button
    type="button"
    data-screen="answer"
>
回答
</button>

</div>

</section>

<section
    id="screen-admin"
    class="screen active"
>

<div class="card">

<h2>管理画面</h2>

<p>
URL:
</p>

<pre id="adminUrl"></pre>

</div>

</section>

<section
    id="screen-survey"
    class="screen"
>

<div class="card">

<h2>アンケート画面</h2>

<p>
URLの <code>surveyId</code> から状態を復元します。
</p>

<pre id="surveyState"></pre>

</div>

</section>

<section
    id="screen-answer"
    class="screen"
>

<div class="card">

<h2>回答画面</h2>

<p>
URLの <code>surveyId</code> /
<code>customerId</code>
から状態を復元します。
</p>

<pre id="answerState"></pre>

</div>

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
待機中
</div>

</section>

<section class="card">

<h2>POST API</h2>

<p class="small">
CSRFトークンがない場合は自動取得します。
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
待機中
</div>

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

<dt>Content-Type</dt>
<dd id="diagnosticContentType">-</dd>

<dt>APIエラーコード</dt>
<dd id="diagnosticErrorCode">-</dd>

<dt>レスポンス有無</dt>
<dd id="diagnosticResponse">-</dd>

<dt>レスポンスサイズ</dt>
<dd id="diagnosticSize">-</dd>

<dt>ブラウザOrigin</dt>
<dd id="diagnosticOrigin">-</dd>

<dt>タイムアウト</dt>
<dd>
<?= API_TIMEOUT_MS ?> ms
</dd>

</dl>

<pre id="diagnosticDetail"></pre>

</section>

</main>

<script>
(() => {
    'use strict';

    /**
     * ========================================================
     * 単一入口
     *
     * PHPが現在のindex.phpのpathnameだけを提供。
     *
     * JavaScriptは物理ディレクトリを知らない。
     * ========================================================
     */
    const ENTRY_PATH =
        <?= $entryPathJson ?>;

    /**
     * ========================================================
     * API URL
     *
     * 絶対URLを組み立てない。
     *
     * 現在ページと同一originの相対URLを使用する。
     *
     * 例:
     *
     *   index.php?action=health
     *
     * となる。
     * ========================================================
     */
    function apiUrl(action) {

        const params =
            new URLSearchParams();

        params.set(
            'action',
            action
        );

        return (
            ENTRY_PATH
            + '?'
            + params.toString()
        );
    }

    /**
     * ========================================================
     * URL状態
     * ========================================================
     */
    function getUrlState() {

        const params =
            new URLSearchParams(
                window.location.search
            );

        return {
            screen:
                params.get('screen')
                || 'admin',

            surveyId:
                params.get('surveyId')
                || '',

            customerId:
                params.get('customerId')
                || ''
        };
    }

    function buildScreenUrl(
        screen,
        surveyId = '',
        customerId = ''
    ) {

        const url =
            new URL(
                window.location.href
            );

        url.pathname =
            ENTRY_PATH;

        url.search = '';

        url.searchParams.set(
            'screen',
            screen
        );

        if (surveyId !== '') {
            url.searchParams.set(
                'surveyId',
                surveyId
            );
        }

        if (customerId !== '') {
            url.searchParams.set(
                'customerId',
                customerId
            );
        }

        return (
            url.pathname
            + url.search
        );
    }

    /**
     * ========================================================
     * 画面状態再構築
     * ========================================================
     */
    function renderScreenFromUrl() {

        const state =
            getUrlState();

        document
            .querySelectorAll('.screen')
            .forEach(
                element => {
                    element.classList.remove(
                        'active'
                    );
                }
            );

        const target =
            document.getElementById(
                'screen-' + state.screen
            );

        const screen =
            target
            ? state.screen
            : 'admin';

        const actualTarget =
            document.getElementById(
                'screen-' + screen
            );

        if (actualTarget) {
            actualTarget.classList.add(
                'active'
            );
        }

        document
            .getElementById(
                'currentScreenBadge'
            )
            .textContent =
                'screen=' + screen;

        document
            .getElementById(
                'adminUrl'
            )
            .textContent =
                window.location.href;

        document
            .getElementById(
                'surveyState'
            )
            .textContent =
                JSON.stringify(
                    {
                        screen:
                            state.screen,
                        surveyId:
                            state.surveyId
                    },
                    null,
                    2
                );

        document
            .getElementById(
                'answerState'
            )
            .textContent =
                JSON.stringify(
                    {
                        screen:
                            state.screen,
                        surveyId:
                            state.surveyId,
                        customerId:
                            state.customerId
                    },
                    null,
                    2
                );
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

    const diagnosticDetail =
        document.getElementById(
            'diagnosticDetail'
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
     * Loading
     * ========================================================
     */
    function setGetProcessing(value) {

        getProcessing = value;

        healthButton.disabled =
            value;

        csrfButton.disabled =
            value;

        getLoading
            .classList
            .toggle(
                'active',
                value
            );
    }

    function setPostProcessing(value) {

        postProcessing = value;

        postButton.disabled =
            value;

        postLoading
            .classList
            .toggle(
                'active',
                value
            );
    }

    /**
     * ========================================================
     * Diagnostic
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
            '確認中';

        diagnosticSize.textContent =
            '-';

        diagnosticOrigin.textContent =
            window.location.origin;

        diagnosticDetail.textContent =
            '';
    }

    function setDiagnosticResponse(
        response,
        text,
        data
    ) {

        diagnosticStatus.textContent =
            String(
                response.status
            );

        diagnosticContentType.textContent =
            response.headers.get(
                'content-type'
            ) || '(なし)';

        diagnosticResponse.textContent =
            'あり';

        diagnosticSize.textContent =
            String(
                new TextEncoder()
                    .encode(text)
                    .length
            ) + ' bytes';

        diagnosticErrorCode.textContent =
            data?.error?.code
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

        diagnosticContentType.textContent =
            '取得できませんでした';

        diagnosticErrorCode.textContent =
            'NETWORK_ERROR';

        diagnosticResponse.textContent =
            'なし';

        diagnosticSize.textContent =
            '-';

        diagnosticDetail.textContent =
            (
                error instanceof Error
                    ? error.message
                    : String(error)
            )
            + '\n\n'
            + 'このエラーはHTTPレスポンスを'
            + '受信できなかったことを示します。'
            + '\n'
            + 'HTTPS証明書、Apache24、'
            + 'ポート、接続先、'
            + 'ブラウザのネットワークエラーを'
            + '確認してください。';
    }

    /**
     * ========================================================
     * Fetch
     *
     * timeoutをAbortControllerで実装。
     * ========================================================
     */
    async function fetchWithTimeout(
        url,
        options = {},
        timeoutMs = <?= API_TIMEOUT_MS ?>
    ) {

        const controller =
            new AbortController();

        const timer =
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
                    credentials:
                        'same-origin',
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
                    '通信がタイムアウトしました。'
                    + ' Apache24、PHP、'
                    + 'ネットワーク接続を確認してください。'
                );
            }

            throw error;

        } finally {

            window.clearTimeout(
                timer
            );
        }
    }

    /**
     * ========================================================
     * JSON Response
     * ========================================================
     */
    async function parseApiResponse(
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
                + text.slice(0, 500)
            );
        }

        setDiagnosticResponse(
            response,
            text,
            data
        );

        return {
            response,
            data,
            text
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

            const result =
                await fetchWithTimeout(
                    url,
                    {
                        method: 'GET',
                        headers: {
                            'Accept':
                                'application/json'
                        },
                        cache: 'no-store'
                    }
                );

            const parsed =
                await parseApiResponse(
                    result
                );

            /**
             * ここが元コードの重要なバグ修正。
             *
             * 元コード:
             *
             * const data = parsed.data;
             * data.success !== true
             *
             * ではなく、
             *
             * parsed.data.success
             *
             * を確認する。
             */
            if (
                !parsed.response.ok
                || parsed.data?.success !== true
            ) {

                diagnosticErrorCode.textContent =
                    parsed.data?.error?.code
                    || 'API_ERROR';

                throw new Error(
                    parsed.data?.error?.message
                    || 'API処理に失敗しました。'
                );
            }

            return parsed.data;

        } catch (error) {

            /**
             * HTTPレスポンスを受信していない場合だけ
             * NETWORK_ERROR。
             */
            if (
                diagnosticResponse.textContent
                !== 'あり'
            ) {
                setDiagnosticNetworkError(
                    error
                );
            }

            throw error;
        }
    }

    /**
     * ========================================================
     * CSRF
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
                + 'CSRFトークンがありません。'
            );
        }

        csrfToken =
            token;

        return token;
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

        if (!csrfToken) {
            await getCsrfToken();
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
                        body:
                            JSON.stringify(
                                payload
                            )
                    }
                );

            const parsed =
                await parseApiResponse(
                    response
                );

            if (
                !response.ok
                || parsed.data?.success !== true
            ) {

                diagnosticErrorCode.textContent =
                    parsed.data?.error?.code
                    || 'API_ERROR';

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
                !== 'あり'
            ) {
                setDiagnosticNetworkError(
                    error
                );
            }

            throw error;
        }
    }

    /**
     * ========================================================
     * Health
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

    /**
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

    /**
     * ========================================================
     * history API
     * ========================================================
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

                        const screen =
                            button.dataset.screen
                            || 'admin';

                        let url;

                        if (
                            screen === 'survey'
                        ) {

                            url =
                                buildScreenUrl(
                                    'survey',
                                    'survey_demo'
                                );

                        } else if (
                            screen === 'answer'
                        ) {

                            url =
                                buildScreenUrl(
                                    'answer',
                                    'survey_demo',
                                    'customer_demo'
                                );

                        } else {

                            url =
                                buildScreenUrl(
                                    'admin'
                                );
                        }

                        window.history.pushState(
                            {},
                            '',
                            url
                        );

                        renderScreenFromUrl();
                    }
                );
            }
        );

    /**
     * ブラウザ戻る/進む
     */
    window.addEventListener(
        'popstate',
        () => {
            renderScreenFromUrl();
        }
    );

    /**
     * 初期画面
     */
    renderScreenFromUrl();

    /**
     * APIイベント
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

    /**
     * HTML表示終了。
     */
    ob_end_flush();

    exit;
}

/**
 * ------------------------------------------------------------
 * GET action検証
 * ------------------------------------------------------------
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

/**
 * ------------------------------------------------------------
 * POST action検証
 * ------------------------------------------------------------
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

    /**
     * POST変更処理では必ずCSRF。
     */
    validateCsrf();
}

/**
 * ============================================================
 * GET health
 *
 * セッションを使用しない。
 *
 * これが最重要のApache/PHP疎通確認API。
 * ============================================================
 */
if (
    $method === 'GET'
    && $action === 'health'
) {

    successResponse(
        [
            'status' =>
                'ok',

            'phpVersion' =>
                PHP_VERSION,

            'sapi' =>
                PHP_SAPI,

            'serverSoftware' =>
                (string)(
                    $_SERVER['SERVER_SOFTWARE']
                    ?? ''
                ),

            'https' =>
                (
                    isset($_SERVER['HTTPS'])
                    && $_SERVER['HTTPS'] !== ''
                    && $_SERVER['HTTPS'] !== 'off'
                ),

            'method' =>
                'GET',

            'time' =>
                date(DATE_ATOM),
        ],
        '通信成功'
    );
}

/**
 * ============================================================
 * GET csrf
 * ============================================================
 */
if (
    $method === 'GET'
    && $action === 'csrf'
) {

    $token =
        getCsrfToken();

    successResponse(
        [
            'csrfToken' =>
                $token,
        ],
        'CSRFトークン取得成功'
    );
}

/**
 * ============================================================
 * POST test_post
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

            'time' =>
                date(DATE_ATOM),
        ],
        'POST API通信成功'
    );
}

/**
 * ============================================================
 * その他の業務API
 *
 * 現段階では未実装。
 *
 * 未実装を実装済みとして扱わない。
 * ============================================================
 */
errorResponse(
    'NOT_IMPLEMENTED',
    'この業務操作はまだ実装されていません。',
    501
);
<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * 単一入口 index.php
 *
 * 第1段階：単一入口・HTTP通信基盤
 *
 * 実行環境：
 *   Apache24
 *   PHP 8.4 / 8.5
 *   DBなし
 *   JSON永続化
 *
 * 方針：
 *   - 公開PHP入口はindex.phpのみ
 *   - GET = 参照
 *   - POST = 変更
 *   - POSTはCSRF必須
 *   - APIは常にJSON
 *   - PHP Warning / Notice等でJSONを破壊しない
 *   - 予期しない例外は共通JSONエラー
 *   - Fatal Errorを可能な範囲で共通処理
 *   - fetchの物理パスハードコード禁止
 *   - API入口は現在のindex.phpを基準
 *   - fetchタイムアウトを明示
 * ============================================================
 */


/* ============================================================
 * 基本設定
 * ============================================================
 */

const APP_TIMEZONE = 'Asia/Tokyo';

/**
 * JavaScript fetchタイムアウト。
 *
 * ここを必ず定義する。
 *
 * 「API_TIMEOUT_MS is not defined」
 * を発生させない。
 */
const API_TIMEOUT_MS = 15000;

date_default_timezone_set(APP_TIMEZONE);


/* ============================================================
 * 出力バッファリング
 * ============================================================
 *
 * PHP Warning / Notice / Fatal Error等によって
 * API JSONが破壊されることを防ぐため、
 * 最初から出力バッファを開始する。
 */

ob_start();


/* ============================================================
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


/* ============================================================
 * 共通HTTPヘッダー
 * ============================================================
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');


/* ============================================================
 * ログ
 * ============================================================
 */

function appLog(
    string $level,
    string $message
): void {
    error_log(
        '[SURVEY_APP]['
        . $level
        . '] '
        . $message
    );
}


/* ============================================================
 * JSONエンコード
 * ============================================================
 */

function encodeJson(mixed $value): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return '{"success":false,"error":{"code":"JSON_ENCODE_ERROR","message":"JSONレスポンスの生成に失敗しました。"}}';
    }

    return $json;
}


/* ============================================================
 * 出力バッファを破棄
 * ============================================================
 */

function clearOutputBuffer(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}


/* ============================================================
 * 共通成功レスポンス
 * ============================================================
 */

function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {
    clearOutputBuffer();

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
 * 共通エラーレスポンス
 * ============================================================
 */

function errorResponse(
    string $code,
    string $message,
    int $status = 400
): never {
    clearOutputBuffer();

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


/* ============================================================
 * PHPエラー処理
 * ============================================================
 *
 * Warning / Notice等を画面へ直接出さず、
 * 例外として共通処理へ流す。
 */

set_error_handler(
    function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (
            !(error_reporting() & $severity)
        ) {
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


/* ============================================================
 * 未処理例外処理
 * ============================================================
 */

set_exception_handler(
    function (Throwable $e): void {
        appLog(
            'EXCEPTION',
            get_class($e)
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
        echo '</body>';
        echo '</html>';

        exit;
    }
);


/* ============================================================
 * Fatal Error共通処理
 * ============================================================
 *
 * PHP Fatal Errorそのものを通常の例外として捕捉できないため、
 * shutdown時に最後のエラーを確認する。
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

        if (
            !in_array(
                $error['type'],
                $fatalTypes,
                true
            )
        ) {
            return;
        }

        appLog(
            'FATAL',
            ($error['message'] ?? 'unknown')
            . ' @ '
            . ($error['file'] ?? 'unknown')
            . ':'
            . ($error['line'] ?? 0)
        );

        if (!isApiRequest()) {
            return;
        }

        /*
         * Fatal Error発生時点では
         * 既に一部出力されている可能性がある。
         *
         * 可能な限りJSONへ置き換える。
         */
        clearOutputBuffer();

        http_response_code(500);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo encodeJson([
            'success' => false,
            'error' => [
                'code' => 'PHP_FATAL_ERROR',
                'message' =>
                    'サーバー内部エラーが発生しました。',
            ],
        ]);
    }
);


/* ============================================================
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


/* ============================================================
 * セッション
 * ============================================================
 */

if (
    session_status()
    !== PHP_SESSION_ACTIVE
) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}


/* ============================================================
 * CSRFトークン生成
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
    (string)$_SESSION['csrf_token'];


/* ============================================================
 * JSON Body取得
 * ============================================================
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

    $raw = file_get_contents(
        'php://input'
    );

    if (
        !is_string($raw)
        || trim($raw) === ''
    ) {
        return [];
    }

    $decoded = json_decode(
        $raw,
        true
    );

    if (
        !is_array($decoded)
    ) {
        errorResponse(
            'INVALID_JSON',
            'JSON形式のリクエストが不正です。',
            400
        );
    }

    $body = $decoded;

    return $body;
}


/* ============================================================
 * action取得
 * ============================================================
 */

function getAction(): string
{
    global $method;

    if ($method === 'GET') {
        $action =
            $_GET['action']
            ?? '';

        return is_string($action)
            ? trim($action)
            : '';
    }

    $action =
        $_POST['action']
        ?? '';

    if (
        is_string($action)
        && trim($action) !== ''
    ) {
        return trim($action);
    }

    $json = getJsonBody();

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
 * CSRF取得
 * ============================================================
 */

function getRequestCsrfToken(): string
{
    $headerToken =
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (
        is_string($headerToken)
        && $headerToken !== ''
    ) {
        return $headerToken;
    }

    $postToken =
        $_POST['csrf_token']
        ?? '';

    if (
        is_string($postToken)
        && $postToken !== ''
    ) {
        return $postToken;
    }

    $json =
        getJsonBody();

    if (
        isset($json['csrf_token'])
        && is_string(
            $json['csrf_token']
        )
    ) {
        return $json['csrf_token'];
    }

    return '';
}


/* ============================================================
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


/* ============================================================
 * action定義
 * ============================================================
 */

$allowedGetActions = [
    '',
    'health',
    'csrf',
];

$allowedPostActions = [
    'test_post',

    /*
     * アンケート
     */
    'survey_create',
    'survey_update',
    'survey_delete',
    'survey_publish',
    'survey_stop',
    'survey_resume',
    'survey_end',

    /*
     * 回答
     */
    'response_confirm',
    'response_complete',

    /*
     * 顧客
     */
    'customer_save',
    'customer_delete',

    /*
     * メール
     */
    'send_mail',
    'resend_mail',
    'remind_mail',

    /*
     * kintone
     */
    'kintone_test',
    'kintone_fields',
    'kintone_sync',

    /*
     * SMTP
     */
    'smtp_test',

    /*
     * 設定
     */
    'settings_save',
];


/* ============================================================
 * action検証
 * ============================================================
 */

$action = getAction();

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

    validateCsrfToken();
}


/* ============================================================
 * GET: health
 * ============================================================
 */

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


/* ============================================================
 * GET: csrf
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


/* ============================================================
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
            'time' => date(DATE_ATOM),
        ],
        'POST API通信成功'
    );
}


/* ============================================================
 * 画面表示
 * ============================================================
 */

if (
    $method === 'GET'
    && $action === ''
) {

    /*
     * --------------------------------------------------------
     * API入口URL
     * --------------------------------------------------------
     *
     * REQUEST_URIではなくSCRIPT_NAMEを使用する。
     *
     * REQUEST_URIには現在のquery stringや
     * URLエンコードされた値が混在するため、
     * 「現在のindex.phpそのもの」を示す値として
     * SCRIPT_NAMEを使用する。
     */

    $scriptName =
        (string)(
            $_SERVER['SCRIPT_NAME']
            ?? ''
        );

    if (
        $scriptName === ''
        || $scriptName[0] !== '/'
    ) {
        $scriptName =
            '/index.php';
    }

    /*
     * PHPからブラウザへ安全に渡す。
     */
    $entryUrlJson =
        json_encode(
            $scriptName,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

    if (
        !is_string($entryUrlJson)
    ) {
        $entryUrlJson =
            '"/index.php"';
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

<title>
アンケート管理システム
</title>

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
    width: min(
        100%,
        1000px
    );

    margin: 0 auto;

    padding: 24px;
}

.card {
    background: #fff;

    border-radius: 12px;

    padding: 24px;

    margin-bottom: 20px;

    box-shadow:
        0 2px 10px
        rgba(0,0,0,.06);
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

    padding:
        11px 16px;

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

.diagnostic {
    overflow-wrap: anywhere;
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
        minmax(150px,max-content)
        1fr;

    gap:
        8px 16px;
}

dt {
    font-weight: 700;
}

dd {
    margin: 0;

    overflow-wrap: anywhere;
}

.notice {
    padding: 12px;

    border-radius: 8px;

    background: #fff7df;

    color: #634c00;

    margin-bottom: 16px;
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

<h1>
アンケート管理システム
</h1>

<p>
単一入口
<code>index.php</code>
</p>

<div class="notice">
現在は第1段階の通信基盤テストです。
未実装の業務APIは
501 NOT_IMPLEMENTED
を返します。
</div>

<p class="small">
API入口：
<span id="entryUrlText"></span>
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

<h2>
POST API
</h2>

<p class="small">
CSRFトークンがない場合は、
POST処理の前に自動取得します。
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


<section class="card diagnostic">

<h2>
通信診断
</h2>

<dl>

<dt>
API URL
</dt>

<dd id="diagnosticUrl">
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
レスポンス有無
</dt>

<dd id="diagnosticResponse">
-
</dd>


<dt>
レスポンスサイズ
</dt>

<dd id="diagnosticSize">
-
</dd>


<dt>
ブラウザOrigin
</dt>

<dd id="diagnosticOrigin">
-
</dd>


<dt>
タイムアウト
</dt>

<dd id="diagnosticTimeout">
<?= h((string)API_TIMEOUT_MS) ?> ms
</dd>

</dl>

<pre id="diagnosticDetail"></pre>

</section>

</main>


<script>
(() => {

'use strict';


/* ============================================================
 * API設定
 * ============================================================
 *
 * PHPから明示されたindex.phpのパス。
 *
 * 物理ディレクトリ名をJavaScriptへ
 * ハードコードしない。
 */

const ENTRY_PATH =
    <?= $entryUrlJson ?>;


/*
 * 重要：
 *
 * API_TIMEOUT_MSを必ず定義する。
 *
 * これがない状態で
 *
 * setTimeout(... API_TIMEOUT_MS ...)
 *
 * 等を実行すると、
 *
 * API_TIMEOUT_MS is not defined
 *
 * になる。
 */

const API_TIMEOUT_MS =
    <?= (int)API_TIMEOUT_MS ?>;


/* ============================================================
 * API入口URL
 * ============================================================
 */

let entryUrl;

try {

    /*
     * 現在ページのoriginを基準にする。
     *
     * https://localhost
     * なら
     *
     * https://localhost/...
     *
     * となる。
     */

    entryUrl =
        new URL(
            ENTRY_PATH,
            window.location.origin
        );

} catch (error) {

    console.error(
        'API入口URL生成失敗',
        error
    );

    throw new Error(
        'API入口URLを生成できません。'
    );
}


/*
 * 同一originを強制。
 */

if (
    entryUrl.origin
    !== window.location.origin
) {
    throw new Error(
        'API入口がブラウザOriginと一致しません。'
    );
}


/*
 * query stringはAPIごとに生成するため、
 * entryUrlには付けない。
 */

entryUrl.search = '';


/* ============================================================
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


entryUrlText.textContent =
    entryUrl.toString();

diagnosticOrigin.textContent =
    window.location.origin;


/* ============================================================
 * 状態
 * ============================================================
 */

let getProcessing = false;

let postProcessing = false;

let csrfToken = '';


/* ============================================================
 * API URL生成
 * ============================================================
 */

function apiUrl(
    action
) {

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


/* ============================================================
 * ローディング
 * ============================================================
 */

function setGetProcessing(
    value
) {

    getProcessing =
        value;

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

    postProcessing =
        value;

    postButton.disabled =
        value;

    postLoading.classList.toggle(
        'active',
        value
    );
}


/* ============================================================
 * 診断リセット
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


/* ============================================================
 * 診断：HTTPレスポンス受信
 * ============================================================
 */

function setDiagnosticResponse(
    response,
    text
) {

    diagnosticStatus.textContent =
        String(
            response.status
        );

    diagnosticContentType.textContent =
        response.headers.get(
            'content-type'
        )
        || '(なし)';

    diagnosticResponse.textContent =
        'あり';

    diagnosticSize.textContent =
        `${text.length}文字`;

}


/* ============================================================
 * 診断：APIエラー
 * ============================================================
 */

function setDiagnosticApiError(
    response,
    data,
    text
) {

    setDiagnosticResponse(
        response,
        text
    );

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


/* ============================================================
 * 診断：JSON解析エラー
 * ============================================================
 */

function setDiagnosticJsonError(
    response,
    text,
    contentType
) {

    setDiagnosticResponse(
        response,
        text
    );

    diagnosticErrorCode.textContent =
        'INVALID_JSON';

    diagnosticDetail.textContent =
        [
            'JSON解析に失敗しました。',
            '',
            `HTTP: ${response.status}`,
            `Content-Type: ${contentType || '(なし)'}`,
            '',
            'レスポンス先頭:',
            text.slice(0, 1000)
        ].join('\n');
}


/* ============================================================
 * 診断：ネットワークエラー
 * ============================================================
 */

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
        error instanceof Error
            ? error.message
            : String(error);
}


/* ============================================================
 * 診断：タイムアウト
 * ============================================================
 */

function setDiagnosticTimeout() {

    diagnosticStatus.textContent =
        '取得できませんでした';

    diagnosticContentType.textContent =
        '取得できませんでした';

    diagnosticErrorCode.textContent =
        'API_TIMEOUT';

    diagnosticResponse.textContent =
        'なし';

    diagnosticSize.textContent =
        '-';

    diagnosticDetail.textContent =
        [
            'API通信がタイムアウトしました。',
            '',
            `timeout: ${API_TIMEOUT_MS} ms`,
            '',
            '確認事項:',
            '1. Apache24が起動しているか',
            '2. HTTPS証明書に問題がないか',
            '3. PHPが実行されているか',
            '4. index.phpへ直接アクセスできるか',
            '5. Apacheアクセスログ',
            '6. PHPエラーログ'
        ].join('\n');
}


/* ============================================================
 * AbortError判定
 * ============================================================
 */

function isAbortError(
    error
) {

    return (
        error instanceof DOMException
        && error.name === 'AbortError'
    )
    || (
        error instanceof Error
        && error.name === 'AbortError'
    );
}


/* ============================================================
 * JSONレスポンス読み込み
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
        )
        || '';


    setDiagnosticResponse(
        response,
        text
    );


    if (
        text.trim() === ''
    ) {

        throw new Error(
            [
                'サーバーから空のレスポンスが返されました。',
                `HTTP: ${response.status}`,
                `Content-Type: ${contentType || '(なし)'}`
            ].join('\n')
        );
    }


    let data;

    try {

        data =
            JSON.parse(text);

    } catch (error) {

        setDiagnosticJsonError(
            response,
            text,
            contentType
        );

        throw new Error(
            'サーバーのレスポンスをJSONとして解析できません。'
        );
    }


    return {
        data,
        text,
        contentType
    };
}


/* ============================================================
 * fetch共通
 * ============================================================
 */

async function fetchWithTimeout(
    url,
    options = {}
) {

    /*
     * API_TIMEOUT_MSはこの関数のスコープで
     * 明確に定義済み。
     */

    const controller =
        new AbortController();

    const timeoutId =
        window.setTimeout(
            () => {
                controller.abort();
            },
            API_TIMEOUT_MS
        );


    try {

        return await fetch(
            url,
            {
                ...options,
                signal:
                    controller.signal,

                credentials:
                    'same-origin'
            }
        );

    } finally {

        window.clearTimeout(
            timeoutId
        );
    }
}


/* ============================================================
 * GET API共通
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
            await fetchWithTimeout(
                url,
                {
                    method: 'GET',

                    headers: {
                        'Accept':
                            'application/json'
                    }
                }
            );


        const parsed =
            await readJsonResponse(
                response
            );

        const data =
            parsed.data;


        /*
         * APIレスポンス構造確認。
         */

        if (
            !data
            || typeof data !== 'object'
            || typeof data.success !== 'boolean'
        ) {

            diagnosticErrorCode.textContent =
                'INVALID_API_RESPONSE';

            diagnosticDetail.textContent =
                JSON.stringify(
                    data,
                    null,
                    2
                );

            throw new Error(
                'APIレスポンスの形式が不正です。'
            );
        }


        if (
            !response.ok
            || data.success !== true
        ) {

            setDiagnosticApiError(
                response,
                data,
                parsed.text
            );

            throw new Error(
                data?.error?.message
                || 'API処理に失敗しました。'
            );
        }


        diagnosticErrorCode.textContent =
            '-';


        diagnosticDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );


        return data;


    } catch (error) {

        /*
         * HTTPレスポンスを受信している場合、
         * NETWORK_ERRORへ上書きしない。
         */

        if (
            isAbortError(error)
        ) {

            setDiagnosticTimeout();

        } else if (
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


/* ============================================================
 * CSRF取得
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
            'APIは成功しましたが、CSRFトークンが含まれていません。'
        );
    }


    csrfToken =
        token;


    return csrfToken;
}


/* ============================================================
 * POST API共通
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
     * CSRFがない場合は取得。
     */

    if (
        !csrfToken
    ) {

        await getCsrfToken();
    }


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
            await readJsonResponse(
                response
            );

        const data =
            parsed.data;


        if (
            !data
            || typeof data !== 'object'
            || typeof data.success !== 'boolean'
        ) {

            diagnosticErrorCode.textContent =
                'INVALID_API_RESPONSE';

            diagnosticDetail.textContent =
                JSON.stringify(
                    data,
                    null,
                    2
                );

            throw new Error(
                'APIレスポンスの形式が不正です。'
            );
        }


        if (
            !response.ok
            || data.success !== true
        ) {

            setDiagnosticApiError(
                response,
                data,
                parsed.text
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


        diagnosticErrorCode.textContent =
            '-';


        diagnosticDetail.textContent =
            JSON.stringify(
                data,
                null,
                2
            );


        return data;


    } catch (error) {

        if (
            isAbortError(error)
        ) {

            setDiagnosticTimeout();

        } else if (
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


/* ============================================================
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


/* ============================================================
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
                `トークン長: ${token.length}`,
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


/* ============================================================
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


/* ============================================================
 * URL状態
 * ============================================================
 *
 * 第1段階では基盤のみ。
 *
 * screenはURLから取得する。
 *
 * pathnameには業務上の意味を持たせない。
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
            || '',

        questionId:
            params.get('questionId')
            || ''
    };
}


/* ============================================================
 * URL状態同期
 * ============================================================
 */

function replaceUrlState(
    state
) {

    const url =
        new URL(
            window.location.href
        );

    url.search = '';


    if (
        state.screen
    ) {

        url.searchParams.set(
            'screen',
            state.screen
        );
    }


    if (
        state.surveyId
    ) {

        url.searchParams.set(
            'surveyId',
            state.surveyId
        );
    }


    if (
        state.customerId
    ) {

        url.searchParams.set(
            'customerId',
            state.customerId
        );
    }


    if (
        state.questionId
    ) {

        url.searchParams.set(
            'questionId',
            state.questionId
        );
    }


    window.history.replaceState(
        {},
        '',
        url.toString()
    );
}


function pushUrlState(
    state
) {

    const url =
        new URL(
            window.location.href
        );

    url.search = '';


    if (
        state.screen
    ) {

        url.searchParams.set(
            'screen',
            state.screen
        );
    }


    if (
        state.surveyId
    ) {

        url.searchParams.set(
            'surveyId',
            state.surveyId
        );
    }


    if (
        state.customerId
    ) {

        url.searchParams.set(
            'customerId',
            state.customerId
        );
    }


    if (
        state.questionId
    ) {

        url.searchParams.set(
            'questionId',
            state.questionId
        );
    }


    window.history.pushState(
        {},
        '',
        url.toString()
    );
}


/* ============================================================
 * URL状態復元
 * ============================================================
 */

function restoreUrlState() {

    const state =
        getUrlState();


    console.log(
        '[URL_STATE]',
        state
    );


    return state;
}


/* ============================================================
 * history API
 * ============================================================
 */

window.addEventListener(
    'popstate',
    () => {

        restoreUrlState();
    }
);


/*
 * 初期URL状態を復元。
 */

restoreUrlState();


/* ============================================================
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


/* ============================================================
 * 開発者向け初期診断
 * ============================================================
 */

console.info(
    '[SURVEY_APP] initialized'
);

console.info(
    '[SURVEY_APP] origin:',
    window.location.origin
);

console.info(
    '[SURVEY_APP] entry:',
    entryUrl.toString()
);

console.info(
    '[SURVEY_APP] API_TIMEOUT_MS:',
    API_TIMEOUT_MS
);

})();
</script>


</body>

</html>

<?php

    /*
     * HTML出力をここで確定。
     */

    ob_end_flush();

    exit;
}


/* ============================================================
 * 未実装API
 * ============================================================
 */

errorResponse(
    'NOT_IMPLEMENTED',
    'この業務操作はまだ実装されていません。',
    501
);
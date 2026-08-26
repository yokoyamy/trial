<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * 単一入口: index.php
 *
 * 実行環境:
 *   Apache24
 *   PHP 8.4 / 8.5
 *   DBなし
 *   JSON永続化
 *
 * 重要:
 *   pathnameには業務上の意味を持たせない。
 *   画面状態・API actionはquery stringで扱う。
 */

const APP_TIMEZONE = 'Asia/Tokyo';

date_default_timezone_set(APP_TIMEZONE);

/* =========================================================
 * 共通HTTPヘッダー
 * ========================================================= */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/*
 * PHP Warning / Notice 等がAPIレスポンスを壊さないよう、
 * 画面/APIともに表示を抑制する。
 *
 * ログにはPHP側設定に従って記録される。
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

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
    int $status = 400,
    mixed $details = null
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    $error = [
        'code' => $code,
        'message' => $message,
    ];

    if ($details !== null) {
        $error['details'] = $details;
    }

    echo json_encode(
        [
            'success' => false,
            'error' => $error,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/* =========================================================
 * 例外・Fatal Error共通処理
 * ========================================================= */

set_exception_handler(
    static function (Throwable $e): void {
        error_log(
            sprintf(
                '[survey-app] Unhandled exception: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            )
        );

        /*
         * すでにHTMLを出力している場合でも、
         * APIの場合は可能な範囲でJSONに戻す。
         */
        if (!headers_sent()) {
            errorResponse(
                'INTERNAL_ERROR',
                'サーバー内部で予期しないエラーが発生しました。',
                500
            );
        }

        exit;
    }
);

register_shutdown_function(
    static function (): void {
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

        error_log(
            sprintf(
                '[survey-app] Fatal error: %s in %s:%d',
                $error['message'],
                $error['file'],
                $error['line']
            )
        );

        /*
         * 既にレスポンスを開始している可能性があるため、
         * 安全にJSONへ戻せる場合のみ戻す。
         */
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode(
                [
                    'success' => false,
                    'error' => [
                        'code' => 'PHP_FATAL_ERROR',
                        'message' => 'サーバー内部エラーが発生しました。',
                    ],
                ],
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        }
    }
);

/* =========================================================
 * HTMLエスケープ
 * ========================================================= */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* =========================================================
 * HTTPメソッド
 * ========================================================= */

$method = strtoupper(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
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
 * JSON bodyを取得する。
 */
function getJsonBody(): array
{
    $contentType = strtolower(
        (string)($_SERVER['CONTENT_TYPE'] ?? '')
    );

    if (!str_contains($contentType, 'application/json')) {
        return [];
    }

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode(
        $raw,
        true
    );

    return is_array($decoded)
        ? $decoded
        : [];
}

/**
 * リクエストからCSRF tokenを取得する。
 */
function getRequestCsrfToken(): string
{
    /*
     * 1. HTTP Header
     */
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        is_string($headerToken)
        && $headerToken !== ''
    ) {
        return $headerToken;
    }

    /*
     * 2. application/x-www-form-urlencoded
     */
    $postToken = $_POST['csrf_token'] ?? '';

    if (
        is_string($postToken)
        && $postToken !== ''
    ) {
        return $postToken;
    }

    /*
     * 3. application/json
     */
    $json = getJsonBody();

    $jsonToken = $json['csrf_token'] ?? '';

    if (
        is_string($jsonToken)
        && $jsonToken !== ''
    ) {
        return $jsonToken;
    }

    return '';
}

/**
 * CSRF検証。
 */
function validateCsrfToken(): void
{
    $expected = $_SESSION['csrf_token'] ?? '';
    $actual = getRequestCsrfToken();

    if (
        !is_string($expected)
        || $expected === ''
        || !is_string($actual)
        || $actual === ''
        || !hash_equals($expected, $actual)
    ) {
        errorResponse(
            'CSRF_INVALID',
            'CSRFトークンが不正です。',
            403
        );
    }
}

/*
 * POST変更処理ではCSRF必須。
 */
if ($method === 'POST') {
    validateCsrfToken();
}

/* =========================================================
 * action取得
 * ========================================================= */

function getAction(): string
{
    if (
        strtoupper(
            (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
        ) === 'GET'
    ) {
        $action = $_GET['action'] ?? '';

        return is_string($action)
            ? trim($action)
            : '';
    }

    /*
     * POST form
     */
    $action = $_POST['action'] ?? '';

    if (
        is_string($action)
        && $action !== ''
    ) {
        return trim($action);
    }

    /*
     * POST JSON
     */
    $json = getJsonBody();

    $action = $json['action'] ?? '';

    return is_string($action)
        ? trim($action)
        : '';
}

/* =========================================================
 * 許可action
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

    /*
     * 第1段階のPOST通信確認用。
     * 業務APIではなく基盤疎通確認用。
     */
    'post_test',
];

/* =========================================================
 * action検証
 * ========================================================= */

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
}

/* =========================================================
 * GET: health
 * ========================================================= */

if ($action === 'health') {
    successResponse(
        [
            'status' => 'ok',
            'phpVersion' => PHP_VERSION,
            'time' => date(DATE_ATOM),
            'method' => $method,
        ],
        '通信成功'
    );
}

/* =========================================================
 * GET: csrf
 * ========================================================= */

if ($action === 'csrf') {
    successResponse(
        [
            'csrfToken' => $csrfToken,
            'method' => $method,
        ],
        'CSRFトークン取得成功'
    );
}

/* =========================================================
 * POST: post_test
 * ========================================================= */

if ($action === 'post_test') {
    successResponse(
        [
            'status' => 'ok',
            'method' => $method,
            'phpVersion' => PHP_VERSION,
            'time' => date(DATE_ATOM),
            'csrfValidated' => true,
        ],
        'POST API通信成功'
    );
}

/* =========================================================
 * GET: survey_list
 * ========================================================= */

if ($action === 'survey_list') {
    successResponse(
        [
            'surveys' => [],
        ],
        'アンケート一覧を取得しました。'
    );
}

/* =========================================================
 * GET: survey_get
 * ========================================================= */

if ($action === 'survey_get') {
    $surveyId = $_GET['surveyId'] ?? '';

    if (
        !is_string($surveyId)
        || $surveyId === ''
    ) {
        errorResponse(
            'SURVEY_ID_REQUIRED',
            'surveyIdが指定されていません。',
            400
        );
    }

    successResponse(
        [
            'surveyId' => $surveyId,
            'survey' => null,
        ],
        'アンケートを取得しました。'
    );
}

/* =========================================================
 * GET: response_summary
 * ========================================================= */

if ($action === 'response_summary') {
    $surveyId = $_GET['surveyId'] ?? '';

    if (
        !is_string($surveyId)
        || $surveyId === ''
    ) {
        errorResponse(
            'SURVEY_ID_REQUIRED',
            '集計対象のsurveyIdが指定されていません。',
            400
        );
    }

    successResponse(
        [
            'surveyId' => $surveyId,
            'responseCount' => 0,
            'targetCount' => 0,
            'responseRate' => 0,
        ],
        '集計情報を取得しました。'
    );
}

/* =========================================================
 * GET: CSV
 * ========================================================= */

if ($action === 'csv_export') {
    $surveyId = $_GET['surveyId'] ?? '';

    if (
        !is_string($surveyId)
        || $surveyId === ''
    ) {
        errorResponse(
            'SURVEY_ID_REQUIRED',
            'CSV出力対象のsurveyIdが指定されていません。',
            400
        );
    }

    successResponse(
        [
            'surveyId' => $surveyId,
            'implemented' => false,
        ],
        'CSVファイル生成機能は未実装です。'
    );
}

/* =========================================================
 * GET: PDF
 * ========================================================= */

if ($action === 'pdf_export') {
    $surveyId = $_GET['surveyId'] ?? '';

    if (
        !is_string($surveyId)
        || $surveyId === ''
    ) {
        errorResponse(
            'SURVEY_ID_REQUIRED',
            'PDF出力対象のsurveyIdが指定されていません。',
            400
        );
    }

    successResponse(
        [
            'surveyId' => $surveyId,
            'implemented' => false,
        ],
        'PDFファイル生成機能は未実装です。'
    );
}

/* =========================================================
 * 未実装POST
 * ========================================================= */

if (
    $method === 'POST'
    && in_array(
        $action,
        $allowedPostActions,
        true
    )
) {
    errorResponse(
        'NOT_IMPLEMENTED',
        'この業務操作はまだ実装されていません。',
        501
    );
}

/* =========================================================
 * GET画面
 * ========================================================= */

if (
    $method === 'GET'
    && $action === ''
) {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">

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
    font-family:
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;

    background: #f5f7fa;
    color: #222;
}

main {
    width: min(1100px, calc(100% - 32px));
    margin: 32px auto;
}

.card {
    background: #fff;
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 20px;

    box-shadow:
        0 2px 12px rgba(0,0,0,.08);
}

h1 {
    margin-top: 0;
}

h2 {
    margin-top: 0;
}

button {
    appearance: none;
    border: 0;
    border-radius: 8px;

    padding: 11px 18px;

    background: #1769aa;
    color: #fff;

    cursor: pointer;

    font-size: 15px;
}

button:hover:not(:disabled) {
    background: #125687;
}

button:disabled {
    opacity: .55;
    cursor: wait;
}

button.secondary {
    background: #555;
}

button.secondary:hover:not(:disabled) {
    background: #333;
}

button.success {
    background: #16804a;
}

button.success:hover:not(:disabled) {
    background: #106238;
}

.buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.loading {
    display: none;
    margin-left: 10px;
    color: #555;
}

.loading.active {
    display: inline-block;
}

.status {
    margin-top: 16px;
    padding: 12px;

    border-radius: 8px;

    background: #eef2f6;

    white-space: pre-wrap;
    word-break: break-word;

    min-height: 50px;
}

.status.success {
    background: #e9f7ef;
    color: #155d36;
}

.status.error {
    background: #fff0f0;
    color: #9a1f1f;
}

.url-box {
    padding: 12px;

    background: #f4f4f4;

    border-radius: 8px;

    word-break: break-all;

    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Monaco,
        Consolas,
        monospace;

    font-size: 13px;
}

pre {
    white-space: pre-wrap;
    word-break: break-word;
}

.state {
    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(180px, 1fr)
        );

    gap: 10px;
}

.state-item {
    padding: 12px;

    border-radius: 8px;

    background: #f1f3f5;
}

.state-label {
    display: block;

    font-size: 12px;

    color: #666;

    margin-bottom: 4px;
}

.state-value {
    font-weight: 600;

    word-break: break-word;
}

.note {
    color: #555;
    font-size: 14px;
}

@media (max-width: 600px) {
    main {
        width: min(
            100% - 20px,
            1100px
        );

        margin: 10px auto;
    }

    .card {
        padding: 16px;
    }

    .buttons {
        flex-direction: column;
    }

    button {
        width: 100%;
    }

    .loading {
        display: block;
        margin: 10px 0 0;
    }
}
</style>
</head>

<body>

<main>

<div class="card">

<h1>アンケート管理システム</h1>

<p>
単一入口 <strong>index.php</strong>
の基盤疎通確認
</p>

<p class="note">
この画面では、GET / POST / CSRF /
URL状態 / ブラウザ履歴を確認できます。
</p>

</div>

<div class="card">

<h2>現在のURL状態</h2>

<div class="state">

<div class="state-item">
<span class="state-label">
screen
</span>
<span
    class="state-value"
    id="screenValue"
>
-
</span>
</div>

<div class="state-item">
<span class="state-label">
surveyId
</span>
<span
    class="state-value"
    id="surveyIdValue"
>
-
</span>
</div>

<div class="state-item">
<span class="state-label">
customerId
</span>
<span
    class="state-value"
    id="customerIdValue"
>
-
</span>
</div>

</div>

</div>

<div class="card">

<h2>API入口</h2>

<div
    class="url-box"
    id="apiUrl"
></div>

</div>

<div class="card">

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
GET csrf
</button>

</div>

</div>

<div class="card">

<h2>POST API</h2>

<p class="note">
POST APIはCSRFトークンを取得してから実行します。
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

</div>

<div class="card">

<h2>画面状態 / History API</h2>

<div class="buttons">

<button
    type="button"
    data-screen="home"
    class="secondary screenButton"
>
home
</button>

<button
    type="button"
    data-screen="admin"
    class="secondary screenButton"
>
admin
</button>

<button
    type="button"
    data-screen="survey"
    class="secondary screenButton"
>
survey
</button>

<button
    type="button"
    data-screen="answer"
    class="secondary screenButton"
>
answer
</button>

<button
    type="button"
    data-screen="complete"
    class="secondary screenButton"
>
complete
</button>

</div>

<p class="note">
ブラウザの戻る・進む・再読み込みで、
URLから画面状態を再構築します。
</p>

</div>

<div class="card">

<h2>通信結果</h2>

<span
    id="loading"
    class="loading"
>
処理中…
</span>

<div
    id="result"
    class="status"
    aria-live="polite"
>
まだ通信していません。
</div>

</div>

</main>

<script>
(() => {
    'use strict';

    /*
     * =====================================================
     * DOM
     * =====================================================
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

    const loading =
        document.getElementById(
            'loading'
        );

    const result =
        document.getElementById(
            'result'
        );

    const apiUrlElement =
        document.getElementById(
            'apiUrl'
        );

    const screenValue =
        document.getElementById(
            'screenValue'
        );

    const surveyIdValue =
        document.getElementById(
            'surveyIdValue'
        );

    const customerIdValue =
        document.getElementById(
            'customerIdValue'
        );

    /*
     * =====================================================
     * 状態
     * =====================================================
     */

    let processing = false;

    let csrfToken = '';

    /*
     * =====================================================
     * 単一入口URL
     * =====================================================
     *
     * 重要:
     *
     *   redirect: 'same-origin'
     *
     * は絶対に指定しない。
     *
     * fetch() の RequestInit.redirect は
     *
     *   follow
     *   error
     *   manual
     *
     * のみ。
     *
     * 今回はredirect自体を指定しない。
     *
     * また、物理ディレクトリをハードコードしない。
     *
     * 現在URLを基準に index.php を解決する。
     */

    function getApiUrl() {
        const url = new URL(
            'index.php',
            window.location.href
        );

        url.search = '';
        url.hash = '';

        return url;
    }

    /*
     * =====================================================
     * URL画面状態
     * =====================================================
     */

    function getUrlState() {
        const url =
            new URL(
                window.location.href
            );

        return {
            screen:
                url.searchParams.get(
                    'screen'
                ) || 'home',

            surveyId:
                url.searchParams.get(
                    'surveyId'
                ),

            customerId:
                url.searchParams.get(
                    'customerId'
                )
        };
    }

    function renderUrlState() {
        const state =
            getUrlState();

        screenValue.textContent =
            state.screen || '-';

        surveyIdValue.textContent =
            state.surveyId || '-';

        customerIdValue.textContent =
            state.customerId || '-';
    }

    function updateScreen(
        screen,
        surveyId = null,
        customerId = null,
        mode = 'push'
    ) {
        const url =
            new URL(
                window.location.href
            );

        /*
         * actionは画面URLには残さない。
         */
        url.searchParams.delete(
            'action'
        );

        if (
            typeof screen === 'string'
            && screen !== ''
        ) {
            url.searchParams.set(
                'screen',
                screen
            );
        } else {
            url.searchParams.delete(
                'screen'
            );
        }

        if (
            typeof surveyId === 'string'
            && surveyId !== ''
        ) {
            url.searchParams.set(
                'surveyId',
                surveyId
            );
        } else {
            url.searchParams.delete(
                'surveyId'
            );
        }

        if (
            typeof customerId === 'string'
            && customerId !== ''
        ) {
            url.searchParams.set(
                'customerId',
                customerId
            );
        } else {
            url.searchParams.delete(
                'customerId'
            );
        }

        /*
         * pathnameは変更しない。
         */
        if (mode === 'replace') {
            window.history.replaceState(
                {},
                '',
                url.toString()
            );
        } else {
            window.history.pushState(
                {},
                '',
                url.toString()
            );
        }

        renderUrlState();
    }

    /*
     * =====================================================
     * 処理中UI
     * =====================================================
     */

    function setProcessing(value) {
        processing = value;

        healthButton.disabled =
            value;

        csrfButton.disabled =
            value;

        postButton.disabled =
            value;

        document
            .querySelectorAll(
                '.screenButton'
            )
            .forEach(
                (button) => {
                    button.disabled =
                        value;
                }
            );

        loading.classList.toggle(
            'active',
            value
        );
    }

    /*
     * =====================================================
     * 結果表示
     * =====================================================
     */

    function showResult(
        text,
        type = ''
    ) {
        result.className =
            'status';

        if (type === 'success') {
            result.classList.add(
                'success'
            );
        }

        if (type === 'error') {
            result.classList.add(
                'error'
            );
        }

        result.textContent = text;
    }

    /*
     * =====================================================
     * API通信エラー情報
     * =====================================================
     */

    function formatNetworkError(
        error,
        url,
        method
    ) {
        const message =
            error instanceof Error
                ? error.message
                : String(error);

        return [
            'ブラウザからAPIへ接続できませんでした。',
            '',
            'URL: ' + url,
            'HTTPメソッド: ' + method,
            '',
            'エラー: ' + message,
            '',
            '確認項目:',
            '・ApacheがこのURLを受け付けているか',
            '・PHPが正常に実行されているか',
            '・PHP Fatal Errorが発生していないか',
            '・HTTPステータスが返っているか',
            '・Content-Typeが返っているか',
            '・HTTPS/HTTP混在になっていないか',
            '・ブラウザのネットワークエラーがないか',
            '・CORS等のブラウザ制約がないか',
            '・証明書エラーが発生していないか',
        ].join('\n');
    }

    /*
     * =====================================================
     * APIレスポンス共通処理
     * =====================================================
     */

    async function requestApi(
        action,
        options = {}
    ) {
        const method =
            options.method || 'GET';

        const apiUrl =
            getApiUrl();

        apiUrl.searchParams.set(
            'action',
            action
        );

        /*
         * fetchのURLは現在のindex.phpを基準。
         *
         * 物理パスをハードコードしない。
         */
        const requestUrl =
            apiUrl.toString();

        const fetchOptions = {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept':
                    'application/json'
            }
        };

        /*
         * 重要:
         *
         * redirect: 'same-origin'
         *
         * は設定しない。
         *
         * これが今回の
         *
         * "The provided value 'same-origin'
         *  is not a valid enum value
         *  of type RequestRedirect"
         *
         * の直接的な修正。
         */

        if (method !== 'GET') {
            fetchOptions.headers[
                'Content-Type'
            ] =
                'application/json';

            fetchOptions.headers[
                'X-CSRF-Token'
            ] =
                csrfToken;

            fetchOptions.body =
                JSON.stringify({
                    action: action,
                    csrf_token:
                        csrfToken
                });
        }

        let response;

        try {
            response =
                await fetch(
                    requestUrl,
                    fetchOptions
                );
        } catch (error) {
            throw new Error(
                formatNetworkError(
                    error,
                    requestUrl,
                    method
                )
            );
        }

        const httpStatus =
            response.status;

        const contentType =
            response.headers.get(
                'content-type'
            ) || '';

        let text = '';

        try {
            text =
                await response.text();
        } catch (error) {
            throw new Error(
                [
                    'APIレスポンスを読み取れませんでした。',
                    '',
                    'URL: ' + requestUrl,
                    'HTTP: ' + httpStatus,
                    'Content-Type: ' + (
                        contentType || '不明'
                    )
                ].join('\n')
            );
        }

        if (
            text.trim() === ''
        ) {
            throw new Error(
                [
                    'APIから空のレスポンスが返されました。',
                    '',
                    'URL: ' + requestUrl,
                    'HTTP: ' + httpStatus,
                    'Content-Type: ' + (
                        contentType || '不明'
                    )
                ].join('\n')
            );
        }

        let data;

        try {
            data =
                JSON.parse(text);
        } catch (error) {
            throw new Error(
                [
                    'APIからJSONではないレスポンスが返されました。',
                    '',
                    'URL: ' + requestUrl,
                    'HTTP: ' + httpStatus,
                    'Content-Type: ' + (
                        contentType || '不明'
                    ),
                    '',
                    'レスポンス先頭:',
                    text.slice(0, 1000)
                ].join('\n')
            );
        }

        if (
            !response.ok
            || data.success !== true
        ) {
            const code =
                data?.error?.code
                || 'HTTP_ERROR';

            const message =
                data?.error?.message
                || 'API通信に失敗しました。';

            throw new Error(
                [
                    'API通信エラー',
                    '',
                    'HTTP: ' + httpStatus,
                    'APIエラーコード: ' + code,
                    'メッセージ: ' + message
                ].join('\n')
            );
        }

        return {
            response: response,
            data: data,
            url: requestUrl,
            method: method
        };
    }

    /*
     * =====================================================
     * GET health
     * =====================================================
     */

    async function requestHealth() {
        if (processing) {
            return;
        }

        setProcessing(true);

        showResult(
            'health APIへ接続しています…'
        );

        try {
            const resultData =
                await requestApi(
                    'health',
                    {
                        method: 'GET'
                    }
                );

            showResult(
                [
                    resultData.data.message,
                    '',
                    JSON.stringify(
                        resultData.data.data,
                        null,
                        2
                    )
                ].join('\n'),
                'success'
            );
        } catch (error) {
            showResult(
                error instanceof Error
                    ? error.message
                    : String(error),
                'error'
            );
        } finally {
            setProcessing(false);
        }
    }

    /*
     * =====================================================
     * GET CSRF
     * =====================================================
     */

    async function requestCsrf() {
        if (processing) {
            return;
        }

        setProcessing(true);

        showResult(
            'CSRFトークンを取得しています…'
        );

        try {
            const resultData =
                await requestApi(
                    'csrf',
                    {
                        method: 'GET'
                    }
                );

            const token =
                resultData
                    ?.data
                    ?.data
                    ?.csrfToken;

            if (
                typeof token !== 'string'
                || token === ''
            ) {
                throw new Error(
                    'APIレスポンスにCSRFトークンがありません。'
                );
            }

            csrfToken = token;

            showResult(
                [
                    'CSRFトークン取得成功',
                    '',
                    'トークンをブラウザ側へ保持しました。',
                    '',
                    'POST APIテストを実行できます。'
                ].join('\n'),
                'success'
            );
        } catch (error) {
            csrfToken = '';

            showResult(
                error instanceof Error
                    ? error.message
                    : String(error),
                'error'
            );
        } finally {
            setProcessing(false);
        }
    }

    /*
     * =====================================================
     * POST test
     * =====================================================
     */

    async function requestPostTest() {
        if (processing) {
            return;
        }

        /*
         * CSRF未取得なら先に取得する。
         */
        if (
            typeof csrfToken !== 'string'
            || csrfToken === ''
        ) {
            setProcessing(true);

            showResult(
                'CSRFトークンを取得しています…'
            );

            try {
                const csrfResult =
                    await requestApi(
                        'csrf',
                        {
                            method: 'GET'
                        }
                    );

                const token =
                    csrfResult
                        ?.data
                        ?.data
                        ?.csrfToken;

                if (
                    typeof token !== 'string'
                    || token === ''
                ) {
                    throw new Error(
                        'CSRFトークンを取得できませんでした。'
                    );
                }

                csrfToken = token;
            } catch (error) {
                setProcessing(false);

                showResult(
                    error instanceof Error
                        ? error.message
                        : String(error),
                    'error'
                );

                return;
            }

            setProcessing(false);
        }

        setProcessing(true);

        showResult(
            'POST APIへ送信しています…'
        );

        try {
            const resultData =
                await requestApi(
                    'post_test',
                    {
                        method: 'POST'
                    }
                );

            showResult(
                [
                    resultData.data.message,
                    '',
                    JSON.stringify(
                        resultData.data.data,
                        null,
                        2
                    )
                ].join('\n'),
                'success'
            );
        } catch (error) {
            showResult(
                error instanceof Error
                    ? error.message
                    : String(error),
                'error'
            );
        } finally {
            setProcessing(false);
        }
    }

    /*
     * =====================================================
     * API入口表示
     * =====================================================
     */

    function renderApiUrl() {
        apiUrlElement.textContent =
            getApiUrl().toString();
    }

    /*
     * =====================================================
     * History API
     * =====================================================
     */

    document
        .querySelectorAll(
            '.screenButton'
        )
        .forEach(
            (button) => {
                button.addEventListener(
                    'click',
                    () => {
                        const screen =
                            button.dataset.screen
                            || 'home';

                        updateScreen(
                            screen,
                            null,
                            null,
                            'push'
                        );
                    }
                );
            }
        );

    /*
     * popstate:
     *
     * 戻る / 進む
     */
    window.addEventListener(
        'popstate',
        () => {
            renderUrlState();
            renderApiUrl();
        }
    );

    /*
     * =====================================================
     * 初期URL
     * =====================================================
     */

    renderUrlState();
    renderApiUrl();

    /*
     * =====================================================
     * 初期画面
     * =====================================================
     *
     * screenがない場合はhomeをURLへ確定。
     *
     * pathnameは変更しない。
     */
    const initialState =
        getUrlState();

    if (
        !initialState.screen
        || initialState.screen === ''
    ) {
        updateScreen(
            'home',
            null,
            null,
            'replace'
        );
    }

    /*
     * =====================================================
     * ボタンイベント
     * =====================================================
     */

    healthButton.addEventListener(
        'click',
        requestHealth
    );

    csrfButton.addEventListener(
        'click',
        requestCsrf
    );

    postButton.addEventListener(
        'click',
        requestPostTest
    );

})();
</script>

</body>
</html>
<?php
    exit;
}

/* =========================================================
 * 最終フォールバック
 * ========================================================= */

errorResponse(
    'NOT_IMPLEMENTED',
    '指定された操作は実装されていません。',
    501
);
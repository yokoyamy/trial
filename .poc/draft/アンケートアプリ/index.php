<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * 単一入口: index.php
 *
 * 実行環境:
 * - Apache24
 * - PHP 8.4 / 8.5
 * - データベースなし
 *
 * 本段階の目的:
 * - Apache + PHP の実HTTP疎通確認
 * - 単一入口 index.php
 * - GET / POST API
 * - 共通JSONレスポンス
 * - 共通例外処理
 * - CSRF
 * - URL状態
 * - fetch通信
 * - history API
 * - ローディング
 * - 二重送信防止
 *
 * 注意:
 * pathnameには業務上の意味を持たせない。
 * APIも画面も必ず現在のindex.phpを単一入口として使用する。
 */

/* =========================================================
 * 基本設定
 * ========================================================= */

const APP_TIMEZONE = 'Asia/Tokyo';

date_default_timezone_set(APP_TIMEZONE);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* =========================================================
 * 共通JSONレスポンス
 * ========================================================= */

function jsonEncode(mixed $data): string
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return '{"success":false,"error":{"code":"JSON_ENCODE_FAILED","message":"JSONレスポンスの生成に失敗しました。"}}';
    }

    return $json;
}

function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo jsonEncode([
        'success' => true,
        'data' => $data,
        'message' => $message,
    ]);

    exit;
}

function errorResponse(
    string $code,
    string $message,
    int $status = 400
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo jsonEncode([
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);

    exit;
}

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
 * 共通例外処理
 * ========================================================= */

set_exception_handler(
    static function (Throwable $e): never {
        error_log(
            sprintf(
                '[survey-app] Unhandled exception: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            )
        );

        errorResponse(
            'INTERNAL_ERROR',
            'サーバー内部で予期しないエラーが発生しました。',
            500
        );
    }
);

set_error_handler(
    static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        /*
         * Warning / Notice等を画面へ直接出力させない。
         * ログへ記録し、PHPの通常処理へ戻す。
         */
        error_log(
            sprintf(
                '[survey-app] PHP error severity=%d: %s in %s:%d',
                $severity,
                $message,
                $file,
                $line
            )
        );

        return true;
    }
);

/* =========================================================
 * シャットダウン時Fatal Error対策
 * ========================================================= */

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
         * すでにレスポンスが開始されている場合、
         * ここから完全なJSONへ戻すことは保証できない。
         *
         * そのため、通常処理ではdisplay_errors=0とし、
         * Fatal ErrorをHTMLとしてブラウザへ漏らさない。
         */
    }
);

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

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

/**
 * POSTリクエストからCSRFトークンを取得する。
 *
 * 優先順位:
 * 1. X-CSRF-Token
 * 2. POST csrf_token
 * 3. JSON csrfToken
 * 4. JSON csrf_token
 */
function getRequestCsrfToken(): string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        is_string($headerToken)
        && $headerToken !== ''
    ) {
        return $headerToken;
    }

    $postToken = $_POST['csrf_token'] ?? '';

    if (
        is_string($postToken)
        && $postToken !== ''
    ) {
        return $postToken;
    }

    $json = getJsonBody();

    foreach (['csrfToken', 'csrf_token'] as $key) {
        if (
            isset($json[$key])
            && is_string($json[$key])
            && $json[$key] !== ''
        ) {
            return $json[$key];
        }
    }

    return '';
}

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

if ($method === 'POST') {
    validateCsrfToken();
}

/* =========================================================
 * action取得
 * ========================================================= */

function getAction(): string
{
    global $method;

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

    $json = getJsonBody();

    if (
        isset($json['action'])
        && is_string($json['action'])
    ) {
        return trim($json['action']);
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
    /*
     * 基盤動作確認用
     */
    'api_test',

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

/* =========================================================
 * action検証
 * ========================================================= */

$action = getAction();

if ($method === 'GET') {
    if (!in_array($action, $allowedGetActions, true)) {
        errorResponse(
            'INVALID_ACTION',
            'GETでは利用できないactionです。',
            400
        );
    }
} else {
    if (!in_array($action, $allowedPostActions, true)) {
        errorResponse(
            'INVALID_ACTION',
            'POSTでは利用できないactionです。',
            400
        );
    }
}

/* =========================================================
 * API疎通確認
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
 * CSRF取得
 * ========================================================= */

if ($action === 'csrf') {
    successResponse(
        [
            'csrfToken' => $csrfToken,
        ],
        'CSRFトークン取得成功'
    );
}

/* =========================================================
 * POST API疎通確認
 * ========================================================= */

if ($action === 'api_test') {
    successResponse(
        [
            'status' => 'ok',
            'method' => $method,
            'phpVersion' => PHP_VERSION,
            'time' => date(DATE_ATOM),
            'csrf' => 'validated',
        ],
        'POST API通信成功'
    );
}

/* =========================================================
 * GETデフォルト画面
 * ========================================================= */

if ($method === 'GET' && $action === '') {
    /*
     * サーバー側で「このindex.php自身」のURLを生成する。
     *
     * JavaScriptが物理ディレクトリ名を推測しない。
     * SCRIPT_NAMEは現在実行されている公開PHP入口そのもの。
     */

    $scheme = 'http';

    if (
        isset($_SERVER['HTTPS'])
        && strtolower((string)$_SERVER['HTTPS']) !== 'off'
        && $_SERVER['HTTPS'] !== ''
    ) {
        $scheme = 'https';
    } elseif (
        isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && is_string($_SERVER['HTTP_X_FORWARDED_PROTO'])
    ) {
        $forwardedProto = strtolower(
            trim(
                explode(
                    ',',
                    $_SERVER['HTTP_X_FORWARDED_PROTO']
                )[0]
            )
        );

        if (
            $forwardedProto === 'https'
            || $forwardedProto === 'http'
        ) {
            $scheme = $forwardedProto;
        }
    }

    $host = (string)(
        $_SERVER['HTTP_HOST']
        ?? 'localhost'
    );

    /*
     * Hostヘッダに改行等が混入している場合は使用しない。
     */
    if (
        $host === ''
        || preg_match('/[\r\n]/', $host)
    ) {
        $host = 'localhost';
    }

    $scriptName = (string)(
        $_SERVER['SCRIPT_NAME']
        ?? '/index.php'
    );

    /*
     * SCRIPT_NAMEにはquery stringを含めない。
     */
    $scriptName = strtok($scriptName, '?');

    if (
        !is_string($scriptName)
        || $scriptName === ''
    ) {
        $scriptName = '/index.php';
    }

    $applicationEntryUrl =
        $scheme
        . '://'
        . $host
        . $scriptName;

    /*
     * 初期画面状態。
     *
     * URLを正規情報として扱う。
     */
    $screen = isset($_GET['screen'])
        && is_string($_GET['screen'])
        ? trim($_GET['screen'])
        : 'home';

    $surveyId = isset($_GET['surveyId'])
        && is_string($_GET['surveyId'])
        && $_GET['surveyId'] !== ''
        ? $_GET['surveyId']
        : null;

    $customerId = isset($_GET['customerId'])
        && is_string($_GET['customerId'])
        && $_GET['customerId'] !== ''
        ? $_GET['customerId']
        : null;

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>アンケート管理システム</title>

<meta
    name="application-entry"
    content="<?= h($applicationEntryUrl) ?>"
>

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

    background: #f5f6f8;
    color: #222;
}

main {
    width: min(100% - 32px, 1100px);
    margin: 0 auto;
    padding: 24px 0 48px;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 24px;
    box-shadow:
        0 2px 10px rgba(0, 0, 0, .06);
    margin-bottom: 20px;
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
    padding: 10px 16px;
    background: #1769aa;
    color: #fff;
    cursor: pointer;
    font-size: 14px;
}

button:hover:not(:disabled) {
    background: #12598f;
}

button:disabled {
    opacity: .6;
    cursor: wait;
}

button.secondary {
    background: #555;
}

button.success {
    background: #26734d;
}

button.danger {
    background: #a33;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 16px;
}

.status {
    margin-top: 16px;
    padding: 12px;
    border-radius: 8px;
    background: #f0f2f5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.status.success {
    background: #eaf7ef;
    color: #165b34;
}

.status.error {
    background: #fff0f0;
    color: #8a1f1f;
}

.status.info {
    background: #eef5ff;
    color: #184d82;
}

.loading {
    display: none;
    margin-left: 8px;
}

.loading.active {
    display: inline-block;
}

.api-panel {
    margin-top: 20px;
}

.api-result {
    min-height: 120px;
    margin-top: 12px;
    padding: 12px;
    background: #111827;
    color: #e5e7eb;
    border-radius: 8px;
    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Consolas,
        monospace;
    font-size: 13px;
    white-space: pre-wrap;
    overflow-x: auto;
}

.url-box {
    margin-top: 12px;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #fafafa;
    word-break: break-all;
    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Consolas,
        monospace;
    font-size: 12px;
}

.state-grid {
    display: grid;
    grid-template-columns:
        repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}

.state-item {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 12px;
    background: #fafafa;
}

.state-label {
    display: block;
    color: #666;
    font-size: 12px;
    margin-bottom: 4px;
}

.state-value {
    font-weight: 600;
    overflow-wrap: anywhere;
}

.small {
    color: #666;
    font-size: 13px;
}

.direct-link {
    display: inline-block;
    margin-top: 12px;
    color: #1769aa;
}
</style>
</head>

<body>

<main>

    <section class="card">
        <h1>アンケート管理システム</h1>

        <p>
            単一入口
            <strong>index.php</strong>
            の実HTTP通信確認
        </p>

        <div class="state-grid">

            <div class="state-item">
                <span class="state-label">
                    現在画面
                </span>

                <span
                    class="state-value"
                    id="screenValue"
                ></span>
            </div>

            <div class="state-item">
                <span class="state-label">
                    surveyId
                </span>

                <span
                    class="state-value"
                    id="surveyIdValue"
                ></span>
            </div>

            <div class="state-item">
                <span class="state-label">
                    customerId
                </span>

                <span
                    class="state-value"
                    id="customerIdValue"
                ></span>
            </div>

        </div>

        <div class="actions">

            <button
                type="button"
                id="homeButton"
            >
                HOME
            </button>

            <button
                type="button"
                id="adminButton"
            >
                管理者画面
            </button>

            <button
                type="button"
                id="surveyButton"
            >
                アンケート画面
            </button>

            <button
                type="button"
                id="answerButton"
            >
                回答画面
            </button>

            <button
                type="button"
                id="completeButton"
            >
                完了画面
            </button>

        </div>
    </section>

    <section class="card api-panel">

        <h2>GET API</h2>

        <p class="small">
            現在の単一入口URLに対して
            GET APIを実行します。
        </p>

        <div class="actions">

            <button
                type="button"
                id="healthButton"
            >
                GET / health
            </button>

            <button
                type="button"
                id="csrfButton"
                class="secondary"
            >
                GET / csrf
            </button>

        </div>

        <span
            id="getLoading"
            class="loading"
            aria-live="polite"
        >
            通信中…
        </span>

        <div
            id="getResult"
            class="api-result"
            aria-live="polite"
        ></div>

        <div class="url-box">
            API入口:
            <span id="entryUrl"></span>
        </div>

        <p>
            <a
                id="directHealthLink"
                class="direct-link"
                target="_blank"
                rel="noopener"
            >
                GET APIを直接開く
            </a>
        </p>

    </section>

    <section class="card api-panel">

        <h2>POST API</h2>

        <p class="small">
            CSRFトークンを取得した後、
            同じindex.phpへPOSTします。
        </p>

        <div class="actions">

            <button
                type="button"
                id="postButton"
                class="success"
            >
                POST API通信テスト
            </button>

        </div>

        <span
            id="postLoading"
            class="loading"
            aria-live="polite"
        >
            通信中…
        </span>

        <div
            id="postResult"
            class="api-result"
            aria-live="polite"
        ></div>

    </section>

    <section class="card">

        <h2>通信診断</h2>

        <div
            id="diagnosticResult"
            class="status info"
        >
            まだAPI通信を実行していません。
        </div>

    </section>

</main>

<script>
(() => {
    'use strict';

    /* =====================================================
     * DOM
     * ===================================================== */

    const screenValue =
        document.getElementById('screenValue');

    const surveyIdValue =
        document.getElementById('surveyIdValue');

    const customerIdValue =
        document.getElementById('customerIdValue');

    const entryUrlElement =
        document.getElementById('entryUrl');

    const directHealthLink =
        document.getElementById('directHealthLink');

    const getResult =
        document.getElementById('getResult');

    const postResult =
        document.getElementById('postResult');

    const diagnosticResult =
        document.getElementById('diagnosticResult');

    const getLoading =
        document.getElementById('getLoading');

    const postLoading =
        document.getElementById('postLoading');

    const healthButton =
        document.getElementById('healthButton');

    const csrfButton =
        document.getElementById('csrfButton');

    const postButton =
        document.getElementById('postButton');

    /* =====================================================
     * アプリケーション単一入口
     * ===================================================== */

    const metaEntry =
        document.querySelector(
            'meta[name="application-entry"]'
        );

    const APP_ENTRY_URL =
        metaEntry
        && metaEntry.content
        ? metaEntry.content
        : '';

    /*
     * サーバーから提供された入口URLがない場合、
     * 勝手に物理パスを生成しない。
     */
    if (!APP_ENTRY_URL) {
        diagnosticResult.textContent =
            'アプリケーション入口URLを取得できません。'
            + '\n'
            + 'サーバー側のapplication-entry設定を確認してください。';
    }

    entryUrlElement.textContent =
        APP_ENTRY_URL || '(取得失敗)';

    /* =====================================================
     * 状態
     * ===================================================== */

    let csrfToken = '';

    let getProcessing = false;
    let postProcessing = false;

    /* =====================================================
     * URL状態
     * ===================================================== */

    function readUrlState() {
        const params =
            new URLSearchParams(
                window.location.search
            );

        const screen =
            params.get('screen')
            || 'home';

        const surveyId =
            params.get('surveyId');

        const customerId =
            params.get('customerId');

        return {
            screen,
            surveyId,
            customerId
        };
    }

    function renderUrlState() {
        const state =
            readUrlState();

        screenValue.textContent =
            state.screen;

        surveyIdValue.textContent =
            state.surveyId || 'null';

        customerIdValue.textContent =
            state.customerId || 'null';

        return state;
    }

    function buildScreenUrl(
        screen,
        surveyId = null,
        customerId = null
    ) {
        /*
         * 画面URLも現在のindex.phpを基準にする。
         * pathnameを業務識別子として使用しない。
         */

        const url =
            new URL(
                window.location.href
            );

        url.search = '';

        url.searchParams.set(
            'screen',
            screen
        );

        if (surveyId) {
            url.searchParams.set(
                'surveyId',
                surveyId
            );
        }

        if (customerId) {
            url.searchParams.set(
                'customerId',
                customerId
            );
        }

        return url;
    }

    function navigate(
        screen,
        surveyId = null,
        customerId = null,
        replace = false
    ) {
        const url =
            buildScreenUrl(
                screen,
                surveyId,
                customerId
            );

        if (replace) {
            window.history.replaceState(
                {
                    screen,
                    surveyId,
                    customerId
                },
                '',
                url.toString()
            );
        } else {
            window.history.pushState(
                {
                    screen,
                    surveyId,
                    customerId
                },
                '',
                url.toString()
            );
        }

        renderUrlState();
    }

    window.addEventListener(
        'popstate',
        () => {
            renderUrlState();
        }
    );

    /* =====================================================
     * ボタン
     * ===================================================== */

    document
        .getElementById('homeButton')
        .addEventListener(
            'click',
            () => navigate('home')
        );

    document
        .getElementById('adminButton')
        .addEventListener(
            'click',
            () => navigate('admin')
        );

    document
        .getElementById('surveyButton')
        .addEventListener(
            'click',
            () => navigate('survey')
        );

    document
        .getElementById('answerButton')
        .addEventListener(
            'click',
            () => navigate('answer')
        );

    document
        .getElementById('completeButton')
        .addEventListener(
            'click',
            () => navigate('complete')
        );

    /* =====================================================
     * URL初期表示
     * ===================================================== */

    renderUrlState();

    /* =====================================================
     * API URL生成
     * ===================================================== */

    function buildApiUrl(action) {
        if (!APP_ENTRY_URL) {
            throw new Error(
                'アプリケーション入口URLが未設定です。'
            );
        }

        /*
         * 必ずサーバーから提供された
         * index.php URLを使用する。
         *
         * /api/xxx
         * /xxx/index.php
         * /アンケートアプリ/api/xxx
         *
         * 等は使用しない。
         */

        const url =
            new URL(APP_ENTRY_URL);

        url.search = '';

        url.searchParams.set(
            'action',
            action
        );

        return url;
    }

    /* =====================================================
     * AbortController
     * ===================================================== */

    async function fetchWithTimeout(
        url,
        options = {},
        timeoutMs = 15000
    ) {
        const controller =
            new AbortController();

        const timeoutId =
            window.setTimeout(
                () => controller.abort(),
                timeoutMs
            );

        try {
            return await fetch(
                url.toString(),
                {
                    ...options,
                    signal:
                        controller.signal
                }
            );
        } finally {
            window.clearTimeout(
                timeoutId
            );
        }
    }

    /* =====================================================
     * レスポンス解析
     * ===================================================== */

    async function parseApiResponse(
        response
    ) {
        const contentType =
            response.headers.get(
                'content-type'
            ) || '';

        const text =
            await response.text();

        if (text.trim() === '') {
            throw new Error(
                'サーバーから空のレスポンスが返されました。'
                + '\nHTTP: '
                + response.status
                + '\nContent-Type: '
                + (
                    contentType
                    || '(なし)'
                )
            );
        }

        let data;

        try {
            data =
                JSON.parse(text);
        } catch (error) {
            throw new Error(
                'サーバーからJSONではないレスポンスが返されました。'
                + '\nHTTP: '
                + response.status
                + '\nContent-Type: '
                + (
                    contentType
                    || '(なし)'
                )
                + '\n\nレスポンス先頭:\n'
                + text.slice(0, 1000)
            );
        }

        if (
            !response.ok
            || data.success !== true
        ) {
            const code =
                data
                && data.error
                && data.error.code
                ? data.error.code
                : 'HTTP_ERROR';

            const message =
                data
                && data.error
                && data.error.message
                ? data.error.message
                : 'API処理に失敗しました。';

            throw new Error(
                message
                + '\nAPIエラーコード: '
                + code
                + '\nHTTP: '
                + response.status
            );
        }

        return data;
    }

    /* =====================================================
     * 通信エラー診断
     * ===================================================== */

    function formatNetworkError(
        error,
        url,
        method
    ) {
        let reason;

        if (
            error
            && error.name === 'AbortError'
        ) {
            reason =
                'タイムアウトしました。'
                + '\n'
                + '15秒以内にサーバーから応答がありませんでした。';
        } else {
            reason =
                error instanceof Error
                    ? error.message
                    : String(error);
        }

        return [
            'ブラウザからAPIへ接続できませんでした。',
            '',
            'URL: ' + url,
            'HTTPメソッド: ' + method,
            '',
            'エラー: ' + reason,
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
            '・Apacheアクセスログを確認する',
            '・PHPエラーログを確認する'
        ].join('\n');
    }

    function setDiagnostic(
        text,
        type = 'info'
    ) {
        diagnosticResult.textContent =
            text;

        diagnosticResult.className =
            'status ' + type;
    }

    /* =====================================================
     * GET API
     * ===================================================== */

    async function requestGet(
        action
    ) {
        if (getProcessing) {
            return null;
        }

        getProcessing = true;

        healthButton.disabled = true;
        csrfButton.disabled = true;

        getLoading.classList.add(
            'active'
        );

        try {
            const url =
                buildApiUrl(action);

            getResult.textContent =
                '通信中…\n'
                + url.toString();

            setDiagnostic(
                'GET API通信中…',
                'info'
            );

            let response;

            try {
                response =
                    await fetchWithTimeout(
                        url,
                        {
                            method: 'GET',

                            headers: {
                                'Accept':
                                    'application/json'
                            },

                            /*
                             * CSRFセッションを使用する。
                             */
                            credentials:
                                'same-origin',

                            /*
                             * キャッシュされた
                             * 古いJSONを使用しない。
                             */
                            cache: 'no-store'
                        }
                    );
            } catch (error) {
                const message =
                    formatNetworkError(
                        error,
                        url.toString(),
                        'GET'
                    );

                getResult.textContent =
                    message;

                setDiagnostic(
                    message,
                    'error'
                );

                return null;
            }

            try {
                const data =
                    await parseApiResponse(
                        response
                    );

                getResult.textContent =
                    JSON.stringify(
                        data,
                        null,
                        2
                    );

                setDiagnostic(
                    'GET API通信成功'
                    + '\nHTTP: '
                    + response.status
                    + '\nContent-Type: '
                    + (
                        response.headers.get(
                            'content-type'
                        )
                        || '(なし)'
                    ),
                    'success'
                );

                return data;
            } catch (error) {
                const message =
                    error instanceof Error
                        ? error.message
                        : String(error);

                getResult.textContent =
                    message;

                setDiagnostic(
                    message,
                    'error'
                );

                return null;
            }
        } finally {
            getProcessing = false;

            healthButton.disabled = false;
            csrfButton.disabled = false;

            getLoading.classList.remove(
                'active'
            );
        }
    }

    healthButton.addEventListener(
        'click',
        () => {
            requestGet('health');
        }
    );

    csrfButton.addEventListener(
        'click',
        async () => {
            const data =
                await requestGet(
                    'csrf'
                );

            if (
                data
                && data.success === true
                && data.data
                && typeof data.data.csrfToken
                    === 'string'
            ) {
                csrfToken =
                    data.data.csrfToken;

                setDiagnostic(
                    'CSRFトークン取得成功'
                    + '\n'
                    + 'POST APIを実行できます。',
                    'success'
                );
            }
        }
    );

    /* =====================================================
     * POST API
     * ===================================================== */

    async function requestPostTest() {
        if (postProcessing) {
            return null;
        }

        postProcessing = true;

        postButton.disabled = true;

        postLoading.classList.add(
            'active'
        );

        try {
            /*
             * CSRFがない場合は先に取得する。
             */
            if (!csrfToken) {
                postResult.textContent =
                    'CSRFトークンを取得しています…';

                const csrfData =
                    await requestGet(
                        'csrf'
                    );

                if (
                    !csrfData
                    || csrfData.success !== true
                    || !csrfData.data
                    || typeof csrfData.data.csrfToken
                        !== 'string'
                ) {
                    throw new Error(
                        'CSRFトークンを取得できませんでした。'
                    );
                }

                csrfToken =
                    csrfData.data.csrfToken;
            }

            const url =
                buildApiUrl(
                    /*
                     * POST actionは
                     * query stringではなく
                     * bodyへ渡す。
                     */
                    'api_test'
                );

            /*
             * actionはPOST bodyへ送る。
             */
            url.search = '';

            const body =
                new URLSearchParams();

            body.set(
                'action',
                'api_test'
            );

            body.set(
                'csrf_token',
                csrfToken
            );

            postResult.textContent =
                '通信中…\n'
                + url.toString();

            setDiagnostic(
                'POST API通信中…',
                'info'
            );

            let response;

            try {
                response =
                    await fetchWithTimeout(
                        url,
                        {
                            method: 'POST',

                            headers: {
                                'Accept':
                                    'application/json',

                                'X-CSRF-Token':
                                    csrfToken,

                                'Content-Type':
                                    'application/x-www-form-urlencoded; charset=UTF-8'
                            },

                            credentials:
                                'same-origin',

                            cache: 'no-store',

                            body
                        }
                    );
            } catch (error) {
                const message =
                    formatNetworkError(
                        error,
                        url.toString(),
                        'POST'
                    );

                postResult.textContent =
                    message;

                setDiagnostic(
                    message,
                    'error'
                );

                return null;
            }

            try {
                const data =
                    await parseApiResponse(
                        response
                    );

                postResult.textContent =
                    JSON.stringify(
                        data,
                        null,
                        2
                    );

                setDiagnostic(
                    'POST API通信成功'
                    + '\nHTTP: '
                    + response.status
                    + '\nContent-Type: '
                    + (
                        response.headers.get(
                            'content-type'
                        )
                        || '(なし)'
                    ),
                    'success'
                );

                return data;
            } catch (error) {
                const message =
                    error instanceof Error
                        ? error.message
                        : String(error);

                postResult.textContent =
                    message;

                setDiagnostic(
                    message,
                    'error'
                );

                return null;
            }
        } catch (error) {
            const message =
                error instanceof Error
                    ? error.message
                    : String(error);

            postResult.textContent =
                message;

            setDiagnostic(
                message,
                'error'
            );

            return null;
        } finally {
            postProcessing = false;

            postButton.disabled = false;

            postLoading.classList.remove(
                'active'
            );
        }
    }

    postButton.addEventListener(
        'click',
        requestPostTest
    );

    /* =====================================================
     * 直接GETリンク
     * ===================================================== */

    try {
        const directUrl =
            buildApiUrl('health');

        directHealthLink.href =
            directUrl.toString();
    } catch (error) {
        directHealthLink.removeAttribute(
            'href'
        );
    }

    /* =====================================================
     * 初期状態
     * ===================================================== */

    renderUrlState();

    /*
     * 画面を再読み込みしても
     * URLから同じ状態を復元する。
     */
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
<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * 第1段階：単一入口・実行基盤
 *
 * 対象：
 * - Apache24 + PHP 8.4 / 8.5
 * - 単一入口 index.php
 * - GET / POST
 * - action whitelist
 * - 共通APIレスポンス
 * - 共通例外処理
 * - CSRF
 * - HTTPメソッド制御
 * - query stringによる画面状態
 * - fetch通信
 * - pushState / replaceState / popstate
 * - Loading
 * - 二重送信防止
 *
 * 注意：
 * この段階ではsurvey等の業務処理は実装しない。
 */

const APP_TIMEZONE = 'Asia/Tokyo';
const CSRF_SESSION_KEY = '_survey_csrf_token';

date_default_timezone_set(APP_TIMEZONE);

/*
 * ------------------------------------------------------------
 * PHPエラー設定
 * ------------------------------------------------------------
 *
 * APIレスポンスへWarning/Notice等を混入させない。
 * 本番相当環境ではdisplay_errorsをOFFにする。
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

/*
 * ------------------------------------------------------------
 * セッション
 * ------------------------------------------------------------
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

/*
 * ------------------------------------------------------------
 * 共通HTTP
 * ------------------------------------------------------------
 */

function isApiRequest(): bool
{
    $action = $_GET['action'] ?? null;

    if (is_string($action) && $action !== '') {
        return true;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (stripos($contentType, 'application/json') !== false) {
            return true;
        }

        if (isset($_POST['action'])) {
            return true;
        }
    }

    return false;
}

function sendJson(array $payload, int $status = 200): never
{
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function successResponse(
    mixed $data = [],
    string $message = '',
    int $status = 200
): never {
    sendJson([
        'success' => true,
        'data' => $data,
        'message' => $message,
    ], $status);
}

function errorResponse(
    string $code,
    string $message,
    int $status = 400,
    mixed $details = null
): never {
    $error = [
        'code' => $code,
        'message' => $message,
    ];

    if ($details !== null) {
        $error['details'] = $details;
    }

    sendJson([
        'success' => false,
        'error' => $error,
    ], $status);
}

/*
 * ------------------------------------------------------------
 * 共通例外処理
 * ------------------------------------------------------------
 */

function logApplicationError(Throwable $e): void
{
    error_log(sprintf(
        '[survey-app] %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
}

set_exception_handler(
    function (Throwable $e): never {
        logApplicationError($e);

        if (isApiRequest()) {
            errorResponse(
                'INTERNAL_ERROR',
                'サーバー内部で予期しないエラーが発生しました。',
                500
            );
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');

        echo '<!doctype html>';
        echo '<html lang="ja">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>システムエラー</title>';
        echo '</head>';
        echo '<body>';
        echo '<h1>システムエラー</h1>';
        echo '<p>サーバー内部で予期しないエラーが発生しました。</p>';
        echo '</body>';
        echo '</html>';

        exit;
    }
);

set_error_handler(
    function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        /*
         * ErrorExceptionへ変換して共通例外処理へ流す。
         */
        if (!(error_reporting() & $severity)) {
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

/*
 * shutdown時のFatal Errorを可能な範囲で統一処理する。
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
        ];

        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        error_log(sprintf(
            '[survey-app] PHP fatal error: %s in %s:%d',
            $error['message'],
            $error['file'],
            $error['line']
        ));

        /*
         * すでに出力が始まっている場合でも、
         * APIとして可能な範囲でJSONを返す。
         */
        if (isApiRequest()) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
            }

            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'PHP_FATAL_ERROR',
                    'message' => 'サーバー内部でエラーが発生しました。',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
);

/*
 * ------------------------------------------------------------
 * リクエスト情報
 * ------------------------------------------------------------
 */

function requestMethod(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function contentType(): string
{
    return strtolower($_SERVER['CONTENT_TYPE'] ?? '');
}

function isJsonRequest(): bool
{
    return str_contains(contentType(), 'application/json');
}

/*
 * ------------------------------------------------------------
 * JSON Body
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

    $raw = file_get_contents('php://input');

    if ($raw === false) {
        errorResponse(
            'REQUEST_BODY_READ_FAILED',
            'リクエスト本文を読み取れません。',
            400
        );
    }

    if (trim($raw) === '') {
        $body = [];
        return $body;
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        errorResponse(
            'INVALID_JSON',
            'JSON形式のリクエストが不正です。',
            400
        );
    }

    $body = $decoded;

    return $body;
}

/*
 * ------------------------------------------------------------
 * action
 * ------------------------------------------------------------
 */

function getAction(): string
{
    if (isset($_GET['action'])) {
        if (!is_string($_GET['action'])) {
            errorResponse(
                'INVALID_ACTION',
                'actionが不正です。',
                400
            );
        }

        return trim($_GET['action']);
    }

    if (requestMethod() === 'POST') {
        if (isJsonRequest()) {
            $body = getJsonBody();

            if (isset($body['action'])) {
                if (!is_string($body['action'])) {
                    errorResponse(
                        'INVALID_ACTION',
                        'actionが不正です。',
                        400
                    );
                }

                return trim($body['action']);
            }
        }

        if (isset($_POST['action'])) {
            if (!is_string($_POST['action'])) {
                errorResponse(
                    'INVALID_ACTION',
                    'actionが不正です。',
                    400
                );
            }

            return trim($_POST['action']);
        }
    }

    return '';
}

/*
 * ------------------------------------------------------------
 * action whitelist
 * ------------------------------------------------------------
 *
 * 第1段階で利用するactionのみ実装する。
 * 業務actionは名前だけ許可しない。
 *
 * 後続段階で追加する。
 */

const GET_ACTIONS = [
    'health',
    'csrf',
];

const POST_ACTIONS = [
    /*
     * 第1段階では変更系の業務APIを実装しない。
     *
     * 将来：
     * survey_create
     * survey_update
     * survey_delete
     * survey_publish
     * ...
     */
];

function validateAction(string $action, string $method): void
{
    if ($action === '') {
        errorResponse(
            'ACTION_REQUIRED',
            'actionを指定してください。',
            400
        );
    }

    $allowed = $method === 'GET'
        ? GET_ACTIONS
        : POST_ACTIONS;

    if (!in_array($action, $allowed, true)) {
        errorResponse(
            'UNKNOWN_ACTION',
            '指定されたactionは利用できません。',
            404,
            [
                'action' => $action,
            ]
        );
    }
}

/*
 * ------------------------------------------------------------
 * HTTP method
 * ------------------------------------------------------------
 */

function requireMethod(string ...$allowed): void
{
    $method = requestMethod();

    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));

        errorResponse(
            'METHOD_NOT_ALLOWED',
            '許可されていないHTTPメソッドです。',
            405
        );
    }
}

/*
 * ------------------------------------------------------------
 * CSRF
 * ------------------------------------------------------------
 */

function generateCsrfToken(): string
{
    if (
        !isset($_SESSION[CSRF_SESSION_KEY]) ||
        !is_string($_SESSION[CSRF_SESSION_KEY]) ||
        $_SESSION[CSRF_SESSION_KEY] === ''
    ) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }

    return $_SESSION[CSRF_SESSION_KEY];
}

function getRequestCsrfToken(): string
{
    /*
     * JSON POST：
     * Authorization等ではなくX-CSRF-Tokenヘッダーを基本とする。
     */
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (is_string($headerToken) && $headerToken !== '') {
        return $headerToken;
    }

    /*
     * form POST
     */
    if (
        isset($_POST['csrf_token']) &&
        is_string($_POST['csrf_token'])
    ) {
        return $_POST['csrf_token'];
    }

    /*
     * JSON bodyにも対応。
     *
     * ただしフロントエンドではヘッダー利用を標準とする。
     */
    if (isJsonRequest()) {
        $body = getJsonBody();

        if (
            isset($body['csrf_token']) &&
            is_string($body['csrf_token'])
        ) {
            return $body['csrf_token'];
        }
    }

    return '';
}

function validateCsrf(): void
{
    $expected = generateCsrfToken();
    $actual = getRequestCsrfToken();

    if (
        $actual === '' ||
        !hash_equals($expected, $actual)
    ) {
        errorResponse(
            'CSRF_INVALID',
            'CSRFトークンが不正です。画面を再読み込みして再度お試しください。',
            403
        );
    }
}

/*
 * ------------------------------------------------------------
 * 入力値
 * ------------------------------------------------------------
 */

function queryString(string $key, ?string $default = null): ?string
{
    if (!isset($_GET[$key])) {
        return $default;
    }

    if (!is_string($_GET[$key])) {
        errorResponse(
            'INVALID_PARAMETER',
            'URLパラメータが不正です。',
            400,
            ['parameter' => $key]
        );
    }

    return trim($_GET[$key]);
}

function validateOptionalId(
    ?string $value,
    string $parameter
): ?string {
    if ($value === null || $value === '') {
        return null;
    }

    /*
     * IDは内部識別子。
     * 第1段階では英数字・_・-・.を許可。
     */
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $value)) {
        errorResponse(
            'INVALID_ID',
            '識別子が不正です。',
            400,
            ['parameter' => $parameter]
        );
    }

    return $value;
}

/*
 * ------------------------------------------------------------
 * 画面状態
 * ------------------------------------------------------------
 */

const ALLOWED_SCREENS = [
    'home',
    'admin',
    'survey',
    'answer',
    'complete',
];

function getScreenState(): array
{
    $screen = queryString('screen', 'home');

    if ($screen === null || $screen === '') {
        $screen = 'home';
    }

    if (!in_array($screen, ALLOWED_SCREENS, true)) {
        $screen = 'home';
    }

    $surveyId = validateOptionalId(
        queryString('surveyId'),
        'surveyId'
    );

    $customerId = validateOptionalId(
        queryString('customerId'),
        'customerId'
    );

    return [
        'screen' => $screen,
        'surveyId' => $surveyId,
        'customerId' => $customerId,
    ];
}

/*
 * ------------------------------------------------------------
 * API処理
 * ------------------------------------------------------------
 */

function handleApi(): never
{
    $method = requestMethod();

    if ($method === 'GET') {
        $action = getAction();

        validateAction($action, 'GET');

        switch ($action) {
            case 'health':
                successResponse([
                    'status' => 'ok',
                    'phpVersion' => PHP_VERSION,
                    'time' => date(DATE_ATOM),
                ], '正常に稼働しています。');

            case 'csrf':
                successResponse([
                    'token' => generateCsrfToken(),
                ], 'CSRFトークンを取得しました。');

            default:
                /*
                 * whitelist済みactionなので通常到達しない。
                 */
                errorResponse(
                    'ACTION_NOT_IMPLEMENTED',
                    'このactionはまだ実装されていません。',
                    501
                );
        }
    }

    if ($method === 'POST') {
        $action = getAction();

        validateAction($action, 'POST');

        /*
         * 第1段階ではPOST業務APIをまだ実装しない。
         */
        validateCsrf();

        errorResponse(
            'ACTION_NOT_IMPLEMENTED',
            'このactionはまだ実装されていません。',
            501
        );
    }

    header('Allow: GET, POST');

    errorResponse(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405
    );
}

/*
 * ------------------------------------------------------------
 * HTML escape
 * ------------------------------------------------------------
 */

function h(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/*
 * ------------------------------------------------------------
 * HTML
 * ------------------------------------------------------------
 */

function renderPage(): never
{
    $state = getScreenState();

    $stateJson = json_encode(
        $state,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    );

    $csrfToken = generateCsrfToken();

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<meta
    name="csrf-token"
    content="<?= h($csrfToken) ?>"
>
<title>アンケート管理システム</title>

<style>
:root {
    color-scheme: light;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f5f7fa;
    color: #1f2937;
}

header {
    padding: 16px 20px;
    background: #111827;
    color: #fff;
}

main {
    width: min(1100px, calc(100% - 32px));
    margin: 24px auto;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow:
        0 1px 3px rgba(0, 0, 0, .08);
    margin-bottom: 16px;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

button {
    appearance: none;
    border: 0;
    border-radius: 8px;
    padding: 10px 16px;
    background: #2563eb;
    color: #fff;
    cursor: pointer;
    font-size: 14px;
}

button:hover:not(:disabled) {
    background: #1d4ed8;
}

button:disabled {
    opacity: .55;
    cursor: not-allowed;
}

button.secondary {
    background: #4b5563;
}

.status {
    margin-top: 16px;
    padding: 12px;
    border-radius: 8px;
    background: #f3f4f6;
    white-space: pre-wrap;
    word-break: break-word;
}

.status.success {
    background: #ecfdf5;
    color: #065f46;
}

.status.error {
    background: #fef2f2;
    color: #991b1b;
}

.loading {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255,255,255,.35);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .7s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.debug {
    font-family: ui-monospace, monospace;
    font-size: 12px;
    background: #111827;
    color: #d1d5db;
    padding: 12px;
    border-radius: 8px;
    overflow-x: auto;
}

@media (max-width: 640px) {
    main {
        width: min(100% - 20px, 1100px);
        margin: 12px auto;
    }

    .card {
        padding: 16px;
    }

    button {
        width: 100%;
    }
}
</style>
</head>

<body>

<header>
    <strong>アンケート管理システム</strong>
</header>

<main>

<section class="card">
    <h1 id="screen-title">システム基盤</h1>

    <p id="screen-description">
        第1段階：単一入口・API・URL状態管理の確認
    </p>

    <div class="actions">
        <button
            type="button"
            data-screen="home"
        >
            Home
        </button>

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

        <button
            type="button"
            data-screen="complete"
        >
            完了
        </button>
    </div>
</section>

<section class="card">
    <h2>API疎通確認</h2>

    <div class="actions">
        <button
            type="button"
            id="health-button"
        >
            health API
        </button>

        <button
            type="button"
            id="csrf-button"
            class="secondary"
        >
            CSRF API
        </button>
    </div>

    <div
        id="api-status"
        class="status"
        aria-live="polite"
    >
        未実行
    </div>
</section>

<section class="card">
    <h2>現在のURL状態</h2>

    <pre
        id="url-state"
        class="debug"
    ></pre>
</section>

</main>

<script>
'use strict';

/*
 * ------------------------------------------------------------
 * 初期状態
 * ------------------------------------------------------------
 */

const INITIAL_STATE = <?= $stateJson ?: '{}' ?>;

const csrfMeta = document.querySelector(
    'meta[name="csrf-token"]'
);

let csrfToken = csrfMeta
    ? csrfMeta.getAttribute('content')
    : '';

/*
 * ------------------------------------------------------------
 * DOM
 * ------------------------------------------------------------
 */

const apiStatus = document.getElementById(
    'api-status'
);

const urlStateElement = document.getElementById(
    'url-state'
);

const screenTitle = document.getElementById(
    'screen-title'
);

const screenDescription = document.getElementById(
    'screen-description'
);

/*
 * ------------------------------------------------------------
 * 画面定義
 * ------------------------------------------------------------
 */

const SCREEN_DEFINITIONS = {
    home: {
        title: 'システム基盤',
        description:
            '第1段階：単一入口・API・URL状態管理の確認'
    },

    admin: {
        title: '管理画面',
        description:
            '管理者向け画面状態の基盤'
    },

    survey: {
        title: 'アンケート',
        description:
            'アンケート画面状態の基盤'
    },

    answer: {
        title: '回答',
        description:
            '回答者画面状態の基盤'
    },

    complete: {
        title: '完了',
        description:
            '回答完了画面状態の基盤'
    }
};

/*
 * ------------------------------------------------------------
 * URL
 * ------------------------------------------------------------
 *
 * pathnameには業務上の意味を持たせない。
 */

function buildApplicationUrl(state) {
    const url = new URL(
        window.location.href
    );

    url.search = '';

    if (state.screen) {
        url.searchParams.set(
            'screen',
            state.screen
        );
    }

    if (state.surveyId) {
        url.searchParams.set(
            'surveyId',
            state.surveyId
        );
    }

    if (state.customerId) {
        url.searchParams.set(
            'customerId',
            state.customerId
        );
    }

    return url;
}

function readUrlState() {
    const params = new URLSearchParams(
        window.location.search
    );

    return {
        screen: params.get('screen') || 'home',
        surveyId: params.get('surveyId'),
        customerId: params.get('customerId')
    };
}

function normalizeScreenState(state) {
    const allowed = Object.keys(
        SCREEN_DEFINITIONS
    );

    const normalized = {
        screen: allowed.includes(state.screen)
            ? state.screen
            : 'home',

        surveyId: state.surveyId || null,
        customerId: state.customerId || null
    };

    return normalized;
}

/*
 * ------------------------------------------------------------
 * 画面状態描画
 * ------------------------------------------------------------
 */

function renderScreenFromUrl() {
    const state = normalizeScreenState(
        readUrlState()
    );

    const definition =
        SCREEN_DEFINITIONS[state.screen];

    screenTitle.textContent =
        definition.title;

    screenDescription.textContent =
        definition.description;

    urlStateElement.textContent =
        JSON.stringify(
            state,
            null,
            2
        );

    return state;
}

/*
 * ------------------------------------------------------------
 * history API
 * ------------------------------------------------------------
 */

function navigate(state, options = {}) {
    const normalized =
        normalizeScreenState(state);

    const url =
        buildApplicationUrl(normalized);

    const method =
        options.replace === true
            ? 'replaceState'
            : 'pushState';

    window.history[method](
        normalized,
        '',
        url
    );

    renderScreenFromUrl();
}

/*
 * popstate
 *
 * ブラウザの戻る・進むでは、
 * JavaScript内部状態を信用せずURLから再構築する。
 */

window.addEventListener(
    'popstate',
    () => {
        renderScreenFromUrl();
    }
);

/*
 * 初期URLをreplaceStateで正規化。
 */

navigate(
    normalizeScreenState(
        INITIAL_STATE
    ),
    {
        replace: true
    }
);

/*
 * ------------------------------------------------------------
 * 画面ボタン
 * ------------------------------------------------------------
 */

document
    .querySelectorAll('[data-screen]')
    .forEach((button) => {
        button.addEventListener(
            'click',
            () => {
                navigate({
                    screen:
                        button.dataset.screen
                            || 'home'
                });
            }
        );
    });

/*
 * ------------------------------------------------------------
 * Loading
 * ------------------------------------------------------------
 */

function setButtonLoading(
    button,
    loading,
    text
) {
    if (!button) {
        return;
    }

    if (loading) {
        if (!button.dataset.originalText) {
            button.dataset.originalText =
                button.textContent;
        }

        button.disabled = true;

        button.innerHTML =
            '<span class="loading">' +
            '<span class="spinner"></span>' +
            '<span>' +
            escapeHtml(text || '処理中...') +
            '</span>' +
            '</span>';

        return;
    }

    button.disabled = false;

    if (button.dataset.originalText) {
        button.textContent =
            button.dataset.originalText;
    }
}

/*
 * ------------------------------------------------------------
 * API URL
 * ------------------------------------------------------------
 *
 * 固定された /api/xxx 等を使用しない。
 * 現在のindex.phpを基準にする。
 */

function buildApiUrl(action) {
    const url = new URL(
        window.location.href
    );

    url.search = '';

    url.searchParams.set(
        'action',
        action
    );

    return url;
}

/*
 * ------------------------------------------------------------
 * fetch共通処理
 * ------------------------------------------------------------
 */

async function apiRequest(
    action,
    options = {}
) {
    const method =
        (options.method || 'GET').toUpperCase();

    const timeoutMs =
        Number(options.timeoutMs || 15000);

    const controller =
        new AbortController();

    const timeoutId =
        window.setTimeout(
            () => controller.abort(),
            timeoutMs
        );

    const url =
        buildApiUrl(action);

    const headers = {
        'Accept': 'application/json'
    };

    let body;

    if (method !== 'GET') {
        headers['Content-Type'] =
            'application/json';

        if (!csrfToken) {
            await refreshCsrfToken();
        }

        headers['X-CSRF-Token'] =
            csrfToken;

        const requestBody =
            options.body || {};

        body = JSON.stringify({
            ...requestBody,
            action
        });
    }

    let response;
    let text = '';

    try {
        response = await fetch(
            url.toString(),
            {
                method,
                headers,
                body,
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal
            }
        );

        text = await response.text();

    } catch (error) {
        if (
            error &&
            error.name === 'AbortError'
        ) {
            throw new Error(
                '通信がタイムアウトしました。'
            );
        }

        throw new Error(
            'ネットワーク通信に失敗しました。'
        );

    } finally {
        window.clearTimeout(
            timeoutId
        );
    }

    const contentType =
        response.headers.get(
            'content-type'
        ) || '';

    let payload = null;

    if (text.trim() !== '') {
        try {
            payload =
                JSON.parse(text);
        } catch (error) {
            const preview =
                text
                    .replace(/\s+/g, ' ')
                    .slice(0, 300);

            throw new Error(
                'APIレスポンスをJSONとして解析できませんでした。' +
                '\nHTTPステータス: ' +
                response.status +
                '\nContent-Type: ' +
                contentType +
                '\nレスポンス: ' +
                preview
            );
        }
    }

    if (!payload) {
        throw new Error(
            'APIから空のレスポンスが返されました。' +
            '\nHTTPステータス: ' +
            response.status
        );
    }

    if (
        typeof payload !== 'object' ||
        typeof payload.success !== 'boolean'
    ) {
        throw new Error(
            'APIレスポンス形式が不正です。' +
            '\nHTTPステータス: ' +
            response.status
        );
    }

    if (!response.ok) {
        const code =
            payload.error &&
            payload.error.code
                ? payload.error.code
                : 'HTTP_ERROR';

        const message =
            payload.error &&
            payload.error.message
                ? payload.error.message
                : 'API処理に失敗しました。';

        const error =
            new Error(
                message +
                '\nエラーコード: ' +
                code +
                '\nHTTPステータス: ' +
                response.status
            );

        error.code = code;
        error.status =
            response.status;

        throw error;
    }

    if (payload.success !== true) {
        const code =
            payload.error &&
            payload.error.code
                ? payload.error.code
                : 'API_ERROR';

        const message =
            payload.error &&
            payload.error.message
                ? payload.error.message
                : 'API処理に失敗しました。';

        const error =
            new Error(
                message +
                '\nエラーコード: ' +
                code
            );

        error.code = code;
        error.status =
            response.status;

        throw error;
    }

    return payload;
}

/*
 * ------------------------------------------------------------
 * CSRF
 * ------------------------------------------------------------
 */

async function refreshCsrfToken() {
    const payload =
        await apiRequest(
            'csrf',
            {
                method: 'GET'
            }
        );

    if (
        !payload.data ||
        typeof payload.data.token !== 'string' ||
        payload.data.token === ''
    ) {
        throw new Error(
            'CSRFトークンを取得できませんでした。'
        );
    }

    csrfToken =
        payload.data.token;

    if (csrfMeta) {
        csrfMeta.setAttribute(
            'content',
            csrfToken
        );
    }

    return csrfToken;
}

/*
 * ------------------------------------------------------------
 * API結果表示
 * ------------------------------------------------------------
 */

function showStatus(
    message,
    type = ''
) {
    apiStatus.textContent =
        message;

    apiStatus.className =
        'status' +
        (type ? ' ' + type : '');
}

function showApiSuccess(
    payload
) {
    const message =
        payload.message ||
        '処理が完了しました。';

    const data =
        payload.data || {};

    showStatus(
        message +
        '\n\n' +
        JSON.stringify(
            data,
            null,
            2
        ),
        'success'
    );
}

function showApiError(error) {
    showStatus(
        error instanceof Error
            ? error.message
            : '処理に失敗しました。',
        'error'
    );
}

/*
 * ------------------------------------------------------------
 * ボタン処理
 * ------------------------------------------------------------
 */

async function executeButtonAction(
    button,
    action,
    options = {}
) {
    if (
        !button ||
        button.disabled
    ) {
        return;
    }

    setButtonLoading(
        button,
        true,
        options.loadingText ||
        '処理中...'
    );

    showStatus(
        '処理中です...',
        ''
    );

    try {
        const payload =
            await apiRequest(
                action,
                options
            );

        showApiSuccess(
            payload
        );

    } catch (error) {
        showApiError(
            error
        );

    } finally {
        setButtonLoading(
            button,
            false
        );
    }
}

/*
 * ------------------------------------------------------------
 * health
 * ------------------------------------------------------------
 */

const healthButton =
    document.getElementById(
        'health-button'
    );

healthButton.addEventListener(
    'click',
    () => {
        executeButtonAction(
            healthButton,
            'health',
            {
                method: 'GET',
                loadingText:
                    '接続確認中...'
            }
        );
    }
);

/*
 * ------------------------------------------------------------
 * csrf
 * ------------------------------------------------------------
 */

const csrfButton =
    document.getElementById(
        'csrf-button'
    );

csrfButton.addEventListener(
    'click',
    async () => {
        if (csrfButton.disabled) {
            return;
        }

        setButtonLoading(
            csrfButton,
            true,
            '取得中...'
        );

        showStatus(
            'CSRFトークンを取得しています...'
        );

        try {
            await refreshCsrfToken();

            showStatus(
                'CSRFトークンを取得しました。',
                'success'
            );

        } catch (error) {
            showApiError(
                error
            );

        } finally {
            setButtonLoading(
                csrfButton,
                false
            );
        }
    }
);

/*
 * ------------------------------------------------------------
 * HTML escape
 * ------------------------------------------------------------
 */

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/*
 * ------------------------------------------------------------
 * デバッグ用：
 * 現在URLをブラウザタイトルにも反映
 * ------------------------------------------------------------
 */

function updateDocumentTitle() {
    const state =
        readUrlState();

    const definition =
        SCREEN_DEFINITIONS[
            state.screen
        ];

    if (definition) {
        document.title =
            definition.title +
            ' - アンケート管理システム';
    }
}

window.addEventListener(
    'popstate',
    updateDocumentTitle
);

updateDocumentTitle();
</script>

</body>
</html>
<?php

    exit;
}

/*
 * ------------------------------------------------------------
 * メイン
 * ------------------------------------------------------------
 */

try {
    if (isApiRequest()) {
        handleApi();
    }

    renderPage();

} catch (Throwable $e) {
    /*
     * set_exception_handlerだけに依存せず、
     * メイン入口でも最終防衛線を持つ。
     */
    logApplicationError($e);

    if (isApiRequest()) {
        errorResponse(
            'INTERNAL_ERROR',
            'サーバー内部で予期しないエラーが発生しました。',
            500
        );
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head><meta charset="utf-8">';
    echo '<title>システムエラー</title></head>';
    echo '<body>';
    echo '<h1>システムエラー</h1>';
    echo '<p>サーバー内部で予期しないエラーが発生しました。</p>';
    echo '</body></html>';

    exit;
}
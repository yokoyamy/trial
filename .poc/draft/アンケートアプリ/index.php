<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * 第1段階：単一HTTP入口
 *
 * この段階の責務：
 * - GET / POST の振り分け
 * - JSON POST の受信
 * - action の取得・検証
 * - HTTPメソッド制御
 * - CSRF
 * - API共通レスポンス
 * - health API
 *
 * まだ実装しないもの：
 * - JSON永続化
 * - survey業務ロジック
 * - 回答
 * - kintone
 * - SMTP
 * - CSV/PDF
 */

// ============================================================
// Bootstrap
// ============================================================

session_start();

date_default_timezone_set('Asia/Tokyo');

const APP_NAME = 'アンケート管理システム';
const APP_VERSION = '0.1.0';

// actionごとの許可HTTPメソッド
const ACTION_METHODS = [
    'health' => ['GET', 'POST'],
];

// ============================================================
// Error / Exception handling
// ============================================================

set_exception_handler(
    static function (Throwable $e): void {
        error_log(
            sprintf(
                '[%s] %s: %s',
                date('c'),
                get_class($e),
                $e->getMessage()
            )
        );

        if (isApiRequest()) {
            apiError(
                'INTERNAL_ERROR',
                'サーバー内部でエラーが発生しました。',
                500
            );
        }

        http_response_code(500);

        renderHtml([
            'title' => APP_NAME,
            'error' => 'サーバー内部でエラーが発生しました。',
        ]);
    }
);

set_error_handler(
    static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
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

// ============================================================
// Entry point
// ============================================================

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        handleGet();
        exit;
    }

    if ($method === 'POST') {
        handlePost();
        exit;
    }

    apiError(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405,
        [
            'allowedMethods' => ['GET', 'POST'],
        ]
    );
} catch (Throwable $e) {
    throw $e;
}

// ============================================================
// GET
// ============================================================

function handleGet(): void
{
    $action = getQueryString('action');

    // actionなし = 通常画面
    if ($action === null || $action === '') {
        renderApp();
        return;
    }

    validateAction($action);

    $allowedMethods = ACTION_METHODS[$action] ?? [];

    if (!in_array('GET', $allowedMethods, true)) {
        apiError(
            'METHOD_NOT_ALLOWED',
            'このactionではGETは許可されていません。',
            405,
            [
                'action' => $action,
                'allowedMethods' => $allowedMethods,
            ]
        );
    }

    dispatchAction('GET', $action, []);
}

// ============================================================
// POST
// ============================================================

function handlePost(): void
{
    requireJsonContentType();

    $payload = readJsonBody();

    if (!array_key_exists('action', $payload)) {
        apiError(
            'ACTION_REQUIRED',
            'actionが指定されていません。',
            400
        );
    }

    if (!is_string($payload['action'])) {
        apiError(
            'INVALID_ACTION',
            'actionは文字列で指定してください。',
            400
        );
    }

    $action = trim($payload['action']);

    if ($action === '') {
        apiError(
            'ACTION_REQUIRED',
            'actionが指定されていません。',
            400
        );
    }

    validateAction($action);

    $allowedMethods = ACTION_METHODS[$action] ?? [];

    if (!in_array('POST', $allowedMethods, true)) {
        apiError(
            'METHOD_NOT_ALLOWED',
            'このactionではPOSTは許可されていません。',
            405,
            [
                'action' => $action,
                'allowedMethods' => $allowedMethods,
            ]
        );
    }

    /*
     * POSTは副作用を伴う可能性があるためCSRFを検証する。
     *
     * トークンは以下のどちらでも受け取れる：
     * 1. JSON body の csrfToken
     * 2. X-CSRF-Token ヘッダー
     */
    verifyCsrf($payload);

    unset($payload['csrfToken']);

    dispatchAction('POST', $action, $payload);
}

// ============================================================
// Action dispatch
// ============================================================

function dispatchAction(
    string $method,
    string $action,
    array $payload
): never {
    switch ($action) {
        case 'health':
            handleHealth($method, $payload);
            break;

        default:
            /*
             * validateAction() で到達しないことを保証する。
             * 防御的に残しておく。
             */
            apiError(
                'INVALID_ACTION',
                '指定されたactionは利用できません。',
                400
            );
    }

    apiError(
        'INTERNAL_ERROR',
        'action処理が正常に終了しませんでした。',
        500
    );
}

// ============================================================
// Health
// ============================================================

function handleHealth(string $method, array $payload): never
{
    apiSuccess(
        [
            'status' => 'ok',
            'application' => APP_NAME,
            'version' => APP_VERSION,
            'method' => $method,
            'timestamp' => date('c'),
        ],
        '正常に接続されています。'
    );
}

// ============================================================
// Action validation
// ============================================================

function validateAction(string $action): void
{
    if (!array_key_exists($action, ACTION_METHODS)) {
        apiError(
            'INVALID_ACTION',
            '指定されたactionは利用できません。',
            400,
            [
                'action' => $action,
            ]
        );
    }
}

// ============================================================
// JSON
// ============================================================

function requireJsonContentType(): void
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    /*
     * charset等が付いても許可する。
     *
     * application/json
     * application/json; charset=UTF-8
     */
    if (
        !preg_match(
            '/^application\/json(?:\s*;.*)?$/i',
            trim($contentType)
        )
    ) {
        apiError(
            'INVALID_CONTENT_TYPE',
            'POSTデータはapplication/jsonで送信してください。',
            415
        );
    }
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false) {
        apiError(
            'REQUEST_READ_ERROR',
            'リクエスト本文を読み取れませんでした。',
            400
        );
    }

    $raw = trim($raw);

    if ($raw === '') {
        apiError(
            'EMPTY_JSON',
            'JSONリクエスト本文が空です。',
            400
        );
    }

    try {
        $decoded = json_decode(
            $raw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        apiError(
            'INVALID_JSON',
            'JSON形式が不正です。',
            400
        );
    }

    if (!is_array($decoded)) {
        apiError(
            'INVALID_JSON_STRUCTURE',
            'JSONのルート要素はオブジェクトで指定してください。',
            400
        );
    }

    return $decoded;
}

// ============================================================
// CSRF
// ============================================================

function ensureCsrfToken(): string
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

function verifyCsrf(array $payload): void
{
    $sessionToken = ensureCsrfToken();

    $bodyToken = null;

    if (
        isset($payload['csrfToken']) &&
        is_string($payload['csrfToken'])
    ) {
        $bodyToken = $payload['csrfToken'];
    }

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (
        !is_string($headerToken) ||
        $headerToken === ''
    ) {
        $headerToken = null;
    }

    $providedToken = $bodyToken ?? $headerToken;

    if (
        $providedToken === null ||
        !hash_equals($sessionToken, $providedToken)
    ) {
        apiError(
            'CSRF_INVALID',
            'CSRFトークンが不正です。',
            403
        );
    }
}

// ============================================================
// API response
// ============================================================

function apiSuccess(
    array $data = [],
    string $message = ''
): never {
    sendJson(
        [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ],
        200
    );
}

function apiError(
    string $code,
    string $message,
    int $status = 400,
    array $details = []
): never {
    $error = [
        'code' => $code,
        'message' => $message,
    ];

    if ($details !== []) {
        $error['details'] = $details;
    }

    sendJson(
        [
            'success' => false,
            'error' => $error,
        ],
        $status
    );
}

function sendJson(array $body, int $status): never
{
    http_response_code($status);

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    /*
     * 405の場合などに必要になった場合でも、
     * JSONレスポンスの形式は変えない。
     */
    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

    exit;
}

// ============================================================
// Request helpers
// ============================================================

function getQueryString(string $key): ?string
{
    if (!isset($_GET[$key])) {
        return null;
    }

    if (!is_string($_GET[$key])) {
        return null;
    }

    return trim($_GET[$key]);
}

function isApiRequest(): bool
{
    $action = $_GET['action'] ?? null;

    if (is_string($action) && $action !== '') {
        return true;
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    return $method === 'POST';
}

// ============================================================
// HTML
// ============================================================

function renderApp(): void
{
    $csrfToken = ensureCsrfToken();

    $csrfTokenJson = json_encode(
        $csrfToken,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

    $appName = htmlspecialchars(
        APP_NAME,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    echo <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$appName}</title>
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
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
    background: #f5f7fa;
    color: #1f2937;
}

.container {
    width: min(960px, calc(100% - 32px));
    margin: 40px auto;
}

.card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, .05);
}

h1 {
    margin-top: 0;
}

.status {
    margin: 16px 0;
    padding: 12px 16px;
    border-radius: 8px;
    background: #f3f4f6;
    white-space: pre-wrap;
    word-break: break-word;
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

button:disabled {
    cursor: not-allowed;
    opacity: .6;
}

.loading {
    display: none;
    margin-left: 10px;
    color: #6b7280;
}

.loading.is-active {
    display: inline;
}

.spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    margin-right: 6px;
    vertical-align: -2px;
    border: 2px solid #d1d5db;
    border-top-color: #2563eb;
    border-radius: 50%;
    animation: spin .7s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

pre {
    overflow: auto;
    padding: 16px;
    border-radius: 8px;
    background: #111827;
    color: #e5e7eb;
}
</style>
</head>
<body>

<main class="container">
    <section class="card">
        <h1>{$appName}</h1>

        <p>
            第1段階：単一入口の疎通確認
        </p>

        <div id="status" class="status">
            待機中
        </div>

        <button id="healthButton" type="button">
            接続テスト
        </button>

        <span id="loading" class="loading">
            <span class="spinner"></span>
            処理中...
        </span>

        <h2>現在のCSRF状態</h2>

        <pre id="csrfState"></pre>

        <h2>APIレスポンス</h2>

        <pre id="response"></pre>
    </section>
</main>

<script>
'use strict';

const CSRF_TOKEN = {$csrfTokenJson};

let operationRunning = false;

const statusElement = document.getElementById('status');
const responseElement = document.getElementById('response');
const csrfStateElement = document.getElementById('csrfState');
const healthButton = document.getElementById('healthButton');
const loadingElement = document.getElementById('loading');

csrfStateElement.textContent =
    'CSRF token: ' + (CSRF_TOKEN ? '取得済み' : '未取得');

function setLoading(isLoading) {
    operationRunning = isLoading;

    healthButton.disabled = isLoading;

    loadingElement.classList.toggle(
        'is-active',
        isLoading
    );
}

function setStatus(message) {
    statusElement.textContent = message;
}

async function requestJson(action, data = {}) {
    if (operationRunning) {
        return;
    }

    setLoading(true);
    setStatus('通信中...');
    responseElement.textContent = '';

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify({
                action,
                csrfToken: CSRF_TOKEN,
                ...data
            })
        });

        const text = await response.text();

        let result;

        try {
            result = JSON.parse(text);
        } catch (error) {
            throw new Error(
                'サーバーからJSONではないレスポンスが返されました。'
                + '\\nHTTP status: '
                + response.status
                + '\\n'
                + text
            );
        }

        responseElement.textContent =
            JSON.stringify(result, null, 2);

        if (!response.ok || result.success !== true) {
            const message =
                result &&
                result.error &&
                result.error.message
                    ? result.error.message
                    : 'API処理に失敗しました。';

            setStatus(
                '失敗\\n'
                + 'HTTP status: '
                + response.status
                + '\\n'
                + message
            );

            return result;
        }

        setStatus(
            '成功\\n'
            + (result.message || '正常に処理されました。')
        );

        return result;
    } catch (error) {
        responseElement.textContent = '';

        /*
         * fetch自体が失敗した場合。
         * ここで「Failed to fetch」だけを表示せず、
         * 可能な限り原因を明示する。
         */
        setStatus(
            '通信失敗\\n'
            + (
                error instanceof Error
                    ? error.message
                    : String(error)
            )
        );

        throw error;
    } finally {
        setLoading(false);
    }
}

healthButton.addEventListener('click', async () => {
    try {
        await requestJson('health');
    } catch (error) {
        console.error(error);
    }
});
</script>

</body>
</html>
HTML;
}
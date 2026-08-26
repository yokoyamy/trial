<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 * Single Entry: index.php
 *
 * PHP 8.4 / 8.5
 *
 * 第1段階:
 * - GET / POST / OPTIONS
 * - action routing
 * - JSON response
 * - Session
 * - CSRF
 * - Request ID
 * - CORS / Origin
 * - 共通例外処理
 * - PHP Error対策
 *
 * 業務APIは後続段階で追加する。
 */

/* =========================================================
 * 基本設定
 * ======================================================= */

const APP_NAME = 'アンケート管理システム';

const CSRF_ACTION = 'csrf';
const HEALTH_ACTION = 'health';

/*
 * 本番環境では false。
 * 開発時に必要なら環境変数等から切り替える。
 */
const DEBUG = false;

/*
 * 同一オリジンを基本とする。
 *
 * cross-originを許可する場合だけ明示的に追加する。
 *
 * 例:
 * [
 *     'https://example.com'
 * ]
 */
const CORS_ALLOWED_ORIGINS = [];

/*
 * fetch timeout等はフロントエンド側にも
 * 同じ設定値を提供できるようにする。
 */
const CLIENT_TIMEOUT_MS = 30000;


/* =========================================================
 * PHPエラー設定
 * ======================================================= */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

error_reporting(E_ALL);


/* =========================================================
 * 共通ユーティリティ
 * ======================================================= */

/**
 * Request ID生成
 */
function generateRequestId(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable) {
        return uniqid('req_', true);
    }
}


/**
 * Request ID取得
 *
 * クライアント指定値をそのまま信用しない。
 */
function getRequestId(): string
{
    $header = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

    if (
        is_string($header)
        && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $header)
    ) {
        /*
         * 外部から渡された値をそのままログ上の
         * 識別子として使わず、サーバー側IDを生成する。
         */
    }

    return generateRequestId();
}


/**
 * ログ出力
 *
 * 秘密情報を引数として渡さないこと。
 */
function appLog(
    string $level,
    string $message,
    array $context = []
): void {
    $safeContext = [];

    foreach ($context as $key => $value) {
        /*
         * 念のため秘密情報らしいキーは除外。
         */
        $lowerKey = strtolower((string)$key);

        if (
            str_contains($lowerKey, 'password')
            || str_contains($lowerKey, 'token')
            || str_contains($lowerKey, 'cookie')
            || str_contains($lowerKey, 'authorization')
            || str_contains($lowerKey, 'secret')
        ) {
            continue;
        }

        $safeContext[$key] = $value;
    }

    $line = sprintf(
        '[%s] [%s] %s %s',
        date('c'),
        strtoupper($level),
        $message,
        $safeContext !== []
            ? json_encode(
                $safeContext,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_INVALID_UTF8_SUBSTITUTE
            )
            : ''
    );

    error_log($line);
}


/**
 * JSONレスポンス
 */
function sendJson(
    array $body,
    int $statusCode = 200,
    array $headers = []
): never {
    http_response_code($statusCode);

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


/**
 * 成功レスポンス
 */
function successResponse(
    array $data = [],
    string $message = '',
    int $statusCode = 200
): never {
    sendJson(
        [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ],
        $statusCode
    );
}


/**
 * エラーレスポンス
 */
function errorResponse(
    string $code,
    string $message,
    int $statusCode = 400,
    ?string $requestId = null
): never {
    $error = [
        'code' => $code,
        'message' => $message,
    ];

    if ($requestId !== null && $requestId !== '') {
        $error['requestId'] = $requestId;
    }

    sendJson(
        [
            'success' => false,
            'error' => $error,
        ],
        $statusCode
    );
}


/* =========================================================
 * Request ID
 * ======================================================= */

$requestId = getRequestId();

header('X-Request-Id: ' . $requestId);


/* =========================================================
 * セキュリティヘッダー
 * ======================================================= */

header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header(
    'Content-Security-Policy: ' .
    "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline'; " .
    "style-src 'self' 'unsafe-inline'; " .
    "img-src 'self' data:; " .
    "connect-src 'self'; " .
    "font-src 'self' data:; " .
    "object-src 'none'; " .
    "base-uri 'self'; " .
    "form-action 'self'; " .
    "frame-ancestors 'self'"
);


/* =========================================================
 * HTTPS判定
 * ======================================================= */

$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);


/* =========================================================
 * Session
 * ======================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}


/* =========================================================
 * Origin / CORS
 * ======================================================= */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$isAllowedOrigin = false;

if ($origin !== '') {
    $isAllowedOrigin = in_array(
        $origin,
        CORS_ALLOWED_ORIGINS,
        true
    );
}


/**
 * 同一オリジン判定
 */
function isSameOriginRequest(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin === '') {
        return true;
    }

    $scheme =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? '';

    if ($host === '') {
        return false;
    }

    return hash_equals(
        $scheme . '://' . $host,
        $origin
    );
}


/*
 * CORS設定。
 *
 * 同一オリジンの場合はCORSヘッダーを付けない。
 */
if ($origin !== '') {
    if ($isAllowedOrigin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    } elseif (!isSameOriginRequest()) {
        /*
         * 許可されていないcross-origin。
         *
         * preflightの場合は後段で処理する。
         * 通常リクエストではブラウザからレスポンスを
         * 読ませない構成とする。
         */
    }
}


/* =========================================================
 * OPTIONS / CORS Preflight
 * ======================================================= */

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {

    /*
     * cross-origin preflightの場合だけ処理。
     */
    if ($origin !== '' && !$isAllowedOrigin && !isSameOriginRequest()) {
        http_response_code(403);

        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(
            [
                'success' => false,
                'error' => [
                    'code' => 'CORS_ORIGIN_DENIED',
                    'message' => '許可されていないOriginです。',
                    'requestId' => $requestId,
                ],
            ],
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        exit;
    }

    /*
     * preflightに業務副作用を発生させない。
     */
    header(
        'Access-Control-Allow-Methods: GET, POST, OPTIONS'
    );

    $requestedHeaders =
        $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';

    if ($requestedHeaders !== '') {
        /*
         * 実際に利用を許可するヘッダーのみを返す。
         */
        $allowedHeaders = [];

        $requested = array_map(
            'trim',
            explode(',', $requestedHeaders)
        );

        foreach ($requested as $headerName) {
            $normalized = strtolower($headerName);

            if (
                $normalized === 'content-type'
                || $normalized === 'x-csrf-token'
                || $normalized === 'x-request-id'
            ) {
                $allowedHeaders[] = $headerName;
            }
        }

        if ($allowedHeaders !== []) {
            header(
                'Access-Control-Allow-Headers: ' .
                implode(', ', $allowedHeaders)
            );
        }
    }

    if ($isAllowedOrigin) {
        header('Access-Control-Allow-Credentials: true');
    }

    http_response_code(204);
    exit;
}


/* =========================================================
 * HTTPメソッド
 * ======================================================= */

if (!in_array($method, ['GET', 'POST'], true)) {
    errorResponse(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405,
        $requestId
    );
}


/* =========================================================
 * PHP Error Handler
 * ======================================================= */

set_error_handler(
    function (
        int $severity,
        string $message,
        string $file,
        int $line
    ) use ($requestId): bool {

        /*
         * Warning / Notice等をレスポンスへ直接出さない。
         */
        appLog(
            'PHP',
            $message,
            [
                'requestId' => $requestId,
                'severity' => $severity,
                'file' => basename($file),
                'line' => $line,
            ]
        );

        /*
         * 通常のPHPエラーを例外化。
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


/* =========================================================
 * Shutdown Handler
 * ======================================================= */

register_shutdown_function(
    function () use ($requestId): void {

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

        if (in_array($error['type'], $fatalTypes, true)) {

            appLog(
                'FATAL',
                $error['message'],
                [
                    'requestId' => $requestId,
                    'file' => basename($error['file']),
                    'line' => $error['line'],
                    'type' => $error['type'],
                ]
            );

            /*
             * 既にレスポンスが開始されている可能性があるため、
             * shutdown handlerだけでJSON化できるとは考えない。
             *
             * 実際のFatal Error対策は構文検査・Apache実動作確認と
             * あわせて行う。
             */
        }
    }
);


/* =========================================================
 * 入力処理
 * ======================================================= */

/**
 * POST JSON取得
 */
function getJsonInput(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    try {
        $data = json_decode(
            $raw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new InvalidArgumentException(
            'JSON形式が不正です。'
        );
    }

    if (!is_array($data)) {
        throw new InvalidArgumentException(
            'JSONオブジェクトを指定してください。'
        );
    }

    return $data;
}


/**
 * action取得
 */
function getAction(string $method): string
{
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $action = $_GET['action'] ?? '';

        if ($action === '') {
            $input = getJsonInput();
            $action = $input['action'] ?? '';
        }
    }

    if (!is_string($action)) {
        return '';
    }

    return trim($action);
}


/* =========================================================
 * CSRF
 * ======================================================= */

/**
 * CSRF token取得/生成
 */
function getCsrfToken(): string
{
    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || $_SESSION['csrf_token'] === ''
    ) {
        $_SESSION['csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['csrf_token'];
}


/**
 * CSRF検証
 */
function validateCsrfToken(
    string $requestId
): void {

    $headerToken =
        $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    $bodyToken = '';

    if ($headerToken === '') {
        try {
            $input = getJsonInput();

            if (isset($input['csrfToken'])) {
                $bodyToken = is_string($input['csrfToken'])
                    ? $input['csrfToken']
                    : '';
            }
        } catch (Throwable) {
            /*
             * JSONエラーは後段で入力エラーとして扱う。
             */
        }
    }

    $token =
        $headerToken !== ''
            ? $headerToken
            : $bodyToken;

    $sessionToken =
        $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($sessionToken)
        || $sessionToken === ''
    ) {
        errorResponse(
            'CSRF_TOKEN_INVALID',
            'CSRFトークンが不正です。',
            403,
            $requestId
        );
    }

    if (
        $token === ''
        || !is_string($token)
        || !hash_equals($sessionToken, $token)
    ) {
        errorResponse(
            'CSRF_TOKEN_INVALID',
            'CSRFトークンが不正です。',
            403,
            $requestId
        );
    }
}


/* =========================================================
 * Action定義
 * ======================================================= */

const ALLOWED_GET_ACTIONS = [
    'health',
    'csrf',
];

const ALLOWED_POST_ACTIONS = [
    /*
     * 後続段階で追加:
     *
     * survey_create
     * survey_update
     * survey_delete
     * survey_status
     * response_submit
     * customer_sync
     * mail_send
     * etc.
     */
];


/* =========================================================
 * API処理
 * ======================================================= */

try {

    $action = getAction($method);

    /*
     * actionがない場合。
     */
    if ($action === '') {

        /*
         * actionなしGETは画面表示として扱う。
         */
        if ($method === 'GET') {
            $screen = $_GET['screen'] ?? 'admin';

            if (!is_string($screen)) {
                $screen = 'admin';
            }

            $screen = trim($screen);

            /*
             * 最小画面。
             *
             * 実際の管理画面は後続段階で実装。
             */
            header(
                'Content-Type: text/html; charset=UTF-8'
            );

            $safeScreen = htmlspecialchars(
                $screen,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            /*
             * 現在アクセスされているindex.phpのURLを
             * サーバー側で生成。
             *
             * JavaScript側で物理パスを推測しない。
             */
            $scheme =
                $isHttps ? 'https' : 'http';

            $host =
                $_SERVER['HTTP_HOST'] ?? 'localhost';

            $script =
                $_SERVER['SCRIPT_NAME'] ?? '/index.php';

            $entryPoint =
                $scheme . '://' . $host . $script;

            $safeEntryPoint = htmlspecialchars(
                $entryPoint,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $safeRequestId = htmlspecialchars(
                $requestId,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            echo '<!doctype html>';
            echo '<html lang="ja">';
            echo '<head>';
            echo '<meta charset="UTF-8">';
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
            echo '<meta name="application-entry-point" content="';
            echo $safeEntryPoint;
            echo '">';
            echo '<title>';
            echo htmlspecialchars(
                APP_NAME,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            echo '</title>';
            echo '</head>';
            echo '<body>';

            echo '<h1>';
            echo htmlspecialchars(
                APP_NAME,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            echo '</h1>';

            echo '<p>通信基盤 第1段階</p>';

            echo '<p>screen: ';
            echo $safeScreen;
            echo '</p>';

            echo '<p>Request ID: ';
            echo $safeRequestId;
            echo '</p>';

            echo '</body>';
            echo '</html>';

            exit;
        }

        errorResponse(
            'ACTION_REQUIRED',
            'actionを指定してください。',
            400,
            $requestId
        );
    }


    /* =====================================================
     * GET
     * =================================================== */

    if ($method === 'GET') {

        if (!in_array(
            $action,
            ALLOWED_GET_ACTIONS,
            true
        )) {
            errorResponse(
                'ACTION_NOT_FOUND',
                '指定されたactionは存在しません。',
                404,
                $requestId
            );
        }


        /* ---------------------------------------------
         * health
         * ------------------------------------------- */

        if ($action === HEALTH_ACTION) {

            successResponse(
                [
                    'status' => 'ok',
                    'application' => APP_NAME,
                    'phpVersion' => PHP_VERSION,
                    'requestId' => $requestId,
                    'timestamp' => date('c'),
                ]
            );
        }


        /* ---------------------------------------------
         * csrf
         * ------------------------------------------- */

        if ($action === CSRF_ACTION) {

            $token = getCsrfToken();

            successResponse(
                [
                    'csrfToken' => $token,
                    'requestId' => $requestId,
                ]
            );
        }
    }


    /* =====================================================
     * POST
     * =================================================== */

    if ($method === 'POST') {

        if (!in_array(
            $action,
            ALLOWED_POST_ACTIONS,
            true
        )) {
            errorResponse(
                'ACTION_NOT_FOUND',
                '指定されたactionは存在しません。',
                404,
                $requestId
            );
        }

        /*
         * 現段階ではPOST業務APIは未実装。
         *
         * 後続段階で各actionを追加する。
         */
        validateCsrfToken($requestId);
    }


    /*
     * 想定外。
     */
    errorResponse(
        'INTERNAL_SERVER_ERROR',
        'サーバー内部でエラーが発生しました。',
        500,
        $requestId
    );

} catch (InvalidArgumentException $e) {

    appLog(
        'WARNING',
        $e->getMessage(),
        [
            'requestId' => $requestId,
            'action' => $action ?? '',
        ]
    );

    errorResponse(
        'VALIDATION_ERROR',
        $e->getMessage(),
        422,
        $requestId
    );

} catch (Throwable $e) {

    /*
     * 内部例外を利用者へ直接出さない。
     */
    appLog(
        'ERROR',
        $e->getMessage(),
        [
            'requestId' => $requestId,
            'exception' => get_class($e),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ]
    );

    errorResponse(
        'INTERNAL_SERVER_ERROR',
        'サーバー内部でエラーが発生しました。',
        500,
        $requestId
    );
}
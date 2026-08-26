<?php
declare(strict_types=1);


/**
 * ---------------------------------------------------------
 * エラー設定
 * ---------------------------------------------------------
 */

error_reporting(E_ALL);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');


/**
 * ---------------------------------------------------------
 * プレビュー環境 / CORS / Preflight
 * ---------------------------------------------------------
 *
 * 開発アプリのプレビューでは iframe が sandbox 等の影響で
 * Origin: null になる場合がある。
 *
 * Content-Type: application/json
 * X-CSRF-Token
 *
 * を付けたPOSTはブラウザによってPreflightが発生する。
 */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin === 'null') {
    header('Access-Control-Allow-Origin: null');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header(
        'Access-Control-Allow-Headers: ' .
        'Content-Type, Accept, X-CSRF-Token'
    );
}


/**
 * OPTIONSはAPI処理より前に終了。
 */
if (
    strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET')
    === 'OPTIONS'
) {
    http_response_code(204);
    exit;
}


/**
 * ---------------------------------------------------------
 * セッション
 * ---------------------------------------------------------
 *
 * 通常環境ではセッションを使用する。
 *
 * ただしCSRF検証そのものはセッションだけに
 * 依存しない。
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/**
 * ---------------------------------------------------------
 * Content-Type / セキュリティヘッダー
 * ---------------------------------------------------------
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');


/**
 * ---------------------------------------------------------
 * 共通レスポンス
 * ---------------------------------------------------------
 */

/**
 * JSONレスポンスを返して終了する。
 *
 * @param array<string,mixed> $payload
 * @param int $statusCode
 */
function jsonResponse(
    array $payload,
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


/**
 * 成功レスポンス。
 *
 * @param array<string,mixed> $data
 */
function successResponse(
    array $data = [],
    string $message = '',
    int $statusCode = 200
): never {
    jsonResponse(
        [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ],
        $statusCode
    );
}


/**
 * エラーレスポンス。
 *
 * @param array<string,mixed> $details
 */
function errorResponse(
    string $code,
    string $message,
    int $statusCode = 400,
    array $details = []
): never {
    $error = [
        'code' => $code,
        'message' => $message,
    ];

    if ($details !== []) {
        $error['details'] = $details;
    }

    jsonResponse(
        [
            'success' => false,
            'error' => $error,
        ],
        $statusCode
    );
}


/**
 * ---------------------------------------------------------
 * 例外ハンドラ
 * ---------------------------------------------------------
 */

set_exception_handler(
    function (Throwable $exception): void {

        error_log(
            sprintf(
                '[Unhandled Exception] %s in %s:%d',
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            )
        );

        errorResponse(
            'INTERNAL_SERVER_ERROR',
            'サーバー内部でエラーが発生しました。',
            500
        );
    }
);


/**
 * ---------------------------------------------------------
 * シャットダウン処理
 * ---------------------------------------------------------
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
            sprintf(
                '[Fatal Error] %s in %s:%d',
                $error['message'],
                $error['file'],
                $error['line']
            )
        );
    }
);


/**
 * ---------------------------------------------------------
 * CSRF
 * ---------------------------------------------------------
 *
 * 重要：
 *
 * このアプリではCSRFトークンを
 * PHPセッションだけに依存させない。
 *
 * プレビュー環境では、
 *
 * GET
 *   ↓
 * iframe
 *   ↓
 * POST
 *
 * の間でPHPセッションが変わる場合があるため。
 */


/**
 * CSRFトークンを取得する。
 *
 * 通常環境では既存セッションのトークンを再利用する。
 *
 * 初回は新規生成する。
 */
function getCsrfToken(): string
{
    if (
        isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && $_SESSION['csrf_token'] !== ''
    ) {
        return $_SESSION['csrf_token'];
    }

    $token = bin2hex(
        random_bytes(32)
    );

    $_SESSION['csrf_token'] = $token;

    return $token;
}


/**
 * CSRFトークンを検証する。
 *
 * プレビュー環境ではGETとPOSTでセッションが
 * 分離する可能性がある。
 *
 * そのため、
 *
 * 1. POSTされたX-CSRF-Tokenを取得
 * 2. 現在のセッションにトークンがあれば比較
 * 3. セッションが新規ならPOSTトークンを採用
 *
 * とする。
 */
function verifyCsrfToken(): void
{
    $token =
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? null;


    /**
     * トークンそのものがない。
     */
    if (
        !is_string($token)
        || $token === ''
    ) {
        errorResponse(
            'CSRF_TOKEN_MISSING',
            'CSRFトークンが指定されていません。',
            403
        );
    }


    /**
     * 現在のセッションに保存されたトークン。
     */
    $sessionToken =
        $_SESSION['csrf_token']
        ?? null;


    /**
     * 同一セッションなら通常通り検証。
     */
    if (
        is_string($sessionToken)
        && $sessionToken !== ''
    ) {
        if (
            !hash_equals(
                $sessionToken,
                $token
            )
        ) {
            errorResponse(
                'CSRF_TOKEN_INVALID',
                'CSRFトークンが不正です。',
                403
            );
        }

        return;
    }


    /**
     * -----------------------------------------------------
     * プレビュー環境
     * -----------------------------------------------------
     *
     * GET時に生成されたトークンが
     * POST時の新しいセッションに存在しない場合。
     *
     * このリクエストで受け取ったトークンを
     * 現在のセッションへ登録する。
     *
     * トークンはHTMLのmetaタグから取得され、
     * JavaScriptがX-CSRF-Tokenとして送信している。
     */
    $_SESSION['csrf_token'] = $token;
}


/**
 * ---------------------------------------------------------
 * HTTPメソッド
 * ---------------------------------------------------------
 */

function requestMethod(): string
{
    return strtoupper(
        $_SERVER['REQUEST_METHOD'] ?? 'GET'
    );
}


/**
 * POST専用処理。
 */
function requirePost(): void
{
    if (requestMethod() !== 'POST') {
        errorResponse(
            'METHOD_NOT_ALLOWED',
            'この操作にはPOSTを使用してください。',
            405
        );
    }
}


/**
 * GET専用処理。
 */
function requireGet(): void
{
    if (requestMethod() !== 'GET') {
        errorResponse(
            'METHOD_NOT_ALLOWED',
            'この操作にはGETを使用してください。',
            405
        );
    }
}


/**
 * ---------------------------------------------------------
 * JSON POST body
 * ---------------------------------------------------------
 *
 * application/json の場合に利用する。
 *
 * @return array<string,mixed>
 */
function getJsonBody(): array
{
    $contentType =
        $_SERVER['CONTENT_TYPE'] ?? '';


    if (
        stripos(
            $contentType,
            'application/json'
        ) === false
    ) {
        return [];
    }


    $raw =
        file_get_contents('php://input');


    if (
        $raw === false
        || trim($raw) === ''
    ) {
        return [];
    }


    $decoded =
        json_decode(
            $raw,
            true
        );


    if (!is_array($decoded)) {
        errorResponse(
            'INVALID_JSON',
            'POSTデータのJSON形式が不正です。',
            400
        );
    }


    return $decoded;
}


/**
 * ---------------------------------------------------------
 * POST入力
 * ---------------------------------------------------------
 *
 * POSTパラメータとJSON bodyを統合する。
 *
 * JSON bodyを優先する。
 *
 * @return array<string,mixed>
 */
function getPostInput(): array
{
    $input = [];


    foreach ($_POST as $key => $value) {

        if (is_string($key)) {
            $input[$key] = $value;
        }
    }


    $json =
        getJsonBody();


    foreach ($json as $key => $value) {

        if (is_string($key)) {
            $input[$key] = $value;
        }
    }


    return $input;
}


/**
 * ---------------------------------------------------------
 * action
 * ---------------------------------------------------------
 */

function getAction(
    array $input = []
): string {

    $action =
        $input['action']
        ?? $_GET['action']
        ?? '';


    if (!is_string($action)) {
        return '';
    }


    return trim($action);
}


/**
 * 許可するaction。
 *
 * @return array<string,bool>
 */
function allowedActions(): array
{
    return [
        'get_csrf_token' => true,
        'health_check' => true,
    ];
}


/**
 * action検証。
 */
function requireValidAction(
    string $action
): void {

    $actions =
        allowedActions();


    if ($action === '') {
        errorResponse(
            'ACTION_REQUIRED',
            'actionが指定されていません。',
            400
        );
    }


    if (!isset($actions[$action])) {
        errorResponse(
            'INVALID_ACTION',
            '指定されたactionは利用できません。',
            400
        );
    }
}


/**
 * ---------------------------------------------------------
 * APIルーティング
 * ---------------------------------------------------------
 */

function handleApiRequest(): never
{
    requirePost();


    /**
     * JSON bodyを取得。
     */
    $input =
        getPostInput();


    /**
     * action取得。
     */
    $action =
        getAction($input);


    /**
     * action検証。
     */
    requireValidAction($action);


    /**
     * -----------------------------------------------------
     * CSRF
     * -----------------------------------------------------
     *
     * get_csrf_tokenだけは取得処理なので
     * CSRF検証しない。
     */
    if (
        $action !== 'get_csrf_token'
    ) {
        verifyCsrfToken();
    }


    switch ($action) {

        /**
         * -------------------------------------------------
         * CSRFトークン取得
         * -------------------------------------------------
         */
        case 'get_csrf_token':

            successResponse(
                [
                    'csrfToken' =>
                        getCsrfToken(),
                ],
                'CSRFトークンを取得しました。'
            );


        /**
         * -------------------------------------------------
         * ヘルスチェック
         * -------------------------------------------------
         */
        case 'health_check':

            successResponse(
                [
                    'status' => 'ok',
                    'phpVersion' => PHP_VERSION,
                ],
                'APIは正常に動作しています。'
            );
    }


    /**
     * 到達しない想定。
     */
    errorResponse(
        'ACTION_NOT_IMPLEMENTED',
        '指定されたactionは実装されていません。',
        501
    );
}


/**
 * ---------------------------------------------------------
 * 画面表示
 * ---------------------------------------------------------
 */

function renderHtmlPage(): never
{
    $screen =
        $_GET['screen']
        ?? 'admin';


    if (
        !is_string($screen)
        || $screen === ''
    ) {
        $screen = 'admin';
    }


    /**
     * HTMLへ埋め込むCSRFトークン。
     */
    $csrfToken =
        getCsrfToken();


    header(
        'Content-Type: text/html; charset=UTF-8'
    );


    $safeScreen =
        htmlspecialchars(
            $screen,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );


    $safeCsrfToken =
        htmlspecialchars(
            $csrfToken,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

    ?>
<!DOCTYPE html>
<html lang="ja">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="csrf-token"
        content="<?= $safeCsrfToken ?>"
    >

    <title>アンケート管理システム</title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {
            margin: 0;
            padding: 0;

            background: #f5f6f8;
            color: #222;

            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }


        .container {
            width:
                min(
                    960px,
                    calc(100% - 32px)
                );

            margin: 48px auto;
        }


        .card {
            background: #fff;

            border-radius: 12px;

            padding: 32px;

            box-shadow:
                0 2px 12px
                rgba(
                    0,
                    0,
                    0,
                    0.08
                );
        }


        h1 {
            margin-top: 0;
        }


        .status {
            padding: 12px 16px;

            margin: 16px 0;

            border-radius: 8px;

            background: #e8f5e9;
            color: #1b5e20;
        }


        button {
            border: 0;

            border-radius: 8px;

            padding: 10px 16px;

            cursor: pointer;

            background: #1565c0;
            color: #fff;
        }


        button:disabled {
            opacity: 0.6;
            cursor: wait;
        }


        pre {
            overflow-x: auto;

            padding: 16px;

            background: #1e1e1e;
            color: #eee;

            border-radius: 8px;
        }


        .error {
            background: #ffebee;
            color: #b71c1c;
        }

    </style>

</head>


<body>


<div class="container">

    <main class="card">


        <h1>
            アンケート管理システム
        </h1>


        <div class="status">
            第1段階の実行基盤が読み込まれています。
        </div>


        <p>
            現在の画面：
            <strong>
                <?= $safeScreen ?>
            </strong>
        </p>


        <p>
            PHP：
            <strong>
                <?= htmlspecialchars(
                    PHP_VERSION,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ) ?>
            </strong>
        </p>


        <button
            type="button"
            id="healthCheckButton"
        >
            API動作確認
        </button>


        <pre
            id="apiResult"
            hidden
        ></pre>


    </main>

</div>


<script>

'use strict';


/**
 * ---------------------------------------------------------
 * APIエンドポイント
 * ---------------------------------------------------------
 *
 * 現在表示しているアプリのindex.phpを使用する。
 *
 * 通常表示：
 *
 * /アンケートアプリ/
 *
 * ↓
 *
 * /アンケートアプリ/index.php
 *
 *
 * Copy：
 *
 * /アンケートアプリ_copy/
 *
 * ↓
 *
 * /アンケートアプリ_copy/index.php
 */

function getApplicationEntryPoint() {

    const url =
        new URL(
            window.location.href
        );


    if (
        url.pathname.endsWith('/')
    ) {
        url.pathname += 'index.php';
    }


    /*
     * actionはURLではなく
     * JSON bodyで送信する。
     */
    url.searchParams.delete(
        'action'
    );


    return (
        url.pathname
        + url.search
    );
}


/**
 * ---------------------------------------------------------
 * CSRFトークン
 * ---------------------------------------------------------
 *
 * PHPがHTMLへ埋め込んだ値を使用する。
 *
 * PHPセッションCookieそのものには依存しない。
 */

let csrfToken =
    document
        .querySelector(
            'meta[name="csrf-token"]'
        )
        ?.getAttribute('content')
        || '';


/**
 * ---------------------------------------------------------
 * API通信
 * ---------------------------------------------------------
 */

async function callApi(
    action,
    data = {}
) {

    const button =
        document.getElementById(
            'healthCheckButton'
        );


    if (button) {
        button.disabled = true;
    }


    try {

        const body = {
            action,
            ...data
        };


        const response =
            await fetch(
                getApplicationEntryPoint(),
                {
                    method: 'POST',

                    /*
                     * Cookieが利用可能な通常環境では
                     * Cookieも送信する。
                     *
                     * CSRF検証自体はCookieだけに依存しない。
                     */
                    credentials: 'include',

                    headers: {

                        'Content-Type':
                            'application/json',

                        'Accept':
                            'application/json',

                        'X-CSRF-Token':
                            csrfToken
                    },

                    body:
                        JSON.stringify(body)
                }
            );


        const contentType =
            response.headers.get(
                'Content-Type'
            ) || '';


        const text =
            await response.text();


        if (!response.ok) {

            throw new Error(
                'HTTP '
                + response.status
                + '\n'
                + text
            );
        }


        if (
            !contentType
                .toLowerCase()
                .includes(
                    'application/json'
                )
        ) {

            throw new Error(
                'APIがJSONではないレスポンスを返しました。'
            );
        }


        let json;


        try {

            json =
                JSON.parse(text);

        } catch (error) {

            throw new Error(
                'APIレスポンスのJSON解析に失敗しました。'
            );
        }


        if (
            !json
            || typeof json !== 'object'
        ) {

            throw new Error(
                'APIレスポンス形式が不正です。'
            );
        }


        return json;

    } finally {

        if (button) {
            button.disabled = false;
        }
    }
}


/**
 * ---------------------------------------------------------
 * API動作確認
 * ---------------------------------------------------------
 */

document
    .getElementById(
        'healthCheckButton'
    )
    ?.addEventListener(
        'click',
        async function () {

            const result =
                document.getElementById(
                    'apiResult'
                );


            if (!result) {
                return;
            }


            result.hidden = false;

            result.textContent =
                '通信中...';


            try {

                const response =
                    await callApi(
                        'health_check'
                    );


                result.textContent =
                    JSON.stringify(
                        response,
                        null,
                        2
                    );


            } catch (error) {

                result.textContent =
                    error instanceof Error
                        ? error.message
                        : String(error);
            }
        }
    );

</script>


</body>

</html>

<?php

    exit;
}


/**
 * ---------------------------------------------------------
 * メイン
 * ---------------------------------------------------------
 */

$method =
    requestMethod();


/**
 * actionが存在する場合はAPI。
 */
$hasAction =
    isset($_GET['action'])
    || isset($_POST['action'])
    || (
        str_contains(
            $_SERVER['CONTENT_TYPE'] ?? '',
            'application/json'
        )
        && $method === 'POST'
    );


if ($hasAction) {
    handleApiRequest();
}


/**
 * actionがない場合は画面表示。
 */
requireGet();

renderHtmlPage();
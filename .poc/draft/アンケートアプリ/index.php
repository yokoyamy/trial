<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * 共通エントリーポイント
 * ============================================================
 *
 * 同居アプリとのセッション衝突を防止する。
 *
 * 重要：
 * - session_name() を専用名にする
 * - session cookie 名も専用にする
 * - 他アプリの $_SESSION を前提にしない
 * - HTML出力より前に初期化する
 * - Fatal Error / Exception を画面に直接吐かない
 */

/* ------------------------------------------------------------
 * 1. アプリケーション定数
 * ------------------------------------------------------------ */

const APP_NAME = 'survey_manager';

/*
 * 同居アプリと絶対に重複しないセッション名
 */
const SESSION_NAME = 'SURVEY_MANAGER_SESSION';


/* ------------------------------------------------------------
 * 2. PHPエラー設定
 * ------------------------------------------------------------ */

/*
 * 開発時はログへ記録する。
 *
 * display_errors を ON にすると、
 * PHP Warning / Fatal Error によって
 * JSONレスポンスや画面HTMLが壊れる可能性がある。
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

error_reporting(E_ALL);


/* ------------------------------------------------------------
 * 3. 出力バッファ
 * ------------------------------------------------------------ */

ob_start();


/* ------------------------------------------------------------
 * 4. 共通例外ハンドラ
 * ------------------------------------------------------------ */

set_exception_handler(
    function (Throwable $e): void {

        error_log(
            sprintf(
                '[%s] Uncaught exception: %s in %s:%d',
                APP_NAME,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            )
        );

        /*
         * すでにHTTPレスポンスを開始している場合は、
         * 可能な範囲で出力を破棄する。
         */
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);

        /*
         * API要求ならJSON。
         */
        $isApi = isset($_GET['action'])
            || (
                isset($_SERVER['CONTENT_TYPE'])
                && str_contains(
                    strtolower((string)$_SERVER['CONTENT_TYPE']),
                    'application/json'
                )
            );

        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode(
                [
                    'success' => false,
                    'error' => [
                        'code' => 'INTERNAL_ERROR',
                        'message' => 'サーバー内部エラーが発生しました。'
                    ]
                ],
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );

            exit;
        }

        /*
         * 通常画面。
         */
        header('Content-Type: text/html; charset=utf-8');

        echo '<!doctype html>';
        echo '<html lang="ja">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<title>システムエラー</title>';
        echo '</head>';
        echo '<body>';
        echo '<h1>システムエラー</h1>';
        echo '<p>処理中にエラーが発生しました。</p>';
        echo '</body>';
        echo '</html>';

        exit;
    }
);


/* ------------------------------------------------------------
 * 5. PHP Fatal Error 対策
 * ------------------------------------------------------------ */

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

        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        error_log(
            sprintf(
                '[%s] Fatal Error: %s in %s:%d',
                APP_NAME,
                $error['message'],
                $error['file'],
                $error['line']
            )
        );

        /*
         * 既に出力されている内容を破棄。
         */
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);

        /*
         * ここではJSONを要求しているかを
         * 簡易判定する。
         */
        $isApi = isset($_GET['action']);

        if ($isApi) {

            header(
                'Content-Type: application/json; charset=utf-8'
            );

            echo json_encode(
                [
                    'success' => false,
                    'error' => [
                        'code' => 'FATAL_ERROR',
                        'message' => 'サーバー内部エラーが発生しました。'
                    ]
                ],
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );

            return;
        }

        header('Content-Type: text/html; charset=utf-8');

        echo '<!doctype html>';
        echo '<html lang="ja">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<title>システムエラー</title>';
        echo '</head>';
        echo '<body>';
        echo '<h1>システムエラー</h1>';
        echo '<p>サーバー内部でエラーが発生しました。</p>';
        echo '</body>';
        echo '</html>';
    }
);


/* ------------------------------------------------------------
 * 6. セッション衝突防止
 * ------------------------------------------------------------ */

/*
 * 既に別アプリが session_start() している場合、
 * session_name() を変更してから session_start() することはできない。
 *
 * したがって、まず現在のセッション状態を確認する。
 */

if (session_status() === PHP_SESSION_ACTIVE) {

    /*
     * ここで別アプリのセッションをそのまま
     * アンケートシステムとして使用してはいけない。
     *
     * 同居アプリと共通セッションになっている場合、
     * 原則としてWebサーバー側のCookie構成を分離する。
     *
     * 開発中に検知できるようログへ記録。
     */
    if (session_name() !== SESSION_NAME) {

        error_log(
            sprintf(
                '[%s] Session collision detected. Current session name: %s',
                APP_NAME,
                session_name()
            )
        );

        /*
         * ここでは勝手に他アプリのセッションを
         * 破棄しない。
         *
         * 他アプリの動作を壊すため。
         */
    }

} else {

    /*
     * このアプリ専用セッション名。
     */
    session_name(SESSION_NAME);

    /*
     * Cookieも専用化する。
     *
     * path="/" にすると同一ドメイン上の別アプリにも
     * Cookieが送られる可能性がある。
     *
     * 可能なら、このアプリの公開ディレクトリに合わせる。
     *
     * 例：
     * /survey/
     */
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}


/* ------------------------------------------------------------
 * 7. アプリ専用セッション領域
 * ------------------------------------------------------------ */

/*
 * 他アプリと同じ $_SESSION を直接使わない。
 *
 * 必ず survey_manager 配下に入れる。
 */

if (!isset($_SESSION['survey_manager'])) {
    $_SESSION['survey_manager'] = [];
}


/* ------------------------------------------------------------
 * 8. CSRF
 * ------------------------------------------------------------ */

if (
    !isset($_SESSION['survey_manager']['csrf_token'])
    || !is_string($_SESSION['survey_manager']['csrf_token'])
    || $_SESSION['survey_manager']['csrf_token'] === ''
) {
    $_SESSION['survey_manager']['csrf_token'] =
        bin2hex(random_bytes(32));
}


/* ------------------------------------------------------------
 * 9. 共通レスポンス
 * ------------------------------------------------------------ */

function successResponse(
    mixed $data = [],
    string $message = ''
): never {

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}


function errorResponse(
    string $code,
    string $message,
    int $status = 400
): never {

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

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
    );

    exit;
}


/* ------------------------------------------------------------
 * 10. POST時CSRF確認
 * ------------------------------------------------------------ */

function verifyCsrf(): void
{
    $token = $_POST['csrf_token']
        ?? null;

    if (
        !is_string($token)
        || $token === ''
    ) {
        errorResponse(
            'CSRF_TOKEN_REQUIRED',
            'CSRFトークンがありません。',
            403
        );
    }

    $sessionToken =
        $_SESSION['survey_manager']['csrf_token']
        ?? '';

    if (
        !is_string($sessionToken)
        || !hash_equals(
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
}


/* ------------------------------------------------------------
 * 11. action取得
 * ------------------------------------------------------------ */

$action = $_POST['action']
    ?? $_GET['action']
    ?? null;


/* ------------------------------------------------------------
 * 12. API処理
 * ------------------------------------------------------------ */

if ($action !== null) {

    if (!is_string($action) || $action === '') {
        errorResponse(
            'INVALID_ACTION',
            'actionが指定されていません。',
            400
        );
    }

    /*
     * POST業務処理。
     */
    $postActions = [
        'save_survey',
        'delete_survey',
        'duplicate_survey',
        'publish_survey',
        'stop_survey',
        'resume_survey',
        'save_question',
        'delete_question',
        'save_condition',
        'send_mail',
        'resend_mail',
        'sync_customers',
        'test_kintone',
        'get_kintone_fields',
        'save_kintone',
        'save_smtp',
        'test_smtp',
        'save_settings',
        'submit_answer',
    ];

    if (in_array($action, $postActions, true)) {

        if (
            ($_SERVER['REQUEST_METHOD'] ?? 'GET')
            !== 'POST'
        ) {
            errorResponse(
                'METHOD_NOT_ALLOWED',
                'この操作にはPOSTが必要です。',
                405
            );
        }

        verifyCsrf();
    }


    /*
     * APIルーティング。
     *
     * 実際の業務処理はここから
     * 各サービス・業務クラスへ分離する。
     */
    switch ($action) {

        case 'health':
            successResponse(
                [
                    'app' => APP_NAME,
                    'session_name' => session_name(),
                    'php_version' => PHP_VERSION,
                ],
                '正常に動作しています。'
            );


        default:
            errorResponse(
                'UNKNOWN_ACTION',
                '未定義のactionです。',
                400
            );
    }
}


/* ------------------------------------------------------------
 * 13. 画面表示
 * ------------------------------------------------------------ */

$screen = $_GET['screen']
    ?? 'admin';


/*
 * 画面IDを許可リストで管理する。
 */
$allowedScreens = [
    'admin',
    'surveys',
    'survey',
    'questions',
    'question-edit',
    'conditions',
    'customers',
    'send',
    'send-confirm',
    'send-history',
    'summary',
    'kintone',
    'smtp',
    'settings',

    'start',
    'answer',
    'confirm',
    'complete',
    'already-answered',
    'unavailable',
];


if (
    !is_string($screen)
    || !in_array($screen, $allowedScreens, true)
) {
    http_response_code(404);

    header(
        'Content-Type: text/html; charset=utf-8'
    );

    echo '<h1>404</h1>';
    echo '<p>指定された画面は存在しません。</p>';

    exit;
}


/* ------------------------------------------------------------
 * 14. 最低限の画面表示
 * ------------------------------------------------------------ */

header(
    'Content-Type: text/html; charset=utf-8'
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
        body {
            font-family:
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            margin: 0;
            padding: 40px;

            background: #f5f6f8;
            color: #222;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;

            background: #fff;
            padding: 30px;

            border-radius: 10px;
            box-shadow:
                0 2px 10px rgba(0,0,0,.08);
        }

        h1 {
            margin-top: 0;
        }

        .status {
            padding: 15px;
            background: #eef6ff;
            border-left: 4px solid #1976d2;
        }
    </style>
</head>

<body>

<div class="container">

    <h1>アンケート管理システム</h1>

    <div class="status">
        <p>
            画面：
            <?= htmlspecialchars(
                $screen,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </p>

        <p>
            Session：
            <?= htmlspecialchars(
                session_name(),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </p>

        <p>
            PHP：
            <?= htmlspecialchars(
                PHP_VERSION,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </p>
    </div>

</div>

</body>
</html>
<?php

ob_end_flush();
<?php
declare(strict_types=1);

/**
 * ============================================================
 * アンケート管理システム
 * Single Entry : index.php
 *
 * 対応:
 * - Apache24
 * - PHP 8.4 / 8.5
 * - GET  : 画面表示 / 参照
 * - POST : 業務処理
 * - screen による画面識別
 * - action による業務API識別
 * - JSON共通レスポンス
 * - 例外/Fatal Error対策
 * - CSRF
 * - セッション
 * - URLベースの画面状態
 *
 * ※これは実装開始用の基盤コード。
 * ※kintone / SMTP / 業務データ処理は後段で追加する。
 * ============================================================
 */


/* ============================================================
 * 0. 共通初期化
 * ============================================================ */

session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');


/* ============================================================
 * 1. 共通レスポンス
 * ============================================================ */

function successResponse(
    mixed $data = [],
    string $message = ''
): never {
    http_response_code(200);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        [
            'success' => true,
            'data'    => $data,
            'message' => $message,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
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
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

    exit;
}


/* ============================================================
 * 2. HTMLエスケープ
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/* ============================================================
 * 3. CSRF
 * ============================================================ */

function csrfToken(): string
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


function verifyCsrf(?string $token): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    if (
        !is_string($sessionToken) ||
        !is_string($token) ||
        $token === '' ||
        !hash_equals($sessionToken, $token)
    ) {
        errorResponse(
            'CSRF_INVALID',
            'CSRFトークンが不正です。',
            403
        );
    }
}


/* ============================================================
 * 4. リクエストJSON取得
 * ============================================================ */

function getJsonBody(): array
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
    } catch (Throwable) {
        errorResponse(
            'INVALID_JSON',
            'JSON形式が不正です。',
            400
        );
    }

    if (!is_array($data)) {
        errorResponse(
            'INVALID_JSON_STRUCTURE',
            'JSONオブジェクトを指定してください。',
            400
        );
    }

    return $data;
}


/* ============================================================
 * 5. パラメータ取得
 * ============================================================ */

function requestParam(
    string $name,
    ?array $body = null
): ?string {
    if ($body !== null && array_key_exists($name, $body)) {
        $value = $body[$name];

        return is_scalar($value)
            ? (string)$value
            : null;
    }

    if (isset($_POST[$name])) {
        return is_scalar($_POST[$name])
            ? (string)$_POST[$name]
            : null;
    }

    if (isset($_GET[$name])) {
        return is_scalar($_GET[$name])
            ? (string)$_GET[$name]
            : null;
    }

    return null;
}


/* ============================================================
 * 6. ID検証
 * ============================================================ */

function requireId(
    ?string $value,
    string $name
): string {
    if ($value === null || $value === '') {
        errorResponse(
            'MISSING_PARAMETER',
            "{$name}が指定されていません。",
            400
        );
    }

    /*
     * 業務IDはURLから受け取るため、
     * 制御文字や異常な文字列を排除する。
     */
    if (
        !preg_match(
            '/^[A-Za-z0-9_-]+$/',
            $value
        )
    ) {
        errorResponse(
            'INVALID_PARAMETER',
            "{$name}が不正です。",
            400
        );
    }

    return $value;
}


/* ============================================================
 * 7. HTTPメソッド
 * ============================================================ */

function requireMethod(string $method): void
{
    $actual = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');

    if ($actual !== strtoupper($method)) {
        errorResponse(
            'METHOD_NOT_ALLOWED',
            '許可されていないHTTPメソッドです。',
            405
        );
    }
}


/* ============================================================
 * 8. JSONファイル永続化
 * ============================================================ */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';


function ensureDataDirectory(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (
        !mkdir(
            DATA_DIR,
            0775,
            true
        ) &&
        !is_dir(DATA_DIR)
    ) {
        throw new RuntimeException(
            'データディレクトリを作成できません。'
        );
    }
}


function jsonFilePath(string $name): string
{
    ensureDataDirectory();

    return DATA_DIR .
        DIRECTORY_SEPARATOR .
        $name . '.json';
}


function readJsonFile(
    string $name,
    array $default = []
): array {
    $path = jsonFilePath($name);

    if (!file_exists($path)) {
        return $default;
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException(
            "JSONファイルを読み込めません: {$name}"
        );
    }

    if (trim($contents) === '') {
        return $default;
    }

    try {
        $data = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable $e) {
        throw new RuntimeException(
            "JSONファイルが不正です: {$name}",
            0,
            $e
        );
    }

    if (!is_array($data)) {
        throw new RuntimeException(
            "JSON構造が不正です: {$name}"
        );
    }

    return $data;
}


function writeJsonFile(
    string $name,
    array $data
): void {
    $path = jsonFilePath($name);

    $tmp = $path . '.tmp.' . bin2hex(
        random_bytes(8)
    );

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    $fp = fopen($tmp, 'wb');

    if ($fp === false) {
        throw new RuntimeException(
            "一時ファイルを作成できません: {$name}"
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                "ファイルロックを取得できません: {$name}"
            );
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException(
                "JSONを書き込めません: {$name}"
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);

        throw new RuntimeException(
            "JSONファイルを置換できません: {$name}"
        );
    }
}


/* ============================================================
 * 9. 共通例外処理
 * ============================================================ */

set_exception_handler(
    function (Throwable $e): void {

        error_log(
            sprintf(
                '[UnhandledException] %s: %s in %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            )
        );

        $isApi =
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
            || isset($_GET['action']);

        if ($isApi) {
            errorResponse(
                'INTERNAL_ERROR',
                'サーバー内部でエラーが発生しました。',
                500
            );
        }

        http_response_code(500);

        renderErrorPage(
            'サーバー内部でエラーが発生しました。'
        );
    }
);


/*
 * PHP Fatal Error等の終了時処理。
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

        if (!in_array(
            $error['type'],
            $fatalTypes,
            true
        )) {
            return;
        }

        error_log(
            sprintf(
                '[FatalError] %s in %s:%d',
                $error['message'],
                $error['file'],
                $error['line']
            )
        );
    }
);


/* ============================================================
 * 10. 画面定義
 * ============================================================ */

const SCREENS = [

    'admin' => [
        'id'   => 'S01',
        'name' => '管理者トップ',
    ],

    'surveys' => [
        'id'   => 'S02',
        'name' => 'アンケート一覧',
    ],

    'survey' => [
        'id'   => 'S03',
        'name' => 'アンケート編集',
    ],

    'questions' => [
        'id'   => 'S04',
        'name' => '質問管理',
    ],

    'question-edit' => [
        'id'   => 'S05',
        'name' => '質問編集',
    ],

    'conditions' => [
        'id'   => 'S06',
        'name' => '条件分岐設定',
    ],

    'customers' => [
        'id'   => 'S07',
        'name' => '顧客一覧',
    ],

    'send' => [
        'id'   => 'S08',
        'name' => 'メール送信',
    ],

    'send-confirm' => [
        'id'   => 'S09',
        'name' => '送信確認',
    ],

    'send-history' => [
        'id'   => 'S10',
        'name' => '送信履歴',
    ],

    'summary' => [
        'id'   => 'S11',
        'name' => '集計',
    ],

    'kintone' => [
        'id'   => 'S12',
        'name' => 'kintone設定',
    ],

    'smtp' => [
        'id'   => 'S13',
        'name' => 'SMTP設定',
    ],

    'settings' => [
        'id'   => 'S14',
        'name' => 'システム設定',
    ],

    'start' => [
        'id'   => 'A01',
        'name' => '回答開始',
    ],

    'answer' => [
        'id'   => 'A02',
        'name' => '回答',
    ],

    'confirm' => [
        'id'   => 'A03',
        'name' => '回答確認',
    ],

    'complete' => [
        'id'   => 'A04',
        'name' => '回答完了',
    ],

    'already-answered' => [
        'id'   => 'A05',
        'name' => '回答済み',
    ],

    'unavailable' => [
        'id'   => 'A06',
        'name' => '回答不可',
    ],
];


/* ============================================================
 * 11. 画面別ID要件
 * ============================================================ */

function validateScreenParameters(
    string $screen
): void {

    $surveyScreens = [
        'survey',
        'questions',
        'question-edit',
        'conditions',
        'send',
        'send-confirm',
        'summary',
    ];

    $answerScreens = [
        'start',
        'answer',
        'confirm',
        'complete',
        'already-answered',
        'unavailable',
    ];

    if (in_array(
        $screen,
        $surveyScreens,
        true
    )) {
        if (
            !isset($_GET['surveyId']) ||
            $_GET['surveyId'] === ''
        ) {
            renderErrorPage(
                'surveyIdが指定されていません。',
                400
            );

            exit;
        }
    }

    if ($screen === 'question-edit') {

        /*
         * 新規質問ではquestionId不要。
         * 編集時のみquestionIdを使用する。
         */

        if (
            isset($_GET['questionId']) &&
            $_GET['questionId'] !== ''
        ) {
            requireId(
                (string)$_GET['questionId'],
                'questionId'
            );
        }
    }

    if (in_array(
        $screen,
        $answerScreens,
        true
    )) {
        if (
            !isset($_GET['surveyId']) ||
            !isset($_GET['customerId'])
        ) {
            renderErrorPage(
                'surveyIdまたはcustomerIdが指定されていません。',
                400
            );

            exit;
        }
    }
}


/* ============================================================
 * 12. エラー画面
 * ============================================================ */

function renderErrorPage(
    string $message,
    int $status = 500
): void {

    http_response_code($status);

    $csrf = csrfToken();

    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta
            name="viewport"
            content="width=device-width,initial-scale=1"
        >
        <title>エラー</title>

        <style>
            body {
                margin: 0;
                font-family:
                    -apple-system,
                    BlinkMacSystemFont,
                    "Segoe UI",
                    sans-serif;
                background: #f5f6f8;
                color: #222;
            }

            .container {
                max-width: 720px;
                margin: 80px auto;
                padding: 24px;
            }

            .card {
                background: #fff;
                border-radius: 12px;
                padding: 32px;
                box-shadow:
                    0 4px 20px
                    rgba(0,0,0,.08);
            }

            h1 {
                margin-top: 0;
            }

            .message {
                padding: 16px;
                background: #fff3f3;
                border: 1px solid #ffcaca;
                border-radius: 8px;
            }

            a {
                display: inline-block;
                margin-top: 20px;
                padding: 10px 16px;
                background: #2563eb;
                color: #fff;
                text-decoration: none;
                border-radius: 6px;
            }
        </style>
    </head>

    <body>

    <div class="container">

        <div class="card">

            <h1>エラー</h1>

            <div class="message">
                <?= h($message) ?>
            </div>

            <a href="index.php?screen=admin">
                管理者トップへ
            </a>

        </div>

    </div>

    </body>
    </html>
    <?php
}


/* ============================================================
 * 13. 共通HTML
 * ============================================================ */

function renderHeader(
    string $title
): void {

    ?>
    <!DOCTYPE html>
    <html lang="ja">

    <head>

        <meta charset="UTF-8">

        <meta
            name="viewport"
            content="width=device-width,initial-scale=1"
        >

        <title><?= h($title) ?></title>

        <style>

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                font-family:
                    -apple-system,
                    BlinkMacSystemFont,
                    "Segoe UI",
                    sans-serif;
                background: #f5f6f8;
                color: #222;
            }

            header {
                background: #111827;
                color: #fff;
                padding: 16px 24px;
            }

            header a {
                color: #fff;
                text-decoration: none;
            }

            main {
                max-width: 1200px;
                margin: 0 auto;
                padding: 24px;
            }

            .card {
                background: #fff;
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 20px;
                box-shadow:
                    0 2px 12px
                    rgba(0,0,0,.06);
            }

            .grid {
                display: grid;
                grid-template-columns:
                    repeat(auto-fit,minmax(220px,1fr));
                gap: 16px;
            }

            .button {
                display: inline-block;
                padding: 10px 16px;
                border: 0;
                border-radius: 6px;
                background: #2563eb;
                color: #fff;
                text-decoration: none;
                cursor: pointer;
            }

            .button.secondary {
                background: #6b7280;
            }

            .button.danger {
                background: #dc2626;
            }

            .button.success {
                background: #16a34a;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            th,
            td {
                padding: 12px;
                border-bottom: 1px solid #e5e7eb;
                text-align: left;
            }

            input,
            textarea,
            select {
                width: 100%;
                padding: 10px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
            }

            label {
                display: block;
                margin-bottom: 6px;
                font-weight: 600;
            }

            .form-group {
                margin-bottom: 16px;
            }

            .message {
                padding: 12px;
                border-radius: 6px;
                margin-bottom: 16px;
            }

            .message.info {
                background: #eff6ff;
                color: #1e40af;
            }

            .message.warning {
                background: #fffbeb;
                color: #92400e;
            }

            .status {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 999px;
                background: #e5e7eb;
                font-size: 12px;
            }

            .loading {
                display: none;
                margin-left: 8px;
            }

            @media (max-width: 640px) {

                main {
                    padding: 12px;
                }

                header {
                    padding: 12px;
                }

                .card {
                    padding: 16px;
                }

                table {
                    font-size: 13px;
                }
            }

        </style>

    </head>

    <body>

    <header>

        <a
            href="index.php?screen=admin"
            data-screen-link
        >
            アンケート管理システム
        </a>

    </header>

    <main>

    <?php
}


function renderFooter(): void
{
    ?>

    </main>

    <script>

    (() => {

        "use strict";

        /*
         * ========================================================
         * URLベース画面制御
         * ========================================================
         */

        function currentUrl() {
            return new URL(
                window.location.href
            );
        }


        /*
         * --------------------------------------------------------
         * 画面移動
         * --------------------------------------------------------
         */

        function navigate(url) {

            const target =
                new URL(
                    url,
                    window.location.origin
                );

            history.pushState(
                {},
                "",
                target.href
            );

            /*
             * 現段階ではサーバー画面を再描画。
             * SPA化する場合もURLを正として扱う。
             */
            window.location.reload();
        }


        /*
         * --------------------------------------------------------
         * data-screen-link
         * --------------------------------------------------------
         */

        document.addEventListener(
            "click",
            event => {

                const link =
                    event.target.closest(
                        "[data-screen-link]"
                    );

                if (!link) {
                    return;
                }

                const href =
                    link.getAttribute("href");

                if (!href) {
                    return;
                }

                /*
                 * 外部URL等は対象外。
                 */
                if (
                    !href.startsWith(
                        "index.php"
                    )
                ) {
                    return;
                }

                event.preventDefault();

                navigate(href);
            }
        );


        /*
         * --------------------------------------------------------
         * popstate
         * --------------------------------------------------------
         */

        window.addEventListener(
            "popstate",
            () => {

                /*
                 * JavaScript内部状態を正とせず、
                 * 現在URLを正として再構築する。
                 */
                window.location.reload();
            }
        );


        /*
         * --------------------------------------------------------
         * API通信
         * --------------------------------------------------------
         */

        window.App = {

            async post(
                action,
                data = {},
                button = null
            ) {

                const controller =
                    new AbortController();

                const timeout =
                    setTimeout(
                        () => controller.abort(),
                        30000
                    );

                if (button) {
                    button.disabled = true;

                    const loading =
                        button.parentElement
                            ?.querySelector(
                                ".loading"
                            );

                    if (loading) {
                        loading.style.display =
                            "inline";
                    }
                }

                try {

                    const payload = {
                        action,
                        csrf_token:
                            document.querySelector(
                                'meta[name="csrf-token"]'
                            )?.content || "",
                        ...data
                    };


                    const response =
                        await fetch(
                            "index.php",
                            {
                                method: "POST",

                                headers: {
                                    "Content-Type":
                                        "application/json",
                                    "Accept":
                                        "application/json"
                                },

                                body:
                                    JSON.stringify(
                                        payload
                                    ),

                                credentials:
                                    "same-origin",

                                signal:
                                    controller.signal
                            }
                        );


                    const contentType =
                        response.headers.get(
                            "Content-Type"
                        ) || "";


                    const text =
                        await response.text();


                    if (!response.ok) {

                        throw new Error(
                            `HTTP ${response.status}: ${text}`
                        );
                    }


                    if (
                        !contentType.includes(
                            "application/json"
                        )
                    ) {

                        throw new Error(
                            "APIがJSONではないレスポンスを返しました。"
                        );
                    }


                    let result;

                    try {

                        result =
                            JSON.parse(text);

                    } catch (error) {

                        throw new Error(
                            "APIレスポンスのJSON解析に失敗しました。"
                        );
                    }


                    if (!result.success) {

                        throw new Error(
                            result.error?.message ||
                            "業務処理に失敗しました。"
                        );
                    }


                    return result;

                } catch (error) {

                    if (
                        error.name ===
                        "AbortError"
                    ) {

                        throw new Error(
                            "通信がタイムアウトしました。"
                        );
                    }

                    /*
                     * Failed to fetchを
                     * そのままUIへ表示しない。
                     */
                    if (
                        error instanceof TypeError &&
                        error.message ===
                            "Failed to fetch"
                    ) {

                        throw new Error(
                            "サーバーへ接続できませんでした。URL、Apache、PHP、ネットワーク状態を確認してください。"
                        );
                    }

                    throw error;

                } finally {

                    clearTimeout(timeout);

                    if (button) {

                        button.disabled =
                            false;

                        const loading =
                            button.parentElement
                                ?.querySelector(
                                    ".loading"
                                );

                        if (loading) {
                            loading.style.display =
                                "none";
                        }
                    }
                }
            }
        };

    })();

    </script>

    </body>
    </html>

    <?php
}


/* ============================================================
 * 14. 画面描画
 * ============================================================ */

function renderScreen(
    string $screen
): void {

    validateScreenParameters($screen);

    switch ($screen) {

        case 'admin':
            renderAdmin();
            break;

        case 'surveys':
            renderSurveys();
            break;

        case 'survey':
            renderSurvey();
            break;

        case 'questions':
            renderQuestions();
            break;

        case 'question-edit':
            renderQuestionEdit();
            break;

        case 'conditions':
            renderConditions();
            break;

        case 'customers':
            renderCustomers();
            break;

        case 'send':
            renderSend();
            break;

        case 'send-confirm':
            renderSendConfirm();
            break;

        case 'send-history':
            renderSendHistory();
            break;

        case 'summary':
            renderSummary();
            break;

        case 'kintone':
            renderKintone();
            break;

        case 'smtp':
            renderSmtp();
            break;

        case 'settings':
            renderSettings();
            break;

        case 'start':
            renderAnswerStart();
            break;

        case 'answer':
            renderAnswer();
            break;

        case 'confirm':
            renderAnswerConfirm();
            break;

        case 'complete':
            renderAnswerComplete();
            break;

        case 'already-answered':
            renderAlreadyAnswered();
            break;

        case 'unavailable':
            renderUnavailable();
            break;

        default:

            renderErrorPage(
                '指定された画面は存在しません。',
                404
            );

            break;
    }
}


/* ============================================================
 * 15. S01 管理者トップ
 * ============================================================ */

function renderAdmin(): void
{
    renderHeader('管理者トップ');
    ?>

    <h1>S01 管理者トップ</h1>

    <div class="grid">

        <div class="card">
            <h2>アンケート管理</h2>
            <a
                class="button"
                href="index.php?screen=surveys"
                data-screen-link
            >
                アンケート一覧
            </a>
        </div>

        <div class="card">
            <h2>顧客管理</h2>
            <a
                class="button"
                href="index.php?screen=customers"
                data-screen-link
            >
                顧客一覧
            </a>
        </div>

        <div class="card">
            <h2>送信履歴</h2>
            <a
                class="button"
                href="index.php?screen=send-history"
                data-screen-link
            >
                送信履歴
            </a>
        </div>

        <div class="card">
            <h2>集計</h2>
            <a
                class="button"
                href="index.php?screen=summary"
                data-screen-link
            >
                集計
            </a>
        </div>

        <div class="card">
            <h2>kintone</h2>
            <a
                class="button"
                href="index.php?screen=kintone"
                data-screen-link
            >
                kintone設定
            </a>
        </div>

        <div class="card">
            <h2>SMTP</h2>
            <a
                class="button"
                href="index.php?screen=smtp"
                data-screen-link
            >
                SMTP設定
            </a>
        </div>

        <div class="card">
            <h2>システム設定</h2>
            <a
                class="button"
                href="index.php?screen=settings"
                data-screen-link
            >
                システム設定
            </a>
        </div>

    </div>

    <?php
    renderFooter();
}


/* ============================================================
 * 16. S02 アンケート一覧
 * ============================================================ */

function renderSurveys(): void
{
    $surveys =
        readJsonFile(
            'surveys',
            []
        );

    renderHeader('アンケート一覧');

    ?>

    <h1>S02 アンケート一覧</h1>

    <div class="card">

        <a
            class="button"
            href="index.php?screen=survey"
            data-screen-link
        >
            新規作成
        </a>

    </div>


    <div class="card">

        <?php if ($surveys === []): ?>

            <p>
                アンケートはまだ登録されていません。
            </p>

        <?php else: ?>

            <table>

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>名称</th>
                        <th>状態</th>
                        <th>操作</th>
                    </tr>

                </thead>

                <tbody>

                <?php foreach ($surveys as $survey): ?>

                    <?php
                    if (!is_array($survey)) {
                        continue;
                    }

                    $id =
                        (string)($survey['id'] ?? '');

                    $name =
                        (string)($survey['name'] ?? '');

                    $status =
                        (string)($survey['status'] ?? 'draft');
                    ?>

                    <tr>

                        <td><?= h($id) ?></td>

                        <td><?= h($name) ?></td>

                        <td>
                            <span class="status">
                                <?= h($status) ?>
                            </span>
                        </td>

                        <td>

                            <a
                                class="button"
                                href="index.php?screen=survey&surveyId=<?= rawurlencode($id) ?>"
                                data-screen-link
                            >
                                編集
                            </a>

                            <a
                                class="button secondary"
                                href="index.php?screen=questions&surveyId=<?= rawurlencode($id) ?>"
                                data-screen-link
                            >
                                質問
                            </a>

                            <a
                                class="button secondary"
                                href="index.php?screen=summary&surveyId=<?= rawurlencode($id) ?>"
                                data-screen-link
                            >
                                集計
                            </a>

                            <a
                                class="button secondary"
                                href="index.php?screen=send&surveyId=<?= rawurlencode($id) ?>"
                                data-screen-link
                            >
                                送信
                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

    <div class="card">

        <a
            href="index.php?screen=admin"
            data-screen-link
        >
            ← 管理者トップへ
        </a>

    </div>

    <?php

    renderFooter();
}


/* ============================================================
 * 17. S03 アンケート編集
 * ============================================================ */

function renderSurvey(): void
{
    $surveyId =
        $_GET['surveyId'] ?? null;

    renderHeader('アンケート編集');

    ?>

    <h1>S03 アンケート編集</h1>

    <div class="message info">
        対象 surveyId：
        <strong><?= h($surveyId) ?></strong>
    </div>

    <div class="card">

        <form
            id="survey-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h(csrfToken()) ?>"
            >

            <input
                type="hidden"
                name="surveyId"
                value="<?= h($surveyId) ?>"
            >

            <div class="form-group">

                <label for="survey-name">
                    アンケート名称
                </label>

                <input
                    id="survey-name"
                    name="name"
                    type="text"
                    required
                >

            </div>

            <div class="form-group">

                <label for="survey-description">
                    説明
                </label>

                <textarea
                    id="survey-description"
                    name="description"
                    rows="5"
                ></textarea>

            </div>

            <div class="form-group">

                <label for="start-at">
                    開始日時
                </label>

                <input
                    id="start-at"
                    name="startAt"
                    type="datetime-local"
                >

            </div>

            <div class="form-group">

                <label for="end-at">
                    終了日時
                </label>

                <input
                    id="end-at"
                    name="endAt"
                    type="datetime-local"
                >

            </div>

            <button
                class="button"
                type="submit"
            >
                保存
            </button>

            <span class="loading">
                処理中...
            </span>

        </form>

    </div>


    <div class="card">

        <a
            class="button"
            href="index.php?screen=questions&surveyId=<?= rawurlencode((string)$surveyId) ?>"
            data-screen-link
        >
            質問管理
        </a>

        <a
            class="button"
            href="index.php?screen=conditions&surveyId=<?= rawurlencode((string)$surveyId) ?>"
            data-screen-link
        >
            条件分岐
        </a>

        <a
            class="button secondary"
            href="index.php?screen=surveys"
            data-screen-link
        >
            戻る
        </a>

    </div>


    <script>

    document
        .getElementById("survey-form")
        .addEventListener(
            "submit",
            async event => {

                event.preventDefault();

                const form =
                    event.currentTarget;

                const button =
                    form.querySelector(
                        "button[type=submit]"
                    );

                const data =
                    Object.fromEntries(
                        new FormData(form)
                    );

                try {

                    await App.post(
                        "save_survey",
                        data,
                        button
                    );

                    alert(
                        "アンケートを保存しました。"
                    );

                    /*
                     * 要件:
                     * 保存成功後もS03を維持する。
                     */
                    window.location.reload();

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );

    </script>

    <?php

    renderFooter();
}


/* ============================================================
 * 18. S04 質問管理
 * ============================================================ */

function renderQuestions(): void
{
    $surveyId =
        $_GET['surveyId'] ?? '';

    $questions =
        readJsonFile(
            'questions',
            []
        );

    renderHeader('質問管理');

    ?>

    <h1>S04 質問管理</h1>

    <div class="message info">
        対象 surveyId：
        <strong><?= h($surveyId) ?></strong>
    </div>

    <div class="card">

        <a
            class="button"
            href="index.php?screen=question-edit&surveyId=<?= rawurlencode((string)$surveyId) ?>"
            data-screen-link
        >
            質問追加
        </a>

        <a
            class="button secondary"
            href="index.php?screen=conditions&surveyId=<?= rawurlencode((string)$surveyId) ?>"
            data-screen-link
        >
            条件分岐
        </a>

    </div>

    <div class="card">

        <?php if ($questions === []): ?>

            <p>
                質問はまだ登録されていません。
            </p>

        <?php else: ?>

            <table>

                <thead>

                    <tr>
                        <th>質問ID</th>
                        <th>質問文</th>
                        <th>操作</th>
                    </tr>

                </thead>

                <tbody>

                <?php foreach ($questions as $question): ?>

                    <?php
                    if (!is_array($question)) {
                        continue;
                    }

                    $id =
                        (string)($question['id'] ?? '');

                    $text =
                        (string)($question['text'] ?? '');
                    ?>

                    <tr>

                        <td><?= h($id) ?></td>

                        <td><?= h($text) ?></td>

                        <td>

                            <a
                                class="button"
                                href="index.php?screen=question-edit&surveyId=<?= rawurlencode((string)$surveyId) ?>&questionId=<?= rawurlencode($id) ?>"
                                data-screen-link
                            >
                                編集
                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>


    <div class="card">

        <a
            href="index.php?screen=survey&surveyId=<?= rawurlencode((string)$surveyId) ?>"
            data-screen-link
        >
            ← アンケート編集へ
        </a>

    </div>

    <?php

    renderFooter();
}


/* ============================================================
 * 19. S05 質問編集
 * ============================================================ */

function renderQuestionEdit(): void
{
    $surveyId =
        $_GET['surveyId'] ?? '';

    $questionId =
        $_GET['questionId'] ?? '';

    renderHeader('質問編集');

    ?>

    <h1>S05 質問編集</h1>

    <div class="message info">

        surveyId：
        <strong><?= h($surveyId) ?></strong>

        <?php if ($questionId !== ''): ?>

            <br>

            questionId：
            <strong><?= h($questionId) ?></strong>

        <?php else: ?>

            <br>

            新規質問

        <?php endif; ?>

    </div>


    <div class="card">

        <form id="question-form">

            <input
                type="hidden"
                name="surveyId"
                value="<?= h($surveyId) ?>"
            >

            <input
                type="hidden"
                name="questionId"
                value="<?= h($questionId) ?>"
            >

            <div class="form-group">

                <label>
                    質問文
                </label>

                <textarea
                    name="text"
                    rows="5"
                    required
                ></textarea>

            </div>

            <div class="form-group">

                <label>
                    必須
                </label>

                <select name="required">

                    <option value="0">
                        任意
                    </option>

                    <option value="1">
                        必須
                    </option>

                </select>

            </div>

            <button
                class="button"
                type="submit"
            >
                保存
            </button>

            <span class="loading">
                処理中...
            </span>

        </form>

    </div>


    <div class="card">

        <a
            class="button secondary"
            href="index.php?screen=questions&surveyId=<?= rawurlencode((string)$surveyId) ?>"
            data-screen-link
        >
            キャンセル
        </a>

    </div>


    <script>

    document
        .getElementById("question-form")
        .addEventListener(
            "submit",
            async event => {

                event.preventDefault();

                const form =
                    event.currentTarget;

                const button =
                    form.querySelector(
                        "button[type=submit]"
                    );

                const data =
                    Object.fromEntries(
                        new FormData(form)
                    );

                try {

                    await App.post(
                        "save_question",
                        data,
                        button
                    );

                    /*
                     * 要件:
                     * 質問保存後はS04へ戻る。
                     */
                    const surveyId =
                        encodeURIComponent(
                            data.surveyId
                        );

                    history.pushState(
                        {},
                        "",
                        `index.php?screen=questions&surveyId=${surveyId}`
                    );

                    window.location.reload();

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );

    </script>

    <?php

    renderFooter();
}


/* ============================================================
 * 20. S06 条件分岐
 * ============================================================ */

function renderConditions(): void
{
    $surveyId =
        $_GET['surveyId'] ?? '';

    renderHeader('条件分岐設定');

    ?>

    <h1>S06 条件分岐設定</h1>

    <div class="message info">
        対象 surveyId：
        <strong><?= h($surveyId) ?></strong>
    </div>

    <div class="card">

        <p>
            条件分岐は
            questionId + choiceId → nextQuestionId
            で管理します。
        </p>

        <button
            class="button"
            type="button"
            id="save-condition"
        >
            保存
        </button>

        <span class="loading">
            処理中...
        </span>

    </div>

    <div class="card">

        <a
            href="index.php?screen=questions&surveyId=<?= rawurlencode((string)$surveyId) ?>"
            data-screen-link
        >
            ← 質問管理へ
        </a>

    </div>


    <script>

    document
        .getElementById("save-condition")
        .addEventListener(
            "click",
            async event => {

                const button =
                    event.currentTarget;

                try {

                    await App.post(
                        "save_condition",
                        {
                            surveyId:
                                <?= json_encode(
                                    $surveyId,
                                    JSON_UNESCAPED_UNICODE
                                ) ?>
                        },
                        button
                    );

                    alert(
                        "条件分岐を保存しました。"
                    );

                    /*
                     * S06を維持する。
                     */
                    window.location.reload();

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );

    </script>

    <?php

    renderFooter();
}


/* ============================================================
 * 21. S07 顧客一覧
 * ============================================================ */

function renderCustomers(): void
{
    renderHeader('顧客一覧');

    ?>

    <h1>S07 顧客一覧</h1>

    <div class="card">

        <button
            class="button"
            type="button"
            data-action="sync_customers"
        >
            kintone同期
        </button>

        <span class="loading">
            処理中...
        </span>

    </div>

    <div class="card">

        <p>
            顧客データをここに表示します。
        </p>

        <a
            class="button"
            href="index.php?screen=send"
            data-screen-link
        >
            送信対象選択
        </a>

    </div>

    <div class="card">

        <a
            href="index.php?screen=admin"
            data-screen-link
        >
            ← 管理者トップへ
        </a>

    </div>


    <script>

    document
        .querySelector(
            '[data-action="sync_customers"]'
        )
        ?.addEventListener(
            "click",
            async event => {

                const button =
                    event.currentTarget;

                try {

                    const result =
                        await App.post(
                            "sync_customers",
                            {},
                            button
                        );

                    alert(
                        result.message ||
                        "顧客同期が完了しました。"
                    );

                    window.location.reload();

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );

    </script>

    <?php

    renderFooter();
}


/* ============================================================
 * 22. S08 メール送信
 * ============================================================ */

function renderSend(): void
{
    $surveyId =
        $_GET['surveyId'] ?? '';

    renderHeader('メール送信');

    ?>

    <h1>S08 メール送信</h1>

    <div class="message info">

        対象 surveyId：
        <strong><?= h($surveyId) ?></strong>

    </div>

    <div class="card">

        <form
            method="GET"
            action="index.php"
        >

            <input
                type="hidden"
                name="screen"
                value="send-confirm"
            >

            <input
                type="hidden"
                name="surveyId"
                value="<?= h($surveyId) ?>"
            >

            <div class="form-group">

                <label>
                    件名
                </label>

                <input
                    name="subject"
                    type="text"
                    required
                >

            </div>

            <div class="form-group">

                <label>
                    本文
                </label>

                <textarea
                    name="body"
                    rows="10"
                    required
                ></textarea>

            </div>

            <button
                class="button"
                type="submit"
            >
                送信確認
            </button>

        </form>

    </div>

    <div class="card">

        <a
            href="index.php?screen=surveys"
            data-screen-link
        >
            ← アンケート一覧へ
        </a>

    </div>

    <?php

    renderFooter();
}


/* ============================================================
 * 23. S09 送信確認
 * ============================================================ */

function renderSendConfirm(): void
{
    $surveyId =
        $_GET['surveyId'] ?? '';

    $subject =
        $_GET['subject'] ?? '';

    $body =
        $_GET['body'] ?? '';

    renderHeader('送信確認');

    ?>

    <h1>S09 送信確認</h1>

    <div class="card">

        <p>
            対象アンケート：
            <?= h($surveyId) ?>
        </p>

        <p>
            件名：
            <?= h($subject) ?>
        </p>

        <p>
            本文：
        </p>

        <pre><?= h($body) ?></pre>

    </div>


    <div class="card">

        <button
            class="button success"
            id="send-button"
        >
            送信実行
        </button>

        <span class="loading">
            処理中...
        </span>

        <a
            class="button secondary"
            href="index.php?screen=send&surveyId=<?= rawurlencode((string)$surveyId) ?>"
            data-screen-link
        >
            修正
        </a>

        <a
            class="button secondary"
            href="index.php?screen=send&surveyId=<?= rawurlencode((string)$surveyId) ?>"
            data-screen-link
        >
            キャンセル
        </a>

    </div>


    <script>

    document
        .getElementById("send-button")
        .addEventListener(
            "click",
            async event => {

                const button =
                    event.currentTarget;

                try {

                    await App.post(
                        "send_mail",
                        {
                            surveyId:
                                <?= json_encode(
                                    $surveyId,
                                    JSON_UNESCAPED_UNICODE
                                ) ?>,

                            subject:
                                <?= json_encode(
                                    $subject,
                                    JSON_UNESCAPED_UNICODE
                                ) ?>,

                            body:
                                <?= json_encode(
                                    $body,
                                    JSON_UNESCAPED_UNICODE
                                ) ?>
                        },
                        button
                    );

                    /*
                     * 要件:
                     * 送信実行後はS08。
                     */
                    history.pushState(
                        {},
                        "",
                        "index.php?screen=send&surveyId=" +
                        encodeURIComponent(
                            <?= json_encode(
                                $surveyId
                            ) ?>
                        )
                    );

                    window.location.reload();

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );

    </script>

    <?php

    renderFooter();
}


/* ============================================================
 * 24. S10 送信履歴
 * ============================================================ */

function renderSendHistory(): void
{
    $history =
        readJsonFile(
            'send_history',
            []
        );

    renderHeader('送信履歴');

    ?>

    <h1>S10 送信履歴</h1>

    <div class="card">

        <?php if ($history === []): ?>

            <p>
                送信履歴はありません。
            </p>

        <?php else: ?>

            <table>

                <thead>

                    <tr>
                        <th>送信日時</th>
                        <th>surveyId</th>
                        <th>customerId</th>
                        <th>送信先</th>
                        <th>結果</th>
                    </tr>

                </thead>

                <tbody>

                <?php foreach ($history as $row): ?>

                    <?php
                    if (!is_array($row)) {
                        continue;
                    }
                    ?>

                    <tr>

                        <td>
                            <?= h(
                                $row['sentAt'] ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                $row['surveyId'] ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                $row['customerId'] ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                $row['to'] ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                $row['status'] ?? ''
                            ) ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

    <div class="card">

        <a
            href="index.php?screen=admin"
            data-screen-link
        >
            ← 管理者トップへ
        </a>

    </div>

    <?php

    renderFooter();
}


/* ============================================================
 * 25. S11 集計
 * ============================================================ */

function renderSummary(): void
{
    $surveyId =
        $_GET['surveyId'] ?? '';

    renderHeader('集計');

    ?>

    <h1>S11 集計</h1>

    <div class="message info">

        対象 surveyId：
        <strong><?= h($surveyId) ?></strong>

    </div>

    <div class="card">

        <p>
            回答数：
            <strong>0</strong>
        </p>

        <p>
            送信対象者数：
            <strong>0</strong>
        </p>

        <p>
            回答率：
            <strong>0%</strong>
        </p>

    </div>


    <div class="card">

        <button
            class="button"
            type="button"
            data-action="export_csv"
        >
            CSV出力
        </button>

        <button
            class="button secondary"
            type="button"
            data-action="export_pdf"
        >
            PDF出力
        </button>

        <p class="message warning">
            CSV/PDF実ファイル生成は現段階では未実装です。
        </p>

    </div>


    <div class="card">

        <a
            href="index.php?screen=surveys"
            data-screen-link
        >
            ← アンケート一覧へ
        </a>

    </div>

    <?php

    renderFooter();
}


/* ============================================================
 * 26. S12 kintone設定
 * ============================================================ */

function renderKintone(): void
{
    $settings =
        readJsonFile(
            'settings',
            []
        );

    $kintone =
        is_array($settings['kintone'] ?? null)
            ? $settings['kintone']
            : [];

    renderHeader('kintone設定');

    ?>

    <h1>S12 kintone設定</h1>

    <div class="card">

        <form id="kintone-form">

            <div class="form-group">

                <label>
                    subdomain
                </label>

                <input
                    name="subdomain"
                    value="<?= h(
                        $kintone['subdomain'] ?? ''
                    ) ?>"
                >

            </div>

            <div class="form-group">

                <label>
                    appId
                </label>

                <input
                    name="appId"
                    value="<?= h(
                        $kintone['appId'] ?? ''
                    ) ?>"
                >

            </div>

            <div class="form-group">

                <label>
                    loginName
                </label>

                <input
                    name="loginName"
                    value="<?= h(
                        $kintone['loginName'] ?? ''
                    ) ?>"
                >

            </div>

            <div class="form-group">

                <label>
                    password
                </label>

                <input
                    name="password"
                    type="password"
                    autocomplete="new-password"
                >

            </div>

            <div class="form-group">

                <label>
                    sslVerify
                </label>

                <select name="sslVerify">

                    <option
                        value="0"
                        <?= empty(
                            $kintone['sslVerify']
                        ) ? 'selected' : '' ?>
                    >
                        false
                    </option>

                    <option
                        value="1"
                        <?= !empty(
                            $kintone['sslVerify']
                        ) ? 'selected' : '' ?>
                    >
                        true
                    </option>

                </select>

            </div>

            <div class="form-group">

                <label>
                    proxy
                </label>

                <input
                    name="proxy"
                    placeholder="host:port"
                    value="<?= h(
                        $kintone['proxy'] ?? ''
                    ) ?>"
                >

            </div>


            <button
                class="button"
                type="submit"
            >
                保存
            </button>

            <span class="loading">
                処理中...
            </span>

        </form>

    </div>


    <div class="card">

        <button
            class="button secondary"
            id="kintone-test"
            type="button"
        >
            接続テスト
        </button>

        <button
            class="button secondary"
            id="kintone-fields"
            type="button"
        >
            項目一覧を再取得
        </button>

        <button
            class="button"
            id="kintone-sync"
            type="button"
        >
            顧客情報を同期
        </button>

        <span class="loading">
            処理中...
        </span>

    </div>


    <script>

    const kintoneForm =
        document.getElementById(
            "kintone-form"
        );


    kintoneForm.addEventListener(
        "submit",
        async event => {

            event.preventDefault();

            const button =
                kintoneForm.querySelector(
                    "button[type=submit]"
                );

            const data =
                Object.fromEntries(
                    new FormData(kintoneForm)
                );

            try {

                await App.post(
                    "save_kintone",
                    data,
                    button
                );

                alert(
                    "kintone設定を保存しました。"
                );

            } catch (error) {

                alert(
                    error.message
                );
            }
        }
    );


    document
        .getElementById("kintone-test")
        .addEventListener(
            "click",
            async event => {

                try {

                    const result =
                        await App.post(
                            "test_kintone",
                            {},
                            event.currentTarget
                        );

                    alert(
                        result.message ||
                        "kintone接続テストが完了しました。"
                    );

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );


    document
        .getElementById("kintone-fields")
        .addEventListener(
            "click",
            async event => {

                try {

                    const result =
                        await App.post(
                            "get_kintone_fields",
                            {},
                            event.currentTarget
                        );

                    alert(
                        result.message ||
                        "項目取得が完了しました。"
                    );

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );


    document
        .getElementById("kintone-sync")
        .addEventListener(
            "click",
            async event => {

                try {

                    const result =
                        await App.post(
                            "sync_customers",
                            {},
                            event.currentTarget
                        );

                    alert(
                        result.message ||
                        "顧客同期が完了しました。"
                    );

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );

    </script>

    <?php

    renderFooter();
}


/* ============================================================
 * 27. S13 SMTP設定
 * ============================================================ */

function renderSmtp(): void
{
    renderHeader('SMTP設定');

    ?>

    <h1>S13 SMTP設定</h1>

    <div class="card">

        <form id="smtp-form">

            <div class="form-group">

                <label>
                    smtpHost
                </label>

                <input name="smtpHost">

            </div>

            <div class="form-group">

                <label>
                    smtpPort
                </label>

                <input
                    name="smtpPort"
                    type="number"
                >

            </div>

            <div class="form-group">

                <label>
                    encryption
                </label>

                <select name="encryption">

                    <option value="">
                        なし
                    </option>

                    <option value="ssl">
                        SSL
                    </option>

                    <option value="tls">
                        TLS
                    </option>

                </select>

            </div>

            <div class="form-group">

                <label>
                    auth
                </label>

                <select name="auth">

                    <option value="0">
                        false
                    </option>

                    <option value="1">
                        true
                    </option>

                </select>

            </div>

            <div class="form-group">

                <label>
                    username
                </label>

                <input name="username">

            </div>

            <div class="form-group">

                <label>
                    password
                </label>

                <input
                    name="password"
                    type="password"
                >

            </div>

            <div class="form-group">

                <label>
                    fromAddress
                </label>

                <input
                    name="fromAddress"
                    type="email"
                >

            </div>

            <div class="form-group">

                <label>
                    fromName
                </label>

                <input name="fromName">

            </div>

            <div class="form-group">

                <label>
                    replyTo
                </label>

                <input
                    name="replyTo"
                    type="email"
                >

            </div>

            <button
                class="button"
                type="submit"
            >
                保存
            </button>

            <span class="loading">
                処理中...
            </span>

        </form>

    </div>


    <div class="card">

        <button
            class="button secondary"
            id="smtp-test"
            type="button"
        >
            テストメール
        </button>

        <span class="loading">
            処理中...
        </span>

    </div>


    <script>

    document
        .getElementById("smtp-form")
        .addEventListener(
            "submit",
            async event => {

                event.preventDefault();

                const form =
                    event.currentTarget;

                const button =
                    form.querySelector(
                        "button[type=submit]"
                    );

                const data =
                    Object.fromEntries(
                        new FormData(form)
                    );

                try {

                    await App.post(
                        "save_smtp",
                        data,
                        button
                    );

                    alert(
                        "SMTP設定を保存しました。"
                    );

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );


    document
        .getElementById("smtp-test")
        .addEventListener(
            "click",
            async event => {

                try {

                    const result =
                        await App.post(
                            "test_smtp",
                            {},
                            event.currentTarget
                        );

                    alert(
                        result.message ||
                        "SMTPテストが完了しました。"
                    );

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );

    </script>

    <?php

    renderFooter();
}


/* ============================================================
 * 28. S14 システム設定
 * ============================================================ */

function renderSettings(): void
{
    renderHeader('システム設定');

    ?>

    <h1>S14 システム設定</h1>

    <div class="card">

        <form id="settings-form">

            <div class="form-group">

                <label>
                    システム名
                </label>

                <input
                    name="systemName"
                    value="アンケート管理システム"
                >

            </div>

            <button
                class="button"
                type="submit"
            >
                保存
            </button>

            <span class="loading">
                処理中...
            </span>

        </form>

    </div>


    <script>

    document
        .getElementById("settings-form")
        .addEventListener(
            "submit",
            async event => {

                event.preventDefault();

                const form =
                    event.currentTarget;

                const button =
                    form.querySelector(
                        "button[type=submit]"
                    );

                const data =
                    Object.fromEntries(
                        new FormData(form)
                    );

                try {

                    await App.post(
                        "save_settings",
                        data,
                        button
                    );

                    alert(
                        "システム設定を保存しました。"
                    );

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );

    </script>

    <?php

    renderFooter();
}


/* ============================================================
 * 29. A01 回答開始
 * ============================================================ */

function renderAnswerStart(): void
{
    $surveyId =
        $_GET['surveyId'] ?? '';

    $customerId =
        $_GET['customerId'] ?? '';

    renderHeader('回答開始');

    ?>

    <h1>A01 回答開始</h1>

    <div class="card">

        <p>
            アンケート：
            <?= h($surveyId) ?>
        </p>

        <p>
            顧客：
            <?= h($customerId) ?>
        </p>

        <a
            class="button"
            href="index.php?screen=answer&surveyId=<?= rawurlencode((string)$surveyId) ?>&customerId=<?= rawurlencode((string)$customerId) ?>"
            data-screen-link
        >
            回答を開始
        </a>

    </div>

    <?php

    renderFooter();
}


/* ============================================================
 * 30. A02 回答
 * ============================================================ */

function renderAnswer(): void
{
    $surveyId =
        $_GET['surveyId'] ?? '';

    $customerId =
        $_GET['customerId'] ?? '';

    renderHeader('回答');

    ?>

    <h1>A02 回答</h1>

    <div class="card">

        <p>
            質問1
        </p>

        <textarea
            id="answer"
            rows="6"
        ></textarea>

    </div>

    <div class="card">

        <a
            class="button"
            href="index.php?screen=confirm&surveyId=<?= rawurlencode((string)$surveyId) ?>&customerId=<?= rawurlencode((string)$customerId) ?>"
            data-screen-link
        >
            確認
        </a>

    </div>

    <?php

    renderFooter();
}


/* ============================================================
 * 31. A03 回答確認
 * ============================================================ */

function renderAnswerConfirm(): void
{
    $surveyId =
        $_GET['surveyId'] ?? '';

    $customerId =
        $_GET['customerId'] ?? '';

    renderHeader('回答確認');

    ?>

    <h1>A03 回答確認</h1>

    <div class="card">

        <p>
            回答内容を確認してください。
        </p>

    </div>

    <div class="card">

        <a
            class="button secondary"
            href="index.php?screen=answer&surveyId=<?= rawurlencode((string)$surveyId) ?>&customerId=<?= rawurlencode((string)$customerId) ?>"
            data-screen-link
        >
            修正
        </a>

        <button
            class="button success"
            id="submit-answer"
        >
            送信
        </button>

        <span class="loading">
            処理中...
        </span>

    </div>


    <script>

    document
        .getElementById("submit-answer")
        .addEventListener(
            "click",
            async event => {

                try {

                    await App.post(
                        "submit_answer",
                        {
                            surveyId:
                                <?= json_encode(
                                    $surveyId
                                ) ?>,

                            customerId:
                                <?= json_encode(
                                    $customerId
                                ) ?>
                        },
                        event.currentTarget
                    );

                    const url =
                        "index.php?screen=complete" +
                        "&surveyId=" +
                        encodeURIComponent(
                            <?= json_encode(
                                $surveyId
                            ) ?>
                        ) +
                        "&customerId=" +
                        encodeURIComponent(
                            <?= json_encode(
                                $customerId
                            ) ?>
                        );

                    history.pushState(
                        {},
                        "",
                        url
                    );

                    window.location.reload();

                } catch (error) {

                    alert(
                        error.message
                    );
                }
            }
        );

    </script>

    <?php

    renderFooter();
}


/* ============================================================
 * 32. A04 回答完了
 * ============================================================ */

function renderAnswerComplete(): void
{
    renderHeader('回答完了');

    ?>

    <h1>A04 回答完了</h1>

    <div class="card">

        <p>
            回答を正常に受け付けました。
        </p>

    </div>

    <?php

    renderFooter();
}


/* ============================================================
 * 33. A05 回答済み
 * ============================================================ */

function renderAlreadyAnswered(): void
{
    renderHeader('回答済み');

    ?>

    <h1>A05 回答済み</h1>

    <div class="card">

        <p>
            このアンケートにはすでに回答済みです。
        </p>

    </div>

    <?php

    renderFooter();
}


/* ============================================================
 * 34. A06 回答不可
 * ============================================================ */

function renderUnavailable(): void
{
    renderHeader('回答不可');

    ?>

    <h1>A06 回答不可</h1>

    <div class="card">

        <p>
            現在、このアンケートには
            回答できません。
        </p>

    </div>

    <?php

    renderFooter();
}


/* ============================================================
 * 35. API
 * ============================================================ */

function handleApi(
    string $action,
    array $body
): never {

    /*
     * POST限定。
     */
    requireMethod('POST');


    /*
     * CSRF。
     */
    verifyCsrf(
        requestParam(
            'csrf_token',
            $body
        )
    );


    switch ($action) {

        /* ----------------------------------------------------
         * アンケート保存
         * ---------------------------------------------------- */

        case 'save_survey':

            $surveyId =
                requestParam(
                    'surveyId',
                    $body
                );

            $name =
                requestParam(
                    'name',
                    $body
                ) ?? '';

            $description =
                requestParam(
                    'description',
                    $body
                ) ?? '';

            if ($name === '') {

                errorResponse(
                    'VALIDATION_ERROR',
                    'アンケート名称を入力してください。',
                    422
                );
            }

            $surveys =
                readJsonFile(
                    'surveys',
                    []
                );

            if (
                $surveyId === null ||
                $surveyId === ''
            ) {
                $surveyId =
                    'survey_' .
                    bin2hex(
                        random_bytes(8)
                    );
            }

            $found = false;

            foreach (
                $surveys
                as &$survey
            ) {

                if (
                    is_array($survey) &&
                    ($survey['id'] ?? '') ===
                        $surveyId
                ) {

                    $survey['name'] =
                        $name;

                    $survey['description'] =
                        $description;

                    $found = true;

                    break;
                }
            }

            unset($survey);


            if (!$found) {

                $surveys[] = [

                    'id' =>
                        $surveyId,

                    'name' =>
                        $name,

                    'description' =>
                        $description,

                    'status' =>
                        'draft',

                    'startAt' =>
                        requestParam(
                            'startAt',
                            $body
                        ),

                    'endAt' =>
                        requestParam(
                            'endAt',
                            $body
                        ),

                    'createdAt' =>
                        date(
                            DATE_ATOM
                        ),

                    'updatedAt' =>
                        date(
                            DATE_ATOM
                        ),
                ];

            }


            writeJsonFile(
                'surveys',
                $surveys
            );


            successResponse(
                [
                    'surveyId' =>
                        $surveyId
                ],
                'アンケートを保存しました。'
            );


        /* ----------------------------------------------------
         * 質問保存
         * ---------------------------------------------------- */

        case 'save_question':

            $surveyId =
                requireId(
                    requestParam(
                        'surveyId',
                        $body
                    ),
                    'surveyId'
                );

            $questionId =
                requestParam(
                    'questionId',
                    $body
                );

            $text =
                requestParam(
                    'text',
                    $body
                ) ?? '';

            if ($text === '') {

                errorResponse(
                    'VALIDATION_ERROR',
                    '質問文を入力してください。',
                    422
                );
            }

            $questions =
                readJsonFile(
                    'questions',
                    []
                );


            if (
                $questionId === null ||
                $questionId === ''
            ) {

                $questionId =
                    'question_' .
                    bin2hex(
                        random_bytes(8)
                    );

                $questions[] = [

                    'id' =>
                        $questionId,

                    'surveyId' =>
                        $surveyId,

                    'text' =>
                        $text,

                    'required' =>
                        requestParam(
                            'required',
                            $body
                        ) === '1',

                    'createdAt' =>
                        date(
                            DATE_ATOM
                        ),

                    'updatedAt' =>
                        date(
                            DATE_ATOM
                        ),
                ];

            } else {

                $found = false;

                foreach (
                    $questions
                    as &$question
                ) {

                    if (
                        is_array($question) &&
                        ($question['id'] ?? '') ===
                            $questionId &&
                        ($question['surveyId'] ?? '') ===
                            $surveyId
                    ) {

                        $question['text'] =
                            $text;

                        $question['required'] =
                            requestParam(
                                'required',
                                $body
                            ) === '1';

                        $question['updatedAt'] =
                            date(
                                DATE_ATOM
                            );

                        $found = true;

                        break;
                    }
                }

                unset($question);


                if (!$found) {

                    errorResponse(
                        'QUESTION_NOT_FOUND',
                        '対象質問が存在しません。',
                        404
                    );
                }
            }


            writeJsonFile(
                'questions',
                $questions
            );


            successResponse(
                [
                    'questionId' =>
                        $questionId
                ],
                '質問を保存しました。'
            );


        /* ----------------------------------------------------
         * 条件分岐保存
         * ---------------------------------------------------- */

        case 'save_condition':

            successResponse(
                [],
                '条件分岐を保存しました。'
            );


        /* ----------------------------------------------------
         * 顧客同期
         * ---------------------------------------------------- */

        case 'sync_customers':

            /*
             * 現段階では業務APIの入口のみ。
             * kintone通信層実装時に実処理へ置換する。
             */

            successResponse(
                [],
                '顧客同期処理を受け付けました。'
            );


        /* ----------------------------------------------------
         * kintone設定保存
         * ---------------------------------------------------- */

        case 'save_kintone':

            $settings =
                readJsonFile(
                    'settings',
                    []
                );

            $settings['kintone'] = [

                'subdomain' =>
                    requestParam(
                        'subdomain',
                        $body
                    ) ?? '',

                'appId' =>
                    requestParam(
                        'appId',
                        $body
                    ) ?? '',

                'loginName' =>
                    requestParam(
                        'loginName',
                        $body
                    ) ?? '',

                /*
                 * 実運用では暗号化等を検討。
                 * HTMLへ返却しない。
                 */
                'password' =>
                    requestParam(
                        'password',
                        $body
                    ) ?? '',

                'sslVerify' =>
                    requestParam(
                        'sslVerify',
                        $body
                    ) === '1',

                'proxy' =>
                    requestParam(
                        'proxy',
                        $body
                    ) ?? '',
            ];

            writeJsonFile(
                'settings',
                $settings
            );

            successResponse(
                [],
                'kintone設定を保存しました。'
            );


        /* ----------------------------------------------------
         * kintone接続テスト
         * ---------------------------------------------------- */

        case 'test_kintone':

            /*
             * 接続テストは、
             * 設定保存・項目取得・顧客同期を
             * 自動実行しない。
             */

            successResponse(
                [
                    'tested' => false
                ],
                'kintone接続テスト処理の実装待ちです。'
            );


        /* ----------------------------------------------------
         * kintone項目取得
         * ---------------------------------------------------- */

        case 'get_kintone_fields':

            successResponse(
                [
                    'fields' => []
                ],
                'kintone項目取得処理の実装待ちです。'
            );


        /* ----------------------------------------------------
         * SMTP保存
         * ---------------------------------------------------- */

        case 'save_smtp':

            $settings =
                readJsonFile(
                    'settings',
                    []
                );

            $settings['smtp'] = [

                'smtpHost' =>
                    requestParam(
                        'smtpHost',
                        $body
                    ) ?? '',

                'smtpPort' =>
                    requestParam(
                        'smtpPort',
                        $body
                    ) ?? '',

                'encryption' =>
                    requestParam(
                        'encryption',
                        $body
                    ) ?? '',

                'auth' =>
                    requestParam(
                        'auth',
                        $body
                    ) === '1',

                'username' =>
                    requestParam(
                        'username',
                        $body
                    ) ?? '',

                'password' =>
                    requestParam(
                        'password',
                        $body
                    ) ?? '',

                'fromAddress' =>
                    requestParam(
                        'fromAddress',
                        $body
                    ) ?? '',

                'fromName' =>
                    requestParam(
                        'fromName',
                        $body
                    ) ?? '',

                'replyTo' =>
                    requestParam(
                        'replyTo',
                        $body
                    ) ?? '',
            ];

            writeJsonFile(
                'settings',
                $settings
            );

            successResponse(
                [],
                'SMTP設定を保存しました。'
            );


        /* ----------------------------------------------------
         * SMTPテスト
         * ---------------------------------------------------- */

        case 'test_smtp':

            successResponse(
                [
                    'tested' => false
                ],
                'SMTPテストメール処理の実装待ちです。'
            );


        /* ----------------------------------------------------
         * システム設定
         * ---------------------------------------------------- */

        case 'save_settings':

            $settings =
                readJsonFile(
                    'settings',
                    []
                );

            $settings['system'] = [

                'systemName' =>
                    requestParam(
                        'systemName',
                        $body
                    ) ?? '',
            ];

            writeJsonFile(
                'settings',
                $settings
            );

            successResponse(
                [],
                'システム設定を保存しました。'
            );


        /* ----------------------------------------------------
         * メール送信
         * ---------------------------------------------------- */

        case 'send_mail':

            $surveyId =
                requireId(
                    requestParam(
                        'surveyId',
                        $body
                    ),
                    'surveyId'
                );

            /*
             * 実SMTP通信はSMTP通信層実装時に追加。
             */
            successResponse(
                [
                    'surveyId' =>
                        $surveyId
                ],
                'メール送信処理の実装待ちです。'
            );


        /* ----------------------------------------------------
         * 回答送信
         * ---------------------------------------------------- */

        case 'submit_answer':

            $surveyId =
                requireId(
                    requestParam(
                        'surveyId',
                        $body
                    ),
                    'surveyId'
                );

            $customerId =
                requireId(
                    requestParam(
                        'customerId',
                        $body
                    ),
                    'customerId'
                );

            /*
             * 実装時にはここで必ずサーバー側再検証:
             *
             * - survey状態
             * - endAt
             * - 必須回答
             * - 条件分岐
             * - 回答済み
             * - 再回答可否
             */

            successResponse(
                [
                    'surveyId' =>
                        $surveyId,

                    'customerId' =>
                        $customerId,
                ],
                '回答を受け付けました。'
            );


        /* ----------------------------------------------------
         * CSV
         * ---------------------------------------------------- */

        case 'export_csv':

            successResponse(
                [],
                'CSV出力は未実装です。'
            );


        /* ----------------------------------------------------
         * PDF
         * ---------------------------------------------------- */

        case 'export_pdf':

            successResponse(
                [],
                'PDF出力は未実装です。'
            );


        /* ----------------------------------------------------
         * 不正action
         * ---------------------------------------------------- */

        default:

            errorResponse(
                'UNKNOWN_ACTION',
                '指定されたactionは存在しません。',
                400
            );
    }
}


/* ============================================================
 * 36. ルーティング
 * ============================================================ */

try {

    $method =
        strtoupper(
            $_SERVER['REQUEST_METHOD'] ?? 'GET'
        );


    /*
     * POST = 業務API
     */
    if ($method === 'POST') {

        $body =
            getJsonBody();

        $action =
            requestParam(
                'action',
                $body
            );

        if (
            $action === null ||
            $action === ''
        ) {

            errorResponse(
                'ACTION_REQUIRED',
                'actionが指定されていません。',
                400
            );
        }

        handleApi(
            $action,
            $body
        );
    }


    /*
     * GET = 画面表示
     */
    if ($method === 'GET') {

        /*
         * action付きGETは参照APIとして
         * 必要になった段階で明示的に追加する。
         *
         * 現時点では業務変更を禁止。
         */
        if (isset($_GET['action'])) {

            $action =
                (string)$_GET['action'];

            /*
             * GET業務変更は禁止。
             */
            $readOnlyActions = [
                'get_survey',
                'get_questions',
                'get_customers',
                'get_summary',
            ];

            if (
                !in_array(
                    $action,
                    $readOnlyActions,
                    true
                )
            ) {

                errorResponse(
                    'GET_ACTION_NOT_ALLOWED',
                    'このactionはGETでは実行できません。',
                    405
                );
            }

            /*
             * 参照API実装箇所。
             */
            switch ($action) {

                case 'get_survey':

                    $surveyId =
                        requireId(
                            requestParam(
                                'surveyId'
                            ),
                            'surveyId'
                        );

                    $surveys =
                        readJsonFile(
                            'surveys',
                            []
                        );

                    foreach (
                        $surveys as $survey
                    ) {

                        if (
                            is_array($survey) &&
                            ($survey['id'] ?? '') ===
                                $surveyId
                        ) {

                            successResponse(
                                $survey
                            );
                        }
                    }

                    errorResponse(
                        'SURVEY_NOT_FOUND',
                        'アンケートが存在しません。',
                        404
                    );

                case 'get_questions':

                    $surveyId =
                        requireId(
                            requestParam(
                                'surveyId'
                            ),
                            'surveyId'
                        );

                    $questions =
                        readJsonFile(
                            'questions',
                            []
                        );

                    $result = [];

                    foreach (
                        $questions as $question
                    ) {

                        if (
                            is_array($question) &&
                            ($question['surveyId'] ?? '') ===
                                $surveyId
                        ) {

                            $result[] =
                                $question;
                        }
                    }

                    successResponse(
                        $result
                    );

                case 'get_customers':

                    successResponse(
                        readJsonFile(
                            'customers',
                            []
                        )
                    );

                case 'get_summary':

                    $surveyId =
                        requireId(
                            requestParam(
                                'surveyId'
                            ),
                            'surveyId'
                        );

                    successResponse(
                        [
                            'surveyId' =>
                                $surveyId,

                            'responseCount' =>
                                0,

                            'targetCount' =>
                                0,

                            'responseRate' =>
                                0,
                        ]
                    );
            }
        }


        /*
         * screen未指定時:
         *
         * 管理者トップへ。
         */
        $screen =
            isset($_GET['screen'])
                ? (string)$_GET['screen']
                : 'admin';


        if (!isset(SCREENS[$screen])) {

            renderErrorPage(
                '指定されたscreenは存在しません。',
                404
            );

            exit;
        }


        renderScreen(
            $screen
        );

        exit;
    }


    /*
     * その他HTTPメソッド
     */
    errorResponse(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405
    );


} catch (Throwable $e) {

    error_log(
        sprintf(
            '[ApplicationError] %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        )
    );

    /*
     * レスポンスがまだ開始されていなければ
     * JSONエラーを返す。
     */
    if (!headers_sent()) {

        errorResponse(
            'INTERNAL_ERROR',
            'サーバー内部でエラーが発生しました。',
            500
        );
    }

    /*
     * すでにHTML出力済みの場合。
     */
    http_response_code(500);

    echo
        '<p>サーバー内部でエラーが発生しました。</p>';
}
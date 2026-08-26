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
 * URLのpathnameには業務上の意味を持たせない。
 * 画面・業務状態はquery stringおよびPOST actionで扱う。
 */

/* =========================================================
 * 基本設定
 * ========================================================= */

const APP_TIMEZONE = 'Asia/Tokyo';

date_default_timezone_set(APP_TIMEZONE);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* =========================================================
 * 共通レスポンス
 * ========================================================= */

/**
 * API成功レスポンス
 *
 * {
 *   "success": true,
 *   "data": {},
 *   "message": ""
 * }
 */
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

/**
 * API失敗レスポンス
 *
 * {
 *   "success": false,
 *   "error": {
 *     "code": "...",
 *     "message": "..."
 *   }
 * }
 */
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
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * HTMLエスケープ
 */
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

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

/*
 * GET:
 *   画面表示・参照のみ
 *
 * POST:
 *   保存・削除・状態変更・送信・同期等
 */
if (!in_array($method, ['GET', 'POST'], true)) {
    errorResponse(
        'METHOD_NOT_ALLOWED',
        '許可されていないHTTPメソッドです。',
        405
    );
}

/* =========================================================
 * CSRF
 * ========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

if (
    !isset($_SESSION['csrf_token'])
    || !is_string($_SESSION['csrf_token'])
    || $_SESSION['csrf_token'] === ''
) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/**
 * POSTの場合だけCSRFを検証する。
 *
 * JSON POSTにも対応できるよう、
 * Header / POST / JSON bodyの順に取得する。
 */
function getRequestCsrfToken(): string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (is_string($headerToken) && $headerToken !== '') {
        return $headerToken;
    }

    $postToken = $_POST['csrf_token'] ?? '';

    if (is_string($postToken) && $postToken !== '') {
        return $postToken;
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
 * リクエスト取得
 * ========================================================= */

function getAction(): string
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if (
            (!is_string($action) || $action === '')
            && str_contains(
                strtolower($_SERVER['CONTENT_TYPE'] ?? ''),
                'application/json'
            )
        ) {
            $raw = file_get_contents('php://input');

            if ($raw === false || trim($raw) === '') {
                return '';
            }

            $json = json_decode($raw, true);

            if (is_array($json) && isset($json['action'])) {
                $action = $json['action'];
            }
        }
    } else {
        $action = $_GET['action'] ?? '';
    }

    return is_string($action) ? trim($action) : '';
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
 * 最低限の疎通確認
 * ========================================================= */

if ($action === 'health') {
    successResponse(
        [
            'status' => 'ok',
            'php_version' => PHP_VERSION,
            'time' => date(DATE_ATOM),
        ],
        '通信成功'
    );
}

if ($action === 'csrf') {
    successResponse(
        [
            'csrfToken' => $csrfToken,
        ]
    );
}

/* =========================================================
 * ここから業務処理
 * =========================================================
 *
 * 現段階では、まず
 *
 * 1. PHPがFatal Errorにならない
 * 2. GET / POSTを正しく分離する
 * 3. actionを検証する
 * 4. 共通JSONレスポンスを返す
 * 5. CSRFを検証する
 *
 * という単一入口の基礎を確立する。
 *
 * 以降の業務処理は、この入口から呼び出す。
 */

/* GETのデフォルト画面 */
if ($method === 'GET' && $action === '') {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>
<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
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
    max-width: 960px;
    margin: 0 auto;
    padding: 24px;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
}

button {
    appearance: none;
    border: 0;
    border-radius: 8px;
    padding: 10px 16px;
    background: #1769aa;
    color: #fff;
    cursor: pointer;
}

button:disabled {
    opacity: .6;
    cursor: wait;
}

#result {
    margin-top: 16px;
    padding: 12px;
    border-radius: 8px;
    background: #f0f2f5;
    white-space: pre-wrap;
}

.loading {
    display: none;
    margin-left: 8px;
}

.loading.active {
    display: inline-block;
}
</style>
</head>
<body>

<main>
    <div class="card">
        <h1>アンケート管理システム</h1>

        <p>
            単一入口 index.php の疎通確認
        </p>

        <button
            type="button"
            id="healthButton"
        >
            通信テスト
        </button>

        <span
            id="loading"
            class="loading"
            aria-live="polite"
        >
            通信中…
        </span>

        <div id="result"></div>
    </div>
</main>

<script>
(() => {
    'use strict';

    const button = document.getElementById('healthButton');
    const loading = document.getElementById('loading');
    const result = document.getElementById('result');

    let processing = false;

    function setProcessing(value) {
        processing = value;
        button.disabled = value;
        loading.classList.toggle('active', value);
    }

    async function requestHealth() {
        if (processing) {
            return;
        }

        setProcessing(true);
        result.textContent = '';

        try {
            /*
             * pathnameには業務上の意味を持たせない。
             * 現在ページ自身を基準にquery stringでactionを指定する。
             */
            const url = new URL(window.location.href);

            url.search = '';
            url.searchParams.set('action', 'health');

            const response = await fetch(url.toString(), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            const text = await response.text();

            let data;

            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'サーバーからJSONではない応答が返されました。'
                    + '\nHTTP ' + response.status
                    + '\n'
                    + text.slice(0, 500)
                );
            }

            if (!response.ok || data.success !== true) {
                const message =
                    data?.error?.message
                    || '通信に失敗しました。';

                throw new Error(message);
            }

            result.textContent =
                data.message
                + '\n'
                + JSON.stringify(data.data, null, 2);

        } catch (error) {
            result.textContent =
                '通信失敗\n'
                + (
                    error instanceof Error
                        ? error.message
                        : String(error)
                );
        } finally {
            setProcessing(false);
        }
    }

    button.addEventListener('click', requestHealth);
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
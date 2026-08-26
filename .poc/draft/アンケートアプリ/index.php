<?php
declare(strict_types=1);

/**
 * ============================================================
 * sample-app
 * 単一ファイル・基本構造
 * ============================================================
 *
 * 方針
 *
 * 1. index.php だけで動作
 * 2. fetch() 不使用
 * 3. API 不使用
 * 4. JavaScript の apiCall() 不使用
 * 5. HTML form POST でサーバー処理を実行
 * 6. PHP が直接ファイルを読み書きする
 * 7. 保存処理は PHP 側で実施
 *
 * ============================================================
 */


/* ============================================================
 * 基本設定
 * ============================================================
 */

date_default_timezone_set('Asia/Tokyo');

$appRoot = __DIR__;

/*
 * sample-app/data/
 *   └─ sample.txt
 *
 * 実際の業務データは、将来的にここを
 * JSON保存領域として利用する。
 */
$dataDir = $appRoot . DIRECTORY_SEPARATOR . 'data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

$dataFile = $dataDir . DIRECTORY_SEPARATOR . 'sample.txt';


/* ============================================================
 * 共通関数
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


/**
 * 現在ページへ戻る
 */
function redirectToSelf(): never
{
    $url = $_SERVER['REQUEST_URI'] ?? '/';

    header('Location: ' . $url, true, 303);
    exit;
}


/**
 * POST値を取得
 */
function postString(string $key, string $default = ''): string
{
    if (!isset($_POST[$key])) {
        return $default;
    }

    if (!is_string($_POST[$key])) {
        return $default;
    }

    return $_POST[$key];
}


/**
 * 成功メッセージをセッションではなく
 * GETパラメータで渡す簡易方式。
 */
function redirectWithMessage(string $message): never
{
    $base = strtok(
        $_SERVER['REQUEST_URI'] ?? '/',
        '?'
    );

    $url =
        $base .
        '?message=' .
        rawurlencode($message);

    header('Location: ' . $url, true, 303);

    exit;
}


/* ============================================================
 * POST処理
 * ============================================================
 *
 * ここが今回の重要部分。
 *
 * fetch()
 * API
 * XMLHttpRequest
 *
 * は一切使用しない。
 *
 * ブラウザのHTMLフォームから
 *
 * POST /sample-app/index.php
 *
 * が来たら、このPHPが直接処理する。
 * ============================================================
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = postString('action');


    /* --------------------------------------------------------
     * save_file
     * --------------------------------------------------------
     */

    if ($action === 'save_file') {

        $content = postString('content');

        /*
         * このサンプルでは保存先を固定する。
         *
         * ユーザーから任意のパスを受け取って
         * file_put_contents() する構造にはしない。
         *
         * これが重要。
         */
        $targetFile = $dataFile;


        /*
         * 排他的ロック付きで保存
         */
        $result = file_put_contents(
            $targetFile,
            $content,
            LOCK_EX
        );


        if ($result === false) {

            http_response_code(500);

            $errorMessage =
                'ファイルの保存に失敗しました。';

        } else {

            redirectWithMessage(
                '保存しました。'
            );
        }
    }


    /* --------------------------------------------------------
     * clear_file
     * --------------------------------------------------------
     */

    if ($action === 'clear_file') {

        $result = file_put_contents(
            $dataFile,
            '',
            LOCK_EX
        );


        if ($result === false) {

            http_response_code(500);

            $errorMessage =
                'ファイルのクリアに失敗しました。';

        } else {

            redirectWithMessage(
                'クリアしました。'
            );
        }
    }


    /* --------------------------------------------------------
     * 未知のaction
     * --------------------------------------------------------
     */

    if (
        $action !== 'save_file' &&
        $action !== 'clear_file'
    ) {

        http_response_code(400);

        $errorMessage =
            '不正な操作です。';
    }
}


/* ============================================================
 * GET処理
 * ============================================================
 *
 * GETではファイルを読むだけ。
 *
 * GETによる保存・変更はしない。
 * ============================================================
 */

$content = '';

if (is_file($dataFile)) {

    $loaded = file_get_contents($dataFile);

    if ($loaded !== false) {
        $content = $loaded;
    }
}


/* ============================================================
 * メッセージ
 * ============================================================
 */

$message = '';

if (isset($_GET['message'])) {

    $message = (string)$_GET['message'];
}


/* ============================================================
 * HTML
 * ============================================================
 */

?>
<!DOCTYPE html>
<html lang="ja">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Sample App</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 30px;
    background: #f5f5f5;
    color: #222;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

.container {
    max-width: 1000px;
    margin: 0 auto;
}

h1 {
    margin-top: 0;
}

.card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

textarea {
    width: 100%;
    min-height: 400px;
    padding: 12px;
    font-family: monospace;
    font-size: 14px;
    line-height: 1.5;
    border: 1px solid #bbb;
    border-radius: 5px;
    resize: vertical;
}

button {
    padding: 10px 18px;
    border: 0;
    border-radius: 5px;
    background: #2563eb;
    color: white;
    cursor: pointer;
    font-size: 14px;
}

button:hover {
    background: #1d4ed8;
}

button.danger {
    background: #dc2626;
}

button.danger:hover {
    background: #b91c1c;
}

.actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

.message {
    padding: 12px;
    background: #dcfce7;
    border: 1px solid #86efac;
    color: #166534;
    border-radius: 5px;
    margin-bottom: 20px;
}

.error {
    padding: 12px;
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
    border-radius: 5px;
    margin-bottom: 20px;
}

.info {
    font-size: 13px;
    color: #666;
}

.path {
    padding: 8px 10px;
    background: #f3f4f6;
    border-radius: 4px;
    font-family: monospace;
    word-break: break-all;
}

</style>

</head>


<body>


<div class="container">


<h1>
    Sample App
</h1>


<?php if ($message !== ''): ?>

<div class="message">
    <?= h($message) ?>
</div>

<?php endif; ?>


<?php if (isset($errorMessage)): ?>

<div class="error">
    <?= h($errorMessage) ?>
</div>

<?php endif; ?>


<div class="card">

<h2>
    基本動作確認
</h2>

<p class="info">
    この画面では JavaScript fetch / API は使用していません。
</p>

<p>
    保存先：
</p>

<div class="path">
    <?= h($dataFile) ?>
</div>

</div>


<div class="card">

<h2>
    ファイル編集
</h2>


<!--
    ========================================================
    保存
    ========================================================

    fetch() ではない。

    ブラウザが通常のHTTP POSTを発行する。

    POST
      ↓
    index.php
      ↓
    PHP
      ↓
    file_put_contents()
      ↓
    ファイル更新
    ========================================================
-->

<form
    method="post"
    action=""
>

<input
    type="hidden"
    name="action"
    value="save_file"
>


<textarea
    name="content"
    id="editor-content"
><?= h($content) ?></textarea>


<div class="actions">

<button
    type="submit"
>
    保存
</button>

</div>

</form>

</div>


<div class="card">

<h2>
    ファイル操作
</h2>


<form
    method="post"
    action=""
>

<input
    type="hidden"
    name="action"
    value="clear_file"
>


<div class="actions">

<button
    type="submit"
    class="danger"
>
    ファイルをクリア
</button>

</div>

</form>

</div>


<div class="card">

<h2>
    現在の構造
</h2>

<pre>
ブラウザ
   │
   │ HTTP GET
   ▼
index.php
   │
   │ file_get_contents()
   ▼
data/sample.txt


ブラウザ
   │
   │ HTTP POST
   │ action=save_file
   ▼
index.php
   │
   │ file_put_contents()
   ▼
data/sample.txt
</pre>

</div>


</div>


</body>

</html>
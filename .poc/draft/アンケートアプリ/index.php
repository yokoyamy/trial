<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| アンケート管理システム
| 基本構造確認用・単一ファイル版
|--------------------------------------------------------------------------
|
| このファイルだけで動作する。
|
| GET
|   → 画面表示
|
| POST
|   → save_file
|   → サーバー上のファイルを直接保存
|
| fetch / apiCall / JSON API は使用しない。
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| 基本設定
|--------------------------------------------------------------------------
*/

const APP_TITLE = 'アンケート管理システム 基本構造';


/*
|--------------------------------------------------------------------------
| 保存基準ディレクトリ
|--------------------------------------------------------------------------
|
| この index.php が置かれている場所を基準とする。
|
| 例：
|
| /gojacic/.poc/draft/アンケートアプリ/index.php
|
| の場合、
|
| /gojacic/.poc/draft/アンケートアプリ/
|
| が基準になる。
|
*/

$baseDir = __DIR__;


/*
|--------------------------------------------------------------------------
| 共通関数
|--------------------------------------------------------------------------
*/


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


/**
 * 現在URL
 */
function currentUrl(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    /*
     * POST後のLocation用。
     * 攻撃用のCRLFを除去。
     */
    return str_replace(
        ["\r", "\n"],
        '',
        $uri
    );
}


/**
 * 保存対象パスを検証・正規化する
 *
 * 例：
 *
 *   demo
 *   projects/demo
 *
 * は許可。
 *
 *   ../demo
 *   ./demo
 *   ../../etc
 *
 * は拒否。
 */
function normalizeRelativePath(string $path): ?string
{
    /*
     * Windows / Linux の区別をなくす
     */
    $path = str_replace('\\', '/', $path);

    /*
     * 前後空白を除去
     */
    $path = trim($path);

    /*
     * 先頭・末尾の / を除去
     */
    $path = trim($path, '/');

    if ($path === '') {
        return null;
    }

    /*
     * URLエンコードされた .. などを考慮
     */
    $decoded = rawurldecode($path);

    if ($decoded !== $path) {
        $path = str_replace('\\', '/', $decoded);
        $path = trim($path, '/');
    }

    /*
     * パーツごとに検証
     */
    $parts = explode('/', $path);

    foreach ($parts as $part) {

        if ($part === '') {
            return null;
        }

        if ($part === '.') {
            return null;
        }

        if ($part === '..') {
            return null;
        }

        /*
         * Windowsのドライブ指定禁止
         */
        if (preg_match('/^[A-Za-z]:$/', $part)) {
            return null;
        }
    }

    return implode('/', $parts);
}


/**
 * 実際の保存先ディレクトリを取得
 */
function buildTargetDirectory(
    string $baseDir,
    string $relativePath
): string {

    $parts = explode('/', $relativePath);

    $target = $baseDir;

    foreach ($parts as $part) {

        $target .= DIRECTORY_SEPARATOR . $part;
    }

    return $target;
}


/**
 * index.phpを保存する
 */
function saveFile(
    string $baseDir,
    string $historyBaseDir,
    string $path,
    string $content
): array {

    /*
     * パス検証
     */
    $normalizedPath =
        normalizeRelativePath($path);

    if ($normalizedPath === null) {

        return [
            'success' => false,
            'error' => '保存先パスが不正です。'
        ];
    }


    /*
     * 保存先
     */
    $targetDirectory =
        buildTargetDirectory(
            $baseDir,
            $normalizedPath
        );


    /*
     * ディレクトリ作成
     */
    if (!is_dir($targetDirectory)) {

        if (!mkdir(
            $targetDirectory,
            0777,
            true
        )) {

            return [
                'success' => false,
                'error' =>
                    '保存先ディレクトリを作成できませんでした。'
            ];
        }
    }


    /*
     * 保存対象ファイル
     */
    $filePath =
        $targetDirectory .
        DIRECTORY_SEPARATOR .
        'index.php';


    /*
     * ファイル保存
     *
     * LOCK_EX：
     * 同時書き込みによる破損を防ぐ。
     */
    $written =
        file_put_contents(
            $filePath,
            $content,
            LOCK_EX
        );


    if ($written === false) {

        return [
            'success' => false,
            'error' =>
                'index.phpの書き込みに失敗しました。'
        ];
    }


    /*
     * 履歴保存
     *
     * 基準：
     *
     * .history/
     *     アプリ名/
     *         YYYYmmddHHMMSS_draft.txt
     */
    $appName =
        basename($normalizedPath);


    $historyDirectory =
        $historyBaseDir .
        DIRECTORY_SEPARATOR .
        $appName;


    if (!is_dir($historyDirectory)) {

        if (!mkdir(
            $historyDirectory,
            0777,
            true
        )) {

            /*
             * 本体保存は成功しているので
             * 全体失敗にはしない。
             */
            return [
                'success' => true,
                'warning' =>
                    'ファイルは保存されましたが、履歴ディレクトリを作成できませんでした。'
            ];
        }
    }


    /*
     * 同一秒の履歴衝突を避ける
     */
    $timestamp =
        date('YmdHis') .
        '_' .
        substr(
            bin2hex(random_bytes(4)),
            0,
            8
        );


    $historyFile =
        $historyDirectory .
        DIRECTORY_SEPARATOR .
        $timestamp .
        '_draft.txt';


    $historyWritten =
        file_put_contents(
            $historyFile,
            $content,
            LOCK_EX
        );


    if ($historyWritten === false) {

        return [
            'success' => true,
            'warning' =>
                'ファイル本体は保存されましたが、履歴保存に失敗しました。'
        ];
    }


    return [
        'success' => true,
        'path' => $normalizedPath,
        'file' => $filePath
    ];
}


/*
|--------------------------------------------------------------------------
| POST処理
|--------------------------------------------------------------------------
|
| ブラウザから通常のHTMLフォームPOSTを受ける。
|
*/

$message = '';
$error = '';

$postedPath = '';
$postedContent = '';


if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
) {

    /*
     * action
     */
    $action =
        isset($_POST['action'])
            ? (string)$_POST['action']
            : '';


    /*
     * save_file
     */
    if ($action === 'save_file') {

        $postedPath =
            isset($_POST['path'])
                ? (string)$_POST['path']
                : '';

        $postedContent =
            isset($_POST['content'])
                ? (string)$_POST['content']
                : '';


        /*
         * 履歴基準ディレクトリ
         */
        $historyBaseDir =
            $baseDir .
            DIRECTORY_SEPARATOR .
            '.history';


        /*
         * 保存
         */
        try {

            $result =
                saveFile(
                    $baseDir,
                    $historyBaseDir,
                    $postedPath,
                    $postedContent
                );

        } catch (Throwable $e) {

            /*
             * 本番では詳細を画面へ出さない。
             * Apache/PHPログには記録する。
             */
            error_log(
                'save_file error: ' .
                $e->getMessage()
            );

            $result = [
                'success' => false,
                'error' =>
                    'サーバー内部で保存処理に失敗しました。'
            ];
        }


        /*
         * 成功
         */
        if ($result['success'] === true) {

            /*
             * PRG
             *
             * POST後にリロードしても
             * 二重POSTされない。
             */
            header(
                'Location: ' .
                currentUrl()
            );

            exit;
        }


        /*
         * 失敗
         */
        $error =
            (string)(
                $result['error']
                ?? '保存に失敗しました。'
            );
    }


    /*
     * 未知のaction
     */
    elseif ($action !== '') {

        $error =
            '不明な操作です。';
    }
}


/*
|--------------------------------------------------------------------------
| GET表示用の初期値
|--------------------------------------------------------------------------
*/

if ($postedPath === '') {
    $postedPath = 'sample-app';
}

?>
<!DOCTYPE html>
<html lang="ja">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title><?= h(APP_TITLE) ?></title>

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

    background: #f4f6f8;
    color: #222;
}

header {
    background: #17202a;
    color: #fff;
    padding: 18px 24px;
}

header h1 {
    margin: 0;
    font-size: 20px;
}

main {
    max-width: 1100px;
    margin: 30px auto;
    padding: 0 20px;
}

.panel {
    background: #fff;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow:
        0 2px 8px rgba(0,0,0,.08);
}

h2 {
    margin-top: 0;
    font-size: 18px;
}

label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
}

input,
textarea {
    width: 100%;
    border: 1px solid #c8ced4;
    border-radius: 5px;
    padding: 10px;
    font-size: 14px;
}

textarea {
    min-height: 420px;
    font-family:
        Consolas,
        "Courier New",
        monospace;

    resize: vertical;
}

.field {
    margin-bottom: 20px;
}

button {
    border: 0;
    border-radius: 5px;
    padding: 11px 20px;
    background: #1976d2;
    color: #fff;
    font-size: 14px;
    cursor: pointer;
}

button:hover {
    background: #125da7;
}

.success {
    background: #e8f5e9;
    border: 1px solid #81c784;
    color: #256029;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.error {
    background: #ffebee;
    border: 1px solid #e57373;
    color: #a51c30;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.info {
    background: #eef4ff;
    border: 1px solid #b6c9ee;
    padding: 14px;
    border-radius: 5px;
    line-height: 1.7;
}

code {
    background: #f0f0f0;
    padding: 2px 5px;
    border-radius: 3px;
}

@media (max-width: 600px) {

    main {
        margin: 15px auto;
        padding: 0 10px;
    }

    .panel {
        padding: 16px;
    }

    textarea {
        min-height: 300px;
    }
}

</style>

</head>

<body>

<header>

    <h1>
        <?= h(APP_TITLE) ?>
    </h1>

</header>


<main>


<?php if ($error !== ''): ?>

    <div class="error">
        <?= h($error) ?>
    </div>

<?php endif; ?>


<div class="panel">

    <h2>
        ファイル保存テスト
    </h2>

    <div class="info">

        この画面は
        <strong>index.php 1ファイル</strong>
        だけで動作します。

        <br>

        保存処理は
        JavaScriptの
        <code>fetch()</code>
        を使用せず、

        <br>

        HTMLフォームの
        <code>POST</code>
        → PHP
        → <code>file_put_contents()</code>

        で実行します。

    </div>

</div>


<div class="panel">

<form
    method="post"
    action=""
>

    <input
        type="hidden"
        name="action"
        value="save_file"
    >


    <div class="field">

        <label for="path">
            保存先ディレクトリ
        </label>

        <input
            id="path"
            name="path"
            type="text"
            value="<?= h($postedPath) ?>"
            placeholder="例: sample-app"
            required
        >

        <small>
            このindex.phpからの相対パスです。
        </small>

    </div>


    <div class="field">

        <label for="editor-content">
            保存内容
        </label>

        <textarea
            id="editor-content"
            name="content"
            spellcheck="false"
        ><?= h($postedContent) ?></textarea>

    </div>


    <button type="submit">
        保存
    </button>

</form>

</div>


<div class="panel">

    <h2>
        処理経路
    </h2>

    <div class="info">

        ブラウザ

        →
        Apache

        →
        index.php

        →
        saveFile()

        →
        file_put_contents()

        <br><br>

        JavaScript
        →
        <strong>fetchなし</strong>

        <br>

        API
        →
        <strong>なし</strong>

        <br>

        データベース
        →
        <strong>なし</strong>

    </div>

</div>


</main>

</body>

</html>
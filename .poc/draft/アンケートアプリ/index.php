<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PHPファイル簡易エディタ
|--------------------------------------------------------------------------
|
| 目的：
|
|   1. サーバー上の index.php を読む
|   2. ブラウザに内容を表示する
|   3. 編集する
|   4. 通常のPOSTで保存する
|
| 通信：
|
|   fetch()       使用しない
|   apiCall()     使用しない
|   JSON API      使用しない
|
| 処理：
|
|   Browser
|      ↓
|   POST
|      ↓
|   Apache
|      ↓
|   index.php
|      ↓
|   PHP
|      ↓
|   file_get_contents()
|   file_put_contents()
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| 基準ディレクトリ
|--------------------------------------------------------------------------
|
| この index.php が存在するディレクトリ。
|
*/

$baseDir = __DIR__;


/*
|--------------------------------------------------------------------------
| 履歴ディレクトリ
|--------------------------------------------------------------------------
*/

$historyBaseDir =
    $baseDir .
    DIRECTORY_SEPARATOR .
    '.history';


/*
|--------------------------------------------------------------------------
| 共通関数
|--------------------------------------------------------------------------
*/


function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| 相対パスを正規化
|--------------------------------------------------------------------------
|
| 例：
|
|   sample-app
|   projects/test
|
| OK
|
|   ../xxx
|   ../../xxx
|   ./xxx
|
| NG
|
|--------------------------------------------------------------------------
*/

function normalizeRelativePath(
    string $path
): ?string {

    $path =
        str_replace(
            '\\',
            '/',
            trim($path)
        );

    $path =
        trim(
            $path,
            '/'
        );

    if ($path === '') {
        return null;
    }

    /*
     * URLエンコードされた
     * パストラバーサルも考慮
     */
    $decoded =
        rawurldecode($path);

    if ($decoded !== $path) {

        $path =
            str_replace(
                '\\',
                '/',
                $decoded
            );

        $path =
            trim(
                $path,
                '/'
            );
    }


    $parts =
        explode(
            '/',
            $path
        );


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
         * Windowsドライブ
         *
         * C:
         * D:
         *
         * 等を拒否
         */
        if (
            preg_match(
                '/^[A-Za-z]:$/',
                $part
            )
        ) {
            return null;
        }
    }


    return implode(
        '/',
        $parts
    );
}


/*
|--------------------------------------------------------------------------
| 実際のディレクトリを作る
|--------------------------------------------------------------------------
*/

function buildTargetDirectory(
    string $baseDir,
    string $relativePath
): string {

    $parts =
        explode(
            '/',
            $relativePath
        );


    $target =
        $baseDir;


    foreach ($parts as $part) {

        $target .=
            DIRECTORY_SEPARATOR .
            $part;
    }


    return $target;
}


/*
|--------------------------------------------------------------------------
| 対象ファイル
|--------------------------------------------------------------------------
*/

function getTargetFile(
    string $baseDir,
    string $relativePath
): string {

    return
        buildTargetDirectory(
            $baseDir,
            $relativePath
        )
        .
        DIRECTORY_SEPARATOR .
        'index.php';
}


/*
|--------------------------------------------------------------------------
| ファイル読み込み
|--------------------------------------------------------------------------
*/

function loadFile(
    string $baseDir,
    string $relativePath
): array {

    $normalized =
        normalizeRelativePath(
            $relativePath
        );


    if ($normalized === null) {

        return [
            'success' => false,
            'error' => '不正なパスです。'
        ];
    }


    $filePath =
        getTargetFile(
            $baseDir,
            $normalized
        );


    if (!is_file($filePath)) {

        return [
            'success' => false,
            'error' =>
                '指定されたindex.phpが存在しません。',
            'path' =>
                $normalized,
            'file' =>
                $filePath
        ];
    }


    $content =
        file_get_contents(
            $filePath
        );


    if ($content === false) {

        return [
            'success' => false,
            'error' =>
                'ファイルを読み込めませんでした。'
        ];
    }


    return [
        'success' => true,
        'path' =>
            $normalized,
        'file' =>
            $filePath,
        'content' =>
            $content
    ];
}


/*
|--------------------------------------------------------------------------
| ファイル保存
|--------------------------------------------------------------------------
*/

function saveFile(
    string $baseDir,
    string $historyBaseDir,
    string $relativePath,
    string $content
): array {

    $normalized =
        normalizeRelativePath(
            $relativePath
        );


    if ($normalized === null) {

        return [
            'success' => false,
            'error' =>
                '保存先パスが不正です。'
        ];
    }


    $targetDirectory =
        buildTargetDirectory(
            $baseDir,
            $normalized
        );


    /*
     * ディレクトリがなければ作成
     */
    if (!is_dir($targetDirectory)) {

        if (
            !mkdir(
                $targetDirectory,
                0777,
                true
            )
        ) {

            return [
                'success' => false,
                'error' =>
                    '保存先ディレクトリを作成できませんでした。'
            ];
        }
    }


    $filePath =
        getTargetFile(
            $baseDir,
            $normalized
        );


    /*
     * 本体保存
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
                'index.phpを書き込めませんでした。'
        ];
    }


    /*
     * 履歴
     */
    $appName =
        basename(
            $normalized
        );


    $historyDirectory =
        $historyBaseDir .
        DIRECTORY_SEPARATOR .
        $appName;


    if (!is_dir($historyDirectory)) {

        if (
            !mkdir(
                $historyDirectory,
                0777,
                true
            )
        ) {

            /*
             * 本体保存は成功。
             */
            return [
                'success' => true,
                'warning' =>
                    '本体は保存されましたが履歴ディレクトリを作成できませんでした。',
                'path' =>
                    $normalized
            ];
        }
    }


    $timestamp =
        date('YmdHis') .
        '_' .
        bin2hex(
            random_bytes(4)
        );


    $historyFile =
        $historyDirectory .
        DIRECTORY_SEPARATOR .
        $timestamp .
        '_draft.txt';


    file_put_contents(
        $historyFile,
        $content,
        LOCK_EX
    );


    return [
        'success' => true,
        'path' =>
            $normalized,
        'file' =>
            $filePath
    ];
}


/*
|--------------------------------------------------------------------------
| POST処理
|--------------------------------------------------------------------------
*/

$message = '';
$error = '';

$currentPath = '';
$currentContent = '';

$loadedPath = '';


if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    === 'POST'
) {

    $action =
        isset($_POST['action'])
            ? (string)$_POST['action']
            : '';


    /*
    |--------------------------------------------------------------------------
    | ファイル読み込み
    |--------------------------------------------------------------------------
    */

    if ($action === 'load_file') {

        $path =
            isset($_POST['path'])
                ? (string)$_POST['path']
                : '';


        try {

            $result =
                loadFile(
                    $baseDir,
                    $path
                );

        } catch (Throwable $e) {

            error_log(
                'load_file error: ' .
                $e->getMessage()
            );

            $result = [
                'success' => false,
                'error' =>
                    'ファイル読み込み中にサーバーエラーが発生しました。'
            ];
        }


        if ($result['success'] === true) {

            $currentPath =
                (string)$result['path'];

            $currentContent =
                (string)$result['content'];

            $loadedPath =
                $currentPath;

            $message =
                'ファイルを読み込みました。';

        } else {

            $error =
                (string)(
                    $result['error']
                    ?? 'ファイルを読み込めませんでした。'
                );

            $currentPath =
                $path;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ファイル保存
    |--------------------------------------------------------------------------
    */

    elseif ($action === 'save_file') {

        $path =
            isset($_POST['path'])
                ? (string)$_POST['path']
                : '';


        $content =
            isset($_POST['content'])
                ? (string)$_POST['content']
                : '';


        try {

            $result =
                saveFile(
                    $baseDir,
                    $historyBaseDir,
                    $path,
                    $content
                );

        } catch (Throwable $e) {

            error_log(
                'save_file error: ' .
                $e->getMessage()
            );

            $result = [
                'success' => false,
                'error' =>
                    'ファイル保存中にサーバーエラーが発生しました。'
            ];
        }


        if ($result['success'] === true) {

            $currentPath =
                (string)$result['path'];

            $currentContent =
                $content;


            if (
                isset(
                    $result['warning']
                )
            ) {

                $message =
                    (string)$result['warning'];

            } else {

                $message =
                    '保存しました。';
            }

        } else {

            $error =
                (string)(
                    $result['error']
                    ?? '保存に失敗しました。'
                );

            $currentPath =
                $path;

            $currentContent =
                $content;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | 不明なaction
    |--------------------------------------------------------------------------
    */

    else {

        $error =
            '不明な操作です。';
    }
}


/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
|
| URLに ?path=xxx を付けて直接ファイルを開ける。
|
| 例：
|
| index.php?path=sample-app
|
|--------------------------------------------------------------------------
*/

elseif (
    isset($_GET['path'])
) {

    $path =
        (string)$_GET['path'];


    try {

        $result =
            loadFile(
                $baseDir,
                $path
            );

    } catch (Throwable $e) {

        error_log(
            'GET load error: ' .
            $e->getMessage()
        );

        $result = [
            'success' => false,
            'error' =>
                'ファイル読み込み中にサーバーエラーが発生しました。'
        ];
    }


    if ($result['success'] === true) {

        $currentPath =
            (string)$result['path'];

        $currentContent =
            (string)$result['content'];

    } else {

        $error =
            (string)(
                $result['error']
                ?? 'ファイルを読み込めませんでした。'
            );

        $currentPath =
            $path;
    }
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

<title>
PHPファイルエディタ
</title>


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

    background: #f3f5f7;

    color: #202124;

    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}


header {

    background: #17202a;

    color: white;

    padding: 16px 24px;
}


header h1 {

    margin: 0;

    font-size: 20px;
}


main {

    width: min(
        1200px,
        calc(100% - 30px)
    );

    margin: 25px auto;
}


.panel {

    background: white;

    border-radius: 8px;

    padding: 20px;

    margin-bottom: 20px;

    box-shadow:
        0 2px 8px rgba(
            0,
            0,
            0,
            .08
        );
}


.field {

    margin-bottom: 18px;
}


label {

    display: block;

    font-weight: 600;

    margin-bottom: 7px;
}


input,
textarea {

    width: 100%;

    border:
        1px solid #c7ccd1;

    border-radius: 5px;

    padding: 10px;

    font-size: 14px;
}


textarea {

    min-height: 550px;

    resize: vertical;

    font-family:
        Consolas,
        "Courier New",
        monospace;

    line-height: 1.5;

    tab-size: 4;
}


button {

    border: 0;

    border-radius: 5px;

    padding:
        10px 18px;

    background: #1976d2;

    color: white;

    cursor: pointer;

    font-size: 14px;
}


button:hover {

    background: #125da7;
}


button.secondary {

    background: #546e7a;
}


button.secondary:hover {

    background: #37474f;
}


.success {

    background: #e8f5e9;

    color: #256029;

    border:
        1px solid #81c784;

    border-radius: 5px;

    padding: 12px;

    margin-bottom: 20px;
}


.error {

    background: #ffebee;

    color: #a51c30;

    border:
        1px solid #e57373;

    border-radius: 5px;

    padding: 12px;

    margin-bottom: 20px;
}


.info {

    background: #eef4ff;

    border:
        1px solid #b8c9ed;

    border-radius: 5px;

    padding: 12px;

    margin-bottom: 18px;

    line-height: 1.6;
}


.actions {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;
}


small {

    color: #666;
}


@media (
    max-width: 600px
) {

    main {

        width:
            calc(100% - 20px);

        margin:
            10px auto;
    }


    .panel {

        padding: 14px;
    }


    textarea {

        min-height: 400px;
    }

}

</style>

</head>


<body>


<header>

<h1>
PHPファイルエディタ
</h1>

</header>


<main>


<?php if ($message !== ''): ?>

<div class="success">

<?= h($message) ?>

</div>

<?php endif; ?>


<?php if ($error !== ''): ?>

<div class="error">

<?= h($error) ?>

</div>

<?php endif; ?>


<div class="panel">

<h2>
サーバー上のPHPファイル
</h2>


<div class="info">

この画面では、

<br>

<strong>
サーバー上の index.php
</strong>

を直接読み込み、

編集し、

通常のPOSTで保存します。

<br><br>

<code>fetch()</code> は使用していません。

<br>

<code>apiCall()</code> も使用していません。

<br>

JavaScript APIも使用していません。

</div>


<form
    method="post"
    action=""
>

<input
    type="hidden"
    name="action"
    value="load_file"
>


<div class="field">

<label for="load-path">

読み込むディレクトリ

</label>


<input
    id="load-path"
    name="path"
    type="text"
    value="<?= h($currentPath) ?>"
    placeholder="例：sample-app"
>


<small>

このindex.phpからの相対パスです。

</small>

</div>


<button
    type="submit"
>

読み込む

</button>

</form>

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

<label for="save-path">

保存先ディレクトリ

</label>


<input
    id="save-path"
    name="path"
    type="text"
    value="<?= h($currentPath) ?>"
    placeholder="例：sample-app"
    required
>


<small>

保存すると、

<br>

指定ディレクトリの

<code>index.php</code>

が更新されます。

</small>

</div>


<div class="field">

<label for="editor-content">

ファイル内容

</label>


<textarea
    id="editor-content"
    name="content"
    spellcheck="false"
><?= h($currentContent) ?></textarea>

</div>


<div class="actions">

<button
    type="submit"
>

保存

</button>


<button
    type="button"
    class="secondary"
    onclick="location.reload()"
>

再読み込み

</button>

</div>

</form>

</div>


<div class="panel">

<h2>
現在の構造
</h2>


<div class="info">

<strong>
読み込み：
</strong>

<br>

ブラウザ

→

GET/POST

→

Apache

→

index.php

→

PHP

→

file_get_contents()

<br><br>


<strong>
保存：
</strong>

<br>

ブラウザ

→

POST

→

Apache

→

index.php

→

PHP

→

file_put_contents()

<br><br>


<strong>
使用していないもの：
</strong>

<br>

fetch()

<br>

apiCall()

<br>

JSON API

<br>

XMLHttpRequest

<br>

データベース

</div>

</div>


</main>


</body>

</html>
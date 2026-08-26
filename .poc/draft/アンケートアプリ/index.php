<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 1ファイル PHP ファイル管理・編集
|--------------------------------------------------------------------------
|
| 通信方式：
|
|   GET  -> このPHP自身
|   POST -> このPHP自身
|
| 使用しない：
|
|   fetch()
|   apiCall()
|   XMLHttpRequest
|   JSON API
|   データベース
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| 基本設定
|--------------------------------------------------------------------------
*/

$baseDir = __DIR__;

$historyBaseDir =
    $baseDir .
    DIRECTORY_SEPARATOR .
    '.history';


/*
|--------------------------------------------------------------------------
| HTMLエスケープ
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
| 相対パス検証
|--------------------------------------------------------------------------
*/

function normalizePath(string $path): ?string
{
    $path = trim($path);

    $path = str_replace(
        '\\',
        '/',
        $path
    );

    $path = rawurldecode($path);

    $path = trim(
        $path,
        '/'
    );

    if ($path === '') {
        return null;
    }

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
         * Windowsドライブ指定を拒否
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

    return implode('/', $parts);
}


/*
|--------------------------------------------------------------------------
| 対象ディレクトリ
|--------------------------------------------------------------------------
*/

function targetDirectory(
    string $baseDir,
    string $relativePath
): string {

    $parts = explode(
        '/',
        $relativePath
    );

    $path = $baseDir;

    foreach ($parts as $part) {

        $path .=
            DIRECTORY_SEPARATOR .
            $part;
    }

    return $path;
}


/*
|--------------------------------------------------------------------------
| 対象 index.php
|--------------------------------------------------------------------------
*/

function targetFile(
    string $baseDir,
    string $relativePath
): string {

    return
        targetDirectory(
            $baseDir,
            $relativePath
        )
        .
        DIRECTORY_SEPARATOR .
        'index.php';
}


/*
|--------------------------------------------------------------------------
| アプリ一覧
|--------------------------------------------------------------------------
|
| このPHP自身が存在するディレクトリ直下から、
| index.phpを持っているディレクトリだけを取得する。
|
| .history は除外。
|
|--------------------------------------------------------------------------
*/

function getApplications(
    string $baseDir
): array {

    $applications = [];

    $items = scandir($baseDir);

    if ($items === false) {
        return [];
    }

    foreach ($items as $item) {

        if (
            $item === '.' ||
            $item === '..' ||
            $item === '.history'
        ) {
            continue;
        }

        $fullPath =
            $baseDir .
            DIRECTORY_SEPARATOR .
            $item;


        if (!is_dir($fullPath)) {
            continue;
        }


        /*
         * ディレクトリ名として安全なものだけ
         */
        if (
            preg_match(
                '/^[A-Za-z0-9_\-\.]+$/u',
                $item
            ) !== 1
        ) {
            continue;
        }


        $indexFile =
            $fullPath .
            DIRECTORY_SEPARATOR .
            'index.php';


        if (!is_file($indexFile)) {
            continue;
        }


        $applications[] = [
            'name' => $item,
            'path' => $item
        ];
    }


    usort(
        $applications,
        static function (
            array $a,
            array $b
        ): int {

            return strnatcasecmp(
                $a['name'],
                $b['name']
            );
        }
    );


    return $applications;
}


/*
|--------------------------------------------------------------------------
| ファイル読み込み
|--------------------------------------------------------------------------
*/

function readApplication(
    string $baseDir,
    string $path
): array {

    $normalized =
        normalizePath($path);


    if ($normalized === null) {

        return [
            'success' => false,
            'message' =>
                '不正なアプリパスです。'
        ];
    }


    /*
     * 今回は第1階層だけ許可
     */
    if (
        substr_count(
            $normalized,
            '/'
        ) !== 0
    ) {

        return [
            'success' => false,
            'message' =>
                'アプリパスは1階層のみ指定できます。'
        ];
    }


    $directory =
        targetDirectory(
            $baseDir,
            $normalized
        );


    $file =
        targetFile(
            $baseDir,
            $normalized
        );


    if (!is_dir($directory)) {

        return [
            'success' => false,
            'message' =>
                'アプリディレクトリが存在しません。'
        ];
    }


    if (!is_file($file)) {

        return [
            'success' => false,
            'message' =>
                'index.phpが存在しません。'
        ];
    }


    $content =
        file_get_contents(
            $file
        );


    if ($content === false) {

        return [
            'success' => false,
            'message' =>
                'index.phpを読み込めませんでした。'
        ];
    }


    return [
        'success' => true,
        'path' => $normalized,
        'content' => $content
    ];
}


/*
|--------------------------------------------------------------------------
| ファイル保存
|--------------------------------------------------------------------------
*/

function writeApplication(
    string $baseDir,
    string $historyBaseDir,
    string $path,
    string $content
): array {

    $normalized =
        normalizePath($path);


    if ($normalized === null) {

        return [
            'success' => false,
            'message' =>
                '不正なアプリパスです。'
        ];
    }


    /*
     * 今回は既存アプリだけを編集する。
     *
     * 新規ディレクトリは作らない。
     */
    if (
        substr_count(
            $normalized,
            '/'
        ) !== 0
    ) {

        return [
            'success' => false,
            'message' =>
                'アプリパスは1階層のみ指定できます。'
        ];
    }


    $directory =
        targetDirectory(
            $baseDir,
            $normalized
        );


    $file =
        targetFile(
            $baseDir,
            $normalized
        );


    if (!is_dir($directory)) {

        return [
            'success' => false,
            'message' =>
                '保存対象のアプリディレクトリが存在しません。'
        ];
    }


    if (!is_file($file)) {

        return [
            'success' => false,
            'message' =>
                '保存対象のindex.phpが存在しません。'
        ];
    }


    /*
     * バックアップ
     */
    if (!is_dir($historyBaseDir)) {

        if (
            !mkdir(
                $historyBaseDir,
                0777,
                true
            )
        ) {

            return [
                'success' => false,
                'message' =>
                    '履歴ディレクトリを作成できませんでした。'
            ];
        }
    }


    $historyDirectory =
        $historyBaseDir .
        DIRECTORY_SEPARATOR .
        $normalized;


    if (!is_dir($historyDirectory)) {

        if (
            !mkdir(
                $historyDirectory,
                0777,
                true
            )
        ) {

            return [
                'success' => false,
                'message' =>
                    'アプリ履歴ディレクトリを作成できませんでした。'
            ];
        }
    }


    /*
     * 保存前の内容を履歴へ退避
     */
    $oldContent =
        file_get_contents(
            $file
        );


    if ($oldContent !== false) {

        $historyFile =
            $historyDirectory .
            DIRECTORY_SEPARATOR .
            date('YmdHis') .
            '_' .
            bin2hex(
                random_bytes(4)
            ) .
            '_before.txt';


        file_put_contents(
            $historyFile,
            $oldContent,
            LOCK_EX
        );
    }


    /*
     * 本体保存
     */
    $result =
        file_put_contents(
            $file,
            $content,
            LOCK_EX
        );


    if ($result === false) {

        return [
            'success' => false,
            'message' =>
                'index.phpの保存に失敗しました。'
        ];
    }


    return [
        'success' => true,
        'message' =>
            '保存しました。',
        'path' =>
            $normalized
    ];
}


/*
|--------------------------------------------------------------------------
| 状態
|--------------------------------------------------------------------------
*/

$message = '';

$error = '';

$selectedPath = '';

$editorContent = '';


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    === 'POST'
) {

    $action =
        isset($_POST['action'])
            ? (string)$_POST['action']
            : '';


    /*
     * 読み込み
     */
    if ($action === 'load') {

        $path =
            isset($_POST['path'])
                ? (string)$_POST['path']
                : '';


        $result =
            readApplication(
                $baseDir,
                $path
            );


        if ($result['success']) {

            $selectedPath =
                (string)$result['path'];

            $editorContent =
                (string)$result['content'];

            $message =
                '読み込みました。';

        } else {

            $error =
                (string)$result['message'];
        }
    }


    /*
     * 保存
     */
    elseif ($action === 'save') {

        $path =
            isset($_POST['path'])
                ? (string)$_POST['path']
                : '';


        $content =
            isset($_POST['content'])
                ? (string)$_POST['content']
                : '';


        $result =
            writeApplication(
                $baseDir,
                $historyBaseDir,
                $path,
                $content
            );


        $selectedPath =
            $path;

        $editorContent =
            $content;


        if ($result['success']) {

            $message =
                (string)$result['message'];

        } else {

            $error =
                (string)$result['message'];
        }
    }


    /*
     * 不明な操作
     */
    else {

        $error =
            '不明な操作です。';
    }
}


/*
|--------------------------------------------------------------------------
| GET ?app=sample-app
|--------------------------------------------------------------------------
*/

elseif (
    isset($_GET['app'])
) {

    $path =
        (string)$_GET['app'];


    $result =
        readApplication(
            $baseDir,
            $path
        );


    if ($result['success']) {

        $selectedPath =
            (string)$result['path'];

        $editorContent =
            (string)$result['content'];

    } else {

        $error =
            (string)$result['message'];
    }
}


/*
|--------------------------------------------------------------------------
| アプリ一覧
|--------------------------------------------------------------------------
*/

$applications =
    getApplications(
        $baseDir
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

<title>
PHPファイル管理
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

    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;

    background: #f3f5f7;

    color: #202124;
}


header {

    background: #17202a;

    color: #fff;

    padding:
        16px 24px;
}


header h1 {

    margin: 0;

    font-size: 20px;
}


main {

    width:
        min(
            1300px,
            calc(100% - 30px)
        );

    margin:
        20px auto;
}


.panel {

    background: #fff;

    border-radius: 8px;

    padding: 20px;

    margin-bottom: 20px;

    box-shadow:
        0 2px 8px
        rgba(
            0,
            0,
            0,
            .08
        );
}


.application-list {

    display: grid;

    grid-template-columns:
        repeat(
            auto-fill,
            minmax(
                220px,
                1fr
            )
        );

    gap: 10px;
}


.application {

    border:
        1px solid #d5d9dd;

    border-radius: 6px;

    padding: 12px;

    background: #fafafa;
}


.application strong {

    display: block;

    margin-bottom: 8px;
}


button {

    border: 0;

    border-radius: 5px;

    padding:
        9px 16px;

    background: #1976d2;

    color: #fff;

    cursor: pointer;

    font-size: 14px;
}


button:hover {

    background: #125da7;
}


button.gray {

    background: #607d8b;
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

    min-height: 600px;

    resize: vertical;

    font-family:
        Consolas,
        "Courier New",
        monospace;

    line-height: 1.5;

    tab-size: 4;
}


.success {

    padding: 12px;

    margin-bottom: 20px;

    border:
        1px solid #81c784;

    border-radius: 5px;

    background: #e8f5e9;

    color: #256029;
}


.error {

    padding: 12px;

    margin-bottom: 20px;

    border:
        1px solid #e57373;

    border-radius: 5px;

    background: #ffebee;

    color: #a51c30;
}


.info {

    padding: 12px;

    margin-bottom: 15px;

    border:
        1px solid #b8c9ed;

    border-radius: 5px;

    background: #eef4ff;

    line-height: 1.6;
}


.actions {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;

    margin-top: 10px;
}


@media (
    max-width: 600px
) {

    main {

        width:
            calc(100% - 20px);
    }


    .panel {

        padding: 14px;
    }


    textarea {

        min-height: 450px;
    }

}

</style>

</head>


<body>


<header>

<h1>
PHPファイル管理・編集
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
既存アプリ
</h2>


<div class="info">

この一覧はPHPが

<code>scandir()</code>

でサーバー側から取得しています。

<br>

ブラウザからAPIを呼び出しているわけではありません。

</div>


<?php if (count($applications) === 0): ?>

<p>
index.phpを持つアプリがありません。
</p>

<?php else: ?>


<div class="application-list">


<?php foreach (
    $applications
    as $application
): ?>


<div class="application">

<strong>

<?= h($application['name']) ?>

</strong>


<form
    method="post"
    action=""
>

<input
    type="hidden"
    name="action"
    value="load"
>


<input
    type="hidden"
    name="path"
    value="<?= h($application['path']) ?>"
>


<button
    type="submit"
>

編集

</button>

</form>

</div>


<?php endforeach; ?>


</div>

<?php endif; ?>

</div>


<?php if ($selectedPath !== ''): ?>


<div class="panel">

<h2>
編集中
</h2>


<div class="info">

<strong>
<?= h($selectedPath) ?>/index.php
</strong>

<br>

保存すると、サーバー上のこのファイルが直接更新されます。

</div>


<form
    method="post"
    action=""
>


<input
    type="hidden"
    name="action"
    value="save"
>


<input
    type="hidden"
    name="path"
    value="<?= h($selectedPath) ?>"
>


<textarea
    name="content"
    spellcheck="false"
><?= h($editorContent) ?></textarea>


<div class="actions">


<button
    type="submit"
>

保存

</button>


<button
    type="button"
    class="gray"
    onclick="location.reload()"
>

破棄して再読み込み

</button>


</div>


</form>

</div>


<?php endif; ?>


<div class="panel">

<h2>
現在の通信構造
</h2>


<div class="info">

<strong>
読み込み
</strong>

<br>

ブラウザ

→

POST

→

Apache

→

この index.php

→

PHP

→

<code>file_get_contents()</code>


<br><br>


<strong>
保存
</strong>

<br>

ブラウザ

→

POST

→

Apache

→

この index.php

→

PHP

→

<code>file_put_contents()</code>


<br><br>


<strong>
使用していないもの
</strong>

<br>

fetch()

<br>

apiCall()

<br>

XMLHttpRequest

<br>

JSON API

<br>

データベース

</div>

</div>


</main>


</body>

</html>
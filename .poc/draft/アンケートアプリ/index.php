<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 1ファイル PHP アプリ管理・編集システム
|--------------------------------------------------------------------------
|
| この段階では「基本構造」を優先する。
|
| 通信方式：
|
|   GET
|   POST
|
| サーバー処理：
|
|   PHP
|   scandir()
|   file_get_contents()
|   file_put_contents()
|   mkdir()
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
| パス正規化
|--------------------------------------------------------------------------
|
| この段階では、
|
|   管理用index.php
|       ├── app-a/
|       │   └── index.php
|       ├── app-b/
|       │   └── index.php
|       └── ...
|
| という第1階層のアプリだけを扱う。
|
|--------------------------------------------------------------------------
*/

function normalizeAppName(
    string $name
): ?string {

    $name = trim($name);

    /*
     * URLエンコード対策
     */
    $name = rawurldecode($name);

    /*
     * Windows区切り文字を拒否
     */
    if (str_contains($name, '\\')) {
        return null;
    }

    /*
     * パス区切りを拒否
     */
    if (str_contains($name, '/')) {
        return null;
    }

    /*
     * 空
     */
    if ($name === '') {
        return null;
    }

    /*
     * . と ..
     */
    if (
        $name === '.' ||
        $name === '..'
    ) {
        return null;
    }

    /*
     * Windowsドライブ
     */
    if (
        preg_match(
            '/^[A-Za-z]:$/',
            $name
        )
    ) {
        return null;
    }

    /*
     * 制御文字を拒否
     */
    if (
        preg_match(
            '/[\x00-\x1F\x7F]/',
            $name
        )
    ) {
        return null;
    }

    /*
     * ディレクトリ名として許可する文字。
     *
     * 日本語も許可する。
     *
     * 例：
     *
     *   sample-app
     *   test_app
     *   アンケート
     *   アンケート2026
     */
    if (
        preg_match(
            '/^[\p{L}\p{N}_.\- ]+$/u',
            $name
        ) !== 1
    ) {
        return null;
    }

    return $name;
}


/*
|--------------------------------------------------------------------------
| アプリディレクトリ
|--------------------------------------------------------------------------
*/

function getAppDirectory(
    string $baseDir,
    string $appName
): string {

    return
        $baseDir .
        DIRECTORY_SEPARATOR .
        $appName;
}


/*
|--------------------------------------------------------------------------
| アプリ index.php
|--------------------------------------------------------------------------
*/

function getAppFile(
    string $baseDir,
    string $appName
): string {

    return
        getAppDirectory(
            $baseDir,
            $appName
        )
        .
        DIRECTORY_SEPARATOR .
        'index.php';
}


/*
|--------------------------------------------------------------------------
| 管理対象外ディレクトリ判定
|--------------------------------------------------------------------------
*/

function isSystemDirectory(
    string $name
): bool {

    return in_array(
        $name,
        [
            '.',
            '..',
            '.history'
        ],
        true
    );
}


/*
|--------------------------------------------------------------------------
| アプリ一覧取得
|--------------------------------------------------------------------------
|
| 「index.phpを持つディレクトリ」だけをアプリとする。
|
|--------------------------------------------------------------------------
*/

function getApplications(
    string $baseDir
): array {

    $applications = [];

    $items =
        scandir(
            $baseDir
        );


    if ($items === false) {
        return [];
    }


    foreach ($items as $item) {

        if (
            isSystemDirectory(
                $item
            )
        ) {
            continue;
        }


        $directory =
            $baseDir .
            DIRECTORY_SEPARATOR .
            $item;


        if (!is_dir($directory)) {
            continue;
        }


        /*
         * index.phpが存在すること
         */
        $file =
            $directory .
            DIRECTORY_SEPARATOR .
            'index.php';


        if (!is_file($file)) {
            continue;
        }


        /*
         * アプリ名として安全なものだけ
         */
        if (
            normalizeAppName(
                $item
            ) === null
        ) {
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
| アプリ読み込み
|--------------------------------------------------------------------------
*/

function loadApplication(
    string $baseDir,
    string $appName
): array {

    $appName =
        normalizeAppName(
            $appName
        );


    if ($appName === null) {

        return [
            'success' => false,
            'message' =>
                'アプリ名が不正です。'
        ];
    }


    $directory =
        getAppDirectory(
            $baseDir,
            $appName
        );


    $file =
        getAppFile(
            $baseDir,
            $appName
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
                'アプリのindex.phpが存在しません。'
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
        'appName' => $appName,
        'content' => $content
    ];
}


/*
|--------------------------------------------------------------------------
| アプリ保存
|--------------------------------------------------------------------------
|
| 既存アプリのみ更新する。
|
| 新規ディレクトリは作らない。
|
|--------------------------------------------------------------------------
*/

function saveApplication(
    string $baseDir,
    string $historyBaseDir,
    string $appName,
    string $content
): array {

    $appName =
        normalizeAppName(
            $appName
        );


    if ($appName === null) {

        return [
            'success' => false,
            'message' =>
                'アプリ名が不正です。'
        ];
    }


    $directory =
        getAppDirectory(
            $baseDir,
            $appName
        );


    $file =
        getAppFile(
            $baseDir,
            $appName
        );


    /*
     * 既存アプリ限定
     */
    if (!is_dir($directory)) {

        return [
            'success' => false,
            'message' =>
                '保存対象のアプリが存在しません。'
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
     * 履歴ディレクトリ
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
        $appName;


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
     * 現在の内容をバックアップ
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
     * 保存
     */
    $written =
        file_put_contents(
            $file,
            $content,
            LOCK_EX
        );


    if ($written === false) {

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
        'appName' =>
            $appName
    ];
}


/*
|--------------------------------------------------------------------------
| 新規アプリ作成
|--------------------------------------------------------------------------
|
| この処理だけがmkdir()を実行する。
|
|--------------------------------------------------------------------------
*/

function createApplication(
    string $baseDir,
    string $appName,
    string $initialContent
): array {

    $appName =
        normalizeAppName(
            $appName
        );


    if ($appName === null) {

        return [
            'success' => false,
            'message' =>
                'アプリ名が不正です。'
        ];
    }


    $directory =
        getAppDirectory(
            $baseDir,
            $appName
        );


    $file =
        getAppFile(
            $baseDir,
            $appName
        );


    /*
     * 既存チェック
     */
    if (is_dir($directory)) {

        return [
            'success' => false,
            'message' =>
                '同名のアプリがすでに存在します。'
        ];
    }


    /*
     * ディレクトリ作成
     */
    if (
        !mkdir(
            $directory,
            0777,
            true
        )
    ) {

        return [
            'success' => false,
            'message' =>
                'アプリディレクトリを作成できませんでした。'
        ];
    }


    /*
     * index.php作成
     */
    $written =
        file_put_contents(
            $file,
            $initialContent,
            LOCK_EX
        );


    if ($written === false) {

        /*
         * index.php作成失敗時は
         * 作成途中のディレクトリを削除する。
         */
        @rmdir($directory);


        return [
            'success' => false,
            'message' =>
                'index.phpを作成できませんでした。'
        ];
    }


    return [
        'success' => true,
        'message' =>
            '新しいアプリを作成しました。',
        'appName' =>
            $appName
    ];
}


/*
|--------------------------------------------------------------------------
| 初期PHPコード
|--------------------------------------------------------------------------
*/

$defaultApplicationContent =
<<<'PHP'
<?php

declare(strict_types=1);

echo '<!DOCTYPE html>';
echo '<html lang="ja">';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<title>新しいアプリ</title>';
echo '</head>';
echo '<body>';

echo '<h1>新しいアプリ</h1>';

echo '<p>このアプリはPHPから作成されました。</p>';

echo '</body>';
echo '</html>';

PHP;


/*
|--------------------------------------------------------------------------
| 画面状態
|--------------------------------------------------------------------------
*/

$message = '';

$error = '';

$selectedApp = '';

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
    |--------------------------------------------------------------------------
    | 新規作成
    |--------------------------------------------------------------------------
    */

    if ($action === 'create') {

        $appName =
            isset($_POST['app_name'])
                ? (string)$_POST['app_name']
                : '';


        $result =
            createApplication(
                $baseDir,
                $appName,
                $defaultApplicationContent
            );


        if ($result['success']) {

            $message =
                (string)$result['message'];

            $selectedApp =
                (string)$result['appName'];


            /*
             * 作成したファイルを読み込んで
             * エディタに表示
             */
            $loaded =
                loadApplication(
                    $baseDir,
                    $selectedApp
                );


            if ($loaded['success']) {

                $editorContent =
                    (string)$loaded['content'];
            }

        } else {

            $error =
                (string)$result['message'];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | 読み込み
    |--------------------------------------------------------------------------
    */

    elseif ($action === 'load') {

        $appName =
            isset($_POST['app_name'])
                ? (string)$_POST['app_name']
                : '';


        $result =
            loadApplication(
                $baseDir,
                $appName
            );


        if ($result['success']) {

            $selectedApp =
                (string)$result['appName'];

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
    |--------------------------------------------------------------------------
    | 保存
    |--------------------------------------------------------------------------
    */

    elseif ($action === 'save') {

        $appName =
            isset($_POST['app_name'])
                ? (string)$_POST['app_name']
                : '';


        $content =
            isset($_POST['content'])
                ? (string)$_POST['content']
                : '';


        $result =
            saveApplication(
                $baseDir,
                $historyBaseDir,
                $appName,
                $content
            );


        $selectedApp =
            $appName;

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
    |--------------------------------------------------------------------------
    | 不明な操作
    |--------------------------------------------------------------------------
    */

    else {

        $error =
            '不明な操作です。';
    }
}


/*
|--------------------------------------------------------------------------
| GET ?app=xxx
|--------------------------------------------------------------------------
*/

elseif (
    isset($_GET['app'])
) {

    $appName =
        (string)$_GET['app'];


    $result =
        loadApplication(
            $baseDir,
            $appName
        );


    if ($result['success']) {

        $selectedApp =
            (string)$result['appName'];

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
PHPアプリ管理
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

    padding:
        16px 24px;

    background: #17202a;

    color: white;
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

    background: white;

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


.panel h2 {

    margin-top: 0;
}


.info {

    padding: 12px;

    background: #eef4ff;

    border:
        1px solid #b8c9ed;

    border-radius: 5px;

    margin-bottom: 15px;

    line-height: 1.6;
}


.success {

    padding: 12px;

    background: #e8f5e9;

    border:
        1px solid #81c784;

    border-radius: 5px;

    color: #256029;

    margin-bottom: 20px;
}


.error {

    padding: 12px;

    background: #ffebee;

    border:
        1px solid #e57373;

    border-radius: 5px;

    color: #a51c30;

    margin-bottom: 20px;
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


input {

    margin-bottom: 10px;
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


button {

    border: 0;

    border-radius: 5px;

    padding:
        9px 16px;

    background: #1976d2;

    color: white;

    cursor: pointer;

    font-size: 14px;
}


button:hover {

    background: #125da7;
}


button.gray {

    background: #607d8b;
}


button.green {

    background: #388e3c;
}


button.red {

    background: #c62828;
}


.application-list {

    display: grid;

    grid-template-columns:
        repeat(
            auto-fill,
            minmax(
                230px,
                1fr
            )
        );

    gap: 10px;
}


.application {

    padding: 12px;

    border:
        1px solid #d5d9dd;

    border-radius: 6px;

    background: #fafafa;
}


.application-name {

    font-weight: 600;

    margin-bottom: 10px;

    word-break: break-all;
}


.application form {

    margin: 0;
}


.actions {

    display: flex;

    flex-wrap: wrap;

    gap: 10px;

    margin-top: 12px;
}


.create-form {

    max-width: 600px;
}


.path-display {

    padding: 10px;

    background: #f5f5f5;

    border:
        1px solid #ddd;

    border-radius: 5px;

    margin-bottom: 10px;

    font-family:
        Consolas,
        monospace;

    word-break: break-all;
}


code {

    font-family:
        Consolas,
        "Courier New",
        monospace;
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
PHPアプリ管理
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


<!-- ======================================================
     新規アプリ作成
     ====================================================== -->

<div class="panel">

<h2>
新規アプリ作成
</h2>


<div class="info">

ここで作成した場合だけ、

<code>mkdir()</code>

で新しいアプリディレクトリを作成します。

<br>

既存アプリの保存処理では、新しいディレクトリを作成しません。

</div>


<form
    method="post"
    action=""
    class="create-form"
>


<input
    type="hidden"
    name="action"
    value="create"
>


<label for="app-name">

アプリ名

</label>


<input
    id="app-name"
    name="app_name"
    type="text"
    placeholder="例：test-app"
    required
    autocomplete="off"
>


<button
    type="submit"
    class="green"
>

アプリを作成

</button>


</form>

</div>


<!-- ======================================================
     アプリ一覧
     ====================================================== -->

<div class="panel">

<h2>
アプリ一覧
</h2>


<div class="info">

PHPの

<code>scandir()</code>

でサーバー上のディレクトリを直接調べています。

<br>

<code>index.php</code>

を持つ既存ディレクトリだけを表示します。

</div>


<?php if (
    count($applications) === 0
): ?>

<p>
アプリはありません。
</p>


<?php else: ?>


<div class="application-list">


<?php foreach (
    $applications
    as $application
): ?>


<div class="application">


<div class="application-name">

<?= h($application['name']) ?>

</div>


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
    name="app_name"
    value="<?= h($application['name']) ?>"
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


<!-- ======================================================
     エディタ
     ====================================================== -->

<?php if (
    $selectedApp !== ''
): ?>


<div class="panel">

<h2>
アプリ編集
</h2>


<div class="path-display">

<?= h($selectedApp) ?>/index.php

</div>


<div class="info">

これは既存ファイルの編集です。

<br>

保存時にサーバー側PHPが直接

<code>file_put_contents()</code>

を実行します。

<br>

ブラウザからAPIを呼び出していません。

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
    name="app_name"
    value="<?= h($selectedApp) ?>"
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

再読み込み

</button>


</div>


</form>

</div>


<?php endif; ?>


<!-- ======================================================
     構造確認
     ====================================================== -->

<div class="panel">

<h2>
現在の基本構造
</h2>


<div class="info">

<strong>
① 新規作成
</strong>

<br>

ブラウザ

→ POST

→ Apache

→ この index.php

→ createApplication()

→ mkdir()

→ index.php作成


<br><br>


<strong>
② 読み込み
</strong>

<br>

ブラウザ

→ POST

→ Apache

→ この index.php

→ loadApplication()

→ file_get_contents()


<br><br>


<strong>
③ 保存
</strong>

<br>

ブラウザ

→ POST

→ Apache

→ この index.php

→ saveApplication()

→ file_put_contents()


<br><br>


<strong>
④ 履歴
</strong>

<br>

保存前の内容を

<code>.history/アプリ名/</code>

へ退避します。


<br><br>


<strong>
サーバー通信に使用していないもの
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

AJAX

<br>

データベース

</div>

</div>


</main>


</body>

</html>
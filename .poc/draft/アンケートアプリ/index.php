<?php

declare(strict_types=1);

/*
 * ============================================================
 * アンケートアプリ
 * Apache + PHP 8.5
 * DBなし / cURLなし / index.php単一エントリーポイント
 * ============================================================
 */

session_set_cookie_params([
    'httponly' => true,
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
    'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') ?: '/',
]);

session_start();

date_default_timezone_set('Asia/Tokyo');


/* ============================================================
 * 共通
 * ============================================================
 */

$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0770, true);
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';

    if (
        !is_string($token) ||
        $token === '' ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(400);
        render_error(
            '不正なリクエストです。',
            'CSRFトークンが一致しません。画面を再読み込みして再度お試しください。'
        );
        exit;
    }
}

function render_error(string $title, string $message): void
{
    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= h($title) ?></title>
        <style>
            :root {
                --primary:#2563eb;
                --danger:#dc2626;
                --gray-light:#f1f5f9;
                --border:#dbe2ea;
                --text:#1e293b;
                --white:#fff;
                --shadow:0 4px 18px rgba(15,23,42,.08);
            }

            * {
                box-sizing:border-box;
            }

            body {
                margin:0;
                background:#f8fafc;
                color:var(--text);
                font-family:
                    -apple-system,
                    BlinkMacSystemFont,
                    "Segoe UI",
                    "Noto Sans JP",
                    "Hiragino Kaku Gothic ProN",
                    Meiryo,
                    sans-serif;
            }

            .container {
                max-width:900px;
                margin:60px auto;
                padding:24px;
            }

            .card {
                background:#fff;
                border:1px solid var(--border);
                border-radius:12px;
                padding:28px;
                box-shadow:var(--shadow);
            }

            .error {
                color:var(--danger);
                font-weight:700;
                font-size:20px;
                margin-bottom:12px;
            }

            .message {
                line-height:1.8;
            }

            .button {
                display:inline-block;
                margin-top:20px;
                padding:10px 18px;
                border-radius:8px;
                background:var(--primary);
                color:#fff;
                text-decoration:none;
            }
        </style>
    </head>
    <body>
        <main class="container">
            <section class="card">
                <div class="error"><?= h($title) ?></div>
                <div class="message"><?= nl2br(h($message)) ?></div>
                <a class="button" href="index.php?screen=kintone">
                    kintone設定へ戻る
                </a>
            </section>
        </main>
    </body>
    </html>
    <?php
}


/* ============================================================
 * JSON保存
 * ============================================================
 */

function read_json_file(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }

    $json = file_get_contents($file);

    if ($json === false || trim($json) === '') {
        return $default;
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : $default;
}

function write_json_file(string $file, array $data): bool
{
    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $fp = fopen($tmp, 'wb');

    if ($fp === false) {
        return false;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tmp);
            return false;
        }

        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    } catch (Throwable $e) {
        fclose($fp);
        @unlink($tmp);
        return false;
    }
}


/* ============================================================
 * kintone設定
 * ============================================================
 */

function kintone_config_file(): string
{
    global $dataDir;

    return $dataDir . DIRECTORY_SEPARATOR . 'kintone.json';
}

function load_kintone_config(): array
{
    return read_json_file(
        kintone_config_file(),
        [
            'subdomain' => '',
            'app_id' => '',
            'username' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => false,
            'field_mapping' => [
                'organization' => [],
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
            'connection_status' => '未設定',
        ]
    );
}


/*
 * パスワードは画面へ戻さない。
 * POSTで空なら既存値を維持する。
 */
function save_kintone_settings(): void
{
    verify_csrf();

    $config = load_kintone_config();

    $subdomain = trim((string)($_POST['subdomain'] ?? ''));
    $appId     = trim((string)($_POST['app_id'] ?? ''));
    $username  = trim((string)($_POST['username'] ?? ''));
    $password  = (string)($_POST['password'] ?? '');
    $proxy     = trim((string)($_POST['proxy'] ?? ''));

    /*
     * SSL検証
     *
     * POC初期値は無効。
     */
    $verifySsl = isset($_POST['verify_ssl']);

    /*
     * サブドメイン正規化
     *
     * 以下を許容:
     * https://xxxx.cybozu.com
     * xxxx.cybozu.com
     * xxxx
     */
    $subdomain = preg_replace(
        '#^https?://#i',
        '',
        $subdomain
    );

    $subdomain = trim($subdomain, "/ \t\n\r\0\x0B");

    if ($subdomain === '') {
        render_error(
            '入力エラー',
            'kintoneサブドメインを入力してください。'
        );
        exit;
    }

    if (preg_match('/^[a-zA-Z0-9-]+$/', $subdomain)) {
        $subdomain .= '.cybozu.com';
    }

    if (
        !preg_match(
            '/^[a-zA-Z0-9-]+\.cybozu\.com$/',
            $subdomain
        )
    ) {
        render_error(
            '入力エラー',
            'kintoneサブドメインの形式が正しくありません。'
        );
        exit;
    }

    if ($appId === '' || !ctype_digit($appId) || (int)$appId <= 0) {
        render_error(
            '入力エラー',
            '顧客管理アプリIDは正の整数で入力してください。'
        );
        exit;
    }

    if ($username === '') {
        render_error(
            '入力エラー',
            'ログイン名を入力してください。'
        );
        exit;
    }

    /*
     * パスワードは空欄の場合、
     * 既存設定が存在すればそれを維持する。
     *
     * これにより、設定画面を開いて保存しただけで
     * パスワードが消えることを防ぐ。
     */
    if ($password === '') {
        $password = (string)($config['password'] ?? '');

        /*
         * 新規設定でパスワードが無い場合のみエラー。
         */
        if ($password === '') {
            render_error(
                '入力エラー',
                'パスワードを入力してください。'
            );
            exit;
        }
    }

    /*
     * Proxy
     *
     * host:port のみ。
     */
    if ($proxy !== '') {
        if (
            !preg_match(
                '/^(?:[a-zA-Z0-9.-]+|\[[0-9a-fA-F:]+\]):[0-9]{1,5}$/',
                $proxy
            )
        ) {
            render_error(
                '入力エラー',
                'Proxyは「host:port」形式で入力してください。'
            );
            exit;
        }
    }

    $config['subdomain'] = $subdomain;
    $config['app_id'] = (int)$appId;
    $config['username'] = $username;
    $config['password'] = $password;
    $config['proxy'] = $proxy;
    $config['verify_ssl'] = $verifySsl;

    /*
     * 保存時点では接続確認済みとはしない。
     */
    $config['connection_status'] = '未設定';

    if (!write_json_file(kintone_config_file(), $config)) {
        render_error(
            '保存エラー',
            'kintone設定を保存できませんでした。'
        );
        exit;
    }

    /*
     * POST後に同じkintone設定画面へ戻す。
     * 一覧へ戻さない。
     */
    header(
        'Location: index.php?screen=kintone&saved=1',
        true,
        303
    );
    exit;
}


/* ============================================================
 * POSTルーティング
 * ============================================================
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string)($_POST['action'] ?? '');

    switch ($action) {

        case 'save_kintone':
            save_kintone_settings();
            break;

        /*
         * ここに以下を別処理として実装する。
         *
         * test_kintone
         * refresh_kintone_fields
         * sync_kintone
         *
         * 重要:
         * 「save_kintone」へフォールバックさせない。
         */
        case 'test_kintone':
            verify_csrf();

            // 実際のkintone REST APIへ接続する処理。
            // 成功/失敗を固定値で返してはいけない。

            break;

        case 'refresh_kintone_fields':
            verify_csrf();

            // 実際のkintone REST APIから項目一覧を取得。

            break;

        case 'sync_kintone':
            verify_csrf();

            // 実際のkintone REST APIから顧客情報を取得・同期。

            break;

        default:
            http_response_code(400);

            render_error(
                '不正なリクエストです。',
                '指定された操作は存在しません。'
            );

            exit;
    }
}


/* ============================================================
 * GET画面ルーティング
 * ============================================================
 */

$screen = (string)($_GET['screen'] ?? 'list');

switch ($screen) {

    case 'kintone':

        $config = load_kintone_config();

        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport"
                  content="width=device-width,initial-scale=1">

            <title>kintone連携設定</title>

            <style>
                :root {
                    --primary:#2563eb;
                    --primary-dark:#1d4ed8;
                    --success:#16a34a;
                    --warning:#d97706;
                    --danger:#dc2626;
                    --gray:#64748b;
                    --gray-light:#f1f5f9;
                    --border:#dbe2ea;
                    --text:#1e293b;
                    --white:#fff;
                    --shadow:0 4px 18px rgba(15,23,42,.08);
                }

                * {
                    box-sizing:border-box;
                }

                body {
                    margin:0;
                    background:#f8fafc;
                    color:var(--text);
                    font-family:
                        -apple-system,
                        BlinkMacSystemFont,
                        "Segoe UI",
                        "Noto Sans JP",
                        "Hiragino Kaku Gothic ProN",
                        Meiryo,
                        sans-serif;
                }

                header {
                    background:#0f172a;
                    color:#fff;
                    padding:16px 24px;
                }

                .header-inner {
                    max-width:1200px;
                    margin:auto;
                    display:flex;
                    justify-content:space-between;
                    align-items:center;
                }

                main {
                    max-width:1200px;
                    margin:32px auto;
                    padding:0 20px;
                }

                .card {
                    background:#fff;
                    border:1px solid var(--border);
                    border-radius:12px;
                    box-shadow:var(--shadow);
                    padding:24px;
                    margin-bottom:20px;
                }

                h1 {
                    margin:0;
                    font-size:22px;
                }

                h2 {
                    font-size:18px;
                    margin-top:0;
                }

                .form-row {
                    margin-bottom:18px;
                }

                label {
                    display:block;
                    font-weight:700;
                    margin-bottom:7px;
                }

                input[type="text"],
                input[type="number"],
                input[type="password"] {
                    width:100%;
                    padding:11px 12px;
                    border:1px solid var(--border);
                    border-radius:8px;
                    font-size:15px;
                }

                .hint {
                    color:var(--gray);
                    font-size:13px;
                    margin-top:5px;
                }

                .buttons {
                    display:flex;
                    flex-wrap:wrap;
                    gap:10px;
                    margin-top:24px;
                }

                button {
                    border:0;
                    border-radius:8px;
                    padding:11px 18px;
                    cursor:pointer;
                    font-weight:700;
                    font-size:14px;
                }

                .primary {
                    background:var(--primary);
                    color:#fff;
                }

                .secondary {
                    background:var(--gray-light);
                    color:var(--text);
                }

                .success {
                    background:var(--success);
                    color:#fff;
                }

                .status {
                    display:inline-block;
                    padding:5px 10px;
                    border-radius:999px;
                    background:var(--gray-light);
                    color:var(--gray);
                    font-size:13px;
                    font-weight:700;
                }

                .notice {
                    background:#eff6ff;
                    border:1px solid #bfdbfe;
                    color:#1e40af;
                    padding:12px 14px;
                    border-radius:8px;
                    margin-bottom:20px;
                }

                .back {
                    color:#fff;
                    text-decoration:none;
                }

                @media(max-width:700px) {
                    .buttons {
                        flex-direction:column;
                    }

                    button {
                        width:100%;
                    }
                }
            </style>
        </head>

        <body>

        <header>
            <div class="header-inner">
                <strong>アンケート管理</strong>
                <a class="back" href="index.php?screen=list">
                    アンケート一覧
                </a>
            </div>
        </header>

        <main>

            <div class="card">

                <h1>kintone連携設定</h1>

                <?php if (isset($_GET['saved'])): ?>
                    <div class="notice">
                        kintone設定を保存しました。
                    </div>
                <?php endif; ?>

                <div style="margin:15px 0">
                    接続状態：
                    <span class="status">
                        <?= h($config['connection_status'] ?? '未設定') ?>
                    </span>
                </div>

                <form method="post"
                      action="index.php?screen=kintone">

                    <input type="hidden"
                           name="csrf"
                           value="<?= h(csrf_token()) ?>">

                    <input type="hidden"
                           name="action"
                           value="save_kintone">

                    <div class="form-row">
                        <label for="subdomain">
                            サブドメイン
                        </label>

                        <input
                            id="subdomain"
                            type="text"
                            name="subdomain"
                            value="<?= h($config['subdomain'] ?? '') ?>"
                            placeholder="xxxx.cybozu.com"
                            required
                        >

                        <div class="hint">
                            https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx
                            のいずれでも入力できます。
                        </div>
                    </div>

                    <div class="form-row">
                        <label for="app_id">
                            顧客管理アプリID
                        </label>

                        <input
                            id="app_id"
                            type="number"
                            name="app_id"
                            value="<?= h($config['app_id'] ?? '') ?>"
                            min="1"
                            required
                        >
                    </div>

                    <div class="form-row">
                        <label for="username">
                            ログイン名
                        </label>

                        <input
                            id="username"
                            type="text"
                            name="username"
                            value="<?= h($config['username'] ?? '') ?>"
                            autocomplete="username"
                            required
                        >
                    </div>

                    <div class="form-row">
                        <label for="password">
                            パスワード
                        </label>

                        <input
                            id="password"
                            type="password"
                            name="password"
                            value=""
                            autocomplete="new-password"
                            placeholder="変更しない場合は空欄"
                        >

                        <div class="hint">
                            保存済みパスワードは画面には表示しません。
                            空欄の場合は現在の保存値を維持します。
                        </div>
                    </div>

                    <div class="form-row">
                        <label for="proxy">
                            Proxy
                        </label>

                        <input
                            id="proxy"
                            type="text"
                            name="proxy"
                            value="<?= h($config['proxy'] ?? '') ?>"
                            placeholder="host:port"
                        >

                        <div class="hint">
                            未入力の場合はProxyを使用せず直接接続します。
                        </div>
                    </div>

                    <div class="form-row">
                        <label>
                            SSL証明書検証
                        </label>

                        <label style="font-weight:400">
                            <input
                                type="checkbox"
                                name="verify_ssl"
                                value="1"
                                <?= !empty($config['verify_ssl']) ? 'checked' : '' ?>
                            >
                            SSL証明書を検証する
                        </label>

                        <div class="hint">
                            POCでは初期状態を無効とします。
                        </div>
                    </div>

                    <div class="buttons">

                        <!-- 1. 設定保存 -->
                        <button
                            type="submit"
                            class="primary">
                            設定保存
                        </button>

                    </div>

                </form>

                <!--
                    保存とは完全に別フォーム。
                    接続テストが保存処理へ流れないようにする。
                -->

                <div class="buttons">

                    <!-- 2. 接続テスト -->
                    <form
                        method="post"
                        action="index.php?screen=kintone"
                        class="kintone-action">

                        <input type="hidden"
                               name="csrf"
                               value="<?= h(csrf_token()) ?>">

                        <input type="hidden"
                               name="action"
                               value="test_kintone">

                        <button
                            type="submit"
                            class="success">
                            接続テスト
                        </button>

                    </form>


                    <!-- 3. 項目一覧再取得 -->
                    <form
                        method="post"
                        action="index.php?screen=kintone"
                        class="kintone-action">

                        <input type="hidden"
                               name="csrf"
                               value="<?= h(csrf_token()) ?>">

                        <input type="hidden"
                               name="action"
                               value="refresh_kintone_fields">

                        <button
                            type="submit"
                            class="secondary">
                            項目一覧を再取得
                        </button>

                    </form>


                    <!-- 4. 顧客情報同期 -->
                    <form
                        method="post"
                        action="index.php?screen=kintone"
                        class="kintone-action">

                        <input type="hidden"
                               name="csrf"
                               value="<?= h(csrf_token()) ?>">

                        <input type="hidden"
                               name="action"
                               value="sync_kintone">

                        <button
                            type="submit"
                            class="secondary">
                            顧客情報を同期
                        </button>

                    </form>

                </div>

            </div>

        </main>

        <script>
        document.querySelectorAll('.kintone-action').forEach(function(form) {

            form.addEventListener('submit', function() {

                document.querySelectorAll(
                    'button'
                ).forEach(function(button) {
                    button.disabled = true;
                });

            });

        });
        </script>

        </body>
        </html>
        <?php

        break;


    default:

        /*
         * ここから既存の一覧・編集等を実装。
         */
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <title>アンケート一覧</title>
        </head>
        <body>
            <p>アンケート一覧</p>
            <p>
                <a href="index.php?screen=kintone">
                    kintone連携設定
                </a>
            </p>
        </body>
        </html>
        <?php

        break;
}
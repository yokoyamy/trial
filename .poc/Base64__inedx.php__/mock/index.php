<?php
echo "################### モック #####";
/**
 * Active Directory 部署属性・ユーザー連携確認ツール (デバッグ＆検証強化版)
 * 
 * 特徴:
 * - データベース不要、LDAP連携、1ファイル構成
 * - 検索が0件になる問題を解決するため、画面上からBASE_DNを動的に変更してテスト可能
 * - フィルター条件を自動で最適化し、部署未設定ユーザーも含めて全検出を試みます
 */

// セッション開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -------------------------------------------------------------
// 1. 関数の定義と安全対策
// -------------------------------------------------------------
if (!function_exists('h')) {
    function h(?string $str): string {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('safe_convert')) {
    function safe_convert(?string $str): string {
        if ($str === null || $str === '') {
            return '';
        }
        return mb_convert_encoding($str, 'UTF-8', 'UTF-8,CP932,SJIS,ASCII');
    }
}

// -------------------------------------------------------------
// 2. Active Directory 基本設定（環境に合わせて確認してください）
// -------------------------------------------------------------
define('AD_SERVER', '192.168.72.110');
define('AD_PORT', 389);
define('DOMAIN_SUFFIX', '@jacad'); // ドメインサフィックス

// デフォルトの検索開始位置（うまくいかない場合は画面から変更してテストできます）
$default_base_dn = 'DC=jacad';

// セッションまたはPOSTからBASE_DNを取得
if (isset($_POST['update_base_dn'])) {
    $_SESSION['base_dn'] = $_POST['base_dn_input'] ?? $default_base_dn;
}
$current_base_dn = $_SESSION['base_dn'] ?? $default_base_dn;

// デバッグ用ログ格納変数
$debug_logs = [];
function write_debug(string $message, $data = null) {
    global $debug_logs;
    $log = "[" . date('H:i:s') . "] " . $message;
    if ($data !== null) {
        $log .= " => " . print_r($data, true);
    }
    $debug_logs[] = $log;
}

$error_msg = '';
$success_msg = '';

// -------------------------------------------------------------
// 3. アプリケーション制御（ログイン・ログアウト・検索）
// -------------------------------------------------------------

// ログアウト処理
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: index.php");
    exit;
}

// ログイン認証処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username !== '' && $password !== '') {
        $ldap_user = str_contains($username, '@') ? $username : $username . DOMAIN_SUFFIX;
        write_debug("【ログイン試行】 ユーザー: $ldap_user で接続を開始します。");

        $ldap_conn = @ldap_connect(AD_SERVER, AD_PORT);
        if ($ldap_conn) {
            ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);

            write_debug("ADサーバーにソケット接続しました。バインド（認証）を実行します...");
            if (@ldap_bind($ldap_conn, $ldap_user, $password)) {
                write_debug("バインド成功！セッションを確立します。");
                $_SESSION['authenticated'] = true;
                $_SESSION['username'] = $ldap_user;
                $_SESSION['password'] = $password; 
                
                header("Location: index.php");
                exit;
            } else {
                $err_code = ldap_errno($ldap_conn);
                $err_msg = ldap_error($ldap_conn);
                write_debug("バインド失敗！ エラー番号: $err_code, エラー内容: $err_msg");
                $error_msg = "認証に失敗しました。ID・パスワード、またはAD側の権限を確認してください。 (LDAP Error: $err_msg)";
            }
            @ldap_close($ldap_conn);
        } else {
            write_debug("ADサーバーへの接続自体に失敗しました。");
            $error_msg = "ADサーバーに接続できませんでした。";
        }
    } else {
        $error_msg = "ユーザー名とパスワードを両方入力してください。";
    }
}

// 表示用データ保持変数
$departments = [];
$users_in_dept = [];
$search_results = [];
$selected_dept = $_GET['dept'] ?? '';
$search_query = $_GET['search'] ?? '';

// 認証済みの場合のみAD情報取得処理
if (!empty($_SESSION['authenticated'])) {
    write_debug("【データ取得開始】 セッション：認証済み。ADへの再バインドを試みます。");
    
    $ldap_conn = @ldap_connect(AD_SERVER, AD_PORT);
    if ($ldap_conn) {
        ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);

        $ldap_user = $_SESSION['username'];
        write_debug("接続確立。ユーザー: $ldap_user でバインド中...");

        if (@ldap_bind($ldap_conn, $ldap_user, $_SESSION['password'])) {
            write_debug("再バインド成功。検索対象ドメインルート(BASE_DN): " . $current_base_dn);
            
            // -------------------------------------------------------------
            // ① 部署(department)一覧の取得（※フィルタを極限までシンプルに安全化）
            // -------------------------------------------------------------
            // 人物かつアカウント名がある全ユーザーをいったん対象にして部署を抽出します
            $filter = "(&(objectClass=user)(sAMAccountName=*))";
            $attributes = ["department", "cn"];
            
            write_debug("【全部署・ユーザー簡易スキャン】 フィルター: $filter");
            $search = @ldap_search($ldap_conn, $current_base_dn, $filter, $attributes);
            
            if ($search) {
                $entries = @ldap_get_entries($ldap_conn, $search);
                $count = $entries['count'] ?? 0;
                write_debug("【スキャン結果】 対象のBASE_DN配下から検出された総オブジェクト数: $count 件");
                
                if ($count === 0) {
                    write_debug("⚠️警告: 1件もオブジェクトが取得できませんでした。BASE_DNの設定ルートが間違っているか、このログインユーザーにOU配下の閲覧権限がありません。");
                }

                $dept_list = [];
                $empty_dept_count = 0;
                for ($i = 0; $i < $count; $i++) {
                    $dept_val = $entries[$i]['department'][0] ?? $entries[$i]['DEPARTMENT'][0] ?? null;
                    if ($dept_val !== null && trim($dept_val) !== '') {
                        $dept_name = safe_convert($dept_val);
                        $dept_list[] = $dept_name;
                    } else {
                        $empty_dept_count++;
                    }
                }
                
                write_debug("【内訳】 部署入力あり: " . count($dept_list) . " 件 / 部署空欄(未設定): $empty_dept_count 件");
                
                $departments = array_unique($dept_list);
                sort($departments);
                write_debug("【部署一覧解析完了】 重複排除後の部署数: " . count($departments) . " 件", $departments);
            } else {
                $err_code = ldap_errno($ldap_conn);
                $err_msg = ldap_error($ldap_conn);
                write_debug("【部署一覧取得失敗】 ldap_search自体が失敗。エラー番号: $err_code, エラー内容: $err_msg");
                if ($err_code === 32) {
                    write_debug("⚠️エラー32 (No such object) は、指定したBASE_DNが存在しないことを示しています。");
                }
            }

            // -------------------------------------------------------------
            // ② ユーザー検索（直接入力時）
            // -------------------------------------------------------------
            if ($search_query !== '') {
                write_debug("【ユーザー直接入力検索】 検索ワード: " . $search_query);
                $escaped_query = str_replace(['\\', '*', '(', ')', "\0"], ['\\5c', '\\2a', '\\28', '\\29', '\\00'], $search_query);
                
                // sAMAccountName、displayName、cnを含む緩い部分一致検索
                $search_filter = "(&(objectClass=user)(|(cn=*" . $escaped_query . "*)(sAMAccountName=*" . $escaped_query . "*)(displayName=*" . $escaped_query . "*)))";
                $search_attrs = ["cn", "samaccountname", "department", "title", "mail", "telephonenumber"];
                
                write_debug("【ユーザー検索実行】 フィルター: $search_filter");
                $user_search = @ldap_search($ldap_conn, $current_base_dn, $search_filter, $search_attrs);
                
                if ($user_search) {
                    $user_entries = @ldap_get_entries($ldap_conn, $user_search);
                    $count = $user_entries['count'] ?? 0;
                    write_debug("【ユーザー検索結果】 マッチしたユーザー数: $count 件");

                    for ($i = 0; $i < $count; $i++) {
                        $entry = $user_entries[$i];
                        
                        $cn = $entry['cn'][0] ?? $entry['CN'][0] ?? 'N/A';
                        $sam = $entry['samaccountname'][0] ?? $entry['SAMACCOUNTNAME'][0] ?? 'N/A';
                        $dept = $entry['department'][0] ?? $entry['DEPARTMENT'][0] ?? '（所属部署未設定）';
                        $title = $entry['title'][0] ?? $entry['TITLE'][0] ?? '-';
                        $mail = $entry['mail'][0] ?? $entry['MAIL'][0] ?? '-';
                        $phone = $entry['telephonenumber'][0] ?? $entry['TELEPHONENUMBER'][0] ?? '-';

                        $search_results[] = [
                            'name'   => safe_convert($cn),
                            'samid'  => safe_convert($sam),
                            'dept'   => safe_convert($dept),
                            'title'  => safe_convert($title),
                            'mail'   => safe_convert($mail),
                            'phone'  => safe_convert($phone),
                        ];
                    }
                } else {
                    $err_code = ldap_errno($ldap_conn);
                    $err_msg = ldap_error($ldap_conn);
                    write_debug("【ユーザー検索失敗】 エラー番号: $err_code, エラー内容: $err_msg");
                }
            }

            // -------------------------------------------------------------
            // ③ 部署クリック時の所属メンバー一覧
            // -------------------------------------------------------------
            if ($selected_dept !== '') {
                write_debug("【部署ユーザー一覧】 選択された部署: " . $selected_dept);
                $escaped_dept = str_replace(['\\', '*', '(', ')', "\0"], ['\\5c', '\\2a', '\\28', '\\29', '\\00'], $selected_dept);
                $dept_filter = "(&(objectClass=user)(department=" . $escaped_dept . "))";
                $user_attributes = ["cn", "samaccountname", "mail", "title", "telephonenumber"];
                
                write_debug("【部署ユーザー検索実行】 フィルター: $dept_filter");
                $dept_search = @ldap_search($ldap_conn, $current_base_dn, $dept_filter, $user_attributes);
                
                if ($dept_search) {
                    $user_entries = @ldap_get_entries($ldap_conn, $dept_search);
                    $count = $user_entries['count'] ?? 0;
                    write_debug("【部署ユーザー結果】 所属人数: $count 名");

                    for ($i = 0; $i < $count; $i++) {
                        $entry = $user_entries[$i];

                        $cn = $entry['cn'][0] ?? $entry['CN'][0] ?? 'N/A';
                        $sam = $entry['samaccountname'][0] ?? $entry['SAMACCOUNTNAME'][0] ?? 'N/A';
                        $mail = $entry['mail'][0] ?? $entry['MAIL'][0] ?? '-';
                        $title = $entry['title'][0] ?? $entry['TITLE'][0] ?? '-';
                        $phone = $entry['telephonenumber'][0] ?? $entry['TELEPHONENUMBER'][0] ?? '-';

                        $users_in_dept[] = [
                            'name'   => safe_convert($cn),
                            'samid'  => safe_convert($sam),
                            'mail'   => safe_convert($mail),
                            'title'  => safe_convert($title),
                            'phone'  => safe_convert($phone),
                        ];
                    }
                } else {
                    $err_code = ldap_errno($ldap_conn);
                    $err_msg = ldap_error($ldap_conn);
                    write_debug("【部署ユーザー検索失敗】 エラー番号: $err_code, エラー内容: $err_msg");
                }
            }

        } else {
            $err_code = ldap_errno($ldap_conn);
            $err_msg = ldap_error($ldap_conn);
            write_debug("【再バインド失敗】 エラー番号: $err_code, エラー内容: $err_msg");
            $error_msg = "ADとの認証セッションが切れました。再度ログインしてください。(LDAP Error: $err_msg)";
            $_SESSION = [];
        }
        @ldap_close($ldap_conn);
    } else {
        write_debug("ADサーバーへの接続に失敗しました。");
        $error_msg = "ADサーバーへの再接続に失敗しました。";
    }
}

// 表示用ログインユーザー名の整形
$display_username = '';
if (!empty($_SESSION['username'])) {
    $display_username = str_contains($_SESSION['username'], '@') ? explode('@', $_SESSION['username'])[0] : $_SESSION['username'];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AD 部署情報・属性確認ツール (改良デバッグ版)</title>
    <style>
        :root {
            --bg-color: #f7fafc;
            --card-bg: #ffffff;
            --text-color: #2d3748;
            --border-color: #e2e8f0;
            --primary-color: #3182ce;
            --primary-hover: #2b6cb0;
            --error-color: #e53e3e;
            --success-color: #38a169;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.5;
            padding: 24px;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
        }

        .debug-panel {
            background-color: #1a202c;
            color: #cbd5e0;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-family: Menlo, Monaco, Consolas, "Courier New", monospace;
            font-size: 0.85rem;
            max-height: 320px;
            overflow-y: auto;
            border: 2px solid #3182ce;
        }

        .debug-panel h4 {
            color: #63b3ed;
            margin-bottom: 10px;
            font-size: 0.95rem;
            border-bottom: 1px solid #4a5568;
            padding-bottom: 5px;
        }

        .debug-log-line {
            margin-bottom: 4px;
            border-bottom: 1px dashed #2d3748;
            padding-bottom: 4px;
            word-break: break-all;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }

        h1 {
            font-size: 1.4rem;
            color: var(--primary-color);
        }

        .btn {
            display: inline-block;
            background-color: var(--primary-color);
            color: #ffffff;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: background-color 0.15s ease;
        }

        .btn:hover {
            background-color: var(--primary-hover);
        }

        .btn-gray {
            background-color: #718096;
        }

        .btn-gray:hover {
            background-color: #4a5568;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 24px;
            font-size: 0.95rem;
        }

        .alert-danger {
            background-color: #fed7d7;
            color: var(--error-color);
            border: 1px solid #feb2b2;
        }

        .login-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 50vh;
        }

        .login-card {
            background: var(--card-bg);
            padding: 32px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            width: 100%;
            max-width: 420px;
        }

        .login-card h2 {
            margin-bottom: 24px;
            font-size: 1.3rem;
            text-align: center;
            color: #4a5568;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #4a5568;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 1rem;
            color: var(--text-color);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.15);
        }

        /* BASE_DN設定クイックパネル */
        .dn-config-panel {
            background-color: #ebf8ff;
            border: 1px solid #bee3f8;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
        }

        .dn-config-panel form {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        .app-layout {
            display: grid;
            grid-template-columns: 310px 1fr;
            gap: 24px;
        }

        @media (max-width: 768px) {
            .app-layout {
                grid-template-columns: 1fr;
            }
        }

        .sidebar, .main-content {
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            padding: 24px;
        }

        .sidebar {
            height: fit-content;
        }

        .section-title {
            font-size: 1.05rem;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--bg-color);
            color: #4a5568;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .search-container {
            background: #f7fafc;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }

        .search-form {
            display: flex;
            gap: 12px;
        }

        .search-form input {
            flex-grow: 1;
        }

        .dept-list {
            list-style: none;
            max-height: 520px;
            overflow-y: auto;
        }

        .dept-link {
            display: block;
            padding: 8px 12px;
            border-radius: 6px;
            color: var(--text-color);
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 4px;
            transition: all 0.1s ease;
        }

        .dept-link:hover {
            background-color: #edf2f7;
            color: var(--primary-color);
        }

        .dept-link.active {
            background-color: #ebf8ff;
            color: var(--primary-color);
            font-weight: 600;
            border-left: 4px solid var(--primary-color);
            border-radius: 0 6px 6px 0;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            margin-bottom: 24px;
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }

        .user-table th, .user-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .user-table th {
            background-color: #f7fafc;
            color: #4a5568;
            font-weight: 600;
        }

        .user-table tr:hover {
            background-color: #f8fafc;
        }

        .no-data {
            text-align: center;
            color: #a0aec0;
            padding: 48px 0;
            font-size: 0.95rem;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 12px;
            background-color: #edf2f7;
            color: #4a5568;
        }
    </style>
</head>
<body>

<div class="container">

    <!-- リアルタイムデバッグログパネル -->
    <div class="debug-panel">
        <h4>【LDAP接続・デバッグ情報ログ】</h4>
        <?php if (empty($debug_logs)): ?>
            <div style="color: #a0aec0;">現在実行されたLDAP処理はありません。ログインまたは設定情報の送信を行ってください。</div>
        <?php else: ?>
            <?php foreach ($debug_logs as $log): ?>
                <div class="debug-log-line"><?= h($log) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (empty($_SESSION['authenticated'])): ?>
        <!-- ログインフォーム -->
        <div class="login-wrapper">
            <div class="login-card">
                <h2>AD 部署・属性検索ログイン</h2>
                
                <?php if ($error_msg !== ''): ?>
                    <div class="alert alert-danger"><?= h($error_msg) ?></div>
                <?php endif; ?>

                <form method="POST" action="index.php">
                    <div class="form-group">
                        <label for="username">ADユーザーID（管理者アカウント推奨）</label>
                        <input type="text" id="username" name="username" class="form-control" placeholder="例: administrator" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="password">パスワード</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" name="login" class="btn" style="width: 100%;">ログインして状況を確認する</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- メイン機能画面 -->
        <header>
            <div>
                <h1>AD 部署・属性検索確認ツール</h1>
                <small style="color: #718096;">ログインユーザーID: <strong><?= h($display_username) ?></strong></small>
            </div>
            <a href="index.php?action=logout" class="btn btn-gray">ログアウト</a>
        </header>

        <!-- クイックBASE_DNテストパネル -->
        <div class="dn-config-panel">
            <form method="POST" action="index.php">
                <div style="flex-grow: 1;">
                    <label style="display: block; font-size: 0.85rem; font-weight: bold; margin-bottom: 6px; color: #2c5282;">
                        🔍 現在の検索ルート（BASE_DN）の設定を変更してテスト
                    </label>
                    <input type="text" name="base_dn_input" class="form-control" value="<?= h($current_base_dn) ?>" style="background: #ffffff;" placeholder="例: CN=Users,DC=domain,DC=local">
                </div>
                <button type="submit" name="update_base_dn" class="btn" style="background-color: #2b6cb0;">ルート変更を適用</button>
            </form>
        </div>

        <?php if ($error_msg !== ''): ?>
            <div class="alert alert-danger"><?= h($error_msg) ?></div>
        <?php endif; ?>

        <div class="app-layout">
            <!-- 左サイドバー: 部署一覧 -->
            <aside class="sidebar">
                <div class="section-title">
                    <span>部署一覧</span>
                    <span class="badge" style="background-color: #e2e8f0;"><?= count($departments) ?></span>
                </div>
                <?php if (empty($departments)): ?>
                    <div class="no-data" style="padding: 16px 0;">
                        <p style="font-size: 0.85rem; color: #e53e3e; font-weight: bold;">部署属性（department）を持つユーザーが見つかりません。</p>
                        <p style="font-size: 0.8rem; margin-top: 5px; color: #718096;">
                            ※上の「ルート変更を適用」で、<code>CN=Users,DC=domain,DC=local</code> などを設定してみてください。
                        </p>
                    </div>
                <?php else: ?>
                    <ul class="dept-list">
                        <?php foreach ($departments as $dept): ?>
                            <li>
                                <a href="index.php?dept=<?= urlencode($dept) ?>" 
                                   class="dept-link <?= $selected_dept === $dept ? 'active' : '' ?>">
                                    <?= h($dept) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </aside>

            <!-- メインコンテンツ領域 -->
            <main class="main-content">
                <!-- ユーザー属性直接検索フォーム -->
                <div class="search-container">
                    <h3 style="font-size: 0.95rem; margin-bottom: 10px; color: #4a5568;">ユーザー検索 (部分一致でADから直接属性を抽出)</h3>
                    <form method="GET" action="index.php" class="search-form">
                        <input type="text" name="search" class="form-control" placeholder="ユーザーID(sAMAccountName)・氏名など..." value="<?= h($search_query) ?>">
                        <button type="submit" class="btn">属性検索</button>
                        <?php if ($search_query !== '' || $selected_dept !== ''): ?>
                            <a href="index.php" class="btn btn-gray">クリア</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- 検索結果テーブルの表示 -->
                <?php if ($search_query !== ''): ?>
                    <h2 class="section-title">「<?= h($search_query) ?>」のAD属性検索結果 (<?= count($search_results) ?>件)</h2>
                    <div class="table-responsive">
                        <?php if (empty($search_results)): ?>
                            <p class="no-data">検索キーワードに合致するユーザー属性情報は見つかりませんでした。</p>
                        <?php else: ?>
                            <table class="user-table">
                                <thead>
                                    <tr>
                                        <th>氏名</th>
                                        <th>ユーザーID</th>
                                        <th>所属部署 (department)</th>
                                        <th>役職 (title)</th>
                                        <th>メール (mail)</th>
                                        <th>内線・電話</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($search_results as $user): ?>
                                        <tr style="<?= strtolower($user['samid']) === strtolower($display_username) ? 'background-color: #fffaf0; border-left: 4px solid #f6ad55;' : '' ?>">
                                            <td>
                                                <strong><?= h($user['name']) ?></strong>
                                                <?php if (strtolower($user['samid']) === strtolower($display_username)): ?>
                                                    <span class="badge" style="background-color: #feebc8; color: #c05621; margin-left: 6px;">あなた</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?= h($user['samid']) ?></code></td>
                                            <td><span class="badge" style="background-color: #ebf8ff; color: #2b6cb0;"><?= h($user['dept']) ?></span></td>
                                            <td><?= h($user['title']) ?></td>
                                            <td><?= h($user['mail']) ?></td>
                                            <td><?= h($user['phone']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- 部署選択時の所属一覧テーブルの表示 -->
                <?php if ($selected_dept !== ''): ?>
                    <h2 class="section-title">部署: <?= h($selected_dept) ?> の所属ユーザー (<?= count($users_in_dept) ?>名)</h2>
                    <div class="table-responsive">
                        <?php if (empty($users_in_dept)): ?>
                            <p class="no-data">この部署に所属しているアクティブなユーザーは見つかりませんでした。</p>
                        <?php else: ?>
                            <table class="user-table">
                                <thead>
                                    <tr>
                                        <th>氏名</th>
                                        <th>ユーザーID</th>
                                        <th>役職 (title)</th>
                                        <th>メールアドレス (mail)</th>
                                        <th>内線・代表</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users_in_dept as $user): ?>
                                        <tr style="<?= strtolower($user['samid']) === strtolower($display_username) ? 'background-color: #fffaf0; border-left: 4px solid #f6ad55;' : '' ?>">
                                            <td>
                                                <strong><?= h($user['name']) ?></strong>
                                                <?php if (strtolower($user['samid']) === strtolower($display_username)): ?>
                                                    <span class="badge" style="background-color: #feebc8; color: #c05621; margin-left: 6px;">あなた</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?= h($user['samid']) ?></code></td>
                                            <td><?= h($user['title']) ?></td>
                                            <td><?= h($user['mail']) ?></td>
                                            <td><?= h($user['phone']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- 操作開始前のガイダンス表示 -->
                <?php if ($selected_dept === '' && $search_query === ''): ?>
                    <div class="no-data">
                        <p style="font-size: 1.05rem; margin-bottom: 12px; color: #4a5568;">AD情報の検索を開始してください。</p>
                        <p style="font-size: 0.85rem; color: #718096; margin-bottom: 6px;">💡 左カラムに部署が表示されない場合は、画面上の「検索ルート（BASE_DN）」の値を調整して適用してください。</p>
                        <p style="font-size: 0.85rem; color: #718096;">💡 <code>CN=Users,DC=domain,DC=local</code> や、特定の <code>OU=組織名,DC=domain,DC=local</code> を指定することで状況が変わる場合があります。</p>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
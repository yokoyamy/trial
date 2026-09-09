<?php
// ==========================================================
// 1. ディレクトリ基本設定（修正）
// ==========================================================
// ==========================================================
// 1. ディレクトリ基本設定
// ==========================================================
namespace gojacicManager;

// 画面へのエラー出力を完全にOFFにする（JSON破壊防止）
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 出力バッファリング開始
if (!ob_get_level()) {
    ob_start();
}

$document_root = realpath(__DIR__);
$base_dir      = $document_root . DIRECTORY_SEPARATOR . 'gojacic';

// === 修正箇所 ===
$doc_clean     = str_replace('\\', '/', $document_root);
$base_clean    = str_replace('\\', '/', $base_dir);
$rel_path      = trim(str_replace($doc_clean, '', $base_clean), '/');
$web_base_path = ($rel_path === '') ? '/' : '/' . $rel_path . '/';
// ==============

// .poc 関連（全アプリの親ディレクトリ）
$poc_dir       = $base_dir . DIRECTORY_SEPARATOR . '.poc';

// 公開用ディレクトリ（.poc の外）
$published_dir = $base_dir . DIRECTORY_SEPARATOR . 'published';

// 前々世代・旧グローバル管理ディレクトリ（$base_dir 直下）
$old_history_dir = $base_dir . DIRECTORY_SEPARATOR . '.history';
$old_prompt_dir  = $base_dir . DIRECTORY_SEPARATOR . '.prompt';
$old_harness_dir = $base_dir . DIRECTORY_SEPARATOR . '.harness';
$old_github_dir  = $base_dir . DIRECTORY_SEPARATOR . '.github';

// 前世代・旧共有管理ディレクトリ（.poc 直下）
$poc_history_dir = $poc_dir . DIRECTORY_SEPARATOR . '.history';
$poc_prompt_dir  = $poc_dir . DIRECTORY_SEPARATOR . '.prompt';
$poc_harness_dir = $poc_dir . DIRECTORY_SEPARATOR . '.harness';

// ==========================================================
// 2. ディレクトリパス取得ヘルパー関数
// ==========================================================
function get_app_dirs($appName, $poc_dir, $published_dir) {
    $app_root = $poc_dir . DIRECTORY_SEPARATOR . $appName;
    return [
        'root'          => $app_root,
        'draft'         => $app_root . DIRECTORY_SEPARATOR . 'draft',
        'mock'          => $app_root . DIRECTORY_SEPARATOR . 'mock',
        'spec'          => $app_root . DIRECTORY_SEPARATOR . 'spec',
        'published'     => $published_dir . DIRECTORY_SEPARATOR . $appName,
        'history'       => $app_root . DIRECTORY_SEPARATOR . '.history',
        'prompt'        => $app_root . DIRECTORY_SEPARATOR . '.prompt',
        'harness'       => $app_root . DIRECTORY_SEPARATOR . '.harness',
        'github'        => $app_root . DIRECTORY_SEPARATOR . '.github',
        'github_config' => $app_root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'config.json',
    ];
}

// 基本親ディレクトリの自動作成
$base_dirs = [$base_dir, $poc_dir, $published_dir];
foreach ($base_dirs as $d) {
    if (!is_dir($d)) {
        if (!mkdir($d, 0755, true) && !is_dir($d)) {
            error_log('ディレクトリ作成失敗: ' . $d);
        }
    }
}

// ==========================================================
// 3. 全世代対応・自動マイグレーション処理
// ==========================================================
$enable_migration = false; // 💡 移行・構造補正を実行する時のみ true に変更してください

if ($enable_migration && is_dir($poc_dir)) {

    // ------------------------------------------------------
    // [世代A] 前世代の移行: .poc/draft/アプリ名 & .poc/mock/アプリ名 ➔ .poc/アプリ名/draft & mock
    // ------------------------------------------------------
    $old_draft_parent = $poc_dir . DIRECTORY_SEPARATOR . 'draft';
    if (is_dir($old_draft_parent)) {
        foreach (scandir($old_draft_parent) as $app_name) {
            if ($app_name === '.' || $app_name === '..') continue;
            $src = $old_draft_parent . DIRECTORY_SEPARATOR . $app_name;
            if (!is_dir($src)) continue;

            $new_app_dir = $poc_dir . DIRECTORY_SEPARATOR . $app_name;
            $dst_draft   = $new_app_dir . DIRECTORY_SEPARATOR . 'draft';

            if (!file_exists($new_app_dir)) mkdir($new_app_dir, 0755, true);
            if (!file_exists($dst_draft)) {
                if (!rename($src, $dst_draft)) error_log("世代A draft移行失敗: {$src} -> {$dst_draft}");
            }
        }
        @rmdir($old_draft_parent);
    }

    $old_mock_parent = $poc_dir . DIRECTORY_SEPARATOR . 'mock';
    if (is_dir($old_mock_parent)) {
        foreach (scandir($old_mock_parent) as $app_name) {
            if ($app_name === '.' || $app_name === '..') continue;
            $src = $old_mock_parent . DIRECTORY_SEPARATOR . $app_name;
            if (!is_dir($src)) continue;

            $new_app_dir = $poc_dir . DIRECTORY_SEPARATOR . $app_name;
            $dst_mock    = $new_app_dir . DIRECTORY_SEPARATOR . 'mock';

            if (!file_exists($new_app_dir)) mkdir($new_app_dir, 0755, true);
            if (!file_exists($dst_mock)) {
                if (!rename($src, $dst_mock)) error_log("世代A mock移行失敗: {$src} -> {$dst_mock}");
            }
        }
        @rmdir($old_mock_parent);
    }

    // ------------------------------------------------------
    // [世代B] 前々世代の移行: .poc/アプリ名(直下にファイル) ➔ .poc/アプリ名/draft
    // ------------------------------------------------------
    foreach (scandir($poc_dir) as $item) {
        if ($item === '.' || $item === '..' || $item === 'draft' || $item === 'mock' || strpos($item, '.') === 0) continue;
        $app_path = $poc_dir . DIRECTORY_SEPARATOR . $item;
        if (!is_dir($app_path)) continue;

        $target_draft = $app_path . DIRECTORY_SEPARATOR . 'draft';
        $target_mock  = $app_path . DIRECTORY_SEPARATOR . 'mock';

        if (is_dir($target_draft) || is_dir($target_mock)) continue;

        $tmp_path = $poc_dir . DIRECTORY_SEPARATOR . '_tmp_' . $item . '_' . time();
        if (rename($app_path, $tmp_path)) {
            mkdir($app_path, 0755, true);
            if (!rename($tmp_path, $target_draft)) {
                error_log("世代B移行失敗: {$tmp_path} -> {$target_draft}");
            }
        }
    }

    // ------------------------------------------------------
    // [世代C] 前々世代グローバル管理フォルダの移行 ($base_dir 直下 ➔ .poc/アプリ名/)
    // ------------------------------------------------------
    $migrate_targets_old = [
        '.history' => $old_history_dir,
        '.prompt'  => $old_prompt_dir,
        '.harness' => $old_harness_dir
    ];
    foreach ($migrate_targets_old as $folder_name => $old_parent_dir) {
        if (is_dir($old_parent_dir)) {
            foreach (scandir($old_parent_dir) as $app_name) {
                if ($app_name === '.' || $app_name === '..') continue;
                $src = $old_parent_dir . DIRECTORY_SEPARATOR . $app_name;
                if (!is_dir($src)) continue;

                $dst_app_dir = $poc_dir . DIRECTORY_SEPARATOR . $app_name;
                $dst_target  = $dst_app_dir . DIRECTORY_SEPARATOR . $folder_name;

                if (!file_exists($dst_app_dir)) mkdir($dst_app_dir, 0755, true);
                if (!file_exists($dst_target)) {
                    if (!rename($src, $dst_target)) error_log("前々世代管理フォルダ移行失敗: {$src} -> {$dst_target}");
                }
            }
            @rmdir($old_parent_dir);
        }
    }

    // .github 設定の移行 ($base_dir/.github ➔ .poc/アプリ名/.github)
    if (is_dir($old_github_dir)) {
        foreach (scandir($old_github_dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $src = $old_github_dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($src)) {
                $dst_app_dir = $poc_dir . DIRECTORY_SEPARATOR . $item;
                $dst_github  = $dst_app_dir . DIRECTORY_SEPARATOR . '.github';
                if (!file_exists($dst_app_dir)) mkdir($dst_app_dir, 0755, true);
                if (!file_exists($dst_github)) @rename($src, $dst_github);
            }
        }
        @rmdir($old_github_dir);
    }

    // ------------------------------------------------------
    // [世代D] 前世代共有管理フォルダの移行 (.poc/.history, .poc/.prompt 内の個別ファイル分配)
    // ------------------------------------------------------
    $poc_shared_targets = [
        '.history' => 'history',
        '.prompt'  => 'prompt',
        '.harness' => 'harness'
    ];
    // 現在認識されているアプリ一覧を取得
    $known_apps = [];
    foreach (scandir($poc_dir) as $entry) {
        if ($entry === '.' || $entry === '..' || strpos($entry, '.') === 0) continue;
        if (is_dir($poc_dir . DIRECTORY_SEPARATOR . $entry)) {
            $known_apps[] = $entry;
        }
    }

    foreach ($poc_shared_targets as $folder_name => $dir_key) {
        $shared_dir = $poc_dir . DIRECTORY_SEPARATOR . $folder_name;
        if (is_dir($shared_dir)) {
            foreach (scandir($shared_dir) as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $src_item = $shared_dir . DIRECTORY_SEPARATOR . $entry;

                if (is_dir($src_item)) {
                    // アプリ名のディレクトリになっている場合
                    $dst_app_dir = $poc_dir . DIRECTORY_SEPARATOR . $entry;
                    $dst_target  = $dst_app_dir . DIRECTORY_SEPARATOR . $folder_name;
                    if (!file_exists($dst_app_dir)) mkdir($dst_app_dir, 0755, true);
                    if (!file_exists($dst_target)) @rename($src_item, $dst_target);
                } elseif (is_file($src_item)) {
                    // ディレクトリがなくファイルが直置きの場合、ファイル名からアプリを推定して移動
                    foreach ($known_apps as $aname) {
                        if (strpos($entry, $aname) !== false) {
                            $app_dirs   = get_app_dirs($aname, $poc_dir, $published_dir);
                            $dst_target = $app_dirs[$dir_key] . DIRECTORY_SEPARATOR . $entry;
                            if (!is_dir($app_dirs[$dir_key])) @mkdir($app_dirs[$dir_key], 0755, true);
                            @rename($src_item, $dst_target);
                            break;
                        }
                    }
                }
            }
            @rmdir($shared_dir);
        }
    }

    // ------------------------------------------------------
    // [最終補正] 新仕様フォルダ（spec等）と初期ファイルの完全補完
    // ------------------------------------------------------
    foreach ($known_apps as $aname) {
        $app_dirs = get_app_dirs($aname, $poc_dir, $published_dir);

        // フォルダ作成
        foreach (['root', 'draft', 'mock', 'spec', 'history', 'prompt'] as $key) {
            if (!is_dir($app_dirs[$key])) {
                @mkdir($app_dirs[$key], 0755, true);
            }
        }

        // 不足している初期ファイルの作成
        $initial_files = [
            $app_dirs['spec'] . DIRECTORY_SEPARATOR . 'common.md' => "# 要件定義 (Common)\n\n",
            $app_dirs['spec'] . DIRECTORY_SEPARATOR . 'dev.md'    => "# 実装要件 (Dev)\n\n",
            $app_dirs['mock'] . DIRECTORY_SEPARATOR . 'index.php' => "<!-- Mock Screen -->\n",
            $app_dirs['draft'] . DIRECTORY_SEPARATOR . 'index.php'=> "<?php\n// Dev Main\n"
        ];

        foreach ($initial_files as $filepath => $initial_content) {
            if (!file_exists($filepath)) {
                @file_put_contents($filepath, $initial_content);
            }
        }
    }
}
// ==========================================================
// 5. API エンドポイント処理（リクエスト受付・読込・保存・ツリー取得）
// ==========================================================
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);
if (!$data) $data = $_POST;

// 💡 JS側の ?api= と ?action= の両方を受け取れるように統合
$action       = $data['api'] ?? $_GET['api'] ?? $data['action'] ?? $_GET['action'] ?? '';
$raw_app_name = $data['app_name'] ?? $_GET['app_name'] ?? '';
$target_file  = $data['target_file'] ?? $_GET['target_file'] ?? '';

// =========================================================================
// ヘルパー関数群（再帰コピー・ディレクトリ削除・ツリー走査）
// =========================================================================

function rcopy($src, $dst) {
    if (is_dir($src)) {
        if (!file_exists($dst)) mkdir($dst, 0777, true);
        $files = scandir($src);
        foreach ($files as $file) {
            if ($file != "." && $file != "..") {
                rcopy("$src/$file", "$dst/$file");
            }
        }
    } else if (file_exists($src)) {
        copy($src, $dst);
    }
}

function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . DIRECTORY_SEPARATOR . $object)) {
                    rrmdir($dir . DIRECTORY_SEPARATOR . $object);
                } else {
                    unlink($dir . DIRECTORY_SEPARATOR . $object);
                }
            }
        }
        rmdir($dir);
    }
}
// ----------------------------------------------------------
// A. ツリー取得 API (get_tree)
// ----------------------------------------------------------
if ($action === 'get_tree') {
    while (ob_get_level()) { ob_end_clean(); } // 混入したHTMLやWarningを完全消去
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $poc_tree       = (isset($poc_dir) && is_dir($poc_dir)) ? scan_tree($poc_dir, '.poc') : [];
        $published_tree = (isset($published_dir) && is_dir($published_dir)) ? scan_tree($published_dir, 'published') : [];

        echo json_encode([
            'success'   => true,
            'poc'       => $poc_tree,
            'published' => $published_tree
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ----------------------------------------------------------
// B. PoCファイル 読込・保存 API
// ----------------------------------------------------------
// アプリ名が指定されていれば安全に抽出
$clean_app_name = '';
if (!empty($raw_app_name)) {
    $clean_app_name = trim(str_replace(['.poc/', '.poc\\', 'published/', 'published\\'], '', $raw_app_name), '/\\');
    // サブディレクトリ階層があっても最上位のアプリ名を抽出
    $parts = explode('/', str_replace('\\', '/', $clean_app_name));
    $clean_app_name = $parts[0] ?? '';
}

if (in_array($action, ['load_poc_file', 'save_poc_file'], true)) {
    while (ob_get_level()) { ob_end_clean(); } // バッファクリア
    header('Content-Type: application/json; charset=UTF-8');

    // アプリ名が未指定・不正な場合のガード
    if (empty($clean_app_name) || preg_match('/[^a-zA-Z0-9_\-]/', $clean_app_name)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error'   => 'アプリが選択されていないか、不正なアプリ名です: [' . $clean_app_name . ']'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $app_dirs = get_app_dirs($clean_app_name, $poc_dir, $published_dir);

    // 許可するファイルのマッピング
    $file_map = [
        'spec/common.md' => $app_dirs['spec'] . DIRECTORY_SEPARATOR . 'common.md',
        'spec/dev.md'    => $app_dirs['spec'] . DIRECTORY_SEPARATOR . 'dev.md',
        'mock/index.php' => $app_dirs['mock'] . DIRECTORY_SEPARATOR . 'index.php',
        'dev/index.php'  => $app_dirs['draft'] . DIRECTORY_SEPARATOR . 'index.php'
    ];

    if (!isset($file_map[$target_file])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '指定されたファイル種別が無効です: ' . $target_file], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $filepath = $file_map[$target_file];

    // ファイル読込
    if ($action === 'load_poc_file') {
        if (!file_exists($filepath)) {
            // 親フォルダがなければ作成
            $target_dir = dirname($filepath);
            if (!is_dir($target_dir)) @mkdir($target_dir, 0755, true);
            
            // 空ファイルとして新規作成
            @file_put_contents($filepath, '');
            echo json_encode(['success' => true, 'content' => ''], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $content = file_get_contents($filepath);
        if ($content === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'ファイルの読み込みに失敗しました。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['success' => true, 'content' => $content], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ファイル保存（自動バックアップ付き）
    if ($action === 'save_poc_file') {
        $content = $data['content'] ?? '';
        $target_dir = dirname($filepath);

        if (!is_dir($target_dir)) {
            if (!mkdir($target_dir, 0755, true) && !is_dir($target_dir)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => '保存先ディレクトリの作成に失敗しました。'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // バックアップ保存
        if (file_exists($filepath)) {
            $history_dir = $app_dirs['history'];
            if (!is_dir($history_dir)) @mkdir($history_dir, 0755, true);
            if (is_dir($history_dir)) {
                $backup_filename = date('Ymd_His') . '_' . str_replace(['/', '\\'], '_', $target_file);
                @copy($filepath, $history_dir . DIRECTORY_SEPARATOR . $backup_filename);
            }
        }

        $result = file_put_contents($filepath, $content);
        if ($result !== false) {
            echo json_encode(['success' => true, 'message' => "{$target_file} を保存しました。"], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => '書き込みに失敗しました。パーミッションを確認してください。'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}
function scan_tree($dir, $relative_prefix = '') {
    $result = [];
    if (!is_dir($dir) || !is_readable($dir)) return $result;
    
    // グローバル変数を安全に取得
    $pub_dir = $GLOBALS['published_dir'] ?? null;
    
    $items = @scandir($dir);
    if ($items === false) return $result;

    foreach ($items as $item) {
        // ドットファイルや除外対象をスキップ
        if ($item === '.' || $item === '..' || $item === '.git') continue;
        
        $full_path = $dir . DIRECTORY_SEPARATOR . $item;
        $rel_path  = ($relative_prefix !== '') ? $relative_prefix . '/' . $item : $item;
        
        if (is_dir($full_path)) {
            $root_index_path  = $full_path . DIRECTORY_SEPARATOR . 'index.php';
            $draft_index_path = $full_path . DIRECTORY_SEPARATOR . 'draft' . DIRECTORY_SEPARATOR . 'index.php';
            $mock_index_path  = $full_path . DIRECTORY_SEPARATOR . 'mock' . DIRECTORY_SEPARATOR . 'index.php';

            $has_root_index  = file_exists($root_index_path);
            $has_draft_index = file_exists($draft_index_path);
            $has_mock_index  = file_exists($mock_index_path);

            $is_app = ($has_root_index || $has_draft_index || $has_mock_index);

            // 子ディレクトリを再帰スキャン
            $children = scan_tree($full_path, $rel_path);

            // 公開中かどうかの判定
            $is_published = (strpos($rel_path, 'published') === 0) || 
                            (!empty($pub_dir) && file_exists($pub_dir . DIRECTORY_SEPARATOR . $item . DIRECTORY_SEPARATOR . 'index.php'));

            // ノード種別の決定
            $type = 'folder';
            $stage = null; // 'mock', 'draft', 'root'

            if ($is_app) {
                $type = 'app';

                // 実質的なコードが存在するか判定（マイグレーション初期ファイルを無視）
                $is_real_draft = false;
                if ($has_draft_index) {
                    $draft_content = trim(@file_get_contents($draft_index_path) ?: '');
                    if ($draft_content !== '' && $draft_content !== "<?php\n// Dev Main" && $draft_content !== "<?php // Dev Main" && $draft_content !== '<?php') {
                        $is_real_draft = true;
                    }
                }

                $is_real_mock = false;
                if ($has_mock_index) {
                    $mock_content = trim(@file_get_contents($mock_index_path) ?: '');
                    if ($mock_content !== '' && $mock_content !== "<!-- Mock Screen -->") {
                        $is_real_mock = true;
                    }
                }

                // 現在のフェーズ判定
                if ($is_real_draft) {
                    $stage = 'draft';
                } elseif ($is_real_mock) {
                    $stage = 'mock';
                } elseif ($has_draft_index && !$has_mock_index) {
                    // 古い世代のアプリ（draftのみ存在）
                    $stage = 'draft';
                } else {
                    // 両方空、またはモック新規作成直後
                    $stage = $has_mock_index ? 'mock' : ($has_draft_index ? 'draft' : 'root');
                }
            } elseif (empty($children)) {
                $type = 'data-folder';
            }

            $node = [
                'name'      => $item,
                'path'      => $rel_path,
                'type'      => $type,
                'stage'     => $stage,       // 'mock' または 'draft'
                'status'    => $stage,       // 互換性のため status にも設定
                'published' => $is_published,
                'children'  => $children
            ];
            
            $result[] = $node;
        }
    }
    
    // 名前順でソート
    usort($result, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    
    return $result;
}
// ==========================================================
// GitHub共通HTTP関数
//
// Git / cURL は使用しない。
// PHP標準の file_get_contents() のみ使用。
// ==========================================================



function github_http_request(
    string $method,
    string $url,
    array $headers = [],
    ?string $body = null,
    bool $proxyEnabled = false,
    string $proxyHost = '',
    int $proxyPort = 0,
    string $proxyUsername = '',
    string $proxyPassword = ''
): array {

    // ------------------------------------------------------
    // ヘッダー
    // ------------------------------------------------------
    $headerLines = $headers;

    // ------------------------------------------------------
    // プロキシ認証
    // ------------------------------------------------------
    if (
        $proxyEnabled &&
        (
            $proxyUsername !== '' ||
            $proxyPassword !== ''
        )
    ) {
        $proxyAuth = base64_encode($proxyUsername . ':' . $proxyPassword);
        $headerLines[] = 'Proxy-Authorization: Basic ' . $proxyAuth;
    }

    // ------------------------------------------------------
    // HTTPコンテキスト
    // ------------------------------------------------------
    $httpOptions = [
        'method'        => $method,
        'header'        => implode("\r\n", $headerLines),
        'timeout'       => 30,
        'ignore_errors' => true
    ];

    // ------------------------------------------------------
    // POST / PUT等のボディ
    // ------------------------------------------------------
    if ($body !== null) {
        $httpOptions['content'] = $body;
    }

    // ------------------------------------------------------
    // プロキシ
    // ------------------------------------------------------
    if ($proxyEnabled) {
        $httpOptions['proxy'] = 'tcp://' . $proxyHost . ':' . $proxyPort;
    }

    // ------------------------------------------------------
    // SSL設定
    // ------------------------------------------------------
    $sslOptions = [
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'allow_self_signed' => false
    ];

    // ------------------------------------------------------
    // PHPのCA設定を取得
    // ------------------------------------------------------
    $caFile = ini_get('openssl.cafile');
    if (
        is_string($caFile) &&
        trim($caFile) !== '' &&
        is_file($caFile)
    ) {
        $sslOptions['cafile'] = $caFile;
    }

    // ------------------------------------------------------
    // コンテキスト作成
    // ------------------------------------------------------
    $context = stream_context_create([
        'http' => $httpOptions,
        'ssl'  => $sslOptions
    ]);

    // ------------------------------------------------------
    // HTTP実行
    // ------------------------------------------------------
    $response = @file_get_contents($url, false, $context);

// ------------------------------------------------------
    // レスポンスヘッダー取得 (PHP 7.x ～ 8.4+ 完全互換・非推奨警告回避)
    // ------------------------------------------------------
    $responseHeaders = [];

    if (function_exists('http_get_last_response_headers')) {
        // PHP 8.4+ (推奨関数)
        $res = http_get_last_response_headers();
        if (is_array($res)) {
            $responseHeaders = $res;
        }
    } else {
        // PHP 8.3以前
        // @エラー抑制演算子と global/ローカル変数参照で警告・Noticeを完全回避
        $res = @$GLOBALS['http_response_header'] ?? ($http_response_header ?? null);
        if (is_array($res)) {
            $responseHeaders = $res;
        }
    }
    // ------------------------------------------------------
    // HTTPステータス取得
    // ------------------------------------------------------
    $httpCode = 0;
    foreach ($responseHeaders as $headerLine) {
        if (preg_match('#HTTP/[0-9\.]+\s+([0-9]+)#i', $headerLine, $matches)) {
            $httpCode = (int)$matches[1];
        }
    }

    // ------------------------------------------------------
    // 通信成否判定 (200〜399番台を成功とみなす)
    // ------------------------------------------------------
    if ($response === false || ($httpCode >= 400 || $httpCode === 0)) {
        return [
            'success'   => false,
            'http_code' => $httpCode,
            'body'      => ($response !== false ? $response : ''),
            'headers'   => $responseHeaders,
            'error'     => 'GitHubへのHTTP通信に失敗しました。(HTTP ' . $httpCode . ')'
        ];
    }

    // ------------------------------------------------------
    // 成功
    // ------------------------------------------------------
    return [
        'success'   => true,
        'http_code' => $httpCode,
        'body'      => $response,
        'headers'   => $responseHeaders,
        'error'     => ''
    ];
}

// =========================================================================
// 2. PHP バックエンド処理 (API 制御)
// =========================================================================
if (isset($_GET['api'])) {
    $api = $_GET['api'];
    $data = json_decode(file_get_contents('php://input'), true);
    header('Content-Type: application/json');
// ==========================================================
    // 【ファイル/ディレクトリ複製（copy）】
    // ==========================================================
    if ($api === 'copy') {
        // 実行時間制限とメモリを一時的に緩和
        @set_time_limit(120);
        @ini_set('memory_limit', '256M');

        // バッファを全クリアして余計な出力を破棄
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');

        $src = $data['src'] ?? '';
        $dst = $data['dst'] ?? '';

        if (empty($src) || empty($dst)) {
            echo json_encode(['success' => false, 'error' => 'コピー元またはコピー先が指定されていません。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // パス正規化
        $srcRel = trim(str_replace(['\\', '//'], '/', $src), '/');
        $dstRel = trim(str_replace(['\\', '//'], '/', $dst), '/');

        $srcPath = $base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $srcRel);
        $dstPath = $base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dstRel);

        if (!file_exists($srcPath)) {
            echo json_encode(['success' => false, 'error' => 'コピー元が存在しません: ' . $srcRel], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (file_exists($dstPath)) {
            echo json_encode(['success' => false, 'error' => 'コピー先が既に存在します: ' . $dstRel], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 再帰コピー実行
        function rcopy_safe($src, $dst) {
            if (is_dir($src)) {
                if (!@mkdir($dst, 0777, true) && !is_dir($dst)) return false;
                $dir = @opendir($src);
                if (!$dir) return false;
                while (false !== ($file = readdir($dir))) {
                    if ($file === '.' || $file === '..') continue;
                    rcopy_safe($src . DIRECTORY_SEPARATOR . $file, $dst . DIRECTORY_SEPARATOR . $file);
                }
                closedir($dir);
                return true;
            } else if (file_exists($src)) {
                @mkdir(dirname($dst), 0777, true);
                return @copy($src, $dst);
            }
            return false;
        }

        $ok = rcopy_safe($srcPath, $dstPath);

        if ($ok) {
            echo json_encode(['success' => true, 'src' => $srcRel, 'dst' => $dstRel], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'error' => 'フォルダまたはファイルの複製に失敗しました。権限を確認してください。'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
	if ($api === 'get_harness') {
        // レスポンスを強制的にJSONにする
        header('Content-Type: application/json; charset=utf-8');

        // POST / GET / JSON リクエストから app_name を取得
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $appName = $data['app_name'] ?? $_REQUEST['app_name'] ?? '';

        // アプリ名の正規化（余分なパス要素を取り除く）
        $parts = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $appName), '/')), function($p) {
            return $p !== '' && $p !== '.poc' && $p !== 'poc' && $p !== 'draft' && $p !== 'published' && $p !== '.history' && $p !== '.prompt' && $p !== '.harness' && $p !== '.github';
        }));
        $appName = !empty($parts) ? end($parts) : basename($appName);

        if (empty($appName)) {
            echo json_encode([
                'success' => false,
                'error' => 'アプリ名が指定されていません。'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 対象アプリの .harness ディレクトリパス
        $target_harness_dir = $poc_dir . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . '.harness';

        // ==========================================
        // 1. ファイルを結合する
        // ==========================================
        $harness_combined_text = '';

        if (is_dir($target_harness_dir)) {
            // *.md を取得
            $harness_files = glob($target_harness_dir . DIRECTORY_SEPARATOR . '*.md');
            
            if (!empty($harness_files)) {
                // ファイル名順でソート（例: 01_xxx.md -> 02_yyy.md）
                sort($harness_files, SORT_NATURAL);
                
                $contents = [];
                foreach ($harness_files as $file) {
                    if (!is_file($file)) {
                        continue;
                    }

                    $text = trim(file_get_contents($file));
                    if ($text !== '') {
                        $filename = pathinfo($file, PATHINFO_FILENAME);
                        // 各ファイルの内容が混ざらないよう見出しと区切りを入れて結合
                        $contents[] = "--- [Harness: {$filename}] ---\n" . $text;
                    }
                }
                
                // ファイル同士を改行2つで連結
                $harness_combined_text = implode("\n\n", $contents);
            }
        }

        // レスポンスを返して処理を終了する
        echo json_encode([
            'success' => true,
            'data' => $harness_combined_text
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
	// ==========================================================
    // 1. ツリー取得 API
    // ==========================================================
    if ($api === 'get_tree') {
        $safeBaseDir = (!empty($base_dir) && is_dir($base_dir)) ? rtrim($base_dir, "\\/") : __DIR__;
        $published_path = isset($published_dir) && is_dir($published_dir) 
            ? $published_dir 
            : $safeBaseDir . DIRECTORY_SEPARATOR . 'published';
        $poc_path = isset($poc_dir) && is_dir($poc_dir) 
            ? $poc_dir 
            : $safeBaseDir . DIRECTORY_SEPARATOR . '.poc';

        // 公開中アプリ名のリストを作成
        $publishedAppSet = [];
        if (is_dir($published_path)) {
            $pubItems = scandir($published_path);
            foreach ($pubItems as $item) {
                if ($item === '.' || $item === '..' || strpos($item, '.') === 0) continue;
                if (file_exists($published_path . DIRECTORY_SEPARATOR . $item . DIRECTORY_SEPARATOR . 'index.php')) {
                    $publishedAppSet[$item] = true;
                }
            }
        }

        // [address]. 公開中ツリー (public)
        $publicTree = [];
        if (is_dir($published_path)) {
            $publicTree = scan_tree($published_path, basename($published_path));
        }

        // [address]. 下書き・モックツリー (draft / mock)
        $draftTree = [];
        $mockTree  = [];

        if (is_dir($poc_path)) {
            $apps = scandir($poc_path);
            foreach ($apps as $app) {
                if ($app === '.' || $app === '..' || strpos($app, '.') === 0 || strpos($app, '_tmp_') === 0) continue;

                $appPath = $poc_path . DIRECTORY_SEPARATOR . $app;
                if (!is_dir($appPath)) continue;

                // ★このアプリが公開中かどうかを判定
                $isPublished = !empty($publishedAppSet[$app]);

                // 下書き: .poc/{アプリ名}/draft
                $draftDir = $appPath . DIRECTORY_SEPARATOR . 'draft';
                $draftIndex = $draftDir . DIRECTORY_SEPARATOR . 'index.php';
                // ★ index.php が存在し、かつ中身がある場合のみ有効なドラフトとする
                $hasRealDraft = is_dir($draftDir) && file_exists($draftIndex) && (trim((string)@file_get_contents($draftIndex)) !== '');

                if ($hasRealDraft) {
                    $draftTree[] = [
                        'name'      => $app,
                        'path'      => ".poc/{$app}/draft",
                        'type'      => 'app',
                        'status'    => 'draft',
                        'published' => $isPublished, // ★ここで true/false を渡す
                        'children'  => scan_tree($draftDir, ".poc/{$app}/draft")
                    ];
                }

                // モック: .poc/{アプリ名}/mock
                $mockDir = $appPath . DIRECTORY_SEPARATOR . 'mock';
                $mockIndex = $mockDir . DIRECTORY_SEPARATOR . 'index.php';
                // ★ index.php が存在し、かつ中身がある場合のみ有効なモックとする
                $hasRealMock = is_dir($mockDir) && file_exists($mockIndex) && (trim((string)@file_get_contents($mockIndex)) !== '');

                if ($hasRealMock) {
                    $mockTree[] = [
                        'name'      => $app,
                        'path'      => ".poc/{$app}/mock",
                        'type'      => 'app',
                        'status'    => 'mock',
                        'published' => $isPublished, // ★ここで true/false を渡す
                        'children'  => scan_tree($mockDir, ".poc/{$app}/mock")
                    ];
                }
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'public' => $publicTree,
            'draft'  => $draftTree,
            'mock'   => $mockTree
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
 
    // ==========================================================
    // 2. ファイル取得 API
    // ==========================================================
    if ($api === 'get_file') {
        header('Content-Type: application/json; charset=utf-8');
        
        $path = $data['path'] ?? $_REQUEST['path'] ?? '';
        $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');
        
        // base_dir からの相対パスとして解決
        $targetPath = $base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
        $filePath   = $targetPath . DIRECTORY_SEPARATOR . 'index.php';
        
        $content = '';
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
        }
        
        echo json_encode(['content' => $content], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
	// =========================================================================
	// チェックポイント（履歴）管理機能 一式
	// =========================================================================

	/**
	 * アプリ名を厳密に抽出する関数
	 */
	function get_app_name($path_or_app) {
	    $clean = trim(str_replace('\\', '/', (string)$path_or_app), "\"'{} \t\n\r\0\x0B/");
	    $parts = explode('/', $clean);
	    foreach ($parts as $i => $part) {
	        if ($part === '.poc' && isset($parts[$i + 1])) return $parts[$i + 1];
	    }
	    $filtered = array_values(array_filter($parts, fn($p) => !in_array($p, ['', 'gojacic', '.poc', 'poc', 'draft', 'mock', 'dev', 'spec'], true)));
	    return !empty($filtered) ? end($filtered) : ($parts[0] ?? '');
	}

	/**
	 * フォルダを再帰的にコピーする関数（.history は除外）
	 */
	function copy_folder($src, $dst) {
	    if (!is_dir($src)) return;
	    @mkdir($dst, 0777, true);
	    $dir = opendir($src);
	    while (false !== ($file = readdir($dir))) {
	        if ($file !== '.' && $file !== '..' && $file !== '.history') {
	            if (is_dir($src . '/' . $file)) {
	                copy_folder($src . '/' . $file, $dst . '/' . $file);
	            } else {
	                @copy($src . '/' . $file, $dst . '/' . $file);
	            }
	        }
	    }
	    closedir($dir);
	}


	// =========================================================================
	// 1. 上書き保存 ＆ チェックポイント作成（create_checkpoint）
	// =========================================================================
	if ($api === 'create_checkpoint') {
	    while (ob_get_level()) { ob_end_clean(); }
	    header('Content-Type: application/json; charset=utf-8');

	    $req     = !empty($data) ? $data : $_REQUEST;
	    $appName = get_app_name($req['app'] ?? $req['path'] ?? '');
	    $type    = $req['type'] ?? 'mock';      // 'mock' | 'dev' | 'spec/common' | 'spec/mock' | 'spec/dev'
	    $content = $req['content'] ?? '';       // 保存するコードや文章
	    $memo    = trim((string)($req['memo'] ?? ''));   // 変更理由（空なら履歴作成しない）
	    $prompt  = $req['prompt'] ?? '';        // AIへのプロンプト

	    if (empty($appName)) {
	        echo json_encode(['success' => false, 'error' => 'アプリ名が特定できません'], JSON_UNESCAPED_UNICODE);
	        exit;
	    }

	    $appRoot = rtrim($poc_dir, '/\\') . '/' . $appName;

	    // --- ① 最新実体ファイルの保存（※必ず実行される） ---
	    if (strpos($type, 'spec/') === 0) {
	        $specName = basename($type);
	        $saveDir  = $appRoot . '/spec';
	        $saveFile = $saveDir . '/' . (str_ends_with($specName, '.md') ? $specName : $specName . '.md');
	    } elseif ($type === 'dev') {
	        $saveDir  = $appRoot . '/dev';
	        $saveFile = $saveDir . '/index.php';
	    } else {
	        $saveDir  = $appRoot . '/mock';
	        $saveFile = $saveDir . '/index.php';
	    }

	    if (!is_dir($saveDir)) @mkdir($saveDir, 0777, true);
	    if (@file_put_contents($saveFile, $content, LOCK_EX) === false) {
	        echo json_encode(['success' => false, 'error' => 'ファイルの書き込みに失敗しました: ' . $saveFile], JSON_UNESCAPED_UNICODE);
	        exit;
	    }
	    @chmod($saveFile, 0666);

	    // --- ② メモ欄の入力がある場合のみチェックポイントを作成 ---
	    $checkpointCreated = false;
	    $cpName = null;

	    if ($memo !== '') {
	        $cleanMemo  = preg_replace('/[\/\\\:\*\?"<>\|\x00-\x1f\s]/u', '_', $memo);
	        $cpName     = date('Ymd_His') . '_' . $cleanMemo;
	        $historyDir = $appRoot . '/.history';
	        $cpDir      = $historyDir . '/' . $cpName;

	        if (!is_dir($cpDir)) @mkdir($cpDir, 0777, true);

	        // spec, mock, dev をまるごと複製保存
	        foreach (['spec', 'mock', 'dev'] as $dirName) {
	            $src = $appRoot . '/' . $dirName;
	            if (is_dir($src)) {
	                copy_folder($src, $cpDir . '/' . $dirName);
	            }
	        }

	        // メタ情報（meta.json）の保存
	        $metaData = [
	            'timestamp' => date('Y-m-d H:i:s'),
	            'type'      => $type,
	            'memo'      => $memo,
	            'prompt'    => $prompt
	        ];
	        @file_put_contents(
	            $cpDir . '/meta.json',
	            json_encode($metaData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
	            LOCK_EX
	        );

	        $checkpointCreated = true;
	    }

	    echo json_encode([
	        'success'            => true,
	        'checkpoint_created' => $checkpointCreated, // 履歴を作成したか（true/false）
	        'checkpoint'         => $cpName,             // 作成したフォルダ名（無ければnull）
	        'saved_file'         => $saveFile
	    ], JSON_UNESCAPED_UNICODE);
	    exit;
	}


	// =========================================================================
	// 2. チェックポイント一覧取得（list_checkpoints）
	// =========================================================================
	if ($api === 'list_checkpoints') {
	    while (ob_get_level()) { ob_end_clean(); }
	    header('Content-Type: application/json; charset=utf-8');

	    $appName = get_app_name($_REQUEST['app'] ?? $_REQUEST['path'] ?? '');
	    $historyDir = rtrim($poc_dir, '/\\') . '/' . $appName . '/.history';
	    $list = [];

	    if (is_dir($historyDir)) {
	        $items = array_diff(scandir($historyDir), ['.', '..']);
	        foreach ($items as $item) {
	            $path = $historyDir . '/' . $item;
	            if (is_dir($path)) {
	                $metaFile = $path . '/meta.json';
	                $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];
	                $list[] = [
	                    'checkpoint_id' => $item,
	                    'date'          => $meta['timestamp'] ?? date('Y/m/d H:i:s', filemtime($path)),
	                    'type'          => $meta['type'] ?? '',
	                    'memo'          => $meta['memo'] ?? '',
	                    'prompt'        => $meta['prompt'] ?? '',
	                    'mtime'         => filemtime($path)
	                ];
	            }
	        }
	        // 新しい順（降順）にソートして全件返却
	        usort($list, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
	    }

	    echo json_encode(['success' => true, 'checkpoints' => $list], JSON_UNESCAPED_UNICODE);
	    exit;
	}


	// =========================================================================
	// 3. チェックポイント復元（restore_checkpoint）
	// =========================================================================
	if ($api === 'restore_checkpoint') {
	    while (ob_get_level()) { ob_end_clean(); }
	    header('Content-Type: application/json; charset=utf-8');

	    $appName = get_app_name($_REQUEST['app'] ?? $_REQUEST['path'] ?? '');
	    $cpId    = basename($_REQUEST['checkpoint_id'] ?? '');

	    $appRoot = rtrim($poc_dir, '/\\') . '/' . $appName;
	    $cpDir   = $appRoot . '/.history/' . $cpId;

	    if (empty($appName) || empty($cpId) || !is_dir($cpDir)) {
	        echo json_encode(['success' => false, 'error' => '指定されたチェックポイントが存在しません'], JSON_UNESCAPED_UNICODE);
	        exit;
	    }

	    // チェックポイント内のフォルダ（spec, mock, dev）を現行に上書き復元
	    foreach (['spec', 'mock', 'dev'] as $dirName) {
	        $src = $cpDir . '/' . $dirName;
	        $dst = $appRoot . '/' . $dirName;
	        if (is_dir($src)) {
	            copy_folder($src, $dst);
	        }
	    }

	    echo json_encode(['success' => true, 'restored' => $cpId], JSON_UNESCAPED_UNICODE);
	    exit;
	}

	// ==========================================
	// 【GitHub同期解除API（draft / mock 等の全ステージ自動一括削除）】
	// ==========================================
	if ($api === 'github_unsync') {
	    while (\ob_get_level()) { \ob_end_clean(); }
	    header('Content-Type: application/json; charset=utf-8');

	    $target = $data['target'] ?? $data['path'] ?? $_REQUEST['target'] ?? $_REQUEST['path'] ?? '';
	    if (empty($target)) {
	        echo json_encode(['success' => false, 'error' => '同期解除の対象が指定されていません。'], JSON_UNESCAPED_UNICODE);
	        exit;
	    }

	    // 1. アプリ名の抽出（.poc や draft, mock 等を除去してアプリ本体名を特定）
	    $parts = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $target), '/')), function($p) {
	        return $p !== '' && $p !== 'gojacic' && $p !== '.poc' && $p !== 'poc' && $p !== 'draft' && $p !== 'mock' && $p !== 'published' && $p !== 'index.php';
	    }));
	    $appName = !empty($parts) ? reset($parts) : basename($target);

	    // アプリ配下の設定ディレクトリ
	    $pocBase          = isset($poc_dir) ? rtrim($poc_dir, DIRECTORY_SEPARATOR) : ($base_dir . DIRECTORY_SEPARATOR . '.poc');
	    $app_github_dir   = $pocBase . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . '.github';
	    $appConfigFile    = $app_github_dir . DIRECTORY_SEPARATOR . 'config.json';
	    $globalConfigFile = $github_config_file ?? ($base_dir . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'config.json');

	    // 2. 設定読み込み
	    $globalConfig = is_file($globalConfigFile) ? (@json_decode(@file_get_contents($globalConfigFile), true) ?: []) : [];
	    $appConfig    = is_file($appConfigFile) ? (@json_decode(@file_get_contents($appConfigFile), true) ?: []) : [];
	    $cfg          = array_merge($globalConfig, $appConfig);

	    $token  = trim($cfg['token'] ?? $cfg['access_token'] ?? $cfg['pat'] ?? '');
	    $owner  = trim($cfg['owner'] ?? $cfg['username'] ?? $cfg['user'] ?? '');
	    $repo   = trim($cfg['repo'] ?? $cfg['repository'] ?? '');
	    $branch = trim($cfg['branch'] ?? 'main') ?: 'main';

	    $deletedFiles = [];
	    $errors = [];

	    // 3. GitHub上の全ファイルを再帰的に探索して削除
	    if ($token !== '' && $owner !== '' && $repo !== '') {
	        $headers = [
	            'Authorization: Bearer ' . $token,
	            'Accept: application/vnd.github+json',
	            'User-Agent: PHP-GitHub-App',
	            'X-GitHub-Api-Version: 2022-11-28'
	        ];
	        $proxyOpts = [
	            !empty($cfg['proxy_enabled']),
	            trim($cfg['proxy_host'] ?? ''),
	            (int)($cfg['proxy_port'] ?? 0),
	            trim($cfg['proxy_username'] ?? ''),
	            $cfg['proxy_password'] ?? ''
	        ];

	        // 再帰的にディレクトリ内のファイル（SHA付き）を収集するヘルパー関数
	        $collectFiles = function($gitDirPath) use (&$collectFiles, $owner, $repo, $branch, $headers, $proxyOpts) {
	            $files = [];
	            $encodedPath = implode('/', array_map('rawurlencode', explode('/', trim($gitDirPath, '/'))));
	            $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$encodedPath}?ref=" . rawurlencode($branch);

	            $res = github_http_request('GET', $url, $headers, null, ...$proxyOpts);
	            if ((int)($res['http_code'] ?? 0) === 200) {
	                $items = json_decode($res['body'] ?? '', true);
	                if (is_array($items)) {
	                    // 単一ファイルの場合（連想配列）
	                    if (isset($items['type']) && $items['type'] === 'file') {
	                        $files[] = ['path' => $items['path'], 'sha' => $items['sha']];
	                    } else {
	                        // ディレクトリ一覧の場合（配列）
	                        foreach ($items as $item) {
	                            if (($item['type'] ?? '') === 'file') {
	                                $files[] = ['path' => $item['path'], 'sha' => $item['sha']];
	                            } elseif (($item['type'] ?? '') === 'dir') {
	                                $files = array_merge($files, $collectFiles($item['path']));
	                            }
	                        }
	                    }
	                }
	            }
	            return $files;
	        };

	        // .poc/{appName} および {appName} 配下の全探索（draft, mock 等すべて対象）
	        $targetRoots = [
	            ".poc/{$appName}",
	            $appName
	        ];

	        $allRemoteFiles = [];
	        foreach ($targetRoots as $root) {
	            $allRemoteFiles = array_merge($allRemoteFiles, $collectFiles($root));
	        }

	        // 重複を除去して削除リクエストを実行
	        $uniqueFiles = [];
	        foreach ($allRemoteFiles as $f) {
	            $uniqueFiles[$f['path']] = $f['sha'];
	        }

	        foreach ($uniqueFiles as $fPath => $sha) {
	            $encodedFilePath = implode('/', array_map('rawurlencode', explode('/', $fPath)));
	            $delUrl = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$encodedFilePath}";

	            $delBody = json_encode([
	                'message' => "Unsync delete: {$fPath}",
	                'sha'     => $sha,
	                'branch'  => $branch
	            ], JSON_UNESCAPED_UNICODE);

	            $delRes = github_http_request('DELETE', $delUrl, array_merge($headers, ['Content-Type: application/json']), $delBody, ...$proxyOpts);
	            $delCode = (int)($delRes['http_code'] ?? 0);

	            if ($delCode >= 200 && $delCode < 300) {
	                $deletedFiles[] = $fPath;
	            } else {
	                $errors[] = "{$fPath} の削除失敗 (HTTP {$delCode})";
	            }
	        }
	    }

	    // 4. ローカル個別設定のクリーンアップ
	    if (is_dir($app_github_dir)) {
	        $files = glob($app_github_dir . DIRECTORY_SEPARATOR . '*');
	        if ($files) {
	            foreach ($files as $f) {
	                if (is_file($f)) @unlink($f);
	            }
	        }
	        @rmdir($app_github_dir);
	    }

	    $oldLocalGitMeta = (isset($github_dir) ? $github_dir : ($base_dir . DIRECTORY_SEPARATOR . '.github')) . DIRECTORY_SEPARATOR . $appName;
	    if (is_dir($oldLocalGitMeta)) {
	        $files = glob($oldLocalGitMeta . DIRECTORY_SEPARATOR . '*');
	        if ($files) {
	            foreach ($files as $f) {
	                if (is_file($f)) @unlink($f);
	            }
	        }
	        @rmdir($oldLocalGitMeta);
	    }

	    // 5. 成功レスポンス
	    echo json_encode([
	        'success'       => true,
	        'unsynced'      => true,
	        'deleted_files' => $deletedFiles,
	        'message'       => count($deletedFiles) > 0 
	            ? "GitHub上の関連ファイル (" . count($deletedFiles) . "件) を削除し、同期を解除しました。" 
	            : 'GitHub上に対象ファイルがなかったため、同期設定のみ解除しました。',
	        'errors'        => $errors
	    ], JSON_UNESCAPED_UNICODE);
	    exit;
	}
}
// ==========================================================
// GitHub同期
//
// Git / cURL は一切使用しない。
// 作業中のファイル（index.php または 要件等）をGitHubへ直接保存する。
// ==========================================================

if ($api === 'github_sync') {
    while (\ob_get_level()) { \ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $req = !empty($data) ? $data : $_REQUEST;
    $path       = trim(str_replace('\\', '/', $req['path'] ?? ''));
    $content    = $req['content'] ?? '';
    $filename   = trim($req['filename'] ?? '') ?: 'index.php';
    $targetType = trim($req['target_type'] ?? $req['file_type'] ?? '');
    $label      = trim($req['label'] ?? '');
    $reqStage   = trim($req['stage'] ?? '');

    if ($path === '') {
        echo json_encode(['success' => false, 'error' => '同期するパスが指定されていません。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ========================================================
    // 1. アプリ名・ステージ(mock / draft)の厳格な特定と検証
    // ========================================================
    $normPath = trim($path, '/');
    $parts = explode('/', $normPath);

    $isPoc = false;
    if (isset($parts[0]) && ($parts[0] === '.poc' || $parts[0] === 'poc')) {
        $isPoc = true;
        array_shift($parts); // '.poc' を取り除く
    }

    $appName = $parts[0] ?? '';
    if (empty($appName)) {
        echo json_encode(['success' => false, 'error' => 'アプリ名を特定できませんでした。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ステージ（mock / draft）の特定
    $stage = null;

    // ① リクエストの stage パラメータで明示指定されている場合
    if ($reqStage === 'mock' || $reqStage === 'draft') {
        $stage = $reqStage;
    }
    // ② パス要素（ディレクトリ構成）に含まれている場合
    elseif (in_array('mock', $parts, true) || preg_match('#(^|/)mock($|/)#', $normPath)) {
        $stage = 'mock';
    } elseif (in_array('draft', $parts, true) || preg_match('#(^|/)draft($|/)#', $normPath)) {
        $stage = 'draft';
    }
    // ③ target_type / file_type で明示指定されている場合
    elseif ($targetType === 'mock') {
        $stage = 'mock';
    } elseif ($targetType === 'draft' || $targetType === 'code' || $targetType === 'prompt') {
        $stage = 'draft';
    }

    // 【厳格ガード】mock でも draft でもない場合は絶対に処理を進めない
    if ($stage !== 'mock' && $stage !== 'draft') {
        echo json_encode([
            'success' => false,
            'error'   => 'ステージ（mock または draft）が特定できません。曖昧なパス・パラメータでの保存は拒否されました。',
            'received' => [
                'path'        => $path,
                'stage'       => $reqStage,
                'target_type' => $targetType
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($label === '') {
        $label = ($stage === 'mock') ? 'モック' : '開発中';
    }

    // ========================================================
    // 2. 設定ファイルの読み込み
    // ========================================================
    $pocBaseDir       = ($poc_dir ?? ($base_dir . DIRECTORY_SEPARATOR . '.poc'));
    $appConfigFile    = $pocBaseDir . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'config.json';
    $globalConfigFile = $github_config_file ?? ($base_dir . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'config.json');

    $appConfig = is_file($appConfigFile) ? (@json_decode(@file_get_contents($appConfigFile), true) ?: []) : [];
    $globalConfig = is_file($globalConfigFile) ? (@json_decode(@file_get_contents($globalConfigFile), true) ?: []) : [];

    // 設定のマージ（アプリ個別設定で上書き）
    $config = array_merge($globalConfig, array_filter($appConfig, function($v) {
        return $v !== '' && $v !== null;
    }));

    // 個別設定に enabled がある場合はそれを最優先、無ければ全体設定
    $isEnabled = true;
    if (array_key_exists('enabled', $appConfig)) {
        $isEnabled = !($appConfig['enabled'] === false || $appConfig['enabled'] === 0 || $appConfig['enabled'] === '0');
    } elseif (array_key_exists('enabled', $globalConfig)) {
        $isEnabled = !($globalConfig['enabled'] === false || $globalConfig['enabled'] === 0 || $globalConfig['enabled'] === '0');
    }

    if (!$isEnabled) {
        echo json_encode([
            'success' => true, 
            'synced'  => false, 
            'message' => 'アプリのGitHub同期がOFFになっています。'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $owner  = trim($config['owner'] ?? $config['username'] ?? $config['user'] ?? '');
    $repo   = trim($config['repo'] ?? $config['repository'] ?? '');
    $branch = trim($config['branch'] ?? 'main') ?: 'main';
    $token  = trim($config['token'] ?? $config['access_token'] ?? $config['pat'] ?? '');

    if ($owner === '' || $repo === '' || $token === '') {
        echo json_encode([
            'success' => false, 
            'error'   => 'GitHub設定（ユーザー名/リポジトリ/Token）が不足しています。'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $proxyEnabled  = (bool)($config['proxy_enabled'] ?? false);
    $proxyHost     = trim($config['proxy_host'] ?? '');
    $proxyPort     = (int)($config['proxy_port'] ?? 0);
    $proxyUsername = trim($config['proxy_username'] ?? '');
    $proxyPassword = $config['proxy_password'] ?? '';

    // ========================================================
    // 3. GitHub上のリポジトリパス組み立て（.poc/AppName/mock/index.php または draft）
    // ========================================================
    $cleanFilename = basename($filename);
    $prefix = $isPoc ? '.poc/' : '';
    $githubFilePath = $prefix . $appName . '/' . $stage . '/' . $cleanFilename;

    $apiUrl = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $githubFilePath)));
    
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.github+json',
        'User-Agent: PHP-GitHub-App',
        'X-GitHub-Api-Version: 2022-11-28'
    ];

    // ========================================================
    // 4. SHA取得 (GET)
    // ========================================================
    $getResult = github_http_request('GET', $apiUrl . '?ref=' . rawurlencode($branch), $headers, null, $proxyEnabled, $proxyHost, $proxyPort, $proxyUsername, $proxyPassword);
    $getHttpCode = (int)($getResult['http_code'] ?? 0);

    $sha = null;
    if ($getHttpCode === 200) {
        $existing = json_decode($getResult['body'] ?? '', true) ?: [];
        $sha = $existing['sha'] ?? null;
    }

    // ========================================================
    // 5. 保存 (PUT)
    // ========================================================
    $requestData = [
        'message' => '[' . $label . ']同期: ' . $githubFilePath,
        'content' => base64_encode($content),
        'branch'  => $branch
    ];
    if ($sha !== null) {
        $requestData['sha'] = $sha;
    }

    $putHeaders = array_merge($headers, ['Content-Type: application/json']);
    $putResult = github_http_request('PUT', $apiUrl, $putHeaders, json_encode($requestData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $proxyEnabled, $proxyHost, $proxyPort, $proxyUsername, $proxyPassword);
    $putHttpCode = (int)($putResult['http_code'] ?? 0);

    $result = json_decode($putResult['body'] ?? '', true) ?: [];

    if ($putHttpCode >= 200 && $putHttpCode < 300) {
        $rawUrl = 'https://raw.githubusercontent.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/' . rawurlencode($branch) . '/' . implode('/', array_map('rawurlencode', explode('/', $githubFilePath)));
        echo json_encode([
            'success'      => true,
            'synced'       => true,
            'message'      => 'GitHubへ保存しました。',
            'path'         => $githubFilePath,
            'stage'        => $stage,
            'branch'       => $branch,
            'raw_url'      => $rawUrl
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => false,
        'error'   => 'GitHubへの保存に失敗しました。',
        'detail'  => $result['message'] ?? ($putResult['body'] ?? ''),
        'status'  => $putHttpCode
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
// ==========================================================
// GitHub設定読み込み（ディレクトリ存在判定・デバッグ対応版）
// ==========================================================

if ($api === 'github_settings_get') {
    // 1. バッファをクリアしてJSON出力をクリーンに保つ
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        // パラメータ取得
        $rawInput = @file_get_contents('php://input');
        $postData = (!empty($rawInput)) ? @json_decode($rawInput, true) : [];
        if (!is_array($postData)) { $postData = []; }

        $allInputs = array_merge(
            is_array($_GET) ? $_GET : [],
            is_array($_POST) ? $_POST : [],
            is_array($_REQUEST) ? $_REQUEST : [],
            $postData,
            (isset($data) && is_array($data)) ? $data : []
        );

        $possibleKeys = ['path', 'app_path', 'appPath', 'target_path', 'targetPath', 'dir', 'app', 'appName', 'name'];
        $rawPath = '';
        foreach ($possibleKeys as $key) {
            if (isset($allInputs[$key]) && is_string($allInputs[$key]) && trim($allInputs[$key]) !== '') {
                $rawPath = trim($allInputs[$key]);
                break;
            }
        }

        // 2. 全体共通設定の読み込み
        $globalConfig = [];
        $baseDirectory = isset($base_dir) ? rtrim($base_dir, '/\\') : dirname(__DIR__);
        $globalConfigFile = $github_config_file ?? ($baseDirectory . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'config.json');
        
        if ($globalConfigFile && is_file($globalConfigFile)) {
            $globalJson = @file_get_contents($globalConfigFile);
            if ($globalJson !== false) {
                $decodedGlobal = @json_decode($globalJson, true);
                if (is_array($decodedGlobal)) { $globalConfig = $decodedGlobal; }
            }
        }

        // 3. アプリ名の抽出
        $appName = '';
        if ($rawPath !== '') {
            $decoded = urldecode($rawPath);
            $normalized = trim(str_replace('\\', '/', $decoded), '/');
            $parts = array_values(array_filter(explode('/', $normalized), function($p) {
                return $p !== '' && $p !== 'gojacic' && $p !== '.poc' && $p !== 'poc' && $p !== 'draft' && $p !== 'mock' && $p !== 'published' && $p !== '.' && $p !== '..';
            }));
            $appName = !empty($parts) ? $parts[0] : basename($normalized);
            if ($appName === '.' || $appName === '..') {
                $appName = '';
            }
        }

        // 4. アプリ個別設定の読み込み（.poc/{appName}/.github/config.json）
        $appConfig = [];
        $appConfigExists = false;
        $targetAppConfigFile = '';

        if ($appName !== '') {
            $pocBaseDir = isset($poc_dir) ? rtrim($poc_dir, '/\\') : ($baseDirectory . DIRECTORY_SEPARATOR . '.poc');
            $targetAppConfigFile = $pocBaseDir . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'config.json';
            
            if (is_file($targetAppConfigFile)) {
                $appJson = @file_get_contents($targetAppConfigFile);
                if ($appJson !== false) {
                    $decodedApp = @json_decode($appJson, true);
                    if (is_array($decodedApp)) {
                        $appConfig = $decodedApp;
                        $appConfigExists = true;
                    }
                }
            }
        }

        // 5. 基本設定のマージ
        $merged = $globalConfig;
        foreach ($appConfig as $key => $value) {
            if ($value !== '' && $value !== null) {
                $merged[$key] = $value;
            }
        }

        // 6. enabled 判定（アプリ設定ファイルが無い場合は確実に false）
        $isEnabled = false;
        if ($appName === '') {
            $val = $globalConfig['enabled'] ?? false;
            $isEnabled = ($val === true || $val === 1 || $val === '1' || $val === 'true');
        } else {
            if ($appConfigExists && isset($appConfig['enabled'])) {
                $val = $appConfig['enabled'];
                $isEnabled = ($val === true || $val === 1 || $val === '1' || $val === 'true');
            } else {
                $isEnabled = false; // 再作成直後や未設定時は絶対に false
            }
        }

        // 7. GitHub上にファイルが存在するかチェック
        // 【重要】アプリが無効（$isEnabled === false）の場合は、無駄なAPI通信をスキップして強制的に false にする
        $existsOnGitHub = false;
        $owner  = trim((string)($merged['owner'] ?? ''));
        $repo   = trim((string)($merged['repo'] ?? ''));
        $branch = !empty($merged['branch']) ? trim((string)$merged['branch']) : 'main';
        $token  = trim((string)($merged['token'] ?? ''));

        $apiDebug = [];

        if ($isEnabled && $appName !== '' && !empty($owner) && !empty($repo) && !empty($token)) {
            $segments = ['.poc', $appName, 'draft'];
            $encodedPath = implode('/', array_map('rawurlencode', $segments));
            $apiUrl = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/' . $encodedPath . '?ref=' . rawurlencode($branch);
            
            $proxyEnabled = (bool)($merged['proxy_enabled'] ?? false);
            $proxyHost    = trim((string)($merged['proxy_host'] ?? ''));
            $proxyPort    = (int)($merged['proxy_port'] ?? 0);
            $proxyUser    = trim((string)($merged['proxy_username'] ?? ''));
            $proxyPass    = (string)($appConfig['proxy_password'] ?? $globalConfig['proxy_password'] ?? '');

            $headerLines = [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'User-Agent: PHP-GitHub-App',
                'X-GitHub-Api-Version: 2022-11-28'
            ];

            $httpOptions = [
                'method'          => 'GET',
                'header'          => implode("\r\n", $headerLines) . "\r\n",
                'timeout'         => 10,
                'ignore_errors'   => true,
                'follow_location' => 1
            ];

            $sslOptions = [
                'verify_peer'      => false,
                'verify_peer_name' => false
            ];

            if ($proxyEnabled && !empty($proxyHost) && !empty($proxyPort)) {
                $httpOptions['proxy'] = 'tcp://' . $proxyHost . ':' . $proxyPort;
                $httpOptions['request_fulluri'] = true;
                if (!empty($proxyUser)) {
                    $auth = base64_encode($proxyUser . (!empty($proxyPass) ? (':' . $proxyPass) : ''));
                    $headerLines[] = 'Proxy-Authorization: Basic ' . $auth;
                    $httpOptions['header'] = implode("\r\n", $headerLines) . "\r\n";
                }
            }

            $context = stream_context_create([
                'http' => $httpOptions,
                'ssl'  => $sslOptions
            ]);

            $resBody = @file_get_contents($apiUrl, false, $context);
            
            $httpCode = 0;
            $headers = function_exists('http_get_last_response_headers') 
                ? http_get_last_response_headers() 
                : ($http_response_header ?? []);

            if (!empty($headers) && is_array($headers)) {
                foreach ($headers as $hdr) {
                    if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#i', $hdr, $m)) {
                        $httpCode = (int)$m[1];
                    }
                }
            }
            if ($httpCode === 200) {
                $existsOnGitHub = true;
            }

            $lastError = error_get_last();
            $apiDebug = [
                'url'       => $apiUrl,
                'http_code' => $httpCode,
                'error'     => ($httpCode === 0 && $lastError) ? $lastError['message'] : null
            ];
        }

        // 8. レスポンス組み立て
        $response = [
            'success'          => true,
            'app_name'         => (string)$appName,
            'exists'           => (bool)$existsOnGitHub,
            'owner'            => $owner,
            'repo'             => $repo,
            'branch'           => $branch,
            'hasToken'         => !empty($token),
            'proxy_enabled'    => isset($merged['proxy_enabled']) ? (bool)$merged['proxy_enabled'] : false,
            'proxy_host'       => (string)($merged['proxy_host'] ?? ''),
            'proxy_port'       => (string)($merged['proxy_port'] ?? ''),
            'proxy_username'   => (string)($merged['proxy_username'] ?? ''),
            'hasProxyPassword' => (isset($appConfig['proxy_password']) && (string)$appConfig['proxy_password'] !== '') 
                                  || (isset($globalConfig['proxy_password']) && (string)$globalConfig['proxy_password'] !== ''),
            'enabled'          => (bool)$isEnabled,
            'is_sync'          => (bool)($isEnabled && $existsOnGitHub),
            'debug'            => [
                'raw_path'           => $rawPath,
                'resolved_app_name'  => $appName,
                'app_config_exists'  => $appConfigExists,
                'target_config_file' => $targetAppConfigFile,
                'github_api_check'   => $apiDebug
            ]
        ];

        $out = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($out === false) {
            echo json_encode(['success' => false, 'error' => 'JSON encode failed: ' . json_last_error_msg()]);
        } else {
            echo $out;
        }

    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ]);
    }

    exit;
}
// ==========================================================
// GitHub同期設定保存（全体設定・新構成アプリ個別設定 両対応版）
// ==========================================================

if ($api === 'github_settings_save') {
    // 1. バッファをクリアしてJSON出力を保証
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        // ------------------------------------------------------
        // 1. 受信データ復元（GET, POST, raw JSON を統合）
        // ------------------------------------------------------
        $rawInput = @file_get_contents('php://input');
        $inputData = !empty($rawInput) ? @json_decode($rawInput, true) : null;
        
        $saveData = [];
        if (isset($_GET) && is_array($_GET)) {
            $saveData = array_merge($saveData, $_GET);
        }
        if (isset($_POST) && is_array($_POST)) {
            $saveData = array_merge($saveData, $_POST);
        }
        if (isset($data) && is_array($data)) {
            $saveData = array_merge($saveData, $data);
        }
        if (is_array($inputData)) {
            $saveData = array_merge($saveData, $inputData);
        }

        // ------------------------------------------------------
        // 2. パス判定 & 保存先ディレクトリ・ファイルの決定
        // ------------------------------------------------------
        $possibleKeys = ['path', 'app_path', 'appPath', 'target_path', 'targetPath', 'dir', 'app', 'appName', 'name'];
        $path = '';
        foreach ($possibleKeys as $key) {
            if (isset($saveData[$key]) && is_string($saveData[$key]) && trim($saveData[$key]) !== '') {
                $path = trim($saveData[$key]);
                break;
            }
        }

        $baseDirectory = isset($base_dir) ? rtrim($base_dir, '/\\') : dirname(__DIR__);
        $pocBaseDir    = isset($poc_dir) ? rtrim($poc_dir, '/\\') : ($baseDirectory . DIRECTORY_SEPARATOR . '.poc');

        $appName = '';
        if ($path !== '') {
            $decodedPath = urldecode($path);
            $normalizedPath = trim(str_replace('\\', '/', $decodedPath), '/');
            $parts = array_values(array_filter(explode('/', $normalizedPath), function($p) {
                return $p !== '' && $p !== 'gojacic' && $p !== '.poc' && $p !== 'poc' && $p !== 'draft' && $p !== 'mock' && $p !== 'published' && $p !== '.' && $p !== '..';
            }));
            $appName = !empty($parts) ? end($parts) : basename($normalizedPath);
            if ($appName === '.' || $appName === '..') {
                $appName = '';
            }
        }

        // 保存先ファイルの決定
        if ($appName !== '') {
            // アプリ個別設定: .poc/{アプリ名}/.github/config.json
            $targetAppDir = $pocBaseDir . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . '.github';
            $targetConfigFile = $targetAppDir . DIRECTORY_SEPARATOR . 'config.json';
            $oldAppConfigFile = !empty($github_dir) ? (rtrim($github_dir, '/\\') . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . 'config.json') : '';
        } else {
            // 全体共通設定: .github/config.json
            $targetAppDir = $baseDirectory . DIRECTORY_SEPARATOR . '.github';
            $targetConfigFile = $github_config_file ?? ($targetAppDir . DIRECTORY_SEPARATOR . 'config.json');
            $oldAppConfigFile = '';
        }

        // ------------------------------------------------------
        // 3. 既存設定読み込み（旧パスからの移行もサポート）
        // ------------------------------------------------------
        $config = [];
        if (is_file($targetConfigFile)) {
            $configJson = @file_get_contents($targetConfigFile);
            if ($configJson !== false && trim($configJson) !== '') {
                $decoded = @json_decode($configJson, true);
                if (is_array($decoded)) { $config = $decoded; }
            }
        } elseif ($oldAppConfigFile && is_file($oldAppConfigFile)) {
            $configJson = @file_get_contents($oldAppConfigFile);
            if ($configJson !== false && trim($configJson) !== '') {
                $decoded = @json_decode($configJson, true);
                if (is_array($decoded)) { $config = $decoded; }
            }
        }

        // ------------------------------------------------------
        // 4. 値の更新（空文字・マスク値は上書きせず保持）
        // ------------------------------------------------------
        if (isset($saveData['owner'])) {
            $config['owner'] = trim($saveData['owner']);
        }
        if (isset($saveData['repo'])) {
            $config['repo'] = trim($saveData['repo']);
        }
        if (isset($saveData['branch']) && trim($saveData['branch']) !== '') {
            $config['branch'] = trim($saveData['branch']);
        }
        if (isset($saveData['token']) && trim($saveData['token']) !== '' && !preg_match('/^\*+$/', trim($saveData['token']))) {
            $config['token'] = trim($saveData['token']);
        }

        // enabled フラグ
        if (isset($saveData['enabled'])) {
            $val = $saveData['enabled'];
            $config['enabled'] = ($val === true || $val === 1 || $val === '1' || $val === 'true');
        }

        // プロキシ関連
        if (isset($saveData['proxy_enabled'])) {
            $val = $saveData['proxy_enabled'];
            $config['proxy_enabled'] = ($val === true || $val === 1 || $val === '1' || $val === 'true');
        }
        if (isset($saveData['proxy_host'])) {
            $config['proxy_host'] = trim($saveData['proxy_host']);
        }
        if (isset($saveData['proxy_port'])) {
            $config['proxy_port'] = trim((string)$saveData['proxy_port']);
        }
        if (isset($saveData['proxy_username'])) {
            $config['proxy_username'] = trim($saveData['proxy_username']);
        }
        if (isset($saveData['proxy_password']) && trim($saveData['proxy_password']) !== '' && !preg_match('/^\*+$/', trim($saveData['proxy_password']))) {
            $config['proxy_password'] = (string)$saveData['proxy_password'];
        }

        // ------------------------------------------------------
        // 5. ディレクトリ作成＆安全な書き込み
        // ------------------------------------------------------
        if (!is_dir($targetAppDir)) {
            if (!@mkdir($targetAppDir, 0755, true) && !is_dir($targetAppDir)) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'GitHub設定ディレクトリを作成できませんでした: ' . $targetAppDir
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
        }

        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            echo json_encode([
                'success' => false,
                'error'   => 'JSON化に失敗しました。',
                'detail'  => json_last_error_msg()
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $result = @file_put_contents($targetConfigFile, $json, LOCK_EX);
        if ($result === false) {
            $lastError = error_get_last();
            echo json_encode([
                'success' => false,
                'error'   => 'GitHub設定ファイルを書き込めませんでした: ' . $targetConfigFile,
                'detail'  => $lastError ? $lastError['message'] : 'unknown'
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        @chmod($targetConfigFile, 0666);

        // ------------------------------------------------------
        // 6. レスポンス返却
        // ------------------------------------------------------
        echo json_encode([
            'success'          => true,
            'is_global'        => ($appName === ''),
            'app_name'         => (string)$appName,
            'file_path'        => $targetConfigFile,
            'enabled'          => (bool)($config['enabled'] ?? false),
            'proxy_enabled'    => (bool)($config['proxy_enabled'] ?? false),
            'hasToken'         => isset($config['token']) && trim($config['token']) !== '',
            'hasProxyPassword' => isset($config['proxy_password']) && $config['proxy_password'] !== ''
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}


// $api 変数が定義されていることを前提としています
if (isset($api)) {



// ==========================================
// 【公開（publish）/ ステージング移行: PoC/下書き -> 本番公開】
// ==========================================

// ==========================================
// 1. 本番公開（publish: draft -> published/{appName}）
// ==========================================
if ($api === 'publish') {
    while (\ob_get_level()) { \ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $appName = trim($data['app_name'] ?? '');
    $src = $data['src'] ?? '';

    if (empty($appName) && !empty($src)) {
        $appName = function_exists('resolve_app_name') ? resolve_app_name($src) : basename(trim(str_replace('\\', '/', $src), '/'));
    }

    if (empty($appName)) {
        echo json_encode(['success' => false, 'error' => 'アプリ名を特定できませんでした。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $safeBaseDir = (!empty($base_dir) && is_dir($base_dir)) ? rtrim($base_dir, "\\/") : __DIR__;
    $publishedDir = (!empty($published_base_dir) && is_dir($published_base_dir))
        ? rtrim($published_base_dir, "\\/")
        : $safeBaseDir . DIRECTORY_SEPARATOR . 'published';

    // 公開元は .poc/{appName}/draft
    $srcPath = $safeBaseDir . DIRECTORY_SEPARATOR . '.poc' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . 'draft';
    $dstPath = $publishedDir . DIRECTORY_SEPARATOR . $appName;

    if (!is_dir($srcPath)) {
        echo json_encode(['success' => false, 'error' => "本番開発（draft）フォルダが見つかりません: {$srcPath}"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 公開先フォルダの整備・コピー
    if (!is_dir($publishedDir)) {
        @mkdir($publishedDir, 0777, true);
    }
    if (is_dir($dstPath)) {
        rrmdir($dstPath);
    }
    rcopy($srcPath, $dstPath);

    // 公開時点のスナップショットを .poc/{appName}/.history 配下に残す
    if (function_exists('save_app_snapshot')) {
        save_app_snapshot($appName, 'publish', '本番公開実行', 'draft');
    }

    echo json_encode([
        'success'        => true,
        'published_path' => $dstPath
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// 2. モックから本番開発へ移行（promote_to_dev: mock -> draft）
// ==========================================
if ($api === 'promote_to_dev') {
    while (\ob_get_level()) { \ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $appName = trim($data['app_name'] ?? '');
    if (!$appName) {
        $targetPath = trim($data['src'] ?? $data['path'] ?? '');
        $parts = explode('/', trim(str_replace('\\', '/', $targetPath), '/'));
        $appName = ($parts[0] === '.poc' && count($parts) >= 2) ? $parts[1] : basename($targetPath);
    }

    if (!$appName) {
        echo json_encode(['success' => false, 'error' => 'アプリ名を特定できませんでした。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $safeBaseDir = (!empty($base_dir) && is_dir($base_dir)) ? rtrim($base_dir, "\\/") : __DIR__;
    $appBaseDir  = $safeBaseDir . DIRECTORY_SEPARATOR . '.poc' . DIRECTORY_SEPARATOR . $appName;
    $mockDir     = $appBaseDir . DIRECTORY_SEPARATOR . 'mock';
    $draftDir    = $appBaseDir . DIRECTORY_SEPARATOR . 'draft';

    if (!is_dir($mockDir)) {
        echo json_encode(['success' => false, 'error' => '移行元のモックフォルダが存在しません: ' . $mockDir], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!is_dir($draftDir)) {
        @mkdir($draftDir, 0775, true);
    }

    $mockIndex  = $mockDir . DIRECTORY_SEPARATOR . 'index.php';
    $draftIndex = $draftDir . DIRECTORY_SEPARATOR . 'index.php';

    // draft/index.php が無ければ mock/index.php を初期値としてコピー
    if (!file_exists($draftIndex)) {
        if (file_exists($mockIndex)) {
            @copy($mockIndex, $draftIndex);
        } else {
            $safeName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
            @file_put_contents($draftIndex, "<h1>{$safeName} (本番開発中)</h1>", LOCK_EX);
        }
        @chmod($draftIndex, 0666);
    }

    // 移行直後のスナップショットを記録
    if (function_exists('save_app_snapshot')) {
        save_app_snapshot($appName, 'promote_to_dev', 'モックから本番開発へ昇格', 'mock');
    }

    echo json_encode([
        'success'  => true,
        'app_name' => $appName,
        'status'   => 'draft',
        'new_path' => ".poc/{$appName}/draft"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// 3. モックに戻す（back_to_mock）
// ==========================================
if ($api === 'back_to_mock') {
    while (\ob_get_level()) { \ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $appName = trim($data['app_name'] ?? '');
    if (!$appName && !empty($data['src'])) {
        $cleanPath = preg_replace('/^\\.?poc[\\/\\\\]?/i', '', str_replace('\\', '/', $data['src']));
        $parts = array_values(array_filter(explode('/', trim($cleanPath, '/'))));
        $appName = !empty($parts) ? $parts[0] : '';
    }

    if (empty($appName)) {
        echo json_encode(['success' => false, 'error' => 'アプリ名が特定できませんでした。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $safeBaseDir = (!empty($base_dir) && is_dir($base_dir)) ? rtrim($base_dir, "\\/") : __DIR__;
    $mockDir = $safeBaseDir . DIRECTORY_SEPARATOR . '.poc' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . 'mock';

    if (!is_dir($mockDir)) {
        echo json_encode(['success' => false, 'error' => 'モックフォルダが存在しません: ' . $mockDir], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success'   => true,
        'message'   => "「{$appName}」の作業レイヤーをモックに切り替えました。",
        'mock_path' => ".poc/{$appName}/mock"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// 4. 公開から下書きへ戻す（back_to_dev / restore_to_draft: published -> draft）
// ==========================================
if ($api === 'back_to_dev' || $api === 'restore_to_draft') {
    while (\ob_get_level()) { \ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $appName = trim($data['app_name'] ?? '');
    $src = $data['src'] ?? '';

    if (empty($appName) && !empty($src)) {
        $appName = function_exists('resolve_app_name') ? resolve_app_name($src) : basename(trim(str_replace('\\', '/', $src), '/'));
    }

    if (empty($appName)) {
        echo json_encode(['success' => false, 'error' => '有効なアプリ名を特定できませんでした。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $safeBaseDir = (!empty($base_dir) && is_dir($base_dir)) ? rtrim($base_dir, "\\/") : __DIR__;
    $publishedDir = (!empty($published_base_dir) && is_dir($published_base_dir))
        ? rtrim($published_base_dir, "\\/")
        : $safeBaseDir . DIRECTORY_SEPARATOR . 'published';

    $srcPath = $publishedDir . DIRECTORY_SEPARATOR . $appName;
    $dstPath = $safeBaseDir . DIRECTORY_SEPARATOR . '.poc' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . 'draft';

    if (is_dir($srcPath)) {
        if (!is_dir($dstPath)) {
            @mkdir($dstPath, 0775, true);
        }
        // published から draft へ同期し、published 側を削除
        rcopy($srcPath, $dstPath);
        rrmdir($srcPath);

        // 公開取り下げ時のスナップショットを記録
        if (function_exists('save_app_snapshot')) {
            save_app_snapshot($appName, 'back_to_draft', '公開取り下げ（draft復帰）', 'draft');
        }

        echo json_encode(['success' => true, 'draft_path' => ".poc/{$appName}/draft"], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'error' => '公開中のアプリが見つかりません。'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
// ==========================================
// 5. アプリ名変更（rename_app）
// ==========================================
if ($api === 'rename_app') {
    $src = $data['src'] ?? '';
    $dst = $data['dst'] ?? '';

    if (empty($src) || empty($dst)) {
        echo json_encode(['success' => false, 'error' => 'フォルダ名が指定されていません。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $oldName = basename(str_replace('\\', '/', $src));
    $newName = basename(str_replace('\\', '/', $dst));

    // ★ 正しいディレクトリ情報を取得（get_app_dirs を使用）
    $oldDirs = get_app_dirs($oldName, $poc_dir, $published_dir);
    $newDirs = get_app_dirs($newName, $poc_dir, $published_dir);

    $srcPath = $oldDirs['root'];
    $dstPath = $newDirs['root'];

    if (!is_dir($srcPath)) {
        echo json_encode(['success' => false, 'error' => "変更元フォルダが存在しません: {$srcPath}"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------
    // ★ 正しいパス (.poc/アプリ名/.github/config.json) から設定を取得
    // ---------------------------------------------------------
    $configFile = $oldDirs['github_config'];
    $config = null;
    $isGithubSyncEnabled = false;

    if (is_file($configFile)) {
        $config = json_decode(file_get_contents($configFile), true);
        if (is_array($config)) {
            $val = $config['enabled'] ?? false;
            $isGithubSyncEnabled = ($val === true || $val === 1 || $val === '1' || $val === 'true');
        }
    }

    // ---------------------------------------------------------
    // GitHub同期処理
    // ---------------------------------------------------------
    if ($isGithubSyncEnabled && !empty($config['token']) && !empty($config['owner']) && !empty($config['repo'])) {
        $owner   = trim($config['owner']);
        $repo    = trim($config['repo']);
        $branch  = trim($config['branch'] ?? 'main') ?: 'main';
        $token   = trim($config['token']);

        // draft/index.php などのファイルを探索
        $targetFile = null;
        $relativeGitPath = '';
        if (is_file($oldDirs['draft'] . DIRECTORY_SEPARATOR . 'index.php')) {
            $targetFile = $oldDirs['draft'] . DIRECTORY_SEPARATOR . 'index.php';
            $relativeGitPath = "draft/index.php";
        } elseif (is_file($srcPath . DIRECTORY_SEPARATOR . 'index.php')) {
            $targetFile = $srcPath . DIRECTORY_SEPARATOR . 'index.php';
            $relativeGitPath = "index.php";
        }

        if ($targetFile) {
            $oldGitPath = ".poc/{$oldName}/{$relativeGitPath}";
            $newGitPath = ".poc/{$newName}/{$relativeGitPath}";

            $headers = [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'User-Agent: App-Rename-Bot'
            ];

            $proxyEnabled  = !empty($config['proxy_enabled']);
            $proxyHost     = trim($config['proxy_host'] ?? '');
            $proxyPort     = (int)($config['proxy_port'] ?? 0);
            $proxyUsername = trim($config['proxy_username'] ?? '');
            $proxyPassword = $config['proxy_password'] ?? '';

            // curl 直呼び出し（名前空間や外部関数依存を排除）
            $execCurl = function($method, $url, $postData = null) use ($headers, $proxyEnabled, $proxyHost, $proxyPort, $proxyUsername, $proxyPassword) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                if ($postData !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                if ($proxyEnabled && !empty($proxyHost) && $proxyPort > 0) {
                    curl_setopt($ch, CURLOPT_PROXY, $proxyHost);
                    curl_setopt($ch, CURLOPT_PROXYPORT, $proxyPort);
                    if (!empty($proxyUsername)) curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$proxyUsername}:{$proxyPassword}");
                }
                $res = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return ['code' => $code, 'body' => $res];
            };

            $encodePath = function($p) { return implode('/', array_map('rawurlencode', explode('/', $p))); };
            $oldApiUrl = "https://api.github.com/repos/{$owner}/{$repo}/contents/" . $encodePath($oldGitPath);
            $newApiUrl = "https://api.github.com/repos/{$owner}/{$repo}/contents/" . $encodePath($newGitPath);

            // 1. 旧ファイルの SHA 取得
            $getRes = $execCurl('GET', $oldApiUrl . '?ref=' . rawurlencode($branch));
            $oldSha = null;
            if ($getRes['code'] === 200) {
                $oldData = json_decode($getRes['body'], true);
                $oldSha = $oldData['sha'] ?? null;
            }

            // 2. 新ファイル作成 (PUT)
            $putRes = $execCurl('PUT', $newApiUrl, json_encode([
                'message' => "Rename: {$oldName} -> {$newName}",
                'content' => base64_encode(file_get_contents($targetFile)),
                'branch'  => $branch
            ], JSON_UNESCAPED_UNICODE));

            if ($putRes['code'] >= 200 && $putRes['code'] < 300) {
                // 3. 旧ファイル削除 (DELETE)
                if ($oldSha) {
                    $execCurl('DELETE', $oldApiUrl, json_encode([
                        'message' => "Delete old path after rename: {$oldGitPath}",
                        'sha'     => $oldSha,
                        'branch'  => $branch
                    ], JSON_UNESCAPED_UNICODE));
                }
            } else {
                echo json_encode(['success' => false, 'error' => "GitHub新ファイル作成に失敗しました (HTTP {$putRes['code']})"], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    // ---------------------------------------------------------
    // ローカルフォルダをリネーム（.poc/アプリ名 のフォルダごと移動）
    // ---------------------------------------------------------
    if (!@rename($srcPath, $dstPath)) {
        $err = error_get_last();
        echo json_encode(['success' => false, 'error' => 'ローカルのリネームに失敗しました: ' . ($err['message'] ?? '')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================================
// 【バックアップ履歴一覧取得（get_history）】
// ==========================================================
// ==========================================================
// 【ソースコード履歴一覧取得（get_history）】
// ==========================================================
if ($api === 'get_history') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $req = !empty($data) ? $data : $_REQUEST;
    $path = $req['path'] ?? '';
    $appName = resolve_app_name($path);

    if (empty($appName)) {
        echo json_encode(['success' => false, 'error' => 'アプリ名を取得できません。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cleanPath = trim(str_replace('\\', '/', $path), '/');

    // .history フォルダの探索候補（優先度順）
    $candidates = [
        // 1. .poc/アプリ名/.history
        rtrim($base_dir, '/\\') . DIRECTORY_SEPARATOR . '.poc' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . '.history',
        // 2. 指定パス直下の .history
        rtrim($base_dir, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath) . DIRECTORY_SEPARATOR . '.history',
        // 3. $history_base_dir/アプリ名
        rtrim($history_base_dir, '/\\') . DIRECTORY_SEPARATOR . $appName,
        // 4. .poc/アプリ名/history（ドットなし）
        rtrim($base_dir, '/\\') . DIRECTORY_SEPARATOR . '.poc' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . 'history',
    ];

    $targetDir = null;
    foreach ($candidates as $dir) {
        if (is_dir($dir)) {
            $targetDir = $dir;
            break;
        }
    }

    $historyList = [];

    if ($targetDir && is_dir($targetDir)) {
        $files = scandir($targetDir);
        if ($files !== false) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || is_dir($targetDir . DIRECTORY_SEPARATOR . $file)) continue;

                $filePath = $targetDir . DIRECTORY_SEPARATOR . $file;
                if (is_file($filePath)) {
                    // ステータス判定
                    $status = 'draft';
                    if (stripos($file, 'published') !== false) {
                        $status = 'published';
                    } elseif (stripos($file, 'reverted') !== false) {
                        $status = 'reverted';
                    } elseif (stripos($file, 'original') !== false) {
                        $status = 'original';
                    }

                    $mtime = filemtime($filePath);
                    $formattedDate = ($mtime !== false) ? date('Y/m/d H:i:s', $mtime) : '';

                    $historyList[] = [
                        'filename' => $file,
                        'date'     => $formattedDate,
                        'status'   => $status,
                        'mtime'    => ($mtime !== false) ? $mtime : 0
                    ];
                }
            }

            // 更新日時の新しい順にソート
            usort($historyList, function($a, $b) {
                return $b['mtime'] <=> $a['mtime'];
            });
        }
    }

    echo json_encode(['success' => true, 'history' => $historyList], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================================
// 【バックアップからの復元（restore_history）】
// ==========================================================
if ($api === 'restore_history') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $req = !empty($data) ? $data : $_REQUEST;
    $path = $req['path'] ?? '';
    $historyFile = $req['history_file'] ?? ($req['file'] ?? '');

    if (empty($path) || empty($historyFile) || strpos($historyFile, '..') !== false) {
        echo json_encode(['success' => false, 'error' => '無効な指定です。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $appName = resolve_app_name($path);
    $cleanHistoryFile = basename($historyFile);
    $historyDir = get_app_history_directory($path, $history_base_dir, $base_dir);
    $srcPath = $historyDir . DIRECTORY_SEPARATOR . $cleanHistoryFile;

    // 復元先ディレクトリ
    $cleanPath = trim(str_replace('\\', '/', $path), '/');
    $targetPath = rtrim($base_dir, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath);

    // 復元先がフォルダなら index.php、ファイル直指定ならそのファイルへ上書き
    $destPath = is_dir($targetPath) ? ($targetPath . DIRECTORY_SEPARATOR . 'index.php') : $targetPath;

    if (file_exists($srcPath)) {
        $content = file_get_contents($srcPath);
        if ($content !== false && @file_put_contents($destPath, $content, LOCK_EX) !== false) {
            @chmod($destPath, 0666);

            // 復元した事実を reverted 履歴として新しく記録
            $ext = pathinfo($cleanHistoryFile, PATHINFO_EXTENSION) ?: 'php';
            $newTimestamp = date('Ymd_His');
            $newHistoryPath = $historyDir . DIRECTORY_SEPARATOR . $newTimestamp . "_reverted." . $ext;
            @file_put_contents($newHistoryPath, $content, LOCK_EX);
            @chmod($newHistoryPath, 0666);

            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'error' => '復元データの書き込みに失敗しました。'], JSON_UNESCAPED_UNICODE);
        }
    } else {
        echo json_encode(['success' => false, 'error' => '履歴ファイルが見つかりません: ' . $srcPath], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ==========================================================
// 【バックアップ履歴の削除（delete_history）】
// ==========================================================
if ($api === 'delete_history') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $req = !empty($data) ? $data : $_REQUEST;
    $path = $req['path'] ?? '';
    $file = $req['file'] ?? ($req['filename'] ?? '');

    if (empty($path) || empty($file) || strpos($file, '..') !== false) {
        echo json_encode(['success' => false, 'error' => '無効なパス指定です。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cleanFile = basename($file);
    $historyDir = get_app_history_directory($path, $history_base_dir, $base_dir);
    $targetFile = $historyDir . DIRECTORY_SEPARATOR . $cleanFile;

    if (file_exists($targetFile)) {
        if (@unlink($targetFile)) {
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'error' => '履歴ファイルの削除に失敗しました。'], JSON_UNESCAPED_UNICODE);
        }
    } else {
        echo json_encode(['success' => false, 'error' => '指定された履歴ファイルが見つかりません。'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
// ==========================================================
// 【新規アプリ作成API（create_app）】
// ==========================================================
// ==========================================================
// 【新規アプリ作成API（create_app）】※モックから開始
// ==========================================================
if ($api === 'create_app') {
    while (\ob_get_level()) { \ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    // 1. パラメータ取得とサニタイズ
    $rawName = trim($data['name'] ?? '');
    $name = basename(str_replace(['\\', '/'], '', $rawName));

    if ($name === '' || $name === '.' || $name === '..') {
        echo json_encode(['success' => false, 'error' => 'アプリ名が正しくありません。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 作成先パス: .poc/アプリ名/mock
    $pocBase = $poc_dir ?? ($base_dir . DIRECTORY_SEPARATOR . '.poc');
    $appBaseDir = rtrim($pocBase, '/\\') . DIRECTORY_SEPARATOR . $name;
    $newAppPath = $appBaseDir . DIRECTORY_SEPARATOR . 'mock';

    // 2. 重複チェック
    $publicAppPath = rtrim($base_dir, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (is_dir($appBaseDir) || is_dir($publicAppPath)) {
        echo json_encode([
            'success' => false, 
            'error'   => '「' . $name . '」は既に存在します。別の名前を入力してください。',
            'code'    => 'ALREADY_EXISTS'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. モックフォルダ（.poc/アプリ名/mock）の作成
    if (!is_dir($newAppPath)) {
        if (!@mkdir($newAppPath, 0775, true) && !is_dir($newAppPath)) {
            echo json_encode(['success' => false, 'error' => 'モック用ディレクトリを作成できませんでした。'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // 4. index.php の初期コンテンツ
    $indexPath = $newAppPath . DIRECTORY_SEPARATOR . 'index.php';
    $safeName  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    $initialTemplate = <<<TEMPLATE
<h1>{$safeName}（モック）が作成されました</h1>
<p>画面の下左側の「ChatAIプロンプト」を使って、作りたいアプリのイメージを書きましょう！</p>
<p>ChatAIにアプリのイメージを伝えてモックのソースを作ってもらいましょう！</p>
<p>今度は右側の「ソース貼付け」を使って、ChatAIが作ったソースを貼り付けて保存しましょう！</p>
TEMPLATE;

    // 5. prompt.txt の初期テンプレート
    $initialPrompt = <<<PROMPT
#目的・ゴール
------------>>>アプリの目的とゴール（これができていればOKみたいなこと）を教えてください。



#アプリの画面構成・イメージ
------------>>>誰が使うのか、画面にどんな項目・ボタンを並べたいかざっくり教えてください。



#アプリに渡す情報
------------>>>Excelなどの入力データがあれば、その項目やファイルの説明を書いてください。



#アプリに期待する結果
------------>>>まずはモックアップとして画面のデザイン・レイアウトを確認したい。



------------>>>修正追加したい機能や、AIが何度も指示を間違える場合に追記してください。
以上のプロンプトで生成したモックのindex.phpについて、修正追加や、同じミスを何度も繰り返さないこと。



------------>>>以下は消さずにそのままコピーしてChatAIに渡してください。
PROMPT;

    $promptPath = $newAppPath . DIRECTORY_SEPARATOR . 'prompt.txt';

    // 6. 実ファイルをローカル保存
    $indexCreated  = (@file_put_contents($indexPath, $initialTemplate, LOCK_EX) !== false);
    $promptCreated = (@file_put_contents($promptPath, $initialPrompt, LOCK_EX) !== false);

    if (!$indexCreated || !$promptCreated) {
        echo json_encode(['success' => false, 'error' => '初期ファイルの作成に失敗しました。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    @chmod($indexPath, 0666);
    @chmod($promptPath, 0666);

    // 7. GitHub設定ファイルの配置（新規作成時は必ず enabled: false で強制初期化）
    $appGitDir = $appBaseDir . DIRECTORY_SEPARATOR . '.github';
    if (!is_dir($appGitDir)) {
        @mkdir($appGitDir, 0775, true);
    }
    $appConfigFile = $appGitDir . DIRECTORY_SEPARATOR . 'config.json';
    $globalConfigFile = $github_config_file ?? ($base_dir . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'config.json');

    $initialGitConfig = ['enabled' => false];
    if (is_file($globalConfigFile)) {
        $loadedGlobal = @json_decode(@file_get_contents($globalConfigFile), true);
        if (is_array($loadedGlobal)) {
            $initialGitConfig = $loadedGlobal;
            $initialGitConfig['enabled'] = false; // ★ 必ずOFFで作成
        }
    }
    @file_put_contents($appConfigFile, json_encode($initialGitConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($appConfigFile, 0666);

    // 8. 初回履歴のローカル保存
    $historyDir = rtrim($history_base_dir, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($historyDir)) {
        @mkdir($historyDir, 0775, true);
    }
    $timestamp = date('Ymd_His');
    @file_put_contents($historyDir . DIRECTORY_SEPARATOR . $timestamp . "_mock_original.php", $initialTemplate, LOCK_EX);
    @file_put_contents($historyDir . DIRECTORY_SEPARATOR . $timestamp . "_mock_prompt_original.txt", $initialPrompt, LOCK_EX);

    // 9. URLの組み立て（実際の通信は行わずURL文字列のみ返す）
    $owner  = trim($initialGitConfig['owner'] ?? $initialGitConfig['username'] ?? $initialGitConfig['user'] ?? '');
    $repo   = trim($initialGitConfig['repo'] ?? $initialGitConfig['repository'] ?? '');
    $branch = trim($initialGitConfig['branch'] ?? 'main') ?: 'main';

    $promptRawUrl = '';
    $mockRawUrl   = '';
    if ($owner !== '' && $repo !== '') {
        $promptRawUrl = 'https://raw.githubusercontent.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/' . rawurlencode($branch) . '/.poc/' . rawurlencode($name) . '/mock/prompt.txt';
        $mockRawUrl   = 'https://raw.githubusercontent.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/' . rawurlencode($branch) . '/.poc/' . rawurlencode($name) . '/mock/index.php';
    }

    echo json_encode([
        'success'        => true,
        'app_name'       => $name,
        'status'         => 'mock',
        'path'           => '.poc/' . $name . '/mock',
        'initial_code'   => $initialTemplate,
        'initial_prompt' => $initialPrompt,
        'prompt_raw_url' => $promptRawUrl,
        'mock_raw_url'   => $mockRawUrl
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// 【削除API】
// ==========================================
if ($api === 'delete') {
    while (\ob_get_level()) { \ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $target = $data['target'] ?? $_REQUEST['target'] ?? '';
    if (empty($target)) {
        echo json_encode(['success' => false, 'error' => '削除対象が指定されていません。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // パスを正規化
    $targetNormalized = trim(str_replace('\\', '/', $target), '/');

    // 1. 純粋なアプリ名を抽出（.poc や draft などを除去）
    $parts = array_values(array_filter(explode('/', $targetNormalized), function($p) {
        return $p !== '' && $p !== 'gojacic' && $p !== '.poc' && $p !== 'poc' && $p !== 'draft' && $p !== 'mock' && $p !== 'published' && $p !== 'index.php';
    }));
    $appName = !empty($parts) ? reset($parts) : basename($targetNormalized);

    if (empty($appName) || $appName === 'draft' || $appName === '.poc') {
        echo json_encode(['success' => false, 'error' => '有効なアプリ名を特定できませんでした。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. 公開中ガード
    $publishedPath = (isset($published_dir) ? rtrim($published_dir, DIRECTORY_SEPARATOR) : ($base_dir . DIRECTORY_SEPARATOR . 'published')) . DIRECTORY_SEPARATOR . $appName;
    if (is_dir($publishedPath)) {
        echo json_encode(['success' => false, 'error' => 'このアプリは現在「公開中」のため、削除することはできません。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. 削除対象のルートフォルダを特定（.poc/アプリ名 フォルダ全体を削除）
    $pocBase = isset($poc_dir) ? rtrim($poc_dir, DIRECTORY_SEPARATOR) : (rtrim($base_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.poc');
    $targetAppPath = $pocBase . DIRECTORY_SEPARATOR . $appName;

    if (!is_dir($targetAppPath)) {
        $directPath = rtrim($base_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target), DIRECTORY_SEPARATOR);
        if (is_dir($directPath)) {
            $targetAppPath = $directPath;
        }
    }

    // 4. 設定読み込み（GitHub削除用）
    $appConfigFile    = $pocBase . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'config.json';
    $globalConfigFile = $github_config_file ?? (isset($github_dir) ? rtrim($github_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json' : ($base_dir . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'config.json'));

    $globalConfig = is_file($globalConfigFile) ? (@json_decode(@file_get_contents($globalConfigFile), true) ?: []) : [];
    $appConfig    = is_file($appConfigFile) ? (@json_decode(@file_get_contents($appConfigFile), true) ?: []) : [];
    $cfg          = array_merge($globalConfig, $appConfig);

    $token  = trim($cfg['token'] ?? $cfg['access_token'] ?? $cfg['pat'] ?? '');
    $owner  = trim($cfg['owner'] ?? $cfg['username'] ?? $cfg['user'] ?? '');
    $repo   = trim($cfg['repo'] ?? $cfg['repository'] ?? '');
    $branch = trim($cfg['branch'] ?? 'main') ?: 'main';

    // 5. GitHub上のファイル削除
    $githubDeleteSuccess = true;
    $githubDeleteMessage = '';

    if ($token !== '' && $owner !== '' && $repo !== '') {
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: PHP-GitHub-App',
            'X-GitHub-Api-Version: 2022-11-28'
        ];
        $proxyOpts = [
            !empty($cfg['proxy_enabled']),
            trim($cfg['proxy_host'] ?? ''),
            (int)($cfg['proxy_port'] ?? 0),
            trim($cfg['proxy_username'] ?? ''),
            $cfg['proxy_password'] ?? ''
        ];

        // 削除対象になり得る代表的ファイル群
        $filesToDelete = [
            ".poc/{$appName}/draft/index.php",
            ".poc/{$appName}/draft/prompt.txt",
            ".poc/{$appName}/mock/index.php",
            ".poc/{$appName}/mock/prompt.txt",
            ".poc/{$appName}/index.php",
            "published/{$appName}/index.php"
        ];
        $deletedCount = 0;
        foreach ($filesToDelete as $gitPath) {
            $gitUrl = "https://api.github.com/repos/" . rawurlencode($owner) . "/" . rawurlencode($repo) . "/contents/" . implode('/', array_map('rawurlencode', explode('/', $gitPath)));

            // SHA取得
            $getRes = github_http_request('GET', $gitUrl . '?ref=' . rawurlencode($branch), $headers, null, ...$proxyOpts);
            if ((int)($getRes['http_code'] ?? 0) === 200) {
                $sha = json_decode($getRes['body'] ?? '', true)['sha'] ?? '';
                if ($sha) {
                    $delBody = json_encode([
                        'message' => "Delete: {$gitPath}",
                        'sha'     => $sha,
                        'branch'  => $branch
                    ], JSON_UNESCAPED_UNICODE);

                    $delRes = github_http_request('DELETE', $gitUrl, array_merge($headers, ['Content-Type: application/json']), $delBody, ...$proxyOpts);
                    if ((int)($delRes['http_code'] ?? 0) >= 200 && (int)($delRes['http_code'] ?? 0) < 300) {
                        $deletedCount++;
                    }
                }
            }
        }

        if ($deletedCount > 0) {
            $githubDeleteMessage = 'GitHubからも対象ファイルを削除しました。';
        } else {
            $githubDeleteMessage = 'GitHub上に対象ファイルはありませんでした。';
        }
    }

    // 6. ディレクトリ再帰削除ヘルパー（★誤記を修正）
    $deleteDirFunc = function ($dir) use (&$deleteDirFunc) {
        if (!file_exists($dir)) return true;
        if (!is_dir($dir)) {
            @chmod($dir, 0666);
            return @unlink($dir);
        }
        $items = scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                if (!$deleteDirFunc($dir . DIRECTORY_SEPARATOR . $item)) return false;
            }
        }
        if (function_exists('gc_collect_cycles')) gc_collect_cycles();
        @chmod($dir, 0777);
        return @rmdir($dir);
    };

    // 7. ローカル関連データおよび本体フォルダの削除
    if (is_dir($targetAppPath)) {
        if (!$deleteDirFunc($targetAppPath)) {
            $err = error_get_last();
            echo json_encode(['success' => false, 'error' => 'フォルダの削除に失敗しました: ' . ($err['message'] ?? '')], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if (isset($history_base_dir)) $deleteDirFunc($history_base_dir . DIRECTORY_SEPARATOR . $appName);
    if (isset($prompt_base_dir))  $deleteDirFunc($prompt_base_dir . DIRECTORY_SEPARATOR . $appName);
    if (isset($github_dir))       $deleteDirFunc($github_dir . DIRECTORY_SEPARATOR . $appName);

    // 8. レスポンス返却
    echo json_encode([
        'success'        => true,
        'local_deleted'  => true,
        'github_deleted' => $githubDeleteSuccess,
        'message'        => $githubDeleteMessage ?: '削除が完了しました。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($api === 'backup_app') {
    // レスポンスを強制的にJSONにする
    header('Content-Type: application/json; charset=utf-8');

    // PHPエラーをキャッチしてJSONで返す設定
    error_reporting(E_ALL);
    ini_set('display_errors', 0);

    try {
        if (!class_exists('\ZipArchive')) {
            throw new \Exception('PHPのZip拡張機能が無効です。');
        }

        // POST/JSONリクエストデータ取得
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        
        $appName = $data['app_name'] ?? $_POST['app_name'] ?? '';

        if (empty($appName)) {
            throw new \Exception('アプリ名が指定されていません。');
        }

        // アプリ名の正規化
		$parts = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $appName), '/')), function($p) {
		            return $p !== '' && $p !== '.poc' && $p !== 'poc' && $p !== 'draft' && $p !== 'mock' && $p !== 'published';
		        }));        
        $appName = !empty($parts) ? end($parts) : basename($appName);

        // ディレクトリパスの設定（新構成: .poc/アプリ名/draft）
        $document_root    = realpath(__DIR__);
        $base_dir         = $document_root . DIRECTORY_SEPARATOR . 'gojacic';
        $poc_app_dir      = $base_dir . DIRECTORY_SEPARATOR . '.poc' . DIRECTORY_SEPARATOR . $appName;
        $history_app_dir  = $base_dir . DIRECTORY_SEPARATOR . '.history' . DIRECTORY_SEPARATOR . $appName;
        $prompt_app_dir   = $base_dir . DIRECTORY_SEPARATOR . '.prompt' . DIRECTORY_SEPARATOR . $appName;
        $github_app_dir   = $base_dir . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . $appName;

        // ZIP内のフォルダ階層 => 実際のディレクトリパス
        $targets = [
            '.poc/' . $appName     => $poc_app_dir,
            '.history/' . $appName => $history_app_dir,
            '.prompt/' . $appName  => $prompt_app_dir,
            '.github/' . $appName  => $github_app_dir
        ];

        // tmp_backups フォルダ作成
        $backupDir = $document_root . DIRECTORY_SEPARATOR . 'tmp_backups';
        if (!file_exists($backupDir)) {
            if (!@mkdir($backupDir, 0777, true)) {
                throw new \Exception('tmp_backups フォルダの作成に失敗しました。');
            }
        }

        // ZIPファイル作成
        $zipFilename = date('Ymd_His') . '_' . $appName . '_backup.zip';
        $zipPath = $backupDir . DIRECTORY_SEPARATOR . $zipFilename;

        $zip = new \ZipArchive();
        $res = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($res !== TRUE) {
            throw new \Exception('ZIP生成に失敗しました。(コード: ' . $res . ')');
        }

        $hasFile = false;

        // ディレクトリ走査用関数
        $addDirToZip = function ($baseDir, $currentDir, $prefix) use (&$zip, &$hasFile, &$addDirToZip) {
            $items = @scandir($currentDir);
            if ($items === false) return;

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;

                $fullPath = $currentDir . DIRECTORY_SEPARATOR . $item;
                $subPath = substr($fullPath, strlen($baseDir));
                $relativePath = $prefix . '/' . ltrim(str_replace('\\', '/', $subPath), '/');

                if (is_dir($fullPath)) {
                    $zip->addEmptyDir($relativePath);
                    $addDirToZip($baseDir, $fullPath, $prefix);
                } else if (is_file($fullPath)) {
                    $zip->addFile($fullPath, $relativePath);
                    $hasFile = true;
                }
            }
        };

        // 各ターゲットディレクトリを走査してZIPに追加
        foreach ($targets as $prefix => $dirPath) {
            if (file_exists($dirPath) && is_dir($dirPath)) {
                $realDirPath = realpath($dirPath);
                if ($realDirPath !== false) {
                    $addDirToZip($realDirPath, $realDirPath, $prefix);
                }
            }
        }

        $zip->close();

        if (!$hasFile) {
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
            throw new \Exception('バックアップ対象のファイルが存在しませんでした。');
        }

        echo json_encode([
            'success' => true,
            'download_url' => 'tmp_backups/' . $zipFilename
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (\Throwable $e) {
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

    echo json_encode(['success' => false, 'error' => '不明なAPIアクションです。'], JSON_UNESCAPED_UNICODE);
    exit;
}
		// 安全にJSで扱えるようJSON化
		$json_harness_init = json_encode($harness_combined_text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
// $document_root と $base_dir の差分から Web上のベースパスを自動算出 (例: '/gojacic/')
$web_base_path = '/' . trim(str_replace([$document_root, '\\'], ['', '/'], $base_dir), '/') . '/';
if ($web_base_path === '//') $web_base_path = '/';

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>gojacic 管理画面</title>
    <style>
		body {
		    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
		    margin: 0;
		    background: #f8fafc;
		    display: flex;
		    height: 100vh;
		    overflow: hidden;
		    user-select: none;
		    color: #1e293b;
		}

/* --------------------------------------------------
           🎨 共通スクロールバーカスタマイズ (安全・掴みやすい固定幅設計)
        -------------------------------------------------- */
        ::-webkit-scrollbar {
            /* レイアウト崩れを防ぐため、縦・横ともに12pxの固定幅にします */
            width: 12px;
            height: 12px;
        }
        ::-webkit-scrollbar-track {
            /* トラック（背景）を透明にして存在感を消し、デザインに馴染ませます */
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            /* つまみの色。透明な境界線(border)を使わず、シンプルに色と角丸だけで構成します */
            background: #cbd5e1;
            border-radius: 6px;
        }
        /* マウスホバー時に色を一段階濃くして、視覚的に掴んでいることを認識しやすくします */
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        /* クリック中の色 */
        ::-webkit-scrollbar-thumb:active {
            background: #64748b;
        }

		/* --------------------------------------------------
		   📦 3カラム構成レイアウト
		-------------------------------------------------- */
/* --------------------------------------------------
   📦 3カラム構成レイアウト
-------------------------------------------------- */
.sidebar-col { 
    /* width: 250px; ← 固定幅を廃止します */
    flex: 1.5;         /* 全体に対して1.5の比率 */
    min-width: 150px;  /* 縮みすぎて崩れるのを防ぐ最小幅 */
    background: #ffffff; 
    display: flex; 
    flex-direction: column; 
    border-right: 1px solid #e2e8f0;
}		

		/* 左右・上下リサイズ用の境界線 */
		.resizer {
		    width: 4px;
		    background: #e2e8f0;
		    cursor: col-resize;
		    transition: background 0.2s, width 0.2s;
		    z-index: 10;
		}
		.resizer:hover, .resizer.dragging {
		    background: #0ea5e9;
		    width: 4px;
		}

		.resizer-v {
		    height: 4px;
		    background: #e2e8f0;
		    cursor: row-resize;
		    transition: background 0.2s, height 0.2s;
		    z-index: 10;
		    flex-shrink: 0;
		}
		.resizer-v:hover, .resizer-v.dragging {
		    background: #0ea5e9;
		    height: 4px;
		}

		#resizer-3:hover,
		.resizer:hover,
		[id^="resizer"]:hover {
		    background-color: #0ea5e9 !important;
		}

.main-col { 
    flex: 8;           /* 全体に対して8の比率（これで合計11となり、1.5:1.5:8の割合になります） */
    display: flex; 
    flex-direction: column; 
    background: #f1f5f9; 
    min-width: 300px;
    height: 100vh;
    overflow: hidden;
}
		/* 📂 ファイルツリーエリア */
		.tree-container { 
		    flex: 1; 
		    overflow-y: auto; 
		    padding: 16px; 
		}
		.tree-title { 
		    font-weight: 600; 
		    font-size: 13px; 
		    margin-bottom: 12px; 
		    color: #475569; 
		    padding-bottom: 8px; 
		    border-bottom: 1px solid #e2e8f0;
		    text-transform: uppercase;
		    letter-spacing: 0.05em;
		}

		summary { 
		    cursor: pointer; 
		    padding: 6px 8px; 
		    outline: none; 
		    list-style: none; 
		    display: flex; 
		    align-items: center; 
		    border-radius: 6px; 
		    font-size: 13px;
		    color: #334155;
		    transition: background 0.15s, color 0.15s;
		}
		summary::-webkit-details-marker { display: none; }
		summary:hover { background: #f1f5f9; color: #0f172a; }
		summary.active { background: #e0f2fe; color: #0369a1; font-weight: 600; }
		details { margin-left: 10px; }
		details > details { margin-left: 14px; }

		/* 🖥️ プレビューエリア（上：3） */
		#preview-area {
		    background: #ffffff;
		    display: flex;
		    flex-direction: column;
		    min-height: 100px;
		    height: 75%;
		    flex-shrink: 0;
		    overflow: hidden;
		}
		.preview-header {
		    padding: 10px 16px;
		    background: #f8fafc;
		    font-size: 12px;
		    font-weight: 600;
		    color: #64748b;
		    border-bottom: 1px solid #e2e8f0;
		    display: flex;
		    justify-content: space-between;
		    align-items: center;
		    flex-shrink: 0;
		}
		#preview-frame {
		    width: 100%;
		    flex: 1;
		    border: none;
		    background: #ffffff;
		}

		/* iframeドラッグの干渉防止カバー */
		.iframe-cover {
		    position: absolute;
		    top: 0; left: 0; right: 0; bottom: 0;
		    z-index: 9;
		    display: none;
		}

		/* ✍️ 左側エディタエリア */
		#editor-area { 
		    flex: 55;            
		    display: flex; 
		    flex-direction: column; 
		    background: #ffffff; 
		    padding: 12px; 
		    min-width: 150px;
		    height: 100%;       
		    box-sizing: border-box;
		    overflow: hidden;
		}

		.editor-container {
		    display: flex;
		    flex: 1;
		    min-height: 0;     
		    border: 1px solid #cbd5e1;
		    border-radius: 6px;
		    overflow: hidden;
		    background: #ffffff;
		    font-family: "Fira Code", Consolas, Monaco, monospace;
		    font-size: 13px;
		    line-height: 1.6;
		}

		.line-numbers {
		    width: 45px;
		    text-align: right;
		    padding: 12px 10px 12px 0;
		    background: #f8fafc;
		    color: #94a3b8;
		    user-select: none;
		    overflow: hidden;
		    box-sizing: border-box;
		    border-right: 1px solid #e2e8f0;
		    font-family: inherit;
		    font-size: inherit;
		    line-height: inherit;
		    white-space: pre;
		}

		textarea { 
		    width: 100%; 
		    flex: 1; 
		    resize: none; 
		    font-family: inherit; 
		    font-size: inherit; 
		    line-height: inherit;
		    padding: 12px; 
		    box-sizing: border-box; 
		    border: none;
		    outline: none;
		    background: transparent;
		    margin: 0;
		    overflow-y: auto;
		    white-space: pre;
		    overflow-x: auto;
		    color: #0f172a;
		}

		.toolbar { 
		    padding: 0 0 10px 0; 
		    display: flex; 
		    gap: 8px; 
		    align-items: center; 
		    flex-shrink: 0; 
		}
		#editor-warning { 
		    color: #ef4444; 
		    font-size: 12px; 
		    font-weight: 600; 
		    margin-left: auto; 
		}

		/* 🛠️ コンテキストメニュー */
		#context-menu { 
		    display: none; 
		    position: absolute; 
		    background: #ffffff; 
		    border: 1px solid #e2e8f0; 
		    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08); 
		    z-index: 1000; 
		    font-size: 12px; 
		    border-radius: 6px; 
		    padding: 4px 0;
		}
		.menu-item { 
		    padding: 8px 16px; 
		    cursor: pointer; 
		    position: relative;
		    display: flex;
		    justify-content: space-between;
		    align-items: center;
		    gap: 12px;
		    color: #334155;
		}
		.menu-item:hover { 
		    background: #f1f5f9; 
		    color: #0f172a;
		}

		.submenu {
		    display: none;
		    position: absolute;
		    left: 100%;
		    top: -4px;
		    background: #ffffff;
		    border: 1px solid #e2e8f0; 
		    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08); 
		    border-radius: 6px;
		    min-width: 220px;
		    max-height: 300px;
		    overflow-y: auto;
		    padding: 4px 0;
		    z-index: 1001;
		}
		.has-submenu:hover .submenu {
		    display: block;
		}

		.published-badge { 
		    font-size: 10px; 
		    background: #e0f2fe; 
		    color: #0284c7; 
		    padding: 2px 6px; 
		    border-radius: 4px; 
		    margin-left: 6px; 
		    font-weight: 600;
		}
		.drag-over { 
		    background: #f0fdf4 !important; 
		}

		/* 🗂️ タブコントロール（見出し部分） */
		.tabs { 
		    display: flex; 
		    border-bottom: 1px solid #e2e8f0; 
		    gap: 4px; 
		    margin-top: 5px; 
		    margin-bottom: 0px;
		    overflow-x: auto; 
		    flex-shrink: 0;
		}
		.tab { 
		    padding: 6px 14px; 
		    background: #f1f5f9; 
		    border: 1px solid #e2e8f0; 
		    border-bottom: none; 
		    border-radius: 6px 6px 0 0; 
		    cursor: pointer; 
		    font-size: 12px; 
		    white-space: nowrap; 
		    color: #64748b;
		    transition: background 0.15s, color 0.15s;
		}
		.tab:hover {
		    background: #e2e8f0;
		    color: #334155;
		}
		.tab.active { 
		    background: #ffffff; 
		    border-bottom: 1px solid #ffffff; 
		    margin-bottom: -1px; 
		    font-weight: 600; 
		    color: #0f172a;
		}

		.pane { 
		    display: none !important; 
		    padding: 8px; 
		    background: #ffffff; 
		    border: 1px solid #e2e8f0; 
		    border-top: none; 
		    box-sizing: border-box; 
		}

		/* 🏢 下部コンテナコンテキスト */
		.bottom-container {
		    display: flex;
		    flex-direction: row;
		    flex: 1;              
		    min-height: 120px;
		    width: 100%;
		    overflow: hidden;
		}

		#split-area {
		    flex: 45;            
		    min-width: 200px;
		    height: 100%;
		    background: #ffffff;
		    display: flex;
		    flex-direction: column;
		    padding: 12px;
		    box-sizing: border-box;
		    overflow: hidden;
		}

		#split-area .toolbar {
		    margin-bottom: 10px;
		    flex-shrink: 0;
		}

		.split-result-scroll {
		    flex: 1 1 auto !important;
		    min-height: 0 !important; 
		    display: flex !important;
		    flex-direction: column !important;
		    height: 100%;
		}

		#res {
		    display: flex !important;
		    flex-direction: column !important;
		    height: 100% !important;
		    min-height: 0 !important;
		}

		#tabW {
		    flex-shrink: 0 !important;
		}

		.split-panes-container {
		    flex: 1 1 auto !important;
		    display: flex !important;
		    flex-direction: column !important;
		    min-height: 0 !important;
		    height: 100% !important;
		}

		.pane.active {
		    display: flex !important;
		    flex-direction: column !important;
		    flex: 1 1 auto !important;
		    min-height: 0 !important;
		    height: 100% !important;
		}

		.pane textarea {
		    flex: 1 1 auto !important;
		    height: 100% !important;
		    box-sizing: border-box !important;
		}

		/* 🌟 ウェルカムスクリーン（プレビュー画面初期表示） */
		.welcome-body {
		    position: absolute;
		    top: 38px;
		    left: 0;
		    width: 100%;
		    height: calc(100% - 38px);
		    display: flex;
		    justify-content: center;
		    align-items: center;
		    background-color: #f8fafc;
		    color: #0f172a;
		    box-sizing: border-box;
		    z-index: 5;
		}

		.welcome-message-container {
		    text-align: center;
		    display: flex;
		    flex-direction: column;
		    align-items: center;
		    gap: 24px;
		}

		.welcome-message {
		    text-align: center;
		    padding: 0 24px;
		    font-size: 18px;
		    font-weight: 700;
		    line-height: 1.6;
		    color: #1e293b;
		}

		.welcome-create-btn {
		    background-color: #0284c7;
		    color: #ffffff;
		    border: none;
		    padding: 12px 28px;
		    font-size: 14px;
		    font-weight: 600;
		    border-radius: 8px;
		    cursor: pointer;
		    transition: background-color 0.2s, transform 0.1s, box-shadow 0.2s;
		    box-shadow: 0 4px 10px rgba(2, 132, 199, 0.2);
		}

		.welcome-create-btn:hover {
		    background-color: #0369a1;
		    transform: translateY(-1px);
		    box-shadow: 0 6px 14px rgba(2, 132, 199, 0.3);
		}

		.welcome-create-btn:active {
		    transform: translateY(1px);
		    box-shadow: 0 2px 4px rgba(2, 132, 199, 0.1);
		}

		/* --------------------------------------------------
		   💾 プロンプト保存履歴の縦カラム固定化スタイル
		-------------------------------------------------- */
		#prompt-history-list {
		    flex-wrap: nowrap !important;
		    flex-direction: column !important;
		    gap: 8px !important;
		    overflow-y: auto;
		    width: 100%;
		}

		#prompt-history-list button {
		    width: 100% !important;
		    box-sizing: border-box;
		    text-align: left;
		    padding: 10px 14px;
		    background: #ffffff;
		    border: 1px solid #e2e8f0;
		    border-radius: 6px;
		    cursor: pointer;
		    font-size: 13px;
		    transition: background 0.15s, border-color 0.15s, box-shadow 0.15s;
		    color: #334155;
		    display: block;
		    white-space: nowrap;
		    overflow: hidden;
		    text-overflow: ellipsis;
		}

		#prompt-history-list button:hover {
		    background: #f8fafc;
		    border-color: #0ea5e9;
		    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
		}

/* --------------------------------------------------
   👁️ 履歴確認用モーダル（ポップアップ）CSS
-------------------------------------------------- */
.history-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background-color: rgba(15, 23, 42, 0.4);
    backdrop-filter: blur(4px);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10000;
}

.history-modal-content {
    background: #ffffff;
    width: 92%;
    max-width: 850px; /* 620pxから拡大：プロンプトを広く見せるため */
    max-height: 90vh; /* 85vhから拡大 */
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    animation: modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: scale(0.96) translateY(-8px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

.history-modal-body {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 18px;
    overflow-y: auto;
    box-sizing: border-box;
    /* --- 以下の3行を追加して親要素を限界まで広げます --- */
    flex-grow: 1;         
    height: 100%;         
    max-height: 100%;     
}

.modal-text-box {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: #f8fafc;
    font-size: 13px;
    color: #334155;
    box-sizing: border-box;
    transition: border-color 0.15s;
}

div.modal-text-box {
    min-height: 40px;
    line-height: 1.5;
    word-break: break-all;
    user-select: text;
}

textarea.modal-text-box {
    height: 450px; /* 280pxから大幅に拡大 */
    resize: none;
    font-family: inherit;
    line-height: 1.55;
    outline: none;
    overflow-y: auto;
    overflow-x: auto;
    white-space: pre;
    user-select: text;
}
textarea.modal-text-box:focus {
    border-color: #94a3b8;
}

/* フッターとコピーボタン用の追加スタイル */
.history-modal-footer {
    padding: 16px 24px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    flex-shrink: 0;
}

.history-btn-copy {
    padding: 10px 20px;
    background-color: #007acc;
    color: #ffffff;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.15s, transform 0.1s;
}

.history-btn-copy:hover {
    background-color: #005999;
}

.history-btn-copy:active {
    transform: scale(0.98);
}

.history-btn-copy.success {
    background-color: #10b981 !important; /* 美しいエメラルドグリーン */
}

.history-btn-close {
    padding: 10px 16px;
    background-color: #ffffff;
    color: #475569;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.15s;
}

.history-btn-close:hover {
    background-color: #f1f5f9;
}
/* 画面全体のブロック画面 */
#loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background-color: rgba(0, 0, 0, 0.6); /* 暗めの半透明 */
    z-index: 99999; /* 他のどの要素よりも手前に表示 */
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    pointer-events: auto; /* 下の要素のクリック・ホバーを完全に無効化 */
    
    /* 初期状態は非表示（隠しておく） */
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s ease;
}

/* 表示指令用クラス */
#loading-overlay.visible {
    opacity: 1;
    visibility: visible;
}

/* くるくる回る白いサークル */
.spinner {
    width: 60px;
    height: 60px;
    border: 6px solid rgba(255, 255, 255, 0.2);
    border-top: 6px solid #fff;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* 処理中の文言用スタイル */
#loading-text {
    color: #fff;
    font-size: 18px;
    font-weight: bold;
    letter-spacing: 1px;
    font-family: sans-serif;
    text-shadow: 0 2px 4px rgba(0,0,0,0.5); /* 文字を見やすく */
}

/* フォルダ選択時のオーバーレイ画面 */
.folder-status-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: #f8fafc;
    color: #334155;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    padding: 20px;
    z-index: 10; /* iframeの上に重ねる */
}

.folder-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    padding: 28px;
    max-width: 460px;
    width: 100%;
    text-align: center;
    border: 1px solid #e2e8f0;
}

.folder-card-title {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 22px;
    color: #0f172a;
}

.folder-status-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    text-align: left;
    margin-bottom: 20px;
}

.status-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    color: #64748b;
    cursor: pointer;
    transition: all 0.2s ease;
    user-select: none;
}

.status-item:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    transform: translateY(-2px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.status-item.active-public {
    background: #f0fdf4;
    border-color: #86efac;
    border-left: 5px solid #22c55e;
    color: #15803d;
    font-weight: 700;
}

.status-item.active-draft {
    background: #f0f9ff;
    border-color: #7dd3fc;
    border-left: 5px solid #0284c7;
    color: #0369a1;
    font-weight: 700;
}

.status-item.active-mock {
    background: #fffbeb;
    border-color: #fde68a;
    border-left: 5px solid #d97706;
    color: #b45309;
    font-weight: 700;
}

.status-label {
    display: flex;
    align-items: center;
    gap: 10px;
}

.current-tag {
    font-size: 11px;
    font-weight: bold;
    padding: 2px 6px;
    border-radius: 4px;
}

.folder-card-desc {
    font-size: 12px;
    color: #94a3b8;
    line-height: 1.5;
}
    </style>
</head>
<body>
<div id="loading-overlay">
    <!-- くるくる回るアニメーション -->
    <div class="spinner"></div>
    <!-- 💡 ここに「処理中...」などのメッセージが動的に入ります -->
    <div id="loading-text">処理中...</div>
</div>

<!-- ドラッグ中にiframeにマウスが吸い込まれて挙動が重くなるのを防ぐカバー -->
<div id="iframe-cover" class="iframe-cover"></div>

<!-- ========================================== -->
<!-- 1. 左側エリア: フォルダツリー（公開中・作成中） -->
<!-- ========================================== -->

<!-- 公開中（Public）専用カラム -->
<div id="col-public" class="sidebar-col">
    <div class="tree-container">
        <div class="tree-title">公開中</div>
        <div id="tree-public"></div>
    </div>
</div>

<!-- 左右スプリッター 1 -->
<div class="resizer" id="resizer-1"></div>

<!-- 作成中（Draft）専用カラム -->
<div id="col-draft" class="sidebar-col">

    <div class="tree-container"
         ondragover="allowDrop(event)"
         ondrop="dropToDraftRoot(event)"
         style="display: flex; flex-direction: column; height: 100%; overflow: hidden;">

        <!-- 作成中 見出し -->
        <div class="tree-title"
             style="flex-shrink: 0;">
            作成中
        </div>

        <!-- 新規アプリ作成ボタン：見出し直下に固定 -->
        <div style="padding: 10px; border-bottom: 1px solid #eee; flex-shrink: 0; background: #fff; z-index: 2;">

            <button onclick="createNewApp()"
                    style="width: 100%; padding: 8px; cursor: pointer;">
                ＋ 🚀 新規アプリ作成
            </button>

        </div>

        <!-- 作成中ツリー：ここだけスクロール -->
        <div id="tree-draft"
             style="flex: 1; min-height: 0; overflow-y: auto;">
        </div>

        <!-- GitHub同期設定：作成中ペイン最下部に固定 -->
        <div style="padding: 10px; border-top: 1px solid #eee; flex-shrink: 0; background: #fff; z-index: 2;">

            <button id="btn-github-setting"
                    onclick="openGitHubSettings()"
                    style="width: 100%; padding: 8px; cursor: pointer; font-weight: bold;">
                GitHub同期設定
            </button>

        </div>

    </div>

</div>

<!-- 左右スプリッター 2 -->
<div class="resizer" id="resizer-2"></div>


<!-- ========================================== -->
<!-- 2. 右側エリア: メインコンテンツ -->
<!-- ========================================== -->

<div class="main-col" style="position: relative;">

    <!-- ドラッグ中用の iframe カバー -->
    <div id="iframe-cover"
         style="display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 9999; background: transparent;">
    </div>

<!-- 上部: プレビュー領域 -->
<div id="preview-area" style="position: relative;">

    <div class="preview-header">
        <span>リアルタイムプレビュー画面</span>

        <div>
            <button type="button"
                    onclick="reloadPreview()"
                    style="cursor: pointer; padding: 2px 8px;">
                更新
            </button>
        </div>
    </div>

    <iframe id="preview-frame"
            title="リアルタイムプレビュー"
            src="about:blank"
            sandbox="allow-forms allow-modals allow-pointer-lock allow-popups allow-scripts"
            style="display: none; width: 100%; height: calc(100% - 32px); border: none;">
    </iframe>

    <div id="welcome-message-area"
         class="welcome-body"
         style="display: flex;">

        <div class="welcome-message-container">

            <div class="welcome-message">
                ChatAIと会話しながら、<br>
                新しいWebアプリを作りましょう。
            </div>

            <button type="button"
                    class="welcome-create-btn"
                    onclick="createNewApp()">
                🚀 新規アプリ作成
            </button>

        </div>

    </div>
	<!-- 上部: プレビュー領域 フォルダ選択時コンテンツ 　-->

	<!-- ★ 追加: フォルダ選択時の進捗・選択カード画面 -->
	<div id="folder-status-overlay" class="folder-status-overlay" style="display: none;">
	    <div class="folder-card">
	        <div id="folder-card-title" class="folder-card-title">📁 フォルダ名</div>
	        <div id="folder-card-status-list" class="folder-status-list"></div>
	        <div class="folder-card-desc">項目をクリックするとプレビュー画面に移動します。</div>
	    </div>
                <!-- アプリごとのGitHub同期 ON/OFF 切り替え -->
        <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0;">
            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: bold; color: #334155; cursor: pointer;">
                <input type="checkbox" id="app-github-sync-toggle" onchange="toggleAppGitHubSync(this)">
					このアプリのGitHub同期を有効にする
            </label>
            <span id="app-github-sync-status-badge" style="font-size: 11px; padding: 2px 8px; border-radius: 10px; background: #e2e8f0; color: #64748b; font-weight: bold;">
                無効
            </span>
        </div>
	    <div>
            <span>RAW URL</span>
			</br>
            <span id="tpl-prompt-url"
                  style="color: #059669; font-weight: bold; word-break: break-all; user-select: text; -webkit-user-select: text; cursor: text;">
                {mock/prompt.txtのRAW URL}
            </span>
            </br>
            <span id="tpl-mock-url"
                  style="color: #2563eb; font-weight: bold; word-break: break-all; user-select: text; -webkit-user-select: text; cursor: text;">
                {mock/index.phpのRAW URL}
            </span>
            </br>
            <span id="tpl-spec-url"
                  style="color: #059669; font-weight: bold; word-break: break-all; user-select: text; -webkit-user-select: text; cursor: text;">
                {draft/dev_specs.txtのRAW URL}
            </span>
            </br>
            <span id="tpl-code-url"
                  style="color: #2563eb; font-weight: bold; word-break: break-all; user-select: text; -webkit-user-select: text; cursor: text;">
                {draft/index.phpのRAW URL}
            </span>
	    </div>

	</div>

</div>


<script>

// JSグローバル変数にWebベースパスを設定
const APP_BASE_PATH = "<?= $web_base_path ?>";



const previewFrame = document.getElementById('preview-frame');



function reloadPreview() {
    const editor = document.getElementById('editor-content');
    const welcome = document.getElementById('welcome-message-area');

    if (!editor || !editor.value.trim()) {
        return;
    }

    previewFrame.srcdoc = editor.value;
    previewFrame.style.display = 'block';
    welcome.style.display = 'none';
}
</script>

    <!-- 上下スプリッター -->
    <div class="resizer-v"
         id="resizer-v"
             style="height: 8px; cursor: row-resize; background: #e0e0e0; border-top: 1px solid #ccc; border-bottom: 1px solid #ccc; flex-shrink: 0; width: 100%; user-select: none;">
 
 
    </div>



<!-- 下部: エディタ・プロンプト領域 -->
    <div class="bottom-container"
         id="bottom-container"
         style="display: flex; flex-direction: column; height: 100%; min-height: 0; flex: 1;">

<!-- タブ切り替えバー -->
<div class="bottom-tab-bar"
     style="display: flex; background: #e0e0e0; border-bottom: 1px solid #ccc; padding: 6px 8px 0; gap: 4px; flex-shrink: 0;">

    <button class="bottom-tab-btn active-tab"
            id="tab-btn-spec-common"
            data-tab="spec-common"
            style="padding: 6px 14px; background: #ffffff; color: #1a73e8; border: 1px solid #ccc; border-bottom: 1px solid #ffffff; cursor: pointer; border-radius: 4px 4px 0 0; font-size: 13px; outline: none; margin-bottom: -1px; font-weight: bold; display: flex; align-items: center; gap: 5px;">
        <span>① 業務・操作要件</span>
    </button>

    <button class="bottom-tab-btn"
            id="tab-btn-mock"
            data-tab="mock"
            style="padding: 6px 14px; background: #f0f0f0; color: #555; border: 1px solid #dcdcdc; border-bottom: 1px solid #ccc; cursor: pointer; border-radius: 4px 4px 0 0; font-size: 13px; outline: none; margin-bottom: -1px; font-weight: normal; display: flex; align-items: center; gap: 5px;">
        <span>② モック (mock)</span>
    </button>

    <button class="bottom-tab-btn"
            id="tab-btn-spec-dev"
            data-tab="spec-dev"
            style="padding: 6px 14px; background: #f0f0f0; color: #555; border: 1px solid #dcdcdc; border-bottom: 1px solid #ccc; cursor: pointer; border-radius: 4px 4px 0 0; font-size: 13px; outline: none; margin-bottom: -1px; font-weight: normal; display: flex; align-items: center; gap: 5px;">
        <span>③ 実装要件・制約</span>
    </button>

    <button class="bottom-tab-btn"
            id="tab-btn-dev"
            data-tab="dev"
            style="padding: 6px 14px; background: #f0f0f0; color: #555; border: 1px solid #dcdcdc; border-bottom: 1px solid #ccc; cursor: pointer; border-radius: 4px 4px 0 0; font-size: 13px; outline: none; margin-bottom: -1px; font-weight: normal; display: flex; align-items: center; gap: 5px;">
        <span>④ 本番コード (dev)</span>
    </button>

    <button class="bottom-tab-btn"
            id="tab-btn-pro"
            data-tab="pro-split"
            style="margin-left: auto; padding: 6px 12px; background: #e8e8e8; color: #666; border: 1px solid #dcdcdc; border-bottom: 1px solid #ccc; cursor: pointer; border-radius: 4px 4px 0 0; font-size: 12px; outline: none; margin-bottom: -1px;">
        ⚡ Pro分割
    </button>
</div>

<!-- タブコンテンツラッパー -->
<div class="bottom-content-wrapper"
     id="bottom-content-wrapper"
     style="flex: 1; min-height: 0; position: relative; display: flex; flex-direction: column; overflow: hidden; width: 100%; height: 100%;">

    <!-- メイン作業エリア（新4タブ共通 左右ペイン構造） -->
    <div id="poc-main-work-area"
         style="display: flex; flex-direction: row; width: 100%; height: 100%; color: #333333; background: #fdfdfd; box-sizing: border-box; overflow: hidden; min-height: 0; flex: 1;">

        <!-- 左ペイン: プロンプト作成 ＆ 世代履歴 -->
        <div id="prompt-panel-left"
             style="flex: 1; display: flex; flex-direction: column; padding: 12px; gap: 8px; height: 100%; box-sizing: border-box; min-width: 200px; min-height: 0;">

            <!-- ヘッダー行＋操作ブロック -->
            <div style="display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px; flex-shrink: 0; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span id="label-left-pane-title" style="font-weight: bold; font-size: 13px; color: #333; white-space: nowrap;">
                        📜 プロンプト作成
                    </span>
                    <span id="prompt-status" style="font-size: 11px; color: #28a745; font-weight: bold;"></span>
                </div>

                <!-- ボタン上にメモ欄をスタック配置 -->
                <div style="display: flex; flex-direction: column; gap: 4px; align-items: stretch; min-width: 170px;">
                    <input type="text"
                           id="prompt-memo"
                           placeholder="履歴メモ（例: 要件追加）"
                           style="padding: 3px 6px; border: 1px solid #ccc; border-radius: 3px; font-size: 11px; outline: none; width: 100%; box-sizing: border-box;">
                    <button id="btn-copy-prompt"
                            style="padding: 5px 12px; background: #007bff; color: #ffffff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 12px; display: flex; align-items: center; justify-content: center; gap: 4px; white-space: nowrap;">
                        📋 コピーしてAIに渡す
                    </button>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; flex: 1; min-height: 0; height: 100%; overflow: hidden;">
                <div id="prompt-content-wrapper" style="flex: 1; min-height: 80px; display: flex; flex-direction: column;">
                    <textarea id="prompt-content"
                              placeholder="AIへの指示・プロンプトを入力してください..."
                              wrap="soft"
                              style="width: 100%; height: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 13px; resize: none; box-sizing: border-box; line-height: 1.5; outline: none; background: #ffffff; color: #333; white-space: pre-wrap; word-break: break-all;"></textarea>
                </div>

                <div id="resizer-prompt-history-v"
                     style="height: 6px; cursor: row-resize; background: #e8e8e8; border-top: 1px solid #ddd; border-bottom: 1px solid #ddd; margin: 4px 0; flex-shrink: 0; user-select: none;">
                </div>

                <div id="prompt-history-wrapper"
                     style="height: 110px; min-height: 50px; flex-shrink: 0; border: 1px solid #e0e0e0; border-radius: 4px; background: #f9f9f9; padding: 8px; display: flex; flex-direction: column; box-sizing: border-box; overflow: hidden;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; border-bottom: 1px solid #e5e5e5; padding-bottom: 3px; flex-shrink: 0;">
                        <span style="font-weight: bold; font-size: 11px; color: #666;">🕒 世代履歴スナップショット</span>
                        <span id="current-history-ver" style="font-size: 11px; color: #007bff; font-weight: bold;">最新 (Working)</span>
                    </div>
                    <div id="prompt-history-list"
                         style="flex: 1; overflow-y: auto; display: flex; flex-wrap: wrap; gap: 4px; align-content: flex-start;">
                        <div style="color: #aaa; font-size: 11px; text-align: center; margin-top: 8px; width: 100%;">
                            履歴はありません
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- 左右リサイザー -->
        <div class="resizer"
             id="resizer-4"
             style="width: 6px; cursor: col-resize; background: #e0e0e0; margin: 0; flex-shrink: 0; border-left: 1px solid #ccc; border-right: 1px solid #ccc;">
        </div>

        <!-- 右ペイン: 結果反映 -->
        <div id="editor-area"
             style="flex: 1; display: flex; flex-direction: column; height: 100%; box-sizing: border-box; padding: 12px; min-width: 200px; min-height: 0;">

            <!-- ツールバー -->
            <div class="toolbar"
                 style="margin-bottom: 8px; display: flex; justify-content: space-between; align-items: flex-end; flex-shrink: 0; gap: 8px;">
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <span id="label-right-pane-target" style="font-weight: bold; font-size: 13px; color: #333; white-space: nowrap;">
                        📄 結果反映 (<span id="target-filename" style="color: #007bff;">spec/common.md</span>)
                    </span>

                    <button id="btn-paste-source"
                            style="padding: 5px 12px; background: #28a745; color: #ffffff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 12px; display: flex; align-items: center; gap: 4px; white-space: nowrap;">
                        📋 AIの出力を貼り付ける
                    </button>

                    <button id="btn-select-all"
                            style="padding: 5px 10px; cursor: pointer; font-size: 12px; white-space: nowrap;">
                        全選択
                    </button>
                </div>

                <!-- ボタン上にメモ欄をスタック配置 -->
                <div style="display: flex; flex-direction: column; gap: 4px; align-items: stretch; min-width: 170px; margin-left: auto;">
                    <input type="text"
                           id="editor-memo"
                           placeholder="履歴メモ（例: 検索機能追加）"
                           style="padding: 3px 6px; border: 1px solid #ccc; border-radius: 3px; font-size: 11px; outline: none; width: 100%; box-sizing: border-box;">
                    <button id="btn-save-current"
                            style="padding: 5px 12px; background: #343a40; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; white-space: nowrap; display: flex; align-items: center; justify-content: center; gap: 4px;">
                        💾 保存 (Ctrl+S)
                    </button>
                </div>
            </div>

            <div class="editor-container"
                 id="editor-container"
                 style="flex: 1; min-height: 0; display: flex; position: relative; border: 1px solid #ccc; border-radius: 4px; background: #fff;">
                <div class="line-numbers" id="line-numbers" style="background: #f4f4f4; border-right: 1px solid #ddd; padding: 8px 4px; font-family: monospace; font-size: 12px; color: #888; text-align: right; user-select: none;">1</div>
                <textarea id="editor-content"
                          placeholder="AIから返ってきた内容を貼り付けるか、直接編集してください"
                          style="flex: 1; height: 100%; resize: none; font-family: monospace; font-size: 13px; padding: 8px; border: none; outline: none; line-height: 1.5; white-space: pre;"></textarea>
            </div>

        </div>

    </div>

    <!-- Pro分割エリア（初期非表示） -->
    <div id="original-pair-group"
         style="display: none; width: 100%; height: 100%; padding: 12px; box-sizing: border-box; flex-direction: row; min-height: 0; flex: 1;">
        <div id="editor-holder-split" style="flex: 1; display: flex; flex-direction: column; height: 100%; min-width: 150px; min-height: 0;"></div>
        <div class="resizer" id="resizer-3" style="width: 5px; cursor: col-resize; background: #e0e0e0; margin: 0 5px; flex-shrink: 0;"></div>
        <div id="split-area" style="flex: 1; display: flex; flex-direction: column; height: 100%; min-width: 200px; min-height: 0;">
            <div class="toolbar" style="margin-bottom: 8px; flex-shrink: 0;">
                <label style="font-size: 13px;">
                    モード
                    <select id="mode">
                        <option value="token">Token 構造分割</option>
                        <option value="lang">PHP/CSS/HTML/JS ブロック automatic分割</option>
                    </select>
                </label>
                <button id="btn-trigger-split" style="padding: 5px 10px; cursor: pointer; font-weight: bold;">分割する</button>
            </div>
            <div id="res" style="display:none; flex-grow: 1; overflow-y: auto;">
                <div id="tabW"></div>
                <div class="split-panes-container"></div>
            </div>
        </div>
    </div>

</div>
        <!-- 縦リサイザー（最下部） -->
        <div id="resizer-github-v"
             style="height: 8px; cursor: row-resize; background: #e0e0e0; border-top: 1px solid #ccc; border-bottom: 1px solid #ccc; flex-shrink: 0; width: 100%; user-select: none;">
        </div>

		<!-- タブ③ GitHub エリア -->
		<div class="github-fixed-card"
		     id="github-fixed-card"
		     style="padding: 10px 14px; background: #f8fafc; flex-shrink: 0; display: flex; flex-direction: column; gap: 8px; box-sizing: border-box; height: 160px; min-height: 60px; overflow-y: auto;">
		    <div style="font-size: 13px; line-height: 1.4; color: #1e293b; display: flex; flex-direction: column; flex-grow: 1;">
		        <span>で次の事象が発生</span>
		        <textarea id="tpl-error-input"
		                  placeholder="発生したエラー内容・ログをここに貼り付けてください"
		                  style="width: 100%; flex-grow: 1; min-height: 40px; margin: 6px 0 0; padding: 6px 8px; font-size: 12px; font-family: monospace; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; resize: none; background: #ffffff;"></textarea>
		    </div>
		    <div style="display: flex; gap: 8px; flex-wrap: wrap; flex-shrink: 0;">
		        <button type="button" onclick="copyAiInstructionPromptRebuild()" style="flex: 1; min-width: 180px; padding: 8px 12px; background: #1e293b; color: #ffffff; border: none; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer;">
		            📋 原因究明・再生成用をコピー
		        </button>
		        <button type="button" onclick="copyAiInstructionPromptFix()" style="flex: 1; min-width: 180px; padding: 8px 12px; background: #2563eb; color: #ffffff; border: none; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer;">
		            📋 原因確認・要件定義を修正指示をコピー
		        </button>
		    </div>
		</div>

    </div>
</div>



<!-- ========================================== -->
<!-- GitHub同期設定モーダル（共通設定専用） -->
<!-- ========================================== -->
<div id="github-settings-modal"
     style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.25); z-index: 10000; overflow-y: auto;">

    <div style="background: #fff; border: 1px solid #ccc; box-shadow: 0 4px 12px rgba(0,0,0,0.2); margin: 5% auto; max-width: 550px; width: 90%; box-sizing: border-box;">

        <!-- ヘッダー -->
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; border-bottom: 1px solid #eee;">
            <h3 style="margin: 0; font-size: 16px;">GitHub接続・アカウント設定（共通）</h3>
            <button type="button" onclick="closeGitHubSettings()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
        </div>

        <!-- 本体 -->
        <div style="padding: 15px;">

            <!-- GitHubユーザー名 -->
            <div style="margin-bottom: 12px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px; font-size: 13px;">GitHubユーザー名</label>
                <input type="text" id="github-owner" placeholder="例: your-name" autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 7px; border: 1px solid #cbd5e1; border-radius: 4px;">
            </div>

            <!-- リポジトリ -->
            <div style="margin-bottom: 12px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px; font-size: 13px;">リポジトリ</label>
                <input type="text" id="github-repo" placeholder="例: my-app" autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 7px; border: 1px solid #cbd5e1; border-radius: 4px;">
            </div>

            <!-- ブランチ -->
            <div style="margin-bottom: 12px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px; font-size: 13px;">ブランチ</label>
                <input type="text" id="github-branch" value="main" autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 7px; border: 1px solid #cbd5e1; border-radius: 4px;">
            </div>

            <!-- GitHub Token -->
            <div style="margin-bottom: 18px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px; font-size: 13px;">GitHub Token</label>
                <input type="password" id="github-token" placeholder="GitHub Personal Access Token" autocomplete="new-password" style="width: 100%; box-sizing: border-box; padding: 7px; border: 1px solid #cbd5e1; border-radius: 4px;">
            </div>

            <!-- プロキシ設定 -->
            <div style="margin-top: 15px; padding: 12px; border: 1px solid #e2e8f0; background: #fafafa; border-radius: 4px;">
                <div style="font-weight: bold; font-size: 13px; margin-bottom: 10px;">プロキシ設定</div>
                
                <div style="margin-bottom: 10px;">
                    <label style="cursor: pointer; font-weight: bold; font-size: 12px;">
                        <input type="checkbox" id="github-proxy-enabled"> プロキシを使用する
                    </label>
                </div>

                <div style="margin-bottom: 10px;">
                    <label style="font-weight: bold; display: block; margin-bottom: 3px; font-size: 12px;">プロキシホスト</label>
                    <input type="text" id="github-proxy-host" placeholder="例: proxy.example.local" autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                </div>

                <div style="margin-bottom: 10px;">
                    <label style="font-weight: bold; display: block; margin-bottom: 3px; font-size: 12px;">プロキシポート</label>
                    <input type="number" id="github-proxy-port" value="8080" style="width: 100%; box-sizing: border-box; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                </div>

                <div style="margin-bottom: 10px;">
                    <label style="font-weight: bold; display: block; margin-bottom: 3px; font-size: 12px;">プロキシユーザー名</label>
                    <input type="text" id="github-proxy-username" placeholder="認証が必要な場合のみ" autocomplete="off" style="width: 100%; box-sizing: border-box; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                </div>

                <div>
                    <label style="font-weight: bold; display: block; margin-bottom: 3px; font-size: 12px;">プロキシパスワード</label>
                    <input type="password" id="github-proxy-password" placeholder="認証が必要な場合のみ" autocomplete="new-password" style="width: 100%; box-sizing: border-box; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                </div>
            </div>

            <!-- フッターボタン -->
            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 15px;">
                <button type="button" onclick="closeGitHubSettings()" style="padding: 7px 14px; cursor: pointer; border: 1px solid #cbd5e1; background: #fff; border-radius: 4px;">キャンセル</button>
                <button type="button" onclick="saveGitHubSettings()" style="padding: 7px 14px; background: #24292f; color: #fff; border: none; cursor: pointer; font-weight: bold; border-radius: 4px;">保存</button>
            </div>
            <div id="github-settings-status" style="margin-top: 8px; font-size: 12px; min-height: 18px;"></div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- 4. モーダル領域 -->
<!-- ========================================== -->

<div id="history-modal"
     class="history-modal-overlay"
     style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: transparent; backdrop-filter: none; -webkit-backdrop-filter: none; z-index: 9999; pointer-events: none;">

    <div class="history-modal-content"
         style="pointer-events: auto; background: #fff; border: 1px solid #ccc; box-shadow: 0 4px 12px rgba(0,0,0,0.15); margin: 10% auto; max-width: 650px; width: 90%;">

        <div class="history-modal-header"
             style="display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; border-bottom: 1px solid #eee;">

            <h3 style="margin: 0;">
                プロンプト履歴の詳細
            </h3>

            <div class="history-modal-actions"
                 style="display: flex; gap: 8px; align-items: center;">

                <button type="button"
                        class="btn-modal-action"
                        onclick="copyModalContent()"
                        title="プロンプト内容をコピー"
                        style="padding: 4px 8px; cursor: pointer;">
                    📋 コピー
                </button>

                <button type="button"
                        class="btn-modal-action"
                        onclick="downloadModalContent()"
                        title="テキストファイルとしてダウンロード"
                        style="padding: 4px 8px; cursor: pointer;">
                    💾 ダウンロード
                </button>

                <button type="button"
                        class="history-modal-close"
                        onclick="closeHistoryModal()"
                        style="background: none; border: none; font-size: 20px; cursor: pointer; padding: 0 4px; line-height: 1;">
                    &times;
                </button>

            </div>

        </div>


        <div class="history-modal-body"
             style="padding: 15px;">

            <div class="history-modal-section"
                 style="margin-bottom: 15px;">

                <label style="font-weight: bold; display: block; margin-bottom: 5px;">
                    📝 メモ
                </label>

                <div id="modal-memo-content"
                     class="modal-text-box"
                     style="user-select: text !important; -webkit-user-select: text !important;">
                </div>

            </div>


            <div class="history-modal-section">

                <label style="font-weight: bold; display: block; margin-bottom: 5px;">
                    💬 プロンプト（チャット）の内容
                </label>

                <textarea id="modal-prompt-content"
                          class="modal-text-box"
                          readonly
                          style="width: 100%; box-sizing: border-box; user-select: text !important; -webkit-user-select: text !important; cursor: text;"></textarea>

            </div>

        </div>

    </div>

</div>
<!-- コンテキスト（右クリック）メニュー -->
<div id="context-menu"></div>

<!-- =========================================================================
     3. JavaScript 処理
     ========================================================================= -->
<script>
/**
 * 💡 画面をブロックして「処理中」の文言を表示する関数
 */
function startBlocking(message = '処理中...') {
    const overlay = document.getElementById('loading-overlay');
    const textBox = document.getElementById('loading-text');
    if (overlay && textBox) {
        textBox.textContent = message;
        overlay.classList.add('visible'); 
    } else {
        console.error("エラー: loading-overlay または loading-text が見つかりません。");
    }
}

/**
 * 💡 ブロックを解除して元の画面に戻す関数
 */
function stopBlocking() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.classList.remove('visible');
    }
}

let currentPath = '';
let currentIsPublic = false;

// 行番号更新用の関数
function updateLineNumbers() {
    const textarea = document.getElementById('editor-content');
    const lineNumbers = document.getElementById('line-numbers');
    if (!textarea || !lineNumbers) return;

    const lines = textarea.value.split('\n');
    const lineCount = lines.length;
    
    let numbersArr = [];
    for (let i = 1; i <= lineCount; i++) {
        numbersArr.push(i);
    }
    lineNumbers.textContent = numbersArr.join('\n');
}

// テキストエリアの入力、スクロール、キーボードイベント登録
const editorTextArea = document.getElementById('editor-content');
const lineNumbersDiv = document.getElementById('line-numbers');
const btnSave = document.getElementById('btn-save');

if (editorTextArea) {
    editorTextArea.addEventListener('input', () => {
        updateLineNumbers();
        if (!currentIsPublic && btnSave) {
            btnSave.style.display = 'inline-block';
        }
    });

    editorTextArea.addEventListener('scroll', function() {
        if (lineNumbersDiv) lineNumbersDiv.scrollTop = editorTextArea.scrollTop;
    });

    editorTextArea.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            if (typeof saveFile === 'function') saveFile();
        }
    });
}

async function apiCall(action, data = {}) {
    const res = await fetch('?api=' + action, { 
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' }, 
        body: JSON.stringify(data) 
    });
    return await res.json();
}



/**
 * GitHub設定をAPIから取得して画面/変数に反映する関数
 */
async function loadGithubSettings(targetPath = '') {
    try {
        const res = await fetch(`?api=github_settings_get&path=${encodeURIComponent(targetPath)}`);
        const data = await res.json();

        if (data.success) {
            console.log('[DEBUG] GitHub設定取得成功:', data);
            
            if (document.getElementById('gh-owner')) document.getElementById('gh-owner').value = data.owner || '';
            if (document.getElementById('gh-repo')) document.getElementById('gh-repo').value = data.repo || '';
            if (document.getElementById('gh-branch')) document.getElementById('gh-branch').value = data.branch || 'main';
            if (document.getElementById('gh-proxy-enabled')) document.getElementById('gh-proxy-enabled').checked = !!data.proxy_enabled;
            if (document.getElementById('gh-proxy-host')) document.getElementById('gh-proxy-host').value = data.proxy_host || '';
            if (document.getElementById('gh-proxy-port')) document.getElementById('gh-proxy-port').value = data.proxy_port || '';
            if (document.getElementById('gh-proxy-username')) document.getElementById('gh-proxy-username').value = data.proxy_username || '';
            
            if (document.getElementById('gh-app-enabled')) {
                document.getElementById('gh-app-enabled').checked = !!data.enabled;
            }
        }
    } catch (e) {
        console.error('[DEBUG] GitHub設定取得失敗:', e);
    }
}

/**
 * ツリーノードのHTMLを組み立てる関数
 * @param {Array} nodes ノード配列
 * @param {Object|boolean} options { isPublic: boolean, status: 'draft'|'mock' } または 真偽値 (isPublic)
 */
// ==========================================
// ツリーHTML生成関数
// ==========================================
// ==========================================
// ツリーHTML生成関数（開発中・モック構造対応版）
// ==========================================
function buildHTML(nodes, options) {
    if (!Array.isArray(nodes) || nodes.length === 0) {
        return '';
    }

    var opts = (typeof options === 'boolean') ? { isPublic: options } : (options || {});
    var isPublic = !!opts.isPublic;
    var defaultStatus = opts.status || (isPublic ? 'published' : 'draft');

    var hiddenNames = new Set([
        '.poc', 'poc', 'published',
        '.history', '.prompt', '.github', '.harness', 'spec',
        'index.php', 'config.json'
    ]);

    var html = '';

    for (var i = 0; i < nodes.length; i++) {
        var node = nodes[i];
        if (!node || node.type === 'data-folder') continue;
        if (hiddenNames.has(node.name)) continue;

        var rawPath = (node.path || '').replace(/\/+$/, '');
        var displayName = node.name || rawPath.split('/').pop() || '';
        var isAppPublished = isPublic || (node.published === true || node.published === 'true' || node.published === 1);
        var isApp = isPublic ? (node.type === 'app') : true;

        // ★ モック専用（作成中）かどうかの判定
        // draftを持たず、mockのみ存在する場合をモック作成中とみなす
        var isOnlyMock = false;
        if (node.status === 'mock' || node.isMock === true || /\/mock$/i.test(rawPath)) {
            isOnlyMock = true;
        } else if (node.children && Array.isArray(node.children)) {
            var hasDraft = node.children.some(c => c.name === 'draft' || /\/draft$/i.test(c.path || ''));
            var hasMock = node.children.some(c => c.name === 'mock' || /\/mock$/i.test(c.path || ''));
            if (hasMock && !hasDraft) {
                isOnlyMock = true;
            }
        }

        // パス末尾から /draft や /mock を取り除いたベースパス
        var appBasePath = rawPath.replace(/\/(draft|mock)$/i, '');

        var childrenHtml = '';

        if (!isPublic && isApp) {
            var childPublishedAttr = isAppPublished ? 'true' : 'false';
            var draftPath = appBasePath + '/draft';
            var mockPath  = appBasePath + '/mock';

            var mockItemHtml = 
                '<details data-path="' + mockPath + '" data-type="app-item" data-status="mock" data-published="' + childPublishedAttr + '" class="item-app" ontoggle="typeof saveTreeState === \'function\' && saveTreeState()">' +
                    '<summary draggable="true" ' +
                             'ondragstart="typeof drag === \'function\' && drag(event, \'' + mockPath + '\')" ' +
                             'ondragover="typeof allowDrop === \'function\' && allowDrop(event)" ' +
                             'ondragleave="typeof dragLeave === \'function\' && dragLeave(event)" ' +
                             'ondrop="typeof drop === \'function\' && drop(event, \'' + mockPath + '\')" ' +
                             'oncontextmenu="typeof showContext === \'function\' && showContext(event, \'' + mockPath + '\', ' + isAppPublished + ')" ' +
                             'onclick="typeof selectFolder === \'function\' && selectFolder(event, \'' + mockPath + '\', ' + isAppPublished + ')">' +
                        '<span style="margin-right: 6px; display: inline-block; width: 18px; text-align: center;">📐</span>' +
                        '<span class="item-name">モック</span>' +
                    '</summary>' +
                '</details>';

            if (isOnlyMock) {
                // 【モック作成中】モックのみ表示
                childrenHtml = mockItemHtml;
            } else {
                // 【開発中（通常）】開発中 ＋ モック の両方を表示
                var draftItemHtml = 
                    '<details data-path="' + draftPath + '" data-type="app-item" data-status="draft" data-published="' + childPublishedAttr + '" class="item-app" ontoggle="typeof saveTreeState === \'function\' && saveTreeState()">' +
                        '<summary draggable="true" ' +
                                 'ondragstart="typeof drag === \'function\' && drag(event, \'' + draftPath + '\')" ' +
                                 'ondragover="typeof allowDrop === \'function\' && allowDrop(event)" ' +
                                 'ondragleave="typeof dragLeave === \'function\' && dragLeave(event)" ' +
                                 'ondrop="typeof drop === \'function\' && drop(event, \'' + draftPath + '\')" ' +
                                 'oncontextmenu="typeof showContext === \'function\' && showContext(event, \'' + draftPath + '\', ' + isAppPublished + ')" ' +
                                 'onclick="typeof selectFolder === \'function\' && selectFolder(event, \'' + draftPath + '\', ' + isAppPublished + ')">' +
                            '<span style="margin-right: 6px; display: inline-block; width: 18px; text-align: center;">🔨</span>' +
                            '<span class="item-name">開発中</span>' +
                        '</summary>' +
                    '</details>';

                // draft / mock 以外の独自サブフォルダがあれば追加レンダリング
                var subFoldersHtml = '';
                if (node.children && Array.isArray(node.children) && node.children.length > 0) {
                    var filteredChildren = node.children.filter(function(c) {
                        return c.name !== 'draft' && c.name !== 'mock';
                    });
                    if (filteredChildren.length > 0) {
                        subFoldersHtml = buildHTML(filteredChildren, opts);
                    }
                }

                childrenHtml = draftItemHtml + mockItemHtml + subFoldersHtml;
            }
        } else if (node.children && Array.isArray(node.children) && node.children.length > 0) {
            childrenHtml = buildHTML(node.children, opts);
        }

        var badge = '';
        if (!isPublic && isAppPublished) {
            badge = '<span class="published-badge badge-public">🌐公開中</span>';
        }

        var icon = isPublic ? '🌐' : '📁';
        var isPublishedAttr = (!isPublic && isAppPublished) ? 'true' : 'false';
        
        // 親フォルダクリック時は、モック作成中なら mock、それ以外なら draft を選択
        var defaultSelectPath = isOnlyMock ? (appBasePath + '/mock') : (appBasePath + '/draft');
        var clickFn = 'typeof selectFolder === \'function\' && selectFolder(event, \'' + defaultSelectPath + '\', ' + isAppPublished + ')';

        html += '<details data-path="' + appBasePath + '" ' +
                         'data-type="' + (isApp ? 'app-group' : node.type) + '" ' +
                         'data-status="' + (isOnlyMock ? 'mock' : defaultStatus) + '" ' +
                         'data-published="' + isPublishedAttr + '" ' +
                         'class="' + (isApp ? 'item-app-group' : 'item-folder') + '" ' +
                         'open ' +
                         'ontoggle="typeof saveTreeState === \'function\' && saveTreeState()">' +
                    '<summary draggable="true" ' +
                             'ondragstart="typeof drag === \'function\' && drag(event, \'' + appBasePath + '\')" ' +
                             'ondragover="typeof allowDrop === \'function\' && allowDrop(event)" ' +
                             'ondragleave="typeof dragLeave === \'function\' && dragLeave(event)" ' +
                             'ondrop="typeof drop === \'function\' && drop(event, \'' + appBasePath + '\')" ' +
                             'oncontextmenu="typeof showContext === \'function\' && showContext(event, \'' + appBasePath + '\', ' + isAppPublished + ')" ' +
                             'onclick="' + clickFn + '">' +
                        '<span style="margin-right: 6px; display: inline-block; width: 18px; text-align: center;">' + icon + '</span>' +
                        '<span class="item-name">' + displayName + '</span>' +
                        badge +
                    '</summary>' +
                    childrenHtml +
                '</details>';
    }
    return html;
}

// ==========================================
// ツリー読み込み関数（単一・完全版）
// ==========================================
async function loadTrees() {
    try {
        const res = await fetch('?api=get_tree');
        const data = await res.json();

        // 1. 公開中ツリー（tree-pub または tree-public の両方に対応）
        const treePublic = document.getElementById('tree-pub') || document.getElementById('tree-public');
        const publishedNodes = data.published || data.public || (data.tree && data.tree.filter(n => n.published)) || [];
        if (treePublic) {
            treePublic.innerHTML = buildHTML(publishedNodes, { isPublic: true, status: 'published' });
        }

        // 2. 開発中ツリー（tree-dev または tree-draft の両方に対応）
        const treeDraft = document.getElementById('tree-dev') || document.getElementById('tree-draft');
        if (treeDraft) {
            let devApps = [];
            if (data.poc && Array.isArray(data.poc)) {
                devApps = data.poc;
            } else if (data.tree && Array.isArray(data.tree)) {
                devApps = data.tree;
            } else {
                const drafts = data.draft || [];
                const mocks  = data.mock || [];
                const draftAppNames = new Set(drafts.map(item => item.name));
                const onlyMocks = mocks.filter(mockItem => !draftAppNames.has(mockItem.name));
                devApps = [...drafts, ...onlyMocks];
            }

            devApps.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
            treeDraft.innerHTML = buildHTML(devApps, { isPublic: false, status: 'draft' });
        }

        // ★ 初回は未選択状態にするため、前回の選択状態の復元処理をスキップ & クリア
        if (typeof currentPath !== 'undefined') {
            currentPath = '';
        }
        
        // 選択ハイライト要素があればすべて解除
        document.querySelectorAll('.item-app.selected, .item-app.active, .item-app-group.selected, summary.active, summary.selected').forEach(el => {
            el.classList.remove('selected', 'active');
        });

        if (typeof loadGithubSettings === 'function') {
            await loadGithubSettings('');
        }
    } catch (e) {
        console.error('[DEBUG] ツリー読み込み失敗:', e);
    }
}
/**
 * プレビュー用URLを安全に生成してiframeに適用する
 */
function updatePreviewFrame(rawPath, isPublic) {
    const previewFrame = document.getElementById('preview-frame');
    if (!previewFrame || !rawPath) return;

    let cleanPath = rawPath.replace(/^\/+|\/+$/g, '');

    // 公開アプリでなく、末尾が draft / mock 以外なら /draft を付与して 403 を回避
    if (!isPublic && !cleanPath.endsWith('/draft') && !cleanPath.endsWith('/mock')) {
        cleanPath += '/draft';
    }

    // パス部分を正しくエンコード
    const encodedPath = cleanPath.split('/').map(segment => encodeURIComponent(segment)).join('/');
    previewFrame.src = `/gojacic/${encodedPath}/`;
}
function restoreTreeState() {
    try {
        const savedPath = localStorage.getItem('last_selected_path');
        if (!savedPath) return;

        // 保存されていたパス要素を探す
        const targetElement = document.querySelector(`[data-path="${savedPath}"]`);
        if (targetElement) {
            const isPublic = targetElement.getAttribute('data-published') === 'true' || 
                             targetElement.getAttribute('data-status') === 'published';
            
            // selectFolder を呼び出すか、プレビューを安全に更新
            if (typeof selectFolder === 'function') {
                selectFolder(null, savedPath, isPublic);
            } else {
                updatePreviewFrame(savedPath, isPublic);
            }
        }
    } catch (e) {
        console.warn('restoreTreeState エラー:', e);
    }
}
function saveTreeState() {
    const openPaths = Array.from(document.querySelectorAll('details[open]')).map(d => d.getAttribute('data-path'));
    localStorage.setItem('treeState_gojacic', JSON.stringify(openPaths));
}

function restoreTreeState() {
    const state = JSON.parse(localStorage.getItem('treeState_gojacic') || '[]');
    document.querySelectorAll('details').forEach(d => { 
        if (state.includes(d.getAttribute('data-path'))) d.open = true; 
    });
    if (typeof updateSelectedStyles === 'function') updateSelectedStyles();
}

function updateSelectedStyles() {
    document.querySelectorAll('summary.active').forEach(el => {
        el.classList.remove('active');
    });

    document.querySelectorAll('details[data-path] summary').forEach(el => {
        const path = el.closest('details').getAttribute('data-path');
        if (path === currentPath) {
            el.classList.add('active');
        }
    });
}
/**
 * ウェルカムメッセージを表示する（プレビューiframeは隠す）
 */
function showWelcomeMessage() {
    const iframe = document.getElementById('preview-frame');
    const welcomeArea = document.getElementById('welcome-message-area');
    
    if (iframe) iframe.style.setProperty('display', 'none', 'important');
    if (welcomeArea) welcomeArea.style.setProperty('display', 'flex', 'important');
}

/**
 * ウェルカムメッセージを非表示にして、プレビュー画面を表示する
 */
function hideWelcomeMessage() {
    const iframe = document.getElementById('preview-frame');
    const welcomeArea = document.getElementById('welcome-message-area');
    
    if (iframe) iframe.style.setProperty('display', 'block', 'important');
    if (welcomeArea) welcomeArea.style.setProperty('display', 'none', 'important');
}
let currentIsMock = false;

// ==========================================
// 💡 postMessage のリスナー登録（iframeからの遷移要求を受信）
// ==========================================
// ==========================================
// postMessage のリスナー登録（iframeからの遷移要求を受信）
// ==========================================
// ==========================================
// postMessage のリスナー登録（iframeからの遷移要求を受信）
// ==========================================
// ==========================================
// postMessage のリスナー登録
// ==========================================
// ==========================================
// postMessage のリスナー登録
// ==========================================
// ==========================================
// postMessage のリスナー登録
// ==========================================
// ==========================================
// postMessage のリスナー登録
// ==========================================
if (!window.__hasSelectFolderMessageListener) {
    window.addEventListener('message', function(e) {
        if (e.data && e.data.type === 'SELECT_APP_PATH') {
            selectFolder(null, e.data.path, e.data.isPublic);
        }
    });
    window.__hasSelectFolderMessageListener = true;
}

/**
 * フォルダ選択処理（動的ベースパス対応版）
 * @param {string} path - 選択されたフォルダパス（例: ".poc/Demo2/mock" や "published/Demo2"）
 * @param {boolean} isPublic - 公開用フォルダかどうか
 */
/**
 * フォルダ選択処理（引数の型揺れ完全防御版）
 * @param {string|HTMLElement} target - パス文字列、またはクリックされたDOM要素
 * @param {boolean} isPublic - 公開用フォルダかどうか
 */
// ==========================================
// 1. ツリーHTML生成関数 (buildHTML)
// ==========================================
// ==========================================
// 1. ツリーHTML生成関数 (buildHTML)
// ==========================================
function buildHTML(nodes, options) {
    if (!Array.isArray(nodes) || nodes.length === 0) {
        return '';
    }

    var opts = (typeof options === 'boolean') ? { isPublic: options } : (options || {});
    var isPublic = !!opts.isPublic;
    var defaultStatus = opts.status || (isPublic ? 'published' : 'draft');

    var hiddenNames = new Set([
        '.poc', 'poc', 'published',
        '.history', '.prompt', '.github', '.harness', 'spec',
        'index.php', 'config.json'
    ]);

    var html = '';

    for (var i = 0; i < nodes.length; i++) {
        var node = nodes[i];
        if (!node || node.type === 'data-folder') continue;
        if (hiddenNames.has(node.name)) continue;

        var rawPath = (node.path || '').replace(/\/+$/, '');
        var displayName = node.name || rawPath.split('/').pop() || '';
        var isAppPublished = isPublic || (node.published === true || node.published === 'true' || node.published === 1);
        var isApp = isPublic ? (node.type === 'app') : true;

        // モック専用判定（PHP側から返される stage / status を優先判定）
        var isOnlyMock = false;
        if (node.stage === 'mock' || node.status === 'mock' || node.isMock === true || /\/mock$/i.test(rawPath)) {
            isOnlyMock = true;
        } else if (node.children && Array.isArray(node.children)) {
            var hasDraft = node.children.some(c => c.name === 'draft' || /\/draft$/i.test(c.path || ''));
            var hasMock = node.children.some(c => c.name === 'mock' || /\/mock$/i.test(c.path || ''));
            if (hasMock && !hasDraft) {
                isOnlyMock = true;
            }
        }

        var appBasePath = rawPath.replace(/\/(draft|mock)$/i, '');
        var childrenHtml = '';

        if (!isPublic && isApp) {
            var childPublishedAttr = isAppPublished ? 'true' : 'false';
            var draftPath = appBasePath + '/draft';
            var mockPath  = appBasePath + '/mock';

            var mockItemHtml = 
                '<details data-path="' + mockPath + '" data-type="app-item" data-status="mock" data-published="' + childPublishedAttr + '" class="item-app" ontoggle="typeof saveTreeState === \'function\' && saveTreeState()">' +
                    '<summary draggable="true" ' +
                             'ondragstart="typeof drag === \'function\' && drag(event, \'' + mockPath + '\')" ' +
                             'ondragover="typeof allowDrop === \'function\' && allowDrop(event)" ' +
                             'ondragleave="typeof dragLeave === \'function\' && dragLeave(event)" ' +
                             'ondrop="typeof drop === \'function\' && drop(event, \'' + mockPath + '\')" ' +
                             'oncontextmenu="typeof showContext === \'function\' && showContext(event, \'' + mockPath + '\', ' + isPublic + ')" ' +
                             'onclick="typeof selectFolder === \'function\' && selectFolder(event, \'' + mockPath + '\', ' + isPublic + ')">' +
                        '<span style="margin-right: 6px; display: inline-block; width: 18px; text-align: center;">📐</span>' +
                        '<span class="item-name">モック</span>' +
                    '</summary>' +
                '</details>';

            if (isOnlyMock) {
                childrenHtml = mockItemHtml;
            } else {
                var draftItemHtml = 
                    '<details data-path="' + draftPath + '" data-type="app-item" data-status="draft" data-published="' + childPublishedAttr + '" class="item-app" ontoggle="typeof saveTreeState === \'function\' && saveTreeState()">' +
                        '<summary draggable="true" ' +
                                 'ondragstart="typeof drag === \'function\' && drag(event, \'' + draftPath + '\')" ' +
                                 'ondragover="typeof allowDrop === \'function\' && allowDrop(event)" ' +
                                 'ondragleave="typeof dragLeave === \'function\' && dragLeave(event)" ' +
                                 'ondrop="typeof drop === \'function\' && drop(event, \'' + draftPath + '\')" ' +
                                 'oncontextmenu="typeof showContext === \'function\' && showContext(event, \'' + draftPath + '\', ' + isPublic + ')" ' +
                                 'onclick="typeof selectFolder === \'function\' && selectFolder(event, \'' + draftPath + '\', ' + isPublic + ')">' +
                            '<span style="margin-right: 6px; display: inline-block; width: 18px; text-align: center;">🔨</span>' +
                            '<span class="item-name">開発中</span>' +
                        '</summary>' +
                    '</details>';

                var subFoldersHtml = '';
                if (node.children && Array.isArray(node.children) && node.children.length > 0) {
                    var filteredChildren = node.children.filter(function(c) {
                        return c.name !== 'draft' && c.name !== 'mock';
                    });
                    if (filteredChildren.length > 0) {
                        subFoldersHtml = buildHTML(filteredChildren, opts);
                    }
                }

                childrenHtml = draftItemHtml + mockItemHtml + subFoldersHtml;
            }
        } else if (node.children && Array.isArray(node.children) && node.children.length > 0) {
            childrenHtml = buildHTML(node.children, opts);
        }

        var badge = '';
        if (!isPublic && isAppPublished) {
            badge = '<span class="published-badge badge-public">🌐公開中</span>';
        }

        var icon = isPublic ? '🌐' : '📁';
        var isPublishedAttr = isAppPublished ? 'true' : 'false';
        
        var clickFn = 'typeof selectFolder === \'function\' && selectFolder(event, \'' + appBasePath + '\', ' + isPublic + ')';

        html += '<details data-path="' + appBasePath + '" ' +
                         'data-type="' + (isApp ? 'app-group' : node.type) + '" ' +
                         'data-status="' + (isOnlyMock ? 'mock' : defaultStatus) + '" ' +
                         'data-published="' + isPublishedAttr + '" ' +
                         'class="' + (isApp ? 'item-app-group' : 'item-folder') + '" ' +
                         'open ' +
                         'ontoggle="typeof saveTreeState === \'function\' && saveTreeState()">' +
                    '<summary draggable="true" ' +
                             'ondragstart="typeof drag === \'function\' && drag(event, \'' + appBasePath + '\')" ' +
                             'ondragover="typeof allowDrop === \'function\' && allowDrop(event)" ' +
                             'ondragleave="typeof dragLeave === \'function\' && dragLeave(event)" ' +
                             'ondrop="typeof drop === \'function\' && drop(event, \'' + appBasePath + '\')" ' +
                             'oncontextmenu="typeof showContext === \'function\' && showContext(event, \'' + appBasePath + '\', ' + isPublic + ')" ' +
                             'onclick="' + clickFn + '">' +
                        '<span style="margin-right: 6px; display: inline-block; width: 18px; text-align: center;">' + icon + '</span>' +
                        '<span class="item-name">' + displayName + '</span>' +
                        badge +
                    '</summary>' +
                    childrenHtml +
                '</details>';
    }
    return html;
}
/**
 * フォルダ選択処理（進捗順縦配置・区切り線対応・カード動的生成・GitHub同期連動版）
 */
async function selectFolder(eventOrTarget, targetPath = null, isPublic = false) {
    let path = '';

    // 1. 引数の解決
    if (typeof targetPath === 'string' && targetPath.trim() !== '') {
        path = targetPath;
    } else if (typeof eventOrTarget === 'string') {
        path = eventOrTarget;
    } else if (eventOrTarget && eventOrTarget.target) {
        const el = eventOrTarget.target.closest('[data-path]');
        if (el) {
            path = el.getAttribute('data-path') || el.dataset.path || '';
        }
    } else if (eventOrTarget && (eventOrTarget instanceof HTMLElement || eventOrTarget.nodeType === 1)) {
        const el = eventOrTarget.closest('[data-path]') || eventOrTarget;
        path = el.getAttribute('data-path') || el.dataset.path || '';
    }

    if (!path || path.startsWith('[object')) {
        console.warn('⚠️ selectFolder: 有効なフォルダパスが取得できませんでした', eventOrTarget, targetPath);
        return;
    }

    // パス末尾のスラッシュを除去して統一
    path = path.replace(/\/+$/, '');
    currentPath = path;

    // ★【追加】選択されたフォルダからアプリのルートパスを抽出し、GitHub同期トグル・バッジを当該アプリの状態に更新
    const appRootPath = path.replace(/\/(mock|draft)$/, '');
    if (typeof window.setAppGitHubSyncTarget === 'function') {
        window.setAppGitHubSyncTarget(appRootPath);
    }

    // 2. UIのアクティブ状態更新
    document.querySelectorAll('#poc-tree-container li, [data-path], summary').forEach(el => {
        el.classList.remove('bg-indigo-50', 'text-indigo-600', 'font-semibold', 'selected', 'active');
    });
    const activeEl = document.querySelector(`[data-path="${path}"]`);
    if (activeEl) {
        activeEl.classList.add('bg-indigo-50', 'text-indigo-600', 'font-semibold', 'selected');
        const summary = activeEl.querySelector('summary');
        if (summary) summary.classList.add('active');
    }

    // DOMから公開フラグ（data-published）を正確に取得
    const isActuallyPublished = Boolean(
        isPublic ||
        (activeEl && (activeEl.getAttribute('data-published') === 'true' || activeEl.dataset.published === 'true')) ||
        path.startsWith('published/')
    );
    currentIsPublic = isActuallyPublished;

    // パス表示更新
    const currentPathDisplay = document.getElementById('current-path-display');
    if (currentPathDisplay) {
        currentPathDisplay.innerText = path;
    }

    // DOM要素の取得
    const previewFrame = document.getElementById('preview-frame');
    const welcomeArea = document.getElementById('welcome-message-area');
    const folderOverlay = document.getElementById('folder-status-overlay');

    // 実行可能アイテム（末尾が /draft または /mock、もしくは公開フォルダそのものの直実行）かどうかの判定
    const isExecutable = path.endsWith('/draft') || path.endsWith('/mock') || (activeEl && activeEl.getAttribute('data-type') === 'app-item');

    if (!isExecutable) {
        // =========================================================================
        // 【親フォルダ選択時】
        // カード画面（#folder-status-overlay）に進捗を縦順で表示
        // =========================================================================

        if (previewFrame) {
            previewFrame.style.display = 'none';
            previewFrame.src = 'about:blank';
        }
        if (welcomeArea) {
            welcomeArea.style.display = 'none';
        }

        if (folderOverlay) {
            folderOverlay.style.display = 'block';

            const folderName = path.split('/').pop() || path;
            const titleEl = document.getElementById('folder-card-title');
            if (titleEl) {
                titleEl.innerHTML = `<span>📁 ${folderName}</span>`;
            }

            // ツリー上の実態から配下の存在（mock/draft）を判定
            const hasMock = Boolean(document.querySelector(`[data-path="${path}/mock"]`));
            const hasDraft = Boolean(document.querySelector(`[data-path="${path}/draft"]`));

            // 進捗リストエリアの生成（上から 公開中 -> 開発中 -> モック作成中）
            const statusListEl = document.getElementById('folder-card-status-list');
            if (statusListEl) {
                const cleanFolder = folderName;
                const pubPath = path.startsWith('published/') ? path : `published/${cleanFolder}`;

                let progressHtml = `
                    <div style="display: flex; flex-direction: column; gap: 10px; margin: 15px 0 20px 0;">
                        <!-- 1. 公開中 -->
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-radius: 8px; border: 1px solid ${isActuallyPublished ? '#bbf7d0' : '#e5e7eb'}; background: ${isActuallyPublished ? '#f0fdf4' : '#f9fafb'};">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 16px;">🚀</span>
                                <span style="font-weight: 600; color: ${isActuallyPublished ? '#166534' : '#9ca3af'};">公開中</span>
                            </div>
                            <div>
                                ${isActuallyPublished 
                                    ? `<button type="button" class="btn btn-sm" style="background:#22c55e; color:#fff; border:none; padding: 5px 12px; border-radius: 6px; cursor: pointer; font-weight: 500;" onclick="window.open('${window.location.origin}/${pubPath}/', '_blank')">公開ページを開く ↗</button>` 
                                    : `<span style="color: #9ca3af; font-size: 12px; font-weight: 500;">未公開</span>`}
                            </div>
                        </div>

                        <!-- 2. 開発中 -->
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-radius: 8px; border: 1px solid ${hasDraft ? '#c7d2fe' : '#e5e7eb'}; background: ${hasDraft ? '#eef2ff' : '#f9fafb'};">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 16px;">🔨</span>
                                <span style="font-weight: 600; color: ${hasDraft ? '#3730a3' : '#9ca3af'};">開発中 (draft)</span>
                            </div>
                            <div>
                                ${hasDraft 
                                    ? `<button type="button" class="btn btn-sm" style="background:#6366f1; color:#fff; border:none; padding: 5px 12px; border-radius: 6px; cursor: pointer; font-weight: 500;" onclick="selectFolder(event, '${path}/draft', ${currentIsPublic})">プレビュー</button>` 
                                    : `<span style="color: #9ca3af; font-size: 12px; font-weight: 500;">未着手</span>`}
                            </div>
                        </div>

                        <!-- 3. モック作成中 -->
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-radius: 8px; border: 1px solid ${hasMock ? '#fde68a' : '#e5e7eb'}; background: ${hasMock ? '#fffbeb' : '#f9fafb'};">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 16px;">📐</span>
                                <span style="font-weight: 600; color: ${hasMock ? '#92400e' : '#9ca3af'};">モック作成中 (mock)</span>
                            </div>
                            <div>
                                ${hasMock 
                                    ? `<button type="button" class="btn btn-sm" style="background:#f59e0b; color:#fff; border:none; padding: 5px 12px; border-radius: 6px; cursor: pointer; font-weight: 500;" onclick="selectFolder(event, '${path}/mock', ${currentIsPublic})">プレビュー</button>` 
                                    : `<span style="color: #9ca3af; font-size: 12px; font-weight: 500;">未着手</span>`}
                            </div>
                        </div>
                    </div>
                    <!-- GitHub同期ON/OFFなどの前に入れる区切り線 -->
                    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;">
                `;

                statusListEl.innerHTML = progressHtml;
            }

            // RAW URL のテキスト更新
            const cleanPath = path.replace(/^\/+/, '');
            const origin = window.location.origin;

            const tplPromptUrl = document.getElementById('tpl-prompt-url');
            if (tplPromptUrl) tplPromptUrl.innerText = hasMock ? `${origin}/${cleanPath}/mock/prompt.txt` : '-';

            const tplMockUrl = document.getElementById('tpl-mock-url');
            if (tplMockUrl) tplMockUrl.innerText = hasMock ? `${origin}/${cleanPath}/mock/index.php` : '-';

            const tplSpecUrl = document.getElementById('tpl-spec-url');
            if (tplSpecUrl) tplSpecUrl.innerText = hasDraft ? `${origin}/${cleanPath}/draft/dev_specs.txt` : '-';

            const tplCodeUrl = document.getElementById('tpl-code-url');
            if (tplCodeUrl) tplCodeUrl.innerText = hasDraft ? `${origin}/${cleanPath}/draft/index.php` : '-';
        }

        // 親フォルダ用のアクションボタン更新
        if (typeof updateActionButtons === 'function') {
            try { updateActionButtons(path, currentIsPublic); } catch (e) {}
        }

        return;

    } else {
        // =========================================================================
        // 【実行可能アイテム（draft / mock / 公開中アプリ）選択時】
        // =========================================================================
        if (folderOverlay) {
            folderOverlay.style.display = 'none';
        }
        if (welcomeArea) {
            welcomeArea.style.display = 'none';
        }

        // アクションボタンとプロンプト履歴の更新
        if (typeof updateActionButtons === 'function') {
            try { updateActionButtons(path, currentIsPublic); } catch (e) { console.error(e); }
        }
        if (typeof loadPromptHistory === 'function') {
            try { await loadPromptHistory(path); } catch (e) { console.error(e); }
        }

        // プレビュー表示
        if (previewFrame) {
            previewFrame.style.display = 'block';
            const cleanSub = path.replace(/^\/+/, '').replace(/\/+$/, '') + '/';
            const basePath = (typeof APP_BASE_PATH !== 'undefined' && APP_BASE_PATH) ? APP_BASE_PATH : '/';
            const baseOriginUrl = new URL(basePath, window.location.origin).href;
            previewFrame.src = new URL(cleanSub, baseOriginUrl).href;
        }
    }
}
// ==========================================================
// AI指示プロンプト一括コピー処理
// ==========================================================

// 共通URLを取得
function getAiPromptUrls() {
    const codeUrl = document.getElementById('tpl-code-url')?.innerText || '{index.phpのRAW URL}';
    const promptUrl = document.getElementById('tpl-prompt-url')?.innerText || '{prompt.txtのRAW URL}';
    const errorText = document.getElementById('tpl-error-input')?.value || '';

    return {
        codeUrl,
        promptUrl,
        errorText
    };
}
// ==========================================================
// ② エラー原因確認・要件修正用
// ==========================================================
function copyAiInstructionPromptFix() {
    const { codeUrl, promptUrl, errorText } = getAiPromptUrls();

    const fullPrompt = `${codeUrl}について
${errorText}

現在のコードを解析し再現性を高めるための要件${promptUrl}を精細し、
要件定義として追記、修正した方がよい事項がある場合は、
原文全体とのバランスを考えた適切な分量で、
コードブロックに表示してください。
ない場合は、改訂不要と回答してください。`;

    navigator.clipboard.writeText(fullPrompt).then(() => {
        alert('「原因確認・部分修正用」のAI指示プロンプトをコピーしました！');
    }).catch(err => {
        console.error('コピー失敗:', err);
    });
}
/**
 * 画面端に一時的な通知（トースト）を表示して消す関数
 */
function showSaveToast(message) {
    let toast = document.getElementById('global-save-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'global-save-toast';
        toast.style.cssText = `
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: rgba(33, 37, 41, 0.9);
            color: #fff;
            padding: 10px 18px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 99999;
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.3s ease, transform 0.3s ease;
            pointer-events: none;
        `;
        document.body.appendChild(toast);
    }

    toast.innerText = message;
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
    }, 2500);
}



// ==========================================================
// GitHub同期設定モーダルを閉じる
// ==========================================================

function closeGitHubSettings() {

    const modal =
        document.getElementById(
            'github-settings-modal'
        );


    if (modal) {

        modal.style.display =
            'none';
    }
}


// ==========================================================
// GitHub同期設定保存
// ==========================================================

// ==========================================================
// GitHub同期設定保存
// ==========================================================

// モーダル間・関数間で確実に引き継ぐための変数宣言
var currentSelectedGitHubAppPath = currentSelectedGitHubAppPath || '';

// ==========================================================
// GitHub同期設定モーダル表示・読み込み
// ==========================================================
// ==========================================================
// GitHub同期設定モーダル表示・読み込み
// ==========================================================
async function openGitHubSettings(appPath) {
    const modal = document.getElementById('github-settings-modal') || document.getElementById('github_settings_modal');
    if (!modal) {
        console.error('github-settings-modal が見つかりません');
        return;
    }

    // 1. 対象パス・アプリ名の自動特定（promptは完全撤去）
    let targetPath = (typeof appPath === 'string' && appPath !== '') ? appPath : '';

    if (!targetPath) {
        // (A) クリックされたイベント元（ボタンなど）から取得
        if (window.event && window.event.target) {
            const btn = window.event.target.closest('[data-path], [data-app], [data-name], button, a');
            if (btn) {
                targetPath = btn.dataset.path || btn.dataset.app || btn.dataset.name || btn.getAttribute('path') || '';
            }
        }
        
        // (B) 画面上のアクティブな要素や選択状態の要素から取得
        if (!targetPath) {
            const activeEl = document.querySelector('.active[data-path], .selected[data-path], [aria-selected="true"][data-path], .active[data-name], .selected[data-name]');
            if (activeEl) {
                targetPath = activeEl.dataset.path || activeEl.dataset.app || activeEl.dataset.name || '';
            }
        }

        // (C) 既存のグローバル変数から取得
        if (!targetPath) {
            if (typeof currentPath !== 'undefined' && currentPath) targetPath = currentPath;
            else if (typeof currentAppPath !== 'undefined' && currentAppPath) targetPath = currentAppPath;
            else if (typeof currentApp !== 'undefined' && currentApp) targetPath = currentApp;
            else if (typeof appName !== 'undefined' && appName) targetPath = appName;
            else if (typeof path !== 'undefined' && path) targetPath = path;
        }

        // (D) URLクエリパラメータから取得
        if (!targetPath) {
            const urlParams = new URLSearchParams(window.location.search);
            targetPath = urlParams.get('path') || urlParams.get('app') || urlParams.get('name') || urlParams.get('dir') || '';
        }
    }

    // パスが空でも「全体共通設定」としてモーダルを開く（エラーで止めない）
    console.log('GitHub設定対象パス:', targetPath);

    // グローバル保持
    window.currentSelectedGitHubAppPath = targetPath;

    // モーダル表示
    modal.style.display = 'block';

    const status = document.getElementById('github-settings-status') || document.getElementById('github_settings_status');
    if (status) {
        status.innerText = '設定を読み込んでいます...';
        status.style.color = '#666';
    }

    // フォーム初期化ヘルパー
    const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.value = val ?? '';
    };
    const setChecked = (id, checked) => {
        const el = document.getElementById(id);
        if (el) el.checked = !!checked;
    };

    // いったんフォームをクリア
    setVal('github-owner', '');
    setVal('github-repo', '');
    setVal('github-branch', 'main');
    setVal('github-token', '');
    setChecked('github-enabled', false);
    setChecked('github-proxy-enabled', false);
    setVal('github-proxy-host', '');
    setVal('github-proxy-port', '8080');
    setVal('github-proxy-username', '');
    setVal('github-proxy-password', '');

    // 2. 設定取得
    try {
        let res;
        if (typeof apiCall === 'function') {
            res = await apiCall('github_settings_get', { path: targetPath, app: targetPath, name: targetPath });
        } else {
            const fetchUrl = `?api=github_settings_get&path=${encodeURIComponent(targetPath)}`;
            const response = await fetch(fetchUrl);
            res = await response.json();
        }

        if (res && res.success) {
            setChecked('github-enabled', res.enabled === true);
            setVal('github-owner', res.owner || '');
            setVal('github-repo', res.repo || '');
            setVal('github-branch', res.branch || 'main');

            const tokenInput = document.getElementById('github-token');
            if (tokenInput) {
                tokenInput.value = '';
                tokenInput.placeholder = res.hasToken ? '（設定済み：変更する場合のみ入力）' : 'ghp_xxxxxxxxxxxx';
            }

            setChecked('github-proxy-enabled', res.proxy_enabled === true);
            setVal('github-proxy-host', res.proxy_host || '');
            setVal('github-proxy-port', res.proxy_port || '8080');
            setVal('github-proxy-username', res.proxy_username || '');

            const proxyPassInput = document.getElementById('github-proxy-password');
            if (proxyPassInput) {
                proxyPassInput.value = '';
                proxyPassInput.placeholder = res.hasProxyPassword ? '（設定済み：変更する場合のみ入力）' : '';
            }

            if (status) {
                if (res.hasToken) {
                    status.innerText = res.app_name ? `✓ [${res.app_name}] の設定を読み込みました` : '✓ 共通設定を読み込みました';
                    status.style.color = '#28a745';
                } else {
                    status.innerText = 'GitHub Tokenが設定されていません。';
                    status.style.color = '#666';
                }
            }
        } else {
            if (status) {
                status.innerText = (res && res.error) ? res.error : '設定の読み込みに失敗しました。';
                status.style.color = '#dc3545';
            }
        }
    } catch (e) {
        console.error('GitHub設定読み込みエラー:', e);
        if (status) {
            status.innerText = '設定の読み込みに失敗しました: ' + e.message;
            status.style.color = '#dc3545';
        }
    }
}

// ==========================================================
// GitHub同期設定保存
// ==========================================================
async function saveGitHubSettings() {
    try {
        // --- 要素の安全取得ヘルパー（'-' と '_' の両方から探す） ---
        const getVal = (key) => {
            const el = document.getElementById(`github-${key}`) || document.getElementById(`github_${key}`);
            return el ? el.value.trim() : '';
        };
        const getRawVal = (key) => {
            const el = document.getElementById(`github-${key}`) || document.getElementById(`github_${key}`);
            return el ? el.value : '';
        };
        const getChecked = (key, defaultVal = false) => {
            const el = document.getElementById(`github-${key}`) || document.getElementById(`github_${key}`);
            return el ? el.checked : defaultVal;
        };

        const enabled = getChecked('enabled', false);
        const owner = getVal('owner');
        const repo = getVal('repo');
        const branch = getVal('branch') || 'main';
        const token = getVal('token');

        const proxyEnabled = getChecked('proxy-enabled', false) || getChecked('proxy_enabled', false);
        const proxyHost = getVal('proxy-host') || getVal('proxy_host');
        const proxyPort = getVal('proxy-port') || getVal('proxy_port');
        const proxyUsername = getVal('proxy-username') || getVal('proxy_username');
        const proxyPassword = getRawVal('proxy-password') || getRawVal('proxy_password');

        const status = document.getElementById('github-settings-status') || document.getElementById('github_settings_status');

        // ======================================================
        // 対象パスの取得（確実に優先順位をつけて取得）
        // ======================================================
        let path = currentSelectedGitHubAppPath;
        if (!path) {
            if (typeof currentPath !== 'undefined' && currentPath) path = currentPath;
            else if (typeof currentAppPath !== 'undefined' && currentAppPath) path = currentAppPath;
            else if (document.getElementById('current-path')) path = document.getElementById('current-path').value;
            else if (document.getElementById('app-path')) path = document.getElementById('app-path').value;
            else {
                const urlParams = new URLSearchParams(window.location.search);
                path = urlParams.get('path') || urlParams.get('app') || '';
            }
        }

        if (!path) {
            alert('保存対象のアプリパスが特定できません。アプリを選択し直してください。');
            return;
        }

        // ======================================================
        // 入力チェック
        // ======================================================
        if (!owner) {
            alert('GitHubユーザー名を入力してください。');
            return;
        }
        if (!repo) {
            alert('リポジトリ名を入力してください。');
            return;
        }

        if (proxyEnabled) {
            if (!proxyHost) {
                alert('プロキシホストを入力してください。');
                return;
            }
            if (!proxyPort) {
                alert('プロキシポートを入力してください。');
                return;
            }
            const portNum = parseInt(proxyPort, 10);
            if (isNaN(portNum) || portNum < 1 || portNum > 65535) {
                alert('プロキシポートは1～65535の範囲で入力してください。');
                return;
            }
        }

        // ======================================================
        // 保存中表示
        // ======================================================
        if (status) {
            status.innerText = '保存しています...';
            status.style.color = '#666';
        }

        // ======================================================
        // API 送信ペイロード
        // ======================================================
        const payload = {
            path: path,
            enabled: enabled,
            owner: owner,
            repo: repo,
            branch: branch,
            token: token,
            proxy_enabled: proxyEnabled,
            proxy_host: proxyHost,
            proxy_port: proxyPort,
            proxy_username: proxyUsername,
            proxy_password: proxyPassword
        };

        // URLパラメータにも path を付与して確実にPHP側($_GET / $_REQUEST)で拾えるようにする
        const saveUrl = `?api=github_settings_save&path=${encodeURIComponent(path)}`;

        let res;
        if (typeof apiCall === 'function') {
            // apiCall が POSTパラメータを展開するタイプ・クエリパラメータ両対応
            res = await apiCall('github_settings_save', payload);
        } else {
            const response = await fetch(saveUrl, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            res = await response.json();
        }

        // ==================================================
        // 成功判定
        // ==================================================
        if (res && res.success) {
            if (status) {
                status.innerText = '✓ GitHub同期設定を保存しました。';
                status.style.color = '#28a745';
            }
            setTimeout(function () {
                if (typeof closeGitHubSettings === 'function') {
                    closeGitHubSettings();
                } else {
                    const modal = document.getElementById('github-settings-modal') || document.getElementById('github_settings_modal');
                    if (modal) modal.style.display = 'none';
                }
            }, 600);
        } else {
            const errMsg = (res && res.error) ? res.error : 'サーバー側で保存に失敗しました。';
            if (status) {
                status.innerText = errMsg;
                status.style.color = '#dc3545';
            }
            alert('保存エラー: ' + errMsg);
        }

    } catch (e) {
        console.error('GitHub設定保存エラー:', e);
        const status = document.getElementById('github-settings-status') || document.getElementById('github_settings_status');
        if (status) {
            status.innerText = 'エラー: ' + e.message;
            status.style.color = '#dc3545';
        }
        alert('処理中にエラーが発生しました:\n' + e.message);
    }
}

// ==========================================================
// アプリ別 GitHub同期状態の管理（グローバル初期化）
// ==========================================================
window.appSyncMemoryStore = window.appSyncMemoryStore || {};
window.activeAppPathForGitHub = window.activeAppPathForGitHub || '';

/**
 * バッジのテキストと色の更新
 */
function updateGitHubSyncBadge(isEnabled) {
    const badge = document.getElementById('app-github-sync-status-badge') || document.getElementById('app-github-sync-badge');
    if (!badge) return;

    if (isEnabled === true) {
        badge.textContent = '同期中';
        badge.className = 'badge bg-success';
        badge.style.setProperty('background', '#dcfce7', 'important');
        badge.style.setProperty('color', '#15803d', 'important');
    } else {
        badge.textContent = '無効';
        badge.className = 'badge bg-secondary';
        badge.style.setProperty('background', '#e2e8f0', 'important');
        badge.style.setProperty('color', '#64748b', 'important');
    }
}

/**
 * アプリ選択時に呼び出す関数
 */
window.setAppGitHubSyncTarget = async function(appPath) {
    window.activeAppPathForGitHub = appPath || '';
    const toggle = document.getElementById('app-github-sync-toggle');
    if (!toggle) return;

    toggle.dataset.path = window.activeAppPathForGitHub;

    // アプリ未選択時は無効化
    if (!window.activeAppPathForGitHub) {
        toggle.checked = false;
        toggle.disabled = true;
        updateGitHubSyncBadge(false);
        return;
    }

    toggle.disabled = false;
    let isEnabled = false;

    // ① キャッシュ（メモリ）に存在すれば最優先で採用
    if (window.appSyncMemoryStore.hasOwnProperty(window.activeAppPathForGitHub)) {
        isEnabled = Boolean(window.appSyncMemoryStore[window.activeAppPathForGitHub]);
    } else {
        // ② キャッシュにない場合はサーバーから取得
        try {
            if (typeof apiCall === 'function') {
                const res = await apiCall('github_settings_get', { path: window.activeAppPathForGitHub });
                if (res) {
                    const val = res.enabled !== undefined ? res.enabled : res.is_sync;
                    isEnabled = (val === true || val === 1 || val === '1' || val === 'true');
                }
            }
        } catch (e) {
            console.warn('GitHub設定取得エラー:', e);
            isEnabled = false;
        }
    }

    // メモリとUIに反映
    window.appSyncMemoryStore[window.activeAppPathForGitHub] = isEnabled;
    toggle.checked = isEnabled;
    updateGitHubSyncBadge(isEnabled);
};

// 互換性のためのエイリアス
window.syncAppGitHubToggle = window.setAppGitHubSyncTarget;

/**
 * ==============================================================================
 * GitHub同期・UI連携 完全安全構文版（クルクル待機対応）
 * ==============================================================================
 */

// 1. バッジ更新関数
window.updateGitHubSyncBadge = function(isEnabled) {
    var badge = document.getElementById('github-sync-status-badge');
    var dot = document.getElementById('github-sync-indicator-dot');
    var text = document.getElementById('github-sync-status-text');

    var active = Boolean(isEnabled);

    if (badge) {
        if (active) {
            badge.classList.remove('bg-gray-100', 'text-gray-600', 'border-gray-300');
            badge.classList.add('bg-green-50', 'text-green-700', 'border-green-300');
        } else {
            badge.classList.remove('bg-green-50', 'text-green-700', 'border-green-300');
            badge.classList.add('bg-gray-100', 'text-gray-600', 'border-gray-300');
        }
    }
    if (dot) {
        if (active) {
            dot.classList.remove('bg-gray-400');
            dot.classList.add('bg-green-500');
        } else {
            dot.classList.remove('bg-green-500');
            dot.classList.add('bg-gray-400');
        }
    }
    if (text) {
        text.innerText = active ? 'GitHub同期中' : 'GitHub未同期';
    }
};

// 2. RAW URL表示リセット
window.resetGitHubRawUrls = function() {
    var elPrompt = document.getElementById('tpl-prompt-url');
    var elMock = document.getElementById('tpl-mock-url');
    var elSpec = document.getElementById('tpl-spec-url');
    var elCode = document.getElementById('tpl-code-url');
    
    if (elPrompt) { elPrompt.innerText = '{mock/prompt.txtのRAW URL}'; }
    if (elMock)   { elMock.innerText   = '{mock/index.phpのRAW URL}'; }
    if (elSpec)   { elSpec.innerText   = '{draft/dev_specs.txtのRAW URL}'; }
    if (elCode)   { elCode.innerText   = '{draft/index.phpのRAW URL}'; }
};

// 3. トースト通知ヘルパー
if (typeof window.showSaveToast !== 'function') {
    window.showSaveToast = function(msg) {
        var toast = document.getElementById('save-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'save-toast';
            toast.style.position = 'fixed';
            toast.style.bottom = '20px';
            toast.style.right = '20px';
            toast.style.backgroundColor = '#1f2937';
            toast.style.color = '#ffffff';
            toast.style.padding = '10px 18px';
            toast.style.borderRadius = '6px';
            toast.style.zIndex = '99999';
            toast.style.fontSize = '14px';
            toast.style.boxShadow = '0 4px 6px -1px rgba(0, 0, 0, 0.1)';
            toast.style.transition = 'opacity 0.3s ease';
            document.body.appendChild(toast);
        }
        toast.innerText = msg;
        toast.style.opacity = '1';
        toast.style.display = 'block';
        setTimeout(function() {
            toast.style.opacity = '0';
            setTimeout(function() {
                toast.style.display = 'none';
            }, 300);
        }, 3000);
    };
}

// 4. トグル切り替え時の保存および解除処理（★クルクル対応）
window.toggleAppGitHubSync = async function(target) {
    var toggle = document.getElementById('app-github-sync-toggle');
    if (!toggle) {
        console.error('app-github-sync-toggle 要素が見つかりません');
        return;
    }

    var isChecked = false;
    if (typeof target === 'boolean') {
        isChecked = target;
    } else if (target && typeof target.checked === 'boolean') {
        isChecked = target.checked;
    } else {
        isChecked = Boolean(toggle.checked);
    }

    // 対象パスの安全な取得
    var targetPath = '';
    if (window.activeAppPathForGitHub) {
        targetPath = window.activeAppPathForGitHub;
    } else if (toggle.dataset && toggle.dataset.path) {
        targetPath = toggle.dataset.path;
    } else if (typeof currentSelectedGitHubAppPath !== 'undefined' && currentSelectedGitHubAppPath) {
        targetPath = currentSelectedGitHubAppPath;
    } else if (typeof currentPath !== 'undefined' && currentPath) {
        targetPath = currentPath;
    }

    console.log('[DEBUG toggle] 実行開始 - targetPath:', targetPath, 'isChecked:', isChecked);

    if (!targetPath) {
        alert('保存対象のアプリが特定できませんでした。アプリを選択し直してください。');
        toggle.checked = !isChecked;
        return;
    }

    // パス末尾のスラッシュを正規化
    targetPath = targetPath.replace(/\/+$/, '');

    // 処理中の二重クリック防止
    toggle.disabled = true;

    // ★ クルクル開始
    if (typeof startBlocking === 'function') {
        startBlocking(isChecked ? 'GitHub同期を有効化し、ファイルを反映中...' : 'GitHub同期を解除中...');
    }

    // ① 即時メモリとUIを更新
    if (window.appSyncMemoryStore) {
        window.appSyncMemoryStore[targetPath] = isChecked;
    }
    toggle.checked = isChecked;
    if (typeof updateGitHubSyncBadge === 'function') {
        updateGitHubSyncBadge(isChecked);
    }

    try {
        // ② 設定保存
        console.log('[DEBUG toggle] github_settings_save 送信:', { path: targetPath, enabled: isChecked ? 1 : 0 });
        var saveRes = await apiCall('github_settings_save', {
            path: targetPath,
            enabled: isChecked ? 1 : 0
        });
        console.log('[DEBUG toggle] github_settings_save 応答:', saveRes);

        if (!saveRes || !saveRes.success) {
            var errDetail = 'GitHub設定の保存APIが失敗しました';
            if (saveRes) {
                if (saveRes.error) {
                    errDetail = saveRes.error;
                } else if (saveRes.message) {
                    errDetail = saveRes.message;
                }
            }
            throw new Error(errDetail);
        }

        // ==========================================
        // ③ 同期ONの場合
        // ==========================================
        if (isChecked) {
            // --- コンテンツ（コード/モックHTML）の取得 ---
            var currentContent = '';
            if (typeof editor !== 'undefined' && editor && typeof editor.getValue === 'function') {
                currentContent = editor.getValue();
            } else {
                var editorSelectors = ['#editor-content', '#editor', '#code', 'textarea[name="content"]', 'textarea'];
                for (var i = 0; i < editorSelectors.length; i++) {
                    var el = document.querySelector(editorSelectors[i]);
                    if (el && el.value !== undefined) {
                        currentContent = el.value;
                        break;
                    }
                }
            }

            // --- プロンプトの取得 ---
            var promptContent = '';
            if (typeof currentPrompt !== 'undefined' && currentPrompt) {
                promptContent = currentPrompt;
            } else if (typeof activePrompt !== 'undefined' && activePrompt) {
                promptContent = activePrompt;
            } else {
                var promptSelectors = ['#prompt-content', '#prompt', '#app-prompt', '#user-prompt', 'textarea[name="prompt"]', '.prompt-input'];
                for (var j = 0; j < promptSelectors.length; j++) {
                    var pEl = document.querySelector(promptSelectors[j]);
                    if (pEl) {
                        if (pEl.value !== undefined && pEl.value !== '') {
                            promptContent = pEl.value;
                        } else if (pEl.innerText) {
                            promptContent = pEl.innerText;
                        }
                        if (promptContent) break;
                    }
                }
            }

            // --- ステージ（mock / draft）の判定 ---
            var stage = null;
            var currentStageEl = document.getElementById('current-stage');
            var stageAttr = '';
            if (toggle.dataset && toggle.dataset.stage) {
                stageAttr = toggle.dataset.stage;
            } else if (document.body && document.body.dataset && document.body.dataset.currentStage) {
                stageAttr = document.body.dataset.currentStage;
            } else if (currentStageEl && currentStageEl.value) {
                stageAttr = currentStageEl.value;
            }

            if (stageAttr === 'mock' || stageAttr === 'draft') {
                stage = stageAttr;
            }

            if (!stage) {
                if (targetPath.endsWith('/mock') || targetPath.indexOf('/mock/') !== -1) {
                    stage = 'mock';
                } else if (targetPath.endsWith('/draft') || targetPath.indexOf('/draft/') !== -1) {
                    stage = 'draft';
                }
            }

            if (!stage) {
                var mockSelectors = ['#mock-preview', '#mock-iframe', '#preview-frame', '.mock-container'];
                var mockFrame = null;
                for (var k = 0; k < mockSelectors.length; k++) {
                    var mEl = document.querySelector(mockSelectors[k]);
                    if (mEl) {
                        mockFrame = mEl;
                        break;
                    }
                }

                if (mockFrame && mockFrame.offsetParent !== null) {
                    stage = 'mock';
                } else {
                    var isMockActive = document.querySelector('.tab-mock.active, [data-tab="mock"].active, #tab-mock.active');
                    stage = isMockActive ? 'mock' : 'draft';
                }
            }

            console.log('[DEBUG toggle] 判定されたステージ:', stage);

            var basePath = targetPath.replace(/\/(mock|draft)$/, '');
            var finalPath = basePath + '/' + stage;

            // 1. メインファイル（index.php）の同期送信
            var isMockStage = (stage === 'mock');
            console.log('[DEBUG toggle] ' + stage + ' メインファイル同期 送信:', finalPath);
            
            var syncRes = await apiCall('github_sync', {
                path: finalPath,
                stage: stage,
                filename: 'index.php',
                content: currentContent,
                label: isMockStage ? 'モック同期' : '開発中コード同期',
                target_type: isMockStage ? 'mock' : 'code',
                file_type: isMockStage ? 'mock' : 'code'
            });
            console.log('[DEBUG toggle] ' + stage + ' メインファイル同期 応答:', syncRes);

            var rawUrl = '';
            if (syncRes) {
                if (syncRes.raw_url) {
                    rawUrl = syncRes.raw_url;
                } else if (syncRes.download_url) {
                    rawUrl = syncRes.download_url;
                }
            }
            if (rawUrl) {
                var targetUrlEl = isMockStage ? document.getElementById('tpl-mock-url') : document.getElementById('tpl-code-url');
                if (targetUrlEl) {
                    targetUrlEl.innerText = rawUrl;
                }
            }

            // 2. プロンプト（prompt.txt）の同期送信
            console.log('[DEBUG toggle] promptContent 確認:', promptContent);
            if (promptContent && promptContent.trim() !== '') {
                console.log('[DEBUG toggle] プロンプト同期 送信 (' + stage + '):', finalPath);
                var syncPromptRes = await apiCall('github_sync', {
                    path: finalPath,
                    stage: stage,
                    filename: 'prompt.txt',
                    content: promptContent,
                    label: 'プロンプト同期',
                    target_type: 'prompt',
                    file_type: 'prompt'
                });
                console.log('[DEBUG toggle] プロンプト同期 応答 (' + stage + '):', syncPromptRes);
            } else {
                console.warn('[DEBUG toggle] プロンプト内容が空のため prompt.txt の送信をスキップしました');
            }

            if (typeof showSaveToast === 'function') {
                showSaveToast('✅ GitHub同期を有効化しました（' + stage + '）');
            }
        } 
        // ==========================================
        // ④ 同期OFFの場合
        // ==========================================
        else {
            console.log('[DEBUG toggle] 同期解除 送信:', targetPath);
            var unsyncRes = await apiCall('github_unsync', {
                target: targetPath,
                path: targetPath,
                filenames: ['index.php', 'prompt.txt', 'dev_specs.txt']
            });
            console.log('[DEBUG toggle] 同期解除 応答:', unsyncRes);

            if (typeof resetGitHubRawUrls === 'function') {
                resetGitHubRawUrls();
            }

            if (typeof showSaveToast === 'function') {
                showSaveToast('GitHub同期を解除しました');
            }
        }

    } catch (err) {
        console.error('同期設定エラー詳細:', err);
        var msg = '';
        if (err && err.message) {
            msg = err.message;
        } else {
            msg = String(err);
        }
        alert('同期処理エラー: ' + msg);
        
        // ロールバック
        if (window.appSyncMemoryStore) {
            window.appSyncMemoryStore[targetPath] = !isChecked;
        }
        toggle.checked = !isChecked;
        if (typeof updateGitHubSyncBadge === 'function') {
            updateGitHubSyncBadge(!isChecked);
        }
    } finally {
        // ★ 処理完了後にクルクル解除とトグルの有効化
        if (typeof stopBlocking === 'function') {
            stopBlocking();
        }
        toggle.disabled = false;
    }
};

// 5. 画面初期化処理
function enforceDefaultOff() {
    var toggle = document.getElementById('app-github-sync-toggle');
    if (toggle && !window.activeAppPathForGitHub) {
        toggle.checked = false;
        if (typeof updateGitHubSyncBadge === 'function') {
            updateGitHubSyncBadge(false);
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enforceDefaultOff);
} else {
    enforceDefaultOff();
}
// 6. アプリ名生成関連ヘルパー
function getExistingDraftNames() {
    var names = [];
    var draftNodes = document.querySelectorAll('[id^=".poc/draft/"]');
    draftNodes.forEach(function(node) {
        var parts = node.id.split('/');
        var folderName = parts[parts.length - 1];
        if (folderName) {
            names.push(folderName.trim());
        }
    });
    return names;
}

function generateUniqueAppName(baseName) {
    var validBase = baseName ? baseName : 'NewApp';
    var existingNames = getExistingDraftNames();
    if (existingNames.indexOf(validBase) === -1) {
        return validBase;
    }

    var counter = 1;
    while (true) {
        var candidateName = validBase + '(' + counter + ')';
        if (existingNames.indexOf(candidateName) === -1) {
            return candidateName;
        }
        counter++;
    }
}
/**
 * 重複しないデフォルトのアプリ名を生成する関数
 */
function generateUniqueAppName(baseName = 'NewApp') {
    const existingNames = getExistingDraftNames();
    if (!existingNames.includes(baseName)) {
        return baseName;
    }

    let counter = 1;
    while (true) {
        const candidateName = `${baseName}(${counter})`;
        if (!existingNames.includes(candidateName)) {
            return candidateName;
        }
        counter++;
    }
}

async function createNewApp() {
    let promptMessage = '新規アプリ（モック）名を入力してください:';
    let currentInputName = typeof generateUniqueAppName === 'function' ? generateUniqueAppName('NewApp') : 'NewApp';
    let isCreating = true;
    let createdResult = null;
    let finalAppName = '';

    while (isCreating) {
        const newName = prompt(promptMessage, currentInputName);
        if (!newName) return;

        const res = await apiCall('create_app', { name: newName });
        if (res && res.success) {
            finalAppName = newName;
            createdResult = res;
            isCreating = false;
        } else {
            const errorMsg = res?.error || '';
            if (errorMsg.includes('存在') || errorMsg.includes('重複') || errorMsg.includes('exists') || errorMsg.includes('already')) {
                promptMessage = `「${newName}」は既に存在します。別の名前を入力してください。`;
                const match = newName.match(/(.*?)\((\d+)\)$/);
                if (match) {
                    currentInputName = `${match[1]}(${parseInt(match[2], 10) + 1})`;
                } else {
                    currentInputName = `${newName}(1)`;
                }
            } else {
                alert(res?.error || '作成に失敗しました。');
                return;
            }
        }
    }

    const createdAppPath = '.poc/' + finalAppName + '/mock';
    const initialPromptText = createdResult?.initial_prompt || '';

    // 下部領域の展開
    const bottomContainer = document.getElementById('bottom-container');
    const resizerV = document.getElementById('resizer-v');
    if (bottomContainer && resizerV) {
        bottomContainer.style.display = 'flex';
        resizerV.style.display = 'block';
        window.dispatchEvent(new Event('resize'));
    }

    // ChatAIプロンプトタブを選択
    const promptTabBtn = document.getElementById('prompt-tab') || 
                         document.querySelector('.tab-btn') || 
                         Array.from(document.querySelectorAll('button')).find(el => el.textContent.includes('ChatAI'));
    if (promptTabBtn) promptTabBtn.click();

    // プロンプト欄への初期設定
    const promptContentEl = document.getElementById('prompt-content');
    if (promptContentEl && initialPromptText) {
        promptContentEl.value = initialPromptText;
    }

    // GitHub同期トグルをOFFにリセット
    const gitToggle = document.getElementById('github-sync-toggle') || document.getElementById('github-toggle');
    if (gitToggle) {
        gitToggle.checked = false;
    }

    // ディレクトリツリー更新と作成先フォルダ選択
    if (typeof loadTrees === 'function') await loadTrees();
    if (typeof selectFolder === 'function') {
        await selectFolder({ ctrlKey: false, metaKey: false }, createdAppPath, false);
    }
}
// 全選択ボタンが押されたときの処理
// 全選択ボタンが押されたときの処理
function selectAllBtn() {
    const editor = document.getElementById("editor-content");
    
    if (editor) {
        editor.focus();
        editor.select(); // テキストを全選択するだけ（保存ボタンの処理は削除）
    }
}

function reloadPreview() { 
    const f = document.getElementById('preview-frame'); 
    f.src = f.src; 
}

function openInNewTab() { 
    if (currentPath) window.open('/gojacic/' + currentPath, '_blank'); 
}


const ctxMenu = document.getElementById('context-menu');
let ctxPath = '';
let ctxIsPublic = false;

// =========================================================
// 1. 右クリックメニュー表示 (showContext)
// =========================================================
// =========================================================
// 1. 右クリックメニュー表示 (showContext)
// =========================================================
async function showContext(e, path, isPublicPane) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    ctxPath = path || '';
    ctxIsPublic = Boolean(isPublicPane);

    let html = '';

    // =========================================================
    // 🌐 1. 【公開中ペイン】(isPublicPane === true)
    // =========================================================
    if (ctxIsPublic) {
        html += `<div class="menu-item" onclick="ctxAction('launch')">🚀 起動(別タブ)</div>`;
        html += `<div class="menu-item" onclick="ctxAction('copy_url')">🔗 URLをコピー</div>`;
        html += `<div class="menu-item" onclick="ctxAction('back_to_dev')">🔧 作成中に戻す</div>`;
    } 
    // =========================================================
    // 📁 2. 【作成中ペイン】(isPublicPane === false)
    // =========================================================
    else {
        // パス構造の解析
        const cleanPath = ctxPath.replace(/\/+$/, '');
        const isDraft = /\/draft$/i.test(cleanPath);
        const isMock = /\/mock$/i.test(cleanPath);
        const isParentFolder = !isDraft && !isMock;

        // DOM要素の探索
        const targetEl = e.target;
        const currentDetails = targetEl.closest('details');
        const appGroupEl = targetEl.closest('[data-type="app-group"]') || 
                           (currentDetails && currentDetails.getAttribute('data-type') === 'app-group' ? currentDetails : null);

        // 対象アプリが公開中かどうか（data-published="true"）
        const isAppPublished = Boolean(
            (appGroupEl && appGroupEl.getAttribute('data-published') === 'true') ||
            (currentDetails && currentDetails.getAttribute('data-published') === 'true')
        );

        // --------------------------------------------------
        // A. 親フォルダ (app-group: [name])
        // --------------------------------------------------
        if (isParentFolder) {
            if (isAppPublished) {
                // 公開中：アプリをコピーのみ
                html += `<div class="menu-item" onclick="ctxAction('instant_copy')">📄 アプリをコピーする</div>`;
            } else {
                // 未公開：履歴 / コピー / 名前変更 / 削除
                html += `
                    <div class="menu-item has-submenu" onmouseenter="preloadHistory(this)">
                        <span>⌛ 履歴から戻す</span>
                        <span>▶</span>
                        <div class="submenu" id="history-submenu-list">
                            <div style="padding: 8px 12px; color: #999;">読み込み中...</div>
                        </div>
                    </div>
                    <div class="menu-item" onclick="ctxAction('instant_copy')">📄 アプリをコピーする</div>
                    <div class="menu-item" onclick="ctxAction('rename_app')">✏️ アプリ名を変更する</div>
                    <div style="border-top: 1px solid #ddd; margin: 4px 0;"></div>
                    <div class="menu-item" onclick="ctxAction('delete')" style="color: red;">🗑️ アプリを削除する</div>
                `;
            }

        // --------------------------------------------------
        // B. 子フォルダ (app-item: 開発中 または モック)
        // --------------------------------------------------
        } else {
            // 親要素（appGroupEl）内に「開発中(draft)」が存在するか確認
            const hasDraft = appGroupEl 
                ? Boolean(appGroupEl.querySelector('[data-status="draft"], [data-path$="/draft"]')) 
                : false;

            // --- 開発中 (draft) ---
            if (isDraft) {
                html += `<div class="menu-item" onclick="ctxAction('publish')">🚀 公開する</div>`;
                
                // 親の中にモック(mock)が存在する場合のみ「モックに戻る」を表示
                const hasMock = appGroupEl 
                    ? Boolean(appGroupEl.querySelector('[data-status="mock"], [data-path$="/mock"]')) 
                    : false;

                if (hasMock) {
                    html += `<div class="menu-item" onclick="ctxAction('back_to_mock')">📐 モックに戻る</div>`;
                }

            // --- モック (mock) ---
            } else if (isMock) {
                // 既に開発中(draft)が存在する場合はモックのメニューを完全無効化
                if (hasDraft) {
                    if (ctxMenu) ctxMenu.style.display = 'none';
                    return;
                } else {
                    // モック作成中（開発前）のみ「開発に進める」を表示
                    html += `<div class="menu-item" onclick="ctxAction('promote_to_dev')">🛠️ 開発に進める</div>`;
                }
            }
        }
    }

    // メニュー項目がない場合は閉じる
    if (!html.trim()) {
        if (ctxMenu) ctxMenu.style.display = 'none';
        return;
    }

    // メニューの描画と位置補正
    ctxMenu.innerHTML = html;
    ctxMenu.style.position = 'fixed';
    ctxMenu.style.display = 'block';
    
    const menuWidth = ctxMenu.offsetWidth || 220;
    const menuHeight = ctxMenu.offsetHeight || 250;

    let clientX = e.clientX;
    let clientY = e.clientY;

    if (clientX + menuWidth > window.innerWidth) {
        clientX = window.innerWidth - menuWidth - 5;
    }
    if (clientY + menuHeight > window.innerHeight) {
        clientY = window.innerHeight - menuHeight - 5;
    }

    ctxMenu.style.left = clientX + 'px'; 
    ctxMenu.style.top = clientY + 'px';
}



// =========================================================
// 3. 履歴サブメニューの読み込み (preloadHistory)
// =========================================================
async function preloadHistory(itemEl) {
    const listEl = itemEl.querySelector('#history-submenu-list');
    if (!listEl) return;

    try {
        const res = await fetch(`/api/app/history?path=${encodeURIComponent(ctxPath)}`);
        const data = await res.json();
        
        if (data.history && data.history.length > 0) {
            listEl.innerHTML = data.history.map(item => `
                <div class="menu-item" onclick="ctxAction('restore_history', '${item.version}')">
                    <span>${item.date}</span>
                    <small style="color:#888; margin-left:8px;">${item.note || ''}</small>
                </div>
            `).join('');
        } else {
            listEl.innerHTML = '<div style="padding: 8px 12px; color: #999;">利用可能な履歴はありません</div>';
        }
    } catch (e) {
        listEl.innerHTML = '<div style="padding: 8px 12px; color: #999;">履歴がありません</div>';
    }
}
// 画面外クリックでメニューを閉じる処理
document.addEventListener('click', function() {
    if (ctxMenu) {
        ctxMenu.style.display = 'none';
    }
});
async function preloadHistory(containerEl) {
    if (!containerEl) return;
    const listContainer = containerEl.querySelector('#history-submenu-list');
    if (!listContainer) return;

    // 💡 既に読み込み済み、または読み込み中の多重呼び出しを即ブロック（6回リクエスト防止）
    if (listContainer.dataset.loaded === "true" || listContainer.dataset.loaded === "loading") {
        return;
    }

    listContainer.dataset.loaded = "loading";
    listContainer.style.minWidth = "320px";
    listContainer.innerHTML = '<div style="padding: 8px 12px; color: #888;">読み込み中...</div>';

    try {
        // 💡 .history 取得用API (get_history) を呼び出す
        const res = await apiCall('get_history', { path: ctxPath });

        if (res.success && Array.isArray(res.history) && res.history.length > 0) {
            let itemsHtml = '';

            res.history.forEach(h => {
                let statusLabel = '復元';
                let color = '#ff9800';

                if (h.status === 'draft') {
                    statusLabel = '作成中';
                    color = '#4caf50'; // 緑
                } else if (h.status === 'published') {
                    statusLabel = '公開時';
                    color = '#2196f3'; // 青
                } else if (h.status === 'original') {
                    statusLabel = '原本';
                    color = '#9c27b0'; // 紫
                }

                // ファイル名（例: 20260713232639_original.php や 20260902_173603_draft.php）から日時を解析
                let displayDate = h.date || '';
                const targetText = h.filename || '';

                if (typeof targetText === 'string') {
                    const match = targetText.match(/(\d{4})(\d{2})(\d{2})_?(\d{2})(\d{2})(\d{2})/);
                    if (match) {
                        const [, y, m, d, hh, mm, ss] = match;
                        displayDate = `${y}/${m}/${d} ${hh}:${mm}:${ss}`;
                    }
                }

                const displayFilename = targetText ? ` (${targetText})` : '';

                itemsHtml += `
                    <div class="menu-item history-item" onclick="event.stopPropagation(); restoreHistoryDirect('${targetText}', '${displayDate}')" style="white-space: nowrap; font-size: 11px; display: flex; align-items: center; justify-content: space-between; width: 100%; box-sizing: border-box; padding: 6px 12px; cursor: pointer;">
                        <div style="display: flex; align-items: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex-grow: 1; margin-right: 8px;">
                            <span style="color: ${color}; font-weight: bold; margin-right: 5px; flex-shrink: 0;">[${statusLabel}]</span>
                            <span style="margin-right: 6px; flex-shrink: 0;">${displayDate}</span>
                            <span style="color: #888; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${targetText}">${displayFilename}</span>
                        </div>
                        <span class="delete-history-btn" style="color: #ff4d4f; cursor: pointer; padding: 2px 6px; font-weight: bold; font-size: 14px; flex-shrink: 0; transition: color 0.2s;" onmouseenter="this.style.color='#ff1a1a'" onmouseleave="this.style.color='#ff4d4f'" title="削除" onclick="event.stopPropagation(); deleteHistoryFile('${targetText}', this);">×</span>
                    </div>`;
            });

            listContainer.innerHTML = itemsHtml;
        } else {
            listContainer.innerHTML = `<div style="padding: 8px 12px; color: #999;">バックアップ履歴なし</div>`;
        }
    } catch (e) {
        console.error(e);
        listContainer.innerHTML = `<div style="padding: 8px 12px; color: #f44336;">読み込み失敗</div>`;
    } finally {
        listContainer.dataset.loaded = "true";
    }
}

window.addEventListener('click', () => { ctxMenu.style.display = 'none'; });

function copyTextToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => alert('URLをコピーしました')).catch(() => fallbackCopyText(text));
    } else fallbackCopyText(text);
}
function fallbackCopyText(text) {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try { document.execCommand('copy'); } catch (e) { prompt('コピーできない場合は手動コピー:', text); }
    document.body.removeChild(ta);
}

function getParentDir(path) {
    const parts = path.split('/');
    parts.pop();
    return parts.join('/');
}

// =========================================================
// メニューのアクション実行ハンドラ (ctxAction)
// =========================================================
function extractAppNameFromPath(p) {
    if (!p) return '';
    const clean = p.replace(/\\/g, '/');
    const parts = clean.split('/').filter(part => {
        return part !== '' &&
               part !== 'gojacic' &&
               part !== '.poc' &&
               part !== 'poc' &&
               part !== 'published' &&
               part !== 'draft' &&
               part !== 'mock' &&
               part !== 'dev' &&
               part !== '.history';
    });
    return parts.length > 0 ? parts[0] : '';
}

async function ctxAction(act) {
    if (!ctxPath) return;

    const menu = document.getElementById('context-menu') || document.getElementById('ctx-menu');
    if (menu) menu.style.display = 'none';

    // ファイル・フォルダ単体の名前
    const baseName = ctxPath.split('/').filter(Boolean).pop();
    // アプリ全体の識別名
    const appName = extractAppNameFromPath(ctxPath) || baseName;

    // 1. ファイル新規作成
    if (act === 'new_file') {
        const fileName = prompt('新しいファイル名を入力してください (例: app.js):');
        if (!fileName) return;
        const cleanFileName = fileName.trim().replace(/^[\\/]+/, '');
        if (!cleanFileName) return;
        const targetPath = ctxPath.endsWith('/') ? ctxPath + cleanFileName : ctxPath + '/' + cleanFileName;
        startBlocking('ファイルを新規作成中...');
        try {
            await apiCall('save_file', { path: targetPath, content: '' });
            await loadTrees();
            if (typeof selectFile === 'function') selectFile(targetPath);
        } catch (err) {
            alert('作成に失敗しました: ' + (err.message || err));
        } finally {
            stopBlocking();
        }

    // 2. フォルダ新規作成
    } else if (act === 'new_dir') {
        const dirName = prompt('新しいフォルダ名を入力してください:');
        if (!dirName) return;
        const cleanDirName = dirName.trim().replace(/^[\\/]+/, '');
        if (!cleanDirName) return;
        const targetPath = ctxPath.endsWith('/') ? ctxPath + cleanDirName : ctxPath + '/' + cleanDirName;
        startBlocking('フォルダを新規作成中...');
        try {
            await apiCall('new_dir', { path: targetPath });
            await loadTrees();
        } catch (err) {
            alert('作成に失敗しました: ' + (err.message || err));
        } finally {
            stopBlocking();
        }

    // 3. 名前変更
    } else if (act === 'rename') {
        const newName = prompt('新しい名前を入力してください:', baseName);
        if (!newName || newName === baseName) return;
        const cleanNewName = newName.trim();
        if (/[\\/:*?"<>|]/.test(cleanNewName)) {
            alert('使用できない記号が含まれています。');
            return;
        }
        const pathParts = ctxPath.split('/').filter(Boolean);
        pathParts.pop();
        const parentPath = pathParts.join('/');
        const newPath = parentPath ? (parentPath + '/' + cleanNewName) : cleanNewName;

        startBlocking('名前を変更中...');
        try {
            const res = await apiCall('rename', { old_path: ctxPath, new_path: newPath });
            if (res && res.success) {
                if (window.appSyncMemoryStore && window.appSyncMemoryStore.hasOwnProperty(ctxPath)) {
                    window.appSyncMemoryStore[newPath] = window.appSyncMemoryStore[ctxPath];
                    delete window.appSyncMemoryStore[ctxPath];
                }
                await loadTrees();
            } else {
                alert('名前変更に失敗しました:\n' + ((res && res.error) ? res.error : JSON.stringify(res)));
            }
        } catch (err) {
            alert('通信エラーが発生しました:\n' + (err.message || err));
        } finally {
            stopBlocking();
        }

    // 4. 単体コピー
    } else if (act === 'copy') {
        const newName = prompt('コピー先の名前を入力してください:', baseName + '_copy');
        if (!newName) return;
        const cleanNewName = newName.trim();
        const pathParts = ctxPath.split('/').filter(Boolean);
        pathParts.pop();
        const parentPath = pathParts.join('/');
        const newPath = parentPath ? (parentPath + '/' + cleanNewName) : cleanNewName;

        startBlocking('複製処理を実行中...');
        try {
            const res = await apiCall('copy', { src: ctxPath, dst: newPath });
            if (res && res.success) {
                await loadTrees();
            } else {
                alert('コピーに失敗しました:\n' + ((res && res.error) ? res.error : JSON.stringify(res)));
            }
        } catch (err) {
            alert('通信エラーが発生しました:\n' + (err.message || err));
        } finally {
            stopBlocking();
        }

    // 5. 削除
    } else if (act === 'delete') {
        if (!confirm(`本当に「${baseName}」を削除してもよろしいですか？\n※この操作は元に戻せません。`)) return;
        startBlocking('削除処理を実行中...');
        try {
            const res = await apiCall('delete', { target: ctxPath });
            if (res && res.success) {
                if (window.appSyncMemoryStore) delete window.appSyncMemoryStore[ctxPath];
                await loadTrees();
            } else {
                alert('削除に失敗しました:\n' + ((res && res.error) ? res.error : JSON.stringify(res)));
            }
        } catch (err) {
            alert('通信エラーが発生しました:\n' + (err.message || err));
        } finally {
            stopBlocking();
        }

    // 6. アプリ複製
    } else if (act === 'instant_copy') {
        let newName = prompt('コピー先のアプリ（フォルダ）名を入力してください:', appName + '_copy');
        if (!newName) return;
        newName = newName.trim();
        if (/[\\/:*?"<>|]/.test(newName)) {
            alert('フォルダ名に使用できない記号が含まれています。');
            return;
        }

        const cleanDst = extractAppNameFromPath(newName) || newName;
        const isPub = ctxPath.startsWith('published/');
        const srcAppDir = isPub ? ('published/' + appName) : ('.poc/' + appName);
        const dstAppDir = isPub ? ('published/' + cleanDst) : ('.poc/' + cleanDst);

        startBlocking('アプリ全体の複製処理を実行中...');
        try {
            const res = await apiCall('copy', { src: srcAppDir, dst: dstAppDir });
            if (res && res.success) {
                await loadTrees();
            } else {
                alert('コピーに失敗しました:\n' + ((res && res.error) ? res.error : JSON.stringify(res)));
            }
        } catch (err) {
            alert('通信エラーが発生しました:\n' + (err.message || err));
        } finally {
            stopBlocking();
        }

    // 7. 本番公開（dev -> published）
    } else if (act === 'publish') {
        if (!confirm(`「${appName}」を本番環境へ公開しますか？`)) return;
        startBlocking('本番公開処理を実行中...');
        try {
            const res = await apiCall('publish', {
                app_name: appName,
                src: ctxPath
            });
            if (res && res.success) {
                await loadTrees();
                alert('本番公開が完了しました。');
            } else {
                alert('公開に失敗しました:\n' + ((res && res.error) ? res.error : JSON.stringify(res)));
            }
        } catch (err) {
            alert('公開エラー:\n' + (err.message || err));
        } finally {
            stopBlocking();
        }

    // 8. 公開取り下げ・開発に戻す（back_to_dev: published -> dev & snapshot記録）
    } else if (
        act === 'back_to_dev' || 
        act === 'restore_to_dev' || 
        act === 'revert_to_dev' || 
        act === 'restore' || 
        act === 'revert' || 
        act === 'to_dev' || 
        act === 'unpublish'
    ) {
        if (!confirm(`「${appName}」の公開を取り下げ、開発環境（.poc/${appName}/dev）へ戻しますか？\n※現在の本番公開データはdevへ同期され、公開フォルダは削除されます。`)) return;
        startBlocking('公開を取り下げ、開発環境へ復元中...');
        try {
            const res = await apiCall('back_to_dev', {
                app_name: appName,
                src: ctxPath
            });

            if (res && res.success) {
                await loadTrees();
                alert('開発環境への復元（公開取り下げ）が完了しました。');
            } else {
                alert('復元に失敗しました:\n' + ((res && res.error) ? res.error : JSON.stringify(res)));
            }
        } catch (err) {
            alert('復元エラー:\n' + (err.message || err));
        } finally {
            stopBlocking();
        }
    }
}
// =========================================================================
// 境界リサイズロジック（横・縦比率 ＆ ウィンドウリサイズ追従対応版）
// =========================================================================
const resizer1 = document.getElementById('resizer-1');
const resizer2 = document.getElementById('resizer-2');
const resizerV = document.getElementById('resizer-v');
const resizer3 = document.getElementById('resizer-3');
const resizer4 = document.getElementById('resizer-4');

// ★ 追加した縦リサイザー
const resizerGithubV = document.getElementById('resizer-github-v');
const resizerPromptHistoryV = document.getElementById('resizer-prompt-history-v');

const colPublic = document.getElementById('col-public');
const colDraft = document.getElementById('col-draft');
const editorArea = document.getElementById('editor-area');
const previewArea = document.getElementById('preview-area');
const iframeCover = document.getElementById('iframe-cover');

// 下段の「分割実行エリア」を取得
const splitArea = document.getElementById('split-area');
const promptPanelLeft = document.getElementById('prompt-panel-left');

// ★ 追加した縦リサイズ対象要素
const githubFixedCard = document.getElementById('github-fixed-card');
const promptHistoryWrapper = document.getElementById('prompt-history-wrapper');

// 各パネルの「比率（親要素に対する割合）」を保持する変数
const panelRatios = {
    'col-public': 0.15,
    'col-draft': 0.15,
    'editor-holder-split': 0.7,
    'prompt-panel-left': 0.7,
    'github-fixed-card': 0.25,        // GitHubエリアの初期縦比率（約25%）
    'prompt-history-wrapper': 0.3      // 履歴エリアの初期縦比率（約30%）
};

// 【横方向（X）イベント登録】
if (resizer1 && colPublic) resizer1.addEventListener('mousedown', initResizeX(colPublic, resizer1, 'col-public'));
if (resizer2 && colDraft) resizer2.addEventListener('mousedown', initResizeX(colDraft, resizer2, 'col-draft'));

const holderSplit = document.getElementById('editor-holder-split');
if (resizer3 && holderSplit) {
    resizer3.addEventListener('mousedown', initResizeX(holderSplit, resizer3, 'editor-holder-split'));
}

if (resizer4 && promptPanelLeft) {
    resizer4.addEventListener('mousedown', initResizeX(promptPanelLeft, resizer4, 'prompt-panel-left'));
}

// 【縦方向（Y）イベント登録】
if (resizerV && previewArea) {
    resizerV.addEventListener('mousedown', initResizeY(previewArea, resizerV));
}

// ★ GitHubエリアの縦リサイズ登録（下側要素の伸縮）
if (resizerGithubV && githubFixedCard) {
    resizerGithubV.addEventListener('mousedown', initResizeYBottom(githubFixedCard, resizerGithubV, 'github-fixed-card', 80));
}

// ★ 履歴一覧エリアの縦リサイズ登録（下側要素の伸縮）
if (resizerPromptHistoryV && promptHistoryWrapper) {
    resizerPromptHistoryV.addEventListener('mousedown', initResizeYBottom(promptHistoryWrapper, resizerPromptHistoryV, 'prompt-history-wrapper', 60));
}

/**
 * X方向（横幅）のリサイズロジック
 */
function initResizeX(targetElement, resizer, ratioKey) {
    return function(e) {
        e.preventDefault();
        const parentElement = targetElement.parentElement;
        if (!parentElement) return;

        const startWidth = targetElement.offsetWidth;
        const startX = e.clientX;
        
        if (iframeCover) iframeCover.style.display = 'block';
        document.body.style.userSelect = 'none';

        function doResize(ev) {
            const newWidth = startWidth + (ev.clientX - startX);
            const parentWidthCurrent = parentElement.clientWidth;

            if (newWidth > 150 && newWidth < (parentWidthCurrent - 100)) {
                const ratio = newWidth / parentWidthCurrent;
                panelRatios[ratioKey] = ratio;

                targetElement.style.flex = `0 0 ${ratio * 100}%`;
                targetElement.style.width = 'auto';
            }
        }

        function stopResize() {
            if (iframeCover) iframeCover.style.display = 'none';
            document.body.style.userSelect = '';
            window.removeEventListener('mousemove', doResize);
            window.removeEventListener('mouseup', stopResize);
        }

        window.addEventListener('mousemove', doResize);
        window.addEventListener('mouseup', stopResize);
    };
}

/**
 * ★ Y方向（下部パネル高さ）のリサイズロジック（下から上へドラッグで拡大）
 */
function initResizeYBottom(targetElement, resizer, ratioKey, minHeight = 60) {
    return function(e) {
        e.preventDefault();
        const parentElement = targetElement.parentElement;
        if (!parentElement) return;

        const startHeight = targetElement.offsetHeight;
        const startY = e.clientY;

        if (iframeCover) iframeCover.style.display = 'block';
        document.body.style.userSelect = 'none';

        function doResize(ev) {
            const deltaY = startY - ev.clientY; // 上に引くとプラス
            const newHeight = startHeight + deltaY;
            const parentHeightCurrent = parentElement.clientHeight;

            if (newHeight >= minHeight && newHeight <= (parentHeightCurrent - 80)) {
                const ratio = newHeight / parentHeightCurrent;
                panelRatios[ratioKey] = ratio;

                targetElement.style.height = `${newHeight}px`;
                targetElement.style.flex = `0 0 ${newHeight}px`;
            }
        }

        function stopResize() {
            if (iframeCover) iframeCover.style.display = 'none';
            document.body.style.userSelect = '';
            window.removeEventListener('mousemove', doResize);
            window.removeEventListener('mouseup', stopResize);
        }

        window.addEventListener('mousemove', doResize);
        window.addEventListener('mouseup', stopResize);
    };
}

/**
 * 🌟 ウィンドウサイズ変更時に比率を再適用
 */
function applyRatioWidths() {
    if (colPublic && colPublic.offsetHeight > 0) {
        colPublic.style.flex = `0 0 ${panelRatios['col-public'] * 100}%`;
    }
    if (colDraft && colDraft.offsetHeight > 0) {
        colDraft.style.flex = `0 0 ${panelRatios['col-draft'] * 100}%`;
    }
    if (holderSplit && holderSplit.offsetHeight > 0) {
        holderSplit.style.flex = `0 0 ${panelRatios['editor-holder-split'] * 100}%`;
    }
    if (promptPanelLeft && promptPanelLeft.offsetHeight > 0) {
        promptPanelLeft.style.flex = `0 0 ${panelRatios['prompt-panel-left'] * 100}%`;
    }
    // 縦リサイズ要素の比率追従
    if (githubFixedCard && githubFixedCard.parentElement) {
        const h = githubFixedCard.parentElement.clientHeight * panelRatios['github-fixed-card'];
        if (h >= 80) {
            githubFixedCard.style.height = `${h}px`;
            githubFixedCard.style.flex = `0 0 ${h}px`;
        }
    }
    if (promptHistoryWrapper && promptHistoryWrapper.parentElement) {
        const h = promptHistoryWrapper.parentElement.clientHeight * panelRatios['prompt-history-wrapper'];
        if (h >= 60) {
            promptHistoryWrapper.style.height = `${h}px`;
            promptHistoryWrapper.style.flex = `0 0 ${h}px`;
        }
    }
}

// ウィンドウリサイズイベントにバインド
window.addEventListener('resize', applyRatioWidths);

// 各タブの定義（キー、対象ファイル名、プレースホルダー等）
window.POC_TAB_CONFIG = {
    'spec-common': {
        name: '① 業務・操作要件',
        targetFile: 'spec/common.md',
        promptPlaceholder: '【業務・操作要件】\nアプリの目的、利用ユーザー、必要な画面構成、操作フローを入力してください...',
        editorPlaceholder: '生成された業務・操作要件 (spec/common.md) がここに入ります'
    },
    'mock': {
        name: '② モック (mock)',
        targetFile: 'mock/index.php',
        promptPlaceholder: '【モック作成】\nモック用の指示を入力してください（UIデザインのトーン、ダミーデータの仕様など）...',
        editorPlaceholder: '生成されたモック単一コード (mock/index.php) がここに入ります'
    },
    'spec-dev': {
        name: '③ 実装要件・制約',
        targetFile: 'spec/dev.md',
        promptPlaceholder: '【実装要件・制約】\n本番実装に向けた制約を入力してください（PHP仕様、DBテーブル定義、バリデーション等）...',
        editorPlaceholder: '生成された実装要件 (spec/dev.md) がここに入ります'
    },
    'dev': {
        name: '④ 本番コード (dev)',
        targetFile: 'dev/index.php',
        promptPlaceholder: '【本番コード】\n本番コード生成の指示を入力してください（実DB連携、認証、エラーハンドリング等）...',
        editorPlaceholder: '生成された本番単一コード (dev/index.php) がここに入ります'
    }
};

// 現在選択中のPoCタブ（初期値）
window.currentPocTab = 'spec-common';

// 【下段左右の専用ドラッグ・タブ切り替え関数】
window.switchBottomTab = function(targetTab) {
    try {
        const newScreen = document.getElementById('poc-main-work-area') || document.getElementById('new-screen-area');
        const originalPair = document.getElementById('original-pair-group');
        const buttons = document.querySelectorAll('.bottom-tab-btn');

        const editorArea = document.getElementById('editor-area');
        const tab1Container = newScreen;
        const tab2LeftHolder = document.getElementById('editor-holder-split');

        const promptTextarea = document.getElementById('prompt-content');
        const editorTextarea = document.getElementById('editor-content');
        const pasteBtn = document.getElementById('btn-paste-source');
        const selectAllBtn = document.getElementById('btn-select-all');

        // 1. ロックの解除
        if (promptTextarea) {
            promptTextarea.readOnly = false;
            promptTextarea.disabled = false;
            promptTextarea.style.removeProperty('background-color');
            promptTextarea.style.removeProperty('color');
            promptTextarea.style.pointerEvents = 'auto';
        }
        if (editorTextarea) {
            editorTextarea.readOnly = false;
            editorTextarea.disabled = false;
            editorTextarea.style.pointerEvents = 'auto';
        }
        if (pasteBtn) {
            pasteBtn.disabled = false;
            pasteBtn.style.removeProperty('opacity');
            pasteBtn.style.removeProperty('pointer-events');
        }
        if (selectAllBtn) {
            selectAllBtn.disabled = false;
            selectAllBtn.style.removeProperty('opacity');
            selectAllBtn.style.removeProperty('pointer-events');
        }

        // 2. 表示リセット
        if (newScreen) newScreen.style.setProperty('display', 'none', 'important');
        if (originalPair) originalPair.style.setProperty('display', 'none', 'important');

        // 3. ボタンの装飾更新
        buttons.forEach((btn) => {
            const btnTab = btn.getAttribute('data-tab') || '';
            const onclickAttr = btn.getAttribute('onclick') || '';
            const isTarget = btnTab === targetTab || onclickAttr.includes(`'${targetTab}'`) || btn.id === `tab-btn-${targetTab}`;

            btn.disabled = false;
            btn.style.pointerEvents = 'auto';
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';

            btn.style.fontWeight = isTarget ? 'bold' : 'normal';
            btn.style.background = isTarget ? '#ffffff' : '#f0f0f0';
            btn.style.color = isTarget ? '#1a73e8' : '#666666';
            btn.style.borderBottom = isTarget ? '1px solid #ffffff' : '1px solid #ccc';
        });

        // 4. 対象タブに応じたレイアウト切り替え
        if (targetTab === 'editor-split' || targetTab === 'pro-split') {
            if (editorArea && tab2LeftHolder) {
                editorArea.style.flex = "1 1 100%";
                editorArea.style.width = "";
                editorArea.style.maxWidth = "none";
                tab2LeftHolder.appendChild(editorArea);

                tab2LeftHolder.style.padding = "0";
                tab2LeftHolder.style.margin = "0";
                tab2LeftHolder.style.display = "flex";
                tab2LeftHolder.style.flexDirection = "column";
            }
            if (originalPair) originalPair.style.setProperty('display', 'flex', 'important');

            if (typeof split === 'function') {
                try { split(); } catch (e) { console.warn(e); }
            }
        } else {
            window.currentPocTab = targetTab;
            
            const config = (window.POC_TAB_CONFIG && window.POC_TAB_CONFIG[targetTab]) ? window.POC_TAB_CONFIG[targetTab] : {
                targetFile: targetTab === 'mock' ? 'mock/index.php' : (targetTab === 'spec-dev' ? 'spec/dev.md' : (targetTab === 'dev' ? 'dev/index.php' : 'spec/common.md')),
                promptPlaceholder: 'プロンプトを入力してください...',
                editorPlaceholder: 'ソースコードを入力してください...'
            };

            if (editorArea && tab1Container && editorArea.parentElement !== tab1Container) {
                editorArea.style.flex = "1 1 0%";
                editorArea.style.width = "";
                editorArea.style.maxWidth = "none";
                tab1Container.appendChild(editorArea);
            }
            if (newScreen) newScreen.style.setProperty('display', 'flex', 'important');

            const targetFilenameEl = document.getElementById('target-filename');
            if (targetFilenameEl && config.targetFile) {
                targetFilenameEl.textContent = config.targetFile;
            }

            if (promptTextarea && config.promptPlaceholder) {
                promptTextarea.placeholder = config.promptPlaceholder;
            }

            if (editorTextarea && config.editorPlaceholder) {
                editorTextarea.placeholder = config.editorPlaceholder;
            }
        }

        // 比率再計算
        if (typeof applyRatioWidths === 'function') {
            setTimeout(applyRatioWidths, 10);
        }
    } catch (err) {
        console.error('switchBottomTab error:', err);
    }
};

// ★ エイリアス定義（関数直下に配置済み）
window.switchPoCTab = window.switchBottomTab;

// ★ クリックイベントの確実なバインドと初期タブ表示
function initPoCTabs() {
    const buttons = document.querySelectorAll('.bottom-tab-btn');
    buttons.forEach(btn => {
        btn.onclick = function(e) {
            e.preventDefault();
            const onclickAttr = this.getAttribute('onclick') || '';
            const match = onclickAttr.match(/['"]([^'"]+)['"]/);
            const target = this.getAttribute('data-tab') || (match ? match[1] : null);
            if (target) {
                window.switchBottomTab(target);
            }
        };
    });

    window.switchBottomTab(window.currentPocTab || 'spec-common');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPoCTabs);
} else {
    initPoCTabs();
}

function initResizeY(targetElement, resizer) {
    return function(e) {
        e.preventDefault();
        const startHeight = targetElement.offsetHeight;
        const startY = e.clientY;
        
        const parentElement = targetElement.parentElement;
        const bottomContainer = parentElement.querySelector('.bottom-container');

        if (iframeCover) {
            iframeCover.style.display = 'block';
        }

        function doResize(ev) {
            const dy = ev.clientY - startY;
            const newHeight = startHeight + dy;
            const parentHeight = parentElement.offsetHeight;
            const newBottomHeight = parentHeight - newHeight - resizer.offsetHeight;

            if (newHeight > 100 && newBottomHeight > 120) {
                targetElement.style.height = newHeight + 'px';
                if (bottomContainer) {
                    bottomContainer.style.height = newBottomHeight + 'px';
                    bottomContainer.style.flex = 'none';
                }
            }
        }

        function stopResize() {
            if (iframeCover) {
                iframeCover.style.display = 'none';
            }
            window.removeEventListener('mousemove', doResize);
            window.removeEventListener('mouseup', stopResize);
        }

        window.addEventListener('mousemove', doResize);
        window.addEventListener('mouseup', stopResize);
    };
}
// file情報を保持するオブジェクト（共通で使われている前提）
// file = { name: '', ext: '', text: '', b64: '' };

function loadFromTextArea() {
  // 1. 対象のテキストエリアからテキストを取得
  const textArea = document.getElementById('editor-content');
  if (!textArea) return;
  const textContent = textArea.value;

  // 2. 画面表示（文字数・トークン数・ステータスエリア）の更新（null安全化）
  const cCountEl = document.getElementById('cCount');
  if (cCountEl) {
    cCountEl.textContent = textContent.length.toLocaleString();
  }

  const tCountEl = document.getElementById('tCount');
  if (tCountEl && typeof estTokens === 'function') {
    tCountEl.textContent = estTokens(textContent).toLocaleString();
  }

  const statsEl = document.getElementById('stats');
  if (statsEl) {
    statsEl.style.display = 'block';
  }

  // 3. 分割処理の実行（テキストを直接渡す）
  if (typeof split === 'function') {
    split(textContent); 
  }

  // 4. 変更検知による保存ボタンの表示/非表示切り替え
  const saveBtn = document.getElementById('btn-save');
  if (saveBtn) {
    // originalEditorContent が未定義の場合にも備えてフォールバック
    const original = typeof originalEditorContent !== 'undefined' ? originalEditorContent : '';
    if (textContent !== original) {
      saveBtn.style.display = 'inline-block'; // 変更がある時は表示
    } else {
      saveBtn.style.display = 'none';         // 元と同じなら非表示
    }
  }
}
const RATE = 2.2;
function getBytes(s) {
  return new Blob([s]).size;
}
function estTokens(s) {
  return Math.ceil(getBytes(s) / RATE);
}
function toggleSet() {
  const mode = document.getElementById('mode').value;
  const isT = (mode === 'token' || mode === 'lang');
  document.getElementById('setT').style.display = isT ? 'block' : 'none';
  document.getElementById('setC').style.display = isT ? 'none' : 'block';
}

function split(rawText) {
  // 1. モードの取得（要素が存在しない場合は 'char' またはデフォルトにフォールバック）
  const modeEl = document.getElementById('mode');
  const mode = modeEl ? modeEl.value : 'char';

  parts = [];

  // 2. テキストの取得（引数があればそれを優先、なければテキストエリアから取得）
  let text = typeof rawText === 'string' ? rawText : '';
  if (!text) {
    const textArea = document.getElementById('editor-content');
    text = textArea ? textArea.value : '';
  }

  // 3. テキストが空なら処理を中断
  if (!text) {
    return;
  }

  // 4. トークン・文字数設定の取得（要素が存在しない場合の安全なフォールバック）
  const rateVal = typeof RATE !== 'undefined' ? RATE : 1;
  const tgtTEl = document.getElementById('tgtT');
  const maxB = (tgtTEl ? (parseInt(tgtTEl.value, 10) || 4000) : 4000) * rateVal;

  if (mode === 'lang') {
    if (typeof splitByLanguage === 'function') {
      splitByLanguage(text, maxB);
    }
  } else if (mode === 'token') {
    if (typeof splitStructure === 'function') {
      const rawParts = splitStructure(text, maxB);
      parts = rawParts.map((p, i) => ({ name: `PART ${i+1}`, code: p }));
    }
  } else {
    const tgtCEl = document.getElementById('tgtC');
    const len = tgtCEl ? (parseInt(tgtCEl.value, 10) || 4000) : 4000;
    
    // base64モードの場合
    let src = text;
    if (mode === 'base64') {
      try {
        src = btoa(unescape(encodeURIComponent(text)));
      } catch (e) {
        console.error("Base64変換に失敗しました:", e);
        return;
      }
    }

    if (!src) return;
    let idx = 1;
    for (let i = 0; i < src.length; i += len) {
      parts.push({ name: `PART ${idx++}`, code: src.slice(i, i + len) });
    }
  }

  // 5. レンダリング実行
  if (typeof render === 'function') {
    render();
  }
}

function splitByLanguage(fullTxt, maxB) {
  let phpCode = "", cssCode = "", jsCode = "", htmlCode = fullTxt;

  // PHP抽出
  const phpRegex = /<\?php[\s\S]*?\?>/gi;
  const phpMatches = htmlCode.match(phpRegex);
  if (phpMatches) {
    phpCode = phpMatches.join("\n\n");
    htmlCode = htmlCode.replace(phpRegex, "");
  }

  // CSS抽出
  const cssRegex = /<style[\s\S]*?>([\s\S]*?)<\/style>/gi;
  let match;
  while ((match = cssRegex.exec(htmlCode)) !== null) {
    cssCode += match[1] + "\n";
  }
  htmlCode = htmlCode.replace(cssRegex, "<!-- [CSS_BLOCK] -->");

  // JS抽出 (干渉防止のため閉じタグ判定を文字列結合で分離)
  const jsRegex = new RegExp('<script[\\s\\S]*?>([\\s\\S]*?)<\\/script' + '>', 'gi');
  while ((match = jsRegex.exec(htmlCode)) !== null) {
    jsCode += match[1] + "\n";
  }
  htmlCode = htmlCode.replace(jsRegex, "<!-- [JS_BLOCK] -->");

  // 各種言語をToken制限値で構造分割
  const phpSub = splitStructure(phpCode.trim(), maxB);
  const cssSub = splitStructure(cssCode.trim(), maxB);
  const htmlSub = splitStructure(htmlCode.trim(), maxB);
  const jsSub = splitStructure(jsCode.trim(), maxB);

  // ① PHP
  phpSub.forEach((code, i) => {
    if (code) parts.push({ name: `1_PHP_${i+1}`, code: code });
  });

  // ② CSS (カプセル化タグを文字列結合で安全に記述)
  cssSub.forEach((code, i) => {
    if (code) parts.push({ name: `2_CSS_${i+1}`, code: "<style>\n" + code + "\n</style>" });
  });

  // ③ HTML (プレースホルダーを消去)
  const cleanHtml = htmlSub.join("\n").replace(/<!-- \[[A-Z0-9_]+\] -->\n?/g, "").trim();
  if (cleanHtml) {
    const cleanHtmlSub = splitStructure(cleanHtml, maxB);
    cleanHtmlSub.forEach((code, i) => {
      parts.push({ name: `3_HTML_${i+1}`, code: code });
    });
  }

  // ④ JS (カプセル化タグをスクリプト終了タグの解釈エラーを避けるため結合式にして格納)
  jsSub.forEach((code, i) => {
    if (code) parts.push({ name: `4_JS_${i+1}`, code: "<script>\n" + code + "\n" + "<\/script" + ">" });
  });
}

function splitStructure(code, maxB) {
  if (!code) return [];
  const lines = code.replace(/\r\n/g, "\n").split("\n");
  let res = [], cur = [], curB = 0, nest = 0;
  const limit = maxB * 1.15;

  for (let line of lines) {
    let len = getBytes(line) + 1;
    if (curB + len > limit && cur.length) {
      res.push(cur.join("\n"));
      cur = []; curB = 0;
    }
    cur.push(line);
    curB += len;

    nest += (line.match(/{/g) || []).length - (line.match(/}/g) || []).length;

    if (curB >= maxB * 0.7 && nest <= 0) {
      res.push(cur.join("\n"));
      cur = []; curB = 0;
    }
  }
  if (cur.length) res.push(cur.join("\n"));
  return res;
}

function render() {
  const tabW = document.getElementById('tabW');
  const panesContainer = document.querySelector('.split-panes-container');
  
  // 初期化（一回中身を空にする）
  tabW.innerHTML = '';
  if (panesContainer) panesContainer.innerHTML = '';
  
  if (!parts.length) { 
    document.getElementById('res').style.display = 'none'; 
    return; 
  }

  // 1. タブボタン作成エリア
  const tabContainer = document.createElement('div');
  tabContainer.className = 'tabs';
  
  parts.forEach((item, i) => {
    const btn = document.createElement('div');
    btn.className = `tab ${i === 0 ? 'active' : ''}`;
    btn.textContent = item.name;
    btn.onclick = () => switchTab(i); // switchTab関数を呼び出す
    tabContainer.appendChild(btn);
  });
  tabW.appendChild(tabContainer);

  // 2. 各テキストエリア（パネル）作成エリア
  parts.forEach((item, i) => {
    const pane = document.createElement('div');
    pane.id = `pane-${i}`;
    pane.className = `pane ${i === 0 ? 'active' : ''}`;

    pane.innerHTML = `
      <div class="banner">
        <span><strong>${item.name}</strong> (${item.code.length}文字 / 推定 ${estTokens(item.code)}T)</span>
        <button onclick="copy(this, 'ta-${i}')">コピー</button>
      </div>
      <textarea id="ta-${i}" readonly onclick="this.select()"></textarea>
    `;
    pane.querySelector('textarea').value = item.code;
    
    // 正しいコンテナ（.split-panes-container）の中に追加します
    if (panesContainer) {
      panesContainer.appendChild(pane);
    } else {
      // 念のためのフォールバック（HTML側で見つからない場合）
      tabW.appendChild(pane);
    }
  });

  // 結果エリアを表示（display: flex に設定）
  document.getElementById('res').style.display = 'flex';
}

function switchTab(idx) {
  document.querySelectorAll('.tab').forEach((t, i) => t.classList.toggle('active', i === idx));
  document.querySelectorAll('.pane').forEach((p, i) => p.classList.toggle('active', i === idx));
}

function copy(btn, id) {
  const ta = document.getElementById(id);
  
  // 対象テキストエリアを全選択状態にする
  ta.select();
  ta.setSelectionRange(0, 99999); // モバイル端末用フォーカス保証

  // 1. モダンな Clipboard API によるコピー試行
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(ta.value).then(() => {
      showSuccess(btn);
    }).catch(() => {
      fallbackCopy(ta, btn);
    });
  } else {
    // 2. 非SSL(HTTP)環境やセキュリティエラー時のための従来型フォールバック
    fallbackCopy(ta, btn);
  }
}

function fallbackCopy(textarea, button) {
  try {
    const successful = document.execCommand('copy');
    if (successful) {
      showSuccess(button);
    } else {
      alert('コピーに失敗しました。テキストエリアの選択範囲を直接コピーしてください。');
    }
  } catch (err) {
    alert('お使いのブラウザはコピー機能をサポートしていません。');
  }
}

function showSuccess(btn) {
  const old = btn.textContent;
  btn.textContent = "OK";
  setTimeout(() => btn.textContent = old, 1000);
}

function switchBottomTab(targetTab) {
    const originalPair = document.getElementById('original-pair-group');
    const newScreen = document.getElementById('new-screen-area');
    const buttons = document.querySelectorAll('.bottom-tab-btn');

    // 移動させる対象のエディタと、それぞれの引っ越し先コンテナを取得
    const editorArea = document.getElementById('editor-area');
    const tab1Container = document.getElementById('new-screen-area');
    const tab2LeftHolder = document.getElementById('editor-holder-split');

    if (targetTab === 'new-screen') {
        // --- 1. エディタを「ChatAIプロンプト」タブの右側へ戻す ---
        if (editorArea && tab1Container) {
            tab1Container.appendChild(editorArea);
        }

        // 「ChatAIプロンプト」を表示
        if (originalPair) originalPair.style.setProperty('display', 'none', 'important');
        if (newScreen) newScreen.style.setProperty('display', 'flex', 'important'); // 横並びにするためflexに修正

        // タブ1（ChatAIプロンプト）：アクティブ（白 ＋ 上部にブルーのアクセント）
        buttons[0].style.background = '#ffffff';
        buttons[0].style.color = '#333333';
        buttons[0].style.borderColor = '#ccc';
        buttons[0].style.borderBottomColor = '#ffffff';
        buttons[0].style.borderTopColor = '#007acc';
        buttons[0].style.fontWeight = 'bold';
        buttons[0].style.zIndex = '1';

        // タブ2（エディタ ＆ 分割）：非アクティブ（薄いグレー）
        buttons[1].style.background = '#f0f0f0';
        buttons[1].style.color = '#666666';
        buttons[1].style.borderColor = '#dcdcdc';
        buttons[1].style.borderBottomColor = '#ccc';
        buttons[1].style.borderTopColor = 'transparent';
        buttons[1].style.fontWeight = 'normal';
        buttons[1].style.zIndex = '0';
    } else {
        // --- 2. エディタを「エディタ ＆ 分割」タブの左側のホルダーへ引っ越す ---
        if (editorArea && tab2LeftHolder) {
            tab2LeftHolder.appendChild(editorArea);
        }

        // 「エディタ ＆ 分割」を表示
        if (originalPair) originalPair.style.setProperty('display', 'flex', 'important');
        if (newScreen) newScreen.style.setProperty('display', 'none', 'important');

        // タブ1（ChatAIプロンプト）：非アクティブ（薄いグレー）
        buttons[0].style.background = '#f0f0f0';
        buttons[0].style.color = '#666666';
        buttons[0].style.borderColor = '#dcdcdc';
        buttons[0].style.borderBottomColor = '#ccc';
        buttons[0].style.borderTopColor = 'transparent';
        buttons[0].style.fontWeight = 'normal';
        buttons[0].style.zIndex = '0';

        // タブ2（エディタ ＆ 分割）：アクティブ（白 ＋ 上部にブルーのアクセント）
        buttons[1].style.background = '#ffffff';
        buttons[1].style.color = '#333333';
        buttons[1].style.borderColor = '#ccc';
        buttons[1].style.borderBottomColor = '#ffffff';
        buttons[1].style.borderTopColor = '#007acc';
        buttons[1].style.fontWeight = 'bold';
        buttons[1].style.zIndex = '1';

        // 画面切り替え時に自動的に分割処理を実行させる場合
        if (typeof split === 'function') {
            split();
        }
    }
}

// ページ表示時や初期化時に履歴およびプロンプト状態を自動で読み込む
// ページ表示時や初期化時に履歴およびプロンプト状態を自動で読み込む
// ==========================================
// 2. 結合が終わった後にJavaScriptで受け取る
// ==========================================


// ページリロードや画面離脱のタイミングで該当データを削除
window.addEventListener('beforeunload', () => {
    localStorage.removeItem(HARNESS_STORAGE_KEY);
});

// ページ表示時や初期化時に自動読み込み
document.addEventListener('DOMContentLoaded', () => {
    // 1. 最後に開いていたパスを復元
    const lastPath = localStorage.getItem("prompt_last_path");
    if (lastPath && (typeof currentPath !== "undefined")) {
        currentPath = lastPath;
    }

    // 2. プロンプト入力状態のロード
    if (typeof loadPromptState === 'function') {
        loadPromptState();
    } else {
        console.error("loadPromptState 関数が定義されていません。");
    }

    // 3. 履歴をロード
    const area = document.getElementById('new-screen-area');
    if (area) {
        const isVisible = area.style.display !== 'none';
        if (isVisible && typeof loadPromptHistory === 'function') {
            loadPromptHistory();
        }
    }
});

/**
 * OSのファイル名に使えない禁止文字（ \ / : * ? " < > | ）を自動でハイフンに置換する関数
 */
function sanitizeFileName(name) {
    if (!name) return "";
    return name
        .replace(/[\\/:*?"<>|]/g, '-')
        .replace(/[\r\n\t]/g, ' ')
        .replace(/^\.+/, '')
        .trim();
}

// ==========================================================
// 1. ソースコード保存 ＆ チェックポイント作成 (saveFile)
// ==========================================================
async function saveFile() {
    if (!currentPath) {
        alert('対象のディレクトリ / パスが選択されていません。');
        return;
    }

    const saveBtn = document.getElementById('save-btn') ||
                    document.getElementById('btn-save') ||
                    document.querySelector('button[onclick*="saveFile"]') ||
                    document.querySelector('.btn-save');

    const originalText = saveBtn ? saveBtn.innerText : '保存 (Ctrl+S)';

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerText = '保存中...';
    }

    let isSuccess = false;

    try {
        // エディタ内容の取得
        let content = '';
        if (typeof editor !== 'undefined' && editor && typeof editor.getValue === 'function') {
            content = editor.getValue();
        } else {
            const editorEl = document.getElementById('editor-content') ||
                             document.getElementById('editor') ||
                             document.getElementById('code') ||
                             document.querySelector('textarea[name="content"]') ||
                             document.querySelector('textarea');
            if (editorEl) content = editorEl.value;
        }

        // メモ欄とプロンプト欄の取得
        const memoEl = document.getElementById('prompt-memo') || document.getElementById('save-memo');
        const memo = memoEl ? memoEl.value.trim() : '';
        const promptEl = document.getElementById('prompt-content') || document.getElementById('prompt');
        const prompt = promptEl ? promptEl.value : '';

        // 現在のタブ/種別の判定 ('mock' | 'dev' 等)
        const currentType = (typeof activeTab !== 'undefined' && activeTab) ? activeTab : 'dev';

        // ① 新チェックポイント保存APIを呼び出し（上書き ＋ メモがあれば履歴作成）
        const saveRes = await apiCall('create_checkpoint', {
            path: currentPath,
            type: currentType,
            content: content,
            memo: memo,
            prompt: prompt
        });

        if (!saveRes || !saveRes.success) {
            alert('保存に失敗しました。\n\n' + (saveRes && saveRes.error ? saveRes.error : ''));
            return;
        }

        // 履歴を作成した場合はメモ欄をクリア
        if (saveRes.checkpoint_created && memoEl) {
            memoEl.value = '';
        }

        // ② GitHub同期
        const githubRes = await apiCall('github_sync', {
            path: currentPath,
            filename: 'index.php',
            content: content,
            label: memo || 'ファイル更新',
            target_type: 'code',
            file_type: 'code'
        });

        if (!githubRes || !githubRes.success) {
            alert('ローカル保存は成功しましたが、GitHub同期に失敗しました。\n\n' + (githubRes && githubRes.error ? githubRes.error : ''));
            return;
        }

        // ③ 指示文の RAW URL 更新
        const rawUrl = (githubRes && (githubRes.raw_url || githubRes.download_url)) || '';
        if (rawUrl) {
            const tplCodeEl = document.getElementById('tpl-code-url');
            if (tplCodeEl) tplCodeEl.innerText = rawUrl;
        }

        if (typeof originalEditorContent !== 'undefined') {
            originalEditorContent = content;
        }
        isSuccess = true;

        if (typeof showSaveToast === 'function') {
            showSaveToast(saveRes.checkpoint_created ? '✅ 保存 ＆ 履歴作成完了' : '✅ 上書き保存完了');
        }
        if (typeof loadTrees === 'function') loadTrees();
        if (typeof reloadPreview === 'function') reloadPreview();
        if (typeof loadCheckpointHistory === 'function') loadCheckpointHistory(); // 履歴一覧を再描画

    } catch (e) {
        console.error('保存処理エラー:', e);
        alert('保存処理中にエラーが発生しました。\n' + (e.message || e));
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerText = originalText;
            if (isSuccess) saveBtn.style.display = 'none';
        }
    }
}

// ==========================================================
// 2. 入力中テキストのブラウザ一時保持（ローカルストレージ）
// ==========================================================
function savePromptState() {
    if (typeof currentPath === "undefined" || !currentPath) return;

    const memo = document.getElementById("prompt-memo") ? document.getElementById("prompt-memo").value : '';
    const content = document.getElementById("prompt-content") ? document.getElementById("prompt-content").value : '';

    const state = {
        memo: memo,
        content: content,
        timestamp: Date.now()
    };
    localStorage.setItem("prompt_state_" + currentPath, JSON.stringify(state));
}

function loadPromptState() {
    const contentInput = document.getElementById("prompt-content");
    const memoInput = document.getElementById("prompt-memo");
    if (!contentInput || !currentPath) return;

    const savedData = localStorage.getItem("prompt_state_" + currentPath);
    if (savedData) {
        try {
            const state = JSON.parse(savedData);
            if (contentInput) contentInput.value = state.content || "";
            if (memoInput) memoInput.value = state.memo || "";
        } catch (e) {
            console.error("プロンプト状態の復元に失敗:", e);
        }
    }
}
// ==========================================================
// 2. ハーネス管理
// ==========================================================
const HARNESS_STORAGE_KEY = 'gojacic_app_harness_data';
const SERVER_HARNESS_DATA = <?php echo isset($json_harness_init) ? $json_harness_init : '""'; ?>;

function initHarnessData(serverData) {
    let dataToSave = '';
    if (typeof serverData === 'object' && serverData !== null) {
        dataToSave = serverData.data ?? serverData.content ?? '';
    } else if (typeof serverData === 'string') {
        dataToSave = serverData;
    }

    if (typeof dataToSave === 'string') {
        dataToSave = dataToSave.trim();
    } else {
        dataToSave = String(dataToSave).trim();
    }

    if (dataToSave !== '') {
        localStorage.setItem(HARNESS_STORAGE_KEY, dataToSave);
    }
}

function getLatestHarnessData() {
    if (typeof SERVER_HARNESS_DATA !== 'undefined' && SERVER_HARNESS_DATA) {
        initHarnessData(SERVER_HARNESS_DATA);
    }
    return localStorage.getItem(HARNESS_STORAGE_KEY) || '';
}

// ==========================================================
// 3. PoC タブ & ファイル連動マネージャー（データ破壊防止ガード付き）
// ==========================================================
(function() {
    window.currentPocTab = window.currentPocTab || 'spec-common';

    const POC_TAB_CONFIG = {
        'spec-common': { targetFile: 'spec/common.md', promptPlaceholder: '共通要件のプロンプトを入力...', editorPlaceholder: '共通要件（spec/common.md）の内容...' },
        'mock':        { targetFile: 'mock/index.php',  promptPlaceholder: 'モック作成のプロンプトを入力...', editorPlaceholder: 'モックコード（mock/index.php）...' },
        'spec-dev':    { targetFile: 'spec/dev.md',     promptPlaceholder: '開発要件のプロンプトを入力...', editorPlaceholder: '開発要件（spec/dev.md）の内容...' },
        'dev':         { targetFile: 'dev/index.php',   promptPlaceholder: '実装プロンプトを入力...', editorPlaceholder: '本番コード（dev/index.php）...' }
    };

    function getActiveAppPath() {
        if (typeof currentPath !== 'undefined' && currentPath) return currentPath;
        if (window.currentAppName) return window.currentAppName;
        const appSelector = document.getElementById('app-select') || document.querySelector('[name="app_name"]');
        if (appSelector && appSelector.value) return appSelector.value;
        return '';
    }

    // 読み込み専用：サーバーの既存ファイルを壊さない安全読み込み
    window.loadFileFromServer = async function(targetFile) {
        const editorTextarea = document.getElementById('editor-content');
        const appPath = getActiveAppPath();
        if (!editorTextarea) return;
        if (!appPath) {
            return;
        }

        try {
            const url = `?action=load_poc_file&app_name=${encodeURIComponent(appPath)}&target_file=${encodeURIComponent(targetFile)}`;
            const res = await fetch(url);
            const data = await res.json();
            if (data.success) {
                editorTextarea.value = data.content;
            } else {
                // ファイルが存在しない場合は空欄で待機（旧ファイルの上書きを防ぐ）
                editorTextarea.value = '';
            }
        } catch (err) {
            console.error('読み込み通信エラー:', err);
        }
    };

    // 明示的な保存ボタン押下時のみ動作
    window.saveCurrentFile = async function() {
        const config = POC_TAB_CONFIG[window.currentPocTab];
        const appPath = getActiveAppPath();
        const editorTextarea = document.getElementById('editor-content');

        if (!config || !config.targetFile) return;
        if (!appPath) {
            alert('アプリ/パスが選択されていません。');
            return;
        }
        if (!editorTextarea) return;

        if (!confirm(`「${config.targetFile}」を保存しますか？`)) {
            return;
        }

        try {
            const res = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_poc_file',
                    app_name: appPath,
                    target_file: config.targetFile,
                    content: editorTextarea.value
                })
            });
            const data = await res.json();
            if (data.success) {
                alert(data.message || '保存が完了しました。');
            } else {
                alert('保存失敗: ' + data.error);
            }
        } catch (err) {
            alert('保存通信エラーが発生しました。');
            console.error(err);
        }
    };

    // タブ切り替え
    window.switchBottomTab = function(targetTab) {
        try {
            const newScreen = document.getElementById('poc-main-work-area') || document.getElementById('new-screen-area');
            const originalPair = document.getElementById('original-pair-group');
            const buttons = document.querySelectorAll('.bottom-tab-btn');

            const editorArea = document.getElementById('editor-area');
            const tab1Container = newScreen;
            const tab2LeftHolder = document.getElementById('editor-holder-split');

            const promptTextarea = document.getElementById('prompt-content');

            if (newScreen) newScreen.style.setProperty('display', 'none', 'important');
            if (originalPair) originalPair.style.setProperty('display', 'none', 'important');

            buttons.forEach((btn) => {
                const btnTab = btn.getAttribute('data-tab') || '';
                const onclickAttr = btn.getAttribute('onclick') || '';
                const isTarget = btnTab === targetTab || onclickAttr.includes(`'${targetTab}'`) || btn.id === `tab-btn-${targetTab}`;

                btn.style.fontWeight = isTarget ? 'bold' : 'normal';
                btn.style.background = isTarget ? '#ffffff' : '#f0f0f0';
                btn.style.color = isTarget ? '#1a73e8' : '#666666';
                btn.style.borderBottom = isTarget ? '1px solid #ffffff' : '1px solid #ccc';
            });

            if (targetTab === 'editor-split' || targetTab === 'pro-split') {
                if (editorArea && tab2LeftHolder) {
                    editorArea.style.flex = "1 1 100%";
                    editorArea.style.width = "";
                    editorArea.style.maxWidth = "none";
                    tab2LeftHolder.appendChild(editorArea);
                    tab2LeftHolder.style.display = "flex";
                }
                if (originalPair) originalPair.style.setProperty('display', 'flex', 'important');
                if (typeof split === 'function') {
                    try { split(); } catch (e) { console.warn(e); }
                }
            } else {
                window.currentPocTab = targetTab;
                const config = POC_TAB_CONFIG[targetTab] || POC_TAB_CONFIG['spec-common'];

                if (editorArea && tab1Container && editorArea.parentElement !== tab1Container) {
                    editorArea.style.flex = "1 1 0%";
                    editorArea.style.width = "";
                    editorArea.style.maxWidth = "none";
                    tab1Container.appendChild(editorArea);
                }
                if (newScreen) newScreen.style.setProperty('display', 'flex', 'important');

                const targetFilenameEl = document.getElementById('target-filename');
                if (targetFilenameEl && config.targetFile) {
                    targetFilenameEl.textContent = config.targetFile;
                }
                if (promptTextarea && config.promptPlaceholder) {
                    promptTextarea.placeholder = config.promptPlaceholder;
                }

                // 安全な読み込み処理の実行
                window.loadFileFromServer(config.targetFile);
            }

            if (typeof applyRatioWidths === 'function') {
                setTimeout(applyRatioWidths, 10);
            }
        } catch (err) {
            console.error('switchBottomTab error:', err);
        }
    };

    window.switchPoCTab = window.switchBottomTab;

    function initPoCTabs() {
        const buttons = document.querySelectorAll('.bottom-tab-btn');
        buttons.forEach(btn => {
            btn.onclick = function(e) {
                e.preventDefault();
                const onclickAttr = this.getAttribute('onclick') || '';
                const match = onclickAttr.match(/['"]([^'"]+)['"]/);
                const target = this.getAttribute('data-tab') || (match ? match[1] : null);
                if (target) window.switchBottomTab(target);
            };
        });

        window.switchBottomTab(window.currentPocTab || 'spec-common');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPoCTabs);
    } else {
        initPoCTabs();
    }
})();


/**
 * プロンプトをクリップボードにコピーする関数（安全なフォールバック対応 ＆ アラート後に全選択）
 */
/**
 * プロンプトをクリップボードにコピーする関数
 * （保存・GitHub同期を実行し、RAW URLと最新ハーネス情報を付加してコピー）
 */
async function copyPromptToClipboard() {
    const contentInput = document.getElementById("prompt-content");
    if (!contentInput) {
        alert("コピー対象のプロンプトが見つかりませんでした。");
        return;
    }

    let textToCopy = contentInput.value;
    if (!textToCopy) {
        alert("コピーするプロンプト内容が空です。");
        return;
    }

    // ① 先にプロンプトを保存＆GitHub同期
    if (typeof savePrompt === 'function') {
        try {
            await savePrompt();
        } catch (saveErr) {
            console.error("保存・GitHub同期中にエラーが発生しました:", saveErr);
        }
    }

    // ② 最新のハーネス情報を取得して更新
    try {
        const res = await apiCall('get_harness', []);

        // res がオブジェクトで data プロパティを持つ場合（空文字含む）
        let newHarnessData = null;
        if (res && typeof res === 'object') {
            if ('data' in res) {
                newHarnessData = res.data;
            } else if ('content' in res) {
                newHarnessData = res.content;
            }
        } else if (typeof res === 'string') {
            newHarnessData = res;
        }

        // 文字列が存在する場合のみ localStorage を更新
        if (typeof newHarnessData === 'string' && newHarnessData.trim() !== '') {
            initHarnessData(newHarnessData);
        }
    } catch (err) {
        console.error("ハーネス情報の最新化に失敗しました:", err);
    }

    // ③ localStorage からハーネス情報を取得して結合
    if (typeof getLatestHarnessData === 'function') {
        const harnessText = getLatestHarnessData();
        if (harnessText && harnessText.trim() !== "") {
            textToCopy += "\n\n" + harnessText.trim();
        }
    }

    // ④ クリップボードへのコピー処理
    if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
        navigator.clipboard.writeText(textToCopy)
            .then(() => {
                if (typeof showCopySuccessMessage === 'function') {
                    showCopySuccessMessage();
                } else {
                    const statusEl = document.getElementById('prompt-status');
                    if (statusEl) {
                        statusEl.innerText = '📋 コピー完了';
                        setTimeout(() => { statusEl.innerText = ''; }, 2000);
                    }
                }
            })
            .catch(err => {
                console.warn("Clipboard API でのコピーに失敗しました。フォールバックを実行します:", err);
                if (typeof fallbackCopyText === 'function') {
                    fallbackCopyText(textToCopy);
                }
            });
    } else {
        if (typeof fallbackCopyText === 'function') {
            fallbackCopyText(textToCopy);
        }
    }
}
/**
 * HTTP環境や古いブラウザでも動作する、従来のコピー処理（フォールバック）
 */
function fallbackCopyText(text) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    
    // 画面外に配置して見えないようにする
    textArea.style.position = "fixed";
    textArea.style.top = "-9999px";
    textArea.style.left = "-9999px";
    document.body.appendChild(textArea);
    
    textArea.focus();
    textArea.select();

    try {
        const successful = document.execCommand("copy");
        if (successful) {
            showCopySuccessMessage();
        } else {
            alert("コピーに失敗しました。お手数ですが手動で選択してコピーしてください。");
        }
    } catch (err) {
        console.error("フォールバックコピー中にエラーが発生しました:", err);
        alert("コピー処理を実行できませんでした。");
    }

    document.body.removeChild(textArea);
}

/*
 * コピー成功時にプロンプト入力欄を全選択し、その上にアラートを表示する関数
 */
function showCopySuccessMessage() {
    // 1. まずテキストを全選択状態にする（フォーカス ＆ 全選択）
    const contentInput = document.getElementById("prompt-content");
    if (contentInput) {
        contentInput.focus();
        contentInput.select();
    }

    // 2. 選択状態の描画をブラウザに完了させてから、アラートを表示する
    setTimeout(() => {
        alert("プロンプトをクリップボードにコピーしました！");
    }, 10); // 10ミリ秒だけずらして実行
    // 3. プロンプト履歴として保存する
    savePrompt();
}

// 貼り付け用関数（自動ペースト成功時、および手動ペースト時ともに保存ボタンを出します）
async function pasteSourceClipboard() {
    const editor = document.getElementById("editor-content");
    const saveBtn = document.getElementById("btn-save");

    if (!editor) return;

    try {
        // 1. クリップボードからの自動読み取りを試行（HTTPS環境のみ動作）
        if (navigator.clipboard && typeof navigator.clipboard.readText === "function") {
            const text = await navigator.clipboard.readText();
            
            if (text) {
                editor.focus();
                
                // 💡 前の文字を残さず、クリップボードの内容で完全に上書き
                editor.value = text;

                // テキスト変更イベントを明示的に発火
                editor.dispatchEvent(new Event('input', { bubbles: true }));

                if (typeof loadFromTextArea === "function") {
                    loadFromTextArea();
                }

                // 保存ボタンを表示
                if (saveBtn) {
                    saveBtn.style.display = "inline-block";
                }

                // 貼り付け完了後に全選択（ハイライト）して知らせる
                setTimeout(() => {
                    editor.focus();
                    editor.select();
                    editor.setSelectionRange(0, editor.value.length);
                }, 50);

                return;
            }
        }
    } catch (err) {
        // ユーザーがクリップボードの読み取り権限を拒否した場合や、エラー時はフォールバックへ
        console.warn("Navigator Clipboard API 制限または拒否により、手動貼り付けへフォールバックします:", err);
    }

    // 2. 自動読み取りが拒否された場合のフォールバック（手動貼り付けの案内）
    // 💡 あらかじめテキストエリア内をクリアしてから選択状態にする（手動ペースト時も以前の文字が混ざらないようにするため）
    editor.value = ""; 
    editor.focus();
    
    const originalPlaceholder = editor.placeholder;
    editor.placeholder = "【ここにCtrl+VまたはCmd+Vでコードを貼り付けてください】";
    
    const pasteHandler = function() {
        // setTimeoutの競合を防ぐため少し猶予を持たせる
        setTimeout(() => {
            if (typeof loadFromTextArea === "function") {
                loadFromTextArea();
            }
            // 手動貼り付け検知時に保存ボタンを表示
            if (saveBtn) {
                saveBtn.style.display = "inline-block";
            }
            
            // 手動ペーストされた後も、綺麗に全選択する
            setTimeout(() => {
                editor.focus();
                editor.select();
                editor.setSelectionRange(0, editor.value.length);
            }, 50);

            editor.placeholder = originalPlaceholder;
            editor.removeEventListener("paste", pasteHandler);
        }, 100);
    };
    
    // 重複登録を防ぐため、一度削除してから登録
    editor.removeEventListener("paste", pasteHandler);
    editor.addEventListener("paste", pasteHandler);
    
    alert("ブラウザのセキュリティ制限により、クリップボードのコードを自動で取得できませんでした。\n\nエディタを空にして選択状態にしましたので、このまま「Ctrl + V」を押して貼り付けてください。貼り付け後に保存ボタンが表示されます。");
}
/**
 * プロンプト内容を大きなモーダルで表示し、コピー＆全選択機能を提供する
 * @param {string} title - メモ・タイトル
 * @param {string} content - プロンプト本文
 */
/**
/**
 * 履歴内容を洗練された大きなモーダルで表示し、コピー＆全選択機能を提供する（サイズ強制適用版）
 * @param {string} title - メモ・タイトル
 * @param {string} content - プロンプト本文
 */

/**
 * .prompt フォルダ内の履歴一覧を読み込んで表示する
 */
async function loadPromptHistory() {
    const historyList = document.getElementById('prompt-history-list');
    if (!historyList) {
        console.error("❌ エラー: 履歴を表示する要素 '#prompt-history-list' が画面上に見つかりません。HTML側のIDを確認してください。");
        return;
    }

    // 読み込み中の表示
    historyList.innerHTML = '<div class="loading" style="color: #666; font-size: 12px; text-align: center; width: 100%; margin-top: 10px;">履歴を読み込み中...</div>';

    if (!currentPath) {
        historyList.innerHTML = '<div class="empty" style="color: #aaa; font-size: 12px; text-align: center; width: 100%; margin-top: 10px;">対象が選択されていません。</div>';
        return;
    }

    try {
        const res = await apiCall('get_prompt_history', { path: currentPath });

        if (!res) {
            throw new Error("サーバーからの応答(res)が空、またはJSONとして解析できませんでした。");
        }

        if (!res.success) {
            throw new Error(res.error || "サーバー側でエラーが発生しました。");
        }

        const history = res.history || [];

        // 固定ファイル（prompt.txt など）を除外し、履歴ファイルのみを抽出
        const validHistory = history.filter(item => {
            const filename = item.filename || '';
            // prompt.txt 自体は履歴一覧から除外
            return filename !== 'prompt.txt' && filename.endsWith('.txt');
        });

        if (validHistory.length === 0) {
            historyList.innerHTML = '<div class="empty" style="color: #aaa; font-size: 12px; text-align: center; width: 100%; margin-top: 10px;">保存された履歴はありません。</div>';
            return;
        }

        // リストの生成
        historyList.innerHTML = '';
        validHistory.forEach((item) => {
            const itemEl = document.createElement('div');
            itemEl.className = 'history-item';
            
            itemEl.style.padding = "6px 12px";
            itemEl.style.background = "#ffffff";
            itemEl.style.border = "1px solid #ddd";
            itemEl.style.borderRadius = "4px";
            itemEl.style.fontSize = "12px";
            itemEl.style.color = "#333333";
            itemEl.style.cursor = "pointer";
            itemEl.style.transition = "all 0.2s";
            itemEl.style.userSelect = "none";
            itemEl.style.display = "flex";
            itemEl.style.alignItems = "center";
            itemEl.style.justifyContent = "space-between";
            itemEl.style.width = "100%";
            itemEl.style.boxSizing = "border-box";
            itemEl.style.marginBottom = "4px";

            // マウスホバー時のエフェクト（borderColorに修正）
            itemEl.onmouseenter = () => {
                itemEl.style.background = "#eef5fc";
                itemEl.style.borderColor = "#007acc";
            };
            itemEl.onmouseleave = () => {
                itemEl.style.background = "#ffffff";
                itemEl.style.borderColor = "#ddd";
            };
            
            // ファイル名から日付部分とメモ部分を分離
            let displayName = item.filename;
            // 形式1: YYYYMMDD_HHMMSS_メモ.txt
            const match1 = item.filename.match(/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})_(.+)\.txt$/);
            // 形式2: prompt_YYYYMMDD_HHMMSS.txt などの表記揺れにも対応
            const match2 = item.filename.match(/^prompt_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})\.txt$/);

            if (match1) {
                const [_, Y, M, D, h, m, s, memo] = match1;
                displayName = `${Y}/${M}/${D} ${h}:${m}:${s} - ${memo}`;
            } else if (match2) {
                const [_, Y, M, D, h, m, s] = match2;
                displayName = `${Y}/${M}/${D} ${h}:${m}:${s}`;
            } else {
                // 形式に合わない場合も警告を出さず拡張子を取って綺麗に表示
                displayName = item.filename.replace(/\.txt$/, '');
            }

            itemEl.title = item.filename;

            // テキスト表示部
            const textEl = document.createElement('span');
            textEl.style.overflow = "hidden";
            textEl.style.textOverflow = "ellipsis";
            textEl.style.whiteSpace = "nowrap";
            textEl.style.flexGrow = "1";
            textEl.style.marginRight = "8px";
            textEl.textContent = displayName;

            // 削除ボタン
            const deleteBtn = document.createElement('span');
            deleteBtn.className = 'delete-prompt-btn';
            deleteBtn.textContent = '×';
            deleteBtn.style.color = "#ff4d4f";
            deleteBtn.style.cursor = "pointer";
            deleteBtn.style.padding = "2px 6px";
            deleteBtn.style.fontWeight = "bold";
            deleteBtn.style.fontSize = "14px";
            deleteBtn.style.flexShrink = "0";
            deleteBtn.style.transition = "color 0.2s";
            
            deleteBtn.onmouseenter = () => { deleteBtn.style.color = '#ff1a1a'; };
            deleteBtn.onmouseleave = () => { deleteBtn.style.color = '#ff4d4f'; };

            // 履歴読み込みイベント
            itemEl.addEventListener('click', async () => {
                await selectPromptHistory(item.filename);
            });

            // 削除イベント
            deleteBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                await deletePromptHistoryFile(item.filename, itemEl);
            });

            itemEl.appendChild(textEl);
            itemEl.appendChild(deleteBtn);
            historyList.appendChild(itemEl);
        });

    } catch (e) {
        console.error("❌ 履歴読み込み処理中に例外エラーが発生しました:", e);
        historyList.innerHTML = `<div class="error" style="color:red; font-size:12px; text-align:center; width:100%; margin-top:10px;">読込失敗: ${e.message}</div>`;
    }
}
// 💡 プロンプト履歴ファイルを個別に削除する関数
async function deletePromptHistoryFile(filename, element) {
    if (!confirm('このプロンプト履歴を削除してもよろしいですか？')) {
        return;
    }

    try {
        console.log(`[削除リクエスト] ファイル: ${filename} を削除します。`);
        const result = await apiCall('delete_prompt_history', {
            path: currentPath,
            file: filename
        });

        if (result.success) {
            console.log("プロンプト履歴が正常に削除されました。");
            // UI上の該当要素を削除
            element.remove();
            
            // 履歴がゼロになった場合は空表示に戻す
            const historyList = document.getElementById('prompt-history-list');
            if (historyList && historyList.querySelectorAll('.history-item').length === 0) {
                historyList.innerHTML = '<div class="empty" style="color: #aaa; font-size: 12px; text-align: center; width: 100%; margin-top: 10px;">保存された履歴はありません。</div>';
            }
        } else {
            alert('プロンプト履歴の削除に失敗しました: ' + (result.error || '不明なエラー'));
        }
    } catch (e) {
        console.error("❌ 削除処理中に通信エラーが発生しました:", e);
        alert('通信エラーが発生しました。');
    }
}
/**
 * 履歴クリック時に実行される関数（?api= を明示的に指定するセキュア版）
 */
async function selectPromptHistory(filename) {
    const path = currentPath || ''; 

    if (!filename || filename.includes('..') || filename.includes('/') || filename.includes('\\')) {
        console.error('セキュリティ警告: 不正なファイル名が検出されました。');
        return;
    }

    // タイムアウト用のコントローラーを作成（5秒で自動キャンセル）
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000); 

    try {
/*
        console.log(`[リクエスト送信] ファイル名: ${filename}, パス: ${path}`);
*/        
        const response = await fetch('?api=get_prompt_content', { 
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                path: path,
                filename: filename
            }),
            signal: controller.signal // タイムアウト監視を登録
        });

        clearTimeout(timeoutId); // 正常に応答があったのでタイマーを解除

        if (!response.ok) {
            throw new Error(`ネットワーク通信エラー。ステータス: ${response.status}`);
        }

        const rawText = await response.text();
/*
        console.log('[受信データ]', rawText); // デバッグ用
*/
        const data = JSON.parse(rawText);

        if (data && data.success) {
            const memoText = data.memo || filename; 
            const promptText = data.content || ''; 
            
            if (typeof openModal === 'function') {
                openModal(memoText, promptText);
            } else {
                alert('モーダル展開関数 [openModal] が定義されていません。');
            }
        } else {
            alert('エラー: ' + (data ? data.error : 'データが空です'));
        }

    } catch (error) {
        clearTimeout(timeoutId);
/*
        console.error('履歴の取得中にエラーが発生しました:', error);
*/
        
        if (error.name === 'AbortError') {
            alert('通信エラー: サーバーからの応答がタイムアウト（5秒超過）しました。PHP側の処理が停止している可能性があります。');
        } else {
            alert('取得エラー: ' + error.message);
        }
    }
}

/**
 * 選択した履歴の内容を読み込み、モーダル（ポップアップ）に表示する
 * @param {string} filename - クリックされた履歴のファイル名（例: "20260716_104554_memo.txt"）
 */
async function loadPromptContent(filename) {
    if (!filename || filename.includes('..') || filename.includes('/') || filename.includes('\\')) {
        alert('不正なファイル名が含まれています。');
        return;
    }

    const path = currentPath || ''; 

    // タイムアウト監視用のタイマー（5秒）
    let isTimeout = false;
    const timeoutPromise = new Promise((_, reject) => 
        setTimeout(() => {
            isTimeout = true;
            reject(new Error('TIMEOUT_ERROR'));
        }, 5000)
    );

    try {
        console.log(`[リクエスト送信] ファイル名: ${filename}, パス: ${path}`);

        // apiCallを実行（タイムアウト監視と競争）
        const res = await Promise.race([
            apiCall('get_prompt_content', {
                path: path,
                filename: filename
            }),
            timeoutPromise
        ]);

        console.log('[レスポンス受信]', res);

        if (res && res.success) {
            // ファイル名からメモ部分を抽出 (例: 20260716_104554_memo.txt -> memo)
            const match = filename.match(/^\d{8}_\d{6}_(.*)\.txt$/);
            const memoText = (match && match[1]) ? match[1] : filename;
            const promptText = res.content || ''; 

            // モーダル表示関数を呼び出す
            openModal(memoText, promptText);

            // ステータス通知（存在すれば）
            const statusSpan = document.getElementById('prompt-status');
            if (statusSpan) {
                statusSpan.style.color = '#007acc';
                statusSpan.textContent = '✓ プロンプトを読み込みました';
                setTimeout(() => { statusSpan.textContent = ''; }, 3000);
            }
        } else {
            alert('読み込みに失敗しました: ' + (res ? res.error : '不明なエラー'));
        }
    } catch (e) {
        console.error('loadPromptContent内でエラーが発生しました:', e);
        if (e.message === 'TIMEOUT_ERROR' || isTimeout) {
            alert('通信エラー: サーバーからの応答がありません。');
        } else {
            alert('エラーが発生しました: ' + e.message);
        }
    }
}

/**
 * モーダル（ポップアップ）を展開して内容をセットする関数
 * @param {string} title - メモ（タイトル）
 * @param {string} content - プロンプト本文
 */
/**
 * モーダル（ポップアップ）を展開して内容をセットする
 */
function openModal(title, content) {
    const modal = document.getElementById('history-modal'); // 共有いただいたID
    const modalTitle = document.getElementById('modal-memo-content'); // メモ表示用
    const modalContent = document.getElementById('modal-prompt-content'); // 本文表示用

    if (modal && modalTitle && modalContent) {
        // テキスト内容をセット
        modalTitle.textContent = title;
        modalContent.value = content;
        
        // モーダルを表示
        modal.style.display = 'flex'; // またはブロック表示 'block'
    }
}

/**
 * モーダルを閉じる
 */
function closeHistoryModal() {
    const modal = document.getElementById('history-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}
/**
 * モーダルを閉じる関数
 */
function closeHistoryModal() {
    const modal = document.getElementById('history-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}


/**
 * モーダルを閉じる関数
 */
function closeModal() {
    const modal = document.getElementById('prompt-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}
// 簡易HTMLエスケープ用
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// 履歴アイテムのホバー効果を動的に追加
function setupHistoryHoverStyles() {
    if (document.getElementById('prompt-history-hover-style')) return;
    const style = document.createElement('style');
    style.id = 'prompt-history-hover-style';
    style.innerHTML = `
        .prompt-history-item:hover {
            background-color: #f0f7ff !important;
            border-color: #007acc !important;
        }
    `;
    document.head.appendChild(style);
}
/**
 * プロンプト内容をクリップボードにコピーする関数
 */
function copyModalContent() {
    const promptText = document.getElementById('modal-prompt-content').value;
    if (!promptText) {
        alert('コピーする内容がありません。');
        return;
    }

    navigator.clipboard.writeText(promptText).then(() => {
        alert('クリップボードにコピーしました！');
    }).catch(err => {
        console.error('コピーに失敗しました:', err);
        // 代替処理 (古いブラウザ用)
        const textarea = document.getElementById('modal-prompt-content');
        textarea.select();
        document.execCommand('copy');
        alert('クリップボードにコピーしました！');
    });
}

/**
 * プロンプト内容を .txt ファイルとしてダウンロードする関数
 */
function downloadModalContent() {
    const promptText = document.getElementById('modal-prompt-content').value;
    if (!promptText) {
        alert('ダウンロードする内容がありません。');
        return;
    }

    // 現在日時を取得してファイル名を作成 (例: prompt_20260804_103733.txt)
    const now = new Date();
    const timestamp = now.getFullYear() +
        String(now.getMonth() + 1).padStart(2, '0') +
        String(now.getDate()).padStart(2, '0') + '_' +
        String(now.getHours()).padStart(2, '0') +
        String(now.getMinutes()).padStart(2, '0') +
        String(now.getSeconds()).padStart(2, '0');
    
    const fileName = `prompt_${timestamp}.txt`;

    // Blobを作成してダウンロード実行
    const blob = new Blob([promptText], { type: 'text/plain;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(link.href);
}



// 初期起動
loadTrees();
</script>
</body>
</html>
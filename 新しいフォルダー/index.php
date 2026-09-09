<?php
declare(strict_types=1);

/**
 * GOJACIC Manager
 * Single-file application
 *
 * Refactoring rules:
 * - index.php remains a single file.
 * - Existing GUI / DOM / API names are preserved.
 * - Existing directory structure is preserved.
 * - Migration is disabled by default.
 * - API responses are always JSON.
 */

namespace gojacicManager;

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (ob_get_level() === 0) {
    ob_start();
}

/* ==========================================================
 * 1. 基本設定
 * ========================================================== */

$document_root = realpath(__DIR__);

if ($document_root === false) {
    http_response_code(500);
    exit('Document root could not be resolved.');
}

$base_dir      = $document_root . DIRECTORY_SEPARATOR . 'gojacic';
$poc_dir       = $base_dir . DIRECTORY_SEPARATOR . '.poc';
$published_dir = $base_dir . DIRECTORY_SEPARATOR . 'published';

$old_history_dir = $base_dir . DIRECTORY_SEPARATOR . '.history';
$old_prompt_dir  = $base_dir . DIRECTORY_SEPARATOR . '.prompt';
$old_harness_dir = $base_dir . DIRECTORY_SEPARATOR . '.harness';
$old_github_dir  = $base_dir . DIRECTORY_SEPARATOR . '.github';

$poc_history_dir = $poc_dir . DIRECTORY_SEPARATOR . '.history';
$poc_prompt_dir  = $poc_dir . DIRECTORY_SEPARATOR . '.prompt';
$poc_harness_dir = $poc_dir . DIRECTORY_SEPARATOR . '.harness';

$doc_clean  = str_replace('\\', '/', $document_root);
$base_clean = str_replace('\\', '/', $base_dir);
$rel_path   = trim(str_replace($doc_clean, '', $base_clean), '/');

$web_base_path =
    $rel_path === ''
        ? '/'
        : '/' . $rel_path . '/';


/* ==========================================================
 * 2. 共通ユーティリティ
 * ========================================================== */

function json_response(
    array $payload,
    int $status = 200
): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


function json_success(
    array $data = []
): never {
    json_response(
        array_merge(
            ['success' => true],
            $data
        )
    );
}


function json_error(
    string $message,
    int $status = 400,
    array $extra = []
): never {
    json_response(
        array_merge(
            [
                'success' => false,
                'error'   => $message
            ],
            $extra
        ),
        $status
    );
}


function request_data(): array
{
    static $data = null;

    if ($data !== null) {
        return $data;
    }

    $raw = file_get_contents('php://input');

    if (
        is_string($raw) &&
        trim($raw) !== ''
    ) {
        $decoded = json_decode(
            $raw,
            true
        );

        if (is_array($decoded)) {
            $data = $decoded;
            return $data;
        }
    }

    $data = $_POST;

    return is_array($data)
        ? $data
        : [];
}


function request_value(
    string $key,
    mixed $default = ''
): mixed {
    $data = request_data();

    if (array_key_exists($key, $data)) {
        return $data[$key];
    }

    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }

    return $default;
}


function normalize_slashes(
    string $path
): string {
    return str_replace(
        '\\',
        '/',
        $path
    );
}


function normalize_relative_path(
    string $path
): string {
    $path = normalize_slashes(
        trim($path)
    );

    $path = preg_replace(
        '#/+#',
        '/',
        $path
    );

    return trim(
        (string)$path,
        '/'
    );
}


function safe_app_name(
    string $value
): string {
    $value =
        normalize_relative_path(
            $value
        );

    $value = preg_replace(
        '#^(?:\.poc|published)/#',
        '',
        $value
    );

    $parts = explode(
        '/',
        $value
    );

    $name =
        trim(
            $parts[0] ?? ''
        );

    if (
        $name === '' ||
        !preg_match(
            '/^[a-zA-Z0-9_-]+$/',
            $name
        )
    ) {
        return '';
    }

    return $name;
}


function safe_target_file(
    string $target
): bool {
    return in_array(
        $target,
        [
            'spec/common.md',
            'spec/dev.md',
            'mock/index.php',
            'dev/index.php'
        ],
        true
    );
}


function ensure_directory(
    string $directory
): bool {
    if (is_dir($directory)) {
        return true;
    }

    return mkdir(
        $directory,
        0755,
        true
    ) || is_dir($directory);
}


/* ==========================================================
 * 3. アプリディレクトリ
 * ========================================================== */

function get_app_dirs(
    string $appName,
    string $poc_dir,
    string $published_dir
): array {
    $app_root =
        $poc_dir .
        DIRECTORY_SEPARATOR .
        $appName;

    return [
        'root' =>
            $app_root,

        'draft' =>
            $app_root .
            DIRECTORY_SEPARATOR .
            'draft',

        'mock' =>
            $app_root .
            DIRECTORY_SEPARATOR .
            'mock',

        'spec' =>
            $app_root .
            DIRECTORY_SEPARATOR .
            'spec',

        'published' =>
            $published_dir .
            DIRECTORY_SEPARATOR .
            $appName,

        'history' =>
            $app_root .
            DIRECTORY_SEPARATOR .
            '.history',

        'prompt' =>
            $app_root .
            DIRECTORY_SEPARATOR .
            '.prompt',

        'harness' =>
            $app_root .
            DIRECTORY_SEPARATOR .
            '.harness',

        'github' =>
            $app_root .
            DIRECTORY_SEPARATOR .
            '.github',

        'github_config' =>
            $app_root .
            DIRECTORY_SEPARATOR .
            '.github' .
            DIRECTORY_SEPARATOR .
            'config.json',
    ];
}


/* ==========================================================
 * 4. 基本ディレクトリ作成
 * ========================================================== */

foreach (
    [
        $base_dir,
        $poc_dir,
        $published_dir
    ] as $directory
) {
    if (
        !ensure_directory(
            $directory
        )
    ) {
        error_log(
            'ディレクトリ作成失敗: ' .
            $directory
        );
    }
}


/* ==========================================================
 * 5. マイグレーション
 *
 * デフォルトでは無効。
 * ========================================================== */

$enable_migration = false;

if (
    $enable_migration &&
    is_dir($poc_dir)
) {

    /* ------------------------------------------------------
     * 世代A:
     * .poc/draft/App
     * .poc/mock/App
     * ->
     * .poc/App/draft
     * .poc/App/mock
     * ------------------------------------------------------ */

    foreach (
        [
            'draft',
            'mock'
        ] as $stage
    ) {

        $old_parent =
            $poc_dir .
            DIRECTORY_SEPARATOR .
            $stage;

        if (!is_dir($old_parent)) {
            continue;
        }

        foreach (
            scandir($old_parent) ?: []
            as $app_name
        ) {

            if (
                $app_name === '.' ||
                $app_name === '..'
            ) {
                continue;
            }

            $src =
                $old_parent .
                DIRECTORY_SEPARATOR .
                $app_name;

            if (!is_dir($src)) {
                continue;
            }

            if (
                !preg_match(
                    '/^[a-zA-Z0-9_-]+$/',
                    $app_name
                )
            ) {
                continue;
            }

            $new_app_dir =
                $poc_dir .
                DIRECTORY_SEPARATOR .
                $app_name;

            $destination =
                $new_app_dir .
                DIRECTORY_SEPARATOR .
                $stage;

            ensure_directory(
                $new_app_dir
            );

            if (
                !file_exists($destination) &&
                !@rename(
                    $src,
                    $destination
                )
            ) {
                error_log(
                    "旧{$stage}移行失敗: {$src} -> {$destination}"
                );
            }
        }

        @rmdir($old_parent);
    }


    /* ------------------------------------------------------
     * 世代B:
     * .poc/App/*
     * ->
     * .poc/App/draft/*
     * ------------------------------------------------------ */

    foreach (
        scandir($poc_dir) ?: []
        as $item
    ) {

        if (
            $item === '.' ||
            $item === '..' ||
            $item === 'draft' ||
            $item === 'mock' ||
            str_starts_with($item, '.')
        ) {
            continue;
        }

        $app_path =
            $poc_dir .
            DIRECTORY_SEPARATOR .
            $item;

        if (!is_dir($app_path)) {
            continue;
        }

        $target_draft =
            $app_path .
            DIRECTORY_SEPARATOR .
            'draft';

        $target_mock =
            $app_path .
            DIRECTORY_SEPARATOR .
            'mock';

        if (
            is_dir($target_draft) ||
            is_dir($target_mock)
        ) {
            continue;
        }

        $tmp_path =
            $poc_dir .
            DIRECTORY_SEPARATOR .
            '_tmp_' .
            $item .
            '_' .
            time();

        if (
            @rename(
                $app_path,
                $tmp_path
            )
        ) {

            ensure_directory(
                $app_path
            );

            if (
                !@rename(
                    $tmp_path,
                    $target_draft
                )
            ) {
                error_log(
                    "世代B移行失敗: {$tmp_path} -> {$target_draft}"
                );
            }
        }
    }


    /* ------------------------------------------------------
     * 世代C:
     * 旧グローバル管理領域
     * ------------------------------------------------------ */

    $migration_map = [
        $old_history_dir => 'history',
        $old_prompt_dir  => 'prompt',
        $old_harness_dir => 'harness'
    ];

    foreach (
        $migration_map as
        $old_parent => $folder_name
    ) {

        if (!is_dir($old_parent)) {
            continue;
        }

        foreach (
            scandir($old_parent) ?: []
            as $app_name
        ) {

            if (
                $app_name === '.' ||
                $app_name === '..'
            ) {
                continue;
            }

            if (
                !preg_match(
                    '/^[a-zA-Z0-9_-]+$/',
                    $app_name
                )
            ) {
                continue;
            }

            $src =
                $old_parent .
                DIRECTORY_SEPARATOR .
                $app_name;

            if (!is_dir($src)) {
                continue;
            }

            $app_root =
                $poc_dir .
                DIRECTORY_SEPARATOR .
                $app_name;

            $destination =
                $app_root .
                DIRECTORY_SEPARATOR .
                '.' .
                $folder_name;

            ensure_directory(
                $app_root
            );

            if (
                !file_exists($destination)
            ) {
                @rename(
                    $src,
                    $destination
                );
            }
        }

        @rmdir($old_parent);
    }


    /* ------------------------------------------------------
     * .github 移行
     * ------------------------------------------------------ */

    if (is_dir($old_github_dir)) {

        foreach (
            scandir($old_github_dir) ?: []
            as $item
        ) {

            if (
                $item === '.' ||
                $item === '..'
            ) {
                continue;
            }

            $src =
                $old_github_dir .
                DIRECTORY_SEPARATOR .
                $item;

            if (!is_dir($src)) {
                continue;
            }

            if (
                !preg_match(
                    '/^[a-zA-Z0-9_-]+$/',
                    $item
                )
            ) {
                continue;
            }

            $destination =
                $poc_dir .
                DIRECTORY_SEPARATOR .
                $item .
                DIRECTORY_SEPARATOR .
                '.github';

            ensure_directory(
                dirname($destination)
            );

            if (
                !file_exists($destination)
            ) {
                @rename(
                    $src,
                    $destination
                );
            }
        }

        @rmdir($old_github_dir);
    }


    /* ------------------------------------------------------
     * 世代D:
     * .poc/.history
     * .poc/.prompt
     * .poc/.harness
     * ------------------------------------------------------ */

    $shared_map = [
        '.history' => 'history',
        '.prompt'  => 'prompt',
        '.harness' => 'harness'
    ];

    $known_apps = [];

    foreach (
        scandir($poc_dir) ?: []
        as $entry
    ) {

        if (
            $entry === '.' ||
            $entry === '..' ||
            str_starts_with($entry, '.')
        ) {
            continue;
        }

        if (
            is_dir(
                $poc_dir .
                DIRECTORY_SEPARATOR .
                $entry
            ) &&
            preg_match(
                '/^[a-zA-Z0-9_-]+$/',
                $entry
            )
        ) {
            $known_apps[] = $entry;
        }
    }

    foreach (
        $shared_map as
        $folder_name => $dir_key
    ) {

        $shared_dir =
            $poc_dir .
            DIRECTORY_SEPARATOR .
            $folder_name;

        if (!is_dir($shared_dir)) {
            continue;
        }

        foreach (
            scandir($shared_dir) ?: []
            as $entry
        ) {

            if (
                $entry === '.' ||
                $entry === '..'
            ) {
                continue;
            }

            $src_item =
                $shared_dir .
                DIRECTORY_SEPARATOR .
                $entry;

            if (is_dir($src_item)) {

                $dst_app_dir =
                    $poc_dir .
                    DIRECTORY_SEPARATOR .
                    $entry;

                if (
                    !preg_match(
                        '/^[a-zA-Z0-9_-]+$/',
                        $entry
                    )
                ) {
                    continue;
                }

                $dst_target =
                    $dst_app_dir .
                    DIRECTORY_SEPARATOR .
                    $folder_name;

                ensure_directory(
                    $dst_app_dir
                );

                if (
                    !file_exists($dst_target)
                ) {
                    @rename(
                        $src_item,
                        $dst_target
                    );
                }

            } elseif (is_file($src_item)) {

                foreach (
                    $known_apps as
                    $app_name
                ) {

                    if (
                        !str_contains(
                            $entry,
                            $app_name
                        )
                    ) {
                        continue;
                    }

                    $dirs =
                        get_app_dirs(
                            $app_name,
                            $poc_dir,
                            $published_dir
                        );

                    ensure_directory(
                        $dirs[$dir_key]
                    );

                    @rename(
                        $src_item,
                        $dirs[$dir_key] .
                        DIRECTORY_SEPARATOR .
                        $entry
                    );

                    break;
                }
            }
        }

        @rmdir($shared_dir);
    }


    /* ------------------------------------------------------
     * 最終補正
     * ------------------------------------------------------ */

    foreach (
        $known_apps as
        $app_name
    ) {

        $dirs =
            get_app_dirs(
                $app_name,
                $poc_dir,
                $published_dir
            );

        foreach (
            [
                'root',
                'draft',
                'mock',
                'spec',
                'history',
                'prompt'
            ] as $key
        ) {
            ensure_directory(
                $dirs[$key]
            );
        }

        $initial_files = [

            $dirs['spec'] .
            DIRECTORY_SEPARATOR .
            'common.md'
                =>
            "# 要件定義 (Common)\n\n",

            $dirs['spec'] .
            DIRECTORY_SEPARATOR .
            'dev.md'
                =>
            "# 実装要件 (Dev)\n\n",

            $dirs['mock'] .
            DIRECTORY_SEPARATOR .
            'index.php'
                =>
            "<!-- Mock Screen -->\n",

            $dirs['draft'] .
            DIRECTORY_SEPARATOR .
            'index.php'
                =>
            "<?php\n// Dev Main\n"
        ];

        foreach (
            $initial_files as
            $file =>
            $content
        ) {

            if (!file_exists($file)) {
                @file_put_contents(
                    $file,
                    $content
                );
            }
        }
    }
}


/* ==========================================================
 * 6. リクエスト情報
 * ========================================================== */

$action = (string)(
    request_value(
        'api',
        request_value(
            'action',
            ''
        )
    )
);

$raw_app_name =
    (string)request_value(
        'app_name',
        ''
    );

$target_file =
    (string)request_value(
        'target_file',
        ''
    );


/* ==========================================================
 * 7. ファイル操作ヘルパー
 * ========================================================== */

function rcopy(
    string $src,
    string $dst
): bool {

    if (is_dir($src)) {

        if (
            !ensure_directory($dst)
        ) {
            return false;
        }

        foreach (
            scandir($src) ?: []
            as $file
        ) {

            if (
                $file === '.' ||
                $file === '..'
            ) {
                continue;
            }

            if (
                !rcopy(
                    $src .
                    DIRECTORY_SEPARATOR .
                    $file,

                    $dst .
                    DIRECTORY_SEPARATOR .
                    $file
                )
            ) {
                return false;
            }
        }

        return true;
    }

    if (!is_file($src)) {
        return false;
    }

    return @copy(
        $src,
        $dst
    );
}


function rrmdir(
    string $dir
): bool {

    if (!is_dir($dir)) {
        return true;
    }

    foreach (
        scandir($dir) ?: []
        as $object
    ) {

        if (
            $object === '.' ||
            $object === '..'
        ) {
            continue;
        }

        $path =
            $dir .
            DIRECTORY_SEPARATOR .
            $object;

        if (
            is_dir($path) &&
            !is_link($path)
        ) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }

    return @rmdir($dir);
}


/* ==========================================================
 * 8. ツリー走査
 * ========================================================== */

function scan_tree(
    string $dir,
    string $relative_prefix = ''
): array {

    if (
        !is_dir($dir) ||
        !is_readable($dir)
    ) {
        return [];
    }

    $result = [];

    $published_dir =
        $GLOBALS['published_dir'] ??
        null;

    $items =
        @scandir($dir);

    if ($items === false) {
        return [];
    }

    foreach (
        $items as $item
    ) {

        if (
            $item === '.' ||
            $item === '..' ||
            $item === '.git'
        ) {
            continue;
        }

        $full_path =
            $dir .
            DIRECTORY_SEPARATOR .
            $item;

        $rel_path =
            $relative_prefix !== ''
                ? $relative_prefix .
                    '/' .
                    $item
                : $item;

        if (!is_dir($full_path)) {
            continue;
        }

        $root_index =
            $full_path .
            DIRECTORY_SEPARATOR .
            'index.php';

        $draft_index =
            $full_path .
            DIRECTORY_SEPARATOR .
            'draft' .
            DIRECTORY_SEPARATOR .
            'index.php';

        $mock_index =
            $full_path .
            DIRECTORY_SEPARATOR .
            'mock' .
            DIRECTORY_SEPARATOR .
            'index.php';

        $has_root_index =
            file_exists(
                $root_index
            );

        $has_draft_index =
            file_exists(
                $draft_index
            );

        $has_mock_index =
            file_exists(
                $mock_index
            );

        $is_app =
            $has_root_index ||
            $has_draft_index ||
            $has_mock_index;

        $children =
            scan_tree(
                $full_path,
                $rel_path
            );

        $is_published =
            str_starts_with(
                $rel_path,
                'published'
            );

        if (
            !$is_published &&
            $published_dir !== null
        ) {

            $published_index =
                $published_dir .
                DIRECTORY_SEPARATOR .
                $item .
                DIRECTORY_SEPARATOR .
                'index.php';

            $is_published =
                file_exists(
                    $published_index
                );
        }

        $type =
            $is_app
                ? 'app'
                : (
                    empty($children)
                        ? 'data-folder'
                        : 'folder'
                );

        $stage = null;

        if ($is_app) {

            $is_real_draft = false;

            if ($has_draft_index) {

                $content =
                    trim(
                        (string)(
                            @file_get_contents(
                                $draft_index
                            ) ?: ''
                        )
                    );

                $is_real_draft =
                    $content !== '' &&
                    $content !== "<?php\n// Dev Main" &&
                    $content !== "<?php // Dev Main" &&
                    $content !== '<?php';
            }


            $is_real_mock = false;

            if ($has_mock_index) {

                $content =
                    trim(
                        (string)(
                            @file_get_contents(
                                $mock_index
                            ) ?: ''
                        )
                    );

                $is_real_mock =
                    $content !== '' &&
                    $content !== '<!-- Mock Screen -->';
            }


            if ($is_real_draft) {
                $stage = 'draft';

            } elseif ($is_real_mock) {
                $stage = 'mock';

            } elseif (
                $has_draft_index &&
                !$has_mock_index
            ) {
                $stage = 'draft';

            } elseif ($has_mock_index) {
                $stage = 'mock';

            } elseif ($has_draft_index) {
                $stage = 'draft';

            } else {
                $stage = 'root';
            }
        }

        $result[] = [
            'name' =>
                $item,

            'path' =>
                $rel_path,

            'type' =>
                $type,

            'stage' =>
                $stage,

            'status' =>
                $stage,

            'published' =>
                $is_published,

            'children' =>
                $children
        ];
    }

    usort(
        $result,
        static function (
            array $a,
            array $b
        ): int {
            return strcmp(
                (string)$a['name'],
                (string)$b['name']
            );
        }
    );

    return $result;
}


/* ==========================================================
 * 9. get_tree API
 * ========================================================== */

if ($action === 'get_tree') {

    try {

        $poc_tree =
            is_dir($poc_dir)
                ? scan_tree(
                    $poc_dir,
                    '.poc'
                )
                : [];

        $published_tree =
            is_dir($published_dir)
                ? scan_tree(
                    $published_dir,
                    'published'
                )
                : [];

        json_success([
            'poc' =>
                $poc_tree,

            'published' =>
                $published_tree
        ]);

    } catch (
        \Throwable $e
    ) {

        error_log(
            '[get_tree] ' .
            $e->getMessage()
        );

        json_error(
            $e->getMessage(),
            500
        );
    }
}


/* ==========================================================
 * 10. PoCファイル API
 * ========================================================== */

if (
    in_array(
        $action,
        [
            'load_poc_file',
            'save_poc_file'
        ],
        true
    )
) {

    $clean_app_name =
        safe_app_name(
            $raw_app_name
        );

    if ($clean_app_name === '') {

        json_error(
            'アプリが選択されていないか、不正なアプリ名です。',
            400
        );
    }

    if (
        !safe_target_file(
            $target_file
        )
    ) {

        json_error(
            '指定されたファイル種別が無効です: ' .
            $target_file,
            400
        );
    }

    $app_dirs =
        get_app_dirs(
            $clean_app_name,
            $poc_dir,
            $published_dir
        );

    $file_map = [

        'spec/common.md' =>
            $app_dirs['spec'] .
            DIRECTORY_SEPARATOR .
            'common.md',

        'spec/dev.md' =>
            $app_dirs['spec'] .
            DIRECTORY_SEPARATOR .
            'dev.md',

        'mock/index.php' =>
            $app_dirs['mock'] .
            DIRECTORY_SEPARATOR .
            'index.php',

        'dev/index.php' =>
            $app_dirs['draft'] .
            DIRECTORY_SEPARATOR .
            'index.php'
    ];

    $filepath =
        $file_map[$target_file];


    /* ------------------------------------------------------
     * 読込
     * ------------------------------------------------------ */

    if (
        $action === 'load_poc_file'
    ) {

        if (!file_exists($filepath)) {

            if (
                !ensure_directory(
                    dirname($filepath)
                )
            ) {
                json_error(
                    '保存先ディレクトリを作成できません。',
                    500
                );
            }

            if (
                @file_put_contents(
                    $filepath,
                    ''
                ) === false
            ) {
                json_error(
                    'ファイルを作成できません。',
                    500
                );
            }

            json_success([
                'content' => ''
            ]);
        }

        $content =
            @file_get_contents(
                $filepath
            );

        if ($content === false) {
            json_error(
                'ファイルの読み込みに失敗しました。',
                500
            );
        }

        json_success([
            'content' =>
                $content
        ]);
    }


    /* ------------------------------------------------------
     * 保存
     * ------------------------------------------------------ */

    $content =
        request_value(
            'content',
            ''
        );

    if (!is_string($content)) {
        $content = '';
    }

    $target_dir =
        dirname($filepath);

    if (
        !ensure_directory(
            $target_dir
        )
    ) {
        json_error(
            '保存先ディレクトリの作成に失敗しました。',
            500
        );
    }


    /*
     * 自動バックアップ
     */
    if (file_exists($filepath)) {

        $history_dir =
            $app_dirs['history'];

        ensure_directory(
            $history_dir
        );

        if (is_dir($history_dir)) {

            $backup_filename =
                date('Ymd_His') .
                '_' .
                str_replace(
                    [
                        '/',
                        '\\'
                    ],
                    '_',
                    $target_file
                );

            @copy(
                $filepath,
                $history_dir .
                DIRECTORY_SEPARATOR .
                $backup_filename
            );
        }
    }


    $bytes =
        @file_put_contents(
            $filepath,
            $content,
            LOCK_EX
        );

    if ($bytes === false) {

        json_error(
            '書き込みに失敗しました。パーミッションを確認してください。',
            500
        );
    }

    json_success([
        'message' =>
            $target_file .
            ' を保存しました。',

        'bytes' =>
            $bytes
    ]);
}


/* ==========================================================
 * 11. GitHub HTTP 共通処理
 * ========================================================== */

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

    $headerLines =
        array_values(
            array_filter(
                $headers,
                static fn($v) =>
                    is_string($v) &&
                    trim($v) !== ''
            )
        );


    if (
        $proxyEnabled &&
        (
            $proxyUsername !== '' ||
            $proxyPassword !== ''
        )
    ) {

        $headerLines[] =
            'Proxy-Authorization: Basic ' .
            base64_encode(
                $proxyUsername .
                ':' .
                $proxyPassword
            );
    }


    $httpOptions = [
        'method' =>
            strtoupper($method),

        'header' =>
            implode(
                "\r\n",
                $headerLines
            ),

        'timeout' =>
            30,

        'ignore_errors' =>
            true
    ];


    if (
        $body !== null
    ) {
        $httpOptions['content'] =
            $body;
    }


    if (
        $proxyEnabled &&
        $proxyHost !== '' &&
        $proxyPort > 0
    ) {

        $httpOptions['proxy'] =
            'tcp://' .
            $proxyHost .
            ':' .
            $proxyPort;
    }


    $sslOptions = [
        'verify_peer' =>
            true,

        'verify_peer_name' =>
            true,

        'allow_self_signed' =>
            false
    ];


    $caFile =
        ini_get(
            'openssl.cafile'
        );

    if (
        is_string($caFile) &&
        trim($caFile) !== '' &&
        is_file($caFile)
    ) {
        $sslOptions['cafile'] =
            $caFile;
    }


    $context =
        stream_context_create([
            'http' =>
                $httpOptions,

            'ssl' =>
                $sslOptions
        ]);


    $response =
        @file_get_contents(
            $url,
            false,
            $context
        );


    $statusCode =
        0;

    $responseHeaders =
        $http_response_header ?? [];

    if (
        isset(
            $responseHeaders[0]
        ) &&
        preg_match(
            '#HTTP/\S+\s+(\d+)#',
            $responseHeaders[0],
            $matches
        )
    ) {
        $statusCode =
            (int)$matches[1];
    }


    return [
        'success' =>
            $response !== false,

        'status' =>
            $statusCode,

        'body' =>
            $response === false
                ? ''
                : $response,

        'headers' =>
            $responseHeaders
    ];
}



/*
|--------------------------------------------------------------------------
| Existing GUI
|--------------------------------------------------------------------------
|
| ↓↓↓ ここから下は元 index.php のGUIをそのまま残す ↓↓↓
|
*/

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

/* ==========================================================
 * GOJACIC MANAGER
 * 修正版 JavaScript 全文
 *
 * 修正内容
 * - APP_BASE_PATH の再宣言をしない
 * - ツリー表示を維持
 * - GitHub設定APIがHTMLを返してもツリーを止めない
 * - GitHub設定エラーを console.error ではなく warning にする
 * - get_tree の poc / published に対応
 * ========================================================== */

const APP_BASE_PATH_VALUE =
    window.__GOJACIC_APP_BASE_PATH__ ||
    document
        .querySelector('meta[name="gojacic-base-path"]')
        ?.getAttribute('content') ||
    '/';

window.__GOJACIC_APP_BASE_PATH__ =
    APP_BASE_PATH_VALUE;


/* ==========================================================
 * State
 * ========================================================== */

let currentPath = '';
let currentIsPublic = false;
let currentIsMock = false;

let isSaving = false;

let contextMenuPath = '';
let contextMenuIsPublic = false;

let draggedPath = '';


/* ==========================================================
 * DOM
 * ========================================================== */

const $ = id =>
    document.getElementById(id);

const editorTextArea =
    $('editor-content');

const lineNumbersDiv =
    $('line-numbers');

const btnSave =
    $('btn-save-current') ||
    $('btn-save');


/* ==========================================================
 * 共通
 * ========================================================== */

function normalizePath(path) {
    return String(path ?? '')
        .replace(/\\/g, '/')
        .replace(/^\/+|\/+$/g, '');
}


function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


function setButtonVisible(
    id,
    visible
) {
    const button = $(id);

    if (button) {
        button.style.display =
            visible ? '' : 'none';
    }
}


/* ==========================================================
 * Loading
 * ========================================================== */

function startBlocking(
    message = '処理中...'
) {
    const overlay =
        $('loading-overlay');

    const text =
        $('loading-text');

    if (!overlay) {
        return;
    }

    if (text) {
        text.textContent =
            message;
    }

    overlay.classList.add(
        'visible'
    );
}


function stopBlocking() {
    const overlay =
        $('loading-overlay');

    if (overlay) {
        overlay.classList.remove(
            'visible'
        );
    }
}


/* ==========================================================
 * Line Numbers
 * ========================================================== */

function updateLineNumbers() {
    if (
        !editorTextArea ||
        !lineNumbersDiv
    ) {
        return;
    }

    const lines =
        editorTextArea.value
            .split('\n')
            .length;

    lineNumbersDiv.textContent =
        Array.from(
            {
                length: lines
            },
            (_, index) =>
                index + 1
        ).join('\n');
}


/* ==========================================================
 * API
 * ========================================================== */

async function apiCall(
    action,
    data = {}
) {
    const response =
        await fetch(
            `?api=${encodeURIComponent(action)}`,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/json',
                    'Accept':
                        'application/json'
                },
                body:
                    JSON.stringify(data)
            }
        );

    const text =
        await response.text();

    let result;

    try {
        result =
            text
                ? JSON.parse(text)
                : {};
    } catch (error) {

        /*
         * PHPがHTMLを返した場合。
         *
         * GitHub設定など任意のAPIでは
         * 呼び出し側で握りつぶせるよう、
         * エラー内容を維持してthrowする。
         */
        throw new Error(
            `JSONではない応答を受信しました: ${text.slice(0, 300)}`
        );
    }

    if (!response.ok) {
        throw new Error(
            result?.error ||
            `HTTP ${response.status}`
        );
    }

    return result;
}


/* ==========================================================
 * Editor
 * ========================================================== */

function setEditorContent(
    content = ''
) {
    if (!editorTextArea) {
        return;
    }

    editorTextArea.value =
        content;

    updateLineNumbers();
}


function getEditorContent() {
    return editorTextArea
        ? editorTextArea.value
        : '';
}


if (editorTextArea) {

    editorTextArea.addEventListener(
        'input',
        () => {
            updateLineNumbers();

            if (
                !currentIsPublic &&
                btnSave
            ) {
                btnSave.style.display =
                    'inline-block';
            }
        }
    );


    editorTextArea.addEventListener(
        'scroll',
        () => {
            if (lineNumbersDiv) {
                lineNumbersDiv.scrollTop =
                    editorTextArea.scrollTop;
            }
        }
    );


    editorTextArea.addEventListener(
        'keydown',
        event => {

            if (
                (event.ctrlKey ||
                    event.metaKey) &&
                event.key.toLowerCase() ===
                    's'
            ) {
                event.preventDefault();

                if (
                    typeof saveFile ===
                    'function'
                ) {
                    saveFile();
                }
            }
        }
    );


    updateLineNumbers();
}


/* ==========================================================
 * GitHub Settings DOM
 * ========================================================== */

function githubElement(key) {
    return (
        $(`gh-${key}`) ||
        $(`github-${key}`) ||
        $(`github_${key}`)
    );
}


function githubValue(key) {
    const element =
        githubElement(key);

    return element?.value?.trim() ||
        '';
}


function githubRawValue(key) {
    const element =
        githubElement(key);

    return element?.value || '';
}


function githubChecked(key) {
    const element =
        githubElement(key);

    return Boolean(
        element?.checked
    );
}


function setGithubValue(
    key,
    value = ''
) {
    const element =
        githubElement(key);

    if (element) {
        element.value =
            value ?? '';
    }
}


function setGithubChecked(
    key,
    value
) {
    const element =
        githubElement(key);

    if (element) {
        element.checked =
            Boolean(value);
    }
}


/* ==========================================================
 * GitHub Settings
 *
 * ここは重要。
 *
 * 現在のPHPが github_settings_get APIを
 * JSONとして返さない場合、
 * apiCall() はHTMLを検出してErrorを投げる。
 *
 * そのエラーをここで受け止め、
 * ツリー読み込みには影響させない。
 * ========================================================== */

async function loadGithubSettings(
    targetPath = ''
) {
    const status =
        $('github-settings-status') ||
        $('github_settings_status');

    try {

        const data =
            await apiCall(
                'github_settings_get',
                {
                    path:
                        targetPath ||
                        currentPath ||
                        ''
                }
            );


        if (
            !data ||
            typeof data !== 'object'
        ) {
            throw new Error(
                'GitHub設定APIから不正な応答が返されました。'
            );
        }


        if (!data.success) {
            throw new Error(
                data.error ||
                'GitHub設定の取得に失敗しました。'
            );
        }


        setGithubValue(
            'owner',
            data.owner || ''
        );


        setGithubValue(
            'repo',
            data.repo || ''
        );


        setGithubValue(
            'branch',
            data.branch ||
                'main'
        );


        setGithubChecked(
            'proxy-enabled',
            data.proxy_enabled
        );


        setGithubValue(
            'proxy-host',
            data.proxy_host || ''
        );


        setGithubValue(
            'proxy-port',
            data.proxy_port || ''
        );


        setGithubChecked(
            'app-enabled',
            data.enabled
        );


        const token =
            githubElement('token');


        if (token) {

            token.value = '';


            if (data.hasToken) {

                token.placeholder =
                    '（設定済み：変更時のみ入力）';

            } else {

                token.placeholder =
                    'GitHub Token';
            }
        }


        if (status) {

            if (data.hasToken) {

                status.textContent =
                    data.app_name
                        ? `✓ [${data.app_name}] の設定を読み込みました`
                        : '✓ GitHub設定を読み込みました';

                status.style.color =
                    '#28a745';

            } else {

                status.textContent =
                    'GitHub Tokenが設定されていません。';

                status.style.color =
                    '#666';
            }
        }


        return data;

    } catch (error) {

        /*
         * ここではconsole.errorを使わない。
         *
         * GitHub設定APIが存在しない、
         * またはHTMLを返す環境でも
         * ツリー自体は正常に表示させる。
         */
        console.warn(
            'GitHub設定読み込みをスキップ:',
            error.message ||
                error
        );


        if (status) {

            status.textContent =
                'GitHub設定は利用できません。';

            status.style.color =
                '#999';
        }


        return null;
    }
}


async function loadGithubSettingsSafe(
    targetPath = ''
) {
    try {

        return await loadGithubSettings(
            targetPath
        );

    } catch (error) {

        console.warn(
            'GitHub設定取得をスキップ:',
            error
        );

        return null;
    }
}


/* ==========================================================
 * Tree Options
 * ========================================================== */

function treeOptions(options) {

    if (
        typeof options ===
        'boolean'
    ) {
        return {
            isPublic: options
        };
    }

    return options || {};
}


function childNodes(node) {

    return Array.isArray(
        node?.children
    )
        ? node.children
        : [];
}


function isAppNode(node) {
    return (
        node?.type === 'app'
    );
}


function isMockOnly(node) {

    if (
        node?.status === 'mock' ||
        node?.isMock === true ||
        /\/mock$/i.test(
            node?.path || ''
        )
    ) {
        return true;
    }


    const children =
        childNodes(node);


    const hasDraft =
        children.some(
            child =>
                child?.name ===
                    'draft' ||
                /\/draft$/i.test(
                    child?.path || ''
                )
        );


    const hasMock =
        children.some(
            child =>
                child?.name ===
                    'mock' ||
                /\/mock$/i.test(
                    child?.path || ''
                )
        );


    return (
        hasMock &&
        !hasDraft
    );
}


/* ==========================================================
 * Tree HTML
 * ========================================================== */

function buildHTML(
    nodes,
    options = {}
) {
    options =
        treeOptions(options);


    if (!Array.isArray(nodes)) {
        return '';
    }


    const isPublic =
        Boolean(
            options.isPublic
        );


    const defaultStatus =
        options.status ||
        (
            isPublic
                ? 'published'
                : 'draft'
        );


    const hidden =
        new Set([
            '.poc',
            'poc',
            'published',
            '.history',
            '.prompt',
            '.github',
            '.harness',
            'spec',
            'index.php',
            'config.json'
        ]);


    return nodes
        .filter(
            node => {

                if (!node) {
                    return false;
                }


                if (
                    hidden.has(
                        node.name
                    )
                ) {
                    return false;
                }


                return true;
            }
        )
        .map(
            node =>
                buildTreeNode(
                    node,
                    isPublic,
                    defaultStatus
                )
        )
        .join('');
}


/* ==========================================================
 * Tree Node
 * ========================================================== */

function buildTreeNode(
    node,
    isPublic,
    defaultStatus
) {
    if (!node) {
        return '';
    }


    const rawPath =
        normalizePath(
            node.path ||
            node.name ||
            ''
        );


    if (!rawPath) {
        return '';
    }


    const displayName =
        node.name ||
        rawPath
            .split('/')
            .pop() ||
        '';


    const children =
        childNodes(node);


    const published =
        isPublic ||
        node.published === true ||
        node.published === 'true' ||
        node.published === 1;


    const nodeIsApp =
        node.type === 'app' ||
        (
            !isPublic &&
            (
                children.length > 0 ||
                !/\/(draft|mock)$/i.test(
                    rawPath
                )
            )
        );


    const isStage =
        /\/(draft|mock)$/i.test(
            rawPath
        );


    let childrenHTML =
        '';


    if (
        !isPublic &&
        nodeIsApp &&
        !isStage
    ) {

        childrenHTML =
            buildAppStages(
                rawPath,
                published,
                children
            );

    } else if (
        children.length
    ) {

        childrenHTML =
            buildHTML(
                children,
                {
                    isPublic,
                    status:
                        defaultStatus
                }
            );
    }


    const badge =
        !isPublic &&
        published
            ? '<span class="published-badge badge-public">🌐公開中</span>'
            : '';


    const icon =
        isPublic
            ? '🌐'
            : (
                nodeIsApp
                    ? '📁'
                    : '📂'
            );


    const type =
        nodeIsApp
            ? 'app-group'
            : (
                node.type ||
                'folder'
            );


    const status =
        isMockOnly(node)
            ? 'mock'
            : defaultStatus;


    const safePath =
        escapeHtml(
            rawPath
        );


    const jsPath =
        JSON.stringify(
            rawPath
        );


    const hasChildren =
        Boolean(
            childrenHTML
        );


    return `
        <details
            data-path="${safePath}"
            data-type="${escapeHtml(type)}"
            data-status="${escapeHtml(status)}"
            data-published="${published}"
            class="${
                nodeIsApp
                    ? 'item-app-group'
                    : 'item-folder'
            }"
            ${
                hasChildren
                    ? 'open'
                    : ''
            }
            ontoggle="typeof saveTreeState === 'function' && saveTreeState()"
        >

            <summary
                draggable="true"
                ondragstart="typeof drag === 'function' && drag(event, ${jsPath})"
                ondragover="typeof allowDrop === 'function' && allowDrop(event)"
                ondragleave="typeof dragLeave === 'function' && dragLeave(event)"
                ondrop="typeof drop === 'function' && drop(event, ${jsPath})"
                oncontextmenu="typeof showContext === 'function' && showContext(event, ${jsPath}, ${published})"
                onclick="typeof selectFolder === 'function' && selectFolder(event, ${jsPath}, ${published})"
            >

                <span
                    style="
                        margin-right:6px;
                        display:inline-block;
                        width:18px;
                        text-align:center;
                    "
                >${icon}</span>

                <span class="item-name">
                    ${escapeHtml(
                        displayName
                    )}
                </span>

                ${badge}

            </summary>

            ${
                childrenHTML
                    ? `
                        <div class="tree-children">
                            ${childrenHTML}
                        </div>
                    `
                    : ''
            }

        </details>
    `;
}


/* ==========================================================
 * App Stages
 * ========================================================== */

function buildAppStages(
    appPath,
    published,
    children
) {
    const normalizedPath =
        normalizePath(
            appPath
        );


    if (!normalizedPath) {
        return '';
    }


    const draftPath =
        `${normalizedPath}/draft`;


    const mockPath =
        `${normalizedPath}/mock`;


    const list =
        Array.isArray(children)
            ? children
            : [];


    const nestedNodes =
        list.filter(
            child =>
                child &&
                child.name !==
                    'draft' &&
                child.name !==
                    'mock' &&
                child.name !==
                    'index.php'
        );


    let html = '';


    html +=
        createStageItem(
            draftPath,
            '🔨',
            '開発中',
            published,
            false
        );


    html +=
        createStageItem(
            mockPath,
            '📐',
            'モック',
            published,
            false
        );


    if (
        nestedNodes.length
    ) {

        html +=
            buildHTML(
                nestedNodes,
                {
                    isPublic: false,
                    status: 'draft'
                }
            );
    }


    return html;
}


/* ==========================================================
 * Stage Item
 * ========================================================== */

function createStageItem(
    path,
    icon,
    label,
    published = false,
    isPublic = false
) {
    const normalized =
        normalizePath(
            path
        );


    if (!normalized) {
        return '';
    }


    const safePath =
        escapeHtml(
            normalized
        );


    const jsPath =
        JSON.stringify(
            normalized
        );


    const status =
        label === 'モック'
            ? 'mock'
            : 'draft';


    return `
        <details
            data-path="${safePath}"
            data-type="app-item"
            data-status="${status}"
            data-published="${Boolean(published)}"
            class="item-app"
            ontoggle="typeof saveTreeState === 'function' && saveTreeState()"
        >

            <summary
                draggable="true"
                ondragstart="typeof drag === 'function' && drag(event, ${jsPath})"
                ondragover="typeof allowDrop === 'function' && allowDrop(event)"
                ondragleave="typeof dragLeave === 'function' && dragLeave(event)"
                ondrop="typeof drop === 'function' && drop(event, ${jsPath})"
                oncontextmenu="typeof showContext === 'function' && showContext(event, ${jsPath}, ${isPublic})"
                onclick="typeof selectFolder === 'function' && selectFolder(event, ${jsPath}, ${isPublic})"
            >

                <span
                    style="
                        margin-right:6px;
                        display:inline-block;
                        width:18px;
                        text-align:center;
                    "
                >${icon}</span>

                <span class="item-name">
                    ${escapeHtml(label)}
                </span>

            </summary>

        </details>
    `;
}


/* ==========================================================
 * Tree Load
 * ========================================================== */

async function loadTrees() {

    try {

        console.log(
            '[GOJACIC] ツリー読み込み開始'
        );


        const data =
            await apiCall(
                'get_tree'
            );


        console.log(
            '[GOJACIC] get_tree response:',
            data
        );


        if (
            !data?.success
        ) {
            throw new Error(
                data?.error ||
                'ツリー取得に失敗しました。'
            );
        }


        /* --------------------------------------------------
         * Public Tree
         * -------------------------------------------------- */

        const publicTree =
            $('tree-pub') ||
            $('tree-public');


        if (publicTree) {

            const published =
                Array.isArray(
                    data.published
                )
                    ? data.published
                    : (
                        Array.isArray(
                            data.public
                        )
                            ? data.public
                            : []
                    );


            publicTree.innerHTML =
                buildHTML(
                    published,
                    {
                        isPublic: true,
                        status:
                            'published'
                    }
                );
        }


        /* --------------------------------------------------
         * Development Tree
         * -------------------------------------------------- */

        const devTree =
            $('tree-dev') ||
            $('tree-draft');


        if (devTree) {

            let apps = [];


            if (
                Array.isArray(
                    data.poc
                )
            ) {

                apps =
                    data.poc;


            } else if (
                Array.isArray(
                    data.tree
                )
            ) {

                apps =
                    data.tree;


            } else {

                const drafts =
                    Array.isArray(
                        data.draft
                    )
                        ? data.draft
                        : [];


                const mocks =
                    Array.isArray(
                        data.mock
                    )
                        ? data.mock
                        : [];


                const map =
                    new Map();


                drafts.forEach(
                    item => {

                        if (!item) {
                            return;
                        }


                        const key =
                            item.path ||
                            item.name;


                        if (key) {
                            map.set(
                                key,
                                item
                            );
                        }
                    }
                );


                mocks.forEach(
                    item => {

                        if (!item) {
                            return;
                        }


                        const key =
                            item.path ||
                            item.name;


                        if (
                            key &&
                            !map.has(key)
                        ) {
                            map.set(
                                key,
                                item
                            );
                        }
                    }
                );


                apps =
                    Array.from(
                        map.values()
                    );
            }


            apps =
                apps.filter(
                    item =>
                        item &&
                        typeof item ===
                            'object'
                );


            apps.sort(
                (a, b) =>
                    String(
                        a?.name || ''
                    ).localeCompare(
                        String(
                            b?.name || ''
                        ),
                        'ja'
                    )
            );


            console.log(
                '[GOJACIC] development tree:',
                apps
            );


            devTree.innerHTML =
                buildHTML(
                    apps,
                    {
                        isPublic: false,
                        status: 'draft'
                    }
                );


            /*
             * データが空だった場合だけ警告。
             * JSエラーにはしない。
             */
            if (
                apps.length === 0
            ) {

                console.warn(
                    '[GOJACIC] 開発ツリーのデータが空です。',
                    data
                );
            }
        }


        /* --------------------------------------------------
         * GitHub設定
         *
         * 失敗してもloadTrees()を失敗させない。
         * -------------------------------------------------- */

        await loadGithubSettingsSafe(
            ''
        );


        /* --------------------------------------------------
         * Tree state
         * -------------------------------------------------- */

        restoreTreeState();

        updateSelectedStyles();


        console.log(
            '[GOJACIC] ツリー読み込み完了'
        );


    } catch (error) {

        console.error(
            '[GOJACIC] ツリー読み込み失敗:',
            error
        );


        const devTree =
            $('tree-dev') ||
            $('tree-draft');


        if (devTree) {

            devTree.innerHTML = `
                <div
                    style="
                        padding:10px;
                        color:#dc3545;
                        font-size:13px;
                    "
                >
                    ツリーを読み込めませんでした。<br>
                    ${escapeHtml(
                        error.message ||
                        String(error)
                    )}
                </div>
            `;
        }
    }
}


/* ==========================================================
 * Tree State
 * ========================================================== */

function saveTreeState() {

    const paths =
        Array.from(
            document.querySelectorAll(
                'details[open][data-path]'
            )
        )
        .map(
            element =>
                element.getAttribute(
                    'data-path'
                )
        )
        .filter(Boolean);


    try {

        localStorage.setItem(
            'treeState_gojacic',
            JSON.stringify(paths)
        );

    } catch (error) {

        console.warn(
            '[GOJACIC] ツリー状態保存失敗:',
            error
        );
    }
}


function restoreTreeState() {

    let paths = [];


    try {

        const saved =
            localStorage.getItem(
                'treeState_gojacic'
            );


        if (saved) {

            const parsed =
                JSON.parse(
                    saved
                );


            if (
                Array.isArray(
                    parsed
                )
            ) {
                paths =
                    parsed;
            }
        }

    } catch (error) {

        console.warn(
            '[GOJACIC] ツリー状態復元失敗:',
            error
        );

        paths = [];
    }


    const pathSet =
        new Set(
            paths.map(
                path =>
                    normalizePath(
                        path
                    )
            )
        );


    document
        .querySelectorAll(
            'details[data-path]'
        )
        .forEach(
            element => {

                const path =
                    normalizePath(
                        element.getAttribute(
                            'data-path'
                        ) || ''
                    );


                if (
                    pathSet.has(
                        path
                    )
                ) {
                    element.open =
                        true;
                }
            }
        );


    updateSelectedStyles();
}


/* ==========================================================
 * Selected Style
 * ========================================================== */

function updateSelectedStyles() {

    document
        .querySelectorAll(
            'summary.active,' +
            'summary.selected'
        )
        .forEach(
            element => {

                element.classList.remove(
                    'active',
                    'selected'
                );
            }
        );


    if (!currentPath) {
        return;
    }


    const targetPath =
        normalizePath(
            currentPath
        );


    document
        .querySelectorAll(
            'details[data-path]'
        )
        .forEach(
            details => {

                const path =
                    normalizePath(
                        details.dataset.path ||
                        ''
                    );


                if (
                    path ===
                    targetPath
                ) {

                    const summary =
                        details.querySelector(
                            ':scope > summary'
                        );


                    if (summary) {

                        summary.classList.add(
                            'active',
                            'selected'
                        );
                    }


                    let parent =
                        details.parentElement;


                    while (
                        parent
                    ) {

                        if (
                            parent.matches?.(
                                'details[data-path]'
                            )
                        ) {
                            parent.open =
                                true;
                        }


                        parent =
                            parent.parentElement;
                    }
                }
            }
        );
}


/* ==========================================================
 * Preview
 * ========================================================== */

function updatePreviewFrame(
    rawPath,
    isPublic
) {
    const frame =
        $('preview-frame');


    if (
        !frame ||
        !rawPath
    ) {
        return;
    }


    let path =
        normalizePath(
            rawPath
        );


    if (
        !isPublic &&
        !/(\/draft|\/mock)$/i.test(
            path
        )
    ) {
        path += '/draft';
    }


    const encodedPath =
        path
            .split('/')
            .map(
                segment =>
                    encodeURIComponent(
                        segment
                    )
            )
            .join('/');


    const base =
        window.__GOJACIC_APP_BASE_PATH__ ||
        APP_BASE_PATH_VALUE ||
        '/';


    try {

        frame.src =
            new URL(
                `${encodedPath}/`,
                new URL(
                    base,
                    window.location.origin
                )
            ).href;

    } catch (error) {

        console.error(
            'プレビューURL生成エラー:',
            error
        );
    }
}


function showWelcomeMessage() {

    const frame =
        $('preview-frame');

    const welcome =
        $('welcome-message-area');


    if (frame) {
        frame.style.display =
            'none';
    }


    if (welcome) {
        welcome.style.display =
            'flex';
    }
}


function hideWelcomeMessage() {

    const frame =
        $('preview-frame');

    const welcome =
        $('welcome-message-area');


    if (frame) {
        frame.style.display =
            'block';
    }


    if (welcome) {
        welcome.style.display =
            'none';
    }
}


/* ==========================================================
 * iframe Message
 * ========================================================== */

if (
    !window.__gojacicMessageListener
) {

    window.addEventListener(
        'message',
        event => {

            if (
                event.data?.type ===
                'SELECT_APP_PATH'
            ) {

                selectFolder(
                    null,
                    event.data.path,
                    Boolean(
                        event.data.isPublic
                    )
                );
            }
        }
    );


    window.__gojacicMessageListener =
        true;
}


/* ==========================================================
 * Path Resolver
 * ========================================================== */

function resolveFolderPath(
    target,
    fallback = ''
) {

    if (
        typeof target ===
            'string' &&
        target.trim()
    ) {
        return target.trim();
    }


    if (
        target?.target
    ) {

        return resolveFolderPath(
            target.target,
            fallback
        );
    }


    if (
        target instanceof
        HTMLElement
    ) {

        const element =
            target.closest(
                '[data-path]'
            ) ||
            target;


        return (
            element.dataset.path ||
            element.getAttribute(
                'data-path'
            ) ||
            fallback
        );
    }


    return fallback;
}


/* ==========================================================
 * Select Folder
 * ========================================================== */

async function selectFolder(
    eventOrTarget,
    targetPath = null,
    isPublic = false
) {

    const path =
        normalizePath(
            resolveFolderPath(
                targetPath ||
                eventOrTarget
            )
        );


    if (!path) {

        console.warn(
            'selectFolder: 有効なパスがありません'
        );

        return;
    }


    currentPath =
        path;


    currentIsPublic =
        Boolean(
            isPublic
        );


    currentIsMock =
        /\/mock$/i.test(
            path
        );


    document
        .querySelectorAll(
            'summary.active,' +
            'summary.selected'
        )
        .forEach(
            element =>
                element.classList.remove(
                    'active',
                    'selected'
                )
        );


    let activeElement =
        null;


    document
        .querySelectorAll(
            'details[data-path]'
        )
        .forEach(
            details => {

                const detailPath =
                    normalizePath(
                        details.dataset.path ||
                        ''
                    );


                if (
                    detailPath ===
                    path
                ) {
                    activeElement =
                        details;
                }
            }
        );


    if (activeElement) {

        activeElement
            .querySelector(
                ':scope > summary'
            )
            ?.classList.add(
                'active',
                'selected'
            );


        let parent =
            activeElement.parentElement;


        while (
            parent
        ) {

            if (
                parent.matches?.(
                    'details[data-path]'
                )
            ) {
                parent.open =
                    true;
            }


            parent =
                parent.parentElement;
        }
    }


    const rootPath =
        path.replace(
            /\/(draft|mock)$/i,
            ''
        );


    if (
        typeof window
            .setAppGitHubSyncTarget ===
        'function'
    ) {

        window.setAppGitHubSyncTarget(
            rootPath
        );
    }


    const pathDisplay =
        $('current-path-display');


    if (pathDisplay) {
        pathDisplay.textContent =
            path;
    }


    const preview =
        $('preview-frame');


    const welcome =
        $('welcome-message-area');


    const overlay =
        $('folder-status-overlay');


    const isExecutable =
        /\/(draft|mock)$/i.test(
            path
        );


    if (!isExecutable) {

        showFolderStatus(
            path,
            currentIsPublic,
            preview,
            welcome,
            overlay
        );


        updateActionButtons(
            path,
            currentIsPublic
        );


        return;
    }


    if (overlay) {
        overlay.style.display =
            'none';
    }


    if (welcome) {
        welcome.style.display =
            'none';
    }


    updateActionButtons(
        path,
        currentIsPublic
    );


    if (
        typeof loadPromptHistory ===
        'function'
    ) {

        try {

            await loadPromptHistory(
                path
            );

        } catch (error) {

            console.warn(
                '履歴読み込みエラー:',
                error
            );
        }
    }


    if (preview) {

        preview.style.display =
            'block';


        updatePreviewFrame(
            path,
            currentIsPublic
        );
    }
}


/* ==========================================================
 * Folder Status
 * ========================================================== */

function showFolderStatus(
    path,
    isPublic,
    preview,
    welcome,
    overlay
) {

    if (preview) {
        preview.style.display =
            'none';
    }


    if (welcome) {
        welcome.style.display =
            'none';
    }


    if (!overlay) {
        return;
    }


    overlay.style.display =
        'flex';


    const title =
        overlay.querySelector(
            '[data-folder-status-title]'
        );


    const message =
        overlay.querySelector(
            '[data-folder-status-message]'
        );


    if (title) {

        title.textContent =
            isPublic
                ? '公開フォルダ'
                : 'フォルダ';
    }


    if (message) {

        message.textContent =
            path
                ? `${path} を選択しています`
                : 'フォルダを選択しています';
    }
}


/* ==========================================================
 * Action Buttons
 * ========================================================== */

function updateActionButtons(
    path = currentPath,
    isPublic = currentIsPublic
) {

    const normalized =
        normalizePath(
            path
        );


    const isDraft =
        /\/draft$/i.test(
            normalized
        );


    const isMock =
        /\/mock$/i.test(
            normalized
        );


    const isAppItem =
        isDraft ||
        isMock;


    setButtonVisible(
        'btn-save',
        !isPublic &&
            isAppItem
    );


    setButtonVisible(
        'btn-save-current',
        !isPublic &&
            isAppItem
    );


    setButtonVisible(
        'btn-publish',
        !isPublic &&
            isDraft
    );


    setButtonVisible(
        'btn-edit-mock',
        !isPublic &&
            isMock
    );


    setButtonVisible(
        'btn-github-sync',
        isPublic ||
            isAppItem
    );
}


/* ==========================================================
 * File Content
 * ========================================================== */

async function loadFileContent(
    path
) {

    if (!path) {
        return null;
    }


    try {

        const data =
            await apiCall(
                'get_file',
                {
                    path
                }
            );


        if (
            !data?.success
        ) {

            throw new Error(
                data?.error ||
                'ファイル取得に失敗しました。'
            );
        }


        return data;

    } catch (error) {

        console.error(
            'ファイル取得エラー:',
            error
        );


        alert(
            `ファイルを取得できませんでした。\n${error.message}`
        );


        return null;
    }
}


/* ==========================================================
 * Save
 * ========================================================== */

async function saveFile() {

    if (isSaving) {
        return;
    }


    if (!currentPath) {

        alert(
            '保存するフォルダを選択してください。'
        );

        return;
    }


    if (currentIsPublic) {

        alert(
            '公開側のファイルはここから保存できません。'
        );

        return;
    }


    isSaving = true;


    startBlocking(
        '保存中...'
    );


    try {

        const data =
            await apiCall(
                'save_file',
                {
                    path:
                        currentPath,
                    content:
                        getEditorContent()
                }
            );


        if (
            !data?.success
        ) {

            throw new Error(
                data?.error ||
                '保存に失敗しました。'
            );
        }


        const status =
            $('prompt-status');


        if (status) {

            status.textContent =
                '✓ 保存しました';

            status.style.color =
                '#28a745';
        }


        await loadTrees();


    } catch (error) {

        console.error(
            '保存エラー:',
            error
        );


        alert(
            `保存に失敗しました。\n${error.message}`
        );


    } finally {

        isSaving = false;

        stopBlocking();
    }
}


/* ==========================================================
 * Prompt History
 * ========================================================== */

function getHistoryElements() {

    return {
        modal:
            $('history-modal'),

        list:
            $('history-list') ||
            $('prompt-history-list'),

        status:
            $('history-status')
    };
}


function closeHistoryModal() {

    const {
        modal
    } =
        getHistoryElements();


    if (modal) {

        modal.style.display =
            'none';
    }
}


async function loadPromptHistory(
    path = currentPath
) {

    if (!path) {
        return [];
    }


    const {
        list,
        status
    } =
        getHistoryElements();


    try {

        const data =
            await apiCall(
                'get_prompt_history',
                {
                    path
                }
            );


        if (
            !data?.success
        ) {

            throw new Error(
                data?.error ||
                '履歴取得に失敗しました。'
            );
        }


        const history =
            Array.isArray(
                data.history
            )
                ? data.history
                : [];


        if (list) {

            list.innerHTML =
                history.length
                    ? history
                        .map(
                            item =>
                                createHistoryItem(
                                    item,
                                    path
                                )
                        )
                        .join('')
                    : '<div class="empty-history">履歴はありません。</div>';
        }


        if (status) {

            status.textContent =
                `${history.length}件`;
        }


        return history;


    } catch (error) {

        console.warn(
            '履歴取得エラー:',
            error
        );


        if (list) {

            list.innerHTML =
                '<div class="empty-history">履歴を取得できませんでした。</div>';
        }


        return [];
    }
}


function createHistoryItem(
    item,
    path
) {

    const filename =
        item?.filename ||
        item?.name ||
        '';


    const title =
        item?.title ||
        item?.memo ||
        filename;


    const date =
        item?.date ||
        item?.created_at ||
        '';


    const jsFilename =
        JSON.stringify(
            filename
        );


    return `
        <button
            type="button"
            class="history-item"
            onclick="selectPromptHistory(${jsFilename})"
            data-path="${escapeHtml(path)}"
        >
            <span class="history-item-title">
                ${escapeHtml(title)}
            </span>

            ${
                date
                    ? `
                        <span class="history-item-date">
                            ${escapeHtml(date)}
                        </span>
                    `
                    : ''
            }
        </button>
    `;
}


async function selectPromptHistory(
    filename
) {

    if (!filename) {
        return;
    }


    try {

        const data =
            await apiCall(
                'get_prompt_content',
                {
                    path:
                        currentPath,
                    filename
                }
            );


        if (
            !data?.success
        ) {

            throw new Error(
                data?.error ||
                '履歴の読み込みに失敗しました。'
            );
        }


        setEditorContent(
            data.content || ''
        );


        if (
            typeof openModal ===
            'function'
        ) {

            openModal(
                data.memo ||
                    filename,
                data.content ||
                    ''
            );
        }


    } catch (error) {

        console.error(
            '履歴内容取得エラー:',
            error
        );


        alert(
            `履歴を読み込めませんでした。\n${error.message}`
        );
    }
}


/* ==========================================================
 * Context Menu
 * ========================================================== */

function getContextMenu() {

    return (
        $('context-menu') ||
        $('file-context-menu')
    );
}


function hideContextMenu() {

    const menu =
        getContextMenu();


    if (menu) {

        menu.style.display =
            'none';
    }


    contextMenuPath =
        '';

    contextMenuIsPublic =
        false;
}


function showContext(
    event,
    path,
    isPublic = false
) {

    event?.preventDefault();
    event?.stopPropagation();


    const menu =
        getContextMenu();


    if (!menu) {
        return;
    }


    contextMenuPath =
        String(
            path || ''
        );


    contextMenuIsPublic =
        Boolean(
            isPublic
        );


    menu.style.display =
        'block';


    const rect =
        menu.getBoundingClientRect();


    const x =
        event?.clientX || 0;


    const y =
        event?.clientY || 0;


    const left =
        Math.min(
            x,
            window.innerWidth -
                rect.width -
                8
        );


    const top =
        Math.min(
            y,
            window.innerHeight -
                rect.height -
                8
        );


    menu.style.left =
        `${Math.max(8, left)}px`;


    menu.style.top =
        `${Math.max(8, top)}px`;
}


document.addEventListener(
    'click',
    event => {

        const menu =
            getContextMenu();


        if (
            menu &&
            !menu.contains(
                event.target
            )
        ) {

            hideContextMenu();
        }
    }
);


/* ==========================================================
 * Drag & Drop
 * ========================================================== */

function drag(
    event,
    path
) {

    draggedPath =
        normalizePath(
            path
        );


    if (
        event?.dataTransfer
    ) {

        event.dataTransfer.effectAllowed =
            'move';

        event.dataTransfer.setData(
            'text/plain',
            draggedPath
        );
    }
}


function allowDrop(event) {

    event?.preventDefault();


    if (
        event?.dataTransfer
    ) {

        event.dataTransfer.dropEffect =
            'move';
    }


    event?.currentTarget
        ?.classList.add(
            'drag-over'
        );
}


function dragLeave(event) {

    event?.currentTarget
        ?.classList.remove(
            'drag-over'
        );
}


async function drop(
    event,
    targetPath
) {

    event?.preventDefault();


    event?.currentTarget
        ?.classList.remove(
            'drag-over'
        );


    const sourcePath =
        normalizePath(
            draggedPath ||
            event?.dataTransfer?.getData(
                'text/plain'
            ) ||
            ''
        );


    const destinationPath =
        normalizePath(
            targetPath ||
            ''
        );


    if (
        !sourcePath ||
        !destinationPath ||
        sourcePath ===
            destinationPath
    ) {
        return;
    }


    try {

        const data =
            await apiCall(
                'move',
                {
                    source:
                        sourcePath,
                    destination:
                        destinationPath
                }
            );


        if (
            !data?.success
        ) {

            throw new Error(
                data?.error ||
                '移動に失敗しました。'
            );
        }


        await loadTrees();


    } catch (error) {

        console.error(
            '移動エラー:',
            error
        );


        alert(
            `移動に失敗しました。\n${error.message}`
        );
    }


    draggedPath =
        '';
}


/* ==========================================================
 * Init
 * ========================================================== */

function initGojacicManager() {

    updateLineNumbers();


    /*
     * ツリーを最優先で読み込む。
     *
     * GitHub設定が失敗しても
     * loadTrees()内で握りつぶすため、
     * ツリーは表示される。
     */
    loadTrees()
        .catch(
            error =>
                console.error(
                    '[GOJACIC] 初期化エラー:',
                    error
                )
        );
}


if (
    document.readyState ===
    'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        initGojacicManager,
        {
            once: true
        }
    );

} else {

    initGojacicManager();
}


/* ==========================================================
 * GOJACIC リサイザー
 *
 * 実際の index.php のDOM構造に合わせた版
 *
 * resizer-1               公開中 ↔ 作成中
 * resizer-2               作成中 ↔ メイン
 * resizer-v               プレビュー ↕ 下部
 * resizer-prompt-history-v プロンプト ↕ 履歴
 * resizer-4               プロンプト ↔ 結果反映
 * resizer-3               Pro左 ↔ Pro右
 * resizer-github-v        メイン ↕ GitHub
 * ========================================================== */

(function(){
'use strict';

let resize=null;

const $=id=>document.getElementById(id);

function bindX(id,leftId,rightId){
    const bar=$(id);
    const left=$(leftId);
    const right=$(rightId);

    if(!bar||!left||!right||bar.dataset.rz)return;

    bar.dataset.rz='1';

    bar.addEventListener('mousedown',function(e){
        e.preventDefault();
        e.stopPropagation();

        resize={
            type:'x',
            bar:bar,
            left:left,
            right:right,
            start:e.clientX,
            leftStart:left.getBoundingClientRect().width,
            rightStart:right.getBoundingClientRect().width
        };

        document.body.style.userSelect='none';
        document.body.style.cursor='col-resize';
    });
}

function bindY(id,top,bottom){
    const bar=$(id);

    if(!bar||bar.dataset.rz)return;

    bar.dataset.rz='1';

    bar.addEventListener('mousedown',function(e){
        e.preventDefault();
        e.stopPropagation();

        resize={
            type:'y',
            bar:bar,
            top:top,
            bottom:bottom,
            start:e.clientY,
            topStart:top.getBoundingClientRect().height,
            bottomStart:bottom.getBoundingClientRect().height
        };

        document.body.style.userSelect='none';
        document.body.style.cursor='row-resize';
    });
}

function bindGithub(){
    const bar=$('resizer-github-v');
    const card=$('github-fixed-card');

    if(!bar||!card||bar.dataset.rz)return;

    bar.dataset.rz='1';

    bar.addEventListener('mousedown',function(e){
        e.preventDefault();
        e.stopPropagation();

        resize={
            type:'github',
            bar:bar,
            card:card,
            start:e.clientY,
            startHeight:card.getBoundingClientRect().height
        };

        document.body.style.userSelect='none';
        document.body.style.cursor='row-resize';
    });
}

document.addEventListener('mousemove',function(e){
    if(!resize)return;

    e.preventDefault();

    if(resize.type==='x'){
        const d=e.clientX-resize.start;

        const leftWidth=Math.max(
            120,
            resize.leftStart+d
        );

        const rightWidth=Math.max(
            120,
            resize.rightStart-d
        );

        resize.left.style.flexBasis=leftWidth+'px';
        resize.left.style.width=leftWidth+'px';

        resize.right.style.flexBasis=rightWidth+'px';
        resize.right.style.width=rightWidth+'px';

        return;
    }

    if(resize.type==='y'){
        const d=e.clientY-resize.start;

        const topHeight=Math.max(
            80,
            resize.topStart+d
        );

        const bottomHeight=Math.max(
            60,
            resize.bottomStart-d
        );

        resize.top.style.flexBasis=topHeight+'px';
        resize.top.style.height=topHeight+'px';

        resize.bottom.style.flexBasis=bottomHeight+'px';
        resize.bottom.style.height=bottomHeight+'px';

        return;
    }

    if(resize.type==='github'){
        const d=resize.start-e.clientY;

        const height=Math.max(
            60,
            Math.min(
                500,
                resize.startHeight+d
            )
        );

        resize.card.style.flexBasis=height+'px';
        resize.card.style.height=height+'px';
    }
},{passive:false});

function stop(){
    if(!resize)return;

    resize=null;

    document.body.style.userSelect='';
    document.body.style.cursor='';
}

document.addEventListener('mouseup',stop);
document.addEventListener('mouseleave',stop);

function init(){

    bindX(
        'resizer-1',
        'col-public',
        'col-draft'
    );

    bindX(
        'resizer-2',
        'col-draft',
        'main-col'
    );

    bindY(
        'resizer-v',
        $('preview-area'),
        $('bottom-container')
    );

    bindY(
        'resizer-prompt-history-v',
        $('prompt-content-wrapper'),
        $('prompt-history-wrapper')
    );

    bindX(
        'resizer-4',
        'prompt-content-wrapper',
        'editor-area'
    );

    bindX(
        'resizer-3',
        'editor-holder-split',
        'split-area'
    );

    bindGithub();
}

if(document.readyState==='loading'){
    document.addEventListener(
        'DOMContentLoaded',
        init,
        {once:true}
    );
}else{
    init();
}

})();




</script>
</body>
</html>

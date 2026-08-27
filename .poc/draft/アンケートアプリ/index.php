<?php
declare(strict_types=1);

/*
 * ============================================================
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 *
 * 単一エントリーポイント:
 *   index.php?screen=list
 *   index.php?screen=edit&id=...
 *   index.php?screen=preview&id=...
 *   index.php?screen=send&id=...
 *   index.php?screen=analytics&id=...
 *   index.php?screen=kintone
 *   index.php?screen=mail
 *   index.php?screen=answer&id=...
 *   index.php?screen=confirm&id=...
 *   index.php?screen=complete&id=...
 *
 * DBなし
 * PHP cURLなし
 * PHP mail()なし
 * Canvasなし
 * 管理者認証なし
 * ============================================================
 */


/* ============================================================
 * 1. 初期設定
 * ============================================================
 */

date_default_timezone_set('Asia/Tokyo');

$APP_DIR  = __DIR__;
$DATA_DIR = $APP_DIR . DIRECTORY_SEPARATOR . 'data';

if (!is_dir($DATA_DIR)) {
    if (!@mkdir($DATA_DIR, 0770, true) && !is_dir($DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}


/*
 * セッション
 *
 * GETごとにIDを再生成しない。
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') ?: '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        http_response_code(500);
        exit('セッションを利用できません。');
    }
}


/* ============================================================
 * 2. 共通関数
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

function now_iso(): string
{
    return date('Y-m-d H:i:s');
}

function redirect_screen(string $screen, array $params = []): never
{
    $query = ['screen' => $screen];

    foreach ($params as $key => $value) {
        $query[$key] = $value;
    }

    header(
        'Location: index.php?' . http_build_query($query),
        true,
        303
    );

    exit;
}

function safe_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}


/* ============================================================
 * 3. CSRF
 *
 * 要件:
 * - POSTで検証
 * - URLに含めない
 * - GETごとに再生成しない
 * ============================================================
 */

function csrf_token(): string
{
    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $posted = $_POST['csrf'] ?? '';

    if (
        !is_string($posted) ||
        $posted === '' ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals(
            (string)$_SESSION['csrf_token'],
            $posted
        )
    ) {
        http_response_code(400);
        render_error(
            '不正なリクエストです。',
            'CSRFトークンが一致しません。画面を再読み込みして再度お試しください。'
        );
        exit;
    }
}


/* ============================================================
 * 4. ファイル永続化
 *
 * Web公開ディレクトリ内でもPHPとして実行されるファイルに
 * 保存し、ブラウザから直接JSONを取得できない構造にする。
 *
 * 保存形式:
 * <?php exit; ?>
 * __PAYLOAD__
 * {JSON}
 * ============================================================
 */

function data_file(string $name): string
{
    global $DATA_DIR;

    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $name)) {
        throw new RuntimeException('不正なデータファイル名です。');
    }

    return $DATA_DIR . DIRECTORY_SEPARATOR . $name . '.php';
}

function read_data(string $name, array $default = []): array
{
    $file = data_file($name);

    if (!is_file($file)) {
        return $default;
    }

    $raw = @file_get_contents($file);

    if ($raw === false) {
        return $default;
    }

    $marker = "__PAYLOAD__\n";
    $pos = strpos($raw, $marker);

    if ($pos === false) {
        return $default;
    }

    $json = substr(
        $raw,
        $pos + strlen($marker)
    );

    $data = json_decode(
        $json,
        true
    );

    return is_array($data) ? $data : $default;
}

function write_data(string $name, array $data): bool
{
    $file = data_file($name);
    $tmp  = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $content =
        "<?php http_response_code(404); exit; ?>\n" .
        "__PAYLOAD__\n" .
        $json;

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        return false;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tmp);
            return false;
        }

        $written = fwrite($fp, $content);

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($written === false || $written < strlen($content)) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;

    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        return false;
    }
}


/* ============================================================
 * 5. 初期データ
 * ============================================================
 */

function load_surveys(): array
{
    return read_data('surveys', []);
}

function save_surveys(array $surveys): void
{
    if (!write_data('surveys', $surveys)) {
        throw new RuntimeException(
            'アンケートデータを保存できませんでした。'
        );
    }
}

function load_answers(): array
{
    return read_data('answers', []);
}

function save_answers(array $answers): void
{
    if (!write_data('answers', $answers)) {
        throw new RuntimeException(
            '回答データを保存できませんでした。'
        );
    }
}

function load_customers(): array
{
    return read_data('customers', []);
}

function save_customers(array $customers): void
{
    if (!write_data('customers', $customers)) {
        throw new RuntimeException(
            '顧客データを保存できませんでした。'
        );
    }
}

function load_send_history(): array
{
    return read_data('send_history', []);
}

function save_send_history(array $history): void
{
    if (!write_data('send_history', $history)) {
        throw new RuntimeException(
            '送信履歴を保存できませんでした。'
        );
    }
}

function load_settings(): array
{
    return read_data(
        'settings',
        [
            'kintone' => [
                'subdomain' => '',
                'app_id' => '',
                'username' => '',
                'password' => '',
                'proxy' => '',
                'verify_ssl' => false,
                'connection_status' => '未設定',
                'fields' => [],
                'mapping' => [
                    'organization' => [],
                    'name' => '',
                    'email' => '',
                    'department' => '',
                    'phone' => '',
                    'address' => [],
                ],
            ],
            'mail' => [
                'server' => '',
                'port' => 587,
                'encryption' => 'tls',
                'auth' => true,
                'username' => '',
                'password' => '',
                'from_email' => '',
                'from_name' => '',
                'reply_to' => '',
                'connection_status' => '未設定',
            ],
        ]
    );
}

function save_settings(array $settings): void
{
    if (!write_data('settings', $settings)) {
        throw new RuntimeException(
            '設定を保存できませんでした。'
        );
    }
}


/* ============================================================
 * 6. アンケート構造
 * ============================================================
 */

function new_question(): array
{
    return [
        'id' => 'q_' . bin2hex(random_bytes(6)),
        'number' => '',
        'text' => '',
        'type' => 'single',
        'required' => false,
        'choices' => [
            ['id' => 'c_' . bin2hex(random_bytes(4)), 'text' => '選択肢1'],
            ['id' => 'c_' . bin2hex(random_bytes(4)), 'text' => '選択肢2'],
        ],
        'branch' => [],
    ];
}

function new_group(): array
{
    return [
        'id' => 'g_' . bin2hex(random_bytes(6)),
        'title' => '新しいグループ',
        'questions' => [],
    ];
}

function new_survey(): array
{
    $group = new_group();
    $group['title'] = '基本情報';
    $group['questions'][] = new_question();

    $survey = [
        'id' => 'survey_' . bin2hex(random_bytes(6)),
        'title' => '新しいアンケート',
        'description' => '',
        'startAt' => date('Y-m-d\TH:i'),
        'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
        'numbering' => 'global',
        'status' => 'draft',
        'createdAt' => now_iso(),
        'updatedAt' => now_iso(),
        'groups' => [$group],
    ];

    renumber_questions($survey);

    return $survey;
}

function find_survey(string $id): ?array
{
    foreach (load_surveys() as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function renumber_questions(array &$survey): void
{
    $numbering = $survey['numbering'] ?? 'global';

    if ($numbering === 'group') {
        $gno = 1;

        foreach ($survey['groups'] as &$group) {
            $qno = 1;

            foreach ($group['questions'] as &$question) {
                $question['number'] =
                    'Q' . $gno . '-' . $qno;
                $qno++;
            }

            unset($question);
            $gno++;
        }

        unset($group);

        return;
    }

    $number = 1;

    foreach ($survey['groups'] as &$group) {
        foreach ($group['questions'] as &$question) {
            $question['number'] = 'Q' . $number;
            $number++;
        }

        unset($question);
    }

    unset($group);
}

function normalize_survey(array $survey): array
{
    $survey['title'] =
        trim((string)($survey['title'] ?? ''));

    $survey['description'] =
        trim((string)($survey['description'] ?? ''));

    $survey['numbering'] =
        (($survey['numbering'] ?? 'global') === 'group')
            ? 'group'
            : 'global';

    if (!isset($survey['groups']) || !is_array($survey['groups'])) {
        $survey['groups'] = [];
    }

    foreach ($survey['groups'] as &$group) {
        if (!isset($group['questions']) || !is_array($group['questions'])) {
            $group['questions'] = [];
        }

        foreach ($group['questions'] as &$question) {

            $allowed = [
                'single',
                'multiple',
                'text',
            ];

            if (!in_array(
                $question['type'] ?? '',
                $allowed,
                true
            )) {
                $question['type'] = 'single';
            }

            $question['text'] =
                trim((string)($question['text'] ?? ''));

            $question['required'] =
                !empty($question['required']);

            if (!isset($question['choices']) || !is_array($question['choices'])) {
                $question['choices'] = [];
            }

            if ($question['type'] === 'text') {
                $question['choices'] = [];
            }

            foreach ($question['choices'] as &$choice) {
                if (!isset($choice['id'])) {
                    $choice['id'] =
                        'c_' . bin2hex(random_bytes(4));
                }

                $choice['text'] =
                    trim((string)($choice['text'] ?? ''));
            }

            unset($choice);

            if (
                !isset($question['branch']) ||
                !is_array($question['branch'])
            ) {
                $question['branch'] = [];
            }
        }

        unset($question);
    }

    unset($group);

    renumber_questions($survey);

    return $survey;
}


/* ============================================================
 * 7. 状態
 * ============================================================
 */

function apply_auto_status(array $survey): array
{
    if (
        ($survey['status'] ?? '') === 'published' &&
        !empty($survey['endAt'])
    ) {
        $end = strtotime((string)$survey['endAt']);

        if ($end !== false && $end < time()) {
            $survey['status'] = 'ended';
        }
    }

    return $survey;
}

function status_label(string $status): string
{
    return match ($status) {
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '不明',
    };
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'danger',
        default => 'gray',
    };
}

function allowed_status_transition(
    string $from,
    string $to
): bool {
    return match ($from) {
        'draft' =>
            $to === 'published',

        'published' =>
            $to === 'stopped',

        'stopped' =>
            $to === 'published',

        'ended' =>
            false,

        default =>
            false,
    };
}


/* ============================================================
 * 8. HTML共通
 * ============================================================
 */

function render_error(
    string $title,
    string $message
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<style>
:root{
 --primary:#2563eb;
 --danger:#dc2626;
 --border:#dbe2ea;
 --text:#1e293b;
 --white:#fff;
 --shadow:0 4px 18px rgba(15,23,42,.08);
}
*{box-sizing:border-box}
body{
 margin:0;
 background:#f8fafc;
 color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
 "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
.container{
 max-width:900px;
 margin:60px auto;
 padding:20px;
}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 box-shadow:var(--shadow);
 padding:28px;
}
.error-title{
 color:var(--danger);
 font-size:20px;
 font-weight:700;
 margin-bottom:12px;
}
.message{
 line-height:1.8;
 white-space:pre-wrap;
}
.btn{
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
<div class="container">
<div class="card">
<div class="error-title"><?= h($title) ?></div>
<div class="message"><?= h($message) ?></div>
<a class="btn" href="index.php?screen=list">
アンケート一覧へ
</a>
</div>
</div>
</body>
</html>
<?php
}

function admin_header(
    string $title,
    bool $back = true
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<style>
:root{
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
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
 background:#f8fafc;
 color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
 "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
.header{
 background:#0f172a;
 color:#fff;
 min-height:60px;
}
.header-inner{
 max-width:1400px;
 margin:auto;
 padding:0 22px;
 min-height:60px;
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:20px;
}
.brand{
 color:#fff;
 text-decoration:none;
 font-weight:800;
}
.nav{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
}
.nav a{
 color:#cbd5e1;
 text-decoration:none;
 padding:8px 10px;
 border-radius:6px;
 font-size:13px;
}
.nav a:hover{background:#1e293b;color:#fff}
.container{
 max-width:1400px;
 margin:0 auto;
 padding:28px 22px 60px;
}
.page-title{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:16px;
 margin-bottom:22px;
}
.page-title h1{
 font-size:25px;
 margin:0;
}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 box-shadow:var(--shadow);
 padding:22px;
 margin-bottom:20px;
}
.grid{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:18px;
}
.grid-3{
 display:grid;
 grid-template-columns:repeat(3,minmax(0,1fr));
 gap:18px;
}
.field{margin-bottom:16px}
.field label{
 display:block;
 font-weight:700;
 margin-bottom:7px;
}
input[type=text],
input[type=password],
input[type=email],
input[type=number],
input[type=datetime-local],
textarea,
select{
 width:100%;
 border:1px solid var(--border);
 border-radius:8px;
 padding:10px 12px;
 background:#fff;
 color:var(--text);
 font-size:14px;
}
textarea{
 min-height:100px;
 resize:vertical;
}
button,.btn{
 border:0;
 border-radius:8px;
 padding:10px 15px;
 font-size:14px;
 font-weight:700;
 cursor:pointer;
 text-decoration:none;
 display:inline-flex;
 align-items:center;
 justify-content:center;
 gap:7px;
}
.btn-primary,button.btn-primary{
 background:var(--primary);
 color:#fff;
}
.btn-primary:hover{background:var(--primary-dark)}
.btn-secondary{
 background:var(--gray-light);
 color:var(--text);
}
.btn-success{
 background:var(--success);
 color:#fff;
}
.btn-warning{
 background:var(--warning);
 color:#fff;
}
.btn-danger{
 background:var(--danger);
 color:#fff;
}
.btn-outline{
 background:#fff;
 border:1px solid var(--border);
 color:var(--text);
}
.buttons{
 display:flex;
 flex-wrap:wrap;
 gap:9px;
}
.toolbar{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:12px;
 flex-wrap:wrap;
}
.notice{
 padding:12px 14px;
 border-radius:8px;
 margin-bottom:16px;
}
.notice-success{
 background:#ecfdf5;
 border:1px solid #bbf7d0;
 color:#166534;
}
.notice-error{
 background:#fef2f2;
 border:1px solid #fecaca;
 color:#991b1b;
}
.notice-info{
 background:#eff6ff;
 border:1px solid #bfdbfe;
 color:#1e40af;
}
.table-wrap{
 overflow-x:auto;
}
table{
 width:100%;
 border-collapse:collapse;
 min-width:1000px;
}
th,td{
 padding:12px 10px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:middle;
}
th{
 background:#f8fafc;
 font-size:13px;
 white-space:nowrap;
}
td{
 font-size:14px;
}
.badge{
 display:inline-block;
 padding:4px 9px;
 border-radius:999px;
 font-size:12px;
 font-weight:700;
}
.badge-success{background:#dcfce7;color:#166534}
.badge-warning{background:#fef3c7;color:#92400e}
.badge-danger{background:#fee2e2;color:#991b1b}
.badge-gray{background:#e2e8f0;color:#475569}
.muted{color:var(--gray)}
.small{font-size:12px}
.right{text-align:right}
.group-card{
 border:1px solid var(--border);
 border-radius:10px;
 padding:18px;
 margin-bottom:18px;
 background:#fff;
}
.group-head{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:12px;
 margin-bottom:14px;
}
.group-title{
 font-size:17px;
 font-weight:800;
}
.question-card{
 border:1px solid var(--border);
 border-radius:9px;
 padding:16px;
 margin:12px 0;
 background:#f8fafc;
}
.question-head{
 display:flex;
 align-items:center;
 justify-content:space-between;
 gap:10px;
 margin-bottom:12px;
}
.drag{
 cursor:grab;
 color:var(--gray);
 user-select:none;
}
.choice-row{
 display:grid;
 grid-template-columns:1fr auto;
 gap:8px;
 margin-bottom:7px;
}
.mapping{
 display:grid;
 grid-template-columns:1fr 2fr;
 gap:10px;
 align-items:center;
 margin-bottom:10px;
}
.stat{
 background:#fff;
 border:1px solid var(--border);
 border-radius:10px;
 padding:18px;
}
.stat .label{
 color:var(--gray);
 font-size:13px;
}
.stat .value{
 font-size:27px;
 font-weight:800;
 margin-top:5px;
}
.answer-card{
 border:1px solid var(--border);
 border-radius:10px;
 padding:18px;
 margin-bottom:14px;
}
.answer-option{
 display:block;
 border:1px solid var(--border);
 border-radius:8px;
 padding:12px;
 margin:8px 0;
 background:#fff;
 cursor:pointer;
}
.answer-option:hover{
 border-color:#93c5fd;
 background:#eff6ff;
}
.answer-option input{
 margin-right:8px;
}
.mobile-answer{
 max-width:720px;
 margin:0 auto;
}
.mobile-answer .card{
 padding:22px;
}
.spinner{
 display:none;
 width:15px;
 height:15px;
 border:2px solid rgba(255,255,255,.5);
 border-top-color:#fff;
 border-radius:50%;
 animation:spin .7s linear infinite;
}
.loading .spinner{display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:900px){
 .grid,.grid-3{
  grid-template-columns:1fr;
 }
 .header-inner{
  align-items:flex-start;
  flex-direction:column;
  padding-top:13px;
  padding-bottom:13px;
 }
}
@media(max-width:600px){
 .container{
  padding:18px 12px 40px;
 }
 .page-title{
  align-items:flex-start;
  flex-direction:column;
 }
 .mapping{
  grid-template-columns:1fr;
 }
 .buttons button,.buttons .btn{
  width:100%;
 }
 .question-head,.group-head{
  align-items:flex-start;
  flex-direction:column;
 }
}
</style>
</head>
<body>
<header class="header">
<div class="header-inner">
<a class="brand" href="index.php?screen=list">
アンケート管理
</a>
<div class="nav">
<a href="index.php?screen=list">アンケート一覧</a>
<a href="index.php?screen=kintone">kintone連携設定</a>
<a href="index.php?screen=mail">メールサーバ設定</a>
</div>
</div>
</header>
<main class="container">
<?php
}

function admin_footer(): void
{
    ?>
</main>
<script>
document.querySelectorAll('form[data-confirm]').forEach(function(form){
    form.addEventListener('submit',function(e){
        var message=form.getAttribute('data-confirm') || '実行しますか？';
        if(!window.confirm(message)){
            e.preventDefault();
        }
    });
});

document.querySelectorAll('form[data-loading]').forEach(function(form){
    form.addEventListener('submit',function(){
        document.querySelectorAll('button').forEach(function(btn){
            btn.disabled=true;
        });
        var submit=form.querySelector('button[type="submit"]');
        if(submit){
            submit.classList.add('loading');
        }
    });
});
</script>
</body>
</html>
<?php
}


/* ============================================================
 * 9. kintone HTTP
 *
 * PHP cURLは使用しない。
 * stream_socket_client + HTTPリクエストを使用。
 * ============================================================
 */

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim($value, "/ \t\n\r\0\x0B");

    if ($value === '') {
        return '';
    }

    if (
        !str_contains($value, '.') &&
        preg_match('/^[A-Za-z0-9-]+$/', $value)
    ) {
        $value .= '.cybozu.com';
    }

    if (
        !preg_match(
            '/^[A-Za-z0-9-]+\.cybozu\.com$/',
            $value
        )
    ) {
        return '';
    }

    return strtolower($value);
}

function parse_proxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (
        !preg_match(
            '/^(.+):([0-9]{1,5})$/',
            $proxy,
            $m
        )
    ) {
        throw new RuntimeException(
            'Proxyは「host:port」形式で入力してください。'
        );
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException(
            'Proxyのポート番号が不正です。'
        );
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

function socket_read_all_http(
    $socket,
    int $timeout
): string {
    stream_set_timeout($socket, $timeout);

    $result = '';

    while (!feof($socket)) {
        $chunk = fread($socket, 8192);

        if ($chunk === false || $chunk === '') {
            break;
        }

        $result .= $chunk;

        if (strlen($result) > 30 * 1024 * 1024) {
            throw new RuntimeException(
                '外部サービスからの応答が大きすぎます。'
            );
        }
    }

    return $result;
}

function http_request_raw(
    string $host,
    string $path,
    string $method,
    array $headers,
    string $body = '',
    ?array $proxy = null,
    bool $verifySsl = false,
    int $connectTimeout = 10,
    int $readTimeout = 30
): array {

    $targetHost = $host;
    $targetPort = 443;

    $socketHost = $host;
    $socketPort = 443;

    if ($proxy !== null) {
        $socketHost = $proxy['host'];
        $socketPort = $proxy['port'];
    }

    $remote =
        'tcp://' .
        $socketHost .
        ':' .
        $socketPort;

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        $connectTimeout,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            '外部サービスへ接続できませんでした。' .
            ' (' . $errstr . ')'
        );
    }

    stream_set_timeout($socket, $readTimeout);

    try {

        /*
         * HTTPSプロキシの場合はCONNECT。
         */
        if ($proxy !== null) {

            $connect =
                "CONNECT " .
                $targetHost .
                ":" .
                $targetPort .
                " HTTP/1.1\r\n" .
                "Host: " .
                $targetHost .
                ":" .
                $targetPort .
                "\r\n" .
                "Connection: keep-alive\r\n\r\n";

            fwrite($socket, $connect);

            $response = '';

            while (($line = fgets($socket)) !== false) {
                $response .= $line;

                if ($line === "\r\n") {
                    break;
                }
            }

            if (
                !preg_match(
                    '#^HTTP/\S+\s+200\b#i',
                    $response
                )
            ) {
                throw new RuntimeException(
                    'Proxy CONNECTに失敗しました。'
                );
            }
        }

        /*
         * TLS
         */
        $cryptoMethod =
            STREAM_CRYPTO_METHOD_TLS_CLIENT;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
                'allow_self_signed' => !$verifySsl,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        stream_context_set_option(
            $socket,
            'ssl',
            'verify_peer',
            $verifySsl
        );

        stream_context_set_option(
            $socket,
            'ssl',
            'verify_peer_name',
            $verifySsl
        );

        stream_context_set_option(
            $socket,
            'ssl',
            'allow_self_signed',
            !$verifySsl
        );

        stream_context_set_option(
            $socket,
            'ssl',
            'SNI_enabled',
            true
        );

        stream_context_set_option(
            $socket,
            'ssl',
            'peer_name',
            $host
        );

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            $cryptoMethod
        );

        if ($crypto !== true) {
            throw new RuntimeException(
                'TLS接続を確立できませんでした。'
            );
        }

        /*
         * リクエスト。
         */
        $request =
            $method .
            ' ' .
            $path .
            " HTTP/1.1\r\n" .
            "Host: " .
            $host .
            "\r\n";

        foreach ($headers as $name => $value) {
            $request .=
                $name .
                ': ' .
                $value .
                "\r\n";
        }

        $request .=
            "Connection: close\r\n" .
            "Content-Length: " .
            strlen($body) .
            "\r\n\r\n" .
            $body;

        fwrite($socket, $request);

        $raw = socket_read_all_http(
            $socket,
            $readTimeout
        );

        fclose($socket);

        $parts = preg_split(
            "/\r\n\r\n/",
            $raw,
            2
        );

        $head = $parts[0] ?? '';
        $responseBody = $parts[1] ?? '';

        $status = 0;

        if (
            preg_match(
                '#^HTTP/\S+\s+(\d{3})#m',
                $head,
                $m
            )
        ) {
            $status = (int)$m[1];
        }

        /*
         * chunked。
         */
        if (
            preg_match(
                '/^Transfer-Encoding:\s*chunked/im',
                $head
            )
        ) {
            $responseBody =
                decode_chunked($responseBody);
        }

        return [
            'status' => $status,
            'headers' => $head,
            'body' => $responseBody,
        ];

    } catch (Throwable $e) {
        @fclose($socket);
        throw $e;
    }
}

function decode_chunked(string $body): string
{
    $out = '';
    $offset = 0;
    $length = strlen($body);

    while ($offset < $length) {

        $pos = strpos(
            $body,
            "\r\n",
            $offset
        );

        if ($pos === false) {
            break;
        }

        $sizeHex = trim(
            substr(
                $body,
                $offset,
                $pos - $offset
            )
        );

        $size = hexdec($sizeHex);

        if ($size === 0) {
            break;
        }

        $offset = $pos + 2;

        $out .= substr(
            $body,
            $offset,
            $size
        );

        $offset += $size + 2;
    }

    return $out;
}

function kintone_request(
    string $method,
    string $path,
    ?array $payload = null
): array {

    $settings = load_settings();
    $k = $settings['kintone'];

    $host =
        normalize_kintone_subdomain(
            (string)($k['subdomain'] ?? '')
        );

    $appId =
        (string)($k['app_id'] ?? '');

    $username =
        (string)($k['username'] ?? '');

    $password =
        (string)($k['password'] ?? '');

    if (
        $host === '' ||
        $appId === '' ||
        $username === '' ||
        $password === ''
    ) {
        throw new RuntimeException(
            'kintone設定が不足しています。'
        );
    }

    $authorization = base64_encode(
        $username . ':' . $password
    );

    $headers = [
        'X-Cybozu-Authorization' => $authorization,
        'Accept' => 'application/json',
    ];

    $body = '';

    if ($payload !== null) {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($body === false) {
            throw new RuntimeException(
                'kintoneリクエストを生成できません。'
            );
        }

        $headers['Content-Type'] =
            'application/json';
    }

    $proxy =
        parse_proxy(
            (string)($k['proxy'] ?? '')
        );

    $response = http_request_raw(
        $host,
        $path,
        $method,
        $headers,
        $body,
        $proxy,
        !empty($k['verify_ssl']),
        10,
        30
    );

    $json = null;

    if ($response['body'] !== '') {
        $json = json_decode(
            $response['body'],
            true
        );
    }

    if (
        $response['status'] < 200 ||
        $response['status'] >= 300
    ) {
        $detail = '';

        if (is_array($json)) {
            $detail =
                (string)(
                    $json['message'] ??
                    $json['code'] ??
                    ''
                );
        }

        if ($detail === '') {
            $detail =
                'HTTPステータス ' .
                $response['status'];
        }

        throw new RuntimeException(
            'kintone通信に失敗しました: ' .
            $detail
        );
    }

    return [
        'status' => $response['status'],
        'json' => is_array($json) ? $json : [],
    ];
}


/* ============================================================
 * 10. SMTP
 *
 * PHP mail()は使用しない。
 * ============================================================
 */

function smtp_read($socket): string
{
    $response = '';

    while (($line = fgets($socket, 8192)) !== false) {
        $response .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    return $response;
}

function smtp_code(string $response): int
{
    if (
        preg_match(
            '/^(\d{3})/m',
            $response,
            $m
        )
    ) {
        return (int)$m[1];
    }

    return 0;
}

function smtp_expect(
    $socket,
    array $allowed
): string {
    $response = smtp_read($socket);
    $code = smtp_code($response);

    if (!in_array($code, $allowed, true)) {
        throw new RuntimeException(
            'SMTPサーバー応答エラー: ' .
            $code
        );
    }

    return $response;
}

function smtp_command(
    $socket,
    string $command,
    array $allowed
): string {
    fwrite(
        $socket,
        $command . "\r\n"
    );

    return smtp_expect(
        $socket,
        $allowed
    );
}

function smtp_open(): array
{
    $settings = load_settings();
    $m = $settings['mail'];

    $server =
        trim((string)($m['server'] ?? ''));

    $port =
        (int)($m['port'] ?? 587);

    $encryption =
        strtolower(
            (string)($m['encryption'] ?? 'tls')
        );

    if ($server === '') {
        throw new RuntimeException(
            'SMTPサーバが設定されていません。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException(
            'SMTPポートが不正です。'
        );
    }

    $host = $server;
    $transport = 'tcp';

    if ($encryption === 'ssl') {
        $transport = 'ssl';
    }

    $socket = @stream_socket_client(
        $transport . '://' . $host . ':' . $port,
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できませんでした。'
        );
    }

    stream_set_timeout($socket, 30);

    try {

        smtp_expect(
            $socket,
            [220]
        );

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );

        if ($encryption === 'tls') {
            smtp_command(
                $socket,
                'STARTTLS',
                [220]
            );

            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTP TLS接続に失敗しました。'
                );
            }

            smtp_command(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (!empty($m['auth'])) {

            $username =
                (string)($m['username'] ?? '');

            $password =
                (string)($m['password'] ?? '');

            if ($username === '' || $password === '') {
                throw new RuntimeException(
                    'SMTP認証情報が不足しています。'
                );
            }

            /*
             * AUTH LOGIN
             */
            smtp_command(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            smtp_command(
                $socket,
                base64_encode($username),
                [334]
            );

            smtp_command(
                $socket,
                base64_encode($password),
                [235]
            );
        }

        return [
            'socket' => $socket,
            'settings' => $m,
        ];

    } catch (Throwable $e) {
        @fclose($socket);
        throw $e;
    }
}

function smtp_send(
    string $to,
    string $subject,
    string $body,
    ?string $replyTo = null
): void {

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            '送信先メールアドレスが不正です。'
        );
    }

    $smtp = smtp_open();
    $socket = $smtp['socket'];
    $m = $smtp['settings'];

    try {

        $from =
            (string)($m['from_email'] ?? '');

        $fromName =
            (string)($m['from_name'] ?? '');

        if (
            !filter_var(
                $from,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                '送信元メールアドレスが不正です。'
            );
        }

        smtp_command(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $encodedSubject =
            '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $encodedName =
            '=?UTF-8?B?' .
            base64_encode($fromName) .
            '?=';

        $headers = [
            'From: ' .
            ($fromName !== ''
                ? $encodedName . ' <' . $from . '>'
                : $from),
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $reply =
            $replyTo !== null && $replyTo !== ''
                ? $replyTo
                : (string)($m['reply_to'] ?? '');

        if (
            $reply !== '' &&
            filter_var(
                $reply,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $headers[] =
                'Reply-To: ' . $reply;
        }

        /*
         * SMTP dot stuffing。
         */
        $body = preg_replace(
            '/^\./m',
            '..',
            $body
        );

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            $body .
            "\r\n.\r\n";

        fwrite($socket, $message);

        smtp_expect(
            $socket,
            [250]
        );

        @fwrite(
            $socket,
            "QUIT\r\n"
        );

        @fclose($socket);

    } catch (Throwable $e) {
        @fclose($socket);
        throw $e;
    }
}


/* ============================================================
 * 11. POST処理
 * ============================================================
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {

    verify_csrf();

    $action =
        trim((string)($_POST['action'] ?? ''));

    try {

        switch ($action) {

            /* ------------------------------------------------
             * アンケート保存
             * ------------------------------------------------
             */
            case 'save_survey':

                $id =
                    trim((string)($_POST['id'] ?? ''));

                $surveys = load_surveys();

                $existing = null;

                if ($id !== '') {
                    $existing = find_survey($id);

                    if ($existing === null) {
                        throw new RuntimeException(
                            '対象アンケートが存在しません。'
                        );
                    }
                }

                $survey = $existing ?? new_survey();

                $survey['title'] =
                    trim((string)($_POST['title'] ?? ''));

                $survey['description'] =
                    trim((string)($_POST['description'] ?? ''));

                $survey['startAt'] =
                    trim((string)($_POST['startAt'] ?? ''));

                $survey['endAt'] =
                    trim((string)($_POST['endAt'] ?? ''));

                $survey['numbering'] =
                    ($_POST['numbering'] ?? 'global') === 'group'
                        ? 'group'
                        : 'global';

                if ($survey['title'] === '') {
                    throw new RuntimeException(
                        'アンケートタイトルを入力してください。'
                    );
                }

                if (mb_strlen($survey['title']) > 200) {
                    throw new RuntimeException(
                        'アンケートタイトルは200文字以内で入力してください。'
                    );
                }

                if ($survey['startAt'] === '' ||
                    strtotime($survey['startAt']) === false) {
                    throw new RuntimeException(
                        '開始日時が不正です。'
                    );
                }

                if ($survey['endAt'] === '' ||
                    strtotime($survey['endAt']) === false) {
                    throw new RuntimeException(
                        '終了日時が不正です。'
                    );
                }

                if (
                    strtotime($survey['endAt']) <=
                    strtotime($survey['startAt'])
                ) {
                    throw new RuntimeException(
                        '終了日時は開始日時より後にしてください。'
                    );
                }

                $survey['updatedAt'] = now_iso();

                $groupsJson =
                    (string)($_POST['groups_json'] ?? '');

                $groups =
                    json_decode(
                        $groupsJson,
                        true
                    );

                if (!is_array($groups)) {
                    throw new RuntimeException(
                        '質問データが不正です。'
                    );
                }

                $survey['groups'] = $groups;

                /*
                 * 既存編集時は状態を維持。
                 * 新規はdraft。
                 */
                if ($existing === null) {
                    $survey['status'] = 'draft';
                }

                $survey =
                    normalize_survey($survey);

                if ($existing === null) {
                    $surveys[] = $survey;
                } else {
                    foreach ($surveys as $i => $s) {
                        if (($s['id'] ?? '') === $id) {
                            $surveys[$i] = $survey;
                            break;
                        }
                    }
                }

                save_surveys($surveys);

                flash(
                    'success',
                    'アンケートを保存しました。'
                );

                redirect_screen('list');

            /* ------------------------------------------------
             * 状態変更
             * ------------------------------------------------
             */
            case 'change_status':

                $id =
                    trim((string)($_POST['id'] ?? ''));

                $to =
                    trim((string)($_POST['status'] ?? ''));

                $surveys = load_surveys();

                foreach ($surveys as &$survey) {

                    if (($survey['id'] ?? '') !== $id) {
                        continue;
                    }

                    $survey =
                        apply_auto_status($survey);

                    $from =
                        (string)$survey['status'];

                    if (!allowed_status_transition(
                        $from,
                        $to
                    )) {
                        throw new RuntimeException(
                            'この状態変更は許可されていません。'
                        );
                    }

                    $survey['status'] = $to;
                    $survey['updatedAt'] = now_iso();

                    break;
                }

                unset($survey);

                save_surveys($surveys);

                flash(
                    'success',
                    '状態を変更しました。'
                );

                redirect_screen(
                    'edit',
                    ['id' => $id]
                );

            /* ------------------------------------------------
             * 複製
             * ------------------------------------------------
             */
            case 'duplicate_survey':

                $id =
                    trim((string)($_POST['id'] ?? ''));

                $survey = find_survey($id);

                if ($survey === null) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                $copy = $survey;

                $copy['id'] =
                    'survey_' . bin2hex(random_bytes(6));

                $copy['title'] =
                    $survey['title'] . '（複製）';

                $copy['status'] = 'draft';
                $copy['createdAt'] = now_iso();
                $copy['updatedAt'] = now_iso();

                /*
                 * 質問・グループIDを再生成。
                 */
                foreach ($copy['groups'] as &$group) {
                    $group['id'] =
                        'g_' . bin2hex(random_bytes(6));

                    foreach ($group['questions'] as &$question) {
                        $oldQuestionId =
                            $question['id'] ?? '';

                        $question['id'] =
                            'q_' . bin2hex(random_bytes(6));

                        foreach ($question['choices'] as &$choice) {
                            $choice['id'] =
                                'c_' . bin2hex(random_bytes(4));
                        }

                        unset($choice);

                        $question['branch'] = [];
                    }

                    unset($question);
                }

                unset($group);

                renumber_questions($copy);

                $surveys = load_surveys();
                $surveys[] = $copy;

                save_surveys($surveys);

                flash(
                    'success',
                    'アンケートを下書きとして複製しました。'
                );

                redirect_screen('list');

            /* ------------------------------------------------
             * 削除
             * ------------------------------------------------
             */
            case 'delete_survey':

                $id =
                    trim((string)($_POST['id'] ?? ''));

                $surveys =
                    array_values(
                        array_filter(
                            load_surveys(),
                            fn(array $s): bool =>
                                ($s['id'] ?? '') !== $id
                        )
                    );

                save_surveys($surveys);

                flash(
                    'success',
                    'アンケートを削除しました。'
                );

                redirect_screen('list');

            /* ------------------------------------------------
             * kintone設定保存
             *
             * 重要:
             * passwordが空でも既存値を維持。
             * 保存後は一覧へ戻さずscreen=kintone。
             * ------------------------------------------------
             */
            case 'save_kintone':

                $settings = load_settings();

                $subdomain =
                    normalize_kintone_subdomain(
                        trim(
                            (string)(
                                $_POST['subdomain'] ?? ''
                            )
                        )
                    );

                $appId =
                    trim(
                        (string)(
                            $_POST['app_id'] ?? ''
                        )
                    );

                $username =
                    trim(
                        (string)(
                            $_POST['username'] ?? ''
                        )
                    );

                $password =
                    (string)(
                        $_POST['password'] ?? ''
                    );

                $proxy =
                    trim(
                        (string)(
                            $_POST['proxy'] ?? ''
                        )
                    );

                if ($subdomain === '') {
                    throw new RuntimeException(
                        'kintoneサブドメインが不正です。'
                    );
                }

                if (
                    !ctype_digit($appId) ||
                    (int)$appId < 1
                ) {
                    throw new RuntimeException(
                        '顧客管理アプリIDが不正です。'
                    );
                }

                if ($username === '') {
                    throw new RuntimeException(
                        'ログイン名を入力してください。'
                    );
                }

                /*
                 * パスワードはブラウザへ戻さない。
                 * 空欄なら既存値を維持。
                 */
                if ($password === '') {
                    $password =
                        (string)(
                            $settings['kintone']['password']
                            ?? ''
                        );
                }

                if ($password === '') {
                    throw new RuntimeException(
                        'パスワードを入力してください。'
                    );
                }

                /*
                 * Proxy形式検証。
                 */
                if ($proxy !== '') {
                    parse_proxy($proxy);
                }

                $settings['kintone']['subdomain'] =
                    $subdomain;

                $settings['kintone']['app_id'] =
                    $appId;

                $settings['kintone']['username'] =
                    $username;

                $settings['kintone']['password'] =
                    $password;

                $settings['kintone']['proxy'] =
                    $proxy;

                $settings['kintone']['verify_ssl'] =
                    isset($_POST['verify_ssl']);

                /*
                 * 保存しただけでは接続確認済みとしない。
                 */
                $settings['kintone']['connection_status'] =
                    '未設定';

                save_settings($settings);

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                /*
                 * 一覧へ戻さない。
                 */
                redirect_screen('kintone');

            /* ------------------------------------------------
             * kintone接続テスト
             * ------------------------------------------------
             */
            case 'test_kintone':

                /*
                 * 接続確認と保存を分離。
                 */
                $response =
                    kintone_request(
                        'GET',
                        '/k/v1/app.json?id=' .
                        rawurlencode(
                            (string)(
                                load_settings()
                                ['kintone']['app_id']
                                ?? ''
                            )
                        )
                    );

                $settings = load_settings();

                $settings['kintone']['connection_status'] =
                    '接続確認済み';

                save_settings($settings);

                flash(
                    'success',
                    'kintoneへの接続に成功しました。'
                );

                redirect_screen('kintone');

            /* ------------------------------------------------
             * kintone項目一覧取得
             * ------------------------------------------------
             */
            case 'refresh_kintone_fields':

                $settings = load_settings();

                $appId =
                    (string)(
                        $settings['kintone']['app_id']
                        ?? ''
                    );

                $response =
                    kintone_request(
                        'GET',
                        '/k/v1/app/form/fields.json?app=' .
                        rawurlencode($appId)
                    );

                $fields =
                    $response['json']['properties']
                    ?? [];

                $settings['kintone']['fields'] =
                    is_array($fields)
                        ? $fields
                        : [];

                save_settings($settings);

                flash(
                    'success',
                    'kintoneの項目一覧を再取得しました。'
                );

                redirect_screen('kintone');

            /* ------------------------------------------------
             * kintone顧客同期
             * ------------------------------------------------
             */
            case 'sync_kintone':

                $settings = load_settings();

                $appId =
                    (string)(
                        $settings['kintone']['app_id']
                        ?? ''
                    );

                $response =
                    kintone_request(
                        'GET',
                        '/k/v1/records.json?app=' .
                        rawurlencode($appId) .
                        '&totalCount=true'
                    );

                $records =
                    $response['json']['records']
                    ?? [];

                $mapping =
                    $settings['kintone']['mapping']
                    ?? [];

                $customers = [];

                foreach ($records as $record) {

                    $getValue = function(
                        string $code
                    ) use ($record): string {

                        $value =
                            $record[$code]['value']
                            ?? '';

                        if (is_array($value)) {
                            return implode(
                                ', ',
                                array_map(
                                    'strval',
                                    $value
                                )
                            );
                        }

                        return (string)$value;
                    };

                    /*
                     * マッピングされたフィールドコード。
                     */
                    $orgCodes =
                        $mapping['organization']
                        ?? [];

                    $addressCodes =
                        $mapping['address']
                        ?? [];

                    $organization = '';

                    foreach ($orgCodes as $code) {
                        $v = $getValue((string)$code);

                        if ($v !== '') {
                            $organization = $v;
                            break;
                        }
                    }

                    $addressParts = [];

                    foreach ($addressCodes as $code) {
                        $v = $getValue((string)$code);

                        if ($v !== '') {
                            $addressParts[] = $v;
                        }
                    }

                    $customers[] = [
                        'id' =>
                            'customer_' .
                            bin2hex(random_bytes(6)),
                        'kintoneId' =>
                            $getValue('$id'),
                        'organization' =>
                            $organization,
                        'name' =>
                            $getValue(
                                (string)(
                                    $mapping['name'] ?? ''
                                )
                            ),
                        'email' =>
                            $getValue(
                                (string)(
                                    $mapping['email'] ?? ''
                                )
                            ),
                        'department' =>
                            $getValue(
                                (string)(
                                    $mapping['department'] ?? ''
                                )
                            ),
                        'phone' =>
                            $getValue(
                                (string)(
                                    $mapping['phone'] ?? ''
                                )
                            ),
                        'address' =>
                            implode(
                                ' ',
                                $addressParts
                            ),
                        'updatedAt' => now_iso(),
                    ];
                }

                save_customers($customers);

                flash(
                    'success',
                    count($customers) .
                    '件の顧客情報を同期しました。'
                );

                redirect_screen('kintone');

            /* ------------------------------------------------
             * kintoneマッピング保存
             * ------------------------------------------------
             */
            case 'save_kintone_mapping':

                $settings = load_settings();

                $settings['kintone']['mapping'] = [
                    'organization' =>
                        array_values(
                            array_filter(
                                $_POST['organization'] ?? [],
                                'is_string'
                            )
                        ),
                    'name' =>
                        trim(
                            (string)(
                                $_POST['name_field'] ?? ''
                            )
                        ),
                    'email' =>
                        trim(
                            (string)(
                                $_POST['email_field'] ?? ''
                            )
                        ),
                    'department' =>
                        trim(
                            (string)(
                                $_POST['department_field'] ?? ''
                            )
                        ),
                    'phone' =>
                        trim(
                            (string)(
                                $_POST['phone_field'] ?? ''
                            )
                        ),
                    'address' =>
                        array_values(
                            array_filter(
                                $_POST['address_fields'] ?? [],
                                'is_string'
                            )
                        ),
                ];

                save_settings($settings);

                flash(
                    'success',
                    'kintone項目マッピングを保存しました。'
                );

                redirect_screen('kintone');

            /* ------------------------------------------------
             * メール設定保存
             * ------------------------------------------------
             */
            case 'save_mail':

                $settings = load_settings();

                $server =
                    trim(
                        (string)(
                            $_POST['server'] ?? ''
                        )
                    );

                $port =
                    (int)(
                        $_POST['port'] ?? 0
                    );

                $encryption =
                    strtolower(
                        trim(
                            (string)(
                                $_POST['encryption']
                                ?? 'none'
                            )
                        )
                    );

                $auth =
                    isset($_POST['auth']);

                $username =
                    trim(
                        (string)(
                            $_POST['username'] ?? ''
                        )
                    );

                $password =
                    (string)(
                        $_POST['password'] ?? ''
                    );

                $fromEmail =
                    trim(
                        (string)(
                            $_POST['from_email'] ?? ''
                        )
                    );

                $fromName =
                    trim(
                        (string)(
                            $_POST['from_name'] ?? ''
                        )
                    );

                $replyTo =
                    trim(
                        (string)(
                            $_POST['reply_to'] ?? ''
                        )
                    );

                if ($server === '') {
                    throw new RuntimeException(
                        'SMTPサーバを入力してください。'
                    );
                }

                if ($port < 1 || $port > 65535) {
                    throw new RuntimeException(
                        'SMTPポートが不正です。'
                    );
                }

                if (
                    !in_array(
                        $encryption,
                        ['ssl','tls','none'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        '暗号化方式が不正です。'
                    );
                }

                if (
                    !filter_var(
                        $fromEmail,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    throw new RuntimeException(
                        '送信元メールアドレスが不正です。'
                    );
                }

                if (
                    $replyTo !== '' &&
                    !filter_var(
                        $replyTo,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    throw new RuntimeException(
                        '返信先メールアドレスが不正です。'
                    );
                }

                /*
                 * 空パスワードは既存値を維持。
                 */
                if ($password === '') {
                    $password =
                        (string)(
                            $settings['mail']['password']
                            ?? ''
                        );
                }

                if (
                    $auth &&
                    $username === ''
                ) {
                    throw new RuntimeException(
                        'SMTPユーザー名を入力してください。'
                    );
                }

                if (
                    $auth &&
                    $password === ''
                ) {
                    throw new RuntimeException(
                        'SMTPパスワードを入力してください。'
                    );
                }

                $settings['mail'] = [
                    'server' =>
                        $server,
                    'port' =>
                        $port,
                    'encryption' =>
                        $encryption,
                    'auth' =>
                        $auth,
                    'username' =>
                        $username,
                    'password' =>
                        $password,
                    'from_email' =>
                        $fromEmail,
                    'from_name' =>
                        $fromName,
                    'reply_to' =>
                        $replyTo,
                    'connection_status' =>
                        '未設定',
                ];

                save_settings($settings);

                flash(
                    'success',
                    'メールサーバ設定を保存しました。'
                );

                redirect_screen('mail');

            /* ------------------------------------------------
             * SMTP接続テスト
             * ------------------------------------------------
             */
            case 'test_mail':

                $smtp = smtp_open();

                @fwrite(
                    $smtp['socket'],
                    "QUIT\r\n"
                );

                @fclose(
                    $smtp['socket']
                );

                $settings = load_settings();

                $settings['mail']['connection_status'] =
                    '接続確認済み';

                save_settings($settings);

                flash(
                    'success',
                    'SMTPサーバへの接続に成功しました。'
                );

                redirect_screen('mail');

            /* ------------------------------------------------
             * テストメール
             * ------------------------------------------------
             */
            case 'send_test_mail':

                $settings = load_settings();

                $to =
                    trim(
                        (string)(
                            $_POST['test_email'] ?? ''
                        )
                    );

                if (
                    !filter_var(
                        $to,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    throw new RuntimeException(
                        'テストメール送信先を入力してください。'
                    );
                }

                smtp_send(
                    $to,
                    'アンケートアプリ テストメール',
                    "アンケートアプリからのテストメールです。\r\n" .
                    "送信日時: " . now_iso(),
                    $settings['mail']['reply_to'] ?? null
                );

                flash(
                    'success',
                    'テストメールを送信しました。'
                );

                redirect_screen('mail');

            /* ------------------------------------------------
             * 顧客への一括送信
             * ------------------------------------------------
             */
            case 'send_bulk_mail':

                $surveyId =
                    trim(
                        (string)(
                            $_POST['survey_id'] ?? ''
                        )
                    );

                $survey =
                    find_survey($surveyId);

                if ($survey === null) {
                    throw new RuntimeException(
                        '対象アンケートが存在しません。'
                    );
                }

                $customerIds =
                    array_values(
                        array_filter(
                            $_POST['customer_ids'] ?? [],
                            'is_string'
                        )
                    );

                if (!$customerIds) {
                    throw new RuntimeException(
                        '送信対象の顧客を選択してください。'
                    );
                }

                $subject =
                    trim(
                        (string)(
                            $_POST['subject'] ?? ''
                        )
                    );

                $body =
                    (string)(
                        $_POST['body'] ?? ''
                    );

                if ($subject === '' || $body === '') {
                    throw new RuntimeException(
                        'メール件名と本文を入力してください。'
                    );
                }

                $customers =
                    load_customers();

                $history =
                    load_send_history();

                $successCount = 0;
                $failureCount = 0;

                foreach ($customers as $customer) {

                    if (
                        !in_array(
                            (string)($customer['id'] ?? ''),
                            $customerIds,
                            true
                        )
                    ) {
                        continue;
                    }

                    $customerName =
                        (string)(
                            $customer['name'] ?? ''
                        );

                    $url =
                        app_public_url(
                            'answer',
                            ['id' => $surveyId]
                        );

                    $mailSubject =
                        str_replace(
                            ['{顧客名}','{アンケートURL}'],
                            [$customerName,$url],
                            $subject
                        );

                    $mailBody =
                        str_replace(
                            ['{顧客名}','{アンケートURL}'],
                            [$customerName,$url],
                            $body
                        );

                    $to =
                        (string)(
                            $customer['email'] ?? ''
                        );

                    $item = [
                        'id' =>
                            'send_' .
                            bin2hex(random_bytes(6)),
                        'surveyId' =>
                            $surveyId,
                        'customerId' =>
                            $customer['id'],
                        'customerName' =>
                            $customerName,
                        'email' =>
                            $to,
                        'type' =>
                            (string)(
                                $_POST['send_type'] ?? 'initial'
                            ),
                        'subject' =>
                            $mailSubject,
                        'sentAt' =>
                            now_iso(),
                        'status' =>
                            'failed',
                        'error' =>
                            '',
                    ];

                    try {

                        smtp_send(
                            $to,
                            $mailSubject,
                            $mailBody
                        );

                        $item['status'] =
                            'sent';

                        $successCount++;

                    } catch (Throwable $e) {

                        /*
                         * パスワードや認証ヘッダーを
                         * 履歴へ保存しない。
                         */
                        $item['error'] =
                            $e->getMessage();

                        $failureCount++;
                    }

                    $history[] = $item;
                }

                save_send_history($history);

                flash(
                    $failureCount > 0
                        ? 'error'
                        : 'success',
                    '送信完了: 成功 ' .
                    $successCount .
                    '件 / 失敗 ' .
                    $failureCount .
                    '件'
                );

                /*
                 * 別画面へ遷移しない。
                 */
                redirect_screen(
                    'send',
                    ['id' => $surveyId]
                );

            /* ------------------------------------------------
             * 回答確認
             * ------------------------------------------------
             */
            case 'answer_confirm':

                $surveyId =
                    trim(
                        (string)(
                            $_POST['survey_id'] ?? ''
                        )
                    );

                $survey =
                    find_survey($surveyId);

                if ($survey === null) {
                    throw new RuntimeException(
                        'アンケートが存在しません。'
                    );
                }

                $answersPosted =
                    $_POST['answer'] ?? [];

                if (!is_array($answersPosted)) {
                    $answersPosted = [];
                }

                $errors = [];

                foreach ($survey['groups'] as $group) {
                    foreach ($group['questions'] as $question) {

                        if (
                            empty($question['required'])
                        ) {
                            continue;
                        }

                        $qid =
                            (string)$question['id'];

                        $value =
                            $answersPosted[$qid]
                            ?? null;

                        $empty = false;

                        if (is_array($value)) {
                            $empty =
                                count(
                                    array_filter(
                                        $value,
                                        fn($v) =>
                                            trim((string)$v) !== ''
                                    )
                                ) === 0;
                        } else {
                            $empty =
                                trim((string)$value) === '';
                        }

                        if ($empty) {
                            $errors[] =
                                ($question['number'] ?? '') .
                                '「' .
                                ($question['text'] ?? '') .
                                '」は必須です。';
                        }
                    }
                }

                if ($errors) {
                    $_SESSION['answer_form'] =
                        $answersPosted;

                    flash(
                        'error',
                        implode("\n", $errors)
                    );

                    redirect_screen(
                        'answer',
                        ['id' => $surveyId]
                    );
                }

                $_SESSION['answer_form'] =
                    $answersPosted;

                redirect_screen(
                    'confirm',
                    ['id' => $surveyId]
                );

            /* ------------------------------------------------
             * 回答送信
             * ------------------------------------------------
             */
            case 'submit_answer':

                $surveyId =
                    trim(
                        (string)(
                            $_POST['survey_id'] ?? ''
                        )
                    );

                $survey =
                    find_survey($surveyId);

                if ($survey === null) {
                    throw new RuntimeException(
                        'アンケートが存在しません。'
                    );
                }

                $answerData =
                    $_SESSION['answer_form']
                    ?? [];

                $answers =
                    load_answers();

                $answers[] = [
                    'id' =>
                        'answer_' .
                        bin2hex(random_bytes(6)),
                    'surveyId' =>
                        $surveyId,
                    'answers' =>
                        $answerData,
                    'registeredCustomer' =>
                        null,
                    'createdAt' =>
                        now_iso(),
                ];

                save_answers($answers);

                unset(
                    $_SESSION['answer_form']
                );

                redirect_screen(
                    'complete',
                    ['id' => $surveyId]
                );

            /* ------------------------------------------------
             * 不明なPOST
             * ------------------------------------------------
             */
            default:

                throw new RuntimeException(
                    '指定された操作は存在しません。'
                );
        }

    } catch (Throwable $e) {

        /*
         * 画面には機密情報を出さない。
         */
        http_response_code(400);

        render_error(
            '処理に失敗しました',
            $e->getMessage()
        );

        exit;
    }
}


/* ============================================================
 * 12. 画面
 * ============================================================
 */

$screen =
    trim(
        (string)(
            $_GET['screen'] ?? 'list'
        )
    );

$id =
    trim(
        (string)(
            $_GET['id'] ?? ''
        )
    );

$flash = get_flash();


/* ============================================================
 * 一覧
 * ============================================================
 */

if ($screen === 'list') {

    $surveys = load_surveys();

    foreach ($surveys as &$survey) {
        $survey =
            apply_auto_status($survey);
    }

    unset($survey);

    /*
     * 自動終了状態を永続化。
     */
    save_surveys($surveys);

    $keyword =
        trim(
            (string)(
                $_GET['q'] ?? ''
            )
        );

    $filter =
        (string)(
            $_GET['filter'] ?? 'all'
        );

    $sort =
        (string)(
            $_GET['sort'] ?? 'updated_desc'
        );

    $filtered = [];

    $answers =
        load_answers();

    foreach ($surveys as $survey) {

        if (
            $keyword !== '' &&
            mb_stripos(
                (string)$survey['title'],
                $keyword
            ) === false
        ) {
            continue;
        }

        $status =
            (string)$survey['status'];

        if (
            $filter !== 'all' &&
            $status !== $filter
        ) {
            continue;
        }

        $filtered[] = $survey;
    }

    usort(
        $filtered,
        function(array $a, array $b) use ($sort): int {

            $answers = load_answers();

            $countA = 0;
            $countB = 0;

            foreach ($answers as $answer) {
                if (($answer['surveyId'] ?? '') === ($a['id'] ?? '')) {
                    $countA++;
                }

                if (($answer['surveyId'] ?? '') === ($b['id'] ?? '')) {
                    $countB++;
                }
            }

            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)$a['updatedAt'],
                        (string)$b['updatedAt']
                    ),

                'answers_desc' =>
                    $countB <=> $countA,

                'answers_asc' =>
                    $countA <=> $countB,

                'start_desc' =>
                    strcmp(
                        (string)$b['startAt'],
                        (string)$a['startAt']
                    ),

                'start_asc' =>
                    strcmp(
                        (string)$a['startAt'],
                        (string)$b['startAt']
                    ),

                default =>
                    strcmp(
                        (string)$b['updatedAt'],
                        (string)$a['updatedAt']
                    ),
            };
        }
    );

    admin_header('アンケート一覧');

    if ($flash): ?>
        <div class="notice notice-<?= h($flash['type']) ?>">
            <?= nl2br(h($flash['message'])) ?>
        </div>
    <?php endif; ?>

    <div class="page-title">
        <h1>アンケート一覧</h1>

        <a class="btn btn-primary"
           href="index.php?screen=edit">
            ＋ 新規作成
        </a>
    </div>

    <div class="card">

        <form method="get">
            <input type="hidden"
                   name="screen"
                   value="list">

            <div class="grid">

                <div class="field">
                    <label>タイトル検索</label>
                    <input type="text"
                           name="q"
                           value="<?= h($keyword) ?>"
                           placeholder="タイトルを検索">
                </div>

                <div class="field">
                    <label>ステータス</label>
                    <select name="filter">
                        <option value="all"
                            <?= $filter === 'all' ? 'selected' : '' ?>>
                            すべて
                        </option>
                        <option value="published"
                            <?= $filter === 'published' ? 'selected' : '' ?>>
                            公開中
                        </option>
                        <option value="draft"
                            <?= $filter === 'draft' ? 'selected' : '' ?>>
                            下書き
                        </option>
                        <option value="stopped"
                            <?= $filter === 'stopped' ? 'selected' : '' ?>>
                            停止
                        </option>
                        <option value="ended"
                            <?= $filter === 'ended' ? 'selected' : '' ?>>
                            終了
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>ソート</label>
                    <select name="sort">
                        <option value="updated_desc"
                            <?= $sort === 'updated_desc' ? 'selected' : '' ?>>
                            更新日：新しい順
                        </option>
                        <option value="updated_asc"
                            <?= $sort === 'updated_asc' ? 'selected' : '' ?>>
                            更新日：古い順
                        </option>
                        <option value="answers_desc"
                            <?= $sort === 'answers_desc' ? 'selected' : '' ?>>
                            回答数：多い順
                        </option>
                        <option value="answers_asc"
                            <?= $sort === 'answers_asc' ? 'selected' : '' ?>>
                            回答数：少ない順
                        </option>
                        <option value="start_desc"
                            <?= $sort === 'start_desc' ? 'selected' : '' ?>>
                            開始日：新しい順
                        </option>
                        <option value="start_asc"
                            <?= $sort === 'start_asc' ? 'selected' : '' ?>>
                            開始日：古い順
                        </option>
                    </select>
                </div>

                <div class="field"
                     style="display:flex;align-items:end">
                    <button class="btn btn-primary"
                            type="submit">
                        検索
                    </button>
                </div>

            </div>
        </form>

    </div>

    <div class="card">

        <div class="table-wrap">

            <table>

                <thead>
                <tr>
                    <th>タイトル</th>
                    <th>作成日</th>
                    <th>更新日</th>
                    <th>アンケート期間</th>
                    <th>ステータス</th>
                    <th>回答数</th>
                    <th>操作</th>
                </tr>
                </thead>

                <tbody>

                <?php if (!$filtered): ?>

                    <tr>
                        <td colspan="7"
                            class="muted">
                            アンケートがありません。
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($filtered as $survey): ?>

                        <?php
                        $answerCount = 0;

                        foreach ($answers as $answer) {
                            if (
                                ($answer['surveyId'] ?? '') ===
                                ($survey['id'] ?? '')
                            ) {
                                $answerCount++;
                            }
                        }

                        $status =
                            (string)$survey['status'];
                        ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= h($survey['title']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= h($survey['createdAt']) ?>
                            </td>

                            <td>
                                <?= h($survey['updatedAt']) ?>
                            </td>

                            <td>
                                <?= h($survey['startAt']) ?>
                                <br>
                                ～
                                <br>
                                <?= h($survey['endAt']) ?>
                            </td>

                            <td>
                                <span class="badge badge-<?= h(
                                    status_class($status)
                                ) ?>">
                                    <?= h(
                                        status_label($status)
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <?= h($answerCount) ?>
                            </td>

                            <td>

                                <div class="buttons">

                                    <a class="btn btn-outline"
                                       href="index.php?screen=edit&id=<?= rawurlencode($survey['id']) ?>">
                                        確認・編集
                                    </a>

                                    <a class="btn btn-outline"
                                       href="index.php?screen=analytics&id=<?= rawurlencode($survey['id']) ?>">
                                        集計
                                    </a>

                                    <a class="btn btn-outline"
                                       href="index.php?screen=send&id=<?= rawurlencode($survey['id']) ?>">
                                        送信
                                    </a>

                                    <form method="post"
                                          data-confirm="このアンケートを複製しますか？">
                                        <input type="hidden"
                                               name="csrf"
                                               value="<?= h(csrf_token()) ?>">
                                        <input type="hidden"
                                               name="action"
                                               value="duplicate_survey">
                                        <input type="hidden"
                                               name="id"
                                               value="<?= h($survey['id']) ?>">
                                        <button class="btn btn-secondary"
                                                type="submit">
                                            複製
                                        </button>
                                    </form>

                                    <form method="post"
                                          data-confirm="このアンケートを削除しますか？">
                                        <input type="hidden"
                                               name="csrf"
                                               value="<?= h(csrf_token()) ?>">
                                        <input type="hidden"
                                               name="action"
                                               value="delete_survey">
                                        <input type="hidden"
                                               name="id"
                                               value="<?= h($survey['id']) ?>">
                                        <button class="btn btn-danger"
                                                type="submit">
                                            削除
                                        </button>
                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <?php
    admin_footer();
    exit;
}


/* ============================================================
 * 編集
 * ============================================================
 */

if ($screen === 'edit') {

    $survey =
        $id !== ''
            ? find_survey($id)
            : null;

    $isNew = $survey === null;

    if ($isNew) {
        $survey = new_survey();
    } else {
        $survey =
            apply_auto_status($survey);

        $surveys = load_surveys();

        foreach ($surveys as $i => $s) {
            if (($s['id'] ?? '') === $survey['id']) {
                $surveys[$i] = $survey;
            }
        }

        save_surveys($surveys);
    }

    admin_header('アンケート作成・編集');

    if ($flash): ?>
        <div class="notice notice-<?= h($flash['type']) ?>">
            <?= nl2br(h($flash['message'])) ?>
        </div>
    <?php endif; ?>

    <div class="page-title">
        <h1>アンケート作成・編集</h1>

        <div class="buttons">
            <a class="btn btn-secondary"
               href="index.php?screen=list"
               onclick="return confirm('編集内容を破棄して一覧へ戻りますか？')">
                キャンセル
            </a>

            <a class="btn btn-outline"
               href="index.php?screen=preview&id=<?= rawurlencode($survey['id']) ?>">
                プレビュー
            </a>
        </div>
    </div>

    <form method="post"
          id="survey-form">

        <input type="hidden"
               name="csrf"
               value="<?= h(csrf_token()) ?>">

        <input type="hidden"
               name="action"
               value="save_survey">

        <input type="hidden"
               name="id"
               value="<?= h($isNew ? '' : $survey['id']) ?>">

        <input type="hidden"
               name="groups_json"
               id="groups_json">

        <div class="card">

            <div class="toolbar">

                <div>
                    <strong>状態：</strong>

                    <span class="badge badge-<?= h(
                        status_class(
                            (string)$survey['status']
                        )
                    ) ?>">
                        <?= h(
                            status_label(
                                (string)$survey['status']
                            )
                        ) ?>
                    </span>
                </div>

                <div class="buttons">

                    <?php if (
                        $survey['status'] === 'draft'
                    ): ?>

                        <button
                            class="btn btn-success"
                            type="submit"
                            name="dummy"
                            value="1"
                            form="status-publish"
                            onclick="return confirm('公開しますか？')">
                            公開
                        </button>

                    <?php elseif (
                        $survey['status'] === 'published'
                    ): ?>

                        <button
                            class="btn btn-warning"
                            type="submit"
                            form="status-stop"
                            onclick="return confirm('停止しますか？')">
                            停止
                        </button>

                    <?php elseif (
                        $survey['status'] === 'stopped'
                    ): ?>

                        <button
                            class="btn btn-success"
                            type="submit"
                            form="status-publish"
                            onclick="return confirm('再開しますか？')">
                            再開
                        </button>

                    <?php endif; ?>

                </div>

            </div>

            <div class="grid"
                 style="margin-top:20px">

                <div class="field">
                    <label>アンケートタイトル</label>
                    <input type="text"
                           id="title"
                           name="title"
                           maxlength="200"
                           required
                           value="<?= h($survey['title']) ?>">
                </div>

                <div class="field">
                    <label>質問番号の採番方式</label>

                    <select id="numbering"
                            name="numbering">
                        <option value="global"
                            <?= $survey['numbering'] === 'global'
                                ? 'selected'
                                : '' ?>>
                            アンケート全体で通番（Q1、Q2、Q3...）
                        </option>
                        <option value="group"
                            <?= $survey['numbering'] === 'group'
                                ? 'selected'
                                : '' ?>>
                            グループ毎（Q1-1、Q1-2、Q2-1...）
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>開始日時</label>
                    <input type="datetime-local"
                           name="startAt"
                           required
                           value="<?= h(
                               date(
                                   'Y-m-d\TH:i',
                                   strtotime(
                                       (string)$survey['startAt']
                                   )
                               )
                           ) ?>">
                </div>

                <div class="field">
                    <label>終了日時</label>
                    <input type="datetime-local"
                           name="endAt"
                           required
                           value="<?= h(
                               date(
                                   'Y-m-d\TH:i',
                                   strtotime(
                                       (string)$survey['endAt']
                                   )
                               )
                           ) ?>">
                </div>

            </div>

            <div class="field">
                <label>アンケート説明</label>
                <textarea name="description"
                          maxlength="5000"><?= h(
                              $survey['description']
                          ) ?></textarea>
            </div>

        </div>

        <div id="groups-container"></div>

        <div class="card">
            <button type="button"
                    class="btn btn-primary"
                    id="add-group">
                ＋ グループを追加
            </button>
        </div>

        <div class="card">

            <div class="right">
                <button class="btn btn-primary"
                        type="submit">
                    保存して一覧へ
                </button>
            </div>

        </div>

    </form>

    <?php if (!$isNew): ?>

        <form method="post"
              id="status-publish">
            <input type="hidden"
                   name="csrf"
                   value="<?= h(csrf_token()) ?>">
            <input type="hidden"
                   name="action"
                   value="change_status">
            <input type="hidden"
                   name="id"
                   value="<?= h($survey['id']) ?>">
            <input type="hidden"
                   name="status"
                   value="published">
        </form>

        <form method="post"
              id="status-stop">
            <input type="hidden"
                   name="csrf"
                   value="<?= h(csrf_token()) ?>">
            <input type="hidden"
                   name="action"
                   value="change_status">
            <input type="hidden"
                   name="id"
                   value="<?= h($survey['id']) ?>">
            <input type="hidden"
                   name="status"
                   value="stopped">
        </form>

    <?php endif; ?>

<script>
const initialGroups =
<?= json_encode(
    $survey['groups'],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

let groups = initialGroups || [];

function uid(prefix){
    return prefix + '_' +
        Math.random().toString(36).slice(2,10);
}

function esc(value){
    const div=document.createElement('div');
    div.textContent=value ?? '';
    return div.innerHTML;
}

function renumber(){
    const numbering=
        document.getElementById('numbering').value;

    let n=1;

    groups.forEach((group,gi)=>{
        group.questions.forEach((question,qi)=>{
            question.number =
                numbering === 'group'
                    ? 'Q'+(gi+1)+'-'+(qi+1)
                    : 'Q'+n;

            n++;
        });
    });
}

function render(){
    renumber();

    const container =
        document.getElementById('groups-container');

    container.innerHTML='';

    groups.forEach((group,gi)=>{

        const groupCard =
            document.createElement('div');

        groupCard.className='card group-card';
        groupCard.draggable=true;
        groupCard.dataset.index=gi;

        groupCard.innerHTML=`
            <div class="group-head">
                <div>
                    <span class="drag">☷</span>
                    <span class="group-title">
                        グループ ${gi+1}
                    </span>
                </div>

                <button type="button"
                        class="btn btn-danger"
                        data-delete-group="${gi}">
                    グループ削除
                </button>
            </div>

            <div class="field">
                <label>グループタイトル</label>
                <input type="text"
                       data-group-title="${gi}"
                       value="${esc(group.title)}"
                       maxlength="200">
            </div>

            <div class="questions"></div>

            <div class="right">
                <button type="button"
                        class="btn btn-outline"
                        data-add-question="${gi}">
                    ＋ 質問を追加
                </button>
            </div>
        `;

        const questions =
            groupCard.querySelector('.questions');

        group.questions.forEach((question,qi)=>{

            const q =
                document.createElement('div');

            q.className='question-card';
            q.draggable=true;
            q.dataset.group=gi;
            q.dataset.question=qi;

            let choicesHtml='';

            if (
                question.type === 'single' ||
                question.type === 'multiple'
            ){
                choicesHtml = `
                    <div class="field">
                        <label>選択肢</label>
                        <div class="choices"></div>
                    </div>
                `;
            }

            q.innerHTML=`
                <div class="question-head">
                    <div>
                        <span class="drag">☷</span>
                        <strong>
                            ${esc(question.number)}
                        </strong>
                    </div>

                    <button type="button"
                            class="btn btn-danger"
                            data-delete-question="${gi}:${qi}">
                        質問削除
                    </button>
                </div>

                <div class="field">
                    <label>質問文</label>
                    <textarea
                        data-question-text="${gi}:${qi}"
                        maxlength="2000"
                        rows="3">${esc(question.text)}</textarea>
                </div>

                <div class="grid">

                    <div class="field">
                        <label>回答形式</label>
                        <select data-question-type="${gi}:${qi}">
                            <option value="single"
                                ${question.type==='single'?'selected':''}>
                                単一選択
                            </option>
                            <option value="multiple"
                                ${question.type==='multiple'?'selected':''}>
                                複数選択
                            </option>
                            <option value="text"
                                ${question.type==='text'?'selected':''}>
                                自由記述
                            </option>
                        </select>
                    </div>

                    <div class="field">
                        <label>必須設定</label>
                        <label style="font-weight:400">
                            <input type="checkbox"
                                   data-required="${gi}:${qi}"
                                   ${question.required?'checked':''}>
                            必須
                        </label>
                    </div>

                </div>

                ${choicesHtml}
            `;

            if (
                question.type === 'single' ||
                question.type === 'multiple'
            ){

                const choices =
                    q.querySelector('.choices');

                question.choices.forEach((choice,ci)=>{

                    const row =
                        document.createElement('div');

                    row.className='choice-row';

                    row.innerHTML=`
                        <input type="text"
                               data-choice="${gi}:${qi}:${ci}"
                               value="${esc(choice.text)}"
                               maxlength="500">

                        <button type="button"
                                class="btn btn-danger"
                                data-delete-choice="${gi}:${qi}:${ci}">
                            削除
                        </button>
                    `;

                    choices.appendChild(row);
                });

                const add =
                    document.createElement('button');

                add.type='button';
                add.className='btn btn-secondary';
                add.textContent='＋ 選択肢追加';

                add.onclick=function(){

                    question.choices.push({
                        id:uid('c'),
                        text:''
                    });

                    render();
                };

                choices.appendChild(add);

                /*
                 * 単一選択の条件分岐。
                 */
                if (question.type === 'single') {

                    const branch =
                        document.createElement('div');

                    branch.className='field';
                    branch.innerHTML=
                        '<label>条件分岐</label>';

                    question.choices.forEach(
                        (choice,ci)=>{

                            const row =
                                document.createElement(
                                    'div'
                                );

                            row.className='mapping';

                            row.innerHTML=`
                                <div>
                                    ${esc(choice.text || '選択肢'+(ci+1))}
                                </div>

                                <select
                                    data-branch="${gi}:${qi}:${ci}">
                                    <option value="">
                                        指定なし（次の質問）
                                    </option>
                                </select>
                            `;

                            const select =
                                row.querySelector(
                                    'select'
                                );

                            groups.forEach(g=>{
                                g.questions.forEach(
                                    target=>{

                                        if (
                                            target.id ===
                                            question.id
                                        ) {
                                            return;
                                        }

                                        const opt =
                                            document.createElement(
                                                'option'
                                            );

                                        opt.value =
                                            target.id;

                                        opt.textContent =
                                            target.number +
                                            ' ' +
                                            target.text;

                                        if (
                                            question.branch &&
                                            question.branch[
                                                choice.id
                                            ] === target.id
                                        ) {
                                            opt.selected=true;
                                        }

                                        select.appendChild(
                                            opt
                                        );
                                    }
                                );
                            });

                            branch.appendChild(row);
                        }
                    );

                    q.appendChild(branch);
                }
            }

            questions.appendChild(q);
        });

        container.appendChild(groupCard);
    });
}

document.getElementById('add-group')
.addEventListener('click',function(){

    groups.push({
        id:uid('g'),
        title:'新しいグループ',
        questions:[]
    });

    render();
});

document.getElementById('numbering')
.addEventListener('change',render);

document.addEventListener('input',function(e){

    const gt =
        e.target.dataset.groupTitle;

    if(gt !== undefined){
        groups[gt].title=e.target.value;
    }

    const qt =
        e.target.dataset.questionText;

    if(qt){
        const [g,q]=qt.split(':');
        groups[g].questions[q].text=
            e.target.value;
    }

    const choice =
        e.target.dataset.choice;

    if(choice){
        const [g,q,c]=choice.split(':');
        groups[g].questions[q].choices[c].text=
            e.target.value;
    }
});

document.addEventListener('change',function(e){

    const type =
        e.target.dataset.questionType;

    if(type){
        const [g,q]=type.split(':');

        groups[g].questions[q].type=
            e.target.value;

        if(e.target.value==='text'){
            groups[g].questions[q].choices=[];
        }

        render();
    }

    const required =
        e.target.dataset.required;

    if(required){
        const [g,q]=required.split(':');

        groups[g].questions[q].required=
            e.target.checked;
    }

    const branch =
        e.target.dataset.branch;

    if(branch){
        const [g,q,c]=branch.split(':');

        if(!groups[g].questions[q].branch){
            groups[g].questions[q].branch={};
        }

        const choice =
            groups[g].questions[q].choices[c];

        groups[g].questions[q].branch[
            choice.id
        ]=e.target.value;
    }
});

document.addEventListener('click',function(e){

    const addQuestion =
        e.target.dataset.addQuestion;

    if(addQuestion !== undefined){

        groups[addQuestion]
            .questions
            .push({
                id:uid('q'),
                number:'',
                text:'',
                type:'single',
                required:false,
                choices:[
                    {id:uid('c'),text:'選択肢1'},
                    {id:uid('c'),text:'選択肢2'}
                ],
                branch:{}
            });

        render();
    }

    const deleteQuestion =
        e.target.dataset.deleteQuestion;

    if(deleteQuestion){

        if(!confirm('質問を削除しますか？')){
            return;
        }

        const [g,q]=
            deleteQuestion.split(':');

        groups[g].questions.splice(q,1);

        render();
    }

    const deleteChoice =
        e.target.dataset.deleteChoice;

    if(deleteChoice){

        if(!confirm('選択肢を削除しますか？')){
            return;
        }

        const [g,q,c]=
            deleteChoice.split(':');

        groups[g].questions[q]
            .choices.splice(c,1);

        render();
    }

    const deleteGroup =
        e.target.dataset.deleteGroup;

    if(deleteGroup !== undefined){

        if(!confirm('グループを削除しますか？')){
            return;
        }

        groups.splice(deleteGroup,1);

        render();
    }
});

document.getElementById('survey-form')
.addEventListener('submit',function(){

    renumber();

    document.getElementById('groups_json')
        .value=JSON.stringify(groups);
});

render();
</script>

<?php
    admin_footer();
    exit;
}


/* ============================================================
 * プレビュー
 * ============================================================
 */

if ($screen === 'preview') {

    $survey =
        $id !== ''
            ? find_survey($id)
            : null;

    if ($survey === null) {
        redirect_screen('list');
    }

    $survey =
        apply_auto_status($survey);

    admin_header('プレビュー');

    ?>

    <div class="page-title">
        <h1>プレビュー</h1>

        <div class="buttons">
            <a class="btn btn-secondary"
               href="index.php?screen=edit&id=<?= rawurlencode($id) ?>">
                編集へ戻る
            </a>
        </div>
    </div>

    <div class="card mobile-answer">

        <h1><?= h($survey['title']) ?></h1>

        <?php if ($survey['description'] !== ''): ?>
            <p>
                <?= nl2br(h($survey['description'])) ?>
            </p>
        <?php endif; ?>

        <?php foreach ($survey['groups'] as $group): ?>

            <div class="answer-card">

                <h2>
                    <?= h($group['title']) ?>
                </h2>

                <?php foreach ($group['questions'] as $question): ?>

                    <div class="answer-card">

                        <div>
                            <strong>
                                <?= h($question['number']) ?>
                            </strong>

                            <?= h($question['text']) ?>

                            <?php if ($question['required']): ?>
                                <span class="badge badge-danger">
                                    必須
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($question['type'] === 'single'): ?>

                            <?php foreach ($question['choices'] as $choice): ?>
                                <label class="answer-option">
                                    <input type="radio"
                                           disabled>
                                    <?= h($choice['text']) ?>
                                </label>
                            <?php endforeach; ?>

                        <?php elseif ($question['type'] === 'multiple'): ?>

                            <?php foreach ($question['choices'] as $choice): ?>
                                <label class="answer-option">
                                    <input type="checkbox"
                                           disabled>
                                    <?= h($choice['text']) ?>
                                </label>
                            <?php endforeach; ?>

                        <?php else: ?>

                            <textarea disabled
                                      placeholder="自由記述"></textarea>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endforeach; ?>

    </div>

    <?php
    admin_footer();
    exit;
}


/* ============================================================
 * kintone設定
 * ============================================================
 */

if ($screen === 'kintone') {

    $settings =
        load_settings();

    $k =
        $settings['kintone'];

    $fields =
        $k['fields'] ?? [];

    admin_header('kintone連携設定');

    if ($flash): ?>
        <div class="notice notice-<?= h($flash['type']) ?>">
            <?= nl2br(h($flash['message'])) ?>
        </div>
    <?php endif; ?>

    <div class="page-title">
        <h1>kintone連携設定</h1>
    </div>

    <div class="card">

        <div style="margin-bottom:20px">
            接続状態：

            <span class="badge badge-<?= h(
                status_class(
                    ($k['connection_status'] ?? '') ===
                    '接続確認済み'
                        ? 'published'
                        : 'draft'
                )
            ?>">
                <?= h(
                    $k['connection_status']
                    ?? '未設定'
                ) ?>
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

            <div class="grid">

                <div class="field">
                    <label>サブドメイン</label>

                    <input type="text"
                           name="subdomain"
                           required
                           value="<?= h(
                               $k['subdomain'] ?? ''
                           ) ?>"
                           placeholder="xxxx.cybozu.com">

                    <div class="muted small">
                        https://xxxx.cybozu.com、
                        xxxx.cybozu.com、xxxx を許容
                    </div>
                </div>

                <div class="field">
                    <label>顧客管理アプリID</label>

                    <input type="number"
                           name="app_id"
                           min="1"
                           required
                           value="<?= h(
                               $k['app_id'] ?? ''
                           ) ?>">
                </div>

                <div class="field">
                    <label>ログイン名</label>

                    <input type="text"
                           name="username"
                           required
                           autocomplete="username"
                           value="<?= h(
                               $k['username'] ?? ''
                           ) ?>">
                </div>

                <div class="field">
                    <label>パスワード</label>

                    <input type="password"
                           name="password"
                           autocomplete="new-password"
                           placeholder="変更しない場合は空欄">

                    <div class="muted small">
                        保存済みパスワードは表示しません。
                        空欄の場合は現在の値を維持します。
                    </div>
                </div>

                <div class="field">
                    <label>Proxy</label>

                    <input type="text"
                           name="proxy"
                           value="<?= h(
                               $k['proxy'] ?? ''
                           ) ?>"
                           placeholder="host:port">

                    <div class="muted small">
                        未入力の場合は直接接続します。
                    </div>
                </div>

                <div class="field">

                    <label>SSL証明書検証</label>

                    <label style="font-weight:400">
                        <input type="checkbox"
                               name="verify_ssl"
                               value="1"
                               <?= !empty($k['verify_ssl'])
                                   ? 'checked'
                                   : '' ?>>
                        有効
                    </label>

                    <div class="muted small">
                        POC初期値は無効です。
                    </div>

                </div>

            </div>

            <button class="btn btn-primary"
                    type="submit">
                設定保存
            </button>

        </form>

        <div class="buttons"
             style="margin-top:12px">

            <form method="post"
                  data-loading>
                <input type="hidden"
                       name="csrf"
                       value="<?= h(csrf_token()) ?>">
                <input type="hidden"
                       name="action"
                       value="test_kintone">
                <button class="btn btn-success"
                        type="submit">
                    <span class="spinner"></span>
                    接続テスト
                </button>
            </form>

            <form method="post"
                  data-loading>
                <input type="hidden"
                       name="csrf"
                       value="<?= h(csrf_token()) ?>">
                <input type="hidden"
                       name="action"
                       value="refresh_kintone_fields">
                <button class="btn btn-secondary"
                        type="submit">
                    <span class="spinner"></span>
                    項目一覧を再取得
                </button>
            </form>

            <form method="post"
                  data-loading
                  data-confirm="kintoneから顧客情報を取得して同期しますか？">
                <input type="hidden"
                       name="csrf"
                       value="<?= h(csrf_token()) ?>">
                <input type="hidden"
                       name="action"
                       value="sync_kintone">
                <button class="btn btn-secondary"
                        type="submit">
                    <span class="spinner"></span>
                    顧客情報を同期
                </button>
            </form>

        </div>

    </div>


    <?php if ($fields): ?>

        <div class="card">

            <h2>kintone項目マッピング</h2>

            <form method="post">

                <input type="hidden"
                       name="csrf"
                       value="<?= h(csrf_token()) ?>">

                <input type="hidden"
                       name="action"
                       value="save_kintone_mapping">

                <div class="field">
                    <label>組織名</label>

                    <?php
                    $selectedOrg =
                        $k['mapping']['organization']
                        ?? [];
                    ?>

                    <?php foreach ($fields as $code => $field): ?>

                        <label style="font-weight:400">
                            <input type="checkbox"
                                   name="organization[]"
                                   value="<?= h($code) ?>"
                                   <?= in_array(
                                       $code,
                                       $selectedOrg,
                                       true
                                   )
                                       ? 'checked'
                                       : '' ?>>
                            <?= h(
                                ($field['label'] ?? $code) .
                                ' [' . $code . ']'
                            ) ?>
                        </label>

                    <?php endforeach; ?>

                </div>

                <?php
                $mappingFields = [
                    'name_field' =>
                        'name',
                    'email_field' =>
                        'email',
                    'department_field' =>
                        'department',
                    'phone_field' =>
                        'phone',
                ];
                ?>

                <?php foreach ($mappingFields as $postName => $key): ?>

                    <div class="field">

                        <label>
                            <?= match ($key) {
                                'name' => '氏名',
                                'email' => 'メールアドレス',
                                'department' => '部署名',
                                'phone' => '電話番号',
                                default => $key,
                            } ?>
                        </label>

                        <select name="<?= h($postName) ?>">

                            <option value="">
                                未設定
                            </option>

                            <?php foreach ($fields as $code => $field): ?>

                                <option value="<?= h($code) ?>"
                                    <?= (
                                        $k['mapping'][$key] ?? ''
                                    ) === $code
                                        ? 'selected'
                                        : '' ?>>
                                    <?= h(
                                        ($field['label'] ?? $code) .
                                        ' [' . $code . ']'
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                <?php endforeach; ?>


                <div class="field">

                    <label>住所</label>

                    <?php
                    $selectedAddress =
                        $k['mapping']['address']
                        ?? [];
                    ?>

                    <?php foreach ($fields as $code => $field): ?>

                        <label style="font-weight:400">
                            <input type="checkbox"
                                   name="address_fields[]"
                                   value="<?= h($code) ?>"
                                   <?= in_array(
                                       $code,
                                       $selectedAddress,
                                       true
                                   )
                                       ? 'checked'
                                       : '' ?>>
                            <?= h(
                                ($field['label'] ?? $code) .
                                ' [' . $code . ']'
                            ) ?>
                        </label>

                    <?php endforeach; ?>

                </div>

                <button class="btn btn-primary"
                        type="submit">
                    マッピングを保存
                </button>

            </form>

        </div>

    <?php endif; ?>

    <?php
    admin_footer();
    exit;
}


/* ============================================================
 * メール設定
 * ============================================================
 */

if ($screen === 'mail') {

    $settings =
        load_settings();

    $m =
        $settings['mail'];

    admin_header('メールサーバ設定');

    if ($flash): ?>
        <div class="notice notice-<?= h($flash['type']) ?>">
            <?= nl2br(h($flash['message'])) ?>
        </div>
    <?php endif; ?>

    <div class="page-title">
        <h1>メールサーバ設定</h1>
    </div>

    <div class="card">

        <div style="margin-bottom:20px">

            接続状態：

            <span class="badge badge-gray">
                <?= h(
                    $m['connection_status']
                    ?? '未設定'
                ) ?>
            </span>

        </div>

        <form method="post">

            <input type="hidden"
                   name="csrf"
                   value="<?= h(csrf_token()) ?>">

            <input type="hidden"
                   name="action"
                   value="save_mail">

            <div class="grid">

                <div class="field">
                    <label>SMTPサーバ</label>
                    <input type="text"
                           name="server"
                           required
                           value="<?= h(
                               $m['server'] ?? ''
                           ) ?>">
                </div>

                <div class="field">
                    <label>SMTPポート</label>
                    <input type="number"
                           name="port"
                           min="1"
                           max="65535"
                           required
                           value="<?= h(
                               $m['port'] ?? 587
                           ) ?>">
                </div>

                <div class="field">
                    <label>暗号化方式</label>
                    <select name="encryption">
                        <option value="ssl"
                            <?= ($m['encryption'] ?? '') === 'ssl'
                                ? 'selected'
                                : '' ?>>
                            SSL
                        </option>
                        <option value="tls"
                            <?= ($m['encryption'] ?? '') === 'tls'
                                ? 'selected'
                                : '' ?>>
                            TLS
                        </option>
                        <option value="none"
                            <?= ($m['encryption'] ?? '') === 'none'
                                ? 'selected'
                                : '' ?>>
                            なし
                        </option>
                    </select>
                </div>

                <div class="field">

                    <label>SMTP認証</label>

                    <label style="font-weight:400">
                        <input type="checkbox"
                               name="auth"
                               value="1"
                               <?= !empty($m['auth'])
                                   ? 'checked'
                                   : '' ?>>
                        SMTP認証を使用
                    </label>

                </div>

                <div class="field">
                    <label>SMTPユーザー名</label>
                    <input type="text"
                           name="username"
                           autocomplete="username"
                           value="<?= h(
                               $m['username'] ?? ''
                           ) ?>">
                </div>

                <div class="field">
                    <label>SMTPパスワード</label>
                    <input type="password"
                           name="password"
                           autocomplete="new-password"
                           placeholder="変更しない場合は空欄">
                </div>

                <div class="field">
                    <label>送信元メールアドレス</label>
                    <input type="email"
                           name="from_email"
                           required
                           value="<?= h(
                               $m['from_email'] ?? ''
                           ) ?>">
                </div>

                <div class="field">
                    <label>送信元名</label>
                    <input type="text"
                           name="from_name"
                           value="<?= h(
                               $m['from_name'] ?? ''
                           ) ?>">
                </div>

                <div class="field">
                    <label>返信先メールアドレス</label>
                    <input type="email"
                           name="reply_to"
                           value="<?= h(
                               $m['reply_to'] ?? ''
                           ) ?>">
                </div>

            </div>

            <button class="btn btn-primary"
                    type="submit">
                設定保存
            </button>

        </form>

        <div class="buttons"
             style="margin-top:12px">

            <form method="post"
                  data-loading>

                <input type="hidden"
                       name="csrf"
                       value="<?= h(csrf_token()) ?>">

                <input type="hidden"
                       name="action"
                       value="test_mail">

                <button class="btn btn-success"
                        type="submit">
                    <span class="spinner"></span>
                    接続テスト
                </button>

            </form>

        </div>

    </div>

    <div class="card">

        <h2>テストメール送信</h2>

        <form method="post"
              data-loading>

            <input type="hidden"
                   name="csrf"
                   value="<?= h(csrf_token()) ?>">

            <input type="hidden"
                   name="action"
                   value="send_test_mail">

            <div class="field">

                <label>テスト送信先</label>

                <input type="email"
                       name="test_email"
                       required
                       placeholder="test@example.com">

            </div>

            <button class="btn btn-primary"
                    type="submit">
                <span class="spinner"></span>
                テストメール送信
            </button>

        </form>

    </div>

    <?php
    admin_footer();
    exit;
}


/* ============================================================
 * 送信
 * ============================================================
 */

if ($screen === 'send') {

    if ($id === '' || !safe_id($id)) {
        redirect_screen('list');
    }

    $survey =
        find_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }

    $customers =
        load_customers();

    $history =
        load_send_history();

    $surveyHistory =
        array_values(
            array_filter(
                $history,
                fn(array $item): bool =>
                    ($item['surveyId'] ?? '') === $id
            )
        );

    admin_header('顧客選択・メール送信');

    if ($flash): ?>
        <div class="notice notice-<?= h($flash['type']) ?>">
            <?= nl2br(h($flash['message'])) ?>
        </div>
    <?php endif; ?>

    <div class="page-title">
        <h1>顧客選択・メール送信</h1>
    </div>

    <div class="card">

        <strong>対象アンケート</strong>

        <div style="font-size:20px;margin-top:7px">
            <?= h($survey['title']) ?>
        </div>

        <div class="muted small">
            対象ID:
            <?= h($survey['id']) ?>
        </div>

    </div>

    <div class="card">

        <div class="toolbar">

            <h2 style="margin:0">
                顧客選択・送信
            </h2>

            <input type="text"
                   id="customer-search"
                   placeholder="顧客検索"
                   style="max-width:300px">

        </div>

        <form method="post"
              style="margin-top:18px"
              data-confirm="選択した顧客へメールを一括送信しますか？">

            <input type="hidden"
                   name="csrf"
                   value="<?= h(csrf_token()) ?>">

            <input type="hidden"
                   name="action"
                   value="send_bulk_mail">

            <input type="hidden"
                   name="survey_id"
                   value="<?= h($id) ?>">

            <div class="table-wrap">

                <table id="customer-table">

                    <thead>
                    <tr>
                        <th>
                            <input type="checkbox"
                                   id="check-all">
                        </th>
                        <th>組織名</th>
                        <th>氏名</th>
                        <th>メールアドレス</th>
                        <th>部署名</th>
                        <th>電話番号</th>
                        <th>住所</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($customers as $customer): ?>

                        <tr data-search="<?= h(
                            implode(
                                ' ',
                                [
                                    $customer['organization'] ?? '',
                                    $customer['name'] ?? '',
                                    $customer['email'] ?? '',
                                    $customer['department'] ?? '',
                                    $customer['phone'] ?? '',
                                    $customer['address'] ?? '',
                                ]
                            )
                        ) ?>">

                            <td>
                                <input type="checkbox"
                                       name="customer_ids[]"
                                       value="<?= h(
                                           $customer['id']
                                       ) ?>">
                            </td>

                            <td>
                                <?= h(
                                    $customer['organization']
                                    ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $customer['name']
                                    ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $customer['email']
                                    ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $customer['department']
                                    ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $customer['phone']
                                    ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $customer['address']
                                    ?? ''
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <div class="grid"
                 style="margin-top:20px">

                <div class="field">
                    <label>送信種別</label>

                    <select name="send_type">
                        <option value="initial">
                            初回送信
                        </option>
                        <option value="reminder">
                            リマインド
                        </option>
                        <option value="resend">
                            再送
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>メール件名</label>

                    <input type="text"
                           name="subject"
                           required
                           value="<?= h(
                               $survey['title'] .
                               ' アンケートのお願い'
                           ) ?>">
                </div>

            </div>

            <div class="field">

                <label>メール本文</label>

                <textarea name="body"
                          required
                          rows="12"><?= h(
' {顧客名} 様

アンケートへのご協力をお願いいたします。

以下のURLからご回答ください。

{アンケートURL}

よろしくお願いいたします。'
                ) ?></textarea>

                <div class="muted small">
                    使用可能な変数：
                    {顧客名} / {アンケートURL}
                </div>

            </div>

            <button class="btn btn-primary"
                    type="submit">
                <span class="spinner"></span>
                一括送信
            </button>

        </form>

    </div>


    <div class="card">

        <h2>送信履歴</h2>

        <?php if (!$surveyHistory): ?>

            <p class="muted">
                送信履歴はありません。
            </p>

        <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>
                    <tr>
                        <th>日時</th>
                        <th>顧客</th>
                        <th>メール</th>
                        <th>種別</th>
                        <th>件名</th>
                        <th>結果</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach (
                        array_reverse($surveyHistory)
                        as $item
                    ): ?>

                        <tr>

                            <td>
                                <?= h(
                                    $item['sentAt']
                                    ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $item['customerName']
                                    ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $item['email']
                                    ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    match (
                                        $item['type'] ?? ''
                                    ) {
                                        'reminder' => 'リマインド',
                                        'resend' => '再送',
                                        default => '初回送信',
                                    }
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $item['subject']
                                    ?? ''
                                ) ?>
                            </td>

                            <td>

                                <?php if (
                                    ($item['status'] ?? '') ===
                                    'sent'
                                ): ?>

                                    <span class="badge badge-success">
                                        送信済み
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-danger">
                                        失敗
                                    </span>

                                    <?php if (
                                        !empty($item['error'])
                                    ): ?>

                                        <div class="small muted">
                                            <?= h(
                                                $item['error']
                                            ) ?>
                                        </div>

                                    <?php endif; ?>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

<script>
const search =
document.getElementById('customer-search');

if(search){
    search.addEventListener('input',function(){
        const keyword=
            this.value.toLowerCase();

        document.querySelectorAll(
            '#customer-table tbody tr'
        ).forEach(function(row){

            const text=
                (row.dataset.search || '')
                .toLowerCase();

            row.style.display=
                text.includes(keyword)
                    ? ''
                    : 'none';
        });
    });
}

const checkAll =
document.getElementById('check-all');

if(checkAll){
    checkAll.addEventListener('change',function(){
        document.querySelectorAll(
            '#customer-table tbody input[type="checkbox"]'
        ).forEach(function(cb){
            cb.checked=checkAll.checked;
        });
    });
}
</script>

    <?php
    admin_footer();
    exit;
}


/* ============================================================
 * 集計
 * ============================================================
 */

if ($screen === 'analytics') {

    if ($id === '' || !safe_id($id)) {
        redirect_screen('list');
    }

    $survey =
        find_survey($id);

    if ($survey === null) {
        redirect_screen('list');
    }

    $answers =
        array_values(
            array_filter(
                load_answers(),
                fn(array $a): bool =>
                    ($a['surveyId'] ?? '') === $id
            )
        );

    $history =
        array_values(
            array_filter(
                load_send_history(),
                fn(array $h): bool =>
                    ($h['surveyId'] ?? '') === $id &&
                    ($h['status'] ?? '') === 'sent'
            )
        );

    $customerCount =
        count(
            array_unique(
                array_map(
                    fn(array $h): string =>
                        (string)(
                            $h['customerId'] ?? ''
                        ),
                    $history
                )
            )
        );

    $answerCount =
        count($answers);

    $rate =
        $customerCount > 0
            ? round(
                $answerCount /
                $customerCount *
                100,
                1
            )
            : 0;

    admin_header('回答集計・分析');

    ?>

    <div class="page-title">

        <h1>回答集計・分析</h1>

        <div class="buttons">

            <a class="btn btn-outline"
               href="index.php?screen=analytics&id=<?= rawurlencode($id) ?>&export=csv">
                CSV
            </a>

            <a class="btn btn-outline"
               href="index.php?screen=analytics&id=<?= rawurlencode($id) ?>&export=pdf">
                PDF
            </a>

        </div>

    </div>

    <div class="card">

        <strong>対象アンケート</strong>

        <div style="font-size:20px;margin-top:7px">
            <?= h($survey['title']) ?>
        </div>

    </div>

    <div class="grid-3">

        <div class="stat">
            <div class="label">
                送信対象者数
            </div>
            <div class="value">
                <?= h($customerCount) ?>
            </div>
        </div>

        <div class="stat">
            <div class="label">
                回答数
            </div>
            <div class="value">
                <?= h($answerCount) ?>
            </div>
        </div>

        <div class="stat">
            <div class="label">
                回答率
            </div>
            <div class="value">
                <?= h($rate) ?>%
            </div>
        </div>

    </div>

    <div class="grid-3"
         style="margin-top:18px">

        <div class="stat">
            <div class="label">
                未登録回答数
            </div>
            <div class="value">
                0
            </div>
        </div>

        <div class="stat">
            <div class="label">
                未回答数
            </div>
            <div class="value">
                <?= h(
                    max(
                        0,
                        $customerCount -
                        $answerCount
                    )
                ) ?>
            </div>
        </div>

    </div>

    <?php if ($answerCount === 0): ?>

        <div class="card"
             style="margin-top:20px">
            現在、回答データはありません
        </div>

    <?php else: ?>

        <div class="card">

            <h2>設問別集計</h2>

            <?php foreach (
                $survey['groups']
                as $group
            ): ?>

                <h3>
                    <?= h($group['title']) ?>
                </h3>

                <?php foreach (
                    $group['questions']
                    as $question
                ): ?>

                    <?php
                    $counts = [];

                    foreach (
                        $question['choices']
                        as $choice
                    ) {
                        $counts[
                            $choice['text']
                        ] = 0;
                    }

                    $textAnswers = [];

                    foreach ($answers as $answer) {

                        $value =
                            $answer['answers']
                            [$question['id']]
                            ?? null;

                        if (is_array($value)) {

                            foreach ($value as $v) {

                                $key =
                                    (string)$v;

                                if (
                                    isset(
                                        $counts[$key]
                                    )
                                ) {
                                    $counts[$key]++;
                                }
                            }

                        } elseif (
                            $value !== null &&
                            $value !== ''
                        ) {

                            if (
                                isset(
                                    $counts[
                                        (string)$value
                                    ]
                                )
                            ) {
                                $counts[
                                    (string)$value
                                ]++;
                            } else {
                                $textAnswers[] =
                                    (string)$value;
                            }
                        }
                    }
                    ?>

                    <div class="answer-card">

                        <strong>
                            <?= h(
                                $question['number']
                            ) ?>
                            <?= h(
                                $question['text']
                            ) ?>
                        </strong>

                        <?php if (
                            $question['type'] !== 'text'
                        ): ?>

                            <?php foreach (
                                $counts as $choice => $count
                            ): ?>

                                <div style="margin-top:9px">

                                    <div class="toolbar">

                                        <span>
                                            <?= h($choice) ?>
                                        </span>

                                        <strong>
                                            <?= h($count) ?>件
                                        </strong>

                                    </div>

                                    <div style="
                                        height:8px;
                                        background:#e2e8f0;
                                        border-radius:99px;
                                        overflow:hidden;
                                        margin-top:5px
                                    ">
                                        <div style="
                                            width:<?= $answerCount
                                                ? min(
                                                    100,
                                                    $count /
                                                    $answerCount *
                                                    100
                                                )
                                                : 0 ?>%;
                                            height:100%;
                                            background:#2563eb
                                        "></div>
                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        <?php if ($textAnswers): ?>

                            <div style="margin-top:14px">

                                <?php foreach (
                                    $textAnswers
                                    as $text
                                ): ?>

                                    <div class="notice notice-info">
                                        <?= nl2br(
                                            h($text)
                                        ) ?>
                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            <?php endforeach; ?>

        </div>

        <div class="card">

            <h2>個別回答</h2>

            <?php foreach (
                $answers as $answer
            ): ?>

                <div class="answer-card">

                    <div class="muted small">
                        <?= h(
                            $answer['createdAt']
                            ?? ''
                        ) ?>
                    </div>

                    <?php foreach (
                        $survey['groups']
                        as $group
                    ): ?>

                        <?php foreach (
                            $group['questions']
                            as $question
                        ): ?>

                            <?php
                            $value =
                                $answer['answers']
                                [$question['id']]
                                ?? '';
                            ?>

                            <div style="margin-top:12px">

                                <strong>
                                    <?= h(
                                        $question['number']
                                    ) ?>
                                    <?= h(
                                        $question['text']
                                    ) ?>
                                </strong>

                                <div style="margin-top:4px">

                                    <?php if (
                                        is_array($value)
                                    ): ?>

                                        <?= h(
                                            implode(
                                                ', ',
                                                array_map(
                                                    'strval',
                                                    $value
                                                )
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        <?= nl2br(
                                            h($value)
                                        ) ?>

                                    <?php endif; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php endforeach; ?>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <?php
    admin_footer();
    exit;
}


/* ============================================================
 * 回答者
 * ============================================================
 */

if ($screen === 'answer') {

    if ($id === '' || !safe_id($id)) {
        render_error(
            'アンケートがありません。',
            '指定されたアンケートが見つかりません。'
        );
        exit;
    }

    $survey =
        find_survey($id);

    if ($survey === null) {
        render_error(
            'アンケートがありません。',
            '指定されたアンケートが見つかりません。'
        );
        exit;
    }

    $survey =
        apply_auto_status($survey);

    if ($survey['status'] !== 'published') {
        render_error(
            '回答できません。',
            'このアンケートは現在回答を受け付けていません。'
        );
        exit;
    }

    $form =
        $_SESSION['answer_form']
        ?? [];

    /*
     * 条件分岐で表示対象を決定。
     */
    $visible = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $visible[$question['id']] = true;
        }
    }

    /*
     * 単純な分岐処理。
     * 選択された分岐先がある場合、
     * その後続以外を一旦表示対象として維持し、
     * 明示的な分岐先を優先。
     */
    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {

            if (
                $question['type'] !== 'single' ||
                empty($question['branch'])
            ) {
                continue;
            }

            $value =
                $form[$question['id']]
                ?? '';

            if ($value === '') {
                continue;
            }

            foreach (
                $question['branch']
                as $choiceId => $targetId
            ) {

                if (
                    $choiceId === $value &&
                    $targetId !== ''
                ) {

                    $found = false;

                    foreach (
                        $survey['groups']
                        as $g
                    ) {
                        foreach (
                            $g['questions']
                            as $q
                        ) {
                            if (
                                ($q['id'] ?? '') ===
                                $targetId
                            ) {
                                $found = true;
                                break 2;
                            }
                        }
                    }

                    if (!$found) {
                        continue;
                    }

                    /*
                     * それ以前の質問は維持。
                     * target以降を表示。
                     */
                }
            }
        }
    }

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($survey['title']) ?></title>
<style>
:root{
 --primary:#2563eb;
 --primary-dark:#1d4ed8;
 --success:#16a34a;
 --danger:#dc2626;
 --border:#dbe2ea;
 --text:#1e293b;
 --gray:#64748b;
}
*{box-sizing:border-box}
body{
 margin:0;
 background:#f8fafc;
 color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
 "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
.mobile-answer{
 max-width:760px;
 margin:0 auto;
 padding:18px;
}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 padding:22px;
 margin-bottom:16px;
 box-shadow:0 4px 18px rgba(15,23,42,.08);
}
.question{
 margin-top:20px;
}
.question-title{
 font-size:17px;
 font-weight:700;
 line-height:1.6;
}
.required{
 color:var(--danger);
 font-size:12px;
 margin-left:6px;
}
.option{
 display:block;
 border:1px solid var(--border);
 border-radius:9px;
 padding:14px;
 margin-top:9px;
 background:#fff;
}
.option input{
 margin-right:9px;
 transform:scale(1.2);
}
textarea{
 width:100%;
 min-height:150px;
 padding:12px;
 border:1px solid var(--border);
 border-radius:9px;
 font-size:16px;
}
.actions{
 display:flex;
 gap:10px;
 justify-content:space-between;
}
button{
 border:0;
 border-radius:8px;
 padding:13px 18px;
 font-size:16px;
 font-weight:700;
 cursor:pointer;
 background:var(--primary);
 color:#fff;
}
.notice{
 padding:12px;
 background:#fef2f2;
 color:#991b1b;
 border:1px solid #fecaca;
 border-radius:8px;
 margin-bottom:15px;
 white-space:pre-wrap;
}
@media(max-width:600px){
 .mobile-answer{
  padding:10px;
 }
 .card{
  padding:17px;
 }
 .actions button{
  flex:1;
 }
}
</style>
</head>
<body>

<main class="mobile-answer">

    <div class="card">

        <h1>
            <?= h($survey['title']) ?>
        </h1>

        <?php if (
            $survey['description'] !== ''
        ): ?>

            <p>
                <?= nl2br(
                    h($survey['description'])
                ) ?>
            </p>

        <?php endif; ?>

    </div>

    <?php if ($flash): ?>

        <div class="notice">
            <?= nl2br(
                h($flash['message'])
            ) ?>
        </div>

    <?php endif; ?>

    <form method="post">

        <input type="hidden"
               name="csrf"
               value="<?= h(csrf_token()) ?>">

        <input type="hidden"
               name="action"
               value="answer_confirm">

        <input type="hidden"
               name="survey_id"
               value="<?= h($survey['id']) ?>">

        <?php foreach (
            $survey['groups']
            as $group
        ): ?>

            <div class="card">

                <h2>
                    <?= h($group['title']) ?>
                </h2>

                <?php foreach (
                    $group['questions']
                    as $question
                ): ?>

                    <div class="question">

                        <div class="question-title">

                            <?= h(
                                $question['number']
                            ) ?>

                            <?= h(
                                $question['text']
                            ) ?>

                            <?php if (
                                $question['required']
                            ): ?>

                                <span class="required">
                                    必須
                                </span>

                            <?php endif; ?>

                        </div>


                        <?php if (
                            $question['type'] ===
                            'single'
                        ): ?>

                            <?php foreach (
                                $question['choices']
                                as $choice
                            ): ?>

                                <label class="option">

                                    <input type="radio"
                                           name="answer[<?= h(
                                               $question['id']
                                           ) ?>]"
                                           value="<?= h(
                                               $choice['text']
                                           ) ?>"
                                           <?= (
                                               ($form[
                                                   $question['id']
                                               ] ?? '') ===
                                               $choice['text']
                                           )
                                               ? 'checked'
                                               : '' ?>>

                                    <?= h(
                                        $choice['text']
                                    ) ?>

                                </label>

                            <?php endforeach; ?>


                        <?php elseif (
                            $question['type'] ===
                            'multiple'
                        ): ?>

                            <?php
                            $selected =
                                is_array(
                                    $form[
                                        $question['id']
                                    ] ?? null
                                )
                                    ? $form[
                                        $question['id']
                                    ]
                                    : [];
                            ?>

                            <?php foreach (
                                $question['choices']
                                as $choice
                            ): ?>

                                <label class="option">

                                    <input type="checkbox"
                                           name="answer[<?= h(
                                               $question['id']
                                           ) ?>][]"
                                           value="<?= h(
                                               $choice['text']
                                           ) ?>"
                                           <?= in_array(
                                               $choice['text'],
                                               $selected,
                                               true
                                           )
                                               ? 'checked'
                                               : '' ?>>

                                    <?= h(
                                        $choice['text']
                                    ) ?>

                                </label>

                            <?php endforeach; ?>


                        <?php else: ?>

                            <textarea
                                name="answer[<?= h(
                                    $question['id']
                                ) ?>]"
                                placeholder="回答を入力してください"><?= h(
                                    is_string(
                                        $form[
                                            $question['id']
                                        ] ?? ''
                                    )
                                        ? $form[
                                            $question['id']
                                        ]
                                        : ''
                                ) ?></textarea>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endforeach; ?>

        <div class="actions">

            <button type="submit">
                次へ
            </button>

        </div>

    </form>

</main>

</body>
</html>
<?php
    exit;
}


/* ============================================================
 * 回答確認
 * ============================================================
 */

if ($screen === 'confirm') {

    if ($id === '' || !safe_id($id)) {
        render_error(
            'アンケートがありません。',
            '指定されたアンケートが見つかりません。'
        );
        exit;
    }

    $survey =
        find_survey($id);

    if ($survey === null) {
        render_error(
            'アンケートがありません。',
            '指定されたアンケートが見つかりません。'
        );
        exit;
    }

    $answerForm =
        $_SESSION['answer_form']
        ?? [];

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>回答確認</title>
<style>
:root{
 --primary:#2563eb;
 --border:#dbe2ea;
 --text:#1e293b;
}
*{box-sizing:border-box}
body{
 margin:0;
 background:#f8fafc;
 color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
 "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
.container{
 max-width:760px;
 margin:0 auto;
 padding:18px;
}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:12px;
 padding:22px;
 margin-bottom:15px;
 box-shadow:0 4px 18px rgba(15,23,42,.08);
}
.actions{
 display:flex;
 gap:10px;
}
button,a{
 flex:1;
 padding:13px;
 border-radius:8px;
 border:0;
 text-align:center;
 text-decoration:none;
 font-weight:700;
 font-size:15px;
}
.primary{
 background:var(--primary);
 color:#fff;
}
.secondary{
 background:#e2e8f0;
 color:#1e293b;
}
</style>
</head>
<body>

<main class="container">

    <div class="card">

        <h1>回答確認</h1>

        <p>
            入力内容をご確認ください。
        </p>

    </div>

    <?php foreach (
        $survey['groups']
        as $group
    ): ?>

        <div class="card">

            <h2>
                <?= h($group['title']) ?>
            </h2>

            <?php foreach (
                $group['questions']
                as $question
            ): ?>

                <div style="margin-top:16px">

                    <strong>
                        <?= h(
                            $question['number']
                        ) ?>
                        <?= h(
                            $question['text']
                        ) ?>
                    </strong>

                    <div style="margin-top:6px">

                        <?php
                        $value =
                            $answerForm[
                                $question['id']
                            ] ?? '';
                        ?>

                        <?php if (
                            is_array($value)
                        ): ?>

                            <?= h(
                                implode(
                                    '、 ',
                                    array_map(
                                        'strval',
                                        $value
                                    )
                                )
                            ) ?>

                        <?php else: ?>

                            <?= nl2br(
                                h($value)
                            ) ?>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endforeach; ?>

    <div class="card">

        <div class="actions">

            <a class="secondary"
               href="index.php?screen=answer&id=<?= rawurlencode($id) ?>">
                戻る
            </a>

            <form method="post"
                  style="flex:1"
                  onsubmit="return confirm('回答を送信しますか？')">

                <input type="hidden"
                       name="csrf"
                       value="<?= h(csrf_token()) ?>">

                <input type="hidden"
                       name="action"
                       value="submit_answer">

                <input type="hidden"
                       name="survey_id"
                       value="<?= h($id) ?>">

                <button class="primary"
                        type="submit"
                        style="width:100%">
                    回答を送信
                </button>

            </form>

        </div>

    </div>

</main>

</body>
</html>
<?php
    exit;
}


/* ============================================================
 * 回答完了
 * ============================================================
 */

if ($screen === 'complete') {

    $survey =
        $id !== ''
            ? find_survey($id)
            : null;

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>回答完了</title>
<style>
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
 "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
.container{
 max-width:650px;
 margin:70px auto;
 padding:20px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:12px;
 padding:35px;
 box-shadow:0 4px 18px rgba(15,23,42,.08);
 text-align:center;
}
.icon{
 width:64px;
 height:64px;
 margin:0 auto 20px;
 border-radius:50%;
 background:#dcfce7;
 color:#16a34a;
 display:flex;
 align-items:center;
 justify-content:center;
 font-size:32px;
 font-weight:800;
}
</style>
</head>
<body>

<main class="container">

    <div class="card">

        <div class="icon">
            ✓
        </div>

        <h1>
            回答ありがとうございました
        </h1>

        <p>
            アンケートへの回答を受け付けました。
        </p>

    </div>

</main>

</body>
</html>
<?php
    exit;
}


/* ============================================================
 * CSV / PDF出力
 * ============================================================
 */

if (
    $screen === 'analytics' &&
    isset($_GET['export'])
) {

    $export =
        (string)$_GET['export'];

    if ($id === '' || !safe_id($id)) {
        http_response_code(400);
        exit('不正なIDです。');
    }

    $survey =
        find_survey($id);

    if ($survey === null) {
        http_response_code(404);
        exit('アンケートがありません。');
    }

    $answers =
        array_values(
            array_filter(
                load_answers(),
                fn(array $a): bool =>
                    ($a['surveyId'] ?? '') === $id
            )
        );

    if ($export === 'csv') {

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="survey_' .
            preg_replace(
                '/[^A-Za-z0-9_-]/',
                '_',
                $id
            ) .
            '.csv"'
        );

        $fp = fopen('php://output', 'wb');

        /*
         * UTF-8 BOM。
         */
        fwrite(
            $fp,
            "\xEF\xBB\xBF"
        );

        $header = [
            '回答ID',
            '回答日時',
        ];

        foreach (
            $survey['groups']
            as $group
        ) {
            foreach (
                $group['questions']
                as $question
            ) {
                $header[] =
                    ($question['number'] ?? '') .
                    ' ' .
                    ($question['text'] ?? '');
            }
        }

        fputcsv(
            $fp,
            $header
        );

        foreach ($answers as $answer) {

            $row = [
                $answer['id'] ?? '',
                $answer['createdAt'] ?? '',
            ];

            foreach (
                $survey['groups']
                as $group
            ) {
                foreach (
                    $group['questions']
                    as $question
                ) {

                    $value =
                        $answer['answers']
                        [$question['id']]
                        ?? '';

                    if (is_array($value)) {
                        $value =
                            implode(
                                '、',
                                array_map(
                                    'strval',
                                    $value
                                )
                            );
                    }

                    $row[] = $value;
                }
            }

            fputcsv(
                $fp,
                $row
            );
        }

        fclose($fp);
        exit;
    }

    if ($export === 'pdf') {

        /*
         * 外部PDFライブラリなしで最小PDFを生成。
         * 実データをPDFコンテンツへ含める。
         *
         * 標準PDFフォントのため、日本語表示はPDFビューア側の
         * フォント置換可否に依存する。
         */
        $lines = [];

        $lines[] =
            'Survey: ' .
            pdf_text(
                $survey['title']
            );

        $lines[] =
            'Answers: ' .
            count($answers);

        foreach ($answers as $answer) {

            $lines[] =
                'Answer ID: ' .
                pdf_text(
                    $answer['id'] ?? ''
                );

            foreach (
                $survey['groups']
                as $group
            ) {
                foreach (
                    $group['questions']
                    as $question
                ) {

                    $value =
                        $answer['answers']
                        [$question['id']]
                        ?? '';

                    if (is_array($value)) {
                        $value =
                            implode(
                                ', ',
                                array_map(
                                    'strval',
                                    $value
                                )
                            );
                    }

                    $lines[] =
                        pdf_text(
                            ($question['number'] ?? '') .
                            ' ' .
                            ($question['text'] ?? '') .
                            ': ' .
                            (string)$value
                        );
                }
            }

            $lines[] = '';
        }

        $pdf =
            make_simple_pdf($lines);

        header(
            'Content-Type: application/pdf'
        );

        header(
            'Content-Disposition: attachment; filename="survey_' .
            preg_replace(
                '/[^A-Za-z0-9_-]/',
                '_',
                $id
            ) .
            '.pdf"'
        );

        echo $pdf;
        exit;
    }
}


/* ============================================================
 * PDF補助
 * ============================================================
 */

function pdf_text(string $text): string
{
    /*
     * PDF標準フォントで扱える範囲を安全にする。
     */
    $text =
        preg_replace(
            '/[^\x20-\x7E]/',
            '?',
            $text
        ) ?? '';

    return str_replace(
        ['\\','(',')'],
        ['\\\\','\\(','\\)'],
        $text
    );
}

function make_simple_pdf(
    array $lines
): string {

    $objects = [];

    $objects[1] =
        '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[2] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[3] =
        '<< /Type /Page /Parent 2 0 R ' .
        '/MediaBox [0 0 595 842] ' .
        '/Resources << /Font << /F1 4 0 R >> >> ' .
        '/Contents 5 0 R >>';

    $objects[4] =
        '<< /Type /Font /Subtype /Type1 ' .
        '/BaseFont /Helvetica >>';

    $content =
        "BT\n" .
        "/F1 9 Tf\n" .
        "40 800 Td\n";

    $lineNo = 0;

    foreach ($lines as $line) {

        if ($lineNo > 0) {
            $content .=
                "0 -14 Td\n";
        }

        $content .=
            '(' .
            pdf_text((string)$line) .
            ") Tj\n";

        $lineNo++;

        if ($lineNo >= 52) {
            break;
        }
    }

    $content .=
        "ET\n";

    $objects[5] =
        '<< /Length ' .
        strlen($content) .
        ' >>' .
        "\nstream\n" .
        $content .
        "endstream";

    $pdf =
        "%PDF-1.4\n";

    $offsets = [0];

    for ($i = 1; $i <= 5; $i++) {

        $offsets[$i] =
            strlen($pdf);

        $pdf .=
            $i .
            " 0 obj\n" .
            $objects[$i] .
            "\nendobj\n";
    }

    $xref =
        strlen($pdf);

    $pdf .=
        "xref\n" .
        "0 6\n" .
        "0000000000 65535 f \n";

    for ($i = 1; $i <= 5; $i++) {

        $pdf .=
            sprintf(
                "%010d 00000 n \n",
                $offsets[$i]
            );
    }

    $pdf .=
        "trailer\n" .
        "<< /Size 6 /Root 1 0 R >>\n" .
        "startxref\n" .
        $xref .
        "\n%%EOF";

    return $pdf;
}


/* ============================================================
 * URL
 * ============================================================
 */

function app_public_url(
    string $screen,
    array $params = []
): string {

    $scheme =
        (!empty($_SERVER['HTTPS']) &&
         $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';

    $host =
        (string)(
            $_SERVER['HTTP_HOST']
            ?? 'localhost'
        );

    /*
     * HTTP Hostをそのまま外部リダイレクト先には使わない。
     * アンケートURL用途のため、現在のアプリURLを生成する。
     */
    $script =
        (string)(
            $_SERVER['SCRIPT_NAME']
            ?? '/index.php'
        );

    $query = [
        'screen' => $screen,
    ];

    foreach ($params as $key => $value) {
        $query[$key] = $value;
    }

    return
        $scheme .
        '://' .
        $host .
        $script .
        '?' .
        http_build_query($query);
}


/* ============================================================
 * 未知のscreen
 * ============================================================
 */

redirect_screen('list');
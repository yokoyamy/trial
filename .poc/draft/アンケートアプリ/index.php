<?php
declare(strict_types=1);

/* =========================================================
   アンケートアプリ / single entry point
   Apache 2.4 / PHP 8.5 / DBなし / cURLなし
   ========================================================= */

const DATA = __DIR__ . '/data';
const SECRET = __DIR__ . '/.secrets/アンケートアプリ/secret.key';

if (!is_dir(DATA)) @mkdir(DATA, 0700, true);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
]);
if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
    $sessionError = true;
} else {
    $sessionError = false;
}

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function post(string $k, mixed $d = ''): mixed {
    return $_POST[$k] ?? $d;
}
function getv(string $k, mixed $d = ''): mixed {
    return $_GET[$k] ?? $d;
}
function data(string $name, mixed $default = []): mixed {
    $f = DATA . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name) . '.php';
    if (!is_file($f)) return $default;
    $v = include $f;
    return $v ?? $default;
}
function save(string $name, mixed $value): bool {
    $f = DATA . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name) . '.php';
    $tmp = $f . '.tmp';
    $s = "<?php\nreturn " . var_export($value, true) . ";\n";
    if (@file_put_contents($tmp, $s, LOCK_EX) === false) return false;
    return @rename($tmp, $f);
}
function flash(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['flash'] = [$msg, $type];
    }
}
function flashHtml(): string {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['flash'])) return '';
    [$m, $t] = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="alert ' . h($t) . '">' . h($m) . '</div>';
}
function csrf(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('セッションを開始できません。');
    }
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function checkCsrf(): void {
    $token = (string)post('_csrf');
    if (session_status() !== PHP_SESSION_ACTIVE || $token === '' ||
        empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        throw new RuntimeException('セッションエラーです。ページを再読み込みしてから再実行してください。');
    }
}
function enc(string $plain): string {
    if (!function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('Sodium拡張が利用できません。');
    }
    if (!is_file(SECRET) || !is_readable(SECRET)) {
        throw new RuntimeException('暗号鍵が存在しません。');
    }
    $key = file_get_contents(SECRET);
    if ($key === false) throw new RuntimeException('暗号鍵を読み込めません。');
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        $key = base64_decode(trim($key), true) ?: '';
    }
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('暗号鍵設定エラーです。');
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
    return 'ENC:v1:' . base64_encode($nonce) . ':' . base64_encode($cipher);
}
function dec(string $value): string {
    if (!str_starts_with($value, 'ENC:v1:')) {
        throw new RuntimeException('暗号文形式が不正です。');
    }
    $p = explode(':', $value, 4);
    if (count($p) !== 4) throw new RuntimeException('暗号文形式が不正です。');
    $nonce = base64_decode($p[2], true);
    $cipher = base64_decode($p[3], true);
    if ($nonce === false || $cipher === false) {
        throw new RuntimeException('暗号文を復元できません。');
    }
    $key = file_get_contents(SECRET);
    if ($key === false) throw new RuntimeException('暗号鍵を読み込めません。');
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        $key = base64_decode(trim($key), true) ?: '';
    }
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('暗号鍵設定エラーです。');
    }
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    if ($plain === false) throw new RuntimeException('暗号化データを復号できません。');
    return $plain;
}

/* 外部通信。画面遷移は絶対に行わない。 */
function httpRequest(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null,
    ?string $proxy = null,
    bool $verify = false
): array {
    $opts = [
        'http' => [
            'method' => $method,
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'header' => implode("\r\n", $headers) . "\r\n",
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
        ]
    ];
    if ($body !== null) $opts['http']['content'] = $body;
    if ($proxy !== null && $proxy !== '') {
        if (!preg_match('/^[^:\/\s]+:\d+$/', $proxy)) {
            return ['code'=>0,'body'=>'','error'=>'Proxy形式が不正です。'];
        }
        $opts['http']['proxy'] = 'tcp://' . $proxy;
        $opts['http']['request_fulluri'] = true;
    }
    $ctx = stream_context_create($opts);
    $headersBefore = $http_response_header ?? [];
    $bodyResult = @file_get_contents($url, false, $ctx);
    $responseHeaders = $http_response_header ?? $headersBefore;
    $code = 0;
    foreach ($responseHeaders as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $line, $m)) {
            $code = (int)$m[1];
        }
    }
    $error = $bodyResult === false ? '外部サービスからレスポンスを取得できませんでした。' : '';
    return [
        'code' => $code,
        'body' => $bodyResult === false ? '' : $bodyResult,
        'headers' => $responseHeaders,
        'error' => $error
    ];
}
function redirectScreen(string $screen, string $id = ''): never {
    $url = 'index.php?screen=' . rawurlencode($screen);
    if ($id !== '') $url .= '&id=' . rawurlencode($id);
    header('Location: ' . $url, true, 303);
    exit;
}
function surveys(): array {
    return data('surveys', [[
        'id'=>'survey-001',
        'title'=>'2026年度 顧客満足度アンケート',
        'description'=>'サービスについてのご意見をお聞かせください。',
        'createdAt'=>'2026-08-01',
        'updatedAt'=>'2026-08-25',
        'startAt'=>'2026-08-01T09:00',
        'endAt'=>'2026-09-20T18:00',
        'status'=>'published',
        'numbering'=>'global',
        'groups'=>[[
            'id'=>'g1',
            'title'=>'サービス全体について',
            'questions'=>[[
                'id'=>'q1',
                'text'=>'サービス全体の満足度を教えてください。',
                'type'=>'single',
                'required'=>true,
                'options'=>['とても満足','満足','普通','不満'],
                'branch'=>[]
            ]]
        ]]
    ]]);
}
function surveyById(string $id): ?array {
    foreach (surveys() as $s) if (($s['id'] ?? '') === $id) return $s;
    return null;
}
function saveSurvey(array $survey): void {
    $all = surveys();
    $found = false;
    foreach ($all as $i => $s) {
        if (($s['id'] ?? '') === $survey['id']) {
            $all[$i] = $survey;
            $found = true;
            break;
        }
    }
    if (!$found) $all[] = $survey;
    if (!save('surveys', $all)) throw new RuntimeException('アンケートを保存できません。');
}
function statusLabel(string $s): string {
    return ['draft'=>'下書き','published'=>'公開中','stopped'=>'停止','ended'=>'終了'][$s] ?? $s;
}
function normalizeStatus(array &$s): void {
    if (($s['status'] ?? '') === 'published' &&
        !empty($s['endAt']) && strtotime((string)$s['endAt']) < time()) {
        $s['status'] = 'ended';
        saveSurvey($s);
    }
}
function questionNumbers(array $groups, string $mode): array {
    $r=[];$n=0;
    foreach ($groups as $gi=>$g) {
        $q=0;
        foreach ($g['questions'] ?? [] as $v) {
            $n++;$q++;
            $r[$v['id']]=$mode==='group'?'Q'.($gi+1).'-'.$q:'Q'.$n;
        }
    }
    return $r;
}
function kintoneConfig(): array { return data('kintone', []); }
function mailConfig(): array { return data('mail', []); }

$screen = (string)getv('screen', 'list');
$id = (string)getv('id', '');
$body = '';

try {
    if ($sessionError) throw new RuntimeException('セッションを開始できません。');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        checkCsrf();
        $action = (string)post('action');

        if ($action === 'saveSurvey') {
            $old = $id ? surveyById((string)post('id')) : null;
            $s = $old ?: [
                'id'=>'survey-'.bin2hex(random_bytes(6)),
                'createdAt'=>date('Y-m-d'),
                'status'=>'draft',
                'groups'=>[]
            ];
            if ($old) normalizeStatus($s);
            if (($s['status'] ?? '') === 'ended') throw new RuntimeException('終了状態は編集できません。');
            $s['title']=trim((string)post('title'));
            $s['description']=trim((string)post('description'));
            $s['startAt']=(string)post('startAt');
            $s['endAt']=(string)post('endAt');
            $s['numbering']=(string)post('numbering','global');
            if ($s['title']==='' || mb_strlen($s['title'])>200) {
                throw new RuntimeException('タイトルを正しく入力してください。');
            }
            if ($s['startAt']!=='' && $s['endAt']!=='' &&
                strtotime($s['startAt']) >= strtotime($s['endAt'])) {
                throw new RuntimeException('終了日時は開始日時より後にしてください。');
            }
            $s['updatedAt']=date('Y-m-d');
            saveSurvey($s);
            flash('保存しました。');
            redirectScreen('list');
        }

        if ($action === 'status') {
            $s=surveyById((string)post('id'));
            if (!$s) throw new RuntimeException('対象アンケートがありません。');
            normalizeStatus($s);
            $to=(string)post('to');
            $allow=['draft'=>['published'],'published'=>['stopped'],
                'stopped'=>['published'],'ended'=>[]];
            if (!in_array($to,$allow[$s['status']]??[],true)) {
                throw new RuntimeException('状態変更できません。');
            }
            $s['status']=$to;$s['updatedAt']=date('Y-m-d');
            saveSurvey($s);flash('状態を変更しました。');redirectScreen('list');
        }

        if ($action === 'delete' || $action === 'duplicate') {
            $target=surveyById((string)post('id'));
            if (!$target) throw new RuntimeException('対象アンケートがありません。');
            $all=surveys();
            if ($action==='delete') {
                $all=array_values(array_filter($all,fn($x)=>$x['id']!==$target['id']));
                save('surveys',$all);flash('削除しました。');
            } else {
                $target['id']='survey-'.bin2hex(random_bytes(6));
                $target['title'].='（コピー）';$target['status']='draft';
                $target['createdAt']=date('Y-m-d');$target['updatedAt']=date('Y-m-d');
                $all[]=$target;save('surveys',$all);flash('複製しました。');
            }
            redirectScreen('list');
        }

        if ($action === 'answer') {
            $s=surveyById((string)post('id'));
            if (!$s) throw new RuntimeException('アンケートが存在しません。');
            normalizeStatus($s);
            if ($s['status']!=='published') throw new RuntimeException('現在回答できません。');
            $values=$_POST['answer']??[];
            $errors=[];
            foreach ($s['groups'] as $g) foreach ($g['questions'] as $qv) {
                if (($qv['required']??false) && empty($values[$qv['id']])) {
                    $errors[]='必須項目「'.$qv['text'].'」を回答してください。';
                }
            }
            if ($errors) {
                $_SESSION['answer_values']=$values;
                throw new RuntimeException($errors[0]);
            }
            $_SESSION['pending_answer']=[
                'survey'=>$s['id'],'values'=>$values
            ];
            redirectScreen('confirm',$s['id']);
        }

        if ($action === 'confirmAnswer') {
            $pending=$_SESSION['pending_answer']??null;
            if (!$pending || $pending['survey']!==(string)post('id')) {
                throw new RuntimeException('回答セッションがありません。');
            }
            $answers=data('answers',[]);
            $answers[]=[
                'id'=>bin2hex(random_bytes(10)),
                'survey'=>$pending['survey'],
                'createdAt'=>date('c'),
                'values'=>$pending['values']
            ];
            if (!save('answers',$answers)) throw new RuntimeException('回答を保存できません。');
            unset($_SESSION['pending_answer'],$_SESSION['answer_values']);
            redirectScreen('complete',(string)post('id'));
        }

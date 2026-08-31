<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5 / DBなし / PHP cURLなし
 * 単一エントリーポイント
 */

const DATA_DIR = __DIR__ . '/data';
const KEY_FILE = __DIR__ . '/.secrets/アンケートアプリ/secret.key';

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0700, true);
if (!is_dir(DATA_DIR . '/sessions')) @mkdir(DATA_DIR . '/sessions', 0700, true);

$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
$cookiePath = rtrim(dirname($scriptPath), '/');
$cookiePath = $cookiePath === '' ? '/' : $cookiePath . '/';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path(DATA_DIR . '/sessions');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    if (!session_start()) {
        http_response_code(500);
        exit('セッションを開始できません。');
    }
}

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
function getv(string $k, string $d=''): string {
    return trim((string)($_GET[$k] ?? $d));
}
function postv(string $k, string $d=''): string {
    return trim((string)($_POST[$k] ?? $d));
}
function data(string $name, array $default=[]): array {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) return $default;
    $f = DATA_DIR . '/' . $name . '.php';
    if (!is_file($f)) return $default;
    $v = include $f;
    return is_array($v) ? $v : $default;
}
function saveData(string $name, array $value): void {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) throw new RuntimeException('保存先が不正です。');
    $f = DATA_DIR . '/' . $name . '.php';
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $s = "<?php\nreturn " . var_export($value, true) . ";\n";
    if (file_put_contents($tmp, $s, LOCK_EX) === false || !rename($tmp, $f)) {
        @unlink($tmp);
        throw new RuntimeException('データ保存に失敗しました。');
    }
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}
function checkCsrf(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) throw new RuntimeException('セッションエラーです。');
    $token = postv('_csrf');
    if ($token === '' || empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $token)) {
        throw new RuntimeException('フォームの有効期限が切れました。画面を再読み込みしてください。');
    }
}
function flash(string $msg, string $type='success'): void {
    $_SESSION['flash'] = [$msg, $type];
}
function flashHtml(): string {
    if (empty($_SESSION['flash'])) return '';
    [$m, $t] = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="alert '.$t.'">'.h($m).'</div>';
}
function go(string $screen, string $id=''): never {
    $url = 'index.php?screen=' . rawurlencode($screen);
    if ($id !== '') $url .= '&id=' . rawurlencode($id);
    header('Location: ' . $url, true, 303);
    exit;
}
function key32(): string {
    if (!is_file(KEY_FILE) || !is_readable(KEY_FILE)) {
        throw new RuntimeException('暗号鍵設定エラーです。secret.keyを確認してください。');
    }
    $v = file_get_contents(KEY_FILE);
    if ($v === false) throw new RuntimeException('暗号鍵を読み込めません。');
    if (strlen($v) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        $v = base64_decode(trim($v), true) ?: '';
    }
    if (strlen($v) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('暗号鍵の長さが不正です。');
    }
    return $v;
}
function enc(string $v): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'ENC:v1:' . base64_encode($nonce) . ':' .
        base64_encode(sodium_crypto_secretbox($v, $nonce, key32()));
}
function dec(string $v): string {
    if (!str_starts_with($v, 'ENC:v1:')) throw new RuntimeException('暗号文形式が不正です。');
    $p = explode(':', $v, 4);
    if (count($p) !== 4) throw new RuntimeException('暗号文形式が不正です。');
    $n = base64_decode($p[2], true);
    $c = base64_decode($p[3], true);
    if ($n === false || $c === false) throw new RuntimeException('暗号文を復元できません。');
    $v = sodium_crypto_secretbox_open($c, $n, key32());
    if ($v === false) throw new RuntimeException('暗号化データを復号できません。');
    return $v;
}
function surveys(): array {
    return data('surveys', [[
        'id'=>'survey-001','title'=>'2026年度 顧客満足度アンケート',
        'description'=>'サービスについてのご意見をお聞かせください。',
        'createdAt'=>'2026-08-01','updatedAt'=>'2026-08-25',
        'startAt'=>'2026-08-01T09:00','endAt'=>'2026-09-20T18:00',
        'status'=>'published','numbering'=>'global',
        'groups'=>[[
            'id'=>'g1','title'=>'サービス全体について','questions'=>[[
                'id'=>'q1','text'=>'サービス全体の満足度を教えてください。',
                'type'=>'single','required'=>true,
                'options'=>['とても満足','満足','普通','不満']
            ]]
        ]]
    ]]);
}
function survey(string $id): ?array {
    foreach (surveys() as $s) if (($s['id'] ?? '') === $id) return $s;
    return null;
}
function saveSurvey(array $s): void {
    $all = surveys(); $found = false;
    foreach ($all as &$x) {
        if (($x['id'] ?? '') === ($s['id'] ?? '')) {
            $x = $s; $found = true; break;
        }
    }
    if (!$found) $all[] = $s;
    saveData('surveys', $all);
}
function statusLabel(string $s): string {
    return ['draft'=>'下書き','published'=>'公開中','stopped'=>'停止','ended'=>'終了'][$s] ?? $s;
}
function normalizeSurvey(array $s): array {
    if (($s['status'] ?? '') === 'published' &&
        !empty($s['endAt']) && strtotime((string)$s['endAt']) < time()) {
        $s['status'] = 'ended';
        saveSurvey($s);
    }
    return $s;
}

/* 外部HTTP通信。リダイレクトを追従せず、302/303を呼出元へ返す。 */
function httpApi(string $url, string $method='GET', array $headers=[], ?string $body=null, ?string $proxy=null, bool $verify=true): array {
    $opts = [
        'http'=>[
            'method'=>$method,
            'timeout'=>15,
            'ignore_errors'=>true,
            'follow_location'=>0,
            'max_redirects'=>0,
            'header'=>implode("\r\n",$headers)
        ],
        'ssl'=>[
            'verify_peer'=>$verify,
            'verify_peer_name'=>$verify
        ]
    ];
    if ($body !== null) {
        $opts['http']['header'] .= "\r\nContent-Type: application/json";
        $opts['http']['content'] = $body;
    }
    if ($proxy !== null && $proxy !== '') {
        if (!preg_match('/^[A-Za-z0-9.-]+:\d{1,5}$/',$proxy)) throw new RuntimeException('Proxy形式が不正です。');
        $opts['http']['proxy'] = 'tcp://' . $proxy;
        $opts['http']['request_fulluri'] = true;
    }
    $ctx = stream_context_create($opts);
    $bodyOut = @file_get_contents($url, false, $ctx);
    $headersOut = $http_response_header ?? [];
    $code = 0;
    foreach ($headersOut as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m)) $code = (int)$m[1];
    }
    return ['code'=>$code,'body'=>$bodyOut===false?'':$bodyOut,'headers'=>$headersOut];
}
function kintoneConfig(): array {
    $c = data('kintone');
    if (empty($c['subdomain']) || empty($c['appId']) || empty($c['username']) || empty($c['password'])) {
        throw new RuntimeException('kintone設定が不足しています。');
    }
    $host = preg_replace('#^https?://#','',rtrim((string)$c['subdomain'],'/'));
    if (!preg_match('/^[A-Za-z0-9.-]+\.cybozu\.com$/',$host)) throw new RuntimeException('kintoneサブドメインが不正です。');
    $pw = dec((string)$c['password']);
    return [$host,(int)$c['appId'],(string)$c['username'],$pw,(string)($c['proxy']??''),!empty($c['verify'])];
}
function kintone(string $path): array {
    [$host,$app,$user,$pw,$proxy,$verify] = kintoneConfig();
    $auth = base64_encode($user . ':' . $pw);
    return httpApi('https://'.$host.$path,'GET',['X-Cybozu-Authorization: '.$auth],null,$proxy,$verify);
}
function smtpRead($fp): string {
    $r=''; $start=microtime(true);
    while (!feof($fp) && microtime(true)-$start<15) {
        $line=fgets($fp,4096); if ($line===false) break; $r.=$line;
        if (preg_match('/^\d{3} /',$line)) break;
    }
    return trim($r);
}
function smtpCmd($fp,string $cmd): string {
    fwrite($fp,$cmd."\r\n");
    return smtpRead($fp);
}
function smtpConnect(bool $send=false, ?array $mail=null, string $to='', string $subject='', string $body=''): void {
    $c = $mail ?? data('mail');
    if (empty($c['host']) || empty($c['port'])) throw new RuntimeException('SMTP設定が不足しています。');
    $host = (string)$c['host']; $port=(int)$c['port'];
    $encType=(string)($c['encryption']??'none');
    $target = $encType==='ssl' ? 'ssl://'.$host.':'.$port : 'tcp://'.$host.':'.$port;
    $fp=@stream_socket_client($target,$errno,$errstr,10,STREAM_CLIENT_CONNECT);
    if (!$fp) throw new RuntimeException('SMTP接続失敗: '.$errstr);
    stream_set_timeout($fp,15);
    $r=smtpRead($fp); if (!str_starts_with($r,'220')) throw new RuntimeException('SMTP応答エラー: '.$r);
    $r=smtpCmd($fp,'EHLO localhost'); if (!str_starts_with($r,'250')) throw new RuntimeException('SMTP EHLOエラー: '.$r);
    if ($encType==='tls') {
        $r=smtpCmd($fp,'STARTTLS'); if (!str_starts_with($r,'220')) throw new RuntimeException('SMTP STARTTLSエラー: '.$r);
        if (!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS接続に失敗しました。');
        $r=smtpCmd($fp,'EHLO localhost');
    }
    if (!empty($c['username'])) {
        $pw=dec((string)$c['password']);
        $r=smtpCmd($fp,'AUTH LOGIN'); if (!str_starts_with($r,'334')) throw new RuntimeException('SMTP認証開始失敗: '.$r);
        $r=smtpCmd($fp,base64_encode((string)$c['username']));
        $r=smtpCmd($fp,base64_encode($pw));
        if (!str_starts_with($r,'235')) throw new RuntimeException('SMTP認証失敗: '.$r);
    }
    if ($send) {
        if (!filter_var($to,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('宛先メールアドレスが不正です。');
        $from=(string)$c['from']; if (!filter_var($from,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('送信元メールアドレスが不正です。');
        $r=smtpCmd($fp,'MAIL FROM:<'.$from.'>'); if (!str_starts_with($r,'250')) throw new RuntimeException('SMTP MAIL FROMエラー: '.$r);
        $r=smtpCmd($fp,'RCPT TO:<'.$to.'>'); if (!str_starts_with($r,'250')&&!str_starts_with($r,'251')) throw new RuntimeException('SMTP RCPT TOエラー: '.$r);
        $r=smtpCmd($fp,'DATA'); if (!str_starts_with($r,'354')) throw new RuntimeException('SMTP DATAエラー: '.$r);
        $msg='From: '.$from."\r\n";
        if (!empty($c['fromName'])) $msg='From: '.mb_encode_mimeheader((string)$c['fromName']).' <'.$from.">\r\n";
        if (!empty($c['replyTo'])) $msg.='Reply-To: '.$c['replyTo']."\r\n";
        $msg.='To: '.$to."\r\nSubject: ".mb_encode_mimeheader($subject)."\r\nContent-Type: text/plain; charset=UTF-8' . "\r\n\r\n".$body."\r\n.";
        $r=smtpCmd($fp,$msg); if (!str_starts_with($r,'250')) throw new RuntimeException('SMTP送信失敗: '.$r);
    }
    @smtpCmd($fp,'QUIT'); fclose($fp);
}

$screen=getv('screen','list'); $id=getv('id'); $body='';
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        checkCsrf();
        $act=postv('action');

        if ($act==='saveSurvey') {
            $s=survey(postv('id')) ?? [
                'id'=>'survey-'.bin2hex(random_bytes(5)),
                'createdAt'=>date('Y-m-d'),'status'=>'draft','groups'=>[]
            ];
            $s['title']=postv('title'); $s['description']=postv('description');
            $s['startAt']=postv('startAt'); $s['endAt']=postv('endAt');
            $s['numbering']=postv('numbering','global'); $s['updatedAt']=date('Y-m-d');
            if ($s['title']===''||mb_strlen($s['title'])>200) throw new RuntimeException('タイトルを正しく入力してください。');
            saveSurvey($s); flash('保存しました。'); go('list');
        }

        if ($act==='status') {
            $s=survey(postv('id')); if (!$s) throw new RuntimeException('アンケートが存在しません。');
            $to=postv('to'); $map=['draft'=>['published'],'published'=>['stopped'],'stopped'=>['published']];
            if ($s['status']==='ended'||!in_array($to,$map[$s['status']]??[],true)) throw new RuntimeException('状態変更できません。');
            $s['status']=$to; $s['updatedAt']=date('Y-m-d'); saveSurvey($s); flash('状態を変更しました。'); go('list');
        }

        if ($act==='delete'||$act==='duplicate') {
            $s=survey(postv('id')); if (!$s) throw new RuntimeException('対象がありません。');
            if ($act==='delete') {
                saveData('surveys',array_values(array_filter(surveys(),fn($x)=>$x['id']!==$s['id'])));
                flash('削除しました。');
            } else {
                $s['id']='survey-'.bin2hex(random_bytes(5)); $s['title'].='（コピー）';
                $s['status']='draft'; $s['createdAt']=date('Y-m-d'); $s['updatedAt']=date('Y-m-d');
                saveSurvey($s); flash('複製しました。');
            }
            go('list');
        }

        if ($act==='answer') {
            $s=survey(postv('id')); if (!$s) throw new RuntimeException('アンケートが存在しません。');
            $values=$_POST['answer']??[];
            $answers=data('answers');
            $answers[]=['id'=>bin2hex(random_bytes(10)),'survey'=>$s['id'],'createdAt'=>date('c'),'values'=>$values];
            saveData('answers',$answers); go('complete',$s['id']);
        }

        if (in_array($act,['kintoneTest','kintoneFields','kintoneSync'],true)) {
            $path=$act==='kintoneFields'
                ? '/k/v1/app/form/fields.json?app='.(int)postv('appId')
                : ($act==='kintoneSync'
                    ? '/k/v1/records.json?app='.(int)postv('appId')
                    : '/k/v1/app.json?id='.(int)postv('appId'));
            $r=kintone($path);
            if ($r['code']===302||$r['code']===303) throw new RuntimeException('kintoneからリダイレクト応答が返されました。設定を確認してください。');
            if ($r['code']<200||$r['code']>=300||$r['body']==='') {
                $j=json_decode($r['body'],true); $detail=$j['message']??'レスポンスを取得できませんでした。';
                throw new RuntimeException('kintone通信失敗 HTTP '.$r['code'].' '.$detail);
            }
            if ($act==='kintoneFields') saveData('kintone_fields',json_decode($r['body'],true)?:[]);
            if ($act==='kintoneSync') {
                $j=json_decode($r['body'],true)?:[]; $rows=[];
                foreach (($j['records']??[]) as $rec) {
                    $get=function($k)use($rec){return (string)($rec[$k]['value']??'');};
                    $rows[]=['id'=>count($rows)+1,'name'=>$get('氏名'),'email'=>$get('メールアドレス'),
                        'department'=>$get('部署名'),'phone'=>$get('電話番号'),'address'=>$get('住所')];
                }
                saveData('customers',$rows);
            }
            flash($act==='kintoneTest'?'kintone接続成功。':($act==='kintoneFields'?'項目一覧を取得しました。':'顧客情報を同期しました。'));
            go('kintone');
        }

        if ($act==='kintoneSave') {
            $host=preg_replace('#^https?://#','',rtrim(postv('subdomain'),'/'));
            if (!preg_match('/^[A-Za-z0-9.-]+\.cybozu\.com$/',$host)) throw new RuntimeException('kintoneサブドメインが不正です。');
            if ((int)postv('appId')<1) throw new RuntimeException('アプリIDが不正です。');
            $c=data('kintone'); $c['subdomain']=$host; $c['appId']=(int)postv('appId');
            $c['username']=postv('username'); $c['proxy']=postv('proxy'); $c['verify']=postv('verify')==='1';
            if (postv('password')!=='') $c['password']=enc(postv('password'));
            saveData('kintone',$c); flash('kintone設定を保存しました。'); go('kintone');
        }

        if ($act==='mailSave') {
            $c=data('mail');
            foreach(['host','port','encryption','username','from','fromName','replyTo'] as $k) $c[$k]=postv($k);
            if (!filter_var($c['from'],FILTER_VALIDATE_EMAIL)) throw new RuntimeException('送信元メールアドレスが不正です。');
            if ($c['replyTo']!==''&&!filter_var($c['replyTo'],FILTER_VALIDATE_EMAIL)) throw new RuntimeException('返信先メールアドレスが不正です。');
            if (postv('password')!=='') $c['password']=enc(postv('password'));
            saveData('mail',$c); flash('SMTP設定を保存しました。'); go('mail');
        }

        if ($act==='mailTest'||$act==='mailSendTest') {
            $c=data('mail'); smtpConnect(false,$c);
            flash('SMTP接続成功。認証まで確認しました。'); go('mail');
        }

        if ($act==='sendMail') {
            $s=survey(postv('id')); if (!$s) throw new RuntimeException('対象アンケートが存在しません。');
            $to=postv('to'); $subject=postv('subject'); $text=postv('body');
            $text=str_replace('{アンケートURL}','index.php?screen=answer&id='.rawurlencode($s['id']),$text);
            smtpConnect(true,data('mail'),$to,$subject,$text);
            $h=data('mail_history'); $h[]=['survey'=>$s['id'],'to'=>$to,'createdAt'=>date('c'),'subject'=>$subject];
            saveData('mail_history',$h); flash('メールを送信しました。'); go('send',$s['id']);
        }
    }
} catch (Throwable $e) {
    flash($e->getMessage(),'danger');
}

function css(): string {
return <<<'CSS'
:root{--primary:#2563eb;--primary-dark:#1d4ed8;--success:#16a34a;--warning:#d97706;--danger:#dc2626;--gray:#64748b;--border:#dbe2ea;--text:#1e293b;--shadow:0 4px 18px rgba(15,23,42,.08)}
*{box-sizing:border-box}body{margin:0;background:#f8fafc;color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif}
header{background:#0f172a;color:#fff;min-height:64px;padding:0 24px;display:flex;align-items:center;gap:30px}header nav{display:flex;gap:6px}header a{color:#cbd5e1;text-decoration:none;padding:9px 13px;border-radius:7px}
main{max-width:1500px;margin:auto;padding:28px}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);padding:20px;margin-bottom:20px}
.bar,.actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}.full{grid-column:1/-1}
input,textarea,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:7px;background:#fff}textarea{min-height:110px}
.btn{display:inline-block;padding:9px 14px;border:1px solid var(--border);border-radius:7px;background:#fff;color:var(--text);cursor:pointer;text-decoration:none}
.primary{background:var(--primary);border-color:var(--primary);color:#fff}.success{background:var(--success);border-color:var(--success);color:#fff}.danger{background:var(--danger);border-color:var(--danger);color:#fff}.warning{background:var(--warning);border-color:var(--warning);color:#fff}
.badge{padding:4px 9px;border-radius:99px;font-size:12px;background:#e2e8f0}.published{background:#dcfce7;color:#166534}.stopped{background:#fef3c7;color:#92400e}.ended{background:#fee2e2;color:#991b1b}
.alert{padding:12px;border-radius:8px;margin-bottom:16px}.alert.success{background:#dcfce7;color:#166534}.alert.danger{background:#fee2e2;color:#991b1b}
.table{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{padding:12px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:13px}
.q{background:#fff;border:1px solid var(--border);border-radius:12px;padding:20px;margin:15px 0}.option{display:block;padding:12px;border:1px solid #cbd5e1;border-radius:8px;margin:8px 0}
.rmain{max-width:760px;margin:auto}.respondent{padding:15px}.drag{cursor:move}.muted{color:var(--gray)}
@media(max-width:800px){header{padding:10px 14px;flex-wrap:wrap}.grid{grid-template-columns:1fr}.full{grid-column:auto}main{padding:16px}.btn{min-height:42px}.option{padding:15px}}
CSS;
}
function shell(string $title,string $body,bool $admin=true): string {
    if (!$admin) return '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).'</title><style>'.css().'</style><div class="respondent"><main class="rmain">'.$body.'</main></div>';
    return '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).'</title><style>'.css().'</style><header><b>アンケート管理システム</b><nav><a href="?screen=list">一覧</a><a href="?screen=kintone">kintone</a><a href="?screen=mail">メール</a></nav></header><main>'.$body.'</main>';
}

$body=flashHtml();

if ($screen==='list') {
    $ss=array_map('normalizeSurvey',surveys()); $search=getv('q'); $filter=getv('status');
    if ($search!=='') $ss=array_values(array_filter($ss,fn($s)=>mb_stripos($s['title'],$search)!==false));
    $map=['公開中'=>'published','下書き'=>'draft','停止'=>'stopped','終了'=>'ended'];
    if ($filter!==''&&isset($map[$filter])) $ss=array_values(array_filter($ss,fn($s)=>$s['status']===$map[$filter]));
    usort($ss,fn($a,$b)=>strcmp($b['updatedAt']??'',$a['updatedAt']??''));
    $body.='<div class="bar" style="justify-content:space-between"><div><h1>アンケート一覧</h1><p>アンケートの作成・公開・集計・送信を管理します。</p></div><a class="btn primary" href="?screen=edit">＋ 新規作成</a></div>';
    $body.='<div class="card"><form class="bar"><input name="q" value="'.h($search).'" placeholder="タイトルを検索（Enter）"><input type="hidden" name="screen" value="list"><select name="status"><option value="">すべて</option><option>公開中</option><option>下書き</option><option>停止</option><option>終了</option></select></form><div class="table"><table><tr><th>タイトル</th><th>作成日</th><th>更新日</th><th>期間</th><th>状態</th><th>回答数</th><th>操作</th></tr>';
    $answers=data('answers');
    foreach($ss as $s){$n=count(array_filter($answers,fn($a)=>($a['survey']??'')===$s['id']));$body.='<tr><td><b>'.h($s['title']).'</b></td><td>'.h($s['createdAt']).'</td><td>'.h($s['updatedAt']).'</td><td>'.h($s['startAt']).' ～ '.h($s['endAt']).'</td><td><span class="badge '.h($s['status']).'">'.h(statusLabel($s['status'])).'</span></td><td>'.$n.'</td><td class="bar"><a class="btn" href="?screen=edit&id='.rawurlencode($s['id']).'">確認・編集</a><a class="btn" href="?screen=analytics&id='.rawurlencode($s['id']).'">集計</a><a class="btn" href="?screen=send&id='.rawurlencode($s['id']).'">送信</a><a class="btn" href="?screen=preview&id='.rawurlencode($s['id']).'">プレビュー</a></td></tr>';}
    $body.='</table></div></div>';
}
elseif ($screen==='edit') {
    $s=$id?survey($id):['id'=>'','title'=>'','description'=>'','startAt'=>'','endAt'=>'','numbering'=>'global','status'=>'draft','groups'=>[['id'=>'g1','title'=>'グループ1','questions'=>[]]]];
    if(!$s)$body.='<div class="alert danger">アンケートが存在しません。</div>';
    else {
        $body.='<div class="card"><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="saveSurvey"><input type="hidden" name="id" value="'.h($s['id']).'"><div class="bar" style="justify-content:space-between"><h1>アンケート作成・編集</h1><span class="badge '.h($s['status']).'">'.h(statusLabel($s['status'])).'</span></div><div class="grid"><label>アンケートタイトル<input name="title" value="'.h($s['title']).'" required></label><label>質問番号<select name="numbering"><option value="global"'.($s['numbering']==='global'?' selected':'').'>全体通番 Q1,Q2...</option><option value="group"'.($s['numbering']==='group'?' selected':'').'>グループ毎 Q1-1...</option></select></label><label class="full">説明<textarea name="description">'.h($s['description']).'</textarea></label><label>開始日時<input type="datetime-local" name="startAt" value="'.h($s['startAt']).'"></label><label>終了日時<input type="datetime-local" name="endAt" value="'.h($s['endAt']).'"></label></div><hr><h2>質問・グループ</h2>';
        foreach($s['groups'] as $gi=>$g){$body.='<div class="card drag"><b>グループ '.($gi+1).'</b><input name="groupTitle" value="'.h($g['title']).'" readonly>';foreach($g['questions'] as $qi=>$qv)$body.='<div class="q drag"><b>Q'.($qi+1).'</b><p>'.h($qv['text']).'</p><span class="badge">'.h($qv['type']).'</span> '.($qv['required']?'必須':'任意').'</div>';$body.='</div>';}
        $body.='<div class="actions"><button class="btn primary">保存して一覧へ</button><a class="btn" href="?screen=preview&id='.rawurlencode($s['id']).'">プレビュー</a><a class="btn" href="?screen=list" onclick="return confirm(\'編集内容を破棄しますか？\')">キャンセル</a></div></form></div>';
    }
}
elseif (in_array($screen,['preview','analytics','send'],true)) {
    $s=$id?survey($id):null;
    if(!$id||!$s){$body.='<div class="alert danger">対象アンケートが存在しません。</div>';}
    else {
        $s=normalizeSurvey($s);
        $body.='<div class="card"><b>対象アンケート</b><h1>'.h($s['title']).'</h1></div>';
        if($screen==='preview'){
            foreach($s['groups'] as $g)foreach($g['questions'] as $qv){$body.='<div class="card"><h3>'.h($qv['text']).'</h3>';foreach($qv['options']??[] as $o)$body.='<div class="option">'.h($o).'</div>';$body.='</div>';}
        }
        if($screen==='analytics'){
            $aa=array_values(array_filter(data('answers'),fn($a)=>($a['survey']??'')===$id));$n=count($aa);
            $body.='<div class="grid"><div class="card"><small>回答数</small><h2>'.$n.'</h2></div><div class="card"><small>回答率</small><h2>—</h2></div></div>';
            $body.='<div class="card"><h2>設問別集計</h2>'.($n?'回答データを表示できます。':'現在、回答データはありません').'</div>';
            $body.='<div class="actions"><a class="btn" href="?screen=analytics&id='.rawurlencode($id).'&export=csv">CSV</a><a class="btn" href="?screen=analytics&id='.rawurlencode($id).'&export=pdf">PDF</a></div>';
        }
        if($screen==='send'){
            $customers=data('customers',[['id'=>'C001','name'=>'山田 太郎','email'=>'test@example.com']]);
            $body.='<div class="card"><h2>顧客選択・メール送信</h2><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="sendMail"><input type="hidden" name="id" value="'.h($id).'"><div class="table"><table><tr><th>選択</th><th>顧客名</th><th>メール</th></tr>';
            foreach($customers as $c)$body.='<tr><td><input type="radio" name="to" value="'.h($c['email']).'" required></td><td>'.h($c['name']).'</td><td>'.h($c['email']).'</td></tr>';
            $body.='</table></div><div class="grid"><label>件名<input name="subject" value="'.h($s['title']).'"></label><label>本文<textarea name="body">アンケートへのご協力をお願いいたします。&#10;{アンケートURL}</textarea></label></div><button class="btn primary" onclick="return confirm(\'一括送信しますか？\')">送信</button></form></div>';
            $body.='<div class="card"><h2>送信履歴</h2>';
            foreach(array_reverse(data('mail_history')) as $hrow)if(($hrow['survey']??'')===$id)$body.='<p>'.h($hrow['createdAt']).' / '.h($hrow['to']).' / '.h($hrow['subject']).'</p>';
            $body.='</div>';
        }
    }
}
elseif($screen==='kintone'||$screen==='mail'){
    $k=$screen==='kintone';$c=data($k?'kintone':'mail');
    $body.='<div class="card"><h1>'.($k?'kintone連携設定':'メールサーバ設定').'</h1><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf()).'"><div class="grid">';
    if($k){
        $body.='<label>サブドメイン<input name="subdomain" value="'.h($c['subdomain']??'') .'"></label><label>顧客管理アプリID<input name="appId" type="number" value="'.h((string)($c['appId']??'' )).'"></label><label>ログイン名<input name="username" value="'.h($c['username']??'').'"></label><label>パスワード<input name="password" type="password" placeholder="'.(!empty($c['password'])?'設定済み':'').'"></label><label>Proxy<input name="proxy" placeholder="host:port" value="'.h($c['proxy']??'').'"></label><label>SSL証明書検証<select name="verify"><option value="0">無効</option><option value="1"'.(!empty($c['verify'])?' selected':'').'>有効</option></select></label></div><div class="actions"><button name="action" value="kintoneSave" class="btn primary">設定保存</button><button name="action" value="kintoneTest" class="btn success">接続テスト</button><button name="action" value="kintoneFields" class="btn">項目一覧を再取得</button><button name="action" value="kintoneSync" class="btn">顧客情報を同期</button>';
    }else{
        $body.='<label>SMTPサーバ<input name="host" value="'.h($c['host']??'').'"></label><label>SMTPポート<input name="port" value="'.h($c['port']??'587').'"></label><label>暗号化<select name="encryption"><option value="tls">TLS</option><option value="ssl"'.(($c['encryption']??'')==='ssl'?' selected':'').'>SSL</option><option value="none"'.(($c['encryption']??'')==='none'?' selected':'').'>なし</option></select></label><label>SMTP認証ユーザー名<input name="username" value="'.h($c['username']??'').'"></label><label>SMTPパスワード<input name="password" type="password" placeholder="'.(!empty($c['password'])?'設定済み':'').'"></label><label>送信元メール<input name="from" type="email" value="'.h($c['from']??'').'"></label><label>送信元名<input name="fromName" value="'.h($c['fromName']??'').'"></label><label>返信先メール<input name="replyTo" type="email" value="'.h($c['replyTo']??'').'"></label></div><div class="actions"><button name="action" value="mailSave" class="btn primary">設定保存</button><button name="action" value="mailTest" class="btn success">接続テスト</button><button name="action" value="mailSendTest" class="btn">テストメール送信</button>';
    }
    $body.='</div></form></div>';
}
elseif(in_array($screen,['answer','confirm','complete'],true)){
    $s=$id?survey($id):null;
    if(!$s)$body='<div class="card"><h2>アンケートが存在しません。</h2></div>';
    elseif($screen==='complete')$body='<div class="card"><h1>回答完了</h1><p>ご回答ありがとうございました。</p></div>';
    else{
        $body='<div class="card"><h1>'.h($s['title']).'</h1><p>'.h($s['description']).'</p><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="answer"><input type="hidden" name="id" value="'.h($id).'">';
        foreach($s['groups'] as $g)foreach($g['questions'] as $qv){
            $body.='<div class="q"><h3>'.h($qv['text']).($qv['required']?'<small style="color:#dc2626"> 必須</small>':'').'</h3>';
            if($qv['type']==='text')$body.='<textarea name="answer['.h($qv['id']).'][]"></textarea>';
            else foreach($qv['options']??[] as $o)$body.='<label class="option"><input type="'.($qv['type']==='multi'?'checkbox':'radio').'" name="answer['.h($qv['id']).'][]" value="'.h($o).'"> '.h($o).'</label>';
            $body.='</div>';
        }
        $body.='<button class="btn primary" onclick="return confirm(\'回答を送信しますか？\')">回答送信</button></form></div>';
    }
}else{
    $body='<div class="card"><h1>画面が見つかりません</h1></div>';
}

echo shell('アンケート管理システム',$body,!in_array($screen,['answer','confirm','complete'],true));
?>

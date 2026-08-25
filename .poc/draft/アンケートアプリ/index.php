<?php
declare(strict_types=1);

/*
 * アンケート管理システム
 * Apache 2.4 / PHP 8.5
 * 単一ファイル構成
 *
 * 固定名称:
 * survey_storage_directory
 * survey_storage_file
 * survey_admin_session_v1
 */

const SURVEY_STORAGE_DIRECTORY = 'survey_storage_directory';
const SURVEY_STORAGE_FILE = 'survey_storage_file';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();
header_remove('X-Powered-By');

/* ============================================================
 * GUARD: 共通・ストレージ
 * ============================================================ */

function storageDir(): string { return __DIR__ . '/survey_storage'; }
function storageFile(): string { return storageDir() . '/survey_data.json'; }

function initialData(): array {
    return [
        'surveys'=>[],
        'responses'=>[],
        'customers'=>[],
        'settings'=>['kintone'=>[],'smtp'=>[]],
        'mail_logs'=>[]
    ];
}

function ensureStorage(): void {
    if (!is_dir(storageDir()) && !@mkdir(storageDir(),0775,true) && !is_dir(storageDir())) {
        throw new RuntimeException('保存先を作成できません。');
    }
}

function normalizeData(array $d): array {
    $base=initialData();
    foreach ($base as $k=>$v) {
        if (!array_key_exists($k,$d)) $d[$k]=$v;
    }
    foreach (['surveys','responses','customers','mail_logs'] as $k) {
        if (!is_array($d[$k])) throw new RuntimeException('JSONデータ構造が不正です。');
    }
    if (!is_array($d['settings'])) throw new RuntimeException('settingsの構造が不正です。');
    foreach (['kintone','smtp'] as $k) {
        if (!isset($d['settings'][$k])) $d['settings'][$k]=[];
        if (!is_array($d['settings'][$k])) throw new RuntimeException('settingsの構造が不正です。');
    }
    return $d;
}

/* GUARD: 既存JSON破損時は空データへ勝手に初期化しない */
function loadData(): array {
    ensureStorage();
    $f=storageFile();

    if (!is_file($f)) {
        $d=initialData();
        if (!saveData($d)) throw new RuntimeException('初期データを保存できません。');
        return $d;
    }
    if (!is_readable($f)) throw new RuntimeException('survey_data.jsonを読み取れません。');

    $raw=@file_get_contents($f);
    if ($raw===false) throw new RuntimeException('survey_data.jsonの読み込みに失敗しました。');
    if (trim($raw)==='') throw new RuntimeException('survey_data.jsonが空です。');

    try { $d=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }
    catch(Throwable) { throw new RuntimeException('survey_data.jsonのJSON形式が壊れています。'); }
    if (!is_array($d)) throw new RuntimeException('survey_data.jsonのトップレベル構造が不正です。');
    return normalizeData($d);
}

/* GUARD: JSON検証→一時保存→再検証→バックアップ→rename */
function saveData(array $d): bool {
    try {
        $d=normalizeData($d);
        $json=json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        json_decode($json,true,512,JSON_THROW_ON_ERROR);
        ensureStorage();

        $tmp=storageDir().'/.survey_data.'.bin2hex(random_bytes(8)).'.tmp';
        if (@file_put_contents($tmp,$json,LOCK_EX)===false) return false;
        $check=@file_get_contents($tmp);
        if ($check===false) { @unlink($tmp); return false; }
        json_decode($check,true,512,JSON_THROW_ON_ERROR);

        $f=storageFile();
        if (is_file($f)) @copy($f,$f.'.bak');
        if (!@rename($tmp,$f)) { @unlink($tmp); return false; }
        return true;
    } catch(Throwable) {
        if (isset($tmp)) @unlink($tmp);
        return false;
    }
}

function jsonResponse(array $d,int $status=200): never {
    while(ob_get_level()>0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    } catch(Throwable) {
        echo '{"ok":false,"message":"JSON応答の生成に失敗しました。","error_type":"server_error"}';
    }
    exit;
}

function apiError(string $message,string $type='server_error',int $status=500,array $checks=[]): never {
    jsonResponse([
        'ok'=>false,'message'=>$message,'error_type'=>$type,
        'http_status'=>$status,'check_items'=>$checks,
        'request_action'=>$_POST['action']??$_GET['action']??''
    ],$status);
}

function id(string $p): string { return $p.'_'.date('YmdHis').'_'.bin2hex(random_bytes(5)); }
function post(string $k,mixed $d=''): mixed { return $_POST[$k]??$d; }
function arrPost(string $k): array {
    $v=post($k,[]);
    if (is_string($v)) { $x=json_decode($v,true); return is_array($x)?$x:[]; }
    return is_array($v)?$v:[];
}
function h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

/* ============================================================
 * GUARD: CSRF / 管理セッション
 * ============================================================ */

function csrf(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function isAdmin(): bool {
    return !empty($_SESSION['admin_authenticated']);
}

function requireAdmin(): void {
    if (!isAdmin()) apiError('ログインセッションが切れています。','authentication',401,['管理画面へ再ログインしてください。']);
}

function requireCsrf(): void {
    $t=(string)post('csrf_token');
    if (!hash_equals(csrf(),$t)) apiError('CSRFトークンが不正です。','csrf',403,['ページを再読み込みして再試行してください。']);
}

function currentUrl(): string {
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    $host=$_SERVER['HTTP_HOST']??'localhost';
    $path=$_SERVER['SCRIPT_NAME']??'/index.php';
    return $scheme.'://'.$host.$path;
}

/*
 * 初回表示は管理画面ログインを兼ねる。
 * 本番環境ではWebサーバー認証等と併用することを推奨。
 */
if (isset($_POST['action']) && $_POST['action']==='login') {
    if (hash_equals((string)($_SESSION['login_nonce']??''),(string)post('login_nonce'))) {
        $_SESSION['admin_authenticated']=true;
        jsonResponse(['ok'=>true,'csrf_token'=>csrf()]);
    }
    apiError('ログイン処理に失敗しました。','authentication',401);
}

if (isset($_GET['action']) && $_GET['action']==='logout') {
    $_SESSION=[];
    session_destroy();
    jsonResponse(['ok'=>true]);
}

if (!isset($_SESSION['login_nonce'])) $_SESSION['login_nonce']=bin2hex(random_bytes(16));

/* ============================================================
 * GUARD: kintone設定
 * ============================================================ */

/*
 * 以下を許可:
 * xxxx
 * xxxx.cybozu.com
 * https://xxxx.cybozu.com
 */
function safeSubdomain(string $v): ?string {
    $v=trim($v);
    $v=preg_replace('#^https?://#i','',$v);
    $v=preg_replace('#/.*$#','',$v);
    $v=strtolower(trim((string)$v));

    if (str_ends_with($v,'.cybozu.com')) $v=substr($v,0,-11);
    if ($v===''||strlen($v)>63||!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?[a-z0-9]?$/',$v)) return null;
    return $v;
}

/*
 * host:port
 */
function safeProxy(string $v): ?string {
    $v=trim($v);
    if ($v==='') return '';
    if (!preg_match('/^(?:[a-zA-Z0-9.-]+|\[[0-9a-fA-F:]+\]):([0-9]{1,5})$/',$v,$m)) return null;
    return ((int)$m[1]>=1&&(int)$m[1]<=65535)?$v:null;
}

function validateKintone(array $in,array $old): array {
    $sub=safeSubdomain((string)($in['subdomain']??''));
    if ($sub===null) return ['ok'=>false,'message'=>'サブドメインは xxxx、xxxx.cybozu.com、https://xxxx.cybozu.com のいずれかで入力してください。'];

    $login=trim((string)($in['login_name']??''));
    if ($login==='') return ['ok'=>false,'message'=>'ログイン名を入力してください。'];

    $pass=(string)($in['password']??'');
    if ($pass===''&&!empty($old['password'])) $pass=(string)$old['password'];
    if ($pass==='') return ['ok'=>false,'message'=>'パスワードを入力してください。'];

    $app=filter_var($in['app_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($app===false) return ['ok'=>false,'message'=>'顧客管理アプリIDは1以上の整数で入力してください。'];

    $proxy=safeProxy((string)($in['proxy']??''));
    if ($proxy===null) return ['ok'=>false,'message'=>'Proxyは host名:port番号 の形式で入力してください。'];

    return ['ok'=>true,'data'=>[
        'subdomain'=>$sub,'login_name'=>$login,'password'=>$pass,'app_id'=>(int)$app,
        'ssl_verify'=>!empty($in['ssl_verify']),'proxy'=>$proxy,
        'field_company'=>trim((string)($in['field_company']??'')),
        'field_name'=>trim((string)($in['field_name']??'')),
        'field_email'=>trim((string)($in['field_email']??'')),
        'field_department'=>trim((string)($in['field_department']??'')),
        'field_phone'=>trim((string)($in['field_phone']??'')),
        'field_address'=>trim((string)($in['field_address']??''))
    ]];
}

function publicSettings(array $s): array {
    $r=$s;
    if (isset($r['kintone']['password'])) {
        unset($r['kintone']['password']);
        $r['kintone']['password_configured']=true;
    } else $r['kintone']['password_configured']=false;

    if (isset($r['smtp']['smtp_password'])) {
        unset($r['smtp']['smtp_password']);
        $r['smtp']['smtp_password_configured']=true;
    } else $r['smtp']['smtp_password_configured']=false;
    return $r;
}

function kurl(array $c,string $path): string {
    return 'https://'.$c['subdomain'].'.cybozu.com/k/v1/'.ltrim($path,'/');
}

function ktype(int $status,int $errno): string {
    if ($errno===CURLE_COULDNT_RESOLVE_HOST) return 'dns';
    if ($errno===CURLE_COULDNT_CONNECT) return 'connection';
    if ($errno===CURLE_OPERATION_TIMEDOUT) return 'timeout';
    if ($errno===CURLE_SSL_CONNECT_ERROR) return 'tls';
    if ($status===401) return 'authentication';
    if ($status===403) return 'authorization';
    if ($status>=400&&$status<500) return 'http_4xx';
    if ($status>=500) return 'http_5xx';
    return 'api';
}

function kchecks(string $t): array {
    return match($t) {
        'dns'=>['サブドメイン','DNS設定'],
        'authentication'=>['ログイン名','パスワード','kintone側の認証設定'],
        'authorization'=>['ログインユーザーの権限','対象アプリへのアクセス権'],
        'tls'=>['SSL証明書検証','サーバー側TLS設定'],
        'proxy'=>['Proxyホスト','Proxyポート','ネットワーク設定'],
        'timeout'=>['ネットワーク接続','Proxy','タイムアウト設定'],
        default=>['サブドメイン','ログイン名','顧客管理アプリID','kintone側の権限','Proxy','SSL証明書検証']
    };
}

function krequest(array $c,string $method,string $path,?array $body=null): array {
    $ch=curl_init(kurl($c,$path));
    if (!$ch) return ['ok'=>false,'error_type'=>'connection','http_status'=>0,'message'=>'kintone通信の初期化に失敗しました。','check_items'=>['PHP cURL拡張']];

    $o=[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json'],
        CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_SSL_VERIFYPEER=>!empty($c['ssl_verify']),
        CURLOPT_SSL_VERIFYHOST=>!empty($c['ssl_verify'])?2:0,
        CURLOPT_USERPWD=>$c['login_name'].':'.$c['password']
    ];
    if (!empty($c['proxy'])) {
        [$ph,$pp]=explode(':',$c['proxy'],2);
        $o[CURLOPT_PROXY]=$ph;$o[CURLOPT_PROXYPORT]=(int)$pp;
    }
    if ($body!==null) $o[CURLOPT_POSTFIELDS]=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch,$o);
    $raw=curl_exec($ch);
    $errno=curl_errno($ch);
    $err=curl_error($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw===false) {
        $t=ktype($status,$errno);
        $msg=match($t) {
            'dns'=>'kintoneホストのDNS解決に失敗しました。',
            'connection'=>'kintoneサーバーへ接続できませんでした。',
            'timeout'=>'kintoneへの接続がタイムアウトしました。',
            'tls'=>'kintoneとのTLS/SSL接続に失敗しました。',
            default=>'kintoneとの通信に失敗しました。'
        };
        return ['ok'=>false,'error_type'=>$t,'http_status'=>$status,'message'=>$msg,'check_items'=>kchecks($t),'detail'=>$err];
    }

    $j=json_decode($raw,true);
    if ($status<200||$status>=300) {
        $t=ktype($status,0);
        $msg=match(true) {
            $status===401=>'kintone APIの認証に失敗しました。',
            $status===403=>'kintone APIへのアクセス権がありません。',
            $status===404=>'kintone APIまたは対象アプリが見つかりません。',
            $status===429=>'kintone APIのリクエスト制限に達しました。',
            $status>=500=>'kintoneサーバー側でエラーが発生しました。',
            default=>'kintone APIがエラーを返しました。'
        };
        return ['ok'=>false,'error_type'=>$t,'http_status'=>$status,'message'=>$msg,
            'api_summary'=>is_array($j)?mb_substr(trim((string)($j['message']??'')),0,500):'',
            'check_items'=>kchecks($t)];
    }
    return ['ok'=>true,'http_status'=>$status,'data'=>is_array($j)?$j:[]];
}

/* ============================================================
 * GUARD: SMTP
 * ============================================================ */

function validateSmtp(array $in,array $old): array {
    $server=trim((string)($in['smtp_server']??''));
    if ($server==='') return ['ok'=>false,'message'=>'SMTPサーバを入力してください。'];

    $port=filter_var($in['smtp_port']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
    if ($port===false) return ['ok'=>false,'message'=>'SMTPポートが不正です。'];

    $enc=(string)($in['smtp_encryption']??'none');
    if (!in_array($enc,['none','starttls','ssl'],true)) return ['ok'=>false,'message'=>'暗号化方式が不正です。'];

    $auth=!empty($in['smtp_auth']);
    $user=trim((string)($in['smtp_username']??''));
    $pass=(string)($in['smtp_password']??'');
    if ($pass===''&&!empty($old['smtp_password'])) $pass=(string)$old['smtp_password'];
    if ($auth&&$user==='') return ['ok'=>false,'message'=>'SMTP認証を有効にする場合はSMTPユーザー名が必要です。'];
    if ($auth&&$pass==='') return ['ok'=>false,'message'=>'SMTP認証を有効にする場合はSMTPパスワードが必要です。'];

    $from=trim((string)($in['smtp_from_email']??''));
    if (!filter_var($from,FILTER_VALIDATE_EMAIL)) return ['ok'=>false,'message'=>'送信元メールアドレスが不正です。'];

    $timeout=filter_var($in['smtp_timeout']??10,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>300]]);
    if ($timeout===false) return ['ok'=>false,'message'=>'接続タイムアウトは1～300秒で指定してください。'];

    return ['ok'=>true,'data'=>[
        'smtp_server'=>$server,'smtp_port'=>(int)$port,'smtp_encryption'=>$enc,
        'smtp_auth'=>$auth,'smtp_username'=>$user,'smtp_password'=>$pass,
        'smtp_from_email'=>$from,'smtp_from_name'=>trim((string)($in['smtp_from_name']??'')),
        'smtp_timeout'=>(int)$timeout
    ]];
}

function smtpRead($s): array {
    $r='';
    while (($line=fgets($s,4096))!==false) {
        $r.=$line;
        if (strlen($line)>=4&&$line[3]===' ') break;
    }
    preg_match('/^(\d{3})/',$r,$m);
    return ['code'=>(int)($m[1]??0),'response'=>trim(preg_replace('/\s+/',' ',$r))];
}

function smtpCmd($s,string $c): array { fwrite($s,$c."\r\n"); return smtpRead($s); }

function smtpConnect(array $c): array {
    $target=$c['smtp_encryption']==='ssl'?'ssl://'.$c['smtp_server']:$c['smtp_server'];
    $errno=0;$err='';
    $s=@stream_socket_client($target.':'.(int)$c['smtp_port'],$errno,$err,(int)$c['smtp_timeout'],STREAM_CLIENT_CONNECT);
    if (!$s) {
        $l=strtolower($err);
        $type=str_contains($l,'resolve')?'dns':(str_contains($l,'timeout')?'timeout':'connection');
        return ['ok'=>false,'error_type'=>$type,'smtp_code'=>null,'message'=>match($type){
            'dns'=>'SMTPサーバのDNS解決に失敗しました。','timeout'=>'SMTPサーバへの接続がタイムアウトしました。',
            default=>'SMTPサーバへ接続できませんでした。'
        },'detail'=>$err];
    }
    stream_set_timeout($s,(int)$c['smtp_timeout']);
    $g=smtpRead($s);
    if ($g['code']<200||$g['code']>=400) { fclose($s); return ['ok'=>false,'error_type'=>'smtp_response','smtp_code'=>$g['code'],'message'=>'SMTPサーバの接続応答が不正です。']; }

    $host=$_SERVER['SERVER_NAME']??'localhost';
    $e=smtpCmd($s,'EHLO '.$host);
    if ($e['code']>=400) $e=smtpCmd($s,'HELO '.$host);
    if ($e['code']>=400) { fclose($s); return ['ok'=>false,'error_type'=>'smtp_protocol','smtp_code'=>$e['code'],'message'=>'SMTPプロトコル応答に失敗しました。']; }

    if ($c['smtp_encryption']==='starttls') {
        $t=smtpCmd($s,'STARTTLS');
        if ($t['code']!==220||!stream_socket_enable_crypto($s,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($s); return ['ok'=>false,'error_type'=>'tls','smtp_code'=>$t['code'],'message'=>'SMTP STARTTLSの確立に失敗しました。'];
        }
        $e=smtpCmd($s,'EHLO '.$host);
        if ($e['code']>=400) { fclose($s); return ['ok'=>false,'error_type'=>'smtp_protocol','smtp_code'=>$e['code'],'message'=>'TLS後のEHLOに失敗しました。']; }
    }

    if (!empty($c['smtp_auth'])) {
        $a=smtpCmd($s,'AUTH LOGIN');
        if ($a['code']!==334) { fclose($s); return ['ok'=>false,'error_type'=>'authentication','smtp_code'=>$a['code'],'message'=>'SMTP認証を開始できませんでした。']; }
        $a=smtpCmd($s,base64_encode($c['smtp_username']));
        if ($a['code']!==334) { fclose($s); return ['ok'=>false,'error_type'=>'authentication','smtp_code'=>$a['code'],'message'=>'SMTPユーザー認証に失敗しました。']; }
        $a=smtpCmd($s,base64_encode($c['smtp_password']));
        if ($a['code']<200||$a['code']>=300) { fclose($s); return ['ok'=>false,'error_type'=>'authentication','smtp_code'=>$a['code'],'message'=>'SMTPパスワード認証に失敗しました。']; }
    }
    return ['ok'=>true,'socket'=>$s];
}

function smtpSend(array $c,string $to,string $subject,string $body): array {
    if (!filter_var($to,FILTER_VALIDATE_EMAIL)) return ['ok'=>false,'error_type'=>'configuration','message'=>'宛先メールアドレスが不正です。'];
    $x=smtpConnect($c);
    if (!$x['ok']) return $x;
    $s=$x['socket'];$from=$c['smtp_from_email'];
    foreach ([
        'MAIL FROM:<'.$from.'>',
        'RCPT TO:<'.$to'>'
    ] as $cmd) {
        $r=smtpCmd($s,$cmd);
        if ($r['code']<200||$r['code']>=400) { fclose($s); return ['ok'=>false,'error_type'=>'smtp_response','smtp_code'=>$r['code'],'message'=>'SMTPメール送信コマンドが拒否されました。']; }
    }
    $r=smtpCmd($s,'DATA');
    if ($r['code']!==354) { fclose($s); return ['ok'=>false,'error_type'=>'smtp_response','smtp_code'=>$r['code'],'message'=>'SMTP DATAコマンドが拒否されました。']; }

    $name=$c['smtp_from_name']!==''?$c['smtp_from_name'].' ': '';
    $msg='From: '.$name.'<'.$from.">\r\n".
         'To: <'.$to.">\r\n".
         'Subject: =?UTF-8?B?'.base64_encode($subject)."?=\r\n".
         "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".
         str_replace(["\r","\n"],["","\r\n"],$body)."\r\n.";
    $r=smtpCmd($s,$msg);
    smtpCmd($s,'QUIT');
    fclose($s);
    return ($r['code']>=200&&$r['code']<400)
        ?['ok'=>true,'smtp_code'=>$r['code']]
        :['ok'=>false,'error_type'=>'smtp_response','smtp_code'=>$r['code'],'message'=>'SMTPサーバがメールを受け付けませんでした。'];
}

/* ============================================================
 * GUARD: アンケート共通処理
 * ============================================================ */

function surveyStatus(string $s): bool { return in_array($s,['draft','active','ended'],true); }

function renumber(array &$survey): void {
    $n=1;
    foreach ($survey['groups']??[] as $gi=>&$g) {
        $q=1;
        foreach ($g['questions']??[] as &$question) {
            $question['number']=$survey['numbering_mode']==='group'
                ? 'Q'.$gi+1 . '-' . $q
                : 'Q'.$n;
            $question['id']=$question['id']??id('question');
            $q++;$n++;
        }
    }
}

function normalizeSurvey(array $s): array {
    $s['id']=$s['id']??id('survey');
    $s['title']=trim((string)($s['title']??'無題のアンケート'));
    $s['start_at']=(string)($s['start_at']??'');
    $s['end_at']=(string)($s['end_at']??'');
    $s['status']=(string)($s['status']??'draft');
    if (!surveyStatus($s['status'])) throw new InvalidArgumentException('ステータスが不正です。');
    $s['numbering_mode']=($s['numbering_mode']??'global')==='group'?'group':'global';
    $s['allow_general']=!empty($s['allow_general']);
    $s['groups']=is_array($s['groups']??null)?$s['groups']:[];
    foreach ($s['groups'] as &$g) {
        $g['id']=$g['id']??id('group');
        $g['name']=(string)($g['name']??'グループ');
        $g['questions']=is_array($g['questions']??null)?$g['questions']:[];
        foreach ($g['questions'] as &$q) {
            $q['id']=$q['id']??id('question');
            $q['type']=(string)($q['type']??'text');
            $q['text']=(string)($q['text']??'');
            $q['required']=!empty($q['required']);
            $q['options']=is_array($q['options']??null)?$q['options']:[];
            $q['other_enabled']=!empty($q['other_enabled']);
            $q['branching']=is_array($q['branching']??null)?$q['branching']:[];
        }
    }
    renumber($s);
    return $s;
}

function findSurvey(array &$d,string $id): ?array {
    foreach ($d['surveys'] as $i=>$s) if (($s['id']??'')===$id) return [$i,$s];
    return null;
}

function publicSurvey(array $s): array { return $s; }

/* ============================================================
 * GUARD: API
 * ============================================================ */

$isApi=isset($_GET['action'])||isset($_POST['action']);

if ($isApi) {
    try {
        $action=(string)(post('action',$_GET['action']??''));

        if ($action==='health_check') {
            $read=is_file(storageFile())&&is_readable(storageFile());
            $write=is_dir(storageDir())&&is_writable(storageDir());
            jsonResponse(['ok'=>$read||$write,'php'=>PHP_VERSION,'storage_exists'=>is_file(storageFile()),'storage_readable'=>$read,'storage_writable'=>$write]);
        }

        if ($action==='get_initial_data') {
            $d=loadData();
            /*
             * 初期データ取得は管理セッションがない場合でも実行可能。
             * 状態変更はrequireAdmin+CSRFで保護する。
             */
            jsonResponse(['ok'=>true,'data'=>[
                'surveys'=>$d['surveys'],
                'responses'=>$d['responses'],
                'customers'=>$d['customers'],
                'settings'=>publicSettings($d['settings']),
                'mail_logs'=>$d['mail_logs'],
                'csrf_token'=>csrf(),
                'authenticated'=>isAdmin()
            ]]);
        }

        if ($action==='login') {
            $_SESSION['admin_authenticated']=true;
            jsonResponse(['ok'=>true,'csrf_token'=>csrf()]);
        }

        if ($action==='logout') {
            $_SESSION=[];session_destroy();jsonResponse(['ok'=>true]);
        }

        requireAdmin();
        if ($_SERVER['REQUEST_METHOD']!=='POST') apiError('この操作にはPOSTが必要です。','method',405);
        requireCsrf();

        $d=loadData();

        switch($action) {

            case 'save_kintone_settings':
                $old=$d['settings']['kintone'];
                $v=validateKintone($_POST,$old);
                if (!$v['ok']) apiError($v['message'],'configuration',422);
                $d['settings']['kintone']=$v['data'];
                if (!saveData($d)) apiError('キントーン設定の保存に失敗しました。','storage_write');
                jsonResponse(['ok'=>true,'message'=>'キントーン設定を保存しました。','data'=>publicSettings($d['settings'])]);
            
            case 'save_smtp_settings':
                $old=$d['settings']['smtp'];
                $v=validateSmtp($_POST,$old);
                if (!$v['ok']) apiError($v['message'],'configuration',422);
                $d['settings']['smtp']=$v['data'];
                if (!saveData($d)) apiError('SMTP設定の保存に失敗しました。','storage_write');
                jsonResponse(['ok'=>true,'message'=>'SMTP設定を保存しました。','data'=>publicSettings($d['settings'])]);

            case 'connect_kintone':
                $c=$d['settings']['kintone'];
                if (empty($c['subdomain'])||empty($c['login_name'])||empty($c['password'])||empty($c['app_id'])) apiError('保存済みキントーン設定が不足しています。','configuration',422);
                $r=krequest($c,'GET','app.json?app='.rawurlencode((string)$c['app_id']));
                if (!$r['ok']) jsonResponse($r,502);
                jsonResponse(['ok'=>true,'message'=>'キントーンへの接続に成功しました。','http_status'=>$r['http_status']]);
            
            case 'fetch_kintone_fields':
                $c=$d['settings']['kintone'];
                if (empty($c['app_id'])) apiError('顧客管理アプリIDが設定されていません。','configuration',422);
                $r=krequest($c,'GET','app/form/fields.json?app='.rawurlencode((string)$c['app_id']));
                if (!$r['ok']) jsonResponse($r,502);
                $fields=[];
                foreach (($r['data']['properties']??[]) as $f) {
                    if (is_array($f)) $fields[]=['label'=>$f['label']??'','code'=>$f['code']??'','type'=>$f['type']??''];
                }
                jsonResponse(['ok'=>true,'http_status'=>$r['http_status'],'fields'=>$fields]);

            case 'sync_customers':
                $c=$d['settings']['kintone'];
                $r=krequest($c,'GET','records.json?app='.rawurlencode((string)$c['app_id']).'&query='.rawurlencode('limit 500'));
                if (!$r['ok']) jsonResponse($r,502);

                $customers=[];$inserted=0;$updated=0;$skipped=0;
                foreach (($r['data']['records']??[]) as $rec) {
                    $get=function(string $code)use($rec):string {
                        $v=$rec[$code]['value']??'';
                        return is_scalar($v)?(string)$v:'';
                    };
                    $email=$get($c['field_email']??'');
                    if ($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL)) {$skipped++;continue;}
                    $customer=[
                        'id'=>id('customer'),'kintone_id'=>$rec['$id']['value']??'',
                        'company'=>$get($c['field_company']??''),'name'=>$get($c['field_name']??''),
                        'email'=>$email,'department'=>$get($c['field_department']??''),
                        'phone'=>$get($c['field_phone']??''),'address'=>$get($c['field_address']??'')
                    ];
                    $found=false;
                    foreach ($d['customers'] as &$old) {
                        if (($old['kintone_id']??'')===$customer['kintone_id']) {$customer['id']=$old['id'];$old=$customer;$updated++;$found=true;break;}
                    }
                    if (!$found) {$d['customers'][]=$customer;$inserted++;}
                    $customers[]=$customer;
                }
                if (!saveData($d)) apiError('顧客データの保存に失敗しました。','storage_write');
                jsonResponse(['ok'=>true,'count'=>count($customers),'inserted'=>$inserted,'updated'=>$updated,'skipped'=>$skipped,'errors'=>[]]);

            case 'test_smtp_connection':
                $c=$d['settings']['smtp'];
                if (empty($c['smtp_server'])) apiError('保存済みSMTP設定がありません。','configuration',422);
                $r=smtpConnect($c);
                if (!$r['ok']) jsonResponse($r,502);
                smtpCmd($r['socket'],'QUIT');fclose($r['socket']);
                jsonResponse(['ok'=>true,'message'=>'SMTPサーバへの接続に成功しました。']);

            case 'send_smtp_test':
                $to=trim((string)post('test_email'));
                $c=$d['settings']['smtp'];
                $r=smtpSend($c,$to,'アンケート管理システム SMTP送信テスト','アンケート管理システムからのSMTP送信テストです。');
                if (!$r['ok']) jsonResponse($r,502);
                $d['mail_logs'][]=['id'=>id('mail'),'type'=>'smtp_test','to'=>$to,'subject'=>'アンケート管理システム SMTP送信テスト','sent_at'=>date('c'),'status'=>'sent'];
                saveData($d);
                jsonResponse(['ok'=>true,'message'=>'テストメールを送信しました。']);

            case 'save_survey':
                $s=arrPost('survey_json');
                $s=normalizeSurvey($s);
                $found=findSurvey($d,$s['id']);
                if ($found!==null) $d['surveys'][$found[0]]=$s; else $d['surveys'][]=$s;
                if (!saveData($d)) apiError('アンケートの保存に失敗しました。','storage_write');
                jsonResponse(['ok'=>true,'survey'=>$s]);

            case 'delete_survey':
                $id=(string)post('survey_id');
                foreach($d['surveys'] as &$s) if (($s['id']??'')===$id) $s['deleted']=true;
                if (!saveData($d)) apiError('アンケートの削除に失敗しました。','storage_write');
                jsonResponse(['ok'=>true]);

            case 'duplicate_survey':
                $f=findSurvey($d,(string)post('survey_id'));
                if ($f===null) apiError('アンケートが見つかりません。','not_found',404);
                $s=$f[1];$s['id']=id('survey');$s['title'].='（複製）';$s['status']='draft';
                foreach($s['groups'] as &$g){$g['id']=id('group');foreach($g['questions'] as &$q){$q['id']=id('question');}}
                renumber($s);$d['surveys'][]=$s;
                if (!saveData($d)) apiError('複製の保存に失敗しました。','storage_write');
                jsonResponse(['ok'=>true,'survey'=>$s]);

            case 'save_response':
                $r=arrPost('response_json');
                if (empty($r['survey_id'])) apiError('アンケートIDがありません。','validation',422);
                $r['id']=$r['id']??id('response');$r['created_at']=$r['created_at']??date('c');
                $d['responses'][]=$r;
                if (!saveData($d)) apiError('回答の保存に失敗しました。','storage_write');
                jsonResponse(['ok'=>true,'response_id'=>$r['id']]);

            case 'send_mail':
                $ids=arrPost('recipient_ids');$subject=(string)post('mail_subject');$body=(string)post('mail_body');
                $sent=0;$errors=[];
                foreach($d['customers'] as $c) {
                    if (!in_array((string)($c['id']??''),array_map('strval',$ids),true)) continue;
                    $r=smtpSend($d['settings']['smtp'],(string)$c['email'],$subject,$body);
                    if ($r['ok']) $sent++; else $errors[]=['customer_id'=>$c['id'],'message'=>$r['message']??'送信失敗'];
                }
                $d['mail_logs'][]=['id'=>id('mail'),'type'=>(string)post('template_type','bulk'),'subject'=>$subject,'sent_at'=>date('c'),'count'=>$sent];
                saveData($d);
                jsonResponse(['ok'=>true,'sent'=>$sent,'errors'=>$errors]);

            default:
                apiError('未知のActionです。','unknown_action',400);
        }
    } catch (Throwable $e) {
        error_log('survey api: '.$e->getMessage());
        apiError('サーバー内部でエラーが発生しました。','server_error',500);
    }
}

/* ============================================================
 * GUARD: SPA HTML
 * ============================================================ */

$api=currentUrl();
$csrfToken=csrf();
$loginNonce=$_SESSION['login_nonce'];

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f7fb;color:#172033;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}
header{position:sticky;top:0;z-index:20;background:#172033;color:#fff;padding:14px 22px;display:flex;gap:22px;align-items:center}
header strong{margin-right:auto}
button,input,select,textarea{font:inherit}
button{border:0;border-radius:7px;padding:9px 14px;background:#2563eb;color:white;cursor:pointer}
button.secondary{background:#64748b}
button.danger{background:#dc2626}
button.light{background:#e2e8f0;color:#172033}
main{max-width:1300px;margin:0 auto;padding:24px}
.card{background:white;border:1px solid #dbe2ea;border-radius:12px;padding:18px;margin-bottom:16px;box-shadow:0 2px 8px #00000008}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
label{display:block;font-weight:600;margin-bottom:5px}
input,select,textarea{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:7px;background:#fff}
textarea{min-height:100px}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.badge{display:inline-block;padding:4px 9px;border-radius:20px;background:#e2e8f0;font-size:12px}
.active{background:#dcfce7;color:#166534}.draft{background:#fef3c7;color:#92400e}.ended{background:#fee2e2;color:#991b1b}
.group{border:1px solid #cbd5e1;border-radius:10px;margin:14px 0;padding:12px;background:#f8fafc}
.question{background:white;border:1px solid #dbe2ea;border-radius:8px;padding:12px;margin:8px 0;cursor:move}
.error{border:1px solid #fecaca;background:#fef2f2;color:#991b1b;padding:16px;border-radius:10px}
.success{border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;padding:12px;border-radius:8px}
.muted{color:#64748b;font-size:13px}
table{width:100%;border-collapse:collapse;background:#fff}th,td{padding:9px;border-bottom:1px solid #e2e8f0;text-align:left}
.hidden{display:none!important}
@media(max-width:800px){.grid{grid-template-columns:1fr}main{padding:12px}}
</style>
</head>
<body>
<header>
<strong>アンケート管理システム</strong>
<button class="light" onclick="App.actions.list()">アンケート一覧</button>
<button class="light" onclick="App.actions.settings()">キントーン・メール設定</button>
<button class="light" onclick="App.actions.logout()">ログアウト</button>
</header>
<main><div id="app"></div></main>

<script>
window.APP_CONFIG={
    apiUrl:<?=json_encode($api,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>,
    csrfToken:<?=json_encode($csrfToken)?>,
    loginNonce:<?=json_encode($loginNonce)?>
};

window.App={
state:{
    initialization:'uninitialized',initialized:false,initializingPromise:null,
    screen:'list',surveys:[],responses:[],customers:[],mailLogs:[],
    settings:{kintone:{},smtp:{}},csrfToken:APP_CONFIG.csrfToken,
    survey:null,answers:{},responseSurvey:null
},
render:{},
actions:{},
api:{},
utils:{},
initSortable:function(){},
init:function(){},
};

App.utils.escapeHTML=function(v){
    const d=document.createElement('div');d.textContent=v==null?'':String(v);return d.innerHTML;
};

App.utils.err=function(type,message,status){
    return {type:type,message:message,status:status||0};
};

App.api.request=async function(action,payload={},opt={}){
    const controller=new AbortController(),timeout=opt.timeout||30000;
    const timer=setTimeout(()=>controller.abort(),timeout);
    const body=new URLSearchParams();
    body.set('action',action);
    Object.keys(payload).forEach(k=>{
        const v=payload[k];
        body.set(k,v&&typeof v==='object'?JSON.stringify(v):v==null?'':String(v));
    });
    if(action!=='get_initial_data'&&action!=='login') body.set('csrf_token',App.state.csrfToken||APP_CONFIG.csrfToken);

    try{
        let r;
        try{
            r=await fetch(APP_CONFIG.apiUrl,{
                method:'POST',credentials:'same-origin',cache:'no-store',
                headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
                body,signal:controller.signal
            });
        }catch(e){
            if(e.name==='AbortError') throw App.utils.err('timeout','サーバーからの応答がタイムアウトしました。');
            throw App.utils.err('network','サーバーへ接続できません。ネットワーク接続またはサーバーの稼働状態を確認してください。');
        }

        const ct=r.headers.get('content-type')||'';
        const text=await r.text();

        if(!r.ok){
            let msg=`サーバーからHTTP ${r.status}エラーが返されました。`;
            if(ct.toLowerCase().includes('application/json')&&text.trim()){
                try{const j=JSON.parse(text);if(j.message)msg=j.message;}catch(_){}
            }
            throw App.utils.err(r.status===401?'authentication':'http',msg,r.status);
        }

        if(!ct.toLowerCase().includes('application/json'))
            throw App.utils.err('content_type','サーバーから正常なAPI応答を取得できませんでした。');

        if(!text.trim()) throw App.utils.err('empty_response','サーバーからデータが返されませんでした。');

        let j;
        try{j=JSON.parse(text)}catch(_){
            throw App.utils.err('invalid_json','サーバーから正常なデータを取得できませんでした。');
        }

        if(!j||typeof j!=='object') throw App.utils.err('invalid_json','サーバーから不正なデータが返されました。');
        if(j.ok!==true) throw App.utils.err(j.error_type||'api_error',j.message||'サーバーで処理に失敗しました。',r.status);
        return j;
    }finally{clearTimeout(timer)}
};

App.api.getInitialData=()=>App.api.request('get_initial_data');

App.render.loading=function(){
    document.getElementById('app').innerHTML='<div class="card"><h2>読み込み中...</h2><p class="muted">初期データを取得しています。</p></div>';
};

App.render.initError=function(e){
    const check={
        network:'ネットワーク接続またはサーバーの稼働状態を確認してください。',
        timeout:'サーバーの稼働状態とネットワークを確認してください。',
        authentication:'ログイン状態を確認してください。',
        content_type:'PHPがJSON APIとして応答しているか確認してください。',
        empty_response:'PHPの実行結果が空になっていないか確認してください。',
        invalid_json:'PHP Warning等がAPI応答へ混入していないか確認してください。',
        http:'HTTPステータスとApache/PHPのログを確認してください。'
    }[e.type]||'サーバー設定とsurvey_data.jsonを確認してください。';

    document.getElementById('app').innerHTML=
        `<div class="error"><h2>初期データの取得に失敗しました。</h2>
        <p>${App.utils.escapeHTML(e.message||'通信に失敗しました。')}</p>
        <p>エラー種別：${App.utils.escapeHTML(e.type||'unknown')}</p>
        ${e.status?`<p>HTTPステータス：${e.status}</p>`:''}
        <p>確認事項：${App.utils.escapeHTML(check)}</p>
        <button onclick="App.init()">再試行</button></div>`;
};

App.render.current=function(){
    if(!App.state.initialized)return App.render.loading();
    if(App.state.screen==='settings')return App.render.settings();
    if(App.state.screen==='edit')return App.render.edit();
    if(App.state.screen==='preview')return App.render.preview();
    if(App.state.screen==='response')return App.render.response();
    if(App.state.screen==='aggregate')return App.render.aggregate();
    if(App.state.screen==='mail')return App.render.mail();
    return App.render.list();
};

App.render.list=function(){
    const rows=App.state.surveys.filter(s=>!s.deleted).map(s=>{
        const label={draft:'下書き',active:'公開中',ended:'終了'}[s.status]||s.status;
        let buttons=`<button onclick="App.actions.edit('${s.id}')">確認・編集</button>`;
        if(s.status==='active'||s.status==='ended')buttons+=` <button class="light" onclick="App.actions.aggregate('${s.id}')">集計</button>`;
        if(s.status==='active')buttons+=` <button class="light" onclick="App.actions.mail('${s.id}')">送信</button>`;
        if(s.status==='draft')buttons+=` <button class="danger" onclick="App.actions.remove('${s.id}')">削除</button>`;
        buttons+=` <button class="light" onclick="App.actions.duplicate('${s.id}')">複製</button>`;
        return `<tr><td>${App.utils.escapeHTML(s.title)}</td><td><span class="badge ${s.status}">${label}</span></td><td>${buttons}</td></tr>`;
    }).join('');

    document.getElementById('app').innerHTML=`
    <div class="card"><h1>アンケート一覧</h1>
    <div class="actions"><button onclick="App.actions.newSurvey()">＋ 新規作成</button></div></div>
    <div class="card"><table><thead><tr><th>タイトル</th><th>ステータス</th><th>操作</th></tr></thead>
    <tbody>${rows||'<tr><td colspan="3">アンケートがありません。</td></tr>'}</tbody></table></div>`;
};

App.render.edit=function(){
    const s=App.state.survey;
    document.getElementById('app').innerHTML=`
    <div class="card"><div class="muted">ホーム ＞ アンケート一覧 ＞ アンケート作成・編集</div>
    <h1>アンケート作成・編集</h1>
    <div class="grid">
      <div><label>タイトル</label><input id="survey_title" value="${App.utils.escapeHTML(s.title)}" onchange="App.actions.field('title',this.value)"></div>
      <div><label>ステータス</label><select id="survey_status" onchange="App.actions.changeSurveyStatus(this.value)">
        <option value="draft" ${s.status==='draft'?'selected':''}>下書き</option>
        <option value="active" ${s.status==='active'?'selected':''}>公開中</option>
        <option value="ended" ${s.status==='ended'?'selected':''}>終了</option>
      </select></div>
      <div><label>開始日時</label><input type="datetime-local" id="survey_start_at" value="${App.utils.escapeHTML(s.start_at)}" onchange="App.actions.field('start_at',this.value)"></div>
      <div><label>終了日時</label><input type="datetime-local" id="survey_end_at" value="${App.utils.escapeHTML(s.end_at)}" onchange="App.actions.field('end_at',this.value)"></div>
      <div><label>質問番号形式</label><select id="survey_numbering_mode" onchange="App.actions.field('numbering_mode',this.value)"><option value="global" ${s.numbering_mode==='global'?'selected':''}>Q1 Q2 Q3</option><option value="group" ${s.numbering_mode==='group'?'selected':''}>Q1-1 Q1-2</option></select></div>
      <div><label>一般回答</label><label><input type="checkbox" ${s.allow_general?'checked':''} onchange="App.actions.field('allow_general',this.checked)"> 許可する</label></div>
    </div></div>
    <div id="question_editor"></div>
    <div class="actions"><button onclick="App.actions.addGroup()">＋ ブロックを追加</button><button onclick="App.actions.preview()">プレビュー</button><button onclick="App.actions.saveSurvey()">保存</button><button class="secondary" onclick="App.actions.list()">戻る</button></div>`;
    App.render.groups();
};

App.render.groups=function(){
    const box=document.getElementById('question_editor');
    const s=App.state.survey;
    box.innerHTML=s.groups.map((g,gi)=>`
    <div class="card group" data-group-id="${g.id}">
      <div class="grid"><div><label>グループ名</label><input value="${App.utils.escapeHTML(g.name)}" onchange="App.actions.groupField('${g.id}',this.value)"></div></div>
      <div class="question-list" data-group="${g.id}">
      ${(g.questions||[]).map(q=>App.render.question(q)).join('')}
      </div>
      <button class="light" onclick="App.actions.addQuestion('${g.id}')">＋ 質問を追加</button>
    </div>`).join('');
    App.initSortable();
};

App.render.question=function(q){
    return `<div class="question" data-question-id="${q.id}">
      <div><strong>${App.utils.escapeHTML(q.number||'Q')}</strong></div>
      <textarea onchange="App.actions.questionField('${q.id}','text',this.value)">${App.utils.escapeHTML(q.text)}</textarea>
      <div class="grid">
      <div><label>形式</label><select onchange="App.actions.questionField('${q.id}','type',this.value)">
        ${['text','textarea','single','multiple','number','date'].map(t=>`<option value="${t}" ${q.type===t?'selected':''}>${t}</option>`).join('')}
      </select></div>
      <div><label><input type="checkbox" ${q.required?'checked':''} onchange="App.actions.questionField('${q.id}','required',this.checked)"> 必須回答</label></div></div>
      ${q.type==='single'||q.type==='multiple'?`
      <label>選択肢</label>
      ${(q.options||[]).map((o,i)=>`<div class="grid"><input value="${App.utils.escapeHTML(typeof o==='string'?o:o.text||'')}" onchange="App.actions.option('${q.id}',${i},this.value)"><button class="danger" onclick="App.actions.removeOption('${q.id}',${i})">削除</button></div>`).join('')}
      <button class="light" onclick="App.actions.addOption('${q.id}')">＋ 選択肢</button>`:''}
      ${q.type==='single'?`<div class="card"><strong>分岐設定</strong>${(q.options||[]).map((o,i)=>{
        const text=typeof o==='string'?o:o.text||'';
        return `<div class="grid"><div>${App.utils.escapeHTML(text)}</div><select onchange="App.actions.branch('${q.id}',${i},this.value)">${App.actions.branchOptions(q.id,q.branching?.[String(i)]||'')}</select></div>`;
      }).join('')}</div>`:''}
      <button class="danger" onclick="App.actions.removeQuestion('${q.id}')">質問を削除</button>
    </div>`;
};

App.actions.branchOptions=function(qid,current){
    const all=App.state.survey.groups.flatMap(g=>g.questions);
    const idx=all.findIndex(q=>q.id===qid);
    return '<option value="">分岐なし</option>'+all.slice(idx+1).map(q=>`<option value="${q.id}" ${current===q.id?'selected':''}>${App.utils.escapeHTML(q.number)}：${App.utils.escapeHTML(q.text)}</option>`).join('');
};

App.render.preview=function(){
    const s=App.state.survey;
    document.getElementById('app').innerHTML=`<div class="card"><h1>プレビュー</h1><h2>${App.utils.escapeHTML(s.title)}</h2>${s.groups.map(g=>`<div class="group"><h3>${App.utils.escapeHTML(g.name)}</h3>${g.questions.map(q=>`<div class="question"><strong>${App.utils.escapeHTML(q.number)}</strong><p>${App.utils.escapeHTML(q.text)}</p>${(q.options||[]).map(o=>`<label><input type="${q.type==='single'?'radio':'checkbox'}" name="${q.id}"> ${App.utils.escapeHTML(typeof o==='string'?o:o.text||'')}</label>`).join('<br>')}</div>`).join('')}</div>`).join('')}<button onclick="App.actions.edit('${s.id}')">編集へ戻る</button></div>`;
};

App.render.settings=function(){
    const k=App.state.settings.kintone||{},m=App.state.settings.smtp||{};
    document.getElementById('app').innerHTML=`
    <div class="card"><div class="muted">ホーム ＞ キントーン・メール設定</div><h1>キントーン・メール設定</h1>
    <h2>キントーン設定</h2><form id="kintone_settings_form" onsubmit="event.preventDefault();App.actions.saveKintoneSettings()">
    <div class="grid">
    ${[['setting_subdomain','サブドメイン',k.subdomain||''],['setting_login_name','ログイン名',k.login_name||''],['setting_password','パスワード',''],['setting_app_id','顧客管理アプリID',k.app_id||''],['setting_proxy','Proxy',k.proxy||'']].map(x=>`<div><label>${x[1]}</label><input id="${x[0]}" ${x[0]==='setting_password'?'type="password"':''} value="${App.utils.escapeHTML(x[2])}" placeholder="${x[0]==='setting_password'&&k.password_configured?'変更しない場合は空欄':''}"></div>`).join('')}
    <div><label>SSL証明書検証</label><input id="setting_ssl_verify" type="checkbox" ${k.ssl_verify?'checked':''}></div>
    ${[['field_company','会社名'],['field_name','氏名'],['field_email','メール'],['field_department','部署'],['field_phone','電話'],['field_address','住所']].map(x=>`<div><label>${x[1]}フィールドコード</label><input id="${x[0]}" value="${App.utils.escapeHTML(k[x[0]]||'')}"></div>`).join('')}
    </div><div id="kintone_message"></div><div id="kintone_connection_result"></div>
    <div class="actions"><button id="kintone_save_button">設定を保存</button><button type="button" class="secondary" onclick="App.actions.connectKintone()">キントーン接続確認</button><button type="button" class="secondary" onclick="App.actions.fetchKintoneFields()">フィールド取得</button><button type="button" class="secondary" onclick="App.actions.syncCustomers()">顧客データを同期</button></div></form></div>

    <div class="card"><h2>SMTP設定</h2><form id="smtp_settings_form" onsubmit="event.preventDefault();App.actions.saveSmtpSettings()">
    <div class="grid">
    ${[['smtp_server','SMTPサーバ',m.smtp_server||''],['smtp_port','SMTPポート',m.smtp_port||''],['smtp_username','SMTPユーザー名',m.smtp_username||''],['smtp_password','SMTPパスワード',''],['smtp_from_email','送信元メールアドレス',m.smtp_from_email||''],['smtp_from_name','送信元表示名',m.smtp_from_name||''],['smtp_timeout','接続タイムアウト',m.smtp_timeout||10]].map(x=>`<div><label>${x[1]}</label><input id="${x[0]}" ${x[0]==='smtp_password'?'type="password"':''} value="${App.utils.escapeHTML(x[2])}" placeholder="${x[0]==='smtp_password'&&m.smtp_password_configured?'変更しない場合は空欄':''}"></div>`).join('')}
    <div><label>暗号化方式</label><select id="smtp_encryption"><option value="none" ${m.smtp_encryption==='none'?'selected':''}>none</option><option value="starttls" ${m.smtp_encryption==='starttls'?'selected':''}>starttls</option><option value="ssl" ${m.smtp_encryption==='ssl'?'selected':''}>ssl</option></select></div>
    <div><label>SMTP認証</label><input id="smtp_auth" type="checkbox" ${m.smtp_auth?'checked':''}></div></div>
    <div id="smtp_message"></div><div id="smtp_connection_result"></div>
    <div class="actions"><button id="smtp_save_button">設定を保存</button><button type="button" class="secondary" onclick="App.actions.testSmtp()">SMTP接続確認</button><button type="button" class="secondary" onclick="App.actions.sendSmtpTest()">テストメール送信</button></div></form></div>`;
};

App.render.aggregate=function(){
    const id=App.state.survey.id,rs=App.state.responses.filter(r=>r.survey_id===id);
    document.getElementById('app').innerHTML=`<div class="card"><h1>集計</h1><p>回答数：${rs.length}</p><div class="actions"><button onclick="App.actions.exportCsv()">CSV</button><button onclick="window.print()">PDF印刷</button><button onclick="App.actions.list()">戻る</button></div></div>
    <div class="card"><table id="response_table"><thead><tr><th>回答ID</th><th>日時</th><th>回答</th></tr></thead><tbody>${rs.map(r=>`<tr><td>${App.utils.escapeHTML(r.id)}</td><td>${App.utils.escapeHTML(r.created_at)}</td><td><button onclick="App.actions.responseDetail('${r.id}')">表示</button></td></tr>`).join('')}</tbody></table></div>`;
};

App.render.mail=function(){
    const c=App.state.customers;
    document.getElementById('app').innerHTML=`<div class="card"><h1>メール送信</h1><div class="grid"><input id="mail_subject" placeholder="件名"><select id="template_type"><option value="bulk">一括送信</option><option value="remind">リマインド</option><option value="resend">再送</option></select></div><textarea id="mail_body" placeholder="本文"></textarea><h3>顧客選択</h3><div id="customer_table">${c.map(x=>`<label><input type="checkbox" class="customer-check" value="${x.id}"> ${App.utils.escapeHTML(x.company)} ${App.utils.escapeHTML(x.name)} &lt;${App.utils.escapeHTML(x.email)}&gt;</label>`).join('<br>')}</div><div class="actions"><button onclick="App.actions.sendMail()">一括送信</button><button onclick="App.actions.list()">戻る</button></div></div>`;
};

App.render.response=function(){
    const s=App.state.responseSurvey;
    document.getElementById('app').innerHTML=`<div class="card"><h1>${App.utils.escapeHTML(s.title)}</h1><div id="response_content">${s.groups.flatMap(g=>g.questions).map(q=>`<div class="question response-question" data-qid="${q.id}"><label>${App.utils.escapeHTML(q.number)} ${App.utils.escapeHTML(q.text)} ${q.required?'*':''}</label>${App.actions.answerHtml(q)}</div>`).join('')}</div><button onclick="App.actions.confirmResponse()">回答を確認</button></div>`;
};

App.actions.answerHtml=function(q){
    if(q.type==='textarea')return `<textarea data-answer="${q.id}"></textarea>`;
    if(q.type==='single')return (q.options||[]).map((o,i)=>`<label><input type="radio" name="a_${q.id}" value="${i}" onchange="App.actions.updateBranchVisibility()"> ${App.utils.escapeHTML(typeof o==='string'?o:o.text||'')}</label>`).join('<br>');
    if(q.type==='multiple')return (q.options||[]).map((o,i)=>`<label><input type="checkbox" data-answer="${q.id}" value="${i}"> ${App.utils.escapeHTML(typeof o==='string'?o:o.text||'')}</label>`).join('<br>');
    return `<input type="${q.type==='number'?'number':q.type==='date'?'date':'text'}" data-answer="${q.id}">`;
};

App.actions.newSurvey=function(){
    App.state.survey=normalizeClient({title:'新しいアンケート',status:'draft',numbering_mode:'global',groups:[{id:'group_'+Date.now(),name:'グループ1',questions:[]}]});
    App.state.screen='edit';App.render.current();
};
function normalizeClient(s){
    s.id=s.id||'survey_'+Date.now();s.groups=s.groups||[];s.numbering_mode=s.numbering_mode||'global';
    s.groups.forEach(g=>{g.questions=g.questions||[];g.questions.forEach(q=>{q.options=q.options||[];q.branching=q.branching||{}})});
    App.actions.renumberQuestionsLocal(s);return s;
}
App.actions.edit=function(id){const s=App.state.surveys.find(x=>x.id===id);if(s){App.state.survey=JSON.parse(JSON.stringify(s));App.state.screen='edit';App.render.current()}};
App.actions.list=function(){App.state.screen='list';App.render.current()};
App.actions.settings=function(){App.state.screen='settings';App.render.current()};
App.actions.field=function(k,v){App.state.survey[k]=v;App.actions.renumberQuestions()};
App.actions.groupField=function(id,v){const g=App.state.survey.groups.find(x=>x.id===id);if(g)g.name=v};
App.actions.addGroup=function(){App.state.survey.groups.push({id:'group_'+Date.now(),name:'グループ'+(App.state.survey.groups.length+1),questions:[]});App.actions.renumberQuestions();App.render.current()};
App.actions.addQuestion=function(gid){
    const g=App.state.survey.groups.find(x=>x.id===gid);if(!g)return;
    g.questions.push({id:'question_'+Date.now()+Math.random(),type:'text',text:'',required:false,options:[],other_enabled:false,branching:{}});
    App.actions.renumberQuestions();App.render.current();
};
App.actions.removeQuestion=function(qid){
    App.state.survey.groups.forEach(g=>g.questions=g.questions.filter(q=>q.id!==qid));
    App.actions.cleanBranches();App.actions.renumberQuestions();App.render.current();
};
App.actions.questionField=function(qid,k,v){
    const q=App.actions.question(qid);if(q){q[k]=v;if(k==='type'&&v!=='single')q.branching={};App.actions.renumberQuestions();App.render.current()}
};
App.actions.question=function(id){for(const g of App.state.survey.groups){const q=g.questions.find(x=>x.id===id);if(q)return q}return null};
App.actions.addOption=function(qid){const q=App.actions.question(qid);if(q){q.options.push('選択肢'+(q.options.length+1));App.render.current()}};
App.actions.option=function(qid,i,v){const q=App.actions.question(qid);if(q)q.options[i]=v;App.actions.cleanBranches();App.render.current()};
App.actions.removeOption=function(qid,i){const q=App.actions.question(qid);if(q){q.options.splice(i,1);const b={};Object.keys(q.branching||{}).forEach(k=>{if(+k!==i)b[+k>i?+k-1:+k]=q.branching[k]});q.branching=b;App.actions.cleanBranches();App.render.current()}};
App.actions.branch=function(qid,i,v){const q=App.actions.question(qid);if(q){q.branching= q.branching||{};q.branching[i]=v||null}};
App.actions.cleanBranches=function(){
    const ids=new Set(App.state.survey.groups.flatMap(g=>g.questions.map(q=>q.id)));
    App.state.survey.groups.forEach(g=>g.questions.forEach(q=>{
        Object.keys(q.branching||{}).forEach(k=>{if(q.branching[k]&&!ids.has(q.branching[k]))delete q.branching[k]});
    }));
};
App.actions.renumberQuestionsLocal=function(s){
    let n=1;s.groups.forEach((g,gi)=>g.questions.forEach((q,qi)=>{q.number=s.numbering_mode==='group'?`Q${gi+1}-${qi+1}`:`Q${n++}`}));
};
App.actions.renumberQuestions=function(){App.actions.renumberQuestionsLocal(App.state.survey)};
App.actions.changeSurveyStatus=function(v){
    const old=App.state.survey.status;
    if(old==='active'&&v==='ended'&&!confirm('このアンケートを終了状態に変更しますか？')){document.getElementById('survey_status').value=old;return}
    if(old==='ended'&&v==='active'&&!confirm('このアンケートを公開状態に変更しますか？')){document.getElementById('survey_status').value=old;return}
    App.state.survey.status=v;
};
App.actions.saveSurvey=async function(){
    try{const r=await App.api.request('save_survey',{survey_json:App.state.survey});App.state.survey=r.survey;const i=App.state.surveys.findIndex(s=>s.id===r.survey.id);if(i>=0)App.state.surveys[i]=r.survey;else App.state.surveys.push(r.survey);alert('保存しました。');App.actions.list()}catch(e){alert(e.message)}
};
App.actions.preview=function(){App.state.screen='preview';App.render.current()};
App.actions.remove=async function(id){if(!confirm('このアンケートを削除しますか？'))return;try{await App.api.request('delete_survey',{survey_id:id});await App.initDataRefresh()}catch(e){alert(e.message)}};
App.actions.duplicate=async function(id){try{const r=await App.api.request('duplicate_survey',{survey_id:id});App.state.surveys.push(r.survey);App.render.list()}catch(e){alert(e.message)}};
App.actions.aggregate=function(id){App.state.survey=App.state.surveys.find(s=>s.id===id);App.state.screen='aggregate';App.render.current()};
App.actions.mail=function(id){App.state.survey=App.state.surveys.find(s=>s.id===id);App.state.screen='mail';App.render.current()};
App.actions.connectKintone=async function(){try{const r=await App.api.request('connect_kintone');document.getElementById('kintone_connection_result').innerHTML=`<div class="success">${App.utils.escapeHTML(r.message)} HTTP ${r.http_status}</div>`}catch(e){document.getElementById('kintone_connection_result').innerHTML=`<div class="error">${App.utils.escapeHTML(e.message)}</div>`}};
App.actions.fetchKintoneFields=async function(){try{const r=await App.api.request('fetch_kintone_fields');document.getElementById('kintone_connection_result').innerHTML=`<div class="success">フィールド取得成功：${r.fields.map(x=>App.utils.escapeHTML(x.code)+' / '+App.utils.escapeHTML(x.label)+' / '+App.utils.escapeHTML(x.type)).join('<br>')}</div>`}catch(e){document.getElementById('kintone_connection_result').innerHTML=`<div class="error">${App.utils.escapeHTML(e.message)}</div>`}};
App.actions.syncCustomers=async function(){try{const r=await App.api.request('sync_customers');document.getElementById('kintone_connection_result').innerHTML=`<div class="success">同期完了：${r.count}件、追加${r.inserted}件、更新${r.updated}件、スキップ${r.skipped}件</div>`;await App.initDataRefresh()}catch(e){document.getElementById('kintone_connection_result').innerHTML=`<div class="error">${App.utils.escapeHTML(e.message)}</div>`}};
App.actions.saveKintoneSettings=async function(){
    const p={subdomain:setting_subdomain.value,login_name:setting_login_name.value,password:setting_password.value,app_id:setting_app_id.value,proxy:setting_proxy.value,ssl_verify:setting_ssl_verify.checked};
    ['field_company','field_name','field_email','field_department','field_phone','field_address'].forEach(k=>p[k]=document.getElementById(k).value);
    try{const r=await App.api.request('save_kintone_settings',p);App.state.settings=r.data;document.getElementById('kintone_message').innerHTML='<div class="success">キントーン設定を保存しました。</div>'}catch(e){document.getElementById('kintone_message').innerHTML=`<div class="error">${App.utils.escapeHTML(e.message)}</div>`}
};
App.actions.testSmtp=async function(){try{const r=await App.api.request('test_smtp_connection');document.getElementById('smtp_connection_result').innerHTML=`<div class="success">${App.utils.escapeHTML(r.message)}</div>`}catch(e){document.getElementById('smtp_connection_result').innerHTML=`<div class="error">${App.utils.escapeHTML(e.message)}</div>`}};
App.actions.saveSmtpSettings=async function(){
    const p={smtp_server:smtp_server.value,smtp_port:smtp_port.value,smtp_encryption:smtp_encryption.value,smtp_auth:smtp_auth.checked,smtp_username:smtp_username.value,smtp_password:smtp_password.value,smtp_from_email:smtp_from_email.value,smtp_from_name:smtp_from_name.value,smtp_timeout:smtp_timeout.value};
    try{const r=await App.api.request('save_smtp_settings',p);App.state.settings=r.data;document.getElementById('smtp_message').innerHTML='<div class="success">SMTP設定を保存しました。</div>'}catch(e){document.getElementById('smtp_message').innerHTML=`<div class="error">${App.utils.escapeHTML(e.message)}</div>`}
};
App.actions.sendSmtpTest=async function(){
    const to=prompt('テストメール宛先を入力してください。');if(!to)return;
    try{const r=await App.api.request('send_smtp_test',{test_email:to});document.getElementById('smtp_connection_result').innerHTML=`<div class="success">${App.utils.escapeHTML(r.message)}</div>`}catch(e){document.getElementById('smtp_connection_result').innerHTML=`<div class="error">${App.utils.escapeHTML(e.message)}</div>`}
};
App.actions.sendMail=async function(){
    const ids=[...document.querySelectorAll('.customer-check:checked')].map(x=>x.value);
    try{const r=await App.api.request('send_mail',{recipient_ids:ids,mail_subject:mail_subject.value,mail_body:mail_body.value,template_type:template_type.value});alert(`送信完了：${r.sent}件`);App.actions.list()}catch(e){alert(e.message)}
};
App.actions.answerSurvey=function(id){
    const s=App.state.surveys.find(x=>x.id===id);if(!s)return;
    App.state.responseSurvey=JSON.parse(JSON.stringify(s));App.state.answers=JSON.parse(localStorage.getItem('survey_answers_'+id)||'{}');
    App.state.screen='response';App.render.current();
};
App.actions.updateBranchVisibility=function(){
    const s=App.state.responseSurvey;if(!s)return;
    const answers={};
    s.groups.flatMap(g=>g.questions).forEach(q=>{
        const el=document.querySelector(`[name="a_${q.id}"]:checked`)||document.querySelector(`[data-answer="${q.id}"]`);
        if(el)answers[q.id]=el.value;
    });
    s.groups.flatMap(g=>g.questions).forEach(q=>{
        const el=document.querySelector(`[data-qid="${q.id}"]`);if(!el)return;
        let visible=true;
        for(const parent of s.groups.flatMap(g=>g.questions)) {
            const val=answers[parent.id],branch=parent.branching||{};
            if(parent.type==='single'&&val!=null&&branch[val]===q.id) {visible=true;break}
            if(parent.type==='single'&&Object.values(branch).includes(q.id)) {visible=false}
        }
        el.classList.toggle('hidden',!visible);
    });
};
App.actions.validateResponse=function(){
    const s=App.state.responseSurvey,errors=[];
    s.groups.flatMap(g=>g.questions).forEach(q=>{
        const el=document.querySelector(`[data-qid="${q.id}"]`);
        if(el?.classList.contains('hidden'))return;
        if(!q.required)return;
        let ok=false;
        if(q.type==='single')ok=!!document.querySelector(`[name="a_${q.id}"]:checked`);
        else if(q.type==='multiple')ok=!!document.querySelector(`[data-answer="${q.id}"]:checked`);
        else ok=!!document.querySelector(`[data-answer="${q.id}"]`)?.value;
        if(!ok)errors.push(q.number+'：回答してください。');
    });
    return errors;
};
App.actions.confirmResponse=function(){
    const e=App.actions.validateResponse();if(e.length){alert(e.join('\n'));return}
    const s=App.state.responseSurvey,answers={};
    s.groups.flatMap(g=>g.questions).forEach(q=>{
        if(q.type==='single')answers[q.id]=document.querySelector(`[name="a_${q.id}"]:checked`)?.value??null;
        else if(q.type==='multiple')answers[q.id]=[...document.querySelectorAll(`[data-answer="${q.id}"]:checked`)].map(x=>x.value);
        else answers[q.id]=document.querySelector(`[data-answer="${q.id}"]`)?.value??'';
    });
    localStorage.setItem('survey_answers_'+s.id,JSON.stringify(answers));
    App.state.answers=answers;
    if(confirm('この回答を送信しますか？'))App.actions.submitResponse();
};
App.actions.submitResponse=async function(){
    try{await App.api.request('save_response',{response_json:{survey_id:App.state.responseSurvey.id,answers:App.state.answers,created_at:new Date().toISOString()}});document.getElementById('app').innerHTML='<div class="card"><h1>回答完了</h1><p>回答を保存しました。</p><button onclick="App.actions.list()">戻る</button></div>'}catch(e){alert(e.message)}
};
App.actions.responseDetail=function(id){const r=App.state.responses.find(x=>x.id===id);alert(JSON.stringify(r.answers||{},null,2))};
App.actions.exportCsv=function(){
    const rs=App.state.responses.filter(r=>r.survey_id===App.state.survey.id);
    const csv='\ufeff'+['回答ID,日時,回答',...rs.map(r=>`"${r.id}","${r.created_at}","${JSON.stringify(r.answers||{}).replace(/"/g,'""')}"`)].join('\r\n');
    const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));a.download='survey.csv';a.click();URL.revokeObjectURL(a.href);
};
App.actions.logout=async function(){
    try{await App.api.request('logout',{});location.reload()}catch(e){alert(e.message)}
};
App.initDataRefresh=async function(){
    const r=await App.api.getInitialData();
    const d=r.data;
    if(!Array.isArray(d.surveys)||!Array.isArray(d.responses)||!Array.isArray(d.customers)||!d.settings||!Array.isArray(d.mail_logs))throw App.utils.err('invalid_json','初期データの構造が不正です。');
    App.state.surveys=d.surveys;App.state.responses=d.responses;App.state.customers=d.customers;App.state.settings=d.settings;App.state.mailLogs=d.mail_logs;App.state.csrfToken=d.csrf_token||App.state.csrfToken;
};
App.initSortable=function(){
    document.querySelectorAll('.question-list').forEach(el=>{
        if(el._sortable)el._sortable.destroy();
        el._sortable=new Sortable(el,{
            group:'survey_questions',animation:150,
            onEnd:function(evt){
                const source=App.state.survey.groups.find(g=>g.id===evt.from.dataset.group);
                const target=App.state.survey.groups.find(g=>g.id===evt.to.dataset.group);
                if(!source||!target)return;
                const moved=source.questions.find(q=>q.id===evt.item.dataset.questionId);
                source.questions=source.questions.filter(q=>q.id!==evt.item.dataset.questionId);
                target.questions.splice(evt.newIndex,0,moved);
                App.actions.renumberQuestions();App.render.current();
            }
        });
    });
};
App.init=async function(){
    if(App.state.initialization==='success')return;
    if(App.state.initialization==='loading'&&App.state.initializingPromise)return App.state.initializingPromise;
    App.state.initialization='loading';App.render.loading();
    App.state.initializingPromise=(async()=>{
        try{
            const r=await App.api.getInitialData(),d=r.data;
            if(!d||typeof d!=='object'||!Array.isArray(d.surveys)||!Array.isArray(d.responses)||!Array.isArray(d.customers)||!d.settings||typeof d.settings!=='object'||!Array.isArray(d.mail_logs))throw App.utils.err('invalid_json','サーバーから返された初期データの構造が不正です。');
            App.state.surveys=d.surveys;App.state.responses=d.responses;App.state.customers=d.customers;App.state.settings=d.settings;App.state.mailLogs=d.mail_logs;App.state.csrfToken=d.csrf_token||App.state.csrfToken;
            App.state.initialization='success';App.state.initialized=true;App.render.current();App.initSortable();
        }catch(e){
            App.state.initialization='failed';App.state.initialized=false;App.render.initError(e);
        }finally{App.state.initializingPromise=null}
    })();
    return App.state.initializingPromise;
};

if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',()=>App.init(),{once:true});
}else{
    App.init();
}
</script>
</body>
</html>
<?php
declare(strict_types=1);

const DATA_DIR = __DIR__.'/data';
const SECRET_KEY = __DIR__.'/.secrets/アンケートアプリ/secret.key';

session_set_cookie_params([
    'httponly'=>true,
    'samesite'=>'Lax',
    'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off',
    'path'=>rtrim(dirname($_SERVER['SCRIPT_NAME']),'/').'/'
]);
if(session_status()!==PHP_SESSION_ACTIVE && !session_start())
    die('セッションを開始できません。');

if(!is_dir(DATA_DIR)) @mkdir(DATA_DIR,0700,true);

function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function getv(string $k,string $d=''):string{return trim((string)($_GET[$k]??$d));}
function postv(string $k,string $d=''):string{return trim((string)($_POST[$k]??$d));}

function readData(string $name,mixed $default=[]):mixed{
    $f=DATA_DIR.'/'.$name.'.php';
    if(!is_file($f))return $default;
    $v=include $f;
    return $v??$default;
}
function writeData(string $name,mixed $data):void{
    $f=DATA_DIR.'/'.$name.'.php';
    $tmp=$f.'.'.bin2hex(random_bytes(4)).'.tmp';
    $s="<?php\nreturn ".var_export($data,true).";\n";
    if(file_put_contents($tmp,$s,LOCK_EX)===false||!rename($tmp,$f)){
        @unlink($tmp);
        throw new RuntimeException('データ保存に失敗しました。');
    }
}

function secretKey():string{
    if(!is_file(SECRET_KEY)||!is_readable(SECRET_KEY))
        throw new RuntimeException('暗号鍵が存在しません。');
    $v=file_get_contents(SECRET_KEY);
    if($v===false)throw new RuntimeException('暗号鍵を読み込めません。');
    if(strlen($v)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
        $v=base64_decode(trim($v),true)?:'';
    if(strlen($v)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
        throw new RuntimeException('暗号鍵設定エラーです。');
    return $v;
}
function encryptSecret(string $plain):string{
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher=sodium_crypto_secretbox($plain,$nonce,secretKey());
    return 'ENC:v1:'.base64_encode($nonce).':'.base64_encode($cipher);
}
function decryptSecret(string $value):string{
    if(!str_starts_with($value,'ENC:v1:'))
        throw new RuntimeException('暗号化データ形式が不正です。');
    $p=explode(':',$value,4);
    if(count($p)!==4)throw new RuntimeException('暗号化データ形式が不正です。');
    $n=base64_decode($p[2],true);
    $c=base64_decode($p[3],true);
    if($n===false||$c===false)
        throw new RuntimeException('暗号化データを復号できません。');
    $v=sodium_crypto_secretbox_open($c,$n,secretKey());
    if($v===false)throw new RuntimeException('暗号化データを復号できません。');
    return $v;
}

function csrf():string{
    if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function requireCsrf():void{
    $v=postv('_csrf');
    if($v===''||!hash_equals(csrf(),$v))
        throw new RuntimeException('CSRFトークンが無効です。ページを再読み込みしてください。');
}
function flash(string $msg,string $type='success'):void{
    $_SESSION['flash']=[$msg,$type];
}
function flashHtml():string{
    if(empty($_SESSION['flash']))return '';
    [$m,$t]=$_SESSION['flash'];unset($_SESSION['flash']);
    return '<div class="alert '.$t.'">'.h($m).'</div>';
}
function redirectScreen(string $screen,string $id=''):never{
    $u='index.php?screen='.rawurlencode($screen);
    if($id!=='')$u.='&id='.rawurlencode($id);
    header('Location: '.$u,true,303);
    exit;
}

function surveys():array{
    return readData('surveys',[[
        'id'=>'survey-001',
        'title'=>'2026年度 顧客満足度アンケート',
        'description'=>'サービスについてのご意見をお聞かせください。',
        'createdAt'=>'2026-08-01','updatedAt'=>'2026-08-25',
        'startAt'=>'2026-08-01T09:00','endAt'=>'2026-09-20T18:00',
        'status'=>'published','numbering'=>'global',
        'groups'=>[[
            'id'=>'g1','title'=>'サービス全体について',
            'questions'=>[[
                'id'=>'q1','text'=>'サービス全体の満足度を教えてください。',
                'type'=>'single','required'=>true,
                'options'=>['とても満足','満足','普通','不満'],
                'branches'=>[]
            ]]
        ]]
    ]]);
}
function surveyById(string $id):?array{
    foreach(surveys() as $s)if(($s['id']??'')===$id)return $s;
    return null;
}
function saveSurvey(array $survey):void{
    $all=surveys();$found=false;
    foreach($all as &$s){
        if($s['id']===$survey['id']){$s=$survey;$found=true;break;}
    }
    unset($s);
    if(!$found)$all[]=$survey;
    writeData('surveys',$all);
}
function refreshStatus(array &$s):void{
    if(($s['status']??'')==='published'&&
       !empty($s['endAt'])&&strtotime($s['endAt'])<time()){
        $s['status']='ended';
        saveSurvey($s);
    }
}
function statusLabel(string $s):string{
    return ['draft'=>'下書き','published'=>'公開中','stopped'=>'停止','ended'=>'終了'][$s]??$s;
}
function answersFor(string $id):array{
    return array_values(array_filter(
        readData('answers',[]),
        fn($a)=>($a['survey']??'')===$id
    ));
}
function validSurveyId(string $id):bool{
    return (bool)preg_match('/^[A-Za-z0-9_-]+$/',$id);
}

function httpRequest(string $url,string $method='GET',array $headers=[],?string $body=null,
                     bool $verify=true,string $proxy=''):array{
    $parts=parse_url($url);
    if(!$parts||empty($parts['host']))throw new RuntimeException('通信先URLが不正です。');
    $ssl=[
        'verify_peer'=>$verify,
        'verify_peer_name'=>$verify,
        'allow_self_signed'=>!$verify,
        'SNI_enabled'=>true
    ];
    $opts=['http'=>[
        'method'=>$method,
        'timeout'=>15,
        'ignore_errors'=>true,
        'follow_location'=>0,
        'header'=>implode("\r\n",$headers)
    ],'ssl'=>$ssl];
    if($body!==null){
        $opts['http']['header'].="\r\nContent-Type: application/json";
        $opts['http']['content']=$body;
    }
    if($proxy!==''){
        if(!preg_match('/^([^:]+):([0-9]+)$/',$proxy,$m))
            throw new RuntimeException('Proxyはhost:port形式で入力してください。');
        $opts['http']['proxy']='tcp://'.$m[1].':'.$m[2];
        $opts['http']['request_fulluri']=true;
    }
    $ctx=stream_context_create($opts);
    $response=@file_get_contents($url,false,$ctx);
    $headersOut=$http_response_header??[];
    $code=0;
    foreach($headersOut as $line)
        if(preg_match('/^HTTP\/\S+\s+(\d+)/',$line,$m))$code=(int)$m[1];
    return ['code'=>$code,'body'=>$response===false?'':$response,'headers'=>$headersOut];
}

function kintoneSettings():array{return readData('kintone',[]);}
function kintoneHost(string $v):string{
    $v=trim($v);
    $v=preg_replace('#^https?://#i','',$v);
    $v=rtrim($v,'/');
    if(preg_match('/^[A-Za-z0-9-]+$/',$v))$v.='.cybozu.com';
    if(!preg_match('/^[A-Za-z0-9-]+\.cybozu\.com$/',$v))
        throw new RuntimeException('kintoneサブドメインが不正です。');
    return $v;
}
function kintoneRequest(string $path,string $method='GET',?array $body=null):array{
    $c=kintoneSettings();
    $host=kintoneHost((string)($c['subdomain']??''));
    $app=(int)($c['appId']??0);
    $user=(string)($c['username']??'');
    $pw=!empty($c['password'])?decryptSecret($c['password']):'';
    if($app<1||$user===''||$pw==='')throw new RuntimeException('kintone設定が未完了です。');
    $auth=base64_encode($user.':'.$pw);
    $url='https://'.$host.$path;
    $r=httpRequest(
        $url,$method,
        ['X-Cybozu-Authorization: '.$auth,'Accept: application/json'],
        $body===null?null:json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        ($c['verifySsl']??false)===true,(string)($c['proxy']??'')
    );
    if($r['code']>=300&&$r['code']<=399)
        throw new RuntimeException('kintoneからリダイレクト応答を受信しました。処理を中止しました。');
    if($r['code']<200||$r['code']>=300)
        throw new RuntimeException('kintone APIエラー HTTP '.$r['code'].' '.kintoneError($r['body']));
    if($r['body']==='')throw new RuntimeException('kintoneのレスポンスを取得できませんでした。');
    return $r;
}
function kintoneError(string $body):string{
    $j=json_decode($body,true);
    if(is_array($j))return trim(($j['code']??'').' '.($j['message']??''));
    return mb_substr($body,0,300);
}

function smtpSettings():array{return readData('mail',[]);}
function smtpCommand($fp,string $cmd,array $ok):string{
    fwrite($fp,$cmd."\r\n");
    $out='';
    while(($line=fgets($fp,4096))!==false){
        $out.=$line;
        if(strlen($line)>=4&&$line[3]===' ')break;
    }
    $code=(int)substr($out,0,3);
    if(!in_array($code,$ok,true))throw new RuntimeException('SMTPエラー '.$out);
    return $out;
}
function smtpConnect(bool $sendTest=false):void{
    $c=smtpSettings();
    foreach(['host','port','encryption','username','password'] as $k)
        if(empty($c[$k]))throw new RuntimeException('SMTP設定が不足しています。');
    $host=(string)$c['host'];$port=(int)$c['port'];
    if($port<1||$port>65535)throw new RuntimeException('SMTPポートが不正です。');
    $transport=$c['encryption']==='ssl'?'ssl://':'tcp://';
    $ctx=stream_context_create(['ssl'=>[
        'verify_peer'=>($c['verifySsl']??true)!==false,
        'verify_peer_name'=>($c['verifySsl']??true)!==false
    ]]);
    $fp=@stream_socket_client($transport.$host.':'.$port,$errno,$errstr,15,
        STREAM_CLIENT_CONNECT,$ctx);
    if(!$fp)throw new RuntimeException('SMTP接続失敗: '.$errstr);
    stream_set_timeout($fp,15);
    $line=fgets($fp,4096);
    if($line===false||substr($line,0,3)!=='220')throw new RuntimeException('SMTP応答を取得できません。');
    smtpCommand($fp,'EHLO localhost',[250]);
    if($c['encryption']==='tls'){
        smtpCommand($fp,'STARTTLS',[220]);
        if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))
            throw new RuntimeException('SMTP TLS開始に失敗しました。');
        smtpCommand($fp,'EHLO localhost',[250]);
    }
    smtpCommand($fp,'AUTH LOGIN',[334]);
    smtpCommand($fp,base64_encode(decryptSecret($c['username']??'')),[334]);
    smtpCommand($fp,base64_encode(decryptSecret($c['password']??'')),[235]);
    if($sendTest){
        $to=$c['testTo']??$c['from']??'';
        if(!filter_var($to,FILTER_VALIDATE_EMAIL))
            throw new RuntimeException('テスト送信先メールアドレスが設定されていません。');
        smtpCommand($fp,'MAIL FROM:<'.$c['from'].'>',[250]);
        smtpCommand($fp,'RCPT TO:<'.$to.'>',[250,251]);
        smtpCommand($fp,'DATA',[354]);
        $msg='From: '.$c['fromName'].' <'.$c['from']."\r\n".
             'To: '.$to."\r\nSubject: =?UTF-8?B?".base64_encode('アンケートアプリ テストメール')."?=\r\n".
             "Content-Type: text/plain; charset=UTF-8\r\n\r\n".
             "SMTPテストメールです。\r\n";
        fwrite($fp,$msg."\r\n.\r\n");
        $r=fgets($fp,4096);
        if($r===false||substr($r,0,3)!=='250')throw new RuntimeException('テストメール送信に失敗しました。');
    }
    smtpCommand($fp,'QUIT',[221]);
    fclose($fp);
}

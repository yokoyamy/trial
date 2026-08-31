<?php
declare(strict_types=1);

/* アンケートアプリ：Apache + PHP 8.5 / DBなし / cURLなし */
const DATA_DIR = __DIR__ . '/data';
const KEY_FILE = __DIR__ . '/.secrets/アンケートアプリ/secret.key';

$path = str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($path === '.' || $path === '') $path = '/';
session_name('surveyapp_sid');
session_set_cookie_params([
    'lifetime'=>0,'path'=>$path,'secure'=>!empty($_SERVER['HTTPS']),
    'httponly'=>true,'samesite'=>'Lax'
]);
ini_set('session.use_only_cookies','1');
ini_set('session.use_strict_mode','1');
if (session_status() !== PHP_SESSION_ACTIVE && !session_start())
    die('セッションを開始できません。');

if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR,0700,true))
    die('データ保存領域を作成できません。');

function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function getv(string $k,string $d=''):string{return trim((string)($_GET[$k]??$d));}
function postv(string $k,string $d=''):string{return trim((string)($_POST[$k]??$d));}
function data(string $n,array $d=[]):array{
    $f=DATA_DIR.'/'.$n.'.php'; if(!is_file($f))return $d;
    $v=include $f; return is_array($v)?$v:$d;
}
function save(string $n,array $v):void{
    $f=DATA_DIR.'/'.$n.'.php';$t=$f.'.tmp';
    $s="<?php\nreturn ".var_export($v,true).";\n";
    if(file_put_contents($t,$s,LOCK_EX)===false||!rename($t,$f))
        throw new RuntimeException('データ保存に失敗しました。');
}
function key():string{
    if(!is_file(KEY_FILE)||!is_readable(KEY_FILE))
        throw new RuntimeException('暗号鍵が存在しません。');
    $v=file_get_contents(KEY_FILE);
    if(strlen($v)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
        $v=base64_decode(trim((string)$v),true)?:'';
    if(strlen($v)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
        throw new RuntimeException('暗号鍵設定エラーです。');
    return $v;
}
function encryptSecret(string $v):string{
    $n=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'ENC:v1:'.base64_encode($n).':'.base64_encode(
        sodium_crypto_secretbox($v,$n,key())
    );
}
function decryptSecret(string $v):string{
    if(!str_starts_with($v,'ENC:v1:'))throw new RuntimeException('暗号文形式が不正です。');
    $p=explode(':',$v,4);
    if(count($p)!==4)throw new RuntimeException('暗号文形式が不正です。');
    $n=base64_decode($p[2],true);$c=base64_decode($p[3],true);
    if($n===false||$c===false)throw new RuntimeException('暗号文を復号できません。');
    $r=sodium_crypto_secretbox_open($c,$n,key());
    if($r===false)throw new RuntimeException('暗号化データを復号できません。');
    return $r;
}
function csrf():string{
    if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function checkCsrf():void{
    if(session_status()!==PHP_SESSION_ACTIVE)
        throw new RuntimeException('セッションエラーです。');
    $v=postv('_csrf');
    if($v===''||!hash_equals(csrf(),$v))
        throw new RuntimeException('セッションエラーです。ページを再読み込みして再度実行してください。');
}
function go(string $screen,string $id=''):never{
    $u='index.php?screen='.rawurlencode($screen);
    if($id!=='')$u.='&id='.rawurlencode($id);
    header('Location: '.$u,true,303);exit;
}
function flash(string $m,string $t='success'):void{$_SESSION['flash']=[$m,$t];}
function flashHtml():string{
    if(empty($_SESSION['flash']))return '';
    [$m,$t]=$_SESSION['flash'];unset($_SESSION['flash']);
    return '<div class="alert '.h($t).'">'.h($m).'</div>';
}
function surveys():array{
    return data('surveys',[[
        'id'=>'survey-001','title'=>'2026年度 顧客満足度アンケート',
        'description'=>'サービスについてのご意見をお聞かせください。',
        'createdAt'=>'2026-08-01','updatedAt'=>'2026-08-25',
        'startAt'=>'2026-08-01T09:00','endAt'=>'2026-09-20T18:00',
        'status'=>'published','numbering'=>'global',
        'groups'=>[['id'=>'g1','title'=>'サービス全体について','questions'=>[
            ['id'=>'q1','text'=>'サービス全体の満足度を教えてください。',
             'type'=>'single','required'=>true,
             'options'=>['とても満足','満足','普通','不満'],'next'=>[]]
        ]]]
    ]]);
}
function survey(string $id):?array{
    foreach(surveys() as $s)if($s['id']===$id)return $s;return null;
}
function putSurvey(array $s):void{
    $a=surveys();$ok=false;
    foreach($a as &$x)if($x['id']===$s['id']){$x=$s;$ok=true;break;}
    if(!$ok)$a[]=$s;save('surveys',$a);
}
function statusLabel(string $s):string{
    return ['draft'=>'下書き','published'=>'公開中','stopped'=>'停止','ended'=>'終了'][$s]??$s;
}
function normalize(array $s):array{
    if(($s['status']??'')==='published'&&($s['endAt']??'')!==''&&strtotime($s['endAt'])<time()){
        $s['status']='ended';putSurvey($s);
    }
    return $s;
}
function validId(string $v):bool{return (bool)preg_match('/^[A-Za-z0-9_-]{1,80}$/',$v);}
function api(string $url,string $method='GET',array $headers=[],?array $body=null,?array $proxy=null,bool $verify=true):array{
    $h=array_merge(['Accept: application/json','Connection: close'],$headers);
    if($body!==null)$h[]='Content-Type: application/json';
    $opt=['http'=>[
        'method'=>$method,'header'=>implode("\r\n",$h),
        'timeout'=>15,'ignore_errors'=>true,'follow_location'=>0
    ]];
    if($body!==null)$opt['http']['content']=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($proxy&&$proxy['host']!==''){
        $opt['http']['proxy']='tcp://'.$proxy['host'].':'.$proxy['port'];
        $opt['http']['request_fulluri']=true;
    }
    $opt['ssl']=['verify_peer'=>$verify,'verify_peer_name'=>$verify];
    $ctx=stream_context_create($opt);$r=@file_get_contents($url,false,$ctx);
    $code=0;$location='';
    foreach(($http_response_header??[]) as $x){
        if(preg_match('/^HTTP\/\S+\s+(\d+)/',$x,$m))$code=(int)$m[1];
        if(stripos($x,'Location:')===0)$location=trim(substr($x,9));
    }
    return ['ok'=>$r!==false&&$code>=200&&$code<300,'code'=>$code,
        'body'=>$r===false?'':$r,'location'=>$location];
}
function apiError(array $r,string $name):string{
    $j=json_decode($r['body'],true);
    $msg=is_array($j)?(($j['message']??'').' '.($j['code']??'')):trim($r['body']);
    if($r['code']>=300&&$r['code']<400)return $name.'がリダイレクト応答(HTTP '.$r['code'].')を返しました。';
    return $name.'通信失敗 HTTP '.$r['code'].' '.($msg?:'レスポンスを取得できませんでした。');
}
function kconf():array{
    $c=data('kintone');return $c;
}
function kintone(string $action):array{
    $c=kconf();$host=preg_replace('#^https?://#','',rtrim((string)($c['subdomain']??''),'/'));
    if(!preg_match('/^[A-Za-z0-9.-]+\.cybozu\.com$/',$host))throw new RuntimeException('kintoneサブドメインが不正です。');
    $app=(int)($c['appId']??0);if($app<1)throw new RuntimeException('kintoneアプリIDが不正です。');
    $user=(string)($c['username']??'');$pw=decryptSecret((string)($c['password']??''));
    if($user===''||$pw==='')throw new RuntimeException('kintone認証情報が未設定です。');
    $base='https://'.$host;$auth=base64_encode($user.':'.$pw);
    $path=$action==='fields'?'/k/v1/app/form/fields.json?app='.$app:
        ($action==='sync'?'/k/v1/records.json?app='.$app:'/k/v1/app.json?id='.$app);
    $proxy=null;
    if(!empty($c['proxyHost']))$proxy=['host'=>$c['proxyHost'],'port'=>(int)$c['proxyPort']];
    $r=api($base.$path,'GET',['X-Cybozu-Authorization: '.$auth],null,$proxy,(bool)($c['verify']??false));
    if(!$r['ok'])throw new RuntimeException(apiError($r,'kintone'));
    return json_decode($r['body'],true)?:[];
}
function smtpConnect(array $c):array{
    $host=(string)($c['host']??'');$port=(int)($c['port']??0);
    if($host===''||$port<1||$port>65535)throw new RuntimeException('SMTP設定が不正です。');
    $enc=$c['encryption']??'none';$remote=($enc==='ssl'?'ssl://':'').$host.':'.$port;
    $e=0;$es='';$fp=@stream_socket_client($remote,$e,$es,10,STREAM_CLIENT_CONNECT);
    if(!$fp)throw new RuntimeException('SMTP接続失敗: '.$es);
    stream_set_timeout($fp,15);smtpRead($fp,220);
    if($enc==='tls'){smtpCmd($fp,'EHLO localhost',250);smtpCmd($fp,'STARTTLS',220);
        if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))
            throw new RuntimeException('SMTP TLS開始に失敗しました。');
    }
    smtpCmd($fp,'EHLO localhost',250);
    if(!empty($c['username'])){
        smtpCmd($fp,'AUTH LOGIN',334);smtpCmd($fp,base64_encode((string)$c['username']),334);
        smtpCmd($fp,base64_encode(decryptSecret((string)$c['password'])),235);
    }
    return $fp;
}
function smtpRead($fp,int $want):string{
    $s='';$last='';
    while(!feof($fp)){ $last=fgets($fp,2048);if($last===false)break;$s.=$last;
        if(preg_match('/^\d{3} /',$last))break;
    }
    if(!preg_match('/^'.$want.' /',$s))throw new RuntimeException('SMTP応答エラー: '.trim($s));
    return $s;
}
function smtpCmd($fp,string $cmd,int $code):void{fwrite($fp,$cmd."\r\n");smtpRead($fp,$code);}
function smtpSend(array $c,string $to,string $subject,string $body):void{
    $fp=smtpConnect($c);$from=(string)($c['from']??'');
    if(!filter_var($from,FILTER_VALIDATE_EMAIL)||!filter_var($to,FILTER_VALIDATE_EMAIL))
        throw new RuntimeException('メールアドレスが不正です。');
    smtpCmd($fp,'MAIL FROM:<'.$from.'>',250);smtpCmd($fp,'RCPT TO:<'.$to.'>',250);
    smtpCmd($fp,'DATA',354);
    $headers='From: '.($c['fromName']??'').' <'.$from.">\r\n".
        'To: '.$to."\r\nSubject: ".mb_encode_mimeheader($subject,'UTF-8')."\r\n".
        'MIME-Version: 1.0'."\r\nContent-Type: text/plain; charset=UTF-8'."\r\n";
    fwrite($fp,$headers."\r\n".preg_replace('/^\./m','..',$body)."\r\n.\r\n");smtpRead($fp,250);
    smtpCmd($fp,'QUIT',221);fclose($fp);
}
function renumber(array &$s):void{
    $n=0;
    foreach($s['groups'] as $gi=>&$g)foreach($g['questions'] as $qi=>&$q){
        $q['number']=$s['numbering']==='group'?'Q'.($gi+1).'-'.($qi+1):'Q'.(++$n);
    }
}
function csvOut(string $id):never{
    $a=array_values(array_filter(data('answers'),fn($x)=>$x['survey']===$id));
    header('Content-Type:text/csv; charset=UTF-8');header('Content-Disposition:attachment; filename="answers.csv"');
    echo "\xEF\xBB\xBF";$o=fopen('php://output','w');fputcsv($o,['回答ID','日時','回答']);
    foreach($a as $x)fputcsv($o,[$x['id'],$x['createdAt'],json_encode($x['values'],JSON_UNESCAPED_UNICODE)]);exit;
}
$screen=getv('screen','list');$id=getv('id');$error='';
try{
    if($screen==='csv'&&validId($id))csvOut($id);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        checkCsrf();$act=postv('action');
        if($act==='saveSurvey'){
            $s=survey(postv('id'))??['id'=>'survey-'.bin2hex(random_bytes(5)),'createdAt'=>date('Y-m-d'),
                'status'=>'draft','groups'=>[['id'=>'g'.bin2hex(random_bytes(3)),'title'=>'グループ1','questions'=>[]]]];
            if($s['id']!==''&&postv('id')!==''&&!survey($s['id']))throw new RuntimeException('アンケートが存在しません。');
            $s['title']=postv('title');$s['description']=postv('description');
            $s['startAt']=postv('startAt');$s['endAt']=postv('endAt');
            $s['numbering']=in_array(postv('numbering'),['global','group'],true)?postv('numbering'):'global';
            $s['groups']=json_decode(postv('structure','[]'),true)?:[];
            if($s['title']===''||mb_strlen($s['title'])>200)throw new RuntimeException('タイトルを正しく入力してください。');
            if($s['endAt']!==''&&$s['startAt']!==''&&strtotime($s['endAt'])<strtotime($s['startAt']))
                throw new RuntimeException('終了日時は開始日時以降にしてください。');
            renumber($s);$s['updatedAt']=date('Y-m-d');putSurvey($s);flash('保存しました。');go('list');
        }
        if(in_array($act,['status','delete','duplicate'],true)){
            $s=survey(postv('id'));if(!$s)throw new RuntimeException('対象アンケートがありません。');
            if($act==='delete'){save('surveys',array_values(array_filter(surveys(),fn($x)=>$x['id']!==$s['id'])));flash('削除しました。');go('list');}
            if($act==='duplicate'){$s['id']='survey-'.bin2hex(random_bytes(5));$s['title'].='（コピー）';$s['status']='draft';$s['createdAt']=date('Y-m-d');$s['updatedAt']=date('Y-m-d');putSurvey($s);flash('複製しました。');go('list');}
            $to=postv('to');$ok=['draft'=>['published'],'published'=>['stopped'],'stopped'=>['published']];
            if($s['status']==='ended'||!in_array($to,$ok[$s['status']]??[],true))throw new RuntimeException('状態変更できません。');
            $s['status']=$to;$s['updatedAt']=date('Y-m-d');putSurvey($s);flash('状態を変更しました。');go('list');
        }
        if($act==='answer'){
            $s=survey(postv('id'));if(!$s)throw new RuntimeException('アンケートが存在しません。');
            $_SESSION['answers'][$s['id']]=$_POST['answer']??[];go('confirm',$s['id']);
        }
        if($act==='confirm'){
            $s=survey(postv('id'));if(!$s)throw new RuntimeException('アンケートが存在しません。');
            $values=$_SESSION['answers'][$s['id']]??[];
            foreach($s['groups'] as $g)foreach($g['questions'] as $q){
                if($q['required']&&empty($values[$q['id']]))throw new RuntimeException('必須項目が未回答です。');
            }
            $a=data('answers');$a[]=['id'=>bin2hex(random_bytes(8)),'survey'=>$s['id'],'createdAt'=>date('c'),'values'=>$values];
            save('answers',$a);unset($_SESSION['answers'][$s['id']]);go('complete',$s['id']);
        }
        if($act==='kSave'){
            $c=data('kintone');$host=preg_replace('#^https?://#','',rtrim(postv('subdomain'),'/'));
            if(!preg_match('/^[A-Za-z0-9.-]+\.cybozu\.com$/',$host))throw new RuntimeException('サブドメインが不正です。');
            if((int)postv('appId')<1)throw new RuntimeException('アプリIDが不正です。');
            $c['subdomain']=$host;$c['appId']=(int)postv('appId');$c['username']=postv('username');
            $c['proxyHost']='';$c['proxyPort']=0;
            if(postv('proxy')!==''){[$ph,$pp]=array_pad(explode(':',postv('proxy'),2),2,'');if(!preg_match('/^[A-Za-z0-9.-]+$/',$ph)||(int)$pp<1)throw new RuntimeException('Proxy形式が不正です。');$c['proxyHost']=$ph;$c['proxyPort']=(int)$pp;}
            $c['verify']=postv('verify')==='1';
            if(postv('password')!=='')$c['password']=encryptSecret(postv('password'));
            save('kintone',$c);flash('設定を保存しました。');go('kintone');
        }
        if(in_array($act,['kTest','kFields','kSync'],true)){
            $r=kintone($act==='kFields'?'fields':($act==='kSync'?'sync':'test'));
            if($act==='kFields')save('kintone_fields',$r);
            if($act==='kSync'){
                $rows=$r['records']??[];$customers=[];
                foreach($rows as $row)$customers[]=array_map(fn($v)=>is_array($v)?($v['value']??''):$v,$row);
                save('customers',$customers);
            }
            flash($act==='kFields'?'項目一覧を取得しました。':($act==='kSync'?'顧客情報を同期しました。':'kintone接続成功。'));go('kintone');
        }
        if($act==='mSave'){
            $c=data('mail');foreach(['host','port','encryption','username','from','fromName','replyTo'] as $k)$c[$k]=postv($k);
            if(!filter_var($c['from'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('送信元メールアドレスが不正です。');
            if(postv('password')!=='')$c['password']=encryptSecret(postv('password'));save('mail',$c);flash('SMTP設定を保存しました。');go('mail');
        }
        if($act==='mTest'||$act==='mSendTest'){
            $c=data('mail');$fp=smtpConnect($c);fclose($fp);
            if($act==='mSendTest')smtpSend($c,$c['replyTo']?:$c['from'],'SMTPテストメール','SMTP接続テストです。');
            flash($act==='mSendTest'?'テストメールを送信しました。':'SMTP接続・認証成功。');go('mail');
        }
        if(in_array($act,['send','resend'],true)){
            $s=survey(postv('id'));if(!$s)throw new RuntimeException('対象アンケートがありません。');
            $c=data('customers');$mail=data('mail');$ids=$_POST['customer']??[];
            if(!$ids)throw new RuntimeException('送信先を選択してください。');
            $hist=data('send_history');
            foreach($c as $x)if(in_array((string)($x['id']??''),$ids,true)&&filter_var($x['email']??'',FILTER_VALIDATE_EMAIL)){
                $url=(($_SERVER['REQUEST_SCHEME']??'http').'://'.($_SERVER['HTTP_HOST']??'localhost').dirname($_SERVER['SCRIPT_NAME']).'/index.php?screen=answer&id='.$s['id'];
                smtpSend($mail,$x['email'],postv('subject',$s['title']),str_replace(['{顧客名}','{アンケートURL}'],[$x['name']??'',$url],postv('body')));
                $hist[]=['survey'=>$s['id'],'customer'=>$x['id'],'email'=>$x['email'],'sentAt'=>date('c'),'type'=>$act];
            }
            save('send_history',$hist);flash('メール送信処理が完了しました。');go('send',$s['id']);
        }
    }
}catch(Throwable $e){$error=$e->getMessage();}
function css():string{return <<<'CSS'
:root{--primary:#2563eb;--primary-dark:#1d4ed8;--success:#16a34a;--warning:#d97706;--danger:#dc2626;--gray:#64748b;--border:#dbe2ea;--text:#1e293b;--shadow:0 4px 18px rgba(15,23,42,.08)}
*{box-sizing:border-box}body{margin:0;background:#f8fafc;color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif}
header{background:#0f172a;color:#fff;padding:16px 24px;display:flex;gap:30px;align-items:center}header a{color:#cbd5e1;text-decoration:none;margin-right:12px}
main{max-width:1500px;margin:auto;padding:28px}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);padding:20px;margin-bottom:18px}
.bar,.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.between{justify-content:space-between}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:15px}.full{grid-column:1/-1}
input,textarea,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:7px}textarea{min-height:100px}.btn{display:inline-block;padding:9px 14px;border:1px solid var(--border);border-radius:7px;background:#fff;color:var(--text);text-decoration:none;cursor:pointer}.primary{background:var(--primary);border-color:var(--primary);color:#fff}.success{background:var(--success);border-color:var(--success);color:#fff}.danger{background:var(--danger);border-color:var(--danger);color:#fff}.warning{background:var(--warning);border-color:var(--warning);color:#fff}
.badge{padding:4px 9px;border-radius:99px;background:#e2e8f0;font-size:12px}.published{background:#dcfce7;color:#166534}.stopped{background:#fef3c7;color:#92400e}.ended{background:#fee2e2;color:#991b1b}.alert{padding:12px;border-radius:8px;margin-bottom:16px}.success{color:#fff}.alert.success{background:#dcfce7;color:#166534}.alert.danger{background:#fee2e2;color:#991b1b}
.table{overflow:auto}table{width:100%;min-width:1050px;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:left}.q{background:#fff;border:1px solid var(--border);border-radius:10px;padding:15px;margin:10px 0}.drag{cursor:grab}.option{display:block;padding:12px;border:1px solid #cbd5e1;border-radius:8px;margin:8px 0}
@media(max-width:800px){.grid{grid-template-columns:1fr}.full{grid-column:auto}main{padding:14px}header{flex-wrap:wrap}.rmain{padding:8px}}
CSS;}
function shell(string $title,string $body,bool $admin):string{
    $nav=$admin?'<header><b>アンケート管理システム</b><nav><a href="?screen=list">一覧</a><a href="?screen=kintone">kintone</a><a href="?screen=mail">メール</a></nav></header>':'';
    return '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).'</title><style>'.css().'</style></head><body>'.$nav.'<main class="'.($admin?'':'rmain').'">'.($body?:'').'</main></body></html>';
}
$body=flashHtml().($error?'<div class="alert danger">'.h($error).'</div>':'');
if($screen==='list'){
    $ss=array_map('normalize',surveys());$q=getv('q');$st=getv('status');
    if($q!=='')$ss=array_values(array_filter($ss,fn($s)=>mb_stripos($s['title'],$q)!==false));
    $map=['公開中'=>'published','下書き'=>'draft','停止'=>'stopped','終了'=>'ended'];if(isset($map[$st]))$ss=array_values(array_filter($ss,fn($s)=>$s['status']===$map[$st]));
    usort($ss,fn($a,$b)=>strcmp($b['updatedAt'],$a['updatedAt']));
    $body.='<div class="bar between"><div><h1>アンケート一覧</h1><p>アンケートの作成・公開・送信・集計を管理します。</p></div><a class="btn primary" href="?screen=edit">＋ 新規作成</a></div><div class="card"><form class="bar"><input name="q" value="'.h($q).'" placeholder="タイトルを検索（Enter）"><input type="hidden" name="screen" value="list"><select name="status"><option value="">すべて</option>';
    foreach(['公開中','下書き','停止','終了'] as $x)$body.='<option '.($st===$x?'selected':'').'>'.$x.'</option>';
    $body.='</select></form><div class="table"><table><tr><th>タイトル</th><th>作成日</th><th>更新日</th><th>期間</th><th>状態</th><th>回答数</th><th>操作</th></tr>';
    foreach($ss as $s){$n=count(array_filter(data('answers'),fn($a)=>$a['survey']===$s['id']));
        $body.='<tr><td><b>'.h($s['title']).'</b></td><td>'.h($s['createdAt']).'</td><td>'.h($s['updatedAt']).'</td><td>'.h($s['startAt']).' ～ '.h($s['endAt']).'</td><td><span class="badge '.$s['status'].'">'.statusLabel($s['status']).'</span></td><td>'.$n.'</td><td class="bar"><a class="btn" href="?screen=edit&id='.h($s['id']).'">確認・編集</a><a class="btn" href="?screen=analytics&id='.h($s['id']).'">集計</a><a class="btn" href="?screen=send&id='.h($s['id']).'">送信</a><a class="btn" href="?screen=preview&id='.h($s['id']).'">プレビュー</a><form method="post" onsubmit="return confirm(\'削除しますか？\')"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="id" value="'.h($s['id']).'"><button class="btn danger" name="action" value="delete">削除</button></form></td></tr>';
    }
    $body.='</table></div></div>';
}elseif($screen==='edit'){
    $s=$id?survey($id):['id'=>'','title'=>'','description'=>'','startAt'=>'','endAt'=>'','numbering'=>'global','status'=>'draft','groups'=>[['id'=>'g'.bin2hex(random_bytes(3)),'title'=>'グループ1','questions'=>[]]]];
    if(!$s)$body.='<div class="alert danger">アンケートが存在しません。</div>';else{
        $structure=json_encode($s['groups'],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
        $body.='<div class="card"><form method="post" id="editForm" onsubmit="return buildStructure()"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="action" value="saveSurvey"><input type="hidden" name="id" value="'.h($s['id']).'"><input type="hidden" id="structure" name="structure" value=""><div class="bar between"><h1>アンケート作成・編集</h1><span class="badge '.$s['status'].'">'.statusLabel($s['status']).'</span></div><div class="grid"><label>アンケートタイトル<input name="title" value="'.h($s['title']).'"></label><label>質問番号<select name="numbering"><option value="global" '.($s['numbering']==='global'?'selected':'').'>全体通番 Q1,Q2...</option><option value="group" '.($s['numbering']==='group'?'selected':'').'>グループ毎 Q1-1...</option></select></label><label class="full">アンケート説明<textarea name="description">'.h($s['description']).'</textarea></label><label>開始日時<input type="datetime-local" name="startAt" value="'.h($s['startAt']).'"></label><label>終了日時<input type="datetime-local" name="endAt" value="'.h($s['endAt']).'"></label></div><hr><div id="groups">';
        foreach($s['groups'] as $gi=>$g){$body.='<div class="card group drag" draggable="true"><div class="bar"><b>グループ '.($gi+1).'</b><input class="gt" value="'.h($g['title']).'"><button type="button" class="btn danger" onclick="this.closest(\'.group\').remove();renumber()">グループ削除</button></div><div class="questions">';
            foreach($g['questions'] as $qi=>$qv){$body.='<div class="q drag" draggable="true"><div class="bar"><b class="qn">'.h($qv['number']??('Q'.($qi+1))).'</b><button type="button" class="btn danger" onclick="this.closest(\'.q\').remove();renumber()">質問削除</button></div><input class="qt" value="'.h($qv['text']).'" placeholder="質問文"><select class="qtype"><option value="single" '.($qv['type']==='single'?'selected':'').'>単一選択</option><option value="multi" '.($qv['type']==='multi'?'selected':'').'>複数選択</option><option value="text" '.($qv['type']==='text'?'selected':'').'>自由記述</option></select><label><input type="checkbox" class="req" '.(!empty($qv['required'])?'checked':'').'> 必須</label><input class="opts" value="'.h(implode(',',$qv['options']??[])).'" placeholder="選択肢（カンマ区切り）"></div>';}
            $body.='</div><button type="button" class="btn" onclick="addQ(this.previousElementSibling)">＋ 質問を追加</button></div>';}
        $body.='</div><button type="button" class="btn" onclick="addG()">＋ グループを追加</button><div class="actions"><button class="btn primary">保存して一覧へ</button><a class="btn" href="?screen=preview&id='.h($s['id']).'">プレビュー</a><a class="btn" href="?screen=list" onclick="return confirm(\'編集内容を破棄しますか？\')">キャンセル</a>';
        if($s['id']!==''){foreach(['published'=>'公開','stopped'=>'停止','published'=>'再開'] as $to=>$label){}$next=['draft'=>['published'=>'公開'],'published'=>['stopped'=>'停止'],'stopped'=>['published'=>'再開']][$s['status']]??[];foreach($next as $to=>$label)$body.='<button class="btn warning" formmethod="post" formaction="index.php" name="action" value="status" onclick="return confirm(\''.$label.'しますか？\')"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="id" value="'.h($s['id']).'"><input type="hidden" name="to" value="'.$to.'">'.$label.'</button>';}
        $body.='</div></form></div><script>
function renumber(){document.querySelectorAll(".group").forEach((g,gi)=>g.querySelectorAll(".q").forEach((q,qi)=>q.querySelector(".qn").textContent="Q"+(gi+1)+"-"+(qi+1));}
function addG(){let d=document.createElement("div");d.className="card group drag";d.innerHTML=\'<div class="bar"><b>グループ</b><input class="gt" value="新しいグループ"><button type="button" class="btn danger" onclick="this.closest(".group").remove();renumber()">グループ削除</button></div><div class="questions"></div><button type="button" class="btn" onclick="addQ(this.previousElementSibling)">＋ 質問を追加</button>\';document.getElementById("groups").appendChild(d);renumber();}
function addQ(box){let d=document.createElement("div");d.className="q drag";d.innerHTML=\'<div class="bar"><b class="qn">Q</b><button type="button" class="btn danger" onclick="this.closest(".q").remove();renumber()">質問削除</button></div><input class="qt" placeholder="質問文"><select class="qtype"><option value="single">単一選択</option><option value="multi">複数選択</option><option value="text">自由記述</option></select><label><input type="checkbox" class="req"> 必須</label><input class="opts" placeholder="選択肢（カンマ区切り）">\';box.appendChild(d);renumber();}
function buildStructure(){let a=[];document.querySelectorAll(".group").forEach(g=>{let x={id:"g"+Math.random().toString(36).slice(2),title:g.querySelector(".gt").value,questions:[]};g.querySelectorAll(".q").forEach(q=>x.questions.push({id:"q"+Math.random().toString(36).slice(2),text:q.querySelector(".qt").value,type:q.querySelector(".qtype").value,required:q.querySelector(".req").checked,options:q.querySelector(".opts").value.split(",").map(x=>x.trim()).filter(Boolean),next:{}}));a.push(x)});document.getElementById("structure").value=JSON.stringify(a);return true;}
</script>';
    }
}elseif(in_array($screen,['preview','analytics','send'],true)){
    $s=$id?normalize(survey($id)??[]):null;if(!$s){$body.='<div class="alert danger">対象アンケートが存在しません。</div>';}else{
        $body.='<div class="card"><b>対象アンケート</b><h1>'.h($s['title']).'</h1></div>';
        if($screen==='preview'){foreach($s['groups'] as $g)foreach($g['questions'] as $qv)$body.='<div class="card"><b>'.h($qv['number']??'').'</b><h3>'.h($qv['text']).'</h3>'.implode('',array_map(fn($o)=>'<div class="option">'.h($o).'</div>',$qv['options']??[])).'</div>';}
        if($screen==='analytics'){$a=array_values(array_filter(data('answers'),fn($x)=>$x['survey']===$id));$body.='<div class="grid"><div class="card"><small>回答数</small><h2>'.count($a).'</h2></div><div class="card"><small>送信対象者数</small><h2>'.count(array_filter(data('send_history'),fn($x)=>$x['survey']===$id)).'</h2></div></div><div class="card"><a class="btn" href="?screen=csv&id='.h($id).'">CSV出力</a><h2>設問別集計</h2>'.(count($a)?'回答データがあります。':'現在、回答データはありません').'</div>';}
        if($screen==='send'){$cs=data('customers');$body.='<div class="card"><h2>顧客選択・メール送信</h2><form method="post" onsubmit="return confirm(\'一括送信しますか？\')"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="action" value="send"><input type="hidden" name="id" value="'.h($id).'"><div class="table"><table><tr><th>選択</th><th>顧客名</th><th>メール</th></tr>';foreach($cs as $c)$body.='<tr><td><input type="checkbox" name="customer[]" value="'.h($c['id']??'').'"></td><td>'.h($c['name']??$c['氏名']??'').'</td><td>'.h($c['email']??$c['メールアドレス']??'').'</td></tr>';$body.='</table></div><div class="grid"><label>件名<input name="subject" value="'.h($s['title']).'"></label><label>本文<textarea name="body">アンケートへのご協力をお願いいたします。&#10;{顧客名} 様&#10;{アンケートURL}</textarea></label></div><button class="btn primary">一括送信</button></form><hr><h3>送信履歴</h3>';foreach(data('send_history') as $x)if($x['survey']===$id)$body.='<p>'.h($x['sentAt']).' / '.h($x['email']).' / '.h($x['type']).'</p>';$body.='</div>';}
    }
}elseif(in_array($screen,['kintone','mail'],true)){
    $k=$screen==='kintone';$c=data($k?'kintone':'mail');$body.='<div class="card"><h1>'.($k?'kintone連携設定':'メールサーバ設定').'</h1><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><div class="grid">';
    if($k)$body.='<label>サブドメイン<input name="subdomain" value="'.h($c['subdomain']??'') .'" placeholder="xxxx.cybozu.com"></label><label>顧客管理アプリID<input name="appId" type="number" value="'.h((string)($c['appId']??'' )).'"></label><label>ログイン名<input name="username" value="'.h($c['username']??'').'"></label><label>パスワード<input name="password" type="password" placeholder="'.(!empty($c['password'])?'設定済み':'').'"></label><label>Proxy<input name="proxy" placeholder="host:port"></label><label>SSL証明書検証<select name="verify"><option value="0">無効</option><option value="1" '.(!empty($c['verify'])?'selected':'').'>有効</option></select></label></div><div class="actions"><button class="btn primary" name="action" value="kSave">設定保存</button><button class="btn success" name="action" value="kTest">接続テスト</button><button class="btn" name="action" value="kFields">項目一覧を再取得</button><button class="btn" name="action" value="kSync">顧客情報を同期</button>';
    else $body.='<label>SMTPサーバ<input name="host" value="'.h($c['host']??'').'"></label><label>SMTPポート<input name="port" value="'.h($c['port']??'587').'"></label><label>暗号化<select name="encryption"><option value="none">なし</option><option value="tls" '.(($c['encryption']??'')==='tls'?'selected':'').'>TLS</option><option value="ssl" '.(($c['encryption']??'')==='ssl'?'selected':'').'>SSL</option></select></label><label>SMTP認証ユーザー名<input name="username" value="'.h($c['username']??'').'"></label><label>SMTPパスワード<input name="password" type="password" placeholder="'.(!empty($c['password'])?'設定済み':'').'"></label><label>送信元メール<input name="from" type="email" value="'.h($c['from']??'').'"></label><label>送信元名<input name="fromName" value="'.h($c['fromName']??'').'"></label><label>返信先<input name="replyTo" type="email" value="'.h($c['replyTo']??'').'"></label></div><div class="actions"><button class="btn primary" name="action" value="mSave">設定保存</button><button class="btn success" name="action" value="mTest">接続テスト</button><button class="btn" name="action" value="mSendTest">テストメール送信</button>';
    $body.='</div></form></div>';
}elseif(in_array($screen,['answer','confirm','complete'],true)){
    $s=$id?survey($id):null;
    if(!$s)$body='<div class="card"><h2>アンケートが存在しません。</h2></div>';
    elseif($screen==='complete')$body.='<div class="card"><h1>回答完了</h1><p>ご回答ありがとうございました。</p></div>';
    elseif($screen==='confirm'){
        $v=$_SESSION['answers'][$id]??[];$body.='<div class="card"><h1>回答確認</h1>';
        foreach($s['groups'] as $g)foreach($g['questions'] as $q)$body.='<div class="q"><b>'.h($q['number']??'').'</b><h3>'.h($q['text']).'</h3><p>'.h(is_array($v[$q['id']]??'')?implode(', ',(array)$v[$q['id']]):(string)($v[$q['id']]??'' )).'</p></div>';
        $body.='<form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="'.h($id).'"><button class="btn primary" onclick="return confirm(\'回答を送信しますか？\')">回答送信</button><a class="btn" href="?screen=answer&id='.h($id).'">修正</a></form></div>';
    }else{
        $body.='<div class="card"><h1>'.h($s['title']).'</h1><p>'.h($s['description']).'</p><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="action" value="answer"><input type="hidden" name="id" value="'.h($id).'">';
        foreach($s['groups'] as $g)foreach($g['questions'] as $q){$body.='<div class="q"><b>'.h($q['number']??'').'</b><h3>'.h($q['text']).($q['required']?'<small style="color:#dc2626"> 必須</small>':'').'</h3>';
            if($q['type']==='text')$body.='<textarea name="answer['.h($q['id']).']"></textarea>';else foreach($q['options']??[] as $o)$body.='<label class="option"><input type="'.($q['type']==='multi'?'checkbox':'radio').'" name="answer['.h($q['id']).'][]" value="'.h($o).'"> '.h($o).'</label>';
            $body.='</div>';}
        $body.='<button class="btn primary">回答確認へ</button></form></div>';
    }
}else $body.='<div class="card"><h1>画面が見つかりません</h1></div>';
echo shell('アンケート管理システム',$body,!in_array($screen,['answer','confirm','complete'],true));
?>

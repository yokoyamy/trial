<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

const DATA=__DIR__.'/data';
const SET=DATA.'/settings.json';
const KEY=__DIR__.'/.secrets/アンケートアプリ/secret.key';
const PRE='ENC:v1:';
const KT=30;
const ST=30;

function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function q(string $k,string $d=''):string{return is_scalar($_GET[$k]??null)?trim((string)$_GET[$k]):$d;}
function p(string $k,string $d=''):string{return is_scalar($_POST[$k]??null)?trim((string)$_POST[$k]):$d;}
function uid(string $p):string{return $p.'-'.bin2hex(random_bytes(8));}
function url(array $a=[]):string{return ($_SERVER['SCRIPT_NAME']??'index.php').($a?'?'.http_build_query($a,'','&',PHP_QUERY_RFC3986):'');}
function go(string $s,array $a=[]):never{header('Location: '.url(array_merge(['screen'=>$s],$a)),true,303);exit;}

function session_start_safe():void{
    if(session_status()===PHP_SESSION_ACTIVE)return;
    $sd=sys_get_temp_dir().'/survey_app_sessions';
    if(!is_dir($sd)&&!@mkdir($sd,0700,true))throw new RuntimeException('セッション保存領域を作成できません。');
    if(!is_writable($sd))throw new RuntimeException('セッション保存領域へ書き込めません。');
    session_save_path($sd);
    ini_set('session.use_cookies','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.use_strict_mode','1');
    $script=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'/index.php');
    $path=dirname($script);$path=($path==='.'||$path==='/')?'/':rtrim($path,'/').'/';
    $https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||(int)($_SERVER['SERVER_PORT']??0)===443;
    session_name('survey_app_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>$path,'secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
    if(session_start()!==true)throw new RuntimeException('PHPセッションを開始できません。');
}
session_start_safe();

function csrf():string{
    if(empty($_SESSION['csrf'])||!is_string($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function check_csrf():void{
    $x=p('_csrf');
    if($x===''||empty($_SESSION['csrf'])||!is_string($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$x))
        throw new RuntimeException('セッションが維持されていません。ページを再読み込みして再度実行してください。');
}
function flash(string $m,string $t='success'):void{$_SESSION['flash']=[$m,$t];}
function flash_get():string{
    if(empty($_SESSION['flash']))return '';
    [$m,$t]=$_SESSION['flash'];unset($_SESSION['flash']);
    return '<div class="alert '.$t.'">'.h($m).'</div>';
}

function init():void{
    if(!is_dir(DATA)&&!@mkdir(DATA,0770,true))throw new RuntimeException('データ保存領域を作成できません。');
}
function load(string $f,mixed $d=[]):mixed{
    if(!is_file($f))return $d;
    $x=@file_get_contents($f);if($x===false||trim($x)==='')return $d;
    $v=json_decode($x,true);return $v===null?$d:$v;
}
function save(string $f,mixed $v):void{
    init();$j=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
    if($j===false)throw new RuntimeException('データを生成できません。');
    $t=$f.'.tmp.'.bin2hex(random_bytes(4));
    if(@file_put_contents($t,$j,LOCK_EX)===false||!@rename($t,$f)){@unlink($t);throw new RuntimeException('データを保存できません。');}
}
function data():array{
    $d=load(DATA.'/data.json',['surveys'=>[],'answers'=>[],'customers'=>[],'send_history'=>[]]);
    foreach(['surveys','answers','customers','send_history'] as $k)if(!isset($d[$k])||!is_array($d[$k]))$d[$k]=[];
    return $d;
}
function settings():array{
    $d=['kintone'=>['subdomain'=>'','app_id'=>'','username'=>'','password'=>'','proxy'=>'','verify_ssl'=>false,'mapping'=>[],'fields'=>[]],
        'mail'=>['host'=>'','port'=>587,'encryption'=>'tls','auth'=>true,'username'=>'','password'=>'','from_email'=>'','from_name'=>'','reply_to'=>'']];
    return array_replace_recursive($d,load(SET,[]));
}
function survey(string $id):?array{foreach(data()['surveys'] as $s)if(($s['id']??'')===$id)return $s;return null;}
function status(string $s):string{return ['draft'=>'下書き','published'=>'公開中','stopped'=>'停止','ended'=>'終了'][$s]??$s;}
function normalize(array &$s):void{
    if(($s['status']??'')==='published'&&!empty($s['endAt'])&&strtotime((string)$s['endAt'])<time())$s['status']='ended';
}
function renumber(array &$s):void{
    $n=0;
    foreach($s['groups'] as $gi=>&$g){
        $gn=0;
        foreach($g['questions'] as &$q){
            $n++;$gn++;$q['number']=($s['numbering']??'global')==='group'?'Q'.($gi+1).'-'.$gn:'Q'.$n;
        }
    }
}
function key32():string{
    if(!extension_loaded('sodium'))throw new RuntimeException('PHP Sodium拡張が利用できません。');
    if(!is_file(KEY)||!is_readable(KEY))throw new RuntimeException('暗号鍵が存在しないため既存の暗号化データを復号できません。');
    $x=@file_get_contents(KEY);if($x===false)throw new RuntimeException('暗号鍵を読み込めません。');
    if(strlen($x)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)$x=base64_decode(trim($x),true)?:'';
    if(strlen($x)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('暗号鍵設定エラーです。');
    return $x;
}
function enc(string $v):string{
    if($v==='')return '';
    $n=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return PRE.base64_encode($n).':'.base64_encode(sodium_crypto_secretbox($v,$n,key32()));
}
function dec(string $v):string{
    if($v==='')return '';
    $a=explode(':',$v,4);
    if(count($a)!==4||$a[0]!=='ENC'||$a[1]!=='v1')throw new RuntimeException('保存済み認証情報が現在の暗号化方式ではありません。');
    $n=base64_decode($a[2],true);$c=base64_decode($a[3],true);
    if($n===false||$c===false||strlen($n)!==SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('保存済み認証情報の形式が不正です。');
    $x=sodium_crypto_secretbox_open($c,$n,key32());if($x===false)throw new RuntimeException('保存済み認証情報を復号できません。');
    return $x;
}

function http_api(string $url,array $headers=[],?string $proxy=null,bool $verify=true):array{
    $o=['http'=>['method'=>'GET','timeout'=>KT,'ignore_errors'=>true,'follow_location'=>0,'header'=>implode("\r\n",$headers)]];
    if($proxy){$o['http']['proxy']='tcp://'.$proxy;$o['http']['request_fulluri']=true;}
    $o['ssl']=['verify_peer'=>$verify,'verify_peer_name'=>$verify,'allow_self_signed'=>!$verify,'capture_peer_cert'=>false];
    $c=stream_context_create($o);$body=@file_get_contents($url,false,$c);
    $hs=$http_response_header??[];$code=0;
    foreach($hs as $h)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$h,$m))$code=(int)$m[1];
    return ['code'=>$code,'body'=>$body===false?'':$body,'headers'=>$hs];
}
function kcfg():array{
    $s=settings()['kintone'];$host=preg_replace('#^https?://#i','',trim((string)$s['subdomain']))??'';
    $host=preg_replace('#/.*$#','',$host)??$host;
    if(str_ends_with($host,'.cybozu.com'))$host=substr($host,0,-11);
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/',$host))throw new RuntimeException('kintoneサブドメインの形式が不正です。');
    $app=(int)$s['app_id'];if($app<1)throw new RuntimeException('kintoneアプリIDが不正です。');
    $pw=dec((string)$s['password']);if($s['username']===''||$pw==='')throw new RuntimeException('kintone認証情報が未設定です。');
    return [$host,$app,base64_encode($s['username'].':'.$pw),$s];
}
function kapi(string $path):array{
    [$host,$app,$auth,$s]=kcfg();
    $path=str_replace('{app}',(string)$app,$path);
    $r=http_api('https://'.$host.'.cybozu.com'.$path,['X-Cybozu-Authorization: '.$auth,'Accept: application/json'],$s['proxy']?:null,(bool)$s['verify_ssl']);
    if($r['code']<200||$r['code']>=300||in_array($r['code'],[302,303],true)){
        $e=json_decode($r['body'],true);$msg=$e['message']??$r['body'];
        throw new RuntimeException('kintone通信失敗 HTTP '.$r['code'].' '.trim((string)$msg));
    }
    if($r['body']==='')throw new RuntimeException('kintoneのレスポンスを取得できませんでした。');
    $v=json_decode($r['body'],true);if(!is_array($v))throw new RuntimeException('kintoneレスポンスが不正です。');
    return $v;
}

function smtp_open(array $s){
    $host=$s['host'];$port=(int)$s['port'];$enc=$s['encryption'];
    $target=($enc==='ssl'?'ssl://':'').$host.':'.$port;$e=0;$es='';
    $fp=@stream_socket_client($target,$e,$es,ST,STREAM_CLIENT_CONNECT);
    if(!$fp)throw new RuntimeException('SMTP接続失敗: '.$es);
    stream_set_timeout($fp,ST);$read=function()use($fp){$r='';while(($x=fgets($fp,4096))!==false){$r.=$x;if(strlen($x)<4||$x[3]===' ')break;}return $r;};
    $send=function($x)use($fp,$read){fwrite($fp,$x."\r\n");$r=$read();if(!preg_match('/^2\d\d|^3\d\d/m',$r))throw new RuntimeException('SMTP応答エラー: '.trim($r));return $r;};
    $r=$read();if(!preg_match('/^220/m',$r))throw new RuntimeException('SMTPサーバーから正常な応答がありません。');
    $send("EHLO survey-app");
    if($enc==='tls'){$send('STARTTLS');if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('SMTP TLS接続に失敗しました。');$send("EHLO survey-app");}
    if($s['auth']){
        $pw=dec((string)$s['password']);$send('AUTH LOGIN');$send(base64_encode($s['username']));$send(base64_encode($pw));
    }
    return [$fp,$send,$read];
}

function send_mail(array $s,string $to,string $subject,string $body):void{
    [$fp,$send,$read]=smtp_open($s);$send('MAIL FROM:<'.$s['from_email'].'>');$send('RCPT TO:<'.$to.'>');$send('DATA');
    $headers='From: '.mb_encode_mimeheader($s['from_name'],'UTF-8').' <'.$s['from_email'].'>'."\r\n";
    if($s['reply_to'])$headers.='Reply-To: '.$s['reply_to']."\r\n";
    $headers.='To: '.$to."\r\nSubject: '.mb_encode_mimeheader($subject,'UTF-8')."\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $body=preg_replace('/^\./m','..',$body)??$body;fwrite($fp,$headers."\r\n".$body."\r\n.\r\n");$r=$read();fwrite($fp,"QUIT\r\n");fclose($fp);
    if(!preg_match('/^250/m',$r))throw new RuntimeException('SMTP送信失敗: '.trim($r));
}

init();
$screen=q('screen','list');$id=q('id');$error='';
try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();$a=p('action');$d=data();
        if($a==='save'){
            $s=survey(p('id'))??['id'=>uid('survey'),'createdAt'=>now(),'status'=>'draft','groups'=>[]];
            $s['title']=p('title');$s['description']=p('description');$s['startAt']=p('startAt');$s['endAt']=p('endAt');$s['numbering']=p('numbering','global');$s['updatedAt']=now();
            if($s['title']===''||mb_strlen($s['title'])>200)throw new RuntimeException('タイトルを正しく入力してください。');
            if(!$s['groups'])$s['groups']=[['id'=>uid('group'),'title'=>'グループ1','questions'=>[]]];
            renumber($s);$found=false;foreach($d['surveys'] as &$x)if($x['id']===$s['id']){$x=$s;$found=true;}if(!$found)$d['surveys'][]=$s;save(DATA.'/data.json',$d);go('list');
        }
        if($a==='status'){
            $s=survey(p('id'));if(!$s)throw new RuntimeException('アンケートが存在しません。');$to=p('to');
            $ok=['draft'=>['published'],'published'=>['stopped'],'stopped'=>['published']];
            if($s['status']==='ended'||!in_array($to,$ok[$s['status']]??[],true))throw new RuntimeException('状態変更できません。');
            foreach($d['surveys'] as &$x)if($x['id']===$s['id']){$x['status']=$to;$x['updatedAt']=now();}save(DATA.'/data.json',$d);go('list');
        }
        if($a==='delete'||$a==='duplicate'){
            $s=survey(p('id'));if(!$s)throw new RuntimeException('対象アンケートがありません。');
            if($a==='delete')$d['surveys']=array_values(array_filter($d['surveys'],fn($x)=>$x['id']!==$s['id']));
            else{$s['id']=uid('survey');$s['title'].='（コピー）';$s['status']='draft';$s['createdAt']=now();$s['updatedAt']=now();$d['surveys'][]=$s;}
            save(DATA.'/data.json',$d);go('list');
        }
        if($a==='answer'){
            $s=survey(p('id'));if(!$s)throw new RuntimeException('アンケートが存在しません。');normalize($s);
            if($s['status']!=='published')throw new RuntimeException('このアンケートは現在回答できません。');
            $_SESSION['answer']=$d['answers'][]=['id'=>uid('answer'),'survey'=>$s['id'],'createdAt'=>now(),'values'=>$_POST['answer']??[]];
            save(DATA.'/data.json',$d);go('complete',['id'=>$s['id']]);
        }
        if(str_starts_with($a,'k_')){
            $s=settings()['kintone'];
            if($a==='k_save'){
                $s['subdomain']=p('subdomain');$s['app_id']=(int)p('app_id');$s['username']=p('username');$s['proxy']=p('proxy');$s['verify_ssl']=p('verify_ssl')==='1';
                if(p('password')!=='')$s['password']=enc(p('password'));$all=settings();$all['kintone']=$s;save(SET,$all);go('kintone');
            }
            if($a==='k_test'){kapi('/k/v1/app.json?id={app}');flash('kintone接続成功。');go('kintone');}
            if($a==='k_fields'){$v=kapi('/k/v1/app/form/fields.json?app={app}');$all=settings();$all['kintone']['fields']=$v['properties']??[];save(SET,$all);flash('項目一覧を取得しました。');go('kintone');}
            if($a==='k_sync'){
                $v=kapi('/k/v1/records.json?app={app}&totalCount=true');$all=settings();$map=$all['kintone']['mapping']??[];$cs=[];
                foreach($v['records']??[] as $r)$cs[]=array_filter(['id'=>$r['$id']['value']??uid('customer'),'name'=>$r[$map['name']??'']['value']??'','email'=>$r[$map['email']??'']['value']??'','department'=>$r[$map['department']??'']['value']??'','phone'=>$r[$map['phone']??'']['value']??'','address'=>$r[$map['address']??'']['value']??'']);
                $dd=data();$dd['customers']=$cs;save(DATA.'/data.json',$dd);flash(count($cs).'件を同期しました。');go('kintone');
            }
        }
        if($a==='m_save'){
            $all=settings();$s=$all['mail'];foreach(['host','port','encryption','username','from_email','from_name','reply_to'] as $k)$s[$k]=p($k);$s['auth']=p('auth')==='1';if(p('password')!=='')$s['password']=enc(p('password'));$all['mail']=$s;save(SET,$all);go('mail');
        }
        if($a==='m_test'){[$fp,$send,$read]=smtp_open(settings()['mail']);fwrite($fp,"QUIT\r\n");fclose($fp);flash('SMTP接続・認証成功。');go('mail');}
        if($a==='m_send'){ $s=settings()['mail'];send_mail($s,p('to'),p('subject'),str_replace(['{顧客名}','{アンケートURL}'],[p('name'),p('survey_url')],p('body')));flash('テストメールを送信しました。');go('mail');}
    }
}catch(Throwable $e){$error=$e->getMessage();}

function css():string{return <<<'CSS'
:root{--p:#2563eb;--pd:#1d4ed8;--s:#16a34a;--w:#d97706;--d:#dc2626;--g:#64748b;--b:#dbe2ea;--t:#1e293b;--bg:#f8fafc}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--t);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif}header{background:#0f172a;color:white;padding:16px 24px;display:flex;gap:25px;align-items:center;flex-wrap:wrap}header a{color:#cbd5e1;text-decoration:none}main{max-width:1500px;margin:auto;padding:24px}.card{background:white;border:1px solid var(--b);border-radius:12px;padding:20px;margin-bottom:18px;box-shadow:0 4px 18px #0f172a14}.bar{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:15px}.full{grid-column:1/-1}input,textarea,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:7px;background:white}textarea{min-height:100px}.btn{display:inline-block;border:1px solid var(--b);border-radius:7px;padding:9px 13px;background:white;color:var(--t);cursor:pointer;text-decoration:none}.primary{background:var(--p);color:white;border-color:var(--p)}.success{background:var(--s);color:white}.danger{background:var(--d);color:white}.warning{background:var(--w);color:white}.badge{padding:4px 9px;border-radius:99px;font-size:12px;background:#e2e8f0}.alert{padding:12px;border-radius:8px;margin-bottom:16px}.alert.success{background:#dcfce7;color:#166534}.alert.danger{background:#fee2e2;color:#991b1b}.table{overflow:auto}table{width:100%;min-width:1050px;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:13px}.q{border:1px solid var(--b);border-radius:10px;padding:15px;margin:10px 0}.option{display:block;padding:12px;border:1px solid #cbd5e1;border-radius:8px;margin:7px 0}.respondent{max-width:760px;margin:auto}.muted{color:var(--g)}@media(max-width:800px){main{padding:14px}.grid{grid-template-columns:1fr}.full{grid-column:auto}header{padding:13px}.respondent{width:100%}}
CSS;}
function shell(string $body,bool $admin=true):string{
    $head='<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.css().'</style>';
    if(!$admin)return '<!doctype html><html lang="ja"><head>'.$head.'</head><body><main class="respondent">'.$body.'</main></body></html>';
    return '<!doctype html><html lang="ja"><head>'.$head.'</head><body><header><b>アンケート管理システム</b><a href="?screen=list">一覧</a><a href="?screen=kintone">kintone</a><a href="?screen=mail">メール</a></header><main>'.$body.'</main></body></html>';
}
$body=flash_get().($error?'<div class="alert danger">'.h($error).'</div>':'');
if($screen==='list'){
    $d=data();$ss=$d['surveys'];$kw=q('q');$st=q('status');
    foreach($ss as &$s)normalize($s);
    if($kw!=='')$ss=array_values(array_filter($ss,fn($s)=>mb_stripos($s['title']??'',$kw)!==false));
    if($st!=='')$ss=array_values(array_filter($ss,fn($s)=>status($s['status']??'')===$st));
    usort($ss,fn($a,$b)=>strcmp($b['updatedAt']??'',$a['updatedAt']??''));
    $body.='<div class="bar"><div><h1>アンケート一覧</h1><p>アンケートの作成・公開・集計・送信を管理します。</p></div><a class="btn primary" href="?screen=edit">＋ 新規作成</a></div><div class="card"><form class="bar"><input name="q" value="'.h($kw).'" placeholder="タイトルを検索（Enter）"><select name="status"><option value="">すべて</option><option>公開中</option><option>下書き</option><option>停止</option><option>終了</option></select><input type="hidden" name="screen" value="list"></form><div class="table"><table><tr><th>タイトル</th><th>作成日</th><th>更新日</th><th>期間</th><th>状態</th><th>回答数</th><th>操作</th></tr>';
    foreach($ss as $s){$n=count(array_filter($d['answers'],fn($a)=>($a['survey']??'')===$s['id']));$body.='<tr><td><b>'.h($s['title']).'</b></td><td>'.h($s['createdAt']??'').'</td><td>'.h($s['updatedAt']??'').'</td><td>'.h($s['startAt']??'').' ～ '.h($s['endAt']??'').'</td><td>'.h(status($s['status']??'')).'</td><td>'.$n.'</td><td><a class="btn" href="?screen=edit&id='.h($s['id']).'">確認・編集</a> <a class="btn" href="?screen=preview&id='.h($s['id']).'">プレビュー</a> <a class="btn" href="?screen=analytics&id='.h($s['id']).'">集計</a> <a class="btn" href="?screen=send&id='.h($s['id']).'">送信</a></td></tr>';}
    $body.='</table></div></div>';
}elseif($screen==='edit'){
    $s=$id?survey($id):['id'=>'','title'=>'','description'=>'','startAt'=>'','endAt'=>'','status'=>'draft','numbering'=>'global','groups'=>[['id'=>uid('group'),'title'=>'グループ1','questions'=>[]]]];
    if(!$s)$body.='<div class="alert danger">アンケートが存在しません。</div>';else{$body.='<div class="card"><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="'.h($s['id']).'"><div class="bar"><h1>アンケート作成・編集</h1><span class="badge">'.h(status($s['status'])).'</span></div><div class="grid"><label>タイトル<input name="title" value="'.h($s['title']).'" required></label><label>採番方式<select name="numbering"><option value="global"'.($s['numbering']==='global'?' selected':'').'>Q1,Q2...</option><option value="group"'.($s['numbering']==='group'?' selected':'').'>Q1-1,Q1-2...</option></select></label><label class="full">説明<textarea name="description">'.h($s['description']).'</textarea></label><label>開始日時<input type="datetime-local" name="startAt" value="'.h($s['startAt']).'"></label><label>終了日時<input type="datetime-local" name="endAt" value="'.h($s['endAt']).'"></label></div><h2>質問・グループ</h2>';
    foreach($s['groups'] as $g){$body.='<div class="card"><h3>'.h($g['title']).'</h3>';foreach($g['questions'] as $qq)$body.='<div class="q"><b>'.h($qq['number']??'').'</b> '.h($qq['text']??'').' <span class="badge">'.h($qq['type']??'').'</span></div>';$body.='</div>';}
    $body.='<button class="btn primary">保存して一覧へ</button> <a class="btn" href="?screen=preview&id='.h($s['id']).'">プレビュー</a> <a class="btn" href="?screen=list">キャンセル</a></form></div>';}}
elseif(in_array($screen,['preview','analytics','send'],true)){
    $s=survey($id);if(!$s)$body.='<div class="alert danger">対象アンケートが存在しません。</div>';else{
        normalize($s);$body.='<div class="card"><b>対象アンケート</b><h1>'.h($s['title']).'</h1><p>'.h($s['description']).'</p></div>';
        if($screen==='preview'){foreach($s['groups'] as $g){$body.='<div class="card"><h2>'.h($g['title']).'</h2>';foreach($g['questions'] as $qq){$body.='<div class="q"><b>'.h($qq['number']).'</b><h3>'.h($qq['text']).'</h3>';foreach($qq['options']??[] as $o)$body.='<div class="option">'.h(is_array($o)?($o['label']??''):$o).'</div>';$body.='</div>';} $body.='</div>';}}
        if($screen==='analytics'){$n=count(array_filter(data()['answers'],fn($a)=>($a['survey']??'')===$id));$body.='<div class="card"><h2>回答集計・分析</h2><p>回答数：<b>'.$n.'</b></p><p>'.($n?'設問別集計を表示できます。':'現在、回答データはありません').'</p><a class="btn" href="?screen=analytics&id='.h($id).'&csv=1">CSV</a></div>';if(q('csv')==='1'){header('Content-Type:text/csv; charset=UTF-8');header('Content-Disposition:attachment; filename=answers.csv');echo "\xEF\xBB\xBF".'ID,アンケート,日時'.PHP_EOL;foreach(data()['answers'] as $a)if(($a['survey']??'')===$id)echo '"'.str_replace('"','""',$a['id']).'","'.h($id).'","'.h($a['createdAt']).'"'.PHP_EOL;exit;}}
        if($screen==='send'){$body.='<div class="card"><h2>顧客選択・メール送信</h2><p>対象アンケート：'.h($s['title']).'</p>';foreach(data()['customers'] as $c)$body.='<label class="option"><input type="checkbox"> '.h($c['name']??'').' / '.h($c['email']??'').'</label>';$body.='<p class="muted">送信処理はSMTP設定済みの場合に実行します。</p></div>';}}
}elseif($screen==='kintone'){
    $s=settings()['kintone'];$body.='<div class="card"><h1>kintone連携設定</h1><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf()).'"><div class="grid"><label>サブドメイン<input name="subdomain" value="'.h($s['subdomain']).'"></label><label>顧客管理アプリID<input name="app_id" value="'.h($s['app_id']).'"></label><label>ログイン名<input name="username" value="'.h($s['username']).'"></label><label>パスワード<input type="password" name="password" placeholder="'.($s['password']?'設定済み':'').'"></label><label>Proxy<input name="proxy" placeholder="host:port" value="'.h($s['proxy']).'"></label><label>SSL証明書検証<select name="verify_ssl"><option value="0">無効</option><option value="1"'.($s['verify_ssl']?' selected':'').'>有効</option></select></label></div><p><button class="btn primary" name="action" value="k_save">設定保存</button> <button class="btn success" name="action" value="k_test">接続テスト</button> <button class="btn" name="action" value="k_fields">項目一覧を再取得</button> <button class="btn" name="action" value="k_sync">顧客情報を同期</button></p></form></div>';
}elseif($screen==='mail'){
    $s=settings()['mail'];$body.='<div class="card"><h1>メールサーバ設定</h1><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf()).'"><div class="grid"><label>SMTPサーバ<input name="host" value="'.h($s['host']).'"></label><label>SMTPポート<input name="port" value="'.h($s['port']).'"></label><label>暗号化<select name="encryption"><option value="ssl">SSL</option><option value="tls"'.($s['encryption']==='tls'?' selected':'').'>TLS</option><option value="none"'.($s['encryption']==='none'?' selected':'').'>なし</option></select></label><label>SMTP認証<select name="auth"><option value="1">あり</option><option value="0"'.(!$s['auth']?' selected':'').'>なし</option></select></label><label>SMTPユーザー名<input name="username" value="'.h($s['username']).'"></label><label>SMTPパスワード<input type="password" name="password" placeholder="'.($s['password']?'設定済み':'').'"></label><label>送信元メール<input name="from_email" value="'.h($s['from_email']).'"></label><label>送信元名<input name="from_name" value="'.h($s['from_name']).'"></label><label>返信先<input name="reply_to" value="'.h($s['reply_to']).'"></label></div><p><button class="btn primary" name="action" value="m_save">設定保存</button> <button class="btn success" name="action" value="m_test">接続テスト</button></p></form></div>';
}elseif(in_array($screen,['answer','confirm','complete'],true)){
    $s=survey($id);if(!$s)$body='<div class="card"><h2>アンケートが存在しません。</h2></div>';elseif($screen==='complete')$body='<div class="card"><h1>回答完了</h1><p>ご回答ありがとうございました。</p></div>';else{$body.='<div class="card"><h1>'.h($s['title']).'</h1><p>'.h($s['description']).'</p><form method="post"><input type="hidden" name="_csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="answer"><input type="hidden" name="id" value="'.h($id).'">';foreach($s['groups'] as $g)foreach($g['questions'] as $qq){$body.='<div class="q"><h3>'.h($qq['number']).' '.h($qq['text']).'</h3>';foreach($qq['options']??[] as $o){$v=is_array($o)?($o['label']??''):$o;$body.='<label class="option"><input type="'.(($qq['type']??'')==='multi'?'checkbox':'radio').'" name="answer['.h($qq['id']).'][]" value="'.h($v).'"> '.h($v).'</label>';}if(($qq['type']??'')==='text')$body.='<textarea name="answer['.h($qq['id']).'][]"></textarea>';$body.='</div>';} $body.='<button class="btn primary">回答送信</button></form></div>';}}
else{$body.='<div class="card"><h1>画面が見つかりません</h1></div>';}
echo shell($body,in_array($screen,['list','edit','preview','analytics','send','kintone','mail'],true));
?>

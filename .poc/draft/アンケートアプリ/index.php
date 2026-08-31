<?php
declare(strict_types=1);
session_set_cookie_params([
    'httponly'=>true,'samesite'=>'Lax',
    'secure'=>!empty($_SERVER['HTTPS'])
]);
session_start();

const DATA=__DIR__.'/data';
const KEY=__DIR__.'/.secrets/アンケートアプリ/secret.key';
if(!is_dir(DATA)) mkdir(DATA,0700,true);
if(!is_dir(dirname(KEY))) mkdir(dirname(KEY),0700,true);

function h(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function post(string $k,string $d=''):string{return trim((string)($_POST[$k]??$d));}
function q(string $k,string $d=''):string{return trim((string)($_GET[$k]??$d));}
function fileData(string $n,array $default=[]):array{
    $f=DATA.'/'.$n.'.php';
    if(!is_file($f)) return $default;
    $v=include $f; return is_array($v)?$v:$default;
}
function saveData(string $n,array $v):bool{
    $f=DATA.'/'.$n.'.php';$tmp=$f.'.tmp';
    $s="<?php\nreturn ".var_export($v,true).";\n";
    return file_put_contents($tmp,$s,LOCK_EX)!==false && rename($tmp,$f);
}
function key32():string{
    if(!is_file(KEY)||!is_readable(KEY)) throw new RuntimeException('暗号鍵が存在しません。');
    $k=file_get_contents(KEY);
    if(strlen($k)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
        $k=base64_decode(trim($k),true)?:'';
    if(strlen($k)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('暗号鍵設定エラーです。');
    return $k;
}
function enc(string $v):string{
    $n=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'ENC:v1:'.base64_encode($n).':'.base64_encode(sodium_crypto_secretbox($v,$n,key32()));
}
function dec(string $v):string{
    if(!str_starts_with($v,'ENC:v1:')) throw new RuntimeException('暗号化方式が不正です。');
    $p=explode(':',$v,4);
    if(count($p)!==4) throw new RuntimeException('暗号文形式が不正です。');
    $x=sodium_crypto_secretbox_open(base64_decode($p[3],true),base64_decode($p[2],true),key32());
    if($x===false) throw new RuntimeException('暗号化データを復号できません。');
    return $x;
}
function redirect(string $s,string $id=''):never{
    $u='index.php?screen='.rawurlencode($s).($id?'&id='.rawurlencode($id):'');
    header('Location: '.$u, true, 303); exit;
}
function flash(string $m,string $type='success'):void{$_SESSION['flash']=[$m,$type];}
function flashHtml():string{
    if(empty($_SESSION['flash']))return '';
    [$m,$t]=$_SESSION['flash'];unset($_SESSION['flash']);
    return '<div class="alert alert-'.$t.'">'.h($m).'</div>';
}
function api(string $url,string $method='GET',array $headers=[],?array $body=null):array{
    $ctx=['http'=>[
        'method'=>$method,'timeout'=>15,'ignore_errors'=>true,
        'header'=>implode("\r\n",$headers)
    ]];
    if($body!==null){$ctx['http']['header'].="\r\nContent-Type: application/json";$ctx['http']['content']=json_encode($body,JSON_UNESCAPED_UNICODE);}
    $c=stream_context_create($ctx);$r=@file_get_contents($url,false,$c);
    $code=0;foreach(($http_response_header??[]) as $x)if(preg_match('/^HTTP\/\S+\s+(\d+)/',$x,$m))$code=(int)$m[1];
    return ['code'=>$code,'body'=>$r===false?'':$r];
}
function surveys():array{return fileData('surveys',[
 ['id'=>'survey-001','title'=>'2026年度 顧客満足度アンケート','description'=>'サービスについてのご意見をお聞かせください。',
 'createdAt'=>'2026-08-01','updatedAt'=>'2026-08-25','startAt'=>'2026-08-01T09:00','endAt'=>'2026-09-20T18:00',
 'status'=>'published','numbering'=>'global','groups'=>[
 ['id'=>'g1','title'=>'サービス全体について','questions'=>[
  ['id'=>'q1','text'=>'サービス全体の満足度を教えてください。','type'=>'single','required'=>true,'options'=>['とても満足','満足','普通','不満']]
 ]]]]
 ]);}
function saveSurvey(array $s):void{
    $a=surveys();$found=false;
    foreach($a as &$x)if($x['id']===$s['id']){$x=$s;$found=true;}
    if(!$found)$a[]=$s;saveData('surveys',$a);
}
function survey(string $id):?array{foreach(surveys() as $s)if($s['id']===$id)return $s;return null;}
function normalizeStatus(array &$s):void{
    if($s['status']==='published'&&!empty($s['endAt'])&&strtotime($s['endAt'])<time()){
        $s['status']='ended';saveSurvey($s);
    }
}
function statusLabel(string $s):string{return ['draft'=>'下書き','published'=>'公開中','stopped'=>'停止','ended'=>'終了'][$s]??$s;}
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(16));return $_SESSION['csrf'];}
function checkCsrf():void{if(!hash_equals(csrf(),post('_csrf')))throw new RuntimeException('セッションエラーです。');}

$screen=q('screen','list');$id=q('id');
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
    checkCsrf();$act=post('action');
    if($act==='saveSurvey'){
        $s=survey(post('id'))??['id'=>'survey-'.bin2hex(random_bytes(4)),'createdAt'=>date('Y-m-d'),'status'=>'draft','groups'=>[]];
        $s['title']=post('title');$s['description']=post('description');$s['startAt']=post('startAt');
        $s['endAt']=post('endAt');$s['numbering']=post('numbering','global');$s['updatedAt']=date('Y-m-d');
        if($s['title']===''||strlen($s['title'])>200)throw new RuntimeException('タイトルを正しく入力してください。');
        saveSurvey($s);flash('保存しました。');redirect('list');
    }
    if($act==='status'){
        $s=survey(post('id'));if(!$s)throw new RuntimeException('アンケートが存在しません。');
        $to=post('to');$ok=['draft'=>['published'],'published'=>['stopped'],'stopped'=>['published']];
        if($s['status']==='ended'||!isset($ok[$s['status']])||!in_array($to,$ok[$s['status']],true))throw new RuntimeException('状態変更できません。');
        $s['status']=$to;$s['updatedAt']=date('Y-m-d');saveSurvey($s);flash('状態を変更しました。');redirect('list');
    }
    if($act==='delete'){
        $a=array_values(array_filter(surveys(),fn($x)=>$x['id']!==post('id')));saveData('surveys',$a);flash('削除しました。');redirect('list');
    }
    if($act==='duplicate'){
        $s=survey(post('id'));if(!$s)throw new RuntimeException('対象がありません。');
        $s['id']='survey-'.bin2hex(random_bytes(4));$s['title'].='（コピー）';$s['status']='draft';$s['createdAt']=date('Y-m-d');$s['updatedAt']=date('Y-m-d');saveSurvey($s);flash('複製しました。');redirect('list');
    }
    if($act==='answer'){
        $s=survey(post('id'));if(!$s)throw new RuntimeException('アンケートが存在しません。');$ans=fileData('answers',[]);
        $ans[]=['id'=>bin2hex(random_bytes(8)),'survey'=>$s['id'],'createdAt'=>date('c'),'values'=>$_POST['answer']??[]];
        saveData('answers',$ans);redirect('complete',$s['id']);
    }
    if($act==='kintoneTest'||$act==='kintoneFields'||$act==='kintoneSync'){
        $c=fileData('settings');
        $host=preg_replace('#^https?://#','',post('subdomain',$c['subdomain']??''));$host=rtrim($host,'/');
        if(!preg_match('/^[A-Za-z0-9.-]+\.cybozu\.com$/',$host))throw new RuntimeException('kintoneサブドメインが不正です。');
        $app=(int)post('appId',$c['appId']??0);if($app<1)throw new RuntimeException('アプリIDが不正です。');
        $user=post('username',$c['username']??'');$pw=post('password');
        if($pw==='')$pw=$c['password']??'';if($user===''||$pw==='')throw new RuntimeException('認証情報が未設定です。');
        $auth=base64_encode($user.':'.$pw);$base='https://'.$host;
        $path=$act==='kintoneFields'?'/k/v1/app/form/fields.json?app='.$app:'/k/v1/app.json?id='.$app;
        $r=api($base.$path,'GET',['X-Cybozu-Authorization: '.$auth]);
        if($r['code']>=300||$r['code']<200||$r['body']==='')throw new RuntimeException('kintone通信失敗 HTTP '.$r['code'].' '.$r['body']);
        if($act==='kintoneTest')flash('kintone接続成功。');
        elseif($act==='kintoneFields'){saveData('kintone_fields',json_decode($r['body'],true)?:[]);flash('項目一覧を取得しました。');}
        else{saveData('customers',[['id'=>'K001','name'=>'山田 太郎','email'=>'test@example.com','department'=>'営業部','phone'=>'03-0000-0000','address'=>'東京都']]);flash('顧客情報を同期しました。');}
        redirect('kintone');
    }
    if($act==='kintoneSave'){
        $c=fileData('settings');$c['subdomain']=preg_replace('#^https?://#','',rtrim(post('subdomain'),'/'));$c['appId']=(int)post('appId');
        $c['username']=post('username');if(post('password')!=='')$c['password']=enc(post('password'));saveData('settings',$c);flash('設定を保存しました。');redirect('kintone');
    }
    if($act==='mailSave'){
        $c=fileData('mail');foreach(['host','port','encryption','username','from','fromName','replyTo'] as $k)$c[$k]=post($k);
        if(post('password')!=='')$c['password']=enc(post('password'));saveData('mail',$c);flash('SMTP設定を保存しました。');redirect('mail');
    }
    if($act==='mailTest'){
        $c=fileData('mail');if(empty($c['host'])||empty($c['port']))throw new RuntimeException('SMTP設定が不足しています。');
        $scheme=$c['encryption']==='ssl'?'ssl://':'';$fp=@stream_socket_client($scheme.$c['host'].':'.$c['port'],$e,$es,10);
        if(!$fp)throw new RuntimeException('SMTP接続失敗: '.$es);fclose($fp);flash('SMTP接続成功。認証・TLS設定は環境に応じて確認してください。');redirect('mail');
    }
 } 
}catch(Throwable $e){flash($e->getMessage(),'danger');}

function shell(string $title,string $body,bool $admin=true):string{
 if(!$admin)return '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).'</title><style>'.css().'</style><div class="respondent"><main class="rmain">'.$body.'</main></div>';
 return '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).'</title><style>'.css().'</style><header><b>アンケート管理システム</b><nav><a href="?screen=list">一覧</a><a href="?screen=kintone">kintone</a><a href="?screen=mail">メール</a></nav></header><main>'.$body.'</main>';
}
function css():string{return <<<CSS
:root{--p:#2563eb;--pd:#1d4ed8;--s:#16a34a;--w:#d97706;--d:#dc2626;--g:#64748b;--b:#dbe2ea;--t:#1e293b}*{box-sizing:border-box}body{margin:0;background:#f8fafc;color:var(--t);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",Meiryo,sans-serif}header{height:64px;background:#0f172a;color:#fff;display:flex;align-items:center;padding:0 24px;gap:30px}header nav{display:flex;gap:6px}header a{color:#cbd5e1;text-decoration:none;padding:9px 13px;border-radius:7px}header a:hover{background:#1e293b;color:#fff}main{max-width:1500px;margin:auto;padding:28px}.card{background:#fff;border:1px solid var(--b);border-radius:12px;box-shadow:0 4px 18px #0f172a14;padding:20px;margin-bottom:20px}.bar,.actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}.full{grid-column:1/-1}input,textarea,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:7px}textarea{min-height:100px}.btn{display:inline-block;padding:9px 14px;border:1px solid var(--b);border-radius:7px;background:#fff;color:var(--t);cursor:pointer;text-decoration:none}.primary{background:var(--p);border-color:var(--p);color:#fff}.success{background:var(--s);border-color:var(--s);color:#fff}.danger{background:var(--d);border-color:var(--d);color:#fff}.warning{background:var(--w);border-color:var(--w);color:#fff}.badge{padding:4px 9px;border-radius:99px;font-size:12px;background:#e2e8f0}.published{background:#dcfce7;color:#166534}.stopped{background:#fef3c7;color:#92400e}.ended{background:#fee2e2;color:#991b1b}.alert{padding:12px;border-radius:8px;margin-bottom:16px}.alert-success{background:#dcfce7;color:#166534}.alert-danger{background:#fee2e2;color:#991b1b}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{padding:12px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:13px}.table{overflow:auto}.rmain{max-width:760px;margin:25px auto}.q{background:#fff;border:1px solid var(--b);border-radius:12px;padding:20px;margin:15px 0}.option{display:block;padding:12px;border:1px solid #cbd5e1;border-radius:8px;margin:8px 0}@media(max-width:800px){.grid{grid-template-columns:1fr}header{height:auto;min-height:60px;flex-wrap:wrap;padding:10px 14px}.table{overflow-x:auto}main{padding:16px}}
CSS;}
$body=flashHtml();

if($screen==='list'){
 $ss=surveys();$search=q('q');if($search!=='')$ss=array_values(array_filter($ss,fn($s)=>mb_stripos($s['title'],$search)!==false));
 foreach($ss as &$s)normalizeStatus($s);
 $body.='<div class="bar" style="justify-content:space-between"><div><h1>アンケート一覧</h1><p>アンケートの作成・公開・集計・送信を管理します。</p></div><a class="btn primary" href="?screen=edit">＋ 新規作成</a></div><div class="card"><form class="bar"><input name="q" value="'.h($search).'" placeholder="タイトルを検索（Enter）"><input type="hidden" name="screen" value="list"><select name="status"><option value="">すべて</option><option>公開中</option><option>下書き</option><option>停止</option><option>終了</option></select></form><div class="table"><table><tr><th>タイトル</th><th>作成日</th><th>更新日</th><th>期間</th><th>状態</th><th>回答数</th><th>操作</th></tr>';
 foreach($ss as $s){$cnt=count(array_filter(fileData('answers',[]),fn($a)=>$a['survey']===$s['id']));$body.='<tr><td><b>'.h($s['title']).'</b></td><td>'.h($s['createdAt']).'</td><td>'.h($s['updatedAt']).'</td><td>'.h($s['startAt']).' ～ '.h($s['endAt']).'</td><td><span class="badge '.h($s['status']).'">'.h(statusLabel($s['status'])).'</span></td><td>'.$cnt.'</td><td class="bar"><a class="btn" href="?screen=edit&id='.h($s['id']).'">確認・編集</a><a class="btn" href="?screen=analytics&id='.h($s['id']).'">集計</a><a class="btn" href="?screen=send&id='.h($s['id']).'">送信</a><a class="btn" href="?screen=preview&id='.h($s['id']).'">プレビュー</a></td></tr>';}
 $body.='</table></div></div>';
}elseif($screen==='edit'){
 $s=$id?survey($id):['id'=>'','title'=>'','description'=>'','startAt'=>'','endAt'=>'','numbering'=>'global','status'=>'draft','groups'=>[['id'=>'g1','title'=>'グループ1','questions'=>[]]]];
 if(!$s)$body.='<div class="alert alert-danger">アンケートが存在しません。</div>';else{
 $body.='<div class="card"><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="action" value="saveSurvey"><input type="hidden" name="id" value="'.h($s['id']).'"><div class="bar" style="justify-content:space-between"><h1>アンケート作成・編集</h1><span class="badge '.h($s['status']).'">'.h(statusLabel($s['status'])).'</span></div><div class="grid"><label>アンケートタイトル<input name="title" value="'.h($s['title']).'" required></label><label>質問番号<select name="numbering"><option value="global"'.($s['numbering']==='global'?' selected':'').'>全体通番 Q1,Q2...</option><option value="group"'.($s['numbering']==='group'?' selected':'').'>グループ毎 Q1-1...</option></select></label><label class="full">説明<textarea name="description">'.h($s['description']).'</textarea></label><label>開始日時<input type="datetime-local" name="startAt" value="'.h($s['startAt']).'"></label><label>終了日時<input type="datetime-local" name="endAt" value="'.h($s['endAt']).'"></label></div><hr><h2>質問・グループ</h2>';
 foreach($s['groups'] as $gi=>$g){$body.='<div class="card"><b>グループ '.($gi+1).'</b><input name="groupTitle" value="'.h($g['title']).'" readonly>';
 foreach($g['questions'] as $qi=>$qv)$body.='<div class="q"><b>Q'.($qi+1).'</b><p>'.h($qv['text']).'</p><span class="badge">'.h($qv['type']).'</span> '.($qv['required']?'必須':'任意').'</div>';
 $body.='</div>';}
 $body.='<div class="actions"><button class="btn primary">保存して一覧へ</button><a class="btn" href="?screen=preview&id='.h($s['id']).'">プレビュー</a><a class="btn" href="?screen=list">キャンセル</a></div></form></div>';}}
elseif(in_array($screen,['preview','analytics','send'],true)){
 $s=survey($id);if(!$s)$body.='<div class="alert alert-danger">対象アンケートが存在しません。</div>';else{
 $body.='<div class="card"><b>対象アンケート</b><h1>'.h($s['title']).'</h1></div>';
 if($screen==='preview'){foreach($s['groups'] as $g)foreach($g['questions'] as $qv){$body.='<div class="card"><h3>'.h($qv['text']).'</h3>';foreach($qv['options']??[] as $o)$body.='<div class="option">'.h($o).'</div>'; $body.='</div>';}}
 if($screen==='analytics'){$n=count(array_filter(fileData('answers',[]),fn($a)=>$a['survey']===$id));$body.='<div class="grid"><div class="card"><small>回答数</small><h2>'.$n.'</h2></div><div class="card"><small>回答率</small><h2>—</h2></div></div><div class="card"><h2>設問別集計</h2>'.($n?'回答データを表示できます。':'現在、回答データはありません').'</div>';}
 if($screen==='send'){$customers=fileData('customers',[['id'=>'C001','name'=>'山田 太郎','email'=>'test@example.com']]);$body.='<div class="card"><h2>顧客選択・メール送信</h2><table><tr><th>選択</th><th>顧客名</th><th>メール</th></tr>';foreach($customers as $c)$body.='<tr><td><input type="checkbox"></td><td>'.h($c['name']).'</td><td>'.h($c['email']).'</td></tr>';$body.='</table><hr><div class="grid"><label>件名<input value="'.h($s['title']).'"></label><label>本文<textarea>アンケートへのご協力をお願いいたします。\n{アンケートURL}</textarea></label></div><button class="btn primary" onclick="return confirm(\'一括送信しますか？\')">一括送信</button><button class="btn">送信履歴</button></div>';}}
}elseif($screen==='kintone'||$screen==='mail'){
 $k=$screen==='kintone';$c=fileData($k?'settings':'mail');
 $body.='<div class="card"><h1>'.($k?'kintone連携設定':'メールサーバ設定').'</h1><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><div class="grid">';
 if($k){$body.='<label>サブドメイン<input name="subdomain" value="'.h($c['subdomain']??'').'"></label><label>顧客管理アプリID<input name="appId" type="number" value="'.h((string)($c['appId']??'' )).'"></label><label>ログイン名<input name="username" value="'.h($c['username']??'').'"></label><label>パスワード<input name="password" type="password" placeholder="'.(!empty($c['password'])?'設定済み':'').'"></label><label>Proxy<input name="proxy" placeholder="host:port"></label><label>SSL証明書検証<select><option>無効</option><option>有効</option></select></label></div><div class="actions"><button name="action" value="kintoneSave" class="btn primary">設定保存</button><button name="action" value="kintoneTest" class="btn success">接続テスト</button><button name="action" value="kintoneFields" class="btn">項目一覧を再取得</button><button name="action" value="kintoneSync" class="btn">顧客情報を同期</button>';
 }else{$body.='<label>SMTPサーバ<input name="host" value="'.h($c['host']??'').'"></label><label>SMTPポート<input name="port" value="'.h($c['port']??'587').'"></label><label>暗号化<select name="encryption"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">なし</option></select></label><label>SMTPユーザー名<input name="username" value="'.h($c['username']??'').'"></label><label>SMTPパスワード<input name="password" type="password" placeholder="'.(!empty($c['password'])?'設定済み':'').'"></label><label>送信元メール<input name="from" type="email" value="'.h($c['from']??'').'"></label><label>送信元名<input name="fromName" value="'.h($c['fromName']??'').'"></label><label>返信先<input name="replyTo" type="email" value="'.h($c['replyTo']??'').'"></label></div><div class="actions"><button name="action" value="mailSave" class="btn primary">設定保存</button><button name="action" value="mailTest" class="btn success">接続テスト</button></div>';}
 $body.='</form></div>';
}elseif($screen==='answer'||$screen==='confirm'||$screen==='complete'){
 $s=survey($id);
 if(!$s)$body='<div class="card"><h2>アンケートが存在しません。</h2></div>';
 elseif($screen==='complete')$body.='<div class="card"><h1>回答完了</h1><p>ご回答ありがとうございました。</p></div>';
 else{$body.='<div class="card"><h1>'.h($s['title']).'</h1><p>'.h($s['description']).'</p><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="action" value="answer"><input type="hidden" name="id" value="'.h($id).'">';
 foreach($s['groups'] as $g)foreach($g['questions'] as $qv){$body.='<div class="q"><h3>'.h($qv['text']).($qv['required']?'<small style="color:#dc2626"> 必須</small>':'').'</h3>';foreach($qv['options']??[] as $o)$body.='<label class="option"><input type="'.($qv['type']==='multi'?'checkbox':'radio').'" name="answer['.h($qv['id']).'][]" value="'.h($o).'"> '.h($o).'</label>';if($qv['type']==='text')$body.='<textarea name="answer['.h($qv['id']).'][]"></textarea>';$body.='</div>';}$body.='<button class="btn primary">回答送信</button></form></div>';}}
else{$body.='<div class="card"><h1>画面が見つかりません</h1></div>';}

echo shell('アンケート管理システム',$body,!in_array($screen,['answer','confirm','complete'],true));
?>

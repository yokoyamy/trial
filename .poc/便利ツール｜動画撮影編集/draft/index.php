<?php
declare(strict_types=1);

/* 新規実装 / PHP 8.1+ / Apache 2.4 */
const STORE = __DIR__.'/storage';

final class Fault extends RuntimeException {
    public function __construct(public string $kind, string $message, int $status=400) {
        parent::__construct($message,$status);
    }
}
function fail(string $code,string $message,int $status=400): never {
    throw new Fault($code,$message,$status);
}
function json(mixed $value): string {
    return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function uuid(): string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function dateUTC(): string {return gmdate('Y-m-d\TH:i:s\Z');}
function validId(mixed $v): string {
    if(!is_string($v)||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i',$v))
        fail('INVALID_INPUT','UUIDが不正です。');
    return $v;
}
function validName(mixed $v): string {
    if(!is_string($v)||trim($v)===''||strlen($v)>600)fail('INVALID_INPUT','名前が不正です。');
    return trim($v);
}
function num(mixed $v): float {
    if((!is_int($v)&&!is_float($v))||!is_finite((float)$v))fail('INVALID_INPUT','数値が不正です。');
    return (float)$v;
}
function readJSON(string $name): array {
    $s=file_get_contents(STORE.'/'.$name);
    if($s===false)fail('STORAGE_ERROR','保存データを読めません。',500);
    $v=json_decode($s,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($v))fail('STORAGE_ERROR','保存データが不正です。',500);
    return $v;
}
function writeJSON(string $name,array $value): void {
    $tmp=tempnam(STORE.'/tmp','json-');
    if($tmp===false)fail('SAVE_FAILED','一時ファイルを作れません。',500);
    $fp=null;
    try{
        $s=json($value);$fp=fopen($tmp,'wb');
        if(!$fp)fail('SAVE_FAILED','保存できません。',500);
        $offset=0;
        while($offset<strlen($s)){
            $n=fwrite($fp,substr($s,$offset));
            if(!$n)fail('SAVE_FAILED','書き込みに失敗しました。',500);
            $offset+=$n;
        }
        if(!fflush($fp)||!fsync($fp))fail('SAVE_FAILED','保存を確定できません。',500);
        fclose($fp);$fp=null;
        if(!rename($tmp,STORE.'/'.$name))fail('SAVE_FAILED','保存を置換できません。',500);
    }finally{
        if(is_resource($fp))fclose($fp);
        if(is_file($tmp))@unlink($tmp);
    }
}
function locate(array $rows,string $id): int {
    foreach($rows as $i=>$row)if($row['id']===$id)return $i;
    fail('NOT_FOUND','対象がありません。',404);
}
function path(array $file): string {
    $p=$file['storagePath']??'';
    if(!is_string($p)||!preg_match('~^files/[a-f0-9-]{36}\.(mp4|webm|mov)$~i',$p))
        fail('STORAGE_ERROR','保存ファイル情報が不正です。',500);
    return STORE.'/'.$p;
}
function canonical(mixed $v): mixed {
    if(!is_array($v))return $v;
    if(!array_is_list($v))ksort($v);
    foreach($v as $k=>$x)$v[$k]=canonical($x);
    return $v;
}
function validateEdit(mixed $d): array {
    if(!is_array($d)||($d['schemaVersion']??null)!==1||
       ($d['coordinateSystem']??null)!=='source-pixels'||
       !is_array($d['elements']??null)||!array_is_list($d['elements']))
        fail('INVALID_INPUT','編集形式が不正です。');
    $sw=num($d['sourceWidth']??null);$sh=num($d['sourceHeight']??null);
    if($sw<=0||$sh<=0)fail('INVALID_INPUT','元動画サイズが不正です。');
    $map=[];
    $color=function($v){
        if(!is_string($v)||!preg_match('/^(#[a-f0-9]{6}|transparent)$/i',$v))
            fail('INVALID_INPUT','色が不正です。');
    };
    foreach($d['elements'] as $e){
        if(!is_array($e))fail('INVALID_INPUT','編集要素が不正です。');
        $id=validId($e['id']??null);$t=$e['type']??null;
        if(isset($map[$id])||!in_array($t,['A','B','C','D','E','F'],true))
            fail('INVALID_INPUT','要素種類またはIDが不正です。');
        if($t==='F'){
            if(num($e['time']??null)<0)fail('INVALID_INPUT','PDF位置が不正です。');
        }elseif($t!=='E'){
            if(num($e['start']??null)<0||num($e['end']??null)<=$e['start'])
                fail('INVALID_INPUT','表示区間が不正です。');
        }
        if(in_array($t,['A','B','C'],true)){
            foreach(['x','y','w','h'] as $k)if(num($e[$k]??null)<0)fail('INVALID_INPUT','座標が不正です。');
            if($e['w']<=0||$e['h']<=0||$e['x']+$e['w']>$sw+.01||$e['y']+$e['h']>$sh+.01||
               !in_array($e['shape']??null,['rect','ellipse','round'],true))
                fail('INVALID_INPUT','図形範囲が不正です。');
            $color($e['background']??null);
        }
        if(in_array($t,['A','B','C','E'],true)){
            $color($e['stroke']??null);
            if(num($e['width']??null)<0||!in_array($e['dash']??null,['solid','dash','dot','chain'],true))
                fail('INVALID_INPUT','線書式が不正です。');
        }
        if($t==='A'){
            $size=num($e['size']??null);
            if(!is_string($e['text']??null)||$size<10||$size>40||
               !in_array($e['font']??null,['gothic','mincho'],true))
                fail('INVALID_INPUT','コメントが不正です。');
            $color($e['color']??null);
        }
        if($t==='C'&&num($e['zoom']??null)<1)fail('INVALID_INPUT','倍率が不正です。');
        if($t==='E'){
            validId($e['ownerId']??null);validId($e['commentId']??null);
            if(!in_array($e['line']??null,['straight','orthogonal','wave'],true)||
               !in_array($e['terminal']??null,['none','arrow','circle'],true))
                fail('INVALID_INPUT','接続線が不正です。');
        }
        $map[$id]=$e;
    }
    foreach($map as $e){
        if($e['type']!=='E')continue;
        $o=$map[$e['ownerId']]??null;$a=$map[$e['commentId']]??null;
        if(!$o||!$a||!in_array($o['type'],['B','C'],true)||$a['type']!=='A')
            fail('INVALID_INPUT','接続対象が不正です。');
        foreach(['from'=>$o,'to'=>$a] as $key=>$target){
            $p=$e[$key]??null;
            if(!is_array($p))fail('INVALID_INPUT','接続位置が不正です。');
            if($target['shape']==='ellipse')num($p['angle']??null);
            elseif(!in_array($p['side']??null,['left','right','top','bottom'],true)||
                   num($p['ratio']??null)<0||$p['ratio']>1)
                fail('INVALID_INPUT','接続位置が不正です。');
        }
    }
    return $d;
}
function incoming(): array {
    $u=$_FILES['file']??null;
    if(!$u||($u['error']??-1)!==UPLOAD_ERR_OK||!is_uploaded_file($u['tmp_name']))
        fail('UPLOAD_FAILED','動画を受信できません。容量設定も確認してください。');
    if(!class_exists('finfo'))fail('SERVER_CONFIGURATION','PHP fileinfo拡張が必要です。',500);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($u['tmp_name']);
    $ext=match($mime){'video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov',default=>null};
    if(!$ext)fail('INVALID_INPUT','MP4、WebM、MOVを指定してください。');
    $id=uuid();$tmp=STORE.'/tmp/'.$id;
    if(!move_uploaded_file($u['tmp_name'],$tmp))fail('SAVE_FAILED','動画を一時保存できません。',500);
    $size=filesize($tmp);
    if(!$size){@unlink($tmp);fail('INVALID_INPUT','空の動画です。');}
    return ['tmp'=>$tmp,'file'=>['id'=>$id,'storagePath'=>"files/$id.$ext",
        'mimeType'=>$mime,'size'=>$size,'createdAt'=>dateUTC()]];
}
function recover(): void {
    if(!is_file(STORE.'/transaction.json'))return;
    $t=readJSON('transaction.json');
    if(($t['metadata']['schemaVersion']??null)!==1)
        fail('SCHEMA_UNSUPPORTED','未対応の復旧データです。',409);
    foreach(['metadata','requests','deletion-retry'] as $k)writeJSON($k.'.json',$t[$k]);
    if(!unlink(STORE.'/transaction.json'))fail('STORAGE_ERROR','復旧を確定できません。',500);
}
function collect(array $m): void {
    $refs=[];foreach($m['files'] as $f)$refs[path($f)]=true;
    $pending=[];
    foreach(readJSON('deletion-retry.json') as $f){
        $p=path($f);
        if(!isset($refs[$p])&&is_file($p)&&!@unlink($p))$pending[$f['id']]=$f;
    }
    foreach(glob(STORE.'/files/*')?:[] as $p){
        if(!is_file($p)||isset($refs[$p]))continue;
        if(!@unlink($p)&&preg_match('/^([a-f0-9-]{36})\.(mp4|webm|mov)$/i',basename($p),$r))
            $pending[$r[1]]=['id'=>$r[1],'storagePath'=>'files/'.basename($p),
                'mimeType'=>'application/octet-stream','size'=>filesize($p),'createdAt'=>dateUTC()];
    }
    foreach(glob(STORE.'/tmp/*')?:[] as $p)if(is_file($p))@unlink($p);
    writeJSON('deletion-retry.json',array_values($pending));
}
function answer(array $data,?string $requestId=null): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json(['ok'=>true,'requestId'=>$requestId,'data'=>$data]);exit;
}

$lock=null;$requestId=null;
try{
    foreach([STORE,STORE.'/files',STORE.'/tmp'] as $dir){
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))
            fail('STORAGE_ERROR','保存領域を作れません。',500);
        if(!is_readable($dir)||!is_writable($dir))fail('STORAGE_ERROR','保存領域の権限を確認してください。',500);
    }
    $deny="Require all denied\n";
    if(!is_file(STORE.'/.htaccess')&&file_put_contents(STORE.'/.htaccess',$deny)!==strlen($deny))
        fail('STORAGE_ERROR','アクセス制御を作れません。',500);
    if(trim((string)file_get_contents(STORE.'/.htaccess'))!==trim($deny))
        fail('STORAGE_ERROR','storageのアクセス制御を確認してください。',500);
    $lock=fopen(STORE.'/.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))fail('STORAGE_ERROR','排他制御に失敗しました。',500);
    if(!is_file(STORE.'/metadata.json')){
        $t=dateUTC();
        writeJSON('metadata.json',['schemaVersion'=>1,'folders'=>[
            ['id'=>uuid(),'parentFolderId'=>null,'name'=>'デフォルト','createdAt'=>$t,'updatedAt'=>$t]
        ],'videos'=>[],'edits'=>[],'files'=>[]]);
    }
    foreach(['requests','deletion-retry'] as $f)if(!is_file(STORE."/$f.json"))writeJSON("$f.json",[]);
    $m=readJSON('metadata.json');
    if(($m['schemaVersion']??null)!==1)fail('SCHEMA_UNSUPPORTED','未対応のデータ形式です。',409);
    recover();$m=readJSON('metadata.json');
    foreach(['folders','videos','edits','files'] as $key)
        if(!isset($m[$key])||!is_array($m[$key])||!array_is_list($m[$key]))
            fail('STORAGE_ERROR','管理データが不正です。',500);

    if(isset($_GET['api'])){
        if($_GET['api']!=='v1')fail('NOT_FOUND','APIがありません。',404);
        $action=(string)($_GET['action']??'');$method=$_SERVER['REQUEST_METHOD'];
        if($method==='GET'){
            if($action==='list'){
                $public=$m;foreach($public['files'] as &$f)unset($f['storagePath']);unset($f);
                answer($public);
            }
            if($action==='edit.get')answer($m['edits'][locate($m['edits'],validId($_GET['id']??null))]);
            if(!in_array($action,['file.stream','file.download'],true))fail('NOT_FOUND','操作がありません。',404);
            $f=$m['files'][locate($m['files'],validId($_GET['id']??null))];
            $p=path($f);$fp=@fopen($p,'rb');
            if(!$fp)fail('NOT_FOUND','動画がありません。',404);
            $size=filesize($p);$start=0;$end=$size-1;
            if(isset($_SERVER['HTTP_RANGE'])){
                if(!preg_match('/^bytes=(\d*)-(\d*)$/',$_SERVER['HTTP_RANGE'],$r)||
                   ($r[1]===''&&($r[2]===''||(int)$r[2]<=0))){
                    header("Content-Range: bytes */$size");fail('INVALID_RANGE','Rangeが不正です。',416);
                }
                if($r[1]==='')$start=max(0,$size-(int)$r[2]);
                else{$start=(int)$r[1];if($r[2]!=='')$end=min($end,(int)$r[2]);}
                if($start>$end||$start>=$size){
                    header("Content-Range: bytes */$size");fail('INVALID_RANGE','Rangeが範囲外です。',416);
                }
                http_response_code(206);header("Content-Range: bytes $start-$end/$size");
            }
            header('Content-Type: '.$f['mimeType']);
            header('Accept-Ranges: bytes');header('X-Content-Type-Options: nosniff');
            header('Content-Length: '.($end-$start+1));
            if($action==='file.download'){
                $name='動画';
                foreach($m['videos'] as $v)if($v['originalFileId']===$f['id'])$name=$v['name'];
                foreach($m['edits'] as $e)if($e['renderedFileId']===$f['id'])$name=$e['name'];
                $ext=pathinfo($p,PATHINFO_EXTENSION);
                header("Content-Disposition: attachment; filename=\"video.$ext\"; filename*=UTF-8''".rawurlencode($name.'.'.$ext));
            }
            flock($lock,LOCK_UN);fclose($lock);$lock=null;
            fseek($fp,$start);$remaining=$end-$start+1;
            while($remaining>0&&!connection_aborted()){
                $chunk=fread($fp,min(1048576,$remaining));
                if($chunk===false||$chunk==='')break;
                echo $chunk;$remaining-=strlen($chunk);
            }
            fclose($fp);exit;
        }
        if($method!=='POST')fail('METHOD_NOT_ALLOWED','POSTを使用してください。',405);
        try{$req=json_decode((string)($_POST['metadata']??file_get_contents('php://input')),true,512,JSON_THROW_ON_ERROR);}
        catch(JsonException){fail('INVALID_INPUT','要求JSONが不正です。');}
        $requestId=validId($req['requestId']??null);$p=$req['payload']??null;
        if(!is_array($p))fail('INVALID_INPUT','要求内容が不正です。');
        $uploadHash=null;
        if(isset($_FILES['file'])&&($_FILES['file']['error']??-1)===UPLOAD_ERR_OK&&is_uploaded_file($_FILES['file']['tmp_name']))
            $uploadHash=hash_file('sha256',$_FILES['file']['tmp_name']);
        $hash=hash('sha256',json(canonical([$action,$p,$uploadHash])));
        $requests=readJSON('requests.json');
        if(isset($requests[$requestId])){
            if($requests[$requestId]['hash']!==$hash)fail('REQUEST_CONFLICT','同じ要求IDの内容が異なります。',409);
            answer($requests[$requestId]['data'],$requestId);
        }
        collect($m);
        $id=validId($p['id']??null);$t=dateUTC();$result=['id'=>$id];$removed=[];$in=null;
        try{
            if($action==='folder.create'){
                foreach($m['folders'] as $f)if($f['id']===$id)fail('CONFLICT','IDが存在します。',409);
                if(!array_key_exists('parentFolderId',$p))fail('INVALID_INPUT','親フォルダ指定がありません。');
                $parent=$p['parentFolderId'];if($parent!==null)locate($m['folders'],validId($parent));
                $m['folders'][]=['id'=>$id,'parentFolderId'=>$parent,'name'=>validName($p['name']??null),'createdAt'=>$t,'updatedAt'=>$t];
            }elseif(in_array($action,['folder.rename','video.rename','edit.rename'],true)){
                $key=explode('.',$action)[0].'s';$i=locate($m[$key],$id);
                $m[$key][$i]['name']=validName($p['name']??null);$m[$key][$i]['updatedAt']=$t;
            }elseif($action==='video.move'){
                $i=locate($m['videos'],$id);$folder=validId($p['folderId']??null);locate($m['folders'],$folder);
                $m['videos'][$i]['folderId']=$folder;$m['videos'][$i]['updatedAt']=$t;
            }elseif($action==='video.upload'){
                foreach($m['videos'] as $v)if($v['id']===$id)fail('CONFLICT','IDが存在します。',409);
                $folder=validId($p['folderId']??null);locate($m['folders'],$folder);
                $name=validName($p['name']??null);$in=incoming();$m['files'][]=$in['file'];
                $m['videos'][]=['id'=>$id,'folderId'=>$folder,'name'=>$name,'originalFileId'=>$in['file']['id'],'createdAt'=>$t,'updatedAt'=>$t];
            }elseif($action==='edit.save'){
                $videoId=validId($p['videoId']??null);locate($m['videos'],$videoId);
                $data=validateEdit($p['editData']??null);$name=validName($p['name']??null);$i=null;
                foreach($m['edits'] as $k=>$e)if($e['id']===$id)$i=$k;
                $version=$i===null?0:$m['edits'][$i]['version'];
                if(($p['expectedVersion']??null)!==$version||($i!==null&&$m['edits'][$i]['videoId']!==$videoId))
                    fail('VERSION_CONFLICT','版が競合しました。再取得してください。',409);
                $in=incoming();$m['files'][]=$in['file'];
                $result=['id'=>$id,'videoId'=>$videoId,'name'=>$name,'version'=>$version+1,'editData'=>$data,
                    'renderedFileId'=>$in['file']['id'],'createdAt'=>$i===null?$t:$m['edits'][$i]['createdAt'],'updatedAt'=>$t];
                if($i===null)$m['edits'][]=$result;
                else{$removed[]=$m['edits'][$i]['renderedFileId'];$m['edits'][$i]=$result;}
            }elseif(in_array($action,['folder.delete','video.delete','edit.delete'],true)){
                $folders=[];$videos=[];$edits=[];
                if($action==='folder.delete'){
                    locate($m['folders'],$id);$folders=[$id];
                    do{$n=count($folders);foreach($m['folders'] as $f)
                        if(in_array($f['parentFolderId'],$folders,true)&&!in_array($f['id'],$folders,true))$folders[]=$f['id'];
                    }while(count($folders)!==$n);
                    foreach($m['videos'] as $v)if(in_array($v['folderId'],$folders,true))$videos[]=$v['id'];
                }elseif($action==='video.delete'){locate($m['videos'],$id);$videos=[$id];}
                else{locate($m['edits'],$id);$edits=[$id];}
                foreach($m['videos'] as $v)if(in_array($v['id'],$videos,true))$removed[]=$v['originalFileId'];
                foreach($m['edits'] as $e)if(in_array($e['videoId'],$videos,true)||in_array($e['id'],$edits,true)){
                    $edits[]=$e['id'];$removed[]=$e['renderedFileId'];
                }
                foreach(['folders'=>$folders,'videos'=>$videos,'edits'=>$edits] as $key=>$ids)
                    $m[$key]=array_values(array_filter($m[$key],fn($row)=>!in_array($row['id'],$ids,true)));
            }else fail('NOT_FOUND','操作がありません。',404);
            $deletions=readJSON('deletion-retry.json');
            foreach($m['files'] as $f)if(in_array($f['id'],$removed,true))$deletions[]=$f;
            $m['files']=array_values(array_filter($m['files'],fn($f)=>!in_array($f['id'],$removed,true)));
            if($in&&!rename($in['tmp'],path($in['file'])))fail('SAVE_FAILED','動画を確定できません。',500);
            $requests[$requestId]=['hash'=>$hash,'data'=>$result];
            writeJSON('transaction.json',['metadata'=>$m,'requests'=>$requests,'deletion-retry'=>$deletions]);
            recover();
        }catch(Throwable $error){
            if($in&&!is_file(STORE.'/transaction.json')){
                if(is_file($in['tmp']))@unlink($in['tmp']);
                if(is_file(path($in['file'])))@unlink(path($in['file']));
            }
            throw $error;
        }
        answer($result,$requestId);
    }
    collect($m);flock($lock,LOCK_UN);fclose($lock);$lock=null;
}catch(Throwable $e){
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
    error_log((string)$e);
    http_response_code($e instanceof Fault?$e->getCode():500);
    $message=$e instanceof Fault?$e->getMessage():'サーバ処理に失敗しました。PHPログを確認してください。';
    if(isset($_GET['api'])){
        header('Content-Type: application/json; charset=utf-8');
        echo json(['ok'=>false,'requestId'=>$requestId,'error'=>['code'=>$e instanceof Fault?$e->kind:'SERVER_ERROR','message'=>$message]]);
    }else echo '<h1>起動エラー</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';
    exit;
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="data:,">
<title>動画撮影編集</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#edf1f5;color:#172535;font:13px sans-serif}
button,input,select{font:inherit}
button{height:26px;padding:2px 8px;cursor:pointer}
button:disabled{opacity:.45;cursor:default}
header,#actions,.toolbar{display:flex;align-items:center;gap:6px;flex-wrap:wrap;background:white;padding:6px}
#actions{justify-content:center}
#stage{height:52vh;overflow:auto;display:grid;place-items:center;background:#263442}
#empty{color:white}
#surface{position:relative}
#video{display:block;width:100%;height:100%}
#overlay{position:absolute;inset:0;width:100%;height:100%;touch-action:none}
#status{width:100%;min-height:18px;text-align:center;white-space:pre-wrap}
#split{height:7px;background:#aab7c4;cursor:ns-resize;touch-action:none}
#timeline{height:30vh;padding:6px;overflow-y:auto;overflow-x:hidden}
#ruler{height:27px;position:relative;border-bottom:1px solid #8d9baa;overflow:hidden}
.tick{position:absolute;bottom:0;height:8px;border-left:1px solid #8d9baa}
.tick span{position:absolute;bottom:9px;white-space:nowrap;font-size:11px;transform:translateX(-50%)}
#scroll{overflow:hidden}
#tracks{position:relative;min-height:60px}
.track{position:relative;height:28px;background:#dbe4ed;margin:3px 0}
.block{position:absolute;height:25px;background:#356589;color:white;padding:4px 9px;white-space:nowrap;overflow:hidden;touch-action:none;cursor:grab}
.block.selected{outline:2px solid #ffbc28;outline-offset:-2px}
.edge{position:absolute;top:0;bottom:0;width:7px;background:#ffffff40;cursor:ew-resize}
.edge.left{left:0}.edge.right{right:0}
#playhead{position:absolute;top:0;bottom:0;width:2px;background:#df332a;z-index:5}
#handle{width:14px;height:14px;background:#df332a;transform:translateX(-6px);cursor:ew-resize;touch-action:none}
#veil{position:fixed;inset:0;background:#0007;z-index:10;display:grid;place-items:center}
#library{width:min(960px,96vw);max-height:90vh;overflow:auto;background:white;padding:8px}
.row{display:flex;gap:6px;align-items:center;min-height:30px;border-bottom:1px solid #ddd;padding:3px}
.row input{flex:1;min-width:60px;width:100px;border:0;background:#f3f6f8}
.row small{font-size:10px}
.row.active{background:#deedff}.row.drop{outline:2px solid #207bd1}
#menu,#pdfDialog{position:fixed;background:white;padding:8px;box-shadow:0 2px 15px #0006;z-index:20;max-height:85vh;overflow:auto}
#menu{width:285px}
#menu>button{display:block;width:100%;text-align:left;margin:3px 0}
#menu label{display:flex;justify-content:space-between;align-items:center;gap:5px;margin:7px 0}
#menu input,#menu select{width:130px}
.palette{display:flex;gap:3px;flex-wrap:wrap;width:145px}
.palette button{width:20px;height:20px;padding:0;border:1px solid #777}
#pdfDialog{left:50%;top:30%;transform:translateX(-50%)}
.textEditor{position:absolute;z-index:7;white-space:pre-wrap;overflow:auto;outline:1px dashed #2789e0;background:#0005}
[hidden]{display:none!important}
#printArea{display:none}
@media print{
@page{size:A4 portrait;margin:10mm}
body>*{display:none!important}
#printArea{display:block!important}
.printPage{height:274mm;display:flex;flex-direction:column;align-items:center;justify-content:space-around;break-after:page}
.printPage:last-child{break-after:auto}
.printPage img{display:block;max-width:190mm;max-height:130mm;object-fit:contain}
.printPage.single img{max-height:270mm}
}
</style>
</head>
<body>
<header>
<button id="list">動画一覧</button>
<button id="upload">動画をアップする</button>
<span id="zoomLabel">表示倍率</span>
<input id="viewZoom" type="range" min="10" max="200" value="60" aria-label="表示倍率">
</header>
<main id="stage">
<p id="empty">録画開始、または動画をアップしてください。</p>
<div id="surface" hidden>
<video id="video" playsinline preload="auto"></video>
<canvas id="overlay"></canvas>
</div>
</main>
<div id="actions">
<button id="primary">録画開始</button>
<button id="begin" disabled>編集開始</button>
<button id="save" disabled>編集結果保存</button>
<button id="download" disabled>ダウンロード</button>
<button id="pdf" disabled>PDF出力</button>
<button id="finish" disabled>編集作業終了</button>
<div id="status" role="status" aria-live="polite"></div>
</div>
<div id="split"></div>
<section id="timeline">
<div class="toolbar">
<span id="clock">0:00 / 0:00</span>
<input id="scale" type="range" min="1" max="100" value="100" aria-label="表示時間範囲">
</div>
<input id="seek" type="range" min="0" max="0" step=".01" value="0" aria-label="再生位置" style="width:100%">
<div id="ruler"></div>
<div id="scroll"><div id="tracks"><div id="playhead"><div id="handle"></div></div></div></div>
</section>
<div id="veil" hidden>
<section id="library">
<div class="toolbar">
<button id="new">新たに画面を録画する</button>
<button id="upload2">動画をアップする</button>
<button id="folder">フォルダ追加</button>
<button id="closeList">動画一覧を閉じる</button>
</div>
<div id="tree"></div>
</section>
</div>
<input id="file" type="file" accept="video/mp4,video/webm,video/quicktime" hidden>
<div id="menu" hidden></div>
<div id="pdfDialog" hidden>
<p>1ページの画面数</p>
<select id="pageCount"><option value="1">1画面</option><option value="2">2画面</option></select>
<button id="print">印刷へ</button><button id="cancelPDF">取消</button>
</div>
<div id="printArea"></div>
<script>
'use strict';
const $=id=>document.getElementById(id);
const video=$('video'),canvas=$('overlay'),ctx=canvas.getContext('2d');
const S={
 library:null,folderId:null,video:null,edit:null,data:null,editing:false,busy:false,
 blocked:false,dirty:false,recording:null,selected:new Set(),gesture:null,
 duration:0,range:{start:0,span:1},connecting:null,exporting:false,editor:null,pending:null
};
const names={A:'コメント',B:'強調枠',C:'拡大枠',D:'スキップ',E:'接続線',F:'PDF'};
const palette=['transparent','#FFFFFF','#000000','#FF0000','#FFFF00','#00FF00',
 '#00FFFF','#0000FF','#FF00FF','#808080','#FF8800','#804000'];
const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
const elements=()=>S.data?.elements||[];
const element=id=>elements().find(e=>e.id===id);
const active=(e,t)=>e.start<=t&&t<e.end;
const endpointOwner=e=>element(e.ownerId);
function status(s){$('status').textContent=s}
function url(action,id){return 'index.php?api=v1&action='+encodeURIComponent(action)+(id?'&id='+encodeURIComponent(id):'')}
function buttons(){
 const disabled=S.busy||S.blocked||!!S.pending,recording=!!S.recording,loaded=!!S.video;
 $('primary').disabled=disabled&&!recording;
 $('primary').textContent=recording?'録画停止':S.editing||loaded?
    (video.paused?'再生':'再生停止'):'録画開始';
 $('begin').disabled=disabled||recording||!loaded||S.editing;
 $('save').disabled=disabled||!S.editing||recording;
 $('finish').disabled=disabled||!S.editing;
 $('download').disabled=disabled||!loaded||S.dirty;
 $('pdf').disabled=disabled||!loaded||recording;
 for(const id of ['list','upload','upload2','new','folder'])$(id).disabled=disabled||recording;
}
async function job(fn){
 if(S.busy||S.blocked)return;
 S.busy=true;buttons();
 try{await fn()}catch(e){status(e.message);console.error(e)}
 finally{S.busy=false;buttons()}
}
async function send(req){
 let body;
 if(req.file){
  body=new FormData();body.append('metadata',JSON.stringify(req.envelope));
  body.append('file',req.file,'video');
 }else body=JSON.stringify(req.envelope);
 const res=await fetch(url(req.action),{method:'POST',body,
  headers:req.file?{}:{'Content-Type':'application/json'}});
 let result;
 try{result=await res.json()}catch{throw Error('サーバ応答を確認できません。')}
 if(!res.ok||!result.ok){
  const e=Error(result.error?.message||'保存に失敗しました。');e.definitive=true;throw e;
 }
 return result.data;
}
async function update(action,payload,file,done){
 if(S.pending)throw Error('未確認の保存要求があります。');
 const req={action,envelope:{requestId:crypto.randomUUID(),payload},file,done};
 S.pending=req;
 try{
  const data=await send(req);S.pending=null;await done?.(data);return data;
 }catch(e){
  if(e.definitive)S.pending=null;
  else showRetry();
  throw e;
 }finally{buttons()}
}
function showRetry(){
 const m=$('menu');m.replaceChildren();m.hidden=false;
 m.style.left='10px';m.style.top='10px';
 const p=document.createElement('p');p.textContent='保存結果未確認。同じ要求で再確認できます。';m.append(p);
 const b=document.createElement('button');b.textContent='同じ要求を再送';
 b.onclick=()=>job(async()=>{
  const req=S.pending;if(!req)return;
  try{const d=await send(req);S.pending=null;m.hidden=true;await req.done?.(d);status('保存を確認しました。')}
  catch(e){if(e.definitive){S.pending=null;m.hidden=true}throw e}
 });
 m.append(b);
}
async function get(action,id){
 const r=await fetch(url(action,id),{cache:'no-store'});
 const v=await r.json();
 if(!r.ok||!v.ok)throw Error(v.error?.message||'取得に失敗しました。');
 return v.data;
}
async function refresh(){
 S.library=await get('list');
 if(!S.library.folders.some(f=>f.id===S.folderId))S.folderId=S.library.folders[0]?.id||null;
 library();
}
function discard(){return !S.dirty||confirm('未保存の編集を破棄しますか？')}
function commitText(){
 if(!S.editor)return;
 const {box,e}=S.editor;e.text=box.innerText;S.editor=null;box.remove();changed();
}
function clear(){
 commitText();video.pause();video.srcObject=null;video.removeAttribute('src');video.load();
 Object.assign(S,{video:null,edit:null,data:null,editing:false,dirty:false,duration:0,connecting:null});
 S.selected.clear();$('surface').hidden=true;$('empty').hidden=false;tracks();buttons();
}
function mediaWait(test,events,action,timeout=30000){
 return new Promise((resolve,reject)=>{
  let timer;
  const clean=()=>{clearTimeout(timer);events.forEach(x=>video.removeEventListener(x,check));video.removeEventListener('error',error)};
  const check=()=>{if(test()){clean();resolve()}};
  const error=()=>{clean();reject(Error('動画を読み込めません。ブラウザの対応形式を確認してください。'))};
  events.forEach(x=>video.addEventListener(x,check));video.addEventListener('error',error);
  timer=setTimeout(()=>{clean();reject(Error('動画処理がタイムアウトしました。'))},timeout);
  try{action?.();check()}catch(e){clean();reject(e)}
 });
}
async function decodedFrame(){
 await mediaWait(()=>video.readyState>=2&&!video.seeking,['loadeddata','canplay','seeked']);
 if(video.requestVideoFrameCallback){
  await new Promise(resolve=>{
   let done=false;
   const finish=()=>{if(!done){done=true;resolve()}};
   const id=video.requestVideoFrameCallback(finish);
   setTimeout(()=>{video.cancelVideoFrameCallback(id);finish()},150);
  });
 }
}
async function seek(t){
 t=clamp(t,0,S.duration);
 if(Math.abs(video.currentTime-t)>.0001||video.seeking)
  await mediaWait(()=>!video.seeking&&Math.abs(video.currentTime-t)<.05,['seeked','timeupdate'],()=>{video.currentTime=t});
 await decodedFrame();
}
async function load(id){
 video.pause();video.srcObject=null;video.muted=false;
 await mediaWait(()=>video.readyState>=1,['loadedmetadata'],()=>{
  video.src=url('file.stream',id);video.load();
 });
 if(!Number.isFinite(video.duration)||video.duration<=0)
  await mediaWait(()=>Number.isFinite(video.duration)&&video.duration>0,
    ['durationchange','seeked','timeupdate'],()=>{video.currentTime=1e10});
 S.duration=video.duration;await seek(0);
 $('surface').hidden=false;$('empty').hidden=true;resize();tracks();
}
async function select(type,item){
 if(!discard())return;commitText();
 const edit=type==='edit'?await get('edit.get',item.id):null;
 S.video=type==='video'?item:S.library.videos.find(v=>v.id===edit.videoId);
 S.edit=edit;S.data=edit?structuredClone(edit.editData):null;
 S.editing=false;S.dirty=false;S.selected.clear();S.connecting=null;
 await load(edit?edit.renderedFileId:S.video.originalFileId);
 $('veil').hidden=true;status('再生、編集開始、ダウンロードを選べます。');buttons();
}
function library(){
 const root=$('tree');root.replaceChildren();
 function row(type,item,depth){
  const r=document.createElement('div');r.className='row';r.style.marginLeft=depth*16+'px';
  r.dataset.entityType=type;r.dataset.entityId=item.id;
  if(type==='folder'&&item.id===S.folderId)r.classList.add('active');
  const open=document.createElement('button');open.textContent=type==='folder'?'フォルダ':type==='video'?'元動画':'編集';
  open.onclick=()=>job(async()=>{
   if(type==='folder'){S.folderId=item.id;library()}else await select(type,item);
  });
  const input=document.createElement('input');input.value=item.name;input.setAttribute('aria-label','名前');
  input.onchange=()=>job(()=>update(type+'.rename',{id:item.id,name:input.value},null,refresh));
  const date=document.createElement('small');date.textContent=new Date(item.updatedAt).toLocaleString();
  r.append(open,input,date);
  if(type==='video'){
   r.draggable=true;
   r.ondragstart=e=>e.dataTransfer.setData('text/plain',item.id);
  }
  if(type==='folder'){
   r.ondragover=e=>{e.preventDefault();r.classList.add('drop')};
   r.ondragleave=()=>r.classList.remove('drop');
   r.ondrop=e=>{
    e.preventDefault();r.classList.remove('drop');
    const id=e.dataTransfer.getData('text/plain');
    if(S.library.videos.some(v=>v.id===id))job(()=>update('video.move',{id,folderId:item.id},null,refresh));
   };
  }
  r.oncontextmenu=e=>{
   e.preventDefault();
   popup(e,[
    ...(type==='folder'?[['配下にフォルダ追加',()=>job(()=>addFolder(item.id))]]:[]),
    ['削除',()=>job(async()=>{
     if(!confirm('この項目と配下の関連データを削除しますか？'))return;
     if(!discard())return;
     await update(type+'.delete',{id:item.id},null,async()=>{
      await refresh();
      if(S.video&&!S.library.videos.some(v=>v.id===S.video.id))clear();
      else if(S.edit&&!S.library.edits.some(v=>v.id===S.edit.id))clear();
     });
    })]
   ]);
  };
  root.append(r);
 }
 function folder(parent,depth,visited){
  for(const f of S.library.folders.filter(x=>x.parentFolderId===parent)){
   if(visited.has(f.id))continue;visited.add(f.id);row('folder',f,depth);
   for(const v of S.library.videos.filter(x=>x.folderId===f.id)){
    row('video',v,depth+1);
    for(const e of S.library.edits.filter(x=>x.videoId===v.id))row('edit',e,depth+2);
   }
   folder(f.id,depth+1,visited);
  }
 }
 folder(null,0,new Set());
}
async function addFolder(parent){
 await update('folder.create',{id:crypto.randomUUID(),parentFolderId:parent,name:'新しいフォルダ'},null,async d=>{
  S.folderId=d.id;await refresh();
 });
}
function resize(){
 if(!video.videoWidth)return;
 canvas.width=video.videoWidth;canvas.height=video.videoHeight;
 const f=+$('viewZoom').value/100;
 $('surface').style.width=canvas.width*f+'px';$('surface').style.height=canvas.height*f+'px';
 draw();
}
function shape(c,e){
 c.beginPath();
 if(e.shape==='ellipse')c.ellipse(e.x+e.w/2,e.y+e.h/2,e.w/2,e.h/2,0,0,Math.PI*2);
 else if(e.shape==='round')c.roundRect(e.x,e.y,e.w,e.h,Math.min(14,e.w/2,e.h/2));
 else c.rect(e.x,e.y,e.w,e.h);
}
function lineStyle(c,e){
 c.strokeStyle=e.stroke;c.lineWidth=e.width;
 c.setLineDash(({solid:[],dash:[10,6],dot:[2,5],chain:[12,4,2,4]})[e.dash]);
}
function boundary(e,p){
 if(e.shape==='ellipse'){
  const a=p.angle;return {x:e.x+e.w/2+Math.cos(a)*e.w/2,y:e.y+e.h/2+Math.sin(a)*e.h/2,
   nx:Math.cos(a)/e.w,ny:Math.sin(a)/e.h};
 }
 const r=p.ratio;
 return p.side==='left'?{x:e.x,y:e.y+e.h*r,nx:-1,ny:0}:
  p.side==='right'?{x:e.x+e.w,y:e.y+e.h*r,nx:1,ny:0}:
  p.side==='top'?{x:e.x+e.w*r,y:e.y,nx:0,ny:-1}:
  {x:e.x+e.w*r,y:e.y+e.h,nx:0,ny:1};
}
function attachment(e,p){
 if(e.shape==='ellipse')return {angle:Math.atan2((p.y-e.y-e.h/2)/(e.h/2),(p.x-e.x-e.w/2)/(e.w/2))};
 const choices=[
  ['left',Math.abs(p.x-e.x)],['right',Math.abs(p.x-e.x-e.w)],
  ['top',Math.abs(p.y-e.y)],['bottom',Math.abs(p.y-e.y-e.h)]
 ].sort((a,b)=>a[1]-b[1]);
 const side=choices[0][0];
 return {side,ratio:clamp(side==='left'||side==='right'?(p.y-e.y)/e.h:(p.x-e.x)/e.w,0,1)};
}
function connector(c,e,t,editing=false){
 const o=endpointOwner(e),a=element(e.commentId);if(!o||!a||!active(o,t))return;
 const p=boundary(o,e.from),q=boundary(a,e.to),dx=q.x-p.x,dy=q.y-p.y,len=Math.hypot(dx,dy);
 c.save();lineStyle(c,e);c.beginPath();c.moveTo(p.x,p.y);
 let tx=dx,ty=dy;
 if(e.line==='straight')c.lineTo(q.x,q.y);
 else if(e.line==='orthogonal'){
  const normal=v=>{const n=Math.hypot(v.nx,v.ny);return {x:v.nx/n,y:v.ny/n}};
  const u=normal(p),v=normal(q),d=Math.min(20,len/4);
  const p1={x:p.x+u.x*d,y:p.y+u.y*d},q1={x:q.x+v.x*d,y:q.y+v.y*d};
  c.lineTo(p1.x,p1.y);c.lineTo(q1.x,p1.y);c.lineTo(q1.x,q1.y);c.lineTo(q.x,q.y);
  tx=q.x-q1.x;ty=q.y-q1.y;
 }else{
  const nx=len?-dy/len:0,ny=len?dx/len:0,amp=Math.min(15,len/8);
  for(let i=1;i<=64;i++){const r=i/64,w=Math.sin(r*Math.PI*2)*amp;c.lineTo(p.x+dx*r+nx*w,p.y+dy*r+ny*w)}
  tx=dx+nx*amp*Math.PI*2;ty=dy+ny*amp*Math.PI*2;
 }
 if(e.stroke!=='transparent')c.stroke();
 c.setLineDash([]);c.fillStyle=e.stroke;
 if(e.terminal==='circle'){c.beginPath();c.arc(q.x,q.y,5,0,Math.PI*2);c.fill()}
 if(e.terminal==='arrow'){
  const angle=Math.atan2(ty,tx);c.beginPath();c.moveTo(q.x,q.y);
  c.lineTo(q.x-12*Math.cos(angle-.45),q.y-12*Math.sin(angle-.45));
  c.lineTo(q.x-12*Math.cos(angle+.45),q.y-12*Math.sin(angle+.45));c.closePath();c.fill();
 }
 if(editing){
  for(const [v,color] of [[p,'#FF8800'],[q,'#00AAFF']]){
   c.fillStyle=color;c.beginPath();c.arc(v.x,v.y,6,0,Math.PI*2);c.fill();
  }
 }
 c.restore();
}
function paint(c,t){
 const visible=elements().filter(e=>['A','B','C'].includes(e.type)&&active(e,t));
 for(const type of ['C','B','A'])for(const e of visible.filter(x=>x.type===type)){
  c.save();shape(c,e);
  if(e.background!=='transparent'){c.fillStyle=e.background;c.fill()}
  if(type==='C'){
   c.save();c.clip();
   const sw=e.w/e.zoom,sh=e.h/e.zoom;
   c.drawImage(video,e.x+(e.w-sw)/2,e.y+(e.h-sh)/2,sw,sh,e.x,e.y,e.w,e.h);
   c.restore();
  }
  lineStyle(c,e);if(e.stroke!=='transparent')c.stroke();
  if(type==='A'){
   c.clip();c.setLineDash([]);c.fillStyle=e.color;
   c.font=e.size+'px '+(e.font==='mincho'?'serif':'sans-serif');c.textBaseline='top';
   let y=e.y+5;
   for(const paragraph of e.text.split('\n')){
    let line='';
    for(const char of paragraph){
     if(line&&c.measureText(line+char).width>e.w-10){c.fillText(line,e.x+5,y);y+=e.size*1.2;line=''}
     line+=char;
    }
    c.fillText(line,e.x+5,y);y+=e.size*1.2;
   }
  }
  c.restore();
 }
 for(const e of elements().filter(x=>x.type==='E'))connector(c,e,t);
}
function composite(c,t){
 c.clearRect(0,0,c.canvas.width,c.canvas.height);
 if(video.readyState>=2){c.drawImage(video,0,0,c.canvas.width,c.canvas.height);paint(c,t)}
}
function draw(){
 ctx.clearRect(0,0,canvas.width,canvas.height);
 if(!S.editing||video.readyState<2)return;
 paint(ctx,video.currentTime);
 if(S.exporting)return;
 for(const id of S.selected){
  const e=element(id);if(!e)continue;
  if(e.type==='E'){connector(ctx,e,video.currentTime,true);continue}
  if(!['A','B','C'].includes(e.type)||!active(e,video.currentTime))continue;
  ctx.save();ctx.strokeStyle='#2789E0';ctx.setLineDash([4,4]);ctx.strokeRect(e.x,e.y,e.w,e.h);
  ctx.fillStyle='#2789E0';ctx.fillRect(e.x+e.w-5,e.y+e.h-5,10,10);ctx.restore();
 }
}
function format(t){
 const m=Math.floor(t/60),s=t-m*60;
 return m+':'+s.toFixed(s%1?1:0).padStart(s%1?4:2,'0');
}
function range(){
 const span=Math.max(.01,S.duration*+$('scale').value/100);
 return {start:clamp(video.currentTime-span/2,0,Math.max(0,S.duration-span)),span};
}
function ruler(){
 $('ruler').replaceChildren();
 const {start,span}=S.range,width=$('ruler').clientWidth;
 const rough=span/Math.max(2,Math.floor(width/85)),power=10**Math.floor(Math.log10(Math.max(.01,rough)));
 const step=[1,2,5,10].map(x=>x*power).find(x=>x>=rough)||power*10;
 for(let t=Math.ceil(start/step)*step;t<=start+span+.0001;t+=step){
  const tick=document.createElement('div');tick.className='tick';tick.style.left=(t-start)/span*100+'%';
  const text=document.createElement('span');text.textContent=format(t);tick.append(text);$('ruler').append(tick);
 }
}
function clock(){
 const t=S.recording?0:video.currentTime||0;
 $('clock').textContent=format(t)+' / '+format(S.duration);
 $('seek').max=S.duration;$('seek').value=clamp(t,0,S.duration);
 $('playhead').style.left=clamp((t-S.range.start)/S.range.span*100,0,100)+'%';
}
function positionBlock(block,e){
 const a=e.type==='F'?e.time:e.start,b=e.type==='F'?a:e.end;
 block.style.left=(a-S.range.start)/S.range.span*100+'%';
 block.style.width=e.type==='F'?'8px':Math.max(7,(b-a)/S.range.span*$('tracks').clientWidth)+'px';
 block.classList.toggle('selected',S.selected.has(e.id));
}
function tracks(){
 if(S.gesture)return;
 S.range=range();ruler();
 $('tracks').querySelectorAll('.track').forEach(x=>x.remove());
 const rows=[];
 for(const e of [...elements()].filter(e=>e.type!=='E').sort((a,b)=>(a.time??a.start)-(b.time??b.start))){
  const a=e.time??e.start,b=e.type==='F'?a+.001:e.end;
  let r=rows.find(x=>x.end<=a);
  if(!r){const div=document.createElement('div');div.className='track';$('tracks').append(div);r={div,end:0};rows.push(r)}
  r.end=b;
  const block=document.createElement('div');block.className='block';block.dataset.elementId=e.id;
  const text=document.createElement('span');text.textContent=names[e.type];block.append(text);
  if(e.type!=='F')for(const side of ['left','right']){
   const edge=document.createElement('span');edge.className='edge '+side;edge.dataset.edge=side;block.append(edge);
  }
  positionBlock(block,e);
  block.onpointerdown=event=>timeDrag(event,e,block);
  block.oncontextmenu=event=>{event.preventDefault();if(S.editing&&!S.busy)settings(event,e)};
  r.div.append(block);
 }
 clock();
}
function changed(){S.dirty=true;tracks();draw();buttons()}
function choose(e,event){
 if(event.ctrlKey||event.metaKey){if(S.selected.has(e.id))S.selected.delete(e.id);else S.selected.add(e.id)}
 else if(!S.selected.has(e.id)){S.selected.clear();S.selected.add(e.id)}
}
function timeDrag(event,e,block){
 if(event.button!==0)return;choose(e,event);
 $('tracks').querySelectorAll('.block').forEach(b=>b.classList.toggle('selected',S.selected.has(b.dataset.elementId)));
 draw();if(!S.editing||S.busy)return;
 video.pause();
 const edge=event.target.dataset.edge,items=edge?[e]:elements().filter(x=>S.selected.has(x.id)&&x.type!=='E');
 if(!items.length)return;
 const snapshots=items.map(x=>({e:x,a:x.time??x.start,b:x.end}));
 const x=event.clientX,width=$('tracks').clientWidth,span=S.range.span;
 let moved=false;S.gesture={type:'time'};
 block.setPointerCapture(event.pointerId);
 block.onpointermove=ev=>{
  let delta=(ev.clientX-x)/width*span;
  if(Math.abs(ev.clientX-x)>1)moved=true;
  if(edge){
   const a=snapshots[0].a,b=snapshots[0].b,min=Math.min(.01,(b-a)/2);
   if(edge==='left')e.start=clamp(a+delta,0,b-min);
   else e.end=clamp(b+delta,a+min,S.duration);
  }else{
   const lo=Math.min(...snapshots.map(v=>v.a));
   const hi=Math.max(...snapshots.map(v=>v.e.type==='F'?v.a+.0001:v.b));
   delta=clamp(delta,-lo,S.duration-hi);
   for(const v of snapshots){
    if(v.e.type==='F')v.e.time=v.a+delta;
    else{v.e.start=v.a+delta;v.e.end=v.b+delta}
   }
  }
  for(const v of snapshots){
   const b=$('tracks').querySelector('[data-element-id="'+v.e.id+'"]');if(b)positionBlock(b,v.e);
  }
  draw();
 };
 const end=()=>{
  block.onpointermove=null;block.onpointerup=null;block.onpointercancel=null;S.gesture=null;
  if(moved)changed();else tracks();
 };
 block.onpointerup=end;block.onpointercancel=end;
}
function popup(event,items){
 if(S.pending)return;
 commitText();
 const m=$('menu');m.replaceChildren();
 for(const [text,fn] of items){
  const b=document.createElement('button');b.textContent=text;b.onclick=()=>{m.hidden=true;fn()};m.append(b);
 }
 m.style.left=clamp(event.clientX,0,innerWidth-295)+'px';
 m.style.top=clamp(event.clientY,0,Math.max(0,innerHeight-400))+'px';m.hidden=false;
}
function deleteElement(e){
 const remove=new Set(S.selected.has(e.id)?S.selected:[e.id]);
 S.data.elements=elements().filter(x=>!remove.has(x.id)&&
  !(x.type==='E'&&(remove.has(x.ownerId)||remove.has(x.commentId))));
 S.selected.clear();changed();
}
function settings(event,e){
 choose(e,event);
 popup(event,[
  ...(e.type==='A'?[['本文編集',()=>textEdit(e)]]:[]),
  ...(['B','C'].includes(e.type)?[['接続線追加',()=>{S.connecting={owner:e.id};status('接続するコメントをクリックしてください。')}]]:[]),
  ...(S.selected.size>1?[['選択要素の開始位置を合わせる',()=>{
   const list=elements().filter(x=>S.selected.has(x.id)&&x.type!=='E');
   const target=e.time??e.start;
   if(list.some(x=>x.type!=='F'&&target+x.end-x.start>S.duration)){
    status('動画末尾を超えるため整列できません。');return;
   }
   for(const x of list){if(x.type==='F')x.time=target;else{const length=x.end-x.start;x.start=target;x.end=target+length}}
   changed();
  }]]:[]),
  ['削除',()=>deleteElement(e)]
 ]);
 const m=$('menu');
 function field(label,node){
  const l=document.createElement('label');l.append(document.createTextNode(label),node);m.append(l);
 }
 function numeric(key,label,min,max,step=.1){
  const input=document.createElement('input');input.type='number';input.min=min;input.max=max;input.step=step;input.value=e[key];
  input.onchange=()=>{
   const n=+input.value;
   if(!Number.isFinite(n)||n<min||n>max){input.value=e[key];return}
   e[key]=n;changed();
  };field(label,input);
 }
 if(e.type==='F')numeric('time','PDF位置（秒）',0,Math.max(0,S.duration-.0001));
 else if(e.type!=='E'){
  numeric('start','開始（秒）',0,e.end-.0001);
  numeric('end','終了（秒）',e.start+.0001,S.duration);
  m.querySelectorAll('input').forEach(input=>{
   const original=input.onchange;
   input.onchange=()=>{
    const key=input===m.querySelector('input')?'start':'end',n=+input.value;
    if(key==='start'&&n>=e.end||key==='end'&&n<=e.start){input.value=e[key];return}
    original();
   };
  });
 }
 if(!['A','B','C','E'].includes(e.type))return;
 function selectField(key,label,values){
  const select=document.createElement('select');
  for(const [value,text] of values)select.add(new Option(text,String(value),false,String(e[key])===String(value)));
  select.onchange=()=>{
   e[key]=key==='width'?+select.value:select.value;
   if(key==='shape'){
    for(const line of elements().filter(x=>x.type==='E')){
     if(line.ownerId===e.id)line.from=e.shape==='ellipse'?{angle:0}:{side:'right',ratio:.5};
     if(line.commentId===e.id)line.to=e.shape==='ellipse'?{angle:Math.PI}:{side:'left',ratio:.5};
    }
   }
   changed();
  };field(label,select);
 }
 function colorField(key,label){
  const div=document.createElement('div');div.className='palette';
  for(const color of palette){
   const b=document.createElement('button');b.title=color==='transparent'?'なし':color;
   b.style.background=color==='transparent'?'repeating-linear-gradient(45deg,#fff,#fff 4px,#ccc 4px,#ccc 8px)':color;
   if(e[key]===color)b.style.outline='2px solid #2789e0';
   b.onclick=()=>{e[key]=color;div.querySelectorAll('button').forEach(x=>x.style.outline='');b.style.outline='2px solid #2789e0';changed()};
   div.append(b);
  }field(label,div);
 }
 selectField('width','線の太さ',[[1,'━'],[2,'━━'],[4,'━━━━'],[6,'━━━━━━']]);
 selectField('dash','線種',[['solid','実線'],['dash','破線'],['dot','点線'],['chain','一点鎖線']]);
 colorField('stroke','線色');
 if(e.type==='E'){
  selectField('line','線形',[['straight','直線'],['orthogonal','折れ線'],['wave','1周期波線']]);
  selectField('terminal','終端',[['none','なし'],['arrow','矢印'],['circle','丸']]);
 }else{
  selectField('shape','図形',[['rect','四角'],['ellipse','楕円'],['round','角丸']]);
  colorField('background','背景色');
 }
 if(e.type==='A'){
  numeric('size','文字サイズ',10,40,1);
  selectField('font','フォント',[['gothic','ゴシック'],['mincho','明朝']]);colorField('color','文字色');
 }
 if(e.type==='C')numeric('zoom','拡大倍率',1,20);
}
function point(event){
 const r=canvas.getBoundingClientRect();return {x:(event.clientX-r.left)*canvas.width/r.width,y:(event.clientY-r.top)*canvas.height/r.height};
}
function hit(p){
 const t=video.currentTime;
 const order=[...elements().filter(e=>e.type==='C'),...elements().filter(e=>e.type==='B'),...elements().filter(e=>e.type==='A')];
 return order.reverse().find(e=>active(e,t)&&p.x>=e.x&&p.x<=e.x+e.w&&p.y>=e.y&&p.y<=e.y+e.h);
}
function lineHit(p){
 for(const e of elements().filter(x=>x.type==='E')){
  const o=endpointOwner(e),a=element(e.commentId);if(!o||!a||!active(o,video.currentTime))continue;
  const from=boundary(o,e.from),to=boundary(a,e.to);
  for(const [key,v] of [['from',from],['to',to]])if(Math.hypot(p.x-v.x,p.y-v.y)<12)return {e,key};
 }
 return null;
}
function add(type,p={x:20,y:20}){
 commitText();video.pause();const start=video.currentTime;
 if(start>=S.duration){status('末尾では追加できません。');return}
 const e={id:crypto.randomUUID(),type};
 if(type==='F')e.time=start;
 else{
  Object.assign(e,{start,end:Math.min(S.duration,start+3)});
  if(['A','B','C'].includes(type)){
   const x=clamp(p.x,0,canvas.width-1),y=clamp(p.y,0,canvas.height-1);
   Object.assign(e,{x,y,w:Math.min(240,canvas.width-x),h:Math.min(90,canvas.height-y),
    stroke:'#FF0000',width:2,dash:'solid',shape:'rect',background:'transparent'});
   if(type==='A')Object.assign(e,{text:'コメント',size:20,font:'gothic',color:'#FFFFFF'});
   if(type==='C')e.zoom=2;
  }
 }
 S.data.elements.push(e);S.selected=new Set([e.id]);changed();
 if(type==='A')textEdit(e);
}
function textEdit(e){
 commitText();video.pause();
 const box=document.createElement('div');box.className='textEditor';box.contentEditable='true';
 const f=$('surface').clientWidth/canvas.width;
 Object.assign(box.style,{left:e.x*f+'px',top:e.y*f+'px',width:e.w*f+'px',height:e.h*f+'px',
  fontSize:e.size*f+'px',fontFamily:e.font==='mincho'?'serif':'sans-serif',color:e.color});
 box.textContent=e.text;S.editor={box,e};$('surface').append(box);box.focus();box.onblur=commitText;
}
canvas.oncontextmenu=event=>{
 event.preventDefault();if(!S.editing||S.busy)return;
 video.pause();const p=point(event),line=lineHit(p),e=line?.e||hit(p);
 if(e)settings(event,e);
 else popup(event,[['コメント追加',()=>add('A',p)],['強調枠追加',()=>add('B',p)],['拡大枠追加',()=>add('C',p)]]);
};
canvas.ondblclick=event=>{const e=hit(point(event));if(S.editing&&!S.busy&&e?.type==='A')textEdit(e)};
canvas.onpointerdown=event=>{
 if(!S.editing||S.busy||event.button!==0)return;
 commitText();video.pause();
 const p=point(event),line=lineHit(p),e=line?.e||hit(p);
 if(S.connecting){
  const owner=element(S.connecting.owner);
  if(e?.type==='A'&&owner){
   S.data.elements.push({id:crypto.randomUUID(),type:'E',ownerId:owner.id,commentId:e.id,
    from:attachment(owner,{x:e.x+e.w/2,y:e.y+e.h/2}),
    to:attachment(e,{x:owner.x+owner.w/2,y:owner.y+owner.h/2}),
    stroke:owner.stroke,width:owner.width,dash:owner.dash,line:'orthogonal',terminal:'none'});
   S.connecting=null;changed();status('接続線を追加しました。');
  }else status('コメントをクリックしてください。');
  return;
 }
 if(!e){S.selected.clear();draw();return}
 choose(e,event);draw();
 S.gesture=line?{type:'line',...line}:{type:'shape',e,p,x:e.x,y:e.y,w:e.w,h:e.h,
  resize:Math.abs(p.x-e.x-e.w)<14&&Math.abs(p.y-e.y-e.h)<14};
 canvas.setPointerCapture(event.pointerId);
};
canvas.onpointermove=event=>{
 const g=S.gesture;if(!g||g.type==='time')return;
 const p=point(event);
 if(g.type==='line'){
  const target=element(g.key==='from'?g.e.ownerId:g.e.commentId);g.e[g.key]=attachment(target,p);
 }else{
  const dx=p.x-g.p.x,dy=p.y-g.p.y;
  if(g.resize){
   g.e.w=clamp(g.w+dx,1,canvas.width-g.e.x);g.e.h=clamp(g.h+dy,1,canvas.height-g.e.y);
  }else{
   g.e.x=clamp(g.x+dx,0,canvas.width-g.e.w);g.e.y=clamp(g.y+dy,0,canvas.height-g.e.h);
  }
 }
 g.moved=true;draw();
};
canvas.onpointerup=canvas.onpointercancel=()=>{
 const g=S.gesture;if(!g||g.type==='time')return;
 S.gesture=null;if(g.moved)changed();else tracks();
};
$('timeline').oncontextmenu=event=>{
 if(event.target.closest('.block'))return;
 event.preventDefault();if(!S.editing||S.busy)return;
 popup(event,[['スキップ追加',()=>add('D')],['PDF位置追加',()=>add('F')]]);
};
$('handle').onpointerdown=event=>{
 if(S.busy||!S.duration)return;
 event.preventDefault();video.pause();const h=$('handle'),r=$('tracks').getBoundingClientRect(),v={...S.range};
 S.gesture={type:'seek'};h.setPointerCapture(event.pointerId);
 h.onpointermove=ev=>{video.currentTime=clamp(v.start+(ev.clientX-r.left)/r.width*v.span,0,S.duration);clock();draw()};
 h.onpointerup=h.onpointercancel=()=>{h.onpointermove=null;S.gesture=null;tracks()};
};
function recordingType(){
 if(!window.MediaRecorder)throw Error('ブラウザが録画に対応していません。');
 const type=['video/mp4;codecs=avc1.42E01E,mp4a.40.2','video/mp4','video/webm;codecs=vp8,opus','video/webm']
  .find(t=>MediaRecorder.isTypeSupported(t));
 if(!type)throw Error('対応する録画形式がありません。');
 return type;
}
function recordResult(recorder){
 const chunks=[];
 return new Promise((resolve,reject)=>{
  recorder.ondataavailable=e=>{if(e.data.size)chunks.push(e.data)};
  recorder.onerror=()=>reject(Error('録画に失敗しました。'));
  recorder.onstop=()=>resolve(new Blob(chunks,{type:recorder.mimeType}));
 });
}
async function upload(blob,name,duration){
 if(!S.folderId)throw Error('先にフォルダを追加してください。');
 status('動画を保存しています…');
 const id=crypto.randomUUID();
 await update('video.upload',{id,folderId:S.folderId,name},blob,async()=>{
  await refresh();const item=S.library.videos.find(v=>v.id===id);
  await select('video',item);status('保存しました。編集開始で編集できます。');
 });
}
async function startRecording(){
 if(!discard())return;
 if(!navigator.mediaDevices?.getDisplayMedia)throw Error('HTTPSまたはlocalhostで開いてください。');
 const mime=recordingType();
 const stream=await navigator.mediaDevices.getDisplayMedia({video:true,audio:true});
 let recorder;
 try{
  clear();recorder=new MediaRecorder(stream,{mimeType:mime});
  const result=recordResult(recorder);
  S.recording=recorder;video.muted=true;video.srcObject=stream;
  $('surface').hidden=false;$('empty').hidden=true;
  await video.play();resize();recorder.start(250);
  status('録画中です。共有音声の有無は画面共有ダイアログの選択に従います。');
  result.then(blob=>{
   stream.getTracks().forEach(t=>t.stop());S.recording=null;video.srcObject=null;buttons();
   job(()=>upload(blob,'録画 '+new Date().toLocaleString()));
  }).catch(e=>{
   stream.getTracks().forEach(t=>t.stop());S.recording=null;clear();status(e.message);
  });
  stream.getVideoTracks()[0].onended=()=>{if(recorder.state!=='inactive')recorder.stop()};
 }catch(e){
  stream.getTracks().forEach(t=>t.stop());S.recording=null;clear();throw e;
 }
}
let audioContext=null,audioSource=null,audioOutput=null;
async function audioStream(){
 if(!audioContext){
  const Audio=window.AudioContext||window.webkitAudioContext;
  if(!Audio)throw Error('音声付き動画生成に対応していません。');
  audioContext=new Audio();audioSource=audioContext.createMediaElementSource(video);
  audioOutput=audioContext.createMediaStreamDestination();
  audioSource.connect(audioOutput);audioSource.connect(audioContext.destination);
 }
 await audioContext.resume();return audioOutput.stream;
}
function skipEnd(t){
 let end=t;
 for(let n=0;n<=elements().length;n++){
  const d=elements().find(e=>e.type==='D'&&end>=e.start&&end<e.end);
  if(!d)break;end=d.end;
 }
 return end;
}
async function renderMovie(){
 commitText();const old=video.currentTime;video.pause();await seek(0);
 const out=document.createElement('canvas');out.width=canvas.width;out.height=canvas.height;
 const c=out.getContext('2d');const sound=await audioStream();
 const stream=out.captureStream(30);
 for(const track of sound.getAudioTracks())stream.addTrack(track.clone());
 const recorder=new MediaRecorder(stream,{mimeType:recordingType()});
 const result=recordResult(recorder);
 S.exporting=true;let running=true;
 const tick=()=>{
  if(!running)return;composite(c,video.currentTime);requestAnimationFrame(tick);
 };
 try{
  composite(c,0);recorder.start(250);tick();
  const deadline=performance.now()+S.duration*1000+60000;
  let t=skipEnd(0);
  if(t>=S.duration)throw Error('全区間スキップの動画は保存できません。');
  await seek(t);await video.play();
  while(!video.ended){
   if(performance.now()>deadline)throw Error('動画生成がタイムアウトしました。');
   const end=skipEnd(video.currentTime);
   if(end>video.currentTime){
    video.pause();recorder.pause();
    if(end>=S.duration)break;
    await seek(end);composite(c,end);recorder.resume();await video.play();
   }
   status('編集動画を生成中… '+format(video.currentTime)+' / '+format(S.duration));
   await new Promise(r=>setTimeout(r,15));
  }
  video.pause();recorder.stop();return await result;
 }finally{
  running=false;video.pause();
  if(recorder.state!=='inactive')recorder.stop();
  result.catch(()=>{});
  stream.getTracks().forEach(t=>t.stop());S.exporting=false;
  await seek(Math.min(old,S.duration));draw();
 }
}
$('primary').onclick=()=>{
 if(S.recording){if(S.recording.state!=='inactive')S.recording.stop();return}
 job(async()=>{
  if(S.video){if(video.paused)await video.play();else video.pause()}
  else await startRecording();
 });
};
$('begin').onclick=()=>job(async()=>{
 commitText();
 if(S.edit)S.edit=await get('edit.get',S.edit.id);
 await load(S.video.originalFileId);
 S.data=S.edit?structuredClone(S.edit.editData):{schemaVersion:1,coordinateSystem:'source-pixels',
  sourceWidth:video.videoWidth,sourceHeight:video.videoHeight,elements:[]};
 S.editing=true;S.selected.clear();tracks();draw();
 status('動画内の右クリックで追加・書式設定。タイムラインではドラッグで時間調整できます。');
});
$('save').onclick=()=>job(async()=>{
 commitText();
 for(const e of elements()){
  if(e.type==='F'&&(e.time<0||e.time>=S.duration)||e.type!=='E'&&e.type!=='F'&&
   (e.start<0||e.end>S.duration||e.end<=e.start))throw Error('動画範囲外の編集要素があります。');
 }
 const blob=await renderMovie();
 const payload={id:S.edit?.id||crypto.randomUUID(),videoId:S.video.id,
  name:S.edit?.name||'編集 '+new Date().toLocaleString(),expectedVersion:S.edit?.version||0,
  editData:structuredClone(S.data)};
 status('編集結果を保存しています…');
 await update('edit.save',payload,blob,async data=>{
  S.edit=data;S.dirty=false;await refresh();status('編集結果を保存しました。');
 });
});
$('finish').onclick=()=>job(async()=>{
 commitText();if(!discard())return;
 S.editing=false;S.dirty=false;S.connecting=null;S.selected.clear();
 S.data=S.edit?structuredClone(S.edit.editData):null;
 await load(S.edit?S.edit.renderedFileId:S.video.originalFileId);
 status('編集を終了しました。');
});
$('download').onclick=()=>job(async()=>{
 const id=S.edit?S.edit.renderedFileId:S.video.originalFileId;
 status('ダウンロードを準備しています…');
 const r=await fetch(url('file.download',id));
 if(!r.ok){const e=await r.json();throw Error(e.error?.message||'取得に失敗しました。')}
 const blob=await r.blob();const file=S.library.files.find(f=>f.id===id);
 const ext=file?.mimeType==='video/mp4'?'mp4':file?.mimeType==='video/quicktime'?'mov':'webm';
 const object=URL.createObjectURL(blob),a=document.createElement('a');
 a.href=object;a.download=(S.edit?.name||S.video.name)+'.'+ext;
 document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(object),60000);
 status('ダウンロードを開始しました。');
});
$('list').onclick=()=>job(async()=>{commitText();await refresh();$('veil').hidden=false});
$('closeList').onclick=()=>{$('veil').hidden=true};
$('upload').onclick=$('upload2').onclick=()=>{$('file').click()};
$('file').onchange=()=>{
 const f=$('file').files[0];$('file').value='';
 if(f&&discard())job(()=>upload(f,f.name));
};
$('folder').onclick=()=>job(()=>addFolder(null));
$('new').onclick=()=>{if(discard()){clear();$('veil').hidden=true;status('録画開始を押してください。')}};
$('viewZoom').oninput=()=>{commitText();resize()};
$('scale').oninput=tracks;
$('seek').oninput=()=>{if(!S.busy&&!S.recording){commitText();video.currentTime=+$('seek').value;tracks();draw()}};
$('pdf').onclick=()=>{commitText();$('pdfDialog').hidden=false};
$('cancelPDF').onclick=()=>{$('pdfDialog').hidden=true};
$('print').onclick=()=>job(async()=>{
 commitText();
 const positions=elements().filter(e=>e.type==='F').map(e=>e.time).sort((a,b)=>a-b);
 if(!positions.length)throw Error('タイムラインにPDF位置を追加してください。');
 const old=video.currentTime,restore=S.editing?S.video.originalFileId:S.edit?S.edit.renderedFileId:S.video.originalFileId;
 $('pdfDialog').hidden=true;video.pause();
 try{
  await load(S.video.originalFileId);
  const out=document.createElement('canvas');out.width=video.videoWidth;out.height=video.videoHeight;
  const c=out.getContext('2d'),count=+$('pageCount').value;
  $('printArea').replaceChildren();let page;
  for(let i=0;i<positions.length;i++){
   if(positions[i]<0||positions[i]>=S.duration)throw Error('PDF位置が動画範囲外です。');
   status('印刷用画面を準備中… '+(i+1)+' / '+positions.length);
   await seek(positions[i]);composite(c,positions[i]);
   if(i%count===0){page=document.createElement('div');page.className='printPage'+(count===1?' single':'');$('printArea').append(page)}
   const img=new Image();img.src=out.toDataURL('image/png');await img.decode();page.append(img);
  }
  await new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));
  window.print();
 }finally{await load(restore);await seek(Math.min(old,S.duration));draw()}
 status('印刷ダイアログでPDF保存を選べます。');
});
$('split').onpointerdown=event=>{
 const split=$('split'),start=event.clientY,stage=$('stage').clientHeight,timeline=$('timeline').clientHeight;
 split.setPointerCapture(event.pointerId);
 split.onpointermove=e=>{
  const d=clamp(e.clientY-start,100-stage,timeline-80);
  $('stage').style.height=stage+d+'px';$('timeline').style.height=timeline-d+'px';
 };
 split.onpointerup=split.onpointercancel=()=>{split.onpointermove=null;tracks()};
};
document.addEventListener('pointerdown',e=>{
 if(!S.pending&&!e.target.closest('#menu'))$('menu').hidden=true;
});
document.addEventListener('keydown',e=>{
 if(e.key==='Escape'){S.connecting=null;if(!S.pending)$('menu').hidden=true}
});
window.addEventListener('beforeunload',e=>{
 if(S.dirty||S.busy||S.recording||S.pending){e.preventDefault();e.returnValue=''}
});
window.addEventListener('resize',()=>{resize();tracks()});
video.onloadedmetadata=resize;
video.onplay=video.onpause=buttons;
let last=0,skipping=false;
function frame(now){
 if(S.editing&&!S.exporting&&!video.paused&&!video.seeking&&!skipping){
  const end=skipEnd(video.currentTime);
  if(end>video.currentTime){
   if(end>=S.duration){video.pause();video.currentTime=S.duration}
   else{skipping=true;video.currentTime=end;video.addEventListener('seeked',()=>{skipping=false},{once:true})}
  }
 }
 draw();clock();
 if(now-last>250&&!video.paused&&!S.gesture&&!S.recording){tracks();last=now}
 requestAnimationFrame(frame);
}
job(async()=>{
 status('保存領域のアクセス制御を確認中…');
 const r=await fetch('storage/metadata.json',{cache:'no-store'});
 if(r.status!==403){S.blocked=true;throw Error('storageの直接アクセスを禁止してください。ApacheのAllowOverride設定を確認してください。')}
 await refresh();status('録画開始、または動画をアップしてください。');
});
requestAnimationFrame(frame);
</script>
</body>
</html>
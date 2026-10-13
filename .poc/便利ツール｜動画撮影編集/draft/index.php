<?php
declare(strict_types=1);

/*
 * 動画撮影・編集 POC
 * アプリコードはこのファイルだけ。
 * storage/.transaction.json : 中断復旧用
 * storage/.lock             : 共通排他制御用
 */
const APP_VERSION = '1.0.0';
const STORE = __DIR__ . '/storage';

final class AppError extends RuntimeException {
    public string $errorCode;
    public function __construct(string $message, string $code = 'INVALID_INPUT', int $status = 400) {
        parent::__construct($message, $status);
        $this->errorCode = $code;
    }
}
function fail(string $message, string $code = 'INVALID_INPUT', int $status = 400): never {
    throw new AppError($message, $code, $status);
}
function uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 15) | 64);
    $b[8] = chr((ord($b[8]) & 63) | 128);
    $h = bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function nowUTC(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function jsonText(mixed $v): string {
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function writeJSON(string $name, array $value): void {
    $tmp = tempnam(STORE.'/tmp', 'json-');
    if ($tmp === false) fail('一時ファイルを作成できません。','SAVE_FAILED',500);
    try {
        $text = jsonText($value);
        $fp = fopen($tmp, 'wb');
        if (!$fp) fail('一時ファイルを開けません。','SAVE_FAILED',500);
        try {
            $offset = 0;
            while ($offset < strlen($text)) {
                $n = fwrite($fp, substr($text, $offset));
                if ($n === false || $n === 0) fail('保存に失敗しました。','SAVE_FAILED',500);
                $offset += $n;
            }
            if (!fflush($fp)) fail('保存に失敗しました。','SAVE_FAILED',500);
            if (function_exists('fsync') && !fsync($fp))
                fail('保存を確定できません。','SAVE_FAILED',500);
        } finally { fclose($fp); }
        if (!rename($tmp, STORE.'/'.$name)) fail('保存を確定できません。','SAVE_FAILED',500);
    } finally { if (is_file($tmp)) @unlink($tmp); }
}
function readJSON(string $name): array {
    $text = file_get_contents(STORE.'/'.$name);
    if ($text === false) fail('管理データを読み込めません。','STORAGE_ERROR',500);
    $v = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($v)) fail('管理データが不正です。','STORAGE_ERROR',500);
    return $v;
}
function idValue(mixed $v): string {
    if (!is_string($v) ||
        !preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $v))
        fail('IDが不正です。');
    return $v;
}
function nameValue(mixed $v): string {
    if (!is_string($v) || trim($v)==='' || strlen($v)>600) fail('名前が不正です。');
    return trim($v);
}
function indexOf(array $rows, string $id): int {
    foreach ($rows as $i=>$r) if ($r['id']===$id) return $i;
    fail('対象がありません。','NOT_FOUND',404);
}
function numberValue(mixed $v): float {
    if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v)) fail('数値が不正です。');
    return (float)$v;
}
function enumValue(mixed $v, array $allowed): void {
    if (!in_array($v,$allowed,true)) fail('書式値が不正です。');
}
function colorValue(mixed $v): void {
    if (!is_string($v) || !preg_match('/^(#[0-9a-f]{6}|transparent)$/i',$v))
        fail('色が不正です。');
}
function filePath(array $f): string {
    $p = $f['storagePath'] ?? '';
    if (!is_string($p) ||
        !preg_match('~^files/[0-9a-f-]{36}\.(mp4|webm|mov)$~i',$p))
        fail('ファイル情報が不正です。','STORAGE_ERROR',500);
    return STORE.'/'.$p;
}
function validateEdit(mixed $d): array {
    if (!is_array($d) || ($d['schemaVersion']??null)!==1 ||
        ($d['coordinateSystem']??null)!=='source-pixels' ||
        !is_array($d['elements']??null) || !array_is_list($d['elements']))
        fail('編集データが不正です。');
    if (numberValue($d['sourceWidth']??null)<=0 ||
        numberValue($d['sourceHeight']??null)<=0) fail('解像度が不正です。');

    $byId = [];
    foreach ($d['elements'] as $e) {
        if (!is_array($e)) fail('編集要素が不正です。');
        $id = idValue($e['id']??null);
        if (isset($byId[$id])) fail('編集要素IDが重複しています。');
        enumValue($e['type']??null,['A','B','C','D','E','F']);
        $byId[$id] = $e;
    }
    foreach ($byId as $e) {
        $type = $e['type'];
        if ($type==='F') {
            if (numberValue($e['time']??null)<0) fail('PDF位置が不正です。');
            continue;
        }
        if ($type==='E') {
            $owner = $byId[idValue($e['ownerId']??null)]??null;
            $comment = $byId[idValue($e['commentId']??null)]??null;
            if (!$owner || !in_array($owner['type'],['B','C'],true) ||
                !$comment || $comment['type']!=='A') fail('接続対象が不正です。');
            foreach (['from'=>$owner,'to'=>$comment] as $key=>$target) {
                $p = $e[$key]??null;
                if (!is_array($p)) fail('接続位置が不正です。');
                if ($target['shape']==='ellipse') numberValue($p['angle']??null);
                else {
                    enumValue($p['side']??null,['left','right','top','bottom']);
                    $r = numberValue($p['ratio']??null);
                    if ($r<0 || $r>1) fail('接続位置が不正です。');
                }
            }
            enumValue($e['line']??null,['straight','orthogonal','wave']);
            enumValue($e['terminal']??null,['none','arrow','circle']);
        } else {
            $start = numberValue($e['start']??null);
            $end = numberValue($e['end']??null);
            if ($start<0 || $end<=$start) fail('表示区間が不正です。');
            if ($type==='D') continue;
            foreach (['x','y','w','h'] as $k) {
                $n = numberValue($e[$k]??null);
                if ($n<0 || (in_array($k,['w','h'],true) && $n<=0)) fail('座標が不正です。');
            }
            if ($e['x']+$e['w']>$d['sourceWidth']+.01 ||
                $e['y']+$e['h']>$d['sourceHeight']+.01) fail('要素が動画領域を超えています。');
            enumValue($e['shape']??null,['rect','ellipse','round']);
            colorValue($e['background']??null);
            if ($type==='A') {
                if (!is_string($e['text']??null)) fail('コメントが不正です。');
                $size = numberValue($e['size']??null);
                if ($size<10 || $size>40) fail('文字サイズが不正です。');
                enumValue($e['font']??null,['gothic','mincho']);
                colorValue($e['color']??null);
            }
            if ($type==='C' && numberValue($e['zoom']??null)<1) fail('倍率が不正です。');
        }
        if (numberValue($e['width']??null)<0) fail('線幅が不正です。');
        colorValue($e['stroke']??null);
        enumValue($e['dash']??null,['solid','dash','dot','chain']);
    }
    return $d;
}
function receiveFile(): array {
    if (!class_exists('finfo')) fail('PHPのfileinfo拡張を有効にしてください。','SERVER_CONFIGURATION',500);
    $u = $_FILES['file']??null;
    if (!$u || $u['error']!==UPLOAD_ERR_OK || !is_uploaded_file($u['tmp_name']))
        fail('動画を受信できません。アップロード容量設定を確認してください。','UPLOAD_FAILED');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($u['tmp_name']);
    $ext = match($mime) {
        'video/mp4','application/mp4'=>'mp4',
        'video/webm'=>'webm',
        'video/quicktime'=>'mov',
        default=>null
    };
    if (!$ext) fail('MP4、WebM、MOVの動画を指定してください。');
    $id = uuid();
    $tmp = STORE.'/tmp/'.$id;
    if (!move_uploaded_file($u['tmp_name'],$tmp)) fail('一時保存に失敗しました。','SAVE_FAILED',500);
    $size = filesize($tmp);
    if (!$size) { @unlink($tmp); fail('動画が空です。'); }
    $f = ['id'=>$id,'storagePath'=>"files/$id.$ext",'mimeType'=>$mime,
        'size'=>$size,'createdAt'=>nowUTC()];
    if (!rename($tmp,filePath($f))) { @unlink($tmp); fail('動画保存に失敗しました。','SAVE_FAILED',500); }
    return $f;
}
function retryDeletion(array $pending): array {
    $left = [];
    foreach ($pending as $f) {
        $path = filePath($f);
        if (is_file($path) && !@unlink($path)) $left[$f['id']]=$f;
    }
    return array_values($left);
}
function recover(): void {
    if (!is_file(STORE.'/.transaction.json')) return;
    $j = readJSON('.transaction.json');
    $state = ($j['phase']??'')==='committed' ? $j['after'] : $j['before'];
    writeJSON('metadata.json',$state['metadata']);
    writeJSON('requests.json',$state['requests']);
    writeJSON('deletion-retry.json',$state['pending']);
    if (!unlink(STORE.'/.transaction.json')) fail('中断復旧を完了できません。','STORAGE_ERROR',500);
}
function commit(array $before,array $after): void {
    $j = ['phase'=>'prepared','before'=>$before,'after'=>$after];
    writeJSON('.transaction.json',$j);
    try {
        writeJSON('metadata.json',$after['metadata']);
        writeJSON('requests.json',$after['requests']);
        writeJSON('deletion-retry.json',$after['pending']);
        $j['phase']='committed';
        writeJSON('.transaction.json',$j);
    } catch (Throwable $e) {
        recover();
        throw $e;
    }
    // 確定後の補助処理失敗を、保存失敗として返さない。
    if (!@unlink(STORE.'/.transaction.json'))
        error_log('Transaction cleanup pending');
}
function canonical(mixed $v): mixed {
    if (!is_array($v)) return $v;
    if (!array_is_list($v)) ksort($v);
    foreach ($v as $k=>$x) $v[$k]=canonical($x);
    return $v;
}
function reply(array $data,?string $requestId=null): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo jsonText(['ok'=>true,'requestId'=>$requestId,'data'=>$data]);
    exit;
}

$lock = null;
$requestId = null;
try {
    foreach ([STORE,STORE.'/files',STORE.'/tmp'] as $dir) {
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir))
            fail('保存領域を作成できません。','STORAGE_ERROR',500);
        if (!is_readable($dir) || !is_writable($dir))
            fail('保存領域の読み書き権限を確認してください。','STORAGE_ERROR',500);
    }
    $lock = fopen(STORE.'/.lock','c');
    if (!$lock || !flock($lock,LOCK_EX)) fail('排他制御に失敗しました。','STORAGE_ERROR',500);
    $deny = "Require all denied\n";
    if (!is_file(STORE.'/.htaccess') &&
        file_put_contents(STORE.'/.htaccess',$deny)!==strlen($deny))
        fail('アクセス制御を作成できません。','STORAGE_ERROR',500);
    if (trim((string)file_get_contents(STORE.'/.htaccess'))!==trim($deny))
        fail('storageのアクセス制御を確認してください。','STORAGE_ERROR',500);

    if (!is_file(STORE.'/metadata.json')) {
        $t=nowUTC();
        writeJSON('metadata.json',[
            'schemaVersion'=>1,
            'folders'=>[['id'=>uuid(),'parentFolderId'=>null,'name'=>'デフォルトフォルダ','createdAt'=>$t,'updatedAt'=>$t]],
            'videos'=>[],'edits'=>[],'files'=>[]
        ]);
    }
    foreach (['requests.json','deletion-retry.json'] as $n)
        if (!is_file(STORE.'/'.$n)) writeJSON($n,[]);
    $m=readJSON('metadata.json');
    if (($m['schemaVersion']??null)!==1)
        fail('未対応のデータ形式です。上書きしません。','SCHEMA_UNSUPPORTED',409);
    recover();
    $m=readJSON('metadata.json');
    foreach (['folders','videos','edits','files'] as $key)
        if (!isset($m[$key]) || !is_array($m[$key]) || !array_is_list($m[$key]))
            fail('管理データが不正です。','STORAGE_ERROR',500);

    $isAPI=isset($_GET['api']);
    $method=$_SERVER['REQUEST_METHOD']??'GET';

    if ($isAPI) {
        if ($_GET['api']!=='v1') fail('APIがありません。','NOT_FOUND',404);
        $action=(string)($_GET['action']??'');

        if ($method==='GET') {
            if ($action==='list') {
                $public=$m;
                foreach ($public['files'] as &$f) unset($f['storagePath']);
                unset($f);
                reply($public);
            }
            if ($action==='edit.get') {
                $id=idValue($_GET['id']??null);
                reply($m['edits'][indexOf($m['edits'],$id)]);
            }
            if (!in_array($action,['file.stream','file.download'],true))
                fail('操作がありません。','NOT_FOUND',404);
            $id=idValue($_GET['id']??null);
            $f=$m['files'][indexOf($m['files'],$id)];
            $path=filePath($f);
            $fp=fopen($path,'rb');
            if (!$fp) fail('動画を開けません。','NOT_FOUND',404);
            $stat=fstat($fp); $size=(int)$stat['size'];
            $start=0; $end=$size-1;
            if (isset($_SERVER['HTTP_RANGE'])) {
                $range=$_SERVER['HTTP_RANGE'];
                if (!preg_match('/^bytes=(\d*)-(\d*)$/',$range,$r) ||
                    ($r[1]==='' && $r[2]==='')) {
                    header("Content-Range: bytes */$size");
                    fail('Rangeが不正です。','INVALID_RANGE',416);
                }
                if ($r[1]==='') {
                    if ((int)$r[2]<=0) {
                        header("Content-Range: bytes */$size");
                        fail('Rangeが不正です。','INVALID_RANGE',416);
                    }
                    $start=max(0,$size-(int)$r[2]);
                } else {
                    $start=(int)$r[1];
                    if ($r[2]!=='') $end=min($end,(int)$r[2]);
                }
                if ($start>$end || $start>=$size) {
                    header("Content-Range: bytes */$size");
                    fail('Rangeが範囲外です。','INVALID_RANGE',416);
                }
                http_response_code(206);
                header("Content-Range: bytes $start-$end/$size");
            }
            header('Content-Type: '.$f['mimeType']);
            header('X-Content-Type-Options: nosniff');
            header('Accept-Ranges: bytes');
            header('Content-Length: '.($end-$start+1));
            if ($action==='file.download') {
                $name='動画';
                foreach ($m['videos'] as $v)
                    if ($v['originalFileId']===$id) $name=$v['name'];
                foreach ($m['edits'] as $e)
                    if ($e['renderedFileId']===$id) $name=$e['name'];
                $ext=pathinfo($path,PATHINFO_EXTENSION);
                $name=preg_replace('/[\\\\\/:*?"<>|\r\n]/u','_',$name).'.'.$ext;
                header("Content-Disposition: attachment; filename=\"video.$ext\"; filename*=UTF-8''".rawurlencode($name));
            }
            flock($lock,LOCK_UN); fclose($lock); $lock=null;
            fseek($fp,$start);
            $left=$end-$start+1;
            while ($left>0 && !feof($fp) && !connection_aborted()) {
                $chunk=fread($fp,min(1048576,$left));
                if ($chunk===false || $chunk==='') break;
                echo $chunk; $left-=strlen($chunk);
            }
            fclose($fp);
            exit;
        }

        if ($method!=='POST') fail('POSTを使用してください。','METHOD_NOT_ALLOWED',405);
        try {
            $req=json_decode((string)($_POST['metadata']??file_get_contents('php://input')),true,512,JSON_THROW_ON_ERROR);
        } catch (JsonException) { fail('要求JSONが不正です。'); }
        if (!is_array($req)) fail('要求が不正です。');
        $requestId=idValue($req['requestId']??null);
        $p=$req['payload']??null;
        if (!is_array($p)) fail('payloadが不正です。');
        $requests=readJSON('requests.json');
        $pending=readJSON('deletion-retry.json');
        $uploadHash=null;
        if (isset($_FILES['file']) && $_FILES['file']['error']===UPLOAD_ERR_OK)
            $uploadHash=hash_file('sha256',$_FILES['file']['tmp_name']);
        $hash=hash('sha256',jsonText(canonical([$action,$p,$uploadHash])));
        if (isset($requests[$requestId])) {
            if ($requests[$requestId]['hash']!==$hash)
                fail('同じ要求IDで内容が異なります。','REQUEST_CONFLICT',409);
            reply($requests[$requestId]['data'],$requestId);
        }
        $id=idValue($p['id']??null);
        $before=['metadata'=>$m,'requests'=>$requests,'pending'=>$pending];
        $t=nowUTC(); $newFile=null; $removed=[]; $data=['id'=>$id];

        if ($action==='folder.create') {
            foreach ($m['folders'] as $f)
                if ($f['id']===$id) fail('IDが存在します。','CONFLICT',409);
            if (!array_key_exists('parentFolderId',$p)) fail('親フォルダの指定が必要です。');
            $parent=$p['parentFolderId'];
            if ($parent!==null) indexOf($m['folders'],idValue($parent));
            $m['folders'][]=['id'=>$id,'parentFolderId'=>$parent,'name'=>nameValue($p['name']??null),'createdAt'=>$t,'updatedAt'=>$t];
        } elseif (in_array($action,['folder.rename','video.rename','edit.rename'],true)) {
            $key=explode('.',$action)[0].'s';
            $i=indexOf($m[$key],$id);
            $m[$key][$i]['name']=nameValue($p['name']??null);
            $m[$key][$i]['updatedAt']=$t;
        } elseif ($action==='video.move') {
            $i=indexOf($m['videos'],$id);
            $folder=idValue($p['folderId']??null);
            indexOf($m['folders'],$folder);
            $m['videos'][$i]['folderId']=$folder;
            $m['videos'][$i]['updatedAt']=$t;
        } elseif ($action==='video.upload') {
            foreach ($m['videos'] as $v)
                if ($v['id']===$id) fail('IDが存在します。','CONFLICT',409);
            $folder=idValue($p['folderId']??null);
            indexOf($m['folders'],$folder);
            $name=nameValue($p['name']??null);
            $newFile=receiveFile();
            $m['files'][]=$newFile;
            $m['videos'][]=['id'=>$id,'folderId'=>$folder,'name'=>$name,'originalFileId'=>$newFile['id'],'createdAt'=>$t,'updatedAt'=>$t];
        } elseif ($action==='edit.save') {
            $videoId=idValue($p['videoId']??null);
            indexOf($m['videos'],$videoId);
            $d=validateEdit($p['editData']??null);
            $name=nameValue($p['name']??null);
            $i=null;
            foreach ($m['edits'] as $k=>$e) if ($e['id']===$id) $i=$k;
            $version=$i===null?0:$m['edits'][$i]['version'];
            if (($p['expectedVersion']??null)!==$version ||
                ($i!==null && $m['edits'][$i]['videoId']!==$videoId))
                fail('編集の版が競合しています。再取得してください。','VERSION_CONFLICT',409);
            $newFile=receiveFile();
            $m['files'][]=$newFile;
            $data=['id'=>$id,'videoId'=>$videoId,'name'=>$name,'version'=>$version+1,
                'editData'=>$d,'renderedFileId'=>$newFile['id'],
                'createdAt'=>$i===null?$t:$m['edits'][$i]['createdAt'],'updatedAt'=>$t];
            if ($i===null) $m['edits'][]=$data;
            else { $removed[]=$m['edits'][$i]['renderedFileId']; $m['edits'][$i]=$data; }
        } elseif (in_array($action,['folder.delete','video.delete','edit.delete'],true)) {
            $folderIds=[]; $videoIds=[]; $editIds=[];
            if ($action==='folder.delete') {
                indexOf($m['folders'],$id); $folderIds=[$id];
                do {
                    $count=count($folderIds);
                    foreach ($m['folders'] as $f)
                        if (in_array($f['parentFolderId'],$folderIds,true) &&
                            !in_array($f['id'],$folderIds,true)) $folderIds[]=$f['id'];
                } while ($count!==count($folderIds));
                foreach ($m['videos'] as $v)
                    if (in_array($v['folderId'],$folderIds,true)) $videoIds[]=$v['id'];
            } elseif ($action==='video.delete') {
                indexOf($m['videos'],$id); $videoIds=[$id];
            } else {
                indexOf($m['edits'],$id); $editIds=[$id];
            }
            foreach ($m['videos'] as $v)
                if (in_array($v['id'],$videoIds,true)) $removed[]=$v['originalFileId'];
            foreach ($m['edits'] as $e)
                if (in_array($e['videoId'],$videoIds,true) || in_array($e['id'],$editIds,true)) {
                    $editIds[]=$e['id']; $removed[]=$e['renderedFileId'];
                }
            foreach (['folders'=>$folderIds,'videos'=>$videoIds,'edits'=>$editIds] as $key=>$ids)
                $m[$key]=array_values(array_filter($m[$key],fn($r)=>!in_array($r['id'],$ids,true)));
        } else fail('操作がありません。','NOT_FOUND',404);

        foreach ($m['files'] as $f)
            if (in_array($f['id'],$removed,true)) $pending[]=$f;
        $m['files']=array_values(array_filter($m['files'],fn($f)=>!in_array($f['id'],$removed,true)));

        // 正式データから参照されない残存ファイルを回収対象にする。
        $known=array_fill_keys(array_map(fn($f)=>basename($f['storagePath']),$m['files']),true);
        foreach (glob(STORE.'/files/*')?:[] as $path) {
            $base=basename($path);
            if (!isset($known[$base]) &&
                preg_match('/^([0-9a-f-]{36})\.(mp4|webm|mov)$/i',$base,$match))
                $pending[]=['id'=>$match[1],'storagePath'=>'files/'.$base];
        }
        $requests[$requestId]=['hash'=>$hash,'data'=>$data];
        try {
            commit($before,['metadata'=>$m,'requests'=>$requests,'pending'=>$pending]);
        } catch (Throwable $e) {
            if ($newFile) @unlink(filePath($newFile));
            throw $e;
        }
        try {
            if (!is_file(STORE.'/.transaction.json')) {
                writeJSON('deletion-retry.json',retryDeletion($pending));
                foreach (glob(STORE.'/tmp/*')?:[] as $path)
                    if (is_file($path)) @unlink($path);
            }
        } catch (Throwable $e) { error_log((string)$e); }
        reply($data,$requestId);
    }
    flock($lock,LOCK_UN); fclose($lock); $lock=null;
} catch (Throwable $e) {
    if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
    error_log((string)$e);
    $status=$e instanceof AppError ? $e->getCode() : 500;
    $message=$e instanceof AppError ? $e->getMessage() : '処理に失敗しました。サーバログを確認してください。';
    http_response_code($status);
    if (isset($_GET['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo jsonText(['ok'=>false,'requestId'=>$requestId,
            'error'=>['code'=>$e instanceof AppError?$e->errorCode:'SERVER_ERROR','message'=>$message]]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>起動エラー</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';
    }
    exit;
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="data:,">
<title>動画撮影・編集</title>
<style>
*{box-sizing:border-box}
body{margin:0;font:12px Meiryo,sans-serif;color:#243247;background:#eef2f7}
button,input,select{font:inherit}
button{height:26px;padding:0 9px;border:1px solid #ccd6e3;border-radius:4px;background:white;cursor:pointer;white-space:nowrap}
button:hover{background:#edf4ff}
button:disabled{opacity:.45;cursor:default}
header,.toolbar{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
header{padding:8px 12px;background:white;border-bottom:1px solid #ccd6e3}
header strong{margin-right:auto;font-size:14px}
main{height:calc(100dvh - 43px);min-height:430px;padding:8px;display:flex;flex-direction:column;gap:7px}
#stage{flex:1;min-height:140px;background:#172235;display:grid;place-items:center;overflow:auto}
#empty{color:#ccd8e8;line-height:2;text-align:center}
#surface{position:relative;flex:none}
#video{display:block;width:100%;height:100%}
#overlay{position:absolute;inset:0;width:100%;height:100%;touch-action:none}
#actions{justify-content:center}
#primary{background:#2563eb;color:white;border-color:#2563eb}
#status{min-height:18px;color:#607089;white-space:pre-wrap}
#viewZoom{width:100px}
#split{height:6px;flex:none;background:#ccd6e3;cursor:ns-resize;touch-action:none}
#timeline{height:205px;min-height:95px;flex:none;background:white;border:1px solid #ccd6e3;display:flex;flex-direction:column}
#timeline .toolbar{padding:6px 9px;border-bottom:1px solid #ccd6e3}
#timeline small{margin-left:auto}
#scroll{flex:1;overflow-y:auto;overflow-x:hidden}
#tracks{position:relative;min-height:155px;touch-action:none;overflow:hidden}
.tick{position:absolute;top:0;height:22px;border-left:1px solid #dde4ed;font-size:10px;color:#64748b}
.clip{position:absolute;height:24px;padding:3px 8px;border:1px solid #91b5e6;background:#e0ecff;border-radius:3px;overflow:hidden;white-space:nowrap;cursor:move;user-select:none}
.clip.B{background:#fff1bd}.clip.C{background:#d9f3e6}.clip.D{background:#ffe2e6}.clip.F{background:#eee2ff}
.clip.selected{outline:2px solid #2563eb;outline-offset:-2px}
.edge{position:absolute;top:0;bottom:0;width:6px;background:#0002;cursor:ew-resize}
.edge.start{left:0}.edge.end{right:0}
#playhead{position:absolute;top:0;bottom:0;width:2px;background:#e44972;pointer-events:none;z-index:2}
#handle{position:absolute;left:-6px;width:14px;height:20px;background:#e44972;clip-path:polygon(0 0,100% 0,100% 65%,50% 100%,0 65%);pointer-events:auto;cursor:ew-resize}
#veil{position:fixed;inset:0;background:#0005;z-index:10}
#library{position:fixed;inset:0 auto 0 0;width:min(500px,100vw);background:white;z-index:11;display:flex;flex-direction:column;box-shadow:4px 0 20px #0003}
#library .toolbar{padding:8px;border-bottom:1px solid #ccd6e3}
#tree{flex:1;overflow:auto;padding:8px}
.row{display:flex;align-items:center;gap:5px;min-height:28px;padding:3px}
.row:hover{background:#edf4ff}.row.drop{outline:2px dashed #2563eb}
.row button{width:20px;padding:0;border:0}
.name{flex:1;cursor:pointer;overflow-wrap:anywhere}
.name[contenteditable=true]{outline:1px solid #2563eb;background:white}
.row time{font-size:10px;color:#64748b;flex:none}
#menu{position:fixed;z-index:30;background:white;border:1px solid #ccd6e3;border-radius:4px;padding:6px;max-height:85vh;max-width:95vw;overflow:auto;box-shadow:0 5px 20px #0003}
#menu>button{display:block;width:100%;text-align:left;border:0}
#menu label{display:flex;justify-content:space-between;gap:12px;padding:5px}
.palette{display:grid;grid-template-columns:repeat(6,24px);gap:5px;padding:5px}
.palette button{width:24px;height:24px;padding:0}
.editor{position:absolute;white-space:pre-wrap;overflow:auto;padding:6px;outline:2px solid #60a5fa;z-index:3}
dialog{max-width:92vw;border:1px solid #ccd6e3;border-radius:5px}
dialog::backdrop{background:#0005}
dialog label{display:block;margin:12px 0}
[hidden]{display:none!important}
#printArea{display:none}
@media(max-width:600px){.row time,#timeline small{display:none}main{padding:5px}#viewZoom{width:65px}}
@media print{
@page{size:A4 portrait;margin:12mm}
body>*:not(#printArea){display:none!important}
#printArea{display:block}
.sheet{height:268mm;display:flex;flex-direction:column;gap:6mm;break-after:page}
.sheet:last-child{break-after:auto}
.shot{flex:1;min-height:0;display:flex;flex-direction:column;justify-content:center;align-items:center}
.shot img{max-width:100%;object-fit:contain}
.shot p{font-size:10pt}
}
</style>
</head>
<body>
<header>
<strong>動画撮影・編集</strong>
<button id="list">動画一覧</button>
<button id="upload">動画をアップする</button>
</header>
<main>
<section id="stage">
<div id="empty">録画開始で共有する画面を選択してください。<br>保存済み動画は動画一覧から開けます。</div>
<div id="surface" hidden>
<video id="video" playsinline preload="auto" crossorigin="anonymous"></video>
<canvas id="overlay"></canvas>
</div>
</section>
<div id="actions" class="toolbar">
<button id="primary">録画開始</button>
<button id="begin" hidden>編集開始</button>
<button id="save" hidden>編集結果保存</button>
<button id="download" hidden>ダウンロード</button>
<button id="pdf" hidden>PDF出力</button>
<button id="finish" hidden>編集作業終了</button>
<span id="zoomLabel">表示倍率</span>
<input id="viewZoom" type="range" min="20" max="200" value="100" aria-label="動画表示倍率">
</div>
<div id="status" role="status" aria-live="polite"></div>
<div id="split" hidden></div>
<section id="timeline" hidden>
<div class="toolbar">
<strong id="clock">0.0 / 0.0 秒</strong>
<label>タイムスケール
<input id="scale" type="range" min="1" max="100" value="100" aria-label="表示時間範囲">
</label>
<small>右クリック：追加・書式・削除　Ctrl／Shift：複数選択</small>
</div>
<div id="scroll">
<div id="tracks"><div id="playhead"><div id="handle"></div></div></div>
</div>
</section>
</main>
<div id="veil" hidden></div>
<aside id="library" hidden>
<div class="toolbar">
<button id="new">新たに画面を録画する</button>
<button id="upload2">動画をアップする</button>
<button id="folder">＋フォルダ</button>
</div>
<div id="tree"></div>
<div class="toolbar">
<button id="closeList">動画一覧を閉じる</button>
<small>ダブルクリック：名前変更　右クリック：操作</small>
</div>
</aside>
<input id="file" type="file" accept="video/mp4,video/webm,video/quicktime" hidden>
<div id="menu" hidden></div>
<dialog id="pdfDialog">
<h3>PDF出力</h3>
<label><input type="radio" name="layout" value="1" checked>1ページに1画面</label>
<label><input type="radio" name="layout" value="2">1ページに2画面</label>
<button id="print">印刷へ進む</button>
<button id="cancelPDF">キャンセル</button>
</dialog>
<div id="printArea"></div>
<script>
'use strict';
(()=>{
const $=id=>document.getElementById(id);
const video=$('video'),canvas=$('overlay'),context=canvas.getContext('2d');
const uid=()=>crypto.randomUUID(),copy=x=>structuredClone(x);
const clamp=(x,a,b)=>Math.max(a,Math.min(b,x));
const titles={A:'コメント',B:'強調枠',C:'拡大枠',D:'スキップ',E:'接続線',F:'PDF位置'};
const colors=['#FFFFFF','#111827','#EF4444','#F97316','#EAB308','#22C55E','#14B8A6','#2563EB','#8B5CF6','#EC4899','#64748B','transparent'];
const dashes={solid:[],dash:[10,6],dot:[2,5],chain:[12,5,2,5]};
const state={
    model:null,current:null,revision:null,data:null,
    editing:false,dirty:false,busy:false,blocked:false,
    duration:0,screen:null,writer:null,editor:null,
    connecting:null,rendered:false,pending:null
};
let selected=new Set(),collapsed=new Set();
let audio=null,audioSource=null,audioSink=null;
let trackStamp=0;
const message=text=>$('status').textContent=text;
const elements=()=>state.data?.elements||[];
const find=id=>elements().find(e=>e.id===id);
const locked=()=>state.busy||state.blocked||!!state.screen;
const endpointTime=e=>e.type==='F'?e.time:e.start;

function endpoint(action,id){
    return 'index.php?api=v1&action='+encodeURIComponent(action)+(id?'&id='+encodeURIComponent(id):'');
}
async function transmit(request){
    const options={method:'POST'};
    const envelope={requestId:request.requestId,payload:request.payload};
    if(request.file){
        const form=new FormData();
        form.append('metadata',JSON.stringify(envelope));
        form.append('file',request.file,'video.'+(request.file.type.includes('mp4')?'mp4':'webm'));
        options.body=form;
    }else{
        options.headers={'Content-Type':'application/json'};
        options.body=JSON.stringify(envelope);
    }
    return fetchJSON(endpoint(request.action),options);
}
async function fetchJSON(url,options={}){
    const response=await fetch(url,{cache:'no-store',...options});
    const text=await response.text();
    let result;
    try{result=JSON.parse(text)}
    catch{throw Object.assign(Error('サーバ応答を読み取れません。'),{uncertain:true})}
    if(!response.ok||!result.ok){
        const error=Error(result.error?.message||'通信に失敗しました。');
        error.code=result.error?.code;
        error.uncertain=response.status>=500;
        throw error;
    }
    return result.data;
}
async function updateAPI(action,payload,file=null){
    if(state.pending)throw Error('未確認の保存要求があります。先に再試行してください。');
    const request={action,payload:copy(payload),file,requestId:uid()};
    state.pending=request;
    try{
        const result=await transmit(request);
        state.pending=null;
        return result;
    }catch(error){
        if(error.code&&!error.uncertain)state.pending=null;
        else error.message+=' 同じ要求で再試行するには、右クリックメニューを開いてください。';
        throw error;
    }
}
async function retry(){
    if(!state.pending)return;
    const request=state.pending;
    const result=await transmit(request);
    state.pending=null;
    await refresh();
    if(request.action==='edit.save'){
        state.revision=result;
        state.dirty=false;
    }
    if(request.action==='video.upload'){
        const item=state.model.videos.find(v=>v.id===request.payload.id);
        if(item)await openVideo(item);
    }
    message('保存結果を確認しました。');
}
async function job(fn){
    if(state.busy||state.blocked)return;
    state.busy=true;update();
    try{await fn()}
    catch(error){console.error(error);message(error.message||'操作に失敗しました。')}
    finally{state.busy=false;update()}
}
async function refresh(){
    state.model=await fetchJSON(endpoint('list'));
    if(state.model.schemaVersion!==1)throw Error('未対応のデータ形式です。');
    tree();
}
function library(open){$('library').hidden=$('veil').hidden=!open}
function commitText(){
    if(!state.editor)return;
    const active=state.editor;state.editor=null;
    active.item.text=active.node.innerText;
    active.node.remove();
    state.dirty=true;
}
function fit(){
    if(!video.videoWidth)return;
    const w=video.videoWidth,h=video.videoHeight;
    const r=$('stage').getBoundingClientRect();
    const base=Math.min(r.width/w,r.height/h);
    const factor=base*+$('viewZoom').value/100;
    $('surface').style.width=w*factor+'px';
    $('surface').style.height=h*factor+'px';
    if(canvas.width!==w||canvas.height!==h){canvas.width=w;canvas.height=h}
}
function update(){
    const loaded=!!state.current,screen=!!state.screen;
    $('surface').hidden=!video.getAttribute('src')&&!video.srcObject;
    $('empty').hidden=!$('surface').hidden;
    $('timeline').hidden=$('split').hidden=!state.editing;
    $('begin').hidden=!loaded||state.editing||screen;
    $('save').hidden=$('finish').hidden=!state.editing;
    $('download').hidden=$('pdf').hidden=!loaded||screen;
    $('zoomLabel').hidden=$('viewZoom').hidden=!loaded&&!screen;
    $('primary').textContent=screen?'録画停止':
        state.editing?(video.paused?'編集再生':'編集再生停止'):'録画開始';
    for(const id of ['list','upload','upload2','new','folder','begin','save','download','pdf','finish'])
        $(id).disabled=locked();
    $('primary').disabled=state.busy||state.blocked;
    $('viewZoom').disabled=state.busy;
    fit();tracks();paint();
}
function clear(){
    commitText();video.pause();
    video.srcObject=null;video.removeAttribute('src');video.load();
    Object.assign(state,{current:null,revision:null,data:null,editing:false,
        dirty:false,duration:0,rendered:false,connecting:null});
    selected.clear();$('viewZoom').value=100;$('scale').value=100;
    update();
}
function waitMedia(events,test,action,timeout=30000){
    return new Promise((resolve,reject)=>{
        let timer;
        const clean=()=>{
            clearTimeout(timer);
            events.forEach(e=>video.removeEventListener(e,check));
            video.removeEventListener('error',error);
        };
        const check=()=>{if(test()){clean();resolve()}};
        const error=()=>{clean();reject(Error('動画を読み込めません。形式と配信を確認してください。'))};
        events.forEach(e=>video.addEventListener(e,check));
        video.addEventListener('error',error);
        timer=setTimeout(()=>{clean();reject(Error('動画処理がタイムアウトしました。'))},timeout);
        try{action?.();check()}catch(e){clean();reject(e)}
    });
}
async function seek(time){
    const target=clamp(time,0,state.duration);
    if(Math.abs(video.currentTime-target)>.001||video.seeking){
        await waitMedia(['seeked','timeupdate'],
            ()=>!video.seeking&&Math.abs(video.currentTime-target)<.05,
            ()=>{video.currentTime=target});
    }
    if(video.readyState<2)
        await waitMedia(['loadeddata','canplay'],()=>video.readyState>=2);
    await new Promise(requestAnimationFrame);
}
async function setSource(id){
    video.pause();video.srcObject=null;state.duration=0;
    await waitMedia(['loadedmetadata'],()=>video.readyState>=1,()=>{
        video.src=endpoint('file.stream',id);video.load();
    });
    if(!Number.isFinite(video.duration)||video.duration<=0){
        await waitMedia(['durationchange','seeked','timeupdate'],
            ()=>Number.isFinite(video.duration)&&video.duration>0,
            ()=>{video.currentTime=1e10});
    }
    state.duration=video.duration;
    await seek(0);fit();
}
function leave(){
    commitText();
    if(state.pending){message('未確認の保存要求を先に再試行してください。');return false}
    return !state.dirty||confirm('未保存の編集を破棄しますか？');
}
async function openVideo(item,revision=null,editMode=false){
    if(!leave())return;
    const edit=revision?await fetchJSON(endpoint('edit.get',revision.id)):null;
    clear();
    state.current=item;state.revision=edit;
    state.data=edit?copy(edit.editData):null;
    state.rendered=!!edit&&!editMode;
    await setSource(state.rendered?edit.renderedFileId:item.originalFileId);
    if(editMode){
        state.editing=true;
        state.data??={schemaVersion:1,coordinateSystem:'source-pixels',
            sourceWidth:video.videoWidth,sourceHeight:video.videoHeight,elements:[]};
        validateDuration();
    }
    library(false);update();
    message(item.name+' ／ '+(edit?.name||'元動画'));
}
function validateDuration(){
    if(!state.data)return;
    if(state.data.sourceWidth!==video.videoWidth||state.data.sourceHeight!==video.videoHeight)
        throw Error('編集データと元動画の解像度が一致しません。');
    for(const e of elements()){
        if(e.type==='E')continue;
        if(e.type==='F'){
            if(e.time<0||e.time>=state.duration)throw Error('PDF位置が動画の範囲外です。');
        }else if(e.start<0||e.start>=e.end||e.end>state.duration+.001){
            throw Error('編集区間が動画の範囲外です。');
        }
    }
}
function point(event){
    const r=canvas.getBoundingClientRect();
    return{x:(event.clientX-r.left)*canvas.width/r.width,
        y:(event.clientY-r.top)*canvas.height/r.height};
}
function drag(event,move,stopCallback=()=>{}){
    event.preventDefault();
    const stop=()=>{
        window.removeEventListener('pointermove',move);
        window.removeEventListener('pointerup',stop);
        window.removeEventListener('pointercancel',stop);
        stopCallback();
    };
    window.addEventListener('pointermove',move);
    window.addEventListener('pointerup',stop);
    window.addEventListener('pointercancel',stop);
}
function choose(item,event){
    if(!event.ctrlKey&&!event.shiftKey)selected.clear();
    if(item){
        if((event.ctrlKey||event.shiftKey)&&selected.has(item.id))selected.delete(item.id);
        else selected.add(item.id);
    }
}
function shape(ctx,e){
    ctx.beginPath();
    if(e.shape==='ellipse')ctx.ellipse(e.x+e.w/2,e.y+e.h/2,e.w/2,e.h/2,0,0,Math.PI*2);
    else if(e.shape==='round')ctx.roundRect(e.x,e.y,e.w,e.h,Math.min(16,e.w/4,e.h/4));
    else ctx.rect(e.x,e.y,e.w,e.h);
}
function port(e,p){
    if(e.shape==='ellipse')
        return{x:e.x+e.w/2+Math.cos(p.angle)*e.w/2,y:e.y+e.h/2+Math.sin(p.angle)*e.h/2};
    const r=p.ratio;
    return p.side==='left'?{x:e.x,y:e.y+e.h*r}:
        p.side==='right'?{x:e.x+e.w,y:e.y+e.h*r}:
        p.side==='top'?{x:e.x+e.w*r,y:e.y}:{x:e.x+e.w*r,y:e.y+e.h};
}
function nearest(e,q){
    if(e.shape==='ellipse')
        return{angle:Math.atan2((q.y-e.y-e.h/2)/(e.h/2),(q.x-e.x-e.w/2)/(e.w/2))};
    const options=[
        {side:'left',ratio:clamp((q.y-e.y)/e.h,0,1)},
        {side:'right',ratio:clamp((q.y-e.y)/e.h,0,1)},
        {side:'top',ratio:clamp((q.x-e.x)/e.w,0,1)},
        {side:'bottom',ratio:clamp((q.x-e.x)/e.w,0,1)}
    ];
    return options.sort((a,b)=>{
        const p=port(e,a),r=port(e,b);
        return Math.hypot(p.x-q.x,p.y-q.y)-Math.hypot(r.x-q.x,r.y-q.y);
    })[0];
}
function normal(e,p){
    if(e.shape==='ellipse'){
        const x=Math.cos(p.angle)/e.w,y=Math.sin(p.angle)/e.h,l=Math.hypot(x,y)||1;
        return{x:x/l,y:y/l};
    }
    return{left:{x:-1,y:0},right:{x:1,y:0},top:{x:0,y:-1},bottom:{x:0,y:1}}[p.side];
}
function endpoints(e){
    const a=find(e.ownerId),b=find(e.commentId);
    if(!a||!b)return null;
    const p=port(a,e.from),q=port(b,e.to),n=normal(a,e.from),m=normal(b,e.to);
    return[p,{x:p.x+20*n.x,y:p.y+20*n.y},
        {x:q.x+20*m.x,y:q.y+20*m.y},q];
}
function visible(e,time){
    const owner=e.type==='E'?find(e.ownerId):e;
    return owner&&!['D','F'].includes(e.type)&&time>=owner.start&&time<owner.end;
}
const order={C:0,B:1,A:2,E:3};
function paint(ctx=context,time=video.currentTime,output=false){
    if(!output)ctx.clearRect(0,0,canvas.width,canvas.height);
    if(!output&&(!state.editing||state.rendered))return;
    elements().filter(e=>visible(e,time)).sort((a,b)=>order[a.type]-order[b.type]).forEach(e=>{
        ctx.save();ctx.strokeStyle=e.stroke;ctx.lineWidth=e.width;
        ctx.setLineDash(dashes[e.dash]);
        if(e.type==='E'){
            const ends=endpoints(e);
            if(!ends){ctx.restore();return}
            const[p,u,v,q]=ends;
            ctx.beginPath();ctx.moveTo(p.x,p.y);ctx.lineTo(u.x,u.y);
            if(e.line==='orthogonal'){
                ctx.lineTo((u.x+v.x)/2,u.y);ctx.lineTo((u.x+v.x)/2,v.y);
            }else if(e.line==='wave'){
                const dx=v.x-u.x,dy=v.y-u.y,l=Math.hypot(dx,dy)||1;
                for(let i=1;i<=80;i++){
                    const t=i/80,s=16*Math.sin(2*Math.PI*t);
                    ctx.lineTo(u.x+dx*t-dy/l*s,u.y+dy*t+dx/l*s);
                }
            }
            ctx.lineTo(v.x,v.y);ctx.lineTo(q.x,q.y);
            if(e.stroke!=='transparent'&&e.width>0)ctx.stroke();
            ctx.setLineDash([]);
            if(e.stroke!=='transparent'&&e.width>0){
                if(e.terminal==='arrow'){
                    const a=Math.atan2(q.y-v.y,q.x-v.x);
                    ctx.beginPath();
                    ctx.moveTo(q.x-12*Math.cos(a-.5),q.y-12*Math.sin(a-.5));
                    ctx.lineTo(q.x,q.y);
                    ctx.lineTo(q.x-12*Math.cos(a+.5),q.y-12*Math.sin(a+.5));
                    ctx.stroke();
                }else if(e.terminal==='circle'){
                    ctx.beginPath();ctx.arc(q.x,q.y,5,0,Math.PI*2);ctx.stroke();
                }
            }
            if(!output)[p,q].forEach((p,i)=>{
                ctx.fillStyle=i?'#22C55E':'#38BDF8';
                ctx.beginPath();ctx.arc(p.x,p.y,selected.has(e.id)?6:4,0,Math.PI*2);ctx.fill();
            });
        }else{
            if(e.type==='C'&&video.readyState>=2){
                ctx.save();shape(ctx,e);ctx.clip();
                ctx.drawImage(video,
                    e.x+(e.w-e.w/e.zoom)/2,e.y+(e.h-e.h/e.zoom)/2,
                    e.w/e.zoom,e.h/e.zoom,e.x,e.y,e.w,e.h);
                ctx.restore();
            }
            shape(ctx,e);
            if(e.background!=='transparent'){ctx.fillStyle=e.background;ctx.fill()}
            if(e.stroke!=='transparent'&&e.width>0)ctx.stroke();
            if(e.type==='A'){
                ctx.save();shape(ctx,e);ctx.clip();
                ctx.fillStyle=e.color;
                ctx.font=e.size+'px '+(e.font==='mincho'?'serif':'sans-serif');
                let y=e.y+e.size+6;
                for(const line of e.text.split('\n')){
                    let row='';
                    for(const ch of line){
                        if(row&&ctx.measureText(row+ch).width>e.w-12){
                            ctx.fillText(row,e.x+6,y);y+=e.size*1.35;row='';
                        }
                        row+=ch;
                    }
                    ctx.fillText(row,e.x+6,y);y+=e.size*1.35;
                }
                ctx.restore();
            }
            if(!output&&selected.has(e.id)){
                ctx.strokeStyle='#60A5FA';ctx.setLineDash([4,3]);ctx.lineWidth=1;
                ctx.strokeRect(e.x,e.y,e.w,e.h);
                ctx.fillStyle='#60A5FA';ctx.fillRect(e.x+e.w-7,e.y+e.h-7,14,14);
            }
        }
        ctx.restore();
    });
}
function hit(q){
    return elements().filter(e=>visible(e,video.currentTime))
        .sort((a,b)=>order[b.type]-order[a.type]).find(e=>{
            if(e.type==='E'){
                const p=endpoints(e);
                return p&&[p[0],p[3]].some(r=>Math.hypot(r.x-q.x,r.y-q.y)<15);
            }
            return q.x>=e.x&&q.x<=e.x+e.w&&q.y>=e.y&&q.y<=e.y+e.h;
        });
}
canvas.onpointerdown=event=>{
    if(event.button!==0||!state.editing||locked())return;
    commitText();video.pause();
    const q=point(event),item=hit(q);
    if(state.connecting){
        if(!item||!['A','B','C'].includes(item.type))return;
        if(state.connecting==='start'){
            state.connecting=item.id;message('接続先をクリックしてください。');return;
        }
        const first=find(state.connecting);
        if(!first||(first.type==='A')===(item.type==='A')){
            message('コメントと強調枠、またはコメントと拡大枠を選択してください。');return;
        }
        const a=first.type==='A'?item:first,b=first.type==='A'?first:item;
        const from=nearest(a,{x:b.x+b.w/2,y:b.y+b.h/2});
        const e={id:uid(),type:'E',ownerId:a.id,commentId:b.id,from,
            to:nearest(b,port(a,from)),stroke:a.stroke,width:a.width,
            dash:a.dash,line:'orthogonal',terminal:'arrow'};
        elements().push(e);selected=new Set([e.id]);
        state.dirty=true;state.connecting=null;paint();return;
    }
    choose(item,event);tracks();paint();
    if(!item)return;
    const old=copy(item);
    const resize=item.type!=='E'&&q.x>item.x+item.w-18&&q.y>item.y+item.h-18;
    const ends=item.type==='E'?endpoints(item):null;
    const end=ends&&Math.hypot(ends[0].x-q.x,ends[0].y-q.y)<15?'from':'to';
    drag(event,event=>{
        const p=point(event);
        if(item.type==='E')item[end]=nearest(find(end==='from'?item.ownerId:item.commentId),p);
        else if(resize){
            item.w=clamp(old.w+p.x-q.x,Math.min(10,canvas.width-item.x),canvas.width-item.x);
            item.h=clamp(old.h+p.y-q.y,Math.min(10,canvas.height-item.y),canvas.height-item.y);
        }else{
            item.x=clamp(old.x+p.x-q.x,0,canvas.width-item.w);
            item.y=clamp(old.y+p.y-q.y,0,canvas.height-item.h);
        }
        state.dirty=true;paint();
    });
};
function editText(item){
    commitText();video.pause();
    const node=document.createElement('div'),k=canvas.clientWidth/canvas.width;
    node.className='editor';node.dataset.elementId=item.id;
    node.contentEditable='true';node.innerText=item.text;
    Object.assign(node.style,{
        left:item.x*k+'px',top:item.y*k+'px',width:item.w*k+'px',height:item.h*k+'px',
        fontSize:item.size*k+'px',fontFamily:item.font==='mincho'?'serif':'sans-serif',
        color:item.color,background:item.background
    });
    $('surface').append(node);state.editor={node,item};
    node.oninput=()=>{item.text=node.innerText;state.dirty=true};
    node.onblur=commitText;node.focus();
}
canvas.ondblclick=event=>{
    if(!state.editing||locked())return;
    const item=hit(point(event));
    if(item?.type==='A')editText(item);
};
function showMenu(event){
    const menu=$('menu');menu.hidden=false;
    menu.style.left=clamp(event.clientX,4,Math.max(4,innerWidth-menu.offsetWidth-4))+'px';
    menu.style.top=clamp(event.clientY,4,Math.max(4,innerHeight-menu.offsetHeight-4))+'px';
}
function menu(event,items){
    event.preventDefault();
    if(locked())return;
    $('menu').replaceChildren();
    if(state.pending)items=[['未確認の保存要求を再試行',()=>job(retry)],...items];
    for(const [label,run] of items){
        const button=document.createElement('button');button.textContent=label;
        button.onclick=()=>{
            $('menu').hidden=true;
            Promise.resolve().then(run).catch(e=>message(e.message));
        };
        $('menu').append(button);
    }
    showMenu(event);
}
function range(){
    const span=Math.max(.001,state.duration*+$('scale').value/100);
    return{
        span,start:clamp(video.currentTime-span/2,0,Math.max(0,state.duration-span)),
        width:Math.max(1,$('tracks').clientWidth-20)
    };
}
function timeAt(event,r=range()){
    return clamp(r.start+(event.clientX-$('tracks').getBoundingClientRect().left-10)/r.width*r.span,0,state.duration);
}
function tracks(){
    const root=$('tracks');
    root.querySelectorAll('.clip,.tick').forEach(n=>n.remove());
    if(!state.editing){clock();return}
    const r=range();
    for(let i=0;i<=8;i++){
        const tick=document.createElement('span');tick.className='tick';
        tick.style.left=10+r.width*i/8+'px';
        tick.textContent=(r.start+r.span*i/8).toFixed(1)+'s';
        if(i===8)tick.style.transform='translateX(-100%)';
        root.append(tick);
    }
    const rows=[];
    elements().filter(e=>e.type!=='E')
        .sort((a,b)=>endpointTime(a)-endpointTime(b)).forEach(e=>{
            const a=endpointTime(e),b=e.type==='F'?a+.001:e.end;
            let row=rows.findIndex(end=>end<=a);
            if(row<0)row=rows.length;
            rows[row]=b;
            if(b<r.start||a>r.start+r.span)return;
            const node=document.createElement('div');
            node.className='clip '+e.type+(selected.has(e.id)?' selected':'');
            node.dataset.elementId=e.id;
            node.textContent=e.type==='A'?e.text:titles[e.type];
            const left=10+(Math.max(a,r.start)-r.start)/r.span*r.width;
            const width=e.type==='F'?8:(Math.min(b,r.start+r.span)-Math.max(a,r.start))/r.span*r.width;
            Object.assign(node.style,{
                left:left+'px',top:26+row*29+'px',
                width:Math.max(2,Math.min(root.clientWidth-10-left,width))+'px'
            });
            if(e.type!=='F'){
                for(const side of ['start','end']){
                    if(side==='start'&&a<r.start||side==='end'&&b>r.start+r.span)continue;
                    const edge=document.createElement('span');edge.className='edge '+side;
                    node.append(edge);
                }
            }
            node.oncontextmenu=event=>{
                event.stopPropagation();elementMenu(event,e,true);
            };
            node.onpointerdown=event=>{
                if(event.button!==0||locked())return;
                event.stopPropagation();commitText();video.pause();choose(e,event);
                const old=copy(e),x=event.clientX;
                const side=event.target.classList.contains('start')?'start':
                    event.target.classList.contains('end')?'end':'move';
                drag(event,event=>{
                    const delta=(event.clientX-x)/r.width*r.span;
                    if(e.type==='F')e.time=clamp(old.time+delta,0,Math.max(0,state.duration-.001));
                    else if(side==='start')e.start=clamp(old.start+delta,0,e.end-.001);
                    else if(side==='end')e.end=clamp(old.end+delta,e.start+.001,state.duration);
                    else{
                        const length=old.end-old.start;
                        e.start=clamp(old.start+delta,0,state.duration-length);
                        e.end=e.start+length;
                    }
                    state.dirty=true;tracks();paint();
                });
                tracks();paint();
            };
            root.append(node);
        });
    root.style.height=Math.max(155,rows.length*29+30)+'px';
    clock();
}
function clock(){
    $('clock').textContent=(video.currentTime||0).toFixed(1)+' / '+state.duration.toFixed(1)+' 秒';
    const r=range();
    $('playhead').style.left=clamp(10+(video.currentTime-r.start)/r.span*r.width,10,r.width+10)+'px';
}
function add(type,time,q){
    if(!state.duration||time>=state.duration){
        message('動画終了より前に追加してください。');return;
    }
    const e={id:uid(),type};
    if(type==='F')e.time=time;
    else{
        Object.assign(e,{start:time,end:Math.min(state.duration,time+3)});
        if(['A','B','C'].includes(type)){
            const w=Math.min(260,canvas.width),h=Math.min(110,canvas.height);
            Object.assign(e,{
                x:clamp(q.x,0,canvas.width-w),y:clamp(q.y,0,canvas.height-h),
                w,h,stroke:'#EF4444',width:3,dash:'solid',shape:'rect',background:'transparent'
            });
            if(type==='A')Object.assign(e,{text:'コメント',size:24,font:'gothic',color:'#FFFFFF'});
            if(type==='C')e.zoom=2;
        }
    }
    elements().push(e);selected=new Set([e.id]);state.dirty=true;tracks();paint();
    if(type==='A')editText(e);
}
function elementMenu(event,item,timeline){
    event.preventDefault();
    if(!state.editing||locked())return;
    commitText();video.pause();
    if(item&&!selected.has(item.id))selected=new Set([item.id]);
    const time=timeline?timeAt(event):video.currentTime;
    const q=timeline?{x:canvas.width*.1,y:canvas.height*.1}:point(event);
    const types=timeline?['A','B','C','D','F']:['A','B','C'];
    const items=types.map(type=>[titles[type]+'追加',()=>add(type,time,q)]);
    if(!timeline)items.push(['接続線追加',()=>{
        state.connecting='start';message('接続開始側をクリックしてください。Escで中止。');
    }]);
    if(timeline&&selected.size>1)items.push(['開始位置を合わせる',()=>{
        const list=elements().filter(e=>selected.has(e.id)&&e.type!=='E');
        const start=item?endpointTime(item):Math.min(...list.map(endpointTime));
        if(list.some(e=>e.type==='F'?start>=state.duration:start+e.end-e.start>state.duration)){
            message('動画終了を超えるため変更できません。');return;
        }
        list.forEach(e=>{
            if(e.type==='F')e.time=start;
            else{e.end=start+e.end-e.start;e.start=start}
        });
        state.dirty=true;tracks();paint();
    }]);
    if(item){
        if(item.type==='A')items.push(['本文編集',()=>editText(item)]);
        if(!['D','F'].includes(item.type))items.push(['書式変更',()=>format(item,event)]);
        items.push(['選択要素を削除',()=>{
            state.data.elements=elements().filter(e=>
                !selected.has(e.id)&&!selected.has(e.ownerId)&&!selected.has(e.commentId));
            selected.clear();state.dirty=true;tracks();paint();
        }]);
    }
    menu(event,items);
}
function format(e,event){
    const root=$('menu');root.replaceChildren();
    const fields=[
        ...(e.type==='A'?[
            ['文字サイズ','size',Array.from({length:31},(_,i)=>[i+10,i+10])],
            ['フォント','font',[['gothic','ゴシック'],['mincho','明朝']]]
        ]:[]),
        ['線の太さ','width',[[1,'━'],[2,'━━'],[3,'━━━━'],[5,'████'],[8,'██████']]],
        ['線種','dash',[['solid','実線'],['dash','破線'],['dot','点線'],['chain','一点鎖線']]],
        ...(e.type==='E'?[
            ['線形','line',[['straight','直線'],['orthogonal','折れ線'],['wave','1周期波線']]],
            ['終端','terminal',[['none','なし'],['arrow','矢印'],['circle','丸']]]
        ]:[
            ['図形','shape',[['rect','四角'],['ellipse','楕円'],['round','角丸四角']]]
        ]),
        ...(e.type==='C'?[['倍率','zoom',[[1,'1倍'],[2,'2倍'],[3,'3倍'],[4,'4倍'],[6,'6倍']]]]:[])
    ];
    for(const [text,key,options] of fields){
        const label=document.createElement('label'),select=document.createElement('select');
        label.textContent=text;
        options.forEach(([value,text])=>select.add(new Option(text,value)));
        select.value=e[key];
        select.onchange=()=>{
            if(key==='shape'){
                const connections=elements().filter(x=>x.type==='E'&&(x.ownerId===e.id||x.commentId===e.id));
                const positions=connections.map(x=>({
                    x,key:x.ownerId===e.id?'from':'to',
                    point:port(e,x.ownerId===e.id?x.from:x.to)
                }));
                e.shape=select.value;
                positions.forEach(p=>{p.x[p.key]=nearest(e,p.point)});
            }else e[key]=['size','width','zoom'].includes(key)?+select.value:select.value;
            state.dirty=true;paint();
        };
        label.append(select);root.append(label);
    }
    const target=document.createElement('select');target.add(new Option('線色','stroke'));
    if(e.type!=='E')target.add(new Option('背景色','background'));
    if(e.type==='A')target.add(new Option('文字色','color'));
    root.append(target);
    const palette=document.createElement('div');palette.className='palette';
    colors.forEach(color=>{
        const button=document.createElement('button');
        button.title=color==='transparent'?'塗り潰しなし':color;
        button.style.background=color==='transparent'?
            'repeating-linear-gradient(45deg,#fff 0 4px,#bbb 4px 8px)':color;
        button.onclick=()=>{e[target.value]=color;state.dirty=true;paint()};
        palette.append(button);
    });
    root.append(palette);showMenu(event);
}
function mp4Recorder(stream){
    if(!window.MediaRecorder)throw Error('このブラウザは録画に対応していません。');
    const type=['video/mp4;codecs=avc1.42E01E,mp4a.40.2','video/mp4']
        .find(t=>MediaRecorder.isTypeSupported(t));
    if(!type)throw Error('このブラウザはMP4生成に対応していません。MP4録画対応ブラウザを使用してください。');
    return new MediaRecorder(stream,{mimeType:type});
}
function collect(recorder){
    return new Promise((resolve,reject)=>{
        const parts=[];
        recorder.ondataavailable=e=>{if(e.data.size)parts.push(e.data)};
        recorder.onstop=()=>resolve(new Blob(parts,{type:recorder.mimeType}));
        recorder.onerror=e=>reject(e.error||Error('録画に失敗しました。'));
    });
}
async function audioReady(){
    if(!audio){
        audio=new AudioContext();
        audioSource=audio.createMediaElementSource(video);
        audioSink=audio.createMediaStreamDestination();
        audioSource.connect(audio.destination);
        audioSource.connect(audioSink);
    }
    await audio.resume();
}
function skip(time){
    for(let i=0;i<=elements().length;i++){
        const list=elements().filter(e=>e.type==='D'&&time>=e.start&&time<e.end);
        if(!list.length)break;
        time=Math.max(...list.map(e=>e.end));
    }
    return Math.min(time,state.duration);
}
async function renderMovie(){
    validateDuration();
    if(!canvas.captureStream)throw Error('編集動画生成に対応していません。');
    if(skip(0)>=state.duration)throw Error('全区間がスキップされています。');
    const oldTime=video.currentTime,oldMuted=video.muted,oldRate=video.playbackRate;
    const output=document.createElement('canvas');
    output.width=canvas.width;output.height=canvas.height;
    const ctx=output.getContext('2d');
    let stream,recorder,frameID,detached=false,timer;
    video.pause();
    try{
        await audioReady();
        audioSource.disconnect(audio.destination);detached=true;
        await seek(skip(0));
        const draw=()=>{
            ctx.drawImage(video,0,0,output.width,output.height);
            paint(ctx,video.currentTime,true);
        };
        draw();stream=output.captureStream(30);
        audioSink.stream.getAudioTracks().forEach(t=>stream.addTrack(t.clone()));
        recorder=mp4Recorder(stream);
        const result=collect(recorder);result.catch(()=>{});
        const finished=new Promise((resolve,reject)=>{
            timer=setTimeout(()=>reject(Error('編集動画生成がタイムアウトしました。')),
                state.duration*1000+120000);
            const loop=async()=>{
                try{
                    if(video.ended||video.currentTime>=state.duration){resolve();return}
                    const next=skip(video.currentTime);
                    if(next>video.currentTime){
                        recorder.pause();video.pause();await seek(next);
                        if(next>=state.duration){resolve();return}
                        draw();recorder.resume();await video.play();
                    }
                    if(video.readyState>=2&&!video.seeking)draw();
                    message('編集動画を生成しています… '+video.currentTime.toFixed(1)+' / '+state.duration.toFixed(1)+' 秒');
                    frameID=requestAnimationFrame(loop);
                }catch(error){reject(error)}
            };
            frameID=requestAnimationFrame(loop);
        });
        finished.catch(()=>{});
        recorder.start(250);video.muted=false;video.playbackRate=1;
        await video.play();
        await Promise.race([finished,result.then(()=>{throw Error('生成が途中で終了しました。')})]);
        video.pause();recorder.stop();
        const blob=await result;
        if(!blob.size)throw Error('編集動画が空です。');
        return blob;
    }finally{
        clearTimeout(timer);cancelAnimationFrame(frameID);video.pause();
        if(recorder&&recorder.state!=='inactive')recorder.stop();
        stream?.getTracks().forEach(t=>t.stop());
        if(detached)audioSource.connect(audio.destination);
        video.muted=oldMuted;video.playbackRate=oldRate;
        await seek(Math.min(oldTime,state.duration));
    }
}
async function saveEdit(){
    commitText();
    if(!state.current||!state.editing)return;
    if(state.pending){await retry();return}
    const blob=await renderMovie();
    message('編集データと動画を保存しています…');
    state.revision=await updateAPI('edit.save',{
        id:state.revision?.id||uid(),videoId:state.current.id,
        name:state.revision?.name||'編集 '+(state.model.edits.filter(e=>e.videoId===state.current.id).length+1),
        expectedVersion:state.revision?.version||0,editData:state.data
    },blob);
    state.dirty=false;
    await refresh();await seek(0);
    message('編集結果と編集動画を保存しました。');
}
async function storeVideo(blob,name){
    const folder=state.model.folders[0];
    if(!folder)throw Error('先にフォルダを作成してください。');
    const id=uid();
    await updateAPI('video.upload',{id,folderId:folder.id,name},blob);
    await refresh();
    return state.model.videos.find(v=>v.id===id);
}
async function record(){
    if(!leave())return;
    if(!navigator.mediaDevices?.getDisplayMedia)
        throw Error('画面録画にはHTTPSまたはlocalhostが必要です。');
    let stream,recorder;
    try{
        stream=await navigator.mediaDevices.getDisplayMedia({video:true,audio:true});
        recorder=mp4Recorder(stream);
        const result=collect(recorder);result.catch(()=>{});
        clear();state.screen=stream;state.writer=recorder;
        video.srcObject=stream;video.muted=true;await video.play();
        stream.getVideoTracks()[0].onended=()=>{
            if(recorder.state!=='inactive')recorder.stop();
        };
        recorder.start(500);
        state.busy=false;update();
        message('録画中。システム音声は画面共有ダイアログで選択します。');
        const blob=await result;
        state.busy=true;update();
        stream.getTracks().forEach(t=>t.stop());
        state.screen=null;state.writer=null;
        video.srcObject=null;video.muted=false;
        message('録画を保存しています…');
        const item=await storeVideo(blob,'録画 '+new Date().toLocaleString('ja-JP'));
        await openVideo(item);
        message('録画を保存しました。編集開始から編集できます。');
    }finally{
        stream?.getTracks().forEach(t=>t.stop());
        state.screen=null;state.writer=null;
        video.srcObject=null;video.muted=false;
    }
}
async function downloadCurrent(){
    if(state.editing&&(state.dirty||!state.revision))await saveEdit();
    const id=state.revision?state.revision.renderedFileId:state.current.originalFileId;
    location.href=endpoint('file.download',id);
}
function openPDF(){
    commitText();
    if(!elements().some(e=>e.type==='F')){
        message('タイムラインを右クリックしてPDF位置を追加してください。');return;
    }
    $('pdfDialog').showModal();
}
async function printPDF(){
    $('pdfDialog').close();commitText();video.pause();
    const oldTime=video.currentTime;
    const restoreId=state.rendered?state.revision.renderedFileId:state.current.originalFileId;
    const count=+document.querySelector('[name=layout]:checked').value;
    const times=elements().filter(e=>e.type==='F').map(e=>e.time).sort((a,b)=>a-b);
    $('printArea').replaceChildren();
    try{
        await setSource(state.current.originalFileId);validateDuration();
        let sheet;
        for(let i=0;i<times.length;i++){
            await seek(times[i]);
            const output=document.createElement('canvas');
            output.width=canvas.width;output.height=canvas.height;
            const ctx=output.getContext('2d');
            ctx.drawImage(video,0,0,output.width,output.height);
            paint(ctx,video.currentTime,true);
            if(i%count===0){
                sheet=document.createElement('div');sheet.className='sheet';
                $('printArea').append(sheet);
            }
            const shot=document.createElement('div'),image=new Image(),label=document.createElement('p');
            shot.className='shot';image.src=output.toDataURL('image/png');
            image.style.maxHeight=count===2?'115mm':'245mm';
            await image.decode();
            label.textContent=state.current.name+' ／ '+times[i].toFixed(1)+' 秒';
            shot.append(image,label);sheet.append(shot);
        }
        window.print();
    }finally{
        await setSource(restoreId);
        await seek(Math.min(oldTime,state.duration));paint();
    }
}
async function removeEntity(type,item){
    if(!leave())return;
    const warning=type==='edit'?'この編集を削除しますか？':
        '配下の動画と編集も削除されます。削除しますか？';
    if(!confirm(warning))return;
    await updateAPI(type+'.delete',{id:item.id});
    await refresh();
    if(state.current&&!state.model.videos.some(v=>v.id===state.current.id))clear();
    else if(state.revision&&!state.model.edits.some(e=>e.id===state.revision.id)){
        state.revision=null;state.data=null;state.editing=false;state.dirty=false;state.rendered=false;
        await setSource(state.current.originalFileId);
    }
    message('削除しました。');
}
function tree(){
    if(!state.model)return;
    const root=$('tree');root.replaceChildren();
    function row(type,item,depth,open,branch=false,displayName=null){
        const node=document.createElement('div'),toggle=document.createElement('button');
        const name=document.createElement('span'),time=document.createElement('time');
        node.className='row';node.style.marginLeft=depth*16+'px';
        node.dataset.entityType=type;node.dataset.entityId=item.id;
        toggle.textContent=branch?(collapsed.has(item.id)?'▸':'▾'):'·';
        toggle.setAttribute('aria-label',branch?'開閉':'開く');
        name.className='name';name.textContent=displayName??item.name;
        time.textContent=new Date(item.updatedAt).toLocaleString('ja-JP');
        node.append(toggle,name,time);root.append(node);
        toggle.onclick=()=>{
            if(locked())return;
            if(branch){
                collapsed.has(item.id)?collapsed.delete(item.id):collapsed.add(item.id);
                tree();
            }else if(open)job(open);
        };
        let timer;
        name.onclick=event=>{
            clearTimeout(timer);
            if(event.detail===1&&open)
                timer=setTimeout(()=>{if(!locked()&&!name.isContentEditable)job(open)},300);
        };
        name.ondblclick=()=>{
            clearTimeout(timer);
            if(!locked()&&!displayName){name.contentEditable='true';name.focus()}
        };
        name.onblur=()=>{
            if(!name.isContentEditable)return;
            name.contentEditable='false';
            const value=name.textContent.trim()||item.name;
            job(async()=>{
                await updateAPI(type+'.rename',{id:item.id,name:value});
                await refresh();
                if(state.current?.id===item.id)state.current.name=value;
                if(state.revision?.id===item.id)state.revision.name=value;
                message('名前を変更しました。');
            });
        };
        name.onkeydown=event=>{
            if(event.key==='Enter'){event.preventDefault();name.blur()}
        };
        return node;
    }
    function outputs(item,edit=null){
        return[
            ...(edit?[
                ['編集する',()=>job(()=>openVideo(item,edit,true))],
                ['保存済み編集動画を再生',()=>job(async()=>{
                    await openVideo(item,edit);
                    if(state.current?.id===item.id&&state.revision?.id===edit.id){
                        if(audio)await audio.resume();
                        await video.play();
                    }
                })]
            ]:[
                ['元動画を開く',()=>job(()=>openVideo(item))],
                ['元動画を再生',()=>job(async()=>{
                    await openVideo(item);
                    if(state.current?.id===item.id&&!state.revision){
                        if(audio)await audio.resume();
                        await video.play();
                    }
                })]
            ]),
            ['ダウンロード',()=>{
                location.href=endpoint('file.download',edit?edit.renderedFileId:item.originalFileId);
            }],
            ['PDF出力',()=>job(async()=>{
                if(!edit){message('PDF位置を設定した編集を選択してください。');return}
                await openVideo(item,edit);
                if(state.revision?.id===edit.id)openPDF();
            })]
        ];
    }
    function visit(f,depth,path){
        if(path.has(f.id))return;
        const next=new Set(path);next.add(f.id);
        const node=row('folder',f,depth,null,true);
        node.oncontextmenu=event=>menu(event,[
            ['子フォルダ追加',()=>job(async()=>{
                await updateAPI('folder.create',{id:uid(),parentFolderId:f.id,name:'新しいフォルダ'});
                collapsed.delete(f.id);await refresh();
            })],
            ['フォルダ削除',()=>job(()=>removeEntity('folder',f))]
        ]);
        node.ondragover=event=>{
            if(locked()||!event.dataTransfer.types.includes('application/x-video-id'))return;
            event.preventDefault();node.classList.add('drop');
        };
        node.ondragleave=()=>node.classList.remove('drop');
        node.ondrop=event=>{
            event.preventDefault();node.classList.remove('drop');
            if(locked())return;
            const id=event.dataTransfer.getData('application/x-video-id');
            const v=state.model.videos.find(v=>v.id===id);
            if(!v||v.folderId===f.id)return;
            job(async()=>{
                await updateAPI('video.move',{id,folderId:f.id});
                collapsed.delete(f.id);await refresh();message('動画を移動しました。');
            });
        };
        if(collapsed.has(f.id))return;
        state.model.folders.filter(x=>x.parentFolderId===f.id).forEach(x=>visit(x,depth+1,next));
        state.model.videos.filter(v=>v.folderId===f.id).forEach(item=>{
            const group=row('video',item,depth+1,()=>openVideo(item),true);
            group.draggable=true;
            group.ondragstart=event=>{
                if(locked()||event.target.isContentEditable)event.preventDefault();
                else{
                    event.dataTransfer.setData('application/x-video-id',item.id);
                    event.dataTransfer.effectAllowed='move';
                }
            };
            group.oncontextmenu=event=>menu(event,[
                ...outputs(item),['動画削除',()=>job(()=>removeEntity('video',item))]
            ]);
            if(collapsed.has(item.id))return;
            const original=row('video',item,depth+2,()=>openVideo(item),false,'元動画');
            original.oncontextmenu=event=>menu(event,[
                ...outputs(item),['動画削除',()=>job(()=>removeEntity('video',item))]
            ]);
            state.model.edits.filter(e=>e.videoId===item.id).forEach(edit=>{
                const er=row('edit',edit,depth+2,()=>openVideo(item,edit));
                er.oncontextmenu=event=>menu(event,[
                    ...outputs(item,edit),['編集削除',()=>job(()=>removeEntity('edit',edit))]
                ]);
            });
        });
    }
    state.model.folders.filter(f=>f.parentFolderId===null).forEach(f=>visit(f,0,new Set()));
}

$('list').onclick=()=>job(async()=>{await refresh();library(true)});
$('closeList').onclick=$('veil').onclick=()=>library(false);
$('upload').onclick=$('upload2').onclick=()=>$('file').click();
$('file').onchange=event=>{
    const file=event.target.files[0];event.target.value='';
    if(!file||!leave())return;
    job(async()=>{
        message('動画を保存しています…');
        const item=await storeVideo(file,file.name.replace(/\.[^.]+$/,'')||file.name);
        await openVideo(item);
    });
};
$('new').onclick=()=>{
    if(locked()||!leave())return;
    clear();library(false);message('録画開始を押してください。');
};
$('folder').onclick=()=>job(async()=>{
    await updateAPI('folder.create',{id:uid(),parentFolderId:null,name:'新しいフォルダ'});
    await refresh();
});
$('primary').onclick=()=>{
    if(state.writer){if(state.writer.state!=='inactive')state.writer.stop();return}
    job(async()=>{
        if(!state.editing){await record();return}
        commitText();
        if(video.paused){
            if(audio)await audio.resume();
            if(video.ended)await seek(0);
            video.currentTime=skip(video.currentTime);
            await video.play();
        }else video.pause();
    });
};
$('begin').onclick=()=>job(async()=>{
    commitText();
    const edit=state.revision?await fetchJSON(endpoint('edit.get',state.revision.id)):null;
    await setSource(state.current.originalFileId);
    state.revision=edit;state.rendered=false;
    state.data=edit?copy(edit.editData):{
        schemaVersion:1,coordinateSystem:'source-pixels',
        sourceWidth:video.videoWidth
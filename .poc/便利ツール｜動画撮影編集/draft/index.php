<?php
declare(strict_types=1);

/*
 * 動画撮影編集 POC
 * PHP 8.1以上 / Apache 2.4 / HTTPSまたはlocalhost
 *
 * 保存:
 *   storage/metadata.json
 *   storage/requests.json
 *   storage/deletion-retry.json
 *
 * 補助:
 *   storage/.lock
 *   storage/transaction.json
 *
 * transaction.jsonによるロールフォワード復旧を採用。
 * ブラウザ内MP4変換: @ffmpeg/ffmpeg 0.11.6
 */

const ROOT = __DIR__ . '/storage';

header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Embedder-Policy: require-corp');
header('X-Content-Type-Options: nosniff');

function fail(string $message, int $status = 400, string $code = 'INVALID_INPUT'): never {
    throw new RuntimeException($code . "\n" . $message, $status);
}

function uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 15) | 64);
    $b[8] = chr((ord($b[8]) & 63) | 128);
    $h = bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
           substr($h,16,4).'-'.substr($h,20);
}

function nowUTC(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
}

function jsonText(mixed $data): string {
    return json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
}

function readJSON(string $name): array {
    $text = file_get_contents(ROOT.'/'.$name);
    if ($text === false) fail('管理データを読み込めません。',500,'STORAGE_ERROR');
    try {
        $data = json_decode($text,true,512,JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        fail('管理データが破損しています。初期化せず停止しました。',500,'STORAGE_ERROR');
    }
    if (!is_array($data)) fail('管理データが不正です。',500,'STORAGE_ERROR');
    return $data;
}

function writeJSON(string $name, array $data): void {
    $path = tempnam(ROOT.'/tmp','json-');
    if ($path === false) fail('一時ファイルを作成できません。',500,'SAVE_FAILED');
    try {
        $fp = fopen($path,'wb');
        if (!$fp) fail('一時ファイルを開けません。',500,'SAVE_FAILED');
        try {
            $text = jsonText($data);
            $offset = 0;
            while ($offset < strlen($text)) {
                $written = fwrite($fp,substr($text,$offset));
                if ($written === false || $written === 0)
                    fail('管理データを書き込めません。',500,'SAVE_FAILED');
                $offset += $written;
            }
            if (!fflush($fp)) fail('書き込みを確定できません。',500,'SAVE_FAILED');
            if (function_exists('fsync') && !fsync($fp))
                fail('書き込みを同期できません。',500,'SAVE_FAILED');
        } finally {
            fclose($fp);
        }
        if (!rename($path,ROOT.'/'.$name))
            fail('管理データを置き換えられません。',500,'SAVE_FAILED');
    } finally {
        if (is_file($path)) @unlink($path);
    }
}

function validID(mixed $value): string {
    if (!is_string($value) ||
        !preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i',$value))
        fail('UUIDが不正です。');
    return $value;
}

function validName(mixed $value): string {
    if (!is_string($value) || trim($value)==='' || strlen($value)>600)
        fail('名前を入力してください。');
    return trim($value);
}

function indexOf(array $rows, string $id): int {
    foreach ($rows as $i=>$row) {
        if (($row['id']??null)===$id) return $i;
    }
    fail('対象がありません。',404,'NOT_FOUND');
}

function pathOf(array $file): string {
    $path = $file['storagePath']??'';
    if (!is_string($path) ||
        !preg_match('~^files/[0-9a-f-]{36}\.(mp4|webm|mov)$~i',$path))
        fail('保存ファイル情報が不正です。',500,'STORAGE_ERROR');
    return ROOT.'/'.$path;
}

function numberValue(mixed $value, string $label): float {
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value))
        fail($label.'は数値で指定してください。');
    return (float)$value;
}

function colorValue(mixed $value): void {
    if (!is_string($value) ||
        !preg_match('/^(#[0-9a-f]{6}|transparent)$/i',$value))
        fail('色が不正です。');
}

function validatePort(mixed $port, array $target): void {
    if (!is_array($port)) fail('接続位置が不正です。');
    if ($target['shape']==='ellipse') {
        numberValue($port['angle']??null,'角度');
    } else {
        if (!in_array($port['side']??null,['left','right','top','bottom'],true))
            fail('接続辺が不正です。');
        $ratio = numberValue($port['ratio']??null,'接続比率');
        if ($ratio<0 || $ratio>1) fail('接続比率が範囲外です。');
    }
}

function validateEdit(mixed $data, float $duration): array {
    if (!is_array($data) || ($data['schemaVersion']??null)!==1 ||
        ($data['coordinateSystem']??null)!=='source-pixels' ||
        !is_array($data['elements']??null) || !array_is_list($data['elements']))
        fail('編集データ形式が不正です。');

    $sw = numberValue($data['sourceWidth']??null,'元動画幅');
    $sh = numberValue($data['sourceHeight']??null,'元動画高さ');
    if ($sw<=0 || $sh<=0 || $sw>32768 || $sh>32768)
        fail('元動画の解像度が不正です。');

    $map = [];
    foreach ($data['elements'] as $e) {
        if (!is_array($e)) fail('編集要素が不正です。');
        $id = validID($e['id']??null);
        if (isset($map[$id])) fail('編集要素IDが重複しています。');
        if (!in_array($e['type']??null,['A','B','C','D','E','F'],true))
            fail('編集要素の種類が不正です。');
        $map[$id] = $e;
    }

    foreach ($data['elements'] as $e) {
        $type = $e['type'];
        if ($type==='F') {
            $time = numberValue($e['time']??null,'PDF位置');
            if ($time<0 || $time>=$duration) fail('PDF位置が動画の範囲外です。');
            continue;
        }
        if ($type!=='E') {
            $start = numberValue($e['start']??null,'開始時刻');
            $end = numberValue($e['end']??null,'終了時刻');
            if ($start<0 || $start>=$end || $end>$duration+0.001)
                fail('表示区間が動画の範囲外です。');
        }
        if ($type==='D') continue;

        $width = numberValue($e['width']??null,'線幅');
        if ($width<0 || $width>100) fail('線幅が範囲外です。');
        colorValue($e['stroke']??null);
        if (!in_array($e['dash']??null,['solid','dash','dot','chain'],true))
            fail('線種が不正です。');

        if ($type==='E') {
            $owner = $map[validID($e['ownerId']??null)]??null;
            $comment = $map[validID($e['commentId']??null)]??null;
            if (!$owner || !$comment || !in_array($owner['type'],['B','C'],true) ||
                $comment['type']!=='A') fail('接続先が不正です。');
            validatePort($e['from']??null,$owner);
            validatePort($e['to']??null,$comment);
            if (!in_array($e['line']??null,['straight','orthogonal','wave'],true) ||
                !in_array($e['terminal']??null,['none','arrow','circle'],true))
                fail('接続線書式が不正です。');
            continue;
        }

        foreach (['x','y','w','h'] as $key)
            numberValue($e[$key]??null,$key);
        if ($e['x']<0 || $e['y']<0 || $e['w']<=0 || $e['h']<=0 ||
            $e['x']+$e['w']>$sw+0.001 || $e['y']+$e['h']>$sh+0.001)
            fail('編集領域が元動画の範囲外です。');
        if (!in_array($e['shape']??null,['rect','ellipse','round'],true))
            fail('図形が不正です。');
        colorValue($e['background']??null);

        if ($type==='A') {
            $size = numberValue($e['size']??null,'文字サイズ');
            if (!is_string($e['text']??null) || strlen($e['text'])>100000 ||
                $size<10 || $size>40 ||
                !in_array($e['font']??null,['gothic','mincho'],true))
                fail('コメント書式が不正です。');
            colorValue($e['color']??null);
        }
        if ($type==='C') {
            $zoom = numberValue($e['zoom']??null,'拡大倍率');
            if ($zoom<1 || $zoom>16) fail('拡大倍率が範囲外です。');
        }
    }
    return $data;
}

function receiveFile(): array {
    if (!class_exists('finfo'))
        fail('PHPのfileinfo拡張を有効にしてください。',500,'SERVER_CONFIGURATION');
    $upload = $_FILES['file']??null;
    if (!$upload || ($upload['error']??-1)!==UPLOAD_ERR_OK ||
        !is_uploaded_file($upload['tmp_name']))
        fail('動画を受信できません。PHPのアップロード容量設定を確認してください。',
             400,'UPLOAD_FAILED');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $ext = match ($mime) {
        'video/mp4','application/mp4'=>'mp4',
        'video/webm'=>'webm',
        'video/quicktime'=>'mov',
        default=>null
    };
    if (!$ext) fail('MP4、WebM、MOVを指定してください。');
    $id = uuid();
    $tmp = ROOT.'/tmp/'.$id;
    if (!move_uploaded_file($upload['tmp_name'],$tmp))
        fail('動画を一時保存できません。',500,'SAVE_FAILED');
    $size = filesize($tmp);
    if ($size===false || $size<=0) {
        @unlink($tmp);
        fail('動画ファイルが空です。');
    }
    $file = [
        'id'=>$id,'storagePath'=>"files/$id.$ext",
        'mimeType'=>$mime,'size'=>$size,'createdAt'=>nowUTC()
    ];
    if (!rename($tmp,pathOf($file))) {
        @unlink($tmp);
        fail('動画を保存できません。',500,'SAVE_FAILED');
    }
    return $file;
}

function recoverTransaction(): void {
    if (!is_file(ROOT.'/transaction.json')) return;
    $tx = readJSON('transaction.json');
    foreach (['metadata','requests','deletions'] as $key)
        if (!isset($tx[$key]) || !is_array($tx[$key]))
            fail('復旧情報が不正です。',500,'STORAGE_ERROR');
    foreach ($tx['metadata']['files']??[] as $f)
        if (!is_file(pathOf($f)))
            fail('復旧対象の動画がありません。',500,'STORAGE_ERROR');
    writeJSON('metadata.json',$tx['metadata']);
    writeJSON('requests.json',$tx['requests']);
    writeJSON('deletion-retry.json',$tx['deletions']);
    if (!unlink(ROOT.'/transaction.json'))
        fail('復旧情報を終了できません。',500,'SAVE_FAILED');
}

function maintenance(array $metadata): void {
    $pending = [];
    foreach (readJSON('deletion-retry.json') as $file) {
        $path = pathOf($file);
        if (is_file($path) && !@unlink($path)) $pending[$file['id']]=$file;
    }
    writeJSON('deletion-retry.json',array_values($pending));

    $referenced = [];
    foreach ($metadata['files'] as $file) $referenced[pathOf($file)]=true;
    foreach (glob(ROOT.'/files/*')?:[] as $path) {
        if (is_file($path) && !isset($referenced[$path])) {
            // 共通排他中なので、現在進行中のアップロードは存在しない。
            if (!@unlink($path)) {
                $name = basename($path);
                if (preg_match('/^([0-9a-f-]{36})\.(mp4|webm|mov)$/i',$name,$m)) {
                    $pending[$m[1]]=[
                        'id'=>$m[1],'storagePath'=>'files/'.$name,
                        'mimeType'=>'application/octet-stream',
                        'size'=>filesize($path),'createdAt'=>nowUTC()
                    ];
                }
            }
        }
    }
    writeJSON('deletion-retry.json',array_values($pending));
    foreach (glob(ROOT.'/tmp/*')?:[] as $path)
        if (is_file($path)) @unlink($path);
}

function success(array $data, ?string $requestId=null): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo jsonText(['ok'=>true,'requestId'=>$requestId,'data'=>$data]);
    exit;
}

$lock = null;
$requestId = null;
$newFile = null;
$journalWritten = false;

try {
    foreach ([ROOT,ROOT.'/files',ROOT.'/tmp'] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir))
            fail('保存領域を作成できません。',500,'STORAGE_ERROR');
        if (!is_readable($dir) || !is_writable($dir))
            fail('保存領域に読み書き権限がありません。',500,'STORAGE_ERROR');
    }

    $deny = "Require all denied\n";
    if (!is_file(ROOT.'/.htaccess') &&
        file_put_contents(ROOT.'/.htaccess',$deny)!==strlen($deny))
        fail('アクセス制御を作成できません。',500,'STORAGE_ERROR');
    if (trim((string)file_get_contents(ROOT.'/.htaccess'))!==trim($deny))
        fail('storage/.htaccessを確認してください。',500,'STORAGE_ERROR');

    $lock = fopen(ROOT.'/.lock','c');
    if (!$lock || !flock($lock,LOCK_EX))
        fail('保存領域をロックできません。',500,'STORAGE_ERROR');

    recoverTransaction();

    if (!is_file(ROOT.'/metadata.json')) {
        $t = nowUTC();
        writeJSON('metadata.json',[
            'schemaVersion'=>1,
            'folders'=>[[
                'id'=>uuid(),'parentFolderId'=>null,'name'=>'デフォルト',
                'createdAt'=>$t,'updatedAt'=>$t
            ]],
            'videos'=>[],'edits'=>[],'files'=>[]
        ]);
    }
    foreach (['requests.json','deletion-retry.json'] as $name)
        if (!is_file(ROOT.'/'.$name)) writeJSON($name,[]);

    $metadata = readJSON('metadata.json');
    if (($metadata['schemaVersion']??null)!==1)
        fail('未対応の保存形式です。既存データは変更しません。',
             409,'SCHEMA_UNSUPPORTED');
    foreach (['folders','videos','edits','files'] as $key)
        if (!isset($metadata[$key]) || !is_array($metadata[$key]) ||
            !array_is_list($metadata[$key]))
            fail('管理データの構造が不正です。',500,'STORAGE_ERROR');

    if (isset($_GET['api'])) {
        if ($_GET['api']!=='v1') fail('APIがありません。',404,'NOT_FOUND');
        $action = (string)($_GET['action']??'');
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method==='GET') {
            if ($action==='list') {
                $result = $metadata;
                foreach ($result['files'] as &$file) unset($file['storagePath']);
                unset($file);
                success($result);
            }
            $id = validID($_GET['id']??null);
            if ($action==='edit.get')
                success($metadata['edits'][indexOf($metadata['edits'],$id)]);
            if (!in_array($action,['file.stream','file.download'],true))
                fail('操作がありません。',404,'NOT_FOUND');

            $file = $metadata['files'][indexOf($metadata['files'],$id)];
            $path = pathOf($file);
            $fp = @fopen($path,'rb');
            if (!$fp) fail('動画がありません。',404,'NOT_FOUND');
            $stat = fstat($fp);
            $size = (int)$stat['size'];
            if ($size<=0) fail('動画が空です。',500,'STORAGE_ERROR');
            $start=0;
            $end=$size-1;

            if (isset($_SERVER['HTTP_RANGE'])) {
                $range=$_SERVER['HTTP_RANGE'];
                if (!preg_match('/^bytes=(\d*)-(\d*)$/',$range,$r) ||
                    ($r[1]==='' && $r[2]==='') ||
                    ($r[1]==='' && (int)$r[2]===0)) {
                    header("Content-Range: bytes */$size");
                    fail('Rangeが不正です。',416,'INVALID_RANGE');
                }
                if ($r[1]==='') $start=max(0,$size-(int)$r[2]);
                else {
                    $start=(int)$r[1];
                    if ($r[2]!=='') $end=min($end,(int)$r[2]);
                }
                if ($start>$end || $start>=$size) {
                    header("Content-Range: bytes */$size");
                    fail('Rangeが範囲外です。',416,'INVALID_RANGE');
                }
                http_response_code(206);
                header("Content-Range: bytes $start-$end/$size");
            }

            header('Content-Type: '.$file['mimeType']);
            header('Accept-Ranges: bytes');
            header('Content-Length: '.($end-$start+1));
            if ($action==='file.download') {
                $name='動画';
                foreach ($metadata['videos'] as $v)
                    if ($v['originalFileId']===$id) $name=$v['name'];
                foreach ($metadata['edits'] as $e)
                    if ($e['renderedFileId']===$id) $name=$e['name'];
                $extension=pathinfo($path,PATHINFO_EXTENSION);
                header('Content-Disposition: attachment; filename="video.'.$extension.
                       '"; filename*=UTF-8\'\''.rawurlencode($name.'.'.$extension));
            }
            flock($lock,LOCK_UN);
            fclose($lock);
            $lock=null;
            session_write_close();
            @set_time_limit(0);
            fseek($fp,$start);
            $left=$end-$start+1;
            while ($left>0 && !feof($fp) && !connection_aborted()) {
                $chunk=fread($fp,min(1048576,$left));
                if ($chunk===false || $chunk==='') break;
                echo $chunk;
                $left-=strlen($chunk);
            }
            fclose($fp);
            exit;
        }

        if ($method!=='POST')
            fail('POSTを使用してください。',405,'METHOD_NOT_ALLOWED');

        maintenance($metadata);

        try {
            $request=json_decode(
                (string)($_POST['metadata']??file_get_contents('php://input')),
                true,512,JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            fail('要求JSONが不正です。');
        }
        if (!is_array($request)) fail('要求が不正です。');
        $requestId=validID($request['requestId']??null);
        $payload=$request['payload']??null;
        if (!is_array($payload)) fail('payloadが不正です。');

        $requests=readJSON('requests.json');
        $uploadHash=null;
        if (isset($_FILES['file']['tmp_name']) &&
            is_uploaded_file($_FILES['file']['tmp_name']))
            $uploadHash=hash_file('sha256',$_FILES['file']['tmp_name']);
        $hash=hash('sha256',jsonText([$action,$payload,$uploadHash]));

        if (isset($requests[$requestId])) {
            if ($requests[$requestId]['hash']!==$hash)
                fail('同じ要求IDの内容が異なります。',409,'REQUEST_CONFLICT');
            success($requests[$requestId]['data'],$requestId);
        }

        $id=validID($payload['id']??null);
        $t=nowUTC();
        $data=['id'=>$id];
        $removed=[];
        $m=$metadata;

        if ($action==='folder.create') {
            foreach ($m['folders'] as $f)
                if ($f['id']===$id) fail('IDが存在します。',409,'CONFLICT');
            if (!array_key_exists('parentFolderId',$payload))
                fail('親フォルダ指定がありません。');
            $parent=$payload['parentFolderId'];
            if ($parent!==null) indexOf($m['folders'],validID($parent));
            $m['folders'][]=[
                'id'=>$id,'parentFolderId'=>$parent,
                'name'=>validName($payload['name']??null),
                'createdAt'=>$t,'updatedAt'=>$t
            ];
        } elseif (in_array($action,['folder.rename','video.rename','edit.rename'],true)) {
            $key=explode('.',$action)[0].'s';
            $i=indexOf($m[$key],$id);
            $m[$key][$i]['name']=validName($payload['name']??null);
            $m[$key][$i]['updatedAt']=$t;
        } elseif ($action==='video.move') {
            $i=indexOf($m['videos'],$id);
            $folderId=validID($payload['folderId']??null);
            indexOf($m['folders'],$folderId);
            $m['videos'][$i]['folderId']=$folderId;
            $m['videos'][$i]['updatedAt']=$t;
        } elseif ($action==='video.upload') {
            foreach ($m['videos'] as $v)
                if ($v['id']===$id) fail('IDが存在します。',409,'CONFLICT');
            $folderId=validID($payload['folderId']??null);
            indexOf($m['folders'],$folderId);
            $name=validName($payload['name']??null);
            $newFile=receiveFile();
            $m['files'][]=$newFile;
            $m['videos'][]=[
                'id'=>$id,'folderId'=>$folderId,'name'=>$name,
                'originalFileId'=>$newFile['id'],
                'createdAt'=>$t,'updatedAt'=>$t
            ];
        } elseif ($action==='edit.save') {
            $videoId=validID($payload['videoId']??null);
            indexOf($m['videos'],$videoId);
            $name=validName($payload['name']??null);

            /*
             * durationは固定payloadへ追加せずmultipartの検証補助値で受け取る。
             * FFmpegがないためサーバーで動画尺を独立検証できない。
             */
            $duration=filter_var($_POST['sourceDuration']??null,FILTER_VALIDATE_FLOAT);
            if ($duration===false || $duration===null ||
                !is_finite((float)$duration) || $duration<=0)
                fail('元動画の長さが不正です。');
            $editData=validateEdit($payload['editData']??null,(float)$duration);

            $i=null;
            foreach ($m['edits'] as $k=>$e)
                if ($e['id']===$id) $i=$k;
            $version=$i===null?0:$m['edits'][$i]['version'];
            if (!is_int($payload['expectedVersion']??null) ||
                $payload['expectedVersion']!==$version ||
                ($i!==null && $m['edits'][$i]['videoId']!==$videoId))
                fail('編集版が競合しています。編集を再取得してください。',
                     409,'VERSION_CONFLICT');

            $newFile=receiveFile();
            $m['files'][]=$newFile;
            $data=[
                'id'=>$id,'videoId'=>$videoId,'name'=>$name,
                'version'=>$version+1,'editData'=>$editData,
                'renderedFileId'=>$newFile['id'],
                'createdAt'=>$i===null?$t:$m['edits'][$i]['createdAt'],
                'updatedAt'=>$t
            ];
            if ($i===null) $m['edits'][]=$data;
            else {
                $removed[]=$m['edits'][$i]['renderedFileId'];
                $m['edits'][$i]=$data;
            }
        } elseif (in_array($action,['folder.delete','video.delete','edit.delete'],true)) {
            $folders=[];
            $videos=[];
            $edits=[];
            if ($action==='folder.delete') {
                indexOf($m['folders'],$id);
                $folders=[$id];
                do {
                    $count=count($folders);
                    foreach ($m['folders'] as $f)
                        if (in_array($f['parentFolderId'],$folders,true) &&
                            !in_array($f['id'],$folders,true))
                            $folders[]=$f['id'];
                } while (count($folders)!==$count);
                foreach ($m['videos'] as $v)
                    if (in_array($v['folderId'],$folders,true)) $videos[]=$v['id'];
            } elseif ($action==='video.delete') {
                indexOf($m['videos'],$id);
                $videos=[$id];
            } else {
                indexOf($m['edits'],$id);
                $edits=[$id];
            }
            foreach ($m['videos'] as $v)
                if (in_array($v['id'],$videos,true)) $removed[]=$v['originalFileId'];
            foreach ($m['edits'] as $e)
                if (in_array($e['videoId'],$videos,true) ||
                    in_array($e['id'],$edits,true)) {
                    $edits[]=$e['id'];
                    $removed[]=$e['renderedFileId'];
                }
            foreach (['folders'=>$folders,'videos'=>$videos,'edits'=>$edits] as $key=>$ids)
                $m[$key]=array_values(array_filter(
                    $m[$key],fn($row)=>!in_array($row['id'],$ids,true)
                ));
        } else {
            fail('操作がありません。',404,'NOT_FOUND');
        }

        $deletions=readJSON('deletion-retry.json');
        foreach ($m['files'] as $f)
            if (in_array($f['id'],$removed,true)) $deletions[]=$f;
        $m['files']=array_values(array_filter(
            $m['files'],fn($f)=>!in_array($f['id'],$removed,true)
        ));
        $requests[$requestId]=['hash'=>$hash,'data'=>$data];

        writeJSON('transaction.json',[
            'metadata'=>$m,'requests'=>$requests,'deletions'=>$deletions
        ]);
        $journalWritten=true;
        recoverTransaction();
        $journalWritten=false;
        $newFile=null;
        success($data,$requestId);
    }

    maintenance($metadata);
    flock($lock,LOCK_UN);
    fclose($lock);
    $lock=null;
} catch (Throwable $e) {
    if ($newFile && !$journalWritten && !is_file(ROOT.'/transaction.json'))
        @unlink(pathOf($newFile));
    if (is_resource($lock)) {
        flock($lock,LOCK_UN);
        fclose($lock);
    }
    error_log((string)$e);
    $parts=explode("\n",$e->getMessage(),2);
    $status=($e instanceof RuntimeException && $e->getCode()>=400 &&
             $e->getCode()<=599)?$e->getCode():500;
    $code=count($parts)===2?$parts[0]:'SERVER_ERROR';
    $message=count($parts)===2?$parts[1]:
        'サーバー処理に失敗しました。PHPのエラーログを確認してください。';
    http_response_code($status);
    if (isset($_GET['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo jsonText([
            'ok'=>false,'requestId'=>$requestId,
            'error'=>['code'=>$code,'message'=>$message]
        ]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>起動エラー</h1><p>'.
            htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';
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
<title>動画撮影編集</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef2f7;color:#243247;font:12px Meiryo,sans-serif}
button,input,select{font:inherit}
button{height:26px;padding:0 9px;border:1px solid #ccd6e3;border-radius:4px;background:#fff;cursor:pointer;white-space:nowrap}
button:hover{background:#edf4ff}
button:disabled{opacity:.45;cursor:not-allowed}
header,.toolbar{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
header{padding:8px 12px;background:#fff;border-bottom:1px solid #ccd6e3}
header strong{margin-right:auto;font-size:14px}
main{height:calc(100dvh - 44px);min-height:450px;padding:8px;display:flex;flex-direction:column}
#stage{height:55%;flex-shrink:0;min-height:100px;background:#172235;overflow:auto;display:grid;place-items:center}
#empty{color:#ccd8e8;line-height:2;text-align:center;padding:15px}
#surface{position:relative;flex-shrink:0}
#video{display:block;width:100%;height:100%}
#overlay{position:absolute;inset:0;width:100%;height:100%;touch-action:none}
#actions{justify-content:center;padding:7px 0}
#primary{background:#2563eb;color:#fff}
#status{min-height:34px;max-height:70px;overflow:auto;padding:4px 8px;white-space:pre-wrap;color:#475569}
#status.error{color:#b91c1c;background:#fff0f0}
#split{height:8px;flex-shrink:0;background:#c2ccd8;cursor:ns-resize;touch-action:none}
#timeline{flex:1;min-height:100px;background:#fff;border:1px solid #ccd6e3;display:flex;flex-direction:column;overflow:hidden}
#timeline .toolbar{padding:6px 9px;border-bottom:1px solid #ccd6e3}
#scroll{flex:1;overflow-y:auto;overflow-x:hidden}
#tracks{position:relative;min-height:140px;touch-action:none;overflow:hidden}
.tick{position:absolute;top:0;height:22px;border-left:1px solid #dde4ed;font-size:10px;color:#64748b}
.clip{position:absolute;height:24px;padding:3px 7px;border:1px solid #91b5e6;background:#e0ecff;border-radius:3px;overflow:hidden;white-space:nowrap;cursor:move;user-select:none}
.clip.B{background:#fff1bd}.clip.C{background:#d9f3e6}.clip.D{background:#ffe2e6}.clip.F{background:#eee2ff}
.clip.selected{outline:2px solid #2563eb;outline-offset:-2px}
.edge{position:absolute;top:0;bottom:0;width:6px;background:#0002;cursor:ew-resize}
.edge.start{left:0}.edge.end{right:0}
#playhead{position:absolute;top:0;bottom:0;width:2px;background:#e44972;pointer-events:none;z-index:2}
#handle{position:absolute;left:-6px;width:14px;height:20px;background:#e44972;clip-path:polygon(0 0,100% 0,100% 65%,50% 100%,0 65%);pointer-events:auto;cursor:ew-resize}
#veil{position:fixed;inset:0;background:#0005;z-index:10}
#library{position:fixed;inset:0 auto 0 0;width:min(570px,100vw);background:#fff;z-index:11;display:flex;flex-direction:column;box-shadow:4px 0 20px #0003}
#library .toolbar{padding:8px;border-bottom:1px solid #ccd6e3}
#tree{flex:1;overflow:auto;padding:8px}
.row{display:flex;align-items:center;gap:5px;min-height:29px;padding:3px}
.row:hover{background:#edf4ff}
.row.drop{outline:2px dashed #2563eb}
.row button{width:22px;padding:0;border:0}
.name{flex:1;cursor:pointer;overflow-wrap:anywhere}
.name[contenteditable=true]{outline:1px solid #2563eb;background:#fff}
.row time{font-size:10px;color:#64748b}
#menu{position:fixed;z-index:30;background:#fff;border:1px solid #ccd6e3;border-radius:4px;padding:6px;max-height:85vh;max-width:95vw;overflow:auto;box-shadow:0 5px 20px #0003}
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
@media(max-width:600px){.row time{display:none}header strong{width:100%}main{height:calc(100dvh - 78px)}}
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
<strong>動画撮影編集</strong>
<button id="list">動画一覧</button>
<button id="upload">動画をアップする</button>
<span id="zoomLabel">表示倍率</span>
<input id="viewZoom" type="range" min="10" max="150" value="50" aria-label="動画表示倍率">
</header>

<main>
<section id="stage">
<div id="empty">「録画開始」で共有する画面を選択してください。<br>保存済み動画は「動画一覧」から開けます。</div>
<div id="surface" hidden>
<video id="video" playsinline preload="auto"></video>
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
</div>

<div id="status" role="status" aria-live="polite">起動確認中です…</div>
<div id="split"></div>

<section id="timeline" hidden>
<div class="toolbar">
<strong id="clock">0.0 / 0.0 秒</strong>
<label>タイムスケール
<input id="scale" type="range" min="1" max="100" value="100">
</label>
<span>右クリック：追加・書式・削除 ／ Ctrl・Shift：複数選択</span>
</div>
<div id="scroll">
<div id="tracks">
<div id="playhead"><div id="handle"></div></div>
</div>
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
<button id="closeList">閉じる</button>
<span>名前変更：ダブルクリック ／ 操作：右クリック</span>
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

const $=id=>document.getElementById(id);
const video=$('video'), canvas=$('overlay'), context=canvas.getContext('2d');
const clone=value=>structuredClone(value);
const uid=()=>crypto.randomUUID();
const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));

const titles={A:'コメント',B:'強調枠',C:'拡大枠',D:'スキップ',E:'接続線',F:'PDF位置'};
const palette=[
 '#FFFFFF','#000000','#FF0000','#F97316','#FFFF00','#00FF00',
 '#00FFFF','#0000FF','#8B5CF6','#EC4899','#64748B','transparent'
];
const dashes={solid:[],dash:[10,6],dot:[2,5],chain:[12,5,2,5]};
const order={C:0,B:1,A:2,E:3};

const S={
 model:null,folderId:null,current:null,revision:null,data:null,
 editing:false,dirty:false,busy:false,ready:false,blocked:false,
 selected:new Set(),collapsed:new Set(),connecting:null,
 duration:0,editor:null,screen:null,recorder:null,recordingResult:null,
 retry:null,audio:null,audioSource:null,audioSink:null,exporting:false
};

let ffmpeg=null, ffmpegLoading=null, ffmpegLog='';
let activeMediaCancel=null;
let timelineDragging=false, lastTrack=0;

function message(text,error=false){
 $('status').textContent=text;
 $('status').classList.toggle('error',error);
}
function elements(){return S.data?.elements||[]}
function find(id){return elements().find(e=>e.id===id)}
function locked(){return S.busy||!!S.screen||!S.ready||S.blocked}
function endpoint(action,id=null){
 return 'index.php?api=v1&action='+encodeURIComponent(action)+
        (id?'&id='+encodeURIComponent(id):'');
}
function update(){
 const loaded=!!S.current;
 $('begin').hidden=!loaded||S.editing||!!S.screen;
 $('save').hidden=$('finish').hidden=!S.editing;
 $('download').hidden=$('pdf').hidden=!loaded||!!S.screen;
 $('timeline').hidden=!S.editing;
 $('primary').textContent=S.screen?'録画停止':
   S.editing?(video.paused?'編集再生':'編集再生停止'):
   loaded?(video.paused?'再生':'再生停止'):'録画開始';

 for(const id of ['list','upload','upload2','new','folder','begin','save','download','pdf','finish'])
   $(id).disabled=locked()||!!S.retry;
 $('primary').disabled=S.busy||!S.ready||S.blocked||!!S.retry;
 if(S.screen&&!S.busy)$('primary').disabled=false;
 $('viewZoom').disabled=S.busy;
 $('scale').disabled=S.busy;
 fit();
}
async function safe(fn){
 if(S.busy)return;
 try{await fn()}
 catch(error){console.error(error);message(error.message||'操作に失敗しました。',true)}
 finally{update()}
}
async function job(fn){
 if(S.busy)return;
 S.busy=true;update();
 try{return await fn()}
 finally{S.busy=false;update()}
}
async function fetchTimed(url,options={},timeout=60000){
 const controller=new AbortController();
 const timer=setTimeout(()=>controller.abort(),timeout);
 try{return await fetch(url,{...options,signal:controller.signal})}
 catch(error){
   if(error.name==='AbortError')
     throw Error('通信がタイムアウトしました。サーバーとネットワークを確認してください。');
   throw Error('通信に失敗しました：'+error.message);
 }finally{clearTimeout(timer)}
}
async function readAPI(action,id=null){
 const response=await fetchTimed(endpoint(action,id),{cache:'no-store'});
 let result;
 try{result=await response.json()}
 catch{throw Error('サーバー応答がJSONではありません。PHPログを確認してください。')}
 if(!response.ok||!result.ok)throw Error(result.error?.message||'取得に失敗しました。');
 return result.data;
}
function retryNotice(error){
 message(
  error.message+'\n保存結果が未確認です。同じ内容で再送するか、一覧を確認して再送待ちを解除してください。',
  true
 );
 const retry=document.createElement('button');
 retry.textContent='同じ要求を再送';
 retry.onclick=()=>safe(()=>job(async()=>{
   const pending=S.retry;
   if(!pending)return;
   const data=await transmit(pending);
   await pending.done(data);
 }));
 const inspect=document.createElement('button');
 inspect.textContent='一覧を確認して解除';
 inspect.onclick=()=>safe(()=>job(async()=>{
   await refresh();library(true);
   if(confirm('一覧で保存結果を確認しましたか？\n未保存なら解除せず、同じ要求を再送してください。'))
     S.retry=null;
 }));
 $('status').append(document.createElement('br'),retry,inspect);
}
async function transmit(pending){
 const options={method:'POST'};
 if(pending.file){
   const form=new FormData();
   form.append('metadata',JSON.stringify(pending.request));
   form.append('file',pending.file,pending.file.type.includes('mp4')?'video.mp4':'video.webm');
   if(pending.duration!==undefined)
     form.append('sourceDuration',String(pending.duration));
   options.body=form;
 }else{
   options.headers={'Content-Type':'application/json'};
   options.body=JSON.stringify(pending.request);
 }
 let response,result;
 try{
   response=await fetchTimed(endpoint(pending.action),options,180000);
   result=await response.json();
 }catch(error){
   S.retry=pending;retryNotice(error);
   throw Error('保存結果を確認できません。画面下部から同じ要求を再送してください。');
 }
 if(!response.ok||!result.ok){
   if(response.status>=500){
     S.retry=pending;
     retryNotice(Error(result.error?.message||'サーバー保存に失敗しました。'));
     throw Error('保存結果を確認できません。同じ要求を再送してください。');
   }
   S.retry=null;
   throw Error((result.error?.code||'ERROR')+'：'+(result.error?.message||'更新に失敗しました。'));
 }
 S.retry=null;
 return result.data;
}
async function mutate(action,payload,file,done,duration){
 if(S.retry)throw Error('先に再送待ちの要求を解決してください。');
 const pending={action,request:{requestId:uid(),payload:clone(payload)},file,done,duration};
 const data=await transmit(pending);
 await done(data);
 return data;
}
async function refresh(){
 S.model=await readAPI('list');
 if(!S.model.folders.some(f=>f.id===S.folderId))
   S.folderId=S.model.folders[0]?.id||null;
 tree();
}
function library(open){
 $('library').hidden=$('veil').hidden=!open;
}
function commitText(){
 if(!S.editor)return;
 const editor=S.editor;S.editor=null;
 editor.item.text=editor.node.innerText;
 editor.node.remove();S.dirty=true;
 draw();
}
function leave(){
 commitText();
 if(S.retry)throw Error('再送待ちの保存要求を先に解決してください。');
 return !S.dirty||confirm('未保存の編集を破棄しますか？');
}
function reset(){
 commitText();
 video.pause();video.srcObject=null;video.removeAttribute('src');video.load();
 Object.assign(S,{
   current:null,revision:null,data:null,editing:false,dirty:false,
   duration:0,connecting:null
 });
 S.selected.clear();
 $('surface').hidden=true;$('empty').hidden=false;
 $('scale').value=100;
 tracks();update();
}

function mediaWait(events,test,action,timeout=30000){
 if(activeMediaCancel)activeMediaCancel();
 return new Promise((resolve,reject)=>{
   let timer;
   const cleanup=()=>{
     clearTimeout(timer);
     for(const event of events)video.removeEventListener(event,check);
     video.removeEventListener('error',failed);
     if(activeMediaCancel===cancel)activeMediaCancel=null;
   };
   const cancel=()=>{cleanup();reject(Error('動画読み込みが中止されました。'))};
   const check=()=>{if(test()){cleanup();resolve()}};
   const failed=()=>{cleanup();reject(Error('動画を読み込めません。形式・配信・ブラウザ対応を確認してください。'))};
   activeMediaCancel=cancel;
   for(const event of events)video.addEventListener(event,check);
   video.addEventListener('error',failed);
   timer=setTimeout(()=>{
     cleanup();reject(Error('動画読み込みがタイムアウトしました。'));
   },timeout);
   try{action?.();check()}catch(error){cleanup();reject(error)}
 });
}
async function seek(time){
 const target=clamp(time,0,S.duration||time);
 if(Math.abs(video.currentTime-target)<0.001&&!video.seeking&&video.readyState>=2){
   await new Promise(requestAnimationFrame);return;
 }
 await mediaWait(
   ['seeked','loadeddata','timeupdate'],
   ()=>!video.seeking&&video.readyState>=2&&Math.abs(video.currentTime-target)<0.05,
   ()=>{video.currentTime=target},20000
 );
 await new Promise(requestAnimationFrame);
}
async function source(fileID){
 video.pause();video.srcObject=null;S.duration=0;
 await mediaWait(['loadedmetadata'],()=>video.readyState>=1,()=>{
   video.src=endpoint('file.stream',fileID);video.load();
 });
 if(!Number.isFinite(video.duration)||video.duration<=0){
   await mediaWait(
     ['durationchange','seeked','timeupdate'],
     ()=>Number.isFinite(video.duration)&&video.duration>0,
     ()=>{video.currentTime=1e10}
   );
 }
 S.duration=video.duration;
 await seek(0);
 $('surface').hidden=false;$('empty').hidden=true;
 canvas.width=video.videoWidth;canvas.height=video.videoHeight;
 fit();tracks();draw();
}
async function openVideo(item,revision=null,editing=false){
 if(!leave())return;
 await job(async()=>{
   const edit=revision?await readAPI('edit.get',revision.id):null;
   S.current=item;S.revision=edit;
   S.data=edit?clone(edit.editData):null;
   S.editing=editing;S.dirty=false;S.selected.clear();S.connecting=null;
   message('動画を読み込んでいます…');
   await source(edit&&!editing?edit.renderedFileId:item.originalFileId);
   if(editing&&!S.data)S.data={
     schemaVersion:1,coordinateSystem:'source-pixels',
     sourceWidth:video.videoWidth,sourceHeight:video.videoHeight,elements:[]
   };
   library(false);tracks();draw();
   message(item.name+' ／ '+(edit?.name||'元動画'));
 });
}
function fit(){
 if(!video.videoWidth)return;
 const factor=+$('viewZoom').value/100;
 $('surface').style.width=video.videoWidth*factor+'px';
 $('surface').style.height=video.videoHeight*factor+'px';
}
function shape(ctx,e){
 ctx.beginPath();
 if(e.shape==='ellipse')ctx.ellipse(e.x+e.w/2,e.y+e.h/2,e.w/2,e.h/2,0,0,Math.PI*2);
 else if(e.shape==='round')ctx.roundRect(e.x,e.y,e.w,e.h,Math.min(16,e.w/4,e.h/4));
 else ctx.rect(e.x,e.y,e.w,e.h);
}
function port(e,p){
 if(e.shape==='ellipse')return{
   x:e.x+e.w/2+Math.cos(p.angle)*e.w/2,
   y:e.y+e.h/2+Math.sin(p.angle)*e.h/2
 };
 const r=p.ratio;
 return p.side==='left'?{x:e.x,y:e.y+e.h*r}:
        p.side==='right'?{x:e.x+e.w,y:e.y+e.h*r}:
        p.side==='top'?{x:e.x+e.w*r,y:e.y}:
        {x:e.x+e.w*r,y:e.y+e.h};
}
function nearest(e,q){
 if(e.shape==='ellipse')return{
   angle:Math.atan2((q.y-e.y-e.h/2)/(e.h/2),(q.x-e.x-e.w/2)/(e.w/2))
 };
 return[
   {side:'left',ratio:clamp((q.y-e.y)/e.h,0,1)},
   {side:'right',ratio:clamp((q.y-e.y)/e.h,0,1)},
   {side:'top',ratio:clamp((q.x-e.x)/e.w,0,1)},
   {side:'bottom',ratio:clamp((q.x-e.x)/e.w,0,1)}
 ].sort((a,b)=>{
   const p=port(e,a),r=port(e,b);
   return Math.hypot(p.x-q.x,p.y-q.y)-Math.hypot(r.x-q.x,r.y-q.y);
 })[0];
}
function normal(e,p){
 if(e.shape==='ellipse'){
   const x=Math.cos(p.angle)/e.w,y=Math.sin(p.angle)/e.h,l=Math.hypot(x,y)||1;
   return{x:x/l,y:y/l};
 }
 return{
   left:{x:-1,y:0},right:{x:1,y:0},top:{x:0,y:-1},bottom:{x:0,y:1}
 }[p.side];
}
function ends(e){
 const a=find(e.ownerId),b=find(e.commentId);
 if(!a||!b)return null;
 const p=port(a,e.from),q=port(b,e.to);
 const n=normal(a,e.from),m=normal(b,e.to);
 return[p,{x:p.x+n.x*18,y:p.y+n.y*18},
          {x:q.x+m.x*18,y:q.y+m.y*18},q];
}
function visible(e,time){
 const owner=e.type==='E'?find(e.ownerId):e;
 return owner&&!['D','F'].includes(e.type)&&time>=owner.start&&time<owner.end;
}
function paint(ctx,time,editingMarks=false){
 elements().filter(e=>visible(e,time)).sort((a,b)=>order[a.type]-order[b.type]).forEach(e=>{
   ctx.save();
   ctx.strokeStyle=e.stroke;ctx.lineWidth=e.width;ctx.setLineDash(dashes[e.dash]);
   if(e.type==='E'){
     const points=ends(e);
     if(!points){ctx.restore();return}
     const[p,u,v,q]=points;
     ctx.beginPath();ctx.moveTo(p.x,p.y);ctx.lineTo(u.x,u.y);
     if(e.line==='orthogonal'){
       ctx.lineTo((u.x+v.x)/2,u.y);ctx.lineTo((u.x+v.x)/2,v.y);
     }else if(e.line==='wave'){
       const dx=v.x-u.x,dy=v.y-u.y,length=Math.hypot(dx,dy)||1;
       for(let i=1;i<=80;i++){
         const t=i/80,wave=16*Math.sin(2*Math.PI*t);
         ctx.lineTo(u.x+dx*t-dy/length*wave,u.y+dy*t+dx/length*wave);
       }
     }
     ctx.lineTo(v.x,v.y);ctx.lineTo(q.x,q.y);
     if(e.stroke!=='transparent'&&e.width>0)ctx.stroke();
     ctx.setLineDash([]);
     if(e.terminal==='arrow'&&e.stroke!=='transparent'){
       const angle=Math.atan2(q.y-v.y,q.x-v.x);
       ctx.beginPath();
       ctx.moveTo(q.x-12*Math.cos(angle-.5),q.y-12*Math.sin(angle-.5));
       ctx.lineTo(q.x,q.y);
       ctx.lineTo(q.x-12*Math.cos(angle+.5),q.y-12*Math.sin(angle+.5));
       ctx.stroke();
     }
     if(e.terminal==='circle'&&e.stroke!=='transparent'){
       ctx.beginPath();ctx.arc(q.x,q.y,5,0,Math.PI*2);ctx.stroke();
     }
     if(editingMarks)[p,q].forEach((point,index)=>{
       ctx.fillStyle=index?'#22C55E':'#38BDF8';
       ctx.beginPath();ctx.arc(point.x,point.y,S.selected.has(e.id)?6:4,0,Math.PI*2);ctx.fill();
     });
   }else{
     shape(ctx,e);
     if(e.type==='C'&&video.readyState>=2){
       ctx.save();ctx.clip();
       ctx.drawImage(
         video,e.x+(e.w-e.w/e.zoom)/2,e.y+(e.h-e.h/e.zoom)/2,
         e.w/e.zoom,e.h/e.zoom,e.x,e.y,e.w,e.h
       );
       ctx.restore();
     }
     if(e.background!=='transparent'){ctx.fillStyle=e.background;ctx.fill()}
     if(e.stroke!=='transparent'&&e.width>0)ctx.stroke();
     if(e.type==='A'){
       ctx.save();shape(ctx,e);ctx.clip();
       ctx.fillStyle=e.color;
       ctx.font=e.size+'px '+(e.font==='mincho'?'serif':'sans-serif');
       ctx.textBaseline='top';
       let y=e.y+6;
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
     if(editingMarks&&S.selected.has(e.id)){
       ctx.strokeStyle='#60A5FA';ctx.lineWidth=2;ctx.setLineDash([4,3]);
       ctx.strokeRect(e.x,e.y,e.w,e.h);
       ctx.fillStyle='#60A5FA';ctx.fillRect(e.x+e.w-7,e.y+e.h-7,14,14);
     }
   }
   ctx.restore();
 });
}
function draw(){
 context.clearRect(0,0,canvas.width,canvas.height);
 if(S.editing&&!S.exporting)paint(context,video.currentTime,true);
}
function point(event){
 const r=canvas.getBoundingClientRect();
 return{x:(event.clientX-r.left)*canvas.width/r.width,
        y:(event.clientY-r.top)*canvas.height/r.height};
}
function hit(q){
 return elements().filter(e=>visible(e,video.currentTime)).
   sort((a,b)=>order[b.type]-order[a.type]).find(e=>{
     if(e.type==='E'){
       const p=ends(e);
       return p&&[p[0],p[3]].some(r=>Math.hypot(r.x-q.x,r.y-q.y)<15);
     }
     return q.x>=e.x&&q.x<=e.x+e.w&&q.y>=e.y&&q.y<=e.y+e.h;
   });
}
function select(item,event){
 if(!event.ctrlKey&&!event.shiftKey)S.selected.clear();
 if(item){
   if((event.ctrlKey||event.shiftKey)&&S.selected.has(item.id))S.selected.delete(item.id);
   else S.selected.add(item.id);
 }
}
function changed(){S.dirty=true;tracks();draw()}
function drag(event,move,done=()=>{}){
 event.preventDefault();
 const stop=()=>{
   window.removeEventListener('pointermove',move);
   window.removeEventListener('pointerup',stop);
   window.removeEventListener('pointercancel',stop);
   done();
 };
 window.addEventListener('pointermove',move);
 window.addEventListener('pointerup',stop);
 window.addEventListener('pointercancel',stop);
}

canvas.onpointerdown=event=>{
 if(event.button!==0||!S.editing||locked())return;
 commitText();video.pause();
 const q=point(event),item=hit(q);
 if(S.connecting){
   if(!item||!['A','B','C'].includes(item.type))return;
   if(S.connecting==='start'){
     S.connecting=item.id;message('接続先をクリックしてください。Escで中止。');return;
   }
   const first=find(S.connecting);
   if(!first||(first.type==='A')===(item.type==='A')){
     message('コメントと、強調枠または拡大枠を選択してください。',true);return;
   }
   const owner=first.type==='A'?item:first,comment=first.type==='A'?first:item;
   const from=nearest(owner,{x:comment.x+comment.w/2,y:comment.y+comment.h/2});
   const e={
     id:uid(),type:'E',ownerId:owner.id,commentId:comment.id,
     from,to:nearest(comment,port(owner,from)),
     stroke:owner.stroke,width:owner.width,dash:owner.dash,
     line:'orthogonal',terminal:'arrow'
   };
   elements().push(e);S.selected=new Set([e.id]);S.connecting=null;changed();return;
 }
 select(item,event);tracks();draw();
 if(!item)return;
 const old=clone(item);
 const resize=item.type!=='E'&&q.x>item.x+item.w-18&&q.y>item.y+item.h-18;
 const points=item.type==='E'?ends(item):null;
 const side=points&&Math.hypot(points[0].x-q.x,points[0].y-q.y)<15?'from':'to';
 drag(event,event=>{
   const p=point(event);
   if(item.type==='E'){
     item[side]=nearest(find(side==='from'?item.ownerId:item.commentId),p);
   }else if(resize){
     item.w=clamp(old.w+p.x-q.x,Math.min(10,canvas.width-item.x),canvas.width-item.x);
     item.h=clamp(old.h+p.y-q.y,Math.min(10,canvas.height-item.y),canvas.height-item.y);
   }else{
     item.x=clamp(old.x+p.x-q.x,0,canvas.width-item.w);
     item.y=clamp(old.y+p.y-q.y,0,canvas.height-item.h);
   }
   S.dirty=true;draw();
 });
};
function editText(item){
 commitText();video.pause();
 const node=document.createElement('div'),k=canvas.clientWidth/canvas.width;
 node.className='editor';node.contentEditable='true';node.innerText=item.text;
 node.dataset.elementId=item.id;
 Object.assign(node.style,{
   left:item.x*k+'px',top:item.y*k+'px',width:item.w*k+'px',height:item.h*k+'px',
   fontSize:item.size*k+'px',fontFamily:item.font==='mincho'?'serif':'sans-serif',
   color:item.color,background:item.background
 });
 $('surface').append(node);S.editor={node,item};
 node.oninput=()=>{item.text=node.innerText;S.dirty=true};
 node.onblur=()=>{commitText();tracks()};
 node.focus();
}
canvas.ondblclick=event=>{
 if(!S.editing||locked())return;
 const item=hit(point(event));
 if(item?.type==='A')editText(item);
};

function range(){
 const span=Math.max(.001,S.duration*+$('scale').value/100);
 return{
   span,start:clamp(video.currentTime-span/2,0,Math.max(0,S.duration-span)),
   width:Math.max(1,$('tracks').clientWidth-20)
 };
}
function timeAt(event,r=range()){
 return clamp(
   r.start+(event.clientX-$('tracks').getBoundingClientRect().left-10)/r.width*r.span,
   0,S.duration
 );
}
function clock(){
 $('clock').textContent=(video.currentTime||0).toFixed(1)+' / '+S.duration.toFixed(1)+' 秒';
 const r=range();
 $('playhead').style.left=10+clamp((video.currentTime-r.start)/r.span,0,1)*r.width+'px';
}
function tracks(){
 const root=$('tracks');
 root.querySelectorAll('.clip,.tick').forEach(node=>node.remove());
 if(!S.editing){clock();return}
 const r=range();
 for(let i=0;i<=8;i++){
   const tick=document.createElement('span');tick.className='tick';
   tick.style.left=10+r.width*i/8+'px';
   tick.textContent=(r.start+r.span*i/8).toFixed(1)+'s';
   if(i===8)tick.style.transform='translateX(-100%)';
   root.append(tick);
 }
 const rows=[];
 elements().filter(e=>e.type!=='E').
   sort((a,b)=>(a.time??a.start)-(b.time??b.start)).forEach(e=>{
     const start=e.type==='F'?e.time:e.start;
     const end=e.type==='F'?start+.0001:e.end;
     let row=rows.findIndex(value=>value<=start);
     if(row<0)row=rows.length;
     rows[row]=end;
     if(end<r.start||start>r.start+r.span)return;
     const node=document.createElement('div');
     node.className='clip '+e.type+(S.selected.has(e.id)?' selected':'');
     node.dataset.elementId=e.id;
     node.textContent=e.type==='A'?e.text:titles[e.type];
     const left=10+(Math.max(start,r.start)-r.start)/r.span*r.width;
     const width=e.type==='F'?8:
       (Math.min(end,r.start+r.span)-Math.max(start,r.start))/r.span*r.width;
     Object.assign(node.style,{
       left:left+'px',top:26+row*29+'px',
       width:Math.max(2,Math.min(root.clientWidth-left,width))+'px'
     });
     if(e.type!=='F')for(const side of ['start','end']){
       if(side==='start'&&start<r.start||side==='end'&&end>r.start+r.span)continue;
       const edge=document.createElement('span');edge.className='edge '+side;node.append(edge);
     }
     node.oncontextmenu=event=>{
       event.stopPropagation();elementMenu(event,e,true);
     };
     node.onpointerdown=event=>{
       if(event.button!==0||locked())return;
       event.stopPropagation();video.pause();select(e,event);
       const old=clone(e),x=event.clientX;
       const side=event.target.classList.contains('start')?'start':
         event.target.classList.contains('end')?'end':'move';
       timelineDragging=true;
       drag(event,event=>{
         const delta=(event.clientX-x)/r.width*r.span;
         if(e.type==='F')e.time=clamp(old.time+delta,0,Math.max(0,S.duration-.001));
         else if(side==='start')e.start=clamp(old.start+delta,0,e.end-.001);
         else if(side==='end')e.end=clamp(old.end+delta,e.start+.001,S.duration);
         else{
           const length=old.end-old.start;
           e.start=clamp(old.start+delta,0,S.duration-length);e.end=e.start+length;
         }
         changed();
       },()=>{timelineDragging=false});
       tracks();draw();
     };
     root.append(node);
   });
 root.style.height=Math.max(140,rows.length*29+30)+'px';clock();
}
function add(type,time,q){
 if(!S.duration||time>=S.duration){message('動画終了より前に追加してください。',true);return}
 const e={id:uid(),type};
 if(type==='F')e.time=time;
 else{
   Object.assign(e,{start:time,end:Math.min(S.duration,time+3)});
   if(['A','B','C'].includes(type)){
     const w=Math.min(260,canvas.width),h=Math.min(110,canvas.height);
     Object.assign(e,{
       x:clamp(q.x,0,canvas.width-w),y:clamp(q.y,0,canvas.height-h),
       w,h,stroke:'#FF0000',width:3,dash:'solid',shape:'rect',background:'transparent'
     });
     if(type==='A')Object.assign(e,{text:'コメント',size:24,font:'gothic',color:'#FFFFFF'});
     if(type==='C')e.zoom=2;
   }
 }
 elements().push(e);S.selected=new Set([e.id]);changed();
 if(type==='A')editText(e);
}
function positionMenu(event){
 const menu=$('menu');menu.hidden=false;
 menu.style.left=clamp(event.clientX,4,Math.max(4,innerWidth-menu.offsetWidth-4))+'px';
 menu.style.top=clamp(event.clientY,4,Math.max(4,innerHeight-menu.offsetHeight-4))+'px';
}
function menu(event,items){
 event.preventDefault();if(locked()||S.retry)return;
 $('menu').replaceChildren();
 for(const [label,run] of items){
   const button=document.createElement('button');button.textContent=label;
   button.onclick=()=>safe(async()=>{$('menu').hidden=true;await run()});
   $('menu').append(button);
 }
 positionMenu(event);
}
function elementMenu(event,item,timeline){
 event.preventDefault();
 if(!S.editing||locked())return;
 commitText();video.pause();
 if(item&&!S.selected.has(item.id))S.selected=new Set([item.id]);
 const time=timeline?timeAt(event):video.currentTime;
 const q=timeline?{x:canvas.width*.1,y:canvas.height*.1}:point(event);
 const items=(timeline?['A','B','C','D','F']:['A','B','C']).
   map(type=>[titles[type]+'追加',()=>add(type,time,q)]);
 if(!timeline)items.push(['接続線追加',()=>{
   S.connecting='start';message('接続開始側をクリックしてください。Escで中止。');
 }]);
 if(timeline&&S.selected.size>1)items.push(['開始位置を合わせる',()=>{
   const list=elements().filter(e=>S.selected.has(e.id)&&e.type!=='E');
   const start=item?(item.time??item.start):Math.min(...list.map(e=>e.time??e.start));
   if(list.some(e=>e.type!=='F'&&start+e.end-e.start>S.duration)){
     message('動画終了を超えるため変更できません。',true);return;
   }
   for(const e of list){
     if(e.type==='F')e.time=start;
     else{const length=e.end-e.start;e.start=start;e.end=start+length}
   }
   changed();
 }]);
 if(item){
   if(item.type==='A')items.push(['本文編集',()=>editText(item)]);
   items.push(['書式・時刻変更',()=>format(item,event)]);
   items.push(['選択要素を削除',()=>{
     S.data.elements=elements().filter(e=>
       !S.selected.has(e.id)&&!S.selected.has(e.ownerId)&&!S.selected.has(e.commentId)
     );
     S.selected.clear();changed();
   }]);
 }
 menu(event,items);
}
function format(e,event){
 const root=$('menu');root.replaceChildren();
 if(e.type!=='E'){
   for(const key of e.type==='F'?['time']:['start','end']){
     const label=document.createElement('label');
     label.textContent={time:'PDF位置（秒）',start:'開始（秒）',end:'終了（秒）'}[key];
     const input=document.createElement('input');input.type='number';input.step='.01';
     input.style.width='105px';input.value=e[key];
     input.onchange=()=>{
       const value=+input.value;
       const valid=Number.isFinite(value)&&value>=0&&(
         key==='time'?value<S.duration:
         key==='start'?value<e.end:value>e.start&&value<=S.duration
       );
       if(!valid){input.value=e[key];message('時刻が動画の範囲外です。',true);return}
       e[key]=value;changed();
     };
     label.append(input);root.append(label);
   }
 }
 if(['D','F'].includes(e.type)){positionMenu(event);return}
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
   ...(e.type==='C'?[
     ['倍率','zoom',[[1,'1倍'],[2,'2倍'],[3,'3倍'],[4,'4倍'],[6,'6倍'],[8,'8倍']]]
   ]:[])
 ];
 for(const [text,key,options] of fields){
   const label=document.createElement('label'),select=document.createElement('select');
   label.textContent=text;
   for(const [value,title] of options)select.add(new Option(title,String(value)));
   select.value=String(e[key]);
   select.onchange=()=>{
     e[key]=['size','width','zoom'].includes(key)?+select.value:select.value;
     if(key==='shape'){
       for(const line of elements().filter(line=>line.type==='E'&&
         (line.ownerId===e.id||line.commentId===e.id))){
         const side=line.ownerId===e.id?'from':'to';
         line[side]=e.shape==='ellipse'?{angle:0}:{side:'right',ratio:.5};
       }
     }
     changed();
   };
   label.append(select);root.append(label);
 }
 const target=document.createElement('select');target.add(new Option('線色','stroke'));
 if(e.type!=='E')target.add(new Option('背景色','background'));
 if(e.type==='A')target.add(new Option('文字色','color'));
 root.append(target);
 const colors=document.createElement('div');colors.className='palette';
 for(const color of palette){
   const button=document.createElement('button');
   button.title=color==='transparent'?'塗り潰しなし':color;
   button.style.background=color==='transparent'?
     'repeating-linear-gradient(45deg,#fff 0 4px,#bbb 4px 8px)':color;
   button.onclick=()=>{e[target.value]=color;changed()};
   colors.append(button);
 }
 root.append(colors);positionMenu(event);
}

function row(entity,type,depth,open,branch=false){
 const node=document.createElement('div'),toggle=document.createElement('button');
 const name=document.createElement('span'),date=document.createElement('time');
 node.className='row';node.style.marginLeft=depth*16+'px';
 node.dataset.entityType=type;node.dataset.entityId=entity.id;
 toggle.textContent=branch?(S.collapsed.has(entity.id)?'▸':'▾'):'·';
 name.className='name';name.textContent=entity.name;
 date.textContent=new Date(entity.updatedAt).toLocaleString('ja-JP');
 node.append(toggle,name,date);$('tree').append(node);

 toggle.onclick=()=>safe(async()=>{
   if(locked())return;
   if(branch){
     if(S.collapsed.has(entity.id))S.collapsed.delete(entity.id);
     else S.collapsed.add(entity.id);
     if(type==='folder')S.folderId=entity.id;
     tree();
   }else await open?.();
 });
 let timer;
 name.onclick=event=>{
   clearTimeout(timer);
   if(event.detail===1)timer=setTimeout(()=>safe(async()=>{
     if(!locked()&&!name.isContentEditable){
       if(type==='folder'){S.folderId=entity.id;message('保存先：'+entity.name)}
       else await open?.();
     }
   }),300);
 };
 name.ondblclick=()=>{
   clearTimeout(timer);
   if(!locked()&&type!=='original'){name.contentEditable='true';name.focus()}
 };
 name.onblur=()=>safe(async()=>{
   if(!name.isContentEditable)return;
   name.contentEditable='false';
   const value=name.textContent.trim()||entity.name;
   await job(()=>mutate(type+'.rename',{id:entity.id,name:value},null,async()=>{
     if(type==='video'&&S.current?.id===entity.id)S.current.name=value;
     if(type==='edit'&&S.revision?.id===entity.id)S.revision.name=value;
     await refresh();message('名前を変更しました。');
   }));
 });
 name.onkeydown=event=>{
   if(event.key==='Enter'){event.preventDefault();name.blur()}
   if(event.key==='Escape'){name.textContent=entity.name;name.blur()}
 };
 return node;
}
async function removeEntity(entity,type){
 if(!leave())return;
 if(!confirm(type==='edit'?'この編集と編集動画を削除しますか？':
   'この項目と配下の動画・編集をすべて削除しますか？'))return;
 await job(()=>mutate(type+'.delete',{id:entity.id},null,async()=>{
   await refresh();
   if(S.current&&!S.model.videos.some(v=>v.id===S.current.id))reset();
   else if(S.revision&&!S.model.edits.some(e=>e.id===S.revision.id)){
     S.revision=null;S.data=null;S.editing=false;S.dirty=false;
     await source(S.current.originalFileId);
   }
   message('削除しました。');
 }));
}
function outputItems(item,edit=null){
 return[
   ['再生',()=>openVideo(item,edit,false)],
   ['編集する',()=>openVideo(item,edit,true)],
   ['ダウンロード',async()=>{
     if(!leave())return;
     await job(()=>downloadFile(edit?edit.renderedFileId:item.originalFileId,
       item.name+(edit?'_'+edit.name:'')));
   }],
   ...(edit?[['PDF出力',async()=>{
     await openVideo(item,edit,true);
     if(S.current?.id===item.id&&S.revision?.id===edit.id)$('pdfDialog').showModal();
   }]]:[])
 ];
}
function tree(){
 if(!S.model)return;
 $('tree').replaceChildren();
 const visited=new Set();
 function visit(folder,depth){
   if(visited.has(folder.id))return;visited.add(folder.id);
   const node=row(folder,'folder',depth,null,true);
   node.oncontextmenu=event=>menu(event,[
     ['保存先にする',()=>{S.folderId=folder.id;message('保存先：'+folder.name)}],
     ['子フォルダ追加',()=>job(()=>mutate('folder.create',{
       id:uid(),parentFolderId:folder.id,name:'新しいフォルダ'
     },null,async()=>{S.collapsed.delete(folder.id);await refresh()}))],
     ['削除',()=>removeEntity(folder,'folder')]
   ]);
   node.ondragover=event=>{
     if(!locked()){event.preventDefault();node.classList.add('drop')}
   };
   node.ondragleave=()=>node.classList.remove('drop');
   node.ondrop=event=>safe(async()=>{
     event.preventDefault();node.classList.remove('drop');
     if(locked())return;
     const id=event.dataTransfer.getData('text/plain');
     if(!S.model.videos.some(v=>v.id===id))return;
     await job(()=>mutate('video.move',{id,folderId:folder.id},null,async()=>{
       if(S.current?.id===id)S.current.folderId=folder.id;
       S.collapsed.delete(folder.id);await refresh();message('動画を移動しました。');
     }));
   });
   if(S.collapsed.has(folder.id))return;
   S.model.folders.filter(f=>f.parentFolderId===folder.id).forEach(f=>visit(f,depth+1));
   for(const item of S.model.videos.filter(v=>v.folderId===folder.id)){
     const group=row(item,'video',depth+1,()=>openVideo(item),true);
     group.draggable=true;
     group.ondragstart=event=>{
       if(locked()||event.target.isContentEditable)event.preventDefault();
       else event.dataTransfer.setData('text/plain',item.id);
     };
     group.oncontextmenu=event=>menu(event,[
       ...outputItems(item),['削除',()=>removeEntity(item,'video')]
     ]);
     if(S.collapsed.has(item.id))continue;
     const original=row({...item,name:'元動画'},'original',depth+2,()=>openVideo(item));
     original.oncontextmenu=event=>menu(event,outputItems(item));
     for(const edit of S.model.edits.filter(e=>e.videoId===item.id)){
       const editRow=row(edit,'edit',depth+2,()=>openVideo(item,edit,true));
       editRow.oncontextmenu=event=>menu(event,[
         ...outputItems(item,edit),['削除',()=>removeEntity(edit,'edit')]
       ]);
     }
   }
 }
 S.model.folders.filter(f=>f.parentFolderId===null).forEach(f=>visit(f,0));
 // 不正な親参照がある場合も項目を隠さない。
 for(const folder of S.model.folders)
   if(!visited.has(folder.id))visit(folder,0);
}

function scriptLoad(src){
 return new Promise((resolve,reject)=>{
   const script=document.createElement('script');
   const timer=setTimeout(()=>{
     script.remove();reject(Error('動画変換ライブラリの読み込みがタイムアウトしました。'));
   },60000);
   script.crossOrigin='anonymous';script.src=src;
   script.onload=()=>{clearTimeout(timer);resolve()};
   script.onerror=()=>{
     clearTimeout(timer);reject(Error('動画変換ライブラリを取得できません。CDNへの接続を確認してください。'));
   };
   document.head.append(script);
 });
}
async function ffmpegReady(){
 if(ffmpeg)return ffmpeg;
 if(ffmpegLoading)return ffmpegLoading;
 ffmpegLoading=(async()=>{
   if(!window.crossOriginIsolated||typeof SharedArrayBuffer==='undefined')
     throw Error('MP4変換に必要なブラウザ分離設定が無効です。HTTPS/localhostと応答ヘッダーを確認してください。');
   message('MP4変換ライブラリを読み込んでいます…');
   if(!window.FFmpeg)
     await scriptLoad('https://cdn.jsdelivr.net/npm/@ffmpeg/ffmpeg@0.11.6/dist/ffmpeg.min.js');
   const instance=window.FFmpeg.createFFmpeg({
     log:false,
     corePath:'https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.11.0/dist/ffmpeg-core.js'
   });
   instance.setLogger(({message:text})=>{ffmpegLog=text});
   instance.setProgress(({ratio})=>{
     if(Number.isFinite(ratio))
       message('MP4変換中… '+Math.round(clamp(ratio,0,1)*100)+'%');
   });
   const timer=setTimeout(()=>{
     try{instance.exit()}catch{}
   },120000);
   try{await instance.load()}
   catch(error){
     throw Error('MP4変換環境を読み込めません：'+error.message);
   }finally{clearTimeout(timer)}
   ffmpeg=instance;return instance;
 })();
 try{return await ffmpegLoading}
 finally{ffmpegLoading=null}
}
async function toMP4(blob){
 const instance=await ffmpegReady();
 const input='input-'+uid().replaceAll('-','')+
   (blob.type.includes('mp4')?'.mp4':blob.type.includes('quicktime')?'.mov':'.webm');
 const output='output-'+uid().replaceAll('-','')+'.mp4';
 let timer;
 try{
   message('MP4変換用データを準備しています…');
   instance.FS('writeFile',input,new Uint8Array(await blob.arrayBuffer()));
   ffmpegLog='';
   const timeout=new Promise((resolve,reject)=>{
     timer=setTimeout(()=>{
       try{instance.exit()}catch{}
       ffmpeg=null;
       reject(Error('MP4変換がタイムアウトしました。短い動画で再試行してください。'));
     },1800000);
   });
   await Promise.race([
     instance.run(
       '-i',input,'-map','0:v:0','-map','0:a?',
       '-vf','pad=ceil(iw/2)*2:ceil(ih/2)*2',
       '-c:v','libx264','-preset','ultrafast','-crf','23',
       '-pix_fmt','yuv420p','-c:a','aac','-b:a','128k',
       '-movflags','+faststart',output
     ),
     timeout
   ]);
   const bytes=instance.FS('readFile',output);
   if(!bytes.length)throw Error('MP4が生成されませんでした。');
   return new Blob([bytes.slice().buffer],{type:'video/mp4'});
 }catch(error){
   throw Error('MP4変換に失敗しました：'+error.message+
     (ffmpegLog?'\n'+ffmpegLog:''));
 }finally{
   clearTimeout(timer);
   for(const name of [input,output]){
     try{instance.FS('unlink',name)}catch{}
   }
 }
}
function recorderFor(stream){
 if(!window.MediaRecorder)throw Error('このブラウザは録画に対応していません。');
 const mimeType=[
   'video/webm;codecs=vp8,opus','video/webm;codecs=vp9,opus',
   'video/webm','video/mp4'
 ].find(type=>MediaRecorder.isTypeSupported(type));
 if(!mimeType)throw Error('使用できる録画形式がありません。');
 return new MediaRecorder(stream,{mimeType});
}
function collect(recorder){
 return new Promise((resolve,reject)=>{
   const parts=[];
   recorder.ondataavailable=event=>{if(event.data.size)parts.push(event.data)};
   recorder.onstop=()=>resolve(new Blob(parts,{type:recorder.mimeType}));
   recorder.onerror=event=>reject(event.error||Error('録画に失敗しました。'));
 });
}
async function storeVideo(blob,name){
 if(!S.folderId)throw Error('保存先フォルダを追加してください。');
 const id=uid();
 await mutate('video.upload',{id,folderId:S.folderId,name},blob,async()=>{
   await refresh();
   S.current=S.model.videos.find(v=>v.id===id);
   S.revision=null;S.data=null;S.editing=false;S.dirty=false;S.selected.clear();
   await source(S.current.originalFileId);
   library(false);message('動画を保存しました。「編集開始」で編集できます。');
 });
}

/*
 * getDisplayMediaはクリックイベント直後に呼び出す。
 * ライブラリ読込やサーバー通信を先にawaitしない。
 */
function recordStart(){
 if(locked()||S.retry)return;
 if(!leave())return;
 if(!window.isSecureContext){
   message('画面録画にはHTTPSまたはlocalhostが必要です。',true);return;
 }
 if(!navigator.mediaDevices?.getDisplayMedia){
   message('このブラウザは画面共有録画に対応していません。PC版Chrome/Edgeを使用してください。',true);return;
 }
 if(!S.folderId){
   message('保存先フォルダを追加してください。',true);return;
 }
 message('画面共有ダイアログで対象を選択してください。音声はダイアログ内で選択します。');
 S.busy=true;update();
 let capture;
 try{capture=navigator.mediaDevices.getDisplayMedia({video:true,audio:true})}
 catch(error){
   S.busy=false;update();message('画面共有を開始できません：'+error.message,true);return;
 }
 capture.then(async stream=>{
   try{
     reset();
     S.screen=stream;video.srcObject=stream;video.muted=true;
     await video.play();
     $('surface').hidden=false;$('empty').hidden=true;
     canvas.width=video.videoWidth||1280;canvas.height=video.videoHeight||720;
     S.recorder=recorderFor(stream);
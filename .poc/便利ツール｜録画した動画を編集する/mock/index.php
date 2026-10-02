<?php
/* 録画した動画を編集する - PoC mock
   Apache + PHP / DBなし / このファイルと同じフォルダへJSON保存 */
$dataFile = __DIR__ . '/video_editor.json';
$uploadDir = __DIR__ . '/videos';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

function loadData($file) {
    if (!is_file($file)) return ['videos'=>[], 'works'=>[], 'results'=>[]];
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : ['videos'=>[], 'works'=>[], 'results'=>[]];
}
function saveData($file, $d) {
    file_put_contents($file, json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
}
function out($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$data = loadData($dataFile);
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = $_GET['api'];
    if ($api === 'state') { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

    if ($api === 'save_work') {
        $p = json_decode($_POST['work'] ?? '', true);
        if (!$p) { http_response_code(400); echo json_encode(['error'=>'保存内容が不正です']); exit; }
        $p['updatedAt'] = date('Y-m-d H:i:s');
        $found = false;
        foreach ($data['works'] as &$w) if (($w['id'] ?? '') === ($p['id'] ?? '')) { $w=$p; $found=true; break; }
        unset($w);
        if (!$found) {
            if (count($data['works']) >= 10) { echo json_encode(['error'=>'編集作業は10件までです。既存の編集作業を削除してから保存してください。']); exit; }
            $p['id'] = $p['id'] ?: uniqid('work_', true);
            $p['createdAt'] = date('Y-m-d H:i:s');
            $data['works'][] = $p;
        }
        saveData($dataFile,$data); echo json_encode(['ok'=>true,'work'=>$p],JSON_UNESCAPED_UNICODE); exit;
    }

    if ($api === 'delete_work') {
        $id=$_POST['id']??'';
        $data['works']=array_values(array_filter($data['works'],fn($x)=>($x['id']??'')!==$id));
        saveData($dataFile,$data); echo json_encode(['ok'=>true]); exit;
    }

    if ($api === 'delete_video') {
        $id=$_POST['id']??'';
        $v=array_values(array_filter($data['videos'],fn($x)=>($x['id']??'')===$id));
        $data['videos']=array_values(array_filter($data['videos'],fn($x)=>($x['id']??'')!==$id));
        foreach ($v as $x) if (!empty($x['file']) && is_file(__DIR__.'/'.$x['file'])) @unlink(__DIR__.'/'.$x['file']);
        saveData($dataFile,$data); echo json_encode(['ok'=>true]); exit;
    }

    if ($api === 'create_result') {
        $p=json_decode($_POST['result']??'',true);
        if (!$p) { echo json_encode(['error'=>'結果データが不正です']); exit; }
        if (count($data['results'])>=10) { echo json_encode(['error'=>'編集結果動画は10件までです。既存の結果動画を削除してから作成してください。']); exit; }
        $p['id']=uniqid('result_',true); $p['createdAt']=date('Y-m-d H:i:s');
        $data['results'][]=$p; saveData($dataFile,$data);
        echo json_encode(['ok'=>true,'result'=>$p],JSON_UNESCAPED_UNICODE); exit;
    }

    if ($api === 'upload') {
        if (count($data['videos'])>=10) { echo json_encode(['error'=>'オリジナル動画は10件までです。既存動画を削除してから追加してください。']); exit; }
        if (empty($_FILES['video']['tmp_name'])) { echo json_encode(['error'=>'動画を指定してください。']); exit; }
        $name=basename($_FILES['video']['name']);
        $id=uniqid('video_',true); $safe=$id.'_'.preg_replace('/[^\w.\-ぁ-んァ-ヶ一-龠]/u','_', $name);
        $dest=$uploadDir.'/'.$safe;
        if (!move_uploaded_file($_FILES['video']['tmp_name'],$dest)) { echo json_encode(['error'=>'動画の読込に失敗しました。']); exit; }
        $item=['id'=>$id,'name'=>$name,'type'=>'original','duration'=>0,'file'=>'videos/'.$safe,'createdAt'=>date('Y-m-d H:i:s')];
        $data['videos'][]=$item; saveData($dataFile,$data);
        echo json_encode(['ok'=>true,'video'=>$item],JSON_UNESCAPED_UNICODE); exit;
    }
    http_response_code(404); echo json_encode(['error'=>'指定されたAPIは存在しません']); exit;
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Yu Gothic",sans-serif;color:#20242a;background:#f4f6f8}
button,input,select,textarea{font:inherit}button{cursor:pointer}.top{height:56px;background:#17202a;color:#fff;display:flex;align-items:center;padding:0 18px;gap:14px}
.top h1{font-size:18px;margin:0}.status{font-size:12px;padding:5px 9px;border-radius:12px;background:#46515d}.status.dirty{background:#9b6500}
.top .actions{margin-left:auto;display:flex;gap:7px}.top button{border:1px solid #65717d;background:#263340;color:#fff;border-radius:5px;padding:7px 11px}
main{height:calc(100vh - 56px);padding:14px;display:flex;flex-direction:column;gap:10px}.editor{display:none;flex:1;min-height:0;gap:10px}.editor.active{display:flex}.stage{background:#101419;border-radius:7px;flex:1;min-width:0;display:flex;flex-direction:column}.videoBox{position:relative;flex:1;min-height:300px;display:flex;align-items:center;justify-content:center;overflow:hidden}
video{max-width:100%;max-height:100%;display:block;background:#000}.overlay{position:absolute;inset:0;pointer-events:none}.el{position:absolute;pointer-events:auto;user-select:none;border:2px solid #2e86de;min-width:45px;min-height:25px}.el.sel{outline:2px solid #fff;box-shadow:0 0 0 3px #2563eb}.el.comment{padding:7px 10px;background:#fff;color:#111;border-color:#2563eb}.el.highlight{background:rgba(255,230,0,.18);border-color:#e0a800}.el.zoom{border-color:#a855f7;background:rgba(168,85,247,.12)}.handle{position:absolute;width:9px;height:9px;background:#fff;border:1px solid #333;right:-5px;bottom:-5px;cursor:nwse-resize}.skip{height:25px;background:#8b5cf6;border-radius:3px;position:absolute;top:38px;opacity:.8}.timeline{height:180px;background:#fff;border-radius:0 0 7px 7px;padding:10px 12px;position:relative;overflow:hidden}.ruler{height:27px;border-bottom:1px solid #ccd2d8;position:relative}.tick{position:absolute;top:0;height:27px;border-left:1px solid #aeb6bf;font-size:10px;color:#59636e;padding-left:3px}.tracks{position:relative;height:112px}.track{position:relative;height:35px}.bar{position:absolute;height:25px;top:5px;border-radius:3px;padding:3px 6px;font-size:11px;overflow:hidden;white-space:nowrap;background:#dbeafe;border:1px solid #3b82f6}.bar.sel{outline:2px solid #2563eb}.bar.skipbar{background:#ede9fe;border-color:#8b5cf6}.cursor{position:absolute;top:0;bottom:0;width:2px;background:#e11d48;z-index:20}.cursor:before{content:"";position:absolute;top:-3px;left:-5px;border:5px solid transparent;border-top-color:#e11d48}.zoomBtns{position:absolute;right:12px;top:8px;z-index:30}.zoomBtns button{border:1px solid #bbc2c9;background:#fff;padding:2px 8px}.home{display:grid;grid-template-columns:1fr 1fr;gap:12px;flex:1;min-height:0}.panel{background:#fff;border:1px solid #d8dde2;border-radius:7px;padding:14px;overflow:auto}.panel h2{font-size:16px;margin:0 0 10px}.empty{color:#68727d;padding:24px;text-align:center;border:1px dashed #c7cdd3;border-radius:6px}.item{border:1px solid #d7dce1;border-radius:6px;padding:10px;margin:7px 0;display:flex;align-items:center;gap:10px}.item:hover{background:#f8fafc}.item .info{flex:1}.item strong{display:block}.meta{font-size:12px;color:#68727d}.tag{font-size:11px;padding:3px 7px;border-radius:10px;background:#e8eef5}.tag.result{background:#fce7f3}.item button{border:1px solid #c7cdd3;background:#fff;border-radius:4px;padding:5px 8px}.load{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;align-items:center;justify-content:center}.load.show{display:flex}.dialog{background:#fff;border-radius:8px;padding:18px;width:min(480px,92vw);box-shadow:0 12px 40px #0004}.dialog h3{margin:0 0 12px}.dialog .row{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}.menu{position:fixed;z-index:200;background:#fff;border:1px solid #bcc3ca;border-radius:6px;box-shadow:0 8px 25px #0003;padding:6px;min-width:230px}.menu button,.menu select,.menu input{width:100%;margin:2px 0;padding:6px;border:1px solid #d1d6db;background:#fff;text-align:left}.menu label{font-size:11px;color:#606a74;display:block;margin-top:5px}.notice{font-size:12px;color:#59636e;margin:5px 0}.hidden{display:none!important}.bottomInfo{display:flex;gap:12px;align-items:center;font-size:12px;color:#64707b}.connectMode{background:#fff2cc;color:#7a4d00;border:1px solid #e5c66b;padding:5px 9px;border-radius:5px}
</style>
</head>
<body>
<header class="top">
<h1>動画編集</h1><span id="stateLabel" class="status">動画未選択</span><span id="workLabel"></span>
<div class="actions">
<button onclick="openVideoDialog()">動画を選択</button><button onclick="openWorkDialog()">編集作業を再開する</button>
<button id="saveBtn" class="hidden" onclick="saveWork()">編集作業を保存</button>
<button id="endBtn" class="hidden" onclick="endWork()">編集作業を終了する</button>
<button id="resultBtn" class="hidden" onclick="makeResult()">編集結果を動画にする</button>
</div></header>
<main>
<section id="home" class="home">
<div class="panel"><h2>動画</h2><div id="videoList"></div><div style="margin-top:10px"><button onclick="document.getElementById('file').click()">＋ オリジナル動画を追加</button><input id="file" type="file" accept="video/*" class="hidden" onchange="uploadVideo(this)"></div></div>
<div class="panel"><h2>保存済み編集作業</h2><div id="workList"></div><h2 style="margin-top:18px">編集結果動画</h2><div id="resultList"></div></div>
</section>

<section id="editor" class="editor">
<div class="stage">
<div id="videoBox" class="videoBox">
<video id="video" controls></video><div id="overlay" class="overlay"></div><svg id="lines" class="overlay"></svg>
</div>
<div id="timeline" class="timeline">
<div class="zoomBtns"><button onclick="scale(-1)">−</button><button onclick="scale(1)">＋</button></div>
<div id="ruler" class="ruler"></div><div id="tracks" class="tracks"></div><div id="cursor" class="cursor"></div>
</div></div>
</section>
<div id="bottomInfo" class="bottomInfo hidden"><span id="timeInfo"></span><span id="connectInfo"></span></div>
</main>
<div id="modal" class="load"><div class="dialog" id="dialog"></div></div>
<div id="menu" class="menu hidden"></div>
<script>
const state={videos:<?=json_encode($data['videos'],JSON_UNESCAPED_UNICODE)?>,works:<?=json_encode($data['works'],JSON_UNESCAPED_UNICODE)?>,results:<?=json_encode($data['results'],JSON_UNESCAPED_UNICODE)?>};
let video=null,work=null,items=[],lines=[],selected=[],dirty=false,connectFrom=null,scaleValue=1,drag=null,menuTarget=null;
const $=id=>document.getElementById(id), api=(u,o)=>fetch(u,o).then(r=>r.json());
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
function openVideoDialog(){showDialog(`<h3>動画を選択</h3><div id="chooseVideos"></div><div class="row"><button onclick="closeDialog()">閉じる</button></div>`);renderChooseVideos()}
function renderChooseVideos(){
 const a=[...state.videos,...state.results];$('chooseVideos').innerHTML=a.length?a.map(v=>`<div class="item"><div class="info"><strong>${esc(v.name)}</strong><span class="meta">${v.type==='result'?'編集結果動画':'オリジナル動画'}　${fmt(v.duration)}</span></div><button onclick="newWork('${v.id}','${v.type}')">新規編集</button><button onclick="removeVideo('${v.id}','${v.type}')">削除</button></div>`).join(''):'<div class="empty">動画がありません。オリジナル動画を追加してください。</div>';
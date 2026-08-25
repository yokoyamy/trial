<?php
declare(strict_types=1);

/* =========================================================
 * アンケート管理システム / index.php
 * 業務要件と実装要件を分離した単一ファイル実装
 * ========================================================= */

const SURVEY_STORAGE_DIRECTORY = __DIR__ . '/survey_storage';
const SURVEY_STORAGE_FILE      = SURVEY_STORAGE_DIRECTORY . '/survey_data.json';
const SURVEY_ADMIN_SESSION     = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

const DEFAULT_DATA = [
    'surveys'=>[], 'responses'=>[], 'customers'=>[],
    'settings'=>['kintone'=>[],'smtp'=>[]], 'mail_logs'=>[]
];

function isApi(): bool {
    return isset($_REQUEST['action']);
}
function jsonOut(array $data, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function ok(array $data=[]): never {
    jsonOut(array_merge(['ok'=>true],$data));
}
function fail(string $message,string $type='application',int $status=400,array $extra=[]): never {
    jsonOut(array_merge([
        'ok'=>false,'message'=>$message,'error_type'=>$type
    ],$extra),$status);
}
function csrf(): string {
    return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}
function requireAdmin(): void {
    if (empty($_SESSION['authenticated'])) {
        fail('管理画面への認証が必要です。','authentication',401);
    }
}
function requireCsrf(): void {
    if (!hash_equals($_SESSION['csrf_token'] ?? '',(string)($_REQUEST['csrf_token']??''))) {
        fail('セキュリティ確認に失敗しました。画面を再読み込みしてください。','csrf',403);
    }
}
function storageInit(): void {
    if (!is_dir(SURVEY_STORAGE_DIRECTORY) &&
        !mkdir(SURVEY_STORAGE_DIRECTORY,0750,true) &&
        !is_dir(SURVEY_STORAGE_DIRECTORY)) {
        throw new RuntimeException('データ保存領域を作成できません。');
    }
    if (!file_exists(SURVEY_STORAGE_FILE)) saveData(DEFAULT_DATA);
}
function normalizeData(array $d): array {
    foreach (['surveys','responses','customers','mail_logs'] as $k)
        if (!isset($d[$k]) || !is_array($d[$k])) $d[$k]=[];
    if (!isset($d['settings']) || !is_array($d['settings'])) $d['settings']=[];
    foreach(['kintone','smtp'] as $k)
        if (!isset($d['settings'][$k]) || !is_array($d['settings'][$k]))
            $d['settings'][$k]=[];
    return $d;
}
function loadData(): array {
    storageInit();
    $raw=file_get_contents(SURVEY_STORAGE_FILE);
    if ($raw===false || trim($raw)==='') return DEFAULT_DATA;
    try {
        $d=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        return is_array($d)?normalizeData($d):DEFAULT_DATA;
    } catch(Throwable $e) {
        throw new RuntimeException('保存データのJSONを読み込めません。');
    }
}
function saveData(array $d): void {
    storageInit();
    $d=normalizeData($d);
    $json=json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    $tmp=tempnam(SURVEY_STORAGE_DIRECTORY,'.survey_');
    if ($tmp===false || file_put_contents($tmp,$json,LOCK_EX)===false) {
        if ($tmp) @unlink($tmp);
        throw new RuntimeException('データ保存に失敗しました。');
    }
    if (!rename($tmp,SURVEY_STORAGE_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('データ更新に失敗しました。');
    }
}
function id(string $prefix='id'): string {
    return $prefix.'_'.bin2hex(random_bytes(5));
}
function post(string $k,string $default=''): string {
    return trim((string)($_POST[$k]??$default));
}
function safeSettings(array $s): array {
    foreach(['kintone','smtp'] as $type) {
        if (!isset($s[$type])) continue;
        foreach(array_keys($s[$type]) as $k) {
            if (in_array($k,['password','smtp_password'],true)) {
                unset($s[$type][$k]);
            }
        }
    }
    return $s;
}

/* ---------------- 認証 ---------------- */

if (isset($_GET['login'])) {
    $_SESSION['authenticated']=true;
    csrf();
    header('Location: '.strtok($_SERVER['REQUEST_URI'],'?'));
    exit;
}
if (isset($_GET['logout'])) {
    $_SESSION=[];
    session_destroy();
    header('Location: '.strtok($_SERVER['REQUEST_URI'],'?'));
    exit;
}

/* ---------------- API ---------------- */

function requestData(): array {
    $raw=(string)($_POST['survey_json']??'');
    if ($raw==='') fail('アンケートデータがありません。');
    try {
        $d=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    } catch(Throwable) {
        fail('アンケートデータの形式が不正です。','json');
    }
    if (!is_array($d)) fail('アンケートデータが不正です。');
    return $d;
}

function findSurvey(array &$data,string $id): ?array {
    foreach($data['surveys'] as $i=>$s)
        if (($s['id']??'')===$id) return $i;
    return null;
}

function kintoneConfig(array $s): array {
    return array_merge([
        'subdomain'=>'','login_name'=>'','password'=>'','app_id'=>'',
        'ssl_verify'=>false,'proxy'=>''
    ],$s['settings']['kintone']??[]);
}
function normalizeKintoneHost(string $v): string {
    $v=trim($v);
    if ($v==='') return '';
    if (!preg_match('~^https?://~i',$v)) $v='https://'.$v;
    $u=parse_url($v);
    $host=$u['host']??'';
    if (!$host && preg_match('~^([^/]+)~',$v,$m)) $host=$m[1];
    $host=preg_replace('~\.cybozu\.com$~i','',$host);
    return $host!==''?'https://'.$host.'.cybozu.com':'';
}
function kurl(array $c,string $path): string {
    return rtrim(normalizeKintoneHost($c['subdomain']),'/').'/k/v1/'.$path;
}
function kcurl(array $c,string $method,string $path,?array $body=null): array {
    if (!$c['subdomain'] || !$c['app_id'])
        throw new RuntimeException('キントーンのサブドメインと顧客管理アプリIDを設定してください。');

    $ch=curl_init(kurl($c,$path));
    $headers=['X-Cybozu-Authorization: '.base64_encode($c['login_name'].':'.$c['password']),
              'Content-Type: application/json'];
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>(bool)$c['ssl_verify'],
        CURLOPT_SSL_VERIFYHOST=>$c['ssl_verify']?2:0
    ]);
    if ($c['proxy']) curl_setopt($ch,CURLOPT_PROXY,$c['proxy']);
    if ($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE));
    $raw=curl_exec($ch);
    $errno=curl_errno($ch); $err=curl_error($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) throw new RuntimeException(
        match($errno){
            CURLE_COULDNT_RESOLVE_HOST=>'DNSで接続先を解決できません。',
            CURLE_COULDNT_CONNECT=>'キントーンへ接続できません。',
            CURLE_OPERATION_TIMEDOUT=>'キントーン通信がタイムアウトしました。',
            CURLE_SSL_CONNECT_ERROR=>'TLS接続に失敗しました。',
            default=>'キントーン通信に失敗しました。'
        }
    );
    $json=json_decode((string)$raw,true);
    if ($status<200 || $status>=300) {
        $msg=match($status){
            401=>'認証に失敗しました。ログイン名・パスワードを確認してください。',
            403=>'権限がありません。キントーンアプリの権限を確認してください。',
            404=>'指定されたアプリまたはAPIが見つかりません。',
            408=>'キントーンへの通信がタイムアウトしました。',
            429=>'キントーンからアクセス制限を受けました。',
            500,502,503,504=>'キントーン側で一時的なエラーが発生しました。',
            default=>'キントーンからエラー応答が返されました。'
        };
        throw new RuntimeException($msg);
    }
    return ['status'=>$status,'data'=>is_array($json)?$json:[]];
}

function smtpConfig(array $d): array {
    return array_merge([
        'smtp_server'=>'','smtp_port'=>'587','smtp_encryption'=>'starttls',
        'smtp_auth'=>'true','smtp_username'=>'','smtp_password'=>'',
        'smtp_from_email'=>'','smtp_from_name'=>'','smtp_timeout'=>'15'
    ],$d['settings']['smtp']??[]);
}

/* SMTP最小実装 */
function smtpConnect(array $c): array {
    if (!$c['smtp_server']) throw new RuntimeException('SMTPサーバを設定してください。');
    $port=(int)$c['smtp_port']?:25;
    $timeout=(float)$c['smtp_timeout']?:15;
    $host=$c['smtp_server'];
    if ($c['smtp_encryption']==='ssl') $host='ssl://'.$host;
    $errno=0;$err='';
    $fp=@stream_socket_client("$host:$port",$errno,$err,$timeout);
    if (!$fp) {
        $type=$errno===0?'connection':'connection';
        throw new RuntimeException('SMTPサーバへ接続できません。');
    }
    stream_set_timeout($fp,(int)$timeout);
    $read=function()use($fp){
        $s='';
        while(($l=fgets($fp))!==false){
            $s.=$l;
            if (isset($l[3])&&$l[3]===' ') break;
        }
        return $s;
    };
    $send=function(string $s)use($fp,$read){
        fwrite($fp,$s."\r\n"); return $read();
    };
    $banner=$read();
    if (substr($banner,0,3)!=='220') throw new RuntimeException('SMTP応答が不正です。');
    $eh=$send('EHLO localhost');
    if ($c['smtp_encryption']==='starttls') {
        $r=$send('STARTTLS');
        if (substr($r,0,3)!=='220') throw new RuntimeException('STARTTLSを開始できません。');
        if (!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))
            throw new RuntimeException('TLS暗号化に失敗しました。');
        $eh=$send('EHLO localhost');
    }
    if (($c['smtp_auth']!=='false') && $c['smtp_username']) {
        if (substr($send('AUTH LOGIN'),0,3)!=='334') throw new RuntimeException('SMTP認証を開始できません。');
        if (substr($send(base64_encode($c['smtp_username'])),0,3)!=='334')
            throw new RuntimeException('SMTPユーザー名が拒否されました。');
        if (substr($send(base64_encode($c['smtp_password'])),0,3)!=='235')
            throw new RuntimeException('SMTP認証に失敗しました。');
    }
    return [$fp,$send,$read];
}

/* API認証 */
if (isApi()) {
    try {
        $action=(string)($_REQUEST['action']??'');
        if ($action==='get_initial_data') {
            $d=loadData();
            $d['settings']=safeSettings($d['settings']);
            ok(['data'=>$d,'csrf_token'=>csrf()]);
        }

        requireAdmin();

        $mutating=!in_array($action,[
            'connect_kintone','fetch_kintone_fields','test_smtp_connection'
        ],true);
        if ($mutating) requireCsrf();

        $d=loadData();

        switch($action) {

        case 'save_kintone_settings':
            $old=$d['settings']['kintone']??[];
            $new=$old;
            $keys=['subdomain','login_name','app_id','proxy','field_company',
                   'field_name','field_email','field_department','field_phone','field_address'];
            foreach($keys as $k) $new[$k]=post('setting_'.$k);
            $new['ssl_verify']=isset($_POST['setting_ssl_verify']) &&
                                $_POST['setting_ssl_verify']==='true';
            $p=post('setting_password');
            if ($p!=='') $new['password']=$p;
            $d['settings']['kintone']=$new;
            saveData($d);
            ok(['message'=>'キントーン設定を保存しました。']);
        case 'save_smtp_settings':
            $old=$d['settings']['smtp']??[];
            $new=$old;
            foreach([
                'smtp_server','smtp_port','smtp_encryption','smtp_auth',
                'smtp_username','smtp_from_email','smtp_from_name','smtp_timeout'
            ] as $k) $new[$k]=post($k);
            $p=post('smtp_password');
            if ($p!=='') $new['smtp_password']=$p;
            $d['settings']['smtp']=$new;
            saveData($d);
            ok(['message'=>'SMTP設定を保存しました。']);

        case 'connect_kintone':
            $c=kintoneConfig($d);
            $r=kcurl($c,'GET','app.json?app='.rawurlencode($c['app_id']));
            ok(['message'=>'キントーンへの接続に成功しました。','http_status'=>$r['status']]);
        case 'fetch_kintone_fields':
            $c=kintoneConfig($d);
            $r=kcurl($c,'GET','app/form/fields.json?app='.rawurlencode($c['app_id']));
            $fields=[];
            foreach(($r['data']['properties']??[]) as $code=>$f)
                $fields[]=['code'=>$code,'label'=>$f['label']??$code,'type'=>$f['type']??''];
            ok(['message'=>'フィールドを取得しました。','fields'=>$fields,'http_status'=>$r['status']]);
        case 'sync_customers':
            $c=kintoneConfig($d);
            $r=kcurl($c,'GET','records.json?app='.rawurlencode($c['app_id']).'&query='.rawurlencode('limit 500'));
            $records=$r['data']['records']??[];
            $count=0;$inserted=0;$updated=0;
            foreach($records as $rec){
                $email=(string)($rec[$c['field_email']??'']['value']??'');
                $name=(string)($rec[$c['field_name']??'']['value']??'');
                if (!$email && !$name) continue;
                $found=null;
                foreach($d['customers'] as $i=>$x)
                    if($email && ($x['email']??'')===$email){$found=$i;break;}
                $item=['id'=>$found!==null?$d['customers'][$found]['id']:id('customer'),
                       'name'=>$name,'email'=>$email,
                       'company'=>(string)($rec[$c['field_company']??'']['value']??''),
                       'department'=>(string)($rec[$c['field_department']??'']['value']??''),
                       'phone'=>(string)($rec[$c['field_phone']??'']['value']??''),
                       'address'=>(string)($rec[$c['field_address']??'']['value']??'')];
                if($found===null){$d['customers'][]=$item;$inserted++;}
                else{$d['customers'][$found]=array_merge($d['customers'][$found],$item);$updated++;}
                $count++;
            }
            saveData($d);
            ok(['message'=>'顧客データを同期しました。','count'=>$count,
                'inserted'=>$inserted,'updated'=>$updated,'skipped'=>count($records)-$count,'errors'=>[]]);

        case 'test_smtp_connection':
            [$fp,$send,$read]=smtpConnect(smtpConfig($d));
            $send('QUIT');fclose($fp);
            ok(['message'=>'SMTPサーバへの接続に成功しました。']);

        case 'send_smtp_test':
            requireCsrf();
            $to=filter_var(post('test_email'),FILTER_VALIDATE_EMAIL);
            if(!$to) fail('テスト送信先メールアドレスを確認してください。');
            $c=smtpConfig($d);
            [$fp,$send,$read]=smtpConnect($c);
            $from=$c['smtp_from_email'];
            $name=$c['smtp_from_name'];
            $send("MAIL FROM:<$from>");
            $r=$send("RCPT TO:<$to>");
            if(strncmp($r,'250',3)!==0 && strncmp($r,'251',3)!==0)
                throw new RuntimeException('SMTPが宛先を受け付けませんでした。');
            $send('DATA');
            $subject='=?UTF-8?B?'.base64_encode('アンケート管理システム SMTP送信テスト').'?=';
            $body="From: ".($name?$name.' ':'')."<$from>\r\nTo: <$to>\r\n".
                  "Subject: $subject\r\nContent-Type: text/plain; charset=UTF-8\r\n".
                  "Content-Transfer-Encoding: 8bit\r\n\r\nSMTP接続テストです。\r\n.";
            $send($body);$send('QUIT');fclose($fp);
            ok(['message'=>'テストメールを送信しました。']);

        case 'saveSurvey':
            $s=requestData();
            $status=$s['status']??'draft';
            if(!in_array($status,['draft','active','ended'],true))
                fail('不正なステータスです。');
            $s['id']=$s['id']??id('survey');
            $s['updated_at']=date('c');
            $idx=findSurvey($d,$s['id']);
            if($idx===null){$s['created_at']=date('c');$d['surveys'][]=$s;}
            else $d['surveys'][$idx]=$s;
            saveData($d);
            ok(['survey'=>$s]);

        case 'deleteSurvey':
            $idx=findSurvey($d,post('survey_id'));
            if($idx===null) fail('アンケートが見つかりません。', 'not_found',404);
            $d['surveys'][$idx]['deleted']=true;
            $d['surveys'][$idx]['deleted_at']=date('c');
            saveData($d);ok(['message'=>'アンケートを削除しました。']);

        case 'duplicateSurvey':
            $idx=findSurvey($d,post('survey_id'));
            if($idx===null) fail('アンケートが見つかりません。','not_found',404);
            $s=$d['surveys'][$idx];
            $s['id']=id('survey');$s['title']=($s['title']??'アンケート').'（複製）';
            $s['status']='draft';$s['created_at']=date('c');$s['updated_at']=date('c');
            $d['surveys'][]=$s;saveData($d);ok(['survey'=>$s]);

        case 'saveResponse':
            $r=json_decode(post('response_json'),true);
            if(!is_array($r)) fail('回答データが不正です。');
            $r['id']=$r['id']??id('response');$r['created_at']=date('c');
            $d['responses'][]=$r;saveData($d);ok(['response'=>$r]);

        case 'logout':
            $_SESSION=[];ok(['message'=>'ログアウトしました。']);

        default:
            fail('指定されたAPIは存在しません。','not_found',404);
        }
    } catch(Throwable $e) {
        error_log('[survey] '.$e->getMessage());
        fail($e->getMessage(),'server',500);
    }
}

/* 初回表示 */
$token=csrf();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理</title>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<style>
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
sans-serif;background:#f5f7fa;color:#222}header{position:sticky;top:0;z-index:10;background:#16324f;
color:#fff;padding:13px 22px;display:flex;gap:24px;align-items:center}header b{margin-right:auto}
button,.btn{border:0;border-radius:6px;padding:8px 13px;background:#1769aa;color:#fff;cursor:pointer}
button.gray{background:#667085}button.red{background:#c62828}button.green{background:#258b4a}
button:disabled{opacity:.5;cursor:not-allowed}main{max-width:1280px;margin:auto;padding:22px}
.card{background:#fff;border-radius:9px;padding:18px;margin-bottom:16px;box-shadow:0 1px 4px #0001}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
label{display:block;font-weight:600;margin-bottom:5px}input,select,textarea{width:100%;padding:9px;
border:1px solid #ccd3dc;border-radius:5px;background:#fff}textarea{min-height:100px}
.nav{display:flex;gap:8px;margin-bottom:15px;flex-wrap:wrap}.survey{border:1px solid #ddd;padding:15px;
border-radius:7px;background:#fff;margin-bottom:10px}.group{border:2px solid #d8e0e8;padding:12px;
margin:12px 0;border-radius:8px;background:#f9fbfd}.question{padding:12px;background:#fff;
border:1px solid #ddd;border-radius:6px;margin:8px 0;cursor:move}.option{display:grid;
grid-template-columns:1fr 220px;gap:8px;margin:6px 0}.message{padding:12px;border-radius:6px;
margin:10px 0;background:#eef5ff}.error{background:#fff0f0;color:#9b1c1c}.success{background:#effaf2;color:#146c2e}
.modal{position:fixed;inset:0;background:#0008;z-index:30;padding:5vh 5vw;overflow:auto}
.modal>div{background:#fff;max-width:1000px;margin:auto;padding:20px;border-radius:10px}
.hidden{display:none!important}.status{font-size:12px;padding:3px 7px;border-radius:12px;background:#eee}
pre{white-space:pre-wrap;background:#f3f4f6;padding:12px;border-radius:5px}
@media(max-width:700px){.grid{grid-template-columns:1fr}.option{grid-template-columns:1fr}}
</style>
</head>
<body>
<header>
<b>アンケート管理</b>
<button onclick="App.actions.list()">アンケート一覧</button>
<button onclick="App.actions.settings()">キントーン・メール設定</button>
<a href="?logout=1" style="color:#fff">ログアウト</a>
</header>
<div id="app"></div>

<script>
'use strict';

window.APP_CONFIG={api:<?=json_encode($_SERVER['SCRIPT_NAME'],JSON_UNESCAPED_SLASHES)?>,
csrf:<?=json_encode($token)?>};

window.App={
state:{
 initialized:false,loading:true,error:null,view:'list',
 surveys:[],responses:[],customers:[],mail_logs:[],
 settings:{kintone:{},smtp:{}},answers:{},survey:null,
 editing:false,preview:false
},

utils:{
 escapeHTML(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;',
 '>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))},
 esc(v){return this.escapeHTML(v)},
 id(p='id'){return p+'_'+Math.random().toString(36).slice(2,10)},
 clone(v){return JSON.parse(JSON.stringify(v))},
 json(v){return JSON.stringify(v,null,2)},
 status(v){return {draft:'下書き',active:'公開中',ended:'終了'}[v]||v},
 fmt(v){return v?new Date(v).toLocaleString('ja-JP'):''}
},

api:{
 async request(action,data={},timeout=20000){
   const ctrl=new AbortController(),timer=setTimeout(()=>ctrl.abort(),timeout);
   const body=new URLSearchParams({action,...data});
   let r,text='';
   try{
     r=await fetch(window.APP_CONFIG.api,{method:'POST',body,credentials:'same-origin',
       headers:{'Accept':'application/json'},signal:ctrl.signal});
   }catch(e){
     clearTimeout(timer);
     if(e.name==='AbortError')throw new Error('サーバーからの応答がタイムアウトしました。');
     throw new Error('サーバーへ接続できません。ネットワーク接続を確認してください。');
   }
   clearTimeout(timer);
   text=await r.text();
   if(!r.ok){
     let j;try{j=JSON.parse(text)}catch{}
     throw new Error((j&&j.message)||`サーバーエラーが発生しました。（HTTP ${r.status}）`);
   }
   if(!r.headers.get('content-type')?.includes('application/json'))
     throw new Error('サーバーからJSONではない応答が返されました。');
   let j;try{j=JSON.parse(text)}catch{throw new Error('サーバー応答のJSON解析に失敗しました。')}
   if(!j.ok)throw new Error(j.message||'サーバー処理に失敗しました。');
   return j;
 },
 getInitialData(){return this.request('get_initial_data')},
 saveSurvey(s){return this.request('saveSurvey',{survey_json:JSON.stringify(s),csrf_token:APP_CONFIG.csrf})},
 saveKintone(d){return this.request('save_kintone_settings',{...d,csrf_token:APP_CONFIG.csrf})},
 saveSmtp(d){return this.request('save_smtp_settings',{...d,csrf_token:APP_CONFIG.csrf})}
},

render:{
 root(){
   const a=App;
   if(a.state.loading)return `<main><div class="card"><h2>読み込み中...</h2></div></main>`;
   if(a.state.error)return `<main><div class="card error"><h2>初期データの取得に失敗しました。</h2>
   <p>${a.utils.esc(a.state.error)}</p><button onclick="App.init(true)">再試行</button></div></main>`;
   if(a.state.view==='settings')return this.settings();
   if(a.state.view==='edit')return this.editor();
   if(a.state.view==='preview')return this.preview();
   if(a.state.view==='response')return this.response();
   if(a.state.view==='aggregate')return this.aggregate();
   return this.list();
 },

 list(){
   const s=App.state.surveys.filter(x=>!x.deleted);
   return `<main><div class="nav"><button onclick="App.actions.newSurvey()">＋新規作成</button></div>
   <div class="card"><h2>アンケート一覧</h2>${s.length?s.map(App.render.surveyRow).join(''):
   '<p>アンケートはありません。</p>'}</div></main>`;
 },

 surveyRow(s){
   const e=App.utils.esc;
   let b=`<button onclick="App.actions.edit('${s.id}')">確認・編集</button>
   <button onclick="App.actions.aggregate('${s.id}')">集計</button>`;
   if(s.status==='active')b+=`<button onclick="App.actions.mail('${s.id}')">送信</button>`;
   if(s.status==='draft')b+=`<button class="red" onclick="App.actions.deleteSurvey('${s.id}')">削除</button>`;
   b+=`<button class="gray" onclick="App.actions.duplicate('${s.id}')">複製</button>`;
   return `<div class="survey"><b>${e(s.title||'無題')}</b>
   <span class="status">${e(App.utils.status(s.status))}</span>
   <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">${b}</div></div>`;
 },

 editor(){
   const s=App.state.survey,e=App.utils.esc;
   if(!s)return '';
   return `<main><div class="nav"><button onclick="App.actions.list()">← 一覧</button>
   <button onclick="App.actions.preview()">プレビュー</button>
   <button class="green" onclick="App.actions.saveSurvey()">保存</button></div>
   <div class="card"><h2>アンケート作成・編集</h2>
   <div class="grid">
   <div><label>タイトル</label><input id="survey_title" value="${e(s.title)}"
   onchange="App.actions.change('title',this.value)"></div>
   <div><label>ステータス</label><select id="survey_status" onchange="App.actions.changeStatus(this.value)">
   ${['draft','active','ended'].map(x=>`<option value="${x}" ${s.status===x?'selected':''}>
   ${App.utils.status(x)}</option>`).join('')}</select></div>
   <div><label>開始日時</label><input id="survey_start_at" type="datetime-local" value="${e(s.start_at||'')}"
   onchange="App.actions.change('start_at',this.value)"></div>
   <div><label>終了日時</label><input id="survey_end_at" type="datetime-local" value="${e(s.end_at||'')}"
   onchange="App.actions.change('end_at',this.value)"></div>
   <div><label>質問番号形式</label><select id="survey_numbering_mode"
   onchange="App.actions.change('numbering_mode',this.value)">
   <option value="global" ${s.numbering_mode!=='group'?'selected':''}>Q1形式</option>
   <option value="group" ${s.numbering_mode==='group'?'selected':''}>Q1-1形式</option></select></div>
   <div><label><input type="checkbox" ${s.general_answer?'checked':''}
   onchange="App.actions.change('general_answer',this.checked)"> 一般回答を許可</label></div>
   </div></div>
   <div id="question_editor">${this.groups()}</div></main>`;
 },

 groups(){
   const s=App.state.survey,e=App.utils.esc;
   return `${s.groups.map((g,gi)=>`<div class="group" data-group-id="${g.id}">
   <div style="display:flex;gap:8px"><input value="${e(g.name)}"
   onchange="App.actions.groupName('${g.id}',this.value)">
   <button class="red" onclick="App.actions.deleteGroup('${g.id}')">削除</button></div>
   <div class="questions">${g.questions.map((q,qi)=>App.render.question(q,gi,qi)).join('')}</div>
   <button onclick="App.actions.addQuestion('${g.id}')">＋ 質問を追加</button>
   </div>`).join('')}
   <button onclick="App.actions.addGroup()">＋ ブロックを追加</button>`;
 },

 question(q,gi,qi){
   const e=App.utils.esc,s=App.state.survey;
   const all=App.actions.allQuestions(),index=all.findIndex(x=>x.id===q.id);
   const later=all.slice(index+1);
   let opts=q.type==='single'?`<div>${(q.options||[]).map((o,i)=>`
   <div class="option"><input value="${e(o.text)}"
   onchange="App.actions.option('${q.id}','${o.id}',this.value)">
   <select onchange="App.actions.branch('${q.id}','${o.id}',this.value)">
   <option value="">分岐なし</option>${later.map(x=>`<option value="${x.id}"
   ${q.branching?.[o.id]===x.id?'selected':''}>${e(x.number)} ${e(x.text)}</option>`).join('')}
   </select></div>`).join('')}
   <button onclick="App.actions.addOption('${q.id}')">＋ 選択肢</button></div>`:'';
   return `<div class="question" data-question-id="${q.id}">
   <div><b>${e(q.number||'')}</b></div>
   <input value="${e(q.text)}" placeholder="質問文"
   onchange="App.actions.questionText('${q.id}',this.value)">
   <div class="grid">
   <select onchange="App.actions.questionType('${q.id}',this.value)">
   ${['text','textarea','single','multiple','number','date'].map(x=>`<option ${q.type===x?'selected':''}
   value="${x}">${x}</option>`).join('')}</select>
   <label><input type="checkbox" ${q.required?'checked':''}
   onchange="App.actions.required('${q.id}',this.checked)"> 必須回答</label></div>
   ${opts}<button class="red" onclick="App.actions.deleteQuestion('${q.id}')">質問削除</button>
   </div>`;
 },

 settings(){
   const k=App.state.settings.kintone||{},m=App.state.settings.smtp||{},e=App.utils.esc;
   return `<main><div class="card"><div>ホーム ＞ キントーン・メール設定</div>
   <h2>キントーン・メール設定</h2>
   <form id="kintone_settings_form" onsubmit="App.actions.saveKintoneSettings();return false">
   <h3>キントーン設定</h3><div class="grid">
   ${App.render.field('setting_subdomain','サブドメイン',k.subdomain)}
   ${App.render.field('setting_login_name','ログイン名',k.login_name)}
   ${App.render.field('setting_password','パスワード','',true,'変更しない場合は空欄')}
   ${App.render.field('setting_app_id','顧客管理アプリID',k.app_id)}
   ${App.render.field('setting_proxy','Proxy',k.proxy,'','host名:port番号')}
   <div><label>SSL証明書検証</label><select id="setting_ssl_verify">
   <option value="false" ${!k.ssl_verify?'selected':''}>検証なし</option>
   <option value="true" ${k.ssl_verify?'selected':''}>検証する</option></select></div>
   ${[['field_company','会社名'],['field_name','氏名'],['field_email','メール'],
   ['field_department','部署'],['field_phone','電話'],['field_address','住所']].map(x=>App.render.field(x[0],x[1],k[x[0]])).join('')}
   </div><div id="kintone_message"></div>
   <button id="kintone_save_button" class="green">設定を保存</button>
   <button type="button" onclick="App.actions.connectKintone()">キントーン接続確認</button>
   <button type="button" onclick="App.actions.fetchKintoneFields()">フィールド取得</button>
   <button type="button" onclick="App.actions.syncCustomers()">顧客データを同期</button>
   <div id="kintone_connection_result"></div></form></div>
   <div class="card"><form id="smtp_settings_form" onsubmit="App.actions.saveSmtpSettings();return false">
   <h3>SMTP設定</h3><div class="grid">
   ${App.render.field('smtp_server','SMTPサーバ',m.smtp_server)}
   ${App.render.field('smtp_port','SMTPポート',m.smtp_port)}
   <div><label>暗号化方式</label><select id="smtp_encryption">
   ${['none','starttls','ssl'].map(x=>`<option ${m.smtp_encryption===x?'selected':''}>${x}</option>`).join('')}</select></div>
   <div><label>SMTP認証</label><select id="smtp_auth"><option value="true">あり</option>
   <option value="false" ${m.smtp_auth==='false'?'selected':''}>なし</option></select></div>
   ${App.render.field('smtp_username','SMTPユーザー名',m.smtp_username)}
   ${App.render.field('smtp_password','SMTPパスワード','',true,'変更しない場合は空欄')}
   ${App.render.field('smtp_from_email','送信元メールアドレス',m.smtp_from_email)}
   ${App.render.field('smtp_from_name','送信元表示名',m.smtp_from_name)}
   ${App.render.field('smtp_timeout','接続タイムアウト',m.smtp_timeout)}
   </div><div id="smtp_message"></div>
   <button id="smtp_save_button" class="green">設定を保存</button>
   <button type="button" onclick="App.actions.testSmtp()">SMTP接続確認</button>
   <input id="test_email" type="email" placeholder="テスト送信先">
   <button type="button" onclick="App.actions.testMail()">テストメール送信</button>
   <div id="smtp_connection_result"></div></form></div></main>`;
 },
 field(id,label,val='',password=false,placeholder=''){
   return `<div><label>${label}</label><input id="${id}" ${password?'type="password"':''}
   value="${App.utils.esc(val)}" placeholder="${placeholder}"></div>`;
 },

 preview(){
   const s=App.state.survey;
   return `<main><div class="card"><button onclick="App.actions.edit('${s.id}')">← 編集へ</button>
   <h2>${App.utils.esc(s.title)}</h2>${s.groups.map(g=>`<section><h3>${App.utils.esc(g.name)}</h3>
   ${g.questions.map(q=>`<div class="card"><b>${App.utils.esc(q.number)}</b>
   <p>${App.utils.esc(q.text)}</p></div>`).join('')}</section>`).join('')}</div></main>`;
 },

 response(){
   const s=App.state.survey;
   if(!s)return '';
   return `<main><div class="card"><h2>${App.utils.esc(s.title)}</h2>
   ${App.actions.visibleQuestions().map(q=>`<div class="card"><label>${App.utils.esc(q.number)}
   ${q.required?' *':''}<br>${App.utils.esc(q.text)}</label>
   ${App.render.answerInput(q)}</div>`).join('')}
   <button class="green" onclick="App.actions.submitResponse()">回答を送信</button></div></main>`;
 },

 answerInput(q){
   const e=App.utils.esc,v=App.state.answers[q.id]??'';
   if(q.type==='single')return (q.options||[]).map(o=>`<label>
   <input type="radio" name="a_${q.id}" ${v===o.id?'checked':''}
   onchange="App.actions.answer('${q.id}','${o.id}')"> ${e(o.text)}</label>`).join('');
   if(q.type==='multiple')return (q.options||[]).map(o=>`<label>
   <input type="checkbox" ${Array.isArray(v)&&v.includes(o.id)?'checked':''}
   onchange="App.actions.answerMulti('${q.id}','${o.id}',this.checked)"> ${e(o.text)}</label>`).join('');
   return `<textarea onchange="App.actions.answer('${q.id}',this.value)">${e(v)}</textarea>`;
 },

 aggregate(){
   const s=App.state.survey;
   const rs=App.state.responses.filter(r=>r.survey_id===s.id);
   return `<main><div class="card"><button onclick="App.actions.list()">← 一覧</button>
   <h2>集計：${App.utils.esc(s.title)}</h2><p>回答数：${rs.length}</p>
   ${App.actions.allQuestions().map(q=>`<div class="card"><b>${App.utils.esc(q.number)}
   ${App.utils.esc(q.text)}</b><p>${App.utils.esc(JSON.stringify(
   rs.map(r=>r.answers?.[q.id]).filter(v=>v!==undefined)) )}</p></div>`).join('')}
   <button onclick="App.actions.csv()">CSV</button>
   <button onclick="window.print()">PDF印刷</button></div></main>`;
 }
},

actions:{
 list(){App.state.view='list';App.state.survey=null;App.renderApp()},
 settings(){App.state.view='settings';App.renderApp()},
 newSurvey(){
   App.state.survey={id:App.utils.id('survey'),title:'新しいアンケート',status:'draft',
   start_at:'',end_at:'',numbering_mode:'global',general_answer:false,
   groups:[{id:App.utils.id('group'),name:'グループ1',questions:[]}]};
   App.state.view='edit';App.renderApp();
 },
 edit(id){
   const s=App.state.surveys.find(x=>x.id===id);
   if(!s)return;
   App.state.survey=App.utils.clone(s);App.state.view='edit';App.renderApp();
 },
 aggregate(id){
   const s=App.state.surveys.find(x=>x.id===id);if(!s)return;
   App.state.survey=s;App.state.view='aggregate';App.renderApp();
 },
 mail(id){alert('メール送信画面は保存済みSMTP設定を使用します。');},
 change(k,v){App.state.survey[k]=v},
 changeStatus(v){
   const old=App.state.survey.status;
   if(old==='active'&&v==='ended'&&!confirm('このアンケートを終了状態に変更しますか？'))return App.renderApp();
   if(old==='ended'&&v==='active'&&!confirm('このアンケートを公開状態に変更しますか？'))return App.renderApp();
   App.state.survey.status=v;
 },
 groupName(id,v){const g=App.state.survey.groups.find(x=>x.id===id);if(g)g.name=v},
 addGroup(){
   App.state.survey.groups.push({id:App.utils.id('group'),
   name:'グループ'+(App.state.survey.groups.length+1),questions:[]});
   this.renumberQuestions();App.renderApp();App.initSortable();
 },
 deleteGroup(id){
   if(App.state.survey.groups.length<=1)return alert('グループは1つ以上必要です。');
   if(!confirm('このグループを削除しますか？'))return;
   App.state.survey.groups=App.state.survey.groups.filter(g=>g.id!==id);
   this.cleanupBranches();this.renumberQuestions();App.renderApp();App.initSortable();
 },
 addQuestion(gid){
   const g=App.state.survey.groups.find(x=>x.id===gid);if(!g)return;
   g.questions.push({id:App.utils.id('question'),text:'',type:'text',required:false,
   options:[],other_enabled:false,branching:{}});
   this.renumberQuestions();App.renderApp();App.initSortable();
 },
 deleteQuestion(id){
   App.state.survey.groups.forEach(g=>g.questions=g.questions.filter(q=>q.id!==id));
   this.cleanupBranches();this.renumberQuestions();App.renderApp();App.initSortable();
 },
 allQuestions(){return App.state.survey.groups.flatMap(g=>g.questions)},
 findQ(id){return this.allQuestions().find(q=>q.id===id)},
 questionText(id,v){const q=this.findQ(id);if(q)q.text=v},
 questionType(id,v){const q=this.findQ(id);if(q){q.type=v;if(v!=='single'){q.options=[];q.branching={}}}App.renderApp();App.initSortable()},
 required(id,v){const q=this.findQ(id);if(q)q.required=v},
 addOption(id){
   const q=this.findQ(id);if(!q)return;
   q.options.push({id:App.utils.id('option'),text:'選択肢'+(q.options.length+1)});
   q.branching[q.options.at(-1).id]=null;App.renderApp();App.initSortable();
 },
 option(qid,oid,v){const q=this.findQ(qid),o=q?.options.find(x=>x.id===oid);if(o)o.text=v},
 branch(qid,oid,v){const q=this.findQ(qid);if(q)q.branching[oid]=v||null},
 cleanupBranches(){
   const ids=new Set(this.allQuestions().map(q=>q.id));
   this.allQuestions().forEach(q=>{
     Object.keys(q.branching||{}).forEach(k=>{
       if(q.branching[k]&&!ids.has(q.branching[k]))q.branching[k]=null;
     });
   });
 },
 renumberQuestions(){
   let n=0;
   App.state.survey.groups.forEach((g,gi)=>g.questions.forEach((q,qi)=>{
     q.number=App.state.survey.numbering_mode==='group'?`Q${gi+1}-${qi+1}`:`Q${++n}`;
   }));
 },
 initSortable(){
   document.querySelectorAll('.questions').forEach(el=>{
     if(el._sortable)el._sortable.destroy();
     el._sortable=Sortable.create(el,{group:'questions',animation:150,onEnd:()=>{
       const groups=[...document.querySelectorAll('.group')];
       const map=new Map(App.state.survey.groups.map(g=>[g.id,g]));
       groups.forEach(el=>{
         const g=map.get(el.dataset.groupId);if(!g)return;
         g.questions=[...el.querySelectorAll('.question')].map(q=>
           this.findQ(q.dataset.questionId)).filter(Boolean);
       });
       this.cleanupBranches();this.renumberQuestions();App.renderApp();App.initSortable();
     }});
   });
 },
 preview(){this.renumberQuestions();App.state.view='preview';App.renderApp()},
 saveSurvey:async function(){
   this.renumberQuestions();
   try{
     const r=await App.api.saveSurvey(App.state.survey);
     const i=App.state.surveys.findIndex(x=>x.id===r.survey.id);
     if(i<0)App.state.surveys.push(r.survey);else App.state.surveys[i]=r.survey;
     alert('保存しました。');this.list();
   }catch(e){alert(e.message)}
 },
 deleteSurvey:async function(id){
   if(!confirm('このアンケートを削除しますか？'))return;
   try{
     await App.api.request('deleteSurvey',{survey_id:id,csrf_token:APP_CONFIG.csrf});
     const s=App.state.surveys.find(x=>x.id===id);if(s)s.deleted=true;this.list();
   }catch(e){alert(e.message)}
 },
 duplicate:async function(id){
   try{
     const r=await App.api.request('duplicateSurvey',{survey_id:id,csrf_token:APP_CONFIG.csrf});
     App.state.surveys.push(r.survey);this.list();
   }catch(e){alert(e.message)}
 },
 saveKintoneSettings:async function(){
   const ids=['subdomain','login_name','password','app_id','proxy',
   'field_company','field_name','field_email','field_department','field_phone','field_address'];
   const d={};ids.forEach(k=>d['setting_'+k]=document.getElementById('setting_'+k)?.value||'');
   d.setting_ssl_verify=document.getElementById('setting_ssl_verify').value;
   try{
     const r=await App.api.saveKintone(d);
     document.getElementById('kintone_message').innerHTML=`<div class="success">${r.message}</div>`;
     await App.reload();
   }catch(e){document.getElementById('kintone_message').innerHTML=
     `<div class="error">${App.utils.esc(e.message)}</div>`}
 },
 saveSmtpSettings:async function(){
   const ids=['smtp_server','smtp_port','smtp_username','smtp_password','smtp_from_email',
   'smtp_from_name','smtp_timeout'];
   const d={};ids.forEach(k=>d[k]=document.getElementById(k)?.value||'');
   d.smtp_encryption=document.getElementById('smtp_encryption').value;
   d.smtp_auth=document.getElementById('smtp_auth').value;
   try{
     const r=await App.api.saveSmtp(d);
     document.getElementById('smtp_message').innerHTML=`<div class="success">${r.message}</div>`;
     await App.reload();
   }catch(e){document.getElementById('smtp_message').innerHTML=
     `<div class="error">${App.utils.esc(e.message)}</div>`}
 },
 connectKintone:async function(){
   const el=document.getElementById('kintone_connection_result');
   try{const r=await App.api.request('connect_kintone',{csrf_token:APP_CONFIG.csrf});
     el.innerHTML=`<div class="success">${r.message}<br>HTTP ${r.http_status}</div>`;
   }catch(e){el.innerHTML=`<div class="error">${App.utils.esc(e.message)}</div>`}
 },
 fetchKintoneFields:async function(){
   const el=document.getElementById('kintone_connection_result');
   try{
     const r=await App.api.request('fetch_kintone_fields',{csrf_token:APP_CONFIG.csrf});
     el.innerHTML=`<div class="success">フィールドを取得しました。</div><pre>${App.utils.esc(
       App.utils.json(r.fields))}</pre>`;
   }catch(e){el.innerHTML=`<div class="error">${App.utils.esc(e.message)}</div>`}
 },
 syncCustomers:async function(){
   const el=document.getElementById('kintone_connection_result');
   try{
     const r=await App.api.request('sync_customers',{csrf_token:APP_CONFIG.csrf});
     el.innerHTML=`<div class="success">同期完了：${r.count}件
     （追加 ${r.inserted} / 更新 ${r.updated} / スキップ ${r.skipped}）</div>`;
     await App.reload();
   }catch(e){el.innerHTML=`<div class="error">${App.utils.esc(e.message)}</div>`}
 },
 testSmtp:async function(){
   const el=document.getElementById('smtp_connection_result');
   try{const r=await App.api.request('test_smtp_connection',{csrf_token:APP_CONFIG.csrf});
   el.innerHTML=`<div class="success">${r.message}</div>`;
   }catch(e){el.innerHTML=`<div class="error">${App.utils.esc(e.message)}</div>`}
 },
 testMail:async function(){
   const el=document.getElementById('smtp_connection_result');
   try{const r=await App.api.request('send_smtp_test',{
     test_email:document.getElementById('test_email').value,csrf_token:APP_CONFIG.csrf});
     el.innerHTML=`<div class="success">${r.message}</div>`;
   }catch(e){el.innerHTML=`<div class="error">${App.utils.esc(e.message)}</div>`}
 },
 answer(id,v){App.state.answers[id]=v;localStorage.setItem('survey_answers',JSON.stringify(App.state.answers));
   this.updateBranchVisibility()},
 answerMulti(id,v,checked){
   let a=Array.isArray(App.state.answers[id])?App.state.answers[id]:[];
   a=checked?[...new Set([...a,v])]:a.filter(x=>x!==v);
   this.answer(id,a);
 },
 visibleQuestions(){
   const all=this.allQuestions(),visible=new Set(all.map(q=>q.id));
   all.forEach(q=>{
     if(q.type==='single'){
       const a=App.state.answers[q.id],o=q.options?.find(x=>x.id===a);
       const target=q.branching?.[o?.id];
       if(target){
         const qi=all.findIndex(x=>x.id===q.id),ti=all.findIndex(x=>x.id===target);
         all.slice(qi+1,ti).forEach(x=>visible.delete(x.id));
       }
     }
   });
   return all.filter(q=>visible.has(q.id));
 },
 updateBranchVisibility(){App.state.view==='response'&&App.renderApp()},
 validateResponse(){
   const bad=this.visibleQuestions().find(q=>q.required&&(
     App.state.answers[q.id]===undefined||App.state.answers[q.id]===''||
     (Array.isArray(App.state.answers[q.id])&&!App.state.answers[q.id].length)));
   if(bad){alert(`${bad.number} は必須回答です。`);return false}return true;
 },
 submitResponse:async function(){
   if(!this.validateResponse())return;
   try{
     await App.api.request('saveResponse',{response_json:JSON.stringify({
       id:App.utils.id('response'),survey_id:App.state.survey.id,
       answers:App.state.answers}),csrf_token:APP_CONFIG.csrf});
     localStorage.removeItem('survey_answers');alert('回答を送信しました。');
   }catch(e){alert(e.message)}
 },
 csv(){
   const s=App.state.survey,rows=[['質問番号','質問','回答']];
   App.actions.allQuestions().forEach(q=>{
     App.state.responses.filter(r=>r.survey_id===s.id).forEach(r=>
       rows.push([q.number,q.text,JSON.stringify(r.answers?.[q.id]??'')]));
   });
   const csv='\ufeff'+rows.map(r=>r.map(v=>`"${String(v).replaceAll('"','""')}"`).join(',')).join('\r\n');
   const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
   a.download='survey.csv';a.click();
 },
 async reload(){
   const r=await App.api.getInitialData();this.stateData(r.data);
 },
 stateData(d){
   this.state.surveys=Array.isArray(d.surveys)?d.surveys:[];
   this.state.responses=Array.isArray(d.responses)?d.responses:[];
   this.state.customers=Array.isArray(d.customers)?d.customers:[];
   this.state.mail_logs=Array.isArray(d.mail_logs)?d.mail_logs:[];
   this.state.settings=d.settings||{kintone:{},smtp:{}};
   if(d.csrf_token)APP_CONFIG.csrf=d.csrf_token;
 },
},

renderApp(){document.getElementById('app').innerHTML=App.render.root();},

async init(retry=false){
 if(this.state.initialized&&!retry)return;
 this.state.initialized=true;this.state.loading=true;this.state.error=null;this.renderApp();
 try{
   const r=await this.api.getInitialData();
   if(!r.data)throw new Error('初期データが存在しません。');
   this.actions.stateData(r.data);
   try{this.state.answers=JSON.parse(localStorage.getItem('survey_answers')||'{}')}catch{this.state.answers={}}
   this.state.loading=false;this.renderApp();this.initSortable();
 }catch(e){
   this.state.loading=false;this.state.error=e.message||'初期データの取得に失敗しました。';
   this.renderApp();
 }
}
};

App.initSortable=App.actions.initSortable.bind(App.actions);
App.reload=App.actions.reload.bind(App.actions);
App.stateData=App.actions.stateData.bind(App.actions);

if(document.readyState==='loading')
 document.addEventListener('DOMContentLoaded',()=>App.init(),{once:true});
else App.init();
</script>
</body>
</html>
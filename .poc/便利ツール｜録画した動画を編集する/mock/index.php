<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>録画動画編集モック</title>
<style>
*{box-sizing:border-box}html,body{margin:0;height:100%;overflow:hidden;background:#11161d;color:#eee;font:14px Arial,"Noto Sans JP",sans-serif}
button{font:inherit;border:1px solid #46515f;background:#242c36;color:#eee;border-radius:5px;padding:6px 10px;cursor:pointer}button:hover{background:#303b48}.primary,.cur{background:#1769aa!important}
.app,.main{display:flex;flex-direction:column}.app{height:100%}.main{flex:1;min-height:0}
header,.tools{display:flex;align-items:center;gap:8px;padding:7px 10px}header{height:52px;background:#161b22;border-bottom:1px solid #30363d}
.status,.scale{margin-left:auto;color:#9aa5b1;font-size:12px}
.video{flex:1;min-height:0;background:#05070a;padding:10px}.screen{height:100%;position:relative;background:#000;overflow:hidden}
video{width:100%;height:100%;object-fit:contain}.layer,svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}.layer{z-index:2}svg{z-index:3}
.el{position:absolute;cursor:move;user-select:none;pointer-events:auto}.el.sel{outline:2px solid #4da3ff;outline-offset:3px}.el.target{outline:2px dashed #ffd54f;cursor:crosshair}
.el svg.shape{position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none;z-index:0}
.el .txt{position:relative;z-index:1;padding:6px 10px;overflow:hidden;width:100%;height:100%;display:flex;align-items:center}
.zoom{border:3px solid #54d68a;background:#54d68a18;color:#9ff0bd;text-align:center;padding:8px}
.handle{position:absolute;width:9px;height:9px;background:#fff;border:2px solid #4da3ff;z-index:5}
.nw{left:-5px;top:-5px;cursor:nwse-resize}.ne{right:-5px;top:-5px;cursor:nesw-resize}.sw{left:-5px;bottom:-5px;cursor:nesw-resize}.se{right:-5px;bottom:-5px;cursor:nwse-resize}
.hit{pointer-events:stroke;stroke:transparent;stroke-width:18;fill:none;cursor:pointer}
.line{fill:none;stroke:#90a4ae;stroke-width:2;pointer-events:none}.line.sel{stroke:#4da3ff;stroke-width:3}.line.pre{stroke:#ffd54f;stroke-dasharray:6 4}
.endh{fill:#fff;stroke:#4da3ff;stroke-width:2;pointer-events:auto;cursor:grab}
.timeline{height:270px;flex:none;border-top:1px solid #30363d;display:flex;flex-direction:column}
.tools{height:42px;border-bottom:1px solid #30363d}.scale input{width:120px}
.scroll{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden}.tl{position:relative;min-height:100%}
.axis{height:26px;margin:0 15px 0 95px;position:relative;border-bottom:1px solid #30363d}
.tick{position:absolute;height:100%;border-left:1px solid #4a5563;color:#9aa5b1;font-size:10px;padding-left:3px;white-space:nowrap}
.tick.end{border:0;border-right:2px solid #f0b000;padding:0 3px 0 0;transform:translateX(-100%);color:#f0b000}
.rows{margin:0 15px 0 95px;position:relative}.row{height:34px;position:relative;border-bottom:1px solid #242b33}
.label{position:absolute;right:100%;width:95px;height:34px;display:flex;align-items:center;padding-left:7px;background:#161b22;font-size:10px;color:#8b949e}
.bar{position:absolute;top:4px;height:26px;border:1px solid;border-radius:5px;display:flex;align-items:center;padding:0 12px;font-size:10px;cursor:grab;overflow:hidden;white-space:nowrap;user-select:none}
.bar.sel{box-shadow:0 0 0 2px #4da3ff}.g{position:absolute;top:0;bottom:0;width:8px;cursor:ew-resize;background:#fff3}.g:hover{background:#fff7}.gl{left:0}.gr{right:0}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:#f04444;z-index:20;cursor:ew-resize}
.playhead::before{content:"";position:absolute;left:-7px;top:0;width:16px;height:16px;background:#f04444;border-radius:3px 3px 8px 8px}
.playhead::after{content:"";position:absolute;left:-8px;right:-8px;top:0;bottom:0}
.menu{position:fixed;display:none;z-index:100;background:#1c2531;border:1px solid #46515f;border-radius:6px;padding:6px;width:250px;max-height:95vh;overflow-y:auto;box-shadow:0 10px 30px #0009}
.menu button{display:block;width:100%;text-align:left;margin:2px 0}.menu hr{border:0;border-top:1px solid #46515f;margin:5px 0}
.menu .t{color:#9aa5b1;font-size:11px;margin:4px 0 2px}.opts{display:flex;flex-wrap:wrap;gap:4px}.opts button{width:auto;flex:1 1 auto;margin:0;text-align:center}
.sw12{display:grid;grid-template-columns:repeat(6,1fr);gap:4px}.sw12 button{height:24px;padding:0;margin:0;border:2px solid #46515f}.sw12 button.cur{border-color:#fff}
.wbtn{min-width:70px}.wbtn svg{width:50px;height:12px;display:block;margin:auto}
.menu input[type=number]{width:70px;background:#11161d;color:#eee;border:1px solid #46515f;border-radius:4px;padding:4px}
.toast{position:fixed;right:12px;bottom:12px;background:#202938;border:1px solid #46515f;padding:8px 12px;border-radius:5px;display:none;z-index:300}
.home{position:fixed;inset:0;z-index:150;background:#11161d;overflow:auto;padding:28px 40px}
.home h1{margin:0 0 6px;font-size:22px}.home h2{font-size:15px;margin:24px 0 8px;color:#c9d1d9}.sub,.meta,.empty,.cap{color:#8b949e;font-size:12px}.cap{font-weight:normal}
.item{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #30363d;border-radius:6px;margin:5px 0;background:#161b22;max-width:760px}.nm{flex:1}
.modal{position:fixed;inset:0;z-index:250;background:#000a;display:none;place-items:center}
.modal>div{background:#1c2531;border:1px solid #46515f;border-radius:8px;padding:18px 20px;min-width:360px;max-width:560px}
.modal p{margin:0 0 14px;line-height:1.6}.bt{display:flex;gap:8px;justify-content:flex-end}
</style></head>
<body>
<div class="home" id="home">
 <h1>録画動画編集</h1><div class="sub">動画は自動選択されません。編集する動画を選ぶか、保存済みの編集作業を再開してください。</div>
 <h2>新しい編集作業</h2><button class="primary" id="pick">動画を選択</button><input id="file" type="file" accept="video/*" hidden>
 <div id="lists"></div>
</div>
<div class="app">
<header><b>録画動画編集</b><button id="save">編集作業を保存</button><button id="saveNew">別の編集作業として保存</button>
 <button id="exportBtn" class="primary">編集結果を動画にする</button><button id="finish">編集作業を終了する</button><span class="status" id="status"></span></header>
<div class="main">
<div class="video"><div class="screen" id="screen"><video id="video" playsinline></video><div class="layer" id="layer"></div><svg id="svg"></svg></div></div>
<div class="timeline">
<div class="tools"><button id="play">▶ 再生</button><button id="stop">■ 停止</button><span id="time" style="min-width:165px"></span>
<span class="scale">時間軸 <input id="scale" type="range" min=".5" max="4" step=".1" value="1"> <span id="scaleText">1.0×</span></span></div>
<div class="scroll"><div class="tl" id="tl"><div class="axis" id="axis"></div><div class="rows" id="rows"></div><div class="playhead" id="head"></div></div></div>
</div></div></div>
<div class="menu" id="menu"></div><div class="toast" id="toast"></div><div class="modal" id="modal"><div id="modalBody"></div></div>
<script>
const $=id=>document.getElementById(id),video=$("video"),scr=$("screen"),layer=$("layer"),svg=$("svg"),menu=$("menu");
const TYPES={comment:["コメント","#4da3ff"],highlight:["強調枠","#f04444"],zoom:["拡大枠","#54d68a"],skip:["スキップ","#ff9800"]};
const SIDES=["top","right","bottom","left"],SJ={top:"上",right:"右",bottom:"下",left:"左"};
const DASH={solid:"",dotted:"2 5",dashed:"10 6",dashdot:"12 5 2 5"},MAX=10;
const COLORS=["#ffffff","#000000","#f04444","#ff9800","#ffeb3b","#54d68a","#009688","#4da3ff","#1e40af","#9c27b0","#e91e63","#90a4ae"];
const FONTS=["sans-serif","serif","monospace","cursive","Georgia","Impact"],WIDTHS=[1,2,4,6,8];
const ENDS=[["none","なし"],["arrow","矢印"],["circle","丸"],["square","四角"]],DASHES=[["solid","実線"],["dotted","点線"],["dashed","破線"],["dashdot","一点鎖線"]];
const FORMS=[["straight","直線"],["elbow","折れ線"],["wave","波線"]],SIDEOPT=[["auto","自動"],...SIDES.map(s=>[s,SJ[s]])];
/* メニュー定義: 要素別・線別。kind=opt(選択肢) color(12色) width(線サンプル) font(フォント見本) bool(有/無) num(数値) */
const bool=[[1,"あり"],[0,"なし"]];
const EMENU={
 comment:[["線","line","bool",bool],["線の太さ","lw","width"],["線の色","lc","color"],["文字色","fc","color"],["背景色","bg","color"],["塗り潰し","fill","bool",bool],["文字サイズ","fs","num"],["フォント","font","font"]],
 highlight:[["線の太さ","lw","width"],["塗り潰し","fill","bool",bool],["線種","dash","opt",DASHES],["線形","form","opt",FORMS]],
 zoom:[],skip:[]};
const LMENU=[["線形","form","opt",FORMS],["線種","dash","opt",DASHES],["始点の終端","se","opt",ENDS],["終点の終端","ee","opt",ENDS],["始点の接続位置","fs","opt",SIDEOPT],["終点の接続位置","ts","opt",SIDEOPT]];
const DEF={comment:{line:1,lw:2,lc:"#ffffff",fc:"#ffffff",bg:"#000000",fill:1,fs:18,font:"sans-serif",text:"新しいコメント"},
 highlight:{lw:4,lc:"#f04444",fill:0,dash:"solid",form:"straight"},zoom:{},skip:{}};
let duration=60,current=0,scale=1,selected=[],selLine=null,next=1,nextLine=1,drag=null,mt=null,connect=null,mouse=null,endDrag=null,tdrag=null,dirty=false;
let els=[],lines=[],uid=1,curOrig=null,curWork=null;
const store={orig:{label:"オリジナル動画",items:[]},work:{label:"編集作業",items:[]},out:{label:"編集結果の動画",items:[]}};
const fmt=v=>String(Math.floor(v/60)).padStart(2,"0")+":"+(v%60).toFixed(3).padStart(6,"0");
const fmtTick=(v,st)=>{let d=st<1?(st<.1?2:1):0;return String(Math.floor(v/60)).padStart(2,"0")+":"+(v%60).toFixed(d).padStart(d?3+d:2,"0")};
const toast=s=>{let t=$("toast");t.textContent=s;t.style.display="block";clearTimeout(t.x);t.x=setTimeout(()=>t.style.display="none",2500)};
const now=()=>new Date().toLocaleString("ja-JP"),esc=s=>String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const TW=()=>Math.max(100,$("tl").clientWidth-110),active=e=>current>=e.start&&current<=e.end,byId=id=>els.find(a=>a.id==id);
const multi=e=>e.shiftKey||e.ctrlKey||e.metaKey,touch=()=>{dirty=true;status()};
function cancelConnect(){connect=null;mouse=null}
function pick(id,e){selLine=null;selected=multi(e)?(selected.includes(id)?selected.filter(i=>i!=id):[...selected,id]):[id]}

/* ダイアログ */
function dialog(msg,buttons,extra=""){return new Promise(res=>{
 $("modalBody").innerHTML=`<p>${msg}</p>${extra}<div class="bt">`+buttons.map((b,i)=>`<button data-i="${i}"${b.primary?' class="primary"':""}>${b.label}</button>`).join("")+"</div>";
 $("modal").style.display="grid";
 $("modalBody").onclick=e=>{let b=e.target.closest("button");if(!b)return;
  if(b.dataset.del){res({del:b.dataset.del});$("modal").style.display="none"}
  else if(b.dataset.i){$("modal").style.display="none";res(buttons[b.dataset.i].v)}}})}
async function ensureRoom(kind){
 const s=store[kind];
 while(s.items.length>=MAX){
  let list=s.items.map(a=>`<div class="item"><span class="nm">${esc(a.name)}</span><span class="meta">${a.at}</span><button data-del="${a.id}">削除</button></div>`).join("");
  let r=await dialog(`${s.label}は${MAX}件までです。保存するには既存の${s.label}を削除する必要があります。削除するものを選んでください（自動では削除しません）。`,[{label:"保存をやめる",v:null}],list);
  if(!r)return false;if(!(await removeItem(kind,r.del)))continue}
 return true}
async function removeItem(kind,id){
 if(kind=="orig"&&store.work.items.some(w=>w.origId==id)){toast("この動画を使っている編集作業があります。先に編集作業を削除してください");return false}
 if(!confirm("削除します。よろしいですか？"))return false;
 let s=store[kind],i=s.items.findIndex(a=>a.id==id);if(s.items[i].url)URL.revokeObjectURL(s.items[i].url);s.items.splice(i,1);return true}

/* 初期画面 */
function homeRender(){
 const row=(k,a,sub,btns)=>`<div class="item"><span class="nm">${esc(a.name)}</span><span class="meta">${sub}</span>${btns}<button data-rm="${k}:${a.id}">削除</button></div>`;
 const sec=(k,title,fn)=>`<h2>${title} <span class="cap">(${store[k].items.length}/${MAX}件)</span></h2>`+(store[k].items.length?store[k].items.map(fn).join(""):'<div class="empty">ありません</div>');
 $("lists").innerHTML=
  sec("work","保存済みの編集作業",w=>row("work",w,`対象: ${esc((store.orig.items.find(o=>o.id==w.origId)||{}).name||"-")} ／ 保存 ${w.at}`,`<button class="primary" data-w="${w.id}">再開</button>`))+
  sec("orig","オリジナル動画",o=>row("orig",o,o.at,`<button data-o="${o.id}">この動画で新規編集</button>`))+
  sec("out","編集結果の動画",o=>row("out",o,`${o.at} ／ 元: ${esc(o.srcName)}`,`<button data-view="${o.id}">再生</button>`))}
$("home").onclick=async e=>{
 let b=e.target.closest("button");if(!b)return;let d=b.dataset;
 if(d.w)openWork(store.work.items.find(x=>x.id==d.w));
 else if(d.o)startEdit(store.orig.items.find(x=>x.id==d.o));
 else if(d.rm){let [k,id]=d.rm.split(":");if(await removeItem(k,id))homeRender()}
 else if(d.view){let o=store.out.items.find(x=>x.id==d.view);await dialog(`<b>${esc(o.name)}</b><br><video src="${o.url}" controls style="width:100%;max-height:50vh;background:#000;margin-top:8px"></video>`,[{label:"閉じる",v:1}])}};
$("pick").onclick=()=>$("file").click();
$("file").onchange=async e=>{
 let f=e.target.files[0];e.target.value="";if(!f||!(await ensureRoom("orig")))return;
 let o={id:"o"+uid++,name:f.name,url:URL.createObjectURL(f),at:now()};store.orig.items.push(o);startEdit(o)};

/* 編集開始・再開 */
function openEditor(o,w,s){
 curOrig=o;curWork=w;selected=[];selLine=null;current=0;cancelConnect();
 els=s?s.els:[];lines=s?s.lines:[];next=s?s.next:1;nextLine=s?s.nextLine:1;scale=s?s.scale:1;
 $("scale").value=scale;$("scaleText").textContent=scale.toFixed(1)+"×";
 video.src=o.url;video.onloadedmetadata=()=>{duration=video.duration||s&&s.duration||60;video.currentTime=0;
  $("home").style.display="none";menu.style.display="none";dirty=false;status();render()}}
const startEdit=o=>openEditor(o,null,null);
function openWork(w){let o=store.orig.items.find(x=>x.id==w.origId);o?openEditor(o,w,JSON.parse(w.data)):toast("対象のオリジナル動画が見つかりません")}
function status(){$("status").textContent=`編集中の動画: ${curOrig?curOrig.name:""} ／ ${curWork?"編集作業: "+curWork.name:"未保存の新規編集作業"}${dirty?"（未保存の変更あり）":""}`}

/* 保存: 編集内容のみ保存。オリジナル動画は変更しない */
async function saveWork(asNew){
 const data=JSON.stringify({els,lines,next,nextLine,scale,duration});
 if(curWork&&!asNew){Object.assign(curWork,{data,at:now()});dirty=false;status();toast("編集作業を保存しました");return true}
 if(!(await ensureRoom("work")))return false;
 let name=prompt("編集作業の名前",curWork?curWork.name+" のコピー":curOrig.name+" の編集");if(name===null)return false;
 curWork={id:"w"+uid++,name:name||"無題の編集作業",origId:curOrig.id,data,at:now()};store.work.items.push(curWork);
 dirty=false;status();toast("編集作業を保存しました");return true}
$("save").onclick=()=>saveWork(false);$("saveNew").onclick=()=>saveWork(true);
$("finish").onclick=async()=>{
 let r=await dialog("現在の編集作業を保存しますか？",[{label:"保存して終了",primary:true,v:"save"},{label:"保存せずに終了",v:"discard"},{label:"編集に戻る",v:"cancel"}]);
 if(r=="cancel"||(r=="save"&&!(await saveWork(false))))return;
 video.pause();$("home").style.display="block";curOrig=curWork=null;homeRender()};

/* 編集結果を動画にする */
$("exportBtn").onclick=async()=>{
 if(!window.MediaRecorder||!HTMLCanvasElement.prototype.captureStream)return toast("このブラウザは動画の書き出しに対応していません");
 if(!(await ensureRoom("out")))return;
 let v=document.createElement("video");v.src=curOrig.url;v.muted=true;v.playsInline=true;await new Promise(r=>v.onloadedmetadata=r);
 let W=Math.min(1280,v.videoWidth||1280),H=Math.round(W*(v.videoHeight||720)/(v.videoWidth||1280)),cv=Object.assign(document.createElement("canvas"),{width:W,height:H}),ctx=cv.getContext("2d");
 let rec=new MediaRecorder(cv.captureStream(30)),chunks=[],skips=els.filter(e=>e.type=="skip"),done=new Promise(r=>rec.onstop=r);
 rec.ondataavailable=e=>e.data.size&&chunks.push(e.data);
 let bar=Object.assign(document.createElement("div"),{className:"toast"});bar.style.display="block";document.body.append(bar);
 rec.start();await v.play();
 await new Promise(res=>(function loop(){
  let t=v.currentTime,s=skips.find(k=>t>=k.start&&t<k.end);if(s)v.currentTime=Math.min(s.end,v.duration);
  drawFrame(ctx,W,H,t,v);bar.textContent=`動画を作成中… ${Math.round(t/v.duration*100)}%`;
  v.ended||t>=v.duration-.05?res():requestAnimationFrame(loop)})());
 rec.stop();await done;bar.remove();
 let name=prompt("作成する動画の名前",curOrig.name.replace(/\.[^.]+$/,"")+"_編集済み");if(name===null)return;
 store.out.items.push({id:"v"+uid++,name:name||"編集済み動画",url:URL.createObjectURL(new Blob(chunks,{type:"video/webm"})),at:now(),srcName:curOrig.name});
 toast("編集結果を動画として作成しました。編集作業は保持されています（初期画面で確認できます）")};
function drawFrame(ctx,W,H,t,v){
 ctx.fillStyle="#000";ctx.fillRect(0,0,W,H);
 let vw=v.videoWidth||W,vh=v.videoHeight||H,k=Math.min(W/vw,H/vh),dw=vw*k,dh=vh*k,ox=(W-dw)/2,oy=(H-dh)/2,on=e=>t>=e.start&&t<=e.end;
 let z=els.find(e=>e.type=="zoom"&&on(e));
 if(z)return ctx.drawImage(v,z.x/100*vw,z.y/100*vh,z.w/100*vw,z.h/100*vh,0,0,W,H);
 ctx.drawImage(v,ox,oy,dw,dh);
 let R=e=>[ox+e.x/100*dw,oy+e.y/100*dh,e.w/100*dw,e.h/100*dh];
 els.filter(e=>on(e)&&e.type=="highlight").forEach(e=>{let [x,y,w,h]=R(e);ctx.strokeStyle=e.lc;ctx.lineWidth=e.lw;ctx.setLineDash((DASH[e.dash]||"").split(" ").filter(Boolean).map(Number));
  ctx.beginPath();ctx.rect(x,y,w,h);if(e.fill){ctx.fillStyle=e.lc+"33";ctx.fill()}ctx.stroke();ctx.setLineDash([])});
|  lines.forEach(L=>{let a=byId(L.from),b=byId(L.to);if(!a||!b||!on(a)||!on(b))return; |
|   let [ax,ay,aw,ah]=R(a),[bx,by,bw,bh]=R(b);ctx.strokeStyle="#90a4ae";ctx.lineWidth=2;ctx.setLineDash((DASH[L.dash]||"").split(" ").filter(Boolean).map(Number)); |
  ctx.beginPath();ctx.moveTo(ax+aw/2,ay+ah/2);ctx.lineTo(bx+bw/2,by+bh/2);ctx.stroke();ctx.setLineDash([])});
 els.filter(e=>on(e)&&e.type=="comment").forEach(e=>{let [x,y,w,h]=R(e);
  if(e.fill){ctx.fillStyle=e.bg;ctx.fillRect(x,y,w,h)}if(e.line){ctx.strokeStyle=e.lc;ctx.lineWidth=e.lw;ctx.strokeRect(x,y,w,h)}
  ctx.fillStyle=e.fc;ctx.font=e.fs*k+"px "+e.font;ctx.textBaseline="middle";ctx.fillText(e.text||"",x+8,y+h/2,w-12)})}

/* 要素の図形(強調枠=線種+線形 を別属性で描画。折れ線/波線でも破線等は重ねない) */
function shapeSvg(e){
 let w=Math.max(10,rp(e).w),h=Math.max(10,rp(e).h);
 const dash=DASH[e.dash]?` stroke-dasharray="${DASH[e.dash]}"`:"";
 let pts=[{x:0,y:0},{x:w,y:0},{x:w,y:h},{x:0,y:h},{x:0,y:0}],d;
 if(e.type=="highlight"&&e.form=="elbow"){let c=Math.min(14,w/4,h/4);d=`M${c} 0L${w-c} 0L${w} ${c}L${w} ${h-c}L${w-c} ${h}L${c} ${h}L0 ${h-c}L0 ${c}Z`}
 else if(e.type=="highlight"&&e.form=="wave")d=toPath(pts,"wave");
 else d=`M${pts.map(p=>p.x+" "+p.y).join("L")}Z`;
 if(e.type=="highlight"&&e.form!="straight"&&e.form!="")return`<svg class="shape"><path d="${d}" fill="${e.fill?e.lc+"33":"none"}" stroke="${e.lc}" stroke-width="${e.lw}"${e.form=="straight"?dash:""}/></svg>`;
 return`<svg class="shape"><path d="${d}" fill="${e.fill?(e.type=="comment"?e.bg:e.lc+"33"):"none"}" stroke="${(e.type=="comment"?e.line:1)?e.lc:"none"}" stroke-width="${e.lw}"${dash}/></svg>`}

/* 編集画面の描画 */
function render(){
 layer.innerHTML="";
 els.filter(e=>e.type!="skip"&&active(e)).forEach(e=>{
  let d=document.createElement("div");d.dataset.id=e.id;
  d.className=`el ${e.type==="zoom"?"zoom":""}${selected.includes(e.id)?" sel":""}${connect&&connect!=e.id?" target":""}`;
  d.style.cssText=`left:${e.x}%;top:${e.y}%;width:${e.w}%;height:${e.h}%`;
  if(e.type=="comment")d.innerHTML=shapeSvg(e)+`<div class="txt" style="color:${e.fc};font-size:${e.fs}px;font-family:${e.font}">${esc(e.text)}</div>`;
  else if(e.type=="highlight")d.innerHTML=shapeSvg(e);
  else d.textContent="拡大表示範囲";
  ["nw","ne","sw","se"].forEach(p=>d.insertAdjacentHTML("beforeend",`<i class="handle ${p}" data-resize="${p}"></i>`));
  d.onpointerdown=startMove;
  d.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();if(connect)return;if(!selected.includes(e.id)){selected=[e.id];selLine=null;render()}elMenu(ev.clientX,ev.clientY)};
  layer.append(d)});
 drawLines();timeline()}
scr.onclick=e=>{if(!e.target.closest(".el,svg *")){selected=[];selLine=null;cancelConnect();menu.style.display="none";render()}};
scr.onpointermove=e=>{if(!connect)return;let r=scr.getBoundingClientRect();mouse={x:e.clientX-r.left,y:e.clientY-r.top};drawLines()};
function startMove(e){
 if(e.button!==0)return;let o=byId(+e.currentTarget.dataset.id);e.stopPropagation();menu.style.display="none";
 if(connect){
  if(connect==o.id)return;
  lines.push({id:nextLine,from:connect,to:o.id,fs:"auto",ts:"auto",form:"straight",dash:"solid",se:"none",ee:"none"});
  selLine=nextLine++;selected=[];cancelConnect();touch();return render()}
 selLine=null;if(!selected.includes(o.id))selected=multi(e)?[...selected,o.id]:[o.id];
 let base={};selected.forEach(id=>base[id]={...byId(id)});
 drag={o,resize:e.target.dataset.resize,sx:e.clientX,sy:e.clientY,base,moved:false};
 addEventListener("pointermove",move);addEventListener("pointerup",end,{once:true})}
function move(e){
 let r=scr.getBoundingClientRect(),dx=(e.clientX-drag.sx)/r.width*100,dy=(e.clientY-drag.sy)/r.height*100;
 if(!drag.moved&&Math.abs(e.clientX-drag.sx)+Math.abs(e.clientY-drag.sy)<4)return;drag.moved=true;touch();
 if(!drag.resize)selected.forEach(id=>{let x=byId(id),b=drag.base[id];x.x=Math.max(0,Math.min(100-x.w,b.x+dx));x.y=Math.max(0,Math.min(100-x.h,b.y+dy))});
 else{let x=drag.o,b=drag.base[x.id],p=drag.resize;
  if(p.includes("e"))x.w=Math.max(5,Math.min(100-x.x,b.w+dx));
  if(p.includes("s"))x.h=Math.max(5,Math.min(100-x.y,b.h+dy));
  if(p.includes("w")){let n=Math.max(0,b.x+dx);x.w=Math.max(5,b.w+b.x-n);x.x=n}
  if(p.includes("n")){let n=Math.max(0,b.y+dy);x.h=Math.max(5,b.h+b.y-n);x.y=n}}
 render()}
function end(){removeEventListener("pointermove",move);drag=null;render()}

/* 接続線 */
const rp=e=>{let r=scr.getBoundingClientRect();return{x:e.x/100*r.width,y:e.y/100*r.height,w:e.w/100*r.width,h:e.h/100*r.height}};
function anchor(e,s){let r=rp(e),cx=r.x+r.w/2,cy=r.y+r.h/2;
 return s=="top"?{x:cx,y:r.y,s}:s=="bottom"?{x:cx,y:r.y+r.h,s}:s=="left"?{x:r.x,y:cy,s}:{x:r.x+r.w,y:cy,s}}
function autoSide(a,b){let A=rp(a),B=rp(b),dx=B.x+B.w/2-A.x-A.w/2,dy=B.y+B.h/2-A.y-A.h/2;
 return Math.abs(dx)>Math.abs(dy)?[dx>0?"right":"left",dx>0?"left":"right"]:[dy>0?"bottom":"top",dy>0?"top":"bottom"]}
const out=(p,d)=>({x:p.x+(p.s=="left"?-d:p.s=="right"?d:0),y:p.y+(p.s=="top"?-d:p.s=="bottom"?d:0)});
function hit(a,b,r){
 let x1=r.x-4,y1=r.y-4,x2=r.x+r.w+4,y2=r.y+r.h+4,dx=b.x-a.x,dy=b.y-a.y,t0=0,t1=1;
 for(let [p,q] of [[-dx,a.x-x1],[dx,x2-a.x],[-dy,a.y-y1],[dy,y2-a.y]]){
  if(!p){if(q<0)return false}else{let t=q/p;if(p<0){if(t>t1)return false;t0=Math.max(t0,t)}else{if(t<t0)return false;t1=Math.min(t1,t)}}}
 return true}
function route(form,A,B,obst){
 if(form=="straight")return[A,B];
 let a=out(A,18),b=out(B,18),h=A.s=="left"||A.s=="right";
 let xs=[a.x,b.x,...obst.flatMap(r=>[r.x,r.x+r.w])],ys=[a.y,b.y,...obst.flatMap(r=>[r.y,r.y+r.h])];
 let c=[[A,a,h?{x:b.x,y:a.y}:{x:a.x,y:b.y},b,B],[A,a,h?{x:a.x,y:b.y}:{x:b.x,y:a.y},b,B],
  ...[Math.min(...ys)-14,Math.max(...ys)+14].map(v=>[A,a,{x:a.x,y:v},{x:b.x,y:v},b,B]),
  ...[Math.min(...xs)-14,Math.max(...xs)+14].map(v=>[A,a,{x:v,y:a.y},{x:v,y:b.y},b,B])];
 return c.find(p=>!p.slice(1).some((q,i)=>obst.some(r=>hit(p[i],q,r))))||c[0]}
function toPath(pts,form){
 if(form!="wave")return"M"+pts.map(p=>p.x.toFixed(1)+" "+p.y.toFixed(1)).join("L");
 let seg=pts.slice(1).map((q,i)=>Math.hypot(q.x-pts[i].x,q.y-pts[i].y)),total=seg.reduce((a,b)=>a+b,0)||1,N=60,d="";
 for(let i=0;i<=N;i++){
  let s=i/N*total,k=0;while(k<seg.length-1&&s>seg[k]){s-=seg[k];k++}
  let p=pts[k],q=pts[k+1],l=seg[k]||1,u=s/l,o=Math.sin(i/N*Math.PI*2)*10;
  d+=(i?"L":"M")+(p.x+(q.x-p.x)*u-(q.y-p.y)/l*o).toFixed(1)+" "+(p.y+(q.y-p.y)*u+(q.x-p.x)/l*o).toFixed(1)}
 return d}
function marker(kind,id,c){if(kind=="none")return"";
 let s=kind=="arrow"?'<path d="M0 0L10 5L0 10Z"':kind=="circle"?'<circle cx="5" cy="5" r="4"':'<rect x="1" y="1" width="8" height="8"';
 return`<marker id="${id}" markerWidth="10" markerHeight="10" refX="${kind=="arrow"?9:5}" refY="5" orient="auto-start-reverse" markerUnits="userSpaceOnUse">${s} fill="${c}"/></marker>`}
function drawLines(){
 let defs="",body="";lines=lines.filter(L=>byId(L.from)&&byId(L.to));
 lines.forEach(L=>{
  let a=byId(L.from),b=byId(L.to);if(!active(a)||!active(b))return;
  let au=autoSide(a,b),A=anchor(a,L.fs=="auto"?au[0]:L.fs),B=anchor(b,L.ts=="auto"?au[1]:L.ts);
  let obst=els.filter(e=>e.type!="skip"&&e!=a&&e!=b&&active(e)).map(rp),d=toPath(route(L.form,A,B,obst),L.form),sel=selLine==L.id,c=sel?"#4da3ff":"#90a4ae";
  defs+=marker(L.se,"ms"+L.id,c)+marker(L.ee,"me"+L.id,c);
  body+=`<path class="hit" data-line="${L.id}" d="${d}"/><path class="line${sel?" sel":""}" d="${d}"${DASH[L.dash]?` stroke-dasharray="${DASH[L.dash]}"`:""}${L.se!="none"?` marker-start="url(#ms${L.id})"`:""}${L.ee!="none"?` marker-end="url(#me${L.id})"`:""}/>`;
  if(sel)body+=[[A,"from"],[B,"to"]].map(([p,k])=>`<circle class="endh" data-end="${k}" data-line="${L.id}" cx="${p.x}" cy="${p.y}" r="6"/>`).join("")});
 let src=connect&&byId(connect);
 if(src&&mouse&&active(src)){
  let r=rp(src),cx=r.x+r.w/2,cy=r.y+r.h/2,A=anchor(src,Math.abs(mouse.x-cx)>Math.abs(mouse.y-cy)?(mouse.x>cx?"right":"left"):(mouse.y>cy?"bottom":"top"));
  body+=`<path class="line pre" d="M${A.x} ${A.y}L${mouse.x} ${mouse.y}"/><circle cx="${mouse.x}" cy="${mouse.y}" r="4" fill="#ffd54f"/>`}
 svg.innerHTML=`<defs>${defs}</defs>`+body;
 svg.querySelectorAll(".hit").forEach(p=>{
  p.onclick=e=>{e.stopPropagation();selLine=+p.dataset.line;selected=[];render()};
  p.oncontextmenu=e=>{e.preventDefault();e.stopPropagation();selLine=+p.dataset.line;selected=[];render();lineMenu(e.clientX,e.clientY,selLine)}});
 svg.querySelectorAll(".endh").forEach(h=>h.onpointerdown=e=>{e.stopPropagation();e.preventDefault();endDrag=h.dataset;
  addEventListener("pointermove",endMove);addEventListener("pointerup",()=>{endDrag=null;removeEventListener("pointermove",endMove)},{once:true})})}
function endMove(e){
 let r=scr.getBoundingClientRect(),L=lines.find(l=>l.id==endDrag.line),k=endDrag.end=="from",el=byId(k?L.from:L.to),px=e.clientX-r.left,py=e.clientY-r.top;
 L[k?"fs":"ts"]=SIDES.map(s=>({s,d:Math.hypot(anchor(el,s).x-px,anchor(el,s).y-py)})).sort((a,b)=>a.d-b.d)[0].s;touch();render()}

/* 操作メニュー: 定義表から対象別に生成 */
function showMenu(x,y,h){menu.innerHTML=h;menu.style.display="block";menu.style.left=Math.min(x,innerWidth-270)+"px";menu.style.top=Math.max(5,Math.min(y,innerHeight-menu.offsetHeight-5))+"px"}
const sample=w=>`<svg viewBox="0 0 50 12"><line x1="2" y1="6" x2="48" y2="6" stroke="#fff" stroke-width="${w}"/></svg>`;
function group(title,key,kind,opts,cur,prefix){
 let b=(v,inner,style="")=>`<button class="${v==cur?"cur":""}${kind=="width"?" wbtn":""}" data-${prefix}="${key}:${v}" style="${style}">${inner}</button>`,body;
 if(kind=="color")body=`<div class="sw12">`+COLORS.map(c=>b(c,"",`background:${c}`)).join("")+"</div>";
 else if(kind=="width")body=`<div class="opts">`+WIDTHS.map(w=>b(w,sample(w))).join("")+"</div>";
 else if(kind=="font")body=`<div class="opts" style="flex-direction:column">`+FONTS.map(f=>b(f,"あいう ABC 123",`font-family:${f}`)).join("")+"</div>";
 else if(kind=="num")body=`<input type="number" min="8" max="96" value="${cur}" data-${prefix}num="${key}"> px`;
 else body=`<div class="opts">`+opts.map(([v,n])=>b(v,n)).join("")+"</div>";
 return`<hr><div class="t">${title}</div>`+body}
function elMenu(x,y){
 if(selected.length>1){mt={multi:1};return showMenu(x,y,`<b>${selected.length}件を選択中</b><hr><button data-a="del">選択した要素を削除</button>`)}
 let e=byId(selected[0]);mt={el:e.id};
 showMenu(x,y,`<b>${TYPES[e.type][0]}</b>`+(e.type=="comment"?'<hr><button data-a="text">コメントの文章を変更</button>':"")+
  EMENU[e.type].map(([t,k,kind,o])=>group(t,k,kind,o,e[k],"e")).join("")+
  (e.type=="skip"?'<div class="t">範囲はタイムラインで変更します</div>':"")+
  (e.type=="zoom"?'<div class="t">位置・サイズは動画上、表示時間はタイムラインで変更します</div>':"")+
  '<hr>'+(e.type!="skip"?'<button data-a="connect">接続線を作成</button>':"")+'<button data-a="del">削除</button>')}
function lineMenu(x,y,id){
 let L=lines.find(l=>l.id==id);mt={line:id};
 showMenu(x,y,"<b>接続線</b>"+LMENU.map(([t,k,kind,o])=>group(t,k,kind,o,L[k],"l")).join("")+'<hr><button data-a="dline">接続線を削除</button>')}
scr.oncontextmenu=e=>{if(e.target.closest(".el,svg *"))return;e.preventDefault();cancelConnect();mt={x:e.clientX,y:e.clientY};
 showMenu(e.clientX,e.clientY,"<b>要素を追加</b>"+Object.entries(TYPES).map(([k,v])=>`<button data-add="${k}">${v[0]}</button>`).join(""))};
| const conv=(k,v)=>(k=="lw"||k=="fs"||k=="line"||k=="fill")?+v:v; |
| menu.onchange=e=>{let i=e.target.dataset.enum;if(!i)return;let x=byId(mt.el);x[i]=Math.max(8,Math.min(96,+e.target.value||18));touch();render()}; |
menu.onclick=e=>{
 let b=e.target.closest("button");if(!b)return;let d=b.dataset;
 if(d.e){let x=byId(mt.el),[k,v]=d.e.split(":");x[k]=conv(k,v);touch();render();return elMenu(parseFloat(menu.style.left),parseFloat(menu.style.top))}
 if(d.l){let L=lines.find(l=>l.id==mt.line),[k,v]=d.l.split(":");L[k]=v;touch();render();return lineMenu(parseFloat(menu.style.left),parseFloat(menu.style.top),L.id)}
 if(d.a){touch();
  if(d.a=="dline"){lines=lines.filter(l=>l.id!=mt.line);selLine=null}
  else if(d.a=="del"){let ids=mt.multi?selected:[mt.el];els=els.filter(v=>!ids.includes(v.id));selected=[]}
  else{let x=byId(mt.el);
   if(d.a=="text"){let v=prompt("コメント",x.text);if(v!==null)x.text=v}
   else if(d.a=="connect"){if(!active(x)){current=x.start;video.currentTime=current}connect=x.id;selected=[x.id];let p=rp(x);mouse={x:p.x+p.w+40,y:p.y+p.h/2}}}
  menu.style.display="none";return render()}
 if(d.add){let r=scr.getBoundingClientRect();touch();
  els.push({id:next++,type:d.add,start:current,end:Math.min(duration,current+5),x:Math.max(0,Math.min(75,(mt.x-r.left)/r.width*100)),y:Math.max(0,Math.min(82,(mt.y-r.top)/r.height*100)),w:25,h:18,...DEF[d.add]});
  menu.style.display="none";render()}};
menu.addEventListener("change",e=>{let k=e.target.dataset.enum;if(!k)return;let x=byId(mt.el);x[k]=Math.max(8,Math.min(96,+e.target.value||18));touch();render();elMenu(parseFloat(menu.style.left),parseFloat(menu.style.top))});

/* タイムライン(幅は固定。スケールは目盛り間隔のみ変える) */
function niceStep(min){let p=Math.pow(10,Math.floor(Math.log10(min)));return [1,2,5,10].find(k=>k*p>=min)*p}
function assignRows(){
 let rowEnd=[],map={};
 [...els].sort((a,b)=>a.start-b.start||a.end-b.end||a.id-b.id).forEach(e=>{
  let r=rowEnd.findIndex(t=>t<=e.start+1e-9);if(r<0){r=rowEnd.length;rowEnd.push(0)}rowEnd[r]=e.end;map[e.id]=r});
 return{map,count:Math.max(rowEnd.length,1)}}
function timeline(){
 let W=TW(),major=niceStep(120/(W/duration*scale)),ax=$("axis");ax.innerHTML="";
 for(let t=0;t<duration;t+=major){let x=t/duration*W;if(t>0&&W-x<60)break;ax.insertAdjacentHTML("beforeend",`<div class="tick" style="left:${x}px">${fmtTick(t,major)}</div>`)}
 ax.insertAdjacentHTML("beforeend",`<div class="tick end" style="left:${W}px">${fmt(duration).slice(0,8)}</div>`);
 let {map,count}=assignRows(),h="";
 for(let r=0;r<count;r++)h+=`<div class="row"><div class="label">行 ${r+1}</div>`+els.filter(e=>map[e.id]==r).map(e=>{
  let [n,c]=TYPES[e.type],L=e.start/duration*W,R=Math.min(e.end/duration*W,W);
  return`<div class="bar${selected.includes(e.id)?" sel":""}" data-id="${e.id}" style="left:${L}px;width:${Math.max(24,R-L)}px;color:${c};background:${c}44"><i class="g gl" data-edge="l"></i>${n}<i class="g gr" data-edge="r"></i></div>`}).join("")+"</div>";
 $("rows").innerHTML=h;
 $("rows").querySelectorAll(".bar").forEach(b=>{
  b.onpointerdown=startTdrag;
  b.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();let id=+b.dataset.id;if(!selected.includes(id)){selected=[id];selLine=null;render()}elMenu(ev.clientX,ev.clientY)}});
 $("head").style.left=95+current/duration*W+"px";$("time").textContent=fmt(current)+" / "+fmt(duration)}
function startTdrag(e){
 if(e.button!==0)return;e.stopPropagation();menu.style.display="none";
 let id=+e.currentTarget.dataset.id,o=byId(id);if(!selected.includes(id))pick(id,e);
 tdrag={o,id,mode:e.target.dataset.edge||"m",sx:e.clientX,s:o.start,e:o.end,moved:false,ev:e};
 addEventListener("pointermove",tmove);addEventListener("pointerup",tend,{once:true})}
function tmove(e){
 if(!tdrag.moved&&Math.abs(e.clientX-tdrag.sx)<3)return;tdrag.moved=true;touch();
 let dt=(e.clientX-tdrag.sx)/TW()*duration,o=tdrag.o;
 if(tdrag.mode=="l")o.start=Math.max(0,Math.min(tdrag.e-.1,tdrag.s+dt));
 else if(tdrag.mode=="r")o.end=Math.min(duration,Math.max(tdrag.s+.1,tdrag.e+dt));
 else{let len=tdrag.e-tdrag.s;o.start=Math.max(0,Math.min(duration-len,tdrag.s+dt));o.end=o.start+len}
 render()}
function tend(){removeEventListener("pointermove",tmove);if(!tdrag.moved)pick(tdrag.id,tdrag.ev);tdrag=null;render()}
function seek(cx){current=Math.max(0,Math.min(duration,(cx-$("tl").getBoundingClientRect().left-95)/TW()*duration));video.currentTime=current;render()}
$("head").onpointerdown=e=>{e.preventDefault();e.stopPropagation();let m=ev=>seek(ev.clientX);addEventListener("pointermove",m);addEventListener("pointerup",()=>removeEventListener("pointermove",m),{once:true})};
$("tl").onclick=e=>{if(!e.target.closest(".bar")&&e.clientX-$("tl").getBoundingClientRect().left>=95)seek(e.clientX)};
$("scale").oninput=e=>{scale=+e.target.value;$("scaleText").textContent=scale.toFixed(1)+"×";render()};

/* 再生・共通操作 */
$("play").onclick=()=>video.paused?video.play():video.pause();
$("stop").onclick=()=>{video.pause();video.currentTime=0};
video.onplay=()=>$("play").textContent="❚❚ 一時停止";video.onpause=()=>$("play").textContent="▶ 再生";
video.ontimeupdate=()=>{current=video.currentTime;render()};
addEventListener("keydown",e=>{
 if($("home").style.display!="none"||$("modal").style.display=="grid"||e.target.tagName=="INPUT")return;
 if(e.key=="Escape"){menu.style.display="none";cancelConnect();render()}
 if(e.key=="Delete"){touch();selLine?lines=lines.filter(l=>l.id!=selLine):els=els.filter(x=>!selected.includes(x.id));selLine=null;selected=[];render()}});
addEventListener("click",e=>{if(!e.target.closest(".menu"))menu.style.display="none"});
addEventListener("resize",()=>curOrig&&render());
homeRender();
</script></body></html>
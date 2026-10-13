<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画撮影・編集</title>
<style>
*{box-sizing:border-box}
:root{font:12px Meiryo,sans-serif;color:#263449;background:#edf1f6}
body{margin:0;height:100dvh;display:flex;flex-direction:column}
button,input,select{font:inherit}
button{height:26px;padding:0 8px;border:1px solid #cbd5e1;border-radius:4px;background:white;white-space:nowrap;cursor:pointer;color:inherit}
button:hover{background:#eaf2ff}
button:disabled{opacity:.45;cursor:default}
button.primary{background:#2563eb;color:white;border-color:#2563eb}
header,.bar{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
header{padding:7px 10px;background:white;border-bottom:1px solid #cbd5e1}
header strong{margin-right:auto;font-size:14px}
main{flex:1;min-height:0;padding:7px;display:flex;flex-direction:column;gap:6px}
#stage{flex:1;min-height:100px;background:#172235;overflow:auto;position:relative;display:grid;place-items:center}
#empty{text-align:center;color:#cfdaea;line-height:2;padding:12px}
#surface{position:relative;flex:none}
video{display:block;width:100%;height:100%}
#overlay{position:absolute;inset:0;width:100%;height:100%;touch-action:none}
#actions{justify-content:center}
#status{min-height:18px;color:#5b6d85}
#split{height:7px;background:#d6dfeb;cursor:ns-resize;touch-action:none}
#split:hover{background:#94b6ea}
#timeline{height:205px;min-height:100px;background:white;border:1px solid #cbd5e1;display:flex;flex-direction:column}
#timeline .bar{padding:5px 8px;border-bottom:1px solid #dce3ec}
#timeline small{margin-left:auto;color:#64748b}
#trackScroll{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden}
#tracks{position:relative;min-height:120px;touch-action:none}
.tick{position:absolute;top:0;height:22px;font-size:10px;color:#64748b;border-left:1px solid #dce3ec}
.clip{position:absolute;height:24px;border:1px solid #95b4df;border-radius:3px;background:#e2edff;padding:3px 8px;overflow:hidden;white-space:nowrap;cursor:move;user-select:none}
.clip.B{background:#fff1bf}
.clip.C{background:#d9f3e5}
.clip.D{background:#ffe1e6}
.clip.F{background:#eee0ff}
.clip.selected{outline:2px solid #2563eb;outline-offset:-2px}
.edge{position:absolute;top:0;bottom:0;width:7px;background:#0002;cursor:ew-resize}
.edge.start{left:0}.edge.end{right:0}
#playhead{position:absolute;top:0;bottom:0;width:2px;background:#e34770;pointer-events:none;z-index:4}
#handle{position:absolute;left:-6px;width:14px;height:20px;background:#e34770;clip-path:polygon(0 0,100% 0,100% 65%,50% 100%,0 65%);pointer-events:auto;cursor:ew-resize}
#veil{position:fixed;inset:0;background:#0005;z-index:20}
#library{position:fixed;inset:0 auto 0 0;width:min(530px,100vw);background:white;z-index:21;display:flex;flex-direction:column;box-shadow:4px 0 20px #0003}
#library .bar{padding:7px;border-bottom:1px solid #dce3ec}
#tree{flex:1;overflow:auto;padding:7px}
.row{display:flex;align-items:center;gap:5px;min-height:28px;padding:3px}
.row:hover,.row.active{background:#eaf2ff}
.row.drop{outline:2px dashed #2563eb}
.row button{width:20px;padding:0;border:0}
.name{flex:1;overflow-wrap:anywhere;cursor:pointer}
.name[contenteditable=true]{outline:1px solid #2563eb;background:white}
.row time{font-size:10px;color:#64748b}
#menu{position:fixed;z-index:40;background:white;border:1px solid #cbd5e1;border-radius:4px;padding:5px;box-shadow:0 5px 18px #0003;max-height:85dvh;max-width:95vw;overflow:auto;min-width:175px}
#menu>button{display:block;width:100%;text-align:left;border:0}
#menu label{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:5px}
.palette{display:grid;grid-template-columns:repeat(6,24px);gap:5px;padding:5px}
.palette button{height:24px;width:24px;padding:0}
.editor{position:absolute;padding:6px;outline:2px solid #60a5fa;white-space:pre-wrap;overflow:auto;z-index:6}
dialog{border:1px solid #cbd5e1;border-radius:5px;max-width:94vw}
dialog::backdrop{background:#0005}
dialog label{display:block;margin:12px 0}
dialog p{line-height:1.8}
dialog .bar{margin-top:12px}
[hidden]{display:none!important}
#printArea{display:none}
@media(max-width:650px){.row time,#timeline small{display:none}header strong{font-size:12px}main{padding:4px}}
@media print{
@page{size:A4 portrait;margin:12mm}
body{height:auto;display:block;background:white}
body>*:not(#printArea){display:none!important}
#printArea{display:block}
.sheet{height:268mm;display:flex;flex-direction:column;gap:6mm;break-after:page}
.sheet:last-child{break-after:auto}
.shot{flex:1;min-height:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
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
<main id="main">
<section id="stage">
<div id="empty">録画開始で共有する画面を選択してください。<br>動画をアップする、または動画一覧から開くこともできます。</div>
<div id="surface" hidden><video id="video" playsinline></video><canvas id="overlay"></canvas></div>
</section>
<div class="bar" id="actions">
<button id="primary" class="primary">録画開始</button>
<button id="edit" hidden>編集開始</button>
<button id="save" hidden>編集結果保存</button>
<button id="download" hidden>ダウンロード</button>
<button id="pdf" hidden>PDF出力</button>
<button id="finish" hidden>編集作業終了</button>
<label id="zoomLabel" hidden>動画表示 <input id="viewZoom" type="range" min=".25" max="3" step=".05" value="1"></label>
</div>
<div id="status" role="status">動画・編集データ・生成済み編集動画を、このブラウザに保存します。</div>
<div id="split" hidden title="上下にドラッグして動画とタイムラインの高さを変更"></div>
<section id="timeline" hidden>
<div class="bar">
<strong id="clock">0.0 / 0.0 秒</strong>
<label>タイムスケール <input id="scale" type="range" min="1" max="10" step=".1" value="10"></label>
<small>右クリック：追加・書式・削除　Ctrl／Shift：複数選択</small>
</div>
<div id="trackScroll"><div id="tracks"><div id="playhead"><div id="handle"></div></div></div></div>
</section>
</main>
<div id="veil" hidden></div>
<aside id="library" hidden>
<div class="bar">
<button id="new">新たに画面を録画する</button>
<button id="upload2">動画をアップする</button>
<button id="folder">＋フォルダ</button>
</div>
<div id="tree"></div>
<div class="bar"><button id="closeList">動画一覧を閉じる</button><span>名前変更：ダブルクリック　操作：右クリック</span></div>
</aside>
<input id="file" type="file" accept="video/*" hidden>
<div id="menu" hidden></div>
<dialog id="pdfDialog">
<h3>PDF出力</h3>
<p>PDF位置にある画面と編集要素を出力します。</p>
<label><input type="radio" name="layout" value="1" checked>1ページに1画面</label>
<label><input type="radio" name="layout" value="2">1ページに2画面</label>
<div class="bar"><button id="print">印刷へ進む</button><button id="cancelPDF">キャンセル</button></div>
</dialog>
<div id="printArea"></div>

<script>
'use strict';
(async()=>{
const $=id=>document.getElementById(id);
const v=$('video'),canvas=$('overlay'),ctx=canvas.getContext('2d');
const uid=()=>crypto.randomUUID();
const clone=x=>structuredClone(x);
const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
const names={A:'コメント',B:'強調枠',C:'拡大枠',D:'スキップ',E:'接続線',F:'PDF位置'};
const colors=['#ffffff','#111827','#ef4444','#f97316','#eab308','#22c55e','#14b8a6','#2563eb','#8b5cf6','#ec4899','#64748b','transparent'];
const dash={solid:[],dash:[10,6],dot:[2,5],chain:[12,5,2,5]};
const order={C:0,B:1,A:2,E:3};
let db,model={folders:[]},current=null,revision=null,items=[];
let selected=new Set(),collapsed=new Set();
let editing=false,dirty=false,busy=false,recordStream=null,recordWriter=null;
let sourceURL=null,baked=false,textEditor=null,connection=null;
let audioContext=null,audioSource=null,audioDestination=null;
let total=0,abortJob=false,animation=null;
const message=s=>$('status').textContent=s;
const locked=()=>busy||!!recordStream;
const duration=()=>total;
const find=id=>items.find(x=>x.id===id);
const newFolder=name=>({id:uid(),name,date:Date.now(),children:[],videos:[]});
const folders=(list=model.folders)=>list.flatMap(f=>[f,...folders(f.children)]);
const videos=()=>folders().flatMap(f=>f.videos);
const safe=fn=>async(...args)=>{
 try{await fn(...args)}
 catch(e){console.error(e);message(e.message||'操作に失敗しました')}
};

function storage(mode,fn){
 return new Promise((resolve,reject)=>{
  const tx=db.transaction('data',mode);
  let result;
  try{result=fn(tx.objectStore('data'))}
  catch(e){tx.abort();reject(e);return}
  tx.oncomplete=()=>resolve(result?.result);
  tx.onerror=tx.onabort=()=>reject(tx.error||Error('保存に失敗しました'));
 });
}
const read=key=>storage('readonly',s=>s.get(key));
async function change(fn,writes=()=>{}){
 const backup=clone(model),vid=current?.id,eid=revision?.id;
 try{
  fn();
  await storage('readwrite',s=>{writes(s);s.put(model,'model')});
 }catch(e){
  model=backup;
  current=videos().find(x=>x.id===vid)||null;
  revision=current?.edits.find(x=>x.id===eid)||null;
  tree();throw e;
 }
 tree();
}
function library(open){$('library').hidden=$('veil').hidden=!open}
function commitText(){
 if(!textEditor)return;
 const active=textEditor;textEditor=null;
 active.item.text=active.node.innerText;
 active.node.remove();dirty=true;
}
function fit(){
 const r=$('stage').getBoundingClientRect();
 const w=v.videoWidth||1280,h=v.videoHeight||720;
 const k=Math.min(r.width/w,r.height/h)*Number($('viewZoom').value);
 $('surface').style.width=Math.max(1,w*k)+'px';
 $('surface').style.height=Math.max(1,h*k)+'px';
 if(canvas.width!==w||canvas.height!==h){canvas.width=w;canvas.height=h}
}
function update(){
 const has=!!v.getAttribute('src')||!!v.srcObject;
 $('surface').hidden=!has;$('empty').hidden=has;
 $('timeline').hidden=$('split').hidden=!editing;
 $('zoomLabel').hidden=!current||!!recordStream;
 $('edit').hidden=!current||editing||!!recordStream;
 $('save').hidden=$('finish').hidden=!editing;
 $('download').hidden=$('pdf').hidden=!current||!!recordStream;
 $('primary').textContent=recordStream?'録画停止':editing?(v.paused?'編集再生':'編集再生停止'):'録画開始';
 document.querySelectorAll('header button,#actions button,#library button').forEach(b=>b.disabled=locked());
 $('primary').disabled=busy;
 $('viewZoom').disabled=$('scale').disabled=locked();
 fit();tracks();paint();
}
function reset(){
 commitText();v.pause();v.srcObject=null;
 v.removeAttribute('src');v.load();
 if(sourceURL)URL.revokeObjectURL(sourceURL);
 sourceURL=null;current=revision=null;items=[];total=0;
 selected.clear();editing=dirty=baked=false;connection=null;
 $('viewZoom').value=1;$('scale').value=10;
}
function waitEvent(name,action){
 return new Promise((resolve,reject)=>{
  const cleanup=()=>{
   clearTimeout(timer);v.removeEventListener(name,done);v.removeEventListener('error',fail);
  };
  const done=()=>{cleanup();resolve()};
  const fail=()=>{cleanup();reject(Error('動画を読み込めませんでした'))};
  const timer=setTimeout(fail,30000);
  v.addEventListener(name,done);v.addEventListener('error',fail);
  try{action()}catch(e){cleanup();reject(e)}
 });
}
async function seek(t){
 t=clamp(t,0,duration());
 if(Math.abs(v.currentTime-t)>.00001)await waitEvent('seeked',()=>v.currentTime=t);
 else if(v.readyState<2)await waitEvent('loadeddata',()=>{});
 await new Promise(requestAnimationFrame);
}
async function setSource(blob,knownDuration=0){
 if(!blob)throw Error('動画データがありません');
 v.pause();v.srcObject=null;
 if(sourceURL)URL.revokeObjectURL(sourceURL);
 sourceURL=URL.createObjectURL(blob);
 await waitEvent('loadedmetadata',()=>{v.src=sourceURL;v.load()});
 total=Number.isFinite(v.duration)&&v.duration>0?v.duration:knownDuration;
 if(!total){
  await waitEvent('seeked',()=>v.currentTime=1e10);
  total=Number.isFinite(v.duration)?v.duration:v.currentTime;
 }
 if(!Number.isFinite(total)||total<=0)throw Error('動画の長さを取得できません');
 await seek(0);fit();
}
async function leave(){
 if(locked())return false;
 commitText();
 if(!dirty)return true;
 if(confirm('編集結果と編集動画を保存して続行しますか？')){
  await save();return true;
 }
 if(confirm('未保存の編集を破棄しますか？')){dirty=false;return true}
 return false;
}
async function load(item,edit=null,mode=false){
 if(!await leave())return false;
 busy=true;update();
 try{
  const useBaked=!!edit&&!mode;
  const blob=await read(useBaked?'render:'+edit.id:'video:'+item.id);
  if(!blob)throw Error(useBaked?'保存済み編集動画がありません':'元動画がありません');
  reset();current=item;revision=edit;items=clone(edit?.items||[]);
  editing=mode;baked=useBaked;
  await setSource(blob,useBaked?edit.outputDuration:item.duration);
  library(false);message(item.name+' ／ '+(edit?.name||'元動画'));
  return true;
 }catch(e){reset();throw e}
 finally{busy=false;update();tree()}
}
function pointer(e){
 const r=canvas.getBoundingClientRect();
 return{x:(e.clientX-r.left)*canvas.width/r.width,y:(e.clientY-r.top)*canvas.height/r.height};
}
function drag(e,move,end=()=>{}){
 e.preventDefault();
 const stop=()=>{
  window.removeEventListener('pointermove',move);
  window.removeEventListener('pointerup',stop);
  window.removeEventListener('pointercancel',stop);end();
 };
 window.addEventListener('pointermove',move);
 window.addEventListener('pointerup',stop);
 window.addEventListener('pointercancel',stop);
}
function select(item,e){
 if(!e.ctrlKey&&!e.shiftKey)selected.clear();
 if(item){
  if((e.ctrlKey||e.shiftKey)&&selected.has(item.id))selected.delete(item.id);
  else selected.add(item.id);
 }
}
function shape(c,e){
 c.beginPath();
 if(e.shape==='ellipse')c.ellipse(e.x+e.w/2,e.y+e.h/2,e.w/2,e.h/2,0,0,Math.PI*2);
 else if(e.shape==='round')c.roundRect(e.x,e.y,e.w,e.h,Math.min(16,e.w/4,e.h/4));
 else c.rect(e.x,e.y,e.w,e.h);
}
function port(e,p){
 if(e.shape==='ellipse')return{
  x:e.x+e.w/2+Math.cos(p.angle||0)*e.w/2,
  y:e.y+e.h/2+Math.sin(p.angle||0)*e.h/2
 };
 const r=p.r??.5;
 return p.side==='left'?{x:e.x,y:e.y+e.h*r}:
 p.side==='right'?{x:e.x+e.w,y:e.y+e.h*r}:
 p.side==='top'?{x:e.x+e.w*r,y:e.y}:{x:e.x+e.w*r,y:e.y+e.h};
}
function normal(e,p){
 if(e.shape==='ellipse'){
  const x=Math.cos(p.angle||0)/e.w,y=Math.sin(p.angle||0)/e.h,k=Math.hypot(x,y)||1;
  return{x:x/k,y:y/k};
 }
 return{left:{x:-1,y:0},right:{x:1,y:0},top:{x:0,y:-1},bottom:{x:0,y:1}}[p.side||'bottom'];
}
function nearest(e,q){
 if(e.shape==='ellipse')return{angle:Math.atan2((q.y-e.y-e.h/2)/(e.h/2),(q.x-e.x-e.w/2)/(e.w/2))};
 return[
  {side:'left',r:clamp((q.y-e.y)/e.h,.02,.98)},
  {side:'right',r:clamp((q.y-e.y)/e.h,.02,.98)},
  {side:'top',r:clamp((q.x-e.x)/e.w,.02,.98)},
  {side:'bottom',r:clamp((q.x-e.x)/e.w,.02,.98)}
 ].sort((a,b)=>{
  const p=port(e,a),r=port(e,b);
  return Math.hypot(p.x-q.x,p.y-q.y)-Math.hypot(r.x-q.x,r.y-q.y);
 })[0];
}
function endpoints(e){
 const a=find(e.owner),b=find(e.comment);if(!a||!b)return null;
 const p=port(a,e.from),q=port(b,e.to),n=normal(a,e.from),m=normal(b,e.to);
 return[p,{x:p.x+n.x*20,y:p.y+n.y*20},{x:q.x+m.x*20,y:q.y+m.y*20},q];
}
function visible(e,t){
 const owner=e.type==='E'?find(e.owner):e;
 return owner&&!['D','F'].includes(e.type)&&t>=owner.start&&t<owner.end;
}
function paint(c=ctx,t=v.currentTime,output=false){
 if(!output)c.clearRect(0,0,canvas.width,canvas.height);
 if(!output&&(!editing||baked))return;
 items.filter(e=>visible(e,t)).sort((a,b)=>order[a.type]-order[b.type]).forEach(e=>{
  c.save();c.strokeStyle=e.stroke;c.lineWidth=e.width;c.setLineDash(dash[e.dash]||[]);
  if(e.type==='E'){
   const ends=endpoints(e);if(!ends){c.restore();return}
   const[p,u,w,q]=ends;c.beginPath();c.moveTo(p.x,p.y);
   c.lineTo(u.x,u.y);
   if(e.line==='orthogonal'){
    c.lineTo((u.x+w.x)/2,u.y);c.lineTo((u.x+w.x)/2,w.y);
   }else if(e.line==='wave'){
    const dx=w.x-u.x,dy=w.y-u.y,l=Math.hypot(dx,dy)||1;
    for(let i=1;i<=80;i++){
     const f=i/80,s=16*Math.sin(2*Math.PI*f)*Math.sin(Math.PI*f)**2;
     c.lineTo(u.x+dx*f-dy/l*s,u.y+dy*f+dx/l*s);
    }
   }
   c.lineTo(w.x,w.y);c.lineTo(q.x,q.y);c.stroke();c.setLineDash([]);
   if(e.terminal==='arrow'){
    const a=Math.atan2(q.y-w.y,q.x-w.x);
    c.beginPath();c.moveTo(q.x-12*Math.cos(a-.5),q.y-12*Math.sin(a-.5));
    c.lineTo(q.x,q.y);c.lineTo(q.x-12*Math.cos(a+.5),q.y-12*Math.sin(a+.5));c.stroke();
   }
   if(e.terminal==='circle'){c.beginPath();c.arc(q.x,q.y,5,0,Math.PI*2);c.stroke()}
   if(!output)[p,q].forEach((p,i)=>{
    c.fillStyle=i?'#22c55e':'#38bdf8';c.beginPath();
    c.arc(p.x,p.y,selected.has(e.id)?6:4,0,Math.PI*2);c.fill();
   });
  }else{
   shape(c,e);
   if(e.type==='C'&&v.readyState>=2){
    c.save();c.clip();
    const sw=e.w/e.zoom,sh=e.h/e.zoom;
    c.drawImage(v,e.x+(e.w-sw)/2,e.y+(e.h-sh)/2,sw,sh,e.x,e.y,e.w,e.h);
    c.restore();
   }
   if(e.background!=='transparent'){c.fillStyle=e.background;c.fill()}
   c.stroke();
   if(e.type==='A'){
    c.save();shape(c,e);c.clip();
    c.fillStyle=e.color;c.font=e.size+'px '+e.font;
    let y=e.y+e.size+6;
    for(const line of e.text.split('\n')){
     let row='';
     for(const ch of line){
      if(row&&c.measureText(row+ch).width>e.w-12){
       c.fillText(row,e.x+6,y);y+=e.size*1.35;row='';
      }
      row+=ch;
     }
     c.fillText(row,e.x+6,y);y+=e.size*1.35;
    }
    c.restore();
   }
   if(!output&&selected.has(e.id)){
    c.strokeStyle='#60a5fa';c.setLineDash([4,3]);c.strokeRect(e.x,e.y,e.w,e.h);
    c.fillStyle='#60a5fa';c.fillRect(e.x+e.w-6,e.y+e.h-6,12,12);
   }
  }
  c.restore();
 });
}
function hit(q){
 return items.filter(e=>visible(e,v.currentTime))
 .sort((a,b)=>order[b.type]-order[a.type]).find(e=>{
  if(e.type==='E'){
   const p=endpoints(e);
   return p&&[p[0],p[3]].some(x=>Math.hypot(x.x-q.x,x.y-q.y)<15);
  }
  return q.x>=e.x&&q.x<=e.x+e.w&&q.y>=e.y&&q.y<=e.y+e.h;
 });
}
canvas.onpointerdown=e=>{
 if(e.button!==0||!editing||locked())return;
 commitText();v.pause();
 const q=pointer(e),item=hit(q);
 if(connection){
  if(!item||!['A','B','C'].includes(item.type))return;
  if(connection==='start'){connection=item.id;message('接続先をクリックしてください');return}
  const first=find(connection);
  if(!first||(first.type==='A')===(item.type==='A')){
   message('コメントと強調枠、またはコメントと拡大枠を選択してください');return;
  }
  const a=first.type==='A'?item:first,b=first.type==='A'?first:item;
  const from=nearest(a,{x:b.x+b.w/2,y:b.y+b.h/2});
  const line={
   id:uid(),type:'E',owner:a.id,comment:b.id,from,to:nearest(b,port(a,from)),
   stroke:a.stroke,width:a.width,dash:a.dash,line:'orthogonal',terminal:'arrow'
  };
  items.push(line);selected=new Set([line.id]);connection=null;dirty=true;paint();return;
 }
 select(item,e);tracks();paint();if(!item)return;
 const old=clone(item);
 const resize=item.type!=='E'&&q.x>item.x+item.w-18&&q.y>item.y+item.h-18;
 const ends=item.type==='E'?endpoints(item):null;
 const end=ends&&Math.hypot(ends[0].x-q.x,ends[0].y-q.y)<15?'from':'to';
 drag(e,event=>{
  const p=pointer(event);
  if(item.type==='E')item[end]=nearest(find(end==='from'?item.owner:item.comment),p);
  else if(resize){
   item.w=clamp(old.w+p.x-q.x,20,canvas.width-item.x);
   item.h=clamp(old.h+p.y-q.y,20,canvas.height-item.y);
  }else{
   item.x=clamp(old.x+p.x-q.x,0,canvas.width-item.w);
   item.y=clamp(old.y+p.y-q.y,0,canvas.height-item.h);
  }
  dirty=true;paint();
 });
};
canvas.ondblclick=e=>{
 if(!editing||locked())return;
 const item=hit(pointer(e));if(item?.type!=='A')return;
 commitText();v.pause();
 const node=document.createElement('div'),k=canvas.clientWidth/canvas.width;
 node.className='editor';node.contentEditable='true';node.innerText=item.text;
 Object.assign(node.style,{
  left:item.x*k+'px',top:item.y*k+'px',width:item.w*k+'px',height:item.h*k+'px',
  fontSize:item.size*k+'px',fontFamily:item.font,color:item.color,
  background:item.background==='transparent'?'#172235ee':item.background
 });
 $('surface').append(node);textEditor={node,item};
 node.oninput=()=>{item.text=node.innerText;dirty=true};
 node.onblur=()=>{commitText();tracks()};node.focus();
};

function showMenu(e){
 const node=$('menu');node.hidden=false;
 node.style.left=clamp(e.clientX,4,Math.max(4,innerWidth-node.offsetWidth-4))+'px';
 node.style.top=clamp(e.clientY,4,Math.max(4,innerHeight-node.offsetHeight-4))+'px';
}
function menu(e,entries){
 e.preventDefault();if(locked())return;
 $('menu').replaceChildren();
 entries.forEach(([label,fn])=>{
  const b=document.createElement('button');b.textContent=label;
  b.onclick=safe(async()=>{$('menu').hidden=true;if(!locked())await fn()});
  $('menu').append(b);
 });showMenu(e);
}
function viewport(){
 const span=Math.max(.001,duration()*(.1+.9*(Number($('scale').value)-1)/9));
 return{
  span,start:clamp(v.currentTime-span/2,0,Math.max(0,duration()-span)),
  width:Math.max(1,$('tracks').clientWidth-20)
 };
}
function timeAt(e,r=viewport()){
 return clamp(r.start+(e.clientX-$('tracks').getBoundingClientRect().left-10)/r.width*r.span,0,duration());
}
function clock(){
 $('clock').textContent=v.currentTime.toFixed(1)+' / '+duration().toFixed(1)+' 秒';
 const r=viewport();
 $('playhead').style.left=10+(v.currentTime-r.start)/r.span*r.width+'px';
}
function tracks(){
 if(!editing)return;
 const root=$('tracks'),r=viewport();
 root.querySelectorAll('.tick,.clip').forEach(n=>n.remove());
 for(let i=0;i<=8;i++){
  const tick=document.createElement('span');tick.className='tick';
  tick.style.left=10+r.width*i/8+'px';tick.textContent=(r.start+r.span*i/8).toFixed(1)+'s';
  if(i===8)tick.style.transform='translateX(-100%)';root.append(tick);
 }
 const rows=[];
 items.filter(e=>e.type!=='E').sort((a,b)=>a.start-b.start).forEach(item=>{
  let row=rows.findIndex(end=>end<=item.start);if(row<0)row=rows.length;
  rows[row]=Math.max(item.end,item.start+.001);
  if(item.end<r.start||item.start>r.start+r.span)return;
  const node=document.createElement('div');
  node.className='clip '+item.type+(selected.has(item.id)?' selected':'');
  node.textContent=item.type==='A'?item.text:names[item.type];
  node.title=item.start.toFixed(2)+' ～ '+item.end.toFixed(2)+' 秒';
  const left=10+(Math.max(item.start,r.start)-r.start)/r.span*r.width;
  const width=item.type==='F'?8:(Math.min(item.end,r.start+r.span)-Math.max(item.start,r.start))/r.span*r.width;
  Object.assign(node.style,{
   left:left+'px',top:26+row*29+'px',
   width:Math.max(2,Math.min(root.clientWidth-10-left,width))+'px'
  });
  if(item.type!=='F')['start','end'].forEach(side=>{
   if(side==='start'&&item.start<r.start||side==='end'&&item.end>r.start+r.span)return;
   const edge=document.createElement('span');edge.className='edge '+side;node.append(edge);
  });
  node.oncontextmenu=e=>{e.stopPropagation();elementMenu(e,item,true)};
  node.onpointerdown=e=>{
   if(e.button!==0||locked())return;
   e.stopPropagation();commitText();v.pause();select(item,e);
   const old=clone(item),x=e.clientX;
   const side=e.target.classList.contains('start')?'start':e.target.classList.contains('end')?'end':'move';
   drag(e,event=>{
    const delta=(event.clientX-x)/r.width*r.span;
    if(side==='start')item.start=clamp(old.start+delta,0,item.end-.001);
    else if(side==='end')item.end=clamp(old.end+delta,item.start+.001,duration());
    else{
     const length=old.end-old.start;
     item.start=clamp(old.start+delta,0,duration()-length);
     item.end=item.start+length;
    }
    dirty=true;tracks();paint();
   });tracks();paint();
  };root.append(node);
 });
 root.style.height=Math.max(120,rows.length*29+30)+'px';clock();
}
function add(type,time,q){
 if(!duration()||time>=duration()){message('動画終了より前の位置に追加してください');return}
 const item={id:uid(),type,start:time,end:type==='F'?time:Math.min(duration(),time+3)};
 if(['A','B','C'].includes(type)){
  const w=Math.min(260,canvas.width),h=Math.min(110,canvas.height);
  Object.assign(item,{
   x:clamp(q.x,0,canvas.width-w),y:clamp(q.y,0,canvas.height-h),w,h,
   text:'コメント',size:24,font:'sans-serif',color:'#ffffff',
   stroke:'#ef4444',width:3,dash:'solid',shape:'rect',background:'transparent',zoom:2
  });
 }
 items.push(item);selected=new Set([item.id]);dirty=true;tracks();paint();
}
function elementMenu(e,item,timeline){
 e.preventDefault();if(!editing||locked())return;
 commitText();v.pause();
 if(item&&!selected.has(item.id))selected=new Set([item.id]);
 const time=timeline?timeAt(e):v.currentTime;
 const q=timeline?{x:canvas.width*.1,y:canvas.height*.1}:pointer(e);
 const entries=(timeline?['A','B','C','D','F']:['A','B','C'])
 .map(type=>[names[type]+'追加',()=>add(type,time,q)]);
 if(!timeline)entries.push(['接続線追加',()=>{
  connection='start';message('接続開始側をクリックしてください。Escで中止。');
 }]);
 if(timeline&&selected.size>1)entries.push(['開始位置を合わせる',()=>{
  const list=items.filter(x=>selected.has(x.id)&&x.type!=='E');if(!list.length)return;
  const start=item?.start??Math.min(...list.map(x=>x.start));
  if(list.some(x=>start+x.end-x.start>duration())){
   message('動画終了を超えるため変更できません');return;
  }
  list.forEach(x=>{x.end=start+x.end-x.start;x.start=start});
  dirty=true;tracks();paint();
 }]);
 if(item){
  if(!['D','F'].includes(item.type))entries.push(['書式変更',()=>format(item,e)]);
  entries.push(['選択要素を削除',()=>{
   items=items.filter(x=>!selected.has(x.id)&&!selected.has(x.owner)&&!selected.has(x.comment));
   selected.clear();dirty=true;tracks();paint();
  }]);
 }
 menu(e,entries);
}
function format(item,e){
 const root=$('menu');root.replaceChildren();
 const fields=[
  ...(item.type==='A'?[
   ['文字サイズ','size',Array.from({length:31},(_,i)=>[i+10,i+10])],
   ['フォント','font',[['sans-serif','ゴシック'],['serif','明朝']]]
  ]:[]),
  ['線の太さ','width',[[1,'━'],[2,'━━'],[3,'━━━━'],[5,'████'],[8,'██████']]],
  ['線種','dash',[['solid','実線'],['dash','破線'],['dot','点線'],['chain','一点鎖線']]],
  ...(item.type==='E'?[
   ['線形','line',[['straight','直線'],['orthogonal','折れ線'],['wave','1周期波線']]],
   ['終端','terminal',[['none','なし'],['arrow','矢印'],['circle','丸']]]
  ]:[['図形','shape',[['rect','四角'],['ellipse','楕円'],['round','角丸四角']]]]),
  ...(item.type==='C'?[['倍率','zoom',[[1,'1倍'],[1.5,'1.5倍'],[2,'2倍'],[3,'3倍'],[4,'4倍'],[6,'6倍']]]]:[])
 ];
 fields.forEach(([label,key,options])=>{
  const row=document.createElement('label'),select=document.createElement('select');
  row.textContent=label;
  options.forEach(([value,text])=>select.add(new Option(text,value)));
  select.value=item[key];
  select.onchange=()=>{
   item[key]=['size','width','zoom'].includes(key)?Number(select.value):select.value;
   dirty=true;paint();
  };
  row.append(select);root.append(row);
 });
 const target=document.createElement('select');
 target.add(new Option('線色','stroke'));
 if(item.type!=='E')target.add(new Option('背景色','background'));
 if(item.type==='A')target.add(new Option('文字色','color'));
 root.append(target);
 const palette=document.createElement('div');palette.className='palette';
 colors.forEach(color=>{
  const b=document.createElement('button');
  b.title=color==='transparent'?'塗り潰しなし':color;
  b.style.background=color==='transparent'?'repeating-linear-gradient(45deg,#fff 0 4px,#bbb 4px 8px)':color;
  b.onclick=()=>{item[target.value]=color;dirty=true;paint()};palette.append(b);
 });root.append(palette);showMenu(e);
}

/* 録画・編集出力 */
function mime(mp4Only=false){
 if(!window.MediaRecorder)throw Error('このブラウザは録画に対応していません');
 const candidates=mp4Only?
 ['video/mp4;codecs=avc1.42E01E,mp4a.40.2','video/mp4']:
 ['video/mp4;codecs=avc1.42E01E,mp4a.40.2','video/mp4','video/webm;codecs=vp8,opus','video/webm'];
 const type=candidates.find(t=>MediaRecorder.isTypeSupported(t));
 if(!type)throw Error(mp4Only?
 'このブラウザはMP4生成に対応していません。MP4録画対応ブラウザで開いてください。':
 '対応する録画形式がありません');
 return type;
}
function collect(writer){
 return new Promise((resolve,reject)=>{
  const chunks=[];
  writer.ondataavailable=e=>{if(e.data.size)chunks.push(e.data)};
  writer.onstop=()=>resolve(new Blob(chunks,{type:writer.mimeType}));
  writer.onerror=e=>reject(e.error||Error('動画生成に失敗しました'));
 });
}
async function sound(){
 if(!audioContext){
  audioContext=new AudioContext();
  audioSource=audioContext.createMediaElementSource(v);
  audioDestination=audioContext.createMediaStreamDestination();
  audioSource.connect(audioContext.destination);
  audioSource.connect(audioDestination);
 }
 await audioContext.resume();
}
function mergedSkips(){
 const result=[];
 items.filter(e=>e.type==='D').map(e=>[
  clamp(e.start,0,duration()),clamp(e.end,0,duration())
 ]).filter(([a,b])=>b>a).sort((a,b)=>a[0]-b[0]).forEach(([a,b])=>{
  const last=result.at(-1);
  if(last&&a<=last[1])last[1]=Math.max(last[1],b);
  else result.push([a,b]);
 });
 return result;
}
function playable(){
 const result=[];let cursor=0;
 for(const[a,b]of mergedSkips()){
  if(a>cursor)result.push([cursor,a]);cursor=b;
 }
 if(cursor<duration())result.push([cursor,duration()]);
 return result;
}
function skip(t){
 for(const[a,b]of mergedSkips())if(t>=a&&t<b)t=b;
 return Math.min(t,duration());
}
function jobDialog(){
 const d=document.createElement('dialog');
 const p=document.createElement('p'),b=document.createElement('button');
 p.textContent='編集動画を生成しています。画面を開いたままお待ちください。';
 b.textContent='キャンセル';b.onclick=()=>{abortJob=true;b.disabled=true};
 d.oncancel=e=>{e.preventDefault();abortJob=true};
 d.append(p,b);document.body.append(d);d.showModal();
 return{node:d,label:p};
}
async function generate(){
 const type=mime(true),segments=playable();
 if(!segments.length)throw Error('全区間がスキップされているため動画を生成できません');
 if(!HTMLCanvasElement.prototype.captureStream)throw Error('このブラウザは編集動画の生成に対応していません');
 const oldTime=v.currentTime,oldMuted=v.muted,oldRate=v.playbackRate;
 const output=document.createElement('canvas');
 output.width=canvas.width;output.height=canvas.height;
 const c=output.getContext('2d');
 let stream,writer,result,detached=false,frame=0,job;
 abortJob=false;
 v.pause();
 try{
  await sound();
  audioSource.disconnect(audioContext.destination);detached=true;
  stream=output.captureStream(30);
  audioDestination.stream.getAudioTracks().forEach(t=>stream.addTrack(t.clone()));
  writer=new MediaRecorder(stream,{mimeType:type});
  result=collect(writer);result.catch(()=>{});
  job=jobDialog();v.muted=false;v.playbackRate=1;
  const length=segments.reduce((sum,[a,b])=>sum+b-a,0);
  let completed=0,started=false;
  for(const[a,b]of segments){
   if(abortJob)throw Error('動画生成をキャンセルしました');
   await seek(a);
   c.drawImage(v,0,0,output.width,output.height);paint(c,a,true);
   if(!started){writer.start(200);started=true}
   else writer.resume();
   await v.play();
   await Promise.race([
    new Promise((resolve,reject)=>{
     const loop=()=>{
      try{
       if(abortJob){reject(Error('動画生成をキャンセルしました'));return}
       if(v.error)throw Error('動画再生に失敗しました');
       if(v.ended||v.currentTime>=b){
        v.pause();if(writer.state==='recording')writer.pause();resolve();return;
       }
       if(v.readyState>=2&&!v.seeking){
        c.drawImage(v,0,0,output.width,output.height);paint(c,v.currentTime,true);
       }
       const progress=clamp((completed+v.currentTime-a)/length*100,0,100);
       job.label.textContent='編集動画を生成中：'+progress.toFixed(0)+'％';
       frame=requestAnimationFrame(loop);
      }catch(e){reject(e)}
     };
     frame=requestAnimationFrame(loop);
    }),
    result.then(()=>{throw Error('動画生成が途中で終了しました')})
   ]);
   completed+=b-a;
  }
  writer.stop();const blob=await result;
  if(!blob.size)throw Error('編集動画を生成できませんでした');
  return{blob,outputDuration:length};
 }finally{
  cancelAnimationFrame(frame);v.pause();
  if(writer&&writer.state!=='inactive')writer.stop();
  stream?.getTracks().forEach(t=>t.stop());
  if(detached)audioSource.connect(audioContext.destination);
  v.muted=oldMuted;v.playbackRate=oldRate;
  job?.node.close();job?.node.remove();
  await seek(oldTime);
 }
}
async function save(){
 commitText();
 if(!current||locked())return;
 busy=true;update();
 try{
  const {blob,outputDuration}=await generate();
  const id=revision?.id||uid();
  await change(()=>{
   if(!revision){
    revision={id,name:'編集 '+(current.edits.length+1)};
    current.edits.push(revision);
   }
   Object.assign(revision,{
    items:clone(items),date:Date.now(),outputDuration,rendered:true
   });
  },s=>s.put(blob,'render:'+id));
  dirty=false;message('編集データとMP4編集動画を保存しました');
 }finally{busy=false;update()}
}
async function record(){
 if(recordWriter?.state==='recording'){recordWriter.stop();return}
 if(!await leave())return;
 if(!navigator.mediaDevices?.getDisplayMedia)throw Error('画面録画にはHTTPSまたはlocalhostが必要です');
 const type=mime();
 busy=true;update();
 try{
  recordStream=await navigator.mediaDevices.getDisplayMedia({video:true,audio:true});
  reset();v.srcObject=recordStream;v.muted=true;await v.play();
  recordWriter=new MediaRecorder(recordStream,{mimeType:type});
  const result=collect(recordWriter);result.catch(()=>{});
  recordStream.getVideoTracks()[0].onended=()=>{
   if(recordWriter?.state==='recording')recordWriter.stop();
  };
  const began=performance.now();
  recordWriter.start(250);busy=false;update();
  message('録画中。共有ダイアログで選択したシステム音声を含めます。');
  const blob=await result,seconds=(performance.now()-began)/1000;
  busy=true;update();
  recordStream.getTracks().forEach(t=>t.stop());recordStream=null;
  v.srcObject=null;v.muted=false;
  const item={
   id:uid(),name:'録画 '+new Date().toLocaleString('ja-JP'),date:Date.now(),
   duration:seconds,original:{id:uid(),name:'元動画',date:Date.now()},edits:[]
  };
  await change(()=>model.folders[0].videos.push(item),s=>s.put(blob,'video:'+item.id));
  busy=false;await load(item);
  message('録画を保存しました。編集開始から編集できます。');
 }finally{
  recordStream?.getTracks().forEach(t=>t.stop());
  recordStream=null;recordWriter=null;v.srcObject=null;v.muted=false;
  busy=false;update();
 }
}
function downloadBlob(blob,name){
 if(!blob?.size)throw Error('動画データがありません');
 const url=URL.createObjectURL(blob),d=document.createElement('dialog');
 const a=document.createElement('a'),b=document.createElement('button');
 const ext=blob.type.includes('mp4')?'mp4':blob.type.includes('webm')?'webm':blob.type.includes('quicktime')?'mov':'video';
 a.href=url;a.download=name.replace(/[\\/:*?"<>|]/g,'_')+'.'+ext;
 a.textContent=a.download+' を保存';
 a.style.display='block';a.style.margin='12px 0';
 b.textContent='閉じる';b.onclick=()=>d.close();
 d.append(a,b);document.body.append(d);
 d.onclose=()=>{d.remove();setTimeout(()=>URL.revokeObjectURL(url),60000)};
 d.showModal();
}
async function downloadCurrent(){
 if(editing){
  if(dirty||!revision?.rendered)await save();
  downloadBlob(await read('render:'+revision.id),current.name+'_'+revision.name);
 }else if(revision&&baked){
  downloadBlob(await read('render:'+revision.id),current.name+'_'+revision.name);
 }else{
  const blob=await read('video:'+current.id);
  if(blob.type.includes('mp4'))downloadBlob(blob,current.name);
  else{
   busy=true;update();
   const savedItems=items;items=[];
   try{
    const result=await generate();
    downloadBlob(result.blob,current.name);
   }finally{items=savedItems;busy=false;update()}
  }
 }
}

/* PDF */
async function openPDF(){
 if(!items.some(e=>e.type==='F')){
  message('編集画面のタイムラインを右クリックしてPDF位置を追加してください');return;
 }
 if(baked){
  busy=true;update();
  try{await setSource(await read('video:'+current.id),current.duration);baked=false;editing=true}
  finally{busy=false;update()}
 }
 $('pdfDialog').showModal();
}
async function printPDF(){
 $('pdfDialog').close();commitText();v.pause();
 const old=v.currentTime,count=Number(document.querySelector('[name=layout]:checked').value);
 const times=items.filter(e=>e.type==='F').map(e=>e.start).sort((a,b)=>a-b);
 $('printArea').replaceChildren();busy=true;update();
 try{
  let sheet;
  for(let i=0;i<times.length;i++){
   await seek(Math.min(times[i],Math.max(0,duration()-.001)));
   const c=document.createElement('canvas');c.width=canvas.width;c.height=canvas.height;
   const context=c.getContext('2d');
   context.drawImage(v,0,0,c.width,c.height);paint(context,v.currentTime,true);
   if(i%count===0){
    sheet=document.createElement('div');sheet.className='sheet';$('printArea').append(sheet);
   }
   const shot=document.createElement('div'),img=new Image(),p=document.createElement('p');
   shot.className='shot';img.src=c.toDataURL('image/png');
   img.style.maxHeight=count===2?'115mm':'245mm';await img.decode();
   p.textContent=current.name+' ／ '+times[i].toFixed(1)+' 秒';
   shot.append(img,p);sheet.append(shot);
  }
  window.print();
 }finally{await seek(old);busy=false;update()}
}

/* 動画一覧 */
async function remove(parent,item,type){
 if(!await leave())return;
 if(!confirm(type==='edit'?'この編集と生成済み編集動画を削除しますか？':
 '配下の元動画・編集データ・編集動画も削除されます。削除しますか？'))return;
 const affected=type==='folder'?folders([item]).flatMap(f=>f.videos):type==='video'?[item]:[];
 const edits=type==='edit'?[item]:affected.flatMap(v=>v.edits);
 const clearCurrent=affected.some(x=>x.id===current?.id)||edits.some(x=>x.id===revision?.id);
 await change(()=>{
  const list=type==='folder'?(parent?parent.children:model.folders):
   type==='video'?parent.videos:parent.edits;
  list.splice(list.indexOf(item),1);
  if(!model.folders.length)model.folders.push(newFolder('デフォルトフォルダ'));
 },s=>{
  affected.forEach(x=>s.delete('video:'+x.id));
  edits.forEach(x=>s.delete('render:'+x.id));
 });
 if(clearCurrent){reset();update()}
}
function tree(){
 const root=$('tree');root.replaceChildren();
 function row(item,depth,open,branch=false){
  const n=document.createElement('div'),toggle=document.createElement('button');
  const name=document.createElement('span'),time=document.createElement('time');
  n.className='row'+(item.id===revision?.id||item.id===current?.id?' active':'');
  n.style.marginLeft=depth*16+'px';
  toggle.textContent=branch?(collapsed.has(item.id)?'▸':'▾'):'·';
  name.className='name';name.textContent=item.name;
  time.textContent=new Date(item.date).toLocaleString('ja-JP');
  n.append(toggle,name,time);root.append(n);
  toggle.onclick=safe(async()=>{
   if(locked())return;
   if(branch){collapsed.has(item.id)?collapsed.delete(item.id):collapsed.add(item.id);tree()}
   else await open?.();
  });
  let timer;
  name.onclick=e=>{
   clearTimeout(timer);
   if(e.detail===1)timer=setTimeout(safe(async()=>{
    if(!locked()&&!name.isContentEditable)await open?.();
   }),300);
  };
  name.ondblclick=()=>{
   clearTimeout(timer);
   if(!locked()){name.contentEditable='true';name.focus()}
  };
  name.onblur=safe(async()=>{
   if(!name.isContentEditable)return;
   name.contentEditable='false';
   const next=name.textContent.trim()||item.name;
   await change(()=>item.name=next);
  });
  name.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();name.blur()}};
  return n;
 }
 function outputs(item,edit=null){
  return[
   ...(edit?[
    ['編集する',()=>load(item,edit,true)],
    ['保存済み編集動画を再生',async()=>{
     if(await load(item,edit,false)){
      if(audioContext)await audioContext.resume();await v.play();
     }
    }]
   ]:[]),
   ['ダウンロード',async()=>{
    if(!await leave())return;
    if(edit){
     downloadBlob(await read('render:'+edit.id),item.name+'_'+edit.name);
    }else downloadBlob(await read('video:'+item.id),item.name);
   }],
   ['PDF出力',async()=>{if(await load(item,edit,!!edit))await openPDF()}]
  ];
 }
 function visit(f,parent,depth){
  const n=row(f,depth,null,true);
  n.oncontextmenu=e=>menu(e,[
   ['子フォルダ追加',()=>change(()=>{
    f.children.push(newFolder('新しいフォルダ'));collapsed.delete(f.id);
   })],
   ['フォルダ削除',()=>remove(parent,f,'folder')]
  ]);
  n.ondragover=e=>{if(!locked()){e.preventDefault();n.classList.add('drop')}};
  n.ondragleave=()=>n.classList.remove('drop');
  n.ondrop=safe(async e=>{
   e.preventDefault();n.classList.remove('drop');if(locked())return;
   const id=e.dataTransfer.getData('text/plain');
   const from=folders().find(x=>x.videos.some(v=>v.id===id));if(!from||from===f)return;
   await change(()=>{
    const item=from.videos.find(x=>x.id===id);
    from.videos=from.videos.filter(x=>x.id!==id);f.videos.push(item);collapsed.delete(f.id);
   });
  });
  if(collapsed.has(f.id))return;
  f.children.forEach(x=>visit(x,f,depth+1));
  f.videos.forEach(item=>{
   const group=row(item,depth+1,()=>load(item),true);group.draggable=true;
   group.ondragstart=e=>{
    if(locked()||e.target.isContentEditable)e.preventDefault();
    else e.dataTransfer.setData('text/plain',item.id);
   };
   group.oncontextmenu=e=>menu(e,[...outputs(item),['動画削除',()=>remove(f,item,'video')]]);
   if(collapsed.has(item.id))return;
   row(item.original,depth+2,()=>load(item)).oncontextmenu=e=>
    menu(e,[...outputs(item),['動画削除',()=>remove(f,item,'video')]]);
   item.edits.forEach(edit=>{
    row(edit,depth+2,()=>load(item,edit,true)).oncontextmenu=e=>
     menu(e,[...outputs(item,edit),['編集削除',()=>remove(item,edit,'edit')]]);
   });
  });
 }
 model.folders.forEach(f=>visit(f,null,0));
}

/* イベント・画面サイズ */
$('list').onclick=()=>library(true);
$('closeList').onclick=$('veil').onclick=()=>library(false);
$('upload').onclick=$('upload2').onclick=()=>$('file').click();
$('folder').onclick=safe(()=>change(()=>model.folders.push(newFolder('新しいフォルダ'))));
$('new').onclick=safe(async()=>{
 if(await leave()){reset();library(false);update();message('録画開始を押してください')}
});
$('file').onchange=safe(async e=>{
 const file=e.target.files[0];e.target.value='';
 if(!file||!await leave())return;
 busy=true;update();
 try{
  reset();await setSource(file);
  const item={
   id:uid(),name:file.name.replace(/\.[^.]+$/,''),date:Date.now(),duration:duration(),
   original:{id:uid(),name:'元動画',date:Date.now()},edits:[]
  };
  await change(()=>model.folders[0].videos.push(item),s=>s.put(file,'video:'+item.id));
  current=item;library(false);message('動画を保存しました。編集開始から編集できます。');
 }finally{busy=false;update();tree()}
});
$('primary').onclick=safe(async()=>{
 if(recordStream||!editing){await record();return}
 commitText();
 if(v.paused){
  if(audioContext)await audioContext.resume();
  if(v.ended||v.currentTime>=duration())await seek(0);
  const next=skip(v.currentTime);if(next>v.currentTime)await seek(next);
  if(next<duration())await v.play();
 }else v.pause();
 update();
});
$('edit').onclick=safe(async()=>{
 if(baked){await setSource(await read('video:'+current.id),current.duration);baked=false}
 editing=true;v.pause();await seek(0);update();
 message('右クリックで要素追加。コメントはダブルクリックで直接編集できます。');
});
$('save').onclick=safe(save);
$('download').onclick=safe(downloadCurrent);
$('finish').onclick=safe(async()=>{
 if(!await leave())return;
 if(revision){
  await setSource(await read('render:'+revision.id),revision.outputDuration);baked=true;
 }else await seek(0);
 editing=false;update();
});
$('pdf').onclick=safe(openPDF);
$('print').onclick=safe(printPDF);
$('cancelPDF').onclick=()=>$('pdfDialog').close();
canvas.oncontextmenu=e=>elementMenu(e,hit(pointer(e)),false);
$('tracks').oncontextmenu=e=>elementMenu(e,null,true);
$('tracks').onpointerdown=e=>{
 if(e.button===0&&!locked()&&!e.target.closest('.clip')){
  commitText();v.pause();v.currentTime=timeAt(e);
 }
};
$('handle').onpointerdown=e=>{
 if(locked())return;e.stopPropagation();commitText();v.pause();
 const r=viewport();drag(e,event=>v.currentTime=timeAt(event,r));
};
$('scale').oninput=()=>{tracks();paint()};
$('viewZoom').oninput=()=>{commitText();fit();paint()};
$('split').onpointerdown=e=>{
 if(locked())return;
 commitText();
 const y=e.clientY,h=$('timeline').getBoundingClientRect().height;
 drag(e,event=>{
  const max=Math.max(100,$('main').clientHeight-180);
  $('timeline').style.height=clamp(h+y-event.clientY,100,max)+'px';
  fit();tracks();paint();
 });
};
v.onseeked=()=>{tracks();paint()};
v.onplay=v.onpause=v.onended=update;
v.onerror=()=>message('動画を再生できません。動画形式を確認してください。');
document.addEventListener('pointerdown',e=>{
 if(!e.target.closest('#menu'))$('menu').hidden=true;
});
document.addEventListener('keydown',e=>{
 if(e.key==='Escape'){connection=null;$('menu').hidden=true}
});
window.addEventListener('beforeunload',e=>{
 if(dirty||locked()){e.preventDefault();e.returnValue=''}
});
new ResizeObserver(()=>{fit();tracks();paint()}).observe($('stage'));
let lastStart=-1,seekingSkip=false;
async function loop(){
 if(!busy&&editing&&!v.paused&&!seekingSkip){
  const next=skip(v.currentTime);
  if(next>v.currentTime){
   seekingSkip=true;v.pause();
   try{await seek(next);if(next<duration())await v.play()}
   catch(e){message(e.message)}
   finally{seekingSkip=false}
  }
 }
 paint();
 if(editing){
  const start=viewport().start;
  if(Math.abs(start-lastStart)>.05){lastStart=start;tracks()}
  else clock();
 }
 animation=requestAnimationFrame(loop);
}
try{
 db=await new Promise((resolve,reject)=>{
  const request=indexedDB.open('VideoEditorRequirementsMock',1);
  request.onupgradeneeded=()=>request.result.createObjectStore('data');
  request.onsuccess=()=>resolve(request.result);
  request.onerror=()=>reject(request.error);
 });
 model=await read('model')||{folders:[newFolder('デフォルトフォルダ')]};
 await storage('readwrite',s=>s.put(model,'model'));
 tree();update();loop();
}catch(e){
 message('保存領域を初期化できませんでした：'+e.message);
 document.querySelectorAll('button').forEach(b=>b.disabled=true);
}
})();
</script>
</body>
</html>
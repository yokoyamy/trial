<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集</title>
<style>
*{box-sizing:border-box}html,body{margin:0;height:100%;font-family:Arial,"Noto Sans JP",sans-serif;color:#222;background:#f4f5f7}
button{font:inherit;cursor:pointer;border:1px solid #ccd1d8;background:#fff;border-radius:5px;padding:7px 11px}
.app{height:100vh;display:flex;flex-direction:column}.head{height:52px;background:#fff;border-bottom:1px solid #ddd;display:flex;align-items:center;padding:0 14px;gap:8px}
.title{font-weight:700;font-size:17px}.head .target{font-size:12px;color:#666;margin-left:10px}.head .actions{margin-left:auto;display:flex;gap:6px}
.primary{background:#2864d7;color:#fff;border-color:#2864d7}.work{flex:1;min-height:0;padding:10px;display:flex;flex-direction:column;gap:8px}
.video{position:relative;flex:1;min-height:0;background:#111;border-radius:7px;display:flex;align-items:center;justify-content:center;overflow:hidden;color:#aaa}
video{width:100%;height:100%;object-fit:contain}.empty{position:absolute;text-align:center}.badge{position:absolute;left:8px;top:8px;background:#000a;color:#fff;border-radius:4px;padding:4px 7px;font-size:11px}
.elem{position:absolute;min-width:90px;min-height:38px;border:2px solid #2d69d7;background:#fff8;border-radius:5px;padding:6px 9px;cursor:move;font-size:12px}
.elem.selected{outline:2px solid #fff;box-shadow:0 0 0 3px #2864d7}.skip{position:absolute;top:4px;bottom:4px;background:#e9edf2aa;border:1px dashed #7b8794}
.line{position:absolute;pointer-events:none}.toolbar{height:42px;background:#fff;border:1px solid #ddd;border-radius:6px;display:flex;align-items:center;gap:5px;padding:5px}
.toolbar .state{margin-left:auto;color:#666;font-size:12px}.timeline{height:118px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:7px;overflow:hidden}
.thead{height:23px;display:flex;align-items:center;font-size:12px;color:#555}.zoom{margin-left:auto;display:flex;gap:4px}.zoom button{padding:3px 8px}.ruler{height:78px;position:relative;border-top:1px solid #aaa;margin-top:3px}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #bbb;font-size:10px;color:#666;padding-left:3px}.cursor{position:absolute;top:0;height:100%;width:2px;background:#e33;z-index:5}.seek{position:absolute;inset:0;cursor:pointer;z-index:6}
.modal{position:fixed;inset:0;background:#0007;display:none;align-items:center;justify-content:center;z-index:20}.modal.on{display:flex}
.box{width:430px;max-width:calc(100% - 24px);background:#fff;border-radius:8px;padding:16px}.box h2{font-size:17px;margin:0 0 12px}.row{border:1px solid #ddd;border-radius:6px;padding:10px;margin:7px 0;cursor:pointer}.row b{display:block}.row small{color:#666}.close{display:flex;justify-content:flex-end;margin-top:12px}
.menu{position:fixed;display:none;background:#fff;border:1px solid #bbb;border-radius:5px;box-shadow:0 5px 18px #0003;padding:4px;z-index:30}.menu.on{display:block}.menu button{display:block;border:0;width:180px;text-align:left}
</style>
</head>
<body>
<div class="app">
<header class="head">
<div class="title">動画編集</div><span class="target" id="target">編集対象：未選択</span>
<div class="actions"><button id="open">動画を開く</button><button class="primary" id="resume">編集作業を再開する</button></div>
</header>
<main class="work">
<section class="video" id="videoArea">
<div class="empty" id="empty">「動画を開く」または「編集作業を再開する」</div>
<video id="player" controls playsinline hidden></video><span class="badge" id="badge" hidden></span>
<svg class="line" id="lines"></svg>
</section>
<section class="toolbar">
<button data-add="コメント">コメント</button><button data-add="強調枠">強調枠</button><button data-add="拡大枠">拡大枠</button><button data-add="スキップ">スキップ</button><button id="connect">接続</button><button id="delete">削除</button>
<button id="save">保存</button><button id="finish">編集作業を終了する</button><button id="make">編集結果を動画にする</button><span class="state" id="state">未選択</span>
</section>
<section class="timeline">
<div class="thead"><span id="clock">00:00 / 00:00</span><div class="zoom"><button id="minus">−</button><span id="倍率">1.0x</span><button id="plus">＋</button></div></div>
<div class="ruler" id="ruler"><div class="seek" id="seek"></div><div class="cursor" id="cursor"></div></div>
</section>
</main>
</div>

<div class="modal" id="modal">
<div class="box"><h2>動画を開く</h2>
<div class="row" id="original"><b>オリジナル動画を選択</b><small>新しい編集作業を開始します</small></div>
<div class="row" id="saved"><b>保存済み編集作業を選択</b><small>保存した編集作業を再開します</small></div>
<div class="row" id="file"><b>動画ファイルを指定</b><small>ローカルの動画を編集対象にします</small></div>
<input id="picker" type="file" accept="video/*" hidden>
<div class="close"><button id="cancel">閉じる</button></div></div></div>
<div class="menu" id="menu"><button data-menu="hide">表示／非表示</button><button data-menu="delete">削除</button></div>

<script>
const $=id=>document.getElementById(id),p=$('player'),area=$('videoArea'),ruler=$('ruler');
const s={target:null,duration:30,scale:1,items:[],links:[],selected:null,source:null,connecting:false,dirty:false};
const demo='https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';

function time(v){return Math.floor(v/60)+':'+String(Math.floor(v%60)).padStart(2,'0')}
function showModal(){$('modal').classList.add('on')}
function closeModal(){$('modal').classList.remove('on')}
function start(src,name,resume){
p.src=src;p.hidden=false;$('empty').hidden=true;$('badge').hidden=false;$('badge').textContent=name;
$('target').textContent='編集対象：'+name;s.target=name;closeModal();s.dirty=false;
s.items=resume?[{id:1,type:'コメント',x:.2,y:.2,a:0,b:10,text:'コメント'},{id:2,type:'強調枠',x:.55,y:.45,a:4,b:14}]:[];
s.links=resume?[{a:1,b:2}]:[];s.selected=null;draw();p.load()
}
$('open').onclick=showModal;$('resume').onclick=()=>start(demo,'オリジナル動画',true);
$('cancel').onclick=closeModal;$('original').onclick=()=>start(demo,'オリジナル動画',false);
$('saved').onclick=()=>start(demo,'保存済み編集作業',true);
$('file').onclick=()=>$('picker').click();
$('picker').onchange=e=>{const f=e.target.files[0];if(f)start(URL.createObjectURL(f),f.name,false)}
p.onloadedmetadata=()=>{s.duration=p.duration||30;rulerDraw();clock()}
p.ontimeupdate=clock;
function clock(){$('clock').textContent=time(p.currentTime)+' / '+time(s.duration);$('cursor').style.left=(p.currentTime/s.duration*100)+'%'}
function rulerDraw(){
ruler.querySelectorAll('.tick').forEach(x=>x.remove());const px=Math.max(12,24*s.scale);
ruler.style.minWidth=Math.max(ruler.clientWidth,s.duration*px)+'px';const step=s.scale>=2?1:s.scale<1?10:5;
for(let t=0;t<=s.duration;t+=step){const e=document.createElement('div');e.className='tick';e.style.left=t*px+'px';e.textContent=time(t);ruler.appendChild(e)}clock()
}
$('seek').onclick=e=>{if(!s.target)return;const q=ruler.getBoundingClientRect();p.currentTime=Math.max(0,Math.min(1,(e.clientX-q.left)/q.width))*s.duration}
$('plus').onclick=()=>{s.scale=Math.min(4,s.scale*2);$('倍率').textContent=s.scale.toFixed(1)+'x';rulerDraw()}
$('minus').onclick=()=>{s.scale=Math.max(.5,s.scale/2);$('倍率').textContent=s.scale.toFixed(1)+'x';rulerDraw()}

document.querySelectorAll('[data-add]').forEach(b=>b.onclick=()=>{
if(!s.target)return;const n=s.items.length+1,id=Date.now(),type=b.dataset.add;
s.items.push({id,type,x:.1+(n%4)*.2,y:.15+(n%3)*.2,a:p.currentTime,b:Math.min(s.duration,p.currentTime+5),text:type});
s.selected=id;s.dirty=true;draw()
});

function item(){return s.items.find(x=>x.id===s.selected)}
function draw(){
area.querySelectorAll('.elem,.skip').forEach(x=>x.remove());
s.items.forEach(o=>{
if(o.type==='スキップ')return;const e=document.createElement('div');e.className='elem'+(o.id===s.selected?' selected':'');e.dataset.id=o.id;
e.style.left=o.x*100+'%';e.style.top=o.y*100+'%';e.textContent=o.text||o.type;
e.onmousedown=dragStart;e.onclick=ev=>{if(!drag){s.selected=o.id;draw()}};
e.oncontextmenu=ev=>{ev.preventDefault();s.selected=o.id;draw();menu(ev.clientX,ev.clientY)};area.appendChild(e)
});
s.items.filter(x=>x.type==='スキップ').forEach(o=>{
const e=document.createElement('div');e.className='skip';e.dataset.id=o.id;e.style.left=o.a/s.duration*100+'%';e.style.width=(o.b-o.a)/s.duration*100+'%';e.textContent='スキップ';area.appendChild(e)
});drawLines()
}
function drawLines(){
$('lines').innerHTML='';const box=area.getBoundingClientRect();
s.links.forEach(l=>{const a=area.querySelector('[data-id="'+l.a+'"]'),b=area.querySelector('[data-id="'+l.b+'"]');if(!a||!b)return;
const ar=a.getBoundingClientRect(),br=b.getBoundingClientRect(),x1=ar.right-box.left,y1=ar.top-box.top+ar.height/2,x2=br.left-box.left,y2=br.top-box.top+br.height/2;
const z=document.createElementNS('http://www.w3.org/2000/svg','path');z.setAttribute('d',`M${x1} ${y1} L${x2} ${y2}`);z.setAttribute('stroke','#2864d7');z.setAttribute('stroke-width','2');z.setAttribute('fill','none');$('lines').appendChild(z)})
}
let drag=null;
function dragStart(e){if(e.button!==0)return;const o=s.items.find(x=>x.id===+e.currentTarget.dataset.id);s.selected=o.id;drag={o,x:e.clientX,y:e.clientY};document.onmousemove=dragMove;document.onmouseup=dragEnd}
function dragMove(e){if(!drag)return;const q=area.getBoundingClientRect();drag.o.x=Math.max(0,Math.min(.9,drag.o.x+(e.clientX-drag.x)/q.width));drag.o.y=Math.max(0,Math.min(.9,drag.o.y+(e.clientY-drag.y)/q.height));drag.x=e.clientX;drag.y=e.clientY;draw()}
function dragEnd(){drag=null;document.onmousemove=null;document.onmouseup=null;s.dirty=true}
$('delete').onclick=()=>{if(s.selected){s.items=s.items.filter(x=>x.id!==s.selected);s.links=s.links.filter(x=>x.a!==s.selected&&x.b!==s.selected);s.selected=null;s.dirty=true;draw()}}
$('connect').onclick=()=>{s.connecting=!s.connecting;s.source=null;$('state').textContent=s.connecting?'接続元を選択':'編集中'}
area.addEventListener('click',e=>{
if(!s.connecting)return;const el=e.target.closest('.elem');if(!el)return;const id=+el.dataset.id;
if(s.source===null){s.source=id;$('state').textContent='接続先を選択'}
else if(s.source!==id){s.links.push({a:s.source,b:id});s.source=null;s.connecting=false;$('state').textContent='編集中';s.dirty=true;draw()}
});
function menu(x,y){$('menu').style.left=x+'px';$('menu').style.top=y+'px';$('menu').classList.add('on')}
document.querySelectorAll('[data-menu]').forEach(b=>b.onclick=()=>{const o=item();if(o&&b.dataset.menu==='hide')o.hidden=!o.hidden;if(o&&b.dataset.menu==='delete')$('delete').click();$('menu').classList.remove('on');draw()});
document.addEventListener('click',e=>{if(!e.target.closest('.menu'))$('menu').classList.remove('on')});
$('save').onclick=()=>{if(!s.target)return;s.dirty=false;$('state').textContent='保存しました'}
$('finish').onclick=()=>{if(s.dirty&&!confirm('未保存の変更があります。終了しますか？'))return;location.reload()}
$('make').onclick=()=>{if(!s.target)return;alert('編集結果動画を作成します。')}
window.onresize=()=>{rulerDraw();drawLines()};rulerDraw()
</script>
</body>
</html>
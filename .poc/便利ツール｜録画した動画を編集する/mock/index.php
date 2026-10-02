<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;height:100%;font-family:Arial,"Noto Sans JP",sans-serif;color:#222;background:#f3f4f6}
button{font:inherit;cursor:pointer}
.app{height:100vh;display:flex;flex-direction:column;overflow:hidden}
.header{height:54px;background:#fff;border-bottom:1px solid #d7d9dd;display:flex;align-items:center;padding:0 16px;gap:10px}
.logo{font-size:18px;font-weight:700}
.header-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.target{font-size:12px;color:#666}
button{border:1px solid #d4d8de;background:#fff;border-radius:5px;padding:7px 12px}
.primary{border:0;background:#2864d7;color:#fff;font-weight:700}
.workspace{flex:1;min-height:0;padding:10px;display:flex;flex-direction:column;gap:10px}
.preview{height:43%;min-height:220px;background:#111;border-radius:8px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;color:#aaa}
.preview video{width:100%;height:100%;object-fit:contain;background:#111}
.preview-label{position:absolute;top:9px;left:9px;background:#0009;color:#fff;padding:5px 8px;border-radius:4px;font-size:12px}
.editor{background:#fff;border:1px solid #d8dbe0;border-radius:8px;flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden}
.toolbar{height:46px;border-bottom:1px solid #e1e3e6;display:flex;align-items:center;gap:6px;padding:6px 9px}
.toolbar .status{margin-left:auto;color:#666;font-size:12px}
.area{position:relative;flex:1;min-height:150px;overflow:auto;background:#f8f9fb}
.objects{position:relative;min-width:700px;height:100%;min-height:230px;background-image:linear-gradient(#e8ebef 1px,transparent 1px),linear-gradient(90deg,#e8ebef 1px,transparent 1px);background-size:24px 24px}
.connections{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
.object{position:absolute;width:130px;min-height:55px;background:#fff;border:2px solid #2864d7;border-radius:7px;padding:9px;box-shadow:0 2px 5px #0001;cursor:move;z-index:2}
.object.selected{outline:2px solid #2864d7;outline-offset:2px}
.object.hidden{display:none}
.object-name{font-size:13px;font-weight:700}
.object-time{font-size:11px;color:#777;margin-top:4px}
.object-delete{position:absolute;right:3px;top:2px;border:0;background:transparent;color:#888;padding:0}
.timeline{height:112px;border-top:1px solid #dfe2e6;padding:8px 10px;flex:none}
.timeline-head{height:24px;display:flex;align-items:center;font-size:12px;color:#555}
.scale{margin-left:auto;display:flex;gap:5px;align-items:center}
.scale button{width:27px;height:24px;padding:0}
.scale span{width:45px;text-align:center}
.ruler{height:65px;position:relative;border-top:1px solid #aaa;overflow:hidden}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #bbb}
.tick span{position:absolute;top:4px;left:3px;font-size:10px;color:#666}
.playhead{position:absolute;top:0;width:2px;height:100%;background:#e23b3b;z-index:4}
.scrub{position:absolute;inset:0;z-index:5;cursor:pointer}
.modal{position:fixed;inset:0;background:#0007;display:none;align-items:center;justify-content:center;z-index:20}
.modal.show{display:flex}
.modal-box{width:390px;max-width:calc(100vw - 30px);background:#fff;border-radius:8px;padding:18px;box-shadow:0 12px 40px #0005}
.modal-box h2{margin:0 0 14px;font-size:18px}
.choice{display:block;width:100%;text-align:left;margin:7px 0;padding:12px}
.choice strong{display:block;margin-bottom:4px}
.choice small{color:#666}
.modal-actions{display:flex;justify-content:flex-end;margin-top:14px}
.context{position:fixed;display:none;background:#fff;border:1px solid #bbb;border-radius:5px;box-shadow:0 5px 18px #0003;z-index:30;padding:4px}
.context.show{display:block}
.context button{display:block;border:0;width:160px;text-align:left}
</style>
</head>
<body>
<div class="app">
<header class="header">
  <div class="logo">動画編集</div>
  <div class="header-right">
    <span class="target" id="target">編集対象：未選択</span>
    <button id="open">動画を開く</button>
    <button class="primary" id="resume">編集作業を再開する</button>
  </div>
</header>

<main class="workspace">
  <div class="preview" id="preview">
    <span id="empty">動画を開くか、編集作業を再開してください</span>
    <video id="video" controls playsinline style="display:none"></video>
    <div class="preview-label" id="label" style="display:none"></div>
  </div>

  <section class="editor">
    <div class="toolbar">
      <button id="comment">コメント</button>
      <button id="highlight">強調枠</button>
      <button id="zoom">拡大枠</button>
      <button id="skip">スキップ</button>
      <button id="connect">接続</button>
      <button id="delete">削除</button>
      <span class="status" id="status">通常操作</span>
    </div>

    <div class="area">
      <div class="objects" id="objects">
        <svg class="connections" id="lines"></svg>
      </div>
    </div>

    <div class="timeline">
      <div class="timeline-head">
        <span id="time">00:00.0 / 00:30.0</span>
        <div class="scale">
          <button id="minus">−</button>
          <span id="scale">1.0x</span>
          <button id="plus">＋</button>
        </div>
      </div>
      <div class="ruler" id="ruler">
        <div class="scrub" id="scrub"></div>
        <div class="playhead" id="playhead"></div>
      </div>
    </div>
  </section>
</main>
</div>

<div class="modal" id="modal">
  <div class="modal-box">
    <h2>動画を開く</h2>
    <button class="choice" data-type="original">
      <strong>オリジナル動画</strong>
      <small>編集作業を新しく開始</small>
    </button>
    <button class="choice" data-type="result">
      <strong>編集結果動画</strong>
      <small>保存済みの動画を開く</small>
    </button>
    <div class="modal-actions">
      <button id="close">キャンセル</button>
    </div>
  </div>
</div>

<div class="context" id="context">
  <button data-action="hide">表示／非表示</button>
  <button data-action="delete">削除</button>
</div>

<script>
const $=id=>document.getElementById(id);
const video=$('video');
const objects=$('objects');
const lines=$('lines');

const state={
  target:null,objects:[],links:[],selected:null,
  source:null,connecting:false,scale:1,duration:30
};

const sources={
  original:'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
  result:'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.webm'
};

function fmt(t){
  return String(Math.floor(t/60)).padStart(2,'0')+':'+
    (t%60).toFixed(1).padStart(4,'0');
}

function openVideo(type){
  state.target=type;
  video.src=sources[type];
  video.style.display='block';
  $('empty').style.display='none';
  $('label').style.display='block';
  $('label').textContent=type==='original'?'オリジナル動画':'編集結果動画';
  $('target').textContent='編集対象：'+
    (type==='original'?'オリジナル動画':'編集結果動画');
  $('modal').classList.remove('show');
  video.currentTime=0;
  video.load();
  type==='result'?loadWork():loadOriginal();
}

function loadOriginal(){
  state.objects=[
    {id:1,name:'コメント',x:70,y:50,show:true},
    {id:2,name:'強調枠',x:300,y:130,show:true}
  ];
  state.links=[];
  state.selected=null;
  draw();
}

function loadWork(){
  state.objects=[
    {id:1,name:'コメント',x:70,y:50,show:true},
    {id:2,name:'強調枠',x:300,y:130,show:true},
    {id:3,name:'拡大枠',x:530,y:55,show:true}
  ];
  state.links=[{from:1,to:2},{from:2,to:3}];
  state.selected=null;
  draw();
}

$('open').onclick=()=>$('modal').classList.add('show');
$('resume').onclick=()=>openVideo('result');
$('close').onclick=()=>$('modal').classList.remove('show');

document.querySelectorAll('.choice').forEach(b=>{
  b.onclick=()=>openVideo(b.dataset.type);
});

video.onloadedmetadata=()=>{
  state.duration=video.duration>0?video.duration:30;
  ruler();
};

video.ontimeupdate=()=>{
  $('time').textContent=fmt(video.currentTime)+' / '+fmt(state.duration);
  $('playhead').style.left=(video.currentTime/state.duration*100)+'%';
};

function ruler(){
  document.querySelectorAll('.tick').forEach(e=>e.remove());
  const interval=state.scale>=2?1:state.scale===1?5:10;
  const px=22*state.scale;
  $('ruler').style.minWidth=Math.max($('ruler').clientWidth,state.duration*px)+'px';

  for(let t=0;t<=state.duration;t+=interval){
    const e=document.createElement('div');
    e.className='tick';
    e.style.left=t*px+'px';
    e.innerHTML='<span>'+fmt(t)+'</span>';
    $('ruler').appendChild(e);
  }
  $('playhead').style.left=(video.currentTime/state.duration*100)+'%';
}

$('scrub').onclick=e=>{
  if(!state.target)return;
  const r=$('ruler').getBoundingClientRect();
  video.currentTime=Math.max(0,Math.min(1,(e.clientX-r.left)/r.width))*state.duration;
};

$('plus').onclick=()=>{
  state.scale=Math.min(4,state.scale*2);
  $('scale').textContent=state.scale.toFixed(1)+'x';
  ruler();
};

$('minus').onclick=()=>{
  state.scale=Math.max(.5,state.scale/2);
  $('scale').textContent=state.scale.toFixed(1)+'x';
  ruler();
};

function add(name){
  const id=Date.now();
  state.objects.push({
    id,name,x:70+(state.objects.length%4)*155,
    y:45+Math.floor(state.objects.length/4)*90,show:true
  });
  state.selected=id;
  draw();
}

$('comment').onclick=()=>add('コメント');
$('highlight').onclick=()=>add('強調枠');
$('zoom').onclick=()=>add('拡大枠');
$('skip').onclick=()=>add('スキップ');

function selected(){
  return state.objects.find(o=>o.id===state.selected);
}

function draw(){
  objects.querySelectorAll('.object').forEach(e=>e.remove());

  state.objects.forEach(o=>{
    const e=document.createElement('div');
    e.className='object'+(o.id===state.selected?' selected':'')+
      (!o.show?' hidden':'');
    e.dataset.id=o.id;
    e.style.left=o.x+'px';
    e.style.top=o.y+'px';
    e.innerHTML='<div class="object-name">'+o.name+'</div>'+
      '<div class="object-time">編集要素</div>'+
      '<button class="object-delete">×</button>';

    e.onmousedown=startDrag;
    e.onclick=ev=>{
      if(ev.target.className!=='object-delete'){
        state.selected=o.id;
        draw();
      }
    };
    e.oncontextmenu=ev=>{
      ev.preventDefault();
      state.selected=o.id;
      draw();
      $('context').style.left=ev.clientX+'px';
      $('context').style.top=ev.clientY+'px';
      $('context').classList.add('show');
    };
    e.querySelector('.object-delete').onclick=ev=>{
      ev.stopPropagation();
      remove(o.id);
    };
    objects.appendChild(e);
  });

  drawLinks();
}

let drag=null;

function startDrag(e){
  if(e.button!==0)return;
  const o=state.objects.find(x=>x.id===Number(e.currentTarget.dataset.id));
  state.selected=o.id;
  drag={o,x:e.clientX,y:e.clientY,left:o.x,top:o.y};
  draw();
  document.addEventListener('mousemove',move);
  document.addEventListener('mouseup',end,{once:true});
}

function move(e){
  if(!drag)return;
  drag.o.x=Math.max(0,drag.left+e.clientX-drag.x);
  drag.o.y=Math.max(0,drag.top+e.clientY-drag.y);
  const el=objects.querySelector('[data-id="'+drag.o.id+'"]');
  if(el){
    el.style.left=drag.o.x+'px';
    el.style.top=drag.o.y+'px';
  }
  drawLinks();
}

function end(){
  drag=null;
  document.removeEventListener('mousemove',move);
}

function remove(id){
  state.objects=state.objects.filter(o=>o.id!==id);
  state.links=state.links.filter(l=>l.from!==id&&l.to!==id);
  if(state.selected===id)state.selected=null;
  draw();
}

$('delete').onclick=()=>{
  if(state.selected!==null)remove(state.selected);
};

$('connect').onclick=()=>{
  state.connecting=!state.connecting;
  state.source=null;
  $('status').textContent=state.connecting?'接続元を選択':'通常操作';
  $('connect').classList.toggle('primary',state.connecting);
};

objects.addEventListener('click',e=>{
  if(!state.connecting)return;
  const el=e.target.closest('.object');
  if(!el)return;
  const id=Number(el.dataset.id);

  if(state.source===null){
    state.source=id;
    $('status').textContent='接続先を選択';
    return;
  }

  if(state.source!==id&&!state.links.some(l=>l.from===state.source&&l.to===id)){
    state.links.push({from:state.source,to:id});
  }

  state.source=null;
  $('status').textContent='接続元を選択';
  drawLinks();
});

function drawLinks(){
  while(lines.firstChild)lines.removeChild(lines.firstChild);
  const base=objects.getBoundingClientRect();

  state.links.forEach(l=>{
    const a=objects.querySelector('[data-id="'+l.from+'"]');
    const b=objects.querySelector('[data-id="'+l.to+'"]');
    const ao=state.objects.find(o=>o.id===l.from);
    if(!a||!b||!ao||!ao.show)return;

    const ar=a.getBoundingClientRect();
    const br=b.getBoundingClientRect();
    const x1=ar.right-base.left;
    const y1=ar.top-base.top+ar.height/2;
    const x2=br.left-base.left;
    const y2=br.top-base.top+br.height/2;
    const bend=Math.max(35,Math.abs(x2-x1)*.45);

    const p=document.createElementNS('http://www.w3.org/2000/svg','path');
    p.setAttribute('d',
      `M${x1} ${y1} C${x1+bend} ${y1},${x2-bend} ${y2},${x2} ${y2}`
    );
    p.setAttribute('fill','none');
    p.setAttribute('stroke','#2864d7');
    p.setAttribute('stroke-width','2');
    lines.appendChild(p);
  });
}

document.querySelectorAll('.context button').forEach(b=>{
  b.onclick=()=>{
    const o=selected();
    if(!o)return;

    if(b.dataset.action==='hide')o.show=!o.show;
    else remove(o.id);

    $('context').classList.remove('show');
    draw();
  };
});

document.addEventListener('click',e=>{
  if(!e.target.closest('.context'))$('context').classList.remove('show');
});

window.onresize=()=>{
  ruler();
  drawLinks();
};

ruler();
loadOriginal();
state.selected=null;
draw();
</script>
</body>
</html>
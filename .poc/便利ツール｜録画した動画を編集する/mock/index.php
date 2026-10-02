<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;background:#111827;color:#e5e7eb}
button,input{font:inherit}
button{cursor:pointer}
.app{height:100%;display:flex;flex-direction:column}
.top{height:50px;display:flex;align-items:center;gap:8px;padding:7px 10px;background:#0b1220;border-bottom:1px solid #334155}
.top strong{margin-right:8px}
.btn{border:1px solid #475569;background:#1e293b;color:#e5e7eb;border-radius:6px;padding:7px 12px}
.btn:hover{background:#334155}
.primary{background:#2563eb;border-color:#3b82f6}
.status{margin-left:auto;color:#94a3b8;font-size:12px}
.file{display:none}

.main{min-height:0;flex:1;display:flex;flex-direction:column}
.videoArea{min-height:0;flex:1;background:#020617;padding:10px;display:flex;align-items:center;justify-content:center}
.videoWrap{position:relative;width:min(1100px,100%);height:100%;background:#000;overflow:hidden}
#video{width:100%;height:100%;object-fit:contain;display:block}
.layer{position:absolute;inset:0;pointer-events:none}
.connections{z-index:5;overflow:visible}
.connections path{fill:none;stroke:#ef4444;stroke-width:3;pointer-events:stroke;cursor:pointer}
.connections path.selected{stroke:#60a5fa;stroke-width:6}
.connections .handle{fill:#fff;stroke:#60a5fa;stroke-width:2;pointer-events:auto;cursor:crosshair}
.elements{z-index:10}
.element{position:absolute;min-width:45px;min-height:28px;user-select:none;touch-action:none;cursor:move}
.element.selected{outline:2px solid #60a5fa;outline-offset:2px}
.element.multi{outline:2px solid #fbbf24;outline-offset:2px}
.element.comment{color:#fff;text-shadow:0 1px 3px #000;font-weight:600}
.element.highlight{border:4px solid #ef4444;background:transparent}
.element.highlight.round{border-radius:18px}
.element.highlight.circle{border-radius:50%}
.element.zoomBox{border:3px solid #111827;background:#0002}
.element.skip{display:none}
.resize{position:absolute;width:9px;height:9px;background:#fff;border:2px solid #60a5fa;display:none}
.element.selected .resize{display:block}
.nw{left:-6px;top:-6px;cursor:nwse-resize}.ne{right:-6px;top:-6px;cursor:nesw-resize}
.sw{left:-6px;bottom:-6px;cursor:nesw-resize}.se{right:-6px;bottom:-6px;cursor:nwse-resize}

.empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;color:#64748b;pointer-events:none}
.empty b{font-size:18px;margin-bottom:6px}

.timeline{height:250px;flex:none;background:#0f172a;border-top:1px solid #334155;display:flex;flex-direction:column}
.controls{height:42px;display:flex;align-items:center;gap:8px;padding:5px 8px;border-bottom:1px solid #334155}
.time{font-variant-numeric:tabular-nums;color:#dbeafe;min-width:170px}
.scale{margin-left:auto;display:flex;align-items:center;gap:6px;font-size:12px;color:#94a3b8}
.scale input{width:110px}
.scroll{flex:1;overflow:auto}
.timelineInner{position:relative;min-width:700px}
.axis{height:30px;position:relative;margin-left:90px;border-bottom:1px solid #334155;background:#131d29}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #334155;padding:5px 0 0 3px;font-size:9px;color:#64748b}
.row{height:48px;display:flex;border-bottom:1px solid #263445}
.label{width:90px;min-width:90px;display:flex;align-items:center;padding-left:8px;border-right:1px solid #334155;background:#111827;font-size:11px}
.lane{position:relative;flex:1}
.bar{position:absolute;top:8px;height:32px;border:1px solid currentColor;border-radius:5px;display:flex;align-items:center;overflow:hidden;cursor:grab;user-select:none}
.bar.selected{box-shadow:0 0 0 2px #60a5fa}
.bar.multi{box-shadow:0 0 0 2px #fbbf24}
.bar span{padding:0 7px;font-size:10px;white-space:nowrap}
.barHandle{position:absolute;top:0;width:8px;height:100%;cursor:ew-resize}
.barHandle.left{left:-3px}.barHandle.right{right:-3px}
.playhead{position:absolute;top:30px;bottom:0;width:2px;background:#ef4444;z-index:30;pointer-events:none}
.playhead:before{content:"";position:absolute;top:-1px;left:-5px;width:12px;height:12px;background:#ef4444;clip-path:polygon(0 0,100% 0,50% 100%)}

.menu{position:fixed;z-index:1000;display:none;min-width:210px;padding:5px;background:#172235;border:1px solid #475569;border-radius:7px;box-shadow:0 12px 30px #0008}
.menu button{display:block;width:100%;padding:8px;border:0;background:transparent;color:#e5e7eb;text-align:left;border-radius:4px}
.menu button:hover{background:#293a52}
.menuTitle{padding:6px 8px;color:#94a3b8;font-size:11px}
.menuSep{height:1px;background:#334155;margin:4px 0}
.notice{position:fixed;right:12px;bottom:12px;background:#1e293b;border:1px solid #475569;padding:9px 13px;border-radius:6px;display:none;z-index:2000}

@media(max-width:800px){
 .timeline{height:220px}
 .top strong{display:none}
}
</style>
</head>
<body>
<div class="app">

<header class="top">
<strong>動画編集</strong>
<button class="btn primary" id="openVideo">動画を選択</button>
<input class="file" id="file" type="file" accept="video/*">
<button class="btn" id="newBtn">新規</button>
<span class="status" id="status">動画を選択してください</span>
</header>

<main class="main">
<section class="videoArea">
<div class="videoWrap" id="videoWrap">
<video id="video" preload="metadata" playsinline></video>
<svg class="layer connections" id="connections"></svg>
<div class="layer elements" id="elements"></div>
<div class="empty" id="empty">
<b>動画を選択してください</b>
画面上を右クリックすると要素を追加できます
</div>
</div>
</section>

<section class="timeline">
<div class="controls">
<button class="btn" id="play">▶</button>
<button class="btn" id="stop">■</button>
<span class="time" id="time">00:00.000 / 00:00.000</span>
<span id="hint" style="color:#94a3b8;font-size:12px"></span>
<div class="scale">
<span>時間スケール</span>
<input id="scale" type="range" min="1" max="10" step=".1" value="1">
<span id="scaleText">1.0×</span>
</div>
</div>
<div class="scroll">
<div class="timelineInner" id="timelineInner">
<div class="axis" id="axis"></div>
<div id="rows"></div>
<div class="playhead" id="playhead"></div>
</div>
</div>
</section>
</main>
</div>

<div class="menu" id="menu"></div>
<div class="notice" id="notice"></div>

<script>
const $=id=>document.getElementById(id);
const video=$('video'),wrap=$('videoWrap'),elements=$('elements'),connections=$('connections');
const file=$('file'),empty=$('empty'),menu=$('menu'),rows=$('rows'),axis=$('axis'),playhead=$('playhead');
const timeText=$('time'),status=$('status'),notice=$('notice');

let duration=60;
let selected=[];
let selectedConnection=null;
let menuTarget=null;
let dragging=null;
let raf=0;
let objectUrl=null;
let nextId=5;

const data={
  elements:[
    {id:1,type:'comment',name:'コメント',x:.12,y:.18,w:.25,h:.10,start:3,end:18,text:'ここを確認してください'},
    {id:2,type:'highlight',name:'強調枠',x:.52,y:.25,w:.25,h:.25,start:8,end:28,shape:'rect'},
    {id:3,type:'zoomBox',name:'拡大枠',x:.34,y:.53,w:.22,h:.20,start:20,end:42},
    {id:4,type:'skip',name:'スキップ',x:0,y:0,w:0,h:0,start:45,end:52}
  ],
  connections:[
    {id:1,from:1,to:2,fromPos:'right',toPos:'left',line:'straight',end:'arrow'}
  ]
};

function notify(s){
  notice.textContent=s;notice.style.display='block';
  clearTimeout(notify.t);notify.t=setTimeout(()=>notice.style.display='none',1800);
}
function fmt(t){
  t=Math.max(0,t||0);
  const m=Math.floor(t/60),s=Math.floor(t%60),ms=Math.floor((t%1)*1000);
  return String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')+'.'+String(ms).padStart(3,'0');
}
function currentTime(){return video.duration||duration}
function timelineWidth(){
  return Math.max(610,wrap.clientWidth-20);
}
function px(t){
  return t/currentTime()*timelineWidth();
}
function posPx(t){return 90+px(t)}

function renderAxis(){
  const w=timelineWidth();
  axis.style.width=w+'px';
  axis.innerHTML='';
  const step=Math.max(1,Math.ceil(currentTime()/10));
  for(let t=0;t<=currentTime()+.001;t+=step){
    const d=document.createElement('div');
    d.className='tick';d.style.left=px(t)+'px';d.textContent=fmt(t).slice(0,5);
    axis.appendChild(d);
  }
  playhead.style.left=posPx(video.currentTime)+'px';
  playhead.style.top='30px';
}

function active(e){
  return video.currentTime>=e.start && video.currentTime<=e.end;
}

function renderElements(){
  elements.innerHTML='';
  data.elements.forEach(e=>{
    if(e.type==='skip')return;
    if(!active(e))return;
    const d=document.createElement('div');
    d.className='element '+e.type+(e.shape==='round'?' round':'')+(e.shape==='circle'?' circle':'');
    if(selected.includes(e.id))d.classList.add(selected.length>1?'multi':'selected');
    d.dataset.id=e.id;
    d.style.left=e.x*100+'%';d.style.top=e.y*100+'%';
    d.style.width=e.w*100+'%';d.style.height=e.h*100+'%';

    if(e.type==='comment'){
      d.textContent=e.text;
      d.style.fontSize=Math.max(13,wrap.clientWidth*.018)+'px';
    }
    if(e.type==='highlight')d.title='強調枠';
    if(e.type==='zoomBox')d.title='拡大枠';

    ['nw','ne','sw','se'].forEach(c=>{
      const h=document.createElement('i');h.className='resize '+c;
      h.dataset.resize=c;d.appendChild(h);
    });

    d.addEventListener('pointerdown',ev=>startElementDrag(ev,e));
    d.addEventListener('click',ev=>{
      ev.stopPropagation();
      selectElement(e.id,ev.shiftKey||ev.ctrlKey);
    });
    d.addEventListener('contextmenu',ev=>{
      ev.preventDefault();ev.stopPropagation();
      selectElement(e.id,ev.shiftKey||ev.ctrlKey);
      showElementMenu(ev.clientX,ev.clientY,e);
    });
    elements.appendChild(d);
  });
}

function connectionPoint(e,p){
  const x=e.x,y=e.y,w=e.w,h=e.h;
  return {
    left:[x,y+h/2],right:[x+w,y+h/2],
    top:[x+w/2,y],bottom:[x+w/2,y+h],
    center:[x+w/2,y+h/2]
  }[p]||[x+w/2,y+h/2];
}
function renderConnections(){
  connections.innerHTML='';
  connections.setAttribute('viewBox','0 0 '+wrap.clientWidth+' '+wrap.clientHeight);
  data.connections.forEach(c=>{
    const a=data.elements.find(e=>e.id===c.from),b=data.elements.find(e=>e.id===c.to);
    if(!a||!b||!active(a)||!active(b))return;
    const p=connectionPoint(a,c.fromPos),q=connectionPoint(b,c.toPos);
    const x1=p[0]*wrap.clientWidth,y1=p[1]*wrap.clientHeight;
    const x2=q[0]*wrap.clientWidth,y2=q[1]*wrap.clientHeight;
    let path='';
    if(c.line==='wave'){
      const mx=(x1+x2)/2;
      path=`M ${x1} ${y1} C ${mx-50} ${y1-35}, ${mx+50} ${y2+35}, ${x2} ${y2}`;
    }else{
      path=`M ${x1} ${y1} L ${x2} ${y2}`;
    }
    const pth=document.createElementNS('http://www.w3.org/2000/svg','path');
    pth.setAttribute('d',path);
    pth.classList.toggle('selected',selectedConnection===c.id);
    if(c.line==='wave')pth.setAttribute('stroke-dasharray','7 5');
    pth.addEventListener('click',ev=>{
      ev.stopPropagation();selectConnection(c.id);hideMenu();
    });
    pth.addEventListener('contextmenu',ev=>{
      ev.preventDefault();ev.stopPropagation();
      selectConnection(c.id);showConnectionMenu(ev.clientX,ev.clientY,c);
    });
    connections.appendChild(pth);

    if(selectedConnection===c.id){
      [a,b].forEach((e,i)=>{
        const p=connectionPoint(e,i?c.toPos:c.fromPos);
        const h=document.createElementNS('http://www.w3.org/2000/svg','circle');
        h.setAttribute('cx',p[0]*wrap.clientWidth);h.setAttribute('cy',p[1]*wrap.clientHeight);
        h.setAttribute('r',6);h.classList.add('handle');h.dataset.end=i?'to':'from';
        h.addEventListener('pointerdown',ev=>startConnectionHandle(ev,c,i?'to':'from'));
        connections.appendChild(h);
      });
    }
  });
}

function renderTimeline(){
  rows.innerHTML='';
  const types=[
    ['comment','コメント','#60a5fa'],
    ['highlight','強調枠','#ef4444'],
    ['zoomBox','拡大枠','#111827'],
    ['skip','スキップ','#f97316']
  ];
  types.forEach(([type,label,color])=>{
    const row=document.createElement('div');row.className='row';
    const lab=document.createElement('div');lab.className='label';lab.textContent=label;
    const lane=document.createElement('div');lane.className='lane';lane.style.width=timelineWidth()+'px';
    data.elements.filter(e=>e.type===type).forEach(e=>{
      const bar=document.createElement('div');bar.className='bar';
      if(selected.includes(e.id))bar.classList.add(selected.length>1?'multi':'selected');
      bar.dataset.id=e.id;
      bar.style.left=px(e.start)+'px';bar.style.width=Math.max(10,px(e.end-e.start))+'px';
      bar.style.color=color;bar.style.background=color+'22';
      bar.innerHTML='<span>'+label+'</span><i class="barHandle left"></i><i class="barHandle right"></i>';
      bar.addEventListener('pointerdown',ev=>startTimelineDrag(ev,e,ev.target.closest('.barHandle')));
      bar.addEventListener('click',ev=>{
        ev.stopPropagation();selectElement(e.id,ev.shiftKey||ev.ctrlKey);
      });
      bar.addEventListener('contextmenu',ev=>{
        ev.preventDefault();selectElement(e.id,ev.shiftKey||ev.ctrlKey);
        showElementMenu(ev.clientX,ev.clientY,e);
      });
      lane.appendChild(bar);
    });
    row.append(lab,lane);rows.appendChild(row);
  });
}

function render(){
  renderAxis();renderElements();renderConnections();renderTimeline();
  timeText.textContent=fmt(video.currentTime)+' / '+fmt(currentTime());
  playhead.style.left=posPx(video.currentTime)+'px';
  playhead.style.top='30px';
  empty.style.display=video.src?'none':'flex';
}

function selectElement(id,multi=false){
  selectedConnection=null;
  if(multi){
    selected=selected.includes(id)?selected.filter(x=>x!==id):[...selected,id];
  }else selected=[id];
  render();
}
function selectConnection(id){
  selected=[];selectedConnection=id;render();
}

function startElementDrag(ev,e){
  if(ev.button!==0)return;
  const target=ev.target;
  if(target.dataset.resize){
    ev.preventDefault();ev.stopPropagation();
    startResize(ev,e,target.dataset.resize);return;
  }
  if(!selected.includes(e.id))selectElement(e.id,ev.shiftKey||ev.ctrlKey);
  ev.preventDefault();
  const rect=wrap.getBoundingClientRect();
  const startX=ev.clientX,startY=ev.clientY;
  const originals=selected.map(id=>{
    const x=data.elements.find(a=>a.id===id);
    return {x:id,l:x.x,t:x.y};
  });
  const move=ev=>{
    const dx=(ev.clientX-startX)/rect.width,dy=(ev.clientY-startY)/rect.height;
    originals.forEach(o=>{
      const x=data.elements.find(a=>a.id===o.x);
      x.x=Math.max(0,Math.min(1-x.w,o.l+dx));
      x.y=Math.max(0,Math.min(1-x.h,o.t+dy));
    });
    render();
  };
  const up=()=>{
    document.removeEventListener('pointermove',move);
    document.removeEventListener('pointerup',up);
  };
  document.addEventListener('pointermove',move);document.addEventListener('pointerup',up);
}

function startResize(ev,e,corner){
  const rect=wrap.getBoundingClientRect(),sx=ev.clientX,sy=ev.clientY;
  const ox=e.x,oy=e.y,ow=e.w,oh=e.h;
  const move=ev=>{
    const dx=(ev.clientX-sx)/rect.width,dy=(ev.clientY-sy)/rect.height;
    if(corner.includes('e'))e.w=Math.max(.04,Math.min(1-e.x,ow+dx));
    if(corner.includes('s'))e.h=Math.max(.04,Math.min(1-e.y,oh+dy));
    if(corner.includes('w')){
      e.x=Math.max(0,Math.min(ox+ow-.04,ox+dx));e.w=ow-(e.x-ox);
    }
    if(corner.includes('n')){
      e.y=Math.max(0,Math.min(oy+oh-.04,oy+dy));e.h=oh-(e.y-oy);
    }
    render();
  };
  const up=()=>{document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',up)};
  document.addEventListener('pointermove',move);document.addEventListener('pointerup',up);
}

function startTimelineDrag(ev,e,handle){
  ev.preventDefault();ev.stopPropagation();
  if(!selected.includes(e.id))selectElement(e.id,ev.shiftKey||ev.ctrlKey);
  const lane=ev.currentTarget.parentElement,rect=lane.getBoundingClientRect(),sx=ev.clientX;
  const os=e.start,oe=e.end;
  const move=mv=>{
    const dt=(mv.clientX-sx)/rect.width*currentTime();
    if(handle){
      if(handle.classList.contains('left'))e.start=Math.max(0,Math.min(oe-.1,os+dt));
      else e.end=Math.min(currentTime(),Math.max(os+.1,oe+dt));
    }else{
      const d=Math.max(-os,Math.min(currentTime()-oe,dt));
      e.start=os+d;e.end=oe+d;
    }
    render();
  };
  const up=()=>{document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',up)};
  document.addEventListener('pointermove',move);document.addEventListener('pointerup',up);
}

function startConnectionHandle(ev,c,end){
  ev.preventDefault();ev.stopPropagation();
  const source=end==='from'?data.elements.find(e=>e.id===c.from):data.elements.find(e=>e.id===c.to);
  const move=mv=>{
    const r=wrap.getBoundingClientRect(),x=(mv.clientX-r.left)/r.width,y=(mv.clientY-r.top)/r.height;
    const points={left:[source.x,source.y+source.h/2],right:[source.x+source.w,source.y+source.h/2],top:[source.x+source.w/2,source.y],bottom:[source.x+source.w/2,source.y+source.h]};
    let best='right',dist=Infinity;
    Object.entries(points).forEach(([name,p])=>{
      const d=(p[0]-x)**2+(p[1]-y)**2;if(d<dist){dist=d;best=name}
    });
    if(end==='from')c.fromPos=best;else c.toPos=best;
    render();
  };
  const up=()=>{document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',up)};
  document.addEventListener('pointermove',move);document.addEventListener('pointerup',up);
}

function showElementMenu(x,y,e){
  menu.innerHTML='<div class="menuTitle">'+e.name+' の操作</div>';
  if(selected.length>1){
    addMenu('複数選択を接続',()=>connectSelected());
    addMenu('選択を解除',()=>{selected=[];render()});
  }else{
    if(e.type==='highlight'){
      addMenu('四角形',()=>{e.shape='rect';render()});
      addMenu('角丸',()=>{e.shape='round';render()});
      addMenu('円形',()=>{e.shape='circle';render()});
      sep();
    }
    addMenu('この要素を削除',()=>removeElement(e.id));
  }
  showMenu(x,y);
}

function showConnectionMenu(x,y,c){
  menu.innerHTML='<div class="menuTitle">接続線の操作</div>';
  addMenu('直線',()=>{c.line='straight';render()});
  addMenu('波線',()=>{c.line='wave';render()});
  sep();
  addMenu('終端：矢印',()=>{c.end='arrow';render()});
  addMenu('終端：丸',()=>{c.end='circle';render()});
  addMenu('終端：なし',()=>{c.end='none';render()});
  sep();
  addMenu('始点を左',()=>{c.fromPos='left';render()});
  addMenu('始点を右',()=>{c.fromPos='right';render()});
  addMenu('始点を上',()=>{c.fromPos='top';render()});
  addMenu('始点を下',()=>{c.fromPos='bottom';render()});
  addMenu('終点を左',()=>{c.toPos='left';render()});
  addMenu('終点を右',()=>{c.toPos='right';render()});
  addMenu('終点を上',()=>{c.toPos='top';render()});
  addMenu('終点を下',()=>{c.toPos='bottom';render()});
  sep();
  addMenu('接続を削除',()=>{data.connections=data.connections.filter(x=>x.id!==c.id);selectedConnection=null;render()});
  showMenu(x,y);
}

function addMenu(text,fn){
  const b=document.createElement('button');b.textContent=text;b.onclick=()=>{hideMenu();fn()};menu.appendChild(b);
}
function sep(){const d=document.createElement('div');d.className='menuSep';menu.appendChild(d)}
function showMenu(x,y){
  menu.style.display='block';
  menu.style.left=Math.min(x,innerWidth-220)+'px';
  menu.style.top=Math.min(y,innerHeight-260)+'px';
}
function hideMenu(){menu.style.display='none'}

function connectSelected(){
  if(selected.length!==2)return;
  const [a,b]=selected;
  if(data.connections.some(c=>c.from===a&&c.to===b||c.from===b&&c.to===a)){notify('すでに接続されています');return}
  data.connections.push({id:Date.now(),from:a,to:b,fromPos:'right',toPos:'left',line:'straight',end:'arrow'});
  notify('要素を接続しました');render();
}

wrap.addEventListener('contextmenu',ev=>{
  if(ev.target!==wrap&&ev.target!==video&&ev.target===connections)return;
  ev.preventDefault();
  if(selectedConnection||selected.length){hideMenu();return}
  menu.innerHTML='<div class="menuTitle">要素を追加</div>';
  addMenu('コメント',()=>addElement('comment'));
  addMenu('強調枠',()=>addElement('highlight'));
  addMenu('拡大枠',()=>addElement('zoomBox'));
  addMenu('スキップ',()=>addElement('skip'));
  showMenu(ev.clientX,ev.clientY);
});
wrap.addEventListener('click',ev=>{
  if(ev.target===wrap||ev.target===video){
    selected=[];selectedConnection=null;hideMenu();render();
  }
});
document.addEventListener('click',ev=>{
  if(!menu.contains(ev.target)&&ev.target!==connections)hideMenu();
});

function addElement(type){
  const e={
    id:nextId++,type,name:type==='comment'?'コメント':type==='highlight'?'強調枠':type==='zoomBox'?'拡大枠':'スキップ',
    x:.25,y:.25,w:type==='comment'?.25:.2,h:type==='comment'?.1:.2,
    start=Math.max(0,video.currentTime-2),end=Math.min(currentTime(),video.currentTime+12),
    text:'新しいコメント',shape:'rect'
  };
  data.elements.push(e);selectElement(e.id);render();
}
function removeElement(id){
  data.elements=data.elements.filter(e=>e.id!==id);
  data.connections=data.connections.filter(c=>c.from!==id&&c.to!==id);
  selected=[];render();
}

$('openVideo').onclick=()=>file.click();
file.onchange=()=>{
  if(!file.files[0])return;
  if(objectUrl)URL.revokeObjectURL(objectUrl);
  objectUrl=URL.createObjectURL(file.files[0]);
  video.src=objectUrl;video.load();
  status.textContent=file.files[0].name;
};
video.onloadedmetadata=()=>{
  duration=video.duration||60;status.textContent='編集中：'+fmt(duration);
  render();
};
video.ontimeupdate=()=>{
  const skips=data.elements.filter(e=>e.type==='skip');
  const s=skips.find(e=>video.currentTime>=e.start&&video.currentTime<e.end);
  if(s){video.currentTime=s.end;return}
  render();
};
video.onplay=()=>{$('play').textContent='⏸';loop()};
video.onpause=()=>{$('play').textContent='▶';cancelAnimationFrame(raf);render()};
video.onended=()=>{$('play').textContent='▶';render()};
function loop(){render();if(!video.paused)raf=requestAnimationFrame(loop)}

$('play').onclick=()=>{
  if(!video.src){notify('先に動画を選択してください');return}
  video.paused?video.play():video.pause();
};
$('stop').onclick=()=>{video.pause();video.currentTime=0;render()};
$('scale').oninput=e=>{
  $('scaleText').textContent=Number(e.target.value).toFixed(1)+'×';
  timelineWidth();
  render();
};

timelineInner.addEventListener('click',ev=>{
  const r=timelineInner.getBoundingClientRect();
  const x=ev.clientX-r.left-90;
  if(x<0)return;
  video.currentTime=Math.max(0,Math.min(currentTime(),x/timelineWidth()*currentTime()));
  render();
});

$('newBtn').onclick=()=>{
  video.pause();video.removeAttribute('src');video.load();
  selected=[];selectedConnection=null;
  status.textContent='動画を選択してください';
  render();
};

window.onresize=render;
render();
</script>
</body>
</html>

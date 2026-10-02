<?php ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>録画した動画を編集する</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;color:#222}
body{overflow:hidden;background:#eee}
.app{height:100vh;display:flex;flex-direction:column}
header{height:52px;flex:none;display:flex;align-items:center;justify-content:space-between;padding:0 16px;background:#222;color:#fff}
.title{font-size:16px;font-weight:600}
.header-info{font-size:12px;color:#bbb}
.workspace{flex:1;min-height:0;display:flex}
.editor{flex:1;min-width:0;display:flex;flex-direction:column;background:#191919}
.video-area{flex:1;min-height:0;padding:16px;display:flex;align-items:center;justify-content:center}
.video{position:relative;width:100%;height:100%;max-width:1100px;background:
linear-gradient(135deg,#38404a 0%,#20262d 45%,#111 100%);overflow:hidden;border:1px solid #444}
.video:before{content:"録画動画 プレビュー";position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);
color:#777;font-size:24px;pointer-events:none}
.overlay{position:absolute;inset:0}
.connections{position:absolute;inset:0;width:100%;height:100%;z-index:1;overflow:visible}
.element{position:absolute;z-index:2;user-select:none;cursor:move}
.element.selected{outline:2px solid #4da3ff;outline-offset:3px}
.element.multi{outline:2px solid #ffbd45;outline-offset:3px}
.comment{padding:9px 12px;background:rgba(30,30,30,.88);color:#fff;border-radius:5px;min-width:150px}
.highlight{border:3px solid #f44336;background:transparent;border-radius:0}
.highlight.circle{border-radius:50%}
.highlight.round{border-radius:18px}
.zoom{border:3px solid #111;background:rgba(255,255,255,.06);box-shadow:0 0 0 9999px rgba(0,0,0,.12)}
.handle{position:absolute;width:9px;height:9px;background:#fff;border:2px solid #2585d8;border-radius:2px;display:none;z-index:5}
.selected .handle{display:block}
.h-nw{left:-6px;top:-6px;cursor:nwse-resize}.h-ne{right:-6px;top:-6px;cursor:nesw-resize}
.h-sw{left:-6px;bottom:-6px;cursor:nesw-resize}.h-se{right:-6px;bottom:-6px;cursor:nwse-resize}
.line-hit{fill:none;stroke:transparent;stroke-width:16;cursor:pointer}
.line{fill:none;stroke:#f44336;stroke-width:3;pointer-events:none}
.line.selected{stroke:#ffd54f;stroke-width:5}
.endpoint{fill:#fff;stroke:#f44336;stroke-width:3;cursor:grab}
.endpoint.selected{stroke:#ffd54f;fill:#222}
.right{width:230px;flex:none;background:#fff;border-left:1px solid #ccc;display:flex;flex-direction:column}
.menu-title{padding:14px 15px;font-weight:600;border-bottom:1px solid #ddd}
.menu-body{padding:14px;overflow:auto}
.empty-menu{color:#888;font-size:13px;line-height:1.6}
button,select{font:inherit}
.menu button,.menu select{width:100%;padding:8px 9px;margin:0 0 8px;border:1px solid #ccc;border-radius:5px;background:#fff;text-align:left;cursor:pointer}
.menu button:hover{background:#f3f6f9}
.menu .danger{color:#c62828}
.menu label{display:block;font-size:12px;color:#666;margin:10px 0 5px}
.selection-count{font-size:12px;color:#777;margin-bottom:10px}
.timeline{height:150px;flex:none;background:#252525;color:#ddd;border-top:1px solid #444;position:relative;overflow:hidden}
.time-axis{height:28px;border-bottom:1px solid #444;position:relative;margin-left:70px}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #555;color:#aaa;font-size:10px;padding-left:4px}
.tracks{position:absolute;left:70px;right:0;top:28px;bottom:0}
.track{height:30px;border-bottom:1px solid #383838;position:relative}
.track-label{position:absolute;left:0;top:28px;width:70px;font-size:11px;color:#aaa;padding:7px}
.clip{position:absolute;top:5px;height:20px;border-radius:3px;padding:2px 8px;font-size:10px;color:#fff;cursor:pointer}
.clip.comment{background:#607d8b}.clip.highlight{background:#c62828}.clip.zoom{background:#424242}.clip.skip{background:#795548}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:#ff5252;z-index:10;left:20%}
.playhead:before{content:"";position:absolute;top:0;left:-5px;border-left:6px solid transparent;border-right:6px solid transparent;border-top:7px solid #ff5252}
.context{position:fixed;display:none;z-index:50;background:#fff;border:1px solid #bbb;box-shadow:0 4px 16px #0003;border-radius:5px;min-width:180px;padding:5px}
.context button{display:block;width:100%;border:0;background:#fff;text-align:left;padding:8px;cursor:pointer}
.context button:hover{background:#eef5ff}
.status{position:absolute;right:10px;bottom:8px;color:#aaa;font-size:11px;pointer-events:none}
</style>
</head>
<body>
<div class="app">
<header>
  <div class="title">録画した動画を編集する</div>
  <div class="header-info">モック編集画面</div>
</header>

<div class="workspace">
  <section class="editor">
    <div class="video-area">
      <div class="video" id="video">
        <svg class="connections" id="svg"></svg>
        <div class="overlay" id="overlay"></div>
        <div class="status">右クリック：要素追加　／　Shift＋クリック：複数選択</div>
      </div>
    </div>

    <div class="timeline" id="timeline">
      <div class="time-axis">
        <span class="tick" style="left:0%">0:00</span>
        <span class="tick" style="left:20%">0:10</span>
        <span class="tick" style="left:40%">0:20</span>
        <span class="tick" style="left:60%">0:30</span>
        <span class="tick" style="left:80%">0:40</span>
        <span class="tick" style="left:100%">0:50</span>
      </div>
      <div class="track-label">要素</div>
      <div class="tracks" id="tracks"></div>
      <div class="playhead"></div>
    </div>
  </section>

  <aside class="right">
    <div class="menu-title" id="menuTitle">操作メニュー</div>
    <div class="menu-body" id="menu"></div>
  </aside>
</div>

<div class="context" id="context">
  <button data-add="comment">＋ コメントを追加</button>
  <button data-add="highlight">＋ 強調枠を追加</button>
  <button data-add="zoom">＋ 拡大枠を追加</button>
  <button data-add="skip">＋ スキップを追加</button>
  <button id="connectMenu">選択した要素を接続</button>
</div>

<script>
const video=document.getElementById('video');
const overlay=document.getElementById('overlay');
const svg=document.getElementById('svg');
const menu=document.getElementById('menu');
const menuTitle=document.getElementById('menuTitle');
const context=document.getElementById('context');
const tracks=document.getElementById('tracks');

let elements=[
 {id:'c1',type:'comment',x:9,y:15,w:170,h:48,text:'ここを確認してください',start:3,end:18},
 {id:'h1',type:'highlight',shape:'rect',x:43,y:25,w:25,h:25,start:8,end:25},
 {id:'z1',type:'zoom',x:64,y:58,w:22,h:22,start:18,end:34}
];

let lines=[
 {id:'l1',from:'c1',to:'h1',fromPos:'right',toPos:'left',style:'solid',end:'arrow'}
];

let selected=[];
let selectedLine=null;
let drag=null;
let uid=10;

function get(id){return elements.find(e=>e.id===id)}
function clearSelection(){selected=[];selectedLine=null;render()}
function selectElement(id,add){
  selectedLine=null;
  if(add){
    selected=selected.includes(id)?selected.filter(x=>x!==id):[...selected,id];
  }else selected=[id];
  render();
}
function selectLine(id){
  selected=[];selectedLine=id;render();
}

function render(){
  renderElements();renderLines();renderTimeline();renderMenu();
}

function renderElements(){
  overlay.innerHTML='';
  elements.forEach(e=>{
    if(e.type==='skip')return;
    const d=document.createElement('div');
    d.className='element '+e.type+(selected.includes(e.id)?(selected.length>1?' multi':' selected'):'');
    if(e.type==='highlight'&&e.shape)d.classList.add(e.shape);
    d.dataset.id=e.id;
    d.style.left=e.x+'%';d.style.top=e.y+'%';d.style.width=e.w+'%';d.style.height=e.h+'%';
    if(e.type==='comment'){
      d.textContent=e.text;
      d.style.height='auto';
    }
    ['nw','ne','sw','se'].forEach(p=>{
      const h=document.createElement('i');h.className='handle h-'+p;h.dataset.handle=p;d.appendChild(h);
    });
    d.addEventListener('pointerdown',startDrag);
    d.addEventListener('click',ev=>{
      ev.stopPropagation();
      selectElement(e.id,ev.shiftKey);
    });
    overlay.appendChild(d);
  });
}

function pos(e,p){
  if(p==='left')return{x:e.x,y:e.y+e.h/2};
  if(p==='right')return{x:e.x+e.w,y:e.y+e.h/2};
  if(p==='top')return{x:e.x+e.w/2,y:e.y};
  return{x:e.x+e.w/2,y:e.y+e.h};
}

function renderLines(){
  svg.innerHTML='';
  lines.forEach(l=>{
    const a=get(l.from),b=get(l.to);if(!a||!b)return;
    const p1=pos(a,l.fromPos),p2=pos(b,l.toPos);
    const x1=p1.x/100*video.clientWidth,y1=p1.y/100*video.clientHeight;
    const x2=p2.x/100*video.clientWidth,y2=p2.y/100*video.clientHeight;
    const path=document.createElementNS('http://www.w3.org/2000/svg','path');
    const curve=Math.max(40,Math.abs(x2-x1)*.35);
    path.setAttribute('d',`M ${x1} ${y1} C ${x1+curve} ${y1}, ${x2-curve} ${y2}, ${x2} ${y2}`);
    path.setAttribute('class','line-hit');
    path.dataset.id=l.id;
    path.addEventListener('click',e=>{e.stopPropagation();selectLine(l.id)});
    svg.appendChild(path);

    const visible=document.createElementNS('http://www.w3.org/2000/svg','path');
    visible.setAttribute('d',path.getAttribute('d'));
    visible.setAttribute('class','line '+(selectedLine===l.id?'selected':''));
    if(l.style==='dashed')visible.setAttribute('stroke-dasharray','10 7');
    if(l.style==='dotted')visible.setAttribute('stroke-dasharray','2 7');
    if(l.end==='none'){}
    else if(l.end==='circle'){
      const c=document.createElementNS('http://www.w3.org/2000/svg','circle');
      c.setAttribute('cx',x2);c.setAttribute('cy',y2);c.setAttribute('r',5);
      c.setAttribute('class','endpoint '+(selectedLine===l.id?'selected':''));
      c.addEventListener('pointerdown',ev=>startEndpoint(ev,l,'to'));
      svg.appendChild(c);
    }else{
      const marker='url(#arrow)';
      let defs=svg.querySelector('defs');
      if(!defs){defs=document.createElementNS('http://www.w3.org/2000/svg','defs');svg.appendChild(defs)}
      if(!defs.querySelector('#arrow')){
        const m=document.createElementNS('http://www.w3.org/2000/svg','marker');
        m.id='arrow';m.setAttribute('markerWidth','8');m.setAttribute('markerHeight','8');
        m.setAttribute('refX','7');m.setAttribute('refY','4');m.setAttribute('orient','auto');
        const q=document.createElementNS('http://www.w3.org/2000/svg','path');
        q.setAttribute('d','M0,0 L8,4 L0,8 Z');q.setAttribute('fill','#f44336');m.appendChild(q);defs.appendChild(m);
      }
      visible.setAttribute('marker-end',marker);
    }
    svg.appendChild(visible);

    if(selectedLine===l.id){
      [['from',x1,y1],['to',x2,y2]].forEach(([side,x,y])=>{
        const c=document.createElementNS('http://www.w3.org/2000/svg','circle');
        c.setAttribute('cx',x);c.setAttribute('cy',y);c.setAttribute('r',7);
        c.setAttribute('class','endpoint selected');
        c.addEventListener('pointerdown',ev=>startEndpoint(ev,l,side));
        svg.appendChild(c);
      });
    }
  });
}

function startEndpoint(ev,line,side){
  ev.preventDefault();ev.stopPropagation();
  drag={kind:'endpoint',line,side};
  document.addEventListener('pointermove',dragMove);
  document.addEventListener('pointerup',dragEnd,{once:true});
}
function startDrag(ev){
  ev.preventDefault();ev.stopPropagation();
  const el=get(ev.currentTarget.dataset.id);
  if(ev.currentTarget.dataset.handle){
    drag={kind:'resize',el,handle:ev.currentTarget.dataset.handle,x:ev.clientX,y:ev.clientY,w:el.w,h:el.h,px:el.x,py:el.y};
  }else{
    if(!selected.includes(el.id))selectElement(el.id,ev.shiftKey);
    drag={kind:'move',ids:[...selected],x:ev.clientX,y:ev.clientY,
      values:selected.map(id=>{const q=get(id);return{id,x:q.x,y:q.y}})};
  }
  document.addEventListener('pointermove',dragMove);
  document.addEventListener('pointerup',dragEnd,{once:true});
}
function dragMove(ev){
  if(!drag)return;
  const r=video.getBoundingClientRect(),dx=(ev.clientX-drag.x)/r.width*100,dy=(ev.clientY-drag.y)/r.height*100;
  if(drag.kind==='move'){
    drag.values.forEach(v=>{const e=get(v.id);e.x=Math.max(0,Math.min(100-e.w,v.x+dx));e.y=Math.max(0,Math.min(100-e.h,v.y+dy))});
  }else if(drag.kind==='resize'){
    const e=drag.el;
    if(drag.handle.includes('e'))e.w=Math.max(5,Math.min(100-e.x,drag.w+dx));
    if(drag.handle.includes('s'))e.h=Math.max(5,Math.min(100-e.y,drag.h+dy));
    if(drag.handle.includes('w')){e.x=Math.max(0,drag.px+dx);e.w=Math.max(5,drag.w-dx)}
    if(drag.handle.includes('n')){e.y=Math.max(0,drag.py+dy);e.h=Math.max(5,drag.h-dy)}
  }else if(drag.kind==='endpoint'){
    const p={x:(ev.clientX-r.left)/r.width*100,y:(ev.clientY-r.top)/r.height*100};
    const line=drag.line;
    const target=drag.side==='from'?line.from:line.to;
    const e=get(target);
    const candidates=[
      ['left',Math.abs(p.x-e.x)+Math.abs(p.y-(e.y+e.h/2))],
      ['right',Math.abs(p.x-(e.x+e.w))+Math.abs(p.y-(e.y+e.h/2))],
      ['top',Math.abs(p.x-(e.x+e.w/2))+Math.abs(p.y-e.y)],
      ['bottom',Math.abs(p.x-(e.x+e.w/2))+Math.abs(p.y-(e.y+e.h))]
    ];
    candidates.sort((a,b)=>a[1]-b[1]);
    line[drag.side+'Pos']=candidates[0][0];
  }
  render();
}
function dragEnd(){drag=null;document.removeEventListener('pointermove',dragMove)}

function renderMenu(){
  menu.innerHTML='';
  if(selectedLine){
    menuTitle.textContent='接続線の操作';
    const l=lines.find(x=>x.id===selectedLine);
    menu.innerHTML=`
      <label>線種</label>
      <select id="lineStyle">
        <option value="solid" ${l.style==='solid'?'selected':''}>実線</option>
        <option value="dashed" ${l.style==='dashed'?'selected':''}>破線</option>
        <option value="dotted" ${l.style==='dotted'?'selected':''}>点線</option>
      </select>
      <label>終端</label>
      <select id="lineEnd">
        <option value="arrow" ${l.end==='arrow'?'selected':''}>矢印</option>
        <option value="circle" ${l.end==='circle'?'selected':''}>丸</option>
        <option value="none" ${l.end==='none'?'selected':''}>なし</option>
      </select>
      <label>始点の接続位置</label>
      <select id="fromPos">${positions(l.fromPos)}</select>
      <label>終点の接続位置</label>
      <select id="toPos">${positions(l.toPos)}</select>
      <button id="deleteLine" class="danger">接続線を削除</button>
    `;
    lineEvents(l);return;
  }
  if(!selected.length){
    menuTitle.textContent='操作メニュー';
    menu.innerHTML='<div class="empty-menu">要素を選択すると、その要素に応じた操作を表示します。<br><br>Shift＋クリックで複数選択できます。</div>';
    return;
  }
  if(selected.length>1){
    menuTitle.textContent='複数選択の操作';
    menu.innerHTML=`<div class="selection-count">${selected.length}個の要素を選択中</div>
      <button id="connect">選択した要素を接続</button>
      <button id="deleteEls" class="danger">選択した要素を削除</button>`;
    document.getElementById('connect').onclick=connectSelected;
    document.getElementById('deleteEls').onclick=deleteSelected;return;
  }
  const e=get(selected[0]);
  menuTitle.textContent=e.type==='highlight'?'強調枠の操作':e.type==='comment'?'コメントの操作':e.type==='zoom'?'拡大枠の操作':'スキップの操作';
  let html='';
  if(e.type==='highlight'){
    html+=`<label>図形</label><select id="shape">
      <option value="rect" ${e.shape==='rect'?'selected':''}>四角</option>
      <option value="round" ${e.shape==='round'?'selected':''}>角丸</option>
      <option value="circle" ${e.shape==='circle'?'selected':''}>円</option>
    </select>`;
  }
  if(e.type==='comment')html+=`<label>コメント</label><input id="commentText" value="${e.text}" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:5px">`;
  if(e.type!=='skip')html+=`<button id="deleteEl" class="danger">この要素を削除</button>`;
  menu.innerHTML=html||'<div class="empty-menu">この要素に専用の操作はありません。</div>';
  if(e.type==='highlight')document.getElementById('shape').onchange=ev=>{e.shape=ev.target.value;render()};
  if(e.type==='comment')document.getElementById('commentText').oninput=ev=>{e.text=ev.target.value;renderElements()};
  const del=document.getElementById('deleteEl');if(del)del.onclick=deleteSelected;
}
function positions(v){
  return ['left','right','top','bottom'].map(x=>`<option value="${x}" ${v===x?'selected':''}>${{left:'左',right:'右',top:'上',bottom:'下'}[x]}</option>`).join('');
}
function lineEvents(l){
  document.getElementById('lineStyle').onchange=e=>{l.style=e.target.value;render()};
  document.getElementById('lineEnd').onchange=e=>{l.end=e.target.value;render()};
  document.getElementById('fromPos').onchange=e=>{l.fromPos=e.target.value;render()};
  document.getElementById('toPos').onchange=e=>{l.toPos=e.target.value;render()};
  document.getElementById('deleteLine').onclick=()=>{lines=lines.filter(x=>x.id!==l.id);clearSelection()};
}
function connectSelected(){
  if(selected.length!==2)return;
  lines.push({id:'l'+(++uid),from:selected[0],to:selected[1],fromPos:'right',toPos:'left',style:'solid',end:'arrow'});
  selectedLine=lines[lines.length-1].id;selected=[];render();
}
function deleteSelected(){
  const ids=[...selected];elements=elements.filter(e=>!ids.includes(e.id));
  lines=lines.filter(l=>!ids.includes(l.from)&&!ids.includes(l.to));
  clearSelection();
}

function add(type){
  const id='e'+(++uid);
  elements.push({
    id,type,x:15+(elements.length*6)%55,y:12+(elements.length*7)%55,
    w:type==='comment'?18:20,h:type==='comment'?8:20,
    text:type==='comment'?'新しいコメント':'',
    shape:type==='highlight'?'rect':undefined,start:5,end:20
  });
  selectElement(id,false);context.style.display='none';
}

video.addEventListener('click',e=>{
  if(e.target===video||e.target===overlay)clearSelection();
});
video.addEventListener('contextmenu',e=>{
  e.preventDefault();
  context.style.left=e.clientX+'px';context.style.top=e.clientY+'px';context.style.display='block';
});
document.addEventListener('click',e=>{
  if(!context.contains(e.target))context.style.display='none';
});
context.querySelectorAll('[data-add]').forEach(b=>b.onclick=()=>add(b.dataset.add));
document.getElementById('connectMenu').onclick=()=>{connectSelected();context.style.display='none'};

function renderTimeline(){
  tracks.innerHTML='';
  const visible=elements.filter(e=>e.type!=='skip');
  visible.forEach((e,i)=>{
    const row=document.createElement('div');row.className='track';
    const c=document.createElement('div');
    c.className='clip '+e.type;
    c.style.left=(e.start/50*100)+'%';c.style.width=((e.end-e.start)/50*100)+'%';
    c.textContent=e.type==='comment'?'コメント':e.type==='highlight'?'強調枠':'拡大枠';
    c.onclick=ev=>{ev.stopPropagation();selectElement(e.id,ev.shiftKey)};
    row.appendChild(c);tracks.appendChild(row);
  });
}

window.addEventListener('resize',()=>renderLines());
render();
</script>
</body>
</html>

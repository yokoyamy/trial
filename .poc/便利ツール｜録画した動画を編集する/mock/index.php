<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>録画動画編集</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;background:#10151c;color:#e8edf3}
button,input{font:inherit}
button{cursor:pointer}
.app{height:100%;display:flex;flex-direction:column}
.top{height:52px;display:flex;align-items:center;gap:8px;padding:7px 12px;background:#151c25;border-bottom:1px solid #303b48}
.title{font-weight:700;margin-right:10px}
.btn{border:1px solid #465365;background:#253142;color:#fff;border-radius:6px;padding:7px 11px}
.btn:hover{background:#344256}
.primary{background:#2563eb;border-color:#3b82f6}
.status{margin-left:auto;color:#9ba8b8;font-size:12px}
.file{display:none}

.main{min-height:0;flex:1;display:flex;flex-direction:column}
.video-area{min-height:0;flex:1;background:#06090d;display:flex;align-items:center;justify-content:center;padding:10px}
.video-wrap{position:relative;width:min(1100px,100%);height:100%;background:#000;overflow:hidden}
#video{width:100%;height:100%;object-fit:contain;display:block}
#overlay,#connections{position:absolute;inset:0}
#connections{z-index:10;width:100%;height:100%;overflow:visible;pointer-events:none}
#overlay{z-index:20;pointer-events:none}
.empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;color:#667384;line-height:1.8;pointer-events:none}
.empty strong{font-size:18px;color:#9aa7b6}

.el{position:absolute;min-width:30px;min-height:24px;pointer-events:auto;user-select:none;touch-action:none;cursor:move}
.el.selected{outline:2px solid #60a5fa;outline-offset:2px}
.el.multi{outline:2px solid #fbbf24;outline-offset:2px}
.el .body{width:100%;height:100%;display:flex;align-items:center;justify-content:center}
.el.text .body{color:#fff;text-shadow:0 1px 3px #000;font-size:18px}
.el.box .body{border:4px solid #ef4444;background:#ef444422}
.el.box.round .body{border-radius:18px}
.el.box.circle .body{border-radius:50%}
.el.zoom .body{border:3px solid #111;background:#ffffff22;box-shadow:0 0 0 2px #fff5}
.el.skip{display:none}
.handle{position:absolute;width:10px;height:10px;background:#fff;border:2px solid #60a5fa;z-index:30}
.nw{left:-6px;top:-6px;cursor:nwse-resize}.ne{right:-6px;top:-6px;cursor:nesw-resize}
.sw{left:-6px;bottom:-6px;cursor:nesw-resize}.se{right:-6px;bottom:-6px;cursor:nwse-resize}

.connection{fill:none;stroke:#ef4444;stroke-width:4;pointer-events:stroke;cursor:pointer}
.connection.selected{stroke:#60a5fa;stroke-width:7}
.conn-handle{fill:#fff;stroke:#60a5fa;stroke-width:3;cursor:crosshair;pointer-events:auto}

.timeline{height:255px;flex:none;border-top:1px solid #303b48;background:#111923;display:flex;flex-direction:column}
.timeline-tools{height:42px;display:flex;align-items:center;gap:8px;padding:5px 8px;border-bottom:1px solid #303b48}
.time{min-width:145px;font-variant-numeric:tabular-nums;color:#dce7f5}
.zoom{margin-left:auto;display:flex;align-items:center;gap:6px;font-size:12px;color:#9ba8b8}
.zoom input{width:110px}
.timeline-scroll{min-height:0;flex:1;overflow:hidden}
.timeline-inner{position:relative;width:100%;height:100%}
.axis{height:28px;position:relative;border-bottom:1px solid #303b48}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #334155;padding:5px 0 0 3px;color:#718096;font-size:9px}
.row{height:54px;position:relative;border-bottom:1px solid #273342}
.row-label{position:absolute;left:0;top:0;bottom:0;width:82px;background:#151e29;border-right:1px solid #303b48;padding:18px 7px;font-size:11px;z-index:5}
.lane{position:absolute;left:82px;right:0;top:0;bottom:0}
.bar{position:absolute;top:9px;height:36px;border:1px solid currentColor;border-radius:5px;display:flex;align-items:center;cursor:grab;user-select:none}
.bar.selected{box-shadow:0 0 0 2px #60a5fa}
.bar.multi{box-shadow:0 0 0 2px #fbbf24}
.bar span{font-size:10px;padding:0 7px;overflow:hidden;white-space:nowrap}
.bar-handle{position:absolute;top:0;width:9px;height:100%;cursor:ew-resize}
.bar-handle.left{left:-4px}.bar-handle.right{right:-4px}
.playhead{position:absolute;top:28px;bottom:0;width:2px;background:#ef4444;z-index:50;pointer-events:none}
.playhead:before{content:"";position:absolute;left:-5px;top:-1px;width:12px;height:12px;background:#ef4444;clip-path:polygon(0 0,100% 0,50% 100%)}

.menu{position:fixed;z-index:1000;display:none;min-width:225px;background:#182231;border:1px solid #465365;border-radius:7px;padding:5px;box-shadow:0 12px 35px #0009}
.menu-title{padding:6px 8px;color:#9ba8b8;font-size:11px}
.menu button{display:block;width:100%;border:0;background:transparent;color:#edf2f7;text-align:left;border-radius:4px;padding:8px}
.menu button:hover{background:#29384b}
.menu .sep{height:1px;background:#334155;margin:4px 0}
.menu select{width:100%;background:#253142;color:#fff;border:1px solid #465365;border-radius:4px;padding:6px}

.toast{position:fixed;right:14px;bottom:14px;background:#202b3a;border:1px solid #465365;padding:9px 13px;border-radius:6px;display:none;z-index:2000}
</style>
</head>

<body>
<div class="app">

<header class="top">
    <div class="title">録画動画編集</div>
    <button class="btn primary" id="load">動画を選択</button>
    <input id="file" class="file" type="file" accept="video/*">
    <button class="btn" id="new">要素追加</button>
    <span class="status" id="status">動画を選択してください</span>
</header>

<main class="main">
<section class="video-area">
    <div class="video-wrap" id="wrap">
        <video id="video" preload="metadata" playsinline></video>
        <svg id="connections"></svg>
        <div id="overlay"></div>
        <div class="empty" id="empty">
            <strong>動画を選択してください</strong>
            録画した動画を編集できます
        </div>
    </div>
</section>

<section class="timeline">
    <div class="timeline-tools">
        <button class="btn" id="play">▶</button>
        <button class="btn" id="stop">■</button>
        <span class="time" id="time">00:00.000 / 00:00.000</span>
        <div class="zoom">
            時間スケール
            <input id="scale" type="range" min="1" max="10" step=".1" value="1">
            <span id="scaleText">1.0×</span>
        </div>
    </div>
    <div class="timeline-scroll">
        <div class="timeline-inner" id="timeline"></div>
    </div>
</section>
</main>
</div>

<div class="menu" id="menu"></div>
<div class="toast" id="toast"></div>

<script>
const $=id=>document.getElementById(id);
const video=$('video'),wrap=$('wrap'),overlay=$('overlay'),svg=$('connections');
const timeline=$('timeline'),menu=$('menu'),empty=$('empty'),status=$('status');
const time=$('time'),scale=$('scale'),scaleText=$('scaleText');
let duration=60,selected=[],elements=[],connections=[],drag=null,connDrag=null,scaleValue=1;

const colors={text:'#60a5fa',box:'#ef4444',zoom:'#111',skip:'#f97316'};

function uid(){return Math.random().toString(36).slice(2,8)}
function fmt(v){
    v=Math.max(0,v||0);
    let m=Math.floor(v/60),s=v%60;
    return String(m).padStart(2,'0')+':'+s.toFixed(3).padStart(6,'0');
}
function toast(t){
    $('toast').textContent=t;$('toast').style.display='block';
    clearTimeout(toast.t);toast.t=setTimeout(()=>$('toast').style.display='none',1600);
}
function point(e){
    const r=wrap.getBoundingClientRect();
    return {x:(e.clientX-r.left)/r.width*100,y:(e.clientY-r.top)/r.height*100};
}
function closeMenu(){menu.style.display='none'}
function clearSelection(){selected=[];render()}
function select(id,add=false){
    if(!add)selected=[];
    if(selected.includes(id)){
        if(add)selected=selected.filter(x=>x!==id);
    }else selected.push(id);
    render();
}
function getEl(id){return elements.find(e=>e.id===id)}

function addElement(type,x=25,y=25,w=22,h=16){
    const e={
        id:uid(),type,x,y,w,h,start:0,end:Math.min(duration,20),
        text:type==='text'?'コメント':type==='box'?'強調':'拡大'
    };
    elements.push(e);selected=[e.id];render();toast('要素を追加しました');
}

function render(){
    overlay.innerHTML='';
    elements.forEach(e=>{
        if(e.type==='skip')return;
        const d=document.createElement('div');
        d.className='el '+e.type+
            (e.shape?' '+e.shape:'')+
            (selected.includes(e.id)?' selected':'')+
            (selected.length>1&&selected.includes(e.id)?' multi':'');
        d.dataset.id=e.id;
        d.style.left=e.x+'%';d.style.top=e.y+'%';
        d.style.width=e.w+'%';d.style.height=e.h+'%';
        d.innerHTML='<div class="body">'+(e.type==='text'?e.text:'')+'</div>'+
            '<i class="handle nw"></i><i class="handle ne"></i><i class="handle sw"></i><i class="handle se"></i>';
        d.addEventListener('pointerdown',startElement);
        d.addEventListener('contextmenu',ev=>{ev.preventDefault();select(e.id,false);showElementMenu(ev,e)});
        overlay.appendChild(d);
    });
    drawConnections();
    drawTimeline();
}

function startElement(ev){
    if(ev.target.classList.contains('handle'))return resizeStart(ev);
    ev.stopPropagation();
    const e=getEl(ev.currentTarget.dataset.id);
    select(e.id,ev.shiftKey);
    const p=point(ev),sx=e.x,sy=e.y;
    drag={e,p,sx,sy};
    ev.currentTarget.setPointerCapture(ev.pointerId);
    ev.currentTarget.addEventListener('pointermove',moveElement);
    ev.currentTarget.addEventListener('pointerup',endElement,{once:true});
}
function moveElement(ev){
    if(!drag)return;
    const p=point(ev),e=drag.e;
    e.x=Math.max(0,Math.min(100-e.w,drag.sx+p.x-drag.p.x));
    e.y=Math.max(0,Math.min(100-e.h,drag.sy+p.y-drag.p.y));
    render();
}
function endElement(){drag=null}

function resizeStart(ev){
    ev.stopPropagation();
    const d=ev.currentTarget,e=getEl(d.dataset.id),handle=ev.target.className;
    const p=point(ev),o={x:e.x,y:e.y,w:e.w,h:e.h};
    function move(x){
        const dx=x.x-p.x,dy=x.y-p.y;
        if(handle.includes('e'))e.w=Math.max(5,o.w+dx);
        if(handle.includes('s'))e.h=Math.max(5,o.h+dy);
        if(handle.includes('w')){e.x=Math.max(0,o.x+dx);e.w=Math.max(5,o.w-dx)}
        if(handle.includes('n')){e.y=Math.max(0,o.y+dy);e.h=Math.max(5,o.h-dy)}
        render();
    }
    function up(){window.removeEventListener('pointermove',mm);window.removeEventListener('pointerup',up)}
    function mm(x){move(point(x))}
    window.addEventListener('pointermove',mm);window.addEventListener('pointerup',up,{once:true});
}

function anchors(e,pos){
    if(pos==='top')return{x:e.x+e.w/2,y:e.y};
    if(pos==='right')return{x:e.x+e.w,y:e.y+e.h/2};
    if(pos==='bottom')return{x:e.x+e.w/2,y:e.y+e.h};
    return{x:e.x,y:e.y+e.h/2};
}
function drawConnections(){
    svg.innerHTML='';
    connections.forEach(c=>{
        const a=getEl(c.a),b=getEl(c.b);if(!a||!b)return;
        const p1=anchors(a,c.ap),p2=anchors(b,c.bp);
        const w=wrap.clientWidth,h=wrap.clientHeight;
        const x1=p1.x/100*w,y1=p1.y/100*h,x2=p2.x/100*w,y2=p2.y/100*h;
        let path;
        if(c.line==='orthogonal'){
            const mx=(x1+x2)/2;
            path=`M ${x1} ${y1} L ${mx} ${y1} L ${mx} ${y2} L ${x2} ${y2}`;
        }else if(c.line==='wave'){
            const dx=(x2-x1)/4;
            path=`M ${x1} ${y1} C ${x1+dx} ${y1-18},${x1+dx} ${y1+18},${x1+2*dx} ${y1} S ${x1+3*dx} ${y1-18},${x2} ${y2}`;
        }else{
            path=`M ${x1} ${y1} L ${x2} ${y2}`;
        }
        const p=document.createElementNS('http://www.w3.org/2000/svg','path');
        p.setAttribute('d',path);p.setAttribute('class','connection'+(c.id===selected[0]?' selected':''));
        p.addEventListener('pointerdown',ev=>{ev.stopPropagation();select(c.id,false);showConnectionMenu(ev,c)});
        svg.appendChild(p);
        if(c.id===selected[0]){
            [['a',p1],['b',p2]].forEach(([side,z])=>{
                const q=document.createElementNS('http://www.w3.org/2000/svg','circle');
                q.setAttribute('cx',z.x/100*w);q.setAttribute('cy',z.y/100*h);q.setAttribute('r',6);
                q.setAttribute('class','conn-handle');
                q.addEventListener('pointerdown',ev=>startConnHandle(ev,c,side));
                svg.appendChild(q);
            });
        }
    });
}
function startConnHandle(ev,c,side){
    ev.stopPropagation();
    function move(x){
        const p=point(x),e=getEl(side==='a'?c.a:c.b);
        let best='left',dist=Infinity;
        ['top','right','bottom','left'].forEach(pos=>{
            const q=anchors(e,pos),d=(q.x-p.x)**2+(q.y-p.y)**2;
            if(d<dist){dist=d;best=pos}
        });
        if(side==='a')c.ap=best;else c.bp=best;
        render();
    }
    function up(){window.removeEventListener('pointermove',move);window.removeEventListener('pointerup',up)}
    window.addEventListener('pointermove',move);window.addEventListener('pointerup',up,{once:true});
}

function drawTimeline(){
    timeline.innerHTML='';
    const axis=document.createElement('div');axis.className='axis';
    const ticks=Math.max(4,Math.round(10/scaleValue));
    for(let i=0;i<=ticks;i++){
        const t=duration*i/ticks,d=document.createElement('div');
        d.className='tick';d.style.left=(i/ticks*100)+'%';d.textContent=fmt(t);
        axis.appendChild(d);
    }
    timeline.appendChild(axis);
    elements.forEach(e=>{
        const row=document.createElement('div');row.className='row';
        const label=document.createElement('div');label.className='row-label';
        label.textContent=e.type==='text'?'コメント':e.type==='box'?'強調枠':e.type==='zoom'?'拡大枠':'スキップ';
        row.appendChild(label);
        const lane=document.createElement('div');lane.className='lane';
        const bar=document.createElement('div');bar.className='bar';
        if(selected.includes(e.id))bar.classList.add(selected.length>1?'multi':'selected');
        bar.style.left=(e.start/duration*100)+'%';bar.style.width=((e.end-e.start)/duration*100)+'%';
        bar.style.color=colors[e.type];bar.style.background=colors[e.type]+'22';
        bar.innerHTML='<span>'+label.textContent+'</span><i class="bar-handle left"></i><i class="bar-handle right"></i>';
        bar.addEventListener('pointerdown',ev=>timelineDrag(ev,e,bar));
        bar.addEventListener('contextmenu',ev=>{ev.preventDefault();select(e.id,false);showElementMenu(ev,e)});
        lane.appendChild(bar);row.appendChild(lane);timeline.appendChild(row);
    });
    const ph=document.createElement('div');ph.className='playhead';
    ph.style.left=(video.currentTime/duration*100)+'%';
    timeline.appendChild(ph);
    timeline.addEventListener('pointerdown',timelineSeek);
}
function timelineSeek(ev){
    if(ev.target.closest('.bar'))return;
    const r=timeline.getBoundingClientRect(),x=Math.max(0,Math.min(1,(ev.clientX-r.left)/r.width));
    video.currentTime=x*duration;
}
function timelineDrag(ev,e,bar){
    ev.stopPropagation();select(e.id,ev.shiftKey);
    const r=timeline.getBoundingClientRect(),start=e.start,end=e.end;
    const resizing=ev.target.classList.contains('bar-handle');
    const right=ev.target.classList.contains('right');
    const left=ev.target.classList.contains('left');
    const sx=ev.clientX;
    function move(x){
        const delta=(x.clientX-sx)/r.width*duration;
        if(resizing&&left)e.start=Math.max(0,Math.min(e.end-.2,start+delta));
        else if(resizing&&right)e.end=Math.max(e.start+.2,Math.min(duration,end+delta));
        else{
            const len=end-start,n=Math.max(0,Math.min(duration-len,start+delta));
            e.start=n;e.end=n+len;
        }
        render();
    }
    function up(){window.removeEventListener('pointermove',move);window.removeEventListener('pointerup',up)}
    window.addEventListener('pointermove',move);window.addEventListener('pointerup',up,{once:true});
}

function menuBase(title){
    menu.innerHTML='<div class="menu-title">'+title+'</div>';
    menu.style.display='block';
}
function positionMenu(ev){
    menu.style.left=Math.min(ev.clientX,innerWidth-240)+'px';
    menu.style.top=Math.min(ev.clientY,innerHeight-300)+'px';
}
function showElementMenu(ev,e){
    menuBase(e.type==='box'?'強調枠':e.type==='text'?'コメント':e.type==='zoom'?'拡大枠':'スキップ');
    if(e.type==='box'){
        menu.innerHTML+='<button data-shape="square">□ 四角</button><button data-shape="round">▢ 角丸</button><button data-shape="circle">○ 円</button><div class="sep"></div>';
    }
    if(selected.length>=2){
        menu.innerHTML+='<button id="connect">選択した要素を接続</button><div class="sep"></div>';
    }
    menu.innerHTML+='<button id="delete">削除</button>';
    positionMenu(ev);
    menu.querySelectorAll('[data-shape]').forEach(b=>b.onclick=()=>{e.shape=b.dataset.shape;closeMenu();render()});
    const del=menu.querySelector('#delete');if(del)del.onclick=()=>{elements=elements.filter(x=>!selected.includes(x.id));connections=connections.filter(c=>!selected.includes(c.a)&&!selected.includes(c.b));selected=[];closeMenu();render()};
    const con=menu.querySelector('#connect');if(con)con.onclick=connectSelected;
}
function showConnectionMenu(ev,c){
    menuBase('接続線');
    menu.innerHTML+=
        '<button data-line="straight">直線</button>'+
        '<button data-line="orthogonal">折れ線</button>'+
        '<button data-line="wave">波線</button>'+
        '<div class="sep"></div>'+
        '<div class="menu-title">始点位置</div>'+
        '<select id="ap"><option>top</option><option>right</option><option>bottom</option><option>left</option></select>'+
        '<div class="menu-title">終点位置</div>'+
        '<select id="bp"><option>top</option><option>right</option><option>bottom</option><option>left</option></select>'+
        '<div class="menu-title">終端</div>'+
        '<button data-end="arrow">矢印</button><button data-end="dot">丸</button>';
    positionMenu(ev);
    menu.querySelectorAll('[data-line]').forEach(b=>b.onclick=()=>{c.line=b.dataset.line;closeMenu();render()});
    menu.querySelector('#ap').value=c.ap;menu.querySelector('#bp').value=c.bp;
    menu.querySelector('#ap').onchange=e=>{c.ap=e.target.value;render()};
    menu.querySelector('#bp').onchange=e=>{c.bp=e.target.value;render()};
    menu.querySelectorAll('[data-end]').forEach(b=>b.onclick=()=>{c.end=b.dataset.end;closeMenu();render()});
}
function connectSelected(){
    if(selected.length!==2){toast('2つの要素を選択してください');return}
    if(elements.find(e=>e.id===selected[0]).type==='skip'||elements.find(e=>e.id===selected[1]).type==='skip'){toast('スキップは接続できません');return}
    connections.push({id:uid(),a:selected[0],b:selected[1],ap:'right',bp:'left',line:'straight',end:'arrow'});
    closeMenu();selected=[];render();toast('接続しました');
}

$('load').onclick=()=>$('file').click();
$('file').onchange=ev=>{
    const f=ev.target.files[0];if(!f)return;
    video.src=URL.createObjectURL(f);video.load();
    video.onloadedmetadata=()=>{
        duration=video.duration||60;empty.style.display='none';status.textContent=f.name;
        render();
    };
};
$('play').onclick=()=>video.paused?video.play():video.pause();
$('stop').onclick=()=>{video.pause();video.currentTime=0};
video.ontimeupdate=()=>{
    time.textContent=fmt(video.currentTime)+' / '+fmt(duration);
    const p=document.querySelector('.playhead');if(p)p.style.left=(video.currentTime/duration*100)+'%';
};
video.onended=()=>render();

$('new').onclick=ev=>{
    menuBase('要素を追加');
    menu.innerHTML+='<button id="addText">コメント</button><button id="addBox">強調枠</button><button id="addZoom">拡大枠</button><button id="addSkip">スキップ</button>';
    positionMenu(ev);
    $('addText').onclick=()=>{addElement('text');closeMenu()};
    $('addBox').onclick=()=>{addElement('box');closeMenu()};
    $('addZoom').onclick=()=>{addElement('zoom');closeMenu()};
    $('addSkip').onclick=()=>{addElement('skip');closeMenu()};
};

wrap.addEventListener('contextmenu',ev=>{
    if(ev.target!==wrap&&ev.target!==video)return;
    ev.preventDefault();clearSelection();
    menuBase('要素を追加');
    menu.innerHTML+='<button id="addText">コメント</button><button id="addBox">強調枠</button><button id="addZoom">拡大枠</button><button id="addSkip">スキップ</button>';
    positionMenu(ev);
    $('addText').onclick=()=>{addElement('text');closeMenu()};
    $('addBox').onclick=()=>{addElement('box');closeMenu()};
    $('addZoom').onclick=()=>{addElement('zoom');closeMenu()};
    $('addSkip').onclick=()=>{addElement('skip');closeMenu()};
});
document.addEventListener('pointerdown',ev=>{
    if(!ev.target.closest('.menu'))closeMenu();
});
scale.oninput=()=>{
    scaleValue=+scale.value;scaleText.textContent=scaleValue.toFixed(1)+'×';render();
};
window.addEventListener('resize',render);

elements=[
    {id:'e1',type:'text',x:12,y:15,w:22,h:12,start:4,end:22,text:'ここを確認'},
    {id:'e2',type:'box',shape:'square',x:48,y:30,w:24,h:24,start:10,end:35,text:''},
    {id:'e3',type:'zoom',x:27,y:58,w:25,h:22,start:24,end:48,text:''}
];
connections=[{id:'c1',a:'e1',b:'e2',ap:'right',bp:'left',line:'straight',end:'arrow'}];
render();
</script>
</body>
</html>

<?php
const DATA_FILE = __DIR__ . '/projects.json';

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $data = json_decode($_POST['data'] ?? '{}', true);
        if (!is_array($data)) json_out(['ok'=>false, 'error'=>'データ不正'], 400);

        $all = file_exists(DATA_FILE)
            ? json_decode(file_get_contents(DATA_FILE), true)
            : [];
        if (!is_array($all)) $all = [];

        $name = $data['name'] ?? '名称未設定';
        $data['savedAt'] = date('Y-m-d H:i:s');
        $all[$name] = $data;

        file_put_contents(
            DATA_FILE,
            json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
        json_out(['ok'=>true]);
    }

    if ($action === 'load') {
        $all = file_exists(DATA_FILE)
            ? json_decode(file_get_contents(DATA_FILE), true)
            : [];
        json_out(['ok'=>true, 'projects'=>is_array($all) ? array_values($all) : []]);
    }

    if ($action === 'delete') {
        $name = $_POST['name'] ?? '';
        $all = file_exists(DATA_FILE)
            ? json_decode(file_get_contents(DATA_FILE), true)
            : [];

        if (isset($all[$name])) {
            unset($all[$name]);
            file_put_contents(
                DATA_FILE,
                json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        }
        json_out(['ok'=>true]);
    }

    json_out(['ok'=>false, 'error'=>'不明なAPI'], 400);
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画注釈編集</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:#f3f4f6;color:#222}
button,input,select{font:inherit}
button{cursor:pointer}
.toolbar{height:52px;background:#20242a;color:#fff;display:flex;align-items:center;gap:7px;padding:8px 12px}
.toolbar button{border:0;border-radius:5px;padding:7px 12px}
.toolbar .title{font-weight:bold;margin-right:auto}
.wrap{display:flex;height:calc(100vh - 52px);flex-direction:column}
.main{flex:1;display:flex;min-height:0}
.video-area{flex:1;display:flex;align-items:center;justify-content:center;background:#111;min-width:0}
.stage{position:relative;display:inline-block;max-width:100%;max-height:100%}
.stage video{display:block;max-width:100%;max-height:calc(100vh - 230px);background:#000}
.overlay{position:absolute;inset:0}
.el{position:absolute;border:2px solid #1976d2;background:#fff9;padding:4px;overflow:hidden;user-select:none;cursor:move}
.el.selected{outline:2px solid #ff9800}
.el.comment{background:#fffbd8}
.el.highlight{background:#fff17688;border-color:#e0a800}
.el.skip{background:#ffcdd288;border-color:#d32f2f}
.el.zoom{background:#ffeb3b22;border:2px dashed #f57c00}
.zoomview{position:absolute;border:2px solid #f57c00;background:#000;pointer-events:none;overflow:hidden}
.zoomview canvas{width:100%;height:100%;display:block}
.side{width:260px;background:#fff;border-left:1px solid #ccc;padding:10px;overflow:auto}
.side h3{margin:4px 0 10px}
.item{padding:8px;border:1px solid #ddd;border-radius:5px;margin-bottom:6px;background:#fafafa}
.timeline{height:150px;background:#fff;border-top:1px solid #ccc;padding:8px 12px}
.track{height:70px;position:relative;background:#eee;border:1px solid #ccc;margin-top:8px;overflow:hidden}
.bar{position:absolute;top:20px;height:30px;border-radius:4px;background:#4c8bf5;color:#fff;font-size:11px;padding:6px;cursor:grab;white-space:nowrap}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:red;pointer-events:none}
.ctx{position:fixed;z-index:50;background:#fff;border:1px solid #bbb;box-shadow:0 3px 12px #0003;display:none}
.ctx div{padding:8px 14px;cursor:pointer;white-space:nowrap}
.ctx div:hover{background:#eee}
.modal-bg{position:fixed;inset:0;background:#0008;display:none;align-items:center;justify-content:center;z-index:60}
.modal{background:#fff;border-radius:8px;padding:18px;width:360px;max-width:90vw}
.modal h3{margin-top:0}
.row{margin:9px 0}
.row label{display:block;font-size:12px;color:#555;margin-bottom:3px}
.row input,.row select{width:100%;padding:7px;border:1px solid #bbb;border-radius:4px}
.actions{display:flex;gap:7px;justify-content:flex-end;margin-top:15px}
.project-list{max-height:300px;overflow:auto}
.small{font-size:12px;color:#666}
</style>
</head>

<body>

<div class="toolbar">
    <div class="title">動画注釈編集</div>
    <button onclick="$('#videoFile').click()">動画を開く</button>
    <button onclick="newProject()">新規</button>
    <button onclick="saveProject()">保存</button>
    <button onclick="openProjects()">開く</button>
    <input id="videoFile" type="file" accept="video/*" hidden>
</div>

<div class="wrap">
<div class="main">

<div class="video-area">
    <div class="stage" id="stage">
        <video id="video" controls></video>
        <div class="overlay" id="overlay"></div>
    </div>
</div>

<div class="side">
    <h3>要素</h3>
    <div id="elementList"></div>
    <hr>
    <div class="small">
        使い方<br>
        動画上で右クリック → 要素追加<br>
        要素を右クリック → 接続開始<br>
        接続線を右クリック → 属性変更<br>
        接続線の端をドラッグ → 接点変更
    </div>
</div>

</div>

<div class="timeline">
    <div>
        <span id="timeLabel">00:00 / 00:00</span>
    </div>
    <div class="track" id="track"></div>
</div>
</div>

<div class="ctx" id="ctx"></div>

<div class="modal-bg" id="modalBg">
<div class="modal" id="modal"></div>
</div>

<script>
const $=s=>document.querySelector(s);
const video=$('#video'),stage=$('#stage'),overlay=$('#overlay');
let project=null,selected=null,connectFrom=null,editId=null,editConn=null;
let drag=null,ctxTarget=null;

function uid(){return 'id'+Date.now()+Math.random().toString(36).slice(2,7)}
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
function fmt(t){t=Math.max(0,t||0);return String(Math.floor(t/60)).padStart(2,'0')+':'+String(Math.floor(t%60)).padStart(2,'0')}

function newProject(){
    project={
        name:'新規プロジェクト',
        videoName:'',
        duration:video.duration||0,
        elements:[],
        connections:[]
    };
    selected=null;connectFrom=null;render();
}

function ensureProject(){
    if(!project)newProject();
}

$('#videoFile').onchange=e=>{
    const f=e.target.files[0];
    if(!f)return;
    const url=URL.createObjectURL(f);
    video.src=url;
    video.load();
    ensureProject();
    project.videoName=f.name;
    video.onloadedmetadata=()=>{
        project.duration=video.duration;
        render();
    };
};

video.ontimeupdate=()=>{
    $('#timeLabel').textContent=fmt(video.currentTime)+' / '+fmt(video.duration);
    renderTimeline();
    drawZooms();
};

video.onplay=()=>{
    const loop=()=>{
        if(video.paused)return;
        drawZooms();
        requestAnimationFrame(loop);
    };
    loop();
};

function render(){
    renderElements();
    renderConnections();
    renderList();
    renderTimeline();
}

function renderElements(){
    overlay.innerHTML='';
    if(!project)return;

    project.elements.forEach(e=>{
        if(e.type==='zoom'){
            const z=document.createElement('div');
            z.className='el zoom'+(selected===e.id?' selected':'');
            z.dataset.id=e.id;
            z.style.cssText=`left:${e.x}%;top:${e.y}%;width:${e.w}%;height:${e.h}%`;
            z.innerHTML=esc(e.name||'拡大');
            bindElement(z,e);
            overlay.appendChild(z);

            const v=document.createElement('div');
            v.className='zoomview';
            v.dataset.zoom=e.id;
            const zw=Math.min(e.w*e.scale,35);
            const zh=Math.min(e.h*e.scale,35);
            let zx=e.x+e.w+1;
            let zy=e.y;
            if(zx+zw>100)zx=Math.max(0,e.x-zw-1);
            if(zy+zh>100)zy=100-zh;
            v.style.cssText=`left:${zx}%;top:${zy}%;width:${zw}%;height:${zh}%`;
            v.innerHTML='<canvas></canvas>';
            overlay.appendChild(v);
            return;
        }

        const d=document.createElement('div');
        d.className='el '+e.type+(selected===e.id?' selected':'');
        d.dataset.id=e.id;
        d.style.cssText=`left:${e.x}%;top:${e.y}%;width:${e.w}%;height:${e.h}%;font-size:${e.fontSize||14}px`;
        d.textContent=e.text||e.name||e.type;
        bindElement(d,e);
        overlay.appendChild(d);
    });
}

function bindElement(el,e){
    el.onpointerdown=ev=>{
        if(ev.button!==0)return;
        selected=e.id;
        const r=overlay.getBoundingClientRect();
        drag={
            id:e.id,
            ox:ev.clientX,
            oy:ev.clientY,
            x:e.x,y:e.y,
            w:r.width,h:r.height
        };
        renderElements();
        ev.stopPropagation();
    };

    el.oncontextmenu=ev=>{
        ev.preventDefault();
        selected=e.id;

        if(connectFrom && connectFrom!==e.id){
            connectElements(connectFrom,e.id);
            connectFrom=null;
            hideCtx();
            return;
        }

        showElementMenu(ev.clientX,ev.clientY,e);
    };
}

document.onpointermove=ev=>{
    if(!drag)return;
    const e=project.elements.find(x=>x.id===drag.id);
    if(!e)return;

    e.x=clamp(drag.x+(ev.clientX-drag.ox)/drag.w*100,0,100-e.w);
    e.y=clamp(drag.y+(ev.clientY-drag.oy)/drag.h*100,0,100-e.h);
    renderElements();
    renderConnections();
};

document.onpointerup=()=>{
    drag=null;
};

function showElementMenu(x,y,e){
    const items=[
        ['編集',()=>editElement(e)],
        ['この要素から接続開始',()=>{
            connectFrom=e.id;
            toast('次に接続先の要素を右クリックしてください');
        }],
        ['削除',()=>deleteElement(e.id)]
    ];
    if(connectFrom&&connectFrom!==e.id)
        items.unshift(['ここへ接続',()=>connectElements(connectFrom,e.id)]);

    showCtx(x,y,items);
}

function addElement(type,x=10,y=10){
    ensureProject();
    const e={
        id:uid(),
        type,
        name:type==='zoom'?'拡大範囲':type==='comment'?'コメント':'強調',
        text:type==='zoom'?'拡大':type==='comment'?'コメント':'',
        start:video.currentTime||0,
        end:(video.currentTime||0)+5,
        x,y,w:type==='zoom'?18:16,h:type==='zoom'?14:9,
        color:'#1976d2',
        fontSize:14,
        scale:2
    };
    project.elements.push(e);
    selected=e.id;
    render();
}

function deleteElement(id){
    project.elements=project.elements.filter(e=>e.id!==id);
    project.connections=project.connections.filter(c=>c.from!==id&&c.to!==id);
    selected=null;
    render();
}

function editElement(e){
    editId=e.id;
    $('#modal').innerHTML=`
    <h3>要素編集</h3>
    <div class="row"><label>名称</label><input id="en" value="${esc(e.name)}"></div>
    <div class="row"><label>表示文字</label><input id="et" value="${esc(e.text)}"></div>
    <div class="row"><label>開始秒</label><input id="es" type="number" step=".1" value="${e.start}"></div>
    <div class="row"><label>終了秒</label><input id="ee" type="number" step=".1" value="${e.end}"></div>
    ${e.type==='zoom'?'<div class="row"><label>拡大倍率</label><input id="ez" type="number" step=".1" min="1" max="5" value="'+(e.scale||2)+'"></div>':''}
    <div class="actions">
      <button onclick="closeModal()">キャンセル</button>
      <button onclick="saveElementEdit()">保存</button>
    </div>`;
    $('#modalBg').style.display='flex';
}

function saveElementEdit(){
    const e=project.elements.find(x=>x.id===editId);
    if(!e)return;
    e.name=$('#en').value;
    e.text=$('#et').value;
    e.start=+$('#es').value||0;
    e.end=+$('#ee').value||e.start+5;
    if(e.type==='zoom')e.scale=+$('#ez').value||2;
    closeModal();
    render();
}

function connectElements(a,b){
    if(a===b)return;
    project.connections.push({
        id:uid(),
        from:a,to:b,
        fromPoint:'right',
        toPoint:'left',
        color:'#1565c0',
        width:3,
        style:'solid',
        arrow:true
    });
    selected=null;
    render();
}

function renderConnections(){
    let svg=document.getElementById('connections');
    if(svg)svg.remove();

    svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
    svg.id='connections';
    svg.style.cssText='position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none';
    overlay.prepend(svg);

    if(!project)return;

    project.connections.forEach(c=>{
        const a=project.elements.find(e=>e.id===c.from);
        const b=project.elements.find(e=>e.id===c.to);
        if(!a||!b)return;

        const p1=sidePoint(a,c.fromPoint);
        const p2=sidePoint(b,c.toPoint);

        const line=document.createElementNS('http://www.w3.org/2000/svg','path');
        line.setAttribute('d',`M ${p1.x} ${p1.y} L ${p2.x} ${p2.y}`);
        line.setAttribute('fill','none');
        line.setAttribute('stroke',c.color||'#1565c0');
        line.setAttribute('stroke-width',c.width||3);
        line.setAttribute('stroke-dasharray',c.style==='dashed'?'8 5':'');
        line.style.pointerEvents='stroke';
        line.style.cursor='pointer';
        line.oncontextmenu=ev=>{
            ev.preventDefault();
            editConnection(c);
        };
        svg.appendChild(line);

        if(selected===c.id){
            [p1,p2].forEach((p,i)=>{
                const h=document.createElementNS('http://www.w3.org/2000/svg','circle');
                h.setAttribute('cx',p.x);
                h.setAttribute('cy',p.y);
                h.setAttribute('r',6);
                h.setAttribute('fill','#fff');
                h.setAttribute('stroke','#f00');
                h.style.pointerEvents='all';
                h.style.cursor='crosshair';
                h.onpointerdown=ev=>{
                    ev.stopPropagation();
                    startEndpointDrag(ev,c,i);
                };
                svg.appendChild(h);
            });
        }
    });
}

function sidePoint(e,side){
    let x=e.x,y=e.y;
    if(side==='right')x+=e.w;
    if(side==='bottom')y+=e.h;
    if(side==='left'){}
    if(side==='top'){}
    if(side==='right'||side==='left')y+=e.h/2;
    else x+=e.w/2;
    return {x:x/100*overlay.clientWidth,y:y/100*overlay.clientHeight};
}

function startEndpointDrag(ev,c,index){
    const move=e=>{
        const r=overlay.getBoundingClientRect();
        const x=(e.clientX-r.left)/r.width*100;
        const y=(e.clientY-r.top)/r.height*100;

        const target=findElementAt(x,y);
        if(target){
            if(index===0)c.from=target.id;
            else c.to=target.id;
            const p=nearestSide(target,x,y);
            if(index===0)c.fromPoint=p;
            else c.toPoint=p;
            renderConnections();
        }
    };

    const up=()=>{
        document.removeEventListener('pointermove',move);
        document.removeEventListener('pointerup',up);
    };

    document.addEventListener('pointermove',move);
    document.addEventListener('pointerup',up);
}

function findElementAt(x,y){
    return [...project.elements].reverse().find(e=>
        x>=e.x&&x<=e.x+e.w&&y>=e.y&&y<=e.y+e.h
    );
}

function nearestSide(e,x,y){
    const d=[
        ['top',Math.abs(y-e.y)],
        ['right',Math.abs(x-(e.x+e.w))],
        ['bottom',Math.abs(y-(e.y+e.h))],
        ['left',Math.abs(x-e.x)]
    ];
    d.sort((a,b)=>a[1]-b[1]);
    return d[0][0];
}

function editConnection(c){
    editConn=c;
    $('#modal').innerHTML=`
    <h3>接続線属性</h3>
    <div class="row"><label>始点</label>
      <select id="cp1">${sides(c.fromPoint)}</select></div>
    <div class="row"><label>終点</label>
      <select id="cp2">${sides(c.toPoint)}</select></div>
    <div class="row"><label>線幅</label>
      <input id="cw" type="number" min="1" max="10" value="${c.width}"></div>
    <div class="row"><label>線種</label>
      <select id="cs"><option value="solid">実線</option><option value="dashed">破線</option></select></div>
    <div class="row"><label>矢印</label>
      <select id="ca"><option value="1">あり</option><option value="0">なし</option></select></div>
    <div class="row"><label>色</label>
      <input id="cc" type="color" value="${c.color}"></div>
    <div class="actions">
      <button onclick="deleteConnection()">削除</button>
      <button onclick="closeModal()">キャンセル</button>
      <button onclick="saveConnection()">保存</button>
    </div>`;
    $('#cs').value=c.style||'solid';
    $('#ca').value=c.arrow?'1':'0';
    $('#modalBg').style.display='flex';
}

function sides(v){
    return ['top','right','bottom','left'].map(x=>
        `<option value="${x}" ${x===v?'selected':''}>${x}</option>`
    ).join('');
}

function saveConnection(){
    editConn.fromPoint=$('#cp1').value;
    editConn.toPoint=$('#cp2').value;
    editConn.width=+$('#cw').value||3;
    editConn.style=$('#cs').value;
    editConn.arrow=$('#ca').value==='1';
    editConn.color=$('#cc').value;
    closeModal();
    render();
}

function deleteConnection(){
    project.connections=project.connections.filter(c=>c.id!==editConn.id);
    closeModal();
    render();
}

function renderList(){
    const box=$('#elementList');
    box.innerHTML='';
    if(!project)return;

    project.elements.forEach(e=>{
        const d=document.createElement('div');
        d.className='item';
        d.innerHTML=`<b>${esc(e.name)}</b><br><span class="small">${e.type} / ${fmt(e.start)}～${fmt(e.end)}</span>`;
        d.onclick=()=>{selected=e.id;render()};
        box.appendChild(d);
    });
}

function renderTimeline(){
    const t=$('#track');
    t.innerHTML='';
    if(!project)return;

    project.elements.forEach(e=>{
        const d=document.createElement('div');
        d.className='bar';
        const duration=project.duration||video.duration||1;
        d.style.left=(e.start/duration*100)+'%';
        d.style.width=Math.max(2,(e.end-e.start)/duration*100)+'%';
        d.textContent=e.name;
        d.onpointerdown=ev=>{
            const startX=ev.clientX;
            const original=e.start;
            const rect=t.getBoundingClientRect();
            const move=x=>{
                const delta=(x.clientX-startX)/rect.width*duration;
                const len=e.end-e.start;
                e.start=clamp(original+delta,0,Math.max(0,duration-len));
                e.end=e.start+len;
                renderTimeline();
                renderElements();
            };
            const up=()=>{
                document.removeEventListener('pointermove',move);
                document.removeEventListener('pointerup',up);
            };
            document.addEventListener('pointermove',move);
            document.addEventListener('pointerup',up);
            ev.stopPropagation();
        };
        t.appendChild(d);
    });

    const p=document.createElement('div');
    p.className='playhead';
    const duration=project.duration||video.duration||1;
    p.style.left=(video.currentTime/duration*100)+'%';
    t.appendChild(p);
}

function drawZooms(){
    if(!project||!video.videoWidth)return;

    project.elements.filter(e=>e.type==='zoom').forEach(e=>{
        const view=document.querySelector(`[data-zoom="${e.id}"]`);
        if(!view)return;
        const canvas=view.querySelector('canvas');
        const cw=canvas.clientWidth*devicePixelRatio;
        const ch=canvas.clientHeight*devicePixelRatio;
        if(canvas.width!==cw||canvas.height!==ch){
            canvas.width=cw;
            canvas.height=ch;
        }

        const sw=video.videoWidth*(e.w/100);
        const sh=video.videoHeight*(e.h/100);
        const sx=video.videoWidth*(e.x/100);
        const sy=video.videoHeight*(e.y/100);

        const ctx=canvas.getContext('2d');
        ctx.clearRect(0,0,canvas.width,canvas.height);
        try{
            ctx.drawImage(video,sx,sy,sw,sh,0,0,canvas.width,canvas.height);
        }catch(_){}
    });
}

function showCtx(x,y,items){
    const c=$('#ctx');
    c.innerHTML='';
    items.forEach(([label,fn])=>{
        const d=document.createElement('div');
        d.textContent=label;
        d.onclick=()=>{hideCtx();fn()};
        c.appendChild(d);
    });
    c.style.left=x+'px';
    c.style.top=y+'px';
    c.style.display='block';
}

function hideCtx(){$('#ctx').style.display='none'}
document.onclick=e=>{
    if(!e.target.closest('.ctx'))hideCtx();
};

stage.oncontextmenu=e=>{
    e.preventDefault();
    const r=overlay.getBoundingClientRect();
    const x=(e.clientX-r.left)/r.width*100;
    const y=(e.clientY-r.top)/r.height*100;

    showCtx(e.clientX,e.clientY,[
        ['＋ コメント',()=>addElement('comment',x,y)],
        ['＋ 強調',()=>addElement('highlight',x,y)],
        ['＋ 拡大要素',()=>addElement('zoom',x,y)]
    ]);
};

function openProjects(){
    fetch(location.pathname,{method:'POST',body:new URLSearchParams({action:'load'})})
    .then(r=>r.json()).then(d=>{
        const list=d.projects||[];
        $('#modal').innerHTML=`
        <h3>プロジェクトを開く</h3>
        <div class="project-list">
        ${list.length?list.map((p,i)=>`
            <div class="item">
                <b>${esc(p.name)}</b>
                <div class="small">${esc(p.videoName||'動画未設定')} / ${esc(p.savedAt||'')}</div>
                <div class="actions">
                    <button onclick="loadProject(${i})">開く</button>
                    <button onclick="removeProject(${JSON.stringify(p.name)})">削除</button>
                </div>
            </div>`).join(''):'保存されたプロジェクトはありません'}
        </div>
        <div class="actions"><button onclick="closeModal()">閉じる</button></div>`;
        window.projectChoices=list;
        $('#modalBg').style.display='flex';
    });
}

function loadProject(i){
    project=JSON.parse(JSON.stringify(window.projectChoices[i]));
    selected=null;
    connectFrom=null;
    closeModal();
    render();
}

function removeProject(name){
    if(!confirm('削除しますか？'))return;
    fetch(location.pathname,{
        method:'POST',
        body:new URLSearchParams({action:'delete',name})
    }).then(()=>openProjects());
}

function saveProject(){
    ensureProject();
    const name=prompt('プロジェクト名',project.name||'新規プロジェクト');
    if(!name)return;
    project.name=name;
    project.duration=video.duration||project.duration||0;

    fetch(location.pathname,{
        method:'POST',
        body:new URLSearchParams({
            action:'save',
            data:JSON.stringify(project)
        })
    }).then(r=>r.json()).then(d=>{
        if(d.ok)alert('保存しました');
        else alert(d.error||'保存できませんでした');
    });
}

function closeModal(){$('#modalBg').style.display='none'}
$('#modalBg').onclick=e=>{
    if(e.target===$('#modalBg'))closeModal();
};

function clamp(v,a,b){return Math.max(a,Math.min(b,v))}

function toast(msg){
    const d=document.createElement('div');
    d.textContent=msg;
    d.style.cssText='position:fixed;bottom:170px;left:50%;transform:translateX(-50%);background:#222;color:#fff;padding:10px 18px;border-radius:5px;z-index:100';
    document.body.appendChild(d);
    setTimeout(()=>d.remove(),1800);
}

newProject();
</script>
</body>
</html>
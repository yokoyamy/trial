```php
<?php
declare(strict_types=1);

const DATA_FILE = __DIR__ . '/projects.json';

function api(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $all = is_file(DATA_FILE)
        ? json_decode(file_get_contents(DATA_FILE) ?: '{}', true)
        : [];
    if (!is_array($all)) $all = [];

    if ($action === 'load') api(['ok'=>true, 'projects'=>$all]);

    if ($action === 'save') {
        $p = json_decode($_POST['data'] ?? '', true);
        if (!is_array($p)) api(['ok'=>false,'error'=>'データが不正です'],400);

        $name = trim((string)($p['name'] ?? '新規プロジェクト'));
        $p['name'] = $name;
        $p['savedAt'] = date('c');
        $all[$name] = $p;

        if (file_put_contents(
            DATA_FILE,
            json_encode($all, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),
            LOCK_EX
        ) === false) {
            api(['ok'=>false,'error'=>'保存できません'],500);
        }
        api(['ok'=>true]);
    }

    if ($action === 'delete') {
        unset($all[(string)($_POST['name'] ?? '')]);
        file_put_contents(
            DATA_FILE,
            json_encode($all, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),
            LOCK_EX
        );
        api(['ok'=>true]);
    }
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
html,body{margin:0;height:100%;overflow:hidden;background:#0b1220;color:#e5e7eb;font:13px system-ui,"Noto Sans JP",sans-serif}
button,input,select,textarea{font:inherit}button{cursor:pointer}
.app{height:100%;display:flex;flex-direction:column}
.top{height:48px;display:flex;align-items:center;gap:7px;padding:6px 10px;background:#07101d;border-bottom:1px solid #334155}
.brand{font-weight:bold;margin-right:8px}
.btn{padding:6px 10px;border:1px solid #475569;border-radius:5px;background:#243247;color:#fff}
.primary{background:#2563eb}.danger{background:#7f1d1d}
.status{margin-left:auto;color:#94a3b8}.file{display:none}

.video-area{flex:1;min-height:260px;background:#03070d;display:flex;align-items:center;justify-content:center;padding:10px}
.stage{position:relative;max-width:100%;max-height:100%;line-height:0}
video{display:block;max-width:100%;max-height:100%;background:#000}
.overlay{position:absolute;inset:0}
.svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none}

.el{position:absolute;min-width:20px;min-height:16px;cursor:move;user-select:none}
.el.sel{outline:2px solid #60a5fa;outline-offset:2px}
.body{width:100%;height:100%}
.comment{display:flex;align-items:center;justify-content:center;padding:4px 7px;border:1px solid currentColor;background:#000b;border-radius:4px;line-height:1.2;text-align:center;white-space:pre-wrap;overflow:hidden}
.highlight{border:3px solid currentColor}
.skip{display:flex;align-items:center;justify-content:center;border:2px dashed #fb923c;background:#f9731626;color:#fdba74}
.zoom{border:2px solid #a78bfa;background:#8b5cf61a;color:#ddd;padding:3px;font-size:11px}

.handle{display:none;position:absolute;width:10px;height:10px;background:#fff;border:1px solid #2563eb;z-index:5}
.sel .handle{display:block}
.nw{left:-5px;top:-5px;cursor:nwse-resize}.ne{right:-5px;top:-5px;cursor:nesw-resize}
.sw{left:-5px;bottom:-5px;cursor:nesw-resize}.se{right:-5px;bottom:-5px;cursor:nwse-resize}

.line{fill:none;pointer-events:stroke;cursor:pointer}
.line.sel{filter:drop-shadow(0 0 3px #60a5fa)}

.endpoint{fill:#fff;stroke:#2563eb;stroke-width:2;cursor:crosshair;pointer-events:all}

.empty{color:#64748b;text-align:center;line-height:2}

.bottom{height:245px;background:#111b29;border-top:1px solid #334155}
.tools{height:42px;display:flex;align-items:center;gap:7px;padding:5px 8px;border-bottom:1px solid #334155}
.read{font-variant-numeric:tabular-nums}
.scroll{height:calc(100% - 42px);overflow:auto}
.timeline{position:relative;min-width:650px}
.axis{height:30px;margin-left:95px;position:relative;background:#172333;border-bottom:1px solid #334155}
.tick{position:absolute;height:100%;top:0;border-left:1px solid #334155;color:#64748b;font-size:10px;padding:5px 0 0 3px}
.row{height:45px;display:flex;border-bottom:1px solid #263445}
.label{width:95px;min-width:95px;padding:14px 8px;background:#101a27;border-right:1px solid #334155}
.lane{position:relative;flex:1}
.bar{position:absolute;top:7px;height:31px;border:1px solid currentColor;border-radius:4px;overflow:hidden;white-space:nowrap;cursor:grab}
.bar.sel{box-shadow:0 0 0 2px #60a5fa}
.bar span{padding:0 5px;font-size:11px}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:#ef4444;pointer-events:none}

.ctx{position:fixed;z-index:100;display:none;min-width:190px;padding:4px;background:#172235;border:1px solid #475569;border-radius:6px;box-shadow:0 10px 30px #0008}
.ctx button{display:block;width:100%;padding:7px;border:0;background:none;color:#fff;text-align:left}
.ctx button:hover{background:#293a52}
.ctx hr{border:0;border-top:1px solid #334155}

.modal{position:fixed;inset:0;z-index:200;display:none;align-items:center;justify-content:center;padding:20px;background:#0009}
.box{width:min(500px,100%);background:#182231;border:1px solid #475569;border-radius:7px}
.head,.foot{padding:10px 13px;border-bottom:1px solid #334155;display:flex;justify-content:space-between}
.foot{border:0;border-top:1px solid #334155;justify-content:flex-end;gap:6px}
.form{padding:13px;display:grid;gap:9px;max-height:75vh;overflow:auto}
.field{display:grid;gap:4px}
.field label{font-size:12px;color:#94a3b8}
.field input,.field textarea,.field select{width:100%;padding:6px;background:#0d1623;border:1px solid #475569;color:#fff;border-radius:4px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.hide{display:none}
.list div{display:flex;gap:7px;align-items:center;padding:9px;border-bottom:1px solid #334155}
.list span{flex:1}.list small{color:#94a3b8}
.toast{position:fixed;right:12px;bottom:12px;z-index:300;display:none;padding:8px 12px;background:#1e293b;border:1px solid #475569;border-radius:5px}
</style>
</head>

<body>
<div class="app">

<header class="top">
    <b class="brand">動画注釈編集</b>
    <button class="btn primary" id="openVideo">動画を開く</button>
    <input class="file" id="videoFile" type="file" accept="video/*">
    <button class="btn" id="newBtn">新規</button>
    <button class="btn" id="saveBtn">保存</button>
    <button class="btn" id="openBtn">開く</button>
    <span class="status" id="status">動画を開いてください</span>
</header>

<main class="main">

<section class="video-area">
<div class="stage" id="stage">
    <video id="video" playsinline preload="metadata"></video>
    <div class="overlay" id="overlay">
        <svg class="svg" id="svg"></svg>
    </div>
    <div class="empty" id="empty">
        動画を開いてください<br>
        動画上で右クリックして要素を追加できます
    </div>
</div>
</section>

<section class="bottom">
<div class="tools">
    <button class="btn" id="play">▶</button>
    <button class="btn" id="stop">■</button>
    <span class="read" id="read">00:00.000 / 00:00.000</span>
    <span id="selection"></span>
    <span style="margin-left:auto">
        時間幅
        <input id="timeScale" type="range" min="1" max="5" step=".1" value="1">
    </span>
</div>

<div class="scroll">
<div class="timeline" id="timeline">
    <div class="axis" id="axis"></div>
    <div id="rows"></div>
    <div class="playhead" id="playhead"></div>
</div>
</div>
</section>

</main>
</div>

<!-- 右クリックメニュー -->
<div class="ctx" id="ctx">
    <button data-add="comment">＋ コメント</button>
    <button data-add="highlight">＋ 強調枠</button>
    <button data-add="skip">＋ スキップ</button>
    <button data-add="zoom">＋ 拡大要素</button>
    <hr>
    <button id="connectBtn">選択要素を接続</button>
    <button id="lineEditBtn">接続線を編集</button>
    <button id="disconnectBtn">接続を解除</button>
    <hr>
    <button id="elementEditBtn">要素を編集</button>
    <button id="elementDeleteBtn">削除</button>
</div>

<!-- 要素編集 -->
<div class="modal" id="elementModal">
<div class="box">
<div class="head">
    <b>要素編集</b>
    <button class="btn" data-close="elementModal">閉じる</button>
</div>

<div class="form">
    <div class="field">
        <label>名前</label>
        <input id="eName">
    </div>

    <div class="field" id="textField">
        <label>コメント</label>
        <textarea id="eText"></textarea>
    </div>

    <div class="two">
        <div class="field">
            <label>開始</label>
            <input id="eStart" type="number" step=".001">
        </div>
        <div class="field">
            <label>終了</label>
            <input id="eEnd" type="number" step=".001">
        </div>
    </div>

    <div class="two">
        <div class="field">
            <label>左位置 (%)</label>
            <input id="eX" type="number" step=".1">
        </div>
        <div class="field">
            <label>上位置 (%)</label>
            <input id="eY" type="number" step=".1">
        </div>
    </div>

    <div class="two">
        <div class="field">
            <label>幅 (%)</label>
            <input id="eW" type="number" step=".1">
        </div>
        <div class="field">
            <label>高さ (%)</label>
            <input id="eH" type="number" step=".1">
        </div>
    </div>

    <div class="field" id="fontField">
        <label>文字サイズ</label>
        <input id="eFont" type="number" min="8" max="100">
    </div>
</div>

<div class="foot">
    <button class="btn danger" id="deleteElement">削除</button>
    <button class="btn" data-close="elementModal">キャンセル</button>
    <button class="btn primary" id="saveElement">保存</button>
</div>
</div>
</div>

<!-- 接続線編集 -->
<div class="modal" id="lineModal">
<div class="box">
<div class="head">
    <b>接続線の属性</b>
    <button class="btn" data-close="lineModal">閉じる</button>
</div>

<div class="form">
    <div class="two">
        <div class="field">
            <label>始点</label>
            <select id="fromPoint">
                <option value="top">上</option>
                <option value="right">右</option>
                <option value="bottom">下</option>
                <option value="left">左</option>
            </select>
        </div>
        <div class="field">
            <label>終点</label>
            <select id="toPoint">
                <option value="top">上</option>
                <option value="right">右</option>
                <option value="bottom">下</option>
                <option value="left">左</option>
            </select>
        </div>
    </div>

    <div class="two">
        <div class="field">
            <label>線の太さ</label>
            <input id="lineWidth" type="number" min="1" max="12" step=".5">
        </div>
        <div class="field">
            <label>線種</label>
            <select id="lineStyle">
                <option value="solid">実線</option>
                <option value="dashed">破線</option>
                <option value="dotted">点線</option>
            </select>
        </div>
    </div>

    <div class="two">
        <div class="field">
            <label>矢印</label>
            <select id="arrow">
                <option value="none">なし</option>
                <option value="end">終点</option>
                <option value="start">始点</option>
                <option value="both">両端</option>
            </select>
        </div>
        <div class="field">
            <label>色</label>
            <input id="lineColor" type="color">
        </div>
    </div>
</div>

<div class="foot">
    <button class="btn danger" id="deleteLine">接続解除</button>
    <button class="btn" data-close="lineModal">キャンセル</button>
    <button class="btn primary" id="saveLine">保存</button>
</div>
</div>
</div>

<!-- 保存プロジェクト -->
<div class="modal" id="projectModal">
<div class="box">
<div class="head">
    <b>保存したプロジェクト</b>
    <button class="btn" data-close="projectModal">閉じる</button>
</div>
<div class="form list" id="projectList"></div>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $ = id => document.getElementById(id);

const V = {
    video:$('video'),
    stage:$('stage'),
    overlay:$('overlay'),
    svg:$('svg')
};

const A = {
    project:null,
    duration:0,
    time:0,
    url:'',
    selected:new Set(),
    line:null,
    editElement:null,
    editLine:null,
    drag:null
};

const uid = () =>
    crypto.randomUUID?.() ||
    Date.now()+'-'+Math.random().toString(36).slice(2);

const element = id =>
    A.project?.elements.find(e => e.id === id);

const connection = id =>
    A.project?.connections.find(c => c.id === id);

const clamp = (n,min,max) =>
    Math.max(min,Math.min(max,n));

const fmt = n => {
    n = Math.max(0,+n||0);
    return String(Math.floor(n/60)).padStart(2,'0') +
        ':' +
        String(Math.floor(n%60)).padStart(2,'0') +
        '.' +
        String(Math.floor(n%1*1000)).padStart(3,'0');
};

function message(text) {
    $('status').textContent = text;
    $('toast').textContent = text;
    $('toast').style.display = 'block';
    clearTimeout(message.timer);
    message.timer = setTimeout(
        () => $('toast').style.display='none',1800
    );
}

function dirty() {
    message('変更あり');
}

function clean() {
    $('status').textContent =
        A.project ? '編集中：'+A.project.name : '動画を開いてください';
}

function newProject(name) {
    return {
        version:1,
        name:name,
        videoName:name,
        duration:A.duration,
        elements:[],
        connections:[]
    };
}

/* ---------- 時間軸 ---------- */

function timeRange() {
    const z = +$('timeScale').value;
    const span = A.duration / z;
    const start = clamp(
        A.time-span/2,
        0,
        Math.max(0,A.duration-span)
    );
    return [start,start+span];
}

function timeX(t) {
    const [s,e] = timeRange();
    return e===s ? 0 : (t-s)/(e-s)*100;
}

function xTime(x) {
    const r = $('axis').getBoundingClientRect();
    const [s,e] = timeRange();
    return s+(x-r.left)/r.width*(e-s);
}

/* ---------- 描画 ---------- */

function render() {
    renderTimeline();
    renderElements();
    renderConnections();

    $('read').textContent =
        fmt(A.time)+' / '+fmt(A.duration);

    $('selection').textContent =
        A.selected.size ? A.selected.size+'個選択' :
        A.line ? '接続線を選択' : '';

    $('playhead').style.left =
        `calc(95px + (100% - 95px) * ${timeX(A.time)/100})`;
}

function renderTimeline() {
    $('axis').innerHTML='';
    $('rows').innerHTML='';

    if (!A.duration) return;

    const [s,e] = timeRange();
    const steps = [.2,.5,1,2,5,10,30,60,120];
    const step = steps.find(v=>v>=(e-s)/7) || 300;

    for (
        let t=Math.ceil(s/step)*step;
        t<=e;
        t+=step
    ) {
        const n=document.createElement('i');
        n.className='tick';
        n.style.left=timeX(t)+'%';
        n.textContent=fmt(t);
        $('axis').append(n);
    }

    [
        ['comment','コメント'],
        ['highlight','強調枠'],
        ['skip','スキップ'],
        ['zoom','拡大']
    ].forEach(([type,label])=>{
        const row=document.createElement('div');
        row.className='row';

        const labelBox=document.createElement('div');
        labelBox.className='label';
        labelBox.textContent=label;

        const lane=document.createElement('div');
        lane.className='lane';

        (A.project?.elements||[])
            .filter(e=>e.type===type)
            .forEach(e=>{
                const bar=document.createElement('div');
                bar.className='bar'+
                    (A.selected.has(e.id)?' sel':'');
                bar.dataset.id=e.id;
                bar.style.left=timeX(e.start)+'%';
                bar.style.width=
                    Math.max(.5,timeX(e.end)-timeX(e.start))+'%';
                bar.style.color=e.color;
                bar.style.background=e.color+'33';

                const span=document.createElement('span');
                span.textContent=e.name;
                bar.append(span);

                bar.onpointerdown=ev=>{
                    timelineDrag(ev,e.id);
                };

                bar.ondblclick=()=>{
                    openElement(e.id);
                };

                lane.append(bar);
            });

        row.append(labelBox,lane);
        $('rows').append(row);
    });
}

/* ---------- 動画上の要素 ---------- */

function renderElements() {
    V.overlay.querySelectorAll('.el').forEach(n=>n.remove());

    if (!A.project) return;

    A.project.elements
        .filter(e=>A.time>=e.start && A.time<e.end)
        .forEach(e=>{
            const n=document.createElement('div');

            n.className='el'+
                (A.selected.has(e.id)?' sel':'');
            n.dataset.id=e.id;

            Object.assign(n.style,{
                left:e.x+'%',
                top:e.y+'%',
                width:e.w+'%',
                height:e.h+'%',
                color:e.color
            });

            const body=document.createElement('div');

            body.className='body '+
                ({
                    comment:'comment',
                    highlight:'highlight',
                    skip:'skip',
                    zoom:'zoom'
                }[e.type]);

            if(e.type==='comment') {
                body.textContent=e.text||e.name;
                body.style.fontSize=e.font+'px';
            }

            if(e.type==='skip') body.textContent='スキップ';
            if(e.type==='zoom') body.textContent='拡大';

            n.append(body);

            if(A.selected.has(e.id)) {
                ['nw','ne','sw','se'].forEach(p=>{
                    const h=document.createElement('i');
                    h.className='handle '+p;
                    h.dataset.resize=p;
                    n.append(h);
                });
            }

            n.onpointerdown=ev=>{
                elementDrag(ev,e.id);
            };

            n.ondblclick=ev=>{
                ev.stopPropagation();
                openElement(e.id);
            };

            n.oncontextmenu=ev=>{
                ev.preventDefault();
                selectElement(e.id,false);
                openMenu(ev.clientX,ev.clientY);
            };

            V.overlay.append(n);
        });
}

/* ---------- 接続線 ---------- */

function point(rect,side,base) {
    const x=rect.left-base.left;
    const y=rect.top-base.top;
    const w=rect.width;
    const h=rect.height;

    return {
        top:[x+w/2,y],
        right:[x+w,y+h/2],
        bottom:[x+w/2,y+h],
        left:[x,y+h/2]
    }[side];
}

function renderConnections() {
    V.svg.innerHTML='';

    if(!A.project) return;

    const base=V.overlay.getBoundingClientRect();

    A.project.connections.forEach(c=>{
        const a=V.overlay.querySelector(
            `[data-id="${CSS.escape(c.from)}"]`
        );
        const b=V.overlay.querySelector(
            `[data-id="${CSS.escape(c.to)}"]`
        );

        if(!a||!b) return;

        const p1=point(
            a.getBoundingClientRect(),
            c.fromPoint,
            base
        );

        const p2=point(
            b.getBoundingClientRect(),
            c.toPoint,
            base
        );

        const path=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        path.classList.add('line');

        if(A.line===c.id)
            path.classList.add('sel');

        path.setAttribute(
            'd',
            `M${p1[0]} ${p1[1]}
             L${(p1[0]+p2[0])/2} ${p1[1]}
             L${(p1[0]+p2[0])/2} ${p2[1]}
             L${p2[0]} ${p2[1]}`
        );

        path.style.stroke=c.color;
        path.style.strokeWidth=c.width;
        path.style.strokeDasharray=
            c.style==='dashed'?'8 5':
            c.style==='dotted'?'2 5':'none';

        path.onclick=ev=>{
            ev.stopPropagation();
            A.line=c.id;
            A.selected.clear();
            render();
        };

        path.oncontextmenu=ev=>{
            ev.preventDefault();
            A.line=c.id;
            A.selected.clear();
            openMenu(ev.clientX,ev.clientY);
        };

        V.svg.append(path);

        /* 接続線の両端 */
        [
            [p1,c.from,'from'],
            [p2,c.to,'to']
        ].forEach(([p,elementId,side])=>{
            const circle=document.createElementNS(
                'http://www.w3.org/2000/svg',
                'circle'
            );

            circle.classList.add('endpoint');
            circle.setAttribute('cx',p[0]);
            circle.setAttribute('cy',p[1]);
            circle.setAttribute('r','5');

            circle.onpointerdown=ev=>{
                ev.stopPropagation();
                connectionEndpointDrag(
                    ev,c.id,side
                );
            };

            V.svg.append(circle);
        });
    });
}

/* ---------- 選択 ---------- */

function selectElement(id,multi) {
    A.line=null;

    if(!multi)
        A.selected.clear();

    if(multi && A.selected.has(id))
        A.selected.delete(id);
    else
        A.selected.add(id);

    render();
}

/* ---------- 右クリック ---------- */

function openMenu(x,y) {
    const menu=$('ctx');

    menu.style.display='block';
    menu.style.left=Math.min(
        x,innerWidth-205
    )+'px';
    menu.style.top=Math.min(
        y,innerHeight-300
    )+'px';

    $('connectBtn').disabled=
        A.selected.size!==2;

    $('lineEditBtn').disabled=
        !A.line;

    $('disconnectBtn').disabled=
        !A.line;
}

function closeMenu() {
    $('ctx').style.display='none';
}

/* ---------- 要素追加 ---------- */

function addElement(type) {
    if(!A.project || !A.duration) {
        message('先に動画を開いてください');
        return;
    }

    const colors={
        comment:'#60a5fa',
        highlight:'#22c55e',
        skip:'#f97316',
        zoom:'#a78bfa'
    };

    const names={
        comment:'コメント',
        highlight:'強調枠',
        skip:'スキップ',
        zoom:'拡大'
    };

    const e={
        id:uid(),
        type,
        name:names[type],
        text:'コメント',
        start:A.time,
        end:Math.min(A.duration,A.time+3),
        x:10,
        y:10,
        w:type==='comment'?30:25,
        h:type==='comment'?15:20,
        color:colors[type],
        font:26
    };

    A.project.elements.push(e);
    A.selected=new Set([e.id]);

    closeMenu();
    dirty();
    render();
    openElement(e.id);
}

/* ---------- 要素ドラッグ ---------- */

function elementDrag(ev,id) {
    if(ev.button!==0) return;

    ev.preventDefault();
    ev.stopPropagation();

    selectElement(
        id,
        ev.ctrlKey || ev.metaKey
    );

    const e=element(id);
    const r=V.overlay.getBoundingClientRect();

    A.drag={
        id,
        sx:ev.clientX,
        sy:ev.clientY,
        x:e.x,
        y:e.y,
        w:e.w,
        h:e.h,
        rw:r.width,
        rh:r.height,
        resize:ev.target.dataset.resize||''
    };

    addEventListener('pointermove',moveElement);
    addEventListener('pointerup',endDrag,{once:true});
}

function moveElement(ev) {
    const d=A.drag;
    const e=element(d?.id);

    if(!d||!e) return;

    const dx=(ev.clientX-d.sx)/d.rw*100;
    const dy=(ev.clientY-d.sy)/d.rh*100;

    if(!d.resize) {
        e.x=clamp(d.x+dx,0,100-d.w);
        e.y=clamp(d.y+dy,0,100-d.h);
    } else {
        if(d.resize.includes('e'))
            e.w=clamp(d.w+dx,1,100-d.x);

        if(d.resize.includes('s'))
            e.h=clamp(d.h+dy,1,100-d.y);

        if(d.resize.includes('w')) {
            const x=clamp(d.x+dx,0,d.x+d.w-1);
            e.w=d.w+d.x-x;
            e.x=x;
        }

        if(d.resize.includes('n')) {
            const y=clamp(d.y+dy,0,d.y+d.h-1);
            e.h=d.h+d.y-y;
            e.y=y;
        }
    }

    dirty();
    render();
}

function endDrag() {
    A.drag=null;
    removeEventListener('pointermove',moveElement);
    removeEventListener('pointermove',moveTimeline);
}

/* ---------- タイムラインドラッグ ---------- */

function timelineDrag(ev,id) {
    ev.preventDefault();
    ev.stopPropagation();

    selectElement(
        id,
        ev.ctrlKey || ev.metaKey
    );

    const e=element(id);
    const lane=ev.currentTarget.parentElement;
    const r=lane.getBoundingClientRect();
    const [s,en]=timeRange();

    A.drag={
        id,
        sx:ev.clientX,
        start:e.start,
        end:e.end,
        width:r.width,
        span:en-s
    };

    addEventListener('pointermove',moveTimeline);
    addEventListener('pointerup',endDrag,{once:true});
}

function moveTimeline(ev) {
    const d=A.drag;
    const e=element(d?.id);

    if(!d||!e) return;

    const dt=
        (ev.clientX-d.sx)/
        d.width*
        d.span;

    const len=d.end-d.start;

    e.start=clamp(
        d.start+dt,
        0,
        A.duration-len
    );

    e.end=e.start+len;

    dirty();
    render();
}

/* ---------- 接続 ---------- */

function createConnection() {
    if(A.selected.size!==2) {
        message('2つの要素を選択してください');
        return;
    }

    const [a,b]=[...A.selected];

    const c={
        id:uid(),
        from:a,
        to:b,
        fromPoint:'right',
        toPoint:'left',
        color:'#facc15',
        width:2,
        style:'solid',
        arrow:'end'
    };

    A.project.connections.push(c);
    A.line=c.id;
    A.selected.clear();

    closeMenu();
    dirty();
    render();
}

/* 接続線の端をドラッグして接点変更 */
function connectionEndpointDrag(ev,id,side) {
    const c=connection(id);
    if(!c) return;

    const move=e=>{
        const r=V.overlay.getBoundingClientRect();
        const x=e.clientX-r.left;
        const y=e.clientY-r.top;

        const target=
            document.elementFromPoint(
                e.clientX,e.clientY
            )?.closest('.el');

        if(target) {
            const targetId=target.dataset.id;

            if(targetId!==c.from && targetId!==c.to) {
                if(side==='from')
                    c.from=targetId;
                else
                    c.to=targetId;
            }
        }

        const el=
            document.elementFromPoint(
                e.clientX,e.clientY
            )?.closest('.el');

        if(el) {
            const er=el.getBoundingClientRect();
            const cx=er.left+er.width/2;
            const cy=er.top+er.height/2;

            const dx=e.clientX-cx;
            const dy=e.clientY-cy;

            let p;

            if(Math.abs(dx)>Math.abs(dy))
                p=dx<0?'left':'right';
            else
                p=dy<0?'top':'bottom';

            if(side==='from')
                c.fromPoint=p;
            else
                c.toPoint=p;

            dirty();
            render();
        }
    };

    const up=()=>{
        removeEventListener('pointermove',move);
        removeEventListener('pointerup',up);
        render();
    };

    addEventListener('pointermove',move);
    addEventListener('pointerup',up);
}

/* ---------- 要素編集 ---------- */

function openElement(id) {
    const e=element(id);
    if(!e) return;

    A.editElement=id;

    $('eName').value=e.name;
    $('eText').value=e.text||'';
    $('eStart').value=e.start;
    $('eEnd').value=e.end;
    $('eX').value=e.x;
    $('eY').value=e.y;
    $('eW').value=e.w;
    $('eH').value=e.h;
    $('eFont').value=e.font||26;

    $('textField').classList.toggle(
        'hide',e.type!=='comment'
    );

    $('fontField').classList.toggle(
        'hide',e.type!=='comment'
    );

    $('elementModal').style.display='flex';
}

function saveElement() {
    const e=element(A.editElement);

    const start=+$('eStart').value;
    const end=+$('eEnd').value;

    if(
        !e ||
        start<0 ||
        end<=start ||
        end>A.duration
    ) {
        message('時間範囲が不正です');
        return;
    }

    Object.assign(e,{
        name:$('eName').value.trim()||'要素',
        text:$('eText').value,
        start,
        end,
        x:clamp(+$('eX').value,0,99),
        y:clamp(+$('eY').value,0,99),
        w:clamp(+$('eW').value,1,100),
        h:clamp(+$('eH').value,1,100),
        font:clamp(+$('eFont').value||26,8,100)
    });

    $('elementModal').style.display='none';
    dirty();
    render();
}

/* ---------- 接続線属性 ---------- */

function openLine() {
    const c=connection(A.line);

    if(!c) {
        message('接続線を選択してください');
        return;
    }

    A.editLine=c.id;

    $('fromPoint').value=c.fromPoint;
    $('toPoint').value=c.toPoint;
    $('lineWidth').value=c.width;
    $('lineStyle').value=c.style;
    $('arrow').value=c.arrow;
    $('lineColor').value=c.color;

    $('lineModal').style.display='flex';
}

function saveLine() {
    const c=connection(A.editLine);
    if(!c) return;

    Object.assign(c,{
        fromPoint:$('fromPoint').value,
        toPoint:$('toPoint').value,
        width:+$('lineWidth').value,
        style:$('lineStyle').value,
        arrow:$('arrow').value,
        color:$('lineColor').value
    });

    $('lineModal').style.display='none';
    dirty();
    render();
}

/* ---------- 削除 ---------- */

function deleteSelected() {
    if(!A.project) return;

    const ids=A.selected;

    A.project.elements=
        A.project.elements.filter(
            e=>!ids.has(e.id)
        );

    A.project.connections=
        A.project.connections.filter(
            c=>!ids.has(c.from)&&!ids.has(c.to)
        );

    A.selected.clear();
    A.line=null;

    document.querySelectorAll('.modal')
        .forEach(x=>x.style.display='none');

    dirty();
    render();
}

function deleteLine() {
    if(!A.line) return;

    A.project.connections=
        A.project.connections.filter(
            c=>c.id!==A.line
        );

    A.line=null;
    $('lineModal').style.display='none';

    dirty();
    render();
}

/* ---------- 動画 ---------- */

$('openVideo').onclick=()=>
    $('videoFile').click();

$('videoFile').onchange=ev=>{
    const f=ev.target.files?.[0];

    if(!f || !f.type.startsWith('video/')) {
        message('動画ファイルを選択してください');
        return;
    }

    if(A.url)
        URL.revokeObjectURL(A.url);

    A.url=URL.createObjectURL(f);
    A.project=newProject(f.name);
    A.time=0;
    A.selected.clear();
    A.line=null;

    V.video.src=A.url;
    V.video.load();
};

V.video.onloadedmetadata=()=>{
    A.duration=V.video.duration;
    A.project.duration=A.duration;
    $('empty').style.display='none';
    clean();
    render();
};

V.video.ontimeupdate=()=>{
    A.time=V.video.currentTime;
    render();
};

$('play').onclick=()=>{
    V.video.paused
        ? V.video.play()
        : V.video.pause();
};

$('stop').onclick=()=>{
    V.video.pause();
    A.time=0;
    V.video.currentTime=0;
    render();
};

/* ---------- 時間軸クリック ---------- */

$('timeline').onpointerdown=ev=>{
    if(ev.target.closest('.bar')) return;

    if(
        ev.target.closest('.axis') ||
        ev.target.closest('.lane')
    ) {
        A.time=clamp(
            xTime(ev.clientX),
            0,
            A.duration
        );

        V.video.currentTime=A.time;
        render();
    }
};

$('timeScale').oninput=render;

/* ---------- 動画上右クリック ---------- */

$('stage').oncontextmenu=ev=>{
    ev.preventDefault();

    if(!A.project) return;

    const r=V.video.getBoundingClientRect();

    if(
        ev.clientX>=r.left &&
        ev.clientX<=r.right &&
        ev.clientY>=r.top &&
        ev.clientY<=r.bottom
    ) {
        openMenu(ev.clientX,ev.clientY);
    }
};

/* ---------- 新規 ---------- */

$('newBtn').onclick=()=>{
    if(A.url)
        URL.revokeObjectURL(A.url);

    A.project=null;
    A.duration=0;
    A.time=0;
    A.url='';
    A.selected.clear();
    A.line=null;

    V.video.removeAttribute('src');
    V.video.load();

    $('empty').style.display='block';

    render();
    clean();
};

/* ---------- 保存 ---------- */

$('saveBtn').onclick=async()=>{
    if(!A.project) return;

    const fd=new FormData();

    fd.append('action','save');
    fd.append(
        'data',
        JSON.stringify(A.project)
    );

    try {
        const r=await fetch('',{
            method:'POST',
            body:fd
        });

        const j=await r.json();

        if(!j.ok)
            throw new Error(j.error);

        clean();
        message('保存しました');

    } catch(e) {
        message('保存できません：'+e.message);
    }
};

/* ---------- プロジェクト一覧 ---------- */

$('openBtn').onclick=showProjects;

async function showProjects() {
    const fd=new FormData();
    fd.append('action','load');

    const r=await fetch('',{
        method:'POST',
        body:fd
    });

    const j=await r.json();
    const box=$('projectList');

    box.innerHTML='';

    Object.entries(j.projects||{})
        .forEach(([name,p])=>{
            const row=document.createElement('div');

            const info=document.createElement('span');

            info.innerHTML=
                '<b>'+escapeHtml(name)+'</b>'+
                '<small> '+
                (p.elements?.length||0)+
                '要素 / '+
                (p.connections?.length||0)+
                '接続</small>';

            const open=document.createElement('button');
            open.className='btn';
            open.textContent='開く';
            open.onclick=()=>{
                loadProject(p);
            };

            const del=document.createElement('button');
            del.className='btn danger';
            del.textContent='削除';
            del.onclick=async()=>{
                if(!confirm('このプロジェクトを削除しますか？'))
                    return;

                const f=new FormData();
                f.append('action','delete');
                f.append('name',name);

                await fetch('',{
                    method:'POST',
                    body:f
                });

                showProjects();
            };

            row.append(info,open,del);
            box.append(row);
        });

    $('projectModal').style.display='flex';
}

function loadProject(p) {
    A.project=p;
    A.duration=+p.duration||0;
    A.time=0;
    A.selected.clear();
    A.line=null;

    $('projectModal').style.display='none';

    clean();
    render();
}

function escapeHtml(s) {
    return String(s??'').replace(
        /[&<>"']/g,
        c=>({
            '&':'&amp;',
            '<':'&lt;',
            '>':'&gt;',
            '"':'&quot;',
            "'":'&#39;'
        }[c])
    );
}

/* ---------- メニュー ---------- */

document.querySelectorAll('[data-add]')
    .forEach(b=>{
        b.onclick=()=>{
            addElement(b.dataset.add);
        };
    });

$('connectBtn').onclick=()=>{
    closeMenu();
    createConnection();
};

$('lineEditBtn').onclick=()=>{
    closeMenu();
    openLine();
};

$('disconnectBtn').onclick=()=>{
    closeMenu();

    if(!A.line) return;

    A.project.connections=
        A.project.connections.filter(
            c=>c.id!==A.line
        );

    A.line=null;
    dirty();
    render();
};

$('elementEditBtn').onclick=()=>{
    closeMenu();

    if(A.selected.size===1)
        openElement([...A.selected][0]);
};

$('elementDeleteBtn').onclick=()=>{
    closeMenu();
    deleteSelected();
};

$('saveElement').onclick=saveElement;
$('deleteElement').onclick=deleteSelected;
$('saveLine').onclick=saveLine;
$('deleteLine').onclick=deleteLine;

document.querySelectorAll('[data-close]')
    .forEach(b=>{
        b.onclick=()=>{
            $(b.dataset.close).style.display='none';
        };
    });

document.addEventListener('click',ev=>{
    if(!ev.target.closest('.ctx'))
        closeMenu();
});

document.addEventListener('keydown',ev=>{
    if(
        ['INPUT','TEXTAREA','SELECT']
        .includes(document.activeElement?.tagName)
    ) return;

    if(ev.key==='Delete')
        deleteSelected();

    if(ev.key===' ')
        $('play').click();

    if(ev.key==='Escape') {
        closeMenu();

        document.querySelectorAll('.modal')
            .forEach(x=>x.style.display='none');
    }
});

addEventListener('resize',render);

render();
</script>
</body>
</html>
```
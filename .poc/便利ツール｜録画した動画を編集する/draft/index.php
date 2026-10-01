<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>画面共有録画・編集</title>
<style>
:root{font-family:system-ui,"Noto Sans JP",sans-serif;color:#eee;background:#101010}
*{box-sizing:border-box}
body{margin:0}
button,input,textarea,select{font:inherit}
button{border:1px solid #555;border-radius:6px;background:#303030;color:#fff;padding:8px 11px;cursor:pointer}
button:hover:not(:disabled){background:#454545}
button:disabled{opacity:.45;cursor:not-allowed}
button.primary{background:#1769aa}
button.danger{background:#a52c2c}
button.success{background:#26733a}
input,textarea,select{accent-color:#4aa3ff}
header,.toolbar,.footer{display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:10px 14px;background:#1c1c1c;border-bottom:1px solid #333}
header{justify-content:space-between}
header h1{font-size:17px;margin:0}
#message{display:none;padding:9px 14px;background:#853232;white-space:pre-wrap}
#message.ok{background:#24643c}
.recorder{height:calc(100vh - 110px);min-height:310px;display:flex;align-items:center;justify-content:center;background:#000}
#preview{max-width:100%;max-height:100%;display:none}
#placeholder{color:#999;text-align:center;padding:20px}
.toolbar{border-top:1px solid #333;border-bottom:0}
#timer{font-variant-numeric:tabular-nums;min-width:82px}
#editor{display:none;position:fixed;inset:0;z-index:10;background:#111;flex-direction:column}
.editor-main{flex:1;min-height:0;display:grid;grid-template-columns:minmax(0,1fr) 330px}
.video-area{position:relative;min-width:0;min-height:0;display:flex;align-items:center;justify-content:center;background:#000;overflow:hidden}
#videoStage{position:relative;max-width:100%;max-height:100%;background:#000;overflow:hidden}
#recordedVideo{display:block;width:100%;height:100%}
#overlay{position:absolute;inset:0;pointer-events:none}
.edit-object{position:absolute;pointer-events:auto;cursor:move;touch-action:none;user-select:none}
.edit-object.selected{outline:2px solid white;outline-offset:2px}
.edit-object.comment{border:1px solid #fff;border-radius:6px;background:rgba(0,0,0,.86);white-space:pre-wrap;overflow:hidden;padding:7px 9px}
.edit-object.box{border:3px solid #ff453a;background:rgba(255,69,58,.08)}
.handle{position:absolute;right:-7px;bottom:-7px;width:14px;height:14px;border:1px solid #222;border-radius:50%;background:#fff;cursor:nwse-resize;touch-action:none}
#connectors{position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none}
.connector{stroke:#fff;stroke-width:2.5;pointer-events:stroke;cursor:pointer}
.connector.selected{stroke:#ffd54f;stroke-width:4}
#contextMenu{display:none;position:fixed;z-index:30;background:#292929;border:1px solid #666;border-radius:7px;padding:5px;box-shadow:0 8px 20px #0009}
#contextMenu button{display:block;width:100%;text-align:left;border:0;background:transparent}
aside{overflow:auto;background:#1b1b1b;border-left:1px solid #383838;padding:12px}
aside h2{font-size:16px;margin:0 0 8px}
section{padding-bottom:13px;margin-bottom:13px;border-bottom:1px solid #393939}
label.field{display:block;font-size:12px;color:#bbb;margin:8px 0}
.field input,.field textarea,.field select{display:block;width:100%;margin-top:4px;border:1px solid #555;border-radius:5px;background:#252525;color:#fff;padding:7px}
.field textarea{min-height:65px;resize:vertical}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 8px}
.help{font-size:12px;color:#aaa;line-height:1.5}
.list-item{border:1px solid #454545;border-radius:6px;margin:6px 0;padding:8px;font-size:12px;overflow-wrap:anywhere}
.list-item.selected{border-color:#4aa3ff}
.list-item .actions{display:flex;gap:5px;margin-top:6px}
.list-item button{padding:4px 7px;font-size:11px}
#timeline{padding:8px 13px;background:#1c1c1c;border-top:1px solid #393939}
#seek{width:100%}
#tracks{display:grid;grid-template-columns:62px 1fr;gap:5px 8px;align-items:center;font-size:11px;color:#aaa}
.track{position:relative;height:15px;background:#2c2c2c;border-radius:3px;overflow:hidden}
.track span{position:absolute;top:2px;height:11px;min-width:3px;border-radius:2px;cursor:pointer;background:#777}
.track .box{background:#e55}
.track .skip{background:#ee9b28}
.footer{justify-content:center;border-top:1px solid #393939;border-bottom:0}
#timeReadout{min-width:108px;text-align:center;font-variant-numeric:tabular-nums}
@media(max-width:760px){
.editor-main{display:flex;flex-direction:column}
.video-area{flex:1;min-height:180px}
aside{max-height:36vh;width:100%;border-left:0;border-top:1px solid #383838}
.footer button{font-size:12px;padding:6px}
}
</style>
</head>
<body>
<header><h1>画面共有録画・編集</h1><span id="status">待機中</span></header>
<div id="message" role="status"></div>

<div class="recorder">
  <video id="preview" autoplay muted playsinline></video>
  <div id="placeholder">「録画開始」を押して共有する画面を選択してください</div>
</div>
<div class="toolbar">
  <label><input id="systemAudio" type="checkbox"> 画面の音声</label>
  <label><input id="microphone" type="checkbox"> マイク</label>
  <span id="timer">00:00:00</span>
  <button id="start" class="primary">録画開始</button>
  <button id="pause" disabled>一時停止</button>
  <button id="stop" class="danger" disabled>停止</button>
  <button id="reset" disabled>リセット</button>
  <button id="openEditor" disabled>編集画面を開く</button>
</div>

<div id="editor">
  <div class="editor-main">
    <div class="video-area" id="videoArea">
      <div id="videoStage">
        <video id="recordedVideo" controls playsinline></video>
        <div id="overlay">
          <svg id="connectors" preserveAspectRatio="none"></svg>
          <div id="objects"></div>
        </div>
      </div>
    </div>

    <aside>
      <section>
        <h2>動画編集</h2>
        <div class="help">動画上を右クリックして追加できます。要素を上下左右にドラッグでき、右下の丸でサイズ変更できます。接続は「線でつなぐ」を押してから、2つの要素を順にクリックしてください。</div>
        <button id="addComment" class="primary">コメントを追加</button>
        <button id="addBox" class="primary">強調枠を追加</button>
        <button id="connect">線でつなぐ</button>
        <button id="deleteSelected">選択項目を削除</button>
      </section>

      <section>
        <h2>選択中の要素</h2>
        <div id="selectionHint" class="help">コメントまたは強調枠を選択してください。</div>
        <div id="objectFields" hidden>
          <label id="textField" class="field">コメント<textarea id="objectText"></textarea></label>
          <div class="grid">
            <label class="field">開始（秒）<input id="objectStart" type="number" min="0" step="0.1"></label>
            <label class="field">終了（秒）<input id="objectEnd" type="number" min="0" step="0.1"></label>
            <label class="field">左（%）<input id="objectX" type="number" min="0" max="100" step="0.1"></label>
            <label class="field">上（%）<input id="objectY" type="number" min="0" max="100" step="0.1"></label>
            <label class="field">幅（%）<input id="objectW" type="number" min="1" max="100" step="0.1"></label>
            <label class="field">高さ（%）<input id="objectH" type="number" min="1" max="100" step="0.1"></label>
          </div>
          <button id="applyObject" class="primary">変更を反映</button>
        </div>
      </section>

      <section>
        <h2>再生をスキップ</h2>
        <div class="grid">
          <label class="field">開始（秒）<input id="skipStart" type="number" min="0" step="0.1" value="0"></label>
          <label class="field">終了（秒）<input id="skipEnd" type="number" min="0" step="0.1" value="5"></label>
        </div>
        <button id="skipFromCurrent">現在位置を入力</button>
        <button id="addSkip" class="primary">範囲を追加</button>
        <div id="skipList"></div>
      </section>

      <section>
        <h2>コメント・強調枠</h2>
        <div id="objectList"></div>
      </section>

      <section>
        <h2>編集データ</h2>
        <button id="saveProject">編集内容を保存</button>
        <button id="loadProject">編集内容を読み込む</button>
        <input id="projectFile" type="file" accept="application/json,.json" hidden>
        <button id="clearEdits" class="danger">編集内容をすべて削除</button>
        <div class="help">保存するJSONには編集内容のみが含まれます。録画動画は含まれません。編集内容はこのブラウザにも自動保存されます。</div>
      </section>
    </aside>
  </div>

  <div id="timeline">
    <input id="seek" type="range" min="0" max="0" step="0.01" value="0">
    <div id="tracks">
      <span>コメント</span><div class="track" id="commentTrack"></div>
      <span>強調枠</span><div class="track" id="boxTrack"></div>
      <span>スキップ</span><div class="track" id="skipTrack"></div>
    </div>
  </div>
  <div class="footer">
    <span id="timeReadout">00:00 / 00:00</span>
    <button id="back5">5秒戻る</button>
    <button id="forward5">5秒進む</button>
    <button id="downloadWebm">WebMをダウンロード</button>
    <button id="downloadMp4" class="success">MP4に変換してダウンロード</button>
    <button id="closeEditor">閉じる</button>
  </div>
</div>

<div id="contextMenu">
  <button data-action="comment">コメントを追加</button>
  <button data-action="box">強調枠を追加</button>
  <button data-action="connect">線でつなぐ</button>
</div>

<script src="https://cdn.jsdelivr.net/npm/@ffmpeg/ffmpeg@0.12.10/dist/umd/ffmpeg.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@ffmpeg/util@0.12.1/dist/umd/index.js"></script>
<script>
"use strict";

const $ = id => document.getElementById(id);
const video = $("recordedVideo");
const stage = $("videoStage");
const STORAGE_KEY = "screen-recorder-edits-v1";

const state = {
  displayStream: null,
  microphoneStream: null,
  audioContext: null,
  recorder: null,
  chunks: [],
  blob: null,
  url: null,
  startedAt: 0,
  elapsedBeforePause: 0,
  timerId: null,
  items: [],
  skips: [],
  connectors: [],
  selected: null,
  connectFrom: null,
  connectMode: false,
  contextPoint: {x: 15, y: 15},
  ffmpeg: null,
  converting: false,
  lastSkipAt: -1
};

function notify(message, ok = false) {
  const box = $("message");
  box.textContent = message;
  box.classList.toggle("ok", ok);
  box.style.display = "block";
  clearTimeout(notify.timer);
  notify.timer = setTimeout(() => box.style.display = "none", 5500);
}
function formatTime(seconds, hours = false) {
  seconds = Math.max(0, Math.floor(Number(seconds) || 0));
  const h = Math.floor(seconds / 3600);
  const m = Math.floor(seconds % 3600 / 60);
  const s = seconds % 60;
  return hours
    ? [h, m, s].map(n => String(n).padStart(2, "0")).join(":")
    : [Math.floor(seconds / 60), s].map(n => String(n).padStart(2, "0")).join(":");
}
function duration() {
  return Number.isFinite(video.duration) ? video.duration : 0;
}
function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}
function validRange(start, end) {
  start = Number(start);
  end = Number(end);
  const max = duration();
  if (!Number.isFinite(start) || !Number.isFinite(end) ||
      start < 0 || end <= start || (max && start >= max)) return null;
  return {start, end: max ? Math.min(end, max) : end};
}
function id() {
  return crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random();
}
function download(blob, name) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = name;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60000);
}
function fileStamp() {
  const d = new Date();
  return [
    d.getFullYear(),
    String(d.getMonth() + 1).padStart(2, "0"),
    String(d.getDate()).padStart(2, "0"),
    "-",
    String(d.getHours()).padStart(2, "0"),
    String(d.getMinutes()).padStart(2, "0"),
    String(d.getSeconds()).padStart(2, "0")
  ].join("");
}

function stopStreams() {
  [state.displayStream, state.microphoneStream].forEach(stream =>
    stream?.getTracks().forEach(track => track.stop())
  );
  state.displayStream = null;
  state.microphoneStream = null;
  $("preview").srcObject = null;
  if (state.audioContext) {
    state.audioContext.close().catch(() => {});
    state.audioContext = null;
  }
}
function updateRecordingButtons(recording = false) {
  $("start").disabled = recording;
  $("pause").disabled = !recording;
  $("stop").disabled = !recording;
  $("reset").disabled = recording || !state.blob;
  $("openEditor").disabled = recording || !state.blob;
}
function updateTimer() {
  const active = state.startedAt ? Date.now() - state.startedAt : 0;
  $("timer").textContent = formatTime((state.elapsedBeforePause + active) / 1000, true);
}
function beginTimer() {
  state.startedAt = Date.now();
  clearInterval(state.timerId);
  state.timerId = setInterval(updateTimer, 250);
}
function freezeTimer() {
  if (state.startedAt) state.elapsedBeforePause += Date.now() - state.startedAt;
  state.startedAt = 0;
  clearInterval(state.timerId);
  state.timerId = null;
  updateTimer();
}
function recordingMime() {
  return ["video/webm;codecs=vp9,opus", "video/webm;codecs=vp8,opus",
    "video/webm;codecs=vp9", "video/webm;codecs=vp8", "video/webm"]
    .find(type => MediaRecorder.isTypeSupported(type));
}
async function recordingStream() {
  const stream = new MediaStream(state.displayStream.getVideoTracks());
  const audioTracks = [
    ...($("systemAudio").checked ? state.displayStream.getAudioTracks() : []),
    ...(state.microphoneStream?.getAudioTracks() || [])
  ];
  if (audioTracks.length === 1) {
    stream.addTrack(audioTracks[0]);
  } else if (audioTracks.length > 1) {
    const context = new AudioContext();
    state.audioContext = context;
    const destination = context.createMediaStreamDestination();
    for (const track of audioTracks) {
      const source = context.createMediaStreamSource(new MediaStream([track]));
      source.connect(destination);
    }
    stream.addTrack(destination.stream.getAudioTracks()[0]);
    await context.resume();
  }
  return stream;
}
$("start").onclick = async () => {
  try {
    if (!navigator.mediaDevices?.getDisplayMedia || !window.MediaRecorder) {
      throw new Error("このブラウザ、または現在の接続では画面録画を利用できません。HTTPSまたはlocalhostで開いてください。");
    }
    const mimeType = recordingMime();
    if (!mimeType) throw new Error("このブラウザはWebM録画に対応していません。");

    $("start").disabled = true;
    state.displayStream = await navigator.mediaDevices.getDisplayMedia({
      video: true,
      audio: $("systemAudio").checked
    });
    if ($("microphone").checked) {
      state.microphoneStream = await navigator.mediaDevices.getUserMedia({audio: true});
    }
    const stream = await recordingStream();
    state.chunks = [];
    state.blob = null;
    state.elapsedBeforePause = 0;
    state.recorder = new MediaRecorder(stream, {mimeType});
    state.recorder.ondataavailable = event => {
      if (event.data?.size) state.chunks.push(event.data);
    };
    state.recorder.onstop = () => {
      freezeTimer();
      stopStreams();
      if (!state.chunks.length) {
        notify("録画データがありません。");
        updateRecordingButtons();
        return;
      }
      state.blob = new Blob(state.chunks, {type: state.recorder.mimeType || "video/webm"});
      if (state.url) URL.revokeObjectURL(state.url);
      state.url = URL.createObjectURL(state.blob);
      video.src = state.url;
      video.load();
      $("status").textContent = "録画終了";
      updateRecordingButtons();
      $("editor").style.display = "flex";
    };
    state.recorder.onerror = () => {
      notify("録画中にエラーが発生しました。");
      stopRecording();
    };
    state.displayStream.getVideoTracks()[0].addEventListener("ended", stopRecording, {once: true});
    $("preview").srcObject = state.displayStream;
    $("preview").style.display = "block";
    $("placeholder").style.display = "none";
    state.recorder.start(1000);
    beginTimer();
    $("status").textContent = "録画中";
    updateRecordingButtons(true);
  } catch (error) {
    stopStreams();
    state.recorder = null;
    $("start").disabled = false;
    notify(error.message || "録画を開始できませんでした。");
  }
};
function stopRecording() {
  if (!state.recorder || state.recorder.state === "inactive") return;
  state.recorder.stop();
  freezeTimer();
  $("pause").disabled = true;
  $("stop").disabled = true;
  $("status").textContent = "録画を保存中";
}
$("stop").onclick = stopRecording;
$("pause").onclick = () => {
  if (!state.recorder) return;
  if (state.recorder.state === "recording") {
    state.recorder.pause();
    freezeTimer();
    $("pause").textContent = "再開";
    $("status").textContent = "一時停止中";
  } else if (state.recorder.state === "paused") {
    state.recorder.resume();
    beginTimer();
    $("pause").textContent = "一時停止";
    $("status").textContent = "録画中";
  }
};
$("reset").onclick = () => {
  if (!confirm("現在の録画を削除して新しく録画しますか？")) return;
  video.pause();
  if (state.url) URL.revokeObjectURL(state.url);
  state.url = null;
  state.blob = null;
  state.chunks = [];
  state.recorder = null;
  video.removeAttribute("src");
  video.load();
  $("preview").style.display = "none";
  $("placeholder").style.display = "block";
  $("editor").style.display = "none";
  $("timer").textContent = "00:00:00";
  $("pause").textContent = "一時停止";
  $("status").textContent = "待機中";
  state.items = [];
  state.skips = [];
  state.connectors = [];
  state.selected = null;
  state.connectMode = false;
  localStorage.removeItem(STORAGE_KEY);
  updateRecordingButtons();
  render();
};
$("openEditor").onclick = () => $("editor").style.display = "flex";
$("closeEditor").onclick = () => {
  video.pause();
  $("editor").style.display = "none";
};
$("downloadWebm").onclick = () => {
  if (!state.blob) return notify("録画データがありません。");
  download(state.blob, `screen-recording-${fileStamp()}.webm`);
};

async function getFFmpeg() {
  if (state.ffmpeg) return state.ffmpeg;
  if (!window.FFmpeg?.FFmpeg || !window.FFmpegUtil?.toBlobURL) {
    throw new Error("変換用ライブラリを読み込めません。インターネット接続を確認してください。");
  }
  const ffmpeg = new window.FFmpeg.FFmpeg();
  const toBlobURL = window.FFmpegUtil.toBlobURL;
  const base = "https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.6/dist/umd";
  ffmpeg.on("progress", ({progress}) => {
    $("status").textContent = `MP4変換中 ${Math.round(clamp(progress, 0, 1) * 100)}%`;
  });
  await ffmpeg.load({
    coreURL: await toBlobURL(`${base}/ffmpeg-core.js`, "text/javascript"),
    wasmURL: await toBlobURL(`${base}/ffmpeg-core.wasm`, "application/wasm"),
    workerURL: await toBlobURL(`${base}/ffmpeg-core.worker.js`, "text/javascript")
  });
  state.ffmpeg = ffmpeg;
  return ffmpeg;
}
$("downloadMp4").onclick = async () => {
  if (!state.blob || state.converting) return;
  state.converting = true;
  $("downloadMp4").disabled = true;
  try {
    $("status").textContent = "MP4変換準備中";
    const ffmpeg = await getFFmpeg();
    await ffmpeg.writeFile("input.webm", await window.FFmpegUtil.fetchFile(state.blob));
    const code = await ffmpeg.exec([
      "-i", "input.webm", "-c:v", "libx264", "-preset", "veryfast",
      "-crf", "23", "-c:a", "aac", "-b:a", "128k",
      "-movflags", "+faststart", "output.mp4"
    ]);
    if (code !== 0) throw new Error("動画の変換処理が完了しませんでした。");
    const data = await ffmpeg.readFile("output.mp4");
    download(new Blob([data], {type: "video/mp4"}), `screen-recording-${fileStamp()}.mp4`);
    await Promise.allSettled([ffmpeg.deleteFile("input.webm"), ffmpeg.deleteFile("output.mp4")]);
    notify("MP4をダウンロードしました。", true);
  } catch (error) {
    notify(`MP4変換に失敗しました。${error.message || ""}`);
  } finally {
    $("status").textContent = "録画終了";
    $("downloadMp4").disabled = false;
    state.converting = false;
  }
};

function saveLocal() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      version: 1, items: state.items, skips: state.skips,
      connectors: state.connectors, duration: duration()
    }));
  } catch {
    notify("ブラウザへの編集内容の自動保存に失敗しました。JSONファイルに保存してください。");
  }
}
function currentSelection() {
  return state.items.find(item => item.id === state.selected) ||
    state.connectors.find(line => line.id === state.selected) || null;
}
function addItem(type, position = {x: 15, y: 15}) {
  const start = Math.min(video.currentTime || 0, Math.max(0, duration() - 0.1));
  const end = Math.min(duration() || start + 3, start + 3);
  if (end <= start) return notify("動画の終了位置には要素を追加できません。");
  const item = {
    id: id(), type, start, end,
    x: clamp(position.x, 0, 70), y: clamp(position.y, 0, 70),
    w: type === "comment" ? 28 : 25, h: type === "comment" ? 12 : 20,
    ...(type === "comment" ? {text: "コメント"} : {})
  };
  state.items.push(item);
  state.selected = item.id;
  video.pause();
  render();
  saveLocal();
}
$("addComment").onclick = () => addItem("comment");
$("addBox").onclick = () => addItem("box");

function selectItem(item) {
  if (state.connectMode && state.items.includes(item)) {
    if (!state.connectFrom) {
      state.connectFrom = item.id;
      state.selected = item.id;
      notify("次に、接続先の要素をクリックしてください。", true);
    } else if (state.connectFrom !== item.id) {
      const from = state.items.find(x => x.id === state.connectFrom);
      const start = Math.max(from.start, item.start);
      const end = Math.min(from.end, item.end);
      if (end <= start) {
        notify("表示時間が重なる要素同士を選択してください。");
        return;
      }
      const line = {id: id(), from: from.id, to: item.id, start, end};
      state.connectors.push(line);
      state.selected = line.id;
      state.connectFrom = null;
      state.connectMode = false;
      saveLocal();
    }
  } else {
    state.selected = item.id;
  }
  render();
}
$("connect").onclick = () => {
  state.connectMode = !state.connectMode;
  state.connectFrom = null;
  $("connect").textContent = state.connectMode ? "接続をキャンセル" : "線でつなぐ";
  notify(state.connectMode
    ? "接続する2つの要素を順番にクリックしてください。"
    : "接続をキャンセルしました。", true);
};
function deleteSelected() {
  if (!state.selected) return notify("削除する項目を選択してください。");
  state.items = state.items.filter(item => item.id !== state.selected);
  state.connectors = state.connectors.filter(line =>
    line.id !== state.selected &&
    state.items.some(item => item.id === line.from) &&
    state.items.some(item => item.id === line.to)
  );
  state.selected = null;
  render();
  saveLocal();
}
$("deleteSelected").onclick = deleteSelected;
document.addEventListener("keydown", event => {
  if (!$("editor").style.display || $("editor").style.display === "none") return;
  if (event.key === "Escape") {
    state.connectMode = false;
    state.connectFrom = null;
    $("connect").textContent = "線でつなぐ";
    $("contextMenu").style.display = "none";
    return;
  }
  const tag = document.activeElement?.tagName;
  if ((event.key === "Delete" || event.key === "Backspace") &&
      !["INPUT", "TEXTAREA", "SELECT"].includes(tag) && state.selected) {
    event.preventDefault();
    deleteSelected();
  }
});

function fitStage() {
  const area = $("videoArea");
  const vw = video.videoWidth || 16;
  const vh = video.videoHeight || 9;
  const scale = Math.min(area.clientWidth / vw, area.clientHeight / vh);
  stage.style.width = `${Math.max(1, vw * scale)}px`;
  stage.style.height = `${Math.max(1, vh * scale)}px`;
  $("connectors").setAttribute("viewBox", "0 0 100 100");
}
new ResizeObserver(fitStage).observe($("videoArea"));

function dragOrResize(event, item, element, resizing) {
  if (event.button !== 0) return;
  event.preventDefault();
  event.stopPropagation();
  video.pause();
  state.selected = item.id;
  updateSelection();

  const origin = {
    clientX: event.clientX, clientY: event.clientY,
    x: item.x, y: item.y, w: item.w, h: item.h
  };
  const pointerTarget = event.currentTarget;
  const pointerId = event.pointerId;
  pointerTarget.setPointerCapture(pointerId);

  const onMove = moveEvent => {
    if (moveEvent.pointerId !== pointerId) return;
    const rect = stage.getBoundingClientRect();
    if (!rect.width || !rect.height) return;

    const dx = (moveEvent.clientX - origin.clientX) / rect.width * 100;
    const dy = (moveEvent.clientY - origin.clientY) / rect.height * 100;

    if (resizing) {
      item.w = clamp(origin.w + dx, 5, 100 - item.x);
      item.h = clamp(origin.h + dy, 5, 100 - item.y);
      element.style.width = `${item.w}%`;
      element.style.height = `${item.h}%`;
    } else {
      item.x = clamp(origin.x + dx, 0, 100 - item.w);
      item.y = clamp(origin.y + dy, 0, 100 - item.h);
      element.style.left = `${item.x}%`;
      element.style.top = `${item.y}%`;
    }
    drawConnectors();
  };
  const onEnd = endEvent => {
    if (endEvent.pointerId !== pointerId) return;
    pointerTarget.removeEventListener("pointermove", onMove);
    pointerTarget.removeEventListener("pointerup", onEnd);
    pointerTarget.removeEventListener("pointercancel", onEnd);
    render();
    saveLocal();
  };
  pointerTarget.addEventListener("pointermove", onMove);
  pointerTarget.addEventListener("pointerup", onEnd);
  pointerTarget.addEventListener("pointercancel", onEnd);
}
function drawConnectors() {
  const svg = $("connectors");
  svg.replaceChildren();
  const t = video.currentTime || 0;
  for (const line of state.connectors) {
    if (t < line.start || t >= line.end) continue;
    const a = state.items.find(item => item.id === line.from);
    const b = state.items.find(item => item.id === line.to);
    if (!a || !b || t < a.start || t >= a.end || t < b.start || t >= b.end) continue;
    const node = document.createElementNS("http://www.w3.org/2000/svg", "line");
    node.setAttribute("x1", a.x + a.w / 2);
    node.setAttribute("y1", a.y + a.h / 2);
    node.setAttribute("x2", b.x + b.w / 2);
    node.setAttribute("y2", b.y + b.h / 2);
    node.classList.add("connector");
    if (state.selected === line.id) node.classList.add("selected");
    node.addEventListener("click", event => {
      event.stopPropagation();
      state.selected = line.id;
      render();
    });
    svg.appendChild(node);
  }
}
function drawObjects() {
  const container = $("objects");
  container.replaceChildren();
  const t = video.currentTime || 0;
  for (const item of state.items) {
    if (t < item.start || t >= item.end) continue;
    const element = document.createElement("div");
    element.className = `edit-object ${item.type}`;
    if (state.selected === item.id) element.classList.add("selected");
    element.style.left = `${item.x}%`;
    element.style.top = `${item.y}%`;
    element.style.width = `${item.w}%`;
    element.style.height = `${item.h}%`;
    if (item.type === "comment") element.textContent = item.text;

    const handle = document.createElement("div");
    handle.className = "handle";
    handle.title = "ドラッグしてサイズ変更";
    handle.addEventListener("pointerdown", event => dragOrResize(event, item, element, true));
    element.appendChild(handle);
    element.addEventListener("pointerdown", event => {
      if (event.target === handle) return;
      if (state.connectMode) {
        event.preventDefault();
        event.stopPropagation();
        selectItem(item);
      } else {
        dragOrResize(event, item, element, false);
      }
    });
    container.appendChild(element);
  }
}
function updateSelection() {
  const item = currentSelection();
  const isObject = item && state.items.includes(item);
  $("selectionHint").textContent = !item
    ? "コメントまたは強調枠を選択してください。"
    : isObject ? `${item.type === "comment" ? "コメント" : "強調枠"}を編集中`
      : "接続線を選択中です。不要なら「選択項目を削除」を押してください。";
  $("objectFields").hidden = !isObject;
  if (!isObject) return;
  $("textField").hidden = item.type !== "comment";
  $("objectText").value = item.text || "";
  for (const key of ["start", "end", "x", "y", "w", "h"]) {
    $(key === "start" ? "objectStart" :
      key === "end" ? "objectEnd" :
      `object${key.toUpperCase()}`).value = Number(item[key].toFixed(2));
  }
}
$("applyObject").onclick = () => {
  const item = currentSelection();
  if (!item || !state.items.includes(item)) return;
  const range = validRange($("objectStart").value, $("objectEnd").value);
  const values = ["X", "Y", "W", "H"].map(key => Number($(`object${key}`).value));
  const [x, y, w, h] = values;
  const text = $("objectText").value.trim();
  if (!range || values.some(value => !Number.isFinite(value)) ||
      x < 0 || y < 0 || w < 5 || h < 5 || x + w > 100 || y + h > 100) {
    return notify("時間・位置・サイズを正しく入力してください。");
  }
  if (item.type === "comment" && !text) return notify("コメントを入力してください。");
  Object.assign(item, range, {x, y, w, h});
  if (item.type === "comment") item.text = text;
  state.connectors.forEach(line => {
    if (line.from === item.id || line.to === item.id) {
      const a = state.items.find(value => value.id === line.from);
      const b = state.items.find(value => value.id === line.to);
      line.start = Math.max(a.start, b.start);
      line.end = Math.min(a.end, b.end);
    }
  });
  state.connectors = state.connectors.filter(line => line.end > line.start);
  render();
  saveLocal();
};
function renderLists() {
  const objects = $("objectList");
  objects.replaceChildren();
  for (const item of state.items) {
    const row = document.createElement("div");
    row.className = "list-item" + (state.selected === item.id ? " selected" : "");
    const label = document.createElement("div");
    label.textContent = `${item.type === "comment" ? `コメント: ${item.text}` : "強調枠"}　${formatTime(item.start)}～${formatTime(item.end)}`;
    row.appendChild(label);
    const actions = document.createElement("div");
    actions.className = "actions";
    const jump = document.createElement("button");
    jump.textContent = "選択・移動";
    jump.onclick = () => {
      video.currentTime = item.start;
      selectItem(item);
    };
    const remove = document.createElement("button");
    remove.textContent = "削除";
    remove.onclick = () => {
      state.selected = item.id;
      deleteSelected();
    };
    actions.append(jump, remove);
    row.appendChild(actions);
    objects.appendChild(row);
  }

  const skips = $("skipList");
  skips.replaceChildren();
  state.skips.forEach((range, index) => {
    const row = document.createElement("div");
    row.className = "list-item";
    row.textContent = `${formatTime(range.start)}～${formatTime(range.end)} `;
    const remove = document.createElement("button");
    remove.textContent = "削除";
    remove.onclick = () => {
      state.skips.splice(index, 1);
      render();
      saveLocal();
    };
    row.appendChild(remove);
    skips.appendChild(row);
  });
}
function renderTracks() {
  for (const [trackId, data] of [
    ["commentTrack", state.items.filter(item => item.type === "comment")],
    ["boxTrack", state.items.filter(item => item.type === "box")],
    ["skipTrack", state.skips]
  ]) {
    const track = $(trackId);
    track.replaceChildren();
    const max = duration();
    if (!max) continue;
    for (const entry of data) {
      const marker = document.createElement("span");
      marker.className = trackId === "skipTrack" ? "skip" : entry.type || "";
      marker.style.left = `${clamp(entry.start / max * 100, 0, 100)}%`;
      marker.style.width = `${clamp((entry.end - entry.start) / max * 100, 0, 100)}%`;
      marker.title = `${formatTime(entry.start)}～${formatTime(entry.end)}`;
      marker.onclick = () => {
        video.currentTime = entry.start;
        if (entry.id) selectItem(entry);
      };
      track.appendChild(marker);
    }
  }
}
function render() {
  fitStage();
  drawConnectors();
  drawObjects();
  renderLists();
  renderTracks();
  updateSelection();
}
function refreshPlayback() {
  const max = duration();
  const t = video.currentTime || 0;
  $("seek").max = max;
  $("seek").value = t;
  $("timeReadout").textContent = `${formatTime(t)} / ${formatTime(max)}`;
  drawConnectors();
  drawObjects();

  if (video.paused) return;
  const skip = state.skips.find(range => t >= range.start && t < range.end);
  if (skip && Math.abs(state.lastSkipAt - t) > 0.05) {
    state.lastSkipAt = t;
    video.currentTime = skip.end;
  }
}
video.addEventListener("loadedmetadata", () => {
  fitStage();
  render();
  refreshPlayback();
});
video.addEventListener("timeupdate", refreshPlayback);
video.addEventListener("seeked", refreshPlayback);
video.addEventListener("play", () => state.lastSkipAt = -1);
$("seek").oninput = event => video.currentTime = Number(event.target.value);
$("back5").onclick = () => video.currentTime = Math.max(0, video.currentTime - 5);
$("forward5").onclick = () => video.currentTime = Math.min(duration(), video.currentTime + 5);

$("skipFromCurrent").onclick = () => {
  $("skipStart").value = video.currentTime.toFixed(1);
  $("skipEnd").value = Math.min(duration(), video.currentTime + 5).toFixed(1);
};
$("addSkip").onclick = () => {
  const range = validRange($("skipStart").value, $("skipEnd").value);
  if (!range) return notify("スキップの開始・終了時間を正しく入力してください。");
  state.skips.push(range);
  state.skips.sort((a, b) => a.start - b.start);
  render();
  saveLocal();
};
$("clearEdits").onclick = () => {
  if (!confirm("コメント・強調枠・接続線・スキップ範囲をすべて削除しますか？")) return;
  state.items = [];
  state.skips = [];
  state.connectors = [];
  state.selected = null;
  state.connectMode = false;
  state.connectFrom = null;
  $("connect").textContent = "線でつなぐ";
  render();
  saveLocal();
};

function validateProject(value) {
  if (!value || value.version !== 1 ||
      !Array.isArray(value.items) || !Array.isArray(value.skips) ||
      !Array.isArray(value.connectors)) throw new Error("編集データの形式が正しくありません。");
  const max = duration();
  if (max && value.duration && Math.abs(value.duration - max) > 1) {
    throw new Error("保存時と動画の長さが異なります。同じ録画を開いてください。");
  }
  const ids = new Set();
  for (const item of value.items) {
    if (!item || !["comment", "box"].includes(item.type) ||
        typeof item.id !== "string" || ids.has(item.id) ||
        !validRange(item.start, item.end) ||
        ![item.x, item.y, item.w, item.h].every(Number.isFinite) ||
        item.x < 0 || item.y < 0 || item.w < 5 || item.h < 5 ||
        item.x + item.w > 100 || item.y + item.h > 100 ||
        (item.type === "comment" && (typeof item.text !== "string" || !item.text.trim()))) {
      throw new Error("編集要素のデータが正しくありません。");
    }
    ids.add(item.id);
  }
  if (value.skips.some(range => !range || !validRange(range.start, range.end))) {
    throw new Error("スキップ範囲のデータが正しくありません。");
  }
  if (value.connectors.some(line => !line || typeof line.id !== "string" ||
      !ids.has(line.from) || !ids.has(line.to) ||
      !validRange(line.start, line.end))) {
    throw new Error("接続線のデータが正しくありません。");
  }
  return value;
}
function applyProject(project) {
  validateProject(project);
  state.items = structuredClone(project.items);
  state.skips = structuredClone(project.skips);
  state.connectors = structuredClone(project.connectors);
  state.selected = null;
  state.connectMode = false;
  state.connectFrom = null;
  $("connect").textContent = "線でつなぐ";
  render();
  saveLocal();
}
$("saveProject").onclick = () => {
  const project = {
    version: 1, duration: duration(), items: state.items,
    skips: state.skips, connectors: state.connectors
  };
  download(new Blob([JSON.stringify(project, null, 2)], {type: "application/json"}),
    `screen-recording-edits-${fileStamp()}.json`);
};
$("loadProject").onclick = () => $("projectFile").click();
$("projectFile").onchange = async event => {
  const file = event.target.files?.[0];
  if (!file) return;
  try {
    const project = JSON.parse(await file.text());
    applyProject(project);
    notify("編集内容を読み込みました。", true);
  } catch (error) {
    notify(`読み込みに失敗しました。${error.message || ""}`);
  } finally {
    event.target.value = "";
  }
};

stage.addEventListener("contextmenu", event => {
  if (!state.blob) return;
  event.preventDefault();
  const rect = stage.getBoundingClientRect();
  state.contextPoint = {
    x: clamp((event.clientX - rect.left) / rect.width * 100, 0, 70),
    y: clamp((event.clientY - rect.top) / rect.height * 100, 0, 70)
  };
  const menu = $("contextMenu");
  menu.style.left = `${clamp(event.clientX, 0, innerWidth - 190)}px`;
  menu.style.top = `${clamp(event.clientY, 0, innerHeight - 130)}px`;
  menu.style.display = "block";
});
$("contextMenu").onclick = event => {
  const action = event.target.closest("button")?.dataset.action;
  if (action === "comment" || action === "box") addItem(action, state.contextPoint);
  if (action === "connect") $("connect").click();
  $("contextMenu").style.display = "none";
};
document.addEventListener("pointerdown", event => {
  if (!$("contextMenu").contains(event.target)) $("contextMenu").style.display = "none";
});

try {
  const saved = localStorage.getItem(STORAGE_KEY);
  if (saved) {
    const project = JSON.parse(saved);
    validateProject(project);
    state.items = project.items;
    state.skips = project.skips;
    state.connectors = project.connectors;
  }
} catch {
  notify("前回の編集内容を復元できませんでした。");
}
updateRecordingButtons();
</script>
</body>
</html>
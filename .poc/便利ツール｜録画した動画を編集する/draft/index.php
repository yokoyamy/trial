<?php
declare(strict_types=1);

/*
 * 画面共有録画・動画編集ツール
 * PHP + Apache 1ファイル版
 *
 * PHP側ではHTMLを返すだけで、録画・編集処理はブラウザ側で完結します。
 * 録画データそのものをサーバーへアップロードする仕様ではありません。
 */
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#101010">
<title>画面共有録画・編集</title>
<style>
:root{
  color-scheme:dark;
  --bg:#101010;--panel:#1b1b1b;--panel2:#202020;--line:#393939;
  --text:#eee;--muted:#aaa;--blue:#1769aa;--red:#a52c2c;--green:#26733a;
}
*{box-sizing:border-box}
html,body{width:100%;height:100%;margin:0}
body{font-family:system-ui,"Noto Sans JP",sans-serif;background:var(--bg);color:var(--text)}
button,input,textarea,select{font:inherit}
button{
  border:1px solid #555;border-radius:6px;background:#303030;color:#fff;
  padding:8px 11px;cursor:pointer
}
button:hover:not(:disabled){background:#454545}
button:disabled{opacity:.45;cursor:not-allowed}
button.primary{background:var(--blue)}
button.danger{background:var(--red)}
button.success{background:var(--green)}
button.active{background:#555;border-color:#aaa}
input,textarea,select{accent-color:#4aa3ff}
header,.toolbar,.footer{
  display:flex;align-items:center;gap:9px;flex-wrap:wrap;
  padding:10px 14px;background:#1c1c1c;border-bottom:1px solid #333
}
header{justify-content:space-between}
header h1{font-size:17px;margin:0}
#status{font-size:13px;color:#bbb}
#message{
  display:none;padding:9px 14px;background:#853232;white-space:pre-wrap;
  position:relative;z-index:50
}
#message.ok{background:#24643c}
.recorder{
  height:calc(100vh - 110px);min-height:310px;display:flex;
  align-items:center;justify-content:center;background:#000
}
#preview{max-width:100%;max-height:100%;display:none}
#placeholder{color:#999;text-align:center;padding:20px}
.toolbar{border-top:1px solid #333;border-bottom:0}
.toolbar .spacer{flex:1}
#timer{font-variant-numeric:tabular-nums;min-width:82px}
#editor{
  display:none;position:fixed;inset:0;z-index:10;background:#111;
  flex-direction:column
}
.editor-main{
  flex:1;min-height:0;display:grid;
  grid-template-columns:minmax(0,1fr) 340px
}
.video-area{
  position:relative;min-width:0;min-height:0;display:flex;
  align-items:center;justify-content:center;background:#000;overflow:hidden
}
#videoStage{
  position:relative;max-width:100%;max-height:100%;background:#000;overflow:hidden;
  box-shadow:0 0 0 1px #222
}
#recordedVideo{display:block;width:100%;height:100%;object-fit:contain}
#overlay{position:absolute;inset:0;pointer-events:none}
#objects{position:absolute;inset:0}
.edit-object{
  position:absolute;pointer-events:auto;cursor:move;touch-action:none;
  user-select:none;min-width:5%;min-height:5%
}
.edit-object.selected{outline:2px solid #fff;outline-offset:2px}
.edit-object.connecting{outline:2px dashed #ffd54f;outline-offset:2px}
.edit-object.comment{
  border:1px solid #fff;border-radius:6px;background:rgba(0,0,0,.86);
  white-space:pre-wrap;overflow:hidden;padding:7px 9px
}
.edit-object.box{border:3px solid #ff453a;background:rgba(255,69,58,.08)}
.handle{
  position:absolute;right:-7px;bottom:-7px;width:14px;height:14px;
  border:1px solid #222;border-radius:50%;background:#fff;
  cursor:nwse-resize;touch-action:none
}
#connectors{
  position:absolute;inset:0;width:100%;height:100%;
  overflow:visible;pointer-events:none
}
.connector{
  stroke:#fff;stroke-width:2.5;pointer-events:stroke;cursor:pointer
}
.connector.selected{stroke:#ffd54f;stroke-width:4}
aside{
  overflow:auto;background:var(--panel);border-left:1px solid #383838;
  padding:12px
}
aside h2{font-size:16px;margin:0 0 8px}
section{padding-bottom:13px;margin-bottom:13px;border-bottom:1px solid #393939}
label.field{display:block;font-size:12px;color:#bbb;margin:8px 0}
.field input,.field textarea,.field select{
  display:block;width:100%;margin-top:4px;border:1px solid #555;
  border-radius:5px;background:#252525;color:#fff;padding:7px
}
.field textarea{min-height:65px;resize:vertical}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 8px}
.help{font-size:12px;color:#aaa;line-height:1.5}
.list-item{
  border:1px solid #454545;border-radius:6px;margin:6px 0;padding:8px;
  font-size:12px;overflow-wrap:anywhere
}
.list-item.selected{border-color:#4aa3ff}
.list-item .actions{display:flex;gap:5px;margin-top:6px}
.list-item button{padding:4px 7px;font-size:11px}
.empty{color:#777;font-size:12px;padding:5px 0}
#timeline{
  padding:8px 13px;background:#1c1c1c;border-top:1px solid #393939
}
#seek{width:100%;cursor:pointer}
#tracks{
  display:grid;grid-template-columns:62px 1fr;gap:5px 8px;
  align-items:center;font-size:11px;color:#aaa
}
.track{
  position:relative;height:15px;background:#2c2c2c;border-radius:3px;
  overflow:hidden
}
.track span{
  position:absolute;top:2px;height:11px;min-width:3px;border-radius:2px;
  cursor:pointer;background:#777
}
.track .box{background:#e55}
.track .skip{background:#ee9b28}
.track span.current{outline:1px solid #fff;z-index:2}
.footer{
  justify-content:center;border-top:1px solid #393939;border-bottom:0
}
#timeReadout{
  min-width:108px;text-align:center;font-variant-numeric:tabular-nums
}
#contextMenu{
  display:none;position:fixed;z-index:100;background:#292929;
  border:1px solid #666;border-radius:7px;padding:5px;
  box-shadow:0 8px 20px #0009
}
#contextMenu button{
  display:block;width:100%;text-align:left;border:0;background:transparent
}
.badge{
  display:inline-block;padding:2px 6px;border-radius:10px;
  background:#333;color:#bbb;font-size:10px
}
.notice{
  padding:7px 9px;border-radius:5px;background:#252525;
  color:#aaa;font-size:12px;margin-top:7px
}
@media(max-width:760px){
  .editor-main{display:flex;flex-direction:column}
  .video-area{flex:1;min-height:180px}
  aside{
    max-height:38vh;width:100%;border-left:0;
    border-top:1px solid #383838
  }
  .footer button{font-size:12px;padding:6px}
  .toolbar{font-size:12px}
}
</style>
</head>

<body>

<header>
  <h1>画面共有録画・編集</h1>
  <span id="status">待機中</span>
</header>

<div id="message" role="status" aria-live="polite"></div>

<div class="recorder">
  <video id="preview" autoplay muted playsinline></video>
  <div id="placeholder">
    「録画開始」を押して共有する画面を選択してください
  </div>
</div>

<div class="toolbar">
  <label><input id="systemAudio" type="checkbox"> 画面の音声</label>
  <label><input id="microphone" type="checkbox"> マイク</label>

  <span class="spacer"></span>
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
          <svg id="connectors" preserveAspectRatio="none" viewBox="0 0 100 100"></svg>
          <div id="objects"></div>
        </div>

      </div>

    </div>

    <aside>

      <section>
        <h2>動画編集</h2>

        <div class="help">
          動画上を右クリックすると編集項目を追加できます。
          要素はドラッグで移動、右下のハンドルでサイズ変更できます。
          「線でつなぐ」を押した後、2つの要素を順番にクリックしてください。
        </div>

        <div style="margin-top:8px;display:flex;gap:5px;flex-wrap:wrap">
          <button id="addComment" class="primary">コメントを追加</button>
          <button id="addBox" class="primary">強調枠を追加</button>
          <button id="connect">線でつなぐ</button>
          <button id="deleteSelected" class="danger">選択項目を削除</button>
        </div>
      </section>

      <section>
        <h2>選択中の要素</h2>

        <div id="selectionHint" class="help">
          コメントまたは強調枠を選択してください。
        </div>

        <div id="objectFields" hidden>

          <label id="textField" class="field">
            コメント
            <textarea id="objectText"></textarea>
          </label>

          <div class="grid">
            <label class="field">
              開始（秒）
              <input id="objectStart" type="number" min="0" step="0.1">
            </label>

            <label class="field">
              終了（秒）
              <input id="objectEnd" type="number" min="0" step="0.1">
            </label>

            <label class="field">
              左（%）
              <input id="objectX" type="number" min="0" max="100" step="0.1">
            </label>

            <label class="field">
              上（%）
              <input id="objectY" type="number" min="0" max="100" step="0.1">
            </label>

            <label class="field">
              幅（%）
              <input id="objectW" type="number" min="5" max="100" step="0.1">
            </label>

            <label class="field">
              高さ（%）
              <input id="objectH" type="number" min="5" max="100" step="0.1">
            </label>
          </div>

          <button id="applyObject" class="primary">変更を反映</button>

        </div>
      </section>

      <section>
        <h2>再生をスキップ</h2>

        <div class="grid">

          <label class="field">
            開始（秒）
            <input id="skipStart" type="number" min="0" step="0.1" value="0">
          </label>

          <label class="field">
            終了（秒）
            <input id="skipEnd" type="number" min="0" step="0.1" value="5">
          </label>

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

        <input
          id="projectFile"
          type="file"
          accept="application/json,.json"
          hidden
        >

        <button id="clearEdits" class="danger">編集内容をすべて削除</button>

        <div class="help" style="margin-top:7px">
          JSONには編集内容のみ保存されます。
          録画動画そのものは含まれません。
          編集内容はブラウザにも自動保存されます。
        </div>
      </section>

    </aside>

  </div>

  <div id="timeline">

    <input id="seek" type="range" min="0" max="0" step="0.01" value="0">

    <div id="tracks">

      <span>コメント</span>
      <div class="track" id="commentTrack"></div>

      <span>強調枠</span>
      <div class="track" id="boxTrack"></div>

      <span>スキップ</span>
      <div class="track" id="skipTrack"></div>

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

/* =========================================================
 * DOM / 定数
 * ======================================================= */

const $ = id => document.getElementById(id);

const DOM = {
  preview: $("preview"),
  video: $("recordedVideo"),
  stage: $("videoStage"),
  videoArea: $("videoArea"),
  overlay: $("overlay"),
  objects: $("objects"),
  connectors: $("connectors"),
  editor: $("editor"),
  contextMenu: $("contextMenu"),
  message: $("message"),
  status: $("status"),
  timer: $("timer"),
  seek: $("seek"),
  timeReadout: $("timeReadout")
};

const STORAGE_KEY = "screen-recorder-edits-v2";
const PROJECT_VERSION = 2;

const state = {
  displayStream: null,
  microphoneStream: null,
  mixedAudioContext: null,

  recorder: null,
  chunks: [],
  blob: null,
  url: null,

  recordingStartedAt: 0,
  recordedElapsed: 0,
  timerId: null,

  items: [],
  skips: [],
  connectors: [],

  selected: null,
  connectMode: false,
  connectFrom: null,
  contextPoint: {x: 15, y: 15},

  ffmpeg: null,
  converting: false,
  lastSkipAt: -1,
  saveTimer: null
};


/* =========================================================
 * 共通
 * ======================================================= */

function notify(message, ok = false) {
  DOM.message.textContent = message;
  DOM.message.classList.toggle("ok", ok);
  DOM.message.style.display = "block";

  clearTimeout(notify.timer);
  notify.timer = setTimeout(() => {
    DOM.message.style.display = "none";
  }, 5500);
}

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}

function uid() {
  if (globalThis.crypto?.randomUUID) return crypto.randomUUID();
  return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function duration() {
  return Number.isFinite(DOM.video.duration) ? DOM.video.duration : 0;
}

function formatTime(seconds, hours = false) {
  seconds = Math.max(0, Math.floor(Number(seconds) || 0));

  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;

  return hours
    ? [h, m, s].map(v => String(v).padStart(2, "0")).join(":")
    : [Math.floor(seconds / 60), s].map(v => String(v).padStart(2, "0")).join(":");
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

function download(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = filename;

  document.body.appendChild(link);
  link.click();
  link.remove();

  setTimeout(() => URL.revokeObjectURL(url), 60000);
}

function clone(value) {
  return structuredClone
    ? structuredClone(value)
    : JSON.parse(JSON.stringify(value));
}


/* =========================================================
 * 時間範囲
 * ======================================================= */

function validRange(start, end) {
  start = Number(start);
  end = Number(end);

  const max = duration();

  if (
    !Number.isFinite(start) ||
    !Number.isFinite(end) ||
    start < 0 ||
    end <= start ||
    (max && start >= max)
  ) {
    return null;
  }

  return {
    start,
    end: max ? Math.min(end, max) : end
  };
}


/* =========================================================
 * 録画
 * ======================================================= */

function recordingMime() {
  if (!window.MediaRecorder) return "";

  return [
    "video/webm;codecs=vp9,opus",
    "video/webm;codecs=vp8,opus",
    "video/webm;codecs=vp9",
    "video/webm;codecs=vp8",
    "video/webm"
  ].find(type => MediaRecorder.isTypeSupported(type)) || "";
}

function stopStreams() {
  [state.displayStream, state.microphoneStream].forEach(stream => {
    stream?.getTracks().forEach(track => track.stop());
  });

  state.displayStream = null;
  state.microphoneStream = null;
  DOM.preview.srcObject = null;

  if (state.mixedAudioContext) {
    state.mixedAudioContext.close().catch(() => {});
    state.mixedAudioContext = null;
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
  const active = state.recordingStartedAt
    ? Date.now() - state.recordingStartedAt
    : 0;

  DOM.timer.textContent = formatTime(
    (state.recordedElapsed + active) / 1000,
    true
  );
}

function startTimer() {
  state.recordingStartedAt = Date.now();

  clearInterval(state.timerId);
  state.timerId = setInterval(updateTimer, 250);

  updateTimer();
}

function stopTimer() {
  if (state.recordingStartedAt) {
    state.recordedElapsed += Date.now() - state.recordingStartedAt;
  }

  state.recordingStartedAt = 0;

  clearInterval(state.timerId);
  state.timerId = null;

  updateTimer();
}

async function createRecordingStream() {
  if (!state.displayStream) {
    throw new Error("画面共有ストリームがありません。");
  }

  const stream = new MediaStream(
    state.displayStream.getVideoTracks()
  );

  const audioTracks = [
    ...($("systemAudio").checked
      ? state.displayStream.getAudioTracks()
      : []),

    ...(state.microphoneStream?.getAudioTracks() || [])
  ];

  if (audioTracks.length === 1) {
    stream.addTrack(audioTracks[0]);
    return stream;
  }

  if (audioTracks.length > 1) {
    const context = new AudioContext();
    state.mixedAudioContext = context;

    const destination = context.createMediaStreamDestination();

    audioTracks.forEach(track => {
      const source = context.createMediaStreamSource(
        new MediaStream([track])
      );

      source.connect(destination);
    });

    await context.resume();

    stream.addTrack(destination.stream.getAudioTracks()[0]);
  }

  return stream;
}

async function startRecording() {
  try {
    if (
      !navigator.mediaDevices?.getDisplayMedia ||
      !window.MediaRecorder
    ) {
      throw new Error(
        "このブラウザでは画面録画を利用できません。HTTPSまたはlocalhostで開いてください。"
      );
    }

    const mimeType = recordingMime();

    if (!mimeType) {
      throw new Error("このブラウザはWebM録画に対応していません。");
    }

    $("start").disabled = true;

    state.displayStream = await navigator.mediaDevices.getDisplayMedia({
      video: true,
      audio: $("systemAudio").checked
    });

    if ($("microphone").checked) {
      try {
        state.microphoneStream =
          await navigator.mediaDevices.getUserMedia({audio: true});
      } catch (error) {
        stopStreams();

        throw new Error(
          "マイクへのアクセスが許可されませんでした。"
        );
      }
    }

    const stream = await createRecordingStream();

    state.chunks = [];
    state.blob = null;
    state.recordedElapsed = 0;

    state.recorder = new MediaRecorder(stream, {mimeType});

    state.recorder.addEventListener("dataavailable", event => {
      if (event.data?.size) {
        state.chunks.push(event.data);
      }
    });

    state.recorder.addEventListener("stop", finishRecording, {once: true});

    state.recorder.addEventListener("error", () => {
      notify("録画中にエラーが発生しました。");
      stopRecording();
    });

    const displayVideoTrack =
      state.displayStream.getVideoTracks()[0];

    displayVideoTrack?.addEventListener(
      "ended",
      stopRecording,
      {once: true}
    );

    DOM.preview.srcObject = state.displayStream;
    DOM.preview.style.display = "block";
    $("placeholder").style.display = "none";

    state.recorder.start(1000);

    startTimer();

    DOM.status.textContent = "録画中";

    $("pause").textContent = "一時停止";

    updateRecordingButtons(true);

  } catch (error) {
    stopStreams();

    state.recorder = null;

    $("start").disabled = false;

    notify(error?.message || "録画を開始できませんでした。");
  }
}

function stopRecording() {
  if (
    !state.recorder ||
    state.recorder.state === "inactive"
  ) {
    return;
  }

  try {
    state.recorder.stop();
  } catch {}

  stopTimer();

  $("pause").disabled = true;
  $("stop").disabled = true;

  DOM.status.textContent = "録画を保存中";
}

function finishRecording() {
  stopTimer();
  stopStreams();

  if (!state.chunks.length) {
    notify("録画データがありません。");
    updateRecordingButtons();
    return;
  }

  state.blob = new Blob(
    state.chunks,
    {
      type: state.recorder?.mimeType || "video/webm"
    }
  );

  if (state.url) {
    URL.revokeObjectURL(state.url);
  }

  state.url = URL.createObjectURL(state.blob);

  DOM.video.src = state.url;
  DOM.video.load();

  state.recorder = null;

  DOM.status.textContent = "録画終了";

  updateRecordingButtons();

  DOM.editor.style.display = "flex";
}

$("start").onclick = startRecording;

$("stop").onclick = stopRecording;

$("pause").onclick = () => {
  if (!state.recorder) return;

  if (state.recorder.state === "recording") {
    state.recorder.pause();
    stopTimer();

    $("pause").textContent = "再開";
    DOM.status.textContent = "一時停止中";

    return;
  }

  if (state.recorder.state === "paused") {
    state.recorder.resume();
    startTimer();

    $("pause").textContent = "一時停止";
    DOM.status.textContent = "録画中";
  }
};

function resetRecording() {
  if (
    !confirm(
      "現在の録画と編集内容を削除して、新しく録画しますか？"
    )
  ) {
    return;
  }

  DOM.video.pause();

  stopStreams();

  if (state.url) {
    URL.revokeObjectURL(state.url);
  }

  state.url = null;
  state.blob = null;
  state.chunks = [];
  state.recorder = null;

  DOM.video.removeAttribute("src");
  DOM.video.load();

  DOM.preview.style.display = "none";
  $("placeholder").style.display = "block";

  DOM.editor.style.display = "none";

  state.recordedElapsed = 0;
  state.recordingStartedAt = 0;

  DOM.timer.textContent = "00:00:00";
  $("pause").textContent = "一時停止";

  DOM.status.textContent = "待機中";

  clearEdits(false);

  localStorage.removeItem(STORAGE_KEY);

  updateRecordingButtons();

  render();
}

$("reset").onclick = resetRecording;


/* =========================================================
 * エディタ
 * ======================================================= */

$("openEditor").onclick = () => {
  if (!state.blob) {
    notify("録画データがありません。");
    return;
  }

  DOM.editor.style.display = "flex";
  requestAnimationFrame(render);
};

$("closeEditor").onclick = () => {
  DOM.video.pause();
  DOM.editor.style.display = "none";
};

function fitStage() {
  const area = DOM.videoArea;

  if (!area) return;

  const vw = DOM.video.videoWidth || 16;
  const vh = DOM.video.videoHeight || 9;

  const availableWidth = Math.max(1, area.clientWidth);
  const availableHeight = Math.max(1, area.clientHeight);

  const scale = Math.min(
    availableWidth / vw,
    availableHeight / vh
  );

  DOM.stage.style.width = `${Math.max(1, vw * scale)}px`;
  DOM.stage.style.height = `${Math.max(1, vh * scale)}px`;

  DOM.connectors.setAttribute("viewBox", "0 0 100 100");
}

if ("ResizeObserver" in window) {
  new ResizeObserver(fitStage).observe(DOM.videoArea);
} else {
  window.addEventListener("resize", fitStage);
}


/* =========================================================
 * 編集オブジェクト
 * ======================================================= */

function currentSelection() {
  return (
    state.items.find(item => item.id === state.selected) ||
    state.connectors.find(line => line.id === state.selected) ||
    null
  );
}

function addItem(
  type,
  position = {x: 15, y: 15}
) {
  if (!["comment", "box"].includes(type)) {
    return;
  }

  const total = duration();

  if (!total) {
    notify("動画を読み込んでから編集してください。");
    return;
  }

  const current = DOM.video.currentTime || 0;
  const start = Math.min(
    current,
    Math.max(0, total - 0.1)
  );

  const end = Math.min(
    total,
    start + Math.min(3, Math.max(0.1, total - start))
  );

  if (end <= start) {
    notify("動画の終了位置には要素を追加できません。");
    return;
  }

  const item = {
    id: uid(),
    type,
    start,
    end,
    x: clamp(Number(position.x) || 15, 0, 70),
    y: clamp(Number(position.y) || 15, 0, 70),
    w: type === "comment" ? 28 : 25,
    h: type === "comment" ? 12 : 20
  };

  if (type === "comment") {
    item.text = "コメント";
  }

  state.items.push(item);
  state.selected = item.id;

  DOM.video.pause();

  render();
  saveLocal();
}

$("addComment").onclick = () => addItem("comment");
$("addBox").onclick = () => addItem("box");


/* =========================================================
 * 選択 / 接続
 * ======================================================= */

function selectItem(item) {
  if (!item) return;

  if (
    state.connectMode &&
    state.items.includes(item)
  ) {
    if (!state.connectFrom) {
      state.connectFrom = item.id;
      state.selected = item.id;

      render();

      notify(
        "次に、接続先の要素をクリックしてください。",
        true
      );

      return;
    }

    if (state.connectFrom === item.id) {
      return;
    }

    const from = state.items.find(
      x => x.id === state.connectFrom
    );

    if (!from) {
      state.connectFrom = null;
      return;
    }

    const start = Math.max(from.start, item.start);
    const end = Math.min(from.end, item.end);

    if (end <= start) {
      notify(
        "表示時間が重なる要素同士を選択してください。"
      );
      return;
    }

    const duplicate = state.connectors.some(line =>
      (
        line.from === from.id &&
        line.to === item.id
      ) ||
      (
        line.from === item.id &&
        line.to === from.id
      )
    );

    if (duplicate) {
      state.connectMode = false;
      state.connectFrom = null;
      updateConnectButton();

      notify("同じ要素同士の接続線は既に存在します。");
      return;
    }

    const line = {
      id: uid(),
      from: from.id,
      to: item.id,
      start,
      end
    };

    state.connectors.push(line);

    state.selected = line.id;
    state.connectMode = false;
    state.connectFrom = null;

    updateConnectButton();

    render();
    saveLocal();

    return;
  }

  state.selected = item.id;

  render();
}

function updateConnectButton() {
  $("connect").textContent =
    state.connectMode
      ? "接続をキャンセル"
      : "線でつなぐ";

  $("connect").classList.toggle(
    "active",
    state.connectMode
  );
}

$("connect").onclick = () => {
  state.connectMode = !state.connectMode;
  state.connectFrom = null;

  updateConnectButton();

  notify(
    state.connectMode
      ? "接続する2つの要素を順番にクリックしてください。"
      : "接続をキャンセルしました。",
    true
  );

  render();
};


/* =========================================================
 * 削除
 * ======================================================= */

function deleteSelected() {
  if (!state.selected) {
    notify("削除する項目を選択してください。");
    return;
  }

  const selected = state.selected;

  state.items = state.items.filter(
    item => item.id !== selected
  );

  state.connectors = state.connectors.filter(
    line =>
      line.id !== selected &&
      state.items.some(item => item.id === line.from) &&
      state.items.some(item => item.id === line.to)
  );

  state.selected = null;
  state.connectFrom = null;

  render();
  saveLocal();
}

$("deleteSelected").onclick = deleteSelected;

document.addEventListener("keydown", event => {
  if (
    DOM.editor.style.display === "none" ||
    !DOM.editor.style.display
  ) {
    return;
  }

  if (event.key === "Escape") {
    state.connectMode = false;
    state.connectFrom = null;

    updateConnectButton();

    DOM.contextMenu.style.display = "none";

    render();

    return;
  }

  const tag = document.activeElement?.tagName;

  if (
    ["INPUT", "TEXTAREA", "SELECT"].includes(tag)
  ) {
    return;
  }

  if (
    ["Delete", "Backspace"].includes(event.key) &&
    state.selected
  ) {
    event.preventDefault();
    deleteSelected();
  }
});


/* =========================================================
 * ドラッグ / リサイズ
 * ======================================================= */

function dragOrResize(
  event,
  item,
  element,
  resizing
) {
  if (event.button !== 0) return;

  event.preventDefault();
  event.stopPropagation();

  DOM.video.pause();

  state.selected = item.id;

  const pointerTarget = event.currentTarget;
  const pointerId = event.pointerId;

  const origin = {
    clientX: event.clientX,
    clientY: event.clientY,
    x: item.x,
    y: item.y,
    w: item.w,
    h: item.h
  };

  pointerTarget.setPointerCapture?.(pointerId);

  const onMove = moveEvent => {
    if (moveEvent.pointerId !== pointerId) {
      return;
    }

    const rect = DOM.stage.getBoundingClientRect();

    if (!rect.width || !rect.height) {
      return;
    }

    const dx =
      (moveEvent.clientX - origin.clientX) /
      rect.width * 100;

    const dy =
      (moveEvent.clientY - origin.clientY) /
      rect.height * 100;

    if (resizing) {
      item.w = clamp(
        origin.w + dx,
        5,
        100 - item.x
      );

      item.h = clamp(
        origin.h + dy,
        5,
        100 - item.y
      );

      element.style.width = `${item.w}%`;
      element.style.height = `${item.h}%`;
    } else {
      item.x = clamp(
        origin.x + dx,
        0,
        100 - item.w
      );

      item.y = clamp(
        origin.y + dy,
        0,
        100 - item.h
      );

      element.style.left = `${item.x}%`;
      element.style.top = `${item.y}%`;
    }

    drawConnectors();
  };

  const onEnd = endEvent => {
    if (endEvent.pointerId !== pointerId) {
      return;
    }

    pointerTarget.removeEventListener(
      "pointermove",
      onMove
    );

    pointerTarget.removeEventListener(
      "pointerup",
      onEnd
    );

    pointerTarget.removeEventListener(
      "pointercancel",
      onEnd
    );

    render();
    saveLocal();
  };

  pointerTarget.addEventListener(
    "pointermove",
    onMove
  );

  pointerTarget.addEventListener(
    "pointerup",
    onEnd
  );

  pointerTarget.addEventListener(
    "pointercancel",
    onEnd
  );

  updateSelection();
}


/* =========================================================
 * 描画
 * ======================================================= */

function drawConnectors() {
  DOM.connectors.replaceChildren();

  const t = DOM.video.currentTime || 0;

  for (const line of state.connectors) {
    if (t < line.start || t >= line.end) {
      continue;
    }

    const from = state.items.find(
      item => item.id === line.from
    );

    const to = state.items.find(
      item => item.id === line.to
    );

    if (!from || !to) {
      continue;
    }

    if (
      t < from.start ||
      t >= from.end ||
      t < to.start ||
      t >= to.end
    ) {
      continue;
    }

    const node = document.createElementNS(
      "http://www.w3.org/2000/svg",
      "line"
    );

    node.setAttribute(
      "x1",
      from.x + from.w / 2
    );

    node.setAttribute(
      "y1",
      from.y + from.h / 2
    );

    node.setAttribute(
      "x2",
      to.x + to.w / 2
    );

    node.setAttribute(
      "y2",
      to.y + to.h / 2
    );

    node.classList.add("connector");

    if (state.selected === line.id) {
      node.classList.add("selected");
    }

    node.addEventListener("click", event => {
      event.stopPropagation();

      state.selected = line.id;

      render();
    });

    DOM.connectors.appendChild(node);
  }
}

function drawObjects() {
  DOM.objects.replaceChildren();

  const t = DOM.video.currentTime || 0;

  for (const item of state.items) {
    if (t < item.start || t >= item.end) {
      continue;
    }

    const element = document.createElement("div");

    element.className =
      `edit-object ${item.type}`;

    if (state.selected === item.id) {
      element.classList.add("selected");
    }

    if (
      state.connectMode &&
      state.connectFrom === item.id
    ) {
      element.classList.add("connecting");
    }

    Object.assign(
      element.style,
      {
        left: `${item.x}%`,
        top: `${item.y}%`,
        width: `${item.w}%`,
        height: `${item.h}%`
      }
    );

    if (item.type === "comment") {
      element.appendChild(
        document.createTextNode(item.text || "")
      );
    }

    const handle = document.createElement("div");

    handle.className = "handle";
    handle.title = "ドラッグしてサイズ変更";

    handle.addEventListener(
      "pointerdown",
      event => {
        dragOrResize(
          event,
          item,
          element,
          true
        );
      }
    );

    element.appendChild(handle);

    element.addEventListener(
      "pointerdown",
      event => {
        if (event.target === handle) {
          return;
        }

        if (state.connectMode) {
          event.preventDefault();
          event.stopPropagation();

          selectItem(item);
          return;
        }

        dragOrResize(
          event,
          item,
          element,
          false
        );
      }
    );

    DOM.objects.appendChild(element);
  }
}

function updateSelection() {
  const item = currentSelection();

  const isObject =
    item &&
    state.items.includes(item);

  if (!item) {
    $("selectionHint").textContent =
      "コメントまたは強調枠を選択してください。";
  } else if (isObject) {
    $("selectionHint").textContent =
      `${item.type === "comment" ? "コメント" : "強調枠"}を編集中`;
  } else {
    $("selectionHint").textContent =
      "接続線を選択中です。不要なら「選択項目を削除」を押してください。";
  }

  $("objectFields").hidden = !isObject;

  if (!isObject) {
    return;
  }

  $("textField").hidden =
    item.type !== "comment";

  $("objectText").value =
    item.text || "";

  const fields = {
    start: "objectStart",
    end: "objectEnd",
    x: "objectX",
    y: "objectY",
    w: "objectW",
    h: "objectH"
  };

  Object.entries(fields).forEach(
    ([key, id]) => {
      $(id).value =
        Number(item[key]).toFixed(2);
    }
  );
}


/* =========================================================
 * オブジェクト編集
 * ======================================================= */

$("applyObject").onclick = () => {
  const item = currentSelection();

  if (
    !item ||
    !state.items.includes(item)
  ) {
    return;
  }

  const range = validRange(
    $("objectStart").value,
    $("objectEnd").value
  );

  const x = Number($("objectX").value);
  const y = Number($("objectY").value);
  const w = Number($("objectW").value);
  const h = Number($("objectH").value);
  const text = $("objectText").value.trim();

  if (
    !range ||
    ![x, y, w, h].every(Number.isFinite) ||
    x < 0 ||
    y < 0 ||
    w < 5 ||
    h < 5 ||
    x + w > 100 ||
    y + h > 100
  ) {
    notify(
      "時間・位置・サイズを正しく入力してください。"
    );

    return;
  }

  if (
    item.type === "comment" &&
    !text
  ) {
    notify("コメントを入力してください。");
    return;
  }

  Object.assign(
    item,
    range,
    {x, y, w, h}
  );

  if (item.type === "comment") {
    item.text = text;
  }

  updateConnectorRanges(item.id);

  render();
  saveLocal();

  notify("変更を反映しました。", true);
};

function updateConnectorRanges(changedId = null) {
  state.connectors.forEach(line => {
    if (
      changedId &&
      line.from !== changedId &&
      line.to !== changedId
    ) {
      return;
    }

    const from = state.items.find(
      item => item.id === line.from
    );

    const to = state.items.find(
      item => item.id === line.to
    );

    if (!from || !to) {
      return;
    }

    line.start = Math.max(
      from.start,
      to.start
    );

    line.end = Math.min(
      from.end,
      to.end
    );
  });

  state.connectors =
    state.connectors.filter(
      line => line.end > line.start
    );
}


/* =========================================================
 * リスト描画
 * ======================================================= */

function createActionButton(
  text,
  handler,
  className = ""
) {
  const button =
    document.createElement("button");

  button.textContent = text;

  if (className) {
    button.className = className;
  }

  button.onclick = handler;

  return button;
}

function renderLists() {
  const objects = $("objectList");
  objects.replaceChildren();

  if (!state.items.length) {
    const empty =
      document.createElement("div");

    empty.className = "empty";
    empty.textContent =
      "コメント・強調枠はまだありません。";

    objects.appendChild(empty);
  }

  state.items.forEach(item => {
    const row =
      document.createElement("div");

    row.className =
      "list-item" +
      (
        state.selected === item.id
          ? " selected"
          : ""
      );

    const label =
      document.createElement("div");

    const title =
      item.type === "comment"
        ? `コメント: ${item.text || ""}`
        : "強調枠";

    label.textContent =
      `${title}　${formatTime(item.start)}～${formatTime(item.end)}`;

    row.appendChild(label);

    const actions =
      document.createElement("div");

    actions.className = "actions";

    actions.appendChild(
      createActionButton(
        "選択・移動",
        () => {
          DOM.video.currentTime =
            item.start;

          selectItem(item);
        }
      )
    );

    actions.appendChild(
      createActionButton(
        "削除",
        () => {
          state.selected = item.id;
          deleteSelected();
        },
        "danger"
      )
    );

    row.appendChild(actions);

    objects.appendChild(row);
  });

  const skips = $("skipList");
  skips.replaceChildren();

  if (!state.skips.length) {
    const empty =
      document.createElement("div");

    empty.className = "empty";
    empty.textContent =
      "スキップ範囲はまだありません。";

    skips.appendChild(empty);
  }

  state.skips.forEach((range, index) => {
    const row =
      document.createElement("div");

    row.className = "list-item";

    const label =
      document.createElement("span");

    label.textContent =
      `${formatTime(range.start)}～${formatTime(range.end)}`;

    row.appendChild(label);

    row.appendChild(
      createActionButton(
        "削除",
        () => {
          state.skips.splice(index, 1);

          render();
          saveLocal();
        },
        "danger"
      )
    );

    skips.appendChild(row);
  });
}


/* =========================================================
 * タイムライン
 * ======================================================= */

function renderTracks() {
  const max = duration();

  const tracks = [
    [
      "commentTrack",
      state.items.filter(
        item => item.type === "comment"
      )
    ],
    [
      "boxTrack",
      state.items.filter(
        item => item.type === "box"
      )
    ],
    [
      "skipTrack",
      state.skips
    ]
  ];

  tracks.forEach(
    ([trackId, entries]) => {
      const track = $(trackId);

      track.replaceChildren();

      if (!max) {
        return;
      }

      entries.forEach(entry => {
        const marker =
          document.createElement("span");

        marker.className =
          trackId === "skipTrack"
            ? "skip"
            : entry.type || "";

        if (
          entry.id &&
          entry.id === state.selected
        ) {
          marker.classList.add("current");
        }

        marker.style.left =
          `${clamp(
            entry.start / max * 100,
            0,
            100
          )}%`;

        marker.style.width =
          `${clamp(
            (entry.end - entry.start) /
              max * 100,
            0,
            100
          )}%`;

        marker.title =
          `${formatTime(entry.start)}～${formatTime(entry.end)}`;

        marker.onclick = event => {
          event.stopPropagation();

          DOM.video.currentTime =
            entry.start;

          if (entry.id) {
            selectItem(entry);
          }
        };

        track.appendChild(marker);
      });
    }
  );
}


/* =========================================================
 * 再生
 * ======================================================= */

function refreshPlayback() {
  const max = duration();
  const current =
    DOM.video.currentTime || 0;

  DOM.seek.max = max;
  DOM.seek.value = current;

  DOM.timeReadout.textContent =
    `${formatTime(current)} / ${formatTime(max)}`;

  drawConnectors();
  drawObjects();

  if (DOM.video.paused) {
    return;
  }

  const skip =
    state.skips.find(
      range =>
        current >= range.start &&
        current < range.end
    );

  if (
    skip &&
    Math.abs(state.lastSkipAt - current) > 0.05
  ) {
    state.lastSkipAt = current;
    DOM.video.currentTime = skip.end;
  }
}

DOM.video.addEventListener(
  "loadedmetadata",
  () => {
    fitStage();
    render();
    refreshPlayback();
  }
);

DOM.video.addEventListener(
  "durationchange",
  render
);

DOM.video.addEventListener(
  "timeupdate",
  refreshPlayback
);

DOM.video.addEventListener(
  "seeked",
  refreshPlayback
);

DOM.video.addEventListener(
  "play",
  () => {
    state.lastSkipAt = -1;
  }
);

DOM.video.addEventListener(
  "ended",
  () => {
    state.lastSkipAt = -1;
    refreshPlayback();
  }
);

DOM.seek.oninput = event => {
  DOM.video.currentTime =
    Number(event.target.value);
};

$("back5").onclick = () => {
  DOM.video.currentTime =
    Math.max(
      0,
      DOM.video.currentTime - 5
    );
};

$("forward5").onclick = () => {
  DOM.video.currentTime =
    Math.min(
      duration(),
      DOM.video.currentTime + 5
    );
};


/* =========================================================
 * スキップ
 * ======================================================= */

$("skipFromCurrent").onclick = () => {
  const start =
    DOM.video.currentTime || 0;

  $("skipStart").value =
    start.toFixed(1);

  $("skipEnd").value =
    Math.min(
      duration(),
      start + 5
    ).toFixed(1);
};

$("addSkip").onclick = () => {
  const range = validRange(
    $("skipStart").value,
    $("skipEnd").value
  );

  if (!range) {
    notify(
      "スキップの開始・終了時間を正しく入力してください。"
    );

    return;
  }

  state.skips.push(range);

  state.skips.sort(
    (a, b) => a.start - b.start
  );

  render();
  saveLocal();
};


/* =========================================================
 * 編集状態
 * ======================================================= */

function clearEdits(confirmUser = true) {
  if (
    confirmUser &&
    !confirm(
      "コメント・強調枠・接続線・スキップ範囲をすべて削除しますか？"
    )
  ) {
    return false;
  }

  state.items = [];
  state.skips = [];
  state.connectors = [];

  state.selected = null;
  state.connectMode = false;
  state.connectFrom = null;

  updateConnectButton();

  render();

  if (confirmUser) {
    saveLocal();
  }

  return true;
}

$("clearEdits").onclick = () => {
  clearEdits(true);
};


/* =========================================================
 * 描画統合
 * ======================================================= */

function render() {
  fitStage();
  drawConnectors();
  drawObjects();
  renderLists();
  renderTracks();
  updateSelection();
  updateConnectButton();
}


/* =========================================================
 * プロジェクト保存
 * ======================================================= */

function projectData() {
  return {
    version: PROJECT_VERSION,
    duration: duration(),
    items: clone(state.items),
    skips: clone(state.skips),
    connectors: clone(state.connectors)
  };
}

function saveLocal() {
  clearTimeout(state.saveTimer);

  state.saveTimer = setTimeout(() => {
    try {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify(projectData())
      );
    } catch (error) {
      notify(
        "ブラウザへの自動保存に失敗しました。JSONファイルに保存してください。"
      );
    }
  }, 100);
}

function validateProject(project) {
  if (
    !project ||
    ![1, PROJECT_VERSION].includes(project.version) ||
    !Array.isArray(project.items) ||
    !Array.isArray(project.skips) ||
    !Array.isArray(project.connectors)
  ) {
    throw new Error(
      "編集データの形式が正しくありません。"
    );
  }

  const max = duration();

  if (
    max &&
    project.duration &&
    Math.abs(
      Number(project.duration) - max
    ) > 1
  ) {
    throw new Error(
      "保存時と動画の長さが異なります。同じ録画を開いてください。"
    );
  }

  const ids = new Set();

  for (const item of project.items) {
    if (
      !item ||
      !["comment", "box"].includes(item.type) ||
      typeof item.id !== "string" ||
      ids.has(item.id)
    ) {
      throw new Error(
        "編集要素のデータが正しくありません。"
      );
    }

    const range =
      validRange(item.start, item.end);

    if (!range) {
      throw new Error(
        "編集要素の時間範囲が正しくありません。"
      );
    }

    const geometry = [
      Number(item.x),
      Number(item.y),
      Number(item.w),
      Number(item.h)
    ];

    if (
      !geometry.every(Number.isFinite) ||
      geometry[0] < 0 ||
      geometry[1] < 0 ||
      geometry[2] < 5 ||
      geometry[3] < 5 ||
      geometry[0] + geometry[2] > 100 ||
      geometry[1] + geometry[3] > 100
    ) {
      throw new Error(
        "編集要素の位置・サイズが正しくありません。"
      );
    }

    if (
      item.type === "comment" &&
      (
        typeof item.text !== "string" ||
        !item.text.trim()
      )
    ) {
      throw new Error(
        "コメントの内容が正しくありません。"
      );
    }

    ids.add(item.id);
  }

  for (const range of project.skips) {
    if (
      !range ||
      !validRange(range.start, range.end)
    ) {
      throw new Error(
        "スキップ範囲のデータが正しくありません。"
      );
    }
  }

  const connectorIds = new Set();

  for (const line of project.connectors) {
    if (
      !line ||
      typeof line.id !== "string" ||
      connectorIds.has(line.id) ||
      !ids.has(line.from) ||
      !ids.has(line.to) ||
      line.from === line.to
    ) {
      throw new Error(
        "接続線のデータが正しくありません。"
      );
    }

    if (
      !validRange(line.start, line.end)
    ) {
      throw new Error(
        "接続線の時間範囲が正しくありません。"
      );
    }

    connectorIds.add(line.id);
  }

  return true;
}

function applyProject(project) {
  validateProject(project);

  state.items = clone(project.items);
  state.skips = clone(project.skips);
  state.connectors = clone(project.connectors);

  state.selected = null;
  state.connectMode = false;
  state.connectFrom = null;

  updateConnectorRanges();

  render();
  saveLocal();
}

$("saveProject").onclick = () => {
  if (!state.blob) {
    notify("録画データがありません。");
    return;
  }

  const project = projectData();

  download(
    new Blob(
      [JSON.stringify(project, null, 2)],
      {type: "application/json"}
    ),
    `screen-recording-edits-${fileStamp()}.json`
  );

  notify(
    "編集データを保存しました。",
    true
  );
};

$("loadProject").onclick = () => {
  if (!state.blob) {
    notify(
      "編集データを読み込む前に録画を用意してください。"
    );

    return;
  }

  $("projectFile").click();
};

$("projectFile").onchange = async event => {
  const file =
    event.target.files?.[0];

  if (!file) {
    return;
  }

  try {
    const project =
      JSON.parse(await file.text());

    applyProject(project);

    notify(
      "編集内容を読み込みました。",
      true
    );
  } catch (error) {
    notify(
      `読み込みに失敗しました。${error?.message || ""}`
    );
  } finally {
    event.target.value = "";
  }
};


/* =========================================================
 * WebM
 * ======================================================= */

$("downloadWebm").onclick = () => {
  if (!state.blob) {
    notify("録画データがありません。");
    return;
  }

  download(
    state.blob,
    `screen-recording-${fileStamp()}.webm`
  );
};


/* =========================================================
 * FFmpeg / MP4
 * ======================================================= */

async function getFFmpeg() {
  if (state.ffmpeg) {
    return state.ffmpeg;
  }

  if (
    !window.FFmpeg?.FFmpeg ||
    !window.FFmpegUtil?.toBlobURL
  ) {
    throw new Error(
      "変換用ライブラリを読み込めません。インターネット接続を確認してください。"
    );
  }

  const ffmpeg =
    new window.FFmpeg.FFmpeg();

  const toBlobURL =
    window.FFmpegUtil.toBlobURL;

  const base =
    "https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.6/dist/umd";

  ffmpeg.on(
    "progress",
    ({progress}) => {
      DOM.status.textContent =
        `MP4変換中 ${Math.round(
          clamp(progress, 0, 1) * 100
        )}%`;
    }
  );

  await ffmpeg.load({
    coreURL: await toBlobURL(
      `${base}/ffmpeg-core.js`,
      "text/javascript"
    ),

    wasmURL: await toBlobURL(
      `${base}/ffmpeg-core.wasm`,
      "application/wasm"
    ),

    workerURL: await toBlobURL(
      `${base}/ffmpeg-core.worker.js`,
      "text/javascript"
    )
  });

  state.ffmpeg = ffmpeg;

  return ffmpeg;
}

$("downloadMp4").onclick = async () => {
  if (
    !state.blob ||
    state.converting
  ) {
    return;
  }

  state.converting = true;

  const button =
    $("downloadMp4");

  button.disabled = true;

  try {
    DOM.status.textContent =
      "MP4変換準備中";

    const ffmpeg =
      await getFFmpeg();

    const input =
      await window.FFmpegUtil.fetchFile(
        state.blob
      );

    await ffmpeg.writeFile(
      "input.webm",
      input
    );

    const code =
      await ffmpeg.exec([
        "-i",
        "input.webm",

        "-c:v",
        "libx264",

        "-preset",
        "veryfast",

        "-crf",
        "23",

        "-c:a",
        "aac",

        "-b:a",
        "128k",

        "-movflags",
        "+faststart",

        "output.mp4"
      ]);

    if (code !== 0) {
      throw new Error(
        "動画の変換処理が完了しませんでした。"
      );
    }

    const data =
      await ffmpeg.readFile(
        "output.mp4"
      );

    download(
      new Blob(
        [data],
        {type: "video/mp4"}
      ),
      `screen-recording-${fileStamp()}.mp4`
    );

    await Promise.allSettled([
      ffmpeg.deleteFile("input.webm"),
      ffmpeg.deleteFile("output.mp4")
    ]);

    notify(
      "MP4をダウンロードしました。",
      true
    );

  } catch (error) {
    notify(
      `MP4変換に失敗しました。${error?.message || ""}`
    );

  } finally {
    DOM.status.textContent =
      "録画終了";

    button.disabled = false;
    state.converting = false;
  }
};


/* =========================================================
 * コンテキストメニュー
 * ======================================================= */

DOM.stage.addEventListener(
  "contextmenu",
  event => {
    if (!state.blob) {
      return;
    }

    event.preventDefault();

    const rect =
      DOM.stage.getBoundingClientRect();

    state.contextPoint = {
      x: clamp(
        (event.clientX - rect.left) /
          rect.width * 100,
        0,
        70
      ),

      y: clamp(
        (event.clientY - rect.top) /
          rect.height * 100,
        0,
        70
      )
    };

    DOM.contextMenu.style.left =
      `${clamp(
        event.clientX,
        0,
        innerWidth - 190
      )}px`;

    DOM.contextMenu.style.top =
      `${clamp(
        event.clientY,
        0,
        innerHeight - 130
      )}px`;

    DOM.contextMenu.style.display =
      "block";
  }
);

$("contextMenu").onclick = event => {
  const action =
    event.target.closest(
      "button"
    )?.dataset.action;

  if (
    action === "comment" ||
    action === "box"
  ) {
    addItem(
      action,
      state.contextPoint
    );
  }

  if (action === "connect") {
    $("connect").click();
  }

  DOM.contextMenu.style.display =
    "none";
};

document.addEventListener(
  "pointerdown",
  event => {
    if (
      !DOM.contextMenu.contains(
        event.target
      )
    ) {
      DOM.contextMenu.style.display =
        "none";
    }
  }
);


/* =========================================================
 * 初期状態復元
 * ======================================================= */

function restoreLocal() {
  try {
    const saved =
      localStorage.getItem(
        STORAGE_KEY
      );

    if (!saved) {
      return;
    }

    const project =
      JSON.parse(saved);

    /*
     * 動画ロード前はduration()が0なので、
     * まず構造だけ検証する。
     */
    if (
      !project ||
      !Array.isArray(project.items) ||
      !Array.isArray(project.skips) ||
      !Array.isArray(project.connectors)
    ) {
      throw new Error(
        "保存データの構造が不正です。"
      );
    }

    state.items =
      clone(project.items);

    state.skips =
      clone(project.skips);

    state.connectors =
      clone(project.connectors);

    render();

  } catch (error) {
    state.items = [];
    state.skips = [];
    state.connectors = [];

    notify(
      "前回の編集内容を復元できませんでした。"
    );
  }
}

restoreLocal();

updateRecordingButtons();

render();

</script>
</body>
</html>

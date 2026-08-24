<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート作成</title>

<!-- SortableJS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<style>
:root {
  --primary: #2563eb;
  --primary-dark: #1d4ed8;
  --danger: #dc2626;
  --bg: #f5f7fb;
  --card: #ffffff;
  --border: #dfe3ea;
  --text: #1f2937;
  --muted: #6b7280;
  --group: #eef4ff;
  --shadow: 0 2px 10px rgba(0,0,0,.06);
}

* {
  box-sizing: border-box;
}

body {
  margin: 0;
  background: var(--bg);
  color: var(--text);
  font-family:
    -apple-system,
    BlinkMacSystemFont,
    "Segoe UI",
    "Noto Sans JP",
    sans-serif;
}

button,
input,
textarea,
select {
  font: inherit;
}

button {
  cursor: pointer;
}

.header {
  position: sticky;
  top: 0;
  z-index: 20;
  height: 64px;
  background: #fff;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 28px;
}

.header-title {
  font-size: 20px;
  font-weight: 700;
}

.header-actions {
  display: flex;
  gap: 10px;
}

.btn {
  border: 1px solid var(--border);
  background: #fff;
  color: var(--text);
  border-radius: 7px;
  padding: 9px 16px;
  font-weight: 600;
}

.btn:hover {
  background: #f8fafc;
}

.btn-primary {
  background: var(--primary);
  color: #fff;
  border-color: var(--primary);
}

.btn-primary:hover {
  background: var(--primary-dark);
}

.btn-danger {
  color: var(--danger);
  border-color: #fecaca;
  background: #fff;
}

.btn-small {
  padding: 6px 10px;
  font-size: 13px;
}

.container {
  width: min(1100px, calc(100% - 32px));
  margin: 28px auto 80px;
}

.basic-info {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 20px;
  box-shadow: var(--shadow);
  margin-bottom: 20px;
}

.label {
  display: block;
  font-size: 13px;
  font-weight: 700;
  color: var(--muted);
  margin-bottom: 7px;
}

.title-input {
  width: 100%;
  border: 0;
  border-bottom: 2px solid #cbd5e1;
  padding: 8px 2px;
  outline: none;
  font-size: 23px;
  font-weight: 700;
}

.title-input:focus {
  border-color: var(--primary);
}

.groups {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.group {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 10px;
  box-shadow: var(--shadow);
  overflow: hidden;
}

.group-header {
  background: var(--group);
  min-height: 58px;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
}

.drag-handle {
  color: #94a3b8;
  font-size: 21px;
  cursor: grab;
  user-select: none;
  letter-spacing: -4px;
  width: 28px;
}

.drag-handle:active {
  cursor: grabbing;
}

.group-title {
  flex: 1;
  border: 0;
  border-bottom: 1px solid transparent;
  background: transparent;
  padding: 7px 4px;
  font-size: 17px;
  font-weight: 700;
  outline: none;
}

.group-title:focus {
  border-bottom-color: var(--primary);
}

.group-body {
  padding: 16px;
  min-height: 80px;
}

.question-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.question {
  border: 1px solid var(--border);
  border-radius: 9px;
  background: #fff;
  padding: 16px;
  transition: box-shadow .15s, transform .15s;
}

.question:hover {
  box-shadow: 0 3px 14px rgba(0,0,0,.07);
}

.question-top {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}

.question-number {
  color: var(--primary);
  font-weight: 800;
  white-space: nowrap;
  padding-top: 8px;
}

.question-content {
  flex: 1;
}

.question-text {
  width: 100%;
  border: 0;
  border-bottom: 1px solid #cbd5e1;
  padding: 7px 2px;
  font-size: 16px;
  outline: none;
  margin-bottom: 14px;
}

.question-text:focus {
  border-color: var(--primary);
}

.question-actions {
  display: flex;
  gap: 6px;
}

.question-options {
  margin-top: 12px;
}

.option-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 8px 0;
}

.option-symbol {
  color: var(--muted);
}

.option-input {
  flex: 1;
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 7px 9px;
}

.remove-option {
  border: 0;
  background: transparent;
  color: #94a3b8;
  font-size: 18px;
}

.remove-option:hover {
  color: var(--danger);
}

.question-settings {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin-top: 15px;
  padding-top: 13px;
  border-top: 1px solid #eef0f3;
}

.select {
  border: 1px solid var(--border);
  background: #fff;
  border-radius: 6px;
  padding: 7px 10px;
}

.required {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #4b5563;
}

.branch {
  margin-top: 12px;
  padding: 12px;
  background: #f8fafc;
  border-radius: 7px;
  border: 1px solid #e5e7eb;
}

.branch-title {
  font-size: 12px;
  color: var(--muted);
  font-weight: 700;
  margin-bottom: 8px;
}

.add-question-area {
  margin-top: 14px;
}

.empty {
  text-align: center;
  color: #94a3b8;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  padding: 22px;
}

.footer-actions {
  margin-top: 22px;
  display: flex;
  justify-content: center;
  gap: 10px;
}

.sortable-ghost {
  opacity: .35;
  background: #dbeafe;
}

.sortable-drag {
  box-shadow: 0 12px 30px rgba(0,0,0,.15);
}

/* Toast */
.toast {
  position: fixed;
  right: 24px;
  bottom: 24px;
  z-index: 100;
  background: #111827;
  color: white;
  padding: 12px 18px;
  border-radius: 8px;
  box-shadow: 0 6px 25px rgba(0,0,0,.2);
  opacity: 0;
  transform: translateY(15px);
  pointer-events: none;
  transition: .25s;
}

.toast.show {
  opacity: 1;
  transform: translateY(0);
}

/* Modal */
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 50;
  background: rgba(15,23,42,.58);
  display: none;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.modal-backdrop.open {
  display: flex;
}

.modal {
  width: min(1000px, 100%);
  height: min(850px, 92vh);
  background: #fff;
  border-radius: 12px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,.25);
}

.modal-header {
  height: 60px;
  flex-shrink: 0;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 18px;
}

.preview-title {
  font-weight: 700;
}

.device-switch {
  display: flex;
  gap: 4px;
  background: #f1f5f9;
  padding: 4px;
  border-radius: 7px;
}

.device-btn {
  border: 0;
  background: transparent;
  padding: 6px 12px;
  border-radius: 5px;
  color: var(--muted);
}

.device-btn.active {
  background: #fff;
  color: var(--text);
  box-shadow: 0 1px 3px rgba(0,0,0,.1);
}

.modal-close {
  border: 0;
  background: transparent;
  font-size: 24px;
  color: var(--muted);
}

.preview-area {
  flex: 1;
  overflow: auto;
  background: #eef1f5;
  padding: 30px;
}

.preview-device {
  min-height: 100%;
  margin: auto;
  background: #fff;
  transition: width .25s;
  box-shadow: 0 4px 18px rgba(0,0,0,.08);
}

.preview-device.pc {
  width: 100%;
}

.preview-device.mobile {
  width: 390px;
  max-width: 100%;
}

.preview-content {
  padding: 32px;
}

.preview-content h1 {
  margin-top: 0;
  font-size: 25px;
}

.preview-group {
  margin-top: 30px;
}

.preview-group h2 {
  font-size: 18px;
  border-bottom: 1px solid var(--border);
  padding-bottom: 9px;
}

.preview-question {
  margin: 22px 0;
}

.preview-question-title {
  font-weight: 700;
  margin-bottom: 10px;
}

.preview-required {
  color: var(--danger);
  font-size: 12px;
  margin-left: 5px;
}

.preview-option {
  margin: 8px 0;
}

.preview-textarea {
  width: 100%;
  min-height: 110px;
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 10px;
  resize: vertical;
}

.preview-submit {
  width: 100%;
  margin-top: 20px;
}

/* Confirm */
.confirm {
  width: min(420px, 100%);
  height: auto;
  padding: 25px;
}

.confirm h2 {
  margin-top: 0;
}

.confirm-buttons {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 22px;
}

/* Responsive */
@media (max-width: 700px) {
  .header {
    height: auto;
    min-height: 64px;
    padding: 12px 14px;
    gap: 10px;
    align-items: flex-start;
  }

  .header-title {
    font-size: 17px;
    padding-top: 8px;
  }

  .header-actions {
    flex-wrap: wrap;
    justify-content: flex-end;
  }

  .header-actions .btn {
    padding: 7px 9px;
    font-size: 12px;
  }

  .container {
    width: calc(100% - 20px);
    margin-top: 14px;
  }

  .group-header {
    padding: 9px 10px;
  }

  .group-body {
    padding: 10px;
  }

  .question {
    padding: 12px;
  }

  .question-settings {
    align-items: flex-start;
    flex-direction: column;
  }

  .preview-area {
    padding: 10px;
  }

  .preview-content {
    padding: 20px;
  }

  .modal-backdrop {
    padding: 8px;
  }

  .modal {
    height: 96vh;
  }
}
</style>
</head>

<body>

<header class="header">
  <div class="header-title">アンケート作成</div>

  <div class="header-actions">
    <button class="btn" onclick="openPreview()">プレビュー</button>
    <button class="btn" onclick="cancelEditing()">キャンセル</button>
    <button class="btn btn-primary" onclick="saveAndBack()">
      保存して一覧へ戻る
    </button>
  </div>
</header>

<main class="container">

  <section class="basic-info">
    <label class="label">アンケートタイトル</label>
    <input
      id="surveyTitle"
      class="title-input"
      value="顧客満足度調査｜サービスに関するアンケート"
      placeholder="アンケートタイトルを入力してください"
    >
  </section>

  <div id="groups" class="groups"></div>

  <div class="footer-actions">
    <button class="btn btn-primary" onclick="addGroup()">
      ＋ グループを追加
    </button>
  </div>

</main>

<div id="toast" class="toast"></div>

<!-- Preview Modal -->
<div id="previewModal" class="modal-backdrop">
  <div class="modal">

    <div class="modal-header">
      <div class="preview-title">プレビュー</div>

      <div class="device-switch">
        <button
          id="pcBtn"
          class="device-btn active"
          onclick="setDevice('pc')"
        >
          PC表示
        </button>

        <button
          id="mobileBtn"
          class="device-btn"
          onclick="setDevice('mobile')"
        >
          スマートフォン表示
        </button>
      </div>

      <button class="modal-close" onclick="closePreview()">×</button>
    </div>

    <div class="preview-area">
      <div id="previewDevice" class="preview-device pc">
        <div id="previewContent" class="preview-content"></div>
      </div>
    </div>

  </div>
</div>

<!-- Confirm Modal -->
<div id="confirmModal" class="modal-backdrop">
  <div class="modal confirm">
    <h2 id="confirmTitle">確認</h2>
    <p id="confirmMessage"></p>

    <div class="confirm-buttons">
      <button class="btn" onclick="closeConfirm()">戻る</button>
      <button id="confirmAction" class="btn btn-danger">
        実行する
      </button>
    </div>
  </div>
</div>

<script>
let state = {
  title: "顧客満足度調査｜サービスに関するアンケート",
  groups: [
    {
      id: uid(),
      title: "基本情報",
      questions: [
        {
          id: uid(),
          text: "当サービスの総合的な満足度を教えてください。",
          type: "single",
          required: true,
          options: [
            "非常に満足",
            "満足",
            "どちらともいえない",
            "不満",
            "非常に不満"
          ],
          branches: {}
        },
        {
          id: uid(),
          text: "当サービスを利用したきっかけを教えてください。",
          type: "multiple",
          required: false,
          options: [
            "Web検索",
            "SNS",
            "知人からの紹介",
            "広告",
            "その他"
          ],
          branches: {}
        }
      ]
    },
    {
      id: uid(),
      title: "ご意見・ご要望",
      questions: [
        {
          id: uid(),
          text: "サービスについてご意見・ご要望があればお聞かせください。",
          type: "textarea",
          required: false,
          options: [],
          branches: {}
        }
      ]
    }
  ]
};

let confirmCallback = null;

function uid() {
  return Math.random().toString(36).slice(2, 10);
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function render() {
  const container = document.getElementById("groups");

  state.title = document.getElementById("surveyTitle").value;

  container.innerHTML = state.groups.map((group, groupIndex) => {

    const questions = group.questions.length
      ? group.questions.map(q => questionHtml(q)).join("")
      : `<div class="empty">質問がありません。質問を追加してください。</div>`;

    return `
      <section class="group" data-group-id="${group.id}">

        <div class="group-header">

          <div class="drag-handle group-handle" title="ドラッグして並び替え">
            ⠿
          </div>

          <input
            class="group-title"
            value="${escapeHtml(group.title)}"
            data-group-id="${group.id}"
            onchange="updateGroupTitle(this)"
          >

          <button
            class="btn btn-danger btn-small"
            onclick="deleteGroup('${group.id}')"
          >
            グループ削除
          </button>

        </div>

        <div class="group-body">

          <div
            class="question-list"
            data-group-id="${group.id}"
          >
            ${questions}
          </div>

          <div class="add-question-area">
            <button
              class="btn btn-small"
              onclick="addQuestion('${group.id}')"
            >
              ＋ 質問を追加
            </button>
          </div>

        </div>
      </section>
    `;
  }).join("");

  initSortable();
  renumberQuestions();
}

function questionHtml(q) {
  let answerHtml = "";

  if (q.type === "single" || q.type === "multiple") {
    answerHtml = `
      <div class="question-options">
        ${q.options.map((option, index) => `
          <div class="option-row">

            <span class="option-symbol">
              ${q.type === "single" ? "○" : "□"}
            </span>

            <input
              class="option-input"
              value="${escapeHtml(option)}"
              onchange="updateOption('${q.id}', ${index}, this.value)"
            >

            <button
              class="remove-option"
              onclick="removeOption('${q.id}', ${index})"
              title="削除"
            >
              ×
            </button>

          </div>

          ${
            q.type === "single"
            ? `
              <div class="branch">
                <div class="branch-title">
                  「${escapeHtml(option)}」を選択した場合の分岐
                </div>

                <select
                  class="select"
                  onchange="updateBranch('${q.id}', ${index}, this.value)"
                >
                  ${branchOptions(q, q.branches?.[index])}
                </select>
              </div>
            `
            : ""
          }
        `).join("")}

        <button
          class="btn btn-small"
          onclick="addOption('${q.id}')"
        >
          ＋ 選択肢を追加
        </button>
      </div>
    `;
  }

  if (q.type === "textarea") {
    answerHtml = `
      <div class="question-options">
        <textarea
          class="preview-textarea"
          placeholder="回答者が自由に入力します"
          disabled
        ></textarea>
      </div>
    `;
  }

  return `
    <article class="question" data-question-id="${q.id}">

      <div class="question-top">

        <div class="drag-handle question-handle" title="ドラッグして移動">
          ⠿
        </div>

        <div class="question-number"></div>

        <div class="question-content">

          <input
            class="question-text"
            value="${escapeHtml(q.text)}"
            placeholder="質問文を入力してください"
            onchange="updateQuestionText('${q.id}', this.value)"
          >

          ${answerHtml}

          <div class="question-settings">

            <select
              class="select"
              onchange="changeQuestionType('${q.id}', this.value)"
            >
              <option value="single" ${q.type === "single" ? "selected" : ""}>
                単一選択
              </option>
              <option value="multiple" ${q.type === "multiple" ? "selected" : ""}>
                複数選択
              </option>
              <option value="textarea" ${q.type === "textarea" ? "selected" : ""}>
                自由記述
              </option>
            </select>

            <label class="required">
              <input
                type="checkbox"
                ${q.required ? "checked" : ""}
                onchange="toggleRequired('${q.id}', this.checked)"
              >
              必須回答
            </label>

            <button
              class="btn btn-danger btn-small"
              onclick="deleteQuestion('${q.id}')"
            >
              質問を削除
            </button>

          </div>

        </div>

      </div>

    </article>
  `;
}

function branchOptions(question, selected) {
  let html = `<option value="">次の質問へ</option>`;

  state.groups.forEach((group, gi) => {
    group.questions.forEach((q, qi) => {
      if (q.id === question.id) return;

      const number = getQuestionNumber(q.id);

      html += `
        <option
          value="${q.id}"
          ${selected === q.id ? "selected" : ""}
        >
          Q${number}. ${escapeHtml(q.text || "（未入力）")}
        </option>
      `;
    });
  });

  html += `<option value="end" ${selected === "end" ? "selected" : ""}>
    アンケート終了
  </option>`;

  return html;
}

function getQuestionNumber(id) {
  let number = 0;

  for (const group of state.groups) {
    for (const q of group.questions) {
      number++;

      if (q.id === id) {
        return number;
      }
    }
  }

  return 0;
}

function renumberQuestions() {
  let number = 1;

  document.querySelectorAll(".question").forEach(el => {
    const label = el.querySelector(".question-number");
    label.textContent = `Q${number}.`;
    number++;
  });
}

function findQuestion(id) {
  for (const group of state.groups) {
    const question = group.questions.find(q => q.id === id);

    if (question) {
      return {
        question,
        group
      };
    }
  }

  return null;
}

function updateGroupTitle(input) {
  const group = state.groups.find(g => g.id === input.dataset.groupId);

  if (group) {
    group.title = input.value;
  }
}

function updateQuestionText(id, value) {
  const found = findQuestion(id);

  if (found) {
    found.question.text = value;
  }
}

function toggleRequired(id, checked) {
  const found = findQuestion(id);

  if (found) {
    found.question.required = checked;
  }
}

function changeQuestionType(id, type) {
  const found = findQuestion(id);

  if (!found) return;

  found.question.type = type;

  if (type === "textarea") {
    found.question.options = [];
  } else if (!found.question.options.length) {
    found.question.options = ["選択肢1", "選択肢2"];
  }

  render();
}

function updateOption(questionId, index, value) {
  const found = findQuestion(questionId);

  if (found) {
    found.question.options[index] = value;
  }
}

function addOption(questionId) {
  const found = findQuestion(questionId);

  if (!found) return;

  found.question.options.push(
    `選択肢${found.question.options.length + 1}`
  );

  render();
}

function removeOption(questionId, index) {
  const found = findQuestion(questionId);

  if (!found) return;

  if (found.question.options.length <= 1) {
    showToast("選択肢は最低1つ必要です");
    return;
  }

  found.question.options.splice(index, 1);
  render();
}

function updateBranch(questionId, optionIndex, target) {
  const found = findQuestion(questionId);

  if (!found) return;

  if (!found.question.branches) {
    found.question.branches = {};
  }

  found.question.branches[optionIndex] = target;
}

function addGroup() {
  state.groups.push({
    id: uid(),
    title: `新しいグループ`,
    questions: []
  });

  render();

  setTimeout(() => {
    const groups = document.querySelectorAll(".group");
    groups[groups.length - 1]?.scrollIntoView({
      behavior: "smooth",
      block: "center"
    });
  }, 50);
}

function deleteGroup(groupId) {
  const group = state.groups.find(g => g.id === groupId);

  if (!group) return;

  openConfirm(
    "グループを削除しますか？",
    `「${group.title}」と、その中に含まれる質問をすべて削除します。この操作は元に戻せません。`,
    () => {
      state.groups = state.groups.filter(g => g.id !== groupId);
      render();
      showToast("グループを削除しました");
    }
  );
}

function addQuestion(groupId) {
  const group = state.groups.find(g => g.id === groupId);

  if (!group) return;

  group.questions.push({
    id: uid(),
    text: "",
    type: "single",
    required: false,
    options: ["選択肢1", "選択肢2"],
    branches: {}
  });

  render();

  setTimeout(() => {
    const questions = document.querySelectorAll(
      `.question-list[data-group-id="${groupId}"] .question`
    );

    questions[questions.length - 1]?.scrollIntoView({
      behavior: "smooth",
      block: "center"
    });
  }, 50);
}

function deleteQuestion(questionId) {
  openConfirm(
    "質問を削除しますか？",
    "削除した質問は元に戻せません。",
    () => {
      for (const group of state.groups) {
        const index = group.questions.findIndex(
          q => q.id === questionId
        );

        if (index !== -1) {
          group.questions.splice(index, 1);
          break;
        }
      }

      render();
      showToast("質問を削除しました");
    }
  );
}

function initSortable() {

  // グループ並び替え
  new Sortable(document.getElementById("groups"), {
    animation: 180,
    handle: ".group-handle",
    ghostClass: "sortable-ghost",
    onEnd: evt => {
      const moved = state.groups.splice(evt.oldIndex, 1)[0];

      state.groups.splice(evt.newIndex, 0, moved);

      render();
    }
  });

  // 質問並び替え
  document.querySelectorAll(".question-list").forEach(list => {

    new Sortable(list, {
      group: "questions",
      animation: 180,
      handle: ".question-handle",
      ghostClass: "sortable-ghost",

      onEnd: evt => {

        const questionId =
          evt.item.dataset.questionId;

        const fromGroupId =
          evt.from.dataset.groupId;

        const toGroupId =
          evt.to.dataset.groupId;

        const fromGroup =
          state.groups.find(g => g.id === fromGroupId);

        const toGroup =
          state.groups.find(g => g.id === toGroupId);

        if (!fromGroup || !toGroup) return;

        const oldIndex =
          fromGroup.questions.findIndex(
            q => q.id === questionId
          );

        if (oldIndex !== -1) {
          const [question] =
            fromGroup.questions.splice(oldIndex, 1);

          /*
           * SortableJSはDOM上で移動済みなので、
           * state側では移動後の位置へ挿入する。
           */
          const newIndex =
            Array.from(evt.to.children)
              .findIndex(
                el => el.dataset.questionId === questionId
              );

          toGroup.questions.splice(
            Math.max(0, newIndex),
            0,
            question
          );
        }

        render();
      }
    });

  });
}

/* ==========================
   Preview
========================== */

function openPreview() {
  // 最新の入力値を取得
  state.title =
    document.getElementById("surveyTitle").value;

  renderPreview();

  document
    .getElementById("previewModal")
    .classList.add("open");
}

function closePreview() {
  document
    .getElementById("previewModal")
    .classList.remove("open");
}

function setDevice(device) {
  const preview = document.getElementById("previewDevice");
  const pcBtn = document.getElementById("pcBtn");
  const mobileBtn = document.getElementById("mobileBtn");

  preview.className =
    `preview-device ${device}`;

  pcBtn.classList.toggle(
    "active",
    device === "pc"
  );

  mobileBtn.classList.toggle(
    "active",
    device === "mobile"
  );
}

function renderPreview() {
  const content =
    document.getElementById("previewContent");

  let questionNumber = 0;

  content.innerHTML = `
    <h1>${escapeHtml(state.title || "アンケート")}</h1>

    ${state.groups.map(group => {

      const questions = group.questions.map(q => {

        questionNumber++;

        return previewQuestionHtml(
          q,
          questionNumber
        );

      }).join("");

      return `
        <section class="preview-group">

          <h2>${escapeHtml(group.title)}</h2>

          ${questions || `
            <p style="color:#94a3b8">
              質問はありません。
            </p>
          `}

        </section>
      `;

    }).join("")}

    <button
      class="btn btn-primary preview-submit"
      onclick="previewSubmit()"
    >
      送信
    </button>
  `;
}

function previewQuestionHtml(q, number) {

  let answer = "";

  if (q.type === "single") {
    answer = q.options.map(option => `
      <label class="preview-option">
        <input type="radio" name="q${number}">
        ${escapeHtml(option)}
      </label>
    `).join("");
  }

  if (q.type === "multiple") {
    answer = q.options.map(option => `
      <label class="preview-option">
        <input type="checkbox">
        ${escapeHtml(option)}
      </label>
    `).join("");
  }

  if (q.type === "textarea") {
    answer = `
      <textarea
        class="preview-textarea"
        placeholder="回答を入力してください"
      ></textarea>
    `;
  }

  return `
    <div class="preview-question">

      <div class="preview-question-title">
        Q${number}. ${escapeHtml(q.text || "（質問文未入力）")}

        ${
          q.required
          ? `<span class="preview-required">必須</span>`
          : ""
        }
      </div>

      ${answer}

    </div>
  `;
}

function previewSubmit() {
  alert(
    "※これはプレビュー表示のため、実際の送信は行われません。"
  );
}

/* ==========================
   Save / Cancel
========================== */

function saveAndBack() {

  state.title =
    document.getElementById("surveyTitle").value;

  // 実際のシステムではここでAPIへPOST
  console.log(
    "POST /api/surveys",
    JSON.stringify(state, null, 2)
  );

  showToast("アンケートを保存しました");

  setTimeout(() => {
    // モックなので一覧画面への遷移を再現
    alert(
      "保存完了\n\n本番ではここでアンケート一覧画面へ遷移します。"
    );
  }, 500);
}

function cancelEditing() {
  openConfirm(
    "編集を破棄しますか？",
    "保存していない変更はすべて破棄されます。",
    () => {
      alert(
        "編集を破棄しました。\n\n本番ではアンケート一覧画面へ遷移します。"
      );
    }
  );
}

/* ==========================
   Confirm
========================== */

function openConfirm(title, message, callback) {
  document.getElementById("confirmTitle").textContent =
    title;

  document.getElementById("confirmMessage").textContent =
    message;

  confirmCallback = callback;

  document
    .getElementById("confirmModal")
    .classList.add("open");

  document.getElementById("confirmAction").onclick =
    () => {
      closeConfirm();

      if (confirmCallback) {
        confirmCallback();
      }
    };
}

function closeConfirm() {
  document
    .getElementById("confirmModal")
    .classList.remove("open");

  confirmCallback = null;
}

/* ==========================
   Toast
========================== */

let toastTimer;

function showToast(message) {
  const toast =
    document.getElementById("toast");

  toast.textContent = message;
  toast.classList.add("show");

  clearTimeout(toastTimer);

  toastTimer = setTimeout(() => {
    toast.classList.remove("show");
  }, 2200);
}

/* ==========================
   Initial render
========================== */

document
  .getElementById("surveyTitle")
  .addEventListener("input", () => {
    state.title =
      document.getElementById("surveyTitle").value;
  });

render();

</script>

</body>
</html>
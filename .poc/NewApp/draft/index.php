<?php
declare(strict_types=1);

// セッションの開始
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// レスポンスヘッダー設定
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>アンケート業務運営アプリ</title>
  <style>
    :root {
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --bg: #f8fafc;
      --surface: #ffffff;
      --border: #e2e8f0;
      --text: #1e293b;
      --text-muted: #64748b;
      --success: #16a34a;
      --danger: #dc2626;
      --warning: #d97706;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
    body { background-color: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }

    /* ヘッダー */
    header { background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; }
    .header-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 0 1rem; }
    .logo { font-weight: bold; font-size: 1.1rem; color: var(--primary); padding: 1rem 0; cursor: pointer; }
    nav ul { display: flex; list-style: none; gap: 0.5rem; }
    nav button { background: none; border: none; padding: 1rem 0.75rem; font-size: 0.95rem; cursor: pointer; color: var(--text-muted); font-weight: 500; border-bottom: 2px solid transparent; }
    nav button.active { color: var(--primary); border-bottom-color: var(--primary); }
    nav button:hover:not(.active) { color: var(--text); }

    /* サブナビ（個別アンケート管理用） */
    .sub-nav { background: #f1f5f9; border-bottom: 1px solid var(--border); padding: 0.5rem 1rem; }
    .sub-nav-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .sub-nav-title { font-weight: 600; font-size: 0.9rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem; }
    .sub-nav-title span { color: var(--text); font-size: 1rem; }
    .sub-nav ul { display: flex; list-style: none; gap: 0.5rem; }
    .sub-nav button { background: var(--surface); border: 1px solid var(--border); border-radius: 4px; padding: 0.35rem 0.75rem; font-size: 0.85rem; cursor: pointer; }
    .sub-nav button.active { background: var(--primary); color: #fff; border-color: var(--primary); }

    /* メインコンテンツ */
    main { max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem; flex: 1; width: 100%; }
    .card { background: var(--surface); border-radius: 8px; border: 1px solid var(--border); padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; }
    .card-title { font-size: 1.15rem; font-weight: 600; }

    /* ボタン・バッジ・フォームパーツ */
    .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.9rem; font-weight: 500; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; }
    .btn-primary { background: var(--primary); color: #fff; }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-outline { background: var(--surface); border-color: var(--border); color: var(--text); }
    .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }
    .btn-danger { background: var(--danger); color: #fff; }
    .btn-danger-outline { border-color: var(--danger); color: var(--danger); background: transparent; }
    .btn-danger-outline:hover { background: #fee2e2; }
    .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.8rem; }

    .badge { padding: 0.25rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
    .badge-draft { background: #e2e8f0; color: #475569; }
    .badge-active { background: #dcfce7; color: #15803d; }
    .badge-closed { background: #fee2e2; color: #b91c1c; }

    .form-group { margin-bottom: 1rem; }
    .form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; }
    .form-control { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; }
    .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(37,99,235,0.1); }
    .form-row { display: flex; gap: 1rem; }
    .form-row .form-group { flex: 1; }

    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
    th, td { padding: 0.75rem; border-bottom: 1px solid var(--border); }
    th { background: #f8fafc; font-weight: 600; color: var(--text-muted); }

    /* アンケート作成用グループ・質問カード */
    .group-card { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem; }
    .question-card { background: #ffffff; border: 1px solid var(--border); border-radius: 6px; padding: 1rem; margin-bottom: 0.75rem; }
    .choice-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }

    /* トースト通知 & モーダル */
    .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #334155; color: #fff; padding: 0.75rem 1.25rem; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 1000; display: none; }
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center; z-index: 500; }
    .modal { background: #fff; width: 90%; max-width: 500px; border-radius: 8px; padding: 1.5rem; }
  </style>
</head>
<body>

  <!-- メインヘッダー -->
  <header id="app-header">
    <div class="header-container">
      <div class="logo" onclick="navigate('survey-list')">📋 アンケート業務運営アプリ</div>
      <nav>
        <ul>
          <li><button id="nav-btn-survey-list" class="active" onclick="navigate('survey-list')">アンケート一覧</button></li>
          <li><button id="nav-btn-survey-editor" onclick="navigate('survey-editor', null)">アンケート作成</button></li>
          <li><button id="nav-btn-customer-list" onclick="navigate('customer-list')">顧客一覧</button></li>
          <li><button id="nav-btn-settings" onclick="navigate('settings')">設定</button></li>
        </ul>
      </nav>
    </div>
    <!-- 個別アンケート用サブナビゲーション -->
    <div id="sub-nav-bar" class="sub-nav" style="display: none;">
      <div class="sub-nav-container">
        <div class="sub-nav-title">選択中: <span id="current-survey-name">アンケート名</span></div>
        <ul>
          <li><button id="subnav-btn-detail" class="active" onclick="navigateSub('detail')">アンケート内容</button></li>
          <li><button id="subnav-btn-send" onclick="navigateSub('send')">送信</button></li>
          <li><button id="subnav-btn-status" onclick="navigateSub('status')">回答状況</button></li>
          <li><button id="subnav-btn-result" onclick="navigateSub('result')">回答結果</button></li>
          <li><button class="btn btn-outline btn-sm" onclick="previewRespondent()">回答画面プレビュー</button></li>
        </ul>
      </div>
    </div>
  </header>

  <!-- メイン画面表示エリア -->
  <main id="main-content"></main>

  <!-- トースト通知 -->
  <div id="toast" class="toast"></div>

  <!-- 確認モーダル -->
  <div id="modal" class="modal-overlay">
    <div class="modal">
      <h3 id="modal-title" style="margin-bottom: 0.75rem;">確認</h3>
      <div id="modal-body" style="font-size: 0.9rem; margin-bottom: 1.25rem;"></div>
      <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
        <button class="btn btn-outline" onclick="closeModal()">キャンセル</button>
        <button id="modal-confirm-btn" class="btn btn-primary">実行</button>
      </div>
    </div>
  </div>

  <script>
    // --- 状態管理 ---
    const state = {
      currentView: 'survey-list',
      currentSubView: 'detail',
      selectedSurveyId: 1,
      settings: {
        kintone: { host: 'example.cybozu.com', appId: '101', login: 'admin', pass: '******', proxyHost: 'proxy.corp.local', proxyPort: '8080' },
        smtp: { host: 'smtp.example.com', port: '587', secure: 'STARTTLS', user: 'survey@example.com', pass: '******', fromEmail: 'noreply@example.com', fromName: 'アンケート運営事務局' }
      },
      customers: [
        { id: 1, name: '山田 太郎', email: 'yamada@example.com', company: '株式会社A' },
        { id: 2, name: '佐藤 花子', email: 'sato@example.com', company: '株式会社B' },
        { id: 3, name: '鈴木 一郎', email: 'suzuki@example.com', company: '合同会社C' },
        { id: 4, name: '田中 次郎', email: 'tanaka@example.com', company: '株式会社D' }
      ],
      surveys: [
        {
          id: 1,
          name: '新サービス利用満足度調査 2026',
          description: '弊社新サービスをご利用のお客様を対象にしたアンケートです。',
          status: '公開中',
          createdAt: '2026-09-01',
          startDate: '2026-09-01',
          endDate: '2026-10-15',
          updatedAt: '2026-09-20',
          numberingFormat: 'group',
          groups: [
            {
              id: 'g1',
              name: '基本情報',
              questions: [
                { id: 'q1', text: 'ご契約プランを選択してください', type: 'single', required: true, choices: ['エントリー', 'スタンダード', 'プレミアム'], branches: {} },
                { id: 'q2', text: 'サービスの利用頻度を教えてください', type: 'single', required: true, choices: ['毎日', '週2〜3回', '月数回', 'ほとんど使わない'], branches: {'ほとんど使わない': 'q4'} }
              ]
            },
            {
              id: 'g2',
              name: '機能の満足度について',
              questions: [
                { id: 'q3', text: 'よく利用する機能をすべて選択してください', type: 'multiple', required: false, choices: ['ダッシュボード', '帳票出力', 'データ連携', 'ユーザー管理'], branches: {} },
                { id: 'q4', text: 'サービスに対するご意見・ご要望をご記入ください', type: 'text', required: false, choices: [], branches: {} }
              ]
            }
          ],
          stats: { sent: 4, answered: 3, responses: [
            { q1: 'スタンダード', q2: '毎日', q3: ['ダッシュボード', 'データ連携'], q4: '非常に使いやすいです。' },
            { q1: 'プレミアム', q2: '週2〜3回', q3: ['ダッシュボード'], q4: 'UIが改善されると嬉しいです。' },
            { q1: 'エントリー', q2: 'ほとんど使わない', q3: [], q4: '使い方が分かりづらかった。' }
          ]}
        }
      ]
    };

    // --- 汎用機能 ---
    function showToast(msg) {
      const t = document.getElementById('toast');
      t.innerText = msg;
      t.style.display = 'block';
      setTimeout(() => { t.style.display = 'none'; }, 3000);
    }

    function openModal(title, content, onConfirm) {
      document.getElementById('modal-title').innerText = title;
      document.getElementById('modal-body').innerHTML = content;
      const btn = document.getElementById('modal-confirm-btn');
      btn.onclick = () => { onConfirm(); closeModal(); };
      document.getElementById('modal').style.display = 'flex';
    }

    function closeModal() {
      document.getElementById('modal').style.display = 'none';
    }

    function getSurvey(id) {
      return state.surveys.find(s => s.id === (id || state.selectedSurveyId));
    }

    function calculateQuestionNumbers(survey) {
      let gIdx = 1;
      let totalIdx = 1;
      const map = {};
      if (!survey || !survey.groups) return map;
      survey.groups.forEach(g => {
        let qIdx = 1;
        g.questions.forEach(q => {
          map[q.id] = survey.numberingFormat === 'global' ? `Q${totalIdx}` : `Q${gIdx}-${qIdx}`;
          qIdx++;
          totalIdx++;
        });
        gIdx++;
      });
      return map;
    }

    // --- 画面遷移 ---
    function navigate(view, surveyId = null) {
      state.currentView = view;
      if (surveyId !== null) {
        state.selectedSurveyId = surveyId;
      }
      
      const subNav = document.getElementById('sub-nav-bar');
      const isSubSection = ['detail', 'send', 'status', 'result'].includes(view);
      
      // メインメニューのアクティブ更新
      document.querySelectorAll('#app-header nav button').forEach(b => b.classList.remove('active'));
      const activeNavBtn = document.getElementById(`nav-btn-${view}`);
      if (activeNavBtn) activeNavBtn.classList.add('active');

      if (isSubSection) {
        subNav.style.display = 'block';
        const s = getSurvey();
        document.getElementById('current-survey-name').innerText = s ? s.name : '';
        state.currentSubView = view;
        document.querySelectorAll('#sub-nav-bar button').forEach(b => {
          b.classList.toggle('active', b.id === `subnav-btn-${view}`);
        });
      } else {
        subNav.style.display = 'none';
      }

      render();
    }

    function navigateSub(subView) {
      navigate(subView, state.selectedSurveyId);
    }

    // --- 描画処理 ---
    function render() {
      const container = document.getElementById('main-content');
      switch (state.currentView) {
        case 'survey-list': renderSurveyList(container); break;
        case 'survey-editor': renderSurveyEditor(container); break;
        case 'customer-list': renderCustomerList(container); break;
        case 'settings': renderSettings(container); break;
        case 'detail': renderSurveyDetail(container); break;
        case 'send': renderSurveySend(container); break;
        case 'status': renderSurveyStatus(container); break;
        case 'result': renderSurveyResult(container); break;
      }
    }

    // 1. アンケート一覧画面
    function renderSurveyList(el) {
      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">アンケート一覧</h2>
            <button class="btn btn-primary" onclick="editorDraft=null; navigate('survey-editor', null)">＋ 新規アンケート作成</button>
          </div>
          <table>
            <thead>
              <tr>
                <th>アンケート名</th>
                <th>状態</th>
                <th>公開期間</th>
                <th>回答数</th>
                <th>最終更新日</th>
                <th style="text-align: right;">操作</th>
              </tr>
            </thead>
            <tbody>
              ${state.surveys.map(s => {
                const badgeClass = s.status === '公開中' ? 'badge-active' : (s.status === '下書き' ? 'badge-draft' : 'badge-closed');
                return `
                  <tr>
                    <td><strong><a href="javascript:void(0)" onclick="navigate('detail', ${s.id})" style="color: var(--primary); text-decoration: none;">${s.name}</a></strong></td>
                    <td><span class="badge ${badgeClass}">${s.status}</span></td>
                    <td>${s.startDate} 〜 ${s.endDate}</td>
                    <td>${s.stats ? s.stats.answered : 0} 件</td>
                    <td>${s.updatedAt}</td>
                    <td style="text-align: right;">
                      <button class="btn btn-outline btn-sm" onclick="navigate('detail', ${s.id})">管理</button>
                      <button class="btn btn-outline btn-sm" onclick="editorDraft=null; navigate('survey-editor', ${s.id})">編集</button>
                      ${s.status === '下書き' ? `<button class="btn btn-danger-outline btn-sm" onclick="deleteSurvey(${s.id})">削除</button>` : ''}
                      ${s.status === '下書き' ? `<button class="btn btn-primary btn-sm" onclick="updateStatus(${s.id}, '公開中')">公開</button>` : ''}
                      ${s.status === '公開中' ? `<button class="btn btn-outline btn-sm" onclick="updateStatus(${s.id}, '終了')">終了</button>` : ''}
                    </td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>
      `;
    }

    function updateStatus(id, status) {
      const s = getSurvey(id);
      if (s) {
        s.status = status;
        s.updatedAt = '2026-09-25';
        showToast(`アンケート状態を「${status}」に更新しました`);
        render();
      }
    }

    function deleteSurvey(id) {
      openModal('削除の確認', 'この下書きアンケートを完全に削除しますか？', () => {
        state.surveys = state.surveys.filter(s => s.id !== id);
        showToast('アンケートを削除しました');
        render();
      });
    }

    // 2. アンケート作成・編集画面
    let editorDraft = null;
    function renderSurveyEditor(el) {
      const targetId = state.selectedSurveyId;
      if (!editorDraft) {
        const s = state.surveys.find(item => item.id === targetId);
        if (s) {
          editorDraft = JSON.parse(JSON.stringify(s));
        } else {
          editorDraft = {
            id: Date.now(),
            name: '',
            description: '',
            status: '下書き',
            createdAt: '2026-09-25',
            startDate: '2026-09-25',
            endDate: '2026-10-31',
            updatedAt: '2026-09-25',
            numberingFormat: 'group',
            groups: [
              { id: 'g1', name: '基本情報', questions: [{ id: 'q1', text: '', type: 'single', required: true, choices: ['はい', 'いいえ'], branches: {} }] }
            ],
            stats: { sent: 0, answered: 0, responses: [] }
          };
        }
      }

      const qNumMap = calculateQuestionNumbers(editorDraft);
      const allQuestions = [];
      editorDraft.groups.forEach(g => g.questions.forEach(q => allQuestions.push({ id: q.id, label: `${qNumMap[q.id]} ${q.text || '(無題)'}` })));

      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">${editorDraft.name ? 'アンケート編集' : '新規アンケート作成'}</h2>
            <div style="display: flex; gap: 0.5rem;">
              <button class="btn btn-outline" onclick="editorDraft=null; navigate('survey-list');">キャンセル</button>
              <button class="btn btn-primary" onclick="saveEditor()">保存</button>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group" style="flex: 2;">
              <label>アンケート名 <span style="color: var(--danger)">*必須</span></label>
              <input type="text" class="form-control" id="ed-name" value="${editorDraft.name}" placeholder="例: 2026年度 顧客満足度調査">
            </div>
            <div class="form-group">
              <label>質問番号形式</label>
              <select class="form-control" id="ed-num-format" onchange="changeNumFormat(this.value)">
                <option value="group" ${editorDraft.numberingFormat==='group'?'selected':''}>グループごと (Q1-1, Q2-1...)</option>
                <option value="global" ${editorDraft.numberingFormat==='global'?'selected':''}>全体で通番 (Q1, Q2, Q3...)</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>説明文</label>
            <textarea class="form-control" id="ed-desc" rows="2">${editorDraft.description}</textarea>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>公開開始日</label>
              <input type="date" class="form-control" id="ed-start" value="${editorDraft.startDate}">
            </div>
            <div class="form-group">
              <label>公開終了日</label>
              <input type="date" class="form-control" id="ed-end" value="${editorDraft.endDate}">
            </div>
          </div>

          <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid var(--border);">

          <h3 style="font-size: 1rem; margin-bottom: 1rem;">質問グループ構成</h3>
          <div id="groups-container">
            ${editorDraft.groups.map((g, gIdx) => `
              <div class="group-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                  <div style="display: flex; align-items: center; gap: 0.5rem; flex: 1;">
                    <input type="text" class="form-control" style="font-weight: 600; width: 300px;" value="${g.name}" onchange="updateGroupName(${gIdx}, this.value)" placeholder="グループ名">
                  </div>
                  <button class="btn btn-danger-outline btn-sm" onclick="removeGroup(${gIdx})">グループ削除</button>
                </div>

                <div class="questions-container">
                  ${g.questions.map((q, qIdx) => `
                    <div class="question-card">
                      <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <div style="flex: 1;">
                          <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <span style="font-weight: bold; padding: 0.4rem 0;">${qNumMap[q.id]}</span>
                            <input type="text" class="form-control" value="${q.text}" placeholder="質問文を入力" onchange="updateQuestionText(${gIdx}, ${qIdx}, this.value)">
                          </div>
                          <div class="form-row" style="margin-bottom: 0.5rem;">
                            <div class="form-group" style="margin-bottom:0;">
                              <label>回答形式</label>
                              <select class="form-control" onchange="updateQuestionType(${gIdx}, ${qIdx}, this.value)">
                                <option value="text" ${q.type==='text'?'selected':''}>自由記述</option>
                                <option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
                                <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
                              </select>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                              <label>必須 / 任意</label>
                              <select class="form-control" onchange="updateQuestionReq(${gIdx}, ${qIdx}, this.value==='true')">
                                <option value="true" ${q.required?'selected':''}>必須</option>
                                <option value="false" ${!q.required?'selected':''}>任意</option>
                              </select>
                            </div>
                          </div>

                          ${q.type !== 'text' ? `
                            <div style="background: #f8fafc; padding: 0.75rem; border-radius: 4px; border: 1px solid var(--border); margin-top: 0.5rem;">
                              <label style="font-size: 0.8rem; font-weight: 600; margin-bottom: 0.5rem; display: block;">選択肢${q.type==='single'?'と分岐先設定':''}</label>
                              ${q.choices.map((c, cIdx) => `
                                <div class="choice-row">
                                  <input type="text" class="form-control" style="flex: 1;" value="${c}" onchange="updateChoice(${gIdx}, ${qIdx}, ${cIdx}, this.value)">
                                  ${q.type === 'single' ? `
                                    <select class="form-control" style="width: 180px;" onchange="updateBranch(${gIdx}, ${qIdx}, '${c}', this.value)">
                                      <option value="">(通常の次へ進む)</option>
                                      <option value="__END__" ${q.branches[c]==='__END__'?'selected':''}>アンケート終了</option>
                                      ${allQuestions.filter(item => item.id !== q.id).map(item => `
                                        <option value="${item.id}" ${q.branches[c]===item.id?'selected':''}>${item.label}へ進む</option>
                                      `).join('')}
                                    </select>
                                  ` : ''}
                                  <button class="btn btn-outline btn-sm" onclick="removeChoice(${gIdx}, ${qIdx}, ${cIdx})">✕</button>
                                </div>
                              `).join('')}
                              <button class="btn btn-outline btn-sm" onclick="addChoice(${gIdx}, ${qIdx})" style="margin-top: 0.25rem;">＋ 選択肢追加</button>
                            </div>
                          ` : ''}
                        </div>
                        <button class="btn btn-danger-outline btn-sm" onclick="removeQuestion(${gIdx}, ${qIdx})">削除</button>
                      </div>
                    </div>
                  `).join('')}
                </div>
                <button class="btn btn-outline btn-sm" onclick="addQuestion(${gIdx})">＋ このグループに質問を追加</button>
              </div>
            `).join('')}
          </div>

          <button class="btn btn-outline" style="width: 100%; border-style: dashed; padding: 0.75rem;" onclick="addGroup()">＋ 新しいグループを追加</button>
        </div>
      `;
    }

    function changeNumFormat(val) { editorDraft.numberingFormat = val; render(); }
    function updateGroupName(gIdx, val) { editorDraft.groups[gIdx].name = val; }
    function addGroup() {
      editorDraft.groups.push({ id: 'g' + Date.now(), name: '新規グループ', questions: [] });
      render();
    }
    function removeGroup(gIdx) {
      if (editorDraft.groups[gIdx].questions.length > 0) {
        openModal('グループ削除確認', 'このグループに含まれる質問もすべて削除されます。よろしいですか？', () => {
          editorDraft.groups.splice(gIdx, 1);
          render();
        });
      } else {
        editorDraft.groups.splice(gIdx, 1);
        render();
      }
    }
    function addQuestion(gIdx) {
      editorDraft.groups[gIdx].questions.push({
        id: 'q' + Date.now(),
        text: '',
        type: 'single',
        required: true,
        choices: ['選択肢1', '選択肢2'],
        branches: {}
      });
      render();
    }
    function removeQuestion(gIdx, qIdx) {
      editorDraft.groups[gIdx].questions.splice(qIdx, 1);
      render();
    }
    function updateQuestionText(gIdx, qIdx, val) { editorDraft.groups[gIdx].questions[qIdx].text = val; }
    function updateQuestionType(gIdx, qIdx, val) {
      const q = editorDraft.groups[gIdx].questions[qIdx];
      q.type = val;
      if (val !== 'text' && (!q.choices || q.choices.length === 0)) {
        q.choices = ['選択肢1', '選択肢2'];
      }
      render();
    }
    function updateQuestionReq(gIdx, qIdx, val) { editorDraft.groups[gIdx].questions[qIdx].required = val; }
    function addChoice(gIdx, qIdx) {
      editorDraft.groups[gIdx].questions[qIdx].choices.push('新規選択肢');
      render();
    }
    function updateChoice(gIdx, qIdx, cIdx, val) { editorDraft.groups[gIdx].questions[qIdx].choices[cIdx] = val; }
    function removeChoice(gIdx, qIdx, cIdx) {
      editorDraft.groups[gIdx].questions[qIdx].choices.splice(cIdx, 1);
      render();
    }
    function updateBranch(gIdx, qIdx, choice, targetQId) {
      const q = editorDraft.groups[gIdx].questions[qIdx];
      if (!targetQId) {
        delete q.branches[choice];
      } else {
        q.branches[choice] = targetQId;
      }
    }

    function saveEditor() {
      const name = document.getElementById('ed-name').value.trim();
      if (!name) {
        alert('アンケート名は必須です。入力してください。');
        return;
      }
      editorDraft.name = name;
      editorDraft.description = document.getElementById('ed-desc').value;
      editorDraft.startDate = document.getElementById('ed-start').value;
      editorDraft.endDate = document.getElementById('ed-end').value;
      editorDraft.updatedAt = '2026-09-25';

      const existingIdx = state.surveys.findIndex(s => s.id === editorDraft.id);
      if (existingIdx >= 0) {
        state.surveys[existingIdx] = JSON.parse(JSON.stringify(editorDraft));
      } else {
        state.surveys.push(JSON.parse(JSON.stringify(editorDraft)));
        state.selectedSurveyId = editorDraft.id;
      }
      showToast('アンケート内容を保存しました');
      navigate('detail', editorDraft.id);
      editorDraft = null;
    }

    // 3. アンケート詳細画面
    function renderSurveyDetail(el) {
      const s = getSurvey();
      if (!s) return;
      const qNumMap = calculateQuestionNumbers(s);

      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <div>
              <h2 class="card-title">${s.name}</h2>
              <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">公開期間: ${s.startDate} 〜 ${s.endDate} | 状態: <span class="badge ${s.status==='公開中'?'badge-active':'badge-draft'}">${s.status}</span></div>
            </div>
            <div>
              <button class="btn btn-primary" onclick="editorDraft=null; navigate('survey-editor', ${s.id})">編集する</button>
            </div>
          </div>
          <p style="margin-bottom: 1.5rem; font-size: 0.95rem;">${s.description || '(説明文なし)'}</p>

          <h3 style="font-size: 1rem; margin-bottom: 1rem;">質問構成一覧</h3>
          ${s.groups.map(g => `
            <div style="margin-bottom: 1.5rem;">
              <h4 style="background: #f1f5f9; padding: 0.5rem 0.75rem; border-radius: 4px; font-size: 0.95rem; margin-bottom: 0.75rem;"> ${g.name}</h4>
              <div style="padding-left: 1rem;">
                ${g.questions.map(q => `
                  <div style="margin-bottom: 0.75rem; border-left: 2px solid var(--border); padding-left: 0.75rem;">
                    <div><strong>${qNumMap[q.id]}</strong> ${q.text} ${q.required ? '<span style="color: var(--danger); font-size: 0.8rem;">[必須]</span>' : '<span style="color: var(--text-muted); font-size: 0.8rem;">[任意]</span>'}</div>
                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.2rem;">
                      形式: ${q.type==='text'?'自由記述':(q.type==='single'?'単一選択':'複数選択')}
                      ${q.choices && q.choices.length > 0 ? ` | 選択肢: ${q.choices.join(', ')}` : ''}
                    </div>
                  </div>
                `).join('')}
              </div>
            </div>
          `).join('')}
        </div>
      `;
    }

    // 4. アンケート送信画面
    let selectedCustomerIds = [];
    let sendResult = null;
    function renderSurveySend(el) {
      const s = getSurvey();
      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">メール送信</h2>
          </div>

          ${sendResult ? `
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem;">
              <h4 style="color: #166534; margin-bottom: 0.5rem;">メール送信が完了しました (${sendResult.date})</h4>
              <p style="font-size: 0.9rem;">送信対象: ${sendResult.total}件 / 成功: ${sendResult.success}件 / 失敗: ${sendResult.failed}件</p>
            </div>
          ` : ''}

          <div class="form-group">
            <label>メール件名</label>
            <input type="text" class="form-control" id="mail-subject" value="【ご協力のお願い】${s.name}">
          </div>
          <div class="form-group">
            <label>メール本文</label>
            <textarea class="form-control" id="mail-body" rows="4">いつも大変お世話になっております。\nこの度、サービスの改善に向けたアンケートを実施しております。\n以下の回答URLよりご協力いただけますと幸いです。</textarea>
          </div>
          <div class="form-group">
            <label>回答用URL案内</label>
            <input type="text" class="form-control" readonly value="https://survey.example.com/respond/${s.id}" style="background: #f8fafc;">
          </div>

          <h3 style="font-size: 1rem; margin: 1.5rem 0 0.75rem;">送信対象者の選択 (顧客一覧より)</h3>
          <table>
            <thead>
              <tr>
                <th style="width: 40px;"><input type="checkbox" onchange="toggleSelectAllCust(this.checked)"></th>
                <th>顧客名</th>
                <th>会社名</th>
                <th>メールアドレス</th>
              </tr>
            </thead>
            <tbody>
              ${state.customers.map(c => `
                <tr>
                  <td><input type="checkbox" value="${c.id}" ${selectedCustomerIds.includes(c.id)?'checked':''} onchange="toggleSelectCust(${c.id}, this.checked)"></td>
                  <td>${c.name}</td>
                  <td>${c.company}</td>
                  <td>${c.email}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>

          <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end;">
            <button class="btn btn-primary" onclick="confirmSend()">送信内容の確認へ進む</button>
          </div>
        </div>
      `;
    }

    function toggleSelectAllCust(checked) {
      selectedCustomerIds = checked ? state.customers.map(c => c.id) : [];
      render();
    }
    function toggleSelectCust(id, checked) {
      if (checked) selectedCustomerIds.push(id);
      else selectedCustomerIds = selectedCustomerIds.filter(x => x !== id);
    }
    function confirmSend() {
      if (selectedCustomerIds.length === 0) {
        alert('送信対象者を1名以上選択してください。');
        return;
      }
      const subject = document.getElementById('mail-subject').value;
      const s = getSurvey();
      openModal('メール送信確認', `
        <p><strong>アンケート:</strong> ${s.name}</p>
        <p><strong>送信件数:</strong> ${selectedCustomerIds.length} 名</p>
        <p><strong>件名:</strong> ${subject}</p>
        <p style="margin-top: 0.5rem; color: var(--text-muted);">※ 上記の内容でメール送信を実行します。</p>
      `, () => {
        sendResult = {
          date: '2026-09-25 14:30',
          total: selectedCustomerIds.length,
          success: selectedCustomerIds.length,
          failed: 0
        };
        showToast('メール送信が完了しました');
        render();
      });
    }

    // 5. 回答状況ダッシュボード
    function renderSurveyStatus(el) {
      const s = getSurvey();
      const stats = s.stats || { sent: 0, answered: 0 };
      const rate = stats.sent > 0 ? Math.round((stats.answered / stats.sent) * 100) : 0;

      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">回答状況ダッシュボード</h2>
            <span class="badge ${s.status==='公開中'?'badge-active':'badge-draft'}">${s.status}</span>
          </div>

          <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem;">
            <div style="background: #f8fafc; border: 1px solid var(--border); padding: 1.25rem; border-radius: 6px; text-align: center;">
              <div style="font-size: 0.85rem; color: var(--text-muted);">総回答数</div>
              <div style="font-size: 1.75rem; font-weight: bold; color: var(--primary);">${stats.answered}</div>
            </div>
            <div style="background: #f8fafc; border: 1px solid var(--border); padding: 1.25rem; border-radius: 6px; text-align: center;">
              <div style="font-size: 0.85rem; color: var(--text-muted);">回答率</div>
              <div style="font-size: 1.75rem; font-weight: bold; color: var(--success);">${rate}%</div>
            </div>
            <div style="background: #f8fafc; border: 1px solid var(--border); padding: 1.25rem; border-radius: 6px; text-align: center;">
              <div style="font-size: 0.85rem; color: var(--text-muted);">送信対象者数</div>
              <div style="font-size: 1.75rem; font-weight: bold;">${stats.sent}</div>
            </div>
            <div style="background: #f8fafc; border: 1px solid var(--border); padding: 1.25rem; border-radius: 6px; text-align: center;">
              <div style="font-size: 0.85rem; color: var(--text-muted);">未回答数</div>
              <div style="font-size: 1.75rem; font-weight: bold; color: var(--danger);">${Math.max(0, stats.sent - stats.answered)}</div>
            </div>
          </div>

          <h3 style="font-size: 1rem; margin-bottom: 0.75rem;">回答状況の推移 (日別)</h3>
          <div style="background: #f8fafc; border: 1px solid var(--border); padding: 1.5rem; border-radius: 6px; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
            [日別回答数グラフ表示エリア]
          </div>
        </div>
      `;
    }

    // 6. 回答結果・集計画面
    function renderSurveyResult(el) {
      const s = getSurvey();
      const qNumMap = calculateQuestionNumbers(s);
      const responses = s.stats ? s.stats.responses : [];

      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">回答結果・集計 (総回答数: ${responses.length}件)</h2>
          </div>

          ${responses.length === 0 ? '<p style="color: var(--text-muted);">回答結果がまだありません。</p>' : ''}

          ${s.groups.map(g => `
            <div style="margin-bottom: 2rem;">
              <h3 style="background: #f1f5f9; padding: 0.5rem 0.75rem; border-radius: 4px; font-size: 1rem; margin-bottom: 1rem;"> ${g.name}</h3>
              ${g.questions.map(q => {
                return `
                  <div style="background: #ffffff; border: 1px solid var(--border); padding: 1.25rem; border-radius: 6px; margin-bottom: 1rem;">
                    <div style="font-weight: 600; margin-bottom: 0.75rem;">${qNumMap[q.id]} ${q.text}</div>
                    ${renderQuestionSummary(q, responses)}
                  </div>
                `;
              }).join('')}
            </div>
          `).join('')}
        </div>
      `;
    }

    function renderQuestionSummary(q, responses) {
      if (q.type === 'text') {
        const answers = responses.map(r => r[q.id]).filter(Boolean);
        return `
          <div style="background: #f8fafc; border-radius: 4px; padding: 0.5rem;">
            ${answers.length === 0 ? '<div style="color: var(--text-muted); font-size: 0.85rem;">回答なし</div>' : answers.map(a => `
              <div style="padding: 0.4rem 0.5rem; border-bottom: 1px solid var(--border); font-size: 0.9rem;"> ${a}</div>
            `).join('')}
          </div>
        `;
      } else {
        const counts = {};
        q.choices.forEach(c => counts[c] = 0);
        let totalChoicesSelected = 0;

        responses.forEach(r => {
          const val = r[q.id];
          if (Array.isArray(val)) {
            val.forEach(c => { if (counts[c] !== undefined) { counts[c]++; totalChoicesSelected++; } });
          } else if (val && counts[val] !== undefined) {
            counts[val]++;
            totalChoicesSelected++;
          }
        });

        return `
          <div>
            ${q.choices.map(c => {
              const count = counts[c] || 0;
              const pct = responses.length > 0 ? Math.round((count / responses.length) * 100) : 0;
              return `
                <div style="margin-bottom: 0.5rem; font-size: 0.85rem;">
                  <div style="display: flex; justify-content: space-between; margin-bottom: 0.2rem;">
                    <span>${c}</span>
                    <span><strong>${count} 件</strong> (${pct}%)</span>
                  </div>
                  <div style="background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden;">
                    <div style="background: var(--primary); width: ${pct}%; height: 100%;"></div>
                  </div>
                </div>
              `;
            }).join('')}
          </div>
        `;
      }
    }

    // 7. 顧客一覧画面
    function renderCustomerList(el) {
      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <div>
              <h2 class="card-title">顧客一覧</h2>
              <div style="font-size: 0.85rem; color: var(--text-muted);">kintoneアプリ連携から自動同期</div>
            </div>
            <button class="btn btn-outline" onclick="showToast('キントーンから最新情報を再同期しました')"> 再同期</button>
          </div>
          <table>
            <thead>
              <tr>
                <th>顧客ID</th>
                <th>顧客名</th>
                <th>メールアドレス</th>
                <th>所属組織</th>
              </tr>
            </thead>
            <tbody>
              ${state.customers.map(c => `
                <tr>
                  <td>${c.id}</td>
                  <td><strong>${c.name}</strong></td>
                  <td>${c.email}</td>
                  <td>${c.company}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `;
    }

    // 8. 設定画面
    function renderSettings(el) {
      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">キントーン接続設定</h2>
            <span class="badge badge-active">接続確認済み</span>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex: 2;">
              <label>利用先 (サブドメイン/URL)</label>
              <input type="text" class="form-control" value="${state.settings.kintone.host}">
            </div>
            <div class="form-group">
              <label>顧客管理アプリID</label>
              <input type="text" class="form-control" value="${state.settings.kintone.appId}">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>ログイン名</label>
              <input type="text" class="form-control" value="${state.settings.kintone.login}">
            </div>
            <div class="form-group">
              <label>パスワード</label>
              <input type="password" class="form-control" value="${state.settings.kintone.pass}">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>プロキシホスト</label>
              <input type="text" class="form-control" value="${state.settings.kintone.proxyHost}">
            </div>
            <div class="form-group">
              <label>プロキシポート</label>
              <input type="text" class="form-control" value="${state.settings.kintone.proxyPort}">
            </div>
          </div>
          <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
            <button class="btn btn-outline" onclick="showToast('キントーン接続テスト成功: 正常に応答しました')">接続確認</button>
            <button class="btn btn-primary" onclick="showToast('キントーン設定を保存しました')">設定保存</button>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h2 class="card-title">メール送信設定 (SMTP)</h2>
            <span class="badge badge-active">送信可能</span>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex: 2;">
              <label>SMTPサーバ</label>
              <input type="text" class="form-control" value="${state.settings.smtp.host}">
            </div>
            <div class="form-group">
              <label>ポート番号</label>
              <input type="text" class="form-control" value="${state.settings.smtp.port}">
            </div>
            <div class="form-group">
              <label>接続方式</label>
              <select class="form-control">
                <option>STARTTLS</option>
                <option>SSL/TLS</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>認証ユーザー名</label>
              <input type="text" class="form-control" value="${state.settings.smtp.user}">
            </div>
            <div class="form-group">
              <label>認証パスワード</label>
              <input type="password" class="form-control" value="${state.settings.smtp.pass}">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>送信元メールアドレス</label>
              <input type="text" class="form-control" value="${state.settings.smtp.fromEmail}">
            </div>
            <div class="form-group">
              <label>送信元名</label>
              <input type="text" class="form-control" value="${state.settings.smtp.fromName}">
            </div>
          </div>
          <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
            <button class="btn btn-outline" onclick="showToast('SMTP接続テスト成功: メールサーバへ接続できました')">接続テスト</button>
            <button class="btn btn-primary" onclick="showToast('メール設定を保存しました')">設定保存</button>
          </div>
        </div>
      `;
    }

    // 回答者画面プレビュー
    function previewRespondent() {
      const s = getSurvey();
      const qNumMap = calculateQuestionNumbers(s);
      
      const newWin = window.open('', '_blank');
      newWin.document.write(`
        <!DOCTYPE html>
        <html lang="ja">
        <head>
          <meta charset="UTF-8">
          <title>${s.name}</title>
          <style>
            body { font-family: sans-serif; background: #f8fafc; color: #1e293b; padding: 2rem 1rem; }
            .container { max-width: 680px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .form-group { margin-bottom: 1.5rem; }
            .btn { background: #2563eb; color: #fff; border: none; padding: 0.75rem 1.5rem; border-radius: 6px; font-size: 1rem; cursor: pointer; width: 100%; }
          </style>
        </head>
        <body>
          <div class="container" id="resp-root">
            <h1 style="font-size: 1.5rem; margin-bottom: 0.5rem;">${s.name}</h1>
            <p style="color: #64748b; margin-bottom: 1.5rem;">${s.description || ''}</p>
            <form onsubmit="event.preventDefault(); document.getElementById('resp-root').innerHTML='<div style=\\'text-align:center; padding: 2rem;\\'><h2>回答ありがとうございました。</h2><p style=\\'color:#64748b; margin-top:0.5rem;\\'>アンケートの回答を受け付けました。</p></div>';">
              ${s.groups.map(g => `
                <div style="margin-bottom: 1.5rem;">
                  <h3 style="font-size: 1.1rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.5rem; margin-bottom: 1rem;">${g.name}</h3>
                  ${g.questions.map(q => `
                    <div class="form-group">
                      <label style="display:block; font-weight:600; margin-bottom: 0.5rem;">
                        ${qNumMap[q.id]} ${q.text} ${q.required ? '<span style="color:red">*</span>' : ''}
                      </label>
                      ${q.type === 'text' ? `
                        <textarea style="width:100%; padding:0.5rem; border: 1px solid #cbd5e1; border-radius:4px;" rows="3" ${q.required?'required':''}></textarea>
                      ` : q.choices.map(c => `
                        <div style="margin-bottom: 0.35rem;">
                          <label style="font-weight: normal; cursor: pointer;">
                            <input type="${q.type==='single'?'radio':'checkbox'}" name="${q.id}" value="${c}" ${q.required?'required':''}> ${c}
                          </label>
                        </div>
                      `).join('')}
                    </div>
                  `).join('')}
                </div>
              `).join('')}
              <button type="submit" class="btn">回答を送信する</button>
            </form>
          </div>
        </body>
        </html>
      `);
    }

    // 初期化表示
    navigate('survey-list');
  </script>
</body>
</html>
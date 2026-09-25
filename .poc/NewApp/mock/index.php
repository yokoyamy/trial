<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>アンケート業務運営アプリ - モックアップ</title>
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
    nav button.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; }
    nav button:hover:not(.active) { color: var(--text); }

    /* サブナビ（個別アンケート用） */
    .sub-nav { background: #f1f5f9; border-bottom: 1px solid var(--border); padding: 0.5rem 1rem; }
    .sub-nav-container { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .sub-nav-title { font-weight: 600; font-size: 0.9rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem; }
    .sub-nav-title span { color: var(--text); font-size: 1rem; }
    .sub-nav ul { display: flex; list-style: none; gap: 0.5rem; }
    .sub-nav button { background: var(--surface); border: 1px solid var(--border); border-radius: 4px; padding: 0.35rem 0.75rem; font-size: 0.85rem; cursor: pointer; font-weight: 500; }
    .sub-nav button.active { background: var(--primary); color: #fff; border-color: var(--primary); }

    /* メインコンテンツ */
    main { max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem; flex: 1; width: 100%; }
    .card { background: var(--surface); border-radius: 8px; border: 1px solid var(--border); padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; }
    .card-title { font-size: 1.15rem; font-weight: 600; }

    /* UI パーツ */
    .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.9rem; font-weight: 500; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; }
    .btn-primary { background: var(--primary); color: #fff; }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-outline { background: var(--surface); border-color: var(--border); color: var(--text); }
    .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }
    .btn-danger { background: var(--danger); color: #fff; }
    .btn-danger-outline { border-color: var(--danger); color: var(--danger); background: transparent; }
    .btn-danger-outline:hover { background: #fee2e2; }
    .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.8rem; }
    .btn:disabled { opacity: 0.6; cursor: not-allowed; }

    .badge { padding: 0.25rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
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
    th, td { padding: 0.75rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    th { background: #f8fafc; font-weight: 600; color: var(--text-muted); }

    /* ドラッグ＆ドロップ・エディタ */
    .group-card { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem; }
    .question-card { background: #ffffff; border: 1px solid var(--border); border-radius: 6px; padding: 1rem; margin-bottom: 0.75rem; }
    .choice-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }

    /* ダッシュボードカード */
    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .stat-card { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem; text-align: center; }
    .stat-val { font-size: 1.75rem; font-weight: bold; color: var(--primary); margin-top: 0.25rem; }
    .stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 500; }

    /* グラフ簡易バー */
    .chart-bar-bg { background: #f1f5f9; border-radius: 4px; height: 1.25rem; width: 100%; overflow: hidden; margin-top: 0.25rem; }
    .chart-bar-fill { background: var(--primary); height: 100%; border-radius: 4px; transition: width 0.3s; }

    /* トースト */
    .toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #334155; color: #fff; padding: 0.75rem 1.25rem; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 1000; font-size: 0.9rem; display: none; }
    
    /* モーダル */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center; z-index: 500; }
    .modal { background: #fff; width: 90%; max-width: 600px; border-radius: 8px; padding: 1.5rem; max-height: 90vh; overflow-y: auto; }

    /* 回答者プレビュー枠 */
    .respondent-view { max-width: 720px; margin: 2rem auto; background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
  </style>
</head>
<body>

  <!-- 運営者用メインヘッダー -->
  <header id="app-header">
    <div class="header-container">
      <div class="logo" onclick="navigate('survey-list')">📋 アンケート業務運営アプリ</div>
      <nav>
        <ul>
          <li><button id="nav-surveys" class="active" onclick="navigate('survey-list')">アンケート一覧</button></li>
          <li><button id="nav-new" onclick="startNewSurvey()">新規アンケート作成</button></li>
          <li><button id="nav-customers" onclick="navigate('customer-list')">顧客一覧</button></li>
          <li><button id="nav-settings" onclick="navigate('settings')">設定</button></li>
        </ul>
      </nav>
    </div>
    <!-- 個別アンケート用サブメニュー -->
    <div id="sub-nav-bar" class="sub-nav" style="display: none;">
      <div class="sub-nav-container">
        <div class="sub-nav-title">選択中: <span id="current-survey-name"></span></div>
        <ul>
          <li><button id="sub-detail" class="active" onclick="navigateSub('detail')">アンケート内容</button></li>
          <li><button id="sub-send" onclick="navigateSub('send')">送信</button></li>
          <li><button id="sub-status" onclick="navigateSub('status')">回答状況</button></li>
          <li><button id="sub-result" onclick="navigateSub('result')">回答結果</button></li>
          <li><button class="btn btn-outline btn-sm" onclick="openRespondentPreview()">回答画面プレビュー</button></li>
        </ul>
      </div>
    </div>
  </header>

  <!-- メインコンテンツ領域 -->
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
    // --- 状態データ ---
    const state = {
      currentView: 'survey-list',
      currentSubView: 'detail',
      selectedSurveyId: 1,
      selectedCustomerIds: [],
      customerSearchQuery: '',
      settings: {
        kintone: { host: 'example.cybozu.com', appId: '101', login: 'admin', pass: '******', nameField: '顧客名', emailField: 'メールアドレス', proxyHost: 'proxy.corp.local', proxyPort: '8080' },
        smtp: { host: 'smtp.example.com', port: '587', secure: 'TLS', user: 'survey@example.com', pass: '******', fromEmail: 'noreply@example.com', fromName: 'アンケート運営事務局' }
      },
      customers: [
        { id: 1, name: '山田 太郎', email: 'yamada@example.com', company: '株式会社A' },
        { id: 2, name: '佐藤 花子', email: 'sato@example.com', company: '株式会社B' },
        { id: 3, name: '鈴木 一郎', email: 'suzuki@example.com', company: '合同会社C' },
        { id: 4, name: '田中 次郎', email: 'tanaka@example.com', company: '株式会社D' },
        { id: 5, name: '高橋 健太', email: 'takahashi@example.com', company: '株式会社E' }
      ],
      surveys: [
        {
          id: 1,
          name: '新サービス利用満足度調査 2026',
          description: '弊社新サービスをご利用のお客様を対象にしたアンケートです。率直なご意見をお聞かせください。',
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
              name: '機能とご意見について',
              questions: [
                { id: 'q3', text: 'よく利用する機能をすべて選択してください', type: 'multiple', required: false, choices: ['ダッシュボード', '帳票出力', 'データ連携', 'ユーザー管理'], branches: {} },
                { id: 'q4', text: 'サービスに対するご意見・ご要望をご記入ください', type: 'text', required: false, choices: [], branches: {} }
              ]
            }
          ],
          recipients: [
            { customerId: 1, name: '山田 太郎', email: 'yamada@example.com', status: '回答済', sentAt: '2026-09-02 10:00' },
            { customerId: 2, name: '佐藤 花子', email: 'sato@example.com', status: '回答済', sentAt: '2026-09-02 10:00' },
            { customerId: 3, name: '鈴木 一郎', email: 'suzuki@example.com', status: '回答済', sentAt: '2026-09-02 10:00' },
            { customerId: 4, name: '田中 次郎', email: 'tanaka@example.com', status: '送信失敗', sentAt: '2026-09-02 10:00', error: 'SMTP Connection Timeout' }
          ],
          responses: [
            { id: 101, customerName: '山田 太郎', answeredAt: '2026-09-03', answers: { q1: 'スタンダード', q2: '毎日', q3: ['ダッシュボード', 'データ連携'], q4: '非常に操作しやすく満足しています。' } },
            { id: 102, customerName: '佐藤 花子', answeredAt: '2026-09-04', answers: { q1: 'プレミアム', q2: '週2〜3回', q3: ['ダッシュボード'], q4: '帳票のカスタマイズ性が上がると嬉しいです。' } },
            { id: 103, customerName: '鈴木 一郎', answeredAt: '2026-09-05', answers: { q1: 'エントリー', q2: 'ほとんど使わない', q3: null, q4: '初期設定の手順が少し難しかったです。' } }
          ]
        },
        {
          id: 2,
          name: 'ユーザー会参加希望アンケート',
          description: '来月開催予定のユーザー交流会に関するアンケートです。',
          status: '下書き',
          createdAt: '2026-09-24',
          startDate: '2026-10-01',
          endDate: '2026-10-20',
          updatedAt: '2026-09-24',
          numberingFormat: 'global',
          groups: [
            {
              id: 'g21',
              name: '参加確認',
              questions: [
                { id: 'q21', text: '交流会への参加を希望されますか？', type: 'single', required: true, choices: ['参加する', '参加しない'], branches: {'参加しない': '__END__'} },
                { id: 'q22', text: '希望する参加形式を選択してください', type: 'single', required: true, choices: ['現地参加（東京会場）', 'オンライン参加（Zoom）'], branches: {} }
              ]
            }
          ],
          recipients: [],
          responses: []
        }
      ]
    };

    let editorDraft = null;

    // --- ユーティリティ ---
    function showToast(msg) {
      const t = document.getElementById('toast');
      t.textContent = msg;
      t.style.display = 'block';
      setTimeout(() => { t.style.display = 'none'; }, 3000);
    }

    function openModal(title, content, onConfirm) {
      document.getElementById('modal-title').textContent = title;
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

    // --- ルーティング ---
    function navigate(view, surveyId = null) {
      state.currentView = view;
      if (surveyId) state.selectedSurveyId = surveyId;
      
      const subNav = document.getElementById('sub-nav-bar');
      const header = document.getElementById('app-header');
      const isSubSection = ['detail', 'send', 'status', 'result'].includes(view);
      const isRespondent = (view === 'respondent');

      if (isRespondent) {
        header.style.display = 'none';
      } else {
        header.style.display = 'block';
      }

      // ナビゲーションアクティブ制御
      document.querySelectorAll('#app-header nav button').forEach(b => b.classList.remove('active'));
      if (view === 'survey-list') document.getElementById('nav-surveys').classList.add('active');
      if (view === 'survey-editor' && !surveyId) document.getElementById('nav-new').classList.add('active');
      if (view === 'customer-list') document.getElementById('nav-customers').classList.add('active');
      if (view === 'settings') document.getElementById('nav-settings').classList.add('active');

      if (isSubSection) {
        subNav.style.display = 'block';
        const s = getSurvey();
        document.getElementById('current-survey-name').textContent = s ? s.name : '';
        state.currentSubView = view;
        document.querySelectorAll('#sub-nav-bar button').forEach(b => b.classList.remove('active'));
        const subBtn = document.getElementById('sub-' + view);
        if (subBtn) subBtn.classList.add('active');
      } else {
        subNav.style.display = 'none';
      }

      render();
    }

    function navigateSub(subView) {
      navigate(subView, state.selectedSurveyId);
    }

    function startNewSurvey() {
      editorDraft = null;
      state.selectedSurveyId = null;
      navigate('survey-editor');
    }

    // --- レンダラー分岐 ---
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
        case 'respondent': renderRespondentView(container); break;
      }
    }

    // 1. アンケート一覧画面
    function renderSurveyList(el) {
      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">アンケート一覧</h2>
            <button class="btn btn-primary" onclick="startNewSurvey()">＋ 新規アンケート作成</button>
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
                const respCount = s.responses ? s.responses.length : 0;
                return `
                  <tr>
                    <td><strong><a href="javascript:void(0)" onclick="navigate('detail', ${s.id})" style="color: var(--primary); text-decoration: none;">${s.name}</a></strong></td>
                    <td><span class="badge ${badgeClass}">${s.status}</span></td>
                    <td>${s.startDate} 〜 ${s.endDate}</td>
                    <td><strong>${respCount}</strong> 件</td>
                    <td>${s.updatedAt}</td>
                    <td style="text-align: right;">
                      <button class="btn btn-outline btn-sm" onclick="navigate('detail', ${s.id})">管理</button>
                      <button class="btn btn-outline btn-sm" onclick="editorDraft=null; navigate('survey-editor', ${s.id})">直接編集</button>
                      ${s.status === '下書き' ? `<button class="btn btn-primary btn-sm" onclick="publishSurvey(${s.id})">公開する</button>` : ''}
                      ${s.status === '公開中' ? `<button class="btn btn-outline btn-sm" onclick="closeSurvey(${s.id})">終了する</button>` : ''}
                      ${s.status === '下書き' ? `<button class="btn btn-danger-outline btn-sm" onclick="deleteSurvey(${s.id})">削除</button>` : ''}
                    </td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>
      `;
    }

    function publishSurvey(id) {
      openModal('アンケートの公開', 'このアンケートを公開状態にし、回答受付を開始しますか？', () => {
        const s = getSurvey(id);
        if (s) {
          s.status = '公開中';
          s.updatedAt = '2026-09-25';
          showToast('アンケートを公開しました');
          render();
        }
      });
    }

    function closeSurvey(id) {
      openModal('回答受付の終了', 'このアンケートを終了状態にし、回答受付を停止しますか？', () => {
        const s = getSurvey(id);
        if (s) {
          s.status = '終了';
          s.updatedAt = '2026-09-25';
          showToast('アンケートを終了しました');
          render();
        }
      });
    }

    function deleteSurvey(id) {
      openModal('下書き削除の確認', 'この下書きアンケートを完全に削除しますか？この操作は取り消せません。', () => {
        state.surveys = state.surveys.filter(s => s.id !== id);
        showToast('下書きアンケートを削除しました');
        render();
      });
    }

    // 2. アンケート作成・編集画面（1画面で全質問・選択肢・分岐を編集）
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
            recipients: [],
            responses: []
          };
        }
      }

      const qNumMap = calculateQuestionNumbers(editorDraft);
      const allQuestions = [];
      editorDraft.groups.forEach(g => g.questions.forEach(q => allQuestions.push({ id: q.id, label: `${qNumMap[q.id]} ${q.text || '(無題の質問)'}` })));

      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">${targetId ? 'アンケート編集' : '新規アンケート作成'}</h2>
            <div style="display: flex; gap: 0.5rem;">
              <button class="btn btn-outline" onclick="cancelEditor()">キャンセル</button>
              <button class="btn btn-primary" onclick="saveEditor()">保存する</button>
            </div>
          </div>

          <!-- 基本情報 -->
          <div class="form-row">
            <div class="form-group" style="flex: 2;">
              <label>アンケート名 <span style="color: var(--danger)">*必須</span></label>
              <input type="text" class="form-control" id="ed-name" value="${editorDraft.name}" placeholder="例: 2026年度 顧客満足度調査">
            </div>
            <div class="form-group">
              <label>質問番号の表示形式</label>
              <select class="form-control" id="ed-num-format" onchange="editorDraft.numberingFormat=this.value; render();">
                <option value="group" ${editorDraft.numberingFormat==='group'?'selected':''}>グループごと (Q1-1, Q2-1...)</option>
                <option value="global" ${editorDraft.numberingFormat==='global'?'selected':''}>全体通番 (Q1, Q2, Q3...)</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>説明文 / 案内文（回答者画面の冒頭に表示）</label>
            <textarea class="form-control" id="ed-desc" rows="2" placeholder="アンケートの趣旨や所要時間を記載">${editorDraft.description}</textarea>
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

          <!-- 質問グループ構成 -->
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="font-size: 1.05rem;">質問グループ構成</h3>
            <span style="font-size: 0.8rem; color: var(--text-muted);">※ 単一選択のみ回答に応じた分岐先を設定できます</span>
          </div>

          <div id="groups-container">
            ${editorDraft.groups.map((g, gIdx) => `
              <div class="group-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                  <div style="display: flex; align-items: center; gap: 0.5rem; flex: 1;">
                    <span style="font-weight: 600; font-size: 0.9rem;">グループ ${gIdx + 1}:</span>
                    <input type="text" class="form-control" style="font-weight: 600; max-width: 350px;" value="${g.name}" onchange="editorDraft.groups[${gIdx}].name=this.value" placeholder="グループ名を入力">
                  </div>
                  <button class="btn btn-danger-outline btn-sm" onclick="removeGroup(${gIdx})">グループ削除</button>
                </div>

                <!-- 質問リスト -->
                <div>
                  ${g.questions.map((q, qIdx) => `
                    <div class="question-card">
                      <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem;">
                        <div style="flex: 1;">
                          <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <span style="font-weight: bold; padding: 0.4rem 0.2rem; min-width: 45px;">${qNumMap[q.id]}</span>
                            <input type="text" class="form-control" value="${q.text}" placeholder="質問文を入力してください" onchange="editorDraft.groups[${gIdx}].questions[${qIdx}].text=this.value">
                          </div>

                          <div class="form-row" style="margin-bottom: 0.5rem;">
                            <div class="form-group" style="margin-bottom: 0;">
                              <label>回答形式</label>
                              <select class="form-control" onchange="changeQuestionType(${gIdx}, ${qIdx}, this.value)">
                                <option value="single" ${q.type==='single'?'selected':''}>単一選択（ラジオボタン / 分岐可）</option>
                                <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択（チェックボックス）</option>
                                <option value="text" ${q.type==='text'?'selected':''}>自由記述（テキスト入力）</option>
                              </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                              <label>必須設定</label>
                              <select class="form-control" onchange="editorDraft.groups[${gIdx}].questions[${qIdx}].required = (this.value==='true')">
                                <option value="true" ${q.required?'selected':''}>必須回答</option>
                                <option value="false" ${!q.required?'selected':''}>任意回答</option>
                              </select>
                            </div>
                          </div>

                          <!-- 選択肢・分岐設定 -->
                          ${q.type !== 'text' ? `
                            <div style="background: #f8fafc; padding: 0.75rem; border-radius: 6px; border: 1px solid var(--border); margin-top: 0.5rem;">
                              <div style="font-size: 0.8rem; font-weight: 600; margin-bottom: 0.5rem; display: flex; justify-content: space-between;">
                                <span>選択肢一覧</span>
                                ${q.type==='single' ? '<span style="color: var(--primary);">選択時の分岐先ジャンプ</span>' : ''}
                              </div>
                              ${q.choices.map((c, cIdx) => `
                                <div class="choice-row">
                                  <input type="text" class="form-control" style="flex: 1;" value="${c}" onchange="editorDraft.groups[${gIdx}].questions[${qIdx}].choices[${cIdx}]=this.value" placeholder="選択肢の文言">
                                  ${q.type === 'single' ? `
                                    <select class="form-control" style="width: 220px;" onchange="updateBranch(${gIdx}, ${qIdx}, '${c}', this.value)">
                                      <option value="">(通常の次へ進む)</option>
                                      <option value="__END__" ${q.branches[c]==='__END__'?'selected':''}>［終了］アンケート回答完了へ</option>
                                      ${allQuestions.filter(item => item.id !== q.id).map(item => `
                                        <option value="${item.id}" ${q.branches[c]===item.id?'selected':''}>${item.label} へジャンプ</option>
                                      `).join('')}
                                    </select>
                                  ` : ''}
                                  <button class="btn btn-outline btn-sm" onclick="removeChoice(${gIdx}, ${qIdx}, ${cIdx})">✕</button>
                                </div>
                              `).join('')}
                              <button class="btn btn-outline btn-sm" onclick="addChoice(${gIdx}, ${qIdx})" style="margin-top: 0.25rem;">＋ 選択肢を追加</button>
                            </div>
                          ` : ''}
                        </div>
                        <button class="btn btn-danger-outline btn-sm" style="margin-top: 0.2rem;" onclick="removeQuestion(${gIdx}, ${qIdx})">削除</button>
                      </div>
                    </div>
                  `).join('')}
                </div>

                <button class="btn btn-outline btn-sm" onclick="addQuestion(${gIdx})">＋ このグループに質問を追加</button>
              </div>
            `).join('')}
          </div>

          <button class="btn btn-outline" style="width: 100%; border-style: dashed; padding: 0.75rem; margin-bottom: 1.5rem;" onclick="addGroup()">＋ 新しい質問グループを追加</button>

          <div style="display: flex; justify-content: flex-end; gap: 0.5rem; border-top: 1px solid var(--border); padding-top: 1rem;">
            <button class="btn btn-outline" onclick="cancelEditor()">キャンセル</button>
            <button class="btn btn-primary" onclick="saveEditor()">保存する</button>
          </div>
        </div>
      `;
    }

    function cancelEditor() {
      if (confirm('編集中の内容は破棄されます。よろしいですか？')) {
        editorDraft = null;
        navigate(state.selectedSurveyId ? 'detail' : 'survey-list');
      }
    }

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

    function changeQuestionType(gIdx, qIdx, type) {
      const q = editorDraft.groups[gIdx].questions[qIdx];
      q.type = type;
      if (type !== 'text' && (!q.choices || q.choices.length === 0)) {
        q.choices = ['選択肢1', '選択肢2'];
      }
      q.branches = {};
      render();
    }

    function addChoice(gIdx, qIdx) {
      editorDraft.groups[gIdx].questions[qIdx].choices.push('新規選択肢');
      render();
    }

    function removeChoice(gIdx, qIdx, cIdx) {
      editorDraft.groups[gIdx].questions[qIdx].choices.splice(cIdx, 1);
      render();
    }

    function updateBranch(gIdx, qIdx, choice, targetId) {
      const q = editorDraft.groups[gIdx].questions[qIdx];
      if (!targetId) {
        delete q.branches[choice];
      } else {
        q.branches[choice] = targetId;
      }
    }

    function saveEditor() {
      const name = document.getElementById('ed-name').value.trim();
      if (!name) {
        alert('アンケート名は必須です。入力してください。');
        document.getElementById('ed-name').focus();
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
      editorDraft = null;
      showToast('アンケートを保存しました');
      navigate('detail', state.selectedSurveyId);
    }

    // 3. アンケート内容確認タブ
    function renderSurveyDetail(el) {
      const s = getSurvey();
      if (!s) return;
      const qNumMap = calculateQuestionNumbers(s);

      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <div>
              <h2 class="card-title">${s.name}</h2>
              <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                公開期間: ${s.startDate} 〜 ${s.endDate} | 状態: <span class="badge ${s.status==='公開中'?'badge-active':'badge-draft'}">${s.status}</span>
              </div>
            </div>
            <div>
              <button class="btn btn-primary" onclick="editorDraft=null; navigate('survey-editor', ${s.id})">内容を編集する</button>
            </div>
          </div>

          <p style="margin-bottom: 1.5rem; font-size: 0.95rem; color: #334155;">${s.description || '（説明文なし）'}</p>

          <h3 style="font-size: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">登録質問・分岐ルール一覧</h3>
          ${s.groups.map(g => `
            <div style="margin-bottom: 1.5rem;">
              <h4 style="background: #f1f5f9; padding: 0.5rem 0.75rem; border-radius: 4px; font-size: 0.95rem; margin-bottom: 0.75rem;">📁 ${g.name}</h4>
              <div style="padding-left: 0.75rem;">
                ${g.questions.map(q => `
                  <div style="margin-bottom: 0.75rem; padding: 0.5rem 0; border-bottom: 1px dashed var(--border);">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                      <span style="font-weight: bold; color: var(--primary);">${qNumMap[q.id]}</span>
                      <span style="font-weight: 600;">${q.text || '(無題)'}</span>
                      <span class="badge badge-draft" style="font-size: 0.7rem;">${q.type==='single'?'単一選択':(q.type==='multiple'?'複数選択':'自由記述')}</span>
                      ${q.required ? '<span style="color: var(--danger); font-size: 0.75rem;">*必須</span>' : '<span style="color: var(--text-muted); font-size: 0.75rem;">任意</span>'}
                    </div>
                    ${q.choices && q.choices.length > 0 ? `
                      <ul style="padding-left: 2rem; font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                        ${q.choices.map(c => `
                          <li>
                            ${c}
                            ${q.branches && q.branches[c] ? `<strong style="color: var(--primary);"> ➔ 「${q.branches[c]==='__END__' ? 'アンケート終了' : (qNumMap[q.branches[c]] || q.branches[c])}」へジャンプ</strong>` : ''}
                          </li>
                        `).join('')}
                      </ul>
                    ` : ''}
                  </div>
                `).join('')}
              </div>
            </div>
          `).join('')}
        </div>
      `;
    }

    // 4. アンケート送信タブ
    function renderSurveySend(el) {
      const s = getSurvey();
      if (!s) return;

      const filteredCustomers = state.customers.filter(c => 
        c.name.includes(state.customerSearchQuery) || c.email.includes(state.customerSearchQuery) || c.company.includes(state.customerSearchQuery)
      );

      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">アンケート送信・案内</h2>
            <span class="badge ${s.status==='公開中'?'badge-active':'badge-draft'}">アンケート状態: ${s.status}</span>
          </div>

          ${s.status !== '公開中' ? `
            <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem;">
              ⚠️ このアンケートは「${s.status}」です。メール送信を行うにはアンケートを「公開中」にする必要があります。
            </div>
          ` : ''}

          <!-- メール文面設定 -->
          <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem;">
            <h3 style="font-size: 0.95rem; margin-bottom: 0.75rem;">📧 送信メール内容</h3>
            <div class="form-group">
              <label>メール件名</label>
              <input type="text" class="form-control" id="mail-subject" value="【ご協力のお願い】${s.name}">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label>本文テンプレート（回答専用URLは自動挿入されます）</label>
              <textarea class="form-control" id="mail-body" rows="4">いつも大変お世話になっております。
以下のアンケートへのご協力をお願い申し上げます。

▼ 回答用URL
https://survey.example.com/ans/${s.id}?token={RECIPIENT_TOKEN}

所要時間は約3〜5分です。</textarea>
            </div>
          </div>

          <!-- 送信対象者選択 -->
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
            <h3 style="font-size: 0.95rem;">👥 送信対象顧客の選択（キントーン連携データ）</h3>
            <div style="display: flex; gap: 0.5rem;">
              <input type="text" class="form-control" style="width: 220px;" placeholder="顧客名・メールで検索" value="${state.customerSearchQuery}" oninput="state.customerSearchQuery=this.value; renderSurveySend(document.getElementById('main-content'))">
              <button class="btn btn-outline btn-sm" onclick="selectAllCustomers(true)">全選択</button>
              <button class="btn btn-outline btn-sm" onclick="selectAllCustomers(false)">全解除</button>
            </div>
          </div>

          <table style="margin-bottom: 1.5rem;">
            <thead>
              <tr>
                <th style="width: 40px;"><input type="checkbox" onchange="toggleSelectAll(this.checked)"></th>
                <th>顧客名</th>
                <th>会社名</th>
                <th>メールアドレス</th>
              </tr>
            </thead>
            <tbody>
              ${filteredCustomers.map(c => `
                <tr>
                  <td><input type="checkbox" ${state.selectedCustomerIds.includes(c.id)?'checked':''} onchange="toggleCustomerSelect(${c.id})"></td>
                  <td><strong>${c.name}</strong></td>
                  <td>${c.company}</td>
                  <td>${c.email}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>

          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 0.9rem; font-weight: 600;">選択中: ${state.selectedCustomerIds.length} 名</span>
            <button class="btn btn-primary" ${s.status!=='公開中'||state.selectedCustomerIds.length===0?'disabled':''} onclick="executeSendMail()">送信確認画面へ進む</button>
          </div>

          <!-- 送信履歴・再送一覧 -->
          <hr style="margin: 2rem 0 1.5rem 0; border: none; border-top: 1px solid var(--border);">
          <h3 style="font-size: 0.95rem; margin-bottom: 0.75rem;">📜 送信履歴・ステータス</h3>
          <table>
            <thead>
              <tr>
                <th>対象者</th>
                <th>メールアドレス</th>
                <th>送信状態</th>
                <th>送信日時</th>
                <th style="text-align: right;">操作</th>
              </tr>
            </thead>
            <tbody>
              ${s.recipients && s.recipients.length > 0 ? s.recipients.map(r => `
                <tr>
                  <td>${r.name}</td>
                  <td>${r.email}</td>
                  <td>
                    ${r.status==='送信失敗' ? `<span class="badge badge-closed">${r.status} (${r.error})</span>` : `<span class="badge badge-active">${r.status}</span>`}
                  </td>
                  <td>${r.sentAt}</td>
                  <td style="text-align: right;">
                    ${r.status==='送信失敗' ? `<button class="btn btn-primary btn-sm" onclick="resendMail('${r.email}')">再送する</button>` : '<span style="color:var(--text-muted); font-size: 0.8rem;">完了</span>'}
                  </td>
                </tr>
              `).join('') : `<tr><td colspan="5" style="text-align:center; color: var(--text-muted);">まだ送信履歴がありません</td></tr>`}
            </tbody>
          </table>
        </div>
      `;
    }

    function toggleCustomerSelect(id) {
      if (state.selectedCustomerIds.includes(id)) {
        state.selectedCustomerIds = state.selectedCustomerIds.filter(x => x !== id);
      } else {
        state.selectedCustomerIds.push(id);
      }
      renderSurveySend(document.getElementById('main-content'));
    }

    function selectAllCustomers(all) {
      state.selectedCustomerIds = all ? state.customers.map(c => c.id) : [];
      renderSurveySend(document.getElementById('main-content'));
    }

    function toggleSelectAll(checked) {
      selectAllCustomers(checked);
    }

    function executeSendMail() {
      const s = getSurvey();
      const count = state.selectedCustomerIds.length;
      openModal('送信の最終確認', `
        <p>以下の内容でアンケート依頼メールを送信します。よろしいですか？</p>
        <div style="background:#f8fafc; padding:0.75rem; margin-top:0.5rem; border-radius:4px; font-size:0.85rem;">
          <div><strong>対象者数:</strong> ${count} 件</div>
          <div><strong>件名:</strong> ${document.getElementById('mail-subject').value}</div>
        </div>
      `, () => {
        state.selectedCustomerIds.forEach(cid => {
          const c = state.customers.find(x => x.id === cid);
          if (c) {
            s.recipients.push({
              customerId: c.id,
              name: c.name,
              email: c.email,
              status: '送信済',
              sentAt: '2026-09-25 15:30'
            });
          }
        });
        state.selectedCustomerIds = [];
        showToast('メール送信が完了しました');
        render();
      });
    }

    function resendMail(email) {
      openModal('再送確認', `宛先 ${email} に対して再度回答依頼メールを送信しますか？`, () => {
        showToast(`再送完了: ${email} に送信しました`);
      });
    }

    // 5. 回答状況ダッシュボードタブ
    function renderSurveyStatus(el) {
      const s = getSurvey();
      if (!s) return;

      const sentCount = s.recipients ? s.recipients.length : 0;
      const respCount = s.responses ? s.responses.length : 0;
      const rate = sentCount > 0 ? Math.round((respCount / sentCount) * 100) : 0;

      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">回答状況ダッシュボード</h2>
            <button class="btn btn-outline btn-sm" onclick="render()">最新情報に更新</button>
          </div>

          <div class="stat-grid">
            <div class="stat-card">
              <div class="stat-label">総回答数</div>
              <div class="stat-val">${respCount} <span style="font-size: 1rem; color: var(--text);">件</span></div>
            </div>
            <div class="stat-card">
              <div class="stat-label">案内送信数</div>
              <div class="stat-val">${sentCount} <span style="font-size: 1rem; color: var(--text);">通</span></div>
            </div>
            <div class="stat-card">
              <div class="stat-label">回答率</div>
              <div class="stat-val" style="color: var(--success);">${rate}%</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">未回答者数</div>
              <div class="stat-val" style="color: var(--warning);">${Math.max(0, sentCount - respCount)} <span style="font-size: 1rem; color: var(--text);">人</span></div>
            </div>
          </div>

          <h3 style="font-size: 0.95rem; margin-bottom: 0.75rem;">📅 日別回答受付推移</h3>
          <table style="margin-bottom: 1.5rem;">
            <thead>
              <tr>
                <th>日付</th>
                <th>受付件数</th>
                <th>進捗割合</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>2026-09-03</td>
                <td>1 件</td>
                <td><div class="chart-bar-bg"><div class="chart-bar-fill" style="width: 33%;"></div></div></td>
              </tr>
              <tr>
                <td>2026-09-04</td>
                <td>1 件</td>
                <td><div class="chart-bar-bg"><div class="chart-bar-fill" style="width: 33%;"></div></div></td>
              </tr>
              <tr>
                <td>2026-09-05</td>
                <td>1 件</td>
                <td><div class="chart-bar-bg"><div class="chart-bar-fill" style="width: 33%;"></div></div></td>
              </tr>
            </tbody>
          </table>
        </div>
      `;
    }

    // 6. 回答結果・集計タブ
    function renderSurveyResult(el) {
      const s = getSurvey();
      if (!s) return;
      const qNumMap = calculateQuestionNumbers(s);
      const totalResponses = s.responses ? s.responses.length : 0;

      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">回答結果集計（総回答数: ${totalResponses}件）</h2>
            <button class="btn btn-outline btn-sm" onclick="showToast('CSV出力機能は本番環境で利用可能です')">CSVダウンロード</button>
          </div>

          ${totalResponses === 0 ? `
            <div style="text-align: center; padding: 3rem; color: var(--text-muted);">まだ回答データが集まっていません</div>
          ` : `
            <div>
              ${s.groups.map(g => `
                <div style="margin-bottom: 2rem;">
                  <h3 style="background: #f1f5f9; padding: 0.5rem 0.75rem; border-radius: 4px; font-size: 1rem; margin-bottom: 1rem;">📁 ${g.name}</h3>
                  ${g.questions.map(q => {
                    return `
                      <div class="card" style="margin-bottom: 1rem; border-left: 4px solid var(--primary);">
                        <div style="font-weight: 600; margin-bottom: 0.75rem;">
                          <span style="color: var(--primary);">${qNumMap[q.id]}</span> ${q.text}
                          <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-muted);">(${q.type==='single'?'単一選択':(q.type==='multiple'?'複数選択':'自由記述')})</span>
                        </div>

                        ${q.type === 'text' ? `
                          <!-- 自由記述一覧 -->
                          <div style="background: #f8fafc; border-radius: 6px; padding: 0.75rem;">
                            ${s.responses.map(r => r.answers[q.id] ? `
                              <div style="padding: 0.5rem 0; border-bottom: 1px dashed var(--border); font-size: 0.9rem;">
                                💬 ${r.answers[q.id]} <span style="font-size: 0.75rem; color: var(--text-muted); float: right;">${r.customerName} (${r.answeredAt})</span>
                              </div>
                            ` : '').join('')}
                          </div>
                        ` : `
                          <!-- 選択肢集計 -->
                          <div>
                            ${q.choices.map(c => {
                              let count = 0;
                              s.responses.forEach(r => {
                                const val = r.answers[q.id];
                                if (Array.isArray(val) && val.includes(c)) count++;
                                if (val === c) count++;
                              });
                              const pct = totalResponses > 0 ? Math.round((count / totalResponses) * 100) : 0;
                              return `
                                <div style="margin-bottom: 0.6rem;">
                                  <div style="display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 500;">
                                    <span>${c}</span>
                                    <span>${count} 票 (${pct}%)</span>
                                  </div>
                                  <div class="chart-bar-bg">
                                    <div class="chart-bar-fill" style="width: ${pct}%;"></div>
                                  </div>
                                </div>
                              `;
                            }).join('')}
                          </div>
                        `}
                      </div>
                    `;
                  }).join('')}
                </div>
              `).join('')}
            </div>
          `}
        </div>
      `;
    }

    // 7. 顧客一覧画面
    function renderCustomerList(el) {
      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">顧客一覧（キントーン同期）</h2>
            <button class="btn btn-primary" onclick="syncKintone()">🔄 キントーンから最新情報を取得</button>
          </div>
          <table style="margin-top: 1rem;">
            <thead>
              <tr>
                <th>顧客ID</th>
                <th>氏名</th>
                <th>会社名</th>
                <th>メールアドレス</th>
              </tr>
            </thead>
            <tbody>
              ${state.customers.map(c => `
                <tr>
                  <td>${c.id}</td>
                  <td><strong>${c.name}</strong></td>
                  <td>${c.company}</td>
                  <td>${c.email}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `;
    }

    function syncKintone() {
      showToast('キントーンと同期中...');
      setTimeout(() => {
        showToast('キントーンからの最新データ同期が完了しました（5件）');
      }, 800);
    }

    // 8. 設定画面
    function renderSettings(el) {
      el.innerHTML = `
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">システム連携・メール設定</h2>
          </div>

          <!-- SMTP設定 -->
          <div style="margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">
              <h3 style="font-size: 1rem;">📧 メール送信設定 (SMTP)</h3>
              <button class="btn btn-outline btn-sm" onclick="testSmtp()">SMTP接続確認テスト</button>
            </div>
            <div class="form-row">
              <div class="form-group"><label>SMTPホスト</label><input type="text" class="form-control" value="${state.settings.smtp.host}"></div>
              <div class="form-group"><label>ポート番号</label><input type="text" class="form-control" value="${state.settings.smtp.port}"></div>
              <div class="form-group"><label>暗号化方式</label>
                <select class="form-control">
                  <option>TLS</option><option>SSL</option><option>None</option>
                </select>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>認証ユーザー</label><input type="text" class="form-control" value="${state.settings.smtp.user}"></div>
              <div class="form-group"><label>認証パスワード</label><input type="password" class="form-control" value="${state.settings.smtp.pass}"></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>送信元アドレス (From)</label><input type="text" class="form-control" value="${state.settings.smtp.fromEmail}"></div>
              <div class="form-group"><label>送信元表示名</label><input type="text" class="form-control" value="${state.settings.smtp.fromName}"></div>
            </div>
          </div>

          <!-- キントーン連携設定 -->
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">
              <h3 style="font-size: 1rem;">🔷 キントーン連携設定</h3>
              <button class="btn btn-outline btn-sm" onclick="testKintone()">キントーン接続確認テスト</button>
            </div>
            <div class="form-row">
              <div class="form-group" style="flex: 2;"><label>キントーンURL (サブドメイン)</label><input type="text" class="form-control" value="${state.settings.kintone.host}"></div>
              <div class="form-group"><label>顧客管理アプリID</label><input type="text" class="form-control" value="${state.settings.kintone.appId}"></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>ログイン名</label><input type="text" class="form-control" value="${state.settings.kintone.login}"></div>
              <div class="form-group"><label>パスワード</label><input type="password" class="form-control" value="${state.settings.kintone.pass}"></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>顧客名 フィールドコード</label><input type="text" class="form-control" value="${state.settings.kintone.nameField}"></div>
              <div class="form-group"><label>メールアドレス フィールドコード</label><input type="text" class="form-control" value="${state.settings.kintone.emailField}"></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>プロキシホスト</label><input type="text" class="form-control" value="${state.settings.kintone.proxyHost}"></div>
              <div class="form-group"><label>プロキシポート</label><input type="text" class="form-control" value="${state.settings.kintone.proxyPort}"></div>
            </div>
          </div>

          <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem;">
            <button class="btn btn-primary" onclick="showToast('設定を保存しました')">設定を保存</button>
          </div>
        </div>
      `;
    }

    function testSmtp() {
      showToast('SMTPサーバーに接続テスト中...');
      setTimeout(() => { showToast('✅ SMTP接続に成功しました'); }, 700);
    }

    function testKintone() {
      showToast('キントーンAPIに接続テスト中...');
      setTimeout(() => { showToast('✅ キントーン接続・アプリ取得に成功しました'); }, 700);
    }

    // 9. 回答者専用画面（独立プレビュー）
    let respondentAnswers = {};
    function openRespondentPreview() {
      respondentAnswers = {};
      navigate('respondent');
    }

    function renderRespondentView(el) {
      const s = getSurvey();
      if (!s) return;
      const qNumMap = calculateQuestionNumbers(s);

      el.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center; max-width: 720px; margin: 1rem auto 0 auto; padding: 0 1rem;">
          <span style="font-size: 0.85rem; color: var(--text-muted);">👀 回答者画面のプレビュー表示中</span>
          <button class="btn btn-outline btn-sm" onclick="navigate('detail', ${s.id})">管理画面へ戻る</button>
        </div>

        <div class="respondent-view">
          <h1 style="font-size: 1.5rem; margin-bottom: 0.5rem; color: var(--primary);">${s.name}</h1>
          <p style="font-size: 0.95rem; color: #475569; margin-bottom: 1.5rem; white-space: pre-wrap;">${s.description}</p>
          <hr style="margin-bottom: 1.5rem; border: none; border-top: 1px solid var(--border);">

          <form id="resp-form" onsubmit="event.preventDefault(); submitRespondentAnswer();">
            ${s.groups.map(g => `
              <div style="margin-bottom: 2rem;">
                <h2 style="font-size: 1.1rem; margin-bottom: 1rem; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.35rem;">${g.name}</h2>
                ${g.questions.map(q => `
                  <div id="resp-q-box-${q.id}" class="resp-q-box" style="margin-bottom: 1.5rem;">
                    <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">
                      <span style="color: var(--primary);">${qNumMap[q.id]}</span> ${q.text}
                      ${q.required ? '<span style="color: var(--danger);">*</span>' : '<span style="color: var(--text-muted); font-size: 0.8rem; font-weight: normal;">(任意)</span>'}
                    </label>

                    ${q.type === 'single' ? `
                      <div>
                        ${q.choices.map(c => `
                          <label style="display: block; margin-bottom: 0.4rem; cursor: pointer;">
                            <input type="radio" name="resp_${q.id}" value="${c}" ${q.required?'required':''} onchange="handleRespondentBranch('${q.id}', '${c}')"> ${c}
                          </label>
                        `).join('')}
                      </div>
                    ` : (q.type === 'multiple' ? `
                      <div>
                        ${q.choices.map(c => `
                          <label style="display: block; margin-bottom: 0.4rem; cursor: pointer;">
                            <input type="checkbox" name="resp_${q.id}" value="${c}"> ${c}
                          </label>
                        `).join('')}
                      </div>
                    ` : `
                      <textarea class="form-control" name="resp_${q.id}" rows="3" placeholder="ご自由にご記入ください" ${q.required?'required':''}></textarea>
                    `)}
                  </div>
                `).join('')}
              </div>
            `).join('')}

            <div style="text-align: center; margin-top: 2rem;">
              <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2.5rem; font-size: 1rem;">回答を送信する</button>
            </div>
          </form>
        </div>
      `;
    }

    function handleRespondentBranch(qId, choice) {
      const s = getSurvey();
      const allQ = [];
      s.groups.forEach(g => g.questions.forEach(q => allQ.push(q)));
      const currQ = allQ.find(q => q.id === qId);

      if (currQ && currQ.branches && currQ.branches[choice]) {
        const targetId = currQ.branches[choice];
        if (targetId === '__END__') {
          showToast('アンケート回答終了条件が選択されました');
        } else {
          showToast(`分岐: 次の対象質問へ案内します`);
        }
      }
    }

    function submitRespondentAnswer() {
      const s = getSurvey();
      s.responses.push({
        id: Date.now(),
        customerName: 'プレビュー回答者',
        answeredAt: '2026-09-25',
        answers: { q1: 'スタンダード', q2: '毎日', q3: ['ダッシュボード'], q4: 'モックアップからのテスト回答です。' }
      });
      openModal('回答送信完了', `
        <div style="text-align: center; padding: 1rem;">
          <div style="font-size: 2rem; margin-bottom: 0.5rem;">🎉</div>
          <h3 style="margin-bottom: 0.5rem;">アンケート回答を受け付けました</h3>
          <p style="color: var(--text-muted); font-size: 0.9rem;">ご協力ありがとうございました。</p>
        </div>
      `, () => {
        navigate('result', s.id);
      });
    }

    // 初期起動
    document.addEventListener('DOMContentLoaded', () => {
      navigate('survey-list');
    });
  </script>
</body>
</html>
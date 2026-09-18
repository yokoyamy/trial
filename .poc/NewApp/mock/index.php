// ==========================================================
// B. 保存 ＆ チェックポイント作成 (saveFile)
// ==========================================================
async function saveFile() {
    const editorEl = document.getElementById('editor-content');
    const memoEl = document.getElementById('editor-memo');
    const promptEl = document.getElementById('prompt-content');
    const saveBtn = document.getElementById('btn-save-current') || document.getElementById('btn-save');

    const editorContent = editorEl ? editorEl.value : '';
    const memo = memoEl ? memoEl.value.trim() : '';
    const prompt = promptEl ? promptEl.value : '';
    
    // 現在選択中のタブキー
    const activeTab = window.currentPocTab || window.currentActiveTab || 'spec-business_ui';
    
    // POC_TAB_CONFIG から設定を取得
    const config = window.POC_TAB_CONFIG?.[activeTab];

    if (!config || !config.targetFile) {
        alert('対象ファイルが特定できません。（現在のタブ: ' + activeTab + '）');
        return;
    }

    // targetFile の取得（impement.md のタイポを正規化）
    let targetFile = config.targetFile;
    if (targetFile === 'spec/impement.md') {
        targetFile = 'spec/implement.md';
    }

    const appName = typeof currentAppName !== 'undefined' ? currentAppName : (typeof getActiveAppPath === 'function' ? getActiveAppPath() : '');

    if (!appName) {
        alert('アプリ/パスが選択されていません。');
        return;
    }

    // 1. ローカル保存 ＆ スナップショット作成
    const saveRes = await apiCall('create_checkpoint', {
        app_name: appName,
        target_file: targetFile,
        type: activeTab,
        content: editorContent,
        memo: memo,
        prompt: prompt
    });

    if (!saveRes || !saveRes.success) {
        alert('保存に失敗しました: ' + (saveRes ? saveRes.error : 'エラーが発生しました'));
        return;
    }

    // ----------------------------------------------------
    // 2. GitHub同期判定（画面の実ID: app-github-sync-toggle を参照）
    // ----------------------------------------------------
    const syncCheckbox = document.getElementById('app-github-sync-toggle');
    const isSyncEnabled = syncCheckbox ? syncCheckbox.checked : false;

    if (isSyncEnabled) {
        const githubRes = await apiCall('github_sync', {
            app_name: appName,
            target_file: targetFile,
            path: `.poc/${appName}/${targetFile}`,
            content: editorContent,
            label: memo || `Update ${targetFile}`,
            stage: activeTab
        });

        if (!githubRes || !githubRes.success) {
            alert('ローカル保存完了、GitHub同期失敗: ' + (githubRes ? githubRes.error : 'エラーが発生しました'));
            return;
        }

        // 同期成功時に画面のRAW URL要素を更新
        const rawUrl = githubRes.raw_url || githubRes.download_url;
        if (rawUrl) {
            const targetIdMap = {
                'spec-business_ui':    'tpl-spec-business-url',
                'spec/business_ui.md': 'tpl-spec-business-url',
                'spec-implement':      'tpl-spec-implement-url',
                'spec/implement.md':   'tpl-spec-implement-url',
                'mock':                'tpl-mock-url',
                'mock/index.php':      'tpl-mock-url',
                'draft':               'tpl-draft-url',
                'draft/index.php':     'tpl-draft-url'
            };
            const elemId = targetIdMap[activeTab] || targetIdMap[targetFile];
            if (elemId) {
                const el = document.getElementById(elemId);
                if (el) el.innerText = rawUrl;
            }
        }

        alert('ローカル保存 ＆ GitHub同期が完了しました');
    } else {
        alert('ローカル保存が完了しました');
    }

    // 3. 保存完了後のUIリセット
    if (saveBtn) {
        saveBtn.style.setProperty('display', 'none', 'important');
    }
    if (memoEl) {
        memoEl.value = '';
        memoEl.style.setProperty('display', 'none', 'important');
    }
    if (typeof window.originalEditorContent !== 'undefined') {
        window.originalEditorContent = editorContent;
    }

    // 履歴リストなどの再読み込み
    if (typeof loadHistoryList === 'function') {
        loadHistoryList();
    }

    // 4. プレビュー領域の自動リロード
    if (typeof reloadPreview === 'function') {
        reloadPreview();
    }
}
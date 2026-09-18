(function() {
    console.log("%c=== DEBUG TRACER INITIALIZED ===", "background: #222; color: #bada55; font-size: 14px;");

    // 1. 全クリックイベントの追跡
    document.addEventListener('click', function(e) {
        const target = e.target.closest('[onclick], button, a, li, tr, .item, .folder, .app-item');
        if (target) {
            console.groupCollapsed("%c[CLICK DETECTED]", "color: #00f; font-weight: bold;", target);
            console.log("Element:", target);
            console.log("ID:", target.id);
            console.log("Classes:", target.className);
            console.log("onclick attribute:", target.getAttribute('onclick'));
            console.log("Dataset:", target.dataset);
            console.groupEnd();
        }
    }, true);

    // 2. apiCall の追跡
    if (typeof window.apiCall === 'function') {
        const _origApiCall = window.apiCall;
        window.apiCall = async function(action, params) {
            console.group(`%c[API CALL] %c${action}`, "color: #e91e63; font-weight: bold;", "color: #333; font-weight: bold;");
            console.log("Action:", action);
            console.log("Params:", params);
            try {
                const res = await _origApiCall(action, params);
                console.log("Response:", res);
                console.groupEnd();
                return res;
            } catch (err) {
                console.error("API Error:", err);
                console.groupEnd();
                throw err;
            }
        };
    } else {
        console.warn("[DEBUG] window.apiCall is not defined globally.");
    }

    // 3. fetch の追跡（apiCall を経由しない通信のキャッチ）
    const _origFetch = window.fetch;
    window.fetch = async function(...args) {
        console.log("%c[FETCH REQUEST]", "color: #ff9800; font-weight: bold;", args);
        try {
            const res = await _origFetch.apply(this, args);
            console.log("%c[FETCH RESPONSE STATUS]", "color: #ff9800;", res.status, args[0]);
            return res;
        } catch (err) {
            console.error("[FETCH ERROR]", err, args[0]);
            throw err;
        }
    };

    // 4. グローバル変数の現状チェック
    console.group("%c[CURRENT STATE DUMP]", "color: #673ab7; font-weight: bold;");
    console.log("currentAppName:", typeof currentAppName !== 'undefined' ? currentAppName : 'undefined');
    console.log("currentPocTab:", typeof currentPocTab !== 'undefined' ? currentPocTab : 'undefined');
    console.log("currentActiveTab:", typeof currentActiveTab !== 'undefined' ? currentActiveTab : 'undefined');
    console.log("POC_TAB_CONFIG:", typeof POC_TAB_CONFIG !== 'undefined' ? POC_TAB_CONFIG : 'undefined');
    console.log("preview-frame DOM:", document.getElementById('preview-frame'));
    console.log("folder-status-overlay DOM:", document.getElementById('folder-status-overlay'));
    console.groupEnd();
})();
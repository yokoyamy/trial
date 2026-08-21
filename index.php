<?php
/*
========================================================================
GUARD COMMENT — 固定名称一覧
※以下の名称は、今後の修正・再生成時も変更・削除禁止。

ストレージ:
- survey_storage_directory
- survey_storage_file
- survey_admin_session_v1

データトップキー:
- surveys
- responses
- customers
- settings
- mail_logs

アンケート項目:
- id
- title
- start_at
- end_at
- status
- created_at
- updated_at
- numbering_mode
- groups
- deleted

グループ項目:
- id
- name
- questions

質問項目:
- id
- text
- type
- required
- options
- other_enabled

質問形式:
- single
- multiple
- text

顧客項目:
- id
- company
- name
- email
- department
- phone
- address
- source
- sent_at
- send_count
- answer_status
- kintone_status

回答項目:
- id
- survey_id
- customer_id
- company
- name
- email
- answered_at
- answers

設定項目:
- subdomain
- login_name
- password
- app_id
- ssl_verify
- proxy
- field_company
- field_name
- field_email
- field_department
- field_phone
- field_address

POST/GETパラメータ:
- action
- survey_id
- customer_id
- response_id
- keyword
- status_filter
- sort
- survey_json
- settings_json
- csrf_token
- recipient_ids
- mail_subject
- mail_body
- template_type
- app_id

API/JSONキー:
- properties
- records
- label
- code
- type
- message
- ok
- fields

HTML DOM ID / JS参照名:
- app
- csrf_token
- survey_title
- survey_start_at
- survey_end_at
- survey_numbering_mode
- question_editor
- preview_modal
- preview_content
- response_modal
- response_detail
- response_filter
- response_table
- customer_filter
- customer_table
- select_all
- mail_subject
- mail_body
- template_type
- settings_form
- settings_json
- setting_subdomain
- setting_app_id
- setting_login_name
- setting_password
- setting_proxy
- setting_ssl_verify
- field_message

取り得る値:
- status: draft / active / ended
- numbering_mode: global / group
- type: single / multiple / text
- source: kintone / web
- answer_status: unanswered / answered
- kintone_status: unregistered / registered
- template_type: initial / reminder
========================================================================
*/

define('SURVEY_STORAGE_DIRECTORY', __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage');
define('SURVEY_STORAGE_FILE', SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json');
define('SURVEY_ADMIN_SESSION', 'survey_admin_session_v1');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0777, true);
}
if (!file_exists(SURVEY_STORAGE_FILE)) {
    $initial_data = [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [
            'subdomain' => '',
            'login_name' => '',
            'password' => '',
            'app_id' => '',
            'ssl_verify' => true,
            'proxy' => '',
            'field_company' => '',
            'field_name' => '',
            'field_email' => '',
            'field_department' => '',
            'field_phone' => '',
            'field_address' => ''
        ],
        'mail_logs' => []
    ];
    @file_put_contents(SURVEY_STORAGE_FILE, json_encode($initial_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

session_start();
if (empty($_SESSION[SURVEY_ADMIN_SESSION])) {
    $_SESSION[SURVEY_ADMIN_SESSION] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION[SURVEY_ADMIN_SESSION];

// Helper: Load Data
function load_survey_data() {
    if (!file_exists(SURVEY_STORAGE_FILE)) return ['surveys'=>[], 'responses'=>[], 'customers'=>[], 'settings'=>[], 'mail_logs'=>[]];
    $json = @file_get_contents(SURVEY_STORAGE_FILE);
    $data = json_decode($json, true);
    return is_array($data) ? $data : ['surveys'=>[], 'responses'=>[], 'customers'=>[], 'settings'=>[], 'mail_logs'=>[]];
}

// Helper: Save Data
function save_survey_data($data) {
    @file_put_contents(SURVEY_STORAGE_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// Helper: Kintone Proxy Request
function proxy_kintone_request($endpoint, $method = 'GET', $body = null) {
    $data = load_survey_data();
    $set = $data['settings'] ?? [];
    
    $subdomain = trim($set['subdomain'] ?? '');
    // normalize subdomain/host
    $subdomain = preg_replace('#^https?://#', '', $subdomain);
    $subdomain = rtrim($subdomain, '/');
    if (empty($subdomain)) {
        return ['status' => 0, 'body' => '', 'json' => null, 'error' => 'kintoneドメインが設定されていません', 'url' => '', 'proxy_used' => false];
    }
    $url = "https://{$subdomain}/k/v1/{$endpoint}";
    
    $proxy = trim($set['proxy'] ?? '');
    $ssl_verify = !empty($set['ssl_verify']);
    $login_name = $set['login_name'] ?? '';
    $password = $set['password'] ?? '';
    
    $headers = [
        "X-Cybozu-Authorization: " . base64_encode("{$login_name}:{$password}"),
        "Content-Type: application/json"
    ];
    
    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 15,
            'ignore_errors' => true,
            'protocol_version' => 1.1
        ],
        'ssl' => [
            'verify_peer' => $ssl_verify,
            'verify_peer_name' => $ssl_verify,
            'allow_self_signed' => !$ssl_verify,
            'SNI_enabled' => true
        ]
    ];
    
    $proxy_used = false;
    if (!empty($proxy)) {
        $opts['http']['proxy'] = $proxy;
        $opts['http']['request_fulluri'] = true;
        $proxy_used = true;
    }
    
    if ($body !== null) {
        $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    
    $context = stream_context_create($opts);
    
    $php_error = '';
    set_error_handler(function($errno, $errstr) use (&$php_error) {
        $php_error = $errstr;
    });
    
    $response_body = @file_get_contents($url, false, $context);
    restore_error_handler();
    
    $status = 0;
    $res_headers = [];
    if (function_exists('http_get_last_response_headers')) {
        $res_headers = http_get_last_response_headers() ?: [];
    } elseif (isset($http_response_header)) {
        $res_headers = $http_response_header;
    }
    
    if (!empty($res_headers)) {
        foreach ($res_headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#i', $h, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
    }
    
    $json = json_decode($response_body, true);
    
    return [
        'status' => $status,
        'body' => $response_body,
        'json' => $json,
        'error' => $php_error,
        'url' => $url,
        'proxy_used' => $proxy_used
    ];
}

// API Router
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['api'];
    $data = load_survey_data();
    
    if ($action === 'get_all') {
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($action === 'save_survey') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['csrf_token']) || $input['csrf_token'] !== $csrf_token) {
            echo json_encode(['ok' => false, 'message' => 'CSRF token mismatch']);
            exit;
        }
        $survey = $input['survey'] ?? null;
        if ($survey) {
            $surveys = $data['surveys'];
            $found = false;
            foreach ($surveys as &$s) {
                if ($s['id'] === $survey['id']) {
                    $s = $survey;
                    $s['updated_at'] = date('Y-m-d H:i:s');
                    $found = true;
                    break;
                }
            }
            unset($s);
            if (!$found) {
                $survey['created_at'] = date('Y-m-d H:i:s');
                $survey['updated_at'] = date('Y-m-d H:i:s');
                $surveys[] = $survey;
            }
            $data['surveys'] = $surveys;
            save_survey_data($data);
            echo json_encode(['ok' => true]);
            exit;
        }
    }
    
    if ($action === 'delete_survey') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $data['surveys'] = array_values(array_filter($data['surveys'], fn($s) => $s['id'] !== $id));
        save_survey_data($data);
        echo json_encode(['ok' => true]);
        exit;
    }
    
    if ($action === 'update_status') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $status = $input['status'] ?? '';
        foreach ($data['surveys'] as &$s) {
            if ($s['id'] === $id) {
                $s['status'] = $status;
                $s['updated_at'] = date('Y-m-d H:i:s');
            }
        }
        unset($s);
        save_survey_data($data);
        echo json_encode(['ok' => true]);
        exit;
    }
    
    if ($action === 'save_settings') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['csrf_token']) || $input['csrf_token'] !== $csrf_token) {
            echo json_encode(['ok' => false, 'message' => 'CSRF token mismatch']);
            exit;
        }
        $settings = $input['settings'] ?? [];
        $data['settings'] = array_merge($data['settings'], $settings);
        save_survey_data($data);
        echo json_encode(['ok' => true]);
        exit;
    }
    
    if ($action === 'test_kintone') {
        $input = json_decode(file_get_contents('php://input'), true);
        $app_id = $input['app_id'] ?? $data['settings']['app_id'] ?? '';
        if (empty($app_id)) {
            echo json_encode(['ok' => false, 'message' => 'アプリIDが指定されていません', 'status' => 0]);
            exit;
        }
        $res = proxy_kintone_request("app/form/fields.json?app=" . rawurlencode($app_id), 'GET');
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode(['ok' => false, 'message' => 'Invalid action']);
    exit;
}

// CSV Export Handler
if (isset($_GET['export_csv'])) {
    $survey_id = $_GET['export_csv'];
    $data = load_survey_data();
    $target_survey = null;
    foreach ($data['surveys'] as $s) {
        if ($s['id'] === $survey_id) { $target_survey = $s; break; }
    }
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="survey_report_' . $survey_id . '.csv"');
    
    $output = fopen('php://output', 'w');
    // BOM for Excel
    fwrite($output, "\xEF\xBB\xBF");
    
    $headers = ['回答ID', '回答日時', '顧客ID', '会社名', '氏名'];
    $questions_map = [];
    if ($target_survey) {
        foreach ($target_survey['groups'] as $g) {
            foreach ($g['questions'] as $q) {
                $headers[] = $q['text'] . ' (' . $q['id'] . ')';
                $questions_map[] = $q['id'];
            }
        }
    }
    fputcsv($output, $headers);
    
    foreach ($data['responses'] as $r) {
        if ($r['survey_id'] !== $survey_id) continue;
        $row = [
            $r['id'],
            $r['answered_at'],
            $r['customer_id'] ?? '',
            $r['company'] ?? '',
            $r['name'] ?? ''
        ];
        foreach ($questions_map as $qid) {
            $ans = $r['answers'][$qid] ?? '';
            if (is_array($ans)) $ans = implode(', ', $ans);
            $row[] = $ans;
        }
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>アンケート管理システム</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/SortableJS/1.15.0/Sortable.min.js"></script>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen flex flex-col font-sans">
    <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
    
    <!-- 固定共通ヘッダー -->
    <header class="bg-white border-b border-gray-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between">
            <h1 class="text-xl font-bold text-gray-700 flex items-center gap-2">
                📊 アンケート管理システム
            </h1>
            <nav class="flex items-center gap-6">
                <button onclick="App.router.navigate('list')" class="text-sm font-medium hover:text-indigo-600 transition">アンケート一覧</button>
                <button onclick="App.router.navigate('settings')" class="text-sm font-medium hover:text-indigo-600 transition">キントーン連携設定</button>
                <span class="text-xs bg-gray-100 text-gray-600 px-3 py-1 rounded-full border">ログアウト（ゲスト）</span>
            </nav>
        </div>
    </header>

    <!-- メインコンテンツ領域 (SPA) -->
    <main id="app" class="flex-1 max-w-7xl w-full mx-auto p-6">
        <!-- 画面テンプレートがここに動的描画されます -->
    </main>

    <!-- モーダル・プレビュー用 -->
    <div id="modal_container"></div>

    <script>
    window.App = {
        state: {
            currentView: 'list', // list, editor, mail, report, settings
            data: { surveys: [], responses: [], customers: [], settings: {}, mail_logs: [] },
            activeSurveyId: null,
            filter: { keyword: '', status: 'all', sort: 'updated_desc' },
            previewData: null
        },
        init() {
            this.loadData(() => {
                this.router.navigate('list');
            });
        },
        loadData(callback) {
            fetch('?api=get_all')
                .then(res => res.json())
                .then(json => {
                    if (json.ok) {
                        this.state.data = json.data;
                        if (callback) callback();
                    }
                });
        },
        router: {
            navigate(view, param = null) {
                App.state.currentView = view;
                App.state.activeSurveyId = param;
                App.render();
            }
        },
        render() {
            const container = document.getElementById('app');
            switch(this.state.currentView) {
                case 'list': container.innerHTML = this.views.renderList(); this.initListEvents(); break;
                case 'editor': container.innerHTML = this.views.renderEditor(); this.initEditorEvents(); break;
                case 'mail': container.innerHTML = this.views.renderMail(); break;
                case 'report': container.innerHTML = this.views.renderReport(); break;
                case 'settings': container.innerHTML = this.views.renderSettings(); this.initSettingsEvents(); break;
                default: container.innerHTML = '<p>画面が見つかりません</p>';
            }
        },
        views: {
            renderList() {
                const surveys = App.state.data.surveys;
                return `
                <div class="space-y-6">
                    <div class="flex justify-between items-center bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                        <div class="flex items-center gap-3">
                            <input type="text" id="search_keyword" placeholder="タイトル検索..." class="border rounded-lg px-3 py-2 text-sm w-64 focus:outline-indigo-500">
                            <select id="status_filter" class="border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">
                                <option value="all">すべて</option>
                                <option value="active">公開中</option>
                                <option value="draft">下書き</option>
                                <option value="ended">終了</option>
                            </select>
                        </div>
                        <button onclick="App.actions.createNewSurvey()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm">
                            + 新規アンケート作成
                        </button>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-100 text-xs text-gray-500 uppercase tracking-wider">
                                    <th class="p-4">タイトル / 期間</th>
                                    <th class="p-4">作成日 / 更新日</th>
                                    <th class="p-4">ステータス</th>
                                    <th class="p-4">回答数</th>
                                    <th class="p-4 text-right">アクション</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-sm">
                                ${surveys.length === 0 ? `<tr><td colspan="5" class="p-8 text-center text-gray-400">アンケートがありません。「+ 新規アンケート作成」から作成してください。</td></tr>` : 
                                  surveys.map(s => {
                                      const ansCount = App.state.data.responses.filter(r => r.survey_id === s.id).length;
                                      const badgeColor = s.status === 'active' ? 'bg-emerald-50 text-emerald-600 border-emerald-200' : (s.status === 'draft' ? 'bg-amber-50 text-amber-600 border-amber-200' : 'bg-gray-100 text-gray-600 border-gray-200');
                                      const statusText = s.status === 'active' ? '公開中' : (s.status === 'draft' ? '下書き' : '終了');
                                      return `
                                      <tr class="hover:bg-gray-50/50 transition">
                                          <td class="p-4">
                                              <div class="font-bold text-gray-800">${escapeHtml(s.title)}</div>
                                              <div class="text-xs text-gray-400 mt-0.5">期間: ${s.start_at || '未設定'} ～ ${s.end_at || '未設定'}</div>
                                          </td>
                                          <td class="p-4 text-xs text-gray-500">
                                              <div>${s.created_at || '-'}</div>
                                              <div class="text-gray-400 mt-0.5">更新: ${s.updated_at || '-'}</div>
                                          </td>
                                          <td class="p-4"><span class="px-2.5 py-1 rounded-full text-xs font-semibold border ${badgeColor}">${statusText}</span></td>
                                          <td class="p-4 font-medium">${ansCount} 件</td>
                                          <td class="p-4 text-right space-x-2">
                                              <button onclick="App.router.navigate('editor', '${s.id}')" class="text-indigo-600 hover:underline font-medium">確認・編集</button>
                                              <button onclick="App.router.navigate('report', '${s.id}')" class="text-emerald-600 hover:underline font-medium">集計</button>
                                              <button onclick="App.router.navigate('mail', '${s.id}')" class="text-blue-600 hover:underline font-medium">送信</button>
                                              <button onclick="App.actions.duplicateSurvey('${s.id}')" class="text-gray-600 hover:underline font-medium">複製</button>
                                              ${s.status === 'active' ? `<button onclick="App.actions.updateStatus('${s.id}', 'ended')" class="text-red-600 hover:underline font-medium">停止</button>` : ''}
                                              ${s.status === 'draft' ? `<button onclick="App.actions.deleteSurvey('${s.id}')" class="text-red-600 hover:underline font-medium">削除</button>` : ''}
                                          </td>
                                      </tr>`;
                                  }).join('')
                                }
                            </tbody>
                        </table>
                    </div>
                </div>`;
            },
            renderEditor() {
                const id = App.state.activeSurveyId;
                const survey = App.state.data.surveys.find(s => s.id === id) || {
                    id: 'sur_' + Date.now(),
                    title: '無題のアンケート',
                    start_at: '',
                    end_at: '',
                    status: 'draft',
                    numbering_mode: 'global',
                    groups: [{ id: 'grp_' + Date.now(), name: '基本グループ', questions: [] }]
                };
                App.state.activeSurveyObj = survey;

                return `
                <div class="space-y-6">
                    <div class="flex justify-between items-center bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                        <input type="text" id="survey_title" value="${escapeHtml(survey.title)}" class="text-lg font-bold border-b border-gray-300 px-2 py-1 focus:outline-indigo-500 w-1/2" placeholder="アンケートタイトル">
                        <div class="space-x-3">
                            <button onclick="App.actions.previewSurvey()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">プレビュー</button>
                            <button onclick="App.actions.saveSurvey()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm">保存して一覧へ戻る</button>
                            <button onclick="App.router.navigate('list')" class="bg-gray-50 hover:bg-gray-100 text-gray-500 px-4 py-2 rounded-lg text-sm font-medium transition border">キャンセル</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 space-y-4 lg:col-span-1 h-fit">
                            <h2 class="font-bold text-gray-700 border-b pb-2">基本設定</h2>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">アンケート期間 (開始)</label>
                                <input type="datetime-local" id="survey_start_at" value="${survey.start_at || ''}" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">アンケート期間 (終了)</label>
                                <input type="datetime-local" id="survey_end_at" value="${survey.end_at || ''}" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">質問番号形式</label>
                                <select id="survey_numbering_mode" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">
                                    <option value="global" ${survey.numbering_mode==='global'?'selected':''}>全体通し番号 (Q1, Q2...)</option>
                                    <option value="group" ${survey.numbering_mode==='group'?'selected':''}>グループ別番号 (Q1-1, Q1-2...)</option>
                                </select>
                            </div>
                            <button onclick="App.actions.addGroup()" class="w-full bg-gray-50 hover:bg-gray-100 text-indigo-600 border border-indigo-200 py-2 rounded-lg text-sm font-medium transition">+ グループ追加</button>
                        </div>

                        <div id="question_editor" class="space-y-4 lg:col-span-2">
                            ${survey.groups.map((g, gIdx) => `
                            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 space-y-4 group-card" data-group-id="${g.id}">
                                <div class="flex items-center justify-between border-b pb-3">
                                    <div class="flex items-center gap-2 w-full">
                                        <span class="cursor-move text-gray-400">⠿</span>
                                        <input type="text" value="${escapeHtml(g.name)}" onchange="App.actions.updateGroupName('${g.id}', this.value)" class="font-bold border-b border-transparent hover:border-gray-300 focus:border-indigo-500 px-2 py-1 text-gray-700 w-1/2">
                                    </div>
                                    <div class="space-x-2 flex items-center">
                                        <button onclick="App.actions.addQuestion('${g.id}')" class="text-xs bg-indigo-50 text-indigo-600 px-3 py-1.5 rounded-lg font-medium hover:bg-indigo-100">+ 質問追加</button>
                                        <button onclick="App.actions.deleteGroup('${g.id}')" class="text-xs text-red-500 hover:underline">グループ削除</button>
                                    </div>
                                </div>
                                <div class="space-y-3 questions-container">
                                    ${g.questions.map((q, qIdx) => {
                                        const qNum = survey.numbering_mode === 'group' ? `Q${gIdx+1}-${qIdx+1}` : `Q${qIdx+1}`;
                                        return `
                                        <div class="p-4 bg-gray-50 rounded-xl border border-gray-200 space-y-3 question-card" data-question-id="${q.id}">
                                            <div class="flex items-center justify-between">
                                                <span class="font-bold text-xs bg-indigo-600 text-white px-2 py-0.5 rounded">${qNum}</span>
                                                <div class="space-x-2 flex items-center text-xs">
                                                    <label class="flex items-center gap-1"><input type="checkbox" ${q.required?'checked':''} onchange="App.actions.toggleRequired('${g.id}', '${q.id}', this.checked)"> 必須回答</label>
                                                    <button onclick="App.actions.deleteQuestion('${g.id}', '${q.id}')" class="text-red-500 hover:underline">削除</button>
                                                </div>
                                            </div>
                                            <input type="text" value="${escapeHtml(q.text)}" onchange="App.actions.updateQuestionText('${g.id}', '${q.id}', this.value)" placeholder="質問文を入力..." class="w-full border rounded-lg px-3 py-2 text-sm bg-white focus:outline-indigo-500">
                                            <div class="flex gap-4 items-center">
                                                <select onchange="App.actions.updateQuestionType('${g.id}', '${q.id}', this.value)" class="border rounded-lg px-3 py-1.5 text-xs bg-white">
                                                    <option value="single" ${q.type==='single'?'selected':''}>単一選択 (ラジオボタン)</option>
                                                    <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択 (チェックボックス)</option>
                                                    <option value="text" ${q.type==='text'?'selected':''}>自由記述 (テキスト)</option>
                                                </select>
                                            </div>
                                            ${q.type !== 'text' ? `
                                            <div class="space-y-2 pl-2 border-l-2 border-indigo-200">
                                                <div class="text-xs font-medium text-gray-500">選択肢</div>
                                                <div class="space-y-1.5 options-list">
                                                    ${(q.options || []).map((opt, oIdx) => `
                                                    <div class="flex items-center gap-2">
                                                        <input type="text" value="${escapeHtml(opt)}" onchange="App.actions.updateOption('${g.id}', '${q.id}', ${oIdx}, this.value)" class="border rounded px-2 py-1 text-xs w-full bg-white">
                                                        <button onclick="App.actions.deleteOption('${g.id}', '${q.id}', ${oIdx})" class="text-gray-400 hover:text-red-500 text-xs">✕</button>
                                                    </div>`).join('')}
                                                </div>
                                                <button onclick="App.actions.addOption('${g.id}', '${q.id}')" class="text-xs text-indigo-600 hover:underline">+ 選択肢追加</button>
                                            </div>` : ''}
                                        </div>`;
                                    }).join('')}
                                </div>
                            </div>`).join('')}
                        </div>
                    </div>
                </div>`;
            },
            renderMail() {
                const id = App.state.activeSurveyId;
                const survey = App.state.data.surveys.find(s => s.id === id) || { title: 'アンケート' };
                return `
                <div class="space-y-6">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 space-y-4">
                        <div class="flex justify-between items-center border-b pb-4">
                            <h2 class="font-bold text-lg text-gray-800">メール送信・回答フォロー: ${escapeHtml(survey.title)}</h2>
                            <button onclick="App.router.navigate('list')" class="text-sm text-indigo-600 hover:underline">一覧に戻る</button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">メール件名</label>
                                <input type="text" id="mail_subject" value="【アンケートご協力のお願い】${escapeHtml(survey.title)}" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">テンプレート種別</label>
                                <select id="template_type" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">
                                    <option value="initial">初回配信</option>
                                    <option value="reminder">リマインド配信</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">本文 ({顧客名}, {アンケートURL} が置換されます)</label>
                            <textarea id="mail_body" rows="4" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">{顧客名} 様\n\nお世話になっております。以下のアンケートにご協力をお願いいたします。\n\n{アンケートURL}</textarea>
                        </div>
                        <button onclick="alert('メール送信機能が実行されました（デモ）')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg text-sm font-medium transition shadow-sm">一括送信実行</button>
                    </div>
                </div>`;
            },
            renderReport() {
                const id = App.state.activeSurveyId;
                const survey = App.state.data.surveys.find(s => s.id === id) || { title: 'アンケート', groups: [] };
                const responses = App.state.data.responses.filter(r => r.survey_id === id);
                return `
                <div class="space-y-6">
                    <div class="flex justify-between items-center bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div>
                            <h2 class="text-lg font-bold text-gray-800">回答集計・分析: ${escapeHtml(survey.title)}</h2>
                            <p class="text-xs text-gray-400 mt-1">総回答数: ${responses.length} 件</p>
                        </div>
                        <div class="space-x-3">
                            <a href="?export_csv=${id}" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm inline-block">CSV出力</a>
                            <button onclick="App.router.navigate('list')" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">一覧に戻る</button>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 space-y-6">
                        <h3 class="font-bold text-gray-700 border-b pb-2">個別回答一覧</h3>
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-100 text-xs text-gray-500 uppercase">
                                    <th class="p-3">回答日時</th>
                                    <th class="p-3">会社名 / 氏名</th>
                                    <th class="p-3 text-right">アクション</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                ${responses.length === 0 ? `<tr><td colspan="3" class="p-6 text-center text-gray-400">現在、回答データはありません。</td></tr>` :
                                  responses.map(r => `
                                  <tr class="hover:bg-gray-50/50">
                                      <td class="p-3 text-xs text-gray-500">${r.answered_at}</td>
                                      <td class="p-3 font-medium">${escapeHtml(r.company || '-')} / ${escapeHtml(r.name || '匿名')}</td>
                                      <td class="p-3 text-right"><button onclick="App.actions.showResponseDetail('${r.id}')" class="text-indigo-600 hover:underline font-medium">詳細表示</button></td>
                                  </tr>`).join('')
                                }
                            </tbody>
                        </table>
                    </div>
                </div>`;
            },
            renderSettings() {
                const set = App.state.data.settings || {};
                return `
                <div class="space-y-6 max-w-2xl mx-auto">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 space-y-6">
                        <h2 class="text-lg font-bold text-gray-800 border-b pb-3">キントーン連携設定</h2>
                        <div id="field_message"></div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">サブドメイン</label>
                                <input type="text" id="setting_subdomain" value="${escapeHtml(set.subdomain || '')}" placeholder="xxxx.cybozu.com" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">アプリID</label>
                                <input type="text" id="setting_app_id" value="${escapeHtml(set.app_id || '')}" placeholder="123" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">ログイン名</label>
                                    <input type="text" id="setting_login_name" value="${escapeHtml(set.login_name || '')}" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">パスワード</label>
                                    <input type="password" id="setting_password" value="${escapeHtml(set.password || '')}" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">プロキシ (任意: host:port)</label>
                                <input type="text" id="setting_proxy" value="${escapeHtml(set.proxy || '')}" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-indigo-500">
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" id="setting_ssl_verify" ${set.ssl_verify ? 'checked' : ''}>
                                <label for="setting_ssl_verify" class="text-xs text-gray-600">SSL証明書を検証する</label>
                            </div>
                        </div>
                        <div class="flex gap-3 pt-4 border-t">
                            <button onclick="App.actions.testKintoneConnection()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">接続確認</button>
                            <button onclick="App.actions.saveSettings()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg text-sm font-medium transition shadow-sm">設定を保存</button>
                        </div>
                    </div>
                </div>`;
            }
        },
        initListEvents() {
            const kw = document.getElementById('search_keyword');
            const sf = document.getElementById('status_filter');
            if (kw) kw.oninput = (e) => { this.state.filter.keyword = e.target.value; };
            if (sf) sf.onchange = (e) => { this.state.filter.status = e.target.value; };
        },
        initEditorEvents() {
            // SortableJS integration for groups and questions
            const el = document.getElementById('question_editor');
            if (el && window.Sortable) {
                new Sortable(el, {
                    animation: 150,
                    handle: '.cursor-move',
                    onEnd: () => {
                        // Reorder groups array based on DOM
                        const groupCards = el.querySelectorAll('.group-card');
                        const newGroups = [];
                        groupCards.forEach(card => {
                            const gId = card.getAttribute('data-group-id');
                            const found = this.state.activeSurveyObj.groups.find(g => g.id === gId);
                            if (found) newGroups.push(found);
                        });
                        this.state.activeSurveyObj.groups = newGroups;
                    }
                });
            }
        },
        initSettingsEvents() {},
        actions: {
            createNewSurvey() {
                const newSurvey = {
                    id: 'sur_' + Date.now(),
                    title: '新しいアンケート',
                    start_at: '',
                    end_at: '',
                    status: 'draft',
                    numbering_mode: 'global',
                    groups: [{ id: 'grp_' + Date.now(), name: '基本グループ', questions: [{ id: 'q_' + Date.now(), text: '', type: 'single', required: false, options: ['選択肢1'] }] }]
                };
                App.state.data.surveys.push(newSurvey);
                App.router.navigate('editor', newSurvey.id);
            },
            saveSurvey() {
                const survey = App.state.activeSurveyObj;
                survey.title = document.getElementById('survey_title').value;
                survey.start_at = document.getElementById('survey_start_at').value;
                survey.end_at = document.getElementById('survey_end_at').value;
                survey.numbering_mode = document.getElementById('survey_numbering_mode').value;
                survey.status = survey.status || 'draft';

                fetch('?api=save_survey', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ survey, csrf_token: document.getElementById('csrf_token').value })
                }).then(res => res.json()).then(json => {
                    if (json.ok) {
                        App.init();
                    } else {
                        alert('保存に失敗しました: ' + (json.message || ''));
                    }
                });
            },
            deleteSurvey(id) {
                if (!confirm('本当に削除しますか？')) return;
                fetch('?api=delete_survey', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id })
                }).then(res => res.json()).then(() => App.init());
            },
            updateStatus(id, status) {
                fetch('?api=update_status', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id, status })
                }).then(res => res.json()).then(() => App.init());
            },
            duplicateSurvey(id) {
                const target = App.state.data.surveys.find(s => s.id === id);
                if (!target) return;
                const copy = JSON.parse(JSON.stringify(target));
                copy.id = 'sur_' + Date.now();
                copy.title = target.title + ' (コピー)';
                copy.status = 'draft';
                App.state.data.surveys.push(copy);
                fetch('?api=save_survey', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ survey: copy, csrf_token: document.getElementById('csrf_token').value })
                }).then(() => App.init());
            },
            addGroup() {
                App.state.activeSurveyObj.groups.push({
                    id: 'grp_' + Date.now(),
                    name: '新しいグループ',
                    questions: []
                });
                App.router.navigate('editor', App.state.activeSurveyObj.id);
            },
            deleteGroup(groupId) {
                App.state.activeSurveyObj.groups = App.state.activeSurveyObj.groups.filter(g => g.id !== groupId);
                App.router.navigate('editor', App.state.activeSurveyObj.id);
            },
            updateGroupName(groupId, name) {
                const g = App.state.activeSurveyObj.groups.find(x => x.id === groupId);
                if (g) g.name = name;
            },
            addQuestion(groupId) {
                const g = App.state.activeSurveyObj.groups.find(x => x.id === groupId);
                if (g) {
                    g.questions.push({
                        id: 'q_' + Date.now(),
                        text: '',
                        type: 'single',
                        required: false,
                        options: ['選択肢1']
                    });
                    App.router.navigate('editor', App.state.activeSurveyObj.id);
                }
            },
            deleteQuestion(groupId, qId) {
                const g = App.state.activeSurveyObj.groups.find(x => x.id === groupId);
                if (g) {
                    g.questions = g.questions.filter(q => q.id !== qId);
                    App.router.navigate('editor', App.state.activeSurveyObj.id);
                }
            },
            updateQuestionText(groupId, qId, text) {
                const g = App.state.activeSurveyObj.groups.find(x => x.id === groupId);
                const q = g?.questions.find(x => x.id === qId);
                if (q) q.text = text;
            },
            updateQuestionType(groupId, qId, type) {
                const g = App.state.activeSurveyObj.groups.find(x => x.id === groupId);
                const q = g?.questions.find(x => x.id === qId);
                if (q) {
                    q.type = type;
                    if (type !== 'text' && (!q.options || q.options.length === 0)) q.options = ['選択肢1'];
                    App.router.navigate('editor', App.state.activeSurveyObj.id);
                }
            },
            toggleRequired(groupId, qId, required) {
                const g = App.state.activeSurveyObj.groups.find(x => x.id === groupId);
                const q = g?.questions.find(x => x.id === qId);
                if (q) q.required = required;
            },
            addOption(groupId, qId) {
                const g = App.state.activeSurveyObj.groups.find(x => x.id === groupId);
                const q = g?.questions.find(x => x.id === qId);
                if (q) {
                    q.options.push('選択肢' + (q.options.length + 1));
                    App.router.navigate('editor', App.state.activeSurveyObj.id);
                }
            },
            deleteOption(groupId, qId, idx) {
                const g = App.state.activeSurveyObj.groups.find(x => x.id === groupId);
                const q = g?.questions.find(x => x.id === qId);
                if (q && q.options.length > 1) {
                    q.options.splice(idx, 1);
                    App.router.navigate('editor', App.state.activeSurveyObj.id);
                }
            },
            updateOption(groupId, qId, idx, val) {
                const g = App.state.activeSurveyObj.groups.find(x => x.id === groupId);
                const q = g?.questions.find(x => x.id === qId);
                if (q) q.options[idx] = val;
            },
            previewSurvey() {
                alert('プレビュー機能（回答者視点モック）');
            },
            showResponseDetail(resId) {
                const r = App.state.data.responses.find(x => x.id === resId);
                if (r) alert(JSON.stringify(r.answers, null, 2));
            },
            testKintoneConnection() {
                const app_id = document.getElementById('setting_app_id').value;
                const subdomain = document.getElementById('setting_subdomain').value;
                const login_name = document.getElementById('setting_login_name').value;
                const password = document.getElementById('setting_password').value;
                const proxy = document.getElementById('setting_proxy').value;
                const ssl_verify = document.getElementById('setting_ssl_verify').checked;

                // 一時的に設定を保存してからテスト
                const settings = { subdomain, app_id, login_name, password, proxy, ssl_verify };
                
                fetch('?api=save_settings', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ settings, csrf_token: document.getElementById('csrf_token').value })
                }).then(() => {
                    return fetch('?api=test_kintone', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ app_id })
                    });
                }).then(res => res.json()).then(data => {
                    const msgEl = document.getElementById('field_message');
                    if (data.status === 200) {
                        msgEl.innerHTML = `<div class="p-3 bg-emerald-50 text-emerald-700 rounded-lg text-xs border border-emerald-200">kintone接続成功！フィールド一覧の取得に成功しました。</div>`;
                    } else {
                        msgEl.innerHTML = `<div class="p-3 bg-red-50 text-red-700 rounded-lg text-xs border border-red-200">
                            <strong>kintone通信エラー</strong><br>
                            HTTPステータス: ${data.status}<br>
                            接続先: ${data.url}<br>
                            エラー内容: ${data.error || (data.json && data.json.message) || '不明'}
                        </div>`;
                    }
                });
            },
            saveSettings() {
                const settings = {
                    subdomain: document.getElementById('setting_subdomain').value,
                    app_id: document.getElementById('setting_app_id').value,
                    login_name: document.getElementById('setting_login_name').value,
                    password: document.getElementById('setting_password').value,
                    proxy: document.getElementById('setting_proxy').value,
                    ssl_verify: document.getElementById('setting_ssl_verify').checked
                };
                fetch('?api=save_settings', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ settings, csrf_token: document.getElementById('csrf_token').value })
                }).then(res => res.json()).then(json => {
                    if (json.ok) alert('設定を保存しました');
                });
            }
        }
    };

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => App.init());
    } else {
        App.init();
    }
    </script>
</body>
</html>

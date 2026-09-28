<?php
declare(strict_types=1);

// ===============================================
// アンケート管理システム index.php（動作版・最小構成）
// ===============================================

mb_internal_encoding('UTF-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

$BASE_DIR = __DIR__;
$FILES = [
    'surveys'        => $BASE_DIR . '/surveys.json',
    'responses'      => $BASE_DIR . '/responses.json',
    'answer_tokens'  => $BASE_DIR . '/answer_tokens.json',
    'settings'       => $BASE_DIR . '/settings.json',
];

// -------------------------
// セッション・CSRF
// -------------------------
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (!isset($_SESSION['app'])) {
    $_SESSION['app'] = [
        'csrf' => bin2hex(random_bytes(16)),
    ];
}
$CSRF_TOKEN = $_SESSION['app']['csrf'];

// -------------------------
// JSONユーティリティ
// -------------------------
function read_json_file(string $path, array $emptyStructure): array {
    if (!file_exists($path)) {
        file_put_contents($path, json_encode($emptyStructure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $emptyStructure;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $emptyStructure;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $emptyStructure;
    }
    return $data;
}

function write_json_file(string $path, array $data): bool {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

function json_ok($data): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $code, string $message, array $fields = []): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
            'fields' => $fields,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function require_csrf(): void {
    global $CSRF_TOKEN;
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = [];
    $token = $body['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals($CSRF_TOKEN, $token)) {
        json_error('CSRF_ERROR', 'CSRFトークンが不正です。');
    }
    $_POST['_json'] = $body;
}

function generate_id(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(8));
}
function now_iso(): string {
    return date('Y-m-d H:i:s');
}

// -------------------------
// データ読み込み
// -------------------------
$surveys   = read_json_file($FILES['surveys'],   ['items' => []]);
$responses = read_json_file($FILES['responses'], ['items' => []]);
$tokens    = read_json_file($FILES['answer_tokens'], ['items' => []]);
$settings  = read_json_file($FILES['settings'], [
    'smtp' => [
        'host' => '',
        'port' => 25,
        'encryption' => 'none',
        'username' => '',
        'passwordConfigured' => false,
        'fromEmail' => '',
        'fromName' => '',
    ],
]);

// -------------------------
// 回答URL生成
// -------------------------
function build_answer_url(string $tokenId): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?');
    return $scheme . '://' . $host . $path . '?answer=' . urlencode($tokenId);
}

// -------------------------
// APIルーティング
// -------------------------
$api = $_GET['api'] ?? null;
if ($api !== null) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch ($api) {
            case 'survey_list':
                json_ok(['surveys' => $surveys['items']]);
            case 'survey_get':
                $id = $_GET['id'] ?? '';
                foreach ($surveys['items'] as $s) {
                    if ($s['id'] === $id) {
                        json_ok(['survey' => $s]);
                    }
                }
                json_error('NOT_FOUND', 'アンケートが見つかりません。');
            case 'settings_get':
                json_ok(['settings' => $settings]);
            case 'response_status':
                $surveyId = $_GET['surveyId'] ?? '';
                $list = [];
                foreach ($tokens['items'] as $t) {
                    if ($t['surveyId'] === $surveyId) {
                        $list[] = $t;
                    }
                }
                json_ok(['tokens' => $list]);
            case 'answer_token_get':
                $tokenId = $_GET['tokenId'] ?? '';
                $token = null;
                foreach ($tokens['items'] as $t) {
                    if ($t['tokenId'] === $tokenId) {
                        $token = $t;
                        break;
                    }
                }
                if ($token === null) {
                    json_error('TOKEN_ERROR', '回答URLが不正です。');
                }
                $survey = null;
                foreach ($surveys['items'] as $s) {
                    if ($s['id'] === $token['surveyId']) {
                        $survey = $s;
                        break;
                    }
                }
                if ($survey === null) {
                    json_error('SURVEY_ERROR', 'アンケートが存在しません。');
                }
                json_ok(['token' => $token, 'survey' => $survey]);
            default:
                json_error('NOT_FOUND', '未知のAPIです。');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $body = $_POST['_json'];

        switch ($api) {
            case 'survey_save':
                $survey = $body['survey'] ?? null;
                if (!is_array($survey)) {
                    json_error('VALIDATION_ERROR', 'アンケートデータが不正です。');
                }
                $id = $survey['id'] ?? '';
                if ($id === '') {
                    $id = generate_id('sv');
                    $survey['id'] = $id;
                    $survey['createdAt'] = now_iso();
                }
                $survey['updatedAt'] = now_iso();
                if (!isset($survey['status'])) $survey['status'] = 'draft';
                if (!isset($survey['groups']) || !is_array($survey['groups'])) {
                    $survey['groups'] = [];
                }
                $found = false;
                foreach ($surveys['items'] as &$s) {
                    if ($s['id'] === $id) {
                        $s = $survey;
                        $found = true;
                        break;
                    }
                }
                unset($s);
                if (!$found) {
                    $surveys['items'][] = $survey;
                }
                if (!write_json_file($FILES['surveys'], $surveys)) {
                    json_error('FILE_WRITE_ERROR', 'アンケート保存に失敗しました。');
                }
                json_ok(['surveyId' => $id]);

            case 'survey_publish':
                $surveyId = (string)($body['surveyId'] ?? '');
                $found = false;
                foreach ($surveys['items'] as &$s) {
                    if ($s['id'] === $surveyId) {
                        $s['status'] = 'published';
                        $s['updatedAt'] = now_iso();
                        $found = true;
                        break;
                    }
                }
                unset($s);
                if (!$found) {
                    json_error('SURVEY_ERROR', 'アンケートが見つかりません。');
                }
                write_json_file($FILES['surveys'], $surveys);
                json_ok(['surveyId' => $surveyId]);

            case 'survey_close':
                $surveyId = (string)($body['surveyId'] ?? '');
                $found = false;
                foreach ($surveys['items'] as &$s) {
                    if ($s['id'] === $surveyId) {
                        $s['status'] = 'closed';
                        $s['updatedAt'] = now_iso();
                        $found = true;
                        break;
                    }
                }
                unset($s);
                if (!$found) {
                    json_error('SURVEY_ERROR', 'アンケートが見つかりません。');
                }
                write_json_file($FILES['surveys'], $surveys);
                json_ok(['surveyId' => $surveyId]);

            case 'individual_token_issue':
                $surveyId = (string)($body['surveyId'] ?? '');
                $survey = null;
                foreach ($surveys['items'] as $s) {
                    if ($s['id'] === $surveyId) {
                        $survey = $s;
                        break;
                    }
                }
                if ($survey === null) {
                    json_error('SURVEY_ERROR', 'アンケートが存在しません。');
                }
                if ($survey['status'] !== 'published') {
                    json_error('SURVEY_PUBLISH_ERROR', '公開済みアンケートのみ個別回答URLを発行できます。');
                }
                $tokenId = generate_id('tok');
                $tokens['items'][] = [
                    'tokenId'       => $tokenId,
                    'surveyId'      => $surveyId,
                    'respondentType'=> 'individual',
                    'customerId'    => null,
                    'organization'  => null,
                    'department'    => null,
                    'email'         => null,
                    'status'        => 'unused',
                    'issuedAt'      => now_iso(),
                    'infoEnteredAt' => null,
                    'answeredAt'    => null,
                    'expiresAt'     => null,
                ];
                write_json_file($FILES['answer_tokens'], $tokens);
                json_ok(['tokenId' => $tokenId, 'url' => build_answer_url($tokenId)]);

            case 'respondent_info_save':
                $tokenId = (string)($body['tokenId'] ?? '');
                $org     = trim((string)($body['organization'] ?? ''));
                $dept    = trim((string)($body['department'] ?? ''));
                $email   = trim((string)($body['email'] ?? ''));
                if ($org === '' || $dept === '' || $email === '') {
                    json_error('VALIDATION_ERROR', '組織名・部署名・メールアドレスは必須です。');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    json_error('VALIDATION_ERROR', 'メールアドレス形式が不正です。');
                }
                $found = false;
                foreach ($tokens['items'] as &$t) {
                    if ($t['tokenId'] === $tokenId) {
                        if ($t['status'] === 'answered') {
                            json_error('TOKEN_ALREADY_USED', 'この回答URLはすでに回答済みです。');
                        }
                        $t['organization']  = $org;
                        $t['department']    = $dept;
                        $t['email']         = $email;
                        $t['status']        = 'info_entered';
                        $t['infoEnteredAt'] = now_iso();
                        $found = true;
                        break;
                    }
                }
                unset($t);
                if (!$found) {
                    json_error('TOKEN_ERROR', '回答URLが不正です。');
                }
                write_json_file($FILES['answer_tokens'], $tokens);
                json_ok(['tokenId' => $tokenId]);

            case 'response_save':
                $tokenId = (string)($body['tokenId'] ?? '');
                $answers = $body['answers'] ?? [];
                if (!is_array($answers)) {
                    json_error('VALIDATION_ERROR', '回答データが不正です。');
                }
                $token = null;
                foreach ($tokens['items'] as $t) {
                    if ($t['tokenId'] === $tokenId) {
                        $token = $t;
                        break;
                    }
                }
                if ($token === null) {
                    json_error('TOKEN_ERROR', '回答URLが不正です。');
                }
                if ($token['status'] === 'answered') {
                    json_error('TOKEN_ALREADY_USED', 'この回答URLはすでに回答済みです。');
                }
                $survey = null;
                foreach ($surveys['items'] as $s) {
                    if ($s['id'] === $token['surveyId']) {
                        $survey = $s;
                        break;
                    }
                }
                if ($survey === null) {
                    json_error('SURVEY_ERROR', 'アンケートが存在しません。');
                }
                if ($survey['status'] !== 'published') {
                    json_error('SURVEY_ERROR', '公開中のアンケートではありません。');
                }
                // 必須・分岐などの詳細検証はここで行うべきだが、最小構成では省略
                $responses['items'][] = [
                    'responseId'    => generate_id('resp'),
                    'surveyId'      => $survey['id'],
                    'tokenId'       => $tokenId,
                    'respondentType'=> $token['respondentType'],
                    'customerId'    => $token['customerId'],
                    'organization'  => $token['organization'],
                    'department'    => $token['department'],
                    'email'         => $token['email'],
                    'startedAt'     => $token['infoEnteredAt'] ?? now_iso(),
                    'answeredAt'    => now_iso(),
                    'answers'       => $answers,
                ];
                foreach ($tokens['items'] as &$t2) {
                    if ($t2['tokenId'] === $tokenId) {
                        $t2['status']     = 'answered';
                        $t2['answeredAt'] = now_iso();
                        break;
                    }
                }
                unset($t2);
                write_json_file($FILES['responses'], $responses);
                write_json_file($FILES['answer_tokens'], $tokens);
                json_ok(['tokenId' => $tokenId]);

            case 'settings_save':
                $smtp = $body['smtp'] ?? [];
                if (!is_array($smtp)) {
                    json_error('VALIDATION_ERROR', 'SMTP設定が不正です。');
                }
                $settings['smtp']['host'] = (string)($smtp['host'] ?? '');
                $settings['smtp']['port'] = (int)($smtp['port'] ?? 25);
                $enc = (string)($smtp['encryption'] ?? 'none');
                $settings['smtp']['encryption'] = in_array($enc, ['none','ssl','tls'], true) ? $enc : 'none';
                $settings['smtp']['username'] = (string)($smtp['username'] ?? '');
                if (isset($smtp['password']) && $smtp['password'] !== '') {
                    $settings['smtp']['passwordConfigured'] = true;
                }
                $settings['smtp']['fromEmail'] = (string)($smtp['fromEmail'] ?? '');
                $settings['smtp']['fromName']  = (string)($smtp['fromName'] ?? '');
                if (!write_json_file($FILES['settings'], $settings)) {
                    json_error('SETTINGS_WRITE_ERROR', '設定保存に失敗しました。');
                }
                json_ok(['settings' => $settings]);

            default:
                json_error('NOT_FOUND', '未知のAPIです。');
        }
    }
    exit;
}

// -------------------------
// 回答者画面 or 管理画面
// -------------------------
$answerToken = $_GET['answer'] ?? null;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>アンケート管理システム</title>
<style>
body {
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background: #f5f5f5;
    color: #333;
    margin: 0;
}
header {
    background: #4a6fb3;
    color: #fff;
    padding: 10px 16px;
}
main {
    padding: 16px;
}
.section {
    background: #fff;
    border-radius: 4px;
    padding: 12px 16px;
    margin-bottom: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
h1,h2,h3 { margin: 0 0 8px; }
button {
    padding: 6px 12px;
    border-radius: 4px;
    border: 1px solid #4a6fb3;
    background: #4a6fb3;
    color: #fff;
    cursor: pointer;
}
button:disabled { opacity: 0.6; cursor: default; }
input[type="text"], input[type="email"], textarea {
    width: 100%;
    padding: 6px;
    border-radius: 4px;
    border: 1px solid #ccc;
    box-sizing: border-box;
}
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
}
th, td {
    border: 1px solid #ddd;
    padding: 4px 6px;
    font-size: 13px;
}
th { background: #eee; }
.notice {
    padding: 6px 10px;
    background: #e0f0ff;
    border-radius: 4px;
    margin-bottom: 8px;
    font-size: 13px;
}
.error {
    padding: 6px 10px;
    background: #ffe0e0;
    border-radius: 4px;
    margin-bottom: 8px;
    font-size: 13px;
}
</style>
</head>
<body>
<header>
    <h1>アンケート管理システム</h1>
</header>
<main>
<?php if ($answerToken !== null): ?>
    <div class="section" id="answer-section">
        <h2>アンケート回答</h2>
        <div id="answer-message" class="notice">個別回答URLからの回答フローです。</div>
        <div id="answer-content"></div>
    </div>
<?php else: ?>
    <div class="section">
        <h2>アンケート一覧</h2>
        <div id="survey-list"></div>
        <button id="btn-new-survey">新規アンケート作成</button>
    </div>
    <div class="section">
        <h2>アンケート編集・個別回答URL発行</h2>
        <div id="survey-detail"></div>
    </div>
    <div class="section">
        <h2>回答状況</h2>
        <div id="response-status"></div>
    </div>
    <div class="section">
        <h2>SMTP設定</h2>
        <div id="settings-section"></div>
    </div>
<?php endif; ?>
</main>
<script>
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
function escapeAttr(str) {
    return escapeHtml(str).replace(/`/g, '&#96;');
}
const CSRF = <?php echo json_encode($CSRF_TOKEN, JSON_UNESCAPED_UNICODE); ?>;

async function apiGet(params) {
    const url = new URL(location.href);
    url.search = '';
    url.searchParams.set('api', params.api);
    if (params.query) {
        for (const [k,v] of Object.entries(params.query)) {
            url.searchParams.set(k, v);
        }
    }
    const res = await fetch(url.toString(), {method: 'GET'});
    return res.json();
}
async function apiPost(params) {
    const url = new URL(location.href);
    url.search = '';
    url.searchParams.set('api', params.api);
    const body = Object.assign({_csrf: CSRF}, params.body || {});
    const res = await fetch(url.toString(), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body),
    });
    return res.json();
}

<?php if ($answerToken === null): ?>
// 管理画面
(async function initAdmin() {
    await renderSurveyList();
    await renderSettings();
})();

async function renderSurveyList() {
    const el = document.getElementById('survey-list');
    const res = await apiGet({api: 'survey_list'});
    if (!res.ok) {
        el.innerHTML = '<div class="error">' + escapeHtml(res.error.message) + '</div>';
        return;
    }
    const surveys = res.data.surveys || [];
    let html = '<table><thead><tr><th>ID</th><th>アンケート名</th><th>状態</th><th>開始</th><th>終了</th><th>操作</th></tr></thead><tbody>';
    for (const s of surveys) {
        html += '<tr>' +
            '<td>' + escapeHtml(s.id) + '</td>' +
            '<td>' + escapeHtml(s.name || '') + '</td>' +
            '<td>' + escapeHtml(s.status || '') + '</td>' +
            '<td>' + escapeHtml(s.startAt || '') + '</td>' +
            '<td>' + escapeHtml(s.endAt || '') + '</td>' +
            '<td>' +
            '<button data-id="' + escapeAttr(s.id) + '" class="btn-edit">内容</button> ' +
            '<button data-id="' + escapeAttr(s.id) + '" class="btn-publish">公開</button> ' +
            '<button data-id="' + escapeAttr(s.id) + '" class="btn-close">終了</button> ' +
            '<button data-id="' + escapeAttr(s.id) + '" class="btn-token">個別回答URL発行</button>' +
            '</td>' +
            '</tr>';
    }
    html += '</tbody></table>';
    el.innerHTML = html;
    el.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', () => loadSurveyDetail(btn.dataset.id));
    });
    el.querySelectorAll('.btn-publish').forEach(btn => {
        btn.addEventListener('click', () => publishSurvey(btn.dataset.id));
    });
    el.querySelectorAll('.btn-close').forEach(btn => {
        btn.addEventListener('click', () => closeSurvey(btn.dataset.id));
    });
    el.querySelectorAll('.btn-token').forEach(btn => {
        btn.addEventListener('click', () => issueIndividualToken(btn.dataset.id));
    });
    document.getElementById('btn-new-survey').onclick = () => createNewSurvey();
}

async function createNewSurvey() {
    const survey = {
        id: '',
        name: '新規アンケート',
        description: '',
        status: 'draft',
        startAt: '',
        endAt: '',
        numberingFormat: 'Q{group}-{index}',
        groups: [],
    };
    const res = await apiPost({api: 'survey_save', body: {survey}});
    if (!res.ok) {
        alert('保存エラー: ' + res.error.message);
        return;
    }
    await renderSurveyList();
    await loadSurveyDetail(res.data.surveyId);
}

async function loadSurveyDetail(id) {
    const el = document.getElementById('survey-detail');
    const res = await apiGet({api: 'survey_get', query: {id}});
    if (!res.ok) {
        el.innerHTML = '<div class="error">' + escapeHtml(res.error.message) + '</div>';
        return;
    }
    const s = res.data.survey;
    let html = '';
    html += '<div class="notice">グループ・質問は最小構成で保存されます。</div>';
    html += '<p><b>ID:</b> ' + escapeHtml(s.id) + '</p>';
    html += '<p><label>アンケート名: <input type="text" id="sv-name" value="' + escapeAttr(s.name || '') + '"></label></p>';
    html += '<p><label>説明: <textarea id="sv-desc">' + escapeHtml(s.description || '') + '</textarea></label></p>';
    html += '<p>状態: ' + escapeHtml(s.status || '') + '</p>';
    html += '<button id="sv-save">下書き保存</button> ';
    html += '<button id="sv-token">個別回答URL発行</button>';
    html += '<div id="sv-token-result" class="notice"></div>';
    el.innerHTML = html;
    document.getElementById('sv-save').onclick = async () => {
        s.name = document.getElementById('sv-name').value;
        s.description = document.getElementById('sv-desc').value;
        const res2 = await apiPost({api: 'survey_save', body: {survey: s}});
        if (!res2.ok) {
            alert('保存エラー: ' + res2.error.message);
            return;
        }
        alert('保存しました。');
        await renderSurveyList();
    };
    document.getElementById('sv-token').onclick = async () => {
        await issueIndividualToken(s.id);
    };
    await renderResponseStatus(s.id);
}

async function publishSurvey(id) {
    if (!confirm('公開しますか？')) return;
    const res = await apiPost({api: 'survey_publish', body: {surveyId: id}});
    if (!res.ok) {
        alert('公開エラー: ' + res.error.message);
        return;
    }
    alert('公開しました。');
    await renderSurveyList();
}

async function closeSurvey(id) {
    if (!confirm('終了しますか？')) return;
    const res = await apiPost({api: 'survey_close', body: {surveyId: id}});
    if (!res.ok) {
        alert('終了エラー: ' + res.error.message);
        return;
    }
    alert('終了しました。');
    await renderSurveyList();
}

async function issueIndividualToken(surveyId) {
    const res = await apiPost({api: 'individual_token_issue', body: {surveyId}});
    if (!res.ok) {
        alert('URL発行エラー: ' + res.error.message);
        return;
    }
    const url = res.data.url;
    const el = document.getElementById('sv-token-result');
    if (el) {
        el.textContent = '個別回答URL: ' + url + '（コピーして対象者へ送付してください）';
    } else {
        alert('個別回答URL: ' + url);
    }
    await renderResponseStatus(surveyId);
}

async function renderResponseStatus(surveyId) {
    const el = document.getElementById('response-status');
    const res = await apiGet({api: 'response_status', query: {surveyId}});
    if (!res.ok) {
        el.innerHTML = '<div class="error">' + escapeHtml(res.error.message) + '</div>';
        return;
    }
    const tokens = res.data.tokens || [];
    let html = '<table><thead><tr><th>tokenId</th><th>種別</th><th>組織名</th><th>部署名</th><th>メール</th><th>状態</th><th>発行</th><th>情報入力</th><th>回答</th></tr></thead><tbody>';
    for (const t of tokens) {
        html += '<tr>' +
            '<td>' + escapeHtml(t.tokenId) + '</td>' +
            '<td>' + escapeHtml(t.respondentType) + '</td>' +
            '<td>' + escapeHtml(t.organization || '') + '</td>' +
            '<td>' + escapeHtml(t.department || '') + '</td>' +
            '<td>' + escapeHtml(t.email || '') + '</td>' +
            '<td>' + escapeHtml(t.status || '') + '</td>' +
            '<td>' + escapeHtml(t.issuedAt || '') + '</td>' +
            '<td>' + escapeHtml(t.infoEnteredAt || '') + '</td>' +
            '<td>' + escapeHtml(t.answeredAt || '') + '</td>' +
            '</tr>';
    }
    html += '</tbody></table>';
    el.innerHTML = html;
}

async function renderSettings() {
    const el = document.getElementById('settings-section');
    const res = await apiGet({api: 'settings_get'});
    if (!res.ok) {
        el.innerHTML = '<div class="error">' + escapeHtml(res.error.message) + '</div>';
        return;
    }
    const smtp = res.data.settings.smtp;
    let html = '';
    html += '<div class="notice">settings.json に保存されるSMTP設定です。</div>';
    html += '<p><label>ホスト: <input type="text" id="smtp-host" value="' + escapeAttr(smtp.host || '') + '"></label></p>';
    html += '<p><label>ポート: <input type="text" id="smtp-port" value="' + escapeAttr(String(smtp.port || 25)) + '"></label></p>';
    html += '<p><label>暗号化: <select id="smtp-enc">' +
        '<option value="none"' + (smtp.encryption === 'none' ? ' selected' : '') + '>なし</option>' +
        '<option value="ssl"' + (smtp.encryption === 'ssl' ? ' selected' : '') + '>SSL</option>' +
        '<option value="tls"' + (smtp.encryption === 'tls' ? ' selected' : '') + '>TLS</option>' +
        '</select></label></p>';
    html += '<p><label>ユーザー名: <input type="text" id="smtp-user" value="' + escapeAttr(smtp.username || '') + '"></label></p>';
    html += '<p><label>パスワード: <input type="text" id="smtp-pass" value=""></label>（設定済み: ' + (smtp.passwordConfigured ? 'はい' : 'いいえ') + '）</p>';
    html += '<p><label>送信元メール: <input type="text" id="smtp-from" value="' + escapeAttr(smtp.fromEmail || '') + '"></label></p>';
    html += '<p><label>送信元名: <input type="text" id="smtp-from-name" value="' + escapeAttr(smtp.fromName || '') + '"></label></p>';
    html += '<button id="smtp-save">SMTP設定を保存</button>';
    el.innerHTML = html;
    document.getElementById('smtp-save').onclick = async () => {
        const body = {
            smtp: {
                host: document.getElementById('smtp-host').value,
                port: parseInt(document.getElementById('smtp-port').value || '25', 10),
                encryption: document.getElementById('smtp-enc').value,
                username: document.getElementById('smtp-user').value,
                password: document.getElementById('smtp-pass').value,
                fromEmail: document.getElementById('smtp-from').value,
                fromName: document.getElementById('smtp-from-name').value,
            }
        };
        const res2 = await apiPost({api: 'settings_save', body});
        if (!res2.ok) {
            alert('保存エラー: ' + res2.error.message);
            return;
        }
        alert('SMTP設定を保存しました。');
        await renderSettings();
    };
}
<?php else: ?>
// 回答者画面
(async function initAnswer() {
    const tokenId = <?php echo json_encode($answerToken, JSON_UNESCAPED_UNICODE); ?>;
    const el = document.getElementById('answer-content');
    const res = await apiGet({api: 'answer_token_get', query: {tokenId}});
    if (!res.ok) {
        el.innerHTML = '<div class="error">' + escapeHtml(res.error.message) + '</div>';
        return;
    }
    const token  = res.data.token;
    const survey = res.data.survey;
    if (token.status === 'answered') {
        el.innerHTML = '<div class="notice">このアンケートはすでに回答済みです。</div>';
        return;
    }
    let html = '';
    html += '<div class="notice">アンケート名: ' + escapeHtml(survey.name || '') + '</div>';
    html += '<h3>回答者情報入力</h3>';
    html += '<p><label>組織名: <input type="text" id="ans-org" value="' + escapeAttr(token.organization || '') + '"></label></p>';
    html += '<p><label>部署名: <input type="text" id="ans-dept" value="' + escapeAttr(token.department || '') + '"></label></p>';
    html += '<p><label>メールアドレス: <input type="email" id="ans-mail" value="' + escapeAttr(token.email || '') + '"></label></p>';
    html += '<button id="ans-info-save">回答へ進む</button>';
    html += '<div id="ans-info-msg"></div>';
    html += '<hr>';
    html += '<h3>アンケート回答</h3>';
    html += '<p>ここではテキスト質問1件のみの最小構成です。</p>';
    html += '<p><label>Q1: ご意見をお聞かせください<textarea id="ans-q1"></textarea></label></p>';
    html += '<button id="ans-send">回答を送信</button>';
    html += '<div id="ans-send-msg"></div>';
    el.innerHTML = html;

    document.getElementById('ans-info-save').onclick = async () => {
        const body = {
            tokenId,
            organization: document.getElementById('ans-org').value,
            department: document.getElementById('ans-dept').value,
            email: document.getElementById('ans-mail').value,
        };
        const res2 = await apiPost({api: 'respondent_info_save', body});
        const msgEl = document.getElementById('ans-info-msg');
        if (!res2.ok) {
            msgEl.innerHTML = '<div class="error">' + escapeHtml(res2.error.message) + '</div>';
            return;
        }
        msgEl.innerHTML = '<div class="notice">回答者情報を登録しました。</div>';
    };

    document.getElementById('ans-send').onclick = async () => {
        const body = {
            tokenId,
            answers: [
                {questionId: 'q1', type: 'text', value: document.getElementById('ans-q1').value}
            ]
        };
        const res3 = await apiPost({api: 'response_save', body});
        const msgEl = document.getElementById('ans-send-msg');
        if (!res3.ok) {
            msgEl.innerHTML = '<div class="error">' + escapeHtml(res3.error.message) + '</div>';
            return;
        }
        msgEl.innerHTML = '<div class="notice">回答ありがとうございました。このURLは回答済みとなり再利用できません。</div>';
    };
})();
<?php endif; ?>
</script>
</body>
</html>

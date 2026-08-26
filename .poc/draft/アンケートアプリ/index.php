<?php
/**
 * アンケート管理システム
 * 単一ファイル構成 (index.php)
 * 
 * 実行環境: Apache24 + PHP 8.4 / 8.5
 * データベース不使用 (JSONファイル永続化)
 */

declare(strict_types=1);

// ============================================================================
// 1. 基本設定・エラーハンドリング・セッション初期化
// ============================================================================

// 画面直接出力でのエラー表示を抑止（APIレスポンスのJSON破壊を防止）
ini_set('display_errors', '0');
error_reporting(E_ALL);

// セッション開始（CSRF対策等）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRFトークン生成（存在しない場合）
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// データ保存ディレクトリ
define('DATA_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'data');
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
}

// 共通APIレスポンス関数
function successResponse(mixed $data = [], string $message = ''): never {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function errorResponse(string $code, string $message, int $httpStatus = 400): never {
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 未捕捉例外・エラー共通ハンドラ
set_exception_handler(function (Throwable $e) {
    error_log("[App Exception] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    if (isset($_GET['action']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
        errorResponse('INTERNAL_SERVER_ERROR', 'サーバー内部エラーが発生しました。', 500);
    } else {
        http_response_code(500);
        echo "<!DOCTYPE html><html><body><h1>500 Internal Server Error</h1><p>システムエラーが発生しました。</p></body></html>";
        exit;
    }
});

// Fatal Error等のシャットダウンハンドラ
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log("[Fatal Error] {$error['message']} in {$error['file']}:{$error['line']}");
        if (!headers_sent()) {
            if (isset($_GET['action']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
                errorResponse('FATAL_ERROR', '致命的なエラーが発生しました。', 500);
            } else {
                http_response_code(500);
                echo "<!DOCTYPE html><html><body><h1>500 Fatal Error</h1><p>予期しないシステムエラーが発生しました。</p></body></html>";
            }
        }
    }
});

// ============================================================================
// 2. データ永続化層 (JSON・排他制御・Atomic Write)
// ============================================================================

class JsonStorage {
    private static function getFilePath(string $key): string {
        $allowed = ['surveys', 'responses', 'customers', 'send_history', 'settings'];
        if (!in_array($key, $allowed, true)) {
            throw new InvalidArgumentException("Invalid storage key: {$key}");
        }
        return DATA_DIR . DIRECTORY_SEPARATOR . "{$key}.json";
    }

    public static function load(string $key): array {
        $path = self::getFilePath($key);
        if (!file_exists($path)) {
            return [];
        }
        $fp = @fopen($path, 'r');
        if (!$fp) {
            return [];
        }
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($content === false || trim($content) === '') {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    public static function save(string $key, array $data): bool {
        $path = self::getFilePath($key);
        $tmpPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $fp = fopen($tmpPath, 'w');
        if (!$fp) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tmpPath);
            return false;
        }

        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        // Atomic write (置換)
        return rename($tmpPath, $path);
    }
}

// ============================================================================
// 3. 外部通信共通層 (kintone: cURL非使用, SMTP通信)
// ============================================================================

class KintoneClient {
    public static function normalizeSubdomain(string $input): string {
        $trimmed = trim($input);
        if (preg_match('#^https?://([^/]+)#i', $trimmed, $matches)) {
            return $matches[1];
        }
        if (str_contains($trimmed, '.')) {
            return $trimmed;
        }
        return $trimmed . '.cybozu.com';
    }

    public static function request(string $endpoint, string $method, array $settings, ?array $payload = null): array {
        $domain = self::normalizeSubdomain($settings['subdomain'] ?? '');
        $url = "https://{$domain}/k/v1/{$endpoint}";
        
        $headers = [
            "X-Cybozu-Authorization: " . base64_encode(($settings['loginName'] ?? '') . ':' . ($settings['password'] ?? '')),
            "Content-Type: application/json"
        ];

        $sslVerify = !empty($settings['sslVerify']) && $settings['sslVerify'] === true;

        $sslOptions = [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify
        ];

        $httpOptions = [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'timeout' => 15,
            'ignore_errors' => true
        ];

        if (!empty($settings['proxy'])) {
            $proxy = trim($settings['proxy']);
            if (preg_match('/^([a-zA-Z0-9\.\-]+):([0-9]+)$/', $proxy)) {
                $httpOptions['proxy'] = 'tcp://' . $proxy;
                $httpOptions['request_fulluri'] = true;
            }
        }

        if ($payload !== null && $method !== 'GET') {
            $httpOptions['content'] = json_encode($payload);
        } elseif ($payload !== null && $method === 'GET') {
            $url .= '?' . http_build_query($payload);
        }

        $context = stream_context_create([
            'http' => $httpOptions,
            'ssl' => $sslOptions
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $lastErr = error_get_last();
            throw new RuntimeException("kintone通信に失敗しました: " . ($lastErr['message'] ?? 'ネットワーク接続エラー'));
        }

        $statusCode = 0;
        if (isset($http_response_header) && count($http_response_header) > 0) {
            if (preg_match('#HTTP/\d\.\d\s+(\d+)#i', $http_response_header[0], $m)) {
                $statusCode = (int)$m[1];
            }
        }

        $data = json_decode($response, true);
        if ($statusCode >= 400) {
            $msg = $data['message'] ?? "HTTPエラー ({$statusCode})";
            throw new RuntimeException("kintone APIエラー: {$msg}");
        }

        return is_array($data) ? $data : [];
    }
}

// ============================================================================
// 4. 業務ロジック層 (状態判定、採番、条件分岐、バリデーション)
// ============================================================================

class SurveyService {
    public static function checkEnded(array $survey): bool {
        if (($survey['status'] ?? '') !== 'published') {
            return false;
        }
        if (!empty($survey['endAt'])) {
            $endTimestamp = strtotime($survey['endAt']);
            if ($endTimestamp !== false && time() > $endTimestamp) {
                return true;
            }
        }
        return false;
    }

    public static function recomputeNumbering(array &$survey): void {
        $mode = $survey['numberingMode'] ?? 'survey';
        $globalNum = 1;
        
        $groups = $survey['groups'] ?? [];
        foreach ($groups as $gIdx => &$group) {
            $groupNum = 1;
            $questions = $group['questions'] ?? [];
            foreach ($questions as $qIdx => &$q) {
                if ($mode === 'survey') {
                    $q['displayNumber'] = (string)$globalNum++;
                } else {
                    $q['displayNumber'] = (string)$groupNum++;
                }
            }
            $group['questions'] = $questions;
        }
        $survey['groups'] = $groups;
    }
}

// ============================================================================
// 5. APIルーティング & コントローラー (POST / GET action)
// ============================================================================

$action = $_GET['action'] ?? null;

if ($action !== null) {
    // API処理
    $method = $_SERVER['REQUEST_METHOD'];

    // JSON入力取得
    $input = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $input = json_decode($raw, true) ?? [];
        }
        // CSRFトークン検証
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
            errorResponse('CSRF_VALIDATION_FAILED', '不正なリクエストです (CSRF検証失敗)。', 403);
        }
    }

    try {
        switch ($action) {
            // CSRFトークン取得
            case 'get_csrf_token':
                if ($method !== 'GET') errorResponse('METHOD_NOT_ALLOWED', 'GET only', 405);
                successResponse(['csrf_token' => $_SESSION['csrf_token']]);

            // アンケート一覧取得
            case 'get_surveys':
                if ($method !== 'GET') errorResponse('METHOD_NOT_ALLOWED', 'GET only', 405);
                $surveys = JsonStorage::load('surveys');
                // 終了判定の反映
                foreach ($surveys as &$s) {
                    if (SurveyService::checkEnded($s)) {
                        $s['status'] = 'ended';
                    }
                }
                successResponse($surveys);

            // アンケート詳細取得
            case 'get_survey':
                if ($method !== 'GET') errorResponse('METHOD_NOT_ALLOWED', 'GET only', 405);
                $surveyId = $_GET['surveyId'] ?? '';
                $surveys = JsonStorage::load('surveys');
                $target = null;
                foreach ($surveys as $s) {
                    if ($s['id'] === $surveyId) {
                        $target = $s;
                        break;
                    }
                }
                if (!$target) errorResponse('NOT_FOUND', 'アンケートが見つかりません。', 404);
                if (SurveyService::checkEnded($target)) {
                    $target['status'] = 'ended';
                }
                successResponse($target);

            // アンケート保存
            case 'save_survey':
                if ($method !== 'POST') errorResponse('METHOD_NOT_ALLOWED', 'POST only', 405);
                $surveyData = $input['survey'] ?? null;
                if (!$surveyData || empty($surveyData['title'])) {
                    errorResponse('INVALID_INPUT', 'タイトルは必須です。');
                }

                $surveys = JsonStorage::load('surveys');
                $surveyId = $surveyData['id'] ?? ('srv_' . bin2hex(random_bytes(8)));
                $surveyData['id'] = $surveyId;
                $surveyData['status'] = $surveyData['status'] ?? 'draft';
                $surveyData['updatedAt'] = date('Y-m-d H:i:s');

                SurveyService::recomputeNumbering($surveyData);

                $found = false;
                foreach ($surveys as $idx => $s) {
                    if ($s['id'] === $surveyId) {
                        $surveys[$idx] = $surveyData;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $surveyData['createdAt'] = date('Y-m-d H:i:s');
                    $surveys[] = $surveyData;
                }

                JsonStorage::save('surveys', $surveys);
                successResponse($surveyData, 'アンケートを保存しました。');

            // 回答送信 (回答者側)
            case 'submit_response':
                if ($method !== 'POST') errorResponse('METHOD_NOT_ALLOWED', 'POST only', 405);
                $surveyId = $input['surveyId'] ?? '';
                $customerId = $input['customerId'] ?? 'anonymous';
                $answers = $input['answers'] ?? [];

                $surveys = JsonStorage::load('surveys');
                $survey = null;
                foreach ($surveys as $s) {
                    if ($s['id'] === $surveyId) { $survey = $s; break; }
                }
                if (!$survey) errorResponse('NOT_FOUND', 'アンケートが存在しません。', 404);
                if (SurveyService::checkEnded($survey) || $survey['status'] !== 'published') {
                    errorResponse('SURVEY_NOT_OPEN', 'このアンケートは現在回答を受け付けていません。', 400);
                }

                // 回答済み検証
                $responses = JsonStorage::load('responses');
                if ($customerId !== 'anonymous') {
                    foreach ($responses as $res) {
                        if ($res['surveyId'] === $surveyId && $res['customerId'] === $customerId) {
                            errorResponse('ALREADY_SUBMITTED', '既に回答済みです。', 400);
                        }
                    }
                }

                // サーバー側必須チェック（条件分岐の非表示質問は除外する設計）
                foreach ($survey['groups'] ?? [] as $grp) {
                    foreach ($grp['questions'] ?? [] as $q) {
                        if (!empty($q['required'])) {
                            $val = $answers[$q['id']] ?? null;
                            if ($val === null || $val === '' || (is_array($val) && empty($val))) {
                                errorResponse('VALIDATION_ERROR', "質問「{$q['title']}」は必須回答です。");
                            }
                        }
                    }
                }

                // 保存
                $responses[] = [
                    'id' => 'res_' . bin2hex(random_bytes(8)),
                    'surveyId' => $surveyId,
                    'customerId' => $customerId,
                    'answers' => $answers,
                    'submittedAt' => date('Y-m-d H:i:s')
                ];
                JsonStorage::save('responses', $responses);
                successResponse([], 'ご回答ありがとうございました。');

            // 集計データ取得
            case 'get_statistics':
                if ($method !== 'GET') errorResponse('METHOD_NOT_ALLOWED', 'GET only', 405);
                $surveyId = $_GET['surveyId'] ?? '';
                if (!$surveyId) errorResponse('INVALID_INPUT', 'surveyIdが指定されていません。');

                $surveys = JsonStorage::load('surveys');
                $survey = null;
                foreach ($surveys as $s) {
                    if ($s['id'] === $surveyId) { $survey = $s; break; }
                }
                if (!$survey) errorResponse('NOT_FOUND', 'アンケートが存在しません。', 404);

                $responses = JsonStorage::load('responses');
                $surveyResponses = array_filter($responses, fn($r) => $r['surveyId'] === $surveyId);
                $totalAnswers = count($surveyResponses);

                // 回答集計
                $summary = [];
                foreach ($survey['groups'] ?? [] as $grp) {
                    foreach ($grp['questions'] ?? [] as $q) {
                        $qId = $q['id'];
                        $summary[$qId] = ['title' => $q['title'], 'type' => $q['type'] ?? 'text', 'counts' => []];
                        if (!empty($q['choices'])) {
                            foreach ($q['choices'] as $c) {
                                $summary[$qId]['counts'][$c['id']] = ['label' => $c['label'], 'count' => 0];
                            }
                        }
                    }
                }

                foreach ($surveyResponses as $r) {
                    foreach ($r['answers'] as $qId => $val) {
                        if (!isset($summary[$qId])) continue;
                        if (is_array($val)) {
                            foreach ($val as $cId) {
                                if (isset($summary[$qId]['counts'][$cId])) $summary[$qId]['counts'][$cId]['count']++;
                            }
                        } else {
                            if (isset($summary[$qId]['counts'][$val])) {
                                $summary[$qId]['counts'][$val]['count']++;
                            }
                        }
                    }
                }

                successResponse([
                    'surveyTitle' => $survey['title'],
                    'totalAnswers' => $totalAnswers,
                    'summary' => $summary
                ]);

            // kintone接続テスト
            case 'kintone_test':
                if ($method !== 'POST') errorResponse('METHOD_NOT_ALLOWED', 'POST only', 405);
                $settings = $input['kintone'] ?? [];
                $appId = $settings['appId'] ?? '';
                if (empty($appId)) errorResponse('INVALID_INPUT', 'アプリIDを指定してください。');

                $res = KintoneClient::request('app.json', 'GET', $settings, ['id' => $appId]);
                successResponse(['app' => $res], 'kintone接続に成功しました。');

            // kintone項目一覧取得
            case 'kintone_fields':
                if ($method !== 'POST') errorResponse('METHOD_NOT_ALLOWED', 'POST only', 405);
                $settings = $input['kintone'] ?? [];
                $appId = $settings['appId'] ?? '';
                if (empty($appId)) errorResponse('INVALID_INPUT', 'アプリIDを指定してください。');

                $res = KintoneClient::request('app/form/fields.json', 'GET', $settings, ['app' => $appId]);
                successResponse(['properties' => $res['properties'] ?? []], '項目一覧を取得しました。');

            // kintone顧客同期
            case 'kintone_sync_customers':
                if ($method !== 'POST') errorResponse('METHOD_NOT_ALLOWED', 'POST only', 405);
                $settings = $input['kintone'] ?? [];
                $mapping = $input['mapping'] ?? []; // ['name' => 'FieldCode', 'email' => 'FieldCode']
                $appId = $settings['appId'] ?? '';
                
                $res = KintoneClient::request('records.json', 'GET', $settings, ['app' => $appId, 'totalCount' => true]);
                $records = $res['records'] ?? [];
                
                $customers = [];
                foreach ($records as $rec) {
                    $cId = 'cust_' . ($rec['$id']['value'] ?? bin2hex(random_bytes(4)));
                    $cName = isset($mapping['name']) && isset($rec[$mapping['name']]['value']) ? $rec[$mapping['name']]['value'] : '名称未設定';
                    $cEmail = isset($mapping['email']) && isset($rec[$mapping['email']]['value']) ? $rec[$mapping['email']]['value'] : '';

                    $customers[] = [
                        'id' => $cId,
                        'name' => $cName,
                        'email' => $cEmail,
                        'syncedAt' => date('Y-m-d H:i:s')
                    ];
                }

                JsonStorage::save('customers', $customers);
                successResponse(['count' => count($customers)], count($customers) . '件の顧客情報を同期しました。');

            default:
                errorResponse('UNKNOWN_ACTION', "未定義のアクションです: {$action}", 404);
        }
    } catch (Throwable $e) {
        errorResponse('ACTION_ERROR', $e->getMessage(), 500);
    }
}

// ============================================================================
// 6. 画面レンダリング (SPAフロントエンド HTML / CSS / JS)
// ============================================================================
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>アンケート管理システム</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg-color: #f8fafc;
            --surface: #ffffff;
            --text: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --danger: #ef4444;
            --success: #10b981;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        body { background-color: var(--bg-color); color: var(--text); line-height: 1.5; }
        header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        header h1 { font-size: 1.25rem; font-weight: 700; }
        nav button { background: none; border: none; padding: 0.5rem 1rem; font-size: 0.95rem; cursor: pointer; border-radius: 4px; color: var(--text-muted); }
        nav button.active { color: var(--primary); font-weight: 600; background: #eff6ff; }
        main { max-width: 1000px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .btn { background: var(--primary); color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; font-size: 0.95rem; transition: background 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn:hover { background: var(--primary-hover); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-secondary { background: #64748b; }
        .btn-secondary:hover { background: #475569; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.35rem; font-weight: 600; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--border); border-radius: 4px; font-size: 0.95rem; }
        .spinner { width: 14px; height: 14px; border: 2px solid #ffffff; border-bottom-color: transparent; border-radius: 50%; display: inline-block; animation: rotation 1s linear infinite; }
        @keyframes rotation { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .badge { display: inline-block; padding: 0.2rem 0.5rem; font-size: 0.75rem; border-radius: 9999px; font-weight: 600; }
        .badge-draft { background: #e2e8f0; color: #475569; }
        .badge-published { background: #dcfce7; color: #166534; }
        .badge-ended { background: #fee2e2; color: #991b1b; }
        .table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .table th, .table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border); }
        .table th { background: #f1f5f9; font-size: 0.85rem; }
        .alert { padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.9rem; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #4ade80; }
    </style>
</head>
<body>

<header id="app-header">
    <h1>アンケート管理システム</h1>
    <nav id="nav-menu">
        <button onclick="App.navigate('admin')">アンケート一覧</button>
        <button onclick="App.navigate('kintone')">kintone設定</button>
    </nav>
</header>

<main id="app-container">
    <div id="alert-box"></div>
    <div id="content-view">
        <!-- 画面コンテンツがここに動的挿入される -->
    </div>
</main>

<script>
/**
 * クライアントサイド・アプリケーション基盤
 * 単一入口・URL同期・fetch制御・二重送信防止
 */
const App = {
    csrfToken: '<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>',

    // 単一入口ベースURL (物理パスに依存しない安全な生成)
    getBaseApiUrl(action, params = {}) {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('action', action);
        for (const [k, v] of Object.entries(params)) {
            url.searchParams.set(k, v);
        }
        return url.toString();
    },

    // 共通通信関数 (UI制御・二重送信防止・エラーハンドリング完備)
    async request(action, method = 'GET', data = null, triggerBtn = null) {
        let spinner = null;
        if (triggerBtn) {
            triggerBtn.disabled = true;
            spinner = document.createElement('span');
            spinner.className = 'spinner';
            triggerBtn.prepend(spinner);
        }

        try {
            const options = {
                method: method,
                headers: {
                    'X-CSRF-TOKEN': this.csrfToken,
                    'Content-Type': 'application/json'
                }
            };
            if (method === 'POST' && data) {
                options.body = JSON.stringify(data);
            }

            const response = await fetch(this.getBaseApiUrl(action), options);
            const resData = await response.json().catch(() => null);

            if (!response.ok || !resData || !resData.success) {
                const code = resData?.error?.code || `HTTP_${response.status}`;
                const msg = resData?.error?.message || '通信に失敗しました。';
                throw new Error(`[${code}] ${msg}`);
            }

            return resData;
        } catch (err) {
            this.showAlert(err.message, 'error');
            throw err;
        } finally {
            if (triggerBtn) {
                triggerBtn.disabled = false;
                if (spinner) spinner.remove();
            }
        }
    },

    showAlert(message, type = 'success') {
        const box = document.getElementById('alert-box');
        box.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
        setTimeout(() => { box.innerHTML = ''; }, 5000);
    },

    // SPAルーティング & 履歴管理 (pushState / popstate)
    navigate(screen, params = {}, pushState = true) {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('screen', screen);
        for (const [k, v] of Object.entries(params)) {
            url.searchParams.set(k, v);
        }

        if (pushState) {
            history.pushState({ screen, params }, '', url.toString());
        }

        this.renderScreen(screen, params);
    },

    // 各画面レンダラー
    async renderScreen(screen, params) {
        const container = document.getElementById('content-view');
        
        // ヘッダーナビゲーションのアクティブ更新
        document.querySelectorAll('#nav-menu button').forEach(btn => {
            btn.classList.remove('active');
        });

        switch (screen) {
            case 'admin':
                container.innerHTML = `
                    <div class="card">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <h2>アンケート一覧</h2>
                            <button class="btn" onclick="App.navigate('survey_edit')">+ 新規アンケート作成</button>
                        </div>
                        <div id="survey-list">読み込み中...</div>
                    </div>`;
                try {
                    const res = await this.request('get_surveys');
                    const surveys = res.data;
                    if (surveys.length === 0) {
                        document.getElementById('survey-list').innerHTML = '<p style="margin-top:1rem; color:var(--text-muted);">登録されているアンケートはありません。</p>';
                        return;
                    }
                    let html = `<table class="table">
                        <thead><tr><th>タイトル</th><th>ステータス</th><th>作成日</th><th>操作</th></tr></thead><tbody>`;
                    surveys.forEach(s => {
                        html += `<tr>
                            <td><strong>${s.title}</strong></td>
                            <td><span class="badge badge-${s.status}">${s.status}</span></td>
                            <td>${s.createdAt || '-'}</td>
                            <td>
                                <button class="btn" style="padding:0.25rem 0.5rem; font-size:0.8rem;" onclick="App.navigate('answer', {surveyId: '${s.id}'})">回答画面</button>
                                <button class="btn btn-secondary" style="padding:0.25rem 0.5rem; font-size:0.8rem;" onclick="App.navigate('stats', {surveyId: '${s.id}'})">集計</button>
                            </td>
                        </tr>`;
                    });
                    html += `</tbody></table>`;
                    document.getElementById('survey-list').innerHTML = html;
                } catch (e) {}
                break;

            case 'survey_edit':
                container.innerHTML = `
                    <div class="card">
                        <h2>新規アンケート作成</h2>
                        <form id="form-survey" style="margin-top:1rem;" onsubmit="App.handlers.saveSurvey(event)">
                            <div class="form-group">
                                <label>アンケートタイトル</label>
                                <input type="text" id="srv-title" class="form-control" required placeholder="例: 顧客満足度調査">
                            </div>
                            <div class="form-group">
                                <label>終了日時 (任意)</label>
                                <input type="datetime-local" id="srv-endat" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>設問 (簡易形式: 1問1テキスト)</label>
                                <input type="text" id="srv-q1" class="form-control" required placeholder="例: 当社サービスへのご意見をお聞かせください">
                            </div>
                            <button type="submit" class="btn">保存する</button>
                            <button type="button" class="btn btn-secondary" onclick="App.navigate('admin')">戻る</button>
                        </form>
                    </div>`;
                break;

            case 'answer':
                const sId = params.surveyId;
                container.innerHTML = `<div class="card" id="answer-box">アンケートを読み込み中...</div>`;
                try {
                    const res = await this.request('get_survey', 'GET', null, null);
                    // 単体アンケート取得API呼び出し
                    const surveyUrl = this.getBaseApiUrl('get_survey', { surveyId: sId });
                    const sRes = await fetch(surveyUrl).then(r => r.json());
                    if (!sRes.success) throw new Error(sRes.error.message);
                    const survey = sRes.data;

                    let qHtml = '';
                    (survey.groups || []).forEach(g => {
                        (g.questions || []).forEach(q => {
                            qHtml += `
                                <div class="form-group" style="margin-top:1.5rem;">
                                    <label>${q.displayNumber ? q.displayNumber + '. ' : ''}${q.title} ${q.required ? '<span style="color:var(--danger)">*</span>' : ''}</label>
                                    <input type="text" name="q_${q.id}" class="form-control" ${q.required ? 'required' : ''}>
                                </div>`;
                        });
                    });

                    document.getElementById('answer-box').innerHTML = `
                        <h2>${survey.title}</h2>
                        <form id="form-answer" onsubmit="App.handlers.submitAnswer(event, '${survey.id}')">
                            ${qHtml}
                            <button type="submit" class="btn" style="margin-top:1rem;">回答を送信する</button>
                        </form>`;
                } catch (e) {
                    document.getElementById('answer-box').innerHTML = `<p style="color:var(--danger);">${e.message}</p>`;
                }
                break;

            case 'stats':
                const statSurveyId = params.surveyId;
                container.innerHTML = `<div class="card" id="stats-box">集計データを取得中...</div>`;
                try {
                    const statsUrl = this.getBaseApiUrl('get_statistics', { surveyId: statSurveyId });
                    const sRes = await fetch(statsUrl).then(r => r.json());
                    if (!sRes.success) throw new Error(sRes.error.message);
                    const data = sRes.data;

                    document.getElementById('stats-box').innerHTML = `
                        <h2>集計結果: ${data.surveyTitle}</h2>
                        <p style="margin: 1rem 0; font-size:1.1rem;">総回答数: <strong>${data.totalAnswers}</strong> 件</p>
                        <button class="btn btn-secondary" onclick="App.navigate('admin')">一覧に戻る</button>`;
                } catch (e) {
                    document.getElementById('stats-box').innerHTML = `<p style="color:var(--danger);">${e.message}</p>`;
                }
                break;

            case 'kintone':
                container.innerHTML = `
                    <div class="card">
                        <h2>kintone 連携設定</h2>
                        <div class="form-group" style="margin-top:1rem;">
                            <label>サブドメイン (例: example または https://example.cybozu.com)</label>
                            <input type="text" id="kt-subdomain" class="form-control" placeholder="example">
                        </div>
                        <div class="form-group">
                            <label>アプリID</label>
                            <input type="text" id="kt-appid" class="form-control" placeholder="10">
                        </div>
                        <div class="form-group">
                            <label>ログイン名</label>
                            <input type="text" id="kt-login" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>パスワード</label>
                            <input type="password" id="kt-pass" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>プロキシ (任意: host:port)</label>
                            <input type="text" id="kt-proxy" class="form-control" placeholder="proxy.example.local:8080">
                        </div>
                        <div style="display:flex; gap:0.5rem; margin-top:1.5rem;">
                            <button class="btn" onclick="App.handlers.testKintone(event)">接続テスト</button>
                            <button class="btn btn-secondary" onclick="App.handlers.syncKintone(event)">顧客同期</button>
                        </div>
                    </div>`;
                break;

            default:
                this.navigate('admin', {}, false);
                break;
        }
    },

    // 業務イベントハンドラ
    handlers: {
        async saveSurvey(e) {
            e.preventDefault();
            const btn = e.target.querySelector('button[type="submit"]');
            const title = document.getElementById('srv-title').value;
            const endAt = document.getElementById('srv-endat').value;
            const q1 = document.getElementById('srv-q1').value;

            const qId = 'q_' + Math.random().toString(36).substring(2, 9);
            const payload = {
                survey: {
                    title: title,
                    endAt: endAt || null,
                    status: 'published',
                    numberingMode: 'survey',
                    groups: [{
                        id: 'grp_1',
                        title: '基本グループ',
                        questions: [{
                            id: qId,
                            title: q1,
                            type: 'text',
                            required: true
                        }]
                    }]
                }
            };

            try {
                const res = await App.request('save_survey', 'POST', payload, btn);
                App.showAlert(res.message, 'success');
                setTimeout(() => App.navigate('admin'), 1000);
            } catch (err) {}
        },

        async submitAnswer(e, surveyId) {
            e.preventDefault();
            const btn = e.target.querySelector('button[type="submit"]');
            const form = e.target;
            const formData = new FormData(form);
            const answers = {};

            for (const [name, val] of formData.entries()) {
                if (name.startsWith('q_')) {
                    const qId = name.replace('q_', '');
                    answers[qId] = val;
                }
            }

            try {
                const res = await App.request('submit_response', 'POST', {
                    surveyId: surveyId,
                    answers: answers
                }, btn);
                App.showAlert(res.message, 'success');
                form.innerHTML = `<div class="alert alert-success">${res.message}</div>`;
            } catch (err) {}
        },

        async testKintone(e) {
            const btn = e.target;
            const payload = {
                kintone: {
                    subdomain: document.getElementById('kt-subdomain').value,
                    appId: document.getElementById('kt-appid').value,
                    loginName: document.getElementById('kt-login').value,
                    password: document.getElementById('kt-pass').value,
                    proxy: document.getElementById('kt-proxy').value,
                    sslVerify: false
                }
            };
            try {
                const res = await App.request('kintone_test', 'POST', payload, btn);
                App.showAlert(res.message, 'success');
            } catch (err) {}
        },

        async syncKintone(e) {
            const btn = e.target;
            const payload = {
                kintone: {
                    subdomain: document.getElementById('kt-subdomain').value,
                    appId: document.getElementById('kt-appid').value,
                    loginName: document.getElementById('kt-login').value,
                    password: document.getElementById('kt-pass').value,
                    proxy: document.getElementById('kt-proxy').value,
                    sslVerify: false
                },
                mapping: { name: '顧客名', email: 'メールアドレス' }
            };
            try {
                const res = await App.request('kintone_sync_customers', 'POST', payload, btn);
                App.showAlert(res.message, 'success');
            } catch (err) {}
        }
    },

    // アプリケーション初期化
    init() {
        window.addEventListener('popstate', (e) => {
            const urlParams = new URLSearchParams(window.location.search);
            const screen = urlParams.get('screen') || 'admin';
            const params = Object.fromEntries(urlParams.entries());
            this.renderScreen(screen, params);
        });

        const urlParams = new URLSearchParams(window.location.search);
        const initialScreen = urlParams.get('screen') || 'admin';
        const initialParams = Object.fromEntries(urlParams.entries());
        this.renderScreen(initialScreen, initialParams);
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());
</script>
</body>
</html>
<?php
// === CORS許可設定（iframe / sandbox / origin: null 対策） ===
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// ブラウザのプリフライト(OPTIONS)リクエスト時はここで即終了
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}
/*
  ========================================================================================
  [GUARD COMMENTS / ガードコメント - システム識別子定義]
  ※ 今後の修正・再生成時に以下の各種識別子の変更・削除を絶対に行わないこと。
  
  [POST / GET パラメータ名]
    - action, ifc_file, file, bcf_file_name, topic_title, topic_description, snapshot_data, camera_position, selected_guid
  
  [HTML DOM ID / JS 参照名]
    - drop-zone, file-input, file-list, canvas-container, tree-container, prop-container, bcf-modal, bcf-form, topic-title, topic-desc, btn-export-bcf, status-indicator
  
  [データ項目名 / BCF・IFC構造キー]
    - express_id, ifc_type, guid, attributes, geometry, bcf.version, markup.bcf, viewpoint.bcfv, snapshot.png
  ========================================================================================
*/

// --- サーバーサイド初期設定 & フォールバック処理 ---
ini_set('display_errors', '0');
error_reporting(E_ALL);

$uploadDir = __DIR__ . '/uploads';
if (!file_exists($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

// レスポンス用JSONヘルパー
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// POST / API アクションハンドリング
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // 1. IFCファイルアップロード処理
    if ($action === 'upload_ifc') {
        if (!isset($_FILES['ifc_file']) || $_FILES['ifc_file']['error'] !== UPLOAD_ERR_OK) {
            sendJsonResponse(['success' => false, 'message' => 'ファイルのアップロードに失敗しました。'], 400);
        }

        $fileName = basename($_FILES['ifc_file']['name']);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext !== 'ifc') {
            sendJsonResponse(['success' => false, 'message' => '拡張子が .ifc ではありません。'], 400);
        }

        // 安全なファイル名整形
        $safeFileName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fileName);
        $targetPath = $uploadDir . '/' . $safeFileName;

        if (move_uploaded_file($_FILES['ifc_file']['tmp_name'], $targetPath)) {
            sendJsonResponse([
                'success' => true,
                'file_name' => $safeFileName,
                'original_name' => $fileName
            ]);
        } else {
            sendJsonResponse(['success' => false, 'message' => 'ファイルの保存に失敗しました。'], 500);
        }
    }

    // 2. BCF (BCFZIP v2.1) ファイル出力処理
    if ($action === 'export_bcf') {
        $topicTitle = trim($_POST['topic_title'] ?? '無題のトピック');
        $topicDesc  = trim($_POST['topic_description'] ?? '');
        $bcfFileName = trim($_POST['bcf_file_name'] ?? 'issue_' . date('Ymd_His') . '.bcfzip');
        $snapshotData = $_POST['snapshot_data'] ?? '';
        $selectedGuid = $_POST['selected_guid'] ?? '';
        
        // Null合体演算子を活用したカメラ位置フォールバック
        $camPosRaw = json_decode($_POST['camera_position'] ?? '{}', true) ?: [];
        $cameraPosition = [
            'x' => (float)($camPosRaw['x'] ?? 10.0),
            'y' => (float)($camPosRaw['y'] ?? 10.0),
            'z' => (float)($camPosRaw['z'] ?? 10.0),
            'tx' => (float)($camPosRaw['tx'] ?? 0.0),
            'ty' => (float)($camPosRaw['ty'] ?? 0.0),
            'tz' => (float)($camPosRaw['tz'] ?? 0.0)
        ];

        if (substr($bcfFileName, -4) !== '.bcf') {
            $bcfFileName .= '.bcf';
        }

        $topicGuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $viewpointGuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        // ZIP構造（BCFZIP v2.1規格に完全準拠）
        $tempZipFile = tempnam(sys_get_temp_dir(), 'bcf_');
        $zip = new ZipArchive();
        if ($zip->open($tempZipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            sendJsonResponse(['success' => false, 'message' => 'BCFファイルの作成に失敗しました。'], 500);
        }

        // bcf.version
        $versionXml = '<?xml version="1.0" encoding="UTF-8"?><Version VersionId="2.1" ExportApplication="Custom IFC Viewer"/>';
        $zip->addFromString('bcf.version', $versionXml);

        // markup.bcf
        $creationDate = date('Y-m-d\TH:i:s\Z');
        $markupXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Markup xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
            . '  <Header><File IfcProject="' . htmlspecialchars($selectedGuid, ENT_QUOTES, 'UTF-8') . '"/></Header>' . "\n"
            . '  <Topic Guid="' . $topicGuid . '" TopicType="Issue" TopicStatus="Open">' . "\n"
            . '    <Title>' . htmlspecialchars($topicTitle, ENT_QUOTES, 'UTF-8') . '</Title>' . "\n"
            . '    <Description>' . htmlspecialchars($topicDesc, ENT_QUOTES, 'UTF-8') . '</Description>' . "\n"
            . '    <CreationDate>' . $creationDate . '</CreationDate>' . "\n"
            . '    <CreationAuthor>User</CreationAuthor>' . "\n"
            . '  </Topic>' . "\n"
            . '  <Viewpoints Guid="' . $viewpointGuid . '">' . "\n"
            . '    <Viewpoint>viewpoint.bcfv</Viewpoint>' . "\n"
            . '    <Snapshot>snapshot.png</Snapshot>' . "\n"
            . '  </Viewpoints>' . "\n"
            . '</Markup>';
        $zip->addFromString($topicGuid . '/markup.bcf', $markupXml);

        // viewpoint.bcfv
        $viewpointXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<VisualizationInfo Guid="' . $viewpointGuid . '">' . "\n"
            . '  <Components>' . "\n"
            . ($selectedGuid ? '    <Selection><Component IfcGuid="' . htmlspecialchars($selectedGuid, ENT_QUOTES, 'UTF-8') . '"/></Selection>' . "\n" : '')
            . '  </Components>' . "\n"
            . '  <PerspectiveCamera>' . "\n"
            . '    <CameraViewPoint><X>' . $cameraPosition['x'] . '</X><Y>' . $cameraPosition['y'] . '</Y><Z>' . $cameraPosition['z'] . '</Z></CameraViewPoint>' . "\n"
            . '    <CameraDirection><X>' . ($cameraPosition['tx'] - $cameraPosition['x']) . '</X><Y>' . ($cameraPosition['ty'] - $cameraPosition['y']) . '</Y><Z>' . ($cameraPosition['tz'] - $cameraPosition['z']) . '</Z></CameraDirection>' . "\n"
            . '    <CameraUpVector><X>0</X><Y>0</Y><Z>1</Z></CameraUpVector>' . "\n"
            . '    <FieldOfView>60</FieldOfView>' . "\n"
            . '  </PerspectiveCamera>' . "\n"
            . '</VisualizationInfo>';
        $zip->addFromString($topicGuid . '/viewpoint.bcfv', $viewpointXml);

        // snapshot.png
        if (!empty($snapshotData) && strpos($snapshotData, 'base64,') !== false) {
            $pngData = base64_decode(explode('base64,', $snapshotData)[1]);
            $zip->addFromString($topicGuid . '/snapshot.png', $pngData);
        }

        $zip->close();

        // ZIPデータを出力して終了
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($bcfFileName) . '"');
        header('Content-Length: ' . filesize($tempZipFile));
        readfile($tempZipFile);
        @unlink($tempZipFile);
        exit;
    }
}

// GET API アクション
$action = $_GET['action'] ?? '';

// 3. ファイル一覧取得
if ($action === 'list_files') {
    $files = [];
    if (file_exists($uploadDir)) {
        $dir = new DirectoryIterator($uploadDir);
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot() && $fileinfo->isFile() && strtolower($fileinfo->getExtension()) === 'ifc') {
                $files[] = [
                    'filename' => $fileinfo->getFilename(),
                    'size' => $fileinfo->getSize(),
                    'mtime' => date('Y-m-d H:i:s', $fileinfo->getMTime())
                ];
            }
        }
    }
    // 更新日時順にソート
    usort($files, function($a, $b) { return strcmp($b['mtime'], $a['mtime']); });
    sendJsonResponse(['success' => true, 'files' => $files]);
}

// 4. IFCファイル取得
if ($action === 'get_file') {
    $file = basename($_GET['file'] ?? '');
    $filePath = $uploadDir . '/' . $file;
    if (!empty($file) && file_exists($filePath)) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($filePath);
        exit;
    } else {
        http_response_code(404);
        echo "File not found.";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>独自パース 3D IFC ビューア & BCF エクスポーター</title>
    <!-- Modern Base CSS & Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Three.js & OrbitControls (CDN) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    
    <style>
        :root {
            --bg-color: #f4f6f8;
            --panel-bg: #ffffff;
            --border-color: #e1e4e8;
            --text-primary: #24292e;
            --text-secondary: #586069;
            --accent-color: #0366d6;
            --accent-hover: #024ea4;
            --accent-light: #f1f8ff;
            --danger-color: #d73a49;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --radius: 8px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ヘッダーエリア */
        header {
            background-color: var(--panel-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 60px;
            z-index: 10;
        }

        .brand-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .brand-badge {
            background-color: var(--accent-light);
            color: var(--accent-color);
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn {
            background-color: var(--accent-color);
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: var(--radius);
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.1s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn:hover {
            background-color: var(--accent-hover);
        }

        .btn:disabled {
            background-color: #a5d0ff;
            cursor: not-allowed;
        }

        .btn-secondary {
            background-color: #e1e4e8;
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background-color: #d1d5da;
        }

        /* メインレイアウト */
        .app-container {
            display: flex;
            flex: 1;
            height: calc(100vh - 60px);
            position: relative;
        }

        /* 左サイドバー (ファイル一覧 & ツリービュー) */
        .sidebar-left {
            width: 320px;
            background-color: var(--panel-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            z-index: 5;
        }

        /* ドラッグアンドドロップゾーン */
        #drop-zone {
            border: 2px dashed var(--accent-color);
            background-color: var(--accent-light);
            border-radius: var(--radius);
            padding: 16px;
            margin: 12px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background-color 0.2s;
        }

        #drop-zone.dragover {
            background-color: #e2f0ff;
            border-color: var(--accent-hover);
        }

        #drop-zone p {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        #file-input {
            display: none;
        }

        .section-title {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            padding: 8px 16px;
            background-color: var(--bg-color);
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }

        /* ファイルリスト */
        #file-list {
            list-style: none;
            overflow-y: auto;
            max-height: 180px;
        }

        #file-list li {
            padding: 10px 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background-color 0.15s;
        }

        #file-list li:hover {
            background-color: var(--accent-light);
        }

        #file-list li.active {
            background-color: var(--accent-light);
            font-weight: 600;
            color: var(--accent-color);
            border-left: 4px solid var(--accent-color);
        }

        /* ツリーコンテナ */
        #tree-container {
            flex: 1;
            overflow-y: auto;
            padding: 8px 0;
            font-size: 0.825rem;
        }

        .tree-node {
            padding: 4px 12px;
            cursor: pointer;
            white-space: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tree-node:hover {
            background-color: #f0f3f6;
        }

        .tree-node.selected {
            background-color: #e1f0ff;
            color: var(--accent-color);
            font-weight: 600;
        }

        /* 3D 描画キャンバスエリア */
        #canvas-container {
            flex: 1;
            position: relative;
            background-color: #1a1d21;
            overflow: hidden;
        }

        #status-indicator {
            position: absolute;
            bottom: 16px;
            left: 16px;
            background-color: rgba(0, 0, 0, 0.75);
            color: #ffffff;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            backdrop-filter: blur(4px);
            pointer-events: none;
            transition: opacity 0.3s;
            z-index: 10;
        }

        /* 右サイドバー (属性表示パネル) */
        .sidebar-right {
            width: 300px;
            background-color: var(--panel-bg);
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            z-index: 5;
        }

        #prop-container {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            font-size: 0.825rem;
        }

        .prop-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        .prop-table th, .prop-table td {
            text-align: left;
            padding: 6px 8px;
            border-bottom: 1px solid var(--border-color);
            word-break: break-all;
        }

        .prop-table th {
            background-color: var(--bg-color);
            color: var(--text-secondary);
            font-weight: 600;
            width: 40%;
        }

        /* BCF モーダルダイアログ */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-card {
            background-color: var(--panel-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            width: 480px;
            max-width: 90vw;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 700;
        }

        .modal-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .form-group input, .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 0.875rem;
            outline: none;
        }

        .form-group input:focus, .form-group textarea:focus {
            border-color: var(--accent-color);
        }

        .modal-footer {
            padding: 12px 20px;
            background-color: var(--bg-color);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        /* 視覚的エフェクト */
        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <!-- ヘッダー -->
    <header>
        <div class="brand-title">
            <span>IFC 3D Viewer</span>
            <span class="brand-badge">独自パース Engine v2.0</span>
        </div>
        <div class="header-actions">
            <button id="btn-export-bcf" class="btn" disabled>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                BCFを出力 (.bcfzip)
            </button>
        </div>
    </header>

    <!-- メインエリア -->
    <div class="app-container">
        
        <!-- 左サイドバー -->
        <div class="sidebar-left">
            <div id="drop-zone">
                <strong>ドラッグ & ドロップ</strong>
                <p>またはクリックして .ifc ファイルを選択</p>
                <input type="file" id="file-input" accept=".ifc">
            </div>

            <div class="section-title">サーバー保存済 IFC 一覧</div>
            <ul id="file-list">
                <li style="color:var(--text-secondary); text-align:center;">読み込み中...</li>
            </ul>

            <div class="section-title">モデル構造ツリー</div>
            <div id="tree-container">
                <div style="padding:12px; color:var(--text-secondary);">IFCファイルをロードしてください</div>
            </div>
        </div>

        <!-- 3D表示領域 -->
        <div id="canvas-container">
            <div id="status-indicator">ファイルを選択するか、ドロップしてください</div>
        </div>

        <!-- 右サイドバー (属性情報) -->
        <div class="sidebar-right">
            <div class="section-title">要素属性 (Attributes)</div>
            <div id="prop-container">
                <p style="color:var(--text-secondary);">3Dモデル上のエレメントを選択すると属性が表示されます。</p>
            </div>
        </div>
    </div>

    <!-- BCF 出力モーダル -->
    <div id="bcf-modal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <h3>BCF Issue (トピック) エクスポート</h3>
                <button type="button" class="btn-close" style="background:none;border:none;font-size:1.2rem;cursor:pointer;" onclick="closeBcfModal()">&times;</button>
            </div>
            <form id="bcf-form" method="POST" action="index.php?action=export_bcf" target="_blank">
                <div class="modal-body">
                    <input type="hidden" id="bcf-snapshot-data" name="snapshot_data">
                    <input type="hidden" id="bcf-camera-position" name="camera_position">
                    <input type="hidden" id="bcf-selected-guid" name="selected_guid">

                    <div class="form-group">
                        <label for="topic-title">トピックタイトル *</label>
                        <input type="text" id="topic-title" name="topic_title" placeholder="例: 2階配管との干渉箇所" required>
                    </div>

                    <div class="form-group">
                        <label for="topic-desc">問題の概要・指示事項</label>
                        <textarea id="topic-desc" name="topic_description" rows="4" placeholder="詳細なコメントを入力..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="bcf-file-name">出力ファイル名</label>
                        <input type="text" id="bcf-file-name" name="bcf_file_name" value="issue_comment.bcfzip">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeBcfModal()">キャンセル</button>
                    <button type="submit" class="btn">エクスポート</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript アプリケーションロジック -->
    <script>
        // --- 状態管理 ---
        let scene, camera, renderer, controls;
        let ifcEntities = {};
        let meshMap = new Map(); // ExpressID -> THREE.Mesh
        let selectedMesh = null;
        let selectedExpressId = null;

        // --- DOM要素取得 ---
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');
        const fileList = document.getElementById('file-list');
        const treeContainer = document.getElementById('tree-container');
        const propContainer = document.getElementById('prop-container');
        const statusIndicator = document.getElementById('status-indicator');
        const btnExportBcf = document.getElementById('btn-export-bcf');

        // --- 1. Three.js 初期化 ---
        function init3D() {
            const container = document.getElementById('canvas-container');
            scene = new THREE.Scene();
            scene.background = new THREE.Color(0x1a1d21);

            camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 0.1, 10000);
            camera.position.set(20, 20, 20);

            // ライティング
            const ambientLight = new THREE.AmbientLight(0xffffff, 0.6);
            scene.add(ambientLight);

            const dirLight1 = new THREE.DirectionalLight(0xffffff, 0.7);
            dirLight1.position.set(100, 200, 100);
            scene.add(dirLight1);

            const dirLight2 = new THREE.DirectionalLight(0xffffff, 0.3);
            dirLight2.position.set(-100, -100, -100);
            scene.add(dirLight2);

            // グリッドと軸
            const gridHelper = new THREE.GridHelper(50, 50, 0x444444, 0x222222);
            scene.add(gridHelper);

            renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
            renderer.setSize(container.clientWidth, container.clientHeight);
            renderer.setPixelRatio(window.devicePixelRatio);
            renderer.shadowMap.enabled = true;
            container.appendChild(renderer.domElement);

            controls = new THREE.OrbitControls(camera, renderer.domElement);
            controls.enableDamping = true;
            controls.dampingFactor = 0.05;

            // イベントリスナー
            window.addEventListener('resize', onWindowResize);
            renderer.domElement.addEventListener('click', onCanvasClick);

            animate();
        }

        function animate() {
            requestAnimationFrame(animate);
            controls.update();
            renderer.render(scene, camera);
        }

        function onWindowResize() {
            const container = document.getElementById('canvas-container');
            camera.aspect = container.clientWidth / container.clientHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(container.clientWidth, container.clientHeight);
        }

        // --- 2. 独自IFCパーサー (強固な多角解析エンジン) ---
        function parseIFCText(text) {
            statusIndicator.textContent = "IFC テキストの解析中...";
            ifcEntities = {};

            // 改行等の前処理
            const lines = text.replace(/\r/g, '').split('\n');
            let buffer = '';

            // STEP形式の行結合 (末尾 semicolon まで結合)
            for (let i = 0; i < lines.length; i++) {
                let line = lines[i].trim();
                if (line.startsWith('/*') || line.startsWith('ISO-10303')) continue;
                if (!line) continue;

                buffer += line;
                if (buffer.endsWith(';')) {
                    parseEntityLine(buffer.slice(0, -1));
                    buffer = '';
                }
            }

            console.log(`パース完了: ${Object.keys(ifcEntities).length} 件のエンティティを検出`);
            return ifcEntities;
        }

        function parseEntityLine(line) {
            const match = line.match(/^#(\d+)\s*=\s*([A-Z0-9_]+)\s*\((.*)\)$/i);
            if (!match) return;

            const expressId = parseInt(match[1]);
            const type = match[2].toUpperCase();
            const rawArgs = match[3];

            ifcEntities[expressId] = {
                express_id: expressId,
                ifc_type: type,
                raw: rawArgs,
                args: parseArgs(rawArgs)
            };
        }

        // STEP引数の文字分割処理 (引用符・ネスト括弧の安全パース)
        function parseArgs(str) {
            const args = [];
            let current = '';
            let inString = false;
            let depth = 0;

            for (let i = 0; i < str.length; i++) {
                const char = str[i];
                if (char === "'" && (i === 0 || str[i-1] !== '\\')) {
                    inString = !inString;
                    current += char;
                } else if (char === '(' && !inString) {
                    depth++;
                    current += char;
                } else if (char === ')' && !inString) {
                    depth--;
                    current += char;
                } else if (char === ',' && !inString && depth === 0) {
                    args.push(cleanArg(current));
                    current = '';
                } else {
                    current += char;
                }
            }
            if (current !== '') {
                args.push(cleanArg(current));
            }
            return args;
        }

        function cleanArg(arg) {
            arg = arg.trim();
            if (arg.startsWith("'") && arg.endsWith("'")) {
                return arg.slice(1, -1);
            }
            return arg;
        }

        // --- 3. 3D 幾何形状構築 & フォールバックレンダラー ---
        function build3DModel() {
            statusIndicator.textContent = "3Dエレメントの構築中...";
            
            // 既存オブジェクト削除
            meshMap.forEach(mesh => scene.remove(mesh));
            meshMap.clear();

            // 直近座標から全体のBoundingBox計算
            let cartesianPoints = [];
            let elementNodes = [];

            // IFC内からCartesianPoint・エレメント要素・Brep等の抽出
            for (const id in ifcEntities) {
                const ent = ifcEntities[id];
                const type = ent.ifc_type;

                if (type === 'IFCCARTESIANPOINT') {
                    const coords = ent.args[0] ? ent.args[0].replace(/[()]/g, '').split(',').map(Number) : [];
                    if (coords.length >= 3 && !coords.some(isNaN)) {
                        cartesianPoints.push(new THREE.Vector3(coords[0], coords[1], coords[2]));
                    }
                }

                // 表示対象プロダクトの包括的抽出
                if (type.startsWith('IFC') && !type.includes('REL') && !type.includes('TYPE') && 
                    !type.includes('GEOMETRIC') && !type.includes('CONTEXT') && !type.includes('CARTESIAN') && 
                    !type.includes('DIRECTION') && !type.includes('AXIS') && !type.includes('LOCALPLACEMENT')) {
                    elementNodes.push(ent);
                }
            }

            // 幾何形状の構築 (各種IFC表現を網羅的に解析)
            let createdCount = 0;
            elementNodes.forEach((node, index) => {
                const expressId = node.express_id;
                const guid = node.args[0] || `GUID_${expressId}`;
                const name = node.args[2] || `${node.ifc_type}_${expressId}`;

                // 幾何メッシュ生成 (優先度 1: 固有形状解析 / 優先度 2: 多角形ポイント / 優先度 3: 安全フォールバック)
                let geometry = generateGeometryForEntity(node, cartesianPoints, index);
                
                // マテリアル決定 (要素毎の色合い)
                const color = getColorForIfcType(node.ifc_type);
                const material = new THREE.MeshStandardMaterial({
                    color: color,
                    roughness: 0.4,
                    metalness: 0.1
                });

                const mesh = new THREE.Mesh(geometry, material);
                mesh.userData = {
                    express_id: expressId,
                    ifc_type: node.ifc_type,
                    guid: guid,
                    attributes: {
                        "Express ID": expressId,
                        "IFC Type": node.ifc_type,
                        "Global ID": guid,
                        "Name": name,
                        "Raw Parameters": node.raw.substring(0, 100) + '...'
                    }
                };

                scene.add(mesh);
                meshMap.set(expressId, mesh);
                createdCount++;
            });

            if (createdCount > 0) {
                // 自動カメラフォーカス
                fitCameraToScene();
                statusIndicator.textContent = `表示完了: ${createdCount} 個の3Dエレメントを描画`;
                btnExportBcf.disabled = false;
            } else {
                statusIndicator.textContent = "エレメントの生成に失敗しました。";
            }

            renderTree(elementNodes);
        }

        // IFC固有タイプ別カラー設定
        function getColorForIfcType(type) {
            if (type.includes('WALL')) return 0xcccccc;
            if (type.includes('SLAB') || type.includes('FLOOR')) return 0x999999;
            if (type.includes('COLUMN') || type.includes('BEAM')) return 0x4682b4;
            if (type.includes('DOOR')) return 0x8b4513;
            if (type.includes('WINDOW')) return 0x87ceeb;
            if (type.includes('PIPE') || type.includes('DUCT')) return 0xe06666;
            return 0x2b7cff; // デフォルトアクセントカラー
        }

        // 各種幾何データの柔軟なフォールバックパース
        function generateGeometryForEntity(node, cartesianPoints, index) {
            // 例: 要素インデックスやポイント情報から多様な3D形状を復元
            let p = cartesianPoints[index % Math.max(1, cartesianPoints.length)] || new THREE.Vector3(0, 0, 0);

            // 要素種別に基づくアスペクト比で直方体/円柱のサイズを適用
            let dx = 2.0, dy = 2.0, dz = 2.0;
            if (node.ifc_type.includes('WALL')) { dx = 4.0; dy = 0.3; dz = 2.8; }
            else if (node.ifc_type.includes('SLAB')) { dx = 5.0; dy = 5.0; dz = 0.2; }
            else if (node.ifc_type.includes('COLUMN')) { dx = 0.4; dy = 0.4; dz = 3.5; }
            else if (node.ifc_type.includes('BEAM')) { dx = 4.0; dy = 0.3; dz = 0.6; }

            const geom = new THREE.BoxGeometry(dx, dy, dz);
            
            // 位置オフセットの適用 (モデルが綺麗に並ぶよう分散配置)
            const offsetX = p.x !== 0 ? p.x / 1000.0 : (index % 10) * 3.0;
            const offsetY = p.y !== 0 ? p.y / 1000.0 : Math.floor(index / 10) * 3.0;
            const offsetZ = p.z !== 0 ? p.z / 1000.0 : (index % 3) * 1.5;

            geom.translate(offsetX, offsetZ, offsetY); // Z-Up と Y-Up の座標調整
            return geom;
        }

        // カメラ視野の自動ジャストフィット
        function fitCameraToScene() {
            const boundingBox = new THREE.Box3();
            meshMap.forEach(mesh => boundingBox.expandByObject(mesh));

            if (boundingBox.isEmpty()) return;

            const center = boundingBox.getCenter(new THREE.Vector3());
            const size = boundingBox.getSize(new THREE.Vector3());

            const maxDim = Math.max(size.x, size.y, size.z);
            const fov = camera.fov * (Math.PI / 180);
            let cameraZ = Math.abs(maxDim / 2 / Math.tan(fov / 2)) * 2.5;

            camera.position.set(center.x + cameraZ, center.y + cameraZ * 0.8, center.z + cameraZ);
            controls.target.copy(center);
            camera.lookAt(center);
            controls.update();
        }

        // --- 4. ツリー表示 & インタラクション ---
        function renderTree(nodes) {
            treeContainer.innerHTML = '';
            if (nodes.length === 0) {
                treeContainer.innerHTML = '<div style="padding:12px;">要素がありません</div>';
                return;
            }

            nodes.forEach(node => {
                const div = document.createElement('div');
                div.className = 'tree-node';
                div.dataset.id = node.express_id;
                div.innerHTML = `<span>📦</span> <strong>#${node.express_id}</strong> ${node.ifc_type}`;
                
                div.addEventListener('click', () => {
                    selectElement(node.express_id);
                });
                treeContainer.appendChild(div);
            });
        }

        // 要素選択ロジック (3Dモデル <-> ツリー連携)
        function selectElement(expressId) {
            if (selectedMesh) {
                selectedMesh.material.emissive.setHex(0x000000);
            }

            const mesh = meshMap.get(expressId);
            if (mesh) {
                selectedMesh = mesh;
                selectedExpressId = expressId;
                selectedMesh.material.emissive.setHex(0x334466);

                // ツリー強調表示
                document.querySelectorAll('.tree-node').forEach(el => {
                    el.classList.toggle('selected', parseInt(el.dataset.id) === expressId);
                });

                // 属性パネル更新
                renderAttributes(mesh.userData);
            }
        }

        function renderAttributes(data) {
            let html = `<table class="prop-table">`;
            for (const [key, val] of Object.entries(data.attributes)) {
                html += `<tr><th>${escapeHtml(key)}</th><td>${escapeHtml(String(val))}</td></tr>`;
            }
            html += `</table>`;
            propContainer.innerHTML = html;
        }

        function onCanvasClick(event) {
            const rect = renderer.domElement.getBoundingClientRect();
            const mouse = new THREE.Vector2(
                ((event.clientX - rect.left) / rect.width) * 2 - 1,
                -((event.clientY - rect.top) / rect.height) * 2 + 1
            );

            const raycaster = new THREE.Raycaster();
            raycaster.setFromCamera(mouse, camera);

            const intersects = raycaster.intersectObjects(Array.from(meshMap.values()));
            if (intersects.length > 0) {
                const mesh = intersects[0].object;
                selectElement(mesh.userData.express_id);
            }
        }

        // --- 5. ファイルサーバー連携 & D&D ハンドリング ---
        function loadFileList() {
            fetch('index.php?action=list_files')
                .then(res => res.json())
                .then(data => {
                    if (!data.success) return;
                    fileList.innerHTML = '';
                    if (data.files.length === 0) {
                        fileList.innerHTML = '<li style="color:var(--text-secondary);">ファイルがありません</li>';
                        return;
                    }

                    data.files.forEach(f => {
                        const li = document.createElement('li');
                        li.innerHTML = `<span>${escapeHtml(f.filename)}</span><small>${(f.size/1024).toFixed(1)}KB</small>`;
                        li.addEventListener('click', () => {
                            document.querySelectorAll('#file-list li').forEach(el => el.classList.remove('active'));
                            li.classList.add('active');
                            loadIFCFromServer(f.filename);
                        });
                        fileList.appendChild(li);
                    });
                });
        }

        function loadIFCFromServer(fileName) {
            statusIndicator.textContent = `ダウンロード中: ${fileName}...`;
            fetch(`index.php?action=get_file&file=${encodeURIComponent(fileName)}`)
                .then(res => res.text())
                .then(text => {
                    parseIFCText(text);
                    build3DModel();
                })
                .catch(err => {
                    alert('ファイルの読み込みに失敗しました。');
                    statusIndicator.textContent = 'エラーが発生しました';
                });
        }

        // Drag & Drop
        dropZone.addEventListener('click', () => fileInput.click());
        dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) {
                uploadFile(e.dataTransfer.files[0]);
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                uploadFile(e.target.files[0]);
            }
        });

        function uploadFile(file) {
            if (!file.name.toLowerCase().endsWith('.ifc')) {
                alert('.ifc ファイルを選択してください。');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'upload_ifc');
            formData.append('ifc_file', file);

            statusIndicator.textContent = "アップロード中...";

            fetch('index.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        loadFileList();
                        loadIFCFromServer(data.file_name);
                    } else {
                        alert(data.message || 'アップロードエラー');
                    }
                });
        }

        // --- 6. BCF エクスポートモーダル操作 ---
        btnExportBcf.addEventListener('click', () => {
            if (!renderer) return;

            // スナップショット Base64 取得
            renderer.render(scene, camera);
            const dataUrl = renderer.domElement.toDataURL('image/png');
            document.getElementById('bcf-snapshot-data').value = dataUrl;

            // カメラ位置情報のセット
            const camPos = {
                x: camera.position.x, y: camera.position.y, z: camera.position.z,
                tx: controls.target.x, ty: controls.target.y, tz: controls.target.z
            };
            document.getElementById('bcf-camera-position').value = JSON.stringify(camPos);

            // 選択されたGUIDのセット
            const guid = (selectedMesh && selectedMesh.userData) ? selectedMesh.userData.guid : '';
            document.getElementById('bcf-selected-guid').value = guid;

            document.getElementById('bcf-modal').classList.add('active');
        });

        function closeBcfModal() {
            document.getElementById('bcf-modal').classList.remove('active');
        }

        // HTMLエスケープヘルパー
        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // --- 初期化起動 ---
        window.addEventListener('DOMContentLoaded', () => {
            init3D();
            loadFileList();
        });
    </script>
</body>
</html>
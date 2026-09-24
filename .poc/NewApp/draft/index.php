<?php
declare(strict_types=1);

namespace jacic\gojacic\newapp;

/**
 * アンケート業務運営アプリ
 *
 * 本番実行環境：
 * Apache 2.4 / PHP 8.4・8.5
 * データベース不使用
 * HTML / CSS / JavaScript / PHP 1ファイル
 * 永続データはアプリルート配下のJSON
 *
 * 第1部分：
 * PHP共通処理
 * セッション
 * CSRF
 * JSON入出力基盤
 * セキュリティヘッダー
 * HTML開始～</head>
 */

/* =========================================================
 * 基本設定
 * ========================================================= */

const APP_SESSION_KEY = 'jacic_gojacic_newapp';
const DATA_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const SETTINGS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'settings.json';
const CUSTOMERS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'customers.json';
const SURVEYS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'surveys.json';
const RESPONSES_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'responses.json';
const MAIL_LOGS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'mail_logs.json';

date_default_timezone_set('Asia/Tokyo');

/* =========================================================
 * セキュリティヘッダー
 * ========================================================= */

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

/* =========================================================
 * セッション
 * ========================================================= */

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
]);

if (!isset($_SESSION[APP_SESSION_KEY])) {
    $_SESSION[APP_SESSION_KEY] = [];
}

if (
    !isset($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
    !is_string($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
    strlen($_SESSION[APP_SESSION_KEY]['csrf_token']) !== 64
) {
    $_SESSION[APP_SESSION_KEY]['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================================
 * HTMLエスケープ
 * ========================================================= */

/**
 * 安全な文字列エスケープ
 */
function h(?string $str): string
{
    return htmlspecialchars(
        $str ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* =========================================================
 * 現在日時
 * ========================================================= */

function current_datetime(): string
{
    return date('c');
}

/* =========================================================
 * JSONレスポンス
 * ========================================================= */

/**
 * JSON APIレスポンスを返して必ず処理を終了する。
 *
 * APIレスポンスには認証情報・CSRF・セッション情報を含めない。
 */
function json_response(
    bool $success,
    string $message,
    array $data = [],
    array $errors = [],
    int $status = 200
): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    $response = [
        'success' => $success,
        'message' => $message
    ];

    if ($data !== []) {
        $response['data'] = $data;
    }

    if ($errors !== []) {
        $response['errors'] = $errors;
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/* =========================================================
 * 安全なレスポンスヘッダー取得
 * ========================================================= */

/**
 * PHP 8.4 / 8.5対応。
 *
 * $http_response_header は直接参照しない。
 */
function get_safe_response_headers(): array
{
    return http_get_last_response_headers() ?? [];
}

/* =========================================================
 * リクエスト判定
 * ========================================================= */

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function requested_action(): string
{
    $action = $_GET['action'] ?? '';

    if (!is_string($action)) {
        return '';
    }

    return trim($action);
}

/* =========================================================
 * JSONリクエスト本文
 * ========================================================= */

function read_json_request(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        json_response(
            false,
            '入力内容を確認してください。',
            [],
            ['request' => 'JSON形式のデータを指定してください。'],
            400
        );
    }

    return $decoded;
}

/* =========================================================
 * CSRF
 * ========================================================= */

function csrf_token(): string
{
    return $_SESSION[APP_SESSION_KEY]['csrf_token'];
}

function validate_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($token)) {
        $token = '';
    }

    if (
        $token === '' ||
        !hash_equals(csrf_token(), $token)
    ) {
        json_response(
            false,
            'セキュリティ確認に失敗しました。画面を再読み込みして再度お試しください。',
            [],
            [],
            403
        );
    }
}

/* =========================================================
 * アプリルートのJSON保存領域
 * ========================================================= */

function ensure_data_directory(): void
{
    if (is_dir(DATA_DIRECTORY)) {
        return;
    }

    if (!mkdir(DATA_DIRECTORY, 0700, true) && !is_dir(DATA_DIRECTORY)) {
        json_response(
            false,
            'データ保存領域を準備できませんでした。管理者へ確認してください。',
            [],
            [],
            500
        );
    }
}

/* =========================================================
 * JSONファイル初期値
 * ========================================================= */

function default_settings(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'mail' => [
            'smtp_server' => '',
            'smtp_port' => 587,
            'connection_type' => 'tls',
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'configured' => false,
            'tested_at' => null
        ],
        'kintone' => [
            'domain' => '',
            'login_name' => '',
            'password' => '',
            'customer_app_id' => '',
            'customer_id_field' => '',
            'customer_name_field' => '',
            'customer_email_field' => '',
            'proxy_host' => '',
            'proxy_port' => '',
            'configured' => false,
            'connection_status' => 'not_configured',
            'tested_at' => null
        ]
    ];
}

function default_customers(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'source' => 'kintone',
        'count' => 0,
        'customers' => []
    ];
}

function default_surveys(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'surveys' => []
    ];
}

function default_responses(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'responses' => []
    ];
}

function default_mail_logs(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'logs' => []
    ];
}

/* =========================================================
 * JSONファイル読み込み
 * ========================================================= */

function read_json_file(
    string $file,
    array $default
): array {
    ensure_data_directory();

    if (!file_exists($file)) {
        return $default;
    }

    $contents = file_get_contents($file);

    if ($contents === false) {
        json_response(
            false,
            'データを読み込めませんでした。管理者へ確認してください。',
            [],
            [],
            500
        );
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded)) {
        json_response(
            false,
            'データを読み込めませんでした。管理者へ確認してください。',
            [],
            [],
            500
        );
    }

    return $decoded;
}

/* =========================================================
 * JSONファイル保存
 * ========================================================= */

function write_json_file(
    string $file,
    array $data
): void {
    ensure_data_directory();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    $directory = dirname($file);

    $temporary = tempnam(
        $directory,
        'newapp_'
    );

    if ($temporary === false) {
        json_response(
            false,
            'データを保存できませんでした。管理者へ確認してください。',
            [],
            [],
            500
        );
    }

    $handle = fopen($temporary, 'wb');

    if ($handle === false) {
        @unlink($temporary);

        json_response(
            false,
            'データを保存できませんでした。管理者へ確認してください。',
            [],
            [],
            500
        );
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new \RuntimeException('lock');
        }

        $written = fwrite($handle, $json);

        if ($written === false || $written !== strlen($json)) {
            throw new \RuntimeException('write');
        }

        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        if (!rename($temporary, $file)) {
            @unlink($temporary);

            json_response(
                false,
                'データを保存できませんでした。管理者へ確認してください。',
                [],
                [],
                500
            );
        }
    } catch (\Throwable $e) {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        @unlink($temporary);

        json_response(
            false,
            'データを保存できませんでした。管理者へ確認してください。',
            [],
            [],
            500
        );
    }

    /*
     * 保存後に再読み込みしてJSONとして正常に読めることを確認する。
     */
    $verify = file_get_contents($file);

    if ($verify === false) {
        json_response(
            false,
            '保存したデータを確認できませんでした。管理者へ確認してください。',
            [],
            [],
            500
        );
    }

    $verifiedData = json_decode($verify, true);

    if (!is_array($verifiedData)) {
        json_response(
            false,
            '保存したデータを確認できませんでした。管理者へ確認してください。',
            [],
            [],
            500
        );
    }
}

/* =========================================================
 * 設定データ
 * ========================================================= */

function load_settings(): array
{
    $settings = read_json_file(
        SETTINGS_FILE,
        default_settings()
    );

    if (!isset($settings['version'])) {
        json_response(
            false,
            '設定データを読み込めませんでした。管理者へ確認してください。',
            [],
            [],
            500
        );
    }

    return $settings;
}

/* =========================================================
 * 顧客データ
 * ========================================================= */

function load_customer_data(): array
{
    return read_json_file(
        CUSTOMERS_FILE,
        default_customers()
    );
}

/* =========================================================
 * アンケートデータ
 * ========================================================= */

function load_survey_data(): array
{
    return read_json_file(
        SURVEYS_FILE,
        default_surveys()
    );
}

/* =========================================================
 * 回答データ
 * ========================================================= */

function load_response_data(): array
{
    return read_json_file(
        RESPONSES_FILE,
        default_responses()
    );
}

/* =========================================================
 * メール送信履歴
 * ========================================================= */

function load_mail_log_data(): array
{
    return read_json_file(
        MAIL_LOGS_FILE,
        default_mail_logs()
    );
}

/* =========================================================
 * 初期データファイルの準備
 * ========================================================= */

function initialize_data_files(): void
{
    ensure_data_directory();

    if (!file_exists(SETTINGS_FILE)) {
        write_json_file(
            SETTINGS_FILE,
            default_settings()
        );
    }

    if (!file_exists(CUSTOMERS_FILE)) {
        write_json_file(
            CUSTOMERS_FILE,
            default_customers()
        );
    }

    if (!file_exists(SURVEYS_FILE)) {
        write_json_file(
            SURVEYS_FILE,
            default_surveys()
        );
    }

    if (!file_exists(RESPONSES_FILE)) {
        write_json_file(
            RESPONSES_FILE,
            default_responses()
        );
    }

    if (!file_exists(MAIL_LOGS_FILE)) {
        write_json_file(
            MAIL_LOGS_FILE,
            default_mail_logs()
        );
    }
}

/* =========================================================
 * アプリ自身のAPI URL
 * ========================================================= */

/**
 * JavaScriptへ本番環境固有のホスト名を渡さない。
 *
 * index.php自身を相対URLとして使用する。
 */
function application_api_path(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

    if (!is_string($scriptName) || $scriptName === '') {
        return 'index.php';
    }

    return $scriptName;
}

/* =========================================================
 * 初期化
 * ========================================================= */

initialize_data_files();

/* =========================================================
 * API処理はHTML出力より前に行う
 * ========================================================= */

$action = requested_action();

if ($action !== '') {
    /*
     * APIはJSONのみを返す。
     * HTMLは一切混在させない。
     */
    if (request_method() === 'POST') {
        validate_csrf();
    }

    /*
     * API本体は次の部分で実装する。
     *
     * GET：
     *   load_settings
     *   load_customers
     *   load_surveys
     *   load_survey
     *   load_mail_logs
     *   load_public_survey
     *   aggregate_responses
     *
     * POST：
     *   save_mail_settings
     *   test_mail_settings
     *   save_kintone_settings
     *   test_kintone_connection
     *   refresh_customers
     *   save_survey
     *   delete_survey
     *   publish_survey
     *   close_survey
     *   prepare_survey_send
     *   send_survey
     *   prepare_resend
     *   resend_survey
     *   save_response
     *
     * 次の部分で各APIを実装する。
     */
}

/* =========================================================
 * HTML開始
 * ========================================================= */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title>アンケート業務運営</title>

<style>
/* =========================================================
 * 基本
 * ========================================================= */

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    min-height: 100%;
}

body {
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Yu Gothic",
        "YuGothic",
        Meiryo,
        sans-serif;
    color: #263238;
    background: #f4f6f8;
    font-size: 14px;
    line-height: 1.6;
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

button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

input,
textarea,
select {
    max-width: 100%;
}

.hidden {
    display: none !important;
}

/* =========================================================
 * ローディング
 * ========================================================= */

.loading-spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    margin-right: 6px;
    border: 2px solid rgba(255, 255, 255, 0.45);
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: newapp-spin 0.7s linear infinite;
    vertical-align: -2px;
}

.btn.loading .loading-spinner {
    display: inline-block;
}

.btn:not(.loading) .loading-spinner {
    display: none;
}

@keyframes newapp-spin {
    to {
        transform: rotate(360deg);
    }
}

/* =========================================================
 * 上部メニューバー
 * モックの構成を維持
 * ========================================================= */

.topbar {
    min-height: 60px;
    background: #1f3a5f;
    color: #ffffff;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 30px;
}

.logo {
    font-size: 18px;
    font-weight: bold;
    white-space: nowrap;
}

.main-nav {
    display: flex;
    height: 60px;
    align-items: stretch;
    gap: 2px;
}

.main-nav button {
    height: 60px;
    padding: 0 17px;
    color: #dce7f3;
    background: transparent;
    border: 0;
    white-space: nowrap;
}

.main-nav button:hover,
.main-nav button.active {
    background: #31557f;
    color: #ffffff;
}

/* =========================================================
 * 全体レイアウト
 * ========================================================= */

.app {
    max-width: 1440px;
    margin: 0 auto;
    padding: 24px;
}

.page {
    width: 100%;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 20px;
}

.page-header h1 {
    margin: 0;
    font-size: 25px;
    line-height: 1.35;
}

.subtext {
    color: #718096;
    font-size: 13px;
    margin-top: 5px;
}

/* =========================================================
 * ボタン
 * ========================================================= */

.btn {
    border: 1px solid #cbd5e0;
    background: #ffffff;
    color: #34495e;
    border-radius: 5px;
    padding: 8px 15px;
    min-height: 38px;
}

.btn:hover {
    background: #f7fafc;
}

.btn-primary {
    background: #2878c8;
    border-color: #2878c8;
    color: #ffffff;
}

.btn-primary:hover {
    background: #2068ad;
    border-color: #2068ad;
}

.btn-success {
    background: #2f855a;
    border-color: #2f855a;
    color: #ffffff;
}

.btn-success:hover {
    background: #276749;
    border-color: #276749;
}

.btn-danger {
    border-color: #e05a5a;
    color: #c53f3f;
    background: #ffffff;
}

.btn-danger:hover {
    background: #fff5f5;
}

.btn-small {
    min-height: 32px;
    padding: 5px 10px;
    font-size: 12px;
}

.link-button {
    border: 0;
    background: none;
    padding: 0;
    color: #2878c8;
    cursor: pointer;
    text-align: left;
}

.link-button:hover {
    text-decoration: underline;
}

/* =========================================================
 * カード
 * ========================================================= */

.card {
    background: #ffffff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 20px;
    margin-bottom: 18px;
}

.card-title {
    font-size: 17px;
    font-weight: bold;
    margin-bottom: 15px;
}

.card-title small {
    font-size: 12px;
    color: #718096;
    font-weight: normal;
    margin-left: 8px;
}

/* =========================================================
 * 通知
 * ========================================================= */

.notice {
    background: #eef6ff;
    border: 1px solid #c9e1f8;
    color: #315a7d;
    border-radius: 6px;
    padding: 12px 14px;
    margin-bottom: 18px;
}

.notice.success {
    background: #eefaf3;
    border-color: #bde3ca;
    color: #276749;
}

.notice.warning {
    background: #fff8e8;
    border-color: #f1d99b;
    color: #856404;
}

.notice.error {
    background: #fff5f5;
    border-color: #f0c2c2;
    color: #a33a3a;
}

/* =========================================================
 * テーブル
 * ========================================================= */

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 12px 10px;
    border-bottom: 1px solid #e6ebef;
    text-align: left;
    vertical-align: middle;
    font-size: 13px;
}

.table th {
    background: #f8fafc;
    color: #52606d;
    font-weight: bold;
}

.table tbody tr:hover td {
    background: #fbfdff;
}

.empty {
    text-align: center !important;
    color: #8a98a5;
    padding: 40px !important;
}

/* =========================================================
 * ステータス
 * ========================================================= */

.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    line-height: 1.4;
    white-space: nowrap;
}

.badge-draft {
    color: #5f6b76;
    background: #edf0f2;
}

.badge-open {
    color: #276749;
    background: #dff5e7;
}

.badge-closed {
    color: #7b3f3f;
    background: #f6dddd;
}

.badge-warn {
    color: #856404;
    background: #fff0c7;
}

/* =========================================================
 * フォーム
 * ========================================================= */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.field {
    margin-bottom: 16px;
}

.field:last-child {
    margin-bottom: 0;
}

.field label {
    display: block;
    margin-bottom: 6px;
    color: #52606d;
    font-size: 13px;
    font-weight: bold;
}

.field input,
.field textarea,
.field select {
    width: 100%;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 8px 10px;
    background: #ffffff;
    color: #263238;
}

.field textarea {
    min-height: 110px;
    resize: vertical;
}

.field input:focus,
.field textarea:focus,
.field select:focus {
    outline: none;
    border-color: #2878c8;
    box-shadow: 0 0 0 2px rgba(40, 120, 200, 0.12);
}

.field-error {
    border-color: #d9534f !important;
    background: #fff8f8 !important;
}

.error-text {
    color: #c53f3f;
    font-size: 12px;
    margin-top: 4px;
}

/* =========================================================
 * 操作エリア
 * ========================================================= */

.action-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.action-row.right {
    justify-content: flex-end;
}

.action-row.space-between {
    justify-content: space-between;
}

/* =========================================================
 * アンケート編集
 * ========================================================= */

.groups {
    width: 100%;
}

.group-card {
    background: #ffffff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    margin-bottom: 16px;
    overflow: hidden;
}

.group-card.dragging {
    opacity: 0.45;
}

.group-card.drag-over {
    border-color: #2878c8;
    box-shadow: 0 0 0 2px rgba(40, 120, 200, 0.12);
}

.group-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    background: #f8fafc;
    border-bottom: 1px solid #e6ebef;
}

.group-title {
    flex: 1;
}

.group-title input {
    width: 100%;
    border: 1px solid transparent;
    background: transparent;
    border-radius: 4px;
    padding: 7px 8px;
    font-weight: bold;
}

.group-title input:hover {
    border-color: #d8e0e7;
    background: #ffffff;
}

.group-title input:focus {
    outline: none;
    border-color: #cbd5e0;
    background: #ffffff;
}

.drag-handle {
    color: #9aa7b3;
    cursor: grab;
    font-size: 18px;
    user-select: none;
}

.group-actions {
    display: flex;
    gap: 5px;
}

/* =========================================================
 * 質問
 * ========================================================= */

.question-card {
    padding: 15px;
    border-bottom: 1px solid #e6ebef;
    background: #ffffff;
}

.question-card:last-child {
    border-bottom: 0;
}

.question-card.dragging {
    opacity: 0.45;
}

.question-card.drag-over {
    border-top: 3px solid #2878c8;
}

.question-head {
    display: flex;
    align-items: center;
    gap: 8px;
}

.question-number {
    width: 58px;
    color: #2878c8;
    font-weight: bold;
    flex: none;
}

.question-title {
    flex: 1;
}

.question-title input {
    width: 100%;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 8px;
}

.question-tools {
    display: flex;
    gap: 6px;
    align-items: center;
}

.question-tools select {
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 6px;
}

.question-meta {
    display: flex;
    gap: 18px;
    align-items: center;
    margin-top: 10px;
    padding-left: 66px;
    color: #657786;
    font-size: 13px;
}

.question-options {
    margin-top: 12px;
    padding-left: 66px;
}

.option-row {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 7px;
}

.option-row input {
    flex: 1;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 7px;
}

.branch-select {
    width: 245px !important;
    flex: none;
}

.branch-label {
    font-size: 11px;
    color: #718096;
    width: 50px;
    flex: none;
}

.invalid-branch {
    border-color: #d9534f !important;
    background: #fff5f5 !important;
}

.add-question-area {
    padding: 12px 14px;
    background: #fafcfd;
    border-top: 1px solid #edf1f4;
}

.add-group-area {
    text-align: center;
    margin-top: 8px;
}

/* =========================================================
 * 個別アンケート
 * ========================================================= */

.survey-tabs {
    display: flex;
    gap: 2px;
    border-bottom: 1px solid #dfe5eb;
    margin-bottom: 18px;
}

.survey-tabs button {
    border: 0;
    background: transparent;
    color: #657786;
    padding: 10px 18px;
    border-bottom: 3px solid transparent;
}

.survey-tabs button:hover,
.survey-tabs button.active {
    color: #2878c8;
    border-bottom-color: #2878c8;
    background: #f8fafc;
}

/* =========================================================
 * 内容確認
 * ========================================================= */

.preview-group {
    margin-bottom: 20px;
}

.preview-group-title {
    font-size: 17px;
    font-weight: bold;
    padding-bottom: 8px;
    border-bottom: 2px solid #dfe5eb;
    margin-bottom: 8px;
}

.preview-question {
    padding: 12px 4px;
    border-bottom: 1px solid #edf1f4;
}

.preview-question-title {
    font-weight: bold;
    margin-bottom: 5px;
}

.preview-option {
    padding-left: 12px;
    color: #52606d;
}

/* =========================================================
 * 送信画面
 * ========================================================= */

.send-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(300px, 0.8fr);
    gap: 18px;
}

.customer-search {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
}

.customer-search input {
    flex: 1;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    padding: 8px 10px;
}

.customer-list {
    max-height: 430px;
    overflow-y: auto;
    border: 1px solid #dfe5eb;
    border-radius: 5px;
}

.customer-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-bottom: 1px solid #edf1f4;
}

.customer-row:last-child {
    border-bottom: 0;
}

.customer-info {
    flex: 1;
    min-width: 0;
}

.customer-name {
    font-weight: bold;
}

.customer-email {
    color: #718096;
    font-size: 12px;
}

/* =========================================================
 * 回答状況
 * ========================================================= */

.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.stat-card {
    background: #ffffff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 17px;
}

.stat-label {
    color: #718096;
    font-size: 12px;
}

.stat-value {
    margin-top: 3px;
    font-size: 27px;
    font-weight: bold;
    color: #263238;
}

.chart-area {
    min-height: 220px;
    display: flex;
    align-items: flex-end;
    gap: 10px;
    padding: 20px 10px 10px;
    border: 1px solid #edf1f4;
    background: #fafcfd;
    border-radius: 5px;
}

/* =========================================================
 * 回答結果
 * ========================================================= */

.result-layout {
    display: grid;
    grid-template-columns: 270px minmax(0, 1fr);
    gap: 18px;
}

.result-question-list {
    border: 1px solid #dfe5eb;
    border-radius: 6px;
    overflow: hidden;
    background: #ffffff;
}

.result-question-button {
    display: block;
    width: 100%;
    border: 0;
    border-bottom: 1px solid #edf1f4;
    background: #ffffff;
    text-align: left;
    padding: 10px 12px;
    color: #52606d;
}

.result-question-button:hover,
.result-question-button.active {
    background: #eef6ff;
    color: #2878c8;
}

.result-content {
    min-width: 0;
}

.result-bar-row {
    margin-bottom: 13px;
}

.result-bar-label {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 4px;
}

.result-bar {
    height: 18px;
    border-radius: 3px;
    overflow: hidden;
    background: #edf1f4;
}

.result-bar-inner {
    height: 100%;
    background: #2878c8;
}

/* =========================================================
 * 設定
 * ========================================================= */

.settings-section {
    margin-bottom: 22px;
}

.settings-section:last-child {
    margin-bottom: 0;
}

.settings-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    margin-bottom: 12px;
}

.status-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #9aa7b3;
}

.status-dot.ready {
    background: #2f855a;
}

.status-dot.error {
    background: #d9534f;
}

.status-dot.warning {
    background: #d69e2e;
}

/* =========================================================
 * 回答者画面
 * ========================================================= */

.respondent-page {
    max-width: 820px;
    margin: 0 auto;
    padding: 30px 20px 60px;
}

.respondent-header {
    background: #ffffff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 22px;
    margin-bottom: 18px;
}

.respondent-question {
    background: #ffffff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 20px;
    margin-bottom: 16px;
}

.respondent-question-title {
    font-weight: bold;
    margin-bottom: 13px;
}

.respondent-option {
    margin-bottom: 8px;
}

.respondent-option label {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.respondent-complete {
    background: #ffffff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 35px 22px;
    text-align: center;
}

/* =========================================================
 * メッセージ
 * ========================================================= */

.toast-container {
    position: fixed;
    top: 76px;
    right: 20px;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: min(380px, calc(100vw - 40px));
}

.toast {
    background: #263238;
    color: #ffffff;
    border-radius: 6px;
    padding: 11px 14px;
    box-shadow: 0 5px 18px rgba(0, 0, 0, 0.16);
}

.toast.success {
    background: #2f855a;
}

.toast.error {
    background: #c53f3f;
}

.toast.warning {
    background: #a66b00;
}

/* =========================================================
 * 確認ダイアログ
 * ========================================================= */

.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(25, 35, 45, 0.48);
}

.modal {
    width: min(700px, 100%);
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.25);
}

.modal-header {
    padding: 17px 20px;
    border-bottom: 1px solid #e6ebef;
    font-weight: bold;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 13px 20px;
    border-top: 1px solid #e6ebef;
}

/* =========================================================
 * レスポンシブ
 * ========================================================= */

@media (max-width: 980px) {
    .topbar {
        gap: 14px;
        padding: 0 14px;
        overflow-x: auto;
    }

    .main-nav button {
        padding: 0 11px;
    }

    .app {
        padding: 18px;
    }

    .send-layout {
        grid-template-columns: 1fr;
    }

    .result-layout {
        grid-template-columns: 1fr;
    }

    .dashboard-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 680px) {
    .topbar {
        min-height: auto;
        align-items: flex-start;
        flex-direction: column;
        gap: 0;
        padding: 10px 12px 0;
    }

    .main-nav {
        width: 100%;
        height: auto;
        overflow-x: auto;
    }

    .main-nav button {
        height: 44px;
        flex: 0 0 auto;
    }

    .page-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .dashboard-grid {
        grid-template-columns: 1fr 1fr;
    }

    .question-head {
        flex-wrap: wrap;
    }

    .question-title {
        order: 2;
        flex-basis: 100%;
    }

    .question-meta,
    .question-options {
        padding-left: 0;
    }

    .option-row {
        flex-wrap: wrap;
    }

    .branch-select {
        width: 100% !important;
    }
}
</style>
</head>

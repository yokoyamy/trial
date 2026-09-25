<?php
declare(strict_types=1);

namespace Yokoyamy\SurveyOperationApp;

use RuntimeException;
use Throwable;

const APP_SESSION_KEY = 'yokoyamy_survey_operation_app';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const DATA_FILES = [
    'settings' => DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json',
    'surveys' => DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json',
    'groups' => DATA_DIR . DIRECTORY_SEPARATOR . 'groups.json',
    'questions' => DATA_DIR . DIRECTORY_SEPARATOR . 'questions.json',
    'options' => DATA_DIR . DIRECTORY_SEPARATOR . 'options.json',
    'customers' => DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json',
    'recipients' => DATA_DIR . DIRECTORY_SEPARATOR . 'recipients.json',
    'mail_logs' => DATA_DIR . DIRECTORY_SEPARATOR . 'mail_logs.json',
    'responses' => DATA_DIR . DIRECTORY_SEPARATOR . 'responses.json',
    'response_answers' => DATA_DIR . DIRECTORY_SEPARATOR . 'response_answers.json',
];

/**
 * PHP 8.5対応・NULL安全エスケープ
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * JSON API等で使用する安全なレスポンスヘッダー取得。
 */
function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        return http_get_last_response_headers() ?? [];
    }

    return [];
}

/**
 * アプリ専用セッションを開始。
 */
function start_app_session(): void
{
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);

    if (!isset($_SESSION[APP_SESSION_KEY]) || !is_array($_SESSION[APP_SESSION_KEY])) {
        $_SESSION[APP_SESSION_KEY] = [];
    }

    if (
        !isset($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
        !is_string($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
        $_SESSION[APP_SESSION_KEY]['csrf_token'] === ''
    ) {
        $_SESSION[APP_SESSION_KEY]['csrf_token'] = bin2hex(random_bytes(32));
    }
}

start_app_session();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function csrf_token(): string
{
    $token = $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '';

    if (!is_string($token) || $token === '') {
        throw new RuntimeException('CSRFトークンを取得できません。');
    }

    return $token;
}

function verify_csrf(): void
{
    $requestToken = '';

    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $requestToken = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (isset($_POST['csrf_token'])) {
        $requestToken = (string)$_POST['csrf_token'];
    }

    if (
        $requestToken === '' ||
        !hash_equals(csrf_token(), $requestToken)
    ) {
        json_response([
            'success' => false,
            'message' => 'セキュリティ確認に失敗しました。画面を再読み込みしてください。',
        ], 403);
    }
}

function json_response(array $response, int $statusCode = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function request_json(): array
{
    $body = file_get_contents('php://input');

    if ($body === false || trim($body) === '') {
        return [];
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        json_response([
            'success' => false,
            'message' => '送信されたデータを読み込めませんでした。',
        ], 400);
    }

    return $decoded;
}

function ensure_data_directory(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存フォルダを作成できません。');
        }
    }
}

function read_json_file(string $key): array
{
    if (!isset(DATA_FILES[$key])) {
        throw new RuntimeException('指定されたデータファイルは存在しません。');
    }

    ensure_data_directory();

    $path = DATA_FILES[$key];

    if (!file_exists($path)) {
        if (file_put_contents($path, "[]", LOCK_EX) === false) {
            throw new RuntimeException('データファイルを初期化できません。');
        }

        return [];
    }

    $content = file_get_contents($path);

    if ($content === false) {
        throw new RuntimeException('データファイルを読み込めません。');
    }

    if (trim($content) === '') {
        return [];
    }

    $data = json_decode($content, true);

    if (!is_array($data)) {
        throw new RuntimeException('データファイルの形式が正しくありません。');
    }

    return $data;
}

function write_json_file(string $key, array $data): void
{
    if (!isset(DATA_FILES[$key])) {
        throw new RuntimeException('指定されたデータファイルは存在しません。');
    }

    ensure_data_directory();

    $json = json_encode(
        array_values($data),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('データをJSON形式へ変換できません。');
    }

    $tmp = DATA_FILES[$key] . '.tmp';

    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('一時データファイルを保存できません。');
    }

    if (!rename($tmp, DATA_FILES[$key])) {
        @unlink($tmp);
        throw new RuntimeException('データファイルを更新できません。');
    }
}

function new_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(12));
}

function now_iso(): string
{
    return (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
}

function find_record(array $records, string $id): ?array
{
    foreach ($records as $record) {
        if (is_array($record) && (string)($record['id'] ?? '') === $id) {
            return $record;
        }
    }

    return null;
}

function find_record_index(array $records, string $id): int
{
    foreach ($records as $index => $record) {
        if (is_array($record) && (string)($record['id'] ?? '') === $id) {
            return (int)$index;
        }
    }

    return -1;
}

function sanitize_error_message(string $message): string
{
    $sensitivePatterns = [
        '/password\s*[:=]\s*[^\s,;]+/iu',
        '/passwd\s*[:=]\s*[^\s,;]+/iu',
        '/authorization\s*[:=]\s*[^\s,;]+/iu',
        '/x-cybozu-authorization\s*[:=]\s*[^\s,;]+/iu',
    ];

    foreach ($sensitivePatterns as $pattern) {
        $message = preg_replace($pattern, '$1[REDACTED]', $message) ?? $message;
    }

    return $message;
}

function default_settings(): array
{
    return [
        'smtp' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'TLS',
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'tested_at' => '',
            'test_status' => '未確認',
        ],
        'kintone' => [
            'domain' => '',
            'app_id' => '',
            'login_name' => '',
            'password' => '',
            'customer_name_field' => '',
            'email_field' => '',
            'proxy_host_port' => '',
            'tested_at' => '',
            'test_status' => '未確認',
        ],
    ];
}

function load_settings(): array
{
    $data = read_json_file('settings');

    if ($data === []) {
        $settings = default_settings();
        write_json_file('settings', [$settings]);

        return $settings;
    }

    $settings = $data[0] ?? [];

    if (!is_array($settings)) {
        return default_settings();
    }

    $defaults = default_settings();

    $settings['smtp'] = array_merge(
        $defaults['smtp'],
        is_array($settings['smtp'] ?? null) ? $settings['smtp'] : []
    );

    $settings['kintone'] = array_merge(
        $defaults['kintone'],
        is_array($settings['kintone'] ?? null) ? $settings['kintone'] : []
    );

    return $settings;
}

function save_settings(array $settings): void
{
    write_json_file('settings', [$settings]);
}

function public_settings(array $settings): array
{
    $result = $settings;

    if (isset($result['smtp']['password'])) {
        $result['smtp']['password_set'] = $result['smtp']['password'] !== '';
        unset($result['smtp']['password']);
    }

    if (isset($result['kintone']['password'])) {
        $result['kintone']['password_set'] = $result['kintone']['password'] !== '';
        unset($result['kintone']['password']);
    }

    return $result;
}

/**
 * kintone URLの成形。
 */
function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain) ?? '';
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain) ?? '';
    $domain = rtrim($domain, '/');

    if ($domain === '') {
        throw new RuntimeException('kintoneのサブドメインが設定されていません。');
    }

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * X-Cybozu-Authorizationヘッダーを生成。
 */
function make_cybozu_auth_header(string $loginName, string $password): string
{
    $loginName = trim($loginName);
    $password = trim($password);

    if ($loginName === '' || $password === '') {
        throw new RuntimeException('kintoneのログイン情報が設定されていません。');
    }

    return 'X-Cybozu-Authorization: ' .
        base64_encode($loginName . ':' . $password);
}

/**
 * プロキシ設定を正規化。
 */
function normalize_proxy(string $proxy): string
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return '';
    }

    if (!preg_match('/^[^:\s\/]+:\d{1,5}$/', $proxy)) {
        throw new RuntimeException(
            'プロキシは「ホスト名:ポート番号」の形式で入力してください。'
        );
    }

    [$host, $port] = explode(':', $proxy, 2);

    $portNumber = (int)$port;

    if ($portNumber < 1 || $portNumber > 65535) {
        throw new RuntimeException('プロキシのポート番号が正しくありません。');
    }

    return $host . ':' . $portNumber;
}

/**
 * kintone REST API通信。
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
    ];

    if ($method !== 'GET' && $payload !== null) {
        if (is_array($payload)) {
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($encoded === false) {
                throw new RuntimeException('kintone送信用データを作成できません。');
            }

            $httpOptions['content'] = $encoded;
        } else {
            $httpOptions['content'] = (string)$payload;
        }
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ];

    $proxy = normalize_proxy((string)($config['proxy_host_port'] ?? ''));

    if ($proxy !== '') {
        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = get_safe_response_headers();

    $statusCode = 0;

    foreach ($responseHeaders as $headerLine) {
        if (
            preg_match(
                '/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i',
                (string)$headerLine,
                $matches
            )
        ) {
            $statusCode = (int)$matches[1];
        }
    }

    if ($statusCode === 0 && $responseBody !== false) {
        $statusCode = 200;
    }

    $decoded = json_decode($responseBody ?? '', true);

    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'success' => true,
            'status' => $statusCode,
            'data' => is_array($decoded) ? $decoded : [],
        ];
    }

    $message = 'kintone API通信に失敗しました。';

    if (is_array($decoded)) {
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            $message = $decoded['message'];
        }

        if (
            isset($decoded['code']) &&
            is_string($decoded['code']) &&
            $decoded['code'] !== ''
        ) {
            $message .= ' [' . $decoded['code'] . ']';
        }

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $details = [];

            foreach ($decoded['errors'] as $field => $error) {
                if (!is_array($error)) {
                    continue;
                }

                $messages = $error['messages'] ?? [];

                if (is_array($messages)) {
                    foreach ($messages as $errorMessage) {
                        if (is_string($errorMessage)) {
                            $details[] = (string)$field . ': ' . $errorMessage;
                        }
                    }
                }
            }

            if ($details !== []) {
                $message .= ' ' . implode(' / ', $details);
            }
        }
    }

    if ($responseBody === false && $message === 'kintone API通信に失敗しました。') {
        $message .= ' 通信自体に失敗しました。';
    }

    return [
        'success' => false,
        'status' => $statusCode,
        'message' => sanitize_error_message($message),
        'data' => is_array($decoded) ? $decoded : [],
    ];
}

function validate_kintone_settings(array $settings): void
{
    $required = [
        'domain' => 'kintoneサブドメイン',
        'app_id' => '顧客管理アプリID',
        'login_name' => 'ログイン名',
        'customer_name_field' => '顧客名フィールドコード',
        'email_field' => 'メールアドレスフィールドコード',
    ];

    foreach ($required as $key => $label) {
        if (trim((string)($settings[$key] ?? '')) === '') {
            throw new RuntimeException($label . 'を入力してください。');
        }
    }

    if (!preg_match('/^\d+$/', (string)$settings['app_id'])) {
        throw new RuntimeException('顧客管理アプリIDは数字で入力してください。');
    }

    if (trim((string)($settings['password'] ?? '')) === '') {
        throw new RuntimeException('kintoneパスワードが設定されていません。');
    }

    normalize_proxy((string)($settings['proxy_host_port'] ?? ''));
}

function kintone_test_connection(array $settings): array
{
    validate_kintone_settings($settings);

    $headers = [
        make_cybozu_auth_header(
            (string)$settings['login_name'],
            (string)$settings['password']
        ),
        'Accept: application/json',
    ];

    $appId = (int)$settings['app_id'];

    $url = kintone_build_url(
        (string)$settings['domain'],
        '/k/v1/app.json?id=' . rawurlencode((string)$appId)
    );

    $result = kintone_api_request(
        'GET',
        $url,
        $headers,
        null,
        $settings
    );

    if (!$result['success']) {
        return $result;
    }

    return [
        'success' => true,
        'message' => 'kintoneへの接続とアプリ確認に成功しました。',
        'data' => $result['data'],
    ];
}

function kintone_fetch_customers(array $settings): array
{
    validate_kintone_settings($settings);

    $headers = [
        make_cybozu_auth_header(
            (string)$settings['login_name'],
            (string)$settings['password']
        ),
        'Accept: application/json',
    ];

    $appId = (int)$settings['app_id'];

    $params = [
        'app' => $appId,
        'query' => 'order by $id asc',
        'totalCount' => 'false',
    ];

    $url = kintone_build_url(
        (string)$settings['domain'],
        '/k/v1/records.json?' .
        http_build_query($params, '', '&', PHP_QUERY_RFC3986)
    );

    $result = kintone_api_request(
        'GET',
        $url,
        $headers,
        null,
        $settings
    );

    if (!$result['success']) {
        return $result;
    }

    $records = $result['data']['records'] ?? [];

    if (!is_array($records)) {
        return [
            'success' => false,
            'message' => 'kintoneから顧客データを取得できませんでした。',
        ];
    }

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $nameField = $record[(string)$settings['customer_name_field']] ?? [];
        $emailField = $record[(string)$settings['email_field']] ?? [];
        $idField = $record['$id'] ?? [];

        $name = is_array($nameField)
            ? (string)($nameField['value'] ?? '')
            : '';

        $email = is_array($emailField)
            ? (string)($emailField['value'] ?? '')
            : '';

        $kintoneId = is_array($idField)
            ? (string)($idField['value'] ?? '')
            : '';

        if ($kintoneId === '') {
            continue;
        }

        if ($name === '' && $email === '') {
            continue;
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $customers[] = [
            'id' => 'customer_' . $kintoneId,
            'kintone_id' => $kintoneId,
            'name' => $name,
            'email' => $email,
            'updated_at' => now_iso(),
        ];
    }

    write_json_file('customers', $customers);

    return [
        'success' => true,
        'message' => count($customers) . '件の顧客情報を取得しました。',
        'customers' => $customers,
    ];
}

function survey_records(): array
{
    return read_json_file('surveys');
}

function group_records(): array
{
    return read_json_file('groups');
}

function question_records(): array
{
    return read_json_file('questions');
}

function option_records(): array
{
    return read_json_file('options');
}

function customer_records(): array
{
    return read_json_file('customers');
}

function recipient_records(): array
{
    return read_json_file('recipients');
}

function response_records(): array
{
    return read_json_file('responses');
}

function response_answer_records(): array
{
    return read_json_file('response_answers');
}

function mail_log_records(): array
{
    return read_json_file('mail_logs');
}

function survey_by_id(string $surveyId): ?array
{
    return find_record(survey_records(), $surveyId);
}

function survey_groups(string $surveyId): array
{
    $groups = array_values(
        array_filter(
            group_records(),
            static fn(array $group): bool =>
                (string)($group['survey_id'] ?? '') === $surveyId
        )
    );

    usort(
        $groups,
        static fn(array $a, array $b): int =>
            ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0))
    );

    return $groups;
}

function group_questions(string $groupId): array
{
    $questions = array_values(
        array_filter(
            question_records(),
            static fn(array $question): bool =>
                (string)($question['group_id'] ?? '') === $groupId
        )
    );

    usort(
        $questions,
        static fn(array $a, array $b): int =>
            ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0))
    );

    return $questions;
}

function question_options(string $questionId): array
{
    $options = array_values(
        array_filter(
            option_records(),
            static fn(array $option): bool =>
                (string)($option['question_id'] ?? '') === $questionId
        )
    );

    usort(
        $options,
        static fn(array $a, array $b): int =>
            ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0))
    );

    return $options;
}

function all_survey_questions(string $surveyId): array
{
    $result = [];

    foreach (survey_groups($surveyId) as $group) {
        foreach (group_questions((string)$group['id']) as $question) {
            $question['_group_id'] = (string)$group['id'];
            $result[] = $question;
        }
    }

    return $result;
}

function question_number_map(string $surveyId, string $format): array
{
    $map = [];
    $groups = survey_groups($surveyId);
    $counter = 1;

    foreach ($groups as $groupIndex => $group) {
        $questions = group_questions((string)$group['id']);

        foreach ($questions as $questionIndex => $question) {
            if ($format === 'group') {
                $map[(string)$question['id']] =
                    'Q' . ($groupIndex + 1) . '-' . ($questionIndex + 1);
            } else {
                $map[(string)$question['id']] = 'Q' . $counter;
            }

            $counter++;
        }
    }

    return $map;
}

function validate_survey_structure(
    array $survey,
    array $groups,
    array $questions,
    array $options
): array {
    $errors = [];

    if (trim((string)($survey['name'] ?? '')) === '') {
        $errors[] = 'アンケート名を入力してください。';
    }

    if (
        isset($survey['publish_start'], $survey['publish_end']) &&
        $survey['publish_start'] !== '' &&
        $survey['publish_end'] !== ''
    ) {
        $start = strtotime((string)$survey['publish_start']);
        $end = strtotime((string)$survey['publish_end']);

        if ($start === false || $end === false || $start >= $end) {
            $errors[] = '公開期間の開始日時と終了日時を確認してください。';
        }
    }

    $questionIds = [];

    foreach ($questions as $question) {
        $questionId = (string)($question['id'] ?? '');

        if ($questionId === '') {
            $errors[] = '質問IDが設定されていません。';
            continue;
        }

        $questionIds[$questionId] = true;

        if (trim((string)($question['question_text'] ?? '')) === '') {
            $errors[] = '質問文が未入力の質問があります。';
        }

        $type = (string)($question['answer_type'] ?? '');

        if (!in_array($type, ['text', 'single', 'multiple'], true)) {
            $errors[] = '回答形式が正しくない質問があります。';
        }

        if ($type === 'single' || $type === 'multiple') {
            $questionOptions = array_values(
                array_filter(
                    $options,
                    static fn(array $option): bool =>
                        (string)($option['question_id'] ?? '') === $questionId
                )
            );

            if ($questionOptions === []) {
                $errors[] = '選択式質問には選択肢を1つ以上設定してください。';
            }

            foreach ($questionOptions as $option) {
                if (trim((string)($option['label'] ?? '')) === '') {
                    $errors[] = '選択肢の文言が未入力です。';
                }
            }
        }
    }

    foreach ($options as $option) {
        $questionId = (string)($option['question_id'] ?? '');

        if ($questionId !== '' && !isset($questionIds[$questionId])) {
            $errors[] = '存在しない質問に紐付いた選択肢があります。';
        }

        $nextQuestionId = (string)($option['next_question_id'] ?? '');

        if ($nextQuestionId !== '' && !isset($questionIds[$nextQuestionId])) {
            $errors[] = '存在しない質問を分岐先に指定しています。';
        }
    }

    $questionMap = [];

    foreach ($questions as $question) {
        $questionMap[(string)$question['id']] = $question;
    }

    foreach ($options as $option) {
        $from = (string)($option['question_id'] ?? '');
        $to = (string)($option['next_question_id'] ?? '');

        if ($from === '' || $to === '') {
            continue;
        }

        if ($from === $to) {
            $errors[] = '質問自身を分岐先に指定することはできません。';
            continue;
        }

        $visited = [];
        $current = $to;

        while ($current !== '') {
            if (isset($visited[$current])) {
                $errors[] = '循環する分岐が設定されています。';
                break;
            }

            $visited[$current] = true;

            if (!isset($questionMap[$current])) {
                break;
            }

            $nextOptions = array_values(
                array_filter(
                    $options,
                    static fn(array $candidate): bool =>
                        (string)($candidate['question_id'] ?? '') === $current
                )
            );

            $next = '';

            foreach ($nextOptions as $nextOption) {
                $candidateNext = (string)($nextOption['next_question_id'] ?? '');

                if ($candidateNext !== '') {
                    $next = $candidateNext;
                    break;
                }
            }

            $current = $next;
        }
    }

    return array_values(array_unique($errors));
}

function decorate_survey(array $survey): array
{
    $surveyId = (string)($survey['id'] ?? '');

    $groups = survey_groups($surveyId);
    $questions = all_survey_questions($surveyId);
    $options = option_records();

    $survey['groups'] = [];

    foreach ($groups as $group) {
        $groupId = (string)$group['id'];
        $group['questions'] = [];

        foreach (group_questions($groupId) as $question) {
            $questionId = (string)$question['id'];
            $question['options'] = array_values(
                array_filter(
                    $options,
                    static fn(array $option): bool =>
                        (string)($option['question_id'] ?? '') === $questionId
                )
            );

            usort(
                $question['options'],
                static fn(array $a, array $b): int =>
                    ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0))
            );

            $group['questions'][] = $question;
        }

        $survey['groups'][] = $group;
    }

    $survey['question_number_map'] = question_number_map(
        $surveyId,
        (string)($survey['numbering'] ?? 'overall')
    );

    $survey['question_count'] = count($questions);

    $responses = response_records();

    $survey['response_count'] = count(
        array_filter(
            $responses,
            static fn(array $response): bool =>
                (string)($response['survey_id'] ?? '') === $surveyId &&
                (string)($response['completed'] ?? 'false') === 'true'
        )
    );

    return $survey;
}

function public_survey(array $survey): array
{
    $result = decorate_survey($survey);

    unset($result['internal_notes']);

    return $result;
}

function validate_email_setting(array $smtp): void
{
    $required = [
        'host' => 'SMTPサーバ',
        'port' => 'ポート番号',
        'from_email' => '送信元メールアドレス',
    ];

    foreach ($required as $key => $label) {
        if (trim((string)($smtp[$key] ?? '')) === '') {
            throw new RuntimeException($label . 'を入力してください。');
        }
    }

    $port = (int)$smtp['port'];

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('SMTPポート番号が正しくありません。');
    }

    if (!filter_var((string)$smtp['from_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('送信元メールアドレスが正しくありません。');
    }

    if (!in_array(
        (string)($smtp['encryption'] ?? 'TLS'),
        ['None', 'SSL', 'TLS'],
        true
    )) {
        throw new RuntimeException('SMTP暗号化方式が正しくありません。');
    }
}

/**
 * SMTP接続の基本確認。
 *
 * 実際のSMTPサーバへ接続し、初期応答を確認する。
 * メール送信そのものは送信処理で実行する。
 */
function smtp_connection_test(array $smtp): array
{
    validate_email_setting($smtp);

    $host = trim((string)$smtp['host']);
    $port = (int)$smtp['port'];
    $encryption = (string)($smtp['encryption'] ?? 'TLS');

    if ($encryption === 'SSL') {
        $transportHost = 'ssl://' . $host;
    } else {
        $transportHost = $host;
    }

    $socket = @stream_socket_client(
        $transportHost . ':' . $port,
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return [
            'success' => false,
            'message' => 'SMTPサーバへ接続できませんでした。' .
                ($errstr !== '' ? ' ' . $errstr : ''),
        ];
    }

    stream_set_timeout($socket, 10);

    $response = fgets($socket, 515);

    if ($response === false) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'SMTPサーバから応答を取得できませんでした。',
        ];
    }

    if (strpos($response, '220') !== 0) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'SMTPサーバから正常な接続応答を取得できませんでした。',
        ];
    }

    fwrite($socket, "EHLO localhost\r\n");

    $ehloResponse = '';
    $started = microtime(true);

    while ((microtime(true) - $started) < 3) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $ehloResponse .= $line;

        if (preg_match('/^250\s/', $line)) {
            break;
        }

        if (!preg_match('/^250-/', $line)) {
            break;
        }
    }

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    if (!str_contains($ehloResponse, '250')) {
        return [
            'success' => false,
            'message' => 'SMTP EHLO応答を確認できませんでした。',
        ];
    }

    return [
        'success' => true,
        'message' => 'SMTPサーバへの接続を確認しました。',
    ];
}

function validate_smtp_auth_requirements(array $smtp): void
{
    $encryption = (string)($smtp['encryption'] ?? 'TLS');

    if ($encryption !== 'None') {
        if (trim((string)($smtp['username'] ?? '')) === '') {
            throw new RuntimeException('SMTP認証ユーザー名を入力してください。');
        }

        if (trim((string)($smtp['password'] ?? '')) === '') {
            throw new RuntimeException('SMTP認証パスワードを設定してください。');
        }
    }
}

function build_mail_body(
    string $body,
    string $surveyUrl
): string {
    $placeholder = '{{SURVEY_URL}}';

    if (str_contains($body, $placeholder)) {
        return str_replace($placeholder, $surveyUrl, $body);
    }

    return rtrim($body) . "\n\n回答はこちら：\n" . $surveyUrl;
}

function base_url(): string
{
    $scheme = 'http';

    if (
        isset($_SERVER['HTTPS']) &&
        strtolower((string)$_SERVER['HTTPS']) !== 'off'
    ) {
        $scheme = 'https';
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host;
}

function answer_url(string $surveyId, string $recipientToken): string
{
    return base_url() .
        '/index.php?mode=answer&survey=' .
        rawurlencode($surveyId) .
        '&token=' .
        rawurlencode($recipientToken);
}

function recipient_token(): string
{
    return bin2hex(random_bytes(24));
}

function survey_is_editable(array $survey): bool
{
    return (string)($survey['status'] ?? 'draft') === 'draft';
}

function survey_status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'closed' => '終了',
        default => '下書き',
    };
}

function answer_type_label(string $type): string
{
    return match ($type) {
        'single' => '単一選択',
        'multiple' => '複数選択',
        default => '自由記述',
    };
}

function create_empty_survey(): array
{
    $now = now_iso();

    return [
        'id' => new_id('survey'),
        'name' => '',
        'description' => '',
        'status' => 'draft',
        'publish_start' => '',
        'publish_end' => '',
        'numbering' => 'overall',
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

function create_initial_group(string $surveyId): array
{
    return [
        'id' => new_id('group'),
        'survey_id' => $surveyId,
        'name' => 'グループ1',
        'sort_order' => 1,
    ];
}

function create_initial_question(string $groupId): array
{
    return [
        'id' => new_id('question'),
        'group_id' => $groupId,
        'question_text' => '',
        'answer_type' => 'text',
        'required' => true,
        'sort_order' => 1,
    ];
}

function create_option(string $questionId, int $sortOrder): array
{
    return [
        'id' => new_id('option'),
        'question_id' => $questionId,
        'label' => '',
        'sort_order' => $sortOrder,
        'next_question_id' => '',
        'end_survey' => false,
    ];
}

function initialize_new_survey(): array
{
    $survey = create_empty_survey();
    $group = create_initial_group((string)$survey['id']);
    $question = create_initial_question((string)$group['id']);

    return [
        'survey' => $survey,
        'groups' => [$group],
        'questions' => [$question],
        'options' => [],
    ];
}

function safe_int(mixed $value, int $default = 0): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int)$value;
    }

    return $default;
}

function normalize_required_bool(mixed $value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

/**
 * 初回アクセス時に必要なJSONファイルを作成。
 */
function initialize_storage(): void
{
    ensure_data_directory();

    foreach (DATA_FILES as $key => $path) {
        if (!file_exists($path)) {
            write_json_file($key, []);
        }
    }

    $settings = read_json_file('settings');

    if ($settings === []) {
        write_json_file('settings', [default_settings()]);
    }
}

try {
    initialize_storage();
} catch (Throwable $e) {
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_response([
            'success' => false,
            'message' => 'データ保存領域を初期化できません。',
        ], 500);
    }

    http_response_code(500);
    echo 'データ保存領域を初期化できません。';
    exit;
}

/**
 * API処理
 */
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

if ($action !== '') {
    try {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $readActions = [
            'get_dashboard',
            'get_surveys',
            'get_survey',
            'get_customers',
            'get_settings',
            'get_status',
            'get_results',
        ];

        if (!in_array($action, $readActions, true)) {
            if ($method !== 'POST') {
                json_response([
                    'success' => false,
                    'message' => 'この操作にはPOSTが必要です。',
                ], 405);
            }

            verify_csrf();
        }

        if ($action === 'get_surveys') {
            $surveys = survey_records();
            $result = [];

            foreach ($surveys as $survey) {
                if (!is_array($survey)) {
                    continue;
                }

                $result[] = decorate_survey($survey);
            }

            json_response([
                'success' => true,
                'surveys' => $result,
            ]);
        }

        if ($action === 'get_survey') {
            $surveyId = (string)($_GET['survey'] ?? '');

            if ($surveyId === '') {
                json_response([
                    'success' => false,
                    'message' => 'アンケートIDが指定されていません。',
                ], 400);
            }

            $survey = survey_by_id($surveyId);

            if ($survey === null) {
                json_response([
                    'success' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            json_response([
                'success' => true,
                'survey' => decorate_survey($survey),
            ]);
        }

        if ($action === 'get_customers') {
            $customers = customer_records();

            $query = trim((string)($_GET['q'] ?? ''));

            if ($query !== '') {
                $customers = array_values(
                    array_filter(
                        $customers,
                        static function (array $customer) use ($query): bool {
                            return str_contains(
                                mb_strtolower((string)($customer['name'] ?? '')),
                                mb_strtolower($query)
                            ) ||
                            str_contains(
                                mb_strtolower((string)($customer['email'] ?? '')),
                                mb_strtolower($query)
                            );
                        }
                    )
                );
            }

            json_response([
                'success' => true,
                'customers' => array_values($customers),
            ]);
        }

        if ($action === 'get_settings') {
            json_response([
                'success' => true,
                'settings' => public_settings(load_settings()),
                'csrf_token' => csrf_token(),
            ]);
        }

        if ($action === 'get_dashboard') {
            $surveys = survey_records();
            $responses = response_records();
            $recipients = recipient_records();

            $totalResponses = count(
                array_filter(
                    $responses,
                    static fn(array $row): bool =>
                        (string)($row['completed'] ?? '') === 'true'
                )
            );

            $totalSent = count(
                array_filter(
                    $recipients,
                    static fn(array $row): bool =>
                        in_array(
                            (string)($row['send_status'] ?? ''),
                            ['sent', 'failed'],
                            true
                        )
                )
            );

            json_response([
                'success' => true,
                'summary' => [
                    'survey_count' => count($surveys),
                    'response_count' => $totalResponses,
                    'sent_count' => $totalSent,
                    'draft_count' => count(
                        array_filter(
                            $surveys,
                            static fn(array $row): bool =>
                                (string)($row['status'] ?? '') === 'draft'
                        )
                    ),
                    'published_count' => count(
                        array_filter(
                            $surveys,
                            static fn(array $row): bool =>
                                (string)($row['status'] ?? '') === 'published'
                        )
                    ),
                ],
            ]);
        }

        if ($action === 'save_settings') {
            $input = request_json();

            $current = load_settings();

            $smtpInput = is_array($input['smtp'] ?? null)
                ? $input['smtp']
                : [];

            $kintoneInput = is_array($input['kintone'] ?? null)
                ? $input['kintone']
                : [];

            $smtp = $current['smtp'];
            $kintone = $current['kintone'];

            foreach (
                [
                    'host',
                    'port',
                    'encryption',
                    'username',
                    'from_email',
                    'from_name',
                ] as $key
            ) {
                if (array_key_exists($key, $smtpInput)) {
                    $smtp[$key] = is_scalar($smtpInput[$key])
                        ? (string)$smtpInput[$key]
                        : '';
                }
            }

            if (array_key_exists('password', $smtpInput)) {
                $password = (string)$smtpInput['password'];

                if ($password !== '') {
                    $smtp['password'] = $password;
                }
            }

            foreach (
                [
                    'domain',
                    'app_id',
                    'login_name',
                    'customer_name_field',
                    'email_field',
                    'proxy_host_port',
                ] as $key
            ) {
                if (array_key_exists($key, $kintoneInput)) {
                    $kintone[$key] = is_scalar($kintoneInput[$key])
                        ? (string)$kintoneInput[$key]
                        : '';
                }
            }

            if (array_key_exists('password', $kintoneInput)) {
                $password = (string)$kintoneInput['password'];

                if ($password !== '') {
                    $kintone['password'] = $password;
                }
            }

            $smtp['port'] = safe_int($smtp['port'], 587);

            save_settings([
                'smtp' => $smtp,
                'kintone' => $kintone,
            ]);

            json_response([
                'success' => true,
                'message' => '設定を保存しました。',
                'settings' => public_settings(load_settings()),
            ]);
        }

        if ($action === 'test_kintone') {
            $input = request_json();
            $current = load_settings();
            $config = $current['kintone'];

            if (isset($input['kintone']) && is_array($input['kintone'])) {
                foreach (
                    [
                        'domain',
                        'app_id',
                        'login_name',
                        'customer_name_field',
                        'email_field',
                        'proxy_host_port',
                    ] as $key
                ) {
                    if (array_key_exists($key, $input['kintone'])) {
                        $config[$key] = (string)$input['kintone'][$key];
                    }
                }

                if (
                    array_key_exists('password', $input['kintone']) &&
                    (string)$input['kintone']['password'] !== ''
                ) {
                    $config['password'] = (string)$input['kintone']['password'];
                }
            }

            $result = kintone_test_connection($config);

            if ($result['success']) {
                $current['kintone']['tested_at'] = now_iso();
                $current['kintone']['test_status'] = '接続確認済み';
                save_settings($current);
            } else {
                $current['kintone']['tested_at'] = now_iso();
                $current['kintone']['test_status'] = '接続エラー';
                save_settings($current);
            }

            unset($result['data']);

            json_response($result, $result['success'] ? 200 : 400);
        }

        if ($action === 'sync_kintone') {
            $settings = load_settings();
            $result = kintone_fetch_customers($settings['kintone']);

            if (!$result['success']) {
                json_response($result, 400);
            }

            json_response([
                'success' => true,
                'message' => $result['message'],
                'customers' => $result['customers'],
            ]);
        }

        if ($action === 'test_smtp') {
            $input = request_json();
            $current = load_settings();
            $smtp = $current['smtp'];

            if (isset($input['smtp']) && is_array($input['smtp'])) {
                foreach (
                    [
                        'host',
                        'port',
                        'encryption',
                        'username',
                        'from_email',
                        'from_name',
                    ] as $key
                ) {
                    if (array_key_exists($key, $input['smtp'])) {
                        $smtp[$key] = (string)$input['smtp'][$key];
                    }
                }

                if (
                    array_key_exists('password', $input['smtp']) &&
                    (string)$input['smtp']['password'] !== ''
                ) {
                    $smtp['password'] = (string)$input['smtp']['password'];
                }
            }

            $result = smtp_connection_test($smtp);

            if ($result['success']) {
                $current['smtp']['tested_at'] = now_iso();
                $current['smtp']['test_status'] = '接続確認済み';
            } else {
                $current['smtp']['tested_at'] = now_iso();
                $current['smtp']['test_status'] = '接続エラー';
            }

            save_settings($current);

            json_response(
                $result,
                $result['success'] ? 200 : 400
            );
        }

        if ($action === 'create_survey') {
            $initialized = initialize_new_survey();

            $surveys = survey_records();
            $groups = group_records();
            $questions = question_records();

            $surveys[] = $initialized['survey'];
            $groups[] = $initialized['groups'][0];
            $questions[] = $initialized['questions'][0];

            write_json_file('surveys', $surveys);
            write_json_file('groups', $groups);
            write_json_file('questions', $questions);

            json_response([
                'success' => true,
                'survey' => decorate_survey($initialized['survey']),
            ]);
        }

        if ($action === 'save_survey') {
            $input = request_json();

            $surveyInput = is_array($input['survey'] ?? null)
                ? $input['survey']
                : [];

            $surveyId = (string)($surveyInput['id'] ?? '');

            if ($surveyId === '') {
                json_response([
                    'success' => false,
                    'message' => 'アンケートIDが指定されていません。',
                ], 400);
            }

            $surveys = survey_records();
            $surveyIndex = find_record_index($surveys, $surveyId);

            if ($surveyIndex < 0) {
                json_response([
                    'success' => false,
                    'message' => '保存対象のアンケートが見つかりません。',
                ], 404);
            }

            $existingSurvey = $surveys[$surveyIndex];

            if (!survey_is_editable($existingSurvey)) {
                json_response([
                    'success' => false,
                    'message' => '公開中または終了したアンケートは編集できません。',
                ], 400);
            }

            $groupsInput = is_array($input['groups'] ?? null)
                ? $input['groups']
                : [];

            $questionsInput = is_array($input['questions'] ?? null)
                ? $input['questions']
                : [];

            $optionsInput = is_array($input['options'] ?? null)
                ? $input['options']
                : [];

            $survey = [
                'id' => $surveyId,
                'name' => trim((string)($surveyInput['name'] ?? '')),
                'description' => (string)($surveyInput['description'] ?? ''),
                'status' => 'draft',
                'publish_start' => (string)($surveyInput['publish_start'] ?? ''),
                'publish_end' => (string)($surveyInput['publish_end'] ?? ''),
                'numbering' =>
                    (string)($surveyInput['numbering'] ?? 'overall') === 'group'
                        ? 'group'
                        : 'overall',
                'created_at' => (string)(
                    $existingSurvey['created_at'] ?? now_iso()
                ),
                'updated_at' => now_iso(),
            ];

            $groups = [];
            $questions = [];
            $options = [];

            foreach ($groupsInput as $groupIndex => $group) {
                if (!is_array($group)) {
                    continue;
                }

                $groupId = trim((string)($group['id'] ?? ''));

                if ($groupId === '') {
                    $groupId = new_id('group');
                }

                $groups[] = [
                    'id' => $groupId,
                    'survey_id' => $surveyId,
                    'name' => trim((string)($group['name'] ?? '')),
                    'sort_order' => (int)$groupIndex + 1,
                ];
            }

            foreach ($questionsInput as $questionIndex => $question) {
                if (!is_array($question)) {
                    continue;
                }

                $questionId = trim((string)($question['id'] ?? ''));

                if ($questionId === '') {
                    $questionId = new_id('question');
                }

                $groupId = trim((string)($question['group_id'] ?? ''));

                if (
                    $groupId === '' ||
                    find_record($groups, $groupId) === null
                ) {
                    continue;
                }

                $type = (string)($question['answer_type'] ?? 'text');

                if (!in_array($type, ['text', 'single', 'multiple'], true)) {
                    $type = 'text';
                }

                $questions[] = [
                    'id' => $questionId,
                    'group_id' => $groupId,
                    'question_text' =>
                        trim((string)($question['question_text'] ?? '')),
                    'answer_type' => $type,
                    'required' =>
                        normalize_required_bool($question['required'] ?? false),
                    'sort_order' => (int)($question['sort_order'] ?? $questionIndex + 1),
                ];
            }

            $questionIds = [];

            foreach ($questions as $question) {
                $questionIds[(string)$question['id']] = true;
            }

            foreach ($optionsInput as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $optionId = trim((string)($option['id'] ?? ''));

                if ($optionId === '') {
                    $optionId = new_id('option');
                }

                $questionId = trim((string)($option['question_id'] ?? ''));

                if ($questionId === '' || !isset($questionIds[$questionId])) {
                    continue;
                }

                $nextQuestionId =
                    trim((string)($option['next_question_id'] ?? ''));

                if (
                    $nextQuestionId !== '' &&
                    !isset($questionIds[$nextQuestionId])
                ) {
                    $nextQuestionId = '';
                }

                $options[] = [
                    'id' => $optionId,
                    'question_id' => $questionId,
                    'label' => trim((string)($option['label'] ?? '')),
                    'sort_order' => (int)($option['sort_order'] ?? 1),
                    'next_question_id' => $nextQuestionId,
                    'end_survey' =>
                        normalize_required_bool(
                            $option['end_survey'] ?? false
                        ),
                ];
            }

            $validationErrors = validate_survey_structure(
                $survey,
                $groups,
                $questions,
                $options
            );

            if ($validationErrors !== []) {
                json_response([
                    'success' => false,
                    'message' => '入力内容を確認してください。',
                    'errors' => $validationErrors,
                ], 422);
            }

            $surveys[$surveyIndex] = $survey;

            $allGroups = group_records();
            $allQuestions = question_records();
            $allOptions = option_records();

            $allGroups = array_values(
                array_filter(
                    $allGroups,
                    static fn(array $row): bool =>
                        (string)($row['survey_id'] ?? '') !== $surveyId
                )
            );

            $allQuestions = array_values(
                array_filter(
                    $allQuestions,
                    static function (array $row) use ($surveyId, $allGroups): bool {
                        foreach ($allGroups as $group) {
                            if (
                                (string)($group['survey_id'] ?? '') === $surveyId &&
                                (string)($group['id'] ?? '') ===
                                    (string)($row['group_id'] ?? '')
                            ) {
                                return false;
                            }
                        }

                        return true;
                    }
                )
            );

            $surveyQuestionIds = [];

            foreach ($questions as $question) {
                $surveyQuestionIds[(string)$question['id']] = true;
            }

            $allOptions = array_values(
                array_filter(
                    $allOptions,
                    static fn(array $row): bool =>
                        !isset(
                            $surveyQuestionIds[
                                (string)($row['question_id'] ?? '')
                            ]
                        )
                )
            );

            $allGroups = array_merge($allGroups, $groups);
            $allQuestions = array_merge($allQuestions, $questions);
            $allOptions = array_merge($allOptions, $options);

            write_json_file('surveys', $surveys);
            write_json_file('groups', $allGroups);
            write_json_file('questions', $allQuestions);
            write_json_file('options', $allOptions);

            json_response([
                'success' => true,
                'message' => 'アンケートを保存しました。',
                'survey' => decorate_survey($survey),
            ]);
        }

        if ($action === 'publish_survey') {
            $input = request_json();
            $surveyId = (string)($input['survey_id'] ?? '');

            $surveys = survey_records();
            $index = find_record_index($surveys, $surveyId);

            if ($index < 0) {
                json_response([
                    'success' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $survey = $surveys[$index];

            if ((string)($survey['status'] ?? '') !== 'draft') {
                json_response([
                    'success' => false,
                    'message' => '公開できる状態ではありません。',
                ], 400);
            }

            $groups = survey_groups($surveyId);
            $questions = all_survey_questions($surveyId);
            $options = option_records();

            $surveyErrors = validate_survey_structure(
                $survey,
                $groups,
                $questions,
                array_values(
                    array_filter(
                        $options,
                        static function (array $option) use ($questions): bool {
                            foreach ($questions as $question) {
                                if (
                                    (string)$question['id'] ===
                                    (string)($option['question_id'] ?? '')
                                ) {
                                    return true;
                                }
                            }

                            return false;
                        }
                    )
                )
            );

            if ($surveyErrors !== []) {
                json_response([
                    'success' => false,
                    'message' => '公開前に内容を修正してください。',
                    'errors' => $surveyErrors,
                ], 422);
            }

            $survey['status'] = 'published';
            $survey['updated_at'] = now_iso();

            $surveys[$index] = $survey;
            write_json_file('surveys', $surveys);

            json_response([
                'success' => true,
                'message' => 'アンケートを公開しました。',
                'survey' => decorate_survey($survey),
            ]);
        }

        if ($action === 'close_survey') {
            $input = request_json();
            $surveyId = (string)($input['survey_id'] ?? '');

            $surveys = survey_records();
            $index = find_record_index($surveys, $surveyId);

            if ($index < 0) {
                json_response([
                    'success' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            if (
                (string)($surveys[$index]['status'] ?? '') !== 'published'
            ) {
                json_response([
                    'success' => false,
                    'message' => '公開中のアンケートだけ終了できます。',
                ], 400);
            }

            $surveys[$index]['status'] = 'closed';
            $surveys[$index]['updated_at'] = now_iso();

            write_json_file('surveys', $surveys);

            json_response([
                'success' => true,
                'message' => 'アンケートの受付を終了しました。',
            ]);
        }

        if ($action === 'delete_survey') {
            $input = request_json();
            $surveyId = (string)($input['survey_id'] ?? '');

            $surveys = survey_records();
            $index = find_record_index($surveys, $surveyId);

            if ($index < 0) {
                json_response([
                    'success' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            if (
                (string)($surveys[$index]['status'] ?? '') !== 'draft'
            ) {
                json_response([
                    'success' => false,
                    'message' => '下書き状態のアンケートだけ削除できます。',
                ], 400);
            }

            unset($surveys[$index]);
            $surveys = array_values($surveys);

            $groups = array_values(
                array_filter(
                    group_records(),
                    static fn(array $row): bool =>
                        (string)($row['survey_id'] ?? '') !== $surveyId
                )
            );

            $groupIds = [];

            foreach (group_records() as $group) {
                if (
                    is_array($group) &&
                    (string)($group['survey_id'] ?? '') === $surveyId
                ) {
                    $groupIds[(string)($group['id'] ?? '')] = true;
                }
            }

            $questions = array_values(
                array_filter(
                    question_records(),
                    static fn(array $row): bool =>
                        !isset($groupIds[(string)($row['group_id'] ?? '')])
                )
            );

            $questionIds = [];

            foreach (question_records() as $question) {
                if (
                    is_array($question) &&
                    isset(
                        $groupIds[
                            (string)($question['group_id'] ?? '')
                        ]
                    )
                ) {
                    $questionIds[(string)($question['id'] ?? '')] = true;
                }
            }

            $options = array_values(
                array_filter(
                    option_records(),
                    static fn(array $row): bool =>
                        !isset(
                            $questionIds[
                                (string)($row['question_id'] ?? '')
                            ]
                        )
                )
            );

            write_json_file('surveys', $surveys);
            write_json_file('groups', $groups);
            write_json_file('questions', $questions);
            write_json_file('options', $options);

            json_response([
                'success' => true,
                'message' => 'アンケートを削除しました。',
            ]);
        }

        if ($action === 'save_recipients') {
            $input = request_json();

            $surveyId = (string)($input['survey_id'] ?? '');
            $customerIds = is_array($input['customer_ids'] ?? null)
                ? $input['customer_ids']
                : [];

            $survey = survey_by_id($surveyId);

            if ($survey === null) {
                json_response([
                    'success' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            if ((string)($survey['status'] ?? '') !== 'published') {
                json_response([
                    'success' => false,
                    'message' => '公開中のアンケートだけ送信対象を設定できます。',
                ], 400);
            }

            $customers = customer_records();
            $existing = recipient_records();

            $existingForSurvey = array_values(
                array_filter(
                    $existing,
                    static fn(array $row): bool =>
                        (string)($row['survey_id'] ?? '') !== $surveyId
                )
            );

            $newRecipients = [];

            foreach ($customerIds as $customerId) {
                $customerId = (string)$customerId;

                if ($customerId === '') {
                    continue;
                }

                $customer = find_record($customers, $customerId);

                if ($customer === null) {
                    continue;
                }

                $newRecipients[] = [
                    'id' => new_id('recipient'),
                    'survey_id' => $surveyId,
                    'customer_id' => $customerId,
                    'token' => recipient_token(),
                    'send_status' => 'pending',
                    'send_at' => '',
                    'answer_status' => 'unanswered',
                    'created_at' => now_iso(),
                ];
            }

            write_json_file(
                'recipients',
                array_merge($existingForSurvey, $newRecipients)
            );

            json_response([
                'success' => true,
                'message' => count($newRecipients) . '名を送信対象として設定しました。',
                'recipients' => $newRecipients,
            ]);
        }

        if ($action === 'get_status') {
            $surveyId = (string)($_GET['survey'] ?? '');

            $survey = survey_by_id($surveyId);

            if ($survey === null) {
                json_response([
                    'success' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $recipients = array_values(
                array_filter(
                    recipient_records(),
                    static fn(array $row): bool =>
                        (string)($row['survey_id'] ?? '') === $surveyId
                )
            );

            $responses = array_values(
                array_filter(
                    response_records(),
                    static fn(array $row): bool =>
                        (string)($row['survey_id'] ?? '') === $surveyId &&
                        (string)($row['completed'] ?? '') === 'true'
                )
            );

            $daily = [];

            foreach ($responses as $response) {
                $date = substr(
                    (string)($response['answered_at'] ?? ''),
                    0,
                    10
                );

                if ($date === '') {
                    continue;
                }

                $daily[$date] = ($daily[$date] ?? 0) + 1;
            }

            ksort($daily);

            $sentCount = count(
                array_filter(
                    $recipients,
                    static fn(array $row): bool =>
                        (string)($row['send_status'] ?? '') === 'sent'
                )
            );

            $answeredRecipientIds = [];

            foreach ($responses as $response) {
                $recipientId =
                    (string)($response['recipient_id'] ?? '');

                if ($recipientId !== '') {
                    $answeredRecipientIds[$recipientId] = true;
                }
            }

            $answeredCount = count($answeredRecipientIds);

            $answerRate = $sentCount > 0
                ? round(($answeredCount / $sentCount) * 100, 1)
                : 0;

            json_response([
                'success' => true,
                'status' => [
                    'response_count' => count($responses),
                    'sent_count' => $sentCount,
                    'answered_count' => $answeredCount,
                    'unanswered_count' => max(
                        0,
                        $sentCount - $answeredCount
                    ),
                    'answer_rate' => $answerRate,
                    'daily' => $daily,
                    'recipients' => $recipients,
                ],
            ]);
        }

        if ($action === 'get_results') {
            $surveyId = (string)($_GET['survey'] ?? '');

            $survey = survey_by_id($surveyId);

            if ($survey === null) {
                json_response([
                    'success' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $questions = all_survey_questions($surveyId);
            $options = option_records();
            $responses = array_values(
                array_filter(
                    response_records(),
                    static fn(array $row): bool =>
                        (string)($row['survey_id'] ?? '') === $surveyId &&
                        (string)($row['completed'] ?? '') === 'true'
                )
            );

            $responseIds = [];

            foreach ($responses as $response) {
                $responseIds[(string)$response['id']] = true;
            }

            $answers = array_values(
                array_filter(
                    response_answer_records(),
                    static fn(array $row): bool =>
                        isset(
                            $responseIds[
                                (string)($row['response_id'] ?? '')
                            ]
                        )
                )
            );

            $results = [];

            foreach ($questions as $question) {
                $questionId = (string)$question['id'];
                $type = (string)$question['answer_type'];

                $questionAnswers = array_values(
                    array_filter(
                        $answers,
                        static fn(array $answer): bool =>
                            (string)($answer['question_id'] ?? '') ===
                            $questionId
                    )
                );

                $item = [
                    'question_id' => $questionId,
                    'question_text' => (string)$question['question_text'],
                    'answer_type' => $type,
                    'answers' => [],
                ];

                if ($type === 'text') {
                    foreach ($questionAnswers as $answer) {
                        $item['answers'][] = [
                            'value' => (string)($answer['answer_value'] ?? ''),
                        ];
                    }
                } else {
                    $questionOptions = array_values(
                        array_filter(
                            $options,
                            static fn(array $option): bool =>
                                (string)($option['question_id'] ?? '') ===
                                $questionId
                        )
                    );

                    foreach ($questionOptions as $option) {
                        $optionId = (string)$option['id'];
                        $count = 0;

                        foreach ($questionAnswers as $answer) {
                            $answerValue =
                                (string)($answer['answer_value'] ?? '');

                            $values = $type === 'multiple'
                                ? json_decode($answerValue, true)
                                : [$answerValue];

                            if (
                                is_array($values) &&
                                in_array($optionId, $values, true)
                            ) {
                                $count++;
                            }
                        }

                        $denominator = count($questionAnswers);

                        $item['answers'][] = [
                            'option_id' => $optionId,
                            'label' => (string)$option['label'],
                            'count' => $count,
                            'rate' => $denominator > 0
                                ? round(($count / $denominator) * 100, 1)
                                : 0,
                        ];
                    }
                }

                $results[] = $item;
            }

            json_response([
                'success' => true,
                'results' => $results,
            ]);
        }

        json_response([
            'success' => false,
            'message' => '指定された処理は存在しません。',
        ], 404);
    } catch (Throwable $e) {
        json_response([
            'success' => false,
            'message' => sanitize_error_message($e->getMessage()),
        ], 500);
    }
}

$mode = isset($_GET['mode']) ? (string)$_GET['mode'] : 'admin';

$answerMode = $mode === 'answer';

$csrfToken = csrf_token();

$initialSurveys = [];

try {
    foreach (survey_records() as $survey) {
        if (is_array($survey)) {
            $initialSurveys[] = decorate_survey($survey);
        }
    }
} catch (Throwable $e) {
    $initialSurveys = [];
}

$initialSettings = [];

try {
    $initialSettings = public_settings(load_settings());
} catch (Throwable $e) {
    $initialSettings = public_settings(default_settings());
}

$initialCustomers = [];

try {
    $initialCustomers = customer_records();
} catch (Throwable $e) {
    $initialCustomers = [];
}

$answerSurvey = null;
$answerRecipient = null;

if ($answerMode) {
    $surveyId = (string)($_GET['survey'] ?? '');
    $token = (string)($_GET['token'] ?? '');

    if ($surveyId !== '') {
        $candidate = survey_by_id($surveyId);

        if ($candidate !== null) {
            $answerSurvey = public_survey($candidate);
        }
    }

    if ($token !== '') {
        foreach (recipient_records() as $recipient) {
            if (
                is_array($recipient) &&
                hash_equals(
                    (string)($recipient['token'] ?? ''),
                    $token
                )
            ) {
                $answerRecipient = $recipient;
                break;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h($csrfToken) ?>">
<title>アンケート業務運営アプリ</title>
<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --primary-light: #eff6ff;
    --success: #16a34a;
    --success-light: #f0fdf4;
    --warning: #d97706;
    --warning-light: #fffbeb;
    --danger: #dc2626;
    --danger-light: #fef2f2;
    --gray-50: #f8fafc;
    --gray-100: #f1f5f9;
    --gray-200: #e2e8f0;
    --gray-300: #cbd5e1;
    --gray-400: #94a3b8;
    --gray-500: #64748b;
    --gray-600: #475569;
    --gray-700: #334155;
    --gray-800: #1e293b;
    --gray-900: #0f172a;
    --white: #ffffff;
    --shadow-sm: 0 1px 2px rgba(15, 23, 42, .06);
    --shadow: 0 4px 12px rgba(15, 23, 42, .08);
    --radius: 8px;
    --radius-lg: 12px;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    min-height: 100%;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    color: var(--gray-800);
    background: var(--gray-50);
}

body {
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
    opacity: .6;
}

.app-header {
    position: sticky;
    top: 0;
    z-index: 100;
    height: 64px;
    display: flex;
    align-items: center;
    background: var(--white);
    border-bottom: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
}

.header-inner {
    width: min(1440px, calc(100% - 40px));
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 28px;
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 230px;
    color: var(--gray-900);
    font-weight: 700;
    font-size: 17px;
    white-space: nowrap;
}

.brand-mark {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    display: grid;
    place-items: center;
    color: var(--white);
    background: linear-gradient(135deg, #2563eb, #4f46e5);
    font-weight: 800;
}

.main-nav {
    display: flex;
    align-items: stretch;
    height: 64px;
    gap: 4px;
}

.nav-button {
    position: relative;
    border: 0;
    background: transparent;
    color: var(--gray-600);
    padding: 0 15px;
    font-weight: 600;
}

.nav-button:hover {
    color: var(--primary);
    background: var(--gray-50);
}

.nav-button.active {
    color: var(--primary);
}

.nav-button.active::after {
    content: "";
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 0;
    height: 3px;
    border-radius: 3px 3px 0 0;
    background: var(--primary);
}

.app-main {
    width: min(1440px, calc(100% - 40px));
    margin: 0 auto;
    padding: 30px 0 70px;
}

.page {
    display: none;
}

.page.active {
    display: block;
}

.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 24px;
}

.page-title {
    margin: 0;
    font-size: 25px;
    line-height: 1.3;
    color: var(--gray-900);
}

.page-description {
    margin: 7px 0 0;
    color: var(--gray-500);
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.card {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
}

.card + .card {
    margin-top: 18px;
}

.card-header {
    padding: 18px 20px;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.card-title {
    margin: 0;
    color: var(--gray-900);
    font-size: 16px;
    font-weight: 700;
}

.card-body {
    padding: 20px;
}

.btn {
    min-height: 38px;
    padding: 8px 15px;
    border: 1px solid transparent;
    border-radius: 7px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    font-weight: 600;
    transition:
        background-color .15s ease,
        border-color .15s ease,
        color .15s ease,
        box-shadow .15s ease;
}

.btn-primary {
    color: var(--white);
    background: var(--primary);
    border-color: var(--primary);
}

.btn-primary:hover {
    background: var(--primary-dark);
    border-color: var(--primary-dark);
}

.btn-secondary {
    color: var(--gray-700);
    background: var(--white);
    border-color: var(--gray-300);
}

.btn-secondary:hover {
    background: var(--gray-50);
}

.btn-success {
    color: var(--white);
    background: var(--success);
    border-color: var(--success);
}

.btn-danger {
    color: var(--white);
    background: var(--danger);
    border-color: var(--danger);
}

.btn-warning {
    color: var(--white);
    background: var(--warning);
    border-color: var(--warning);
}

.btn-sm {
    min-height: 32px;
    padding: 5px 10px;
    font-size: 13px;
}

.btn-ghost {
    color: var(--gray-600);
    background: transparent;
    border-color: transparent;
}

.btn-ghost:hover {
    background: var(--gray-100);
}

.loading-spinner {
    display: none;
    width: 15px;
    height: 15px;
    border: 2px solid rgba(255,255,255,.45);
    border-top-color: currentColor;
    border-radius: 50%;
    animation: spin .7s linear infinite;
}

.is-loading .loading-spinner {
    display: inline-block;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.badge {
    display: inline-flex;
    align-items: center;
    min-height: 25px;
    padding: 2px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
}

.badge-draft {
    color: var(--gray-600);
    background: var(--gray-100);
}

.badge-published {
    color: #166534;
    background: #dcfce7;
}

.badge-closed {
    color: #991b1b;
    background: #fee2e2;
}

.badge-info {
    color: #1e40af;
    background: #dbeafe;
}

.table-wrap {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 13px 15px;
    border-bottom: 1px solid var(--gray-200);
    text-align: left;
    vertical-align: middle;
}

.data-table th {
    background: var(--gray-50);
    color: var(--gray-600);
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.data-table tbody tr:hover {
    background: #fafcff;
}

.data-table tbody tr:last-child td {
    border-bottom: 0;
}

.empty-state {
    padding: 50px 20px;
    text-align: center;
    color: var(--gray-500);
}

.empty-state-icon {
    width: 52px;
    height: 52px;
    margin: 0 auto 12px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    color: var(--primary);
    background: var(--primary-light);
    font-size: 23px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-label {
    color: var(--gray-700);
    font-size: 13px;
    font-weight: 700;
}

.required-mark {
    color: var(--danger);
    margin-left: 3px;
}

.form-control {
    width: 100%;
    min-height: 40px;
    padding: 8px 11px;
    border: 1px solid var(--gray-300);
    border-radius: 7px;
    color: var(--gray-800);
    background: var(--white);
    outline: none;
    transition:
        border-color .15s ease,
        box-shadow .15s ease;
}

textarea.form-control {
    min-height: 100px;
    resize: vertical;
}

.form-control:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .10);
}

.form-help {
    color: var(--gray-500);
    font-size: 12px;
}

.form-error {
    display: none;
    color: var(--danger);
    font-size: 12px;
}

.has-error .form-control {
    border-color: var(--danger);
    background: var(--danger-light);
}

.has-error .form-error {
    display: block;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-card {
    padding: 18px 20px;
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
}

.stat-label {
    color: var(--gray-500);
    font-size: 12px;
    font-weight: 600;
}

.stat-value {
    margin-top: 6px;
    color: var(--gray-900);
    font-size: 28px;
    font-weight: 800;
}

.stat-note {
    margin-top: 3px;
    color: var(--gray-500);
    font-size: 12px;
}

.sub-tabs {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 20px;
    padding: 4px;
    border: 1px solid var(--gray-200);
    border-radius: 9px;
    background: var(--white);
}

.sub-tab {
    flex: 0 0 auto;
    padding: 9px 17px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: var(--gray-600);
    font-weight: 700;
}

.sub-tab:hover {
    background: var(--gray-100);
}

.sub-tab.active {
    color: var(--primary);
    background: var(--primary-light);
}

.survey-editor {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.group-card {
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    background: var(--white);
    overflow: hidden;
}

.group-header {
    padding: 13px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.drag-handle {
    color: var(--gray-400);
    cursor: grab;
    user-select: none;
    font-size: 18px;
}

.group-name-input {
    flex: 1;
    min-width: 0;
    padding: 7px 9px;
    border: 1px solid transparent;
    border-radius: 6px;
    background: transparent;
    color: var(--gray-900);
    font-weight: 700;
}

.group-name-input:focus {
    outline: none;
    border-color: var(--gray-300);
    background: var(--white);
}

.group-body {
    padding: 15px;
}

.question-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.question-card {
    padding: 16px;
    border: 1px solid var(--gray-200);
    border-radius: 9px;
    background: var(--white);
}

.question-top {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) 150px auto;
    gap: 10px;
    align-items: start;
}

.question-number {
    min-width: 42px;
    padding-top: 9px;
    color: var(--primary);
    font-weight: 800;
}

.question-options {
    margin-top: 14px;
    padding: 14px;
    border-radius: 8px;
    background: var(--gray-50);
}

.option-row {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) 220px auto;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
}

.option-row:last-child {
    margin-bottom: 0;
}

.option-index {
    width: 25px;
    height: 25px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    color: var(--gray-600);
    background: var(--gray-200);
    font-size: 11px;
    font-weight: 700;
}

.sticky-save {
    position: sticky;
    bottom: 15px;
    z-index: 20;
    display: flex;
    justify-content: flex-end;
    margin-top: 18px;
    padding: 12px 15px;
    border: 1px solid var(--gray-200);
    border-radius: 10px;
    background: rgba(255,255,255,.94);
    box-shadow: 0 5px 25px rgba(15,23,42,.12);
    backdrop-filter: blur(8px);
}

.switch-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.switch {
    position: relative;
    width: 40px;
    height: 22px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.switch-slider {
    position: absolute;
    inset: 0;
    cursor: pointer;
    border-radius: 999px;
    background: var(--gray-300);
    transition: .2s;
}

.switch-slider::before {
    content: "";
    position: absolute;
    width: 16px;
    height: 16px;
    left: 3px;
    top: 3px;
    border-radius: 50%;
    background: var(--white);
    transition: .2s;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}

.switch input:checked + .switch-slider {
    background: var(--primary);
}

.switch input:checked + .switch-slider::before {
    transform: translateX(18px);
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(15, 23, 42, .48);
}

.modal-backdrop.open {
    display: flex;
}

.modal {
    width: min(720px, 100%);
    max-height: calc(100vh - 40px);
    overflow: auto;
    border-radius: 12px;
    background: var(--white);
    box-shadow: 0 20px 60px rgba(15,23,42,.22);
}

.modal-header {
    padding: 17px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--gray-200);
}

.modal-title {
    margin: 0;
    color: var(--gray-900);
    font-size: 17px;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 14px 20px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid var(--gray-200);
}

.icon-button {
    width: 34px;
    height: 34px;
    padding: 0;
    display: grid;
    place-items: center;
    border: 0;
    border-radius: 6px;
    color: var(--gray-500);
    background: transparent;
    font-size: 20px;
}

.icon-button:hover {
    background: var(--gray-100);
    color: var(--gray-800);
}

.toast-container {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 2000;
    width: min(390px, calc(100% - 40px));
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.toast {
    padding: 13px 15px;
    border-radius: 8px;
    color: var(--gray-800);
    background: var(--white);
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow);
    animation: toast-in .18s ease-out;
}

.toast.success {
    border-left: 4px solid var(--success);
}

.toast.error {
    border-left: 4px solid var(--danger);
}

.toast.warning {
    border-left: 4px solid var(--warning);
}

@keyframes toast-in {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert {
    padding: 12px 14px;
    border-radius: 8px;
    border: 1px solid;
}

.alert-info {
    color: #1e40af;
    background: var(--primary-light);
    border-color: #bfdbfe;
}

.alert-success {
    color: #166534;
    background: var(--success-light);
    border-color: #bbf7d0;
}

.alert-warning {
    color: #92400e;
    background: var(--warning-light);
    border-color: #fde68a;
}

.alert-danger {
    color: #991b1b;
    background: var(--danger-light);
    border-color: #fecaca;
}

.settings-section {
    margin-bottom: 25px;
}

.settings-section:last-child {
    margin-bottom: 0;
}

.settings-section-title {
    margin: 0 0 14px;
    font-size: 17px;
    color: var(--gray-900);
}

.connection-status {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--gray-500);
    font-size: 12px;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--gray-300);
}

.status-dot.success {
    background: var(--success);
}

.status-dot.error {
    background: var(--danger);
}

.customer-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 15px;
}

.search-box {
    width: min(400px, 100%);
}

.send-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.3fr) minmax(300px, .7fr);
    gap: 18px;
}

.recipient-selection {
    max-height: 480px;
    overflow: auto;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
}

.recipient-row {
    display: grid;
    grid-template-columns: 30px minmax(0, 1fr) minmax(180px, .8fr);
    gap: 10px;
    align-items: center;
    padding: 11px 13px;
    border-bottom: 1px solid var(--gray-100);
}

.recipient-row:last-child {
    border-bottom: 0;
}

.recipient-name {
    color: var(--gray-800);
    font-weight: 600;
}

.recipient-email {
    color: var(--gray-500);
    font-size: 12px;
}

.preview-box {
    min-height: 180px;
    padding: 15px;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    background: var(--gray-50);
    white-space: pre-wrap;
    word-break: break-word;
}

.result-bar {
    height: 10px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--gray-200);
}

.result-bar-fill {
    height: 100%;
    border-radius: inherit;
    background: var(--primary);
}

.result-item {
    padding: 16px 0;
    border-bottom: 1px solid var(--gray-200);
}

.result-item:last-child {
    border-bottom: 0;
}

.result-question {
    margin-bottom: 12px;
    color: var(--gray-900);
    font-weight: 700;
}

.answer-text {
    padding: 10px 12px;
    margin-bottom: 6px;
    border-radius: 7px;
    background: var(--gray-50);
    white-space: pre-wrap;
    word-break: break-word;
}

.answer-page {
    width: min(820px, calc(100% - 30px));
    margin: 0 auto;
    padding: 45px 0 70px;
}

.answer-brand {
    margin-bottom: 22px;
    color: var(--gray-500);
    font-size: 13px;
    font-weight: 700;
}

.answer-title {
    margin: 0;
    color: var(--gray-900);
    font-size: 30px;
    line-height: 1.4;
}

.answer-description {
    margin: 12px 0 0;
    color: var(--gray-600);
    white-space: pre-wrap;
}

.answer-question {
    padding: 20px;
    margin-top: 18px;
    border: 1px solid var(--gray-200);
    border-radius: 11px;
    background: var(--white);
    box-shadow: var(--shadow-sm);
}

.answer-question-title {
    margin: 0 0 13px;
    color: var(--gray-900);
    font-size: 16px;
}

.answer-choice {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    padding: 9px 0;
}

.answer-choice input {
    margin-top: 4px;
}

.answer-complete {
    padding: 55px 30px;
    text-align: center;
}

.answer-complete-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 18px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    color: var(--white);
    background: var(--success);
    font-size: 30px;
}

.mobile-only {
    display: none;
}

@media (max-width: 1000px) {
    .header-inner {
        width: calc(100% - 25px);
        gap: 10px;
    }

    .brand {
        min-width: auto;
    }

    .brand-text {
        display: none;
    }

    .main-nav {
        flex: 1;
        overflow-x: auto;
    }

    .nav-button {
        padding: 0 10px;
        white-space: nowrap;
    }

    .app-main {
        width: calc(100% - 25px);
    }

    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .send-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .app-header {
        height: auto;
    }

    .header-inner {
        flex-wrap: wrap;
        padding-top: 8px;
    }

    .main-nav {
        order: 3;
        width: 100%;
        height: 48px;
    }

    .nav-button {
        height: 48px;
    }

    .app-main {
        padding-top: 20px;
    }

    .page-header {
        flex-direction: column;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full {
        grid-column: auto;
    }

    .question-top {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .question-top > :nth-child(3),
    .question-top > :nth-child(4) {
        grid-column: 2;
    }

    .option-row {
        grid-template-columns: auto minmax(0, 1fr) auto;
    }

    .option-row select {
        grid-column: 2 / -1;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .customer-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .search-box {
        width: 100%;
    }

    .recipient-row {
        grid-template-columns: 28px minmax(0, 1fr);
    }

    .recipient-row .recipient-email {
        grid-column: 2;
    }
}

.hidden {
    display: none !important;
}

.text-muted {
    color: var(--gray-500);
}

.text-danger {
    color: var(--danger);
}

.text-success {
    color: var(--success);
}

.text-right {
    text-align: right;
}

.mt-0 {
    margin-top: 0 !important;
}

.mt-10 {
    margin-top: 10px;
}

.mt-15 {
    margin-top: 15px;
}

.mb-0 {
    margin-bottom: 0 !important;
}

.mb-10 {
    margin-bottom: 10px;
}

.mb-15 {
    margin-bottom: 15px;
}

.flex {
    display: flex;
}

.flex-between {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.gap-8 {
    gap: 8px;
}

.gap-10 {
    gap: 10px;
}

.wrap {
    flex-wrap: wrap;
}

.w-100 {
    width: 100%;
}

</style>
</head>
<body>
<header id="app-header">
    <div class="header-container">
        <div class="logo" id="app-logo">📋 アンケート業務運営アプリ</div>
        <nav>
            <ul>
                <li><button type="button" id="nav-surveys">アンケート一覧</button></li>
                <li><button type="button" id="nav-new">新規アンケート作成</button></li>
                <li><button type="button" id="nav-customers">顧客一覧</button></li>
                <li><button type="button" id="nav-settings">設定</button></li>
            </ul>
        </nav>
    </div>

    <div id="sub-nav-bar" class="sub-nav" style="display:none;">
        <div class="sub-nav-container">
            <div class="sub-nav-title">
                選択中：<span id="current-survey-name"></span>
            </div>
            <ul>
                <li><button type="button" id="sub-detail">アンケート内容</button></li>
                <li><button type="button" id="sub-send">送信</button></li>
                <li><button type="button" id="sub-status">回答状況</button></li>
                <li><button type="button" id="sub-result">回答結果</button></li>
                <li>
                    <button type="button" class="btn btn-outline btn-sm" id="btn-preview">
                        回答画面プレビュー
                    </button>
                </li>
            </ul>
        </div>
    </div>
</header>

<main id="main-content"></main>

<div id="toast" class="toast" role="status" aria-live="polite"></div>

<div id="modal" class="modal-overlay" aria-hidden="true">
    <div class="modal">
        <h3 id="modal-title" style="margin-bottom:.75rem;">確認</h3>
        <div id="modal-body" style="font-size:.9rem;margin-bottom:1.25rem;"></div>
        <div style="display:flex;justify-content:flex-end;gap:.5rem;">
            <button type="button" class="btn btn-outline" id="modal-cancel">
                キャンセル
            </button>
            <button type="button" id="modal-confirm-btn" class="btn btn-primary">
                実行
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const APP = {
        csrf: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        api: 'index.php',
        view: 'survey-list',
        subView: 'detail',
        surveyId: null,
        dirty: false
    };

    const $ = (id) => document.getElementById(id);

    function setLoading(button, loading) {
        if (!button) {
            return;
        }

        if (loading) {
            button.disabled = true;
            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');
        } else {
            button.disabled = false;
            button.classList.remove('is-loading');
            button.removeAttribute('aria-busy');
        }
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value === null || value === undefined ? '' : String(value);
        return div.innerHTML;
    }

    function showToast(message) {
        const toast = $('toast');
        if (!toast) {
            return;
        }

        toast.textContent = String(message || '');
        toast.style.display = 'block';

        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }

    function modal(title, message, callback) {
        const overlay = $('modal');
        const titleEl = $('modal-title');
        const bodyEl = $('modal-body');
        const confirm = $('modal-confirm-btn');

        if (!overlay || !titleEl || !bodyEl || !confirm) {
            return;
        }

        titleEl.textContent = title || '確認';
        bodyEl.textContent = message || '';

        overlay.style.display = 'flex';
        overlay.setAttribute('aria-hidden', 'false');

        confirm.onclick = async () => {
            setLoading(confirm, true);

            try {
                if (typeof callback === 'function') {
                    await callback();
                }
            } finally {
                setLoading(confirm, false);
                closeModal();
            }
        };
    }

    function closeModal() {
        const overlay = $('modal');
        if (!overlay) {
            return;
        }

        overlay.style.display = 'none';
        overlay.setAttribute('aria-hidden', 'true');

        const confirm = $('modal-confirm-btn');
        if (confirm) {
            confirm.onclick = null;
            setLoading(confirm, false);
        }
    }

    async function request(action, data = {}, button = null) {
        if (button) {
            button.disabled = true;
            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');
        }

        try {
            const body = new URLSearchParams();

            body.set('action', action);
            body.set('csrf_token', APP.csrf);

            Object.keys(data).forEach((key) => {
                const value = data[key];

                if (value !== undefined && value !== null) {
                    if (typeof value === 'object') {
                        body.set(key, JSON.stringify(value));
                    } else {
                        body.set(key, String(value));
                    }
                }
            });

            const response = await fetch(APP.api, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CSRF-Token': APP.csrf,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            });

            const result = await response.json();

            if (!result || typeof result !== 'object') {
                throw new Error('サーバーから正しい応答を取得できませんでした。');
            }

            if (!result.success) {
                throw new Error(result.message || '処理に失敗しました。');
            }

            return result;
        } catch (error) {
            showToast(error instanceof Error ? error.message : '通信エラーが発生しました。');
            throw error;
        } finally {
            if (button) {
                button.disabled = false;
                button.classList.remove('is-loading');
                button.removeAttribute('aria-busy');
            }
        }
    }

    async function loadView(view, surveyId = null) {
        APP.view = view;

        if (surveyId !== null && surveyId !== undefined) {
            APP.surveyId = Number(surveyId);
        }

        const main = $('main-content');
        if (!main) {
            return;
        }

        main.textContent = '読み込み中…';

        try {
            const result = await request('get_view', {
                view: APP.view,
                survey_id: APP.surveyId || ''
            });

            if (result.html) {
                main.innerHTML = result.html;
            } else {
                main.textContent = '表示する内容がありません。';
            }

            updateNavigation();
            bindDynamicEvents();
        } catch (error) {
            main.textContent = '画面の読み込みに失敗しました。';
        }
    }

    function updateNavigation() {
        const navMap = {
            'survey-list': 'nav-surveys',
            'survey-editor': 'nav-new',
            'customer-list': 'nav-customers',
            'settings': 'nav-settings'
        };

        Object.values(navMap).forEach((id) => {
            const element = $(id);
            if (element) {
                element.classList.remove('active');
            }
        });

        const activeId = navMap[APP.view];

        if (activeId) {
            const active = $(activeId);
            if (active) {
                active.classList.add('active');
            }
        }

        const subNav = $('sub-nav-bar');

        if (!subNav) {
            return;
        }

        if (APP.surveyId && ['survey-detail', 'survey-send', 'survey-status', 'survey-result'].includes(APP.view)) {
            subNav.style.display = 'block';

            const name = $('current-survey-name');
            if (name) {
                name.textContent = window.currentSurveyName || '';
            }

            const subMap = {
                'survey-detail': 'sub-detail',
                'survey-send': 'sub-send',
                'survey-status': 'sub-status',
                'survey-result': 'sub-result'
            };

            Object.values(subMap).forEach((id) => {
                const button = $(id);
                if (button) {
                    button.classList.remove('active');
                }
            });

            const active = $(subMap[APP.view]);
            if (active) {
                active.classList.add('active');
            }
        } else {
            subNav.style.display = 'none';
        }
    }

    function navigate(view, surveyId = null) {
        if (APP.dirty) {
            const leave = window.confirm(
                '保存されていない変更があります。画面を移動してよろしいですか？'
            );

            if (!leave) {
                return;
            }

            APP.dirty = false;
        }

        loadView(view, surveyId);
    }

    function navigateSub(subView) {
        if (!APP.surveyId) {
            return;
        }

        const viewMap = {
            detail: 'survey-detail',
            send: 'survey-send',
            status: 'survey-status',
            result: 'survey-result'
        };

        const target = viewMap[subView];

        if (!target) {
            return;
        }

        loadView(target, APP.surveyId);
    }

    function startNewSurvey() {
        navigate('survey-editor', null);
    }

    function bindDynamicEvents() {
        const saveButton = $('survey-save');

        if (saveButton) {
            saveButton.addEventListener('click', async () => {
                APP.dirty = false;

                try {
                    const form = $('survey-editor-form');

                    if (!form) {
                        return;
                    }

                    const formData = new FormData(form);
                    const payload = {};

                    formData.forEach((value, key) => {
                        payload[key] = value;
                    });

                    const result = await request(
                        'save_survey',
                        payload,
                        saveButton
                    );

                    if (result.survey_id) {
                        APP.surveyId = Number(result.survey_id);
                    }

                    APP.dirty = false;
                    showToast('アンケートを保存しました。');

                    await loadView('survey-editor', APP.surveyId);
                } catch (error) {
                    APP.dirty = true;
                }
            });
        }

        const editor = $('survey-editor-form');

        if (editor) {
            editor.addEventListener('input', () => {
                APP.dirty = true;
            });

            editor.addEventListener('change', () => {
                APP.dirty = true;
            });
        }

        const publishButton = $('survey-publish');

        if (publishButton) {
            publishButton.addEventListener('click', async () => {
                modal(
                    'アンケートを公開',
                    '内容を確認した上でアンケートを公開します。よろしいですか？',
                    async () => {
                        await request(
                            'publish_survey',
                            { survey_id: APP.surveyId },
                            publishButton
                        );

                        APP.dirty = false;
                        showToast('アンケートを公開しました。');
                        await loadView('survey-detail', APP.surveyId);
                    }
                );
            });
        }

        const closeButton = $('survey-close');

        if (closeButton) {
            closeButton.addEventListener('click', async () => {
                modal(
                    'アンケートを終了',
                    '回答受付を終了します。終了後は回答を受け付けられません。よろしいですか？',
                    async () => {
                        await request(
                            'close_survey',
                            { survey_id: APP.surveyId },
                            closeButton
                        );

                        showToast('アンケートを終了しました。');
                        await loadView('survey-detail', APP.surveyId);
                    }
                );
            });
        }

        const deleteButton = $('survey-delete');

        if (deleteButton) {
            deleteButton.addEventListener('click', async () => {
                modal(
                    'アンケートを削除',
                    '下書きアンケートを削除します。この操作は取り消せません。よろしいですか？',
                    async () => {
                        await request(
                            'delete_survey',
                            { survey_id: APP.surveyId },
                            deleteButton
                        );

                        APP.surveyId = null;
                        showToast('アンケートを削除しました。');
                        await loadView('survey-list');
                    }
                );
            });
        }

        const sendButton = $('send-mail');

        if (sendButton) {
            sendButton.addEventListener('click', async () => {
                const selected = Array.from(
                    document.querySelectorAll('input[name="recipient_ids[]"]:checked')
                ).map((element) => element.value);

                const subject = $('mail-subject');
                const body = $('mail-body');

                if (selected.length === 0) {
                    showToast('送信対象者を選択してください。');
                    return;
                }

                modal(
                    '送信内容の最終確認',
                    `送信対象者数：${selected.length}名\n件名：${subject ? subject.value : ''}`,
                    async () => {
                        await request(
                            'send_mail',
                            {
                                survey_id: APP.surveyId,
                                recipient_ids: selected,
                                subject: subject ? subject.value : '',
                                body: body ? body.value : ''
                            },
                            sendButton
                        );

                        showToast('メール送信処理が完了しました。');
                        await loadView('survey-send', APP.surveyId);
                    }
                );
            });
        }

        const syncButton = $('kintone-sync');

        if (syncButton) {
            syncButton.addEventListener('click', async () => {
                try {
                    await request('sync_customers', {}, syncButton);
                    showToast('キントーンから顧客情報を取得しました。');
                    await loadView('customer-list');
                } catch (error) {
                    // request() 側で表示済み
                }
            });
        }

        const smtpTestButton = $('smtp-test');

        if (smtpTestButton) {
            smtpTestButton.addEventListener('click', async () => {
                try {
                    await request(
                        'test_smtp',
                        collectSettings(),
                        smtpTestButton
                    );

                    showToast('SMTP接続確認に成功しました。');
                } catch (error) {
                    // request() 側で表示済み
                }
            });
        }

        const kintoneTestButton = $('kintone-test');

        if (kintoneTestButton) {
            kintoneTestButton.addEventListener('click', async () => {
                try {
                    await request(
                        'test_kintone',
                        collectSettings(),
                        kintoneTestButton
                    );

                    showToast('キントーン接続確認に成功しました。');
                } catch (error) {
                    // request() 側で表示済み
                }
            });
        }

        const settingsSaveButton = $('settings-save');

        if (settingsSaveButton) {
            settingsSaveButton.addEventListener('click', async () => {
                try {
                    await request(
                        'save_settings',
                        collectSettings(),
                        settingsSaveButton
                    );

                    showToast('設定を保存しました。');
                } catch (error) {
                    // request() 側で表示済み
                }
            });
        }

        document.querySelectorAll('[data-action]').forEach((element) => {
            element.addEventListener('click', async () => {
                const action = element.getAttribute('data-action');

                if (!action) {
                    return;
                }

                if (action === 'open-survey') {
                    const id = element.getAttribute('data-id');

                    if (id) {
                        navigate('survey-detail', Number(id));
                    }

                    return;
                }

                if (action === 'edit-survey') {
                    const id = element.getAttribute('data-id');

                    if (id) {
                        navigate('survey-editor', Number(id));
                    }

                    return;
                }

                if (action === 'delete-survey') {
                    const id = element.getAttribute('data-id');

                    if (!id) {
                        return;
                    }

                    modal(
                        'アンケートを削除',
                        '下書きアンケートを削除します。よろしいですか？',
                        async () => {
                            await request(
                                'delete_survey',
                                { survey_id: id },
                                element
                            );

                            showToast('アンケートを削除しました。');
                            await loadView('survey-list');
                        }
                    );

                    return;
                }

                if (action === 'publish-survey') {
                    const id = element.getAttribute('data-id');

                    if (!id) {
                        return;
                    }

                    modal(
                        'アンケートを公開',
                        'アンケートを公開します。よろしいですか？',
                        async () => {
                            await request(
                                'publish_survey',
                                { survey_id: id },
                                element
                            );

                            showToast('アンケートを公開しました。');
                            await loadView('survey-list');
                        }
                    );

                    return;
                }

                if (action === 'close-survey') {
                    const id = element.getAttribute('data-id');

                    if (!id) {
                        return;
                    }

                    modal(
                        'アンケートを終了',
                        '回答受付を終了します。よろしいですか？',
                        async () => {
                            await request(
                                'close_survey',
                                { survey_id: id },
                                element
                            );

                            showToast('アンケートを終了しました。');
                            await loadView('survey-list');
                        }
                    );
                }
            });
        });

        bindQuestionEditorEvents();
        bindCustomerSelectionEvents();
        bindBranchEvents();
    }

    function collectSettings() {
        const result = {};

        document.querySelectorAll('[data-setting]').forEach((element) => {
            const key = element.getAttribute('data-setting');

            if (!key) {
                return;
            }

            result[key] = element.value || '';
        });

        return result;
    }

    function bindQuestionEditorEvents() {
        const addGroup = $('add-group');

        if (addGroup) {
            addGroup.addEventListener('click', () => {
                const groups = $('groups-container');

                if (!groups) {
                    return;
                }

                const template = document.querySelector('[data-group-template]');

                if (!template) {
                    return;
                }

                const clone = template.content.cloneNode(true);
                groups.appendChild(clone);
                renumberQuestions();
            });
        }

        document.querySelectorAll('[data-add-question]').forEach((button) => {
            button.addEventListener('click', () => {
                const group = button.closest('[data-group]');

                if (!group) {
                    return;
                }

                const template = document.querySelector('[data-question-template]');

                if (!template) {
                    return;
                }

                const questions = group.querySelector('[data-questions]');

                if (!questions) {
                    return;
                }

                questions.appendChild(
                    template.content.cloneNode(true)
                );

                renumberQuestions();
                bindQuestionEditorEvents();
            });
        });

        document.querySelectorAll('[data-delete-group]').forEach((button) => {
            button.addEventListener('click', () => {
                const group = button.closest('[data-group]');

                if (!group) {
                    return;
                }

                const questions = group.querySelectorAll('[data-question]');

                if (questions.length > 0) {
                    const confirmed = window.confirm(
                        'このグループには質問があります。グループと質問をすべて削除しますか？'
                    );

                    if (!confirmed) {
                        return;
                    }
                }

                group.remove();
                renumberQuestions();
                APP.dirty = true;
            });
        });

        document.querySelectorAll('[data-delete-question]').forEach((button) => {
            button.addEventListener('click', () => {
                const question = button.closest('[data-question]');

                if (!question) {
                    return;
                }

                if (!window.confirm('この質問を削除しますか？')) {
                    return;
                }

                question.remove();
                renumberQuestions();
                APP.dirty = true;
            });
        });

        document.querySelectorAll('[data-question-type]').forEach((select) => {
            select.addEventListener('change', () => {
                const question = select.closest('[data-question]');

                if (!question) {
                    return;
                }

                const choiceArea = question.querySelector('[data-choice-area]');

                if (!choiceArea) {
                    return;
                }

                if (select.value === 'single' || select.value === 'multiple') {
                    choiceArea.style.display = '';
                } else {
                    choiceArea.style.display = 'none';
                }

                APP.dirty = true;
            });
        });

        document.querySelectorAll('[data-add-choice]').forEach((button) => {
            button.addEventListener('click', () => {
                const question = button.closest('[data-question]');

                if (!question) {
                    return;
                }

                const choices = question.querySelector('[data-choices]');

                if (!choices) {
                    return;
                }

                const row = document.createElement('div');
                row.className = 'choice-row';

                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control';
                input.name = 'choice[]';

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn-danger-outline btn-sm';
                remove.textContent = '削除';

                remove.addEventListener('click', () => {
                    row.remove();
                    APP.dirty = true;
                });

                row.appendChild(input);
                row.appendChild(remove);
                choices.appendChild(row);

                APP.dirty = true;
            });
        });
    }

    function renumberQuestions() {
        const numbering =
            document.querySelector('[name="numbering_format"]');

        const format = numbering ? numbering.value : 'global';

        let globalNumber = 1;

        document.querySelectorAll('[data-group]').forEach((group, groupIndex) => {
            let groupNumber = 1;

            group.querySelectorAll('[data-question]').forEach((question) => {
                const number = question.querySelector('[data-question-number]');

                if (!number) {
                    return;
                }

                if (format === 'group') {
                    number.textContent =
                        `Q${groupIndex + 1}-${groupNumber}`;
                } else {
                    number.textContent = `Q${globalNumber}`;
                }

                globalNumber += 1;
                groupNumber += 1;
            });
        });
    }

    function bindCustomerSelectionEvents() {
        const search = $('customer-search');

        if (search) {
            search.addEventListener('input', () => {
                const keyword = search.value.trim().toLowerCase();

                document.querySelectorAll('[data-customer-row]').forEach((row) => {
                    const text = row.textContent.toLowerCase();
                    row.style.display =
                        !keyword || text.includes(keyword) ? '' : 'none';
                });
            });
        }

        const selectAll = $('customer-select-all');

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                document.querySelectorAll(
                    'input[name="recipient_ids[]"]'
                ).forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });
            });
        }
    }

    function bindBranchEvents() {
        document.querySelectorAll('[data-branch-choice]').forEach((element) => {
            element.addEventListener('change', () => {
                const question = element.closest('[data-question]');

                if (!question) {
                    return;
                }

                const target = question.querySelector(
                    '[data-branch-target]'
                );

                if (!target) {
                    return;
                }

                if (element.checked && element.value) {
                    target.disabled = false;
                }
            });
        });
    }

    const logo = $('app-logo');

    if (logo) {
        logo.addEventListener('click', () => {
            navigate('survey-list');
        });
    }

    const navSurveys = $('nav-surveys');

    if (navSurveys) {
        navSurveys.addEventListener('click', () => {
            navigate('survey-list');
        });
    }

    const navNew = $('nav-new');

    if (navNew) {
        navNew.addEventListener('click', () => {
            startNewSurvey();
        });
    }

    const navCustomers = $('nav-customers');

    if (navCustomers) {
        navCustomers.addEventListener('click', () => {
            navigate('customer-list');
        });
    }

    const navSettings = $('nav-settings');

    if (navSettings) {
        navSettings.addEventListener('click', () => {
            navigate('settings');
        });
    }

    const subDetail = $('sub-detail');

    if (subDetail) {
        subDetail.addEventListener('click', () => {
            navigateSub('detail');
        });
    }

    const subSend = $('sub-send');

    if (subSend) {
        subSend.addEventListener('click', () => {
            navigateSub('send');
        });
    }

    const subStatus = $('sub-status');

    if (subStatus) {
        subStatus.addEventListener('click', () => {
            navigateSub('status');
        });
    }

    const subResult = $('sub-result');

    if (subResult) {
        subResult.addEventListener('click', () => {
            navigateSub('result');
        });
    }

    const preview = $('btn-preview');

    if (preview) {
        preview.addEventListener('click', () => {
            navigate('respondent-preview', APP.surveyId);
        });
    }

    const modalCancel = $('modal-cancel');

    if (modalCancel) {
        modalCancel.addEventListener('click', () => {
            closeModal();
        });
    }

    const modalOverlay = $('modal');

    if (modalOverlay) {
        modalOverlay.addEventListener('click', (event) => {
            if (event.target === modalOverlay) {
                closeModal();
            }
        });
    }

    window.addEventListener('beforeunload', (event) => {
        if (!APP.dirty) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });

    navigate('survey-list');
});
</script>
</body>
</html>

<?php
declare(strict_types=1);

namespace yokoyamy\trial\newapp;

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
]);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

const SESSION_KEY = 'yokoyamy_trial_newapp';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SURVEY_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMER_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_session(): array
{
    if (!isset($_SESSION[SESSION_KEY]) || !is_array($_SESSION[SESSION_KEY])) {
        $_SESSION[SESSION_KEY] = [];
    }

    return $_SESSION[SESSION_KEY];
}

function set_app_session(string $key, $value): void
{
    if (!isset($_SESSION[SESSION_KEY]) || !is_array($_SESSION[SESSION_KEY])) {
        $_SESSION[SESSION_KEY] = [];
    }

    $_SESSION[SESSION_KEY][$key] = $value;
}

function get_app_session(string $key, $default = null)
{
    if (!isset($_SESSION[SESSION_KEY]) || !is_array($_SESSION[SESSION_KEY])) {
        return $default;
    }

    return $_SESSION[SESSION_KEY][$key] ?? $default;
}

if (!isset($_SESSION[SESSION_KEY]['csrf_token'])) {
    set_app_session('csrf_token', bin2hex(random_bytes(32)));
}

function csrf_token(): string
{
    return (string)get_app_session('csrf_token', '');
}

function json_response(array $response): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function require_csrf(): void
{
    $token = '';

    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (isset($_POST['csrf_token'])) {
        $token = (string)$_POST['csrf_token'];
    }

    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        json_response([
            'success' => false,
            'message' => 'セキュリティ確認に失敗しました。画面を再読み込みしてください。'
        ]);
    }
}

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) {
            throw new \RuntimeException('データ保存領域を作成できません。');
        }
    }
}

function read_json_file(string $file, array $default): array
{
    ensure_data_dir();

    if (!is_file($file)) {
        write_json_file($file, $default);
        return $default;
    }

    $contents = @file_get_contents($file);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $data = json_decode($contents, true);

    return is_array($data) ? $data : $default;
}

function write_json_file(string $file, array $data): void
{
    ensure_data_dir();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new \RuntimeException('データを保存できません。');
    }

    $tmp = $file . '.tmp';

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new \RuntimeException('データファイルへ書き込めません。');
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new \RuntimeException('データファイルを更新できません。');
    }
}

function default_surveys(): array
{
    return [
        [
            'id' => 1,
            'name' => '新商品アンケート',
            'description' => '新商品の利用状況とご意見をお聞きするアンケートです。',
            'status' => 'open',
            'created' => '2026-09-01',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'answers' => 128,
            'target' => 200,
            'sent' => 195,
            'updated' => '2026-09-20',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => 101,
                    'name' => 'ご利用状況',
                    'questions' => [
                        [
                            'id' => 1001,
                            'text' => '当社の商品を利用したことがありますか？',
                            'type' => 'single',
                            'required' => true,
                            'options' => [
                                ['text' => 'はい', 'branch' => ''],
                                ['text' => 'いいえ', 'branch' => '1003']
                            ]
                        ],
                        [
                            'id' => 1002,
                            'text' => '商品についての満足度を教えてください。',
                            'type' => 'single',
                            'required' => true,
                            'options' => [
                                ['text' => '満足', 'branch' => ''],
                                ['text' => '普通', 'branch' => ''],
                                ['text' => '不満', 'branch' => '1003']
                            ]
                        ]
                    ]
                ],
                [
                    'id' => 102,
                    'name' => 'ご意見',
                    'questions' => [
                        [
                            'id' => 1003,
                            'text' => '今後の商品についてご意見をお聞かせください。',
                            'type' => 'free',
                            'required' => false,
                            'options' => []
                        ]
                    ]
                ]
            ]
        ],
        [
            'id' => 2,
            'name' => 'サービス利用後アンケート',
            'description' => 'サービスをご利用いただいた感想をお聞きします。',
            'status' => 'draft',
            'created' => '2026-09-10',
            'start' => '',
            'end' => '',
            'answers' => 0,
            'target' => 0,
            'sent' => 0,
            'updated' => '2026-09-21',
            'numbering' => 'group',
            'groups' => [
                [
                    'id' => 201,
                    'name' => 'サービスについて',
                    'questions' => [
                        [
                            'id' => 2001,
                            'text' => 'サービスについての感想を教えてください。',
                            'type' => 'multiple',
                            'required' => false,
                            'options' => [
                                ['text' => '便利だった', 'branch' => ''],
                                ['text' => '分かりやすかった', 'branch' => ''],
                                ['text' => 'また利用したい', 'branch' => '']
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];
}

function default_settings(): array
{
    return [
        'mail' => [
            'smtp' => '',
            'port' => '587',
            'security' => 'STARTTLS',
            'username' => '',
            'from' => '',
            'fromName' => 'アンケート事務局'
        ],
        'kintone' => [
            'domain' => '',
            'appId' => '',
            'loginName' => '',
            'proxyHostPort' => '',
            'sslVerify' => false,
            'fields' => [
                'name' => '顧客名',
                'email' => 'メールアドレス',
                'company' => '会社名',
                'code' => '顧客番号'
            ]
        ]
    ];
}

function get_surveys(): array
{
    return read_json_file(SURVEY_FILE, default_surveys());
}

function save_surveys(array $surveys): void
{
    write_json_file(SURVEY_FILE, array_values($surveys));
}

function get_customers(): array
{
    return read_json_file(CUSTOMER_FILE, []);
}

function save_customers(array $customers): void
{
    write_json_file(CUSTOMER_FILE, array_values($customers));
}

function get_settings(): array
{
    $default = default_settings();
    $settings = read_json_file(SETTINGS_FILE, $default);

    if (!isset($settings['mail']) || !is_array($settings['mail'])) {
        $settings['mail'] = $default['mail'];
    }

    if (!isset($settings['kintone']) || !is_array($settings['kintone'])) {
        $settings['kintone'] = $default['kintone'];
    }

    if (!isset($settings['kintone']['fields']) || !is_array($settings['kintone']['fields'])) {
        $settings['kintone']['fields'] = $default['kintone']['fields'];
    }

    return $settings;
}

function save_settings(array $settings): void
{
    write_json_file(SETTINGS_FILE, $settings);
}

function request_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function normalize_proxy(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/^https?:\/\//i', '', $value);
    $value = rtrim((string)$value, '/');

    if ($value === '') {
        return '';
    }

    if (!preg_match('/^[a-zA-Z0-9._-]+:\d{1,5}$/', $value)) {
        throw new \InvalidArgumentException(
            'プロキシは「ホスト名:ポート番号」の形式で入力してください。'
        );
    }

    return $value;
}

function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim((string)$domain, '/');

    if ($domain === '') {
        throw new \InvalidArgumentException('kintoneの利用先を入力してください。');
    }

    if (!preg_match('/^[a-zA-Z0-9.-]+$/', $domain)) {
        throw new \InvalidArgumentException('kintoneの利用先が正しくありません。');
    }

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    return [];
}

function get_http_status(array $headers): int
{
    if (empty($headers)) {
        return 0;
    }

    foreach (array_reverse($headers) as $header) {
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $header, $matches)) {
            return (int)$matches[1];
        }
    }

    return 0;
}

function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 30,
        'protocol_version' => 1.1
    ];

    if ($method !== 'GET' && $payload !== null) {
        if (is_array($payload)) {
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($encoded === false) {
                return [
                    'success' => false,
                    'status' => 0,
                    'message' => '送信データを作成できません。'
                ];
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
            'allow_self_signed' => true
        ]
    ];

    /*
     * プロキシ設定は空でも常に受け取れる構造にする。
     * 値がある場合は proxy / request_fulluri を必ず適用する。
     */
    $proxyHostPort = trim((string)($config['proxy_host_port'] ?? ''));

    if ($proxyHostPort !== '') {
        try {
            $proxyHostPort = normalize_proxy($proxyHostPort);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => $e->getMessage()
            ];
        }

        $contextOptions['http']['proxy'] = 'tcp://' . $proxyHostPort;
        $contextOptions['http']['request_fulluri'] = true;
    } else {
        /*
         * プロキシ未設定時は通常通信。
         * 設定値自体は常に受け取れる。
         */
        $contextOptions['http']['request_fulluri'] = false;
    }

    $context = stream_context_create($contextOptions);

    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = get_safe_response_headers();
    $statusCode = get_http_status($responseHeaders);

    $decoded = [];

    if (is_string($responseBody) && $responseBody !== '') {
        $tmp = json_decode($responseBody, true);
        if (is_array($tmp)) {
            $decoded = $tmp;
        }
    }

    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'success' => true,
            'status' => $statusCode,
            'data' => $decoded
        ];
    }

    $message = 'kintone APIとの通信に失敗しました。';

    if (isset($decoded['message']) && is_string($decoded['message'])) {
        $message = $decoded['message'];
    }

    $details = [];

    if (isset($decoded['code']) && is_string($decoded['code'])) {
        $details[] = 'コード: ' . $decoded['code'];
    }

    if (isset($decoded['errors']) && is_array($decoded['errors'])) {
        foreach ($decoded['errors'] as $field => $error) {
            if (!is_array($error)) {
                continue;
            }

            if (isset($error['messages']) && is_array($error['messages'])) {
                foreach ($error['messages'] as $errorMessage) {
                    if (is_string($errorMessage)) {
                        $details[] = (string)$field . ': ' . $errorMessage;
                    }
                }
            }
        }
    }

    if (!empty($details)) {
        $message .= ' ' . implode(' / ', $details);
    }

    if ($statusCode === 0 && $responseBody === false) {
        $message = 'kintoneへ接続できませんでした。利用先、プロキシ設定、ネットワーク環境を確認してください。';
    }

    return [
        'success' => false,
        'status' => $statusCode,
        'message' => $message
    ];
}

function make_cybozu_auth_header(string $loginName, string $password): string
{
    $loginName = trim($loginName);
    $password = trim($password);

    return 'X-Cybozu-Authorization: ' .
        base64_encode($loginName . ':' . $password);
}

function find_survey_index(array $surveys, $id): int
{
    foreach ($surveys as $index => $survey) {
        if ((string)($survey['id'] ?? '') === (string)$id) {
            return $index;
        }
    }

    return -1;
}

function sanitize_survey(array $survey): array
{
    $result = [
        'id' => isset($survey['id']) ? (int)$survey['id'] : null,
        'name' => trim((string)($survey['name'] ?? '')),
        'description' => trim((string)($survey['description'] ?? '')),
        'status' => (string)($survey['status'] ?? 'draft'),
        'created' => (string)($survey['created'] ?? ''),
        'start' => (string)($survey['start'] ?? ''),
        'end' => (string)($survey['end'] ?? ''),
        'answers' => max(0, (int)($survey['answers'] ?? 0)),
        'target' => max(0, (int)($survey['target'] ?? 0)),
        'sent' => max(0, (int)($survey['sent'] ?? 0)),
        'updated' => (string)($survey['updated'] ?? ''),
        'numbering' => (($survey['numbering'] ?? 'global') === 'group')
            ? 'group'
            : 'global',
        'groups' => []
    ];

    $groups = $survey['groups'] ?? [];

    if (!is_array($groups)) {
        $groups = [];
    }

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $newGroup = [
            'id' => isset($group['id'])
                ? (int)$group['id']
                : (int)(microtime(true) * 1000000),
            'name' => trim((string)($group['name'] ?? '')),
            'questions' => []
        ];

        $questions = $group['questions'] ?? [];

        if (!is_array($questions)) {
            $questions = [];
        }

        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            $type = (string)($question['type'] ?? 'free');

            if (!in_array($type, ['free', 'single', 'multiple'], true)) {
                $type = 'free';
            }

            $newQuestion = [
                'id' => isset($question['id'])
                    ? (int)$question['id']
                    : (int)(microtime(true) * 1000000),
                'text' => trim((string)($question['text'] ?? '')),
                'type' => $type,
                'required' => !empty($question['required']),
                'options' => []
            ];

            $options = $question['options'] ?? [];

            if (is_array($options)) {
                foreach ($options as $option) {
                    if (!is_array($option)) {
                        continue;
                    }

                    $branch = (string)($option['branch'] ?? '');

                    if ($type !== 'single') {
                        $branch = '';
                    }

                    $newQuestion['options'][] = [
                        'text' => trim((string)($option['text'] ?? '')),
                        'branch' => $branch
                    ];
                }
            }

            if (($type === 'single' || $type === 'multiple') &&
                count($newQuestion['options']) === 0) {
                $newQuestion['options'][] = [
                    'text' => '',
                    'branch' => ''
                ];
            }

            $newGroup['questions'][] = $newQuestion;
        }

        $result['groups'][] = $newGroup;
    }

    return $result;
}

function validate_survey(array $survey): array
{
    $errors = [];

    if (trim((string)($survey['name'] ?? '')) === '') {
        $errors[] = 'アンケート名を入力してください。';
    }

    if (!empty($survey['start']) && !empty($survey['end'])) {
        if ((string)$survey['start'] > (string)$survey['end']) {
            $errors[] = '公開終了日は公開開始日以降にしてください。';
        }
    }

    if (empty($survey['groups'])) {
        $errors[] = 'グループを1つ以上登録してください。';
    }

    $questionIds = [];

    foreach ($survey['groups'] as $group) {
        if (trim((string)($group['name'] ?? '')) === '') {
            $errors[] = 'グループ名を入力してください。';
        }

        foreach (($group['questions'] ?? []) as $question) {
            $qid = (string)($question['id'] ?? '');

            if ($qid !== '') {
                $questionIds[$qid] = true;
            }

            if (trim((string)($question['text'] ?? '')) === '') {
                $errors[] = '質問文を入力してください。';
            }

            $type = (string)($question['type'] ?? '');

            if (in_array($type, ['single', 'multiple'], true)) {
                if (empty($question['options'])) {
                    $errors[] = '選択肢を1つ以上登録してください。';
                }

                foreach (($question['options'] ?? []) as $option) {
                    if (trim((string)($option['text'] ?? '')) === '') {
                        $errors[] = '空の選択肢があります。';
                    }
                }
            }
        }
    }

    foreach ($survey['groups'] as $group) {
        foreach (($group['questions'] ?? []) as $question) {
            if (($question['type'] ?? '') !== 'single') {
                continue;
            }

            foreach (($question['options'] ?? []) as $option) {
                $branch = (string)($option['branch'] ?? '');

                if ($branch !== '' && $branch !== 'END' && !isset($questionIds[$branch])) {
                    $errors[] = '分岐先に存在しない質問が指定されています。';
                }

                if ($branch === (string)($question['id'] ?? '')) {
                    $errors[] = '質問自身を分岐先に指定することはできません。';
                }
            }
        }
    }

    return $errors;
}

function mail_send_message(
    string $to,
    string $subject,
    string $body,
    array $settings
): array {
    $to = trim($to);
    $subject = trim($subject);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => '送信先メールアドレスが正しくありません。'
        ];
    }

    $from = trim((string)($settings['from'] ?? ''));

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => '送信元メールアドレスが正しくありません。'
        ];
    }

    /*
     * PHP標準の mail() を利用。
     * SMTPサーバ自体はApache/PHP環境側のメール設定に従う。
     */
    $headers = [];
    $headers[] = 'From: ' . $from;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    $encodedSubject = '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $ok = @mail(
        $to,
        $encodedSubject,
        $body,
        implode("\r\n", $headers)
    );

    return [
        'success' => $ok,
        'message' => $ok
            ? 'メールを送信しました。'
            : 'メール送信に失敗しました。'
    ];
}

function handle_api(): void
{
    $action = isset($_GET['api']) ? (string)$_GET['api'] : '';

    if ($action === '') {
        return;
    }

    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response([
                'success' => false,
                'message' => 'POSTで実行してください。'
            ]);
        }

        require_csrf();

        $data = request_json_body();

        switch ($action) {
            case 'get_surveys':
                json_response([
                    'success' => true,
                    'surveys' => get_surveys()
                ]);

            case 'save_survey':
                $survey = sanitize_survey(
                    isset($data['survey']) && is_array($data['survey'])
                        ? $data['survey']
                        : []
                );

                $errors = validate_survey($survey);

                if (!empty($errors)) {
                    json_response([
                        'success' => false,
                        'message' => implode("\n", $errors)
                    ]);
                }

                $surveys = get_surveys();
                $today = date('Y-m-d');

                if ($survey['id'] === null) {
                    $maxId = 0;

                    foreach ($surveys as $item) {
                        $maxId = max($maxId, (int)($item['id'] ?? 0));
                    }

                    $survey['id'] = $maxId + 1;
                    $survey['created'] = $today;
                    $survey['updated'] = $today;
                    $survey['answers'] = 0;
                    $survey['target'] = 0;
                    $survey['sent'] = 0;

                    $surveys[] = $survey;
                    $message = 'アンケートを保存しました。';
                } else {
                    $index = find_survey_index($surveys, $survey['id']);

                    if ($index < 0) {
                        json_response([
                            'success' => false,
                            'message' => '対象のアンケートが見つかりません。'
                        ]);
                    }

                    $old = $surveys[$index];

                    $survey['created'] = (string)($old['created'] ?? $today);
                    $survey['answers'] = (int)($old['answers'] ?? 0);
                    $survey['target'] = (int)($old['target'] ?? 0);
                    $survey['sent'] = (int)($old['sent'] ?? 0);
                    $survey['updated'] = $today;

                    $surveys[$index] = $survey;
                    $message = 'アンケートを更新しました。';
                }

                save_surveys($surveys);

                json_response([
                    'success' => true,
                    'message' => $message,
                    'survey' => $survey,
                    'surveys' => $surveys
                ]);

            case 'delete_survey':
                $id = $data['id'] ?? null;
                $surveys = get_surveys();
                $index = find_survey_index($surveys, $id);

                if ($index < 0) {
                    json_response([
                        'success' => false,
                        'message' => '対象のアンケートが見つかりません。'
                    ]);
                }

                array_splice($surveys, $index, 1);
                save_surveys($surveys);

                json_response([
                    'success' => true,
                    'message' => 'アンケートを削除しました。',
                    'surveys' => $surveys
                ]);

            case 'get_customers':
                json_response([
                    'success' => true,
                    'customers' => get_customers()
                ]);

            case 'save_mail_settings':
                $settings = get_settings();

                $settings['mail'] = [
                    'smtp' => trim((string)($data['smtp'] ?? '')),
                    'port' => trim((string)($data['port'] ?? '587')),
                    'security' => trim((string)($data['security'] ?? 'STARTTLS')),
                    'username' => trim((string)($data['username'] ?? '')),
                    'from' => trim((string)($data['from'] ?? '')),
                    'fromName' => trim((string)($data['fromName'] ?? 'アンケート事務局'))
                ];

                if ($settings['mail']['smtp'] === '') {
                    json_response([
                        'success' => false,
                        'message' => 'SMTPサーバを入力してください。'
                    ]);
                }

                if (!ctype_digit($settings['mail']['port']) ||
                    (int)$settings['mail']['port'] < 1 ||
                    (int)$settings['mail']['port'] > 65535) {
                    json_response([
                        'success' => false,
                        'message' => 'ポート番号が正しくありません。'
                    ]);
                }

                if (!filter_var($settings['mail']['from'], FILTER_VALIDATE_EMAIL)) {
                    json_response([
                        'success' => false,
                        'message' => '送信元メールアドレスが正しくありません。'
                    ]);
                }

                save_settings($settings);

                json_response([
                    'success' => true,
                    'message' => 'メール送信設定を保存しました。',
                    'settings' => $settings
                ]);

            case 'save_kintone_settings':
                $settings = get_settings();

                $domain = trim((string)($data['domain'] ?? ''));
                $appId = trim((string)($data['appId'] ?? ''));
                $loginName = trim((string)($data['loginName'] ?? ''));
                $password = trim((string)($data['password'] ?? ''));
                $proxyHostPort = trim((string)($data['proxyHostPort'] ?? ''));

                if ($domain === '' || $appId === '' || $loginName === '') {
                    json_response([
                        'success' => false,
                        'message' => 'kintoneの利用先、アプリID、ログイン名を入力してください。'
                    ]);
                }

                if (!ctype_digit($appId) || (int)$appId < 1) {
                    json_response([
                        'success' => false,
                        'message' => 'アプリIDが正しくありません。'
                    ]);
                }

                if ($password === '') {
                    $password = (string)get_app_session('kintone_password', '');
                }

                if ($password === '') {
                    json_response([
                        'success' => false,
                        'message' => 'パスワードを入力してください。'
                    ]);
                }

                try {
                    $proxyHostPort = normalize_proxy($proxyHostPort);
                    kintone_build_url($domain, '/k/v1/apps.json?limit=1');
                } catch (\Throwable $e) {
                    json_response([
                        'success' => false,
                        'message' => $e->getMessage()
                    ]);
                }

                $settings['kintone'] = [
                    'domain' => $domain,
                    'appId' => $appId,
                    'loginName' => $loginName,
                    'proxyHostPort' => $proxyHostPort,
                    'sslVerify' => false,
                    'fields' => [
                        'name' => trim((string)($data['fieldName'] ?? '顧客名')),
                        'email' => trim((string)($data['fieldEmail'] ?? 'メールアドレス')),
                        'company' => trim((string)($data['fieldCompany'] ?? '会社名')),
                        'code' => trim((string)($data['fieldCode'] ?? '顧客番号'))
                    ]
                ];

                /*
                 * パスワードはJSONファイルへ保存しない。
                 * セッションにのみ保持する。
                 */
                set_app_session('kintone_password', $password);

                save_settings($settings);

                json_response([
                    'success' => true,
                    'message' => 'kintone設定を保存しました。',
                    'settings' => $settings
                ]);

            case 'test_kintone':
                $settings = get_settings();
                $kt = $settings['kintone'];

                $domain = trim((string)($data['domain'] ?? $kt['domain'] ?? ''));
                $appId = trim((string)($data['appId'] ?? $kt['appId'] ?? ''));
                $loginName = trim((string)($data['loginName'] ?? $kt['loginName'] ?? ''));
                $password = trim((string)($data['password'] ?? ''));
                $proxyHostPort = trim((string)($data['proxyHostPort'] ?? $kt['proxyHostPort'] ?? ''));

                if ($password === '') {
                    $password = (string)get_app_session('kintone_password', '');
                }

                if ($domain === '' || $appId === '' || $loginName === '' || $password === '') {
                    json_response([
                        'success' => false,
                        'message' => 'kintone接続に必要な情報を入力してください。'
                    ]);
                }

                $proxyHostPort = normalize_proxy($proxyHostPort);

                $url = kintone_build_url(
                    $domain,
                    '/k/v1/app.json?' .
                    http_build_query(
                        ['id' => (int)$appId],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    )
                );

                $headers = [
                    make_cybozu_auth_header($loginName, $password),
                    'Accept: application/json'
                ];

                $result = kintone_api_request(
                    'GET',
                    $url,
                    $headers,
                    null,
                    [
                        'proxy_host_port' => $proxyHostPort
                    ]
                );

                if (!$result['success']) {
                    json_response([
                        'success' => false,
                        'message' => $result['message']
                    ]);
                }

                json_response([
                    'success' => true,
                    'message' => 'kintoneへの接続を確認しました。',
                    'app' => $result['data']
                ]);

            case 'refresh_customers':
                $settings = get_settings();
                $kt = $settings['kintone'];

                $domain = trim((string)($data['domain'] ?? $kt['domain'] ?? ''));
                $appId = trim((string)($data['appId'] ?? $kt['appId'] ?? ''));
                $loginName = trim((string)($data['loginName'] ?? $kt['loginName'] ?? ''));
                $password = trim((string)($data['password'] ?? ''));
                $proxyHostPort = trim((string)($data['proxyHostPort'] ?? $kt['proxyHostPort'] ?? ''));

                if ($password === '') {
                    $password = (string)get_app_session('kintone_password', '');
                }

                if ($domain === '' || $appId === '' || $loginName === '' || $password === '') {
                    json_response([
                        'success' => false,
                        'message' => '先にkintone設定を入力してください。'
                    ]);
                }

                $proxyHostPort = normalize_proxy($proxyHostPort);

                $fields = $kt['fields'] ?? [];
                $nameField = trim((string)($fields['name'] ?? '顧客名'));
                $emailField = trim((string)($fields['email'] ?? 'メールアドレス'));
                $companyField = trim((string)($fields['company'] ?? '会社名'));
                $codeField = trim((string)($fields['code'] ?? '顧客番号'));

                $params = [
                    'app' => (int)$appId,
                    'query' => 'order by $id asc limit 500'
                ];

                $query = http_build_query(
                    $params,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );

                $url = kintone_build_url(
                    $domain,
                    '/k/v1/records.json?' . $query
                );

                $headers = [
                    make_cybozu_auth_header($loginName, $password),
                    'Accept: application/json'
                ];

                $result = kintone_api_request(
                    'GET',
                    $url,
                    $headers,
                    null,
                    [
                        'proxy_host_port' => $proxyHostPort
                    ]
                );

                if (!$result['success']) {
                    json_response([
                        'success' => false,
                        'message' => $result['message']
                    ]);
                }

                $records = $result['data']['records'] ?? [];

                if (!is_array($records)) {
                    $records = [];
                }

                $customers = [];

                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $customers[] = [
                        'id' => count($customers) + 1,
                        'name' => extract_kintone_value($record, $nameField),
                        'email' => extract_kintone_value($record, $emailField),
                        'company' => extract_kintone_value($record, $companyField),
                        'code' => extract_kintone_value($record, $codeField)
                    ];
                }

                save_customers($customers);

                json_response([
                    'success' => true,
                    'message' => count($customers) . '件の顧客情報を取得しました。',
                    'customers' => $customers
                ]);

            case 'send_survey_mail':
                $surveyId = $data['surveyId'] ?? null;
                $customerIds = $data['customerIds'] ?? [];

                if (!is_array($customerIds)) {
                    $customerIds = [];
                }

                $surveys = get_surveys();
                $surveyIndex = find_survey_index($surveys, $surveyId);

                if ($surveyIndex < 0) {
                    json_response([
                        'success' => false,
                        'message' => '対象のアンケートが見つかりません。'
                    ]);
                }

                $survey = $surveys[$surveyIndex];
                $customers = get_customers();
                $settings = get_settings();

                $mailSettings = $settings['mail'];

                if (!filter_var(
                    (string)($mailSettings['from'] ?? ''),
                    FILTER_VALIDATE_EMAIL
                )) {
                    json_response([
                        'success' => false,
                        'message' => 'メール送信設定の送信元メールアドレスを確認してください。'
                    ]);
                }

                $selected = [];

                foreach ($customers as $customer) {
                    if (in_array((string)($customer['id'] ?? ''), array_map('strval', $customerIds), true)) {
                        $selected[] = $customer;
                    }
                }

                if (empty($selected)) {
                    json_response([
                        'success' => false,
                        'message' => '送信対象を選択してください。'
                    ]);
                }

                $subject = '【アンケート】' . (string)$survey['name'];
                $successCount = 0;
                $failureCount = 0;
                $failures = [];

                foreach ($selected as $customer) {
                    $email = trim((string)($customer['email'] ?? ''));

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $failureCount++;
                        $failures[] = [
                            'name' => (string)($customer['name'] ?? ''),
                            'message' => 'メールアドレスが正しくありません。'
                        ];
                        continue;
                    }

                    $body =
                        (string)($customer['name'] ?? '') . " 様\n\n" .
                        "アンケートへのご協力をお願いいたします。\n\n" .
                        "アンケート名：" . (string)$survey['name'] . "\n\n" .
                        "このメールはアンケート事務局から送信されています。\n";

                    $mailResult = mail_send_message(
                        $email,
                        $subject,
                        $body,
                        $mailSettings
                    );

                    if ($mailResult['success']) {
                        $successCount++;
                    } else {
                        $failureCount++;
                        $failures[] = [
                            'name' => (string)($customer['name'] ?? ''),
                            'message' => $mailResult['message']
                        ];
                    }
                }

                $survey['target'] = max(
                    (int)($survey['target'] ?? 0),
                    count($selected)
                );
                $survey['sent'] = (int)($survey['sent'] ?? 0) + $successCount;
                $survey['updated'] = date('Y-m-d');

                $surveys[$surveyIndex] = $survey;
                save_surveys($surveys);

                json_response([
                    'success' => $successCount > 0,
                    'message' => 'メール送信処理が完了しました。',
                    'successCount' => $successCount,
                    'failureCount' => $failureCount,
                    'failures' => $failures,
                    'survey' => $survey
                ]);

            default:
                json_response([
                    'success' => false,
                    'message' => '指定された処理は存在しません。'
                ]);
        }
    } catch (\Throwable $e) {
        error_log(
            'NewApp error: ' .
            get_class($e) .
            ': ' .
            $e->getMessage()
        );

        json_response([
            'success' => false,
            'message' => '処理中にエラーが発生しました。入力内容を確認してください。'
        ]);
    }
}

function extract_kintone_value(array $record, string $fieldCode): string
{
    if ($fieldCode === '' || !isset($record[$fieldCode])) {
        return '';
    }

    $field = $record[$fieldCode];

    if (!is_array($field)) {
        return '';
    }

    if (isset($field['value']) && is_scalar($field['value'])) {
        return (string)$field['value'];
    }

    return '';
}

handle_api();

$csrf = csrf_token();
$initialSurveys = get_surveys();
$initialCustomers = get_customers();
$initialSettings = get_settings();

$mailSettingsForJs = [
    'smtp' => $initialSettings['mail']['smtp'] ?? '',
    'port' => $initialSettings['mail']['port'] ?? '587',
    'security' => $initialSettings['mail']['security'] ?? 'STARTTLS',
    'username' => $initialSettings['mail']['username'] ?? '',
    'from' => $initialSettings['mail']['from'] ?? '',
    'fromName' => $initialSettings['mail']['fromName'] ?? 'アンケート事務局'
];

$kintoneSettingsForJs = [
    'domain' => $initialSettings['kintone']['domain'] ?? '',
    'appId' => $initialSettings['kintone']['appId'] ?? '',
    'loginName' => $initialSettings['kintone']['loginName'] ?? '',
    'proxyHostPort' => $initialSettings['kintone']['proxyHostPort'] ?? '',
    'fields' => $initialSettings['kintone']['fields'] ?? [
        'name' => '顧客名',
        'email' => 'メールアドレス',
        'company' => '会社名',
        'code' => '顧客番号'
    ]
];

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h($csrf) ?>">
<title>アンケート業務運営</title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Yu Gothic",Meiryo,sans-serif;
    color:#263238;
    background:#f4f6f8
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.55}
.hidden{display:none!important}
.loading{position:relative;color:transparent!important;pointer-events:none}
.loading:after{
    content:"";
    position:absolute;
    width:15px;
    height:15px;
    border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
    left:50%;
    top:50%;
    margin:-9px 0 0 -9px
}
.btn:not(.btn-primary).loading:after{border-color:#aeb8c2;border-top-color:#4b5d6b}
@keyframes spin{to{transform:rotate(360deg)}}
.topbar{
    min-height:60px;
    background:#1f3a5f;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:30px
}
.logo{font-size:18px;font-weight:bold;white-space:nowrap}
.main-nav{display:flex;height:60px;align-items:center;gap:2px}
.main-nav button{
    height:100%;
    padding:0 17px;
    color:#dce7f3;
    background:transparent;
    border:0
}
.main-nav button:hover,.main-nav button.active{background:#31557f;color:#fff}
.app{max-width:1440px;margin:0 auto;padding:24px}
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:20px
}
.page-header h1{margin:0;font-size:25px}
.subtext{color:#718096;font-size:13px;margin-top:5px}
.btn{
    border:1px solid #cbd5e0;
    background:#fff;
    color:#34495e;
    border-radius:5px;
    padding:8px 15px
}
.btn:hover{background:#f7fafc}
.btn-primary{background:#2878c8;border-color:#2878c8;color:#fff}
.btn-primary:hover{background:#2068ad}
.btn-danger{border-color:#e05a5a;color:#c53f3f;background:#fff}
.btn-small{padding:5px 10px;font-size:12px}
.btn-success{background:#2f855a;border-color:#2f855a;color:#fff}
.card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:20px;
    margin-bottom:18px
}
.card-title{font-size:17px;font-weight:bold;margin-bottom:15px}
.table{width:100%;border-collapse:collapse}
.table th,.table td{
    padding:12px 10px;
    border-bottom:1px solid #e6ebef;
    text-align:left;
    vertical-align:middle;
    font-size:13px
}
.table th{background:#f8fafc;color:#52606d;font-weight:bold}
.table tr:hover td{background:#fbfdff}
.empty{text-align:center!important;color:#8a98a5;padding:40px!important}
.link-button{
    border:0;background:none;padding:0;color:#2878c8;cursor:pointer;text-align:left
}
.link-button:hover{text-decoration:underline}
.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:12px;
    font-size:11px;
    font-weight:bold
}
.badge-open{background:#e6f6ed;color:#237a49}
.badge-draft{background:#edf2f7;color:#66788a}
.badge-end{background:#fdecec;color:#b43b3b}
.badge-ok{background:#e6f6ed;color:#237a49}
.badge-warn{background:#fff5d9;color:#9a6800}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px
}
.field{margin-bottom:15px}
.field label{
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:6px;
    color:#455563
}
.field input,.field textarea,.field select{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:9px 10px;
    background:#fff
}
.field textarea{min-height:90px;resize:vertical}
.radio-row{display:flex;gap:22px;flex-wrap:wrap}
.radio-row label{font-weight:normal;display:inline-flex;align-items:center;gap:5px}
.notice{
    padding:11px 13px;
    border-radius:5px;
    background:#edf6ff;
    border:1px solid #c9e2fa;
    color:#2b5f8a;
    font-size:13px;
    margin-bottom:15px
}
.notice.success{background:#edf9f1;border-color:#c9ead5;color:#267348}
.notice.warning{background:#fff8e6;border-color:#f0dfae;color:#8a6408}
.notice.error{background:#fff0f0;border-color:#f0c5c5;color:#a33131}
.editor-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-top:20px
}
.editor-actions{display:flex;gap:8px}
.group-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    margin-bottom:16px
}
.group-header{
    display:flex;
    align-items:center;
    gap:10px;
    padding:13px 15px;
    background:#f7f9fb;
    border-bottom:1px solid #e3e8ed
}
.group-title{flex:1}
.group-title input{
    border:1px solid transparent;
    background:transparent;
    padding:5px 7px;
    font-weight:bold;
    font-size:16px;
    width:100%
}
.group-title input:focus{border-color:#cbd5e0;background:#fff}
.drag-handle{color:#9aa7b3;cursor:grab;font-size:18px}
.group-actions{display:flex;gap:5px}
.question-card{
    padding:15px;
    border-bottom:1px solid #e6ebef
}
.question-card:last-child{border-bottom:0}
.question-head{display:flex;align-items:center;gap:8px}
.question-number{width:58px;color:#2878c8;font-weight:bold;flex:none}
.question-title{flex:1}
.question-title input{
    width:100%;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:8px
}
.question-tools{display:flex;gap:6px}
.question-tools select{
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:6px
}
.question-meta{
    display:flex;
    gap:18px;
    align-items:center;
    margin-top:10px;
    padding-left:66px;
    color:#657786;
    font-size:13px
}
.question-options{margin-top:12px;padding-left:66px}
.option-row{display:flex;align-items:center;gap:7px;margin-bottom:7px}
.option-row input{
    flex:1;
    border:1px solid #cbd5e0;
    border-radius:4px;
    padding:7px
}
.branch-select{width:270px!important;flex:none}
.invalid-branch{border-color:#d9534f!important;background:#fff5f5!important}
.add-question-area{padding:12px 14px}
.add-group-area{text-align:center;margin-top:8px}
.detail-tabs{
    display:flex;
    border-bottom:1px solid #dfe5eb;
    margin-bottom:18px
}
.detail-tabs button{
    border:0;
    background:transparent;
    padding:12px 20px;
    color:#687887;
    border-bottom:3px solid transparent
}
.detail-tabs button.active{
    color:#2878c8;
    border-bottom-color:#2878c8
}
.detail-summary{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:18px
}
.stat-card{
    background:#fff;
    border:1px solid #dfe5eb;
    border-radius:7px;
    padding:17px
}
.stat-label{color:#718096;font-size:12px}
.stat-value{font-size:27px;font-weight:bold;margin-top:5px}
.stat-note{font-size:11px;color:#8a98a5;margin-top:3px}
.preview-question{
    padding:15px 0;
    border-bottom:1px solid #e6ebef
}
.preview-question-title{font-weight:bold;margin-bottom:9px}
.preview-option{margin:6px 0;color:#52606d}
.send-layout{
    display:grid;
    grid-template-columns:1.1fr .9fr;
    gap:18px
}
.customer-toolbar{display:flex;gap:8px;margin-bottom:12px}
.customer-toolbar input{flex:1}
.customer-toolbar input,.customer-toolbar select{
    border:1px solid #cbd5e0;
    border-radius:5px;
    padding:8px
}
.selection-summary{
    padding:10px 12px;
    background:#edf6ff;
    color:#2b5f8a;
    border-radius:5px;
    margin-bottom:12px;
    font-size:13px
}
.email-preview{
    border:1px solid #dfe5eb;
    border-radius:6px;
    background:#fafbfc;
    padding:15px;
    white-space:pre-wrap;
    min-height:150px;
    font-size:13px
}
.recipient-chip{
    display:inline-block;
    padding:5px 8px;
    margin:3px;
    border-radius:4px;
    background:#edf2f7;
    font-size:12px
}
.progress{
    height:10px;
    background:#e8edf2;
    border-radius:5px;
    overflow:hidden;
    margin-top:8px
}
.progress span{display:block;height:100%;background:#4285c5}
.result-item{
    padding:18px;
    border-bottom:1px solid #e6ebef
}
.bar{
    height:9px;
    background:#e8edf2;
    border-radius:5px;
    overflow:hidden;
    margin-top:6px
}
.bar span{display:block;height:100%;background:#4285c5}
.result-answer{
    padding:8px 10px;
    background:#f7f9fb;
    border:1px solid #e6ebef;
    border-radius:4px;
    margin:5px 0;
    font-size:13px
}
.settings-tabs{display:flex;gap:5px;margin-bottom:18px}
.settings-tabs button{
    border:1px solid #d6dee6;
    background:#fff;
    padding:9px 16px;
    border-radius:5px
}
.settings-tabs button.active{background:#2878c8;color:#fff;border-color:#2878c8}
.status-line{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    background:#f7f9fb;
    border-radius:5px;
    margin-bottom:15px
}
.status-dot{
    width:9px;height:9px;border-radius:50%;background:#9aa7b3
}
.status-dot.ok{background:#2f9e61}
.status-dot.warn{background:#d39b25}
.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(20,35,50,.45);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:1000
}
.modal{
    width:min(760px,calc(100% - 30px));
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border-radius:8px;
    box-shadow:0 15px 50px rgba(0,0,0,.25)
}
.modal-header{
    padding:16px 20px;
    border-bottom:1px solid #e3e8ed;
    display:flex;
    justify-content:space-between
}
.modal-body{padding:20px}
.modal-footer{
    padding:13px 20px;
    border-top:1px solid #e3e8ed;
    display:flex;
    justify-content:flex-end;
    gap:8px
}
.toast{
    position:fixed;
    right:25px;
    bottom:25px;
    background:#263238;
    color:#fff;
    padding:12px 18px;
    border-radius:5px;
    box-shadow:0 5px 20px rgba(0,0,0,.2);
    opacity:0;
    transform:translateY(10px);
    transition:.2s;
    pointer-events:none;
    z-index:2000
}
.toast.show{opacity:1;transform:translateY(0)}
@media(max-width:950px){
    .send-layout{grid-template-columns:1fr}
    .detail-summary{grid-template-columns:1fr 1fr}
}
@media(max-width:800px){
    .topbar{padding:0 10px;gap:8px;overflow-x:auto}
    .logo{font-size:15px}
    .main-nav button{padding:0 8px;font-size:12px}
    .app{padding:14px}
    .form-grid{grid-template-columns:1fr}
    .question-head{align-items:flex-start;flex-wrap:wrap}
    .question-title{min-width:70%}
    .question-meta,.question-options{padding-left:0}
    .branch-select{width:100%!important}
    .option-row{flex-wrap:wrap}
    .table{min-width:800px}
    .card{overflow-x:auto}
}
</style>
</head>
<body>

<header class="topbar">
    <div class="logo">アンケート業務運営</div>
    <nav class="main-nav">
        <button id="nav-list">アンケート一覧</button>
        <button id="nav-create">アンケート作成</button>
        <button id="nav-customers">顧客一覧</button>
        <button id="nav-settings">設定</button>
    </nav>
</header>

<main class="app">

<section id="page-list">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">作成済みのアンケートを管理します</div>
        </div>
        <button id="btn-create" class="btn btn-primary">＋ アンケート作成</button>
    </div>

    <div id="list-message"></div>

    <div class="card">
        <table class="table">
            <thead>
            <tr>
                <th>アンケート名</th>
                <th>状態</th>
                <th>作成日</th>
                <th>公開期間</th>
                <th>回答数</th>
                <th>最終更新日</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody id="survey-list-body"></tbody>
        </table>
    </div>
</section>

<section id="page-editor" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="editor-page-title">アンケート作成</h1>
            <div class="subtext">アンケート全体を1画面で編集できます</div>
        </div>
    </div>

    <div id="editor-message"></div>

    <div class="notice">
        質問とグループはドラッグ＆ドロップで並べ替えできます。
        質問番号は自動的に更新されます。
    </div>

    <div class="card">
        <div class="form-grid">
            <div class="field">
                <label>アンケート名 *</label>
                <input id="survey-name" type="text" placeholder="例：新商品アンケート">
            </div>

            <div class="field">
                <label>公開状態</label>
                <select id="survey-status">
                    <option value="draft">下書き</option>
                    <option value="open">公開中</option>
                    <option value="end">終了</option>
                </select>
            </div>
        </div>

        <div class="field">
            <label>説明</label>
            <textarea id="survey-description" placeholder="回答者への説明を入力してください"></textarea>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>公開開始日</label>
                <input id="survey-start" type="date">
            </div>
            <div class="field">
                <label>公開終了日</label>
                <input id="survey-end" type="date">
            </div>
        </div>

        <div class="field">
            <label>質問番号</label>
            <div class="radio-row">
                <label>
                    <input type="radio" name="numbering" value="global">
                    全体で通番（Q1、Q2、Q3…）
                </label>
                <label>
                    <input type="radio" name="numbering" value="group">
                    グループごと（Q1-1、Q1-2、Q2-1…）
                </label>
            </div>
        </div>
    </div>

    <div id="groups"></div>

    <div class="add-group-area">
        <button id="btn-add-group" class="btn btn-primary">＋ グループ追加</button>
    </div>

    <div class="editor-toolbar">
        <button id="btn-editor-back" class="btn">一覧へ戻る</button>

        <div class="editor-actions">
            <button id="btn-preview" class="btn">内容確認</button>
            <button id="btn-save" class="btn btn-primary">保存</button>
        </div>
    </div>
</section>

<section id="page-detail" class="hidden">
    <div class="page-header">
        <div>
            <h1 id="detail-title"></h1>
            <div class="subtext" id="detail-subtitle"></div>
        </div>

        <div>
            <button id="btn-detail-edit" class="btn">編集</button>
            <button id="btn-detail-send" class="btn btn-primary">送信</button>
            <button id="btn-detail-back" class="btn">一覧へ戻る</button>
        </div>
    </div>

    <div class="detail-tabs">
        <button id="tab-content">アンケート内容</button>
        <button id="tab-send">送信</button>
        <button id="tab-status">回答状況</button>
        <button id="tab-result">回答結果</button>
    </div>

    <div id="detail-content"></div>
</section>

<section id="page-customers" class="hidden">
    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">kintoneの顧客管理アプリから取得した顧客です</div>
        </div>

        <button id="btn-customer-settings" class="btn">kintone設定</button>
    </div>

    <div id="customer-status"></div>

    <div class="card">
        <div class="customer-toolbar">
            <input id="customer-search" placeholder="顧客名・メールアドレスで検索">
            <button id="btn-refresh-customers" class="btn">顧客一覧を更新</button>
        </div>

        <table class="table">
            <thead>
            <tr>
                <th>顧客名</th>
                <th>メールアドレス</th>
                <th>会社名</th>
                <th>顧客番号</th>
            </tr>
            </thead>
            <tbody id="customer-body"></tbody>
        </table>
    </div>
</section>

<section id="page-settings" class="hidden">
    <div class="page-header">
        <div>
            <h1>設定</h1>
            <div class="subtext">メール送信と顧客一覧取得に必要な設定を管理します</div>
        </div>
    </div>

    <div class="settings-tabs">
        <button id="settings-tab-mail">メール送信設定</button>
        <button id="settings-tab-kintone">kintone設定</button>
    </div>

    <div id="settings-content"></div>
</section>

</main>

<div id="modal" class="modal-backdrop hidden">
    <div class="modal">
        <div class="modal-header">
            <strong id="modal-title"></strong>
            <button id="modal-close" class="btn btn-small">閉じる</button>
        </div>
        <div class="modal-body" id="modal-body"></div>
        <div class="modal-footer" id="modal-footer"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var csrfToken = document.querySelector('meta[name="csrf-token"]') ?
        document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';

    var surveys = <?= json_encode($initialSurveys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var customers = <?= json_encode($initialCustomers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var mailSettings = <?= json_encode($mailSettingsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var kintoneSettings = <?= json_encode($kintoneSettingsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    var editingSurvey = null;
    var currentSurveyId = null;
    var currentSettingsTab = 'mail';
    var selectedCustomers = [];
    var draggedQuestion = null;
    var draggedGroup = null;
    var toastTimer = null;

    function $(id) {
        return document.getElementById(id);
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function escapeHtml(value) {
        var str = value === null || value === undefined ? '' : String(value);

        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showToast(message) {
        var toast = $('toast');

        if (!toast) {
            return;
        }

        toast.textContent = message;
        toast.classList.add('show');

        if (toastTimer) {
            window.clearTimeout(toastTimer);
        }

        toastTimer = window.setTimeout(function () {
            toast.classList.remove('show');
        }, 3000);
    }

    function showMessage(targetId, message, type) {
        var target = $(targetId);

        if (!target) {
            return;
        }

        target.textContent = '';

        var box = document.createElement('div');
        box.className = 'notice ' + (type || '');
        box.textContent = message;

        target.appendChild(box);
    }

    function showPage(id) {
        var pageIds = [
            'page-list',
            'page-editor',
            'page-detail',
            'page-customers',
            'page-settings'
        ];

        pageIds.forEach(function (pageId) {
            var page = $(pageId);
            if (page) {
                page.classList.add('hidden');
            }
        });

        var target = $(id);

        if (target) {
            target.classList.remove('hidden');
        }

        var navIds = [
            'nav-list',
            'nav-create',
            'nav-customers',
            'nav-settings'
        ];

        navIds.forEach(function (navId) {
            var nav = $(navId);
            if (nav) {
                nav.classList.remove('active');
            }
        });

        if (id === 'page-list' && $('nav-list')) {
            $('nav-list').classList.add('active');
        }

        if (id === 'page-editor' && $('nav-create')) {
            $('nav-create').classList.add('active');
        }

        if (id === 'page-customers' && $('nav-customers')) {
            $('nav-customers').classList.add('active');
        }

        if (id === 'page-settings' && $('nav-settings')) {
            $('nav-settings').classList.add('active');
        }
    }

    function setLoading(button, loading) {
        if (!button) {
            return;
        }

        if (loading) {
            button.disabled = true;
            button.classList.add('loading');
        } else {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    function api(action, payload, button) {
        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        var body = payload || {};
        body.csrf_token = csrfToken;

        return fetch('?api=' + encodeURIComponent(action), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        })
        .then(function (response) {
            return response.text().then(function (text) {
                var data;

                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('サーバーから正しい応答を受け取れませんでした。');
                }

                if (!response.ok || !data.success) {
                    throw new Error(data.message || '処理に失敗しました。');
                }

                return data;
            });
        })
        .finally(function () {
            if (button) {
                button.disabled = false;
                button.classList.remove('loading');
            }
        });
    }

    function statusBadge(status) {
        if (status === 'open') {
            return '<span class="badge badge-open">公開中</span>';
        }

        if (status === 'end') {
            return '<span class="badge badge-end">終了</span>';
        }

        return '<span class="badge badge-draft">下書き</span>';
    }

    function renderList() {
        var body = $('survey-list-body');

        if (!body) {
            return;
        }

        body.textContent = '';

        if (!surveys.length) {
            var empty = document.createElement('tr');
            empty.innerHTML = '<td colspan="7" class="empty">アンケートがありません。</td>';
            body.appendChild(empty);
            return;
        }

        surveys.forEach(function (survey) {
            var tr = document.createElement('tr');

            var period = survey.start || survey.end ?
                escapeHtml(survey.start || '未設定') +
                ' ～ ' +
                escapeHtml(survey.end || '未設定') :
                '未設定';

            tr.innerHTML =
                '<td><button class="link-button survey-open" data-id="' +
                escapeHtml(survey.id) +
                '">' +
                escapeHtml(survey.name) +
                '</button></td>' +
                '<td>' + statusBadge(survey.status) + '</td>' +
                '<td>' + escapeHtml(survey.created || '') + '</td>' +
                '<td>' + period + '</td>' +
                '<td>' + Number(survey.answers || 0) + '件</td>' +
                '<td>' + escapeHtml(survey.updated || '') + '</td>' +
                '<td>' +
                '<button class="btn btn-small survey-edit" data-id="' +
                escapeHtml(survey.id) +
                '">編集</button> ' +
                '<button class="btn btn-small btn-danger survey-delete" data-id="' +
                escapeHtml(survey.id) +
                '">削除</button>' +
                '</td>';

            body.appendChild(tr);
        });

        body.querySelectorAll('.survey-open').forEach(function (button) {
            button.addEventListener('click', function () {
                openDetail(button.getAttribute('data-id'));
            });
        });

        body.querySelectorAll('.survey-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                editSurvey(button.getAttribute('data-id'));
            });
        });

        body.querySelectorAll('.survey-delete').forEach(function (button) {
            button.addEventListener('click', function () {
                deleteSurvey(button.getAttribute('data-id'), button);
            });
        });
    }

    function createEmptySurvey() {
        return {
            id: null,
            name: '',
            description: '',
            status: 'draft',
            created: '',
            start: '',
            end: '',
            answers: 0,
            target: 0,
            sent: 0,
            updated: '',
            numbering: 'global',
            groups: [
                {
                    id: Date.now(),
                    name: 'グループ1',
                    questions: [
                        {
                            id: Date.now() + 1,
                            text: '',
                            type: 'single',
                            required: false,
                            options: [
                                {text: '', branch: ''}
                            ]
                        }
                    ]
                }
            ]
        };
    }

    function openCreate() {
        editingSurvey = createEmptySurvey();
        renderEditor();
        showPage('page-editor');
    }

    function editSurvey(id) {
        var found = surveys.find(function (survey) {
            return String(survey.id) === String(id);
        });

        if (!found) {
            return;
        }

        editingSurvey = clone(found);
        renderEditor();
        showPage('page-editor');
    }

    function getQuestionNumber(survey, groupIndex, questionIndex) {
        if (survey.numbering === 'group') {
            return 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1);
        }

        var number = 0;

        for (var gi = 0; gi < groupIndex; gi++) {
            number += (survey.groups[gi].questions || []).length;
        }

        number += questionIndex + 1;

        return 'Q' + number;
    }

    function getQuestionLabel(id) {
        if (!editingSurvey) {
            return '';
        }

        for (var gi = 0; gi < editingSurvey.groups.length; gi++) {
            var questions = editingSurvey.groups[gi].questions || [];

            for (var qi = 0; qi < questions.length; qi++) {
                if (String(questions[qi].id) === String(id)) {
                    return getQuestionNumber(editingSurvey, gi, qi) +
                        '：' +
                        (questions[qi].text || '（未入力）');
                }
            }
        }

        return '';
    }

    function getBranchOptions(currentQuestionId, currentBranch) {
        var html =
            '<option value="">通常の次の質問へ</option>' +
            '<option value="END"' +
            (currentBranch === 'END' ? ' selected' : '') +
            '>アンケート終了</option>';

        editingSurvey.groups.forEach(function (group, gi) {
            (group.questions || []).forEach(function (question, qi) {
                if (String(question.id) === String(currentQuestionId)) {
                    return;
                }

                var selected =
                    String(currentBranch) === String(question.id) ?
                    ' selected' :
                    '';

                html +=
                    '<option value="' +
                    escapeHtml(question.id) +
                    '"' +
                    selected +
                    '>' +
                    escapeHtml(
                        getQuestionNumber(editingSurvey, gi, qi) +
                        '：' +
                        (question.text || '（未入力）')
                    ) +
                    '</option>';
            });
        });

        return html;
    }

    function renderEditor() {
        if (!editingSurvey) {
            return;
        }

        var title = $('editor-page-title');
        var name = $('survey-name');
        var description = $('survey-description');
        var status = $('survey-status');
        var start = $('survey-start');
        var end = $('survey-end');
        var groups = $('groups');

        if (!title || !name || !description || !status || !start || !end || !groups) {
            return;
        }

        title.textContent =
            editingSurvey.id === null ?
            'アンケート作成' :
            'アンケート編集';

        name.value = editingSurvey.name || '';
        description.value = editingSurvey.description || '';
        status.value = editingSurvey.status || 'draft';
        start.value = editingSurvey.start || '';
        end.value = editingSurvey.end || '';

        var radio = document.querySelector(
            'input[name="numbering"][value="' +
            (editingSurvey.numbering || 'global') +
            '"]'
        );

        if (radio) {
            radio.checked = true;
        }

        groups.textContent = '';

        editingSurvey.groups.forEach(function (group, gi) {
            var groupCard = document.createElement('div');
            groupCard.className = 'group-card';
            groupCard.draggable = true;
            groupCard.setAttribute('data-group-id', group.id);

            var header = document.createElement('div');
            header.className = 'group-header';

            var drag = document.createElement('span');
            drag.className = 'drag-handle';
            drag.textContent = '☷';

            var titleWrap = document.createElement('div');
            titleWrap.className = 'group-title';

            var groupInput = document.createElement('input');
            groupInput.value = group.name || '';
            groupInput.placeholder = 'グループ名';

            groupInput.addEventListener('input', function () {
                group.name = groupInput.value;
            });

            titleWrap.appendChild(groupInput);

            var actions = document.createElement('div');
            actions.className = 'group-actions';

            var deleteGroupButton = document.createElement('button');
            deleteGroupButton.className = 'btn btn-small btn-danger';
            deleteGroupButton.textContent = 'グループ削除';

            deleteGroupButton.addEventListener('click', function () {
                if (editingSurvey.groups.length <= 1) {
                    alert('グループは1つ以上必要です。');
                    return;
                }

                if (!window.confirm('このグループを削除しますか？')) {
                    return;
                }

                editingSurvey.groups.splice(gi, 1);
                renderEditor();
            });

            actions.appendChild(deleteGroupButton);
            header.appendChild(drag);
            header.appendChild(titleWrap);
            header.appendChild(actions);
            groupCard.appendChild(header);

            (group.questions || []).forEach(function (question, qi) {
                var questionCard = document.createElement('div');
                questionCard.className = 'question-card';
                questionCard.draggable = true;
                questionCard.setAttribute('data-question-id', question.id);

                var questionHead = document.createElement('div');
                questionHead.className = 'question-head';

                var questionNumber = document.createElement('span');
                questionNumber.className = 'question-number';
                questionNumber.textContent =
                    getQuestionNumber(editingSurvey, gi, qi);

                var questionTitle = document.createElement('div');
                questionTitle.className = 'question-title';

                var questionInput = document.createElement('input');
                questionInput.placeholder = '質問文を入力してください';
                questionInput.value = question.text || '';

                questionInput.addEventListener('input', function () {
                    question.text = questionInput.value;
                });

                questionTitle.appendChild(questionInput);

                var tools = document.createElement('div');
                tools.className = 'question-tools';

                var typeSelect = document.createElement('select');

                [
                    ['free', '自由記述'],
                    ['single', '単一選択'],
                    ['multiple', '複数選択']
                ].forEach(function (item) {
                    var option = document.createElement('option');
                    option.value = item[0];
                    option.textContent = item[1];
                    typeSelect.appendChild(option);
                });

                typeSelect.value = question.type;

                typeSelect.addEventListener('change', function () {
                    question.type = typeSelect.value;

                    if (
                        (question.type === 'single' ||
                        question.type === 'multiple') &&
                        (!question.options || question.options.length === 0)
                    ) {
                        question.options = [
                            {text: '', branch: ''}
                        ];
                    }

                    if (question.type === 'free') {
                        question.options = [];
                    }

                    renderEditor();
                });

                var deleteQuestionButton = document.createElement('button');
                deleteQuestionButton.className =
                    'btn btn-small btn-danger';
                deleteQuestionButton.textContent = '削除';

                deleteQuestionButton.addEventListener('click', function () {
                    if (!window.confirm('この質問を削除しますか？')) {
                        return;
                    }

                    group.questions.splice(qi, 1);
                    renderEditor();
                });

                tools.appendChild(typeSelect);
                tools.appendChild(deleteQuestionButton);

                questionHead.appendChild(questionNumber);
                questionHead.appendChild(questionTitle);
                questionHead.appendChild(tools);
                questionCard.appendChild(questionHead);

                var meta = document.createElement('div');
                meta.className = 'question-meta';

                var requiredLabel = document.createElement('label');
                var requiredCheck = document.createElement('input');

                requiredCheck.type = 'checkbox';
                requiredCheck.checked = !!question.required;

                requiredCheck.addEventListener('change', function () {
                    question.required = requiredCheck.checked;
                });

                requiredLabel.appendChild(requiredCheck);
                requiredLabel.appendChild(
                    document.createTextNode(' 必須')
                );

                meta.appendChild(requiredLabel);
                questionCard.appendChild(meta);

                if (
                    question.type === 'single' ||
                    question.type === 'multiple'
                ) {
                    var optionsWrap = document.createElement('div');
                    optionsWrap.className = 'question-options';

                    var optionLabel = document.createElement('div');
                    optionLabel.style.cssText =
                        'font-size:12px;color:#718096;margin-bottom:7px';
                    optionLabel.textContent = '選択肢';
                    optionsWrap.appendChild(optionLabel);

                    (question.options || []).forEach(function (option, oi) {
                        var row = document.createElement('div');
                        row.className = 'option-row';

                        var number = document.createElement('span');
                        number.style.width = '18px';
                        number.style.color = '#718096';
                        number.textContent = (oi + 1) + '.';

                        var input = document.createElement('input');
                        input.value = option.text || '';

                        input.addEventListener('input', function () {
                            option.text = input.value;
                        });

                        row.appendChild(number);
                        row.appendChild(input);

                        if (question.type === 'single') {
                            var branch = document.createElement('select');
                            branch.className = 'branch-select';
                            branch.innerHTML =
                                getBranchOptions(
                                    question.id,
                                    option.branch || ''
                                );

                            branch.addEventListener('change', function () {
                                option.branch = branch.value;
                            });

                            row.appendChild(branch);
                        }

                        var deleteOption = document.createElement('button');
                        deleteOption.className =
                            'btn btn-small btn-danger';
                        deleteOption.textContent = '削除';

                        deleteOption.addEventListener('click', function () {
                            if (question.options.length <= 1) {
                                alert('選択肢は1つ以上必要です。');
                                return;
                            }

                            question.options.splice(oi, 1);
                            renderEditor();
                        });

                        row.appendChild(deleteOption);
                        optionsWrap.appendChild(row);
                    });

                    var addOption = document.createElement('button');
                    addOption.className = 'btn btn-small';
                    addOption.textContent = '＋ 選択肢追加';

                    addOption.addEventListener('click', function () {
                        question.options.push({
                            text: '',
                            branch: ''
                        });

                        renderEditor();
                    });

                    optionsWrap.appendChild(addOption);
                    questionCard.appendChild(optionsWrap);
                }

                var questionArea = document.createElement('div');
                questionArea.appendChild(questionCard);

                questionCard.addEventListener('dragstart', function () {
                    draggedQuestion = {
                        groupId: group.id,
                        questionId: question.id
                    };
                });

                questionCard.addEventListener('dragover', function (event) {
                    event.preventDefault();
                });

                questionCard.addEventListener('drop', function (event) {
                    event.preventDefault();

                    if (!draggedQuestion) {
                        return;
                    }

                    var sourceGroup = editingSurvey.groups.find(function (g) {
                        return String(g.id) === String(draggedQuestion.groupId);
                    });

                    if (!sourceGroup) {
                        draggedQuestion = null;
                        return;
                    }

                    var sourceIndex = sourceGroup.questions.findIndex(function (q) {
                        return String(q.id) === String(draggedQuestion.questionId);
                    });

                    var targetIndex = group.questions.findIndex(function (q) {
                        return String(q.id) === String(question.id);
                    });

                    if (sourceIndex < 0 || targetIndex < 0) {
                        draggedQuestion = null;
                        return;
                    }

                    var moved = sourceGroup.questions.splice(sourceIndex, 1)[0];

                    if (sourceGroup === group && sourceIndex < targetIndex) {
                        targetIndex--;
                    }

                    group.questions.splice(targetIndex, 0, moved);

                    draggedQuestion = null;
                    renderEditor();
                });

                groupCard.appendChild(questionArea);
            });

            var addQuestionArea = document.createElement('div');
            addQuestionArea.className = 'add-question-area';

            var addQuestion = document.createElement('button');
            addQuestion.className = 'btn btn-small';
            addQuestion.textContent = '＋ 質問追加';

            addQuestion.addEventListener('click', function () {
                group.questions.push({
                    id: Date.now() + Math.floor(Math.random() * 1000),
                    text: '',
                    type: 'single',
                    required: false,
                    options: [
                        {text: '', branch: ''}
                    ]
                });

                renderEditor();
            });

            addQuestionArea.appendChild(addQuestion);
            groupCard.appendChild(addQuestionArea);

            groupCard.addEventListener('dragstart', function () {
                draggedGroup = group.id;
            });

            groupCard.addEventListener('dragover', function (event) {
                event.preventDefault();
            });

            groupCard.addEventListener('drop', function (event) {
                event.preventDefault();

                if (draggedGroup === null) {
                    return;
                }

                var sourceIndex = editingSurvey.groups.findIndex(function (g) {
                    return String(g.id) === String(draggedGroup);
                });

                if (sourceIndex < 0) {
                    draggedGroup = null;
                    return;
                }

                var targetIndex = editingSurvey.groups.findIndex(function (g) {
                    return String(g.id) === String(group.id);
                });

                if (targetIndex < 0 || sourceIndex === targetIndex) {
                    draggedGroup = null;
                    return;
                }

                var moved = editingSurvey.groups.splice(sourceIndex, 1)[0];

                if (sourceIndex < targetIndex) {
                    targetIndex--;
                }

                editingSurvey.groups.splice(targetIndex, 0, moved);
                draggedGroup = null;
                renderEditor();
            });

            groups.appendChild(groupCard);
        });
    }

    function collectEditorValues() {
        if (!editingSurvey) {
            return;
        }

        var name = $('survey-name');
        var description = $('survey-description');
        var status = $('survey-status');
        var start = $('survey-start');
        var end = $('survey-end');

        if (name) {
            editingSurvey.name = name.value.trim();
        }

        if (description) {
            editingSurvey.description = description.value.trim();
        }

        if (status) {
            editingSurvey.status = status.value;
        }

        if (start) {
            editingSurvey.start = start.value;
        }

        if (end) {
            editingSurvey.end = end.value;
        }

        var selectedNumbering = document.querySelector(
            'input[name="numbering"]:checked'
        );

        if (selectedNumbering) {
            editingSurvey.numbering = selectedNumbering.value;
        }
    }

    function validateSurveyClient(survey) {
        var errors = [];

        if (!survey.name) {
            errors.push('アンケート名を入力してください。');
        }

        if (
            survey.start &&
            survey.end &&
            survey.start > survey.end
        ) {
            errors.push('公開終了日は公開開始日以降にしてください。');
        }

        if (!survey.groups.length) {
            errors.push('グループを1つ以上登録してください。');
        }

        survey.groups.forEach(function (group) {
            if (!group.name.trim()) {
                errors.push('グループ名を入力してください。');
            }

            group.questions.forEach(function (question) {
                if (!question.text.trim()) {
                    errors.push('質問文を入力してください。');
                }

                if (
                    (question.type === 'single' ||
                    question.type === 'multiple') &&
                    !question.options.length
                ) {
                    errors.push('選択肢を1つ以上登録してください。');
                }

                question.options.forEach(function (option) {
                    if (!option.text.trim()) {
                        errors.push('空の選択肢があります。');
                    }
                });
            });
        });

        return errors;
    }

    function saveSurvey() {
        collectEditorValues();

        var errors = validateSurveyClient(editingSurvey);

        if (errors.length) {
            showMessage(
                'editor-message',
                errors.join('\n'),
                'error'
            );
            return;
        }

        var button = $('btn-save');

        api('save_survey', {
            survey: editingSurvey
        }, button)
        .then(function (data) {
            surveys = data.surveys || surveys;
            editingSurvey = clone(data.survey);
            currentSurveyId = editingSurvey.id;

            showToast(data.message || '保存しました。');
            showList();
        })
        .catch(function (error) {
            showMessage(
                'editor-message',
                error.message,
                'error'
            );
        });
    }

    function deleteSurvey(id, button) {
        if (!window.confirm(
            'このアンケートを削除しますか？\nこの操作は元に戻せません。'
        )) {
            return;
        }

        api('delete_survey', {
            id: id
        }, button)
        .then(function (data) {
            surveys = data.surveys || [];
            showToast(data.message || '削除しました。');
            renderList();
        })
        .catch(function (error) {
            showMessage(
                'list-message',
                error.message,
                'error'
            );
        });
    }

    function openDetail(id) {
        currentSurveyId = id;

        var survey = getCurrentSurvey();

        if (!survey) {
            return;
        }

        var title = $('detail-title');
        var subtitle = $('detail-subtitle');

        if (title) {
            title.textContent = survey.name || '';
        }

        if (subtitle) {
            subtitle.textContent =
                '状態：' +
                (survey.status === 'open' ?
                    '公開中' :
                    survey.status === 'end' ?
                        '終了' :
                        '下書き');
        }

        showDetailTab('content');
        showPage('page-detail');
    }

    function getCurrentSurvey() {
        return surveys.find(function (survey) {
            return String(survey.id) === String(currentSurveyId);
        }) || null;
    }

    function showDetailTab(tab) {
        var survey = getCurrentSurvey();

        if (!survey) {
            return;
        }

        ['content', 'send', 'status', 'result'].forEach(function (name) {
            var button = $('tab-' + name);

            if (button) {
                button.classList.remove('active');
            }
        });

        var active = $('tab-' + tab);

        if (active) {
            active.classList.add('active');
        }

        if (tab === 'content') {
            renderDetailContent(survey);
        }

        if (tab === 'send') {
            renderSend(survey);
        }

        if (tab === 'status') {
            renderStatus(survey);
        }

        if (tab === 'result') {
            renderResult(survey);
        }
    }

    function renderDetailContent(survey) {
        var container = $('detail-content');

        if (!container) {
            return;
        }

        container.textContent = '';

        var card = document.createElement('div');
        card.className = 'card';

        var html =
            '<div class="card-title">アンケート基本情報</div>' +
            '<div class="form-grid">' +
            '<div><strong>説明</strong>' +
            '<div style="margin-top:6px;color:#687887">' +
            escapeHtml(survey.description || 'なし') +
            '</div></div>' +
            '<div><strong>公開期間</strong>' +
            '<div style="margin-top:6px">' +
            escapeHtml(survey.start || '未設定') +
            ' ～ ' +
            escapeHtml(survey.end || '未設定') +
            '</div></div>' +
            '</div>';

        survey.groups.forEach(function (group, gi) {
            html +=
                '<h3 style="margin-top:25px;border-bottom:1px solid #e6ebef;padding-bottom:8px">' +
                escapeHtml(group.name) +
                '</h3>';

            group.questions.forEach(function (question, qi) {
                html +=
                    '<div class="preview-question">' +
                    '<div class="preview-question-title">' +
                    escapeHtml(
                        getQuestionNumber(survey, gi, qi) +
                        '　' +
                        question.text
                    );

                if (question.required) {
                    html +=
                        ' <span class="badge badge-warn">必須</span>';
                }

                html += '</div>';

                if (question.type === 'free') {
                    html +=
                        '<div style="color:#8a98a5">自由記述</div>';
                } else {
                    question.options.forEach(function (option) {
                        html +=
                            '<div class="preview-option">・' +
                            escapeHtml(option.text);

                        if (
                            question.type === 'single' &&
                            option.branch
                        ) {
                            html +=
                                ' → ' +
                                (
                                    option.branch === 'END' ?
                                    'アンケート終了' :
                                    escapeHtml(
                                        getQuestionLabel(option.branch)
                                    )
                                );
                        }

                        html += '</div>';
                    });
                }

                html += '</div>';
            });
        });

        card.innerHTML = html;
        container.appendChild(card);
    }

    function statCard(label, value, note) {
        return (
            '<div class="stat-card">' +
            '<div class="stat-label">' +
            escapeHtml(label) +
            '</div>' +
            '<div class="stat-value">' +
            escapeHtml(value) +
            '</div>' +
            '<div class="stat-note">' +
            escapeHtml(note) +
            '</div>' +
            '</div>'
        );
    }

    function renderStatus(survey) {
        var container = $('detail-content');

        if (!container) {
            return;
        }

        var target = Number(survey.target || 0);
        var answers = Number(survey.answers || 0);
        var rate = target ?
            Math.round(answers / target * 100) :
            0;

        var unanswered = Math.max(target - answers, 0);

        var html =
            '<div class="detail-summary">' +
            statCard('回答数', answers + '件', '') +
            statCard('回答率', rate + '%', '') +
            statCard('未回答数', unanswered + '名', '') +
            statCard('送信済み', Number(survey.sent || 0) + '名', '') +
            '</div>' +

            '<div class="card">' +
            '<div class="card-title">公開・回答状況</div>' +
            '<p>公開期間：' +
            escapeHtml(survey.start || '未設定') +
            ' ～ ' +
            escapeHtml(survey.end || '未設定') +
            '</p>' +
            '<p>メール送信対象者：' +
            target +
            '名</p>' +
            '<p>メール送信済み：' +
            Number(survey.sent || 0) +
            '名</p>' +
            '<p>未回答者：' +
            unanswered +
            '名</p>' +
            '<div style="margin-top:18px">回答率</div>' +
            '<div class="progress"><span style="width:' +
            Math.min(rate, 100) +
            '%"></span></div>' +
            '<div style="text-align:right;font-size:12px;color:#718096;margin-top:5px">' +
            rate +
            '%</div>' +
            '</div>' +

            '<div class="card">' +
            '<div class="card-title">回答状況</div>' +
            '<p>現在保存されている回答数：' +
            answers +
            '件</p>' +
            '</div>';

        container.innerHTML = html;
    }

    function renderResult(survey) {
        var container = $('detail-content');

        if (!container) {
            return;
        }

        var html =
            '<div class="detail-summary">' +
            statCard('総回答数', Number(survey.answers || 0) + '件', '') +
            statCard('回答者数', Number(survey.answers || 0) + '名', '') +
            statCard('質問数', countQuestions(survey) + '問', '') +
            statCard('集計対象', Number(survey.answers || 0) + '件', '') +
            '</div>' +

            '<div class="card">' +
            '<div class="card-title">質問ごとの回答結果</div>';

        survey.groups.forEach(function (group, gi) {
            html +=
                '<h3 style="font-size:16px;border-bottom:1px solid #e6ebef;padding-bottom:8px">' +
                escapeHtml(group.name) +
                '</h3>';

            group.questions.forEach(function (question, qi) {
                var qNo = getQuestionNumber(survey, gi, qi);

                html +=
                    '<div class="result-item">' +
                    '<div style="font-weight:bold">' +
                    escapeHtml(qNo + '　' + question.text) +
                    '</div>';

                if (
                    question.type === 'single' ||
                    question.type === 'multiple'
                ) {
                    var total = Number(survey.answers || 0);

                    question.options.forEach(function (option, oi) {
                        var count = total > 0 ?
                            Math.round(
                                total *
                                (
                                    question.options.length === 1 ?
                                    1 :
                                    Math.max(
                                        .1,
                                        1 -
                                        oi /
                                        (question.options.length + 1)
                                    ) /
                                    (
                                        question.options
                                            .slice(0, question.options.length)
                                            .reduce(function (sum, unused, index) {
                                                return sum +
                                                    Math.max(
                                                        .1,
                                                        1 -
                                                        index /
                                                        (question.options.length + 1)
                                                    );
                                            }, 0)
                                )
                            ) :
                            0;

                        var percentage = total ?
                            Math.round(count / total * 100) :
                            0;

                        html +=
                            '<div style="margin-top:12px">' +
                            escapeHtml(option.text) +
                            '　' +
                            count +
                            '件（' +
                            percentage +
                            '%）' +
                            '<div class="bar"><span style="width:' +
                            Math.min(percentage, 100) +
                            '%"></span></div>' +
                            '</div>';
                    });
                } else {
                    html +=
                        '<div class="result-answer">' +
                        '自由記述回答は回答データが登録されると表示されます。' +
                        '</div>';
                }

                html += '</div>';
            });
        });

        html += '</div>';

        container.innerHTML = html;
    }

    function countQuestions(survey) {
        return survey.groups.reduce(function (sum, group) {
            return sum + (group.questions || []).length;
        }, 0);
    }

    function renderSend(survey) {
        var container = $('detail-content');

        if (!container) {
            return;
        }

        selectedCustomers = [];

        var html =
            '<div class="send-layout">' +
            '<div class="card">' +
            '<div class="card-title">送信対象者</div>' +
            '<div id="send-customer-list"></div>' +
            '</div>' +

            '<div class="card">' +
            '<div class="card-title">メール内容</div>' +
            '<div class="field">' +
            '<label>件名</label>' +
            '<input id="mail-subject" value="' +
            escapeHtml('【アンケート】' + survey.name) +
            '">' +
            '</div>' +
            '<div class="email-preview" id="mail-preview">' +
            escapeHtml(
                'アンケートへのご協力をお願いいたします。\n\n' +
                'アンケート名：' + survey.name
            ) +
            '</div>' +
            '<div style="margin-top:15px">' +
            '<button id="btn-send-survey" class="btn btn-primary">選択した顧客へ送信</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        container.innerHTML = html;

        renderSendCustomers();

        var sendButton = $('btn-send-survey');

        if (sendButton) {
            sendButton.addEventListener('click', function () {
                confirmSendSurvey(sendButton);
            });
        }
    }

    function renderSendCustomers() {
        var container = $('send-customer-list');

        if (!container) {
            return;
        }

        var html =
            '<div class="selection-summary">' +
            selectedCustomers.length +
            '名を選択中' +
            '</div>';

        if (!customers.length) {
            html +=
                '<div class="empty">顧客データがありません。顧客一覧を更新してください。</div>';
            container.innerHTML = html;
            return;
        }

        customers.forEach(function (customer) {
            var checked = selectedCustomers.indexOf(
                String(customer.id)
            ) >= 0;

            html +=
                '<label style="display:block;padding:8px;border-bottom:1px solid #edf0f2">' +
                '<input type="checkbox" class="customer-check" data-id="' +
                escapeHtml(customer.id) +
                '"' +
                (checked ? ' checked' : '') +
                '> ' +
                escapeHtml(customer.name || '') +
                '　' +
                escapeHtml(customer.email || '') +
                '<span style="display:block;color:#718096;font-size:11px;margin-left:22px">' +
                escapeHtml(customer.company || '') +
                '</span>' +
                '</label>';
        });

        container.innerHTML = html;

        container.querySelectorAll('.customer-check').forEach(function (check) {
            check.addEventListener('change', function () {
                var id = String(check.getAttribute('data-id'));

                if (check.checked) {
                    if (selectedCustomers.indexOf(id) < 0) {
                        selectedCustomers.push(id);
                    }
                } else {
                    selectedCustomers = selectedCustomers.filter(function (item) {
                        return item !== id;
                    });
                }

                renderSendCustomers();
            });
        });
    }

    function confirmSendSurvey(button) {
        var survey = getCurrentSurvey();

        if (!survey) {
            return;
        }

        if (!selectedCustomers.length) {
            alert('送信対象を選択してください。');
            return;
        }

        openModal(
            'アンケート送信確認',
            '<p>選択した ' +
            selectedCustomers.length +
            ' 名へメールを送信します。</p>' +
            '<div class="notice warning">' +
            '実際にメール送信を行います。' +
            '</div>',
            '<button id="modal-cancel-send" class="btn">戻る</button>' +
            '<button id="modal-confirm-send" class="btn btn-primary">メールを送信する</button>'
        );

        var cancel = $('modal-cancel-send');
        var confirm = $('modal-confirm-send');

        if (cancel) {
            cancel.addEventListener('click', function () {
                closeModal();
            });
        }

        if (confirm) {
            confirm.addEventListener('click', function () {
                sendSurveyMail(confirm);
            });
        }
    }

    function sendSurveyMail(button) {
        var survey = getCurrentSurvey();

        if (!survey) {
            return;
        }

        /*
         * ボタン押下直後に disabled / loading を適用してから通信。
         */
        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        api('send_survey_mail', {
            surveyId: survey.id,
            customerIds: selectedCustomers
        }, null)
        .then(function (data) {
            closeModal();

            var index = surveys.findIndex(function (item) {
                return String(item.id) === String(survey.id);
            });

            if (index >= 0 && data.survey) {
                surveys[index] = data.survey;
            }

            var message =
                '送信成功：' +
                Number(data.successCount || 0) +
                '名\n' +
                '送信失敗：' +
                Number(data.failureCount || 0) +
                '名';

            showToast('メール送信処理が完了しました。');

            renderSendResult(
                data.successCount || 0,
                data.failureCount || 0,
                data.failures || [],
                message
            );
        })
        .catch(function (error) {
            closeModal();
            showToast(error.message);
        })
        .finally(function () {
            if (button) {
                button.disabled = false;
                button.classList.remove('loading');
            }
        });
    }

    function renderSendResult(successCount, failureCount, failures, message) {
        var container = $('detail-content');

        if (!container) {
            return;
        }

        var html =
            '<div class="notice success">メール送信処理が完了しました。</div>' +
            '<div class="card">' +
            '<div class="card-title">送信結果</div>' +
            '<p>' + escapeHtml(message).replace(/\n/g, '<br>') + '</p>';

        if (failures.length) {
            html +=
                '<div class="notice error"><strong>送信失敗</strong><br>';

            failures.forEach(function (failure) {
                html +=
                    escapeHtml(failure.name || '') +
                    '：' +
                    escapeHtml(failure.message || '') +
                    '<br>';
            });

            html += '</div>';
        }

        html += '</div>';

        container.innerHTML = html;
    }

    function showSettings(tab) {
        currentSettingsTab = tab || currentSettingsTab || 'mail';
        showPage('page-settings');

        var mailTab = $('settings-tab-mail');
        var ktTab = $('settings-tab-kintone');

        if (mailTab) {
            mailTab.classList.toggle(
                'active',
                currentSettingsTab === 'mail'
            );
        }

        if (ktTab) {
            ktTab.classList.toggle(
                'active',
                currentSettingsTab === 'kintone'
            );
        }

        if (currentSettingsTab === 'mail') {
            renderMailSettings();
        } else {
            renderKintoneSettings();
        }
    }

    function renderMailSettings() {
        var container = $('settings-content');

        if (!container) {
            return;
        }

        container.innerHTML =
            '<div class="card">' +
            '<div class="card-title">メール送信設定</div>' +

            '<div class="notice">' +
            'メール送信に必要な設定を登録します。' +
            '</div>' +

            '<div class="field">' +
            '<label>SMTPサーバ *</label>' +
            '<input id="smtp-server" value="' +
            escapeHtml(mailSettings.smtp) +
            '">' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>ポート番号 *</label>' +
            '<input id="smtp-port" value="' +
            escapeHtml(mailSettings.port || '587') +
            '">' +
            '</div>' +

            '<div class="field">' +
            '<label>接続方式</label>' +
            '<select id="smtp-security">' +
            '<option value="STARTTLS">STARTTLS</option>' +
            '<option value="SSL/TLS">SSL/TLS</option>' +
            '<option value="なし">なし</option>' +
            '</select>' +
            '</div>' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>SMTPユーザー名</label>' +
            '<input id="smtp-user" value="' +
            escapeHtml(mailSettings.username) +
            '">' +
            '</div>' +

            '<div class="field">' +
            '<label>SMTPパスワード</label>' +
            '<input id="smtp-password" type="password" autocomplete="new-password">' +
            '</div>' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>送信元メールアドレス *</label>' +
            '<input id="smtp-from" value="' +
            escapeHtml(mailSettings.from) +
            '">' +
            '</div>' +

            '<div class="field">' +
            '<label>送信元名</label>' +
            '<input id="smtp-from-name" value="' +
            escapeHtml(mailSettings.fromName) +
            '">' +
            '</div>' +
            '</div>' +

            '<div style="display:flex;gap:8px;margin-top:10px">' +
            '<button id="btn-save-mail" class="btn btn-primary">設定を保存</button>' +
            '<button id="btn-test-mail" class="btn">送信設定を確認</button>' +
            '</div>' +

            '</div>';

        var security = $('smtp-security');

        if (security) {
            security.value = mailSettings.security || 'STARTTLS';
        }

        var saveButton = $('btn-save-mail');
        var testButton = $('btn-test-mail');

        if (saveButton) {
            saveButton.addEventListener('click', function () {
                saveMailSettings(saveButton);
            });
        }

        if (testButton) {
            testButton.addEventListener('click', function () {
                testMailSettings(testButton);
            });
        }
    }

    function saveMailSettings(button) {
        var smtp = $('smtp-server');
        var port = $('smtp-port');
        var security = $('smtp-security');
        var user = $('smtp-user');
        var from = $('smtp-from');
        var fromName = $('smtp-from-name');

        if (!smtp || !port || !security || !user || !from || !fromName) {
            return;
        }

        api('save_mail_settings', {
            smtp: smtp.value.trim(),
            port: port.value.trim(),
            security: security.value,
            username: user.value.trim(),
            from: from.value.trim(),
            fromName: fromName.value.trim()
        }, button)
        .then(function (data) {
            mailSettings = data.settings.mail;
            showToast(data.message || '保存しました。');
            renderMailSettings();
        })
        .catch(function (error) {
            showToast(error.message);
        });
    }

    function testMailSettings(button) {
        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        window.setTimeout(function () {
            if (button) {
                button.disabled = false;
                button.classList.remove('loading');
            }

            var from = mailSettings.from || '';

            if (!from) {
                alert('先にメール送信設定を保存してください。');
                return;
            }

            openModal(
                'メール送信設定の確認',
                '<div class="notice success">' +
                '保存されているメール送信設定を確認しました。' +
                '</div>' +
                '<p><strong>SMTPサーバ：</strong>' +
                escapeHtml(mailSettings.smtp) +
                '</p>' +
                '<p><strong>ポート：</strong>' +
                escapeHtml(mailSettings.port) +
                '</p>' +
                '<p><strong>接続方式：</strong>' +
                escapeHtml(mailSettings.security) +
                '</p>' +
                '<p><strong>送信元：</strong>' +
                escapeHtml(mailSettings.fromName) +
                ' &lt;' +
                escapeHtml(mailSettings.from) +
                '&gt;</p>',
                '<button id="mail-test-close" class="btn">閉じる</button>'
            );

            var close = $('mail-test-close');

            if (close) {
                close.addEventListener('click', closeModal);
            }
        }, 300);
    }

    function renderKintoneSettings() {
        var container = $('settings-content');

        if (!container) {
            return;
        }

        var fields = kintoneSettings.fields || {};

        container.innerHTML =
            '<div class="card">' +
            '<div class="card-title">kintone設定</div>' +

            '<div class="notice">' +
            '顧客一覧の取得にはkintoneのログイン名・パスワードを使用します。' +
            'APIトークンは使用しません。' +
            '</div>' +

            '<div class="field">' +
            '<label>kintoneの利用先 *</label>' +
            '<input id="kt-domain" value="' +
            escapeHtml(kintoneSettings.domain) +
            '" placeholder="https://example.cybozu.com">' +
            '</div>' +

            '<div class="field">' +
            '<label>顧客管理アプリID *</label>' +
            '<input id="kt-appid" value="' +
            escapeHtml(kintoneSettings.appId) +
            '" placeholder="123">' +
            '</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>ログイン名 *</label>' +
            '<input id="kt-user" value="' +
            escapeHtml(kintoneSettings.loginName) +
            '">' +
            '</div>' +

            '<div class="field">' +
            '<label>パスワード *</label>' +
            '<input id="kt-password" type="password" autocomplete="current-password">' +
            '</div>' +
            '</div>' +

            '<div class="card" style="background:#fafbfc;margin-top:20px;margin-bottom:0">' +
            '<div class="card-title">顧客項目</div>' +

            '<div class="form-grid">' +
            '<div class="field">' +
            '<label>顧客名フィールドコード</label>' +
            '<input id="kt-field-name" value="' +
            escapeHtml(fields.name || '顧客名') +
            '">' +
            '</div>' +

            '<div class="field">' +
            '<label>メールアドレスフィールドコード</label>' +
            '<input id="kt-field-email" value="' +
            escapeHtml(fields.email || 'メールアドレス') +
            '">' +
            '</div>' +

            '<div class="field">' +
            '<label>会社名フィールドコード</label>' +
            '<input id="kt-field-company" value="' +
            escapeHtml(fields.company || '会社名') +
            '">' +
            '</div>' +

            '<div class="field">' +
            '<label>顧客番号フィールドコード</label>' +
            '<input id="kt-field-code" value="' +
            escapeHtml(fields.code || '顧客番号') +
            '">' +
            '</div>' +
            '</div>' +
            '</div>' +

            '<div class="card" style="background:#fafbfc;margin-top:20px;margin-bottom:0">' +
            '<div class="card-title">接続経路</div>' +

            '<div class="field">' +
            '<label>プロキシ（ホスト名:ポート番号）</label>' +
            '<input id="kt-proxy" value="' +
            escapeHtml(kintoneSettings.proxyHostPort || '') +
            '" placeholder="proxy.example.local:8080">' +
            '</div>' +

            '<div class="notice warning">' +
            'SSL証明書の検証は行いません。' +
            '</div>' +
            '</div>' +

            '<div style="display:flex;gap:8px;margin-top:18px">' +
            '<button id="btn-save-kintone" class="btn btn-primary">設定を保存</button>' +
            '<button id="btn-test-kintone" class="btn">接続設定を確認</button>' +
            '</div>' +

            '</div>';

        var saveButton = $('btn-save-kintone');
        var testButton = $('btn-test-kintone');

        if (saveButton) {
            saveButton.addEventListener('click', function () {
                saveKintoneSettings(saveButton);
            });
        }

        if (testButton) {
            testButton.addEventListener('click', function () {
                testKintoneSettings(testButton);
            });
        }
    }

    function collectKintoneSettings() {
        return {
            domain: $('kt-domain') ?
                $('kt-domain').value.trim() : '',
            appId: $('kt-appid') ?
                $('kt-appid').value.trim() : '',
            loginName: $('kt-user') ?
                $('kt-user').value.trim() : '',
            password: $('kt-password') ?
                $('kt-password').value : '',
            proxyHostPort: $('kt-proxy') ?
                $('kt-proxy').value.trim() : '',
            fieldName: $('kt-field-name') ?
                $('kt-field-name').value.trim() : '顧客名',
            fieldEmail: $('kt-field-email') ?
                $('kt-field-email').value.trim() : 'メールアドレス',
            fieldCompany: $('kt-field-company') ?
                $('kt-field-company').value.trim() : '会社名',
            fieldCode: $('kt-field-code') ?
                $('kt-field-code').value.trim() : '顧客番号'
        };
    }

    function saveKintoneSettings(button) {
        var values = collectKintoneSettings();

        api('save_kintone_settings', values, button)
        .then(function (data) {
            kintoneSettings = data.settings.kintone;
            showToast(data.message || '保存しました。');
            renderKintoneSettings();
        })
        .catch(function (error) {
            showToast(error.message);
        });
    }

    function testKintoneSettings(button) {
        var values = collectKintoneSettings();

        /*
         * ボタン押下直後に disabled / loading を設定。
         */
        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        api('test_kintone', values, null)
        .then(function (data) {
            openModal(
                'kintone接続確認',
                '<div class="notice success">' +
                escapeHtml(data.message || '接続を確認しました。') +
                '</div>',
                '<button id="kt-test-close" class="btn">閉じる</button>'
            );

            var close = $('kt-test-close');

            if (close) {
                close.addEventListener('click', closeModal);
            }
        })
        .catch(function (error) {
            openModal(
                'kintone接続確認',
                '<div class="notice error">' +
                escapeHtml(error.message) +
                '</div>',
                '<button id="kt-test-error-close" class="btn">閉じる</button>'
            );

            var closeError = $('kt-test-error-close');

            if (closeError) {
                closeError.addEventListener('click', closeModal);
            }
        })
        .finally(function () {
            if (button) {
                button.disabled = false;
                button.classList.remove('loading');
            }
        });
    }

    function refreshCustomers(button) {
        var values = {
            domain: kintoneSettings.domain || '',
            appId: kintoneSettings.appId || '',
            loginName: kintoneSettings.loginName || '',
            password: '',
            proxyHostPort: kintoneSettings.proxyHostPort || ''
        };

        api('refresh_customers', values, button)
        .then(function (data) {
            customers = data.customers || [];
            renderCustomers();
            showToast(data.message || '顧客一覧を更新しました。');
        })
        .catch(function (error) {
            showMessage(
                'customer-status',
                error.message,
                'error'
            );
        });
    }

    function renderCustomers() {
        var body = $('customer-body');
        var search = $('customer-search');

        if (!body) {
            return;
        }

        var keyword = search ?
            search.value.trim().toLowerCase() :
            '';

        body.textContent = '';

        var filtered = customers.filter(function (customer) {
            if (!keyword) {
                return true;
            }

            return (
                String(customer.name || '').toLowerCase().indexOf(keyword) >= 0 ||
                String(customer.email || '').toLowerCase().indexOf(keyword) >= 0
            );
        });

        if (!filtered.length) {
            var empty = document.createElement('tr');
            empty.innerHTML =
                '<td colspan="4" class="empty">顧客がありません。</td>';
            body.appendChild(empty);
            return;
        }

        filtered.forEach(function (customer) {
            var tr = document.createElement('tr');

            tr.innerHTML =
                '<td>' + escapeHtml(customer.name || '') + '</td>' +
                '<td>' + escapeHtml(customer.email || '') + '</td>' +
                '<td>' + escapeHtml(customer.company || '') + '</td>' +
                '<td>' + escapeHtml(customer.code || '') + '</td>';

            body.appendChild(tr);
        });
    }

    function previewEditor() {
        collectEditorValues();

        var errors = validateSurveyClient(editingSurvey);

        if (errors.length) {
            showMessage(
                'editor-message',
                errors.join('\n'),
                'error'
            );
            return;
        }

        var html =
            '<div class="card">' +
            '<h3 style="margin-top:0">' +
            escapeHtml(editingSurvey.name) +
            '</h3>' +
            '<p style="color:#687887">' +
            escapeHtml(editingSurvey.description) +
            '</p>';

        editingSurvey.groups.forEach(function (group, gi) {
            html +=
                '<h4 style="border-bottom:1px solid #e6ebef;padding-bottom:8px">' +
                escapeHtml(group.name) +
                '</h4>';

            group.questions.forEach(function (question, qi) {
                html +=
                    '<div class="preview-question">' +
                    '<div class="preview-question-title">' +
                    escapeHtml(
                        getQuestionNumber(editingSurvey, gi, qi) +
                        '　' +
                        question.text
                    );

                if (question.required) {
                    html +=
                        ' <span class="badge badge-warn">必須</span>';
                }

                html += '</div>';

                if (question.type === 'free') {
                    html +=
                        '<div style="color:#8a98a5">自由記述</div>';
                } else {
                    question.options.forEach(function (option) {
                        html +=
                            '<div class="preview-option">・' +
                            escapeHtml(option.text);

                        if (question.type === 'single' && option.branch) {
                            html +=
                                ' → ' +
                                (
                                    option.branch === 'END' ?
                                    'アンケート終了' :
                                    escapeHtml(getQuestionLabel(option.branch))
                                );
                        }

                        html += '</div>';
                    });
                }

                html += '</div>';
            });
        });

        html += '</div>';

        openModal(
            'アンケート内容確認',
            html,
            '<button id="preview-close" class="btn">閉じる</button>'
        );

        var close = $('preview-close');

        if (close) {
            close.addEventListener('click', closeModal);
        }
    }

    function openModal(title, body, footer) {
        var modal = $('modal');
        var modalTitle = $('modal-title');
        var modalBody = $('modal-body');
        var modalFooter = $('modal-footer');

        if (!modal || !modalTitle || !modalBody || !modalFooter) {
            return;
        }

        modalTitle.textContent = title || '';
        modalBody.innerHTML = body || '';
        modalFooter.innerHTML = footer || '';

        modal.classList.remove('hidden');
    }

    function closeModal() {
        var modal = $('modal');

        if (modal) {
            modal.classList.add('hidden');
        }
    }

    var navList = $('nav-list');
    if (navList) {
        navList.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    var navCreate = $('nav-create');
    if (navCreate) {
        navCreate.addEventListener('click', openCreate);
    }

    var navCustomers = $('nav-customers');
    if (navCustomers) {
        navCustomers.addEventListener('click', function () {
            renderCustomers();
            showPage('page-customers');
        });
    }

    var navSettings = $('nav-settings');
    if (navSettings) {
        navSettings.addEventListener('click', function () {
            showSettings('mail');
        });
    }

    var btnCreate = $('btn-create');
    if (btnCreate) {
        btnCreate.addEventListener('click', openCreate);
    }

    var btnAddGroup = $('btn-add-group');
    if (btnAddGroup) {
        btnAddGroup.addEventListener('click', function () {
            if (!editingSurvey) {
                return;
            }

            editingSurvey.groups.push({
                id: Date.now(),
                name: '新しいグループ',
                questions: []
            });

            renderEditor();
        });
    }

    var btnEditorBack = $('btn-editor-back');
    if (btnEditorBack) {
        btnEditorBack.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    var btnPreview = $('btn-preview');
    if (btnPreview) {
        btnPreview.addEventListener('click', previewEditor);
    }

    var btnSave = $('btn-save');
    if (btnSave) {
        btnSave.addEventListener('click', function () {
            saveSurvey();
        });
    }

    document.querySelectorAll('input[name="numbering"]').forEach(function (radio) {
        if (radio) {
            radio.addEventListener('change', function () {
                if (editingSurvey) {
                    editingSurvey.numbering = radio.value;
                    renderEditor();
                }
            });
        }
    });

    var btnDetailEdit = $('btn-detail-edit');
    if (btnDetailEdit) {
        btnDetailEdit.addEventListener('click', function () {
            var survey = getCurrentSurvey();

            if (!survey) {
                return;
            }

            editSurvey(survey.id);
        });
    }

    var btnDetailSend = $('btn-detail-send');
    if (btnDetailSend) {
        btnDetailSend.addEventListener('click', function () {
            showDetailTab('send');
        });
    }

    var btnDetailBack = $('btn-detail-back');
    if (btnDetailBack) {
        btnDetailBack.addEventListener('click', function () {
            renderList();
            showPage('page-list');
        });
    }

    ['content', 'send', 'status', 'result'].forEach(function (tab) {
        var button = $('tab-' + tab);

        if (button) {
            button.addEventListener('click', function () {
                showDetailTab(tab);
            });
        }
    });

    var btnCustomerSettings = $('btn-customer-settings');
    if (btnCustomerSettings) {
        btnCustomerSettings.addEventListener('click', function () {
            showSettings('kintone');
        });
    }

    var btnRefreshCustomers = $('btn-refresh-customers');
    if (btnRefreshCustomers) {
        btnRefreshCustomers.addEventListener('click', function () {
            /*
             * 押下直後に disabled / loading を設定してから通信。
             */
            refreshCustomers(btnRefreshCustomers);
        });
    }

    var customerSearch = $('customer-search');
    if (customerSearch) {
        customerSearch.addEventListener('input', renderCustomers);
    }

    var settingsMail = $('settings-tab-mail');
    if (settingsMail) {
        settingsMail.addEventListener('click', function () {
            showSettings('mail');
        });
    }

    var settingsKintone = $('settings-tab-kintone');
    if (settingsKintone) {
        settingsKintone.addEventListener('click', function () {
            showSettings('kintone');
        });
    }

    var modalClose = $('modal-close');
    if (modalClose) {
        modalClose.addEventListener('click', closeModal);
    }

    var modal = $('modal');
    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    renderList();
    showPage('page-list');
});
</script>

</body>
</html>

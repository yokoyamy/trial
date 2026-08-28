<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 * 単一エントリーポイント
 *
 * 重要:
 * - 管理者認証なし（POC）
 * - 回答者画面と管理者画面を分離
 * - 永続化はサーバー側JSON
 * - kintoneはX-Cybozu-Authorization
 * - PHP cURLは使用しない
 * - SMTPはソケット通信
 * - JavaScriptはUI補助。保存等の業務処理はPHP
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR = __DIR__ . '/_data';
const DATA_FILE = DATA_DIR . '/data.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';

const APP_NAME = 'アンケート管理';
const MAX_TITLE = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION = 1000;
const MAX_OPTION = 500;

session_name('survey_app_session');

function app_start(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存フォルダを作成できません。');
        }
    }

    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => cookie_path(),
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!is_file(DATA_FILE)) {
        save_json(DATA_FILE, default_data());
    }

    if (!is_file(SETTINGS_FILE)) {
        save_json(SETTINGS_FILE, default_settings());
    }
}

function cookie_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = dirname($script);

    if ($dir === '.' || $dir === '/' || $dir === '\\') {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

function default_data(): array
{
    $now = date('Y-m-d H:i:s');

    return [
        'surveys' => [
            [
                'id' => 'survey-001',
                'title' => '顧客満足度アンケート',
                'description' => 'サービスについてのご意見をお聞かせください。',
                'startAt' => date('Y-m-d\TH:i'),
                'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
                'status' => 'draft',
                'numbering' => 'global',
                'createdAt' => $now,
                'updatedAt' => $now,
                'groups' => [
                    [
                        'id' => 'group-001',
                        'title' => '基本アンケート',
                        'questions' => [
                            [
                                'id' => 'question-001',
                                'number' => 'Q1',
                                'text' => 'サービスの満足度を教えてください。',
                                'type' => 'single',
                                'required' => true,
                                'options' => [
                                    ['id' => 'option-001', 'label' => '非常に満足'],
                                    ['id' => 'option-002', 'label' => '満足'],
                                    ['id' => 'option-003', 'label' => '普通'],
                                    ['id' => 'option-004', 'label' => '不満'],
                                ],
                            ],
                            [
                                'id' => 'question-002',
                                'number' => 'Q2',
                                'text' => 'ご意見・ご要望があれば入力してください。',
                                'type' => 'text',
                                'required' => false,
                                'options' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'answers' => [],
        'customers' => [],
        'send_history' => [],
    ];
}

function default_settings(): array
{
    return [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'username' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => false,
            'fields' => [],
            'mapping' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
            'last_test' => '',
            'last_sync' => '',
        ],
        'mail' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to' => '',
            'last_test' => '',
        ],
    ];
}

function load_json(string $file, array $fallback): array
{
    if (!is_file($file)) {
        return $fallback;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return $fallback;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : $fallback;
}

function save_json(string $file, array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('JSON保存に失敗しました。');
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('一時ファイルへ保存できません。');
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('保存ファイルを更新できません。');
    }
}

function load_data(): array
{
    $data = load_json(DATA_FILE, default_data());

    foreach (['surveys', 'answers', 'customers', 'send_history'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    return $data;
}

function load_settings(): array
{
    $default = default_settings();
    $settings = load_json(SETTINGS_FILE, $default);

    $settings['kintone'] = array_replace_recursive(
        $default['kintone'],
        is_array($settings['kintone'] ?? null)
            ? $settings['kintone']
            : []
    );

    $settings['mail'] = array_replace_recursive(
        $default['mail'],
        is_array($settings['mail'] ?? null)
            ? $settings['mail']
            : []
    );

    return $settings;
}

function save_data(array $data): void
{
    save_json(DATA_FILE, $data);
}

function save_settings(array $settings): void
{
    save_json(SETTINGS_FILE, $settings);
}

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function post_string(string $key): string
{
    $v = $_POST[$key] ?? '';

    return is_scalar($v) ? trim((string)$v) : '';
}

function get_string(string $key): string
{
    $v = $_GET[$key] ?? '';

    return is_scalar($v) ? trim((string)$v) : '';
}

function post_bool(string $key): bool
{
    return isset($_POST[$key])
        && in_array((string)$_POST[$key], ['1', 'on', 'true'], true);
}

function app_url(array $params = []): string
{
    $base = $_SERVER['SCRIPT_NAME'] ?? 'index.php';

    if (!$params) {
        return $base;
    }

    return $base . '?' . http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

function redirect_screen(string $screen, array $params = []): never
{
    $params = array_merge(['screen' => $screen], $params);

    header('Location: ' . app_url($params));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): ?array
{
    $v = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($v) ? $v : null;
}

function uuid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function survey_index(array $surveys, string $id): int
{
    foreach ($surveys as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function get_survey(array $data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'gray',
        default => 'gray',
    };
}

function auto_end(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
        && strtotime((string)$survey['endAt']) !== false
        && strtotime((string)$survey['endAt']) < time()
    ) {
        $survey['status'] = 'ended';
        $survey['updatedAt'] = date('Y-m-d H:i:s');
        return true;
    }

    return false;
}

function refresh_statuses(array &$data): void
{
    $changed = false;

    foreach ($data['surveys'] as &$survey) {
        if (auto_end($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        save_data($data);
    }
}

function recalc_numbers(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] =
                    'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);
}

function validate_survey(): array
{
    $title = post_string('title');
    $description = post_string('description');
    $startAt = post_string('startAt');
    $endAt = post_string('endAt');
    $numbering = post_string('numbering');

    $errors = [];

    if ($title === '') {
        $errors[] = 'アンケートタイトルは必須です。';
    }

    if (mb_strlen($title) > MAX_TITLE) {
        $errors[] = 'アンケートタイトルが長すぎます。';
    }

    if (mb_strlen($description) > MAX_DESCRIPTION) {
        $errors[] = 'アンケート説明が長すぎます。';
    }

    if (!in_array($numbering, ['global', 'group'], true)) {
        $errors[] = '採番方式が不正です。';
    }

    if ($startAt !== '' && strtotime($startAt) === false) {
        $errors[] = '開始日時が不正です。';
    }

    if ($endAt !== '' && strtotime($endAt) === false) {
        $errors[] = '終了日時が不正です。';
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) !== false
        && strtotime($endAt) !== false
        && strtotime($endAt) < strtotime($startAt)
    ) {
        $errors[] = '終了日時は開始日時以降にしてください。';
    }

    return [
        'errors' => $errors,
        'title' => $title,
        'description' => $description,
        'startAt' => $startAt,
        'endAt' => $endAt,
        'numbering' => $numbering,
    ];
}

/*
 * POSTされた質問構造を一度完全に再構築する。
 *
 * 重要:
 * 新規追加された質問も、既存質問も同じ処理を通す。
 * したがって「追加した質問だけ保存時に扱いが違う」
 * という状態を作らない。
 */
function build_groups_from_post(): array
{
    $groups = [];

    $groupOrder = $_POST['group_order'] ?? [];
    $groupTitles = $_POST['group_title'] ?? [];
    $questionIdsByGroup = $_POST['questions_by_group'] ?? [];
    $questionTexts = $_POST['question_text'] ?? [];
    $questionTypes = $_POST['question_type'] ?? [];
    $questionRequired = $_POST['question_required'] ?? [];
    $questionOptions = $_POST['question_option'] ?? [];
    $branching = $_POST['branching'] ?? [];

    if (!is_array($groupOrder)) {
        $groupOrder = [];
    }

    if (!$groupOrder) {
        $groupOrder = [uuid('group')];
    }

    foreach ($groupOrder as $rawGroupId) {
        $groupId = trim((string)$rawGroupId);

        if ($groupId === '') {
            continue;
        }

        $title = trim((string)($groupTitles[$groupId] ?? ''));

        if ($title === '') {
            $title = '新しいグループ';
        }

        $group = [
            'id' => $groupId,
            'title' => mb_substr($title, 0, 200),
            'questions' => [],
        ];

        $questionIds = $questionIdsByGroup[$groupId] ?? [];

        if (!is_array($questionIds)) {
            $questionIds = [];
        }

        foreach ($questionIds as $rawQuestionId) {
            $questionId = trim((string)$rawQuestionId);

            if ($questionId === '') {
                continue;
            }

            /*
             * 回答形式は必ずPOST値から取得。
             * 新規質問にも同じ処理を適用。
             */
            $type = (string)($questionTypes[$questionId] ?? 'single');

            if (!in_array($type, ['single', 'multiple', 'text'], true)) {
                $type = 'single';
            }

            $text = trim((string)($questionTexts[$questionId] ?? ''));

            $question = [
                'id' => $questionId,
                'number' => '',
                'text' => mb_substr($text, 0, MAX_QUESTION),
                'type' => $type,
                'required' => isset($questionRequired[$questionId]),
                'options' => [],
                'branching' => '',
            ];

            /*
             * 自由記述なら選択肢を保存しない。
             * 単一・複数なら選択肢を保存する。
             */
            if ($type !== 'text') {
                $rawOptions = $questionOptions[$questionId] ?? [];

                if (!is_array($rawOptions)) {
                    $rawOptions = [];
                }

                foreach ($rawOptions as $rawLabel) {
                    $label = trim((string)$rawLabel);

                    if ($label === '') {
                        continue;
                    }

                    $question['options'][] = [
                        'id' => uuid('option'),
                        'label' => mb_substr($label, 0, MAX_OPTION),
                    ];
                }

                if (!$question['options']) {
                    $question['options'] = [
                        ['id' => uuid('option'), 'label' => '選択肢1'],
                        ['id' => uuid('option'), 'label' => '選択肢2'],
                    ];
                }
            }

            if ($type === 'single') {
                $question['branching'] =
                    trim((string)($branching[$questionId] ?? ''));
            }

            $group['questions'][] = $question;
        }

        $groups[] = $group;
    }

    return $groups;
}

function normalize_kintone_subdomain(string $value): string
{
    $value = trim($value);
    $value = preg_replace('#^https?://#i', '', $value) ?? $value;
    $value = rtrim($value, '/');

    if (str_ends_with(strtolower($value), '.cybozu.com')) {
        $value = substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );
    }

    return $value;
}

function validate_kintone(array $config, bool $passwordRequired = false): array
{
    $errors = [];

    $subdomain = normalize_kintone_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    if (
        $subdomain === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $subdomain
        )
    ) {
        $errors[] = 'サブドメインが不正です。';
    }

    $appId = (string)($config['app_id'] ?? '');

    if (!ctype_digit($appId) || (int)$appId < 1) {
        $errors[] = 'アプリIDが不正です。';
    }

    if (trim((string)($config['username'] ?? '')) === '') {
        $errors[] = 'ログイン名を入力してください。';
    }

    if (
        $passwordRequired
        && trim((string)($config['password'] ?? '')) === ''
    ) {
        $errors[] = 'パスワードを入力してください。';
    }

    $proxy = trim((string)($config['proxy'] ?? ''));

    if (
        $proxy !== ''
        && !preg_match('/^[^:\s]+:\d{1,5}$/', $proxy)
    ) {
        $errors[] = 'Proxyはhost:port形式で入力してください。';
    }

    return $errors;
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $errors = validate_kintone($config, true);

    if ($errors) {
        throw new RuntimeException(implode("\n", $errors));
    }

    $subdomain = normalize_kintone_subdomain(
        (string)$config['subdomain']
    );

    $url = 'https://' . $subdomain . '.cybozu.com' . $path;

    $authorization = base64_encode(
        (string)$config['username']
        . ':'
        . (string)$config['password']
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
    ];

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            throw new RuntimeException('JSON生成に失敗しました。');
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $verify = !empty($config['verify_ssl']);

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 30,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
        ],
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '') {
        [$host, $port] = explode(':', $proxy, 2);

        $options['http']['proxy'] =
            'tcp://' . $host . ':' . (int)$port;

        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
            break;
        }
    }

    $raw = $response === false ? '' : $response;
    $json = json_decode($raw, true);

    if ($status < 200 || $status >= 300) {
        $code = is_array($json)
            ? (string)($json['code'] ?? '')
            : '';

        $message = is_array($json)
            ? (string)($json['message'] ?? '')
            : '';

        throw new RuntimeException(
            'kintone APIエラー'
            . ($code !== '' ? ' [' . $code . ']' : '')
            . ($message !== '' ? ' ' . $message : '')
            . ' HTTP ' . $status
        );
    }

    return [
        'status' => $status,
        'body' => is_array($json) ? $json : [],
    ];
}

function kintone_fields(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app='
        . rawurlencode((string)$config['app_id'])
    )['body'];
}

function kintone_records(array $config): array
{
    return kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app='
        . rawurlencode((string)$config['app_id'])
        . '&totalCount=true'
    )['body'];
}

function normalize_fields(array $response): array
{
    $properties = $response['properties'] ?? [];

    if (!is_array($properties)) {
        return [];
    }

    $result = [];

    foreach ($properties as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $result[] = [
            'code' => (string)$code,
            'label' => (string)($field['label'] ?? $code),
            'type' => (string)($field['type'] ?? ''),
        ];
    }

    usort(
        $result,
        static fn($a, $b) =>
            strnatcasecmp($a['code'], $b['code'])
    );

    return $result;
}

function smtp_read($socket): string
{
    $result = '';

    while (($line = fgets($socket, 515)) !== false) {
        $result .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return $result;
}

function smtp_command(
    $socket,
    string $command,
    array $expected
): string {
    fwrite($socket, $command . "\r\n");

    $response = smtp_read($socket);

    if (!preg_match('/^(\d{3})/', $response, $m)) {
        throw new RuntimeException('SMTP応答を解析できません。');
    }

    $code = (int)$m[1];

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . trim($response)
        );
    }

    return $response;
}

function smtp_open(array $config)
{
    $host = trim((string)$config['host']);
    $port = (int)$config['port'];
    $encryption = (string)$config['encryption'];

    if ($host === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('SMTP設定が不正です。');
    }

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $host . ':' . $port;
    } else {
        $target = 'tcp://' . $host . ':' . $port;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        15
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません: ' . $errstr
        );
    }

    stream_set_timeout($socket, 15);

    smtp_command($socket, '', [220]);

    smtp_command(
        $socket,
        'EHLO localhost',
        [250]
    );

    if ($encryption === 'tls') {
        smtp_command(
            $socket,
            'STARTTLS',
            [220]
        );

        if (
            !stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        ) {
            fclose($socket);
            throw new RuntimeException('TLSを開始できません。');
        }

        smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );
    }

    if (!empty($config['auth'])) {
        $username = (string)$config['username'];
        $password = (string)$config['password'];

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);
    }

    return $socket;
}

function smtp_send(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('送信先メールアドレスが不正です。');
    }

    $from = trim((string)$config['from_email']);

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('送信元メールアドレスが不正です。');
    }

    $socket = smtp_open($config);

    smtp_command(
        $socket,
        'MAIL FROM:<' . $from . '>',
        [250]
    );

    smtp_command(
        $socket,
        'RCPT TO:<' . $to . '>',
        [250, 251]
    );

    smtp_command($socket, 'DATA', [354]);

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mb_encode_mimeheader(
            (string)($config['from_name'] ?: $from)
        ) . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . mb_encode_mimeheader($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    if (!empty($config['reply_to'])) {
        $headers[] = 'Reply-To: ' . $config['reply_to'];
    }

    $message =
        implode("\r\n", $headers)
        . "\r\n\r\n"
        . str_replace("\n.", "\n..", $body)
        . "\r\n.";

    smtp_command($socket, $message, [250]);
    smtp_command($socket, 'QUIT', [221]);

    fclose($socket);
}

function handle_post(array &$data, array &$settings): ?string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }

    $action = post_string('action');

    try {
        switch ($action) {

            case 'save_survey':
                $input = validate_survey();

                if ($input['errors']) {
                    flash(
                        'error',
                        implode("\n", $input['errors'])
                    );
                    return null;
                }

                $id = post_string('survey_id');
                $index = survey_index($data['surveys'], $id);

                if ($index < 0) {
                    $survey = [
                        'id' => uuid('survey'),
                        'title' => $input['title'],
                        'description' => $input['description'],
                        'startAt' => $input['startAt'],
                        'endAt' => $input['endAt'],
                        'status' => 'draft',
                        'numbering' => $input['numbering'],
                        'createdAt' => date('Y-m-d H:i:s'),
                        'updatedAt' => date('Y-m-d H:i:s'),
                        'groups' => [],
                    ];

                    $survey['groups'] =
                        build_groups_from_post();

                    recalc_numbers($survey);

                    $data['surveys'][] = $survey;
                } else {
                    $survey = $data['surveys'][$index];

                    $survey['title'] = $input['title'];
                    $survey['description'] = $input['description'];
                    $survey['startAt'] = $input['startAt'];
                    $survey['endAt'] = $input['endAt'];
                    $survey['numbering'] = $input['numbering'];

                    /*
                     * 現在状態を勝手に変更しない。
                     * 状態変更は change_status のみ。
                     */
                    $survey['groups'] =
                        build_groups_from_post();

                    recalc_numbers($survey);

                    $survey['updatedAt'] =
                        date('Y-m-d H:i:s');

                    $data['surveys'][$index] = $survey;
                }

                save_data($data);

                flash('success', 'アンケートを保存しました。');

                return 'list';

            case 'change_status':
                $id = post_string('survey_id');
                $newStatus = post_string('new_status');
                $index = survey_index($data['surveys'], $id);

                if ($index < 0) {
                    throw new RuntimeException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $survey = $data['surveys'][$index];
                $old = (string)$survey['status'];

                if ($old === 'ended') {
                    throw new RuntimeException(
                        '終了したアンケートは状態変更できません。'
                    );
                }

                $allowed = [
                    'draft' => ['published'],
                    'published' => ['stopped'],
                    'stopped' => ['published'],
                ];

                if (
                    !isset($allowed[$old])
                    || !in_array(
                        $newStatus,
                        $allowed[$old],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        '不正な状態変更です。'
                    );
                }

                $survey['status'] = $newStatus;
                $survey['updatedAt'] =
                    date('Y-m-d H:i:s');

                $data['surveys'][$index] = $survey;
                save_data($data);

                flash(
                    'success',
                    '状態を「'
                    . status_label($newStatus)
                    . '」へ変更しました。'
                );

                return 'list';

            case 'delete_survey':
                $id = post_string('survey_id');
                $index = survey_index($data['surveys'], $id);

                if ($index >= 0) {
                    array_splice($data['surveys'], $index, 1);
                    save_data($data);
                    flash('success', '削除しました。');
                }

                return 'list';

            case 'duplicate_survey':
                $id = post_string('survey_id');
                $survey = get_survey($data, $id);

                if ($survey === null) {
                    throw new RuntimeException(
                        '複製対象が見つかりません。'
                    );
                }

                $survey['id'] = uuid('survey');
                $survey['title'] .= '（複製）';
                $survey['status'] = 'draft';
                $survey['createdAt'] =
                    date('Y-m-d H:i:s');
                $survey['updatedAt'] =
                    date('Y-m-d H:i:s');

                $data['surveys'][] = $survey;
                save_data($data);

                flash('success', '下書きとして複製しました。');

                return 'list';

            case 'save_kintone':
                $config =& $settings['kintone'];

                $config['subdomain'] =
                    normalize_kintone_subdomain(
                        post_string('subdomain')
                    );

                $config['app_id'] = post_string('app_id');
                $config['username'] = post_string('username');
                $config['proxy'] = post_string('proxy');
                $config['verify_ssl'] =
                    post_bool('verify_ssl');

                $password = post_string('password');

                if ($password !== '') {
                    $config['password'] = $password;
                }

                $errors = validate_kintone(
                    $config,
                    false
                );

                if ($errors) {
                    throw new RuntimeException(
                        implode("\n", $errors)
                    );
                }

                save_settings($settings);

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                return 'kintone';

            case 'test_kintone':
                $config = $settings['kintone'];

                $password = post_string('password');

                if ($password !== '') {
                    $config['password'] = $password;
                }

                kintone_request(
                    $config,
                    'GET',
                    '/k/v1/app.json?id='
                    . rawurlencode(
                        (string)$config['app_id']
                    )
                );

                $settings['kintone']['last_test'] =
                    date('Y-m-d H:i:s');

                save_settings($settings);

                flash(
                    'success',
                    'kintoneへの接続に成功しました。'
                );

                return 'kintone';

            case 'fetch_kintone_fields':
                $fields = normalize_fields(
                    kintone_fields(
                        $settings['kintone']
                    )
                );

                $settings['kintone']['fields'] = $fields;

                save_settings($settings);

                flash(
                    'success',
                    count($fields)
                    . '件の項目を取得しました。'
                );

                return 'kintone';

            case 'save_kintone_mapping':
                $mapping =& $settings['kintone']['mapping'];

                $mapping['organization'] =
                    post_string('mapping_organization');

                $mapping['name'] =
                    post_string('mapping_name');

                $mapping['email'] =
                    post_string('mapping_email');

                $mapping['department'] =
                    post_string('mapping_department');

                $mapping['phone'] =
                    post_string('mapping_phone');

                $address = $_POST['mapping_address'] ?? [];

                $mapping['address'] =
                    is_array($address)
                    ? array_values(
                        array_map('strval', $address)
                    )
                    : [];

                save_settings($settings);

                flash(
                    'success',
                    '項目マッピングを保存しました。'
                );

                return 'kintone';

            case 'sync_kintone':
                $result = kintone_records(
                    $settings['kintone']
                );

                $customers = [];

                foreach (
                    ($result['records'] ?? []) as $record
                ) {
                    $map = $settings['kintone']['mapping'];

                    $get = static function (
                        string $code
                    ) use ($record): string {
                        if (
                            $code === ''
                            || !isset($record[$code])
                        ) {
                            return '';
                        }

                        $v = $record[$code]['value'] ?? '';

                        if (is_array($v)) {
                            $parts = [];

                            foreach ($v as $item) {
                                if (is_array($item)) {
                                    $parts[] =
                                        (string)(
                                            $item['value']
                                            ?? ''
                                        );
                                } else {
                                    $parts[] =
                                        (string)$item;
                                }
                            }

                            return implode(' ', $parts);
                        }

                        return (string)$v;
                    };

                    $addressParts = [];

                    foreach (
                        $map['address'] ?? []
                        as $code
                    ) {
                        $v = $get((string)$code);

                        if ($v !== '') {
                            $addressParts[] = $v;
                        }
                    }

                    $customers[] = [
                        'id' => uuid('customer'),
                        'organization' =>
                            $get($map['organization']),
                        'name' =>
                            $get($map['name']),
                        'email' =>
                            $get($map['email']),
                        'department' =>
                            $get($map['department']),
                        'phone' =>
                            $get($map['phone']),
                        'address' =>
                            implode(' ', $addressParts),
                    ];
                }

                $data['customers'] = $customers;

                $settings['kintone']['last_sync'] =
                    date('Y-m-d H:i:s');

                save_data($data);
                save_settings($settings);

                flash(
                    'success',
                    count($customers)
                    . '件の顧客情報を同期しました。'
                );

                return 'kintone';

            case 'save_mail':
                $config =& $settings['mail'];

                $config['host'] = post_string('server');
                $config['port'] = (int)post_string('port');
                $config['encryption'] =
                    post_string('encryption');
                $config['auth'] = post_bool('auth');
                $config['username'] =
                    post_string('username');
                $config['from_email'] =
                    post_string('from_email');
                $config['from_name'] =
                    post_string('from_name');
                $config['reply_to'] =
                    post_string('reply_to');

                $password = post_string('password');

                if ($password !== '') {
                    $config['password'] = $password;
                }

                if (
                    $config['host'] === ''
                    || $config['port'] < 1
                    || $config['port'] > 65535
                ) {
                    throw new RuntimeException(
                        'SMTPサーバまたはポートが不正です。'
                    );
                }

                if (
                    !filter_var(
                        $config['from_email'],
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    throw new RuntimeException(
                        '送信元メールアドレスが不正です。'
                    );
                }

                save_settings($settings);

                flash(
                    'success',
                    'SMTP設定を保存しました。'
                );

                return 'mail';

            case 'test_mail':
                $config = $settings['mail'];

                $password = post_string('password');

                if ($password !== '') {
                    $config['password'] = $password;
                }

                $socket = smtp_open($config);

                smtp_command(
                    $socket,
                    'QUIT',
                    [221]
                );

                fclose($socket);

                $settings['mail']['last_test'] =
                    date('Y-m-d H:i:s');

                save_settings($settings);

                flash(
                    'success',
                    'SMTP接続・認証に成功しました。'
                );

                return 'mail';

            case 'send_test_mail':
                $to = post_string('test_email');

                smtp_send(
                    $settings['mail'],
                    $to,
                    'アンケートアプリ テストメール',
                    'SMTPテストメールです。'
                );

                flash(
                    'success',
                    'テストメールを送信しました。'
                );

                return 'mail';

            case 'send_mail':
                $surveyId = post_string('survey_id');
                $survey = get_survey($data, $surveyId);

                if ($survey === null) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $subject = post_string('subject');
                $body = post_string('body');

                $selected = $_POST['customer'] ?? [];

                if (!is_array($selected)) {
                    $selected = [];
                }

                $sent = 0;

                foreach ($data['customers'] as $customer) {
                    if (
                        !in_array(
                            $customer['id'],
                            $selected,
                            true
                        )
                    ) {
                        continue;
                    }

                    if (
                        !filter_var(
                            $customer['email'],
                            FILTER_VALIDATE_EMAIL
                        )
                    ) {
                        continue;
                    }

                    $url = app_url([
                        'screen' => 'answer',
                        'id' => $survey['id'],
                    ]);

                    $mailBody = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [
                            $customer['name'],
                            $url,
                        ],
                        $body
                    );

                    smtp_send(
                        $settings['mail'],
                        $customer['email'],
                        $subject,
                        $mailBody
                    );

                    $data['send_history'][] = [
                        'id' => uuid('send'),
                        'survey_id' => $survey['id'],
                        'customer_id' => $customer['id'],
                        'customer_name' => $customer['name'],
                        'email' => $customer['email'],
                        'sentAt' => date('Y-m-d H:i:s'),
                    ];

                    $sent++;
                }

                save_data($data);

                flash(
                    'success',
                    $sent . '件送信しました。'
                );

                return 'send';

            case 'answer_next':
                $surveyId = post_string('survey_id');
                $survey = get_survey($data, $surveyId);

                if ($survey === null) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $_SESSION['answer_draft'] =
                    is_array($_POST['answer'] ?? null)
                    ? $_POST['answer']
                    : [];

                return 'confirm';

            case 'submit_answer':
                $surveyId = post_string('survey_id');

                $survey = get_survey($data, $surveyId);

                if ($survey === null) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $draft = $_SESSION['answer_draft'] ?? [];

                $data['answers'][] = [
                    'id' => uuid('answer'),
                    'survey_id' => $survey['id'],
                    'values' => $draft,
                    'createdAt' => date('Y-m-d H:i:s'),
                ];

                save_data($data);

                unset($_SESSION['answer_draft']);

                return 'complete';
        }

    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        return null;
    }

    return null;
}

/* =========================================================
 * HTML
 * ========================================================= */

function render_head(
    string $title,
    bool $admin = true
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_NAME) ?></title>

<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --gray:#64748b;
    --gray-light:#f1f5f9;
    --border:#dbe2ea;
    --text:#1e293b;
    --white:#fff;
    --shadow:0 4px 18px rgba(15,23,42,.08);
}

*{box-sizing:border-box}

body{
    margin:0;
    background:#f8fafc;
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
}

a{
    color:var(--primary);
    text-decoration:none;
}

button,
input,
textarea,
select{
    font:inherit;
}

.admin-header{
    background:#0f172a;
    color:#fff;
    padding:14px 24px;
}

.header-inner{
    max-width:1280px;
    margin:auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.header-title{
    font-weight:700;
    font-size:18px;
}

.header-nav{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.header-nav a{
    color:#cbd5e1;
    padding:8px 12px;
    border-radius:8px;
}

.header-nav a:hover{
    background:#1e293b;
    color:#fff;
}

.container{
    max-width:1280px;
    margin:0 auto;
    padding:28px 20px 60px;
}

.answer-shell{
    max-width:760px;
    margin:0 auto;
    padding:30px 16px 60px;
}

.page-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    margin-bottom:22px;
}

.page-title h1{
    margin:0 0 6px;
    font-size:28px;
}

.page-title p{
    margin:0;
    color:var(--gray);
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
    overflow:hidden;
}

.card-header{
    padding:18px 20px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
}

.card-header h2{
    margin:0;
    font-size:19px;
}

.card-body{
    padding:20px;
}

.grid{
    display:grid;
    gap:16px;
}

.grid-2{
    grid-template-columns:repeat(2,minmax(0,1fr));
}

.grid-3{
    grid-template-columns:repeat(3,minmax(0,1fr));
}

.form-group{
    margin-bottom:16px;
}

.form-group > label{
    display:block;
}

.form-group span,
.field-label{
    display:block;
    font-weight:600;
    margin-bottom:7px;
}

input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
input[type=search],
textarea,
select{
    width:100%;
    border:1px solid var(--border);
    border-radius:8px;
    background:#fff;
    color:var(--text);
    padding:10px 12px;
    outline:none;
}

input:focus,
textarea:focus,
select:focus{
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

textarea{
    min-height:120px;
    resize:vertical;
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border:1px solid transparent;
    border-radius:8px;
    padding:9px 14px;
    cursor:pointer;
    font-weight:600;
    background:#fff;
}

.btn-primary{
    color:#fff;
    background:var(--primary);
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-secondary{
    color:var(--text);
    background:#fff;
    border-color:var(--border);
}

.btn-danger{
    color:#fff;
    background:var(--danger);
}

.btn-light{
    color:var(--gray);
    background:var(--gray-light);
    border-color:var(--border);
}

.button-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
}

.sticky-actions{
    position:sticky;
    bottom:0;
    z-index:10;
    background:rgba(248,250,252,.95);
    border-top:1px solid var(--border);
    padding:14px 0;
}

.alert{
    border-radius:10px;
    padding:14px 16px;
    margin-bottom:20px;
    white-space:pre-line;
}

.alert-success{
    background:#dcfce7;
    color:#166534;
}

.alert-error{
    background:#fee2e2;
    color:#991b1b;
}

.alert-warning{
    background:#fef3c7;
    color:#92400e;
}

.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge-success{
    color:#166534;
    background:#dcfce7;
}

.badge-warning{
    color:#92400e;
    background:#fef3c7;
}

.badge-gray{
    color:#475569;
    background:#e2e8f0;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    min-width:900px;
    border-collapse:collapse;
}

th,
td{
    border-bottom:1px solid var(--border);
    padding:12px;
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    white-space:nowrap;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}

.group-card{
    border:1px solid var(--border);
    border-radius:12px;
    background:#fff;
    margin-bottom:18px;
    box-shadow:var(--shadow);
}

.group-head{
    padding:14px 18px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    background:#f8fafc;
}

.group-title-line{
    display:flex;
    align-items:center;
    gap:10px;
}

.question-card{
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
    margin:16px 0;
}

.question-head{
    padding:12px 15px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
}

.question-number{
    display:inline-block;
    color:var(--primary);
    font-weight:700;
    margin-right:8px;
}

.drag-handle{
    cursor:grab;
    color:var(--gray);
    font-size:20px;
    user-select:none;
}

.question-card.dragging,
.group-card.dragging{
    opacity:.5;
    border:2px dashed var(--primary);
}

.drop-target{
    border-top:3px solid var(--primary);
}

.option-row{
    display:flex;
    gap:8px;
    margin-bottom:8px;
    align-items:center;
}

.option-row input{
    flex:1;
}

.check{
    display:flex!important;
    align-items:center;
    gap:8px;
    cursor:pointer;
}

.check input{
    width:auto;
}

.help{
    color:var(--gray);
    font-size:13px;
}

.preview-question{
    padding:16px;
    border:1px solid var(--border);
    border-radius:10px;
    margin:14px 0;
    background:#fff;
}

.answer-option{
    display:flex;
    align-items:center;
    gap:10px;
    border:1px solid var(--border);
    border-radius:10px;
    padding:13px;
    margin:8px 0;
    cursor:pointer;
}

.answer-option input{
    width:20px;
    height:20px;
}

.empty{
    padding:35px;
    text-align:center;
    color:var(--gray);
}

.mapping-list{
    display:grid;
    gap:8px;
}

.mapping-item{
    border:1px solid var(--border);
    border-radius:8px;
    padding:10px;
    background:#fff;
}

@media(max-width:800px){
    .grid-2,
    .grid-3{
        grid-template-columns:1fr;
    }

    .container{
        padding:20px 12px 50px;
    }

    .header-inner{
        align-items:flex-start;
        flex-direction:column;
    }

    .page-title{
        align-items:flex-start;
        flex-direction:column;
    }

    .page-title h1{
        font-size:24px;
    }

    .button-row .btn{
        width:100%;
    }

    .answer-shell{
        padding:16px 10px 45px;
    }

    .card-body{
        padding:15px;
    }
}
</style>
</head>

<body>

<?php if ($admin): ?>

<header class="admin-header">
<div class="header-inner">

<div class="header-title">
<?= h(APP_NAME) ?>
</div>

<nav class="header-nav">
<a href="<?= h(app_url(['screen'=>'list'])) ?>">
アンケート一覧
</a>
<a href="<?= h(app_url(['screen'=>'kintone'])) ?>">
kintone連携
</a>
<a href="<?= h(app_url(['screen'=>'mail'])) ?>">
メール設定
</a>
</nav>

</div>
</header>

<?php endif; ?>

<main class="<?= $admin ? 'container' : 'answer-shell' ?>">

<?php
$flash = consume_flash();

if ($flash):
?>
<div class="alert <?= $flash['type'] === 'success'
    ? 'alert-success'
    : 'alert-error' ?>">
<?= h($flash['message']) ?>
</div>
<?php endif; ?>

<?php
}

function render_footer(): void
{
?>
</main>

<script>
(function(){

'use strict';

/*
 * =========================================================
 * 共通確認
 * =========================================================
 */
document.addEventListener('click', function(e){

    const confirmButton =
        e.target.closest('[data-confirm]');

    if(
        confirmButton &&
        !window.confirm(
            confirmButton.getAttribute('data-confirm')
        )
    ){
        e.preventDefault();
        return;
    }
});

/*
 * =========================================================
 * アンケート編集
 * =========================================================
 */

const groups =
    document.getElementById('groups');

let dragQuestion = null;
let dragGroup = null;

function uid(prefix){
    return prefix + '-' +
        Date.now() + '-' +
        Math.random().toString(16).slice(2);
}

function esc(v){
    return String(v)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function questionTemplate(groupId){

    const qid = uid('question');

    return `
<div class="question-card"
     draggable="true"
     data-question-id="${esc(qid)}">

<input type="hidden"
       name="questions_by_group[${esc(groupId)}][]"
       value="${esc(qid)}">

<div class="question-head">

<div>
<span class="drag-handle">☷</span>
<span class="question-number"
      data-question-number>Q</span>
</div>

<button type="button"
        class="btn btn-danger"
        data-remove-question>
削除
</button>

</div>

<div class="card-body">

<div class="form-group">
<label>
<span>質問文</span>
<input type="text"
       name="question_text[${esc(qid)}]"
       maxlength="1000"
       placeholder="質問文を入力してください">
</label>
</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>回答形式</span>

<select name="question_type[${esc(qid)}]"
        class="js-question-type">

<option value="single">
単一選択
</option>

<option value="multiple">
複数選択
</option>

<option value="text">
自由記述
</option>

</select>
</label>
</div>

<div class="form-group">
<label class="check"
       style="margin-top:30px">

<input type="checkbox"
       name="question_required[${esc(qid)}]"
       value="1">

必須

</label>
</div>

</div>

<div class="question-options">

<div class="form-group">

<label>
<span>選択肢</span>
</label>

<div class="options">

<div class="option-row">
<input type="text"
       name="question_option[${esc(qid)}][]"
       value="選択肢1">

<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>
</div>

<div class="option-row">
<input type="text"
       name="question_option[${esc(qid)}][]"
       value="選択肢2">

<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>
</div>

</div>

<button type="button"
        class="btn btn-secondary"
        data-add-option>
＋ 選択肢追加
</button>

</div>

</div>

<div class="branching-area"
     data-branching-area>
</div>

</div>
</div>`;
}

function groupTemplate(){

    const gid = uid('group');

    return `
<div class="group-card"
     draggable="true"
     data-group-id="${esc(gid)}">

<input type="hidden"
       name="group_order[]"
       value="${esc(gid)}">

<div class="group-head">

<div class="group-title-line">
<span class="drag-handle">☷</span>
<strong>新しいグループ</strong>
</div>

<button type="button"
        class="btn btn-danger"
        data-remove-group>
グループ削除
</button>

</div>

<div class="card-body">

<div class="form-group">
<label>
<span>グループタイトル</span>
<input type="text"
       name="group_title[${esc(gid)}]"
       value="新しいグループ"
       maxlength="200">
</label>
</div>

<div class="questions"></div>

<button type="button"
        class="btn btn-secondary"
        data-add-question>
＋ 質問を追加
</button>

</div>
</div>`;
}

function addGroup(){

    if(!groups){
        return;
    }

    groups.insertAdjacentHTML(
        'beforeend',
        groupTemplate()
    );

    const group =
        groups.lastElementChild;

    addQuestion(group);
    renumber();
}

function addQuestion(group){

    if(!group){
        return;
    }

    const gid =
        group.getAttribute('data-group-id');

    const list =
        group.querySelector('.questions');

    if(!gid || !list){
        return;
    }

    list.insertAdjacentHTML(
        'beforeend',
        questionTemplate(gid)
    );

    /*
     * 新規質問追加直後に回答形式UIを初期化。
     * 「追加したあと別編集をしないと形式を変更できない」
     * という状態を作らない。
     */
    const question =
        list.lastElementChild;

    if(question){
        initializeQuestionType(question);
    }

    renumber();
}

function initializeQuestionType(question){

    if(!question){
        return;
    }

    const select =
        question.querySelector('.js-question-type');

    const options =
        question.querySelector('.question-options');

    if(!select){
        return;
    }

    if(options){
        options.style.display =
            select.value === 'text'
                ? 'none'
                : '';
    }

    /*
     * 新規質問では単一選択が初期値。
     * 形式変更後も即座にDOMを更新する。
     */
}

function changeQuestionType(select){

    const question =
        select.closest('.question-card');

    if(!question){
        return;
    }

    const options =
        question.querySelector('.question-options');

    if(options){
        options.style.display =
            select.value === 'text'
                ? 'none'
                : '';
    }

    /*
     * 自由記述→選択式の場合、選択肢がなければ
     * 即座に2項目を生成する。
     */
    if(
        select.value !== 'text'
        && options
    ){

        const list =
            options.querySelector('.options');

        if(
            list &&
            list.children.length === 0
        ){

            list.insertAdjacentHTML(
                'beforeend',
                `
<div class="option-row">
<input type="text"
       value="選択肢1"
       name="${esc(
           getQuestionOptionName(question)
       )}">
<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>
</div>

<div class="option-row">
<input type="text"
       value="選択肢2"
       name="${esc(
           getQuestionOptionName(question)
       )}">
<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>
</div>
`
            );
        }
    }
}

function getQuestionOptionName(question){

    const qid =
        question.getAttribute('data-question-id');

    return 'question_option[' +
        qid +
        '][]';
}

function addOption(question){

    const options =
        question.querySelector('.options');

    if(!options){
        return;
    }

    const name =
        getQuestionOptionName(question);

    options.insertAdjacentHTML(
        'beforeend',
        `
<div class="option-row">
<input type="text"
       name="${esc(name)}"
       value="">
<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>
</div>
`
    );
}

function renumber(){

    if(!groups){
        return;
    }

    let globalNo = 1;

    const numbering =
        document.getElementById('numbering');

    const mode =
        numbering
            ? numbering.value
            : 'global';

    [...groups.querySelectorAll('.group-card')]
        .forEach((group, gi) => {

            [
                ...group.querySelectorAll(
                    ':scope > .card-body > .questions > .question-card'
                )
            ].forEach((question, qi) => {

                const el =
                    question.querySelector(
                        '[data-question-number]'
                    );

                if(!el){
                    return;
                }

                el.textContent =
                    mode === 'group'
                        ? 'Q' + (gi + 1) + '-' + (qi + 1)
                        : 'Q' + globalNo;

                globalNo++;
            });
        });
}

/*
 * イベントデリゲーション。
 * 動的に追加した質問にも必ず効く。
 */
document.addEventListener(
    'change',
    function(e){

        if(
            e.target.matches(
                '.js-question-type'
            )
        ){
            changeQuestionType(e.target);
            return;
        }

        if(
            e.target.id === 'numbering'
        ){
            renumber();
        }
    }
);

document.addEventListener(
    'click',
    function(e){

        const addGroupButton =
            e.target.closest('[data-add-group]');

        if(addGroupButton){
            e.preventDefault();
            addGroup();
            return;
        }

        const addQuestionButton =
            e.target.closest('[data-add-question]');

        if(addQuestionButton){
            e.preventDefault();

            addQuestion(
                addQuestionButton.closest(
                    '.group-card'
                )
            );

            return;
        }

        const removeGroupButton =
            e.target.closest('[data-remove-group]');

        if(removeGroupButton){

            e.preventDefault();

            const group =
                removeGroupButton.closest(
                    '.group-card'
                );

            if(
                group &&
                window.confirm(
                    'このグループを削除しますか？'
                )
            ){

                if(
                    document.querySelectorAll(
                        '.group-card'
                    ).length <= 1
                ){
                    window.alert(
                        'グループは1つ以上必要です。'
                    );
                    return;
                }

                group.remove();
                renumber();
            }

            return;
        }

        const removeQuestionButton =
            e.target.closest(
                '[data-remove-question]'
            );

        if(removeQuestionButton){

            e.preventDefault();

            if(
                !window.confirm(
                    'この質問を削除しますか？'
                )
            ){
                return;
            }

            const question =
                removeQuestionButton.closest(
                    '.question-card'
                );

            if(question){
                question.remove();
                renumber();
            }

            return;
        }

        const addOptionButton =
            e.target.closest(
                '[data-add-option]'
            );

        if(addOptionButton){

            e.preventDefault();

            const question =
                addOptionButton.closest(
                    '.question-card'
                );

            addOption(question);

            return;
        }

        const removeOptionButton =
            e.target.closest(
                '[data-remove-option]'
            );

        if(removeOptionButton){

            e.preventDefault();

            const row =
                removeOptionButton.closest(
                    '.option-row'
                );

            if(row){
                row.remove();
            }

            return;
        }
    }
);

/*
 * =========================================================
 * 質問ドラッグ＆ドロップ
 * =========================================================
 */

document.addEventListener(
    'dragstart',
    function(e){

        const question =
            e.target.closest(
                '.question-card'
            );

        if(question){

            dragQuestion = question;
            question.classList.add('dragging');

            e.dataTransfer.effectAllowed =
                'move';

            return;
        }

        const group =
            e.target.closest(
                '.group-card'
            );

        if(group){

            dragGroup = group;
            group.classList.add('dragging');

            e.dataTransfer.effectAllowed =
                'move';
        }
    }
);

document.addEventListener(
    'dragend',
    function(){

        document
            .querySelectorAll(
                '.dragging,.drop-target'
            )
            .forEach(el => {
                el.classList.remove(
                    'dragging',
                    'drop-target'
                );
            });

        dragQuestion = null;
        dragGroup = null;

        renumber();
    }
);

document.addEventListener(
    'dragover',
    function(e){

        const target =
            e.target.closest(
                '.question-card'
            );

        if(
            dragQuestion &&
            target &&
            target !== dragQuestion
        ){
            e.preventDefault();
            target.classList.add(
                'drop-target'
            );
        }
    }
);

document.addEventListener(
    'drop',
    function(e){

        if(!dragQuestion){
            return;
        }

        const target =
            e.target.closest(
                '.question-card'
            );

        if(
            target &&
            target !== dragQuestion
        ){

            e.preventDefault();

            const parent =
                target.parentNode;

            const rect =
                target.getBoundingClientRect();

            if(
                e.clientY <
                rect.top + rect.height / 2
            ){
                parent.insertBefore(
                    dragQuestion,
                    target
                );
            }else{
                parent.insertBefore(
                    dragQuestion,
                    target.nextSibling
                );
            }

            renumber();
        }
    }
);

document.addEventListener(
    'dragover',
    function(e){

        const target =
            e.target.closest(
                '.group-card'
            );

        if(
            dragGroup &&
            target &&
            target !== dragGroup
        ){
            e.preventDefault();
        }
    }
);

document.addEventListener(
    'drop',
    function(e){

        if(!dragGroup){
            return;
        }

        const target =
            e.target.closest(
                '.group-card'
            );

        if(
            target &&
            target !== dragGroup
        ){

            e.preventDefault();

            const rect =
                target.getBoundingClientRect();

            if(
                e.clientY <
                rect.top + rect.height / 2
            ){
                target.parentNode.insertBefore(
                    dragGroup,
                    target
                );
            }else{
                target.parentNode.insertBefore(
                    dragGroup,
                    target.nextSibling
                );
            }

            renumber();
        }
    }
);

/*
 * 既存質問も初期化。
 */
document
    .querySelectorAll(
        '.question-card'
    )
    .forEach(initializeQuestionType);

renumber();

/*
 * 顧客検索
 */
const customerSearch =
    document.getElementById(
        'customerSearch'
    );

if(customerSearch){

    customerSearch.addEventListener(
        'input',
        function(){

            const keyword =
                this.value
                    .toLowerCase()
                    .trim();

            document
                .querySelectorAll(
                    '[data-customer-row]'
                )
                .forEach(row => {

                    const text =
                        row.textContent
                            .toLowerCase();

                    row.style.display =
                        !keyword ||
                        text.includes(keyword)
                            ? ''
                            : 'none';
                });
        }
    );
}

})();
</script>

</body>
</html>
<?php
}

/* =========================================================
 * 一覧
 * ========================================================= */

function render_list(array $data): void
{
    render_head('アンケート一覧');

?>
<div class="page-title">

<div>
<h1>アンケート一覧</h1>
<p>アンケートの作成・公開・送信・集計を管理します。</p>
</div>

<a class="btn btn-primary"
   href="<?= h(app_url(['screen'=>'edit'])) ?>">
＋ 新規作成
</a>

</div>

<div class="card">
<div class="card-body">

<form method="get">

<input type="hidden"
       name="screen"
       value="list">

<div class="grid grid-3">

<div class="form-group">
<label>
<span>検索</span>
<input type="search"
       name="q"
       value="<?= h(get_string('q')) ?>"
       placeholder="タイトルを検索">
</label>
</div>

<div class="form-group">
<label>
<span>ステータス</span>

<select name="status">
<option value="">すべて</option>
<option value="published">公開中</option>
<option value="draft">下書き</option>
<option value="stopped">停止</option>
<option value="ended">終了</option>
</select>

</label>
</div>

<div class="form-group">
<label>
<span>ソート</span>

<select name="sort">
<option value="updated_desc">更新日：新しい順</option>
<option value="updated_asc">更新日：古い順</option>
<option value="start_desc">開始日：新しい順</option>
<option value="start_asc">開始日：古い順</option>
</select>

</label>
</div>

</div>

<button class="btn btn-secondary"
        type="submit">
検索
</button>

</form>

</div>
</div>

<?php

$q = get_string('q');
$status = get_string('status');

$surveys = array_filter(
    $data['surveys'],
    static function($survey) use ($q, $status){

        if(
            $q !== ''
            && mb_stripos(
                (string)$survey['title'],
                $q
            ) === false
        ){
            return false;
        }

        if(
            $status !== ''
            && ($survey['status'] ?? '') !== $status
        ){
            return false;
        }

        return true;
    }
);

?>

<div class="card">
<div class="card-body">

<div class="table-wrap">

<table>

<thead>
<tr>
<th>タイトル</th>
<th>作成日</th>
<th>更新日</th>
<th>期間</th>
<th>状態</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>

<tbody>

<?php if(!$surveys): ?>

<tr>
<td colspan="7">
<div class="empty">
アンケートがありません。
</div>
</td>
</tr>

<?php endif; ?>

<?php foreach($surveys as $survey): ?>

<?php
$answers = 0;

foreach($data['answers'] as $answer){
    if(
        ($answer['survey_id'] ?? '')
        === $survey['id']
    ){
        $answers++;
    }
}
?>

<tr>

<td>
<strong><?= h($survey['title']) ?></strong>
</td>

<td><?= h($survey['createdAt']) ?></td>

<td><?= h($survey['updatedAt']) ?></td>

<td>
<?= h($survey['startAt']) ?>
<br>
〜
<br>
<?= h($survey['endAt']) ?>
</td>

<td>
<span class="badge badge-<?= h(
    status_class($survey['status'])
) ?>">
<?= h(status_label($survey['status'])) ?>
</span>
</td>

<td><?= $answers ?></td>

<td>

<div class="actions">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id']
   ])) ?>">
確認・編集
</a>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id']
   ])) ?>">
プレビュー
</a>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'send',
       'id'=>$survey['id']
   ])) ?>">
送信
</a>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'analytics',
       'id'=>$survey['id']
   ])) ?>">
集計
</a>

<form method="post">

<input type="hidden"
       name="action"
       value="duplicate_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-light"
        type="submit"
        data-confirm="このアンケートを複製しますか？">
複製
</button>

</form>

<form method="post">

<input type="hidden"
       name="action"
       value="delete_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-danger"
        type="submit"
        data-confirm="このアンケートを削除しますか？">
削除
</button>

</form>

</div>

</td>

</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>
</div>
</div>

<?php
render_footer();
}

/* =========================================================
 * 編集
 * ========================================================= */

function render_edit(?array $survey): void
{
    render_head(
        $survey
            ? 'アンケート作成・編集'
            : 'アンケート作成'
    );

    if(!$survey){

        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [],
        ];
    }

    if(!$survey['groups']){
        $survey['groups'] = [[
            'id' => 'group-' . bin2hex(random_bytes(4)),
            'title' => '基本アンケート',
            'questions' => [],
        ]];

        $survey['groups'][0]['questions'][] = [
            'id' => 'question-' . bin2hex(random_bytes(4)),
            'number' => 'Q1',
            'text' => '',
            'type' => 'single',
            'required' => false,
            'options' => [
                ['id' => uuid('option'), 'label' => '選択肢1'],
                ['id' => uuid('option'), 'label' => '選択肢2'],
            ],
        ];
    }
?>

<div class="page-title">

<div>
<h1>アンケート作成・編集</h1>
<p>
状態：
<span class="badge badge-<?= h(
    status_class($survey['status'])
) ?>">
<?= h(status_label($survey['status'])) ?>
</span>
</p>
</div>

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
キャンセル
</a>

<?php if($survey['id'] !== ''): ?>

<?php
$nextStatus = match($survey['status']){
    'draft' => 'published',
    'published' => 'stopped',
    'stopped' => 'published',
    default => '',
};

$nextLabel = match($survey['status']){
    'draft' => '公開',
    'published' => '停止',
    'stopped' => '再開',
    default => '',
};
?>

<?php if($nextStatus !== ''): ?>

<form method="post">

<input type="hidden"
       name="action"
       value="change_status">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<input type="hidden"
       name="new_status"
       value="<?= h($nextStatus) ?>">

<button class="btn btn-secondary"
        type="submit"
        data-confirm="状態を変更しますか？">
<?= h($nextLabel) ?>
</button>

</form>

<?php endif; ?>

<?php endif; ?>

</div>
</div>

<form method="post"
      id="surveyEditor">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="card">

<div class="card-header">
<h2>基本情報</h2>
</div>

<div class="card-body">

<div class="form-group">
<label>
<span>アンケートタイトル</span>
<input type="text"
       name="title"
       value="<?= h($survey['title']) ?>"
       maxlength="200"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>アンケート説明</span>
<textarea name="description"
          maxlength="5000"><?= h(
    $survey['description']
) ?></textarea>
</label>
</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>開始日時</span>
<input type="datetime-local"
       name="startAt"
       value="<?= h($survey['startAt']) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h($survey['endAt']) ?>">
</label>
</div>

</div>

<div class="form-group">

<label>
<span>質問番号の採番方式</span>

<select name="numbering"
        id="numbering">

<option value="global"
<?= $survey['numbering'] === 'global'
    ? 'selected'
    : '' ?>>
アンケート全体で通番：Q1、Q2、Q3...
</option>

<option value="group"
<?= $survey['numbering'] === 'group'
    ? 'selected'
    : '' ?>>
グループ毎：Q1-1、Q1-2、Q2-1...
</option>

</select>

</label>

</div>

</div>
</div>

<div id="groups">

<?php foreach($survey['groups'] as $group): ?>

<div class="group-card"
     draggable="true"
     data-group-id="<?= h($group['id']) ?>">

<input type="hidden"
       name="group_order[]"
       value="<?= h($group['id']) ?>">

<div class="group-head">

<div class="group-title-line">

<span class="drag-handle">☷</span>

<strong>
<?= h($group['title']) ?>
</strong>

</div>

<button type="button"
        class="btn btn-danger"
        data-remove-group>
グループ削除
</button>

</div>

<div class="card-body">

<div class="form-group">

<label>
<span>グループタイトル</span>

<input type="text"
       name="group_title[<?= h($group['id']) ?>]"
       value="<?= h($group['title']) ?>"
       maxlength="200">
</label>

</div>

<div class="questions">

<?php foreach($group['questions'] as $question): ?>

<div class="question-card"
     draggable="true"
     data-question-id="<?= h($question['id']) ?>">

<input type="hidden"
       name="questions_by_group[<?= h($group['id']) ?>][]"
       value="<?= h($question['id']) ?>">

<div class="question-head">

<div>
<span class="drag-handle">☷</span>

<span class="question-number"
      data-question-number>
<?= h($question['number']) ?>
</span>
</div>

<button type="button"
        class="btn btn-danger"
        data-remove-question>
削除
</button>

</div>

<div class="card-body">

<div class="form-group">

<label>
<span>質問文</span>

<input type="text"
       name="question_text[<?= h($question['id']) ?>]"
       value="<?= h($question['text']) ?>"
       maxlength="1000">
</label>

</div>

<div class="grid grid-2">

<div class="form-group">

<label>
<span>回答形式</span>

<select name="question_type[<?= h($question['id']) ?>]"
        class="js-question-type">

<option value="single"
<?= $question['type'] === 'single'
    ? 'selected'
    : '' ?>>
単一選択
</option>

<option value="multiple"
<?= $question['type'] === 'multiple'
    ? 'selected'
    : '' ?>>
複数選択
</option>

<option value="text"
<?= $question['type'] === 'text'
    ? 'selected'
    : '' ?>>
自由記述
</option>

</select>

</label>

</div>

<div class="form-group">

<label class="check"
       style="margin-top:30px">

<input type="checkbox"
       name="question_required[<?= h(
           $question['id']
       ) ?>]"
       value="1"
<?= !empty($question['required'])
    ? 'checked'
    : '' ?>>

必須

</label>

</div>

</div>

<div class="question-options">

<div class="form-group">

<label>
<span>選択肢</span>
</label>

<div class="options">

<?php foreach(
    $question['options'] ?? []
    as $option
): ?>

<div class="option-row">

<input type="text"
       name="question_option[<?= h(
           $question['id']
       ) ?>][]"
       value="<?= h($option['label']) ?>"
       maxlength="500">

<button type="button"
        class="btn btn-light"
        data-remove-option>
削除
</button>

</div>

<?php endforeach; ?>

</div>

<button type="button"
        class="btn btn-secondary"
        data-add-option>
＋ 選択肢追加
</button>

</div>

</div>

</div>
</div>

<?php endforeach; ?>

</div>

<button type="button"
        class="btn btn-secondary"
        data-add-question>
＋ 質問を追加
</button>

</div>
</div>

<?php endforeach; ?>

</div>

<div class="card">
<div class="card-body">

<button type="button"
        class="btn btn-secondary"
        data-add-group>
＋ グループを追加
</button>

</div>
</div>

<div class="sticky-actions">

<div class="button-row"
     style="justify-content:flex-end">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'list'
   ])) ?>">
キャンセル
</a>

<button class="btn btn-primary"
        type="submit">
保存して一覧へ
</button>

<?php if($survey['id'] !== ''): ?>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id']
   ])) ?>">
プレビュー
</a>

<?php endif; ?>

</div>

</div>

</form>

<?php
render_footer();
}

/* =========================================================
 * Preview
 * ========================================================= */

function render_preview(array $survey): void
{
    render_head('プレビュー');
?>

<div class="page-title">

<div>
<h1>プレビュー</h1>
<p><?= h($survey['title']) ?></p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$survey['id']
   ])) ?>">
編集へ戻る
</a>

</div>

<div class="card">

<div class="card-body">

<h2><?= h($survey['title']) ?></h2>

<p><?= nl2br(h($survey['description'])) ?></p>

<?php foreach($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach($group['questions'] as $question): ?>

<div class="preview-question">

<div class="field-label">

<?= h($question['number']) ?>

<?= h($question['text']) ?>

<?php if($question['required']): ?>

<span class="badge badge-warning">
必須
</span>

<?php endif; ?>

</div>

<?php if($question['type'] === 'text'): ?>

<textarea
        placeholder="自由記述"></textarea>

<?php else: ?>

<?php foreach(
    $question['options']
    as $option
): ?>

<label class="answer-option">

<input type="<?= $question['type'] === 'single'
    ? 'radio'
    : 'checkbox' ?>">

<span><?= h($option['label']) ?></span>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>
</div>

<?php
render_footer();
}

/* =========================================================
 * kintone
 * ========================================================= */

function render_kintone(array $settings): void
{
    $config = $settings['kintone'];

    render_head('kintone連携設定');
?>

<div class="page-title">

<div>
<h1>kintone連携設定</h1>
<p>顧客情報の取得・同期設定</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>

</div>

<div class="card">

<div class="card-header">
<h2>kintone設定</h2>
</div>

<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid grid-2">

<div class="form-group">

<label>
<span>サブドメイン</span>

<input type="text"
       name="subdomain"
       value="<?= h($config['subdomain']) ?>"
       placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx"
       required>

</label>

</div>

<div class="form-group">

<label>
<span>顧客管理アプリID</span>

<input type="number"
       name="app_id"
       value="<?= h($config['app_id']) ?>"
       min="1"
       required>

</label>

</div>

<div class="form-group">

<label>
<span>ログイン名</span>

<input type="text"
       name="username"
       value="<?= h($config['username']) ?>"
       autocomplete="username"
       required>

</label>

</div>

<div class="form-group">

<label>
<span>パスワード</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更時のみ入力">

</label>

</div>

<div class="form-group">

<label>
<span>Proxy</span>

<input type="text"
       name="proxy"
       value="<?= h($config['proxy']) ?>"
       placeholder="host:port">

</label>

</div>

<div class="form-group">

<label class="check"
       style="margin-top:30px">

<input type="checkbox"
       name="verify_ssl"
       value="1"
<?= !empty($config['verify_ssl'])
    ? 'checked'
    : '' ?>>

SSL証明書を検証する

</label>

<p class="help">
POCでは無効を初期値とします。
</p>

</div>

</div>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>

</form>

<hr style="border:0;border-top:1px solid var(--border);margin:25px 0">

<form method="post">

<input type="hidden"
       name="action"
       value="test_kintone">

<div class="form-group">

<label>
<span>接続テスト用パスワード</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みなら空欄可">

</label>

</div>

<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>

</form>

<?php if($config['last_test']): ?>

<p class="help">
最終接続確認：
<?= h($config['last_test']) ?>
</p>

<?php endif; ?>

</div>
</div>

<div class="card">

<div class="card-header">
<h2>顧客項目マッピング</h2>
</div>

<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="fetch_kintone_fields">

<button class="btn btn-secondary"
        type="submit">
項目一覧を再取得
</button>

</form>

<?php if($config['fields']): ?>

<hr style="border:0;border-top:1px solid var(--border);margin:25px 0">

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="grid grid-2">

<?php
$map = $config['mapping'];
$fields = $config['fields'];
?>

<div class="form-group">

<label>
<span>組織名</span>

<select name="mapping_organization">

<option value="">未設定</option>

<?php foreach($fields as $field): ?>

<option value="<?= h($field['code']) ?>"
<?= $map['organization'] === $field['code']
    ? 'selected'
    : '' ?>>

<?= h($field['label']) ?>
（<?= h($field['code']) ?>）

</option>

<?php endforeach; ?>

</select>

</label>

</div>

<div class="form-group">

<label>
<span>氏名</span>

<select name="mapping_name">

<option value="">未設定</option>

<?php foreach($fields as $field): ?>

<option value="<?= h($field['code']) ?>"
<?= $map['name'] === $field['code']
    ? 'selected'
    : '' ?>>

<?= h($field['label']) ?>

</option>

<?php endforeach; ?>

</select>

</label>

</div>

<div class="form-group">

<label>
<span>メールアドレス</span>

<select name="mapping_email">

<option value="">未設定</option>

<?php foreach($fields as $field): ?>

<option value="<?= h($field['code']) ?>"
<?= $map['email'] === $field['code']
    ? 'selected'
    : '' ?>>

<?= h($field['label']) ?>

</option>

<?php endforeach; ?>

</select>

</label>

</div>

<div class="form-group">

<label>
<span>部署名</span>

<select name="mapping_department">

<option value="">未設定</option>

<?php foreach($fields as $field): ?>

<option value="<?= h($field['code']) ?>"
<?= $map['department'] === $field['code']
    ? 'selected'
    : '' ?>>

<?= h($field['label']) ?>

</option>

<?php endforeach; ?>

</select>

</label>

</div>

<div class="form-group">

<label>
<span>電話番号</span>

<select name="mapping_phone">

<option value="">未設定</option>

<?php foreach($fields as $field): ?>

<option value="<?= h($field['code']) ?>"
<?= $map['phone'] === $field['code']
    ? 'selected'
    : '' ?>>

<?= h($field['label']) ?>

</option>

<?php endforeach; ?>

</select>

</label>

</div>

</div>

<div class="form-group">

<span>住所</span>

<div class="mapping-list">

<?php foreach($fields as $field): ?>

<label class="mapping-item check">

<input type="checkbox"
       name="mapping_address[]"
       value="<?= h($field['code']) ?>"
<?= in_array(
    $field['code'],
    $map['address'] ?? [],
    true
) ? 'checked' : '' ?>>

<?= h($field['label']) ?>
（<?= h($field['code']) ?> /
<?= h($field['type']) ?>）

</label>

<?php endforeach; ?>

</div>

<p class="help">
住所は複数のkintone項目を選択して結合できます。
</p>

</div>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
マッピング保存
</button>

</div>

</form>

<form method="post"
      style="margin-top:15px">

<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="btn btn-secondary"
        type="submit"
        data-confirm="kintoneの顧客情報を同期しますか？">
顧客情報を同期
</button>

</form>

<?php else: ?>

<div class="empty">
まず「項目一覧を再取得」を実行してください。
</div>

<?php endif; ?>

</div>
</div>

<?php
render_footer();
}

/* =========================================================
 * Mail
 * ========================================================= */

function render_mail(array $settings): void
{
    $config = $settings['mail'];

    render_head('メールサーバ設定');
?>

<div class="page-title">

<div>
<h1>メールサーバ設定</h1>
<p>SMTP接続・テスト送信設定</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>

</div>

<div class="card">

<div class="card-header">
<h2>SMTP設定</h2>
</div>

<div class="card-body">

<form method="post">

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>SMTPサーバ</span>
<input type="text"
       name="server"
       value="<?= h($config['host']) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>SMTPポート</span>
<input type="number"
       name="port"
       value="<?= h($config['port']) ?>"
       min="1"
       max="65535"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>暗号化方式</span>

<select name="encryption">

<option value="ssl"
<?= $config['encryption'] === 'ssl'
    ? 'selected'
    : '' ?>>
SSL
</option>

<option value="tls"
<?= $config['encryption'] === 'tls'
    ? 'selected'
    : '' ?>>
TLS
</option>

<option value="none"
<?= $config['encryption'] === 'none'
    ? 'selected'
    : '' ?>>
なし
</option>

</select>

</label>
</div>

<div class="form-group">

<label class="check"
       style="margin-top:30px">

<input type="checkbox"
       name="auth"
       value="1"
<?= !empty($config['auth'])
    ? 'checked'
    : '' ?>>

SMTP認証

</label>

</div>

<div class="form-group">
<label>
<span>SMTPユーザー名</span>
<input type="text"
       name="username"
       value="<?= h($config['username']) ?>"
       autocomplete="username">
</label>
</div>

<div class="form-group">
<label>
<span>SMTPパスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更時のみ入力">
</label>
</div>

<div class="form-group">
<label>
<span>送信元メールアドレス</span>
<input type="email"
       name="from_email"
       value="<?= h($config['from_email']) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>送信元名</span>
<input type="text"
       name="from_name"
       value="<?= h($config['from_name']) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>返信先メールアドレス</span>
<input type="email"
       name="reply_to"
       value="<?= h($config['reply_to']) ?>">
</label>
</div>

</div>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>

</form>

<hr style="border:0;border-top:1px solid var(--border);margin:25px 0">

<h3>接続状態</h3>

<?php if($config['last_test']): ?>

<span class="badge badge-success">
接続確認済み
</span>

<p class="help">
<?= h($config['last_test']) ?>
</p>

<?php else: ?>

<span class="badge badge-gray">
未設定
</span>

<?php endif; ?>

<form method="post"
      style="margin-top:18px">

<input type="hidden"
       name="action"
       value="test_mail">

<div class="form-group">

<label>
<span>接続テスト用パスワード</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みなら空欄可">

</label>

</div>

<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>

</form>

<hr style="border:0;border-top:1px solid var(--border);margin:25px 0">

<form method="post">

<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="form-group">

<label>
<span>テスト送信先メールアドレス</span>

<input type="email"
       name="test_email"
       required>

</label>

</div>

<button class="btn btn-primary"
        type="submit"
        data-confirm="テストメールを送信しますか？">
テストメール送信
</button>

</form>

</div>
</div>

<?php
render_footer();
}

/* =========================================================
 * Send
 * ========================================================= */

function render_send(
    array $survey,
    array $data
): void {
    render_head('顧客選択・メール送信');
?>

<div class="page-title">

<div>
<h1>顧客選択・メール送信</h1>
<p>
対象アンケート：
<strong><?= h($survey['title']) ?></strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>

</div>

<div class="card">

<div class="card-header">
<h2>顧客選択・メール作成</h2>
</div>

<div class="card-body">

<div class="alert alert-warning">
メール変数：
{顧客名} / {アンケートURL}
</div>

<form method="post">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="form-group">

<label>
<span>顧客検索</span>

<input type="search"
       id="customerSearch"
       placeholder="氏名・組織名・メールアドレス等">

</label>

</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>選択</th>
<th>氏名</th>
<th>組織</th>
<th>メール</th>
</tr>
</thead>

<tbody>

<?php foreach(
    $data['customers']
    as $customer
): ?>

<tr data-customer-row>

<td>
<input type="checkbox"
       name="customer[]"
       value="<?= h($customer['id']) ?>">
</td>

<td><?= h($customer['name']) ?></td>

<td><?= h($customer['organization']) ?></td>

<td><?= h($customer['email']) ?></td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<div class="form-group"
     style="margin-top:20px">

<label>
<span>メール件名</span>

<input type="text"
       name="subject"
       value="<?= h($survey['title']) ?>"
       required>

</label>

</div>

<div class="form-group">

<label>
<span>メール本文</span>

<textarea name="body"
          required> {顧客名} 様

アンケートへのご協力をお願いいたします。

以下のURLからご回答ください。

{アンケートURL}

よろしくお願いいたします。</textarea>

</label>

</div>

<button class="btn btn-primary"
        type="submit"
        data-confirm="選択した顧客へメールを送信しますか？">
一括送信
</button>

</form>

</div>
</div>

<div class="card">

<div class="card-header">
<h2>送信履歴</h2>
</div>

<div class="card-body">

<div class="table-wrap">

<table>

<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>メール</th>
</tr>
</thead>

<tbody>

<?php
$history = array_reverse(
    array_filter(
        $data['send_history'],
        static fn($h) =>
            ($h['survey_id'] ?? '')
            === $survey['id']
    )
);
?>

<?php if(!$history): ?>

<tr>
<td colspan="3">
<div class="empty">
送信履歴はありません。
</div>
</td>
</tr>

<?php endif; ?>

<?php foreach($history as $item): ?>

<tr>
<td><?= h($item['sentAt']) ?></td>
<td><?= h($item['customer_name']) ?></td>
<td><?= h($item['email']) ?></td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>
</div>
</div>

<?php
render_footer();
}

/* =========================================================
 * Analytics
 * ========================================================= */

function render_analytics(
    array $survey,
    array $data
): void {
    render_head('回答集計・分析');
?>

<div class="page-title">

<div>
<h1>回答集計・分析</h1>
<p>
対象アンケート：
<strong><?= h($survey['title']) ?></strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url(['screen'=>'list'])) ?>">
一覧へ戻る
</a>

</div>

<?php

$answers = array_values(
    array_filter(
        $data['answers'],
        static fn($a) =>
            ($a['survey_id'] ?? '')
            === $survey['id']
    )
);

?>

<div class="card">

<div class="card-body">

<div class="grid grid-3">

<div>
<strong>送信対象者数</strong>
<p><?= count($data['customers']) ?></p>
</div>

<div>
<strong>回答数</strong>
<p><?= count($answers) ?></p>
</div>

<div>
<strong>未回答数</strong>
<p>
<?= max(
    0,
    count($data['customers'])
    - count($answers)
) ?>
</p>
</div>

</div>

<?php if(!$answers): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<?php foreach(
    $survey['groups']
    as $group
): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach(
    $group['questions']
    as $question
): ?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<?php
$counts = [];

foreach($answers as $answer){

    $v =
        $answer['values'][$question['id']]
        ?? '';

    if(is_array($v)){
        foreach($v as $x){
            $counts[(string)$x] =
                ($counts[(string)$x] ?? 0) + 1;
        }
    }else{
        $v = (string)$v;

        if($v !== ''){
            $counts[$v] =
                ($counts[$v] ?? 0) + 1;
        }
    }
}
?>

<?php if(!$counts): ?>

<p class="help">回答なし</p>

<?php else: ?>

<?php foreach($counts as $label => $count): ?>

<div style="margin-top:8px">
<?= h($label) ?>
：
<strong><?= $count ?></strong>件
</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<?php
render_footer();
}

/* =========================================================
 * Answer
 * ========================================================= */

function render_answer(array $survey): void
{
    render_head('アンケート回答', false);

    $draft =
        $_SESSION['answer_draft'] ?? [];
?>

<div class="answer-shell">

<div class="page-title">

<div>
<h1><?= h($survey['title']) ?></h1>
<p><?= nl2br(h($survey['description'])) ?></p>
</div>

</div>

<form method="post">

<input type="hidden"
       name="action"
       value="answer_next">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="card">
<div class="card-body">

<?php foreach(
    $survey['groups']
    as $group
): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach(
    $group['questions']
    as $question
): ?>

<div class="form-group">

<div class="field-label">

<?= h($question['number']) ?>
<?= h($question['text']) ?>

<?php if($question['required']): ?>

<span class="badge badge-warning">
必須
</span>

<?php endif; ?>

</div>

<?php if(
    $question['type'] === 'text'
): ?>

<textarea
name="answer[<?= h($question['id']) ?>]"
<?= $question['required']
    ? 'required'
    : '' ?>><?= h(
        is_string(
            $draft[$question['id']] ?? ''
        )
        ? $draft[$question['id']] ?? ''
        : ''
    ) ?></textarea>

<?php else: ?>

<?php foreach(
    $question['options']
    as $option
): ?>

<label class="answer-option">

<input
type="<?= $question['type'] === 'single'
    ? 'radio'
    : 'checkbox' ?>"
name="answer[<?= h($question['id']) ?>]<?= $question['type'] === 'multiple'
    ? '[]'
    : '' ?>"
value="<?= h($option['label']) ?>">

<span><?= h($option['label']) ?></span>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>
</div>

<div class="button-row"
     style="justify-content:flex-end">

<button class="btn btn-primary"
        type="submit">
回答確認へ
</button>

</div>

</form>

</div>

<?php
render_footer();
}

/* =========================================================
 * Confirm
 * ========================================================= */

function render_confirm(array $survey): void
{
    render_head('回答確認', false);

    $draft =
        $_SESSION['answer_draft'] ?? [];
?>

<div class="answer-shell">

<div class="page-title">

<div>
<h1>回答確認</h1>
<p><?= h($survey['title']) ?></p>
</div>

</div>

<div class="card">
<div class="card-body">

<?php foreach(
    $survey['groups']
    as $group
): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach(
    $group['questions']
    as $question
): ?>

<?php

$value =
    $draft[$question['id']] ?? '';

if(is_array($value)){
    $value = implode(
        '、',
        array_map('strval', $value)
    );
}

?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<p>
<?= nl2br(h((string)$value)) ?>
</p>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen'=>'answer',
       'id'=>$survey['id']
   ])) ?>">
修正する
</a>

<form method="post">

<input type="hidden"
       name="action"
       value="submit_answer">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-primary"
        type="submit"
        data-confirm="回答を送信しますか？">
回答を送信
</button>

</form>

</div>

</div>
</div>

</div>

<?php
render_footer();
}

/* =========================================================
 * Complete
 * ========================================================= */

function render_complete(array $survey): void
{
    render_head('回答完了', false);
?>

<div class="answer-shell">

<div class="card">

<div class="card-body"
     style="text-align:center;padding:55px 25px">

<h1>回答ありがとうございました</h1>

<p>
「<?= h($survey['title']) ?>」への回答を受け付けました。
</p>

</div>

</div>

</div>

<?php
render_footer();
}

/* =========================================================
 * Main
 * ========================================================= */

try {

    app_start();

    $data = load_data();
    $settings = load_settings();

    refresh_statuses($data);

    $redirect =
        handle_post(
            $data,
            $settings
        );

    if($redirect !== null){

        if(
            $redirect === 'confirm'
            && isset($_POST['survey_id'])
        ){
            redirect_screen(
                'confirm',
                [
                    'id' =>
                        post_string('survey_id')
                ]
            );
        }

        if(
            in_array(
                $redirect,
                [
                    'send',
                    'analytics',
                    'edit',
                    'preview',
                    'answer',
                    'complete'
                ],
                true
            )
        ){
            $id =
                post_string('survey_id');

            if($id !== ''){
                redirect_screen(
                    $redirect,
                    ['id'=>$id]
                );
            }
        }

        redirect_screen($redirect);
    }

    $data = load_data();
    $settings = load_settings();

    refresh_statuses($data);

    $screen =
        get_string('screen');

    if($screen === ''){
        $screen = 'list';
    }

    /*
     * 回答者画面
     */
    if(
        in_array(
            $screen,
            ['answer','confirm','complete'],
            true
        )
    ){

        $id =
            get_string('id');

        $survey =
            get_survey($data, $id);

        if($survey === null){
            render_head(
                'アンケート',
                false
            );

            ?>

            <div class="alert alert-error">
            アンケートが見つかりません。
            </div>

            <?php

            render_footer();
            exit;
        }

        if(
            $screen === 'answer'
        ){
            render_answer($survey);
        }elseif(
            $screen === 'confirm'
        ){
            render_confirm($survey);
        }else{
            render_complete($survey);
        }

        exit;
    }

    /*
     * 管理者画面
     */
    switch($screen){

        case 'edit':

            $survey =
                get_survey(
                    $data,
                    get_string('id')
                );

            render_edit($survey);
            break;

        case 'preview':

            $survey =
                get_survey(
                    $data,
                    get_string('id')
                );

            if($survey === null){
                redirect_screen('list');
            }

            render_preview($survey);
            break;

        case 'send':

            $survey =
                get_survey(
                    $data,
                    get_string('id')
                );

            if($survey === null){
                redirect_screen('list');
            }

            render_send(
                $survey,
                $data
            );
            break;

        case 'analytics':

            $survey =
                get_survey(
                    $data,
                    get_string('id')
                );

            if($survey === null){
                redirect_screen('list');
            }

            render_analytics(
                $survey,
                $data
            );
            break;

        case 'kintone':

            render_kintone(
                $settings
            );
            break;

        case 'mail':

            render_mail(
                $settings
            );
            break;

        case 'list':
        default:

            render_list($data);
            break;
    }

} catch(Throwable $e){

    http_response_code(500);

    render_head(
        'システムエラー'
    );

    ?>

    <div class="alert alert-error">

    処理中にエラーが発生しました。

    <br><br>

    <?= h($e->getMessage()) ?>

    </div>

    <?php

    render_footer();
}
?>
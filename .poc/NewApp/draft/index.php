<?php
declare(strict_types=1);

namespace jacic\gojacic\survey_manager;

\session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

\header('X-Frame-Options: SAMEORIGIN');
\header('X-Content-Type-Options: nosniff');
\header('Referrer-Policy: same-origin');

const APP_SESSION_KEY = 'jacic_gojacic_survey_manager';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

function h(?string $str): string
{
    return \htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $response): never
{
    while (\ob_get_level() > 0) {
        \ob_end_clean();
    }

    \header('Content-Type: application/json; charset=utf-8');
    echo \json_encode(
        $response,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function app_session(): array
{
    if (!isset($_SESSION[APP_SESSION_KEY]) || !is_array($_SESSION[APP_SESSION_KEY])) {
        $_SESSION[APP_SESSION_KEY] = [];
    }

    return $_SESSION[APP_SESSION_KEY];
}

function csrf_token(): string
{
    if (
        !isset($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
        !is_string($_SESSION[APP_SESSION_KEY]['csrf_token']) ||
        $_SESSION[APP_SESSION_KEY]['csrf_token'] === ''
    ) {
        $_SESSION[APP_SESSION_KEY]['csrf_token'] = \bin2hex(\random_bytes(32));
    }

    return $_SESSION[APP_SESSION_KEY]['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_POST['csrf_token']
        ?? '';

    if (
        !is_string($token) ||
        !\hash_equals(csrf_token(), $token)
    ) {
        json_response([
            'success' => false,
            'message' => 'CSRF検証に失敗しました。ページを再読み込みしてください。',
        ]);
    }
}

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!\mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new \RuntimeException('dataディレクトリを作成できません。');
        }
    }
}

function data_file(string $name): string
{
    ensure_data_dir();

    $allowed = [
        'settings',
        'surveys',
        'groups',
        'questions',
        'options',
        'customers',
        'recipients',
        'mail_logs',
        'responses',
        'response_answers',
    ];

    if (!in_array($name, $allowed, true)) {
        throw new \InvalidArgumentException('不正なデータファイルです。');
    }

    return DATA_DIR . DIRECTORY_SEPARATOR . $name . '.json';
}

function read_json(string $name): array
{
    $file = data_file($name);

    if (!file_exists($file)) {
        return [];
    }

    $raw = @file_get_contents($file);

    if ($raw === false) {
        throw new \RuntimeException('JSONファイルを読み込めません。');
    }

    $data = \json_decode($raw, true);

    if (!is_array($data)) {
        throw new \RuntimeException('JSONデータが壊れています。');
    }

    return $data;
}

function write_json(string $name, array $data): void
{
    $file = data_file($name);
    $tmp = $file . '.tmp';

    $json = \json_encode(
        array_values($data),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new \RuntimeException('JSON変換に失敗しました。');
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new \RuntimeException('JSONファイルを保存できません。');
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new \RuntimeException('JSONファイルの更新に失敗しました。');
    }
}

function id(): string
{
    return \bin2hex(\random_bytes(16));
}

function now(): string
{
    return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function find_record(array $records, string $id): ?array
{
    foreach ($records as $record) {
        if (($record['id'] ?? '') === $id) {
            return $record;
        }
    }

    return null;
}

function find_index(array $records, string $id): int
{
    foreach ($records as $index => $record) {
        if (($record['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function sort_by_order(array &$records): void
{
    usort(
        $records,
        static fn(array $a, array $b): int =>
            ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0))
    );
}

function validate_survey_payload(array $payload): array
{
    $errors = [];

    $name = trim((string)($payload['name'] ?? ''));

    if ($name === '') {
        $errors[] = 'アンケート名は必須です。';
    }

    if (\mb_strlen($name) > 200) {
        $errors[] = 'アンケート名は200文字以内で入力してください。';
    }

    $description = (string)($payload['description'] ?? '');

    if (\mb_strlen($description) > 5000) {
        $errors[] = '説明文は5000文字以内で入力してください。';
    }

    $number_format = (string)($payload['number_format'] ?? 'overall');

    if (!in_array($number_format, ['overall', 'group'], true)) {
        $errors[] = '質問番号形式が不正です。';
    }

    $groups = $payload['groups'] ?? [];

    if (!is_array($groups)) {
        $errors[] = 'グループデータが不正です。';
        return $errors;
    }

    foreach ($groups as $gi => $group) {
        if (!is_array($group)) {
            $errors[] = 'グループデータが不正です。';
            continue;
        }

        $group_name = trim((string)($group['name'] ?? ''));

        if ($group_name === '') {
            $errors[] = 'グループ名は必須です。';
        }

        $questions = $group['questions'] ?? [];

        if (!is_array($questions)) {
            $errors[] = '質問データが不正です。';
            continue;
        }

        foreach ($questions as $qi => $question) {
            if (!is_array($question)) {
                $errors[] = '質問データが不正です。';
                continue;
            }

            $text = trim((string)($question['text'] ?? ''));
            $type = (string)($question['type'] ?? 'text');

            if ($text === '') {
                $errors[] = '質問文は必須です。';
            }

            if (!in_array($type, ['text', 'single', 'multiple'], true)) {
                $errors[] = '回答形式が不正です。';
            }

            if (in_array($type, ['single', 'multiple'], true)) {
                $choices = $question['choices'] ?? [];

                if (!is_array($choices) || count($choices) === 0) {
                    $errors[] = '選択式質問には選択肢が必要です。';
                }

                foreach ((array)$choices as $choice) {
                    if (trim((string)($choice['text'] ?? '')) === '') {
                        $errors[] = '選択肢の文言は必須です。';
                    }
                }
            }
        }
    }

    return $errors;
}

function save_survey(array $payload): string
{
    $errors = validate_survey_payload($payload);

    if ($errors !== []) {
        throw new \InvalidArgumentException(implode("\n", $errors));
    }

    $surveys = read_json('surveys');
    $groups_db = read_json('groups');
    $questions_db = read_json('questions');
    $options_db = read_json('options');

    $survey_id = trim((string)($payload['id'] ?? ''));

    if ($survey_id === '') {
        $survey_id = id();
        $created_at = now();
        $status = 'draft';
    } else {
        $existing = find_record($surveys, $survey_id);

        if ($existing === null) {
            throw new \RuntimeException('対象アンケートが存在しません。');
        }

        $created_at = (string)$existing['created_at'];
        $status = (string)$existing['status'];

        if ($status !== 'draft') {
            throw new \RuntimeException(
                '公開中または終了済みのアンケートは編集できません。'
            );
        }

        $groups_db = array_values(
            array_filter(
                $groups_db,
                static fn(array $row): bool =>
                    ($row['survey_id'] ?? '') !== $survey_id
            )
        );

        $group_ids = [];

        foreach ($questions_db as $row) {
            if (in_array($row['group_id'] ?? '', $group_ids, true)) {
                continue;
            }
        }

        $questions_db = array_values(
            array_filter(
                $questions_db,
                static fn(array $row): bool =>
                    !in_array(
                        $row['group_id'] ?? '',
                        array_column(
                            array_filter(
                                $groups_db,
                                static fn(array $g): bool =>
                                    ($g['survey_id'] ?? '') === $survey_id
                            ),
                            'id'
                        ),
                        true
                    )
            )
        );
    }

    $created_group_ids = [];

    foreach ((array)($payload['groups'] ?? []) as $gi => $group) {
        $group_id = trim((string)($group['id'] ?? ''));

        if ($group_id === '') {
            $group_id = id();
        }

        $created_group_ids[] = $group_id;

        $groups_db[] = [
            'id' => $group_id,
            'survey_id' => $survey_id,
            'name' => trim((string)$group['name']),
            'sort_order' => $gi + 1,
        ];

        foreach ((array)($group['questions'] ?? []) as $qi => $question) {
            $question_id = trim((string)($question['id'] ?? ''));

            if ($question_id === '') {
                $question_id = id();
            }

            $questions_db[] = [
                'id' => $question_id,
                'group_id' => $group_id,
                'text' => trim((string)$question['text']),
                'type' => (string)$question['type'],
                'required' => !empty($question['required']),
                'sort_order' => $qi + 1,
            ];

            foreach ((array)($question['choices'] ?? []) as $oi => $choice) {
                $options_db[] = [
                    'id' => trim((string)($choice['id'] ?? '')) ?: id(),
                    'question_id' => $question_id,
                    'text' => trim((string)($choice['text'] ?? '')),
                    'sort_order' => $oi + 1,
                    'branch_question_id' =>
                        trim((string)($choice['branch_question_id'] ?? '')),
                    'branch_end' => !empty($choice['branch_end']),
                ];
            }
        }
    }

    $existing_index = find_index($surveys, $survey_id);

    $survey = [
        'id' => $survey_id,
        'name' => trim((string)$payload['name']),
        'description' => (string)($payload['description'] ?? ''),
        'status' => $status,
        'start_at' => trim((string)($payload['start_at'] ?? '')),
        'end_at' => trim((string)($payload['end_at'] ?? '')),
        'number_format' => (string)($payload['number_format'] ?? 'overall'),
        'created_at' => $created_at,
        'updated_at' => now(),
    ];

    if ($existing_index >= 0) {
        $surveys[$existing_index] = $survey;
    } else {
        $surveys[] = $survey;
    }

    write_json('surveys', $surveys);
    write_json('groups', $groups_db);
    write_json('questions', $questions_db);
    write_json('options', $options_db);

    return $survey_id;
}

function build_survey(string $survey_id): ?array
{
    $survey = find_record(read_json('surveys'), $survey_id);

    if ($survey === null) {
        return null;
    }

    $groups = array_values(
        array_filter(
            read_json('groups'),
            static fn(array $g): bool =>
                ($g['survey_id'] ?? '') === $survey_id
        )
    );

    sort_by_order($groups);

    $questions_all = read_json('questions');
    $options_all = read_json('options');

    foreach ($groups as &$group) {
        $questions = array_values(
            array_filter(
                $questions_all,
                static fn(array $q): bool =>
                    ($q['group_id'] ?? '') === ($group['id'] ?? '')
            )
        );

        sort_by_order($questions);

        foreach ($questions as &$question) {
            $choices = array_values(
                array_filter(
                    $options_all,
                    static fn(array $o): bool =>
                        ($o['question_id'] ?? '') === ($question['id'] ?? '')
                )
            );

            sort_by_order($choices);
            $question['choices'] = $choices;
        }

        unset($question);

        $group['questions'] = $questions;
    }

    unset($group);

    $survey['groups'] = $groups;

    return $survey;
}

function validate_branch_rules(array $survey): array
{
    $question_ids = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $question_ids[] = $question['id'];
        }
    }

    $errors = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            if (($question['type'] ?? '') !== 'single') {
                continue;
            }

            foreach ($question['choices'] ?? [] as $choice) {
                $target = trim((string)($choice['branch_question_id'] ?? ''));

                if ($target !== '' && !in_array($target, $question_ids, true)) {
                    $errors[] =
                        '「' .
                        (string)$question['text'] .
                        '」の分岐先が存在しません。';
                }

                if (
                    $target !== '' &&
                    $target === ($question['id'] ?? '')
                ) {
                    $errors[] =
                        '質問自身を分岐先に指定することはできません。';
                }
            }
        }
    }

    return $errors;
}

function publish_survey(string $survey_id): void
{
    $survey = build_survey($survey_id);

    if ($survey === null) {
        throw new \RuntimeException('アンケートが存在しません。');
    }

    if (($survey['status'] ?? '') !== 'draft') {
        throw new \RuntimeException('公開できるのは下書きだけです。');
    }

    $errors = validate_survey_payload($survey);

    if ($errors !== []) {
        throw new \RuntimeException(implode("\n", $errors));
    }

    $branch_errors = validate_branch_rules($survey);

    if ($branch_errors !== []) {
        throw new \RuntimeException(implode("\n", $branch_errors));
    }

    $surveys = read_json('surveys');
    $index = find_index($surveys, $survey_id);

    if ($index < 0) {
        throw new \RuntimeException('アンケートが存在しません。');
    }

    $surveys[$index]['status'] = 'active';
    $surveys[$index]['updated_at'] = now();

    write_json('surveys', $surveys);
}

function close_survey(string $survey_id): void
{
    $surveys = read_json('surveys');
    $index = find_index($surveys, $survey_id);

    if ($index < 0) {
        throw new \RuntimeException('アンケートが存在しません。');
    }

    if (($surveys[$index]['status'] ?? '') !== 'active') {
        throw new \RuntimeException('公開中のアンケートだけ終了できます。');
    }

    $surveys[$index]['status'] = 'closed';
    $surveys[$index]['updated_at'] = now();

    write_json('surveys', $surveys);
}

function delete_survey(string $survey_id): void
{
    $surveys = read_json('surveys');
    $survey = find_record($surveys, $survey_id);

    if ($survey === null) {
        throw new \RuntimeException('アンケートが存在しません。');
    }

    if (($survey['status'] ?? '') !== 'draft') {
        throw new \RuntimeException(
            '公開中または終了済みのアンケートは削除できません。'
        );
    }

    $surveys = array_values(
        array_filter(
            $surveys,
            static fn(array $row): bool =>
                ($row['id'] ?? '') !== $survey_id
        )
    );

    $groups = read_json('groups');

    $group_ids = [];

    foreach ($groups as $group) {
        if (($group['survey_id'] ?? '') === $survey_id) {
            $group_ids[] = $group['id'];
        }
    }

    $groups = array_values(
        array_filter(
            $groups,
            static fn(array $row): bool =>
                ($row['survey_id'] ?? '') !== $survey_id
        )
    );

    $questions = read_json('questions');
    $question_ids = [];

    foreach ($questions as $question) {
        if (in_array($question['group_id'] ?? '', $group_ids, true)) {
            $question_ids[] = $question['id'];
        }
    }

    $questions = array_values(
        array_filter(
            $questions,
            static fn(array $row): bool =>
                !in_array($row['group_id'] ?? '', $group_ids, true)
        )
    );

    $options = array_values(
        array_filter(
            read_json('options'),
            static fn(array $row): bool =>
                !in_array($row['question_id'] ?? '', $question_ids, true)
        )
    );

    write_json('surveys', $surveys);
    write_json('groups', $groups);
    write_json('questions', $questions);
    write_json('options', $options);
}

function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain) ?? $domain;
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain) ?? $domain;
    $domain = rtrim($domain, '/');

    return 'https://' . $domain . '.cybozu.com/' .
        ltrim($endpoint, '/');
}

function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        return http_get_last_response_headers() ?? [];
    }

    return [];
}

function make_cybozu_auth_header(
    string $login_name,
    string $password
): string {
    $auth = base64_encode(trim($login_name) . ':' . trim($password));

    return 'X-Cybozu-Authorization: ' . $auth;
}

function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
    ];

    if ($method !== 'GET' && $payload !== null) {
        $options['content'] = is_string($payload)
            ? $payload
            : json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
    }

    $context_options = [
        'http' => $options,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ];

    $proxy = trim((string)($config['proxy_host_port'] ?? ''));

    if ($proxy !== '') {
        $context_options['http']['proxy'] = 'tcp://' . $proxy;
        $context_options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($context_options);

    $body = @file_get_contents($url, false, $context);
    $headers_response = get_safe_response_headers();

    $status = 500;

    foreach ($headers_response as $header) {
        if (
            preg_match(
                '/HTTP\/\d(?:\.\d)?\s+(\d+)/i',
                $header,
                $matches
            )
        ) {
            $status = (int)$matches[1];
            break;
        }
    }

    $decoded = json_decode($body ?: '', true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : [],
        ];
    }

    $message = 'kintone API通信に失敗しました。';

    if (is_array($decoded)) {
        if (isset($decoded['message'])) {
            $message = (string)$decoded['message'];
        }

        if (isset($decoded['code'])) {
            $message .= ' [' . (string)$decoded['code'] . ']';
        }

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $details = [];

            foreach ($decoded['errors'] as $field => $error) {
                if (is_array($error)) {
                    $details[] =
                        $field . ': ' .
                        (string)($error['messages'][0] ?? 'エラー');
                }
            }

            if ($details !== []) {
                $message .= ' ' . implode(' / ', $details);
            }
        }
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
    ];
}

function kintone_test(array $settings): array
{
    $domain = trim((string)($settings['kintone_domain'] ?? ''));
    $login = (string)($settings['kintone_login'] ?? '');
    $password = (string)($settings['kintone_password'] ?? '');
    $proxy = trim((string)($settings['proxy_host_port'] ?? ''));

    if ($domain === '' || $login === '' || $password === '') {
        return [
            'success' => false,
            'message' => 'kintoneの接続情報を入力してください。',
        ];
    }

    $url = kintone_build_url(
        $domain,
        '/k/v1/apps.json?limit=1'
    );

    return kintone_api_request(
        'GET',
        $url,
        [
            make_cybozu_auth_header($login, $password),
        ],
        null,
        [
            'proxy_host_port' => $proxy,
        ]
    );
}

function api(): never
{
    $action = (string)($_GET['api'] ?? '');

    try {
        $write_actions = [
            'save_survey',
            'publish_survey',
            'close_survey',
            'delete_survey',
            'save_settings',
            'kintone_test',
        ];

        if (in_array($action, $write_actions, true)) {
            verify_csrf();
        }

        switch ($action) {
            case 'bootstrap':
                json_response([
                    'success' => true,
                    'csrf_token' => csrf_token(),
                    'surveys' => read_json('surveys'),
                ]);

            case 'get_survey':
                $survey_id = trim((string)($_GET['id'] ?? ''));

                if ($survey_id === '') {
                    throw new \InvalidArgumentException(
                        'アンケートIDがありません。'
                    );
                }

                $survey = build_survey($survey_id);

                if ($survey === null) {
                    throw new \RuntimeException(
                        'アンケートが存在しません。'
                    );
                }

                json_response([
                    'success' => true,
                    'survey' => $survey,
                ]);

            case 'save_survey':
                $raw = file_get_contents('php://input');
                $payload = json_decode($raw ?: '{}', true);

                if (!is_array($payload)) {
                    throw new \InvalidArgumentException(
                        '入力データが不正です。'
                    );
                }

                $id = save_survey($payload);

                json_response([
                    'success' => true,
                    'message' => '保存しました。',
                    'id' => $id,
                ]);

            case 'publish_survey':
                publish_survey(
                    trim((string)($_POST['id'] ?? ''))
                );

                json_response([
                    'success' => true,
                    'message' => '公開しました。',
                ]);

            case 'close_survey':
                close_survey(
                    trim((string)($_POST['id'] ?? ''))
                );

                json_response([
                    'success' => true,
                    'message' => '終了しました。',
                ]);

            case 'delete_survey':
                delete_survey(
                    trim((string)($_POST['id'] ?? ''))
                );

                json_response([
                    'success' => true,
                    'message' => '削除しました。',
                ]);

            case 'save_settings':
                $raw = file_get_contents('php://input');
                $settings = json_decode($raw ?: '{}', true);

                if (!is_array($settings)) {
                    throw new \InvalidArgumentException(
                        '設定データが不正です。'
                    );
                }

                $old = read_json('settings');

                $safe = [
                    'kintone_domain' =>
                        trim((string)($settings['kintone_domain'] ?? '')),
                    'kintone_app_id' =>
                        trim((string)($settings['kintone_app_id'] ?? '')),
                    'kintone_login' =>
                        trim((string)($settings['kintone_login'] ?? '')),
                    'kintone_password' =>
                        (string)($settings['kintone_password'] ?? ''),
                    'customer_name_field' =>
                        trim((string)($settings['customer_name_field'] ?? '')),
                    'customer_email_field' =>
                        trim((string)($settings['customer_email_field'] ?? '')),
                    'proxy_host_port' =>
                        trim((string)($settings['proxy_host_port'] ?? '')),
                ];

                if ($safe['kintone_password'] === '') {
                    $safe['kintone_password'] =
                        (string)($old['kintone_password'] ?? '');
                }

                write_json('settings', [$safe]);

                json_response([
                    'success' => true,
                    'message' => '設定を保存しました。',
                ]);

            case 'get_settings':
                $settings = read_json('settings')[0] ?? [];

                if (isset($settings['kintone_password'])) {
                    $settings['kintone_password'] = '';
                }

                json_response([
                    'success' => true,
                    'settings' => $settings,
                ]);

            case 'kintone_test':
                $settings = read_json('settings')[0] ?? [];

                $result = kintone_test($settings);

                json_response($result);

            default:
                json_response([
                    'success' => false,
                    'message' => '不明なAPIです。',
                ]);
        }
    } catch (\Throwable $e) {
        json_response([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

if (isset($_GET['api'])) {
    api();
}

$csrf = csrf_token();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>アンケート業務運営アプリ</title>

<style>
:root {
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --bg:#f8fafc;
    --surface:#fff;
    --border:#e2e8f0;
    --text:#1e293b;
    --muted:#64748b;
    --success:#16a34a;
    --danger:#dc2626;
    --warning:#d97706;
}

* {
    box-sizing:border-box;
}

body {
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

button,
input,
textarea,
select {
    font:inherit;
}

button {
    cursor:pointer;
}

button:disabled {
    opacity:.55;
    cursor:not-allowed;
}

.header {
    background:#fff;
    border-bottom:1px solid var(--border);
    position:sticky;
    top:0;
    z-index:100;
}

.header-inner {
    max-width:1200px;
    margin:auto;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 20px;
    gap:20px;
}

.logo {
    font-weight:700;
    color:var(--primary);
    white-space:nowrap;
}

.nav {
    display:flex;
    gap:4px;
    flex-wrap:wrap;
}

.nav button {
    border:0;
    background:transparent;
    color:var(--muted);
    padding:10px 12px;
    border-bottom:2px solid transparent;
}

.nav button.active {
    color:var(--primary);
    border-bottom-color:var(--primary);
}

.main {
    max-width:1200px;
    margin:24px auto;
    padding:0 20px 80px;
}

.card {
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:10px;
    padding:20px;
    margin-bottom:20px;
}

.card-title {
    font-size:18px;
    font-weight:700;
    margin-bottom:16px;
}

.toolbar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:16px;
    flex-wrap:wrap;
}

.btn {
    border:1px solid var(--border);
    border-radius:7px;
    padding:9px 14px;
    background:#fff;
    color:var(--text);
}

.btn.primary {
    background:var(--primary);
    border-color:var(--primary);
    color:#fff;
}

.btn.primary:hover {
    background:var(--primary-dark);
}

.btn.danger {
    color:#fff;
    background:var(--danger);
    border-color:var(--danger);
}

.btn.small {
    padding:6px 10px;
    font-size:13px;
}

table {
    width:100%;
    border-collapse:collapse;
}

th,
td {
    padding:12px 10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th {
    background:#f8fafc;
    color:var(--muted);
    font-size:13px;
}

.badge {
    display:inline-block;
    border-radius:999px;
    padding:4px 9px;
    font-size:12px;
    font-weight:700;
}

.badge.draft {
    background:#e2e8f0;
    color:#475569;
}

.badge.active {
    background:#dcfce7;
    color:#15803d;
}

.badge.closed {
    background:#fee2e2;
    color:#b91c1c;
}

.form-grid {
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:16px;
}

.form-group {
    margin-bottom:14px;
}

.form-group.full {
    grid-column:1 / -1;
}

.form-group label {
    display:block;
    font-weight:700;
    font-size:13px;
    margin-bottom:6px;
}

.form-control {
    width:100%;
    border:1px solid var(--border);
    border-radius:7px;
    padding:9px 10px;
    background:#fff;
}

textarea.form-control {
    min-height:100px;
    resize:vertical;
}

.group {
    border:1px solid #cbd5e1;
    border-radius:9px;
    padding:16px;
    background:#f8fafc;
    margin-bottom:18px;
}

.group-header {
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:12px;
}

.drag {
    cursor:grab;
    color:var(--muted);
}

.question {
    background:#fff;
    border:1px solid var(--border);
    border-radius:8px;
    padding:14px;
    margin-bottom:12px;
}

.question-header {
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-bottom:12px;
}

.question-number {
    font-weight:700;
    color:var(--primary);
}

.choice {
    display:flex;
    gap:8px;
    margin-bottom:8px;
}

.choice input {
    flex:1;
}

.actions {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.notice {
    padding:12px 14px;
    border-radius:7px;
    background:#eff6ff;
    color:#1e40af;
    margin-bottom:16px;
}

.error {
    padding:12px 14px;
    border-radius:7px;
    background:#fef2f2;
    color:#991b1b;
    margin-bottom:16px;
    white-space:pre-line;
}

.success {
    padding:12px 14px;
    border-radius:7px;
    background:#f0fdf4;
    color:#166534;
    margin-bottom:16px;
}

.tabs {
    display:flex;
    gap:6px;
    border-bottom:1px solid var(--border);
    margin-bottom:20px;
    overflow:auto;
}

.tabs button {
    border:0;
    background:transparent;
    padding:10px 14px;
    color:var(--muted);
    white-space:nowrap;
}

.tabs button.active {
    color:var(--primary);
    border-bottom:2px solid var(--primary);
}

.sticky-save {
    position:fixed;
    bottom:0;
    left:0;
    right:0;
    background:#fff;
    border-top:1px solid var(--border);
    padding:12px 20px;
    z-index:90;
}

.sticky-save-inner {
    max-width:1200px;
    margin:auto;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.empty {
    text-align:center;
    color:var(--muted);
    padding:50px 20px;
}

.modal-backdrop {
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.45);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:500;
    padding:20px;
}

.modal {
    background:#fff;
    border-radius:10px;
    width:min(600px,100%);
    max-height:90vh;
    overflow:auto;
    padding:22px;
}

.modal-actions {
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-top:20px;
}

.spinner {
    width:14px;
    height:14px;
    border:2px solid rgba(255,255,255,.5);
    border-top-color:#fff;
    border-radius:50%;
    display:inline-block;
    animation:spin .7s linear infinite;
}

@keyframes spin {
    to { transform:rotate(360deg); }
}

@media(max-width:760px) {
    .header-inner {
        align-items:flex-start;
        flex-direction:column;
        padding-top:12px;
        padding-bottom:12px;
    }

    .form-grid {
        grid-template-columns:1fr;
    }

    .form-group.full {
        grid-column:auto;
    }

    table {
        font-size:13px;
    }
}
</style>
</head>

<body>

<header class="header">
    <div class="header-inner">
        <div class="logo" id="logo">📋 アンケート業務運営アプリ</div>

        <nav class="nav">
            <button type="button" id="nav-list">アンケート一覧</button>
            <button type="button" id="nav-new">新規アンケート作成</button>
            <button type="button" id="nav-settings">設定</button>
        </nav>
    </div>
</header>

<main class="main" id="app"></main>

<div class="modal-backdrop" id="modal">
    <div class="modal">
        <div id="modal-content"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const APP = {
        csrf: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>,
        survey: null,
        surveys: [],
        dirty: false,
        page: 'list'
    };

    const $ = (selector) => document.querySelector(selector);

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    function setLoading(button, loading) {
        if (!button) return;

        if (loading) {
            button.disabled = true;
            button.dataset.originalText = button.textContent;
            button.innerHTML =
                '<span class="spinner"></span> 処理中...';
        } else {
            button.disabled = false;

            if (button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
            }
        }
    }

    async function api(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                ...(options.headers || {}),
                'X-CSRF-Token': APP.csrf
            }
        });

        const data = await response.json();

        if (!data || data.success !== true) {
            throw new Error(
                data?.message || '通信に失敗しました。'
            );
        }

        return data;
    }

    function toast(message, type = 'success') {
        const old = document.querySelector('.toast-message');

        if (old) old.remove();

        const el = document.createElement('div');
        el.className = 'toast-message';
        el.textContent = message;

        Object.assign(el.style, {
            position:'fixed',
            right:'20px',
            bottom:'80px',
            zIndex:'999',
            padding:'12px 16px',
            borderRadius:'8px',
            color:'#fff',
            background:type === 'error' ? '#dc2626' : '#334155',
            boxShadow:'0 5px 20px rgba(0,0,0,.2)'
        });

        document.body.appendChild(el);

        setTimeout(() => {
            el.remove();
        }, 3000);
    }

    function statusBadge(status) {
        const map = {
            draft: ['下書き', 'draft'],
            active: ['公開中', 'active'],
            closed: ['終了', 'closed']
        };

        const item = map[status] || ['不明', 'draft'];

        return `<span class="badge ${item[1]}">${escapeHtml(item[0])}</span>`;
    }

    async function loadList() {
        APP.page = 'list';
        APP.survey = null;
        APP.dirty = false;

        const data = await api('?api=bootstrap', {
            method:'GET',
            headers:{}
        });

        APP.csrf = data.csrf_token;
        APP.surveys = Array.isArray(data.surveys)
            ? data.surveys
            : [];

        renderList();
    }

    function renderList() {
        const app = $('#app');

        if (!app) return;

        app.textContent = '';

        const card = document.createElement('section');
        card.className = 'card';

        const toolbar = document.createElement('div');
        toolbar.className = 'toolbar';

        const title = document.createElement('div');
        title.className = 'card-title';
        title.textContent = 'アンケート一覧';

        const newButton = document.createElement('button');
        newButton.type = 'button';
        newButton.className = 'btn primary';
        newButton.textContent = '＋ 新規アンケート';

        newButton.addEventListener('click', () => {
            renderEditor(null);
        });

        toolbar.append(title, newButton);
        card.appendChild(toolbar);

        if (APP.surveys.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent =
                'アンケートがありません。「新規アンケート」から作成してください。';
            card.appendChild(empty);
        } else {
            const table = document.createElement('table');

            const thead = document.createElement('thead');
            const tr = document.createElement('tr');

            [
                'アンケート名',
                '状態',
                '公開期間',
                '更新日時',
                '操作'
            ].forEach((text) => {
                const th = document.createElement('th');
                th.textContent = text;
                tr.appendChild(th);
            });

            thead.appendChild(tr);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');

            APP.surveys.forEach((survey) => {
                const row = document.createElement('tr');

                const name = document.createElement('td');
                name.textContent = survey.name || '';

                const status = document.createElement('td');
                status.innerHTML = statusBadge(survey.status);

                const period = document.createElement('td');
                period.textContent =
                    `${survey.start_at || '-'} ～ ${survey.end_at || '-'}`;

                const updated = document.createElement('td');
                updated.textContent = survey.updated_at || '';

                const actions = document.createElement('td');
                const wrap = document.createElement('div');
                wrap.className = 'actions';

                const open = document.createElement('button');
                open.type = 'button';
                open.className = 'btn small';
                open.textContent = '開く';

                open.addEventListener('click', () => {
                    openSurvey(survey.id);
                });

                wrap.appendChild(open);

                if (survey.status === 'draft') {
                    const publish = document.createElement('button');
                    publish.type = 'button';
                    publish.className = 'btn small primary';
                    publish.textContent = '公開';

                    publish.addEventListener('click', () => {
                        changeStatus(
                            survey.id,
                            'publish_survey',
                            publish
                        );
                    });

                    const del = document.createElement('button');
                    del.type = 'button';
                    del.className = 'btn small danger';
                    del.textContent = '削除';

                    del.addEventListener('click', () => {
                        deleteSurvey(survey.id, del);
                    });

                    wrap.append(publish, del);
                }

                if (survey.status === 'active') {
                    const close = document.createElement('button');
                    close.type = 'button';
                    close.className = 'btn small';
                    close.textContent = '終了';

                    close.addEventListener('click', () => {
                        changeStatus(
                            survey.id,
                            'close_survey',
                            close
                        );
                    });

                    wrap.appendChild(close);
                }

                actions.appendChild(wrap);

                row.append(
                    name,
                    status,
                    period,
                    updated,
                    actions
                );

                tbody.appendChild(row);
            });

            table.appendChild(tbody);
            card.appendChild(table);
        }

        app.appendChild(card);
    }

    async function openSurvey(id) {
        try {
            const data = await api(
                '?api=get_survey&id=' +
                encodeURIComponent(id),
                {method:'GET'}
            );

            APP.survey = data.survey;
            APP.page = 'editor';
            APP.dirty = false;

            renderEditor(APP.survey);
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    function newSurveyData() {
        return {
            id:'',
            name:'',
            description:'',
            status:'draft',
            start_at:'',
            end_at:'',
            number_format:'overall',
            groups:[
                {
                    id:'',
                    name:'基本情報',
                    questions:[
                        {
                            id:'',
                            text:'',
                            type:'text',
                            required:true,
                            choices:[]
                        }
                    ]
                }
            ]
        };
    }

    function renderEditor(survey) {
        APP.survey = survey || newSurveyData();
        APP.page = 'editor';

        const app = $('#app');

        if (!app) return;

        app.textContent = '';

        const heading = document.createElement('div');
        heading.className = 'toolbar';

        const title = document.createElement('div');
        title.className = 'card-title';
        title.textContent =
            APP.survey.id
                ? 'アンケート編集'
                : '新規アンケート作成';

        const back = document.createElement('button');
        back.type = 'button';
        back.className = 'btn';
        back.textContent = '一覧へ戻る';

        back.addEventListener('click', async () => {
            if (APP.dirty) {
                const ok = window.confirm(
                    '保存していない変更があります。画面を移動しますか？'
                );

                if (!ok) return;
            }

            await loadList();
        });

        heading.append(title, back);
        app.appendChild(heading);

        const basic = document.createElement('section');
        basic.className = 'card';

        basic.innerHTML = `
            <div class="card-title">基本情報</div>
            <div class="form-grid">
                <div class="form-group full">
                    <label for="survey-name">アンケート名 *</label>
                    <input
                        id="survey-name"
                        class="form-control"
                        maxlength="200"
                        value="${escapeHtml(APP.survey.name || '')}"
                    >
                </div>

                <div class="form-group full">
                    <label for="survey-description">説明文 / 案内文</label>
                    <textarea
                        id="survey-description"
                        class="form-control"
                    >${escapeHtml(APP.survey.description || '')}</textarea>
                </div>

                <div class="form-group">
                    <label for="start-at">公開開始日時</label>
                    <input
                        id="start-at"
                        class="form-control"
                        type="datetime-local"
                        value="${escapeHtml(APP.survey.start_at || '')}"
                    >
                </div>

                <div class="form-group">
                    <label for="end-at">公開終了日時</label>
                    <input
                        id="end-at"
                        class="form-control"
                        type="datetime-local"
                        value="${escapeHtml(APP.survey.end_at || '')}"
                    >
                </div>

                <div class="form-group">
                    <label for="number-format">質問番号</label>
                    <select id="number-format" class="form-control">
                        <option value="overall">全体通番形式</option>
                        <option value="group">グループ別番号形式</option>
                    </select>
                </div>
            </div>
        `;

        app.appendChild(basic);

        const numberFormat = $('#number-format');

        if (numberFormat) {
            numberFormat.value =
                APP.survey.number_format || 'overall';

            numberFormat.addEventListener('change', () => {
                APP.dirty = true;
                renderQuestionsOnly();
            });
        }

        [
            '#survey-name',
            '#survey-description',
            '#start-at',
            '#end-at'
        ].forEach((selector) => {
            const element = $(selector);

            if (element) {
                element.addEventListener('input', () => {
                    APP.dirty = true;
                });
            }
        });

        const editor = document.createElement('section');
        editor.className = 'card';
        editor.id = 'question-editor';

        app.appendChild(editor);

        renderQuestionsOnly();

        const saveBar = document.createElement('div');
        saveBar.className = 'sticky-save';

        const saveInner = document.createElement('div');
        saveInner.className = 'sticky-save-inner';

        const save = document.createElement('button');
        save.type = 'button';
        save.className = 'btn primary';
        save.textContent = '保存';

        save.addEventListener('click', () => {
            saveSurvey(save);
        });

        saveInner.appendChild(save);
        saveBar.appendChild(saveInner);
        app.appendChild(saveBar);
    }

    function renderQuestionsOnly() {
        const editor = $('#question-editor');

        if (!editor) return;

        editor.textContent = '';

        const title = document.createElement('div');
        title.className = 'card-title';
        title.textContent = '質問構成';

        editor.appendChild(title);

        APP.survey.groups.forEach((group, groupIndex) => {
            const groupEl = document.createElement('div');
            groupEl.className = 'group';

            const header = document.createElement('div');
            header.className = 'group-header';

            const drag = document.createElement('span');
            drag.className = 'drag';
            drag.textContent = '☰';

            const input = document.createElement('input');
            input.className = 'form-control';
            input.value = group.name || '';

            input.addEventListener('input', () => {
                group.name = input.value;
                APP.dirty = true;
            });

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn small danger';
            del.textContent = 'グループ削除';

            del.addEventListener('click', () => {
                if (
                    !window.confirm(
                        'このグループと質問を削除しますか？'
                    )
                ) {
                    return;
                }

                APP.survey.groups.splice(groupIndex, 1);

                if (APP.survey.groups.length === 0) {
                    APP.survey.groups.push({
                        id:'',
                        name:'新しいグループ',
                        questions:[]
                    });
                }

                APP.dirty = true;
                renderQuestionsOnly();
            });

            header.append(drag, input, del);
            groupEl.appendChild(header);

            group.questions.forEach((question, questionIndex) => {
                renderQuestion(
                    groupEl,
                    group,
                    question,
                    groupIndex,
                    questionIndex
                );
            });

            const addQuestion = document.createElement('button');
            addQuestion.type = 'button';
            addQuestion.className = 'btn';
            addQuestion.textContent = '＋ 質問を追加';

            addQuestion.addEventListener('click', () => {
                group.questions.push({
                    id:'',
                    text:'',
                    type:'text',
                    required:false,
                    choices:[]
                });

                APP.dirty = true;
                renderQuestionsOnly();
            });

            groupEl.appendChild(addQuestion);
            editor.appendChild(groupEl);
        });

        const addGroup = document.createElement('button');
        addGroup.type = 'button';
        addGroup.className = 'btn primary';
        addGroup.textContent = '＋ グループを追加';

        addGroup.addEventListener('click', () => {
            APP.survey.groups.push({
                id:'',
                name:'新しいグループ',
                questions:[]
            });

            APP.dirty = true;
            renderQuestionsOnly();
        });

        editor.appendChild(addGroup);
    }

    function renderQuestion(
        parent,
        group,
        question,
        groupIndex,
        questionIndex
    ) {
        const el = document.createElement('div');
        el.className = 'question';

        const header = document.createElement('div');
        header.className = 'question-header';

        const number = document.createElement('div');
        number.className = 'question-number';
        number.textContent =
            questionNumber(groupIndex, questionIndex);

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn small danger';
        del.textContent = '質問削除';

        del.addEventListener('click', () => {
            if (!window.confirm('この質問を削除しますか？')) {
                return;
            }

            group.questions.splice(questionIndex, 1);
            APP.dirty = true;
            renderQuestionsOnly();
        });

        header.append(number, del);
        el.appendChild(header);

        const text = document.createElement('textarea');
        text.className = 'form-control';
        text.placeholder = '質問文を入力してください';
        text.value = question.text || '';

        text.addEventListener('input', () => {
            question.text = text.value;
            APP.dirty = true;
        });

        el.appendChild(text);

        const controls = document.createElement('div');
        controls.className = 'form-grid';

        const typeWrap = document.createElement('div');
        typeWrap.className = 'form-group';

        const typeLabel = document.createElement('label');
        typeLabel.textContent = '回答形式';

        const type = document.createElement('select');
        type.className = 'form-control';

        [
            ['text', '自由記述'],
            ['single', '単一選択'],
            ['multiple', '複数選択']
        ].forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            type.appendChild(option);
        });

        type.value = question.type || 'text';

        type.addEventListener('change', () => {
            question.type = type.value;

            if (
                type.value === 'text'
            ) {
                question.choices = [];
            } else if (
                !Array.isArray(question.choices) ||
                question.choices.length === 0
            ) {
                question.choices = [
                    {
                        id:'',
                        text:'',
                        branch_question_id:'',
                        branch_end:false
                    }
                ];
            }

            APP.dirty = true;
            renderQuestionsOnly();
        });

        typeWrap.append(typeLabel, type);

        const requiredWrap = document.createElement('div');
        requiredWrap.className = 'form-group';

        const requiredLabel = document.createElement('label');
        requiredLabel.textContent = '必須';

        const required = document.createElement('select');
        required.className = 'form-control';

        const yes = document.createElement('option');
        yes.value = '1';
        yes.textContent = '必須';

        const no = document.createElement('option');
        no.value = '0';
        no.textContent = '任意';

        required.append(yes, no);
        required.value = question.required ? '1' : '0';

        required.addEventListener('change', () => {
            question.required = required.value === '1';
            APP.dirty = true;
        });

        requiredWrap.append(requiredLabel, required);

        controls.append(typeWrap, requiredWrap);
        el.appendChild(controls);

        if (
            question.type === 'single' ||
            question.type === 'multiple'
        ) {
            const choicesTitle = document.createElement('div');
            choicesTitle.style.fontWeight = '700';
            choicesTitle.style.marginBottom = '8px';
            choicesTitle.textContent = '選択肢';

            el.appendChild(choicesTitle);

            (question.choices || []).forEach(
                (choice, choiceIndex) => {
                    const row = document.createElement('div');
                    row.className = 'choice';

                    const input = document.createElement('input');
                    input.className = 'form-control';
                    input.value = choice.text || '';
                    input.placeholder = '選択肢';

                    input.addEventListener('input', () => {
                        choice.text = input.value;
                        APP.dirty = true;
                    });

                    row.appendChild(input);

                    if (question.type === 'single') {
                        const branch = document.createElement('select');
                        branch.className = 'form-control';

                        const next = document.createElement('option');
                        next.value = '';
                        next.textContent = '次の質問';

                        branch.appendChild(next);

                        allQuestionOptions().forEach((item) => {
                            if (
                                item.id === question.id
                            ) {
                                return;
                            }

                            const option =
                                document.createElement('option');

                            option.value = item.id;
                            option.textContent =
                                item.number + ' ' + item.text;

                            branch.appendChild(option);
                        });

                        const end = document.createElement('option');
                        end.value = '__END__';
                        end.textContent = 'アンケート終了';

                        branch.appendChild(end);

                        branch.value =
                            choice.branch_end
                                ? '__END__'
                                : (choice.branch_question_id || '');

                        branch.addEventListener('change', () => {
                            choice.branch_end =
                                branch.value === '__END__';

                            choice.branch_question_id =
                                branch.value === '__END__'
                                    ? ''
                                    : branch.value;

                            APP.dirty = true;
                        });

                        row.appendChild(branch);
                    }

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'btn small';
                    remove.textContent = '削除';

                    remove.addEventListener('click', () => {
                        question.choices.splice(
                            choiceIndex,
                            1
                        );

                        APP.dirty = true;
                        renderQuestionsOnly();
                    });

                    row.appendChild(remove);
                    el.appendChild(row);
                }
            );

            const addChoice = document.createElement('button');
            addChoice.type = 'button';
            addChoice.className = 'btn small';
            addChoice.textContent = '＋ 選択肢';

            addChoice.addEventListener('click', () => {
                question.choices.push({
                    id:'',
                    text:'',
                    branch_question_id:'',
                    branch_end:false
                });

                APP.dirty = true;
                renderQuestionsOnly();
            });

            el.appendChild(addChoice);
        }

        parent.appendChild(el);
    }

    function questionNumber(groupIndex, questionIndex) {
        const format =
            $('#number-format')?.value ||
            APP.survey.number_format ||
            'overall';

        if (format === 'group') {
            return `Q${groupIndex + 1}-${questionIndex + 1}`;
        }

        let count = 0;

        for (let g = 0; g < APP.survey.groups.length; g++) {
            for (
                let q = 0;
                q < APP.survey.groups[g].questions.length;
                q++
            ) {
                count++;

                if (
                    g === groupIndex &&
                    q === questionIndex
                ) {
                    return `Q${count}`;
                }
            }
        }

        return `Q${count}`;
    }

    function allQuestionOptions() {
        const result = [];

        APP.survey.groups.forEach((group, gi) => {
            group.questions.forEach((question, qi) => {
                result.push({
                    id:question.id,
                    text:question.text || '(未入力)',
                    number:questionNumber(gi, qi)
                });
            });
        });

        return result;
    }

    function collectEditorData() {
        return {
            id:APP.survey.id || '',
            name:$('#survey-name')?.value.trim() || '',
            description:
                $('#survey-description')?.value || '',
            start_at:$('#start-at')?.value || '',
            end_at:$('#end-at')?.value || '',
            number_format:
                $('#number-format')?.value || 'overall',
            groups:APP.survey.groups
        };
    }

    async function saveSurvey(button) {
        if (!button) return;

        setLoading(button, true);

        try {
            const payload = collectEditorData();

            const data = await api('?api=save_survey', {
                method:'POST',
                headers:{
                    'Content-Type':'application/json'
                },
                body:JSON.stringify(payload)
            });

            APP.dirty = false;

            toast(data.message || '保存しました。');
            await openSurvey(data.id);
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setLoading(button, false);
        }
    }

    async function changeStatus(id, action, button) {
        if (!button) return;

        const message =
            action === 'publish_survey'
                ? 'このアンケートを公開しますか？'
                : 'このアンケートを終了しますか？';

        if (!window.confirm(message)) return;

        setLoading(button, true);

        try {
            const body = new URLSearchParams();
            body.set('id', id);

            const data = await api('?api=' + action, {
                method:'POST',
                headers:{
                    'Content-Type':
                        'application/x-www-form-urlencoded'
                },
                body:body.toString()
            });

            toast(data.message || '処理しました。');
            await loadList();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setLoading(button, false);
        }
    }

    async function deleteSurvey(id, button) {
        if (!button) return;

        if (!window.confirm(
            'この下書きアンケートを削除しますか？'
        )) {
            return;
        }

        setLoading(button, true);

        try {
            const body = new URLSearchParams();
            body.set('id', id);

            const data = await api('?api=delete_survey', {
                method:'POST',
                headers:{
                    'Content-Type':
                        'application/x-www-form-urlencoded'
                },
                body:body.toString()
            });

            toast(data.message || '削除しました。');
            await loadList();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            setLoading(button, false);
        }
    }

    async function renderSettings() {
        APP.page = 'settings';

        const app = $('#app');

        if (!app) return;

        app.textContent = '';

        let settings = {};

        try {
            const data = await api('?api=get_settings', {
                method:'GET'
            });

            settings = data.settings || {};
        } catch (error) {
            toast(error.message, 'error');
        }

        const card = document.createElement('section');
        card.className = 'card';

        const title = document.createElement('div');
        title.className = 'card-title';
        title.textContent = 'kintone連携設定';

        card.appendChild(title);

        const form = document.createElement('div');
        form.className = 'form-grid';

        const fields = [
            [
                'kintone_domain',
                'kintoneサブドメイン',
                'text',
                settings.kintone_domain || ''
            ],
            [
                'kintone_app_id',
                '顧客管理アプリID',
                'text',
                settings.kintone_app_id || ''
            ],
            [
                'kintone_login',
                'ログイン名',
                'text',
                settings.kintone_login || ''
            ],
            [
                'kintone_password',
                'パスワード',
                'password',
                ''
            ],
            [
                'customer_name_field',
                '顧客名フィールドコード',
                'text',
                settings.customer_name_field || ''
            ],
            [
                'customer_email_field',
                'メールアドレスフィールドコード',
                'text',
                settings.customer_email_field || ''
            ],
            [
                'proxy_host_port',
                'プロキシ host:port',
                'text',
                settings.proxy_host_port || ''
            ]
        ];

        const controls = {};

        fields.forEach(
            ([key, label, type, value]) => {
                const wrap = document.createElement('div');
                wrap.className = 'form-group';

                const labelEl = document.createElement('label');
                labelEl.textContent = label;

                const input = document.createElement('input');
                input.className = 'form-control';
                input.type = type;
                input.value = value;

                controls[key] = input;

                wrap.append(labelEl, input);
                form.appendChild(wrap);
            }
        );

        card.appendChild(form);

        const notice = document.createElement('div');
        notice.className = 'notice';
        notice.textContent =
            'SSL証明書検証は実装要件に従い無効化されています。' +
            'プロキシを指定した場合はkintone通信に適用されます。';

        card.appendChild(notice);

        const actions = document.createElement('div');
        actions.className = 'actions';

        const save = document.createElement('button');
        save.type = 'button';
        save.className = 'btn primary';
        save.textContent = '設定を保存';

        const test = document.createElement('button');
        test.type = 'button';
        test.className = 'btn';
        test.textContent = 'kintone接続確認';

        save.addEventListener('click', async () => {
            setLoading(save, true);

            try {
                const payload = {};

                Object.keys(controls).forEach((key) => {
                    payload[key] = controls[key].value;
                });

                const data = await api('?api=save_settings', {
                    method:'POST',
                    headers:{
                        'Content-Type':'application/json'
                    },
                    body:JSON.stringify(payload)
                });

                toast(data.message || '保存しました。');
            } catch (error) {
                toast(error.message, 'error');
            } finally {
                setLoading(save, false);
            }
        });

        test.addEventListener('click', async () => {
            setLoading(test, true);

            try {
                const data = await api('?api=kintone_test', {
                    method:'POST'
                });

                toast(
                    data.message ||
                    'kintone接続に成功しました。'
                );
            } catch (error) {
                toast(error.message, 'error');
            } finally {
                setLoading(test, false);
            }
        });

        actions.append(save, test);
        card.appendChild(actions);

        app.appendChild(card);
    }

    const navList = $('#nav-list');
    const navNew = $('#nav-new');
    const navSettings = $('#nav-settings');
    const logo = $('#logo');

    if (navList) {
        navList.addEventListener('click', () => {
            loadList().catch((error) => {
                toast(error.message, 'error');
            });
        });
    }

    if (navNew) {
        navNew.addEventListener('click', () => {
            renderEditor(null);
        });
    }

    if (navSettings) {
        navSettings.addEventListener('click', () => {
            renderSettings().catch((error) => {
                toast(error.message, 'error');
            });
        });
    }

    if (logo) {
        logo.addEventListener('click', () => {
            loadList().catch((error) => {
                toast(error.message, 'error');
            });
        });
    }

    window.addEventListener('beforeunload', (event) => {
        if (!APP.dirty) return;

        event.preventDefault();
        event.returnValue = '';
    });

    loadList().catch((error) => {
        const app = $('#app');

        if (app) {
            app.innerHTML = '';

            const errorEl = document.createElement('div');
            errorEl.className = 'error';
            errorEl.textContent =
                '初期化に失敗しました。\n' +
                error.message;

            app.appendChild(errorEl);
        }
    });
});
</script>

</body>
</html>
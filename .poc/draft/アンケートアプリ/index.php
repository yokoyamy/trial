<?php
declare(strict_types=1);

/*
============================================================
GUARD COMMENT
============================================================

固定ストレージ名称
- survey_storage_directory
- survey_storage_file
- survey_admin_session_v1

PHP定数
- SURVEY_STORAGE_DIRECTORY
- SURVEY_STORAGE_FILE
- SURVEY_ADMIN_SESSION

JSONトップキー
- surveys
- responses
- customers
- settings
- mail_logs

固定ステータス
- draft
- active
- ended

固定DOM ID
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

追加固定DOM ID
- survey_status

画面名称
- キントーン・メール設定

JavaScript
- window.App
- App.actions.changeSurveyStatus
- App.actions.updateBranchVisibility
- App.actions.saveSettings
- App.actions.connectKintone
- App.actions.fetchKintoneFields
- App.actions.addGroup
- App.actions.addQuestion
- App.actions.saveSurvey
- App.initSortable

分岐
- question.branching

禁止旧名称
- kintone連携設定
- ホーム ＞ システム設定

============================================================
*/

const SURVEY_STORAGE_DIRECTORY = 'survey_storage_directory';
const SURVEY_STORAGE_FILE = 'survey_storage_file';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header_remove('X-Powered-By');

function survey_app_storage_dir(): string
{
    return __DIR__ . '/survey_storage';
}

function survey_app_storage_file(): string
{
    return survey_app_storage_dir() . '/survey_data.json';
}

function survey_app_initial_data(): array
{
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [],
        'mail_logs' => [],
    ];
}

function survey_app_load_data(): array
{
    $file = survey_app_storage_file();

    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
    }

    if (!is_file($file)) {
        $data = survey_app_initial_data();
        survey_app_save_data($data);
        return $data;
    }

    $json = @file_get_contents($file);

    if ($json === false || trim($json) === '') {
        $data = survey_app_initial_data();
        survey_app_save_data($data);
        return $data;
    }

    try {
        $data = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        $data = survey_app_initial_data();
        survey_app_save_data($data);
        return $data;
    }

    if (!is_array($data)) {
        $data = survey_app_initial_data();
    }

    foreach (survey_app_initial_data() as $key => $default) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $default;
        }
    }

    if (!is_array($data['surveys'])) {
        $data['surveys'] = [];
    }
    if (!is_array($data['responses'])) {
        $data['responses'] = [];
    }
    if (!is_array($data['customers'])) {
        $data['customers'] = [];
    }
    if (!is_array($data['settings'])) {
        $data['settings'] = [];
    }
    if (!is_array($data['mail_logs'])) {
        $data['mail_logs'] = [];
    }

    return $data;
}

function survey_app_save_data(array $data): bool
{
    $dir = survey_app_storage_dir();

    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return false;
    }

    try {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT |
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        return false;
    }

    $tmp = $dir . '/survey_data.tmp.' . bin2hex(random_bytes(8));

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    $check = @file_get_contents($tmp);

    if ($check === false) {
        @unlink($tmp);
        return false;
    }

    try {
        json_decode($check, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, survey_app_storage_file())) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function survey_app_json(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function survey_app_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function survey_app_require_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals(survey_app_csrf(), $token)) {
        survey_app_json([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。',
        ], 403);
    }
}

function survey_app_id(string $prefix): string
{
    return $prefix . '_' .
        date('YmdHis') . '_' .
        bin2hex(random_bytes(6));
}

function survey_app_clean_settings(array $settings, array $old = []): array
{
    $allowed = [
        'subdomain',
        'login_name',
        'password',
        'app_id',
        'ssl_verify',
        'proxy',
        'field_company',
        'field_name',
        'field_email',
        'field_department',
        'field_phone',
        'field_address',
        'smtp_server',
        'smtp_port',
        'smtp_encryption',
        'smtp_auth',
        'smtp_username',
        'smtp_password',
        'smtp_from_email',
        'smtp_from_name',
        'smtp_timeout',
    ];

    $result = [];

    foreach ($allowed as $key) {
        if (
            in_array($key, ['password', 'smtp_password'], true) &&
            !array_key_exists($key, $settings)
        ) {
            if (isset($old[$key])) {
                $result[$key] = $old[$key];
            }
            continue;
        }

        $value = $settings[$key] ?? '';

        if (in_array($key, ['password', 'smtp_password'], true)) {
            if ($value === '' && isset($old[$key])) {
                $result[$key] = $old[$key];
            } else {
                $result[$key] = (string)$value;
            }
        } elseif ($key === 'ssl_verify') {
            $result[$key] = !empty($value);
        } elseif (in_array($key, ['app_id', 'smtp_port', 'smtp_timeout'], true)) {
            $result[$key] = max(0, (int)$value);
        } else {
            $result[$key] = is_scalar($value)
                ? (string)$value
                : '';
        }
    }

    return $result;
}

function survey_app_public_settings(array $settings): array
{
    $result = $settings;

    unset(
        $result['password'],
        $result['smtp_password']
    );

    return $result;
}

function survey_app_normalize_survey(array $survey): array
{
    $status = (string)($survey['status'] ?? 'draft');

    if (!in_array($status, ['draft', 'active', 'ended'], true)) {
        throw new InvalidArgumentException(
            'status は draft / active / ended のいずれかです。'
        );
    }

    $survey['id'] = (string)(
        $survey['id'] ?? survey_app_id('survey')
    );

    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['start_at'] = (string)($survey['start_at'] ?? '');
    $survey['end_at'] = (string)($survey['end_at'] ?? '');
    $survey['status'] = $status;

    $survey['created_at'] = (string)(
        $survey['created_at'] ?? date('c')
    );

    $survey['updated_at'] = date('c');

    $survey['numbering_mode'] = in_array(
        $survey['numbering_mode'] ?? 'global',
        ['global', 'group'],
        true
    )
        ? $survey['numbering_mode']
        : 'global';

    $survey['general_response_enabled'] =
        !empty($survey['general_response_enabled']);

    $survey['deleted'] = !empty($survey['deleted']);

    $survey['public_token'] = (string)(
        $survey['public_token'] ??
        bin2hex(random_bytes(24))
    );

    if (
        !isset($survey['groups']) ||
        !is_array($survey['groups'])
    ) {
        $survey['groups'] = [];
    }

    foreach ($survey['groups'] as &$group) {
        $group['id'] = (string)(
            $group['id'] ?? survey_app_id('group')
        );

        $group['name'] = (string)(
            $group['name'] ?? 'ブロック'
        );

        if (
            !isset($group['questions']) ||
            !is_array($group['questions'])
        ) {
            $group['questions'] = [];
        }

        foreach ($group['questions'] as &$question) {
            $question['id'] = (string)(
                $question['id'] ?? survey_app_id('question')
            );

            $question['text'] = (string)(
                $question['text'] ?? ''
            );

            $question['type'] = in_array(
                $question['type'] ?? 'text',
                ['single', 'multiple', 'text'],
                true
            )
                ? $question['type']
                : 'text';

            $question['required'] =
                !empty($question['required']);

            $question['other_enabled'] =
                !empty($question['other_enabled']);

            if (
                !isset($question['options']) ||
                !is_array($question['options'])
            ) {
                $question['options'] = [];
            }

            foreach ($question['options'] as &$option) {
                if (is_string($option)) {
                    $option = [
                        'id' => survey_app_id('option'),
                        'label' => $option,
                    ];
                }

                if (!is_array($option)) {
                    $option = [
                        'id' => survey_app_id('option'),
                        'label' => '',
                    ];
                }

                $option['id'] = (string)(
                    $option['id'] ?? survey_app_id('option')
                );

                $option['label'] = (string)(
                    $option['label'] ?? ''
                );
            }

            unset($option);

            $question['branching'] =
                isset($question['branching']) &&
                is_array($question['branching'])
                    ? $question['branching']
                    : [];

            if ($question['type'] !== 'single') {
                $question['branching'] = [];
            }
        }

        unset($question);
    }

    unset($group);

    return $survey;
}

function survey_app_renumber(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if ($survey['numbering_mode'] === 'group') {
                $question['number'] =
                    'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $questionNo++;
            $global++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);
}

function survey_app_find_survey(
    array &$data,
    string $id
): ?array {
    foreach ($data['surveys'] as $index => $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return [
                'index' => $index,
                'survey' => $survey,
            ];
        }
    }

    return null;
}

function survey_app_find_question(
    array $survey,
    string $questionId
): ?array {
    foreach ($survey['groups'] as $groupIndex => $group) {
        foreach ($group['questions'] as $questionIndex => $question) {
            if ((string)$question['id'] === $questionId) {
                return [
                    'group_index' => $groupIndex,
                    'question_index' => $questionIndex,
                    'question' => $question,
                ];
            }
        }
    }

    return null;
}

function survey_app_all_question_ids(array $survey): array
{
    $ids = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $ids[] = (string)$question['id'];
        }
    }

    return $ids;
}

function survey_app_cleanup_branching(array &$survey): void
{
    $valid = array_flip(
        survey_app_all_question_ids($survey)
    );

    foreach ($survey['groups'] as &$group) {
        foreach ($group['questions'] as &$question) {
            if ($question['type'] !== 'single') {
                $question['branching'] = [];
                continue;
            }

            $clean = [];

            foreach ($question['options'] as $option) {
                $optionId = (string)$option['id'];
                $target = $question['branching'][$optionId] ?? null;

                if (
                    $target !== null &&
                    !isset($valid[(string)$target])
                ) {
                    $target = null;
                }

                $clean[$optionId] = $target !== null
                    ? (string)$target
                    : null;
            }

            $question['branching'] = $clean;
        }
    }

    unset($group, $question);
}

function survey_app_http_request(
    string $url,
    string $method,
    array $headers,
    ?string $body,
    int $timeout,
    bool $sslVerify = true,
    string $proxy = ''
): array {
    $headerText = implode("\r\n", $headers);

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => $headerText,
            'content' => $body ?? '',
            'timeout' => max(1, $timeout),
            'ignore_errors' => true,
        ],
    ];

    if ($proxy !== '') {
        $options['http']['proxy'] = $proxy;
        $options['http']['request_fulluri'] = true;
    }

    $options['ssl'] = [
        'verify_peer' => $sslVerify,
        'verify_peer_name' => $sslVerify,
        'allow_self_signed' => !$sslVerify,
    ];

    $context = stream_context_create($options);

    $response = @file_get_contents($url, false, $context);

    $headersOut = function_exists(
        'http_get_last_response_headers'
    )
        ? (http_get_last_response_headers() ?: [])
        : ($http_response_header ?? []);

    $status = 0;

    foreach ($headersOut as $line) {
        if (preg_match(
            '/^HTTP\/\S+\s+(\d+)/',
            $line,
            $m
        )) {
            $status = (int)$m[1];
        }
    }

    return [
        'ok' => $response !== false &&
            $status >= 200 &&
            $status < 300,
        'body' => $response === false ? '' : $response,
        'headers' => $headersOut,
        'status' => $status,
    ];
}

function survey_app_kintone_base(array $settings): string
{
    $subdomain = trim((string)(
        $settings['subdomain'] ?? ''
    ));

    $subdomain = preg_replace(
        '/[^a-zA-Z0-9_-]/',
        '',
        $subdomain
    );

    return 'https://' . $subdomain . '.cybozu.com';
}

function survey_app_kintone_headers(array $settings): array
{
    $login = (string)(
        $settings['login_name'] ?? ''
    );

    $password = (string)(
        $settings['password'] ?? ''
    );

    return [
        'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password),
        'Content-Type: application/json',
        'Accept: application/json',
    ];
}

function survey_app_kintone_call(
    array $settings,
    string $path,
    string $method = 'GET',
    ?array $payload = null
): array {
    $base = survey_app_kintone_base($settings);

    if (
        ($settings['subdomain'] ?? '') === '' ||
        ($settings['login_name'] ?? '') === '' ||
        ($settings['password'] ?? '') === ''
    ) {
        return [
            'ok' => false,
            'message' => 'キントーン接続設定が不足しています。',
            'http_status' => 0,
        ];
    }

    $url = $base . $path;

    $body = $payload === null
        ? null
        : json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

    $result = survey_app_http_request(
        $url,
        $method,
        survey_app_kintone_headers($settings),
        $body,
        (int)($settings['smtp_timeout'] ?? 15),
        !empty($settings['ssl_verify']),
        (string)($settings['proxy'] ?? '')
    );

    $decoded = null;

    if ($result['body'] !== '') {
        try {
            $decoded = json_decode(
                $result['body'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            $decoded = null;
        }
    }

    if (!$result['ok']) {
        $message = 'キントーンAPI通信に失敗しました。';

        if (is_array($decoded) && !empty($decoded['message'])) {
            $message = (string)$decoded['message'];
        }

        return [
            'ok' => false,
            'message' => $message,
            'http_status' => $result['status'],
        ];
    }

    return [
        'ok' => true,
        'http_status' => $result['status'],
        'data' => is_array($decoded) ? $decoded : [],
    ];
}

function survey_app_smtp_read(
    $socket,
    int $timeout
): array {
    stream_set_timeout($socket, $timeout);

    $lines = [];

    while (!feof($socket)) {
        $line = fgets($socket, 4096);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    $last = end($lines) ?: '';

    return [
        'code' => (int)substr($last, 0, 3),
        'lines' => $lines,
    ];
}

function survey_app_smtp_command(
    $socket,
    string $command,
    array $expected,
    int $timeout
): array {
    fwrite($socket, $command . "\r\n");

    $reply = survey_app_smtp_read(
        $socket,
        $timeout
    );

    if (!in_array($reply['code'], $expected, true)) {
        throw new RuntimeException(
            'SMTP応答エラー: ' . $reply['code']
        );
    }

    return $reply;
}

function survey_app_smtp_connect(
    array $settings
) {
    $server = trim((string)(
        $settings['smtp_server'] ?? ''
    ));

    $port = (int)(
        $settings['smtp_port'] ?? 587
    );

    $encryption = (string)(
        $settings['smtp_encryption'] ?? 'starttls'
    );

    $timeout = max(
        1,
        (int)($settings['smtp_timeout'] ?? 15)
    );

    if ($server === '') {
        throw new RuntimeException(
            'SMTPサーバを設定してください。'
        );
    }

    $host = $server;

    if ($encryption === 'ssl') {
        $host = 'tls://' . $server;
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTP接続に失敗しました。'
        );
    }

    stream_set_timeout($socket, $timeout);

    $reply = survey_app_smtp_read(
        $socket,
        $timeout
    );

    if ($reply['code'] !== 220) {
        fclose($socket);

        throw new RuntimeException(
            'SMTPサーバの応答が不正です。'
        );
    }

    survey_app_smtp_command(
        $socket,
        'EHLO localhost',
        [250],
        $timeout
    );

    if ($encryption === 'starttls') {
        survey_app_smtp_command(
            $socket,
            'STARTTLS',
            [220],
            $timeout
        );

        if (!stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        )) {
            fclose($socket);

            throw new RuntimeException(
                'STARTTLSの開始に失敗しました。'
            );
        }

        survey_app_smtp_command(
            $socket,
            'EHLO localhost',
            [250],
            $timeout
        );
    }

    $auth = !empty($settings['smtp_auth']);

    if ($auth) {
        $username = (string)(
            $settings['smtp_username'] ?? ''
        );

        $password = (string)(
            $settings['smtp_password'] ?? ''
        );

        survey_app_smtp_command(
            $socket,
            'AUTH LOGIN',
            [334],
            $timeout
        );

        survey_app_smtp_command(
            $socket,
            base64_encode($username),
            [334],
            $timeout
        );

        survey_app_smtp_command(
            $socket,
            base64_encode($password),
            [235],
            $timeout
        );
    }

    return $socket;
}

function survey_app_smtp_send(
    array $settings,
    string $recipient,
    string $subject,
    string $body
): void {
    $socket = survey_app_smtp_connect($settings);

    $timeout = max(
        1,
        (int)($settings['smtp_timeout'] ?? 15)
    );

    $from = (string)(
        $settings['smtp_from_email'] ?? ''
    );

    $fromName = (string)(
        $settings['smtp_from_name'] ?? ''
    );

    if ($from === '') {
        throw new RuntimeException(
            '送信元メールアドレスを設定してください。'
        );
    }

    survey_app_smtp_command(
        $socket,
        'MAIL FROM:<' . $from . '>',
        [250],
        $timeout
    );

    survey_app_smtp_command(
        $socket,
        'RCPT TO:<' . $recipient . '>',
        [250, 251],
        $timeout
    );

    survey_app_smtp_command(
        $socket,
        'DATA',
        [354],
        $timeout
    );

    $encodedSubject =
        '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $encodedName =
        $fromName !== ''
            ? '=?UTF-8?B?' .
                base64_encode($fromName) .
                '?='
            : '';

    $fromHeader = $encodedName !== ''
        ? $encodedName . ' <' . $from . '>'
        : $from;

    $message =
        'From: ' . $fromHeader . "\r\n" .
        'To: <' . $recipient . ">\r\n" .
        'Subject: ' . $encodedSubject . "\r\n" .
        'MIME-Version: 1.0' . "\r\n" .
        'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
        'Content-Transfer-Encoding: 8bit' . "\r\n" .
        "\r\n" .
        str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

    $message = str_replace(
        "\n",
        "\r\n",
        $message
    );

    fwrite(
        $socket,
        $message . "\r\n.\r\n"
    );

    $reply = survey_app_smtp_read(
        $socket,
        $timeout
    );

    if (!in_array(
        $reply['code'],
        [250],
        true
    )) {
        fclose($socket);

        throw new RuntimeException(
            'メール送信に失敗しました。'
        );
    }

    survey_app_smtp_command(
        $socket,
        'QUIT',
        [221],
        $timeout
    );

    fclose($socket);
}

function survey_app_handle_api(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === '') {
        return;
    }

    survey_app_require_csrf();

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();

        survey_app_json([
            'ok' => true,
        ]);
    }

    $data = survey_app_load_data();

    switch ($action) {
        case 'get_data':
            survey_app_json([
                'ok' => true,
                'surveys' => $data['surveys'],
                'responses' => $data['responses'],
                'customers' => $data['customers'],
                'settings' =>
                    survey_app_public_settings(
                        $data['settings']
                    ),
                'mail_logs' => $data['mail_logs'],
            ]);
            break;

        case 'list_surveys':
            $surveys = [];

            foreach ($data['surveys'] as $survey) {
                if (!empty($survey['deleted'])) {
                    continue;
                }

                $survey['response_count'] = 0;

                foreach ($data['responses'] as $response) {
                    if (
                        ($response['survey_id'] ?? '') ===
                        ($survey['id'] ?? '') &&
                        empty($response['deleted'])
                    ) {
                        $survey['response_count']++;
                    }
                }

                $surveys[] = $survey;
            }

            survey_app_json([
                'ok' => true,
                'surveys' => $surveys,
            ]);
            break;

        case 'save_survey':
            $raw = (string)(
                $_POST['survey_json'] ?? ''
            );

            try {
                $survey = json_decode(
                    $raw,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (!is_array($survey)) {
                    throw new RuntimeException();
                }

                $survey = survey_app_normalize_survey(
                    $survey
                );

                survey_app_cleanup_branching(
                    $survey
                );

                survey_app_renumber($survey);
            } catch (Throwable $e) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        $e->getMessage() !== ''
                            ? $e->getMessage()
                            : 'アンケートJSONが不正です。',
                ], 400);
            }

            $found = survey_app_find_survey(
                $data,
                (string)$survey['id']
            );

            if ($found === null) {
                $data['surveys'][] = $survey;
            } else {
                $data['surveys'][$found['index']] =
                    $survey;
            }

            if (!survey_app_save_data($data)) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        'アンケートを保存できません。',
                ], 500);
            }

            survey_app_json([
                'ok' => true,
                'survey' => $survey,
            ]);
            break;

        case 'duplicate_survey':
            $id = (string)(
                $_POST['survey_id'] ?? ''
            );

            $found = survey_app_find_survey(
                $data,
                $id
            );

            if ($found === null) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        'アンケートが見つかりません。',
                ], 404);
            }

            $copy = $found['survey'];

            $oldIds = [];
            $newIds = [];

            foreach ($copy['groups'] as &$group) {
                $group['id'] = survey_app_id('group');

                foreach ($group['questions'] as &$question) {
                    $oldIds[] = (string)$question['id'];

                    $newId = survey_app_id('question');
                    $newIds[] = $newId;
                    $question['id'] = $newId;
                }

                unset($question);
            }

            unset($group);

            foreach ($copy['groups'] as &$group) {
                foreach ($group['questions'] as &$question) {
                    $newBranch = [];

                    foreach ($question['branching'] as $optionId => $target) {
                        $index = array_search(
                            (string)$target,
                            $oldIds,
                            true
                        );

                        $newBranch[$optionId] =
                            $index === false
                                ? null
                                : $newIds[$index];
                    }

                    $question['branching'] = $newBranch;
                }
            }

            unset($group, $question);

            $copy['id'] = survey_app_id('survey');
            $copy['title'] .= '（複製）';
            $copy['status'] = 'draft';
            $copy['created_at'] = date('c');
            $copy['updated_at'] = date('c');
            $copy['deleted'] = false;
            $copy['public_token'] =
                bin2hex(random_bytes(24));

            survey_app_renumber($copy);

            $data['surveys'][] = $copy;

            if (!survey_app_save_data($data)) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        '複製データを保存できません。',
                ], 500);
            }

            survey_app_json([
                'ok' => true,
                'survey' => $copy,
            ]);
            break;

        case 'delete_survey':
            $id = (string)(
                $_POST['survey_id'] ?? ''
            );

            $found = survey_app_find_survey(
                $data,
                $id
            );

            if ($found === null) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        'アンケートが見つかりません。',
                ], 404);
            }

            $data['surveys'][$found['index']]['deleted'] =
                true;

            $data['surveys'][$found['index']]['updated_at'] =
                date('c');

            if (!survey_app_save_data($data)) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        '削除状態を保存できません。',
                ], 500);
            }

            survey_app_json([
                'ok' => true,
            ]);
            break;

        case 'save_settings':
            $raw = (string)(
                $_POST['settings_json'] ?? ''
            );

            try {
                $settings = json_decode(
                    $raw,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (!is_array($settings)) {
                    throw new RuntimeException();
                }
            } catch (Throwable) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        '設定JSONが不正です。',
                ], 400);
            }

            $settings = survey_app_clean_settings(
                $settings,
                $data['settings']
            );

            $data['settings'] = $settings;

            if (!survey_app_save_data($data)) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        '設定を保存できません。',
                ], 500);
            }

            survey_app_json([
                'ok' => true,
                'settings' =>
                    survey_app_public_settings(
                        $settings
                    ),
            ]);
            break;

        case 'connect_kintone':
            $result = survey_app_kintone_call(
                $data['settings'],
                '/k/v1/app.json?app=' .
                    rawurlencode(
                        (string)(
                            $data['settings']['app_id'] ?? ''
                        )
                    )
            );

            if (!$result['ok']) {
                survey_app_json([
                    'ok' => false,
                    'message' => $result['message'],
                    'http_status' =>
                        $result['http_status'],
                ], 400);
            }

            survey_app_json([
                'ok' => true,
            ]);
            break;

        case 'fetch_kintone_fields':
            $appId = (int)(
                $data['settings']['app_id'] ?? 0
            );

            if ($appId <= 0) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        '顧客管理アプリIDを設定してください。',
                ], 400);
            }

            $result = survey_app_kintone_call(
                $data['settings'],
                '/k/v1/app/form/fields.json?app=' .
                    $appId
            );

            if (!$result['ok']) {
                survey_app_json([
                    'ok' => false,
                    'message' => $result['message'],
                    'http_status' =>
                        $result['http_status'],
                ], 400);
            }

            $fields = [];

            foreach (
                ($result['data']['properties'] ?? [])
                as $code => $property
            ) {
                $fields[] = [
                    'code' => (string)$code,
                    'label' => (string)(
                        $property['label'] ?? ''
                    ),
                    'type' => (string)(
                        $property['type'] ?? ''
                    ),
                ];
            }

            survey_app_json([
                'ok' => true,
                'fields' => $fields,
            ]);
            break;

        case 'sync_customers':
            $appId = (int)(
                $data['settings']['app_id'] ?? 0
            );

            if ($appId <= 0) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        '顧客管理アプリIDを設定してください。',
                ], 400);
            }

            $result = survey_app_kintone_call(
                $data['settings'],
                '/k/v1/records.json?app=' .
                    $appId
            );

            if (!$result['ok']) {
                survey_app_json([
                    'ok' => false,
                    'message' => $result['message'],
                    'http_status' =>
                        $result['http_status'],
                ], 400);
            }

            $records = $result['data']['records'] ?? [];

            if (!is_array($records)) {
                $records = [];
            }

            $customers = [];

            $companyCode = (string)(
                $data['settings']['field_company'] ?? ''
            );
            $nameCode = (string)(
                $data['settings']['field_name'] ?? ''
            );
            $emailCode = (string)(
                $data['settings']['field_email'] ?? ''
            );
            $departmentCode = (string)(
                $data['settings']['field_department'] ?? ''
            );
            $phoneCode = (string)(
                $data['settings']['field_phone'] ?? ''
            );
            $addressCode = (string)(
                $data['settings']['field_address'] ?? ''
            );

            foreach ($records as $record) {
                $customers[] = [
                    'id' => survey_app_id('customer'),
                    'kintone_id' =>
                        (string)($record['$id']['value'] ?? ''),
                    'company' =>
                        (string)($record[$companyCode]['value'] ?? ''),
                    'name' =>
                        (string)($record[$nameCode]['value'] ?? ''),
                    'email' =>
                        (string)($record[$emailCode]['value'] ?? ''),
                    'department' =>
                        (string)($record[$departmentCode]['value'] ?? ''),
                    'phone' =>
                        (string)($record[$phoneCode]['value'] ?? ''),
                    'address' =>
                        (string)($record[$addressCode]['value'] ?? ''),
                    'updated_at' => date('c'),
                ];
            }

            $data['customers'] = $customers;

            if (!survey_app_save_data($data)) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        '顧客データを保存できません。',
                ], 500);
            }

            survey_app_json([
                'ok' => true,
                'count' => count($customers),
                'customers' => $customers,
            ]);
            break;

        case 'test_smtp_connection':
            try {
                $socket = survey_app_smtp_connect(
                    $data['settings']
                );

                $timeout = max(
                    1,
                    (int)($data['settings']['smtp_timeout'] ?? 15)
                );

                survey_app_smtp_command(
                    $socket,
                    'QUIT',
                    [221],
                    $timeout
                );

                fclose($socket);

                survey_app_json([
                    'ok' => true,
                ]);
            } catch (Throwable $e) {
                survey_app_json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ], 400);
            }
            break;

        case 'test_smtp_mail':
            $recipient = trim((string)(
                $_POST['recipient'] ?? ''
            ));

            if (
                $recipient === '' ||
                !filter_var(
                    $recipient,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        'テスト送信先メールアドレスが不正です。',
                ], 400);
            }

            try {
                survey_app_smtp_send(
                    $data['settings'],
                    $recipient,
                    'アンケート管理システム SMTP送信テスト',
                    "アンケート管理システムのSMTP接続テストです。\r\n\r\n" .
                    date('Y-m-d H:i:s')
                );

                $data['mail_logs'][] = [
                    'id' => survey_app_id('mail'),
                    'type' => 'initial',
                    'recipient' => $recipient,
                    'subject' =>
                        'アンケート管理システム SMTP送信テスト',
                    'status' => 'sent',
                    'created_at' => date('c'),
                ];

                survey_app_save_data($data);

                survey_app_json([
                    'ok' => true,
                ]);
            } catch (Throwable $e) {
                survey_app_json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ], 400);
            }
            break;

        case 'send_mail':
            $idsRaw = $_POST['recipient_ids'] ?? '[]';

            try {
                $recipientIds = is_string($idsRaw)
                    ? json_decode(
                        $idsRaw,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    )
                    : $idsRaw;
            } catch (Throwable) {
                $recipientIds = [];
            }

            if (!is_array($recipientIds)) {
                $recipientIds = [];
            }

            $subject = trim((string)(
                $_POST['mail_subject'] ?? ''
            ));

            $body = (string)(
                $_POST['mail_body'] ?? ''
            );

            if ($subject === '') {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        '件名を入力してください。',
                ], 400);
            }

            $sent = 0;
            $failed = 0;

            foreach ($data['customers'] as $customer) {
                if (
                    !in_array(
                        (string)($customer['id'] ?? ''),
                        array_map('strval', $recipientIds),
                        true
                    )
                ) {
                    continue;
                }

                $email = (string)(
                    $customer['email'] ?? ''
                );

                if (
                    !filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    $failed++;
                    continue;
                }

                try {
                    survey_app_smtp_send(
                        $data['settings'],
                        $email,
                        $subject,
                        $body
                    );

                    $sent++;

                    $data['mail_logs'][] = [
                        'id' => survey_app_id('mail'),
                        'type' =>
                            (string)(
                                $_POST['template_type'] ??
                                'initial'
                            ),
                        'customer_id' =>
                            (string)$customer['id'],
                        'recipient' => $email,
                        'subject' => $subject,
                        'status' => 'sent',
                        'created_at' => date('c'),
                    ];
                } catch (Throwable) {
                    $failed++;

                    $data['mail_logs'][] = [
                        'id' => survey_app_id('mail'),
                        'type' =>
                            (string)(
                                $_POST['template_type'] ??
                                'initial'
                            ),
                        'customer_id' =>
                            (string)$customer['id'],
                        'recipient' => $email,
                        'subject' => $subject,
                        'status' => 'failed',
                        'created_at' => date('c'),
                    ];
                }
            }

            survey_app_save_data($data);

            survey_app_json([
                'ok' => true,
                'sent' => $sent,
                'failed' => $failed,
            ]);
            break;

        case 'save_response':
            $raw = (string)(
                $_POST['response_json'] ?? ''
            );

            try {
                $response = json_decode(
                    $raw,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (!is_array($response)) {
                    throw new RuntimeException();
                }
            } catch (Throwable) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        '回答データが不正です。',
                ], 400);
            }

            $response['id'] = (string)(
                $response['id'] ??
                survey_app_id('response')
            );

            $response['created_at'] =
                (string)(
                    $response['created_at'] ??
                    date('c')
                );

            $response['updated_at'] = date('c');
            $response['deleted'] = false;

            $data['responses'][] = $response;

            if (!survey_app_save_data($data)) {
                survey_app_json([
                    'ok' => false,
                    'message' =>
                        '回答を保存できません。',
                ], 500);
            }

            survey_app_json([
                'ok' => true,
                'response_id' => $response['id'],
            ]);
            break;

        default:
            survey_app_json([
                'ok' => false,
                'message' =>
                    'Unknown API action: ' . $action,
            ], 400);
    }
}

survey_app_handle_api();

$csrf = survey_app_csrf();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-100 text-slate-900">

<input
    type="hidden"
    id="csrf_token"
    value="<?= htmlspecialchars(
        $csrf,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    ) ?>"
>

<div id="app"></div>

<script>
'use strict';

window.App = {
    state: {
        initialized: false,
        screen: 'list',
        surveys: [],
        responses: [],
        customers: [],
        settings: {},
        mail_logs: [],
        currentSurvey: null,
        keyword: '',
        statusFilter: '',
        sort: 'updated_desc',
        answers: {},
        visibleQuestionIds: [],
        responseSurvey: null,
        editingGroupId: null,
        previewSurvey: null,
        fields: []
    },

    render: {},

    actions: {},

    api: {},

    utils: {},

    initSortable: function () {
        const editor =
            document.getElementById('question_editor');

        if (!editor ||
            typeof Sortable === 'undefined') {
            return;
        }

        editor
            .querySelectorAll('[data-sortable-groups]')
            .forEach((container) => {
                if (
                    container.dataset.sortableInitialized === '1'
                ) {
                    return;
                }

                Sortable.create(container, {
                    animation: 150,
                    handle: '[data-group-handle]',
                    onEnd: function () {
                        App.actions.syncGroupStructure();
                        App.actions.renumberQuestions();
                        App.render.editor();
                    }
                });

                container.dataset.sortableInitialized = '1';
            });

        editor
            .querySelectorAll('[data-sortable-questions]')
            .forEach((container) => {
                if (
                    container.dataset.sortableInitialized === '1'
                ) {
                    return;
                }

                Sortable.create(container, {
                    group: 'survey-questions',
                    animation: 150,
                    handle: '[data-question-handle]',

                    onEnd: function () {
                        App.actions.syncQuestionStructure();
                        App.actions.renumberQuestions();
                        App.actions.cleanupBranching();
                        App.render.editor();
                    }
                });

                container.dataset.sortableInitialized = '1';
            });
    },

    init: async function () {
        if (this.state.initialized) {
            return;
        }

        this.state.initialized = true;

        this.render.shell();

        try {
            await this.api.load();
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }

        this.render.current();
    }
};

App.utils = {

    escapeHTML: function (value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    },

    escapeAttr: function (value) {
        return App.utils.escapeHTML(value);
    },

    uid: function (prefix) {
        return prefix + '_' +
            Date.now() + '_' +
            Math.random()
                .toString(36)
                .slice(2, 10);
    },

    notice: function (message, type = 'info') {
        const element =
            document.createElement('div');

        element.className =
            'fixed right-4 top-4 z-[9999] ' +
            'max-w-md rounded-xl px-5 py-4 shadow-xl text-white ' +
            (
                type === 'error'
                    ? 'bg-red-600'
                    : 'bg-blue-600'
            );

        element.textContent = message;

        document.body.appendChild(element);

        setTimeout(function () {
            element.remove();
        }, 4000);
    },

    confirm: function (message) {
        return window.confirm(message);
    },

    newQuestion: function () {
        return {
            id: App.utils.uid('question'),
            number: '',
            text: '',
            type: 'text',
            required: false,
            options: [],
            other_enabled: false,
            branching: {}
        };
    },

    newGroup: function () {
        return {
            id: App.utils.uid('group'),
            name: 'ブロック',
            questions: []
        };
    },

    newSurvey: function () {
        const survey = {
            id: App.utils.uid('survey'),
            title: '新しいアンケート',
            start_at: '',
            end_at: '',
            status: 'draft',
            numbering_mode: 'global',
            general_response_enabled: false,
            groups: [
                App.utils.newGroup()
            ],
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
            deleted: false,
            public_token: App.utils.uid('public')
        };

        return survey;
    },

    clone: function (object) {
        return JSON.parse(
            JSON.stringify(object)
        );
    },

    flattenQuestions: function (survey) {
        const result = [];

        (survey.groups || []).forEach(
            function (group) {
                (group.questions || []).forEach(
                    function (question) {
                        result.push({
                            groupId: group.id,
                            question: question
                        });
                    }
                );
            }
        );

        return result;
    },

    questionLabel: function (question) {
        return (
            question.number +
            '：' +
            (question.text || '（質問文未入力）')
        );
    }
};

App.api = {

    request: async function (
        action,
        payload = {}
    ) {
        const body = new URLSearchParams();

        body.set('action', action);

        body.set(
            'csrf_token',
            document.getElementById(
                'csrf_token'
            ).value
        );

        Object.entries(payload).forEach(
            function ([key, value]) {
                if (
                    value !== null &&
                    typeof value === 'object'
                ) {
                    body.set(
                        key,
                        JSON.stringify(value)
                    );
                } else {
                    body.set(
                        key,
                        String(value ?? '')
                    );
                }
            }
        );

        const response = await fetch(
            window.location.href,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept':
                        'application/json'
                },
                body: body.toString()
            }
        );

        const text = await response.text();

        let json;

        try {
            json = JSON.parse(text);
        } catch (error) {
            console.error(text);

            throw new Error(
                'サーバーがJSONではない応答を返しました。'
            );
        }

        if (!response.ok || json.ok === false) {
            throw new Error(
                json.message ||
                'API処理に失敗しました。'
            );
        }

        return json;
    },

    load: async function () {
        const result =
            await this.request('get_data');

        App.state.surveys =
            Array.isArray(result.surveys)
                ? result.surveys
                : [];

        App.state.responses =
            Array.isArray(result.responses)
                ? result.responses
                : [];

        App.state.customers =
            Array.isArray(result.customers)
                ? result.customers
                : [];

        App.state.settings =
            result.settings &&
            typeof result.settings === 'object'
                ? result.settings
                : {};

        App.state.mail_logs =
            Array.isArray(result.mail_logs)
                ? result.mail_logs
                : [];
    }
};

App.render = {

    shell: function () {
        document.getElementById(
            'app'
        ).innerHTML = `
            <div class="min-h-screen">
                <header
                    class="sticky top-0 z-40 border-b
                           bg-white shadow-sm"
                >
                    <div
                        class="mx-auto flex max-w-7xl
                               items-center justify-between
                               px-4 py-4"
                    >
                        <button
                            class="text-lg font-bold"
                            onclick="App.actions.goList()"
                        >
                            アンケート管理システム
                        </button>

                        <nav class="flex gap-1">
                            <button
                                class="rounded-lg px-4 py-2
                                       hover:bg-slate-100"
                                onclick="App.actions.goList()"
                            >
                                アンケート一覧
                            </button>

                            <button
                                class="rounded-lg px-4 py-2
                                       hover:bg-slate-100"
                                onclick="App.actions.showSettings()"
                            >
                                キントーン・メール設定
                            </button>

                            <button
                                class="rounded-lg px-4 py-2
                                       hover:bg-slate-100"
                                onclick="App.actions.logout()"
                            >
                                ログアウト
                            </button>
                        </nav>
                    </div>
                </header>

                <main
                    id="main_content"
                    class="mx-auto max-w-7xl px-4 py-6"
                ></main>
            </div>

            <div
                id="preview_modal"
                class="fixed inset-0 z-50 hidden
                       bg-black/50 p-4"
            >
                <div
                    class="mx-auto flex max-h-[90vh]
                           max-w-4xl flex-col
                           overflow-hidden rounded-2xl
                           bg-white shadow-2xl"
                >
                    <div
                        class="flex items-center
                               justify-between border-b
                               px-6 py-4"
                    >
                        <h2 class="font-bold">
                            プレビュー
                        </h2>

                        <button
                            class="rounded-lg px-3 py-2
                                   hover:bg-slate-100"
                            onclick="App.actions.closePreview()"
                        >
                            閉じる
                        </button>
                    </div>

                    <div
                        id="preview_content"
                        class="overflow-y-auto p-6"
                    ></div>
                </div>
            </div>

            <div
                id="response_modal"
                class="fixed inset-0 z-50 hidden
                       bg-black/50 p-4"
            >
                <div
                    class="mx-auto max-w-3xl
                           rounded-2xl bg-white p-6
                           shadow-2xl"
                >
                    <div
                        class="mb-4 flex items-center
                               justify-between"
                    >
                        <h2 class="font-bold">
                            回答詳細
                        </h2>

                        <button
                            onclick="App.actions.closeResponse()"
                            class="rounded-lg px-3 py-2
                                   hover:bg-slate-100"
                        >
                            閉じる
                        </button>
                    </div>

                    <div id="response_detail"></div>
                </div>
            </div>
        `;
    },

    current: function () {
        const screen = App.state.screen;

        if (screen === 'list') {
            this.list();
        } else if (screen === 'edit') {
            this.editor();
        } else if (screen === 'settings') {
            this.settings();
        } else if (screen === 'send') {
            this.send();
        } else if (screen === 'summary') {
            this.summary();
        } else if (screen === 'respondent') {
            this.respondent();
        } else if (screen === 'answer') {
            this.answer();
        } else if (screen === 'confirm') {
            this.answerConfirm();
        } else if (screen === 'complete') {
            this.complete();
        }
    },

    breadcrumb: function (items) {
        return `
            <div class="mb-4 text-sm text-slate-500">
                ${items.map(function (item, index) {
                    return `
                        <span>
                            ${App.utils.escapeHTML(item)}
                        </span>
                        ${
                            index < items.length - 1
                                ? '<span class="mx-2">＞</span>'
                                : ''
                        }
                    `;
                }).join('')}
            </div>
        `;
    },

    list: function () {
        const main =
            document.getElementById(
                'main_content'
            );

        let surveys =
            App.state.surveys.filter(
                function (survey) {
                    if (survey.deleted) {
                        return false;
                    }

                    const keyword =
                        App.state.keyword
                            .trim()
                            .toLowerCase();

                    if (
                        keyword &&
                        !String(
                            survey.title || ''
                        )
                            .toLowerCase()
                            .includes(keyword)
                    ) {
                        return false;
                    }

                    if (
                        App.state.statusFilter &&
                        survey.status !==
                            App.state.statusFilter
                    ) {
                        return false;
                    }

                    return true;
                }
            );

        surveys.sort(
            function (a, b) {
                const at =
                    Date.parse(
                        a.updated_at || ''
                    ) || 0;

                const bt =
                    Date.parse(
                        b.updated_at || ''
                    ) || 0;

                return App.state.sort ===
                    'updated_asc'
                    ? at - bt
                    : bt - at;
            }
        );

        main.innerHTML = `
            ${this.breadcrumb([
                'ホーム',
                'アンケート一覧'
            ])}

            <div
                class="mb-6 flex items-center
                       justify-between"
            >
                <div>
                    <h1
                        class="text-2xl font-bold"
                    >
                        アンケート一覧
                    </h1>

                    <p class="mt-1 text-sm text-slate-500">
                        アンケートの検索・確認・編集・集計・送信を行います。
                    </p>
                </div>

                <button
                    class="rounded-xl bg-blue-600
                           px-5 py-3 font-semibold
                           text-white hover:bg-blue-700"
                    onclick="App.actions.newSurvey()"
                >
                    ＋ 新規アンケート
                </button>
            </div>

            <div
                class="mb-5 grid gap-3 rounded-2xl
                       bg-white p-4 shadow-sm
                       md:grid-cols-4"
            >
                <input
                    class="rounded-lg border px-3 py-2"
                    placeholder="タイトル検索"
                    value="${App.utils.escapeAttr(
                        App.state.keyword
                    )}"
                    oninput="App.actions.setKeyword(this.value)"
                >

                <select
                    class="rounded-lg border px-3 py-2"
                    onchange="App.actions.setStatusFilter(this.value)"
                >
                    <option value="">すべてのステータス</option>
                    <option
                        value="draft"
                        ${App.state.statusFilter === 'draft'
                            ? 'selected'
                            : ''}
                    >
                        下書き
                    </option>
                    <option
                        value="active"
                        ${App.state.statusFilter === 'active'
                            ? 'selected'
                            : ''}
                    >
                        公開中
                    </option>
                    <option
                        value="ended"
                        ${App.state.statusFilter === 'ended'
                            ? 'selected'
                            : ''}
                    >
                        終了
                    </option>
                </select>

                <select
                    class="rounded-lg border px-3 py-2"
                    onchange="App.actions.setSort(this.value)"
                >
                    <option
                        value="updated_desc"
                        ${App.state.sort === 'updated_desc'
                            ? 'selected'
                            : ''}
                    >
                        更新日時が新しい順
                    </option>
                    <option
                        value="updated_asc"
                        ${App.state.sort === 'updated_asc'
                            ? 'selected'
                            : ''}
                    >
                        更新日時が古い順
                    </option>
                </select>

                <div
                    class="flex items-center
                           justify-end text-sm
                           text-slate-500"
                >
                    ${surveys.length} 件
                </div>
            </div>

            <div
                class="overflow-hidden rounded-2xl
                       bg-white shadow-sm"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="border-b bg-slate-50"
                        >
                            <tr>
                                <th class="px-4 py-3 text-left">
                                    アンケート
                                </th>
                                <th class="px-4 py-3">
                                    ステータス
                                </th>
                                <th class="px-4 py-3">
                                    回答数
                                </th>
                                <th class="px-4 py-3">
                                    更新日時
                                </th>
                                <th class="px-4 py-3 text-right">
                                    操作
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            ${
                                surveys.length
                                    ? surveys.map(
                                        this.surveyRow
                                      ).join('')
                                    : `
                                        <tr>
                                            <td
                                                colspan="5"
                                                class="px-4 py-12
                                                       text-center
                                                       text-slate-500"
                                            >
                                                アンケートがありません。
                                            </td>
                                        </tr>
                                    `
                            }
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    },

    surveyRow: function (survey) {
        const statusText = {
            draft: '下書き',
            active: '公開中',
            ended: '終了'
        }[survey.status] || survey.status;

        const statusClass = {
            draft:
                'bg-slate-100 text-slate-700',
            active:
                'bg-emerald-100 text-emerald-700',
            ended:
                'bg-red-100 text-red-700'
        }[survey.status] || '';

        let actions = `
            <button
                class="rounded-lg bg-blue-50
                       px-3 py-2 text-blue-700
                       hover:bg-blue-100"
                onclick="App.actions.editSurvey('${App.utils.escapeAttr(survey.id)}')"
            >
                確認・編集
            </button>

            <button
                class="rounded-lg bg-slate-50
                       px-3 py-2 text-slate-700
                       hover:bg-slate-100"
                onclick="App.actions.duplicateSurvey('${App.utils.escapeAttr(survey.id)}')"
            >
                複製
            </button>
        `;

        if (
            survey.status === 'active' ||
            survey.status === 'ended'
        ) {
            actions = `
                <button
                    class="rounded-lg bg-blue-50
                           px-3 py-2 text-blue-700
                           hover:bg-blue-100"
                    onclick="App.actions.editSurvey('${App.utils.escapeAttr(survey.id)}')"
                >
                    確認・編集
                </button>

                <button
                    class="rounded-lg bg-purple-50
                           px-3 py-2 text-purple-700
                           hover:bg-purple-100"
                    onclick="App.actions.showSummary('${App.utils.escapeAttr(survey.id)}')"
                >
                    集計
                </button>

                <button
                    class="rounded-lg bg-emerald-50
                           px-3 py-2 text-emerald-700
                           hover:bg-emerald-100"
                    onclick="App.actions.showSend('${App.utils.escapeAttr(survey.id)}')"
                >
                    送信
                </button>

                <button
                    class="rounded-lg bg-slate-50
                           px-3 py-2 text-slate-700
                           hover:bg-slate-100"
                    onclick="App.actions.duplicateSurvey('${App.utils.escapeAttr(survey.id)}')"
                >
                    複製
                </button>
            `;
        }

        if (survey.status === 'draft') {
            actions += `
                <button
                    class="rounded-lg bg-red-50
                           px-3 py-2 text-red-700
                           hover:bg-red-100"
                    onclick="App.actions.deleteSurvey('${App.utils.escapeAttr(survey.id)}')"
                >
                    削除
                </button>
            `;
        }

        return `
            <tr class="border-b last:border-0">
                <td class="px-4 py-4">
                    <div class="font-semibold">
                        ${App.utils.escapeHTML(
                            survey.title
                        )}
                    </div>

                    <div class="mt-1 text-xs text-slate-500">
                        ${App.utils.escapeHTML(
                            survey.id
                        )}
                    </div>
                </td>

                <td class="px-4 py-4 text-center">
                    <span
                        class="inline-flex rounded-full
                               px-3 py-1 text-xs font-semibold
                               ${statusClass}"
                    >
                        ${statusText}
                    </span>
                </td>

                <td class="px-4 py-4 text-center">
                    ${Number(
                        survey.response_count || 0
                    )}
                </td>

                <td class="px-4 py-4 text-center">
                    ${App.utils.escapeHTML(
                        survey.updated_at || ''
                    )}
                </td>

                <td class="px-4 py-4">
                    <div
                        class="flex flex-wrap
                               justify-end gap-2"
                    >
                        ${actions}
                    </div>
                </td>
            </tr>
        `;
    },

    editor: function () {
        const main =
            document.getElementById(
                'main_content'
            );

        const survey =
            App.state.currentSurvey;

        if (!survey) {
            App.actions.goList();
            return;
        }

        const groupHTML =
            (survey.groups || []).map(
                this.groupHTML
            ).join('');

        main.innerHTML = `
            ${this.breadcrumb([
                'ホーム',
                'アンケート一覧',
                'アンケート作成・編集'
            ])}

            <div
                class="mb-5 flex flex-wrap
                       items-center justify-between
                       gap-3"
            >
                <div>
                    <h1 class="text-2xl font-bold">
                        アンケート作成・編集
                    </h1>

                    <p class="mt-1 text-sm text-slate-500">
                        アンケート内容、ブロック、質問、分岐、ステータスを編集します。
                    </p>
                </div>

                <div class="flex gap-2">
                    <button
                        class="rounded-xl border
                               bg-white px-4 py-2
                               hover:bg-slate-50"
                        onclick="App.actions.preview()"
                    >
                        プレビュー
                    </button>

                    <button
                        class="rounded-xl bg-blue-600
                               px-5 py-2 font-semibold
                               text-white hover:bg-blue-700"
                        onclick="App.actions.saveSurvey()"
                    >
                        保存
                    </button>
                </div>
            </div>

            <div
                class="mb-5 grid gap-5
                       lg:grid-cols-2"
            >
                <div
                    class="rounded-2xl bg-white
                           p-5 shadow-sm"
                >
                    <h2 class="mb-4 text-lg font-bold">
                        基本設定
                    </h2>

                    <div class="space-y-4">
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium">
                                タイトル
                            </span>

                            <input
                                id="survey_title"
                                class="w-full rounded-lg border
                                       px-3 py-2"
                                value="${App.utils.escapeAttr(
                                    survey.title
                                )}"
                                oninput="App.actions.updateSurveyField('title', this.value)"
                            >
                        </label>

                        <div class="grid gap-4 md:grid-cols-2">
                            <label>
                                <span class="mb-1 block text-sm font-medium">
                                    開始日時
                                </span>

                                <input
                                    id="survey_start_at"
                                    type="datetime-local"
                                    class="w-full rounded-lg border
                                           px-3 py-2"
                                    value="${App.utils.escapeAttr(
                                        survey.start_at
                                    )}"
                                    onchange="App.actions.updateSurveyField('start_at', this.value)"
                                >
                            </label>

                            <label>
                                <span class="mb-1 block text-sm font-medium">
                                    終了日時
                                </span>

                                <input
                                    id="survey_end_at"
                                    type="datetime-local"
                                    class="w-full rounded-lg border
                                           px-3 py-2"
                                    value="${App.utils.escapeAttr(
                                        survey.end_at
                                    )}"
                                    onchange="App.actions.updateSurveyField('end_at', this.value)"
                                >
                            </label>
                        </div>

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                ステータス
                            </span>

                            <select
                                id="survey_status"
                                class="w-full rounded-lg border
                                       px-3 py-2"
                                onchange="App.actions.changeSurveyStatus(this.value)"
                            >
                                <option
                                    value="draft"
                                    ${survey.status === 'draft'
                                        ? 'selected'
                                        : ''}
                                >
                                    下書き
                                </option>

                                <option
                                    value="active"
                                    ${survey.status === 'active'
                                        ? 'selected'
                                        : ''}
                                >
                                    公開中
                                </option>

                                <option
                                    value="ended"
                                    ${survey.status === 'ended'
                                        ? 'selected'
                                        : ''}
                                >
                                    終了
                                </option>
                            </select>
                        </label>

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                質問番号形式
                            </span>

                            <select
                                id="survey_numbering_mode"
                                class="w-full rounded-lg border
                                       px-3 py-2"
                                onchange="App.actions.changeNumberingMode(this.value)"
                            >
                                <option
                                    value="global"
                                    ${survey.numbering_mode === 'global'
                                        ? 'selected'
                                        : ''}
                                >
                                    Q1 / Q2 / Q3
                                </option>

                                <option
                                    value="group"
                                    ${survey.numbering_mode === 'group'
                                        ? 'selected'
                                        : ''}
                                >
                                    Q1-1 / Q1-2 / Q2-1
                                </option>
                            </select>
                        </label>

                        <label
                            class="flex items-center gap-2"
                        >
                            <input
                                type="checkbox"
                                ${survey.general_response_enabled
                                    ? 'checked'
                                    : ''}
                                onchange="App.actions.updateSurveyField('general_response_enabled', this.checked)"
                            >

                            <span class="text-sm">
                                一般回答を許可する
                            </span>
                        </label>
                    </div>
                </div>

                <div
                    class="rounded-2xl bg-white
                           p-5 shadow-sm"
                >
                    <h2 class="mb-4 text-lg font-bold">
                        その他設定
                    </h2>

                    <div class="space-y-3 text-sm">
                        <div>
                            <span class="font-medium">
                                アンケートID
                            </span>

                            <div
                                class="mt-1 rounded-lg
                                       bg-slate-50 p-3
                                       font-mono text-xs"
                            >
                                ${App.utils.escapeHTML(
                                    survey.id
                                )}
                            </div>
                        </div>

                        <div>
                            <span class="font-medium">
                                公開トークン
                            </span>

                            <div
                                class="mt-1 rounded-lg
                                       bg-slate-50 p-3
                                       font-mono text-xs break-all"
                            >
                                ${App.utils.escapeHTML(
                                    survey.public_token
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div
                id="question_editor"
                class="space-y-5"
            >
                <div
                    data-sortable-groups
                    class="space-y-5"
                >
                    ${groupHTML}
                </div>

                <button
                    class="w-full rounded-xl border-2
                           border-dashed border-blue-300
                           bg-blue-50 px-5 py-4
                           font-semibold text-blue-700
                           hover:bg-blue-100"
                    onclick="App.actions.addGroup()"
                >
                    ＋ ブロックを追加
                </button>
            </div>
        `;

        App.initSortable();
    },

    groupHTML: function (group, groupIndex) {
        const questions =
            (group.questions || [])
                .map(
                    function (question) {
                        return App.render.questionHTML(
                            question,
                            group
                        );
                    }
                )
                .join('');

        return `
            <section
                class="rounded-2xl bg-white
                       shadow-sm"
                data-group-id="${App.utils.escapeAttr(
                    group.id
                )}"
            >
                <div
                    class="flex items-center
                           justify-between
                           border-b px-5 py-4"
                >
                    <div
                        class="flex items-center gap-3"
                    >
                        <span
                            data-group-handle
                            class="cursor-move text-slate-400"
                        >
                            ☰
                        </span>

                        <div>
                            <div class="text-xs text-slate-500">
                                ブロック ${groupIndex + 1}
                            </div>

                            <input
                                class="mt-1 rounded-lg
                                       border px-3 py-2
                                       font-semibold"
                                value="${App.utils.escapeAttr(
                                    group.name
                                )}"
                                oninput="App.actions.updateGroupName('${App.utils.escapeAttr(group.id)}', this.value)"
                            >
                        </div>
                    </div>

                    <button
                        class="rounded-lg bg-red-50
                               px-3 py-2 text-sm
                               text-red-700"
                        onclick="App.actions.removeGroup('${App.utils.escapeAttr(group.id)}')"
                    >
                        グループ削除
                    </button>
                </div>

                <div
                    class="space-y-4 p-5"
                    data-sortable-questions
                    data-group-id="${App.utils.escapeAttr(
                        group.id
                    )}"
                >
                    ${questions}
                </div>

                <div class="border-t p-5">
                    <button
                        class="w-full rounded-xl border
                               border-blue-200
                               bg-blue-50 px-4 py-3
                               font-semibold text-blue-700
                               hover:bg-blue-100"
                        onclick="App.actions.addQuestion('${App.utils.escapeAttr(group.id)}')"
                    >
                        ＋ 質問を追加
                    </button>
                </div>
            </section>
        `;
    },

    questionHTML: function (question, group) {
        const survey =
            App.state.currentSurvey;

        const allQuestions =
            App.utils.flattenQuestions(
                survey
            );

        const currentIndex =
            allQuestions.findIndex(
                function (item) {
                    return item.question.id ===
                        question.id;
                }
            );

        const candidates =
            allQuestions.slice(
                currentIndex + 1
            );

        const options =
            (question.options || [])
                .map(
                    function (option, index) {
                        const selected =
                            question.branching &&
                            Object.prototype.hasOwnProperty.call(
                                question.branching,
                                option.id
                            )
                                ? question.branching[
                                    option.id
                                ]
                                : null;

                        return `
                            <div
                                class="rounded-xl
                                       border bg-slate-50
                                       p-3"
                            >
                                <div
                                    class="grid gap-3
                                           md:grid-cols-[1fr_260px]"
                                >
                                    <input
                                        class="rounded-lg
                                               border bg-white
                                               px-3 py-2"
                                        value="${App.utils.escapeAttr(
                                            option.label
                                        )}"
                                        placeholder="選択肢"
                                        oninput="App.actions.updateOption('${App.utils.escapeAttr(group.id)}','${App.utils.escapeAttr(question.id)}','${App.utils.escapeAttr(option.id)}',this.value)"
                                    >

                                    <label>
                                        <span
                                            class="mb-1 block
                                                   text-xs
                                                   font-medium
                                                   text-slate-500"
                                        >
                                            分岐先質問
                                        </span>

                                        <select
                                            class="w-full rounded-lg
                                                   border bg-white
                                                   px-3 py-2"
                                            onchange="App.actions.changeBranch('${App.utils.escapeAttr(group.id)}','${App.utils.escapeAttr(question.id)}','${App.utils.escapeAttr(option.id)}',this.value)"
                                        >
                                            <option value="">
                                                表示しない
                                            </option>

                                            ${candidates.map(
                                                function (item) {
                                                    return `
                                                        <option
                                                            value="${App.utils.escapeAttr(item.question.id)}"
                                                            ${selected === item.question.id
                                                                ? 'selected'
                                                                : ''}
                                                        >
                                                            ${App.utils.escapeHTML(
                                                                App.utils.questionLabel(
                                                                    item.question
                                                                )
                                                            )}
                                                        </option>
                                                    `;
                                                }
                                            ).join('')}
                                        </select>
                                    </label>
                                </div>

                                <div class="mt-2 flex justify-end">
                                    <button
                                        class="text-xs text-red-600"
                                        onclick="App.actions.removeOption('${App.utils.escapeAttr(group.id)}','${App.utils.escapeAttr(question.id)}','${App.utils.escapeAttr(option.id)}')"
                                    >
                                        選択肢削除
                                    </button>
                                </div>
                            </div>
                        `;
                    }
                )
                .join('');

        return `
            <article
                class="rounded-xl border
                       border-slate-200
                       bg-white shadow-sm"
                data-question-id="${App.utils.escapeAttr(
                    question.id
                )}"
            >
                <div
                    class="flex items-start
                           justify-between gap-3
                           border-b bg-slate-50
                           px-4 py-3"
                >
                    <div class="flex items-center gap-3">
                        <span
                            data-question-handle
                            class="cursor-move
                                   text-slate-400"
                        >
                            ☰
                        </span>

                        <span
                            class="rounded-lg
                                   bg-blue-100 px-2 py-1
                                   text-sm font-bold
                                   text-blue-700"
                        >
                            ${App.utils.escapeHTML(
                                question.number || ''
                            )}
                        </span>
                    </div>

                    <button
                        class="rounded-lg bg-red-50
                               px-3 py-2 text-sm
                               text-red-700"
                        onclick="App.actions.removeQuestion('${App.utils.escapeAttr(group.id)}','${App.utils.escapeAttr(question.id)}')"
                    >
                        質問削除
                    </button>
                </div>

                <div class="space-y-4 p-4">
                    <label class="block">
                        <span
                            class="mb-1 block text-sm
                                   font-medium"
                        >
                            質問文
                        </span>

                        <textarea
                            class="w-full rounded-lg
                                   border px-3 py-2"
                            rows="2"
                            oninput="App.actions.updateQuestion('${App.utils.escapeAttr(group.id)}','${App.utils.escapeAttr(question.id)}','text',this.value)"
                        >${App.utils.escapeHTML(
                            question.text
                        )}</textarea>
                    </label>

                    <div class="grid gap-4 md:grid-cols-3">
                        <label>
                            <span
                                class="mb-1 block text-sm
                                       font-medium"
                            >
                                質問形式
                            </span>

                            <select
                                class="w-full rounded-lg
                                       border px-3 py-2"
                                onchange="App.actions.updateQuestion('${App.utils.escapeAttr(group.id)}','${App.utils.escapeAttr(question.id)}','type',this.value)"
                            >
                                <option
                                    value="text"
                                    ${question.type === 'text'
                                        ? 'selected'
                                        : ''}
                                >
                                    自由記述
                                </option>

                                <option
                                    value="single"
                                    ${question.type === 'single'
                                        ? 'selected'
                                        : ''}
                                >
                                    単一選択
                                </option>

                                <option
                                    value="multiple"
                                    ${question.type === 'multiple'
                                        ? 'selected'
                                        : ''}
                                >
                                    複数選択
                                </option>
                            </select>
                        </label>

                        <label
                            class="flex items-center
                                   gap-2 pt-7"
                        >
                            <input
                                type="checkbox"
                                ${question.required
                                    ? 'checked'
                                    : ''}
                                onchange="App.actions.updateQuestion('${App.utils.escapeAttr(group.id)}','${App.utils.escapeAttr(question.id)}','required',this.checked)"
                            >

                            <span class="text-sm">
                                必須回答
                            </span>
                        </label>

                        <label
                            class="flex items-center
                                   gap-2 pt-7"
                        >
                            <input
                                type="checkbox"
                                ${question.other_enabled
                                    ? 'checked'
                                    : ''}
                                onchange="App.actions.updateQuestion('${App.utils.escapeAttr(group.id)}','${App.utils.escapeAttr(question.id)}','other_enabled',this.checked)"
                            >

                            <span class="text-sm">
                                その他を許可
                            </span>
                        </label>
                    </div>

                    ${
                        question.type === 'single' ||
                        question.type === 'multiple'
                            ? `
                                <div>
                                    <div
                                        class="mb-2 flex
                                               items-center
                                               justify-between"
                                    >
                                        <h4
                                            class="font-semibold"
                                        >
                                            選択肢
                                        </h4>

                                        <button
                                            class="rounded-lg
                                                   bg-blue-50
                                                   px-3 py-2
                                                   text-sm
                                                   text-blue-700"
                                            onclick="App.actions.addOption('${App.utils.escapeAttr(group.id)}','${App.utils.escapeAttr(question.id)}')"
                                        >
                                            ＋ 選択肢を追加
                                        </button>
                                    </div>

                                    <div class="space-y-2">
                                        ${options}
                                    </div>
                                </div>
                            `
                            : ''
                    }
                </div>
            </article>
        `;
    },

    settings: function () {
        const main =
            document.getElementById(
                'main_content'
            );

        const s = App.state.settings || {};

        main.innerHTML = `
            ${this.breadcrumb([
                'ホーム',
                'キントーン・メール設定'
            ])}

            <div
                class="mb-5 flex items-center
                       justify-between"
            >
                <div>
                    <h1 class="text-2xl font-bold">
                        キントーン・メール設定
                    </h1>

                    <p class="mt-1 text-sm text-slate-500">
                        キントーンとSMTPの接続情報を保存し、実際の通信に使用します。
                    </p>
                </div>

                <button
                    class="rounded-xl bg-blue-600
                           px-5 py-3 font-semibold
                           text-white"
                    onclick="App.actions.saveSettings()"
                >
                    設定を保存
                </button>
            </div>

            <form
                id="settings_form"
                onsubmit="event.preventDefault(); App.actions.saveSettings();"
                class="space-y-5"
            >
                <input
                    type="hidden"
                    id="settings_json"
                >

                <section
                    class="rounded-2xl bg-white
                           p-6 shadow-sm"
                >
                    <h2 class="mb-5 text-lg font-bold">
                        キントーン設定
                    </h2>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                サブドメイン
                            </span>
                            <input
                                id="setting_subdomain"
                                class="w-full rounded-lg border px-3 py-2"
                                value="${App.utils.escapeAttr(
                                    s.subdomain || ''
                                )}"
                            >
                        </label>

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                顧客管理アプリID
                            </span>
                            <input
                                id="setting_app_id"
                                type="number"
                                min="1"
                                class="w-full rounded-lg border px-3 py-2"
                                value="${App.utils.escapeAttr(
                                    s.app_id || ''
                                )}"
                            >
                        </label>

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                ログイン名
                            </span>
                            <input
                                id="setting_login_name"
                                class="w-full rounded-lg border px-3 py-2"
                                value="${App.utils.escapeAttr(
                                    s.login_name || ''
                                )}"
                            >
                        </label>

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                パスワード
                            </span>
                            <input
                                id="setting_password"
                                type="password"
                                autocomplete="new-password"
                                class="w-full rounded-lg border px-3 py-2"
                                placeholder="変更しない場合は空欄"
                            >
                        </label>

                        <label
                            class="flex items-center
                                   gap-2 pt-7"
                        >
                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${s.ssl_verify !== false
                                    ? 'checked'
                                    : ''}
                            >
                            <span class="text-sm">
                                SSL証明書検証
                            </span>
                        </label>

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                Proxy
                            </span>
                            <input
                                id="setting_proxy"
                                class="w-full rounded-lg border px-3 py-2"
                                value="${App.utils.escapeAttr(
                                    s.proxy || ''
                                )}"
                                placeholder="例: tcp://proxy.example:8080"
                            >
                        </label>
                    </div>

                    <div class="mt-5">
                        <h3 class="mb-3 font-semibold">
                            顧客フィールド
                        </h3>

                        <div class="grid gap-4 md:grid-cols-2">
                            ${this.settingInput(
                                'field_company',
                                '会社名',
                                s.field_company
                            )}

                            ${this.settingInput(
                                'field_name',
                                '氏名',
                                s.field_name
                            )}

                            ${this.settingInput(
                                'field_email',
                                'メールアドレス',
                                s.field_email
                            )}

                            ${this.settingInput(
                                'field_department',
                                '部署',
                                s.field_department
                            )}

                            ${this.settingInput(
                                'field_phone',
                                '電話番号',
                                s.field_phone
                            )}

                            ${this.settingInput(
                                'field_address',
                                '住所',
                                s.field_address
                            )}
                        </div>
                    </div>

                    <div
                        class="mt-5 flex flex-wrap gap-2"
                    >
                        <button
                            type="button"
                            class="rounded-lg bg-blue-600
                                   px-4 py-2 text-white"
                            onclick="App.actions.connectKintone()"
                        >
                            キントーン接続確認
                        </button>

                        <button
                            type="button"
                            class="rounded-lg bg-slate-100
                                   px-4 py-2"
                            onclick="App.actions.fetchKintoneFields()"
                        >
                            フィールド取得
                        </button>

                        <button
                            type="button"
                            class="rounded-lg bg-emerald-600
                                   px-4 py-2 text-white"
                            onclick="App.actions.syncCustomers()"
                        >
                            顧客データを同期
                        </button>
                    </div>

                    <div
                        id="field_message"
                        class="mt-4 text-sm"
                    ></div>
                </section>

                <section
                    class="rounded-2xl bg-white
                           p-6 shadow-sm"
                >
                    <h2 class="mb-5 text-lg font-bold">
                        SMTP設定
                    </h2>

                    <div class="grid gap-4 md:grid-cols-2">
                        ${this.settingInput(
                            'smtp_server',
                            'SMTPサーバ',
                            s.smtp_server
                        )}

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                SMTPポート
                            </span>
                            <input
                                id="smtp_port"
                                type="number"
                                class="w-full rounded-lg border px-3 py-2"
                                value="${App.utils.escapeAttr(
                                    s.smtp_port || 587
                                )}"
                            >
                        </label>

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                暗号化方式
                            </span>
                            <select
                                id="smtp_encryption"
                                class="w-full rounded-lg border px-3 py-2"
                            >
                                <option
                                    value="none"
                                    ${s.smtp_encryption === 'none'
                                        ? 'selected'
                                        : ''}
                                >
                                    なし
                                </option>
                                <option
                                    value="starttls"
                                    ${!s.smtp_encryption ||
                                      s.smtp_encryption === 'starttls'
                                        ? 'selected'
                                        : ''}
                                >
                                    STARTTLS
                                </option>
                                <option
                                    value="ssl"
                                    ${s.smtp_encryption === 'ssl'
                                        ? 'selected'
                                        : ''}
                                >
                                    SSL/TLS
                                </option>
                            </select>
                        </label>

                        <label
                            class="flex items-center
                                   gap-2 pt-7"
                        >
                            <input
                                id="smtp_auth"
                                type="checkbox"
                                ${s.smtp_auth
                                    ? 'checked'
                                    : ''}
                            >
                            <span class="text-sm">
                                SMTP認証
                            </span>
                        </label>

                        ${this.settingInput(
                            'smtp_username',
                            'SMTPユーザー名',
                            s.smtp_username
                        )}

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                SMTPパスワード
                            </span>
                            <input
                                id="smtp_password"
                                type="password"
                                autocomplete="new-password"
                                class="w-full rounded-lg border px-3 py-2"
                                placeholder="変更しない場合は空欄"
                            >
                        </label>

                        ${this.settingInput(
                            'smtp_from_email',
                            '送信元メールアドレス',
                            s.smtp_from_email
                        )}

                        ${this.settingInput(
                            'smtp_from_name',
                            '送信元表示名',
                            s.smtp_from_name
                        )}

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                接続タイムアウト
                            </span>
                            <input
                                id="smtp_timeout"
                                type="number"
                                min="1"
                                class="w-full rounded-lg border px-3 py-2"
                                value="${App.utils.escapeAttr(
                                    s.smtp_timeout || 15
                                )}"
                            >
                        </label>
                    </div>

                    <div
                        class="mt-5 flex flex-wrap gap-2"
                    >
                        <button
                            type="button"
                            class="rounded-lg bg-blue-600
                                   px-4 py-2 text-white"
                            onclick="App.actions.testSMTPConnection()"
                        >
                            SMTP接続確認
                        </button>

                        <button
                            type="button"
                            class="rounded-lg bg-emerald-600
                                   px-4 py-2 text-white"
                            onclick="App.actions.testSMTPMail()"
                        >
                            テストメール送信
                        </button>
                    </div>
                </section>
            </form>
        `;
    },

    settingInput: function (
        id,
        label,
        value
    ) {
        return `
            <label>
                <span
                    class="mb-1 block text-sm
                           font-medium"
                >
                    ${App.utils.escapeHTML(label)}
                </span>

                <input
                    id="${App.utils.escapeAttr(id)}"
                    class="w-full rounded-lg
                           border px-3 py-2"
                    value="${App.utils.escapeAttr(
                        value || ''
                    )}"
                >
            </label>
        `;
    },

    send: function () {
        const main =
            document.getElementById(
                'main_content'
            );

        const survey =
            App.state.currentSurvey;

        const selected =
            new Set();

        main.innerHTML = `
            ${this.breadcrumb([
                'ホーム',
                'アンケート一覧',
                '送信'
            ])}

            <div class="mb-5">
                <h1 class="text-2xl font-bold">
                    送信
                </h1>

                <p class="mt-1 text-sm text-slate-500">
                    ${App.utils.escapeHTML(
                        survey?.title || ''
                    )}
                </p>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <section
                    class="rounded-2xl bg-white
                           p-5 shadow-sm"
                >
                    <h2 class="mb-4 font-bold">
                        顧客選択
                    </h2>

                    <input
                        id="customer_filter"
                        class="mb-4 w-full rounded-lg
                               border px-3 py-2"
                        placeholder="会社名・氏名・メール検索"
                        oninput="App.actions.filterCustomers(this.value)"
                    >

                    <div class="overflow-x-auto">
                        <table
                            id="customer_table"
                            class="w-full text-sm"
                        >
                            <thead>
                                <tr class="border-b">
                                    <th class="p-2">
                                        <input
                                            id="select_all"
                                            type="checkbox"
                                            onchange="App.actions.toggleAllCustomers(this.checked)"
                                        >
                                    </th>
                                    <th class="p-2 text-left">
                                        会社名
                                    </th>
                                    <th class="p-2 text-left">
                                        氏名
                                    </th>
                                    <th class="p-2 text-left">
                                        メール
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                ${App.state.customers.map(
                                    function (customer) {
                                        return `
                                            <tr
                                                data-customer-row
                                                class="border-b"
                                            >
                                                <td class="p-2">
                                                    <input
                                                        type="checkbox"
                                                        data-customer-id="${App.utils.escapeAttr(customer.id)}"
                                                    >
                                                </td>
                                                <td class="p-2">
                                                    ${App.utils.escapeHTML(customer.company)}
                                                </td>
                                                <td class="p-2">
                                                    ${App.utils.escapeHTML(customer.name)}
                                                </td>
                                                <td class="p-2">
                                                    ${App.utils.escapeHTML(customer.email)}
                                                </td>
                                            </tr>
                                        `;
                                    }
                                ).join('')}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section
                    class="rounded-2xl bg-white
                           p-5 shadow-sm"
                >
                    <h2 class="mb-4 font-bold">
                        メール送信
                    </h2>

                    <div class="space-y-4">
                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                種別
                            </span>

                            <select
                                id="template_type"
                                class="w-full rounded-lg border px-3 py-2"
                            >
                                <option value="initial">
                                    初回
                                </option>
                                <option value="reminder">
                                    リマインド
                                </option>
                                <option value="resend">
                                    再送
                                </option>
                            </select>
                        </label>

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                件名
                            </span>

                            <input
                                id="mail_subject"
                                class="w-full rounded-lg border px-3 py-2"
                                value="${App.utils.escapeAttr(
                                    survey?.title || ''
                                )}"
                            >
                        </label>

                        <label>
                            <span class="mb-1 block text-sm font-medium">
                                本文
                            </span>

                            <textarea
                                id="mail_body"
                                rows="12"
                                class="w-full rounded-lg border px-3 py-2"
                            >アンケートへのご回答をお願いいたします。</textarea>
                        </label>

                        <button
                            class="w-full rounded-xl
                                   bg-blue-600 px-5 py-3
                                   font-semibold text-white"
                            onclick="App.actions.sendMail()"
                        >
                            一括送信
                        </button>
                    </div>
                </section>
            </div>
        `;
    },

    summary: function () {
        const main =
            document.getElementById(
                'main_content'
            );

        const survey =
            App.state.currentSurvey;

        const responses =
            App.state.responses.filter(
                function (response) {
                    return response.survey_id ===
                        survey.id &&
                        !response.deleted;
                }
            );

        const rows = [];

        App.utils.flattenQuestions(
            survey
        ).forEach(
            function (item) {
                const question =
                    item.question;

                const counts = {};

                responses.forEach(
                    function (response) {
                        const value =
                            response.answers?.[
                                question.id
                            ];

                        if (Array.isArray(value)) {
                            value.forEach(
                                function (v) {
                                    counts[v] =
                                        (counts[v] || 0) + 1;
                                }
                            );
                        } else if (
                            value !== undefined &&
                            value !== ''
                        ) {
                            counts[value] =
                                (counts[value] || 0) + 1;
                        }
                    }
                );

                rows.push(`
                    <div
                        class="rounded-xl border p-4"
                    >
                        <div class="font-semibold">
                            ${App.utils.escapeHTML(
                                question.number
                            )}
                            ${App.utils.escapeHTML(
                                question.text
                            )}
                        </div>

                        ${
                            question.options?.length
                                ? `
                                    <div class="mt-3 space-y-2">
                                        ${question.options.map(
                                            function (option) {
                                                return `
                                                    <div
                                                        class="flex
                                                               justify-between
                                                               rounded-lg
                                                               bg-slate-50
                                                               px-3 py-2"
                                                    >
                                                        <span>
                                                            ${App.utils.escapeHTML(option.label)}
                                                        </span>
                                                        <strong>
                                                            ${counts[option.id] || counts[option.label] || 0}
                                                        </strong>
                                                    </div>
                                                `;
                                            }
                                        ).join('')}
                                    </div>
                                `
                                : `
                                    <div
                                        class="mt-3 text-sm
                                               text-slate-500"
                                    >
                                        自由記述回答あり
                                    </div>
                                `
                        }
                    </div>
                `);
            }
        );

        main.innerHTML = `
            ${this.breadcrumb([
                'ホーム',
                'アンケート一覧',
                '集計'
            ])}

            <div
                class="mb-5 flex flex-wrap
                       items-center justify-between
                       gap-3"
            >
                <div>
                    <h1 class="text-2xl font-bold">
                        集計
                    </h1>
                    <p class="text-sm text-slate-500">
                        ${App.utils.escapeHTML(
                            survey.title
                        )}
                    </p>
                </div>

                <div class="flex gap-2">
                    <button
                        class="rounded-lg border
                               bg-white px-4 py-2"
                        onclick="App.actions.exportCSV()"
                    >
                        CSV
                    </button>

                    <button
                        class="rounded-lg border
                               bg-white px-4 py-2"
                        onclick="window.print()"
                    >
                        PDF印刷
                    </button>
                </div>
            </div>

            <div class="space-y-4">
                ${rows.join('')}
            </div>

            <div class="mt-5 rounded-2xl bg-white p-5 shadow-sm">
                全回答数：${responses.length}
            </div>
        `;
    },

    respondent: function () {
        const main =
            document.getElementById(
                'main_content'
            );

        const survey =
            App.state.responseSurvey;

        main.innerHTML = `
            ${this.breadcrumb([
                '回答者情報入力'
            ])}

            <div class="mx-auto max-w-xl">
                <div
                    class="rounded-2xl bg-white
                           p-6 shadow-sm"
                >
                    <h1 class="text-2xl font-bold">
                        回答者情報入力
                    </h1>

                    <p class="mt-2 text-sm text-slate-500">
                        ${App.utils.escapeHTML(
                            survey?.title || ''
                        )}
                    </p>

                    <div class="mt-6 space-y-4">
                        <label>
                            <span class="mb-1 block text-sm">
                                氏名
                            </span>
                            <input
                                id="respondent_name"
                                class="w-full rounded-lg border px-3 py-2"
                            >
                        </label>

                        <label>
                            <span class="mb-1 block text-sm">
                                メールアドレス
                            </span>
                            <input
                                id="respondent_email"
                                type="email"
                                class="w-full rounded-lg border px-3 py-2"
                            >
                        </label>

                        <button
                            class="w-full rounded-xl
                                   bg-blue-600 px-5 py-3
                                   font-semibold text-white"
                            onclick="App.actions.startResponse()"
                        >
                            回答を開始
                        </button>
                    </div>
                </div>
            </div>
        `;
    },

    answer: function () {
        const main =
            document.getElementById(
                'main_content'
            );

        const survey =
            App.state.responseSurvey;

        App.actions.updateBranchVisibility();

        const visible =
            new Set(
                App.state.visibleQuestionIds
            );

        const questions =
            App.utils.flattenQuestions(
                survey
            ).filter(
                function (item) {
                    return visible.has(
                        item.question.id
                    );
                }
            );

        main.innerHTML = `
            ${this.breadcrumb([
                '回答',
                survey.title
            ])}

            <div class="mx-auto max-w-3xl">
                <div
                    class="mb-5 rounded-2xl bg-white
                           p-6 shadow-sm"
                >
                    <h1 class="text-2xl font-bold">
                        ${App.utils.escapeHTML(
                            survey.title
                        )}
                    </h1>
                </div>

                <div class="space-y-4">
                    ${questions.map(
                        function (item) {
                            return App.render.responseQuestion(
                                item.question
                            );
                        }
                    ).join('')}
                </div>

                <button
                    class="mt-5 w-full rounded-xl
                           bg-blue-600 px-5 py-3
                           font-semibold text-white"
                    onclick="App.actions.goAnswerConfirm()"
                >
                    回答内容を確認
                </button>
            </div>
        `;
    },

    responseQuestion: function (question) {
        const value =
            App.state.answers[question.id];

        let input = '';

        if (question.type === 'text') {
            input = `
                <textarea
                    rows="4"
                    class="w-full rounded-lg border px-3 py-2"
                    onchange="App.actions.setAnswer('${App.utils.escapeAttr(question.id)}', this.value)"
                >${App.utils.escapeHTML(value || '')}</textarea>
            `;
        }

        if (question.type === 'single') {
            input =
                (question.options || [])
                    .map(
                        function (option) {
                            return `
                                <label
                                    class="flex items-center
                                           gap-3 rounded-lg
                                           border p-3
                                           hover:bg-slate-50"
                                >
                                    <input
                                        type="radio"
                                        name="q_${App.utils.escapeAttr(question.id)}"
                                        value="${App.utils.escapeAttr(option.id)}"
                                        ${value === option.id
                                            ? 'checked'
                                            : ''}
                                        onchange="App.actions.setAnswer('${App.utils.escapeAttr(question.id)}', this.value); App.actions.updateBranchVisibility(); App.render.answer();"
                                    >

                                    <span>
                                        ${App.utils.escapeHTML(
                                            option.label
                                        )}
                                    </span>
                                </label>
                            `;
                        }
                    )
                    .join('');
        }

        if (question.type === 'multiple') {
            const current =
                Array.isArray(value)
                    ? value
                    : [];

            input =
                (question.options || [])
                    .map(
                        function (option) {
                            return `
                                <label
                                    class="flex items-center
                                           gap-3 rounded-lg
                                           border p-3"
                                >
                                    <input
                                        type="checkbox"
                                        value="${App.utils.escapeAttr(option.id)}"
                                        ${current.includes(option.id)
                                            ? 'checked'
                                            : ''}
                                        onchange="App.actions.toggleMultipleAnswer('${App.utils.escapeAttr(question.id)}','${App.utils.escapeAttr(option.id)}',this.checked)"
                                    >

                                    <span>
                                        ${App.utils.escapeHTML(
                                            option.label
                                        )}
                                    </span>
                                </label>
                            `;
                        }
                    )
                    .join('');
        }

        return `
            <section
                class="rounded-2xl bg-white
                       p-5 shadow-sm"
                data-response-question="${App.utils.escapeAttr(
                    question.id
                )}"
            >
                <div class="mb-4">
                    <div class="text-sm font-bold text-blue-700">
                        ${App.utils.escapeHTML(
                            question.number
                        )}
                        ${
                            question.required
                                ? ' ・ 必須'
                                : ''
                        }
                    </div>

                    <h2 class="mt-1 font-semibold">
                        ${App.utils.escapeHTML(
                            question.text
                        )}
                    </h2>
                </div>

                <div class="space-y-2">
                    ${input}
                </div>
            </section>
        `;
    },

    answerConfirm: function () {
        const main =
            document.getElementById(
                'main_content'
            );

        const survey =
            App.state.responseSurvey;

        const visible =
            new Set(
                App.state.visibleQuestionIds
            );

        const questions =
            App.utils.flattenQuestions(
                survey
            ).filter(
                function (item) {
                    return visible.has(
                        item.question.id
                    );
                }
            );

        main.innerHTML = `
            ${this.breadcrumb([
                '回答',
                '回答確認'
            ])}

            <div class="mx-auto max-w-3xl">
                <h1 class="mb-5 text-2xl font-bold">
                    回答確認
                </h1>

                <div class="space-y-3">
                    ${questions.map(
                        function (item) {
                            const q =
                                item.question;

                            const value =
                                App.state.answers[q.id];

                            return `
                                <div
                                    class="rounded-xl
                                           bg-white p-4
                                           shadow-sm"
                                >
                                    <div class="text-sm font-bold text-blue-700">
                                        ${App.utils.escapeHTML(q.number)}
                                    </div>

                                    <div class="font-semibold">
                                        ${App.utils.escapeHTML(q.text)}
                                    </div>

                                    <div class="mt-2 whitespace-pre-wrap text-slate-700">
                                        ${App.utils.escapeHTML(
                                            Array.isArray(value)
                                                ? value.join(', ')
                                                : value || ''
                                        )}
                                    </div>
                                </div>
                            `;
                        }
                    ).join('')}
                </div>

                <div class="mt-5 flex gap-3">
                    <button
                        class="flex-1 rounded-xl
                               border bg-white
                               px-5 py-3"
                        onclick="App.actions.backToAnswer()"
                    >
                        戻る
                    </button>

                    <button
                        class="flex-1 rounded-xl
                               bg-blue-600
                               px-5 py-3
                               font-semibold
                               text-white"
                        onclick="App.actions.submitResponse()"
                    >
                        送信する
                    </button>
                </div>
            </div>
        `;
    },

    complete: function () {
        const main =
            document.getElementById(
                'main_content'
            );

        main.innerHTML = `
            <div
                class="mx-auto mt-20 max-w-xl
                       rounded-2xl bg-white
                       p-10 text-center
                       shadow-sm"
            >
                <div
                    class="mx-auto mb-5 flex h-16
                           w-16 items-center
                           justify-center
                           rounded-full bg-emerald-100
                           text-3xl text-emerald-600"
                >
                    ✓
                </div>

                <h1 class="text-2xl font-bold">
                    回答が完了しました
                </h1>

                <p class="mt-3 text-slate-500">
                    ご回答ありがとうございました。
                </p>

                <button
                    class="mt-6 rounded-xl
                           bg-blue-600 px-5 py-3
                           font-semibold text-white"
                    onclick="App.actions.goList()"
                >
                    管理画面へ戻る
                </button>
            </div>
        `;
    }
};

App.actions = {

    goList: async function () {
        App.state.screen = 'list';
        App.state.currentSurvey = null;
        App.render.current();
    },

    newSurvey: function () {
        const survey =
            App.utils.newSurvey();

        App.state.currentSurvey =
            survey;

        App.state.screen = 'edit';

        App.actions.renumberQuestions();

        App.render.current();
    },

    editSurvey: async function (id) {
        const survey =
            App.state.surveys.find(
                function (item) {
                    return item.id === id;
                }
            );

        if (!survey) {
            App.utils.notice(
                'アンケートが見つかりません。',
                'error'
            );
            return;
        }

        App.state.currentSurvey =
            App.utils.clone(survey);

        App.state.screen = 'edit';

        App.actions.renumberQuestions();

        App.render.current();
    },

    updateSurveyField: function (
        key,
        value
    ) {
        if (!App.state.currentSurvey) {
            return;
        }

        App.state.currentSurvey[key] =
            value;
    },

    changeNumberingMode: function (
        value
    ) {
        if (!App.state.currentSurvey) {
            return;
        }

        if (!['global', 'group'].includes(value)) {
            return;
        }

        App.state.currentSurvey.numbering_mode =
            value;

        App.actions.renumberQuestions();

        App.render.editor();
    },

    changeSurveyStatus: function (
        value
    ) {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        const previous =
            survey.status;

        if (
            previous === 'active' &&
            value === 'ended'
        ) {
            if (
                !App.utils.confirm(
                    'このアンケートを終了状態に変更しますか？'
                )
            ) {
                App.render.editor();
                return;
            }
        }

        if (
            previous === 'ended' &&
            value === 'active'
        ) {
            if (
                !App.utils.confirm(
                    'このアンケートを公開状態に変更しますか？'
                )
            ) {
                App.render.editor();
                return;
            }
        }

        if (
            !['draft', 'active', 'ended']
                .includes(value)
        ) {
            return;
        }

        survey.status = value;

        App.render.editor();
    },

    saveSurvey: async function () {
        const survey =
            App.utils.clone(
                App.state.currentSurvey
            );

        App.actions.syncQuestionStructure();

        App.actions.renumberQuestions();

        App.actions.cleanupBranching();

        try {
            const result =
                await App.api.request(
                    'save_survey',
                    {
                        survey_json:
                            App.state.currentSurvey
                    }
                );

            App.state.currentSurvey =
                result.survey;

            const index =
                App.state.surveys.findIndex(
                    function (item) {
                        return item.id ===
                            result.survey.id;
                    }
                );

            if (index >= 0) {
                App.state.surveys[index] =
                    result.survey;
            } else {
                App.state.surveys.push(
                    result.survey
                );
            }

            App.utils.notice(
                'アンケートを保存しました。'
            );

            App.render.editor();
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    },

    addGroup: function () {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        survey.groups.push(
            App.utils.newGroup()
        );

        App.actions.renumberQuestions();

        App.render.editor();

        App.initSortable();
    },

    removeGroup: function (groupId) {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        if (survey.groups.length <= 1) {
            App.utils.notice(
                '最後のブロックは削除できません。',
                'error'
            );
            return;
        }

        if (
            !App.utils.confirm(
                'このブロックを削除しますか？'
            )
        ) {
            return;
        }

        survey.groups =
            survey.groups.filter(
                function (group) {
                    return group.id !== groupId;
                }
            );

        App.actions.renumberQuestions();
        App.actions.cleanupBranching();

        App.render.editor();
        App.initSortable();
    },

    updateGroupName: function (
        groupId,
        value
    ) {
        const group =
            App.state.currentSurvey.groups.find(
                function (item) {
                    return item.id === groupId;
                }
            );

        if (group) {
            group.name = value;
        }
    },

    addQuestion: function (groupId) {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        const group =
            survey.groups.find(
                function (item) {
                    return item.id === groupId;
                }
            );

        if (!group) {
            return;
        }

        group.questions.push(
            App.utils.newQuestion()
        );

        App.actions.renumberQuestions();

        App.render.editor();

        App.initSortable();
    },

    removeQuestion: function (
        groupId,
        questionId
    ) {
        const survey =
            App.state.currentSurvey;

        const group =
            survey.groups.find(
                function (item) {
                    return item.id === groupId;
                }
            );

        if (!group) {
            return;
        }

        if (
            !App.utils.confirm(
                'この質問を削除しますか？'
            )
        ) {
            return;
        }

        group.questions =
            group.questions.filter(
                function (question) {
                    return question.id !==
                        questionId;
                }
            );

        App.actions.renumberQuestions();

        App.actions.cleanupBranching();

        App.render.editor();

        App.initSortable();
    },

    updateQuestion: function (
        groupId,
        questionId,
        key,
        value
    ) {
        const question =
            App.actions.findQuestion(
                groupId,
                questionId
            );

        if (!question) {
            return;
        }

        question[key] = value;

        if (key === 'type' &&
            value !== 'single') {
            question.branching = {};
        }

        App.actions.cleanupBranching();

        if (key === 'type') {
            App.render.editor();
            App.initSortable();
        }
    },

    findQuestion: function (
        groupId,
        questionId
    ) {
        const group =
            App.state.currentSurvey.groups.find(
                function (item) {
                    return item.id === groupId;
                }
            );

        if (!group) {
            return null;
        }

        return group.questions.find(
            function (question) {
                return question.id === questionId;
            }
        ) || null;
    },

    addOption: function (
        groupId,
        questionId
    ) {
        const question =
            App.actions.findQuestion(
                groupId,
                questionId
            );

        if (!question) {
            return;
        }

        const option = {
            id: App.utils.uid('option'),
            label: '新しい選択肢'
        };

        question.options.push(option);

        if (!question.branching) {
            question.branching = {};
        }

        question.branching[option.id] =
            null;

        App.render.editor();
        App.initSortable();
    },

    removeOption: function (
        groupId,
        questionId,
        optionId
    ) {
        const question =
            App.actions.findQuestion(
                groupId,
                questionId
            );

        if (!question) {
            return;
        }

        question.options =
            question.options.filter(
                function (option) {
                    return option.id !== optionId;
                }
            );

        if (question.branching) {
            delete question.branching[
                optionId
            ];
        }

        App.render.editor();
        App.initSortable();
    },

    updateOption: function (
        groupId,
        questionId,
        optionId,
        value
    ) {
        const question =
            App.actions.findQuestion(
                groupId,
                questionId
            );

        if (!question) {
            return;
        }

        const option =
            question.options.find(
                function (item) {
                    return item.id === optionId;
                }
            );

        if (option) {
            option.label = value;
        }
    },

    changeBranch: function (
        groupId,
        questionId,
        optionId,
        value
    ) {
        const question =
            App.actions.findQuestion(
                groupId,
                questionId
            );

        if (!question) {
            return;
        }

        if (!question.branching) {
            question.branching = {};
        }

        question.branching[optionId] =
            value === ''
                ? null
                : value;
    },

    cleanupBranching: function () {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        const flat =
            App.utils.flattenQuestions(
                survey
            );

        const positions =
            new Map();

        flat.forEach(
            function (item, index) {
                positions.set(
                    item.question.id,
                    index
                );
            }
        );

        flat.forEach(
            function (item, index) {
                const q =
                    item.question;

                if (q.type !== 'single') {
                    q.branching = {};
                    return;
                }

                const clean = {};

                (q.options || []).forEach(
                    function (option) {
                        let target =
                            q.branching?.[
                                option.id
                            ] ?? null;

                        if (
                            target !== null &&
                            (
                                !positions.has(target) ||
                                positions.get(target) <= index
                            )
                        ) {
                            target = null;
                        }

                        clean[option.id] =
                            target;
                    }
                );

                q.branching = clean;
            }
        );
    },

    renumberQuestions: function () {
        const survey =
            App.state.currentSurvey;

        if (!survey) {
            return;
        }

        let global = 1;

        survey.groups.forEach(
            function (group, groupIndex) {
                group.questions.forEach(
                    function (question, questionIndex) {
                        question.number =
                            survey.numbering_mode ===
                            'group'
                                ? 'Q' +
                                    (groupIndex + 1) +
                                    '-' +
                                    (questionIndex + 1)
                                : 'Q' + global;

                        global++;
                    }
                );
            }
        );
    },

    syncQuestionStructure: function () {
        const survey =
            App.state.currentSurvey;

        const editor =
            document.getElementById(
                'question_editor'
            );

        if (!survey || !editor) {
            return;
        }

        const groups =
            Array.from(
                editor.querySelectorAll(
                    '[data-sortable-questions]'
                )
            );

        groups.forEach(
            function (container) {
                const groupId =
                    container.dataset.groupId;

                const group =
                    survey.groups.find(
                        function (item) {
                            return item.id ===
                                groupId;
                        }
                    );

                if (!group) {
                    return;
                }

                const questionIds =
                    Array.from(
                        container.querySelectorAll(
                            '[data-question-id]'
                        )
                    ).map(
                        function (element) {
                            return element.dataset.questionId;
                        }
                    );

                const map =
                    new Map(
                        group.questions.map(
                            function (question) {
                                return [
                                    question.id,
                                    question
                                ];
                            }
                        )
                    );

                const all =
                    App.utils.flattenQuestions(
                        survey
                    );

                all.forEach(
                    function (item) {
                        map.set(
                            item.question.id,
                            item.question
                        );
                    }
                );

                group.questions =
                    questionIds
                        .map(
                            function (id) {
                                return map.get(id);
                            }
                        )
                        .filter(Boolean);
            }
        );
    },

    syncGroupStructure: function () {
        const survey =
            App.state.currentSurvey;

        const editor =
            document.getElementById(
                'question_editor'
            );

        if (!survey || !editor) {
            return;
        }

        const containers =
            Array.from(
                editor.querySelectorAll(
                    '[data-sortable-groups]'
                )
            );

        const ids =
            containers
                .flatMap(
                    function (container) {
                        return Array.from(
                            container.children
                        )
                            .filter(
                                function (element) {
                                    return element.dataset.groupId;
                                }
                            )
                            .map(
                                function (element) {
                                    return element.dataset.groupId;
                                }
                            );
                    }
                );

        if (!ids.length) {
            return;
        }

        const map =
            new Map(
                survey.groups.map(
                    function (group) {
                        return [
                            group.id,
                            group
                        ];
                    }
                )
            );

        survey.groups =
            ids
                .map(
                    function (id) {
                        return map.get(id);
                    }
                )
                .filter(Boolean);
    },

    updateBranchVisibility: function () {
        const survey =
            App.state.responseSurvey;

        if (!survey) {
            return [];
        }

        const flat =
            App.utils.flattenQuestions(
                survey
            );

        const visible =
            new Set(
                flat.map(
                    function (item) {
                        return item.question.id;
                    }
                )
            );

        flat.forEach(
            function (item, index) {
                const question =
                    item.question;

                if (question.type !== 'single') {
                    return;
                }

                const answer =
                    App.state.answers[
                        question.id
                    ];

                if (
                    answer === undefined ||
                    answer === ''
                ) {
                    return;
                }

                const target =
                    question.branching?.[
                        answer
                    ] ?? null;

                if (target === null) {
                    return;
                }

                const targetIndex =
                    flat.findIndex(
                        function (targetItem) {
                            return targetItem.question.id ===
                                target;
                        }
                    );

                if (
                    targetIndex < 0 ||
                    targetIndex <= index
                ) {
                    return;
                }

                for (
                    let i = index + 1;
                    i < targetIndex;
                    i++
                ) {
                    visible.delete(
                        flat[i].question.id
                    );
                }
            }
        );

        App.state.visibleQuestionIds =
            flat
                .filter(
                    function (item) {
                        return visible.has(
                            item.question.id
                        );
                    }
                )
                .map(
                    function (item) {
                        return item.question.id;
                    }
                );

        return App.state.visibleQuestionIds;
    },

    validateResponse: function () {
        const survey =
            App.state.responseSurvey;

        if (!survey) {
            return [];
        }

        App.actions.updateBranchVisibility();

        const visible =
            new Set(
                App.state.visibleQuestionIds
            );

        const errors = [];

        App.utils.flattenQuestions(
            survey
        ).forEach(
            function (item) {
                const q =
                    item.question;

                if (!visible.has(q.id)) {
                    return;
                }

                if (!q.required) {
                    return;
                }

                const value =
                    App.state.answers[q.id];

                const empty =
                    value === undefined ||
                    value === null ||
                    value === '' ||
                    (
                        Array.isArray(value) &&
                        value.length === 0
                    );

                if (empty) {
                    errors.push(
                        q.number +
                        '「' +
                        q.text +
                        '」は必須回答です。'
                    );
                }
            }
        );

        return errors;
    },

    setAnswer: function (
        questionId,
        value
    ) {
        App.state.answers[
            questionId
        ] = value;

        localStorage.setItem(
            'survey_answers_' +
            App.state.responseSurvey.id,
            JSON.stringify(
                App.state.answers
            )
        );
    },

    toggleMultipleAnswer: function (
        questionId,
        optionId,
        checked
    ) {
        let current =
            Array.isArray(
                App.state.answers[questionId]
            )
                ? App.state.answers[questionId]
                : [];

        if (checked) {
            if (!current.includes(optionId)) {
                current.push(optionId);
            }
        } else {
            current =
                current.filter(
                    function (id) {
                        return id !== optionId;
                    }
                );
        }

        App.actions.setAnswer(
            questionId,
            current
        );
    },

    preview: function () {
        const survey =
            App.utils.clone(
                App.state.currentSurvey
            );

        App.actions.renumberQuestions();

        App.state.previewSurvey =
            survey;

        const modal =
            document.getElementById(
                'preview_modal'
            );

        const content =
            document.getElementById(
                'preview_content'
            );

        const oldResponse =
            App.state.responseSurvey;

        App.state.responseSurvey =
            survey;

        App.state.answers = {};

        App.actions.updateBranchVisibility();

        const visible =
            new Set(
                App.state.visibleQuestionIds
            );

        content.innerHTML = `
            <div class="mb-5">
                <h1 class="text-2xl font-bold">
                    ${App.utils.escapeHTML(
                        survey.title
                    )}
                </h1>

                <div class="mt-2 text-sm text-slate-500">
                    ステータス：
                    ${
                        survey.status === 'draft'
                            ? '下書き'
                            : survey.status === 'active'
                                ? '公開中'
                                : '終了'
                    }
                </div>
            </div>

            <div class="space-y-4">
                ${App.utils.flattenQuestions(
                    survey
                ).filter(
                    function (item) {
                        return visible.has(
                            item.question.id
                        );
                    }
                ).map(
                    function (item) {
                        return App.render.responseQuestion(
                            item.question
                        );
                    }
                ).join('')}
            </div>
        `;

        App.state.responseSurvey =
            oldResponse;

        modal.classList.remove('hidden');
    },

    closePreview: function () {
        document.getElementById(
            'preview_modal'
        ).classList.add('hidden');
    },

    showSettings: async function () {
        App.state.screen =
            'settings';

        App.render.current();
    },

    saveSettings: async function () {
        const get = function (id) {
            const element =
                document.getElementById(id);

            return element
                ? element.value
                : '';
        };

        const settings = {
            subdomain:
                get('setting_subdomain'),

            login_name:
                get('setting_login_name'),

            password:
                get('setting_password'),

            app_id:
                Number(
                    get('setting_app_id') || 0
                ),

            ssl_verify:
                Boolean(
                    document.getElementById(
                        'setting_ssl_verify'
                    )?.checked
                ),

            proxy:
                get('setting_proxy'),

            field_company:
                get('field_company'),

            field_name:
                get('field_name'),

            field_email:
                get('field_email'),

            field_department:
                get('field_department'),

            field_phone:
                get('field_phone'),

            field_address:
                get('field_address'),

            smtp_server:
                get('smtp_server'),

            smtp_port:
                Number(
                    get('smtp_port') || 587
                ),

            smtp_encryption:
                get('smtp_encryption'),

            smtp_auth:
                Boolean(
                    document.getElementById(
                        'smtp_auth'
                    )?.checked
                ),

            smtp_username:
                get('smtp_username'),

            smtp_password:
                get('smtp_password'),

            smtp_from_email:
                get('smtp_from_email'),

            smtp_from_name:
                get('smtp_from_name'),

            smtp_timeout:
                Number(
                    get('smtp_timeout') || 15
                )
        };

        document.getElementById(
            'settings_json'
        ).value = JSON.stringify(settings);

        try {
            const result =
                await App.api.request(
                    'save_settings',
                    {
                        settings_json: settings
                    }
                );

            App.state.settings =
                result.settings;

            App.utils.notice(
                '設定を保存しました。'
            );

            App.render.settings();
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    },

    connectKintone: async function () {
        try {
            await App.actions.saveSettings();

            const result =
                await App.api.request(
                    'connect_kintone'
                );

            App.utils.notice(
                result.ok
                    ? 'キントーンへの接続に成功しました。'
                    : 'キントーンへの接続に失敗しました。'
            );
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    },

    fetchKintoneFields: async function () {
        try {
            await App.actions.saveSettings();

            const result =
                await App.api.request(
                    'fetch_kintone_fields'
                );

            App.state.fields =
                result.fields || [];

            const message =
                document.getElementById(
                    'field_message'
                );

            if (message) {
                message.innerHTML = `
                    <div class="rounded-lg bg-emerald-50
                                p-3 text-emerald-700">
                        ${result.fields.length} 件のフィールドを取得しました。
                    </div>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b">
                                    <th class="p-2 text-left">
                                        label
                                    </th>
                                    <th class="p-2 text-left">
                                        code
                                    </th>
                                    <th class="p-2 text-left">
                                        type
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                ${result.fields.map(
                                    function (field) {
                                        return `
                                            <tr class="border-b">
                                                <td class="p-2">
                                                    ${App.utils.escapeHTML(field.label)}
                                                </td>
                                                <td class="p-2 font-mono">
                                                    ${App.utils.escapeHTML(field.code)}
                                                </td>
                                                <td class="p-2">
                                                    ${App.utils.escapeHTML(field.type)}
                                                </td>
                                            </tr>
                                        `;
                                    }
                                ).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    },

    syncCustomers: async function () {
        try {
            await App.actions.saveSettings();

            const result =
                await App.api.request(
                    'sync_customers'
                );

            App.state.customers =
                result.customers || [];

            App.utils.notice(
                result.count +
                '件の顧客データを同期しました。'
            );
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    },

    testSMTPConnection: async function () {
        try {
            await App.actions.saveSettings();

            await App.api.request(
                'test_smtp_connection'
            );

            App.utils.notice(
                'SMTP接続に成功しました。'
            );
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    },

    testSMTPMail: async function () {
        const recipient =
            window.prompt(
                'テスト送信先メールアドレスを入力してください。'
            );

        if (!recipient) {
            return;
        }

        try {
            await App.actions.saveSettings();

            await App.api.request(
                'test_smtp_mail',
                {
                    recipient: recipient
                }
            );

            App.utils.notice(
                'テストメールを送信しました。'
            );
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    },

    duplicateSurvey: async function (id) {
        try {
            const result =
                await App.api.request(
                    'duplicate_survey',
                    {
                        survey_id: id
                    }
                );

            App.state.surveys.push(
                result.survey
            );

            App.utils.notice(
                'アンケートを複製しました。'
            );

            App.render.list();
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    },

    deleteSurvey: async function (id) {
        if (
            !App.utils.confirm(
                'この下書きアンケートを削除しますか？'
            )
        ) {
            return;
        }

        try {
            await App.api.request(
                'delete_survey',
                {
                    survey_id: id
                }
            );

            App.state.surveys =
                App.state.surveys.filter(
                    function (survey) {
                        return survey.id !== id;
                    }
                );

            App.utils.notice(
                'アンケートを削除しました。'
            );

            App.render.list();
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    },

    setKeyword: function (value) {
        App.state.keyword = value;
        App.render.list();
    },

    setStatusFilter: function (value) {
        App.state.statusFilter = value;
        App.render.list();
    },

    setSort: function (value) {
        App.state.sort = value;
        App.render.list();
    },

    showSend: function (id) {
        const survey =
            App.state.surveys.find(
                function (item) {
                    return item.id === id;
                }
            );

        if (!survey) {
            return;
        }

        App.state.currentSurvey =
            App.utils.clone(survey);

        App.state.screen = 'send';

        App.render.current();
    },

    filterCustomers: function (value) {
        const keyword =
            String(value || '')
                .toLowerCase();

        document
            .querySelectorAll(
                '[data-customer-row]'
            )
            .forEach(
                function (row) {
                    row.classList.toggle(
                        'hidden',
                        !row.textContent
                            .toLowerCase()
                            .includes(keyword)
                    );
                }
            );
    },

    toggleAllCustomers: function (
        checked
    ) {
        document
            .querySelectorAll(
                '[data-customer-id]'
            )
            .forEach(
                function (checkbox) {
                    checkbox.checked =
                        checked;
                }
            );
    },

    sendMail: async function () {
        const ids =
            Array.from(
                document.querySelectorAll(
                    '[data-customer-id]:checked'
                )
            ).map(
                function (element) {
                    return element.dataset.customerId;
                }
            );

        if (!ids.length) {
            App.utils.notice(
                '送信先を選択してください。',
                'error'
            );
            return;
        }

        const subject =
            document.getElementById(
                'mail_subject'
            ).value;

        const body =
            document.getElementById(
                'mail_body'
            ).value;

        const template =
            document.getElementById(
                'template_type'
            ).value;

        if (
            !App.utils.confirm(
                ids.length +
                '件へメールを送信します。よろしいですか？'
            )
        ) {
            return;
        }

        try {
            const result =
                await App.api.request(
                    'send_mail',
                    {
                        recipient_ids: ids,
                        mail_subject: subject,
                        mail_body: body,
                        template_type: template
                    }
                );

            App.utils.notice(
                '送信完了：' +
                result.sent +
                '件 / 失敗：' +
                result.failed +
                '件'
            );
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    },

    showSummary: function (id) {
        const survey =
            App.state.surveys.find(
                function (item) {
                    return item.id === id;
                }
            );

        if (!survey) {
            return;
        }

        App.state.currentSurvey =
            App.utils.clone(survey);

        App.state.screen =
            'summary';

        App.render.current();
    },

    exportCSV: function () {
        const survey =
            App.state.currentSurvey;

        const responses =
            App.state.responses.filter(
                function (response) {
                    return response.survey_id ===
                        survey.id &&
                        !response.deleted;
                }
            );

        const questions =
            App.utils.flattenQuestions(
                survey
            ).map(
                function (item) {
                    return item.question;
                }
            );

        const header = [
            'response_id',
            'created_at',
            ...questions.map(
                function (q) {
                    return q.number + ':' + q.text;
                }
            )
        ];

        const rows = responses.map(
            function (response) {
                return [
                    response.id,
                    response.created_at,
                    ...questions.map(
                        function (q) {
                            const value =
                                response.answers?.[
                                    q.id
                                ];

                            return Array.isArray(value)
                                ? value.join(' / ')
                                : value ?? '';
                        }
                    )
                ];
            }
        );

        const csv = [
            header,
            ...rows
        ].map(
            function (row) {
                return row.map(
                    function (value) {
                        return '"' +
                            String(value)
                                .replaceAll(
                                    '"',
                                    '""'
                                ) +
                            '"';
                    }
                ).join(',');
            }
        ).join('\r\n');

        const blob =
            new Blob(
                ['\uFEFF' + csv],
                {
                    type:
                        'text/csv;charset=utf-8'
                }
            );

        const url =
            URL.createObjectURL(blob);

        const anchor =
            document.createElement('a');

        anchor.href = url;
        anchor.download =
            'survey_' +
            survey.id +
            '_responses.csv';

        anchor.click();

        URL.revokeObjectURL(url);
    },

    startResponse: function () {
        const survey =
            App.state.responseSurvey;

        App.state.respondent = {
            name:
                document.getElementById(
                    'respondent_name'
                ).value,

            email:
                document.getElementById(
                    'respondent_email'
                ).value
        };

        const saved =
            localStorage.getItem(
                'survey_answers_' +
                survey.id
            );

        if (saved) {
            try {
                App.state.answers =
                    JSON.parse(saved);
            } catch (error) {
                App.state.answers = {};
            }
        } else {
            App.state.answers = {};
        }

        App.state.screen = 'answer';

        App.render.current();
    },

    goAnswerConfirm: function () {
        const errors =
            App.actions.validateResponse();

        if (errors.length) {
            App.utils.notice(
                errors.join('\n'),
                'error'
            );
            return;
        }

        App.state.screen = 'confirm';

        App.render.current();
    },

    backToAnswer: function () {
        App.state.screen = 'answer';
        App.render.current();
    },

    submitResponse: async function () {
        const survey =
            App.state.responseSurvey;

        const errors =
            App.actions.validateResponse();

        if (errors.length) {
            App.utils.notice(
                errors.join('\n'),
                'error'
            );
            App.state.screen = 'answer';
            App.render.current();
            return;
        }

        try {
            await App.api.request(
                'save_response',
                {
                    response_json: {
                        survey_id: survey.id,
                        respondent:
                            App.state.respondent ||
                            {},
                        answers:
                            App.state.answers
                    }
                }
            );

            localStorage.removeItem(
                'survey_answers_' +
                survey.id
            );

            App.state.screen =
                'complete';

            App.render.current();
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    },

    showResponse: function (responseId) {
        const response =
            App.state.responses.find(
                function (item) {
                    return item.id ===
                        responseId;
                }
            );

        if (!response) {
            return;
        }

        document.getElementById(
            'response_detail'
        ).innerHTML = `
            <div class="space-y-3">
                <div>
                    <strong>回答ID</strong>
                    <div class="font-mono text-sm">
                        ${App.utils.escapeHTML(
                            response.id
                        )}
                    </div>
                </div>

                <div>
                    <strong>回答日時</strong>
                    <div>
                        ${App.utils.escapeHTML(
                            response.created_at
                        )}
                    </div>
                </div>

                <pre
                    class="overflow-auto rounded-lg
                           bg-slate-50 p-4 text-xs"
                >${App.utils.escapeHTML(
                    JSON.stringify(
                        response.answers || {},
                        null,
                        2
                    )
                )}</pre>
            </div>
        `;

        document.getElementById(
            'response_modal'
        ).classList.remove('hidden');
    },

    closeResponse: function () {
        document.getElementById(
            'response_modal'
        ).classList.add('hidden');
    },

    logout: async function () {
        try {
            await App.api.request(
                'logout'
            );

            window.location.reload();
        } catch (error) {
            App.utils.notice(
                error.message,
                'error'
            );
        }
    }
};

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        { once: true }
    );
} else {
    App.init();
}
</script>

</body>
</html>
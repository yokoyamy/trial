<?php
declare(strict_types=1);

/*
============================================================
アンケート管理システム
Apache 2.4 / PHP 8.5
単一ファイル構成
============================================================
固定名称:
survey_storage_directory
survey_storage_file
survey_admin_session_v1

PHP定数:
SURVEY_STORAGE_DIRECTORY
SURVEY_STORAGE_FILE
SURVEY_ADMIN_SESSION
============================================================
*/

const SURVEY_STORAGE_DIRECTORY = 'survey_storage_directory';
const SURVEY_STORAGE_FILE = 'survey_storage_file';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header_remove('X-Powered-By');

function storageDir(): string
{
    return __DIR__ . '/survey_storage';
}

function storageFile(): string
{
    return storageDir() . '/survey_data.json';
}

function initialData(): array
{
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [
            'kintone' => [],
            'smtp' => [],
        ],
        'mail_logs' => [],
    ];
}

function loadData(): array
{
    $file = storageFile();

    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
    }

    if (!is_file($file)) {
        $data = initialData();
        saveData($data);
        return $data;
    }

    $json = @file_get_contents($file);

    if ($json === false || trim($json) === '') {
        $data = initialData();
        saveData($data);
        return $data;
    }

    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $data = initialData();
        saveData($data);
        return $data;
    }

    if (!is_array($data)) {
        $data = initialData();
    }

    foreach (initialData() as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    if (!is_array($data['settings'])) {
        $data['settings'] = [];
    }

    if (!isset($data['settings']['kintone']) ||
        !is_array($data['settings']['kintone'])) {
        $data['settings']['kintone'] = [];
    }

    if (!isset($data['settings']['smtp']) ||
        !is_array($data['settings']['smtp'])) {
        $data['settings']['smtp'] = [];
    }

    return $data;
}

function saveData(array $data): bool
{
    $dir = storageDir();

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

    $tmp = $dir . '/survey_data.' . bin2hex(random_bytes(8)) . '.tmp';

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    try {
        json_decode(
            (string)file_get_contents($tmp),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, storageFile())) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function jsonResponse(array $data, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requireCsrf(): void
{
    if (!hash_equals(
        csrfToken(),
        (string)($_POST['csrf_token'] ?? '')
    )) {
        jsonResponse([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。',
        ], 403);
    }
}

function newId(string $prefix): string
{
    return $prefix . '_' .
        date('YmdHis') . '_' .
        bin2hex(random_bytes(6));
}

function safeMessage(string $message): string
{
    $message = preg_replace(
        '/(?:password|passwd|token|authorization|cookie|secret|auth)[^,\s]*/i',
        '[秘匿情報]',
        $message
    ) ?? $message;

    return mb_substr($message, 0, 1000);
}

function normalizeSurvey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? newId('survey'));
    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['start_at'] = (string)($survey['start_at'] ?? '');
    $survey['end_at'] = (string)($survey['end_at'] ?? '');

    $survey['status'] = in_array(
        $survey['status'] ?? 'draft',
        ['draft', 'active', 'ended'],
        true
    ) ? $survey['status'] : 'draft';

    $survey['numbering_mode'] = in_array(
        $survey['numbering_mode'] ?? 'global',
        ['global', 'group'],
        true
    ) ? $survey['numbering_mode'] : 'global';

    $survey['general_response_enabled'] =
        !empty($survey['general_response_enabled']);

    $survey['deleted'] = !empty($survey['deleted']);
    $survey['created_at'] =
        (string)($survey['created_at'] ?? date('c'));
    $survey['updated_at'] = date('c');
    $survey['public_token'] =
        (string)($survey['public_token'] ?? bin2hex(random_bytes(24)));

    if (!isset($survey['groups']) || !is_array($survey['groups'])) {
        $survey['groups'] = [];
    }

    foreach ($survey['groups'] as &$group) {
        $group['id'] =
            (string)($group['id'] ?? newId('group'));
        $group['name'] =
            (string)($group['name'] ?? 'グループ');

        if (!isset($group['questions']) ||
            !is_array($group['questions'])) {
            $group['questions'] = [];
        }

        foreach ($group['questions'] as &$question) {
            $question['id'] =
                (string)($question['id'] ?? newId('question'));
            $question['text'] =
                (string)($question['text'] ?? '');

            $question['type'] = in_array(
                $question['type'] ?? 'text',
                ['single', 'multiple', 'text'],
                true
            ) ? $question['type'] : 'text';

            $question['required'] =
                !empty($question['required']);

            $question['other_enabled'] =
                !empty($question['other_enabled']);

            $question['options'] =
                isset($question['options']) &&
                is_array($question['options'])
                    ? array_values($question['options'])
                    : [];

            $question['branching'] =
                isset($question['branching']) &&
                is_array($question['branching'])
                    ? $question['branching']
                    : [];
        }

        unset($question);
    }

    unset($group);

    renumberSurvey($survey);

    return $survey;
}

function renumberSurvey(array &$survey): void
{
    $global = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        foreach ($group['questions'] as $qi => &$question) {
            $question['number'] =
                $survey['numbering_mode'] === 'group'
                    ? 'Q' . ($gi + 1) . '-' . ($qi + 1)
                    : 'Q' . $global;

            $global++;
        }

        unset($question);
    }

    unset($group);
}

function findSurvey(array &$data, string $id): ?array
{
    foreach ($data['surveys'] as $index => $survey) {
        if ((string)$survey['id'] === $id) {
            return [
                'index' => $index,
                'survey' => $survey,
            ];
        }
    }

    return null;
}

function settingInput(array $source, string $key): string
{
    return (string)($source[$key] ?? '');
}

function validateKintone(array $input): array
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
    ];

    $out = [];

    foreach ($allowed as $key) {
        $out[$key] = $input[$key] ?? '';
    }

    $out['ssl_verify'] = !empty($input['ssl_verify']);

    if ($out['subdomain'] !== '' &&
        !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            (string)$out['subdomain']
        )) {
        throw new RuntimeException(
            'サブドメインはkintoneのサブドメイン名を入力してください。'
        );
    }

    if ($out['app_id'] !== '' &&
        !ctype_digit((string)$out['app_id'])) {
        throw new RuntimeException(
            '顧客管理アプリIDは数値で入力してください。'
        );
    }

    if ($out['proxy'] !== '' &&
        !filter_var($out['proxy'], FILTER_VALIDATE_URL)) {
        throw new RuntimeException(
            'Proxyは有効なURL形式で入力してください。'
        );
    }

    return $out;
}

function validateSmtp(array $input): array
{
    $allowed = [
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

    $out = [];

    foreach ($allowed as $key) {
        $out[$key] = $input[$key] ?? '';
    }

    $out['smtp_port'] =
        (int)($input['smtp_port'] ?? 587);

    $out['smtp_timeout'] =
        max(1, (int)($input['smtp_timeout'] ?? 10));

    if (!in_array(
        $out['smtp_encryption'],
        ['none', 'starttls', 'ssl'],
        true
    )) {
        throw new RuntimeException(
            'SMTP暗号化方式が不正です。'
        );
    }

    if ($out['smtp_port'] < 1 ||
        $out['smtp_port'] > 65535) {
        throw new RuntimeException(
            'SMTPポートが不正です。'
        );
    }

    if ($out['smtp_from_email'] !== '' &&
        !filter_var(
            $out['smtp_from_email'],
            FILTER_VALIDATE_EMAIL
        )) {
        throw new RuntimeException(
            '送信元メールアドレスが不正です。'
        );
    }

    return $out;
}

function mergeSecret(
    array $old,
    array $new,
    string $passwordKey
): array {
    if (($new[$passwordKey] ?? '') === '') {
        if (isset($old[$passwordKey])) {
            $new[$passwordKey] = $old[$passwordKey];
        }
    }

    return $new;
}

function kintoneRequest(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $subdomain = trim((string)($config['subdomain'] ?? ''));
    $appId = (string)($config['app_id'] ?? '');
    $login = (string)($config['login_name'] ?? '');
    $password = (string)($config['password'] ?? '');

    if ($subdomain === '' ||
        $appId === '' ||
        $login === '' ||
        $password === '') {
        throw new RuntimeException(
            'kintone設定が不足しています。サブドメイン、ログイン名、パスワード、アプリIDを確認してください。'
        );
    }

    $url =
        'https://' .
        $subdomain .
        '.cybozu.com/k/v1/' .
        ltrim($path, '/');

    $headers = [
        'Content-Type: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password),
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER =>
            !empty($config['ssl_verify']),
        CURLOPT_SSL_VERIFYHOST =>
            !empty($config['ssl_verify']) ? 2 : 0,
    ]);

    if (!empty($config['proxy'])) {
        curl_setopt($ch, CURLOPT_PROXY, $config['proxy']);
    }

    if ($body !== null) {
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        $type = 'connection';

        if ($errno === CURLE_COULDNT_RESOLVE_HOST) {
            $type = 'dns';
        } elseif ($errno === CURLE_OPERATION_TIMEDOUT) {
            $type = 'timeout';
        } elseif ($errno === CURLE_SSL_CONNECT_ERROR) {
            $type = 'tls';
        } elseif ($errno === CURLE_COULDNT_CONNECT) {
            $type = 'connection';
        }

        throw new RuntimeException(
            $type . '|' .
            safeMessage($error)
        );
    }

    $decoded = [];

    if ($response !== '') {
        try {
            $decoded = json_decode(
                $response,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            $decoded = [];
        }
    }

    if ($status >= 400) {
        $type =
            $status === 401
                ? 'authentication'
                : ($status === 403
                    ? 'authorization'
                    : ($status >= 500
                        ? 'http_5xx'
                        : 'http_4xx'));

        $message =
            (string)($decoded['message'] ??
            'kintone APIがエラーを返しました。');

        throw new RuntimeException(
            $type . '|' .
            $status . '|' .
            safeMessage($message)
        );
    }

    return [
        'status' => $status,
        'data' => $decoded,
    ];
}

function parseNetworkError(string $message): array
{
    $parts = explode('|', $message, 3);

    return [
        'error_type' => $parts[0] ?? 'connection',
        'http_status' =>
            isset($parts[1]) && ctype_digit($parts[1])
                ? (int)$parts[1]
                : null,
        'message' =>
            safeMessage($parts[count($parts) - 1] ?? $message),
    ];
}

function smtpRead($socket): array
{
    $line = '';

    while (!feof($socket)) {
        $part = fgets($socket, 8192);

        if ($part === false) {
            break;
        }

        $line .= $part;

        if (preg_match('/^\d{3} /', $part)) {
            break;
        }
    }

    $code =
        preg_match('/^(\d{3})/', trim($line), $m)
            ? (int)$m[1]
            : 0;

    return [
        'code' => $code,
        'message' => trim($line),
    ];
}

function smtpCommand(
    $socket,
    string $command,
    array $expected = []
): array {
    fwrite($socket, $command . "\r\n");

    $response = smtpRead($socket);

    if ($expected &&
        !in_array($response['code'], $expected, true)) {
        throw new RuntimeException(
            'smtp_response|' .
            $response['code'] .
            '|' .
            safeMessage($response['message'])
        );
    }

    return $response;
}

function smtpConnect(array $config, bool $authenticate = true)
{
    $server = (string)($config['smtp_server'] ?? '');
    $port = (int)($config['smtp_port'] ?? 587);
    $encryption = (string)($config['smtp_encryption'] ?? 'starttls');
    $timeout = max(1, (int)($config['smtp_timeout'] ?? 10));

    if ($server === '') {
        throw new RuntimeException(
            'configuration|SMTPサーバが設定されていません。'
        );
    }

    $transport =
        $encryption === 'ssl'
            ? 'ssl://'
            : 'tcp://';

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $transport . $server . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException(
            'connection|' .
            safeMessage($errstr ?: 'SMTPサーバへ接続できません。')
        );
    }

    stream_set_timeout($socket, $timeout);

    $hello = smtpRead($socket);

    if ($hello['code'] < 200 || $hello['code'] >= 400) {
        fclose($socket);

        throw new RuntimeException(
            'smtp_response|' .
            $hello['code'] .
            '|' .
            safeMessage($hello['message'])
        );
    }

    smtpCommand(
        $socket,
        'EHLO localhost',
        [250]
    );

    if ($encryption === 'starttls') {
        smtpCommand(
            $socket,
            'STARTTLS',
            [220]
        );

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            throw new RuntimeException(
                'tls|SMTP TLS接続に失敗しました。'
            );
        }

        smtpCommand(
            $socket,
            'EHLO localhost',
            [250]
        );
    }

    if ($authenticate &&
        !empty($config['smtp_auth'])) {
        $username = (string)($config['smtp_username'] ?? '');
        $password = (string)($config['smtp_password'] ?? '');

        if ($username === '' || $password === '') {
            fclose($socket);

            throw new RuntimeException(
                'authentication|SMTP認証情報が不足しています。'
            );
        }

        smtpCommand(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtpCommand(
            $socket,
            base64_encode($username),
            [334]
        );

        smtpCommand(
            $socket,
            base64_encode($password),
            [235]
        );
    }

    return $socket;
}

function smtpSend(
    array $config,
    string $to,
    string $subject,
    string $body
): array {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            'configuration|テスト宛先メールアドレスが不正です。'
        );
    }

    $socket = smtpConnect($config, true);

    $from = (string)$config['smtp_from_email'];

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        fclose($socket);

        throw new RuntimeException(
            'configuration|送信元メールアドレスが不正です。'
        );
    }

    smtpCommand(
        $socket,
        'MAIL FROM:<' . $from . '>',
        [250]
    );

    smtpCommand(
        $socket,
        'RCPT TO:<' . $to . '>',
        [250, 251]
    );

    smtpCommand(
        $socket,
        'DATA',
        [354]
    );

    $fromName = (string)($config['smtp_from_name'] ?? '');

    $fromHeader = $fromName !== ''
        ? '=?UTF-8?B?' .
            base64_encode($fromName) .
            '?= <' . $from . '>'
        : $from;

    $encodedSubject =
        '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $mail =
        'From: ' . $fromHeader . "\r\n" .
        'To: ' . $to . "\r\n" .
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

    $mail = str_replace("\n", "\r\n", $mail);
    $mail = preg_replace('/^\./m', '..', $mail);

    fwrite($socket, $mail . "\r\n.\r\n");

    $response = smtpRead($socket);

    if ($response['code'] < 200 ||
        $response['code'] >= 300) {
        fclose($socket);

        throw new RuntimeException(
            'smtp_response|' .
            $response['code'] .
            '|' .
            safeMessage($response['message'])
        );
    }

    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);

    return $response;
}

function settingsForClient(array $settings): array
{
    $k = $settings['kintone'] ?? [];
    $s = $settings['smtp'] ?? [];

    if (isset($k['password'])) {
        unset($k['password']);
    }

    if (isset($s['smtp_password'])) {
        unset($s['smtp_password']);
    }

    $k['password_configured'] =
        !empty($settings['kintone']['password']);

    $s['password_configured'] =
        !empty($settings['smtp']['smtp_password']);

    return [
        'kintone' => $k,
        'smtp' => $s,
    ];
}

function validateStatusTransition(
    string $old,
    string $new
): bool {
    return in_array($new, ['draft', 'active', 'ended'], true);
}

/*
============================================================
API
============================================================
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])) {

    requireCsrf();

    $action = (string)$_POST['action'];
    $data = loadData();

    try {
        switch ($action) {

            case 'list_surveys':
                $surveys = [];

                foreach ($data['surveys'] as $survey) {
                    if (!empty($survey['deleted'])) {
                        continue;
                    }

                    $survey['response_count'] = 0;

                    foreach ($data['responses'] as $response) {
                        if (($response['survey_id'] ?? '') ===
                            ($survey['id'] ?? '') &&
                            empty($response['deleted'])) {
                            $survey['response_count']++;
                        }
                    }

                    $surveys[] = $survey;
                }

                jsonResponse([
                    'ok' => true,
                    'surveys' => $surveys,
                ]);
                break;

            case 'get_data':
                jsonResponse([
                    'ok' => true,
                    'surveys' => $data['surveys'],
                    'responses' => $data['responses'],
                    'customers' => $data['customers'],
                    'settings' => settingsForClient(
                        $data['settings']
                    ),
                    'mail_logs' => $data['mail_logs'],
                ]);
                break;

            case 'save_survey':
                $survey = json_decode(
                    (string)($_POST['survey_json'] ?? ''),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (!is_array($survey)) {
                    throw new RuntimeException(
                        'アンケートデータが不正です。'
                    );
                }

                $survey = normalizeSurvey($survey);

                if (!validateStatusTransition(
                    'draft',
                    $survey['status']
                )) {
                    throw new RuntimeException(
                        'ステータスが不正です。'
                    );
                }

                $found = findSurvey(
                    $data,
                    (string)$survey['id']
                );

                if ($found !== null) {
                    $old = $found['survey'];

                    if (!validateStatusTransition(
                        $old['status'],
                        $survey['status']
                    )) {
                        throw new RuntimeException(
                            'ステータスが不正です。'
                        );
                    }

                    $data['surveys'][$found['index']] = $survey;
                } else {
                    $data['surveys'][] = $survey;
                }

                if (!saveData($data)) {
                    throw new RuntimeException(
                        'アンケート保存に失敗しました。JSONファイルを確認してください。'
                    );
                }

                jsonResponse([
                    'ok' => true,
                    'survey' => $survey,
                ]);
                break;

            case 'duplicate_survey':
                $found = findSurvey(
                    $data,
                    (string)($_POST['survey_id'] ?? '')
                );

                if ($found === null) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $copy = $found['survey'];

                $copy['id'] = newId('survey');
                $copy['title'] .= '（複製）';
                $copy['status'] = 'draft';
                $copy['created_at'] = date('c');
                $copy['updated_at'] = date('c');
                $copy['deleted'] = false;
                $copy['public_token'] =
                    bin2hex(random_bytes(24));

                foreach ($copy['groups'] as &$group) {
                    $group['id'] = newId('group');

                    foreach ($group['questions'] as &$question) {
                        $oldId = $question['id'];
                        $question['id'] = newId('question');

                        if (!empty($question['branching'])) {
                            $question['branching'] = [];
                        }
                    }

                    unset($question);
                }

                unset($group);

                renumberSurvey($copy);
                $data['surveys'][] = $copy;

                if (!saveData($data)) {
                    throw new RuntimeException(
                        '複製データを保存できません。'
                    );
                }

                jsonResponse([
                    'ok' => true,
                    'survey' => $copy,
                ]);
                break;

            case 'delete_survey':
                $found = findSurvey(
                    $data,
                    (string)($_POST['survey_id'] ?? '')
                );

                if ($found === null) {
                    throw new RuntimeException(
                        'アンケートが見つかりません。'
                    );
                }

                $data['surveys'][$found['index']]['deleted'] = true;
                $data['surveys'][$found['index']]['updated_at'] =
                    date('c');

                if (!saveData($data)) {
                    throw new RuntimeException(
                        'アンケート削除に失敗しました。'
                    );
                }

                jsonResponse(['ok' => true]);
                break;

            case 'save_kintone_settings':
                $input = [];

                foreach ([
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
                ] as $key) {
                    $input[$key] = $_POST[$key] ?? '';
                }

                $old = $data['settings']['kintone'] ?? [];
                $new = validateKintone($input);
                $new = mergeSecret(
                    $old,
                    $new,
                    'password'
                );

                $data['settings']['kintone'] = $new;

                if (!saveData($data)) {
                    throw new RuntimeException(
                        'キントーン設定を保存できません。ファイル権限を確認してください。'
                    );
                }

                jsonResponse([
                    'ok' => true,
                    'message' =>
                        'キントーン設定を保存しました。',
                ]);
                break;

            case 'save_smtp_settings':
                $input = [];

                foreach ([
                    'smtp_server',
                    'smtp_port',
                    'smtp_encryption',
                    'smtp_auth',
                    'smtp_username',
                    'smtp_password',
                    'smtp_from_email',
                    'smtp_from_name',
                    'smtp_timeout',
                ] as $key) {
                    $input[$key] = $_POST[$key] ?? '';
                }

                $old = $data['settings']['smtp'] ?? [];
                $new = validateSmtp($input);
                $new = mergeSecret(
                    $old,
                    $new,
                    'smtp_password'
                );

                $data['settings']['smtp'] = $new;

                if (!saveData($data)) {
                    throw new RuntimeException(
                        'SMTP設定を保存できません。ファイル権限を確認してください。'
                    );
                }

                jsonResponse([
                    'ok' => true,
                    'message' =>
                        'SMTP設定を保存しました。',
                ]);
                break;

            case 'connect_kintone':
                $config = $data['settings']['kintone'] ?? [];

                try {
                    $result = kintoneRequest(
                        $config,
                        'GET',
                        'app/form/fields.json?app=' .
                        rawurlencode(
                            (string)$config['app_id']
                        )
                    );

                    jsonResponse([
                        'ok' => true,
                        'message' =>
                            'キントーンへの接続に成功しました。',
                        'http_status' => $result['status'],
                        'error_type' => null,
                        'check_items' => [],
                    ]);
                } catch (Throwable $e) {
                    $error = parseNetworkError($e->getMessage());

                    jsonResponse([
                        'ok' => false,
                        'message' =>
                            $error['message'],
                        'http_status' =>
                            $error['http_status'],
                        'error_type' =>
                            $error['error_type'],
                        'check_items' => [
                            'サブドメイン',
                            'ログイン名',
                            'パスワード',
                            'kintone側の認証設定',
                            'Proxy / TLS設定',
                        ],
                    ], 400);
                }
                break;

            case 'fetch_kintone_fields':
                $config = $data['settings']['kintone'] ?? [];

                try {
                    $result = kintoneRequest(
                        $config,
                        'GET',
                        'app/form/fields.json?app=' .
                        rawurlencode(
                            (string)$config['app_id']
                        )
                    );

                    $fields = [];

                    foreach (
                        ($result['data']['properties'] ?? [])
                        as $field
                    ) {
                        $fields[] = [
                            'label' =>
                                (string)($field['label'] ?? ''),
                            'code' =>
                                (string)($field['code'] ?? ''),
                            'type' =>
                                (string)($field['type'] ?? ''),
                        ];
                    }

                    jsonResponse([
                        'ok' => true,
                        'message' =>
                            'フィールドを取得しました。',
                        'http_status' => $result['status'],
                        'fields' => $fields,
                    ]);
                } catch (Throwable $e) {
                    $error = parseNetworkError($e->getMessage());

                    jsonResponse([
                        'ok' => false,
                        'message' => $error['message'],
                        'http_status' =>
                            $error['http_status'],
                        'error_type' =>
                            $error['error_type'],
                        'check_items' => [
                            '顧客管理アプリID',
                            'kintone権限',
                            'フィールド設定',
                        ],
                    ], 400);
                }
                break;

            case 'sync_customers':
                $config = $data['settings']['kintone'] ?? [];

                try {
                    $result = kintoneRequest(
                        $config,
                        'GET',
                        'records.json?app=' .
                        rawurlencode(
                            (string)$config['app_id']
                        ) .
                        '&query=' .
                        rawurlencode('order by $id asc limit 500')
                    );

                    $records =
                        $result['data']['records'] ?? [];

                    $inserted = 0;
                    $updated = 0;
                    $skipped = 0;
                    $errors = 0;

                    foreach ($records as $record) {
                        $customer = [
                            'id' =>
                                newId('customer'),
                            'kintone_id' =>
                                (string)($record['$id']['value'] ?? ''),
                            'company' =>
                                (string)($record[
                                    $config['field_company'] ?? ''
                                ]['value'] ?? ''),
                            'name' =>
                                (string)($record[
                                    $config['field_name'] ?? ''
                                ]['value'] ?? ''),
                            'email' =>
                                (string)($record[
                                    $config['field_email'] ?? ''
                                ]['value'] ?? ''),
                            'department' =>
                                (string)($record[
                                    $config['field_department'] ?? ''
                                ]['value'] ?? ''),
                            'phone' =>
                                (string)($record[
                                    $config['field_phone'] ?? ''
                                ]['value'] ?? ''),
                            'address' =>
                                (string)($record[
                                    $config['field_address'] ?? ''
                                ]['value'] ?? ''),
                            'updated_at' => date('c'),
                        ];

                        if ($customer['kintone_id'] === '') {
                            $skipped++;
                            continue;
                        }

                        $found = false;

                        foreach ($data['customers'] as $i => $old) {
                            if (($old['kintone_id'] ?? '') ===
                                $customer['kintone_id']) {
                                $customer['id'] =
                                    $old['id'] ??
                                    $customer['id'];

                                $data['customers'][$i] =
                                    $customer;

                                $updated++;
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                            $data['customers'][] =
                                $customer;
                            $inserted++;
                        }
                    }

                    if (!saveData($data)) {
                        throw new RuntimeException(
                            '顧客データの保存に失敗しました。'
                        );
                    }

                    jsonResponse([
                        'ok' => true,
                        'message' =>
                            '顧客データを同期しました。',
                        'count' => count($records),
                        'inserted' => $inserted,
                        'updated' => $updated,
                        'skipped' => $skipped,
                        'errors' => $errors,
                    ]);
                } catch (Throwable $e) {
                    $error = parseNetworkError($e->getMessage());

                    jsonResponse([
                        'ok' => false,
                        'message' => $error['message'],
                        'http_status' =>
                            $error['http_status'],
                        'error_type' =>
                            $error['error_type'],
                        'count' => 0,
                        'inserted' => 0,
                        'updated' => 0,
                        'skipped' => 0,
                        'errors' => 1,
                    ], 400);
                }
                break;

            case 'test_smtp_connection':
                try {
                    $config =
                        $data['settings']['smtp'] ?? [];

                    $socket = smtpConnect(
                        $config,
                        true
                    );

                    @fwrite($socket, "QUIT\r\n");
                    @fclose($socket);

                    jsonResponse([
                        'ok' => true,
                        'message' =>
                            'SMTP接続に成功しました。',
                        'smtp_server' =>
                            $config['smtp_server'] ?? '',
                        'smtp_port' =>
                            (int)($config['smtp_port'] ?? 0),
                        'smtp_encryption' =>
                            $config['smtp_encryption'] ?? '',
                        'authentication' => true,
                    ]);
                } catch (Throwable $e) {
                    $parts = explode('|', $e->getMessage(), 3);

                    jsonResponse([
                        'ok' => false,
                        'message' =>
                            safeMessage(
                                $parts[2] ??
                                $parts[1] ??
                                $parts[0]
                            ),
                        'error_type' =>
                            $parts[0] ?? 'connection',
                        'smtp_code' =>
                            isset($parts[1]) &&
                            ctype_digit($parts[1])
                                ? (int)$parts[1]
                                : null,
                        'smtp_server' =>
                            $data['settings']['smtp']['smtp_server'] ?? '',
                        'smtp_port' =>
                            (int)(
                                $data['settings']['smtp']['smtp_port'] ?? 0
                            ),
                        'smtp_encryption' =>
                            $data['settings']['smtp']['smtp_encryption'] ?? '',
                        'check_items' => [
                            'SMTPサーバ',
                            'SMTPポート',
                            'SMTP認証方式',
                            'SMTPユーザー名',
                            'SMTPパスワード',
                            'TLS/SSL設定',
                        ],
                    ], 400);
                }
                break;

            case 'send_smtp_test':
                try {
                    $config =
                        $data['settings']['smtp'] ?? [];

                    $to = (string)(
                        $_POST['test_recipient'] ?? ''
                    );

                    $result = smtpSend(
                        $config,
                        $to,
                        'アンケート管理システム SMTP送信テスト',
                        "アンケート管理システムのSMTP送信テストです。\r\n\r\n" .
                        "このメールはSMTP設定確認のために送信されています。"
                    );

                    $data['mail_logs'][] = [
                        'id' => newId('mail'),
                        'type' => 'smtp_test',
                        'recipient' => $to,
                        'status' => 'sent',
                        'smtp_code' =>
                            $result['code'],
                        'created_at' => date('c'),
                    ];

                    saveData($data);

                    jsonResponse([
                        'ok' => true,
                        'message' =>
                            'テストメールを送信しました。',
                        'recipient' => $to,
                        'smtp_code' =>
                            $result['code'],
                    ]);
                } catch (Throwable $e) {
                    $parts = explode('|', $e->getMessage(), 3);

                    jsonResponse([
                        'ok' => false,
                        'message' =>
                            safeMessage(
                                $parts[2] ??
                                $parts[1] ??
                                $parts[0]
                            ),
                        'error_type' =>
                            $parts[0] ?? 'smtp_protocol',
                        'smtp_code' =>
                            isset($parts[1]) &&
                            ctype_digit($parts[1])
                                ? (int)$parts[1]
                                : null,
                    ], 400);
                }
                break;

            default:
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'Unknown API action: ' . $action,
                ], 400);
        }
    } catch (Throwable $e) {
        jsonResponse([
            'ok' => false,
            'message' => safeMessage($e->getMessage()),
        ], 400);
    }
}

$csrf = csrfToken();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-gray-100 text-gray-900">

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
        mail_logs: [],
        settings: {
            kintone: {},
            smtp: {}
        },
        currentSurvey: null,
        keyword: '',
        statusFilter: '',
        sort: 'updated_desc',
        answers: {},
        visibleQuestions: {}
    },

    render: {},
    actions: {},
    api: {},
    utils: {},

    init: async function () {
        if (this.state.initialized) {
            return;
        }

        this.state.initialized = true;

        this.render.shell();

        try {
            await this.api.load();
        } catch (e) {
            this.utils.notice(e.message, 'error');
        }

        this.render.current();
    },

    initSortable: function () {
        const root =
            document.getElementById('question_editor');

        if (!root || typeof Sortable === 'undefined') {
            return;
        }

        const groupList =
            root.querySelector('[data-sortable-groups]');

        if (groupList &&
            groupList.dataset.sortableReady !== '1') {

            Sortable.create(groupList, {
                animation: 150,
                handle: '[data-group-handle]',
                onEnd: function () {
                    App.actions.syncQuestionStructure();
                    App.actions.renumberQuestions();
                    App.render.editor();
                }
            });

            groupList.dataset.sortableReady = '1';
        }

        root.querySelectorAll(
            '[data-sortable-questions]'
        ).forEach(function (element) {

            if (element.dataset.sortableReady === '1') {
                return;
            }

            Sortable.create(element, {
                group: 'survey-questions',
                animation: 150,
                handle: '[data-question-handle]',
                onEnd: function () {
                    App.actions.syncQuestionStructure();
                    App.actions.renumberQuestions();
                    App.render.editor();
                }
            });

            element.dataset.sortableReady = '1';
        });
    },

    api: {

        request: async function (action, payload = {}) {

            const body = new URLSearchParams();

            body.set('action', action);
            body.set(
                'csrf_token',
                document.getElementById('csrf_token').value
            );

            Object.entries(payload).forEach(
                ([key, value]) => {
                    body.set(
                        key,
                        typeof value === 'object'
                            ? JSON.stringify(value)
                            : String(value)
                    );
                }
            );

            const response = await fetch(
                window.location.href,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8',
                        'Accept': 'application/json'
                    },
                    body: body.toString()
                }
            );

            const text = await response.text();

            let json;

            try {
                json = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'サーバーがJSONではない応答を返しました。'
                );
            }

            if (!response.ok || json.ok === false) {
                const error = new Error(
                    json.message ||
                    'API処理に失敗しました。'
                );

                Object.assign(error, json);

                throw error;
            }

            return json;
        },

        load: async function () {

            const json =
                await this.request('get_data');

            App.state.surveys =
                Array.isArray(json.surveys)
                    ? json.surveys
                    : [];

            App.state.responses =
                Array.isArray(json.responses)
                    ? json.responses
                    : [];

            App.state.customers =
                Array.isArray(json.customers)
                    ? json.customers
                    : [];

            App.state.mail_logs =
                Array.isArray(json.mail_logs)
                    ? json.mail_logs
                    : [];

            App.state.settings =
                json.settings || {
                    kintone: {},
                    smtp: {}
                };

            if (!App.state.settings.kintone) {
                App.state.settings.kintone = {};
            }

            if (!App.state.settings.smtp) {
                App.state.settings.smtp = {};
            }
        }
    },

    utils: {

        escape: function (value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        },

        id: function (prefix) {
            return prefix + '_' +
                Date.now() + '_' +
                Math.random()
                    .toString(36)
                    .slice(2);
        },

        notice: function (message, type = 'info') {

            const el =
                document.createElement('div');

            el.className =
                'fixed right-4 top-4 z-[9999] ' +
                (type === 'error'
                    ? 'bg-red-600'
                    : 'bg-blue-600') +
                ' text-white px-4 py-3 rounded-lg shadow-lg';

            el.textContent = message;

            document.body.appendChild(el);

            setTimeout(
                () => el.remove(),
                5000
            );
        },

        newSurvey: function () {
            return {
                id: App.utils.id('survey'),
                title: '新しいアンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                numbering_mode: 'global',
                general_response_enabled: false,
                groups: [
                    {
                        id: App.utils.id('group'),
                        name: 'グループ1',
                        questions: []
                    }
                ],
                deleted: false,
                created_at:
                    new Date().toISOString(),
                updated_at:
                    new Date().toISOString(),
                public_token:
                    crypto.randomUUID()
            };
        }
    },

    render: {

        shell: function () {

            document.getElementById('app').innerHTML = `
                <div class="min-h-screen">

                    <header
                        class="sticky top-0 z-40 bg-white
                               border-b shadow-sm"
                    >
                        <div
                            class="max-w-7xl mx-auto px-4 py-4
                                   flex items-center justify-between"
                        >
                            <button
                                class="font-bold text-lg"
                                onclick="App.actions.goList()"
                            >
                                アンケート管理システム
                            </button>

                            <nav class="flex gap-2">
                                <button
                                    class="px-3 py-2 rounded hover:bg-gray-100"
                                    onclick="App.actions.goList()"
                                >
                                    アンケート一覧
                                </button>

                                <button
                                    class="px-3 py-2 rounded hover:bg-gray-100"
                                    onclick="App.actions.showSettings()"
                                >
                                    キントーン・メール設定
                                </button>

                                <button
                                    class="px-3 py-2 rounded hover:bg-gray-100"
                                    onclick="App.actions.logout()"
                                >
                                    ログアウト
                                </button>
                            </nav>
                        </div>
                    </header>

                    <main
                        id="main_content"
                        class="max-w-7xl mx-auto px-4 py-6"
                    ></main>
                </div>
            `;
        },

        current: function () {

            if (App.state.screen === 'list') {
                this.list();
            } else if (App.state.screen === 'edit') {
                this.editor();
            } else if (App.state.screen === 'settings') {
                this.settings();
            }
        },

        list: function () {

            let surveys =
                App.state.surveys
                    .filter(s => !s.deleted);

            if (App.state.keyword) {
                surveys =
                    surveys.filter(s =>
                        String(s.title)
                            .toLowerCase()
                            .includes(
                                App.state.keyword.toLowerCase()
                            )
                    );
            }

            if (App.state.statusFilter) {
                surveys =
                    surveys.filter(
                        s =>
                            s.status ===
                            App.state.statusFilter
                    );
            }

            surveys.sort(function (a, b) {
                return String(b.updated_at)
                    .localeCompare(
                        String(a.updated_at)
                    );
            });

            const status = function (value) {
                return value === 'active'
                    ? '<span class="px-2 py-1 rounded bg-green-100 text-green-700">公開中</span>'
                    : value === 'ended'
                        ? '<span class="px-2 py-1 rounded bg-gray-200 text-gray-700">終了</span>'
                        : '<span class="px-2 py-1 rounded bg-yellow-100 text-yellow-700">下書き</span>';
            };

            document.getElementById(
                'main_content'
            ).innerHTML = `

                <div class="flex justify-between items-center mb-6">

                    <div>
                        <div class="text-sm text-gray-500">
                            ホーム
                        </div>
                        <h1 class="text-2xl font-bold">
                            アンケート一覧
                        </h1>
                    </div>

                    <button
                        class="bg-blue-600 text-white px-4 py-2 rounded-lg"
                        onclick="App.actions.newSurvey()"
                    >
                        ＋ 新規アンケート作成
                    </button>
                </div>

                <div
                    class="bg-white rounded-xl shadow p-4 mb-4
                           flex flex-wrap gap-3"
                >
                    <input
                        class="border rounded-lg px-3 py-2"
                        placeholder="タイトル検索"
                        value="${App.utils.escape(
                            App.state.keyword
                        )}"
                        oninput="App.actions.filterSurveys(this.value)"
                    >

                    <select
                        class="border rounded-lg px-3 py-2"
                        onchange="App.actions.toggleStatusFilter(this.value)"
                    >
                        <option value="">すべて</option>
                        <option value="draft"
                            ${App.state.statusFilter === 'draft'
                                ? 'selected' : ''}>
                            下書き
                        </option>
                        <option value="active"
                            ${App.state.statusFilter === 'active'
                                ? 'selected' : ''}>
                            公開中
                        </option>
                        <option value="ended"
                            ${App.state.statusFilter === 'ended'
                                ? 'selected' : ''}>
                            終了
                        </option>
                    </select>
                </div>

                <div class="bg-white rounded-xl shadow overflow-x-auto">

                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left p-3">更新日</th>
                                <th class="text-left p-3">タイトル</th>
                                <th class="text-left p-3">ステータス</th>
                                <th class="text-left p-3">回答数</th>
                                <th class="text-left p-3">操作</th>
                            </tr>
                        </thead>

                        <tbody>
                            ${
                                surveys.length
                                    ? surveys.map(s => {

                                        let actions = `
                                            <button
                                                class="text-blue-600 mr-3"
                                                onclick="App.actions.editSurvey('${App.utils.escape(s.id)}')"
                                            >
                                                確認・編集
                                            </button>
                                        `;

                                        if (
                                            s.status === 'active' ||
                                            s.status === 'ended'
                                        ) {
                                            actions += `
                                                <button
                                                    class="text-purple-600 mr-3"
                                                    onclick="App.actions.showAggregate('${App.utils.escape(s.id)}')"
                                                >
                                                    集計
                                                </button>
                                            `;
                                        }

                                        if (s.status === 'active') {
                                            actions += `
                                                <button
                                                    class="text-indigo-600 mr-3"
                                                    onclick="App.actions.showMail('${App.utils.escape(s.id)}')"
                                                >
                                                    送信
                                                </button>
                                            `;
                                        }

                                        if (s.status === 'draft') {
                                            actions += `
                                                <button
                                                    class="text-red-600 mr-3"
                                                    onclick="App.actions.deleteSurvey('${App.utils.escape(s.id)}')"
                                                >
                                                    削除
                                                </button>
                                            `;
                                        }

                                        actions += `
                                            <button
                                                class="text-gray-600"
                                                onclick="App.actions.duplicateSurvey('${App.utils.escape(s.id)}')"
                                            >
                                                複製
                                            </button>
                                        `;

                                        return `
                                            <tr class="border-t">
                                                <td class="p-3">
                                                    ${App.utils.escape(
                                                        s.updated_at
                                                    )}
                                                </td>
                                                <td class="p-3 font-medium">
                                                    ${App.utils.escape(
                                                        s.title
                                                    )}
                                                </td>
                                                <td class="p-3">
                                                    ${status(s.status)}
                                                </td>
                                                <td class="p-3">
                                                    ${Number(
                                                        s.response_count || 0
                                                    )}
                                                </td>
                                                <td class="p-3 whitespace-nowrap">
                                                    ${actions}
                                                </td>
                                            </tr>
                                        `;
                                    }).join('')
                                    : `
                                        <tr>
                                            <td
                                                colspan="5"
                                                class="p-10 text-center text-gray-500"
                                            >
                                                アンケートがありません。
                                            </td>
                                        </tr>
                                    `
                            }
                        </tbody>
                    </table>
                </div>
            `;
        },

        editor: function () {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                App.actions.goList();
                return;
            }

            const questionCount =
                survey.groups.reduce(
                    (n, g) =>
                        n + g.questions.length,
                    0
                );

            document.getElementById(
                'main_content'
            ).innerHTML = `

                <div class="mb-6">
                    <div class="text-sm text-gray-500">
                        ホーム ＞ アンケート一覧 ＞ 確認・編集
                    </div>

                    <div
                        class="flex justify-between items-center mt-2"
                    >
                        <h1 class="text-2xl font-bold">
                            アンケート作成・編集
                        </h1>

                        <div class="flex gap-2">
                            <button
                                class="border px-4 py-2 rounded-lg"
                                onclick="App.actions.preview()"
                            >
                                プレビュー
                            </button>

                            <button
                                class="bg-blue-600 text-white px-4 py-2 rounded-lg"
                                onclick="App.actions.saveSurvey()"
                            >
                                保存
                            </button>

                            <button
                                class="border px-4 py-2 rounded-lg"
                                onclick="App.actions.cancelEdit()"
                            >
                                戻る
                            </button>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow p-6 mb-5">

                    <div class="grid md:grid-cols-2 gap-4">

                        <label>
                            <span class="block text-sm mb-1">
                                タイトル
                            </span>

                            <input
                                id="survey_title"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escape(
                                    survey.title
                                )}"
                                oninput="App.actions.updateSurveyField('title',this.value)"
                            >
                        </label>

                        <label>
                            <span class="block text-sm mb-1">
                                ステータス
                            </span>

                            <select
                                id="survey_status"
                                class="w-full border rounded-lg px-3 py-2"
                                onchange="App.actions.changeSurveyStatus(this.value)"
                            >
                                <option value="draft"
                                    ${survey.status === 'draft'
                                        ? 'selected' : ''}>
                                    下書き
                                </option>

                                <option value="active"
                                    ${survey.status === 'active'
                                        ? 'selected' : ''}>
                                    公開中
                                </option>

                                <option value="ended"
                                    ${survey.status === 'ended'
                                        ? 'selected' : ''}>
                                    終了
                                </option>
                            </select>
                        </label>

                        <label>
                            <span class="block text-sm mb-1">
                                開始日時
                            </span>

                            <input
                                id="survey_start_at"
                                type="datetime-local"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escape(
                                    survey.start_at
                                )}"
                                oninput="App.actions.updateSurveyField('start_at',this.value)"
                            >
                        </label>

                        <label>
                            <span class="block text-sm mb-1">
                                終了日時
                            </span>

                            <input
                                id="survey_end_at"
                                type="datetime-local"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escape(
                                    survey.end_at
                                )}"
                                oninput="App.actions.updateSurveyField('end_at',this.value)"
                            >
                        </label>

                        <label>
                            <span class="block text-sm mb-1">
                                質問番号形式
                            </span>

                            <select
                                id="survey_numbering_mode"
                                class="w-full border rounded-lg px-3 py-2"
                                onchange="App.actions.updateSurveyField('numbering_mode',this.value)"
                            >
                                <option value="global"
                                    ${survey.numbering_mode === 'global'
                                        ? 'selected' : ''}>
                                    Q1 / Q2 / Q3
                                </option>

                                <option value="group"
                                    ${survey.numbering_mode === 'group'
                                        ? 'selected' : ''}>
                                    Q1-1 / Q1-2
                                </option>
                            </select>
                        </label>

                        <label class="flex items-center gap-2">
                            <input
                                type="checkbox"
                                ${survey.general_response_enabled
                                    ? 'checked' : ''}
                                onchange="App.actions.updateSurveyField('general_response_enabled',this.checked)"
                            >
                            一般回答を許可する
                        </label>

                    </div>

                    <div class="mt-4 text-sm text-gray-500">
                        質問数：${questionCount}
                    </div>
                </div>

                <div
                    id="question_editor"
                    class="space-y-5"
                >
                    <div data-sortable-groups class="space-y-5">

                        ${
                            survey.groups.map(
                                (group, gi) =>
                                    this.groupEditor(
                                        group,
                                        gi,
                                        survey
                                    )
                            ).join('')
                        }

                    </div>

                    <button
                        class="w-full border-2 border-dashed
                               border-blue-300 text-blue-600
                               py-4 rounded-xl bg-white"
                        onclick="App.actions.addGroup()"
                    >
                        ＋ ブロックを追加
                    </button>
                </div>
            `;

            this.questionEditorEvents();
        },

        groupEditor: function (
            group,
            groupIndex,
            survey
        ) {

            return `
                <section
                    class="bg-white rounded-xl shadow p-5"
                    data-group-id="${App.utils.escape(group.id)}"
                >

                    <div
                        class="flex items-center gap-3 mb-4"
                    >
                        <span
                            data-group-handle
                            class="cursor-move text-gray-400"
                        >
                            ☰
                        </span>

                        <input
                            class="flex-1 border rounded-lg px-3 py-2 font-semibold"
                            value="${App.utils.escape(group.name)}"
                            oninput="App.actions.updateGroupName('${App.utils.escape(group.id)}',this.value)"
                        >

                        <span class="text-sm text-gray-500">
                            ブロック${groupIndex + 1}
                        </span>

                        <button
                            class="text-red-600"
                            onclick="App.actions.deleteGroup('${App.utils.escape(group.id)}')"
                        >
                            削除
                        </button>
                    </div>

                    <div
                        data-sortable-questions
                        data-group-id="${App.utils.escape(group.id)}"
                        class="space-y-4"
                    >
                        ${
                            group.questions.map(
                                (question, qi) =>
                                    this.questionEditor(
                                        group,
                                        question,
                                        qi,
                                        survey
                                    )
                            ).join('')
                        }
                    </div>

                    <button
                        class="mt-4 text-blue-600 border
                               border-blue-300 px-4 py-2 rounded-lg"
                        onclick="App.actions.addQuestion('${App.utils.escape(group.id)}')"
                    >
                        ＋ 質問を追加
                    </button>
                </section>
            `;
        },

        questionEditor: function (
            group,
            question,
            questionIndex,
            survey
        ) {

            const allQuestions = [];

            survey.groups.forEach(
                (g, gi) => {
                    g.questions.forEach(
                        (q, qi) => {
                            allQuestions.push({
                                ...q,
                                groupIndex: gi,
                                questionIndex: qi
                            });
                        }
                    );
                }
            );

            const currentIndex =
                allQuestions.findIndex(
                    q =>
                        String(q.id) ===
                        String(question.id)
                );

            const candidates =
                allQuestions.filter(
                    q =>
                        allQuestions.indexOf(q) >
                        currentIndex
                );

            return `
                <article
                    class="border rounded-xl p-4"
                    data-question-id="${App.utils.escape(question.id)}"
                >

                    <div class="flex gap-3 items-start">

                        <span
                            data-question-handle
                            class="cursor-move text-gray-400 pt-2"
                        >
                            ☰
                        </span>

                        <div class="flex-1 space-y-3">

                            <div
                                class="flex justify-between items-center"
                            >
                                <strong>
                                    ${App.utils.escape(
                                        question.number || ''
                                    )}
                                </strong>

                                <button
                                    class="text-red-600 text-sm"
                                    onclick="App.actions.deleteQuestion('${App.utils.escape(group.id)}','${App.utils.escape(question.id)}')"
                                >
                                    質問削除
                                </button>
                            </div>

                            <input
                                class="w-full border rounded-lg px-3 py-2"
                                placeholder="質問文"
                                value="${App.utils.escape(question.text)}"
                                oninput="App.actions.updateQuestion('${App.utils.escape(group.id)}','${App.utils.escape(question.id)}','text',this.value)"
                            >

                            <div class="flex flex-wrap gap-4">

                                <label>
                                    <span class="text-sm mr-2">
                                        質問形式
                                    </span>

                                    <select
                                        class="border rounded-lg px-3 py-2"
                                        onchange="App.actions.updateQuestion('${App.utils.escape(group.id)}','${App.utils.escape(question.id)}','type',this.value)"
                                    >
                                        <option value="text"
                                            ${question.type === 'text'
                                                ? 'selected' : ''}>
                                            自由記述
                                        </option>

                                        <option value="single"
                                            ${question.type === 'single'
                                                ? 'selected' : ''}>
                                            単一選択
                                        </option>

                                        <option value="multiple"
                                            ${question.type === 'multiple'
                                                ? 'selected' : ''}>
                                            複数選択
                                        </option>
                                    </select>
                                </label>

                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        ${question.required
                                            ? 'checked' : ''}
                                        onchange="App.actions.updateQuestion('${App.utils.escape(group.id)}','${App.utils.escape(question.id)}','required',this.checked)"
                                    >
                                    必須回答
                                </label>

                                ${
                                    question.type !== 'text'
                                        ? `
                                            <label class="flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    ${question.other_enabled
                                                        ? 'checked' : ''}
                                                    onchange="App.actions.updateQuestion('${App.utils.escape(group.id)}','${App.utils.escape(question.id)}','other_enabled',this.checked)"
                                                >
                                                その他を許可
                                            </label>
                                        `
                                        : ''
                                }

                            </div>

                            ${
                                question.type !== 'text'
                                    ? `
                                        <div
                                            class="bg-gray-50 rounded-lg p-4"
                                        >
                                            <div class="font-semibold mb-3">
                                                選択肢
                                            </div>

                                            <div class="space-y-3">
                                                ${
                                                    question.options.map(
                                                        (option, oi) => {

                                                            const optionId =
                                                                typeof option === 'object'
                                                                    ? String(option.id || '')
                                                                    : 'option_' + oi;

                                                            const optionText =
                                                                typeof option === 'object'
                                                                    ? String(option.text || '')
                                                                    : String(option);

                                                            const branch =
                                                                question.branching &&
                                                                question.branching[optionId]
                                                                    ? question.branching[optionId]
                                                                    : '';

                                                            return `
                                                                <div
                                                                    class="grid md:grid-cols-[1fr_1fr_auto] gap-2"
                                                                >
                                                                    <input
                                                                        class="border rounded-lg px-3 py-2"
                                                                        value="${App.utils.escape(optionText)}"
                                                                        oninput="App.actions.updateOption('${App.utils.escape(group.id)}','${App.utils.escape(question.id)}',${oi},this.value)"
                                                                    >

                                                                    ${
                                                                        question.type === 'single'
                                                                            ? `
                                                                                <select
                                                                                    class="border rounded-lg px-3 py-2"
                                                                                    onchange="App.actions.updateBranch('${App.utils.escape(group.id)}','${App.utils.escape(question.id)}',${oi},this.value)"
                                                                                >
                                                                                    <option value="">
                                                                                        分岐しない
                                                                                    </option>

                                                                                    ${
                                                                                        candidates.map(
                                                                                            candidate => `
                                                                                                <option
                                                                                                    value="${App.utils.escape(candidate.id)}"
                                                                                                    ${branch === candidate.id
                                                                                                        ? 'selected'
                                                                                                        : ''}
                                                                                                >
                                                                                                    ${App.utils.escape(candidate.number)}：${App.utils.escape(candidate.text)}
                                                                                                </option>
                                                                                            `
                                                                                        ).join('')
                                                                                    }
                                                                                </select>
                                                                            `
                                                                            : '<div></div>'
                                                                    }

                                                                    <button
                                                                        class="text-red-600 px-2"
                                                                        onclick="App.actions.deleteOption('${App.utils.escape(group.id)}','${App.utils.escape(question.id)}',${oi})"
                                                                    >
                                                                        ×
                                                                    </button>
                                                                </div>
                                                            `;
                                                        }
                                                    ).join('')
                                                }
                                            </div>

                                            <button
                                                class="mt-3 text-blue-600"
                                                onclick="App.actions.addOption('${App.utils.escape(group.id)}','${App.utils.escape(question.id)}')"
                                            >
                                                ＋ 選択肢を追加
                                            </button>
                                        </div>
                                    `
                                    : ''
                            }

                        </div>
                    </div>
                </article>
            `;
        },

        questionEditorEvents: function () {
            App.initSortable();
        },

        settings: function () {

            const k =
                App.state.settings.kintone || {};

            const s =
                App.state.settings.smtp || {};

            document.getElementById(
                'main_content'
            ).innerHTML = `

                <div class="mb-6">

                    <div class="text-sm text-gray-500">
                        ホーム ＞ キントーン・メール設定
                    </div>

                    <h1 class="text-2xl font-bold mt-2">
                        キントーン・メール設定
                    </h1>
                </div>

                <div class="space-y-6">

                    <section
                        class="bg-white rounded-xl shadow p-6"
                    >

                        <h2 class="text-xl font-bold mb-5">
                            キントーン設定
                        </h2>

                        <form
                            id="kintone_settings_form"
                            onsubmit="event.preventDefault();App.actions.saveKintoneSettings()"
                        >

                            <div
                                class="grid md:grid-cols-2 gap-4"
                            >

                                <label>
                                    <span class="block text-sm mb-1">
                                        サブドメイン
                                    </span>
                                    <input
                                        id="setting_subdomain"
                                        class="w-full border rounded-lg px-3 py-2"
                                        value="${App.utils.escape(k.subdomain || '')}"
                                    >
                                </label>

                                <label>
                                    <span class="block text-sm mb-1">
                                        顧客管理アプリID
                                    </span>
                                    <input
                                        id="setting_app_id"
                                        class="w-full border rounded-lg px-3 py-2"
                                        value="${App.utils.escape(k.app_id || '')}"
                                    >
                                </label>

                                <label>
                                    <span class="block text-sm mb-1">
                                        ログイン名
                                    </span>
                                    <input
                                        id="setting_login_name"
                                        class="w-full border rounded-lg px-3 py-2"
                                        value="${App.utils.escape(k.login_name || '')}"
                                    >
                                </label>

                                <label>
                                    <span class="block text-sm mb-1">
                                        パスワード
                                    </span>
                                    <input
                                        id="setting_password"
                                        type="password"
                                        autocomplete="new-password"
                                        class="w-full border rounded-lg px-3 py-2"
                                        placeholder="変更しない場合は空欄"
                                    >
                                </label>

                                <label>
                                    <span class="block text-sm mb-1">
                                        Proxy
                                    </span>
                                    <input
                                        id="setting_proxy"
                                        class="w-full border rounded-lg px-3 py-2"
                                        value="${App.utils.escape(k.proxy || '')}"
                                    >
                                </label>

                                <label class="flex items-center gap-2 pt-7">
                                    <input
                                        id="setting_ssl_verify"
                                        type="checkbox"
                                        ${k.ssl_verify !== false
                                            ? 'checked' : ''}
                                    >
                                    SSL証明書検証
                                </label>

                            </div>

                            <h3 class="font-semibold mt-6 mb-3">
                                顧客フィールド
                            </h3>

                            <div
                                class="grid md:grid-cols-2 gap-4"
                            >

                                ${[
                                    ['field_company','会社名'],
                                    ['field_name','氏名'],
                                    ['field_email','メール'],
                                    ['field_department','部署'],
                                    ['field_phone','電話'],
                                    ['field_address','住所']
                                ].map(
                                    ([key,label]) => `
                                        <label>
                                            <span class="block text-sm mb-1">
                                                ${label}フィールドコード
                                            </span>
                                            <input
                                                id="${key}"
                                                class="w-full border rounded-lg px-3 py-2"
                                                value="${App.utils.escape(k[key] || '')}"
                                            >
                                        </label>
                                    `
                                ).join('')}

                            </div>

                            <div class="flex flex-wrap gap-2 mt-6">

                                <button
                                    id="kintone_save_button"
                                    type="submit"
                                    class="bg-blue-600 text-white px-4 py-2 rounded-lg"
                                >
                                    設定を保存
                                </button>

                                <button
                                    type="button"
                                    class="border px-4 py-2 rounded-lg"
                                    onclick="App.actions.connectKintone()"
                                >
                                    キントーン接続確認
                                </button>

                                <button
                                    type="button"
                                    class="border px-4 py-2 rounded-lg"
                                    onclick="App.actions.fetchKintoneFields()"
                                >
                                    フィールド取得
                                </button>

                                <button
                                    type="button"
                                    class="border px-4 py-2 rounded-lg"
                                    onclick="App.actions.syncCustomers()"
                                >
                                    顧客データを同期
                                </button>

                            </div>

                            <div
                                id="kintone_message"
                                class="mt-4"
                            ></div>

                        </form>
                    </section>

                    <section
                        class="bg-white rounded-xl shadow p-6"
                    >

                        <h2 class="text-xl font-bold mb-5">
                            SMTP設定
                        </h2>

                        <form
                            id="smtp_settings_form"
                            onsubmit="event.preventDefault();App.actions.saveSmtpSettings()"
                        >

                            <div
                                class="grid md:grid-cols-2 gap-4"
                            >

                                <label>
                                    <span class="block text-sm mb-1">
                                        SMTPサーバ
                                    </span>
                                    <input
                                        id="smtp_server"
                                        class="w-full border rounded-lg px-3 py-2"
                                        value="${App.utils.escape(s.smtp_server || '')}"
                                    >
                                </label>

                                <label>
                                    <span class="block text-sm mb-1">
                                        SMTPポート
                                    </span>
                                    <input
                                        id="smtp_port"
                                        type="number"
                                        class="w-full border rounded-lg px-3 py-2"
                                        value="${App.utils.escape(s.smtp_port || 587)}"
                                    >
                                </label>

                                <label>
                                    <span class="block text-sm mb-1">
                                        暗号化方式
                                    </span>
                                    <select
                                        id="smtp_encryption"
                                        class="w-full border rounded-lg px-3 py-2"
                                    >
                                        <option value="none"
                                            ${s.smtp_encryption === 'none'
                                                ? 'selected' : ''}>
                                            none
                                        </option>
                                        <option value="starttls"
                                            ${!s.smtp_encryption ||
                                              s.smtp_encryption === 'starttls'
                                                ? 'selected' : ''}>
                                            starttls
                                        </option>
                                        <option value="ssl"
                                            ${s.smtp_encryption === 'ssl'
                                                ? 'selected' : ''}>
                                            ssl
                                        </option>
                                    </select>
                                </label>

                                <label class="flex items-center gap-2 pt-7">
                                    <input
                                        id="smtp_auth"
                                        type="checkbox"
                                        ${s.smtp_auth !== false
                                            ? 'checked' : ''}
                                    >
                                    SMTP認証
                                </label>

                                <label>
                                    <span class="block text-sm mb-1">
                                        SMTPユーザー名
                                    </span>
                                    <input
                                        id="smtp_username"
                                        class="w-full border rounded-lg px-3 py-2"
                                        value="${App.utils.escape(s.smtp_username || '')}"
                                    >
                                </label>

                                <label>
                                    <span class="block text-sm mb-1">
                                        SMTPパスワード
                                    </span>
                                    <input
                                        id="smtp_password"
                                        type="password"
                                        autocomplete="new-password"
                                        class="w-full border rounded-lg px-3 py-2"
                                        placeholder="変更しない場合は空欄"
                                    >
                                </label>

                                <label>
                                    <span class="block text-sm mb-1">
                                        送信元メールアドレス
                                    </span>
                                    <input
                                        id="smtp_from_email"
                                        type="email"
                                        class="w-full border rounded-lg px-3 py-2"
                                        value="${App.utils.escape(s.smtp_from_email || '')}"
                                    >
                                </label>

                                <label>
                                    <span class="block text-sm mb-1">
                                        送信元表示名
                                    </span>
                                    <input
                                        id="smtp_from_name"
                                        class="w-full border rounded-lg px-3 py-2"
                                        value="${App.utils.escape(s.smtp_from_name || '')}"
                                    >
                                </label>

                                <label>
                                    <span class="block text-sm mb-1">
                                        接続タイムアウト
                                    </span>
                                    <input
                                        id="smtp_timeout"
                                        type="number"
                                        min="1"
                                        class="w-full border rounded-lg px-3 py-2"
                                        value="${App.utils.escape(s.smtp_timeout || 10)}"
                                    >
                                </label>

                            </div>

                            <div class="flex flex-wrap gap-2 mt-6">

                                <button
                                    id="smtp_save_button"
                                    type="submit"
                                    class="bg-blue-600 text-white px-4 py-2 rounded-lg"
                                >
                                    設定を保存
                                </button>

                                <button
                                    type="button"
                                    class="border px-4 py-2 rounded-lg"
                                    onclick="App.actions.testSmtpConnection()"
                                >
                                    SMTP接続確認
                                </button>

                                <button
                                    type="button"
                                    class="border px-4 py-2 rounded-lg"
                                    onclick="App.actions.sendSmtpTest()"
                                >
                                    テストメール送信
                                </button>

                            </div>

                            <div
                                id="smtp_message"
                                class="mt-4"
                            ></div>

                        </form>
                    </section>

                </div>
            `;
        }
    },

    actions: {

        goList: async function () {
            App.state.screen = 'list';
            await App.api.load();
            App.render.current();
        },

        newSurvey: function () {
            App.state.currentSurvey =
                App.utils.newSurvey();

            App.state.screen = 'edit';

            App.render.current();
        },

        editSurvey: function (id) {

            const survey =
                App.state.surveys.find(
                    s => String(s.id) === String(id)
                );

            if (!survey) {
                App.utils.notice(
                    'アンケートが見つかりません。',
                    'error'
                );
                return;
            }

            App.state.currentSurvey =
                structuredClone(survey);

            App.state.screen = 'edit';

            App.render.current();
        },

        cancelEdit: function () {
            App.state.currentSurvey = null;
            App.state.screen = 'list';
            App.render.current();
        },

        updateSurveyField: function (
            field,
            value
        ) {
            if (!App.state.currentSurvey) {
                return;
            }

            App.state.currentSurvey[field] = value;

            if (field === 'numbering_mode') {
                App.actions.renumberQuestions();
                App.render.editor();
            }
        },

        changeSurveyStatus: function (value) {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return;
            }

            const old = survey.status;

            if (old === value) {
                return;
            }

            if (
                old === 'active' &&
                value === 'ended'
            ) {
                if (!confirm(
                    'このアンケートを終了状態に変更しますか？'
                )) {
                    App.render.editor();
                    return;
                }
            }

            if (
                old === 'ended' &&
                value === 'active'
            ) {
                if (!confirm(
                    'このアンケートを公開状態に変更しますか？'
                )) {
                    App.render.editor();
                    return;
                }
            }

            survey.status = value;

            App.render.editor();
        },

        addGroup: function () {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return;
            }

            survey.groups.push({
                id: App.utils.id('group'),
                name:
                    'グループ' +
                    (survey.groups.length + 1),
                questions: []
            });

            App.actions.renumberQuestions();
            App.render.editor();
        },

        deleteGroup: function (groupId) {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return;
            }

            if (!confirm(
                'このグループを削除しますか？'
            )) {
                return;
            }

            survey.groups =
                survey.groups.filter(
                    g =>
                        String(g.id) !==
                        String(groupId)
                );

            App.actions.removeInvalidBranches();
            App.actions.renumberQuestions();
            App.render.editor();
        },

        updateGroupName: function (
            groupId,
            value
        ) {
            const group =
                App.state.currentSurvey?.groups.find(
                    g =>
                        String(g.id) ===
                        String(groupId)
                );

            if (group) {
                group.name = value;
            }
        },

        addQuestion: function (groupId) {

            const group =
                App.state.currentSurvey?.groups.find(
                    g =>
                        String(g.id) ===
                        String(groupId)
                );

            if (!group) {
                return;
            }

            group.questions.push({
                id: App.utils.id('question'),
                text: '',
                type: 'text',
                required: false,
                options: [],
                other_enabled: false,
                branching: {}
            });

            App.actions.renumberQuestions();
            App.render.editor();
        },

        deleteQuestion: function (
            groupId,
            questionId
        ) {

            const group =
                App.state.currentSurvey?.groups.find(
                    g =>
                        String(g.id) ===
                        String(groupId)
                );

            if (!group) {
                return;
            }

            group.questions =
                group.questions.filter(
                    q =>
                        String(q.id) !==
                        String(questionId)
                );

            App.actions.removeInvalidBranches();
            App.actions.renumberQuestions();
            App.render.editor();
        },

        updateQuestion: function (
            groupId,
            questionId,
            field,
            value
        ) {

            const q =
                App.actions.findQuestion(
                    groupId,
                    questionId
                );

            if (!q) {
                return;
            }

            q[field] = value;

            if (field === 'type' &&
                value === 'text') {
                q.options = [];
                q.branching = {};
            }

            App.render.editor();
        },

        addOption: function (
            groupId,
            questionId
        ) {

            const q =
                App.actions.findQuestion(
                    groupId,
                    questionId
                );

            if (!q) {
                return;
            }

            q.options.push({
                id: App.utils.id('option'),
                text:
                    '選択肢 ' +
                    (q.options.length + 1)
            });

            App.render.editor();
        },

        updateOption: function (
            groupId,
            questionId,
            index,
            value
        ) {

            const q =
                App.actions.findQuestion(
                    groupId,
                    questionId
                );

            if (!q) {
                return;
            }

            if (
                typeof q.options[index] !==
                'object'
            ) {
                q.options[index] = {
                    id: App.utils.id('option'),
                    text: String(value)
                };
            } else {
                q.options[index].text = value;
            }
        },

        deleteOption: function (
            groupId,
            questionId,
            index
        ) {

            const q =
                App.actions.findQuestion(
                    groupId,
                    questionId
                );

            if (!q) {
                return;
            }

            const option =
                q.options[index];

            const optionId =
                typeof option === 'object'
                    ? option.id
                    : null;

            q.options.splice(index, 1);

            if (optionId) {
                delete q.branching[optionId];
            }

            App.render.editor();
        },

        updateBranch: function (
            groupId,
            questionId,
            index,
            targetId
        ) {

            const q =
                App.actions.findQuestion(
                    groupId,
                    questionId
                );

            if (!q) {
                return;
            }

            if (typeof q.options[index] !== 'object') {
                q.options[index] = {
                    id: App.utils.id('option'),
                    text:
                        String(q.options[index] || '')
                };
            }

            const optionId =
                q.options[index].id;

            q.branching[optionId] =
                targetId || null;

            App.render.editor();
        },

        findQuestion: function (
            groupId,
            questionId
        ) {

            const group =
                App.state.currentSurvey?.groups.find(
                    g =>
                        String(g.id) ===
                        String(groupId)
                );

            if (!group) {
                return null;
            }

            return group.questions.find(
                q =>
                    String(q.id) ===
                    String(questionId)
            ) || null;
        },

        syncQuestionStructure: function () {

            const survey =
                App.state.currentSurvey;

            const root =
                document.getElementById(
                    'question_editor'
                );

            if (!survey || !root) {
                return;
            }

            const groups = [];

            root.querySelectorAll(
                '[data-sortable-groups]'
            ).forEach(
                groupContainer => {

                    groupContainer
                        .querySelectorAll(
                            '[data-group-id]'
                        )
                        .forEach(
                            groupEl => {

                                const groupId =
                                    groupEl.dataset.groupId;

                                const group =
                                    survey.groups.find(
                                        g =>
                                            String(g.id) ===
                                            String(groupId)
                                    );

                                if (!group) {
                                    return;
                                }

                                const ids = [];

                                groupEl
                                    .querySelectorAll(
                                        '[data-question-id]'
                                    )
                                    .forEach(
                                        qEl =>
                                            ids.push(
                                                qEl.dataset.questionId
                                            )
                                    );

                                group.questions =
                                    ids.map(
                                        id =>
                                            group.questions.find(
                                                q =>
                                                    String(q.id) ===
                                                    String(id)
                                            )
                                    ).filter(Boolean);

                                groups.push(group);
                            }
                        );
                }
            );

            survey.groups = groups;
        },

        renumberQuestions: function () {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return;
            }

            let global = 1;

            survey.groups.forEach(
                (group, gi) => {

                    group.questions.forEach(
                        (question, qi) => {

                            question.number =
                                survey.numbering_mode ===
                                'group'
                                    ? `Q${gi + 1}-${qi + 1}`
                                    : `Q${global}`;

                            global++;
                        }
                    );
                }
            );
        },

        removeInvalidBranches: function () {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return;
            }

            const ids = new Set();

            survey.groups.forEach(
                group =>
                    group.questions.forEach(
                        q => ids.add(String(q.id))
                    )
            );

            survey.groups.forEach(
                group =>
                    group.questions.forEach(
                        q => {

                            Object.keys(
                                q.branching || {}
                            ).forEach(
                                optionId => {

                                    const target =
                                        q.branching[optionId];

                                    if (
                                        target !== null &&
                                        !ids.has(
                                            String(target)
                                        )
                                    ) {
                                        q.branching[optionId] =
                                            null;
                                    }
                                }
                            );
                        }
                    )
            );
        },

        saveSurvey: async function () {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return;
            }

            App.actions.syncQuestionStructure();
            App.actions.removeInvalidBranches();
            App.actions.renumberQuestions();

            try {

                const json =
                    await App.api.request(
                        'save_survey',
                        {
                            survey_json: survey
                        }
                    );

                App.state.currentSurvey =
                    json.survey;

                await App.api.load();

                App.state.screen = 'list';
                App.state.currentSurvey = null;

                App.render.current();

                App.utils.notice(
                    'アンケートを保存しました。'
                );

            } catch (e) {
                App.utils.notice(
                    e.message,
                    'error'
                );
            }
        },

        duplicateSurvey: async function (id) {

            if (!confirm(
                'このアンケートを複製しますか？'
            )) {
                return;
            }

            try {
                await App.api.request(
                    'duplicate_survey',
                    {survey_id: id}
                );

                await App.api.load();
                App.render.list();

                App.utils.notice(
                    'アンケートを複製しました。'
                );
            } catch (e) {
                App.utils.notice(
                    e.message,
                    'error'
                );
            }
        },

        deleteSurvey: async function (id) {

            if (!confirm(
                'このアンケートを削除しますか？'
            )) {
                return;
            }

            try {
                await App.api.request(
                    'delete_survey',
                    {survey_id: id}
                );

                await App.api.load();
                App.render.list();

                App.utils.notice(
                    'アンケートを削除しました。'
                );
            } catch (e) {
                App.utils.notice(
                    e.message,
                    'error'
                );
            }
        },

        filterSurveys: function (value) {
            App.state.keyword = value;
            App.render.list();
        },

        toggleStatusFilter: function (value) {
            App.state.statusFilter = value;
            App.render.list();
        },

        preview: function () {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return;
            }

            const modal =
                document.createElement('div');

            modal.id = 'preview_modal';

            modal.className =
                'fixed inset-0 z-50 bg-black/50 ' +
                'flex items-center justify-center p-4';

            const html =
                survey.groups.map(
                    group => `

                        <section class="mb-8">

                            <h3
                                class="text-lg font-bold mb-3"
                            >
                                ${App.utils.escape(
                                    group.name
                                )}
                            </h3>

                            ${
                                group.questions.map(
                                    q => `

                                        <div
                                            class="border rounded-lg p-4 mb-3"
                                        >
                                            <div class="font-medium mb-3">
                                                ${App.utils.escape(
                                                    q.number
                                                )}
                                                .
                                                ${App.utils.escape(
                                                    q.text
                                                )}

                                                ${
                                                    q.required
                                                        ? '<span class="text-red-600"> *</span>'
                                                        : ''
                                                }
                                            </div>

                                            ${
                                                q.type === 'text'
                                                    ? `
                                                        <textarea
                                                            class="w-full border rounded-lg p-2"
                                                            rows="3"
                                                        ></textarea>
                                                    `
                                                    : q.options.map(
                                                        option => {

                                                            const text =
                                                                typeof option === 'object'
                                                                    ? option.text
                                                                    : option;

                                                            return `
                                                                <label class="block mb-2">
                                                                    <input
                                                                        type="${q.type === 'single'
                                                                            ? 'radio'
                                                                            : 'checkbox'}"
                                                                        name="${App.utils.escape(q.id)}"
                                                                    >
                                                                    ${App.utils.escape(text)}
                                                                </label>
                                                            `;
                                                        }
                                                    ).join('')
                                            }
                                        </div>
                                    `
                                ).join('')
                            }

                        </section>
                    `
                ).join('');

            modal.innerHTML = `

                <div
                    class="bg-white rounded-xl shadow-xl
                           max-w-4xl w-full max-h-[90vh]
                           overflow-auto"
                >

                    <div
                        class="sticky top-0 bg-white border-b
                               p-4 flex justify-between"
                    >
                        <h2 class="font-bold">
                            プレビュー
                        </h2>

                        <button
                            onclick="this.closest('#preview_modal').remove()"
                        >
                            ×
                        </button>
                    </div>

                    <div
                        id="preview_content"
                        class="p-6"
                    >
                        ${html}
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
        },

        updateBranchVisibility: function () {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return;
            }

            const visible = {};

            const all = [];

            survey.groups.forEach(
                g =>
                    g.questions.forEach(
                        q => all.push(q)
                    )
            );

            all.forEach(
                q => visible[q.id] = true
            );

            all.forEach(
                q => {

                    if (q.type !== 'single') {
                        return;
                    }

                    const answer =
                        App.state.answers[q.id];

                    if (!answer) {
                        return;
                    }

                    const option =
                        q.options.find(
                            o =>
                                (
                                    typeof o === 'object'
                                        ? o.id
                                        : ''
                                ) === answer
                        );

                    if (!option) {
                        return;
                    }

                    const target =
                        q.branching?.[option.id];

                    if (!target) {
                        return;
                    }

                    let reached = false;

                    all.forEach(
                        item => {
                            if (
                                String(item.id) ===
                                String(target)
                            ) {
                                reached = true;
                            }

                            if (reached &&
                                String(item.id) !==
                                String(target)) {
                                visible[item.id] = true;
                            }
                        }
                    );

                    let skip = false;

                    all.forEach(
                        item => {

                            if (
                                String(item.id) ===
                                String(q.id)
                            ) {
                                skip = true;
                                return;
                            }

                            if (
                                String(item.id) ===
                                String(target)
                            ) {
                                skip = false;
                            }

                            if (skip) {
                                visible[item.id] = false;
                            }
                        }
                    );
                }
            );

            App.state.visibleQuestions =
                visible;
        },

        validateResponse: function () {

            const survey =
                App.state.currentSurvey;

            if (!survey) {
                return false;
            }

            App.actions.updateBranchVisibility();

            for (const group of survey.groups) {
                for (const q of group.questions) {

                    if (
                        !q.required ||
                        App.state.visibleQuestions[q.id] === false
                    ) {
                        continue;
                    }

                    const answer =
                        App.state.answers[q.id];

                    if (
                        answer === undefined ||
                        answer === null ||
                        answer === ''
                    ) {
                        return false;
                    }
                }
            }

            return true;
        },

        showSettings: async function () {
            App.state.screen = 'settings';

            try {
                await App.api.load();
            } catch (e) {
                App.utils.notice(
                    e.message,
                    'error'
                );
            }

            App.render.current();
        },

        saveKintoneSettings: async function () {

            const payload = {
                subdomain:
                    document.getElementById(
                        'setting_subdomain'
                    ).value,

                app_id:
                    document.getElementById(
                        'setting_app_id'
                    ).value,

                login_name:
                    document.getElementById(
                        'setting_login_name'
                    ).value,

                password:
                    document.getElementById(
                        'setting_password'
                    ).value,

                proxy:
                    document.getElementById(
                        'setting_proxy'
                    ).value,

                ssl_verify:
                    document.getElementById(
                        'setting_ssl_verify'
                    ).checked,

                field_company:
                    document.getElementById(
                        'field_company'
                    ).value,

                field_name:
                    document.getElementById(
                        'field_name'
                    ).value,

                field_email:
                    document.getElementById(
                        'field_email'
                    ).value,

                field_department:
                    document.getElementById(
                        'field_department'
                    ).value,

                field_phone:
                    document.getElementById(
                        'field_phone'
                    ).value,

                field_address:
                    document.getElementById(
                        'field_address'
                    ).value
            };

            const message =
                document.getElementById(
                    'kintone_message'
                );

            try {

                const json =
                    await App.api.request(
                        'save_kintone_settings',
                        payload
                    );

                message.innerHTML = `
                    <div class="p-4 rounded-lg bg-green-50 text-green-700">
                        ${App.utils.escape(
                            json.message ||
                            'キントーン設定を保存しました。'
                        )}
                    </div>
                `;

                await App.api.load();
                App.render.settings();

            } catch (e) {

                message.innerHTML = `
                    <div class="p-4 rounded-lg bg-red-50 text-red-700">
                        <strong>保存失敗</strong><br>
                        ${App.utils.escape(e.message)}
                    </div>
                `;
            }
        },

        saveSmtpSettings: async function () {

            const payload = {
                smtp_server:
                    document.getElementById(
                        'smtp_server'
                    ).value,

                smtp_port:
                    document.getElementById(
                        'smtp_port'
                    ).value,

                smtp_encryption:
                    document.getElementById(
                        'smtp_encryption'
                    ).value,

                smtp_auth:
                    document.getElementById(
                        'smtp_auth'
                    ).checked,

                smtp_username:
                    document.getElementById(
                        'smtp_username'
                    ).value,

                smtp_password:
                    document.getElementById(
                        'smtp_password'
                    ).value,

                smtp_from_email:
                    document.getElementById(
                        'smtp_from_email'
                    ).value,

                smtp_from_name:
                    document.getElementById(
                        'smtp_from_name'
                    ).value,

                smtp_timeout:
                    document.getElementById(
                        'smtp_timeout'
                    ).value
            };

            const message =
                document.getElementById(
                    'smtp_message'
                );

            try {

                const json =
                    await App.api.request(
                        'save_smtp_settings',
                        payload
                    );

                message.innerHTML = `
                    <div class="p-4 rounded-lg bg-green-50 text-green-700">
                        ${App.utils.escape(
                            json.message ||
                            'SMTP設定を保存しました。'
                        )}
                    </div>
                `;

                await App.api.load();
                App.render.settings();

            } catch (e) {

                message.innerHTML = `
                    <div class="p-4 rounded-lg bg-red-50 text-red-700">
                        <strong>保存失敗</strong><br>
                        ${App.utils.escape(e.message)}
                    </div>
                `;
            }
        },

        connectKintone: async function () {

            const message =
                document.getElementById(
                    'kintone_message'
                );

            try {

                const json =
                    await App.api.request(
                        'connect_kintone'
                    );

                message.innerHTML = `
                    <div class="p-4 rounded-lg bg-green-50 text-green-700">
                        <strong>接続成功</strong><br>
                        接続先：
                        ${App.utils.escape(
                            App.state.settings.kintone.subdomain ||
                            ''
                        )}.cybozu.com<br>
                        HTTPステータス：
                        ${App.utils.escape(
                            json.http_status || ''
                        )}
                    </div>
                `;

            } catch (e) {

                message.innerHTML =
                    App.actions.externalErrorHtml(
                        'キントーン接続確認',
                        e
                    );
            }
        },

        fetchKintoneFields: async function () {

            const message =
                document.getElementById(
                    'kintone_message'
                );

            try {

                const json =
                    await App.api.request(
                        'fetch_kintone_fields'
                    );

                const rows =
                    (json.fields || [])
                        .map(
                            field => `
                                <tr class="border-t">
                                    <td class="p-2">
                                        ${App.utils.escape(field.label)}
                                    </td>
                                    <td class="p-2">
                                        ${App.utils.escape(field.code)}
                                    </td>
                                    <td class="p-2">
                                        ${App.utils.escape(field.type)}
                                    </td>
                                </tr>
                            `
                        ).join('');

                message.innerHTML = `
                    <div class="p-4 rounded-lg bg-green-50 text-green-700">
                        <strong>フィールド取得成功</strong>
                        <div class="overflow-x-auto mt-3">
                            <table class="w-full bg-white text-gray-900">
                                <thead>
                                    <tr>
                                        <th class="p-2 text-left">label</th>
                                        <th class="p-2 text-left">code</th>
                                        <th class="p-2 text-left">type</th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                    </div>
                `;

            } catch (e) {

                message.innerHTML =
                    App.actions.externalErrorHtml(
                        'キントーンフィールド取得',
                        e
                    );
            }
        },

        syncCustomers: async function () {

            const message =
                document.getElementById(
                    'kintone_message'
                );

            try {

                const json =
                    await App.api.request(
                        'sync_customers'
                    );

                message.innerHTML = `
                    <div class="p-4 rounded-lg bg-green-50 text-green-700">
                        <strong>顧客データ同期成功</strong><br>
                        取得件数：${Number(json.count || 0)}<br>
                        追加件数：${Number(json.inserted || 0)}<br>
                        更新件数：${Number(json.updated || 0)}<br>
                        スキップ件数：${Number(json.skipped || 0)}<br>
                        エラー件数：${Number(json.errors || 0)}
                    </div>
                `;

            } catch (e) {

                message.innerHTML =
                    App.actions.externalErrorHtml(
                        '顧客データ同期',
                        e
                    );
            }
        },

        testSmtpConnection: async function () {

            const message =
                document.getElementById(
                    'smtp_message'
                );

            try {

                const json =
                    await App.api.request(
                        'test_smtp_connection'
                    );

                message.innerHTML = `
                    <div class="p-4 rounded-lg bg-green-50 text-green-700">
                        <strong>SMTP接続成功</strong><br>
                        SMTPサーバ：
                        ${App.utils.escape(
                            json.smtp_server || ''
                        )}<br>
                        ポート：
                        ${Number(json.smtp_port || 0)}<br>
                        暗号化：
                        ${App.utils.escape(
                            json.smtp_encryption || ''
                        )}<br>
                        認証結果：成功
                    </div>
                `;

            } catch (e) {

                message.innerHTML =
                    App.actions.externalErrorHtml(
                        'SMTP接続確認',
                        e
                    );
            }
        },

        sendSmtpTest: async function () {

            const recipient =
                prompt(
                    'テストメールの宛先メールアドレスを入力してください。'
                );

            if (!recipient) {
                return;
            }

            const message =
                document.getElementById(
                    'smtp_message'
                );

            try {

                const json =
                    await App.api.request(
                        'send_smtp_test',
                        {
                            test_recipient:
                                recipient
                        }
                    );

                message.innerHTML = `
                    <div class="p-4 rounded-lg bg-green-50 text-green-700">
                        <strong>テストメール送信成功</strong><br>
                        送信済みです。<br>
                        宛先：
                        ${App.utils.escape(
                            json.recipient || recipient
                        )}<br>
                        SMTP応答：
                        ${Number(json.smtp_code || 0)}
                    </div>
                `;

            } catch (e) {

                message.innerHTML =
                    App.actions.externalErrorHtml(
                        'SMTPテスト送信',
                        e
                    );
            }
        },

        externalErrorHtml: function (
            processName,
            error
        ) {

            const checks =
                Array.isArray(error.check_items)
                    ? error.check_items
                    : [];

            return `
                <div class="p-4 rounded-lg bg-red-50 text-red-700">
                    <strong>
                        ${App.utils.escape(processName)}
                    </strong><br>

                    状態：失敗<br>

                    ${
                        error.http_status
                            ? `HTTPステータス：
                               ${App.utils.escape(error.http_status)}<br>`
                            : ''
                    }

                    ${
                        error.smtp_code
                            ? `SMTP応答コード：
                               ${App.utils.escape(error.smtp_code)}<br>`
                            : ''
                    }

                    ${
                        error.error_type
                            ? `エラー種別：
                               ${App.utils.escape(error.error_type)}<br>`
                            : ''
                    }

                    内容：
                    ${App.utils.escape(
                        error.message ||
                        '外部サービスとの通信に失敗しました。'
                    )}

                    ${
                        checks.length
                            ? `
                                <div class="mt-3">
                                    <strong>確認事項：</strong>
                                    <ul class="list-disc ml-5">
                                        ${
                                            checks.map(
                                                item =>
                                                    `<li>${App.utils.escape(item)}</li>`
                                            ).join('')
                                        }
                                    </ul>
                                </div>
                            `
                            : ''
                    }
                </div>
            `;
        },

        showAggregate: function (id) {

            const survey =
                App.state.surveys.find(
                    s => String(s.id) === String(id)
                );

            if (!survey) {
                return;
            }

            const responses =
                App.state.responses.filter(
                    r =>
                        String(r.survey_id) ===
                        String(id) &&
                        !r.deleted
                );

            const modal =
                document.createElement('div');

            modal.id = 'response_modal';

            modal.className =
                'fixed inset-0 z-50 bg-black/50 ' +
                'flex items-center justify-center p-4';

            modal.innerHTML = `
                <div
                    class="bg-white rounded-xl shadow-xl
                           max-w-5xl w-full max-h-[90vh]
                           overflow-auto"
                >
                    <div
                        class="sticky top-0 bg-white border-b
                               p-4 flex justify-between"
                    >
                        <h2 class="font-bold">
                            集計：${App.utils.escape(
                                survey.title
                            )}
                        </h2>

                        <button
                            onclick="this.closest('#response_modal').remove()"
                        >
                            ×
                        </button>
                    </div>

                    <div class="p-6">
                        <p class="mb-4">
                            回答件数：
                            <strong>${responses.length}</strong>
                        </p>

                        ${
                            survey.groups.map(
                                group =>
                                    group.questions.map(
                                        q => {

                                            const values =
                                                responses
                                                    .map(
                                                        r =>
                                                            r.answers?.[q.id]
                                                    )
                                                    .filter(
                                                        v =>
                                                            v !== undefined &&
                                                            v !== ''
                                                    );

                                            return `
                                                <div
                                                    class="border rounded-lg p-4 mb-3"
                                                >
                                                    <div class="font-semibold">
                                                        ${App.utils.escape(q.number)}
                                                        ${App.utils.escape(q.text)}
                                                    </div>

                                                    <div class="mt-2 text-sm">
                                                        回答数：
                                                        ${values.length}
                                                    </div>

                                                    ${
                                                        q.type !== 'text'
                                                            ? `
                                                                <div class="mt-2">
                                                                    ${
                                                                        q.options.map(
                                                                            option => {

                                                                                const id =
                                                                                    typeof option === 'object'
                                                                                        ? option.id
                                                                                        : '';

                                                                                const text =
                                                                                    typeof option === 'object'
                                                                                        ? option.text
                                                                                        : option;

                                                                                const count =
                                                                                    values.filter(
                                                                                        value =>
                                                                                            value === id ||
                                                                                            value === text
                                                                                    ).length;

                                                                                return `
                                                                                    <div class="flex justify-between border-t py-2">
                                                                                        <span>
                                                                                            ${App.utils.escape(text)}
                                                                                        </span>
                                                                                        <strong>${count}</strong>
                                                                                    </div>
                                                                                `;
                                                                            }
                                                                        ).join('')
                                                                    }
                                                                </div>
                                                            `
                                                            : ''
                                                    }
                                                </div>
                                            `;
                                        }
                                    ).join('')
                            ).join('')
                        }
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
        },

        showMail: function (id) {

            const modal =
                document.createElement('div');

            modal.id = 'response_modal';

            modal.className =
                'fixed inset-0 z-50 bg-black/50 ' +
                'flex items-center justify-center p-4';

            modal.innerHTML = `
                <div
                    class="bg-white rounded-xl shadow-xl
                           max-w-2xl w-full"
                >
                    <div class="p-4 border-b flex justify-between">
                        <h2 class="font-bold">
                            メール送信
                        </h2>
                        <button
                            onclick="this.closest('#response_modal').remove()"
                        >
                            ×
                        </button>
                    </div>

                    <div class="p-6">

                        <label class="block mb-4">
                            <span class="block text-sm mb-1">
                                件名
                            </span>
                            <input
                                id="mail_subject"
                                class="w-full border rounded-lg px-3 py-2"
                                value="アンケートのご案内"
                            >
                        </label>

                        <label class="block mb-4">
                            <span class="block text-sm mb-1">
                                本文
                            </span>
                            <textarea
                                id="mail_body"
                                class="w-full border rounded-lg px-3 py-2"
                                rows="8"
                            >アンケートへのご協力をお願いいたします。</textarea>
                        </label>

                        <button
                            class="bg-blue-600 text-white px-4 py-2 rounded-lg"
                            onclick="App.actions.sendMail('${App.utils.escape(id)}')"
                        >
                            一括送信
                        </button>

                    </div>
                </div>
            `;

            document.body.appendChild(modal);
        },

        sendMail: async function (surveyId) {

            const subject =
                document.getElementById(
                    'mail_subject'
                )?.value || '';

            const body =
                document.getElementById(
                    'mail_body'
                )?.value || '';

            if (!subject || !body) {
                App.utils.notice(
                    '件名と本文を入力してください。',
                    'error'
                );
                return;
            }

            App.utils.notice(
                '送信対象の準備が完了しました。'
            );
        },

        logout: function () {
            window.location.href =
                window.location.pathname;
        }
    }
};

if (document.readyState === 'loading') {

    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        {once: true}
    );

} else {

    App.init();
}
</script>

</body>
</html>
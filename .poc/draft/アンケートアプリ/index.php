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

function storageDir(): string {
    return __DIR__ . '/survey_storage';
}

function storageFile(): string {
    return storageDir() . '/survey_data.json';
}

function initialData(): array {
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [
            'kintone' => [],
            'smtp' => []
        ],
        'mail_logs' => []
    ];
}

function loadData(): array {
    $file = storageFile();

    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
    }

    if (!is_file($file)) {
        $data = initialData();
        saveData($data);
        return $data;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        $data = initialData();
        saveData($data);
        return $data;
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
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

function saveData(array $data): bool {
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

    $tmp = $dir . '/survey_data.tmp.' . bin2hex(random_bytes(8));

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

function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function csrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requireCsrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals(csrf(), $token)) {
        jsonResponse([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }
}

function id(string $prefix): string {
    return $prefix . '_' .
        date('YmdHis') . '_' .
        bin2hex(random_bytes(6));
}

function safeSubdomain(string $value): ?string {
    $value = trim($value);

    /*
     * kintoneのサブドメインはURLではない。
     * https:// や http:// を入力させない。
     */
    if ($value === '' ||
        strlen($value) > 63 ||
        !preg_match(
            '/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/',
            $value
        )) {
        return null;
    }

    return strtolower($value);
}

function safeProxy(string $value): ?string {
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    /*
     * Proxyはhttp://またはhttps://を明示したURL。
     */
    if (!preg_match(
        '#^https?://[^\s/$.?#].[^\s]*$#i',
        $value
    )) {
        return null;
    }

    $parts = parse_url($value);

    if ($parts === false ||
        empty($parts['scheme']) ||
        !in_array(
            strtolower($parts['scheme']),
            ['http', 'https'],
            true
        ) ||
        empty($parts['host'])) {
        return null;
    }

    return $value;
}

function validateKintone(array $input, array $old): array {
    $subdomain = safeSubdomain(
        (string)($input['subdomain'] ?? '')
    );

    if ($subdomain === null) {
        return [
            'ok' => false,
            'message' =>
                'サブドメインはkintoneのサブドメイン名を入力してください。'
        ];
    }

    $login = trim((string)($input['login_name'] ?? ''));

    if ($login === '') {
        return [
            'ok' => false,
            'message' => 'ログイン名を入力してください。'
        ];
    }

    $password = (string)($input['password'] ?? '');

    if ($password === '' &&
        !empty($old['password'])) {
        $password = (string)$old['password'];
    }

    if ($password === '') {
        return [
            'ok' => false,
            'message' => 'パスワードを入力してください。'
        ];
    }

    $appId = filter_var(
        $input['app_id'] ?? '',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($appId === false) {
        return [
            'ok' => false,
            'message' => '顧客管理アプリIDは1以上の整数で入力してください。'
        ];
    }

    $proxy = safeProxy(
        (string)($input['proxy'] ?? '')
    );

    if ($proxy === null) {
        return [
            'ok' => false,
            'message' =>
                'Proxyはhttp://またはhttps://で始まる有効なURL形式で入力してください。'
        ];
    }

    return [
        'ok' => true,
        'data' => [
            'subdomain' => $subdomain,
            'login_name' => $login,
            'password' => $password,
            'app_id' => (int)$appId,
            'ssl_verify' => !empty($input['ssl_verify']),
            'proxy' => $proxy,
            'field_company' => trim((string)($input['field_company'] ?? '')),
            'field_name' => trim((string)($input['field_name'] ?? '')),
            'field_email' => trim((string)($input['field_email'] ?? '')),
            'field_department' => trim((string)($input['field_department'] ?? '')),
            'field_phone' => trim((string)($input['field_phone'] ?? '')),
            'field_address' => trim((string)($input['field_address'] ?? ''))
        ]
    ];
}

function validateSmtp(array $input, array $old): array {
    $server = trim((string)($input['smtp_server'] ?? ''));
    $port = filter_var(
        $input['smtp_port'] ?? '',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]
    );

    if ($server === '') {
        return ['ok' => false, 'message' => 'SMTPサーバを入力してください。'];
    }

    if ($port === false) {
        return ['ok' => false, 'message' => 'SMTPポートが不正です。'];
    }

    $encryption = (string)($input['smtp_encryption'] ?? 'none');

    if (!in_array($encryption, ['none', 'starttls', 'ssl'], true)) {
        return ['ok' => false, 'message' => '暗号化方式が不正です。'];
    }

    $auth = !empty($input['smtp_auth']);
    $username = trim((string)($input['smtp_username'] ?? ''));
    $password = (string)($input['smtp_password'] ?? '');

    if ($password === '' && !empty($old['smtp_password'])) {
        $password = (string)$old['smtp_password'];
    }

    if ($auth && $username === '') {
        return [
            'ok' => false,
            'message' => 'SMTP認証を有効にする場合はユーザー名が必要です。'
        ];
    }

    if ($auth && $password === '') {
        return [
            'ok' => false,
            'message' => 'SMTP認証を有効にする場合はパスワードが必要です。'
        ];
    }

    $from = trim((string)($input['smtp_from_email'] ?? ''));

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok' => false,
            'message' => '送信元メールアドレスが不正です。'
        ];
    }

    $timeout = filter_var(
        $input['smtp_timeout'] ?? 10,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 300]]
    );

    if ($timeout === false) {
        return [
            'ok' => false,
            'message' => '接続タイムアウトは1～300秒で指定してください。'
        ];
    }

    return [
        'ok' => true,
        'data' => [
            'smtp_server' => $server,
            'smtp_port' => (int)$port,
            'smtp_encryption' => $encryption,
            'smtp_auth' => $auth,
            'smtp_username' => $username,
            'smtp_password' => $password,
            'smtp_from_email' => $from,
            'smtp_from_name' =>
                trim((string)($input['smtp_from_name'] ?? '')),
            'smtp_timeout' => (int)$timeout
        ]
    ];
}

function kintoneUrl(array $config, string $path): string {
    return 'https://' .
        $config['subdomain'] .
        '.cybozu.com/k/v1/' .
        ltrim($path, '/');
}

function kintoneRequest(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $url = kintoneUrl($config, $path);

    $ch = curl_init($url);

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json'
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => !empty($config['ssl_verify']),
        CURLOPT_SSL_VERIFYHOST => !empty($config['ssl_verify']) ? 2 : 0
    ];

    if (!empty($config['proxy'])) {
        $options[CURLOPT_PROXY] = $config['proxy'];
    }

    /*
     * kintoneログイン名・パスワードによる認証。
     * APIレスポンスには絶対に返さない。
     */
    $options[CURLOPT_USERPWD] =
        $config['login_name'] . ':' . $config['password'];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    curl_setopt_array($ch, $options);

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

        return [
            'ok' => false,
            'error_type' => $type,
            'http_status' => $status,
            'message' => match ($type) {
                'dns' => 'kintoneホストのDNS解決に失敗しました。',
                'timeout' => 'kintoneへの接続がタイムアウトしました。',
                'tls' => 'kintoneとのTLS/SSL接続に失敗しました。',
                default => 'kintoneへの接続に失敗しました。'
            },
            'detail' => $error
        ];
    }

    $decoded = json_decode($response, true);

    if ($status < 200 || $status >= 300) {
        $safe = is_array($decoded)
            ? (string)($decoded['message'] ?? '')
            : '';

        $type = 'api';

        if ($status === 401) {
            $type = 'authentication';
        } elseif ($status === 403) {
            $type = 'authorization';
        } elseif ($status >= 400 && $status < 500) {
            $type = 'http_4xx';
        } elseif ($status >= 500) {
            $type = 'http_5xx';
        }

        return [
            'ok' => false,
            'error_type' => $type,
            'http_status' => $status,
            'message' => $safe !== ''
                ? $safe
                : 'kintone APIがエラーを返しました。'
        ];
    }

    return [
        'ok' => true,
        'http_status' => $status,
        'data' => is_array($decoded) ? $decoded : []
    ];
}

function smtpRead($socket): array {
    $response = '';

    while (($line = fgets($socket, 4096)) !== false) {
        $response .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    $code = 0;

    if (preg_match('/^(\d{3})/', $response, $m)) {
        $code = (int)$m[1];
    }

    return [
        'code' => $code,
        'response' => trim($response)
    ];
}

function smtpCommand($socket, string $command): array {
    fwrite($socket, $command . "\r\n");
    return smtpRead($socket);
}

function smtpConnect(array $config): array {
    $server = $config['smtp_server'];
    $port = (int)$config['smtp_port'];
    $timeout = (int)$config['smtp_timeout'];

    $target = $server;

    if ($config['smtp_encryption'] === 'ssl') {
        $target = 'ssl://' . $server;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return [
            'ok' => false,
            'error_type' => 'connection',
            'smtp_code' => null,
            'message' => 'SMTPサーバへ接続できませんでした。',
            'detail' => $errstr
        ];
    }

    stream_set_timeout($socket, $timeout);

    $greeting = smtpRead($socket);

    if ($greeting['code'] < 200 || $greeting['code'] >= 400) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $greeting['code'],
            'message' => 'SMTPサーバが接続を拒否しました。'
        ];
    }

    $host = $_SERVER['SERVER_NAME'] ?? 'localhost';

    $ehlo = smtpCommand($socket, 'EHLO ' . $host);

    if ($ehlo['code'] >= 400) {
        $ehlo = smtpCommand($socket, 'HELO ' . $host);
    }

    if ($ehlo['code'] >= 400) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_protocol',
            'smtp_code' => $ehlo['code'],
            'message' => 'SMTP EHLO/HELOに失敗しました。'
        ];
    }

    if ($config['smtp_encryption'] === 'starttls') {
        $tls = smtpCommand($socket, 'STARTTLS');

        if ($tls['code'] !== 220) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'tls',
                'smtp_code' => $tls['code'],
                'message' => 'SMTP STARTTLSに失敗しました。'
            ];
        }

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'tls',
                'smtp_code' => null,
                'message' => 'SMTP TLSネゴシエーションに失敗しました。'
            ];
        }

        $ehlo = smtpCommand($socket, 'EHLO ' . $host);

        if ($ehlo['code'] >= 400) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'smtp_protocol',
                'smtp_code' => $ehlo['code'],
                'message' => 'TLS後のEHLOに失敗しました。'
            ];
        }
    }

    if (!empty($config['smtp_auth'])) {
        $auth = smtpCommand($socket, 'AUTH LOGIN');

        if ($auth['code'] !== 334) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'authentication',
                'smtp_code' => $auth['code'],
                'message' => 'SMTP認証を開始できませんでした。'
            ];
        }

        $user = smtpCommand(
            $socket,
            base64_encode($config['smtp_username'])
        );

        if ($user['code'] !== 334) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'authentication',
                'smtp_code' => $user['code'],
                'message' => 'SMTPユーザー名の認証に失敗しました。'
            ];
        }

        $pass = smtpCommand(
            $socket,
            base64_encode($config['smtp_password'])
        );

        if ($pass['code'] < 200 || $pass['code'] >= 300) {
            fclose($socket);

            return [
                'ok' => false,
                'error_type' => 'authentication',
                'smtp_code' => $pass['code'],
                'message' => 'SMTP認証に失敗しました。'
            ];
        }
    }

    return [
        'ok' => true,
        'socket' => $socket,
        'greeting' => $greeting['code'],
        'ehlo' => $ehlo['code']
    ];
}

function smtpSend(
    array $config,
    string $recipient,
    string $subject,
    string $body
): array {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok' => false,
            'error_type' => 'configuration',
            'message' => 'テスト宛先メールアドレスが不正です。'
        ];
    }

    $conn = smtpConnect($config);

    if (!$conn['ok']) {
        return $conn;
    }

    $socket = $conn['socket'];

    $r = smtpCommand(
        $socket,
        'MAIL FROM:<' . $config['smtp_from_email'] . '>'
    );

    if ($r['code'] < 200 || $r['code'] >= 300) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $r['code'],
            'message' => 'MAIL FROMが拒否されました。'
        ];
    }

    $r = smtpCommand(
        $socket,
        'RCPT TO:<' . $recipient . '>'
    );

    if ($r['code'] < 200 || $r['code'] >= 300) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $r['code'],
            'message' => '宛先がSMTPサーバに拒否されました。'
        ];
    }

    $r = smtpCommand($socket, 'DATA');

    if ($r['code'] !== 354) {
        fclose($socket);

        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $r['code'],
            'message' => 'SMTP DATAを開始できませんでした。'
        ];
    }

    $fromName = $config['smtp_from_name'] !== ''
        ? '=?UTF-8?B?' .
          base64_encode($config['smtp_from_name']) .
          '?='
        : $config['smtp_from_email'];

    $encodedSubject =
        '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $message =
        'From: ' . $fromName .
        ' <' . $config['smtp_from_email'] . ">\r\n" .
        'To: <' . $recipient . ">\r\n" .
        'Subject: ' . $encodedSubject . "\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: 8bit\r\n" .
        "\r\n" .
        str_replace("\n", "\r\n", $body) .
        "\r\n.\r\n";

    fwrite($socket, $message);

    $r = smtpRead($socket);

    smtpCommand($socket, 'QUIT');
    fclose($socket);

    if ($r['code'] < 200 || $r['code'] >= 300) {
        return [
            'ok' => false,
            'error_type' => 'smtp_response',
            'smtp_code' => $r['code'],
            'message' => 'メール送信がSMTPサーバに拒否されました。'
        ];
    }

    return [
        'ok' => true,
        'smtp_code' => $r['code']
    ];
}

function normalizeSurvey(array $survey): array {
    $survey['id'] = (string)($survey['id'] ?? id('survey'));
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
        $group['id'] = (string)($group['id'] ?? id('group'));
        $group['name'] = (string)($group['name'] ?? 'グループ');

        if (!isset($group['questions']) ||
            !is_array($group['questions'])) {
            $group['questions'] = [];
        }

        foreach ($group['questions'] as &$question) {
            $question['id'] =
                (string)($question['id'] ?? id('question'));

            $question['text'] =
                (string)($question['text'] ?? '');

            $question['type'] = in_array(
                $question['type'] ?? 'text',
                ['text', 'single', 'multiple'],
                true
            ) ? $question['type'] : 'text';

            $question['required'] = !empty($question['required']);

            $question['options'] =
                isset($question['options']) &&
                is_array($question['options'])
                    ? array_values($question['options'])
                    : [];

            foreach ($question['options'] as &$option) {
                if (is_string($option)) {
                    $option = [
                        'id' => id('option'),
                        'text' => $option
                    ];
                }

                if (!is_array($option)) {
                    $option = [
                        'id' => id('option'),
                        'text' => ''
                    ];
                }

                $option['id'] =
                    (string)($option['id'] ?? id('option'));

                $option['text'] =
                    (string)($option['text'] ?? '');
            }

            unset($option);

            $question['other_enabled'] =
                !empty($question['other_enabled']);

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

function renumberSurvey(array &$survey): void {
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            $question['number'] =
                $survey['numbering_mode'] === 'group'
                    ? 'Q' . $groupNo . '-' . $questionNo
                    : 'Q' . $global;

            $questionNo++;
            $global++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);

    /*
     * 削除・移動後に存在しない分岐先を除去。
     * 分岐先は後続質問だけ。
     */
    $flat = [];

    foreach ($survey['groups'] as $gIndex => $group) {
        foreach ($group['questions'] as $qIndex => $question) {
            $flat[] = [
                'id' => $question['id'],
                'index' => count($flat)
            ];
        }
    }

    $position = [];

    foreach ($flat as $item) {
        $position[$item['id']] = $item['index'];
    }

    $flatCount = count($flat);
    $currentIndex = 0;

    foreach ($survey['groups'] as &$group) {
        foreach ($group['questions'] as &$question) {
            $allowed = [];

            if (!empty($question['branching'])) {
                foreach ($question['branching'] as $optionId => $targetId) {
                    if ($targetId === null || $targetId === '') {
                        $allowed[$optionId] = null;
                        continue;
                    }

                    $current = $position[$question['id']] ?? 0;
                    $target = $position[$targetId] ?? -1;

                    $allowed[$optionId] =
                        $target > $current
                            ? $targetId
                            : null;
                }
            }

            $question['branching'] = $allowed;
            $currentIndex++;
        }

        unset($question);
    }

    unset($group);
}

function findSurvey(array &$data, string $surveyId): ?int {
    foreach ($data['surveys'] as $i => $survey) {
        if ((string)($survey['id'] ?? '') === $surveyId) {
            return $i;
        }
    }

    return null;
}

function publicSettings(array $settings): array {
    $result = $settings;

    if (isset($result['kintone']['password'])) {
        unset($result['kintone']['password']);
        $result['kintone']['password_configured'] = true;
    }

    if (isset($result['smtp']['smtp_password'])) {
        unset($result['smtp']['smtp_password']);
        $result['smtp']['smtp_password_configured'] = true;
    }

    return $result;
}

function handleApi(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === '') {
        return;
    }

    requireCsrf();

    $data = loadData();

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
                'surveys' => $surveys
            ]);

        case 'get_data':
            jsonResponse([
                'ok' => true,
                'surveys' => $data['surveys'],
                'responses' => $data['responses'],
                'customers' => $data['customers'],
                'settings' => publicSettings($data['settings']),
                'mail_logs' => $data['mail_logs']
            ]);

        case 'save_survey':
            $raw = (string)($_POST['survey_json'] ?? '');

            try {
                $survey = json_decode(
                    $raw,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'アンケートJSONが不正です。'
                ], 400);
            }

            if (!is_array($survey)) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            if (!in_array(
                $survey['status'] ?? '',
                ['draft', 'active', 'ended'],
                true
            )) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'ステータスはdraft、active、endedのいずれかです。'
                ], 400);
            }

            $survey = normalizeSurvey($survey);

            $index = findSurvey($data, $survey['id']);

            if ($index === null) {
                $data['surveys'][] = $survey;
            } else {
                $data['surveys'][$index] = $survey;
            }

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'アンケートの保存に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'survey' => $survey
            ]);

        case 'duplicate_survey':
            $index = findSurvey(
                $data,
                (string)($_POST['survey_id'] ?? '')
            );

            if ($index === null) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。'
                ], 404);
            }

            $copy = $data['surveys'][$index];

            $oldQuestionIds = [];
            $newQuestionIds = [];

            $copy['id'] = id('survey');
            $copy['title'] .= '（複製）';
            $copy['status'] = 'draft';
            $copy['deleted'] = false;
            $copy['created_at'] = date('c');
            $copy['updated_at'] = date('c');
            $copy['public_token'] = bin2hex(random_bytes(24));

            foreach ($copy['groups'] as &$group) {
                $group['id'] = id('group');

                foreach ($group['questions'] as &$question) {
                    $old = $question['id'];
                    $new = id('question');

                    $oldQuestionIds[] = $old;
                    $newQuestionIds[] = $new;

                    $question['id'] = $new;

                    foreach ($question['options'] as &$option) {
                        $option['id'] = id('option');
                    }

                    unset($option);
                }

                unset($question);
            }

            unset($group);

            $map = array_combine(
                $oldQuestionIds,
                $newQuestionIds
            );

            foreach ($copy['groups'] as &$group) {
                foreach ($group['questions'] as &$question) {
                    foreach ($question['branching'] as $optionId => $target) {
                        if ($target !== null &&
                            isset($map[$target])) {
                            $question['branching'][$optionId] =
                                $map[$target];
                        }
                    }
                }

                unset($question);
            }

            unset($group);

            $copy = normalizeSurvey($copy);
            $data['surveys'][] = $copy;

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'アンケートの複製に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'survey' => $copy
            ]);

        case 'delete_survey':
            $index = findSurvey(
                $data,
                (string)($_POST['survey_id'] ?? '')
            );

            if ($index === null) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。'
                ], 404);
            }

            $data['surveys'][$index]['deleted'] = true;
            $data['surveys'][$index]['updated_at'] = date('c');

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' => 'アンケートの削除に失敗しました。'
                ], 500);
            }

            jsonResponse(['ok' => true]);

        case 'save_kintone_settings':
            $validation = validateKintone(
                $_POST,
                $data['settings']['kintone']
            );

            if (!$validation['ok']) {
                jsonResponse($validation, 400);
            }

            $oldSettings = $data['settings'];

            $data['settings']['kintone'] =
                $validation['data'];

            if (!saveData($data)) {
                $data['settings'] = $oldSettings;

                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'キントーン設定の保存に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'message' => 'キントーン設定を保存しました。',
                'settings' => publicSettings($data['settings'])
            ]);

        case 'save_smtp_settings':
            $validation = validateSmtp(
                $_POST,
                $data['settings']['smtp']
            );

            if (!$validation['ok']) {
                jsonResponse($validation, 400);
            }

            $oldSettings = $data['settings'];

            $data['settings']['smtp'] =
                $validation['data'];

            if (!saveData($data)) {
                $data['settings'] = $oldSettings;

                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'SMTP設定の保存に失敗しました。'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'message' => 'SMTP設定を保存しました。',
                'settings' => publicSettings($data['settings'])
            ]);

        case 'connect_kintone':
            $config = $data['settings']['kintone'];

            if (empty($config['subdomain']) ||
                empty($config['login_name']) ||
                empty($config['password']) ||
                empty($config['app_id'])) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '保存済みキントーン設定が不足しています。',
                    'error_type' => 'configuration',
                    'check_items' => [
                        'サブドメイン',
                        'ログイン名',
                        'パスワード',
                        '顧客管理アプリID'
                    ]
                ], 400);
            }

            $result = kintoneRequest(
                $config,
                'GET',
                'app.json?id=' . rawurlencode((string)$config['app_id'])
            );

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' => $result['message'],
                    'error_type' => $result['error_type'],
                    'http_status' => $result['http_status'] ?? null,
                    'check_items' => [
                        'サブドメイン',
                        'ログイン名',
                        'パスワード',
                        'kintone側の認証設定',
                        'Proxy',
                        'SSL証明書検証'
                    ]
                ], 502);
            }

            jsonResponse([
                'ok' => true,
                'message' => 'キントーンへの接続に成功しました。',
                'subdomain' => $config['subdomain'],
                'app_id' => (int)$config['app_id'],
                'http_status' => $result['http_status']
            ]);

        case 'fetch_kintone_fields':
            $config = $data['settings']['kintone'];

            if (empty($config['subdomain']) ||
                empty($config['app_id']) ||
                empty($config['password'])) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '保存済みキントーン設定が不足しています。',
                    'error_type' => 'configuration'
                ], 400);
            }

            $result = kintoneRequest(
                $config,
                'GET',
                'app/form/fields.json?app=' .
                rawurlencode((string)$config['app_id'])
            );

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' => $result['message'],
                    'error_type' => $result['error_type'],
                    'http_status' => $result['http_status'] ?? null,
                    'check_items' => [
                        '顧客管理アプリID',
                        'kintone権限',
                        'ログイン情報',
                        'Proxy',
                        'SSL証明書検証'
                    ]
                ], 502);
            }

            $fields = [];

            foreach (($result['data']['properties'] ?? []) as $field) {
                $fields[] = [
                    'label' => (string)($field['label'] ?? ''),
                    'code' => (string)($field['code'] ?? ''),
                    'type' => (string)($field['type'] ?? '')
                ];
            }

            jsonResponse([
                'ok' => true,
                'message' => 'フィールドを取得しました。',
                'fields' => $fields,
                'http_status' => $result['http_status']
            ]);

        case 'sync_customers':
            $config = $data['settings']['kintone'];

            if (empty($config['app_id']) ||
                empty($config['password'])) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        '保存済みキントーン設定が不足しています。',
                    'error_type' => 'configuration'
                ], 400);
            }

            $result = kintoneRequest(
                $config,
                'GET',
                'records.json?app=' .
                rawurlencode((string)$config['app_id']) .
                '&totalCount=true'
            );

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' => $result['message'],
                    'error_type' => $result['error_type'],
                    'http_status' => $result['http_status'] ?? null
                ], 502);
            }

            $records =
                is_array($result['data']['records'] ?? null)
                    ? $result['data']['records']
                    : [];

            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;

            $fieldMap = [
                'company' =>
                    $config['field_company'] ?? '',
                'name' =>
                    $config['field_name'] ?? '',
                'email' =>
                    $config['field_email'] ?? '',
                'department' =>
                    $config['field_department'] ?? '',
                'phone' =>
                    $config['field_phone'] ?? '',
                'address' =>
                    $config['field_address'] ?? ''
            ];

            foreach ($records as $record) {
                try {
                    $customer = [
                        'id' => (string)(
                            $record['$id']['value']
                            ?? id('customer')
                        ),
                        'kintone_id' =>
                            (string)(
                                $record['$id']['value'] ?? ''
                            ),
                        'company' =>
                            (string)(
                                $record[
                                    $fieldMap['company']
                                ]['value'] ?? ''
                            ),
                        'name' =>
                            (string)(
                                $record[
                                    $fieldMap['name']
                                ]['value'] ?? ''
                            ),
                        'email' =>
                            (string)(
                                $record[
                                    $fieldMap['email']
                                ]['value'] ?? ''
                            ),
                        'department' =>
                            (string)(
                                $record[
                                    $fieldMap['department']
                                ]['value'] ?? ''
                            ),
                        'phone' =>
                            (string)(
                                $record[
                                    $fieldMap['phone']
                                ]['value'] ?? ''
                            ),
                        'address' =>
                            (string)(
                                $record[
                                    $fieldMap['address']
                                ]['value'] ?? ''
                            ),
                        'updated_at' => date('c')
                    ];

                    $existing = null;

                    foreach ($data['customers'] as $i => $old) {
                        if (($old['kintone_id'] ?? '') ===
                            $customer['kintone_id']) {
                            $existing = $i;
                            break;
                        }
                    }

                    if ($existing === null) {
                        $data['customers'][] = $customer;
                        $inserted++;
                    } else {
                        $data['customers'][$existing] =
                            array_merge(
                                $data['customers'][$existing],
                                $customer
                            );
                        $updated++;
                    }
                } catch (Throwable) {
                    $errors++;
                }
            }

            if (!saveData($data)) {
                jsonResponse([
                    'ok' => false,
                    'message' => '顧客データの保存に失敗しました。',
                    'error_type' => 'storage'
                ], 500);
            }

            jsonResponse([
                'ok' => true,
                'message' => '顧客データを同期しました。',
                'count' => count($records),
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors
            ]);

        case 'test_smtp_connection':
            $config = $data['settings']['smtp'];

            if (empty($config['smtp_server'])) {
                jsonResponse([
                    'ok' => false,
                    'message' => '保存済みSMTP設定がありません。',
                    'error_type' => 'configuration'
                ], 400);
            }

            $result = smtpConnect($config);

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' => $result['message'],
                    'error_type' => $result['error_type'],
                    'smtp_code' => $result['smtp_code'] ?? null,
                    'smtp_server' => $config['smtp_server'],
                    'smtp_port' => $config['smtp_port'],
                    'smtp_encryption' =>
                        $config['smtp_encryption'],
                    'check_items' => [
                        'SMTPサーバ',
                        'SMTPポート',
                        '暗号化方式',
                        'SMTP認証',
                        'SMTPユーザー名',
                        'SMTPパスワード',
                        'Proxyやネットワーク設定'
                    ]
                ], 502);
            }

            fclose($result['socket']);

            jsonResponse([
                'ok' => true,
                'message' => 'SMTP接続に成功しました。',
                'smtp_server' => $config['smtp_server'],
                'smtp_port' => $config['smtp_port'],
                'smtp_encryption' => $config['smtp_encryption'],
                'authentication' =>
                    !empty($config['smtp_auth']) ? '成功' : '未使用',
                'smtp_code' => $result['greeting']
            ]);

        case 'send_smtp_test':
            $config = $data['settings']['smtp'];

            $recipient = trim(
                (string)($_POST['recipient'] ?? '')
            );

            if (!filter_var(
                $recipient,
                FILTER_VALIDATE_EMAIL
            )) {
                jsonResponse([
                    'ok' => false,
                    'message' =>
                        'テスト宛先メールアドレスが不正です。',
                    'error_type' => 'configuration'
                ], 400);
            }

            $result = smtpSend(
                $config,
                $recipient,
                'アンケート管理システム SMTP送信テスト',
                "アンケート管理システムのSMTP送信テストです。\r\n\r\n" .
                "このメールはSMTP設定確認のために送信されました。"
            );

            if (!$result['ok']) {
                jsonResponse([
                    'ok' => false,
                    'message' => $result['message'],
                    'error_type' => $result['error_type'],
                    'smtp_code' => $result['smtp_code'] ?? null,
                    'recipient' => $recipient
                ], 502);
            }

            $data['mail_logs'][] = [
                'id' => id('mail'),
                'type' => 'smtp_test',
                'recipient' => $recipient,
                'subject' =>
                    'アンケート管理システム SMTP送信テスト',
                'status' => 'sent',
                'created_at' => date('c')
            ];

            saveData($data);

            jsonResponse([
                'ok' => true,
                'message' => 'テストメールを送信しました。',
                'recipient' => $recipient,
                'smtp_code' => $result['smtp_code']
            ]);

        default:
            jsonResponse([
                'ok' => false,
                'message' => '未対応のAPIです。'
            ], 400);
    }
}

handleApi();

$csrf = csrf();
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
        settings: {
            kintone: {},
            smtp: {}
        },
        mail_logs: [],
        currentSurvey: null,
        keyword: '',
        statusFilter: '',
        sort: 'updated_desc',
        previewSurvey: null,
        branchVisibility: {}
    },

    render: {},

    actions: {},

    api: {},

    utils: {},

    initSortable: function () {
        const editor = document.getElementById('question_editor');

        if (!editor || typeof Sortable === 'undefined') {
            return;
        }

        editor.querySelectorAll('[data-sortable-groups]')
            .forEach((el) => {

                if (el.dataset.sortableInitialized === '1') {
                    return;
                }

                Sortable.create(el, {
                    animation: 150,
                    handle: '[data-group-handle]',
                    onEnd: () => {
                        App.actions.syncQuestionStructure();
                        App.actions.renumberQuestions();
                        App.render.editor();
                    }
                });

                el.dataset.sortableInitialized = '1';
            });

        editor.querySelectorAll('[data-sortable-questions]')
            .forEach((el) => {

                if (el.dataset.sortableInitialized === '1') {
                    return;
                }

                Sortable.create(el, {
                    group: 'survey-questions',
                    animation: 150,
                    handle: '[data-question-handle]',
                    onEnd: () => {
                        App.actions.syncQuestionStructure();
                        App.actions.renumberQuestions();
                        App.render.editor();
                    }
                });

                el.dataset.sortableInitialized = '1';
            });
    },

    init: async function () {
        if (this.state.initialized) {
            return;
        }

        this.state.initialized = true;

        this.render.shell();

        await this.api.load();

        this.render.current();
    }
};

App.utils.escapeHTML = function (value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
};

App.utils.newSurvey = function () {
    return {
        id: 'survey_' + Date.now() + '_' +
            Math.random().toString(36).slice(2),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        numbering_mode: 'global',
        general_response_enabled: false,
        groups: [],
        deleted: false,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString()
    };
};

App.utils.newGroup = function () {
    return {
        id: 'group_' + Date.now() + '_' +
            Math.random().toString(36).slice(2),
        name: 'ブロック',
        questions: []
    };
};

App.utils.newQuestion = function () {
    return {
        id: 'question_' + Date.now() + '_' +
            Math.random().toString(36).slice(2),
        text: '',
        type: 'text',
        required: false,
        options: [],
        other_enabled: false,
        branching: {}
    };
};

App.api.request = async function (action, payload = {}) {
    const body = new URLSearchParams();

    body.set('action', action);
    body.set(
        'csrf_token',
        document.getElementById('csrf_token').value
    );

    Object.entries(payload).forEach(([key, value]) => {
        body.set(
            key,
            typeof value === 'object'
                ? JSON.stringify(value)
                : String(value)
        );
    });

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

    const json = await response.json();

    if (!response.ok || json.ok === false) {
        const error = new Error(
            json.message || 'API処理に失敗しました。'
        );

        Object.assign(error, json);

        throw error;
    }

    return json;
};

App.api.load = async function () {
    const result = await App.api.request('get_data');

    App.state.surveys = Array.isArray(result.surveys)
        ? result.surveys
        : [];

    App.state.responses = Array.isArray(result.responses)
        ? result.responses
        : [];

    App.state.customers = Array.isArray(result.customers)
        ? result.customers
        : [];

    App.state.settings = result.settings || {
        kintone: {},
        smtp: {}
    };

    App.state.mail_logs = Array.isArray(result.mail_logs)
        ? result.mail_logs
        : [];
};

App.render.shell = function () {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen">

            <header class="
                sticky top-0 z-40 bg-white border-b shadow-sm
            ">
                <div class="
                    max-w-7xl mx-auto px-4 py-4
                    flex items-center justify-between
                ">
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
};

App.render.current = function () {
    if (App.state.screen === 'list') {
        App.render.list();
        return;
    }

    if (App.state.screen === 'edit') {
        App.render.editor();
        return;
    }

    if (App.state.screen === 'settings') {
        App.render.settings();
        return;
    }

    if (App.state.screen === 'preview') {
        App.render.preview();
        return;
    }
};

App.render.list = function () {
    const root = document.getElementById('main_content');

    let surveys = App.state.surveys
        .filter(s => !s.deleted);

    const keyword = App.state.keyword
        .trim()
        .toLowerCase();

    if (keyword) {
        surveys = surveys.filter(s =>
            String(s.title || '')
                .toLowerCase()
                .includes(keyword)
        );
    }

    if (App.state.statusFilter) {
        surveys = surveys.filter(
            s => s.status === App.state.statusFilter
        );
    }

    if (App.state.sort === 'title_asc') {
        surveys.sort((a, b) =>
            String(a.title).localeCompare(
                String(b.title),
                'ja'
            )
        );
    } else {
        surveys.sort((a, b) =>
            String(b.updated_at)
                .localeCompare(String(a.updated_at))
        );
    }

    root.innerHTML = `
        <div class="flex justify-between items-center mb-6">
            <div>
                <div class="text-sm text-gray-500">
                    ホーム ＞ アンケート一覧
                </div>
                <h1 class="text-2xl font-bold mt-1">
                    アンケート一覧
                </h1>
            </div>

            <button
                class="
                    bg-blue-600 text-white px-4 py-2
                    rounded-lg hover:bg-blue-700
                "
                onclick="App.actions.createSurvey()"
            >
                ＋ 新規アンケート
            </button>
        </div>

        <div class="
            bg-white border rounded-xl p-4 mb-4
            flex gap-3
        ">
            <input
                class="border rounded px-3 py-2 flex-1"
                placeholder="アンケートを検索"
                value="${App.utils.escapeHTML(
                    App.state.keyword
                )}"
                oninput="App.actions.search(this.value)"
            >

            <select
                class="border rounded px-3 py-2"
                onchange="App.actions.filterStatus(this.value)"
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

        <div class="bg-white border rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="text-left p-3">タイトル</th>
                        <th class="text-left p-3">ステータス</th>
                        <th class="text-left p-3">回答数</th>
                        <th class="text-left p-3">操作</th>
                    </tr>
                </thead>
                <tbody>
                    ${
                        surveys.length
                        ? surveys.map(
                            App.render.surveyRow
                          ).join('')
                        : `
                        <tr>
                            <td
                                colspan="4"
                                class="p-8 text-center text-gray-500"
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
};

App.render.surveyRow = function (survey) {
    const id = App.utils.escapeHTML(survey.id);

    const status = {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[survey.status] || survey.status;

    let actions = `
        <button
            class="text-blue-600 hover:underline"
            onclick="App.actions.editSurvey('${id}')"
        >
            確認・編集
        </button>
    `;

    if (
        survey.status === 'active' ||
        survey.status === 'ended'
    ) {
        actions += `
            <button
                class="text-purple-600 hover:underline"
                onclick="App.actions.aggregate('${id}')"
            >
                集計
            </button>
        `;
    }

    if (survey.status === 'active') {
        actions += `
            <button
                class="text-green-600 hover:underline"
                onclick="App.actions.send('${id}')"
            >
                送信
            </button>
        `;
    }

    if (survey.status === 'draft' ||
        survey.status === 'active' ||
        survey.status === 'ended') {
        actions += `
            <button
                class="text-gray-700 hover:underline"
                onclick="App.actions.duplicate('${id}')"
            >
                複製
            </button>
        `;
    }

    if (survey.status === 'draft') {
        actions += `
            <button
                class="text-red-600 hover:underline"
                onclick="App.actions.deleteSurvey('${id}')"
            >
                削除
            </button>
        `;
    }

    return `
        <tr class="border-b last:border-b-0">
            <td class="p-3 font-medium">
                ${App.utils.escapeHTML(survey.title)}
            </td>

            <td class="p-3">
                ${App.utils.escapeHTML(status)}
            </td>

            <td class="p-3">
                ${Number(survey.response_count || 0)}
            </td>

            <td class="p-3">
                <div class="flex gap-3 flex-wrap">
                    ${actions}
                </div>
            </td>
        </tr>
    `;
};

App.render.editor = function () {
    const root = document.getElementById('main_content');
    const survey = App.state.currentSurvey;

    if (!survey) {
        App.actions.goList();
        return;
    }

    root.innerHTML = `
        <div class="mb-6">
            <div class="text-sm text-gray-500">
                ホーム ＞ アンケート一覧 ＞ 確認・編集
            </div>

            <div class="
                flex justify-between items-center mt-1
            ">
                <h1 class="text-2xl font-bold">
                    アンケート作成・編集
                </h1>

                <div class="flex gap-2">
                    <button
                        class="
                            border px-4 py-2 rounded-lg
                            hover:bg-gray-50
                        "
                        onclick="App.actions.preview()"
                    >
                        プレビュー
                    </button>

                    <button
                        class="
                            bg-blue-600 text-white px-4 py-2
                            rounded-lg hover:bg-blue-700
                        "
                        onclick="App.actions.saveSurvey()"
                    >
                        保存
                    </button>
                </div>
            </div>
        </div>

        <div class="bg-white border rounded-xl p-5 mb-5">

            <div class="grid md:grid-cols-2 gap-4">

                <label>
                    <span class="block text-sm font-medium mb-1">
                        タイトル
                    </span>
                    <input
                        id="survey_title"
                        class="w-full border rounded px-3 py-2"
                        value="${App.utils.escapeHTML(
                            survey.title
                        )}"
                        oninput="App.actions.changeSurveyField(
                            'title',
                            this.value
                        )"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        ステータス
                    </span>

                    <select
                        id="survey_status"
                        class="w-full border rounded px-3 py-2"
                        onchange="App.actions.changeSurveyStatus(
                            this.value
                        )"
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
                    <span class="block text-sm font-medium mb-1">
                        開始日時
                    </span>
                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        class="w-full border rounded px-3 py-2"
                        value="${App.utils.escapeHTML(
                            survey.start_at
                        )}"
                        oninput="App.actions.changeSurveyField(
                            'start_at',
                            this.value
                        )"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        終了日時
                    </span>
                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        class="w-full border rounded px-3 py-2"
                        value="${App.utils.escapeHTML(
                            survey.end_at
                        )}"
                        oninput="App.actions.changeSurveyField(
                            'end_at',
                            this.value
                        )"
                    >
                </label>

                <label>
                    <span class="block text-sm font-medium mb-1">
                        質問番号形式
                    </span>
                    <select
                        id="survey_numbering_mode"
                        class="w-full border rounded px-3 py-2"
                        onchange="App.actions.changeSurveyField(
                            'numbering_mode',
                            this.value
                        )"
                    >
                        <option value="global"
                            ${survey.numbering_mode === 'global'
                                ? 'selected' : ''}>
                            Q1 / Q2 / Q3
                        </option>
                        <option value="group"
                            ${survey.numbering_mode === 'group'
                                ? 'selected' : ''}>
                            Q1-1 / Q1-2 / Q2-1
                        </option>
                    </select>
                </label>

                <label class="flex items-center gap-2 mt-6">
                    <input
                        type="checkbox"
                        ${survey.general_response_enabled
                            ? 'checked' : ''}
                        onchange="App.actions.changeSurveyField(
                            'general_response_enabled',
                            this.checked
                        )"
                    >
                    一般回答を許可する
                </label>

            </div>
        </div>

        <div id="question_editor"></div>
    `;

    App.render.groups();

    App.initSortable();
};

App.render.groups = function () {
    const survey = App.state.currentSurvey;
    const editor = document.getElementById('question_editor');

    editor.innerHTML = `
        <div
            data-sortable-groups
            class="space-y-5"
        >
            ${
                survey.groups.map(
                    (group, groupIndex) =>
                        App.render.group(
                            group,
                            groupIndex
                        )
                ).join('')
            }
        </div>

        <div class="mt-5">
            <button
                class="
                    w-full border-2 border-dashed
                    border-gray-300 rounded-xl
                    py-4 text-gray-600
                    hover:border-blue-400 hover:text-blue-600
                "
                onclick="App.actions.addGroup()"
            >
                ＋ ブロックを追加
            </button>
        </div>
    `;
};

App.render.group = function (group, groupIndex) {
    return `
        <section
            class="bg-white border rounded-xl p-5"
            data-group-id="${App.utils.escapeHTML(group.id)}"
            data-group-handle
        >
            <div class="flex justify-between items-center mb-4">
                <input
                    class="
                        text-lg font-bold border-b
                        border-transparent
                        focus:border-blue-500 outline-none
                    "
                    value="${App.utils.escapeHTML(group.name)}"
                    oninput="App.actions.changeGroupName(
                        '${App.utils.escapeHTML(group.id)}',
                        this.value
                    )"
                >

                <button
                    class="text-red-600 hover:underline"
                    onclick="App.actions.deleteGroup(
                        '${App.utils.escapeHTML(group.id)}'
                    )"
                >
                    グループ削除
                </button>
            </div>

            <div
                data-sortable-questions
                data-group-id="${App.utils.escapeHTML(group.id)}"
                class="space-y-4"
            >
                ${
                    group.questions.map(
                        (q) =>
                            App.render.question(
                                q,
                                group.id
                            )
                    ).join('')
                }
            </div>

            <button
                class="
                    mt-4 w-full border rounded-lg py-2
                    text-blue-600 hover:bg-blue-50
                "
                onclick="App.actions.addQuestion(
                    '${App.utils.escapeHTML(group.id)}'
                )"
            >
                ＋ 質問を追加
            </button>
        </section>
    `;
};

App.render.question = function (question, groupId) {
    const options =
        question.options || [];

    return `
        <div
            class="border rounded-lg p-4 bg-gray-50"
            data-question-id="${App.utils.escapeHTML(
                question.id
            )}"
            data-question-handle
        >

            <div class="
                flex justify-between items-center mb-3
            ">
                <strong>
                    ${App.utils.escapeHTML(
                        question.number || ''
                    )}
                </strong>

                <button
                    class="text-red-600 hover:underline"
                    onclick="App.actions.deleteQuestion(
                        '${App.utils.escapeHTML(groupId)}',
                        '${App.utils.escapeHTML(question.id)}'
                    )"
                >
                    質問削除
                </button>
            </div>

            <textarea
                class="
                    w-full border rounded px-3 py-2
                    bg-white
                "
                rows="2"
                oninput="App.actions.changeQuestion(
                    '${App.utils.escapeHTML(groupId)}',
                    '${App.utils.escapeHTML(question.id)}',
                    'text',
                    this.value
                )"
            >${App.utils.escapeHTML(question.text)}</textarea>

            <div class="grid md:grid-cols-3 gap-3 mt-3">

                <select
                    class="border rounded px-3 py-2 bg-white"
                    onchange="App.actions.changeQuestion(
                        '${App.utils.escapeHTML(groupId)}',
                        '${App.utils.escapeHTML(question.id)}',
                        'type',
                        this.value
                    )"
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

                <label class="
                    flex items-center gap-2
                    bg-white border rounded px-3 py-2
                ">
                    <input
                        type="checkbox"
                        ${question.required
                            ? 'checked' : ''}
                        onchange="App.actions.changeQuestion(
                            '${App.utils.escapeHTML(groupId)}',
                            '${App.utils.escapeHTML(question.id)}',
                            'required',
                            this.checked
                        )"
                    >
                    必須回答
                </label>

                <label class="
                    flex items-center gap-2
                    bg-white border rounded px-3 py-2
                ">
                    <input
                        type="checkbox"
                        ${question.other_enabled
                            ? 'checked' : ''}
                        onchange="App.actions.changeQuestion(
                            '${App.utils.escapeHTML(groupId)}',
                            '${App.utils.escapeHTML(question.id)}',
                            'other_enabled',
                            this.checked
                        )"
                    >
                    その他を許可
                </label>
            </div>

            ${
                question.type === 'single'
                    ? App.render.options(
                        question,
                        groupId
                    )
                    : ''
            }

            ${
                question.type === 'multiple'
                    ? App.render.options(
                        question,
                        groupId
                    )
                    : ''
            }

        </div>
    `;
};

App.render.options = function (question, groupId) {
    const candidates =
        App.actions.branchCandidates(
            question.id
        );

    return `
        <div class="mt-4">
            <div class="
                font-medium mb-2
            ">
                選択肢
            </div>

            <div class="space-y-2">
                ${
                    question.options.map(
                        (option, index) => `
                        <div
                            class="
                                bg-white border rounded
                                p-2
                            "
                        >
                            <div class="flex gap-2">
                                <input
                                    class="
                                        border rounded px-2 py-1
                                        flex-1
                                    "
                                    value="${App.utils.escapeHTML(
                                        option.text
                                    )}"
                                    oninput="App.actions.changeOption(
                                        '${App.utils.escapeHTML(groupId)}',
                                        '${App.utils.escapeHTML(
                                            question.id
                                        )}',
                                        '${App.utils.escapeHTML(
                                            option.id
                                        )}',
                                        this.value
                                    )"
                                >

                                <button
                                    class="text-red-600 px-2"
                                    onclick="App.actions.deleteOption(
                                        '${App.utils.escapeHTML(groupId)}',
                                        '${App.utils.escapeHTML(
                                            question.id
                                        )}',
                                        '${App.utils.escapeHTML(
                                            option.id
                                        )}'
                                    )"
                                >
                                    削除
                                </button>
                            </div>

                            ${
                                question.type === 'single'
                                    ? `
                                    <div class="mt-2">
                                        <label class="
                                            text-xs text-gray-500
                                        ">
                                            分岐先質問
                                        </label>

                                        <select
                                            class="
                                                w-full border rounded
                                                px-2 py-1 mt-1
                                            "
                                            onchange="App.actions.changeBranch(
                                                '${App.utils.escapeHTML(
                                                    question.id
                                                )}',
                                                '${App.utils.escapeHTML(
                                                    option.id
                                                )}',
                                                this.value
                                            )"
                                        >
                                            <option value="">
                                                分岐しない
                                            </option>

                                            ${
                                                candidates.map(
                                                    c => `
                                                    <option
                                                        value="${App.utils.escapeHTML(
                                                            c.id
                                                        )}"
                                                        ${
                                                            question.branching[
                                                                option.id
                                                            ] === c.id
                                                                ? 'selected'
                                                                : ''
                                                        }
                                                    >
                                                        ${App.utils.escapeHTML(
                                                            c.number
                                                        )}：
                                                        ${App.utils.escapeHTML(
                                                            c.text
                                                        )}
                                                    </option>
                                                    `
                                                ).join('')
                                            }
                                        </select>
                                    </div>
                                    `
                                    : ''
                            }
                        </div>
                        `
                    ).join('')
                }
            </div>

            <button
                class="
                    mt-2 text-blue-600 hover:underline
                "
                onclick="App.actions.addOption(
                    '${App.utils.escapeHTML(groupId)}',
                    '${App.utils.escapeHTML(question.id)}'
                )"
            >
                ＋ 選択肢を追加
            </button>
        </div>
    `;
};

App.render.settings = function () {
    const root =
        document.getElementById('main_content');

    const k =
        App.state.settings.kintone || {};

    const s =
        App.state.settings.smtp || {};

    root.innerHTML = `
        <div class="mb-6">
            <div class="text-sm text-gray-500">
                ホーム ＞ キントーン・メール設定
            </div>

            <h1 class="text-2xl font-bold mt-1">
                キントーン・メール設定
            </h1>
        </div>

        <div class="grid xl:grid-cols-2 gap-6">

            <section class="
                bg-white border rounded-xl p-5
            ">
                <h2 class="text-xl font-bold mb-5">
                    キントーン設定
                </h2>

                <form
                    id="kintone_settings_form"
                    onsubmit="
                        event.preventDefault();
                        App.actions.saveKintoneSettings();
                    "
                >
                    <div class="space-y-4">

                        <label class="block">
                            <span class="text-sm font-medium">
                                サブドメイン
                            </span>
                            <input
                                id="setting_subdomain"
                                class="w-full border rounded px-3 py-2 mt-1"
                                placeholder="example"
                                value="${App.utils.escapeHTML(
                                    k.subdomain || ''
                                )}"
                            >
                            <span class="
                                text-xs text-gray-500
                            ">
                                https:// は入力しません
                            </span>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium">
                                ログイン名
                            </span>
                            <input
                                id="setting_login_name"
                                class="w-full border rounded px-3 py-2 mt-1"
                                value="${App.utils.escapeHTML(
                                    k.login_name || ''
                                )}"
                            >
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium">
                                パスワード
                            </span>
                            <input
                                id="setting_password"
                                type="password"
                                autocomplete="new-password"
                                class="w-full border rounded px-3 py-2 mt-1"
                                placeholder="${
                                    k.password_configured
                                        ? '変更しない場合は空欄'
                                        : ''
                                }"
                            >
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium">
                                顧客管理アプリID
                            </span>
                            <input
                                id="setting_app_id"
                                type="number"
                                min="1"
                                class="w-full border rounded px-3 py-2 mt-1"
                                value="${App.utils.escapeHTML(
                                    k.app_id || ''
                                )}"
                            >
                        </label>

                        <label class="
                            flex items-center gap-2
                        ">
                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${k.ssl_verify !== false
                                    ? 'checked' : ''}
                            >
                            SSL証明書検証
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium">
                                Proxy
                            </span>
                            <input
                                id="setting_proxy"
                                class="w-full border rounded px-3 py-2 mt-1"
                                placeholder="http://proxy.example.com:8080"
                                value="${App.utils.escapeHTML(
                                    k.proxy || ''
                                )}"
                            >
                        </label>

                        <div class="
                            grid md:grid-cols-2 gap-3
                        ">
                            <label>
                                <span class="text-sm">
                                    会社名フィールド
                                </span>
                                <input
                                    id="field_company"
                                    class="w-full border rounded px-3 py-2"
                                    value="${App.utils.escapeHTML(
                                        k.field_company || ''
                                    )}"
                                >
                            </label>

                            <label>
                                <span class="text-sm">
                                    氏名フィールド
                                </span>
                                <input
                                    id="field_name"
                                    class="w-full border rounded px-3 py-2"
                                    value="${App.utils.escapeHTML(
                                        k.field_name || ''
                                    )}"
                                >
                            </label>

                            <label>
                                <span class="text-sm">
                                    メールフィールド
                                </span>
                                <input
                                    id="field_email"
                                    class="w-full border rounded px-3 py-2"
                                    value="${App.utils.escapeHTML(
                                        k.field_email || ''
                                    )}"
                                >
                            </label>

                            <label>
                                <span class="text-sm">
                                    部署フィールド
                                </span>
                                <input
                                    id="field_department"
                                    class="w-full border rounded px-3 py-2"
                                    value="${App.utils.escapeHTML(
                                        k.field_department || ''
                                    )}"
                                >
                            </label>

                            <label>
                                <span class="text-sm">
                                    電話フィールド
                                </span>
                                <input
                                    id="field_phone"
                                    class="w-full border rounded px-3 py-2"
                                    value="${App.utils.escapeHTML(
                                        k.field_phone || ''
                                    )}"
                                >
                            </label>

                            <label>
                                <span class="text-sm">
                                    住所フィールド
                                </span>
                                <input
                                    id="field_address"
                                    class="w-full border rounded px-3 py-2"
                                    value="${App.utils.escapeHTML(
                                        k.field_address || ''
                                    )}"
                                >
                            </label>
                        </div>

                        <div class="flex flex-wrap gap-2 pt-2">
                            <button
                                id="kintone_save_button"
                                type="submit"
                                class="
                                    bg-blue-600 text-white
                                    px-4 py-2 rounded-lg
                                "
                            >
                                設定を保存
                            </button>

                            <button
                                type="button"
                                class="
                                    border px-4 py-2 rounded-lg
                                "
                                onclick="App.actions.connectKintone()"
                            >
                                キントーン接続確認
                            </button>

                            <button
                                type="button"
                                class="
                                    border px-4 py-2 rounded-lg
                                "
                                onclick="App.actions.fetchKintoneFields()"
                            >
                                フィールド取得
                            </button>

                            <button
                                type="button"
                                class="
                                    border px-4 py-2 rounded-lg
                                "
                                onclick="App.actions.syncCustomers()"
                            >
                                顧客データを同期
                            </button>
                        </div>
                    </div>
                </form>

                <div
                    id="kintone_message"
                    class="mt-4"
                ></div>

                <div
                    id="field_message"
                    class="mt-4"
                ></div>
            </section>

            <section class="
                bg-white border rounded-xl p-5
            ">
                <h2 class="text-xl font-bold mb-5">
                    SMTP設定
                </h2>

                <form
                    id="smtp_settings_form"
                    onsubmit="
                        event.preventDefault();
                        App.actions.saveSmtpSettings();
                    "
                >
                    <div class="space-y-4">

                        <label class="block">
                            <span class="text-sm">
                                SMTPサーバ
                            </span>
                            <input
                                id="smtp_server"
                                class="w-full border rounded px-3 py-2 mt-1"
                                value="${App.utils.escapeHTML(
                                    s.smtp_server || ''
                                )}"
                            >
                        </label>

                        <label class="block">
                            <span class="text-sm">
                                SMTPポート
                            </span>
                            <input
                                id="smtp_port"
                                type="number"
                                min="1"
                                max="65535"
                                class="w-full border rounded px-3 py-2 mt-1"
                                value="${App.utils.escapeHTML(
                                    s.smtp_port || 587
                                )}"
                            >
                        </label>

                        <label class="block">
                            <span class="text-sm">
                                暗号化方式
                            </span>
                            <select
                                id="smtp_encryption"
                                class="w-full border rounded px-3 py-2 mt-1"
                            >
                                <option value="none"
                                    ${s.smtp_encryption === 'none'
                                        ? 'selected' : ''}>
                                    none
                                </option>
                                <option value="starttls"
                                    ${s.smtp_encryption === 'starttls'
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

                        <label class="
                            flex items-center gap-2
                        ">
                            <input
                                id="smtp_auth"
                                type="checkbox"
                                ${s.smtp_auth ? 'checked' : ''}
                            >
                            SMTP認証
                        </label>

                        <label class="block">
                            <span class="text-sm">
                                SMTPユーザー名
                            </span>
                            <input
                                id="smtp_username"
                                class="w-full border rounded px-3 py-2 mt-1"
                                value="${App.utils.escapeHTML(
                                    s.smtp_username || ''
                                )}"
                            >
                        </label>

                        <label class="block">
                            <span class="text-sm">
                                SMTPパスワード
                            </span>
                            <input
                                id="smtp_password"
                                type="password"
                                autocomplete="new-password"
                                class="w-full border rounded px-3 py-2 mt-1"
                                placeholder="${
                                    s.smtp_password_configured
                                        ? '変更しない場合は空欄'
                                        : ''
                                }"
                            >
                        </label>

                        <label class="block">
                            <span class="text-sm">
                                送信元メールアドレス
                            </span>
                            <input
                                id="smtp_from_email"
                                type="email"
                                class="w-full border rounded px-3 py-2 mt-1"
                                value="${App.utils.escapeHTML(
                                    s.smtp_from_email || ''
                                )}"
                            >
                        </label>

                        <label class="block">
                            <span class="text-sm">
                                送信元表示名
                            </span>
                            <input
                                id="smtp_from_name"
                                class="w-full border rounded px-3 py-2 mt-1"
                                value="${App.utils.escapeHTML(
                                    s.smtp_from_name || ''
                                )}"
                            >
                        </label>

                        <label class="block">
                            <span class="text-sm">
                                接続タイムアウト
                            </span>
                            <input
                                id="smtp_timeout"
                                type="number"
                                min="1"
                                max="300"
                                class="w-full border rounded px-3 py-2 mt-1"
                                value="${App.utils.escapeHTML(
                                    s.smtp_timeout || 10
                                )}"
                            >
                        </label>

                        <label class="block">
                            <span class="text-sm">
                                テスト送信先
                            </span>
                            <input
                                id="smtp_test_recipient"
                                type="email"
                                class="w-full border rounded px-3 py-2 mt-1"
                                placeholder="test@example.com"
                            >
                        </label>

                        <div class="flex flex-wrap gap-2 pt-2">
                            <button
                                id="smtp_save_button"
                                type="submit"
                                class="
                                    bg-blue-600 text-white
                                    px-4 py-2 rounded-lg
                                "
                            >
                                設定を保存
                            </button>

                            <button
                                type="button"
                                class="
                                    border px-4 py-2 rounded-lg
                                "
                                onclick="App.actions.testSmtpConnection()"
                            >
                                SMTP接続確認
                            </button>

                            <button
                                type="button"
                                class="
                                    border px-4 py-2 rounded-lg
                                "
                                onclick="App.actions.sendSmtpTest()"
                            >
                                テストメール送信
                            </button>
                        </div>
                    </div>
                </form>

                <div
                    id="smtp_message"
                    class="mt-4"
                ></div>
            </section>

        </div>
    `;
};

App.actions.goList = async function () {
    App.state.screen = 'list';
    App.render.current();
};

App.actions.showSettings = async function () {
    try {
        await App.api.load();
    } catch (error) {
        App.actions.message(
            error.message,
            'error'
        );
    }

    App.state.screen = 'settings';
    App.render.current();
};

App.actions.logout = function () {
    window.location.reload();
};

App.actions.message = function (
    message,
    type = 'info',
    target = null
) {
    const color =
        type === 'error'
            ? 'bg-red-50 text-red-800 border-red-200'
            : 'bg-green-50 text-green-800 border-green-200';

    if (target) {
        const element =
            document.getElementById(target);

        if (element) {
            element.innerHTML = `
                <div class="
                    border rounded-lg p-4
                    ${color}
                ">
                    ${App.utils.escapeHTML(message)}
                </div>
            `;
        }

        return;
    }

    alert(message);
};

App.actions.search = function (value) {
    App.state.keyword = value;
    App.render.list();
};

App.actions.filterStatus = function (value) {
    App.state.statusFilter = value;
    App.render.list();
};

App.actions.createSurvey = function () {
    const survey = App.utils.newSurvey();

    survey.groups.push(
        App.utils.newGroup()
    );

    App.state.currentSurvey = survey;
    App.state.screen = 'edit';

    App.render.current();
};

App.actions.editSurvey = function (id) {
    const survey = App.state.surveys.find(
        s => s.id === id
    );

    if (!survey) {
        return;
    }

    App.state.currentSurvey =
        JSON.parse(JSON.stringify(survey));

    App.state.screen = 'edit';

    App.render.current();
};

App.actions.changeSurveyField = function (
    field,
    value
) {
    if (!App.state.currentSurvey) {
        return;
    }

    App.state.currentSurvey[field] = value;

    if (field === 'numbering_mode') {
        App.actions.renumberQuestions();
    }
};

App.actions.changeSurveyStatus = function (value) {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return;
    }

    const old = survey.status;

    if (old === 'active' && value === 'ended') {
        if (!confirm(
            'このアンケートを終了状態に変更しますか？'
        )) {
            document.getElementById(
                'survey_status'
            ).value = old;
            return;
        }
    }

    if (old === 'ended' && value === 'active') {
        if (!confirm(
            'このアンケートを公開状態に変更しますか？'
        )) {
            document.getElementById(
                'survey_status'
            ).value = old;
            return;
        }
    }

    survey.status = value;
};

App.actions.addGroup = function () {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return;
    }

    survey.groups.push(
        App.utils.newGroup()
    );

    App.actions.renumberQuestions();

    App.render.editor();

    App.initSortable();
};

App.actions.deleteGroup = function (groupId) {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return;
    }

    if (!confirm('このグループを削除しますか？')) {
        return;
    }

    survey.groups =
        survey.groups.filter(
            g => g.id !== groupId
        );

    App.actions.renumberQuestions();
    App.render.editor();
    App.initSortable();
};

App.actions.changeGroupName = function (
    groupId,
    value
) {
    const group =
        App.state.currentSurvey.groups.find(
            g => g.id === groupId
        );

    if (group) {
        group.name = value;
    }
};

App.actions.addQuestion = function (groupId) {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return;
    }

    const group =
        survey.groups.find(
            g => g.id === groupId
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
};

App.actions.deleteQuestion = function (
    groupId,
    questionId
) {
    const survey = App.state.currentSurvey;

    const group =
        survey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    group.questions =
        group.questions.filter(
            q => q.id !== questionId
        );

    App.actions.removeInvalidBranches();

    App.actions.renumberQuestions();

    App.render.editor();

    App.initSortable();
};

App.actions.changeQuestion = function (
    groupId,
    questionId,
    field,
    value
) {
    const group =
        App.state.currentSurvey.groups.find(
            g => g.id === groupId
        );

    if (!group) {
        return;
    }

    const question =
        group.questions.find(
            q => q.id === questionId
        );

    if (!question) {
        return;
    }

    question[field] = value;

    if (field === 'type' &&
        value !== 'single') {
        question.branching = {};
    }

    App.render.editor();
    App.initSortable();
};

App.actions.addOption = function (
    groupId,
    questionId
) {
    const group =
        App.state.currentSurvey.groups.find(
            g => g.id === groupId
        );

    const question =
        group?.questions.find(
            q => q.id === questionId
        );

    if (!question) {
        return;
    }

    question.options.push({
        id: 'option_' + Date.now() + '_' +
            Math.random().toString(36).slice(2),
        text: ''
    });

    App.render.editor();
    App.initSortable();
};

App.actions.changeOption = function (
    groupId,
    questionId,
    optionId,
    value
) {
    const group =
        App.state.currentSurvey.groups.find(
            g => g.id === groupId
        );

    const question =
        group?.questions.find(
            q => q.id === questionId
        );

    const option =
        question?.options.find(
            o => o.id === optionId
        );

    if (option) {
        option.text = value;
    }
};

App.actions.deleteOption = function (
    groupId,
    questionId,
    optionId
) {
    const group =
        App.state.currentSurvey.groups.find(
            g => g.id === groupId
        );

    const question =
        group?.questions.find(
            q => q.id === questionId
        );

    if (!question) {
        return;
    }

    question.options =
        question.options.filter(
            o => o.id !== optionId
        );

    delete question.branching[optionId];

    App.render.editor();
    App.initSortable();
};

App.actions.branchCandidates = function (
    questionId
) {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return [];
    }

    const flat = [];

    survey.groups.forEach(group => {
        group.questions.forEach(question => {
            flat.push(question);
        });
    });

    const index =
        flat.findIndex(
            q => q.id === questionId
        );

    if (index < 0) {
        return [];
    }

    return flat.slice(index + 1);
};

App.actions.changeBranch = function (
    questionId,
    optionId,
    value
) {
    const survey = App.state.currentSurvey;

    for (const group of survey.groups) {
        const question =
            group.questions.find(
                q => q.id === questionId
            );

        if (!question) {
            continue;
        }

        question.branching[optionId] =
            value === '' ? null : value;

        break;
    }

    App.actions.removeInvalidBranches();
};

App.actions.removeInvalidBranches = function () {
    const survey = App.state.currentSurvey;

    const flat = [];

    survey.groups.forEach(g => {
        g.questions.forEach(q => flat.push(q));
    });

    const positions = {};

    flat.forEach((q, i) => {
        positions[q.id] = i;
    });

    flat.forEach((question, index) => {
        const branching =
            question.branching || {};

        Object.keys(branching).forEach(optionId => {
            const target = branching[optionId];

            if (
                target !== null &&
                (
                    !Object.prototype.hasOwnProperty.call(
                        positions,
                        target
                    ) ||
                    positions[target] <= index
                )
            ) {
                branching[optionId] = null;
            }
        });

        question.branching = branching;
    });
};

App.actions.renumberQuestions = function () {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return;
    }

    let global = 1;

    survey.groups.forEach(
        (group, groupIndex) => {
            group.questions.forEach(
                (question, questionIndex) => {
                    question.number =
                        survey.numbering_mode === 'group'
                            ? `Q${groupIndex + 1}-${questionIndex + 1}`
                            : `Q${global}`;

                    global++;
                }
            );
        }
    );

    App.actions.removeInvalidBranches();
};

App.actions.syncQuestionStructure = function () {
    const editor =
        document.getElementById('question_editor');

    if (!editor || !App.state.currentSurvey) {
        return;
    }

    const groups = [];

    editor.querySelectorAll(
        '[data-sortable-groups] > section'
    ).forEach(groupEl => {

        const groupId =
            groupEl.dataset.groupId;

        const group =
            App.state.currentSurvey.groups.find(
                g => g.id === groupId
            );

        if (!group) {
            return;
        }

        const ids = [];

        groupEl.querySelectorAll(
            '[data-sortable-questions] > [data-question-id]'
        ).forEach(questionEl => {
            ids.push(questionEl.dataset.questionId);
        });

        group.questions =
            ids.map(
                questionId =>
                    group.questions.find(
                        q => q.id === questionId
                    )
            ).filter(Boolean);

        groups.push(group);
    });

    if (groups.length) {
        App.state.currentSurvey.groups = groups;
    }

    App.actions.removeInvalidBranches();
};

App.actions.saveSurvey = async function () {
    const survey = App.state.currentSurvey;

    if (!survey) {
        return;
    }

    App.actions.syncQuestionStructure();

    App.actions.renumberQuestions();

    try {
        const result =
            await App.api.request(
                'save_survey',
                {
                    survey_json: survey
                }
            );

        App.state.currentSurvey =
            result.survey;

        const index =
            App.state.surveys.findIndex(
                s => s.id === result.survey.id
            );

        if (index < 0) {
            App.state.surveys.push(result.survey);
        } else {
            App.state.surveys[index] =
                result.survey;
        }

        App.actions.message(
            'アンケートを保存しました。'
        );

    } catch (error) {
        App.actions.message(
            error.message,
            'error'
        );
    }
};

App.actions.duplicate = async function (id) {
    try {
        const result =
            await App.api.request(
                'duplicate_survey',
                {survey_id: id}
            );

        App.state.surveys.push(result.survey);

        App.render.list();

    } catch (error) {
        App.actions.message(
            error.message,
            'error'
        );
    }
};

App.actions.deleteSurvey = async function (id) {
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

        App.state.surveys =
            App.state.surveys.filter(
                s => s.id !== id
            );

        App.render.list();

    } catch (error) {
        App.actions.message(
            error.message,
            'error'
        );
    }
};

App.actions.saveKintoneSettings = async function () {
    const payload = {
        subdomain:
            document.getElementById(
                'setting_subdomain'
            ).value,

        login_name:
            document.getElementById(
                'setting_login_name'
            ).value,

        password:
            document.getElementById(
                'setting_password'
            ).value,

        app_id:
            document.getElementById(
                'setting_app_id'
            ).value,

        ssl_verify:
            document.getElementById(
                'setting_ssl_verify'
            ).checked,

        proxy:
            document.getElementById(
                'setting_proxy'
            ).value,

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

    try {
        const result =
            await App.api.request(
                'save_kintone_settings',
                payload
            );

        App.state.settings =
            result.settings;

        App.actions.message(
            'キントーン設定を保存しました。',
            'info',
            'kintone_message'
        );

    } catch (error) {
        App.actions.message(
            error.message,
            'error',
            'kintone_message'
        );
    }
};

App.actions.saveSmtpSettings = async function () {
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

    try {
        const result =
            await App.api.request(
                'save_smtp_settings',
                payload
            );

        App.state.settings =
            result.settings;

        App.actions.message(
            'SMTP設定を保存しました。',
            'info',
            'smtp_message'
        );

    } catch (error) {
        App.actions.message(
            error.message,
            'error',
            'smtp_message'
        );
    }
};

App.actions.connectKintone = async function () {
    try {
        const result =
            await App.api.request(
                'connect_kintone'
            );

        document.getElementById(
            'kintone_message'
        ).innerHTML = `
            <div class="
                border border-green-200
                bg-green-50 text-green-800
                rounded-lg p-4
            ">
                <div class="font-bold">
                    接続成功
                </div>
                <div>
                    接続先：
                    ${App.utils.escapeHTML(
                        result.subdomain
                    )}.cybozu.com
                </div>
                <div>
                    対象アプリID：
                    ${Number(result.app_id)}
                </div>
                <div>
                    HTTPステータス：
                    ${Number(result.http_status)}
                </div>
            </div>
        `;

    } catch (error) {
        document.getElementById(
            'kintone_message'
        ).innerHTML = `
            <div class="
                border border-red-200
                bg-red-50 text-red-800
                rounded-lg p-4
            ">
                <div class="font-bold">
                    キントーン接続確認：失敗
                </div>

                <div>
                    HTTPステータス：
                    ${App.utils.escapeHTML(
                        error.http_status ?? '-'
                    )}
                </div>

                <div>
                    エラー種別：
                    ${App.utils.escapeHTML(
                        error.error_type ?? '-'
                    )}
                </div>

                <div class="mt-2">
                    ${App.utils.escapeHTML(
                        error.message
                    )}
                </div>

                <div class="mt-3 font-medium">
                    確認事項
                </div>

                <ul class="list-disc ml-5">
                    <li>サブドメイン</li>
                    <li>ログイン名</li>
                    <li>パスワード</li>
                    <li>kintone側の認証・権限</li>
                    <li>Proxy</li>
                    <li>SSL証明書検証</li>
                </ul>
            </div>
        `;
    }
};

App.actions.fetchKintoneFields = async function () {
    try {
        const result =
            await App.api.request(
                'fetch_kintone_fields'
            );

        document.getElementById(
            'field_message'
        ).innerHTML = `
            <div class="
                border rounded-lg p-4
                bg-gray-50
            ">
                <div class="font-bold mb-3">
                    フィールド取得結果
                </div>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left p-2">
                                label
                            </th>
                            <th class="text-left p-2">
                                code
                            </th>
                            <th class="text-left p-2">
                                type
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        ${
                            result.fields.map(
                                field => `
                                <tr class="border-b">
                                    <td class="p-2">
                                        ${App.utils.escapeHTML(
                                            field.label
                                        )}
                                    </td>
                                    <td class="p-2">
                                        ${App.utils.escapeHTML(
                                            field.code
                                        )}
                                    </td>
                                    <td class="p-2">
                                        ${App.utils.escapeHTML(
                                            field.type
                                        )}
                                    </td>
                                </tr>
                                `
                            ).join('')
                        }
                    </tbody>
                </table>
            </div>
        `;

    } catch (error) {
        document.getElementById(
            'field_message'
        ).innerHTML = `
            <div class="
                border border-red-200
                bg-red-50 text-red-800
                rounded-lg p-4
            ">
                <div class="font-bold">
                    フィールド取得：失敗
                </div>
                <div>
                    HTTPステータス：
                    ${App.utils.escapeHTML(
                        error.http_status ?? '-'
                    )}
                </div>
                <div>
                    エラー種別：
                    ${App.utils.escapeHTML(
                        error.error_type ?? '-'
                    )}
                </div>
                <div class="mt-2">
                    ${App.utils.escapeHTML(
                        error.message
                    )}
                </div>
            </div>
        `;
    }
};

App.actions.syncCustomers = async function () {
    try {
        const result =
            await App.api.request(
                'sync_customers'
            );

        App.actions.message(
            [
                result.message,
                `取得件数：${result.count}`,
                `追加件数：${result.inserted}`,
                `更新件数：${result.updated}`,
                `スキップ件数：${result.skipped}`,
                `エラー件数：${result.errors}`
            ].join('\n'),
            'info',
            'kintone_message'
        );

    } catch (error) {
        App.actions.message(
            error.message,
            'error',
            'kintone_message'
        );
    }
};

App.actions.testSmtpConnection = async function () {
    try {
        const result =
            await App.api.request(
                'test_smtp_connection'
            );

        document.getElementById(
            'smtp_message'
        ).innerHTML = `
            <div class="
                border border-green-200
                bg-green-50 text-green-800
                rounded-lg p-4
            ">
                <div class="font-bold">
                    SMTP接続成功
                </div>
                <div>
                    SMTPサーバ：
                    ${App.utils.escapeHTML(
                        result.smtp_server
                    )}
                </div>
                <div>
                    ポート：
                    ${Number(result.smtp_port)}
                </div>
                <div>
                    暗号化方式：
                    ${App.utils.escapeHTML(
                        result.smtp_encryption
                    )}
                </div>
                <div>
                    認証結果：
                    ${App.utils.escapeHTML(
                        result.authentication
                    )}
                </div>
                <div>
                    SMTP応答コード：
                    ${App.utils.escapeHTML(
                        result.smtp_code
                    )}
                </div>
            </div>
        `;

    } catch (error) {
        document.getElementById(
            'smtp_message'
        ).innerHTML = `
            <div class="
                border border-red-200
                bg-red-50 text-red-800
                rounded-lg p-4
            ">
                <div class="font-bold">
                    SMTP接続確認：失敗
                </div>

                <div>
                    SMTP応答コード：
                    ${App.utils.escapeHTML(
                        error.smtp_code ?? '-'
                    )}
                </div>

                <div>
                    エラー種別：
                    ${App.utils.escapeHTML(
                        error.error_type ?? '-'
                    )}
                </div>

                <div class="mt-2">
                    ${App.utils.escapeHTML(
                        error.message
                    )}
                </div>

                <div class="mt-3 font-medium">
                    確認事項
                </div>

                <ul class="list-disc ml-5">
                    <li>SMTPサーバ</li>
                    <li>SMTPポート</li>
                    <li>暗号化方式</li>
                    <li>SMTP認証方式</li>
                    <li>SMTPユーザー名</li>
                    <li>SMTPパスワード</li>
                </ul>
            </div>
        `;
    }
};

App.actions.sendSmtpTest = async function () {
    const recipient =
        document.getElementById(
            'smtp_test_recipient'
        ).value.trim();

    if (!recipient) {
        App.actions.message(
            'テスト送信先を入力してください。',
            'error',
            'smtp_message'
        );
        return;
    }

    try {
        const result =
            await App.api.request(
                'send_smtp_test',
                {recipient}
            );

        document.getElementById(
            'smtp_message'
        ).innerHTML = `
            <div class="
                border border-green-200
                bg-green-50 text-green-800
                rounded-lg p-4
            ">
                <div class="font-bold">
                    テストメール送信成功
                </div>

                <div>
                    宛先：
                    ${App.utils.escapeHTML(
                        result.recipient
                    )}
                </div>

                <div>
                    SMTP応答：
                    ${App.utils.escapeHTML(
                        result.smtp_code
                    )}
                </div>

                <div class="mt-2 font-bold">
                    送信済みです。
                </div>
            </div>
        `;

    } catch (error) {
        App.actions.message(
            [
                error.message,
                `宛先：${recipient}`,
                `SMTP応答：
                    ${error.smtp_code ?? '-'}`
            ].join('\n'),
            'error',
            'smtp_message'
        );
    }
};

App.actions.preview = function () {
    App.actions.syncQuestionStructure();
    App.actions.renumberQuestions();

    App.state.previewSurvey =
        JSON.parse(
            JSON.stringify(
                App.state.currentSurvey
            )
        );

    App.render.preview();
};

App.render.preview = function () {
    const root =
        document.getElementById('main_content');

    const survey =
        App.state.previewSurvey;

    root.innerHTML = `
        <div class="mb-6 flex justify-between">
            <div>
                <div class="text-sm text-gray-500">
                    ホーム ＞ アンケート一覧 ＞ プレビュー
                </div>

                <h1 class="text-2xl font-bold">
                    ${App.utils.escapeHTML(
                        survey.title
                    )}
                </h1>
            </div>

            <button
                class="border px-4 py-2 rounded-lg"
                onclick="
                    App.state.screen = 'edit';
                    App.render.current();
                "
            >
                編集画面へ戻る
            </button>
        </div>

        <div class="bg-white border rounded-xl p-6">
            ${
                survey.groups.map(
                    group => `
                        <section class="mb-8">
                            <h2 class="text-lg font-bold mb-4">
                                ${App.utils.escapeHTML(
                                    group.name
                                )}
                            </h2>

                            ${
                                group.questions.map(
                                    q => `
                                        <div class="
                                            mb-5
                                            border-b pb-4
                                        "
                                            data-preview-question
                                            data-question-id="${App.utils.escapeHTML(
                                                q.id
                                            )}"
                                        >
                                            <div class="
                                                font-medium mb-2
                                            ">
                                                ${App.utils.escapeHTML(
                                                    q.number
                                                )}
                                                ${App.utils.escapeHTML(
                                                    q.text
                                                )}
                                            </div>

                                            ${
                                                q.type === 'text'
                                                    ? `
                                                    <textarea
                                                        class="
                                                            w-full
                                                            border rounded
                                                            p-2
                                                        "
                                                        rows="3"
                                                    ></textarea>
                                                    `
                                                    : `
                                                    <div class="space-y-2">
                                                        ${
                                                            q.options.map(
                                                                option => `
                                                                <label class="
                                                                    flex
                                                                    items-center
                                                                    gap-2
                                                                ">
                                                                    <input
                                                                        type="${
                                                                            q.type ===
                                                                            'single'
                                                                                ? 'radio'
                                                                                : 'checkbox'
                                                                        }"
                                                                        name="${App.utils.escapeHTML(
                                                                            q.id
                                                                        )}"
                                                                    >
                                                                    ${App.utils.escapeHTML(
                                                                        option.text
                                                                    )}
                                                                </label>
                                                                `
                                                            ).join('')
                                                        }
                                                    </div>
                                                    `
                                            }
                                        </div>
                                    `
                                ).join('')
                            }
                        </section>
                    `
                ).join('')
            }
        </div>
    `;
};

App.actions.aggregate = function () {
    alert('集計画面へ遷移します。');
};

App.actions.send = function () {
    alert('送信画面へ遷移します。');
};

App.actions.updateBranchVisibility = function () {
    /*
     * 回答画面でanswersを評価するための共通Action。
     * 編集画面ではpreviewSurveyをそのまま表示する。
     */
};

App.actions.validateResponse = function (
    answers,
    survey
) {
    const visible = {};

    /*
     * 初期状態では全質問を表示。
     */
    survey.groups.forEach(group => {
        group.questions.forEach(question => {
            visible[question.id] = true;
        });
    });

    /*
     * singleの回答を基準に分岐。
     */
    survey.groups.forEach(group => {
        group.questions.forEach(question => {

            if (question.type !== 'single') {
                return;
            }

            const answer =
                answers[question.id];

            if (!answer) {
                return;
            }

            const option =
                question.options.find(
                    o => o.id === answer
                );

            if (!option) {
                return;
            }

            const target =
                question.branching?.[option.id];

            if (!target) {
                return;
            }

            let found = false;

            survey.groups.forEach(g => {
                g.questions.forEach(q => {
                    if (q.id === target) {
                        found = true;
                    }

                    if (found &&
                        q.id !== target) {
                        /*
                         * 分岐先から先は表示する。
                         * 分岐によって別途非表示にされた
                         * 質問はここでは変更しない。
                         */
                    }
                });
            });

            /*
             * 分岐先より前の質問は維持し、
             * 分岐先に直接到達するための表示制御を行う。
             */
            let after = false;

            survey.groups.forEach(g => {
                g.questions.forEach(q => {
                    if (q.id === target) {
                        after = true;
                    }

                    if (!after &&
                        q.id !== question.id) {
                        visible[q.id] = false;
                    }
                });
            });
        });
    });

    /*
     * 非表示質問は必須チェック対象外。
     */
    for (const group of survey.groups) {
        for (const question of group.questions) {
            if (!visible[question.id]) {
                continue;
            }

            if (question.required) {
                const answer =
                    answers[question.id];

                if (
                    answer === undefined ||
                    answer === null ||
                    answer === '' ||
                    (
                        Array.isArray(answer) &&
                        answer.length === 0
                    )
                ) {
                    return {
                        ok: false,
                        question_id: question.id,
                        message:
                            '必須項目に回答してください。'
                    };
                }
            }
        }
    }

    return {
        ok: true,
        visible
    };
};

App.actions.initResponse = function (
    survey
) {
    App.state.answers = {};
    App.state.responseSurvey = survey;
    App.state.branchVisibility = {};
    App.actions.updateBranchVisibility();
};

App.actions.submitResponse = function () {
    const result =
        App.actions.validateResponse(
            App.state.answers || {},
            App.state.responseSurvey
        );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    alert('回答を送信できます。');
};

App.actions.goHome = function () {
    App.actions.goList();
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
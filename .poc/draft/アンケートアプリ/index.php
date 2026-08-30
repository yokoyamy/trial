<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * Apache 2.4 / PHP 8.x
 * DBなし / PHP cURLなし
 * 単一エントリーポイント
 *
 * 永続化:
 *   _data/data.json
 *   _data/settings.json
 *
 * 機密情報:
 *   APP_ENCRYPTION_KEY
 *
 * 外部サービス:
 *   kintone REST API
 *   SMTP
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SET_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const MAX_TITLE       = 200;
const MAX_DESCRIPTION = 5000;
const MAX_QUESTION    = 1000;
const MAX_OPTION      = 500;

const HTTP_TIMEOUT = 30;

function h(mixed $v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function post_string(string $key): string
{
    $v = post($key, '');
    return is_scalar($v) ? trim((string)$v) : '';
}

function get_string(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
}

function post_bool(string $key): bool
{
    return in_array(
        strtolower((string)post($key, '')),
        ['1', 'true', 'on', 'yes'],
        true
    );
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function app_url(array $params = []): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php');

    if ($params === []) {
        return $script;
    }

    return $script . '?' . http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

function public_url(string $id): string
{
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    $scheme = $https ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host .
        app_url([
            'screen' => 'answer',
            'id' => $id,
        ]);
}

function cookie_path(): string
{
    $script = str_replace(
        '\\',
        '/',
        (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
    );

    $dir = dirname($script);

    if ($dir === '.' || $dir === '/' || $dir === '\\') {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

function start_app(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'データ保存フォルダを作成できません。'
            );
        }
    }

    if (!is_file(DATA_FILE)) {
        save_json(DATA_FILE, default_data());
    }

    if (!is_file(SET_FILE)) {
        save_json(SET_FILE, default_settings());
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure =
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

        session_name('survey_app_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => cookie_path(),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException(
                'セッションを開始できません。'
            );
        }
    }
}

function default_data(): array
{
    $n = now();

    return [
        'surveys' => [[
            'id' => 'survey-001',
            'title' => '顧客満足度アンケート',
            'description' => 'サービスについてのご意見をお聞かせください。',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' => date('Y-m-d\TH:i', strtotime('+30 days')),
            'status' => 'draft',
            'numbering' => 'global',
            'createdAt' => $n,
            'updatedAt' => $n,
            'groups' => [[
                'id' => 'group-001',
                'title' => '基本アンケート',
                'questions' => [[
                    'id' => 'question-001',
                    'number' => 'Q1',
                    'text' => 'サービスの満足度を教えてください。',
                    'type' => 'single',
                    'required' => true,
                    'options' => [
                        [
                            'id' => 'option-001',
                            'label' => '非常に満足',
                            'nextQuestionId' => '',
                        ],
                        [
                            'id' => 'option-002',
                            'label' => '満足',
                            'nextQuestionId' => '',
                        ],
                        [
                            'id' => 'option-003',
                            'label' => '普通',
                            'nextQuestionId' => '',
                        ],
                        [
                            'id' => 'option-004',
                            'label' => '不満',
                            'nextQuestionId' => '',
                        ],
                    ],
                ], [
                    'id' => 'question-002',
                    'number' => 'Q2',
                    'text' => 'ご意見・ご要望があれば入力してください。',
                    'type' => 'text',
                    'required' => false,
                    'options' => [],
                ]],
            ]],
        ]],
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
            'verify_ssl' => true,
            'mapping' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
            'fields' => [],
            'last_test' => null,
            'last_sync' => null,
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
            'last_test' => null,
        ],
    ];
}

function load_json(string $file, array $fallback): array
{
    if (!is_file($file)) {
        return $fallback;
    }

    $fp = @fopen($file, 'rb');

    if (!$fp) {
        return $fallback;
    }

    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

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
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException(
            'JSON保存データを生成できません。'
        );
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));

    $fp = @fopen($tmp, 'wb');

    if (!$fp) {
        throw new RuntimeException(
            '一時ファイルを作成できません。'
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException(
                'データファイルをロックできません。'
            );
        }

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException(
                'データを書き込めません。'
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!rename($tmp, $file)) {
            @unlink($tmp);

            throw new RuntimeException(
                'データファイルを更新できません。'
            );
        }
    } catch (Throwable $e) {
        @fclose($fp);
        @unlink($tmp);
        throw $e;
    }
}

function load_data(): array
{
    $data = load_json(
        DATA_FILE,
        default_data()
    );

    foreach (
        ['surveys', 'answers', 'customers', 'send_history']
        as $key
    ) {
        if (
            !isset($data[$key]) ||
            !is_array($data[$key])
        ) {
            $data[$key] = [];
        }
    }

    return $data;
}

function save_data(array $data): void
{
    save_json(DATA_FILE, $data);
}

function load_settings(): array
{
    $default = default_settings();

    $settings = load_json(
        SET_FILE,
        $default
    );

    foreach (
        ['kintone', 'mail'] as $service
    ) {
        $settings[$service] =
            array_replace_recursive(
                $default[$service],
                is_array($settings[$service] ?? null)
                    ? $settings[$service]
                    : []
            );
    }

    return $settings;
}

/*
 * APP_ENCRYPTION_KEY
 *
 * Windows + Apacheでは設定方法によって
 * getenv() / $_SERVER / $_ENV の取得結果が異なる場合が
 * あるため、3経路を確認する。
 */
function encryption_key(): string
{
    $value = getenv('APP_ENCRYPTION_KEY');

    if (
        $value === false ||
        trim((string)$value) === ''
    ) {
        $value = $_SERVER['APP_ENCRYPTION_KEY'] ?? '';
    }

    if (
        !is_string($value) ||
        trim($value) === ''
    ) {
        $value = $_ENV['APP_ENCRYPTION_KEY'] ?? '';
    }

    if (
        !is_string($value) ||
        trim($value) === ''
    ) {
        throw new RuntimeException(
            'APP_ENCRYPTION_KEY が設定されていません。'
            . ' Apache/PHPのサーバー環境変数に設定してください。'
        );
    }

    return hash(
        'sha256',
        trim($value),
        true
    );
}

function is_encrypted_secret(string $value): bool
{
    if ($value === '') {
        return false;
    }

    $decoded = base64_decode(
        $value,
        true
    );

    if ($decoded === false) {
        return false;
    }

    $payload = json_decode(
        $decoded,
        true
    );

    return is_array($payload)
        && (int)($payload['v'] ?? 0) === 1
        && isset(
            $payload['iv'],
            $payload['tag'],
            $payload['data']
        );
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $key = encryption_key();
    $iv = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException(
            '機密情報の暗号化に失敗しました。'
        );
    }

    $payload = [
        'v' => 1,
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'data' => base64_encode($cipher),
    ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException(
            '暗号化データを生成できません。'
        );
    }

    return base64_encode($json);
}

function decrypt_secret(string $encrypted): string
{
    if ($encrypted === '') {
        return '';
    }

    if (!is_encrypted_secret($encrypted)) {
        /*
         * 旧版の平文設定をそのまま秘密情報として扱わない。
         * 移行時に設定画面で再保存する。
         */
        return $encrypted;
    }

    $decoded = base64_decode(
        $encrypted,
        true
    );

    if ($decoded === false) {
        throw new RuntimeException(
            '保存された機密情報の形式が不正です。'
        );
    }

    $payload = json_decode(
        $decoded,
        true
    );

    if (!is_array($payload)) {
        throw new RuntimeException(
            '保存された機密情報の形式が不正です。'
        );
    }

    $iv = base64_decode(
        (string)($payload['iv'] ?? ''),
        true
    );

    $tag = base64_decode(
        (string)($payload['tag'] ?? ''),
        true
    );

    $cipher = base64_decode(
        (string)($payload['data'] ?? ''),
        true
    );

    if (
        $iv === false ||
        $tag === false ||
        $cipher === false
    ) {
        throw new RuntimeException(
            '保存された機密情報を復号できません。'
        );
    }

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plain === false) {
        throw new RuntimeException(
            'APP_ENCRYPTION_KEY が正しくないか、保存データが破損しています。'
        );
    }

    return $plain;
}

function runtime_settings(array $settings): array
{
    foreach (
        ['kintone', 'mail'] as $service
    ) {
        if (
            isset($settings[$service]['password'])
        ) {
            $settings[$service]['password'] =
                decrypt_secret(
                    (string)$settings[$service]['password']
                );
        }
    }

    return $settings;
}

function save_settings(array $settings): void
{
    foreach (
        ['kintone', 'mail'] as $service
    ) {
        if (
            isset($settings[$service]['password'])
        ) {
            $password =
                (string)$settings[$service]['password'];

            if (
                $password !== '' &&
                !is_encrypted_secret($password)
            ) {
                $settings[$service]['password'] =
                    encrypt_secret($password);
            }
        }
    }

    save_json(
        SET_FILE,
        $settings
    );
}

function flash(
    string $type,
    string $message
): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($f) ? $f : null;
}

function survey_index(
    array $surveys,
    string $id
): int {
    foreach (
        $surveys as $i => $survey
    ) {
        if (
            (string)($survey['id'] ?? '') === $id
        ) {
            return $i;
        }
    }

    return -1;
}

function survey_get(
    array $surveys,
    string $id
): ?array {
    $i = survey_index(
        $surveys,
        $id
    );

    return $i >= 0
        ? $surveys[$i]
        : null;
}

function all_questions(array $survey): array
{
    $out = [];

    foreach (
        $survey['groups'] ?? [] as $group
    ) {
        foreach (
            $group['questions'] ?? [] as $q
        ) {
            $out[] = $q;
        }
    }

    return $out;
}

function recalc_numbers(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach (
        $survey['groups'] as &$group
    ) {
        $questionNo = 1;

        foreach (
            $group['questions'] as &$question
        ) {
            if (
                ($survey['numbering'] ?? 'global') === 'group'
            ) {
                $question['number'] =
                    'Q' .
                    $groupNo .
                    '-' .
                    $questionNo;
            } else {
                $question['number'] =
                    'Q' . $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);
}

function refresh_status(array &$data): void
{
    $changed = false;

    foreach (
        $data['surveys'] as &$survey
    ) {
        if (
            ($survey['status'] ?? '') === 'published' &&
            !empty($survey['endAt'])
        ) {
            $t = strtotime(
                (string)$survey['endAt']
            );

            if (
                $t !== false &&
                $t < time()
            ) {
                $survey['status'] = 'ended';
                $survey['updatedAt'] = now();
                $changed = true;
            }
        }
    }

    unset($survey);

    if ($changed) {
        save_data($data);
    }
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

function validate_kintone(
    array $config
): array {
    $errors = [];

    $sub =
        preg_replace(
            '#^https?://#i',
            '',
            trim((string)($config['subdomain'] ?? ''))
        ) ?? '';

    $sub =
        preg_replace(
            '#/.*$#',
            '',
            $sub
        ) ?? $sub;

    $sub = preg_replace(
        '/\.cybozu\.com$/i',
        '',
        $sub
    ) ?? $sub;

    if (
        $sub === '' ||
        !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $sub
        )
    ) {
        $errors[] =
            'kintoneサブドメインが不正です。';
    }

    $app =
        trim((string)($config['app_id'] ?? ''));

    if (
        !ctype_digit($app) ||
        (int)$app < 1
    ) {
        $errors[] =
            '顧客管理アプリIDが不正です。';
    }

    if (
        trim((string)($config['username'] ?? '')) === ''
    ) {
        $errors[] =
            'ログイン名を入力してください。';
    }

    if (
        trim((string)($config['password'] ?? '')) === ''
    ) {
        $errors[] =
            'パスワードを入力してください。';
    }

    return $errors;
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $errors = validate_kintone(
        $config
    );

    if ($errors !== []) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $sub =
        preg_replace(
            '#^https?://#i',
            '',
            trim((string)$config['subdomain'])
        ) ?? '';

    $sub =
        preg_replace(
            '#/.*$#',
            '',
            $sub
        ) ?? $sub;

    $sub =
        preg_replace(
            '/\.cybozu\.com$/i',
            '',
            $sub
        ) ?? $sub;

    $url =
        'https://' .
        $sub .
        '.cybozu.com' .
        $path;

    $auth = base64_encode(
        (string)$config['username'] .
        ':' .
        (string)$config['password']
    );

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
        'Connection: close',
    ];

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            throw new RuntimeException(
                'kintoneリクエストを生成できません。'
            );
        }

        $headers[] =
            'Content-Type: application/json';
    }

    $verify =
        !empty($config['verify_ssl']);

    $options = [
        'http' => [
            'method' =>
                strtoupper($method),
            'header' =>
                implode(
                    "\r\n",
                    $headers
                ),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => HTTP_TIMEOUT,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' =>
                $sub . '.cybozu.com',
        ],
    ];

    $proxy =
        trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '') {
        if (
            !preg_match(
                '/^([^:]+):(\d{1,5})$/',
                $proxy,
                $m
            )
        ) {
            throw new RuntimeException(
                'Proxyはhost:port形式で指定してください。'
            );
        }

        $options['http']['proxy'] =
            'tcp://' .
            $m[1] .
            ':' .
            (int)$m[2];

        $options['http']['request_fulluri'] =
            true;
    }

    $context =
        stream_context_create($options);

    $response =
        @file_get_contents(
            $url,
            false,
            $context
        );

    $status = 0;

    foreach (
        $http_response_header ?? [] as $header
    ) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへの通信に失敗しました。'
        );
    }

    if (
        $status === 301 ||
        $status === 302 ||
        $status === 303 ||
        $status === 307 ||
        $status === 308
    ) {
        throw new RuntimeException(
            'kintoneからリダイレクト応答が返されました。'
        );
    }

    if (
        $status < 200 ||
        $status >= 300
    ) {
        $json =
            json_decode(
                $response,
                true
            );

        $message =
            is_array($json)
                ? (string)($json['message'] ?? '')
                : '';

        $code =
            is_array($json)
                ? (string)($json['code'] ?? '')
                : '';

        $error =
            'kintone APIエラー HTTP ' .
            $status;

        if ($code !== '') {
            $error .=
                ' [' . $code . ']';
        }

        if ($message !== '') {
            $error .=
                ' ' . $message;
        }

        throw new RuntimeException(
            $error
        );
    }

    $json =
        json_decode(
            $response,
            true
        );

    if (!is_array($json)) {
        throw new RuntimeException(
            'kintoneから正常なJSON応答を取得できませんでした。'
        );
    }

    return [
        'status' => $status,
        'body' => $json,
    ];
}

function kintone_fields(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode(
            (string)$config['app_id']
        )
    );
}

function kintone_records(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app=' .
        rawurlencode(
            (string)$config['app_id']
        ) .
        '&totalCount=true'
    );
}

function kintone_test(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id=' .
        rawurlencode(
            (string)$config['app_id']
        )
    );
}

function kintone_field_list(
    array $response
): array {
    $properties =
        $response['body']['properties'] ?? [];

    if (!is_array($properties)) {
        return [];
    }

    $result = [];

    foreach (
        $properties as $code => $field
    ) {
        if (!is_array($field)) {
            continue;
        }

        $result[] = [
            'code' => (string)$code,
            'label' =>
                (string)(
                    $field['label'] ?? $code
                ),
            'type' =>
                (string)(
                    $field['type'] ?? ''
                ),
        ];
    }

    usort(
        $result,
        static function (
            array $a,
            array $b
        ): int {
            return strnatcasecmp(
                $a['code'],
                $b['code']
            );
        }
    );

    return $result;
}

function krecord(
    array $record,
    string $code
): string {
    if (
        $code === '' ||
        !isset($record[$code]) ||
        !is_array($record[$code])
    ) {
        return '';
    }

    $value =
        $record[$code]['value'] ?? '';

    if (!is_array($value)) {
        return (string)$value;
    }

    $parts = [];

    foreach ($value as $item) {
        if (!is_array($item)) {
            $parts[] = (string)$item;
        } elseif (isset($item['name'])) {
            $parts[] =
                (string)$item['name'];
        } elseif (isset($item['value'])) {
            $parts[] =
                (string)$item['value'];
        }
    }

    return implode(
        ' ',
        array_filter(
            $parts,
            static fn(string $v): bool =>
                $v !== ''
        )
    );
}

function sync_kintone_customers(
    array $config
): array {
    $response =
        kintone_records($config);

    $records =
        $response['body']['records'] ?? [];

    if (!is_array($records)) {
        throw new RuntimeException(
            'kintoneから顧客レコードを取得できませんでした。'
        );
    }

    $mapping =
        $config['mapping'] ?? [];

    $customers = [];

    foreach (
        $records as $record
    ) {
        if (!is_array($record)) {
            continue;
        }

        $address = [];

        foreach (
            $mapping['address'] ?? [] as $code
        ) {
            $v =
                krecord(
                    $record,
                    (string)$code
                );

            if ($v !== '') {
                $address[] = $v;
            }
        }

        $email =
            krecord(
                $record,
                (string)(
                    $mapping['email'] ?? ''
                )
            );

        if (
            $email === '' ||
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            continue;
        }

        $customers[] = [
            'id' => uid('customer'),
            'organization' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['organization'] ?? ''
                    )
                ),
            'name' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['name'] ?? ''
                    )
                ),
            'email' => $email,
            'department' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['department'] ?? ''
                    )
                ),
            'phone' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['phone'] ?? ''
                    )
                ),
            'address' =>
                implode(
                    ' ',
                    $address
                ),
            'syncedAt' => now(),
        ];
    }

    return $customers;
}

function normalize_groups(
    mixed $input
): array {
    if (!is_array($input)) {
        return [];
    }

    $groups = [];

    foreach ($input as $group) {
        if (!is_array($group)) {
            continue;
        }

        $gid =
            trim(
                (string)($group['id'] ?? '')
            );

        if ($gid === '') {
            $gid = uid('group');
        }

        $title =
            mb_substr(
                trim(
                    (string)(
                        $group['title'] ?? ''
                    )
                ),
                0,
                MAX_TITLE
            );

        $questions = [];

        foreach (
            $group['questions'] ?? [] as $question
        ) {
            if (!is_array($question)) {
                continue;
            }

            $qid =
                trim(
                    (string)(
                        $question['id'] ?? ''
                    )
                );

            if ($qid === '') {
                $qid = uid('question');
            }

            $type =
                (string)(
                    $question['type'] ?? 'text'
                );

            if (
                !in_array(
                    $type,
                    [
                        'single',
                        'multiple',
                        'text',
                    ],
                    true
                )
            ) {
                $type = 'text';
            }

            $options = [];

            if (
                $type === 'single' ||
                $type === 'multiple'
            ) {
                foreach (
                    $question['options'] ?? [] as $option
                ) {
                    if (!is_array($option)) {
                        continue;
                    }

                    $oid =
                        trim(
                            (string)(
                                $option['id'] ?? ''
                            )
                        );

                    if ($oid === '') {
                        $oid = uid('option');
                    }

                    $options[] = [
                        'id' => $oid,
                        'label' =>
                            mb_substr(
                                trim(
                                    (string)(
                                        $option['label']
                                        ?? ''
                                    )
                                ),
                                0,
                                MAX_OPTION
                            ),
                        'nextQuestionId' =>
                            $type === 'single'
                                ? trim(
                                    (string)(
                                        $option[
                                            'nextQuestionId'
                                        ] ?? ''
                                    )
                                )
                                : '',
                    ];
                }
            }

            $questions[] = [
                'id' => $qid,
                'number' => '',
                'text' =>
                    mb_substr(
                        trim(
                            (string)(
                                $question['text'] ?? ''
                            )
                        ),
                        0,
                        MAX_QUESTION
                    ),
                'type' => $type,
                'required' =>
                    !empty(
                        $question['required']
                    ),
                'options' => $options,
            ];
        }

        $groups[] = [
            'id' => $gid,
            'title' => $title,
            'questions' => $questions,
        ];
    }

    return $groups;
}

function visible_questions(
    array $survey,
    array $answers
): array {
    $questions =
        all_questions($survey);

    if ($questions === []) {
        return [];
    }

    $byId = [];

    foreach ($questions as $question) {
        $byId[
            (string)$question['id']
        ] = $question;
    }

    $visible = [];
    $current = $questions[0];
    $visited = [];

    while (
        isset($current['id']) &&
        !isset(
            $visited[
                (string)$current['id']
            ]
        )
    ) {
        $id =
            (string)$current['id'];

        $visited[$id] = true;
        $visible[] = $current;

        $next = '';

        if (
            ($current['type'] ?? '') === 'single'
        ) {
            $answer =
                (string)(
                    $answers[$id] ?? ''
                );

            foreach (
                $current['options'] ?? [] as $option
            ) {
                if (
                    (string)(
                        $option['id'] ?? ''
                    ) === $answer
                ) {
                    $next =
                        (string)(
                            $option[
                                'nextQuestionId'
                            ] ?? ''
                        );
                    break;
                }
            }
        }

        if (
            $next === '' ||
            !isset($byId[$next])
        ) {
            break;
        }

        $current =
            $byId[$next];
    }

    return $visible;
}

function validate_answers(
    array $survey,
    array $answers
): array {
    $errors = [];

    foreach (
        visible_questions(
            $survey,
            $answers
        ) as $question
    ) {
        $id =
            (string)$question['id'];

        $value =
            $answers[$id] ?? '';

        if (!empty($question['required'])) {
            $empty =
                $value === '' ||
                $value === null ||
                (
                    is_array($value) &&
                    count($value) === 0
                );

            if ($empty) {
                $errors[] =
                    $question['number'] .
                    ' は必須です。';
            }
        }

        if (
            $question['type'] === 'multiple' &&
            $value !== '' &&
            !is_array($value)
        ) {
            $errors[] =
                $question['number'] .
                ' の回答形式が不正です。';
        }
    }

    return $errors;
}

function safe_external_error(
    Throwable $e
): string {
    $message = trim($e->getMessage());

    if ($message === '') {
        return '詳細不明の外部サービスエラーです。';
    }

    return $message;
}

function validate_mail(
    array $config
): array {
    $errors = [];

    if (
        trim((string)($config['host'] ?? '')) === ''
    ) {
        $errors[] =
            'SMTPサーバを入力してください。';
    }

    $port =
        (int)($config['port'] ?? 0);

    if (
        $port < 1 ||
        $port > 65535
    ) {
        $errors[] =
            'SMTPポートが不正です。';
    }

    if (
        !in_array(
            (string)(
                $config['encryption'] ?? ''
            ),
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        $errors[] =
            '暗号化方式が不正です。';
    }

    if (
        !filter_var(
            (string)(
                $config['from_email'] ?? ''
            ),
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '送信元メールアドレスが不正です。';
    }

    if (
        !empty($config['reply_to']) &&
        !filter_var(
            (string)$config['reply_to'],
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '返信先メールアドレスが不正です。';
    }

    if (
        !empty($config['auth']) &&
        trim(
            (string)(
                $config['username'] ?? ''
            )
        ) === ''
    ) {
        $errors[] =
            'SMTPユーザー名を入力してください。';
    }

    return $errors;
}

function smtp_read(
    $socket
): string {
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            preg_match(
                '/^\d{3}\s/',
                $line
            )
        ) {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException(
            'SMTPサーバから応答を取得できませんでした。'
        );
    }

    return $response;
}

function smtp_expect(
    $socket,
    array $codes
): string {
    $response =
        smtp_read($socket);

    $code =
        (int)substr(
            trim($response),
            0,
            3
        );

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' .
            $code
        );
    }

    return $response;
}

function smtp_command(
    $socket,
    string $command,
    array $codes
): string {
    if (
        fwrite(
            $socket,
            $command . "\r\n"
        ) === false
    ) {
        throw new RuntimeException(
            'SMTPコマンド送信に失敗しました。'
        );
    }

    return smtp_expect(
        $socket,
        $codes
    );
}

function smtp_connect(
    array $config
) {
    $errors =
        validate_mail($config);

    if ($errors !== []) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $host =
        (string)$config['host'];

    $port =
        (int)$config['port'];

    $encryption =
        (string)$config['encryption'];

    $target = $host;

    if ($encryption === 'ssl') {
        $target =
            'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $socket =
        @stream_socket_client(
            $target . ':' . $port,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT
        );

    if (!$socket) {
        throw new RuntimeException(
            'SMTP接続に失敗しました。'
        );
    }

    stream_set_timeout(
        $socket,
        30
    );

    try {
        smtp_expect(
            $socket,
            [220]
        );

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

            $crypto =
                stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTP TLS開始に失敗しました。'
                );
            }

            smtp_command(
                $socket,
                'EHLO localhost',
                [250]
            );
        }

        if (!empty($config['auth'])) {
            smtp_command(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            smtp_command(
                $socket,
                base64_encode(
                    (string)$config['username']
                ),
                [334]
            );

            smtp_command(
                $socket,
                base64_encode(
                    (string)$config['password']
                ),
                [235]
            );
        }

        return $socket;
    } catch (Throwable $e) {
        fclose($socket);
        throw $e;
    }
}

function smtp_test(
    array $config
): void {
    $socket =
        smtp_connect($config);

    try {
        smtp_command(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }
}

function smtp_send(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new RuntimeException(
            '送信先メールアドレスが不正です。'
        );
    }

    $socket =
        smtp_connect($config);

    try {
        smtp_command(
            $socket,
            'MAIL FROM:<' .
            $config['from_email'] .
            '>',
            [250]
        );

        smtp_command(
            $socket,
            'RCPT TO:<' .
            $to .
            '>',
            [250, 251]
        );

        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        $headers = [
            'From: ' .
                mb_encode_mimeheader(
                    (string)$config['from_name']
                ) .
                ' <' .
                $config['from_email'] .
                '>',
            'To: <' . $to . '>',
            'Subject: ' .
                mb_encode_mimeheader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (
            !empty($config['reply_to'])
        ) {
            $headers[] =
                'Reply-To: ' .
                $config['reply_to'];
        }

        $message =
            implode(
                "\r\n",
                $headers
            ) .
            "\r\n\r\n" .
            str_replace(
                "\n",
                "\r\n",
                str_replace(
                    "\r\n",
                    "\n",
                    $body
                )
            );

        $message =
            preg_replace(
                '/^\./m',
                '..',
                $message
            ) ?? $message;

        fwrite(
            $socket,
            $message .
            "\r\n.\r\n"
        );

        smtp_expect(
            $socket,
            [250]
        );

        smtp_command(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }
}

/* =========================================================
 * POST
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): array {
    $action =
        post_string('action');

    switch ($action) {

        case 'save_survey':
            $id =
                post_string('survey_id');

            $title =
                mb_substr(
                    post_string('title'),
                    0,
                    MAX_TITLE
                );

            if ($title === '') {
                flash(
                    'error',
                    'タイトルを入力してください。'
                );

                return [
                    'screen' => 'edit',
                    'id' => $id,
                ];
            }

            $start =
                post_string('startAt');

            $end =
                post_string('endAt');

            if (
                $start !== '' &&
                strtotime($start) === false
            ) {
                flash(
                    'error',
                    '開始日時が不正です。'
                );

                return [
                    'screen' => 'edit',
                    'id' => $id,
                ];
            }

            if (
                $end !== '' &&
                strtotime($end) === false
            ) {
                flash(
                    'error',
                    '終了日時が不正です。'
                );

                return [
                    'screen' => 'edit',
                    'id' => $id,
                ];
            }

            if (
                $start !== '' &&
                $end !== '' &&
                strtotime($start) >= strtotime($end)
            ) {
                flash(
                    'error',
                    '終了日時は開始日時より後にしてください。'
                );

                return [
                    'screen' => 'edit',
                    'id' => $id,
                ];
            }

            $index =
                survey_index(
                    $data['surveys'],
                    $id
                );

            $old =
                $index >= 0
                    ? $data['surveys'][$index]
                    : null;

            $survey = [
                'id' =>
                    $id !== ''
                        ? $id
                        : uid('survey'),
                'title' => $title,
                'description' =>
                    mb_substr(
                        post_string('description'),
                        0,
                        MAX_DESCRIPTION
                    ),
                'startAt' => $start,
                'endAt' => $end,
                'status' =>
                    $old !== null
                        ? (string)(
                            $old['status'] ?? 'draft'
                        )
                        : 'draft',
                'numbering' =>
                    in_array(
                        post_string('numbering'),
                        ['global', 'group'],
                        true
                    )
                        ? post_string('numbering')
                        : 'global',
                'createdAt' =>
                    $old['createdAt'] ?? now(),
                'updatedAt' => now(),
                'groups' =>
                    normalize_groups(
                        post(
                            'groups',
                            []
                        )
                    ),
            ];

            if ($survey['groups'] === []) {
                $survey['groups'][] = [
                    'id' => uid('group'),
                    'title' => '基本グループ',
                    'questions' => [],
                ];
            }

            recalc_numbers($survey);

            if ($index >= 0) {
                $data['surveys'][$index] =
                    $survey;
            } else {
                $data['surveys'][] =
                    $survey;
            }

            save_data($data);

            flash(
                'success',
                'アンケートを保存しました。'
            );

            return [
                'screen' => 'list',
            ];

        case 'change_status':
            $id =
                post_string('survey_id');

            $to =
                post_string('status');

            $index =
                survey_index(
                    $data['surveys'],
                    $id
                );

            if ($index < 0) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            $from =
                (string)(
                    $data['surveys'][$index]['status']
                    ?? 'draft'
                );

            $allowed =
                (
                    $from === 'draft' &&
                    $to === 'published'
                ) ||
                (
                    $from === 'published' &&
                    $to === 'stopped'
                ) ||
                (
                    $from === 'stopped' &&
                    $to === 'published'
                );

            if (!$allowed) {
                flash(
                    'error',
                    '指定された状態変更はできません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            $data['surveys'][$index]['status'] =
                $to;

            $data['surveys'][$index]['updatedAt'] =
                now();

            save_data($data);

            flash(
                'success',
                '状態を変更しました。'
            );

            return [
                'screen' => 'list',
            ];

        case 'duplicate_survey':
            $id =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $id
                );

            if (!$survey) {
                flash(
                    'error',
                    '複製元アンケートが見つかりません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            $survey['id'] =
                uid('survey');

            $survey['title'] =
                (string)$survey['title'] .
                '（複製）';

            $survey['status'] =
                'draft';

            $survey['createdAt'] =
                now();

            $survey['updatedAt'] =
                now();

            foreach (
                $survey['groups'] as &$group
            ) {
                $group['id'] =
                    uid('group');

                foreach (
                    $group['questions']
                    as &$question
                ) {
                    $question['id'] =
                        uid('question');

                    foreach (
                        $question['options']
                        as &$option
                    ) {
                        $option['id'] =
                            uid('option');
                    }

                    unset($option);
                }

                unset($question);
            }

            unset($group);

            recalc_numbers($survey);

            $data['surveys'][] =
                $survey;

            save_data($data);

            flash(
                'success',
                'アンケートを複製しました。'
            );

            return [
                'screen' => 'list',
            ];

        case 'delete_survey':
            $id =
                post_string('survey_id');

            $index =
                survey_index(
                    $data['surveys'],
                    $id
                );

            if ($index < 0) {
                flash(
                    'error',
                    'アンケートが見つかりません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            array_splice(
                $data['surveys'],
                $index,
                1
            );

            save_data($data);

            flash(
                'success',
                'アンケートを削除しました。'
            );

            return [
                'screen' => 'list',
            ];

        case 'save_kintone':
            $current =
                $settings['kintone'];

            $password =
                post_string('password');

            if ($password === '') {
                $password =
                    (string)(
                        $current['password'] ?? ''
                    );

                if (
                    is_encrypted_secret(
                        $password
                    )
                ) {
                    $password =
                        decrypt_secret(
                            $password
                        );
                }
            }

            $config = [
                'subdomain' =>
                    post_string('subdomain'),
                'app_id' =>
                    post_string('app_id'),
                'username' =>
                    post_string('username'),
                'password' =>
                    $password,
                'proxy' =>
                    post_string('proxy'),
                'verify_ssl' =>
                    post_bool('verify_ssl'),
                'mapping' =>
                    $current['mapping'] ?? [],
                'fields' =>
                    $current['fields'] ?? [],
                'last_test' =>
                    $current['last_test'] ?? null,
                'last_sync' =>
                    $current['last_sync'] ?? null,
            ];

            $errors =
                validate_kintone(
                    $config
                );

            if ($errors !== []) {
                flash(
                    'error',
                    implode(
                        "\n",
                        $errors
                    )
                );

                return [
                    'screen' => 'kintone',
                ];
            }

            /*
             * 実際の保存時に暗号化キーを確認する。
             * キーがない場合、平文保存しない。
             */
            try {
                encryption_key();
                $settings['kintone'] =
                    $config;

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone接続設定を保存しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    safe_external_error($e)
                );
            }

            return [
                'screen' => 'kintone',
            ];

        case 'test_kintone':
            try {
                $runtime =
                    runtime_settings(
                        $settings
                    );

                $response =
                    kintone_test(
                        $runtime['kintone']
                    );

                if (
                    ($response['status'] ?? 0) !== 200
                ) {
                    throw new RuntimeException(
                        'kintone接続テストに失敗しました。'
                    );
                }

                $settings['kintone']['last_test'] =
                    now();

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone接続テスト成功。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone接続テスト失敗：' .
                    safe_external_error($e)
                );
            }

            return [
                'screen' => 'kintone',
            ];

        case 'load_kintone_fields':
            try {
                $runtime =
                    runtime_settings(
                        $settings
                    );

                $response =
                    kintone_fields(
                        $runtime['kintone']
                    );

                $settings['kintone']['fields'] =
                    kintone_field_list(
                        $response
                    );

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'kintone項目一覧を取得しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    '項目一覧取得失敗：' .
                    safe_external_error($e)
                );
            }

            return [
                'screen' => 'kintone',
            ];

        case 'save_kintone_mapping':
            $mapping = [
                'organization' =>
                    post_string(
                        'mapping_organization'
                    ),
                'name' =>
                    post_string(
                        'mapping_name'
                    ),
                'email' =>
                    post_string(
                        'mapping_email'
                    ),
                'department' =>
                    post_string(
                        'mapping_department'
                    ),
                'phone' =>
                    post_string(
                        'mapping_phone'
                    ),
                'address' => [],
            ];

            $address =
                post(
                    'mapping_address',
                    []
                );

            if (is_array($address)) {
                $mapping['address'] =
                    array_values(
                        array_map(
                            'strval',
                            $address
                        )
                    );
            }

            $settings['kintone']['mapping'] =
                $mapping;

            save_settings(
                $settings
            );

            flash(
                'success',
                '顧客項目マッピングを保存しました。'
            );

            return [
                'screen' => 'kintone',
            ];

        case 'sync_kintone':
            try {
                $runtime =
                    runtime_settings(
                        $settings
                    );

                $customers =
                    sync_kintone_customers(
                        $runtime['kintone']
                    );

                /*
                 * 同期結果をサーバー側へ保存してから
                 * 顧客一覧画面へ遷移する。
                 */
                $data['customers'] =
                    $customers;

                $settings['kintone']['last_sync'] =
                    now();

                save_data($data);
                save_settings($settings);

                flash(
                    'success',
                    count($customers) .
                    '件の顧客情報を同期しました。'
                );

                return [
                    'screen' => 'customers',
                ];
            } catch (Throwable $e) {
                flash(
                    'error',
                    'kintone顧客同期失敗：' .
                    safe_external_error($e)
                );

                return [
                    'screen' => 'kintone',
                ];
            }

        case 'save_mail':
            $current =
                $settings['mail'];

            $password =
                post_string('password');

            if ($password === '') {
                $password =
                    (string)(
                        $current['password'] ?? ''
                    );

                if (
                    is_encrypted_secret(
                        $password
                    )
                ) {
                    $password =
                        decrypt_secret(
                            $password
                        );
                }
            }

            $config = [
                'host' =>
                    post_string('host'),
                'port' =>
                    (int)post_string('port'),
                'encryption' =>
                    post_string('encryption'),
                'auth' =>
                    post_bool('auth'),
                'username' =>
                    post_string('username'),
                'password' =>
                    $password,
                'from_email' =>
                    post_string('from_email'),
                'from_name' =>
                    post_string('from_name'),
                'reply_to' =>
                    post_string('reply_to'),
                'last_test' =>
                    $current['last_test'] ?? null,
            ];

            $errors =
                validate_mail(
                    $config
                );

            if ($errors !== []) {
                flash(
                    'error',
                    implode(
                        "\n",
                        $errors
                    )
                );

                return [
                    'screen' => 'mail',
                ];
            }

            try {
                $settings['mail'] =
                    $config;

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'メール設定を保存しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    safe_external_error($e)
                );
            }

            return [
                'screen' => 'mail',
            ];

        case 'test_mail':
            try {
                $runtime =
                    runtime_settings(
                        $settings
                    );

                smtp_test(
                    $runtime['mail']
                );

                $settings['mail']['last_test'] =
                    now();

                save_settings(
                    $settings
                );

                flash(
                    'success',
                    'SMTP接続テスト成功。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'SMTP接続テスト失敗：' .
                    safe_external_error($e)
                );
            }

            return [
                'screen' => 'mail',
            ];

        case 'send_test_mail':
            try {
                $to =
                    post_string('test_email');

                if (
                    !filter_var(
                        $to,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    throw new RuntimeException(
                        '宛先メールアドレスが不正です。'
                    );
                }

                $runtime =
                    runtime_settings(
                        $settings
                    );

                smtp_send(
                    $runtime['mail'],
                    $to,
                    'アンケートアプリ テストメール',
                    'SMTP接続およびメール送信テストです。'
                );

                flash(
                    'success',
                    'テストメールを送信しました。'
                );
            } catch (Throwable $e) {
                flash(
                    'error',
                    'テストメール送信失敗：' .
                    safe_external_error($e)
                );
            }

            return [
                'screen' => 'mail',
            ];

        case 'send_mail':
            $surveyId =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $surveyId
                );

            if (!$survey) {
                flash(
                    'error',
                    '対象アンケートが見つかりません。'
                );

                return [
                    'screen' => 'list',
                ];
            }

            $selected =
                post(
                    'customers',
                    []
                );

            if (!is_array($selected)) {
                $selected = [];
            }

            $subject =
                post_string('subject');

            $body =
                (string)post(
                    'body',
                    ''
                );

            if ($subject === '') {
                $subject =
                    (string)$survey['title'];
            }

            if ($body === '') {
                $body =
                    "アンケートへのご協力をお願いいたします。\n\n" .
                    "{アンケートURL}";
            }

            $runtime =
                runtime_settings(
                    $settings
                );

            $sent = 0;
            $failed = 0;

            foreach (
                $data['customers'] as $customer
            ) {
                if (
                    !in_array(
                        (string)($customer['id'] ?? ''),
                        array_map(
                            'strval',
                            $selected
                        ),
                        true
                    )
                ) {
                    continue;
                }

                $name =
                    (string)(
                        $customer['name'] ?? ''
                    );

                $mailBody =
                    str_replace(
                        [
                            '{顧客名}',
                            '{アンケートURL}',
                        ],
                        [
                            $name,
                            public_url(
                                $surveyId
                            ),
                        ],
                        $body
                    );

                try {
                    smtp_send(
                        $runtime['mail'],
                        (string)$customer['email'],
                        $subject,
                        $mailBody
                    );

                    $sent++;

                    $data['send_history'][] = [
                        'id' => uid('send'),
                        'surveyId' => $surveyId,
                        'customerId' =>
                            $customer['id'],
                        'customerName' =>
                            $name,
                        'email' =>
                            $customer['email'],
                        'status' => 'sent',
                        'sentAt' => now(),
                    ];
                } catch (Throwable $e) {
                    $failed++;

                    $data['send_history'][] = [
                        'id' => uid('send'),
                        'surveyId' => $surveyId,
                        'customerId' =>
                            $customer['id'],
                        'customerName' =>
                            $name,
                        'email' =>
                            $customer['email'],
                        'status' => 'failed',
                        'sentAt' => now(),
                        'error' =>
                            safe_external_error($e),
                    ];
                }
            }

            save_data($data);

            flash(
                $failed === 0
                    ? 'success'
                    : 'warning',
                '送信完了：成功 ' .
                $sent .
                '件、失敗 ' .
                $failed .
                '件'
            );

            return [
                'screen' => 'send',
                'id' => $surveyId,
            ];

        case 'answer_next':
            $id =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $id
                );

            if (!$survey) {
                return [
                    'screen' => 'answer',
                    'id' => $id,
                ];
            }

            $answers =
                post(
                    'answers',
                    []
                );

            if (!is_array($answers)) {
                $answers = [];
            }

            $answers =
                array_map(
                    static function (
                        mixed $v
                    ): mixed {
                        if (is_array($v)) {
                            return array_values(
                                array_map(
                                    'strval',
                                    $v
                                )
                            );
                        }

                        return is_scalar($v)
                            ? trim((string)$v)
                            : '';
                    },
                    $answers
                );

            $errors =
                validate_answers(
                    $survey,
                    $answers
                );

            if ($errors !== []) {
                $_SESSION[
                    'answer_' . $id
                ] = $answers;

                flash(
                    'error',
                    implode(
                        "\n",
                        $errors
                    )
                );

                return [
                    'screen' => 'answer',
                    'id' => $id,
                ];
            }

            $_SESSION[
                'answer_' . $id
            ] = $answers;

            return [
                'screen' => 'confirm',
                'id' => $id,
            ];

        case 'answer_back':
            return [
                'screen' => 'answer',
                'id' =>
                    post_string('survey_id'),
            ];

        case 'answer_submit':
            $id =
                post_string('survey_id');

            $survey =
                survey_get(
                    $data['surveys'],
                    $id
                );

            if (!$survey) {
                return [
                    'screen' => 'complete',
                    'id' => $id,
                ];
            }

            $answers =
                $_SESSION[
                    'answer_' . $id
                ] ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            $errors =
                validate_answers(
                    $survey,
                    $answers
                );

            if ($errors !== []) {
                flash(
                    'error',
                    implode(
                        "\n",
                        $errors
                    )
                );

                return [
                    'screen' => 'answer',
                    'id' => $id,
                ];
            }

            $data['answers'][] = [
                'id' => uid('answer'),
                'surveyId' => $id,
                'answers' => $answers,
                'createdAt' => now(),
            ];

            save_data($data);

            unset(
                $_SESSION[
                    'answer_' . $id
                ]
            );

            return [
                'screen' => 'complete',
                'id' => $id,
            ];

        default:
            return [
                'screen' => 'list',
            ];
    }
}

/* =========================================================
 * HTML
 * ========================================================= */

function admin_header(
    string $title,
    ?array $flash = null
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_TITLE) ?></title>
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
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 "Hiragino Kaku Gothic ProN",
 Meiryo,sans-serif;
}
header{
 background:#0f172a;
 color:#fff;
}
.nav{
 max-width:1280px;
 margin:auto;
 padding:14px 20px;
 display:flex;
 gap:18px;
 align-items:center;
 flex-wrap:wrap;
}
.nav strong{margin-right:auto}
.nav a{
 color:#fff;
 text-decoration:none;
}
.wrap{
 max-width:1280px;
 margin:auto;
 padding:24px 20px 50px;
}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:14px;
 padding:20px;
 margin-bottom:18px;
 box-shadow:var(--shadow);
}
.grid{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:16px;
}
.field{margin-bottom:16px}
.field label{
 display:block;
 font-weight:600;
 margin-bottom:6px;
}
input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
textarea,
select{
 width:100%;
 border:1px solid #cbd5e1;
 border-radius:8px;
 padding:10px 12px;
 font:inherit;
 background:#fff;
}
textarea{
 min-height:120px;
 resize:vertical;
}
button,.btn{
 display:inline-block;
 padding:9px 14px;
 border-radius:8px;
 border:1px solid #cbd5e1;
 background:#fff;
 color:var(--text);
 text-decoration:none;
 cursor:pointer;
 font:inherit;
}
button.primary,.btn.primary{
 background:var(--primary);
 border-color:var(--primary);
 color:#fff;
}
button.danger,.btn.danger{
 background:#fff;
 border-color:#fecaca;
 color:var(--danger);
}
button:hover,.btn:hover{
 opacity:.9;
}
.actions{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
 align-items:center;
}
.flash{
 padding:14px;
 border-radius:10px;
 margin-bottom:18px;
 white-space:pre-line;
}
.flash.success{
 background:#dcfce7;
 color:#166534;
}
.flash.error{
 background:#fee2e2;
 color:#991b1b;
}
.flash.warning{
 background:#fef3c7;
 color:#92400e;
}
.badge{
 display:inline-block;
 padding:4px 8px;
 border-radius:999px;
 font-size:12px;
}
.badge.success{
 background:#dcfce7;
 color:#166534;
}
.badge.warning{
 background:#fef3c7;
 color:#92400e;
}
.badge.gray{
 background:#e2e8f0;
 color:#475569;
}
.table-wrap{
 overflow-x:auto;
}
table{
 width:100%;
 border-collapse:collapse;
 min-width:900px;
}
th,td{
 padding:10px;
 border-bottom:1px solid #e2e8f0;
 text-align:left;
 vertical-align:top;
}
th{background:#f8fafc}
.question{
 border:1px solid var(--border);
 border-radius:10px;
 padding:16px;
 margin:12px 0;
 background:#fff;
}
.group{
 border:2px solid #e2e8f0;
 border-radius:12px;
 padding:16px;
 margin:18px 0;
 background:#f8fafc;
}
.drag{
 cursor:grab;
}
.option-row{
 display:grid;
 grid-template-columns:1fr auto;
 gap:8px;
 margin:8px 0;
}
.toolbar{
 display:flex;
 justify-content:space-between;
 gap:10px;
 flex-wrap:wrap;
 margin-bottom:18px;
}
.small{
 color:#64748b;
 font-size:13px;
}
.answer-choice{
 display:block;
 padding:12px;
 margin:8px 0;
 border:1px solid #dbe2ea;
 border-radius:8px;
 background:#fff;
}
@media(max-width:700px){
 .wrap{padding:16px 12px 40px}
 .grid{grid-template-columns:1fr}
 .card{padding:16px}
 .nav{padding:12px}
}
</style>
</head>
<body>
<header>
<div class="nav">
<strong><?= h(APP_TITLE) ?></strong>
<a href="<?= h(app_url(['screen'=>'list'])) ?>">
アンケート一覧
</a>
<a href="<?= h(app_url(['screen'=>'kintone'])) ?>">
kintone
</a>
<a href="<?= h(app_url(['screen'=>'mail'])) ?>">
メール設定
</a>
</div>
</header>
<main class="wrap">
<?php if ($flash): ?>
<div class="flash <?= h($flash['type'] ?? 'success') ?>">
<?= nl2br(h($flash['message'] ?? '')) ?>
</div>
<?php endif; ?>
<?php
}

function admin_footer(): void
{
?>
</main>
</body>
</html>
<?php
}

function render_list(
    array $data
): void {
    $q =
        get_string('q');

    $status =
        get_string('status');

    if ($status === '') {
        $status = 'all';
    }

    $sort =
        get_string('sort');

    if ($sort === '') {
        $sort = 'new';
    }

    $surveys =
        array_values(
            array_filter(
                $data['surveys'],
                static function (
                    array $survey
                ) use (
                    $q,
                    $status
                ): bool {
                    if (
                        $q !== '' &&
                        mb_stripos(
                            (string)(
                                $survey['title'] ?? ''
                            ),
                            $q
                        ) === false
                    ) {
                        return false;
                    }

                    if (
                        $status !== 'all' &&
                        (string)(
                            $survey['status'] ?? ''
                        ) !== $status
                    ) {
                        return false;
                    }

                    return true;
                }
            )
        );

    usort(
        $surveys,
        static function (
            array $a,
            array $b
        ) use ($sort): int {
            if ($sort === 'answers_desc') {
                return 0;
            }

            if ($sort === 'old') {
                return strcmp(
                    (string)(
                        $a['updatedAt'] ?? ''
                    ),
                    (string)(
                        $b['updatedAt'] ?? ''
                    )
                );
            }

            if ($sort === 'start_desc') {
                return strcmp(
                    (string)(
                        $b['startAt'] ?? ''
                    ),
                    (string)(
                        $a['startAt'] ?? ''
                    )
                );
            }

            if ($sort === 'start_old') {
                return strcmp(
                    (string)(
                        $a['startAt'] ?? ''
                    ),
                    (string)(
                        $b['startAt'] ?? ''
                    )
                );
            }

            return strcmp(
                (string)(
                    $b['updatedAt'] ?? ''
                ),
                (string)(
                    $a['updatedAt'] ?? ''
                )
            );
        }
    );

    $flash =
        flash_get();

    admin_header(
        'アンケート一覧',
        $flash
    );
?>
<div class="card">
<div class="toolbar">
<h1>アンケート一覧</h1>
<a class="btn primary"
   href="<?= h(app_url(['screen'=>'edit'])) ?>">
新規作成
</a>
</div>

<form method="get">
<input type="hidden" name="screen" value="list">
<div class="grid">
<div class="field">
<label>タイトル検索</label>
<input type="text"
       name="q"
       value="<?= h($q) ?>"
       placeholder="タイトル部分一致">
</div>

<div class="field">
<label>ステータス</label>
<select name="status">
<option value="all">すべて</option>
<option value="published"
 <?= $status === 'published' ? 'selected' : '' ?>>
公開中
</option>
<option value="draft"
 <?= $status === 'draft' ? 'selected' : '' ?>>
下書き
</option>
<option value="stopped"
 <?= $status === 'stopped' ? 'selected' : '' ?>>
停止
</option>
<option value="ended"
 <?= $status === 'ended' ? 'selected' : '' ?>>
終了
</option>
</select>
</div>

<div class="field">
<label>ソート</label>
<select name="sort">
<option value="new"
 <?= $sort === 'new' ? 'selected' : '' ?>>
更新日：新しい順
</option>
<option value="old"
 <?= $sort === 'old' ? 'selected' : '' ?>>
更新日：古い順
</option>
<option value="start_desc"
 <?= $sort === 'start_desc' ? 'selected' : '' ?>>
開始日：新しい順
</option>
<option value="start_old"
 <?= $sort === 'start_old' ? 'selected' : '' ?>>
開始日：古い順
</option>
</select>
</div>
</div>

<button class="primary">検索</button>
</form>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>タイトル</th>
<th>期間</th>
<th>ステータス</th>
<th>作成日</th>
<th>更新日</th>
<th>回答数</th>
<th>操作</th>
</tr>
</thead>
<tbody>
<?php foreach ($surveys as $survey): ?>
<?php
$count = 0;

foreach ($data['answers'] as $answer) {
    if (
        (string)($answer['surveyId'] ?? '') ===
        (string)($survey['id'] ?? '')
    ) {
        $count++;
    }
}
?>
<tr>
<td>
<strong><?= h($survey['title'] ?? '') ?></strong>
</td>
<td>
<?= h($survey['startAt'] ?? '') ?>
<br>～<br>
<?= h($survey['endAt'] ?? '') ?>
</td>
<td>
<span class="badge <?= h(
    status_class(
        (string)(
            $survey['status'] ?? ''
        )
    )
) ?>">
<?= h(
    status_label(
        (string)(
            $survey['status'] ?? ''
        )
    )
) ?>
</span>
</td>
<td><?= h($survey['createdAt'] ?? '') ?></td>
<td><?= h($survey['updatedAt'] ?? '') ?></td>
<td><?= h($count) ?></td>
<td>
<div class="actions">
<a class="btn"
 href="<?= h(app_url([
     'screen'=>'edit',
     'id'=>$survey['id']
 ])) ?>">
確認・編集
</a>

<a class="btn"
 href="<?= h(app_url([
     'screen'=>'preview',
     'id'=>$survey['id']
 ])) ?>">
プレビュー
</a>

<a class="btn"
 href="<?= h(app_url([
     'screen'=>'analytics',
     'id'=>$survey['id']
 ])) ?>">
集計
</a>

<a class="btn"
 href="<?= h(app_url([
     'screen'=>'send',
     'id'=>$survey['id']
 ])) ?>">
送信
</a>

<form method="post"
 onsubmit="return confirm('複製しますか？')">
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button>複製</button>
</form>

<?php if (
    ($survey['status'] ?? '') === 'draft'
): ?>
<form method="post"
 onsubmit="return confirm('公開しますか？')">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="status"
       value="published">
<button class="primary">公開</button>
</form>
<?php elseif (
    ($survey['status'] ?? '') === 'published'
): ?>
<form method="post"
 onsubmit="return confirm('停止しますか？')">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="status"
       value="stopped">
<button>停止</button>
</form>
<?php elseif (
    ($survey['status'] ?? '') === 'stopped'
): ?>
<form method="post"
 onsubmit="return confirm('再開しますか？')">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<input type="hidden"
       name="status"
       value="published">
<button class="primary">再開</button>
</form>
<?php endif; ?>

<form method="post"
 onsubmit="return confirm('削除しますか？')">
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">
<button class="danger">削除</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>

<?php if ($surveys === []): ?>
<tr>
<td colspan="7">
該当するアンケートがありません。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<?php
    admin_footer();
}

function render_edit(
    array $data,
    ?string $id
): void {
    $survey =
        $id !== null
            ? survey_get(
                $data['surveys'],
                $id
            )
            : null;

    if (!$survey) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [[
                'id' => uid('group'),
                'title' => '基本グループ',
                'questions' => [],
            ]],
        ];
    }

    $flash =
        flash_get();

    admin_header(
        'アンケート作成・編集',
        $flash
    );
?>
<div class="card">
<div class="toolbar">
<div>
<h1>
<?= $survey['id'] !== ''
    ? 'アンケート編集'
    : 'アンケート作成' ?>
</h1>
<span class="badge <?= h(
    status_class(
        (string)$survey['status']
    )
) ?>">
<?= h(
    status_label(
        (string)$survey['status']
    )
) ?>
</span>
</div>

<div class="actions">
<a class="btn"
   href="<?= h(app_url([
       'screen'=>'list'
   ])) ?>">
キャンセル
</a>

<?php if ($survey['id'] !== ''): ?>
<a class="btn"
   href="<?= h(app_url([
       'screen'=>'preview',
       'id'=>$survey['id']
   ])) ?>">
プレビュー
</a>
<?php endif; ?>

<button form="survey-form"
        class="primary">
保存して一覧へ
</button>
</div>
</div>

<form id="survey-form"
      method="post">
<input type="hidden"
       name="action"
       value="save_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="grid">
<div class="field">
<label>アンケートタイトル</label>
<input type="text"
       name="title"
       maxlength="<?= MAX_TITLE ?>"
       value="<?= h($survey['title']) ?>"
       required>
</div>

<div class="field">
<label>質問番号の採番方式</label>
<select name="numbering">
<option value="global"
 <?= ($survey['numbering'] ?? 'global') === 'global'
     ? 'selected'
     : '' ?>>
アンケート全体で通番（Q1、Q2…）
</option>
<option value="group"
 <?= ($survey['numbering'] ?? '') === 'group'
     ? 'selected'
     : '' ?>>
グループ毎（Q1-1、Q1-2…）
</option>
</select>
</div>
</div>

<div class="field">
<label>アンケート説明</label>
<textarea name="description"
          maxlength="<?= MAX_DESCRIPTION ?>"><?= h(
    $survey['description']
) ?></textarea>
</div>

<div class="grid">
<div class="field">
<label>開始日時</label>
<input type="datetime-local"
       name="startAt"
       value="<?= h($survey['startAt']) ?>">
</div>

<div class="field">
<label>終了日時</label>
<input type="datetime-local"
       name="endAt"
       value="<?= h($survey['endAt']) ?>">
</div>
</div>

<div id="groups">
<?php foreach (
    $survey['groups'] as $group
): ?>
<div class="group drag"
     draggable="true">

<input type="hidden"
       name="groups[<?= h($group['id']) ?>][id]"
       value="<?= h($group['id']) ?>">

<div class="toolbar">
<div style="flex:1">
<div class="field">
<label>グループタイトル</label>
<input type="text"
       name="groups[<?= h($group['id']) ?>][title]"
       value="<?= h($group['title']) ?>">
</div>
</div>

<button type="button"
        onclick="removeGroup(this)">
グループ削除
</button>
</div>

<div class="questions">
<?php foreach (
    $group['questions'] as $question
): ?>
<div class="question drag"
     draggable="true">

<input type="hidden"
       name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][id]"
       value="<?= h($question['id']) ?>">

<div class="field">
<label>
<?= h($question['number']) ?> 質問文
</label>
<input type="text"
       name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][text]"
       value="<?= h($question['text']) ?>"
       maxlength="<?= MAX_QUESTION ?>">
</div>

<div class="grid">
<div class="field">
<label>回答形式</label>
<select name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][type]"
        onchange="toggleOptions(this)">
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
</div>

<div class="field">
<label>
<input type="checkbox"
       name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][required]"
       value="1"
 <?= !empty($question['required'])
     ? 'checked'
     : '' ?>>
必須
</label>
</div>
</div>

<div class="options"
     style="<?= $question['type'] === 'text'
         ? 'display:none'
         : '' ?>">
<label>選択肢</label>

<div class="option-list">
<?php foreach (
    $question['options'] as $option
): ?>
<div class="option-row">
<div>
<input type="hidden"
       name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][options][<?= h($option['id']) ?>][id]"
       value="<?= h($option['id']) ?>">

<input type="text"
       name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][options][<?= h($option['id']) ?>][label]"
       value="<?= h($option['label']) ?>"
       placeholder="選択肢">
</div>

<?php if (
    $question['type'] === 'single'
): ?>
<div>
<select name="groups[<?= h($group['id']) ?>][questions][<?= h($question['id']) ?>][options][<?= h($option['id']) ?>][nextQuestionId]">
<option value="">次の質問へ</option>
<?php foreach (
    all_questions($survey) as $target
): ?>
<?php if (
    $target['id'] !== $question['id']
): ?>
<option value="<?= h($target['id']) ?>"
 <?= ($option['nextQuestionId'] ?? '') === $target['id']
     ? 'selected'
     : '' ?>>
<?= h($target['number']) ?>
<?= h($target['text']) ?>
</option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<?php endif; ?>

<button type="button"
        onclick="removeOption(this)">
削除
</button>
</div>
<?php endforeach; ?>
</div>

<button type="button"
        onclick="addOption(this)">
選択肢を追加
</button>
</div>

<div class="actions">
<button type="button"
        onclick="removeQuestion(this)">
質問を削除
</button>
</div>
</div>
<?php endforeach; ?>
</div>

<div class="actions">
<button type="button"
        onclick="addQuestion(this)">
質問を追加
</button>
</div>
</div>
<?php endforeach; ?>
</div>

<div class="actions">
<button type="button"
        class="primary"
        onclick="addGroup()">
グループを追加
</button>
</div>
</form>
</div>

<script>
function id(prefix){
    return prefix + '-' +
        Math.random()
            .toString(16)
            .slice(2) +
        Date.now();
}

function removeGroup(button){
    if(!confirm('グループを削除しますか？')){
        return;
    }

    const group =
        button.closest('.group');

    if(group){
        group.remove();
    }
}

function removeQuestion(button){
    if(!confirm('質問を削除しますか？')){
        return;
    }

    const question =
        button.closest('.question');

    if(question){
        question.remove();
    }
}

function removeOption(button){
    const row =
        button.closest('.option-row');

    if(row){
        row.remove();
    }
}

function toggleOptions(select){
    const question =
        select.closest('.question');

    if(!question){
        return;
    }

    const options =
        question.querySelector('.options');

    if(!options){
        return;
    }

    options.style.display =
        select.value === 'text'
            ? 'none'
            : '';
}

function addOption(button){
    const question =
        button.closest('.question');

    if(!question){
        return;
    }

    const gid =
        question.closest('.group')
            .querySelector(
                'input[name$="[id]"]'
            );

    const qid =
        question.querySelector(
            'input[name$="[id]"]'
        );

    if(!gid || !qid){
        return;
    }

    const groupId = gid.value;
    const questionId = qid.value;
    const optionId = id('option');

    const list =
        question.querySelector('.option-list');

    const row =
        document.createElement('div');

    row.className = 'option-row';

    row.innerHTML =
        '<div>' +
        '<input type="hidden" name="groups[' +
        groupId +
        '][questions][' +
        questionId +
        '][options][' +
        optionId +
        '][id]" value="' +
        optionId +
        '">' +
        '<input type="text" name="groups[' +
        groupId +
        '][questions][' +
        questionId +
        '][options][' +
        optionId +
        '][label]" placeholder="選択肢">' +
        '</div>' +
        '<button type="button" onclick="removeOption(this)">削除</button>';

    list.appendChild(row);
}

function addQuestion(button){
    const group =
        button.closest('.group');

    if(!group){
        return;
    }

    const gid =
        group.querySelector(
            'input[name$="[id]"]'
        );

    if(!gid){
        return;
    }

    const groupId = gid.value;
    const questionId = id('question');

    const questions =
        group.querySelector('.questions');

    const div =
        document.createElement('div');

    div.className =
        'question drag';

    div.draggable = true;

    div.innerHTML =
        '<input type="hidden" name="groups[' +
        groupId +
        '][questions][' +
        questionId +
        '][id]" value="' +
        questionId +
        '">' +

        '<div class="field">' +
        '<label>質問文</label>' +
        '<input type="text" name="groups[' +
        groupId +
        '][questions][' +
        questionId +
        '][text]" maxlength="<?= MAX_QUESTION ?>">' +
        '</div>' +

        '<div class="grid">' +
        '<div class="field">' +
        '<label>回答形式</label>' +
        '<select name="groups[' +
        groupId +
        '][questions][' +
        questionId +
        '][type]" onchange="toggleOptions(this)">' +
        '<option value="single">単一選択</option>' +
        '<option value="multiple">複数選択</option>' +
        '<option value="text">自由記述</option>' +
        '</select>' +
        '</div>' +
        '<div class="field">' +
        '<label><input type="checkbox" name="groups[' +
        groupId +
        '][questions][' +
        questionId +
        '][required]" value="1"> 必須</label>' +
        '</div>' +
        '</div>' +

        '<div class="options">' +
        '<label>選択肢</label>' +
        '<div class="option-list"></div>' +
        '<button type="button" onclick="addOption(this)">選択肢を追加</button>' +
        '</div>' +

        '<div class="actions">' +
        '<button type="button" onclick="removeQuestion(this)">質問を削除</button>' +
        '</div>';

    questions.appendChild(div);
}

function addGroup(){
    const groups =
        document.getElementById('groups');

    const groupId =
        id('group');

    const div =
        document.createElement('div');

    div.className =
        'group drag';

    div.draggable = true;

    div.innerHTML =
        '<input type="hidden" name="groups[' +
        groupId +
        '][id]" value="' +
        groupId +
        '">' +

        '<div class="toolbar">' +
        '<div style="flex:1">' +
        '<div class="field">' +
        '<label>グループタイトル</label>' +
        '<input type="text" name="groups[' +
        groupId +
        '][title]" value="新しいグループ">' +
        '</div>' +
        '</div>' +
        '<button type="button" onclick="removeGroup(this)">グループ削除</button>' +
        '</div>' +

        '<div class="questions"></div>' +

        '<div class="actions">' +
        '<button type="button" onclick="addQuestion(this)">質問を追加</button>' +
        '</div>';

    groups.appendChild(div);
}

let dragItem = null;

document.addEventListener(
    'dragstart',
    function(e){
        const item =
            e.target.closest(
                '.drag'
            );

        if(item){
            dragItem = item;
        }
    }
);

document.addEventListener(
    'dragover',
    function(e){
        e.preventDefault();

        if(!dragItem){
            return;
        }

        const target =
            e.target.closest(
                '.drag'
            );

        if(
            target &&
            target !== dragItem &&
            target.parentNode ===
                dragItem.parentNode
        ){
            const rect =
                target.getBoundingClientRect();

            const after =
                e.clientY >
                rect.top +
                rect.height / 2;

            target.parentNode.insertBefore(
                dragItem,
                after
                    ? target.nextSibling
                    : target
            );
        }
    }
);

document.addEventListener(
    'dragend',
    function(){
        dragItem = null;
    }
);

document.addEventListener(
    'keydown',
    function(e){
        if(
            e.key === 'Enter' &&
            e.target.matches(
                'input[name="q"]'
            )
        ){
            e.target.form.submit();
        }
    }
);
</script>
<?php
    admin_footer();
}

function render_preview(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_error(
            'アンケートが見つかりません。'
        );
        return;
    }

    $flash =
        flash_get();

    admin_header(
        'アンケートプレビュー',
        $flash
    );
?>
<div class="toolbar">
<h1>プレビュー</h1>
<div class="actions">
<a class="btn"
   href="<?= h(app_url([
       'screen'=>'edit',
       'id'=>$id
   ])) ?>">
編集へ戻る
</a>
</div>
</div>

<div class="card">
<h2><?= h($survey['title']) ?></h2>

<?php if (
    $survey['description'] !== ''
): ?>
<p><?= nl2br(
    h($survey['description'])
) ?></p>
<?php endif; ?>

<?php foreach (
    all_questions($survey) as $question
): ?>
<div class="question">
<h3>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
<?php if (
    !empty($question['required'])
): ?>
<span>（必須）</span>
<?php endif; ?>
</h3>

<?php if (
    $question['type'] === 'text'
): ?>
<textarea disabled></textarea>
<?php else: ?>
<?php foreach (
    $question['options'] as $option
): ?>
<label class="answer-choice">
<input
 <?= $question['type'] === 'single'
     ? 'type="radio"'
     : 'type="checkbox"'
 ?> disabled>
<?= h($option['label']) ?>

<?php if (
    $question['type'] === 'single' &&
    !empty($option['nextQuestionId'])
): ?>
<span class="small">
→ 次：
<?php
$target =
    survey_question_by_id(
        $survey,
        (string)$option['nextQuestionId']
    );
?>
<?= $target
    ? h($target['number'])
    : '指定なし' ?>
</span>
<?php endif; ?>
</label>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php
    admin_footer();
}

function survey_question_by_id(
    array $survey,
    string $id
): ?array {
    foreach (
        all_questions($survey) as $q
    ) {
        if (
            (string)$q['id'] === $id
        ) {
            return $q;
        }
    }

    return null;
}

function render_customers(
    array $data
): void {
    $flash =
        flash_get();

    admin_header(
        '同期済み顧客一覧',
        $flash
    );
?>
<div class="toolbar">
<h1>同期済み顧客一覧</h1>
<div class="actions">
<a class="btn"
   href="<?= h(app_url([
       'screen'=>'kintone'
   ])) ?>">
kintone設定
</a>
</div>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
<th>組織</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
<th>電話</th>
<th>住所</th>
<th>同期日時</th>
</tr>
</thead>
<tbody>
<?php foreach (
    $data['customers'] as $customer
): ?>
<tr>
<td><?= h(
    $customer['organization'] ?? ''
) ?></td>
<td><?= h(
    $customer['name'] ?? ''
) ?></td>
<td><?= h(
    $customer['email'] ?? ''
) ?></td>
<td><?= h(
    $customer['department'] ?? ''
) ?></td>
<td><?= h(
    $customer['phone'] ?? ''
) ?></td>
<td><?= h(
    $customer['address'] ?? ''
) ?></td>
<td><?= h(
    $customer['syncedAt'] ?? ''
) ?></td>
</tr>
<?php endforeach; ?>

<?php if (
    $data['customers'] === []
): ?>
<tr>
<td colspan="7">
同期済み顧客がありません。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<?php
    admin_footer();
}

function render_kintone(
    array $settings
): void {
    $flash =
        flash_get();

    $config =
        $settings['kintone'];

    $display =
        $config;

    $display['password'] = '';

    admin_header(
        'kintone連携設定',
        $flash
    );
?>
<div class="card">
<h1>kintone連携設定</h1>

<form method="post">
<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid">
<div class="field">
<label>サブドメイン</label>
<input type="text"
       name="subdomain"
       value="<?= h(
           $display['subdomain']
       ) ?>"
       placeholder="example">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input type="number"
       name="app_id"
       min="1"
       value="<?= h(
           $display['app_id']
       ) ?>">
</div>

<div class="field">
<label>ログイン名</label>
<input type="text"
       name="username"
       value="<?= h(
           $display['username']
       ) ?>">
</div>

<div class="field">
<label>パスワード</label>
<input type="password"
       name="password"
       value=""
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>
</div>

<div class="field">
<label>Proxy</label>
<input type="text"
       name="proxy"
       value="<?= h(
           $display['proxy']
       ) ?>"
       placeholder="host:port">
</div>

<label>
<input type="checkbox"
       name="verify_ssl"
       value="1"
 <?= !empty($display['verify_ssl'])
     ? 'checked'
     : '' ?>>
SSL証明書を検証する
</label>

<br><br>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>接続テスト</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="test_kintone">
<button class="primary">
接続テスト
</button>
</form>

<?php if (
    !empty($config['last_test'])
): ?>
<p class="small">
最終接続テスト：
<?= h($config['last_test']) ?>
</p>
<?php endif; ?>
</div>

<div class="card">
<h2>顧客項目一覧</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="load_kintone_fields">

<button class="primary">
項目一覧を再取得
</button>
</form>

<?php if (
    !empty($config['fields'])
): ?>
<p>
<?= count($config['fields']) ?>
項目取得済み
</p>
<?php endif; ?>
</div>

<div class="card">
<h2>顧客項目マッピング</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<?php
$mapping =
    $config['mapping'] ?? [];

$fields =
    $config['fields'] ?? [];

$map = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<?php foreach (
    $map as $key => $label
): ?>
<div class="field">
<label><?= h($label) ?></label>
<select name="mapping_<?= h($key) ?>">
<option value="">未設定</option>
<?php foreach (
    $fields as $field
): ?>
<option value="<?= h($field['code']) ?>"
 <?= ($mapping[$key] ?? '') ===
     $field['code']
     ? 'selected'
     : '' ?>>
<?= h(
    $field['code'] .
    ' / ' .
    $field['label']
) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<?php endforeach; ?>

<div class="field">
<label>住所（複数項目可）</label>
<?php foreach (
    $fields as $field
): ?>
<label>
<input type="checkbox"
       name="mapping_address[]"
       value="<?= h($field['code']) ?>"
 <?= in_array(
     $field['code'],
     $mapping['address'] ?? [],
     true
 )
     ? 'checked'
     : '' ?>>
<?= h(
    $field['code'] .
    ' / ' .
    $field['label']
) ?>
</label>
<?php endforeach; ?>
</div>

<button class="primary">
マッピング保存
</button>
</form>
</div>

<div class="card">
<h2>顧客情報同期</h2>

<p>
kintoneの顧客管理アプリから実データを取得します。
</p>

<form method="post"
      onsubmit="return confirm('顧客情報を同期しますか？')">
<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="primary">
顧客情報を同期
</button>
</form>

<?php if (
    !empty($config['last_sync'])
): ?>
<p class="small">
最終同期：
<?= h($config['last_sync']) ?>
</p>
<?php endif; ?>
</div>
<?php
    admin_footer();
}

function render_mail(
    array $settings
): void {
    $flash =
        flash_get();

    $config =
        $settings['mail'];

    admin_header(
        'メールサーバ設定',
        $flash
    );
?>
<div class="card">
<h1>メールサーバ設定</h1>

<form method="post">
<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid">
<div class="field">
<label>SMTPサーバ</label>
<input type="text"
       name="host"
       value="<?= h(
           $config['host']
       ) ?>">
</div>

<div class="field">
<label>SMTPポート</label>
<input type="number"
       name="port"
       min="1"
       max="65535"
       value="<?= h(
           $config['port']
       ) ?>">
</div>

<div class="field">
<label>暗号化方式</label>
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
</div>

<div class="field">
<label>
<input type="checkbox"
       name="auth"
       value="1"
 <?= !empty($config['auth'])
     ? 'checked'
     : '' ?>>
SMTP認証
</label>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input type="text"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>">
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">
</div>

<div class="field">
<label>送信元メールアドレス</label>
<input type="email"
       name="from_email"
       value="<?= h(
           $config['from_email']
       ) ?>">
</div>

<div class="field">
<label>送信元名</label>
<input type="text"
       name="from_name"
       value="<?= h(
           $config['from_name']
       ) ?>">
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input type="email"
       name="reply_to"
       value="<?= h(
           $config['reply_to']
       ) ?>">
</div>
</div>

<button class="primary">
設定保存
</button>
</form>
</div>

<div class="card">
<h2>SMTP接続テスト</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="test_mail">

<button class="primary">
接続テスト
</button>
</form>

<?php if (
    !empty($config['last_test'])
): ?>
<p class="small">
最終テスト：
<?= h($config['last_test']) ?>
</p>
<?php endif; ?>
</div>

<div class="card">
<h2>テストメール</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="field">
<label>宛先</label>
<input type="email"
       name="test_email"
       required>
</div>

<button class="primary">
テストメール送信
</button>
</form>
</div>
<?php
    admin_footer();
}

function render_send(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_error(
            '対象アンケートが見つかりません。'
        );
        return;
    }

    $flash =
        flash_get();

    admin_header(
        '顧客選択・メール送信',
        $flash
    );
?>
<div class="toolbar">
<h1><?= h($survey['title']) ?></h1>
<a class="btn"
   href="<?= h(app_url([
       'screen'=>'list'
   ])) ?>">
一覧へ戻る
</a>
</div>

<div class="card">
<h2>メール作成</h2>

<form method="post">
<input type="hidden"
       name="action"
       value="send_mail">
<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<div class="field">
<label>件名</label>
<input type="text"
       name="subject"
       value="<?= h($survey['title']) ?>">
</div>

<div class="field">
<label>本文</label>
<textarea name="body">アンケートへのご協力をお願いいたします。

{顧客名} 様

以下のURLからアンケートへご回答ください。

{アンケートURL}</textarea>
</div>

<h3>顧客選択</h3>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>選択</th>
<th>組織</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
</tr>
</thead>
<tbody>
<?php foreach (
    $data['customers'] as $customer
): ?>
<tr>
<td>
<input type="checkbox"
       name="customers[]"
       value="<?= h($customer['id']) ?>">
</td>
<td><?= h(
    $customer['organization'] ?? ''
) ?></td>
<td><?= h(
    $customer['name'] ?? ''
) ?></td>
<td><?= h(
    $customer['email'] ?? ''
) ?></td>
<td><?= h(
    $customer['department'] ?? ''
) ?></td>
</tr>
<?php endforeach; ?>

<?php if (
    $data['customers'] === []
): ?>
<tr>
<td colspan="5">
同期済み顧客がありません。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>

<br>

<button class="primary"
        onclick="return confirm('選択した顧客へ一括送信しますか？')">
一括送信
</button>
</form>
</div>

<div class="card">
<h2>送信履歴</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>メール</th>
<th>結果</th>
</tr>
</thead>
<tbody>
<?php
$history = array_reverse(
    array_filter(
        $data['send_history'],
        static function (
            array $row
        ) use ($id): bool {
            return
                (string)(
                    $row['surveyId'] ?? ''
                ) === $id;
        }
    )
);
?>
<?php foreach (
    $history as $row
): ?>
<tr>
<td><?= h(
    $row['sentAt'] ?? ''
) ?></td>
<td><?= h(
    $row['customerName'] ?? ''
) ?></td>
<td><?= h(
    $row['email'] ?? ''
) ?></td>
<td><?= h(
    $row['status'] ?? ''
) ?></td>
</tr>
<?php endforeach; ?>

<?php if ($history === []): ?>
<tr>
<td colspan="4">
送信履歴はありません。
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<?php
    admin_footer();
}

function render_analytics(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_error(
            '対象アンケートが見つかりません。'
        );
        return;
    }

    $answers =
        array_values(
            array_filter(
                $data['answers'],
                static function (
                    array $row
                ) use ($id): bool {
                    return
                        (string)(
                            $row['surveyId'] ?? ''
                        ) === $id;
                }
            )
        );

    $sent =
        array_values(
            array_filter(
                $data['send_history'],
                static function (
                    array $row
                ) use ($id): bool {
                    return
                        (string)(
                            $row['surveyId'] ?? ''
                        ) === $id;
                }
            )
        );

    $sentCount =
        count(
            array_filter(
                $sent,
                static fn(array $r): bool =>
                    ($r['status'] ?? '') === 'sent'
            )
        );

    $answerCount =
        count($answers);

    $rate =
        $sentCount > 0
            ? round(
                $answerCount /
                $sentCount *
                100,
                1
            )
            : 0;

    $flash =
        flash_get();

    admin_header(
        '回答集計・分析',
        $flash
    );
?>
<div class="toolbar">
<h1>回答集計・分析</h1>
<a class="btn"
   href="<?= h(app_url([
       'screen'=>'list'
   ])) ?>">
一覧へ戻る
</a>
</div>

<div class="card">
<h2><?= h($survey['title']) ?></h2>

<div class="grid">
<div>
<strong>送信対象者数</strong>
<p><?= h($sentCount) ?></p>
</div>
<div>
<strong>回答数</strong>
<p><?= h($answerCount) ?></p>
</div>
<div>
<strong>未回答数</strong>
<p><?= h(max(0, $sentCount - $answerCount)) ?></p>
</div>
<div>
<strong>回答率</strong>
<p><?= h($rate) ?>%</p>
</div>
</div>
</div>

<div class="card">
<h2>設問別集計</h2>

<?php if (
    $answerCount === 0
): ?>
<p>現在、回答データはありません</p>
<?php else: ?>

<?php foreach (
    all_questions($survey) as $question
): ?>
<div class="question">
<h3>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</h3>

<?php
$counts = [];

foreach (
    $question['options'] ?? [] as $option
) {
    $counts[
        (string)$option['id']
    ] = 0;
}

$textAnswers = 0;

foreach (
    $answers as $answer
) {
    $value =
        $answer['answers'][
            $question['id']
        ] ?? '';

    if (is_array($value)) {
        foreach ($value as $v) {
            if (isset($counts[$v])) {
                $counts[$v]++;
            }
        }
    } elseif (
        $value !== ''
    ) {
        if (isset($counts[$value])) {
            $counts[$value]++;
        } else {
            $textAnswers++;
        }
    }
}
?>

<?php foreach (
    $question['options'] ?? [] as $option
): ?>
<p>
<?= h($option['label']) ?>：
<?= h(
    $counts[
        $option['id']
    ] ?? 0
) ?>件
</p>
<?php endforeach; ?>

<?php if (
    $question['type'] === 'text'
): ?>
<p>
自由記述回答：
<?= h($textAnswers) ?>件
</p>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>
</div>

<div class="card">
<h2>個別回答</h2>

<?php foreach (
    $answers as $answer
): ?>
<div class="question">
<?php foreach (
    all_questions($survey) as $question
): ?>
<p>
<strong>
<?= h($question['number']) ?>
</strong>
<br>
<?php
$value =
    $answer['answers'][
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $labels = [];

    foreach (
        $question['options'] ?? [] as $option
    ) {
        if (
            in_array(
                $option['id'],
                $value,
                true
            )
        ) {
            $labels[] =
                $option['label'];
        }
    }

    echo h(
        implode(
            '、',
            $labels
        )
    );
} else {
    echo nl2br(
        h($value)
    );
}
?>
</p>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>
<?php
    admin_footer();
}

function render_answer(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_answer_message(
            'アンケートが見つかりません。'
        );
        return;
    }

    if (
        ($survey['status'] ?? '') !== 'published'
    ) {
        render_answer_message(
            'このアンケートは現在回答できません。'
        );
        return;
    }

    $answers =
        $_SESSION[
            'answer_' . $id
        ] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $flash =
        flash_get();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= h($survey['title']) ?></title>
<style>
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 Meiryo,sans-serif;
}
.wrap{
 max-width:760px;
 margin:auto;
 padding:20px 14px 50px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:20px;
 margin-bottom:16px;
}
.question{
 margin-bottom:18px;
}
textarea,
input[type=text]{
 width:100%;
 box-sizing:border-box;
 padding:13px;
 border:1px solid #cbd5e1;
 border-radius:8px;
 font-size:16px;
}
textarea{min-height:140px}
.choice{
 display:block;
 padding:14px;
 margin:8px 0;
 border:1px solid #dbe2ea;
 border-radius:8px;
}
button{
 width:100%;
 padding:14px;
 border:0;
 border-radius:8px;
 background:#2563eb;
 color:#fff;
 font-size:16px;
}
.flash{
 padding:14px;
 background:#fee2e2;
 color:#991b1b;
 border-radius:10px;
 margin-bottom:16px;
 white-space:pre-line;
}
</style>
</head>
<body>
<div class="wrap">
<?php if ($flash): ?>
<div class="flash">
<?= nl2br(h($flash['message'] ?? '')) ?>
</div>
<?php endif; ?>

<div class="card">
<h1><?= h($survey['title']) ?></h1>

<?php if (
    $survey['description'] !== ''
): ?>
<p><?= nl2br(
    h($survey['description'])
) ?></p>
<?php endif; ?>
</div>

<form method="post">
<input type="hidden"
       name="action"
       value="answer_next">
<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">

<?php foreach (
    visible_questions(
        $survey,
        $answers
    ) as $question
): ?>
<div class="card question">
<h2>
<?= h($question['number']) ?>
</h2>

<p>
<?= nl2br(
    h($question['text'])
) ?>

<?php if (
    !empty($question['required'])
): ?>
<strong>（必須）</strong>
<?php endif; ?>
</p>

<?php if (
    $question['type'] === 'text'
): ?>

<textarea
 name="answers[<?= h($question['id']) ?>]"><?= h(
     $answers[
         $question['id']
     ] ?? ''
 ) ?></textarea>

<?php elseif (
    $question['type'] === 'single'
): ?>

<?php foreach (
    $question['options'] as $option
): ?>
<label class="choice">
<input type="radio"
       name="answers[<?= h($question['id']) ?>]"
       value="<?= h($option['id']) ?>"
 <?= (
     (string)(
         $answers[
             $question['id']
         ] ?? ''
     ) ===
     (string)$option['id']
 )
     ? 'checked'
     : '' ?>>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php else: ?>

<?php
$current =
    is_array(
        $answers[
            $question['id']
        ] ?? null
    )
        ? $answers[
            $question['id']
        ]
        : [];
?>

<?php foreach (
    $question['options'] as $option
): ?>
<label class="choice">
<input type="checkbox"
       name="answers[<?= h($question['id']) ?>][]"
       value="<?= h($option['id']) ?>"
 <?= in_array(
     $option['id'],
     $current,
     true
 )
     ? 'checked'
     : '' ?>>
<?= h($option['label']) ?>
</label>
<?php endforeach; ?>

<?php endif; ?>
</div>
<?php endforeach; ?>

<button>
回答内容を確認する
</button>
</form>
</div>
</body>
</html>
<?php
}

function render_answer_confirm(
    array $data,
    string $id
): void {
    $survey =
        survey_get(
            $data['surveys'],
            $id
        );

    if (!$survey) {
        render_answer_message(
            'アンケートが見つかりません。'
        );
        return;
    }

    $answers =
        $_SESSION[
            'answer_' . $id
        ] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>回答確認</title>
<style>
body{
 margin:0;
 background:#f8fafc;
 color:#1e293b;
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 Meiryo,sans-serif;
}
.wrap{
 max-width:760px;
 margin:auto;
 padding:20px 14px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:20px;
 margin-bottom:16px;
}
.actions{
 display:flex;
 gap:10px;
}
button{
 flex:1;
 padding:13px;
 border-radius:8px;
 border:1px solid #cbd5e1;
 background:#fff;
 font-size:16px;
}
.primary{
 background:#2563eb;
 color:#fff;
 border-color:#2563eb;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1>回答確認</h1>
<p>内容を確認して送信してください。</p>

<?php foreach (
    visible_questions(
        $survey,
        $answers
    ) as $question
): ?>
<div>
<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>
<br>

<?php
$value =
    $answers[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $labels = [];

    foreach (
        $question['options'] ?? [] as $option
    ) {
        if (
            in_array(
                $option['id'],
                $value,
                true
            )
        ) {
            $labels[] =
                $option['label'];
        }
    }

    echo nl2br(
        h(
            implode(
                '、',
                $labels
            )
        )
    );
} elseif (
    $question['type'] === 'single'
) {
    $label = '';

    foreach (
        $question['options'] ?? [] as $option
    ) {
        if (
            (string)$option['id'] ===
            (string)$value
        ) {
            $label =
                (string)$option['label'];
            break;
        }
    }

    echo h($label);
} else {
    echo nl2br(
        h($value)
    );
}
?>
</div>
<hr>
<?php endforeach; ?>

<div class="actions">
<form method="post" style="flex:1">
<input type="hidden"
       name="action"
       value="answer_back">
<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">
<button>
修正する
</button>
</form>

<form method="post" style="flex:1"
      onsubmit="return confirm('回答を送信しますか？')">
<input type="hidden"
       name="action"
       value="answer_submit">
<input type="hidden"
       name="survey_id"
       value="<?= h($id) ?>">
<button class="primary">
回答を送信
</button>
</form>
</div>
</div>
</div>
</body>
</html>
<?php
}

function render_complete(): void
{
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>回答完了</title>
<style>
body{
 margin:0;
 background:#f8fafc;
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 Meiryo,sans-serif;
 color:#1e293b;
}
.wrap{
 max-width:650px;
 margin:auto;
 padding:40px 16px;
}
.card{
 background:#fff;
 border:1px solid #dbe2ea;
 border-radius:14px;
 padding:30px;
 text-align:center;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1>回答完了</h1>
<p>
アンケートへのご回答ありがとうございました。
</p>
<p>
この画面を閉じてください。
</p>
</div>
</div>
</body>
</html>
<?php
}

function render_answer_message(
    string $message
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケート</title>
<style>
body{
 margin:0;
 background:#f8fafc;
 font-family:
 -apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",
 Meiryo,sans-serif;
}
.wrap{
 max-width:650px;
 margin:auto;
 padding:40px 16px;
}
.card{
 background:#fff;
 padding:30px;
 border-radius:14px;
 border:1px solid #dbe2ea;
}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1>アンケート</h1>
<p><?= nl2br(h($message)) ?></p>
</div>
</div>
</body>
</html>
<?php
}

function render_error(
    string $message
): void {
    admin_header(
        'エラー',
        [
            'type' => 'error',
            'message' => $message,
        ]
    );

    echo '<div class="card">';
    echo '<h1>処理エラー</h1>';
    echo '<p>' . nl2br(h($message)) . '</p>';
    echo '<a class="btn" href="' .
        h(app_url(['screen'=>'list'])) .
        '">アンケート一覧へ</a>';
    echo '</div>';

    admin_footer();
}

/* =========================================================
 * アプリケーション起動
 * ========================================================= */

try {
    start_app();

    $data =
        load_data();

    $settings =
        load_settings();

    refresh_status($data);

    /*
     * POST処理と画面表示を分離する。
     * 外部サービス関数から画面遷移は行わない。
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result =
            handle_post(
                $data,
                $settings
            );

        $screen =
            (string)(
                $result['screen'] ?? 'list'
            );

        $id =
            isset($result['id'])
                ? (string)$result['id']
                : null;
    } else {
        $screen =
            get_string('screen');

        if ($screen === '') {
            $screen = 'list';
        }

        $id =
            get_string('id');

        if ($id === '') {
            $id = null;
        }
    }

    /*
     * 対象アンケートが必要な画面では、
     * IDが存在しない場合は表示しない。
     */
    if (
        in_array(
            $screen,
            [
                'preview',
                'send',
                'analytics',
                'answer',
                'confirm',
                'complete',
            ],
            true
        ) &&
        (
            $id === null ||
            survey_get(
                $data['surveys'],
                $id
            ) === null
        )
    ) {
        if (
            in_array(
                $screen,
                [
                    'answer',
                    'confirm',
                    'complete',
                ],
                true
            )
        ) {
            render_answer_message(
                '対象アンケートが見つかりません。'
            );
        } else {
            render_error(
                '対象アンケートが見つかりません。'
            );
        }

        exit;
    }

    switch ($screen) {
        case 'list':
            render_list($data);
            break;

        case 'edit':
            render_edit(
                $data,
                $id
            );
            break;

        case 'preview':
            render_preview(
                $data,
                (string)$id
            );
            break;

        case 'customers':
            render_customers(
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

        case 'send':
            render_send(
                $data,
                (string)$id
            );
            break;

        case 'analytics':
            render_analytics(
                $data,
                (string)$id
            );
            break;

        case 'answer':
            render_answer(
                $data,
                (string)$id
            );
            break;

        case 'confirm':
            render_answer_confirm(
                $data,
                (string)$id
            );
            break;

        case 'complete':
            render_complete();
            break;

        default:
            render_list($data);
            break;
    }
} catch (Throwable $e) {
    /*
     * 白画面を避ける。
     * 内部スタックトレースや認証情報は表示しない。
     */
    if (
        session_status() === PHP_SESSION_ACTIVE
    ) {
        flash(
            'error',
            'システムエラー：' .
            safe_external_error($e)
        );
    }

    http_response_code(500);

    echo '<!doctype html>';
    echo '<html lang="ja"><head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>システムエラー</title>';
    echo '<style>';
    echo 'body{font-family:sans-serif;background:#f8fafc;padding:30px}';
    echo '.card{max-width:700px;margin:auto;background:#fff;padding:25px;border-radius:12px;border:1px solid #dbe2ea}';
    echo '</style>';
    echo '</head><body>';
    echo '<div class="card">';
    echo '<h1>処理エラー</h1>';
    echo '<p>';
    echo h(
        '処理に失敗しました。設定値・サーバー環境を確認してください。'
    );
    echo '</p>';
    echo '</div>';
    echo '</body></html>';
}

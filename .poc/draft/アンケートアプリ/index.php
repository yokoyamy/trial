<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし
 * PHP cURLなし
 * PHP mail()なし
 * 単一 index.php
 *
 * prompt.txt の仕様に合わせ、
 * - 管理者認証なし
 * - CSRFなし
 * - screenパラメータ方式
 * - Windowsでも壊れにくいファイル保存
 * - 外部302/303を成功扱いしない
 * - 外部通信処理からリダイレクトしない
 * - kintone認証はX-Cybozu-Authorization
 * - 外部サービスパスワードをHTML/URL/Cookie/ログへ出さない
 * - SMTPは標準socketのみ
 * - POST後303は結果確定後のみ
 * を満たす。
 */

const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'app.dat.php';
const LOCK_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'app.lock';
const TIMEZONE  = 'Asia/Tokyo';

date_default_timezone_set(TIMEZONE);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_set_cookie_params([
    'lifetime' => 0,
    /*
     * SCRIPT_NAMEに日本語パスが含まれる環境では、
     * 生のREQUEST URI由来のCookie Pathが文字化けする場合がある。
     * POCでは同一アプリのセッション維持を優先して "/" とする。
     */
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS'])
        && strtolower((string)$_SERVER['HTTPS']) !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

/* =========================================================
 * 基本
 * ========================================================= */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function jsonEncode(mixed $value): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('データをJSON化できません。');
    }

    return $json;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function getString(string $name, string $default = ''): string
{
    if (!isset($_GET[$name]) || !is_scalar($_GET[$name])) {
        return $default;
    }

    return trim((string)$_GET[$name]);
}

function postString(string $name, string $default = ''): string
{
    if (!isset($_POST[$name]) || !is_scalar($_POST[$name])) {
        return $default;
    }

    return trim((string)$_POST[$name]);
}

function postArray(string $name): array
{
    return isset($_POST[$name]) && is_array($_POST[$name])
        ? $_POST[$name]
        : [];
}

function validateId(string $id): bool
{
    return preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) === 1;
}

function validEmail(string $email): bool
{
    return filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    ) !== false;
}

function validDateTime(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $dt = DateTimeImmutable::createFromFormat(
        'Y-m-d\TH:i',
        $value
    );

    return $dt !== false
        && $dt->format('Y-m-d\TH:i') === $value;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function takeFlash(): ?array
{
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($value) ? $value : null;
}

function redirectTo(
    string $screen,
    array $params = []
): never {
    $allowed = [
        'list',
        'edit',
        'preview',
        'send',
        'analytics',
        'kintone',
        'mail',
        'answer',
        'confirm',
        'complete',
    ];

    if (!in_array($screen, $allowed, true)) {
        $screen = 'list';
    }

    $query = array_merge(
        ['screen' => $screen],
        $params
    );

    $url = (string)(
        $_SERVER['SCRIPT_NAME'] ?? 'index.php'
    );

    $url .= '?'
        . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

    header('Location: ' . $url, true, 303);
    exit;
}

/* =========================================================
 * 永続データ
 * ========================================================= */

function defaultData(): array
{
    return [
        'surveys' => [],
        'answers' => [],
        'customers' => [],
        'sendHistory' => [],
        'kintone' => [
            'subdomain' => '',
            'appId' => '',
            'username' => '',
            'proxy' => '',
            'sslVerify' => true,
            'connection' => '未設定',
            'connectionDetail' => '',
            'fields' => [],
            'mappings' => [
                'org' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
        ],
        'mailSettings' => [
            'server' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'fromEmail' => '',
            'fromName' => '',
            'replyTo' => '',
            'connection' => '未設定',
            'connectionDetail' => '',
        ],
    ];
}

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0770, true)
            && !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'データ保存ディレクトリを作成できません。'
            );
        }
    }

    if (!is_writable(DATA_DIR)) {
        throw new RuntimeException(
            'データ保存ディレクトリに書き込み権限がありません。'
        );
    }

    $htaccess = DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';

    if (!is_file($htaccess)) {
        $content =
            "Options -Indexes\n"
            . "<FilesMatch \"\\.(dat|json|tmp|lock)(\\.php)?$\">\n"
            . "Require all denied\n"
            . "</FilesMatch>\n";

        @file_put_contents(
            $htaccess,
            $content,
            LOCK_EX
        );
    }
}

function withDataLock(
    int $mode,
    callable $callback
): mixed {
    ensureDataDir();

    $fp = @fopen(LOCK_FILE, 'c+b');

    if ($fp === false) {
        throw new RuntimeException(
            'データロックファイルを開けません。'
        );
    }

    try {
        if (!flock($fp, $mode)) {
            throw new RuntimeException(
                'データファイルをロックできません。'
            );
        }

        try {
            return $callback();
        } finally {
            flock($fp, LOCK_UN);
        }
    } finally {
        fclose($fp);
    }
}

function readData(): array
{
    return withDataLock(
        LOCK_SH,
        static function (): array {
            if (!is_file(DATA_FILE)) {
                return defaultData();
            }

            $contents = @file_get_contents(DATA_FILE);

            if ($contents === false
                || trim($contents) === '') {
                return defaultData();
            }

            /*
             * app.dat.php がPHPとして実行されないよう、
             * 保存ファイルはJSONそのものではなく、
             * PHPアクセス時にもデータを出力しない形式にする。
             */
            if (str_starts_with(
                ltrim($contents),
                '<?php'
            )) {
                throw new RuntimeException(
                    '保存データ形式が不正です。'
                );
            }

            $data = json_decode(
                $contents,
                true
            );

            if (!is_array($data)) {
                throw new RuntimeException(
                    '保存データが破損しています。'
                );
            }

            $data = array_replace_recursive(
                defaultData(),
                $data
            );

            /*
             * 外部サービス認証情報は永続データへ入れない。
             */
            unset(
                $data['kintone']['password'],
                $data['mailSettings']['password']
            );

            return $data;
        }
    );
}

function saveData(array $data): void
{
    ensureDataDir();

    /*
     * 外部サービスパスワードを永続データへ入れない。
     */
    unset(
        $data['kintone']['password'],
        $data['mailSettings']['password']
    );

    $json = jsonEncode($data);

    withDataLock(
        LOCK_EX,
        static function () use ($json): void {
            $tmp = DATA_FILE
                . '.'
                . bin2hex(random_bytes(8))
                . '.tmp';

            /*
             * Windowsでは既存ファイルへのrename()置換に依存しない。
             * 同じロックを読込側にも使い、
             * copy()中の部分データを読ませない。
             */
            $written = @file_put_contents(
                $tmp,
                $json,
                LOCK_EX
            );

            if ($written === false
                || $written !== strlen($json)) {
                @unlink($tmp);

                throw new RuntimeException(
                    'データ保存用一時ファイルへ完全に書き込めません。'
                );
            }

            clearstatcache(true, $tmp);

            if (!is_file($tmp)
                || (int)filesize($tmp) !== strlen($json)) {
                @unlink($tmp);

                throw new RuntimeException(
                    'データ保存用一時ファイルを確認できません。'
                );
            }

            /*
             * Windows/Apacheで確実に既存ファイルを更新する。
             * 読み取り側も同じLOCK_FILEを共有するため、
             * copy中の内容を参照されない。
             */
            if (!@copy($tmp, DATA_FILE)) {
                @unlink($tmp);

                throw new RuntimeException(
                    'データファイルを更新できません。'
                    . 'dataディレクトリの権限を確認してください。'
                );
            }

            clearstatcache(true, DATA_FILE);

            $saved = @file_get_contents(DATA_FILE);

            if ($saved === false
                || !hash_equals(
                    hash('sha256', $json),
                    hash('sha256', $saved)
                )) {
                @unlink($tmp);

                throw new RuntimeException(
                    'データ保存結果を確認できません。'
                );
            }

            @unlink($tmp);
        }
    );
}

/* =========================================================
 * アンケートモデル
 * ========================================================= */

function normalizeQuestion(array $q): array
{
    $type = (string)($q['type'] ?? 'single');

    if (!in_array(
        $type,
        ['single', 'multiple', 'free'],
        true
    )) {
        $type = 'single';
    }

    $options = [];

    foreach (($q['options'] ?? []) as $option) {
        if (!is_scalar($option)) {
            continue;
        }

        $option = trim((string)$option);

        if ($option !== '') {
            $options[] = $option;
        }
    }

    if ($type === 'free') {
        $options = [];
    }

    $branches = [];

    if (is_array($q['branches'] ?? null)) {
        foreach ($q['branches'] as $option => $target) {
            if (!is_scalar($target)) {
                continue;
            }

            $target = trim((string)$target);

            if ($target !== ''
                && validateId($target)) {
                $branches[(string)$option] = $target;
            }
        }
    }

    return [
        'id' => validateId(
            (string)($q['id'] ?? '')
        )
            ? (string)$q['id']
            : uid('q'),
        'number' => '',
        'text' => trim(
            (string)($q['text'] ?? '')
        ),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => array_values($options),
        'branches' => $branches,
    ];
}

function normalizeSurvey(array $survey): array
{
    $groups = [];

    foreach (($survey['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }

        $questions = [];

        foreach (($group['questions'] ?? []) as $question) {
            if (is_array($question)) {
                $questions[] =
                    normalizeQuestion($question);
            }
        }

        $groups[] = [
            'id' => validateId(
                (string)($group['id'] ?? '')
            )
                ? (string)$group['id']
                : uid('g'),
            'title' => trim(
                (string)($group['title'] ?? '')
            ),
            'questions' => $questions,
        ];
    }

    $status = (string)(
        $survey['status'] ?? 'draft'
    );

    if (!in_array(
        $status,
        ['draft', 'published', 'stopped', 'ended'],
        true
    )) {
        $status = 'draft';
    }

    $result = [
        'id' => validateId(
            (string)($survey['id'] ?? '')
        )
            ? (string)$survey['id']
            : uid('survey'),

        'createdAt' => (string)(
            $survey['createdAt'] ?? today()
        ),

        'updatedAt' => (string)(
            $survey['updatedAt'] ?? today()
        ),

        'title' => trim(
            (string)($survey['title'] ?? '')
        ),

        'description' => trim(
            (string)($survey['description'] ?? '')
        ),

        'startAt' => (string)(
            $survey['startAt'] ?? ''
        ),

        'endAt' => (string)(
            $survey['endAt'] ?? ''
        ),

        'status' => $status,

        'numbering' =>
            ($survey['numbering'] ?? 'global') === 'group'
                ? 'group'
                : 'global',

        'groups' => $groups,
    ];

    renumberSurvey($result);

    return $result;
}

function renumberSurvey(array &$survey): void
{
    $global = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        foreach (
            $group['questions']
            as $qi => &$question
        ) {
            if ($survey['numbering'] === 'group') {
                $question['number'] =
                    'Q'
                    . ($gi + 1)
                    . '-'
                    . ($qi + 1);
            } else {
                $question['number'] =
                    'Q' . $global;
            }

            $global++;
        }

        unset($question);
    }

    unset($group);
}

function surveyIndex(
    array $data,
    string $id
): int {
    foreach ($data['surveys'] as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function surveyById(
    array $data,
    string $id
): ?array {
    $index = surveyIndex($data, $id);

    return $index >= 0
        ? $data['surveys'][$index]
        : null;
}

function allQuestions(array $survey): array
{
    $result = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $result[] = $question;
        }
    }

    return $result;
}

function questionMap(array $survey): array
{
    $map = [];

    foreach (allQuestions($survey) as $question) {
        $map[$question['id']] = $question;
    }

    return $map;
}

function canTransition(
    string $from,
    string $to
): bool {
    return match ($from) {
        'draft' => $to === 'published',
        'published' => $to === 'stopped',
        'stopped' => $to === 'published',
        default => false,
    };
}

function updateAutomaticStatus(
    array &$data
): void {
    $changed = false;
    $current = new DateTimeImmutable();

    foreach ($data['surveys'] as &$survey) {
        if (($survey['status'] ?? '') !== 'published') {
            continue;
        }

        $endAt = (string)(
            $survey['endAt'] ?? ''
        );

        if ($endAt === '') {
            continue;
        }

        $end = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            $endAt
        );

        if ($end !== false && $current > $end) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = today();
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        saveData($data);
    }
}

function surveyAvailable(
    array $survey
): bool {
    if (($survey['status'] ?? '') !== 'published') {
        return false;
    }

    $current = new DateTimeImmutable();

    if (!empty($survey['startAt'])) {
        $start = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            (string)$survey['startAt']
        );

        if ($start !== false && $current < $start) {
            return false;
        }
    }

    if (!empty($survey['endAt'])) {
        $end = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            (string)$survey['endAt']
        );

        if ($end !== false && $current > $end) {
            return false;
        }
    }

    return true;
}

/* =========================================================
 * 条件分岐・回答検証
 * ========================================================= */

function visibleQuestionIds(
    array $survey,
    array $answers
): array {
    $questions = allQuestions($survey);
    $rules = [];

    foreach ($questions as $parent) {
        if (($parent['type'] ?? '') !== 'single') {
            continue;
        }

        foreach (($parent['branches'] ?? []) as $option => $target) {
            if (validateId((string)$target)) {
                $rules[(string)$target] = [
                    'parent' => $parent['id'],
                    'option' => (string)$option,
                ];
            }
        }
    }

    $visible = [];

    foreach ($questions as $question) {
        $id = (string)$question['id'];

        if (!isset($rules[$id])) {
            $visible[] = $id;
            continue;
        }

        $rule = $rules[$id];

        $answer = $answers[
            $rule['parent']
        ] ?? '';

        if ((string)$answer === $rule['option']) {
            $visible[] = $id;
        }
    }

    return array_values(array_unique($visible));
}

function validateAnswers(
    array $survey,
    array $answers
): array {
    $errors = [];
    $map = questionMap($survey);

    $visible = visibleQuestionIds(
        $survey,
        $answers
    );

    foreach ($visible as $id) {
        if (!isset($map[$id])) {
            continue;
        }

        $question = $map[$id];
        $value = $answers[$id] ?? '';

        if (is_array($value)) {
            $value = array_values(
                array_map(
                    static fn($v): string =>
                        trim((string)$v),
                    $value
                )
            );
        }

        $empty = is_array($value)
            ? count($value) === 0
            : trim((string)$value) === '';

        if (!empty($question['required'])
            && $empty) {
            $errors[] =
                $question['number']
                . '「'
                . $question['text']
                . '」は必須です。';

            continue;
        }

        if ($empty) {
            continue;
        }

        if ($question['type'] === 'single') {
            if (!is_string($value)
                || !in_array(
                    $value,
                    $question['options'],
                    true
                )) {
                $errors[] =
                    $question['number']
                    . 'の選択値が不正です。';
            }
        }

        if ($question['type'] === 'multiple') {
            if (!is_array($value)) {
                $errors[] =
                    $question['number']
                    . 'の回答形式が不正です。';

                continue;
            }

            foreach ($value as $item) {
                if (!in_array(
                    (string)$item,
                    $question['options'],
                    true
                )) {
                    $errors[] =
                        $question['number']
                        . 'の選択値が不正です。';

                    break;
                }
            }
        }
    }

    return $errors;
}

/* =========================================================
 * HTTP
 * ========================================================= */

function httpRequest(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null,
    int $timeout = 20,
    bool $verifyTls = true,
    ?string $proxy = null
): array {
    if (!preg_match('#^https://#i', $url)) {
        throw new InvalidArgumentException(
            'HTTPS URLのみ許可されています。'
        );
    }

    $parts = parse_url($url);

    if (!$parts || empty($parts['host'])) {
        throw new InvalidArgumentException(
            '接続先URLが不正です。'
        );
    }

    $contextOptions = [
        'http' => [
            'method' => strtoupper($method),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
            'header' => implode(
                "\r\n",
                $headers
            ),
        ],
        'ssl' => [
            'verify_peer' => $verifyTls,
            'verify_peer_name' => $verifyTls,
            'allow_self_signed' => !$verifyTls,
            'SNI_enabled' => true,
            'peer_name' => $parts['host'],
        ],
    ];

    if ($body !== null) {
        $contextOptions['http']['content'] = $body;
    }

    if ($proxy !== null && $proxy !== '') {
        if (!preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )) {
            throw new InvalidArgumentException(
                'Proxyはhost:port形式で指定してください。'
            );
        }

        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy;

        $contextOptions['http']['request_fulluri'] =
            true;
    }

    $context = stream_context_create(
        $contextOptions
    );

    $error = null;

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$error): bool {
            $error = $message;
            return true;
        }
    );

    try {
        $fp = fopen(
            $url,
            'rb',
            false,
            $context
        );
    } finally {
        restore_error_handler();
    }

    if ($fp === false) {
        return [
            'ok' => false,
            'category' => 'connection_error',
            'status' => 0,
            'body' => '',
            'headers' => [],
            'error' => '外部サービスへ接続できません。',
        ];
    }

    stream_set_timeout(
        $fp,
        $timeout
    );

    $responseBody =
        stream_get_contents($fp);

    $meta = stream_get_meta_data($fp);

    $headersOut = [];
    $status = 0;

    foreach (
        ($meta['wrapper_data'] ?? [])
        as $line
    ) {
        if (preg_match(
            '#^HTTP/\S+\s+(\d{3})#i',
            $line,
            $m
        )) {
            $status = (int)$m[1];
        } elseif (str_contains($line, ':')) {
            [$key, $value] =
                explode(':', $line, 2);

            $headersOut[
                strtolower(trim($key))
            ] = trim($value);
        }
    }

    fclose($fp);

    if ($responseBody === false) {
        return [
            'ok' => false,
            'category' => 'response_error',
            'status' => $status,
            'body' => '',
            'headers' => $headersOut,
            'error' => 'レスポンスを取得できませんでした。',
        ];
    }

    if (!empty($meta['timed_out'])) {
        return [
            'ok' => false,
            'category' => 'timeout',
            'status' => $status,
            'body' => $responseBody,
            'headers' => $headersOut,
            'error' => '外部サービスへの通信がタイムアウトしました。',
        ];
    }

    if ($status >= 300 && $status < 400) {
        return [
            'ok' => false,
            'category' => 'redirect',
            'status' => $status,
            'body' => $responseBody,
            'headers' => $headersOut,
            'error' =>
                '外部サービスからHTTP '
                . $status
                . ' リダイレクトが返されました。',
        ];
    }

    if ($status >= 200 && $status < 300) {
        return [
            'ok' => true,
            'category' => 'success',
            'status' => $status,
            'body' => $responseBody,
            'headers' => $headersOut,
            'error' => '',
        ];
    }

    return [
        'ok' => false,
        'category' => 'http_error',
        'status' => $status,
        'body' => $responseBody,
        'headers' => $headersOut,
        'error' =>
            $status > 0
                ? '外部サービスからHTTP '
                    . $status
                    . ' エラーが返されました。'
                : '外部サービスから正常なHTTP応答を取得できませんでした。',
    ];
}

/* =========================================================
 * kintone
 * ========================================================= */

function normalizeKintoneHost(
    string $input
): string {
    $input = trim($input);

    $input = preg_replace(
        '#^https?://#i',
        '',
        $input
    );

    $input = preg_replace(
        '#/.*$#',
        '',
        $input
    );

    $input = trim((string)$input);

    if ($input === ''
        || !preg_match(
            '/^[A-Za-z0-9.-]+$/',
            $input
        )) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    if (str_ends_with(
        strtolower($input),
        '.cybozu.com'
    )) {
        return $input;
    }

    return $input . '.cybozu.com';
}

function kintoneAuthorization(
    string $username,
    string $password
): string {
    if ($username === ''
        || $password === '') {
        throw new InvalidArgumentException(
            'kintoneログイン名とパスワードを入力してください。'
        );
    }

    return base64_encode(
        $username . ':' . $password
    );
}

function kintoneRequest(
    array $settings,
    string $path,
    string $method,
    string $password
): array {
    $host = normalizeKintoneHost(
        (string)($settings['subdomain'] ?? '')
    );

    $appId = (string)(
        $settings['appId'] ?? ''
    );

    if (!ctype_digit($appId)
        || (int)$appId < 1) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $authorization =
        kintoneAuthorization(
            (string)(
                $settings['username'] ?? ''
            ),
            $password
        );

    try {
        $headers = [
            'X-Cybozu-Authorization: '
                . $authorization,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: SurveyPOC/4.0',
        ];

        return httpRequest(
            'https://' . $host . $path,
            $method,
            $headers,
            null,
            20,
            !empty($settings['sslVerify']),
            !empty($settings['proxy'])
                ? (string)$settings['proxy']
                : null
        );
    } finally {
        unset($authorization);
    }
}

function kintoneTest(
    array $settings,
    string $password
): array {
    $appId = (string)$settings['appId'];

    return kintoneRequest(
        $settings,
        '/k/v1/app.json?id='
        . rawurlencode($appId),
        'GET',
        $password
    );
}

function kintoneFields(
    array $settings,
    string $password
): array {
    $appId = (string)$settings['appId'];

    return kintoneRequest(
        $settings,
        '/k/v1/app/form/fields.json?app='
        . rawurlencode($appId),
        'GET',
        $password
    );
}

function kintoneRecords(
    array $settings,
    string $password
): array {
    $appId = (string)$settings['appId'];

    return kintoneRequest(
        $settings,
        '/k/v1/records.json?app='
        . rawurlencode($appId)
        . '&totalCount=true',
        'GET',
        $password
    );
}

function kintoneErrorMessage(
    array $response
): string {
    $body = json_decode(
        (string)($response['body'] ?? ''),
        true
    );

    if (is_array($body)) {
        $code = trim(
            (string)($body['code'] ?? '')
        );

        $message = trim(
            (string)($body['message'] ?? '')
        );

        if ($code === 'GAIA_AP401') {
            return 'kintoneの認証に失敗しました。ログイン名とパスワードを確認してください。';
        }

        if ($response['status'] === 403) {
            return 'kintoneへのアクセスが拒否されました。権限を確認してください。';
        }

        if ($response['status'] === 404) {
            return 'kintoneのアプリまたは接続先が見つかりません。';
        }

        if ($message !== '') {
            return 'kintone APIエラーが発生しました。'
                . ($code !== '' ? 'エラーコード: ' . $code . '。' : '');
        }
    }

    if (($response['category'] ?? '') === 'redirect') {
        return 'kintoneからリダイレクトが返されました。接続先設定を確認してください。';
    }

    return (string)(
        $response['error']
        ?? 'kintoneとの通信に失敗しました。'
    );
}

/* =========================================================
 * kintone POST
 * ========================================================= */

function saveKintoneAction(
    array &$data
): void {
    $rawSubdomain =
        postString('subdomain');

    $subdomain = $rawSubdomain !== ''
        ? normalizeKintoneHost($rawSubdomain)
        : '';

    $appId = postString('appId');

    if ($appId !== ''
        && (!ctype_digit($appId)
            || (int)$appId < 1)) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが不正です。'
        );
    }

    $username =
        postString('username');

    $proxy =
        postString('proxy');

    if ($proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で指定してください。'
        );
    }

    $sslVerify =
        postString(
            'sslVerify',
            '1'
        ) === '1';

    $data['kintone']['subdomain'] =
        $subdomain;

    $data['kintone']['appId'] =
        $appId;

    $data['kintone']['username'] =
        $username;

    $data['kintone']['proxy'] =
        $proxy;

    $data['kintone']['sslVerify'] =
        $sslVerify;

    $data['kintone']['connection'] =
        '未設定';

    $data['kintone']['connectionDetail'] =
        '';

    saveData($data);

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirectTo('kintone');
}

function testKintoneAction(
    array &$data
): void {
    $password =
        postString('password');

    try {
        if ($password === '') {
            throw new InvalidArgumentException(
                'kintoneパスワードを入力してください。'
            );
        }

        $response = kintoneTest(
            $data['kintone'],
            $password
        );
    } finally {
        $password = '';
    }

    if (!$response['ok']) {
        $message =
            kintoneErrorMessage(
                $response
            );

        $data['kintone']['connection'] =
            '接続できません';

        $data['kintone']['connectionDetail'] =
            $message;

        saveData($data);

        throw new RuntimeException($message);
    }

    $data['kintone']['connection'] =
        '接続確認済み';

    $data['kintone']['connectionDetail'] =
        'kintoneへの接続と認証に成功しました。';

    saveData($data);

    flash(
        'success',
        'kintone接続テストに成功しました。'
    );

    redirectTo('kintone');
}

function fetchKintoneFieldsAction(
    array &$data
): void {
    $password =
        postString('password');

    try {
        if ($password === '') {
            throw new InvalidArgumentException(
                'kintoneパスワードを入力してください。'
            );
        }

        $response = kintoneFields(
            $data['kintone'],
            $password
        );
    } finally {
        $password = '';
    }

    if (!$response['ok']) {
        throw new RuntimeException(
            kintoneErrorMessage($response)
        );
    }

    $json = json_decode(
        (string)$response['body'],
        true
    );

    if (!is_array($json)
        || !is_array(
            $json['properties'] ?? null
        )) {
        throw new RuntimeException(
            'kintone項目一覧を取得できませんでした。'
        );
    }

    $fields = [];

    foreach (
        $json['properties']
        as $code => $field
    ) {
        if (!is_array($field)) {
            continue;
        }

        $fields[(string)$code] = [
            'label' => (string)(
                $field['label'] ?? ''
            ),
            'type' => (string)(
                $field['type'] ?? ''
            ),
        ];
    }

    $data['kintone']['fields'] =
        $fields;

    saveData($data);

    flash(
        'success',
        'kintone項目一覧を再取得しました。'
    );

    redirectTo('kintone');
}

function kintoneFieldValue(
    array $record,
    string $code
): string {
    if ($code === ''
        || !isset($record[$code])
        || !is_array($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)
                && isset($item['value'])) {
                $parts[] = (string)$item['value'];
            } elseif (is_scalar($item)) {
                $parts[] = (string)$item;
            }
        }

        return trim(implode(' ', $parts));
    }

    return trim((string)$value);
}

function syncKintoneAction(
    array &$data
): void {
    $password =
        postString('password');

    try {
        if ($password === '') {
            throw new InvalidArgumentException(
                'kintoneパスワードを入力してください。'
            );
        }

        $response = kintoneRecords(
            $data['kintone'],
            $password
        );
    } finally {
        $password = '';
    }

    if (!$response['ok']) {
        throw new RuntimeException(
            kintoneErrorMessage($response)
        );
    }

    $json = json_decode(
        (string)$response['body'],
        true
    );

    if (!is_array($json)
        || !is_array($json['records'] ?? null)) {
        throw new RuntimeException(
            'kintone顧客情報を取得できませんでした。'
        );
    }

    $mapping =
        $data['kintone']['mappings']
        ?? [];

    $customers = [];

    foreach ($json['records'] as $record) {
        if (!is_array($record)) {
            continue;
        }

        $id = uid('customer');

        $addressCodes =
            is_array($mapping['address'] ?? null)
                ? $mapping['address']
                : [];

        $addressParts = [];

        foreach ($addressCodes as $code) {
            if (!is_scalar($code)) {
                continue;
            }

            $value = kintoneFieldValue(
                $record,
                (string)$code
            );

            if ($value !== '') {
                $addressParts[] = $value;
            }
        }

        $customers[] = [
            'id' => $id,
            'org' => kintoneFieldValue(
                $record,
                (string)($mapping['org'] ?? '')
            ),
            'name' => kintoneFieldValue(
                $record,
                (string)($mapping['name'] ?? '')
            ),
            'email' => kintoneFieldValue(
                $record,
                (string)($mapping['email'] ?? '')
            ),
            'department' => kintoneFieldValue(
                $record,
                (string)($mapping['department'] ?? '')
            ),
            'phone' => kintoneFieldValue(
                $record,
                (string)($mapping['phone'] ?? '')
            ),
            'address' => implode(
                ' ',
                $addressParts
            ),
            'updatedAt' => now(),
        ];
    }

    $data['customers'] =
        $customers;

    saveData($data);

    flash(
        'success',
        '顧客情報を同期しました。'
        . ' 件数: '
        . count($customers)
        . '件'
    );

    redirectTo('kintone');
}

/* =========================================================
 * Survey POST
 * ========================================================= */

function saveSurveyAction(
    array &$data
): void {
    $id = postString('id');

    if ($id !== '' && !validateId($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $title = postString('title');

    if ($title === '') {
        throw new InvalidArgumentException(
            'タイトルを入力してください。'
        );
    }

    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException(
            'タイトルは200文字以内で入力してください。'
        );
    }

    $startAt =
        postString('startAt');

    $endAt =
        postString('endAt');

    if (!validDateTime($startAt)
        || !validDateTime($endAt)) {
        throw new InvalidArgumentException(
            '日時の形式が不正です。'
        );
    }

    if ($startAt !== ''
        && $endAt !== ''
        && $startAt >= $endAt) {
        throw new InvalidArgumentException(
            '終了日時は開始日時より後にしてください。'
        );
    }

    $numbering =
        postString(
            'numbering',
            'global'
        );

    if (!in_array(
        $numbering,
        ['global', 'group'],
        true
    )) {
        $numbering = 'global';
    }

    $existing =
        $id !== ''
            ? surveyById($data, $id)
            : null;

    $survey = normalizeSurvey([
        'id' =>
            $existing['id']
            ?? ($id !== ''
                ? $id
                : uid('survey')),

        'createdAt' =>
            $existing['createdAt']
            ?? today(),

        'updatedAt' => today(),

        'title' => $title,

        'description' =>
            postString('description'),

        'startAt' => $startAt,

        'endAt' => $endAt,

        'status' =>
            $existing['status']
            ?? 'draft',

        'numbering' => $numbering,

        'groups' =>
            postArray('groups'),
    ]);

    foreach ($survey['groups'] as $group) {
        if (trim((string)$group['title']) === ''
            && count($group['questions']) === 0) {
            continue;
        }

        foreach ($group['questions'] as $question) {
            if (trim((string)$question['text']) === '') {
                throw new InvalidArgumentException(
                    '質問文を入力してください。'
                );
            }

            if (mb_strlen(
                (string)$question['text']
            ) > 2000) {
                throw new InvalidArgumentException(
                    '質問文は2000文字以内で入力してください。'
                );
            }

            if (
                in_array(
                    $question['type'],
                    ['single', 'multiple'],
                    true
                )
                && count($question['options']) === 0
            ) {
                throw new InvalidArgumentException(
                    '選択式質問には選択肢を1つ以上設定してください。'
                );
            }
        }
    }

    if ($existing !== null) {
        $index =
            surveyIndex(
                $data,
                $survey['id']
            );

        if ($index < 0) {
            throw new RuntimeException(
                'アンケートが存在しません。'
            );
        }

        $data['surveys'][$index] =
            $survey;
    } else {
        $data['surveys'][] =
            $survey;
    }

    saveData($data);

    flash(
        'success',
        'アンケートを保存しました。'
    );

    redirectTo('list');
}

function transitionAction(
    array &$data
): void {
    $id = postString('id');
    $to = postString('to');

    if (!validateId($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $index =
        surveyIndex(
            $data,
            $id
        );

    if ($index < 0) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $from =
        (string)(
            $data['surveys'][$index]['status']
            ?? ''
        );

    if (!canTransition(
        $from,
        $to
    )) {
        throw new InvalidArgumentException(
            '指定された状態遷移は許可されていません。'
        );
    }

    $data['surveys'][$index]['status'] =
        $to;

    $data['surveys'][$index]['updatedAt'] =
        today();

    saveData($data);

    flash(
        'success',
        '状態を変更しました。'
    );

    redirectTo('list');
}

function duplicateAction(
    array &$data
): void {
    $id = postString('id');

    if (!validateId($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $survey =
        surveyById(
            $data,
            $id
        );

    if (!$survey) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $survey['id'] =
        uid('survey');

    $survey['title'] .=
        '（コピー）';

    $survey['createdAt'] =
        today();

    $survey['updatedAt'] =
        today();

    $survey['status'] =
        'draft';

    foreach (
        $survey['groups']
        as &$group
    ) {
        $group['id'] =
            uid('g');

        foreach (
            $group['questions']
            as &$question
        ) {
            $question['id'] =
                uid('q');

            $question['branches'] =
                [];
        }
    }

    unset(
        $group,
        $question
    );

    renumberSurvey($survey);

    $data['surveys'][] =
        $survey;

    saveData($data);

    flash(
        'success',
        'アンケートを複製しました。'
    );

    redirectTo('list');
}

function deleteSurveyAction(
    array &$data
): void {
    $id = postString('id');

    if (!validateId($id)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $index =
        surveyIndex(
            $data,
            $id
        );

    if ($index < 0) {
        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    array_splice(
        $data['surveys'],
        $index,
        1
    );

    unset(
        $data['answers'][$id]
    );

    $data['sendHistory'] =
        array_values(
            array_filter(
                $data['sendHistory'],
                static fn(array $row): bool =>
                    ($row['surveyId'] ?? '') !== $id
            )
        );

    saveData($data);

    flash(
        'success',
        'アンケートを削除しました。'
    );

    redirectTo('list');
}

/* =========================================================
 * 回答
 * ========================================================= */

function answerConfirmAction(
    array &$data
): void {
    $surveyId =
        postString('surveyId');

    if (!validateId($surveyId)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $survey =
        surveyById(
            $data,
            $surveyId
        );

    if (!$survey
        || !surveyAvailable($survey)) {
        throw new RuntimeException(
            '回答可能なアンケートではありません。'
        );
    }

    $answers =
        postArray('answers');

    $errors =
        validateAnswers(
            $survey,
            $answers
        );

    if ($errors) {
        $_SESSION['answer_draft'] =
            $answers;

        $_SESSION['answer_errors'] =
            $errors;

        redirectTo(
            'answer',
            ['id' => $surveyId]
        );
    }

    $_SESSION['answer_draft'] =
        $answers;

    $_SESSION['answer_survey'] =
        $surveyId;

    $_SESSION['answer_customer'] =
        postString('customerId');

    redirectTo(
        'confirm',
        ['id' => $surveyId]
    );
}

function answerSubmitAction(
    array &$data
): void {
    $surveyId =
        postString('surveyId');

    if (!validateId($surveyId)) {
        throw new InvalidArgumentException(
            'アンケートIDが不正です。'
        );
    }

    $survey =
        surveyById(
            $data,
            $surveyId
        );

    if (!$survey
        || !surveyAvailable($survey)) {
        throw new RuntimeException(
            '回答可能なアンケートではありません。'
        );
    }

    $answers =
        $_SESSION['answer_draft']
        ?? [];

    if (!is_array($answers)) {
        throw new RuntimeException(
            '回答途中データが不正です。'
        );
    }

    $errors =
        validateAnswers(
            $survey,
            $answers
        );

    if ($errors) {
        throw new RuntimeException(
            '回答内容を確認してください。'
        );
    }

    $customerId =
        (string)(
            $_SESSION['answer_customer']
            ?? ''
        );

    $customer = null;

    foreach (
        $data['customers']
        as $candidate
    ) {
        if (($candidate['id'] ?? '')
            === $customerId) {
            $customer = $candidate;
            break;
        }
    }

    $data['answers'][$surveyId] ??=
        [];

    $data['answers'][$surveyId][] = [
        'id' => uid('answer'),
        'customerId' => $customerId,
        'customer' =>
            $customer['name']
            ?? '未登録回答者',
        'org' =>
            $customer['org']
            ?? '',
        'date' => now(),
        'values' => $answers,
    ];

    /*
     * 回答データ保存が成功するまで完了画面へ進めない。
     */
    saveData($data);

    unset(
        $_SESSION['answer_draft'],
        $_SESSION['answer_errors'],
        $_SESSION['answer_survey'],
        $_SESSION['answer_customer']
    );

    redirectTo(
        'complete',
        ['id' => $surveyId]
    );
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtpConnect(
    string $server,
    int $port,
    string $encryption,
    int $timeout = 20
) {
    if ($server === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    $remote = $encryption === 'ssl'
        ? 'ssl://' . $server . ':' . $port
        : 'tcp://' . $server . ':' . $port;

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if ($fp === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません。'
        );
    }

    stream_set_timeout(
        $fp,
        $timeout
    );

    smtpExpect(
        $fp,
        [220]
    );

    smtpCommand(
        $fp,
        'EHLO localhost',
        [250]
    );

    if ($encryption === 'tls') {
        smtpCommand(
            $fp,
            'STARTTLS',
            [220]
        );

        $crypto = @stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($fp);

            throw new RuntimeException(
                'SMTP TLS通信を確立できません。'
            );
        }

        smtpCommand(
            $fp,
            'EHLO localhost',
            [250]
        );
    }

    return $fp;
}

function smtpRead(
    $fp
): array {
    $lines = [];

    while (true) {
        $line = fgets($fp);

        if ($line === false) {
            throw new RuntimeException(
                'SMTPレスポンスを取得できません。'
            );
        }

        $line = rtrim(
            $line,
            "\r\n"
        );

        $lines[] = $line;

        if (preg_match(
            '/^\d{3} /',
            $line
        )) {
            break;
        }

        if (count($lines) > 100) {
            throw new RuntimeException(
                'SMTPレスポンスが不正です。'
            );
        }
    }

    return $lines;
}

function smtpExpect(
    $fp,
    array $codes
): array {
    $lines = smtpRead($fp);

    $last = $lines[count($lines) - 1] ?? '';

    $code = (int)substr(
        $last,
        0,
        3
    );

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTP通信に失敗しました。'
        );
    }

    return $lines;
}

function smtpCommand(
    $fp,
    string $command,
    array $codes
): array {
    if (@fwrite(
        $fp,
        $command . "\r\n"
    ) === false) {
        throw new RuntimeException(
            'SMTPコマンドを送信できません。'
        );
    }

    return smtpExpect(
        $fp,
        $codes
    );
}

function smtpAuth(
    $fp,
    string $username,
    string $password
): void {
    smtpCommand(
        $fp,
        'AUTH LOGIN',
        [334]
    );

    smtpCommand(
        $fp,
        base64_encode($username),
        [334]
    );

    smtpCommand(
        $fp,
        base64_encode($password),
        [235]
    );
}

function smtpDotStuff(
    string $body
): string {
    $body = str_replace(
        ["\r\n", "\r"],
        "\n",
        $body
    );

    $lines = explode(
        "\n",
        $body
    );

    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }

    unset($line);

    return implode(
        "\r\n",
        $lines
    );
}

function smtpSendMail(
    array $settings,
    string $password,
    string $to,
    string $subject,
    string $body
): void {
    if (!validEmail($to)) {
        throw new InvalidArgumentException(
            '送信先メールアドレスが不正です。'
        );
    }

    $server =
        (string)$settings['server'];

    $port =
        (int)$settings['port'];

    $encryption =
        (string)$settings['encryption'];

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new InvalidArgumentException(
            'SMTP暗号化方式が不正です。'
        );
    }

    $fp = null;

    try {
        $fp = smtpConnect(
            $server,
            $port,
            $encryption
        );

        if (!empty($settings['auth'])) {
            $username =
                (string)$settings['username'];

            if ($username === ''
                || $password === '') {
                throw new InvalidArgumentException(
                    'SMTP認証情報を入力してください。'
                );
            }

            smtpAuth(
                $fp,
                $username,
                $password
            );
        }

        $from =
            (string)$settings['fromEmail'];

        if (!validEmail($from)) {
            throw new InvalidArgumentException(
                '送信元メールアドレスが不正です。'
            );
        }

        smtpCommand(
            $fp,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtpCommand(
            $fp,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtpCommand(
            $fp,
            'DATA',
            [354]
        );

        $fromName =
            (string)$settings['fromName'];

        $encodedSubject =
            '=?UTF-8?B?'
            . base64_encode($subject)
            . '?=';

        $headers = [
            'Date: ' . date(
                'r'
            ),
            'From: '
                . ($fromName !== ''
                    ? '=?UTF-8?B?'
                        . base64_encode($fromName)
                        . '?= '
                    : '')
                . '<' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $replyTo =
            (string)$settings['replyTo'];

        if ($replyTo !== '') {
            if (!validEmail($replyTo)) {
                throw new InvalidArgumentException(
                    '返信先メールアドレスが不正です。'
                );
            }

            $headers[] =
                'Reply-To: <'
                . $replyTo
                . '>';
        }

        $message =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . smtpDotStuff($body)
            . "\r\n.";

        if (@fwrite(
            $fp,
            $message . "\r\n"
        ) === false) {
            throw new RuntimeException(
                'SMTPメッセージを送信できません。'
            );
        }

        smtpExpect(
            $fp,
            [250]
        );

        smtpCommand(
            $fp,
            'QUIT',
            [221]
        );
    } finally {
        if (is_resource($fp)) {
            fclose($fp);
        }

        $password = '';
    }
}

/* =========================================================
 * Mail settings
 * ========================================================= */

function saveMailAction(
    array &$data
): void {
    $server =
        postString('server');

    $portText =
        postString('port');

    $port =
        ctype_digit($portText)
            ? (int)$portText
            : 0;

    if ($server === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートは1～65535で指定してください。'
        );
    }

    $encryption =
        postString(
            'encryption',
            'tls'
        );

    if (!in_array(
        $encryption,
        ['ssl', 'tls', 'none'],
        true
    )) {
        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    $auth =
        postString(
            'auth',
            '1'
        ) === '1';

    $username =
        postString('username');

    $fromEmail =
        postString('fromEmail');

    $fromName =
        postString('fromName');

    $replyTo =
        postString('replyTo');

    if (!validEmail($fromEmail)) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    if ($replyTo !== ''
        && !validEmail($replyTo)) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }

    if ($auth && $username === '') {
        throw new InvalidArgumentException(
            'SMTP認証を使用する場合はユーザー名を入力してください。'
        );
    }

    $data['mailSettings'] = [
        'server' => $server,
        'port' => $port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'fromEmail' => $fromEmail,
        'fromName' => $fromName,
        'replyTo' => $replyTo,
        'connection' => '未設定',
        'connectionDetail' => '',
    ];

    saveData($data);

    flash(
        'success',
        'メールサーバ設定を保存しました。'
    );

    redirectTo('mail');
}

function testMailAction(
    array &$data
): void {
    $password =
        postString('password');

    try {
        if (!empty(
            $data['mailSettings']['auth']
        ) && $password === '') {
            throw new InvalidArgumentException(
                'SMTPパスワードを入力してください。'
            );
        }

        $settings =
            $data['mailSettings'];

        $fp = smtpConnect(
            (string)$settings['server'],
            (int)$settings['port'],
            (string)$settings['encryption']
        );

        try {
            if (!empty($settings['auth'])) {
                smtpAuth(
                    $fp,
                    (string)$settings['username'],
                    $password
                );
            }

            smtpCommand(
                $fp,
                'QUIT',
                [221]
            );
        } finally {
            fclose($fp);
        }
    } finally {
        $password = '';
    }

    $data['mailSettings']['connection'] =
        '接続確認済み';

    $data['mailSettings']['connectionDetail'] =
        'SMTP接続と認証に成功しました。';

    saveData($data);

    flash(
        'success',
        'SMTP接続テストに成功しました。'
    );

    redirectTo('mail');
}

function sendTestMailAction(
    array &$data
): void {
    $password =
        postString('password');

    $to =
        postString('to');

    try {
        if (!validEmail($to)) {
            throw new InvalidArgumentException(
                'テスト送信先メールアドレスが不正です。'
            );
        }

        if (!empty(
            $data['mailSettings']['auth']
        ) && $password === '') {
            throw new InvalidArgumentException(
                'SMTPパスワードを入力してください。'
            );
        }

        smtpSendMail(
            $data['mailSettings'],
            $password,
            $to,
            'アンケートアプリ SMTPテスト',
            "これはSMTP接続確認用のテストメールです。\r\n"
            . "送信日時: "
            . now()
        );
    } finally {
        $password = '';
    }

    $data['mailSettings']['connection'] =
        '接続確認済み';

    $data['mailSettings']['connectionDetail'] =
        'テストメール送信に成功しました。';

    saveData($data);

    flash(
        'success',
        'テストメールを送信しました。'
    );

    redirectTo('mail');
}

/* =========================================================
 * 顧客メール送信
 * ========================================================= */

function replaceMailVariables(
    string $text,
    array $customer,
    array $survey
): string {
    $url =
        (string)(
            $_SERVER['REQUEST_SCHEME']
            ?? 'https'
        )
        . '://'
        . (string)(
            $_SERVER['HTTP_HOST']
            ?? ''
        )
        . (string)(
            $_SERVER['SCRIPT_NAME']
            ?? '/index.php'
        )
        . '?screen=answer&id='
        . rawurlencode(
            (string)$survey['id']
        )
        . '&customerId='
        . rawurlencode(
            (string)($customer['id'] ?? '')
        );

    return strtr(
        $text,
        [
            '{顧客名}' =>
                (string)(
                    $customer['name']
                    ?? ''
                ),
            '{アンケートURL}' =>
                $url,
        ]
    );
}

function sendSurveyMailAction(
    array &$data
): void {
    $surveyId =
        postString('surveyId');

    if (!validateId($surveyId)) {
        throw new InvalidArgumentException(
            '送信対象アンケートIDが不正です。'
        );
    }

    $survey =
        surveyById(
            $data,
            $surveyId
        );

    if (!$survey) {
        throw new RuntimeException(
            '送信対象アンケートが存在しません。'
        );
    }

    $customerIds =
        postArray('customerIds');

    $subject =
        postString('subject');

    $body =
        postString('body');

    $password =
        postString('password');

    try {
        if ($subject === '') {
            throw new InvalidArgumentException(
                'メール件名を入力してください。'
            );
        }

        if ($body === '') {
            throw new InvalidArgumentException(
                'メール本文を入力してください。'
            );
        }

        if (empty($customerIds)) {
            throw new InvalidArgumentException(
                '送信対象顧客を選択してください。'
            );
        }

        if (!empty(
            $data['mailSettings']['auth']
        ) && $password === '') {
            throw new InvalidArgumentException(
                'SMTPパスワードを入力してください。'
            );
        }

        $results = [];

        foreach ($customerIds as $customerId) {
            if (!is_scalar($customerId)) {
                continue;
            }

            $customerId =
                (string)$customerId;

            $customer = null;

            foreach (
                $data['customers']
                as $candidate
            ) {
                if (($candidate['id'] ?? '')
                    === $customerId) {
                    $customer = $candidate;
                    break;
                }
            }

            if (!$customer) {
                $results[] = [
                    'customerId' => $customerId,
                    'customer' => '',
                    'email' => '',
                    'status' => 'failure',
                    'detail' => '顧客が存在しません。',
                    'date' => now(),
                ];

                continue;
            }

            $email =
                (string)(
                    $customer['email']
                    ?? ''
                );

            if (!validEmail($email)) {
                $results[] = [
                    'customerId' => $customerId,
                    'customer' =>
                        (string)(
                            $customer['name']
                            ?? ''
                        ),
                    'email' => $email,
                    'status' => 'failure',
                    'detail' =>
                        'メールアドレスが不正です。',
                    'date' => now(),
                ];

                continue;
            }

            $actualSubject =
                replaceMailVariables(
                    $subject,
                    $customer,
                    $survey
                );

            $actualBody =
                replaceMailVariables(
                    $body,
                    $customer,
                    $survey
                );

            try {
                smtpSendMail(
                    $data['mailSettings'],
                    $password,
                    $email,
                    $actualSubject,
                    $actualBody
                );

                /*
                 * SMTPの成功応答を取得した後だけ成功記録を作る。
                 */
                $results[] = [
                    'customerId' => $customerId,
                    'customer' =>
                        (string)(
                            $customer['name']
                            ?? ''
                        ),
                    'email' => $email,
                    'status' => 'success',
                    'detail' => '送信成功',
                    'date' => now(),
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'customerId' => $customerId,
                    'customer' =>
                        (string)(
                            $customer['name']
                            ?? ''
                        ),
                    'email' => $email,
                    'status' => 'failure',
                    'detail' =>
                        safeErrorMessage($e),
                    'date' => now(),
                ];
            }
        }

        foreach ($results as $result) {
            $data['sendHistory'][] = [
                'id' => uid('send'),
                'surveyId' => $surveyId,
                'customerId' =>
                    $result['customerId'],
                'customer' =>
                    $result['customer'],
                'email' =>
                    $result['email'],
                'status' =>
                    $result['status'],
                'detail' =>
                    $result['detail'],
                'date' =>
                    $result['date'],
            ];
        }

        saveData($data);

        $_SESSION['send_result'] = [
            'surveyId' => $surveyId,
            'results' => $results,
            'date' => now(),
        ];

        redirectTo(
            'send',
            ['id' => $surveyId]
        );
    } finally {
        $password = '';
    }
}

/* =========================================================
 * POST router
 * ========================================================= */

function processPost(
    array &$data
): void {
    $action =
        postString('action');

    switch ($action) {
        case 'save_survey':
            saveSurveyAction($data);
            return;

        case 'transition':
            transitionAction($data);
            return;

        case 'duplicate':
            duplicateAction($data);
            return;

        case 'delete_survey':
            deleteSurveyAction($data);
            return;

        case 'answer_confirm':
            answerConfirmAction($data);
            return;

        case 'answer_submit':
            answerSubmitAction($data);
            return;

        case 'save_kintone':
            saveKintoneAction($data);
            return;

        case 'test_kintone':
            testKintoneAction($data);
            return;

        case 'fetch_kintone_fields':
            fetchKintoneFieldsAction($data);
            return;

        case 'sync_kintone':
            syncKintoneAction($data);
            return;

        case 'save_mail':
            saveMailAction($data);
            return;

        case 'test_mail':
            testMailAction($data);
            return;

        case 'send_test_mail':
            sendTestMailAction($data);
            return;

        case 'send_survey_mail':
            sendSurveyMailAction($data);
            return;

        default:
            throw new InvalidArgumentException(
                '指定された操作は利用できません。'
            );
    }
}

/* =========================================================
 * HTML
 * ========================================================= */

function renderHeader(
    string $title,
    bool $admin = true
): string {
    $nav = '';

    if ($admin) {
        $nav = '
        <nav class="nav">
            <a href="?screen=list">アンケート一覧</a>
            <a href="?screen=kintone">kintone設定</a>
            <a href="?screen=mail">メール設定</a>
        </nav>';
    }

    return '<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title>' . h($title) . '</title>
<style>
*{box-sizing:border-box}
body{
 margin:0;
 background:#f5f7fa;
 color:#263238;
 font-family:-apple-system,BlinkMacSystemFont,
 "Segoe UI","Noto Sans JP",sans-serif;
 line-height:1.6
}
header{
 background:#263238;
 color:#fff;
 padding:14px 20px
}
header .inner{
 max-width:1200px;
 margin:auto;
 display:flex;
 justify-content:space-between;
 align-items:center;
 gap:20px
}
header a{
 color:#fff;
 text-decoration:none
}
main{
 max-width:1200px;
 margin:28px auto;
 padding:0 16px
}
.nav{
 display:flex;
 flex-wrap:wrap;
 gap:16px
}
.card{
 background:#fff;
 border:1px solid #dce2e8;
 border-radius:10px;
 padding:20px;
 margin-bottom:18px;
 box-shadow:0 2px 8px rgba(0,0,0,.04)
}
.grid2{
 display:grid;
 grid-template-columns:repeat(2,minmax(0,1fr));
 gap:16px
}
.field{margin-bottom:16px}
label{
 display:block;
 font-weight:600;
 margin-bottom:5px
}
input,textarea,select{
 width:100%;
 border:1px solid #c7d0d9;
 border-radius:6px;
 padding:9px 10px;
 font:inherit;
 background:#fff
}
textarea{min-height:110px}
button,.btn{
 display:inline-block;
 border:1px solid #c7d0d9;
 border-radius:6px;
 padding:9px 15px;
 background:#fff;
 color:#263238;
 cursor:pointer;
 text-decoration:none
}
button.primary,.btn.primary{
 background:#1976d2;
 color:#fff;
 border-color:#1976d2
}
button.danger{
 color:#c62828
}
.actions{
 display:flex;
 flex-wrap:wrap;
 gap:8px;
 margin-top:14px
}
.alert{
 border-radius:6px;
 padding:12px 15px;
 margin-bottom:18px
}
.alert.success{
 background:#e8f5e9;
 color:#2e7d32
}
.alert.error{
 background:#ffebee;
 color:#c62828
}
.muted{
 color:#607d8b
}
.table-wrap{
 overflow-x:auto
}
table{
 width:100%;
 border-collapse:collapse;
 background:#fff
}
th,td{
 border-bottom:1px solid #e0e6eb;
 padding:10px;
 text-align:left;
 vertical-align:top
}
th{
 background:#f7f9fb
}
.badge{
 display:inline-block;
 border-radius:999px;
 padding:2px 9px;
 font-size:.85em
}
.badge.draft{background:#eceff1}
.badge.published{background:#e3f2fd;color:#1565c0}
.badge.stopped{background:#fff3e0;color:#ef6c00}
.badge.ended{background:#ffebee;color:#c62828}
.question{
 border:1px solid #e0e6eb;
 border-radius:8px;
 padding:15px;
 margin:12px 0
}
.option-row{
 display:grid;
 grid-template-columns:1fr auto;
 gap:8px;
 margin:6px 0
}
.small{font-size:.9em}
pre{
 white-space:pre-wrap;
 word-break:break-word
}
@media(max-width:760px){
 .grid2{grid-template-columns:1fr}
 header .inner{
  align-items:flex-start;
  flex-direction:column
 }
 main{margin-top:18px}
 button,.btn{
  min-height:44px
 }
}
@media print{
 header,.no-print,.actions{
  display:none!important
 }
 body{background:#fff}
 main{
  max-width:none;
  margin:0;
  padding:0
 }
 .card{
  box-shadow:none;
  border:1px solid #aaa
 }
}
</style>
</head>
<body>
<header>
<div class="inner">
<strong>アンケートアプリ</strong>
' . $nav . '
</div>
</header>
<main>';
}

function renderFooter(): string
{
    return '</main>
</body>
</html>';
}

/* =========================================================
 * List
 * ========================================================= */

function statusLabel(
    string $status
): string {
    return match ($status) {
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '不明',
    };
}

function renderList(
    array $data
): string {
    $q =
        getString('q');

    $status =
        getString(
            'status',
            'all'
        );

    $sort =
        getString(
            'sort',
            'updated_desc'
        );

    $surveys =
        $data['surveys'];

    $surveys = array_values(
        array_filter(
            $surveys,
            static function (
                array $survey
            ) use (
                $q,
                $status,
                $data
            ): bool {
                if ($q !== ''
                    && !str_contains(
                        mb_strtolower(
                            (string)$survey['title']
                        ),
                        mb_strtolower($q)
                    )) {
                    return false;
                }

                if ($status !== 'all'
                    && ($survey['status'] ?? '')
                        !== $status) {
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
        ) use (
            $sort,
            $data
        ): int {
            $answersA =
                count(
                    $data['answers'][
                        $a['id']
                    ] ?? []
                );

            $answersB =
                count(
                    $data['answers'][
                        $b['id']
                    ] ?? []
                );

            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)$a['updatedAt'],
                        (string)$b['updatedAt']
                    ),

                'answers_desc' =>
                    $answersB <=> $answersA,

                'answers_asc' =>
                    $answersA <=> $answersB,

                'start_desc' =>
                    strcmp(
                        (string)$b['startAt'],
                        (string)$a['startAt']
                    ),

                'start_asc' =>
                    strcmp(
                        (string)$a['startAt'],
                        (string)$b['startAt']
                    ),

                default =>
                    strcmp(
                        (string)$b['updatedAt'],
                        (string)$a['updatedAt']
                    ),
            };
        }
    );

    $html = '
<h1>アンケート一覧</h1>

<div class="card">
<form method="get">
<input type="hidden"
 name="screen"
 value="list">

<div class="grid2">
<div class="field">
<label>タイトル検索</label>
<input name="q"
 value="' . h($q) . '"
 placeholder="タイトルで検索">
</div>

<div class="field">
<label>ステータス</label>
<select name="status">
<option value="all">すべて</option>
<option value="published" '
        . ($status === 'published'
            ? 'selected'
            : '')
        . '>公開中</option>
<option value="draft" '
        . ($status === 'draft'
            ? 'selected'
            : '')
        . '>下書き</option>
<option value="stopped" '
        . ($status === 'stopped'
            ? 'selected'
            : '')
        . '>停止</option>
<option value="ended" '
        . ($status === 'ended'
            ? 'selected'
            : '')
        . '>終了</option>
</select>
</div>
</div>

<div class="field">
<label>ソート</label>
<select name="sort">
<option value="updated_desc" '
        . ($sort === 'updated_desc'
            ? 'selected'
            : '')
        . '>更新日：新しい順</option>
<option value="updated_asc" '
        . ($sort === 'updated_asc'
            ? 'selected'
            : '')
        . '>更新日：古い順</option>
<option value="answers_desc" '
        . ($sort === 'answers_desc'
            ? 'selected'
            : '')
        . '>回答数：多い順</option>
<option value="answers_asc" '
        . ($sort === 'answers_asc'
            ? 'selected'
            : '')
        . '>回答数：少ない順</option>
<option value="start_desc" '
        . ($sort === 'start_desc'
            ? 'selected'
            : '')
        . '>開始日：新しい順</option>
<option value="start_asc" '
        . ($sort === 'start_asc'
            ? 'selected'
            : '')
        . '>開始日：古い順</option>
</select>
</div>

<button class="primary">検索</button>
</form>
</div>

<div class="actions">
<a class="btn primary"
 href="?screen=edit">新規作成</a>
<a class="btn"
 href="?screen=kintone">kintone設定</a>
<a class="btn"
 href="?screen=mail">メール設定</a>
</div>

<div class="card">
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
<tbody>';

    foreach ($surveys as $survey) {
        $id =
            (string)$survey['id'];

        $answerCount =
            count(
                $data['answers'][$id]
                ?? []
            );

        $html .= '<tr>
<td>'
            . h($survey['title'])
            . '</td>
<td>'
            . h($survey['createdAt'])
            . '</td>
<td>'
            . h($survey['updatedAt'])
            . '</td>
<td>'
            . h($survey['startAt'] ?: '指定なし')
            . ' ～ '
            . h($survey['endAt'] ?: '指定なし')
            . '</td>
<td><span class="badge '
            . h($survey['status'])
            . '">'
            . h(
                statusLabel(
                    (string)$survey['status']
                )
            )
            . '</span></td>
<td>'
            . h($answerCount)
            . '</td>
<td>
<div class="actions">
<a class="btn"
 href="?screen=edit&id='
            . rawurlencode($id)
            . '">確認・編集</a>
<a class="btn"
 href="?screen=preview&id='
            . rawurlencode($id)
            . '">プレビュー</a>
<a class="btn"
 href="?screen=analytics&id='
            . rawurlencode($id)
            . '">集計</a>
<a class="btn"
 href="?screen=send&id='
            . rawurlencode($id)
            . '">送信</a>';

        if (
            $survey['status'] === 'draft'
            || $survey['status'] === 'published'
            || $survey['status'] === 'stopped'
        ) {
            $next =
                $survey['status'] === 'published'
                    ? 'stopped'
                    : 'published';

            $label =
                $next === 'published'
                    ? '公開'
                    : '停止';

            $html .= '
<form method="post">
<input type="hidden"
 name="action"
 value="transition">
<input type="hidden"
 name="id"
 value="' . h($id) . '">
<input type="hidden"
 name="to"
 value="' . h($next) . '">
<button>'
                . h($label)
                . '</button>
</form>';
        }

        $html .= '
<form method="post"
 onsubmit="return confirm(\'複製しますか？\')">
<input type="hidden"
 name="action"
 value="duplicate">
<input type="hidden"
 name="id"
 value="' . h($id) . '">
<button>複製</button>
</form>

<form method="post"
 onsubmit="return confirm(\'このアンケートを削除しますか？\')">
<input type="hidden"
 name="action"
 value="delete_survey">
<input type="hidden"
 name="id"
 value="' . h($id) . '">
<button class="danger">削除</button>
</form>
</div>
</td>
</tr>';
    }

    if (!$surveys) {
        $html .= '
<tr>
<td colspan="7"
 class="muted">
該当するアンケートはありません。
</td>
</tr>';
    }

    $html .= '
</tbody>
</table>
</div>
</div>';

    return $html;
}

/* =========================================================
 * Edit
 * ========================================================= */

function renderEdit(
    array $survey
): string {
    $groups =
        $survey['groups'];

    $html = '
<h1>アンケート編集</h1>

<form method="post">
<input type="hidden"
 name="action"
 value="save_survey">

<input type="hidden"
 name="id"
 value="' . h($survey['id']) . '">

<div class="card">
<div class="grid2">
<div class="field">
<label>タイトル</label>
<input name="title"
 value="' . h($survey['title']) . '"
 maxlength="200"
 required>
</div>

<div class="field">
<label>採番方式</label>
<select name="numbering">
<option value="global" '
        . ($survey['numbering'] === 'global'
            ? 'selected'
            : '')
        . '>全体通番：Q1、Q2...</option>
<option value="group" '
        . ($survey['numbering'] === 'group'
            ? 'selected'
            : '')
        . '>グループ単位：Q1-1、Q1-2...</option>
</select>
</div>
</div>

<div class="field">
<label>説明</label>
<textarea name="description">'
        . h($survey['description'])
        . '</textarea>
</div>

<div class="grid2">
<div class="field">
<label>開始日時</label>
<input type="datetime-local"
 name="startAt"
 value="' . h($survey['startAt']) . '">
</div>

<div class="field">
<label>終了日時</label>
<input type="datetime-local"
 name="endAt"
 value="' . h($survey['endAt']) . '">
</div>
</div>

<p class="muted">
現在の状態:
<strong>'
        . h(
            statusLabel(
                (string)$survey['status']
            )
        )
        . '</strong>
</p>
</div>';

    foreach (
        $groups
        as $gi => $group
    ) {
        $html .= '
<div class="card">
<h2>グループ '
            . ($gi + 1)
            . '</h2>

<div class="field">
<label>グループタイトル</label>
<input name="groups['
            . $gi
            . '][title]"
 value="'
            . h($group['title'])
            . '">
</div>';

        foreach (
            $group['questions']
            as $qi => $question
        ) {
            $html .= '
<div class="question">
<div class="grid2">

<div class="field">
<label>'
                . h($question['number'])
                . ' 質問文</label>

<input type="hidden"
 name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][id]"
 value="'
                . h($question['id'])
                . '">

<input name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][text]"
 value="'
                . h($question['text'])
                . '"
 maxlength="2000"
 required>
</div>

<div class="field">
<label>回答形式</label>
<select name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][type]">
<option value="single" '
                . ($question['type'] === 'single'
                    ? 'selected'
                    : '')
                . '>単一選択</option>
<option value="multiple" '
                . ($question['type'] === 'multiple'
                    ? 'selected'
                    : '')
                . '>複数選択</option>
<option value="free" '
                . ($question['type'] === 'free'
                    ? 'selected'
                    : '')
                . '>自由記述</option>
</select>
</div>

</div>

<div class="field">
<label>
<input type="checkbox"
 name="groups['
                . $gi
                . '][questions]['
                . $qi
                . '][required]"
 value="1" '
                . (!empty($question['required'])
                    ? 'checked'
                    : '')
                . '>
必須
</label>
</div>';

            if (
                in_array(
                    $question['type'],
                    ['single', 'multiple'],
                    true
                )
            ) {
                $html .= '
<div class="field">
<label>選択肢（1行1項目）</label>
<textarea name="groups['
                    . $gi
                    . '][questions]['
                    . $qi
                    . '][optionsText]">';

                /*
                 * POST再構成用の特殊textareaではなく、
                 * 下のJavaScriptで送信前にoptions[]へ変換する。
                 */
                $html .= h(
                    implode(
                        "\n",
                        $question['options']
                    )
                );

                $html .= '</textarea>
</div>

<div class="field">
<label>条件分岐</label>';

                foreach (
                    $question['options']
                    as $option
                ) {
                    $target =
                        $question['branches'][
                            $option
                        ] ?? '';

                    $html .= '
<div class="option-row">
<div>'
                        . h($option)
                        . '</div>
<select
 name="groups['
                        . $gi
                        . '][questions]['
                        . $qi
                        . '][branch]['
                        . rawurlencode($option)
                        . ']">
<option value="">次の質問を指定しない</option>';

                    foreach (
                        allQuestions($survey)
                        as $targetQuestion
                    ) {
                        if (
                            $targetQuestion['id']
                            === $question['id']
                        ) {
                            continue;
                        }

                        $html .= '<option value="'
                            . h($targetQuestion['id'])
                            . '" '
                            . (
                                $target
                                === $targetQuestion['id']
                                    ? 'selected'
                                    : ''
                            )
                            . '>'
                            . h(
                                $targetQuestion['number']
                                . ' '
                                . $targetQuestion['text']
                            )
                            . '</option>';
                    }

                    $html .= '
</select>
</div>';
                }

                $html .= '</div>';
            }

            $html .= '
</div>';
        }

        $html .= '
<p class="muted">
質問の追加・削除・並び替えはブラウザ側の操作で行えます。
保存時に質問番号を再計算します。
</p>
</div>';
    }

    if (!$groups) {
        $html .= '
<div class="card">
<p class="muted">
グループがまだありません。
</p>
</div>';
    }

    $html .= '
<div class="actions">
<button class="primary">
保存して一覧へ
</button>
<a class="btn"
 href="?screen=list"
 onclick="return confirm(\'編集内容を破棄して一覧へ戻りますか？\')">
キャンセル
</a>
</div>
</form>

<script>
document.querySelectorAll("form").forEach(function(form){
    form.addEventListener("submit", function(){
        document.querySelectorAll(
            "textarea[name$=\\"[optionsText]\\"]"
        ).forEach(function(area){
            var name = area.name;
            var values = area.value
                .split(/\\r?\\n/)
                .map(function(v){return v.trim();})
                .filter(function(v){return v !== "";});

            var prefix = name.replace("[optionsText]", "");

            area.name = prefix + "[options][]";

            values.forEach(function(value){
                var input = document.createElement("input");
                input.type = "hidden";
                input.name = prefix + "[options][]";
                input.value = value;
                form.appendChild(input);
            });

            area.remove();
        });

        document.querySelectorAll(
            "select[name*=\\"[branch]\\"]"
        ).forEach(function(select){
            var matches = select.name.match(
                /^(.+)\\[branch\\]\\[.*\\]$/
            );

            if (!matches) {
                return;
            }

            var base = matches[1];
            var option = decodeURIComponent(
                select.name.substring(
                    select.name.lastIndexOf("[") + 1,
                    select.name.lastIndexOf("]")
                )
            );

            if (select.value !== "") {
                var hidden = document.createElement("input");
                hidden.type = "hidden";
                hidden.name = base + "[branches][" + option + "]";
                hidden.value = select.value;
                form.appendChild(hidden);
            }

            select.disabled = true;
        });
    });
});
</script>';

    return $html;
}

/* =========================================================
 * Preview
 * ========================================================= */

function renderPreview(
    array $survey
): string {
    $html = '
<h1>プレビュー</h1>

<div class="card">
<h2>'
        . h($survey['title'])
        . '</h2>';

    if ($survey['description'] !== '') {
        $html .= '<p>'
            . nl2br(
                h($survey['description'])
            )
            . '</p>';
    }

    foreach (
        $survey['groups']
        as $group
    ) {
        $html .= '
<div class="card">
<h3>'
            . h($group['title'])
            . '</h3>';

        foreach (
            $group['questions']
            as $question
        ) {
            $html .= '
<div class="question">
<strong>'
                . h($question['number'])
                . '</strong>
<p>'
                . nl2br(
                    h($question['text'])
                )
                . '</p>';

            if ($question['type'] === 'free') {
                $html .=
                    '<textarea disabled></textarea>';
            } else {
                foreach (
                    $question['options']
                    as $option
                ) {
                    $html .=
                        '<label>
<input type="'
                        . (
                            $question['type'] === 'single'
                                ? 'radio'
                                : 'checkbox'
                        )
                        . '" disabled>
'
                        . h($option)
                        . '</label><br>';
                }
            }

            if (!empty($question['required'])) {
                $html .=
                    '<p class="muted">必須</p>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
    }

    $html .= '
</div>

<div class="actions">
<a class="btn"
 href="?screen=list">一覧へ戻る</a>
<a class="btn"
 href="?screen=edit&id='
        . rawurlencode(
            (string)$survey['id']
        )
        . '">編集へ戻る</a>
</div>';

    return $html;
}

/* =========================================================
 * Answer
 * ========================================================= */

function renderAnswer(
    array $survey,
    array $answers = [],
    array $errors = [],
    string $customerId = ''
): string {
    $html = '
<h1>'
        . h($survey['title'])
        . '</h1>';

    if ($survey['description'] !== '') {
        $html .= '
<div class="card">'
            . nl2br(
                h($survey['description'])
            )
            . '</div>';
    }

    if ($errors) {
        $html .= '
<div class="alert error">
<ul>';

        foreach ($errors as $error) {
            $html .= '<li>'
                . h($error)
                . '</li>';
        }

        $html .= '</ul>
</div>';
    }

    $html .= '
<form method="post">
<input type="hidden"
 name="action"
 value="answer_confirm">

<input type="hidden"
 name="surveyId"
 value="'
        . h($survey['id'])
        . '">

<div class="card">
<label>顧客</label>
<select name="customerId">
<option value="">未登録回答者</option>';

    /*
     * 顧客データは管理者側で同期されたものだけを選択する。
     */
    $data = readData();

    foreach (
        $data['customers']
        as $customer
    ) {
        $html .= '<option value="'
            . h($customer['id'])
            . '" '
            . (
                $customerId ===
                (string)$customer['id']
                    ? 'selected'
                    : ''
            )
            . '>'
            . h(
                ($customer['org'] ?? '')
                . ' '
                . ($customer['name'] ?? '')
                . ' '
                . ($customer['email'] ?? '')
            )
            . '</option>';
    }

    $html .= '
</select>
</div>';

    foreach (
        $survey['groups']
        as $group
    ) {
        $html .= '
<div class="card">
<h2>'
            . h($group['title'])
            . '</h2>';

        foreach (
            $group['questions']
            as $question
        ) {
            $id =
                (string)$question['id'];

            $value =
                $answers[$id] ?? '';

            $html .= '
<div class="question"
 data-question-id="'
                . h($id)
                . '"
 data-parent-option="';

            /*
             * クライアント側の表示切替は補助的なUI。
             * 最終判定はvalidateAnswers()でサーバー側でも行う。
             */
            $html .= h('');

            $html .= '">
<strong>'
                . h($question['number'])
                . '</strong>';

            if (!empty($question['required'])) {
                $html .=
                    ' <span class="muted">必須</span>';
            }

            $html .= '
<p>'
                . nl2br(
                    h($question['text'])
                )
                . '</p>';

            if ($question['type'] === 'free') {
                $html .=
                    '<textarea name="answers['
                    . h($id)
                    . ']">'
                    . h(
                        is_string($value)
                            ? $value
                            : ''
                    )
                    . '</textarea>';
            } elseif ($question['type'] === 'single') {
                foreach (
                    $question['options']
                    as $option
                ) {
                    $html .=
                        '<label>
<input type="radio"
 name="answers['
                        . h($id)
                        . ']"
 value="'
                        . h($option)
                        . '" '
                        . (
                            $value === $option
                                ? 'checked'
                                : ''
                        )
                        . '>
'
                        . h($option)
                        . '</label>';
                }
            } else {
                $selected =
                    is_array($value)
                        ? $value
                        : [];

                foreach (
                    $question['options']
                    as $option
                ) {
                    $html .=
                        '<label>
<input type="checkbox"
 name="answers['
                        . h($id)
                        . '][]"
 value="'
                        . h($option)
                        . '" '
                        . (
                            in_array(
                                $option,
                                $selected,
                                true
                            )
                                ? 'checked'
                                : ''
                        )
                        . '>
'
                        . h($option)
                        . '</label>';
                }
            }

            $html .= '</div>';
        }

        $html .= '</div>';
    }

    $html .= '
<div class="actions">
<button class="primary">
回答確認へ
</button>
</div>
</form>';

    return $html;
}

/* =========================================================
 * Confirm
 * ========================================================= */

function renderConfirm(
    array $survey
): string {
    $answers =
        $_SESSION['answer_draft']
        ?? [];

    $map =
        questionMap($survey);

    $html = '
<h1>回答確認</h1>

<div class="card">
<p>入力内容を確認してください。</p>';

    foreach (
        visibleQuestionIds(
            $survey,
            is_array($answers)
                ? $answers
                : []
        ) as $id
    ) {
        if (!isset($map[$id])) {
            continue;
        }

        $question =
            $map[$id];

        $value =
            $answers[$id] ?? '';

        if (is_array($value)) {
            $display =
                implode(
                    '、',
                    array_map(
                        'strval',
                        $value
                    )
                );
        } else {
            $display =
                (string)$value;
        }

        $html .= '
<div class="question">
<strong>'
            . h($question['number'])
            . '</strong>
<p>'
            . nl2br(
                h($question['text'])
            )
            . '</p>
<p>'
            . nl2br(
                h($display)
            )
            . '</p>
</div>';
    }

    $html .= '
</div>

<div class="actions">
<a class="btn"
 href="?screen=answer&id='
        . rawurlencode(
            (string)$survey['id']
        )
        . '">回答を修正</a>

<form method="post">
<input type="hidden"
 name="action"
 value="answer_submit">
<input type="hidden"
 name="surveyId"
 value="'
        . h($survey['id'])
        . '">
<button class="primary">
回答送信
</button>
</form>
</div>';

    return $html;
}

function renderComplete(
    array $survey
): string {
    return '
<h1>回答完了</h1>
<div class="card">
<p>アンケートへの回答を受け付けました。</p>
<p class="muted">
ご回答ありがとうございました。
</p>
</div>';
}

/* =========================================================
 * Analytics
 * ========================================================= */

function renderAnalytics(
    array $data,
    array $survey
): string {
    /*
     * 画面表示時にも対象IDを固定する。
     */
    $id =
        (string)$survey['id'];

    $answers =
        $data['answers'][$id]
        ?? [];

    $history =
        array_values(
            array_filter(
                $data['sendHistory'],
                static fn(array $row): bool =>
                    ($row['surveyId'] ?? '') === $id
            )
        );

    $sent =
        count($history);

    $answered =
        count($answers);

    $unregistered =
        0;

    foreach ($answers as $answer) {
        if (($answer['customerId'] ?? '') === '') {
            $unregistered++;
        }
    }

    $unanswered =
        max(
            0,
            $sent - $answered
        );

    $rate =
        $sent > 0
            ? round(
                ($answered / $sent) * 100,
                1
            )
            : 0;

    $html = '
<h1>回答集計・分析</h1>

<div class="card">
<h2>'
        . h($survey['title'])
        . '</h2>

<div class="grid2">
<div>
<strong>送信対象者数</strong>
<p>'
        . h($sent)
        . '</p>
</div>

<div>
<strong>回答数</strong>
<p>'
        . h($answered)
        . '</p>
</div>

<div>
<strong>未登録回答数</strong>
<p>'
        . h($unregistered)
        . '</p>
</div>

<div>
<strong>未回答数</strong>
<p>'
        . h($unanswered)
        . '</p>
</div>

<div>
<strong>回答率</strong>
<p>'
        . h($rate)
        . '%</p>
</div>
</div>

<div class="actions no-print">
<a class="btn"
 href="?screen=analytics&id='
        . rawurlencode($id)
        . '&export=csv">
CSV出力</a>
<button onclick="window.print()">
PDF / 印刷
</button>
</div>
</div>';

    if (!$answers) {
        return $html
            . '
<div class="card">
<p class="muted">
現在、回答データはありません
</p>
</div>';
    }

    foreach (
        allQuestions($survey)
        as $question
    ) {
        if (!in_array(
            $question['type'],
            ['single', 'multiple'],
            true
        )) {
            continue;
        }

        $counts = [];

        foreach (
            $question['options']
            as $option
        ) {
            $counts[$option] = 0;
        }

        foreach (
            $answers
            as $answer
        ) {
            $value =
                $answer['values'][
                    $question['id']
                ] ?? null;

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (isset(
                        $counts[$item]
                    )) {
                        $counts[$item]++;
                    }
                }
            } elseif (
                isset(
                    $counts[(string)$value]
                )
            ) {
                $counts[(string)$value]++;
            }
        }

        $html .= '
<div class="card">
<h3>'
            . h($question['number'])
            . ' '
            . h($question['text'])
            . '</h3>

<table>
<thead>
<tr>
<th>選択肢</th>
<th>回答数</th>
</tr>
</thead>
<tbody>';

        foreach (
            $counts
            as $option => $count
        ) {
            $html .= '
<tr>
<td>'
                . h($option)
                . '</td>
<td>'
                . h($count)
                . '</td>
</tr>';
        }

        $html .= '
</tbody>
</table>
</div>';
    }

    $html .= '
<div class="card">
<h2>個別回答</h2>';

    foreach (
        $answers
        as $answer
    ) {
        $html .= '
<div class="question">
<p><strong>'
            . h(
                $answer['customer']
                ?? '未登録回答者'
            )
            . '</strong>
'
            . h(
                $answer['date']
                ?? ''
            )
            . '</p>';

        foreach (
            allQuestions($survey)
            as $question
        ) {
            $value =
                $answer['values'][
                    $question['id']
                ] ?? '';

            if (is_array($value)) {
                $value =
                    implode(
                        '、',
                        array_map(
                            'strval',
                            $value
                        )
                    );
            }

            $html .= '
<p>
<strong>'
                . h($question['number'])
                . '</strong>
'
                . nl2br(
                    h((string)$value)
                )
                . '</p>';
        }

        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}

/* =========================================================
 * CSV
 * ========================================================= */

function exportAnalyticsCsv(
    array $data,
    array $survey
): never {
    $id =
        (string)$survey['id'];

    /*
     * データ取得時にも対象IDを確定。
     */
    $fixedSurvey =
        surveyById(
            $data,
            $id
        );

    if (!$fixedSurvey) {
        throw new RuntimeException(
            '対象アンケートが存在しません。'
        );
    }

    $answers =
        $data['answers'][$id]
        ?? [];

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="survey-'
        . preg_replace(
            '/[^A-Za-z0-9_-]/',
            '_',
            $id
        )
        . '.csv"'
    );

    echo "\xEF\xBB\xBF";

    $fp = fopen(
        'php://output',
        'wb'
    );

    if ($fp === false) {
        throw new RuntimeException(
            'CSV出力を開始できません。'
        );
    }

    $header = [
        '回答ID',
        '回答日時',
        '顧客名',
        '組織',
    ];

    foreach (
        allQuestions($fixedSurvey)
        as $question
    ) {
        $header[] =
            $question['number']
            . ' '
            . $question['text'];
    }

    fputcsv(
        $fp,
        $header
    );

    foreach (
        $answers
        as $answer
    ) {
        $row = [
            (string)($answer['id'] ?? ''),
            (string)($answer['date'] ?? ''),
            (string)($answer['customer'] ?? ''),
            (string)($answer['org'] ?? ''),
        ];

        foreach (
            allQuestions($fixedSurvey)
            as $question
        ) {
            $value =
                $answer['values'][
                    $question['id']
                ] ?? '';

            if (is_array($value)) {
                $value =
                    implode(
                        '、',
                        array_map(
                            'strval',
                            $value
                        )
                    );
            }

            $row[] =
                (string)$value;
        }

        fputcsv(
            $fp,
            $row
        );
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * kintone UI
 * ========================================================= */

function renderKintone(
    array $data
): string {
    $settings =
        $data['kintone'];

    $fields =
        is_array($settings['fields'] ?? null)
            ? $settings['fields']
            : [];

    $mapping =
        is_array($settings['mappings'] ?? null)
            ? $settings['mappings']
            : [];

    $html = '
<h1>kintone設定</h1>

<div class="card">
<form method="post">

<input type="hidden"
 name="action"
 value="save_kintone">

<div class="grid2">

<div class="field">
<label>サブドメイン</label>
<input name="subdomain"
 value="'
        . h($settings['subdomain'])
        . '"
 placeholder="example または example.cybozu.com">
</div>

<div class="field">
<label>顧客管理アプリID</label>
<input name="appId"
 value="'
        . h($settings['appId'])
        . '"
 inputmode="numeric">
</div>

<div class="field">
<label>ログイン名</label>
<input name="username"
 value="'
        . h($settings['username'])
        . '">
</div>

<div class="field">
<label>Proxy</label>
<input name="proxy"
 value="'
        . h($settings['proxy'])
        . '"
 placeholder="host:port">
</div>

</div>

<div class="field">
<label>SSL証明書検証</label>
<select name="sslVerify">
<option value="1" '
        . (
            $settings['sslVerify']
                ? 'selected'
                : ''
        )
        . '>有効</option>
<option value="0" '
        . (
            !$settings['sslVerify']
                ? 'selected'
                : ''
        )
        . '>無効（POC）</option>
</select>
</div>

<p class="muted">
kintoneパスワードはHTMLへ保存・再表示しません。
接続テスト・項目取得・顧客同期時にPOSTで入力してください。
</p>

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

<div class="field">
<label>kintoneパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password"
 required>
</div>

<button class="primary">
接続テスト
</button>
</form>
</div>

<div class="card">
<h2>項目一覧再取得</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="fetch_kintone_fields">

<div class="field">
<label>kintoneパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password"
 required>
</div>

<button>
項目一覧再取得
</button>
</form>';

    if ($fields) {
        $html .= '
<hr>
<h3>取得済み項目</h3>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>フィールドコード</th>
<th>ラベル</th>
<th>形式</th>
</tr>
</thead>
<tbody>';

        foreach (
            $fields
            as $code => $field
        ) {
            $html .= '
<tr>
<td>'
                . h($code)
                . '</td>
<td>'
                . h($field['label'] ?? '')
                . '</td>
<td>'
                . h($field['type'] ?? '')
                . '</td>
</tr>';
        }

        $html .= '
</tbody>
</table>
</div>';
    }

    $html .= '
</div>

<div class="card">
<h2>顧客項目マッピング</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="save_kintone_mapping">

<p class="muted">
取得済みkintone項目から顧客情報への対応を指定します。
</p>

<div class="grid2">';

    $mappingNames = [
        'org' => '組織名',
        'name' => '氏名',
        'email' => 'メールアドレス',
        'department' => '部署名',
        'phone' => '電話番号',
    ];

    foreach (
        $mappingNames
        as $key => $label
    ) {
        $html .= '
<div class="field">
<label>'
            . h($label)
            . '</label>
<select name="mapping['
            . h($key)
            . ']">
<option value="">指定なし</option>';

        foreach (
            $fields
            as $code => $field
        ) {
            $html .= '
<option value="'
                . h($code)
                . '" '
                . (
                    ($mapping[$key] ?? '')
                    === $code
                        ? 'selected'
                        : ''
                )
                . '>'
                . h(
                    $code
                    . ' / '
                    . ($field['label'] ?? '')
                )
                . '</option>';
        }

        $html .= '
</select>
</div>';
    }

    $html .= '
</div>

<div class="field">
<label>住所（複数指定可）</label>';

    foreach (
        $fields
        as $code => $field
    ) {
        $selected =
            is_array(
                $mapping['address'] ?? null
            )
                ? $mapping['address']
                : [];

        $html .= '
<label>
<input type="checkbox"
 name="mapping[address][]"
 value="'
            . h($code)
            . '" '
            . (
                in_array(
                    $code,
                    $selected,
                    true
                )
                    ? 'checked'
                    : ''
            )
            . '>
'
            . h(
                $code
                . ' / '
                . ($field['label'] ?? '')
            )
            . '</label>';
    }

    $html .= '
</div>

<button>
マッピング保存
</button>
</form>
</div>

<div class="card">
<h2>顧客情報同期</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="sync_kintone">

<div class="field">
<label>kintoneパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password"
 required>
</div>

<button class="primary">
顧客情報同期
</button>
</form>
</div>

<div class="card">
<h2>接続状態</h2>
<p><strong>'
        . h(
            $settings['connection']
            ?? '未設定'
        )
        . '</strong></p>
<p class="muted">'
        . h(
            $settings['connectionDetail']
            ?? ''
        )
        . '</p>
<p>
同期済み顧客数:
'
        . h(
            count(
                $data['customers']
                ?? []
            )
        )
        . '件
</p>
</div>';

    return $html;
}

/* =========================================================
 * Mail UI
 * ========================================================= */

function renderMail(
    array $data
): string {
    $s =
        $data['mailSettings'];

    return '
<h1>メールサーバ設定</h1>

<div class="card">
<form method="post">

<input type="hidden"
 name="action"
 value="save_mail">

<div class="grid2">

<div class="field">
<label>SMTPサーバ</label>
<input name="server"
 value="'
        . h($s['server'])
        . '"
 required>
</div>

<div class="field">
<label>SMTPポート</label>
<input name="port"
 value="'
        . h($s['port'])
        . '"
 inputmode="numeric"
 required>
</div>

<div class="field">
<label>暗号化方式</label>
<select name="encryption">
<option value="ssl" '
        . (
            $s['encryption'] === 'ssl'
                ? 'selected'
                : ''
        )
        . '>SSL</option>
<option value="tls" '
        . (
            $s['encryption'] === 'tls'
                ? 'selected'
                : ''
        )
        . '>TLS</option>
<option value="none" '
        . (
            $s['encryption'] === 'none'
                ? 'selected'
                : ''
        )
        . '>なし</option>
</select>
</div>

<div class="field">
<label>SMTP認証</label>
<select name="auth">
<option value="1" '
        . (
            $s['auth']
                ? 'selected'
                : ''
        )
        . '>使用する</option>
<option value="0" '
        . (
            !$s['auth']
                ? 'selected'
                : ''
        )
        . '>使用しない</option>
</select>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input name="username"
 value="'
        . h($s['username'])
        . '">
</div>

<div class="field">
<label>送信元メールアドレス</label>
<input name="fromEmail"
 value="'
        . h($s['fromEmail'])
        . '"
 type="email"
 required>
</div>

<div class="field">
<label>送信元名</label>
<input name="fromName"
 value="'
        . h($s['fromName'])
        . '">
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input name="replyTo"
 value="'
        . h($s['replyTo'])
        . '"
 type="email">
</div>

</div>

<p class="muted">
SMTPパスワードは設定保存しません。
接続テスト・テストメール・実メール送信時だけ入力します。
</p>

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
 value="test_mail">

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password">
</div>

<button>
接続テスト
</button>
</form>
</div>

<div class="card">
<h2>テストメール</h2>

<form method="post">
<input type="hidden"
 name="action"
 value="send_test_mail">

<div class="field">
<label>送信先</label>
<input type="email"
 name="to"
 required>
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password">
</div>

<button>
テストメール送信
</button>
</form>
</div>

<div class="card">
<h2>接続状態</h2>
<p><strong>'
        . h(
            $s['connection']
            ?? '未設定'
        )
        . '</strong></p>
<p class="muted">'
        . h(
            $s['connectionDetail']
            ?? ''
        )
        . '</p>
</div>';
}

/* =========================================================
 * Send UI
 * ========================================================= */

function renderSend(
    array $data,
    array $survey
): string {
    $id =
        (string)$survey['id'];

    $customers =
        $data['customers'];

    $result =
        $_SESSION['send_result']
        ?? null;

    if (
        is_array($result)
        && ($result['surveyId'] ?? '') === $id
    ) {
        unset(
            $_SESSION['send_result']
        );
    } else {
        $result = null;
    }

    $html = '
<h1>顧客選択・メール送信</h1>

<div class="card">
<p>対象アンケート:</p>
<strong>'
        . h($survey['title'])
        . '</strong>

<p class="muted">
送信対象アンケートはURLのIDによって固定されています。
この画面から別アンケートを選択することはできません。
</p>
</div>';

    if ($result) {
        $html .= '
<div class="card">
<h2>送信結果</h2>
<table>
<thead>
<tr>
<th>顧客</th>
<th>メール</th>
<th>結果</th>
<th>日時</th>
</tr>
</thead>
<tbody>';

        foreach (
            $result['results']
            as $row
        ) {
            $html .= '
<tr>
<td>'
                . h($row['customer'])
                . '</td>
<td>'
                . h($row['email'])
                . '</td>
<td>'
                . h($row['status'] === 'success'
                    ? '成功'
                    : '失敗')
                . '</td>
<td>'
                . h($row['date'])
                . '</td>
</tr>';
        }

        $html .= '
</tbody>
</table>
</div>';
    }

    $html .= '
<div class="card">
<h2>メール送信</h2>

<form method="post">

<input type="hidden"
 name="action"
 value="send_survey_mail">

<input type="hidden"
 name="surveyId"
 value="'
        . h($id)
        . '">

<div class="field">
<label>顧客</label>';

    if (!$customers) {
        $html .= '
<p class="muted">
同期済み顧客がありません。
先にkintoneから顧客情報を同期してください。
</p>';
    } else {
        foreach (
            $customers
            as $customer
        ) {
            $html .= '
<label>
<input type="checkbox"
 name="customerIds[]"
 value="'
                . h($customer['id'])
                . '">
'
                . h(
                    ($customer['org'] ?? '')
                    . ' / '
                    . ($customer['name'] ?? '')
                    . ' / '
                    . ($customer['email'] ?? '')
                )
                . '</label>';
        }
    }

    $html .= '
</div>

<div class="field">
<label>件名</label>
<input name="subject"
 value="アンケートのご案内"
 required>
</div>

<div class="field">
<label>本文</label>
<textarea name="body"
 required>いつもお世話になっております。

アンケートへのご協力をお願いいたします。

{顧客名} 様

アンケートURL:
{アンケートURL}</textarea>
</div>

<div class="field">
<label>SMTPパスワード</label>
<input type="password"
 name="password"
 autocomplete="new-password">
</div>

<p class="muted">
SMTP通信が成功した顧客だけを成功として送信履歴へ保存します。
</p>

<button class="primary">
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
<th>内容</th>
</tr>
</thead>
<tbody>';

    $history =
        array_values(
            array_filter(
                $data['sendHistory'],
                static fn(array $row): bool =>
                    ($row['surveyId'] ?? '') === $id
            )
        );

    foreach (
        array_reverse($history)
        as $row
    ) {
        $html .= '
<tr>
<td>'
            . h($row['date'] ?? '')
            . '</td>
<td>'
            . h($row['customer'] ?? '')
            . '</td>
<td>'
            . h($row['email'] ?? '')
            . '</td>
<td>'
            . h(
                ($row['status'] ?? '') === 'success'
                    ? '成功'
                    : '失敗'
            )
            . '</td>
<td>'
            . h($row['detail'] ?? '')
            . '</td>
</tr>';
    }

    if (!$history) {
        $html .= '
<tr>
<td colspan="5"
 class="muted">
送信履歴はありません。
</td>
</tr>';
    }

    $html .= '
</tbody>
</table>
</div>
</div>';

    return $html;
}

/* =========================================================
 * Error
 * ========================================================= */

function safeErrorMessage(
    Throwable $e
): string {
    $message =
        trim($e->getMessage());

    if ($message === '') {
        return '処理中にエラーが発生しました。';
    }

    /*
     * 秘密情報や内部情報を利用者へ出さない。
     */
    $unsafe = [
        '/password\s*[=:]/i',
        '/authorization\s*[=:]/i',
        '/x-cybozu-authorization/i',
        '/secret\s*[=:]/i',
        '/session\s*[=:]/i',
        '/cookie\s*[=:]/i',
        '/PHPSESSID/i',
        '/APP_SECRET/i',
        '/APP_ENCRYPTION_KEY/i',
    ];

    foreach ($unsafe as $pattern) {
        if (preg_match(
            $pattern,
            $message
        )) {
            return
                '外部サービスとの通信または設定処理に失敗しました。'
                . '設定内容を確認してください。';
        }
    }

    return $message;
}

/* =========================================================
 * Main
 * ========================================================= */

$data = null;
$screen = 'list';

try {
    $data =
        readData();

    updateAutomaticStatus(
        $data
    );

    $screen =
        getString(
            'screen',
            'list'
        );

    /*
     * CSVは対象アンケートIDを必須にし、
     * データ取得時にも同一IDを再確認する。
     */
    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
            === 'GET'
        && getString('export') === 'csv'
    ) {
        if (!in_array(
            $screen,
            ['analytics'],
            true
        )) {
            throw new InvalidArgumentException(
                'CSV出力対象画面が不正です。'
            );
        }

        $id =
            getString('id');

        if (!validateId($id)) {
            throw new InvalidArgumentException(
                '集計対象アンケートIDが必要です。'
            );
        }

        $survey =
            surveyById(
                $data,
                $id
            );

        if (!$survey) {
            throw new RuntimeException(
                '対象アンケートが存在しません。'
            );
        }

        exportAnalyticsCsv(
            $data,
            $survey
        );
    }

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
            === 'POST'
    ) {
        /*
         * prompt.txtに従いCSRFは実装しない。
         */
        processPost(
            $data
        );

        /*
         * 各正常POST処理はredirectTo()で終了する。
         * ここへ到達した場合だけ異常。
         */
        throw new RuntimeException(
            '処理結果を確定できませんでした。'
        );
    }

    $allowedScreens = [
        'list',
        'edit',
        'preview',
        'send',
        'analytics',
        'kintone',
        'mail',
        'answer',
        'confirm',
        'complete',
    ];

    if (!in_array(
        $screen,
        $allowedScreens,
        true
    )) {
        throw new InvalidArgumentException(
            '指定された画面は利用できません。'
        );
    }

    switch ($screen) {
        case 'list':
            $title =
                'アンケート一覧';

            $content =
                renderList($data);

            break;

        case 'edit':
            $id =
                getString('id');

            $survey =
                $id !== ''
                    ? surveyById(
                        $data,
                        $id
                    )
                    : normalizeSurvey([
                        'id' => uid('survey'),
                        'createdAt' => today(),
                        'updatedAt' => today(),
                        'title' => '',
                        'description' => '',
                        'startAt' => '',
                        'endAt' => '',
                        'status' => 'draft',
                        'numbering' => 'global',
                        'groups' => [
                            [
                                'id' => uid('g'),
                                'title' => 'グループ1',
                                'questions' => [
                                    [
                                        'id' => uid('q'),
                                        'text' => '',
                                        'type' => 'single',
                                        'required' => false,
                                        'options' => [
                                            '選択肢1',
                                            '選択肢2',
                                        ],
                                        'branches' => [],
                                    ],
                                ],
                            ],
                        ],
                    ]);

            if (!$survey) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            $title =
                'アンケート編集';

            $content =
                renderEdit($survey);

            break;

        case 'preview':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    'プレビュー対象アンケートIDが必要です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (!$survey) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            $title =
                'プレビュー';

            $content =
                renderPreview($survey);

            break;

        case 'analytics':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    '集計対象アンケートIDが必要です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (!$survey) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            /*
             * データ取得時にも対象IDを固定。
             */
            $fixedId =
                (string)$survey['id'];

            $survey =
                surveyById(
                    $data,
                    $fixedId
                );

            if (!$survey) {
                throw new RuntimeException(
                    '集計対象アンケートが存在しません。'
                );
            }

            $title =
                '回答集計・分析';

            $content =
                renderAnalytics(
                    $data,
                    $survey
                );

            break;

        case 'send':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    '送信対象アンケートIDが必要です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (!$survey) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            $title =
                '顧客選択・メール送信';

            $content =
                renderSend(
                    $data,
                    $survey
                );

            break;

        case 'kintone':
            $title =
                'kintone設定';

            $content =
                renderKintone($data);

            break;

        case 'mail':
            $title =
                'メールサーバ設定';

            $content =
                renderMail($data);

            break;

        case 'answer':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    '回答対象アンケートIDが必要です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (!$survey
                || !surveyAvailable($survey)) {
                throw new RuntimeException(
                    '回答可能なアンケートではありません。'
                );
            }

            $answers =
                $_SESSION['answer_draft']
                ?? [];

            if (!is_array($answers)) {
                $answers = [];
            }

            $errors =
                $_SESSION['answer_errors']
                ?? [];

            if (!is_array($errors)) {
                $errors = [];
            }

            $customerId =
                (string)(
                    $_SESSION['answer_customer']
                    ?? ''
                );

            unset(
                $_SESSION['answer_errors']
            );

            echo renderHeader(
                $survey['title'],
                false
            );

            echo renderAnswer(
                $survey,
                $answers,
                $errors,
                $customerId
            );

            echo renderFooter();
            exit;

        case 'confirm':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    '回答確認対象アンケートIDが必要です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (!$survey
                || !surveyAvailable($survey)) {
                throw new RuntimeException(
                    '回答可能なアンケートではありません。'
                );
            }

            if (
                ($_SESSION['answer_survey'] ?? '')
                !== $id
            ) {
                throw new RuntimeException(
                    '回答途中データがありません。'
                );
            }

            $title =
                '回答確認';

            $content =
                renderConfirm($survey);

            break;

        case 'complete':
            $id =
                getString('id');

            if (!validateId($id)) {
                throw new InvalidArgumentException(
                    'アンケートIDが不正です。'
                );
            }

            $survey =
                surveyById(
                    $data,
                    $id
                );

            if (!$survey) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            $title =
                '回答完了';

            $content =
                renderComplete($survey);

            /*
             * 回答者画面なので管理者メニューを表示しない。
             */
            echo renderHeader(
                $title,
                false
            );

            echo $content;

            echo renderFooter();
            exit;
    }

    $flash =
        takeFlash();

    echo renderHeader(
        $title,
        true
    );

    if ($flash) {
        echo '<div class="alert '
            . h($flash['type'])
            . '">'
            . h($flash['message'])
            . '</div>';
    }

    echo $content;

    echo renderFooter();

} catch (Throwable $e) {
    $message =
        safeErrorMessage($e);

    /*
     * PHP内部エラー・秘密情報・認証情報は
     * 画面へ出力しない。
     */
    http_response_code(500);

    $isAnswerScreen =
        in_array(
            $screen,
            ['answer', 'confirm', 'complete'],
            true
        );

    echo renderHeader(
        '処理エラー',
        !$isAnswerScreen
    );

    echo '<div class="alert error">'
        . h($message)
        . '</div>';

    if ($isAnswerScreen) {
        echo '<div class="actions">
<a class="btn"
 href="?screen=answer&id='
            . h(
                getString('id')
            )
            . '">
回答画面へ戻る
</a>
</div>';
    } else {
        echo '<div class="actions">
<a class="btn"
 href="?screen=list">
アンケート一覧へ戻る
</a>';

        if ($screen === 'kintone') {
            echo '<a class="btn"
 href="?screen=kintone">
kintone設定へ戻る
</a>';
        }

        if ($screen === 'mail') {
            echo '<a class="btn"
 href="?screen=mail">
メール設定へ戻る
</a>';
        }

        echo '</div>';
    }

    echo renderFooter();
}

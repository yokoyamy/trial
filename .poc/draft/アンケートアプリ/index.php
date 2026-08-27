<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * 実行環境:
 *   Apache 2.4
 *   PHP 8.5
 *   DBなし
 *   PHP cURLなし
 *
 * POCでは以下を実装しない:
 *   - 管理者ログイン
 *   - CSRF
 *   - 初回設定
 *
 * データ:
 *   __DIR__ . '/data'
 *
 * 外部連携:
 *   - kintone 実接続
 *   - SMTP 実接続
 */

const APP_NAME = 'アンケートアプリ';

const SCREEN_LIST      = 'list';
const SCREEN_EDIT      = 'edit';
const SCREEN_PREVIEW   = 'preview';
const SCREEN_SEND      = 'send';
const SCREEN_ANALYTICS = 'analytics';
const SCREEN_KINTONE   = 'kintone';
const SCREEN_MAIL      = 'mail';
const SCREEN_ANSWER    = 'answer';
const SCREEN_CONFIRM   = 'confirm';
const SCREEN_COMPLETE  = 'complete';

const STATUS_DRAFT    = 'draft';
const STATUS_OPEN     = 'open';
const STATUS_STOPPED  = 'stopped';
const STATUS_FINISHED = 'finished';

const ANSWER_SINGLE = 'single';
const ANSWER_MULTI  = 'multi';
const ANSWER_TEXT   = 'text';

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

date_default_timezone_set('Asia/Tokyo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* =========================================================
 * 共通
 * ========================================================= */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function url(array $params = []): string
{
    return 'index.php' .
        ($params ? '?' . http_build_query($params) : '');
}

function redirectTo(array $params = []): never
{
    header('Location: ' . url($params), true, 303);
    exit;
}

function post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

function get(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uuid(): string
{
    return bin2hex(random_bytes(12));
}

/* =========================================================
 * データ保存
 * ========================================================= */

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException(
                'dataディレクトリを作成できません。'
            );
        }
    }
}

function dataFile(string $name): string
{
    $allowed = [
        'surveys.json',
        'answers.json',
        'customers.json',
        'mail_logs.json',
        'settings.json',
    ];

    if (!in_array($name, $allowed, true)) {
        throw new InvalidArgumentException('不正なデータファイルです。');
    }

    ensureDataDir();

    return DATA_DIR . DIRECTORY_SEPARATOR . $name;
}

function readData(string $name, array $default = []): array
{
    $file = dataFile($name);

    if (!is_file($file)) {
        return $default;
    }

    $fp = fopen($file, 'rb');

    if ($fp === false) {
        throw new RuntimeException('データファイルを開けません。');
    }

    try {
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($content === false || trim($content) === '') {
        return $default;
    }

    $data = json_decode(
        $content,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    return is_array($data) ? $data : $default;
}

function writeData(string $name, array $data): void
{
    $file = dataFile($name);

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(5));

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('データを保存できません。');
    }

    @chmod($tmp, 0660);

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データ保存を確定できません。');
    }
}

function surveys(): array
{
    return readData('surveys.json', []);
}

function saveSurveys(array $data): void
{
    writeData('surveys.json', array_values($data));
}

function answers(): array
{
    return readData('answers.json', []);
}

function saveAnswers(array $data): void
{
    writeData('answers.json', array_values($data));
}

function customers(): array
{
    return readData('customers.json', []);
}

function saveCustomers(array $data): void
{
    writeData('customers.json', array_values($data));
}

function mailLogs(): array
{
    return readData('mail_logs.json', []);
}

function saveMailLogs(array $data): void
{
    writeData('mail_logs.json', array_values($data));
}

function settings(): array
{
    return readData('settings.json', [
        'kintone' => [],
        'smtp' => [],
    ]);
}

function saveSettings(array $data): void
{
    writeData('settings.json', $data);
}

/* =========================================================
 * アンケート
 * ========================================================= */

function findSurvey(string $id): ?array
{
    foreach (surveys() as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function surveyStatus(array $survey): string
{
    $status = $survey['status'] ?? STATUS_DRAFT;

    if (
        $status === STATUS_OPEN &&
        !empty($survey['end_at'])
    ) {
        $end = strtotime((string)$survey['end_at']);

        if ($end !== false && $end < time()) {
            return STATUS_FINISHED;
        }
    }

    return $status;
}

function statusLabel(string $status): string
{
    return match ($status) {
        STATUS_DRAFT    => '下書き',
        STATUS_OPEN     => '公開中',
        STATUS_STOPPED  => '停止',
        STATUS_FINISHED => '終了',
        default         => $status,
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        STATUS_OPEN     => 'badge-success',
        STATUS_STOPPED  => 'badge-warning',
        STATUS_FINISHED => 'badge-danger',
        default         => 'badge-draft',
    };
}

function recalcNumbers(array &$survey): void
{
    $mode = $survey['numbering'] ?? 'global';
    $global = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        $local = 1;

        foreach ($group['questions'] as &$question) {
            if ($mode === 'group') {
                $question['number'] =
                    'Q' . ($gi + 1) . '-' . $local;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $local++;
            $global++;
        }

        unset($question);
    }

    unset($group);
}

function newSurvey(): array
{
    $survey = [
        'id' => uuid(),
        'title' => '新しいアンケート',
        'description' => '',
        'start_at' => date('Y-m-d\TH:i'),
        'end_at' => date(
            'Y-m-d\TH:i',
            strtotime('+30 days')
        ),
        'status' => STATUS_DRAFT,
        'numbering' => 'global',
        'created_at' => now(),
        'updated_at' => now(),
        'groups' => [
            [
                'id' => uuid(),
                'title' => '基本情報',
                'questions' => [
                    [
                        'id' => uuid(),
                        'number' => 'Q1',
                        'text' => 'ご意見をお聞かせください。',
                        'type' => ANSWER_SINGLE,
                        'required' => true,
                        'options' => [
                            'とても良い',
                            '良い',
                            '普通',
                            '悪い',
                        ],
                        'branch' => [],
                    ],
                ],
            ],
        ],
    ];

    recalcNumbers($survey);

    return $survey;
}

function saveSurvey(array $survey): void
{
    $list = surveys();
    $found = false;

    $survey['updated_at'] = now();

    recalcNumbers($survey);

    foreach ($list as $i => $item) {
        if (($item['id'] ?? '') === $survey['id']) {
            $list[$i] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $list[] = $survey;
    }

    saveSurveys($list);
}

function deleteSurvey(string $id): void
{
    $list = array_values(
        array_filter(
            surveys(),
            fn(array $s): bool =>
                ($s['id'] ?? '') !== $id
        )
    );

    saveSurveys($list);
}

function duplicateSurvey(string $id): void
{
    $survey = findSurvey($id);

    if ($survey === null) {
        return;
    }

    $survey['id'] = uuid();
    $survey['title'] .= '（複製）';
    $survey['status'] = STATUS_DRAFT;
    $survey['created_at'] = now();
    $survey['updated_at'] = now();

    foreach ($survey['groups'] as &$group) {
        $group['id'] = uuid();

        foreach ($group['questions'] as &$question) {
            $question['id'] = uuid();
        }

        unset($question);
    }

    unset($group);

    recalcNumbers($survey);
    saveSurvey($survey);
}

/* =========================================================
 * アンケート入力
 * ========================================================= */

function buildSurveyFromPost(): array
{
    $id = trim((string)post('id', ''));

    $survey = $id !== ''
        ? findSurvey($id)
        : null;

    if ($survey === null) {
        $survey = newSurvey();
    }

    $survey['title'] = trim((string)post('title', ''));
    $survey['description'] = trim(
        (string)post('description', '')
    );

    $survey['start_at'] = trim(
        (string)post('start_at', '')
    );

    $survey['end_at'] = trim(
        (string)post('end_at', '')
    );

    $numbering = (string)post('numbering', 'global');

    $survey['numbering'] =
        $numbering === 'group'
            ? 'group'
            : 'global';

    $groups = post('groups', []);

    if (!is_array($groups)) {
        $groups = [];
    }

    $survey['groups'] = [];

    foreach ($groups as $groupIndex => $groupInput) {
        if (!is_array($groupInput)) {
            continue;
        }

        $groupId = trim(
            (string)($groupInput['id'] ?? '')
        );

        if ($groupId === '') {
            $groupId = uuid();
        }

        $groupTitle = trim(
            (string)($groupInput['title'] ?? '')
        );

        if ($groupTitle === '') {
            $groupTitle = 'グループ ' . ($groupIndex + 1);
        }

        $group = [
            'id' => $groupId,
            'title' => $groupTitle,
            'questions' => [],
        ];

        $questions = $groupInput['questions'] ?? [];

        if (is_array($questions)) {
            foreach ($questions as $questionInput) {
                if (!is_array($questionInput)) {
                    continue;
                }

                $questionId = trim(
                    (string)($questionInput['id'] ?? '')
                );

                if ($questionId === '') {
                    $questionId = uuid();
                }

                $type = (string)(
                    $questionInput['type'] ?? ANSWER_TEXT
                );

                if (!in_array(
                    $type,
                    [
                        ANSWER_SINGLE,
                        ANSWER_MULTI,
                        ANSWER_TEXT,
                    ],
                    true
                )) {
                    $type = ANSWER_TEXT;
                }

                $options = [];

                if (
                    isset($questionInput['options'])
                    && is_array($questionInput['options'])
                ) {
                    foreach ($questionInput['options'] as $option) {
                        $option = trim((string)$option);

                        if ($option !== '') {
                            $options[] = $option;
                        }
                    }
                }

                $branch = [];

                if (
                    isset($questionInput['branch'])
                    && is_array($questionInput['branch'])
                ) {
                    foreach ($questionInput['branch'] as $option => $target) {
                        $branch[(string)$option] =
                            (string)$target;
                    }
                }

                $group['questions'][] = [
                    'id' => $questionId,
                    'number' => '',
                    'text' => trim(
                        (string)($questionInput['text'] ?? '')
                    ),
                    'type' => $type,
                    'required' => !empty(
                        $questionInput['required']
                    ),
                    'options' => $options,
                    'branch' => $branch,
                ];
            }
        }

        $survey['groups'][] = $group;
    }

    recalcNumbers($survey);

    return $survey;
}

/* =========================================================
 * kintone
 * ========================================================= */

function kintoneConfig(): array
{
    return settings()['kintone'] ?? [];
}

function kintoneRequest(
    string $path,
    array $config,
    string $method = 'GET',
    ?array $body = null
): array {
    $subdomain = trim(
        (string)($config['subdomain'] ?? '')
    );

    $login = (string)($config['login'] ?? '');
    $password = (string)($config['password'] ?? '');

    if ($subdomain === '' || $login === '') {
        throw new RuntimeException(
            'kintone設定が不足しています。'
        );
    }

    $url = 'https://' . $subdomain . '.cybozu.com'
        . $path;

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password),
        'Content-Type: application/json',
    ];

    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ];

    if ($body !== null) {
        $contextOptions['http']['content'] =
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );
    }

    $context = stream_context_create($contextOptions);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへ接続できません。'
        );
    }

    $statusCode = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match(
            '/^HTTP\/\S+\s+(\d+)/',
            $header,
            $m
        )) {
            $statusCode = (int)$m[1];
            break;
        }
    }

    $decoded = json_decode(
        $response,
        true
    );

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = is_array($decoded)
            ? ($decoded['message'] ?? $response)
            : $response;

        throw new RuntimeException(
            'kintone APIエラー: ' . $message
        );
    }

    return is_array($decoded)
        ? $decoded
        : [];
}

function kintoneTest(): string
{
    $config = kintoneConfig();

    $app = (string)(
        $config['app_id'] ?? ''
    );

    if ($app === '') {
        throw new RuntimeException(
            '顧客管理アプリIDを設定してください。'
        );
    }

    kintoneRequest(
        '/k/v1/app.json?id=' .
            rawurlencode($app),
        $config
    );

    return 'kintoneへの接続に成功しました。';
}

function syncKintoneCustomers(): int
{
    $config = kintoneConfig();

    $app = (string)(
        $config['app_id'] ?? ''
    );

    if ($app === '') {
        throw new RuntimeException(
            '顧客管理アプリIDを設定してください。'
        );
    }

    $result = kintoneRequest(
        '/k/v1/records.json?app=' .
            rawurlencode($app) .
            '&query=' .
            rawurlencode('order by $id asc'),
        $config
    );

    $mapping = $config['mapping'] ?? [];

    $records = $result['records'] ?? [];

    $customers = [];

    foreach ($records as $record) {
        $value = function (string $logical) use (
            $record,
            $mapping
        ): string {
            $field = (string)(
                $mapping[$logical] ?? ''
            );

            if ($field === '') {
                return '';
            }

            return (string)(
                $record[$field]['value'] ?? ''
            );
        };

        $customers[] = [
            'id' => $value('id')
                ?: uuid(),
            'organization' => $value('organization'),
            'name' => $value('name'),
            'email' => $value('email'),
            'department' => $value('department'),
            'phone' => $value('phone'),
            'address' => $value('address'),
            'updated_at' => now(),
        ];
    }

    saveCustomers($customers);

    return count($customers);
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtpConfig(): array
{
    return settings()['smtp'] ?? [];
}

function smtpRead($fp): string
{
    $response = '';

    while (!feof($fp)) {
        $line = fgets($fp, 515);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    return $response;
}

function smtpCommand(
    $fp,
    string $command,
    array $expected
): string {
    fwrite($fp, $command . "\r\n");

    $response = smtpRead($fp);

    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . trim($response)
        );
    }

    return $response;
}

function smtpSend(
    string $to,
    string $subject,
    string $body
): void {
    $config = smtpConfig();

    $host = trim(
        (string)($config['host'] ?? '')
    );

    $port = (int)(
        $config['port'] ?? 587
    );

    $encryption = (string)(
        $config['encryption'] ?? 'tls'
    );

    $username = (string)(
        $config['username'] ?? ''
    );

    $password = (string)(
        $config['password'] ?? ''
    );

    $from = trim(
        (string)($config['from'] ?? '')
    );

    $fromName = trim(
        (string)($config['from_name'] ?? '')
    );

    $replyTo = trim(
        (string)($config['reply_to'] ?? '')
    );

    if (
        $host === '' ||
        $from === ''
    ) {
        throw new RuntimeException(
            'SMTP設定が不足しています。'
        );
    }

    $transportHost = $host;

    if ($encryption === 'ssl') {
        $transportHost = 'ssl://' . $host;
    }

    $fp = @stream_socket_client(
        $transportHost . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if ($fp === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できません: ' .
            $errstr
        );
    }

    stream_set_timeout($fp, 15);

    try {
        $response = smtpRead($fp);

        if ((int)substr($response, 0, 3) !== 220) {
            throw new RuntimeException(
                'SMTP接続に失敗しました。'
            );
        }

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

            if (
                !stream_socket_enable_crypto(
                    $fp,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                )
            ) {
                throw new RuntimeException(
                    'TLSを開始できません。'
                );
            }

            smtpCommand(
                $fp,
                'EHLO localhost',
                [250]
            );
        }

        if ($username !== '') {
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

        $encodedSubject = '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $headers = [
            'From: ' .
                ($fromName !== ''
                    ? '=?UTF-8?B?' .
                      base64_encode($fromName) .
                      '?= '
                    : '') .
                '<' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if ($replyTo !== '') {
            $headers[] =
                'Reply-To: <' . $replyTo . '>';
        }

        $body = str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

        $body = str_replace(
            "\n",
            "\r\n",
            $body
        );

        fwrite(
            $fp,
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            $body .
            "\r\n.\r\n"
        );

        $response = smtpRead($fp);

        if ((int)substr($response, 0, 3) !== 250) {
            throw new RuntimeException(
                'メール送信に失敗しました: ' .
                trim($response)
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
}

/* =========================================================
 * アクション
 * ========================================================= */

function handlePost(): void
{
    $action = (string)post('action', '');

    switch ($action) {
        case 'save_survey':
            $survey = buildSurveyFromPost();

            if ($survey['title'] === '') {
                throw new RuntimeException(
                    'タイトルを入力してください。'
                );
            }

            saveSurvey($survey);

            redirectTo([
                'screen' => SCREEN_LIST,
            ]);

        case 'delete_survey':
            deleteSurvey(
                (string)post('id', '')
            );

            redirectTo([
                'screen' => SCREEN_LIST,
            ]);

        case 'duplicate_survey':
            duplicateSurvey(
                (string)post('id', '')
            );

            redirectTo([
                'screen' => SCREEN_LIST,
            ]);

        case 'change_status':
            changeStatus(
                (string)post('id', ''),
                (string)post('status', '')
            );

            redirectTo([
                'screen' => SCREEN_LIST,
            ]);

        case 'save_kintone':
            saveKintoneSettings();
            redirectTo([
                'screen' => SCREEN_KINTONE,
            ]);

        case 'test_kintone':
            $_SESSION['flash'] = kintoneTest();

            redirectTo([
                'screen' => SCREEN_KINTONE,
            ]);

        case 'sync_kintone':
            $count = syncKintoneCustomers();

            $_SESSION['flash'] =
                $count . '件の顧客情報を同期しました。';

            redirectTo([
                'screen' => SCREEN_KINTONE,
            ]);

        case 'save_smtp':
            saveSmtpSettings();

            redirectTo([
                'screen' => SCREEN_MAIL,
            ]);

        case 'test_smtp':
            smtpSend(
                (string)post('test_to', ''),
                'SMTPテストメール',
                "SMTP接続テストです。\n"
                . now()
            );

            $_SESSION['flash'] =
                'テストメールを送信しました。';

            redirectTo([
                'screen' => SCREEN_MAIL,
            ]);

        case 'send_mail':
            handleSendMail();

        case 'submit_answer':
            handleAnswer();

        default:
            throw new RuntimeException(
                '不明な操作です。'
            );
    }
}

function changeStatus(
    string $id,
    string $newStatus
): void {
    $survey = findSurvey($id);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが見つかりません。'
        );
    }

    $current = surveyStatus($survey);

    $allowed = [
        STATUS_DRAFT => [
            STATUS_OPEN,
        ],
        STATUS_OPEN => [
            STATUS_STOPPED,
        ],
        STATUS_STOPPED => [
            STATUS_OPEN,
        ],
        STATUS_FINISHED => [],
    ];

    if (
        !in_array(
            $newStatus,
            $allowed[$current] ?? [],
            true
        )
    ) {
        throw new RuntimeException(
            '許可されていない状態変更です。'
        );
    }

    $survey['status'] = $newStatus;

    saveSurvey($survey);
}

function saveKintoneSettings(): void
{
    $config = settings();

    $mapping = [];

    $fields = [
        'id',
        'organization',
        'name',
        'email',
        'department',
        'phone',
        'address',
    ];

    foreach ($fields as $field) {
        $mapping[$field] = trim(
            (string)post(
                'mapping_' . $field,
                ''
            )
        );
    }

    $config['kintone'] = [
        'subdomain' => trim(
            (string)post('subdomain', '')
        ),
        'app_id' => trim(
            (string)post('app_id', '')
        ),
        'login' => trim(
            (string)post('login', '')
        ),
        'password' => (string)post(
            'password',
            ''
        ),
        'proxy' => trim(
            (string)post('proxy', '')
        ),
        'verify_ssl' => !empty(
            $_POST['verify_ssl']
        ),
        'mapping' => $mapping,
    ];

    saveSettings($config);

    $_SESSION['flash'] =
        'kintone設定を保存しました。';
}

function saveSmtpSettings(): void
{
    $config = settings();

    $config['smtp'] = [
        'host' => trim(
            (string)post('host', '')
        ),
        'port' => (int)post(
            'port',
            587
        ),
        'encryption' => (string)post(
            'encryption',
            'tls'
        ),
        'username' => trim(
            (string)post('username', '')
        ),
        'password' => (string)post(
            'password',
            ''
        ),
        'from' => trim(
            (string)post('from', '')
        ),
        'from_name' => trim(
            (string)post('from_name', '')
        ),
        'reply_to' => trim(
            (string)post('reply_to', '')
        ),
    ];

    saveSettings($config);

    $_SESSION['flash'] =
        'SMTP設定を保存しました。';
}

function handleSendMail(): never
{
    $surveyId = (string)post(
        'survey_id',
        ''
    );

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが見つかりません。'
        );
    }

    $ids = post('customer_ids', []);

    if (!is_array($ids) || !$ids) {
        throw new RuntimeException(
            '顧客を選択してください。'
        );
    }

    $subject = trim(
        (string)post(
            'subject',
            $survey['title'] . ' のご案内'
        )
    );

    $bodyTemplate = (string)post(
        'body',
        "{顧客名} 様\n\n"
        . "アンケートへのご協力をお願いいたします。\n\n"
        . "{アンケートURL}"
    );

    $baseUrl = getBaseUrl();

    $logs = mailLogs();
    $customerList = customers();

    foreach ($customerList as $customer) {
        $customerId = (string)(
            $customer['id'] ?? ''
        );

        if (!in_array(
            $customerId,
            array_map('strval', $ids),
            true
        )) {
            continue;
        }

        $email = trim(
            (string)($customer['email'] ?? '')
        );

        $name = (string)(
            $customer['name'] ?? ''
        );

        $body = str_replace(
            [
                '{顧客名}',
                '{アンケートURL}',
            ],
            [
                $name,
                $baseUrl . '?' . http_build_query([
                    'screen' => SCREEN_ANSWER,
                    'id' => $surveyId,
                ]),
            ],
            $bodyTemplate
        );

        $success = false;
        $error = '';

        try {
            if (
                $email === '' ||
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                throw new RuntimeException(
                    'メールアドレスが不正です。'
                );
            }

            smtpSend(
                $email,
                $subject,
                $body
            );

            $success = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $logs[] = [
            'id' => uuid(),
            'survey_id' => $surveyId,
            'customer_id' => $customerId,
            'customer_name' => $name,
            'email' => $email,
            'subject' => $subject,
            'success' => $success,
            'error' => $error,
            'created_at' => now(),
        ];
    }

    saveMailLogs($logs);

    redirectTo([
        'screen' => SCREEN_SEND,
        'id' => $surveyId,
    ]);
}

function handleAnswer(): never
{
    $surveyId = (string)post(
        'survey_id',
        ''
    );

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        throw new RuntimeException(
            'アンケートが見つかりません。'
        );
    }

    if (surveyStatus($survey) !== STATUS_OPEN) {
        throw new RuntimeException(
            'このアンケートは回答できません。'
        );
    }

    $values = post('answer', []);

    if (!is_array($values)) {
        $values = [];
    }

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            if (
                !empty($question['required'])
                && questionVisible(
                    $survey,
                    $values,
                    $question['id']
                )
            ) {
                $value = $values[
                    $question['id']
                ] ?? '';

                $empty =
                    $value === '' ||
                    $value === [] ||
                    $value === null;

                if ($empty) {
                    throw new RuntimeException(
                        '必須項目が未回答です。'
                    );
                }
            }
        }
    }

    $answers = answers();

    $answers[] = [
        'id' => uuid(),
        'survey_id' => $surveyId,
        'answers' => $values,
        'created_at' => now(),
    ];

    saveAnswers($answers);

    $_SESSION['answer_done'] = true;

    redirectTo([
        'screen' => SCREEN_COMPLETE,
    ]);
}

function questionVisible(
    array $survey,
    array $answers,
    string $questionId
): bool {
    $questions = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $questions[] = $question;
        }
    }

    $visible = true;

    foreach ($questions as $question) {
        if (($question['id'] ?? '') === $questionId) {
            break;
        }

        foreach (($question['branch'] ?? []) as $option => $target) {
            $answer = $answers[
                $question['id']
            ] ?? null;

            $matched = false;

            if (is_array($answer)) {
                $matched = in_array(
                    $option,
                    $answer,
                    true
                );
            } else {
                $matched =
                    (string)$answer ===
                    (string)$option;
            }

            if ($matched && $target !== '') {
                $visible =
                    $target === $questionId;
            }
        }
    }

    return $visible;
}

/* =========================================================
 * URL
 * ========================================================= */

function getBaseUrl(): string
{
    $scheme =
        (!empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST']
        ?? 'localhost';

    $dir = dirname(
        $_SERVER['SCRIPT_NAME']
        ?? '/index.php'
    );

    if ($dir === '/' || $dir === '\\') {
        $dir = '';
    }

    return $scheme .
        '://' .
        $host .
        rtrim($dir, '/');
}

/* =========================================================
 * HTML
 * ========================================================= */

function renderHeader(
    string $title,
    string $active = ''
): void {
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= e(APP_NAME) ?></title>

<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --success: #16a34a;
    --warning: #d97706;
    --danger: #dc2626;
    --gray: #64748b;
    --gray-light: #f1f5f9;
    --border: #dbe2ea;
    --text: #1e293b;
    --white: #fff;
    --background: #f8fafc;
    --header: #0f172a;
    --shadow: 0 4px 18px rgba(15,23,42,.08);
    --radius: 10px;
}

* { box-sizing:border-box; }

body {
    margin:0;
    background:var(--background);
    color:var(--text);
    font-family:
      -apple-system,
      BlinkMacSystemFont,
      "Segoe UI",
      "Noto Sans JP",
      "Hiragino Kaku Gothic ProN",
      Meiryo,
      sans-serif;
    line-height:1.6;
}

a {
    color:var(--primary);
    text-decoration:none;
}

a:hover { text-decoration:underline; }

button,
input,
select,
textarea {
    font:inherit;
}

button { cursor:pointer; }

h1 {
    font-size:1.65rem;
    margin:0 0 6px;
}

h2 {
    font-size:1.2rem;
    margin:0 0 18px;
}

h3 {
    font-size:1rem;
    margin:0 0 12px;
}

.app-header {
    background:var(--header);
    color:white;
    min-height:64px;
    display:flex;
    align-items:center;
    padding:0 24px;
}

.brand {
    color:white;
    font-weight:700;
    font-size:1.1rem;
}

.nav {
    margin-left:auto;
    display:flex;
    gap:6px;
}

.nav a {
    color:#cbd5e1;
    padding:8px 11px;
    border-radius:6px;
}

.nav a:hover,
.nav a.active {
    color:white;
    background:rgba(255,255,255,.08);
    text-decoration:none;
}

.container {
    width:min(1200px,calc(100% - 32px));
    margin:auto;
    padding:28px 0 48px;
}

.answer-container {
    width:min(760px,calc(100% - 32px));
    margin:auto;
    padding:28px 0 48px;
}

.page-title {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    margin-bottom:22px;
}

.card {
    background:white;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
}

.grid-2 {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:20px;
}

.grid-3 {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:20px;
}

.form-group {
    margin-bottom:16px;
}

.form-label {
    display:block;
    font-weight:600;
    margin-bottom:6px;
}

input[type=text],
input[type=search],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
select,
textarea {
    width:100%;
    min-height:42px;
    padding:9px 12px;
    border:1px solid var(--border);
    border-radius:7px;
    background:white;
    color:var(--text);
}

textarea {
    min-height:140px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus {
    outline:none;
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

.btn {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:8px 14px;
    border:1px solid transparent;
    border-radius:7px;
    font-weight:600;
    line-height:1.3;
    white-space:nowrap;
}

.btn:hover {
    text-decoration:none;
}

.btn-primary {
    background:var(--primary);
    color:white;
}

.btn-primary:hover {
    background:var(--primary-dark);
}

.btn-secondary {
    background:white;
    color:var(--text);
    border-color:var(--border);
}

.btn-secondary:hover {
    background:var(--gray-light);
}

.btn-success {
    background:var(--success);
    color:white;
}

.btn-warning {
    background:var(--warning);
    color:white;
}

.btn-danger {
    background:var(--danger);
    color:white;
}

.btn-sm {
    min-height:34px;
    padding:6px 10px;
    font-size:.875rem;
}

.actions {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    border-collapse:collapse;
    background:white;
}

th,
td {
    padding:12px 14px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th {
    background:var(--gray-light);
    font-weight:700;
    white-space:nowrap;
}

.badge {
    display:inline-flex;
    padding:4px 9px;
    border-radius:999px;
    font-size:.8rem;
    font-weight:700;
    white-space:nowrap;
}

.badge-success {
    background:#dcfce7;
    color:#166534;
}

.badge-warning {
    background:#fef3c7;
    color:#92400e;
}

.badge-danger {
    background:#fee2e2;
    color:#991b1b;
}

.badge-draft {
    background:#e0e7ff;
    color:#3730a3;
}

.muted {
    color:var(--gray);
}

.empty {
    padding:34px 20px;
    text-align:center;
    color:var(--gray);
    background:#f8fafc;
    border:1px dashed var(--border);
    border-radius:8px;
}

.alert {
    padding:12px 15px;
    border-radius:8px;
    margin-bottom:18px;
    background:#eff6ff;
    color:#1e40af;
    border:1px solid #bfdbfe;
}

.alert-error {
    background:#fef2f2;
    color:#991b1b;
    border-color:#fecaca;
}

.group-card {
    border:1px solid var(--border);
    border-radius:9px;
    overflow:hidden;
    margin-bottom:18px;
}

.group-header {
    display:flex;
    gap:10px;
    align-items:center;
    padding:14px 16px;
    background:var(--gray-light);
    border-bottom:1px solid var(--border);
}

.group-body {
    padding:16px;
}

.question-card {
    border:1px solid var(--border);
    border-radius:8px;
    padding:16px;
    margin-bottom:12px;
}

.question-card:last-child {
    margin-bottom:0;
}

.question-title {
    font-weight:700;
    margin-bottom:12px;
}

.option {
    display:flex;
    align-items:center;
    gap:8px;
    margin:8px 0;
}

.stats {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:16px;
}

.stat {
    background:white;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:18px;
}

.stat-label {
    color:var(--gray);
    font-size:.85rem;
}

.stat-value {
    font-size:1.8rem;
    font-weight:700;
}

.tabs {
    display:flex;
    gap:4px;
    border-bottom:1px solid var(--border);
    margin-bottom:20px;
}

.tabs a {
    padding:10px 14px;
    color:var(--gray);
    border-bottom:2px solid transparent;
}

.tabs a.active {
    color:var(--primary);
    border-bottom-color:var(--primary);
    font-weight:700;
}

@media(max-width:900px) {
    .grid-3 { grid-template-columns:repeat(2,1fr); }
    .stats { grid-template-columns:repeat(2,1fr); }
}

@media(max-width:720px) {
    .app-header {
        padding:12px 16px;
        flex-wrap:wrap;
    }

    .nav {
        width:100%;
        margin-left:0;
        overflow-x:auto;
    }

    .container,
    .answer-container {
        width:calc(100% - 20px);
        padding-top:18px;
    }

    .page-title {
        flex-direction:column;
    }

    .grid-2,
    .grid-3,
    .stats {
        grid-template-columns:1fr;
    }

    .card {
        padding:16px;
    }
}
</style>
</head>

<body>

<header class="app-header">
    <a class="brand"
       href="<?= e(url(['screen'=>SCREEN_LIST])) ?>">
        <?= e(APP_NAME) ?>
    </a>

    <nav class="nav">
        <a class="<?= $active === 'list' ? 'active' : '' ?>"
           href="<?= e(url(['screen'=>SCREEN_LIST])) ?>">
            アンケート
        </a>

        <a class="<?= $active === 'kintone' ? 'active' : '' ?>"
           href="<?= e(url(['screen'=>SCREEN_KINTONE])) ?>">
            kintone
        </a>

        <a class="<?= $active === 'mail' ? 'active' : '' ?>"
           href="<?= e(url(['screen'=>SCREEN_MAIL])) ?>">
            メール
        </a>
    </nav>
</header>
<?php
}

function renderFooter(): void
{
    ?>
</body>
</html>
<?php
}

function renderError(
    string $title,
    string $message
): void {
    renderHeader('エラー');

    ?>
<div class="container">
    <div class="card">
        <h1><?= e($title) ?></h1>
        <div class="alert alert-error">
            <?= nl2br(e($message)) ?>
        </div>
        <a class="btn btn-secondary"
           href="<?= e(url(['screen'=>SCREEN_LIST])) ?>">
            一覧へ戻る
        </a>
    </div>
</div>
<?php

    renderFooter();
}

/* =========================================================
 * 一覧
 * ========================================================= */

function renderList(): void
{
    $list = surveys();

    $keyword = trim(
        (string)get('q', '')
    );

    $statusFilter = (string)get(
        'status',
        'all'
    );

    $sort = (string)get(
        'sort',
        'updated_desc'
    );

    $list = array_map(
        function (array $survey): array {
            $survey['display_status'] =
                surveyStatus($survey);

            return $survey;
        },
        $list
    );

    if ($keyword !== '') {
        $list = array_values(
            array_filter(
                $list,
                fn(array $s): bool =>
                    mb_stripos(
                        (string)($s['title'] ?? ''),
                        $keyword
                    ) !== false
            )
        );
    }

    if ($statusFilter !== 'all') {
        $list = array_values(
            array_filter(
                $list,
                fn(array $s): bool =>
                    ($s['display_status'] ?? '') ===
                    $statusFilter
            )
        );
    }

    usort(
        $list,
        function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)$a['updated_at'],
                        (string)$b['updated_at']
                    ),

                'answers_desc' =>
                    answerCount(
                        (string)$b['id']
                    ) <=>
                    answerCount(
                        (string)$a['id']
                    ),

                'answers_asc' =>
                    answerCount(
                        (string)$a['id']
                    ) <=>
                    answerCount(
                        (string)$b['id']
                    ),

                'start_desc' =>
                    strcmp(
                        (string)$b['start_at'],
                        (string)$a['start_at']
                    ),

                'start_asc' =>
                    strcmp(
                        (string)$a['start_at'],
                        (string)$b['start_at']
                    ),

                default =>
                    strcmp(
                        (string)$b['updated_at'],
                        (string)$a['updated_at']
                    ),
            };
        }
    );

    renderHeader(
        'アンケート一覧',
        'list'
    );

    ?>
<div class="container">

<div class="page-title">
    <div>
        <h1>アンケート一覧</h1>
        <div class="muted">
            アンケートの作成・公開・集計・送信を行います。
        </div>
    </div>

    <a class="btn btn-primary"
       href="<?= e(url(['screen'=>SCREEN_EDIT])) ?>">
        ＋ 新規作成
    </a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
<div class="alert">
    <?= e($_SESSION['flash']) ?>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<div class="card">

<form method="get" class="grid-3">
    <input type="hidden"
           name="screen"
           value="list">

    <div>
        <label class="form-label">タイトル検索</label>
        <input type="search"
               name="q"
               value="<?= e($keyword) ?>"
               placeholder="タイトルを検索">
    </div>

    <div>
        <label class="form-label">ステータス</label>
        <select name="status">
            <?php
            $statuses = [
                'all' => 'すべて',
                STATUS_OPEN => '公開中',
                STATUS_DRAFT => '下書き',
                STATUS_STOPPED => '停止',
                STATUS_FINISHED => '終了',
            ];

            foreach ($statuses as $value => $label):
            ?>
            <option value="<?= e($value) ?>"
                <?= $statusFilter === $value
                    ? 'selected' : '' ?>>
                <?= e($label) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label class="form-label">ソート</label>
        <select name="sort">
            <option value="updated_desc"
                <?= $sort === 'updated_desc'
                    ? 'selected' : '' ?>>
                更新日：新しい順
            </option>
            <option value="updated_asc"
                <?= $sort === 'updated_asc'
                    ? 'selected' : '' ?>>
                更新日：古い順
            </option>
            <option value="answers_desc"
                <?= $sort === 'answers_desc'
                    ? 'selected' : '' ?>>
                回答数：多い順
            </option>
            <option value="answers_asc"
                <?= $sort === 'answers_asc'
                    ? 'selected' : '' ?>>
                回答数：少ない順
            </option>
            <option value="start_desc"
                <?= $sort === 'start_desc'
                    ? 'selected' : '' ?>>
                開始日：新しい順
            </option>
            <option value="start_asc"
                <?= $sort === 'start_asc'
                    ? 'selected' : '' ?>>
                開始日：古い順
            </option>
        </select>
    </div>

    <div>
        <button class="btn btn-secondary"
                type="submit">
            検索・絞り込み
        </button>
    </div>
</form>

</div>

<div class="card">
<?php if (!$list): ?>

<div class="empty">
    アンケートがありません。
</div>

<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>タイトル</th>
    <th>開始日時</th>
    <th>終了日時</th>
    <th>状態</th>
    <th>回答数</th>
    <th>操作</th>
</tr>
</thead>

<tbody>

<?php foreach ($list as $survey): ?>
<tr>

<td>
    <strong><?= e($survey['title']) ?></strong>
    <div class="muted">
        更新：
        <?= e($survey['updated_at']) ?>
    </div>
</td>

<td><?= e($survey['start_at']) ?></td>
<td><?= e($survey['end_at']) ?></td>

<td>
    <span class="badge <?= e(
        statusClass(
            $survey['display_status']
        )
    ) ?>">
        <?= e(
            statusLabel(
                $survey['display_status']
            )
        ) ?>
    </span>
</td>

<td><?= answerCount($survey['id']) ?></td>

<td>
<div class="actions">

<a class="btn btn-secondary btn-sm"
   href="<?= e(url([
       'screen'=>SCREEN_EDIT,
       'id'=>$survey['id'],
   ])) ?>">
    編集
</a>

<a class="btn btn-secondary btn-sm"
   href="<?= e(url([
       'screen'=>SCREEN_PREVIEW,
       'id'=>$survey['id'],
   ])) ?>">
    プレビュー
</a>

<a class="btn btn-secondary btn-sm"
   href="<?= e(url([
       'screen'=>SCREEN_ANALYTICS,
       'id'=>$survey['id'],
   ])) ?>">
    集計
</a>

<a class="btn btn-primary btn-sm"
   href="<?= e(url([
       'screen'=>SCREEN_SEND,
       'id'=>$survey['id'],
   ])) ?>">
    送信
</a>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('複製しますか？');">
    <input type="hidden"
           name="action"
           value="duplicate_survey">
    <input type="hidden"
           name="id"
           value="<?= e($survey['id']) ?>">
    <button class="btn btn-secondary btn-sm"
            type="submit">
        複製
    </button>
</form>

<form method="post"
      style="display:inline"
      onsubmit="return confirm('削除しますか？');">
    <input type="hidden"
           name="action"
           value="delete_survey">
    <input type="hidden"
           name="id"
           value="<?= e($survey['id']) ?>">
    <button class="btn btn-danger btn-sm"
            type="submit">
        削除
    </button>
</form>

<?php
$currentStatus =
    surveyStatus($survey);

if ($currentStatus === STATUS_DRAFT):
?>
<form method="post"
      style="display:inline">
    <input type="hidden"
           name="action"
           value="change_status">
    <input type="hidden"
           name="id"
           value="<?= e($survey['id']) ?>">
    <input type="hidden"
           name="status"
           value="<?= STATUS_OPEN ?>">
    <button class="btn btn-success btn-sm"
            type="submit">
        公開
    </button>
</form>

<?php elseif ($currentStatus === STATUS_OPEN): ?>

<form method="post"
      style="display:inline">
    <input type="hidden"
           name="action"
           value="change_status">
    <input type="hidden"
           name="id"
           value="<?= e($survey['id']) ?>">
    <input type="hidden"
           name="status"
           value="<?= STATUS_STOPPED ?>">
    <button class="btn btn-warning btn-sm"
            type="submit">
        停止
    </button>
</form>

<?php elseif ($currentStatus === STATUS_STOPPED): ?>

<form method="post"
      style="display:inline">
    <input type="hidden"
           name="action"
           value="change_status">
    <input type="hidden"
           name="id"
           value="<?= e($survey['id']) ?>">
    <input type="hidden"
           name="status"
           value="<?= STATUS_OPEN ?>">
    <button class="btn btn-success btn-sm"
            type="submit">
        再開
    </button>
</form>

<?php endif; ?>

</div>
</td>

</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>
</div>

</div>
<?php

    renderFooter();
}

/* =========================================================
 * 編集
 * ========================================================= */

function renderEdit(?string $id): void
{
    $survey = $id
        ? findSurvey($id)
        : newSurvey();

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '指定されたアンケートは存在しません。'
        );
        return;
    }

    renderHeader(
        'アンケート作成・編集',
        'list'
    );

    ?>
<div class="container">

<div class="page-title">
    <div>
        <h1>アンケート作成・編集</h1>
        <div class="muted">
            <?= e($survey['title']) ?>
        </div>
    </div>

    <div class="actions">
        <a class="btn btn-secondary"
           href="<?= e(url(['screen'=>SCREEN_LIST])) ?>">
            キャンセル
        </a>

        <?php if ($id): ?>
        <a class="btn btn-secondary"
           href="<?= e(url([
               'screen'=>SCREEN_PREVIEW,
               'id'=>$survey['id'],
           ])) ?>">
            プレビュー
        </a>
        <?php endif; ?>
    </div>
</div>

<form method="post"
      action="<?= e(url()) ?>">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="id"
       value="<?= e($survey['id']) ?>">

<div class="card">

<h2>基本設定</h2>

<div class="form-group">
    <label class="form-label">
        タイトル
    </label>
    <input type="text"
           name="title"
           required
           value="<?= e($survey['title']) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        説明
    </label>
    <textarea name="description"><?= e(
        $survey['description']
    ) ?></textarea>
</div>

<div class="grid-2">

<div class="form-group">
    <label class="form-label">
        開始日時
    </label>
    <input type="datetime-local"
           name="start_at"
           value="<?= e($survey['start_at']) ?>">
</div>

<div class="form-group">
    <label class="form-label">
        終了日時
    </label>
    <input type="datetime-local"
           name="end_at"
           value="<?= e($survey['end_at']) ?>">
</div>

</div>

<div class="form-group">
    <label class="form-label">
        質問番号
    </label>

    <label class="option">
        <input type="radio"
               name="numbering"
               value="global"
            <?= $survey['numbering'] === 'global'
                ? 'checked' : '' ?>>
        アンケート全体で通番
        （Q1、Q2、Q3...）
    </label>

    <label class="option">
        <input type="radio"
               name="numbering"
               value="group"
            <?= $survey['numbering'] === 'group'
                ? 'checked' : '' ?>>
        グループ単位
        （Q1-1、Q1-2、Q2-1...）
    </label>
</div>

<div>
    状態：
    <span class="badge <?= e(
        statusClass(
            surveyStatus($survey)
        )
    ) ?>">
        <?= e(
            statusLabel(
                surveyStatus($survey)
            )
        ) ?>
    </span>
</div>

</div>

<?php
foreach ($survey['groups'] as $gi => $group):
?>

<div class="group-card">

<div class="group-header">
    <strong>
        グループ <?= $gi + 1 ?>
    </strong>

    <input type="hidden"
           name="groups[<?= $gi ?>][id]"
           value="<?= e($group['id']) ?>">

    <input type="text"
           name="groups[<?= $gi ?>][title]"
           value="<?= e($group['title']) ?>">

    <button class="btn btn-danger btn-sm"
            type="button"
            onclick="removeGroup(this)">
        削除
    </button>
</div>

<div class="group-body">

<?php
foreach ($group['questions'] as $qi => $question):
?>

<div class="question-card">

<input type="hidden"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][id]"
       value="<?= e($question['id']) ?>">

<div class="form-group">
    <label class="form-label">
        <?= e($question['number']) ?>
    </label>

    <input type="text"
           name="groups[<?= $gi ?>][questions][<?= $qi ?>][text]"
           value="<?= e($question['text']) ?>"
           placeholder="質問文">
</div>

<div class="grid-2">

<div>
    <label class="form-label">
        回答形式
    </label>

    <select name="groups[<?= $gi ?>][questions][<?= $qi ?>][type]"
            onchange="toggleOptions(this)">

        <option value="single"
            <?= $question['type'] === 'single'
                ? 'selected' : '' ?>>
            単一選択
        </option>

        <option value="multi"
            <?= $question['type'] === 'multi'
                ? 'selected' : '' ?>>
            複数選択
        </option>

        <option value="text"
            <?= $question['type'] === 'text'
                ? 'selected' : '' ?>>
            自由記述
        </option>

    </select>
</div>

<div>
    <label class="form-label">
        必須
    </label>

    <label class="option">
        <input type="checkbox"
               name="groups[<?= $gi ?>][questions][<?= $qi ?>][required]"
               value="1"
            <?= !empty($question['required'])
                ? 'checked' : '' ?>>
        必須回答
    </label>
</div>

</div>

<div class="form-group options-box"
     style="<?= in_array(
         $question['type'],
         ['single','multi'],
         true
     ) ? '' : 'display:none' ?>">

<label class="form-label">
    選択肢
</label>

<?php
$options = $question['options'] ?: [''];
foreach ($options as $oi => $option):
?>

<div class="option">
    <input type="text"
           name="groups[<?= $gi ?>][questions][<?= $qi ?>][options][]"
           value="<?= e($option) ?>"
           placeholder="選択肢">
</div>

<?php endforeach; ?>

<div class="muted">
    選択肢は空欄を除いて保存されます。
</div>

</div>

<div class="form-group">
    <label class="form-label">
        条件分岐
    </label>

    <div class="muted">
        POCでは保存データ上の分岐設定を利用できます。
        分岐先は質問IDで指定します。
    </div>

<?php foreach (($question['branch'] ?? []) as $option => $target): ?>

<div class="grid-2"
     style="margin-top:8px">

<input type="text"
       name="groups[<?= $gi ?>][questions][<?= $qi ?>][branch][<?= e($option) ?>]"
       value="<?= e($target) ?>"
       placeholder="対象質問ID">

</div>

<?php endforeach; ?>

</div>

<button class="btn btn-danger btn-sm"
        type="button"
        onclick="removeQuestion(this)">
    質問を削除
</button>

</div>

<?php endforeach; ?>

<div style="margin-top:14px">
    <button class="btn btn-secondary"
            type="button"
            onclick="addQuestion(this)">
        ＋ 質問を追加
    </button>
</div>

</div>
</div>

<?php endforeach; ?>

<div class="card">

<div class="actions">

<button class="btn btn-primary"
        type="submit">
    保存して一覧へ
</button>

<a class="btn btn-secondary"
   href="<?= e(url(['screen'=>SCREEN_LIST])) ?>">
    キャンセル
</a>

</div>

</div>

</form>
</div>

<script>
function removeQuestion(button) {
    const card = button.closest('.question-card');
    if (card) card.remove();
}

function removeGroup(button) {
    const group = button.closest('.group-card');

    if (
        group &&
        confirm('このグループを削除しますか？')
    ) {
        group.remove();
    }
}

function toggleOptions(select) {
    const card = select.closest('.question-card');

    if (!card) return;

    const box = card.querySelector('.options-box');

    if (!box) return;

    box.style.display =
        (select.value === 'single' ||
         select.value === 'multi')
        ? ''
        : 'none';
}
</script>

<?php
    renderFooter();
}

/* =========================================================
 * プレビュー
 * ========================================================= */

function renderPreview(string $id): void
{
    $survey = findSurvey($id);

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '指定されたアンケートは存在しません。'
        );
        return;
    }

    renderHeader('プレビュー');

    ?>
<div class="answer-container">

<div class="page-title">
<div>
    <h1><?= e($survey['title']) ?></h1>
    <div class="muted">プレビュー</div>
</div>

<a class="btn btn-secondary"
   href="<?= e(url([
       'screen'=>SCREEN_EDIT,
       'id'=>$id,
   ])) ?>">
    編集へ戻る
</a>
</div>

<div class="answer-card">
    <?= nl2br(e($survey['description'])) ?>
</div>

<?php foreach ($survey['groups'] as $group): ?>

<div class="answer-card">

<h2><?= e($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<div class="question-card">

<div class="question-title">
    <?= e($question['number']) ?>.
    <?= e($question['text']) ?>

    <?php if (!empty($question['required'])): ?>
        <span class="badge badge-danger">
            必須
        </span>
    <?php endif; ?>
</div>

<?php
if ($question['type'] === ANSWER_TEXT):
?>

<textarea disabled
          placeholder="回答入力欄"></textarea>

<?php else: ?>

<?php foreach ($question['options'] as $option): ?>

<label class="option">
    <input
        type="<?= $question['type'] === ANSWER_MULTI
            ? 'checkbox'
            : 'radio' ?>">
    <?= e($option) ?>
</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>
<?php

    renderFooter();
}

/* =========================================================
 * 回答
 * ========================================================= */

function renderAnswer(string $id): void
{
    $survey = findSurvey($id);

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '指定されたアンケートは存在しません。'
        );
        return;
    }

    if (surveyStatus($survey) !== STATUS_OPEN) {
        renderError(
            '回答できません。',
            'このアンケートは現在回答を受け付けていません。'
        );
        return;
    }

    renderHeader('アンケート回答');

    ?>
<div class="answer-container">

<div class="answer-card">
    <h1><?= e($survey['title']) ?></h1>

    <?php if ($survey['description'] !== ''): ?>
    <p>
        <?= nl2br(e($survey['description'])) ?>
    </p>
    <?php endif; ?>
</div>

<form method="post">

<input type="hidden"
       name="action"
       value="submit_answer">

<input type="hidden"
       name="survey_id"
       value="<?= e($id) ?>">

<?php foreach ($survey['groups'] as $group): ?>

<div class="answer-card">

<h2><?= e($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<div class="question-card">

<div class="question-title">
    <?= e($question['number']) ?>.
    <?= e($question['text']) ?>

    <?php if (!empty($question['required'])): ?>
    <span class="badge badge-danger">
        必須
    </span>
    <?php endif; ?>
</div>

<?php if ($question['type'] === ANSWER_TEXT): ?>

<textarea
    name="answer[<?= e($question['id']) ?>]"
    <?= !empty($question['required'])
        ? 'required' : '' ?>></textarea>

<?php else: ?>

<?php foreach ($question['options'] as $option): ?>

<label class="option">

<input
    type="<?= $question['type'] === ANSWER_MULTI
        ? 'checkbox'
        : 'radio' ?>"
    name="answer[<?= e($question['id']) ?>]<?= $question['type'] === ANSWER_MULTI ? '[]' : '' ?>"
    value="<?= e($option) ?>"
    <?= !empty($question['required'])
        ? 'required' : '' ?>>

<?= e($option) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="answer-card">
    <button class="btn btn-primary"
            type="submit">
        回答を確認する
    </button>
</div>

</form>

</div>
<?php

    renderFooter();
}

/* =========================================================
 * 集計
 * ========================================================= */

function answerCount(string $surveyId): int
{
    return count(
        array_filter(
            answers(),
            fn(array $answer): bool =>
                ($answer['survey_id'] ?? '') ===
                $surveyId
        )
    );
}

function renderAnalytics(string $id): void
{
    $survey = findSurvey($id);

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '指定されたアンケートは存在しません。'
        );
        return;
    }

    $surveyAnswers = array_values(
        array_filter(
            answers(),
            fn(array $a): bool =>
                ($a['survey_id'] ?? '') === $id
        )
    );

    $sent = array_values(
        array_filter(
            mailLogs(),
            fn(array $log): bool =>
                ($log['survey_id'] ?? '') === $id
        )
    );

    $sentCount = count($sent);
    $answerTotal = count($surveyAnswers);
    $rate = $sentCount > 0
        ? round(
            $answerTotal / $sentCount * 100,
            1
        )
        : 0;

    renderHeader('回答集計・分析');

    ?>
<div class="container">

<div class="page-title">
<div>
    <h1>回答集計・分析</h1>
    <div class="muted">
        <?= e($survey['title']) ?>
    </div>
</div>

<div class="actions">
    <a class="btn btn-secondary"
       href="<?= e(url([
           'screen'=>SCREEN_LIST,
       ])) ?>">
        一覧へ戻る
    </a>

    <a class="btn btn-secondary"
       href="<?= e(url([
           'screen'=>SCREEN_ANALYTICS,
           'id'=>$id,
           'format'=>'csv',
       ])) ?>">
        CSV
    </a>

    <a class="btn btn-secondary"
       href="<?= e(url([
           'screen'=>SCREEN_ANALYTICS,
           'id'=>$id,
           'format'=>'pdf',
       ])) ?>">
        PDF
    </a>
</div>
</div>

<div class="stats">

<div class="stat">
    <div class="stat-label">送信対象</div>
    <div class="stat-value">
        <?= $sentCount ?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">回答数</div>
    <div class="stat-value">
        <?= $answerTotal ?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">未回答</div>
    <div class="stat-value">
        <?= max(0, $sentCount - $answerTotal) ?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">回答率</div>
    <div class="stat-value">
        <?= e($rate) ?>%
    </div>
</div>

</div>

<?php if (!$surveyAnswers): ?>

<div class="card">
    <div class="empty">
        現在、回答データはありません
    </div>
</div>

<?php else: ?>

<?php foreach ($survey['groups'] as $group): ?>

<div class="card">
<h2><?= e($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<?php
$counts = [];

foreach ($surveyAnswers as $answer) {
    $value =
        $answer['answers'][
            $question['id']
        ] ?? null;

    if (is_array($value)) {
        foreach ($value as $v) {
            $counts[(string)$v] =
                ($counts[(string)$v] ?? 0) + 1;
        }
    } elseif ($value !== null && $value !== '') {
        $counts[(string)$value] =
            ($counts[(string)$value] ?? 0) + 1;
    }
}
?>

<div class="question-card">

<div class="question-title">
    <?= e($question['number']) ?>.
    <?= e($question['text']) ?>
</div>

<?php if ($counts): ?>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>回答</th>
    <th>件数</th>
</tr>
</thead>
<tbody>

<?php foreach ($counts as $value => $count): ?>
<tr>
    <td><?= e($value) ?></td>
    <td><?= $count ?></td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<?php else: ?>

<div class="muted">
    回答なし
</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="card">

<h2>個別回答</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>回答日時</th>
    <th>回答内容</th>
</tr>
</thead>

<tbody>

<?php foreach ($surveyAnswers as $answer): ?>

<tr>
<td><?= e($answer['created_at']) ?></td>
<td>
<?php
foreach (
    ($answer['answers'] ?? []) as $qid => $value
):
?>
<div>
<strong><?= e($qid) ?>:</strong>
<?= e(
    is_array($value)
        ? implode(', ', $value)
        : $value
) ?>
</div>
<?php endforeach; ?>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

</div>

<?php endif; ?>

</div>
<?php

    renderFooter();
}

/* =========================================================
 * CSV
 * ========================================================= */

function outputCsv(
    array $survey,
    array $surveyAnswers
): never {
    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="survey.csv"'
    );

    $fp = fopen('php://output', 'wb');

    fwrite($fp, "\xEF\xBB\xBF");

    fputcsv(
        $fp,
        ['回答日時', '回答ID', '回答内容']
    );

    foreach ($surveyAnswers as $answer) {
        $values = [];

        foreach (
            ($answer['answers'] ?? []) as $qid => $value
        ) {
            $values[] =
                $qid . '=' .
                (
                    is_array($value)
                    ? implode(',', $value)
                    : $value
                );
        }

        fputcsv(
            $fp,
            [
                $answer['created_at'] ?? '',
                $answer['id'] ?? '',
                implode(' / ', $values),
            ]
        );
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * 簡易PDF
 *
 * 外部ライブラリを前提としないPOC用。
 * 日本語フォントを埋め込まないため、
 * 日本語部分は簡易的にASCII化して出力する。
 * 本番ではPDFライブラリを採用する。
 * ========================================================= */

function outputPdf(
    array $survey,
    array $surveyAnswers
): never {
    $lines = [];

    $lines[] =
        'Survey: ' . asciiPdfText(
            (string)$survey['title']
        );

    $lines[] =
        'Answers: ' . count($surveyAnswers);

    foreach ($surveyAnswers as $answer) {
        $lines[] =
            $answer['created_at'] ?? '';

        foreach (
            ($answer['answers'] ?? []) as $qid => $value
        ) {
            $lines[] =
                $qid . ': ' .
                (
                    is_array($value)
                    ? implode(', ', $value)
                    : (string)$value
                );
        }
    }

    $pdf = simplePdf($lines);

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="survey.pdf"'
    );

    echo $pdf;
    exit;
}

function asciiPdfText(string $value): string
{
    $value = preg_replace(
        '/[^\x20-\x7E]/',
        '?',
        $value
    );

    return $value ?? '';
}

function pdfEscape(string $value): string
{
    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $value
    );
}

function simplePdf(array $lines): string
{
    $stream = "BT\n";
    $stream .= "/F1 10 Tf\n";
    $stream .= "50 780 Td\n";

    foreach ($lines as $index => $line) {
        if ($index > 0) {
            $stream .= "0 -16 Td\n";
        }

        $stream .= '(' .
            pdfEscape(
                asciiPdfText((string)$line)
            ) .
            ") Tj\n";
    }

    $stream .= "ET\n";

    $objects = [];

    $objects[] =
        '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[] =
        '<< /Type /Page /Parent 2 0 R ' .
        '/MediaBox [0 0 595 842] ' .
        '/Resources << /Font << /F1 4 0 R >> >> ' .
        '/Contents 5 0 R >>';

    $objects[] =
        '<< /Type /Font /Subtype /Type1 ' .
        '/BaseFont /Helvetica >>';

    $objects[] =
        '<< /Length ' .
        strlen($stream) .
        ' >>' .
        "\nstream\n" .
        $stream .
        "endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $object) {
        $offsets[$i + 1] = strlen($pdf);

        $pdf .= ($i + 1) .
            " 0 obj\n" .
            $object .
            "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .= "xref\n";
    $pdf .= "0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .= "trailer\n";
    $pdf .= "<< /Size " .
        (count($objects) + 1) .
        " /Root 1 0 R >>\n";
    $pdf .= "startxref\n";
    $pdf .= $xref . "\n";
    $pdf .= "%%EOF";

    return $pdf;
}

/* =========================================================
 * 送信
 * ========================================================= */

function renderSend(string $id): void
{
    $survey = findSurvey($id);

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '指定されたアンケートは存在しません。'
        );
        return;
    }

    $customerList = customers();

    $logs = array_values(
        array_filter(
            mailLogs(),
            fn(array $log): bool =>
                ($log['survey_id'] ?? '') === $id
        )
    );

    renderHeader('顧客選択・メール送信');

    ?>
<div class="container">

<div class="page-title">
<div>
    <h1>顧客選択・メール送信</h1>
    <div class="muted">
        <?= e($survey['title']) ?>
    </div>
</div>

<a class="btn btn-secondary"
   href="<?= e(url([
       'screen'=>SCREEN_LIST,
   ])) ?>">
    一覧へ戻る
</a>
</div>

<div class="card">

<h2>顧客</h2>

<?php if (!$customerList): ?>

<div class="empty">
    顧客データがありません。
    kintone設定画面から同期してください。
</div>

<?php else: ?>

<form method="post">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= e($id) ?>">

<div class="table-wrap">

<table>
<thead>
<tr>
    <th>選択</th>
    <th>組織名</th>
    <th>氏名</th>
    <th>メールアドレス</th>
</tr>
</thead>

<tbody>

<?php foreach ($customerList as $customer): ?>

<tr>

<td>
<input type="checkbox"
       name="customer_ids[]"
       value="<?= e($customer['id']) ?>">
</td>

<td><?= e(
    $customer['organization'] ?? ''
) ?></td>

<td><?= e(
    $customer['name'] ?? ''
) ?></td>

<td><?= e(
    $customer['email'] ?? ''
) ?></td>

</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

<div class="form-group"
     style="margin-top:20px">

<label class="form-label">
    件名
</label>

<input type="text"
       name="subject"
       value="<?= e(
           $survey['title'] .
           ' のご案内'
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
    本文
</label>

<textarea name="body">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>

</div>

<button class="btn btn-primary"
        type="submit">
    選択した顧客へ送信
</button>

</form>

<?php endif; ?>

</div>

<div class="card">

<h2>送信履歴</h2>

<?php if (!$logs): ?>

<div class="empty">
    送信履歴はありません。
</div>

<?php else: ?>

<div class="table-wrap">

<table>
<thead>
<tr>
    <th>送信日時</th>
    <th>顧客</th>
    <th>宛先</th>
    <th>結果</th>
    <th>エラー</th>
</tr>
</thead>

<tbody>

<?php foreach ($logs as $log): ?>

<tr>
<td><?= e($log['created_at'] ?? '') ?></td>
<td><?= e($log['customer_name'] ?? '') ?></td>
<td><?= e($log['email'] ?? '') ?></td>

<td>
<?php if (!empty($log['success'])): ?>
<span class="badge badge-success">
    送信済み
</span>
<?php else: ?>
<span class="badge badge-danger">
    失敗
</span>
<?php endif; ?>
</td>

<td><?= e($log['error'] ?? '') ?></td>

</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

<?php endif; ?>

</div>

</div>
<?php

    renderFooter();
}

/* =========================================================
 * kintone設定
 * ========================================================= */

function renderKintone(): void
{
    $config = kintoneConfig();

    $mapping = $config['mapping'] ?? [];

    renderHeader(
        'kintone連携設定',
        'kintone'
    );

    ?>
<div class="container">

<div class="page-title">
<div>
    <h1>kintone連携設定</h1>
    <div class="muted">
        実際のkintoneへ接続します。
    </div>
</div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
<div class="alert">
    <?= e($_SESSION['flash']) ?>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<div class="card">

<form method="post">

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid-2">

<div class="form-group">
<label class="form-label">
    サブドメイン
</label>
<input type="text"
       name="subdomain"
       value="<?= e(
           $config['subdomain'] ?? ''
       ) ?>"
       placeholder="example">
</div>

<div class="form-group">
<label class="form-label">
    顧客管理アプリID
</label>
<input type="text"
       name="app_id"
       value="<?= e(
           $config['app_id'] ?? ''
       ) ?>">
</div>

<div class="form-group">
<label class="form-label">
    ログイン名
</label>
<input type="text"
       name="login"
       value="<?= e(
           $config['login'] ?? ''
       ) ?>">
</div>

<div class="form-group">
<label class="form-label">
    パスワード
</label>
<input type="password"
       name="password"
       value="<?= e(
           $config['password'] ?? ''
       ) ?>">
</div>

<div class="form-group">
<label class="form-label">
    Proxy
</label>
<input type="text"
       name="proxy"
       value="<?= e(
           $config['proxy'] ?? ''
       ) ?>"
       placeholder="必要な場合のみ">
</div>

<div class="form-group">
<label class="form-label">
    SSL証明書検証
</label>
<label class="option">
<input type="checkbox"
       name="verify_ssl"
       value="1"
    <?= !empty($config['verify_ssl'])
        ? 'checked' : '' ?>>
有効
</label>
</div>

</div>

<h2>顧客項目マッピング</h2>

<div class="grid-2">

<?php
$mapLabels = [
    'id' => '顧客ID',
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
    'address' => '住所',
];

foreach ($mapLabels as $key => $label):
?>

<div class="form-group">
<label class="form-label">
    <?= e($label) ?>
</label>

<input type="text"
       name="mapping_<?= e($key) ?>"
       value="<?= e(
           $mapping[$key] ?? ''
       ) ?>"
       placeholder="kintoneフィールドコード">
</div>

<?php endforeach; ?>

</div>

<div class="actions">

<button class="btn btn-primary"
        type="submit">
    設定保存
</button>

</div>

</form>

<hr>

<form method="post"
      style="display:inline">

<input type="hidden"
       name="action"
       value="test_kintone">

<button class="btn btn-secondary"
        type="submit">
    接続テスト
</button>

</form>

<form method="post"
      style="display:inline">

<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="btn btn-secondary"
        type="submit">
    顧客情報を同期
</button>

</form>

</div>

</div>
<?php

    renderFooter();
}

/* =========================================================
 * SMTP設定
 * ========================================================= */

function renderMail(): void
{
    $config = smtpConfig();

    renderHeader(
        'メールサーバ設定',
        'mail'
    );

    ?>
<div class="container">

<div class="page-title">
<div>
    <h1>メールサーバ設定</h1>
    <div class="muted">
        実際のSMTPサーバへ接続します。
    </div>
</div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
<div class="alert">
    <?= e($_SESSION['flash']) ?>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<div class="card">

<form method="post">

<input type="hidden"
       name="action"
       value="save_smtp">

<div class="grid-2">

<div class="form-group">
<label class="form-label">
    SMTPサーバ
</label>
<input type="text"
       name="host"
       value="<?= e(
           $config['host'] ?? ''
       ) ?>">
</div>

<div class="form-group">
<label class="form-label">
    SMTPポート
</label>
<input type="number"
       name="port"
       value="<?= e(
           $config['port'] ?? 587
       ) ?>">
</div>

<div class="form-group">
<label class="form-label">
    暗号化方式
</label>
<select name="encryption">
    <?php
    $encryption =
        $config['encryption'] ?? 'tls';
    ?>
    <option value="none"
        <?= $encryption === 'none'
            ? 'selected' : '' ?>>
        なし
    </option>

    <option value="tls"
        <?= $encryption === 'tls'
            ? 'selected' : '' ?>>
        STARTTLS
    </option>

    <option value="ssl"
        <?= $encryption === 'ssl'
            ? 'selected' : '' ?>>
        SSL/TLS
    </option>
</select>
</div>

<div class="form-group">
<label class="form-label">
    SMTPユーザー名
</label>
<input type="text"
       name="username"
       value="<?= e(
           $config['username'] ?? ''
       ) ?>">
</div>

<div class="form-group">
<label class="form-label">
    SMTPパスワード
</label>
<input type="password"
       name="password"
       value="<?= e(
           $config['password'] ?? ''
       ) ?>">
</div>

<div class="form-group">
<label class="form-label">
    送信元メールアドレス
</label>
<input type="email"
       name="from"
       value="<?= e(
           $config['from'] ?? ''
       ) ?>">
</div>

<div class="form-group">
<label class="form-label">
    送信元名
</label>
<input type="text"
       name="from_name"
       value="<?= e(
           $config['from_name'] ?? ''
       ) ?>">
</div>

<div class="form-group">
<label class="form-label">
    返信先
</label>
<input type="email"
       name="reply_to"
       value="<?= e(
           $config['reply_to'] ?? ''
       ) ?>">
</div>

</div>

<button class="btn btn-primary"
        type="submit">
    設定保存
</button>

</form>

<hr>

<form method="post">

<input type="hidden"
       name="action"
       value="test_smtp">

<div class="grid-2">

<div>
<label class="form-label">
    テスト送信先
</label>

<input type="email"
       name="test_to"
       required>
</div>

<div style="display:flex;align-items:end">

<button class="btn btn-secondary"
        type="submit">
    テストメール送信
</button>

</div>

</div>

</form>

</div>

</div>
<?php

    renderFooter();
}

/* =========================================================
 * 完了
 * ========================================================= */

function renderComplete(): void
{
    renderHeader('回答完了');

    ?>
<div class="answer-container">

<div class="answer-card"
     style="text-align:center">

<h1>回答ありがとうございました</h1>

<p>
回答を受け付けました。
</p>

</div>

</div>
<?php

    renderFooter();
}

/* =========================================================
 * ディスパッチ
 * ========================================================= */

function dispatchScreen(): void
{
    $screen = (string)get(
        'screen',
        SCREEN_LIST
    );

    if (
        $screen === SCREEN_ANALYTICS &&
        get('format') === 'csv'
    ) {
        $id = (string)get('id', '');
        $survey = findSurvey($id);

        if ($survey === null) {
            throw new RuntimeException(
                'アンケートが見つかりません。'
            );
        }

        $surveyAnswers = array_values(
            array_filter(
                answers(),
                fn(array $a): bool =>
                    ($a['survey_id'] ?? '') === $id
            )
        );

        outputCsv(
            $survey,
            $surveyAnswers
        );
    }

    if (
        $screen === SCREEN_ANALYTICS &&
        get('format') === 'pdf'
    ) {
        $id = (string)get('id', '');
        $survey = findSurvey($id);

        if ($survey === null) {
            throw new RuntimeException(
                'アンケートが見つかりません。'
            );
        }

        $surveyAnswers = array_values(
            array_filter(
                answers(),
                fn(array $a): bool =>
                    ($a['survey_id'] ?? '') === $id
            )
        );

        outputPdf(
            $survey,
            $surveyAnswers
        );
    }

    switch ($screen) {
        case SCREEN_LIST:
            renderList();
            break;

        case SCREEN_EDIT:
            renderEdit(
                get('id')
                    ? (string)get('id')
                    : null
            );
            break;

        case SCREEN_PREVIEW:
            renderPreview(
                (string)get('id', '')
            );
            break;

        case SCREEN_SEND:
            renderSend(
                (string)get('id', '')
            );
            break;

        case SCREEN_ANALYTICS:
            renderAnalytics(
                (string)get('id', '')
            );
            break;

        case SCREEN_KINTONE:
            renderKintone();
            break;

        case SCREEN_MAIL:
            renderMail();
            break;

        case SCREEN_ANSWER:
            renderAnswer(
                (string)get('id', '')
            );
            break;

        case SCREEN_COMPLETE:
            renderComplete();
            break;

        default:
            redirectTo([
                'screen' => SCREEN_LIST,
            ]);
    }
}

/* =========================================================
 * メイン
 * ========================================================= */

try {

    ensureDataDir();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handlePost();
    }

    dispatchScreen();

} catch (Throwable $e) {

    http_response_code(500);

    renderError(
        '処理に失敗しました。',
        $e->getMessage()
    );
}
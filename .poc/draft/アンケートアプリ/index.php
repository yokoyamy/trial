<?php
declare(strict_types=1);

/*
 * アンケート管理システム
 *
 * PHP 8.5 / Apache 2.4
 *
 * index.php だけで動作。
 *
 * データ:
 *   data/surveys.json
 *   data/customers.json
 *   data/responses.json
 *   data/send_history.json
 *   data/kintone.json
 *   data/mail.json
 *
 * 管理者認証なし
 * 回答者認証なし
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const DATA_FILES = [
    'surveys'     => DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json',
    'customers'   => DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json',
    'responses'   => DATA_DIR . DIRECTORY_SEPARATOR . 'responses.json',
    'history'     => DATA_DIR . DIRECTORY_SEPARATOR . 'send_history.json',
    'kintone'     => DATA_DIR . DIRECTORY_SEPARATOR . 'kintone.json',
    'mail'        => DATA_DIR . DIRECTORY_SEPARATOR . 'mail.json',
];

const STATUS_DRAFT     = 'draft';
const STATUS_PUBLISHED = 'published';
const STATUS_STOPPED   = 'stopped';
const STATUS_FINISHED  = 'finished';

const QUESTION_SINGLE   = 'single';
const QUESTION_MULTIPLE = 'multiple';
const QUESTION_TEXT     = 'text';


/* ============================================================
 * 基本
 * ========================================================== */

function h(mixed $v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function isoNow(): string
{
    return date('c');
}

function uid(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(12));
}

function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function fail(string $message, int $status = 400, array $extra = []): never
{
    jsonOut(
        array_merge([
            'success' => false,
            'error'   => $message,
        ], $extra),
        $status
    );
}

function requestJson(): array
{
    $raw = file_get_contents('php://input');

    if ($raw !== false && trim($raw) !== '') {
        $data = json_decode($raw, true);

        if (is_array($data)) {
            return $data;
        }
    }

    return $_POST;
}

function ensureDataDir(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (!@mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        fail(
            'dataディレクトリを作成できません。',
            500,
            [
                'detail' => DATA_DIR .
                    ' に書き込み権限があるか確認してください。'
            ]
        );
    }
}

function readData(string $name, mixed $default = []): mixed
{
    if (!isset(DATA_FILES[$name])) {
        return $default;
    }

    $file = DATA_FILES[$name];

    if (!file_exists($file)) {
        return $default;
    }

    $fp = @fopen($file, 'rb');

    if (!$fp) {
        fail(
            'JSONファイルを開けません。',
            500,
            ['file' => basename($file)]
        );
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            fail(
                'JSONファイルのロックを取得できません。',
                500,
                ['file' => basename($file)]
            );
        }

        $raw = stream_get_contents($fp);

        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        fail(
            'JSONファイルが壊れています。',
            500,
            [
                'file' => basename($file),
                'detail' => json_last_error_msg()
            ]
        );
    }

    return $data;
}

function writeData(string $name, mixed $data): void
{
    if (!isset(DATA_FILES[$name])) {
        fail('不正なデータ領域です。', 500);
    }

    ensureDataDir();

    $file = DATA_FILES[$name];

    $fp = @fopen($file, 'c+b');

    if (!$fp) {
        fail(
            'JSONファイルへ書き込めません。',
            500,
            [
                'file' => basename($file),
                'detail' =>
                    'Apache/PHP実行ユーザーにdataディレクトリへの書き込み権限が必要です。'
            ]
        );
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            fail(
                'JSONファイルの排他ロックを取得できません。',
                500,
                ['file' => basename($file)]
            );
        }

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

        if ($json === false) {
            flock($fp, LOCK_UN);

            fail(
                'JSONへの変換に失敗しました。',
                500,
                ['detail' => json_last_error_msg()]
            );
        }

        ftruncate($fp, 0);
        rewind($fp);

        $written = fwrite($fp, $json);

        if ($written === false || $written < strlen($json)) {
            flock($fp, LOCK_UN);

            fail(
                'JSONファイルへの書き込みに失敗しました。',
                500,
                ['file' => basename($file)]
            );
        }

        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}


/* ============================================================
 * 初期データ
 * ========================================================== */

function defaultKintone(): array
{
    return [
        'subdomain' => '',
        'appId' => '',
        'loginName' => '',
        'password' => '',
        'sslVerify' => false,
        'proxy' => '',
        'fields' => [],
        'mapping' => [
            'organization' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => [],
        ],
        'updatedAt' => null,
    ];
}

function defaultMail(): array
{
    return [
        'smtpServer' => '',
        'smtpPort' => 587,
        'encryption' => 'starttls',
        'authentication' => true,
        'username' => '',
        'password' => '',
        'fromEmail' => '',
        'fromName' => '',
        'replyTo' => '',
        'connectionStatus' => '未設定',
        'lastTestAt' => null,
        'lastError' => '',
        'updatedAt' => null,
    ];
}

function makeQuestion(
    string $text,
    string $type = QUESTION_SINGLE,
    bool $required = false
): array {
    $choices = [];

    if ($type !== QUESTION_TEXT) {
        foreach (['はい', 'いいえ'] as $label) {
            $choices[] = [
                'id' => uid('choice-'),
                'label' => $label,
                'sortOrder' => count($choices) + 1,
            ];
        }
    }

    return [
        'id' => uid('question-'),
        'groupId' => '',
        'sortOrder' => 1,
        'questionNumber' => '',
        'text' => $text,
        'type' => $type,
        'required' => $required,
        'choices' => $choices,
        'branches' => [],
    ];
}

function makeSurvey(
    string $title,
    string $status,
    ?string $start,
    ?string $end
): array {
    $g1 = [
        'id' => uid('group-'),
        'title' => '基本情報',
        'sortOrder' => 1,
        'questions' => [],
    ];

    $q1 = makeQuestion(
        '今回のサービスについて総合的に評価してください。',
        QUESTION_SINGLE,
        true
    );

    $q2 = makeQuestion(
        'ご意見・ご要望があれば入力してください。',
        QUESTION_TEXT,
        false
    );

    $q1['groupId'] = $g1['id'];
    $q2['groupId'] = $g1['id'];

    $q1['sortOrder'] = 1;
    $q2['sortOrder'] = 2;

    $g1['questions'] = [$q1, $q2];

    $g2 = [
        'id' => uid('group-'),
        'title' => '追加確認',
        'sortOrder' => 2,
        'questions' => [],
    ];

    $q3 = makeQuestion(
        '今後もサービスを利用したいと思いますか？',
        QUESTION_SINGLE,
        true
    );

    $q3['groupId'] = $g2['id'];

    $g2['questions'][] = $q3;

    $survey = [
        'id' => uid('survey-'),
        'title' => $title,
        'description' => '動作確認用のサンプルアンケートです。',
        'startDate' => $start,
        'endDate' => $end,
        'questionNumberMode' => 'all',
        'allowResubmission' => false,
        'status' => $status,
        'groups' => [$g1, $g2],
        'createdAt' => isoNow(),
        'updatedAt' => isoNow(),
    ];

    recalcNumbers($survey);

    return $survey;
}

function initializeData(): void
{
    ensureDataDir();

    if (!file_exists(DATA_FILES['surveys'])) {
        $past = date('c', strtotime('-1 day'));

        $surveys = [
            makeSurvey(
                'サンプルアンケート（下書き）',
                STATUS_DRAFT,
                date('c'),
                date('c', strtotime('+30 days'))
            ),

            makeSurvey(
                'サンプルアンケート（公開中）',
                STATUS_PUBLISHED,
                date('c', strtotime('-2 days')),
                date('c', strtotime('+30 days'))
            ),

            makeSurvey(
                'サンプルアンケート（停止）',
                STATUS_STOPPED,
                date('c', strtotime('-5 days')),
                date('c', strtotime('+30 days'))
            ),

            makeSurvey(
                'サンプルアンケート（終了）',
                STATUS_FINISHED,
                date('c', strtotime('-30 days')),
                $past
            ),

            makeSurvey(
                '期限経過サンプル（下書き）',
                STATUS_DRAFT,
                date('c', strtotime('-5 days')),
                $past
            ),

            makeSurvey(
                '期限経過サンプル（停止）',
                STATUS_STOPPED,
                date('c', strtotime('-5 days')),
                $past
            ),

            makeSurvey(
                '期限経過サンプル（公開中→終了）',
                STATUS_PUBLISHED,
                date('c', strtotime('-5 days')),
                $past
            ),
        ];

        writeData('surveys', $surveys);
    }

    if (!file_exists(DATA_FILES['customers'])) {
        writeData('customers', [
            [
                'id' => 'customer-001',
                'organization' => '株式会社サンプル',
                'name' => '山田 太郎',
                'email' => 'sample@example.com',
                'department' => '営業部',
                'phone' => '03-0000-0001',
                'address' => '東京都港区赤坂1-1-1',
                'status' => '未送信',
                'lastSentAt' => null,
                'sendCount' => 0,
                'kintoneStatus' => '登録済み',
            ],
            [
                'id' => 'customer-002',
                'organization' => '株式会社テスト',
                'name' => '佐藤 花子',
                'email' => 'test@example.com',
                'department' => '企画部',
                'phone' => '03-0000-0002',
                'address' => '東京都千代田区丸の内1-1-1',
                'status' => '送信済み / 未回答',
                'lastSentAt' => date('c', strtotime('-3 days')),
                'sendCount' => 1,
                'kintoneStatus' => '登録済み',
            ],
            [
                'id' => 'customer-003',
                'organization' => '合同会社デモ',
                'name' => '鈴木 一郎',
                'email' => 'demo@example.com',
                'department' => '管理部',
                'phone' => '03-0000-0003',
                'address' => '東京都新宿区西新宿2-2-2',
                'status' => '回答済み',
                'lastSentAt' => date('c', strtotime('-10 days')),
                'sendCount' => 1,
                'kintoneStatus' => '登録済み',
            ],
        ]);
    }

    if (!file_exists(DATA_FILES['responses'])) {
        writeData('responses', []);
    }

    if (!file_exists(DATA_FILES['history'])) {
        writeData('history', []);
    }

    if (!file_exists(DATA_FILES['kintone'])) {
        writeData('kintone', defaultKintone());
    }

    if (!file_exists(DATA_FILES['mail'])) {
        writeData('mail', defaultMail());
    }
}


/* ============================================================
 * アンケート状態
 * ========================================================== */

function recalcNumbers(array &$survey): void
{
    $mode = ($survey['questionNumberMode'] ?? 'all') === 'group'
        ? 'group'
        : 'all';

    $global = 0;
    $gi = 0;

    foreach ($survey['groups'] as &$group) {
        $gi++;

        $qi = 0;

        foreach ($group['questions'] as &$question) {
            $qi++;
            $global++;

            $question['groupId'] = $group['id'];
            $question['sortOrder'] = $qi;

            if ($mode === 'group') {
                $question['questionNumber'] =
                    'Q' . $gi . '-' . $qi;
            } else {
                $question['questionNumber'] =
                    'Q' . $global;
            }
        }

        unset($question);

        $group['sortOrder'] = $gi;
    }

    unset($group);
}

function autoFinish(array &$surveys): bool
{
    $changed = false;
    $now = time();

    foreach ($surveys as &$survey) {
        if (($survey['status'] ?? '') !== STATUS_PUBLISHED) {
            continue;
        }

        $end = $survey['endDate'] ?? null;

        if (!$end) {
            continue;
        }

        $timestamp = strtotime((string)$end);

        if ($timestamp !== false && $now > $timestamp) {
            $survey['status'] = STATUS_FINISHED;
            $survey['updatedAt'] = isoNow();
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function surveyById(array $surveys, string $id): ?array
{
    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function surveyIndex(array $surveys, string $id): int
{
    foreach ($surveys as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function questionById(array $survey, string $id): ?array
{
    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            if (($question['id'] ?? '') === $id) {
                return $question;
            }
        }
    }

    return null;
}


/* ============================================================
 * kintone
 * ========================================================== */

function normalizeSubdomain(string $value): ?string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = preg_replace(
        '#/.*$#',
        '',
        $value
    );

    if (preg_match(
        '/^([a-zA-Z0-9][a-zA-Z0-9-]*)\.cybozu\.com$/',
        $value,
        $m
    )) {
        return strtolower($m[1]);
    }

    if (preg_match(
        '/^([a-zA-Z0-9][a-zA-Z0-9-]*)$/',
        $value,
        $m
    )) {
        return strtolower($m[1]);
    }

    return null;
}

function normalizeProxy(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (!preg_match(
        '/^[^:\s\/]+:[0-9]{1,5}$/',
        $value
    )) {
        return null;
    }

    [$host, $port] = explode(':', $value, 2);

    if ((int)$port < 1 || (int)$port > 65535) {
        return null;
    }

    return $host . ':' . (int)$port;
}

function kintoneRequest(
    array $settings,
    string $method,
    string $path,
    ?array $body = null
): array {
    $subdomain = normalizeSubdomain(
        (string)($settings['subdomain'] ?? '')
    );

    if ($subdomain === null) {
        return [
            'success' => false,
            'error' => 'kintoneサブドメインが正しく設定されていません。',
        ];
    }

    $appId = trim((string)($settings['appId'] ?? ''));

    if ($appId === '') {
        return [
            'success' => false,
            'error' => '顧客管理アプリIDが設定されていません。',
        ];
    }

    $url =
        'https://' .
        $subdomain .
        '.cybozu.com' .
        $path;

    $login = (string)($settings['loginName'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        return [
            'success' => false,
            'error' => 'kintoneログイン名・パスワードが設定されていません。',
        ];
    }

    $ch = curl_init($url);

    if (!$ch) {
        return [
            'success' => false,
            'error' => 'cURLを初期化できません。',
        ];
    }

    $verify = (bool)($settings['sslVerify'] ?? false);

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password),
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ];

    $proxy = normalizeProxy(
        (string)($settings['proxy'] ?? '')
    );

    if ($proxy !== null) {
        $options[CURLOPT_PROXY] = $proxy;
    }

    if ($body !== null) {
        $encoded = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $options[CURLOPT_POSTFIELDS] = $encoded;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($response === false || $errno !== 0) {
        return [
            'success' => false,
            'error' => 'kintone API通信に失敗しました。',
            'detail' => $error,
            'curlError' => $errno,
            'httpStatus' => $http,
        ];
    }

    $decoded = json_decode($response, true);

    if ($http < 200 || $http >= 300) {
        return [
            'success' => false,
            'error' => 'kintone APIがエラーを返しました。',
            'detail' => is_array($decoded)
                ? ($decoded['message'] ?? $response)
                : $response,
            'httpStatus' => $http,
        ];
    }

    return [
        'success' => true,
        'data' => is_array($decoded)
            ? $decoded
            : [],
        'httpStatus' => $http,
    ];
}


/* ============================================================
 * SMTP
 * ========================================================== */

function smtpRead($socket): string
{
    $result = '';

    while (($line = fgets($socket, 515)) !== false) {
        $result .= $line;

        if (
            strlen($line) >= 4 &&
            $line[3] === ' '
        ) {
            break;
        }
    }

    return $result;
}

function smtpExpect($socket, array $codes): void
{
    $response = smtpRead($socket);

    $code = (int)substr(trim($response), 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTP応答エラー: ' .
            trim($response)
        );
    }
}

function smtpCommand(
    $socket,
    string $command,
    array $codes
): void {
    fwrite($socket, $command . "\r\n");

    smtpExpect($socket, $codes);
}

function smtpSend(
    array $cfg,
    string $to,
    string $subject,
    string $body
): void {
    $server = trim((string)($cfg['smtpServer'] ?? ''));
    $port = (int)($cfg['smtpPort'] ?? 587);

    if ($server === '' || $port < 1) {
        throw new RuntimeException(
            'SMTPサーバ設定が未設定です。'
        );
    }

    $encryption = $cfg['encryption'] ?? 'starttls';

    if ($encryption === 'ssl') {
        $host = 'ssl://' . $server;
    } else {
        $host = $server;
    }

    $errno = 0;
    $errstr = '';

    $socket = @fsockopen(
        $host,
        $port,
        $errno,
        $errstr,
        20
    );

    if (!$socket) {
        throw new RuntimeException(
            'SMTP接続に失敗しました: ' .
            $errstr .
            ' (' . $errno . ')'
        );
    }

    stream_set_timeout($socket, 20);

    try {
        smtpExpect($socket, [220]);

        smtpCommand(
            $socket,
            'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
            [250]
        );

        if (
            $encryption === 'starttls'
        ) {
            smtpCommand(
                $socket,
                'STARTTLS',
                [220]
            );

            $crypto = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'STARTTLSを有効化できませんでした。'
                );
            }

            smtpCommand(
                $socket,
                'EHLO ' .
                    ($_SERVER['SERVER_NAME'] ?? 'localhost'),
                [250]
            );
        }

        if (
            !empty($cfg['authentication'])
        ) {
            $username = (string)($cfg['username'] ?? '');
            $password = (string)($cfg['password'] ?? '');

            if ($username === '') {
                throw new RuntimeException(
                    'SMTPユーザー名が設定されていません。'
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

        $from = trim((string)($cfg['fromEmail'] ?? ''));

        if ($from === '') {
            throw new RuntimeException(
                '送信元メールアドレスが設定されていません。'
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

        $fromName = trim(
            (string)($cfg['fromName'] ?? '')
        );

        $fromHeader = $from;

        if ($fromName !== '') {
            $fromHeader =
                '=?UTF-8?B?' .
                base64_encode($fromName) .
                '?= <' .
                $from .
                '>';
        }

        $headers = [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' .
                base64_encode($subject) .
                '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $replyTo = trim(
            (string)($cfg['replyTo'] ?? '')
        );

        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            str_replace(
                ["\r\n.", "\n."],
                ["\r\n..", "\n.."],
                $body
            ) .
            "\r\n.";

        fwrite($socket, $message . "\r\n");

        smtpExpect($socket, [250]);

        smtpCommand(
            $socket,
            'QUIT',
            [221]
        );
    } finally {
        fclose($socket);
    }
}


/* ============================================================
 * API
 * ========================================================== */

function api(string $action, array $input): never
{
    switch ($action) {

        /* ---------------------------------------------
         * 初期データ
         * ------------------------------------------- */

        case 'bootstrap':

            $surveys = readData('surveys', []);

            if (autoFinish($surveys)) {
                writeData('surveys', $surveys);
            }

            jsonOut([
                'success' => true,
                'surveys' => $surveys,
                'customers' => readData('customers', []),
                'responses' => readData('responses', []),
                'history' => readData('history', []),
                'kintone' => readData(
                    'kintone',
                    defaultKintone()
                ),
                'mail' => readData(
                    'mail',
                    defaultMail()
                ),
            ]);


        /* ---------------------------------------------
         * アンケート保存
         * ------------------------------------------- */

        case 'saveSurvey':

            $surveys = readData('surveys', []);

            $id = trim(
                (string)($input['id'] ?? '')
            );

            $existingIndex =
                $id === ''
                    ? -1
                    : surveyIndex($surveys, $id);

            $existing =
                $existingIndex >= 0
                    ? $surveys[$existingIndex]
                    : null;

            $survey = $input['survey'] ?? null;

            if (!is_array($survey)) {
                fail('アンケートデータが不正です。');
            }

            $survey['id'] =
                $existing['id'] ??
                uid('survey-');

            $survey['title'] =
                trim((string)($survey['title'] ?? ''));

            if ($survey['title'] === '') {
                fail('タイトルを入力してください。');
            }

            $survey['description'] =
                trim((string)($survey['description'] ?? ''));

            $survey['startDate'] =
                $survey['startDate'] ?? null;

            $survey['endDate'] =
                $survey['endDate'] ?? null;

            $survey['questionNumberMode'] =
                ($survey['questionNumberMode'] ?? 'all')
                    === 'group'
                    ? 'group'
                    : 'all';

            $survey['allowResubmission'] =
                !empty($survey['allowResubmission']);

            /*
             * 新規はdraft。
             * 既存は現在statusを維持。
             */
            if ($existing) {
                $survey['status'] =
                    $existing['status'];
                $survey['createdAt'] =
                    $existing['createdAt'];
            } else {
                $survey['status'] =
                    STATUS_DRAFT;
                $survey['createdAt'] =
                    isoNow();
            }

            if (!isset($survey['groups']) ||
                !is_array($survey['groups'])) {
                $survey['groups'] = [];
            }

            recalcNumbers($survey);

            $survey['updatedAt'] = isoNow();

            if ($existingIndex >= 0) {
                $surveys[$existingIndex] = $survey;
            } else {
                $surveys[] = $survey;
            }

            writeData('surveys', $surveys);

            jsonOut([
                'success' => true,
                'survey' => $survey,
            ]);


        /* ---------------------------------------------
         * 状態変更
         * ------------------------------------------- */

        case 'changeStatus':

            $id = trim(
                (string)($input['surveyId'] ?? '')
            );

            $newStatus =
                (string)($input['status'] ?? '');

            $allowed = [
                STATUS_DRAFT,
                STATUS_PUBLISHED,
                STATUS_STOPPED,
            ];

            if (!in_array($newStatus, $allowed, true)) {
                fail(
                    '指定できない状態です。'
                );
            }

            $surveys = readData('surveys', []);

            $index = surveyIndex($surveys, $id);

            if ($index < 0) {
                fail(
                    'アンケートが存在しません。',
                    404
                );
            }

            $current =
                $surveys[$index]['status'];

            if ($current === STATUS_FINISHED) {
                fail(
                    '終了したアンケートは変更できません。'
                );
            }

            $valid = (
                ($current === STATUS_DRAFT &&
                    $newStatus === STATUS_PUBLISHED) ||

                ($current === STATUS_PUBLISHED &&
                    $newStatus === STATUS_STOPPED) ||

                ($current === STATUS_STOPPED &&
                    $newStatus === STATUS_PUBLISHED)
            );

            if (!$valid) {
                fail(
                    '許可されていない状態遷移です。'
                );
            }

            $surveys[$index]['status'] =
                $newStatus;

            $surveys[$index]['updatedAt'] =
                isoNow();

            writeData('surveys', $surveys);

            jsonOut([
                'success' => true,
                'survey' => $surveys[$index],
            ]);


        /* ---------------------------------------------
         * 複製
         * ------------------------------------------- */

        case 'duplicateSurvey':

            $id = trim(
                (string)($input['surveyId'] ?? '')
            );

            $surveys = readData('surveys', []);

            $source = surveyById(
                $surveys,
                $id
            );

            if (!$source) {
                fail(
                    '複製対象が存在しません。',
                    404
                );
            }

            $copy = $source;

            $copy['id'] =
                uid('survey-');

            $copy['title'] =
                $source['title'] . '（複製）';

            $copy['status'] =
                STATUS_DRAFT;

            $copy['createdAt'] =
                isoNow();

            $copy['updatedAt'] =
                isoNow();

            /*
             * IDを再生成。
             * 回答・送信履歴はコピーしない。
             */
            foreach ($copy['groups'] as &$group) {
                $oldGroupId = $group['id'];
                $group['id'] = uid('group-');

                foreach (
                    $group['questions']
                    as &$question
                ) {
                    $question['id'] =
                        uid('question-');

                    $question['groupId'] =
                        $group['id'];

                    foreach (
                        $question['choices']
                        as &$choice
                    ) {
                        $choice['id'] =
                            uid('choice-');
                    }

                    unset($choice);

                    foreach (
                        $question['branches']
                        as &$branch
                    ) {
                        $branch['nextQuestionId'] = '';
                    }

                    unset($branch);
                }

                unset($question);
            }

            unset($group);

            recalcNumbers($copy);

            $surveys[] = $copy;

            writeData('surveys', $surveys);

            jsonOut([
                'success' => true,
                'survey' => $copy,
            ]);


        /* ---------------------------------------------
         * 削除
         * ------------------------------------------- */

        case 'deleteSurvey':

            $id = trim(
                (string)($input['surveyId'] ?? '')
            );

            $surveys = readData('surveys', []);

            $index = surveyIndex(
                $surveys,
                $id
            );

            if ($index < 0) {
                fail(
                    '削除対象が存在しません。',
                    404
                );
            }

            array_splice(
                $surveys,
                $index,
                1
            );

            writeData('surveys', $surveys);

            /*
             * 関連回答を削除。
             * 顧客そのものは削除しない。
             */
            $responses =
                readData('responses', []);

            $responses = array_values(
                array_filter(
                    $responses,
                    static fn($r) =>
                        ($r['surveyId'] ?? '') !== $id
                )
            );

            writeData(
                'responses',
                $responses
            );

            jsonOut([
                'success' => true,
            ]);


        /* ---------------------------------------------
         * 回答送信
         * ------------------------------------------- */

        case 'submitResponse':

            $surveyId = trim(
                (string)($input['surveyId'] ?? '')
            );

            $token = trim(
                (string)($input['token'] ?? '')
            );

            $answers =
                is_array($input['answers'] ?? null)
                    ? $input['answers']
                    : [];

            $respondent =
                is_array($input['respondent'] ?? null)
                    ? $input['respondent']
                    : [];

            $surveys =
                readData('surveys', []);

            if (autoFinish($surveys)) {
                writeData('surveys', $surveys);
            }

            $survey = surveyById(
                $surveys,
                $surveyId
            );

            if (!$survey) {
                fail(
                    'アンケートが存在しません。',
                    404
                );
            }

            if (($survey['status'] ?? '') !==
                STATUS_PUBLISHED) {
                fail(
                    '現在このアンケートには回答できません。'
                );
            }

            $responses =
                readData('responses', []);

            if ($token !== '') {
                foreach ($responses as $response) {
                    if (
                        ($response['surveyId'] ?? '') ===
                            $surveyId &&
                        ($response['individualToken'] ?? '') ===
                            $token &&
                        ($response['status'] ?? '') ===
                            'completed'
                    ) {
                        if (
                            empty(
                                $survey['allowResubmission']
                            )
                        ) {
                            fail(
                                'このアンケートは回答済みです。'
                            );
                        }
                    }
                }
            }

            $response = [
                'id' => uid('response-'),
                'surveyId' => $surveyId,
                'individualToken' => $token,
                'respondent' => $respondent,
                'answers' => $answers,
                'status' => 'completed',
                'submittedAt' => isoNow(),
                'updatedAt' => isoNow(),
            ];

            $responses[] = $response;

            writeData(
                'responses',
                $responses
            );

            /*
             * 顧客との紐付け。
             * tokenまたはメールアドレスを使用。
             */
            $customers =
                readData('customers', []);

            $email =
                strtolower(
                    trim(
                        (string)(
                            $respondent['email'] ?? ''
                        )
                    )
                );

            foreach ($customers as &$customer) {
                $match = false;

                if (
                    $token !== '' &&
                    ($customer['token'] ?? '') ===
                        $token
                ) {
                    $match = true;
                }

                if (
                    !$match &&
                    $email !== '' &&
                    strtolower(
                        (string)(
                            $customer['email'] ?? ''
                        )
                    ) === $email
                ) {
                    $match = true;
                }

                if ($match) {
                    $customer['status'] =
                        '回答済み';
                }
            }

            unset($customer);

            writeData(
                'customers',
                $customers
            );

            jsonOut([
                'success' => true,
                'response' => $response,
            ]);


        /* ---------------------------------------------
         * SMTP一括送信
         * ------------------------------------------- */

        case 'sendMail':

            $surveyId = trim(
                (string)($input['surveyId'] ?? '')
            );

            if ($surveyId === '') {
                fail(
                    '送信対象アンケートが指定されていません。'
                );
            }

            $surveys =
                readData('surveys', []);

            $survey = surveyById(
                $surveys,
                $surveyId
            );

            if (!$survey) {
                fail(
                    '対象アンケートが存在しません。',
                    404
                );
            }

            $customerIds =
                is_array($input['customerIds'] ?? null)
                    ? $input['customerIds']
                    : [];

            if (!$customerIds) {
                fail(
                    '送信対象顧客を選択してください。'
                );
            }

            $subject =
                trim(
                    (string)($input['subject'] ?? '')
                );

            $body =
                (string)($input['body'] ?? '');

            $type =
                (string)($input['type'] ?? '一括送信');

            if ($subject === '') {
                fail(
                    'メール件名を入力してください。'
                );
            }

            $customers =
                readData('customers', []);

            $mail =
                readData(
                    'mail',
                    defaultMail()
                );

            $history =
                readData('history', []);

            $results = [];

            foreach ($customerIds as $customerId) {

                $customer = null;

                foreach ($customers as $c) {
                    if (
                        ($c['id'] ?? '') ===
                            (string)$customerId
                    ) {
                        $customer = $c;
                        break;
                    }
                }

                if (!$customer) {
                    $results[] = [
                        'customerId' => $customerId,
                        'success' => false,
                        'error' =>
                            '顧客が存在しません。',
                    ];

                    continue;
                }

                $name =
                    (string)(
                        $customer['name'] ?? ''
                    );

                /*
                 * 個別URL。
                 * 管理画面URLではなく回答URL。
                 */
                $base =
                    rtrim(
                        (string)(
                            $input['answerBaseUrl']
                                ?? (
                                    (
                                        !empty($_SERVER['HTTPS'])
                                            ? 'https'
                                            : 'http'
                                    ) .
                                    '://' .
                                    ($_SERVER['HTTP_HOST'] ?? '') .
                                    dirname(
                                        $_SERVER['SCRIPT_NAME']
                                            ?? '/index.php'
                                    ) .
                                    '/index.php'
                                )
                        ),
                        '/'
                    );

                $token =
                    $customer['token'] ??
                    uid('answer-');

                $url =
                    $base .
                    '?view=answer&survey=' .
                    rawurlencode($surveyId) .
                    '&token=' .
                    rawurlencode($token);

                $expandedSubject =
                    str_replace(
                        [
                            '{顧客名}',
                            '{アンケートURL}',
                        ],
                        [
                            $name,
                            $url,
                        ],
                        $subject
                    );

                $expandedBody =
                    str_replace(
                        [
                            '{顧客名}',
                            '{アンケートURL}',
                        ],
                        [
                            $name,
                            $url,
                        ],
                        $body
                    );

                $to =
                    trim(
                        (string)(
                            $customer['email'] ?? ''
                        )
                    );

                if (!filter_var(
                    $to,
                    FILTER_VALIDATE_EMAIL
                )) {
                    $results[] = [
                        'customerId' =>
                            $customer['id'],
                        'customerName' => $name,
                        'success' => false,
                        'error' =>
                            'メールアドレスが不正です。',
                    ];

                    continue;
                }

                try {
                    smtpSend(
                        $mail,
                        $to,
                        $expandedSubject,
                        $expandedBody
                    );

                    foreach (
                        $customers as &$customerRow
                    ) {
                        if (
                            ($customerRow['id'] ?? '') ===
                                $customer['id']
                        ) {
                            $customerRow['token'] =
                                $token;

                            $customerRow['status'] =
                                '送信済み / 未回答';

                            $customerRow['lastSentAt'] =
                                isoNow();

                            $customerRow['sendCount'] =
                                (int)(
                                    $customerRow['sendCount']
                                        ?? 0
                                ) + 1;
                        }
                    }

                    unset($customerRow);

                    $results[] = [
                        'customerId' =>
                            $customer['id'],
                        'customerName' => $name,
                        'success' => true,
                        'url' => $url,
                    ];
                } catch (
                    Throwable $e
                ) {
                    $results[] = [
                        'customerId' =>
                            $customer['id'],
                        'customerName' => $name,
                        'success' => false,
                        'error' =>
                            $e->getMessage(),
                    ];
                }
            }

            writeData(
                'customers',
                $customers
            );

            $successCount =
                count(
                    array_filter(
                        $results,
                        static fn($r) =>
                            !empty($r['success'])
                    )
                );

            $failureCount =
                count($results) -
                $successCount;

            $history[] = [
                'id' => uid('history-'),
                'surveyId' => $surveyId,
                'sentAt' => isoNow(),
                'type' => $type,
                'count' => count($results),
                'successCount' => $successCount,
                'failureCount' => $failureCount,
                'subject' => $subject,
                'body' => $body,
                'results' => $results,
                'executedBy' => '管理者',
            ];

            writeData(
                'history',
                $history
            );

            jsonOut([
                'success' => true,
                'results' => $results,
                'summary' => [
                    'total' => count($results),
                    'success' => $successCount,
                    'failure' => $failureCount,
                    'sentAt' => isoNow(),
                ],
            ]);


        /* ---------------------------------------------
         * kintone設定保存
         * ------------------------------------------- */

        case 'saveKintone':

            $settings = [
                'subdomain' =>
                    trim(
                        (string)(
                            $input['subdomain'] ?? ''
                        )
                    ),
                'appId' =>
                    trim(
                        (string)(
                            $input['appId'] ?? ''
                        )
                    ),
                'loginName' =>
                    trim(
                        (string)(
                            $input['loginName'] ?? ''
                        )
                    ),
                'password' =>
                    (string)(
                        $input['password'] ?? ''
                    ),
                'sslVerify' =>
                    !empty($input['sslVerify']),
                'proxy' =>
                    trim(
                        (string)(
                            $input['proxy'] ?? ''
                        )
                    ),
                'fields' =>
                    readData(
                        'kintone',
                        defaultKintone()
                    )['fields'] ?? [],
                'mapping' =>
                    is_array(
                        $input['mapping'] ?? null
                    )
                        ? $input['mapping']
                        : defaultKintone()['mapping'],
                'updatedAt' => isoNow(),
            ];

            if (
                $settings['subdomain'] !== '' &&
                normalizeSubdomain(
                    $settings['subdomain']
                ) === null
            ) {
                fail(
                    'kintoneサブドメインの形式が不正です。'
                );
            }

            if (
                $settings['proxy'] !== '' &&
                normalizeProxy(
                    $settings['proxy']
                ) === null
            ) {
                fail(
                    'プロキシはhost:port形式で入力してください。'
                );
            }

            writeData(
                'kintone',
                $settings
            );

            jsonOut([
                'success' => true,
                'settings' => $settings,
            ]);


        /* ---------------------------------------------
         * kintone接続テスト
         * ------------------------------------------- */

        case 'testKintone':

            $settings =
                readData(
                    'kintone',
                    defaultKintone()
                );

            /*
             * 接続テストだけ。
             * 保存・項目取得・同期はしない。
             */
            $result = kintoneRequest(
                $settings,
                'GET',
                '/k/v1/record.json?app=' .
                    rawurlencode(
                        (string)$settings['appId']
                    ) .
                    '&totalCount=true&query=' .
                    rawurlencode('limit 1')
            );

            if (!$result['success']) {
                jsonOut([
                    'success' => false,
                    'error' => '接続失敗',
                    'detail' =>
                        $result['error'] .
                        (
                            isset($result['detail'])
                                ? ' ' .
                                    $result['detail']
                                : ''
                        ),
                ]);
            }

            jsonOut([
                'success' => true,
                'message' => '接続成功',
            ]);


        /* ---------------------------------------------
         * kintone項目取得
         * ------------------------------------------- */

        case 'fetchKintoneFields':

            $settings =
                readData(
                    'kintone',
                    defaultKintone()
                );

            /*
             * 接続テストは呼ばない。
             */
            $result = kintoneRequest(
                $settings,
                'GET',
                '/k/v1/app/form/fields.json?app=' .
                    rawurlencode(
                        (string)$settings['appId']
                    )
            );

            if (!$result['success']) {
                fail(
                    'kintone項目取得に失敗しました。',
                    400,
                    [
                        'detail' =>
                            $result['error'] .
                            (
                                isset($result['detail'])
                                    ? ' ' .
                                        $result['detail']
                                    : ''
                            )
                    ]
                );
            }

            $fields =
                $result['data']['properties']
                    ?? [];

            $settings['fields'] =
                $fields;

            $settings['updatedAt'] =
                isoNow();

            writeData(
                'kintone',
                $settings
            );

            jsonOut([
                'success' => true,
                'fields' => $fields,
            ]);


        /* ---------------------------------------------
         * kintone顧客同期
         * ------------------------------------------- */

        case 'syncKintone':

            $settings =
                readData(
                    'kintone',
                    defaultKintone()
                );

            $appId =
                trim(
                    (string)$settings['appId']
                );

            $result = kintoneRequest(
                $settings,
                'GET',
                '/k/v1/records.json?app=' .
                    rawurlencode($appId) .
                    '&query=' .
                    rawurlencode('limit 500')
            );

            if (!$result['success']) {
                fail(
                    '顧客同期に失敗しました。',
                    400,
                    [
                        'detail' =>
                            $result['error'] .
                            (
                                isset($result['detail'])
                                    ? ' ' .
                                        $result['detail']
                                    : ''
                            )
                    ]
                );
            }

            $records =
                $result['data']['records']
                    ?? [];

            $mapping =
                $settings['mapping']
                    ?? defaultKintone()['mapping'];

            $customers =
                readData('customers', []);

            $byEmail = [];

            foreach ($customers as $index => $customer) {
                $email =
                    strtolower(
                        trim(
                            (string)(
                                $customer['email']
                                    ?? ''
                            )
                        )
                    );

                if ($email !== '') {
                    $byEmail[$email] =
                        $index;
                }
            }

            foreach ($records as $record) {

                $getField = static function (
                    string $code
                ) use ($record): string {
                    return trim(
                        (string)(
                            $record[$code]['value']
                                ?? ''
                        )
                    );
                };

                $emailCode =
                    (string)(
                        $mapping['email'] ?? ''
                    );

                $email =
                    strtolower(
                        $getField($emailCode)
                    );

                if ($email === '') {
                    continue;
                }

                $customer = [
                    'id' => uid('customer-'),
                    'organization' =>
                        $getField(
                            (string)(
                                $mapping['organization']
                                    ?? ''
                            )
                        ),
                    'name' =>
                        $getField(
                            (string)(
                                $mapping['name']
                                    ?? ''
                            )
                        ),
                    'email' => $email,
                    'department' =>
                        $getField(
                            (string)(
                                $mapping['department']
                                    ?? ''
                            )
                        ),
                    'phone' =>
                        $getField(
                            (string)(
                                $mapping['phone']
                                    ?? ''
                            )
                        ),
                    'address' => '',
                    'status' => '未送信',
                    'lastSentAt' => null,
                    'sendCount' => 0,
                    'kintoneStatus' => '登録済み',
                ];

                $addressParts = [];

                foreach (
                    ($mapping['address'] ?? [])
                    as $code
                ) {
                    $part =
                        $getField(
                            (string)$code
                        );

                    if ($part !== '') {
                        $addressParts[] =
                            $part;
                    }
                }

                $customer['address'] =
                    implode(
                        ' ',
                        $addressParts
                    );

                if (
                    isset($byEmail[$email])
                ) {
                    $index =
                        $byEmail[$email];

                    $customer['id'] =
                        $customers[$index]['id'];

                    $customer['status'] =
                        $customers[$index]['status']
                            ?? '未送信';

                    $customer['lastSentAt'] =
                        $customers[$index]['lastSentAt']
                            ?? null;

                    $customer['sendCount'] =
                        $customers[$index]['sendCount']
                            ?? 0;

                    $customers[$index] =
                        $customer;
                } else {
                    $customers[] =
                        $customer;

                    $byEmail[$email] =
                        count($customers) - 1;
                }
            }

            writeData(
                'customers',
                $customers
            );

            jsonOut([
                'success' => true,
                'message' => '顧客同期完了',
                'count' => count($records),
            ]);


        /* ---------------------------------------------
         * メール設定保存
         * ------------------------------------------- */

        case 'saveMail':

            $mail = [
                'smtpServer' =>
                    trim(
                        (string)(
                            $input['smtpServer']
                                ?? ''
                        )
                    ),
                'smtpPort' =>
                    (int)(
                        $input['smtpPort']
                            ?? 587
                    ),
                'encryption' =>
                    in_array(
                        ($input['encryption']
                            ?? 'starttls'),
                        ['none', 'starttls', 'ssl'],
                        true
                    )
                        ? $input['encryption']
                        : 'starttls',
                'authentication' =>
                    !empty(
                        $input['authentication']
                    ),
                'username' =>
                    trim(
                        (string)(
                            $input['username']
                                ?? ''
                        )
                    ),
                'password' =>
                    (string)(
                        $input['password']
                            ?? ''
                    ),
                'fromEmail' =>
                    trim(
                        (string)(
                            $input['fromEmail']
                                ?? ''
                        )
                    ),
                'fromName' =>
                    trim(
                        (string)(
                            $input['fromName']
                                ?? ''
                        )
                    ),
                'replyTo' =>
                    trim(
                        (string)(
                            $input['replyTo']
                                ?? ''
                        )
                    ),
                'connectionStatus' =>
                    '未設定',
                'lastTestAt' => null,
                'lastError' => '',
                'updatedAt' => isoNow(),
            ];

            writeData(
                'mail',
                $mail
            );

            jsonOut([
                'success' => true,
                'mail' => $mail,
            ]);


        /* ---------------------------------------------
         * SMTPテスト
         * ------------------------------------------- */

        case 'testMail':

            $mail =
                readData(
                    'mail',
                    defaultMail()
                );

            $to =
                trim(
                    (string)(
                        $input['to']
                            ?? $mail['replyTo']
                            ?? ''
                    )
                );

            if (
                !filter_var(
                    $to,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                fail(
                    'テスト送信先メールアドレスを指定してください。'
                );
            }

            try {

                smtpSend(
                    $mail,
                    $to,
                    'アンケート管理システム テストメール',
                    'SMTP通信テストに成功しました。'
                );

                $mail['connectionStatus'] =
                    '接続確認済み';

                $mail['lastTestAt'] =
                    isoNow();

                $mail['lastError'] = '';

                writeData(
                    'mail',
                    $mail
                );

                jsonOut([
                    'success' => true,
                    'message' =>
                        'テストメール送信成功',
                ]);
            } catch (
                Throwable $e
            ) {
                $mail['connectionStatus'] =
                    '接続できません';

                $mail['lastTestAt'] =
                    isoNow();

                $mail['lastError'] =
                    $e->getMessage();

                writeData(
                    'mail',
                    $mail
                );

                jsonOut([
                    'success' => false,
                    'error' =>
                        'テストメール送信失敗',
                    'detail' =>
                        $e->getMessage(),
                ]);
            }


        default:
            fail(
                '未知のAPIです。',
                404,
                ['action' => $action]
            );
    }
}


/* ============================================================
 * PHP API入口
 *
 * ここが今回の重要部分。
 *
 * APIリクエスト:
 *   POST index.php?api=bootstrap
 *
 * 通常表示:
 *   GET index.php
 *
 * 回答:
 *   GET index.php?view=answer&survey=...&token=...
 * ========================================================== */

try {

    initializeData();

    if (
        isset($_GET['api']) &&
        trim((string)$_GET['api']) !== ''
    ) {
        $action =
            trim((string)$_GET['api']);

        $input = requestJson();

        api($action, $input);
    }

} catch (
    Throwable $e
) {
    /*
     * APIの場合は絶対にHTMLを返さない。
     */
    if (
        isset($_GET['api'])
    ) {
        jsonOut([
            'success' => false,
            'error' =>
                'PHP API内部エラー',
            'detail' =>
                $e->getMessage(),
            'file' =>
                basename($e->getFile()),
            'line' =>
                $e->getLine(),
        ], 500);
    }

    /*
     * 通常HTML側の致命的エラー。
     */
    $bootError =
        $e->getMessage();
}


/* ============================================================
 * 回答者URL判定
 * ========================================================== */

$isAnswerView =
    isset($_GET['view']) &&
    $_GET['view'] === 'answer';

$answerSurveyId =
    trim(
        (string)(
            $_GET['survey']
                ?? ''
        )
    );

$answerToken =
    trim(
        (string)(
            $_GET['token']
                ?? ''
        )
    );

$bootError =
    $bootError
        ?? null;

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>
<title>アンケート管理システム</title>

<style>
* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
    color: #1f2937;
    background: #f3f4f6;
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

.hidden {
    display: none !important;
}

.app-header {
    height: 64px;
    background: #111827;
    color: white;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
}

.app-title {
    font-weight: 700;
    font-size: 18px;
}

.app-nav {
    display: flex;
    gap: 8px;
}

.app-nav button {
    border: 0;
    background: transparent;
    color: #d1d5db;
    padding: 9px 12px;
    border-radius: 6px;
}

.app-nav button:hover {
    background: #374151;
    color: white;
}

.container {
    max-width: 1440px;
    margin: 0 auto;
    padding: 24px;
}

.card {
    background: white;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    padding: 20px;
    margin-bottom: 18px;
}

.page-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
}

.page-title h1 {
    margin: 0;
    font-size: 24px;
}

.toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

button {
    border: 1px solid #d1d5db;
    background: white;
    border-radius: 7px;
    padding: 9px 14px;
}

button.primary {
    background: #2563eb;
    border-color: #2563eb;
    color: white;
}

button.danger {
    background: #dc2626;
    border-color: #dc2626;
    color: white;
}

button.success {
    background: #059669;
    border-color: #059669;
    color: white;
}

button:disabled {
    opacity: .45;
    cursor: not-allowed;
}

input,
textarea,
select {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    padding: 9px 11px;
    background: white;
}

textarea {
    min-height: 100px;
    resize: vertical;
}

label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
}

.form-grid {
    display: grid;
    grid-template-columns:
        repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.form-grid .full {
    grid-column: 1 / -1;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

th,
td {
    padding: 11px 10px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    vertical-align: middle;
}

th {
    background: #f9fafb;
    white-space: nowrap;
}

.status {
    display: inline-block;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 700;
}

.status-draft {
    background: #e5e7eb;
}

.status-published {
    background: #dcfce7;
    color: #166534;
}

.status-stopped {
    background: #fef3c7;
    color: #92400e;
}

.status-finished {
    background: #fee2e2;
    color: #991b1b;
}

.group {
    border: 1px solid #d1d5db;
    border-radius: 10px;
    margin-bottom: 18px;
    background: #fff;
}

.group-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}

.drag-handle {
    cursor: grab;
    color: #6b7280;
}

.question {
    margin: 12px;
    padding: 15px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: white;
}

.question-header {
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.question-number {
    font-weight: 800;
    color: #2563eb;
}

.choice-row {
    display: flex;
    gap: 8px;
    margin: 6px 0;
}

.choice-row input {
    flex: 1;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 1000;
}

.modal {
    width: min(560px, 100%);
    background: white;
    border-radius: 10px;
    padding: 22px;
    box-shadow: 0 20px 50px rgba(0,0,0,.25);
}

.modal h2 {
    margin-top: 0;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 20px;
}

.toast {
    position: fixed;
    right: 20px;
    bottom: 20px;
    max-width: 480px;
    padding: 14px 18px;
    border-radius: 8px;
    background: #111827;
    color: white;
    z-index: 2000;
}

.error-panel {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #991b1b;
    padding: 18px;
    border-radius: 8px;
    white-space: pre-wrap;
}

.summary-grid {
    display: grid;
    grid-template-columns:
        repeat(5, minmax(0, 1fr));
    gap: 12px;
}

.summary-card {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    background: white;
}

.summary-card strong {
    display: block;
    font-size: 26px;
    margin-top: 5px;
}

.answer-shell {
    min-height: 100vh;
    background: #f8fafc;
    padding: 20px;
}

.answer-container {
    width: min(760px, 100%);
    margin: 0 auto;
}

.answer-card {
    background: white;
    border-radius: 12px;
    padding: 22px;
    border: 1px solid #e5e7eb;
    margin-bottom: 16px;
}

.answer-choice {
    display: block;
    padding: 13px;
    margin: 8px 0;
    border: 1px solid #d1d5db;
    border-radius: 8px;
}

.answer-choice input {
    width: auto;
    margin-right: 8px;
}

.answer-actions {
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.bar {
    height: 14px;
    background: #e5e7eb;
    border-radius: 999px;
    overflow: hidden;
}

.bar > div {
    height: 100%;
    background: #2563eb;
}

.preview-phone {
    max-width: 390px;
    margin: auto;
    border: 8px solid #111827;
    border-radius: 25px;
    overflow: hidden;
}

.preview-pc {
    max-width: 1100px;
    margin: auto;
}

@media (max-width: 900px) {
    .form-grid {
        grid-template-columns: 1fr;
    }

    .summary-grid {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .app-header {
        height: auto;
        padding: 12px;
        gap: 10px;
        flex-wrap: wrap;
    }

    .container {
        padding: 12px;
    }
}

@media (max-width: 600px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .page-title {
        align-items: flex-start;
        flex-direction: column;
    }

    .answer-shell {
        padding: 8px;
    }

    .answer-card {
        padding: 16px;
    }
}
</style>
</head>

<body>

<?php if ($isAnswerView): ?>

<div id="answerApp" class="answer-shell"></div>

<script>
window.__ANSWER_CONTEXT__ = {
    surveyId: <?= json_encode(
        $answerSurveyId,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>,
    token: <?= json_encode(
        $answerToken,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>
};
</script>

<?php else: ?>

<header class="app-header">
    <div class="app-title">
        アンケート管理システム
    </div>

    <nav class="app-nav">
        <button onclick="App.show('list')">
            アンケート一覧
        </button>

        <button onclick="App.show('kintone')">
            kintone連携設定
        </button>

        <button onclick="App.show('mail')">
            メールサーバ設定
        </button>

        <button onclick="App.logout()">
            ログアウト
        </button>
    </nav>
</header>

<main class="container">
    <div id="app"></div>
</main>

<div id="modalRoot"></div>
<div id="toastRoot"></div>

<?php endif; ?>


<script>
"use strict";

/* ============================================================
 * 共通API
 *
 * 今回の Failed to fetch 対策の中心。
 * ========================================================== */

const API_URL =
    window.location.pathname + "?api=";

async function api(action, payload = {}) {

    let response;

    try {
        response = await fetch(
            API_URL +
            encodeURIComponent(action),
            {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type":
                        "application/json",
                    "Accept":
                        "application/json"
                },
                body: JSON.stringify(payload)
            }
        );
    } catch (error) {

        throw new Error(
            "PHP APIへ接続できませんでした。\n\n" +
            "Failed to fetch\n\n" +
            "index.phpがApache/PHP経由で実行されていること、" +
            "PHPが有効になっていることを確認してください。\n\n" +
            "API: " +
            API_URL +
            encodeURIComponent(action) +
            "\n\n" +
            "詳細: " +
            error.message
        );
    }

    const text =
        await response.text();

    const contentType =
        response.headers.get(
            "content-type"
        ) || "";

    if (
        !contentType
            .toLowerCase()
            .includes("application/json")
    ) {
        throw new Error(
            "PHP APIがJSONを返していません。\n\n" +
            "HTTP: " +
            response.status +
            "\n" +
            "Content-Type: " +
            contentType +
            "\n\n" +
            "index.phpがPHPとして実行されているか確認してください。\n\n" +
            "レスポンス先頭:\n" +
            text.slice(0, 500)
        );
    }

    let data;

    try {
        data = JSON.parse(text);
    } catch (error) {
        throw new Error(
            "PHP APIから不正なJSONが返されました。\n\n" +
            text.slice(0, 1000)
        );
    }

    if (!response.ok || data.success === false) {
        throw new Error(
            data.error ||
            data.detail ||
            `APIエラー: HTTP ${response.status}`
        );
    }

    return data;
}


/* ============================================================
 * 共通状態
 * ========================================================== */

const State = {
    screen: "list",

    surveys: [],
    customers: [],
    responses: [],
    history: [],

    kintone: null,
    mail: null,

    editSurveyId: null,
    aggregateSurveyId: null,
    sendSurveyId: null,

    editDraft: null,

    selectedCustomers: new Set(),

    search: "",
    filter: "all",
    sort: "updatedDesc",

    aggregateSelected:
        new Set(),

    aggregateSurvey: null,

    answer: {
        survey: null,
        surveyId: "",
        token: "",
        values: {},
        respondent: {},
        visible: [],
        page: 0,
        confirmed: false
    }
};


/* ============================================================
 * UI
 * ========================================================== */

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function toast(message) {
    const root =
        document.getElementById(
            "toastRoot"
        );

    if (!root) return;

    root.innerHTML =
        `<div class="toast">
            ${escapeHtml(message)}
        </div>`;

    setTimeout(() => {
        root.innerHTML = "";
    }, 4000);
}

function confirmModal(
    message,
    onExecute
) {
    const root =
        document.getElementById(
            "modalRoot"
        );

    root.innerHTML = `
        <div class="modal-backdrop">
            <div class="modal">
                <h2>確認</h2>
                <div>${escapeHtml(message)}</div>
                <div class="modal-actions">
                    <button
                        onclick="closeModal()"
                    >
                        キャンセル
                    </button>

                    <button
                        class="primary"
                        id="modalExecute"
                    >
                        実行
                    </button>
                </div>
            </div>
        </div>
    `;

    document
        .getElementById(
            "modalExecute"
        )
        .onclick = async () => {
            closeModal();

            try {
                await onExecute();
            } catch (e) {
                toast(e.message);
            }
        };
}

function closeModal() {
    const root =
        document.getElementById(
            "modalRoot"
        );

    if (root) {
        root.innerHTML = "";
    }
}


/* ============================================================
 * 管理者アプリ
 * ========================================================== */

const App = {

    async init() {
        try {
            const data =
                await api("bootstrap");

            State.surveys =
                data.surveys || [];

            State.customers =
                data.customers || [];

            State.responses =
                data.responses || [];

            State.history =
                data.history || [];

            State.kintone =
                data.kintone || {};

            State.mail =
                data.mail || {};

            this.show("list");

        } catch (error) {

            const root =
                document.getElementById(
                    "app"
                );

            root.innerHTML = `
                <div class="card">
                    <h1>
                        システムを起動できませんでした。
                    </h1>

                    <div class="error-panel">
                        ${escapeHtml(
                            error.message
                        )}
                    </div>

                    <br>

                    <button
                        class="primary"
                        onclick="location.reload()"
                    >
                        再読み込み
                    </button>
                </div>
            `;
        }
    },

    show(screen) {

        State.screen = screen;

        switch (screen) {
            case "list":
                this.renderList();
                break;

            case "edit":
                this.renderEdit();
                break;

            case "preview":
                this.renderPreview();
                break;

            case "send":
                this.renderSend();
                break;

            case "aggregate":
                this.renderAggregate();
                break;

            case "kintone":
                this.renderKintone();
                break;

            case "mail":
                this.renderMail();
                break;

            default:
                this.renderList();
        }
    },

    logout() {
        State.screen = "list";
        State.editSurveyId = null;
        State.aggregateSurveyId = null;
        State.sendSurveyId = null;

        toast(
            "画面状態をリセットしました。"
        );

        this.renderList();
    },


    /* ========================================================
     * 一覧
     * ====================================================== */

    renderList() {

        const root =
            document.getElementById(
                "app"
            );

        let surveys =
            [...State.surveys];

        const search =
            State.search
                .trim()
                .toLowerCase();

        if (search) {
            surveys =
                surveys.filter(
                    s =>
                        String(
                            s.title || ""
                        )
                            .toLowerCase()
                            .includes(search)
                );
        }

        if (State.filter !== "all") {
            surveys =
                surveys.filter(
                    s =>
                        s.status ===
                        State.filter
                );
        }

        surveys.sort(
            (a, b) => {

                switch (
                    State.sort
                ) {

                    case "updatedAsc":
                        return String(
                            a.updatedAt || ""
                        ).localeCompare(
                            String(
                                b.updatedAt || ""
                            )
                        );

                    case "answersDesc":
                        return (
                            this.answerCount(b.id) -
                            this.answerCount(a.id)
                        );

                    case "answersAsc":
                        return (
                            this.answerCount(a.id) -
                            this.answerCount(b.id)
                        );

                    case "startDesc":
                        return String(
                            b.startDate || ""
                        ).localeCompare(
                            String(
                                a.startDate || ""
                            )
                        );

                    case "startAsc":
                        return String(
                            a.startDate || ""
                        ).localeCompare(
                            String(
                                b.startDate || ""
                            )
                        );

                    default:
                        return String(
                            b.updatedAt || ""
                        ).localeCompare(
                            String(
                                a.updatedAt || ""
                            )
                        );
                }
            }
        );

        root.innerHTML = `
            <div class="page-title">
                <h1>アンケート一覧</h1>

                <button
                    class="primary"
                    onclick="App.newSurvey()"
                >
                    ＋ アンケート作成
                </button>
            </div>

            <div class="card">
                <div class="toolbar">

                    <input
                        id="surveySearch"
                        style="max-width:420px"
                        placeholder="タイトルを検索"
                        value="${escapeHtml(
                            State.search
                        )}"
                        onkeydown="
                            if(event.key==='Enter'){
                                App.searchSurvey();
                            }
                        "
                    >

                    <button
                        onclick="App.searchSurvey()"
                    >
                        検索
                    </button>

                    <select
                        style="width:auto"
                        onchange="
                            State.filter=this.value;
                            App.renderList();
                        "
                    >
                        <option value="all">
                            すべて
                        </option>
                        <option
                            value="published"
                            ${State.filter === "published"
                                ? "selected" : ""}
                        >
                            公開中
                        </option>
                        <option
                            value="draft"
                            ${State.filter === "draft"
                                ? "selected" : ""}
                        >
                            下書き
                        </option>
                        <option
                            value="stopped"
                            ${State.filter === "stopped"
                                ? "selected" : ""}
                        >
                            停止
                        </option>
                        <option
                            value="finished"
                            ${State.filter === "finished"
                                ? "selected" : ""}
                        >
                            終了
                        </option>
                    </select>

                    <select
                        style="width:auto"
                        onchange="
                            State.sort=this.value;
                            App.renderList();
                        "
                    >
                        <option
                            value="updatedDesc"
                        >
                            更新日 新しい順
                        </option>
                        <option
                            value="updatedAsc"
                        >
                            更新日 古い順
                        </option>
                        <option
                            value="answersDesc"
                        >
                            回答数 多い順
                        </option>
                        <option
                            value="answersAsc"
                        >
                            回答数 少ない順
                        </option>
                        <option
                            value="startDesc"
                        >
                            開始日 新しい順
                        </option>
                        <option
                            value="startAsc"
                        >
                            開始日 古い順
                        </option>
                    </select>
                </div>
            </div>

            <div class="card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>作成日</th>
                                <th>更新日</th>
                                <th>タイトル</th>
                                <th>期間</th>
                                <th>ステータス</th>
                                <th>回答数</th>
                                <th>操作</th>
                            </tr>
                        </thead>

                        <tbody>
                            ${surveys.map(
                                s =>
                                    this.surveyRow(s)
                            ).join("")}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    },

    surveyRow(s) {

        const statusText = {
            draft: "下書き",
            published: "公開中",
            stopped: "停止",
            finished: "終了"
        }[s.status] || s.status;

        return `
            <tr>
                <td>
                    ${escapeHtml(
                        this.date(s.createdAt)
                    )}
                </td>

                <td>
                    ${escapeHtml(
                        this.date(s.updatedAt)
                    )}
                </td>

                <td>
                    <strong>
                        ${escapeHtml(s.title)}
                    </strong>
                </td>

                <td>
                    ${escapeHtml(
                        this.date(s.startDate)
                    )}
                    ～
                    ${escapeHtml(
                        this.date(s.endDate)
                    )}
                </td>

                <td>
                    <span
                        class="status status-${s.status}"
                    >
                        ${statusText}
                    </span>
                </td>

                <td>
                    ${this.answerCount(s.id)}
                </td>

                <td>
                    <div class="toolbar">

                        <button
                            onclick="
                                App.edit('${s.id}')
                            "
                        >
                            確認・編集
                        </button>

                        <button
                            onclick="
                                App.aggregate('${s.id}')
                            "
                        >
                            集計
                        </button>

                        <button
                            onclick="
                                App.send('${s.id}')
                            "
                        >
                            送信
                        </button>

                        <button
                            onclick="
                                App.duplicate('${s.id}')
                            "
                        >
                            複製
                        </button>

                        <button
                            class="danger"
                            onclick="
                                App.remove('${s.id}')
                            "
                        >
                            削除
                        </button>

                    </div>
                </td>
            </tr>
        `;
    },

    searchSurvey() {
        const input =
            document.getElementById(
                "surveySearch"
            );

        State.search =
            input
                ? input.value
                : "";

        this.renderList();
    },

    answerCount(id) {
        return State.responses.filter(
            r =>
                r.surveyId === id &&
                r.status === "completed"
        ).length;
    },

    date(value) {
        if (!value) {
            return "-";
        }

        const d =
            new Date(value);

        if (
            Number.isNaN(
                d.getTime()
            )
        ) {
            return String(value);
        }

        return d.toLocaleString(
            "ja-JP"
        );
    },


    /* ========================================================
     * 編集
     * ====================================================== */

    newSurvey() {

        State.editSurveyId = null;

        State.editDraft = {
            id: null,
            title: "",
            description: "",
            startDate: "",
            endDate: "",
            questionNumberMode: "all",
            allowResubmission: false,
            status: "draft",
            groups: []
        };

        this.show("edit");
    },

    edit(id) {

        const survey =
            State.surveys.find(
                s => s.id === id
            );

        if (!survey) {
            toast(
                "アンケートが存在しません。"
            );

            return;
        }

        State.editSurveyId = id;

        State.editDraft =
            JSON.parse(
                JSON.stringify(survey)
            );

        this.show("edit");
    },

    renderEdit() {

        const root =
            document.getElementById(
                "app"
            );

        const s =
            State.editDraft;

        const statusText = {
            draft: "下書き",
            published: "公開中",
            stopped: "停止",
            finished: "終了"
        };

        let statusOptions = "";

        if (
            s.status === "draft"
        ) {
            statusOptions = `
                <option value="draft">
                    下書き
                </option>
                <option value="published">
                    公開中
                </option>
            `;
        } else if (
            s.status === "published"
        ) {
            statusOptions = `
                <option value="published">
                    公開中
                </option>
                <option value="stopped">
                    停止
                </option>
            `;
        } else if (
            s.status === "stopped"
        ) {
            statusOptions = `
                <option value="stopped">
                    停止
                </option>
                <option value="published">
                    公開中
                </option>
            `;
        } else {
            statusOptions = `
                <option value="finished">
                    終了
                </option>
            `;
        }

        root.innerHTML = `
            <div class="page-title">

                <h1>
                    ${s.id
                        ? "アンケート編集"
                        : "アンケート作成"}
                </h1>

                <div class="toolbar">

                    <button
                        onclick="App.cancelEdit()"
                    >
                        キャンセル
                    </button>

                    <button
                        class="primary"
                        onclick="App.saveEdit()"
                    >
                        保存して一覧へ
                    </button>

                </div>
            </div>

            <div class="card">

                <div class="form-grid">

                    <div class="full">
                        <label>
                            タイトル
                        </label>

                        <input
                            id="editTitle"
                            value="${escapeHtml(
                                s.title
                            )}"
                        >
                    </div>

                    <div class="full">
                        <label>
                            説明
                        </label>

                        <textarea
                            id="editDescription"
                        >${escapeHtml(
                            s.description
                        )}</textarea>
                    </div>

                    <div>
                        <label>
                            開始日時
                        </label>

                        <input
                            id="editStart"
                            type="datetime-local"
                            value="${this.localDate(
                                s.startDate
                            )}"
                        >
                    </div>

                    <div>
                        <label>
                            終了日時
                        </label>

                        <input
                            id="editEnd"
                            type="datetime-local"
                            value="${this.localDate(
                                s.endDate
                            )}"
                        >
                    </div>

                    <div>
                        <label>
                            質問番号の採番方式
                        </label>

                        <select
                            id="editNumberMode"
                            onchange="
                                App.setNumberMode(this.value)
                            "
                        >
                            <option
                                value="all"
                                ${s.questionNumberMode === "all"
                                    ? "selected" : ""}
                            >
                                アンケート全体で通番
                            </option>

                            <option
                                value="group"
                                ${s.questionNumberMode === "group"
                                    ? "selected" : ""}
                            >
                                グループ毎に採番
                            </option>
                        </select>
                    </div>

                    <div>
                        <label>
                            状態
                        </label>

                        <select
                            id="editStatus"
                            ${s.status === "finished"
                                ? "disabled" : ""}
                            onchange="
                                App.changeEditStatus(
                                    this.value
                                )
                            "
                        >
                            ${statusOptions}
                        </select>
                    </div>

                    <div>
                        <label>
                            回答済み再回答
                        </label>

                        <label>
                            <input
                                id="allowResubmission"
                                type="checkbox"
                                style="width:auto"
                                ${s.allowResubmission
                                    ? "checked" : ""}
                            >
                            再回答を許可する
                        </label>
                    </div>

                </div>

            </div>

            <div id="groupsRoot">
                ${this.renderGroups()}
            </div>

            <div class="card">
                <button
                    class="primary"
                    onclick="App.addGroup()"
                >
                    ＋ グループを追加
                </button>
            </div>
        `;
    },

    localDate(value) {
        if (!value) return "";

        const d =
            new Date(value);

        if (
            Number.isNaN(
                d.getTime()
            )
        ) {
            return "";
        }

        const pad =
            n => String(n).padStart(
                2,
                "0"
            );

        return (
            d.getFullYear() +
            "-" +
            pad(d.getMonth() + 1) +
            "-" +
            pad(d.getDate()) +
            "T" +
            pad(d.getHours()) +
            ":" +
            pad(d.getMinutes())
        );
    },

    setNumberMode(mode) {
        State.editDraft.questionNumberMode =
            mode === "group"
                ? "group"
                : "all";

        this.recalcDraft();

        document.getElementById(
            "groupsRoot"
        ).innerHTML =
            this.renderGroups();
    },

    renderGroups() {

        const s =
            State.editDraft;

        return s.groups.map(
            (group, gi) => `
                <div
                    class="group"
                    draggable="true"
                    ondragstart="
                        App.dragGroup(${gi})
                    "
                    ondragover="
                        event.preventDefault()
                    "
                    ondrop="
                        App.dropGroup(${gi})
                    "
                >

                    <div class="group-header">

                        <span class="drag-handle">
                            ☷
                        </span>

                        <input
                            value="${escapeHtml(
                                group.title
                            )}"
                            onchange="
                                App.updateGroupTitle(
                                    ${gi},
                                    this.value
                                )
                            "
                        >

                        <button
                            class="danger"
                            onclick="
                                App.removeGroup(${gi})
                            "
                        >
                            削除
                        </button>

                    </div>

                    <div>
                        ${group.questions.map(
                            (q, qi) =>
                                this.renderQuestion(
                                    gi,
                                    qi,
                                    q
                                )
                        ).join("")}
                    </div>

                    <div
                        style="padding:12px"
                    >
                        <button
                            onclick="
                                App.addQuestion(
                                    ${gi}
                                )
                            "
                        >
                            ＋ 質問を追加
                        </button>
                    </div>

                </div>
            `
        ).join("");
    },

    renderQuestion(
        gi,
        qi,
        q
    ) {

        return `
            <div
                class="question"
                draggable="true"
                ondragstart="
                    App.dragQuestion(
                        ${gi},
                        ${qi}
                    )
                "
                ondragover="
                    event.preventDefault()
                "
                ondrop="
                    App.dropQuestion(
                        ${gi},
                        ${qi}
                    )
                "
            >

                <div class="question-header">

                    <div>
                        <span class="question-number">
                            ${escapeHtml(
                                q.questionNumber
                            )}
                        </span>
                    </div>

                    <div class="toolbar">

                        <button
                            onclick="
                                App.moveQuestion(
                                    ${gi},
                                    ${qi}
                                )
                            "
                        >
                            移動
                        </button>

                        <button
                            class="danger"
                            onclick="
                                App.removeQuestion(
                                    ${gi},
                                    ${qi}
                                )
                            "
                        >
                            削除
                        </button>

                    </div>

                </div>

                <br>

                <label>
                    質問文
                </label>

                <textarea
                    onchange="
                        App.updateQuestion(
                            ${gi},
                            ${qi},
                            'text',
                            this.value
                        )
                    "
                >${escapeHtml(
                    q.text
                )}</textarea>

                <br>

                <div class="form-grid">

                    <div>
                        <label>
                            回答形式
                        </label>

                        <select
                            onchange="
                                App.changeQuestionType(
                                    ${gi},
                                    ${qi},
                                    this.value
                                )
                            "
                        >
                            <option
                                value="single"
                                ${q.type === "single"
                                    ? "selected" : ""}
                            >
                                単一選択
                            </option>

                            <option
                                value="multiple"
                                ${q.type === "multiple"
                                    ? "selected" : ""}
                            >
                                複数選択
                            </option>

                            <option
                                value="text"
                                ${q.type === "text"
                                    ? "selected" : ""}
                            >
                                自由記述
                            </option>
                        </select>
                    </div>

                    <div>
                        <label>
                            必須
                        </label>

                        <label>
                            <input
                                type="checkbox"
                                style="width:auto"
                                ${q.required
                                    ? "checked" : ""}
                                onchange="
                                    App.updateQuestion(
                                        ${gi},
                                        ${qi},
                                        'required',
                                        this.checked
                                    )
                                "
                            >
                            必須回答
                        </label>
                    </div>

                </div>

                ${
                    q.type !== "text"
                        ? this.renderChoices(
                            gi,
                            qi,
                            q
                        )
                        : ""
                }

                ${
                    q.type === "single"
                        ? this.renderBranches(
                            gi,
                            qi,
                            q
                        )
                        : ""
                }

            </div>
        `;
    },

    renderChoices(
        gi,
        qi,
        q
    ) {

        return `
            <div class="card">

                <strong>
                    選択肢
                </strong>

                ${
                    q.choices.map(
                        (c, ci) => `
                            <div class="choice-row">

                                <input
                                    value="${escapeHtml(
                                        c.label
                                    )}"
                                    onchange="
                                        App.updateChoice(
                                            ${gi},
                                            ${qi},
                                            ${ci},
                                            this.value
                                        )
                                    "
                                >

                                <button
                                    class="danger"
                                    onclick="
                                        App.removeChoice(
                                            ${gi},
                                            ${qi},
                                            ${ci}
                                        )
                                    "
                                >
                                    削除
                                </button>

                            </div>
                        `
                    ).join("")
                }

                <button
                    onclick="
                        App.addChoice(
                            ${gi},
                            ${qi}
                        )
                    "
                >
                    ＋ 選択肢
                </button>

            </div>
        `;
    },

    renderBranches(
        gi,
        qi,
        q
    ) {

        return `
            <div class="card">

                <strong>
                    条件分岐
                </strong>

                <p>
                    選択肢ごとに次の質問を指定できます。
                </p>

                ${
                    q.choices.map(
                        c => {

                            const branch =
                                (
                                    q.branches
                                        || []
                                ).find(
                                    b =>
                                        b.choiceId ===
                                        c.id
                                );

                            return `
                                <div
                                    class="form-grid"
                                    style="margin-bottom:8px"
                                >

                                    <div>
                                        <label>
                                            ${escapeHtml(
                                                c.label
                                            )}
                                        </label>
                                    </div>

                                    <div>
                                        <select
                                            onchange="
                                                App.setBranch(
                                                    ${gi},
                                                    ${qi},
                                                    '${c.id}',
                                                    this.value
                                                )
                                            "
                                        >
                                            <option value="">
                                                次の質問を指定しない
                                            </option>

                                            ${
                                                this.allQuestions()
                                                    .map(
                                                        target => `
                                                            <option
                                                                value="${target.id}"
                                                                ${branch &&
                                                                    branch.nextQuestionId ===
                                                                    target.id
                                                                    ? "selected"
                                                                    : ""}
                                                            >
                                                                ${escapeHtml(
                                                                    target.questionNumber
                                                                )}
                                                                ${escapeHtml(
                                                                    target.text
                                                                )}
                                                            </option>
                                                        `
                                                    )
                                                    .join("")
                                            }

                                        </select>
                                    </div>

                                </div>
                            `;
                        }
                    ).join("")
                }

            </div>
        `;
    },

    allQuestions() {

        const result = [];

        for (
            const group
            of State.editDraft.groups
        ) {
            for (
                const q
                of group.questions
            ) {
                result.push(q);
            }
        }

        return result;
    },

    recalcDraft() {

        let global = 0;

        State.editDraft.groups
            .forEach(
                (group, gi) => {

                    group.sortOrder =
                        gi + 1;

                    group.questions
                        .forEach(
                            (q, qi) => {

                                global++;

                                q.groupId =
                                    group.id;

                                q.sortOrder =
                                    qi + 1;

                                q.questionNumber =
                                    State.editDraft
                                        .questionNumberMode ===
                                        "group"
                                            ? `Q${gi + 1}-${qi + 1}`
                                            : `Q${global}`;
                            }
                        );
                }
            );
    },

    updateGroupTitle(
        gi,
        value
    ) {
        State.editDraft.groups[gi]
            .title = value;
    },

    addGroup() {

        State.editDraft.groups.push({
            id: "group-" +
                crypto.randomUUID(),
            title: "新しいグループ",
            sortOrder:
                State.editDraft.groups.length + 1,
            questions: []
        });

        this.recalcDraft();

        this.renderEdit();
    },

    removeGroup(gi) {

        const group =
            State.editDraft.groups[gi];

        const message =
            group.questions.length
                ? "質問が存在するグループです。削除しますか？"
                : "このグループを削除しますか？";

        confirmModal(
            message,
            async () => {

                State.editDraft.groups
                    .splice(gi, 1);

                this.recalcDraft();
                this.renderEdit();
            }
        );
    },

    addQuestion(gi) {

        const group =
            State.editDraft.groups[gi];

        const q =
            makeQuestionClient();

        q.groupId =
            group.id;

        q.sortOrder =
            group.questions.length + 1;

        group.questions.push(q);

        this.recalcDraft();
        this.renderEdit();
    },

    removeQuestion(
        gi,
        qi
    ) {

        confirmModal(
            "この質問を削除しますか？",
            async () => {

                State.editDraft
                    .groups[gi]
                    .questions
                    .splice(qi, 1);

                this.recalcDraft();
                this.renderEdit();
            }
        );
    },

    changeQuestionType(
        gi,
        qi,
        type
    ) {

        const q =
            State.editDraft
                .groups[gi]
                .questions[qi];

        q.type = type;

        if (type === "text") {
            q.choices = [];
            q.branches = [];
        } else if (
            !q.choices.length
        ) {
            q.choices = [
                {
                    id:
                        "choice-" +
                        crypto.randomUUID(),
                    label: "選択肢1",
                    sortOrder: 1
                }
            ];
        }

        if (type !== "single") {
            q.branches = [];
        }

        this.renderEdit();
    },

    updateQuestion(
        gi,
        qi,
        field,
        value
    ) {

        State.editDraft
            .groups[gi]
            .questions[qi][field] =
            value;
    },

    addChoice(
        gi,
        qi
    ) {

        const q =
            State.editDraft
                .groups[gi]
                .questions[qi];

        q.choices.push({
            id:
                "choice-" +
                crypto.randomUUID(),
            label:
                "選択肢" +
                (q.choices.length + 1),
            sortOrder:
                q.choices.length + 1
        });

        this.renderEdit();
    },

    updateChoice(
        gi,
        qi,
        ci,
        value
    ) {

        State.editDraft
            .groups[gi]
            .questions[qi]
            .choices[ci]
            .label = value;
    },

    removeChoice(
        gi,
        qi,
        ci
    ) {

        const q =
            State.editDraft
                .groups[gi]
                .questions[qi];

        q.choices.splice(ci, 1);

        q.choices.forEach(
            (c, i) =>
                c.sortOrder = i + 1
        );

        q.branches =
            (q.branches || [])
                .filter(
                    b =>
                        q.choices.some(
                            c =>
                                c.id ===
                                b.choiceId
                        )
                );

        this.renderEdit();
    },

    setBranch(
        gi,
        qi,
        choiceId,
        nextQuestionId
    ) {

        const q =
            State.editDraft
                .groups[gi]
                .questions[qi];

        q.branches =
            (q.branches || [])
                .filter(
                    b =>
                        b.choiceId !==
                        choiceId
                );

        if (nextQuestionId) {
            q.branches.push({
                choiceId,
                nextQuestionId
            });
        }
    },

    dragGroup(gi) {
        this.dragData = {
            type: "group",
            index: gi
        };
    },

    dropGroup(target) {

        const d =
            this.dragData;

        if (
            !d ||
            d.type !== "group" ||
            d.index === target
        ) {
            return;
        }

        const groups =
            State.editDraft.groups;

        const item =
            groups.splice(
                d.index,
                1
            )[0];

        groups.splice(
            target,
            0,
            item
        );

        this.recalcDraft();
        this.renderEdit();
    },

    dragQuestion(
        gi,
        qi
    ) {

        this.dragData = {
            type: "question",
            gi,
            qi
        };
    },

    dropQuestion(
        targetGi,
        targetQi
    ) {

        const d =
            this.dragData;

        if (
            !d ||
            d.type !== "question"
        ) {
            return;
        }

        const sourceGroup =
            State.editDraft
                .groups[d.gi];

        const targetGroup =
            State.editDraft
                .groups[targetGi];

        const item =
            sourceGroup.questions
                .splice(d.qi, 1)[0];

        let insertAt =
            targetQi;

        if (
            d.gi === targetGi &&
            d.qi < targetQi
        ) {
            insertAt--;
        }

        targetGroup.questions
            .splice(
                Math.max(
                    0,
                    insertAt
                ),
                0,
                item
            );

        this.recalcDraft();
        this.renderEdit();
    },

    moveQuestion(
        gi,
        qi
    ) {

        const options =
            State.editDraft.groups
                .map(
                    (g, i) =>
                        `${i}: ${g.title}`
                )
                .join("\n");

        const value =
            prompt(
                "移動先グループ番号を入力してください。\n\n" +
                options
            );

        if (value === null) {
            return;
        }

        const target =
            Number(value);

        if (
            !Number.isInteger(target) ||
            target < 0 ||
            target >=
                State.editDraft.groups.length
        ) {
            toast(
                "移動先が不正です。"
            );

            return;
        }

        const source =
            State.editDraft
                .groups[gi];

        const item =
            source.questions
                .splice(qi, 1)[0];

        item.groupId =
            State.editDraft
                .groups[target]
                .id;

        State.editDraft
            .groups[target]
            .questions
            .push(item);

        this.recalcDraft();
        this.renderEdit();
    },

    async saveEdit() {

        const s =
            State.editDraft;

        s.title =
            document.getElementById(
                "editTitle"
            ).value.trim();

        s.description =
            document.getElementById(
                "editDescription"
            ).value;

        s.startDate =
            document.getElementById(
                "editStart"
            ).value || null;

        s.endDate =
            document.getElementById(
                "editEnd"
            ).value || null;

        s.questionNumberMode =
            document.getElementById(
                "editNumberMode"
            ).value;

        s.allowResubmission =
            document.getElementById(
                "allowResubmission"
            ).checked;

        if (!s.title) {
            toast(
                "タイトルを入力してください。"
            );

            return;
        }

        this.recalcDraft();

        try {

            const result =
                await api(
                    "saveSurvey",
                    {
                        id: s.id,
                        survey: s
                    }
                );

            const index =
                State.surveys.findIndex(
                    x =>
                        x.id ===
                        result.survey.id
                );

            if (index >= 0) {
                State.surveys[index] =
                    result.survey;
            } else {
                State.surveys.push(
                    result.survey
                );
            }

            toast(
                "保存しました。"
            );

            this.show("list");

        } catch (e) {
            toast(e.message);
        }
    },

    cancelEdit() {

        confirmModal(
            "編集内容を破棄して前画面へ戻りますか？",
            async () => {
                this.show("list");
            }
        );
    },

    changeEditStatus(
        status
    ) {

        const old =
            State.editDraft.status;

        if (old === status) {
            return;
        }

        const message = {
            published:
                "このアンケートを公開しますか？",
            stopped:
                "このアンケートを停止しますか？",
        }[status];

        if (!message) {
            document.getElementById(
                "editStatus"
            ).value = old;

            return;
        }

        confirmModal(
            message,
            async () => {

                try {

                    const result =
                        await api(
                            "changeStatus",
                            {
                                surveyId:
                                    State.editDraft.id,
                                status
                            }
                        );

                    State.editDraft =
                        JSON.parse(
                            JSON.stringify(
                                result.survey
                            )
                        );

                    const index =
                        State.surveys
                            .findIndex(
                                x =>
                                    x.id ===
                                    result.survey.id
                            );

                    if (index >= 0) {
                        State.surveys[index] =
                            result.survey;
                    }

                    this.renderEdit();

                } catch (e) {

                    document.getElementById(
                        "editStatus"
                    ).value = old;

                    toast(e.message);
                }
            }
        );
    },

    duplicate(id) {

        confirmModal(
            "このアンケートを複製しますか？",
            async () => {

                const result =
                    await api(
                        "duplicateSurvey",
                        {
                            surveyId: id
                        }
                    );

                State.surveys.push(
                    result.survey
                );

                this.renderList();

                toast(
                    "複製しました。"
                );
            }
        );
    },

    remove(id) {

        confirmModal(
            "このアンケートを削除しますか？",
            async () => {

                await api(
                    "deleteSurvey",
                    {
                        surveyId: id
                    }
                );

                State.surveys =
                    State.surveys.filter(
                        s => s.id !== id
                    );

                State.responses =
                    State.responses.filter(
                        r =>
                            r.surveyId !== id
                    );

                this.renderList();

                toast(
                    "削除しました。"
                );
            }
        );
    },


    /* ========================================================
     * プレビュー
     * ====================================================== */

    renderPreview() {

        const s =
            State.editDraft;

        const root =
            document.getElementById(
                "app"
            );

        root.innerHTML = `
            <div class="page-title">
                <h1>プレビュー</h1>

                <div class="toolbar">
                    <button
                        onclick="App.show('edit')"
                    >
                        編集へ戻る
                    </button>

                    <button
                        onclick="
                            App.previewMode('pc')
                        "
                    >
                        PC
                    </button>

                    <button
                        onclick="
                            App.previewMode('phone')
                        "
                    >
                        スマートフォン
                    </button>
                </div>
            </div>

            <div
                id="previewRoot"
                class="preview-pc"
            >
                ${this.previewContent()}
            </div>
        `;
    },

    previewMode(mode) {

        const root =
            document.getElementById(
                "previewRoot"
            );

        if (!root) return;

        root.className =
            mode === "phone"
                ? "preview-phone"
                : "preview-pc";
    },

    previewContent() {

        const s =
            State.editDraft;

        return `
            <div class="card">
                <h1>
                    ${escapeHtml(
                        s.title
                    )}
                </h1>

                <p>
                    ${escapeHtml(
                        s.description
                    )}
                </p>
            </div>

            ${
                s.groups.map(
                    g => `
                        <div class="card">

                            <h2>
                                ${escapeHtml(
                                    g.title
                                )}
                            </h2>

                            ${
                                g.questions
                                    .map(
                                        q =>
                                            this.previewQuestion(
                                                q
                                            )
                                    )
                                    .join("")
                            }

                        </div>
                    `
                ).join("")
            }
        `;
    },

    previewQuestion(q) {

        return `
            <div class="question">

                <div>
                    <span class="question-number">
                        ${escapeHtml(
                            q.questionNumber
                        )}
                    </span>

                    <strong>
                        ${escapeHtml(
                            q.text
                        )}
                    </strong>

                    ${
                        q.required
                            ? " <span>必須</span>"
                            : ""
                    }
                </div>

                <br>

                ${
                    q.type === "text"
                        ? `
                            <textarea
                                disabled
                            ></textarea>
                        `
                        : q.choices.map(
                            c => `
                                <label
                                    class="answer-choice"
                                >
                                    <input
                                        type="${
                                            q.type ===
                                            "single"
                                                ? "radio"
                                                : "checkbox"
                                        }"
                                        disabled
                                    >
                                    ${escapeHtml(
                                        c.label
                                    )}
                                </label>
                            `
                        ).join("")
                }

            </div>
        `;
    },


    /* ========================================================
     * 送信
     * ====================================================== */

    send(id) {

        State.sendSurveyId = id;

        State.selectedCustomers =
            new Set();

        this.show("send");
    },

    renderSend() {

        const root =
            document.getElementById(
                "app"
            );

        const survey =
            State.surveys.find(
                s =>
                    s.id ===
                    State.sendSurveyId
            );

        if (!survey) {
            this.show("list");
            return;
        }

        const search =
            State.customerSearch
                || "";

        let customers =
            State.customers.filter(
                c => {

                    const text =
                        [
                            c.name,
                            c.organization,
                            c.email,
                            c.status
                        ]
                            .join(" ")
                            .toLowerCase();

                    return text.includes(
                        search.toLowerCase()
                    );
                }
            );

        root.innerHTML = `
            <div class="page-title">

                <h1>
                    顧客選択・メール送信
                </h1>

                <button
                    onclick="App.show('list')"
                >
                    一覧へ戻る
                </button>

            </div>

            <div class="card">
                <strong>
                    対象アンケート
                </strong>

                <h2>
                    ${escapeHtml(
                        survey.title
                    )}
                </h2>

                <p>
                    対象アンケートはこの画面では変更できません。
                </p>
            </div>

            <div class="card">

                <h2>顧客選択</h2>

                <div class="toolbar">

                    <input
                        style="max-width:500px"
                        placeholder="
                            顧客名・組織名・メール・ステータス
                        "
                        value="${escapeHtml(
                            search
                        )}"
                        oninput="
                            State.customerSearch=this.value;
                            App.renderSend();
                        "
                        onkeydown="
                            if(event.key==='Enter'){
                                App.renderSend();
                            }
                        "
                    >

                    <button
                        onclick="App.selectReminder()"
                    >
                        未回答を選択
                    </button>

                </div>

                <br>

                <div class="table-wrap">

                    <table>
                        <thead>
                            <tr>
                                <th></th>
                                <th>組織名</th>
                                <th>氏名</th>
                                <th>メール</th>
                                <th>電話</th>
                                <th>住所</th>
                                <th>最終送信</th>
                                <th>送信回数</th>
                                <th>回答ステータス</th>
                                <th>kintone</th>
                            </tr>
                        </thead>

                        <tbody>

                            ${customers.map(
                                c => `
                                    <tr>

                                        <td>
                                            <input
                                                type="checkbox"
                                                style="width:auto"
                                                ${
                                                    State.selectedCustomers
                                                        .has(c.id)
                                                        ? "checked"
                                                        : ""
                                                }
                                                onchange="
                                                    App.toggleCustomer(
                                                        '${c.id}',
                                                        this.checked
                                                    )
                                                "
                                            >
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                c.organization
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                c.name
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                c.email
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                c.phone
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                c.address
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                this.date(
                                                    c.lastSentAt
                                                )
                                            )}
                                        </td>

                                        <td>
                                            ${c.sendCount || 0}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                c.status
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                c.kintoneStatus
                                            )}
                                        </td>

                                    </tr>
                                `
                            ).join("")}

                        </tbody>
                    </table>

                </div>
            </div>

            <div class="card">

                <h2>メール作成</h2>

                <div class="form-grid">

                    <div class="full">
                        <label>
                            件名
                        </label>

                        <input
                            id="mailSubject"
                            value="
                                アンケートのお願い
                            "
                        >
                    </div>

                    <div class="full">
                        <label>
                            本文
                        </label>

                        <textarea
                            id="mailBody"
                        >${escapeHtml(
                            "【{顧客名}】様\n\n" +
                            "以下のURLからアンケートへご回答ください。\n\n" +
                            "{アンケートURL}\n"
                        )}</textarea>
                    </div>

                </div>

                <br>

                <div class="toolbar">

                    <button
                        class="primary"
                        onclick="
                            App.confirmSend(
                                '一括送信'
                            )
                        "
                    >
                        一括送信
                    </button>

                    <button
                        onclick="
                            App.confirmSend(
                                '再送'
                            )
                        "
                    >
                        再送
                    </button>

                    <button
                        onclick="
                            App.selectReminder()
                        "
                    >
                        リマインド対象を選択
                    </button>

                </div>

            </div>

            ${this.sendResultHtml()}

            ${this.sendHistoryHtml(survey.id)}
        `;
    },

    toggleCustomer(
        id,
        checked
    ) {

        if (checked) {
            State.selectedCustomers.add(
                id
            );
        } else {
            State.selectedCustomers.delete(
                id
            );
        }
    },

    selectReminder() {

        State.selectedCustomers =
            new Set(
                State.customers
                    .filter(
                        c =>
                            c.status ===
                            "送信済み / 未回答"
                    )
                    .map(
                        c => c.id
                    )
            );

        this.renderSend();

        toast(
            "未回答顧客を選択しました。"
        );
    },

    confirmSend(type) {

        const ids =
            [...State.selectedCustomers];

        if (!ids.length) {
            toast(
                "顧客を選択してください。"
            );

            return;
        }

        const message =
            type === "再送"
                ? "送信済み顧客を含めて再送しますか？"
                : "選択した顧客へメールを送信しますか？";

        confirmModal(
            message,
            async () => {

                const subject =
                    document.getElementById(
                        "mailSubject"
                    ).value;

                const body =
                    document.getElementById(
                        "mailBody"
                    ).value;

                const result =
                    await api(
                        "sendMail",
                        {
                            surveyId:
                                State.sendSurveyId,
                            customerIds:
                                ids,
                            subject,
                            body,
                            type,
                        }
                    );

                State.lastSendResult =
                    result;

                const fresh =
                    await api(
                        "bootstrap"
                    );

                State.customers =
                    fresh.customers;

                State.history =
                    fresh.history;

                this.renderSend();
            }
        );
    },

    sendResultHtml() {

        const result =
            State.lastSendResult;

        if (!result) {
            return "";
        }

        return `
            <div class="card">

                <h2>
                    送信結果
                </h2>

                <div class="summary-grid">

                    <div class="summary-card">
                        対象件数
                        <strong>
                            ${result.summary.total}
                        </strong>
                    </div>

                    <div class="summary-card">
                        成功件数
                        <strong>
                            ${result.summary.success}
                        </strong>
                    </div>

                    <div class="summary-card">
                        失敗件数
                        <strong>
                            ${result.summary.failure}
                        </strong>
                    </div>

                    <div class="summary-card">
                        送信日時
                        <strong
                            style="font-size:16px"
                        >
                            ${escapeHtml(
                                this.date(
                                    result.summary.sentAt
                                )
                            )}
                        </strong>
                    </div>

                </div>

                <br>

                <div class="table-wrap">

                    <table>

                        <thead>
                            <tr>
                                <th>顧客</th>
                                <th>結果</th>
                                <th>詳細</th>
                            </tr>
                        </thead>

                        <tbody>

                            ${result.results
                                .map(
                                    r => `
                                        <tr>

                                            <td>
                                                ${escapeHtml(
                                                    r.customerName
                                                        || r.customerId
                                                )}
                                            </td>

                                            <td>
                                                ${
                                                    r.success
                                                        ? "成功"
                                                        : "失敗"
                                                }
                                            </td>

                                            <td>
                                                ${escapeHtml(
                                                    r.error
                                                        || r.url
                                                        || ""
                                                )}
                                            </td>

                                        </tr>
                                    `
                                )
                                .join("")}

                        </tbody>

                    </table>

                </div>

            </div>
        `;
    },

    sendHistoryHtml(
        surveyId
    ) {

        const rows =
            State.history.filter(
                h =>
                    h.surveyId ===
                    surveyId
            ).slice()
            .reverse();

        return `
            <div class="card">

                <h2>
                    送信履歴
                </h2>

                ${
                    rows.length
                        ? rows.map(
                            h => `
                                <details>
                                    <summary>
                                        ${escapeHtml(
                                            this.date(
                                                h.sentAt
                                            )
                                        )}
                                        /
                                        ${escapeHtml(
                                            h.type
                                        )}
                                        /
                                        ${h.count}件
                                    </summary>

                                    <p>
                                        件名:
                                        ${escapeHtml(
                                            h.subject
                                        )}
                                    </p>

                                    <pre
                                        style="
                                            white-space:pre-wrap;
                                            background:#f9fafb;
                                            padding:12px
                                        "
                                    >${escapeHtml(
                                        h.body
                                    )}</pre>

                                    ${
                                        (h.results || [])
                                            .map(
                                                r => `
                                                    <div
                                                        class="card"
                                                    >
                                                        <strong>
                                                            ${escapeHtml(
                                                                r.customerName
                                                            )}
                                                        </strong>

                                                        <p>
                                                            ${
                                                                r.url
                                                                    ? escapeHtml(
                                                                        r.url
                                                                    )
                                                                    : ""
                                                            }
                                                        </p>
                                                    </div>
                                                `
                                            )
                                            .join("")
                                    }

                                </details>
                            `
                        ).join("")
                        : "<p>送信履歴はありません。</p>"
                }

            </div>
        `;
    },


    /* ========================================================
     * 集計
     * ====================================================== */

    aggregate(id) {

        State.aggregateSurveyId =
            id;

        this.show("aggregate");
    },

    renderAggregate() {

        const survey =
            State.surveys.find(
                s =>
                    s.id ===
                    State.aggregateSurveyId
            );

        if (!survey) {
            this.show("list");
            return;
        }

        State.aggregateSurvey =
            survey;

        const root =
            document.getElementById(
                "app"
            );

        const responses =
            State.responses.filter(
                r =>
                    r.surveyId ===
                    survey.id &&
                    r.status ===
                    "completed"
            );

        const selected =
            State.aggregateSelected;

        if (!selected.size) {
            this.allQuestionIds(
                survey
            ).forEach(
                id =>
                    selected.add(id)
            );
        }

        const sentCustomers =
            State.customers.filter(
                c =>
                    c.status !==
                    "未送信"
            );

        const registered =
            responses.filter(
                r =>
                    r.customerId
            ).length;

        const answerRate =
            sentCustomers.length
                ? Math.round(
                    responses.length /
                    sentCustomers.length *
                    100
                )
                : 0;

        root.innerHTML = `
            <div class="page-title">

                <h1>
                    回答集計・分析
                </h1>

                <button
                    onclick="App.show('list')"
                >
                    一覧へ戻る
                </button>

            </div>

            <div class="card">

                <strong>
                    対象アンケート
                </strong>

                <h2>
                    ${escapeHtml(
                        survey.title
                    )}
                </h2>

            </div>

            <div class="summary-grid">

                <div class="summary-card">
                    送信対象者数
                    <strong>
                        ${sentCustomers.length}
                    </strong>
                </div>

                <div class="summary-card">
                    回答数
                    <strong>
                        ${responses.length}
                    </strong>
                </div>

                <div class="summary-card">
                    未登録回答数
                    <strong>
                        ${responses.length - registered}
                    </strong>
                </div>

                <div class="summary-card">
                    未回答数
                    <strong>
                        ${Math.max(
                            0,
                            sentCustomers.length -
                            responses.length
                        )}
                    </strong>
                </div>

                <div class="summary-card">
                    回答率
                    <strong>
                        ${answerRate}%
                    </strong>
                </div>

            </div>

            <br>

            <div class="card">

                <div class="toolbar">

                    <button
                        onclick="
                            App.selectAllQuestions()
                        "
                    >
                        すべて選択
                    </button>

                    <button
                        onclick="
                            App.clearQuestions()
                        "
                    >
                        すべて解除
                    </button>

                    <button
                        onclick="
                            App.csvExport()
                        "
                    >
                        CSV出力
                    </button>

                    <button
                        onclick="
                            App.pdfExport()
                        "
                    >
                        PDF出力
                    </button>

                </div>

            </div>

            ${survey.groups.map(
                g =>
                    g.questions
                        .map(
                            q =>
                                this.aggregateQuestion(
                                    q,
                                    responses
                                )
                        )
                        .join("")
            ).join("")}

            <div class="card">

                <h2>
                    個別回答
                </h2>

                ${responses.map(
                    r =>
                        `
                            <details>
                                <summary>
                                    ${escapeHtml(
                                        r.respondent?.name
                                            || "未登録回答者"
                                    )}
                                    /
                                    ${escapeHtml(
                                        this.date(
                                            r.submittedAt
                                        )
                                    )}
                                </summary>

                                <pre
                                    style="
                                        white-space:pre-wrap
                                    "
                                >${escapeHtml(
                                    JSON.stringify(
                                        r.answers,
                                        null,
                                        2
                                    )
                                )}</pre>

                            </details>
                        `
                ).join("")}

            </div>
        `;
    },

    allQuestionIds(survey) {

        const ids = [];

        survey.groups.forEach(
            g =>
                g.questions.forEach(
                    q =>
                        ids.push(q.id)
                )
        );

        return ids;
    },

    selectAllQuestions() {

        State.aggregateSelected =
            new Set(
                this.allQuestionIds(
                    State.aggregateSurvey
                )
            );

        this.renderAggregate();
    },

    clearQuestions() {

        State.aggregateSelected =
            new Set();

        this.renderAggregate();
    },

    aggregateQuestion(
        q,
        responses
    ) {

        const checked =
            State.aggregateSelected
                .has(q.id);

        if (!checked) {
            return "";
        }

        if (q.type === "text") {

            return `
                <div class="card">

                    <h3>
                        ${escapeHtml(
                            q.questionNumber
                        )}
                        ${escapeHtml(
                            q.text
                        )}
                    </h3>

                    ${
                        responses.map(
                            r => `
                                <div
                                    class="card"
                                >
                                    <strong>
                                        ${escapeHtml(
                                            r.respondent
                                                ?.name
                                                || "未登録"
                                        )}
                                    </strong>

                                    <p>
                                        ${escapeHtml(
                                            this.answerText(
                                                r,
                                                q
                                            )
                                        )}
                                    </p>
                                </div>
                            `
                        ).join("")
                    }

                </div>
            `;
        }

        const counts = {};

        q.choices.forEach(
            c =>
                counts[c.id] = 0
        );

        responses.forEach(
            r => {

                const value =
                    r.answers?.[q.id];

                if (
                    Array.isArray(value)
                ) {
                    value.forEach(
                        id => {
                            if (
                                counts[id]
                                    !== undefined
                            ) {
                                counts[id]++;
                            }
                        }
                    );
                } else if (
                    value &&
                    counts[value]
                        !== undefined
                ) {
                    counts[value]++;
                }
            }
        );

        const total =
            responses.length;

        return `
            <div class="card">

                <h3>
                    ${escapeHtml(
                        q.questionNumber
                    )}
                    ${escapeHtml(
                        q.text
                    )}
                </h3>

                ${
                    q.choices.map(
                        c => {

                            const count =
                                counts[c.id]
                                    || 0;

                            const rate =
                                total
                                    ? Math.round(
                                        count /
                                        total *
                                        100
                                    )
                                    : 0;

                            return `
                                <div
                                    style="margin:12px 0"
                                >
                                    <div>
                                        ${escapeHtml(
                                            c.label
                                        )}
                                        /
                                        ${count}件
                                        /
                                        ${rate}%
                                    </div>

                                    <div class="bar">
                                        <div
                                            style="
                                                width:${rate}%
                                            "
                                        ></div>
                                    </div>
                                </div>
                            `;
                        }
                    ).join("")
                }

            </div>
        `;
    },

    answerText(
        response,
        question
    ) {

        const value =
            response.answers?.[
                question.id
            ];

        if (Array.isArray(value)) {

            return value.map(
                id => {
                    const c =
                        question.choices
                            .find(
                                x =>
                                    x.id === id
                            );

                    return c
                        ? c.label
                        : id;
                }
            ).join(", ");
        }

        const c =
            question.choices
                ?.find(
                    x =>
                        x.id === value
                );

        return c
            ? c.label
            : String(value ?? "");
    },

    csvExport() {

        const survey =
            State.aggregateSurvey;

        const rows = [
            [
                "回答日時",
                "回答者",
                "質問番号",
                "質問",
                "回答"
            ]
        ];

        State.responses
            .filter(
                r =>
                    r.surveyId ===
                    survey.id
            )
            .forEach(
                r => {

                    survey.groups
                        .forEach(
                            g =>
                                g.questions
                                    .forEach(
                                        q =>
                                            rows.push([
                                                r.submittedAt,
                                                r.respondent
                                                    ?.name
                                                    || "",
                                                q.questionNumber,
                                                q.text,
                                                this.answerText(
                                                    r,
                                                    q
                                                )
                                            ])
                                    )
                        );
                }
            );

        const csv =
            rows.map(
                row =>
                    row.map(
                        value =>
                            '"' +
                            String(value ?? "")
                                .replaceAll(
                                    '"',
                                    '""'
                                ) +
                            '"'
                    ).join(",")
            ).join("\r\n");

        const blob =
            new Blob(
                ["\uFEFF" + csv],
                {
                    type:
                        "text/csv;charset=utf-8"
                }
            );

        const url =
            URL.createObjectURL(
                blob
            );

        const a =
            document.createElement(
                "a"
            );

        a.href = url;

        a.download =
            "survey-result.csv";

        a.click();

        URL.revokeObjectURL(
            url
        );

        toast(
            "CSV出力を実行しました。"
        );
    },

    pdfExport() {

        toast(
            "PDF出力操作を実行しました。印刷ダイアログからPDF保存できます。"
        );

        window.print();
    },


    /* ========================================================
     * kintone
     * ====================================================== */

    renderKintone() {

        const root =
            document.getElementById(
                "app"
            );

        const k =
            State.kintone || {};

        const fields =
            k.fields || [];

        root.innerHTML = `
            <div class="page-title">

                <h1>
                    kintone連携設定
                </h1>

                <button
                    onclick="App.show('list')"
                >
                    一覧へ戻る
                </button>

            </div>

            <div class="card">

                <div class="form-grid">

                    <div>
                        <label>
                            サブドメイン
                        </label>

                        <input
                            id="kSubdomain"
                            value="${escapeHtml(
                                k.subdomain
                                    || ""
                            )}"
                            placeholder="
                                xxxx / xxxx.cybozu.com
                            "
                        >
                    </div>

                    <div>
                        <label>
                            顧客管理アプリID
                        </label>

                        <input
                            id="kAppId"
                            value="${escapeHtml(
                                k.appId
                                    || ""
                            )}"
                        >
                    </div>

                    <div>
                        <label>
                            ログイン名
                        </label>

                        <input
                            id="kLoginName"
                            value="${escapeHtml(
                                k.loginName
                                    || ""
                            )}"
                        >
                    </div>

                    <div>
                        <label>
                            パスワード
                        </label>

                        <input
                            id="kPassword"
                            type="password"
                            value="${escapeHtml(
                                k.password
                                    || ""
                            )}"
                        >
                    </div>

                    <div>
                        <label>
                            SSL証明書検証
                        </label>

                        <select id="kSsl">
                            <option
                                value="false"
                                ${!k.sslVerify
                                    ? "selected" : ""}
                            >
                                検証しない
                            </option>

                            <option
                                value="true"
                                ${k.sslVerify
                                    ? "selected" : ""}
                            >
                                検証する
                            </option>
                        </select>
                    </div>

                    <div>
                        <label>
                            プロキシ
                        </label>

                        <input
                            id="kProxy"
                            value="${escapeHtml(
                                k.proxy
                                    || ""
                            )}"
                            placeholder="
                                proxy.example.local:8080
                            "
                        >
                    </div>

                </div>

                <br>

                <div class="toolbar">

                    <button
                        class="primary"
                        onclick="
                            App.saveKintone()
                        "
                    >
                        設定を保存
                    </button>

                    <button
                        onclick="
                            App.testKintone()
                        "
                    >
                        接続テスト
                    </button>

                    <button
                        onclick="
                            App.fetchKintoneFields()
                        "
                    >
                        項目一覧を再取得
                    </button>

                    <button
                        onclick="
                            App.syncKintone()
                        "
                    >
                        顧客情報を同期
                    </button>

                </div>

            </div>

            <div class="card">

                <h2>
                    フィールドマッピング
                </h2>

                ${this.kintoneMapping()}

            </div>

            <div class="card">

                <h2>
                    kintoneフィールド
                </h2>

                <div class="table-wrap">

                    <table>

                        <thead>
                            <tr>
                                <th>フィールドコード</th>
                                <th>ラベル</th>
                                <th>タイプ</th>
                            </tr>
                        </thead>

                        <tbody>

                            ${fields.map(
                                f => `
                                    <tr>
                                        <td>
                                            ${escapeHtml(
                                                f.code || ""
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                f.label || ""
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                f.type || ""
                                            )}
                                        </td>
                                    </tr>
                                `
                            ).join("")}

                        </tbody>

                    </table>

                </div>

            </div>
        `;
    },

    kintoneMapping() {

        const k =
            State.kintone || {};

        const fields =
            k.fields || [];

        const mapping =
            k.mapping || {};

        const select =
            (
                value = "",
                multiple = false
            ) => {

                if (multiple) {

                    return fields.map(
                        f => `
                            <label>
                                <input
                                    type="checkbox"
                                    style="width:auto"
                                    value="${escapeHtml(
                                        f.code
                                    )}"
                                    ${
                                        (
                                            mapping.address
                                                || []
                                        ).includes(
                                            f.code
                                        )
                                            ? "checked"
                                            : ""
                                    }
                                    onchange="
                                        App.setAddressMapping(
                                            '${f.code}',
                                            this.checked
                                        )
                                    "
                                >
                                ${escapeHtml(
                                    f.label ||
                                    f.code
                                )}
                            </label>
                        `
                    ).join("");
                }

                return `
                    <select
                        onchange="
                            App.setMapping(
                                '${value}',
                                this.value
                            )
                        "
                    >
                        <option value="">
                            未設定
                        </option>

                        ${fields.map(
                            f => `
                                <option
                                    value="${escapeHtml(
                                        f.code
                                    )}"
                                    ${
                                        mapping[value]
                                            === f.code
                                                ? "selected"
                                                : ""
                                    }
                                >
                                    ${escapeHtml(
                                        f.label ||
                                        f.code
                                    )}
                                </option>
                            `
                        ).join("")}
                    </select>
                `;
            };

        return `
            <div class="form-grid">

                <div>
                    <label>組織名</label>
                    ${select("organization")}
                </div>

                <div>
                    <label>氏名</label>
                    ${select("name")}
                </div>

                <div>
                    <label>メールアドレス</label>
                    ${select("email")}
                </div>

                <div>
                    <label>部署名</label>
                    ${select("department")}
                </div>

                <div>
                    <label>電話番号</label>
                    ${select("phone")}
                </div>

                <div class="full">
                    <label>
                        住所
                    </label>

                    ${select("", true)}
                </div>

            </div>
        `;
    },

    setMapping(
        key,
        value
    ) {

        State.kintone.mapping =
            State.kintone.mapping
                || {};

        State.kintone.mapping[key] =
            value;
    },

    setAddressMapping(
        code,
        checked
    ) {

        const mapping =
            State.kintone.mapping
                || {};

        mapping.address =
            mapping.address
                || [];

        if (checked) {

            if (
                !mapping.address
                    .includes(code)
            ) {
                mapping.address.push(
                    code
                );
            }

        } else {

            mapping.address =
                mapping.address.filter(
                    x => x !== code
                );
        }

        State.kintone.mapping =
            mapping;
    },

    async saveKintone() {

        const data = {
            subdomain:
                document.getElementById(
                    "kSubdomain"
                ).value,

            appId:
                document.getElementById(
                    "kAppId"
                ).value,

            loginName:
                document.getElementById(
                    "kLoginName"
                ).value,

            password:
                document.getElementById(
                    "kPassword"
                ).value,

            sslVerify:
                document.getElementById(
                    "kSsl"
                ).value === "true",

            proxy:
                document.getElementById(
                    "kProxy"
                ).value,

            mapping:
                State.kintone.mapping
                    || {}
        };

        const result =
            await api(
                "saveKintone",
                data
            );

        State.kintone =
            result.settings;

        toast(
            "kintone設定を保存しました。"
        );
    },

    async testKintone() {

        try {

            const result =
                await api(
                    "testKintone"
                );

            toast(
                result.message ||
                "接続成功"
            );

        } catch (e) {
            toast(
                e.message
            );
        }
    },

    async fetchKintoneFields() {

        try {

            const result =
                await api(
                    "fetchKintoneFields"
                );

            State.kintone.fields =
                result.fields;

            this.renderKintone();

            toast(
                "項目一覧を再取得しました。"
            );

        } catch (e) {
            toast(e.message);
        }
    },

    async syncKintone() {

        try {

            const result =
                await api(
                    "syncKintone"
                );

            const data =
                await api(
                    "bootstrap"
                );

            State.customers =
                data.customers;

            toast(
                result.message
                    || "顧客同期完了"
            );

        } catch (e) {
            toast(e.message);
        }
    },


    /* ========================================================
     * メール設定
     * ====================================================== */

    renderMail() {

        const root =
            document.getElementById(
                "app"
            );

        const m =
            State.mail || {};

        root.innerHTML = `
            <div class="page-title">

                <h1>
                    メールサーバ設定
                </h1>

                <button
                    onclick="App.show('list')"
                >
                    一覧へ戻る
                </button>

            </div>

            <div class="card">

                <div class="form-grid">

                    <div>
                        <label>
                            SMTPサーバ
                        </label>

                        <input
                            id="smtpServer"
                            value="${escapeHtml(
                                m.smtpServer
                                    || ""
                            )}"
                        >
                    </div>

                    <div>
                        <label>
                            SMTPポート
                        </label>

                        <input
                            id="smtpPort"
                            type="number"
                            value="${m.smtpPort || 587}"
                        >
                    </div>

                    <div>
                        <label>
                            暗号化方式
                        </label>

                        <select
                            id="smtpEncryption"
                        >
                            <option
                                value="none"
                                ${m.encryption === "none"
                                    ? "selected" : ""}
                            >
                                なし
                            </option>

                            <option
                                value="starttls"
                                ${m.encryption === "starttls"
                                    ? "selected" : ""}
                            >
                                STARTTLS
                            </option>

                            <option
                                value="ssl"
                                ${m.encryption === "ssl"
                                    ? "selected" : ""}
                            >
                                SSL/TLS
                            </option>
                        </select>
                    </div>

                    <div>
                        <label>
                            SMTP認証
                        </label>

                        <label>
                            <input
                                id="smtpAuth"
                                type="checkbox"
                                style="width:auto"
                                ${m.authentication
                                    ? "checked" : ""}
                            >
                            認証を使用する
                        </label>
                    </div>

                    <div>
                        <label>
                            SMTPユーザー名
                        </label>

                        <input
                            id="smtpUsername"
                            value="${escapeHtml(
                                m.username
                                    || ""
                            )}"
                        >
                    </div>

                    <div>
                        <label>
                            SMTPパスワード
                        </label>

                        <input
                            id="smtpPassword"
                            type="password"
                            value="${escapeHtml(
                                m.password
                                    || ""
                            )}"
                        >
                    </div>

                    <div>
                        <label>
                            送信元メールアドレス
                        </label>

                        <input
                            id="fromEmail"
                            value="${escapeHtml(
                                m.fromEmail
                                    || ""
                            )}"
                        >
                    </div>

                    <div>
                        <label>
                            送信元名
                        </label>

                        <input
                            id="fromName"
                            value="${escapeHtml(
                                m.fromName
                                    || ""
                            )}"
                        >
                    </div>

                    <div>
                        <label>
                            返信先メールアドレス
                        </label>

                        <input
                            id="replyTo"
                            value="${escapeHtml(
                                m.replyTo
                                    || ""
                            )}"
                        >
                    </div>

                </div>

                <br>

                <p>
                    接続状態:
                    <strong>
                        ${escapeHtml(
                            m.connectionStatus
                                || "未設定"
                        )}
                    </strong>
                </p>

                ${
                    m.lastError
                        ? `
                            <div class="error-panel">
                                ${escapeHtml(
                                    m.lastError
                                )}
                            </div>
                        `
                        : ""
                }

                <br>

                <div class="toolbar">

                    <button
                        class="primary"
                        onclick="App.saveMail()"
                    >
                        設定を保存
                    </button>

                    <input
                        id="testMailTo"
                        style="max-width:320px"
                        placeholder="テスト送信先"
                    >

                    <button
                        onclick="App.testMail()"
                    >
                        テストメール
                    </button>

                </div>

            </div>
        `;
    },

    async saveMail() {

        const data = {
            smtpServer:
                document.getElementById(
                    "smtpServer"
                ).value,

            smtpPort:
                Number(
                    document.getElementById(
                        "smtpPort"
                    ).value
                ),

            encryption:
                document.getElementById(
                    "smtpEncryption"
                ).value,

            authentication:
                document.getElementById(
                    "smtpAuth"
                ).checked,

            username:
                document.getElementById(
                    "smtpUsername"
                ).value,

            password:
                document.getElementById(
                    "smtpPassword"
                ).value,

            fromEmail:
                document.getElementById(
                    "fromEmail"
                ).value,

            fromName:
                document.getElementById(
                    "fromName"
                ).value,

            replyTo:
                document.getElementById(
                    "replyTo"
                ).value
        };

        try {

            const result =
                await api(
                    "saveMail",
                    data
                );

            State.mail =
                result.mail;

            toast(
                "メール設定を保存しました。"
            );

        } catch (e) {
            toast(e.message);
        }
    },

    async testMail() {

        const to =
            document.getElementById(
                "testMailTo"
            ).value.trim();

        if (!to) {
            toast(
                "テスト送信先を入力してください。"
            );

            return;
        }

        try {

            const result =
                await api(
                    "testMail",
                    {to}
                );

            toast(
                result.message ||
                "テストメール送信成功"
            );

            const data =
                await api(
                    "bootstrap"
                );

            State.mail =
                data.mail;

            this.renderMail();

        } catch (e) {

            toast(
                e.message
            );

            const data =
                await api(
                    "bootstrap"
                ).catch(
                    () => null
                );

            if (data) {
                State.mail =
                    data.mail;

                this.renderMail();
            }
        }
    }
};


/* ============================================================
 * 編集用クライアントID
 * ========================================================== */

function makeQuestionClient() {

    return {
        id:
            "question-" +
            crypto.randomUUID(),

        groupId: "",

        sortOrder: 1,

        questionNumber: "",

        text: "",

        type: "single",

        required: false,

        choices: [
            {
                id:
                    "choice-" +
                    crypto.randomUUID(),

                label: "選択肢1",

                sortOrder: 1
            }
        ],

        branches: []
    };
}


/* ============================================================
 * 回答者アプリ
 *
 * 管理者Appとは完全に別のUI。
 * 管理者ヘッダーはこの分岐では描画されない。
 * ========================================================== */

const AnswerApp = {

    async init() {

        const context =
            window.__ANSWER_CONTEXT__;

        const root =
            document.getElementById(
                "answerApp"
            );

        try {

            if (!context.surveyId) {
                throw new Error(
                    "アンケートが指定されていません。"
                );
            }

            const data =
                await api(
                    "bootstrap"
                );

            const survey =
                data.surveys.find(
                    s =>
                        s.id ===
                        context.surveyId
                );

            if (!survey) {
                throw new Error(
                    "アンケートが存在しません。"
                );
            }

            if (
                survey.status !==
                "published"
            ) {
                throw new Error(
                    "現在このアンケートには回答できません。"
                );
            }

            const existing =
                data.responses.find(
                    r =>
                        r.surveyId ===
                            context.surveyId &&
                        context.token &&
                        r.individualToken ===
                            context.token &&
                        r.status ===
                            "completed"
                );

            if (
                existing &&
                !survey.allowResubmission
            ) {

                root.innerHTML = `
                    <div class="answer-container">

                        <div class="answer-card">
                            <h1>
                                回答済み
                            </h1>

                            <p>
                                このアンケートはすでに回答済みです。
                            </p>
                        </div>

                    </div>
                `;

                return;
            }

            State.answer.survey =
                survey;

            State.answer.surveyId =
                context.surveyId;

            State.answer.token =
                context.token;

            State.answer.values = {};

            State.answer.respondent = {};

            this.renderAnswer();

        } catch (e) {

            root.innerHTML = `
                <div class="answer-container">

                    <div class="answer-card">
                        <h1>
                            アンケートを表示できません
                        </h1>

                        <div class="error-panel">
                            ${escapeHtml(
                                e.message
                            )}
                        </div>
                    </div>

                </div>
            `;
        }
    },

    questions() {

        const result = [];

        State.answer.survey.groups
            .forEach(
                g =>
                    g.questions.forEach(
                        q =>
                            result.push(q)
                    )
            );

        return result;
    },

    visibleQuestions() {

        const all =
            this.questions();

        const visible = [];

        /*
         * 最初は全質問。
         * 分岐で除外する。
         *
         * 内部IDで判定するため、
         * questionNumber変更の影響を受けない。
         */
        const hidden =
            new Set();

        all.forEach(
            q => {

                const answer =
                    State.answer
                        .values[q.id];

                if (
                    q.type !==
                    "single"
                ) {
                    return;
                }

                if (!answer) {
                    return;
                }

                const branch =
                    (q.branches || [])
                        .find(
                            b =>
                                b.choiceId ===
                                answer
                        );

                if (
                    branch &&
                    branch.nextQuestionId
                ) {

                    let found = false;

                    for (
                        const candidate
                        of all
                    ) {

                        if (found) {
                            hidden.add(
                                candidate.id
                            );
                        }

                        if (
                            candidate.id ===
                            branch.nextQuestionId
                        ) {
                            found = true;
                        }
                    }
                }
            }
        );

        return all.filter(
            q =>
                !hidden.has(q.id)
        );
    },

    renderAnswer() {

        const root =
            document.getElementById(
                "answerApp"
            );

        const survey =
            State.answer.survey;

        const questions =
            this.visibleQuestions();

        State.answer.visible =
            questions;

        root.innerHTML = `
            <div class="answer-container">

                <div class="answer-card">

                    <h1>
                        ${escapeHtml(
                            survey.title
                        )}
                    </h1>

                    <p>
                        ${escapeHtml(
                            survey.description
                        )}
                    </p>

                </div>

                <div class="answer-card">

                    <h2>
                        回答者情報
                    </h2>

                    <div class="form-grid">

                        <div>
                            <label>
                                組織名
                            </label>

                            <input
                                value="${escapeHtml(
                                    State.answer.respondent.organization
                                        || ""
                                )}"
                                oninput="
                                    AnswerApp.setRespondent(
                                        'organization',
                                        this.value
                                    )
                                "
                            >
                        </div>

                        <div>
                            <label>
                                氏名
                            </label>

                            <input
                                value="${escapeHtml(
                                    State.answer.respondent.name
                                        || ""
                                )}"
                                oninput="
                                    AnswerApp.setRespondent(
                                        'name',
                                        this.value
                                    )
                                "
                            >
                        </div>

                        <div>
                            <label>
                                メールアドレス
                            </label>

                            <input
                                type="email"
                                value="${escapeHtml(
                                    State.answer.respondent.email
                                        || ""
                                )}"
                                oninput="
                                    AnswerApp.setRespondent(
                                        'email',
                                        this.value
                                    )
                                "
                            >
                        </div>

                        <div>
                            <label>
                                部署名
                            </label>

                            <input
                                value="${escapeHtml(
                                    State.answer.respondent.department
                                        || ""
                                )}"
                                oninput="
                                    AnswerApp.setRespondent(
                                        'department',
                                        this.value
                                    )
                                "
                            >
                        </div>

                        <div>
                            <label>
                                電話番号
                            </label>

                            <input
                                value="${escapeHtml(
                                    State.answer.respondent.phone
                                        || ""
                                )}"
                                oninput="
                                    AnswerApp.setRespondent(
                                        'phone',
                                        this.value
                                    )
                                "
                            >
                        </div>

                        <div>
                            <label>
                                住所
                            </label>

                            <input
                                value="${escapeHtml(
                                    State.answer.respondent.address
                                        || ""
                                )}"
                                oninput="
                                    AnswerApp.setRespondent(
                                        'address',
                                        this.value
                                    )
                                "
                            >
                        </div>

                    </div>

                </div>

                ${
                    questions.map(
                        q =>
                            this.renderQuestion(q)
                    ).join("")
                }

                <div class="answer-card">

                    <div class="answer-actions">

                        <div></div>

                        <button
                            class="primary"
                            onclick="
                                AnswerApp.confirm()
                            "
                        >
                            回答内容を確認
                        </button>

                    </div>

                </div>

            </div>
        `;
    },

    setRespondent(
        field,
        value
    ) {

        State.answer
            .respondent[field] =
            value;
    },

    renderQuestion(q) {

        const value =
            State.answer.values[
                q.id
            ];

        return `
            <div class="answer-card">

                <div>
                    <span
                        class="question-number"
                    >
                        ${escapeHtml(
                            q.questionNumber
                        )}
                    </span>

                    <h2
                        style="display:inline"
                    >
                        ${escapeHtml(
                            q.text
                        )}
                    </h2>

                    ${
                        q.required
                            ? `
                                <span
                                    style="
                                        color:#dc2626;
                                        margin-left:8px
                                    "
                                >
                                    必須
                                </span>
                            `
                            : ""
                    }
                </div>

                <br>

                ${
                    q.type === "text"
                        ? `
                            <textarea
                                oninput="
                                    AnswerApp.setValue(
                                        '${q.id}',
                                        this.value
                                    )
                                "
                            >${escapeHtml(
                                value || ""
                            )}</textarea>
                        `
                        : q.choices.map(
                            c =>
                                `
                                    <label
                                        class="answer-choice"
                                    >

                                        <input
                                            type="${
                                                q.type === "single"
                                                    ? "radio"
                                                    : "checkbox"
                                            }"
                                            name="q_${q.id}"
                                            value="${c.id}"
                                            ${
                                                q.type === "single"
                                                    ? (
                                                        value ===
                                                        c.id
                                                            ? "checked"
                                                            : ""
                                                    )
                                                    : (
                                                        Array.isArray(
                                                            value
                                                        ) &&
                                                        value.includes(
                                                            c.id
                                                        )
                                                            ? "checked"
                                                            : ""
                                                    )
                                            }
                                            onchange="
                                                AnswerApp.changeChoice(
                                                    '${q.id}',
                                                    '${c.id}',
                                                    this.checked,
                                                    '${q.type}'
                                                )
                                            "
                                        >

                                        ${escapeHtml(
                                            c.label
                                        )}

                                    </label>
                                `
                        ).join("")
                }

            </div>
        `;
    },

    setValue(
        questionId,
        value
    ) {

        State.answer.values[
            questionId
        ] = value;
    },

    changeChoice(
        questionId,
        choiceId,
        checked,
        type
    ) {

        if (
            type ===
            "single"
        ) {

            State.answer.values[
                questionId
            ] = choiceId;

        } else {

            const current =
                Array.isArray(
                    State.answer.values[
                        questionId
                    ]
                )
                    ? [
                        ...State.answer.values[
                            questionId
                        ]
                    ]
                    : [];

            if (checked) {

                if (
                    !current.includes(
                        choiceId
                    )
                ) {
                    current.push(
                        choiceId
                    );
                }

            } else {

                const index =
                    current.indexOf(
                        choiceId
                    );

                if (index >= 0) {
                    current.splice(
                        index,
                        1
                    );
                }
            }

            State.answer.values[
                questionId
            ] = current;
        }

        /*
         * 条件分岐変更時に再描画。
         */
        this.renderAnswer();
    },

    validate() {

        const errors = [];

        const questions =
            this.visibleQuestions();

        questions.forEach(
            q => {

                if (!q.required) {
                    return;
                }

                const value =
                    State.answer.values[
                        q.id
                    ];

                const empty =
                    value === undefined ||
                    value === null ||
                    value === "" ||
                    (
                        Array.isArray(value) &&
                        value.length === 0
                    );

                if (empty) {
                    errors.push(
                        q.questionNumber +
                        " " +
                        q.text
                    );
                }
            }
        );

        return errors;
    },

    confirm() {

        const errors =
            this.validate();

        if (errors.length) {

            toast(
                "必須回答が未入力です:\n" +
                errors.join("\n")
            );

            return;
        }

        this.renderConfirm();
    },

    renderConfirm() {

        const root =
            document.getElementById(
                "answerApp"
            );

        const questions =
            this.visibleQuestions();

        root.innerHTML = `
            <div class="answer-container">

                <div class="answer-card">

                    <h1>
                        回答内容確認
                    </h1>

                    <p>
                        送信前に回答内容を確認してください。
                    </p>

                </div>

                ${
                    questions.map(
                        q => `
                            <div class="answer-card">

                                <div>
                                    <span
                                        class="question-number"
                                    >
                                        ${escapeHtml(
                                            q.questionNumber
                                        )}
                                    </span>

                                    <h3>
                                        ${escapeHtml(
                                            q.text
                                        )}
                                    </h3>
                                </div>

                                <p>
                                    ${escapeHtml(
                                        this.answerText(
                                            q
                                        )
                                    )}
                                </p>

                                <button
                                    onclick="
                                        AnswerApp.renderAnswer()
                                    "
                                >
                                    修正
                                </button>

                            </div>
                        `
                    ).join("")
                }

                <div class="answer-card">

                    <h3>
                        回答者情報
                    </h3>

                    <pre
                        style="white-space:pre-wrap"
                    >${escapeHtml(
                        JSON.stringify(
                            State.answer.respondent,
                            null,
                            2
                        )
                    )}</pre>

                    <div class="answer-actions">

                        <button
                            onclick="
                                AnswerApp.renderAnswer()
                            "
                        >
                            戻る
                        </button>

                        <button
                            class="primary"
                            onclick="
                                AnswerApp.submitConfirm()
                            "
                        >
                            回答を送信
                        </button>

                    </div>

                </div>

            </div>
        `;
    },

    answerText(q) {

        const value =
            State.answer.values[
                q.id
            ];

        if (Array.isArray(value)) {

            return value.map(
                id => {

                    const c =
                        q.choices.find(
                            x =>
                                x.id === id
                        );

                    return c
                        ? c.label
                        : id;
                }
            ).join(", ");
        }

        const c =
            q.choices?.find(
                x =>
                    x.id === value
            );

        return c
            ? c.label
            : String(value ?? "");
    },

    submitConfirm() {

        confirmModal(
            "この回答を送信しますか？",
            async () => {

                try {

                    const result =
                        await api(
                            "submitResponse",
                            {
                                surveyId:
                                    State.answer.surveyId,

                                token:
                                    State.answer.token,

                                answers:
                                    State.answer.values,

                                respondent:
                                    State.answer.respondent
                            }
                        );

                    this.renderComplete(
                        result
                    );

                } catch (e) {

                    /*
                     * 回答者画面では
                     * 管理者UIへ遷移させない。
                     */
                    const root =
                        document.getElementById(
                            "answerApp"
                        );

                    root.innerHTML = `
                        <div
                            class="answer-container"
                        >
                            <div
                                class="answer-card"
                            >
                                <h1>
                                    送信できませんでした
                                </h1>

                                <div
                                    class="error-panel"
                                >
                                    ${escapeHtml(
                                        e.message
                                    )}
                                </div>

                                <br>

                                <button
                                    onclick="
                                        AnswerApp.renderConfirm()
                                    "
                                >
                                    回答確認へ戻る
                                </button>

                            </div>
                        </div>
                    `;
                }
            }
        );
    },

    renderComplete() {

        const root =
            document.getElementById(
                "answerApp"
            );

        root.innerHTML = `
            <div class="answer-container">

                <div class="answer-card">

                    <h1>
                        回答完了
                    </h1>

                    <p>
                        アンケートの回答を受け付けました。
                    </p>

                    <p>
                        ご回答ありがとうございました。
                    </p>

                </div>

            </div>
        `;
    }
};


/* ============================================================
 * 初期化
 * ========================================================== */

<?php if ($isAnswerView): ?>

AnswerApp.init();

<?php else: ?>

App.init();

<?php endif; ?>

</script>

</body>
</html>
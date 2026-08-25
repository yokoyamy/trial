<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 *
 * 必要ファイル:
 *   index.php
 *
 * 初回アクセス時に自動生成:
 *   data/surveys.json
 *   data/customers.json
 *   data/responses.json
 *   data/send_history.json
 *   data/kintone.json
 *   data/mail.json
 *
 * PHP 8.5 / Apache 2.4
 *
 * 管理者認証: 実装しない
 * 回答者認証: 実装しない
 *
 * 注意:
 * - kintone APIは実通信
 * - SMTPは実通信
 * - JSONを永続データとして利用
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const DATA_FILES = [
    'surveys'     => DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json',
    'customers'   => DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json',
    'responses'   => DATA_DIR . DIRECTORY_SEPARATOR . 'responses.json',
    'sendHistory' => DATA_DIR . DIRECTORY_SEPARATOR . 'send_history.json',
    'kintone'     => DATA_DIR . DIRECTORY_SEPARATOR . 'kintone.json',
    'mail'        => DATA_DIR . DIRECTORY_SEPARATOR . 'mail.json',
];

/* ============================================================
 * 共通
 * ========================================================== */

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}

function nowIso(): string
{
    return date('c');
}

function uid(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(12));
}

function h(mixed $v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function inputData(): array
{
    $raw = file_get_contents('php://input');

    if ($raw !== false && trim($raw) !== '') {
        $json = json_decode($raw, true);

        if (is_array($json)) {
            return $json;
        }
    }

    return $_POST;
}

function jsonOut(
    array $data,
    int $status = 200
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function readJson(
    string $key,
    mixed $default = []
): mixed {
    if (!isset(DATA_FILES[$key])) {
        return $default;
    }

    $file = DATA_FILES[$key];

    if (!is_file($file)) {
        return $default;
    }

    $fp = @fopen($file, 'rb');

    if (!$fp) {
        return $default;
    }

    $content = '';

    try {
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return $default;
        }

        $content = stream_get_contents($fp);

        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($content === false || trim($content) === '') {
        return $default;
    }

    $decoded = json_decode($content, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $decoded
        : $default;
}

function writeJson(
    string $key,
    mixed $data
): bool {
    if (!isset(DATA_FILES[$key])) {
        return false;
    }

    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0775, true)) {
        return false;
    }

    $file = DATA_FILES[$key];

    $fp = @fopen($file, 'c+b');

    if (!$fp) {
        return false;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

        if ($json === false) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        ftruncate($fp, 0);
        rewind($fp);

        $length = strlen($json);
        $written = fwrite($fp, $json);

        fflush($fp);
        flock($fp, LOCK_UN);

        return $written === $length;
    } finally {
        fclose($fp);
    }
}

function strv(mixed $v): string
{
    return trim((string)$v);
}

function boolv(mixed $v): bool
{
    return filter_var($v, FILTER_VALIDATE_BOOLEAN);
}

/* ============================================================
 * 初期設定
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

/* ============================================================
 * アンケート初期データ
 * ========================================================== */

function makeChoice(
    string $label,
    int $order
): array {
    return [
        'id' => uid('choice_'),
        'label' => $label,
        'sortOrder' => $order,
    ];
}

function makeQuestion(
    string $text,
    string $type,
    bool $required,
    int $order,
    string $groupId,
    array $choices = []
): array {
    return [
        'id' => uid('question_'),
        'groupId' => $groupId,
        'sortOrder' => $order,
        'questionNumber' => '',
        'text' => $text,
        'type' => $type,
        'required' => $required,
        'choices' => $choices,
        'branches' => [],
    ];
}

function makeGroup(
    string $title,
    int $order
): array {
    return [
        'id' => uid('group_'),
        'title' => $title,
        'sortOrder' => $order,
        'questions' => [],
    ];
}

function recalcNumbers(
    array &$survey
): void {
    $mode = ($survey['questionNumberMode'] ?? 'all') === 'group'
        ? 'group'
        : 'all';

    $global = 0;
    $groupNo = 0;

    foreach ($survey['groups'] as &$group) {
        $groupNo++;
        $questionNo = 0;

        foreach ($group['questions'] as &$question) {
            $questionNo++;
            $global++;

            $question['groupId'] = $group['id'];
            $question['sortOrder'] = $questionNo;

            if ($mode === 'group') {
                $question['questionNumber'] =
                    'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['questionNumber'] =
                    'Q' . $global;
            }

            foreach ($question['choices'] as $i => &$choice) {
                $choice['sortOrder'] = $i + 1;
            }

            unset($choice);
        }

        unset($question);

        $group['sortOrder'] = $groupNo;
    }

    unset($group);
}

function makeSampleSurvey(
    string $title,
    string $status,
    ?string $start,
    ?string $end
): array {
    $survey = [
        'id' => uid('survey_'),
        'title' => $title,
        'description' => '動作確認用のサンプルアンケートです。',
        'startDate' => $start,
        'endDate' => $end,
        'questionNumberMode' => 'all',
        'allowResubmission' => false,
        'status' => $status,
        'groups' => [],
        'createdAt' => nowIso(),
        'updatedAt' => nowIso(),
    ];

    $g1 = makeGroup('基本情報', 1);

    $q1 = makeQuestion(
        '今回のサービスに対する総合評価を教えてください。',
        'single',
        true,
        1,
        $g1['id'],
        [
            makeChoice('とても満足', 1),
            makeChoice('満足', 2),
            makeChoice('普通', 3),
            makeChoice('やや不満', 4),
            makeChoice('不満', 5),
        ]
    );

    $q2 = makeQuestion(
        'ご意見・ご要望があれば入力してください。',
        'text',
        false,
        2,
        $g1['id']
    );

    $g1['questions'] = [$q1, $q2];

    $g2 = makeGroup('追加確認', 2);

    $q3 = makeQuestion(
        '今後も利用したいと思いますか？',
        'single',
        true,
        1,
        $g2['id'],
        [
            makeChoice('はい', 1),
            makeChoice('いいえ', 2),
        ]
    );

    $g2['questions'] = [$q3];

    $survey['groups'] = [$g1, $g2];

    recalcNumbers($survey);

    return $survey;
}

function initializeData(): void
{
    if (!is_file(DATA_FILES['surveys'])) {
        $surveys = [];

        $surveys[] = makeSampleSurvey(
            'サンプルアンケート（下書き）',
            'draft',
            date('c'),
            date('c', strtotime('+30 days'))
        );

        $surveys[] = makeSampleSurvey(
            'サンプルアンケート（公開中）',
            'published',
            date('c', strtotime('-5 days')),
            date('c', strtotime('+30 days'))
        );

        $surveys[] = makeSampleSurvey(
            'サンプルアンケート（停止）',
            'stopped',
            date('c', strtotime('-5 days')),
            date('c', strtotime('+30 days'))
        );

        $surveys[] = makeSampleSurvey(
            'サンプルアンケート（終了）',
            'finished',
            date('c', strtotime('-30 days')),
            date('c', strtotime('-1 day'))
        );

        $surveys[] = makeSampleSurvey(
            '期限経過サンプル（下書き）',
            'draft',
            date('c', strtotime('-5 days')),
            date('c', strtotime('-1 day'))
        );

        $surveys[] = makeSampleSurvey(
            '期限経過サンプル（停止）',
            'stopped',
            date('c', strtotime('-5 days')),
            date('c', strtotime('-1 day'))
        );

        $surveys[] = makeSampleSurvey(
            '期限経過サンプル（公開中→終了）',
            'published',
            date('c', strtotime('-5 days')),
            date('c', strtotime('-1 day'))
        );

        writeJson('surveys', $surveys);
    }

    if (!is_file(DATA_FILES['customers'])) {
        writeJson('customers', [
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
            [
                'id' => 'customer-004',
                'organization' => '未登録企業',
                'name' => '未登録 回答者',
                'email' => 'unregistered@example.com',
                'department' => '',
                'phone' => '',
                'address' => '',
                'status' => '未送信',
                'lastSentAt' => null,
                'sendCount' => 0,
                'kintoneStatus' => '未登録',
            ],
        ]);
    }

    if (!is_file(DATA_FILES['responses'])) {
        writeJson('responses', []);
    }

    if (!is_file(DATA_FILES['sendHistory'])) {
        writeJson('sendHistory', []);
    }

    if (!is_file(DATA_FILES['kintone'])) {
        writeJson('kintone', defaultKintone());
    }

    if (!is_file(DATA_FILES['mail'])) {
        writeJson('mail', defaultMail());
    }
}

initializeData();

/* ============================================================
 * 状態
 * ========================================================== */

function applyAutoFinish(
    array &$surveys
): bool {
    $changed = false;
    $now = time();

    foreach ($surveys as &$survey) {
        if (($survey['status'] ?? '') !== 'published') {
            continue;
        }

        if (empty($survey['endDate'])) {
            continue;
        }

        $end = strtotime((string)$survey['endDate']);

        if ($end !== false && $now > $end) {
            $survey['status'] = 'finished';
            $survey['updatedAt'] = nowIso();
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function findSurveyIndex(
    array $surveys,
    string $id
): int {
    foreach ($surveys as $i => $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function findSurvey(
    array $surveys,
    string $id
): ?array {
    $i = findSurveyIndex($surveys, $id);

    return $i >= 0 ? $surveys[$i] : null;
}

function findQuestion(
    array $survey,
    string $id
): ?array {
    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            if (($question['id'] ?? '') === $id) {
                return $question;
            }
        }
    }

    return null;
}

function surveyResponseCount(
    string $surveyId
): int {
    $responses = readJson('responses', []);

    $n = 0;

    foreach ($responses as $response) {
        if (
            ($response['surveyId'] ?? '') === $surveyId &&
            ($response['status'] ?? '') === 'completed'
        ) {
            $n++;
        }
    }

    return $n;
}

function sanitizeSurvey(
    array $input,
    ?array $existing = null
): array {
    $survey = $existing ?? [
        'id' => uid('survey_'),
        'status' => 'draft',
        'createdAt' => nowIso(),
        'groups' => [],
    ];

    $survey['title'] = strv($input['title'] ?? '');
    $survey['description'] = strv($input['description'] ?? '');

    $survey['startDate'] =
        strv($input['startDate'] ?? '') ?: null;

    $survey['endDate'] =
        strv($input['endDate'] ?? '') ?: null;

    $mode = strv(
        $input['questionNumberMode'] ?? 'all'
    );

    $survey['questionNumberMode'] =
        in_array($mode, ['all', 'group'], true)
        ? $mode
        : 'all';

    $survey['allowResubmission'] =
        boolv($input['allowResubmission'] ?? false);

    $groups = [];

    foreach (($input['groups'] ?? []) as $gi => $g) {
        if (!is_array($g)) {
            continue;
        }

        $groupId =
            strv($g['id'] ?? '') ?: uid('group_');

        $group = [
            'id' => $groupId,
            'title' => strv($g['title'] ?? ''),
            'sortOrder' => $gi + 1,
            'questions' => [],
        ];

        foreach (($g['questions'] ?? []) as $qi => $q) {
            if (!is_array($q)) {
                continue;
            }

            $type = strv($q['type'] ?? 'single');

            if (
                !in_array(
                    $type,
                    ['single', 'multiple', 'text'],
                    true
                )
            ) {
                $type = 'single';
            }

            $questionId =
                strv($q['id'] ?? '') ?: uid('question_');

            $question = [
                'id' => $questionId,
                'groupId' => $groupId,
                'sortOrder' => $qi + 1,
                'questionNumber' => '',
                'text' => strv($q['text'] ?? ''),
                'type' => $type,
                'required' => boolv($q['required'] ?? false),
                'choices' => [],
                'branches' => [],
            ];

            if ($type !== 'text') {
                foreach (($q['choices'] ?? []) as $ci => $c) {
                    if (!is_array($c)) {
                        continue;
                    }

                    $question['choices'][] = [
                        'id' =>
                            strv($c['id'] ?? '') ?:
                            uid('choice_'),
                        'label' => strv($c['label'] ?? ''),
                        'sortOrder' => $ci + 1,
                    ];
                }
            }

            if ($type === 'single') {
                foreach (($q['branches'] ?? []) as $branch) {
                    if (!is_array($branch)) {
                        continue;
                    }

                    $choiceId =
                        strv($branch['choiceId'] ?? '');

                    $nextId =
                        strv($branch['nextQuestionId'] ?? '');

                    if ($choiceId !== '') {
                        $question['branches'][] = [
                            'choiceId' => $choiceId,
                            'nextQuestionId' => $nextId,
                        ];
                    }
                }
            }

            $group['questions'][] = $question;
        }

        $groups[] = $group;
    }

    $survey['groups'] = $groups;
    $survey['updatedAt'] = nowIso();

    recalcNumbers($survey);

    return $survey;
}

/* ============================================================
 * kintone
 * ========================================================== */

function normalizeSubdomain(
    string $value
): ?string {
    $value = trim($value);

    if ($value === '') {
        return null;
    }

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

    if (
        preg_match(
            '/^([a-zA-Z0-9][a-zA-Z0-9-]*)\.cybozu\.com$/',
            $value,
            $m
        )
    ) {
        return strtolower($m[1]);
    }

    if (
        preg_match(
            '/^[a-zA-Z0-9][a-zA-Z0-9-]*$/',
            $value
        )
    ) {
        return strtolower($value);
    }

    return null;
}

function normalizeProxy(
    string $proxy
): ?string {
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (
        !preg_match(
            '/^[^:\s\/]+:\d{1,5}$/',
            $proxy
        )
    ) {
        return null;
    }

    [$host, $port] = explode(':', $proxy, 2);

    $port = (int)$port;

    if ($port < 1 || $port > 65535) {
        return null;
    }

    return $host . ':' . $port;
}

function kintoneSettings(): array
{
    $settings = readJson(
        'kintone',
        defaultKintone()
    );

    return array_merge(
        defaultKintone(),
        is_array($settings) ? $settings : []
    );
}

function kintoneRequest(
    array $settings,
    string $method,
    string $path,
    ?array $body = null
): array {
    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'error' => 'PHP cURL拡張が有効ではありません。',
        ];
    }

    $subdomain = normalizeSubdomain(
        (string)($settings['subdomain'] ?? '')
    );

    if ($subdomain === null) {
        return [
            'success' => false,
            'error' => 'kintoneサブドメインが正しく設定されていません。',
        ];
    }

    $appId = trim(
        (string)($settings['appId'] ?? '')
    );

    if ($appId === '' || !ctype_digit($appId)) {
        return [
            'success' => false,
            'error' => '顧客管理アプリIDが正しく設定されていません。',
        ];
    }

    $login = (string)($settings['loginName'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        return [
            'success' => false,
            'error' => 'kintoneログイン名またはパスワードが未設定です。',
        ];
    }

    $url =
        'https://' .
        $subdomain .
        '.cybozu.com' .
        $path;

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'success' => false,
            'error' => 'cURL初期化に失敗しました。',
        ];
    }

    $verify = boolv(
        $settings['sslVerify'] ?? false
    );

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
        $options[CURLOPT_PROXYAUTH] = CURLAUTH_NONE;
    }

    if ($body !== null) {
        $encoded = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            curl_close($ch);

            return [
                'success' => false,
                'error' => 'リクエストJSON生成に失敗しました。',
            ];
        }

        $options[CURLOPT_POSTFIELDS] = $encoded;
    }

    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($raw === false) {
        return [
            'success' => false,
            'error' =>
                'kintone API通信に失敗しました。' .
                ' cURL #' . $errno . ': ' . $error,
        ];
    }

    $json = json_decode($raw, true);

    if ($http < 200 || $http >= 300) {
        $detail = is_array($json)
            ? json_encode(
                $json,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
            : $raw;

        return [
            'success' => false,
            'error' =>
                'kintone API HTTP ' .
                $http .
                ': ' .
                $detail,
            'httpStatus' => $http,
        ];
    }

    return [
        'success' => true,
        'data' => is_array($json) ? $json : [],
        'httpStatus' => $http,
    ];
}

/* ============================================================
 * SMTP
 * ========================================================== */

function smtpRead(
    $socket
): string {
    $result = '';

    while (!feof($socket)) {
        $line = fgets($socket, 8192);

        if ($line === false) {
            break;
        }

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

function smtpCode(
    string $response
): int {
    return (int)substr(
        trim($response),
        0,
        3
    );
}

function smtpCommand(
    $socket,
    string $command,
    array $expected = [250]
): array {
    if ($command !== '') {
        fwrite(
            $socket,
            $command . "\r\n"
        );
    }

    $response = smtpRead($socket);
    $code = smtpCode($response);

    return [
        'ok' => in_array($code, $expected, true),
        'code' => $code,
        'response' => $response,
    ];
}

function smtpSendMail(
    array $settings,
    string $to,
    string $subject,
    string $body
): array {
    $server = trim(
        (string)($settings['smtpServer'] ?? '')
    );

    $port = (int)(
        $settings['smtpPort'] ?? 587
    );

    $encryption =
        (string)(
            $settings['encryption'] ?? 'starttls'
        );

    $auth = boolv(
        $settings['authentication'] ?? true
    );

    if ($server === '') {
        return [
            'success' => false,
            'error' => 'SMTPサーバが未設定です。',
        ];
    }

    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return [
            'success' => false,
            'error' => '宛先メールアドレスが不正です。',
        ];
    }

    $fromEmail = trim(
        (string)($settings['fromEmail'] ?? '')
    );

    if (
        !filter_var(
            $fromEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return [
            'success' => false,
            'error' => '送信元メールアドレスが未設定または不正です。',
        ];
    }

    $remote = $server . ':' . $port;

    if ($encryption === 'ssl') {
        $remote = 'ssl://' . $remote;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return [
            'success' => false,
            'error' =>
                'SMTP接続に失敗しました。' .
                ' [' . $errno . '] ' . $errstr,
        ];
    }

    stream_set_timeout(
        $socket,
        20
    );

    try {
        $hello = smtpRead($socket);

        if (smtpCode($hello) !== 220) {
            return [
                'success' => false,
                'error' =>
                    'SMTP greeting失敗: ' .
                    trim($hello),
            ];
        }

        $hostName =
            $_SERVER['SERVER_NAME'] ??
            'localhost';

        $r = smtpCommand(
            $socket,
            'EHLO ' . $hostName,
            [250]
        );

        if (!$r['ok']) {
            return [
                'success' => false,
                'error' =>
                    'EHLO失敗: ' .
                    trim($r['response']),
            ];
        }

        if ($encryption === 'starttls') {
            $r = smtpCommand(
                $socket,
                'STARTTLS',
                [220]
            );

            if (!$r['ok']) {
                return [
                    'success' => false,
                    'error' =>
                        'STARTTLS失敗: ' .
                        trim($r['response']),
                ];
            }

            $crypto = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                return [
                    'success' => false,
                    'error' =>
                        'SMTP TLS確立に失敗しました。',
                ];
            }

            $r = smtpCommand(
                $socket,
                'EHLO ' . $hostName,
                [250]
            );

            if (!$r['ok']) {
                return [
                    'success' => false,
                    'error' =>
                        'TLS後EHLO失敗: ' .
                        trim($r['response']),
                ];
            }
        }

        if ($auth) {
            $username =
                (string)(
                    $settings['username'] ?? ''
                );

            $password =
                (string)(
                    $settings['password'] ?? ''
                );

            if ($username === '') {
                return [
                    'success' => false,
                    'error' =>
                        'SMTP認証ユーザー名が未設定です。',
                ];
            }

            $r = smtpCommand(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            if (!$r['ok']) {
                return [
                    'success' => false,
                    'error' =>
                        'SMTP AUTH LOGIN開始に失敗しました。',
                ];
            }

            $r = smtpCommand(
                $socket,
                base64_encode($username),
                [334]
            );

            if (!$r['ok']) {
                return [
                    'success' => false,
                    'error' =>
                        'SMTPユーザー名認証に失敗しました。',
                ];
            }

            $r = smtpCommand(
                $socket,
                base64_encode($password),
                [235]
            );

            if (!$r['ok']) {
                return [
                    'success' => false,
                    'error' =>
                        'SMTPパスワード認証に失敗しました。',
                ];
            }
        }

        $r = smtpCommand(
            $socket,
            'MAIL FROM:<' . $fromEmail . '>',
            [250]
        );

        if (!$r['ok']) {
            return [
                'success' => false,
                'error' =>
                    'MAIL FROM失敗: ' .
                    trim($r['response']),
            ];
        }

        $r = smtpCommand(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        if (!$r['ok']) {
            return [
                'success' => false,
                'error' =>
                    'RCPT TO失敗: ' .
                    trim($r['response']),
            ];
        }

        $r = smtpCommand(
            $socket,
            'DATA',
            [354]
        );

        if (!$r['ok']) {
            return [
                'success' => false,
                'error' =>
                    'DATA開始失敗: ' .
                    trim($r['response']),
            ];
        }

        $fromName =
            (string)(
                $settings['fromName'] ?? ''
            );

        $fromHeader = $fromEmail;

        if ($fromName !== '') {
            $fromHeader =
                '=?UTF-8?B?' .
                base64_encode($fromName) .
                '?= <' .
                $fromEmail .
                '>';
        }

        $subjectHeader =
            '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $headers = [];

        $headers[] =
            'From: ' . $fromHeader;

        $headers[] =
            'To: <' . $to . '>';

        $headers[] =
            'Subject: ' . $subjectHeader;

        $headers[] =
            'Date: ' . date(DATE_RFC2822);

        $headers[] =
            'Message-ID: <' .
            uid() .
            '@localhost>';

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        $headers[] =
            'Content-Transfer-Encoding: 8bit';

        $replyTo =
            trim(
                (string)(
                    $settings['replyTo'] ?? ''
                )
            );

        if (
            $replyTo !== '' &&
            filter_var(
                $replyTo,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $headers[] =
                'Reply-To: <' . $replyTo . '>';
        }

        $safeBody =
            str_replace(
                ["\r\n", "\r"],
                "\n",
                $body
            );

        $safeBody =
            preg_replace(
                '/^\./m',
                '..',
                $safeBody
            );

        $message =
            implode(
                "\r\n",
                $headers
            ) .
            "\r\n\r\n" .
            str_replace(
                "\n",
                "\r\n",
                $safeBody
            ) .
            "\r\n.\r\n";

        fwrite(
            $socket,
            $message
        );

        $response = smtpRead($socket);

        if (smtpCode($response) !== 250) {
            return [
                'success' => false,
                'error' =>
                    'メール送信失敗: ' .
                    trim($response),
            ];
        }

        smtpCommand(
            $socket,
            'QUIT',
            [221]
        );

        return [
            'success' => true,
            'message' => 'SMTP送信成功',
        ];
    } finally {
        fclose($socket);
    }
}

/* ============================================================
 * API
 * ========================================================== */

if (
    isset($_GET['api']) ||
    isset($_POST['api'])
) {
    $api =
        (string)(
            $_GET['api'] ??
            $_POST['api'] ??
            ''
        );

    try {
        /* -----------------------------
         * load
         * --------------------------- */

        if ($api === 'load') {
            $surveys = readJson(
                'surveys',
                []
            );

            if (applyAutoFinish($surveys)) {
                writeJson(
                    'surveys',
                    $surveys
                );
            }

            $customers = readJson(
                'customers',
                []
            );

            $responses = readJson(
                'responses',
                []
            );

            $history = readJson(
                'sendHistory',
                []
            );

            $kintone = kintoneSettings();
            $mail = array_merge(
                defaultMail(),
                readJson(
                    'mail',
                    []
                )
            );

            jsonOut([
                'success' => true,
                'surveys' => $surveys,
                'customers' => $customers,
                'responses' => $responses,
                'sendHistory' => $history,
                'kintone' => $kintone,
                'mail' => $mail,
                'serverTime' => nowIso(),
            ]);
        }

        /* -----------------------------
         * save survey
         * --------------------------- */

        if ($api === 'save_survey') {
            $data = inputData();

            $surveys = readJson(
                'surveys',
                []
            );

            $existingId =
                strv($data['id'] ?? '');

            $index = $existingId !== ''
                ? findSurveyIndex(
                    $surveys,
                    $existingId
                )
                : -1;

            $existing =
                $index >= 0
                ? $surveys[$index]
                : null;

            $survey = sanitizeSurvey(
                $data,
                $existing
            );

            if ($existing === null) {
                $survey['status'] = 'draft';
                $survey['createdAt'] = nowIso();
            } else {
                $survey['status'] =
                    $existing['status'] ?? 'draft';

                $survey['createdAt'] =
                    $existing['createdAt'] ??
                    nowIso();
            }

            if ($index >= 0) {
                $surveys[$index] = $survey;
            } else {
                $surveys[] = $survey;
            }

            if (!writeJson(
                'surveys',
                $surveys
            )) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        'surveys.jsonへ保存できません。' .
                        'dataディレクトリの書き込み権限を確認してください。',
                ], 500);
            }

            jsonOut([
                'success' => true,
                'survey' => $survey,
            ]);
        }

        /* -----------------------------
         * status
         * --------------------------- */

        if ($api === 'change_status') {
            $data = inputData();

            $id = strv(
                $data['surveyId'] ?? ''
            );

            $status = strv(
                $data['status'] ?? ''
            );

            if (
                !in_array(
                    $status,
                    [
                        'draft',
                        'published',
                        'stopped',
                    ],
                    true
                )
            ) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        '指定できない状態です。',
                ], 400);
            }

            $surveys = readJson(
                'surveys',
                []
            );

            $index = findSurveyIndex(
                $surveys,
                $id
            );

            if ($index < 0) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        'アンケートが見つかりません。',
                ], 404);
            }

            $current =
                $surveys[$index]['status'] ??
                'draft';

            $allowed = false;

            if (
                $current === 'draft' &&
                $status === 'published'
            ) {
                $allowed = true;
            }

            if (
                $current === 'published' &&
                $status === 'stopped'
            ) {
                $allowed = true;
            }

            if (
                $current === 'stopped' &&
                $status === 'published'
            ) {
                $allowed = true;
            }

            if (
                $current === 'finished'
            ) {
                $allowed = false;
            }

            if (!$allowed) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        '許可されていない状態変更です。',
                ], 400);
            }

            $surveys[$index]['status'] = $status;
            $surveys[$index]['updatedAt'] =
                nowIso();

            writeJson(
                'surveys',
                $surveys
            );

            jsonOut([
                'success' => true,
                'survey' => $surveys[$index],
            ]);
        }

        /* -----------------------------
         * duplicate
         * --------------------------- */

        if ($api === 'duplicate_survey') {
            $data = inputData();

            $id = strv(
                $data['surveyId'] ?? ''
            );

            $surveys = readJson(
                'surveys',
                []
            );

            $survey = findSurvey(
                $surveys,
                $id
            );

            if ($survey === null) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        '複製元アンケートが見つかりません。',
                ], 404);
            }

            $oldId = $survey['id'];

            $survey['id'] =
                uid('survey_');

            $survey['title'] =
                ($survey['title'] ?? '') .
                '（複製）';

            $survey['status'] = 'draft';
            $survey['createdAt'] = nowIso();
            $survey['updatedAt'] = nowIso();

            foreach ($survey['groups'] as &$group) {
                $group['id'] =
                    uid('group_');

                foreach (
                    $group['questions']
                    as &$question
                ) {
                    $oldQuestionId =
                        $question['id'];

                    $question['id'] =
                        uid('question_');

                    $question['groupId'] =
                        $group['id'];

                    foreach (
                        $question['choices']
                        as &$choice
                    ) {
                        $choice['id'] =
                            uid('choice_');
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

            recalcNumbers($survey);

            $surveys[] = $survey;

            writeJson(
                'surveys',
                $surveys
            );

            jsonOut([
                'success' => true,
                'survey' => $survey,
                'sourceSurveyId' => $oldId,
            ]);
        }

        /* -----------------------------
         * delete
         * --------------------------- */

        if ($api === 'delete_survey') {
            $data = inputData();

            $id = strv(
                $data['surveyId'] ?? ''
            );

            $surveys = readJson(
                'surveys',
                []
            );

            $index = findSurveyIndex(
                $surveys,
                $id
            );

            if ($index < 0) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        'アンケートが見つかりません。',
                ], 404);
            }

            array_splice(
                $surveys,
                $index,
                1
            );

            writeJson(
                'surveys',
                $surveys
            );

            jsonOut([
                'success' => true,
            ]);
        }

        /* -----------------------------
         * save kintone
         * --------------------------- */

        if ($api === 'save_kintone') {
            $data = inputData();

            $current =
                kintoneSettings();

            $subdomain =
                strv(
                    $data['subdomain'] ?? ''
                );

            if (
                $subdomain !== '' &&
                normalizeSubdomain(
                    $subdomain
                ) === null
            ) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        'サブドメイン形式が不正です。',
                ], 400);
            }

            $proxy =
                strv(
                    $data['proxy'] ?? ''
                );

            if (
                $proxy !== '' &&
                normalizeProxy($proxy) === null
            ) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        'プロキシはhost:port形式で入力してください。',
                ], 400);
            }

            $current['subdomain'] =
                $subdomain;

            $current['appId'] =
                strv($data['appId'] ?? '');

            $current['loginName'] =
                strv($data['loginName'] ?? '');

            if (
                array_key_exists(
                    'password',
                    $data
                )
            ) {
                $password =
                    (string)$data['password'];

                if ($password !== '') {
                    $current['password'] =
                        $password;
                }
            }

            $current['sslVerify'] =
                boolv(
                    $data['sslVerify'] ??
                    false
                );

            $current['proxy'] =
                $proxy;

            $current['updatedAt'] =
                nowIso();

            writeJson(
                'kintone',
                $current
            );

            jsonOut([
                'success' => true,
                'kintone' => $current,
            ]);
        }

        /* -----------------------------
         * kintone test
         * --------------------------- */

        if ($api === 'kintone_test') {
            $settings = kintoneSettings();

            $result = kintoneRequest(
                $settings,
                'GET',
                '/v1/preview/app/form/fields.json?app=' .
                rawurlencode(
                    (string)$settings['appId']
                )
            );

            if (!$result['success']) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        $result['error'],
                ], 502);
            }

            jsonOut([
                'success' => true,
                'message' => 'kintone接続成功',
            ]);
        }

        /* -----------------------------
         * kintone fields
         * --------------------------- */

        if ($api === 'kintone_fields') {
            $settings = kintoneSettings();

            $result = kintoneRequest(
                $settings,
                'GET',
                '/v1/preview/app/form/fields.json?app=' .
                rawurlencode(
                    (string)$settings['appId']
                )
            );

            if (!$result['success']) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        $result['error'],
                ], 502);
            }

            $properties =
                $result['data']['properties'] ??
                [];

            $fields = [];

            foreach (
                $properties as $code => $field
            ) {
                $fields[] = [
                    'code' => $code,
                    'label' =>
                        $field['label'] ??
                        $code,
                    'type' =>
                        $field['type'] ??
                        '',
                ];
            }

            $settings['fields'] =
                $fields;

            writeJson(
                'kintone',
                $settings
            );

            jsonOut([
                'success' => true,
                'fields' => $fields,
            ]);
        }

        /* -----------------------------
         * kintone mapping
         * --------------------------- */

        if ($api === 'save_kintone_mapping') {
            $data = inputData();

            $settings =
                kintoneSettings();

            $settings['mapping'] = [
                'organization' =>
                    strv(
                        $data['organization'] ??
                        ''
                    ),
                'name' =>
                    strv(
                        $data['name'] ?? ''
                    ),
                'email' =>
                    strv(
                        $data['email'] ?? ''
                    ),
                'department' =>
                    strv(
                        $data['department'] ??
                        ''
                    ),
                'phone' =>
                    strv(
                        $data['phone'] ??
                        ''
                    ),
                'address' =>
                    array_values(
                        array_map(
                            'strv',
                            is_array(
                                $data['address'] ??
                                null
                            )
                            ? $data['address']
                            : []
                        )
                    ),
            ];

            $settings['updatedAt'] =
                nowIso();

            writeJson(
                'kintone',
                $settings
            );

            jsonOut([
                'success' => true,
                'kintone' => $settings,
            ]);
        }

        /* -----------------------------
         * kintone sync
         * --------------------------- */

        if ($api === 'kintone_sync') {
            $settings =
                kintoneSettings();

            $appId =
                (string)$settings['appId'];

            $query =
                '/v1/records.json?app=' .
                rawurlencode($appId) .
                '&totalCount=true';

            $result = kintoneRequest(
                $settings,
                'GET',
                $query
            );

            if (!$result['success']) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        $result['error'],
                ], 502);
            }

            $records =
                $result['data']['records'] ??
                [];

            $mapping =
                $settings['mapping'] ??
                [];

            $customers = [];

            foreach ($records as $record) {
                $getValue =
                    static function (
                        string $code
                    ) use ($record): string {
                        return strv(
                            $record[$code]['value'] ??
                            ''
                        );
                    };

                $emailCode =
                    strv(
                        $mapping['email'] ??
                        ''
                    );

                $email =
                    $emailCode !== ''
                    ? $getValue($emailCode)
                    : '';

                if (
                    $email === '' ||
                    !filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    continue;
                }

                $nameCode =
                    strv(
                        $mapping['name'] ??
                        ''
                    );

                $organizationCode =
                    strv(
                        $mapping['organization'] ??
                        ''
                    );

                $departmentCode =
                    strv(
                        $mapping['department'] ??
                        ''
                    );

                $phoneCode =
                    strv(
                        $mapping['phone'] ??
                        ''
                    );

                $addressParts = [];

                foreach (
                    $mapping['address'] ??
                    [] as $code
                ) {
                    $value =
                        $getValue(
                            (string)$code
                        );

                    if ($value !== '') {
                        $addressParts[] =
                            $value;
                    }
                }

                $customers[] = [
                    'id' =>
                        'kintone-' .
                        sha1($email),
                    'organization' =>
                        $organizationCode !== ''
                        ? $getValue(
                            $organizationCode
                        )
                        : '',
                    'name' =>
                        $nameCode !== ''
                        ? $getValue(
                            $nameCode
                        )
                        : '',
                    'email' => $email,
                    'department' =>
                        $departmentCode !== ''
                        ? $getValue(
                            $departmentCode
                        )
                        : '',
                    'phone' =>
                        $phoneCode !== ''
                        ? $getValue(
                            $phoneCode
                        )
                        : '',
                    'address' =>
                        implode(
                            ' ',
                            $addressParts
                        ),
                    'status' => '未送信',
                    'lastSentAt' => null,
                    'sendCount' => 0,
                    'kintoneStatus' =>
                        '登録済み',
                ];
            }

            $existing =
                readJson(
                    'customers',
                    []
                );

            $byEmail = [];

            foreach ($existing as $customer) {
                $email =
                    strtolower(
                        strv(
                            $customer['email'] ??
                            ''
                        )
                    );

                if ($email !== '') {
                    $byEmail[$email] =
                        $customer;
                }
            }

            foreach ($customers as $customer) {
                $email =
                    strtolower(
                        $customer['email']
                    );

                if (
                    isset(
                        $byEmail[$email]
                    )
                ) {
                    $old =
                        $byEmail[$email];

                    $customer['status'] =
                        $old['status'] ??
                        '未送信';

                    $customer['lastSentAt'] =
                        $old['lastSentAt'] ??
                        null;

                    $customer['sendCount'] =
                        (int)(
                            $old['sendCount'] ??
                            0
                        );
                }

                $byEmail[$email] =
                    $customer;
            }

            writeJson(
                'customers',
                array_values($byEmail)
            );

            jsonOut([
                'success' => true,
                'message' =>
                    '顧客同期完了',
                'count' =>
                    count($customers),
            ]);
        }

        /* -----------------------------
         * save mail
         * --------------------------- */

        if ($api === 'save_mail') {
            $data = inputData();

            $mail = array_merge(
                defaultMail(),
                readJson(
                    'mail',
                    []
                )
            );

            $mail['smtpServer'] =
                strv(
                    $data['smtpServer'] ??
                    ''
                );

            $mail['smtpPort'] =
                max(
                    1,
                    min(
                        65535,
                        (int)(
                            $data['smtpPort'] ??
                            587
                        )
                    )
                );

            $encryption =
                strv(
                    $data['encryption'] ??
                    'starttls'
                );

            if (
                !in_array(
                    $encryption,
                    [
                        'none',
                        'starttls',
                        'ssl',
                    ],
                    true
                )
            ) {
                $encryption = 'starttls';
            }

            $mail['encryption'] =
                $encryption;

            $mail['authentication'] =
                boolv(
                    $data['authentication'] ??
                    true
                );

            $mail['username'] =
                strv(
                    $data['username'] ??
                    ''
                );

            if (
                array_key_exists(
                    'password',
                    $data
                ) &&
                strv(
                    $data['password']
                ) !== ''
            ) {
                $mail['password'] =
                    (string)$data['password'];
            }

            $mail['fromEmail'] =
                strv(
                    $data['fromEmail'] ??
                    ''
                );

            $mail['fromName'] =
                strv(
                    $data['fromName'] ??
                    ''
                );

            $mail['replyTo'] =
                strv(
                    $data['replyTo'] ??
                    ''
                );

            $mail['updatedAt'] =
                nowIso();

            writeJson(
                'mail',
                $mail
            );

            jsonOut([
                'success' => true,
                'mail' => $mail,
            ]);
        }

        /* -----------------------------
         * mail test
         * --------------------------- */

        if ($api === 'mail_test') {
            $data = inputData();

            $mail = array_merge(
                defaultMail(),
                readJson(
                    'mail',
                    []
                )
            );

            $to =
                strv(
                    $data['to'] ??
                    $mail['fromEmail'] ??
                    ''
                );

            if (
                !filter_var(
                    $to,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        'テスト送信先メールアドレスが不正です。',
                ], 400);
            }

            $result = smtpSendMail(
                $mail,
                $to,
                'アンケート管理システム テストメール',
                "SMTP通信テストです。\n\n送信日時: " .
                nowIso()
            );

            if ($result['success']) {
                $mail['connectionStatus'] =
                    '接続確認済み';

                $mail['lastError'] = '';
            } else {
                $mail['connectionStatus'] =
                    '接続できません';

                $mail['lastError'] =
                    $result['error'] ?? '';
            }

            $mail['lastTestAt'] =
                nowIso();

            writeJson(
                'mail',
                $mail
            );

            if (!$result['success']) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        $result['error'] ??
                        'SMTPテスト失敗',
                ], 502);
            }

            jsonOut([
                'success' => true,
                'message' =>
                    'テストメール送信成功',
            ]);
        }

        /* -----------------------------
         * send
         * --------------------------- */

        if ($api === 'send') {
            $data = inputData();

            $surveyId =
                strv(
                    $data['surveyId'] ??
                    ''
                );

            $customerIds =
                is_array(
                    $data['customerIds'] ??
                    null
                )
                ? array_values(
                    array_map(
                        'strv',
                        $data['customerIds']
                    )
                )
                : [];

            $subject =
                strv(
                    $data['subject'] ??
                    ''
                );

            $body =
                (string)(
                    $data['body'] ??
                    ''
                );

            $sendType =
                strv(
                    $data['sendType'] ??
                    '一括送信'
                );

            if ($surveyId === '') {
                jsonOut([
                    'success' => false,
                    'error' =>
                        '対象アンケートIDがありません。',
                ], 400);
            }

            if (!$customerIds) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        '送信対象顧客が選択されていません。',
                ], 400);
            }

            $surveys =
                readJson(
                    'surveys',
                    []
                );

            $survey =
                findSurvey(
                    $surveys,
                    $surveyId
                );

            if ($survey === null) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        '対象アンケートが見つかりません。',
                ], 404);
            }

            $customers =
                readJson(
                    'customers',
                    []
                );

            $mail = array_merge(
                defaultMail(),
                readJson(
                    'mail',
                    []
                )
            );

            $history =
                readJson(
                    'sendHistory',
                    []
                );

            $results = [];

            $baseUrl =
                (
                    (
                        !empty($_SERVER['HTTPS']) &&
                        $_SERVER['HTTPS'] !== 'off'
                    )
                    ? 'https'
                    : 'http'
                ) .
                '://' .
                (
                    $_SERVER['HTTP_HOST'] ??
                    'localhost'
                ) .
                dirname(
                    $_SERVER['SCRIPT_NAME'] ??
                    '/index.php'
                );

            $baseUrl =
                rtrim(
                    $baseUrl,
                    '/'
                );

            foreach ($customers as &$customer) {
                if (
                    !in_array(
                        (string)$customer['id'],
                        $customerIds,
                        true
                    )
                ) {
                    continue;
                }

                $customerName =
                    (string)(
                        $customer['name'] ??
                        ''
                    );

                $url =
                    $baseUrl .
                    '/index.php?respond=1&survey=' .
                    rawurlencode(
                        $surveyId
                    ) .
                    '&customer=' .
                    rawurlencode(
                        (string)$customer['id']
                    );

                $expandedSubject =
                    str_replace(
                        [
                            '{顧客名}',
                            '{アンケートURL}',
                        ],
                        [
                            $customerName,
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
                            $customerName,
                            $url,
                        ],
                        $body
                    );

                $send =
                    smtpSendMail(
                        $mail,
                        (string)$customer['email'],
                        $expandedSubject,
                        $expandedBody
                    );

                $success =
                    (bool)(
                        $send['success'] ??
                        false
                    );

                if ($success) {
                    $customer['status'] =
                        '送信済み / 未回答';

                    $customer['lastSentAt'] =
                        nowIso();

                    $customer['sendCount'] =
                        (int)(
                            $customer['sendCount'] ??
                            0
                        ) + 1;
                }

                $results[] = [
                    'customerId' =>
                        $customer['id'],
                    'customerName' =>
                        $customerName,
                    'email' =>
                        $customer['email'] ??
                        '',
                    'success' =>
                        $success,
                    'error' =>
                        $success
                        ? ''
                        : (
                            $send['error'] ??
                            '送信失敗'
                        ),
                    'subject' =>
                        $expandedSubject,
                    'body' =>
                        $expandedBody,
                    'url' =>
                        $url,
                ];
            }

            unset($customer);

            writeJson(
                'customers',
                $customers
            );

            $successCount = 0;

            foreach ($results as $r) {
                if ($r['success']) {
                    $successCount++;
                }
            }

            $history[] = [
                'id' =>
                    uid('history_'),
                'surveyId' =>
                    $surveyId,
                'sentAt' =>
                    nowIso(),
                'sendType' =>
                    $sendType,
                'count' =>
                    count($results),
                'successCount' =>
                    $successCount,
                'failureCount' =>
                    count($results) -
                    $successCount,
                'subject' =>
                    $subject,
                'body' =>
                    $body,
                'executor' =>
                    '管理画面',
                'targets' =>
                    $results,
            ];

            writeJson(
                'sendHistory',
                $history
            );

            jsonOut([
                'success' => true,
                'results' => $results,
                'summary' => [
                    'total' =>
                        count($results),
                    'success' =>
                        $successCount,
                    'failure' =>
                        count($results) -
                        $successCount,
                    'sentAt' =>
                        nowIso(),
                ],
            ]);
        }

        /* -----------------------------
         * response submit
         * --------------------------- */

        if ($api === 'submit_response') {
            $data = inputData();

            $surveyId =
                strv(
                    $data['surveyId'] ??
                    ''
                );

            if ($surveyId === '') {
                jsonOut([
                    'success' => false,
                    'error' =>
                        'アンケートIDがありません。',
                ], 400);
            }

            $surveys =
                readJson(
                    'surveys',
                    []
                );

            $survey =
                findSurvey(
                    $surveys,
                    $surveyId
                );

            if ($survey === null) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        'アンケートが見つかりません。',
                ], 404);
            }

            if (
                ($survey['status'] ?? '') !==
                'published'
            ) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        'このアンケートは現在回答できません。',
                ], 400);
            }

            $customerId =
                strv(
                    $data['customerId'] ??
                    ''
                );

            $token =
                strv(
                    $data['token'] ??
                    ''
                );

            $responses =
                readJson(
                    'responses',
                    []
                );

            if (
                !$survey['allowResubmission'] &&
                (
                    (
                        $customerId !== '' &&
                        array_filter(
                            $responses,
                            static function (
                                $r
                            ) use (
                                $surveyId,
                                $customerId
                            ) {
                                return
                                    ($r['surveyId'] ?? '') ===
                                    $surveyId &&
                                    ($r['customerId'] ?? '') ===
                                    $customerId &&
                                    ($r['status'] ?? '') ===
                                    'completed';
                            }
                        )
                    ) ||
                    (
                        $token !== '' &&
                        array_filter(
                            $responses,
                            static function (
                                $r
                            ) use (
                                $surveyId,
                                $token
                            ) {
                                return
                                    ($r['surveyId'] ?? '') ===
                                    $surveyId &&
                                    ($r['individualToken'] ?? '') ===
                                    $token &&
                                    ($r['status'] ?? '') ===
                                    'completed';
                            }
                        )
                    )
                )
            ) {
                jsonOut([
                    'success' => false,
                    'alreadyAnswered' => true,
                    'error' =>
                        'このアンケートは回答済みです。',
                ], 409);
            }

            $response = [
                'id' =>
                    uid('response_'),
                'surveyId' =>
                    $surveyId,
                'individualToken' =>
                    $token !== ''
                    ? $token
                    : uid('token_'),
                'customerId' =>
                    $customerId !== ''
                    ? $customerId
                    : null,
                'respondent' =>
                    is_array(
                        $data['respondent'] ??
                        null
                    )
                    ? $data['respondent']
                    : [],
                'answers' =>
                    is_array(
                        $data['answers'] ??
                        null
                    )
                    ? $data['answers']
                    : [],
                'status' =>
                    'completed',
                'submittedAt' =>
                    nowIso(),
                'updatedAt' =>
                    nowIso(),
            ];

            $responses[] = $response;

            writeJson(
                'responses',
                $responses
            );

            if ($customerId !== '') {
                $customers =
                    readJson(
                        'customers',
                        []
                    );

                foreach (
                    $customers as &$customer
                ) {
                    if (
                        (string)(
                            $customer['id'] ??
                            ''
                        ) === $customerId
                    ) {
                        $customer['status'] =
                            '回答済み';
                    }
                }

                unset($customer);

                writeJson(
                    'customers',
                    $customers
                );
            }

            jsonOut([
                'success' => true,
                'responseId' =>
                    $response['id'],
            ]);
        }

        jsonOut([
            'success' => false,
            'error' =>
                'Unknown API: ' . $api,
        ], 404);
    } catch (Throwable $e) {
        error_log(
            '[survey-system] ' .
            $e->getMessage() .
            "\n" .
            $e->getTraceAsString()
        );

        jsonOut([
            'success' => false,
            'error' =>
                'サーバー処理中にエラーが発生しました。',
            'detail' =>
                $e->getMessage(),
        ], 500);
    }
}

/* ============================================================
 * 回答者起動判定
 * ========================================================== */

$respondMode =
    isset($_GET['respond']) &&
    $_GET['respond'] === '1';

$respondSurveyId =
    strv(
        $_GET['survey'] ??
        ''
    );

$respondCustomerId =
    strv(
        $_GET['customer'] ??
        ''
    );

$respondToken =
    strv(
        $_GET['token'] ??
        ''
    );
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>アンケート管理システム</title>

<style>
:root {
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --danger:#dc2626;
    --success:#059669;
    --warning:#d97706;
    --muted:#64748b;
    --border:#dbe2ea;
    --bg:#f5f7fa;
    --card:#fff;
    --text:#172033;
    --radius:12px;
    --shadow:0 4px 18px rgba(15,23,42,.07);
}

* {
    box-sizing:border-box;
}

html,
body {
    margin:0;
    padding:0;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
    color:var(--text);
    background:var(--bg);
}

button,
input,
select,
textarea {
    font:inherit;
}

button {
    cursor:pointer;
}

button:disabled {
    cursor:not-allowed;
    opacity:.55;
}

.app-header {
    position:sticky;
    top:0;
    z-index:20;
    background:#111827;
    color:#fff;
    min-height:64px;
    display:flex;
    align-items:center;
    padding:10px 20px;
    gap:20px;
}

.app-header .brand {
    font-weight:800;
    white-space:nowrap;
}

.header-nav {
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.header-nav button {
    border:0;
    background:transparent;
    color:#cbd5e1;
    padding:9px 12px;
    border-radius:8px;
}

.header-nav button:hover {
    background:#1f2937;
    color:#fff;
}

.header-spacer {
    flex:1;
}

.container {
    max-width:1500px;
    margin:0 auto;
    padding:24px;
}

.page {
    display:none;
}

.page.active {
    display:block;
}

.page-title {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    margin-bottom:20px;
}

.page-title h1 {
    margin:0;
    font-size:26px;
}

.card {
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:18px;
}

.toolbar {
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
}

.btn {
    min-height:42px;
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    border-radius:8px;
    padding:8px 14px;
    font-weight:700;
}

.btn:hover {
    background:#f8fafc;
}

.btn-primary {
    background:var(--primary);
    border-color:var(--primary);
    color:#fff;
}

.btn-primary:hover {
    background:var(--primary-dark);
}

.btn-danger {
    color:#fff;
    background:var(--danger);
    border-color:var(--danger);
}

.btn-success {
    color:#fff;
    background:var(--success);
    border-color:var(--success);
}

.btn-warning {
    color:#fff;
    background:var(--warning);
    border-color:var(--warning);
}

.btn-small {
    min-height:34px;
    padding:6px 9px;
    font-size:13px;
}

input,
select,
textarea {
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}

textarea {
    min-height:130px;
    resize:vertical;
}

label {
    display:block;
    font-weight:700;
    margin-bottom:6px;
}

.form-grid {
    display:grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap:16px;
}

.form-grid.three {
    grid-template-columns:
        repeat(3,minmax(0,1fr));
}

.form-field.full {
    grid-column:1 / -1;
}

.help {
    color:var(--muted);
    font-size:13px;
    margin-top:5px;
}

.table-wrap {
    overflow-x:auto;
}

table {
    width:100%;
    min-width:1050px;
    border-collapse:collapse;
}

th,
td {
    border-bottom:1px solid var(--border);
    padding:12px 10px;
    text-align:left;
    vertical-align:middle;
}

th {
    background:#f8fafc;
    white-space:nowrap;
}

.status {
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:4px 10px;
    font-size:12px;
    font-weight:800;
}

.status-draft {
    color:#475569;
    background:#e2e8f0;
}

.status-published {
    color:#047857;
    background:#d1fae5;
}

.status-stopped {
    color:#92400e;
    background:#fef3c7;
}

.status-finished {
    color:#7f1d1d;
    background:#fee2e2;
}

.action-row {
    display:flex;
    flex-wrap:wrap;
    gap:5px;
}

.search-row {
    display:grid;
    grid-template-columns:minmax(200px,1fr) 180px 180px;
    gap:10px;
}

.filter-tabs {
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    margin-top:12px;
}

.filter-tabs button {
    border:1px solid var(--border);
    background:#fff;
    padding:8px 14px;
    border-radius:999px;
}

.filter-tabs button.active {
    background:#dbeafe;
    border-color:#93c5fd;
    color:#1d4ed8;
    font-weight:800;
}

.editor-group {
    border:2px solid #dbeafe;
    border-radius:12px;
    padding:16px;
    margin-bottom:18px;
    background:#f8fbff;
}

.group-head {
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:14px;
}

.drag-handle {
    cursor:grab;
    padding:6px;
    color:var(--muted);
}

.question {
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:15px;
    margin-bottom:12px;
}

.question-head {
    display:grid;
    grid-template-columns:auto 1fr auto;
    gap:10px;
    align-items:center;
}

.question-number {
    font-weight:900;
    color:var(--primary);
    white-space:nowrap;
}

.choice-row {
    display:grid;
    grid-template-columns:1fr auto;
    gap:8px;
    margin:8px 0;
}

.branch-row {
    display:grid;
    grid-template-columns:1fr 1fr auto;
    gap:8px;
    margin:8px 0;
}

.preview-box {
    max-width:900px;
    margin:0 auto;
    transition:max-width .2s;
}

.preview-box.mobile {
    max-width:390px;
}

.answer-question {
    margin-bottom:22px;
}

.answer-choice {
    display:block;
    border:1px solid var(--border);
    border-radius:10px;
    padding:13px;
    margin:8px 0;
    cursor:pointer;
}

.answer-choice:hover {
    background:#f8fafc;
}

.answer-choice input {
    width:auto;
    margin-right:8px;
}

.summary-grid {
    display:grid;
    grid-template-columns:
        repeat(5,minmax(0,1fr));
    gap:12px;
}

.summary-card {
    border:1px solid var(--border);
    background:#fff;
    border-radius:10px;
    padding:16px;
}

.summary-label {
    color:var(--muted);
    font-size:13px;
}

.summary-value {
    font-size:28px;
    font-weight:900;
    margin-top:5px;
}

.bar {
    height:22px;
    background:#e2e8f0;
    border-radius:999px;
    overflow:hidden;
}

.bar > div {
    height:100%;
    background:var(--primary);
}

.modal-backdrop {
    display:none;
    position:fixed;
    inset:0;
    z-index:100;
    background:rgba(15,23,42,.58);
    align-items:center;
    justify-content:center;
    padding:20px;
}

.modal-backdrop.show {
    display:flex;
}

.modal {
    width:min(560px,100%);
    background:#fff;
    border-radius:14px;
    padding:22px;
    box-shadow:0 25px 70px rgba(0,0,0,.25);
}

.modal h2 {
    margin-top:0;
}

.modal-actions {
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin-top:20px;
}

.toast {
    position:fixed;
    right:20px;
    bottom:20px;
    z-index:200;
    min-width:260px;
    max-width:500px;
    background:#111827;
    color:#fff;
    border-radius:10px;
    padding:14px 16px;
    box-shadow:var(--shadow);
    display:none;
}

.toast.show {
    display:block;
}

.toast.error {
    background:#991b1b;
}

.toast.success {
    background:#065f46;
}

.result-box {
    border:1px solid var(--border);
    border-radius:10px;
    padding:14px;
    margin-top:15px;
}

.mail-preview {
    white-space:pre-wrap;
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:8px;
    padding:15px;
}

.kintone-fields {
    display:grid;
    grid-template-columns:
        repeat(3,minmax(0,1fr));
    gap:8px;
}

.empty {
    text-align:center;
    color:var(--muted);
    padding:40px;
}

.loading {
    position:fixed;
    inset:0;
    z-index:300;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#f8fafc;
}

.loading.hidden {
    display:none;
}

.spinner {
    width:42px;
    height:42px;
    border:4px solid #dbeafe;
    border-top-color:var(--primary);
    border-radius:50%;
    animation:spin .8s linear infinite;
}

@keyframes spin {
    to {
        transform:rotate(360deg);
    }
}

.admin-only {
    display:block;
}

.answer-only {
    display:none;
}

body.answer-mode .admin-only {
    display:none !important;
}

body.answer-mode .answer-only {
    display:block;
}

.drag-over {
    outline:3px dashed #60a5fa;
}

@media(max-width:900px) {
    .summary-grid {
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }

    .form-grid,
    .form-grid.three,
    .search-row {
        grid-template-columns:1fr;
    }

    .kintone-fields {
        grid-template-columns:1fr;
    }

    .container {
        padding:14px;
    }

    .app-header {
        position:static;
        align-items:flex-start;
        flex-direction:column;
    }

    .header-spacer {
        display:none;
    }

    .page-title {
        align-items:flex-start;
        flex-direction:column;
    }
}

@media(max-width:600px) {
    .summary-grid {
        grid-template-columns:1fr;
    }

    .question-head {
        grid-template-columns:1fr;
    }

    .branch-row {
        grid-template-columns:1fr;
    }

    .choice-row {
        grid-template-columns:1fr;
    }

    .btn {
        min-height:44px;
    }

    .answer-choice {
        padding:16px;
    }
}
</style>
</head>

<body>

<div id="loading"
     class="loading">
    <div>
        <div class="spinner"></div>
        <div style="margin-top:12px">
            システムを起動しています…
        </div>
    </div>
</div>

<header class="app-header admin-only">
    <div class="brand">
        アンケート管理システム
    </div>

    <nav class="header-nav">
        <button onclick="showList()">
            アンケート一覧
        </button>

        <button onclick="showKintone()">
            kintone連携設定
        </button>

        <button onclick="showMail()">
            メールサーバ設定
        </button>

        <button onclick="uiLogout()">
            ログアウト
        </button>
    </nav>

    <div class="header-spacer"></div>
</header>

<main class="container">

<!-- ========================================================
     一覧
========================================================= -->

<section id="page-list"
         class="page admin-only">

    <div class="page-title">
        <div>
            <h1>アンケート一覧</h1>
            <div class="help">
                アンケート管理業務の起点です。
            </div>
        </div>

        <button class="btn btn-primary"
                onclick="newSurvey()">
            ＋ アンケート作成
        </button>
    </div>

    <div class="card">

        <div class="search-row">
            <input id="survey-search"
                   type="search"
                   placeholder="タイトルを検索"
                   onkeydown="if(event.key==='Enter')renderSurveyList()">

            <select id="survey-sort"
                    onchange="renderSurveyList()">
                <option value="updated_desc">
                    更新日 新しい順
                </option>
                <option value="updated_asc">
                    更新日 古い順
                </option>
                <option value="responses_desc">
                    回答数 多い順
                </option>
                <option value="responses_asc">
                    回答数 少ない順
                </option>
                <option value="start_desc">
                    開始日 新しい順
                </option>
                <option value="start_asc">
                    開始日 古い順
                </option>
            </select>

            <button class="btn"
                    onclick="renderSurveyList()">
                検索
            </button>
        </div>

        <div class="filter-tabs">
            <button data-filter="all"
                    onclick="setSurveyFilter('all')">
                すべて
            </button>

            <button data-filter="published"
                    onclick="setSurveyFilter('published')">
                公開中
            </button>

            <button data-filter="draft"
                    onclick="setSurveyFilter('draft')">
                下書き
            </button>

            <button data-filter="stopped"
                    onclick="setSurveyFilter('stopped')">
                停止
            </button>

            <button data-filter="finished"
                    onclick="setSurveyFilter('finished')">
                終了
            </button>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>更新日</th>
                    <th>タイトル</th>
                    <th>期間</th>
                    <th>ステータス</th>
                    <th>回答数</th>
                    <th>操作</th>
                </tr>
                </thead>

                <tbody id="survey-table-body"></tbody>
            </table>
        </div>
    </div>

</section>

<!-- ========================================================
     編集
========================================================= -->

<section id="page-editor"
         class="page admin-only">

    <div class="page-title">
        <div>
            <h1 id="editor-title">
                アンケート作成
            </h1>
        </div>

        <div class="toolbar">
            <button class="btn"
                    onclick="cancelEditor()">
                キャンセル
            </button>

            <button class="btn"
                    onclick="previewEditor()">
                プレビュー
            </button>

            <button class="btn btn-primary"
                    onclick="saveEditor()">
                保存して一覧へ
            </button>
        </div>
    </div>

    <div class="card">

        <div class="form-grid">

            <div class="form-field full">
                <label>タイトル</label>
                <input id="editor-title-input">
            </div>

            <div class="form-field full">
                <label>説明</label>
                <textarea id="editor-description"></textarea>
            </div>

            <div>
                <label>開始日時</label>
                <input id="editor-start"
                       type="datetime-local">
            </div>

            <div>
                <label>終了日時</label>
                <input id="editor-end"
                       type="datetime-local">
            </div>

            <div>
                <label>質問番号の採番方式</label>
                <select id="editor-number-mode"
                        onchange="recalcEditor()">
                    <option value="all">
                        アンケート全体で通番
                    </option>
                    <option value="group">
                        グループ毎に採番
                    </option>
                </select>
            </div>

            <div>
                <label>再回答</label>
                <select id="editor-resubmission">
                    <option value="false">
                        再回答不可
                    </option>
                    <option value="true">
                        再回答可能
                    </option>
                </select>
            </div>

            <div class="form-field full">
                <label>状態</label>
                <select id="editor-status"
                        onchange="statusChanged()">
                </select>
                <div class="help">
                    終了状態は自動判定のみです。
                </div>
            </div>

        </div>

    </div>

    <div id="editor-groups"></div>

    <div class="card">
        <button class="btn btn-primary"
                onclick="addGroup()">
            ＋ グループを追加
        </button>
    </div>

</section>

<!-- ========================================================
     プレビュー
========================================================= -->

<section id="page-preview"
         class="page admin-only">

    <div class="page-title">
        <h1>アンケートプレビュー</h1>

        <div class="toolbar">
            <button class="btn"
                    onclick="previewMode('pc')">
                PC表示
            </button>

            <button class="btn"
                    onclick="previewMode('mobile')">
                スマートフォン表示
            </button>

            <button class="btn"
                    onclick="backEditor()">
                編集へ戻る
            </button>
        </div>
    </div>

    <div class="card">
        <div id="preview-container"
             class="preview-box"></div>
    </div>

</section>

<!-- ========================================================
     送信
========================================================= -->

<section id="page-send"
         class="page admin-only">

    <div class="page-title">
        <div>
            <h1>顧客選択・メール送信</h1>
            <div id="send-survey-title"
                 class="help"></div>
        </div>

        <button class="btn"
                onclick="showList()">
            一覧へ戻る
        </button>
    </div>

    <div class="card">

        <div class="form-grid">

            <div>
                <label>顧客検索</label>
                <input id="customer-search"
                       placeholder="顧客名・組織名・メールアドレス"
                       onkeydown="if(event.key==='Enter')renderCustomers()"
                       oninput="renderCustomers()">
            </div>

            <div>
                <label>ステータス</label>
                <select id="customer-status-filter"
                        onchange="renderCustomers()">
                    <option value="">すべて</option>
                    <option value="未送信">未送信</option>
                    <option value="送信済み / 未回答">
                        送信済み / 未回答
                    </option>
                    <option value="回答済み">
                        回答済み
                    </option>
                </select>
            </div>

        </div>

        <div class="toolbar"
             style="margin-top:15px">
            <button class="btn"
                    onclick="selectAllCustomers()">
                すべて選択
            </button>

            <button class="btn"
                    onclick="clearCustomerSelection()">
                すべて解除
            </button>

            <button class="btn btn-warning"
                    onclick="prepareReminder()">
                未回答者をリマインド対象にする
            </button>
        </div>

    </div>

    <div class="card">
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

                <tbody id="customer-table-body"></tbody>
            </table>
        </div>
    </div>

    <div class="card">

        <h2>メール作成</h2>

        <div class="form-grid">

            <div class="form-field full">
                <label>件名</label>
                <input id="mail-subject">
            </div>

            <div class="form-field full">
                <label>本文</label>
                <textarea id="mail-body"></textarea>

                <div class="help">
                    使用可能な変数:
                    {顧客名}
                    {アンケートURL}
                </div>
            </div>

        </div>

        <div class="toolbar"
             style="margin-top:15px">

            <button class="btn"
                    onclick="showMailPreview()">
                送信文を確認
            </button>

            <button class="btn btn-primary"
                    onclick="confirmSend('一括送信')">
                一括送信
            </button>

            <button class="btn btn-warning"
                    onclick="confirmSend('再送')">
                再送
            </button>
        </div>

        <div id="mail-preview"></div>

    </div>

    <div class="card">
        <h2>送信結果</h2>
        <div id="send-result">
            <div class="empty">
                まだ送信していません。
            </div>
        </div>
    </div>

    <div class="card">
        <h2>送信履歴</h2>
        <div id="send-history"></div>
    </div>

</section>

<!-- ========================================================
     集計
========================================================= -->

<section id="page-analysis"
         class="page admin-only">

    <div class="page-title">
        <div>
            <h1>回答集計・分析</h1>
            <div id="analysis-survey-title"
                 class="help"></div>
        </div>

        <div class="toolbar">
            <button class="btn"
                    onclick="exportCsv()">
                CSV出力
            </button>

            <button class="btn"
                    onclick="exportPdf()">
                PDF出力
            </button>

            <button class="btn"
                    onclick="showList()">
                一覧へ戻る
            </button>
        </div>
    </div>

    <div class="summary-grid"
         id="analysis-summary"></div>

    <div class="card">
        <div class="toolbar">
            <button class="btn"
                    onclick="selectAllAnalysis()">
                すべて選択
            </button>

            <button class="btn"
                    onclick="clearAnalysisSelection()">
                すべて解除
            </button>
        </div>
    </div>

    <div id="analysis-questions"></div>

    <div class="card">
        <h2>個別回答</h2>
        <div id="analysis-responses"></div>
    </div>

</section>

<!-- ========================================================
     kintone
========================================================= -->

<section id="page-kintone"
         class="page admin-only">

    <div class="page-title">
        <h1>kintone連携設定</h1>

        <button class="btn"
                onclick="showList()">
            一覧へ戻る
        </button>
    </div>

    <div class="card">

        <div class="form-grid">

            <div>
                <label>サブドメイン</label>
                <input id="k-subdomain"
                       placeholder="xxxx / xxxx.cybozu.com">
            </div>

            <div>
                <label>顧客管理アプリID</label>
                <input id="k-appid">
            </div>

            <div>
                <label>ログイン名</label>
                <input id="k-login">
            </div>

            <div>
                <label>パスワード</label>
                <input id="k-password"
                       type="password"
                       placeholder="変更しない場合は空欄">
            </div>

            <div>
                <label>SSL証明書検証</label>
                <select id="k-ssl">
                    <option value="false">
                        検証しない
                    </option>
                    <option value="true">
                        検証する
                    </option>
                </select>
            </div>

            <div>
                <label>プロキシ</label>
                <input id="k-proxy"
                       placeholder="proxy.example.local:8080">
                <div class="help">
                    認証なし。host:port形式。
                </div>
            </div>

        </div>

        <div class="toolbar"
             style="margin-top:18px">

            <button class="btn btn-primary"
                    onclick="saveKintone()">
                設定を保存
            </button>

            <button class="btn"
                    onclick="testKintone()">
                接続テスト
            </button>

            <button class="btn"
                    onclick="getKintoneFields()">
                項目一覧を再取得
            </button>

            <button class="btn btn-success"
                    onclick="syncKintone()">
                顧客情報を同期
            </button>
        </div>

        <div id="kintone-result"
             class="result-box">
        </div>

    </div>

    <div class="card">
        <h2>kintoneフィールド</h2>
        <div id="kintone-fields"></div>
    </div>

    <div class="card">
        <h2>フィールドマッピング</h2>

        <div class="form-grid">

            <div>
                <label>組織名</label>
                <select id="map-organization"></select>
            </div>

            <div>
                <label>氏名</label>
                <select id="map-name"></select>
            </div>

            <div>
                <label>メールアドレス</label>
                <select id="map-email"></select>
            </div>

            <div>
                <label>部署名</label>
                <select id="map-department"></select>
            </div>

            <div>
                <label>電話番号</label>
                <select id="map-phone"></select>
            </div>

        </div>

        <h3>住所</h3>
        <div id="map-address"></div>

        <button class="btn btn-primary"
                style="margin-top:15px"
                onclick="saveKintoneMapping()">
            マッピングを保存
        </button>
    </div>

</section>

<!-- ========================================================
     メール
========================================================= -->

<section id="page-mail"
         class="page admin-only">

    <div class="page-title">
        <h1>メールサーバ設定</h1>

        <button class="btn"
                onclick="showList()">
            一覧へ戻る
        </button>
    </div>

    <div class="card">

        <div class="form-grid">

            <div>
                <label>SMTPサーバ</label>
                <input id="m-server">
            </div>

            <div>
                <label>SMTPポート</label>
                <input id="m-port"
                       type="number"
                       min="1"
                       max="65535">
            </div>

            <div>
                <label>暗号化方式</label>
                <select id="m-encryption">
                    <option value="none">
                        なし
                    </option>
                    <option value="starttls">
                        STARTTLS
                    </option>
                    <option value="ssl">
                        SSL/TLS
                    </option>
                </select>
            </div>

            <div>
                <label>SMTP認証</label>
                <select id="m-auth">
                    <option value="true">あり</option>
                    <option value="false">なし</option>
                </select>
            </div>

            <div>
                <label>SMTPユーザー名</label>
                <input id="m-user">
            </div>

            <div>
                <label>SMTPパスワード</label>
                <input id="m-password"
                       type="password"
                       placeholder="変更しない場合は空欄">
            </div>

            <div>
                <label>送信元メールアドレス</label>
                <input id="m-from">
            </div>

            <div>
                <label>送信元名</label>
                <input id="m-from-name">
            </div>

            <div>
                <label>返信先メールアドレス</label>
                <input id="m-reply">
            </div>

        </div>

        <div style="margin-top:18px">
            接続状態:
            <strong id="mail-status">
                未設定
            </strong>
        </div>

        <div class="toolbar"
             style="margin-top:18px">

            <button class="btn btn-primary"
                    onclick="saveMail()">
                設定を保存
            </button>

            <button class="btn"
                    onclick="testMail()">
                テストメール
            </button>
        </div>

        <div id="mail-result"
             class="result-box">
        </div>

    </div>

</section>

<!-- ========================================================
     回答
========================================================= -->

<section id="page-answer"
         class="page answer-only">

    <div class="page-title">
        <div>
            <h1 id="answer-title"></h1>
            <div id="answer-description"
                 class="help"></div>
        </div>
    </div>

    <div id="answer-content"></div>

</section>

<section id="page-confirm"
         class="page answer-only">

    <div class="page-title">
        <h1>回答内容確認</h1>
    </div>

    <div class="card"
         id="confirm-content"></div>

    <div class="toolbar">
        <button class="btn"
                onclick="answerBack()">
            戻る
        </button>

        <button class="btn btn-primary"
                onclick="confirmSubmit()">
            回答を送信
        </button>
    </div>

</section>

<section id="page-complete"
         class="page answer-only">

    <div class="card"
         style="text-align:center">

        <h1>回答ありがとうございました</h1>

        <p>
            回答の送信が完了しました。
        </p>

    </div>

</section>

</main>

<!-- ========================================================
     モーダル
========================================================= -->

<div id="modal"
     class="modal-backdrop">

    <div class="modal">

        <h2 id="modal-title">
            確認
        </h2>

        <div id="modal-message"></div>

        <div class="modal-actions">

            <button class="btn"
                    onclick="closeModal()">
                キャンセル
            </button>

            <button id="modal-ok"
                    class="btn btn-primary">
                実行
            </button>

        </div>

    </div>
</div>

<div id="toast"
     class="toast"></div>

<script>
"use strict";

/* ============================================================
 * 状態
 * ========================================================== */

const state = {
    screen: <?php echo $respondMode ? "'answer'" : "'list'"; ?>,

    surveys: [],
    customers: [],
    responses: [],
    sendHistory: [],

    kintone: {
        subdomain: "",
        appId: "",
        loginName: "",
        password: "",
        sslVerify: false,
        proxy: "",
        fields: [],
        mapping: {
            organization: "",
            name: "",
            email: "",
            department: "",
            phone: "",
            address: []
        }
    },

    mail: {},

    surveyFilter: "all",

    editingSurveyId: null,
    editingSurvey: null,

    analysisSurveyId: null,
    sendSurveyId: null,

    selectedCustomers: new Set(),
    selectedAnalysisQuestions: new Set(),

    answerSurveyId:
        <?php echo json_encode(
            $respondSurveyId,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ); ?>,

    answerCustomerId:
        <?php echo json_encode(
            $respondCustomerId,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ); ?>,

    answerToken:
        <?php echo json_encode(
            $respondToken,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ); ?>,

    answerSurvey: null,
    answerAnswers: {},
    answerRespondent: {
        organization: "",
        name: "",
        email: "",
        department: "",
        phone: "",
        address: ""
    },

    answerAlreadyCompleted: false
};

const API = new URL(
    window.location.href
);

API.search = "";

async function api(
    action,
    payload = null
) {
    const url =
        window.location.pathname +
        "?api=" +
        encodeURIComponent(action);

    const options = {
        method: payload === null
            ? "GET"
            : "POST",
        headers: {
            "Accept":
                "application/json"
        }
    };

    if (payload !== null) {
        options.headers[
            "Content-Type"
        ] = "application/json";

        options.body =
            JSON.stringify(payload);
    }

    let response;

    try {
        response =
            await fetch(
                url,
                options
            );
    } catch (error) {
        throw new Error(
            "PHP APIへ接続できませんでした。\n" +
            "index.phpをApache/PHP経由で開いているか確認してください。\n\n" +
            "詳細: " +
            error.message
        );
    }

    const text =
        await response.text();

    let json;

    try {
        json = JSON.parse(text);
    } catch (e) {
        throw new Error(
            "PHPからJSONではないレスポンスが返りました。\n" +
            "HTTP " +
            response.status +
            "\n\n" +
            text.slice(0, 1000)
        );
    }

    if (
        !response.ok ||
        json.success === false
    ) {
        throw new Error(
            json.error ||
            "API処理に失敗しました。"
        );
    }

    return json;
}

async function boot() {
    try {
        const data =
            await api("load");

        state.surveys =
            Array.isArray(data.surveys)
            ? data.surveys
            : [];

        state.customers =
            Array.isArray(data.customers)
            ? data.customers
            : [];

        state.responses =
            Array.isArray(data.responses)
            ? data.responses
            : [];

        state.sendHistory =
            Array.isArray(
                data.sendHistory
            )
            ? data.sendHistory
            : [];

        state.kintone =
            data.kintone ||
            state.kintone;

        state.mail =
            data.mail ||
            {};

        if (state.screen === "answer") {
            await bootAnswer();
        } else {
            showList();
        }

        document
            .getElementById("loading")
            .classList.add("hidden");
    } catch (error) {
        document
            .getElementById("loading")
            .classList.add("hidden");

        showFatal(
            error.message
        );
    }
}

function showFatal(message) {
    document.body.innerHTML = `
        <div style="
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:20px;
            background:#f8fafc;
        ">
            <div style="
                max-width:700px;
                background:#fff;
                border:1px solid #fecaca;
                border-radius:14px;
                padding:28px;
                box-shadow:0 10px 40px rgba(0,0,0,.08);
            ">
                <h1 style="color:#991b1b">
                    システムを起動できませんでした。
                </h1>
                <pre style="
                    white-space:pre-wrap;
                    background:#f8fafc;
                    padding:15px;
                    border-radius:8px;
                ">${escapeHtml(message)}</pre>

                <p>
                    index.phpをApache/PHP経由で開いていること、
                    dataディレクトリへの書き込み権限を確認してください。
                </p>

                <button
                    class="btn btn-primary"
                    onclick="location.reload()">
                    再読み込み
                </button>
            </div>
        </div>
    `;
}

/* ============================================================
 * Utility
 * ========================================================== */

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function escapeAttr(value) {
    return escapeHtml(value);
}

function formatDate(value) {
    if (!value) return "-";

    const d =
        new Date(value);

    if (Number.isNaN(d.getTime())) {
        return value;
    }

    return d.toLocaleString(
        "ja-JP"
    );
}

function toInputDate(value) {
    if (!value) return "";

    const d =
        new Date(value);

    if (Number.isNaN(d.getTime())) {
        return "";
    }

    const pad =
        n => String(n).padStart(2, "0");

    return d.getFullYear() +
        "-" +
        pad(d.getMonth() + 1) +
        "-" +
        pad(d.getDate()) +
        "T" +
        pad(d.getHours()) +
        ":" +
        pad(d.getMinutes());
}

function fromInputDate(value) {
    if (!value) return null;

    const d =
        new Date(value);

    return Number.isNaN(
        d.getTime()
    )
        ? null
        : d.toISOString();
}

function statusLabel(status) {
    return {
        draft: "下書き",
        published: "公開中",
        stopped: "停止",
        finished: "終了"
    }[status] || status;
}

function statusClass(status) {
    return "status status-" +
        status;
}

function toast(
    message,
    type = "success"
) {
    const el =
        document.getElementById(
            "toast"
        );

    el.textContent =
        message;

    el.className =
        "toast show " +
        type;

    setTimeout(
        () => {
            el.className =
                "toast";
        },
        3500
    );
}

function confirmModal(
    title,
    message,
    callback
) {
    document.getElementById(
        "modal-title"
    ).textContent = title;

    document.getElementById(
        "modal-message"
    ).innerHTML =
        escapeHtml(
            message
        ).replaceAll(
            "\n",
            "<br>"
        );

    document
        .getElementById("modal")
        .classList.add("show");

    document
        .getElementById("modal-ok")
        .onclick = async () => {
            closeModal();

            try {
                await callback();
            } catch (e) {
                toast(
                    e.message,
                    "error"
                );
            }
        };
}

function closeModal() {
    document
        .getElementById("modal")
        .classList.remove("show");
}

function hidePages() {
    document
        .querySelectorAll(".page")
        .forEach(
            page =>
                page.classList.remove(
                    "active"
                )
        );
}

function showPage(id) {
    hidePages();

    const page =
        document.getElementById(
            id
        );

    if (page) {
        page.classList.add(
            "active"
        );
    }
}

function findSurvey(id) {
    return state.surveys.find(
        s => s.id === id
    ) || null;
}

function findQuestion(
    survey,
    questionId
) {
    for (
        const group of
        survey.groups || []
    ) {
        for (
            const q of
            group.questions || []
        ) {
            if (
                q.id === questionId
            ) {
                return q;
            }
        }
    }

    return null;
}

function allQuestions(survey) {
    const result = [];

    for (
        const group of
        survey.groups || []
    ) {
        for (
            const q of
            group.questions || []
        ) {
            result.push(q);
        }
    }

    return result;
}

/* ============================================================
 * 管理画面
 * ========================================================== */

function showList() {
    document.body
        .classList.remove(
            "answer-mode"
        );

    state.screen = "list";

    showPage(
        "page-list"
    );

    renderSurveyList();
}

function setSurveyFilter(filter) {
    state.surveyFilter =
        filter;

    renderSurveyList();
}

function renderSurveyList() {
    const search =
        document
            .getElementById(
                "survey-search"
            )
            .value
            .trim()
            .toLowerCase();

    const sort =
        document
            .getElementById(
                "survey-sort"
            )
            .value;

    document
        .querySelectorAll(
            ".filter-tabs button"
        )
        .forEach(
            btn => {
                btn.classList.toggle(
                    "active",
                    btn.dataset.filter ===
                    state.surveyFilter
                );
            }
        );

    let surveys =
        [...state.surveys];

    surveys =
        surveys.filter(
            survey => {
                if (
                    state.surveyFilter !==
                    "all" &&
                    survey.status !==
                    state.surveyFilter
                ) {
                    return false;
                }

                if (
                    search &&
                    !String(
                        survey.title || ""
                    )
                    .toLowerCase()
                    .includes(search)
                ) {
                    return false;
                }

                return true;
            }
        );

    surveys.sort(
        (a,b) => {
            if (
                sort ===
                "responses_desc"
            ) {
                return (
                    surveyResponseCount(
                        b.id
                    ) -
                    surveyResponseCount(
                        a.id
                    )
                );
            }

            if (
                sort ===
                "responses_asc"
            ) {
                return (
                    surveyResponseCount(
                        a.id
                    ) -
                    surveyResponseCount(
                        b.id
                    )
                );
            }

            const av =
                sort.startsWith(
                    "start"
                )
                ? (
                    a.startDate || ""
                )
                : (
                    a.updatedAt || ""
                );

            const bv =
                sort.startsWith(
                    "start"
                )
                ? (
                    b.startDate || ""
                )
                : (
                    b.updatedAt || ""
                );

            return sort.endsWith(
                "_asc"
            )
                ? av.localeCompare(bv)
                : bv.localeCompare(av);
        }
    );

    const body =
        document.getElementById(
            "survey-table-body"
        );

    if (!surveys.length) {
        body.innerHTML = `
            <tr>
                <td colspan="6"
                    class="empty">
                    該当するアンケートはありません。
                </td>
            </tr>
        `;

        return;
    }

    body.innerHTML =
        surveys.map(
            survey => {
                const count =
                    surveyResponseCount(
                        survey.id
                    );

                return `
                <tr>
                    <td>
                        ${escapeHtml(
                            formatDate(
                                survey.updatedAt
                            )
                        )}
                    </td>

                    <td>
                        <strong>
                            ${escapeHtml(
                                survey.title
                            )}
                        </strong>
                    </td>

                    <td>
                        ${escapeHtml(
                            formatDate(
                                survey.startDate
                            )
                        )}
                        〜
                        ${escapeHtml(
                            formatDate(
                                survey.endDate
                            )
                        )}
                    </td>

                    <td>
                        <span class="${statusClass(
                            survey.status
                        )}">
                            ${statusLabel(
                                survey.status
                            )}
                        </span>
                    </td>

                    <td>${count}</td>

                    <td>
                        <div class="action-row">

                            <button
                                class="btn btn-small"
                                onclick="editSurvey('${escapeAttr(
                                    survey.id
                                )}')">
                                確認・編集
                            </button>

                            <button
                                class="btn btn-small"
                                onclick="openAnalysis('${escapeAttr(
                                    survey.id
                                )}')">
                                集計
                            </button>

                            <button
                                class="btn btn-small"
                                onclick="openSend('${escapeAttr(
                                    survey.id
                                )}')">
                                送信
                            </button>

                            <button
                                class="btn btn-small"
                                onclick="duplicateSurvey('${escapeAttr(
                                    survey.id
                                )}')">
                                複製
                            </button>

                            <button
                                class="btn btn-small btn-danger"
                                onclick="deleteSurvey('${escapeAttr(
                                    survey.id
                                )}')">
                                削除
                            </button>

                        </div>
                    </td>
                </tr>
                `;
            }
        ).join("");
}

/* ============================================================
 * Editor
 * ========================================================== */

function newSurvey() {
    state.editingSurveyId = null;

    state.editingSurvey = {
        id: null,
        title: "",
        description: "",
        startDate: null,
        endDate: null,
        questionNumberMode: "all",
        allowResubmission: false,
        status: "draft",
        groups: []
    };

    showEditor();
}

function editSurvey(id) {
    const survey =
        findSurvey(id);

    if (!survey) {
        toast(
            "アンケートが見つかりません。",
            "error"
        );
        return;
    }

    state.editingSurveyId =
        id;

    state.editingSurvey =
        JSON.parse(
            JSON.stringify(
                survey
            )
        );

    showEditor();
}

function showEditor() {
    showPage(
        "page-editor"
    );

    const s =
        state.editingSurvey;

    document.getElementById(
        "editor-title"
    ).textContent =
        s.id
        ? "アンケート編集"
        : "アンケート作成";

    document.getElementById(
        "editor-title-input"
    ).value =
        s.title || "";

    document.getElementById(
        "editor-description"
    ).value =
        s.description || "";

    document.getElementById(
        "editor-start"
    ).value =
        toInputDate(
            s.startDate
        );

    document.getElementById(
        "editor-end"
    ).value =
        toInputDate(
            s.endDate
        );

    document.getElementById(
        "editor-number-mode"
    ).value =
        s.questionNumberMode ||
        "all";

    document.getElementById(
        "editor-resubmission"
    ).value =
        String(
            !!s.allowResubmission
        );

    renderStatusSelect();
    renderEditorGroups();
}

function renderStatusSelect() {
    const select =
        document.getElementById(
            "editor-status"
        );

    const status =
        state.editingSurvey.status ||
        "draft";

    let options = [];

    if (status === "draft") {
        options = [
            ["draft", "下書き"],
            ["published", "公開中"]
        ];
    } else if (
        status === "published"
    ) {
        options = [
            ["published", "公開中"],
            ["stopped", "停止"]
        ];
    } else if (
        status === "stopped"
    ) {
        options = [
            ["stopped", "停止"],
            ["published", "公開中"]
        ];
    } else {
        options = [
            ["finished", "終了"]
        ];
    }

    select.innerHTML =
        options.map(
            ([value,label]) =>
                `<option value="${value}">
                    ${label}
                </option>`
        ).join("");

    select.value =
        status;

    select.disabled =
        status === "finished";
}

function readEditorIntoState() {
    const s =
        state.editingSurvey;

    s.title =
        document.getElementById(
            "editor-title-input"
        ).value.trim();

    s.description =
        document.getElementById(
            "editor-description"
        ).value.trim();

    s.startDate =
        fromInputDate(
            document.getElementById(
                "editor-start"
            ).value
        );

    s.endDate =
        fromInputDate(
            document.getElementById(
                "editor-end"
            ).value
        );

    s.questionNumberMode =
        document.getElementById(
            "editor-number-mode"
        ).value;

    s.allowResubmission =
        document.getElementById(
            "editor-resubmission"
        ).value === "true";

    recalcEditor();
}

function recalcEditor() {
    readEditorBasic();

    recalcLocalNumbers(
        state.editingSurvey
    );

    renderEditorGroups();
}

function readEditorBasic() {
    const s =
        state.editingSurvey;

    s.title =
        document.getElementById(
            "editor-title-input"
        ).value.trim();

    s.description =
        document.getElementById(
            "editor-description"
        ).value.trim();

    s.startDate =
        fromInputDate(
            document.getElementById(
                "editor-start"
            ).value
        );

    s.endDate =
        fromInputDate(
            document.getElementById(
                "editor-end"
            ).value
        );

    s.questionNumberMode =
        document.getElementById(
            "editor-number-mode"
        ).value;

    s.allowResubmission =
        document.getElementById(
            "editor-resubmission"
        ).value === "true";
}

function recalcLocalNumbers(
    survey
) {
    let global = 0;

    (survey.groups || [])
        .forEach(
            (group, gi) => {
                group.sortOrder =
                    gi + 1;

                (group.questions || [])
                    .forEach(
                        (q, qi) => {
                            global++;

                            q.groupId =
                                group.id;

                            q.sortOrder =
                                qi + 1;

                            q.questionNumber =
                                survey.questionNumberMode ===
                                "group"
                                ? `Q${gi + 1}-${qi + 1}`
                                : `Q${global}`;
                        }
                    );
            }
        );
}

function renderEditorGroups() {
    const root =
        document.getElementById(
            "editor-groups"
        );

    const s =
        state.editingSurvey;

    recalcLocalNumbers(s);

    if (!s.groups.length) {
        root.innerHTML = `
            <div class="card empty">
                グループがありません。
            </div>
        `;
        return;
    }

    root.innerHTML =
        s.groups.map(
            (group, gi) => `
            <div class="editor-group"
                 draggable="true"
                 data-group-id="${escapeAttr(
                     group.id
                 )}"
                 ondragstart="dragGroupStart(event,'${escapeAttr(
                     group.id
                 )}')"
                 ondragover="dragOver(event)"
                 ondrop="dropGroup(event,'${escapeAttr(
                     group.id
                 )}')">

                <div class="group-head">

                    <span class="drag-handle">
                        ☰
                    </span>

                    <input
                        value="${escapeAttr(
                            group.title
                        )}"
                        placeholder="グループタイトル"
                        onchange="updateGroupTitle('${escapeAttr(
                            group.id
                        )}',this.value)">

                    <button
                        class="btn btn-small btn-danger"
                        onclick="deleteGroup('${escapeAttr(
                            group.id
                        )}')">
                        グループ削除
                    </button>

                </div>

                ${
                    group.questions.length
                    ? group.questions.map(
                        q =>
                            renderEditorQuestion(
                                group,
                                q
                            )
                    ).join("")
                    : `
                        <div class="empty">
                            質問がありません。
                        </div>
                    `
                }

                <button
                    class="btn"
                    onclick="addQuestion('${escapeAttr(
                        group.id
                    )}')">
                    ＋ 質問を追加
                </button>

            </div>
            `
        ).join("");
}

function renderEditorQuestion(
    group,
    q
) {
    return `
        <div class="question"
             draggable="true"
             ondragstart="dragQuestionStart(event,'${escapeAttr(
                 q.id
             )}','${escapeAttr(
                 group.id
             )}')"
             ondragover="dragOver(event)"
             ondrop="dropQuestion(event,'${escapeAttr(
                 q.id
             )}','${escapeAttr(
                 group.id
             )}')">

            <div class="question-head">

                <span class="drag-handle">
                    ☰
                </span>

                <div class="question-number">
                    ${escapeHtml(
                        q.questionNumber
                    )}
                </div>

                <button
                    class="btn btn-small btn-danger"
                    onclick="deleteQuestion('${escapeAttr(
                        group.id
                    )}','${escapeAttr(
                        q.id
                    )}')">
                    削除
                </button>

            </div>

            <div style="margin-top:12px">

                <label>質問文</label>

                <textarea
                    onchange="updateQuestion('${escapeAttr(
                        group.id
                    )}','${escapeAttr(
                        q.id
                    )}','text',this.value)"
                >${escapeHtml(
                    q.text
                )}</textarea>

            </div>

            <div class="form-grid"
                 style="margin-top:12px">

                <div>
                    <label>回答形式</label>

                    <select
                        onchange="updateQuestion('${escapeAttr(
                            group.id
                        )}','${escapeAttr(
                            q.id
                        )}','type',this.value)">

                        <option
                            value="single"
                            ${
                                q.type ===
                                "single"
                                ? "selected"
                                : ""
                            }>
                            単一選択
                        </option>

                        <option
                            value="multiple"
                            ${
                                q.type ===
                                "multiple"
                                ? "selected"
                                : ""
                            }>
                            複数選択
                        </option>

                        <option
                            value="text"
                            ${
                                q.type ===
                                "text"
                                ? "selected"
                                : ""
                            }>
                            自由記述
                        </option>

                    </select>
                </div>

                <div>
                    <label>必須 / 任意</label>

                    <select
                        onchange="updateQuestion('${escapeAttr(
                            group.id
                        )}','${escapeAttr(
                            q.id
                        )}','required',this.value)">

                        <option
                            value="true"
                            ${
                                q.required
                                ? "selected"
                                : ""
                            }>
                            必須
                        </option>

                        <option
                            value="false"
                            ${
                                !q.required
                                ? "selected"
                                : ""
                            }>
                            任意
                        </option>

                    </select>
                </div>

            </div>

            ${
                q.type !== "text"
                ? renderChoices(
                    group,
                    q
                )
                : ""
            }

            ${
                q.type === "single"
                ? renderBranches(
                    group,
                    q
                )
                : ""
            }

        </div>
    `;
}

function renderChoices(
    group,
    q
) {
    return `
        <div style="margin-top:14px">
            <strong>選択肢</strong>

            ${
                q.choices.map(
                    choice => `
                    <div class="choice-row">

                        <input
                            value="${escapeAttr(
                                choice.label
                            )}"
                            onchange="updateChoice(
                                '${escapeAttr(
                                    group.id
                                )}',
                                '${escapeAttr(
                                    q.id
                                )}',
                                '${escapeAttr(
                                    choice.id
                                )}',
                                this.value
                            )">

                        <button
                            class="btn btn-small btn-danger"
                            onclick="deleteChoice(
                                '${escapeAttr(
                                    group.id
                                )}',
                                '${escapeAttr(
                                    q.id
                                )}',
                                '${escapeAttr(
                                    choice.id
                                )}'
                            )">
                            削除
                        </button>

                    </div>
                    `
                ).join("")
            }

            <button
                class="btn btn-small"
                onclick="addChoice(
                    '${escapeAttr(
                        group.id
                    )}',
                    '${escapeAttr(
                        q.id
                    )}'
                )">
                ＋ 選択肢
            </button>
        </div>
    `;
}

function renderBranches(
    group,
    q
) {
    const questions =
        allQuestions(
            state.editingSurvey
        );

    return `
        <div style="margin-top:14px">
            <strong>条件分岐</strong>

            ${
                q.choices.map(
                    choice => {
                        const branch =
                            (
                                q.branches ||
                                []
                            ).find(
                                b =>
                                    b.choiceId ===
                                    choice.id
                            );

                        return `
                        <div class="branch-row">

                            <div>
                                <small>
                                    ${escapeHtml(
                                        choice.label
                                    )}
                                </small>
                            </div>

                            <select
                                onchange="updateBranch(
                                    '${escapeAttr(
                                        group.id
                                    )}',
                                    '${escapeAttr(
                                        q.id
                                    )}',
                                    '${escapeAttr(
                                        choice.id
                                    )}',
                                    this.value
                                )">

                                <option value="">
                                    次の質問なし
                                </option>

                                ${
                                    questions
                                        .filter(
                                            x =>
                                                x.id !==
                                                q.id
                                        )
                                        .map(
                                            x =>
                                                `<option
                                                    value="${escapeAttr(
                                                        x.id
                                                    )}"
                                                    ${
                                                        branch &&
                                                        branch.nextQuestionId ===
                                                        x.id
                                                        ? "selected"
                                                        : ""
                                                    }>
                                                    ${escapeHtml(
                                                        x.questionNumber
                                                    )} -
                                                    ${escapeHtml(
                                                        x.text
                                                    )}
                                                </option>`
                                        )
                                        .join("")
                                }

                            </select>

                            <span></span>

                        </div>
                        `;
                    }
                ).join("")
            }

            <div class="help">
                条件分岐は内部IDで保持されます。
            </div>
        </div>
    `;
}

function findEditorGroup(id) {
    return state.editingSurvey.groups.find(
        g => g.id === id
    );
}

function findEditorQuestion(
    groupId,
    questionId
) {
    const group =
        findEditorGroup(
            groupId
        );

    if (!group) return null;

    return group.questions.find(
        q => q.id === questionId
    ) || null;
}

function updateGroupTitle(
    id,
    value
) {
    const group =
        findEditorGroup(id);

    if (group) {
        group.title =
            value.trim();
    }
}

function addGroup() {
    const group =
        makeLocalGroup(
            "新しいグループ"
        );

    state.editingSurvey.groups.push(
        group
    );

    recalcEditor();
}

function makeLocalGroup(
    title
) {
    return {
        id:
            "group_" +
            crypto.randomUUID(),
        title,
        sortOrder:0,
        questions:[]
    };
}

function addQuestion(
    groupId
) {
    const group =
        findEditorGroup(
            groupId
        );

    if (!group) return;

    group.questions.push({
        id:
            "question_" +
            crypto.randomUUID(),
        groupId,
        sortOrder:0,
        questionNumber:"",
        text:"",
        type:"single",
        required:false,
        choices:[
            {
                id:
                    "choice_" +
                    crypto.randomUUID(),
                label:"選択肢1",
                sortOrder:1
            },
            {
                id:
                    "choice_" +
                    crypto.randomUUID(),
                label:"選択肢2",
                sortOrder:2
            }
        ],
        branches:[]
    });

    recalcEditor();
}

function updateQuestion(
    groupId,
    questionId,
    field,
    value
) {
    const q =
        findEditorQuestion(
            groupId,
            questionId
        );

    if (!q) return;

    if (field === "required") {
        q.required =
            value === "true";
    } else if (
        field === "type"
    ) {
        q.type = value;

        if (value === "text") {
            q.choices = [];
            q.branches = [];
        } else if (
            !q.choices.length
        ) {
            q.choices = [
                {
                    id:
                        "choice_" +
                        crypto.randomUUID(),
                    label:"選択肢1",
                    sortOrder:1
                },
                {
                    id:
                        "choice_" +
                        crypto.randomUUID(),
                    label:"選択肢2",
                    sortOrder:2
                }
            ];
        }

        if (value !== "single") {
            q.branches = [];
        }
    } else {
        q[field] = value;
    }

    recalcEditor();
}

function deleteGroup(id) {
    const group =
        findEditorGroup(id);

    if (!group) return;

    const message =
        group.questions.length
        ? "質問が存在します。このグループを削除しますか？"
        : "このグループを削除しますか？";

    confirmModal(
        "グループ削除",
        message,
        async () => {
            state.editingSurvey.groups =
                state.editingSurvey.groups
                    .filter(
                        g => g.id !== id
                    );

            recalcEditor();

            toast(
                "グループを削除しました。"
            );
        }
    );
}

function deleteQuestion(
    groupId,
    questionId
) {
    confirmModal(
        "質問削除",
        "この質問を削除しますか？",
        async () => {
            const group =
                findEditorGroup(
                    groupId
                );

            if (!group) return;

            group.questions =
                group.questions.filter(
                    q =>
                        q.id !==
                        questionId
                );

            recalcEditor();

            toast(
                "質問を削除しました。"
            );
        }
    );
}

function addChoice(
    groupId,
    questionId
) {
    const q =
        findEditorQuestion(
            groupId,
            questionId
        );

    if (!q) return;

    q.choices.push({
        id:
            "choice_" +
            crypto.randomUUID(),
        label:
            "選択肢" +
            (
                q.choices.length + 1
            ),
        sortOrder:
            q.choices.length + 1
    });

    renderEditorGroups();
}

function updateChoice(
    groupId,
    questionId,
    choiceId,
    value
) {
    const q =
        findEditorQuestion(
            groupId,
            questionId
        );

    if (!q) return;

    const choice =
        q.choices.find(
            c => c.id === choiceId
        );

    if (choice) {
        choice.label =
            value.trim();
    }
}

function deleteChoice(
    groupId,
    questionId,
    choiceId
) {
    const q =
        findEditorQuestion(
            groupId,
            questionId
        );

    if (!q) return;

    q.choices =
        q.choices.filter(
            c => c.id !== choiceId
        );

    q.branches =
        (q.branches || [])
            .filter(
                b =>
                    b.choiceId !==
                    choiceId
            );

    renderEditorGroups();
}

function updateBranch(
    groupId,
    questionId,
    choiceId,
    nextQuestionId
) {
    const q =
        findEditorQuestion(
            groupId,
            questionId
        );

    if (!q) return;

    q.branches =
        q.branches || [];

    let branch =
        q.branches.find(
            b =>
                b.choiceId ===
                choiceId
        );

    if (!branch) {
        branch = {
            choiceId,
            nextQuestionId
        };

        q.branches.push(
            branch
        );
    } else {
        branch.nextQuestionId =
            nextQuestionId;
    }
}

function saveEditor() {
    readEditorBasic();

    if (
        !state.editingSurvey.title
    ) {
        toast(
            "タイトルを入力してください。",
            "error"
        );
        return;
    }

    const payload =
        JSON.parse(
            JSON.stringify(
                state.editingSurvey
            )
        );

    api(
        "save_survey",
        payload
    ).then(
        data => {
            const saved =
                data.survey;

            const index =
                state.surveys.findIndex(
                    s =>
                        s.id ===
                        saved.id
                );

            if (index >= 0) {
                state.surveys[index] =
                    saved;
            } else {
                state.surveys.push(
                    saved
                );
            }

            toast(
                "保存しました。"
            );

            showList();
        }
    ).catch(
        e =>
            toast(
                e.message,
                "error"
            )
    );
}

function cancelEditor() {
    confirmModal(
        "編集内容を破棄",
        "編集内容を破棄して前画面へ戻りますか？",
        async () => {
            showList();
        }
    );
}

function statusChanged() {
    const select =
        document.getElementById(
            "editor-status"
        );

    const current =
        state.editingSurvey.status;

    const next =
        select.value;

    if (next === current) {
        return;
    }

    const messages = {
        published:
            "このアンケートを公開しますか？",
        stopped:
            "このアンケートを停止しますか？"
    };

    select.value =
        current;

    confirmModal(
        "状態変更確認",
        messages[next] ||
        "状態を変更しますか？",
        async () => {
            await api(
                "change_status",
                {
                    surveyId:
                        state.editingSurvey.id,
                    status:next
                }
            );

            const survey =
                findSurvey(
                    state.editingSurvey.id
                );

            if (survey) {
                survey.status =
                    next;
                survey.updatedAt =
                    new Date().toISOString();
            }

            state.editingSurvey.status =
                next;

            renderStatusSelect();

            toast(
                "状態を変更しました。"
            );
        }
    );
}

/* ============================================================
 * D&D
 * ========================================================== */

let dragGroupId = null;
let dragQuestion = null;

function dragOver(event) {
    event.preventDefault();

    event.currentTarget
        .classList.add(
            "drag-over"
        );
}

function dragGroupStart(
    event,
    groupId
) {
    dragGroupId =
        groupId;

    event.dataTransfer.effectAllowed =
        "move";
}

function dropGroup(
    event,
    targetId
) {
    event.preventDefault();

    event.currentTarget
        .classList.remove(
            "drag-over"
        );

    if (
        !dragGroupId ||
        dragGroupId === targetId
    ) {
        return;
    }

    const groups =
        state.editingSurvey.groups;

    const from =
        groups.findIndex(
            g => g.id === dragGroupId
        );

    const to =
        groups.findIndex(
            g => g.id === targetId
        );

    if (
        from < 0 ||
        to < 0
    ) {
        return;
    }

    const [item] =
        groups.splice(
            from,
            1
        );

    groups.splice(
        to,
        0,
        item
    );

    dragGroupId = null;

    recalcEditor();
}

function dragQuestionStart(
    event,
    questionId,
    groupId
) {
    dragQuestion = {
        questionId,
        groupId
    };

    event.dataTransfer.effectAllowed =
        "move";
}

function dropQuestion(
    event,
    targetQuestionId,
    targetGroupId
) {
    event.preventDefault();

    event.currentTarget
        .classList.remove(
            "drag-over"
        );

    if (!dragQuestion) {
        return;
    }

    const sourceGroup =
        findEditorGroup(
            dragQuestion.groupId
        );

    const targetGroup =
        findEditorGroup(
            targetGroupId
        );

    if (
        !sourceGroup ||
        !targetGroup
    ) {
        return;
    }

    const sourceIndex =
        sourceGroup.questions
            .findIndex(
                q =>
                    q.id ===
                    dragQuestion.questionId
            );

    if (sourceIndex < 0) {
        return;
    }

    const [question] =
        sourceGroup.questions.splice(
            sourceIndex,
            1
        );

    const targetIndex =
        targetGroup.questions
            .findIndex(
                q =>
                    q.id ===
                    targetQuestionId
            );

    question.groupId =
        targetGroup.id;

    if (
        targetIndex < 0
    ) {
        targetGroup.questions.push(
            question
        );
    } else {
        targetGroup.questions.splice(
            targetIndex,
            0,
            question
        );
    }

    dragQuestion = null;

    recalcEditor();
}

/* ============================================================
 * Preview
 * ========================================================== */

function previewEditor() {
    readEditorBasic();

    showPage(
        "page-preview"
    );

    previewMode("pc");
}

function previewMode(mode) {
    const root =
        document.getElementById(
            "preview-container"
        );

    root.classList.toggle(
        "mobile",
        mode === "mobile"
    );

    const survey =
        state.editingSurvey;

    root.innerHTML = `
        <div class="card">
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

        ${
            survey.groups.map(
                group => `
                    <div class="card">
                        <h2>
                            ${escapeHtml(
                                group.title
                            )}
                        </h2>

                        ${
                            group.questions
                                .map(
                                    q =>
                                        previewQuestion(
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
}

function previewQuestion(q) {
    return `
        <div class="answer-question">

            <h3>
                ${escapeHtml(
                    q.questionNumber
                )}
                ${escapeHtml(
                    q.text
                )}
                ${
                    q.required
                    ? " <span style='color:#dc2626'>*</span>"
                    : ""
                }
            </h3>

            ${
                q.type === "text"
                ? `
                    <textarea
                        disabled
                        placeholder="自由記述">
                    </textarea>
                `
                : q.choices.map(
                    choice => `
                        <label
                            class="answer-choice">

                            <input
                                type="${
                                    q.type ===
                                    "single"
                                    ? "radio"
                                    : "checkbox"
                                }"
                                disabled>

                            ${escapeHtml(
                                choice.label
                            )}

                        </label>
                    `
                ).join("")
            }

        </div>
    `;
}

function backEditor() {
    showEditor();
}

/* ============================================================
 * 複製 / 削除
 * ========================================================== */

function duplicateSurvey(id) {
    confirmModal(
        "アンケート複製",
        "このアンケートを複製しますか？",
        async () => {
            const data =
                await api(
                    "duplicate_survey",
                    {
                        surveyId:id
                    }
                );

            state.surveys.push(
                data.survey
            );

            toast(
                "アンケートを複製しました。"
            );

            renderSurveyList();
        }
    );
}

function deleteSurvey(id) {
    confirmModal(
        "アンケート削除",
        "このアンケートを削除しますか？",
        async () => {
            await api(
                "delete_survey",
                {
                    surveyId:id
                }
            );

            state.surveys =
                state.surveys.filter(
                    s => s.id !== id
                );

            toast(
                "削除しました。"
            );

            renderSurveyList();
        }
    );
}

/* ============================================================
 * 送信
 * ========================================================== */

function openSend(id) {
    const survey =
        findSurvey(id);

    if (!survey) {
        toast(
            "対象アンケートが見つかりません。",
            "error"
        );
        return;
    }

    state.sendSurveyId =
        id;

    state.selectedCustomers =
        new Set();

    showPage(
        "page-send"
    );

    document.getElementById(
        "send-survey-title"
    ).textContent =
        "対象アンケート: " +
        survey.title;

    if (
        !document.getElementById(
            "mail-subject"
        ).value
    ) {
        document.getElementById(
            "mail-subject"
        ).value =
            "【アンケートのお願い】" +
            survey.title;
    }

    if (
        !document.getElementById(
            "mail-body"
        ).value
    ) {
        document.getElementById(
            "mail-body"
        ).value =
            "{顧客名} 様\n\n" +
            "アンケートへのご協力をお願いいたします。\n\n" +
            "{アンケートURL}\n";
    }

    renderCustomers();
    renderHistory();
}

function renderCustomers() {
    const search =
        document.getElementById(
            "customer-search"
        ).value
        .trim()
        .toLowerCase();

    const status =
        document.getElementById(
            "customer-status-filter"
        ).value;

    const list =
        state.customers.filter(
            customer => {
                const haystack =
                    [
                        customer.name,
                        customer.organization,
                        customer.email,
                        customer.status
                    ]
                    .join(" ")
                    .toLowerCase();

                if (
                    search &&
                    !haystack.includes(
                        search
                    )
                ) {
                    return false;
                }

                if (
                    status &&
                    customer.status !==
                    status
                ) {
                    return false;
                }

                return true;
            }
        );

    const body =
        document.getElementById(
            "customer-table-body"
        );

    body.innerHTML =
        list.map(
            c => `
                <tr>

                    <td>
                        <input
                            type="checkbox"
                            ${
                                state.selectedCustomers.has(
                                    c.id
                                )
                                ? "checked"
                                : ""
                            }
                            onchange="toggleCustomer(
                                '${escapeAttr(
                                    c.id
                                )}',
                                this.checked
                            )">
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
                            formatDate(
                                c.lastSentAt
                            )
                        )}
                    </td>

                    <td>
                        ${Number(
                            c.sendCount || 0
                        )}
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
        ).join("");
}

function toggleCustomer(
    id,
    checked
) {
    if (checked) {
        state.selectedCustomers.add(
            id
        );
    } else {
        state.selectedCustomers.delete(
            id
        );
    }
}

function selectAllCustomers() {
    state.customers.forEach(
        c =>
            state.selectedCustomers.add(
                c.id
            )
    );

    renderCustomers();
}

function clearCustomerSelection() {
    state.selectedCustomers.clear();

    renderCustomers();
}

function prepareReminder() {
    state.selectedCustomers.clear();

    state.customers.forEach(
        c => {
            if (
                c.status ===
                "送信済み / 未回答"
            ) {
                state.selectedCustomers.add(
                    c.id
                );
            }
        }
    );

    renderCustomers();

    toast(
        "未回答者を送信対象にしました。"
    );
}

function showMailPreview() {
    const selected =
        state.customers.filter(
            c =>
                state.selectedCustomers.has(
                    c.id
                )
        );

    if (!selected.length) {
        toast(
            "顧客を選択してください。",
            "error"
        );
        return;
    }

    const subject =
        document.getElementById(
            "mail-subject"
        ).value;

    const body =
        document.getElementById(
            "mail-body"
        ).value;

    const survey =
        findSurvey(
            state.sendSurveyId
        );

    const root =
        document.getElementById(
            "mail-preview"
        );

    root.innerHTML =
        `
        <div class="result-box">
            <h3>送信予定内容</h3>

            ${
                selected.map(
                    c => {
                        const url =
                            buildSurveyUrl(
                                survey,
                                c
                            );

                        return `
                            <div class="card">

                                <strong>
                                    ${escapeHtml(
                                        c.name
                                    )}
                                    /
                                    ${escapeHtml(
                                        c.email
                                    )}
                                </strong>

                                <hr>

                                <div>
                                    <strong>
                                        件名
                                    </strong>
                                </div>

                                <div>
                                    ${escapeHtml(
                                        expandMail(
                                            subject,
                                            c,
                                            url
                                        )
                                    )}
                                </div>

                                <br>

                                <div>
                                    <strong>
                                        本文
                                    </strong>
                                </div>

                                <div class="mail-preview">
                                    ${escapeHtml(
                                        expandMail(
                                            body,
                                            c,
                                            url
                                        )
                                    )}
                                </div>

                            </div>
                        `;
                    }
                ).join("")
            }

        </div>
        `;
}

function buildSurveyUrl(
    survey,
    customer
) {
    return (
        window.location.origin +
        window.location.pathname +
        "?respond=1&survey=" +
        encodeURIComponent(
            survey.id
        ) +
        "&customer=" +
        encodeURIComponent(
            customer.id
        )
    );
}

function expandMail(
    text,
    customer,
    url
) {
    return String(text)
        .replaceAll(
            "{顧客名}",
            customer.name || ""
        )
        .replaceAll(
            "{アンケートURL}",
            url
        );
}

function confirmSend(
    type
) {
    const ids =
        [...state.selectedCustomers];

    if (!ids.length) {
        toast(
            "顧客を選択してください。",
            "error"
        );
        return;
    }

    const resend =
        type === "再送";

    confirmModal(
        type,
        `${ids.length}件を${type}します。実際にSMTP通信を行います。よろしいですか？`,
        async () => {
            await executeSend(
                type
            );
        }
    );
}

async function executeSend(
    type
) {
    const data =
        await api(
            "send",
            {
                surveyId:
                    state.sendSurveyId,
                customerIds:
                    [
                        ...state.selectedCustomers
                    ],
                subject:
                    document.getElementById(
                        "mail-subject"
                    ).value,
                body:
                    document.getElementById(
                        "mail-body"
                    ).value,
                sendType:type
            }
        );

    data.results.forEach(
        result => {
            const customer =
                state.customers.find(
                    c =>
                        c.id ===
                        result.customerId
                );

            if (
                customer &&
                result.success
            ) {
                customer.status =
                    "送信済み / 未回答";

                customer.lastSentAt =
                    new Date().toISOString();

                customer.sendCount =
                    Number(
                        customer.sendCount || 0
                    ) + 1;
            }
        }
    );

    state.sendHistory.push({
        sentAt:
            data.summary.sentAt,
        sendType:type,
        count:
            data.summary.total,
        successCount:
            data.summary.success,
        failureCount:
            data.summary.failure,
        subject:
            document.getElementById(
                "mail-subject"
            ).value
    });

    renderCustomers();
    renderHistory();

    document.getElementById(
        "send-result"
    ).innerHTML = `
        <div class="result-box">

            <p>
                対象件数:
                <strong>
                    ${data.summary.total}
                </strong>
            </p>

            <p style="color:#047857">
                成功:
                <strong>
                    ${data.summary.success}
                </strong>
            </p>

            <p style="color:#b91c1c">
                失敗:
                <strong>
                    ${data.summary.failure}
                </strong>
            </p>

            <p>
                送信日時:
                ${escapeHtml(
                    formatDate(
                        data.summary.sentAt
                    )
                )}
            </p>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>顧客</th>
                        <th>メール</th>
                        <th>結果</th>
                        <th>詳細</th>
                    </tr>
                    </thead>

                    <tbody>
                    ${
                        data.results.map(
                            r => `
                            <tr>
                                <td>
                                    ${escapeHtml(
                                        r.customerName
                                    )}
                                </td>

                                <td>
                                    ${escapeHtml(
                                        r.email
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
                                        r.error || ""
                                    )}
                                </td>
                            </tr>
                            `
                        ).join("")
                    }
                    </tbody>
                </table>
            </div>

        </div>
    `;

    toast(
        `${type}を実行しました。`
    );
}

function renderHistory() {
    const root =
        document.getElementById(
            "send-history"
        );

    const list =
        state.sendHistory.filter(
            h =>
                h.surveyId ===
                state.sendSurveyId
        );

    if (!list.length) {
        root.innerHTML = `
            <div class="empty">
                送信履歴はありません。
            </div>
        `;

        return;
    }

    root.innerHTML =
        list
            .slice()
            .reverse()
            .map(
                h => `
                    <details class="result-box">
                        <summary>
                            ${escapeHtml(
                                formatDate(
                                    h.sentAt
                                )
                            )}
                            /
                            ${escapeHtml(
                                h.sendType
                            )}
                            /
                            ${Number(
                                h.count || 0
                            )}件
                        </summary>

                        <p>
                            件名:
                            ${escapeHtml(
                                h.subject || ""
                            )}
                        </p>

                        ${
                            Array.isArray(
                                h.targets
                            )
                            ? h.targets.map(
                                target => `
                                    <div class="mail-preview"
                                         style="margin-top:10px">

                                        <strong>
                                            ${escapeHtml(
                                                target.customerName
                                            )}
                                        </strong>

                                        <br>

                                        URL:
                                        ${escapeHtml(
                                            target.url || ""
                                        )}

                                        <hr>

                                        ${escapeHtml(
                                            target.body || ""
                                        )}

                                    </div>
                                `
                            ).join("")
                            : ""
                        }

                    </details>
                `
            ).join("");
}

/* ============================================================
 * 集計
 * ========================================================== */

function openAnalysis(id) {
    const survey =
        findSurvey(id);

    if (!survey) {
        toast(
            "対象アンケートが見つかりません。",
            "error"
        );
        return;
    }

    state.analysisSurveyId =
        id;

    showPage(
        "page-analysis"
    );

    document.getElementById(
        "analysis-survey-title"
    ).textContent =
        "対象アンケート: " +
        survey.title;

    const surveyResponses =
        state.responses.filter(
            r =>
                r.surveyId ===
                id &&
                r.status ===
                "completed"
        );

    const sendTargets =
        state.customers.filter(
            c =>
                c.status ===
                "送信済み / 未回答" ||
                c.status ===
                "回答済み"
        );

    const answeredCustomerIds =
        new Set(
            surveyResponses
                .map(
                    r =>
                        r.customerId
                )
                .filter(Boolean)
        );

    const unregistered =
        surveyResponses.filter(
            r =>
                !r.customerId
        ).length;

    const unanswered =
        Math.max(
            0,
            sendTargets.length -
            answeredCustomerIds.size
        );

    const rate =
        sendTargets.length
        ? (
            surveyResponses.length /
            sendTargets.length *
            100
        ).toFixed(1)
        : "0.0";

    document.getElementById(
        "analysis-summary"
    ).innerHTML = `
        <div class="summary-card">
            <div class="summary-label">
                送信対象者数
            </div>
            <div class="summary-value">
                ${sendTargets.length}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">
                回答数
            </div>
            <div class="summary-value">
                ${surveyResponses.length}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">
                未登録顧客回答
            </div>
            <div class="summary-value">
                ${unregistered}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">
                未回答
            </div>
            <div class="summary-value">
                ${unanswered}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">
                回答率
            </div>
            <div class="summary-value">
                ${rate}%
            </div>
        </div>
    `;

    state.selectedAnalysisQuestions =
        new Set(
            allQuestions(
                survey
            ).map(
                q => q.id
            )
        );

    renderAnalysisQuestions(
        survey,
        surveyResponses
    );

    renderAnalysisResponses(
        survey,
        surveyResponses
    );
}

function renderAnalysisQuestions(
    survey,
    responses
) {
    const root =
        document.getElementById(
            "analysis-questions"
        );

    root.innerHTML =
        survey.groups.map(
            group => `
                <div class="card">

                    <h2>
                        ${escapeHtml(
                            group.title
                        )}
                    </h2>

                    ${
                        group.questions.map(
                            q =>
                                renderAnalysisQuestion(
                                    q,
                                    responses
                                )
                        ).join("")
                    }

                </div>
            `
        ).join("");
}

function renderAnalysisQuestion(
    q,
    responses
) {
    const checked =
        state.selectedAnalysisQuestions
            .has(q.id);

    if (q.type === "text") {
        const answers =
            responses.map(
                r => {
                    const answer =
                        r.answers?.[
                            q.id
                        ];

                    return {
                        response:r,
                        value:
                            Array.isArray(
                                answer
                            )
                            ? answer.join(
                                ", "
                            )
                            : (
                                answer ??
                                ""
                            )
                    };
                }
            ).filter(
                x => x.value !== ""
            );

        return `
            <div class="result-box">

                <label>
                    <input
                        type="checkbox"
                        ${
                            checked
                            ? "checked"
                            : ""
                        }
                        onchange="toggleAnalysisQuestion(
                            '${escapeAttr(
                                q.id
                            )}',
                            this.checked
                        )">

                    ${escapeHtml(
                        q.questionNumber
                    )}
                    ${escapeHtml(
                        q.text
                    )}
                </label>

                ${
                    checked
                    ? `
                        ${
                            answers.length
                            ? answers.map(
                                a => `
                                    <div
                                        class="mail-preview"
                                        style="margin-top:8px">

                                        ${escapeHtml(
                                            a.value
                                        )}

                                    </div>
                                `
                            ).join("")
                            : `
                                <div class="empty">
                                    回答なし
                                </div>
                            `
                        }
                    `
                    : ""
                }

            </div>
        `;
    }

    const counts = {};

    q.choices.forEach(
        c =>
            counts[c.id] = 0
    );

    let total = 0;

    responses.forEach(
        r => {
            const answer =
                r.answers?.[
                    q.id
                ];

            const values =
                Array.isArray(answer)
                ? answer
                : (
                    answer
                    ? [answer]
                    : []
                );

            values.forEach(
                id => {
                    if (
                        counts[id] !==
                        undefined
                    ) {
                        counts[id]++;
                        total++;
                    }
                }
            );
        }
    );

    return `
        <div class="result-box">

            <label>
                <input
                    type="checkbox"
                    ${
                        checked
                        ? "checked"
                        : ""
                    }
                    onchange="toggleAnalysisQuestion(
                        '${escapeAttr(
                            q.id
                        )}',
                        this.checked
                    )">

                ${escapeHtml(
                    q.questionNumber
                )}
                ${escapeHtml(
                    q.text
                )}
            </label>

            ${
                checked
                ? q.choices.map(
                    c => {
                        const count =
                            counts[c.id] ||
                            0;

                        const percentage =
                            total
                            ? (
                                count /
                                total *
                                100
                            )
                            : 0;

                        return `
                            <div style="
                                margin-top:15px">

                                <div>
                                    <strong>
                                        ${escapeHtml(
                                            c.label
                                        )}
                                    </strong>
                                    :
                                    ${count}件
                                    /
                                    ${percentage.toFixed(
                                        1
                                    )}%
                                </div>

                                <div class="bar">
                                    <div
                                        style="width:${percentage}%">
                                    </div>
                                </div>

                            </div>
                        `;
                    }
                ).join("")
                : ""
            }

        </div>
    `;
}

function toggleAnalysisQuestion(
    id,
    checked
) {
    if (checked) {
        state.selectedAnalysisQuestions.add(
            id
        );
    } else {
        state.selectedAnalysisQuestions.delete(
            id
        );
    }

    const survey =
        findSurvey(
            state.analysisSurveyId
        );

    const responses =
        state.responses.filter(
            r =>
                r.surveyId ===
                state.analysisSurveyId &&
                r.status ===
                "completed"
        );

    renderAnalysisQuestions(
        survey,
        responses
    );
}

function selectAllAnalysis() {
    const survey =
        findSurvey(
            state.analysisSurveyId
        );

    if (!survey) return;

    state.selectedAnalysisQuestions =
        new Set(
            allQuestions(
                survey
            ).map(
                q => q.id
            )
        );

    openAnalysis(
        state.analysisSurveyId
    );
}

function clearAnalysisSelection() {
    state.selectedAnalysisQuestions.clear();

    const survey =
        findSurvey(
            state.analysisSurveyId
        );

    if (!survey) return;

    const responses =
        state.responses.filter(
            r =>
                r.surveyId ===
                state.analysisSurveyId &&
                r.status ===
                "completed"
        );

    renderAnalysisQuestions(
        survey,
        responses
    );
}

function renderAnalysisResponses(
    survey,
    responses
) {
    const root =
        document.getElementById(
            "analysis-responses"
        );

    if (!responses.length) {
        root.innerHTML = `
            <div class="empty">
                回答データがありません。
            </div>
        `;

        return;
    }

    root.innerHTML =
        responses.map(
            response => `
                <details class="result-box">

                    <summary>
                        ${escapeHtml(
                            response.respondent?.name ||
                            "未登録回答者"
                        )}
                        /
                        ${escapeHtml(
                            formatDate(
                                response.submittedAt
                            )
                        )}
                    </summary>

                    <div style="margin-top:12px">

                        <p>
                            組織:
                            ${escapeHtml(
                                response.respondent?.organization ||
                                ""
                            )}
                        </p>

                        <p>
                            メール:
                            ${escapeHtml(
                                response.respondent?.email ||
                                ""
                            )}
                        </p>

                        ${
                            allQuestions(
                                survey
                            ).map(
                                q => {
                                    const value =
                                        response.answers?.[
                                            q.id
                                        ];

                                    return `
                                        <div class="result-box">

                                            <strong>
                                                ${escapeHtml(
                                                    q.questionNumber
                                                )}
                                                ${escapeHtml(
                                                    q.text
                                                )}
                                            </strong>

                                            <div>
                                                ${escapeHtml(
                                                    Array.isArray(
                                                        value
                                                    )
                                                    ? value.join(
                                                        ", "
                                                    )
                                                    : (
                                                        value ??
                                                        ""
                                                    )
                                                )}
                                            </div>

                                        </div>
                                    `;
                                }
                            ).join("")
                        }

                    </div>

                </details>
            `
        ).join("");
}

/* ============================================================
 * CSV / PDF
 * ========================================================== */

function exportCsv() {
    const survey =
        findSurvey(
            state.analysisSurveyId
        );

    if (!survey) {
        toast(
            "対象アンケートがありません。",
            "error"
        );
        return;
    }

    const questions =
        allQuestions(
            survey
        );

    const rows = [];

    rows.push([
        "回答ID",
        "回答日時",
        "組織名",
        "氏名",
        "メールアドレス",
        "部署名",
        "電話番号",
        "住所",
        ...questions.map(
            q =>
                q.questionNumber +
                " " +
                q.text
        )
    ]);

    state.responses
        .filter(
            r =>
                r.surveyId ===
                survey.id &&
                r.status ===
                "completed"
        )
        .forEach(
            r => {
                rows.push([
                    r.id,
                    r.submittedAt,
                    r.respondent?.organization || "",
                    r.respondent?.name || "",
                    r.respondent?.email || "",
                    r.respondent?.department || "",
                    r.respondent?.phone || "",
                    r.respondent?.address || "",
                    ...questions.map(
                        q => {
                            const value =
                                r.answers?.[
                                    q.id
                                ];

                            return Array.isArray(
                                value
                            )
                            ? value.join(
                                " / "
                            )
                            : (
                                value ??
                                ""
                            );
                        }
                    )
                ]);
            }
        );

    const csv =
        "\uFEFF" +
        rows.map(
            row =>
                row.map(
                    value =>
                        '"' +
                        String(
                            value ?? ""
                        )
                        .replaceAll(
                            '"',
                            '""'
                        ) +
                        '"'
                ).join(",")
        ).join("\r\n");

    const blob =
        new Blob(
            [csv],
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
        "survey-" +
        survey.id +
        ".csv";

    a.click();

    URL.revokeObjectURL(
        url
    );

    toast(
        "CSV出力を実行しました。"
    );
}

function exportPdf() {
    toast(
        "PDF出力を実行しました。印刷ダイアログからPDF保存できます。"
    );

    setTimeout(
        () => {
            window.print();
        },
        100
    );
}

/* ============================================================
 * kintone
 * ========================================================== */

function showKintone() {
    showPage(
        "page-kintone"
    );

    const k =
        state.kintone;

    document.getElementById(
        "k-subdomain"
    ).value =
        k.subdomain || "";

    document.getElementById(
        "k-appid"
    ).value =
        k.appId || "";

    document.getElementById(
        "k-login"
    ).value =
        k.loginName || "";

    document.getElementById(
        "k-password"
    ).value = "";

    document.getElementById(
        "k-ssl"
    ).value =
        String(
            !!k.sslVerify
        );

    document.getElementById(
        "k-proxy"
    ).value =
        k.proxy || "";

    renderKintoneFields();
    renderMapping();
}

async function saveKintone() {
    try {
        const data =
            await api(
                "save_kintone",
                {
                    subdomain:
                        document.getElementById(
                            "k-subdomain"
                        ).value,
                    appId:
                        document.getElementById(
                            "k-appid"
                        ).value,
                    loginName:
                        document.getElementById(
                            "k-login"
                        ).value,
                    password:
                        document.getElementById(
                            "k-password"
                        ).value,
                    sslVerify:
                        document.getElementById(
                            "k-ssl"
                        ).value ===
                        "true",
                    proxy:
                        document.getElementById(
                            "k-proxy"
                        ).value
                }
            );

        state.kintone =
            data.kintone;

        document.getElementById(
            "k-password"
        ).value = "";

        toast(
            "kintone設定を保存しました。"
        );
    } catch (e) {
        toast(
            e.message,
            "error"
        );
    }
}

async function testKintone() {
    try {
        await saveKintone();

        const data =
            await api(
                "kintone_test"
            );

        document.getElementById(
            "kintone-result"
        ).textContent =
            data.message ||
            "接続成功";

        toast(
            "kintone接続成功"
        );
    } catch (e) {
        document.getElementById(
            "kintone-result"
        ).textContent =
            e.message;

        toast(
            e.message,
            "error"
        );
    }
}

async function getKintoneFields() {
    try {
        await saveKintone();

        const data =
            await api(
                "kintone_fields"
            );

        state.kintone.fields =
            data.fields || [];

        renderKintoneFields();
        renderMapping();

        toast(
            "kintone項目一覧を取得しました。"
        );
    } catch (e) {
        document.getElementById(
            "kintone-result"
        ).textContent =
            e.message;

        toast(
            e.message,
            "error"
        );
    }
}

async function syncKintone() {
    try {
        const data =
            await api(
                "kintone_sync"
            );

        const loaded =
            await api(
                "load"
            );

        state.customers =
            loaded.customers || [];

        toast(
            data.message ||
            "顧客同期完了"
        );
    } catch (e) {
        toast(
            e.message,
            "error"
        );
    }
}

function renderKintoneFields() {
    const fields =
        state.kintone.fields || [];

    const root =
        document.getElementById(
            "kintone-fields"
        );

    if (!fields.length) {
        root.innerHTML = `
            <div class="empty">
                項目一覧がありません。
                「項目一覧を再取得」を実行してください。
            </div>
        `;
        return;
    }

    root.innerHTML = `
        <div class="kintone-fields">
            ${
                fields.map(
                    f => `
                        <div class="result-box">
                            <strong>
                                ${escapeHtml(
                                    f.label
                                )}
                            </strong>
                            <br>
                            code:
                            ${escapeHtml(
                                f.code
                            )}
                            <br>
                            type:
                            ${escapeHtml(
                                f.type
                            )}
                        </div>
                    `
                ).join("")
            }
        </div>
    `;
}

function fieldOptions(
    selected = ""
) {
    return `
        <option value="">
            未設定
        </option>
        ${
            (
                state.kintone.fields ||
                []
            ).map(
                f =>
                    `<option
                        value="${escapeAttr(
                            f.code
                        )}"
                        ${
                            f.code ===
                            selected
                            ? "selected"
                            : ""
                        }>
                        ${escapeHtml(
                            f.label
                        )}
                        (${escapeHtml(
                            f.code
                        )})
                    </option>`
            ).join("")
        }
    `;
}

function renderMapping() {
    const mapping =
        state.kintone.mapping ||
        {};

    [
        [
            "map-organization",
            mapping.organization
        ],
        [
            "map-name",
            mapping.name
        ],
        [
            "map-email",
            mapping.email
        ],
        [
            "map-department",
            mapping.department
        ],
        [
            "map-phone",
            mapping.phone
        ]
    ].forEach(
        ([id,value]) => {
            document.getElementById(
                id
            ).innerHTML =
                fieldOptions(
                    value || ""
                );
        }
    );

    const address =
        document.getElementById(
            "map-address"
        );

    const selected =
        mapping.address || [];

    address.innerHTML =
        (
            state.kintone.fields ||
            []
        ).map(
            f => `
                <label
                    style="
                        display:flex;
                        align-items:center;
                        gap:8px;
                        font-weight:normal">

                    <input
                        type="checkbox"
                        value="${escapeAttr(
                            f.code
                        )}"
                        ${
                            selected.includes(
                                f.code
                            )
                            ? "checked"
                            : ""
                        }>

                    ${escapeHtml(
                        f.label
                    )}
                    (${escapeHtml(
                        f.code
                    )})

                </label>
            `
        ).join("");
}

async function saveKintoneMapping() {
    try {
        const address =
            [
                ...document.querySelectorAll(
                    "#map-address input:checked"
                )
            ].map(
                el => el.value
            );

        const data =
            await api(
                "save_kintone_mapping",
                {
                    organization:
                        document.getElementById(
                            "map-organization"
                        ).value,
                    name:
                        document.getElementById(
                            "map-name"
                        ).value,
                    email:
                        document.getElementById(
                            "map-email"
                        ).value,
                    department:
                        document.getElementById(
                            "map-department"
                        ).value,
                    phone:
                        document.getElementById(
                            "map-phone"
                        ).value,
                    address
                }
            );

        state.kintone =
            data.kintone;

        toast(
            "マッピングを保存しました。"
        );
    } catch (e) {
        toast(
            e.message,
            "error"
        );
    }
}

/* ============================================================
 * Mail
 * ========================================================== */

function showMail() {
    showPage(
        "page-mail"
    );

    const m =
        state.mail || {};

    document.getElementById(
        "m-server"
    ).value =
        m.smtpServer || "";

    document.getElementById(
        "m-port"
    ).value =
        m.smtpPort || 587;

    document.getElementById(
        "m-encryption"
    ).value =
        m.encryption ||
        "starttls";

    document.getElementById(
        "m-auth"
    ).value =
        String(
            m.authentication !== false
        );

    document.getElementById(
        "m-user"
    ).value =
        m.username || "";

    document.getElementById(
        "m-password"
    ).value = "";

    document.getElementById(
        "m-from"
    ).value =
        m.fromEmail || "";

    document.getElementById(
        "m-from-name"
    ).value =
        m.fromName || "";

    document.getElementById(
        "m-reply"
    ).value =
        m.replyTo || "";

    document.getElementById(
        "mail-status"
    ).textContent =
        m.connectionStatus ||
        "未設定";
}

async function saveMail() {
    try {
        const data =
            await api(
                "save_mail",
                {
                    smtpServer:
                        document.getElementById(
                            "m-server"
                        ).value,
                    smtpPort:
                        document.getElementById(
                            "m-port"
                        ).value,
                    encryption:
                        document.getElementById(
                            "m-encryption"
                        ).value,
                    authentication:
                        document.getElementById(
                            "m-auth"
                        ).value ===
                        "true",
                    username:
                        document.getElementById(
                            "m-user"
                        ).value,
                    password:
                        document.getElementById(
                            "m-password"
                        ).value,
                    fromEmail:
                        document.getElementById(
                            "m-from"
                        ).value,
                    fromName:
                        document.getElementById(
                            "m-from-name"
                        ).value,
                    replyTo:
                        document.getElementById(
                            "m-reply"
                        ).value
                }
            );

        state.mail =
            data.mail;

        document.getElementById(
            "m-password"
        ).value = "";

        document.getElementById(
            "mail-status"
        ).textContent =
            state.mail.connectionStatus ||
            "未設定";

        toast(
            "メール設定を保存しました。"
        );
    } catch (e) {
        toast(
            e.message,
            "error"
        );
    }
}

async function testMail() {
    try {
        await saveMail();

        const to =
            prompt(
                "テスト送信先メールアドレス",
                state.mail.fromEmail ||
                ""
            );

        if (!to) {
            return;
        }

        const data =
            await api(
                "mail_test",
                {
                    to
                }
            );

        state.mail.connectionStatus =
            "接続確認済み";

        document.getElementById(
            "mail-status"
        ).textContent =
            "接続確認済み";

        document.getElementById(
            "mail-result"
        ).textContent =
            data.message ||
            "テストメール送信成功";

        toast(
            "テストメール送信成功"
        );
    } catch (e) {
        state.mail.connectionStatus =
            "接続できません";

        document.getElementById(
            "mail-status"
        ).textContent =
            "接続できません";

        document.getElementById(
            "mail-result"
        ).textContent =
            e.message;

        toast(
            e.message,
            "error"
        );
    }
}

/* ============================================================
 * Logout UI
 * ========================================================== */

function uiLogout() {
    confirmModal(
        "ログアウト",
        "認証セッションを持たない実装のため、画面状態を一覧へリセットします。",
        async () => {
            state.editingSurveyId = null;
            state.editingSurvey = null;
            state.analysisSurveyId = null;
            state.sendSurveyId = null;

            showList();
        }
    );
}

/* ============================================================
 * 回答者
 * ========================================================== */

async function bootAnswer() {
    document.body
        .classList.add(
            "answer-mode"
        );

    const survey =
        findSurvey(
            state.answerSurveyId
        );

    if (!survey) {
        showPage(
            "page-answer"
        );

        document.getElementById(
            "answer-content"
        ).innerHTML = `
            <div class="card">
                <h2>
                    アンケートが見つかりません。
                </h2>
            </div>
        `;

        return;
    }

    state.answerSurvey =
        survey;

    if (
        survey.status !==
        "published"
    ) {
        showPage(
            "page-answer"
        );

        document.getElementById(
            "answer-content"
        ).innerHTML = `
            <div class="card">
                <h2>
                    このアンケートは現在回答できません。
                </h2>

                <p>
                    状態:
                    ${escapeHtml(
                        statusLabel(
                            survey.status
                        )
                    )}
                </p>
            </div>
        `;

        return;
    }

    const completed =
        state.responses.some(
            r =>
                r.surveyId ===
                survey.id &&
                r.status ===
                "completed" &&
                (
                    (
                        state.answerCustomerId &&
                        r.customerId ===
                        state.answerCustomerId
                    ) ||
                    (
                        state.answerToken &&
                        r.individualToken ===
                        state.answerToken
                    )
                )
        );

    if (
        completed &&
        !survey.allowResubmission
    ) {
        state.answerAlreadyCompleted =
            true;

        showPage(
            "page-answer"
        );

        document.getElementById(
            "answer-content"
        ).innerHTML = `
            <div class="card">
                <h2>
                    回答済みです
                </h2>

                <p>
                    このアンケートはすでに回答済みです。
                </p>
            </div>
        `;

        return;
    }

    const customer =
        state.customers.find(
            c =>
                c.id ===
                state.answerCustomerId
        );

    if (customer) {
        state.answerRespondent = {
            organization:
                customer.organization ||
                "",
            name:
                customer.name ||
                "",
            email:
                customer.email ||
                "",
            department:
                customer.department ||
                "",
            phone:
                customer.phone ||
                "",
            address:
                customer.address ||
                ""
        };
    }

    showAnswer();
}

function showAnswer() {
    document.body
        .classList.add(
            "answer-mode"
        );

    showPage(
        "page-answer"
    );

    const survey =
        state.answerSurvey;

    document.getElementById(
        "answer-title"
    ).textContent =
        survey.title;

    document.getElementById(
        "answer-description"
    ).textContent =
        survey.description ||
        "";

    const root =
        document.getElementById(
            "answer-content"
        );

    root.innerHTML = `
        <div class="card">

            <h2>回答者情報</h2>

            <div class="form-grid">

                <div>
                    <label>組織名</label>
                    <input
                        value="${escapeAttr(
                            state.answerRespondent.organization
                        )}"
                        onchange="answerRespondentChanged('organization',this.value)">
                </div>

                <div>
                    <label>氏名</label>
                    <input
                        value="${escapeAttr(
                            state.answerRespondent.name
                        )}"
                        onchange="answerRespondentChanged('name',this.value)">
                </div>

                <div>
                    <label>メールアドレス</label>
                    <input
                        type="email"
                        value="${escapeAttr(
                            state.answerRespondent.email
                        )}"
                        onchange="answerRespondentChanged('email',this.value)">
                </div>

                <div>
                    <label>部署名</label>
                    <input
                        value="${escapeAttr(
                            state.answerRespondent.department
                        )}"
                        onchange="answerRespondentChanged('department',this.value)">
                </div>

                <div>
                    <label>電話番号</label>
                    <input
                        value="${escapeAttr(
                            state.answerRespondent.phone
                        )}"
                        onchange="answerRespondentChanged('phone',this.value)">
                </div>

                <div>
                    <label>住所</label>
                    <input
                        value="${escapeAttr(
                            state.answerRespondent.address
                        )}"
                        onchange="answerRespondentChanged('address',this.value)">
                </div>

            </div>

        </div>

        <div class="card">
            ${
                visibleAnswerQuestions()
                    .map(
                        q =>
                            renderAnswerQuestion(
                                q
                            )
                    ).join("")
            }
        </div>

        <div id="answer-error"></div>

        <div class="toolbar"
             style="justify-content:flex-end">

            <button
                class="btn btn-primary"
                onclick="goConfirm()">
                次へ
            </button>

        </div>
    `;
}

function answerRespondentChanged(
    field,
    value
) {
    state.answerRespondent[
        field
    ] = value;
}

function visibleAnswerQuestions() {
    const survey =
        state.answerSurvey;

    const questions =
        allQuestions(
            survey
        );

    const visible = [];

    let startIndex = 0;

    if (
        state.answerAnswers.__startQuestionId
    ) {
        const idx =
            questions.findIndex(
                q =>
                    q.id ===
                    state.answerAnswers.__startQuestionId
            );

        if (idx >= 0) {
            startIndex = idx;
        }
    }

    for (
        let i = startIndex;
        i < questions.length;
        i++
    ) {
        const q =
            questions[i];

        visible.push(q);

        if (
            q.type === "single"
        ) {
            const answer =
                state.answerAnswers[
                    q.id
                ];

            if (answer) {
                const branch =
                    (
                        q.branches ||
                        []
                    ).find(
                        b =>
                            b.choiceId ===
                            answer
                    );

                if (
                    branch &&
                    branch.nextQuestionId
                ) {
                    const next =
                        questions.findIndex(
                            x =>
                                x.id ===
                                branch.nextQuestionId
                        );

                    if (
                        next >= 0
                    ) {
                        const last =
                            visible[
                                visible.length - 1
                            ];

                        const lastIndex =
                            questions.indexOf(
                                last
                            );

                        if (
                            next >
                            lastIndex
                        ) {
                            i =
                                next - 1;
                        }
                    }
                }
            }
        }
    }

    return visible;
}

function renderAnswerQuestion(
    q
) {
    const answer =
        state.answerAnswers[
            q.id
        ];

    return `
        <div class="answer-question"
             id="answer-question-${escapeAttr(
                 q.id
             )}">

            <h3>
                ${escapeHtml(
                    q.questionNumber
                )}
                ${escapeHtml(
                    q.text
                )}

                ${
                    q.required
                    ? `
                        <span
                            style="
                                color:#dc2626">
                            *
                        </span>
                    `
                    : ""
                }
            </h3>

            ${
                q.type === "text"
                ? `
                    <textarea
                        placeholder="回答を入力してください"
                        onchange="setAnswer(
                            '${escapeAttr(
                                q.id
                            )}',
                            this.value
                        )">${escapeHtml(
                            answer || ""
                        )}</textarea>
                `
                : q.choices.map(
                    choice => {
                        const selected =
                            q.type ===
                            "multiple"
                            ? (
                                Array.isArray(
                                    answer
                                ) &&
                                answer.includes(
                                    choice.id
                                )
                            )
                            : (
                                answer ===
                                choice.id
                            );

                        return `
                            <label
                                class="answer-choice">

                                <input
                                    type="${
                                        q.type ===
                                        "single"
                                        ? "radio"
                                        : "checkbox"
                                    }"
                                    name="q-${escapeAttr(
                                        q.id
                                    )}"
                                    ${
                                        selected
                                        ? "checked"
                                        : ""
                                    }
                                    onchange="toggleAnswer(
                                        '${escapeAttr(
                                            q.id
                                        )}',
                                        '${escapeAttr(
                                            choice.id
                                        )}',
                                        this.checked,
                                        '${q.type}'
                                    )">

                                ${escapeHtml(
                                    choice.label
                                )}

                            </label>
                        `;
                    }
                ).join("")
            }

        </div>
    `;
}

function setAnswer(
    questionId,
    value
) {
    state.answerAnswers[
        questionId
    ] = value;

    showAnswer();
}

function toggleAnswer(
    questionId,
    choiceId,
    checked,
    type
) {
    if (
        type === "single"
    ) {
        state.answerAnswers[
            questionId
        ] = choiceId;
    } else {
        const values =
            Array.isArray(
                state.answerAnswers[
                    questionId
                ]
            )
            ? [
                ...state.answerAnswers[
                    questionId
                ]
            ]
            : [];

        if (checked) {
            if (
                !values.includes(
                    choiceId
                )
            ) {
                values.push(
                    choiceId
                );
            }
        } else {
            const index =
                values.indexOf(
                    choiceId
                );

            if (index >= 0) {
                values.splice(
                    index,
                    1
                );
            }
        }

        state.answerAnswers[
            questionId
        ] = values;
    }

    showAnswer();
}

function validateAnswers() {
    const visible =
        visibleAnswerQuestions();

    const errors = [];

    visible.forEach(
        q => {
            if (!q.required) {
                return;
            }

            const answer =
                state.answerAnswers[
                    q.id
                ];

            const empty =
                answer === undefined ||
                answer === null ||
                answer === "" ||
                (
                    Array.isArray(
                        answer
                    ) &&
                    answer.length === 0
                );

            if (empty) {
                errors.push(q);
            }
        }
    );

    return errors;
}

function goConfirm() {
    const errors =
        validateAnswers();

    if (errors.length) {
        document.getElementById(
            "answer-error"
        ).innerHTML = `
            <div class="result-box"
                 style="
                    border-color:#fecaca;
                    background:#fef2f2;
                    color:#991b1b">

                <strong>
                    必須回答を入力してください。
                </strong>

                <ul>
                    ${
                        errors.map(
                            q =>
                                `<li>
                                    ${escapeHtml(
                                        q.questionNumber
                                    )}
                                    ${escapeHtml(
                                        q.text
                                    )}
                                </li>`
                        ).join("")
                    }
                </ul>

            </div>
        `;

        errors[0].__scroll =
            true;

        document.getElementById(
            "answer-question-" +
            errors[0].id
        )?.scrollIntoView({
            behavior:"smooth",
            block:"center"
        });

        return;
    }

    renderConfirm();
}

function renderConfirm() {
    showPage(
        "page-confirm"
    );

    const survey =
        state.answerSurvey;

    const root =
        document.getElementById(
            "confirm-content"
        );

    root.innerHTML = `
        <div class="result-box">
            <h2>
                回答者情報
            </h2>

            <p>
                組織:
                ${escapeHtml(
                    state.answerRespondent.organization
                )}
            </p>

            <p>
                氏名:
                ${escapeHtml(
                    state.answerRespondent.name
                )}
            </p>

            <p>
                メール:
                ${escapeHtml(
                    state.answerRespondent.email
                )}
            </p>
        </div>

        ${
            visibleAnswerQuestions().map(
                q => {
                    const value =
                        state.answerAnswers[
                            q.id
                        ];

                    const labels =
                        q.choices
                            .filter(
                                c =>
                                    Array.isArray(
                                        value
                                    )
                                    ? value.includes(
                                        c.id
                                    )
                                    : value ===
                                        c.id
                            )
                            .map(
                                c =>
                                    c.label
                            );

                    const display =
                        q.type === "text"
                        ? value || ""
                        : labels.join(
                            " / "
                        );

                    return `
                        <div
                            class="result-box">

                            <div>
                                <strong>
                                    ${escapeHtml(
                                        q.questionNumber
                                    )}
                                </strong>
                            </div>

                            <div>
                                ${escapeHtml(
                                    q.text
                                )}
                            </div>

                            <div
                                style="
                                    margin-top:8px">
                                <strong>
                                    回答:
                                </strong>

                                <div>
                                    ${escapeHtml(
                                        display
                                    )}
                                </div>
                            </div>

                            <button
                                class="btn btn-small"
                                style="margin-top:8px"
                                onclick="showAnswer()">
                                修正
                            </button>

                        </div>
                    `;
                }
            ).join("")
        }
    `;
}

function answerBack() {
    showAnswer();
}

function confirmSubmit() {
    confirmModal(
        "回答送信確認",
        "回答を送信します。送信後は回答済みとして扱われます。よろしいですか？",
        async () => {
            const data =
                await api(
                    "submit_response",
                    {
                        surveyId:
                            state.answerSurveyId,
                        customerId:
                            state.answerCustomerId,
                        token:
                            state.answerToken,
                        respondent:
                            state.answerRespondent,
                        answers:
                            state.answerAnswers
                    }
                );

            if (
                data.alreadyAnswered
            ) {
                toast(
                    data.error,
                    "error"
                );
                return;
            }

            showPage(
                "page-complete"
            );
        }
    );
}

/* ============================================================
 * 初期化
 * ========================================================== */

boot();

</script>

</body>
</html>
<?php
declare(strict_types=1);

/*
 * アンケート管理システム
 * 実装対象: index.php
 * PHP 8.5 / Apache 2.4
 *
 * データ保存:
 *   data/surveys.json
 *   data/customers.json
 *   data/responses.json
 *   data/send_history.json
 *   data/kintone.json
 *   data/mail.json
 *
 * 本実装では管理者認証・回答者認証を行わない。
 */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const DATA_FILES = [
    'surveys'     => DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json',
    'customers'   => DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json',
    'responses'   => DATA_DIR . DIRECTORY_SEPARATOR . 'responses.json',
    'sendHistory' => DATA_DIR . DIRECTORY_SEPARATOR . 'send_history.json',
    'kintone'     => DATA_DIR . DIRECTORY_SEPARATOR . 'kintone.json',
    'mail'        => DATA_DIR . DIRECTORY_SEPARATOR . 'mail.json',
];

date_default_timezone_set('Asia/Tokyo');

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}

/* ------------------------------------------------------------
 * 共通関数
 * ------------------------------------------------------------ */

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowIso(): string
{
    return date('c');
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function readJson(string $key, mixed $default = []): mixed
{
    if (!isset(DATA_FILES[$key])) {
        return $default;
    }

    $file = DATA_FILES[$key];

    if (!file_exists($file)) {
        return $default;
    }

    $fp = @fopen($file, 'rb');
    if (!$fp) {
        return $default;
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            return $default;
        }

        $contents = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $data = json_decode($contents, true);

    return json_last_error() === JSON_ERROR_NONE ? $data : $default;
}

function writeJson(string $key, mixed $data): bool
{
    if (!isset(DATA_FILES[$key])) {
        return false;
    }

    $file = DATA_FILES[$key];

    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }

    $fp = @fopen($file, 'c+b');

    if (!$fp) {
        return false;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
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
            return false;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    return true;
}

function requestData(): array
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

function normalizeString(mixed $value): string
{
    return trim((string)$value);
}

function normalizeBool(mixed $value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function normalizeSubdomain(string $input): ?string
{
    $value = trim($input);

    if ($value === '') {
        return null;
    }

    $value = preg_replace('#^https?://#i', '', $value);
    $value = preg_replace('#/.*$#', '', $value);
    $value = trim($value);

    if (preg_match('/^([a-zA-Z0-9][a-zA-Z0-9-]*)\.cybozu\.com$/i', $value, $m)) {
        return strtolower($m[1]);
    }

    if (preg_match('/^([a-zA-Z0-9][a-zA-Z0-9-]*)$/', $value, $m)) {
        return strtolower($m[1]);
    }

    return null;
}

function kintoneBaseUrl(array $settings): ?string
{
    $subdomain = normalizeSubdomain((string)($settings['subdomain'] ?? ''));

    if ($subdomain === null) {
        return null;
    }

    return 'https://' . $subdomain . '.cybozu.com';
}

function normalizeProxy(string $proxy): ?string
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (!preg_match('/^[^:\s\/]+:\d{1,5}$/', $proxy)) {
        return null;
    }

    [$host, $port] = explode(':', $proxy, 2);

    $portNumber = (int)$port;

    if ($portNumber < 1 || $portNumber > 65535) {
        return null;
    }

    return $host . ':' . $portNumber;
}

function defaultKintoneSettings(): array
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

function defaultMailSettings(): array
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

/* ------------------------------------------------------------
 * 初期データ
 * ------------------------------------------------------------ */

function sampleSurvey(
    string $id,
    string $title,
    string $status,
    ?string $startDate,
    ?string $endDate,
    bool $pastEnd = false
): array {
    $groups = [];

    $g1 = [
        'id' => uuid(),
        'title' => '基本情報',
        'sortOrder' => 1,
        'questions' => [],
    ];

    $q1 = [
        'id' => uuid(),
        'groupId' => $g1['id'],
        'sortOrder' => 1,
        'questionNumber' => '',
        'text' => '今回のサービスに対する総合評価を教えてください。',
        'type' => 'single',
        'required' => true,
        'choices' => [
            ['id' => uuid(), 'label' => 'とても満足', 'sortOrder' => 1],
            ['id' => uuid(), 'label' => '満足', 'sortOrder' => 2],
            ['id' => uuid(), 'label' => '普通', 'sortOrder' => 3],
            ['id' => uuid(), 'label' => 'やや不満', 'sortOrder' => 4],
            ['id' => uuid(), 'label' => '不満', 'sortOrder' => 5],
        ],
        'branches' => [],
    ];

    $q2 = [
        'id' => uuid(),
        'groupId' => $g1['id'],
        'sortOrder' => 2,
        'questionNumber' => '',
        'text' => 'ご意見・ご要望があれば入力してください。',
        'type' => 'text',
        'required' => false,
        'choices' => [],
        'branches' => [],
    ];

    $g1['questions'] = [$q1, $q2];

    $g2 = [
        'id' => uuid(),
        'title' => '追加確認',
        'sortOrder' => 2,
        'questions' => [],
    ];

    $q3 = [
        'id' => uuid(),
        'groupId' => $g2['id'],
        'sortOrder' => 1,
        'questionNumber' => '',
        'text' => '今後も利用したいと思いますか？',
        'type' => 'single',
        'required' => true,
        'choices' => [
            ['id' => uuid(), 'label' => 'はい', 'sortOrder' => 1],
            ['id' => uuid(), 'label' => 'いいえ', 'sortOrder' => 2],
        ],
        'branches' => [],
    ];

    $g2['questions'] = [$q3];

    $sur = [
        'id' => $id,
        'title' => $title,
        'description' => '動作確認用のサンプルアンケートです。',
        'startDate' => $startDate,
        'endDate' => $endDate,
        'questionNumberMode' => 'all',
        'status' => $status,
        'allowResubmission' => false,
        'groups' => [$g1, $g2],
        'createdAt' => nowIso(),
        'updatedAt' => nowIso(),
    ];

    if ($pastEnd) {
        $sur['endDate'] = date('c', strtotime('-1 day'));
    }

    recalculateQuestionNumbers($sur);

    return $sur;
}

function initializeData(): void
{
    $needsSurveys = !file_exists(DATA_FILES['surveys']);
    $needsCustomers = !file_exists(DATA_FILES['customers']);
    $needsResponses = !file_exists(DATA_FILES['responses']);
    $needsHistory = !file_exists(DATA_FILES['sendHistory']);
    $needsKintone = !file_exists(DATA_FILES['kintone']);
    $needsMail = !file_exists(DATA_FILES['mail']);

    if ($needsSurveys) {
        $surveys = [];

        $surveys[] = sampleSurvey(
            'survey-demo-draft',
            'サンプルアンケート（下書き）',
            'draft',
            date('c'),
            date('c', strtotime('+30 days'))
        );

        $surveys[] = sampleSurvey(
            'survey-demo-published',
            'サンプルアンケート（公開中）',
            'published',
            date('c', strtotime('-2 days')),
            date('c', strtotime('+30 days'))
        );

        $surveys[] = sampleSurvey(
            'survey-demo-stopped',
            'サンプルアンケート（停止）',
            'stopped',
            date('c', strtotime('-5 days')),
            date('c', strtotime('+30 days'))
        );

        $surveys[] = sampleSurvey(
            'survey-demo-finished',
            'サンプルアンケート（終了）',
            'finished',
            date('c', strtotime('-30 days')),
            date('c', strtotime('-1 day'))
        );

        $surveys[] = sampleSurvey(
            'survey-demo-expired-draft',
            '期限経過サンプル（下書き）',
            'draft',
            date('c', strtotime('-5 days')),
            date('c', strtotime('-1 day')),
            true
        );

        $surveys[] = sampleSurvey(
            'survey-demo-expired-stopped',
            '期限経過サンプル（停止）',
            'stopped',
            date('c', strtotime('-5 days')),
            date('c', strtotime('-1 day')),
            true
        );

        $surveys[] = sampleSurvey(
            'survey-demo-expired-published',
            '期限経過サンプル（公開中→終了）',
            'published',
            date('c', strtotime('-5 days')),
            date('c', strtotime('-1 day')),
            true
        );

        writeJson('surveys', $surveys);
    }

    if ($needsCustomers) {
        $customers = [
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
        ];

        writeJson('customers', $customers);
    }

    if ($needsResponses) {
        writeJson('responses', [
            [
                'id' => 'response-demo-001',
                'surveyId' => 'survey-demo-published',
                'individualToken' => 'demo-token-001',
                'customerId' => 'customer-003',
                'respondent' => [
                    'organization' => '合同会社デモ',
                    'name' => '鈴木 一郎',
                    'email' => 'demo@example.com',
                    'department' => '管理部',
                    'phone' => '03-0000-0003',
                    'address' => '東京都新宿区西新宿2-2-2',
                ],
                'answers' => [],
                'status' => 'completed',
                'submittedAt' => date('c', strtotime('-2 days')),
                'updatedAt' => date('c', strtotime('-2 days')),
            ],
        ]);
    }

    if ($needsHistory) {
        writeJson('sendHistory', []);
    }

    if ($needsKintone) {
        writeJson('kintone', defaultKintoneSettings());
    }

    if ($needsMail) {
        writeJson('mail', defaultMailSettings());
    }
}

initializeData();

/* ------------------------------------------------------------
 * 状態・質問番号
 * ------------------------------------------------------------ */

function recalculateQuestionNumbers(array &$survey): void
{
    $mode = ($survey['questionNumberMode'] ?? 'all') === 'group'
        ? 'group'
        : 'all';

    $global = 0;
    $groupIndex = 0;

    foreach ($survey['groups'] as &$group) {
        $groupIndex++;
        $questionIndex = 0;

        foreach ($group['questions'] as &$question) {
            $questionIndex++;
            $global++;

            if ($mode === 'group') {
                $question['questionNumber'] = 'Q' . $groupIndex . '-' . $questionIndex;
            } else {
                $question['questionNumber'] = 'Q' . $global;
            }

            $question['groupId'] = $group['id'];
            $question['sortOrder'] = $questionIndex;
        }

        unset($question);
        $group['sortOrder'] = $groupIndex;
    }

    unset($group);
}

function applyAutomaticFinish(array &$surveys): bool
{
    $changed = false;
    $now = time();

    foreach ($surveys as &$survey) {
        if (($survey['status'] ?? '') !== 'published') {
            continue;
        }

        $endDate = $survey['endDate'] ?? null;

        if (!$endDate) {
            continue;
        }

        $endTime = strtotime((string)$endDate);

        if ($endTime !== false && $now > $endTime) {
            $survey['status'] = 'finished';
            $survey['updatedAt'] = nowIso();
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function saveSurveys(array $surveys): void
{
    writeJson('surveys', $surveys);
}

function findSurvey(array $surveys, string $id): ?array
{
    foreach ($surveys as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function findSurveyIndex(array $surveys, string $id): int
{
    foreach ($surveys as $index => $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function findQuestion(array $survey, string $questionId): ?array
{
    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            if (($question['id'] ?? '') === $questionId) {
                return $question;
            }
        }
    }

    return null;
}

function findChoice(array $question, string $choiceId): ?array
{
    foreach ($question['choices'] ?? [] as $choice) {
        if (($choice['id'] ?? '') === $choiceId) {
            return $choice;
        }
    }

    return null;
}

function sanitizeSurveyPayload(array $input, ?array $existing = null): array
{
    $survey = $existing ?? [
        'id' => uuid(),
        'status' => 'draft',
        'createdAt' => nowIso(),
        'groups' => [],
    ];

    $survey['title'] = normalizeString($input['title'] ?? '');
    $survey['description'] = normalizeString($input['description'] ?? '');
    $survey['startDate'] = normalizeString($input['startDate'] ?? '') ?: null;
    $survey['endDate'] = normalizeString($input['endDate'] ?? '') ?: null;

    $mode = (string)($input['questionNumberMode'] ?? 'all');
    $survey['questionNumberMode'] = in_array($mode, ['all', 'group'], true)
        ? $mode
        : 'all';

    $survey['allowResubmission'] = normalizeBool(
        $input['allowResubmission'] ?? false
    );

    $survey['groups'] = [];

    foreach (($input['groups'] ?? []) as $gIndex => $groupInput) {
        if (!is_array($groupInput)) {
            continue;
        }

        $groupId = normalizeString($groupInput['id'] ?? '') ?: uuid();

        $group = [
            'id' => $groupId,
            'title' => normalizeString($groupInput['title'] ?? ''),
            'sortOrder' => $gIndex + 1,
            'questions' => [],
        ];

        foreach (($groupInput['questions'] ?? []) as $qIndex => $questionInput) {
            if (!is_array($questionInput)) {
                continue;
            }

            $questionId = normalizeString($questionInput['id'] ?? '') ?: uuid();

            $type = (string)($questionInput['type'] ?? 'single');

            if (!in_array($type, ['single', 'multiple', 'text'], true)) {
                $type = 'single';
            }

            $question = [
                'id' => $questionId,
                'groupId' => $groupId,
                'sortOrder' => $qIndex + 1,
                'questionNumber' => '',
                'text' => normalizeString($questionInput['text'] ?? ''),
                'type' => $type,
                'required' => normalizeBool($questionInput['required'] ?? false),
                'choices' => [],
                'branches' => [],
            ];

            if ($type !== 'text') {
                foreach (($questionInput['choices'] ?? []) as $cIndex => $choiceInput) {
                    if (!is_array($choiceInput)) {
                        continue;
                    }

                    $choiceId = normalizeString($choiceInput['id'] ?? '') ?: uuid();

                    $question['choices'][] = [
                        'id' => $choiceId,
                        'label' => normalizeString($choiceInput['label'] ?? ''),
                        'sortOrder' => $cIndex + 1,
                    ];
                }
            }

            if ($type === 'single') {
                foreach (($questionInput['branches'] ?? []) as $branch) {
                    if (!is_array($branch)) {
                        continue;
                    }

                    $choiceId = normalizeString($branch['choiceId'] ?? '');
                    $nextQuestionId = normalizeString($branch['nextQuestionId'] ?? '');

                    if ($choiceId !== '') {
                        $question['branches'][] = [
                            'choiceId' => $choiceId,
                            'nextQuestionId' => $nextQuestionId,
                        ];
                    }
                }
            }

            $group['questions'][] = $question;
        }

        $survey['groups'][] = $group;
    }

    recalculateQuestionNumbers($survey);

    $survey['updatedAt'] = nowIso();

    return $survey;
}

/* ------------------------------------------------------------
 * kintone
 * ------------------------------------------------------------ */

function kintoneHeaders(array $settings): array
{
    $login = (string)($settings['loginName'] ?? '');
    $password = (string)($settings['password'] ?? '');

    $authorization = base64_encode($login . ':' . $password);

    return [
        'X-Cybozu-Authorization: ' . $authorization,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
}

function kintoneRequest(
    array $settings,
    string $method,
    string $path,
    ?array $body = null
): array {
    $base = kintoneBaseUrl($settings);

    if ($base === null) {
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

    $url = $base . $path;

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'success' => false,
            'error' => 'cURLを初期化できませんでした。',
        ];
    }

    $verify = (bool)($settings['sslVerify'] ?? false);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => kintoneHeaders($settings),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    $proxy = normalizeProxy((string)($settings['proxy'] ?? ''));

    if ((string)($settings['proxy'] ?? '') !== '' && $proxy === null) {
        curl_close($ch);

        return [
            'success' => false,
            'error' => 'プロキシはhost:port形式で指定してください。',
        ];
    }

    if ($proxy !== null) {
        $options[CURLOPT_PROXY] = $proxy;
        $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false || $errno !== 0) {
        return [
            'success' => false,
            'httpCode' => $httpCode,
            'error' => $error ?: 'kintone API通信に失敗しました。',
        ];
    }

    $decoded = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = 'kintone APIがエラーを返しました。';

        if (is_array($decoded)) {
            $message = (string)($decoded['message'] ?? $message);
        }

        return [
            'success' => false,
            'httpCode' => $httpCode,
            'error' => $message,
            'response' => $decoded,
        ];
    }

    return [
        'success' => true,
        'httpCode' => $httpCode,
        'data' => is_array($decoded) ? $decoded : [],
    ];
}

function kintoneTestConnection(array $settings): array
{
    $result = kintoneRequest(
        $settings,
        'GET',
        '/k/v1/app.json?app=' . rawurlencode((string)$settings['appId'])
    );

    if (!$result['success']) {
        return [
            'success' => false,
            'message' => '接続失敗',
            'detail' => $result['error'] ?? '不明なエラー',
        ];
    }

    return [
        'success' => true,
        'message' => '接続成功',
        'detail' => 'kintone APIへの接続を確認しました。',
    ];
}

function kintoneGetFields(array $settings): array
{
    $result = kintoneRequest(
        $settings,
        'GET',
        '/k/v1/app/form/fields.json?app=' . rawurlencode((string)$settings['appId'])
    );

    if (!$result['success']) {
        return $result;
    }

    $properties = $result['data']['properties'] ?? [];
    $fields = [];

    foreach ($properties as $code => $field) {
        $fields[] = [
            'code' => $code,
            'label' => (string)($field['label'] ?? $code),
            'type' => (string)($field['type'] ?? ''),
        ];
    }

    $settings['fields'] = $fields;
    $settings['updatedAt'] = nowIso();

    return [
        'success' => true,
        'fields' => $fields,
    ];
}

function kintoneSyncCustomers(array $settings, array $mapping): array
{
    $appId = trim((string)($settings['appId'] ?? ''));

    if ($appId === '') {
        return [
            'success' => false,
            'error' => '顧客管理アプリIDが設定されていません。',
        ];
    }

    $query = 'limit 500';

    $result = kintoneRequest(
        $settings,
        'GET',
        '/k/v1/records.json?app=' .
        rawurlencode($appId) .
        '&query=' .
        rawurlencode($query)
    );

    if (!$result['success']) {
        return $result;
    }

    $records = $result['data']['records'] ?? [];

    $customers = readJson('customers', []);

    if (!is_array($customers)) {
        $customers = [];
    }

    $byEmail = [];

    foreach ($customers as $index => $customer) {
        $email = strtolower(trim((string)($customer['email'] ?? '')));
        if ($email !== '') {
            $byEmail[$email] = $index;
        }
    }

    $synced = 0;

    foreach ($records as $record) {
        $getField = static function (string $code) use ($record): string {
            if ($code === '') {
                return '';
            }

            $value = $record[$code]['value'] ?? '';

            if (is_array($value)) {
                if (isset($value['name'])) {
                    return (string)$value['name'];
                }

                return implode(', ', array_map('strval', $value));
            }

            return (string)$value;
        };

        $addressParts = [];

        foreach (($mapping['address'] ?? []) as $addressCode) {
            $part = $getField((string)$addressCode);
            if ($part !== '') {
                $addressParts[] = $part;
            }
        }

        $customer = [
            'id' => 'kintone-' . (string)($record['$id']['value'] ?? uuid()),
            'organization' => $getField((string)($mapping['organization'] ?? '')),
            'name' => $getField((string)($mapping['name'] ?? '')),
            'email' => $getField((string)($mapping['email'] ?? '')),
            'department' => $getField((string)($mapping['department'] ?? '')),
            'phone' => $getField((string)($mapping['phone'] ?? '')),
            'address' => implode(' ', $addressParts),
            'status' => '未送信',
            'lastSentAt' => null,
            'sendCount' => 0,
            'kintoneStatus' => '登録済み',
            'kintoneRecordId' => (string)($record['$id']['value'] ?? ''),
            'updatedAt' => nowIso(),
        ];

        $email = strtolower(trim($customer['email']));

        if ($email !== '' && isset($byEmail[$email])) {
            $index = $byEmail[$email];

            $old = $customers[$index];

            $customer['status'] = $old['status'] ?? '未送信';
            $customer['lastSentAt'] = $old['lastSentAt'] ?? null;
            $customer['sendCount'] = (int)($old['sendCount'] ?? 0);

            $customers[$index] = $customer;
        } else {
            $customers[] = $customer;

            if ($email !== '') {
                $byEmail[$email] = count($customers) - 1;
            }
        }

        $synced++;
    }

    writeJson('customers', $customers);

    return [
        'success' => true,
        'message' => '顧客同期完了',
        'count' => $synced,
    ];
}

/* ------------------------------------------------------------
 * SMTP
 * ------------------------------------------------------------ */

function smtpRead($socket): array
{
    $lines = [];

    while (!feof($socket)) {
        $line = fgets($socket, 4096);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");

        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }

    $last = end($lines);

    return [
        'code' => $last !== false ? (int)substr($last, 0, 3) : 0,
        'text' => implode("\n", $lines),
    ];
}

function smtpCommand($socket, string $command, array $acceptedCodes): array
{
    fwrite($socket, $command . "\r\n");

    $response = smtpRead($socket);

    if (!in_array($response['code'], $acceptedCodes, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' . $response['text']
        );
    }

    return $response;
}

function smtpSendMail(
    array $settings,
    string $to,
    string $subject,
    string $body
): array {
    $server = trim((string)($settings['smtpServer'] ?? ''));
    $port = (int)($settings['smtpPort'] ?? 0);
    $encryption = (string)($settings['encryption'] ?? 'none');
    $auth = (bool)($settings['authentication'] ?? false);
    $username = (string)($settings['username'] ?? '');
    $password = (string)($settings['password'] ?? '');
    $fromEmail = trim((string)($settings['fromEmail'] ?? ''));
    $fromName = trim((string)($settings['fromName'] ?? ''));
    $replyTo = trim((string)($settings['replyTo'] ?? ''));

    if ($server === '' || $port < 1 || $port > 65535) {
        return [
            'success' => false,
            'error' => 'SMTPサーバまたはSMTPポートが設定されていません。',
        ];
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'error' => '宛先メールアドレスが不正です。',
        ];
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'error' => '送信元メールアドレスが不正です。',
        ];
    }

    $transportHost = $server;

    if ($encryption === 'ssl') {
        $transportHost = 'ssl://' . $server;
    }

    $errno = 0;
    $errstr = '';

    $socket = @fsockopen(
        $transportHost,
        $port,
        $errno,
        $errstr,
        15
    );

    if (!$socket) {
        return [
            'success' => false,
            'error' => 'SMTPサーバへ接続できません: ' . $errstr,
        ];
    }

    stream_set_timeout($socket, 30);

    try {
        $greeting = smtpRead($socket);

        if (!in_array($greeting['code'], [220], true)) {
            throw new RuntimeException(
                'SMTPサーバの応答が不正です: ' . $greeting['text']
            );
        }

        $hostname = gethostname() ?: 'localhost';

        smtpCommand($socket, 'EHLO ' . $hostname, [250]);

        if ($encryption === 'starttls') {
            smtpCommand($socket, 'STARTTLS', [220]);

            $crypto = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException('STARTTLSを確立できませんでした。');
            }

            smtpCommand($socket, 'EHLO ' . $hostname, [250]);
        }

        if ($auth) {
            if ($username === '' || $password === '') {
                throw new RuntimeException(
                    'SMTP認証を使用する設定ですが、ユーザー名またはパスワードが未設定です。'
                );
            }

            smtpCommand($socket, 'AUTH LOGIN', [334]);

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

        smtpCommand(
            $socket,
            'MAIL FROM:<' . $fromEmail . '>',
            [250]
        );

        smtpCommand(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtpCommand($socket, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $encodedFromName = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?='
            : '';

        $headers = [];

        $headers[] = 'From: ' .
            ($encodedFromName !== ''
                ? $encodedFromName . ' '
                : '') .
            '<' . $fromEmail . '>';

        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . $encodedSubject;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }

        $safeBody = str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

        $safeBody = preg_replace(
            '/^\./m',
            '..',
            $safeBody
        );

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            str_replace("\n", "\r\n", $safeBody) .
            "\r\n.";

        fwrite($socket, $message . "\r\n");

        $response = smtpRead($socket);

        if ($response['code'] !== 250) {
            throw new RuntimeException(
                'メール送信に失敗しました: ' . $response['text']
            );
        }

        @fwrite($socket, "QUIT\r\n");

        return [
            'success' => true,
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    } finally {
        fclose($socket);
    }
}

function smtpTest(array $settings, string $to): array
{
    $to = trim($to);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'error' => 'テスト送信先メールアドレスを入力してください。',
        ];
    }

    return smtpSendMail(
        $settings,
        $to,
        'アンケート管理システム SMTPテスト',
        "SMTP通信のテストメールです。\n\n"
        . "このメールが届けばSMTP設定は正常に動作しています。"
    );
}

/* ------------------------------------------------------------
 * 送信・回答関連
 * ------------------------------------------------------------ */

function surveyPublicUrl(string $surveyId, ?string $token = null): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $base = $scheme . '://' . $host .
        strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?');

    $base = preg_replace('/\?.*$/', '', $base);

    $query = '?view=answer&survey=' . rawurlencode($surveyId);

    if ($token !== null && $token !== '') {
        $query .= '&token=' . rawurlencode($token);
    }

    return $base . $query;
}

function renderMailTemplate(
    string $template,
    array $customer,
    string $surveyUrl
): string {
    return str_replace(
        ['{顧客名}', '{アンケートURL}'],
        [
            (string)($customer['name'] ?? ''),
            $surveyUrl,
        ],
        $template
    );
}

function customerResponseStatus(
    string $surveyId,
    array $customer,
    array $responses
): string {
    foreach ($responses as $response) {
        if (
            ($response['surveyId'] ?? '') === $surveyId &&
            ($response['customerId'] ?? '') === ($customer['id'] ?? '')
        ) {
            if (($response['status'] ?? '') === 'completed') {
                return '回答済み';
            }
        }
    }

    $status = (string)($customer['status'] ?? '未送信');

    return $status !== '' ? $status : '未送信';
}

function getResponseForToken(
    string $surveyId,
    string $token,
    array $responses
): ?array {
    foreach ($responses as $response) {
        if (
            ($response['surveyId'] ?? '') === $surveyId &&
            ($response['individualToken'] ?? '') === $token
        ) {
            return $response;
        }
    }

    return null;
}

function visibleQuestionIds(array $survey, array $answers): array
{
    $all = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $all[] = $question['id'];
        }
    }

    $visible = [];

    if (!$all) {
        return [];
    }

    $currentIndex = 0;

    while ($currentIndex < count($all)) {
        $questionId = $all[$currentIndex];
        $question = findQuestion($survey, $questionId);

        if (!$question) {
            $currentIndex++;
            continue;
        }

        $visible[] = $questionId;

        if (($question['type'] ?? '') === 'single') {
            $answer = $answers[$questionId] ?? '';

            if ($answer !== '') {
                $next = null;

                foreach ($question['branches'] ?? [] as $branch) {
                    if (($branch['choiceId'] ?? '') === (string)$answer) {
                        $next = (string)($branch['nextQuestionId'] ?? '');
                        break;
                    }
                }

                if ($next !== '') {
                    $targetIndex = array_search($next, $all, true);

                    if ($targetIndex !== false) {
                        $currentIndex = (int)$targetIndex;
                        continue;
                    }
                }
            }
        }

        $currentIndex++;
    }

    return array_values(array_unique($visible));
}

function validateAnswers(array $survey, array $answers): array
{
    $errors = [];
    $visibleIds = visibleQuestionIds($survey, $answers);

    foreach ($visibleIds as $questionId) {
        $question = findQuestion($survey, $questionId);

        if (!$question) {
            continue;
        }

        if (!($question['required'] ?? false)) {
            continue;
        }

        $answer = $answers[$questionId] ?? null;

        $empty = false;

        if ($question['type'] === 'multiple') {
            $empty = !is_array($answer) || count($answer) === 0;
        } else {
            $empty = trim((string)$answer) === '';
        }

        if ($empty) {
            $errors[$questionId] =
                ($question['questionNumber'] ?? '') .
                '「' .
                ($question['text'] ?? '') .
                '」は必須回答です。';
        }
    }

    return $errors;
}

/* ------------------------------------------------------------
 * API
 * ------------------------------------------------------------ */

if (isset($_GET['api']) || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_GET['api'] ?? '');

    $input = requestData();

    if ($action === '' && isset($input['action'])) {
        $action = (string)$input['action'];
    }

    /* surveys */
    if ($action === 'get_data') {
        $surveys = readJson('surveys', []);
        $customers = readJson('customers', []);
        $responses = readJson('responses', []);
        $history = readJson('sendHistory', []);
        $kintone = readJson('kintone', defaultKintoneSettings());
        $mail = readJson('mail', defaultMailSettings());

        if (applyAutomaticFinish($surveys)) {
            saveSurveys($surveys);
        }

        $safeKintone = $kintone;

        if (isset($safeKintone['password'])) {
            $safeKintone['password'] = '';
            $safeKintone['passwordConfigured'] =
                (string)($kintone['password'] ?? '') !== '';
        }

        $safeMail = $mail;

        if (isset($safeMail['password'])) {
            $safeMail['password'] = '';
            $safeMail['passwordConfigured'] =
                (string)($mail['password'] ?? '') !== '';
        }

        jsonResponse([
            'success' => true,
            'surveys' => $surveys,
            'customers' => $customers,
            'responses' => $responses,
            'sendHistory' => $history,
            'kintone' => $safeKintone,
            'mail' => $safeMail,
        ]);
    }

    if ($action === 'save_survey') {
        $surveys = readJson('surveys', []);
        $id = normalizeString($input['id'] ?? '');

        $index = $id !== '' ? findSurveyIndex($surveys, $id) : -1;

        if ($index >= 0) {
            $existing = $surveys[$index];

            $survey = sanitizeSurveyPayload($input, $existing);

            /* 編集保存では現在状態を維持 */
            $survey['status'] = $existing['status'] ?? 'draft';
            $survey['createdAt'] = $existing['createdAt'] ?? nowIso();

            $surveys[$index] = $survey;
        } else {
            $survey = sanitizeSurveyPayload($input, null);
            $survey['id'] = uuid();
            $survey['status'] = 'draft';
            $survey['createdAt'] = nowIso();
            $survey['updatedAt'] = nowIso();

            $surveys[] = $survey;
        }

        if (!writeJson('surveys', $surveys)) {
            jsonResponse([
                'success' => false,
                'error' => 'アンケートを保存できませんでした。',
            ], 500);
        }

        jsonResponse([
            'success' => true,
            'survey' => $survey,
            'surveys' => $surveys,
        ]);
    }

    if ($action === 'change_status') {
        $surveys = readJson('surveys', []);
        $id = normalizeString($input['id'] ?? '');
        $newStatus = normalizeString($input['status'] ?? '');

        $index = findSurveyIndex($surveys, $id);

        if ($index < 0) {
            jsonResponse([
                'success' => false,
                'error' => '対象アンケートが存在しません。',
            ], 404);
        }

        $oldStatus = (string)($surveys[$index]['status'] ?? 'draft');

        $allowed = [
            'draft' => ['published'],
            'published' => ['stopped'],
            'stopped' => ['published'],
            'finished' => [],
        ];

        if (!in_array(
            $newStatus,
            $allowed[$oldStatus] ?? [],
            true
        )) {
            jsonResponse([
                'success' => false,
                'error' => '許可されていない状態変更です。',
            ], 400);
        }

        $surveys[$index]['status'] = $newStatus;
        $surveys[$index]['updatedAt'] = nowIso();

        saveSurveys($surveys);

        jsonResponse([
            'success' => true,
            'survey' => $surveys[$index],
        ]);
    }

    if ($action === 'delete_survey') {
        $surveys = readJson('surveys', []);
        $id = normalizeString($input['id'] ?? '');

        $index = findSurveyIndex($surveys, $id);

        if ($index < 0) {
            jsonResponse([
                'success' => false,
                'error' => '対象アンケートが存在しません。',
            ], 404);
        }

        array_splice($surveys, $index, 1);
        saveSurveys($surveys);

        /* 関連回答も削除 */
        $responses = readJson('responses', []);

        $responses = array_values(array_filter(
            $responses,
            static fn($r) => ($r['surveyId'] ?? '') !== $id
        ));

        writeJson('responses', $responses);

        jsonResponse([
            'success' => true,
            'surveys' => $surveys,
        ]);
    }

    if ($action === 'duplicate_survey') {
        $surveys = readJson('surveys', []);
        $id = normalizeString($input['id'] ?? '');

        $survey = findSurvey($surveys, $id);

        if (!$survey) {
            jsonResponse([
                'success' => false,
                'error' => '複製対象アンケートが存在しません。',
            ], 404);
        }

        $new = $survey;
        $new['id'] = uuid();
        $new['title'] = ($survey['title'] ?? '') . '（複製）';
        $new['status'] = 'draft';
        $new['createdAt'] = nowIso();
        $new['updatedAt'] = nowIso();

        foreach ($new['groups'] as &$group) {
            $group['id'] = uuid();

            foreach ($group['questions'] as &$question) {
                $question['id'] = uuid();
                $question['groupId'] = $group['id'];

                foreach ($question['choices'] as &$choice) {
                    $choice['id'] = uuid();
                }

                foreach ($question['branches'] as &$branch) {
                    /* 後で旧ID→新IDへ変換 */
                }
            }
        }

        unset($group);

        /* 質問IDを旧→新へ対応させるため再構築 */
        $originalQuestions = [];

        foreach ($survey['groups'] as $oldGroup) {
            foreach ($oldGroup['questions'] as $oldQuestion) {
                $originalQuestions[$oldQuestion['id']] = true;
            }
        }

        /*
         * ID変更に伴う分岐の整合性を保つ。
         * 質問と選択肢を旧ID順に再マッピングする。
         */
        $oldQuestionIds = [];
        $newQuestionIds = [];

        $oldChoiceIds = [];
        $newChoiceIds = [];

        foreach ($survey['groups'] as $gi => $oldGroup) {
            foreach ($oldGroup['questions'] as $qi => $oldQuestion) {
                $oldQuestionIds[] = $oldQuestion['id'];
                $newQuestionIds[] = $new['groups'][$gi]['questions'][$qi]['id'];

                foreach ($oldQuestion['choices'] ?? [] as $ci => $oldChoice) {
                    $oldChoiceIds[] = $oldChoice['id'];
                    $newChoiceIds[] =
                        $new['groups'][$gi]['questions'][$qi]['choices'][$ci]['id'];
                }
            }
        }

        $questionMap = array_combine($oldQuestionIds, $newQuestionIds) ?: [];
        $choiceMap = array_combine($oldChoiceIds, $newChoiceIds) ?: [];

        foreach ($new['groups'] as &$group) {
            foreach ($group['questions'] as &$question) {
                foreach ($question['branches'] as &$branch) {
                    if (isset($choiceMap[$branch['choiceId']])) {
                        $branch['choiceId'] =
                            $choiceMap[$branch['choiceId']];
                    }

                    if (
                        $branch['nextQuestionId'] !== '' &&
                        isset($questionMap[$branch['nextQuestionId']])
                    ) {
                        $branch['nextQuestionId'] =
                            $questionMap[$branch['nextQuestionId']];
                    }
                }
            }
        }

        unset($group, $question, $branch);

        recalculateQuestionNumbers($new);

        $surveys[] = $new;
        saveSurveys($surveys);

        jsonResponse([
            'success' => true,
            'survey' => $new,
            'surveys' => $surveys,
        ]);
    }

    /* responses */
    if ($action === 'submit_response') {
        $surveys = readJson('surveys', []);

        if (applyAutomaticFinish($surveys)) {
            saveSurveys($surveys);
        }

        $surveyId = normalizeString($input['surveyId'] ?? '');
        $token = normalizeString($input['token'] ?? '');

        $survey = findSurvey($surveys, $surveyId);

        if (!$survey) {
            jsonResponse([
                'success' => false,
                'error' => 'アンケートが存在しません。',
            ], 404);
        }

        if (($survey['status'] ?? '') !== 'published') {
            jsonResponse([
                'success' => false,
                'error' => 'このアンケートは現在回答できません。',
            ], 400);
        }

        if ($token === '') {
            $token = uuid();
        }

        $answers = $input['answers'] ?? [];

        if (!is_array($answers)) {
            $answers = [];
        }

        $errors = validateAnswers($survey, $answers);

        if ($errors) {
            jsonResponse([
                'success' => false,
                'error' => '必須回答を確認してください。',
                'validationErrors' => $errors,
            ], 400);
        }

        $responses = readJson('responses', []);

        $existingIndex = -1;

        foreach ($responses as $index => $response) {
            if (
                ($response['surveyId'] ?? '') === $surveyId &&
                ($response['individualToken'] ?? '') === $token
            ) {
                $existingIndex = $index;
                break;
            }
        }

        if (
            $existingIndex >= 0 &&
            !($survey['allowResubmission'] ?? false) &&
            ($responses[$existingIndex]['status'] ?? '') === 'completed'
        ) {
            jsonResponse([
                'success' => false,
                'error' => 'このアンケートは回答済みです。',
            ], 409);
        }

        $customers = readJson('customers', []);

        $respondent = $input['respondent'] ?? [];

        if (!is_array($respondent)) {
            $respondent = [];
        }

        $email = strtolower(trim((string)($respondent['email'] ?? '')));
        $customerId = normalizeString($input['customerId'] ?? '');

        if ($customerId === '' && $email !== '') {
            foreach ($customers as $customer) {
                if (
                    strtolower((string)($customer['email'] ?? '')) ===
                    $email
                ) {
                    $customerId = (string)$customer['id'];
                    break;
                }
            }
        }

        $response = [
            'id' => $existingIndex >= 0
                ? $responses[$existingIndex]['id']
                : uuid(),
            'surveyId' => $surveyId,
            'individualToken' => $token,
            'customerId' => $customerId !== '' ? $customerId : null,
            'respondent' => [
                'organization' => normalizeString(
                    $respondent['organization'] ?? ''
                ),
                'name' => normalizeString(
                    $respondent['name'] ?? ''
                ),
                'email' => normalizeString(
                    $respondent['email'] ?? ''
                ),
                'department' => normalizeString(
                    $respondent['department'] ?? ''
                ),
                'phone' => normalizeString(
                    $respondent['phone'] ?? ''
                ),
                'address' => normalizeString(
                    $respondent['address'] ?? ''
                ),
            ],
            'answers' => $answers,
            'status' => 'completed',
            'submittedAt' => nowIso(),
            'updatedAt' => nowIso(),
        ];

        if ($existingIndex >= 0) {
            $responses[$existingIndex] = $response;
        } else {
            $responses[] = $response;
        }

        writeJson('responses', $responses);

        if ($customerId !== '') {
            foreach ($customers as &$customer) {
                if (($customer['id'] ?? '') === $customerId) {
                    $customer['status'] = '回答済み';
                    $customer['updatedAt'] = nowIso();
                    break;
                }
            }

            unset($customer);

            writeJson('customers', $customers);
        }

        jsonResponse([
            'success' => true,
            'responseId' => $response['id'],
        ]);
    }

    if ($action === 'check_response') {
        $surveyId = normalizeString($input['surveyId'] ?? '');
        $token = normalizeString($input['token'] ?? '');

        $surveys = readJson('surveys', []);

        if (applyAutomaticFinish($surveys)) {
            saveSurveys($surveys);
        }

        $survey = findSurvey($surveys, $surveyId);

        if (!$survey) {
            jsonResponse([
                'success' => false,
                'error' => 'アンケートが存在しません。',
            ], 404);
        }

        $responses = readJson('responses', []);

        $response = getResponseForToken(
            $surveyId,
            $token,
            $responses
        );

        jsonResponse([
            'success' => true,
            'survey' => $survey,
            'answered' => $response !== null &&
                ($response['status'] ?? '') === 'completed',
            'response' => $response,
        ]);
    }

    /* send */
    if ($action === 'send_mail') {
        $surveyId = normalizeString($input['surveyId'] ?? '');

        if ($surveyId === '') {
            jsonResponse([
                'success' => false,
                'error' => '対象アンケートが指定されていません。',
            ], 400);
        }

        $surveys = readJson('surveys', []);

        if (applyAutomaticFinish($surveys)) {
            saveSurveys($surveys);
        }

        $survey = findSurvey($surveys, $surveyId);

        if (!$survey) {
            jsonResponse([
                'success' => false,
                'error' => '対象アンケートが存在しません。',
            ], 404);
        }

        $customerIds = $input['customerIds'] ?? [];

        if (!is_array($customerIds)) {
            $customerIds = [];
        }

        $customers = readJson('customers', []);
        $responses = readJson('responses', []);
        $mail = readJson('mail', defaultMailSettings());

        $subjectTemplate =
            normalizeString($input['subject'] ?? '');

        $bodyTemplate =
            (string)($input['body'] ?? '');

        $type = normalizeString($input['sendType'] ?? '一括送信');

        $results = [];
        $historyItems = [];

        foreach ($customerIds as $customerId) {
            $customerId = (string)$customerId;

            $customer = null;

            foreach ($customers as $candidate) {
                if (($candidate['id'] ?? '') === $customerId) {
                    $customer = $candidate;
                    break;
                }
            }

            if (!$customer) {
                $results[] = [
                    'customerId' => $customerId,
                    'success' => false,
                    'error' => '顧客が存在しません。',
                ];
                continue;
            }

            $email = trim((string)($customer['email'] ?? ''));

            $token = uuid();

            $url = surveyPublicUrl(
                $surveyId,
                $token
            );

            $subject = renderMailTemplate(
                $subjectTemplate,
                $customer,
                $url
            );

            $body = renderMailTemplate(
                $bodyTemplate,
                $customer,
                $url
            );

            $sendResult = smtpSendMail(
                $mail,
                $email,
                $subject,
                $body
            );

            $results[] = [
                'customerId' => $customerId,
                'customerName' => $customer['name'] ?? '',
                'email' => $email,
                'success' => $sendResult['success'],
                'error' => $sendResult['error'] ?? '',
                'url' => $url,
                'subject' => $subject,
                'body' => $body,
            ];

            if ($sendResult['success']) {
                foreach ($customers as &$storedCustomer) {
                    if (($storedCustomer['id'] ?? '') === $customerId) {
                        $storedCustomer['status'] =
                            '送信済み / 未回答';
                        $storedCustomer['lastSentAt'] = nowIso();
                        $storedCustomer['sendCount'] =
                            (int)($storedCustomer['sendCount'] ?? 0) + 1;
                        break;
                    }
                }

                unset($storedCustomer);
            }

            $historyItems[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'customerName' => $customer['name'] ?? '',
                'organization' => $customer['organization'] ?? '',
                'email' => $email,
                'sendAt' => nowIso(),
                'sendType' => $type,
                'subject' => $subject,
                'body' => $body,
                'surveyUrl' => $url,
                'success' => $sendResult['success'],
                'error' => $sendResult['error'] ?? '',
                'executedBy' => '管理者',
            ];
        }

        writeJson('customers', $customers);

        $history = readJson('sendHistory', []);

        foreach ($historyItems as $item) {
            $history[] = $item;
        }

        writeJson('sendHistory', $history);

        $successCount = count(array_filter(
            $results,
            static fn($r) => !empty($r['success'])
        ));

        jsonResponse([
            'success' => true,
            'results' => $results,
            'summary' => [
                'target' => count($results),
                'success' => $successCount,
                'failure' => count($results) - $successCount,
                'sentAt' => nowIso(),
            ],
            'history' => $history,
        ]);
    }

    /* kintone設定 */
    if ($action === 'save_kintone') {
        $current = readJson(
            'kintone',
            defaultKintoneSettings()
        );

        $subdomainInput =
            normalizeString($input['subdomain'] ?? '');

        if ($subdomainInput !== '' &&
            normalizeSubdomain($subdomainInput) === null
        ) {
            jsonResponse([
                'success' => false,
                'error' =>
                    'サブドメインは xxxx、xxxx.cybozu.com、https://xxxx.cybozu.com のいずれかで入力してください。',
            ], 400);
        }

        $proxyInput =
            normalizeString($input['proxy'] ?? '');

        if ($proxyInput !== '' &&
            normalizeProxy($proxyInput) === null
        ) {
            jsonResponse([
                'success' => false,
                'error' =>
                    'プロキシはhost:port形式で入力してください。',
            ], 400);
        }

        $current['subdomain'] =
            $subdomainInput !== ''
                ? (normalizeSubdomain($subdomainInput) ?? '')
                : '';

        $current['appId'] =
            normalizeString($input['appId'] ?? '');

        $current['loginName'] =
            normalizeString($input['loginName'] ?? '');

        if (
            array_key_exists('password', $input) &&
            normalizeString($input['password'] ?? '') !== ''
        ) {
            $current['password'] =
                (string)$input['password'];
        }

        $current['sslVerify'] =
            normalizeBool($input['sslVerify'] ?? false);

        $current['proxy'] = $proxyInput;

        $current['mapping'] = [
            'organization' =>
                normalizeString(
                    $input['mapping']['organization'] ?? ''
                ),
            'name' =>
                normalizeString(
                    $input['mapping']['name'] ?? ''
                ),
            'email' =>
                normalizeString(
                    $input['mapping']['email'] ?? ''
                ),
            'department' =>
                normalizeString(
                    $input['mapping']['department'] ?? ''
                ),
            'phone' =>
                normalizeString(
                    $input['mapping']['phone'] ?? ''
                ),
            'address' => array_values(
                array_map(
                    'strval',
                    $input['mapping']['address'] ?? []
                )
            ),
        ];

        $current['updatedAt'] = nowIso();

        writeJson('kintone', $current);

        $safe = $current;
        $safe['password'] = '';
        $safe['passwordConfigured'] =
            (string)$current['password'] !== '';

        jsonResponse([
            'success' => true,
            'kintone' => $safe,
        ]);
    }

    if ($action === 'kintone_test') {
        $settings = readJson(
            'kintone',
            defaultKintoneSettings()
        );

        /* 未保存フォーム値を利用 */
        if (!empty($input['settings']) && is_array($input['settings'])) {
            $temp = $input['settings'];

            if (isset($temp['subdomain'])) {
                $settings['subdomain'] =
                    normalizeString($temp['subdomain']);
            }

            if (isset($temp['appId'])) {
                $settings['appId'] =
                    normalizeString($temp['appId']);
            }

            if (isset($temp['loginName'])) {
                $settings['loginName'] =
                    normalizeString($temp['loginName']);
            }

            if (
                isset($temp['password']) &&
                normalizeString($temp['password']) !== ''
            ) {
                $settings['password'] =
                    (string)$temp['password'];
            }

            if (array_key_exists('sslVerify', $temp)) {
                $settings['sslVerify'] =
                    normalizeBool($temp['sslVerify']);
            }

            if (isset($temp['proxy'])) {
                $settings['proxy'] =
                    normalizeString($temp['proxy']);
            }
        }

        $result = kintoneTestConnection($settings);

        jsonResponse($result);
    }

    if ($action === 'kintone_fields') {
        $settings = readJson(
            'kintone',
            defaultKintoneSettings()
        );

        $result = kintoneGetFields($settings);

        if (!$result['success']) {
            jsonResponse($result, 400);
        }

        $settings['fields'] = $result['fields'];
        $settings['updatedAt'] = nowIso();

        writeJson('kintone', $settings);

        jsonResponse([
            'success' => true,
            'fields' => $result['fields'],
        ]);
    }

    if ($action === 'kintone_sync') {
        $settings = readJson(
            'kintone',
            defaultKintoneSettings()
        );

        $result = kintoneSyncCustomers(
            $settings,
            $settings['mapping'] ?? []
        );

        jsonResponse($result);
    }

    /* mail settings */
    if ($action === 'save_mail') {
        $current = readJson(
            'mail',
            defaultMailSettings()
        );

        foreach (
            [
                'smtpServer',
                'smtpPort',
                'encryption',
                'authentication',
                'username',
                'fromEmail',
                'fromName',
                'replyTo',
            ] as $field
        ) {
            if (array_key_exists($field, $input)) {
                $current[$field] = $input[$field];
            }
        }

        if (
            array_key_exists('password', $input) &&
            normalizeString($input['password'] ?? '') !== ''
        ) {
            $current['password'] =
                (string)$input['password'];
        }

        $current['smtpServer'] =
            normalizeString($current['smtpServer'] ?? '');

        $current['smtpPort'] =
            max(1, min(65535, (int)($current['smtpPort'] ?? 587)));

        $current['encryption'] =
            in_array(
                $current['encryption'] ?? '',
                ['none', 'starttls', 'ssl'],
                true
            )
                ? $current['encryption']
                : 'starttls';

        $current['authentication'] =
            normalizeBool($current['authentication'] ?? false);

        $current['updatedAt'] = nowIso();

        writeJson('mail', $current);

        $safe = $current;
        $safe['password'] = '';
        $safe['passwordConfigured'] =
            (string)$current['password'] !== '';

        jsonResponse([
            'success' => true,
            'mail' => $safe,
        ]);
    }

    if ($action === 'mail_test') {
        $settings = readJson(
            'mail',
            defaultMailSettings()
        );

        if (!empty($input['settings']) && is_array($input['settings'])) {
            $temp = $input['settings'];

            foreach (
                [
                    'smtpServer',
                    'smtpPort',
                    'encryption',
                    'authentication',
                    'username',
                    'fromEmail',
                    'fromName',
                    'replyTo',
                ] as $field
            ) {
                if (array_key_exists($field, $temp)) {
                    $settings[$field] = $temp[$field];
                }
            }

            if (
                isset($temp['password']) &&
                normalizeString($temp['password']) !== ''
            ) {
                $settings['password'] =
                    (string)$temp['password'];
            }
        }

        $to = normalizeString(
            $input['testEmail'] ??
            $settings['replyTo'] ??
            $settings['fromEmail'] ??
            ''
        );

        $result = smtpTest($settings, $to);

        $stored = readJson(
            'mail',
            defaultMailSettings()
        );

        $stored['connectionStatus'] =
            $result['success']
                ? '接続確認済み'
                : '接続できません';

        $stored['lastTestAt'] = nowIso();
        $stored['lastError'] = $result['error'] ?? '';

        writeJson('mail', $stored);

        jsonResponse([
            'success' => $result['success'],
            'message' => $result['success']
                ? 'テストメール送信成功'
                : 'テストメール送信失敗',
            'detail' => $result['error'] ?? '',
        ]);
    }

    /* 未知API */
    jsonResponse([
        'success' => false,
        'error' => '不明な操作です。',
    ], 400);
}

/* ------------------------------------------------------------
 * HTML
 * ------------------------------------------------------------ */
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --danger:#dc2626;
    --warning:#d97706;
    --success:#059669;
    --text:#172033;
    --muted:#667085;
    --border:#dbe2ea;
    --bg:#f5f7fb;
    --card:#fff;
    --shadow:0 2px 10px rgba(15,23,42,.07);
    --radius:10px;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;color:var(--text);background:var(--bg)}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
.hidden{display:none!important}
.app{min-height:100vh}
.admin-header{
    height:64px;background:#172033;color:#fff;display:flex;
    align-items:center;padding:0 22px;gap:24px;
    position:sticky;top:0;z-index:20;
}
.brand{font-size:18px;font-weight:700;white-space:nowrap}
.admin-nav{display:flex;gap:5px;flex:1}
.admin-nav button{
    background:transparent;border:0;color:#cbd5e1;
    padding:9px 12px;border-radius:7px
}
.admin-nav button:hover,.admin-nav button.active{
    background:#26344d;color:#fff
}
.logout-btn{
    border:1px solid #475569!important;
    color:#e2e8f0!important;
}
main{max-width:1500px;margin:0 auto;padding:24px}
.page-header{
    display:flex;align-items:center;justify-content:space-between;
    gap:15px;margin-bottom:20px;flex-wrap:wrap
}
.page-header h1{margin:0;font-size:25px}
.page-header .subtitle{color:var(--muted);font-size:13px;margin-top:4px}
.toolbar{
    background:var(--card);border:1px solid var(--border);
    border-radius:var(--radius);padding:14px;display:flex;
    gap:10px;flex-wrap:wrap;margin-bottom:16px
}
input,select,textarea{
    border:1px solid #cbd5e1;border-radius:7px;
    background:#fff;color:var(--text);padding:9px 11px;
    width:100%;
}
input:focus,select:focus,textarea:focus{
    outline:2px solid rgba(37,99,235,.15);
    border-color:var(--primary)
}
textarea{min-height:110px;resize:vertical}
button{
    border:1px solid #cbd5e1;background:#fff;color:var(--text);
    border-radius:7px;padding:9px 13px;
}
button:hover{background:#f8fafc}
button.primary{background:var(--primary);color:#fff;border-color:var(--primary)}
button.primary:hover{background:var(--primary-dark)}
button.danger{background:#fff;color:var(--danger);border-color:#fecaca}
button.success{background:var(--success);color:#fff;border-color:var(--success)}
button.warning{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
button.small{font-size:12px;padding:6px 8px}
button:disabled{opacity:.5;cursor:not-allowed}
.table-wrap{
    overflow-x:auto;background:#fff;border:1px solid var(--border);
    border-radius:var(--radius);box-shadow:var(--shadow)
}
table{width:100%;border-collapse:collapse;min-width:900px}
th,td{padding:12px 13px;border-bottom:1px solid #edf0f4;text-align:left;vertical-align:middle}
th{background:#f8fafc;color:#475569;font-size:12px;white-space:nowrap}
td{font-size:13px}
tr:last-child td{border-bottom:0}
.actions{display:flex;gap:5px;flex-wrap:wrap}
.status{
    display:inline-flex;align-items:center;justify-content:center;
    border-radius:999px;padding:4px 9px;font-size:11px;font-weight:700;
    white-space:nowrap
}
.status-draft{background:#eef2ff;color:#4338ca}
.status-published{background:#ecfdf5;color:#047857}
.status-stopped{background:#fff7ed;color:#c2410c}
.status-finished{background:#f1f5f9;color:#475569}
.card{
    background:#fff;border:1px solid var(--border);border-radius:var(--radius);
    box-shadow:var(--shadow);padding:20px;margin-bottom:16px
}
.card h2,.card h3{margin-top:0}
.form-grid{
    display:grid;grid-template-columns:repeat(2,minmax(0,1fr));
    gap:15px
}
.form-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}
.field label{
    display:block;font-size:12px;font-weight:700;margin-bottom:6px;
    color:#475569
}
.field.full{grid-column:1/-1}
.help{font-size:12px;color:var(--muted);margin-top:5px}
.page-actions{display:flex;gap:8px;flex-wrap:wrap}
.editor-group{
    border:1px solid #cfd8e3;border-radius:10px;margin-bottom:16px;
    background:#f8fafc
}
.group-head{
    display:flex;align-items:center;gap:8px;padding:12px;
    border-bottom:1px solid #dbe2ea;background:#eef2f7;
    border-radius:10px 10px 0 0
}
.group-head .drag-handle,.question-head .drag-handle{
    cursor:grab;color:#64748b;font-size:18px
}
.group-head input{flex:1;font-weight:700}
.group-body{padding:12px}
.question{
    background:#fff;border:1px solid #dbe2ea;border-radius:8px;
    padding:13px;margin-bottom:10px
}
.question.dragging,.editor-group.dragging{opacity:.45}
.question-head{
    display:flex;align-items:center;gap:8px;margin-bottom:10px
}
.question-number{
    min-width:55px;font-weight:700;color:#2563eb
}
.question-head input{flex:1}
.question-meta{
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;
    margin-bottom:10px
}
.choice-row{
    display:flex;gap:7px;align-items:center;margin-bottom:7px
}
.choice-row input{flex:1}
.choice-row button{flex:none}
.branch-row{
    display:grid;grid-template-columns:1fr 1fr auto;gap:8px;
    margin-bottom:7px
}
.add-area{
    border:1px dashed #94a3b8;border-radius:7px;padding:9px;
    text-align:center;background:#fff;margin-top:8px
}
.preview-frame{
    border:1px solid #cbd5e1;border-radius:10px;background:#e2e8f0;
    padding:25px;display:flex;justify-content:center;min-height:500px
}
.preview-device{
    background:#fff;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.15);
    width:100%;max-width:1100px;padding:30px
}
.preview-device.mobile{max-width:390px;padding:18px}
.answer-shell{
    min-height:100vh;background:#f5f7fb
}
.answer-header{
    background:#fff;border-bottom:1px solid var(--border);
    padding:18px 20px
}
.answer-container{max-width:800px;margin:0 auto;padding:20px}
.answer-question{
    background:#fff;border:1px solid var(--border);border-radius:10px;
    padding:18px;margin-bottom:15px
}
.answer-question.required .q-label::after{
    content:"必須";font-size:10px;background:#fee2e2;color:#b91c1c;
    border-radius:4px;padding:3px 5px;margin-left:7px;vertical-align:middle
}
.q-label{font-weight:700;line-height:1.6;margin-bottom:12px}
.option{
    display:flex;align-items:center;gap:10px;border:1px solid #dbe2ea;
    border-radius:8px;padding:12px;margin-bottom:8px;cursor:pointer
}
.option:hover{background:#f8fafc}
.option input{width:auto}
.answer-nav{display:flex;justify-content:space-between;gap:10px;margin-top:20px}
.summary-grid{
    display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px
}
.summary-card{
    background:#fff;border:1px solid var(--border);border-radius:9px;
    padding:16px
}
.summary-card .value{font-size:26px;font-weight:800;margin-top:5px}
.summary-card .label{font-size:12px;color:var(--muted)}
.bar-row{
    display:grid;grid-template-columns:170px 1fr 70px;gap:10px;
    align-items:center;margin:10px 0
}
.bar-bg{height:16px;background:#e2e8f0;border-radius:999px;overflow:hidden}
.bar{height:100%;background:#2563eb;border-radius:999px}
.history-item{
    border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:8px;
    background:#fff
}
.history-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.modal-backdrop{
    position:fixed;inset:0;background:rgba(15,23,42,.55);
    display:flex;align-items:center;justify-content:center;
    z-index:100;padding:20px
}
.modal{
    width:min(560px,100%);background:#fff;border-radius:12px;
    box-shadow:0 15px 60px rgba(0,0,0,.25);overflow:hidden
}
.modal-head{padding:16px 18px;border-bottom:1px solid var(--border);font-weight:700}
.modal-body{padding:18px;line-height:1.7}
.modal-foot{padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}
.toast{
    position:fixed;right:20px;bottom:20px;z-index:200;
    background:#172033;color:#fff;border-radius:8px;padding:12px 16px;
    box-shadow:var(--shadow);max-width:420px
}
.toast.error{background:#991b1b}
.toast.success{background:#047857}
.error-box{
    background:#fef2f2;color:#991b1b;border:1px solid #fecaca;
    border-radius:8px;padding:10px 12px;margin-bottom:12px
}
.success-box{
    background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;
    border-radius:8px;padding:10px 12px;margin-bottom:12px
}
.info-box{
    background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;
    border-radius:8px;padding:10px 12px;margin-bottom:12px
}
.inline{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.checkbox{display:flex;align-items:center;gap:7px}
.checkbox input{width:auto}
.tabs{display:flex;gap:5px;border-bottom:1px solid var(--border);margin-bottom:15px}
.tab{
    border:0;border-bottom:2px solid transparent;border-radius:0;
    background:transparent;color:#64748b
}
.tab.active{color:#2563eb;border-bottom-color:#2563eb}
.result-table{margin-top:15px}
.sticky-actions{
    position:sticky;bottom:0;background:rgba(255,255,255,.95);
    border-top:1px solid var(--border);padding:12px;z-index:10;
    display:flex;justify-content:flex-end;gap:8px
}
.kintone-field-list{
    max-height:260px;overflow:auto;border:1px solid var(--border);
    border-radius:8px;padding:8px
}
.field-item{padding:7px 8px;border-bottom:1px solid #eef2f7;font-size:12px}
.field-item:last-child{border-bottom:0}
.muted{color:var(--muted)}
.right{text-align:right}
.center{text-align:center}
@media(max-width:900px){
    .admin-header{height:auto;min-height:60px;flex-wrap:wrap;padding:10px 14px}
    .admin-nav{order:3;flex-basis:100%;overflow-x:auto}
    main{padding:15px}
    .form-grid,.form-grid.three{grid-template-columns:1fr}
    .summary-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:600px){
    .page-header{align-items:flex-start}
    .page-header h1{font-size:21px}
    .toolbar{padding:10px}
    .summary-grid{grid-template-columns:1fr 1fr}
    .summary-card .value{font-size:21px}
    .bar-row{grid-template-columns:100px 1fr 50px;font-size:11px}
    .answer-container{padding:12px}
    .answer-header{padding:14px}
    .branch-row{grid-template-columns:1fr}
    .sticky-actions{flex-wrap:wrap}
}
</style>
</head>
<body>
<div id="app" class="app"></div>
<div id="modalRoot"></div>
<div id="toastRoot"></div>

<script>
"use strict";

/* ============================================================
 * アプリケーション状態
 * ============================================================ */

const state = {
    surveys: [],
    customers: [],
    responses: [],
    sendHistory: [],
    kintone: {},
    mail: {},

    screen: "list",

    editSurveyId: null,
    aggregateSurveyId: null,
    sendSurveyId: null,

    answerSurveyId: null,
    answerToken: null,
    answerCustomerId: null,
    answerRespondent: {},
    answers: {},
    answerStep: 0,

    listSearch: "",
    listFilter: "all",
    listSort: "updatedDesc",

    customerSearch: "",
    customerFilter: "all",
    selectedCustomerIds: new Set(),

    sendTab: "customers",
    sendSubject: "アンケートのお願い",
    sendBody:
        "{顧客名} 様\n\n" +
        "アンケートへのご協力をお願いいたします。\n\n" +
        "以下のURLよりご回答ください。\n" +
        "{アンケートURL}\n\n" +
        "よろしくお願いいたします。",
    sendResults: null,

    selectedQuestions: new Set(),

    editorDraft: null,
    previewMode: "pc",

    modalResolver: null
};

const STATUS_LABEL = {
    draft: "下書き",
    published: "公開中",
    stopped: "停止",
    finished: "終了"
};

const TYPE_LABEL = {
    single: "単一選択",
    multiple: "複数選択",
    text: "自由記述"
};

/* ============================================================
 * API
 * ============================================================ */

async function api(action, payload = {}) {
    const response = await fetch(
        "?api=" + encodeURIComponent(action),
        {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                action,
                ...payload
            })
        }
    );

    const data = await response.json();

    if (!response.ok || data.success === false) {
        throw new Error(
            data.error ||
            data.detail ||
            "処理に失敗しました。"
        );
    }

    return data;
}

async function loadData() {
    const data = await api("get_data");

    state.surveys = data.surveys || [];
    state.customers = data.customers || [];
    state.responses = data.responses || [];
    state.sendHistory = data.sendHistory || [];
    state.kintone = data.kintone || {};
    state.mail = data.mail || {};
}

/* ============================================================
 * 表示ヘルパー
 * ============================================================ */

function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function formatDate(value) {
    if (!value) return "-";

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) {
        return String(value);
    }

    return d.toLocaleString("ja-JP", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit"
    });
}

function statusBadge(status) {
    return `
        <span class="status status-${escapeHtml(status)}">
            ${escapeHtml(STATUS_LABEL[status] || status)}
        </span>
    `;
}

function toast(message, type = "") {
    const root = document.getElementById("toastRoot");

    root.innerHTML = `
        <div class="toast ${type}">
            ${escapeHtml(message)}
        </div>
    `;

    setTimeout(() => {
        root.innerHTML = "";
    }, 3500);
}

function showError(error) {
    console.error(error);
    toast(
        error?.message ||
        String(error),
        "error"
    );
}

function confirmModal(title, message) {
    return new Promise(resolve => {
        state.modalResolver = resolve;

        document.getElementById("modalRoot").innerHTML = `
            <div class="modal-backdrop" id="modalBackdrop">
                <div class="modal" role="dialog" aria-modal="true">
                    <div class="modal-head">${escapeHtml(title)}</div>
                    <div class="modal-body">${escapeHtml(message).replace(/\n/g, "<br>")}</div>
                    <div class="modal-foot">
                        <button data-modal-cancel>キャンセル</button>
                        <button class="primary" data-modal-ok>実行</button>
                    </div>
                </div>
            </div>
        `;

        document.querySelector("[data-modal-cancel]")
            .addEventListener("click", () => closeModal(false));

        document.querySelector("[data-modal-ok]")
            .addEventListener("click", () => closeModal(true));

        document.getElementById("modalBackdrop")
            .addEventListener("click", e => {
                if (e.target.id === "modalBackdrop") {
                    closeModal(false);
                }
            });
    });
}

function closeModal(result) {
    document.getElementById("modalRoot").innerHTML = "";

    if (state.modalResolver) {
        const resolver = state.modalResolver;
        state.modalResolver = null;
        resolver(result);
    }
}

function go(screen) {
    state.screen = screen;
    render();
    window.scrollTo({top: 0, behavior: "smooth"});
}

function getSurvey(id) {
    return state.surveys.find(s => s.id === id) || null;
}

function getQuestion(survey, id) {
    for (const group of survey?.groups || []) {
        const q = (group.questions || []).find(x => x.id === id);
        if (q) return q;
    }

    return null;
}

function getQuestionIndexList(survey) {
    const result = [];

    for (const group of survey?.groups || []) {
        for (const question of group.questions || []) {
            result.push(question);
        }
    }

    return result;
}

function statusText(customer, surveyId) {
    const response = state.responses.find(r =>
        r.surveyId === surveyId &&
        r.customerId === customer.id &&
        r.status === "completed"
    );

    if (response) return "回答済み";

    return customer.status || "未送信";
}

/* ============================================================
 * 管理者ヘッダー
 * ============================================================ */

function adminHeader() {
    return `
        <header class="admin-header">
            <div class="brand">アンケート管理システム</div>

            <nav class="admin-nav">
                <button
                    class="${state.screen === "list" ? "active" : ""}"
                    data-nav="list"
                >アンケート一覧</button>

                <button
                    class="${state.screen === "kintone" ? "active" : ""}"
                    data-nav="kintone"
                >kintone連携設定</button>

                <button
                    class="${state.screen === "mail" ? "active" : ""}"
                    data-nav="mail"
                >メールサーバ設定</button>

                <button class="logout-btn" data-ui-logout>
                    リセット
                </button>
            </nav>
        </header>
    `;
}

/* ============================================================
 * 一覧
 * ============================================================ */

function renderList() {
    let surveys = [...state.surveys];

    const q = state.listSearch.trim().toLowerCase();

    if (q) {
        surveys = surveys.filter(s =>
            String(s.title || "")
                .toLowerCase()
                .includes(q)
        );
    }

    if (state.listFilter !== "all") {
        surveys = surveys.filter(
            s => s.status === state.listFilter
        );
    }

    const sorters = {
        updatedDesc: (a,b) =>
            new Date(b.updatedAt || 0) -
            new Date(a.updatedAt || 0),

        updatedAsc: (a,b) =>
            new Date(a.updatedAt || 0) -
            new Date(b.updatedAt || 0),

        answersDesc: (a,b) =>
            responseCount(b.id) -
            responseCount(a.id),

        answersAsc: (a,b) =>
            responseCount(a.id) -
            responseCount(b.id),

        startDesc: (a,b) =>
            new Date(b.startDate || 0) -
            new Date(a.startDate || 0),

        startAsc: (a,b) =>
            new Date(a.startDate || 0) -
            new Date(b.startDate || 0)
    };

    surveys.sort(
        sorters[state.listSort] ||
        sorters.updatedDesc
    );

    return `
        <div class="page-header">
            <div>
                <h1>アンケート一覧</h1>
                <div class="subtitle">
                    アンケート管理業務の起点
                </div>
            </div>

            <button class="primary" data-create-survey>
                ＋ アンケートを作成
            </button>
        </div>

        <div class="toolbar">
            <input
                id="surveySearch"
                placeholder="タイトルを検索"
                value="${escapeHtml(state.listSearch)}"
                style="max-width:300px"
            >

            <select id="surveyFilter" style="width:auto">
                <option value="all">すべて</option>
                <option value="published" ${state.listFilter === "published" ? "selected" : ""}>公開中</option>
                <option value="draft" ${state.listFilter === "draft" ? "selected" : ""}>下書き</option>
                <option value="stopped" ${state.listFilter === "stopped" ? "selected" : ""}>停止</option>
                <option value="finished" ${state.listFilter === "finished" ? "selected" : ""}>終了</option>
            </select>

            <select id="surveySort" style="width:auto">
                <option value="updatedDesc" ${state.listSort === "updatedDesc" ? "selected" : ""}>更新日 新しい順</option>
                <option value="updatedAsc" ${state.listSort === "updatedAsc" ? "selected" : ""}>更新日 古い順</option>
                <option value="answersDesc" ${state.listSort === "answersDesc" ? "selected" : ""}>回答数 多い順</option>
                <option value="answersAsc" ${state.listSort === "answersAsc" ? "selected" : ""}>回答数 少ない順</option>
                <option value="startDesc" ${state.listSort === "startDesc" ? "selected" : ""}>開始日 新しい順</option>
                <option value="startAsc" ${state.listSort === "startAsc" ? "selected" : ""}>開始日 古い順</option>
            </select>

            <button data-search-survey>検索</button>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>作成日 / 更新日</th>
                        <th>タイトル</th>
                        <th>アンケート期間</th>
                        <th>ステータス</th>
                        <th>回答数</th>
                        <th>操作</th>
                    </tr>
                </thead>

                <tbody>
                    ${
                        surveys.length
                        ? surveys.map(s => `
                            <tr>
                                <td>
                                    <div>${formatDate(s.createdAt)}</div>
                                    <div class="muted">${formatDate(s.updatedAt)}</div>
                                </td>

                                <td>
                                    <strong>${escapeHtml(s.title || "(無題)")}</strong>
                                </td>

                                <td>
                                    ${formatDate(s.startDate)}
                                    〜
                                    ${formatDate(s.endDate)}
                                </td>

                                <td>${statusBadge(s.status)}</td>

                                <td>${responseCount(s.id)}</td>

                                <td>
                                    <div class="actions">
                                        <button
                                            class="small"
                                            data-edit="${escapeHtml(s.id)}"
                                        >確認・編集</button>

                                        <button
                                            class="small"
                                            data-aggregate="${escapeHtml(s.id)}"
                                        >集計</button>

                                        <button
                                            class="small primary"
                                            data-send="${escapeHtml(s.id)}"
                                        >送信</button>

                                        <button
                                            class="small"
                                            data-duplicate="${escapeHtml(s.id)}"
                                        >複製</button>

                                        <button
                                            class="small danger"
                                            data-delete="${escapeHtml(s.id)}"
                                        >削除</button>
                                    </div>
                                </td>
                            </tr>
                        `).join("")
                        : `
                            <tr>
                                <td colspan="6" class="center muted">
                                    該当するアンケートはありません。
                                </td>
                            </tr>
                        `
                    }
                </tbody>
            </table>
        </div>
    `;
}

function responseCount(surveyId) {
    return state.responses.filter(
        r =>
            r.surveyId === surveyId &&
            r.status === "completed"
    ).length;
}

/* ============================================================
 * 編集
 * ============================================================ */

function blankSurvey() {
    return {
        id: null,
        title: "",
        description: "",
        startDate: "",
        endDate: "",
        questionNumberMode: "all",
        allowResubmission: false,
        status: "draft",
        groups: [],
        createdAt: null,
        updatedAt: null
    };
}

function createGroup() {
    return {
        id: crypto.randomUUID(),
        title: "",
        sortOrder: 1,
        questions: []
    };
}

function createQuestion(groupId) {
    return {
        id: crypto.randomUUID(),
        groupId,
        sortOrder: 1,
        questionNumber: "",
        text: "",
        type: "single",
        required: false,
        choices: [
            {
                id: crypto.randomUUID(),
                label: "",
                sortOrder: 1
            }
        ],
        branches: []
    };
}

function recalcDraftNumbers(survey) {
    let global = 0;

    (survey.groups || []).forEach((group, gi) => {
        (group.questions || []).forEach((question, qi) => {
            global++;

            question.groupId = group.id;
            question.sortOrder = qi + 1;

            question.questionNumber =
                survey.questionNumberMode === "group"
                ? `Q${gi + 1}-${qi + 1}`
                : `Q${global}`;
        });

        group.sortOrder = gi + 1;
    });
}

function renderEdit() {
    const survey = state.editorDraft;

    if (!survey) {
        return `
            <div class="card">
                編集対象がありません。
            </div>
        `;
    }

    recalcDraftNumbers(survey);

    const currentStatus = survey.status || "draft";

    let statusOptions = "";

    if (currentStatus === "draft") {
        statusOptions = `
            <option value="draft" selected>下書き</option>
            <option value="published">公開中</option>
        `;
    } else if (currentStatus === "published") {
        statusOptions = `
            <option value="published" selected>公開中</option>
            <option value="stopped">停止</option>
        `;
    } else if (currentStatus === "stopped") {
        statusOptions = `
            <option value="stopped" selected>停止</option>
            <option value="published">公開中</option>
        `;
    } else {
        statusOptions = `
            <option value="finished" selected>終了</option>
        `;
    }

    return `
        <div class="page-header">
            <div>
                <h1>アンケート作成・編集</h1>
                <div class="subtitle">
                    状態変更と保存は別の業務操作です。
                </div>
            </div>

            <div class="page-actions">
                <button data-edit-cancel>キャンセル</button>
                <button class="primary" data-save-survey>
                    保存して一覧へ
                </button>
            </div>
        </div>

        <div class="card">
            <div class="form-grid">
                <div class="field full">
                    <label>タイトル</label>
                    <input
                        id="editTitle"
                        value="${escapeHtml(survey.title)}"
                    >
                </div>

                <div class="field full">
                    <label>説明</label>
                    <textarea id="editDescription">${escapeHtml(survey.description)}</textarea>
                </div>

                <div class="field">
                    <label>開始日時</label>
                    <input
                        type="datetime-local"
                        id="editStartDate"
                        value="${toDateTimeLocal(survey.startDate)}"
                    >
                </div>

                <div class="field">
                    <label>終了日時</label>
                    <input
                        type="datetime-local"
                        id="editEndDate"
                        value="${toDateTimeLocal(survey.endDate)}"
                    >
                </div>

                <div class="field">
                    <label>質問番号の採番方式</label>
                    <select id="editNumberMode">
                        <option value="all" ${survey.questionNumberMode === "all" ? "selected" : ""}>
                            アンケート全体で通番
                        </option>
                        <option value="group" ${survey.questionNumberMode === "group" ? "selected" : ""}>
                            グループ毎に採番
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>状態</label>
                    <select
                        id="editStatus"
                        ${currentStatus === "finished" ? "disabled" : ""}
                    >
                        ${statusOptions}
                    </select>
                    <div class="help">
                        状態selectの変更は確認後に確定します。
                    </div>
                </div>

                <div class="field full">
                    <label class="checkbox">
                        <input
                            type="checkbox"
                            id="editResubmission"
                            ${survey.allowResubmission ? "checked" : ""}
                        >
                        回答済みURLからの再回答を許可する
                    </label>
                </div>
            </div>
        </div>

        <div id="groupsContainer">
            ${
                (survey.groups || []).map(
                    (group, index) =>
                        renderGroupEditor(group, index, survey)
                ).join("")
            }
        </div>

        <div class="add-area">
            <button data-add-group>
                ＋ グループを追加
            </button>
        </div>

        <div class="sticky-actions">
            <button data-preview-edit>プレビュー</button>
            <button data-edit-cancel>キャンセル</button>
            <button class="primary" data-save-survey>
                保存して一覧へ
            </button>
        </div>
    `;
}

function toDateTimeLocal(value) {
    if (!value) return "";

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) {
        return "";
    }

    const pad = n => String(n).padStart(2, "0");

    return d.getFullYear() +
        "-" + pad(d.getMonth() + 1) +
        "-" + pad(d.getDate()) +
        "T" + pad(d.getHours()) +
        ":" + pad(d.getMinutes());
}

function fromDateTimeLocal(value) {
    if (!value) return null;

    const d = new Date(value);

    return Number.isNaN(d.getTime())
        ? null
        : d.toISOString();
}

function renderGroupEditor(group, index, survey) {
    return `
        <div
            class="editor-group"
            draggable="true"
            data-group-id="${escapeHtml(group.id)}"
        >
            <div class="group-head">
                <span class="drag-handle">☷</span>

                <strong>グループ ${index + 1}</strong>

                <input
                    data-group-title="${escapeHtml(group.id)}"
                    value="${escapeHtml(group.title)}"
                    placeholder="グループタイトル"
                >

                <button
                    class="small danger"
                    data-delete-group="${escapeHtml(group.id)}"
                >
                    グループ削除
                </button>
            </div>

            <div class="group-body">
                ${
                    (group.questions || []).map(
                        (question, qIndex) =>
                            renderQuestionEditor(
                                question,
                                qIndex,
                                group,
                                survey
                            )
                    ).join("")
                }

                <div class="add-area">
                    <button
                        data-add-question="${escapeHtml(group.id)}"
                    >
                        ＋ 質問を追加
                    </button>
                </div>
            </div>
        </div>
    `;
}

function renderQuestionEditor(question, index, group, survey) {
    return `
        <div
            class="question"
            draggable="true"
            data-question-id="${escapeHtml(question.id)}"
            data-question-group="${escapeHtml(group.id)}"
        >
            <div class="question-head">
                <span class="drag-handle">☷</span>

                <span class="question-number">
                    ${escapeHtml(question.questionNumber)}
                </span>

                <input
                    data-question-text="${escapeHtml(question.id)}"
                    value="${escapeHtml(question.text)}"
                    placeholder="質問文"
                >

                <button
                    class="small danger"
                    data-delete-question="${escapeHtml(question.id)}"
                >
                    削除
                </button>
            </div>

            <div class="question-meta">
                <select
                    data-question-type="${escapeHtml(question.id)}"
                    style="max-width:180px"
                >
                    <option value="single" ${question.type === "single" ? "selected" : ""}>
                        単一選択
                    </option>
                    <option value="multiple" ${question.type === "multiple" ? "selected" : ""}>
                        複数選択
                    </option>
                    <option value="text" ${question.type === "text" ? "selected" : ""}>
                        自由記述
                    </option>
                </select>

                <label class="checkbox">
                    <input
                        type="checkbox"
                        data-question-required="${escapeHtml(question.id)}"
                        ${question.required ? "checked" : ""}
                    >
                    必須
                </label>
            </div>

            ${
                question.type !== "text"
                ? `
                    <div>
                        <strong style="font-size:12px">選択肢</strong>

                        <div data-choices="${escapeHtml(question.id)}">
                            ${
                                (question.choices || []).map(
                                    choice => `
                                        <div class="choice-row">
                                            <input
                                                data-choice-label="${escapeHtml(choice.id)}"
                                                data-choice-question="${escapeHtml(question.id)}"
                                                value="${escapeHtml(choice.label)}"
                                                placeholder="選択肢"
                                            >
                                            <button
                                                class="small danger"
                                                data-delete-choice="${escapeHtml(choice.id)}"
                                                data-choice-question="${escapeHtml(question.id)}"
                                            >削除</button>
                                        </div>
                                    `
                                ).join("")
                            }
                        </div>

                        <button
                            class="small"
                            data-add-choice="${escapeHtml(question.id)}"
                        >
                            ＋ 選択肢を追加
                        </button>
                    </div>
                `
                : ""
            }

            ${
                question.type === "single"
                ? `
                    <div style="margin-top:14px">
                        <strong style="font-size:12px">
                            条件分岐
                        </strong>

                        <div class="help">
                            選択肢ごとに次に表示する質問を設定します。
                        </div>

                        <div style="margin-top:8px">
                            ${
                                (question.choices || []).map(
                                    choice => {
                                        const branch =
                                            (question.branches || [])
                                                .find(
                                                    b =>
                                                        b.choiceId === choice.id
                                                );

                                        return `
                                            <div class="branch-row">
                                                <div class="inline">
                                                    <span>
                                                        ${escapeHtml(choice.label || "未入力")}
                                                    </span>
                                                </div>

                                                <select
                                                    data-branch-next
                                                    data-branch-question="${escapeHtml(question.id)}"
                                                    data-branch-choice="${escapeHtml(choice.id)}"
                                                >
                                                    <option value="">次の質問へ</option>
                                                    ${
                                                        getQuestionIndexList(survey)
                                                            .filter(
                                                                q => q.id !== question.id
                                                            )
                                                            .map(
                                                                q => `
                                                                    <option
                                                                        value="${escapeHtml(q.id)}"
                                                                        ${branch?.nextQuestionId === q.id ? "selected" : ""}
                                                                    >
                                                                        ${escapeHtml(q.questionNumber)} ${escapeHtml(q.text)}
                                                                    </option>
                                                                `
                                                            ).join("")
                                                    }
                                                </select>

                                                <button
                                                    class="small"
                                                    data-clear-branch
                                                    data-branch-question="${escapeHtml(question.id)}"
                                                    data-branch-choice="${escapeHtml(choice.id)}"
                                                >
                                                    解除
                                                </button>
                                            </div>
                                        `;
                                    }
                                ).join("")
                            }
                        </div>
                    </div>
                `
                : ""
            }
        </div>
    `;
}

function ensureEditorDraft() {
    if (!state.editorDraft) {
        state.editorDraft = blankSurvey();
    }
}

/* ============================================================
 * プレビュー
 * ============================================================ */

function renderPreview() {
    const survey = state.editorDraft;

    if (!survey) {
        return `<div class="card">プレビュー対象がありません。</div>`;
    }

    recalcDraftNumbers(survey);

    return `
        <div class="page-header">
            <div>
                <h1>プレビュー</h1>
                <div class="subtitle">
                    実際の回答送信は行いません。
                </div>
            </div>

            <div class="page-actions">
                <button
                    class="${state.previewMode === "pc" ? "primary" : ""}"
                    data-preview-mode="pc"
                >PC</button>

                <button
                    class="${state.previewMode === "mobile" ? "primary" : ""}"
                    data-preview-mode="mobile"
                >スマートフォン</button>

                <button data-preview-back>
                    編集へ戻る
                </button>
            </div>
        </div>

        <div class="preview-frame">
            <div class="preview-device ${state.previewMode === "mobile" ? "mobile" : ""}">
                <h1>${escapeHtml(survey.title || "アンケート")}</h1>

                ${
                    survey.description
                    ? `<p class="muted">${escapeHtml(survey.description)}</p>`
                    : ""
                }

                ${
                    (survey.groups || []).map(group => `
                        <section style="margin-top:25px">
                            <h2 style="font-size:18px">
                                ${escapeHtml(group.title || "グループ")}
                            </h2>

                            ${
                                (group.questions || []).map(q =>
                                    renderPreviewQuestion(q)
                                ).join("")
                            }
                        </section>
                    `).join("")
                }
            </div>
        </div>
    `;
}

function renderPreviewQuestion(question) {
    return `
        <div class="answer-question ${question.required ? "required" : ""}">
            <div class="q-label">
                ${escapeHtml(question.questionNumber)}
                ${escapeHtml(question.text)}
            </div>

            ${
                question.type === "single"
                ? (question.choices || []).map(c => `
                    <div class="option">
                        <input type="radio" disabled>
                        <span>${escapeHtml(c.label)}</span>
                    </div>
                `).join("")
                : ""
            }

            ${
                question.type === "multiple"
                ? (question.choices || []).map(c => `
                    <div class="option">
                        <input type="checkbox" disabled>
                        <span>${escapeHtml(c.label)}</span>
                    </div>
                `).join("")
                : ""
            }

            ${
                question.type === "text"
                ? `<textarea disabled placeholder="回答を入力"></textarea>`
                : ""
            }
        </div>
    `;
}

/* ============================================================
 * 顧客送信
 * ============================================================ */

function renderSend() {
    const survey = getSurvey(state.sendSurveyId);

    if (!survey) {
        return `
            <div class="card">
                <div class="error-box">
                    対象アンケートが指定されていません。
                </div>
                <button data-back-list>一覧へ戻る</button>
            </div>
        `;
    }

    const customers = filteredCustomers(survey.id);

    const histories = state.sendHistory
        .filter(h => h.surveyId === survey.id)
        .sort(
            (a,b) =>
                new Date(b.sendAt || 0) -
                new Date(a.sendAt || 0)
        );

    return `
        <div class="page-header">
            <div>
                <h1>顧客選択・メール送信</h1>
                <div class="subtitle">
                    対象アンケート：
                    <strong>${escapeHtml(survey.title)}</strong>
                </div>
            </div>

            <button data-back-list>一覧へ戻る</button>
        </div>

        <div class="tabs">
            <button
                class="tab ${state.sendTab === "customers" ? "active" : ""}"
                data-send-tab="customers"
            >顧客選択</button>

            <button
                class="tab ${state.sendTab === "mail" ? "active" : ""}"
                data-send-tab="mail"
            >メール作成</button>

            <button
                class="tab ${state.sendTab === "result" ? "active" : ""}"
                data-send-tab="result"
            >送信結果</button>

            <button
                class="tab ${state.sendTab === "history" ? "active" : ""}"
                data-send-tab="history"
            >送信履歴</button>
        </div>

        ${
            state.sendTab === "customers"
            ? renderCustomerSelection(survey, customers)
            : ""
        }

        ${
            state.sendTab === "mail"
            ? renderMailComposer(survey)
            : ""
        }

        ${
            state.sendTab === "result"
            ? renderSendResults()
            : ""
        }

        ${
            state.sendTab === "history"
            ? renderSendHistory(histories)
            : ""
        }
    `;
}

function filteredCustomers(surveyId) {
    let customers = [...state.customers];

    const q = state.customerSearch.trim().toLowerCase();

    if (q) {
        customers = customers.filter(c =>
            [
                c.name,
                c.organization,
                c.email,
                c.status
            ].join(" ").toLowerCase().includes(q)
        );
    }

    if (state.customerFilter !== "all") {
        customers = customers.filter(c =>
            statusText(c, surveyId) === state.customerFilter
        );
    }

    return customers;
}

function renderCustomerSelection(survey, customers) {
    return `
        <div class="card">
            <div class="toolbar" style="margin:0 0 15px">
                <input
                    id="customerSearch"
                    placeholder="顧客名・組織名・メールアドレス・ステータス"
                    value="${escapeHtml(state.customerSearch)}"
                    style="max-width:420px"
                >

                <select id="customerFilter" style="width:auto">
                    <option value="all">すべて</option>
                    <option value="未送信" ${state.customerFilter === "未送信" ? "selected" : ""}>
                        未送信
                    </option>
                    <option value="送信済み / 未回答" ${state.customerFilter === "送信済み / 未回答" ? "selected" : ""}>
                        送信済み / 未回答
                    </option>
                    <option value="回答済み" ${state.customerFilter === "回答済み" ? "selected" : ""}>
                        回答済み
                    </option>
                </select>

                <button data-customer-search>検索</button>

                <button data-select-reminder>
                    未回答者を選択
                </button>

                <button data-select-all>
                    表示中をすべて選択
                </button>

                <button data-clear-selection>
                    選択解除
                </button>
            </div>

            <div class="info-box">
                選択中：<strong>${state.selectedCustomerIds.size}</strong> 件
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" data-check-visible>
                            </th>
                            <th>組織名</th>
                            <th>氏名</th>
                            <th>メールアドレス</th>
                            <th>電話番号</th>
                            <th>住所</th>
                            <th>最終送信日時</th>
                            <th>送信回数</th>
                            <th>回答ステータス</th>
                            <th>kintone登録状態</th>
                        </tr>
                    </thead>

                    <tbody>
                        ${
                            customers.map(c => `
                                <tr>
                                    <td>
                                        <input
                                            type="checkbox"
                                            data-customer-check="${escapeHtml(c.id)}"
                                            ${state.selectedCustomerIds.has(c.id) ? "checked" : ""}
                                        >
                                    </td>
                                    <td>${escapeHtml(c.organization)}</td>
                                    <td>${escapeHtml(c.name)}</td>
                                    <td>${escapeHtml(c.email)}</td>
                                    <td>${escapeHtml(c.phone)}</td>
                                    <td>${escapeHtml(c.address)}</td>
                                    <td>${formatDate(c.lastSentAt)}</td>
                                    <td>${Number(c.sendCount || 0)}</td>
                                    <td>${escapeHtml(statusText(c, survey.id))}</td>
                                    <td>${escapeHtml(c.kintoneStatus || "")}</td>
                                </tr>
                            `).join("")
                        }
                    </tbody>
                </table>
            </div>

            <div class="sticky-actions">
                <button
                    class="primary"
                    data-go-mail
                    ${state.selectedCustomerIds.size ? "" : "disabled"}
                >
                    選択顧客のメールを作成
                </button>
            </div>
        </div>
    `;
}

function renderMailComposer(survey) {
    const selected = state.customers.filter(c =>
        state.selectedCustomerIds.has(c.id)
    );

    return `
        <div class="card">
            <h2>メール作成</h2>

            <div class="info-box">
                送信対象：${selected.length} 件
            </div>

            <div class="form-grid">
                <div class="field full">
                    <label>件名</label>
                    <input
                        id="sendSubject"
                        value="${escapeHtml(state.sendSubject)}"
                    >
                </div>

                <div class="field full">
                    <label>本文</label>
                    <textarea
                        id="sendBody"
                        style="min-height:250px"
                    >${escapeHtml(state.sendBody)}</textarea>

                    <div class="help">
                        利用可能な変数：
                        {顧客名} / {アンケートURL}
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <h3>送信文確認</h3>

            ${
                selected.length
                ? selected.map(customer => {
                    const token = "preview-" + customer.id;
                    const url = buildClientSurveyUrl(
                        survey.id,
                        token
                    );

                    const subject =
                        state.sendSubject
                            .replaceAll(
                                "{顧客名}",
                                customer.name || ""
                            )
                            .replaceAll(
                                "{アンケートURL}",
                                url
                            );

                    const body =
                        state.sendBody
                            .replaceAll(
                                "{顧客名}",
                                customer.name || ""
                            )
                            .replaceAll(
                                "{アンケートURL}",
                                url
                            );

                    return `
                        <div class="history-item">
                            <strong>
                                ${escapeHtml(customer.name)}
                            </strong>

                            <div class="muted">
                                ${escapeHtml(customer.email)}
                            </div>

                            <hr>

                            <div>
                                <strong>件名：</strong>
                                ${escapeHtml(subject)}
                            </div>

                            <pre style="white-space:pre-wrap;font-family:inherit">${escapeHtml(body)}</pre>
                        </div>
                    `;
                }).join("")
                : `
                    <div class="muted">
                        顧客を選択してください。
                    </div>
                `
            }
        </div>

        <div class="sticky-actions">
            <button data-send-tab="customers">
                顧客選択へ戻る
            </button>

            <button
                class="warning"
                data-resend
                ${selected.length ? "" : "disabled"}
            >
                再送
            </button>

            <button
                class="primary"
                data-bulk-send
                ${selected.length ? "" : "disabled"}
            >
                一括送信
            </button>
        </div>
    `;
}

function buildClientSurveyUrl(surveyId, token) {
    const base =
        window.location.origin +
        window.location.pathname;

    return (
        base +
        "?view=answer&survey=" +
        encodeURIComponent(surveyId) +
        "&token=" +
        encodeURIComponent(token)
    );
}

function renderSendResults() {
    const result = state.sendResults;

    if (!result) {
        return `
            <div class="card">
                <div class="info-box">
                    まだ送信結果はありません。
                </div>
            </div>
        `;
    }

    return `
        <div class="card">
            <h2>送信結果</h2>

            <div class="summary-grid">
                <div class="summary-card">
                    <div class="label">対象件数</div>
                    <div class="value">${result.target}</div>
                </div>

                <div class="summary-card">
                    <div class="label">成功件数</div>
                    <div class="value" style="color:#059669">
                        ${result.success}
                    </div>
                </div>

                <div class="summary-card">
                    <div class="label">失敗件数</div>
                    <div class="value" style="color:#dc2626">
                        ${result.failure}
                    </div>
                </div>

                <div class="summary-card">
                    <div class="label">送信日時</div>
                    <div class="value" style="font-size:15px">
                        ${formatDate(result.sentAt)}
                    </div>
                </div>
            </div>

            <div class="result-table table-wrap">
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
                            result.results.map(r => `
                                <tr>
                                    <td>${escapeHtml(r.customerName || "")}</td>
                                    <td>${escapeHtml(r.email || "")}</td>
                                    <td>
                                        ${
                                            r.success
                                            ? `<span class="status status-published">成功</span>`
                                            : `<span class="status status-stopped">失敗</span>`
                                        }
                                    </td>
                                    <td>
                                        ${
                                            r.success
                                            ? "送信しました。"
                                            : escapeHtml(r.error || "")
                                        }
                                    </td>
                                </tr>
                            `).join("")
                        }
                    </tbody>
                </table>
            </div>
        </div>
    `;
}

function renderSendHistory(histories) {
    return `
        <div class="card">
            <h2>送信履歴</h2>

            ${
                histories.length
                ? histories.map(h => `
                    <div class="history-item">
                        <div class="history-head">
                            <div>
                                <strong>
                                    ${escapeHtml(h.customerName || "")}
                                </strong>
                                <span class="muted">
                                    ${escapeHtml(h.email || "")}
                                </span>
                            </div>

                            <div>
                                ${escapeHtml(h.sendType || "")}
                                /
                                ${formatDate(h.sendAt)}
                            </div>
                        </div>

                        <div style="margin-top:8px">
                            <strong>件名：</strong>
                            ${escapeHtml(h.subject || "")}
                        </div>

                        <div style="margin-top:8px">
                            <strong>本文：</strong>
                            <pre style="white-space:pre-wrap;font-family:inherit">${escapeHtml(h.body || "")}</pre>
                        </div>

                        <div style="margin-top:8px">
                            <strong>個別アンケートURL：</strong>
                            <div style="word-break:break-all">
                                ${escapeHtml(h.surveyUrl || "")}
                            </div>
                        </div>

                        <div class="muted" style="margin-top:8px">
                            実行者：${escapeHtml(h.executedBy || "")}
                        </div>
                    </div>
                `).join("")
                : `
                    <div class="muted">
                        送信履歴はありません。
                    </div>
                `
            }
        </div>
    `;
}

/* ============================================================
 * 集計
 * ============================================================ */

function renderAggregate() {
    const survey = getSurvey(state.aggregateSurveyId);

    if (!survey) {
        return `
            <div class="card">
                <div class="error-box">
                    対象アンケートが指定されていません。
                </div>
                <button data-back-list>一覧へ戻る</button>
            </div>
        `;
    }

    const surveyResponses =
        state.responses.filter(
            r =>
                r.surveyId === survey.id &&
                r.status === "completed"
        );

    const sentCustomers =
        state.customers.filter(
            c =>
                Number(c.sendCount || 0) > 0 ||
                state.sendHistory.some(
                    h =>
                        h.surveyId === survey.id &&
                        h.customerId === c.id
                )
        );

    const registeredResponses =
        surveyResponses.filter(
            r => r.customerId
        );

    const unregisteredResponses =
        surveyResponses.filter(
            r => !r.customerId
        );

    const answeredCustomerIds = new Set(
        registeredResponses.map(r => r.customerId)
    );

    const unanswered =
        Math.max(
            0,
            sentCustomers.length -
            answeredCustomerIds.size
        );

    const rate =
        sentCustomers.length
        ? Math.round(
            surveyResponses.length /
            sentCustomers.length *
            100
        )
        : 0;

    return `
        <div class="page-header">
            <div>
                <h1>回答集計・分析</h1>
                <div class="subtitle">
                    対象アンケート：
                    <strong>${escapeHtml(survey.title)}</strong>
                </div>
            </div>

            <div class="page-actions">
                <button data-export-csv>CSV出力</button>
                <button data-export-pdf>PDF出力</button>
                <button data-back-list>一覧へ戻る</button>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="label">送信対象者数</div>
                <div class="value">${sentCustomers.length}</div>
            </div>

            <div class="summary-card">
                <div class="label">回答数</div>
                <div class="value">${surveyResponses.length}</div>
            </div>

            <div class="summary-card">
                <div class="label">未登録顧客からの回答数</div>
                <div class="value">${unregisteredResponses.length}</div>
            </div>

            <div class="summary-card">
                <div class="label">未回答数</div>
                <div class="value">${unanswered}</div>
            </div>

            <div class="summary-card">
                <div class="label">回答率</div>
                <div class="value">${rate}%</div>
            </div>
        </div>

        <div class="card" style="margin-top:16px">
            <div class="inline" style="justify-content:space-between">
                <h2>設問別集計</h2>

                <div class="inline">
                    <button data-select-all-questions>
                        すべて選択
                    </button>
                    <button data-clear-questions>
                        すべて解除
                    </button>
                </div>
            </div>

            <div style="margin-top:15px">
                ${
                    getQuestionIndexList(survey).map(q => `
                        <label class="checkbox" style="margin-bottom:8px">
                            <input
                                type="checkbox"
                                data-question-select="${escapeHtml(q.id)}"
                                ${state.selectedQuestions.size === 0 ||
                                  state.selectedQuestions.has(q.id)
                                  ? "checked"
                                  : ""}
                            >
                            ${escapeHtml(q.questionNumber)}
                            ${escapeHtml(q.text)}
                        </label>
                    `).join("")
                }
            </div>
        </div>

        ${
            getQuestionIndexList(survey)
                .filter(q =>
                    state.selectedQuestions.size === 0 ||
                    state.selectedQuestions.has(q.id)
                )
                .map(q =>
                    renderQuestionAggregate(
                        q,
                        surveyResponses
                    )
                ).join("")
        }

        <div class="card">
            <h2>個別回答</h2>

            ${
                surveyResponses.length
                ? `
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>回答日時</th>
                                    <th>回答者</th>
                                    <th>組織</th>
                                    <th>メール</th>
                                    <th>回答内容</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${
                                    surveyResponses.map(r => `
                                        <tr>
                                            <td>${formatDate(r.submittedAt)}</td>
                                            <td>${escapeHtml(r.respondent?.name || "")}</td>
                                            <td>${escapeHtml(r.respondent?.organization || "")}</td>
                                            <td>${escapeHtml(r.respondent?.email || "")}</td>
                                            <td>
                                                ${
                                                    Object.entries(r.answers || {})
                                                        .map(([qid, answer]) => {
                                                            const q = getQuestion(survey, qid);
                                                            if (!q) return "";

                                                            let text = "";

                                                            if (Array.isArray(answer)) {
                                                                text = answer.map(id => {
                                                                    const choice = findChoiceById(q, id);
                                                                    return choice?.label || id;
                                                                }).join(", ");
                                                            } else if (q.type === "single") {
                                                                text =
                                                                    findChoiceById(q, answer)?.label ||
                                                                    answer;
                                                            } else {
                                                                text = answer;
                                                            }

                                                            return `
                                                                <div>
                                                                    <strong>${escapeHtml(q.questionNumber)}</strong>
                                                                    ${escapeHtml(text)}
                                                                </div>
                                                            `;
                                                        }).join("")
                                                }
                                            </td>
                                        </tr>
                                    `).join("")
                                }
                            </tbody>
                        </table>
                    </div>
                `
                : `<div class="muted">回答データはありません。</div>`
            }
        </div>
    `;
}

function findChoiceById(question, choiceId) {
    return (question.choices || [])
        .find(c => c.id === choiceId) || null;
}

function renderQuestionAggregate(question, responses) {
    if (question.type === "text") {
        return `
            <div class="card">
                <h3>
                    ${escapeHtml(question.questionNumber)}
                    ${escapeHtml(question.text)}
                </h3>

                ${
                    responses.length
                    ? responses.map(r => `
                        <div class="history-item">
                            <strong>
                                ${escapeHtml(r.respondent?.name || "未登録回答者")}
                            </strong>

                            <div class="muted">
                                ${escapeHtml(r.respondent?.organization || "")}
                                /
                                ${escapeHtml(r.respondent?.email || "")}
                            </div>

                            <div style="margin-top:8px;white-space:pre-wrap">
                                ${escapeHtml(r.answers?.[question.id] || "")}
                            </div>
                        </div>
                    `).join("")
                    : `<div class="muted">回答はありません。</div>`
                }
            </div>
        `;
    }

    const counts = {};

    for (const choice of question.choices || []) {
        counts[choice.id] = 0;
    }

    for (const response of responses) {
        const answer = response.answers?.[question.id];

        if (Array.isArray(answer)) {
            for (const value of answer) {
                if (counts[value] !== undefined) {
                    counts[value]++;
                }
            }
        } else if (answer && counts[answer] !== undefined) {
            counts[answer]++;
        }
    }

    const total = responses.length;

    return `
        <div class="card">
            <h3>
                ${escapeHtml(question.questionNumber)}
                ${escapeHtml(question.text)}
            </h3>

            ${
                (question.choices || []).map(choice => {
                    const count = counts[choice.id] || 0;
                    const percent = total
                        ? Math.round(count / total * 100)
                        : 0;

                    return `
                        <div class="bar-row">
                            <div>
                                ${escapeHtml(choice.label)}
                            </div>

                            <div class="bar-bg">
                                <div
                                    class="bar"
                                    style="width:${percent}%"
                                ></div>
                            </div>

                            <div class="right">
                                ${count}件 / ${percent}%
                            </div>
                        </div>
                    `;
                }).join("")
            }
        </div>
    `;
}

/* ============================================================
 * kintone設定
 * ============================================================ */

function renderKintone() {
    const k = state.kintone || {};

    const fields = k.fields || [];
    const mapping = k.mapping || {};

    return `
        <div class="page-header">
            <div>
                <h1>kintone連携設定</h1>
                <div class="subtitle">
                    接続テスト・項目取得・顧客同期は独立した操作です。
                </div>
            </div>
        </div>

        <div class="card">
            <h2>接続設定</h2>

            <div class="form-grid">
                <div class="field full">
                    <label>サブドメイン</label>
                    <input
                        id="kSubdomain"
                        placeholder="xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com"
                        value="${escapeHtml(k.subdomain || "")}"
                    >
                    <div class="help">
                        xxxx、xxxx.cybozu.com、https://xxxx.cybozu.com の3形式を入力できます。
                        保存時にはxxxxの形式に正規化します。
                    </div>
                </div>

                <div class="field">
                    <label>顧客管理アプリID</label>
                    <input
                        id="kAppId"
                        value="${escapeHtml(k.appId || "")}"
                    >
                </div>

                <div class="field">
                    <label>ログイン名</label>
                    <input
                        id="kLoginName"
                        autocomplete="off"
                        value="${escapeHtml(k.loginName || "")}"
                    >
                </div>

                <div class="field">
                    <label>パスワード</label>
                    <input
                        id="kPassword"
                        type="password"
                        autocomplete="new-password"
                        placeholder="${k.passwordConfigured ? "設定済み。変更する場合のみ入力" : ""}"
                    >
                </div>

                <div class="field">
                    <label>SSL証明書検証</label>
                    <select id="kSslVerify">
                        <option
                            value="false"
                            ${k.sslVerify ? "" : "selected"}
                        >検証しない</option>
                        <option
                            value="true"
                            ${k.sslVerify ? "selected" : ""}
                        >検証する</option>
                    </select>
                </div>

                <div class="field full">
                    <label>プロキシ</label>
                    <input
                        id="kProxy"
                        placeholder="proxy.example.local:8080"
                        value="${escapeHtml(k.proxy || "")}"
                    >
                    <div class="help">
                        host:port形式。プロキシ認証は行いません。
                    </div>
                </div>
            </div>

            <div class="page-actions" style="margin-top:15px">
                <button class="primary" data-save-kintone>
                    設定を保存
                </button>

                <button data-kintone-test>
                    接続テスト
                </button>

                <button data-kintone-fields>
                    項目一覧を再取得
                </button>

                <button data-kintone-sync>
                    顧客情報を同期
                </button>
            </div>

            <div id="kintoneMessage" style="margin-top:15px"></div>
        </div>

        <div class="card">
            <h2>kintoneフィールドマッピング</h2>

            ${
                fields.length
                ? renderKintoneMapping(fields, mapping)
                : `
                    <div class="info-box">
                        「項目一覧を再取得」でkintoneのフィールド一覧を取得してください。
                    </div>
                `
            }
        </div>
    `;
}

function renderKintoneMapping(fields, mapping) {
    const option = (selected) => `
        <option value="">未設定</option>
        ${
            fields.map(f => `
                <option
                    value="${escapeHtml(f.code)}"
                    ${selected === f.code ? "selected" : ""}
                >
                    ${escapeHtml(f.label)}
                    (${escapeHtml(f.code)})
                </option>
            `).join("")
        }
    `;

    return `
        <div class="form-grid">
            <div class="field">
                <label>組織名</label>
                <select data-map="organization">
                    ${option(mapping.organization || "")}
                </select>
            </div>

            <div class="field">
                <label>氏名</label>
                <select data-map="name">
                    ${option(mapping.name || "")}
                </select>
            </div>

            <div class="field">
                <label>メールアドレス</label>
                <select data-map="email">
                    ${option(mapping.email || "")}
                </select>
            </div>

            <div class="field">
                <label>部署名</label>
                <select data-map="department">
                    ${option(mapping.department || "")}
                </select>
            </div>

            <div class="field">
                <label>電話番号</label>
                <select data-map="phone">
                    ${option(mapping.phone || "")}
                </select>
            </div>

            <div class="field full">
                <label>住所（複数選択可）</label>

                <div class="kintone-field-list">
                    ${
                        fields.map(f => `
                            <label class="checkbox field-item">
                                <input
                                    type="checkbox"
                                    data-map-address="${escapeHtml(f.code)}"
                                    ${
                                        (mapping.address || [])
                                            .includes(f.code)
                                        ? "checked"
                                        : ""
                                    }
                                >
                                ${escapeHtml(f.label)}
                                (${escapeHtml(f.code)})
                            </label>
                        `).join("")
                    }
                </div>
            </div>
        </div>
    `;
}

/* ============================================================
 * メール設定
 * ============================================================ */

function renderMail() {
    const m = state.mail || {};

    return `
        <div class="page-header">
            <div>
                <h1>メールサーバ設定</h1>
                <div class="subtitle">
                    実際のSMTP通信を使用します。
                </div>
            </div>
        </div>

        <div class="card">
            <h2>SMTP設定</h2>

            <div class="form-grid">
                <div class="field">
                    <label>SMTPサーバ</label>
                    <input
                        id="mServer"
                        value="${escapeHtml(m.smtpServer || "")}"
                    >
                </div>

                <div class="field">
                    <label>SMTPポート</label>
                    <input
                        type="number"
                        id="mPort"
                        value="${Number(m.smtpPort || 587)}"
                    >
                </div>

                <div class="field">
                    <label>暗号化方式</label>
                    <select id="mEncryption">
                        <option value="none" ${m.encryption === "none" ? "selected" : ""}>
                            なし
                        </option>
                        <option value="starttls" ${m.encryption === "starttls" ? "selected" : ""}>
                            STARTTLS
                        </option>
                        <option value="ssl" ${m.encryption === "ssl" ? "selected" : ""}>
                            SSL/TLS
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>SMTP認証</label>
                    <select id="mAuth">
                        <option value="true" ${m.authentication !== false ? "selected" : ""}>
                            使用する
                        </option>
                        <option value="false" ${m.authentication === false ? "selected" : ""}>
                            使用しない
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>SMTPユーザー名</label>
                    <input
                        id="mUsername"
                        autocomplete="off"
                        value="${escapeHtml(m.username || "")}"
                    >
                </div>

                <div class="field">
                    <label>SMTPパスワード</label>
                    <input
                        id="mPassword"
                        type="password"
                        autocomplete="new-password"
                        placeholder="${m.passwordConfigured ? "設定済み。変更する場合のみ入力" : ""}"
                    >
                </div>

                <div class="field">
                    <label>送信元メールアドレス</label>
                    <input
                        id="mFromEmail"
                        type="email"
                        value="${escapeHtml(m.fromEmail || "")}"
                    >
                </div>

                <div class="field">
                    <label>送信元名</label>
                    <input
                        id="mFromName"
                        value="${escapeHtml(m.fromName || "")}"
                    >
                </div>

                <div class="field">
                    <label>返信先メールアドレス</label>
                    <input
                        id="mReplyTo"
                        type="email"
                        value="${escapeHtml(m.replyTo || "")}"
                    >
                </div>

                <div class="field">
                    <label>接続状態</label>
                    <input
                        value="${escapeHtml(m.connectionStatus || "未設定")}"
                        disabled
                    >
                </div>
            </div>

            <div class="page-actions" style="margin-top:15px">
                <button class="primary" data-save-mail>
                    設定を保存
                </button>
            </div>
        </div>

        <div class="card">
            <h2>テストメール</h2>

            <div class="form-grid">
                <div class="field">
                    <label>テスト送信先</label>
                    <input
                        id="mTestEmail"
                        type="email"
                        placeholder="test@example.com"
                    >
                </div>
            </div>

            <div class="page-actions" style="margin-top:15px">
                <button data-mail-test>
                    テストメールを送信
                </button>
            </div>

            <div id="mailMessage" style="margin-top:15px"></div>
        </div>
    `;
}

/* ============================================================
 * 回答者
 * ============================================================ */

function renderAnswer() {
    const survey = getSurvey(state.answerSurveyId);

    if (!survey) {
        return `
            <div class="answer-shell">
                <div class="answer-container">
                    <div class="card">
                        <div class="error-box">
                            アンケートが存在しません。
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    if (survey.status !== "published") {
        return `
            <div class="answer-shell">
                <div class="answer-header">
                    <strong>${escapeHtml(survey.title)}</strong>
                </div>
                <div class="answer-container">
                    <div class="card">
                        <h2>現在回答できません</h2>
                        <p>
                            このアンケートは現在公開されていません。
                        </p>
                    </div>
                </div>
            </div>
        `;
    }

    const visibleIds =
        visibleQuestionIds(
            survey,
            state.answers
        );

    const questions =
        visibleIds
            .map(id => getQuestion(survey, id))
            .filter(Boolean);

    const pageSize = 3;

    const start =
        state.answerStep * pageSize;

    const current =
        questions.slice(start, start + pageSize);

    const hasNext =
        start + pageSize < questions.length;

    return `
        <div class="answer-shell">
            <div class="answer-header">
                <div class="answer-container" style="padding:0">
                    <strong>${escapeHtml(survey.title)}</strong>
                </div>
            </div>

            <div class="answer-container">
                ${
                    survey.description
                    ? `
                        <div class="card">
                            ${escapeHtml(survey.description)}
                        </div>
                    `
                    : ""
                }

                <div id="answerErrors"></div>

                ${
                    current.map(
                        q => renderAnswerQuestion(q)
                    ).join("")
                }

                <div class="answer-nav">
                    <button
                        data-answer-back
                        ${state.answerStep === 0 ? "disabled" : ""}
                    >
                        ← 戻る
                    </button>

                    ${
                        hasNext
                        ? `
                            <button
                                class="primary"
                                data-answer-next
                            >
                                次へ →
                            </button>
                        `
                        : `
                            <button
                                class="primary"
                                data-answer-confirm
                            >
                                回答内容を確認 →
                            </button>
                        `
                    }
                </div>
            </div>
        </div>
    `;
}

function renderAnswerQuestion(question) {
    const answer =
        state.answers[question.id];

    return `
        <div
            class="answer-question ${question.required ? "required" : ""}"
            data-answer-question="${escapeHtml(question.id)}"
        >
            <div class="q-label">
                ${escapeHtml(question.questionNumber)}
                ${escapeHtml(question.text)}
            </div>

            ${
                question.type === "single"
                ? (question.choices || []).map(choice => `
                    <label class="option">
                        <input
                            type="radio"
                            name="answer_${escapeHtml(question.id)}"
                            value="${escapeHtml(choice.id)}"
                            ${answer === choice.id ? "checked" : ""}
                            data-answer-input
                            data-question-id="${escapeHtml(question.id)}"
                        >
                        <span>${escapeHtml(choice.label)}</span>
                    </label>
                `).join("")
                : ""
            }

            ${
                question.type === "multiple"
                ? (question.choices || []).map(choice => `
                    <label class="option">
                        <input
                            type="checkbox"
                            value="${escapeHtml(choice.id)}"
                            ${
                                Array.isArray(answer) &&
                                answer.includes(choice.id)
                                ? "checked"
                                : ""
                            }
                            data-answer-input
                            data-question-id="${escapeHtml(question.id)}"
                        >
                        <span>${escapeHtml(choice.label)}</span>
                    </label>
                `).join("")
                : ""
            }

            ${
                question.type === "text"
                ? `
                    <textarea
                        data-answer-input
                        data-question-id="${escapeHtml(question.id)}"
                        placeholder="回答を入力してください"
                    >${escapeHtml(answer || "")}</textarea>
                `
                : ""
            }
        </div>
    `;
}

function renderConfirm() {
    const survey = getSurvey(state.answerSurveyId);

    if (!survey) {
        return "";
    }

    const visibleIds =
        visibleQuestionIds(
            survey,
            state.answers
        );

    return `
        <div class="answer-shell">
            <div class="answer-header">
                <div class="answer-container" style="padding:0">
                    <strong>${escapeHtml(survey.title)}</strong>
                </div>
            </div>

            <div class="answer-container">
                <div class="card">
                    <h1>回答内容の確認</h1>
                    <p class="muted">
                        内容を確認し、問題なければ送信してください。
                    </p>
                </div>

                ${
                    visibleIds.map(id => {
                        const q = getQuestion(survey, id);
                        if (!q) return "";

                        let value = state.answers[id];

                        if (Array.isArray(value)) {
                            value = value.map(choiceId => {
                                return findChoiceById(q, choiceId)?.label ||
                                    choiceId;
                            }).join("、");
                        } else if (q.type === "single") {
                            value =
                                findChoiceById(q, value)?.label ||
                                value ||
                                "未回答";
                        }

                        return `
                            <div class="answer-question">
                                <div class="q-label">
                                    ${escapeHtml(q.questionNumber)}
                                    ${escapeHtml(q.text)}
                                </div>

                                <div style="white-space:pre-wrap">
                                    ${escapeHtml(value || "未回答")}
                                </div>

                                <div style="margin-top:10px">
                                    <button
                                        data-edit-answer="${escapeHtml(q.id)}"
                                    >
                                        修正
                                    </button>
                                </div>
                            </div>
                        `;
                    }).join("")
                }

                <div class="answer-nav">
                    <button data-confirm-back>
                        ← 回答入力へ戻る
                    </button>

                    <button
                        class="primary"
                        data-submit-response
                    >
                        回答を送信する
                    </button>
                </div>
            </div>
        </div>
    `;
}

function renderComplete() {
    const survey = getSurvey(state.answerSurveyId);

    return `
        <div class="answer-shell">
            <div class="answer-container">
                <div class="card" style="text-align:center;padding:40px 20px">
                    <h1>回答ありがとうございました</h1>

                    <p>
                        「${escapeHtml(survey?.title || "アンケート")}」
                        の回答を受け付けました。
                    </p>

                    <p class="muted">
                        この画面から管理者向けの業務へ移動することはできません。
                    </p>
                </div>
            </div>
        </div>
    `;
}

/* ============================================================
 * render
 * ============================================================ */

function render() {
    const app = document.getElementById("app");

    if (
        state.screen === "answer" ||
        state.screen === "confirm" ||
        state.screen === "complete"
    ) {
        app.innerHTML =
            state.screen === "answer"
            ? renderAnswer()
            : state.screen === "confirm"
                ? renderConfirm()
                : renderComplete();

        bindEvents();
        return;
    }

    let content = "";

    switch (state.screen) {
        case "list":
            content = renderList();
            break;

        case "edit":
            content = renderEdit();
            break;

        case "preview":
            content = renderPreview();
            break;

        case "send":
            content = renderSend();
            break;

        case "aggregate":
            content = renderAggregate();
            break;

        case "kintone":
            content = renderKintone();
            break;

        case "mail":
            content = renderMail();
            break;

        default:
            state.screen = "list";
            content = renderList();
    }

    app.innerHTML =
        adminHeader() +
        `<main>${content}</main>`;

    bindEvents();
}

/* ============================================================
 * 編集イベント
 * ============================================================ */

function syncEditorBasicValues() {
    if (!state.editorDraft) return;

    state.editorDraft.title =
        document.getElementById("editTitle")?.value || "";

    state.editorDraft.description =
        document.getElementById("editDescription")?.value || "";

    state.editorDraft.startDate =
        fromDateTimeLocal(
            document.getElementById("editStartDate")?.value || ""
        );

    state.editorDraft.endDate =
        fromDateTimeLocal(
            document.getElementById("editEndDate")?.value || ""
        );

    state.editorDraft.questionNumberMode =
        document.getElementById("editNumberMode")?.value || "all";

    state.editorDraft.allowResubmission =
        document.getElementById("editResubmission")?.checked ||
        false;

    document.querySelectorAll("[data-group-title]")
        .forEach(input => {
            const id = input.dataset.groupTitle;
            const group = state.editorDraft.groups
                .find(g => g.id === id);

            if (group) {
                group.title = input.value;
            }
        });

    document.querySelectorAll("[data-question-text]")
        .forEach(input => {
            const id = input.dataset.questionText;
            const question =
                getQuestion(state.editorDraft, id);

            if (question) {
                question.text = input.value;
            }
        });

    document.querySelectorAll("[data-question-type]")
        .forEach(select => {
            const id = select.dataset.questionType;
            const question =
                getQuestion(state.editorDraft, id);

            if (question) {
                question.type = select.value;

                if (question.type === "text") {
                    question.choices = [];
                    question.branches = [];
                }
            }
        });

    document.querySelectorAll("[data-question-required]")
        .forEach(input => {
            const id = input.dataset.questionRequired;
            const question =
                getQuestion(state.editorDraft, id);

            if (question) {
                question.required = input.checked;
            }
        });

    document.querySelectorAll("[data-choice-label]")
        .forEach(input => {
            const choiceId = input.dataset.choiceLabel;
            const questionId = input.dataset.choiceQuestion;

            const question =
                getQuestion(
                    state.editorDraft,
                    questionId
                );

            if (!question) return;

            const choice =
                (question.choices || [])
                    .find(c => c.id === choiceId);

            if (choice) {
                choice.label = input.value;
            }
        });

    document.querySelectorAll("[data-branch-next]")
        .forEach(select => {
            const questionId =
                select.dataset.branchQuestion;

            const choiceId =
                select.dataset.branchChoice;

            const question =
                getQuestion(
                    state.editorDraft,
                    questionId
                );

            if (!question) return;

            question.branches =
                (question.branches || [])
                    .filter(
                        b => b.choiceId !== choiceId
                    );

            if (select.value) {
                question.branches.push({
                    choiceId,
                    nextQuestionId: select.value
                });
            }
        });

    recalcDraftNumbers(state.editorDraft);
}

/* ============================================================
 * D&D
 * ============================================================ */

let draggedGroupId = null;
let draggedQuestionId = null;

function bindDragDrop() {
    document.querySelectorAll("[data-group-id]")
        .forEach(el => {
            el.addEventListener("dragstart", e => {
                draggedGroupId =
                    el.dataset.groupId;

                e.dataTransfer.effectAllowed =
                    "move";

                el.classList.add("dragging");
            });

            el.addEventListener("dragend", () => {
                el.classList.remove("dragging");
                draggedGroupId = null;
            });

            el.addEventListener("dragover", e => {
                e.preventDefault();
            });

            el.addEventListener("drop", e => {
                e.preventDefault();

                if (!state.editorDraft ||
                    !draggedGroupId
                ) return;

                if (
                    draggedGroupId ===
                    el.dataset.groupId
                ) return;

                syncEditorBasicValues();

                const groups =
                    state.editorDraft.groups;

                const from =
                    groups.findIndex(
                        g => g.id === draggedGroupId
                    );

                const to =
                    groups.findIndex(
                        g => g.id === el.dataset.groupId
                    );

                if (from < 0 || to < 0) return;

                const [item] =
                    groups.splice(from, 1);

                groups.splice(to, 0, item);

                recalcDraftNumbers(
                    state.editorDraft
                );

                render();
            });
        });

    document.querySelectorAll("[data-question-id]")
        .forEach(el => {
            el.addEventListener("dragstart", e => {
                draggedQuestionId =
                    el.dataset.questionId;

                e.dataTransfer.effectAllowed =
                    "move";

                el.classList.add("dragging");
            });

            el.addEventListener("dragend", () => {
                el.classList.remove("dragging");
                draggedQuestionId = null;
            });

            el.addEventListener("dragover", e => {
                e.preventDefault();
            });

            el.addEventListener("drop", e => {
                e.preventDefault();

                if (
                    !state.editorDraft ||
                    !draggedQuestionId ||
                    draggedQuestionId ===
                        el.dataset.questionId
                ) return;

                syncEditorBasicValues();

                moveQuestion(
                    draggedQuestionId,
                    el.dataset.questionId
                );

                render();
            });
        });
}

function moveQuestion(questionId, targetQuestionId) {
    let sourceGroup = null;
    let sourceIndex = -1;
    let targetGroup = null;
    let targetIndex = -1;

    for (const group of state.editorDraft.groups) {
        const index =
            group.questions.findIndex(
                q => q.id === questionId
            );

        if (index >= 0) {
            sourceGroup = group;
            sourceIndex = index;
        }

        const tIndex =
            group.questions.findIndex(
                q => q.id === targetQuestionId
            );

        if (tIndex >= 0) {
            targetGroup = group;
            targetIndex = tIndex;
        }
    }

    if (
        !sourceGroup ||
        !targetGroup ||
        sourceIndex < 0 ||
        targetIndex < 0
    ) {
        return;
    }

    const [question] =
        sourceGroup.questions.splice(
            sourceIndex,
            1
        );

    question.groupId = targetGroup.id;

    targetGroup.questions.splice(
        targetIndex,
        0,
        question
    );

    recalcDraftNumbers(
        state.editorDraft
    );
}

/* ============================================================
 * bindEvents
 * ============================================================ */

function bindEvents() {
    document.querySelectorAll("[data-nav]")
        .forEach(button => {
            button.addEventListener("click", () => {
                const target =
                    button.dataset.nav;

                if (target === "list") {
                    state.editSurveyId = null;
                    go("list");
                } else if (target === "kintone") {
                    go("kintone");
                } else if (target === "mail") {
                    go("mail");
                }
            });
        });

    document.querySelector("[data-ui-logout]")
        ?.addEventListener("click", async () => {
            state.editSurveyId = null;
            state.aggregateSurveyId = null;
            state.sendSurveyId = null;
            state.selectedCustomerIds.clear();
            state.answers = {};
            state.answerToken = null;
            state.answerSurveyId = null;
            state.sendResults = null;

            toast(
                "画面状態をリセットしました。",
                "success"
            );

            go("list");
        });

    document.querySelector("[data-create-survey]")
        ?.addEventListener("click", () => {
            state.editorDraft = blankSurvey();
            state.editorDraft.groups.push(
                createGroup()
            );
            go("edit");
        });

    document.querySelector("[data-search-survey]")
        ?.addEventListener("click", () => {
            state.listSearch =
                document.getElementById("surveySearch")?.value ||
                "";
            state.listFilter =
                document.getElementById("surveyFilter")?.value ||
                "all";
            state.listSort =
                document.getElementById("surveySort")?.value ||
                "updatedDesc";

            render();
        });

    document.getElementById("surveySearch")
        ?.addEventListener("keydown", e => {
            if (e.key === "Enter") {
                state.listSearch =
                    e.target.value;

                state.listFilter =
                    document.getElementById("surveyFilter")?.value ||
                    "all";

                state.listSort =
                    document.getElementById("surveySort")?.value ||
                    "updatedDesc";

                render();
            }
        });

    document.querySelectorAll("[data-edit]")
        .forEach(button => {
            button.addEventListener("click", () => {
                const survey =
                    getSurvey(button.dataset.edit);

                if (!survey) return;

                state.editSurveyId =
                    survey.id;

                state.editorDraft =
                    JSON.parse(
                        JSON.stringify(survey)
                    );

                go("edit");
            });
        });

    document.querySelectorAll("[data-aggregate]")
        .forEach(button => {
            button.addEventListener("click", () => {
                const id =
                    button.dataset.aggregate;

                if (!getSurvey(id)) {
                    toast(
                        "対象アンケートが存在しません。",
                        "error"
                    );
                    return;
                }

                state.aggregateSurveyId = id;
                state.selectedQuestions.clear();

                go("aggregate");
            });
        });

    document.querySelectorAll("[data-send]")
        .forEach(button => {
            button.addEventListener("click", () => {
                const id =
                    button.dataset.send;

                if (!getSurvey(id)) {
                    toast(
                        "対象アンケートが存在しません。",
                        "error"
                    );
                    return;
                }

                state.sendSurveyId = id;
                state.selectedCustomerIds.clear();
                state.sendResults = null;
                state.sendTab = "customers";
                go("send");
            });
        });

    document.querySelectorAll("[data-delete]")
        .forEach(button => {
            button.addEventListener("click", async () => {
                const survey =
                    getSurvey(button.dataset.delete);

                if (!survey) return;

                const ok =
                    await confirmModal(
                        "アンケート削除",
                        `「${survey.title}」を削除しますか？`
                    );

                if (!ok) return;

                try {
                    const data =
                        await api(
                            "delete_survey",
                            {id: survey.id}
                        );

                    state.surveys =
                        data.surveys || [];

                    toast(
                        "アンケートを削除しました。",
                        "success"
                    );

                    render();
                } catch (e) {
                    showError(e);
                }
            });
        });

    document.querySelectorAll("[data-duplicate]")
        .forEach(button => {
            button.addEventListener("click", async () => {
                const survey =
                    getSurvey(button.dataset.duplicate);

                if (!survey) return;

                const ok =
                    await confirmModal(
                        "アンケート複製",
                        `「${survey.title}」を複製しますか？`
                    );

                if (!ok) return;

                try {
                    const data =
                        await api(
                            "duplicate_survey",
                            {id: survey.id}
                        );

                    state.surveys =
                        data.surveys || [];

                    toast(
                        "アンケートを複製しました。",
                        "success"
                    );

                    render();
                } catch (e) {
                    showError(e);
                }
            });
        });

    /* 編集 */
    document.querySelectorAll("[data-edit-cancel]")
        .forEach(button => {
            button.addEventListener("click", async () => {
                const ok =
                    await confirmModal(
                        "編集内容の破棄",
                        "編集内容を破棄して前画面へ戻りますか？"
                    );

                if (!ok) return;

                state.editorDraft = null;
                go("list");
            });
        });

    document.querySelector("[data-preview-edit]")
        ?.addEventListener("click", () => {
            syncEditorBasicValues();
            state.previewMode = "pc";
            go("preview");
        });

    document.querySelectorAll("[data-preview-mode]")
        .forEach(button => {
            button.addEventListener("click", () => {
                state.previewMode =
                    button.dataset.previewMode;

                render();
            });
        });

    document.querySelector("[data-preview-back]")
        ?.addEventListener("click", () => {
            go("edit");
        });

    document.querySelector("[data-add-group]")
        ?.addEventListener("click", () => {
            syncEditorBasicValues();

            const group =
                createGroup();

            group.sortOrder =
                state.editorDraft.groups.length + 1;

            state.editorDraft.groups.push(group);

            recalcDraftNumbers(
                state.editorDraft
            );

            render();
        });

    document.querySelectorAll("[data-delete-group]")
        .forEach(button => {
            button.addEventListener("click", async () => {
                syncEditorBasicValues();

                const group =
                    state.editorDraft.groups
                        .find(
                            g =>
                                g.id ===
                                button.dataset.deleteGroup
                        );

                if (!group) return;

                const message =
                    group.questions.length
                    ? "質問が存在するグループです。削除しますか？"
                    : "このグループを削除しますか？";

                const ok =
                    await confirmModal(
                        "グループ削除",
                        message
                    );

                if (!ok) return;

                state.editorDraft.groups =
                    state.editorDraft.groups.filter(
                        g =>
                            g.id !==
                            button.dataset.deleteGroup
                    );

                recalcDraftNumbers(
                    state.editorDraft
                );

                render();
            });
        });

    document.querySelectorAll("[data-add-question]")
        .forEach(button => {
            button.addEventListener("click", () => {
                syncEditorBasicValues();

                const group =
                    state.editorDraft.groups.find(
                        g =>
                            g.id ===
                            button.dataset.addQuestion
                    );

                if (!group) return;

                const q =
                    createQuestion(group.id);

                q.sortOrder =
                    group.questions.length + 1;

                group.questions.push(q);

                recalcDraftNumbers(
                    state.editorDraft
                );

                render();
            });
        });

    document.querySelectorAll("[data-delete-question]")
        .forEach(button => {
            button.addEventListener("click", async () => {
                syncEditorBasicValues();

                const id =
                    button.dataset.deleteQuestion;

                const question =
                    getQuestion(
                        state.editorDraft,
                        id
                    );

                if (!question) return;

                const ok =
                    await confirmModal(
                        "質問削除",
                        `「${question.questionNumber} ${question.text}」を削除しますか？`
                    );

                if (!ok) return;

                for (
                    const group
                    of state.editorDraft.groups
                ) {
                    group.questions =
                        group.questions.filter(
                            q => q.id !== id
                        );

                    group.questions.forEach(
                        q => {
                            q.branches =
                                (q.branches || [])
                                    .filter(
                                        b =>
                                            b.nextQuestionId !== id
                                    );
                        }
                    );
                }

                recalcDraftNumbers(
                    state.editorDraft
                );

                render();
            });
        });

    document.querySelectorAll("[data-add-choice]")
        .forEach(button => {
            button.addEventListener("click", () => {
                syncEditorBasicValues();

                const question =
                    getQuestion(
                        state.editorDraft,
                        button.dataset.addChoice
                    );

                if (!question) return;

                question.choices.push({
                    id: crypto.randomUUID(),
                    label: "",
                    sortOrder:
                        question.choices.length + 1
                });

                render();
            });
        });

    document.querySelectorAll("[data-delete-choice]")
        .forEach(button => {
            button.addEventListener("click", () => {
                syncEditorBasicValues();

                const question =
                    getQuestion(
                        state.editorDraft,
                        button.dataset.choiceQuestion
                    );

                if (!question) return;

                const choiceId =
                    button.dataset.deleteChoice;

                question.choices =
                    question.choices.filter(
                        c => c.id !== choiceId
                    );

                question.branches =
                    (question.branches || [])
                        .filter(
                            b =>
                                b.choiceId !==
                                choiceId
                        );

                question.choices.forEach(
                    (c, index) => {
                        c.sortOrder = index + 1;
                    }
                );

                render();
            });
        });

    document.querySelectorAll("[data-clear-branch]")
        .forEach(button => {
            button.addEventListener("click", () => {
                syncEditorBasicValues();

                const question =
                    getQuestion(
                        state.editorDraft,
                        button.dataset.branchQuestion
                    );

                if (!question) return;

                question.branches =
                    (question.branches || [])
                        .filter(
                            b =>
                                b.choiceId !==
                                button.dataset.branchChoice
                        );

                render();
            });
        });

    document.getElementById("editNumberMode")
        ?.addEventListener("change", () => {
            syncEditorBasicValues();
            recalcDraftNumbers(
                state.editorDraft
            );
            render();
        });

    document.querySelectorAll("[data-save-survey]")
        .forEach(button => {
            button.addEventListener("click", async () => {
                syncEditorBasicValues();

                if (
                    !state.editorDraft.title.trim()
                ) {
                    toast(
                        "タイトルを入力してください。",
                        "error"
                    );
                    return;
                }

                try {
                    const data =
                        await api(
                            "save_survey",
                            state.editorDraft
                        );

                    state.surveys =
                        data.surveys || [];

                    state.editorDraft = null;

                    toast(
                        "アンケートを保存しました。",
                        "success"
                    );

                    go("list");
                } catch (e) {
                    showError(e);
                }
            });
        });

    document.getElementById("editStatus")
        ?.addEventListener("change", async e => {
            const newStatus =
                e.target.value;

            const oldStatus =
                state.editorDraft.status;

            if (newStatus === oldStatus) {
                return;
            }

            const messages = {
                published:
                    "このアンケートを公開しますか？",
                stopped:
                    "このアンケートを停止しますか？",
                draft:
                    "下書きへ変更しますか？"
            };

            const ok =
                await confirmModal(
                    "状態変更",
                    messages[newStatus] ||
                    "状態を変更しますか？"
                );

            if (!ok) {
                e.target.value = oldStatus;
                return;
            }

            try {
                if (!state.editorDraft.id) {
                    e.target.value = oldStatus;

                    toast(
                        "新規作成したアンケートは、先に保存してください。",
                        "error"
                    );

                    return;
                }

                const data =
                    await api(
                        "change_status",
                        {
                            id: state.editorDraft.id,
                            status: newStatus
                        }
                    );

                state.editorDraft.status =
                    data.survey.status;

                const index =
                    state.surveys.findIndex(
                        s =>
                            s.id ===
                            data.survey.id
                    );

                if (index >= 0) {
                    state.surveys[index] =
                        data.survey;
                }

                render();

                toast(
                    "状態を変更しました。",
                    "success"
                );
            } catch (e) {
                e.target.value = oldStatus;
                showError(e);
            }
        });

    bindDragDrop();

    /* 顧客送信 */
    document.querySelectorAll("[data-send-tab]")
        .forEach(button => {
            button.addEventListener("click", () => {
                state.sendTab =
                    button.dataset.sendTab;

                if (
                    state.sendTab === "mail"
                ) {
                    state.sendSubject =
                        document.getElementById("sendSubject")
                            ?.value ||
                        state.sendSubject;

                    state.sendBody =
                        document.getElementById("sendBody")
                            ?.value ||
                        state.sendBody;
                }

                render();
            });
        });

    document.querySelector("[data-back-list]")
        ?.addEventListener("click", () => {
            go("list");
        });

    document.querySelector("[data-customer-search]")
        ?.addEventListener("click", () => {
            state.customerSearch =
                document.getElementById("customerSearch")
                    ?.value || "";

            state.customerFilter =
                document.getElementById("customerFilter")
                    ?.value || "all";

            render();
        });

    document.getElementById("customerSearch")
        ?.addEventListener("keydown", e => {
            if (e.key === "Enter") {
                state.customerSearch =
                    e.target.value;

                state.customerFilter =
                    document.getElementById("customerFilter")
                        ?.value || "all";

                render();
            }
        });

    document.querySelectorAll("[data-customer-check]")
        .forEach(input => {
            input.addEventListener("change", () => {
                if (input.checked) {
                    state.selectedCustomerIds.add(
                        input.dataset.customerCheck
                    );
                } else {
                    state.selectedCustomerIds.delete(
                        input.dataset.customerCheck
                    );
                }

                render();
            });
        });

    document.querySelector("[data-select-all]")
        ?.addEventListener("click", () => {
            const survey =
                getSurvey(state.sendSurveyId);

            filteredCustomers(survey.id)
                .forEach(c =>
                    state.selectedCustomerIds.add(c.id)
                );

            render();
        });

    document.querySelector("[data-clear-selection]")
        ?.addEventListener("click", () => {
            state.selectedCustomerIds.clear();
            render();
        });

    document.querySelector("[data-check-visible]")
        ?.addEventListener("change", e => {
            const survey =
                getSurvey(state.sendSurveyId);

            filteredCustomers(survey.id)
                .forEach(c => {
                    if (e.target.checked) {
                        state.selectedCustomerIds.add(c.id);
                    } else {
                        state.selectedCustomerIds.delete(c.id);
                    }
                });

            render();
        });

    document.querySelector("[data-select-reminder]")
        ?.addEventListener("click", () => {
            const survey =
                getSurvey(state.sendSurveyId);

            filteredCustomers(survey.id)
                .filter(
                    c =>
                        statusText(c, survey.id) ===
                        "送信済み / 未回答"
                )
                .forEach(
                    c =>
                        state.selectedCustomerIds.add(c.id)
                );

            render();
        });

    document.querySelector("[data-go-mail]")
        ?.addEventListener("click", () => {
            state.sendSubject =
                document.getElementById("sendSubject")
                    ?.value ||
                state.sendSubject;

            state.sendBody =
                document.getElementById("sendBody")
                    ?.value ||
                state.sendBody;

            state.sendTab = "mail";
            render();
        });

    document.querySelector("[data-bulk-send]")
        ?.addEventListener("click", async () => {
            await executeSend("一括送信");
        });

    document.querySelector("[data-resend]")
        ?.addEventListener("click", async () => {
            const selected =
                state.customers.filter(
                    c =>
                        state.selectedCustomerIds.has(
                            c.id
                        )
                );

            const hasSent =
                selected.some(
                    c =>
                        Number(c.sendCount || 0) > 0
                );

            if (hasSent) {
                const ok =
                    await confirmModal(
                        "再送確認",
                        "送信済み顧客を含みます。再送しますか？"
                    );

                if (!ok) return;
            }

            await executeSend("再送");
        });

    /* 集計 */
    document.querySelectorAll("[data-question-select]")
        .forEach(input => {
            input.addEventListener("change", () => {
                const id =
                    input.dataset.questionSelect;

                if (input.checked) {
                    state.selectedQuestions.add(id);
                } else {
                    state.selectedQuestions.delete(id);
                }

                render();
            });
        });

    document.querySelector("[data-select-all-questions]")
        ?.addEventListener("click", () => {
            const survey =
                getSurvey(state.aggregateSurveyId);

            getQuestionIndexList(survey)
                .forEach(
                    q =>
                        state.selectedQuestions.add(q.id)
                );

            render();
        });

    document.querySelector("[data-clear-questions]")
        ?.addEventListener("click", () => {
            state.selectedQuestions.clear();
            render();
        });

    document.querySelector("[data-export-csv]")
        ?.addEventListener("click", () => {
            exportCSV();
        });

    document.querySelector("[data-export-pdf]")
        ?.addEventListener("click", () => {
            toast(
                "PDF出力操作を実行しました。",
                "success"
            );

            window.print();
        });

    /* kintone */
    document.querySelector("[data-save-kintone]")
        ?.addEventListener("click", saveKintone);

    document.querySelector("[data-kintone-test]")
        ?.addEventListener("click", testKintone);

    document.querySelector("[data-kintone-fields]")
        ?.addEventListener("click", getKintoneFields);

    document.querySelector("[data-kintone-sync]")
        ?.addEventListener("click", syncKintone);

    /* mail */
    document.querySelector("[data-save-mail]")
        ?.addEventListener("click", saveMail);

    document.querySelector("[data-mail-test]")
        ?.addEventListener("click", testMail);

    /* 回答者 */
    document.querySelectorAll("[data-answer-input]")
        .forEach(input => {
            input.addEventListener("change", () => {
                collectAnswerInputs();
                render();
            });

            input.addEventListener("input", () => {
                collectAnswerInputs();
            });
        });

    document.querySelector("[data-answer-next]")
        ?.addEventListener("click", () => {
            collectAnswerInputs();

            const survey =
                getSurvey(state.answerSurveyId);

            const visibleIds =
                visibleQuestionIds(
                    survey,
                    state.answers
                );

            const pageSize = 3;

            const current =
                visibleIds.slice(
                    state.answerStep * pageSize,
                    state.answerStep * pageSize +
                    pageSize
                );

            const errors =
                validateVisibleQuestions(
                    survey,
                    current
                );

            if (Object.keys(errors).length) {
                showAnswerErrors(errors);
                return;
            }

            state.answerStep++;
            render();
        });

    document.querySelector("[data-answer-back]")
        ?.addEventListener("click", () => {
            collectAnswerInputs();

            if (state.answerStep > 0) {
                state.answerStep--;
                render();
            }
        });

    document.querySelector("[data-answer-confirm]")
        ?.addEventListener("click", () => {
            collectAnswerInputs();

            const survey =
                getSurvey(state.answerSurveyId);

            const errors =
                validateAnswers(
                    survey,
                    state.answers
                );

            if (Object.keys(errors).length) {
                showAnswerErrors(errors);
                return;
            }

            state.screen = "confirm";
            render();
        });

    document.querySelector("[data-confirm-back]")
        ?.addEventListener("click", () => {
            state.screen = "answer";
            render();
        });

    document.querySelectorAll("[data-edit-answer]")
        .forEach(button => {
            button.addEventListener("click", () => {
                state.screen = "answer";

                const survey =
                    getSurvey(state.answerSurveyId);

                const questionIds =
                    visibleQuestionIds(
                        survey,
                        state.answers
                    );

                const index =
                    questionIds.indexOf(
                        button.dataset.editAnswer
                    );

                state.answerStep =
                    index >= 0
                    ? Math.floor(index / 3)
                    : 0;

                render();
            });
        });

    document.querySelector("[data-submit-response]")
        ?.addEventListener("click", async () => {
            const ok =
                await confirmModal(
                    "回答送信確認",
                    "回答を送信します。送信後はアンケートの設定に応じて再回答できない場合があります。"
                );

            if (!ok) return;

            try {
                const data =
                    await api(
                        "submit_response",
                        {
                            surveyId:
                                state.answerSurveyId,
                            token:
                                state.answerToken,
                            customerId:
                                state.answerCustomerId,
                            respondent:
                                state.answerRespondent,
                            answers:
                                state.answers
                        }
                    );

                if (data.success) {
                    state.screen = "complete";
                    render();
                }
            } catch (e) {
                showError(e);
            }
        });
}

/* ============================================================
 * 送信実行
 * ============================================================ */

async function executeSend(sendType) {
    const survey =
        getSurvey(state.sendSurveyId);

    if (!survey) {
        toast(
            "対象アンケートが指定されていません。",
            "error"
        );
        return;
    }

    const selected =
        [...state.selectedCustomerIds];

    if (!selected.length) {
        toast(
            "送信対象を選択してください。",
            "error"
        );
        return;
    }

    state.sendSubject =
        document.getElementById("sendSubject")
            ?.value ||
        state.sendSubject;

    state.sendBody =
        document.getElementById("sendBody")
            ?.value ||
        state.sendBody;

    const ok =
        await confirmModal(
            sendType,
            `${selected.length}件の顧客へメールを送信しますか？`
        );

    if (!ok) return;

    try {
        const data =
            await api(
                "send_mail",
                {
                    surveyId:
                        survey.id,
                    customerIds:
                        selected,
                    subject:
                        state.sendSubject,
                    body:
                        state.sendBody,
                    sendType
                }
            );

        state.sendResults = {
            ...data.summary,
            results: data.results
        };

        state.sendHistory =
            data.history || [];

        await loadData();

        state.sendTab = "result";

        render();

        toast(
            "送信処理が完了しました。",
            "success"
        );
    } catch (e) {
        showError(e);
    }
}

/* ============================================================
 * 回答
 * ============================================================ */

function collectAnswerInputs() {
    document.querySelectorAll(
        "[data-answer-input]"
    ).forEach(input => {
        const questionId =
            input.dataset.questionId;

        const question =
            getQuestion(
                getSurvey(state.answerSurveyId),
                questionId
            );

        if (!question) return;

        if (question.type === "multiple") {
            const checked =
                [...document.querySelectorAll(
                    `[data-question-id="${CSS.escape(questionId)}"]`
                )]
                    .filter(el => el.checked)
                    .map(el => el.value);

            state.answers[questionId] =
                checked;
        } else if (question.type === "single") {
            if (input.checked) {
                state.answers[questionId] =
                    input.value;
            }
        } else {
            state.answers[questionId] =
                input.value;
        }
    });
}

function validateVisibleQuestions(
    survey,
    questionIds
) {
    const errors = {};

    for (const id of questionIds) {
        const q =
            getQuestion(survey, id);

        if (!q || !q.required) {
            continue;
        }

        const value =
            state.answers[id];

        const empty =
            q.type === "multiple"
            ? !Array.isArray(value) ||
              value.length === 0
            : String(value ?? "").trim() === "";

        if (empty) {
            errors[id] =
                `${q.questionNumber}「${q.text}」は必須回答です。`;
        }
    }

    return errors;
}

function showAnswerErrors(errors) {
    const root =
        document.getElementById("answerErrors");

    if (!root) return;

    root.innerHTML = `
        <div class="error-box">
            <strong>未回答の必須項目があります。</strong>
            <ul>
                ${
                    Object.values(errors)
                        .map(
                            message =>
                                `<li>${escapeHtml(message)}</li>`
                        ).join("")
                }
            </ul>
        </div>
    `;

    root.scrollIntoView({
        behavior: "smooth",
        block: "start"
    });
}

async function initializeAnswerFromUrl() {
    const params =
        new URLSearchParams(
            window.location.search
        );

    const surveyId =
        params.get("survey") || "";

    const token =
        params.get("token") || "";

    if (!surveyId) {
        state.answerSurveyId = null;
        state.screen = "answer";
        render();
        return;
    }

    try {
        const data =
            await api(
                "check_response",
                {
                    surveyId,
                    token
                }
            );

        state.answerSurveyId =
            surveyId;

        state.answerToken =
            token || crypto.randomUUID();

        state.answers = {};

        if (
            data.answered &&
            !data.survey.allowResubmission
        ) {
            state.screen = "complete";

            render();

            document.querySelector(
                ".answer-container .card"
            ).innerHTML = `
                <h1>回答済みです</h1>
                <p>
                    このアンケートはすでに回答済みです。
                </p>
            `;

            return;
        }

        if (data.response) {
            state.answers =
                data.response.answers || {};

            state.answerCustomerId =
                data.response.customerId || null;

            state.answerRespondent =
                data.response.respondent || {};
        }

        state.answerStep = 0;
        state.screen = "answer";

        render();
    } catch (e) {
        document.getElementById("app").innerHTML = `
            <div class="answer-shell">
                <div class="answer-container">
                    <div class="card">
                        <div class="error-box">
                            ${escapeHtml(e.message)}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
}

/* ============================================================
 * CSV
 * ============================================================ */

function exportCSV() {
    const survey =
        getSurvey(state.aggregateSurveyId);

    if (!survey) {
        toast(
            "対象アンケートが指定されていません。",
            "error"
        );
        return;
    }

    const questions =
        getQuestionIndexList(survey);

    const rows = [];

    const header = [
        "回答ID",
        "回答日時",
        "回答者組織",
        "回答者氏名",
        "メールアドレス",
        "部署名",
        "電話番号",
        "住所",
        ...questions.map(
            q =>
                `${q.questionNumber} ${q.text}`
        )
    ];

    rows.push(header);

    state.responses
        .filter(
            r =>
                r.surveyId === survey.id &&
                r.status === "completed"
        )
        .forEach(response => {
            const row = [
                response.id,
                response.submittedAt,
                response.respondent?.organization || "",
                response.respondent?.name || "",
                response.respondent?.email || "",
                response.respondent?.department || "",
                response.respondent?.phone || "",
                response.respondent?.address || ""
            ];

            questions.forEach(q => {
                let answer =
                    response.answers?.[q.id] ?? "";

                if (Array.isArray(answer)) {
                    answer =
                        answer.map(
                            id =>
                                findChoiceById(q, id)?.label ||
                                id
                        ).join(" / ");
                } else if (
                    q.type === "single"
                ) {
                    answer =
                        findChoiceById(q, answer)?.label ||
                        answer;
                }

                row.push(answer);
            });

            rows.push(row);
        });

    const csv =
        rows.map(
            row =>
                row.map(
                    value =>
                        '"' +
                        String(value ?? "")
                            .replace(/"/g, '""') +
                        '"'
                ).join(",")
        ).join("\r\n");

    const blob =
        new Blob(
            ["\ufeff" + csv],
            {
                type:
                    "text/csv;charset=utf-8"
            }
        );

    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement("a");

    a.href = url;
    a.download =
        "survey-" +
        survey.id +
        "-" +
        new Date()
            .toISOString()
            .slice(0,10) +
        ".csv";

    document.body.appendChild(a);
    a.click();
    a.remove();

    URL.revokeObjectURL(url);

    toast(
        "CSV出力を実行しました。",
        "success"
    );
}

/* ============================================================
 * kintone設定操作
 * ============================================================ */

async function saveKintone() {
    try {
        const mapping = {
            organization:
                document.querySelector(
                    '[data-map="organization"]'
                )?.value || "",

            name:
                document.querySelector(
                    '[data-map="name"]'
                )?.value || "",

            email:
                document.querySelector(
                    '[data-map="email"]'
                )?.value || "",

            department:
                document.querySelector(
                    '[data-map="department"]'
                )?.value || "",

            phone:
                document.querySelector(
                    '[data-map="phone"]'
                )?.value || "",

            address:
                [...document.querySelectorAll(
                    "[data-map-address]"
                )]
                    .filter(
                        el => el.checked
                    )
                    .map(
                        el =>
                            el.dataset.mapAddress
                    )
        };

        const payload = {
            subdomain:
                document.getElementById("kSubdomain")
                    ?.value || "",

            appId:
                document.getElementById("kAppId")
                    ?.value || "",

            loginName:
                document.getElementById("kLoginName")
                    ?.value || "",

            password:
                document.getElementById("kPassword")
                    ?.value || "",

            sslVerify:
                document.getElementById("kSslVerify")
                    ?.value === "true",

            proxy:
                document.getElementById("kProxy")
                    ?.value || "",

            mapping
        };

        const data =
            await api(
                "save_kintone",
                payload
            );

        state.kintone =
            data.kintone || {};

        toast(
            "kintone設定を保存しました。",
            "success"
        );

        render();
    } catch (e) {
        showError(e);
    }
}

async function testKintone() {
    const message =
        document.getElementById("kintoneMessage");

    if (message) {
        message.innerHTML = `
            <div class="info-box">
                接続テストを実行しています…
            </div>
        `;
    }

    try {
        const data =
            await api(
                "kintone_test",
                {
                    settings: {
                        subdomain:
                            document.getElementById("kSubdomain")
                                ?.value || "",

                        appId:
                            document.getElementById("kAppId")
                                ?.value || "",

                        loginName:
                            document.getElementById("kLoginName")
                                ?.value || "",

                        password:
                            document.getElementById("kPassword")
                                ?.value || "",

                        sslVerify:
                            document.getElementById("kSslVerify")
                                ?.value === "true",

                        proxy:
                            document.getElementById("kProxy")
                                ?.value || ""
                    }
                }
            );

        if (message) {
            message.innerHTML = `
                <div class="success-box">
                    <strong>${escapeHtml(data.message)}</strong>
                    <div>${escapeHtml(data.detail || "")}</div>
                </div>
            `;
        }
    } catch (e) {
        if (message) {
            message.innerHTML = `
                <div class="error-box">
                    <strong>接続失敗</strong>
                    <div>${escapeHtml(e.message)}</div>
                </div>
            `;
        }
    }
}

async function getKintoneFields() {
    try {
        const data =
            await api(
                "kintone_fields"
            );

        state.kintone.fields =
            data.fields || [];

        toast(
            "kintone項目一覧を取得しました。",
            "success"
        );

        render();
    } catch (e) {
        showError(e);
    }
}

async function syncKintone() {
    try {
        const data =
            await api(
                "kintone_sync"
            );

        await loadData();

        toast(
            data.message ||
            "顧客同期完了",
            "success"
        );

        render();
    } catch (e) {
        showError(e);
    }
}

/* ============================================================
 * メール設定操作
 * ============================================================ */

async function saveMail() {
    try {
        const payload = {
            smtpServer:
                document.getElementById("mServer")
                    ?.value || "",

            smtpPort:
                Number(
                    document.getElementById("mPort")
                        ?.value || 587
                ),

            encryption:
                document.getElementById("mEncryption")
                    ?.value || "starttls",

            authentication:
                document.getElementById("mAuth")
                    ?.value === "true",

            username:
                document.getElementById("mUsername")
                    ?.value || "",

            password:
                document.getElementById("mPassword")
                    ?.value || "",

            fromEmail:
                document.getElementById("mFromEmail")
                    ?.value || "",

            fromName:
                document.getElementById("mFromName")
                    ?.value || "",

            replyTo:
                document.getElementById("mReplyTo")
                    ?.value || ""
        };

        const data =
            await api(
                "save_mail",
                payload
            );

        state.mail =
            data.mail || {};

        toast(
            "メールサーバ設定を保存しました。",
            "success"
        );

        render();
    } catch (e) {
        showError(e);
    }
}

async function testMail() {
    const message =
        document.getElementById("mailMessage");

    if (message) {
        message.innerHTML = `
            <div class="info-box">
                テストメールを送信しています…
            </div>
        `;
    }

    try {
        const settings = {
            smtpServer:
                document.getElementById("mServer")
                    ?.value || "",

            smtpPort:
                Number(
                    document.getElementById("mPort")
                        ?.value || 587
                ),

            encryption:
                document.getElementById("mEncryption")
                    ?.value || "starttls",

            authentication:
                document.getElementById("mAuth")
                    ?.value === "true",

            username:
                document.getElementById("mUsername")
                    ?.value || "",

            password:
                document.getElementById("mPassword")
                    ?.value || "",

            fromEmail:
                document.getElementById("mFromEmail")
                    ?.value || "",

            fromName:
                document.getElementById("mFromName")
                    ?.value || "",

            replyTo:
                document.getElementById("mReplyTo")
                    ?.value || ""
        };

        const testEmail =
            document.getElementById("mTestEmail")
                ?.value || "";

        const data =
            await api(
                "mail_test",
                {
                    settings,
                    testEmail
                }
            );

        if (message) {
            message.innerHTML = `
                <div class="success-box">
                    <strong>${escapeHtml(data.message)}</strong>
                    <div>${escapeHtml(data.detail || "")}</div>
                </div>
            `;
        }
    } catch (e) {
        if (message) {
            message.innerHTML = `
                <div class="error-box">
                    <strong>テストメール送信失敗</strong>
                    <div>${escapeHtml(e.message)}</div>
                </div>
            `;
        }
    }
}

/* ============================================================
 * URL判定
 * ============================================================ */

function initializeFromUrl() {
    const params =
        new URLSearchParams(
            window.location.search
        );

    const view =
        params.get("view");

    if (view === "answer") {
        initializeAnswerFromUrl();
        return true;
    }

    return false;
}

/* ============================================================
 * 起動
 * ============================================================ */

(async function boot() {
    try {
        if (initializeFromUrl()) {
            return;
        }

        await loadData();

        /*
         * 管理者画面は認証なし。
         * 回答者URLを除き、常に一覧を起点とする。
         */
        state.screen = "list";

        render();
    } catch (e) {
        document.getElementById("app").innerHTML = `
            <div class="answer-shell">
                <div class="answer-container">
                    <div class="card">
                        <div class="error-box">
                            システムを起動できませんでした。
                            <br>
                            ${escapeHtml(e.message)}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
})();
</script>
</body>
</html>
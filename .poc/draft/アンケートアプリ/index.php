<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| アンケート管理システム - Single File Prototype
| PHP 8.5 / Apache 2.4
|--------------------------------------------------------------------------
| index.php
|
| 管理者認証・回答者認証なし。
| DB不使用。data/*.jsonへ永続化。
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

const JSON_SURVEYS   = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const JSON_CUSTOMERS = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const JSON_RESPONSES = DATA_DIR . DIRECTORY_SEPARATOR . 'responses.json';
const JSON_HISTORY   = DATA_DIR . DIRECTORY_SEPARATOR . 'send_history.json';
const JSON_KINTONE   = DATA_DIR . DIRECTORY_SEPARATOR . 'kintone.json';
const JSON_MAIL      = DATA_DIR . DIRECTORY_SEPARATOR . 'mail.json';

const MAX_BODY_SIZE = 5 * 1024 * 1024;

/* =========================================================================
 * PHP utility
 * ========================================================================= */

function nowIso(): string
{
    return date('c');
}

function uid(string $prefix): string
{
    try {
        return $prefix . '_' . bin2hex(random_bytes(10));
    } catch (Throwable) {
        return $prefix . '_' . uniqid('', true);
    }
}

function jsonResponse(
    bool $ok,
    mixed $data = null,
    ?string $code = null,
    ?string $message = null,
    int $status = 200
): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $payload = ['ok' => $ok];

    if ($ok) {
        $payload['data'] = $data;
    } else {
        $payload['error'] = [
            'code' => $code ?? 'ERROR',
            'message' => $message ?? '処理に失敗しました。'
        ];
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function apiError(string $code, string $message, int $status = 400): never
{
    jsonResponse(false, null, $code, $message, $status);
}

function validateString(mixed $value, string $name, int $max = 10000, bool $required = false): string
{
    if ($value === null) {
        if ($required) {
            apiError('VALIDATION', "{$name}は必須です。");
        }
        return '';
    }

    if (!is_string($value)) {
        apiError('VALIDATION', "{$name}が不正です。");
    }

    $value = trim($value);

    if ($required && $value === '') {
        apiError('VALIDATION', "{$name}は必須です。");
    }

    if (mb_strlen($value) > $max) {
        apiError('VALIDATION', "{$name}が長すぎます。");
    }

    return $value;
}

function requestJson(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($length > MAX_BODY_SIZE) {
        apiError('REQUEST_TOO_LARGE', 'リクエストサイズが大きすぎます。', 413);
    }

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        apiError('INVALID_JSON', 'リクエストJSONが不正です。');
    }

    return $data;
}

function ensureDataDirectory(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('dataディレクトリを作成できません。');
        }
    }

    /*
     * data/*.json の直接取得をApache環境で防ぐ。
     * PHPファイルではないため、index.php単一PHP構成という条件は維持する。
     */
    $htaccess = DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';

    if (!file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Options -Indexes\n" .
            "<FilesMatch \"\\.json$\">\n" .
            "    Require all denied\n" .
            "</FilesMatch>\n"
        );
    }
}

function writeJsonAtomic(string $file, mixed $data): void
{
    ensureDataDirectory();

    $dir = dirname($file);
    $lockFile = $file . '.lock';

    $lock = fopen($lockFile, 'c+');

    if (!$lock) {
        throw new RuntimeException('ロックファイルを開けません。');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('ファイルロックを取得できません。');
        }

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            throw new RuntimeException('JSONエンコードに失敗しました。');
        }

        $tmp = tempnam($dir, basename($file) . '.tmp.');

        if ($tmp === false) {
            throw new RuntimeException('一時ファイルを作成できません。');
        }

        $fp = fopen($tmp, 'wb');

        if (!$fp) {
            @unlink($tmp);
            throw new RuntimeException('一時ファイルを開けません。');
        }

        try {
            if (fwrite($fp, $json) === false) {
                throw new RuntimeException('JSONを書き込めません。');
            }

            fflush($fp);
        } finally {
            fclose($fp);
        }

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('JSONファイルを更新できません。');
        }

        flock($lock, LOCK_UN);
    } finally {
        fclose($lock);
    }
}

function readJson(string $file, mixed $default): mixed
{
    ensureDataDirectory();

    if (!file_exists($file)) {
        writeJsonAtomic($file, $default);
        return $default;
    }

    $lockFile = $file . '.lock';
    $lock = fopen($lockFile, 'c+');

    if (!$lock) {
        throw new RuntimeException('JSONロックを開けません。');
    }

    try {
        flock($lock, LOCK_SH);

        $raw = file_get_contents($file);

        if ($raw === false || trim($raw) === '') {
            return $default;
        }

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'JSON読み込みエラー: ' . json_last_error_msg()
            );
        }

        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function saveSurveys(array $surveys): void
{
    writeJsonAtomic(JSON_SURVEYS, array_values($surveys));
}

function saveCustomers(array $customers): void
{
    writeJsonAtomic(JSON_CUSTOMERS, array_values($customers));
}

function saveResponses(array $responses): void
{
    writeJsonAtomic(JSON_RESPONSES, array_values($responses));
}

function saveHistory(array $history): void
{
    writeJsonAtomic(JSON_HISTORY, array_values($history));
}

function saveKintone(array $data): void
{
    writeJsonAtomic(JSON_KINTONE, $data);
}

function saveMail(array $data): void
{
    writeJsonAtomic(JSON_MAIL, $data);
}

function loadAllData(): array
{
    return [
        'surveys' => readJson(JSON_SURVEYS, []),
        'customers' => readJson(JSON_CUSTOMERS, []),
        'responses' => readJson(JSON_RESPONSES, []),
        'history' => readJson(JSON_HISTORY, []),
        'kintone' => readJson(JSON_KINTONE, defaultKintone()),
        'mail' => readJson(JSON_MAIL, defaultMail()),
    ];
}

/* =========================================================================
 * Survey helpers
 * ========================================================================= */

function defaultKintone(): array
{
    return [
        'settings' => [
            'subdomain' => '',
            'appId' => '',
            'loginName' => '',
            'password' => '',
            'sslVerify' => false,
            'proxy' => ''
        ],
        'fields' => [],
        'mapping' => [
            'organizationName' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => []
        ],
        'updatedAt' => null
    ];
}

function defaultMail(): array
{
    return [
        'smtpServer' => '',
        'smtpPort' => 587,
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password' => '',
        'fromEmail' => '',
        'fromName' => '',
        'replyTo' => '',
        'status' => '未設定',
        'updatedAt' => null
    ];
}

function sampleGroup(string $id, string $title, int $sort): array
{
    return [
        'groupId' => $id,
        'title' => $title,
        'sortOrder' => $sort,
        'questions' => []
    ];
}

function sampleQuestion(
    string $id,
    string $groupId,
    int $sort,
    string $text,
    string $type,
    bool $required,
    array $choices = [],
    array $branchRules = []
): array {
    return [
        'questionId' => $id,
        'groupId' => $groupId,
        'sortOrder' => $sort,
        'questionText' => $text,
        'type' => $type,
        'required' => $required,
        'choices' => $choices,
        'branchRules' => $branchRules
    ];
}

function sampleChoice(string $id, string $label, int $sort): array
{
    return [
        'choiceId' => $id,
        'label' => $label,
        'sortOrder' => $sort
    ];
}

function sampleSurvey(
    string $title,
    string $status,
    ?string $endDate = null
): array {
    $surveyId = uid('survey');
    $group1 = uid('group');
    $group2 = uid('group');

    $q1 = uid('question');
    $q2 = uid('question');
    $q3 = uid('question');
    $q4 = uid('question');

    $c11 = uid('choice');
    $c12 = uid('choice');

    $c21 = uid('choice');
    $c22 = uid('choice');

    $g1 = sampleGroup($group1, '基本情報', 1);
    $g2 = sampleGroup($group2, 'ご意見', 2);

    $g1['questions'] = [
        sampleQuestion(
            $q1,
            $group1,
            1,
            '今回のサービスについて総合的に満足していますか？',
            'single',
            true,
            [
                sampleChoice($c11, '満足', 1),
                sampleChoice($c12, '不満', 2)
            ]
        ),
        sampleQuestion(
            $q2,
            $group1,
            2,
            '利用したサービスを選択してください。',
            'multiple',
            false,
            [
                sampleChoice(uid('choice'), 'Webサービス', 1),
                sampleChoice(uid('choice'), 'サポート', 2),
                sampleChoice(uid('choice'), '資料・コンテンツ', 3)
            ]
        )
    ];

    $g2['questions'] = [
        sampleQuestion(
            $q3,
            $group2,
            1,
            '特に良かった点を教えてください。',
            'text',
            false
        ),
        sampleQuestion(
            $q4,
            $group2,
            2,
            '改善してほしい点を教えてください。',
            'text',
            false
        )
    ];

    /*
     * 満足 / 不満による分岐サンプル。
     * 不満ならQ1-2へ進む、満足ならQ1-2を飛ばす、という形ではなく
     * nextQuestionIdを直接参照する。
     */
    $g1['questions'][0]['branchRules'] = [
        [
            'questionId' => $q1,
            'choiceId' => $c11,
            'nextQuestionId' => $q2
        ],
        [
            'questionId' => $q1,
            'choiceId' => $c12,
            'nextQuestionId' => $q3
        ]
    ];

    $survey = [
        'surveyId' => $surveyId,
        'title' => $title,
        'description' => 'サンプルアンケートです。',
        'startDate' => date('c', strtotime('-2 days')),
        'endDate' => $endDate,
        'numberingMode' => 'all',
        'status' => $status,
        'allowReanswer' => false,
        'createdAt' => nowIso(),
        'updatedAt' => nowIso(),
        'groups' => [$g1, $g2]
    ];

    recalculateSurvey($survey);

    return $survey;
}

function initializeData(): void
{
    ensureDataDirectory();

    $surveys = readJson(JSON_SURVEYS, null);

    if ($surveys === null || !is_array($surveys) || count($surveys) === 0) {
        $past = date('c', strtotime('-1 day'));

        $surveys = [
            sampleSurvey('サンプル：下書き', 'draft'),
            sampleSurvey('サンプル：公開中', 'published', date('c', strtotime('+30 days'))),
            sampleSurvey('サンプル：停止', 'stopped', date('c', strtotime('+30 days'))),
            sampleSurvey('サンプル：終了', 'finished', $past),
            sampleSurvey('サンプル：下書き＋過去日時', 'draft', $past),
            sampleSurvey('サンプル：公開中＋過去日時', 'published', $past),
            sampleSurvey('サンプル：停止＋過去日時', 'stopped', $past)
        ];

        saveSurveys($surveys);
    }

    if (!file_exists(JSON_CUSTOMERS)) {
        $customers = [];

        for ($i = 1; $i <= 12; $i++) {
            $customers[] = [
                'customerId' => 'customer_' . $i,
                'organizationName' => '株式会社サンプル' . $i,
                'name' => '山田 太郎' . $i,
                'email' => "sample{$i}@example.com",
                'department' => $i % 2 ? '営業部' : '企画部',
                'phone' => '03-1234-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT),
                'address' => [
                    'postalCode' => '100-0001',
                    'prefecture' => '東京都',
                    'city' => '千代田区',
                    'street' => 'サンプル1-1-1',
                    'building' => ''
                ],
                'lastSentAt' => null,
                'sendCount' => 0,
                'answerStatus' => '未送信',
                'kintoneStatus' => '未同期',
                'createdAt' => nowIso(),
                'updatedAt' => nowIso()
            ];
        }

        saveCustomers($customers);
    }

    if (!file_exists(JSON_RESPONSES)) {
        saveResponses([]);
    }

    if (!file_exists(JSON_HISTORY)) {
        saveHistory([]);
    }

    if (!file_exists(JSON_KINTONE)) {
        saveKintone(defaultKintone());
    }

    if (!file_exists(JSON_MAIL)) {
        saveMail(defaultMail());
    }
}

function normalizeSurvey(array $survey): array
{
    $survey['surveyId'] = (string)($survey['surveyId'] ?? uid('survey'));
    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['description'] = (string)($survey['description'] ?? '');
    $survey['startDate'] = $survey['startDate'] ?? null;
    $survey['endDate'] = $survey['endDate'] ?? null;
    $survey['numberingMode'] = in_array(
        $survey['numberingMode'] ?? 'all',
        ['all', 'group'],
        true
    ) ? $survey['numberingMode'] : 'all';

    $survey['status'] = in_array(
        $survey['status'] ?? 'draft',
        ['draft', 'published', 'stopped', 'finished'],
        true
    ) ? $survey['status'] : 'draft';

    $survey['allowReanswer'] = (bool)($survey['allowReanswer'] ?? false);
    $survey['createdAt'] = $survey['createdAt'] ?? nowIso();
    $survey['updatedAt'] = nowIso();

    $groups = [];

    foreach (($survey['groups'] ?? []) as $gi => $group) {
        if (!is_array($group)) {
            continue;
        }

        $group['groupId'] = (string)($group['groupId'] ?? uid('group'));
        $group['title'] = (string)($group['title'] ?? '');
        $group['sortOrder'] = $gi + 1;

        $questions = [];

        foreach (($group['questions'] ?? []) as $qi => $q) {
            if (!is_array($q)) {
                continue;
            }

            $q['questionId'] = (string)($q['questionId'] ?? uid('question'));
            $q['groupId'] = $group['groupId'];
            $q['sortOrder'] = $qi + 1;
            $q['questionText'] = (string)($q['questionText'] ?? '');
            $q['type'] = in_array(
                $q['type'] ?? 'text',
                ['single', 'multiple', 'text'],
                true
            ) ? $q['type'] : 'text';

            $q['required'] = (bool)($q['required'] ?? false);
            $q['choices'] = is_array($q['choices'] ?? null)
                ? array_values($q['choices'])
                : [];
            $q['branchRules'] = is_array($q['branchRules'] ?? null)
                ? array_values($q['branchRules'])
                : [];

            $choices = [];

            foreach ($q['choices'] as $ci => $choice) {
                if (!is_array($choice)) {
                    continue;
                }

                $choices[] = [
                    'choiceId' => (string)($choice['choiceId'] ?? uid('choice')),
                    'label' => (string)($choice['label'] ?? ''),
                    'sortOrder' => $ci + 1
                ];
            }

            $q['choices'] = $choices;

            if ($q['type'] !== 'single') {
                $q['branchRules'] = [];
            }

            $questions[] = $q;
        }

        $group['questions'] = $questions;
        $groups[] = $group;
    }

    $survey['groups'] = $groups;

    recalculateSurvey($survey);

    return $survey;
}

function recalculateSurvey(array &$survey): void
{
    usort(
        $survey['groups'],
        fn($a, $b) => ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0)
    );

    $allQuestions = [];

    foreach ($survey['groups'] as $gi => &$group) {
        $group['sortOrder'] = $gi + 1;

        usort(
            $group['questions'],
            fn($a, $b) => ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0)
        );

        foreach ($group['questions'] as $qi => &$question) {
            $question['groupId'] = $group['groupId'];
            $question['sortOrder'] = $qi + 1;
            $question['questionId'] = (string)$question['questionId'];

            foreach ($question['choices'] as $ci => &$choice) {
                $choice['sortOrder'] = $ci + 1;
            }
            unset($choice);

            $allQuestions[] = &$question;
        }
        unset($question);
    }
    unset($group);

    if (($survey['numberingMode'] ?? 'all') === 'group') {
        foreach ($survey['groups'] as $gi => &$group) {
            foreach ($group['questions'] as $qi => &$question) {
                $question['questionNumber'] = 'Q' . ($gi + 1) . '-' . ($qi + 1);
            }
            unset($question);
        }
        unset($group);
    } else {
        $number = 1;

        foreach ($survey['groups'] as &$group) {
            foreach ($group['questions'] as &$question) {
                $question['questionNumber'] = 'Q' . $number++;
            }
            unset($question);
        }
        unset($group);
    }

    /*
     * 無効なbranchRulesを整理。
     */
    $questionIds = [];
    $choiceIds = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $q) {
            $questionIds[$q['questionId']] = true;

            foreach ($q['choices'] as $choice) {
                $choiceIds[$choice['choiceId']] = true;
            }
        }
    }

    foreach ($survey['groups'] as &$group) {
        foreach ($group['questions'] as &$question) {
            if ($question['type'] !== 'single') {
                $question['branchRules'] = [];
                continue;
            }

            $rules = [];

            foreach ($question['branchRules'] as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $ruleQuestionId = (string)($rule['questionId'] ?? '');
                $choiceId = (string)($rule['choiceId'] ?? '');
                $nextQuestionId = (string)($rule['nextQuestionId'] ?? '');

                if (
                    $ruleQuestionId === $question['questionId'] &&
                    isset($choiceIds[$choiceId]) &&
                    isset($questionIds[$nextQuestionId])
                ) {
                    $rules[] = [
                        'questionId' => $ruleQuestionId,
                        'choiceId' => $choiceId,
                        'nextQuestionId' => $nextQuestionId
                    ];
                }
            }

            $question['branchRules'] = $rules;
        }
        unset($question);
    }
    unset($group);
}

function expireSurveyIfNeeded(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published' &&
        !empty($survey['endDate'])
    ) {
        $end = strtotime((string)$survey['endDate']);

        if ($end !== false && time() > $end) {
            $survey['status'] = 'finished';
            $survey['updatedAt'] = nowIso();
            return true;
        }
    }

    return false;
}

function applyExpirationToAll(array &$surveys): bool
{
    $changed = false;

    foreach ($surveys as &$survey) {
        if (expireSurveyIfNeeded($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    return $changed;
}

function findSurvey(array $surveys, string $id): ?array
{
    foreach ($surveys as $survey) {
        if (($survey['surveyId'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function surveyResponseCount(array $responses, string $surveyId): int
{
    $count = 0;

    foreach ($responses as $response) {
        if (($response['surveyId'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

function flattenQuestions(array $survey): array
{
    $result = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $result[] = $question;
        }
    }

    return $result;
}

function questionMap(array $survey): array
{
    $map = [];

    foreach (flattenQuestions($survey) as $q) {
        $map[$q['questionId']] = $q;
    }

    return $map;
}

/* =========================================================================
 * Survey validation
 * ========================================================================= */

function validateSurveyForSave(array $survey): array
{
    $survey = normalizeSurvey($survey);

    if ($survey['title'] === '') {
        apiError('VALIDATION', 'アンケートタイトルは必須です。');
    }

    if (mb_strlen($survey['title']) > 300) {
        apiError('VALIDATION', 'アンケートタイトルが長すぎます。');
    }

    if (
        $survey['startDate'] !== null &&
        $survey['startDate'] !== '' &&
        strtotime((string)$survey['startDate']) === false
    ) {
        apiError('VALIDATION', '開始日時が不正です。');
    }

    if (
        $survey['endDate'] !== null &&
        $survey['endDate'] !== '' &&
        strtotime((string)$survey['endDate']) === false
    ) {
        apiError('VALIDATION', '終了日時が不正です。');
    }

    if (
        !empty($survey['startDate']) &&
        !empty($survey['endDate']) &&
        strtotime((string)$survey['startDate']) > strtotime((string)$survey['endDate'])
    ) {
        apiError('VALIDATION', '終了日時は開始日時以降にしてください。');
    }

    $questionIds = [];
    $choiceIds = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $q) {
            if (isset($questionIds[$q['questionId']])) {
                apiError('DATA_INTEGRITY', '質問IDが重複しています。');
            }

            $questionIds[$q['questionId']] = true;

            if (mb_strlen($q['questionText']) > 5000) {
                apiError('VALIDATION', '質問文が長すぎます。');
            }

            foreach ($q['choices'] as $choice) {
                if (isset($choiceIds[$choice['choiceId']])) {
                    apiError('DATA_INTEGRITY', '選択肢IDが重複しています。');
                }

                $choiceIds[$choice['choiceId']] = true;

                if (mb_strlen($choice['label']) > 1000) {
                    apiError('VALIDATION', '選択肢が長すぎます。');
                }
            }
        }
    }

    recalculateSurvey($survey);

    return $survey;
}

/* =========================================================================
 * Status transition
 * ========================================================================= */

function allowedStatus(string $current, string $target): bool
{
    return match ($current) {
        'draft' => $target === 'published',
        'published' => $target === 'stopped',
        'stopped' => $target === 'published',
        'finished' => false,
        default => false
    };
}

/* =========================================================================
 * kintone helpers
 * ========================================================================= */

function normalizeKintoneSubdomain(string $input): string
{
    $input = trim($input);

    if ($input === '') {
        throw new RuntimeException('サブドメインを入力してください。');
    }

    if (!preg_match('~^https?://~i', $input)) {
        if (str_contains($input, '.cybozu.com')) {
            $input = 'https://' . $input;
        } else {
            $input = 'https://' . $input . '.cybozu.com';
        }
    }

    $parts = parse_url($input);

    if (!$parts || empty($parts['host'])) {
        throw new RuntimeException('サブドメイン形式が不正です。');
    }

    $host = strtolower($parts['host']);

    if (!str_ends_with($host, '.cybozu.com')) {
        throw new RuntimeException('cybozu.comのサブドメインを指定してください。');
    }

    return 'https://' . $host;
}

function kintoneSettingsFromData(array $kintone): array
{
    $settings = $kintone['settings'] ?? defaultKintone()['settings'];

    $settings['subdomain'] = (string)($settings['subdomain'] ?? '');
    $settings['appId'] = (string)($settings['appId'] ?? '');
    $settings['loginName'] = (string)($settings['loginName'] ?? '');
    $settings['password'] = (string)($settings['password'] ?? '');
    $settings['sslVerify'] = (bool)($settings['sslVerify'] ?? false);
    $settings['proxy'] = (string)($settings['proxy'] ?? '');

    return $settings;
}

function validateProxy(string $proxy): string
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return '';
    }

    if (!preg_match('/^[^:\s\/]+:\d{1,5}$/', $proxy)) {
        throw new RuntimeException('プロキシはhost:port形式で入力してください。');
    }

    [$host, $port] = explode(':', $proxy, 2);

    $portNumber = (int)$port;

    if ($portNumber < 1 || $portNumber > 65535) {
        throw new RuntimeException('プロキシポートが不正です。');
    }

    return $host . ':' . $portNumber;
}

function kintoneCurl(
    array $settings,
    string $method,
    string $path,
    ?array $body = null
): array {
    $base = normalizeKintoneSubdomain($settings['subdomain']);
    $url = rtrim($base, '/') . $path;

    $appId = (string)$settings['appId'];
    $loginName = (string)$settings['loginName'];
    $password = (string)$settings['password'];

    if ($appId === '' || !ctype_digit($appId)) {
        throw new RuntimeException('顧客管理アプリIDが不正です。');
    }

    if ($loginName === '' || $password === '') {
        throw new RuntimeException('kintoneログイン情報が未設定です。');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL拡張が利用できません。');
    }

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('cURL初期化に失敗しました。');
    }

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64_encode($loginName . ':' . $password),
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => (bool)$settings['sslVerify'],
        CURLOPT_SSL_VERIFYHOST => $settings['sslVerify'] ? 2 : 0
    ];

    if (!empty($settings['proxy'])) {
        $proxy = validateProxy($settings['proxy']);
        $options[CURLOPT_PROXY] = $proxy;
    }

    if ($body !== null) {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            curl_close($ch);
            throw new RuntimeException('kintoneリクエストJSON生成に失敗しました。');
        }

        $options[CURLOPT_POSTFIELDS] = $json;
    }

    curl_setopt_array($ch, $options);

    $started = microtime(true);
    $response = curl_exec($ch);
    $elapsed = microtime(true) - $started;

    if ($response === false) {
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        throw new RuntimeException(
            "kintone通信失敗（cURL {$errno}）: {$error}"
        );
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($status < 200 || $status >= 300) {
        $detail = is_array($decoded)
            ? ($decoded['message'] ?? json_encode($decoded, JSON_UNESCAPED_UNICODE))
            : mb_substr(strip_tags($response), 0, 500);

        throw new RuntimeException(
            "kintone API HTTP {$status}: {$detail}"
        );
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('kintone APIのレスポンスJSONが不正です。');
    }

    $decoded['_elapsed'] = $elapsed;

    return $decoded;
}

function kintoneGetFields(array $settings): array
{
    $appId = (string)$settings['appId'];

    $data = kintoneCurl(
        $settings,
        'GET',
        '/k/v1/app/form/fields.json?app=' . rawurlencode($appId)
    );

    $fields = [];

    foreach (($data['properties'] ?? []) as $code => $field) {
        $fields[] = [
            'code' => $code,
            'label' => $field['label'] ?? $code,
            'type' => $field['type'] ?? '',
            'enabled' => true
        ];
    }

    return $fields;
}

function kintoneGetRecords(array $settings): array
{
    $appId = (string)$settings['appId'];

    $records = [];
    $offset = 0;
    $limit = 500;

    while (true) {
        $query = '$id > ' . $offset . ' order by $id asc limit ' . $limit;

        $data = kintoneCurl(
            $settings,
            'GET',
            '/k/v1/records.json?app=' .
            rawurlencode($appId) .
            '&query=' .
            rawurlencode($query)
        );

        $batch = $data['records'] ?? [];

        if (!is_array($batch)) {
            break;
        }

        foreach ($batch as $record) {
            $records[] = $record;
        }

        if (count($batch) < $limit) {
            break;
        }

        $last = end($batch);

        $offset = isset($last['$id']['value'])
            ? (int)$last['$id']['value']
            : $offset + $limit;
    }

    return $records;
}

function kintoneFieldValue(array $record, string $code): string
{
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $v) {
            if (is_array($v) && isset($v['name'])) {
                $parts[] = (string)$v['name'];
            } elseif (is_scalar($v)) {
                $parts[] = (string)$v;
            }
        }

        return implode(', ', $parts);
    }

    return is_scalar($value) ? (string)$value : '';
}

/* =========================================================================
 * SMTP implementation
 * ========================================================================= */

function smtpReadLine($socket): string
{
    $line = '';

    while (($part = fgets($socket, 515)) !== false) {
        $line .= $part;

        if (strlen($part) < 4 || substr($part, 3, 1) === ' ') {
            break;
        }
    }

    return $line;
}

function smtpCommand($socket, string $command, array $expected): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('SMTPコマンド送信に失敗しました。');
    }

    $response = smtpReadLine($socket);

    if ($response === '') {
        throw new RuntimeException('SMTPサーバーから応答がありません。');
    }

    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            'SMTPエラー [' . $code . '] ' . trim($response)
        );
    }

    return $response;
}

function smtpEncodeHeader(string $value): string
{
    if ($value === '') {
        return '';
    }

    return '=?UTF-8?B?' .
        base64_encode($value) .
        '?=';
}

function smtpDotStuff(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace("\n", "\r\n", $text);
    $text = preg_replace('/^\./m', '..', $text) ?? $text;

    return $text;
}

function smtpSendMail(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (!function_exists('stream_socket_client')) {
        throw new RuntimeException('PHPソケット機能が利用できません。');
    }

    $server = trim((string)($config['smtpServer'] ?? ''));
    $port = (int)($config['smtpPort'] ?? 587);
    $encryption = (string)($config['encryption'] ?? 'tls');
    $auth = (bool)($config['auth'] ?? true);
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');
    $fromEmail = trim((string)($config['fromEmail'] ?? ''));
    $fromName = (string)($config['fromName'] ?? '');
    $replyTo = trim((string)($config['replyTo'] ?? ''));

    if ($server === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('SMTPサーバー設定が不正です。');
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("宛先メールアドレスが不正です: {$to}");
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('送信元メールアドレスが不正です。');
    }

    if ($auth && ($username === '' || $password === '')) {
        throw new RuntimeException('SMTP認証情報が未設定です。');
    }

    $remote = $encryption === 'ssl'
        ? "ssl://{$server}:{$port}"
        : "tcp://{$server}:{$port}";

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false
        ]
    ]);

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException(
            "SMTP接続失敗 ({$errno}): {$errstr}"
        );
    }

    stream_set_timeout($socket, 30);

    try {
        $greeting = smtpReadLine($socket);

        if ((int)substr($greeting, 0, 3) !== 220) {
            throw new RuntimeException(
                'SMTP greeting error: ' . trim($greeting)
            );
        }

        $localHost = $_SERVER['SERVER_NAME'] ?? 'localhost';

        smtpCommand($socket, "EHLO {$localHost}", [250]);

        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);

            $crypto = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException('SMTP TLS開始に失敗しました。');
            }

            smtpCommand($socket, "EHLO {$localHost}", [250]);
        }

        if ($auth) {
            smtpCommand($socket, 'AUTH LOGIN', [334]);
            smtpCommand($socket, base64_encode($username), [334]);
            smtpCommand($socket, base64_encode($password), [235]);
        }

        smtpCommand($socket, "MAIL FROM:<{$fromEmail}>", [250]);
        smtpCommand($socket, "RCPT TO:<{$to}>", [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . ($fromName !== ''
                ? smtpEncodeHeader($fromName) . " <{$fromEmail}>"
                : $fromEmail),
            'To: <' . $to . '>',
            'Subject: ' . smtpEncodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        ];

        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $message = implode("\r\n", $headers) .
            "\r\n\r\n" .
            smtpDotStuff($body) .
            "\r\n.";

        if (fwrite($socket, $message . "\r\n") === false) {
            throw new RuntimeException('SMTP DATA送信に失敗しました。');
        }

        $response = smtpReadLine($socket);

        if ((int)substr($response, 0, 3) !== 250) {
            throw new RuntimeException(
                'SMTPメール送信エラー: ' . trim($response)
            );
        }

        smtpCommand($socket, 'QUIT', [221, 250]);
    } finally {
        fclose($socket);
    }
}

/* =========================================================================
 * Request routing
 * ========================================================================= */

try {
    initializeData();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        /*
         * JSON APIを基本とする。
         * multipart/form-urlencodedのactionも許容する。
         */
        if ($action === '') {
            $request = requestJson();
            $action = (string)($request['action'] ?? '');
        } else {
            $request = $_POST;

            if (isset($_POST['payload'])) {
                $decoded = json_decode((string)$_POST['payload'], true);

                if (is_array($decoded)) {
                    $request = array_merge($request, $decoded);
                }
            }
        }

        switch ($action) {

            /* =============================================================
             * Load
             * ============================================================= */

            case 'load_data': {
                $data = loadAllData();

                $changed = applyExpirationToAll($data['surveys']);

                if ($changed) {
                    saveSurveys($data['surveys']);
                }

                foreach ($data['surveys'] as &$survey) {
                    recalculateSurvey($survey);
                }
                unset($survey);

                /*
                 * パスワードをクライアントへ返さない。
                 */
                $mailPublic = $data['mail'];
                $mailPublic['password'] = '';
                $mailPublic['hasPassword'] = !empty($data['mail']['password']);

                $kPublic = $data['kintone'];
                $kPublic['settings']['password'] = '';
                $kPublic['settings']['hasPassword'] =
                    !empty($data['kintone']['settings']['password']);

                jsonResponse(true, [
                    'surveys' => $data['surveys'],
                    'customers' => $data['customers'],
                    'responses' => $data['responses'],
                    'history' => $data['history'],
                    'kintone' => $kPublic,
                    'mail' => $mailPublic
                ]);
            }

            /* =============================================================
             * Survey save
             * ============================================================= */

            case 'save_survey': {
                $survey = $request['survey'] ?? null;

                if (!is_array($survey)) {
                    apiError('VALIDATION', 'アンケートデータがありません。');
                }

                $survey = validateSurveyForSave($survey);

                $surveys = readJson(JSON_SURVEYS, []);

                $found = false;

                foreach ($surveys as $i => $existing) {
                    if (($existing['surveyId'] ?? '') === $survey['surveyId']) {
                        /*
                         * 保存時は既存statusを維持。
                         */
                        $survey['status'] = $existing['status'] ?? 'draft';
                        $survey['createdAt'] = $existing['createdAt'] ?? nowIso();
                        $survey['updatedAt'] = nowIso();

                        if ($survey['status'] === 'finished') {
                            $survey['status'] = 'finished';
                        }

                        $surveys[$i] = $survey;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $survey['status'] = 'draft';
                    $survey['createdAt'] = nowIso();
                    $survey['updatedAt'] = nowIso();
                    $surveys[] = $survey;
                }

                saveSurveys($surveys);

                jsonResponse(true, [
                    'survey' => $survey,
                    'message' => '保存しました。'
                ]);
            }

            /* =============================================================
             * Status
             * ============================================================= */

            case 'change_status': {
                $surveyId = validateString(
                    $request['surveyId'] ?? '',
                    'surveyId',
                    200,
                    true
                );

                $target = validateString(
                    $request['status'] ?? '',
                    'status',
                    30,
                    true
                );

                if (!in_array(
                    $target,
                    ['draft', 'published', 'stopped'],
                    true
                )) {
                    apiError('STATUS', '指定できない状態です。');
                }

                $surveys = readJson(JSON_SURVEYS, []);

                $found = false;

                foreach ($surveys as &$survey) {
                    if (($survey['surveyId'] ?? '') !== $surveyId) {
                        continue;
                    }

                    $found = true;

                    expireSurveyIfNeeded($survey);

                    $current = (string)$survey['status'];

                    if (!allowedStatus($current, $target)) {
                        apiError(
                            'INVALID_TRANSITION',
                            "状態 {$current} から {$target} へ変更できません。"
                        );
                    }

                    $survey['status'] = $target;
                    $survey['updatedAt'] = nowIso();
                    break;
                }
                unset($survey);

                if (!$found) {
                    apiError('NOT_FOUND', 'アンケートが見つかりません。', 404);
                }

                saveSurveys($surveys);

                jsonResponse(true, [
                    'surveyId' => $surveyId,
                    'status' => $target
                ]);
            }

            /* =============================================================
             * Delete survey
             * ============================================================= */

            case 'delete_survey': {
                $surveyId = validateString(
                    $request['surveyId'] ?? '',
                    'surveyId',
                    200,
                    true
                );

                $surveys = readJson(JSON_SURVEYS, []);

                $new = [];
                $found = false;

                foreach ($surveys as $survey) {
                    if (($survey['surveyId'] ?? '') === $surveyId) {
                        $found = true;
                        continue;
                    }

                    $new[] = $survey;
                }

                if (!$found) {
                    apiError('NOT_FOUND', 'アンケートが見つかりません。', 404);
                }

                saveSurveys($new);

                /*
                 * 回答・履歴は監査上残すことも考えられるが、
                 * 要件の「関連データ削除または整合性維持」に従い
                 * 回答は削除し、履歴は残す。
                 */
                $responses = readJson(JSON_RESPONSES, []);
                $responses = array_values(array_filter(
                    $responses,
                    fn($r) => ($r['surveyId'] ?? '') !== $surveyId
                ));
                saveResponses($responses);

                jsonResponse(true, [
                    'message' => 'アンケートを削除しました。'
                ]);
            }

            /* =============================================================
             * Duplicate
             * ============================================================= */

            case 'duplicate_survey': {
                $surveyId = validateString(
                    $request['surveyId'] ?? '',
                    'surveyId',
                    200,
                    true
                );

                $surveys = readJson(JSON_SURVEYS, []);
                $source = findSurvey($surveys, $surveyId);

                if (!$source) {
                    apiError('NOT_FOUND', '複製元アンケートが見つかりません。', 404);
                }

                $copy = $source;

                $copy['surveyId'] = uid('survey');
                $copy['status'] = 'draft';
                $copy['createdAt'] = nowIso();
                $copy['updatedAt'] = nowIso();

                $groupIdMap = [];
                $questionIdMap = [];
                $choiceIdMap = [];

                foreach ($copy['groups'] as &$group) {
                    $oldGroupId = $group['groupId'];
                    $newGroupId = uid('group');

                    $groupIdMap[$oldGroupId] = $newGroupId;
                    $group['groupId'] = $newGroupId;

                    foreach ($group['questions'] as &$question) {
                        $oldQuestionId = $question['questionId'];
                        $newQuestionId = uid('question');

                        $questionIdMap[$oldQuestionId] = $newQuestionId;
                        $question['questionId'] = $newQuestionId;
                        $question['groupId'] = $newGroupId;

                        foreach ($question['choices'] as &$choice) {
                            $oldChoiceId = $choice['choiceId'];
                            $newChoiceId = uid('choice');

                            $choiceIdMap[$oldChoiceId] = $newChoiceId;
                            $choice['choiceId'] = $newChoiceId;
                        }
                        unset($choice);
                    }
                    unset($question);
                }
                unset($group);

                foreach ($copy['groups'] as &$group) {
                    foreach ($group['questions'] as &$question) {
                        foreach ($question['branchRules'] as &$rule) {
                            $rule['questionId'] =
                                $questionIdMap[$rule['questionId']]
                                ?? $question['questionId'];

                            $rule['choiceId'] =
                                $choiceIdMap[$rule['choiceId']]
                                ?? $rule['choiceId'];

                            $rule['nextQuestionId'] =
                                $questionIdMap[$rule['nextQuestionId']]
                                ?? $rule['nextQuestionId'];
                        }
                        unset($rule);
                    }
                    unset($question);
                }
                unset($group);

                recalculateSurvey($copy);

                $surveys[] = $copy;
                saveSurveys($surveys);

                jsonResponse(true, [
                    'survey' => $copy,
                    'message' => 'アンケートを複製しました。'
                ]);
            }

            /* =============================================================
             * Save response
             * ============================================================= */

            case 'save_response': {
                $surveyId = validateString(
                    $request['surveyId'] ?? '',
                    'surveyId',
                    200,
                    true
                );

                $token = validateString(
                    $request['answerToken'] ?? '',
                    'answerToken',
                    300,
                    false
                );

                $respondent = is_array($request['respondent'] ?? null)
                    ? $request['respondent']
                    : [];

                $answers = is_array($request['answers'] ?? null)
                    ? $request['answers']
                    : [];

                $surveys = readJson(JSON_SURVEYS, []);
                $changed = applyExpirationToAll($surveys);

                if ($changed) {
                    saveSurveys($surveys);
                }

                $survey = findSurvey($surveys, $surveyId);

                if (!$survey) {
                    apiError('NOT_FOUND', 'アンケートが見つかりません。', 404);
                }

                if (($survey['status'] ?? '') !== 'published') {
                    apiError('NOT_AVAILABLE', 'このアンケートは現在回答できません。');
                }

                $responses = readJson(JSON_RESPONSES, []);

                if (!$survey['allowReanswer'] && $token !== '') {
                    foreach ($responses as $old) {
                        if (
                            ($old['surveyId'] ?? '') === $surveyId &&
                            ($old['answerToken'] ?? '') === $token &&
                            ($old['status'] ?? '') === 'completed'
                        ) {
                            apiError(
                                'ALREADY_ANSWERED',
                                'このアンケートは回答済みです。'
                            );
                        }
                    }
                }

                /*
                 * 必須検証。
                 */
                $questionMap = questionMap($survey);

                foreach ($questionMap as $questionId => $question) {
                    if (!$question['required']) {
                        continue;
                    }

                    $answer = $answers[$questionId] ?? null;

                    $empty = false;

                    if ($question['type'] === 'multiple') {
                        $empty = !is_array($answer) || count($answer) === 0;
                    } else {
                        $empty = $answer === null ||
                            (is_string($answer) && trim($answer) === '');
                    }

                    if ($empty) {
                        apiError(
                            'REQUIRED',
                            "{$question['questionNumber']}「{$question['questionText']}」は必須です。"
                        );
                    }
                }

                /*
                 * 選択肢の整合性検証。
                 */
                foreach ($questionMap as $questionId => $question) {
                    if (!array_key_exists($questionId, $answers)) {
                        continue;
                    }

                    $value = $answers[$questionId];

                    if ($question['type'] === 'text') {
                        if (!is_string($value)) {
                            apiError('VALIDATION', '自由記述回答が不正です。');
                        }

                        if (mb_strlen($value) > 10000) {
                            apiError('VALIDATION', '自由記述回答が長すぎます。');
                        }
                    } elseif ($question['type'] === 'single') {
                        $valid = array_column($question['choices'], 'choiceId');

                        if ($value !== '' && !in_array((string)$value, $valid, true)) {
                            apiError('VALIDATION', '選択回答が不正です。');
                        }
                    } elseif ($question['type'] === 'multiple') {
                        if (!is_array($value)) {
                            apiError('VALIDATION', '複数選択回答が不正です。');
                        }

                        $valid = array_column($question['choices'], 'choiceId');

                        foreach ($value as $choiceId) {
                            if (!in_array((string)$choiceId, $valid, true)) {
                                apiError('VALIDATION', '選択回答が不正です。');
                            }
                        }
                    }
                }

                $response = [
                    'responseId' => uid('response'),
                    'surveyId' => $surveyId,
                    'answerToken' => $token !== '' ? $token : uid('token'),
                    'respondentId' => (string)($respondent['respondentId'] ?? ''),
                    'customerId' => (string)($respondent['customerId'] ?? ''),
                    'respondent' => [
                        'organizationName' => (string)($respondent['organizationName'] ?? ''),
                        'name' => (string)($respondent['name'] ?? ''),
                        'email' => (string)($respondent['email'] ?? ''),
                        'department' => (string)($respondent['department'] ?? ''),
                        'phone' => (string)($respondent['phone'] ?? ''),
                        'address' => is_array($respondent['address'] ?? null)
                            ? $respondent['address']
                            : []
                    ],
                    'answers' => $answers,
                    'status' => 'completed',
                    'createdAt' => nowIso(),
                    'updatedAt' => nowIso()
                ];

                $responses[] = $response;
                saveResponses($responses);

                /*
                 * customerIdがある場合は回答済みに更新。
                 */
                $customerId = $response['customerId'];

                if ($customerId !== '') {
                    $customers = readJson(JSON_CUSTOMERS, []);

                    foreach ($customers as &$customer) {
                        if (($customer['customerId'] ?? '') === $customerId) {
                            $customer['answerStatus'] = '回答済み';
                            $customer['updatedAt'] = nowIso();
                            break;
                        }
                    }
                    unset($customer);

                    saveCustomers($customers);
                }

                jsonResponse(true, [
                    'responseId' => $response['responseId'],
                    'answerToken' => $response['answerToken'],
                    'message' => '回答を送信しました。'
                ]);
            }

            /* =============================================================
             * Check response
             * ============================================================= */

            case 'check_response': {
                $surveyId = validateString(
                    $request['surveyId'] ?? '',
                    'surveyId',
                    200,
                    true
                );

                $token = validateString(
                    $request['answerToken'] ?? '',
                    'answerToken',
                    300,
                    true
                );

                $surveys = readJson(JSON_SURVEYS, []);
                $changed = applyExpirationToAll($surveys);

                if ($changed) {
                    saveSurveys($surveys);
                }

                $survey = findSurvey($surveys, $surveyId);

                if (!$survey) {
                    apiError('NOT_FOUND', 'アンケートが見つかりません。', 404);
                }

                $responses = readJson(JSON_RESPONSES, []);

                foreach ($responses as $response) {
                    if (
                        ($response['surveyId'] ?? '') === $surveyId &&
                        ($response['answerToken'] ?? '') === $token &&
                        ($response['status'] ?? '') === 'completed'
                    ) {
                        jsonResponse(true, [
                            'answered' => true,
                            'allowReanswer' => (bool)$survey['allowReanswer']
                        ]);
                    }
                }

                jsonResponse(true, [
                    'answered' => false,
                    'allowReanswer' => (bool)$survey['allowReanswer']
                ]);
            }

            /* =============================================================
             * Save mail
             * ============================================================= */

            case 'save_mail': {
                $current = readJson(JSON_MAIL, defaultMail());

                $mail = [
                    'smtpServer' => validateString(
                        $request['smtpServer'] ?? '',
                        'SMTPサーバ',
                        500
                    ),
                    'smtpPort' => (int)($request['smtpPort'] ?? 587),
                    'encryption' => in_array(
                        $request['encryption'] ?? 'tls',
                        ['none', 'tls', 'ssl'],
                        true
                    ) ? $request['encryption'] : 'tls',
                    'auth' => filter_var(
                        $request['auth'] ?? true,
                        FILTER_VALIDATE_BOOLEAN
                    ),
                    'username' => validateString(
                        $request['username'] ?? '',
                        'SMTPユーザー名',
                        500
                    ),
                    'password' => array_key_exists('password', $request) &&
                        trim((string)$request['password']) !== ''
                        ? (string)$request['password']
                        : (string)($current['password'] ?? ''),
                    'fromEmail' => validateString(
                        $request['fromEmail'] ?? '',
                        '送信元メールアドレス',
                        500
                    ),
                    'fromName' => validateString(
                        $request['fromName'] ?? '',
                        '送信元名',
                        500
                    ),
                    'replyTo' => validateString(
                        $request['replyTo'] ?? '',
                        '返信先メールアドレス',
                        500
                    ),
                    'status' => '未設定',
                    'updatedAt' => nowIso()
                ];

                if ($mail['smtpPort'] < 1 || $mail['smtpPort'] > 65535) {
                    apiError('VALIDATION', 'SMTPポートが不正です。');
                }

                saveMail($mail);

                $public = $mail;
                $public['password'] = '';
                $public['hasPassword'] = $mail['password'] !== '';

                jsonResponse(true, [
                    'mail' => $public,
                    'message' => 'メール設定を保存しました。'
                ]);
            }

            /* =============================================================
             * Test mail
             * ============================================================= */

            case 'test_mail': {
                $to = validateString(
                    $request['to'] ?? '',
                    'テスト送信先',
                    500,
                    true
                );

                $mail = readJson(JSON_MAIL, defaultMail());

                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    apiError('VALIDATION', 'テスト送信先メールアドレスが不正です。');
                }

                try {
                    smtpSendMail(
                        $mail,
                        $to,
                        'アンケート管理システム テストメール',
                        "SMTP接続テストです。\n\n送信日時: " . nowIso()
                    );

                    $mail['status'] = '接続確認済み';
                    saveMail($mail);

                    jsonResponse(true, [
                        'success' => true,
                        'message' => 'テストメールを送信しました。'
                    ]);
                } catch (Throwable $e) {
                    $mail['status'] = '接続できません';
                    saveMail($mail);

                    jsonResponse(false, null, 'SMTP_ERROR', $e->getMessage());
                }
            }

            /* =============================================================
             * Send mail
             * ============================================================= */

            case 'send_mail': {
                $surveyId = validateString(
                    $request['surveyId'] ?? '',
                    'surveyId',
                    200,
                    true
                );

                $customerIds = $request['customerIds'] ?? [];

                if (!is_array($customerIds)) {
                    apiError('VALIDATION', '顧客選択が不正です。');
                }

                if (count($customerIds) === 0) {
                    apiError('NO_RECIPIENT', '送信対象顧客を選択してください。');
                }

                $subject = validateString(
                    $request['subject'] ?? '',
                    '件名',
                    1000,
                    true
                );

                $body = validateString(
                    $request['body'] ?? '',
                    '本文',
                    50000,
                    true
                );

                $type = in_array(
                    $request['sendType'] ?? 'bulk',
                    ['bulk', 'resend', 'reminder'],
                    true
                ) ? $request['sendType'] : 'bulk';

                $surveys = readJson(JSON_SURVEYS, []);
                $changed = applyExpirationToAll($surveys);

                if ($changed) {
                    saveSurveys($surveys);
                }

                $survey = findSurvey($surveys, $surveyId);

                if (!$survey) {
                    apiError('NOT_FOUND', '対象アンケートが見つかりません。', 404);
                }

                $customers = readJson(JSON_CUSTOMERS, []);
                $mail = readJson(JSON_MAIL, defaultMail());

                $selected = [];

                foreach ($customers as $customer) {
                    if (in_array(
                        (string)$customer['customerId'],
                        array_map('strval', $customerIds),
                        true
                    )) {
                        $selected[] = $customer;
                    }
                }

                if (!$selected) {
                    apiError('NO_RECIPIENT', '有効な顧客がありません。');
                }

                /*
                 * リマインドは送信済み/未回答のみ。
                 */
                if ($type === 'reminder') {
                    $selected = array_values(array_filter(
                        $selected,
                        fn($c) => ($c['answerStatus'] ?? '') === '送信済み / 未回答'
                    ));

                    if (!$selected) {
                        apiError(
                            'NO_REMINDER_TARGET',
                            '送信済み / 未回答の顧客がありません。'
                        );
                    }
                }

                $history = readJson(JSON_HISTORY, []);
                $results = [];

                $success = 0;
                $failure = 0;

                foreach ($selected as $customer) {
                    $name = (string)($customer['name'] ?? '');
                    $email = (string)($customer['email'] ?? '');

                    /*
                     * 個別URL。
                     */
                    $base = (
                        (!empty($_SERVER['HTTPS']) &&
                         $_SERVER['HTTPS'] !== 'off')
                            ? 'https'
                            : 'http'
                    ) . '://' .
                    ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                    strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?');

                    $token = hash(
                        'sha256',
                        $surveyId . ':' . $customer['customerId']
                    );

                    $answerUrl =
                        $base .
                        '?view=answer' .
                        '&survey=' . rawurlencode($surveyId) .
                        '&token=' . rawurlencode($token);

                    $expandedSubject = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [$name, $answerUrl],
                        $subject
                    );

                    $expandedBody = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [$name, $answerUrl],
                        $body
                    );

                    try {
                        smtpSendMail(
                            $mail,
                            $email,
                            $expandedSubject,
                            $expandedBody
                        );

                        $ok = true;
                        $error = '';
                        $success++;

                        foreach ($customers as &$c) {
                            if ($c['customerId'] === $customer['customerId']) {
                                $c['lastSentAt'] = nowIso();
                                $c['sendCount'] =
                                    (int)($c['sendCount'] ?? 0) + 1;
                                $c['answerStatus'] = '送信済み / 未回答';
                                $c['updatedAt'] = nowIso();
                                break;
                            }
                        }
                        unset($c);
                    } catch (Throwable $e) {
                        $ok = false;
                        $error = $e->getMessage();
                        $failure++;
                    }

                    $results[] = [
                        'customerId' => $customer['customerId'],
                        'customerName' => $name,
                        'email' => $email,
                        'success' => $ok,
                        'error' => $error,
                        'subject' => $expandedSubject,
                        'body' => $expandedBody,
                        'answerUrl' => $answerUrl
                    ];
                }

                saveCustomers($customers);

                $history[] = [
                    'historyId' => uid('history'),
                    'surveyId' => $surveyId,
                    'sentAt' => nowIso(),
                    'sendType' => $type,
                    'count' => count($selected),
                    'successCount' => $success,
                    'failureCount' => $failure,
                    'subject' => $subject,
                    'body' => $body,
                    'executedBy' => '管理画面',
                    'targets' => $results
                ];

                saveHistory($history);

                jsonResponse(true, [
                    'targetCount' => count($selected),
                    'successCount' => $success,
                    'failureCount' => $failure,
                    'sentAt' => nowIso(),
                    'results' => $results,
                    'message' => '送信処理が完了しました。'
                ]);
            }

            /* =============================================================
             * Save kintone settings
             * ============================================================= */

            case 'save_kintone': {
                $current = readJson(JSON_KINTONE, defaultKintone());

                $subdomain = validateString(
                    $request['subdomain'] ?? '',
                    'サブドメイン',
                    500
                );

                if ($subdomain !== '') {
                    try {
                        $subdomain = normalizeKintoneSubdomain($subdomain);
                    } catch (Throwable $e) {
                        apiError('VALIDATION', $e->getMessage());
                    }
                }

                $appId = validateString(
                    $request['appId'] ?? '',
                    '顧客管理アプリID',
                    100
                );

                if ($appId !== '' && !ctype_digit($appId)) {
                    apiError('VALIDATION', 'アプリIDは数値で入力してください。');
                }

                $password = array_key_exists('password', $request) &&
                    trim((string)$request['password']) !== ''
                    ? (string)$request['password']
                    : (string)($current['settings']['password'] ?? '');

                $proxy = validateString(
                    $request['proxy'] ?? '',
                    'プロキシ',
                    500
                );

                if ($proxy !== '') {
                    try {
                        $proxy = validateProxy($proxy);
                    } catch (Throwable $e) {
                        apiError('VALIDATION', $e->getMessage());
                    }
                }

                $data = $current;

                $data['settings'] = [
                    'subdomain' => $subdomain,
                    'appId' => $appId,
                    'loginName' => validateString(
                        $request['loginName'] ?? '',
                        'ログイン名',
                        500
                    ),
                    'password' => $password,
                    'sslVerify' => filter_var(
                        $request['sslVerify'] ?? false,
                        FILTER_VALIDATE_BOOLEAN
                    ),
                    'proxy' => $proxy
                ];

                $data['updatedAt'] = nowIso();

                saveKintone($data);

                $public = $data;
                $public['settings']['password'] = '';
                $public['settings']['hasPassword'] = $password !== '';

                jsonResponse(true, [
                    'kintone' => $public,
                    'message' => 'kintone設定を保存しました。'
                ]);
            }

            /* =============================================================
             * kintone test
             * ============================================================= */

            case 'kintone_test': {
                $data = readJson(JSON_KINTONE, defaultKintone());
                $settings = kintoneSettingsFromData($data);

                try {
                    /*
                     * 接続テストはフィールド取得・同期を行わない。
                     * app情報取得だけを実行。
                     */
                    $result = kintoneCurl(
                        $settings,
                        'GET',
                        '/k/v1/app.json?app=' .
                        rawurlencode($settings['appId'])
                    );

                    jsonResponse(true, [
                        'success' => true,
                        'message' => 'kintone接続成功',
                        'appName' => $result['name'] ?? '',
                        'elapsed' => $result['_elapsed'] ?? null
                    ]);
                } catch (Throwable $e) {
                    jsonResponse(
                        false,
                        null,
                        'KINTONE_CONNECTION_ERROR',
                        $e->getMessage()
                    );
                }
            }

            /* =============================================================
             * kintone fields
             * ============================================================= */

            case 'kintone_fields': {
                $data = readJson(JSON_KINTONE, defaultKintone());
                $settings = kintoneSettingsFromData($data);

                try {
                    $fields = kintoneGetFields($settings);

                    $data['fields'] = $fields;
                    $data['updatedAt'] = nowIso();

                    saveKintone($data);

                    jsonResponse(true, [
                        'fields' => $fields,
                        'message' => '項目一覧を取得しました。'
                    ]);
                } catch (Throwable $e) {
                    jsonResponse(
                        false,
                        null,
                        'KINTONE_FIELDS_ERROR',
                        $e->getMessage()
                    );
                }
            }

            /* =============================================================
             * kintone mapping
             * ============================================================= */

            case 'save_kintone_mapping': {
                $data = readJson(JSON_KINTONE, defaultKintone());

                $address = $request['address'] ?? [];

                if (!is_array($address)) {
                    apiError('VALIDATION', '住所マッピングが不正です。');
                }

                $data['mapping'] = [
                    'organizationName' => (string)($request['organizationName'] ?? ''),
                    'name' => (string)($request['name'] ?? ''),
                    'email' => (string)($request['email'] ?? ''),
                    'department' => (string)($request['department'] ?? ''),
                    'phone' => (string)($request['phone'] ?? ''),
                    'address' => array_values(array_map('strval', $address))
                ];

                $data['updatedAt'] = nowIso();

                saveKintone($data);

                jsonResponse(true, [
                    'mapping' => $data['mapping'],
                    'message' => 'フィールドマッピングを保存しました。'
                ]);
            }

            /* =============================================================
             * kintone sync
             * ============================================================= */

            case 'kintone_sync': {
                $data = readJson(JSON_KINTONE, defaultKintone());
                $settings = kintoneSettingsFromData($data);

                try {
                    /*
                     * 顧客同期は項目取得を内部で行わず、
                     * 保存済みマッピングを使用する。
                     */
                    $records = kintoneGetRecords($settings);

                    $mapping = $data['mapping'] ?? [];
                    $customers = readJson(JSON_CUSTOMERS, []);

                    $existingByEmail = [];

                    foreach ($customers as $idx => $customer) {
                        if (!empty($customer['email'])) {
                            $existingByEmail[
                                strtolower((string)$customer['email'])
                            ] = $idx;
                        }
                    }

                    $added = 0;
                    $updated = 0;

                    foreach ($records as $record) {
                        $organizationName =
                            kintoneFieldValue(
                                $record,
                                (string)($mapping['organizationName'] ?? '')
                            );

                        $name =
                            kintoneFieldValue(
                                $record,
                                (string)($mapping['name'] ?? '')
                            );

                        $email =
                            kintoneFieldValue(
                                $record,
                                (string)($mapping['email'] ?? '')
                            );

                        $department =
                            kintoneFieldValue(
                                $record,
                                (string)($mapping['department'] ?? '')
                            );

                        $phone =
                            kintoneFieldValue(
                                $record,
                                (string)($mapping['phone'] ?? '')
                            );

                        $addressCodes = $mapping['address'] ?? [];

                        $address = [
                            'postalCode' => '',
                            'prefecture' => '',
                            'city' => '',
                            'street' => '',
                            'building' => ''
                        ];

                        $addressKeys = [
                            'postalCode',
                            'prefecture',
                            'city',
                            'street',
                            'building'
                        ];

                        foreach ($addressCodes as $i => $code) {
                            if (!isset($addressKeys[$i])) {
                                break;
                            }

                            $address[$addressKeys[$i]] =
                                kintoneFieldValue($record, (string)$code);
                        }

                        if ($email !== '' &&
                            filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $key = strtolower($email);

                            if (isset($existingByEmail[$key])) {
                                $idx = $existingByEmail[$key];

                                $customers[$idx]['organizationName'] =
                                    $organizationName;

                                $customers[$idx]['name'] = $name;
                                $customers[$idx]['department'] = $department;
                                $customers[$idx]['phone'] = $phone;
                                $customers[$idx]['address'] = $address;
                                $customers[$idx]['kintoneStatus'] = '同期済み';
                                $customers[$idx]['updatedAt'] = nowIso();

                                $updated++;
                            } else {
                                $customer = [
                                    'customerId' => uid('customer'),
                                    'organizationName' => $organizationName,
                                    'name' => $name,
                                    'email' => $email,
                                    'department' => $department,
                                    'phone' => $phone,
                                    'address' => $address,
                                    'lastSentAt' => null,
                                    'sendCount' => 0,
                                    'answerStatus' => '未送信',
                                    'kintoneStatus' => '同期済み',
                                    'createdAt' => nowIso(),
                                    'updatedAt' => nowIso()
                                ];

                                $customers[] = $customer;
                                $existingByEmail[$key] = count($customers) - 1;
                                $added++;
                            }
                        }
                    }

                    saveCustomers($customers);

                    jsonResponse(true, [
                        'message' => '顧客同期完了',
                        'records' => count($records),
                        'added' => $added,
                        'updated' => $updated
                    ]);
                } catch (Throwable $e) {
                    jsonResponse(
                        false,
                        null,
                        'KINTONE_SYNC_ERROR',
                        $e->getMessage()
                    );
                }
            }

            /* =============================================================
             * Logout UI reset
             * ============================================================= */

            case 'logout': {
                /*
                 * 認証は存在しないため、サーバー側セッション等は操作しない。
                 */
                jsonResponse(true, [
                    'message' => '画面状態を初期化しました。'
                ]);
            }

            default:
                apiError('UNKNOWN_ACTION', '未対応の操作です。');
        }
    }

    /*
     * 回答者URL判定。
     */
    $initialView = $_GET['view'] ?? '';
    $initialSurvey = $_GET['survey'] ?? '';
    $initialToken = $_GET['token'] ?? '';

} catch (Throwable $e) {
    /*
     * POSTの場合はHTMLを返さない。
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        jsonResponse(
            false,
            null,
            'PHP_ERROR',
            $e->getMessage(),
            500
        );
    }

    $initialView = '';
    $initialSurvey = '';
    $initialToken = '';
}

/* =========================================================================
 * HTML
 * ========================================================================= */
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>アンケート管理システム</title>

<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --danger: #dc2626;
    --warning: #d97706;
    --success: #16a34a;
    --text: #1f2937;
    --muted: #6b7280;
    --border: #e5e7eb;
    --bg: #f3f4f6;
    --surface: #fff;
    --shadow: 0 2px 12px rgba(0,0,0,.07);
    --radius: 10px;
}

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
    color: var(--text);
    background: var(--bg);
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
    background: #111827;
    color: #fff;
    padding: 0 24px;
    min-height: 58px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

.app-header h1 {
    font-size: 18px;
    margin: 0;
}

.nav {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.nav button {
    border: 0;
    color: #fff;
    background: transparent;
    padding: 10px 12px;
    border-radius: 7px;
}

.nav button:hover {
    background: rgba(255,255,255,.1);
}

.container {
    width: min(1400px, calc(100% - 32px));
    margin: 24px auto 50px;
}

.page-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 20px;
}

.page-title h2 {
    margin: 0;
    font-size: 25px;
}

.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 20px;
    margin-bottom: 18px;
}

.toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.btn {
    border: 1px solid var(--border);
    background: #fff;
    color: var(--text);
    padding: 9px 14px;
    min-height: 42px;
    border-radius: 8px;
}

.btn:hover {
    background: #f9fafb;
}

.btn-primary {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

.btn-primary:hover {
    background: var(--primary-dark);
}

.btn-danger {
    background: var(--danger);
    color: #fff;
    border-color: var(--danger);
}

.btn-success {
    background: var(--success);
    color: #fff;
    border-color: var(--success);
}

.btn-warning {
    background: var(--warning);
    color: #fff;
    border-color: var(--warning);
}

.btn-small {
    min-height: 34px;
    padding: 6px 10px;
    font-size: 13px;
}

.input,
select,
textarea {
    width: 100%;
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 7px;
    padding: 10px 12px;
    min-height: 42px;
}

textarea {
    min-height: 110px;
    resize: vertical;
}

label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.form-full {
    grid-column: 1 / -1;
}

.field {
    min-width: 0;
}

.muted {
    color: var(--muted);
    font-size: 13px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}

th,
td {
    border-bottom: 1px solid var(--border);
    padding: 12px 10px;
    text-align: left;
    vertical-align: middle;
}

th {
    background: #f9fafb;
    font-size: 13px;
    white-space: nowrap;
}

.status {
    display: inline-flex;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
}

.status-draft {
    background: #e5e7eb;
    color: #374151;
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

.filters {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 15px;
}

.filter-btn.active {
    background: #111827;
    color: #fff;
}

.group {
    border: 1px solid var(--border);
    border-radius: 10px;
    margin-bottom: 16px;
    background: #fafafa;
}

.group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px;
    border-bottom: 1px solid var(--border);
    background: #f3f4f6;
    cursor: move;
}

.group-title {
    font-weight: 700;
    flex: 1;
}

.question-list {
    padding: 10px;
    min-height: 60px;
}

.question {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 10px;
    cursor: move;
}

.question:last-child {
    margin-bottom: 0;
}

.question-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.question-number {
    font-weight: 800;
    color: var(--primary);
    white-space: nowrap;
}

.question-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.choice-row {
    display: flex;
    gap: 7px;
    align-items: center;
    margin-bottom: 7px;
}

.choice-row input {
    flex: 1;
}

.branch-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 8px;
}

.add-row {
    margin-top: 10px;
}

.section-title {
    margin: 0 0 12px;
    font-size: 18px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
}

.summary-item {
    background: #f9fafb;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 15px;
}

.summary-label {
    color: var(--muted);
    font-size: 12px;
}

.summary-value {
    font-size: 24px;
    font-weight: 800;
    margin-top: 5px;
}

.bar-chart {
    display: flex;
    flex-direction: column;
    gap: 9px;
}

.bar-row {
    display: grid;
    grid-template-columns: 150px 1fr 80px;
    align-items: center;
    gap: 10px;
}

.bar-track {
    height: 22px;
    background: #e5e7eb;
    border-radius: 999px;
    overflow: hidden;
}

.bar-fill {
    height: 100%;
    background: var(--primary);
}

.preview-shell {
    border: 1px solid #d1d5db;
    border-radius: 10px;
    overflow: hidden;
    background: #fff;
}

.preview-toolbar {
    background: #f3f4f6;
    padding: 8px;
    display: flex;
    justify-content: center;
    gap: 8px;
}

.preview-device {
    margin: 20px auto;
    border: 1px solid #d1d5db;
    border-radius: 12px;
    overflow: hidden;
    min-height: 500px;
    background: #fff;
}

.preview-device.pc {
    width: 95%;
}

.preview-device.mobile {
    width: min(390px, 95%);
}

.respondent-shell {
    min-height: 100vh;
    background: #f3f4f6;
}

.respondent-main {
    width: min(760px, calc(100% - 24px));
    margin: 0 auto;
    padding: 25px 0 60px;
}

.respondent-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 22px;
}

.respondent-header {
    background: #fff;
    border-bottom: 1px solid var(--border);
    padding: 18px;
}

.respondent-header-inner {
    width: min(760px, calc(100% - 24px));
    margin: 0 auto;
}

.answer-choice {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid #d1d5db;
    border-radius: 9px;
    padding: 14px;
    margin-bottom: 9px;
    cursor: pointer;
}

.answer-choice:hover {
    background: #f9fafb;
}

.answer-choice input {
    width: 20px;
    height: 20px;
}

.answer-question {
    margin-bottom: 25px;
}

.required-mark {
    color: var(--danger);
    margin-left: 5px;
}

.error-box {
    color: #991b1b;
    background: #fee2e2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 10px 12px;
    margin: 10px 0;
}

.success-box {
    color: #166534;
    background: #dcfce7;
    border: 1px solid #bbf7d0;
    border-radius: 8px;
    padding: 10px 12px;
    margin: 10px 0;
}

.warning-box {
    color: #92400e;
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 10px 12px;
    margin: 10px 0;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(17,24,39,.55);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 9999;
}

.modal {
    width: min(650px, 100%);
    max-height: 90vh;
    overflow: auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 20px 50px rgba(0,0,0,.25);
}

.modal-header,
.modal-footer {
    padding: 15px 18px;
}

.modal-header {
    border-bottom: 1px solid var(--border);
}

.modal-footer {
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.modal-body {
    padding: 18px;
}

.toast-area {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: min(420px, calc(100% - 40px));
}

.toast {
    padding: 12px 15px;
    border-radius: 8px;
    background: #111827;
    color: #fff;
    box-shadow: var(--shadow);
}

.toast.error {
    background: #991b1b;
}

.toast.success {
    background: #166534;
}

.inline-check {
    display: flex;
    align-items: center;
    gap: 8px;
}

.inline-check input {
    width: auto;
}

.detail-list {
    display: grid;
    gap: 8px;
}

.detail-item {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px;
    background: #fafafa;
}

.email-preview {
    white-space: pre-wrap;
    background: #f9fafb;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px;
}

.history-item {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 8px;
}

.history-target {
    border-top: 1px dashed var(--border);
    padding-top: 8px;
    margin-top: 8px;
}

.kintone-field-list {
    max-height: 350px;
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: 8px;
}

.kintone-field {
    padding: 9px 10px;
    border-bottom: 1px solid var(--border);
    display: grid;
    grid-template-columns: 1fr 1fr 130px;
    gap: 8px;
}

.empty {
    padding: 40px 15px;
    text-align: center;
    color: var(--muted);
}

.mobile-stack {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

@media (max-width: 1000px) {
    .summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {
    .app-header {
        padding: 10px 14px;
        align-items: flex-start;
        flex-direction: column;
    }

    .nav {
        width: 100%;
        overflow-x: auto;
        flex-wrap: nowrap;
    }

    .container {
        width: min(100% - 20px, 1400px);
        margin-top: 15px;
    }

    .page-title {
        align-items: flex-start;
        flex-direction: column;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .branch-row {
        grid-template-columns: 1fr;
    }

    .bar-row {
        grid-template-columns: 1fr;
        gap: 3px;
    }

    .question-head {
        flex-direction: column;
    }

    .btn {
        min-height: 44px;
    }

    .respondent-card {
        padding: 16px;
    }
}

@media (max-width: 420px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<div id="adminApp">
    <header class="app-header">
        <h1>アンケート管理システム</h1>

        <nav class="nav">
            <button type="button" onclick="App.show('admin-survey-list')">
                アンケート一覧
            </button>
            <button type="button" onclick="App.show('admin-kintone')">
                kintone連携設定
            </button>
            <button type="button" onclick="App.show('admin-mail')">
                メールサーバ設定
            </button>
            <button type="button" onclick="App.logout()">
                ログアウト
            </button>
        </nav>
    </header>

    <main id="adminContainer" class="container"></main>
</div>

<div id="respondentApp" class="respondent-shell hidden">
    <div id="respondentContainer"></div>
</div>

<div id="modalRoot"></div>
<div id="toastArea" class="toast-area"></div>

<script>
'use strict';

/* =========================================================================
 * Application state
 * ========================================================================= */

const INITIAL_VIEW = <?= json_encode($initialView, JSON_UNESCAPED_UNICODE) ?>;
const INITIAL_SURVEY = <?= json_encode($initialSurvey, JSON_UNESCAPED_UNICODE) ?>;
const INITIAL_TOKEN = <?= json_encode($initialToken, JSON_UNESCAPED_UNICODE) ?>;

const App = {
    state: {
        currentView: 'admin-survey-list',

        surveys: [],
        customers: [],
        responses: [],
        history: [],
        kintone: null,
        mail: null,

        editingSurveyId: null,
        aggregationSurveyId: null,
        sendingSurveyId: null,

        selectedCustomerIds: new Set(),

        surveySearch: '',
        surveyFilter: 'all',
        surveySort: 'updated_desc',

        customerSearch: '',
        customerStatus: 'all',

        editingSurvey: null,
        previewSurvey: null,

        answerSurveyId: null,
        answerToken: '',
        answerRespondent: {
            respondentId: '',
            customerId: '',
            organizationName: '',
            name: '',
            email: '',
            department: '',
            phone: '',
            address: {}
        },
        answers: {},
        answerStep: 'answer',

        mailDraft: {
            subject: 'アンケートご協力のお願い',
            body:
                '{顧客名} 様\n\n' +
                'アンケートへのご協力をお願いいたします。\n\n' +
                '{アンケートURL}\n\n' +
                'よろしくお願いいたします。'
        },

        sendResult: null,
        aggregationSelection: new Set(),

        loading: false,
        initialError: null
    },

    async init() {
        try {
            await this.loadData();

            if (INITIAL_VIEW === 'answer' && INITIAL_SURVEY) {
                await this.startAnswer(
                    INITIAL_SURVEY,
                    INITIAL_TOKEN
                );
            } else {
                this.show('admin-survey-list');
            }
        } catch (error) {
            this.state.initialError = error;
            this.renderInitialError();
        }
    },

    async loadData() {
        this.state.loading = true;

        try {
            const result = await API.call('load_data', {});

            this.state.surveys = result.surveys || [];
            this.state.customers = result.customers || [];
            this.state.responses = result.responses || [];
            this.state.history = result.history || [];
            this.state.kintone = result.kintone || {};
            this.state.mail = result.mail || {};

            /*
             * 初期表示時に状態を整える。
             */
            this.state.surveys.forEach(Survey.normalize);

        } finally {
            this.state.loading = false;
        }
    },

    renderInitialError() {
        document.getElementById('adminApp').classList.remove('hidden');
        document.getElementById('respondentApp').classList.add('hidden');

        document.getElementById('adminContainer').innerHTML = `
            <div class="card">
                <h2>システムを起動できませんでした。</h2>
                <div class="error-box">
                    ${escapeHtml(
                        this.state.initialError?.message ||
                        '不明なエラー'
                    )}
                </div>
                <p class="muted">
                    HTTP通信、PHPエラー、JSON読み込みエラー等の
                    詳細を確認してください。
                </p>
            </div>
        `;
    },

    show(view) {
        if (
            view === 'admin-send' &&
            !this.state.sendingSurveyId
        ) {
            Toast.error('対象アンケートが指定されていません。');
            return;
        }

        if (
            view === 'admin-aggregation' &&
            !this.state.aggregationSurveyId
        ) {
            Toast.error('対象アンケートが指定されていません。');
            return;
        }

        if (view.startsWith('admin-')) {
            document.getElementById('adminApp').classList.remove('hidden');
            document.getElementById('respondentApp').classList.add('hidden');
            this.state.currentView = view;
            this.render();
        }
    },

    render() {
        const container = document.getElementById('adminContainer');

        switch (this.state.currentView) {
            case 'admin-survey-list':
                container.innerHTML = Views.surveyList();
                break;

            case 'admin-survey-edit':
                container.innerHTML = Views.surveyEdit();
                this.afterSurveyEditRender();
                break;

            case 'admin-preview':
                container.innerHTML = Views.preview();
                break;

            case 'admin-send':
                container.innerHTML = Views.send();
                break;

            case 'admin-aggregation':
                container.innerHTML = Views.aggregation();
                break;

            case 'admin-kintone':
                container.innerHTML = Views.kintone();
                break;

            case 'admin-mail':
                container.innerHTML = Views.mail();
                break;

            default:
                this.state.currentView = 'admin-survey-list';
                container.innerHTML = Views.surveyList();
        }
    },

    afterSurveyEditRender() {
        DnD.install();
    },

    /* ---------------------------------------------------------------------
     * Survey
     * ------------------------------------------------------------------ */

    newSurvey() {
        const survey = Survey.create();
        this.state.editingSurveyId = survey.surveyId;
        this.state.editingSurvey = survey;
        this.show('admin-survey-edit');
    },

    editSurvey(id) {
        const survey = this.findSurvey(id);

        if (!survey) {
            Toast.error('アンケートが見つかりません。');
            return;
        }

        this.state.editingSurveyId = id;
        this.state.editingSurvey = deepClone(survey);
        Survey.recalculate(this.state.editingSurvey);
        this.show('admin-survey-edit');
    },

    cancelEdit() {
        Modal.confirm(
            '編集内容を破棄しますか？',
            '保存していない変更は失われます。',
            () => {
                this.state.editingSurvey = null;
                this.state.editingSurveyId = null;
                this.show('admin-survey-list');
            }
        );
    },

    async saveSurvey() {
        const survey = this.state.editingSurvey;

        if (!survey) {
            return;
        }

        Survey.recalculate(survey);

        if (!survey.title.trim()) {
            Toast.error('アンケートタイトルを入力してください。');
            return;
        }

        try {
            const result = await API.call('save_survey', {
                survey
            });

            const saved = result.survey;

            const index = this.state.surveys.findIndex(
                s => s.surveyId === saved.surveyId
            );

            if (index >= 0) {
                this.state.surveys[index] = saved;
            } else {
                this.state.surveys.push(saved);
            }

            this.state.editingSurvey = null;
            this.state.editingSurveyId = null;

            Toast.success('保存しました。');
            this.show('admin-survey-list');
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async changeStatus(status) {
        const survey = this.state.editingSurvey;

        if (!survey) {
            return;
        }

        const labels = {
            published: '公開',
            stopped: '停止'
        };

        const label = labels[status] || status;

        Modal.confirm(
            `このアンケートを${label}しますか？`,
            '',
            async () => {
                try {
                    const result = await API.call(
                        'change_status',
                        {
                            surveyId: survey.surveyId,
                            status
                        }
                    );

                    survey.status = result.status;

                    const found = this.findSurvey(
                        survey.surveyId
                    );

                    if (found) {
                        found.status = result.status;
                        found.updatedAt = new Date().toISOString();
                    }

                    this.render();
                    Toast.success(`${label}しました。`);
                } catch (e) {
                    Toast.error(e.message);
                }
            }
        );
    },

    duplicateSurvey(id) {
        Modal.confirm(
            'このアンケートを複製しますか？',
            '回答・送信履歴・状態は複製されません。',
            async () => {
                try {
                    const result = await API.call(
                        'duplicate_survey',
                        { surveyId: id }
                    );

                    this.state.surveys.push(result.survey);
                    this.render();
                    Toast.success('アンケートを複製しました。');
                } catch (e) {
                    Toast.error(e.message);
                }
            }
        );
    },

    deleteSurvey(id) {
        Modal.confirm(
            'このアンケートを削除しますか？',
            '回答データも削除されます。この操作は元に戻せません。',
            async () => {
                try {
                    await API.call(
                        'delete_survey',
                        { surveyId: id }
                    );

                    this.state.surveys =
                        this.state.surveys.filter(
                            s => s.surveyId !== id
                        );

                    this.state.responses =
                        this.state.responses.filter(
                            r => r.surveyId !== id
                        );

                    this.render();

                    Toast.success('アンケートを削除しました。');
                } catch (e) {
                    Toast.error(e.message);
                }
            }
        );
    },

    previewSurvey() {
        if (!this.state.editingSurvey) {
            return;
        }

        Survey.recalculate(this.state.editingSurvey);

        this.state.previewSurvey =
            deepClone(this.state.editingSurvey);

        this.show('admin-preview');
    },

    /* ---------------------------------------------------------------------
     * Groups
     * ------------------------------------------------------------------ */

    addGroup() {
        const survey = this.state.editingSurvey;

        if (!survey) {
            return;
        }

        survey.groups.push({
            groupId: id('group'),
            title: '新しいグループ',
            sortOrder: survey.groups.length + 1,
            questions: []
        });

        Survey.recalculate(survey);
        this.render();
    },

    deleteGroup(groupId) {
        const survey = this.state.editingSurvey;
        const group = survey?.groups.find(
            g => g.groupId === groupId
        );

        if (!group) {
            return;
        }

        const hasQuestions =
            (group.questions || []).length > 0;

        Modal.confirm(
            'このグループを削除しますか？',
            hasQuestions
                ? '質問が存在します。グループと質問を削除します。'
                : 'グループを削除します。',
            () => {
                survey.groups =
                    survey.groups.filter(
                        g => g.groupId !== groupId
                    );

                Survey.recalculate(survey);
                this.render();
            }
        );
    },

    moveQuestion(questionId, targetGroupId, targetIndex = null) {
        const survey = this.state.editingSurvey;

        if (!survey) {
            return;
        }

        let question = null;

        for (const group of survey.groups) {
            const index = group.questions.findIndex(
                q => q.questionId === questionId
            );

            if (index >= 0) {
                question = group.questions.splice(index, 1)[0];
                break;
            }
        }

        if (!question) {
            return;
        }

        const targetGroup = survey.groups.find(
            g => g.groupId === targetGroupId
        );

        if (!targetGroup) {
            return;
        }

        question.groupId = targetGroupId;

        if (
            targetIndex === null ||
            targetIndex < 0 ||
            targetIndex > targetGroup.questions.length
        ) {
            targetGroup.questions.push(question);
        } else {
            targetGroup.questions.splice(
                targetIndex,
                0,
                question
            );
        }

        Survey.recalculate(survey);
        this.render();
    },

    /* ---------------------------------------------------------------------
     * Questions
     * ------------------------------------------------------------------ */

    addQuestion(groupId) {
        const survey = this.state.editingSurvey;

        const group = survey?.groups.find(
            g => g.groupId === groupId
        );

        if (!group) {
            return;
        }

        group.questions.push({
            questionId: id('question'),
            groupId,
            sortOrder: group.questions.length + 1,
            questionText: '',
            type: 'single',
            required: false,
            choices: [
                {
                    choiceId: id('choice'),
                    label: '選択肢1',
                    sortOrder: 1
                },
                {
                    choiceId: id('choice'),
                    label: '選択肢2',
                    sortOrder: 2
                }
            ],
            branchRules: []
        });

        Survey.recalculate(survey);
        this.render();
    },

    deleteQuestion(groupId, questionId) {
        const survey = this.state.editingSurvey;
        const group = survey?.groups.find(
            g => g.groupId === groupId
        );

        if (!group) {
            return;
        }

        Modal.confirm(
            'この質問を削除しますか？',
            '質問を削除すると、その質問を参照する条件分岐も整理されます。',
            () => {
                group.questions =
                    group.questions.filter(
                        q => q.questionId !== questionId
                    );

                for (const g of survey.groups) {
                    for (const q of g.questions) {
                        q.branchRules =
                            (q.branchRules || []).filter(
                                r =>
                                    r.nextQuestionId !== questionId &&
                                    r.questionId !== questionId
                            );
                    }
                }

                Survey.recalculate(survey);
                this.render();
            }
        );
    },

    updateQuestion(groupId, questionId, patch) {
        const q = this.getQuestion(
            this.state.editingSurvey,
            questionId
        );

        if (!q) {
            return;
        }

        Object.assign(q, patch);

        if (q.type !== 'single') {
            q.branchRules = [];
        }

        if (
            q.type === 'single' ||
            q.type === 'multiple'
        ) {
            if (!Array.isArray(q.choices)) {
                q.choices = [];
            }
        }

        Survey.recalculate(this.state.editingSurvey);
    },

    addChoice(questionId) {
        const q = this.getQuestion(
            this.state.editingSurvey,
            questionId
        );

        if (!q || q.type === 'text') {
            return;
        }

        q.choices.push({
            choiceId: id('choice'),
            label: '新しい選択肢',
            sortOrder: q.choices.length + 1
        });

        Survey.recalculate(this.state.editingSurvey);
        this.render();
    },

    deleteChoice(questionId, choiceId) {
        const q = this.getQuestion(
            this.state.editingSurvey,
            questionId
        );

        if (!q) {
            return;
        }

        q.choices =
            q.choices.filter(
                c => c.choiceId !== choiceId
            );

        q.branchRules =
            (q.branchRules || []).filter(
                r => r.choiceId !== choiceId
            );

        Survey.recalculate(this.state.editingSurvey);
        this.render();
    },

    updateChoice(questionId, choiceId, label) {
        const q = this.getQuestion(
            this.state.editingSurvey,
            questionId
        );

        if (!q) {
            return;
        }

        const choice = q.choices.find(
            c => c.choiceId === choiceId
        );

        if (choice) {
            choice.label = label;
        }
    },

    addBranchRule(questionId) {
        const q = this.getQuestion(
            this.state.editingSurvey,
            questionId
        );

        if (!q || q.type !== 'single') {
            return;
        }

        const choice = q.choices[0];

        if (!choice) {
            Toast.error('先に選択肢を追加してください。');
            return;
        }

        q.branchRules.push({
            questionId,
            choiceId: choice.choiceId,
            nextQuestionId:
                flattenQuestions(this.state.editingSurvey)
                    .find(
                        x => x.questionId !== questionId
                    )?.questionId || ''
        });

        this.render();
    },

    deleteBranchRule(questionId, index) {
        const q = this.getQuestion(
            this.state.editingSurvey,
            questionId
        );

        if (!q) {
            return;
        }

        q.branchRules.splice(index, 1);
        this.render();
    },

    /* ---------------------------------------------------------------------
     * Answer
     * ------------------------------------------------------------------ */

    async startAnswer(surveyId, token) {
        try {
            const survey = this.findSurvey(surveyId);

            if (!survey) {
                await this.loadData();
            }

            const found = this.findSurvey(surveyId);

            if (!found) {
                this.renderRespondentError(
                    'アンケートが見つかりません。'
                );
                return;
            }

            this.state.answerSurveyId = surveyId;
            this.state.answerToken = token || '';

            const check = await API.call(
                'check_response',
                {
                    surveyId,
                    answerToken: token || ''
                }
            );

            if (check.answered && !check.allowReanswer) {
                this.renderAnsweredMessage();
                return;
            }

            if (found.status !== 'published') {
                this.renderRespondentError(
                    'このアンケートは現在回答できません。'
                );
                return;
            }

            this.state.answerStep = 'answer';
            this.state.answers = {};
            this.state.answerRespondent = {
                respondentId: '',
                customerId: '',
                organizationName: '',
                name: '',
                email: '',
                department: '',
                phone: '',
                address: {}
            };

            this.renderRespondent();
        } catch (e) {
            this.renderRespondentError(e.message);
        }
    },

    renderRespondent() {
        document.getElementById('adminApp').classList.add('hidden');
        document
            .getElementById('respondentApp')
            .classList.remove('hidden');

        const container =
            document.getElementById('respondentContainer');

        if (this.state.answerStep === 'answer') {
            container.innerHTML = Views.respondentAnswer();
        } else if (this.state.answerStep === 'confirm') {
            container.innerHTML = Views.respondentConfirm();
        } else {
            container.innerHTML = Views.respondentComplete();
        }
    },

    renderRespondentError(message) {
        document.getElementById('adminApp').classList.add('hidden');
        document
            .getElementById('respondentApp')
            .classList.remove('hidden');

        document.getElementById(
            'respondentContainer'
        ).innerHTML = `
            <main class="respondent-main">
                <div class="respondent-card">
                    <h1>アンケート</h1>
                    <div class="error-box">
                        ${escapeHtml(message)}
                    </div>
                </div>
            </main>
        `;
    },

    renderAnsweredMessage() {
        document.getElementById('adminApp').classList.add('hidden');
        document
            .getElementById('respondentApp')
            .classList.remove('hidden');

        document.getElementById(
            'respondentContainer'
        ).innerHTML = `
            <main class="respondent-main">
                <div class="respondent-card">
                    <h1>回答済み</h1>
                    <div class="success-box">
                        このアンケートはすでに回答済みです。
                    </div>
                    <p>
                        ご回答ありがとうございました。
                    </p>
                </div>
            </main>
        `;
    },

    answerNext() {
        const survey = this.findSurvey(
            this.state.answerSurveyId
        );

        if (!survey) {
            return;
        }

        const questions = flattenQuestions(survey);

        /*
         * 現在表示される質問だけを対象に必須チェック。
         */
        const visible = Answer.visibleQuestions(
            survey,
            this.state.answers
        );

        const errors = [];

        for (const q of visible) {
            if (!q.required) {
                continue;
            }

            const value = this.state.answers[q.questionId];

            if (
                q.type === 'multiple'
                    ? !Array.isArray(value) || value.length === 0
                    : value === undefined ||
                      value === null ||
                      String(value).trim() === ''
            ) {
                errors.push(
                    `${q.questionNumber}「${q.questionText}」`
                );
            }
        }

        if (errors.length) {
            document.getElementById('answerErrors').innerHTML = `
                <div class="error-box">
                    <strong>未回答の必須項目があります。</strong>
                    <ul>
                        ${errors.map(
                            e => `<li>${escapeHtml(e)}</li>`
                        ).join('')}
                    </ul>
                </div>
            `;

            document.getElementById('answerErrors')
                .scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });

            return;
        }

        this.state.answerStep = 'confirm';
        this.renderRespondent();
    },

    answerBack() {
        this.state.answerStep = 'answer';
        this.renderRespondent();
    },

    confirmSubmit() {
        Modal.confirm(
            '回答を送信しますか？',
            '送信後は回答内容が保存されます。',
            async () => {
                try {
                    const result = await API.call(
                        'save_response',
                        {
                            surveyId: this.state.answerSurveyId,
                            answerToken: this.state.answerToken,
                            respondent: this.state.answerRespondent,
                            answers: this.state.answers
                        }
                    );

                    this.state.answerStep = 'complete';
                    this.state.answerToken =
                        result.answerToken;

                    this.renderRespondent();
                } catch (e) {
                    Toast.error(e.message);
                }
            }
        );
    },

    /* ---------------------------------------------------------------------
     * Sending
     * ------------------------------------------------------------------ */

    startSend(id) {
        if (!this.findSurvey(id)) {
            Toast.error('アンケートが見つかりません。');
            return;
        }

        this.state.sendingSurveyId = id;
        this.state.selectedCustomerIds = new Set();
        this.state.sendResult = null;

        this.show('admin-send');
    },

    filteredCustomers() {
        const query =
            this.state.customerSearch
                .trim()
                .toLowerCase();

        return this.state.customers.filter(c => {
            const statusMatch =
                this.state.customerStatus === 'all' ||
                c.answerStatus === this.state.customerStatus;

            if (!statusMatch) {
                return false;
            }

            if (!query) {
                return true;
            }

            return [
                c.name,
                c.organizationName,
                c.email,
                c.answerStatus
            ]
                .join(' ')
                .toLowerCase()
                .includes(query);
        });
    },

    toggleCustomer(id, checked) {
        if (checked) {
            this.state.selectedCustomerIds.add(id);
        } else {
            this.state.selectedCustomerIds.delete(id);
        }

        this.render();
    },

    selectAllVisibleCustomers(checked) {
        for (const customer of this.filteredCustomers()) {
            if (checked) {
                this.state.selectedCustomerIds.add(
                    customer.customerId
                );
            } else {
                this.state.selectedCustomerIds.delete(
                    customer.customerId
                );
            }
        }

        this.render();
    },

    selectReminderTargets() {
        this.state.selectedCustomerIds = new Set(
            this.state.customers
                .filter(
                    c =>
                        c.answerStatus ===
                        '送信済み / 未回答'
                )
                .map(c => c.customerId)
        );

        this.state.customerStatus =
            '送信済み / 未回答';

        this.render();

        Toast.success(
            'リマインド対象を選択しました。'
        );
    },

    async sendMail(sendType = 'bulk') {
        const survey = this.findSurvey(
            this.state.sendingSurveyId
        );

        if (!survey) {
            return;
        }

        const ids = [
            ...this.state.selectedCustomerIds
        ];

        if (!ids.length) {
            Toast.error('送信対象を選択してください。');
            return;
        }

        const subject =
            document.getElementById('mailSubject')?.value ||
            this.state.mailDraft.subject;

        const body =
            document.getElementById('mailBody')?.value ||
            this.state.mailDraft.body;

        const customers =
            this.state.customers.filter(
                c => ids.includes(c.customerId)
            );

        const previews =
            customers.map(c =>
                Mail.expand(
                    survey,
                    c,
                    subject,
                    body
                )
            );

        Modal.confirm(
            sendType === 'reminder'
                ? 'リマインドを送信しますか？'
                : sendType === 'resend'
                    ? '再送しますか？'
                    : '選択した顧客へ送信しますか？',
            `${customers.length}件のメールを送信します。`,
            async () => {
                try {
                    const result =
                        await API.call(
                            'send_mail',
                            {
                                surveyId:
                                    survey.surveyId,
                                customerIds: ids,
                                subject,
                                body,
                                sendType
                            }
                        );

                    this.state.sendResult = result;

                    await this.loadData();

                    this.state.sendingSurveyId =
                        survey.surveyId;

                    this.render();

                    Toast.success(
                        'メール送信処理が完了しました。'
                    );
                } catch (e) {
                    Toast.error(e.message);
                }
            },
            {
                preview: Views.mailPreview(
                    previews
                )
            }
        );
    },

    /* ---------------------------------------------------------------------
     * Aggregation
     * ------------------------------------------------------------------ */

    startAggregation(id) {
        const survey = this.findSurvey(id);

        if (!survey) {
            Toast.error('アンケートが見つかりません。');
            return;
        }

        this.state.aggregationSurveyId = id;

        this.state.aggregationSelection =
            new Set(
                flattenQuestions(survey)
                    .map(q => q.questionId)
            );

        this.show('admin-aggregation');
    },

    aggregationQuestions() {
        const survey = this.findSurvey(
            this.state.aggregationSurveyId
        );

        if (!survey) {
            return [];
        }

        return flattenQuestions(survey);
    },

    /* ---------------------------------------------------------------------
     * Kintone
     * ------------------------------------------------------------------ */

    async saveKintone() {
        const form = {
            subdomain:
                document.getElementById(
                    'kSubdomain'
                )?.value || '',

            appId:
                document.getElementById(
                    'kAppId'
                )?.value || '',

            loginName:
                document.getElementById(
                    'kLoginName'
                )?.value || '',

            password:
                document.getElementById(
                    'kPassword'
                )?.value || '',

            sslVerify:
                document.getElementById(
                    'kSslVerify'
                )?.checked || false,

            proxy:
                document.getElementById(
                    'kProxy'
                )?.value || ''
        };

        try {
            const result =
                await API.call(
                    'save_kintone',
                    form
                );

            this.state.kintone =
                result.kintone;

            Toast.success(
                'kintone設定を保存しました。'
            );

            this.render();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async kintoneTest() {
        try {
            const result =
                await API.call(
                    'kintone_test',
                    {}
                );

            Toast.success(
                result.message || '接続成功'
            );
        } catch (e) {
            Toast.error(
                `kintone接続失敗: ${e.message}`
            );
        }
    },

    async kintoneFields() {
        try {
            const result =
                await API.call(
                    'kintone_fields',
                    {}
                );

            this.state.kintone.fields =
                result.fields || [];

            Toast.success(
                '項目一覧を再取得しました。'
            );

            this.render();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async saveKintoneMapping() {
        const address =
            [...document.querySelectorAll(
                'input[name="kAddress"]:checked'
            )].map(el => el.value);

        try {
            const result =
                await API.call(
                    'save_kintone_mapping',
                    {
                        organizationName:
                            document.getElementById(
                                'mapOrganization'
                            )?.value || '',

                        name:
                            document.getElementById(
                                'mapName'
                            )?.value || '',

                        email:
                            document.getElementById(
                                'mapEmail'
                            )?.value || '',

                        department:
                            document.getElementById(
                                'mapDepartment'
                            )?.value || '',

                        phone:
                            document.getElementById(
                                'mapPhone'
                            )?.value || '',

                        address
                    }
                );

            this.state.kintone.mapping =
                result.mapping;

            Toast.success(
                'マッピングを保存しました。'
            );

            this.render();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async kintoneSync() {
        Modal.confirm(
            'kintoneから顧客情報を同期しますか？',
            '現在のマッピングを使用して顧客データを更新します。',
            async () => {
                try {
                    const result =
                        await API.call(
                            'kintone_sync',
                            {}
                        );

                    await this.loadData();

                    Toast.success(
                        result.message || '顧客同期完了'
                    );
                } catch (e) {
                    Toast.error(
                        `顧客同期失敗: ${e.message}`
                    );
                }
            }
        );
    },

    /* ---------------------------------------------------------------------
     * Mail settings
     * ------------------------------------------------------------------ */

    async saveMail() {
        const form = {
            smtpServer:
                document.getElementById(
                    'smtpServer'
                )?.value || '',

            smtpPort:
                document.getElementById(
                    'smtpPort'
                )?.value || 587,

            encryption:
                document.getElementById(
                    'smtpEncryption'
                )?.value || 'tls',

            auth:
                document.getElementById(
                    'smtpAuth'
                )?.checked || false,

            username:
                document.getElementById(
                    'smtpUsername'
                )?.value || '',

            password:
                document.getElementById(
                    'smtpPassword'
                )?.value || '',

            fromEmail:
                document.getElementById(
                    'smtpFromEmail'
                )?.value || '',

            fromName:
                document.getElementById(
                    'smtpFromName'
                )?.value || '',

            replyTo:
                document.getElementById(
                    'smtpReplyTo'
                )?.value || ''
        };

        try {
            const result =
                await API.call(
                    'save_mail',
                    form
                );

            this.state.mail =
                result.mail;

            Toast.success(
                'メール設定を保存しました。'
            );

            this.render();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async testMail() {
        const to =
            document.getElementById(
                'testMailTo'
            )?.value || '';

        try {
            const result =
                await API.call(
                    'test_mail',
                    { to }
                );

            Toast.success(
                result.message || 'テストメール送信成功'
            );
        } catch (e) {
            Toast.error(
                `SMTP接続失敗: ${e.message}`
            );
        }
    },

    /* ---------------------------------------------------------------------
     * CSV / PDF
     * ------------------------------------------------------------------ */

    exportCSV() {
        const survey = this.findSurvey(
            this.state.aggregationSurveyId
        );

        if (!survey) {
            return;
        }

        const responses =
            this.state.responses.filter(
                r => r.surveyId === survey.surveyId
            );

        const rows = [
            [
                '回答ID',
                '回答日時',
                '組織名',
                '氏名',
                'メールアドレス',
                '質問番号',
                '質問文',
                '回答'
            ]
        ];

        const questions =
            flattenQuestions(survey);

        for (const response of responses) {
            for (const q of questions) {
                rows.push([
                    response.responseId,
                    response.createdAt,
                    response.respondent?.organizationName || '',
                    response.respondent?.name || '',
                    response.respondent?.email || '',
                    q.questionNumber,
                    q.questionText,
                    Answer.displayValue(
                        q,
                        response.answers?.[q.questionId]
                    )
                ]);
            }
        }

        const csv = rows.map(
            row =>
                row.map(csvEscape).join(',')
        ).join('\r\n');

        const blob = new Blob(
            ['\ufeff' + csv],
            {
                type: 'text/csv;charset=utf-8'
            }
        );

        const url =
            URL.createObjectURL(blob);

        const a =
            document.createElement('a');

        a.href = url;
        a.download =
            `survey_${survey.surveyId}.csv`;

        document.body.appendChild(a);
        a.click();
        a.remove();

        URL.revokeObjectURL(url);

        Toast.success('CSV出力を実行しました。');
    },

    exportPDF() {
        Toast.success(
            'PDF出力操作を実行しました。実PDF生成はプロトタイプ仕様上省略しています。'
        );
    },

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    findSurvey(id) {
        return this.state.surveys.find(
            s => s.surveyId === id
        ) || null;
    },

    getQuestion(survey, questionId) {
        if (!survey) {
            return null;
        }

        for (const group of survey.groups) {
            const question =
                group.questions.find(
                    q => q.questionId === questionId
                );

            if (question) {
                return question;
            }
        }

        return null;
    },

    logout() {
        Modal.confirm(
            '画面状態を初期化しますか？',
            '認証機能は実装されていないため、実際のログアウト処理はありません。',
            () => {
                this.state.currentView =
                    'admin-survey-list';

                this.state.editingSurveyId = null;
                this.state.sendingSurveyId = null;
                this.state.aggregationSurveyId = null;
                this.state.editingSurvey = null;

                this.show('admin-survey-list');
            }
        );
    }
};

/* =========================================================================
 * API client
 * ========================================================================= */

const API = {
    async call(action, data) {
        const controller =
            new AbortController();

        const timeout =
            setTimeout(
                () => controller.abort(),
                120000
            );

        try {
            const response =
                await fetch(
                    window.location.href,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type':
                                'application/json',
                            'Accept':
                                'application/json'
                        },
                        body: JSON.stringify({
                            action,
                            ...data
                        }),
                        signal: controller.signal,
                        credentials: 'same-origin'
                    }
                );

            const contentType =
                response.headers.get(
                    'content-type'
                ) || '';

            const text =
                await response.text();

            if (!response.ok) {
                throw new Error(
                    `HTTP ${response.status}: ` +
                    (text || 'サーバーエラー')
                );
            }

            let result;

            try {
                result = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'JSON解析失敗: ' +
                    text.substring(0, 1000)
                );
            }

            if (!result || typeof result !== 'object') {
                throw new Error(
                    'APIレスポンス形式が不正です。'
                );
            }

            if (!result.ok) {
                const err =
                    result.error || {};

                throw new Error(
                    `[${err.code || 'ERROR'}] ` +
                    (err.message ||
                        'PHP処理に失敗しました。')
                );
            }

            return result.data;

        } catch (error) {
            if (error.name === 'AbortError') {
                throw new Error(
                    '通信がタイムアウトしました。'
                );
            }

            if (
                error instanceof TypeError &&
                error.message === 'Failed to fetch'
            ) {
                throw new Error(
                    '通信失敗（Failed to fetch）。' +
                    'index.phpのURL、HTTP状態、PHPエラー、' +
                    'CORS、タイムアウト等を確認してください。'
                );
            }

            throw error;
        } finally {
            clearTimeout(timeout);
        }
    }
};

/* =========================================================================
 * Survey model
 * ========================================================================= */

const Survey = {
    create() {
        const survey = {
            surveyId: id('survey'),
            title: '',
            description: '',
            startDate: '',
            endDate: '',
            numberingMode: 'all',
            status: 'draft',
            allowReanswer: false,
            createdAt: new Date().toISOString(),
            updatedAt: new Date().toISOString(),
            groups: [
                {
                    groupId: id('group'),
                    title: 'グループ1',
                    sortOrder: 1,
                    questions: []
                }
            ]
        };

        this.recalculate(survey);

        return survey;
    },

    normalize(survey) {
        survey.groups ||= [];
        this.recalculate(survey);
    },

    recalculate(survey) {
        survey.groups ||= [];

        survey.groups.forEach(
            (g, gi) => {
                g.sortOrder = gi + 1;
                g.questions ||= [];

                g.questions.forEach(
                    (q, qi) => {
                        q.groupId = g.groupId;
                        q.sortOrder = qi + 1;
                        q.choices ||= [];
                        q.branchRules ||= [];

                        q.choices.forEach(
                            (c, ci) => {
                                c.sortOrder = ci + 1;
                            }
                        );
                    }
                );
            }
        );

        let number = 1;

        if (survey.numberingMode === 'group') {
            survey.groups.forEach(
                (g, gi) => {
                    g.questions.forEach(
                        (q, qi) => {
                            q.questionNumber =
                                `Q${gi + 1}-${qi + 1}`;
                        }
                    );
                }
            );
        } else {
            survey.groups.forEach(
                g => {
                    g.questions.forEach(
                        q => {
                            q.questionNumber =
                                `Q${number++}`;
                        }
                    );
                }
            );
        }
    }
};

/* =========================================================================
 * Answer logic
 * ========================================================================= */

const Answer = {
    visibleQuestions(survey, answers) {
        const all =
            flattenQuestions(survey);

        if (!all.length) {
            return [];
        }

        /*
         * 最初はすべて表示。
         * branchRulesがある場合は、直前の回答から次の質問へ移動する。
         *
         * ルールがない質問は通常の順序で次へ進む。
         */
        const result = [];

        let index = 0;
        const visited = new Set();

        while (
            index >= 0 &&
            index < all.length &&
            result.length < all.length
        ) {
            const q = all[index];

            if (visited.has(q.questionId)) {
                break;
            }

            visited.add(q.questionId);
            result.push(q);

            const value =
                answers[q.questionId];

            let nextId = null;

            if (
                q.type === 'single' &&
                value &&
                Array.isArray(q.branchRules)
            ) {
                const rule =
                    q.branchRules.find(
                        r =>
                            r.choiceId ===
                            String(value)
                    );

                if (rule) {
                    nextId =
                        rule.nextQuestionId;
                }
            }

            if (nextId) {
                const nextIndex =
                    all.findIndex(
                        x =>
                            x.questionId ===
                            nextId
                    );

                if (nextIndex >= 0) {
                    index = nextIndex;
                    continue;
                }
            }

            index++;
        }

        return result;
    },

    displayValue(question, value) {
        if (
            value === undefined ||
            value === null
        ) {
            return '';
        }

        if (question.type === 'text') {
            return String(value);
        }

        const choices =
            question.choices || [];

        if (question.type === 'single') {
            const choice =
                choices.find(
                    c =>
                        c.choiceId ===
                        String(value)
                );

            return choice?.label ||
                String(value);
        }

        if (question.type === 'multiple') {
            if (!Array.isArray(value)) {
                return '';
            }

            return value.map(
                id => {
                    const c =
                        choices.find(
                            x =>
                                x.choiceId ===
                                String(id)
                        );

                    return c?.label || id;
                }
            ).join('、');
        }

        return String(value);
    }
};

/* =========================================================================
 * Mail
 * ========================================================================= */

const Mail = {
    expand(survey, customer, subject, body) {
        const token =
            sha256Local(
                `${survey.surveyId}:${customer.customerId}`
            );

        const base =
            `${location.origin}${location.pathname}`;

        const url =
            `${base}?view=answer` +
            `&survey=${encodeURIComponent(
                survey.surveyId
            )}` +
            `&token=${encodeURIComponent(token)}`;

        return {
            customerId:
                customer.customerId,

            name:
                customer.name || '',

            email:
                customer.email || '',

            subject:
                subject
                    .replaceAll(
                        '{顧客名}',
                        customer.name || ''
                    )
                    .replaceAll(
                        '{アンケートURL}',
                        url
                    ),

            body:
                body
                    .replaceAll(
                        '{顧客名}',
                        customer.name || ''
                    )
                    .replaceAll(
                        '{アンケートURL}',
                        url
                    ),

            url
        };
    }
};

/* =========================================================================
 * Views
 * ========================================================================= */

const Views = {

    surveyList() {
        const surveys =
            this.filteredSurveys();

        return `
            <div class="page-title">
                <div>
                    <h2>アンケート一覧</h2>
                    <div class="muted">
                        管理業務の起点
                    </div>
                </div>

                <button
                    class="btn btn-primary"
                    onclick="App.newSurvey()"
                >
                    ＋ アンケート作成
                </button>
            </div>

            <div class="card">
                <div class="toolbar">
                    <input
                        id="surveySearch"
                        class="input"
                        style="max-width:420px"
                        placeholder="タイトルを検索"
                        value="${escapeAttr(
                            App.state.surveySearch
                        )}"
                        onkeydown="
                            if(event.key==='Enter')
                                App.applySurveySearch()
                        "
                    >

                    <button
                        class="btn"
                        onclick="App.applySurveySearch()"
                    >
                        検索
                    </button>

                    <select
                        style="width:auto"
                        onchange="
                            App.state.surveySort=this.value;
                            App.render()
                        "
                    >
                        <option value="updated_desc"
                            ${selected(
                                App.state.surveySort,
                                'updated_desc'
                            )}>
                            更新日 新しい順
                        </option>

                        <option value="updated_asc"
                            ${selected(
                                App.state.surveySort,
                                'updated_asc'
                            )}>
                            更新日 古い順
                        </option>

                        <option value="responses_desc"
                            ${selected(
                                App.state.surveySort,
                                'responses_desc'
                            )}>
                            回答数 多い順
                        </option>

                        <option value="responses_asc"
                            ${selected(
                                App.state.surveySort,
                                'responses_asc'
                            )}>
                            回答数 少ない順
                        </option>

                        <option value="start_desc"
                            ${selected(
                                App.state.surveySort,
                                'start_desc'
                            )}>
                            開始日 新しい順
                        </option>

                        <option value="start_asc"
                            ${selected(
                                App.state.surveySort,
                                'start_asc'
                            )}>
                            開始日 古い順
                        </option>
                    </select>
                </div>

                <div class="filters">
                    ${[
                        ['all', 'すべて'],
                        ['published', '公開中'],
                        ['draft', '下書き'],
                        ['stopped', '停止'],
                        ['finished', '終了']
                    ].map(
                        ([value, label]) => `
                            <button
                                class="btn btn-small filter-btn
                                    ${App.state.surveyFilter === value
                                        ? 'active'
                                        : ''}"
                                onclick="
                                    App.state.surveyFilter='${value}';
                                    App.render()
                                "
                            >
                                ${label}
                            </button>
                        `
                    ).join('')}
                </div>
            </div>

            <div class="card">
                <div class="table-wrap">
                    ${
                        surveys.length
                        ? `
                        <table>
                            <thead>
                                <tr>
                                    <th>作成日</th>
                                    <th>更新日</th>
                                    <th>タイトル</th>
                                    <th>アンケート期間</th>
                                    <th>ステータス</th>
                                    <th>回答数</th>
                                    <th>操作</th>
                                </tr>
                            </thead>

                            <tbody>
                                ${surveys.map(
                                    survey =>
                                        this.surveyRow(
                                            survey
                                        )
                                ).join('')}
                            </tbody>
                        </table>
                        `
                        : `
                        <div class="empty">
                            アンケートがありません。
                        </div>
                        `
                    }
                </div>
            </div>
        `;
    },

    surveyRow(survey) {
        const count =
            App.state.responses.filter(
                r =>
                    r.surveyId ===
                    survey.surveyId
            ).length;

        return `
            <tr>
                <td>
                    ${formatDate(survey.createdAt)}
                </td>

                <td>
                    ${formatDate(survey.updatedAt)}
                </td>

                <td>
                    <strong>
                        ${escapeHtml(survey.title)}
                    </strong>
                </td>

                <td>
                    ${formatDateTime(survey.startDate)}
                    <br>
                    ～
                    <br>
                    ${formatDateTime(survey.endDate)}
                </td>

                <td>
                    ${statusBadge(survey.status)}
                </td>

                <td>
                    ${count}
                </td>

                <td>
                    <div class="mobile-stack">
                        <button
                            class="btn btn-small"
                            onclick="
                                App.editSurvey(
                                    '${escapeJs(
                                        survey.surveyId
                                    )}'
                                )
                            "
                        >
                            確認・編集
                        </button>

                        <button
                            class="btn btn-small"
                            onclick="
                                App.startAggregation(
                                    '${escapeJs(
                                        survey.surveyId
                                    )}'
                                )
                            "
                        >
                            集計
                        </button>

                        <button
                            class="btn btn-small"
                            onclick="
                                App.startSend(
                                    '${escapeJs(
                                        survey.surveyId
                                    )}'
                                )
                            "
                        >
                            送信
                        </button>

                        <button
                            class="btn btn-small"
                            onclick="
                                App.duplicateSurvey(
                                    '${escapeJs(
                                        survey.surveyId
                                    )}'
                                )
                            "
                        >
                            複製
                        </button>

                        <button
                            class="btn btn-small btn-danger"
                            onclick="
                                App.deleteSurvey(
                                    '${escapeJs(
                                        survey.surveyId
                                    )}'
                                )
                            "
                        >
                            削除
                        </button>
                    </div>
                </td>
            </tr>
        `;
    },

    surveyEdit() {
        const survey =
            App.state.editingSurvey;

        if (!survey) {
            return `
                <div class="card">
                    対象アンケートがありません。
                </div>
            `;
        }

        Survey.recalculate(survey);

        return `
            <div class="page-title">
                <div>
                    <h2>
                        ${survey.createdAt
                            ? 'アンケート編集'
                            : 'アンケート作成'}
                    </h2>
                    <div>
                        状態：
                        ${statusBadge(survey.status)}
                    </div>
                </div>

                <div class="toolbar">
                    <button
                        class="btn"
                        onclick="App.cancelEdit()"
                    >
                        キャンセル
                    </button>

                    <button
                        class="btn btn-primary"
                        onclick="App.saveSurvey()"
                    >
                        保存して一覧へ
                    </button>
                </div>
            </div>

            <div class="card">
                <h3 class="section-title">
                    基本情報
                </h3>

                <div class="form-grid">
                    <div class="field form-full">
                        <label>タイトル</label>
                        <input
                            class="input"
                            value="${escapeAttr(
                                survey.title
                            )}"
                            oninput="
                                App.state.editingSurvey.title=this.value
                            "
                        >
                    </div>

                    <div class="field form-full">
                        <label>説明</label>
                        <textarea
                            oninput="
                                App.state.editingSurvey.description=this.value
                            "
                        >${escapeHtml(
                            survey.description
                        )}</textarea>
                    </div>

                    <div class="field">
                        <label>開始日時</label>
                        <input
                            class="input"
                            type="datetime-local"
                            value="${toDatetimeLocal(
                                survey.startDate
                            )}"
                            onchange="
                                App.state.editingSurvey.startDate=this.value
                            "
                        >
                    </div>

                    <div class="field">
                        <label>終了日時</label>
                        <input
                            class="input"
                            type="datetime-local"
                            value="${toDatetimeLocal(
                                survey.endDate
                            )}"
                            onchange="
                                App.state.editingSurvey.endDate=this.value
                            "
                        >
                    </div>

                    <div class="field">
                        <label>質問番号採番方式</label>
                        <select
                            onchange="
                                App.state.editingSurvey.numberingMode=this.value;
                                Survey.recalculate(
                                    App.state.editingSurvey
                                );
                                App.render()
                            "
                        >
                            <option
                                value="all"
                                ${selected(
                                    survey.numberingMode,
                                    'all'
                                )}
                            >
                                アンケート全体で通番
                            </option>

                            <option
                                value="group"
                                ${selected(
                                    survey.numberingMode,
                                    'group'
                                )}
                            >
                                グループ毎に採番
                            </option>
                        </select>
                    </div>

                    <div class="field">
                        <label>再回答</label>
                        <label class="inline-check">
                            <input
                                type="checkbox"
                                ${survey.allowReanswer
                                    ? 'checked'
                                    : ''}
                                onchange="
                                    App.state.editingSurvey.allowReanswer=this.checked
                                "
                            >
                            再回答を許可する
                        </label>
                    </div>
                </div>

                ${
                    survey.status === 'finished'
                    ? `
                    <div class="warning-box">
                        終了状態のアンケートは
                        状態変更できません。
                    </div>
                    `
                    : `
                    <div style="margin-top:18px">
                        <label>
                            状態変更
                        </label>

                        <div class="toolbar">
                            ${this.statusActions(survey)}
                        </div>
                    </div>
                    `
                }
            </div>

            <div class="card">
                <div class="page-title">
                    <h3 class="section-title">
                        グループ・質問
                    </h3>
                </div>

                <div id="groupList">
                    ${
                        survey.groups.length
                        ? survey.groups.map(
                            group =>
                                this.groupEditor(
                                    survey,
                                    group
                                )
                        ).join('')
                        : `
                        <div class="empty">
                            グループがありません。
                        </div>
                        `
                    }
                </div>

                <div class="toolbar"
                     style="justify-content:center;margin-top:12px">
                    <button
                        class="btn btn-primary"
                        onclick="App.addGroup()"
                    >
                        ＋ グループ追加
                    </button>
                </div>
            </div>
        `;
    },

    statusActions(survey) {
        if (survey.status === 'draft') {
            return `
                <button
                    class="btn btn-success"
                    onclick="
                        App.changeStatus('published')
                    "
                >
                    公開
                </button>
            `;
        }

        if (survey.status === 'published') {
            return `
                <button
                    class="btn btn-warning"
                    onclick="
                        App.changeStatus('stopped')
                    "
                >
                    停止
                </button>
            `;
        }

        if (survey.status === 'stopped') {
            return `
                <button
                    class="btn btn-success"
                    onclick="
                        App.changeStatus('published')
                    "
                >
                    再開
                </button>
            `;
        }

        return '';
    },

    groupEditor(survey, group) {
        return `
            <div
                class="group"
                data-group-id="${escapeAttr(
                    group.groupId
                )}"
            >
                <div class="group-header">
                    <span>☷</span>

                    <input
                        class="input group-title"
                        value="${escapeAttr(
                            group.title
                        )}"
                        oninput="
                            this.closest('.group')
                                .dataset.title=this.value;
                            Views.updateGroupTitle(
                                '${escapeJs(
                                    group.groupId
                                )}',
                                this.value
                            )
                        "
                    >

                    <button
                        class="btn btn-small btn-danger"
                        onclick="
                            App.deleteGroup(
                                '${escapeJs(
                                    group.groupId
                                )}'
                            )
                        "
                    >
                        グループ削除
                    </button>
                </div>

                <div
                    class="question-list"
                    data-question-list="${escapeAttr(
                        group.groupId
                    )}"
                >
                    ${
                        group.questions.length
                        ? group.questions.map(
                            q =>
                                this.questionEditor(
                                    survey,
                                    group,
                                    q
                                )
                        ).join('')
                        : `
                        <div class="empty">
                            質問がありません。
                        </div>
                        `
                    }
                </div>

                <div style="padding:10px">
                    <button
                        class="btn btn-small"
                        onclick="
                            App.addQuestion(
                                '${escapeJs(
                                    group.groupId
                                )}'
                            )
                        "
                    >
                        ＋ 質問追加
                    </button>
                </div>
            </div>
        `;
    },

    questionEditor(survey, group, q) {
        const allQuestions =
            flattenQuestions(survey);

        return `
            <div
                class="question"
                data-question-id="${escapeAttr(
                    q.questionId
                )}"
                data-group-id="${escapeAttr(
                    group.groupId
                )}"
            >
                <div class="question-head">
                    <div>
                        <div class="question-number">
                            ${escapeHtml(
                                q.questionNumber
                            )}
                        </div>
                        <input
                            class="input"
                            style="margin-top:7px"
                            value="${escapeAttr(
                                q.questionText
                            )}"
                            placeholder="質問文"
                            oninput="
                                App.updateQuestion(
                                    '${escapeJs(
                                        group.groupId
                                    )}',
                                    '${escapeJs(
                                        q.questionId
                                    )}',
                                    {questionText:this.value}
                                )
                            "
                        >
                    </div>

                    <div class="question-actions">
                        <button
                            class="btn btn-small btn-danger"
                            onclick="
                                App.deleteQuestion(
                                    '${escapeJs(
                                        group.groupId
                                    )}',
                                    '${escapeJs(
                                        q.questionId
                                    )}'
                                )
                            "
                        >
                            削除
                        </button>
                    </div>
                </div>

                <div class="form-grid"
                     style="margin-top:12px">

                    <div class="field">
                        <label>回答形式</label>

                        <select
                            onchange="
                                App.updateQuestion(
                                    '${escapeJs(
                                        group.groupId
                                    )}',
                                    '${escapeJs(
                                        q.questionId
                                    )}',
                                    {type:this.value}
                                );
                                App.render()
                            "
                        >
                            <option
                                value="single"
                                ${selected(q.type,'single')}
                            >
                                単一選択
                            </option>

                            <option
                                value="multiple"
                                ${selected(q.type,'multiple')}
                            >
                                複数選択
                            </option>

                            <option
                                value="text"
                                ${selected(q.type,'text')}
                            >
                                自由記述
                            </option>
                        </select>
                    </div>

                    <div class="field">
                        <label>回答必須</label>

                        <label class="inline-check">
                            <input
                                type="checkbox"
                                ${q.required
                                    ? 'checked'
                                    : ''}
                                onchange="
                                    App.updateQuestion(
                                        '${escapeJs(
                                            group.groupId
                                        )}',
                                        '${escapeJs(
                                            q.questionId
                                        )}',
                                        {required:this.checked}
                                    )
                                "
                            >
                            必須
                        </label>
                    </div>
                </div>

                ${
                    q.type !== 'text'
                    ? `
                    <div style="margin-top:14px">
                        <strong>選択肢</strong>

                        <div style="margin-top:8px">
                            ${q.choices.map(
                                choice => `
                                <div class="choice-row">
                                    <span>☷</span>

                                    <input
                                        class="input"
                                        value="${escapeAttr(
                                            choice.label
                                        )}"
                                        oninput="
                                            App.updateChoice(
                                                '${escapeJs(
                                                    q.questionId
                                                )}',
                                                '${escapeJs(
                                                    choice.choiceId
                                                )}',
                                                this.value
                                            )
                                        "
                                    >

                                    <button
                                        class="btn btn-small"
                                        onclick="
                                            App.deleteChoice(
                                                '${escapeJs(
                                                    q.questionId
                                                )}',
                                                '${escapeJs(
                                                    choice.choiceId
                                                )}'
                                            )
                                        "
                                    >
                                        削除
                                    </button>
                                </div>
                            ).join('')}
                        </div>

                        <button
                            class="btn btn-small"
                            onclick="
                                App.addChoice(
                                    '${escapeJs(
                                        q.questionId
                                    )}'
                                )
                            "
                        >
                            ＋ 選択肢追加
                        </button>
                    </div>
                    `
                    : ''
                }

                ${
                    q.type === 'single'
                    ? `
                    <div style="margin-top:14px">
                        <strong>条件分岐</strong>

                        <p class="muted">
                            選択肢ごとに次に表示する質問を設定します。
                            内部的にはquestionIdで管理されます。
                        </p>

                        ${
                            q.branchRules.map(
                                (rule, index) => `
                                <div class="branch-row">
                                    <select
                                        onchange="
                                            App.updateBranch(
                                                '${escapeJs(
                                                    q.questionId
                                                )}',
                                                ${index},
                                                'choiceId',
                                                this.value
                                            )
                                        "
                                    >
                                        ${q.choices.map(
                                            c => `
                                            <option
                                                value="${escapeAttr(
                                                    c.choiceId
                                                )}"
                                                ${selected(
                                                    rule.choiceId,
                                                    c.choiceId
                                                )}
                                            >
                                                ${escapeHtml(
                                                    c.label
                                                )}
                                            </option>
                                        `).join('')}
                                    </select>

                                    <div class="toolbar">
                                        <select
                                            onchange="
                                                App.updateBranch(
                                                    '${escapeJs(
                                                        q.questionId
                                                    )}',
                                                    ${index},
                                                    'nextQuestionId',
                                                    this.value
                                                )
                                            "
                                        >
                                            <option value="">
                                                次の質問
                                            </option>

                                            ${allQuestions
                                                .filter(
                                                    x =>
                                                        x.questionId !==
                                                        q.questionId
                                                )
                                                .map(
                                                    x => `
                                                    <option
                                                        value="${escapeAttr(
                                                            x.questionId
                                                        )}"
                                                        ${selected(
                                                            rule.nextQuestionId,
                                                            x.questionId
                                                        )}
                                                    >
                                                        ${escapeHtml(
                                                            x.questionNumber
                                                        )}
                                                        :
                                                        ${escapeHtml(
                                                            x.questionText
                                                        )}
                                                    </option>
                                                `
                                                ).join('')}
                                        </select>

                                        <button
                                            class="btn btn-small"
                                            onclick="
                                                App.deleteBranchRule(
                                                    '${escapeJs(
                                                        q.questionId
                                                    )}',
                                                    ${index}
                                                )
                                            "
                                        >
                                            削除
                                        </button>
                                    </div>
                                </div>
                            `
                            ).join('')
                        }

                        <button
                            class="btn btn-small"
                            onclick="
                                App.addBranchRule(
                                    '${escapeJs(
                                        q.questionId
                                    )}'
                                )
                            "
                        >
                            ＋ 条件分岐追加
                        </button>
                    </div>
                    `
                    : ''
                }
            </div>
        `;
    },

    preview() {
        const survey =
            App.state.previewSurvey;

        if (!survey) {
            return `
                <div class="card">
                    プレビュー対象がありません。
                </div>
            `;
        }

        return `
            <div class="page-title">
                <div>
                    <h2>プレビュー</h2>
                    <div class="muted">
                        実際の送信は行いません。
                    </div>
                </div>

                <button
                    class="btn"
                    onclick="App.show('admin-survey-edit')"
                >
                    編集へ戻る
                </button>
            </div>

            <div class="card">
                <div class="preview-toolbar">
                    <button
                        class="btn btn-small"
                        onclick="
                            document
                                .querySelector('.preview-device')
                                ?.classList.remove('mobile');
                            document
                                .querySelector('.preview-device')
                                ?.classList.add('pc')
                        "
                    >
                        PC
                    </button>

                    <button
                        class="btn btn-small"
                        onclick="
                            document
                                .querySelector('.preview-device')
                                ?.classList.remove('pc');
                            document
                                .querySelector('.preview-device')
                                ?.classList.add('mobile')
                        "
                    >
                        スマートフォン
                    </button>
                </div>

                <div class="preview-device pc">
                    ${this.previewContent(survey)}
                </div>
            </div>
        `;
    },

    previewContent(survey) {
        const questions =
            flattenQuestions(survey);

        return `
            <div class="respondent-card"
                 style="box-shadow:none">
                <h1>
                    ${escapeHtml(survey.title)}
                </h1>

                ${
                    survey.description
                    ? `<p>${nl2br(
                        escapeHtml(
                            survey.description
                        )
                    )}</p>`
                    : ''
                }

                ${questions.map(
                    q => `
                    <div class="answer-question">
                        <div>
                            <strong>
                                ${escapeHtml(
                                    q.questionNumber
                                )}
                            </strong>

                            ${
                                q.required
                                ? '<span class="required-mark">必須</span>'
                                : ''
                            }
                        </div>

                        <h3>
                            ${escapeHtml(
                                q.questionText
                            )}
                        </h3>

                        ${
                            q.type === 'text'
                            ? `
                                <textarea
                                    placeholder="回答を入力"
                                ></textarea>
                            `
                            : q.choices.map(
                                c => `
                                <label class="answer-choice">
                                    <input
                                        type="${
                                            q.type === 'single'
                                                ? 'radio'
                                                : 'checkbox'
                                        }"
                                    >
                                    ${escapeHtml(
                                        c.label
                                    )}
                                </label>
                            `
                            ).join('')
                        }
                    </div>
                `).join('')}

                <button
                    class="btn btn-primary"
                    type="button"
                    onclick="return false"
                >
                    次へ
                </button>
            </div>
        `;
    },

    /* ---------------------------------------------------------------------
     * Send
     * ------------------------------------------------------------------ */

    send() {
        const survey =
            App.findSurvey(
                App.state.sendingSurveyId
            );

        if (!survey) {
            return `
                <div class="card">
                    対象アンケートがありません。
                </div>
            `;
        }

        const customers =
            App.filteredCustomers();

        const selected =
            App.state.selectedCustomerIds;

        return `
            <div class="page-title">
                <div>
                    <h2>顧客選択・メール送信</h2>
                    <div class="muted">
                        対象アンケート：
                        <strong>
                            ${escapeHtml(
                                survey.title
                            )}
                        </strong>
                    </div>
                </div>

                <button
                    class="btn"
                    onclick="App.show('admin-survey-list')"
                >
                    一覧へ
                </button>
            </div>

            <div class="card">
                <h3 class="section-title">
                    顧客選択
                </h3>

                <div class="toolbar">
                    <input
                        class="input"
                        style="max-width:350px"
                        placeholder="顧客名・組織名・メールを検索"
                        value="${escapeAttr(
                            App.state.customerSearch
                        )}"
                        oninput="
                            App.state.customerSearch=this.value;
                            App.render()
                        "
                        onkeydown="
                            if(event.key==='Enter')
                                App.render()
                        "
                    >

                    <select
                        style="width:auto"
                        onchange="
                            App.state.customerStatus=this.value;
                            App.render()
                        "
                    >
                        <option value="all">
                            すべて
                        </option>

                        <option
                            value="未送信"
                            ${selected(
                                App.state.customerStatus,
                                '未送信'
                            )}
                        >
                            未送信
                        </option>

                        <option
                            value="送信済み / 未回答"
                            ${selected(
                                App.state.customerStatus,
                                '送信済み / 未回答'
                            )}
                        >
                            送信済み / 未回答
                        </option>

                        <option
                            value="回答済み"
                            ${selected(
                                App.state.customerStatus,
                                '回答済み'
                            )}
                        >
                            回答済み
                        </option>
                    </select>

                    <button
                        class="btn btn-warning"
                        onclick="App.selectReminderTargets()"
                    >
                        リマインド対象を抽出
                    </button>
                </div>

                <div class="table-wrap"
                     style="margin-top:15px">
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <input
                                        type="checkbox"
                                        onchange="
                                            App.selectAllVisibleCustomers(
                                                this.checked
                                            )
                                        "
                                    >
                                </th>
                                <th>組織名</th>
                                <th>氏名</th>
                                <th>メールアドレス</th>
                                <th>電話番号</th>
                                <th>最終送信日時</th>
                                <th>送信回数</th>
                                <th>回答ステータス</th>
                                <th>kintone</th>
                            </tr>
                        </thead>

                        <tbody>
                            ${
                                customers.length
                                ? customers.map(
                                    c => `
                                    <tr>
                                        <td>
                                            <input
                                                type="checkbox"
                                                ${selected.has(
                                                    c.customerId
                                                )
                                                    ? 'checked'
                                                    : ''}
                                                onchange="
                                                    App.toggleCustomer(
                                                        '${escapeJs(
                                                            c.customerId
                                                        )}',
                                                        this.checked
                                                    )
                                                "
                                            >
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                c.organizationName
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
                                            ${formatDateTime(
                                                c.lastSentAt
                                            )}
                                        </td>

                                        <td>
                                            ${c.sendCount || 0}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                c.answerStatus ||
                                                '未送信'
                                            )}
                                        </td>

                                        <td>
                                            ${escapeHtml(
                                                c.kintoneStatus ||
                                                ''
                                            )}
                                        </td>
                                    </tr>
                                `
                                ).join('')
                                : `
                                    <tr>
                                        <td colspan="9"
                                            class="empty">
                                            対象顧客がありません。
                                        </td>
                                    </tr>
                                `
                            }
                        </tbody>
                    </table>
                </div>

                <div class="success-box">
                    選択件数：
                    <strong>
                        ${selected.size}
                    </strong>
                </div>
            </div>

            <div class="card">
                <h3 class="section-title">
                    メール作成
                </h3>

                <div class="field">
                    <label>件名</label>
                    <input
                        id="mailSubject"
                        class="input"
                        value="${escapeAttr(
                            App.state.mailDraft.subject
                        )}"
                        oninput="
                            App.state.mailDraft.subject=this.value
                        "
                    >
                </div>

                <div class="field"
                     style="margin-top:14px">
                    <label>本文</label>
                    <textarea
                        id="mailBody"
                        style="min-height:220px"
                        oninput="
                            App.state.mailDraft.body=this.value
                        "
                    >${escapeHtml(
                        App.state.mailDraft.body
                    )}</textarea>
                </div>

                <p class="muted">
                    使用可能な変数：
                    <code>{顧客名}</code>
                    <code>{アンケートURL}</code>
                </p>

                <div class="toolbar"
                     style="margin-top:15px">
                    <button
                        class="btn btn-primary"
                        onclick="App.sendMail('bulk')"
                    >
                        一括送信
                    </button>

                    <button
                        class="btn btn-warning"
                        onclick="App.sendMail('resend')"
                    >
                        再送
                    </button>

                    <button
                        class="btn"
                        onclick="App.sendMail('reminder')"
                    >
                        リマインド送信
                    </button>
                </div>
            </div>

            ${
                App.state.sendResult
                ? `
                <div class="card">
                    <h3 class="section-title">
                        送信結果
                    </h3>

                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="summary-label">
                                対象件数
                            </div>
                            <div class="summary-value">
                                ${App.state.sendResult.targetCount}
                            </div>
                        </div>

                        <div class="summary-item">
                            <div class="summary-label">
                                成功件数
                            </div>
                            <div class="summary-value">
                                ${App.state.sendResult.successCount}
                            </div>
                        </div>

                        <div class="summary-item">
                            <div class="summary-label">
                                失敗件数
                            </div>
                            <div class="summary-value">
                                ${App.state.sendResult.failureCount}
                            </div>
                        </div>

                        <div class="summary-item">
                            <div class="summary-label">
                                送信日時
                            </div>
                            <div>
                                ${formatDateTime(
                                    App.state.sendResult.sentAt
                                )}
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:15px">
                        ${
                            App.state.sendResult.results
                                ?.map(
                                    r => `
                                    <div class="${
                                        r.success
                                            ? 'success-box'
                                            : 'error-box'
                                    }">
                                        <strong>
                                            ${escapeHtml(
                                                r.customerName
                                            )}
                                        </strong>
                                        /
                                        ${escapeHtml(
                                            r.email
                                        )}
                                        <br>
                                        ${
                                            r.success
                                            ? '送信成功'
                                            : '送信失敗：' +
                                                escapeHtml(
                                                    r.error
                                                )
                                        }
                                    </div>
                                `
                                )
                                .join('') || ''
                        }
                    </div>
                </div>
                `
                : ''
            }

            <div class="card">
                <h3 class="section-title">
                    送信履歴
                </h3>

                ${
                    Views.history(
                        survey.surveyId
                    )
                }
            </div>
        `;
    },

    mailPreview(items) {
        if (!items.length) {
            return `
                <div class="muted">
                    送信対象がありません。
                </div>
            `;
        }

        return `
            <div>
                <strong>送信予定内容</strong>

                ${items.map(
                    item => `
                    <div class="detail-item"
                         style="margin-top:10px">
                        <strong>
                            ${escapeHtml(
                                item.name
                            )}
                        </strong>
                        /
                        ${escapeHtml(
                            item.email
                        )}

                        <div
                            class="email-preview"
                            style="margin-top:8px"
                        >
                            <strong>
                                ${escapeHtml(
                                    item.subject
                                )}
                            </strong>

                            <hr>

                            ${escapeHtml(
                                item.body
                            )}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    },

    history(surveyId) {
        const history =
            App.state.history
                .filter(
                    h =>
                        h.surveyId === surveyId
                )
                .slice()
                .reverse();

        if (!history.length) {
            return `
                <div class="empty">
                    送信履歴はありません。
                </div>
            `;
        }

        return history.map(
            h => `
            <details class="history-item">
                <summary>
                    ${formatDateTime(h.sentAt)}
                    /
                    ${escapeHtml(
                        h.sendType
                    )}
                    /
                    ${h.count}件
                    /
                    成功${h.successCount}
                    失敗${h.failureCount}
                </summary>

                <div style="margin-top:10px">
                    <strong>件名</strong>
                    <div class="email-preview">
                        ${escapeHtml(
                            h.subject
                        )}
                    </div>

                    <strong>本文</strong>
                    <div class="email-preview">
                        ${escapeHtml(
                            h.body
                        )}
                    </div>

                    <div style="margin-top:10px">
                        <strong>
                            顧客別送信内容
                        </strong>

                        ${
                            (h.targets || [])
                                .map(
                                    t => `
                                    <div
                                        class="history-target"
                                    >
                                        <strong>
                                            ${escapeHtml(
                                                t.customerName
                                            )}
                                        </strong>
                                        /
                                        ${escapeHtml(
                                            t.email
                                        )}

                                        <div
                                            class="email-preview"
                                            style="margin-top:5px"
                                        >
                                            ${escapeHtml(
                                                t.body
                                            )}

                                            <hr>

                                            個別URL：
                                            ${escapeHtml(
                                                t.answerUrl
                                            )}
                                        </div>
                                    </div>
                                `
                                )
                                .join('')
                        }
                    </div>
                </div>
            </details>
        `
        ).join('');
    },

    /* ---------------------------------------------------------------------
     * Aggregation
     * ------------------------------------------------------------------ */

    aggregation() {
        const survey =
            App.findSurvey(
                App.state.aggregationSurveyId
            );

        if (!survey) {
            return `
                <div class="card">
                    対象アンケートがありません。
                </div>
            `;
        }

        const responses =
            App.state.responses.filter(
                r =>
                    r.surveyId ===
                    survey.surveyId
            );

        const targetCount =
            App.state.customers.length;

        const answerCount =
            responses.length;

        const registeredCount =
            responses.filter(
                r =>
                    !!r.customerId
            ).length;

        const unregisteredCount =
            responses.filter(
                r =>
                    !r.customerId
            ).length;

        const unanswered =
            Math.max(
                0,
                targetCount - registeredCount
            );

        const rate =
            targetCount
                ? ((answerCount / targetCount) * 100)
                    .toFixed(1)
                : '0.0';

        const questions =
            flattenQuestions(survey);

        return `
            <div class="page-title">
                <div>
                    <h2>回答集計・分析</h2>
                    <div class="muted">
                        対象：
                        <strong>
                            ${escapeHtml(
                                survey.title
                            )}
                        </strong>
                    </div>
                </div>

                <div class="toolbar">
                    <button
                        class="btn"
                        onclick="App.exportCSV()"
                    >
                        CSV出力
                    </button>

                    <button
                        class="btn"
                        onclick="App.exportPDF()"
                    >
                        PDF出力
                    </button>

                    <button
                        class="btn"
                        onclick="
                            App.show(
                                'admin-survey-list'
                            )
                        "
                    >
                        一覧へ
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-label">
                            送信対象者数
                        </div>
                        <div class="summary-value">
                            ${targetCount}
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-label">
                            回答数
                        </div>
                        <div class="summary-value">
                            ${answerCount}
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-label">
                            未登録顧客回答
                        </div>
                        <div class="summary-value">
                            ${unregisteredCount}
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-label">
                            未回答
                        </div>
                        <div class="summary-value">
                            ${unanswered}
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-label">
                            回答率
                        </div>
                        <div class="summary-value">
                            ${rate}%
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3 class="section-title">
                    設問別集計
                </h3>

                <div class="toolbar"
                     style="margin-bottom:15px">
                    <button
                        class="btn btn-small"
                        onclick="
                            App.state.aggregationSelection=
                                new Set(
                                    ${JSON.stringify(
                                        questions.map(
                                            q =>
                                                q.questionId
                                        )
                                    )}
                                );
                            App.render()
                        "
                    >
                        すべて選択
                    </button>

                    <button
                        class="btn btn-small"
                        onclick="
                            App.state.aggregationSelection=
                                new Set();
                            App.render()
                        "
                    >
                        すべて解除
                    </button>
                </div>

                ${
                    questions.map(
                        q =>
                            this.aggregationQuestion(
                                q,
                                responses
                            )
                    ).join('')
                }
            </div>

            <div class="card">
                <h3 class="section-title">
                    個別回答
                </h3>

                ${
                    responses.length
                    ? `
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>回答日時</th>
                                    <th>組織名</th>
                                    <th>氏名</th>
                                    <th>メール</th>
                                    <th>回答</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${responses.map(
                                    r => `
                                    <tr>
                                        <td>
                                            ${formatDateTime(
                                                r.createdAt
                                            )}
                                        </td>
                                        <td>
                                            ${escapeHtml(
                                                r.respondent?.organizationName || ''
                                            )}
                                        </td>
                                        <td>
                                            ${escapeHtml(
                                                r.respondent?.name || ''
                                            )}
                                        </td>
                                        <td>
                                            ${escapeHtml(
                                                r.respondent?.email || ''
                                            )}
                                        </td>
                                        <td>
                                            <details>
                                                <summary>
                                                    回答を見る
                                                </summary>

                                                <div
                                                    class="detail-list"
                                                    style="margin-top:8px"
                                                >
                                                    ${questions.map(
                                                        q => `
                                                        <div class="detail-item">
                                                            <strong>
                                                                ${escapeHtml(
                                                                    q.questionNumber
                                                                )}
                                                            </strong>
                                                            ${escapeHtml(
                                                                q.questionText
                                                            )}
                                                            <br>
                                                            ${escapeHtml(
                                                                Answer.displayValue(
                                                                    q,
                                                                    r.answers?.[
                                                                        q.questionId
                                                                    ]
                                                                )
                                                            )}
                                                        </div>
                                                    `
                                                    ).join('')}
                                                </div>
                                            </details>
                                        </td>
                                    </tr>
                                `
                                ).join('')}
                            </tbody>
                        </table>
                    </div>
                    `
                    : `
                    <div class="empty">
                        回答はありません。
                    </div>
                    `
                }
            </div>
        `;
    },

    aggregationQuestion(q, responses) {
        const selected =
            App.state.aggregationSelection
                .has(q.questionId);

        if (!selected) {
            return '';
        }

        if (q.type === 'text') {
            const items =
                responses
                    .map(
                        r => ({
                            response: r,
                            value:
                                r.answers?.[
                                    q.questionId
                                ]
                        })
                    )
                    .filter(
                        x =>
                            x.value !== undefined &&
                            String(x.value).trim() !== ''
                    );

            return `
                <div class="card"
                     style="box-shadow:none">
                    <h4>
                        ${escapeHtml(
                            q.questionNumber
                        )}
                        ${escapeHtml(
                            q.questionText
                        )}
                    </h4>

                    ${
                        items.length
                        ? items.map(
                            x => `
                            <div class="detail-item">
                                <strong>
                                    ${escapeHtml(
                                        x.response.respondent?.name || '未登録回答者'
                                    )}
                                </strong>
                                /
                                ${escapeHtml(
                                    x.response.respondent?.email || ''
                                )}

                                <p>
                                    ${nl2br(
                                        escapeHtml(
                                            String(x.value)
                                        )
                                    )}
                                </p>
                            </div>
                        `
                        ).join('')
                        : `
                            <div class="empty">
                                回答がありません。
                            </div>
                        `
                    }
                </div>
            `;
        }

        const counts = {};

        for (const c of q.choices) {
            counts[c.choiceId] = 0;
        }

        for (const response of responses) {
            const value =
                response.answers?.[
                    q.questionId
                ];

            if (q.type === 'single') {
                if (value && counts[value] !== undefined) {
                    counts[value]++;
                }
            } else if (q.type === 'multiple') {
                if (Array.isArray(value)) {
                    for (const choiceId of value) {
                        if (
                            counts[choiceId] !==
                            undefined
                        ) {
                            counts[choiceId]++;
                        }
                    }
                }
            }
        }

        const total =
            q.type === 'single'
                ? responses.length
                : responses.filter(
                    r =>
                        Array.isArray(
                            r.answers?.[
                                q.questionId
                            ]
                        )
                ).length;

        const max =
            Math.max(
                1,
                ...Object.values(counts)
            );

        return `
            <div class="card"
                 style="box-shadow:none">
                <h4>
                    ${escapeHtml(
                        q.questionNumber
                    )}
                    ${escapeHtml(
                        q.questionText
                    )}
                </h4>

                <div class="bar-chart">
                    ${q.choices.map(
                        c => {
                            const count =
                                counts[c.choiceId] || 0;

                            const percentage =
                                total
                                    ? (
                                        count /
                                        total *
                                        100
                                    ).toFixed(1)
                                    : '0.0';

                            return `
                                <div class="bar-row">
                                    <div>
                                        ${escapeHtml(
                                            c.label
                                        )}
                                    </div>

                                    <div>
                                        <div class="bar-track">
                                            <div
                                                class="bar-fill"
                                                style="width:${
                                                    (
                                                        count /
                                                        max *
                                                        100
                                                    )
                                                }%"
                                            ></div>
                                        </div>
                                    </div>

                                    <div>
                                        ${count}件
                                        /
                                        ${percentage}%
                                    </div>
                                </div>
                            `;
                        }
                    ).join('')}
                </div>
            </div>
        `;
    },

    /* ---------------------------------------------------------------------
     * Kintone
     * ------------------------------------------------------------------ */

    kintone() {
        const data =
            App.state.kintone || {};

        const settings =
            data.settings || {};

        const mapping =
            data.mapping || {};

        const fields =
            data.fields || [];

        return `
            <div class="page-title">
                <div>
                    <h2>kintone連携設定</h2>
                </div>

                <button
                    class="btn"
                    onclick="
                        App.show(
                            'admin-survey-list'
                        )
                    "
                >
                    一覧へ
                </button>
            </div>

            <div class="card">
                <h3 class="section-title">
                    接続設定
                </h3>

                <div class="form-grid">
                    <div class="field form-full">
                        <label>
                            サブドメイン
                        </label>

                        <input
                            id="kSubdomain"
                            class="input"
                            value="${escapeAttr(
                                settings.subdomain || ''
                            )}"
                            placeholder="xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com"
                        >

                        <p class="muted">
                            xxxx、xxxx.cybozu.com、
                            https://xxxx.cybozu.com
                            のいずれも指定できます。
                        </p>
                    </div>

                    <div class="field">
                        <label>
                            顧客管理アプリID
                        </label>

                        <input
                            id="kAppId"
                            class="input"
                            value="${escapeAttr(
                                settings.appId || ''
                            )}"
                        >
                    </div>

                    <div class="field">
                        <label>
                            ログイン名
                        </label>

                        <input
                            id="kLoginName"
                            class="input"
                            value="${escapeAttr(
                                settings.loginName || ''
                            )}"
                        >
                    </div>

                    <div class="field">
                        <label>
                            パスワード
                        </label>

                        <input
                            id="kPassword"
                            class="input"
                            type="password"
                            placeholder="${
                                settings.hasPassword
                                    ? '変更する場合のみ入力'
                                    : ''
                            }"
                        >
                    </div>

                    <div class="field">
                        <label>
                            SSL証明書検証
                        </label>

                        <label class="inline-check">
                            <input
                                id="kSslVerify"
                                type="checkbox"
                                ${
                                    settings.sslVerify
                                    ? 'checked'
                                    : ''
                                }
                            >
                            検証する
                        </label>

                        <p class="muted">
                            初期値は「検証しない」です。
                        </p>
                    </div>

                    <div class="field form-full">
                        <label>
                            プロキシ
                        </label>

                        <input
                            id="kProxy"
                            class="input"
                            value="${escapeAttr(
                                settings.proxy || ''
                            )}"
                            placeholder="proxy.example.local:8080"
                        >

                        <p class="muted">
                            host:port形式。
                            プロキシ認証はありません。
                        </p>
                    </div>
                </div>

                <div class="toolbar"
                     style="margin-top:15px">
                    <button
                        class="btn btn-primary"
                        onclick="App.saveKintone()"
                    >
                        設定保存
                    </button>

                    <button
                        class="btn"
                        onclick="App.kintoneTest()"
                    >
                        接続テスト
                    </button>

                    <button
                        class="btn"
                        onclick="App.kintoneFields()"
                    >
                        項目一覧を再取得
                    </button>

                    <button
                        class="btn btn-success"
                        onclick="App.kintoneSync()"
                    >
                        顧客情報を同期
                    </button>
                </div>
            </div>

            <div class="card">
                <h3 class="section-title">
                    kintoneフィールド
                </h3>

                ${
                    fields.length
                    ? `
                        <div class="kintone-field-list">
                            ${fields.map(
                                f => `
                                <div class="kintone-field">
                                    <div>
                                        ${escapeHtml(
                                            f.code
                                        )}
                                    </div>
                                    <div>
                                        ${escapeHtml(
                                            f.label
                                        )}
                                    </div>
                                    <div>
                                        ${escapeHtml(
                                            f.type
                                        )}
                                    </div>
                                </div>
                            `
                            ).join('')}
                        </div>
                    `
                    : `
                        <div class="empty">
                            項目一覧がありません。
                            「項目一覧を再取得」を実行してください。
                        </div>
                    `
                }
            </div>

            <div class="card">
                <h3 class="section-title">
                    フィールドマッピング
                </h3>

                ${
                    fields.length
                    ? `
                    <div class="form-grid">
                        ${this.mappingSelect(
                            'mapOrganization',
                            '組織名',
                            mapping.organizationName,
                            fields
                        )}

                        ${this.mappingSelect(
                            'mapName',
                            '氏名',
                            mapping.name,
                            fields
                        )}

                        ${this.mappingSelect(
                            'mapEmail',
                            'メールアドレス',
                            mapping.email,
                            fields
                        )}

                        ${this.mappingSelect(
                            'mapDepartment',
                            '部署名',
                            mapping.department,
                            fields
                        )}

                        ${this.mappingSelect(
                            'mapPhone',
                            '電話番号',
                            mapping.phone,
                            fields
                        )}

                        <div class="field form-full">
                            <label>
                                住所
                            </label>

                            <p class="muted">
                                複数選択できます。
                                上から郵便番号、都道府県、
                                市区町村、番地、建物名として使用します。
                            </p>

                            <div class="detail-list">
                                ${fields.map(
                                    f => `
                                    <label
                                        class="inline-check"
                                    >
                                        <input
                                            type="checkbox"
                                            name="kAddress"
                                            value="${escapeAttr(
                                                f.code
                                            )}"
                                            ${
                                                (
                                                    mapping.address ||
                                                    []
                                                ).includes(
                                                    f.code
                                                )
                                                    ? 'checked'
                                                    : ''
                                            }
                                        >
                                        ${escapeHtml(
                                            f.label
                                        )}
                                        /
                                        ${escapeHtml(
                                            f.code
                                        )}
                                    </label>
                                `
                                ).join('')}
                            </div>
                        </div>
                    </div>

                    <button
                        class="btn btn-primary"
                        style="margin-top:15px"
                        onclick="App.saveKintoneMapping()"
                    >
                        マッピング保存
                    </button>
                    `
                    : `
                    <div class="empty">
                        先にkintone項目一覧を取得してください。
                    </div>
                    `
                }
            </div>
        `;
    },

    mappingSelect(id, label, value, fields) {
        return `
            <div class="field">
                <label>${escapeHtml(label)}</label>

                <select id="${escapeAttr(id)}">
                    <option value="">
                        未設定
                    </option>

                    ${fields.map(
                        f => `
                        <option
                            value="${escapeAttr(
                                f.code
                            )}"
                            ${selected(
                                value,
                                f.code
                            )}
                        >
                            ${escapeHtml(
                                f.label
                            )}
                            /
                            ${escapeHtml(
                                f.code
                            )}
                        </option>
                    `
                    ).join('')}
                </select>
            </div>
        `;
    },

    /* ---------------------------------------------------------------------
     * Mail settings
     * ------------------------------------------------------------------ */

    mail() {
        const mail =
            App.state.mail || {};

        return `
            <div class="page-title">
                <div>
                    <h2>メールサーバ設定</h2>
                    <div class="muted">
                        接続状態：
                        <strong>
                            ${escapeHtml(
                                mail.status ||
                                '未設定'
                            )}
                        </strong>
                    </div>
                </div>

                <button
                    class="btn"
                    onclick="
                        App.show(
                            'admin-survey-list'
                        )
                    "
                >
                    一覧へ
                </button>
            </div>

            <div class="card">
                <div class="form-grid">
                    <div class="field">
                        <label>
                            SMTPサーバ
                        </label>
                        <input
                            id="smtpServer"
                            class="input"
                            value="${escapeAttr(
                                mail.smtpServer || ''
                            )}"
                        >
                    </div>

                    <div class="field">
                        <label>
                            SMTPポート
                        </label>
                        <input
                            id="smtpPort"
                            class="input"
                            type="number"
                            min="1"
                            max="65535"
                            value="${escapeAttr(
                                mail.smtpPort || 587
                            )}"
                        >
                    </div>

                    <div class="field">
                        <label>
                            暗号化方式
                        </label>
                        <select id="smtpEncryption">
                            <option
                                value="none"
                                ${selected(
                                    mail.encryption,
                                    'none'
                                )}
                            >
                                なし
                            </option>
                            <option
                                value="tls"
                                ${selected(
                                    mail.encryption,
                                    'tls'
                                )}
                            >
                                TLS / STARTTLS
                            </option>
                            <option
                                value="ssl"
                                ${selected(
                                    mail.encryption,
                                    'ssl'
                                )}
                            >
                                SSL
                            </option>
                        </select>
                    </div>

                    <div class="field">
                        <label>
                            SMTP認証
                        </label>
                        <label class="inline-check">
                            <input
                                id="smtpAuth"
                                type="checkbox"
                                ${
                                    mail.auth !== false
                                        ? 'checked'
                                        : ''
                                }
                            >
                            認証する
                        </label>
                    </div>

                    <div class="field">
                        <label>
                            SMTPユーザー名
                        </label>
                        <input
                            id="smtpUsername"
                            class="input"
                            value="${escapeAttr(
                                mail.username || ''
                            )}"
                        >
                    </div>

                    <div class="field">
                        <label>
                            SMTPパスワード
                        </label>
                        <input
                            id="smtpPassword"
                            class="input"
                            type="password"
                            placeholder="${
                                mail.hasPassword
                                    ? '変更する場合のみ入力'
                                    : ''
                            }"
                        >
                    </div>

                    <div class="field">
                        <label>
                            送信元メールアドレス
                        </label>
                        <input
                            id="smtpFromEmail"
                            class="input"
                            value="${escapeAttr(
                                mail.fromEmail || ''
                            )}"
                        >
                    </div>

                    <div class="field">
                        <label>
                            送信元名
                        </label>
                        <input
                            id="smtpFromName"
                            class="input"
                            value="${escapeAttr(
                                mail.fromName || ''
                            )}"
                        >
                    </div>

                    <div class="field form-full">
                        <label>
                            返信先メールアドレス
                        </label>
                        <input
                            id="smtpReplyTo"
                            class="input"
                            value="${escapeAttr(
                                mail.replyTo || ''
                            )}"
                        >
                    </div>
                </div>

                <div class="toolbar"
                     style="margin-top:15px">
                    <button
                        class="btn btn-primary"
                        onclick="App.saveMail()"
                    >
                        設定保存
                    </button>
                </div>
            </div>

            <div class="card">
                <h3 class="section-title">
                    テストメール
                </h3>

                <div class="toolbar">
                    <input
                        id="testMailTo"
                        class="input"
                        style="max-width:420px"
                        placeholder="test@example.com"
                    >

                    <button
                        class="btn btn-success"
                        onclick="App.testMail()"
                    >
                        テストメール送信
                    </button>
                </div>
            </div>
        `;
    },

    /* ---------------------------------------------------------------------
     * Respondent
     * ------------------------------------------------------------------ */

    respondentAnswer() {
        const survey =
            App.findSurvey(
                App.state.answerSurveyId
            );

        if (!survey) {
            return '';
        }

        const questions =
            Answer.visibleQuestions(
                survey,
                App.state.answers
            );

        return `
            <div class="respondent-shell">
                <header class="respondent-header">
                    <div class="respondent-header-inner">
                        <strong>
                            アンケート
                        </strong>
                    </div>
                </header>

                <main class="respondent-main">
                    <div class="respondent-card">
                        <h1>
                            ${escapeHtml(
                                survey.title
                            )}
                        </h1>

                        ${
                            survey.description
                            ? `
                            <p>
                                ${nl2br(
                                    escapeHtml(
                                        survey.description
                                    )
                                )}
                            </p>
                            `
                            : ''
                        }

                        <div id="answerErrors"></div>

                        ${questions.map(
                            q =>
                                this.answerQuestion(
                                    q
                                )
                        ).join('')}

                        <div class="toolbar"
                             style="
                                justify-content:flex-end;
                                margin-top:25px;
                             ">
                            <button
                                class="btn btn-primary"
                                onclick="App.answerNext()"
                            >
                                回答内容を確認する
                            </button>
                        </div>
                    </div>
                </main>
            </div>
        `;
    },

    answerQuestion(q) {
        const value =
            App.state.answers[q.questionId];

        return `
            <div
                class="answer-question"
                data-question-id="${escapeAttr(
                    q.questionId
                )}"
            >
                <div>
                    <strong>
                        ${escapeHtml(
                            q.questionNumber
                        )}
                    </strong>

                    ${
                        q.required
                        ? `
                            <span class="required-mark">
                                必須
                            </span>
                        `
                        : ''
                    }
                </div>

                <h3>
                    ${escapeHtml(
                        q.questionText
                    )}
                </h3>

                ${
                    q.type === 'text'
                    ? `
                        <textarea
                            class="input"
                            placeholder="回答を入力してください"
                            oninput="
                                App.state.answers[
                                    '${escapeJs(
                                        q.questionId
                                    )}'
                                ]=this.value
                            "
                        >${escapeHtml(
                            value || ''
                        )}</textarea>
                    `
                    : q.choices.map(
                        c => `
                        <label class="answer-choice">
                            <input
                                type="${
                                    q.type === 'single'
                                        ? 'radio'
                                        : 'checkbox'
                                }"
                                name="q_${
                                    escapeAttr(
                                        q.questionId
                                    )
                                }"
                                value="${escapeAttr(
                                    c.choiceId
                                )}"
                                ${
                                    q.type === 'single'
                                        ? (
                                            String(
                                                value || ''
                                            ) ===
                                            String(
                                                c.choiceId
                                            )
                                                ? 'checked'
                                                : ''
                                        )
                                        : (
                                            Array.isArray(value) &&
                                            value.includes(
                                                c.choiceId
                                            )
                                                ? 'checked'
                                                : ''
                                        )
                                }
                                onchange="
                                    AnswerInput.change(
                                        '${escapeJs(
                                            q.questionId
                                        )}',
                                        '${escapeJs(
                                            q.type
                                        )}',
                                        this
                                    )
                                "
                            >

                            <span>
                                ${escapeHtml(
                                    c.label
                                )}
                            </span>
                        </label>
                    `
                    ).join('')
                }
            </div>
        `;
    },

    respondentConfirm() {
        const survey =
            App.findSurvey(
                App.state.answerSurveyId
            );

        if (!survey) {
            return '';
        }

        const questions =
            Answer.visibleQuestions(
                survey,
                App.state.answers
            );

        return `
            <header class="respondent-header">
                <div class="respondent-header-inner">
                    <strong>
                        回答内容確認
                    </strong>
                </div>
            </header>

            <main class="respondent-main">
                <div class="respondent-card">
                    <h1>
                        回答内容確認
                    </h1>

                    <div class="warning-box">
                        送信前に回答内容をご確認ください。
                    </div>

                    ${questions.map(
                        q => `
                        <div class="detail-item"
                             style="margin-bottom:10px">
                            <strong>
                                ${escapeHtml(
                                    q.questionNumber
                                )}
                            </strong>

                            <div>
                                ${escapeHtml(
                                    q.questionText
                                )}
                            </div>

                            <p>
                                ${
                                    nl2br(
                                        escapeHtml(
                                            Answer.displayValue(
                                                q,
                                                App.state.answers[
                                                    q.questionId
                                                ]
                                            ) ||
                                            '未回答'
                                        )
                                    )
                                }
                            </p>
                        </div>
                    `).join('')}

                    <div class="toolbar"
                         style="
                            justify-content:space-between;
                            margin-top:20px;
                         ">
                        <button
                            class="btn"
                            onclick="App.answerBack()"
                        >
                            戻って修正
                        </button>

                        <button
                            class="btn btn-primary"
                            onclick="App.confirmSubmit()"
                        >
                            回答を送信
                        </button>
                    </div>
                </div>
            </main>
        `;
    },

    respondentComplete() {
        return `
            <header class="respondent-header">
                <div class="respondent-header-inner">
                    <strong>
                        回答完了
                    </strong>
                </div>
            </header>

            <main class="respondent-main">
                <div class="respondent-card">
                    <h1>
                        回答ありがとうございました
                    </h1>

                    <div class="success-box">
                        回答を正常に受け付けました。
                    </div>

                    <p>
                        この画面を閉じてください。
                    </p>
                </div>
            </main>
        `;
    }
};

/* =========================================================================
 * D&D
 * ========================================================================= */

const DnD = {
    install() {
        document.querySelectorAll(
            '.group-header'
        ).forEach(header => {
            header.draggable = true;

            header.addEventListener(
                'dragstart',
                e => {
                    const group =
                        header.closest('.group');

                    e.dataTransfer.setData(
                        'group-id',
                        group.dataset.groupId
                    );
                }
            );

            header.addEventListener(
                'dragover',
                e => e.preventDefault()
            );

            header.addEventListener(
                'drop',
                e => {
                    e.preventDefault();

                    const source =
                        e.dataTransfer.getData(
                            'group-id'
                        );

                    const target =
                        header.closest('.group')
                            ?.dataset.groupId;

                    if (
                        source &&
                        target &&
                        source !== target
                    ) {
                        DnD.moveGroup(
                            source,
                            target
                        );
                    }
                }
            );
        });

        document.querySelectorAll(
            '.question'
        ).forEach(question => {
            question.draggable = true;

            question.addEventListener(
                'dragstart',
                e => {
                    e.dataTransfer.setData(
                        'question-id',
                        question.dataset.questionId
                    );
                }
            );
        });

        document.querySelectorAll(
            '.question-list'
        ).forEach(list => {
            list.addEventListener(
                'dragover',
                e => e.preventDefault()
            );

            list.addEventListener(
                'drop',
                e => {
                    e.preventDefault();

                    const qid =
                        e.dataTransfer.getData(
                            'question-id'
                        );

                    if (!qid) {
                        return;
                    }

                    const targetGroup =
                        list.dataset.questionList;

                    const targetQuestion =
                        e.target.closest(
                            '.question'
                        );

                    const targetIndex =
                        targetQuestion
                            ? [...list.querySelectorAll(
                                '.question'
                            )].indexOf(
                                targetQuestion
                            )
                            : null;

                    App.moveQuestion(
                        qid,
                        targetGroup,
                        targetIndex
                    );
                }
            );
        });
    },

    moveGroup(sourceId, targetId) {
        const survey =
            App.state.editingSurvey;

        const sourceIndex =
            survey.groups.findIndex(
                g => g.groupId === sourceId
            );

        const targetIndex =
            survey.groups.findIndex(
                g => g.groupId === targetId
            );

        if (
            sourceIndex < 0 ||
            targetIndex < 0
        ) {
            return;
        }

        const [source] =
            survey.groups.splice(
                sourceIndex,
                1
            );

        survey.groups.splice(
            targetIndex,
            0,
            source
        );

        Survey.recalculate(survey);
        App.render();
    }
};

/* =========================================================================
 * Answer input
 * ========================================================================= */

const AnswerInput = {
    change(questionId, type, element) {
        if (type === 'single') {
            App.state.answers[questionId] =
                element.value;
        } else if (type === 'multiple') {
            const checked =
                [...document.querySelectorAll(
                    `input[name="q_${CSS.escape(
                        questionId
                    )}"]:checked`
                )].map(
                    el => el.value
                );

            App.state.answers[questionId] =
                checked;
        }
    }
};

/* =========================================================================
 * Modal
 * ========================================================================= */

const Modal = {
    confirm(title, message, callback, options = {}) {
        const root =
            document.getElementById(
                'modalRoot'
            );

        root.innerHTML = `
            <div class="modal-backdrop"
                 id="commonModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3 style="margin:0">
                            ${escapeHtml(title)}
                        </h3>
                    </div>

                    <div class="modal-body">
                        ${
                            message
                            ? `<p>${nl2br(
                                escapeHtml(
                                    message
                                )
                            )}</p>`
                            : ''
                        }

                        ${
                            options.preview ||
                            ''
                        }
                    </div>

                    <div class="modal-footer">
                        <button
                            class="btn"
                            onclick="Modal.close()"
                        >
                            キャンセル
                        </button>

                        <button
                            class="btn btn-primary"
                            id="modalExecute"
                        >
                            実行
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.getElementById(
            'modalExecute'
        ).onclick = async () => {
            Modal.close();

            try {
                await callback();
            } catch (e) {
                Toast.error(e.message);
            }
        };
    },

    close() {
        document.getElementById(
            'modalRoot'
        ).innerHTML = '';
    }
};

/* =========================================================================
 * Toast
 * ========================================================================= */

const Toast = {
    show(message, type = '') {
        const area =
            document.getElementById(
                'toastArea'
            );

        const item =
            document.createElement('div');

        item.className =
            `toast ${type}`;

        item.textContent = message;

        area.appendChild(item);

        setTimeout(
            () => item.remove(),
            5000
        );
    },

    success(message) {
        this.show(message, 'success');
    },

    error(message) {
        this.show(message, 'error');
    }
};

/* =========================================================================
 * App helper methods added after object definition
 * ========================================================================= */

App.filteredSurveys = function() {
    const query =
        this.state.surveySearch
            .trim()
            .toLowerCase();

    let surveys =
        this.state.surveys.filter(
            s => {
                const filter =
                    this.state.surveyFilter;

                if (
                    filter !== 'all' &&
                    s.status !== filter
                ) {
                    return false;
                }

                if (!query) {
                    return true;
                }

                return (
                    s.title ||
                    ''
                )
                    .toLowerCase()
                    .includes(query);
            }
        );

    const count = survey =>
        this.state.responses.filter(
            r =>
                r.surveyId ===
                survey.surveyId
        ).length;

    surveys.sort(
        (a, b) => {
            switch (
                this.state.surveySort
            ) {
                case 'updated_asc':
                    return dateValue(
                        a.updatedAt
                    ) -
                    dateValue(
                        b.updatedAt
                    );

                case 'responses_desc':
                    return count(b) -
                        count(a);

                case 'responses_asc':
                    return count(a) -
                        count(b);

                case 'start_desc':
                    return dateValue(
                        b.startDate
                    ) -
                    dateValue(
                        a.startDate
                    );

                case 'start_asc':
                    return dateValue(
                        a.startDate
                    ) -
                    dateValue(
                        b.startDate
                    );

                default:
                    return dateValue(
                        b.updatedAt
                    ) -
                    dateValue(
                        a.updatedAt
                    );
            }
        }
    );

    return surveys;
};

App.applySurveySearch = function() {
    this.state.surveySearch =
        document.getElementById(
            'surveySearch'
        )?.value || '';

    this.render();
};

Views.updateGroupTitle = function(
    groupId,
    title
) {
    const group =
        App.state.editingSurvey?.groups.find(
            g => g.groupId === groupId
        );

    if (group) {
        group.title = title;
    }
};

App.updateBranch = function(
    questionId,
    index,
    key,
    value
) {
    const q =
        this.getQuestion(
            this.state.editingSurvey,
            questionId
        );

    if (!q || !q.branchRules[index]) {
        return;
    }

    q.branchRules[index][key] = value;
};

App.findQuestion = function(
    survey,
    questionId
) {
    return this.getQuestion(
        survey,
        questionId
    );
};

/* =========================================================================
 * Utility
 * ========================================================================= */

function flattenQuestions(survey) {
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

function id(prefix) {
    if (
        window.crypto &&
        crypto.randomUUID
    ) {
        return `${prefix}_${crypto.randomUUID()}`;
    }

    return (
        prefix +
        '_' +
        Math.random()
            .toString(36)
            .slice(2) +
        Date.now()
    );
}

function deepClone(value) {
    return JSON.parse(
        JSON.stringify(value)
    );
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function escapeAttr(value) {
    return escapeHtml(value);
}

function escapeJs(value) {
    return String(value ?? '')
        .replaceAll('\\', '\\\\')
        .replaceAll("'", "\\'")
        .replaceAll('\n', '\\n')
        .replaceAll('\r', '\\r');
}

function selected(a, b) {
    return String(a ?? '') ===
        String(b ?? '')
        ? 'selected'
        : '';
}

function checked(value) {
    return value ? 'checked' : '';
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    const date =
        new Date(value);

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {
        return escapeHtml(value);
    }

    return new Intl.DateTimeFormat(
        'ja-JP',
        {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }
    ).format(date);
}

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    const date =
        new Date(value);

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {
        return escapeHtml(value);
    }

    return new Intl.DateTimeFormat(
        'ja-JP',
        {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        }
    ).format(date);
}

function dateValue(value) {
    if (!value) {
        return 0;
    }

    const d =
        new Date(value).getTime();

    return Number.isNaN(d)
        ? 0
        : d;
}

function toDatetimeLocal(value) {
    if (!value) {
        return '';
    }

    const date =
        new Date(value);

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {
        return String(value)
            .replace(' ', 'T')
            .slice(0, 16);
    }

    const pad = n =>
        String(n).padStart(2, '0');

    return (
        date.getFullYear() +
        '-' +
        pad(date.getMonth() + 1) +
        '-' +
        pad(date.getDate()) +
        'T' +
        pad(date.getHours()) +
        ':' +
        pad(date.getMinutes())
    );
}

function statusBadge(status) {
    const labels = {
        draft: '下書き',
        published: '公開中',
        stopped: '停止',
        finished: '終了'
    };

    return `
        <span
            class="status status-${escapeAttr(
                status
            )}"
        >
            ${labels[status] || status}
        </span>
    `;
}

function nl2br(value) {
    return String(value)
        .replace(/\n/g, '<br>');
}

function csvEscape(value) {
    const text =
        String(value ?? '');

    return '"' +
        text.replaceAll('"', '""') +
        '"';
}

/*
 * SHA-256:
 * Web Cryptoがある場合は非同期になるため、
 * メールURL生成用にはサーバー側と同じ決定的値が必要。
 *
 * ブラウザ側では完全一致が必要なため、
 * Web Cryptoの代わりに簡易ハッシュを使用しない。
 * 実送信時のURLはPHPが生成する。
 *
 * プレビュー用にのみ使用。
 */
function sha256Local(value) {
    /*
     * PHP側URLとの厳密一致が必要な本番用途では
     * server-generated tokenを使用すべき。
     *
     * このプロトタイプでは送信時PHP側でURLを生成するため、
     * ここはプレビュー用の安定IDとして利用する。
     */
    let h1 = 0xdeadbeef;
    let h2 = 0x41c6ce57;

    for (let i = 0; i < value.length; i++) {
        const ch =
            value.charCodeAt(i);

        h1 =
            Math.imul(
                h1 ^ ch,
                2654435761
            );

        h2 =
            Math.imul(
                h2 ^ ch,
                1597334677
            );
    }

    h1 =
        Math.imul(
            h1 ^ (h1 >>> 16),
            2246822507
        );

    h1 ^=
        Math.imul(
            h2 ^ (h2 >>> 13),
            3266489909
        );

    return (
        (h1 >>> 0)
            .toString(16)
            .padStart(8, '0') +
        (h2 >>> 0)
            .toString(16)
            .padStart(8, '0')
    );
}

/* =========================================================================
 * Start
 * ========================================================================= */

document.addEventListener(
    'DOMContentLoaded',
    () => App.init()
);
</script>

</body>
</html>
<?php
declare(strict_types=1);

/*
 * アンケート管理システム
 * PHP 8.5 / Apache 2.4
 * DB不使用 / JSON永続化
 *
 * 必要ディレクトリ:
 *   index.php
 *   data/              ← PHPが自動生成
 */

const DATA_DIR = __DIR__ . '/data';

const FILES = [
    'surveys' => DATA_DIR . '/surveys.json',
    'customers' => DATA_DIR . '/customers.json',
    'responses' => DATA_DIR . '/responses.json',
    'history' => DATA_DIR . '/send_history.json',
    'kintone' => DATA_DIR . '/kintone.json',
    'mail' => DATA_DIR . '/mail.json',
];

date_default_timezone_set('Asia/Tokyo');

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}

/* =========================================================
 * PHP JSON API
 * ========================================================= */

function jsonResponse(bool $ok, mixed $data = null, ?string $code = null, ?string $message = null, int $status = 200): never
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $ok
            ? ['ok' => true, 'data' => $data]
            : ['ok' => false, 'error' => ['code' => $code, 'message' => $message]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function readJson(string $name, mixed $default = []): mixed
{
    $file = FILES[$name] ?? null;

    if (!$file) {
        throw new RuntimeException("Unknown JSON file: {$name}");
    }

    if (!file_exists($file)) {
        return $default;
    }

    $fp = fopen($file, 'rb');

    if (!$fp) {
        throw new RuntimeException("JSONファイルを開けません: {$name}");
    }

    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException(
            "{$name}.json のJSONが壊れています: " . json_last_error_msg()
        );
    }

    return $data;
}

function writeJson(string $name, mixed $data): void
{
    $file = FILES[$name] ?? null;

    if (!$file) {
        throw new RuntimeException("Unknown JSON file: {$name}");
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));

    $fp = fopen($tmp, 'wb');

    if (!$fp) {
        throw new RuntimeException("一時ファイルを作成できません");
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        @unlink($tmp);
        throw new RuntimeException("JSONロック取得に失敗しました");
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($tmp);
        throw new RuntimeException('JSONエンコードに失敗しました');
    }

    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException("JSON保存に失敗しました");
    }
}

function uid(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function nowIso(): string
{
    return date('Y-m-d\TH:i:s');
}

function initialChoice(string $label): array
{
    return [
        'choiceId' => uid('choice'),
        'label' => $label,
        'sortOrder' => 0,
    ];
}

function initialQuestion(string $text, string $type = 'single'): array
{
    $q = [
        'questionId' => uid('question'),
        'groupId' => '',
        'sortOrder' => 0,
        'questionNumber' => '',
        'questionText' => $text,
        'type' => $type,
        'required' => true,
        'choices' => [],
        'branchRules' => [],
    ];

    if ($type !== 'text') {
        $q['choices'] = [
            initialChoice('はい'),
            initialChoice('いいえ'),
        ];
    }

    return $q;
}

function initialGroup(string $title, string $questionText = ''): array
{
    $groupId = uid('group');

    $q = initialQuestion($questionText ?: '質問文を入力してください');
    $q['groupId'] = $groupId;

    return [
        'groupId' => $groupId,
        'title' => $title,
        'sortOrder' => 0,
        'questions' => [$q],
    ];
}

function createSurvey(string $title, string $status = 'draft', ?string $endDate = null): array
{
    return [
        'surveyId' => uid('survey'),
        'title' => $title,
        'description' => 'アンケートの説明です。',
        'startDate' => date('Y-m-d\TH:i'),
        'endDate' => $endDate,
        'questionNumberMode' => 'all',
        'status' => $status,
        'allowReanswer' => false,
        'createdAt' => nowIso(),
        'updatedAt' => nowIso(),
        'groups' => [
            initialGroup('基本情報', 'このアンケートについてどう思いますか？'),
        ],
    ];
}

function recalcSurvey(array &$survey): void
{
    usort($survey['groups'], fn($a, $b) =>
        ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0)
    );

    foreach ($survey['groups'] as $gi => &$group) {
        $group['sortOrder'] = $gi;

        usort($group['questions'], fn($a, $b) =>
            ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0)
        );

        foreach ($group['questions'] as $qi => &$q) {
            $q['groupId'] = $group['groupId'];
            $q['sortOrder'] = $qi;

            if ($survey['questionNumberMode'] === 'group') {
                $q['questionNumber'] = 'Q' . ($gi + 1) . '-' . ($qi + 1);
            } else {
                $number = 1;

                for ($x = 0; $x < $gi; $x++) {
                    $number += count($survey['groups'][$x]['questions']);
                }

                $q['questionNumber'] = 'Q' . ($number + $qi);
            }

            foreach ($q['choices'] as $ci => &$choice) {
                $choice['sortOrder'] = $ci;
            }
            unset($choice);
        }
        unset($q);
    }
    unset($group);

    $survey['updatedAt'] = nowIso();
}

function applyExpiry(array &$surveys): bool
{
    $changed = false;
    $now = time();

    foreach ($surveys as &$survey) {
        if (
            ($survey['status'] ?? '') === 'published' &&
            !empty($survey['endDate']) &&
            strtotime($survey['endDate']) !== false &&
            $now > strtotime($survey['endDate'])
        ) {
            $survey['status'] = 'finished';
            $survey['updatedAt'] = nowIso();
            $changed = true;
        }
    }

    unset($survey);
    return $changed;
}

function ensureData(): void
{
    if (!file_exists(FILES['surveys'])) {
        $past = date('Y-m-d\TH:i', time() - 86400);

        $surveys = [
            createSurvey('下書きサンプル', 'draft'),
            createSurvey('公開中サンプル', 'published', date('Y-m-d\TH:i', time() + 86400 * 30)),
            createSurvey('停止サンプル', 'stopped', date('Y-m-d\TH:i', time() + 86400 * 30)),
            createSurvey('終了サンプル', 'finished', $past),
            createSurvey('下書き＋過去日時', 'draft', $past),
            createSurvey('公開中＋過去日時', 'published', $past),
            createSurvey('停止＋過去日時', 'stopped', $past),
        ];

        foreach ($surveys as &$survey) {
            recalcSurvey($survey);
        }

        writeJson('surveys', $surveys);
    }

    if (!file_exists(FILES['customers'])) {
        writeJson('customers', [
            [
                'customerId' => uid('customer'),
                'organizationName' => 'サンプル株式会社',
                'name' => '山田 太郎',
                'email' => 'example@example.com',
                'department' => '営業部',
                'phone' => '03-0000-0000',
                'address' => '東京都港区',
                'lastSentAt' => null,
                'sendCount' => 0,
                'answerStatus' => '未送信',
                'kintoneStatus' => '未登録',
            ],
            [
                'customerId' => uid('customer'),
                'organizationName' => 'テスト合同会社',
                'name' => '佐藤 花子',
                'email' => 'test@example.com',
                'department' => '管理部',
                'phone' => '03-1111-1111',
                'address' => '東京都渋谷区',
                'lastSentAt' => null,
                'sendCount' => 0,
                'answerStatus' => '未送信',
                'kintoneStatus' => '未登録',
            ],
        ]);
    }

    foreach ([
        'responses' => [],
        'history' => [],
        'kintone' => [
            'settings' => [
                'subdomain' => '',
                'appId' => '',
                'loginName' => '',
                'password' => '',
                'sslVerify' => false,
                'proxy' => '',
            ],
            'fields' => [],
            'mapping' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
        ],
        'mail' => [
            'smtpServer' => '',
            'smtpPort' => 587,
            'encryption' => 'tls',
            'authentication' => true,
            'username' => '',
            'password' => '',
            'fromEmail' => '',
            'fromName' => '',
            'replyTo' => '',
        ],
    ] as $name => $value) {
        if (!file_exists(FILES[$name])) {
            writeJson($name, $value);
        }
    }
}

function surveyById(array $surveys, string $id): ?array
{
    foreach ($surveys as $survey) {
        if (($survey['surveyId'] ?? '') === $id) {
            return $survey;
        }
    }
    return null;
}

function normalizeSurvey(array $survey): array
{
    $survey['groups'] ??= [];

    foreach ($survey['groups'] as &$group) {
        $group['groupId'] ??= uid('group');
        $group['title'] ??= '';
        $group['sortOrder'] ??= 0;
        $group['questions'] ??= [];

        foreach ($group['questions'] as &$q) {
            $q['questionId'] ??= uid('question');
            $q['groupId'] = $group['groupId'];
            $q['sortOrder'] ??= 0;
            $q['questionText'] ??= '';
            $q['type'] = in_array($q['type'] ?? '', ['single', 'multiple', 'text'], true)
                ? $q['type']
                : 'single';
            $q['required'] = (bool)($q['required'] ?? false);
            $q['choices'] ??= [];
            $q['branchRules'] ??= [];
        }
        unset($q);
    }
    unset($group);

    $survey['questionNumberMode'] =
        ($survey['questionNumberMode'] ?? 'all') === 'group'
            ? 'group'
            : 'all';

    return $survey;
}

function validateSurvey(array $survey): array
{
    if (trim((string)($survey['title'] ?? '')) === '') {
        throw new InvalidArgumentException('アンケートタイトルは必須です。');
    }

    if (!in_array($survey['status'] ?? 'draft', ['draft', 'published', 'stopped', 'finished'], true)) {
        throw new InvalidArgumentException('不正なステータスです。');
    }

    $survey = normalizeSurvey($survey);
    recalcSurvey($survey);

    return $survey;
}

/* =========================================================
 * API
 * ========================================================= */

ensureData();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            $input = $_POST;
        } else {
            $input = json_decode($raw, true);

            if (!is_array($input)) {
                jsonResponse(false, null, 'INVALID_JSON', 'JSON形式が不正です。', 400);
            }
        }

        $action = $input['action'] ?? '';

        if ($action === 'load_data') {
            $surveys = readJson('surveys', []);
            if (applyExpiry($surveys)) {
                writeJson('surveys', $surveys);
            }

            jsonResponse(true, [
                'surveys' => $surveys,
                'customers' => readJson('customers', []),
                'responses' => readJson('responses', []),
                'history' => readJson('history', []),
                'kintone' => readJson('kintone', []),
                'mail' => readJson('mail', []),
            ]);
        }

        if ($action === 'save_survey') {
            $survey = validateSurvey($input['survey'] ?? []);

            $surveys = readJson('surveys', []);
            $found = false;

            foreach ($surveys as $i => $old) {
                if ($old['surveyId'] === $survey['surveyId']) {
                    $survey['createdAt'] = $old['createdAt'] ?? nowIso();
                    $survey['status'] = $old['status'] ?? 'draft';

                    if ($survey['status'] === 'finished') {
                        $survey['status'] = 'finished';
                    }

                    $surveys[$i] = $survey;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $survey['surveyId'] = uid('survey');
                $survey['status'] = 'draft';
                $survey['createdAt'] = nowIso();
                $survey['updatedAt'] = nowIso();
                $surveys[] = $survey;
            }

            writeJson('surveys', $surveys);

            jsonResponse(true, $survey);
        }

        if ($action === 'change_status') {
            $id = (string)($input['surveyId'] ?? '');
            $next = (string)($input['status'] ?? '');

            $allowed = [
                'draft' => ['published'],
                'published' => ['stopped'],
                'stopped' => ['published'],
                'finished' => [],
            ];

            $surveys = readJson('surveys', []);
            $found = false;

            foreach ($surveys as &$survey) {
                if ($survey['surveyId'] !== $id) {
                    continue;
                }

                $current = $survey['status'];

                if (!in_array($next, $allowed[$current] ?? [], true)) {
                    throw new InvalidArgumentException(
                        "状態変更 {$current} → {$next} は許可されていません。"
                    );
                }

                $survey['status'] = $next;
                $survey['updatedAt'] = nowIso();
                $found = true;
                break;
            }
            unset($survey);

            if (!$found) {
                throw new RuntimeException('対象アンケートがありません。');
            }

            writeJson('surveys', $surveys);
            jsonResponse(true);
        }

        if ($action === 'delete_survey') {
            $id = (string)($input['surveyId'] ?? '');

            $surveys = array_values(array_filter(
                readJson('surveys', []),
                fn($s) => $s['surveyId'] !== $id
            ));

            writeJson('surveys', $surveys);

            $responses = array_values(array_filter(
                readJson('responses', []),
                fn($r) => ($r['surveyId'] ?? '') !== $id
            ));

            writeJson('responses', $responses);

            jsonResponse(true);
        }

        if ($action === 'duplicate_survey') {
            $id = (string)($input['surveyId'] ?? '');
            $surveys = readJson('surveys', []);
            $source = surveyById($surveys, $id);

            if (!$source) {
                throw new RuntimeException('複製対象がありません。');
            }

            $copy = $source;
            $copy['surveyId'] = uid('survey');
            $copy['title'] .= '（複製）';
            $copy['status'] = 'draft';
            $copy['createdAt'] = nowIso();
            $copy['updatedAt'] = nowIso();

            foreach ($copy['groups'] as &$group) {
                $group['groupId'] = uid('group');

                foreach ($group['questions'] as &$q) {
                    $q['questionId'] = uid('question');
                    $q['groupId'] = $group['groupId'];

                    foreach ($q['choices'] as &$choice) {
                        $choice['choiceId'] = uid('choice');
                    }
                    unset($choice);

                    foreach ($q['branchRules'] as &$rule) {
                        $rule['questionId'] = $q['questionId'];
                        $rule['nextQuestionId'] = '';
                    }
                    unset($rule);
                }
                unset($q);
            }
            unset($group);

            recalcSurvey($copy);
            $surveys[] = $copy;

            writeJson('surveys', $surveys);

            jsonResponse(true, $copy);
        }

        if ($action === 'save_response') {
            $surveyId = (string)($input['surveyId'] ?? '');
            $token = trim((string)($input['answerToken'] ?? ''));
            $answers = $input['answers'] ?? [];
            $respondent = $input['respondent'] ?? [];

            $surveys = readJson('surveys', []);

            foreach ($surveys as &$s) {
                if ($s['surveyId'] === $surveyId) {
                    if (
                        $s['status'] === 'published' &&
                        !empty($s['endDate']) &&
                        time() > strtotime($s['endDate'])
                    ) {
                        $s['status'] = 'finished';
                    }

                    $survey = $s;
                    break;
                }
            }
            unset($s);

            if (!isset($survey)) {
                throw new RuntimeException('アンケートが存在しません。');
            }

            if ($survey['status'] !== 'published') {
                throw new RuntimeException('このアンケートは回答できません。');
            }

            if ($token === '') {
                $token = uid('answer');
            }

            $responses = readJson('responses', []);

            foreach ($responses as $r) {
                if (
                    ($r['surveyId'] ?? '') === $surveyId &&
                    ($r['answerToken'] ?? '') === $token &&
                    !$survey['allowReanswer']
                ) {
                    throw new RuntimeException('このアンケートは回答済みです。');
                }
            }

            $response = [
                'responseId' => uid('response'),
                'surveyId' => $surveyId,
                'answerToken' => $token,
                'respondentId' => $respondent['customerId'] ?? null,
                'respondent' => $respondent,
                'answers' => $answers,
                'createdAt' => nowIso(),
            ];

            $responses[] = $response;
            writeJson('responses', $responses);

            if (!empty($respondent['customerId'])) {
                $customers = readJson('customers', []);

                foreach ($customers as &$customer) {
                    if ($customer['customerId'] === $respondent['customerId']) {
                        $customer['answerStatus'] = '回答済み';
                        break;
                    }
                }
                unset($customer);

                writeJson('customers', $customers);
            }

            jsonResponse(true, $response);
        }

        if ($action === 'save_mail') {
            $mail = $input['mail'] ?? [];

            $allowed = [
                'smtpServer',
                'smtpPort',
                'encryption',
                'authentication',
                'username',
                'password',
                'fromEmail',
                'fromName',
                'replyTo',
            ];

            $out = readJson('mail', []);

            foreach ($allowed as $key) {
                if (array_key_exists($key, $mail)) {
                    $out[$key] = $mail[$key];
                }
            }

            writeJson('mail', $out);

            jsonResponse(true, $out);
        }

        if ($action === 'save_kintone') {
            $settings = $input['settings'] ?? [];
            $old = readJson('kintone', []);

            $out = [
                'settings' => [
                    'subdomain' => trim((string)($settings['subdomain'] ?? '')),
                    'appId' => trim((string)($settings['appId'] ?? '')),
                    'loginName' => trim((string)($settings['loginName'] ?? '')),
                    'password' => (string)($settings['password'] ?? ''),
                    'sslVerify' => (bool)($settings['sslVerify'] ?? false),
                    'proxy' => trim((string)($settings['proxy'] ?? '')),
                ],
                'fields' => $old['fields'] ?? [],
                'mapping' => $old['mapping'] ?? [],
            ];

            writeJson('kintone', $out);

            jsonResponse(true, [
                'settings' => [
                    'subdomain' => $out['settings']['subdomain'],
                    'appId' => $out['settings']['appId'],
                    'loginName' => $out['settings']['loginName'],
                    'sslVerify' => $out['settings']['sslVerify'],
                    'proxy' => $out['settings']['proxy'],
                    'passwordSet' => $out['settings']['password'] !== '',
                ],
            ]);
        }

        if ($action === 'send_mail') {
            $surveyId = (string)($input['surveyId'] ?? '');
            $customerIds = $input['customerIds'] ?? [];
            $subject = (string)($input['subject'] ?? '');
            $body = (string)($input['body'] ?? '');
            $type = (string)($input['type'] ?? '一括送信');

            if ($surveyId === '') {
                throw new InvalidArgumentException('対象アンケートがありません。');
            }

            if (!$customerIds) {
                throw new InvalidArgumentException('顧客を選択してください。');
            }

            $surveys = readJson('surveys', []);
            $survey = surveyById($surveys, $surveyId);

            if (!$survey) {
                throw new RuntimeException('対象アンケートがありません。');
            }

            $customers = readJson('customers', []);
            $selected = array_values(array_filter(
                $customers,
                fn($c) => in_array($c['customerId'], $customerIds, true)
            ));

            $mail = readJson('mail', []);

            $results = [];
            $success = 0;
            $failed = 0;

            /*
             * SMTP設定がない場合は「失敗」とする。
             * ダミー送信は行わない。
             */
            foreach ($selected as $customer) {
                $url = rtrim(
                    ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        ? 'https'
                        : 'http') .
                    '://' .
                    $_SERVER['HTTP_HOST'] .
                    dirname($_SERVER['SCRIPT_NAME']),
                    '/'
                );

                $url .= '/index.php?survey=' .
                    rawurlencode($surveyId) .
                    '&token=' .
                    rawurlencode($customer['customerId']);

                $expandedSubject = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [$customer['name'], $url],
                    $subject
                );

                $expandedBody = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [$customer['name'], $url],
                    $body
                );

                if (
                    empty($mail['smtpServer']) ||
                    empty($mail['fromEmail']) ||
                    empty($customer['email'])
                ) {
                    $results[] = [
                        'customerId' => $customer['customerId'],
                        'name' => $customer['name'],
                        'email' => $customer['email'],
                        'ok' => false,
                        'message' => 'SMTP設定または顧客メールアドレスが未設定です。',
                        'subject' => $expandedSubject,
                        'body' => $expandedBody,
                        'url' => $url,
                    ];
                    $failed++;
                    continue;
                }

                /*
                 * 標準mail()を利用。
                 * SMTPサーバを指定した本格SMTPが必要な環境では
                 * php.ini / SMTP環境を設定してください。
                 */
                $headers = [];
                $headers[] = 'MIME-Version: 1.0';
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headers[] = 'From: ' .
                    ($mail['fromName']
                        ? mb_encode_mimeheader($mail['fromName']) . ' '
                        : '') .
                    '<' . $mail['fromEmail'] . '>';

                if (!empty($mail['replyTo'])) {
                    $headers[] = 'Reply-To: ' . $mail['replyTo'];
                }

                $sent = @mail(
                    $customer['email'],
                    mb_encode_mimeheader($expandedSubject),
                    $expandedBody,
                    implode("\r\n", $headers)
                );

                if ($sent) {
                    $success++;
                    $results[] = [
                        'customerId' => $customer['customerId'],
                        'name' => $customer['name'],
                        'email' => $customer['email'],
                        'ok' => true,
                        'message' => '送信成功',
                        'subject' => $expandedSubject,
                        'body' => $expandedBody,
                        'url' => $url,
                    ];
                } else {
                    $failed++;
                    $results[] = [
                        'customerId' => $customer['customerId'],
                        'name' => $customer['name'],
                        'email' => $customer['email'],
                        'ok' => false,
                        'message' => 'SMTP/mail送信に失敗しました。',
                        'subject' => $expandedSubject,
                        'body' => $expandedBody,
                        'url' => $url,
                    ];
                }
            }

            foreach ($customers as &$customer) {
                foreach ($results as $result) {
                    if ($customer['customerId'] === $result['customerId'] && $result['ok']) {
                        $customer['lastSentAt'] = nowIso();
                        $customer['sendCount'] = (int)$customer['sendCount'] + 1;
                        $customer['answerStatus'] = '送信済み / 未回答';
                    }
                }
            }
            unset($customer);

            writeJson('customers', $customers);

            $history = readJson('history', []);

            $history[] = [
                'historyId' => uid('history'),
                'surveyId' => $surveyId,
                'sentAt' => nowIso(),
                'type' => $type,
                'count' => count($selected),
                'success' => $success,
                'failed' => $failed,
                'subject' => $subject,
                'executedBy' => '管理画面',
                'customers' => $results,
            ];

            writeJson('history', $history);

            jsonResponse(true, [
                'total' => count($selected),
                'success' => $success,
                'failed' => $failed,
                'sentAt' => nowIso(),
                'results' => $results,
            ]);
        }

        if ($action === 'save_kintone_mapping') {
            $k = readJson('kintone', []);
            $k['mapping'] = $input['mapping'] ?? [];
            writeJson('kintone', $k);
            jsonResponse(true, $k['mapping']);
        }

        if ($action === 'kintone_test') {
            $k = readJson('kintone', []);
            $s = $k['settings'] ?? [];

            if (empty($s['subdomain']) || empty($s['appId']) || empty($s['loginName']) || empty($s['password'])) {
                jsonResponse(false, null, 'KINTONE_CONFIG', 'サブドメイン、アプリID、ログイン名、パスワードを設定してください。', 400);
            }

            $sub = preg_replace(
                '#^https?://#',
                '',
                trim($s['subdomain'])
            );
            $sub = preg_replace('#/.*$#', '', $sub);

            if (!str_ends_with($sub, '.cybozu.com')) {
                $sub .= '.cybozu.com';
            }

            $url = 'https://' . $sub . '/k/v1/app.json?id=' . rawurlencode($s['appId']);

            $headers = [
                'X-Cybozu-Authorization: ' .
                    base64_encode($s['loginName'] . ':' . $s['password']),
            ];

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => (bool)$s['sslVerify'],
                CURLOPT_SSL_VERIFYHOST => $s['sslVerify'] ? 2 : 0,
            ]);

            if (!empty($s['proxy'])) {
                curl_setopt($ch, CURLOPT_PROXY, $s['proxy']);
            }

            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno || $http < 200 || $http >= 300) {
                jsonResponse(false, null, 'KINTONE_CONNECTION', 'kintone接続失敗: ' . ($error ?: "HTTP {$http}"), 502);
            }

            jsonResponse(true, [
                'message' => 'kintone接続成功',
                'httpStatus' => $http,
            ]);
        }

        if ($action === 'kintone_fields') {
            $k = readJson('kintone', []);
            $s = $k['settings'] ?? [];

            if (empty($s['subdomain']) || empty($s['appId']) || empty($s['loginName']) || empty($s['password'])) {
                throw new RuntimeException('kintone接続設定を入力してください。');
            }

            $sub = preg_replace('#^https?://#', '', trim($s['subdomain']));
            $sub = preg_replace('#/.*$#', '', $sub);

            if (!str_ends_with($sub, '.cybozu.com')) {
                $sub .= '.cybozu.com';
            }

            $url = 'https://' . $sub .
                '/k/v1/app/form/fields.json?app=' .
                rawurlencode($s['appId']);

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'X-Cybozu-Authorization: ' .
                    base64_encode($s['loginName'] . ':' . $s['password']),
                ],
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => (bool)$s['sslVerify'],
                CURLOPT_SSL_VERIFYHOST => $s['sslVerify'] ? 2 : 0,
            ]);

            if (!empty($s['proxy'])) {
                curl_setopt($ch, CURLOPT_PROXY, $s['proxy']);
            }

            $response = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || $http < 200 || $http >= 300) {
                throw new RuntimeException('kintone項目取得失敗: ' . ($error ?: "HTTP {$http}"));
            }

            $decoded = json_decode($response, true);

            $fields = [];

            foreach (($decoded['properties'] ?? []) as $code => $field) {
                $fields[] = [
                    'code' => $code,
                    'label' => $field['label'] ?? $code,
                    'type' => $field['type'] ?? '',
                ];
            }

            $k['fields'] = $fields;
            writeJson('kintone', $k);

            jsonResponse(true, $fields);
        }

        if ($action === 'kintone_sync') {
            $k = readJson('kintone', []);
            $s = $k['settings'] ?? [];

            if (empty($s['subdomain']) || empty($s['appId']) || empty($s['loginName']) || empty($s['password'])) {
                throw new RuntimeException('kintone接続設定を入力してください。');
            }

            $sub = preg_replace('#^https?://#', '', trim($s['subdomain']));
            $sub = preg_replace('#/.*$#', '', $sub);

            if (!str_ends_with($sub, '.cybozu.com')) {
                $sub .= '.cybozu.com';
            }

            $url = 'https://' . $sub .
                '/k/v1/records.json?app=' .
                rawurlencode($s['appId']);

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'X-Cybozu-Authorization: ' .
                    base64_encode($s['loginName'] . ':' . $s['password']),
                ],
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => (bool)$s['sslVerify'],
                CURLOPT_SSL_VERIFYHOST => $s['sslVerify'] ? 2 : 0,
            ]);

            if (!empty($s['proxy'])) {
                curl_setopt($ch, CURLOPT_PROXY, $s['proxy']);
            }

            $response = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || $http < 200 || $http >= 300) {
                throw new RuntimeException('kintone顧客同期失敗: ' . ($error ?: "HTTP {$http}"));
            }

            $decoded = json_decode($response, true);
            $records = $decoded['records'] ?? [];

            $mapping = $k['mapping'] ?? [];

            $customers = readJson('customers', []);

            foreach ($records as $record) {
                $emailCode = $mapping['email'] ?? '';

                if (!$emailCode || empty($record[$emailCode]['value'])) {
                    continue;
                }

                $email = (string)$record[$emailCode]['value'];

                $existing = null;

                foreach ($customers as &$customer) {
                    if ($customer['email'] === $email) {
                        $existing = &$customer;
                        break;
                    }
                }

                if (!$existing) {
                    $customers[] = [
                        'customerId' => uid('customer'),
                        'organizationName' => '',
                        'name' => '',
                        'email' => $email,
                        'department' => '',
                        'phone' => '',
                        'address' => '',
                        'lastSentAt' => null,
                        'sendCount' => 0,
                        'answerStatus' => '未送信',
                        'kintoneStatus' => '登録済み',
                    ];

                    $existing = &$customers[array_key_last($customers)];
                }

                $map = [
                    'organizationName' => $mapping['organization'] ?? '',
                    'name' => $mapping['name'] ?? '',
                    'department' => $mapping['department'] ?? '',
                    'phone' => $mapping['phone'] ?? '',
                ];

                foreach ($map as $target => $code) {
                    if ($code && isset($record[$code]['value'])) {
                        $existing[$target] = (string)$record[$code]['value'];
                    }
                }

                $existing['kintoneStatus'] = '登録済み';

                unset($existing);
            }

            unset($customer);

            writeJson('customers', $customers);

            jsonResponse(true, [
                'message' => '顧客同期完了',
                'count' => count($records),
            ]);
        }

        if ($action === 'test_mail') {
            $mail = readJson('mail', []);
            $to = trim((string)($input['to'] ?? ''));

            if (!$to) {
                throw new InvalidArgumentException('テスト送信先を入力してください。');
            }

            if (empty($mail['smtpServer']) || empty($mail['fromEmail'])) {
                throw new RuntimeException('SMTP設定が未設定です。');
            }

            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . $mail['fromEmail'],
            ];

            $ok = @mail(
                $to,
                mb_encode_mimeheader('アンケート管理システム テストメール'),
                'SMTPテストメールです。',
                implode("\r\n", $headers)
            );

            if (!$ok) {
                throw new RuntimeException('テストメール送信に失敗しました。');
            }

            jsonResponse(true, ['message' => 'テストメール送信成功']);
        }

        jsonResponse(false, null, 'UNKNOWN_ACTION', '未対応のAPIです。', 400);

    } catch (InvalidArgumentException $e) {
        jsonResponse(false, null, 'VALIDATION_ERROR', $e->getMessage(), 400);
    } catch (Throwable $e) {
        error_log((string)$e);
        jsonResponse(false, null, 'SERVER_ERROR', $e->getMessage(), 500);
    }
}

/* =========================================================
 * Respondent detection
 * ========================================================= */

$isRespondent = isset($_GET['survey']);

$initialSurveyId = (string)($_GET['survey'] ?? '');
$initialToken = (string)($_GET['token'] ?? '');

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<style>
*{box-sizing:border-box}
:root{
 --primary:#2563eb;
 --primary-dark:#1d4ed8;
 --danger:#dc2626;
 --warning:#d97706;
 --success:#16a34a;
 --muted:#64748b;
 --bg:#f1f5f9;
 --card:#fff;
 --border:#e2e8f0;
 --text:#0f172a;
}
body{
 margin:0;
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;
 color:var(--text);
 background:var(--bg);
}
button,input,textarea,select{font:inherit}
button{
 border:0;
 border-radius:8px;
 padding:10px 15px;
 cursor:pointer;
 background:#e2e8f0;
 color:#0f172a;
 min-height:42px;
}
button:hover{filter:brightness(.97)}
button.primary{background:var(--primary);color:white}
button.danger{background:var(--danger);color:white}
button.success{background:var(--success);color:white}
button.warning{background:var(--warning);color:white}
button.small{padding:7px 10px;min-height:34px;font-size:13px}
button:disabled{opacity:.45;cursor:not-allowed}
input,textarea,select{
 width:100%;
 border:1px solid #cbd5e1;
 border-radius:7px;
 padding:10px 12px;
 background:white;
}
textarea{min-height:110px;resize:vertical}
label{font-weight:600;font-size:14px}
.field{display:grid;gap:6px;margin-bottom:16px}
.container{max-width:1450px;margin:auto;padding:20px}
.admin-header{
 background:#0f172a;color:white;
 display:flex;align-items:center;
 gap:10px;padding:12px 20px;
 position:sticky;top:0;z-index:20;
}
.admin-header h1{font-size:18px;margin-right:auto}
.admin-header button{background:#1e293b;color:white}
.page{display:none}
.page.active{display:block}
.card{
 background:white;border:1px solid var(--border);
 border-radius:12px;padding:20px;margin-bottom:18px;
 box-shadow:0 2px 8px rgba(15,23,42,.04)
}
.toolbar{
 display:flex;gap:10px;flex-wrap:wrap;
 align-items:center;margin-bottom:15px
}
.toolbar .grow{flex:1}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:900px}
th,td{padding:12px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}
th{background:#f8fafc;white-space:nowrap}
.status{
 display:inline-block;padding:4px 9px;border-radius:99px;
 font-size:12px;font-weight:700
}
.status-draft{background:#e2e8f0}
.status-published{background:#dcfce7;color:#166534}
.status-stopped{background:#fef3c7;color:#92400e}
.status-finished{background:#fee2e2;color:#991b1b}
.actions{display:flex;gap:6px;flex-wrap:wrap}
h2{margin-top:0}
h3{margin-bottom:10px}
.muted{color:var(--muted)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.group{
 border:2px solid #dbeafe;
 border-radius:10px;
 margin-bottom:16px;
 background:#f8fbff;
}
.group-head{
 padding:12px;
 background:#eff6ff;
 display:flex;gap:8px;align-items:center
}
.group-head input{font-weight:700}
.group-body{padding:12px}
.question{
 background:white;border:1px solid var(--border);
 border-radius:9px;padding:15px;margin-bottom:10px
}
.question.dragging,.group.dragging{opacity:.45}
.question-head{
 display:flex;gap:8px;align-items:center;margin-bottom:10px
}
.question-number{font-weight:800;color:var(--primary);white-space:nowrap}
.choice-row{display:flex;gap:7px;margin:7px 0}
.choice-row button{min-width:40px}
.dropzone{
 height:10px;border-radius:10px;
 margin:5px 0;
}
.dropzone.over{height:45px;background:#dbeafe}
.add-row{
 padding:10px;border:1px dashed #94a3b8;
 border-radius:8px;text-align:center;
 cursor:pointer;color:var(--primary)
}
.modal-backdrop{
 position:fixed;inset:0;background:rgba(15,23,42,.55);
 display:none;align-items:center;justify-content:center;
 z-index:100
}
.modal-backdrop.show{display:flex}
.modal{
 background:white;border-radius:12px;
 width:min(600px,calc(100% - 30px));
 padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.25)
}
.modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
.toast{
 position:fixed;right:20px;bottom:20px;
 background:#0f172a;color:white;padding:14px 18px;
 border-radius:9px;z-index:200;display:none;
 max-width:450px
}
.toast.show{display:block}
.toast.error{background:#991b1b}
.preview{
 max-width:760px;margin:auto;background:white;
 border-radius:12px;padding:25px
}
.answer-wrap{
 min-height:100vh;background:#f8fafc;padding:20px
}
.answer-card{
 max-width:760px;margin:auto;background:white;
 border-radius:14px;padding:25px;
 box-shadow:0 5px 25px rgba(15,23,42,.08)
}
.answer-choice{
 display:block;padding:15px;border:2px solid #e2e8f0;
 border-radius:10px;margin:9px 0;cursor:pointer
}
.answer-choice:hover{border-color:#93c5fd;background:#eff6ff}
.answer-choice input{width:auto;margin-right:10px}
.summary{
 display:grid;grid-template-columns:repeat(5,1fr);gap:12px
}
.summary-item{
 background:#f8fafc;border:1px solid var(--border);
 padding:15px;border-radius:10px
}
.summary-item strong{font-size:24px;display:block}
.bar{height:22px;background:#e2e8f0;border-radius:99px;overflow:hidden}
.bar span{display:block;height:100%;background:#2563eb}
pre.debug{
 white-space:pre-wrap;background:#020617;color:#e2e8f0;
 padding:12px;border-radius:8px;max-height:300px;overflow:auto
}
@media(max-width:900px){
 .grid2,.grid3,.summary{grid-template-columns:1fr}
 .admin-header{flex-wrap:wrap}
 .admin-header h1{width:100%}
 .container{padding:12px}
}
@media(max-width:600px){
 .admin-header button{flex:1}
 .card{padding:14px}
 .answer-wrap{padding:8px}
 .answer-card{padding:18px}
}
</style>
</head>

<body>

<?php if (!$isRespondent): ?>

<header class="admin-header">
    <h1>アンケート管理システム</h1>
    <button data-view="list">アンケート一覧</button>
    <button data-view="kintone">kintone連携設定</button>
    <button data-view="mail">メールサーバ設定</button>
    <button id="adminReset">ログアウト</button>
</header>

<main class="container">

<section id="page-list" class="page active">
<div class="card">
    <div class="toolbar">
        <input id="surveySearch" class="grow" placeholder="タイトルで検索（Enter対応）">
        <select id="surveyFilter">
            <option value="all">すべて</option>
            <option value="published">公開中</option>
            <option value="draft">下書き</option>
            <option value="stopped">停止</option>
            <option value="finished">終了</option>
        </select>
        <select id="surveySort">
            <option value="updated-desc">更新日 新しい順</option>
            <option value="updated-asc">更新日 古い順</option>
            <option value="answers-desc">回答数 多い順</option>
            <option value="answers-asc">回答数 少ない順</option>
            <option value="start-desc">開始日 新しい順</option>
            <option value="start-asc">開始日 古い順</option>
        </select>
        <button class="primary" id="newSurvey">＋ アンケート作成</button>
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
            <tbody id="surveyTable"></tbody>
        </table>
    </div>
</div>
</section>

<section id="page-edit" class="page">
<div class="card">
    <div class="toolbar">
        <button id="cancelEdit">キャンセル</button>
        <button class="primary" id="saveSurvey">保存して一覧へ</button>
        <span class="muted">状態：</span>
        <select id="editStatus" style="width:180px"></select>
    </div>
</div>

<div class="card">
    <h2 id="editHeading">アンケート作成</h2>

    <div class="grid2">
        <div class="field">
            <label>タイトル</label>
            <input id="editTitle">
        </div>

        <div class="field">
            <label>質問番号採番方式</label>
            <select id="editNumbering">
                <option value="all">アンケート全体で通番</option>
                <option value="group">グループ毎に採番</option>
            </select>
        </div>

        <div class="field">
            <label>開始日時</label>
            <input id="editStart" type="datetime-local">
        </div>

        <div class="field">
            <label>終了日時</label>
            <input id="editEnd" type="datetime-local">
        </div>
    </div>

    <div class="field">
        <label>説明</label>
        <textarea id="editDescription"></textarea>
    </div>

    <label>
        <input id="editReanswer" type="checkbox" style="width:auto">
        回答済みURLからの再回答を許可
    </label>
</div>

<div class="card">
    <h2>グループ・質問</h2>
    <div id="groups"></div>
    <div class="add-row" id="addGroup">＋ グループを追加</div>
</div>
</section>

<section id="page-preview" class="page">
<div class="card">
    <div class="toolbar">
        <button data-view="edit">編集へ戻る</button>
        <button id="previewPc" class="primary">PC表示</button>
        <button id="previewMobile">スマートフォン表示</button>
    </div>
</div>
<div id="previewContainer"></div>
</section>

<section id="page-send" class="page">
<div class="card">
    <h2>顧客選択・メール送信</h2>
    <div id="sendSurveyTitle" class="muted"></div>
</div>

<div class="card">
    <div class="toolbar">
        <input id="customerSearch" class="grow" placeholder="顧客名・組織名・メール・ステータス">
        <button id="selectReminder">未回答者を選択</button>
        <button id="selectAllCustomers">すべて選択</button>
        <button id="clearCustomers">すべて解除</button>
    </div>
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
                <th>回答状態</th>
                <th>kintone</th>
            </tr>
            </thead>
            <tbody id="customerTable"></tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>メール作成</h3>
    <div class="field">
        <label>件名</label>
        <input id="mailSubject" value="アンケートのお願い">
    </div>
    <div class="field">
        <label>本文</label>
        <textarea id="mailBody"> {顧客名} 様

アンケートへのご協力をお願いいたします。

以下のURLからご回答ください。
{アンケートURL}

よろしくお願いいたします。</textarea>
    </div>

    <div class="toolbar">
        <button class="primary" id="sendBulk">一括送信</button>
        <button id="resend">再送</button>
        <button id="remind">リマインド</button>
    </div>
</div>

<div class="card">
    <h3>送信結果</h3>
    <div id="sendResult">まだ送信していません。</div>
</div>

<div class="card">
    <h3>送信履歴</h3>
    <div id="historyArea"></div>
</div>
</section>

<section id="page-aggregation" class="page">
<div class="card">
    <div class="toolbar">
        <button data-view="list">一覧へ戻る</button>
        <button id="csvExport" class="primary">CSV出力</button>
        <button id="pdfExport">PDF出力</button>
    </div>
    <h2 id="aggregationTitle"></h2>
</div>

<div class="card">
    <div class="summary" id="summary"></div>
</div>

<div class="card">
    <h2>設問別集計</h2>
    <div class="toolbar">
        <button id="allQuestions">すべて選択</button>
        <button id="noQuestions">すべて解除</button>
    </div>
    <div id="questionStats"></div>
</div>
</section>

<section id="page-kintone" class="page">
<div class="card">
    <div class="toolbar">
        <button data-view="list">一覧へ戻る</button>
        <button class="primary" id="saveKintone">設定保存</button>
        <button id="kintoneTest">接続テスト</button>
        <button id="kintoneFields">項目一覧を再取得</button>
        <button id="kintoneSync">顧客情報を同期</button>
    </div>

    <h2>kintone連携設定</h2>

    <div class="grid2">
        <div class="field">
            <label>サブドメイン</label>
            <input id="kSubdomain" placeholder="https://xxxx.cybozu.com">
        </div>

        <div class="field">
            <label>顧客管理アプリID</label>
            <input id="kAppId">
        </div>

        <div class="field">
            <label>ログイン名</label>
            <input id="kLoginName">
        </div>

        <div class="field">
            <label>パスワード</label>
            <input id="kPassword" type="password" autocomplete="new-password">
        </div>

        <div class="field">
            <label>SSL証明書検証</label>
            <select id="kSsl">
                <option value="false">検証しない</option>
                <option value="true">検証する</option>
            </select>
        </div>

        <div class="field">
            <label>プロキシ</label>
            <input id="kProxy" placeholder="proxy.example.local:8080">
        </div>
    </div>

    <div id="kintoneResult"></div>
</div>

<div class="card">
    <h3>kintoneフィールド</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>フィールドコード</th><th>日本語ラベル</th><th>タイプ</th></tr></thead>
            <tbody id="kintoneFieldsTable"></tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>フィールドマッピング</h3>
    <div id="mapping"></div>
</div>
</section>

<section id="page-mail" class="page">
<div class="card">
    <div class="toolbar">
        <button data-view="list">一覧へ戻る</button>
        <button class="primary" id="saveMail">設定保存</button>
        <button id="testMail">テストメール</button>
    </div>

    <h2>メールサーバ設定</h2>

    <div class="grid2">
        <div class="field">
            <label>SMTPサーバ</label>
            <input id="smtpServer">
        </div>
        <div class="field">
            <label>SMTPポート</label>
            <input id="smtpPort" type="number" value="587">
        </div>
        <div class="field">
            <label>暗号化方式</label>
            <select id="smtpEncryption">
                <option value="none">なし</option>
                <option value="tls">STARTTLS</option>
                <option value="ssl">SSL/TLS</option>
            </select>
        </div>
        <div class="field">
            <label>SMTP認証</label>
            <select id="smtpAuth">
                <option value="true">使用する</option>
                <option value="false">使用しない</option>
            </select>
        </div>
        <div class="field">
            <label>SMTPユーザー名</label>
            <input id="smtpUsername">
        </div>
        <div class="field">
            <label>SMTPパスワード</label>
            <input id="smtpPassword" type="password">
        </div>
        <div class="field">
            <label>送信元メールアドレス</label>
            <input id="smtpFrom">
        </div>
        <div class="field">
            <label>送信元名</label>
            <input id="smtpFromName">
        </div>
        <div class="field">
            <label>返信先メールアドレス</label>
            <input id="smtpReply">
        </div>
    </div>

    <div id="mailResult"></div>
</div>
</section>

</main>

<?php else: ?>

<div class="answer-wrap">
    <main class="answer-card" id="answerApp">
        <div id="answerContent">読み込み中...</div>
    </main>
</div>

<?php endif; ?>

<div class="modal-backdrop" id="modal">
    <div class="modal">
        <h3 id="modalTitle">確認</h3>
        <div id="modalMessage"></div>
        <div class="modal-actions">
            <button id="modalCancel">キャンセル</button>
            <button class="primary" id="modalOk">実行</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

/* =========================================================
 * JavaScript
 * ========================================================= */

const IS_RESPONDENT = <?= $isRespondent ? 'true' : 'false' ?>;
const INITIAL_SURVEY_ID = <?= json_encode($initialSurveyId, JSON_UNESCAPED_UNICODE) ?>;
const INITIAL_TOKEN = <?= json_encode($initialToken, JSON_UNESCAPED_UNICODE) ?>;

const state = {
    surveys: [],
    customers: [],
    responses: [],
    history: [],
    kintone: null,
    mail: null,

    currentView: IS_RESPONDENT ? 'answer' : 'list',

    editingSurveyId: null,
    aggregationSurveyId: null,
    sendingSurveyId: null,

    editingSurvey: null,

    selectedCustomerIds: new Set(),
    aggregationQuestions: new Set(),

    answerToken: INITIAL_TOKEN || '',
    answerSurvey: null,
    answerValues: {},
    answerStep: 'answer',
};

const $ = id => document.getElementById(id);

async function api(action, data = {}) {
    let response;

    try {
        response = await fetch('index.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action, ...data})
        });
    } catch (e) {
        throw new Error('通信失敗: ' + e.message);
    }

    const text = await response.text();

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${text.slice(0,1000)}`);
    }

    let result;

    try {
        result = JSON.parse(text);
    } catch (e) {
        throw new Error('JSON解析失敗: ' + text.slice(0,1000));
    }

    if (!result.ok) {
        throw new Error(
            (result.error?.code ? '[' + result.error.code + '] ' : '') +
            (result.error?.message || 'PHP APIエラー')
        );
    }

    return result.data;
}

function toast(message, error=false) {
    const el = $('toast');
    el.textContent = message;
    el.className = 'toast show' + (error ? ' error' : '');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => el.className='toast', 5000);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

function confirmModal(title, message) {
    return new Promise(resolve => {
        $('modalTitle').textContent = title;
        $('modalMessage').innerHTML = message;
        $('modal').classList.add('show');

        const ok = $('modalOk');
        const cancel = $('modalCancel');

        const cleanup = result => {
            $('modal').classList.remove('show');
            ok.onclick = null;
            cancel.onclick = null;
            resolve(result);
        };

        ok.onclick = () => cleanup(true);
        cancel.onclick = () => cleanup(false);
    });
}

function showView(name) {
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));

    const page = $('page-' + name);

    if (!page) return;

    page.classList.add('active');
    state.currentView = name;

    if (name === 'list') renderSurveyList();
    if (name === 'kintone') renderKintone();
    if (name === 'mail') renderMail();
    if (name === 'send') renderSend();
    if (name === 'aggregation') renderAggregation();
}

/* ---------------------------------------------------------
 * Load
 * --------------------------------------------------------- */

async function loadData() {
    try {
        const data = await api('load_data');

        state.surveys = data.surveys || [];
        state.customers = data.customers || [];
        state.responses = data.responses || [];
        state.history = data.history || [];
        state.kintone = data.kintone || {};
        state.mail = data.mail || {};

        if (IS_RESPONDENT) {
            await initRespondent();
        } else {
            renderSurveyList();
        }
    } catch (e) {
        document.body.innerHTML = `
            <div style="padding:30px;font-family:sans-serif">
                <h2>システムを起動できませんでした。</h2>
                <p>${escapeHtml(e.message)}</p>
                <pre class="debug">${escapeHtml(e.stack || '')}</pre>
            </div>`;
    }
}

/* ---------------------------------------------------------
 * Survey list
 * --------------------------------------------------------- */

function statusLabel(status) {
    return {
        draft:'下書き',
        published:'公開中',
        stopped:'停止',
        finished:'終了'
    }[status] || status;
}

function statusHtml(status) {
    return `<span class="status status-${status}">
        ${escapeHtml(statusLabel(status))}
    </span>`;
}

function answerCount(surveyId) {
    return state.responses.filter(r => r.surveyId === surveyId).length;
}

function renderSurveyList() {
    if (!$('surveyTable')) return;

    const search = $('surveySearch').value.trim().toLowerCase();
    const filter = $('surveyFilter').value;
    const sort = $('surveySort').value;

    let surveys = state.surveys.filter(s => {
        if (search && !String(s.title).toLowerCase().includes(search)) return false;
        if (filter !== 'all' && s.status !== filter) return false;
        return true;
    });

    surveys.sort((a,b) => {
        const ac = answerCount(a.surveyId);
        const bc = answerCount(b.surveyId);

        if (sort === 'answers-desc') return bc-ac;
        if (sort === 'answers-asc') return ac-bc;

        if (sort === 'start-desc')
            return String(b.startDate).localeCompare(String(a.startDate));

        if (sort === 'start-asc')
            return String(a.startDate).localeCompare(String(b.startDate));

        if (sort === 'updated-asc')
            return String(a.updatedAt).localeCompare(String(b.updatedAt));

        return String(b.updatedAt).localeCompare(String(a.updatedAt));
    });

    $('surveyTable').innerHTML = surveys.map(s => `
        <tr>
            <td>${escapeHtml(s.createdAt?.slice(0,10))}</td>
            <td>${escapeHtml(s.updatedAt?.slice(0,16).replace('T',' '))}</td>
            <td><strong>${escapeHtml(s.title)}</strong></td>
            <td>
                ${escapeHtml(s.startDate || '')}
                ～<br>
                ${escapeHtml(s.endDate || '期限なし')}
            </td>
            <td>${statusHtml(s.status)}</td>
            <td>${answerCount(s.surveyId)}</td>
            <td>
                <div class="actions">
                    <button class="small" data-edit="${s.surveyId}">確認・編集</button>
                    <button class="small" data-aggregate="${s.surveyId}">集計</button>
                    <button class="small" data-send="${s.surveyId}">送信</button>
                    <button class="small" data-duplicate="${s.surveyId}">複製</button>
                    <button class="small danger" data-delete="${s.surveyId}">削除</button>
                </div>
            </td>
        </tr>
    `).join('');
}

function newSurvey() {
    state.editingSurveyId = null;

    const now = new Date();
    const start = now.toISOString().slice(0,16);

    state.editingSurvey = {
        surveyId:'',
        title:'',
        description:'',
        startDate:start,
        endDate:'',
        questionNumberMode:'all',
        status:'draft',
        allowReanswer:false,
        createdAt:'',
        updatedAt:'',
        groups:[]
    };

    addGroup(false);
    state.editingSurvey.groups[0].questions = [];

    renderEditor();
    showView('edit');
}

/* ---------------------------------------------------------
 * Editor
 * --------------------------------------------------------- */

function editSurvey(id) {
    const source = state.surveys.find(s => s.surveyId === id);

    if (!source) return;

    state.editingSurveyId = id;
    state.editingSurvey = JSON.parse(JSON.stringify(source));

    renderEditor();
    showView('edit');
}

function renderEditor() {
    const s = state.editingSurvey;

    $('editHeading').textContent =
        state.editingSurveyId ? 'アンケート編集' : 'アンケート作成';

    $('editTitle').value = s.title || '';
    $('editDescription').value = s.description || '';
    $('editStart').value = s.startDate || '';
    $('editEnd').value = s.endDate || '';
    $('editNumbering').value = s.questionNumberMode || 'all';
    $('editReanswer').checked = !!s.allowReanswer;

    renderStatusSelect();
    renderGroups();
}

function renderStatusSelect() {
    const status = state.editingSurvey.status;

    let options = [`<option value="${status}">${statusLabel(status)}</option>`];

    if (status === 'draft') {
        options.push('<option value="published">公開中</option>');
    } else if (status === 'published') {
        options.push('<option value="stopped">停止</option>');
    } else if (status === 'stopped') {
        options.push('<option value="published">公開中</option>');
    }

    $('editStatus').innerHTML = options.join('');
}

function renderGroups() {
    const s = state.editingSurvey;

    $('groups').innerHTML = s.groups.map((g, gi) => `
        <div class="group"
             draggable="true"
             data-group="${g.groupId}"
             data-index="${gi}">
            <div class="group-head">
                <span>☷</span>
                <input value="${escapeHtml(g.title)}"
                       data-group-title="${g.groupId}">
                <button class="small danger" data-remove-group="${g.groupId}">
                    グループ削除
                </button>
            </div>

            <div class="group-body">
                ${g.questions.map((q, qi) => questionHtml(q, g, qi)).join('')}

                <div class="add-row" data-add-question="${g.groupId}">
                    ＋ 質問を追加
                </div>
            </div>
        </div>
    `).join('');

    recalcLocal();
}

function questionHtml(q, g, qi) {
    let choices = '';

    if (q.type !== 'text') {
        choices = `
            <div>
                <strong>選択肢</strong>
                ${q.choices.map(c => `
                    <div class="choice-row">
                        <input value="${escapeHtml(c.label)}"
                               data-choice-label="${q.questionId}"
                               data-choice-id="${c.choiceId}">
                        <button class="small danger"
                                data-remove-choice="${q.questionId}"
                                data-choice="${c.choiceId}">
                            削除
                        </button>
                    </div>
                `).join('')}
                <button class="small" data-add-choice="${q.questionId}">
                    ＋ 選択肢
                </button>
            </div>
        `;
    }

    let branch = '';

    if (q.type === 'single') {
        branch = `
            <details>
                <summary>条件分岐</summary>
                <div class="field">
                    <label>選択肢ごとの次質問</label>
                    ${q.choices.map(c => {
                        const rule = q.branchRules.find(r => r.choiceId === c.choiceId);
                        return `
                        <div class="choice-row">
                            <span style="min-width:100px">${escapeHtml(c.label)}</span>
                            <select data-branch="${q.questionId}"
                                    data-choice="${c.choiceId}">
                                <option value="">次の質問なし</option>
                                ${allQuestions().filter(x => x.questionId !== q.questionId).map(x =>
                                    `<option value="${x.questionId}"
                                        ${rule?.nextQuestionId === x.questionId ? 'selected':''}>
                                        ${escapeHtml(x.questionNumber)} ${escapeHtml(x.questionText)}
                                    </option>`
                                ).join('')}
                            </select>
                        </div>`;
                    }).join('')}
                </div>
            </details>
        `;
    }

    return `
    <div class="question"
         draggable="true"
         data-question="${q.questionId}"
         data-group="${g.groupId}">
        <div class="question-head">
            <span>☷</span>
            <span class="question-number">${escapeHtml(q.questionNumber || '')}</span>
            <strong>質問</strong>
            <button class="small danger" data-remove-question="${q.questionId}">
                削除
            </button>
        </div>

        <div class="field">
            <label>質問文</label>
            <textarea data-question-text="${q.questionId}">${escapeHtml(q.questionText)}</textarea>
        </div>

        <div class="grid2">
            <div class="field">
                <label>回答形式</label>
                <select data-question-type="${q.questionId}">
                    <option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
                    <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
                    <option value="text" ${q.type==='text'?'selected':''}>自由記述</option>
                </select>
            </div>

            <div class="field">
                <label>必須</label>
                <select data-question-required="${q.questionId}">
                    <option value="true" ${q.required?'selected':''}>必須</option>
                    <option value="false" ${!q.required?'selected':''}>任意</option>
                </select>
            </div>
        </div>

        ${choices}
        ${branch}
    </div>`;
}

function allQuestions() {
    return state.editingSurvey.groups.flatMap(g => g.questions);
}

function recalcLocal() {
    const s = state.editingSurvey;

    s.groups.forEach((g, gi) => {
        g.sortOrder = gi;

        g.questions.forEach((q, qi) => {
            q.groupId = g.groupId;
            q.sortOrder = qi;

            if (s.questionNumberMode === 'group') {
                q.questionNumber = `Q${gi+1}-${qi+1}`;
            } else {
                let n = 1;

                for (let i=0;i<gi;i++) {
                    n += s.groups[i].questions.length;
                }

                q.questionNumber = `Q${n+qi}`;
            }
        });
    });
}

/* ---------------------------------------------------------
 * Editor events
 * --------------------------------------------------------- */

function addGroup(render=true) {
    const g = {
        groupId:'group_' + crypto.randomUUID(),
        title:'新しいグループ',
        sortOrder:state.editingSurvey.groups.length,
        questions:[]
    };

    state.editingSurvey.groups.push(g);

    if (render) renderGroups();
}

function addQuestion(groupId) {
    const group = state.editingSurvey.groups.find(g => g.groupId === groupId);
    if (!group) return;

    const q = {
        questionId:'question_' + crypto.randomUUID(),
        groupId,
        sortOrder:group.questions.length,
        questionNumber:'',
        questionText:'新しい質問',
        type:'single',
        required:false,
        choices:[
            {choiceId:'choice_'+crypto.randomUUID(),label:'選択肢1',sortOrder:0},
            {choiceId:'choice_'+crypto.randomUUID(),label:'選択肢2',sortOrder:1}
        ],
        branchRules:[]
    };

    group.questions.push(q);

    renderGroups();
}

async function removeGroup(id) {
    const g = state.editingSurvey.groups.find(x => x.groupId === id);
    if (!g) return;

    const warning = g.questions.length
        ? `<p><strong>このグループには質問があります。</strong></p>
           <p>グループと質問をすべて削除します。</p>`
        : '<p>このグループを削除しますか？';

    if (!await confirmModal('グループ削除', warning)) return;

    state.editingSurvey.groups =
        state.editingSurvey.groups.filter(x => x.groupId !== id);

    renderGroups();
}

async function removeQuestion(id) {
    if (!await confirmModal('質問削除','この質問を削除しますか？')) return;

    for (const g of state.editingSurvey.groups) {
        g.questions = g.questions.filter(q => q.questionId !== id);
    }

    for (const g of state.editingSurvey.groups) {
        for (const q of g.questions) {
            q.branchRules = q.branchRules.filter(
                r => r.nextQuestionId !== id
            );
        }
    }

    renderGroups();
}

function addChoice(qid) {
    const q = allQuestions().find(x => x.questionId === qid);
    if (!q) return;

    q.choices.push({
        choiceId:'choice_'+crypto.randomUUID(),
        label:'新しい選択肢',
        sortOrder:q.choices.length
    });

    renderGroups();
}

function removeChoice(qid,cid) {
    const q = allQuestions().find(x => x.questionId === qid);
    if (!q) return;

    q.choices = q.choices.filter(c => c.choiceId !== cid);
    q.branchRules = q.branchRules.filter(r => r.choiceId !== cid);

    renderGroups();
}

function collectEditorInputs() {
    const s = state.editingSurvey;

    s.title = $('editTitle').value;
    s.description = $('editDescription').value;
    s.startDate = $('editStart').value;
    s.endDate = $('editEnd').value;
    s.questionNumberMode = $('editNumbering').value;
    s.allowReanswer = $('editReanswer').checked;

    s.groups.forEach(g => {
        const title = document.querySelector(
            `[data-group-title="${g.groupId}"]`
        );

        if (title) g.title = title.value;

        g.questions.forEach(q => {
            const text = document.querySelector(
                `[data-question-text="${q.questionId}"]`
            );

            const type = document.querySelector(
                `[data-question-type="${q.questionId}"]`
            );

            const required = document.querySelector(
                `[data-question-required="${q.questionId}"]`
            );

            if (text) q.questionText = text.value;
            if (type) q.type = type.value;
            if (required) q.required = required.value === 'true';

            q.choices.forEach(c => {
                const el = document.querySelector(
                    `[data-choice-label="${q.questionId}"][data-choice-id="${c.choiceId}"]`
                );
                if (el) c.label = el.value;
            });

            if (q.type === 'single') {
                q.branchRules = [];

                q.choices.forEach(c => {
                    const el = document.querySelector(
                        `[data-branch="${q.questionId}"][data-choice="${c.choiceId}"]`
                    );

                    if (el && el.value) {
                        q.branchRules.push({
                            questionId:q.questionId,
                            choiceId:c.choiceId,
                            nextQuestionId:el.value
                        });
                    }
                });
            } else {
                q.branchRules = [];
            }
        });
    });

    recalcLocal();
}

async function saveEditor() {
    collectEditorInputs();

    if (!state.editingSurvey.title.trim()) {
        toast('タイトルを入力してください',true);
        return;
    }

    try {
        const saved = await api('save_survey', {
            survey:state.editingSurvey
        });

        const index = state.surveys.findIndex(
            s => s.surveyId === saved.surveyId
        );

        if (index >= 0) {
            state.surveys[index] = saved;
        } else {
            state.surveys.push(saved);
        }

        toast('保存しました');
        showView('list');
    } catch(e) {
        toast(e.message,true);
    }
}

async function changeEditStatus() {
    const current = state.editingSurvey.status;
    const next = $('editStatus').value;

    if (current === next) return;

    const messages = {
        published:'このアンケートを公開しますか？',
        stopped:'このアンケートを停止しますか？'
    };

    if (!await confirmModal(
        statusLabel(next),
        messages[next] || '状態を変更しますか？'
    )) {
        renderStatusSelect();
        return;
    }

    try {
        await api('change_status',{
            surveyId:state.editingSurvey.surveyId,
            status:next
        });

        state.editingSurvey.status = next;

        const survey = state.surveys.find(
            s => s.surveyId === state.editingSurvey.surveyId
        );

        if (survey) survey.status = next;

        renderStatusSelect();
        toast('状態を変更しました');
    } catch(e) {
        toast(e.message,true);
        renderStatusSelect();
    }
}

/* ---------------------------------------------------------
 * Preview
 * --------------------------------------------------------- */

function renderPreview() {
    collectEditorInputs();

    const s = state.editingSurvey;

    $('previewContainer').innerHTML = `
    <div class="preview" id="preview">
        <h1>${escapeHtml(s.title)}</h1>
        <p>${escapeHtml(s.description)}</p>

        ${s.groups.map(g => `
            <section>
                <h2>${escapeHtml(g.title)}</h2>
                ${g.questions.map(q => `
                    <div class="card">
                        <h3>
                            ${escapeHtml(q.questionNumber)}
                            ${escapeHtml(q.questionText)}
                            ${q.required ? '<span style="color:red">*</span>':''}
                        </h3>

                        ${
                            q.type === 'text'
                            ? '<textarea placeholder="回答を入力"></textarea>'
                            : q.choices.map(c => `
                                <label class="answer-choice">
                                    <input type="${q.type==='multiple'?'checkbox':'radio'}">
                                    ${escapeHtml(c.label)}
                                </label>
                            `).join('')
                        }
                    </div>
                `).join('')}
            </section>
        `).join('')}
    </div>`;
}

/* ---------------------------------------------------------
 * Send
 * --------------------------------------------------------- */

function renderSend() {
    const s = state.surveys.find(
        x => x.surveyId === state.sendingSurveyId
    );

    if (!s) {
        showView('list');
        return;
    }

    $('sendSurveyTitle').textContent =
        `対象アンケート: ${s.title}`;

    renderCustomers();
    renderHistory();
}

function renderCustomers() {
    const keyword = ($('customerSearch')?.value || '').toLowerCase();

    const list = state.customers.filter(c => {
        if (!keyword) return true;

        return [
            c.name,
            c.organizationName,
            c.email,
            c.answerStatus
        ].join(' ').toLowerCase().includes(keyword);
    });

    $('customerTable').innerHTML = list.map(c => `
        <tr>
            <td>
                <input type="checkbox"
                       data-customer="${c.customerId}"
                       ${state.selectedCustomerIds.has(c.customerId)?'checked':''}>
            </td>
            <td>${escapeHtml(c.organizationName)}</td>
            <td>${escapeHtml(c.name)}</td>
            <td>${escapeHtml(c.email)}</td>
            <td>${escapeHtml(c.phone)}</td>
            <td>${escapeHtml(c.address)}</td>
            <td>${escapeHtml(c.lastSentAt || '')}</td>
            <td>${c.sendCount || 0}</td>
            <td>${escapeHtml(c.answerStatus)}</td>
            <td>${escapeHtml(c.kintoneStatus)}</td>
        </tr>
    `).join('');
}

function renderHistory() {
    const history = state.history.filter(
        h => h.surveyId === state.sendingSurveyId
    ).reverse();

    $('historyArea').innerHTML = history.length
        ? history.map(h => `
            <details class="card">
                <summary>
                    ${escapeHtml(h.sentAt)}
                    / ${escapeHtml(h.type)}
                    / ${h.count}件
                </summary>
                <p>成功: ${h.success} / 失敗: ${h.failed}</p>
                <p>件名: ${escapeHtml(h.subject)}</p>
                ${(h.customers || []).map(c => `
                    <details>
                        <summary>${escapeHtml(c.name)} / ${escapeHtml(c.email)}</summary>
                        <p>${escapeHtml(c.subject)}</p>
                        <pre style="white-space:pre-wrap">${escapeHtml(c.body)}</pre>
                        <p>${escapeHtml(c.url)}</p>
                    </details>
                `).join('')}
            </details>
        `).join('')
        : '<p class="muted">送信履歴はありません。</p>';
}

async function sendMail(type) {
    if (!state.selectedCustomerIds.size) {
        toast('顧客を選択してください',true);
        return;
    }

    const message = type === '一括送信'
        ? '選択した顧客へメールを送信しますか？'
        : `${type}を実行しますか？`;

    if (!await confirmModal(type,message)) return;

    try {
        const result = await api('send_mail',{
            surveyId:state.sendingSurveyId,
            customerIds:[...state.selectedCustomerIds],
            subject:$('mailSubject').value,
            body:$('mailBody').value,
            type
        });

        $('sendResult').innerHTML = `
            <div class="summary">
                <div class="summary-item"><strong>${result.total}</strong>対象件数</div>
                <div class="summary-item"><strong>${result.success}</strong>成功件数</div>
                <div class="summary-item"><strong>${result.failed}</strong>失敗件数</div>
                <div class="summary-item"><strong>${escapeHtml(result.sentAt)}</strong>送信日時</div>
            </div>
            <hr>
            ${result.results.map(r => `
                <p>
                    ${r.ok ? '✅':'❌'}
                    ${escapeHtml(r.name)}
                    (${escapeHtml(r.email)})
                    : ${escapeHtml(r.message)}
                </p>
            `).join('')}
        `;

        const data = await api('load_data');

        state.customers = data.customers;
        state.history = data.history;

        renderCustomers();
        renderHistory();

    } catch(e) {
        toast(e.message,true);
    }
}

/* ---------------------------------------------------------
 * Aggregation
 * --------------------------------------------------------- */

function renderAggregation() {
    const s = state.surveys.find(
        x => x.surveyId === state.aggregationSurveyId
    );

    if (!s) {
        showView('list');
        return;
    }

    const responses = state.responses.filter(
        r => r.surveyId === s.surveyId
    );

    const sentCustomers = state.customers.filter(
        c => c.sendCount > 0
    );

    const registered = responses.filter(
        r => r.respondentId
    );

    const unregistered = responses.filter(
        r => !r.respondentId
    );

    $('aggregationTitle').textContent = s.title;

    const rate = sentCustomers.length
        ? Math.round(responses.length / sentCustomers.length * 100)
        : 0;

    $('summary').innerHTML = `
        <div class="summary-item"><strong>${sentCustomers.length}</strong>送信対象者数</div>
        <div class="summary-item"><strong>${responses.length}</strong>回答数</div>
        <div class="summary-item"><strong>${unregistered.length}</strong>未登録回答数</div>
        <div class="summary-item"><strong>${Math.max(0,sentCustomers.length-responses.length)}</strong>未回答数</div>
        <div class="summary-item"><strong>${rate}%</strong>回答率</div>
    `;

    const questions = allSurveyQuestions(s);

    $('questionStats').innerHTML = questions.map(q => {
        const values = responses
            .map(r => r.answers?.[q.questionId])
            .filter(v => v !== undefined && v !== null && v !== '');

        if (q.type === 'text') {
            return `
            <div class="card">
                <label>
                    <input type="checkbox"
                           data-stat-question="${q.questionId}"
                           ${state.aggregationQuestions.has(q.questionId)?'checked':''}>
                    ${escapeHtml(q.questionNumber)} ${escapeHtml(q.questionText)}
                </label>
                <div>
                    ${values.map(v =>
                        `<p>${escapeHtml(Array.isArray(v)?v.join(', '):v)}</p>`
                    ).join('') || '<p class="muted">回答なし</p>'}
                </div>
            </div>`;
        }

        const counts = q.choices.map(c => ({
            label:c.label,
            count:values.reduce((n,v) => {
                if (Array.isArray(v)) return n + (v.includes(c.choiceId) ? 1:0);
                return n + (v === c.choiceId ? 1:0);
            },0)
        }));

        const total = values.length || 1;

        return `
        <div class="card">
            <label>
                <input type="checkbox"
                       data-stat-question="${q.questionId}"
                       ${state.aggregationQuestions.has(q.questionId)?'checked':''}>
                ${escapeHtml(q.questionNumber)} ${escapeHtml(q.questionText)}
            </label>

            ${counts.map(x => `
                <div style="margin:12px 0">
                    <div style="display:flex;justify-content:space-between">
                        <span>${escapeHtml(x.label)}</span>
                        <span>${x.count}件 / ${Math.round(x.count/total*100)}%</span>
                    </div>
                    <div class="bar">
                        <span style="width:${x.count/total*100}%"></span>
                    </div>
                </div>
            `).join('')}
        </div>`;
    }).join('');
}

function allSurveyQuestions(s) {
    return s.groups.flatMap(g => g.questions);
}

function exportCsv() {
    const s = state.surveys.find(
        x => x.surveyId === state.aggregationSurveyId
    );

    if (!s) return;

    const qs = allSurveyQuestions(s);

    const rows = [
        [
            '回答者名',
            'メールアドレス',
            ...qs.map(q => `${q.questionNumber}:${q.questionText}`)
        ]
    ];

    state.responses
        .filter(r => r.surveyId === s.surveyId)
        .forEach(r => {
            rows.push([
                r.respondent?.name || '',
                r.respondent?.email || '',
                ...qs.map(q => {
                    const value = r.answers?.[q.questionId];

                    if (Array.isArray(value)) {
                        return value.map(id =>
                            q.choices.find(c => c.choiceId === id)?.label || id
                        ).join(', ');
                    }

                    if (q.type === 'single') {
                        return q.choices.find(c => c.choiceId === value)?.label || value || '';
                    }

                    return value || '';
                })
            ]);
        });

    const csv = rows.map(row =>
        row.map(v => `"${String(v).replaceAll('"','""')}"`).join(',')
    ).join('\r\n');

    const blob = new Blob(
        ['\ufeff' + csv],
        {type:'text/csv;charset=utf-8'}
    );

    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `${s.title}_回答.csv`;
    a.click();

    toast('CSVを出力しました');
}

function exportPdf() {
    toast('PDF出力操作を実行しました。印刷ダイアログからPDF保存できます。');
    window.print();
}

/* ---------------------------------------------------------
 * Kintone / Mail
 * --------------------------------------------------------- */

function renderKintone() {
    const s = state.kintone?.settings || {};

    $('kSubdomain').value = s.subdomain || '';
    $('kAppId').value = s.appId || '';
    $('kLoginName').value = s.loginName || '';
    $('kPassword').value = '';
    $('kSsl').value = String(!!s.sslVerify);
    $('kProxy').value = s.proxy || '';

    renderKintoneFields();
    renderMapping();
}

function renderKintoneFields() {
    const fields = state.kintone?.fields || [];

    $('kintoneFieldsTable').innerHTML = fields.map(f => `
        <tr>
            <td>${escapeHtml(f.code)}</td>
            <td>${escapeHtml(f.label)}</td>
            <td>${escapeHtml(f.type)}</td>
        </tr>
    `).join('');
}

function renderMapping() {
    const fields = state.kintone?.fields || [];
    const mapping = state.kintone?.mapping || {};

    const select = (key,label) => `
        <div class="field">
            <label>${label}</label>
            <select data-map="${key}">
                <option value="">未設定</option>
                ${fields.map(f => `
                    <option value="${escapeHtml(f.code)}"
                        ${mapping[key] === f.code?'selected':''}>
                        ${escapeHtml(f.label)} (${escapeHtml(f.code)})
                    </option>
                `).join('')}
            </select>
        </div>`;

    $('mapping').innerHTML =
        select('organization','組織名') +
        select('name','氏名') +
        select('email','メールアドレス') +
        select('department','部署名') +
        select('phone','電話番号') +
        `<div class="field">
            <label>住所（複数選択可）</label>
            ${fields.map(f => `
                <label style="font-weight:normal">
                    <input type="checkbox"
                           data-address-map="${escapeHtml(f.code)}"
                           ${(mapping.address||[]).includes(f.code)?'checked':''}
                           style="width:auto">
                    ${escapeHtml(f.label)}
                </label>
            `).join('')}
        </div>`;
}

function renderMail() {
    const m = state.mail || {};

    $('smtpServer').value = m.smtpServer || '';
    $('smtpPort').value = m.smtpPort || 587;
    $('smtpEncryption').value = m.encryption || 'tls';
    $('smtpAuth').value = String(m.authentication !== false);
    $('smtpUsername').value = m.username || '';
    $('smtpPassword').value = '';
    $('smtpFrom').value = m.fromEmail || '';
    $('smtpFromName').value = m.fromName || '';
    $('smtpReply').value = m.replyTo || '';
}

/* ---------------------------------------------------------
 * Respondent
 * --------------------------------------------------------- */

async function initRespondent() {
    let survey = state.surveys.find(
        s => s.surveyId === INITIAL_SURVEY_ID
    );

    if (!survey) {
        $('answerContent').innerHTML =
            '<h2>アンケートが見つかりません。</h2>';
        return;
    }

    if (
        survey.status === 'published' &&
        survey.endDate &&
        Date.now() > new Date(survey.endDate).getTime()
    ) {
        survey.status = 'finished';
    }

    if (survey.status !== 'published') {
        $('answerContent').innerHTML = `
            <h1>${escapeHtml(survey.title)}</h1>
            <p>このアンケートは現在回答できません。</p>`;
        return;
    }

    state.answerSurvey = survey;

    if (!state.answerToken) {
        state.answerToken = 'public_' + crypto.randomUUID();
    }

    const existing = state.responses.find(r =>
        r.surveyId === survey.surveyId &&
        r.answerToken === state.answerToken
    );

    if (existing && !survey.allowReanswer) {
        $('answerContent').innerHTML = `
            <h1>${escapeHtml(survey.title)}</h1>
            <h2>回答済みです</h2>
            <p>このアンケートはすでに回答されています。</p>`;
        return;
    }

    renderAnswer();
}

function visibleQuestions() {
    const s = state.answerSurvey;

    if (!s) return [];

    const questions = allSurveyQuestions(s);

    /*
     * 条件分岐:
     * 回答済みの質問からnextQuestionIdを追跡し、
     * 明示的な分岐先がない質問は表示。
     */
    const hidden = new Set();

    questions.forEach(q => {
        if (q.type !== 'single') return;

        const answer = state.answerValues[q.questionId];

        if (!answer) return;

        const rule = q.branchRules.find(
            r => r.choiceId === answer
        );

        if (!rule?.nextQuestionId) return;

        let reached = false;

        for (const target of questions) {
            if (target.questionId === rule.nextQuestionId) {
                reached = true;
                continue;
            }

            if (reached) {
                hidden.add(target.questionId);
            }
        }
    });

    return questions.filter(q => !hidden.has(q.questionId));
}

function renderAnswer() {
    const s = state.answerSurvey;

    $('answerContent').innerHTML = `
        <h1>${escapeHtml(s.title)}</h1>
        <p>${escapeHtml(s.description)}</p>

        <div id="answerQuestions">
            ${visibleQuestions().map(q => answerQuestionHtml(q)).join('')}
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:20px">
            <button class="primary" id="answerNext">回答内容を確認する</button>
        </div>
    `;
}

function answerQuestionHtml(q) {
    const value = state.answerValues[q.questionId];

    if (q.type === 'text') {
        return `
        <div class="card">
            <label>
                ${escapeHtml(q.questionNumber)}
                ${escapeHtml(q.questionText)}
                ${q.required ? '<span style="color:red">*</span>':''}
            </label>
            <textarea
                data-answer-text="${q.questionId}"
                placeholder="回答を入力してください"
            >${escapeHtml(value || '')}</textarea>
        </div>`;
    }

    return `
    <div class="card">
        <strong>
            ${escapeHtml(q.questionNumber)}
            ${escapeHtml(q.questionText)}
            ${q.required ? '<span style="color:red">*</span>':''}
        </strong>

        ${q.choices.map(c => `
            <label class="answer-choice">
                <input
                    type="${q.type==='multiple'?'checkbox':'radio'}"
                    name="answer_${q.questionId}"
                    value="${escapeHtml(c.choiceId)}"
                    data-answer-choice="${q.questionId}"
                    ${Array.isArray(value)
                        ? value.includes(c.choiceId)?'checked':''
                        : value === c.choiceId?'checked':''}
                >
                ${escapeHtml(c.label)}
            </label>
        `).join('')}

        <div data-answer-error="${q.questionId}" style="color:red"></div>
    </div>`;
}

function collectAnswers() {
    const s = state.answerSurvey;

    visibleQuestions().forEach(q => {
        if (q.type === 'text') {
            const el = document.querySelector(
                `[data-answer-text="${q.questionId}"]`
            );

            state.answerValues[q.questionId] = el?.value || '';
        } else if (q.type === 'multiple') {
            state.answerValues[q.questionId] = [
                ...document.querySelectorAll(
                    `[data-answer-choice="${q.questionId}"]:checked`
                )
            ].map(x => x.value);
        } else {
            const el = document.querySelector(
                `[data-answer-choice="${q.questionId}"]:checked`
            );

            state.answerValues[q.questionId] = el?.value || '';
        }
    });
}

function validateAnswers() {
    collectAnswers();

    let valid = true;

    visibleQuestions().forEach(q => {
        if (!q.required) return;

        const value = state.answerValues[q.questionId];

        const empty =
            value === '' ||
            value === null ||
            value === undefined ||
            (Array.isArray(value) && value.length === 0);

        const error = document.querySelector(
            `[data-answer-error="${q.questionId}"]`
        );

        if (empty) {
            valid = false;

            if (error) {
                error.textContent = 'この質問は必須です。';
            }

            document.querySelector(
                `[data-answer-text="${q.questionId}"], [data-answer-choice="${q.questionId}"]`
            )?.scrollIntoView({
                behavior:'smooth',
                block:'center'
            });
        } else if (error) {
            error.textContent = '';
        }
    });

    return valid;
}

function renderConfirm() {
    const s = state.answerSurvey;

    $('answerContent').innerHTML = `
        <h1>回答内容の確認</h1>
        <p>${escapeHtml(s.title)}</p>

        ${visibleQuestions().map(q => {
            const value = state.answerValues[q.questionId];

            let display = value;

            if (Array.isArray(value)) {
                display = value.map(id =>
                    q.choices.find(c => c.choiceId === id)?.label || id
                ).join(', ');
            } else if (q.type === 'single') {
                display = q.choices.find(c => c.choiceId === value)?.label || value;
            }

            return `
            <div class="card">
                <strong>${escapeHtml(q.questionNumber)} ${escapeHtml(q.questionText)}</strong>
                <p>${escapeHtml(display || '未回答')}</p>
                <button data-answer-edit="${q.questionId}">修正</button>
            </div>`;
        }).join('')}

        <div class="toolbar">
            <button id="answerBack">戻る</button>
            <button class="primary" id="answerSubmit">回答を送信する</button>
        </div>
    `;
}

async function submitAnswer() {
    if (!await confirmModal(
        '回答送信確認',
        'この内容で回答を送信しますか？'
    )) return;

    try {
        await api('save_response',{
            surveyId:state.answerSurvey.surveyId,
            answerToken:state.answerToken,
            answers:state.answerValues,
            respondent:{
                customerId:
                    state.answerToken.startsWith('customer_')
                        ? state.answerToken
                        : null
            }
        });

        $('answerContent').innerHTML = `
            <div style="text-align:center;padding:40px 10px">
                <h1>回答ありがとうございました</h1>
                <p>回答を正常に受け付けました。</p>
                <p>この画面を閉じてください。</p>
            </div>`;
    } catch(e) {
        toast(e.message,true);
    }
}

/* ---------------------------------------------------------
 * D&D
 * --------------------------------------------------------- */

let dragInfo = null;

document.addEventListener('dragstart', e => {
    const q = e.target.closest('[data-question]');
    const g = e.target.closest('[data-group]');

    if (q) {
        dragInfo = {
            type:'question',
            id:q.dataset.question,
            groupId:q.dataset.group
        };
        q.classList.add('dragging');
    } else if (g) {
        dragInfo = {
            type:'group',
            id:g.dataset.group
        };
        g.classList.add('dragging');
    }
});

document.addEventListener('dragend', e => {
    document.querySelectorAll('.dragging')
        .forEach(x => x.classList.remove('dragging'));

    dragInfo = null;
});

document.addEventListener('dragover', e => {
    if (!dragInfo) return;

    const q = e.target.closest('[data-question]');
    const g = e.target.closest('[data-group]');

    if (
        dragInfo.type === 'question' &&
        q &&
        q.dataset.question !== dragInfo.id
    ) {
        e.preventDefault();
    }

    if (
        dragInfo.type === 'group' &&
        g &&
        g.dataset.group !== dragInfo.id
    ) {
        e.preventDefault();
    }
});

document.addEventListener('drop', e => {
    if (!dragInfo) return;

    const targetQ = e.target.closest('[data-question]');
    const targetG = e.target.closest('[data-group]');

    if (dragInfo.type === 'group' && targetG) {
        e.preventDefault();

        const from = state.editingSurvey.groups.findIndex(
            g => g.groupId === dragInfo.id
        );

        const to = state.editingSurvey.groups.findIndex(
            g => g.groupId === targetG.dataset.group
        );

        if (from >= 0 && to >= 0 && from !== to) {
            const [item] = state.editingSurvey.groups.splice(from,1);
            state.editingSurvey.groups.splice(to,0,item);
            renderGroups();
        }

        return;
    }

    if (dragInfo.type === 'question' && targetQ) {
        e.preventDefault();

        let sourceGroup;
        let sourceIndex = -1;

        for (const g of state.editingSurvey.groups) {
            const i = g.questions.findIndex(
                q => q.questionId === dragInfo.id
            );

            if (i >= 0) {
                sourceGroup = g;
                sourceIndex = i;
                break;
            }
        }

        const targetGroup = state.editingSurvey.groups.find(
            g => g.groupId === targetQ.dataset.group
        );

        const targetIndex = targetGroup?.questions.findIndex(
            q => q.questionId === targetQ.dataset.question
        );

        if (!sourceGroup || !targetGroup || targetIndex < 0) return;

        const [q] = sourceGroup.questions.splice(sourceIndex,1);

        q.groupId = targetGroup.groupId;

        targetGroup.questions.splice(targetIndex,0,q);

        renderGroups();
    }
});

/* ---------------------------------------------------------
 * Event delegation
 * --------------------------------------------------------- */

document.addEventListener('click', async e => {
    const target = e.target;

    const view = target.closest('[data-view]');
    if (view) {
        showView(view.dataset.view);
        return;
    }

    if (target.id === 'newSurvey') {
        newSurvey();
        return;
    }

    if (target.id === 'saveSurvey') {
        await saveEditor();
        return;
    }

    if (target.id === 'cancelEdit') {
        if (await confirmModal(
            '編集内容破棄',
            '編集内容を破棄しますか？'
        )) {
            showView('list');
        }
        return;
    }

    if (target.id === 'addGroup') {
        addGroup();
        return;
    }

    if (target.id === 'previewPc' || target.id === 'previewMobile') {
        renderPreview();

        const p = $('preview');

        if (target.id === 'previewMobile') {
            p.style.maxWidth = '390px';
        } else {
            p.style.maxWidth = '760px';
        }

        showView('preview');
        return;
    }

    const edit = target.closest('[data-edit]');
    if (edit) {
        editSurvey(edit.dataset.edit);
        return;
    }

    const aggregate = target.closest('[data-aggregate]');
    if (aggregate) {
        state.aggregationSurveyId = aggregate.dataset.aggregate;

        if (!state.aggregationSurveyId) {
            toast('対象アンケートがありません',true);
            return;
        }

        showView('aggregation');
        return;
    }

    const send = target.closest('[data-send]');
    if (send) {
        state.sendingSurveyId = send.dataset.send;

        if (!state.sendingSurveyId) {
            toast('対象アンケートがありません',true);
            return;
        }

        state.selectedCustomerIds.clear();
        showView('send');
        return;
    }

    const duplicate = target.closest('[data-duplicate]');
    if (duplicate) {
        if (!await confirmModal(
            'アンケート複製',
            'このアンケートを複製しますか？'
        )) return;

        try {
            const copy = await api('duplicate_survey',{
                surveyId:duplicate.dataset.duplicate
            });

            state.surveys.push(copy);
            renderSurveyList();
            toast('複製しました');
        } catch(e) {
            toast(e.message,true);
        }
        return;
    }

    const del = target.closest('[data-delete]');
    if (del) {
        if (!await confirmModal(
            'アンケート削除',
            'このアンケートを削除しますか？'
        )) return;

        try {
            await api('delete_survey',{
                surveyId:del.dataset.delete
            });

            state.surveys = state.surveys.filter(
                s => s.surveyId !== del.dataset.delete
            );

            renderSurveyList();
            toast('削除しました');
        } catch(e) {
            toast(e.message,true);
        }
        return;
    }

    const rg = target.closest('[data-remove-group]');
    if (rg) {
        await removeGroup(rg.dataset.removeGroup);
        return;
    }

    const aq = target.closest('[data-add-question]');
    if (aq) {
        addQuestion(aq.dataset.addQuestion);
        return;
    }

    const rq = target.closest('[data-remove-question]');
    if (rq) {
        await removeQuestion(rq.dataset.removeQuestion);
        return;
    }

    const ac = target.closest('[data-add-choice]');
    if (ac) {
        addChoice(ac.dataset.addChoice);
        return;
    }

    const rc = target.closest('[data-remove-choice]');
    if (rc) {
        removeChoice(rc.dataset.removeChoice,rc.dataset.choice);
        return;
    }

    if (target.id === 'selectAllCustomers') {
        state.customers.forEach(c =>
            state.selectedCustomerIds.add(c.customerId)
        );
        renderCustomers();
        return;
    }

    if (target.id === 'clearCustomers') {
        state.selectedCustomerIds.clear();
        renderCustomers();
        return;
    }

    if (target.id === 'selectReminder') {
        state.selectedCustomerIds.clear();

        state.customers
            .filter(c => c.answerStatus === '送信済み / 未回答')
            .forEach(c =>
                state.selectedCustomerIds.add(c.customerId)
            );

        renderCustomers();
        return;
    }

    if (target.id === 'sendBulk') {
        await sendMail('一括送信');
        return;
    }

    if (target.id === 'resend') {
        await sendMail('再送');
        return;
    }

    if (target.id === 'remind') {
        await sendMail('リマインド');
        return;
    }

    if (target.id === 'csvExport') {
        exportCsv();
        return;
    }

    if (target.id === 'pdfExport') {
        exportPdf();
        return;
    }

    if (target.id === 'allQuestions') {
        allSurveyQuestions(
            state.surveys.find(s =>
                s.surveyId === state.aggregationSurveyId
            )
        ).forEach(q => state.aggregationQuestions.add(q.questionId));

        renderAggregation();
        return;
    }

    if (target.id === 'noQuestions') {
        state.aggregationQuestions.clear();
        renderAggregation();
        return;
    }

    if (target.id === 'saveKintone') {
        try {
            const settings = {
                subdomain:$('kSubdomain').value,
                appId:$('kAppId').value,
                loginName:$('kLoginName').value,
                password:$('kPassword').value,
                sslVerify:$('kSsl').value === 'true',
                proxy:$('kProxy').value
            };

            await api('save_kintone',{settings});

            const data = await api('load_data');
            state.kintone = data.kintone;

            toast('kintone設定を保存しました');
        } catch(e) {
            toast(e.message,true);
        }
        return;
    }

    if (target.id === 'kintoneTest') {
        try {
            const result = await api('kintone_test');

            $('kintoneResult').innerHTML =
                `<p style="color:green">✓ ${escapeHtml(result.message)}</p>`;

            toast(result.message);
        } catch(e) {
            $('kintoneResult').innerHTML =
                `<p style="color:red">✕ ${escapeHtml(e.message)}</p>`;

            toast(e.message,true);
        }
        return;
    }

    if (target.id === 'kintoneFields') {
        try {
            const fields = await api('kintone_fields');

            state.kintone.fields = fields;
            renderKintoneFields();
            renderMapping();

            toast('項目一覧を取得しました');
        } catch(e) {
            toast(e.message,true);
        }
        return;
    }

    if (target.id === 'kintoneSync') {
        try {
            const result = await api('kintone_sync');

            const data = await api('load_data');

            state.customers = data.customers;

            toast(result.message);
        } catch(e) {
            toast(e.message,true);
        }
        return;
    }

    if (target.id === 'saveMail') {
        try {
            const mail = {
                smtpServer:$('smtpServer').value,
                smtpPort:Number($('smtpPort').value),
                encryption:$('smtpEncryption').value,
                authentication:$('smtpAuth').value === 'true',
                username:$('smtpUsername').value,
                password:$('smtpPassword').value,
                fromEmail:$('smtpFrom').value,
                fromName:$('smtpFromName').value,
                replyTo:$('smtpReply').value
            };

            state.mail = await api('save_mail',{mail});

            toast('メール設定を保存しました');
        } catch(e) {
            toast(e.message,true);
        }
        return;
    }

    if (target.id === 'testMail') {
        const to = prompt('テスト送信先メールアドレス');

        if (!to) return;

        try {
            const result = await api('test_mail',{to});
            $('mailResult').innerHTML =
                `<p style="color:green">✓ ${escapeHtml(result.message)}</p>`;
            toast(result.message);
        } catch(e) {
            $('mailResult').innerHTML =
                `<p style="color:red">✕ ${escapeHtml(e.message)}</p>`;
            toast(e.message,true);
        }
        return;
    }

    if (target.id === 'answerNext') {
        if (!validateAnswers()) return;

        state.answerStep = 'confirm';
        renderConfirm();
        return;
    }

    if (target.id === 'answerBack') {
        state.answerStep = 'answer';
        renderAnswer();
        return;
    }

    if (target.id === 'answerSubmit') {
        await submitAnswer();
        return;
    }

    const answerEdit = target.closest('[data-answer-edit]');
    if (answerEdit) {
        state.answerStep = 'answer';
        renderAnswer();

        setTimeout(() => {
            document.querySelector(
                `[data-answer-text="${answerEdit.dataset.answerEdit}"], [data-answer-choice="${answerEdit.dataset.answerEdit}"]`
            )?.scrollIntoView({
                behavior:'smooth',
                block:'center'
            });
        },50);

        return;
    }
});

document.addEventListener('change', async e => {
    const t = e.target;

    if (t.id === 'editStatus') {
        await changeEditStatus();
        return;
    }

    if (t.id === 'editNumbering') {
        collectEditorInputs();
        state.editingSurvey.questionNumberMode = t.value;
        renderGroups();
        return;
    }

    if (t.dataset.customer) {
        if (t.checked) {
            state.selectedCustomerIds.add(t.dataset.customer);
        } else {
            state.selectedCustomerIds.delete(t.dataset.customer);
        }
        return;
    }

    if (t.dataset.statQuestion) {
        if (t.checked) {
            state.aggregationQuestions.add(t.dataset.statQuestion);
        } else {
            state.aggregationQuestions.delete(t.dataset.statQuestion);
        }
        return;
    }

    if (t.dataset.map) {
        state.kintone.mapping ||= {};
        state.kintone.mapping[t.dataset.map] = t.value;

        await api('save_kintone_mapping',{
            mapping:state.kintone.mapping
        });

        return;
    }

    if (t.dataset.addressMap) {
        state.kintone.mapping ||= {};
        state.kintone.mapping.address ||= [];

        if (t.checked) {
            if (!state.kintone.mapping.address.includes(t.dataset.addressMap)) {
                state.kintone.mapping.address.push(t.dataset.addressMap);
            }
        } else {
            state.kintone.mapping.address =
                state.kintone.mapping.address.filter(
                    x => x !== t.dataset.addressMap
                );
        }

        await api('save_kintone_mapping',{
            mapping:state.kintone.mapping
        });

        return;
    }

    if (t.dataset.questionType) {
        collectEditorInputs();

        const q = allQuestions().find(
            x => x.questionId === t.dataset.questionType
        );

        if (q) {
            q.type = t.value;

            if (q.type === 'text') {
                q.choices = [];
                q.branchRules = [];
            } else if (!q.choices.length) {
                q.choices = [
                    {
                        choiceId:'choice_'+crypto.randomUUID(),
                        label:'選択肢1',
                        sortOrder:0
                    },
                    {
                        choiceId:'choice_'+crypto.randomUUID(),
                        label:'選択肢2',
                        sortOrder:1
                    }
                ];
            }
        }

        renderGroups();
        return;
    }

    if (t.dataset.branch) {
        collectEditorInputs();
        return;
    }

    if (t.dataset.answerText || t.dataset.answerChoice) {
        /*
         * 回答入力は次へ押下時にまとめて取得する。
         */
        return;
    }
});

$('surveySearch')?.addEventListener('input',renderSurveyList);
$('surveySearch')?.addEventListener('keydown',e => {
    if (e.key === 'Enter') {
        e.preventDefault();
        renderSurveyList();
    }
});

$('surveyFilter')?.addEventListener('change',renderSurveyList);
$('surveySort')?.addEventListener('change',renderSurveyList);
$('customerSearch')?.addEventListener('input',renderCustomers);

$('adminReset')?.addEventListener('click',() => {
    showView('list');
});

/* =========================================================
 * Start
 * ========================================================= */

loadData();

</script>
</body>
</html>
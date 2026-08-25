<?php
declare(strict_types=1);

/*
 * アンケート管理システム
 * PHP 8.5 / Apache 2.4
 *
 * PHPファイルは本ファイルのみ。
 * 永続データは data/*.json。
 */

date_default_timezone_set('Asia/Tokyo');

const DATA_DIR = __DIR__ . '/data';

const FILE_SURVEYS = DATA_DIR . '/surveys.json';
const FILE_CUSTOMERS = DATA_DIR . '/customers.json';
const FILE_RESPONSES = DATA_DIR . '/responses.json';
const FILE_HISTORY = DATA_DIR . '/send_history.json';
const FILE_KINTONE = DATA_DIR . '/kintone.json';
const FILE_MAIL = DATA_DIR . '/mail.json';

const ALLOWED_VIEWS = [
    'admin-survey-list',
    'admin-survey-edit',
    'admin-preview',
    'admin-send',
    'admin-aggregation',
    'admin-kintone',
    'admin-mail',
    'answer',
    'confirm',
    'complete',
];

function jsonResponse(bool $ok, mixed $data = null, ?string $code = null, ?string $message = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $ok
            ? ['ok' => true, 'data' => $data]
            : ['ok' => false, 'error' => [
                'code' => $code ?? 'ERROR',
                'message' => $message ?? '処理に失敗しました。'
            ]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function fail(string $code, string $message, int $status = 400): never
{
    jsonResponse(false, null, $code, $message, $status);
}

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ensureData(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }

    $defaults = [
        FILE_SURVEYS => [],
        FILE_CUSTOMERS => [],
        FILE_RESPONSES => [],
        FILE_HISTORY => [],
        FILE_KINTONE => [
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
                'organizationName' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
        ],
        FILE_MAIL => [
            'smtpServer' => '',
            'smtpPort' => 587,
            'encryption' => 'tls',
            'authentication' => true,
            'username' => '',
            'password' => '',
            'fromEmail' => '',
            'fromName' => '',
            'replyTo' => '',
            'status' => '未設定',
        ],
    ];

    foreach ($defaults as $file => $value) {
        if (!file_exists($file)) {
            atomicWrite($file, $value);
        }
    }

    $surveys = readJson(FILE_SURVEYS);

    if (!$surveys) {
        $now = date('c');

        $surveys = [
            sampleSurvey('survey-draft', 'サンプル下書き', 'draft', null),
            sampleSurvey('survey-published', 'サンプル公開中', 'published', date('c', time() + 86400 * 30)),
            sampleSurvey('survey-stopped', 'サンプル停止', 'stopped', date('c', time() + 86400 * 30)),
            sampleSurvey('survey-finished', 'サンプル終了', 'finished', date('c', time() - 86400)),
            sampleSurvey('survey-draft-expired', '下書き＋過去日時', 'draft', date('c', time() - 86400)),
            sampleSurvey('survey-published-expired', '公開中＋過去日時', 'published', date('c', time() - 86400)),
            sampleSurvey('survey-stopped-expired', '停止＋過去日時', 'stopped', date('c', time() - 86400)),
        ];

        atomicWrite(FILE_SURVEYS, $surveys);
    }

    $customers = readJson(FILE_CUSTOMERS);

    if (!$customers) {
        $customers = [
            [
                'customerId' => 'customer001',
                'organizationName' => '株式会社サンプル',
                'name' => '山田 太郎',
                'email' => 'taro@example.com',
                'department' => '営業部',
                'phone' => '03-0000-0000',
                'address' => [
                    'postalCode' => '100-0001',
                    'prefecture' => '東京都',
                    'city' => '千代田区',
                    'street' => '千代田1-1',
                    'building' => '',
                ],
                'lastSentAt' => null,
                'sendCount' => 0,
                'answerStatus' => '未送信',
                'kintoneStatus' => '未同期',
            ],
            [
                'customerId' => 'customer002',
                'organizationName' => 'テスト株式会社',
                'name' => '佐藤 花子',
                'email' => 'hanako@example.com',
                'department' => '総務部',
                'phone' => '03-1111-1111',
                'address' => [
                    'postalCode' => '150-0001',
                    'prefecture' => '東京都',
                    'city' => '渋谷区',
                    'street' => '神宮前1-2',
                    'building' => 'テストビル',
                ],
                'lastSentAt' => null,
                'sendCount' => 0,
                'answerStatus' => '未送信',
                'kintoneStatus' => '未同期',
            ],
            [
                'customerId' => 'customer003',
                'organizationName' => 'デモ商事',
                'name' => '鈴木 一郎',
                'email' => 'ichiro@example.com',
                'department' => '企画部',
                'phone' => '03-2222-2222',
                'address' => [
                    'postalCode' => '160-0001',
                    'prefecture' => '東京都',
                    'city' => '新宿区',
                    'street' => '新宿2-3',
                    'building' => '',
                ],
                'lastSentAt' => null,
                'sendCount' => 0,
                'answerStatus' => '未送信',
                'kintoneStatus' => '未同期',
            ],
        ];

        atomicWrite(FILE_CUSTOMERS, $customers);
    }
}

function sampleSurvey(string $id, string $title, string $status, ?string $end): array
{
    $q1 = uid('q');
    $q2 = uid('q');
    $c1 = uid('c');
    $c2 = uid('c');

    return [
        'surveyId' => $id,
        'title' => $title,
        'description' => 'これは動作確認用のサンプルアンケートです。',
        'startDate' => date('c', time() - 86400),
        'endDate' => $end,
        'numbering' => 'all',
        'status' => $status,
        'allowReanswer' => false,
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'groups' => [
            [
                'groupId' => uid('g'),
                'title' => '基本情報',
                'sortOrder' => 1,
                'questions' => [
                    [
                        'questionId' => $q1,
                        'groupId' => '',
                        'sortOrder' => 1,
                        'questionText' => 'サービスに満足していますか？',
                        'type' => 'single',
                        'required' => true,
                        'choices' => [
                            [
                                'choiceId' => $c1,
                                'label' => 'はい',
                                'sortOrder' => 1,
                            ],
                            [
                                'choiceId' => $c2,
                                'label' => 'いいえ',
                                'sortOrder' => 2,
                            ],
                        ],
                        'branchRules' => [],
                        'questionNumber' => '',
                    ],
                    [
                        'questionId' => $q2,
                        'groupId' => '',
                        'sortOrder' => 2,
                        'questionText' => 'ご意見をご記入ください。',
                        'type' => 'text',
                        'required' => false,
                        'choices' => [],
                        'branchRules' => [],
                        'questionNumber' => '',
                    ],
                ],
            ],
        ],
    ];
}

function uid(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function readJson(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $fp = fopen($file, 'rb');

    if (!$fp) {
        throw new RuntimeException("ファイルを開けません: {$file}");
    }

    flock($fp, LOCK_SH);
    $contents = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $data = json_decode($contents, true);

    if (!is_array($data)) {
        throw new RuntimeException("JSONが不正です: {$file}");
    }

    return $data;
}

function atomicWrite(string $file, array $data): void
{
    $dir = dirname($file);

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $lockFile = $file . '.lock';
    $lock = fopen($lockFile, 'c');

    if (!$lock) {
        throw new RuntimeException('ロックファイルを作成できません。');
    }

    flock($lock, LOCK_EX);

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));

    $fp = fopen($tmp, 'wb');

    if (!$fp) {
        flock($lock, LOCK_UN);
        fclose($lock);
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        fclose($fp);
        flock($lock, LOCK_UN);
        fclose($lock);
        throw new RuntimeException('JSONエンコードに失敗しました。');
    }

    fwrite($fp, $json);
    fflush($fp);
    fclose($fp);

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        flock($lock, LOCK_UN);
        fclose($lock);
        throw new RuntimeException('JSON保存に失敗しました。');
    }

    flock($lock, LOCK_UN);
    fclose($lock);
}

function normalizeSurveyStatus(array &$survey): bool
{
    if (($survey['status'] ?? '') !== 'published') {
        return false;
    }

    $end = $survey['endDate'] ?? null;

    if (!$end) {
        return false;
    }

    $timestamp = strtotime((string)$end);

    if ($timestamp === false) {
        return false;
    }

    if (time() > $timestamp) {
        $survey['status'] = 'finished';
        $survey['updatedAt'] = date('c');
        return true;
    }

    return false;
}

function normalizeAllSurveys(array &$surveys): bool
{
    $changed = false;

    foreach ($surveys as &$survey) {
        if (normalizeSurveyStatus($survey)) {
            $changed = true;
        }

        recalculateSurvey($survey);
    }

    unset($survey);

    return $changed;
}

function recalculateSurvey(array &$survey): void
{
    $groups = $survey['groups'] ?? [];

    usort($groups, fn($a, $b) =>
        ((int)($a['sortOrder'] ?? 0)) <=> ((int)($b['sortOrder'] ?? 0))
    );

    $global = 1;
    $groupNo = 1;

    foreach ($groups as &$group) {
        $questions = $group['questions'] ?? [];

        usort($questions, fn($a, $b) =>
            ((int)($a['sortOrder'] ?? 0)) <=> ((int)($b['sortOrder'] ?? 0))
        );

        $questionNo = 1;

        foreach ($questions as &$question) {
            $question['groupId'] = $group['groupId'];
            $question['sortOrder'] = $questionNo;

            if (($survey['numbering'] ?? 'all') === 'group') {
                $question['questionNumber'] = 'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['questionNumber'] = 'Q' . $global;
            }

            foreach (($question['choices'] ?? []) as $i => &$choice) {
                $choice['sortOrder'] = $i + 1;
            }

            unset($choice);

            $questionNo++;
            $global++;
        }

        unset($question);

        $group['sortOrder'] = $groupNo;
        $group['questions'] = $questions;

        $groupNo++;
    }

    unset($group);

    $survey['groups'] = $groups;
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

function validId(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,100}$/', $value);
}

function requestJson(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        fail('INVALID_JSON', '送信されたJSONが不正です。');
    }

    return $data;
}

function validateSurvey(array $survey): void
{
    if (!isset($survey['surveyId']) || !validId((string)$survey['surveyId'])) {
        fail('INVALID_SURVEY_ID', 'アンケートIDが不正です。');
    }

    if (!isset($survey['title']) || trim((string)$survey['title']) === '') {
        fail('VALIDATION', 'アンケートタイトルを入力してください。');
    }

    $allowedStatuses = ['draft', 'published', 'stopped', 'finished'];

    if (!in_array($survey['status'] ?? 'draft', $allowedStatuses, true)) {
        fail('VALIDATION', '不正なステータスです。');
    }

    if (!in_array($survey['numbering'] ?? 'all', ['all', 'group'], true)) {
        fail('VALIDATION', '質問番号方式が不正です。');
    }

    foreach (($survey['groups'] ?? []) as $group) {
        if (!isset($group['groupId']) || !validId((string)$group['groupId'])) {
            fail('VALIDATION', 'グループIDが不正です。');
        }

        foreach (($group['questions'] ?? []) as $question) {
            if (!isset($question['questionId']) || !validId((string)$question['questionId'])) {
                fail('VALIDATION', '質問IDが不正です。');
            }

            if (!in_array($question['type'] ?? '', ['single', 'multiple', 'text'], true)) {
                fail('VALIDATION', '回答形式が不正です。');
            }

            if (($question['type'] ?? '') !== 'text' && empty($question['choices'])) {
                fail('VALIDATION', '選択式質問には選択肢が必要です。');
            }
        }
    }
}

function statusTransitionAllowed(string $from, string $to): bool
{
    return match ($from) {
        'draft' => $to === 'published',
        'published' => $to === 'stopped',
        'stopped' => $to === 'published',
        default => false,
    };
}

function baseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

    return $scheme . '://' . $host . $script;
}

function surveyAnswerUrl(string $surveyId, ?string $token = null): string
{
    $params = [
        'view' => 'answer',
        'surveyId' => $surveyId,
    ];

    if ($token !== null && $token !== '') {
        $params['token'] = $token;
    }

    return baseUrl() . '?' . http_build_query($params);
}

function customerById(array $customers, string $id): ?array
{
    foreach ($customers as $customer) {
        if (($customer['customerId'] ?? '') === $id) {
            return $customer;
        }
    }

    return null;
}

function responseFor(array $responses, string $surveyId, ?string $token, ?string $customerId): ?array
{
    foreach ($responses as $response) {
        if (($response['surveyId'] ?? '') !== $surveyId) {
            continue;
        }

        if ($token !== null && $token !== '' && ($response['token'] ?? '') === $token) {
            return $response;
        }

        if ($customerId !== null && $customerId !== '' && ($response['customerId'] ?? '') === $customerId) {
            return $response;
        }
    }

    return null;
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

function answerCount(array $responses, string $surveyId): int
{
    $count = 0;

    foreach ($responses as $response) {
        if (($response['surveyId'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

function mailSettingsSafe(array $settings): array
{
    $copy = $settings;
    $copy['password'] = '';
    $copy['passwordConfigured'] = !empty($settings['password']);
    return $copy;
}

/* ----------------------------------------------------------
 * SMTP
 * ---------------------------------------------------------- */

function smtpRead($socket): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

function smtpExpect($socket, array $codes): void
{
    $response = smtpRead($socket);

    $code = (int)substr(trim($response), 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP応答エラー: ' . trim($response));
    }
}

function smtpCommand($socket, string $command, array $codes): void
{
    fwrite($socket, $command . "\r\n");
    smtpExpect($socket, $codes);
}

function smtpSend(array $settings, string $to, string $subject, string $body): array
{
    $server = trim((string)($settings['smtpServer'] ?? ''));
    $port = (int)($settings['smtpPort'] ?? 587);
    $encryption = $settings['encryption'] ?? 'tls';

    if ($server === '' || $port <= 0) {
        throw new RuntimeException('SMTPサーバ設定が未設定です。');
    }

    $transportHost = $server;

    if ($encryption === 'ssl') {
        $transportHost = 'ssl://' . $server;
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $socket = @stream_socket_client(
        $transportHost . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException("SMTP接続失敗: {$errstr} ({$errno})");
    }

    stream_set_timeout($socket, 15);

    try {
        smtpExpect($socket, [220]);

        smtpCommand($socket, 'EHLO localhost', [250]);

        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS接続に失敗しました。');
            }

            smtpCommand($socket, 'EHLO localhost', [250]);
        }

        if (!empty($settings['authentication'])) {
            $username = (string)($settings['username'] ?? '');
            $password = (string)($settings['password'] ?? '');

            if ($username === '' || $password === '') {
                throw new RuntimeException('SMTP認証情報が未設定です。');
            }

            smtpCommand($socket, 'AUTH LOGIN', [334]);
            smtpCommand($socket, base64_encode($username), [334]);
            smtpCommand($socket, base64_encode($password), [235]);
        }

        $from = (string)($settings['fromEmail'] ?? '');

        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('送信元メールアドレスが不正です。');
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('宛先メールアドレスが不正です。');
        }

        smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $fromName = (string)($settings['fromName'] ?? '');

        $headers = [];
        $headers[] = 'From: ' . ($fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>'
            : $from);

        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $replyTo = trim((string)($settings['replyTo'] ?? ''));

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $message = implode("\r\n", $headers)
            . "\r\n\r\n"
            . str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body)
            . "\r\n.";

        fwrite($socket, $message . "\r\n");
        smtpExpect($socket, [250]);

        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }

    return ['success' => true];
}

/* ----------------------------------------------------------
 * kintone
 * ---------------------------------------------------------- */

function normalizeKintoneBase(string $input): string
{
    $input = trim($input);

    $input = preg_replace('#^https?://#i', '', $input);
    $input = rtrim($input, '/');

    if (str_contains($input, '/')) {
        $input = explode('/', $input)[0];
    }

    if (!str_ends_with($input, '.cybozu.com')) {
        $input .= '.cybozu.com';
    }

    return 'https://' . $input;
}

function kintoneRequest(array $settings, string $path, string $method = 'GET', ?array $body = null): array
{
    $subdomain = trim((string)($settings['subdomain'] ?? ''));
    $appId = trim((string)($settings['appId'] ?? ''));
    $login = (string)($settings['loginName'] ?? '');
    $password = (string)($settings['password'] ?? '');
    $sslVerify = (bool)($settings['sslVerify'] ?? false);
    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($subdomain === '' || $appId === '' || $login === '' || $password === '') {
        throw new RuntimeException('kintone接続設定が不足しています。');
    }

    $base = normalizeKintoneBase($subdomain);
    $url = $base . $path;

    $ch = curl_init($url);

    $headers = [
        'X-Cybozu-Authorization: ' . base64_encode($login . ':' . $password),
        'Content-Type: application/json',
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => $sslVerify,
        CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    if ($proxy !== '') {
        $options[CURLOPT_PROXY] = $proxy;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false || $errno !== 0) {
        throw new RuntimeException("kintone通信失敗: {$error}");
    }

    $decoded = json_decode($response, true);

    if ($status < 200 || $status >= 300) {
        $detail = is_array($decoded)
            ? json_encode($decoded, JSON_UNESCAPED_UNICODE)
            : $response;

        throw new RuntimeException("kintone APIエラー HTTP {$status}: {$detail}");
    }

    return is_array($decoded) ? $decoded : [];
}

function kintoneGetFields(array $settings): array
{
    $appId = (string)$settings['appId'];

    return kintoneRequest(
        $settings,
        '/v1/app/form/fields.json?app=' . rawurlencode($appId)
    );
}

function kintoneGetRecords(array $settings): array
{
    $appId = (string)$settings['appId'];

    return kintoneRequest(
        $settings,
        '/v1/records.json?app=' . rawurlencode($appId) . '&query=' . rawurlencode('limit 500'),
        'GET'
    );
}

/* ----------------------------------------------------------
 * API
 * ---------------------------------------------------------- */

ensureData();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === '') {
            $input = requestJson();
            $action = $input['action'] ?? '';
        } else {
            $input = $_POST;

            if (isset($_POST['payload'])) {
                $payload = json_decode((string)$_POST['payload'], true);
                if (is_array($payload)) {
                    $input = array_merge($input, $payload);
                }
            }
        }

        switch ($action) {

            case 'load_data':
                $surveys = readJson(FILE_SURVEYS);
                $changed = normalizeAllSurveys($surveys);

                if ($changed) {
                    atomicWrite(FILE_SURVEYS, $surveys);
                }

                jsonResponse(true, [
                    'surveys' => $surveys,
                    'customers' => readJson(FILE_CUSTOMERS),
                    'responses' => readJson(FILE_RESPONSES),
                    'history' => readJson(FILE_HISTORY),
                    'kintone' => readJson(FILE_KINTONE),
                    'mail' => mailSettingsSafe(readJson(FILE_MAIL)),
                ]);
                break;

            case 'save_survey':
                $survey = $input['survey'] ?? null;

                if (!is_array($survey)) {
                    fail('INVALID_SURVEY', 'アンケートデータがありません。');
                }

                $surveys = readJson(FILE_SURVEYS);

                recalculateSurvey($survey);
                validateSurvey($survey);

                $found = false;

                foreach ($surveys as $i => $existing) {
                    if (($existing['surveyId'] ?? '') === $survey['surveyId']) {
                        $survey['createdAt'] = $existing['createdAt'] ?? date('c');
                        $survey['updatedAt'] = date('c');
                        $surveys[$i] = $survey;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $survey['createdAt'] = date('c');
                    $survey['updatedAt'] = date('c');
                    $survey['status'] = 'draft';
                    $surveys[] = $survey;
                }

                atomicWrite(FILE_SURVEYS, $surveys);

                jsonResponse(true, $survey);
                break;

            case 'change_status':
                $surveyId = (string)($input['surveyId'] ?? '');
                $newStatus = (string)($input['status'] ?? '');

                if (!validId($surveyId)) {
                    fail('INVALID_SURVEY_ID', 'アンケートIDが不正です。');
                }

                $surveys = readJson(FILE_SURVEYS);
                $found = false;

                foreach ($surveys as &$survey) {
                    if (($survey['surveyId'] ?? '') !== $surveyId) {
                        continue;
                    }

                    normalizeSurveyStatus($survey);

                    if (!statusTransitionAllowed(
                        (string)$survey['status'],
                        $newStatus
                    )) {
                        fail('INVALID_TRANSITION', '許可されていない状態遷移です。');
                    }

                    $survey['status'] = $newStatus;
                    $survey['updatedAt'] = date('c');
                    $found = true;
                    break;
                }

                unset($survey);

                if (!$found) {
                    fail('NOT_FOUND', '対象アンケートが存在しません.', 404);
                }

                atomicWrite(FILE_SURVEYS, $surveys);

                jsonResponse(true, ['surveyId' => $surveyId, 'status' => $newStatus]);
                break;

            case 'delete_survey':
                $surveyId = (string)($input['surveyId'] ?? '');

                $surveys = readJson(FILE_SURVEYS);
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
                    fail('NOT_FOUND', '対象アンケートが存在しません.', 404);
                }

                atomicWrite(FILE_SURVEYS, $new);

                $responses = readJson(FILE_RESPONSES);
                $responses = array_values(array_filter(
                    $responses,
                    fn($r) => ($r['surveyId'] ?? '') !== $surveyId
                ));
                atomicWrite(FILE_RESPONSES, $responses);

                jsonResponse(true, ['deleted' => $surveyId]);
                break;

            case 'duplicate_survey':
                $surveyId = (string)($input['surveyId'] ?? '');

                $surveys = readJson(FILE_SURVEYS);
                $source = findSurvey($surveys, $surveyId);

                if (!$source) {
                    fail('NOT_FOUND', '複製元アンケートが存在しません.', 404);
                }

                $copy = $source;
                $copy['surveyId'] = uid('survey');
                $copy['title'] = ($source['title'] ?? '') . '（複製）';
                $copy['status'] = 'draft';
                $copy['createdAt'] = date('c');
                $copy['updatedAt'] = date('c');

                foreach ($copy['groups'] as &$group) {
                    $group['groupId'] = uid('g');

                    foreach ($group['questions'] as &$question) {
                        $oldQuestionId = $question['questionId'];

                        $question['questionId'] = uid('q');
                        $question['groupId'] = $group['groupId'];

                        foreach ($question['choices'] as &$choice) {
                            $choice['choiceId'] = uid('c');
                        }

                        unset($choice);

                        foreach ($question['branchRules'] as &$rule) {
                            $rule['questionId'] = $question['questionId'];
                        }

                        unset($rule);

                        $question['_oldQuestionId'] = $oldQuestionId;
                    }

                    unset($question);
                }

                unset($group);

                /*
                 * 複製時の分岐先を新しいIDへ張り替える。
                 */
                $questionMap = [];

                foreach ($copy['groups'] as $group) {
                    foreach ($group['questions'] as $question) {
                        if (isset($question['_oldQuestionId'])) {
                            $questionMap[$question['_oldQuestionId']] = $question['questionId'];
                        }
                    }
                }

                foreach ($copy['groups'] as &$group) {
                    foreach ($group['questions'] as &$question) {
                        unset($question['_oldQuestionId']);

                        foreach ($question['branchRules'] as &$rule) {
                            if (isset($questionMap[$rule['nextQuestionId']])) {
                                $rule['nextQuestionId'] =
                                    $questionMap[$rule['nextQuestionId']];
                            }
                        }

                        unset($rule);
                    }

                    unset($question);
                }

                unset($group);

                recalculateSurvey($copy);
                $surveys[] = $copy;

                atomicWrite(FILE_SURVEYS, $surveys);

                jsonResponse(true, $copy);
                break;

            case 'save_response':
                $surveyId = (string)($input['surveyId'] ?? '');
                $token = trim((string)($input['token'] ?? ''));
                $customerId = trim((string)($input['customerId'] ?? ''));
                $answers = $input['answers'] ?? [];

                if (!validId($surveyId)) {
                    fail('INVALID_SURVEY_ID', 'アンケートIDが不正です。');
                }

                $surveys = readJson(FILE_SURVEYS);
                $survey = findSurvey($surveys, $surveyId);

                if (!$survey) {
                    fail('NOT_FOUND', 'アンケートが存在しません.', 404);
                }

                if (normalizeSurveyStatus($survey)) {
                    foreach ($surveys as &$s) {
                        if ($s['surveyId'] === $surveyId) {
                            $s = $survey;
                        }
                    }
                    unset($s);
                    atomicWrite(FILE_SURVEYS, $surveys);
                }

                if (($survey['status'] ?? '') !== 'published') {
                    fail('NOT_AVAILABLE', 'このアンケートは回答できません。');
                }

                if (!is_array($answers)) {
                    fail('INVALID_ANSWERS', '回答データが不正です。');
                }

                $questions = flattenQuestions($survey);

                foreach ($questions as $question) {
                    if (!empty($question['required'])) {
                        $value = $answers[$question['questionId']] ?? null;

                        $empty = $value === null
                            || $value === ''
                            || (is_array($value) && count($value) === 0);

                        if ($empty) {
                            fail(
                                'REQUIRED',
                                ($question['questionNumber'] ?? '') .
                                '「' .
                                ($question['questionText'] ?? '') .
                                '」は必須です。'
                            );
                        }
                    }
                }

                $responses = readJson(FILE_RESPONSES);

                if ($token !== '') {
                    foreach ($responses as $response) {
                        if (
                            ($response['surveyId'] ?? '') === $surveyId &&
                            ($response['token'] ?? '') === $token
                        ) {
                            if (!empty($survey['allowReanswer'])) {
                                continue;
                            }

                            fail('ALREADY_ANSWERED', 'このアンケートは回答済みです。');
                        }
                    }
                }

                $response = [
                    'responseId' => uid('response'),
                    'surveyId' => $surveyId,
                    'token' => $token !== '' ? $token : null,
                    'customerId' => $customerId !== '' ? $customerId : null,
                    'submittedAt' => date('c'),
                    'answers' => $answers,
                ];

                $responses[] = $response;

                atomicWrite(FILE_RESPONSES, $responses);

                if ($customerId !== '') {
                    $customers = readJson(FILE_CUSTOMERS);

                    foreach ($customers as &$customer) {
                        if (($customer['customerId'] ?? '') === $customerId) {
                            $customer['answerStatus'] = '回答済み';
                            break;
                        }
                    }

                    unset($customer);

                    atomicWrite(FILE_CUSTOMERS, $customers);
                }

                jsonResponse(true, $response);
                break;

            case 'send_mail':
                $surveyId = (string)($input['surveyId'] ?? '');
                $customerIds = $input['customerIds'] ?? [];
                $subject = (string)($input['subject'] ?? '');
                $body = (string)($input['body'] ?? '');
                $type = (string)($input['sendType'] ?? '一括送信');

                if (!validId($surveyId)) {
                    fail('INVALID_SURVEY_ID', 'アンケートIDが不正です。');
                }

                if (!is_array($customerIds) || count($customerIds) === 0) {
                    fail('NO_CUSTOMERS', '送信対象顧客を選択してください。');
                }

                $surveys = readJson(FILE_SURVEYS);
                $survey = findSurvey($surveys, $surveyId);

                if (!$survey) {
                    fail('NOT_FOUND', '対象アンケートが存在しません.', 404);
                }

                $settings = readJson(FILE_MAIL);

                if (
                    empty($settings['smtpServer']) ||
                    empty($settings['fromEmail'])
                ) {
                    fail('SMTP_NOT_CONFIGURED', 'SMTP設定が未設定です。');
                }

                $customers = readJson(FILE_CUSTOMERS);
                $history = readJson(FILE_HISTORY);

                $results = [];
                $success = 0;
                $failed = 0;

                foreach ($customerIds as $customerId) {
                    $customer = customerById($customers, (string)$customerId);

                    if (!$customer) {
                        $results[] = [
                            'customerId' => $customerId,
                            'success' => false,
                            'error' => '顧客が存在しません。',
                        ];
                        $failed++;
                        continue;
                    }

                    $token = hash(
                        'sha256',
                        $surveyId . ':' . $customer['customerId']
                    );

                    $url = surveyAnswerUrl($surveyId, $token);

                    $expandedSubject = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [$customer['name'] ?? '', $url],
                        $subject
                    );

                    $expandedBody = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [$customer['name'] ?? '', $url],
                        $body
                    );

                    try {
                        smtpSend(
                            $settings,
                            (string)$customer['email'],
                            $expandedSubject,
                            $expandedBody
                        );

                        $success++;

                        foreach ($customers as &$c) {
                            if ($c['customerId'] === $customer['customerId']) {
                                $c['lastSentAt'] = date('c');
                                $c['sendCount'] = (int)($c['sendCount'] ?? 0) + 1;
                                $c['answerStatus'] = '送信済み / 未回答';
                                break;
                            }
                        }

                        unset($c);

                        $results[] = [
                            'customerId' => $customer['customerId'],
                            'customerName' => $customer['name'],
                            'success' => true,
                            'url' => $url,
                            'subject' => $expandedSubject,
                            'body' => $expandedBody,
                        ];
                    } catch (Throwable $e) {
                        $failed++;

                        $results[] = [
                            'customerId' => $customer['customerId'],
                            'customerName' => $customer['name'] ?? '',
                            'success' => false,
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                atomicWrite(FILE_CUSTOMERS, $customers);

                $history[] = [
                    'historyId' => uid('history'),
                    'surveyId' => $surveyId,
                    'sentAt' => date('c'),
                    'type' => $type,
                    'count' => count($customerIds),
                    'successCount' => $success,
                    'failedCount' => $failed,
                    'subject' => $subject,
                    'executedBy' => '管理画面利用者',
                    'customers' => $results,
                ];

                atomicWrite(FILE_HISTORY, $history);

                jsonResponse(true, [
                    'targetCount' => count($customerIds),
                    'successCount' => $success,
                    'failedCount' => $failed,
                    'sentAt' => date('c'),
                    'results' => $results,
                ]);
                break;

            case 'test_mail':
                $settings = readJson(FILE_MAIL);

                $to = trim((string)($input['to'] ?? ''));

                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    fail('INVALID_EMAIL', 'テスト送信先メールアドレスが不正です。');
                }

                smtpSend(
                    $settings,
                    $to,
                    'アンケート管理システム SMTPテスト',
                    "SMTP接続テストメールです。\n送信日時: " . date('c')
                );

                $settings['status'] = '接続確認済み';
                atomicWrite(FILE_MAIL, $settings);

                jsonResponse(true, [
                    'message' => 'テストメール送信に成功しました。',
                ]);
                break;

            case 'save_mail':
                $current = readJson(FILE_MAIL);

                $new = [
                    'smtpServer' => trim((string)($input['smtpServer'] ?? '')),
                    'smtpPort' => (int)($input['smtpPort'] ?? 587),
                    'encryption' => (string)($input['encryption'] ?? 'tls'),
                    'authentication' => !empty($input['authentication']),
                    'username' => (string)($input['username'] ?? ''),
                    'password' => array_key_exists('password', $input) && $input['password'] !== ''
                        ? (string)$input['password']
                        : (string)($current['password'] ?? ''),
                    'fromEmail' => trim((string)($input['fromEmail'] ?? '')),
                    'fromName' => trim((string)($input['fromName'] ?? '')),
                    'replyTo' => trim((string)($input['replyTo'] ?? '')),
                    'status' => '未確認',
                ];

                atomicWrite(FILE_MAIL, $new);

                jsonResponse(true, mailSettingsSafe($new));
                break;

            case 'save_kintone':
                $current = readJson(FILE_KINTONE);

                $settings = [
                    'subdomain' => trim((string)($input['subdomain'] ?? '')),
                    'appId' => trim((string)($input['appId'] ?? '')),
                    'loginName' => (string)($input['loginName'] ?? ''),
                    'password' => array_key_exists('password', $input) && $input['password'] !== ''
                        ? (string)$input['password']
                        : (string)($current['settings']['password'] ?? ''),
                    'sslVerify' => !empty($input['sslVerify']),
                    'proxy' => trim((string)($input['proxy'] ?? '')),
                ];

                $current['settings'] = $settings;

                atomicWrite(FILE_KINTONE, $current);

                $safe = $current;
                $safe['settings']['password'] = '';
                $safe['settings']['passwordConfigured'] =
                    !empty($settings['password']);

                jsonResponse(true, $safe);
                break;

            case 'kintone_test':
                $data = readJson(FILE_KINTONE);
                kintoneRequest(
                    $data['settings'],
                    '/v1/app.json?id=' . rawurlencode($data['settings']['appId'])
                );

                jsonResponse(true, [
                    'message' => 'kintone接続成功',
                ]);
                break;

            case 'kintone_fields':
                $data = readJson(FILE_KINTONE);

                $result = kintoneGetFields($data['settings']);

                $fields = [];

                foreach (($result['properties'] ?? []) as $code => $field) {
                    $fields[] = [
                        'code' => $code,
                        'label' => $field['label'] ?? $code,
                        'type' => $field['type'] ?? '',
                    ];
                }

                $data['fields'] = $fields;

                atomicWrite(FILE_KINTONE, $data);

                jsonResponse(true, [
                    'fields' => $fields,
                ]);
                break;

            case 'save_kintone_mapping':
                $data = readJson(FILE_KINTONE);

                $data['mapping'] = [
                    'organizationName' => (string)($input['organizationName'] ?? ''),
                    'name' => (string)($input['name'] ?? ''),
                    'email' => (string)($input['email'] ?? ''),
                    'department' => (string)($input['department'] ?? ''),
                    'phone' => (string)($input['phone'] ?? ''),
                    'address' => is_array($input['address'] ?? null)
                        ? $input['address']
                        : [],
                ];

                atomicWrite(FILE_KINTONE, $data);

                jsonResponse(true, $data['mapping']);
                break;

            case 'kintone_sync':
                $data = readJson(FILE_KINTONE);
                $result = kintoneGetRecords($data['settings']);

                $mapping = $data['mapping'];
                $customers = [];

                foreach (($result['records'] ?? []) as $record) {
                    $value = function (string $code) use ($record): string {
                        return (string)($record[$code]['value'] ?? '');
                    };

                    $organization = $value($mapping['organizationName'] ?? '');
                    $name = $value($mapping['name'] ?? '');
                    $email = $value($mapping['email'] ?? '');
                    $department = $value($mapping['department'] ?? '');
                    $phone = $value($mapping['phone'] ?? '');

                    $addressParts = [];

                    foreach (($mapping['address'] ?? []) as $code) {
                        $v = $value((string)$code);
                        if ($v !== '') {
                            $addressParts[] = $v;
                        }
                    }

                    if ($email === '') {
                        continue;
                    }

                    $customers[] = [
                        'customerId' => 'k_' . hash('sha256', $email),
                        'organizationName' => $organization,
                        'name' => $name,
                        'email' => $email,
                        'department' => $department,
                        'phone' => $phone,
                        'address' => [
                            'combined' => implode(' ', $addressParts),
                        ],
                        'lastSentAt' => null,
                        'sendCount' => 0,
                        'answerStatus' => '未送信',
                        'kintoneStatus' => '同期済み',
                    ];
                }

                atomicWrite(FILE_CUSTOMERS, $customers);

                jsonResponse(true, [
                    'message' => '顧客同期完了',
                    'count' => count($customers),
                ]);
                break;

            default:
                fail('UNKNOWN_ACTION', '未知のAPI処理です。');
        }
    } catch (Throwable $e) {
        error_log((string)$e);

        jsonResponse(
            false,
            null,
            'SERVER_ERROR',
            'サーバー処理に失敗しました。詳細: ' . $e->getMessage(),
            500
        );
    }
}

$requestedView = $_GET['view'] ?? 'admin-survey-list';

if (!in_array($requestedView, ALLOWED_VIEWS, true)) {
    $requestedView = 'admin-survey-list';
}

$surveyId = $_GET['surveyId'] ?? '';
$token = $_GET['token'] ?? '';

if ($surveyId !== '' && !validId((string)$surveyId)) {
    $surveyId = '';
}

if ($token !== '' && !preg_match('/^[A-Za-z0-9_-]{1,300}$/', (string)$token)) {
    $token = '';
}

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<style>
* {
    box-sizing: border-box;
}

html, body {
    margin: 0;
    padding: 0;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
    color: #1f2937;
    background: #f5f7fb;
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

a {
    color: inherit;
}

.hidden {
    display: none !important;
}

.app {
    min-height: 100vh;
}

.admin-header {
    background: #172033;
    color: white;
    padding: 14px 24px;
    display: flex;
    align-items: center;
    gap: 28px;
    flex-wrap: wrap;
}

.admin-header .logo {
    font-weight: 700;
    font-size: 18px;
    margin-right: auto;
}

.admin-header button {
    border: 0;
    background: transparent;
    color: white;
    padding: 10px 12px;
    border-radius: 7px;
}

.admin-header button:hover {
    background: rgba(255,255,255,.1);
}

main {
    max-width: 1440px;
    margin: 0 auto;
    padding: 28px;
}

.page-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 22px;
}

.page-title h1 {
    margin: 0;
    font-size: 28px;
}

.card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(15,23,42,.04);
}

.toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

input,
textarea,
select {
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    padding: 9px 11px;
    background: white;
    width: 100%;
}

textarea {
    min-height: 110px;
    resize: vertical;
}

.btn {
    border: 1px solid #cbd5e1;
    background: white;
    color: #334155;
    padding: 9px 15px;
    border-radius: 7px;
    min-height: 42px;
}

.btn:hover {
    background: #f8fafc;
}

.btn.primary {
    background: #2563eb;
    color: white;
    border-color: #2563eb;
}

.btn.danger {
    background: #dc2626;
    color: white;
    border-color: #dc2626;
}

.btn.success {
    background: #059669;
    color: white;
    border-color: #059669;
}

.btn.warning {
    background: #d97706;
    color: white;
    border-color: #d97706;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 950px;
}

th,
td {
    padding: 13px 10px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    vertical-align: middle;
}

th {
    background: #f8fafc;
    white-space: nowrap;
}

.status {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 700;
}

.status.draft {
    color: #475569;
    background: #e2e8f0;
}

.status.published {
    color: #047857;
    background: #d1fae5;
}

.status.stopped {
    color: #92400e;
    background: #fef3c7;
}

.status.finished {
    color: #7f1d1d;
    background: #fee2e2;
}

.grid {
    display: grid;
    gap: 18px;
}

.grid.two {
    grid-template-columns: repeat(2, minmax(0,1fr));
}

.grid.three {
    grid-template-columns: repeat(3, minmax(0,1fr));
}

.field {
    margin-bottom: 16px;
}

.field label {
    display: block;
    font-weight: 700;
    margin-bottom: 7px;
}

.small {
    color: #64748b;
    font-size: 13px;
}

.actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.group {
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    margin-bottom: 18px;
    background: #f8fafc;
}

.group-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    background: #eef2ff;
    border-bottom: 1px solid #cbd5e1;
}

.drag-handle {
    cursor: grab;
    font-size: 18px;
}

.question {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin: 12px;
    padding: 15px;
}

.choice {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
}

.choice input {
    width: auto;
}

.question-meta {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.question-number {
    color: #2563eb;
    font-weight: 700;
}

.preview-shell {
    max-width: 900px;
    margin: auto;
}

.phone-preview {
    max-width: 390px;
    margin: auto;
    border: 10px solid #111827;
    border-radius: 28px;
    padding: 10px;
    background: white;
}

.summary-card {
    text-align: center;
}

.summary-number {
    font-size: 30px;
    font-weight: 800;
    color: #2563eb;
}

.bar-row {
    display: grid;
    grid-template-columns: minmax(120px,180px) 1fr 70px;
    gap: 10px;
    align-items: center;
    margin: 10px 0;
}

.bar {
    height: 20px;
    border-radius: 5px;
    background: #dbeafe;
    overflow: hidden;
}

.bar > span {
    display: block;
    height: 100%;
    background: #2563eb;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.55);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 1000;
}

.modal {
    background: white;
    border-radius: 12px;
    width: min(600px, 100%);
    max-height: 90vh;
    overflow: auto;
    padding: 22px;
}

.modal h2 {
    margin-top: 0;
}

.answer-shell {
    max-width: 760px;
    margin: 0 auto;
    padding: 25px 15px 60px;
}

.answer-card {
    background: white;
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 4px 18px rgba(15,23,42,.07);
}

.answer-question {
    padding: 20px 0;
    border-bottom: 1px solid #e2e8f0;
}

.answer-option {
    display: block;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 15px;
    margin: 9px 0;
}

.answer-option input {
    width: auto;
    margin-right: 8px;
}

.error {
    color: #b91c1c;
    background: #fee2e2;
    padding: 10px 13px;
    border-radius: 7px;
    margin: 10px 0;
}

.success {
    color: #047857;
    background: #d1fae5;
    padding: 10px 13px;
    border-radius: 7px;
    margin: 10px 0;
}

.notice {
    color: #92400e;
    background: #fef3c7;
    padding: 10px 13px;
    border-radius: 7px;
}

@media(max-width: 800px) {
    main {
        padding: 16px;
    }

    .grid.two,
    .grid.three {
        grid-template-columns: 1fr;
    }

    .page-title {
        align-items: flex-start;
        flex-direction: column;
    }

    .admin-header {
        padding: 10px;
        gap: 5px;
    }

    .admin-header .logo {
        width: 100%;
    }

    .bar-row {
        grid-template-columns: 1fr;
    }
}

@media(max-width: 500px) {
    .answer-shell {
        padding: 12px 8px 40px;
    }

    .answer-card {
        padding: 16px;
    }

    .btn {
        width: 100%;
    }

    .actions {
        flex-direction: column;
    }
}
</style>
</head>

<body>

<div id="app" class="app"></div>

<div id="modal-root"></div>

<script>
"use strict";

/* ==========================================================
 * URL STATE
 * ========================================================== */

const allowedViews = <?=
    json_encode(ALLOWED_VIEWS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
?>;

const initialView = <?= json_encode($requestedView, JSON_UNESCAPED_UNICODE) ?>;
const initialSurveyId = <?= json_encode($surveyId, JSON_UNESCAPED_UNICODE) ?>;
const initialToken = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;

const state = {
    currentView: initialView,
    editingSurveyId: null,
    aggregationSurveyId: null,
    sendingSurveyId: null,
    surveyId: initialSurveyId || null,
    answerToken: initialToken || null,

    surveys: [],
    customers: [],
    responses: [],
    history: [],
    kintone: {},
    mail: {},

    selectedCustomerIds: new Set(),

    surveySearch: '',
    surveyFilter: 'all',
    surveySort: 'updated-desc',

    customerSearch: '',
    customerStatus: 'all',

    editSurvey: null,

    answerValues: {},

    aggregationSelection: new Set(),

    lastSendResult: null,
};

function parseUrlState() {
    const params = new URLSearchParams(location.search);

    let view = params.get('view') || 'admin-survey-list';

    if (!allowedViews.includes(view)) {
        view = 'admin-survey-list';
    }

    const surveyId = params.get('surveyId') || null;
    const token = params.get('token') || null;

    state.currentView = view;
    state.surveyId = surveyId;
    state.answerToken = token;

    state.editingSurveyId =
        ['admin-survey-edit', 'admin-preview'].includes(view)
            ? surveyId
            : null;

    state.aggregationSurveyId =
        view === 'admin-aggregation'
            ? surveyId
            : null;

    state.sendingSurveyId =
        view === 'admin-send'
            ? surveyId
            : null;
}

function buildUrl(options) {
    const params = new URLSearchParams();

    params.set('view', options.view);

    if (options.surveyId) {
        params.set('surveyId', options.surveyId);
    }

    if (options.token) {
        params.set('token', options.token);
    }

    return 'index.php?' + params.toString();
}

function navigate(options, replace = false) {
    const url = buildUrl(options);

    if (replace) {
        history.replaceState({}, '', url);
    } else {
        history.pushState({}, '', url);
    }

    parseUrlState();
    render();
}

window.addEventListener('popstate', () => {
    parseUrlState();
    render();
});

/* ==========================================================
 * API
 * ========================================================== */

async function api(action, payload = {}) {
    const fd = new FormData();

    fd.append('action', action);

    for (const [key, value] of Object.entries(payload)) {
        if (value === undefined) continue;

        if (
            typeof value === 'object' &&
            value !== null
        ) {
            fd.append(key, JSON.stringify(value));
        } else {
            fd.append(key, String(value));
        }
    }

    let response;

    try {
        response = await fetch('index.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        });
    } catch (e) {
        throw new Error('通信失敗: ' + e.message);
    }

    const text = await response.text();

    let json;

    try {
        json = JSON.parse(text);
    } catch (e) {
        throw new Error(
            'JSON解析失敗。HTTP ' +
            response.status +
            '\n' +
            text.substring(0, 1000)
        );
    }

    if (!response.ok) {
        throw new Error(
            json?.error?.message ||
            ('HTTPエラー: ' + response.status)
        );
    }

    if (!json.ok) {
        throw new Error(
            (json.error?.code || 'PHP_ERROR') +
            ': ' +
            (json.error?.message || '処理に失敗しました。')
        );
    }

    return json.data;
}

async function loadData() {
    const data = await api('load_data');

    state.surveys = data.surveys || [];
    state.customers = data.customers || [];
    state.responses = data.responses || [];
    state.history = data.history || [];
    state.kintone = data.kintone || {};
    state.mail = data.mail || {};
}

/* ==========================================================
 * HELPERS
 * ========================================================== */

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}

function surveyById(id) {
    return state.surveys.find(s => s.surveyId === id);
}

function statusLabel(status) {
    return {
        draft: '下書き',
        published: '公開中',
        stopped: '停止',
        finished: '終了',
    }[status] || status;
}

function statusHtml(status) {
    return `<span class="status ${escapeHtml(status)}">${escapeHtml(statusLabel(status))}</span>`;
}

function formatDate(value) {
    if (!value) return '-';

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) {
        return value;
    }

    return d.toLocaleString('ja-JP');
}

function responseCount(surveyId) {
    return state.responses.filter(
        r => r.surveyId === surveyId
    ).length;
}

function questionsOf(survey) {
    return (survey?.groups || [])
        .flatMap(g => g.questions || []);
}

function deepClone(value) {
    return JSON.parse(JSON.stringify(value));
}

function notifyError(message) {
    alert(message);
}

function showModal(title, body, onExecute) {
    const root = document.getElementById('modal-root');

    root.innerHTML = `
        <div class="modal-backdrop">
            <div class="modal">
                <h2>${escapeHtml(title)}</h2>
                <div>${body}</div>
                <div class="actions" style="margin-top:20px">
                    <button class="btn" id="modal-cancel">キャンセル</button>
                    <button class="btn primary" id="modal-ok">実行</button>
                </div>
            </div>
        </div>
    `;

    root.querySelector('#modal-cancel').onclick = () => {
        root.innerHTML = '';
    };

    root.querySelector('#modal-ok').onclick = async () => {
        try {
            await onExecute();
            root.innerHTML = '';
        } catch (e) {
            notifyError(e.message);
        }
    };
}

function adminHeader() {
    return `
        <header class="admin-header">
            <div class="logo">アンケート管理システム</div>

            <button onclick="navigate({view:'admin-survey-list'})">
                アンケート一覧
            </button>

            <button onclick="navigate({view:'admin-kintone'})">
                kintone連携設定
            </button>

            <button onclick="navigate({view:'admin-mail'})">
                メールサーバ設定
            </button>

            <button onclick="navigate({view:'admin-survey-list'}, true)">
                ログアウト
            </button>
        </header>
    `;
}

function renderAdmin(content) {
    return `
        ${adminHeader()}
        <main>${content}</main>
    `;
}

/* ==========================================================
 * SURVEY LIST
 * ========================================================== */

function renderSurveyList() {
    let surveys = [...state.surveys];

    const keyword = state.surveySearch.trim().toLowerCase();

    if (keyword) {
        surveys = surveys.filter(s =>
            String(s.title || '').toLowerCase().includes(keyword)
        );
    }

    if (state.surveyFilter !== 'all') {
        surveys = surveys.filter(
            s => s.status === state.surveyFilter
        );
    }

    surveys.sort((a, b) => {
        const av = state.surveySort.includes('answer')
            ? responseCount(a.surveyId)
            : new Date(
                state.surveySort.includes('start')
                    ? a.startDate
                    : a.updatedAt
            ).getTime();

        const bv = state.surveySort.includes('answer')
            ? responseCount(b.surveyId)
            : new Date(
                state.surveySort.includes('start')
                    ? b.startDate
                    : b.updatedAt
            ).getTime();

        return state.surveySort.endsWith('asc')
            ? av - bv
            : bv - av;
    });

    return renderAdmin(`
        <div class="page-title">
            <div>
                <h1>アンケート一覧</h1>
                <div class="small">管理者業務の起点</div>
            </div>

            <button class="btn primary"
                onclick="startNewSurvey()">
                ＋ アンケート作成
            </button>
        </div>

        <div class="card">
            <div class="toolbar">
                <input
                    id="survey-search"
                    placeholder="タイトル検索"
                    value="${escapeHtml(state.surveySearch)}"
                    onkeydown="if(event.key==='Enter')applySurveySearch()"
                >

                <button class="btn" onclick="applySurveySearch()">
                    検索
                </button>

                <select onchange="state.surveyFilter=this.value;render()">
                    <option value="all" ${state.surveyFilter === 'all' ? 'selected' : ''}>すべて</option>
                    <option value="published" ${state.surveyFilter === 'published' ? 'selected' : ''}>公開中</option>
                    <option value="draft" ${state.surveyFilter === 'draft' ? 'selected' : ''}>下書き</option>
                    <option value="stopped" ${state.surveyFilter === 'stopped' ? 'selected' : ''}>停止</option>
                    <option value="finished" ${state.surveyFilter === 'finished' ? 'selected' : ''}>終了</option>
                </select>

                <select onchange="state.surveySort=this.value;render()">
                    <option value="updated-desc" ${state.surveySort === 'updated-desc' ? 'selected' : ''}>更新日 新しい順</option>
                    <option value="updated-asc" ${state.surveySort === 'updated-asc' ? 'selected' : ''}>更新日 古い順</option>
                    <option value="answer-desc" ${state.surveySort === 'answer-desc' ? 'selected' : ''}>回答数 多い順</option>
                    <option value="answer-asc" ${state.surveySort === 'answer-asc' ? 'selected' : ''}>回答数 少ない順</option>
                    <option value="start-desc" ${state.surveySort === 'start-desc' ? 'selected' : ''}>開始日 新しい順</option>
                    <option value="start-asc" ${state.surveySort === 'start-asc' ? 'selected' : ''}>開始日 古い順</option>
                </select>
            </div>

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
                        ${
                            surveys.length
                                ? surveys.map(renderSurveyRow).join('')
                                : `
                                    <tr>
                                        <td colspan="7">
                                            該当するアンケートはありません。
                                        </td>
                                    </tr>
                                `
                        }
                    </tbody>
                </table>
            </div>
        </div>
    `);
}

function renderSurveyRow(survey) {
    return `
        <tr>
            <td>${escapeHtml(formatDate(survey.createdAt))}</td>
            <td>${escapeHtml(formatDate(survey.updatedAt))}</td>
            <td><strong>${escapeHtml(survey.title)}</strong></td>
            <td>
                ${escapeHtml(formatDate(survey.startDate))}
                ～<br>
                ${escapeHtml(formatDate(survey.endDate))}
            </td>
            <td>${statusHtml(survey.status)}</td>
            <td>${responseCount(survey.surveyId)}</td>
            <td>
                <div class="actions">
                    <button class="btn"
                        onclick="navigate({view:'admin-survey-edit',surveyId:'${escapeHtml(survey.surveyId)}'})">
                        確認・編集
                    </button>

                    <button class="btn"
                        onclick="navigate({view:'admin-aggregation',surveyId:'${escapeHtml(survey.surveyId)}'})">
                        集計
                    </button>

                    <button class="btn"
                        onclick="navigate({view:'admin-send',surveyId:'${escapeHtml(survey.surveyId)}'})">
                        送信
                    </button>

                    <button class="btn"
                        onclick="confirmDuplicate('${escapeHtml(survey.surveyId)}')">
                        複製
                    </button>

                    <button class="btn danger"
                        onclick="confirmDelete('${escapeHtml(survey.surveyId)}')">
                        削除
                    </button>
                </div>
            </td>
        </tr>
    `;
}

function applySurveySearch() {
    const input = document.getElementById('survey-search');

    state.surveySearch = input?.value || '';

    render();
}

/* ==========================================================
 * EDITOR
 * ========================================================== */

function newSurveyObject() {
    return {
        surveyId: 'survey_' + crypto.randomUUID().replaceAll('-', ''),
        title: '',
        description: '',
        startDate: '',
        endDate: '',
        numbering: 'all',
        status: 'draft',
        allowReanswer: false,
        createdAt: null,
        updatedAt: null,
        groups: [],
    };
}

function startNewSurvey() {
    state.editSurvey = newSurveyObject();
    navigate({view:'admin-survey-edit'});
}

function getEditorSurvey() {
    if (state.editSurvey) {
        return state.editSurvey;
    }

    if (state.editingSurveyId) {
        const survey = surveyById(state.editingSurveyId);

        if (!survey) {
            navigate({view:'admin-survey-list'}, true);
            return null;
        }

        state.editSurvey = deepClone(survey);
        return state.editSurvey;
    }

    return newSurveyObject();
}

function renderSurveyEdit() {
    const survey = getEditorSurvey();

    if (!survey) return '';

    return renderAdmin(`
        <div class="page-title">
            <div>
                <h1>${survey.createdAt ? 'アンケート編集' : 'アンケート作成'}</h1>
                ${statusHtml(survey.status)}
            </div>

            <div class="actions">
                <button class="btn"
                    onclick="confirmCancelEdit()">
                    キャンセル
                </button>

                <button class="btn primary"
                    onclick="saveSurvey()">
                    保存して一覧へ
                </button>
            </div>
        </div>

        <div class="card">
            <div class="grid two">
                <div class="field">
                    <label>タイトル</label>
                    <input
                        value="${escapeHtml(survey.title)}"
                        oninput="state.editSurvey.title=this.value"
                    >
                </div>

                <div class="field">
                    <label>質問番号採番方式</label>
                    <select onchange="state.editSurvey.numbering=this.value;recalculateClientSurvey();render()">
                        <option value="all" ${survey.numbering === 'all' ? 'selected' : ''}>
                            アンケート全体で通番
                        </option>
                        <option value="group" ${survey.numbering === 'group' ? 'selected' : ''}>
                            グループ毎に採番
                        </option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label>説明</label>
                <textarea
                    oninput="state.editSurvey.description=this.value"
                >${escapeHtml(survey.description)}</textarea>
            </div>

            <div class="grid two">
                <div class="field">
                    <label>開始日時</label>
                    <input
                        type="datetime-local"
                        value="${toLocalInput(survey.startDate)}"
                        onchange="state.editSurvey.startDate=this.value"
                    >
                </div>

                <div class="field">
                    <label>終了日時</label>
                    <input
                        type="datetime-local"
                        value="${toLocalInput(survey.endDate)}"
                        onchange="state.editSurvey.endDate=this.value"
                    >
                </div>
            </div>

            <div class="field">
                <label>
                    <input
                        type="checkbox"
                        ${survey.allowReanswer ? 'checked' : ''}
                        onchange="state.editSurvey.allowReanswer=this.checked"
                        style="width:auto"
                    >
                    再回答を許可する
                </label>
            </div>

            ${
                survey.createdAt
                    ? renderStatusEditor(survey)
                    : ''
            }
        </div>

        <div style="margin-top:20px">
            ${renderGroups(survey)}

            <button
                class="btn primary"
                onclick="addGroup()">
                ＋ グループ追加
            </button>
        </div>
    `);
}

function toLocalInput(value) {
    if (!value) return '';

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) return value;

    const pad = n => String(n).padStart(2,'0');

    return d.getFullYear() + '-' +
        pad(d.getMonth()+1) + '-' +
        pad(d.getDate()) + 'T' +
        pad(d.getHours()) + ':' +
        pad(d.getMinutes());
}

function renderStatusEditor(survey) {
    const options = [];

    if (survey.status === 'draft') {
        options.push(['published','公開中']);
    }

    if (survey.status === 'published') {
        options.push(['stopped','停止']);
    }

    if (survey.status === 'stopped') {
        options.push(['published','公開中']);
    }

    if (!options.length) {
        return `
            <div class="notice">
                終了状態のため状態変更できません。
            </div>
        `;
    }

    return `
        <div class="field">
            <label>状態変更</label>
            <select id="status-change">
                <option value="">変更しない</option>
                ${
                    options.map(([value,label]) =>
                        `<option value="${value}">${label}</option>`
                    ).join('')
                }
            </select>

            <button class="btn warning"
                style="margin-top:8px"
                onclick="changeEditorStatus()">
                状態変更
            </button>
        </div>
    `;
}

function recalculateClientSurvey() {
    const survey = state.editSurvey;

    if (!survey) return;

    let global = 1;

    survey.groups.forEach((group, gi) => {
        group.sortOrder = gi + 1;

        group.questions.forEach((question, qi) => {
            question.groupId = group.groupId;
            question.sortOrder = qi + 1;

            question.questionNumber =
                survey.numbering === 'group'
                    ? `Q${gi+1}-${qi+1}`
                    : `Q${global}`;

            global++;

            question.choices?.forEach((choice, ci) => {
                choice.sortOrder = ci + 1;
            });
        });
    });
}

function renderGroups(survey) {
    return survey.groups.map((group, gi) => `
        <div
            class="group"
            draggable="true"
            ondragstart="dragGroup=${gi}"
            ondragover="event.preventDefault()"
            ondrop="dropGroup(${gi})"
        >
            <div class="group-header">
                <span class="drag-handle">☷</span>

                <input
                    value="${escapeHtml(group.title)}"
                    oninput="state.editSurvey.groups[${gi}].title=this.value"
                >

                <button class="btn danger"
                    onclick="confirmDeleteGroup(${gi})">
                    グループ削除
                </button>
            </div>

            ${group.questions.map((q, qi) =>
                renderQuestion(group, q, gi, qi)
            ).join('')}

            <div style="padding:12px">
                <button class="btn"
                    onclick="addQuestion(${gi})">
                    ＋ 質問追加
                </button>
            </div>
        </div>
    `).join('');
}

let dragGroup = null;

function dropGroup(target) {
    if (dragGroup === null || dragGroup === target) {
        dragGroup = null;
        return;
    }

    const groups = state.editSurvey.groups;
    const [item] = groups.splice(dragGroup,1);

    groups.splice(target,0,item);

    dragGroup = null;

    recalculateClientSurvey();
    render();
}

function renderQuestion(group, question, gi, qi) {
    return `
        <div
            class="question"
            draggable="true"
            ondragstart="dragQuestion={gi:${gi},qi:${qi}}"
            ondragover="event.preventDefault()"
            ondrop="dropQuestion(${gi},${qi})"
        >
            <div class="question-meta">
                <span class="question-number">
                    ${escapeHtml(question.questionNumber)}
                </span>

                <select
                    onchange="changeQuestionType(${gi},${qi},this.value)"
                    style="max-width:180px"
                >
                    <option value="single" ${question.type === 'single' ? 'selected' : ''}>
                        単一選択
                    </option>
                    <option value="multiple" ${question.type === 'multiple' ? 'selected' : ''}>
                        複数選択
                    </option>
                    <option value="text" ${question.type === 'text' ? 'selected' : ''}>
                        自由記述
                    </option>
                </select>

                <label>
                    <input
                        type="checkbox"
                        ${question.required ? 'checked' : ''}
                        onchange="state.editSurvey.groups[${gi}].questions[${qi}].required=this.checked"
                        style="width:auto"
                    >
                    必須
                </label>

                <button class="btn danger"
                    onclick="confirmDeleteQuestion(${gi},${qi})">
                    削除
                </button>
            </div>

            <div class="field">
                <label>質問文</label>
                <textarea
                    oninput="state.editSurvey.groups[${gi}].questions[${qi}].questionText=this.value"
                >${escapeHtml(question.questionText)}</textarea>
            </div>

            ${
                question.type !== 'text'
                    ? renderChoices(question, gi, qi)
                    : ''
            }

            ${
                question.type === 'single'
                    ? renderBranchRules(question, gi, qi)
                    : ''
            }
        </div>
    `;
}

let dragQuestion = null;

function dropQuestion(targetGi, targetQi) {
    if (!dragQuestion) return;

    const fromGi = dragQuestion.gi;
    const fromQi = dragQuestion.qi;

    const sourceGroup = state.editSurvey.groups[fromGi];
    const targetGroup = state.editSurvey.groups[targetGi];

    const [question] = sourceGroup.questions.splice(fromQi,1);

    question.groupId = targetGroup.groupId;

    targetGroup.questions.splice(targetQi,0,question);

    dragQuestion = null;

    recalculateClientSurvey();
    render();
}

function changeQuestionType(gi, qi, type) {
    const question = state.editSurvey.groups[gi].questions[qi];

    question.type = type;

    if (type === 'text') {
        question.choices = [];
        question.branchRules = [];
    }

    if (
        (type === 'single' || type === 'multiple') &&
        question.choices.length === 0
    ) {
        question.choices.push({
            choiceId: crypto.randomUUID(),
            label: '選択肢',
            sortOrder: 1,
        });
    }

    if (type !== 'single') {
        question.branchRules = [];
    }

    render();
}

function renderChoices(question, gi, qi) {
    return `
        <div>
            <strong>選択肢</strong>

            ${
                question.choices.map((choice, ci) => `
                    <div class="choice">
                        <input
                            value="${escapeHtml(choice.label)}"
                            oninput="state.editSurvey.groups[${gi}].questions[${qi}].choices[${ci}].label=this.value"
                        >

                        <button class="btn danger"
                            onclick="deleteChoice(${gi},${qi},${ci})">
                            削除
                        </button>
                    </div>
                `).join('')
            }

            <button class="btn"
                onclick="addChoice(${gi},${qi})">
                ＋ 選択肢
            </button>
        </div>
    `;
}

function renderBranchRules(question, gi, qi) {
    const allQuestions = questionsOf(state.editSurvey);

    return `
        <div style="margin-top:15px">
            <strong>条件分岐</strong>

            ${
                question.choices.map(choice => {
                    const rule =
                        question.branchRules.find(
                            r => r.choiceId === choice.choiceId
                        );

                    return `
                        <div class="grid two" style="margin-top:8px">
                            <div>
                                ${escapeHtml(choice.label)}
                            </div>

                            <select
                                onchange="setBranchRule(${gi},${qi},'${escapeHtml(choice.choiceId)}',this.value)"
                            >
                                <option value="">次の質問を指定しない</option>

                                ${
                                    allQuestions
                                        .filter(
                                            q => q.questionId !== question.questionId
                                        )
                                        .map(q => `
                                            <option
                                                value="${escapeHtml(q.questionId)}"
                                                ${rule?.nextQuestionId === q.questionId ? 'selected' : ''}
                                            >
                                                ${escapeHtml(q.questionNumber)}
                                                ${escapeHtml(q.questionText)}
                                            </option>
                                        `).join('')
                                }
                            </select>
                        </div>
                    `;
                }).join('')
            }
        </div>
    `;
}

function setBranchRule(gi,qi,choiceId,nextQuestionId) {
    const question =
        state.editSurvey.groups[gi].questions[qi];

    question.branchRules =
        question.branchRules.filter(
            r => r.choiceId !== choiceId
        );

    if (nextQuestionId) {
        question.branchRules.push({
            questionId: question.questionId,
            choiceId,
            nextQuestionId,
        });
    }
}

function addGroup() {
    const survey = state.editSurvey;

    survey.groups.push({
        groupId: crypto.randomUUID(),
        title: '新しいグループ',
        sortOrder: survey.groups.length + 1,
        questions: [],
    });

    recalculateClientSurvey();
    render();
}

function confirmDeleteGroup(gi) {
    const group = state.editSurvey.groups[gi];

    const warning = group.questions.length
        ? '<p><strong>このグループには質問があります。</strong>質問も削除されます。</p>'
        : '<p>このグループを削除しますか？</p>';

    showModal('グループ削除', warning, async () => {
        state.editSurvey.groups.splice(gi,1);
        recalculateClientSurvey();
        render();
    });
}

function addQuestion(gi) {
    const group = state.editSurvey.groups[gi];

    group.questions.push({
        questionId: crypto.randomUUID(),
        groupId: group.groupId,
        sortOrder: group.questions.length + 1,
        questionText: '',
        type: 'single',
        required: false,
        choices: [
            {
                choiceId: crypto.randomUUID(),
                label: '選択肢',
                sortOrder: 1,
            },
        ],
        branchRules: [],
        questionNumber: '',
    });

    recalculateClientSurvey();
    render();
}

function confirmDeleteQuestion(gi, qi) {
    showModal(
        '質問削除',
        '<p>この質問を削除しますか？</p>',
        async () => {
            const question =
                state.editSurvey.groups[gi].questions[qi];

            const deletedId = question.questionId;

            state.editSurvey.groups[gi].questions.splice(qi,1);

            for (const group of state.editSurvey.groups) {
                for (const q of group.questions) {
                    q.branchRules =
                        q.branchRules.filter(
                            r =>
                                r.nextQuestionId !== deletedId &&
                                r.questionId !== deletedId
                        );
                }
            }

            recalculateClientSurvey();
            render();
        }
    );
}

function addChoice(gi,qi) {
    const question =
        state.editSurvey.groups[gi].questions[qi];

    question.choices.push({
        choiceId: crypto.randomUUID(),
        label: '選択肢',
        sortOrder: question.choices.length + 1,
    });

    render();
}

function deleteChoice(gi,qi,ci) {
    const question =
        state.editSurvey.groups[gi].questions[qi];

    const choiceId = question.choices[ci].choiceId;

    question.choices.splice(ci,1);

    question.branchRules =
        question.branchRules.filter(
            r => r.choiceId !== choiceId
        );

    render();
}

async function saveSurvey() {
    try {
        recalculateClientSurvey();

        const survey = deepClone(state.editSurvey);

        if (!survey.createdAt) {
            survey.status = 'draft';
        }

        await api('save_survey', {survey});

        state.editSurvey = null;

        await loadData();

        navigate({view:'admin-survey-list'}, true);
    } catch (e) {
        notifyError(e.message);
    }
}

function confirmCancelEdit() {
    showModal(
        '編集内容を破棄',
        '<p>編集内容を破棄しますか？</p>',
        async () => {
            state.editSurvey = null;
            navigate({view:'admin-survey-list'});
        }
    );
}

function changeEditorStatus() {
    const select = document.getElementById('status-change');

    const newStatus = select.value;

    if (!newStatus) return;

    const survey = state.editSurvey;

    const messages = {
        published: 'このアンケートを公開しますか？',
        stopped: 'このアンケートを停止しますか？',
    };

    showModal(
        statusLabel(newStatus),
        `<p>${messages[newStatus]}</p>`,
        async () => {
            await api('change_status', {
                surveyId: survey.surveyId,
                status: newStatus,
            });

            state.editSurvey = null;

            await loadData();

            render();
        }
    );
}

/* ==========================================================
 * PREVIEW
 * ========================================================== */

function renderPreview() {
    const survey = surveyById(state.editingSurveyId);

    if (!survey) {
        navigate({view:'admin-survey-list'}, true);
        return '';
    }

    return renderAdmin(`
        <div class="page-title">
            <div>
                <h1>プレビュー</h1>
                <div class="small">${escapeHtml(survey.title)}</div>
            </div>

            <div class="actions">
                <button class="btn"
                    onclick="navigate({view:'admin-survey-edit',surveyId:'${escapeHtml(survey.surveyId)}'})">
                    編集へ戻る
                </button>

                <button class="btn"
                    onclick="togglePreviewMode()">
                    PC / スマートフォン
                </button>
            </div>
        </div>

        <div id="preview-container" class="preview-shell">
            ${renderPreviewContent(survey)}
        </div>
    `);
}

let previewPhone = false;

function togglePreviewMode() {
    previewPhone = !previewPhone;

    const survey = surveyById(state.editingSurveyId);

    const container = document.getElementById('preview-container');

    if (container) {
        container.innerHTML = previewPhone
            ? `<div class="phone-preview">${renderPreviewContent(survey)}</div>`
            : renderPreviewContent(survey);
    }
}

function renderPreviewContent(survey) {
    return `
        <div class="card">
            <h1>${escapeHtml(survey.title)}</h1>

            <p>${escapeHtml(survey.description)}</p>

            ${questionsOf(survey).map(q => `
                <div class="answer-question">
                    <div class="question-number">
                        ${escapeHtml(q.questionNumber)}
                    </div>

                    <h3>
                        ${escapeHtml(q.questionText)}
                        ${q.required ? '<span style="color:#dc2626"> *</span>' : ''}
                    </h3>

                    ${
                        q.type === 'text'
                            ? '<textarea disabled></textarea>'
                            : q.choices.map(c => `
                                <label class="answer-option">
                                    <input
                                        type="${q.type === 'single' ? 'radio' : 'checkbox'}"
                                        disabled
                                    >
                                    ${escapeHtml(c.label)}
                                </label>
                            `).join('')
                    }
                </div>
            `).join('')}
        </div>
    `;
}

/* ==========================================================
 * CUSTOMER SEND
 * ========================================================== */

function renderSend() {
    const survey = surveyById(state.sendingSurveyId);

    if (!survey) {
        navigate({view:'admin-survey-list'}, true);
        return '';
    }

    let customers = [...state.customers];

    const keyword = state.customerSearch.trim().toLowerCase();

    if (keyword) {
        customers = customers.filter(c =>
            [
                c.name,
                c.organizationName,
                c.email,
                c.answerStatus
            ]
            .join(' ')
            .toLowerCase()
            .includes(keyword)
        );
    }

    if (state.customerStatus !== 'all') {
        customers = customers.filter(
            c => c.answerStatus === state.customerStatus
        );
    }

    return renderAdmin(`
        <div class="page-title">
            <div>
                <h1>顧客選択・メール送信</h1>
                <div class="small">
                    対象アンケート：
                    <strong>${escapeHtml(survey.title)}</strong>
                </div>
            </div>

            <button class="btn"
                onclick="navigate({view:'admin-survey-list'})">
                一覧へ
            </button>
        </div>

        <div class="grid two">
            <div class="card">
                <h2>顧客選択</h2>

                <div class="toolbar">
                    <input
                        placeholder="顧客名・組織名・メール・ステータス"
                        value="${escapeHtml(state.customerSearch)}"
                        oninput="state.customerSearch=this.value;render()"
                        onkeydown="if(event.key==='Enter')render()"
                    >

                    <select onchange="state.customerStatus=this.value;render()">
                        <option value="all">すべて</option>
                        <option value="未送信">未送信</option>
                        <option value="送信済み / 未回答">送信済み / 未回答</option>
                        <option value="回答済み">回答済み</option>
                    </select>

                    <button class="btn"
                        onclick="selectReminderTargets()">
                        未回答を選択
                    </button>
                </div>

                <div class="table-wrap">
                    <table style="min-width:900px">
                        <thead>
                            <tr>
                                <th></th>
                                <th>組織名</th>
                                <th>氏名</th>
                                <th>メール</th>
                                <th>電話</th>
                                <th>最終送信</th>
                                <th>送信回数</th>
                                <th>回答状況</th>
                                <th>kintone</th>
                            </tr>
                        </thead>

                        <tbody>
                            ${
                                customers.map(c => `
                                    <tr>
                                        <td>
                                            <input
                                                type="checkbox"
                                                ${state.selectedCustomerIds.has(c.customerId) ? 'checked' : ''}
                                                onchange="toggleCustomer('${escapeHtml(c.customerId)}',this.checked)"
                                                style="width:auto"
                                            >
                                        </td>
                                        <td>${escapeHtml(c.organizationName)}</td>
                                        <td>${escapeHtml(c.name)}</td>
                                        <td>${escapeHtml(c.email)}</td>
                                        <td>${escapeHtml(c.phone)}</td>
                                        <td>${escapeHtml(formatDate(c.lastSentAt))}</td>
                                        <td>${c.sendCount || 0}</td>
                                        <td>${escapeHtml(c.answerStatus)}</td>
                                        <td>${escapeHtml(c.kintoneStatus)}</td>
                                    </tr>
                                `).join('')
                            }
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:15px">
                    選択件数：
                    <strong>${state.selectedCustomerIds.size}</strong>
                </div>
            </div>

            <div class="card">
                <h2>メール作成</h2>

                <div class="small">
                    利用可能な変数：
                    {顧客名} / {アンケートURL}
                </div>

                <div class="field">
                    <label>件名</label>
                    <input id="mail-subject"
                        value="【アンケートのお願い】{顧客名}様"
                    >
                </div>

                <div class="field">
                    <label>本文</label>
                    <textarea id="mail-body">{顧客名}様

アンケートへのご協力をお願いいたします。

回答URL：
{アンケートURL}

よろしくお願いいたします。</textarea>
                </div>

                <div class="actions">
                    <button class="btn primary"
                        onclick="confirmSend('一括送信')">
                        一括送信
                    </button>

                    <button class="btn warning"
                        onclick="confirmSend('再送')">
                        再送
                    </button>

                    <button class="btn"
                        onclick="confirmSend('リマインド')">
                        リマインド
                    </button>
                </div>

                ${
                    state.lastSendResult
                        ? renderSendResult()
                        : ''
                }
            </div>
        </div>

        <div class="card" style="margin-top:18px">
            <h2>送信履歴</h2>

            ${
                state.history
                    .filter(h => h.surveyId === survey.surveyId)
                    .slice()
                    .reverse()
                    .map(renderHistory)
                    .join('')
                    ||
                    '<div class="small">送信履歴はありません。</div>'
            }
        </div>
    `);
}

function toggleCustomer(id, checked) {
    if (checked) {
        state.selectedCustomerIds.add(id);
    } else {
        state.selectedCustomerIds.delete(id);
    }

    render();
}

function selectReminderTargets() {
    state.selectedCustomerIds.clear();

    state.customers
        .filter(c => c.answerStatus === '送信済み / 未回答')
        .forEach(c => state.selectedCustomerIds.add(c.customerId));

    render();
}

function confirmSend(type) {
    if (state.selectedCustomerIds.size === 0) {
        notifyError('顧客を選択してください。');
        return;
    }

    const survey = surveyById(state.sendingSurveyId);

    const subject =
        document.getElementById('mail-subject')?.value || '';

    const body =
        document.getElementById('mail-body')?.value || '';

    showModal(
        type,
        `<p>${state.selectedCustomerIds.size}件にメールを送信します。</p>
         <p>対象アンケート：${escapeHtml(survey.title)}</p>
         <p>実際にSMTP通信を行います。</p>`,
        async () => {
            const result = await api('send_mail', {
                surveyId: survey.surveyId,
                customerIds: [...state.selectedCustomerIds],
                subject,
                body,
                sendType: type,
            });

            state.lastSendResult = result;

            await loadData();

            render();
        }
    );
}

function renderSendResult() {
    const r = state.lastSendResult;

    return `
        <div class="success">
            送信処理完了
        </div>

        <div class="grid three">
            <div class="summary-card card">
                <div class="summary-number">${r.targetCount}</div>
                対象件数
            </div>

            <div class="summary-card card">
                <div class="summary-number">${r.successCount}</div>
                成功件数
            </div>

            <div class="summary-card card">
                <div class="summary-number">${r.failedCount}</div>
                失敗件数
            </div>
        </div>

        <div style="margin-top:10px">
            送信日時：${escapeHtml(formatDate(r.sentAt))}
        </div>

        <div class="table-wrap" style="margin-top:15px">
            <table>
                <thead>
                    <tr>
                        <th>顧客</th>
                        <th>結果</th>
                        <th>詳細</th>
                    </tr>
                </thead>

                <tbody>
                    ${
                        r.results.map(item => `
                            <tr>
                                <td>${escapeHtml(item.customerName || item.customerId)}</td>
                                <td>
                                    ${
                                        item.success
                                            ? '<span class="success">成功</span>'
                                            : '<span class="error">失敗</span>'
                                    }
                                </td>
                                <td>
                                    ${
                                        item.success
                                            ? escapeHtml(item.subject)
                                            : escapeHtml(item.error)
                                    }
                                </td>
                            </tr>
                        `).join('')
                    }
                </tbody>
            </table>
        </div>
    `;
}

function renderHistory(history) {
    return `
        <details style="margin:10px 0">
            <summary>
                ${escapeHtml(formatDate(history.sentAt))}
                ／
                ${escapeHtml(history.type)}
                ／
                ${history.count}件
                ／成功 ${history.successCount}
                ／失敗 ${history.failedCount}
            </summary>

            <div style="padding:12px">
                <div>
                    件名：
                    ${escapeHtml(history.subject)}
                </div>

                ${
                    (history.customers || []).map(c => `
                        <div class="card" style="margin-top:8px">
                            <strong>${escapeHtml(c.customerName || '')}</strong>

                            ${
                                c.success
                                    ? `
                                        <p>URL：
                                        ${escapeHtml(c.url || '')}</p>

                                        <p>展開後件名：
                                        ${escapeHtml(c.subject || '')}</p>

                                        <pre style="white-space:pre-wrap">${escapeHtml(c.body || '')}</pre>
                                    `
                                    : `
                                        <p class="error">
                                            ${escapeHtml(c.error || '')}
                                        </p>
                                    `
                            }
                        </div>
                    `).join('')
                }
            </div>
        </details>
    `;
}

/* ==========================================================
 * AGGREGATION
 * ========================================================== */

function renderAggregation() {
    const survey = surveyById(state.aggregationSurveyId);

    if (!survey) {
        navigate({view:'admin-survey-list'}, true);
        return '';
    }

    const responses =
        state.responses.filter(r => r.surveyId === survey.surveyId);

    const customers =
        state.customers;

    const sentTargets =
        customers.filter(
            c =>
                c.lastSentAt &&
                c.answerStatus !== '未送信'
        ).length;

    const answered = responses.length;

    const unregistered =
        responses.filter(r => !r.customerId).length;

    const unanswered =
        Math.max(0, sentTargets - answered);

    const rate =
        sentTargets > 0
            ? Math.round(answered / sentTargets * 100)
            : 0;

    if (
        state.aggregationSelection.size === 0
    ) {
        questionsOf(survey).forEach(
            q => state.aggregationSelection.add(q.questionId)
        );
    }

    return renderAdmin(`
        <div class="page-title">
            <div>
                <h1>回答集計・分析</h1>
                <div class="small">
                    対象アンケート：
                    <strong>${escapeHtml(survey.title)}</strong>
                </div>
            </div>

            <div class="actions">
                <button class="btn"
                    onclick="navigate({view:'admin-survey-list'})">
                    一覧へ
                </button>

                <button class="btn"
                    onclick="exportCSV()">
                    CSV出力
                </button>

                <button class="btn"
                    onclick="exportPDF()">
                    PDF出力
                </button>
            </div>
        </div>

        <div class="grid three">
            ${summaryCard('送信対象者数',sentTargets)}
            ${summaryCard('回答数',answered)}
            ${summaryCard('未登録顧客',unregistered)}
            ${summaryCard('未回答数',unanswered)}
            ${summaryCard('回答率',rate + '%')}
        </div>

        <div class="card" style="margin-top:18px">
            <div class="toolbar">
                <button class="btn"
                    onclick="selectAllQuestions()">
                    すべて選択
                </button>

                <button class="btn"
                    onclick="clearQuestions()">
                    すべて解除
                </button>
            </div>

            ${
                questionsOf(survey)
                    .map(q => `
                        <label style="display:block;margin:8px 0">
                            <input
                                type="checkbox"
                                ${state.aggregationSelection.has(q.questionId) ? 'checked' : ''}
                                onchange="toggleAggregationQuestion('${escapeHtml(q.questionId)}',this.checked)"
                                style="width:auto"
                            >
                            ${escapeHtml(q.questionNumber)}
                            ${escapeHtml(q.questionText)}
                        </label>
                    `).join('')
            }
        </div>

        ${
            questionsOf(survey)
                .filter(q =>
                    state.aggregationSelection.has(q.questionId)
                )
                .map(q =>
                    renderQuestionAggregation(q,responses)
                )
                .join('')
        }

        <div class="card" style="margin-top:18px">
            <h2>個別回答</h2>

            ${
                responses.length
                    ? responses.map(r =>
                        renderIndividualResponse(survey,r)
                    ).join('')
                    : '<div class="small">回答はありません。</div>'
            }
        </div>
    `);
}

function summaryCard(label,value) {
    return `
        <div class="card summary-card">
            <div class="summary-number">
                ${escapeHtml(value)}
            </div>
            <div>${escapeHtml(label)}</div>
        </div>
    `;
}

function toggleAggregationQuestion(id, checked) {
    if (checked) {
        state.aggregationSelection.add(id);
    } else {
        state.aggregationSelection.delete(id);
    }

    render();
}

function selectAllQuestions() {
    const survey = surveyById(state.aggregationSurveyId);

    questionsOf(survey).forEach(
        q => state.aggregationSelection.add(q.questionId)
    );

    render();
}

function clearQuestions() {
    state.aggregationSelection.clear();
    render();
}

function renderQuestionAggregation(question,responses) {
    if (question.type === 'text') {
        return `
            <div class="card" style="margin-top:18px">
                <h2>
                    ${escapeHtml(question.questionNumber)}
                    ${escapeHtml(question.questionText)}
                </h2>

                ${
                    responses.map(r => `
                        <div style="padding:12px;border-bottom:1px solid #e2e8f0">
                            <div class="small">
                                ${escapeHtml(r.customerId || '未登録回答者')}
                            </div>

                            <div>
                                ${escapeHtml(
                                    typeof r.answers?.[question.questionId] === 'string'
                                        ? r.answers[question.questionId]
                                        : JSON.stringify(r.answers?.[question.questionId] ?? '')
                                )}
                            </div>
                        </div>
                    `).join('')
                }
            </div>
        `;
    }

    const counts = {};

    question.choices.forEach(
        c => counts[c.choiceId] = 0
    );

    responses.forEach(r => {
        const value =
            r.answers?.[question.questionId];

        const values =
            Array.isArray(value)
                ? value
                : [value];

        values.forEach(v => {
            if (v && counts[v] !== undefined) {
                counts[v]++;
            }
        });
    });

    const total = responses.length;

    return `
        <div class="card" style="margin-top:18px">
            <h2>
                ${escapeHtml(question.questionNumber)}
                ${escapeHtml(question.questionText)}
            </h2>

            ${
                question.choices.map(c => {
                    const count = counts[c.choiceId] || 0;
                    const percentage =
                        total > 0
                            ? Math.round(count / total * 100)
                            : 0;

                    return `
                        <div class="bar-row">
                            <div>${escapeHtml(c.label)}</div>

                            <div class="bar">
                                <span style="width:${percentage}%"></span>
                            </div>

                            <div>
                                ${count}件
                                (${percentage}%)
                            </div>
                        </div>
                    `;
                }).join('')
            }
        </div>
    `;
}

function renderIndividualResponse(survey,response) {
    return `
        <details style="margin-bottom:10px">
            <summary>
                ${escapeHtml(response.customerId || '未登録回答者')}
                ／
                ${escapeHtml(formatDate(response.submittedAt))}
            </summary>

            <div class="card" style="margin-top:8px">
                ${
                    questionsOf(survey).map(q => `
                        <div style="margin-bottom:15px">
                            <strong>
                                ${escapeHtml(q.questionNumber)}
                                ${escapeHtml(q.questionText)}
                            </strong>

                            <div>
                                ${escapeHtml(
                                    answerDisplay(
                                        q,
                                        response.answers?.[q.questionId]
                                    )
                                )}
                            </div>
                        </div>
                    `).join('')
                }
            </div>
        </details>
    `;
}

function answerDisplay(question,value) {
    if (value === undefined || value === null) {
        return '';
    }

    if (Array.isArray(value)) {
        return value.map(v => {
            const c = question.choices.find(
                c => c.choiceId === v
            );

            return c?.label || v;
        }).join(', ');
    }

    if (question.type !== 'text') {
        const c = question.choices.find(
            c => c.choiceId === value
        );

        return c?.label || value;
    }

    return value;
}

function exportCSV() {
    const survey = surveyById(state.aggregationSurveyId);

    const rows = [
        ['回答者ID','質問番号','質問文','回答']
    ];

    state.responses
        .filter(r => r.surveyId === survey.surveyId)
        .forEach(r => {
            questionsOf(survey).forEach(q => {
                rows.push([
                    r.customerId || '未登録',
                    q.questionNumber,
                    q.questionText,
                    answerDisplay(
                        q,
                        r.answers?.[q.questionId]
                    ),
                ]);
            });
        });

    const csv =
        '\ufeff' +
        rows
            .map(row =>
                row
                    .map(v =>
                        '"' +
                        String(v ?? '').replaceAll('"','""') +
                        '"'
                    )
                    .join(',')
            )
            .join('\r\n');

    const blob =
        new Blob([csv], {type:'text/csv;charset=utf-8'});

    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href = url;
    a.download =
        (survey.title || 'survey') + '.csv';

    a.click();

    URL.revokeObjectURL(url);

    alert('CSV出力を実行しました。');
}

function exportPDF() {
    alert(
        'PDF出力操作を実行しました。\n' +
        'このプロトタイプでは実PDF生成は省略しています。'
    );
}

/* ==========================================================
 * KINTONE
 * ========================================================== */

function renderKintone() {
    const k = state.kintone || {};
    const s = k.settings || {};
    const mapping = k.mapping || {};
    const fields = k.fields || [];

    return renderAdmin(`
        <div class="page-title">
            <h1>kintone連携設定</h1>
        </div>

        <div class="card">
            <div class="grid two">
                <div class="field">
                    <label>サブドメイン</label>
                    <input id="k-subdomain"
                        value="${escapeHtml(s.subdomain || '')}"
                        placeholder="xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com"
                    >
                </div>

                <div class="field">
                    <label>顧客管理アプリID</label>
                    <input id="k-appid"
                        value="${escapeHtml(s.appId || '')}"
                    >
                </div>

                <div class="field">
                    <label>ログイン名</label>
                    <input id="k-login"
                        value="${escapeHtml(s.loginName || '')}"
                    >
                </div>

                <div class="field">
                    <label>パスワード</label>
                    <input id="k-password"
                        type="password"
                        placeholder="${s.passwordConfigured ? '設定済み（変更する場合のみ入力）' : ''}"
                    >
                </div>

                <div class="field">
                    <label>SSL証明書検証</label>
                    <select id="k-ssl">
                        <option value="false" ${!s.sslVerify ? 'selected' : ''}>
                            検証しない
                        </option>
                        <option value="true" ${s.sslVerify ? 'selected' : ''}>
                            検証する
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>プロキシ</label>
                    <input id="k-proxy"
                        value="${escapeHtml(s.proxy || '')}"
                        placeholder="proxy.example.local:8080"
                    >
                </div>
            </div>

            <div class="actions">
                <button class="btn primary"
                    onclick="saveKintone()">
                    設定保存
                </button>

                <button class="btn"
                    onclick="kintoneTest()">
                    接続テスト
                </button>

                <button class="btn"
                    onclick="kintoneFields()">
                    項目一覧を再取得
                </button>

                <button class="btn success"
                    onclick="kintoneSync()">
                    顧客情報を同期
                </button>
            </div>

            <div id="kintone-message"></div>
        </div>

        <div class="card" style="margin-top:18px">
            <h2>kintoneフィールド</h2>

            ${
                fields.length
                    ? `
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>フィールドコード</th>
                                        <th>日本語ラベル</th>
                                        <th>タイプ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${
                                        fields.map(f => `
                                            <tr>
                                                <td>${escapeHtml(f.code)}</td>
                                                <td>${escapeHtml(f.label)}</td>
                                                <td>${escapeHtml(f.type)}</td>
                                            </tr>
                                        `).join('')
                                    }
                                </tbody>
                            </table>
                        </div>
                    `
                    : '<div class="small">項目はまだ取得されていません。</div>'
            }
        </div>

        <div class="card" style="margin-top:18px">
            <h2>フィールドマッピング</h2>

            ${mappingSelect('organizationName','組織名',mapping.organizationName,fields)}
            ${mappingSelect('name','氏名',mapping.name,fields)}
            ${mappingSelect('email','メールアドレス',mapping.email,fields)}
            ${mappingSelect('department','部署名',mapping.department,fields)}
            ${mappingSelect('phone','電話番号',mapping.phone,fields)}

            <div class="field">
                <label>住所</label>

                ${
                    fields.map(f => `
                        <label style="display:block">
                            <input
                                type="checkbox"
                                class="address-field"
                                value="${escapeHtml(f.code)}"
                                ${(mapping.address || []).includes(f.code) ? 'checked' : ''}
                                style="width:auto"
                            >
                            ${escapeHtml(f.label)}
                        </label>
                    `).join('')
                }
            </div>

            <button class="btn primary"
                onclick="saveKintoneMapping()">
                マッピング保存
            </button>
        </div>
    `);
}

function mappingSelect(key,label,value,fields) {
    return `
        <div class="field">
            <label>${escapeHtml(label)}</label>
            <select id="map-${key}">
                <option value="">未設定</option>
                ${
                    fields.map(f => `
                        <option
                            value="${escapeHtml(f.code)}"
                            ${value === f.code ? 'selected' : ''}
                        >
                            ${escapeHtml(f.label)}
                            (${escapeHtml(f.code)})
                        </option>
                    `).join('')
                }
            </select>
        </div>
    `;
}

async function saveKintone() {
    try {
        await api('save_kintone', {
            subdomain: document.getElementById('k-subdomain').value,
            appId: document.getElementById('k-appid').value,
            loginName: document.getElementById('k-login').value,
            password: document.getElementById('k-password').value,
            sslVerify: document.getElementById('k-ssl').value === 'true',
            proxy: document.getElementById('k-proxy').value,
        });

        await loadData();

        alert('kintone設定を保存しました。');
        render();
    } catch (e) {
        notifyError(e.message);
    }
}

async function kintoneTest() {
    showKintoneBusy('接続テスト中...');

    try {
        const result = await api('kintone_test');

        showKintoneMessage(result.message, true);
    } catch (e) {
        showKintoneMessage(e.message, false);
    }
}

async function kintoneFields() {
    showKintoneBusy('項目取得中...');

    try {
        const result = await api('kintone_fields');

        await loadData();

        showKintoneMessage(
            `項目取得完了：${result.fields.length}件`,
            true
        );

        render();
    } catch (e) {
        showKintoneMessage(e.message, false);
    }
}

async function kintoneSync() {
    showKintoneBusy('顧客同期中...');

    try {
        const result = await api('kintone_sync');

        await loadData();

        showKintoneMessage(
            result.message + '（' + result.count + '件）',
            true
        );
    } catch (e) {
        showKintoneMessage(e.message, false);
    }
}

function showKintoneBusy(text) {
    const el = document.getElementById('kintone-message');

    if (el) {
        el.innerHTML = `<div class="notice">${escapeHtml(text)}</div>`;
    }
}

function showKintoneMessage(text,success) {
    const el = document.getElementById('kintone-message');

    if (el) {
        el.innerHTML =
            `<div class="${success ? 'success' : 'error'}">
                ${escapeHtml(text)}
            </div>`;
    }
}

async function saveKintoneMapping() {
    try {
        const address = [
            ...document.querySelectorAll('.address-field:checked')
        ].map(el => el.value);

        await api('save_kintone_mapping', {
            organizationName:
                document.getElementById('map-organizationName').value,
            name:
                document.getElementById('map-name').value,
            email:
                document.getElementById('map-email').value,
            department:
                document.getElementById('map-department').value,
            phone:
                document.getElementById('map-phone').value,
            address,
        });

        await loadData();

        alert('マッピングを保存しました。');
        render();
    } catch (e) {
        notifyError(e.message);
    }
}

/* ==========================================================
 * MAIL SETTINGS
 * ========================================================== */

function renderMail() {
    const m = state.mail || {};

    return renderAdmin(`
        <div class="page-title">
            <h1>メールサーバ設定</h1>
        </div>

        <div class="card">
            <div class="grid two">
                <div class="field">
                    <label>SMTPサーバ</label>
                    <input id="m-server"
                        value="${escapeHtml(m.smtpServer || '')}"
                    >
                </div>

                <div class="field">
                    <label>SMTPポート</label>
                    <input id="m-port"
                        type="number"
                        value="${m.smtpPort || 587}"
                    >
                </div>

                <div class="field">
                    <label>暗号化方式</label>
                    <select id="m-encryption">
                        <option value="none" ${m.encryption === 'none' ? 'selected' : ''}>
                            なし
                        </option>
                        <option value="tls" ${m.encryption === 'tls' ? 'selected' : ''}>
                            STARTTLS
                        </option>
                        <option value="ssl" ${m.encryption === 'ssl' ? 'selected' : ''}>
                            SSL/TLS
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>
                        <input
                            id="m-auth"
                            type="checkbox"
                            ${m.authentication !== false ? 'checked' : ''}
                            style="width:auto"
                        >
                        SMTP認証
                    </label>
                </div>

                <div class="field">
                    <label>SMTPユーザー名</label>
                    <input id="m-user"
                        value="${escapeHtml(m.username || '')}"
                    >
                </div>

                <div class="field">
                    <label>SMTPパスワード</label>
                    <input id="m-password"
                        type="password"
                        placeholder="${m.passwordConfigured ? '設定済み（変更する場合のみ入力）' : ''}"
                    >
                </div>

                <div class="field">
                    <label>送信元メールアドレス</label>
                    <input id="m-from"
                        value="${escapeHtml(m.fromEmail || '')}"
                    >
                </div>

                <div class="field">
                    <label>送信元名</label>
                    <input id="m-fromname"
                        value="${escapeHtml(m.fromName || '')}"
                    >
                </div>

                <div class="field">
                    <label>返信先メールアドレス</label>
                    <input id="m-reply"
                        value="${escapeHtml(m.replyTo || '')}"
                    >
                </div>
            </div>

            <div class="notice">
                接続状態：
                ${escapeHtml(m.status || '未設定')}
            </div>

            <div class="actions" style="margin-top:15px">
                <button class="btn primary"
                    onclick="saveMail()">
                    設定保存
                </button>

                <button class="btn"
                    onclick="testMail()">
                    テストメール
                </button>
            </div>
        </div>
    `);
}

async function saveMail() {
    try {
        await api('save_mail', {
            smtpServer:
                document.getElementById('m-server').value,
            smtpPort:
                document.getElementById('m-port').value,
            encryption:
                document.getElementById('m-encryption').value,
            authentication:
                document.getElementById('m-auth').checked,
            username:
                document.getElementById('m-user').value,
            password:
                document.getElementById('m-password').value,
            fromEmail:
                document.getElementById('m-from').value,
            fromName:
                document.getElementById('m-fromname').value,
            replyTo:
                document.getElementById('m-reply').value,
        });

        await loadData();

        alert('メール設定を保存しました。');
        render();
    } catch (e) {
        notifyError(e.message);
    }
}

async function testMail() {
    const to =
        prompt('テスト送信先メールアドレスを入力してください。');

    if (!to) return;

    try {
        const result =
            await api('test_mail',{to});

        await loadData();

        alert(result.message);
        render();
    } catch (e) {
        notifyError(e.message);
    }
}

/* ==========================================================
 * ANSWER
 * ========================================================== */

function getAnswerSurvey() {
    const survey = surveyById(state.surveyId);

    return survey || null;
}

function getAnswerCustomerId() {
    if (!state.answerToken) {
        return null;
    }

    const response =
        state.responses.find(
            r =>
                r.surveyId === state.surveyId &&
                r.token === state.answerToken
        );

    return response?.customerId || null;
}

function getAnsweredResponse() {
    return state.responses.find(
        r =>
            r.surveyId === state.surveyId &&
            state.answerToken &&
            r.token === state.answerToken
    );
}

function renderAnswer() {
    const survey = getAnswerSurvey();

    if (!survey) {
        return renderAnswerError('対象アンケートが存在しません。');
    }

    if (survey.status !== 'published') {
        return renderAnswerError(
            'このアンケートは現在回答できません。'
        );
    }

    const previous = getAnsweredResponse();

    if (previous && !survey.allowReanswer) {
        return renderAnswerError(
            'このアンケートは回答済みです。'
        );
    }

    return `
        <div class="answer-shell">
            <div class="answer-card">
                <h1>${escapeHtml(survey.title)}</h1>

                <p>${escapeHtml(survey.description)}</p>

                ${
                    renderAnswerQuestions(survey)
                }

                <div id="answer-error"></div>

                <div class="actions" style="margin-top:25px">
                    <button class="btn primary"
                        onclick="goConfirm()">
                        回答内容を確認
                    </button>
                </div>
            </div>
        </div>
    `;
}

function renderAnswerQuestions(survey) {
    const questions = questionsOf(survey);

    return questions.map(q => {
        const value = state.answerValues[q.questionId];

        return `
            <div class="answer-question"
                id="question-${escapeHtml(q.questionId)}">

                <div class="question-number">
                    ${escapeHtml(q.questionNumber)}
                </div>

                <h3>
                    ${escapeHtml(q.questionText)}
                    ${q.required ? '<span style="color:#dc2626">*</span>' : ''}
                </h3>

                ${
                    q.type === 'text'
                        ? `
                            <textarea
                                oninput="state.answerValues['${escapeHtml(q.questionId)}']=this.value"
                            >${escapeHtml(value || '')}</textarea>
                        `
                        :
                        q.choices.map(c => {
                            const checked =
                                q.type === 'single'
                                    ? value === c.choiceId
                                    : Array.isArray(value) &&
                                      value.includes(c.choiceId);

                            return `
                                <label class="answer-option">
                                    <input
                                        type="${q.type === 'single' ? 'radio' : 'checkbox'}"
                                        name="q_${escapeHtml(q.questionId)}"
                                        value="${escapeHtml(c.choiceId)}"
                                        ${checked ? 'checked' : ''}
                                        onchange="answerChoiceChanged('${escapeHtml(q.questionId)}','${escapeHtml(c.choiceId)}','${q.type}',this.checked)"
                                    >
                                    ${escapeHtml(c.label)}
                                </label>
                            `;
                        }).join('')
                }
            </div>
        `;
    }).join('');
}

function answerChoiceChanged(questionId,choiceId,type,checked) {
    if (type === 'single') {
        state.answerValues[questionId] = choiceId;
    } else {
        const current =
            Array.isArray(state.answerValues[questionId])
                ? [...state.answerValues[questionId]]
                : [];

        if (checked) {
            if (!current.includes(choiceId)) {
                current.push(choiceId);
            }
        } else {
            const i = current.indexOf(choiceId);

            if (i >= 0) {
                current.splice(i,1);
            }
        }

        state.answerValues[questionId] = current;
    }
}

function validateAnswers() {
    const survey = getAnswerSurvey();

    const missing = [];

    for (const q of questionsOf(survey)) {
        if (!q.required) continue;

        const value =
            state.answerValues[q.questionId];

        const empty =
            value === undefined ||
            value === null ||
            value === '' ||
            (Array.isArray(value) && value.length === 0);

        if (empty) {
            missing.push(q);
        }
    }

    return missing;
}

function goConfirm() {
    const missing = validateAnswers();

    const error =
        document.getElementById('answer-error');

    if (missing.length) {
        if (error) {
            error.innerHTML = `
                <div class="error">
                    必須項目が未回答です：
                    ${missing.map(q =>
                        escapeHtml(q.questionNumber)
                    ).join(', ')}
                </div>
            `;
        }

        document.getElementById(
            'question-' + missing[0].questionId
        )?.scrollIntoView({
            behavior:'smooth',
            block:'center'
        });

        return;
    }

    navigate({
        view:'confirm',
        surveyId:state.surveyId,
        token:state.answerToken,
    });
}

function renderConfirm() {
    const survey = getAnswerSurvey();

    if (!survey) {
        return renderAnswerError('対象アンケートが存在しません。');
    }

    return `
        <div class="answer-shell">
            <div class="answer-card">
                <h1>回答内容確認</h1>

                <p>
                    以下の内容で送信します。
                </p>

                ${
                    questionsOf(survey).map(q => `
                        <div class="answer-question">
                            <div class="question-number">
                                ${escapeHtml(q.questionNumber)}
                            </div>

                            <h3>${escapeHtml(q.questionText)}</h3>

                            <div>
                                ${escapeHtml(
                                    answerDisplay(
                                        q,
                                        state.answerValues[q.questionId]
                                    )
                                )}
                            </div>
                        </div>
                    `).join('')
                }

                <div class="actions" style="margin-top:25px">
                    <button class="btn"
                        onclick="navigate({view:'answer',surveyId:'${escapeHtml(state.surveyId)}',token:'${escapeHtml(state.answerToken || '')}'})">
                        修正する
                    </button>

                    <button class="btn primary"
                        onclick="confirmAnswerSubmit()">
                        回答を送信
                    </button>
                </div>
            </div>
        </div>
    `;
}

function confirmAnswerSubmit() {
    showModal(
        '回答送信確認',
        '<p>回答を送信します。送信後は回答済みとして扱われます。</p>',
        async () => {
            const result =
                await api('save_response', {
                    surveyId:state.surveyId,
                    token:state.answerToken || '',
                    customerId:getAnswerCustomerId() || '',
                    answers:state.answerValues,
                });

            navigate({
                view:'complete',
                surveyId:state.surveyId,
                token:state.answerToken || '',
            });
        }
    );
}

function renderComplete() {
    return `
        <div class="answer-shell">
            <div class="answer-card summary-card">
                <h1>回答ありがとうございました</h1>

                <p>
                    回答を正常に受け付けました。
                </p>

                <div class="success">
                    回答完了
                </div>
            </div>
        </div>
    `;
}

function renderAnswerError(message) {
    return `
        <div class="answer-shell">
            <div class="answer-card">
                <h1>アンケート</h1>

                <div class="error">
                    ${escapeHtml(message)}
                </div>
            </div>
        </div>
    `;
}

/* ==========================================================
 * ADMIN ACTIONS
 * ========================================================== */

function confirmDelete(id) {
    showModal(
        'アンケート削除',
        '<p>このアンケートを削除しますか？関連する回答も削除されます。</p>',
        async () => {
            await api('delete_survey',{surveyId:id});
            await loadData();
            navigate({view:'admin-survey-list'},true);
        }
    );
}

function confirmDuplicate(id) {
    showModal(
        'アンケート複製',
        '<p>このアンケートを複製しますか？複製後は下書きになります。</p>',
        async () => {
            await api('duplicate_survey',{surveyId:id});
            await loadData();
            render();
        }
    );
}

/* ==========================================================
 * RENDER
 * ========================================================== */

function render() {
    parseUrlState();

    const app =
        document.getElementById('app');

    if (
        state.currentView === 'answer' ||
        state.currentView === 'confirm' ||
        state.currentView === 'complete'
    ) {
        if (state.currentView === 'answer') {
            app.innerHTML = renderAnswer();
        } else if (state.currentView === 'confirm') {
            app.innerHTML = renderConfirm();
        } else {
            app.innerHTML = renderComplete();
        }

        return;
    }

    switch (state.currentView) {
        case 'admin-survey-list':
            app.innerHTML = renderSurveyList();
            break;

        case 'admin-survey-edit':
            app.innerHTML = renderSurveyEdit();
            break;

        case 'admin-preview':
            app.innerHTML = renderPreview();
            break;

        case 'admin-send':
            app.innerHTML = renderSend();
            break;

        case 'admin-aggregation':
            app.innerHTML = renderAggregation();
            break;

        case 'admin-kintone':
            app.innerHTML = renderKintone();
            break;

        case 'admin-mail':
            app.innerHTML = renderMail();
            break;

        default:
            navigate(
                {view:'admin-survey-list'},
                true
            );
    }
}

/* ==========================================================
 * STARTUP
 * ========================================================== */

(async function() {
    try {
        await loadData();

        parseUrlState();

        /*
         * 対象IDが必要な画面で存在しない場合は一覧へ戻す。
         */
        if (
            [
                'admin-survey-edit',
                'admin-preview',
                'admin-send',
                'admin-aggregation'
            ].includes(state.currentView) &&
            state.currentView !== 'admin-survey-edit' &&
            !surveyById(state.surveyId)
        ) {
            navigate(
                {view:'admin-survey-list'},
                true
            );
            return;
        }

        if (
            ['answer','confirm','complete']
                .includes(state.currentView) &&
            !surveyById(state.surveyId)
        ) {
            document.getElementById('app').innerHTML =
                renderAnswerError(
                    '指定されたアンケートが存在しません。'
                );

            return;
        }

        render();
    } catch (e) {
        document.getElementById('app').innerHTML = `
            <main>
                <div class="card">
                    <h1>システムを起動できませんでした。</h1>

                    <div class="error">
                        ${escapeHtml(e.message)}
                    </div>
                </div>
            </main>
        `;
    }
})();
</script>

</body>
</html>
<?php
declare(strict_types=1);

/*
 * アンケート管理システム
 * PHP 8.5 / Apache 2.4
 *
 * 画面ルーティング:
 *   index.php?view=...
 *
 * API:
 *   POST index.php
 *   action=...
 *
 * 画面専用パス / Rewrite / REQUEST_URIによるルーティングは使用しない。
 */

const DATA_DIR = __DIR__ . '/data';

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

const JSON_FILES = [
    'surveys'      => DATA_DIR . '/surveys.json',
    'customers'    => DATA_DIR . '/customers.json',
    'responses'    => DATA_DIR . '/responses.json',
    'send_history' => DATA_DIR . '/send_history.json',
    'kintone'      => DATA_DIR . '/kintone.json',
    'mail'         => DATA_DIR . '/mail.json',
];

date_default_timezone_set('Asia/Tokyo');

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function requestJson(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function ensureDataFiles(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }

    $defaults = defaultData();

    foreach (JSON_FILES as $key => $file) {
        if (!file_exists($file)) {
            writeJson($file, $defaults[$key] ?? []);
        }
    }
}

function readJson(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $fp = fopen($file, 'rb');

    if ($fp === false) {
        return [];
    }

    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function writeJson(string $file, array $data): bool
{
    $fp = fopen($file, 'c+');

    if ($fp === false) {
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    ftruncate($fp, 0);
    rewind($fp);

    $result = fwrite(
        $fp,
        json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        )
    );

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $result !== false;
}

function nowIso(): string
{
    return date('c');
}

function id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(6));
}

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function normalizeKintoneSubdomain(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace('#^https?://#i', '', $value);
    $value = trim((string)$value, "/ \t\n\r\0\x0B");

    if (!str_contains($value, '.cybozu.com')) {
        $value .= '.cybozu.com';
    }

    return 'https://' . $value;
}

function calculateSurveyStatus(array $survey): string
{
    $status = $survey['status'] ?? 'draft';

    if ($status !== 'published') {
        return $status;
    }

    $end = $survey['endAt'] ?? '';

    if ($end !== '') {
        $timestamp = strtotime($end);

        if ($timestamp !== false && time() > $timestamp) {
            return 'ended';
        }
    }

    return 'published';
}

function statusLabel(string $status): string
{
    return match ($status) {
        'draft'     => '下書き',
        'published' => '公開中',
        'stopped'   => '停止',
        'ended'     => '終了',
        default     => '不明',
    };
}

function answerStatus(array $response): string
{
    return ($response['submittedAt'] ?? '') !== ''
        ? 'answered'
        : 'unanswered';
}

function findSurvey(array $surveys, string $surveyId): ?array
{
    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') === $surveyId) {
            return $survey;
        }
    }

    return null;
}

function surveyIndex(array $surveys, string $surveyId): int
{
    foreach ($surveys as $i => $survey) {
        if (($survey['id'] ?? '') === $surveyId) {
            return $i;
        }
    }

    return -1;
}

function findCustomer(array $customers, string $customerId): ?array
{
    foreach ($customers as $customer) {
        if (($customer['id'] ?? '') === $customerId) {
            return $customer;
        }
    }

    return null;
}

function renumberSurvey(array &$survey): void
{
    $mode = $survey['questionNumbering'] ?? 'global';

    $global = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        $group['sortOrder'] = $gi + 1;

        foreach ($group['questions'] as $qi => &$question) {
            $question['sortOrder'] = $qi + 1;

            if ($mode === 'group') {
                $question['questionNumber'] =
                    'Q' . ($gi + 1) . '-' . ($qi + 1);
            } else {
                $question['questionNumber'] = 'Q' . $global;
                $global++;
            }

            foreach ($question['choices'] ?? [] as $ci => &$choice) {
                $choice['sortOrder'] = $ci + 1;
            }
            unset($choice);
        }
        unset($question);
    }
    unset($group);
}

function defaultSurvey(
    string $id,
    string $title,
    string $status,
    string $startAt,
    string $endAt
): array {
    $survey = [
        'id' => $id,
        'title' => $title,
        'description' => 'サンプルアンケートです。',
        'startAt' => $startAt,
        'endAt' => $endAt,
        'status' => $status,
        'questionNumbering' => 'global',
        'allowResubmit' => false,
        'createdAt' => nowIso(),
        'updatedAt' => nowIso(),
        'groups' => [
            [
                'id' => id('group'),
                'title' => '基本情報',
                'sortOrder' => 1,
                'questions' => [
                    [
                        'id' => id('question'),
                        'questionNumber' => 'Q1',
                        'questionText' => '今回のサービスに満足していますか？',
                        'type' => 'single',
                        'required' => true,
                        'sortOrder' => 1,
                        'choices' => [
                            [
                                'id' => id('choice'),
                                'label' => '満足',
                                'sortOrder' => 1,
                                'nextQuestionId' => null,
                            ],
                            [
                                'id' => id('choice'),
                                'label' => '普通',
                                'sortOrder' => 2,
                                'nextQuestionId' => null,
                            ],
                            [
                                'id' => id('choice'),
                                'label' => '不満',
                                'sortOrder' => 3,
                                'nextQuestionId' => null,
                            ],
                        ],
                    ],
                    [
                        'id' => id('question'),
                        'questionNumber' => 'Q2',
                        'questionText' => 'ご意見・ご感想を入力してください。',
                        'type' => 'text',
                        'required' => false,
                        'sortOrder' => 2,
                        'choices' => [],
                    ],
                ],
            ],
        ],
    ];

    renumberSurvey($survey);

    return $survey;
}

function defaultData(): array
{
    $now = time();

    $surveys = [
        defaultSurvey(
            'survey_draft',
            '下書きサンプル',
            'draft',
            date('c', $now - 86400),
            date('c', $now + 86400 * 30)
        ),
        defaultSurvey(
            'survey_published',
            '公開中サンプル',
            'published',
            date('c', $now - 86400),
            date('c', $now + 86400 * 30)
        ),
        defaultSurvey(
            'survey_stopped',
            '停止サンプル',
            'stopped',
            date('c', $now - 86400),
            date('c', $now + 86400 * 30)
        ),
        defaultSurvey(
            'survey_ended',
            '終了サンプル',
            'ended',
            date('c', $now - 86400 * 10),
            date('c', $now - 86400)
        ),
        defaultSurvey(
            'survey_draft_past',
            '下書き＋過去日時',
            'draft',
            date('c', $now - 86400 * 5),
            date('c', $now - 86400)
        ),
        defaultSurvey(
            'survey_published_past',
            '公開中＋過去日時',
            'published',
            date('c', $now - 86400 * 5),
            date('c', $now - 86400)
        ),
        defaultSurvey(
            'survey_stopped_past',
            '停止＋過去日時',
            'stopped',
            date('c', $now - 86400 * 5),
            date('c', $now - 86400)
        ),
    ];

    return [
        'surveys' => $surveys,
        'customers' => [
            [
                'id' => 'customer_001',
                'organization' => 'サンプル株式会社',
                'name' => '山田 太郎',
                'email' => 'yamada@example.com',
                'department' => '営業部',
                'phone' => '03-1234-5678',
                'address' => '東京都港区赤坂1-1-1',
                'lastSentAt' => '',
                'sendCount' => 0,
                'status' => 'unsent',
                'kintoneStatus' => '登録済み',
            ],
            [
                'id' => 'customer_002',
                'organization' => 'テスト株式会社',
                'name' => '佐藤 花子',
                'email' => 'sato@example.com',
                'department' => '総務部',
                'phone' => '03-2222-3333',
                'address' => '東京都千代田区1-2-3',
                'lastSentAt' => '',
                'sendCount' => 0,
                'status' => 'unsent',
                'kintoneStatus' => '登録済み',
            ],
            [
                'id' => 'customer_003',
                'organization' => 'サンプル商事',
                'name' => '鈴木 一郎',
                'email' => 'suzuki@example.com',
                'department' => '企画部',
                'phone' => '03-4444-5555',
                'address' => '東京都新宿区4-5-6',
                'lastSentAt' => '',
                'sendCount' => 0,
                'status' => 'unsent',
                'kintoneStatus' => '未登録',
            ],
        ],
        'responses' => [],
        'send_history' => [],
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
            'smtpAuth' => true,
            'username' => '',
            'password' => '',
            'fromEmail' => '',
            'fromName' => '',
            'replyTo' => '',
            'connectionStatus' => '未設定',
        ],
    ];
}

function updateSurveyStatuses(): array
{
    $surveys = readJson(JSON_FILES['surveys']);
    $changed = false;

    foreach ($surveys as &$survey) {
        $before = $survey['status'] ?? 'draft';

        if ($before === 'published') {
            $after = calculateSurveyStatus($survey);

            if ($after !== $before) {
                $survey['status'] = $after;
                $survey['updatedAt'] = nowIso();
                $changed = true;
            }
        }
    }
    unset($survey);

    if ($changed) {
        writeJson(JSON_FILES['surveys'], $surveys);
    }

    return $surveys;
}

function apiSaveSurvey(array $input): never
{
    $surveys = readJson(JSON_FILES['surveys']);
    $survey = $input['survey'] ?? null;

    if (!is_array($survey)) {
        jsonResponse([
            'success' => false,
            'message' => 'アンケートデータが不正です。',
        ], 400);
    }

    $survey['id'] = (string)($survey['id'] ?? '');

    if ($survey['id'] === '') {
        $survey['id'] = id('survey');
        $survey['status'] = 'draft';
        $survey['createdAt'] = nowIso();
    }

    $survey['title'] = trim((string)($survey['title'] ?? ''));

    if ($survey['title'] === '') {
        jsonResponse([
            'success' => false,
            'message' => 'タイトルを入力してください。',
        ], 422);
    }

    $survey['updatedAt'] = nowIso();
    $survey['status'] = $survey['status'] ?? 'draft';
    $survey['groups'] = is_array($survey['groups'] ?? null)
        ? $survey['groups']
        : [];

    renumberSurvey($survey);

    $index = surveyIndex($surveys, $survey['id']);

    if ($index >= 0) {
        $surveys[$index] = $survey;
    } else {
        $surveys[] = $survey;
    }

    if (!writeJson(JSON_FILES['surveys'], $surveys)) {
        jsonResponse([
            'success' => false,
            'message' => '保存に失敗しました。',
        ], 500);
    }

    jsonResponse([
        'success' => true,
        'survey' => $survey,
    ]);
}

function apiSurveyStatus(array $input): never
{
    $surveyId = (string)($input['surveyId'] ?? '');
    $operation = (string)($input['operation'] ?? '');

    $surveys = readJson(JSON_FILES['surveys']);
    $index = surveyIndex($surveys, $surveyId);

    if ($index < 0) {
        jsonResponse([
            'success' => false,
            'message' => 'アンケートが見つかりません。',
        ], 404);
    }

    $current = $surveys[$index]['status'] ?? 'draft';

    $target = match ($operation) {
        'publish' => $current === 'draft' ? 'published' : null,
        'stop'    => $current === 'published' ? 'stopped' : null,
        'resume'  => $current === 'stopped' ? 'published' : null,
        default   => null,
    };

    if ($target === null) {
        jsonResponse([
            'success' => false,
            'message' => '許可されていない状態変更です。',
        ], 422);
    }

    $surveys[$index]['status'] = $target;
    $surveys[$index]['updatedAt'] = nowIso();

    if (!writeJson(JSON_FILES['surveys'], $surveys)) {
        jsonResponse([
            'success' => false,
            'message' => '状態変更に失敗しました。',
        ], 500);
    }

    jsonResponse([
        'success' => true,
        'survey' => $surveys[$index],
    ]);
}

function apiDeleteSurvey(array $input): never
{
    $surveyId = (string)($input['surveyId'] ?? '');
    $surveys = readJson(JSON_FILES['surveys']);

    $index = surveyIndex($surveys, $surveyId);

    if ($index < 0) {
        jsonResponse([
            'success' => false,
            'message' => 'アンケートが見つかりません。',
        ], 404);
    }

    if (($surveys[$index]['status'] ?? '') === 'published') {
        jsonResponse([
            'success' => false,
            'message' => '公開中アンケートは削除できません。',
        ], 422);
    }

    array_splice($surveys, $index, 1);

    writeJson(JSON_FILES['surveys'], $surveys);

    jsonResponse([
        'success' => true,
    ]);
}

function apiDuplicateSurvey(array $input): never
{
    $surveyId = (string)($input['surveyId'] ?? '');
    $surveys = readJson(JSON_FILES['surveys']);

    $survey = findSurvey($surveys, $surveyId);

    if ($survey === null) {
        jsonResponse([
            'success' => false,
            'message' => 'アンケートが見つかりません。',
        ], 404);
    }

    $newId = id('survey');
    $survey['id'] = $newId;
    $survey['title'] = ($survey['title'] ?? '') . '（複製）';
    $survey['status'] = 'draft';
    $survey['createdAt'] = nowIso();
    $survey['updatedAt'] = nowIso();

    foreach ($survey['groups'] as &$group) {
        $group['id'] = id('group');

        foreach ($group['questions'] as &$question) {
            $question['id'] = id('question');

            foreach ($question['choices'] ?? [] as &$choice) {
                $choice['id'] = id('choice');
                $choice['nextQuestionId'] = null;
            }
            unset($choice);
        }
        unset($question);
    }
    unset($group);

    renumberSurvey($survey);
    $surveys[] = $survey;

    writeJson(JSON_FILES['surveys'], $surveys);

    jsonResponse([
        'success' => true,
        'survey' => $survey,
    ]);
}

function apiSaveResponse(array $input): never
{
    $surveyId = (string)($input['surveyId'] ?? '');
    $token = (string)($input['token'] ?? '');

    if ($surveyId === '') {
        jsonResponse([
            'success' => false,
            'message' => 'surveyIdがありません。',
        ], 400);
    }

    $surveys = updateSurveyStatuses();
    $survey = findSurvey($surveys, $surveyId);

    if ($survey === null) {
        jsonResponse([
            'success' => false,
            'message' => 'アンケートが見つかりません。',
        ], 404);
    }

    if (calculateSurveyStatus($survey) !== 'published') {
        jsonResponse([
            'success' => false,
            'message' => '現在回答できるアンケートではありません。',
        ], 422);
    }

    if ($token === '') {
        $token = id('token');
    }

    $responses = readJson(JSON_FILES['responses']);

    foreach ($responses as $response) {
        if (
            ($response['surveyId'] ?? '') === $surveyId &&
            ($response['token'] ?? '') === $token &&
            ($response['submittedAt'] ?? '') !== '' &&
            !($survey['allowResubmit'] ?? false)
        ) {
            jsonResponse([
                'success' => false,
                'message' => 'このアンケートは回答済みです。',
            ], 422);
        }
    }

    $response = [
        'id' => id('response'),
        'surveyId' => $surveyId,
        'token' => $token,
        'customerId' => $input['customerId'] ?? null,
        'respondent' => $input['respondent'] ?? [],
        'answers' => $input['answers'] ?? [],
        'submittedAt' => nowIso(),
    ];

    $responses[] = $response;

    if (!writeJson(JSON_FILES['responses'], $responses)) {
        jsonResponse([
            'success' => false,
            'message' => '回答保存に失敗しました。',
        ], 500);
    }

    jsonResponse([
        'success' => true,
        'response' => $response,
        'token' => $token,
    ]);
}

function apiSaveSendHistory(array $input): never
{
    $history = readJson(JSON_FILES['send_history']);

    $record = [
        'id' => id('send'),
        'surveyId' => (string)($input['surveyId'] ?? ''),
        'sentAt' => nowIso(),
        'type' => (string)($input['type'] ?? 'bulk'),
        'count' => (int)($input['count'] ?? 0),
        'subject' => (string)($input['subject'] ?? ''),
        'executor' => '管理画面',
        'customers' => $input['customers'] ?? [],
        'results' => $input['results'] ?? [],
    ];

    $history[] = $record;

    writeJson(JSON_FILES['send_history'], $history);

    jsonResponse([
        'success' => true,
        'history' => $record,
    ]);
}

function smtpSend(
    array $settings,
    string $to,
    string $subject,
    string $body
): array {
    $server = trim((string)($settings['smtpServer'] ?? ''));
    $port = (int)($settings['smtpPort'] ?? 587);
    $encryption = (string)($settings['encryption'] ?? 'tls');
    $username = (string)($settings['username'] ?? '');
    $password = (string)($settings['password'] ?? '');
    $from = trim((string)($settings['fromEmail'] ?? ''));
    $fromName = trim((string)($settings['fromName'] ?? ''));
    $replyTo = trim((string)($settings['replyTo'] ?? ''));

    if ($server === '' || $from === '') {
        return [
            'success' => false,
            'message' => 'SMTPサーバまたは送信元メールアドレスが未設定です。',
        ];
    }

    /*
     * PHP標準だけではSMTP AUTH/TLSを安定して実装できないため、
     * stream_socket_clientを使用した最小SMTPクライアント。
     */
    $host = $server;

    if ($encryption === 'ssl') {
        $host = 'ssl://' . $server;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $host . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return [
            'success' => false,
            'message' => "SMTP接続失敗: {$errstr} ({$errno})",
        ];
    }

    stream_set_timeout($socket, 15);

    $read = function () use ($socket): string {
        $result = '';

        while (($line = fgets($socket, 515)) !== false) {
            $result .= $line;

            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        return $result;
    };

    $write = function (string $command) use ($socket, $read): array {
        fwrite($socket, $command . "\r\n");
        $response = $read();

        return [
            'code' => (int)substr($response, 0, 3),
            'response' => $response,
        ];
    };

    $greeting = $read();

    if ((int)substr($greeting, 0, 3) !== 220) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'SMTP greetingに失敗しました。',
        ];
    }

    $result = $write('EHLO localhost');

    if ($result['code'] >= 400) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'EHLOに失敗しました。',
        ];
    }

    if ($encryption === 'tls') {
        $tls = $write('STARTTLS');

        if ($tls['code'] !== 220) {
            fclose($socket);

            return [
                'success' => false,
                'message' => 'STARTTLSに失敗しました。',
            ];
        }

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            return [
                'success' => false,
                'message' => 'TLS確立に失敗しました。',
            ];
        }

        $result = $write('EHLO localhost');
    }

    if (($settings['smtpAuth'] ?? true) && $username !== '') {
        $auth = $write('AUTH LOGIN');

        if ($auth['code'] !== 334) {
            fclose($socket);

            return [
                'success' => false,
                'message' => 'SMTP AUTHに失敗しました。',
            ];
        }

        $auth = $write(base64_encode($username));

        if ($auth['code'] !== 334) {
            fclose($socket);

            return [
                'success' => false,
                'message' => 'SMTPユーザー名認証に失敗しました。',
            ];
        }

        $auth = $write(base64_encode($password));

        if ($auth['code'] !== 235) {
            fclose($socket);

            return [
                'success' => false,
                'message' => 'SMTPパスワード認証に失敗しました。',
            ];
        }
    }

    $mailFrom = $write('MAIL FROM:<' . $from . '>');

    if ($mailFrom['code'] >= 400) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'MAIL FROMに失敗しました。',
        ];
    }

    $rcpt = $write('RCPT TO:<' . $to . '>');

    if ($rcpt['code'] >= 400) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'RCPT TOに失敗しました。',
        ];
    }

    $data = $write('DATA');

    if ($data['code'] !== 354) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'DATAに失敗しました。',
        ];
    }

    $encodedSubject = '=?UTF-8?B?' .
        base64_encode($subject) .
        '?=';

    $encodedName = $fromName !== ''
        ? '=?UTF-8?B?' . base64_encode($fromName) . '?='
        : $from;

    $headers = [
        'From: ' . $encodedName . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $message =
        implode("\r\n", $headers) .
        "\r\n\r\n" .
        str_replace("\n.", "\n..", $body) .
        "\r\n.";

    fwrite($socket, $message . "\r\n");

    $sent = $read();

    $write('QUIT');
    fclose($socket);

    if ((int)substr($sent, 0, 3) >= 400) {
        return [
            'success' => false,
            'message' => 'SMTP送信に失敗しました。',
        ];
    }

    return [
        'success' => true,
        'message' => '送信成功',
    ];
}

function apiSendMail(array $input): never
{
    $surveyId = (string)($input['surveyId'] ?? '');
    $customers = is_array($input['customers'] ?? null)
        ? $input['customers']
        : [];

    $subject = (string)($input['subject'] ?? '');
    $body = (string)($input['body'] ?? '');
    $type = (string)($input['type'] ?? 'bulk');

    $mail = readJson(JSON_FILES['mail']);
    $customerData = readJson(JSON_FILES['customers']);
    $surveys = updateSurveyStatuses();

    $survey = findSurvey($surveys, $surveyId);

    if ($survey === null) {
        jsonResponse([
            'success' => false,
            'message' => '対象アンケートが見つかりません。',
        ], 404);
    }

    $results = [];

    foreach ($customers as $customerId) {
        $customer = findCustomer($customerData, (string)$customerId);

        if ($customer === null) {
            $results[] = [
                'customerId' => $customerId,
                'success' => false,
                'message' => '顧客が見つかりません。',
            ];
            continue;
        }

        $email = trim((string)($customer['email'] ?? ''));

        if ($email === '') {
            $results[] = [
                'customerId' => $customerId,
                'success' => false,
                'message' => 'メールアドレス未登録',
            ];
            continue;
        }

        $url = sprintf(
            '%s?view=answer&surveyId=%s&token=%s',
            strtok(
                $_SERVER['REQUEST_SCHEME'] ?? 'http',
                '?'
            ) === 'https' ? 'https://' : 'http://',
            rawurlencode($surveyId),
            rawurlencode($customerId)
        );

        $url .= h('');

        $personalBody = str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [
                (string)($customer['name'] ?? ''),
                $url,
            ],
            $body
        );

        $result = smtpSend(
            $mail,
            $email,
            $subject,
            $personalBody
        );

        $results[] = [
            'customerId' => $customerId,
            'customerName' => $customer['name'] ?? '',
            'email' => $email,
            'success' => $result['success'],
            'message' => $result['message'],
            'body' => $personalBody,
            'url' => $url,
        ];

        if ($result['success']) {
            $index = array_search(
                $customerId,
                array_column($customerData, 'id'),
                true
            );

            if ($index !== false) {
                $customerData[$index]['lastSentAt'] = nowIso();
                $customerData[$index]['sendCount'] =
                    ((int)($customerData[$index]['sendCount'] ?? 0)) + 1;
                $customerData[$index]['status'] = 'unanswered';
            }
        }
    }

    writeJson(JSON_FILES['customers'], $customerData);

    $success = count(array_filter(
        $results,
        static fn(array $r): bool => $r['success'] === true
    ));

    $failed = count($results) - $success;

    $history = readJson(JSON_FILES['send_history']);

    $history[] = [
        'id' => id('send'),
        'surveyId' => $surveyId,
        'sentAt' => nowIso(),
        'type' => $type,
        'count' => count($results),
        'successCount' => $success,
        'failedCount' => $failed,
        'subject' => $subject,
        'executor' => '管理画面',
        'customers' => $results,
    ];

    writeJson(JSON_FILES['send_history'], $history);

    jsonResponse([
        'success' => true,
        'total' => count($results),
        'successCount' => $success,
        'failedCount' => $failed,
        'sentAt' => nowIso(),
        'results' => $results,
    ]);
}

function kintoneRequest(
    array $settings,
    string $method,
    string $endpoint,
    ?array $body = null
): array {
    $base = normalizeKintoneSubdomain(
        (string)($settings['subdomain'] ?? '')
    );

    $appId = (string)($settings['appId'] ?? '');
    $login = (string)($settings['loginName'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($base === '' || $appId === '' || $login === '' || $password === '') {
        return [
            'success' => false,
            'message' => 'kintone接続設定が不足しています。',
            'status' => 0,
        ];
    }

    $url = $base . $endpoint;

    if ($endpoint !== '/v1/preview/app/form/fields.json') {
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator . 'app=' . rawurlencode($appId);
    }

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'success' => false,
            'message' => 'cURL初期化に失敗しました。',
            'status' => 0,
        ];
    }

    $headers = [
        'X-Cybozu-Authorization: ' .
        base64_encode($login . ':' . $password),
        'Content-Type: application/json',
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER =>
            (bool)($settings['sslVerify'] ?? false),
        CURLOPT_SSL_VERIFYHOST =>
            (bool)($settings['sslVerify'] ?? false) ? 2 : 0,
    ];

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        $options[CURLOPT_PROXY] = $proxy;
    }

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
        );
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'message' => 'kintone通信失敗: ' . $error,
            'status' => $status,
        ];
    }

    $decoded = json_decode($response, true);

    if ($status < 200 || $status >= 300) {
        return [
            'success' => false,
            'message' => 'kintone APIエラー: ' .
                ($decoded['message'] ?? $response),
            'status' => $status,
            'data' => $decoded,
        ];
    }

    return [
        'success' => true,
        'message' => 'kintone通信成功',
        'status' => $status,
        'data' => $decoded,
    ];
}

function apiKintoneTest(array $input): never
{
    $settings = $input['settings'] ?? [];

    $result = kintoneRequest(
        $settings,
        'GET',
        '/v1/record.json'
    );

    jsonResponse([
        'success' => $result['success'],
        'message' => $result['success']
            ? '接続成功'
            : '接続失敗: ' . $result['message'],
        'detail' => $result,
    ]);
}

function apiKintoneFields(array $input): never
{
    $settings = $input['settings'] ?? [];

    $result = kintoneRequest(
        $settings,
        'GET',
        '/v1/preview/app/form/fields.json'
    );

    if (!$result['success']) {
        jsonResponse([
            'success' => false,
            'message' => $result['message'],
        ]);
    }

    $fields = [];

    foreach (($result['data']['properties'] ?? []) as $code => $field) {
        $fields[] = [
            'code' => $code,
            'label' => $field['label'] ?? $code,
            'type' => $field['type'] ?? '',
        ];
    }

    $kintone = readJson(JSON_FILES['kintone']);
    $kintone['fields'] = $fields;

    writeJson(JSON_FILES['kintone'], $kintone);

    jsonResponse([
        'success' => true,
        'message' => '項目一覧を取得しました。',
        'fields' => $fields,
    ]);
}

function apiKintoneSave(array $input): never
{
    $kintone = readJson(JSON_FILES['kintone']);

    $settings = $input['settings'] ?? $kintone['settings'] ?? [];
    $mapping = $input['mapping'] ?? $kintone['mapping'] ?? [];

    $settings['subdomain'] =
        (string)($settings['subdomain'] ?? '');
    $settings['appId'] =
        (string)($settings['appId'] ?? '');
    $settings['loginName'] =
        (string)($settings['loginName'] ?? '');
    $settings['password'] =
        (string)($settings['password'] ?? '');
    $settings['sslVerify'] =
        (bool)($settings['sslVerify'] ?? false);
    $settings['proxy'] =
        (string)($settings['proxy'] ?? '');

    $kintone['settings'] = $settings;
    $kintone['mapping'] = $mapping;

    writeJson(JSON_FILES['kintone'], $kintone);

    jsonResponse([
        'success' => true,
        'message' => 'kintone設定を保存しました。',
    ]);
}

function apiKintoneSync(array $input): never
{
    $kintone = readJson(JSON_FILES['kintone']);
    $settings = $input['settings'] ?? $kintone['settings'] ?? [];

    $result = kintoneRequest(
        $settings,
        'GET',
        '/v1/records.json'
    );

    if (!$result['success']) {
        jsonResponse([
            'success' => false,
            'message' => '顧客同期失敗: ' . $result['message'],
        ]);
    }

    $mapping = $kintone['mapping'] ?? [];
    $records = $result['data']['records'] ?? [];

    $customers = readJson(JSON_FILES['customers']);

    $synced = 0;

    foreach ($records as $record) {
        $getValue = static function (string $code) use ($record): string {
            return (string)(
                $record[$code]['value']
                ?? ''
            );
        };

        $emailCode = (string)($mapping['email'] ?? '');
        $email = $getValue($emailCode);

        if ($email === '') {
            continue;
        }

        $index = null;

        foreach ($customers as $i => $customer) {
            if (($customer['email'] ?? '') === $email) {
                $index = $i;
                break;
            }
        }

        $addressValues = [];

        foreach (($mapping['address'] ?? []) as $code) {
            $value = $getValue((string)$code);

            if ($value !== '') {
                $addressValues[] = $value;
            }
        }

        $customer = [
            'id' => $index !== null
                ? $customers[$index]['id']
                : id('customer'),
            'organization' =>
                $getValue((string)($mapping['organization'] ?? '')),
            'name' =>
                $getValue((string)($mapping['name'] ?? '')),
            'email' => $email,
            'department' =>
                $getValue((string)($mapping['department'] ?? '')),
            'phone' =>
                $getValue((string)($mapping['phone'] ?? '')),
            'address' => implode(' ', $addressValues),
            'lastSentAt' =>
                $index !== null
                    ? ($customers[$index]['lastSentAt'] ?? '')
                    : '',
            'sendCount' =>
                $index !== null
                    ? ($customers[$index]['sendCount'] ?? 0)
                    : 0,
            'status' =>
                $index !== null
                    ? ($customers[$index]['status'] ?? 'unsent')
                    : 'unsent',
            'kintoneStatus' => '登録済み',
        ];

        if ($index !== null) {
            $customers[$index] = $customer;
        } else {
            $customers[] = $customer;
        }

        $synced++;
    }

    writeJson(JSON_FILES['customers'], $customers);

    jsonResponse([
        'success' => true,
        'message' => "顧客同期完了: {$synced}件",
        'count' => $synced,
    ]);
}

function apiMailSave(array $input): never
{
    $mail = [
        'smtpServer' =>
            (string)($input['smtpServer'] ?? ''),
        'smtpPort' =>
            (int)($input['smtpPort'] ?? 587),
        'encryption' =>
            (string)($input['encryption'] ?? 'tls'),
        'smtpAuth' =>
            (bool)($input['smtpAuth'] ?? true),
        'username' =>
            (string)($input['username'] ?? ''),
        'password' =>
            (string)($input['password'] ?? ''),
        'fromEmail' =>
            (string)($input['fromEmail'] ?? ''),
        'fromName' =>
            (string)($input['fromName'] ?? ''),
        'replyTo' =>
            (string)($input['replyTo'] ?? ''),
        'connectionStatus' =>
            '未設定',
    ];

    writeJson(JSON_FILES['mail'], $mail);

    jsonResponse([
        'success' => true,
        'message' => 'メール設定を保存しました。',
        'mail' => $mail,
    ]);
}

function apiMailTest(array $input): never
{
    $mail = $input['mail'] ?? [];

    $to = trim((string)($input['to'] ?? $mail['fromEmail'] ?? ''));

    if ($to === '') {
        jsonResponse([
            'success' => false,
            'message' => 'テスト送信先メールアドレスを入力してください。',
        ], 422);
    }

    $result = smtpSend(
        $mail,
        $to,
        'アンケート管理システム SMTPテスト',
        "SMTP接続テストメールです。\n送信日時: " . nowIso()
    );

    if ($result['success']) {
        $mail['connectionStatus'] = '接続確認済み';
    } else {
        $mail['connectionStatus'] = '接続できません';
    }

    writeJson(JSON_FILES['mail'], $mail);

    jsonResponse([
        'success' => $result['success'],
        'message' => $result['success']
            ? 'テストメール送信成功'
            : 'テストメール送信失敗: ' . $result['message'],
    ]);
}

function apiLoadData(array $input): never
{
    $surveys = updateSurveyStatuses();

    jsonResponse([
        'success' => true,
        'surveys' => $surveys,
        'customers' => readJson(JSON_FILES['customers']),
        'responses' => readJson(JSON_FILES['responses']),
        'sendHistory' => readJson(JSON_FILES['send_history']),
        'kintone' => readJson(JSON_FILES['kintone']),
        'mail' => readJson(JSON_FILES['mail']),
    ]);
}

function apiDispatch(string $action, array $input): never
{
    switch ($action) {
        case 'load_data':
            apiLoadData($input);

        case 'save_survey':
            apiSaveSurvey($input);

        case 'survey_status':
            apiSurveyStatus($input);

        case 'delete_survey':
            apiDeleteSurvey($input);

        case 'duplicate_survey':
            apiDuplicateSurvey($input);

        case 'save_response':
            apiSaveResponse($input);

        case 'save_send_history':
            apiSaveSendHistory($input);

        case 'send_mail':
            apiSendMail($input);

        case 'kintone_test':
            apiKintoneTest($input);

        case 'kintone_fields':
            apiKintoneFields($input);

        case 'kintone_save':
            apiKintoneSave($input);

        case 'kintone_sync':
            apiKintoneSync($input);

        case 'mail_save':
            apiMailSave($input);

        case 'mail_test':
            apiMailTest($input);

        default:
            jsonResponse([
                'success' => false,
                'message' => '未知のactionです。',
            ], 400);
    }
}

ensureDataFiles();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)(
        $_POST['action']
        ?? requestJson()['action']
        ?? ''
    ));

    $input = requestJson();

    if (!empty($_POST)) {
        $input = array_merge($input, $_POST);
    }

    apiDispatch($action, $input);
}

$view = trim((string)($_GET['view'] ?? ''));

if (!in_array($view, ALLOWED_VIEWS, true)) {
    $view = 'admin-survey-list';
}

$surveyId = trim((string)($_GET['surveyId'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));

$initialData = [
    'view' => $view,
    'surveyId' => $surveyId,
    'token' => $token,
];
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --danger: #dc2626;
    --success: #16a34a;
    --warning: #d97706;
    --muted: #64748b;
    --border: #dbe2ea;
    --bg: #f5f7fb;
    --card: #fff;
    --text: #172033;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    min-height: 100%;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Hiragino Kaku Gothic ProN",
        "Yu Gothic",
        Meiryo,
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

a {
    color: inherit;
}

.app {
    min-height: 100vh;
}

.admin-shell {
    display: grid;
    grid-template-columns: 240px 1fr;
    min-height: 100vh;
}

.sidebar {
    background: #111827;
    color: #fff;
    padding: 20px 14px;
}

.brand {
    font-size: 19px;
    font-weight: 800;
    padding: 8px 10px 20px;
}

.nav {
    display: grid;
    gap: 6px;
}

.nav button {
    border: 0;
    background: transparent;
    color: #cbd5e1;
    text-align: left;
    border-radius: 8px;
    padding: 11px 12px;
}

.nav button:hover,
.nav button.active {
    color: #fff;
    background: #1f2937;
}

.main {
    min-width: 0;
}

.topbar {
    background: #fff;
    border-bottom: 1px solid var(--border);
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    position: sticky;
    top: 0;
    z-index: 20;
}

.content {
    padding: 24px;
    max-width: 1600px;
    margin: 0 auto;
}

h1 {
    margin: 0;
    font-size: 26px;
}

h2 {
    margin: 0 0 16px;
    font-size: 20px;
}

h3 {
    margin: 0 0 12px;
    font-size: 17px;
}

.page-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 20px;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.btn {
    border: 1px solid var(--border);
    background: #fff;
    border-radius: 8px;
    padding: 9px 14px;
    min-height: 40px;
}

.btn:hover {
    background: #f8fafc;
}

.btn.primary {
    color: #fff;
    background: var(--primary);
    border-color: var(--primary);
}

.btn.primary:hover {
    background: var(--primary-dark);
}

.btn.danger {
    color: #fff;
    background: var(--danger);
    border-color: var(--danger);
}

.btn.success {
    color: #fff;
    background: var(--success);
    border-color: var(--success);
}

.btn.warning {
    color: #fff;
    background: var(--warning);
    border-color: var(--warning);
}

.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 18px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, .03);
}

.grid {
    display: grid;
    gap: 16px;
}

.grid-2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.grid-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.grid-4 {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

label {
    display: block;
    font-weight: 700;
    margin-bottom: 6px;
}

input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
textarea,
select {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    background: #fff;
    color: var(--text);
}

textarea {
    min-height: 120px;
    resize: vertical;
}

.field {
    margin-bottom: 15px;
}

.help {
    color: var(--muted);
    font-size: 13px;
    margin-top: 5px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 850px;
    border-collapse: collapse;
}

th,
td {
    border-bottom: 1px solid var(--border);
    padding: 12px 10px;
    text-align: left;
    vertical-align: middle;
}

th {
    background: #f8fafc;
    font-size: 13px;
    white-space: nowrap;
}

.badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 700;
}

.badge.draft {
    background: #e2e8f0;
    color: #334155;
}

.badge.published {
    background: #dcfce7;
    color: #166534;
}

.badge.stopped {
    background: #fef3c7;
    color: #92400e;
}

.badge.ended {
    background: #fee2e2;
    color: #991b1b;
}

.searchbar {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.searchbar > * {
    flex: 1 1 180px;
}

.filter-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 12px;
}

.filter-tabs button {
    border: 1px solid var(--border);
    background: #fff;
    border-radius: 999px;
    padding: 7px 12px;
}

.filter-tabs button.active {
    color: #fff;
    background: var(--primary);
    border-color: var(--primary);
}

.stat {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 17px;
}

.stat-label {
    color: var(--muted);
    font-size: 13px;
}

.stat-value {
    font-size: 27px;
    font-weight: 800;
    margin-top: 4px;
}

.group-card {
    border: 1px solid var(--border);
    border-radius: 12px;
    background: #fff;
    margin-bottom: 14px;
    overflow: hidden;
}

.group-head {
    background: #f8fafc;
    padding: 13px 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.group-head input {
    flex: 1;
}

.group-body {
    padding: 14px;
}

.question-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 10px;
    background: #fff;
}

.question-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}

.question-number {
    font-weight: 800;
    color: var(--primary);
    min-width: 55px;
}

.question-actions {
    margin-left: auto;
    display: flex;
    gap: 5px;
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

.drag {
    cursor: grab;
    color: var(--muted);
    user-select: none;
}

.empty {
    color: var(--muted);
    text-align: center;
    padding: 30px;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, .55);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100;
    padding: 20px;
}

.modal-backdrop.show {
    display: flex;
}

.modal {
    width: min(650px, 100%);
    max-height: 90vh;
    overflow: auto;
    background: #fff;
    border-radius: 14px;
    padding: 22px;
}

.modal-head {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 15px;
}

.alert {
    border-radius: 9px;
    padding: 11px 13px;
    margin-bottom: 14px;
}

.alert.success {
    background: #dcfce7;
    color: #166534;
}

.alert.error {
    background: #fee2e2;
    color: #991b1b;
}

.alert.info {
    background: #dbeafe;
    color: #1e40af;
}

.answer-shell {
    min-height: 100vh;
    background: #f8fafc;
}

.answer-container {
    width: min(760px, calc(100% - 28px));
    margin: 0 auto;
    padding: 24px 0 50px;
}

.answer-header {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 22px;
    margin-bottom: 14px;
}

.answer-question {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 12px;
}

.answer-question h3 {
    font-size: 17px;
}

.answer-choice {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 14px;
    margin: 8px 0;
    cursor: pointer;
}

.answer-choice:has(input:checked) {
    border-color: var(--primary);
    background: #eff6ff;
}

.answer-choice input {
    width: 20px;
    height: 20px;
    flex: 0 0 auto;
}

.answer-footer {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-top: 16px;
}

.preview-phone {
    width: 390px;
    max-width: 100%;
    min-height: 680px;
    border: 10px solid #111827;
    border-radius: 32px;
    margin: 0 auto;
    overflow: auto;
    background: #f8fafc;
}

.preview-desktop {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
}

.chart {
    display: grid;
    gap: 9px;
}

.bar-row {
    display: grid;
    grid-template-columns: 150px 1fr 70px;
    gap: 10px;
    align-items: center;
}

.bar {
    height: 20px;
    border-radius: 5px;
    background: #e2e8f0;
    overflow: hidden;
}

.bar > span {
    display: block;
    height: 100%;
    background: var(--primary);
}

.history-item {
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 12px;
    margin-bottom: 8px;
}

.toast {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 200;
    padding: 12px 16px;
    background: #111827;
    color: #fff;
    border-radius: 9px;
    display: none;
}

.toast.show {
    display: block;
}

.hidden {
    display: none !important;
}

.mobile-menu {
    display: none;
}

@media (max-width: 1000px) {
    .admin-shell {
        grid-template-columns: 1fr;
    }

    .sidebar {
        display: none;
    }

    .mobile-menu {
        display: inline-flex;
    }

    .grid-4 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {
    .content {
        padding: 14px;
    }

    .topbar {
        padding: 12px 14px;
    }

    .page-head {
        flex-direction: column;
    }

    .grid-2,
    .grid-3,
    .grid-4 {
        grid-template-columns: 1fr;
    }

    .answer-container {
        width: calc(100% - 20px);
        padding-top: 10px;
    }

    .answer-question,
    .answer-header {
        padding: 16px;
    }

    .answer-footer {
        position: sticky;
        bottom: 0;
        background: #f8fafc;
        padding: 10px 0;
    }

    .bar-row {
        grid-template-columns: 90px 1fr 50px;
        font-size: 12px;
    }
}
</style>
</head>

<body>

<div id="app"></div>

<div id="modal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2 id="modal-title">確認</h2>
            <button class="btn" onclick="closeModal()">閉じる</button>
        </div>
        <div id="modal-body"></div>
        <div id="modal-actions" class="actions" style="justify-content:flex-end;margin-top:18px"></div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
"use strict";

/*
 * 重要:
 * currentViewはURLから毎回再構築する。
 * JavaScript状態をURLの代替にはしない。
 */

const INITIAL_URL_STATE = <?=
    json_encode(
        $initialData,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    )
?>;

const ALLOWED_VIEWS = new Set([
    "admin-survey-list",
    "admin-survey-edit",
    "admin-preview",
    "admin-send",
    "admin-aggregation",
    "admin-kintone",
    "admin-mail",
    "answer",
    "confirm",
    "complete"
]);

const state = {
    currentView: "",
    surveyId: "",
    token: "",
    surveys: [],
    customers: [],
    responses: [],
    sendHistory: [],
    kintone: {},
    mail: {},
    filter: "all",
    search: "",
    sort: "updated_desc",
    selectedCustomers: new Set(),
    selectedQuestions: new Set(),
    editSurvey: null,
    answerDraft: {},
    answerRespondent: {},
    answerStep: 0,
    answerVisibleIds: [],
    lastSendResult: null,
    previewMode: "desktop"
};

function urlState() {
    const params = new URLSearchParams(location.search);

    let view = params.get("view") || "admin-survey-list";

    if (!ALLOWED_VIEWS.has(view)) {
        view = "admin-survey-list";
    }

    return {
        view,
        surveyId: params.get("surveyId") || "",
        token: params.get("token") || ""
    };
}

function rebuildStateFromUrl() {
    const url = urlState();

    state.currentView = url.view;
    state.surveyId = url.surveyId;
    state.token = url.token;

    return url;
}

function navigate(view, params = {}, replace = false) {
    const query = new URLSearchParams();

    query.set("view", view);

    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== "") {
            query.set(key, value);
        }
    });

    const url = "index.php?" + query.toString();

    if (replace) {
        history.replaceState({}, "", url);
    } else {
        history.pushState({}, "", url);
    }

    /*
     * URLを更新した後、必ずURLから状態を再構築する。
     */
    rebuildStateFromUrl();
    render();
}

window.addEventListener("popstate", () => {
    /*
     * 戻る・進むでもURLを正として再構築。
     */
    rebuildStateFromUrl();
    render();
});

async function api(action, payload = {}) {
    const response = await fetch("index.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            action,
            ...payload
        })
    });

    const data = await response.json();

    if (!response.ok || data.success === false) {
        throw new Error(data.message || "API処理に失敗しました。");
    }

    return data;
}

async function loadData() {
    const data = await api("load_data");

    state.surveys = data.surveys || [];
    state.customers = data.customers || [];
    state.responses = data.responses || [];
    state.sendHistory = data.sendHistory || [];
    state.kintone = data.kintone || {};
    state.mail = data.mail || {};
}

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function statusLabel(status) {
    return {
        draft: "下書き",
        published: "公開中",
        stopped: "停止",
        ended: "終了"
    }[status] || "不明";
}

function statusClass(status) {
    return status || "draft";
}

function formatDate(value) {
    if (!value) return "-";

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) {
        return value;
    }

    return d.toLocaleString("ja-JP");
}

function surveyById(id) {
    return state.surveys.find(s => s.id === id) || null;
}

function responsesForSurvey(id) {
    return state.responses.filter(r => r.surveyId === id);
}

function answerCount(id) {
    return responsesForSurvey(id)
        .filter(r => r.submittedAt)
        .length;
}

function calculateQuestionNumber(survey) {
    let n = 1;

    survey.groups.forEach((group, gi) => {
        group.questions.forEach((question, qi) => {
            question.sortOrder = qi + 1;

            if (survey.questionNumbering === "group") {
                question.questionNumber =
                    `Q${gi + 1}-${qi + 1}`;
            } else {
                question.questionNumber = `Q${n++}`;
            }

            question.choices ||= [];

            question.choices.forEach((choice, ci) => {
                choice.sortOrder = ci + 1;
            });
        });

        group.sortOrder = gi + 1;
    });
}

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function newChoice() {
    return {
        id: "choice_" + crypto.randomUUID(),
        label: "",
        sortOrder: 1,
        nextQuestionId: null
    };
}

function newQuestion() {
    return {
        id: "question_" + crypto.randomUUID(),
        questionNumber: "",
        questionText: "",
        type: "single",
        required: false,
        sortOrder: 1,
        choices: [
            newChoice()
        ]
    };
}

function newGroup() {
    return {
        id: "group_" + crypto.randomUUID(),
        title: "",
        sortOrder: 1,
        questions: []
    };
}

function emptySurvey() {
    return {
        id: "",
        title: "",
        description: "",
        startAt: "",
        endAt: "",
        status: "draft",
        questionNumbering: "global",
        allowResubmit: false,
        createdAt: "",
        updatedAt: "",
        groups: [
            newGroup()
        ]
    };
}

function notify(message, type = "info") {
    const toast = document.getElementById("toast");

    toast.textContent = message;
    toast.className = "toast show";

    if (type === "error") {
        toast.style.background = "#991b1b";
    } else if (type === "success") {
        toast.style.background = "#166534";
    } else {
        toast.style.background = "#111827";
    }

    setTimeout(() => {
        toast.className = "toast";
    }, 3000);
}

function openModal(title, body, actions = []) {
    document.getElementById("modal-title").textContent = title;
    document.getElementById("modal-body").innerHTML = body;

    const container = document.getElementById("modal-actions");

    container.innerHTML = "";

    actions.forEach(action => {
        const button = document.createElement("button");

        button.className =
            "btn " + (action.className || "");

        button.textContent = action.label;

        button.addEventListener("click", action.onClick);

        container.appendChild(button);
    });

    document.getElementById("modal").classList.add("show");
}

function closeModal() {
    document.getElementById("modal").classList.remove("show");
}

function confirmAction(title, message, callback) {
    openModal(
        title,
        `<p>${escapeHtml(message)}</p>`,
        [
            {
                label: "キャンセル",
                onClick: closeModal
            },
            {
                label: "実行",
                className: "primary",
                onClick: async () => {
                    closeModal();

                    try {
                        await callback();
                    } catch (error) {
                        notify(error.message, "error");
                    }
                }
            }
        ]
    );
}

function adminLayout(title, content) {
    return `
        <div class="admin-shell">
            <aside class="sidebar">
                <div class="brand">アンケート管理</div>

                <nav class="nav">
                    <button
                        class="${state.currentView === "admin-survey-list" ? "active" : ""}"
                        onclick="navigate("admin-survey-list")">
                        アンケート一覧
                    </button>

                    <button
                        class="${state.currentView === "admin-kintone" ? "active" : ""}"
                        onclick="navigate("admin-kintone")">
                        kintone連携
                    </button>

                    <button
                        class="${state.currentView === "admin-mail" ? "active" : ""}"
                        onclick="navigate("admin-mail")">
                        メールサーバ
                    </button>
                </nav>
            </aside>

            <main class="main">
                <header class="topbar">
                    <button class="btn mobile-menu"
                        onclick="navigate("admin-survey-list")">
                        メニュー
                    </button>

                    <strong>${escapeHtml(title)}</strong>

                    <span style="color:#64748b;font-size:13px">
                        認証なしプロトタイプ
                    </span>
                </header>

                <section class="content">
                    ${content}
                </section>
            </main>
        </div>
    `;
}

function render() {
    /*
     * render開始時にもURLを正として再構築。
     */
    rebuildStateFromUrl();

    const app = document.getElementById("app");

    if (state.currentView.startsWith("admin-")) {
        renderAdmin(app);
    } else {
        renderAnswer(app);
    }
}

function renderAdmin(app) {
    switch (state.currentView) {
        case "admin-survey-list":
            app.innerHTML = adminLayout(
                "アンケート一覧",
                renderSurveyList()
            );
            break;

        case "admin-survey-edit":
            app.innerHTML = adminLayout(
                state.surveyId
                    ? "アンケート編集"
                    : "アンケート作成",
                renderSurveyEdit()
            );
            bindSurveyEditor();
            break;

        case "admin-preview":
            app.innerHTML = adminLayout(
                "アンケートプレビュー",
                renderPreview()
            );
            break;

        case "admin-send":
            app.innerHTML = adminLayout(
                "顧客選択・メール送信",
                renderSend()
            );
            bindSend();
            break;

        case "admin-aggregation":
            app.innerHTML = adminLayout(
                "回答集計・分析",
                renderAggregation()
            );
            break;

        case "admin-kintone":
            app.innerHTML = adminLayout(
                "kintone連携設定",
                renderKintone()
            );
            bindKintone();
            break;

        case "admin-mail":
            app.innerHTML = adminLayout(
                "メールサーバ設定",
                renderMail()
            );
            bindMail();
            break;

        default:
            navigate("admin-survey-list", {}, true);
            break;
    }
}

function renderSurveyList() {
    let surveys = [...state.surveys];

    const q = state.search.trim().toLowerCase();

    if (q) {
        surveys = surveys.filter(s =>
            String(s.title || "")
                .toLowerCase()
                .includes(q)
        );
    }

    if (state.filter !== "all") {
        surveys = surveys.filter(
            s => s.status === state.filter
        );
    }

    surveys.sort((a, b) => {
        if (state.sort === "updated_desc") {
            return String(b.updatedAt).localeCompare(
                String(a.updatedAt)
            );
        }

        if (state.sort === "updated_asc") {
            return String(a.updatedAt).localeCompare(
                String(b.updatedAt)
            );
        }

        if (state.sort === "answers_desc") {
            return answerCount(b.id) - answerCount(a.id);
        }

        if (state.sort === "answers_asc") {
            return answerCount(a.id) - answerCount(b.id);
        }

        if (state.sort === "start_desc") {
            return String(b.startAt).localeCompare(
                String(a.startAt)
            );
        }

        return String(a.startAt).localeCompare(
            String(b.startAt)
        );
    });

    return `
        <div class="page-head">
            <div>
                <h1>アンケート一覧</h1>
                <div class="help">
                    管理者業務の起点です。状態変更は一覧から行いません。
                </div>
            </div>

            <button class="btn primary"
                onclick="navigate("admin-survey-edit")">
                ＋ アンケート作成
            </button>
        </div>

        <div class="card">
            <div class="searchbar">
                <input
                    id="survey-search"
                    type="text"
                    placeholder="タイトルを検索"
                    value="${escapeHtml(state.search)}"
                    onkeydown="if(event.key === "Enter") applySurveySearch()">

                <button class="btn"
                    onclick="applySurveySearch()">
                    検索
                </button>

                <select onchange="changeSurveySort(this.value)">
                    <option value="updated_desc"
                        ${state.sort === "updated_desc" ? "selected" : ""}>
                        更新日 新しい順
                    </option>
                    <option value="updated_asc"
                        ${state.sort === "updated_asc" ? "selected" : ""}>
                        更新日 古い順
                    </option>
                    <option value="answers_desc"
                        ${state.sort === "answers_desc" ? "selected" : ""}>
                        回答数 多い順
                    </option>
                    <option value="answers_asc"
                        ${state.sort === "answers_asc" ? "selected" : ""}>
                        回答数 少ない順
                    </option>
                    <option value="start_desc"
                        ${state.sort === "start_desc" ? "selected" : ""}>
                        開始日 新しい順
                    </option>
                    <option value="start_asc"
                        ${state.sort === "start_asc" ? "selected" : ""}>
                        開始日 古い順
                    </option>
                </select>
            </div>

            <div class="filter-tabs">
                ${[
                    ["all", "すべて"],
                    ["published", "公開中"],
                    ["draft", "下書き"],
                    ["stopped", "停止"],
                    ["ended", "終了"]
                ].map(([value, label]) => `
                    <button
                        class="${state.filter === value ? "active" : ""}"
                        onclick="setSurveyFilter("${value}")">
                        ${label}
                    </button>
                `).join("")}
            </div>
        </div>

        <div class="card">
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
                                ? surveys.map(renderSurveyRow).join("")
                                : `
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty">
                                                該当するアンケートはありません。
                                            </div>
                                        </td>
                                    </tr>
                                `
                        }
                    </tbody>
                </table>
            </div>
        </div>
    `;
}

function renderSurveyRow(survey) {
    const status = survey.status;

    return `
        <tr>
            <td>
                <div>${formatDate(survey.createdAt)}</div>
                <div class="help">
                    更新: ${formatDate(survey.updatedAt)}
                </div>
            </td>

            <td>
                <strong>${escapeHtml(survey.title)}</strong>
            </td>

            <td>
                ${formatDate(survey.startAt)}
                ～<br>
                ${formatDate(survey.endAt)}
            </td>

            <td>
                <span class="badge ${statusClass(status)}">
                    ${statusLabel(status)}
                </span>
            </td>

            <td>${answerCount(survey.id)}</td>

            <td>
                <div class="actions">
                    <button class="btn"
                        onclick="navigate(
                            "admin-survey-edit",
                            {surveyId: "${escapeHtml(survey.id)}"}
                        )">
                        確認・編集
                    </button>

                    <button class="btn"
                        onclick="navigate(
                            "admin-aggregation",
                            {surveyId: "${escapeHtml(survey.id)}"}
                        )">
                        集計
                    </button>

                    <button class="btn"
                        onclick="navigate(
                            "admin-send",
                            {surveyId: "${escapeHtml(survey.id)}"}
                        )">
                        送信
                    </button>

                    <button class="btn"
                        onclick="duplicateSurvey("${escapeHtml(survey.id)}")">
                        複製
                    </button>

                    <button class="btn danger"
                        onclick="deleteSurvey("${escapeHtml(survey.id)}")">
                        削除
                    </button>
                </div>
            </td>
        </tr>
    `;
}

function applySurveySearch() {
    const input = document.getElementById("survey-search");

    state.search = input ? input.value : "";

    render();
}

function setSurveyFilter(filter) {
    state.filter = filter;
    render();
}

function changeSurveySort(sort) {
    state.sort = sort;
    render();
}

async function duplicateSurvey(id) {
    confirmAction(
        "アンケート複製",
        "このアンケートを複製しますか？",
        async () => {
            await api("duplicate_survey", {
                surveyId: id
            });

            await loadData();

            notify("アンケートを複製しました。", "success");
            render();
        }
    );
}

async function deleteSurvey(id) {
    confirmAction(
        "アンケート削除",
        "このアンケートを削除します。この操作は元に戻せません。",
        async () => {
            await api("delete_survey", {
                surveyId: id
            });

            await loadData();

            notify("アンケートを削除しました。", "success");
            render();
        }
    );
}

function renderSurveyEdit() {
    let survey;

    if (state.surveyId) {
        const original = surveyById(state.surveyId);

        if (!original) {
            return `
                <div class="card">
                    <div class="alert error">
                        対象アンケートが見つかりません。
                    </div>

                    <button class="btn"
                        onclick="navigate("admin-survey-list")">
                        一覧へ戻る
                    </button>
                </div>
            `;
        }

        if (
            !state.editSurvey ||
            state.editSurvey.id !== state.surveyId
        ) {
            state.editSurvey = clone(original);
        }

        survey = state.editSurvey;
    } else {
        if (!state.editSurvey || state.editSurvey.id) {
            state.editSurvey = emptySurvey();
        }

        survey = state.editSurvey;
    }

    calculateQuestionNumber(survey);

    return `
        <div class="page-head">
            <div>
                <h1>
                    ${state.surveyId ? "アンケート編集" : "アンケート作成"}
                </h1>
            </div>

            <div class="actions">
                <button class="btn"
                    onclick="navigate(
                        "admin-preview",
                        ${state.surveyId
                            ? `{surveyId:"${escapeHtml(state.surveyId)}"}`
                            : "{}"}
                    )">
                    プレビュー
                </button>

                <button class="btn"
                    onclick="cancelSurveyEdit()">
                    キャンセル
                </button>

                <button class="btn primary"
                    onclick="saveSurvey()">
                    保存して一覧へ
                </button>
            </div>
        </div>

        <div class="card">
            <h2>基本情報</h2>

            <div class="grid grid-2">
                <div class="field">
                    <label>タイトル</label>
                    <input
                        id="edit-title"
                        type="text"
                        value="${escapeHtml(survey.title)}">
                </div>

                <div class="field">
                    <label>質問番号の採番方式</label>
                    <select id="edit-numbering"
                        onchange="changeNumbering(this.value)">
                        <option value="global"
                            ${survey.questionNumbering === "global"
                                ? "selected" : ""}>
                            アンケート全体で通番
                        </option>
                        <option value="group"
                            ${survey.questionNumbering === "group"
                                ? "selected" : ""}>
                            グループ毎に採番
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>開始日時</label>
                    <input
                        id="edit-start"
                        type="datetime-local"
                        value="${toDatetimeLocal(survey.startAt)}">
                </div>

                <div class="field">
                    <label>終了日時</label>
                    <input
                        id="edit-end"
                        type="datetime-local"
                        value="${toDatetimeLocal(survey.endAt)}">
                </div>
            </div>

            <div class="field">
                <label>説明</label>
                <textarea id="edit-description">${escapeHtml(
                    survey.description
                )}</textarea>
            </div>

            <label>
                <input
                    id="edit-resubmit"
                    type="checkbox"
                    ${survey.allowResubmit ? "checked" : ""}>
                回答済み顧客の再回答を許可する
            </label>

            ${
                state.surveyId
                    ? `
                        <div class="help" style="margin-top:10px">
                            現在の状態:
                            <strong>
                                ${statusLabel(survey.status)}
                            </strong>
                            （保存操作では状態を変更しません）
                        </div>
                    `
                    : ""
            }
        </div>

        <div class="card">
            <div class="page-head" style="margin-bottom:12px">
                <div>
                    <h2>グループ・質問</h2>
                    <div class="help">
                        ドラッグ＆ドロップでグループ・質問を並び替えできます。
                    </div>
                </div>
            </div>

            <div id="groups-container">
                ${survey.groups.map(
                    (group, gi) => renderGroupEditor(group, gi, survey)
                ).join("")}
            </div>

            <button class="btn primary"
                onclick="addGroup()">
                ＋ グループ追加
            </button>
        </div>

        ${
            state.surveyId
                ? renderStateControls(survey)
                : ""
        }
    `;
}

function toDatetimeLocal(value) {
    if (!value) return "";

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) {
        return "";
    }

    const pad = n => String(n).padStart(2, "0");

    return [
        d.getFullYear(),
        pad(d.getMonth() + 1),
        pad(d.getDate())
    ].join("-") + "T" + [
        pad(d.getHours()),
        pad(d.getMinutes())
    ].join(":");
}

function renderStateControls(survey) {
    let controls = "";

    if (survey.status === "draft") {
        controls += `
            <button class="btn success"
                onclick="changeSurveyStatus(
                    "${escapeHtml(survey.id)}",
                    "publish"
                )">
                公開
            </button>
        `;
    }

    if (survey.status === "published") {
        controls += `
            <button class="btn warning"
                onclick="changeSurveyStatus(
                    "${escapeHtml(survey.id)}",
                    "stop"
                )">
                停止
            </button>
        `;
    }

    if (survey.status === "stopped") {
        controls += `
            <button class="btn success"
                onclick="changeSurveyStatus(
                    "${escapeHtml(survey.id)}",
                    "resume"
                )">
                再開
            </button>
        `;
    }

    if (!controls) {
        return "";
    }

    return `
        <div class="card">
            <h2>状態変更</h2>
            <div class="actions">
                ${controls}
            </div>
        </div>
    `;
}

function renderGroupEditor(group, gi, survey) {
    return `
        <div
            class="group-card"
            draggable="true"
            data-group-id="${escapeHtml(group.id)}"
            ondragstart="dragGroupStart(event)"
            ondragover="event.preventDefault()"
            ondrop="dropGroup(event)">

            <div class="group-head">
                <span class="drag">☷</span>

                <strong>グループ ${gi + 1}</strong>

                <input
                    type="text"
                    value="${escapeHtml(group.title)}"
                    placeholder="グループタイトル"
                    onchange="updateGroupTitle(
                        "${escapeHtml(group.id)}",
                        this.value
                    )">

                <button class="btn danger"
                    onclick="deleteGroup("${escapeHtml(group.id)}")">
                    削除
                </button>
            </div>

            <div class="group-body">
                ${
                    group.questions.length
                        ? group.questions.map(
                            (question, qi) =>
                                renderQuestionEditor(
                                    question,
                                    group,
                                    qi,
                                    survey
                                )
                        ).join("")
                        : `
                            <div class="empty">
                                質問がありません。
                            </div>
                        `
                }

                <button class="btn"
                    onclick="addQuestion("${escapeHtml(group.id)}")">
                    ＋ 質問追加
                </button>
            </div>
        </div>
    `;
}

function renderQuestionEditor(question, group, qi, survey) {
    const allQuestions = survey.groups.flatMap(
        g => g.questions
    );

    const choicesHtml =
        question.type === "text"
            ? ""
            : `
                <div class="field">
                    <label>選択肢</label>

                    ${
                        question.choices.map((choice, ci) => `
                            <div class="choice-row">
                                <span class="drag">☷</span>

                                <input
                                    type="text"
                                    value="${escapeHtml(choice.label)}"
                                    placeholder="選択肢"
                                    onchange="updateChoiceLabel(
                                        "${escapeHtml(group.id)}",
                                        "${escapeHtml(question.id)}",
                                        "${escapeHtml(choice.id)}",
                                        this.value
                                    )">

                                ${
                                    question.type === "single"
                                        ? `
                                            <select
                                                title="条件分岐"
                                                onchange="updateNextQuestion(
                                                    "${escapeHtml(group.id)}",
                                                    "${escapeHtml(question.id)}",
                                                    "${escapeHtml(choice.id)}",
                                                    this.value
                                                )">
                                                <option value="">
                                                    分岐なし
                                                </option>

                                                ${allQuestions
                                                    .filter(
                                                        q =>
                                                            q.id !== question.id
                                                    )
                                                    .map(q => `
                                                        <option
                                                            value="${escapeHtml(q.id)}"
                                                            ${choice.nextQuestionId === q.id
                                                                ? "selected" : ""}>
                                                            ${escapeHtml(q.questionNumber)}
                                                            ${escapeHtml(q.questionText)}
                                                        </option>
                                                    `).join("")}
                                            </select>
                                        `
                                        : ""
                                }

                                <button class="btn"
                                    onclick="deleteChoice(
                                        "${escapeHtml(group.id)}",
                                        "${escapeHtml(question.id)}",
                                        "${escapeHtml(choice.id)}"
                                    )">
                                    ×
                                </button>
                            </div>
                        `).join("")
                    }

                    <button class="btn"
                        onclick="addChoice(
                            "${escapeHtml(group.id)}",
                            "${escapeHtml(question.id)}"
                        )">
                        ＋ 選択肢追加
                    </button>
                </div>
            `;

    return `
        <div
            class="question-card"
            draggable="true"
            data-question-id="${escapeHtml(question.id)}"
            data-group-id="${escapeHtml(group.id)}"
            ondragstart="dragQuestionStart(event)"
            ondragover="event.preventDefault()"
            ondrop="dropQuestion(event)">

            <div class="question-head">
                <span class="drag">☷</span>

                <span class="question-number">
                    ${escapeHtml(question.questionNumber)}
                </span>

                <strong>質問 ${qi + 1}</strong>

                <div class="question-actions">
                    <button class="btn"
                        onclick="moveQuestionDialog(
                            "${escapeHtml(group.id)}",
                            "${escapeHtml(question.id)}"
                        )">
                        移動
                    </button>

                    <button class="btn danger"
                        onclick="deleteQuestion(
                            "${escapeHtml(group.id)}",
                            "${escapeHtml(question.id)}"
                        )">
                        削除
                    </button>
                </div>
            </div>

            <div class="field">
                <label>質問文</label>
                <textarea
                    onchange="updateQuestionText(
                        "${escapeHtml(group.id)}",
                        "${escapeHtml(question.id)}",
                        this.value
                    )">${escapeHtml(question.questionText)}</textarea>
            </div>

            <div class="grid grid-2">
                <div class="field">
                    <label>回答形式</label>
                    <select
                        onchange="updateQuestionType(
                            "${escapeHtml(group.id)}",
                            "${escapeHtml(question.id)}",
                            this.value
                        )">
                        <option value="single"
                            ${question.type === "single" ? "selected" : ""}>
                            単一選択
                        </option>
                        <option value="multiple"
                            ${question.type === "multiple" ? "selected" : ""}>
                            複数選択
                        </option>
                        <option value="text"
                            ${question.type === "text" ? "selected" : ""}>
                            自由記述
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>必須設定</label>
                    <label style="font-weight:400">
                        <input
                            type="checkbox"
                            ${question.required ? "checked" : ""}
                            onchange="updateQuestionRequired(
                                "${escapeHtml(group.id)}",
                                "${escapeHtml(question.id)}",
                                this.checked
                            )">
                        必須回答
                    </label>
                </div>
            </div>

            ${choicesHtml}
        </div>
    `;
}

function bindSurveyEditor() {
    /*
     * イベントは各要素のinline handlerで処理。
     * 再描画しても状態はstate.editSurveyに保持される。
     */
}

function changeNumbering(value) {
    state.editSurvey.questionNumbering = value;
    calculateQuestionNumber(state.editSurvey);
    render();
}

function updateGroupTitle(groupId, value) {
    const group = state.editSurvey.groups.find(
        g => g.id === groupId
    );

    if (group) {
        group.title = value;
    }
}

function addGroup() {
    state.editSurvey.groups.push(newGroup());
    calculateQuestionNumber(state.editSurvey);
    render();
}

function deleteGroup(groupId) {
    const group = state.editSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    const message = group.questions.length
        ? "質問が存在するグループです。削除しますか？"
        : "このグループを削除しますか？";

    confirmAction(
        "グループ削除",
        message,
        async () => {
            state.editSurvey.groups =
                state.editSurvey.groups.filter(
                    g => g.id !== groupId
                );

            calculateQuestionNumber(state.editSurvey);
            render();
        }
    );
}

function addQuestion(groupId) {
    const group = state.editSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    const question = newQuestion();

    question.sortOrder = group.questions.length + 1;

    group.questions.push(question);

    calculateQuestionNumber(state.editSurvey);

    render();
}

function findQuestion(groupId, questionId) {
    const group = state.editSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return null;

    return group.questions.find(
        q => q.id === questionId
    ) || null;
}

function deleteQuestion(groupId, questionId) {
    confirmAction(
        "質問削除",
        "この質問を削除しますか？",
        async () => {
            const group = state.editSurvey.groups.find(
                g => g.id === groupId
            );

            if (!group) return;

            group.questions =
                group.questions.filter(
                    q => q.id !== questionId
                );

            /*
             * 削除された質問を参照する分岐は解除。
             */
            state.editSurvey.groups.forEach(g => {
                g.questions.forEach(q => {
                    q.choices?.forEach(choice => {
                        if (choice.nextQuestionId === questionId) {
                            choice.nextQuestionId = null;
                        }
                    });
                });
            });

            calculateQuestionNumber(state.editSurvey);
            render();
        }
    );
}

function updateQuestionText(groupId, questionId, value) {
    const q = findQuestion(groupId, questionId);

    if (q) {
        q.questionText = value;
    }
}

function updateQuestionType(groupId, questionId, value) {
    const q = findQuestion(groupId, questionId);

    if (!q) return;

    q.type = value;

    if (value === "text") {
        q.choices = [];
    } else if (!q.choices.length) {
        q.choices = [newChoice()];
    }

    render();
}

function updateQuestionRequired(groupId, questionId, value) {
    const q = findQuestion(groupId, questionId);

    if (q) {
        q.required = value;
    }
}

function updateChoiceLabel(
    groupId,
    questionId,
    choiceId,
    value
) {
    const q = findQuestion(groupId, questionId);

    if (!q) return;

    const choice = q.choices.find(
        c => c.id === choiceId
    );

    if (choice) {
        choice.label = value;
    }
}

function updateNextQuestion(
    groupId,
    questionId,
    choiceId,
    value
) {
    const q = findQuestion(groupId, questionId);

    if (!q) return;

    const choice = q.choices.find(
        c => c.id === choiceId
    );

    if (choice) {
        choice.nextQuestionId = value || null;
    }
}

function addChoice(groupId, questionId) {
    const q = findQuestion(groupId, questionId);

    if (!q) return;

    q.choices.push(newChoice());

    render();
}

function deleteChoice(
    groupId,
    questionId,
    choiceId
) {
    const q = findQuestion(groupId, questionId);

    if (!q) return;

    q.choices = q.choices.filter(
        c => c.id !== choiceId
    );

    render();
}

function moveQuestionDialog(groupId, questionId) {
    const options = state.editSurvey.groups
        .filter(g => g.id !== groupId)
        .map(g => `
            <option value="${escapeHtml(g.id)}">
                ${escapeHtml(g.title || "無題のグループ")}
            </option>
        `)
        .join("");

    if (!options) {
        notify("移動先グループがありません。", "error");
        return;
    }

    openModal(
        "質問移動",
        `
            <div class="field">
                <label>移動先グループ</label>
                <select id="move-question-target">
                    ${options}
                </select>
            </div>
        `,
        [
            {
                label: "キャンセル",
                onClick: closeModal
            },
            {
                label: "移動",
                className: "primary",
                onClick: () => {
                    const target =
                        document.getElementById(
                            "move-question-target"
                        ).value;

                    const source =
                        state.editSurvey.groups.find(
                            g => g.id === groupId
                        );

                    const targetGroup =
                        state.editSurvey.groups.find(
                            g => g.id === target
                        );

                    if (!source || !targetGroup) return;

                    const index =
                        source.questions.findIndex(
                            q => q.id === questionId
                        );

                    if (index < 0) return;

                    const [question] =
                        source.questions.splice(index, 1);

                    targetGroup.questions.push(question);

                    calculateQuestionNumber(
                        state.editSurvey
                    );

                    closeModal();
                    render();
                }
            }
        ]
    );
}

let draggedGroupId = null;
let draggedQuestionId = null;
let draggedQuestionGroupId = null;

function dragGroupStart(event) {
    draggedGroupId =
        event.currentTarget.dataset.groupId;
}

function dropGroup(event) {
    const targetId =
        event.currentTarget.dataset.groupId;

    if (!draggedGroupId || draggedGroupId === targetId) {
        return;
    }

    const groups = state.editSurvey.groups;

    const from = groups.findIndex(
        g => g.id === draggedGroupId
    );

    const to = groups.findIndex(
        g => g.id === targetId
    );

    if (from < 0 || to < 0) return;

    const [group] = groups.splice(from, 1);

    groups.splice(to, 0, group);

    calculateQuestionNumber(state.editSurvey);

    draggedGroupId = null;

    render();
}

function dragQuestionStart(event) {
    draggedQuestionId =
        event.currentTarget.dataset.questionId;

    draggedQuestionGroupId =
        event.currentTarget.dataset.groupId;
}

function dropQuestion(event) {
    const targetId =
        event.currentTarget.dataset.questionId;

    const targetGroupId =
        event.currentTarget.dataset.groupId;

    if (
        !draggedQuestionId ||
        draggedQuestionId === targetId
    ) {
        return;
    }

    if (draggedQuestionGroupId !== targetGroupId) {
        return;
    }

    const group = state.editSurvey.groups.find(
        g => g.id === targetGroupId
    );

    if (!group) return;

    const from = group.questions.findIndex(
        q => q.id === draggedQuestionId
    );

    const to = group.questions.findIndex(
        q => q.id === targetId
    );

    if (from < 0 || to < 0) return;

    const [question] =
        group.questions.splice(from, 1);

    group.questions.splice(to, 0, question);

    calculateQuestionNumber(state.editSurvey);

    draggedQuestionId = null;
    draggedQuestionGroupId = null;

    render();
}

async function saveSurvey() {
    const survey = state.editSurvey;

    survey.title =
        document.getElementById("edit-title").value.trim();

    survey.description =
        document.getElementById("edit-description").value;

    survey.questionNumbering =
        document.getElementById("edit-numbering").value;

    survey.startAt =
        document.getElementById("edit-start").value
            ? new Date(
                document.getElementById("edit-start").value
            ).toISOString()
            : "";

    survey.endAt =
        document.getElementById("edit-end").value
            ? new Date(
                document.getElementById("edit-end").value
            ).toISOString()
            : "";

    survey.allowResubmit =
        document.getElementById("edit-resubmit").checked;

    if (!survey.title) {
        notify("タイトルを入力してください。", "error");
        return;
    }

    calculateQuestionNumber(survey);

    try {
        await api("save_survey", {
            survey
        });

        state.editSurvey = null;

        await loadData();

        notify("アンケートを保存しました。", "success");

        navigate("admin-survey-list");
    } catch (error) {
        notify(error.message, "error");
    }
}

function cancelSurveyEdit() {
    confirmAction(
        "編集内容破棄",
        "編集内容を破棄して前画面へ戻りますか？",
        async () => {
            state.editSurvey = null;

            navigate("admin-survey-list");
        }
    );
}

async function changeSurveyStatus(
    surveyId,
    operation
) {
    const labels = {
        publish: "公開",
        stop: "停止",
        resume: "再開"
    };

    confirmAction(
        labels[operation],
        `アンケートを「${labels[operation]}」しますか？`,
        async () => {
            await api("survey_status", {
                surveyId,
                operation
            });

            await loadData();

            state.editSurvey = null;

            notify(
                `アンケートを${labels[operation]}しました。`,
                "success"
            );

            render();
        }
    );
}

function renderPreview() {
    const survey = state.surveyId
        ? surveyById(state.surveyId)
        : state.editSurvey;

    if (!survey) {
        return `
            <div class="card">
                <div class="alert error">
                    プレビュー対象がありません。
                </div>
            </div>
        `;
    }

    return `
        <div class="page-head">
            <div>
                <h1>アンケートプレビュー</h1>
                <div class="help">
                    実際の回答送信は行いません。
                </div>
            </div>

            <div class="actions">
                <button class="btn"
                    onclick="setPreviewMode("desktop")">
                    PC
                </button>

                <button class="btn"
                    onclick="setPreviewMode("mobile")">
                    スマートフォン
                </button>

                <button class="btn"
                    onclick="goBackFromPreview()">
                    戻る
                </button>
            </div>
        </div>

        ${
            state.previewMode === "mobile"
                ? `
                    <div class="preview-phone">
                        ${renderAnswerContent(
                            survey,
                            true
                        )}
                    </div>
                `
                : `
                    <div class="preview-desktop">
                        ${renderAnswerContent(
                            survey,
                            true
                        )}
                    </div>
                `
        }
    `;
}

function setPreviewMode(mode) {
    state.previewMode = mode;
    render();
}

function goBackFromPreview() {
    if (state.surveyId) {
        navigate(
            "admin-survey-edit",
            {surveyId: state.surveyId}
        );
    } else {
        navigate("admin-survey-list");
    }
}

function renderAnswerContent(survey, preview = false) {
    const questions = survey.groups.flatMap(
        g => g.questions
    );

    return `
        <div class="answer-container"
            style="${preview ? "width:auto;margin:0;padding:10px" : ""}">

            <div class="answer-header">
                <h1>${escapeHtml(survey.title)}</h1>

                <p>
                    ${escapeHtml(survey.description || "")}
                </p>
            </div>

            ${questions.map(q => `
                <div class="answer-question">
                    <h3>
                        ${escapeHtml(q.questionNumber)}
                        ${q.required
                            ? '<span style="color:#dc2626">*</span>'
                            : ""}
                    </h3>

                    <div>
                        ${escapeHtml(q.questionText)}
                    </div>

                    ${
                        q.type === "text"
                            ? `
                                <textarea
                                    placeholder="回答を入力"></textarea>
                            `
                            : q.choices.map(c => `
                                <label class="answer-choice">
                                    <input
                                        type="${
                                            q.type === "single"
                                                ? "radio"
                                                : "checkbox"
                                        }"
                                        name="preview_${escapeHtml(q.id)}">
                                    <span>
                                        ${escapeHtml(c.label)}
                                    </span>
                                </label>
                            `).join("")
                    }
                </div>
            `).join("")}

            <div class="answer-footer">
                <button class="btn">戻る</button>
                <button class="btn primary" disabled>
                    送信
                </button>
            </div>
        </div>
    `;
}

function renderSend() {
    const survey = surveyById(state.surveyId);

    if (!survey) {
        return `
            <div class="card">
                <div class="alert error">
                    対象アンケートが指定されていません。
                </div>
                <button class="btn"
                    onclick="navigate("admin-survey-list")">
                    一覧へ戻る
                </button>
            </div>
        `;
    }

    const selected =
        state.customers.filter(
            c => state.selectedCustomers.has(c.id)
        );

    const history =
        state.sendHistory.filter(
            h => h.surveyId === survey.id
        );

    return `
        <div class="page-head">
            <div>
                <h1>顧客選択・メール送信</h1>
                <div class="help">
                    対象アンケート:
                    <strong>${escapeHtml(survey.title)}</strong>
                </div>
            </div>

            <button class="btn"
                onclick="navigate("admin-survey-list")">
                一覧へ戻る
            </button>
        </div>

        <div class="grid grid-4">
            <div class="stat">
                <div class="stat-label">顧客数</div>
                <div class="stat-value">
                    ${state.customers.length}
                </div>
            </div>

            <div class="stat">
                <div class="stat-label">選択数</div>
                <div class="stat-value">
                    ${selected.length}
                </div>
            </div>

            <div class="stat">
                <div class="stat-label">回答済み</div>
                <div class="stat-value">
                    ${countCustomerAnswered(survey.id)}
                </div>
            </div>

            <div class="stat">
                <div class="stat-label">未回答</div>
                <div class="stat-value">
                    ${countCustomerUnanswered(survey.id)}
                </div>
            </div>
        </div>

        <div class="card">
            <h2>顧客選択</h2>

            <div class="searchbar">
                <input
                    id="customer-search"
                    type="text"
                    placeholder="顧客名・組織名・メールアドレス">

                <select id="customer-status">
                    <option value="">すべてのステータス</option>
                    <option value="unsent">未送信</option>
                    <option value="unanswered">
                        送信済み / 未回答
                    </option>
                    <option value="answered">回答済み</option>
                </select>

                <button class="btn"
                    onclick="render()">
                    検索
                </button>

                <button class="btn"
                    onclick="selectAllVisibleCustomers()">
                    すべて選択
                </button>

                <button class="btn"
                    onclick="clearSelectedCustomers()">
                    すべて解除
                </button>

                <button class="btn warning"
                    onclick="selectReminderCustomers()">
                    未回答を選択
                </button>
            </div>

            <div
                class="table-wrap"
                style="margin-top:14px">

                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>組織名</th>
                            <th>氏名</th>
                            <th>メール</th>
                            <th>電話番号</th>
                            <th>住所</th>
                            <th>最終送信</th>
                            <th>送信回数</th>
                            <th>回答ステータス</th>
                            <th>kintone</th>
                        </tr>
                    </thead>

                    <tbody>
                        ${renderCustomerRows(survey.id)}
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>メール編集</h2>

            <div class="field">
                <label>件名</label>
                <input
                    id="send-subject"
                    type="text"
                    value="アンケートご回答のお願い">
            </div>

            <div class="field">
                <label>本文</label>
                <textarea id="send-body">{
顧客名} 様

アンケートへのご回答をお願いいたします。

以下のURLからご回答ください。
{アンケートURL}

よろしくお願いいたします。</textarea>
                <div class="help">
                    使用可能な変数:
                    {顧客名} / {アンケートURL}
                </div>
            </div>

            <div class="actions">
                <button class="btn"
                    onclick="previewSelectedMail()">
                    メール内容確認
                </button>

                <button class="btn primary"
                    onclick="sendSelected("bulk")">
                    一括送信
                </button>

                <button class="btn warning"
                    onclick="sendSelected("resend")">
                    再送
                </button>

                <button class="btn warning"
                    onclick="sendSelected("reminder")">
                    リマインド
                </button>
            </div>
        </div>

        ${
            state.lastSendResult
                ? renderSendResult()
                : ""
        }

        <div class="card">
            <h2>送信履歴</h2>

            ${
                history.length
                    ? history
                        .slice()
                        .reverse()
                        .map(renderHistory)
                        .join("")
                    : `
                        <div class="empty">
                            送信履歴はありません。
                        </div>
                    `
            }
        </div>
    `;
}

function customerSurveyStatus(customer, surveyId) {
    const responses =
        state.responses.filter(
            r =>
                r.surveyId === surveyId &&
                (
                    r.customerId === customer.id ||
                    r.token === customer.id
                )
        );

    if (responses.some(r => r.submittedAt)) {
        return "answered";
    }

    if ((customer.sendCount || 0) > 0) {
        return "unanswered";
    }

    return "unsent";
}

function countCustomerAnswered(surveyId) {
    return state.customers.filter(
        c => customerSurveyStatus(c, surveyId) === "answered"
    ).length;
}

function countCustomerUnanswered(surveyId) {
    return state.customers.filter(
        c => customerSurveyStatus(c, surveyId) === "unanswered"
    ).length;
}

function renderCustomerRows(surveyId) {
    let customers = [...state.customers];

    const search =
        document.getElementById("customer-search")?.value
        || "";

    const status =
        document.getElementById("customer-status")?.value
        || "";

    if (search.trim()) {
        const q = search.trim().toLowerCase();

        customers = customers.filter(c =>
            [
                c.name,
                c.organization,
                c.email
            ].some(v =>
                String(v || "")
                    .toLowerCase()
                    .includes(q)
            )
        );
    }

    if (status) {
        customers = customers.filter(
            c => customerSurveyStatus(c, surveyId) === status
        );
    }

    return customers.map(c => {
        const customerStatus =
            customerSurveyStatus(c, surveyId);

        return `
            <tr>
                <td>
                    <input
                        type="checkbox"
                        ${state.selectedCustomers.has(c.id)
                            ? "checked" : ""}
                        onchange="toggleCustomer(
                            "${escapeHtml(c.id)}",
                            this.checked
                        )">
                </td>

                <td>${escapeHtml(c.organization)}</td>
                <td>${escapeHtml(c.name)}</td>
                <td>${escapeHtml(c.email)}</td>
                <td>${escapeHtml(c.phone)}</td>
                <td>${escapeHtml(c.address)}</td>
                <td>${formatDate(c.lastSentAt)}</td>
                <td>${c.sendCount || 0}</td>
                <td>
                    <span class="badge ${
                        customerStatus === "answered"
                            ? "published"
                            : customerStatus === "unanswered"
                                ? "stopped"
                                : "draft"
                    }">
                        ${
                            customerStatus === "answered"
                                ? "回答済み"
                                : customerStatus === "unanswered"
                                    ? "送信済み / 未回答"
                                    : "未送信"
                        }
                    </span>
                </td>
                <td>${escapeHtml(c.kintoneStatus)}</td>
            </tr>
        `;
    }).join("");
}

function bindSend() {
    /*
     * 顧客検索はJavaScript側で行う。
     */
    const search =
        document.getElementById("customer-search");

    const status =
        document.getElementById("customer-status");

    [search, status].forEach(el => {
        if (!el) return;

        el.addEventListener("input", () => {
            const tbody =
                document.querySelector(
                    "#customer-search"
                )?.closest(".card")
                ?.querySelector("tbody");

            if (tbody) {
                tbody.innerHTML =
                    renderCustomerRows(state.surveyId);
            }
        });
    });
}

function toggleCustomer(id, checked) {
    if (checked) {
        state.selectedCustomers.add(id);
    } else {
        state.selectedCustomers.delete(id);
    }

    render();
}

function selectAllVisibleCustomers() {
    const surveyId = state.surveyId;

    state.customers.forEach(c => {
        state.selectedCustomers.add(c.id);
    });

    render();
}

function clearSelectedCustomers() {
    state.selectedCustomers.clear();
    render();
}

function selectReminderCustomers() {
    state.customers.forEach(c => {
        if (
            customerSurveyStatus(
                c,
                state.surveyId
            ) === "unanswered"
        ) {
            state.selectedCustomers.add(c.id);
        }
    });

    render();
}

function previewSelectedMail() {
    const subject =
        document.getElementById("send-subject")?.value || "";

    const body =
        document.getElementById("send-body")?.value || "";

    const customers =
        state.customers.filter(
            c => state.selectedCustomers.has(c.id)
        );

    if (!customers.length) {
        notify("顧客を選択してください。", "error");
        return;
    }

    const items = customers.map(c => {
        const url =
            buildAnswerUrl(
                state.surveyId,
                c.id
            );

        const personalBody =
            body.replaceAll(
                "{顧客名}",
                c.name || ""
            ).replaceAll(
                "{アンケートURL}",
                url
            );

        return `
            <div class="history-item">
                <strong>
                    ${escapeHtml(c.name)}
                    (${escapeHtml(c.email)})
                </strong>

                <div style="margin-top:8px">
                    <strong>件名:</strong>
                    ${escapeHtml(subject)}
                </div>

                <pre style="
                    white-space:pre-wrap;
                    font-family:inherit;
                    background:#f8fafc;
                    padding:10px;
                    border-radius:8px;
                    margin-top:8px
                ">${escapeHtml(personalBody)}</pre>
            </div>
        `;
    }).join("");

    openModal(
        "メール内容確認",
        items,
        [
            {
                label: "閉じる",
                onClick: closeModal
            }
        ]
    );
}

function buildAnswerUrl(surveyId, token) {
    const query = new URLSearchParams();

    query.set("view", "answer");
    query.set("surveyId", surveyId);
    query.set("token", token);

    return location.origin +
        location.pathname +
        "?" +
        query.toString();
}

function sendSelected(type) {
    const ids =
        [...state.selectedCustomers];

    if (!ids.length) {
        notify("顧客を選択してください。", "error");
        return;
    }

    const alreadySent =
        state.customers.filter(
            c =>
                ids.includes(c.id) &&
                (c.sendCount || 0) > 0
        );

    const message =
        type === "reminder"
            ? "未回答顧客へリマインドを送信しますか？"
            : type === "resend" && alreadySent.length
                ? `送信済み顧客 ${alreadySent.length}件を含みます。再送しますか？`
                : `${ids.length}件にメールを送信しますか？`;

    confirmAction(
        type === "reminder"
            ? "リマインド確認"
            : type === "resend"
                ? "再送確認"
                : "一括送信確認",
        message,
        async () => {
            const subject =
                document.getElementById(
                    "send-subject"
                ).value;

            const body =
                document.getElementById(
                    "send-body"
                ).value;

            const result = await api(
                "send_mail",
                {
                    surveyId: state.surveyId,
                    customers: ids,
                    subject,
                    body,
                    type
                }
            );

            state.lastSendResult = result;

            await loadData();

            notify(
                "メール送信処理が完了しました。",
                "success"
            );

            render();
        }
    );
}

function renderSendResult() {
    const r = state.lastSendResult;

    return `
        <div class="card">
            <h2>送信結果</h2>

            <div class="grid grid-4">
                <div class="stat">
                    <div class="stat-label">対象件数</div>
                    <div class="stat-value">${r.total}</div>
                </div>

                <div class="stat">
                    <div class="stat-label">成功件数</div>
                    <div class="stat-value"
                        style="color:#16a34a">
                        ${r.successCount}
                    </div>
                </div>

                <div class="stat">
                    <div class="stat-label">失敗件数</div>
                    <div class="stat-value"
                        style="color:#dc2626">
                        ${r.failedCount}
                    </div>
                </div>

                <div class="stat">
                    <div class="stat-label">送信日時</div>
                    <div style="margin-top:8px">
                        ${formatDate(r.sentAt)}
                    </div>
                </div>
            </div>

            <div style="margin-top:14px">
                ${r.results.map(result => `
                    <div class="history-item">
                        <strong>
                            ${escapeHtml(
                                result.customerName || ""
                            )}
                        </strong>

                        <span class="badge ${
                            result.success
                                ? "published"
                                : "ended"
                        }">
                            ${
                                result.success
                                    ? "成功"
                                    : "失敗"
                            }
                        </span>

                        <div class="help">
                            ${escapeHtml(
                                result.message || ""
                            )}
                        </div>
                    </div>
                `).join("")}
            </div>
        </div>
    `;
}

function renderHistory(history) {
    return `
        <div class="history-item">
            <div style="
                display:flex;
                justify-content:space-between;
                gap:10px;
                flex-wrap:wrap
            ">
                <strong>
                    ${escapeHtml(history.subject)}
                </strong>

                <span>
                    ${formatDate(history.sentAt)}
                </span>
            </div>

            <div class="help">
                種別:
                ${escapeHtml(history.type)}
                /
                件数:
                ${history.count}
                /
                成功:
                ${history.successCount ?? "-"}
                /
                失敗:
                ${history.failedCount ?? "-"}
            </div>

            <details style="margin-top:8px">
                <summary>送信内容を確認</summary>

                <div style="margin-top:10px">
                    ${(history.customers || [])
                        .map(c => `
                            <div class="history-item">
                                <strong>
                                    ${escapeHtml(
                                        c.customerName || ""
                                    )}
                                    /
                                    ${escapeHtml(
                                        c.email || ""
                                    )}
                                </strong>

                                <div class="help">
                                    アンケートURL:
                                    ${escapeHtml(
                                        c.url || ""
                                    )}
                                </div>

                                <pre style="
                                    white-space:pre-wrap;
                                    font-family:inherit
                                ">${escapeHtml(
                                    c.body || ""
                                )}</pre>
                            </div>
                        `)
                        .join("")}
                </div>
            </details>
        </div>
    `;
}

function renderAggregation() {
    const survey = surveyById(state.surveyId);

    if (!survey) {
        return `
            <div class="card">
                <div class="alert error">
                    対象アンケートが指定されていません。
                </div>

                <button class="btn"
                    onclick="navigate("admin-survey-list")">
                    一覧へ戻る
                </button>
            </div>
        `;
    }

    const responses =
        responsesForSurvey(survey.id)
            .filter(r => r.submittedAt);

    const sentTargets =
        state.customers.filter(
            c => (c.sendCount || 0) > 0
        ).length;

    const unknown =
        responses.filter(
            r => !r.customerId ||
                !state.customers.some(
                    c => c.id === r.customerId
                )
        ).length;

    const answered = responses.length;

    const rate = sentTargets
        ? ((answered / sentTargets) * 100).toFixed(1)
        : "0.0";

    const questions =
        survey.groups.flatMap(
            g => g.questions
        );

    if (!state.selectedQuestions.size) {
        questions.forEach(q =>
            state.selectedQuestions.add(q.id)
        );
    }

    return `
        <div class="page-head">
            <div>
                <h1>回答集計・分析</h1>

                <div class="help">
                    対象アンケート:
                    <strong>
                        ${escapeHtml(survey.title)}
                    </strong>
                </div>
            </div>

            <div class="actions">
                <button class="btn"
                    onclick="exportCsv()">
                    CSV出力
                </button>

                <button class="btn"
                    onclick="exportPdf()">
                    PDF出力
                </button>

                <button class="btn"
                    onclick="navigate("admin-survey-list")">
                    一覧へ戻る
                </button>
            </div>
        </div>

        <div class="grid grid-4">
            <div class="stat">
                <div class="stat-label">送信対象者数</div>
                <div class="stat-value">
                    ${sentTargets}
                </div>
            </div>

            <div class="stat">
                <div class="stat-label">回答数</div>
                <div class="stat-value">
                    ${answered}
                </div>
            </div>

            <div class="stat">
                <div class="stat-label">未登録顧客からの回答</div>
                <div class="stat-value">
                    ${unknown}
                </div>
            </div>

            <div class="stat">
                <div class="stat-label">回答率</div>
                <div class="stat-value">
                    ${rate}%
                </div>
            </div>
        </div>

        <div class="card">
            <h2>設問選択</h2>

            <div class="actions">
                <button class="btn"
                    onclick="selectAllQuestions()">
                    すべて選択
                </button>

                <button class="btn"
                    onclick="clearAllQuestions()">
                    すべて解除
                </button>
            </div>

            <div style="margin-top:14px">
                ${questions.map(q => `
                    <label style="
                        display:flex;
                        gap:8px;
                        align-items:flex-start;
                        margin-bottom:8px;
                        font-weight:400
                    ">
                        <input
                            type="checkbox"
                            ${state.selectedQuestions.has(q.id)
                                ? "checked" : ""}
                            onchange="toggleQuestion(
                                "${escapeHtml(q.id)}",
                                this.checked
                            )">

                        <span>
                            <strong>
                                ${escapeHtml(q.questionNumber)}
                            </strong>
                            ${escapeHtml(q.questionText)}
                        </span>
                    </label>
                `).join("")}
            </div>
        </div>

        ${
            questions
                .filter(q =>
                    state.selectedQuestions.has(q.id)
                )
                .map(q =>
                    renderQuestionAggregation(
                        q,
                        responses
                    )
                )
                .join("")
        }

        <div class="card">
            <h2>個別回答</h2>

            ${
                responses.length
                    ? responses.map(r =>
                        renderIndividualResponse(
                            r,
                            survey
                        )
                    ).join("")
                    : `
                        <div class="empty">
                            回答はありません。
                        </div>
                    `
            }
        </div>
    `;
}

function renderQuestionAggregation(
    question,
    responses
) {
    const values = [];

    responses.forEach(r => {
        const answer =
            r.answers?.[question.id];

        if (answer === undefined) return;

        if (Array.isArray(answer)) {
            answer.forEach(v => values.push(String(v)));
        } else {
            values.push(String(answer));
        }
    });

    if (question.type === "text") {
        return `
            <div class="card">
                <h2>
                    ${escapeHtml(question.questionNumber)}
                    ${escapeHtml(question.questionText)}
                </h2>

                ${
                    values.length
                        ? values.map(value => `
                            <div class="history-item">
                                ${escapeHtml(value)}
                            </div>
                        `).join("")
                        : `
                            <div class="empty">
                                自由記述回答はありません。
                            </div>
                        `
                }
            </div>
        `;
    }

    const counts = {};

    question.choices.forEach(
        choice => {
            counts[choice.id] = {
                label: choice.label,
                count: 0
            };
        }
    );

    values.forEach(value => {
        const choice =
            question.choices.find(
                c =>
                    c.id === value ||
                    c.label === value
            );

        if (choice) {
            counts[choice.id].count++;
        }
    });

    const total = values.length;

    return `
        <div class="card">
            <h2>
                ${escapeHtml(question.questionNumber)}
                ${escapeHtml(question.questionText)}
            </h2>

            <div class="chart">
                ${Object.values(counts).map(item => {
                    const percent =
                        total
                            ? (item.count / total * 100)
                            : 0;

                    return `
                        <div class="bar-row">
                            <div>
                                ${escapeHtml(item.label)}
                            </div>

                            <div class="bar">
                                <span style="
                                    width:${percent}%
                                "></span>
                            </div>

                            <div>
                                ${item.count}件
                                (${percent.toFixed(1)}%)
                            </div>
                        </div>
                    `;
                }).join("")}
            </div>
        </div>
    `;
}

function renderIndividualResponse(response, survey) {
    const customer =
        state.customers.find(
            c => c.id === response.customerId
        );

    return `
        <details class="history-item">
            <summary>
                ${
                    customer
                        ? escapeHtml(customer.name)
                        : "未登録回答者"
                }
                /
                ${formatDate(response.submittedAt)}
            </summary>

            <div style="margin-top:12px">
                ${
                    survey.groups
                        .flatMap(g => g.questions)
                        .map(q => `
                            <div style="margin-bottom:12px">
                                <strong>
                                    ${escapeHtml(
                                        q.questionNumber
                                    )}
                                    ${escapeHtml(
                                        q.questionText
                                    )}
                                </strong>

                                <div style="margin-top:4px">
                                    ${
                                        Array.isArray(
                                            response.answers?.[q.id]
                                        )
                                            ? response.answers[q.id]
                                                .map(
                                                    escapeHtml
                                                )
                                                .join(", ")
                                            : escapeHtml(
                                                response.answers?.[q.id]
                                                    ?? ""
                                            )
                                    }
                                </div>
                            </div>
                        `)
                        .join("")
                }
            </div>
        </details>
    `;
}

function toggleQuestion(id, checked) {
    if (checked) {
        state.selectedQuestions.add(id);
    } else {
        state.selectedQuestions.delete(id);
    }

    render();
}

function selectAllQuestions() {
    const survey = surveyById(state.surveyId);

    if (!survey) return;

    survey.groups
        .flatMap(g => g.questions)
        .forEach(q =>
            state.selectedQuestions.add(q.id)
        );

    render();
}

function clearAllQuestions() {
    state.selectedQuestions.clear();
    render();
}

function exportCsv() {
    const survey = surveyById(state.surveyId);

    if (!survey) return;

    const questions =
        survey.groups.flatMap(g => g.questions);

    const rows = [];

    rows.push([
        "回答ID",
        "回答日時",
        "顧客ID",
        "顧客名",
        ...questions.map(
            q => q.questionNumber
        )
    ]);

    responsesForSurvey(survey.id)
        .filter(r => r.submittedAt)
        .forEach(r => {
            const customer =
                state.customers.find(
                    c => c.id === r.customerId
                );

            rows.push([
                r.id,
                r.submittedAt,
                r.customerId || "",
                customer?.name || "未登録",
                ...questions.map(q => {
                    const value =
                        r.answers?.[q.id] ?? "";

                    return Array.isArray(value)
                        ? value.join(" / ")
                        : value;
                })
            ]);
        });

    const csv = rows.map(row =>
        row.map(value =>
            '"' +
            String(value ?? "")
                .replaceAll('"', '""') +
            '"'
        ).join(",")
    ).join("\r\n");

    const blob = new Blob(
        ["\uFEFF" + csv],
        {type: "text/csv;charset=utf-8"}
    );

    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");

    a.href = url;
    a.download =
        "survey-" + survey.id + ".csv";

    a.click();

    URL.revokeObjectURL(url);

    notify(
        "CSV出力を実行しました。",
        "success"
    );
}

function exportPdf() {
    /*
     * PDF実ファイル生成は必須ではないため、
     * 操作実行を画面上で確認する。
     */
    openModal(
        "PDF出力",
        `
            <div class="alert success">
                PDF出力操作を実行しました。
            </div>

            <p>
                このプロトタイプではPDF実ファイル生成は
                必須要件ではないため、操作結果を表示しています。
            </p>
        `,
        [
            {
                label: "閉じる",
                onClick: closeModal
            }
        ]
    );
}

function renderKintone() {
    const settings =
        state.kintone.settings || {};

    const mapping =
        state.kintone.mapping || {};

    const fields =
        state.kintone.fields || [];

    return `
        <div class="page-head">
            <div>
                <h1>kintone連携設定</h1>
            </div>
        </div>

        <div class="card">
            <h2>接続設定</h2>

            <div class="grid grid-2">
                <div class="field">
                    <label>サブドメイン</label>
                    <input
                        id="k-subdomain"
                        type="text"
                        value="${escapeHtml(
                            settings.subdomain || ""
                        )}"
                        placeholder="https://xxxx.cybozu.com">
                    <div class="help">
                        https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx
                    </div>
                </div>

                <div class="field">
                    <label>顧客管理アプリID</label>
                    <input
                        id="k-appid"
                        type="text"
                        value="${escapeHtml(
                            settings.appId || ""
                        )}">
                </div>

                <div class="field">
                    <label>ログイン名</label>
                    <input
                        id="k-login"
                        type="text"
                        value="${escapeHtml(
                            settings.loginName || ""
                        )}">
                </div>

                <div class="field">
                    <label>パスワード</label>
                    <input
                        id="k-password"
                        type="password"
                        value="${escapeHtml(
                            settings.password || ""
                        )}">
                </div>

                <div class="field">
                    <label>SSL証明書検証</label>
                    <select id="k-ssl">
                        <option value="false"
                            ${!settings.sslVerify
                                ? "selected" : ""}>
                            検証しない
                        </option>

                        <option value="true"
                            ${settings.sslVerify
                                ? "selected" : ""}>
                            検証する
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>プロキシ</label>
                    <input
                        id="k-proxy"
                        type="text"
                        value="${escapeHtml(
                            settings.proxy || ""
                        )}"
                        placeholder="proxy.example.local:8080">
                    <div class="help">
                        認証なし。host:port形式。
                    </div>
                </div>
            </div>

            <div id="kintone-result"></div>

            <div class="actions">
                <button class="btn primary"
                    onclick="saveKintone()">
                    設定保存
                </button>

                <button class="btn"
                    onclick="testKintone()">
                    接続テスト
                </button>

                <button class="btn"
                    onclick="getKintoneFields()">
                    項目一覧を再取得
                </button>

                <button class="btn success"
                    onclick="syncKintone()">
                    顧客情報を同期
                </button>
            </div>
        </div>

        <div class="card">
            <h2>kintoneフィールドマッピング</h2>

            ${renderKintoneMapping(
                mapping,
                fields
            )}
        </div>
    `;
}

function renderKintoneMapping(mapping, fields) {
    const option = (selected) => `
        <option value="">未設定</option>
        ${fields.map(f => `
            <option
                value="${escapeHtml(f.code)}"
                ${selected === f.code
                    ? "selected" : ""}>
                ${escapeHtml(f.label)}
                (${escapeHtml(f.code)})
            </option>
        `).join("")}
    `;

    return `
        <div class="grid grid-2">
            <div class="field">
                <label>組織名</label>
                <select id="map-organization">
                    ${option(mapping.organization || "")}
                </select>
            </div>

            <div class="field">
                <label>氏名</label>
                <select id="map-name">
                    ${option(mapping.name || "")}
                </select>
            </div>

            <div class="field">
                <label>メールアドレス</label>
                <select id="map-email">
                    ${option(mapping.email || "")}
                </select>
            </div>

            <div class="field">
                <label>部署名</label>
                <select id="map-department">
                    ${option(mapping.department || "")}
                </select>
            </div>

            <div class="field">
                <label>電話番号</label>
                <select id="map-phone">
                    ${option(mapping.phone || "")}
                </select>
            </div>
        </div>

        <div class="field">
            <label>住所</label>

            <div class="grid grid-3">
                ${fields.map(f => `
                    <label style="font-weight:400">
                        <input
                            type="checkbox"
                            name="map-address"
                            value="${escapeHtml(f.code)}"
                            ${(mapping.address || []).includes(
                                f.code
                            ) ? "checked" : ""}>
                        ${escapeHtml(f.label)}
                        (${escapeHtml(f.code)})
                    </label>
                `).join("")}
            </div>
        </div>

        <button class="btn primary"
            onclick="saveKintoneMapping()">
            マッピング保存
        </button>

        <div class="help" style="margin-top:12px">
            フィールド一覧:
            ${fields.length}件
        </div>
    `;
}

function getKintoneSettingsFromForm() {
    return {
        subdomain:
            document.getElementById("k-subdomain").value,
        appId:
            document.getElementById("k-appid").value,
        loginName:
            document.getElementById("k-login").value,
        password:
            document.getElementById("k-password").value,
        sslVerify:
            document.getElementById("k-ssl").value === "true",
        proxy:
            document.getElementById("k-proxy").value
    };
}

function bindKintone() {}

async function saveKintone() {
    try {
        const settings =
            getKintoneSettingsFromForm();

        await api("kintone_save", {
            settings
        });

        await loadData();

        notify(
            "kintone設定を保存しました。",
            "success"
        );

        render();
    } catch (error) {
        notify(error.message, "error");
    }
}

async function testKintone() {
    try {
        const settings =
            getKintoneSettingsFromForm();

        const result =
            await api("kintone_test", {
                settings
            });

        document.getElementById(
            "kintone-result"
        ).innerHTML = `
            <div class="alert success">
                ${escapeHtml(result.message)}
            </div>
        `;
    } catch (error) {
        document.getElementById(
            "kintone-result"
        ).innerHTML = `
            <div class="alert error">
                接続失敗:
                ${escapeHtml(error.message)}
            </div>
        `;
    }
}

async function getKintoneFields() {
    try {
        const settings =
            getKintoneSettingsFromForm();

        const result =
            await api("kintone_fields", {
                settings
            });

        await loadData();

        notify(
            "kintone項目一覧を取得しました。",
            "success"
        );

        render();
    } catch (error) {
        notify(error.message, "error");
    }
}

async function saveKintoneMapping() {
    try {
        const settings =
            getKintoneSettingsFromForm();

        const address = [
            ...document.querySelectorAll(
                'input[name="map-address"]:checked'
            )
        ].map(input => input.value);

        const mapping = {
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
        };

        await api("kintone_save", {
            settings,
            mapping
        });

        await loadData();

        notify(
            "フィールドマッピングを保存しました。",
            "success"
        );

        render();
    } catch (error) {
        notify(error.message, "error");
    }
}

async function syncKintone() {
    confirmAction(
        "顧客情報を同期",
        "kintoneから顧客情報を同期しますか？",
        async () => {
            const settings =
                getKintoneSettingsFromForm();

            const result =
                await api("kintone_sync", {
                    settings
                });

            await loadData();

            notify(
                result.message,
                "success"
            );

            render();
        }
    );
}

function renderMail() {
    const mail = state.mail || {};

    return `
        <div class="page-head">
            <div>
                <h1>メールサーバ設定</h1>
            </div>
        </div>

        <div class="card">
            <div class="grid grid-2">
                <div class="field">
                    <label>SMTPサーバ</label>
                    <input
                        id="mail-server"
                        type="text"
                        value="${escapeHtml(
                            mail.smtpServer || ""
                        )}">
                </div>

                <div class="field">
                    <label>SMTPポート</label>
                    <input
                        id="mail-port"
                        type="number"
                        value="${mail.smtpPort || 587}">
                </div>

                <div class="field">
                    <label>暗号化方式</label>
                    <select id="mail-encryption">
                        <option value="none"
                            ${mail.encryption === "none"
                                ? "selected" : ""}>
                            なし
                        </option>

                        <option value="tls"
                            ${!mail.encryption ||
                                mail.encryption === "tls"
                                ? "selected" : ""}>
                            STARTTLS
                        </option>

                        <option value="ssl"
                            ${mail.encryption === "ssl"
                                ? "selected" : ""}>
                            SSL/TLS
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>SMTP認証</label>
                    <select id="mail-auth">
                        <option value="true"
                            ${mail.smtpAuth !== false
                                ? "selected" : ""}>
                            使用する
                        </option>

                        <option value="false"
                            ${mail.smtpAuth === false
                                ? "selected" : ""}>
                            使用しない
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>SMTPユーザー名</label>
                    <input
                        id="mail-user"
                        type="text"
                        value="${escapeHtml(
                            mail.username || ""
                        )}">
                </div>

                <div class="field">
                    <label>SMTPパスワード</label>
                    <input
                        id="mail-password"
                        type="password"
                        value="${escapeHtml(
                            mail.password || ""
                        )}">
                </div>

                <div class="field">
                    <label>送信元メールアドレス</label>
                    <input
                        id="mail-from"
                        type="email"
                        value="${escapeHtml(
                            mail.fromEmail || ""
                        )}">
                </div>

                <div class="field">
                    <label>送信元名</label>
                    <input
                        id="mail-from-name"
                        type="text"
                        value="${escapeHtml(
                            mail.fromName || ""
                        )}">
                </div>

                <div class="field">
                    <label>返信先メールアドレス</label>
                    <input
                        id="mail-reply"
                        type="email"
                        value="${escapeHtml(
                            mail.replyTo || ""
                        )}">
                </div>

                <div class="field">
                    <label>接続状態</label>
                    <div style="padding-top:10px">
                        <span class="badge ${
                            mail.connectionStatus === "接続確認済み"
                                ? "published"
                                : mail.connectionStatus ===
                                    "接続できません"
                                    ? "ended"
                                    : "draft"
                        }">
                            ${escapeHtml(
                                mail.connectionStatus ||
                                "未設定"
                            )}
                        </span>
                    </div>
                </div>
            </div>

            <div class="actions">
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
    `;
}

function bindMail() {}

function getMailForm() {
    return {
        smtpServer:
            document.getElementById(
                "mail-server"
            ).value,

        smtpPort:
            Number(
                document.getElementById(
                    "mail-port"
                ).value
            ),

        encryption:
            document.getElementById(
                "mail-encryption"
            ).value,

        smtpAuth:
            document.getElementById(
                "mail-auth"
            ).value === "true",

        username:
            document.getElementById(
                "mail-user"
            ).value,

        password:
            document.getElementById(
                "mail-password"
            ).value,

        fromEmail:
            document.getElementById(
                "mail-from"
            ).value,

        fromName:
            document.getElementById(
                "mail-from-name"
            ).value,

        replyTo:
            document.getElementById(
                "mail-reply"
            ).value
    };
}

async function saveMail() {
    try {
        const mail = getMailForm();

        await api("mail_save", mail);

        await loadData();

        notify(
            "メール設定を保存しました。",
            "success"
        );

        render();
    } catch (error) {
        notify(error.message, "error");
    }
}

async function testMail() {
    try {
        const mail = getMailForm();

        const to = prompt(
            "テスト送信先メールアドレス",
            mail.fromEmail || ""
        );

        if (!to) return;

        const result =
            await api("mail_test", {
                mail,
                to
            });

        await loadData();

        notify(
            result.message,
            result.success ? "success" : "error"
        );

        render();
    } catch (error) {
        notify(error.message, "error");
    }
}

function renderAnswer(app) {
    const survey = surveyById(state.surveyId);

    if (
        !survey ||
        !state.surveyId
    ) {
        app.innerHTML = renderAnswerError(
            "アンケートが指定されていません。"
        );
        return;
    }

    if (state.currentView === "answer") {
        app.innerHTML =
            renderAnswerPage(survey);
        return;
    }

    if (state.currentView === "confirm") {
        app.innerHTML =
            renderConfirmPage(survey);
        return;
    }

    if (state.currentView === "complete") {
        app.innerHTML =
            renderCompletePage(survey);
        return;
    }

    /*
     * 未知viewはURL検証で管理者一覧に戻るため
     * 通常ここには到達しない。
     */
    navigate(
        "admin-survey-list",
        {},
        true
    );
}

function renderAnswerError(message) {
    return `
        <div class="answer-shell">
            <div class="answer-container">
                <div class="card">
                    <div class="alert error">
                        ${escapeHtml(message)}
                    </div>
                </div>
            </div>
        </div>
    `;
}

function existingResponseForToken(surveyId, token) {
    return state.responses.find(
        r =>
            r.surveyId === surveyId &&
            r.token === token &&
            r.submittedAt
    ) || null;
}

function renderAnswerPage(survey) {
    const status =
        survey.status;

    if (status !== "published") {
        return `
            <div class="answer-shell">
                <div class="answer-container">
                    <div class="answer-header">
                        <h1>${escapeHtml(
                            survey.title
                        )}</h1>

                        <div class="alert info">
                            現在このアンケートには
                            回答できません。
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    if (state.token) {
        const existing =
            existingResponseForToken(
                survey.id,
                state.token
            );

        if (
            existing &&
            !survey.allowResubmit
        ) {
            return `
                <div class="answer-shell">
                    <div class="answer-container">
                        <div class="answer-header">
                            <h1>${escapeHtml(
                                survey.title
                            )}</h1>

                            <div class="alert success">
                                このアンケートは回答済みです。
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
    }

    if (!state.answerVisibleIds.length) {
        initializeAnswerFlow(survey);
    }

    return `
        <div class="answer-shell">
            <div class="answer-container">

                <div class="answer-header">
                    <h1>${escapeHtml(
                        survey.title
                    )}</h1>

                    <p>
                        ${escapeHtml(
                            survey.description || ""
                        )}
                    </p>

                    <div class="help">
                        必須項目はすべて回答してください。
                    </div>
                </div>

                <div id="answer-error"></div>

                ${renderCurrentAnswerQuestions(
                    survey
                )}

                <div class="answer-footer">
                    <button class="btn"
                        onclick="answerBack()">
                        戻る
                    </button>

                    <button class="btn primary"
                        onclick="answerNext()">
                        次へ
                    </button>
                </div>

            </div>
        </div>
    `;
}

function initializeAnswerFlow(survey) {
    const questions =
        survey.groups.flatMap(
            g => g.questions
        );

    state.answerStep = 0;

    state.answerVisibleIds =
        questions.length
            ? [questions[0].id]
            : [];
}

function currentAnswerQuestion(survey) {
    const questions =
        survey.groups.flatMap(
            g => g.questions
        );

    const id =
        state.answerVisibleIds[
            state.answerStep
        ];

    return questions.find(
        q => q.id === id
    ) || null;
}

function renderCurrentAnswerQuestions(survey) {
    const question =
        currentAnswerQuestion(survey);

    if (!question) {
        return `
            <div class="answer-question">
                回答項目がありません。
            </div>
        `;
    }

    return `
        <div class="answer-question">
            <h3>
                ${escapeHtml(question.questionNumber)}
                ${
                    question.required
                        ? '<span style="color:#dc2626">*</span>'
                        : ""
                }
            </h3>

            <p>
                ${escapeHtml(question.questionText)}
            </p>

            ${
                question.type === "text"
                    ? `
                        <textarea
                            id="answer-text-${escapeHtml(question.id)}"
                            placeholder="回答を入力"
                            oninput="updateTextAnswer(
                                "${escapeHtml(question.id)}",
                                this.value
                            )">${escapeHtml(
                                state.answerDraft[
                                    question.id
                                ] || ""
                            )}</textarea>
                    `
                    : question.choices.map(choice => {
                        const current =
                            state.answerDraft[
                                question.id
                            ];

                        const checked =
                            question.type === "single"
                                ? current === choice.id
                                : Array.isArray(current) &&
                                    current.includes(
                                        choice.id
                                    );

                        return `
                            <label class="answer-choice">
                                <input
                                    type="${
                                        question.type === "single"
                                            ? "radio"
                                            : "checkbox"
                                    }"
                                    name="answer_${escapeHtml(
                                        question.id
                                    )}"
                                    value="${escapeHtml(
                                        choice.id
                                    )}"
                                    ${checked
                                        ? "checked"
                                        : ""}
                                    onchange="updateChoiceAnswer(
                                        "${escapeHtml(
                                            question.id
                                        )}",
                                        "${escapeHtml(
                                            choice.id
                                        )}",
                                        this.checked
                                    )">

                                <span>
                                    ${escapeHtml(
                                        choice.label
                                    )}
                                </span>
                            </label>
                        `;
                    }).join("")
            }
        </div>

        <div class="help">
            ${state.answerStep + 1}
            /
            ${state.answerVisibleIds.length}
        </div>
    `;
}

function updateTextAnswer(questionId, value) {
    state.answerDraft[questionId] = value;
}

function updateChoiceAnswer(
    questionId,
    choiceId,
    checked
) {
    const survey =
        surveyById(state.surveyId);

    const question =
        survey?.groups
            .flatMap(g => g.questions)
            .find(q => q.id === questionId);

    if (!question) return;

    if (question.type === "single") {
        state.answerDraft[questionId] =
            checked ? choiceId : "";
    } else {
        let current =
            Array.isArray(
                state.answerDraft[questionId]
            )
                ? state.answerDraft[questionId]
                : [];

        if (checked) {
            if (!current.includes(choiceId)) {
                current.push(choiceId);
            }
        } else {
            current =
                current.filter(
                    id => id !== choiceId
                );
        }

        state.answerDraft[questionId] = current;
    }
}

function isQuestionAnswered(
    question
) {
    const value =
        state.answerDraft[question.id];

    if (question.type === "text") {
        return String(value || "").trim() !== "";
    }

    if (question.type === "multiple") {
        return Array.isArray(value) &&
            value.length > 0;
    }

    return !!value;
}

function nextQuestionForAnswer(
    survey,
    question
) {
    if (question.type !== "single") {
        return null;
    }

    const choiceId =
        state.answerDraft[question.id];

    if (!choiceId) return null;

    const choice =
        question.choices.find(
            c => c.id === choiceId
        );

    return choice?.nextQuestionId || null;
}

function answerNext() {
    const survey =
        surveyById(state.surveyId);

    if (!survey) return;

    const question =
        currentAnswerQuestion(survey);

    if (!question) return;

    if (
        question.required &&
        !isQuestionAnswered(question)
    ) {
        document.getElementById(
            "answer-error"
        ).innerHTML = `
            <div class="alert error">
                ${escapeHtml(
                    question.questionNumber
                )}「${escapeHtml(
                    question.questionText
                )}」は必須回答です。
            </div>
        `;

        window.scrollTo({
            top: 0,
            behavior: "smooth"
        });

        return;
    }

    const next =
        nextQuestionForAnswer(
            survey,
            question
        );

    if (next) {
        state.answerVisibleIds =
            state.answerVisibleIds.slice(
                0,
                state.answerStep + 1
            );

        if (
            !state.answerVisibleIds.includes(
                next
            )
        ) {
            state.answerVisibleIds.push(next);
        }

        state.answerStep++;
    } else {
        const allQuestions =
            survey.groups.flatMap(
                g => g.questions
            );

        const currentIndex =
            allQuestions.findIndex(
                q => q.id === question.id
            );

        const nextQuestion =
            allQuestions[currentIndex + 1];

        if (nextQuestion) {
            state.answerVisibleIds =
                state.answerVisibleIds.slice(
                    0,
                    state.answerStep + 1
                );

            state.answerVisibleIds.push(
                nextQuestion.id
            );

            state.answerStep++;
        } else {
            navigate(
                "confirm",
                {
                    surveyId: state.surveyId,
                    ...(state.token
                        ? {token: state.token}
                        : {})
                }
            );

            return;
        }
    }

    render();
}

function answerBack() {
    if (state.answerStep <= 0) {
        return;
    }

    state.answerStep--;

    render();
}

function renderConfirmPage(survey) {
    const questions =
        survey.groups.flatMap(
            g => g.questions
        );

    return `
        <div class="answer-shell">
            <div class="answer-container">

                <div class="answer-header">
                    <h1>回答内容確認</h1>

                    <p>
                        ${escapeHtml(
                            survey.title
                        )}
                    </p>
                </div>

                ${questions.map(q => {
                    const value =
                        state.answerDraft[q.id];

                    return `
                        <div class="answer-question">
                            <h3>
                                ${escapeHtml(
                                    q.questionNumber
                                )}
                            </h3>

                            <p>
                                ${escapeHtml(
                                    q.questionText
                                )}
                            </p>

                            <div style="
                                background:#f8fafc;
                                padding:12px;
                                border-radius:8px
                            ">
                                ${
                                    Array.isArray(value)
                                        ? value.map(
                                            choiceId => {
                                                const choice =
                                                    q.choices.find(
                                                        c =>
                                                            c.id ===
                                                            choiceId
                                                    );

                                                return escapeHtml(
                                                    choice?.label ||
                                                    choiceId
                                                );
                                            }
                                        ).join(", ")
                                        : q.type === "single"
                                            ? escapeHtml(
                                                q.choices.find(
                                                    c =>
                                                        c.id ===
                                                        value
                                                )?.label ||
                                                value ||
                                                "未回答"
                                            )
                                            : escapeHtml(
                                                value ||
                                                "未回答"
                                            )
                                }
                            </div>
                        </div>
                    `;
                }).join("")}

                <div class="answer-footer">
                    <button class="btn"
                        onclick="navigate(
                            "answer",
                            {
                                surveyId: state.surveyId,
                                ...(state.token
                                    ? {token: state.token}
                                    : {})
                            }
                        )">
                        修正
                    </button>

                    <button class="btn primary"
                        onclick="confirmSubmitResponse()">
                        回答を送信
                    </button>
                </div>
            </div>
        </div>
    `;
}

function confirmSubmitResponse() {
    openModal(
        "回答送信確認",
        `
            <p>
                回答を送信しますか？
            </p>

            <p class="help">
                送信後は回答済みとして登録されます。
            </p>
        `,
        [
            {
                label: "戻る",
                onClick: closeModal
            },
            {
                label: "送信する",
                className: "primary",
                onClick: async () => {
                    closeModal();

                    try {
                        const result =
                            await api(
                                "save_response",
                                {
                                    surveyId:
                                        state.surveyId,
                                    token:
                                        state.token,
                                    respondent:
                                        state.answerRespondent,
                                    answers:
                                        state.answerDraft
                                }
                            );

                        const newToken =
                            result.token;

                        const query = {
                            surveyId:
                                state.surveyId
                        };

                        if (newToken) {
                            query.token = newToken;
                        }

                        navigate(
                            "complete",
                            query
                        );
                    } catch (error) {
                        notify(
                            error.message,
                            "error"
                        );
                    }
                }
            }
        ]
    );
}

function renderCompletePage(survey) {
    return `
        <div class="answer-shell">
            <div class="answer-container">

                <div class="answer-header"
                    style="text-align:center">

                    <div style="
                        width:70px;
                        height:70px;
                        border-radius:50%;
                        background:#dcfce7;
                        color:#166534;
                        display:flex;
                        align-items:center;
                        justify-content:center;
                        font-size:36px;
                        margin:0 auto 18px
                    ">
                        ✓
                    </div>

                    <h1>回答ありがとうございました</h1>

                    <p>
                        「${escapeHtml(
                            survey.title
                        )}」への回答を受け付けました。
                    </p>

                    <div class="alert success">
                        回答完了
                    </div>
                </div>

            </div>
        </div>
    `;
}

async function init() {
    /*
     * 初期状態はPHPの値ではなく、実際の現在URLを正として取得。
     */
    rebuildStateFromUrl();

    await loadData();

    /*
     * loadData後もURLを再取得。
     */
    rebuildStateFromUrl();

    render();
}

init().catch(error => {
    document.getElementById("app").innerHTML = `
        <div class="content">
            <div class="alert error">
                初期化に失敗しました:
                ${escapeHtml(error.message)}
            </div>
        </div>
    `;
});
</script>

</body>
</html>
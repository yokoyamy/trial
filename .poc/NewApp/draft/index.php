<?php
declare(strict_types=1);

/**
 * アンケート業務運営アプリ
 * Apache 2.4 / PHP 8.4-8.5
 * DB不使用・JSON保存
 */

namespace Yokoyamy\QuestionnaireOperations;

use RuntimeException;
use Throwable;

const APP_NAME = 'アンケート業務運営アプリ';
const DATA_DIR = __DIR__ . '/data';

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['yokoyamy_questionnaire_csrf'])) {
    $_SESSION['yokoyamy_questionnaire_csrf'] = bin2hex(random_bytes(32));
}

function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(array $data, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function requestMethod(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function isApiRequest(): bool
{
    return isset($_GET['api']);
}

function inputJson(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException('リクエストデータが正しくありません。');
    }

    return $data;
}

function requireCsrf(array $data): void
{
    $token = (string)($data['csrf'] ?? '');

    if (
        $token === '' ||
        !isset($_SESSION['yokoyamy_questionnaire_csrf']) ||
        !hash_equals((string)$_SESSION['yokoyamy_questionnaire_csrf'], $token)
    ) {
        throw new RuntimeException('セッションの有効期限が切れています。画面を再読み込みしてください。');
    }
}

function ensureDataDirectory(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存フォルダを作成できません。');
        }
    }
}

function dataPath(string $name): string
{
    return DATA_DIR . '/' . $name . '.json';
}

function readJson(string $name, array $default = []): array
{
    ensureDataDirectory();

    $path = dataPath($name);

    if (!file_exists($path)) {
        return $default;
    }

    $raw = file_get_contents($path);

    if ($raw === false) {
        throw new RuntimeException("データを読み込めません: {$name}");
    }

    if (trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException("JSONデータが壊れています: {$name}");
    }

    return $data;
}

function writeJson(string $name, array $data): void
{
    ensureDataDirectory();

    $path = dataPath($name);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException("JSON変換に失敗しました: {$name}");
    }

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException("データを保存できません: {$name}");
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("データを更新できません: {$name}");
    }
}

function nowDate(): string
{
    return date('Y-m-d');
}

function nowDateTime(): string
{
    return date('Y-m-d H:i:s');
}

function nextId(array $items): int
{
    $max = 0;

    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        $max = max($max, $id);
    }

    return $max + 1;
}

function findById(array $items, int|string $id): ?array
{
    foreach ($items as $item) {
        if ((string)($item['id'] ?? '') === (string)$id) {
            return $item;
        }
    }

    return null;
}

function normalizeSurveyStatus(string $status): string
{
    $allowed = ['draft', 'published', 'closed'];
    return in_array($status, $allowed, true) ? $status : 'draft';
}

function surveyStatusLabel(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'closed' => '終了',
        default => '下書き',
    };
}

function loadSettings(): array
{
    return readJson('settings', [
        'kintone' => [
            'host' => '',
            'app_id' => '',
            'login' => '',
            'password' => '',
            'name_field' => '',
            'email_field' => '',
            'proxy' => '',
            'verify_ssl' => false,
        ],
        'smtp' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'TLS',
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
        ],
    ]);
}

function loadSurveys(): array
{
    return readJson('surveys', []);
}

function saveSurveys(array $surveys): void
{
    writeJson('surveys', array_values($surveys));
}

function loadGroups(): array
{
    return readJson('groups', []);
}

function saveGroups(array $groups): void
{
    writeJson('groups', array_values($groups));
}

function loadQuestions(): array
{
    return readJson('questions', []);
}

function saveQuestions(array $questions): void
{
    writeJson('questions', array_values($questions));
}

function loadOptions(): array
{
    return readJson('options', []);
}

function saveOptions(array $options): void
{
    writeJson('options', array_values($options));
}

function loadCustomers(): array
{
    return readJson('customers', []);
}

function loadRecipients(): array
{
    return readJson('recipients', []);
}

function saveRecipients(array $items): void
{
    writeJson('recipients', array_values($items));
}

function loadMailLogs(): array
{
    return readJson('mail_logs', []);
}

function saveMailLogs(array $items): void
{
    writeJson('mail_logs', array_values($items));
}

function loadResponses(): array
{
    return readJson('responses', []);
}

function saveResponses(array $items): void
{
    writeJson('responses', array_values($items));
}

function loadResponseAnswers(): array
{
    return readJson('response_answers', []);
}

function saveResponseAnswers(array $items): void
{
    writeJson('response_answers', array_values($items));
}

function findSurvey(int $id): ?array
{
    return findById(loadSurveys(), $id);
}

function surveyGroups(int $surveyId): array
{
    $groups = array_values(array_filter(
        loadGroups(),
        static fn(array $g): bool => (int)($g['survey_id'] ?? 0) === $surveyId
    ));

    usort(
        $groups,
        static fn(array $a, array $b): int =>
            ((int)($a['display_order'] ?? 0)) <=> ((int)($b['display_order'] ?? 0))
    );

    return $groups;
}

function surveyQuestions(int $surveyId): array
{
    $groupIds = array_column(surveyGroups($surveyId), 'id');

    $questions = array_values(array_filter(
        loadQuestions(),
        static fn(array $q): bool => in_array($q['group_id'] ?? null, $groupIds, true)
    ));

    usort(
        $questions,
        static fn(array $a, array $b): int =>
            ((int)($a['display_order'] ?? 0)) <=> ((int)($b['display_order'] ?? 0))
    );

    return $questions;
}

function surveyOptions(int $surveyId): array
{
    $questionIds = array_column(surveyQuestions($surveyId), 'id');

    $options = array_values(array_filter(
        loadOptions(),
        static fn(array $o): bool => in_array($o['question_id'] ?? null, $questionIds, true)
    ));

    usort(
        $options,
        static fn(array $a, array $b): int =>
            ((int)($a['display_order'] ?? 0)) <=> ((int)($b['display_order'] ?? 0))
    );

    return $options;
}

function surveyPayload(int $surveyId): ?array
{
    $survey = findSurvey($surveyId);

    if (!$survey) {
        return null;
    }

    $groups = surveyGroups($surveyId);
    $questions = surveyQuestions($surveyId);
    $options = surveyOptions($surveyId);

    foreach ($groups as &$group) {
        $group['questions'] = [];
        foreach ($questions as $question) {
            if (($question['group_id'] ?? null) !== ($group['id'] ?? null)) {
                continue;
            }

            $question['options'] = array_values(array_filter(
                $options,
                static fn(array $option): bool =>
                    ($option['question_id'] ?? null) === ($question['id'] ?? null)
            ));

            $group['questions'][] = $question;
        }
    }
    unset($group);

    $survey['status_label'] = surveyStatusLabel((string)($survey['status'] ?? 'draft'));
    $survey['groups'] = $groups;

    return $survey;
}

function validateSurvey(array $survey, bool $forPublish = false): void
{
    $name = trim((string)($survey['name'] ?? ''));

    if ($name === '') {
        throw new RuntimeException('アンケート名を入力してください。');
    }

    if (mb_strlen($name) > 200) {
        throw new RuntimeException('アンケート名は200文字以内で入力してください。');
    }

    $start = trim((string)($survey['start_date'] ?? ''));
    $end = trim((string)($survey['end_date'] ?? ''));

    if ($start !== '' && !strtotime($start)) {
        throw new RuntimeException('開始日が正しくありません。');
    }

    if ($end !== '' && !strtotime($end)) {
        throw new RuntimeException('終了日が正しくありません。');
    }

    if ($start !== '' && $end !== '' && $start > $end) {
        throw new RuntimeException('公開期間の開始日は終了日以前にしてください。');
    }

    $questions = surveyQuestions((int)$survey['id']);

    foreach ($questions as $question) {
        $type = (string)($question['type'] ?? 'text');

        if (!in_array($type, ['text', 'single', 'multiple'], true)) {
            throw new RuntimeException('質問形式が正しくありません。');
        }

        if (trim((string)($question['text'] ?? '')) === '') {
            throw new RuntimeException('質問文が未入力です。');
        }

        if (in_array($type, ['single', 'multiple'], true)) {
            $options = array_values(array_filter(
                loadOptions(),
                static fn(array $o): bool =>
                    ($o['question_id'] ?? null) === ($question['id'] ?? null)
            ));

            if (count($options) === 0) {
                throw new RuntimeException('選択式質問には選択肢が必要です。');
            }
        }

        if ($type !== 'single') {
            $options = array_values(array_filter(
                loadOptions(),
                static fn(array $o): bool =>
                    ($o['question_id'] ?? null) === ($question['id'] ?? null)
            ));

            foreach ($options as $option) {
                if (!empty($option['branch_target'])) {
                    throw new RuntimeException('分岐は単一選択質問にのみ設定できます。');
                }
            }
        }
    }

    if ($forPublish && count($questions) === 0) {
        throw new RuntimeException('質問を1件以上登録してください。');
    }

    validateBranches($survey);
}

function validateBranches(array $survey): void
{
    $questions = surveyQuestions((int)$survey['id']);
    $questionIds = array_map(
        static fn(array $q): string => (string)$q['id'],
        $questions
    );

    foreach ($questions as $question) {
        if (($question['type'] ?? '') !== 'single') {
            continue;
        }

        $options = array_values(array_filter(
            loadOptions(),
            static fn(array $o): bool =>
                ($o['question_id'] ?? null) === ($question['id'] ?? null)
        ));

        foreach ($options as $option) {
            $target = trim((string)($option['branch_target'] ?? ''));

            if ($target === '' || $target === '__END__') {
                continue;
            }

            if (!in_array($target, $questionIds, true)) {
                throw new RuntimeException('分岐先の質問が存在しません。');
            }

            if ($target === (string)$question['id']) {
                throw new RuntimeException('質問自身への分岐は設定できません。');
            }
        }
    }
}

function kintoneUrl(array $settings, string $path): string
{
    $host = trim((string)($settings['host'] ?? ''));

    $host = preg_replace('#^https?://#i', '', $host);
    $host = preg_replace('#/+$#', '', $host);

    if ($host === '') {
        throw new RuntimeException('kintoneのホストを設定してください。');
    }

    if (!str_contains($host, '.cybozu.com')) {
        $host .= '.cybozu.com';
    }

    return 'https://' . $host . '/' . ltrim($path, '/');
}

function parseProxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (!preg_match('/^([^:]+):([0-9]+)$/', $proxy, $m)) {
        throw new RuntimeException('プロキシは host:port 形式で入力してください。');
    }

    return [
        'host' => $m[1],
        'port' => (int)$m[2],
    ];
}

function kintoneRequest(
    string $method,
    string $url,
    array $settings,
    array $query = [],
    ?array $body = null
): array {
    $method = strtoupper($method);

    if ($query !== []) {
        $query = array_filter(
            $query,
            static fn($v): bool => $v !== null && $v !== ''
        );

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?')
                . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
    }

    $login = trim((string)($settings['login'] ?? ''));
    $password = (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        throw new RuntimeException('kintoneのログイン情報を設定してください。');
    }

    $authorization = base64_encode(trim($login . ':' . $password));

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
    ];

    $content = null;

    if (in_array($method, ['POST', 'PUT', 'DELETE'], true) && $body !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer' => (bool)($settings['verify_ssl'] ?? false),
            'verify_peer_name' => (bool)($settings['verify_ssl'] ?? false),
        ],
    ];

    if ($content !== null) {
        $contextOptions['http']['content'] = $content;
    }

    $proxy = parseProxy((string)($settings['proxy'] ?? ''));

    if ($proxy !== null) {
        $contextOptions['http']['proxy'] = 'tcp://' . $proxy['host'] . ':' . $proxy['port'];
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);
    $response = @file_get_contents($url, false, $context);

    $status = 0;
    $headersOut = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : [];

    if (is_array($headersOut)) {
        foreach ($headersOut as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $m)) {
                $status = (int)$m[1];
            }
        }
    }

    if ($response === false) {
        throw new RuntimeException('kintoneへの接続に失敗しました。');
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('kintoneから正しいJSON応答を取得できませんでした。');
    }

    if (
        $status >= 400 ||
        isset($decoded['code']) ||
        isset($decoded['errors'])
    ) {
        $message = (string)($decoded['message'] ?? 'kintone APIエラー');
        throw new RuntimeException($message);
    }

    return $decoded;
}

function kintoneTest(array $settings): array
{
    $appId = trim((string)($settings['app_id'] ?? ''));

    if ($appId !== '') {
        return kintoneRequest(
            'GET',
            kintoneUrl($settings, 'k/v1/app.json'),
            $settings,
            ['id' => $appId]
        );
    }

    return kintoneRequest(
        'GET',
        kintoneUrl($settings, 'k/v1/apps.json'),
        $settings,
        ['limit' => 1]
    );
}

function kintoneCustomers(array $settings): array
{
    $appId = trim((string)($settings['app_id'] ?? ''));

    if ($appId === '') {
        throw new RuntimeException('kintoneアプリIDを設定してください。');
    }

    $nameField = trim((string)($settings['name_field'] ?? ''));
    $emailField = trim((string)($settings['email_field'] ?? ''));

    if ($nameField === '' || $emailField === '') {
        throw new RuntimeException('顧客名・メールアドレスのフィールドコードを設定してください。');
    }

    $fields = [$nameField, $emailField];

    $data = kintoneRequest(
        'GET',
        kintoneUrl($settings, 'k/v1/records.json'),
        $settings,
        [
            'app' => $appId,
            'fields' => $fields,
            'query' => 'order by $id asc',
            'totalCount' => 'true',
        ]
    );

    $customers = [];

    foreach (($data['records'] ?? []) as $record) {
        $name = (string)($record[$nameField]['value'] ?? '');
        $email = (string)($record[$emailField]['value'] ?? '');

        if ($email === '') {
            continue;
        }

        $customers[] = [
            'id' => (string)($record['$id']['value'] ?? bin2hex(random_bytes(6))),
            'name' => $name,
            'email' => $email,
        ];
    }

    return $customers;
}

function sendMail(
    array $smtp,
    string $to,
    string $subject,
    string $body
): bool {
    $host = trim((string)($smtp['host'] ?? ''));
    $port = (int)($smtp['port'] ?? 0);
    $fromEmail = trim((string)($smtp['from_email'] ?? ''));

    if ($host === '' || $port <= 0 || $fromEmail === '') {
        throw new RuntimeException('SMTP設定が不足しています。');
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $fromEmail,
    ];

    $fromName = trim((string)($smtp['from_name'] ?? ''));

    if ($fromName !== '') {
        $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
    }

    /*
     * SMTP送信は環境側のmail設定を使用する。
     * 認証が必要なSMTPサーバーでは、サーバー側のSMTP設定を利用する。
     * アプリ内に独自SMTPクライアントを実装しない。
     */
    return @mail(
        $to,
        mb_encode_mimeheader($subject, 'UTF-8'),
        $body,
        implode("\r\n", $headers)
    );
}

function answerVisible(
    array $question,
    array $answers,
    array $questions
): bool {
    foreach ($questions as $previous) {
        if (($previous['type'] ?? '') !== 'single') {
            continue;
        }

        $value = $answers[(string)$previous['id']] ?? null;

        if ($value === null || $value === '') {
            continue;
        }

        $options = array_values(array_filter(
            loadOptions(),
            static fn(array $o): bool =>
                ($o['question_id'] ?? null) === ($previous['id'] ?? null)
        ));

        foreach ($options as $option) {
            if ((string)($option['label'] ?? '') !== (string)$value) {
                continue;
            }

            $target = trim((string)($option['branch_target'] ?? ''));

            if ($target === '__END__') {
                return false;
            }

            if ($target !== '' && $target === (string)$question['id']) {
                return true;
            }
        }
    }

    return true;
}

function validateAnswers(
    array $survey,
    array $answers
): void {
    $questions = surveyQuestions((int)$survey['id']);

    foreach ($questions as $question) {
        if (!answerVisible($question, $answers, $questions)) {
            continue;
        }

        $id = (string)$question['id'];
        $value = $answers[$id] ?? null;

        if (!empty($question['required'])) {
            $empty = $value === null ||
                $value === '' ||
                (is_array($value) && count($value) === 0);

            if ($empty) {
                throw new RuntimeException('必須項目に未回答があります。');
            }
        }

        if ($value === null || $value === '') {
            continue;
        }

        $type = (string)($question['type'] ?? 'text');

        if ($type === 'multiple' && !is_array($value)) {
            throw new RuntimeException('複数選択の回答形式が正しくありません。');
        }

        if ($type === 'single' && is_array($value)) {
            throw new RuntimeException('単一選択の回答形式が正しくありません。');
        }

        $options = array_values(array_filter(
            loadOptions(),
            static fn(array $o): bool =>
                ($o['question_id'] ?? null) === ($question['id'] ?? null)
        ));

        $allowed = array_map(
            static fn(array $o): string => (string)($o['label'] ?? ''),
            $options
        );

        if ($type === 'single' && !in_array((string)$value, $allowed, true)) {
            throw new RuntimeException('選択肢にない回答があります。');
        }

        if ($type === 'multiple') {
            foreach ($value as $item) {
                if (!in_array((string)$item, $allowed, true)) {
                    throw new RuntimeException('選択肢にない回答があります。');
                }
            }
        }

        if ($type === 'text' && mb_strlen((string)$value) > 10000) {
            throw new RuntimeException('自由記述は10000文字以内で入力してください。');
        }
    }
}

function bootstrapData(): array
{
    $surveys = loadSurveys();
    $customers = loadCustomers();
    $settings = loadSettings();

    return [
        'csrf' => $_SESSION['yokoyamy_questionnaire_csrf'],
        'surveys' => array_map(
            static function (array $survey): array {
                $id = (int)$survey['id'];
                $responses = array_values(array_filter(
                    loadResponses(),
                    static fn(array $r): bool =>
                        (int)($r['survey_id'] ?? 0) === $id
                ));

                $survey['status_label'] = surveyStatusLabel(
                    (string)($survey['status'] ?? 'draft')
                );
                $survey['response_count'] = count($responses);

                return $survey;
            },
            $surveys
        ),
        'customers' => $customers,
        'settings' => [
            'kintone' => [
                'host' => $settings['kintone']['host'] ?? '',
                'app_id' => $settings['kintone']['app_id'] ?? '',
                'login' => $settings['kintone']['login'] ?? '',
                'name_field' => $settings['kintone']['name_field'] ?? '',
                'email_field' => $settings['kintone']['email_field'] ?? '',
                'proxy' => $settings['kintone']['proxy'] ?? '',
                'verify_ssl' => (bool)($settings['kintone']['verify_ssl'] ?? false),
            ],
            'smtp' => [
                'host' => $settings['smtp']['host'] ?? '',
                'port' => $settings['smtp']['port'] ?? 587,
                'encryption' => $settings['smtp']['encryption'] ?? 'TLS',
                'username' => $settings['smtp']['username'] ?? '',
                'from_email' => $settings['smtp']['from_email'] ?? '',
                'from_name' => $settings['smtp']['from_name'] ?? '',
            ],
        ],
    ];
}

function apiBootstrap(): never
{
    jsonResponse([
        'ok' => true,
        'data' => bootstrapData(),
    ]);
}

function apiSurveyGet(): never
{
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        throw new RuntimeException('アンケートIDが指定されていません。');
    }

    $survey = surveyPayload($id);

    if ($survey === null) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    jsonResponse([
        'ok' => true,
        'survey' => $survey,
    ]);
}

function apiSurveySave(array $data): never
{
    requireCsrf($data);

    $survey = $data['survey'] ?? null;

    if (!is_array($survey)) {
        throw new RuntimeException('アンケートデータがありません。');
    }

    $surveys = loadSurveys();

    $id = (int)($survey['id'] ?? 0);
    $existing = $id > 0 ? findById($surveys, $id) : null;

    if ($existing && in_array(
        (string)($existing['status'] ?? 'draft'),
        ['published', 'closed'],
        true
    )) {
        throw new RuntimeException('公開済みまたは終了済みのアンケートは編集できません。');
    }

    if ($id <= 0) {
        $id = nextId($surveys);
    }

    $record = [
        'id' => $id,
        'name' => trim((string)($survey['name'] ?? '')),
        'description' => trim((string)($survey['description'] ?? '')),
        'status' => normalizeSurveyStatus((string)($survey['status'] ?? 'draft')),
        'start_date' => trim((string)($survey['start_date'] ?? '')),
        'end_date' => trim((string)($survey['end_date'] ?? '')),
        'numbering_format' => (($survey['numbering_format'] ?? 'group') === 'global')
            ? 'global'
            : 'group',
        'created_at' => $existing['created_at'] ?? nowDateTime(),
        'updated_at' => nowDateTime(),
    ];

    if ($record['status'] !== 'draft') {
        $record['status'] = $existing['status'] ?? 'draft';
    }

    $tmpGroups = $survey['groups'] ?? [];

    if (!is_array($tmpGroups)) {
        $tmpGroups = [];
    }

    $groups = loadGroups();
    $questions = loadQuestions();
    $options = loadOptions();

    $oldGroupIds = array_map(
        static fn(array $g): string => (string)$g['id'],
        array_filter(
            $groups,
            static fn(array $g): bool =>
                (int)($g['survey_id'] ?? 0) === $id
        )
    );

    $newGroupIds = [];

    foreach ($tmpGroups as $groupIndex => $group) {
        if (!is_array($group)) {
            continue;
        }

        $groupId = (string)($group['id'] ?? '');

        if ($groupId === '') {
            $groupId = 'g_' . bin2hex(random_bytes(6));
        }

        $newGroupIds[] = $groupId;

        $found = false;

        foreach ($groups as &$existingGroup) {
            if (($existingGroup['id'] ?? null) === $groupId) {
                $existingGroup['survey_id'] = $id;
                $existingGroup['name'] = trim((string)($group['name'] ?? ''));
                $existingGroup['display_order'] = $groupIndex + 1;
                $found = true;
                break;
            }
        }
        unset($existingGroup);

        if (!$found) {
            $groups[] = [
                'id' => $groupId,
                'survey_id' => $id,
                'name' => trim((string)($group['name'] ?? '')),
                'display_order' => $groupIndex + 1,
            ];
        }

        $newQuestionIds = [];

        foreach (($group['questions'] ?? []) as $questionIndex => $question) {
            if (!is_array($question)) {
                continue;
            }

            $questionId = (string)($question['id'] ?? '');

            if ($questionId === '') {
                $questionId = 'q_' . bin2hex(random_bytes(6));
            }

            $newQuestionIds[] = $questionId;

            $questionRecord = [
                'id' => $questionId,
                'group_id' => $groupId,
                'text' => trim((string)($question['text'] ?? '')),
                'type' => in_array(
                    ($question['type'] ?? 'text'),
                    ['text', 'single', 'multiple'],
                    true
                ) ? $question['type'] : 'text',
                'required' => !empty($question['required']),
                'display_order' => $questionIndex + 1,
            ];

            $foundQuestion = false;

            foreach ($questions as &$existingQuestion) {
                if (($existingQuestion['id'] ?? null) === $questionId) {
                    $existingQuestion = $questionRecord;
                    $foundQuestion = true;
                    break;
                }
            }
            unset($existingQuestion);

            if (!$foundQuestion) {
                $questions[] = $questionRecord;
            }

            $newOptionIds = [];

            foreach (($question['options'] ?? []) as $optionIndex => $option) {
                if (!is_array($option)) {
                    continue;
                }

                $optionId = (string)($option['id'] ?? '');

                if ($optionId === '') {
                    $optionId = 'o_' . bin2hex(random_bytes(6));
                }

                $newOptionIds[] = $optionId;

                $optionRecord = [
                    'id' => $optionId,
                    'question_id' => $questionId,
                    'label' => trim((string)($option['label'] ?? '')),
                    'display_order' => $optionIndex + 1,
                    'branch_target' => trim((string)($option['branch_target'] ?? '')),
                ];

                $foundOption = false;

                foreach ($options as &$existingOption) {
                    if (($existingOption['id'] ?? null) === $optionId) {
                        $existingOption = $optionRecord;
                        $foundOption = true;
                        break;
                    }
                }
                unset($existingOption);

                if (!$foundOption) {
                    $options[] = $optionRecord;
                }
            }

            $options = array_values(array_filter(
                $options,
                static fn(array $o): bool =>
                    ($o['question_id'] ?? null) !== $questionId ||
                    in_array((string)$o['id'], $newOptionIds, true)
            ));
        }

        $questions = array_values(array_filter(
            $questions,
            static fn(array $q): bool =>
                ($q['group_id'] ?? null) !== $groupId ||
                in_array((string)$q['id'], $newQuestionIds, true)
        ));
    }

    $groups = array_values(array_filter(
        $groups,
        static fn(array $g): bool =>
            (int)($g['survey_id'] ?? 0) !== $id ||
            in_array((string)$g['id'], $newGroupIds, true)
    ));

    $questions = array_values(array_filter(
        $questions,
        static function (array $q) use ($newGroupIds): bool {
            if (!in_array((string)($q['group_id'] ?? ''), $newGroupIds, true)) {
                return true;
            }
            return true;
        }
    ));

    $currentGroupIds = $newGroupIds;

    $questions = array_values(array_filter(
        $questions,
        static function (array $q) use ($currentGroupIds): bool {
            $groupId = (string)($q['group_id'] ?? '');
            return !in_array($groupId, $currentGroupIds, true)
                || $groupId !== '';
        }
    ));

    validateSurvey($record);

    $replaced = false;

    foreach ($surveys as &$existingSurvey) {
        if ((int)($existingSurvey['id'] ?? 0) === $id) {
            $existingSurvey = $record;
            $replaced = true;
            break;
        }
    }
    unset($existingSurvey);

    if (!$replaced) {
        $surveys[] = $record;
    }

    saveSurveys($surveys);
    saveGroups($groups);
    saveQuestions($questions);
    saveOptions($options);

    jsonResponse([
        'ok' => true,
        'survey' => surveyPayload($id),
    ]);
}

function apiSurveyPublish(array $data): never
{
    requireCsrf($data);

    $id = (int)($data['id'] ?? 0);
    $survey = findSurvey($id);

    if (!$survey) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    if (($survey['status'] ?? '') !== 'draft') {
        throw new RuntimeException('下書き状態のアンケートだけ公開できます。');
    }

    validateSurvey($survey, true);

    $surveys = loadSurveys();

    foreach ($surveys as &$item) {
        if ((int)$item['id'] === $id) {
            $item['status'] = 'published';
            $item['updated_at'] = nowDateTime();
        }
    }
    unset($item);

    saveSurveys($surveys);

    jsonResponse([
        'ok' => true,
        'survey' => surveyPayload($id),
    ]);
}

function apiSurveyClose(array $data): never
{
    requireCsrf($data);

    $id = (int)($data['id'] ?? 0);
    $survey = findSurvey($id);

    if (!$survey) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    if (($survey['status'] ?? '') !== 'published') {
        throw new RuntimeException('公開中のアンケートだけ終了できます。');
    }

    $surveys = loadSurveys();

    foreach ($surveys as &$item) {
        if ((int)$item['id'] === $id) {
            $item['status'] = 'closed';
            $item['updated_at'] = nowDateTime();
        }
    }
    unset($item);

    saveSurveys($surveys);

    jsonResponse([
        'ok' => true,
        'survey' => surveyPayload($id),
    ]);
}

function apiSurveyDelete(array $data): never
{
    requireCsrf($data);

    $id = (int)($data['id'] ?? 0);
    $survey = findSurvey($id);

    if (!$survey) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    if (($survey['status'] ?? 'draft') !== 'draft') {
        throw new RuntimeException('下書きだけ削除できます。');
    }

    saveSurveys(array_values(array_filter(
        loadSurveys(),
        static fn(array $s): bool => (int)$s['id'] !== $id
    )));

    $groupIds = array_column(surveyGroups($id), 'id');
    $questionIds = array_column(surveyQuestions($id), 'id');

    saveGroups(array_values(array_filter(
        loadGroups(),
        static fn(array $g): bool => !in_array($g['id'] ?? null, $groupIds, true)
    )));

    saveQuestions(array_values(array_filter(
        loadQuestions(),
        static fn(array $q): bool => !in_array($q['id'] ?? null, $questionIds, true)
    )));

    saveOptions(array_values(array_filter(
        loadOptions(),
        static fn(array $o): bool => !in_array($o['question_id'] ?? null, $questionIds, true)
    )));

    jsonResponse(['ok' => true]);
}

function apiCustomersSync(array $data): never
{
    requireCsrf($data);

    $settings = loadSettings();
    $customers = kintoneCustomers($settings['kintone'] ?? []);

    writeJson('customers', $customers);

    jsonResponse([
        'ok' => true,
        'customers' => $customers,
    ]);
}

function apiSettingsSave(array $data): never
{
    requireCsrf($data);

    $current = loadSettings();

    $kintone = $data['kintone'] ?? [];
    $smtp = $data['smtp'] ?? [];

    if (!is_array($kintone)) {
        $kintone = [];
    }

    if (!is_array($smtp)) {
        $smtp = [];
    }

    $current['kintone']['host'] = trim((string)($kintone['host'] ?? ''));
    $current['kintone']['app_id'] = trim((string)($kintone['app_id'] ?? ''));
    $current['kintone']['login'] = trim((string)($kintone['login'] ?? ''));
    $current['kintone']['name_field'] = trim((string)($kintone['name_field'] ?? ''));
    $current['kintone']['email_field'] = trim((string)($kintone['email_field'] ?? ''));
    $current['kintone']['proxy'] = trim((string)($kintone['proxy'] ?? ''));
    $current['kintone']['verify_ssl'] = !empty($kintone['verify_ssl']);

    if (!empty($kintone['password'])) {
        $current['kintone']['password'] = (string)$kintone['password'];
    }

    $current['smtp']['host'] = trim((string)($smtp['host'] ?? ''));
    $current['smtp']['port'] = max(1, (int)($smtp['port'] ?? 587));
    $current['smtp']['encryption'] = in_array(
        ($smtp['encryption'] ?? 'TLS'),
        ['None', 'SSL', 'TLS'],
        true
    ) ? $smtp['encryption'] : 'TLS';
    $current['smtp']['username'] = trim((string)($smtp['username'] ?? ''));
    $current['smtp']['from_email'] = trim((string)($smtp['from_email'] ?? ''));
    $current['smtp']['from_name'] = trim((string)($smtp['from_name'] ?? ''));

    if (!empty($smtp['password'])) {
        $current['smtp']['password'] = (string)$smtp['password'];
    }

    writeJson('settings', $current);

    jsonResponse([
        'ok' => true,
        'settings' => [
            'kintone' => [
                'host' => $current['kintone']['host'],
                'app_id' => $current['kintone']['app_id'],
                'login' => $current['kintone']['login'],
                'name_field' => $current['kintone']['name_field'],
                'email_field' => $current['kintone']['email_field'],
                'proxy' => $current['kintone']['proxy'],
                'verify_ssl' => $current['kintone']['verify_ssl'],
            ],
            'smtp' => [
                'host' => $current['smtp']['host'],
                'port' => $current['smtp']['port'],
                'encryption' => $current['smtp']['encryption'],
                'username' => $current['smtp']['username'],
                'from_email' => $current['smtp']['from_email'],
                'from_name' => $current['smtp']['from_name'],
            ],
        ],
    ]);
}

function apiKintoneTest(array $data): never
{
    requireCsrf($data);

    $settings = loadSettings();
    kintoneTest($settings['kintone'] ?? []);

    jsonResponse([
        'ok' => true,
        'message' => 'kintoneへの接続に成功しました。',
    ]);
}

function apiMailSend(array $data): never
{
    requireCsrf($data);

    $surveyId = (int)($data['survey_id'] ?? 0);
    $customerIds = $data['customer_ids'] ?? [];

    if (!is_array($customerIds) || count($customerIds) === 0) {
        throw new RuntimeException('送信先を選択してください。');
    }

    $survey = findSurvey($surveyId);

    if (!$survey) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    if (($survey['status'] ?? '') !== 'published') {
        throw new RuntimeException('公開中のアンケートだけ送信できます。');
    }

    $customers = loadCustomers();
    $recipients = loadRecipients();
    $logs = loadMailLogs();
    $settings = loadSettings();

    $subject = trim((string)($data['subject'] ?? ''));
    $body = (string)($data['body'] ?? '');

    if ($subject === '') {
        throw new RuntimeException('メール件名を入力してください。');
    }

    if ($body === '') {
        throw new RuntimeException('メール本文を入力してください。');
    }

    $results = [];

    foreach ($customerIds as $customerId) {
        $customer = null;

        foreach ($customers as $item) {
            if ((string)($item['id'] ?? '') === (string)$customerId) {
                $customer = $item;
                break;
            }
        }

        if (!$customer) {
            $results[] = [
                'customer_id' => $customerId,
                'success' => false,
                'error' => '顧客が存在しません。',
            ];
            continue;
        }

        $email = trim((string)($customer['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $results[] = [
                'customer_id' => $customerId,
                'success' => false,
                'error' => 'メールアドレスが正しくありません。',
            ];
            continue;
        }

        $alreadySent = false;

        foreach ($recipients as $recipient) {
            if (
                (int)($recipient['survey_id'] ?? 0) === $surveyId &&
                (string)($recipient['customer_id'] ?? '') === (string)$customerId &&
                ($recipient['send_status'] ?? '') === 'success'
            ) {
                $alreadySent = true;
                break;
            }
        }

        if ($alreadySent) {
            $results[] = [
                'customer_id' => $customerId,
                'success' => false,
                'error' => 'すでに送信済みです。',
            ];
            continue;
        }

        $personalBody = str_replace(
            ['{{name}}', '{{answer_url}}'],
            [
                (string)($customer['name'] ?? ''),
                '',
            ],
            $body
        );

        try {
            $success = sendMail(
                $settings['smtp'] ?? [],
                $email,
                $subject,
                $personalBody
            );

            $error = $success ? '' : 'メール送信に失敗しました。';

            $recipientId = nextId($recipients);

            $recipients[] = [
                'id' => $recipientId,
                'survey_id' => $surveyId,
                'customer_id' => (string)$customerId,
                'send_status' => $success ? 'success' : 'failed',
                'send_time' => nowDateTime(),
                'response_status' => 'unanswered',
            ];

            $logs[] = [
                'id' => nextId($logs),
                'recipient_id' => $recipientId,
                'subject' => $subject,
                'body' => $personalBody,
                'send_time' => nowDateTime(),
                'success' => $success,
                'error' => $error,
                'retry_source_id' => null,
            ];

            $results[] = [
                'customer_id' => $customerId,
                'success' => $success,
                'error' => $error,
            ];
        } catch (Throwable $e) {
            $results[] = [
                'customer_id' => $customerId,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    saveRecipients($recipients);
    saveMailLogs($logs);

    jsonResponse([
        'ok' => true,
        'results' => $results,
    ]);
}

function apiAnswerSubmit(array $data): never
{
    requireCsrf($data);

    $surveyId = (int)($data['survey_id'] ?? 0);
    $recipientId = $data['recipient_id'] ?? null;
    $answers = $data['answers'] ?? [];

    if (!is_array($answers)) {
        throw new RuntimeException('回答データが正しくありません。');
    }

    $survey = findSurvey($surveyId);

    if (!$survey) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    if (($survey['status'] ?? '') !== 'published') {
        throw new RuntimeException('このアンケートは現在回答を受け付けていません。');
    }

    $today = nowDate();

    if (
        !empty($survey['start_date']) &&
        $today < (string)$survey['start_date']
    ) {
        throw new RuntimeException('回答受付期間外です。');
    }

    if (
        !empty($survey['end_date']) &&
        $today > (string)$survey['end_date']
    ) {
        throw new RuntimeException('回答受付期間外です。');
    }

    validateAnswers($survey, $answers);

    $responses = loadResponses();

    foreach ($responses as $response) {
        if (
            (int)($response['survey_id'] ?? 0) === $surveyId &&
            $recipientId !== null &&
            (string)($response['recipient_id'] ?? '') === (string)$recipientId
        ) {
            throw new RuntimeException('このアンケートはすでに回答済みです。');
        }
    }

    $responseId = nextId($responses);

    $responses[] = [
        'id' => $responseId,
        'survey_id' => $surveyId,
        'recipient_id' => $recipientId,
        'answer_time' => nowDateTime(),
        'completion' => true,
    ];

    $responseAnswers = loadResponseAnswers();

    foreach ($answers as $questionId => $answerValue) {
        if (is_array($answerValue)) {
            $answerValue = json_encode(
                array_values($answerValue),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        $responseAnswers[] = [
            'id' => nextId($responseAnswers),
            'response_id' => $responseId,
            'question_id' => (string)$questionId,
            'answer_value' => (string)$answerValue,
        ];
    }

    saveResponses($responses);
    saveResponseAnswers($responseAnswers);

    if ($recipientId !== null) {
        $recipients = loadRecipients();

        foreach ($recipients as &$recipient) {
            if ((string)($recipient['id'] ?? '') === (string)$recipientId) {
                $recipient['response_status'] = 'answered';
            }
        }
        unset($recipient);

        saveRecipients($recipients);
    }

    jsonResponse([
        'ok' => true,
        'response_id' => $responseId,
    ]);
}

function apiResult(int $surveyId): never
{
    $survey = findSurvey($surveyId);

    if (!$survey) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    $questions = surveyQuestions($surveyId);
    $responses = array_values(array_filter(
        loadResponses(),
        static fn(array $r): bool =>
            (int)($r['survey_id'] ?? 0) === $surveyId
    ));
    $answers = loadResponseAnswers();

    $result = [];

    foreach ($questions as $question) {
        $qid = (string)$question['id'];

        $questionAnswers = array_values(array_filter(
            $answers,
            static fn(array $a): bool =>
                (string)($a['question_id'] ?? '') === $qid &&
                in_array(
                    (int)($a['response_id'] ?? 0),
                    array_map(
                        static fn(array $r): int => (int)$r['id'],
                        $responses
                    ),
                    true
                )
        ));

        $item = [
            'question_id' => $qid,
            'text' => $question['text'],
            'type' => $question['type'],
            'count' => count($questionAnswers),
            'values' => [],
        ];

        if ($question['type'] === 'text') {
            foreach ($questionAnswers as $answer) {
                $item['values'][] = $answer['answer_value'];
            }
        } else {
            $counts = [];

            foreach ($questionAnswers as $answer) {
                $values = [$answer['answer_value']];

                if ($question['type'] === 'multiple') {
                    $decoded = json_decode(
                        (string)$answer['answer_value'],
                        true
                    );

                    if (is_array($decoded)) {
                        $values = $decoded;
                    }
                }

                foreach ($values as $value) {
                    $value = (string)$value;
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }

            $item['values'] = $counts;
        }

        $result[] = $item;
    }

    jsonResponse([
        'ok' => true,
        'survey' => $survey,
        'response_count' => count($responses),
        'result' => $result,
    ]);
}

function handleApi(): never
{
    $api = (string)($_GET['api'] ?? '');

    try {
        switch ($api) {
            case 'bootstrap':
                apiBootstrap();

            case 'survey_get':
                apiSurveyGet();

            case 'survey_save':
                apiSurveySave(inputJson());

            case 'survey_publish':
                apiSurveyPublish(inputJson());

            case 'survey_close':
                apiSurveyClose(inputJson());

            case 'survey_delete':
                apiSurveyDelete(inputJson());

            case 'customers_sync':
                apiCustomersSync(inputJson());

            case 'settings_save':
                apiSettingsSave(inputJson());

            case 'kintone_test':
                apiKintoneTest(inputJson());

            case 'mail_send':
                apiMailSend(inputJson());

            case 'answer_submit':
                apiAnswerSubmit(inputJson());

            case 'result':
                apiResult((int)($_GET['survey_id'] ?? 0));

            default:
                jsonResponse([
                    'ok' => false,
                    'error' => '指定されたAPIは存在しません。',
                ], 404);
        }
    } catch (Throwable $e) {
        jsonResponse([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 400);
    }
}

if (isApiRequest()) {
    handleApi();
}

$csrf = $_SESSION['yokoyamy_questionnaire_csrf'];

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(APP_NAME) ?></title>
<style>
:root{
    --primary:#2563eb;
    --primary-hover:#1d4ed8;
    --bg:#f8fafc;
    --surface:#fff;
    --border:#e2e8f0;
    --text:#1e293b;
    --muted:#64748b;
    --success:#16a34a;
    --danger:#dc2626;
    --warning:#d97706;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
header{
    background:#fff;
    border-bottom:1px solid var(--border);
    position:sticky;
    top:0;
    z-index:20;
}
.header-inner{
    max-width:1200px;
    margin:auto;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:0 16px;
}
.logo{
    font-weight:700;
    color:var(--primary);
    cursor:pointer;
}
nav{display:flex;gap:4px}
nav button{
    border:0;
    background:#fff;
    padding:20px 12px;
    color:var(--muted);
}
nav button.active{
    color:var(--primary);
    border-bottom:2px solid var(--primary);
}
.subnav{
    display:none;
    background:#f1f5f9;
    border-bottom:1px solid var(--border);
}
.subnav-inner{
    max-width:1200px;
    margin:auto;
    padding:8px 16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}
.subnav-buttons{display:flex;gap:6px;flex-wrap:wrap}
.subnav button{
    border:1px solid var(--border);
    background:#fff;
    border-radius:6px;
    padding:7px 12px;
}
.subnav button.active{
    background:var(--primary);
    color:#fff;
}
main{
    max-width:1200px;
    margin:24px auto;
    padding:0 16px 60px;
}
.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:8px;
    padding:20px;
    margin-bottom:18px;
}
.card-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:18px;
}
h1,h2,h3{margin-top:0}
h2{font-size:20px}
h3{font-size:16px}
.btn{
    border:1px solid transparent;
    border-radius:6px;
    padding:8px 14px;
    background:#fff;
}
.btn-primary{
    background:var(--primary);
    color:#fff;
}
.btn-primary:hover{background:var(--primary-hover)}
.btn-outline{
    border-color:var(--border);
    color:var(--text);
}
.btn-danger{
    background:var(--danger);
    color:#fff;
}
.btn-sm{
    padding:5px 9px;
    font-size:13px;
}
.btn:disabled{
    opacity:.55;
    cursor:not-allowed;
}
.loading{
    position:relative;
}
.loading::after{
    content:"";
    width:13px;
    height:13px;
    margin-left:7px;
    border:2px solid currentColor;
    border-right-color:transparent;
    border-radius:50%;
    display:inline-block;
    vertical-align:-2px;
    animation:spin .7s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    padding:10px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}
th{
    color:var(--muted);
    background:#f8fafc;
    font-size:13px;
}
.badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    font-size:12px;
    font-weight:600;
}
.badge-draft{background:#e2e8f0;color:#475569}
.badge-published{background:#dcfce7;color:#15803d}
.badge-closed{background:#fee2e2;color:#b91c1c}
.form-group{margin-bottom:16px}
label{
    display:block;
    font-size:13px;
    font-weight:600;
    margin-bottom:6px;
}
input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=date],
textarea,
select{
    width:100%;
    border:1px solid var(--border);
    border-radius:6px;
    padding:9px 10px;
    background:#fff;
}
textarea{min-height:110px;resize:vertical}
.form-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}
.toolbar{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
}
.muted{color:var(--muted)}
.empty{
    padding:40px;
    text-align:center;
    color:var(--muted);
}
.group-card{
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:8px;
    padding:16px;
    margin-bottom:16px;
}
.question-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:7px;
    padding:14px;
    margin-top:10px;
}
.option-row{
    display:grid;
    grid-template-columns:1fr 1fr auto;
    gap:8px;
    margin-bottom:8px;
}
.choice-list{margin-top:10px}
.stats{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
}
.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:8px;
    padding:18px;
}
.stat strong{
    display:block;
    font-size:25px;
    color:var(--primary);
}
.customer-list{
    max-height:420px;
    overflow:auto;
    border:1px solid var(--border);
    border-radius:6px;
}
.customer-row{
    display:flex;
    gap:10px;
    align-items:center;
    padding:10px;
    border-bottom:1px solid var(--border);
}
.customer-row:last-child{border-bottom:0}
.preview{
    max-width:720px;
    margin:20px auto;
}
.answer-question{
    margin-bottom:24px;
}
.answer-option{
    display:block;
    margin:9px 0;
}
.toast{
    position:fixed;
    right:20px;
    bottom:20px;
    z-index:100;
    background:#334155;
    color:#fff;
    padding:12px 16px;
    border-radius:7px;
    display:none;
}
.error{
    color:var(--danger);
    background:#fef2f2;
    border:1px solid #fecaca;
    border-radius:6px;
    padding:10px;
    margin-bottom:14px;
}
.success{
    color:var(--success);
    background:#f0fdf4;
    border:1px solid #bbf7d0;
    border-radius:6px;
    padding:10px;
}
@media(max-width:800px){
    .header-inner,.subnav-inner{flex-direction:column;align-items:stretch}
    nav{overflow:auto}
    nav button{padding:12px 8px}
    .form-row,.stats{grid-template-columns:1fr}
    table{font-size:13px}
    th,td{padding:7px}
}
</style>
</head>
<body>

<header id="appHeader">
    <div class="header-inner">
        <div class="logo" id="logoButton">📋 アンケート業務運営アプリ</div>
        <nav>
            <button id="navSurveys" type="button">アンケート一覧</button>
            <button id="navNew" type="button">新規作成</button>
            <button id="navCustomers" type="button">顧客一覧</button>
            <button id="navSettings" type="button">設定</button>
        </nav>
    </div>

    <div id="subnav" class="subnav">
        <div class="subnav-inner">
            <strong id="subnavTitle"></strong>
            <div class="subnav-buttons">
                <button id="subDetail" type="button">アンケート内容</button>
                <button id="subSend" type="button">送信</button>
                <button id="subStatus" type="button">回答状況</button>
                <button id="subResult" type="button">回答結果</button>
                <button id="subPreview" type="button">回答画面</button>
            </div>
        </div>
    </div>
</header>

<main id="main"></main>
<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const state = {
        view: 'survey-list',
        surveyId: null,
        subView: 'detail',
        data: {
            surveys: [],
            customers: [],
            settings: {
                kintone: {},
                smtp: {}
            }
        },
        draft: null
    };

    const main = document.getElementById('main');
    const subnav = document.getElementById('subnav');
    const subnavTitle = document.getElementById('subnavTitle');
    const toast = document.getElementById('toast');

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.style.display = 'block';
        window.setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function apiUrl(name, params = {}) {
        const url = new URL(window.location.href);
        url.search = '';
        url.hash = '';
        url.searchParams.set('api', name);

        Object.entries(params).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') {
                url.searchParams.set(key, String(value));
            }
        });

        return url.toString();
    }

    async function api(name, options = {}, button = null) {
        const config = {
            method: options.method || 'GET',
            headers: {
                'Accept': 'application/json'
            }
        };

        if (options.body !== undefined) {
            config.headers['Content-Type'] = 'application/json';
            config.body = JSON.stringify({
                ...options.body,
                csrf: CSRF
            });
        }

        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }

        try {
            const response = await fetch(apiUrl(name, options.params || {}), config);
            const data = await response.json().catch(() => null);

            if (!response.ok || !data || data.ok !== true) {
                throw new Error(
                    data?.error ||
                    `通信に失敗しました（HTTP ${response.status}）`
                );
            }

            return data;
        } finally {
            if (button) {
                button.disabled = false;
                button.classList.remove('loading');
            }
        }
    }

    function getSurvey(id = state.surveyId) {
        return state.data.surveys.find(
            survey => Number(survey.id) === Number(id)
        ) || null;
    }

    function statusBadge(status) {
        const cls =
            status === 'published'
                ? 'badge-published'
                : status === 'closed'
                    ? 'badge-closed'
                    : 'badge-draft';

        return `<span class="badge ${cls}">${escapeHtml(
            status === 'published'
                ? '公開中'
                : status === 'closed'
                    ? '終了'
                    : '下書き'
        )}</span>`;
    }

    function setActiveNav() {
        document.querySelectorAll('nav button').forEach(button => {
            if (button) button.classList.remove('active');
        });

        const map = {
            'survey-list': 'navSurveys',
            'survey-editor': 'navNew',
            'customer-list': 'navCustomers',
            'settings': 'navSettings'
        };

        const id = map[state.view];

        if (id) {
            const button = document.getElementById(id);
            if (button) button.classList.add('active');
        }
    }

    function setSubnav() {
        const survey = getSurvey();

        const visible = [
            'detail',
            'send',
            'status',
            'result'
        ].includes(state.view);

        if (!visible || !survey) {
            subnav.style.display = 'none';
            return;
        }

        subnav.style.display = 'block';
        subnavTitle.textContent = survey.name || '';

        document.querySelectorAll('.subnav button').forEach(button => {
            if (button) button.classList.remove('active');
        });

        const id = {
            detail: 'subDetail',
            send: 'subSend',
            status: 'subStatus',
            result: 'subResult'
        }[state.view];

        const button = document.getElementById(id);

        if (button) {
            button.classList.add('active');
        }
    }

    function navigate(view, surveyId = null) {
        state.view = view;

        if (surveyId !== null) {
            state.surveyId = Number(surveyId);
        }

        setActiveNav();
        setSubnav();
        render();
        window.scrollTo({top: 0, behavior: 'smooth'});
    }

    function navigateSub(view) {
        if (!state.surveyId) return;
        state.view = view;
        state.subView = view;
        setActiveNav();
        setSubnav();
        render();
    }

    function render() {
        switch (state.view) {
            case 'survey-list':
                renderSurveyList();
                break;
            case 'survey-editor':
                renderSurveyEditor();
                break;
            case 'customer-list':
                renderCustomerList();
                break;
            case 'settings':
                renderSettings();
                break;
            case 'detail':
                renderSurveyDetail();
                break;
            case 'send':
                renderSend();
                break;
            case 'status':
                renderStatus();
                break;
            case 'result':
                renderResult();
                break;
            case 'respondent':
                renderRespondent();
                break;
            default:
                navigate('survey-list');
        }
    }

    async function loadBootstrap() {
        const data = await api('bootstrap');

        state.data = data.data || {
            surveys: [],
            customers: [],
            settings: {
                kintone: {},
                smtp: {}
            }
        };

        render();
    }

    function renderSurveyList() {
        const surveys = state.data.surveys || [];

        main.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>アンケート一覧</h2>
                        <div class="muted">作成・公開・回答状況を管理します。</div>
                    </div>
                    <button id="createSurvey" class="btn btn-primary" type="button">
                        ＋ 新規アンケート
                    </button>
                </div>

                ${
                    surveys.length === 0
                        ? `<div class="empty">アンケートがありません。</div>`
                        : `
                        <table>
                            <thead>
                                <tr>
                                    <th>アンケート名</th>
                                    <th>状態</th>
                                    <th>公開期間</th>
                                    <th>回答数</th>
                                    <th>更新日</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${surveys.map(survey => `
                                    <tr>
                                        <td>
                                            <button
                                                class="btn btn-outline btn-sm survey-open"
                                                type="button"
                                                data-id="${Number(survey.id)}"
                                            >
                                                ${escapeHtml(survey.name || '(名称未設定)')}
                                            </button>
                                        </td>
                                        <td>${statusBadge(survey.status)}</td>
                                        <td>
                                            ${escapeHtml(survey.start_date || '')}
                                            〜
                                            ${escapeHtml(survey.end_date || '')}
                                        </td>
                                        <td>${Number(survey.response_count || 0)} 件</td>
                                        <td>${escapeHtml(survey.updated_at || '')}</td>
                                        <td>
                                            <div class="toolbar">
                                                <button
                                                    class="btn btn-outline btn-sm survey-open"
                                                    type="button"
                                                    data-id="${Number(survey.id)}"
                                                >管理</button>

                                                ${
                                                    survey.status === 'draft'
                                                        ? `
                                                        <button
                                                            class="btn btn-primary btn-sm publish-survey"
                                                            type="button"
                                                            data-id="${Number(survey.id)}"
                                                        >公開</button>

                                                        <button
                                                            class="btn btn-outline btn-sm delete-survey"
                                                            type="button"
                                                            data-id="${Number(survey.id)}"
                                                        >削除</button>
                                                        `
                                                        : ''
                                                }

                                                ${
                                                    survey.status === 'published'
                                                        ? `
                                                        <button
                                                            class="btn btn-outline btn-sm close-survey"
                                                            type="button"
                                                            data-id="${Number(survey.id)}"
                                                        >終了</button>
                                                        `
                                                        : ''
                                                }
                                            </div>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `
                }
            </div>
        `;

        const createButton = document.getElementById('createSurvey');

        if (createButton) {
            createButton.addEventListener('click', () => {
                createNewSurvey();
            });
        }

        document.querySelectorAll('.survey-open').forEach(button => {
            button.addEventListener('click', () => {
                const id = Number(button.dataset.id);
                state.surveyId = id;
                navigate('detail', id);
            });
        });

        document.querySelectorAll('.publish-survey').forEach(button => {
            button.addEventListener('click', async () => {
                if (!window.confirm('このアンケートを公開しますか？')) {
                    return;
                }

                try {
                    await api(
                        'survey_publish',
                        {
                            method: 'POST',
                            body: {
                                id: Number(button.dataset.id)
                            }
                        },
                        button
                    );

                    await loadBootstrap();
                    showToast('アンケートを公開しました。');
                } catch (error) {
                    window.alert(error.message);
                }
            });
        });

        document.querySelectorAll('.close-survey').forEach(button => {
            button.addEventListener('click', async () => {
                if (!window.confirm('回答受付を終了しますか？')) {
                    return;
                }

                try {
                    await api(
                        'survey_close',
                        {
                            method: 'POST',
                            body: {
                                id: Number(button.dataset.id)
                            }
                        },
                        button
                    );

                    await loadBootstrap();
                    showToast('アンケートを終了しました。');
                } catch (error) {
                    window.alert(error.message);
                }
            });
        });

        document.querySelectorAll('.delete-survey').forEach(button => {
            button.addEventListener('click', async () => {
                if (!window.confirm('この下書きを削除しますか？')) {
                    return;
                }

                try {
                    await api(
                        'survey_delete',
                        {
                            method: 'POST',
                            body: {
                                id: Number(button.dataset.id)
                            }
                        },
                        button
                    );

                    await loadBootstrap();
                    showToast('削除しました。');
                } catch (error) {
                    window.alert(error.message);
                }
            });
        });
    }

    function createNewSurvey() {
        state.surveyId = null;
        state.draft = {
            id: 0,
            name: '',
            description: '',
            status: 'draft',
            start_date: '',
            end_date: '',
            numbering_format: 'group',
            groups: [
                {
                    id: '',
                    name: '基本情報',
                    questions: [
                        {
                            id: '',
                            text: '',
                            type: 'text',
                            required: true,
                            options: []
                        }
                    ]
                }
            ]
        };

        navigate('survey-editor');
    }

    async function openEditor(id) {
        try {
            const data = await api(
                'survey_get',
                {
                    params: {id}
                }
            );

            state.surveyId = id;
            state.draft = data.survey;
            navigate('survey-editor');
        } catch (error) {
            window.alert(error.message);
        }
    }

    function renderSurveyEditor() {
        if (!state.draft) {
            createNewSurvey();
            return;
        }

        const draft = state.draft;

        main.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>${draft.id ? 'アンケート編集' : '新規アンケート作成'}</h2>
                        <div class="muted">
                            ${draft.status === 'published' ? '公開済み' : '下書き'}
                        </div>
                    </div>
                    <div class="toolbar">
                        <button id="cancelEditor" class="btn btn-outline" type="button">
                            戻る
                        </button>
                        <button id="saveSurvey" class="btn btn-primary" type="button">
                            保存
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="surveyName">アンケート名 *</label>
                    <input id="surveyName" type="text" value="${escapeHtml(draft.name || '')}">
                </div>

                <div class="form-group">
                    <label for="surveyDescription">説明</label>
                    <textarea id="surveyDescription">${escapeHtml(draft.description || '')}</textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="surveyStart">開始日</label>
                        <input id="surveyStart" type="date" value="${escapeHtml(draft.start_date || '')}">
                    </div>
                    <div class="form-group">
                        <label for="surveyEnd">終了日</label>
                        <input id="surveyEnd" type="date" value="${escapeHtml(draft.end_date || '')}">
                    </div>
                </div>

                <div class="form-group">
                    <label for="numberingFormat">質問番号</label>
                    <select id="numberingFormat">
                        <option value="group" ${draft.numbering_format === 'group' ? 'selected' : ''}>
                            グループごと
                        </option>
                        <option value="global" ${draft.numbering_format === 'global' ? 'selected' : ''}>
                            全体で連番
                        </option>
                    </select>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>質問</h2>
                    <button id="addGroup" class="btn btn-outline" type="button">
                        ＋ グループ追加
                    </button>
                </div>

                <div id="groupContainer"></div>
            </div>
        `;

        const cancelButton = document.getElementById('cancelEditor');
        const saveButton = document.getElementById('saveSurvey');
        const addGroupButton = document.getElementById('addGroup');

        if (cancelButton) {
            cancelButton.addEventListener('click', () => {
                navigate(
                    state.surveyId ? 'detail' : 'survey-list',
                    state.surveyId
                );
            });
        }

        if (saveButton) {
            saveButton.addEventListener('click', async () => {
                syncEditor();

                try {
                    const result = await api(
                        'survey_save',
                        {
                            method: 'POST',
                            body: {
                                survey: state.draft
                            }
                        },
                        saveButton
                    );

                    state.surveyId = Number(result.survey.id);
                    state.draft = result.survey;

                    await loadBootstrap();

                    showToast('保存しました。');
                    navigate('detail', state.surveyId);
                } catch (error) {
                    window.alert(error.message);
                }
            });
        }

        if (addGroupButton) {
            addGroupButton.addEventListener('click', () => {
                state.draft.groups = state.draft.groups || [];

                state.draft.groups.push({
                    id: '',
                    name: '',
                    questions: []
                });

                renderSurveyEditor();
            });
        }

        renderGroups();
    }

    function renderGroups() {
        const container = document.getElementById('groupContainer');

        if (!container) return;

        const groups = state.draft.groups || [];

        if (groups.length === 0) {
            container.innerHTML = `
                <div class="empty">
                    グループがありません。
                </div>
            `;
            return;
        }

        container.innerHTML = groups.map((group, groupIndex) => `
            <div class="group-card" data-group-index="${groupIndex}">
                <div class="card-header">
                    <strong>グループ ${groupIndex + 1}</strong>
                    <div class="toolbar">
                        <button
                            class="btn btn-outline btn-sm move-group-up"
                            type="button"
                            data-index="${groupIndex}"
                        >↑</button>

                        <button
                            class="btn btn-outline btn-sm move-group-down"
                            type="button"
                            data-index="${groupIndex}"
                        >↓</button>

                        <button
                            class="btn btn-danger btn-sm delete-group"
                            type="button"
                            data-index="${groupIndex}"
                        >削除</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>グループ名</label>
                    <input
                        class="group-name"
                        type="text"
                        data-index="${groupIndex}"
                        value="${escapeHtml(group.name || '')}"
                    >
                </div>

                <div>
                    ${(group.questions || []).map((question, questionIndex) =>
                        renderQuestionHtml(groupIndex, questionIndex, question)
                    ).join('')}
                </div>

                <button
                    class="btn btn-outline add-question"
                    type="button"
                    data-index="${groupIndex}"
                >
                    ＋ 質問追加
                </button>
            </div>
        `).join('');

        document.querySelectorAll('.group-name').forEach(input => {
            input.addEventListener('input', () => {
                const index = Number(input.dataset.index);
                state.draft.groups[index].name = input.value;
            });
        });

        document.querySelectorAll('.delete-group').forEach(button => {
            button.addEventListener('click', () => {
                const index = Number(button.dataset.index);

                if (!window.confirm('このグループを削除しますか？')) {
                    return;
                }

                state.draft.groups.splice(index, 1);
                renderSurveyEditor();
            });
        });

        document.querySelectorAll('.move-group-up').forEach(button => {
            button.addEventListener('click', () => {
                const index = Number(button.dataset.index);

                if (index <= 0) return;

                const groups = state.draft.groups;
                [groups[index - 1], groups[index]] =
                    [groups[index], groups[index - 1]];

                renderSurveyEditor();
            });
        });

        document.querySelectorAll('.move-group-down').forEach(button => {
            button.addEventListener('click', () => {
                const index = Number(button.dataset.index);
                const groups = state.draft.groups;

                if (index >= groups.length - 1) return;

                [groups[index], groups[index + 1]] =
                    [groups[index + 1], groups[index]];

                renderSurveyEditor();
            });
        });

        document.querySelectorAll('.add-question').forEach(button => {
            button.addEventListener('click', () => {
                const groupIndex = Number(button.dataset.index);

                state.draft.groups[groupIndex].questions.push({
                    id: '',
                    text: '',
                    type: 'text',
                    required: false,
                    options: []
                });

                renderSurveyEditor();
            });
        });

        bindQuestionEvents();
    }

    function renderQuestionHtml(groupIndex, questionIndex, question) {
        const options = question.options || [];

        return `
            <div class="question-card">
                <div class="card-header">
                    <strong>質問 ${questionIndex + 1}</strong>
                    <div class="toolbar">
                        <button
                            class="btn btn-outline btn-sm move-question-up"
                            type="button"
                            data-group="${groupIndex}"
                            data-question="${questionIndex}"
                        >↑</button>

                        <button
                            class="btn btn-outline btn-sm move-question-down"
                            type="button"
                            data-group="${groupIndex}"
                            data-question="${questionIndex}"
                        >↓</button>

                        <button
                            class="btn btn-danger btn-sm delete-question"
                            type="button"
                            data-group="${groupIndex}"
                            data-question="${questionIndex}"
                        >削除</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>質問文 *</label>
                    <textarea
                        class="question-text"
                        data-group="${groupIndex}"
                        data-question="${questionIndex}"
                    >${escapeHtml(question.text || '')}</textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>回答形式</label>
                        <select
                            class="question-type"
                            data-group="${groupIndex}"
                            data-question="${questionIndex}"
                        >
                            <option value="text" ${question.type === 'text' ? 'selected' : ''}>
                                自由記述
                            </option>
                            <option value="single" ${question.type === 'single' ? 'selected' : ''}>
                                単一選択
                            </option>
                            <option value="multiple" ${question.type === 'multiple' ? 'selected' : ''}>
                                複数選択
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>必須</label>
                        <label>
                            <input
                                class="question-required"
                                type="checkbox"
                                data-group="${groupIndex}"
                                data-question="${questionIndex}"
                                ${question.required ? 'checked' : ''}
                            >
                            必須回答にする
                        </label>
                    </div>
                </div>

                ${
                    ['single', 'multiple'].includes(question.type)
                        ? `
                            <div class="choice-list">
                                <label>選択肢</label>
                                ${options.map((option, optionIndex) => `
                                    <div class="option-row">
                                        <input
                                            class="option-label"
                                            type="text"
                                            data-group="${groupIndex}"
                                            data-question="${questionIndex}"
                                            data-option="${optionIndex}"
                                            value="${escapeHtml(option.label || '')}"
                                            placeholder="選択肢"
                                        >

                                        ${
                                            question.type === 'single'
                                                ? `
                                                    <select
                                                        class="option-branch"
                                                        data-group="${groupIndex}"
                                                        data-question="${questionIndex}"
                                                        data-option="${optionIndex}"
                                                    >
                                                        ${branchOptions(
                                                            question,
                                                            option.branch_target || ''
                                                        )}
                                                    </select>
                                                `
                                                : `
                                                    <input type="text" disabled value="分岐なし">
                                                `
                                        }

                                        <button
                                            class="btn btn-danger btn-sm delete-option"
                                            type="button"
                                            data-group="${groupIndex}"
                                            data-question="${questionIndex}"
                                            data-option="${optionIndex}"
                                        >削除</button>
                                    </div>
                                `).join('')}

                                <button
                                    class="btn btn-outline btn-sm add-option"
                                    type="button"
                                    data-group="${groupIndex}"
                                    data-question="${questionIndex}"
                                >＋ 選択肢追加</button>
                            </div>
                        `
                        : ''
                }
            </div>
        `;
    }

    function branchOptions(question, selected) {
        const allQuestions = [];

        (state.draft.groups || []).forEach(group => {
            (group.questions || []).forEach(q => {
                if (q !== question) {
                    allQuestions.push(q);
                }
            });
        });

        let html = `<option value="">次の質問へ</option>`;

        allQuestions.forEach(q => {
            const text = q.text || '(未入力)';
            const value = q.id || '';
            html += `
                <option value="${escapeHtml(value)}" ${selected === value ? 'selected' : ''}>
                    ${escapeHtml(text)}
                </option>
            `;
        });

        html += `
            <option value="__END__" ${selected === '__END__' ? 'selected' : ''}>
                回答終了
            </option>
        `;

        return html;
    }

    function bindQuestionEvents() {
        document.querySelectorAll('.question-text').forEach(input => {
            input.addEventListener('input', () => {
                const g = Number(input.dataset.group);
                const q = Number(input.dataset.question);
                state.draft.groups[g].questions[q].text = input.value;
            });
        });

        document.querySelectorAll('.question-type').forEach(select => {
            select.addEventListener('change', () => {
                const g = Number(select.dataset.group);
                const q = Number(select.dataset.question);

                state.draft.groups[g].questions[q].type = select.value;

                if (select.value === 'text') {
                    state.draft.groups[g].questions[q].options = [];
                }

                renderSurveyEditor();
            });
        });

        document.querySelectorAll('.question-required').forEach(input => {
            input.addEventListener('change', () => {
                const g = Number(input.dataset.group);
                const q = Number(input.dataset.question);

                state.draft.groups[g].questions[q].required = input.checked;
            });
        });

        document.querySelectorAll('.option-label').forEach(input => {
            input.addEventListener('input', () => {
                const g = Number(input.dataset.group);
                const q = Number(input.dataset.question);
                const o = Number(input.dataset.option);

                state.draft.groups[g].questions[q].options[o].label = input.value;
            });
        });

        document.querySelectorAll('.option-branch').forEach(select => {
            select.addEventListener('change', () => {
                const g = Number(select.dataset.group);
                const q = Number(select.dataset.question);
                const o = Number(select.dataset.option);

                state.draft.groups[g].questions[q].options[o].branch_target =
                    select.value;
            });
        });

        document.querySelectorAll('.add-option').forEach(button => {
            button.addEventListener('click', () => {
                const g = Number(button.dataset.group);
                const q = Number(button.dataset.question);

                state.draft.groups[g].questions[q].options.push({
                    id: '',
                    label: '',
                    branch_target: ''
                });

                renderSurveyEditor();
            });
        });

        document.querySelectorAll('.delete-option').forEach(button => {
            button.addEventListener('click', () => {
                const g = Number(button.dataset.group);
                const q = Number(button.dataset.question);
                const o = Number(button.dataset.option);

                state.draft.groups[g].questions[q].options.splice(o, 1);
                renderSurveyEditor();
            });
        });

        document.querySelectorAll('.delete-question').forEach(button => {
            button.addEventListener('click', () => {
                const g = Number(button.dataset.group);
                const q = Number(button.dataset.question);

                state.draft.groups[g].questions.splice(q, 1);
                renderSurveyEditor();
            });
        });

        document.querySelectorAll('.move-question-up').forEach(button => {
            button.addEventListener('click', () => {
                const g = Number(button.dataset.group);
                const q = Number(button.dataset.question);

                if (q <= 0) return;

                const questions = state.draft.groups[g].questions;

                [questions[q - 1], questions[q]] =
                    [questions[q], questions[q - 1]];

                renderSurveyEditor();
            });
        });

        document.querySelectorAll('.move-question-down').forEach(button => {
            button.addEventListener('click', () => {
                const g = Number(button.dataset.group);
                const q = Number(button.dataset.question);
                const questions = state.draft.groups[g].questions;

                if (q >= questions.length - 1) return;

                [questions[q], questions[q + 1]] =
                    [questions[q + 1], questions[q]];

                renderSurveyEditor();
            });
        });
    }

    function syncEditor() {
        if (!state.draft) return;

        const name = document.getElementById('surveyName');
        const description = document.getElementById('surveyDescription');
        const start = document.getElementById('surveyStart');
        const end = document.getElementById('surveyEnd');
        const numbering = document.getElementById('numberingFormat');

        if (name) state.draft.name = name.value.trim();
        if (description) state.draft.description = description.value;
        if (start) state.draft.start_date = start.value;
        if (end) state.draft.end_date = end.value;
        if (numbering) state.draft.numbering_format = numbering.value;
    }

    function renderSurveyDetail() {
        const survey = getSurvey();

        if (!survey) {
            navigate('survey-list');
            return;
        }

        const questions = [];
        (survey.groups || []).forEach(group => {
            (group.questions || []).forEach(question => {
                questions.push(question);
            });
        });

        main.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>${escapeHtml(survey.name || '')}</h2>
                        ${statusBadge(survey.status)}
                    </div>

                    <div class="toolbar">
                        ${
                            survey.status === 'draft'
                                ? `
                                <button id="editSurvey" class="btn btn-primary" type="button">
                                    編集
                                </button>
                                `
                                : ''
                        }

                        <button id="previewSurvey" class="btn btn-outline" type="button">
                            回答画面
                        </button>
                    </div>
                </div>

                <p>${escapeHtml(survey.description || '')}</p>

                <div class="form-row">
                    <div>
                        <strong>公開期間</strong>
                        <div>
                            ${escapeHtml(survey.start_date || '')}
                            〜
                            ${escapeHtml(survey.end_date || '')}
                        </div>
                    </div>
                    <div>
                        <strong>回答数</strong>
                        <div>${Number(survey.response_count || 0)} 件</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>質問構成</h2>
                ${
                    questions.length === 0
                        ? `<div class="empty">質問がありません。</div>`
                        : `
                            ${questions.map((q, index) => `
                                <div class="question-card">
                                    <strong>Q${index + 1}. ${escapeHtml(q.text || '')}</strong>
                                    <div class="muted">
                                        ${q.type === 'single'
                                            ? '単一選択'
                                            : q.type === 'multiple'
                                                ? '複数選択'
                                                : '自由記述'}
                                        ${q.required ? ' / 必須' : ''}
                                    </div>
                                </div>
                            `).join('')}
                        `
                }
            </div>
        `;

        const editButton = document.getElementById('editSurvey');
        const previewButton = document.getElementById('previewSurvey');

        if (editButton) {
            editButton.addEventListener('click', () => {
                openEditor(Number(survey.id));
            });
        }

        if (previewButton) {
            previewButton.addEventListener('click', () => {
                state.view = 'respondent';
                render();
            });
        }
    }

    function renderSend() {
        const survey = getSurvey();

        if (!survey) {
            navigate('survey-list');
            return;
        }

        if (survey.status !== 'published') {
            main.innerHTML = `
                <div class="card">
                    <h2>メール送信</h2>
                    <div class="error">
                        公開中のアンケートだけ送信できます。
                    </div>
                </div>
            `;
            return;
        }

        const customers = state.data.customers || [];

        main.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>回答依頼メール</h2>
                        <div class="muted">
                            送信済みの顧客は再送対象になりません。
                        </div>
                    </div>
                    <button id="syncCustomers" class="btn btn-outline" type="button">
                        kintoneから更新
                    </button>
                </div>

                <div class="form-group">
                    <label for="mailSubject">件名</label>
                    <input id="mailSubject" type="text"
                        value="${escapeHtml(survey.name || '')}">
                </div>

                <div class="form-group">
                    <label for="mailBody">本文</label>
                    <textarea id="mailBody">{{name}} 様

アンケートへのご回答をお願いいたします。

アンケート名：${escapeHtml(survey.name || '')}

ご回答よろしくお願いいたします。</textarea>
                </div>

                <div class="form-group">
                    <label>
                        送信先
                    </label>

                    <div class="toolbar" style="margin-bottom:10px">
                        <button id="selectAllCustomers" class="btn btn-outline btn-sm" type="button">
                            全選択
                        </button>
                        <button id="clearCustomers" class="btn btn-outline btn-sm" type="button">
                            全解除
                        </button>
                    </div>

                    <div class="customer-list">
                        ${
                            customers.length === 0
                                ? `<div class="empty">顧客データがありません。</div>`
                                : customers.map(customer => `
                                    <label class="customer-row">
                                        <input
                                            class="customer-check"
                                            type="checkbox"
                                            value="${escapeHtml(customer.id)}"
                                        >
                                        <span>
                                            <strong>${escapeHtml(customer.name || '')}</strong><br>
                                            <span class="muted">${escapeHtml(customer.email || '')}</span>
                                        </span>
                                    </label>
                                `).join('')
                        }
                    </div>
                </div>

                <button id="sendMail" class="btn btn-primary" type="button">
                    選択した顧客へ送信
                </button>
            </div>
        `;

        const syncButton = document.getElementById('syncCustomers');
        const allButton = document.getElementById('selectAllCustomers');
        const clearButton = document.getElementById('clearCustomers');
        const sendButton = document.getElementById('sendMail');

        if (syncButton) {
            syncButton.addEventListener('click', async () => {
                try {
                    const result = await api(
                        'customers_sync',
                        {
                            method: 'POST',
                            body: {}
                        },
                        syncButton
                    );

                    state.data.customers = result.customers || [];
                    renderSend();
                    showToast('顧客情報を更新しました。');
                } catch (error) {
                    window.alert(error.message);
                }
            });
        }

        if (allButton) {
            allButton.addEventListener('click', () => {
                document.querySelectorAll('.customer-check').forEach(input => {
                    input.checked = true;
                });
            });
        }

        if (clearButton) {
            clearButton.addEventListener('click', () => {
                document.querySelectorAll('.customer-check').forEach(input => {
                    input.checked = false;
                });
            });
        }

        if (sendButton) {
            sendButton.addEventListener('click', async () => {
                const customerIds = Array.from(
                    document.querySelectorAll('.customer-check:checked')
                ).map(input => input.value);

                const subject = document.getElementById('mailSubject')?.value || '';
                const body = document.getElementById('mailBody')?.value || '';

                if (customerIds.length === 0) {
                    window.alert('送信先を選択してください。');
                    return;
                }

                if (!window.confirm(
                    `${customerIds.length}件にメールを送信します。よろしいですか？`
                )) {
                    return;
                }

                try {
                    const result = await api(
                        'mail_send',
                        {
                            method: 'POST',
                            body: {
                                survey_id: Number(survey.id),
                                customer_ids: customerIds,
                                subject,
                                body
                            }
                        },
                        sendButton
                    );

                    const success = (result.results || []).filter(
                        item => item.success
                    ).length;

                    const failed = (result.results || []).length - success;

                    showToast(
                        `送信完了：成功 ${success}件 / 失敗 ${failed}件`
                    );

                    navigate('status', survey.id);
                } catch (error) {
                    window.alert(error.message);
                }
            });
        }
    }

    function renderStatus() {
        const survey = getSurvey();

        if (!survey) {
            navigate('survey-list');
            return;
        }

        const recipients = state.data.recipients || [];

        main.innerHTML = `
            <div class="card">
                <h2>回答状況</h2>

                <div class="stats">
                    <div class="stat">
                        <span class="muted">回答数</span>
                        <strong>${Number(survey.response_count || 0)}</strong>
                    </div>

                    <div class="stat">
                        <span class="muted">アンケート状態</span>
                        <strong style="font-size:18px">
                            ${escapeHtml(
                                survey.status === 'published'
                                    ? '公開中'
                                    : survey.status === 'closed'
                                        ? '終了'
                                        : '下書き'
                            )}
                        </strong>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>回答者別状況</h2>

                ${
                    recipients.length === 0
                        ? `<div class="empty">送信履歴がありません。</div>`
                        : `
                            <table>
                                <thead>
                                    <tr>
                                        <th>顧客ID</th>
                                        <th>送信状態</th>
                                        <th>回答状態</th>
                                        <th>送信日時</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${recipients
                                        .filter(item =>
                                            Number(item.survey_id) === Number(survey.id)
                                        )
                                        .map(item => `
                                            <tr>
                                                <td>${escapeHtml(String(item.customer_id || ''))}</td>
                                                <td>${escapeHtml(
                                                    item.send_status === 'success'
                                                        ? '送信済み'
                                                        : '送信失敗'
                                                )}</td>
                                                <td>${escapeHtml(
                                                    item.response_status === 'answered'
                                                        ? '回答済'
                                                        : '未回答'
                                                )}</td>
                                                <td>${escapeHtml(item.send_time || '')}</td>
                                            </tr>
                                        `).join('')}
                                </tbody>
                            </table>
                        `
                }
            </div>
        `;
    }

    async function renderResult() {
        const survey = getSurvey();

        if (!survey) {
            navigate('survey-list');
            return;
        }

        main.innerHTML = `
            <div class="card">
                <h2>回答結果</h2>
                <div class="muted">集計結果を読み込んでいます。</div>
            </div>
        `;

        try {
            const data = await api(
                'result',
                {
                    params: {
                        survey_id: Number(survey.id)
                    }
                }
            );

            const results = data.result || [];

            main.innerHTML = `
                <div class="card">
                    <h2>回答結果</h2>
                    <div class="stats">
                        <div class="stat">
                            <span class="muted">回答数</span>
                            <strong>${Number(data.response_count || 0)}</strong>
                        </div>
                    </div>
                </div>

                ${
                    results.length === 0
                        ? `
                            <div class="card">
                                <div class="empty">まだ回答がありません。</div>
                            </div>
                        `
                        : results.map(item => `
                            <div class="card">
                                <h3>${escapeHtml(item.text || '')}</h3>

                                ${
                                    item.type === 'text'
                                        ? `
                                            ${
                                                (item.values || []).length === 0
                                                    ? `<div class="muted">回答なし</div>`
                                                    : `
                                                        ${(item.values || []).map(value => `
                                                            <div class="question-card">
                                                                ${escapeHtml(value)}
                                                            </div>
                                                        `).join('')}
                                                    `
                                            }
                                        `
                                        : `
                                            ${
                                                Object.keys(item.values || {}).length === 0
                                                    ? `<div class="muted">回答なし</div>`
                                                    : `
                                                        <table>
                                                            <thead>
                                                                <tr>
                                                                    <th>選択肢</th>
                                                                    <th>件数</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                ${Object.entries(item.values || {}).map(([label, count]) => `
                                                                    <tr>
                                                                        <td>${escapeHtml(label)}</td>
                                                                        <td>${Number(count)} 件</td>
                                                                    </tr>
                                                                `).join('')}
                                                            </tbody>
                                                        </table>
                                                    `
                                            }
                                        `
                                }
                            </div>
                        `).join('')
                }
            `;
        } catch (error) {
            main.innerHTML = `
                <div class="card">
                    <div class="error">${escapeHtml(error.message)}</div>
                </div>
            `;
        }
    }

    function renderCustomerList() {
        const customers = state.data.customers || [];

        main.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>顧客一覧</h2>
                        <div class="muted">
                            kintoneから取得した顧客情報です。
                        </div>
                    </div>

                    <button id="syncCustomerList" class="btn btn-primary" type="button">
                        kintoneから更新
                    </button>
                </div>

                ${
                    customers.length === 0
                        ? `<div class="empty">顧客情報がありません。</div>`
                        : `
                            <table>
                                <thead>
                                    <tr>
                                        <th>顧客名</th>
                                        <th>メールアドレス</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${customers.map(customer => `
                                        <tr>
                                            <td>${escapeHtml(customer.name || '')}</td>
                                            <td>${escapeHtml(customer.email || '')}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        `
                }
            </div>
        `;

        const syncButton = document.getElementById('syncCustomerList');

        if (syncButton) {
            syncButton.addEventListener('click', async () => {
                try {
                    const result = await api(
                        'customers_sync',
                        {
                            method: 'POST',
                            body: {}
                        },
                        syncButton
                    );

                    state.data.customers = result.customers || [];
                    renderCustomerList();
                    showToast('顧客情報を更新しました。');
                } catch (error) {
                    window.alert(error.message);
                }
            });
        }
    }

    function renderSettings() {
        const settings = state.data.settings || {};
        const kintone = settings.kintone || {};
        const smtp = settings.smtp || {};

        main.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>kintone設定</h2>
                        <div class="muted">
                            APIトークンは使用せず、ログイン情報で接続します。
                        </div>
                    </div>
                    <button id="testKintone" class="btn btn-outline" type="button">
                        接続テスト
                    </button>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>ホスト</label>
                        <input id="kHost" type="text" value="${escapeHtml(kintone.host || '')}">
                    </div>
                    <div class="form-group">
                        <label>アプリID</label>
                        <input id="kAppId" type="text" value="${escapeHtml(kintone.app_id || '')}">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>ログイン名</label>
                        <input id="kLogin" type="text" value="${escapeHtml(kintone.login || '')}">
                    </div>
                    <div class="form-group">
                        <label>パスワード</label>
                        <input id="kPassword" type="password" placeholder="変更する場合のみ入力">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>顧客名フィールドコード</label>
                        <input id="kNameField" type="text" value="${escapeHtml(kintone.name_field || '')}">
                    </div>
                    <div class="form-group">
                        <label>メールフィールドコード</label>
                        <input id="kEmailField" type="text" value="${escapeHtml(kintone.email_field || '')}">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>プロキシ host:port</label>
                        <input id="kProxy" type="text" value="${escapeHtml(kintone.proxy || '')}">
                    </div>
                    <div class="form-group">
                        <label>SSL証明書検証</label>
                        <select id="kVerifySsl">
                            <option value="0" ${!kintone.verify_ssl ? 'selected' : ''}>無効</option>
                            <option value="1" ${kintone.verify_ssl ? 'selected' : ''}>有効</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>SMTP設定</h2>

                <div class="form-row">
                    <div class="form-group">
                        <label>ホスト</label>
                        <input id="sHost" type="text" value="${escapeHtml(smtp.host || '')}">
                    </div>
                    <div class="form-group">
                        <label>ポート</label>
                        <input id="sPort" type="number" value="${Number(smtp.port || 587)}">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>暗号化</label>
                        <select id="sEncryption">
                            ${['None','SSL','TLS'].map(value => `
                                <option value="${value}" ${smtp.encryption === value ? 'selected' : ''}>
                                    ${value}
                                </option>
                            `).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ユーザー名</label>
                        <input id="sUsername" type="text" value="${escapeHtml(smtp.username || '')}">
                    </div>
                </div>

                <div class="form-group">
                    <label>パスワード</label>
                    <input id="sPassword" type="password" placeholder="変更する場合のみ入力">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>送信元メールアドレス</label>
                        <input id="sFromEmail" type="email" value="${escapeHtml(smtp.from_email || '')}">
                    </div>
                    <div class="form-group">
                        <label>送信元名</label>
                        <input id="sFromName" type="text" value="${escapeHtml(smtp.from_name || '')}">
                    </div>
                </div>

                <button id="saveSettings" class="btn btn-primary" type="button">
                    設定を保存
                </button>
            </div>
        `;

        const saveButton = document.getElementById('saveSettings');
        const testButton = document.getElementById('testKintone');

        if (saveButton) {
            saveButton.addEventListener('click', async () => {
                try {
                    const result = await api(
                        'settings_save',
                        {
                            method: 'POST',
                            body: {
                                kintone: {
                                    host: document.getElementById('kHost')?.value || '',
                                    app_id: document.getElementById('kAppId')?.value || '',
                                    login: document.getElementById('kLogin')?.value || '',
                                    password: document.getElementById('kPassword')?.value || '',
                                    name_field: document.getElementById('kNameField')?.value || '',
                                    email_field: document.getElementById('kEmailField')?.value || '',
                                    proxy: document.getElementById('kProxy')?.value || '',
                                    verify_ssl: document.getElementById('kVerifySsl')?.value === '1'
                                },
                                smtp: {
                                    host: document.getElementById('sHost')?.value || '',
                                    port: Number(document.getElementById('sPort')?.value || 587),
                                    encryption: document.getElementById('sEncryption')?.value || 'TLS',
                                    username: document.getElementById('sUsername')?.value || '',
                                    password: document.getElementById('sPassword')?.value || '',
                                    from_email: document.getElementById('sFromEmail')?.value || '',
                                    from_name: document.getElementById('sFromName')?.value || ''
                                }
                            }
                        },
                        saveButton
                    );

                    state.data.settings = result.settings || state.data.settings;
                    showToast('設定を保存しました。');
                } catch (error) {
                    window.alert(error.message);
                }
            });
        }

        if (testButton) {
            testButton.addEventListener('click', async () => {
                try {
                    await api(
                        'kintone_test',
                        {
                            method: 'POST',
                            body: {}
                        },
                        testButton
                    );

                    showToast('kintoneへの接続に成功しました。');
                } catch (error) {
                    window.alert(error.message);
                }
            });
        }
    }

    function renderRespondent() {
        const survey = getSurvey();

        if (!survey) {
            navigate('survey-list');
            return;
        }

        document.getElementById('appHeader').style.display = 'none';

        const groups = survey.groups || [];
        let number = 1;

        main.innerHTML = `
            <div class="preview">
                <div class="card">
                    <h2>${escapeHtml(survey.name || '')}</h2>
                    <p>${escapeHtml(survey.description || '')}</p>
                </div>

                <form id="answerForm">
                    ${groups.map(group => `
                        <div class="card">
                            <h3>${escapeHtml(group.name || '')}</h3>

                            ${(group.questions || []).map(question => {
                                const currentNumber = number++;
                                return renderAnswerQuestion(
                                    question,
                                    currentNumber
                                );
                            }).join('')}
                        </div>
                    `).join('')}

                    <div class="card">
                        <button id="submitAnswer" class="btn btn-primary" type="submit">
                            回答を送信する
                        </button>
                        <button id="backFromRespondent" class="btn btn-outline" type="button">
                            管理画面へ戻る
                        </button>
                    </div>
                </form>
            </div>
        `;

        const form = document.getElementById('answerForm');
        const backButton = document.getElementById('backFromRespondent');

        if (form) {
            form.addEventListener('submit', async event => {
                event.preventDefault();

                const submitButton = document.getElementById('submitAnswer');

                const answers = {};

                form.querySelectorAll('[data-question-id]').forEach(input => {
                    const id = input.dataset.questionId;

                    if (!id) return;

                    if (input.type === 'checkbox') {
                        if (!answers[id]) answers[id] = [];

                        if (input.checked) {
                            answers[id].push(input.value);
                        }
                    } else if (input.type === 'radio') {
                        if (input.checked) {
                            answers[id] = input.value;
                        }
                    } else {
                        answers[id] = input.value;
                    }
                });

                if (!window.confirm('この内容で回答を送信しますか？')) {
                    return;
                }

                try {
                    await api(
                        'answer_submit',
                        {
                            method: 'POST',
                            body: {
                                survey_id: Number(survey.id),
                                recipient_id: null,
                                answers
                            }
                        },
                        submitButton
                    );

                    main.innerHTML = `
                        <div class="preview">
                            <div class="card">
                                <h2>回答ありがとうございました</h2>
                                <p>回答を受け付けました。</p>
                            </div>
                        </div>
                    `;
                } catch (error) {
                    window.alert(error.message);
                }
            });
        }

        if (backButton) {
            backButton.addEventListener('click', () => {
                document.getElementById('appHeader').style.display = '';
                navigate('detail', survey.id);
            });
        }
    }

    function renderAnswerQuestion(question, number) {
        const id = escapeHtml(question.id || '');
        const required = question.required ? ' *' : '';

        let input = '';

        if (question.type === 'text') {
            input = `
                <textarea
                    data-question-id="${id}"
                    ${question.required ? 'required' : ''}
                ></textarea>
            `;
        } else if (question.type === 'single') {
            input = (question.options || []).map(option => `
                <label class="answer-option">
                    <input
                        type="radio"
                        name="q_${id}"
                        value="${escapeHtml(option.label || '')}"
                        data-question-id="${id}"
                        ${question.required ? 'required' : ''}
                    >
                    ${escapeHtml(option.label || '')}
                </label>
            `).join('');
        } else {
            input = (question.options || []).map(option => `
                <label class="answer-option">
                    <input
                        type="checkbox"
                        name="q_${id}[]"
                        value="${escapeHtml(option.label || '')}"
                        data-question-id="${id}"
                    >
                    ${escapeHtml(option.label || '')}
                </label>
            `).join('');
        }

        return `
            <div class="answer-question">
                <label>
                    Q${number}. ${escapeHtml(question.text || '')}${required}
                </label>
                ${input}
            </div>
        `;
    }

    const logoButton = document.getElementById('logoButton');
    const navSurveys = document.getElementById('navSurveys');
    const navNew = document.getElementById('navNew');
    const navCustomers = document.getElementById('navCustomers');
    const navSettings = document.getElementById('navSettings');

    if (logoButton) {
        logoButton.addEventListener('click', () => {
            document.getElementById('appHeader').style.display = '';
            state.surveyId = null;
            state.draft = null;
            navigate('survey-list');
        });
    }

    if (navSurveys) {
        navSurveys.addEventListener('click', () => {
            document.getElementById('appHeader').style.display = '';
            state.surveyId = null;
            state.draft = null;
            navigate('survey-list');
        });
    }

    if (navNew) {
        navNew.addEventListener('click', () => {
            document.getElementById('appHeader').style.display = '';
            createNewSurvey();
        });
    }

    if (navCustomers) {
        navCustomers.addEventListener('click', () => {
            document.getElementById('appHeader').style.display = '';
            state.surveyId = null;
            state.draft = null;
            navigate('customer-list');
        });
    }

    if (navSettings) {
        navSettings.addEventListener('click', () => {
            document.getElementById('appHeader').style.display = '';
            state.surveyId = null;
            state.draft = null;
            navigate('settings');
        });
    }

    const subDetail = document.getElementById('subDetail');
    const subSend = document.getElementById('subSend');
    const subStatus = document.getElementById('subStatus');
    const subResult = document.getElementById('subResult');
    const subPreview = document.getElementById('subPreview');

    if (subDetail) {
        subDetail.addEventListener('click', () => {
            navigateSub('detail');
        });
    }

    if (subSend) {
        subSend.addEventListener('click', () => {
            navigateSub('send');
        });
    }

    if (subStatus) {
        subStatus.addEventListener('click', () => {
            navigateSub('status');
        });
    }

    if (subResult) {
        subResult.addEventListener('click', () => {
            navigateSub('result');
        });
    }

    if (subPreview) {
        subPreview.addEventListener('click', () => {
            state.view = 'respondent';
            render();
        });
    }

    loadBootstrap().catch(error => {
        main.innerHTML = `
            <div class="card">
                <div class="error">
                    ${escapeHtml(error.message || '初期データの読み込みに失敗しました。')}
                </div>
                <button id="reloadApp" class="btn btn-primary" type="button">
                    再読み込み
                </button>
            </div>
        `;

        const reload = document.getElementById('reloadApp');

        if (reload) {
            reload.addEventListener('click', () => {
                window.location.reload();
            });
        }
    });
});
</script>
</body>
</html>
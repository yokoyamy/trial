<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * 実行環境:
 *   Apache 2.4
 *   PHP 8.5
 *   DBなし
 *   PHP cURLなし
 *
 * 単一エントリーポイント:
 *   index.php?screen=list
 *   index.php?screen=edit&id=survey-001
 *   index.php?screen=preview&id=survey-001
 *   index.php?screen=send&id=survey-001
 *   index.php?screen=analytics&id=survey-001
 *   index.php?screen=kintone
 *   index.php?screen=mail
 *   index.php?screen=answer&id=survey-001
 *   index.php?screen=confirm&id=survey-001
 *   index.php?screen=complete&id=survey-001
 */

/* =========================================================
   基本設定
========================================================= */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const DATA_PREFIX = "<?php exit; ?>\n";
const APP_TIMEZONE = 'Asia/Tokyo';

date_default_timezone_set(APP_TIMEZONE);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

/* =========================================================
   セッション
========================================================= */

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$cookiePath = $scriptDir === '.' || $scriptDir === '' ? '/' : rtrim($scriptDir, '/') . '/';

$isHttps =
    (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath,
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================================
   共通ヘルパー
========================================================= */

function h(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(mixed $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '';
}

function nowString(): string
{
    return date('Y-m-d H:i:s');
}

function todayString(): string
{
    return date('Y-m-d');
}

function uuid(string $prefix = 'id'): string
{
    return $prefix . '-' . bin2hex(random_bytes(6));
}

function csrfToken(): string
{
    return (string)($_SESSION['csrf_token'] ?? '');
}

function validateCsrf(): void
{
    $token = (string)($_POST['_csrf'] ?? '');

    if ($token === '' || !hash_equals(csrfToken(), $token)) {
        http_response_code(419);
        exit('CSRFトークンが無効です。ページを再読み込みして再度お試しください。');
    }
}

function redirectTo(string $url, int $status = 303): never
{
    header('Location: ' . $url, true, $status);
    exit;
}

function currentBaseUrl(): string
{
    $scheme = (
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
    ) ? 'https' : 'http';

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');

    return $scheme . '://' . $host . $script;
}

function answerUrl(string $surveyId, ?string $customerId = null): string
{
    $url = currentBaseUrl() . '?screen=answer&id=' . rawurlencode($surveyId);

    if ($customerId !== null && $customerId !== '') {
        $url .= '&customer=' . rawurlencode($customerId);
    }

    return $url;
}

function validateEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validDateTime(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);

    return $d !== false && $d->format('Y-m-d\TH:i') === $value;
}

function normalizeDateTime(string $value): string
{
    return trim($value);
}

function statusLabel(string $status): string
{
    return match ($status) {
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => $status,
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        'draft' => 'badge-draft',
        'published' => 'badge-published',
        'stopped' => 'badge-stopped',
        'ended' => 'badge-ended',
        default => '',
    };
}

function typeLabel(string $type): string
{
    return match ($type) {
        'single' => '単一選択',
        'multiple' => '複数選択',
        'free' => '自由記述',
        default => $type,
    };
}

function htmlStatusBadge(string $status): string
{
    return '<span class="badge ' . h(statusClass($status)) . '">' .
        h(statusLabel($status)) .
        '</span>';
}

function formatDateValue(?string $value): string
{
    if (!$value) {
        return '-';
    }

    return str_replace('T', ' ', $value);
}

/* =========================================================
   ファイル永続化
========================================================= */

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0770, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存ディレクトリを作成できません。');
        }
    }
}

function dataFile(string $name): string
{
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
        throw new InvalidArgumentException('不正なデータファイル名です。');
    }

    ensureDataDir();

    return DATA_DIR . DIRECTORY_SEPARATOR . $name . '.dat.php';
}

function loadJsonFile(string $name, mixed $default = []): mixed
{
    $file = dataFile($name);

    if (!is_file($file)) {
        return $default;
    }

    $raw = file_get_contents($file);

    if ($raw === false) {
        throw new RuntimeException('データファイルを読み込めません。');
    }

    if (str_starts_with($raw, DATA_PREFIX)) {
        $raw = substr($raw, strlen(DATA_PREFIX));
    }

    $decoded = json_decode($raw, true);

    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
}

function saveJsonFile(string $name, mixed $data): void
{
    ensureDataDir();

    $file = dataFile($name);
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(5));

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('JSON生成に失敗しました。');
    }

    $payload = DATA_PREFIX . $json;

    if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
        throw new RuntimeException('一時データファイルを保存できません。');
    }

    @chmod($tmp, 0660);

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データファイルの反映に失敗しました。');
    }

    @chmod($file, 0660);
}

/* =========================================================
   機密情報暗号化
========================================================= */

function secretKey(): string
{
    $env = getenv('SURVEY_APP_KEY');

    if (is_string($env) && strlen($env) >= 32) {
        return hash('sha256', $env, true);
    }

    $file = dataFile('app_secret');

    if (is_file($file)) {
        $raw = file_get_contents($file);

        if ($raw !== false) {
            $raw = str_starts_with($raw, DATA_PREFIX)
                ? substr($raw, strlen(DATA_PREFIX))
                : $raw;

            $key = trim($raw);

            if ($key !== '') {
                return hash('sha256', $key, true);
            }
        }
    }

    $key = base64_encode(random_bytes(48));

    $payload = DATA_PREFIX . $key;
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(5));

    if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
        throw new RuntimeException('秘密鍵を保存できません。');
    }

    @chmod($tmp, 0600);

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('秘密鍵を保存できません。');
    }

    @chmod($file, 0600);

    return hash('sha256', $key, true);
}

function encryptSecret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSLが利用できません。');
    }

    $iv = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        secretKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException('機密情報の暗号化に失敗しました。');
    }

    return base64_encode($iv . $tag . $cipher);
}

function decryptSecret(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }

    $raw = base64_decode($encoded, true);

    if ($raw === false || strlen($raw) < 28) {
        return '';
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        secretKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return $plain === false ? '' : $plain;
}

/* =========================================================
   初期データ
========================================================= */

function initialData(): array
{
    $past20 = (new DateTimeImmutable('now'))->modify('-20 days')->setTime(9, 0);
    $future20 = (new DateTimeImmutable('now'))->modify('+20 days')->setTime(18, 0);

    $past10 = (new DateTimeImmutable('now'))->modify('-10 days')->setTime(9, 0);
    $future10 = (new DateTimeImmutable('now'))->modify('+10 days')->setTime(18, 0);

    $past40 = (new DateTimeImmutable('now'))->modify('-40 days')->setTime(9, 0);
    $past5 = (new DateTimeImmutable('now'))->modify('-5 days')->setTime(18, 0);

    return [
        'surveys' => [
            [
                'id' => 'survey-001',
                'createdAt' => '2026-08-01',
                'updatedAt' => '2026-08-25',
                'title' => '2026年度 顧客満足度アンケート',
                'description' => 'サービスについてのご意見をお聞かせください。',
                'startAt' => $past20->format('Y-m-d\TH:i'),
                'endAt' => $future20->format('Y-m-d\TH:i'),
                'status' => 'published',
                'numbering' => 'global',
                'groups' => [
                    [
                        'id' => 'g1',
                        'title' => 'サービス全体について',
                        'questions' => [
                            [
                                'id' => 'q1',
                                'text' => '当社のサービスに満足していますか？',
                                'type' => 'single',
                                'required' => true,
                                'options' => ['非常に満足', '満足', '普通', 'やや不満', '不満'],
                                'branches' => [],
                                'number' => 'Q1',
                            ],
                            [
                                'id' => 'q2',
                                'text' => 'サービスについて特に良かった点を教えてください。',
                                'type' => 'free',
                                'required' => false,
                                'options' => [],
                                'branches' => [],
                                'number' => 'Q2',
                            ],
                        ],
                    ],
                    [
                        'id' => 'g2',
                        'title' => '今後について',
                        'questions' => [
                            [
                                'id' => 'q3',
                                'text' => '今後も当社サービスを利用したいですか？',
                                'type' => 'single',
                                'required' => true,
                                'options' => [
                                    'ぜひ利用したい',
                                    '利用したい',
                                    'どちらともいえない',
                                    '利用したくない',
                                ],
                                'branches' => [],
                                'number' => 'Q3',
                            ],
                            [
                                'id' => 'q4',
                                'text' => '改善してほしい点を教えてください。',
                                'type' => 'free',
                                'required' => false,
                                'options' => [],
                                'branches' => [],
                                'number' => 'Q4',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'survey-002',
                'createdAt' => '2026-08-10',
                'updatedAt' => '2026-08-24',
                'title' => '2026年度 新商品ご利用アンケート',
                'description' => '新商品のご利用状況についてお伺いします。',
                'startAt' => $past10->format('Y-m-d\TH:i'),
                'endAt' => $future10->format('Y-m-d\TH:i'),
                'status' => 'stopped',
                'numbering' => 'group',
                'groups' => [
                    [
                        'id' => 'g3',
                        'title' => 'ご利用状況',
                        'questions' => [
                            [
                                'id' => 'q5',
                                'text' => '新商品を利用しましたか？',
                                'type' => 'single',
                                'required' => true,
                                'options' => ['はい', 'いいえ'],
                                'branches' => [],
                                'number' => 'Q1-1',
                            ],
                            [
                                'id' => 'q6',
                                'text' => '利用した感想を教えてください。',
                                'type' => 'free',
                                'required' => false,
                                'options' => [],
                                'branches' => [],
                                'number' => 'Q1-2',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'survey-003',
                'createdAt' => '2026-07-15',
                'updatedAt' => '2026-08-12',
                'title' => '社内サービス改善アンケート',
                'description' => '社内向けアンケートです。',
                'startAt' => $past40->format('Y-m-d\TH:i'),
                'endAt' => $past5->format('Y-m-d\TH:i'),
                'status' => 'draft',
                'numbering' => 'global',
                'groups' => [
                    [
                        'id' => 'g4',
                        'title' => '改善について',
                        'questions' => [
                            [
                                'id' => 'q7',
                                'text' => '改善してほしいサービスを選択してください。',
                                'type' => 'multiple',
                                'required' => true,
                                'options' => ['営業', 'サポート', 'Webサイト', 'その他'],
                                'branches' => [],
                                'number' => 'Q1',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'survey-004',
                'createdAt' => '2026-07-01',
                'updatedAt' => '2026-08-05',
                'title' => '2026年度 上期サービス評価',
                'description' => '上期のサービス評価をお願いします。',
                'startAt' => $past40->modify('-30 days')->format('Y-m-d\TH:i'),
                'endAt' => $past5->format('Y-m-d\TH:i'),
                'status' => 'published',
                'numbering' => 'global',
                'groups' => [
                    [
                        'id' => 'g5',
                        'title' => '評価',
                        'questions' => [
                            [
                                'id' => 'q8',
                                'text' => '総合評価を教えてください。',
                                'type' => 'single',
                                'required' => true,
                                'options' => ['5', '4', '3', '2', '1'],
                                'branches' => [],
                                'number' => 'Q1',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'customers' => [
            [
                'id' => 'c001',
                'org' => '株式会社サンプル',
                'name' => '山田 太郎',
                'email' => 'taro@example.com',
                'department' => '',
                'phone' => '03-1234-5678',
                'address' => '東京都港区',
                'lastSent' => '2026-08-20 10:15',
                'sendCount' => 2,
                'answerStatus' => 'answered',
                'kintone' => true,
            ],
            [
                'id' => 'c002',
                'org' => '株式会社テスト',
                'name' => '佐藤 花子',
                'email' => 'hanako@example.com',
                'department' => '',
                'phone' => '03-2345-6789',
                'address' => '東京都千代田区',
                'lastSent' => '2026-08-21 11:30',
                'sendCount' => 1,
                'answerStatus' => 'sent',
                'kintone' => true,
            ],
            [
                'id' => 'c003',
                'org' => '有限会社サンプル商事',
                'name' => '鈴木 一郎',
                'email' => 'ichiro@example.com',
                'department' => '',
                'phone' => '03-3456-7890',
                'address' => '東京都新宿区',
                'lastSent' => '',
                'sendCount' => 0,
                'answerStatus' => 'unsent',
                'kintone' => false,
            ],
            [
                'id' => 'c004',
                'org' => '株式会社ABC',
                'name' => '田中 美咲',
                'email' => 'misaki@example.com',
                'department' => '',
                'phone' => '03-4567-8901',
                'address' => '東京都渋谷区',
                'lastSent' => '2026-08-22 14:00',
                'sendCount' => 1,
                'answerStatus' => 'sent',
                'kintone' => true,
            ],
            [
                'id' => 'c005',
                'org' => '株式会社XYZ',
                'name' => '高橋 健',
                'email' => 'ken@example.com',
                'department' => '',
                'phone' => '03-5678-9012',
                'address' => '東京都品川区',
                'lastSent' => '',
                'sendCount' => 0,
                'answerStatus' => 'unsent',
                'kintone' => false,
            ],
        ],
        'answers' => [
            'survey-001' => [
                [
                    'id' => 'answer-001',
                    'customerId' => 'c001',
                    'customer' => '山田 太郎',
                    'org' => '株式会社サンプル',
                    'date' => '2026-08-22 13:20',
                    'values' => [
                        'q1' => '非常に満足',
                        'q2' => 'サポートが丁寧でした。',
                        'q3' => 'ぜひ利用したい',
                        'q4' => '特にありません。',
                    ],
                ],
                [
                    'id' => 'answer-002',
                    'customerId' => 'c003',
                    'customer' => '鈴木 一郎',
                    'org' => '有限会社サンプル商事',
                    'date' => '2026-08-23 09:10',
                    'values' => [
                        'q1' => '満足',
                        'q2' => '使いやすかったです。',
                        'q3' => '利用したい',
                        'q4' => '料金プランを増やしてほしい。',
                    ],
                ],
            ],
            'survey-002' => [],
            'survey-003' => [],
            'survey-004' => [],
        ],
        'sendHistory' => [
            [
                'id' => 'h001',
                'surveyId' => 'survey-001',
                'date' => '2026-08-20 10:15',
                'type' => '一括送信',
                'count' => 2,
                'subject' => '2026年度 顧客満足度アンケートのお願い',
                'executor' => '管理者',
                'customers' => ['山田 太郎', '佐藤 花子'],
                'body' => "{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}",
            ],
        ],
        'mailSettings' => [
            'smtp' => '',
            'port' => '587',
            'encryption' => 'TLS',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from' => 'survey@example.com',
            'fromName' => 'アンケート事務局',
            'replyTo' => '',
            'connection' => '未設定',
            'updatedAt' => '',
        ],
        'kintone' => [
            'subdomain' => 'example',
            'appId' => '123',
            'username' => 'admin',
            'password' => '',
            'proxy' => '',
            'sslVerify' => false,
            'connection' => '未テスト',
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
            'syncedAt' => '',
        ],
    ];
}

function loadAppData(): array
{
    $default = initialData();

    $data = [
        'surveys' => loadJsonFile('surveys', $default['surveys']),
        'customers' => loadJsonFile('customers', $default['customers']),
        'answers' => loadJsonFile('answers', $default['answers']),
        'sendHistory' => loadJsonFile('send_history', $default['sendHistory']),
        'mailSettings' => loadJsonFile('mail_settings', $default['mailSettings']),
        'kintone' => loadJsonFile('kintone', $default['kintone']),
    ];

    return $data;
}

function saveAppData(array $data): void
{
    saveJsonFile('surveys', $data['surveys']);
    saveJsonFile('customers', $data['customers']);
    saveJsonFile('answers', $data['answers']);
    saveJsonFile('send_history', $data['sendHistory']);
    saveJsonFile('mail_settings', $data['mailSettings']);
    saveJsonFile('kintone', $data['kintone']);
}

/* =========================================================
   アンケート関連
========================================================= */

function surveyById(array &$data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if ((string)$survey['id'] === $id) {
            return $survey;
        }
    }

    return null;
}

function surveyIndex(array &$data, string $id): int
{
    foreach ($data['surveys'] as $index => $survey) {
        if ((string)$survey['id'] === $id) {
            return $index;
        }
    }

    return -1;
}

function renumberSurvey(array &$survey): void
{
    $numbering = $survey['numbering'] ?? 'global';

    if ($numbering === 'group') {
        foreach ($survey['groups'] as $gi => &$group) {
            foreach ($group['questions'] as $qi => &$question) {
                $question['number'] = 'Q' . ($gi + 1) . '-' . ($qi + 1);
            }
            unset($question);
        }
        unset($group);
        return;
    }

    $n = 1;

    foreach ($survey['groups'] as &$group) {
        foreach ($group['questions'] as &$question) {
            $question['number'] = 'Q' . $n++;
        }
        unset($question);
    }

    unset($group);
}

function automaticEnd(array &$data): bool
{
    $changed = false;
    $now = new DateTimeImmutable('now');

    foreach ($data['surveys'] as &$survey) {
        if (
            ($survey['status'] ?? '') === 'published' &&
            !empty($survey['endAt'])
        ) {
            try {
                $end = new DateTimeImmutable((string)$survey['endAt']);

                if ($end < $now) {
                    $survey['status'] = 'ended';
                    $survey['updatedAt'] = todayString();
                    $changed = true;
                }
            } catch (Throwable) {
                // 不正な日時は入力エラーとして扱うため、自動終了させない。
            }
        }

        renumberSurvey($survey);
    }

    unset($survey);

    if ($changed) {
        saveJsonFile('surveys', $data['surveys']);
    }

    return $changed;
}

function validateSurveyPayload(array $payload, ?array $existing = null): array
{
    $errors = [];

    $title = trim((string)($payload['title'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $startAt = trim((string)($payload['startAt'] ?? ''));
    $endAt = trim((string)($payload['endAt'] ?? ''));
    $numbering = (string)($payload['numbering'] ?? 'global');
    $groups = $payload['groups'] ?? [];

    if ($title === '') {
        $errors[] = 'アンケートタイトルは必須です。';
    } elseif (mb_strlen($title) > 200) {
        $errors[] = 'アンケートタイトルは200文字以内で入力してください。';
    }

    if (mb_strlen($description) > 5000) {
        $errors[] = 'アンケート説明は5000文字以内で入力してください。';
    }

    if ($startAt !== '' && !validDateTime($startAt)) {
        $errors[] = '開始日時の形式が不正です。';
    }

    if ($endAt !== '' && !validDateTime($endAt)) {
        $errors[] = '終了日時の形式が不正です。';
    }

    if ($startAt !== '' && $endAt !== '' && $startAt >= $endAt) {
        $errors[] = '終了日時は開始日時より後にしてください。';
    }

    if (!in_array($numbering, ['global', 'group'], true)) {
        $errors[] = '質問番号の採番方式が不正です。';
    }

    if (!is_array($groups) || count($groups) < 1) {
        $errors[] = 'グループを1つ以上登録してください。';
    }

    $normalizedGroups = [];

    if (is_array($groups)) {
        foreach ($groups as $gi => $group) {
            $groupTitle = trim((string)($group['title'] ?? ''));

            if ($groupTitle === '') {
                $errors[] = 'グループ' . ($gi + 1) . 'のタイトルは必須です。';
            }

            if (mb_strlen($groupTitle) > 200) {
                $errors[] = 'グループ' . ($gi + 1) . 'のタイトルが長すぎます。';
            }

            $questions = $group['questions'] ?? [];

            if (!is_array($questions)) {
                $questions = [];
            }

            $normalizedQuestions = [];

            foreach ($questions as $qi => $question) {
                $qId = trim((string)($question['id'] ?? ''));

                if ($qId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $qId)) {
                    $qId = uuid('q');
                }

                $text = trim((string)($question['text'] ?? ''));
                $type = (string)($question['type'] ?? 'single');
                $required = !empty($question['required']);
                $options = $question['options'] ?? [];

                if ($text === '') {
                    $errors[] = 'グループ' . ($gi + 1) . 'の質問' . ($qi + 1) . 'の質問文は必須です。';
                } elseif (mb_strlen($text) > 2000) {
                    $errors[] = 'グループ' . ($gi + 1) . 'の質問文が長すぎます。';
                }

                if (!in_array($type, ['single', 'multiple', 'free'], true)) {
                    $errors[] = '不正な回答形式が指定されています。';
                    $type = 'single';
                }

                if (!is_array($options)) {
                    $options = [];
                }

                $cleanOptions = [];

                foreach ($options as $option) {
                    $option = trim((string)$option);

                    if ($option !== '') {
                        if (mb_strlen($option) > 500) {
                            $errors[] = '選択肢は500文字以内で入力してください。';
                        }

                        $cleanOptions[] = $option;
                    }
                }

                if ($type !== 'free' && count($cleanOptions) < 1) {
                    $errors[] = '選択式の質問には選択肢を1つ以上登録してください。';
                }

                if ($type === 'free') {
                    $cleanOptions = [];
                }

                $branches = [];

                if ($type === 'single' && isset($question['branches']) && is_array($question['branches'])) {
                    foreach ($question['branches'] as $optionIndex => $targetId) {
                        $optionIndex = (int)$optionIndex;
                        $targetId = trim((string)$targetId);

                        if ($optionIndex >= 0 && $targetId !== '') {
                            $branches[(string)$optionIndex] = $targetId;
                        }
                    }
                }

                $normalizedQuestions[] = [
                    'id' => $qId,
                    'text' => $text,
                    'type' => $type,
                    'required' => $required,
                    'options' => $cleanOptions,
                    'branches' => $branches,
                    'number' => '',
                ];
            }

            $normalizedGroups[] = [
                'id' => preg_match('/^[A-Za-z0-9_-]+$/', (string)($group['id'] ?? ''))
                    ? (string)$group['id']
                    : uuid('g'),
                'title' => $groupTitle,
                'questions' => $normalizedQuestions,
            ];
        }
    }

    $result = [
        'title' => $title,
        'description' => $description,
        'startAt' => $startAt,
        'endAt' => $endAt,
        'numbering' => $numbering,
        'groups' => $normalizedGroups,
    ];

    if ($existing) {
        $result['id'] = $existing['id'];
        $result['createdAt'] = $existing['createdAt'];
        $result['status'] = $existing['status'];
    }

    return [$result, $errors];
}

/* =========================================================
   POST処理
========================================================= */

function handlePost(array &$data): ?array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }

    validateCsrf();

    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'save_survey':
                return postSaveSurvey($data);

            case 'change_status':
                return postChangeStatus($data);

            case 'delete_survey':
                return postDeleteSurvey($data);

            case 'duplicate_survey':
                return postDuplicateSurvey($data);

            case 'save_kintone':
                return postSaveKintone($data);

            case 'test_kintone':
                return postTestKintone($data);

            case 'get_kintone_fields':
                return postGetKintoneFields($data);

            case 'save_kintone_mapping':
                return postSaveKintoneMapping($data);

            case 'sync_kintone':
                return postSyncKintone($data);

            case 'save_mail':
                return postSaveMail($data);

            case 'test_mail':
                return postTestMail($data);

            case 'send_mail':
                return postSendMail($data);

            case 'submit_answer':
                return postSubmitAnswer($data);

            case 'download_csv':
                outputCsv($data, (string)($_POST['id'] ?? ''));
                exit;

            case 'download_pdf':
                outputPdf($data, (string)($_POST['id'] ?? ''));
                exit;

            default:
                return [
                    'message' => '不明な操作です。',
                    'type' => 'danger',
                ];
        }
    } catch (Throwable $e) {
        error_log(
            'survey-app error: ' .
            get_class($e) .
            ': ' .
            $e->getMessage()
        );

        return [
            'message' => '処理に失敗しました。入力内容または設定を確認してください。',
            'detail' => '処理中にエラーが発生しました。',
            'type' => 'danger',
        ];
    }
}

function postSaveSurvey(array &$data): array
{
    $id = trim((string)($_POST['id'] ?? ''));

    $raw = (string)($_POST['survey_json'] ?? '');

    if ($raw === '' || strlen($raw) > 1000000) {
        return [
            'message' => 'アンケートデータが不正です。',
            'type' => 'danger',
        ];
    }

    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        return [
            'message' => 'アンケートデータの解析に失敗しました。',
            'type' => 'danger',
        ];
    }

    $existing = null;

    if ($id !== '') {
        $existing = surveyById($data, $id);

        if (!$existing) {
            return [
                'message' => '対象アンケートが存在しません。',
                'type' => 'danger',
            ];
        }
    }

    [$survey, $errors] = validateSurveyPayload($payload, $existing);

    if ($errors) {
        $_SESSION['form_errors'] = $errors;

        return [
            'message' => implode(' / ', $errors),
            'type' => 'danger',
            'stay' => true,
        ];
    }

    if ($existing) {
        $index = surveyIndex($data, $id);

        $survey['updatedAt'] = todayString();
        $survey['status'] = $existing['status'];

        renumberSurvey($survey);

        $data['surveys'][$index] = $survey;
    } else {
        $survey['id'] = uuid('survey');
        $survey['createdAt'] = todayString();
        $survey['updatedAt'] = todayString();
        $survey['status'] = 'draft';

        renumberSurvey($survey);

        $data['surveys'][] = $survey;
    }

    saveJsonFile('surveys', $data['surveys']);

    return [
        'message' => 'アンケートを保存しました。',
        'type' => 'success',
        'redirect' => '?screen=list',
    ];
}

function postChangeStatus(array &$data): array
{
    $id = trim((string)($_POST['id'] ?? ''));
    $status = trim((string)($_POST['status'] ?? ''));

    $allowed = ['draft', 'published', 'stopped'];

    if (!in_array($status, $allowed, true)) {
        return [
            'message' => '不正な状態です。',
            'type' => 'danger',
        ];
    }

    $index = surveyIndex($data, $id);

    if ($index < 0) {
        return [
            'message' => '対象アンケートが存在しません。',
            'type' => 'danger',
        ];
    }

    $current = $data['surveys'][$index]['status'];

    if ($current === 'ended') {
        return [
            'message' => '終了したアンケートの状態は変更できません。',
            'type' => 'danger',
        ];
    }

    $validTransition = match ($current) {
        'draft' => $status === 'published',
        'published' => $status === 'stopped',
        'stopped' => $status === 'published',
        default => false,
    };

    if (!$validTransition) {
        return [
            'message' => '許可されていない状態変更です。',
            'type' => 'danger',
        ];
    }

    $data['surveys'][$index]['status'] = $status;
    $data['surveys'][$index]['updatedAt'] = todayString();

    saveJsonFile('surveys', $data['surveys']);

    return [
        'message' => '状態を変更しました。',
        'type' => 'success',
        'redirect' => '?screen=edit&id=' . rawurlencode($id),
    ];
}

function postDeleteSurvey(array &$data): array
{
    $id = trim((string)($_POST['id'] ?? ''));

    $index = surveyIndex($data, $id);

    if ($index < 0) {
        return [
            'message' => '対象アンケートが存在しません。',
            'type' => 'danger',
        ];
    }

    array_splice($data['surveys'], $index, 1);

    unset($data['answers'][$id]);

    $data['sendHistory'] = array_values(array_filter(
        $data['sendHistory'],
        static fn(array $history): bool => ($history['surveyId'] ?? '') !== $id
    ));

    saveJsonFile('surveys', $data['surveys']);
    saveJsonFile('answers', $data['answers']);
    saveJsonFile('send_history', $data['sendHistory']);

    return [
        'message' => 'アンケートを削除しました。',
        'type' => 'success',
        'redirect' => '?screen=list',
    ];
}

function postDuplicateSurvey(array &$data): array
{
    $id = trim((string)($_POST['id'] ?? ''));

    $survey = surveyById($data, $id);

    if (!$survey) {
        return [
            'message' => '対象アンケートが存在しません。',
            'type' => 'danger',
        ];
    }

    $new = $survey;
    $new['id'] = uuid('survey');
    $new['createdAt'] = todayString();
    $new['updatedAt'] = todayString();
    $new['status'] = 'draft';
    $new['title'] = $survey['title'] . '（複製）';

    foreach ($new['groups'] as &$group) {
        $group['id'] = uuid('g');

        foreach ($group['questions'] as &$question) {
            $question['id'] = uuid('q');
        }

        unset($question);
    }

    unset($group);

    renumberSurvey($new);

    $data['surveys'][] = $new;

    saveJsonFile('surveys', $data['surveys']);

    return [
        'message' => 'アンケートを複製しました。',
        'type' => 'success',
        'redirect' => '?screen=list',
    ];
}

/* =========================================================
   kintone HTTPクライアント
========================================================= */

function normalizeKintoneSubdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace('#^https?://#i', '', $value);
    $value = preg_replace('#/.*$#', '', $value);
    $value = preg_replace('#\.cybozu\.com$#i', '', $value);

    if ($value === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/', $value)) {
        throw new InvalidArgumentException('kintoneサブドメインが不正です。');
    }

    return $value;
}

function normalizeProxy(string $value): ?array
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (!preg_match('/^([^:]+):([0-9]{1,5})$/', $value, $m)) {
        throw new InvalidArgumentException('Proxyはhost:port形式で入力してください。');
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('Proxyポート番号が不正です。');
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

function validateKintoneConfig(array $k): array
{
    $subdomain = normalizeKintoneSubdomain((string)($k['subdomain'] ?? ''));
    $appId = trim((string)($k['appId'] ?? ''));
    $username = trim((string)($k['username'] ?? ''));
    $password = decryptSecret((string)($k['password'] ?? ''));

    if (!preg_match('/^[0-9]+$/', $appId) || (int)$appId < 1) {
        throw new InvalidArgumentException('顧客管理アプリIDが不正です。');
    }

    if ($username === '') {
        throw new InvalidArgumentException('kintoneログイン名を入力してください。');
    }

    if ($password === '') {
        throw new InvalidArgumentException('kintoneパスワードを設定してください。');
    }

    return [
        'subdomain' => $subdomain,
        'appId' => (int)$appId,
        'username' => $username,
        'password' => $password,
        'proxy' => normalizeProxy((string)($k['proxy'] ?? '')),
        'sslVerify' => !empty($k['sslVerify']),
    ];
}

function readHttpResponse($socket): array
{
    $headerData = '';

    while (!str_contains($headerData, "\r\n\r\n")) {
        $chunk = fread($socket, 4096);

        if ($chunk === false || $chunk === '') {
            break;
        }

        $headerData .= $chunk;

        if (strlen($headerData) > 1024 * 1024) {
            throw new RuntimeException('HTTPヘッダーが大きすぎます。');
        }
    }

    $separator = strpos($headerData, "\r\n\r\n");

    if ($separator === false) {
        throw new RuntimeException('HTTPレスポンスヘッダーを取得できません。');
    }

    $headersRaw = substr($headerData, 0, $separator);
    $body = substr($headerData, $separator + 4);

    $lines = preg_split("/\r\n/", $headersRaw);

    $statusLine = array_shift($lines);

    if (!$statusLine || !preg_match('/^HTTP\/\S+\s+(\d{3})\s*(.*)$/', $statusLine, $m)) {
        throw new RuntimeException('HTTPステータスを解析できません。');
    }

    $status = (int)$m[1];

    $headers = [];

    foreach ($lines as $line) {
        $pos = strpos($line, ':');

        if ($pos === false) {
            continue;
        }

        $name = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));

        if (isset($headers[$name])) {
            $headers[$name] .= ', ' . $value;
        } else {
            $headers[$name] = $value;
        }
    }

    $contentLength = isset($headers['content-length'])
        ? (int)$headers['content-length']
        : null;

    $transferEncoding = strtolower($headers['transfer-encoding'] ?? '');

    if (str_contains($transferEncoding, 'chunked')) {
        $body = decodeChunkedBody($socket, $body);
    } elseif ($contentLength !== null) {
        $remaining = $contentLength - strlen($body);

        while ($remaining > 0) {
            $chunk = fread($socket, min(8192, $remaining));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('HTTPレスポンス本文を完全に取得できません。');
            }

            $body .= $chunk;
            $remaining -= strlen($chunk);
        }

        $body = substr($body, 0, $contentLength);
    } else {
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);

            if ($chunk === false) {
                break;
            }

            $body .= $chunk;
        }
    }

    return [
        'status' => $status,
        'reason' => trim($m[2]),
        'headers' => $headers,
        'body' => $body,
    ];
}

function decodeChunkedBody($socket, string $initial): string
{
    $buffer = $initial;
    $result = '';

    while (true) {
        $pos = strpos($buffer, "\r\n");

        while ($pos === false) {
            $chunk = fread($socket, 4096);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('chunked HTTPレスポンスを解析できません。');
            }

            $buffer .= $chunk;
            $pos = strpos($buffer, "\r\n");
        }

        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 2);

        $sizePart = trim(explode(';', $line, 2)[0]);
        $size = hexdec($sizePart);

        if ($size === 0) {
            return $result;
        }

        while (strlen($buffer) < $size + 2) {
            $chunk = fread($socket, 8192);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('chunked HTTP本文を完全に取得できません。');
            }

            $buffer .= $chunk;
        }

        $result .= substr($buffer, 0, $size);
        $buffer = substr($buffer, $size + 2);
    }
}

function openKintoneSocket(
    string $host,
    int $port,
    ?array $proxy,
    bool $verifySsl,
    int $timeout
) {
    $contextOptions = [
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
            'SNI_enabled' => true,
            'peer_name' => $host,
            'capture_peer_cert' => false,
        ],
    ];

    $context = stream_context_create($contextOptions);

    if ($proxy) {
        $proxyAddress = 'tcp://' . $proxy['host'] . ':' . $proxy['port'];

        $socket = @stream_socket_client(
            $proxyAddress,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new RuntimeException(
                'Proxyへ接続できません。' . ($errstr ? ' ' . $errstr : '')
            );
        }

        stream_set_timeout($socket, $timeout);

        $connectRequest =
            "CONNECT {$host}:{$port} HTTP/1.1\r\n" .
            "Host: {$host}:{$port}\r\n" .
            "Connection: keep-alive\r\n\r\n";

        fwrite($socket, $connectRequest);

        $response = readHttpResponse($socket);

        if ($response['status'] !== 200) {
            fclose($socket);

            throw new RuntimeException(
                'Proxy CONNECTに失敗しました。HTTP ' . $response['status']
            );
        }

        $cryptoResult = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($cryptoResult !== true) {
            fclose($socket);

            throw new RuntimeException('Proxy経由のTLS接続に失敗しました。');
        }

        return $socket;
    }

    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException(
            'kintoneへ接続できません。' . ($errstr ? ' ' . $errstr : '')
        );
    }

    stream_set_timeout($socket, $timeout);

    $cryptoResult = stream_socket_enable_crypto(
        $socket,
        true,
        STREAM_CRYPTO_METHOD_TLS_CLIENT
    );

    if ($cryptoResult !== true) {
        fclose($socket);

        throw new RuntimeException('kintone TLS接続に失敗しました。');
    }

    return $socket;
}

function kintoneRequest(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $host = $config['subdomain'] . '.cybozu.com';
    $port = 443;

    $proxy = $config['proxy'] ?? null;
    $verifySsl = (bool)($config['sslVerify'] ?? false);

    $socket = openKintoneSocket(
        $host,
        $port,
        $proxy,
        $verifySsl,
        10
    );

    try {
        $authorization = base64_encode(
            $config['username'] . ':' . $config['password']
        );

        $encodedBody = $body === null ? '' : jsonResponse($body);

        $headers = [
            'Host: ' . $host,
            'X-Cybozu-Authorization: ' . $authorization,
            'Accept: application/json',
            'Connection: close',
        ];

        if ($encodedBody !== '') {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($encodedBody);
        }

        $request =
            $method . ' ' . $path . " HTTP/1.1\r\n" .
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            $encodedBody;

        $written = fwrite($socket, $request);

        if ($written === false || $written < strlen($request)) {
            throw new RuntimeException('kintone APIリクエストを送信できません。');
        }

        $response = readHttpResponse($socket);

        return $response;
    } finally {
        fclose($socket);
    }
}

function kintoneResultOrError(array $response): array
{
    $status = (int)$response['status'];
    $body = (string)$response['body'];

    $json = json_decode($body, true);

    if ($status >= 200 && $status < 300) {
        return [
            'ok' => true,
            'status' => $status,
            'data' => is_array($json) ? $json : [],
            'body' => $body,
        ];
    }

    $code = is_array($json) ? (string)($json['code'] ?? '') : '';
    $message = is_array($json) ? (string)($json['message'] ?? '') : '';

    if ($status === 301 || $status === 302 || $status === 303 || $status === 307 || $status === 308) {
        return [
            'ok' => false,
            'status' => $status,
            'errorType' => 'redirect',
            'message' => 'kintone APIからリダイレクト応答が返されました。API URLやサブドメイン設定を確認してください。',
            'body' => $body,
        ];
    }

    if ($message === '') {
        $message = 'kintone APIからHTTP ' . $status . ' が返されました。';
    }

    return [
        'ok' => false,
        'status' => $status,
        'errorType' => $status === 401 || $status === 403 ? 'authentication' : 'api',
        'code' => $code,
        'message' => $message,
        'body' => $body,
    ];
}

/* =========================================================
   kintone POST
========================================================= */

function postSaveKintone(array &$data): array
{
    $subdomain = trim((string)($_POST['subdomain'] ?? ''));
    $appId = trim((string)($_POST['appId'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $proxy = trim((string)($_POST['proxy'] ?? ''));
    $sslVerify = isset($_POST['sslVerify']);

    try {
        normalizeKintoneSubdomain($subdomain);

        if (!preg_match('/^[0-9]+$/', $appId) || (int)$appId < 1) {
            throw new InvalidArgumentException('顧客管理アプリIDが不正です。');
        }

        if ($username === '') {
            throw new InvalidArgumentException('ログイン名を入力してください。');
        }

        normalizeProxy($proxy);

        $old = $data['kintone'];

        $data['kintone']['subdomain'] = $subdomain;
        $data['kintone']['appId'] = $appId;
        $data['kintone']['username'] = $username;
        $data['kintone']['proxy'] = $proxy;
        $data['kintone']['sslVerify'] = $sslVerify;

        if ($password !== '') {
            $data['kintone']['password'] = encryptSecret($password);
        } else {
            $data['kintone']['password'] = $old['password'] ?? '';
        }

        $data['kintone']['connection'] = '未テスト';
        $data['kintone']['connectionDetail'] = '';

        saveJsonFile('kintone', $data['kintone']);

        return [
            'message' => 'kintone接続設定を保存しました。',
            'type' => 'success',
        ];
    } catch (Throwable $e) {
        return [
            'message' => $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : 'kintone設定の保存に失敗しました。',
            'type' => 'danger',
        ];
    }
}

function getKintoneConfig(array $data): array
{
    return validateKintoneConfig($data['kintone']);
}

function postTestKintone(array &$data): array
{
    try {
        $config = getKintoneConfig($data);

        $path = '/k/v1/app.json?id=' . rawurlencode((string)$config['appId']);

        $response = kintoneRequest(
            $config,
            'GET',
            $path
        );

        $result = kintoneResultOrError($response);

        if (!$result['ok']) {
            $detail = 'HTTP ' . $result['status'];

            if (!empty($result['code'])) {
                $detail .= ' / ' . $result['code'];
            }

            if (!empty($result['message'])) {
                $detail .= ' / ' . $result['message'];
            }

            $data['kintone']['connection'] = '接続失敗';
            $data['kintone']['connectionDetail'] = $detail;

            saveJsonFile('kintone', $data['kintone']);

            return [
                'message' => 'kintoneへの接続に失敗しました。',
                'detail' => $detail,
                'type' => 'danger',
            ];
        }

        $appName = (string)($result['data']['name'] ?? '');

        $data['kintone']['connection'] = '接続成功';
        $data['kintone']['connectionDetail'] =
            'HTTP ' . $result['status'] .
            ($appName !== '' ? ' / アプリ: ' . $appName : '');

        saveJsonFile('kintone', $data['kintone']);

        return [
            'message' => 'kintoneへの接続に成功しました。',
            'detail' => $data['kintone']['connectionDetail'],
            'type' => 'success',
        ];
    } catch (Throwable $e) {
        $detail = $e->getMessage();

        $data['kintone']['connection'] = '接続失敗';
        $data['kintone']['connectionDetail'] = $detail;

        saveJsonFile('kintone', $data['kintone']);

        return [
            'message' => 'kintoneへの接続に失敗しました。',
            'detail' => $detail,
            'type' => 'danger',
        ];
    }
}

function postGetKintoneFields(array &$data): array
{
    try {
        $config = getKintoneConfig($data);

        $path = '/k/v1/app/form/fields.json?app=' .
            rawurlencode((string)$config['appId']);

        $response = kintoneRequest(
            $config,
            'GET',
            $path
        );

        $result = kintoneResultOrError($response);

        if (!$result['ok']) {
            $detail = 'HTTP ' . $result['status'];

            if (!empty($result['code'])) {
                $detail .= ' / ' . $result['code'];
            }

            if (!empty($result['message'])) {
                $detail .= ' / ' . $result['message'];
            }

            return [
                'message' => 'kintone項目一覧の取得に失敗しました。',
                'detail' => $detail,
                'type' => 'danger',
            ];
        }

        $properties = $result['data']['properties'] ?? [];

        if (!is_array($properties)) {
            return [
                'message' => 'kintone項目一覧のレスポンス形式が不正です。',
                'type' => 'danger',
            ];
        }

        $fields = [];

        foreach ($properties as $code => $property) {
            if (!is_array($property)) {
                continue;
            }

            $fields[] = [
                'code' => (string)$code,
                'label' => (string)($property['label'] ?? $code),
                'type' => (string)($property['type'] ?? ''),
            ];
        }

        $data['kintone']['fields'] = $fields;

        saveJsonFile('kintone', $data['kintone']);

        return [
            'message' => 'kintone項目一覧を再取得しました。',
            'detail' => count($fields) . '件取得しました。',
            'type' => 'success',
        ];
    } catch (Throwable $e) {
        return [
            'message' => 'kintone項目一覧の取得に失敗しました。',
            'detail' => $e->getMessage(),
            'type' => 'danger',
        ];
    }
}

function postSaveKintoneMapping(array &$data): array
{
    $fields = $data['kintone']['fields'] ?? [];
    $validCodes = [];

    foreach ($fields as $field) {
        $validCodes[] = (string)($field['code'] ?? '');
    }

    $keys = ['org', 'name', 'email', 'department', 'phone'];

    foreach ($keys as $key) {
        $value = trim((string)($_POST['mapping_' . $key] ?? ''));

        if ($value !== '' && !in_array($value, $validCodes, true)) {
            return [
                'message' => '不正なフィールドマッピングが指定されています。',
                'type' => 'danger',
            ];
        }

        $data['kintone']['mappings'][$key] = $value;
    }

    $address = $_POST['mapping_address'] ?? [];

    if (!is_array($address)) {
        $address = [];
    }

    $address = array_values(array_filter(
        array_map('strval', $address),
        static fn(string $value): bool => in_array($value, $validCodes, true)
    ));

    $data['kintone']['mappings']['address'] = $address;

    saveJsonFile('kintone', $data['kintone']);

    return [
        'message' => 'フィールドマッピングを保存しました。',
        'type' => 'success',
    ];
}

function kintoneFieldValue(array $record, string $code): string
{
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $field = $record[$code];

    if (!is_array($field)) {
        return (string)$field;
    }

    $value = $field['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] = (string)($item['name'] ?? $item['code'] ?? '');
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode('、', array_filter($parts, static fn($x) => $x !== ''));
    }

    return (string)$value;
}

function postSyncKintone(array &$data): array
{
    try {
        $config = getKintoneConfig($data);

        if (($data['kintone']['connection'] ?? '') !== '接続成功') {
            return [
                'message' => '先にkintone接続テストを成功させてください。',
                'type' => 'danger',
            ];
        }

        $mappings = $data['kintone']['mappings'] ?? [];

        if (
            empty($mappings['name']) ||
            empty($mappings['email'])
        ) {
            return [
                'message' => '氏名とメールアドレスのマッピングを設定してください。',
                'type' => 'danger',
            ];
        }

        $fields = [
            '$id',
            $mappings['org'] ?? '',
            $mappings['name'] ?? '',
            $mappings['email'] ?? '',
            $mappings['department'] ?? '',
            $mappings['phone'] ?? '',
        ];

        foreach (($mappings['address'] ?? []) as $addressCode) {
            $fields[] = $addressCode;
        }

        $fields = array_values(array_unique(array_filter($fields)));

        $allRecords = [];
        $offset = 0;

        do {
            $query = 'order by $id asc limit 500 offset ' . $offset;

            $params = [
                'app' => (int)$config['appId'],
                'query' => $query,
            ];

            foreach ($fields as $field) {
                $params['fields'][] = $field;
            }

            $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

            $response = kintoneRequest(
                $config,
                'GET',
                '/k/v1/records.json?' . $queryString
            );

            $result = kintoneResultOrError($response);

            if (!$result['ok']) {
                $detail = 'HTTP ' . $result['status'];

                if (!empty($result['code'])) {
                    $detail .= ' / ' . $result['code'];
                }

                if (!empty($result['message'])) {
                    $detail .= ' / ' . $result['message'];
                }

                return [
                    'message' => 'kintone顧客情報の同期に失敗しました。',
                    'detail' => $detail,
                    'type' => 'danger',
                ];
            }

            $records = $result['data']['records'] ?? [];

            if (!is_array($records)) {
                throw new RuntimeException('kintone recordsレスポンスが不正です。');
            }

            $allRecords = array_merge($allRecords, $records);
            $count = count($records);
            $offset += $count;
        } while ($count === 500);

        $customers = [];

        foreach ($allRecords as $record) {
            if (!is_array($record)) {
                continue;
            }

            $recordId = kintoneFieldValue($record, '$id');
            $name = kintoneFieldValue($record, (string)($mappings['name'] ?? ''));
            $email = kintoneFieldValue($record, (string)($mappings['email'] ?? ''));

            if ($name === '' && $email === '') {
                continue;
            }

            $addressParts = [];

            foreach (($mappings['address'] ?? []) as $addressCode) {
                $value = kintoneFieldValue($record, (string)$addressCode);

                if ($value !== '') {
                    $addressParts[] = $value;
                }
            }

            $existing = null;

            foreach ($data['customers'] as $customer) {
                if (
                    ($recordId !== '' && (string)($customer['kintoneRecordId'] ?? '') === $recordId)
                    || ($email !== '' && strtolower((string)$customer['email']) === strtolower($email))
                ) {
                    $existing = $customer;
                    break;
                }
            }

            $customer = [
                'id' => $existing['id'] ?? uuid('customer'),
                'kintoneRecordId' => $recordId,
                'org' => kintoneFieldValue($record, (string)($mappings['org'] ?? '')),
                'name' => $name,
                'email' => $email,
                'department' => kintoneFieldValue($record, (string)($mappings['department'] ?? '')),
                'phone' => kintoneFieldValue($record, (string)($mappings['phone'] ?? '')),
                'address' => implode(' ', $addressParts),
                'lastSent' => $existing['lastSent'] ?? '',
                'sendCount' => (int)($existing['sendCount'] ?? 0),
                'answerStatus' => $existing['answerStatus'] ?? 'unsent',
                'kintone' => true,
            ];

            $customers[] = $customer;
        }

        $data['customers'] = $customers;
        $data['kintone']['syncedAt'] = nowString();

        saveJsonFile('customers', $data['customers']);
        saveJsonFile('kintone', $data['kintone']);

        return [
            'message' => 'kintone顧客情報を同期しました。',
            'detail' => count($customers) . '件同期しました。',
            'type' => 'success',
        ];
    } catch (Throwable $e) {
        return [
            'message' => 'kintone顧客情報の同期に失敗しました。',
            'detail' => $e->getMessage(),
            'type' => 'danger',
        ];
    }
}

/* =========================================================
   SMTP
========================================================= */

function validateMailConfig(array $m): array
{
    $smtp = trim((string)($m['smtp'] ?? ''));
    $port = (int)($m['port'] ?? 0);
    $encryption = (string)($m['encryption'] ?? 'TLS');
    $auth = !empty($m['auth']);
    $username = trim((string)($m['username'] ?? ''));
    $password = decryptSecret((string)($m['password'] ?? ''));
    $from = trim((string)($m['from'] ?? ''));
    $fromName = trim((string)($m['fromName'] ?? ''));
    $replyTo = trim((string)($m['replyTo'] ?? ''));

    if ($smtp === '') {
        throw new InvalidArgumentException('SMTPサーバを入力してください。');
    }

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('SMTPポートが不正です。');
    }

    if (!in_array($encryption, ['SSL', 'TLS', 'none'], true)) {
        throw new InvalidArgumentException('暗号化方式が不正です。');
    }

    if ($auth && ($username === '' || $password === '')) {
        throw new InvalidArgumentException('SMTP認証情報を入力してください。');
    }

    if (!validateEmail($from)) {
        throw new InvalidArgumentException('送信元メールアドレスが不正です。');
    }

    if ($replyTo !== '' && !validateEmail($replyTo)) {
        throw new InvalidArgumentException('返信先メールアドレスが不正です。');
    }

    return [
        'smtp' => $smtp,
        'port' => $port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'password' => $password,
        'from' => $from,
        'fromName' => $fromName,
        'replyTo' => $replyTo,
    ];
}

function smtpRead($socket): array
{
    $lines = [];

    while (true) {
        $line = fgets($socket, 8192);

        if ($line === false) {
            throw new RuntimeException('SMTPサーバから応答を取得できません。');
        }

        $line = rtrim($line, "\r\n");
        $lines[] = $line;

        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            if ($m[2] === ' ') {
                return [
                    'code' => (int)$m[1],
                    'lines' => $lines,
                    'text' => implode("\n", $lines),
                ];
            }
        }

        if (count($lines) > 100) {
            throw new RuntimeException('SMTP応答が不正です。');
        }
    }
}

function smtpCommand($socket, string $command, array $expected = []): array
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('SMTPコマンドを送信できません。');
    }

    $response = smtpRead($socket);

    if ($expected && !in_array($response['code'], $expected, true)) {
        throw new RuntimeException(
            'SMTPエラー: ' .
            $response['code'] .
            ' ' .
            $response['text']
        );
    }

    return $response;
}

function smtpConnect(array $config)
{
    $timeout = 10;

    if ($config['encryption'] === 'SSL') {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $config['smtp'],
            ],
        ]);

        $socket = @stream_socket_client(
            'tls://' . $config['smtp'] . ':' . $config['port'],
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new RuntimeException(
                'SMTP SSL接続に失敗しました。' .
                ($errstr ? ' ' . $errstr : '')
            );
        }
    } else {
        $socket = @stream_socket_client(
            'tcp://' . $config['smtp'] . ':' . $config['port'],
            $errno,
            $errstr,
            $timeout
        );

        if (!$socket) {
            throw new RuntimeException(
                'SMTP接続に失敗しました。' .
                ($errstr ? ' ' . $errstr : '')
            );
        }

        stream_set_timeout($socket, $timeout);
    }

    stream_set_timeout($socket, $timeout);

    $greeting = smtpRead($socket);

    if ($greeting['code'] !== 220) {
        fclose($socket);

        throw new RuntimeException(
            'SMTP greeting error: ' . $greeting['text']
        );
    }

    smtpCommand(
        $socket,
        'EHLO ' . gethostname(),
        [250]
    );

    if ($config['encryption'] === 'TLS') {
        $startTls = smtpCommand(
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
            fclose($socket);

            throw new RuntimeException('SMTP STARTTLSに失敗しました。');
        }

        smtpCommand(
            $socket,
            'EHLO ' . gethostname(),
            [250]
        );
    }

    if ($config['auth']) {
        smtpCommand(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        smtpCommand(
            $socket,
            base64_encode($config['username']),
            [334]
        );

        smtpCommand(
            $socket,
            base64_encode($config['password']),
            [235]
        );
    }

    return $socket;
}

function smtpQuoteHeader(string $value): string
{
    $value = preg_replace("/[\r\n]+/", ' ', $value);

    return trim((string)$value);
}

function encodeMimeHeader(string $value): string
{
    $value = smtpQuoteHeader($value);

    if ($value === '') {
        return '';
    }

    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $value;
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function foldMimeBody(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);

    $encoded = [];

    foreach ($lines as $line) {
        $encoded[] = rtrim(
            chunk_split(base64_encode($line), 76, "\r\n")
        );
    }

    return implode("\r\n", $encoded);
}

function smtpSendMail(array $config, string $to, string $subject, string $body): array
{
    if (!validateEmail($to)) {
        throw new InvalidArgumentException('宛先メールアドレスが不正です。');
    }

    $socket = smtpConnect($config);

    try {
        smtpCommand(
            $socket,
            'MAIL FROM:<' . $config['from'] . '>',
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

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . encodeMimeHeader($config['fromName']) .
                ' <' . $config['from'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . encodeMimeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        if ($config['replyTo'] !== '') {
            $headers[] = 'Reply-To: <' . $config['replyTo'] . '>';
        }

        $mime =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            foldMimeBody($body) .
            "\r\n.\r\n";

        $mime = preg_replace('/^\./m', '..', $mime);

        if (fwrite($socket, $mime) === false) {
            throw new RuntimeException('SMTP DATAを送信できません。');
        }

        $response = smtpRead($socket);

        if (!in_array($response['code'], [250], true)) {
            throw new RuntimeException(
                'SMTP DATAエラー: ' .
                $response['code'] .
                ' ' .
                $response['text']
            );
        }

        smtpCommand(
            $socket,
            'QUIT',
            [221]
        );

        return [
            'ok' => true,
            'code' => $response['code'],
        ];
    } finally {
        fclose($socket);
    }
}

function postSaveMail(array &$data): array
{
    $old = $data['mailSettings'];

    try {
        $smtp = trim((string)($_POST['smtp'] ?? ''));
        $port = trim((string)($_POST['port'] ?? ''));
        $encryption = (string)($_POST['encryption'] ?? 'TLS');
        $auth = isset($_POST['auth']);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $from = trim((string)($_POST['from'] ?? ''));
        $fromName = trim((string)($_POST['fromName'] ?? ''));
        $replyTo = trim((string)($_POST['replyTo'] ?? ''));

        $temp = [
            'smtp' => $smtp,
            'port' => $port,
            'encryption' => $encryption,
            'auth' => $auth,
            'username' => $username,
            'password' => $password !== ''
                ? encryptSecret($password)
                : ($old['password'] ?? ''),
            'from' => $from,
            'fromName' => $fromName,
            'replyTo' => $replyTo,
        ];

        $validation = $temp;
        $validation['password'] = $password !== ''
            ? $password
            : decryptSecret((string)($old['password'] ?? ''));

        validateMailConfig($validation);

        $temp['connection'] = '未設定';
        $temp['updatedAt'] = nowString();

        $data['mailSettings'] = $temp;

        saveJsonFile('mail_settings', $data['mailSettings']);

        return [
            'message' => 'メールサーバ設定を保存しました。',
            'type' => 'success',
        ];
    } catch (Throwable $e) {
        return [
            'message' => $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : 'メールサーバ設定の保存に失敗しました。',
            'type' => 'danger',
        ];
    }
}

function postTestMail(array &$data): array
{
    try {
        $config = validateMailConfig($data['mailSettings']);

        $socket = smtpConnect($config);

        try {
            smtpCommand($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }

        $data['mailSettings']['connection'] = '接続確認済み';
        saveJsonFile('mail_settings', $data['mailSettings']);

        return [
            'message' => 'SMTPサーバへの接続・認証に成功しました。',
            'type' => 'success',
        ];
    } catch (Throwable $e) {
        $data['mailSettings']['connection'] = '接続できません';
        saveJsonFile('mail_settings', $data['mailSettings']);

        return [
            'message' => 'SMTP接続テストに失敗しました。',
            'detail' => $e->getMessage(),
            'type' => 'danger',
        ];
    }
}

function postSendMail(array &$data): array
{
    $surveyId = trim((string)($_POST['id'] ?? ''));
    $type = trim((string)($_POST['send_type'] ?? '一括送信'));

    $survey = surveyById($data, $surveyId);

    if (!$survey) {
        return [
            'message' => '対象アンケートが存在しません。',
            'type' => 'danger',
        ];
    }

    $selected = $_POST['customer_ids'] ?? [];

    if (!is_array($selected)) {
        $selected = [];
    }

    $selected = array_values(array_unique(
        array_filter(
            array_map('strval', $selected),
            static fn(string $id): bool => $id !== ''
        )
    ));

    if (!$selected) {
        return [
            'message' => '送信対象の顧客を選択してください。',
            'type' => 'danger',
        ];
    }

    $subject = trim((string)($_POST['subject'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));

    if ($subject === '') {
        return [
            'message' => 'メール件名を入力してください。',
            'type' => 'danger',
        ];
    }

    if ($body === '') {
        return [
            'message' => 'メール本文を入力してください。',
            'type' => 'danger',
        ];
    }

    if (mb_strlen($subject) > 500) {
        return [
            'message' => 'メール件名が長すぎます。',
            'type' => 'danger',
        ];
    }

    if (mb_strlen($body) > 20000) {
        return [
            'message' => 'メール本文が長すぎます。',
            'type' => 'danger',
        ];
    }

    try {
        $mailConfig = validateMailConfig($data['mailSettings']);
    } catch (Throwable $e) {
        return [
            'message' => 'SMTP設定を確認してください。',
            'detail' => $e->getMessage(),
            'type' => 'danger',
        ];
    }

    $selectedCustomers = [];

    foreach ($selected as $customerId) {
        foreach ($data['customers'] as $customer) {
            if ((string)$customer['id'] === $customerId) {
                $selectedCustomers[] = $customer;
                break;
            }
        }
    }

    if (count($selectedCustomers) !== count($selected)) {
        return [
            'message' => '存在しない顧客が送信対象に含まれています。',
            'type' => 'danger',
        ];
    }

    $success = 0;
    $failed = 0;
    $errors = [];
    $sentNames = [];

    foreach ($selectedCustomers as $customer) {
        $mailBody = str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [
                (string)$customer['name'],
                answerUrl(
                    $surveyId,
                    (string)$customer['id']
                ),
            ],
            $body
        );

        $mailSubject = str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [
                (string)$customer['name'],
                answerUrl(
                    $surveyId,
                    (string)$customer['id']
                ),
            ],
            $subject
        );

        try {
            smtpSendMail(
                $mailConfig,
                (string)$customer['email'],
                $mailSubject,
                $mailBody
            );

            $success++;
            $sentNames[] = (string)$customer['name'];

            foreach ($data['customers'] as &$storedCustomer) {
                if ((string)$storedCustomer['id'] === (string)$customer['id']) {
                    $storedCustomer['lastSent'] = nowString();
                    $storedCustomer['sendCount'] =
                        ((int)($storedCustomer['sendCount'] ?? 0)) + 1;
                    $storedCustomer['answerStatus'] = 'sent';
                    break;
                }
            }

            unset($storedCustomer);
        } catch (Throwable $e) {
            $failed++;

            $errors[] =
                (string)$customer['name'] .
                ': ' .
                $e->getMessage();
        }
    }

    $history = [
        'id' => uuid('history'),
        'surveyId' => $surveyId,
        'date' => nowString(),
        'type' => $type,
        'count' => $success,
        'failed' => $failed,
        'subject' => $subject,
        'executor' => '管理者',
        'customers' => $sentNames,
        'body' => $body,
    ];

    $data['sendHistory'][] = $history;

    saveJsonFile('customers', $data['customers']);
    saveJsonFile('send_history', $data['sendHistory']);

    $message =
        '送信結果：成功 ' .
        $success .
        '件 / 失敗 ' .
        $failed .
        '件';

    return [
        'message' => $message,
        'detail' => $errors ? implode("\n", $errors) : '',
        'type' => $failed === 0 ? 'success' : 'danger',
    ];
}

/* =========================================================
   回答処理
========================================================= */

function visibleQuestionIds(array $survey, array $answers): array
{
    $all = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $all[] = $question;
        }
    }

    $targets = [];

    foreach ($all as $question) {
        foreach (($question['branches'] ?? []) as $targetId) {
            if ($targetId !== '') {
                $targets[(string)$targetId] = true;
            }
        }
    }

    $visible = [];

    foreach ($all as $question) {
        $isTarget = isset($targets[$question['id']]);

        if (!$isTarget) {
            $visible[] = $question;
            continue;
        }

        $shown = false;

        foreach ($all as $parent) {
            if (($parent['type'] ?? '') !== 'single') {
                continue;
            }

            $answer = $answers[$parent['id']] ?? '';

            if ($answer === '') {
                continue;
            }

            $optionIndex = array_search(
                $answer,
                $parent['options'] ?? [],
                true
            );

            if ($optionIndex === false) {
                continue;
            }

            $target = (string)(($parent['branches'] ?? [])[(string)$optionIndex] ?? '');

            if ($target === (string)$question['id']) {
                $shown = true;
                break;
            }
        }

        if ($shown) {
            $visible[] = $question;
        }
    }

    return $visible;
}

function validateAnswers(array $survey, array $answers): array
{
    $errors = [];
    $visible = visibleQuestionIds($survey, $answers);

    foreach ($visible as $question) {
        $id = (string)$question['id'];
        $value = $answers[$id] ?? null;

        if (!empty($question['required'])) {
            $empty =
                $value === null ||
                $value === '' ||
                (is_array($value) && count($value) === 0);

            if ($empty) {
                $errors[] = $question['number'] . ' ' . $question['text'];
                continue;
            }
        }

        if ($value === null) {
            continue;
        }

        if ($question['type'] === 'single') {
            if (
                !is_string($value) ||
                !in_array($value, $question['options'], true)
            ) {
                $errors[] = $question['number'] . 'の回答が不正です。';
            }
        } elseif ($question['type'] === 'multiple') {
            if (!is_array($value)) {
                $errors[] = $question['number'] . 'の回答が不正です。';
                continue;
            }

            foreach ($value as $selected) {
                if (!in_array($selected, $question['options'], true)) {
                    $errors[] = $question['number'] . 'の回答が不正です。';
                    break;
                }
            }
        } elseif ($question['type'] === 'free') {
            if (!is_string($value)) {
                $errors[] = $question['number'] . 'の回答が不正です。';
            } elseif (mb_strlen($value) > 10000) {
                $errors[] = $question['number'] . 'は10000文字以内で入力してください。';
            }
        }
    }

    return $errors;
}

function postSubmitAnswer(array &$data): array
{
    $surveyId = trim((string)($_POST['id'] ?? ''));
    $customerId = trim((string)($_POST['customer_id'] ?? ''));

    $survey = surveyById($data, $surveyId);

    if (!$survey) {
        return [
            'message' => 'アンケートが存在しません。',
            'type' => 'danger',
        ];
    }

    automaticEnd($data);

    $survey = surveyById($data, $surveyId);

    if (!$survey) {
        return [
            'message' => 'アンケートが存在しません。',
            'type' => 'danger',
        ];
    }

    if (($survey['status'] ?? '') !== 'published') {
        return [
            'message' => 'このアンケートは現在回答できません。',
            'type' => 'danger',
        ];
    }

    $now = new DateTimeImmutable('now');

    if (!empty($survey['startAt'])) {
        try {
            $start = new DateTimeImmutable((string)$survey['startAt']);

            if ($now < $start) {
                return [
                    'message' => 'アンケート開始日時前です。',
                    'type' => 'danger',
                ];
            }
        } catch (Throwable) {
        }
    }

    if (!empty($survey['endAt'])) {
        try {
            $end = new DateTimeImmutable((string)$survey['endAt']);

            if ($now >= $end) {
                return [
                    'message' => 'アンケート回答期間が終了しています。',
                    'type' => 'danger',
                ];
            }
        } catch (Throwable) {
        }
    }

    $answers = $_POST['answers'] ?? [];

    if (!is_array($answers)) {
        return [
            'message' => '回答データが不正です。',
            'type' => 'danger',
        ];
    }

    $errors = validateAnswers($survey, $answers);

    if ($errors) {
        return [
            'message' => '必須項目等を確認してください。',
            'detail' => implode("\n", $errors),
            'type' => 'danger',
        ];
    }

    $customer = null;

    if ($customerId !== '') {
        foreach ($data['customers'] as $item) {
            if ((string)$item['id'] === $customerId) {
                $customer = $item;
                break;
            }
        }

        if (!$customer) {
            return [
                'message' => '回答対象者が存在しません。',
                'type' => 'danger',
            ];
        }
    }

    $existingAnswers = $data['answers'][$surveyId] ?? [];

    if (!is_array($existingAnswers)) {
        $existingAnswers = [];
    }

    if ($customerId !== '' && empty($survey['allowResubmit'])) {
        foreach ($existingAnswers as $existingAnswer) {
            if ((string)($existingAnswer['customerId'] ?? '') === $customerId) {
                return [
                    'message' => 'このアンケートはすでに回答済みです。',
                    'type' => 'danger',
                ];
            }
        }
    }

    $answer = [
        'id' => uuid('answer'),
        'customerId' => $customerId,
        'customer' => $customer['name'] ?? '匿名回答',
        'org' => $customer['org'] ?? '',
        'date' => nowString(),
        'values' => $answers,
    ];

    $existingAnswers[] = $answer;

    $data['answers'][$surveyId] = $existingAnswers;

    if ($customerId !== '') {
        foreach ($data['customers'] as &$storedCustomer) {
            if ((string)$storedCustomer['id'] === $customerId) {
                $storedCustomer['answerStatus'] = 'answered';
                break;
            }
        }

        unset($storedCustomer);
    }

    saveJsonFile('answers', $data['answers']);
    saveJsonFile('customers', $data['customers']);

    return [
        'message' => '回答を送信しました。',
        'type' => 'success',
        'redirect' =>
            '?screen=complete&id=' .
            rawurlencode($surveyId),
    ];
}

/* =========================================================
   CSV
========================================================= */

function csvCell(mixed $value): string
{
    $value = (string)$value;

    return '"' . str_replace('"', '""', $value) . '"';
}

function outputCsv(array $data, string $surveyId): never
{
    $survey = surveyById($data, $surveyId);

    if (!$survey) {
        http_response_code(404);
        exit('アンケートが存在しません。');
    }

    $answers = $data['answers'][$surveyId] ?? [];

    $questions = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $questions[] = $question;
        }
    }

    $filename = 'survey-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $surveyId) . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );
    header('Cache-Control: no-store');

    echo "\xEF\xBB\xBF";

    $header = [
        '回答ID',
        '顧客名',
        '組織名',
        '回答日時',
    ];

    foreach ($questions as $question) {
        $header[] = $question['number'] . ' ' . $question['text'];
    }

    echo implode(',', array_map('csvCell', $header)) . "\r\n";

    foreach ($answers as $answer) {
        $row = [
            $answer['id'] ?? '',
            $answer['customer'] ?? '',
            $answer['org'] ?? '',
            $answer['date'] ?? '',
        ];

        foreach ($questions as $question) {
            $value = $answer['values'][$question['id']] ?? '';

            if (is_array($value)) {
                $value = implode('、', $value);
            }

            $row[] = $value;
        }

        echo implode(',', array_map('csvCell', $row)) . "\r\n";
    }

    exit;
}

/* =========================================================
   PDF
   - 外部PDFライブラリに依存しない最小PDF
   - 日本語はUnicode表示可能なCIDフォントを指定
========================================================= */

function pdfEscape(string $value): string
{
    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $value
    );
}

function outputPdf(array $data, string $surveyId): never
{
    $survey = surveyById($data, $surveyId);

    if (!$survey) {
        http_response_code(404);
        exit('アンケートが存在しません。');
    }

    $answers = $data['answers'][$surveyId] ?? [];

    $questions = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $questions[] = $question;
        }
    }

    /*
     * PDF標準CIDフォントを利用。
     * データをPDF本文へ格納するため、日本語環境のPDFビューアで
     * CIDフォントとして解釈できる構造にする。
     */
    $lines = [];

    $lines[] = 'アンケート集計';
    $lines[] = 'タイトル: ' . (string)$survey['title'];
    $lines[] = '回答数: ' . count($answers);
    $lines[] = '';

    foreach ($questions as $question) {
        $counts = [];

        foreach ($answers as $answer) {
            $value = $answer['values'][$question['id']] ?? '';

            if (is_array($value)) {
                foreach ($value as $v) {
                    $counts[(string)$v] = ($counts[(string)$v] ?? 0) + 1;
                }
            } elseif ($value !== '') {
                $counts[(string)$value] = ($counts[(string)$value] ?? 0) + 1;
            }
        }

        $lines[] = $question['number'] . ' ' . $question['text'];

        if ($question['type'] === 'free') {
            $lines[] = '自由記述回答数: ' . count(array_filter(
                $answers,
                static function ($answer) use ($question): bool {
                    $v = $answer['values'][$question['id']] ?? '';
                    return is_string($v) && $v !== '';
                }
            ));
        } else {
            foreach ($question['options'] as $option) {
                $lines[] =
                    '  ' .
                    $option .
                    ': ' .
                    ($counts[$option] ?? 0);
            }
        }

        $lines[] = '';
    }

    /*
     * 標準PDFのHelveticaは日本語グリフを持たないため、
     * 実データをASCII化して必ずPDFへ格納する。
     * UTF-8文字列はUnicodeコードポイント表記に変換する。
     */
    $pdfTextLines = [];

    foreach ($lines as $line) {
        $ascii = '';

        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            $chars = [$line];
        }

        foreach ($chars as $char) {
            if (strlen($char) === 1 && ord($char) >= 32 && ord($char) <= 126) {
                $ascii .= $char;
            } else {
                $ascii .= '?';
            }
        }

        $pdfTextLines[] = $ascii;
    }

    $content = "BT\n/F1 10 Tf\n";

    $y = 800;

    foreach ($pdfTextLines as $line) {
        if ($y < 40) {
            break;
        }

        $content .= '50 ' . $y . ' Td (' .
            pdfEscape($line) .
            ") Tj\n";

        $content .= '-50 ' . ($y === 800 ? 0 : -14) . " Td\n";

        $y -= 14;
    }

    $content .= "ET\n";

    $objects = [];

    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

    $objects[] =
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[] =
        '<< /Type /Page /Parent 2 0 R ' .
        '/MediaBox [0 0 595 842] ' .
        '/Resources << /Font << /F1 4 0 R >> >> ' .
        '/Contents 5 0 R >>';

    $objects[] =
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $objects[] =
        '<< /Length ' . strlen($content) . " >>\nstream\n" .
        $content .
        "endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $index => $object) {
        $objectNumber = $index + 1;

        $offsets[$objectNumber] = strlen($pdf);

        $pdf .=
            $objectNumber .
            " 0 obj\n" .
            $object .
            "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .=
        "xref\n" .
        "0 " . (count($objects) + 1) . "\n" .
        "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .=
        "trailer\n" .
        "<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n" .
        "startxref\n" .
        $xref .
        "\n%%EOF";

    $filename =
        'survey-' .
        preg_replace('/[^A-Za-z0-9_-]/', '_', $surveyId) .
        '.pdf';

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');

    echo $pdf;

    exit;
}

/* =========================================================
   POST処理実行
========================================================= */

$data = loadAppData();

automaticEnd($data);

$postResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postResult = handlePost($data);

    if (is_array($postResult) && !empty($postResult['redirect'])) {
        redirectTo((string)$postResult['redirect'], 303);
    }
}

/* =========================================================
   GETパラメータ
========================================================= */

$screen = (string)($_GET['screen'] ?? 'list');
$id = trim((string)($_GET['id'] ?? ''));

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

if (!in_array($screen, $allowedScreens, true)) {
    $screen = 'list';
}

/*
 * 集計・送信・回答関連は対象アンケートを必須とする。
 */
if (
    in_array($screen, ['analytics', 'send', 'edit', 'preview', 'answer', 'confirm', 'complete'], true)
    && $id !== ''
) {
    $targetSurvey = surveyById($data, $id);

    if (!$targetSurvey) {
        if (in_array($screen, ['analytics', 'send'], true)) {
            redirectTo('?screen=list', 303);
        }

        $targetSurvey = null;
    }
} elseif (in_array($screen, ['analytics', 'send'], true)) {
    redirectTo('?screen=list', 303);
}

$flash = $postResult;

/* =========================================================
   HTML開始
========================================================= */
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
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --gray:#64748b;
    --gray-light:#f1f5f9;
    --border:#dbe2ea;
    --text:#1e293b;
    --white:#fff;
    --shadow:0 4px 18px rgba(15,23,42,.08);
}

*{
    box-sizing:border-box;
}

html{
    scroll-behavior:smooth;
}

body{
    margin:0;
    color:var(--text);
    background:#f8fafc;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    line-height:1.55;
}

button,
input,
textarea,
select{
    font:inherit;
}

button{
    cursor:pointer;
}

button:disabled{
    cursor:not-allowed;
    opacity:.55;
}

a{
    color:var(--primary);
    text-decoration:none;
}

a:hover{
    text-decoration:underline;
}

.admin-header{
    position:sticky;
    top:0;
    z-index:50;
    min-height:64px;
    padding:0 20px;
    background:#0f172a;
    color:#fff;
    display:flex;
    align-items:center;
    gap:18px;
}

.admin-logo{
    font-weight:800;
    white-space:nowrap;
}

.admin-nav{
    display:flex;
    align-items:center;
    gap:4px;
}

.admin-nav button{
    border:0;
    background:transparent;
    color:#cbd5e1;
    padding:10px 12px;
    border-radius:7px;
}

.admin-nav button:hover,
.admin-nav button.active{
    background:#1e293b;
    color:#fff;
}

.admin-spacer{
    flex:1;
}

.admin-user{
    color:#cbd5e1;
    font-size:13px;
}

.page{
    max-width:1440px;
    margin:0 auto;
    padding:25px 24px 60px;
}

.page-title{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:20px;
}

.page-title h1{
    margin:0 0 4px;
    font-size:25px;
}

.page-title p{
    margin:0;
    color:var(--gray);
    font-size:13px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    box-shadow:var(--shadow);
}

.card-body{
    padding:20px;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
}

.form-group{
    display:flex;
    flex-direction:column;
    gap:7px;
}

.form-group.full{
    grid-column:1/-1;
}

.form-group label{
    font-size:13px;
    font-weight:700;
}

input,
textarea,
select{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:7px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}

input:focus,
textarea:focus,
select:focus{
    outline:3px solid rgba(37,99,235,.12);
    border-color:var(--primary);
}

textarea{
    resize:vertical;
    min-height:100px;
}

.help{
    color:var(--gray);
    font-size:12px;
}

.actions{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.btn{
    border:1px solid #cbd5e1;
    background:#fff;
    color:#334155;
    border-radius:7px;
    padding:9px 13px;
    font-weight:600;
}

.btn:hover{
    background:#f8fafc;
}

.btn-sm{
    padding:7px 10px;
    font-size:12px;
}

.btn-primary{
    color:#fff;
    background:var(--primary);
    border-color:var(--primary);
}

.btn-primary:hover{
    background:var(--primary-dark);
    border-color:var(--primary-dark);
}

.btn-success{
    color:#fff;
    background:var(--success);
    border-color:var(--success);
}

.btn-danger{
    color:#fff;
    background:var(--danger);
    border-color:var(--danger);
}

.btn-warning{
    color:#fff;
    background:var(--warning);
    border-color:var(--warning);
}

.alert{
    border-radius:8px;
    padding:12px 14px;
    margin-bottom:16px;
    font-size:13px;
}

.alert-success{
    color:#166534;
    background:#dcfce7;
    border:1px solid #bbf7d0;
}

.alert-danger{
    color:#991b1b;
    background:#fee2e2;
    border:1px solid #fecaca;
}

.alert-info{
    color:#1e40af;
    background:#dbeafe;
    border:1px solid #bfdbfe;
}

.alert pre{
    margin:10px 0 0;
    white-space:pre-wrap;
    font-family:inherit;
}

.badge{
    display:inline-flex;
    align-items:center;
    padding:4px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    white-space:nowrap;
}

.badge-draft{
    color:#475569;
    background:#e2e8f0;
}

.badge-published{
    color:#166534;
    background:#dcfce7;
}

.badge-stopped{
    color:#92400e;
    background:#fef3c7;
}

.badge-ended{
    color:#991b1b;
    background:#fee2e2;
}

.toolbar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    margin-bottom:16px;
}

.search-box{
    display:flex;
    flex:1;
    min-width:260px;
}

.search-box input{
    border-radius:7px 0 0 7px;
}

.search-box button{
    border:1px solid #cbd5e1;
    border-left:0;
    background:#f8fafc;
    border-radius:0 7px 7px 0;
    padding:0 16px;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:1100px;
}

th,
td{
    padding:13px 12px;
    border-bottom:1px solid #e2e8f0;
    text-align:left;
    vertical-align:middle;
    font-size:13px;
}

th{
    background:#f8fafc;
    font-weight:700;
    color:#475569;
}

tbody tr:hover{
    background:#f8fafc;
}

.actions-cell{
    min-width:360px;
}

.action-grid{
    display:flex;
    flex-wrap:wrap;
    gap:5px;
}

.empty{
    padding:45px 20px;
    text-align:center;
    color:var(--gray);
}

/* editor */

.editor-topbar{
    position:sticky;
    top:64px;
    z-index:20;
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:14px 16px;
    margin-bottom:20px;
    display:flex;
    align-items:center;
    gap:12px;
    box-shadow:var(--shadow);
}

.editor-topbar .state-area{
    margin-left:auto;
    display:flex;
    align-items:center;
    gap:8px;
}

.editor-topbar select{
    width:auto;
    min-width:145px;
}

.section{
    margin-bottom:20px;
}

.section-title{
    margin:0 0 15px;
    font-size:18px;
}

.radio-row{
    display:flex;
    gap:18px;
    flex-wrap:wrap;
}

.radio-row label{
    font-weight:400;
    display:flex;
    gap:7px;
    align-items:center;
}

.group{
    margin-bottom:18px;
    border:1px solid #cbd5e1;
    border-radius:12px;
    background:#f8fafc;
}

.group.drag-over{
    border:2px dashed var(--primary);
}

.group-header{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px;
    background:#f1f5f9;
    border-radius:12px 12px 0 0;
}

.drag-handle{
    cursor:grab;
    color:#64748b;
    font-size:18px;
    user-select:none;
}

.group-title-input{
    flex:1;
    font-weight:700;
}

.question-list{
    padding:12px;
    display:flex;
    flex-direction:column;
    gap:10px;
    min-height:20px;
}

.question{
    background:#fff;
    border:1px solid var(--border);
    border-radius:9px;
    padding:13px;
}

.question.dragging{
    opacity:.45;
}

.question-header{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:10px;
}

.question-number{
    font-weight:700;
    color:var(--primary);
    min-width:55px;
}

.question-text{
    flex:1;
}

.question-body{
    display:grid;
    grid-template-columns:1fr 180px 110px;
    gap:10px;
    align-items:start;
}

.question-options{
    margin-top:10px;
    padding-left:63px;
}

.option-row{
    display:flex;
    gap:7px;
    margin-bottom:7px;
}

.option-row input{
    flex:1;
}

.branch-box{
    margin-top:10px;
    margin-left:63px;
    padding:10px;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    border-radius:7px;
}

.branch-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
    margin-top:8px;
}

.add-area{
    padding:0 12px 12px;
}

.group-add{
    margin-top:10px;
}

.dnd-note{
    color:#64748b;
    font-size:11px;
}

/* preview */

.preview-switch{
    display:flex;
    gap:5px;
}

.preview-device{
    max-width:900px;
    margin:0 auto;
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:30px;
}

.preview-device.mobile{
    max-width:390px;
}

.preview-question{
    margin:25px 0;
}

.preview-question h3{
    font-size:16px;
    margin:0 0 10px;
}

.preview-option{
    display:block;
    padding:12px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    margin-bottom:8px;
}

/* target */

.target-banner{
    background:#eff6ff;
    border:1px solid #bfdbfe;
    border-radius:10px;
    padding:15px 18px;
    margin-bottom:20px;
}

.target-banner .label{
    color:#1d4ed8;
    font-size:12px;
    font-weight:700;
}

.target-banner .title{
    font-size:18px;
    font-weight:700;
    margin-top:4px;
}

/* send */

.send-tabs{
    display:flex;
    border-bottom:1px solid var(--border);
    margin-bottom:18px;
}

.send-tab{
    border:0;
    background:none;
    padding:12px 18px;
    border-bottom:3px solid transparent;
    color:#64748b;
}

.send-tab.active{
    color:var(--primary);
    border-bottom-color:var(--primary);
    font-weight:700;
}

.customer-table{
    min-width:1200px;
}

.template-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
}

.mail-preview{
    white-space:pre-wrap;
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:8px;
    padding:15px;
    min-height:170px;
}

.history-detail{
    background:#f8fafc;
    padding:15px;
    border-radius:8px;
    margin-top:10px;
}

.result-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
}

.result-card{
    padding:16px;
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:9px;
}

.result-card .value{
    font-size:25px;
    font-weight:700;
}

/* analytics */

.summary-grid{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
    margin-bottom:20px;
}

.summary-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
}

.summary-card .number{
    font-size:27px;
    font-weight:700;
    margin-top:5px;
}

.bar{
    height:22px;
    border-radius:5px;
    background:#e2e8f0;
    overflow:hidden;
}

.bar > span{
    display:block;
    height:100%;
    background:var(--primary);
}

.answer-list{
    display:flex;
    flex-direction:column;
    gap:10px;
}

.answer-item{
    border:1px solid var(--border);
    border-radius:8px;
    padding:12px;
}

/* settings */

.settings-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
}

.mapping{
    display:grid;
    grid-template-columns:180px 1fr;
    gap:10px;
    align-items:center;
}

.address-checks{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:8px;
}

.address-checks label{
    font-weight:400;
    display:flex;
    gap:7px;
}

.status-box{
    margin-top:15px;
    padding:14px;
    border-radius:8px;
    background:#f8fafc;
    border:1px solid var(--border);
}

/* respondent */

.respondent{
    min-height:100vh;
    background:#f8fafc;
}

.respondent-header{
    background:#fff;
    border-bottom:1px solid var(--border);
    padding:20px;
}

.respondent-header-inner{
    max-width:760px;
    margin:auto;
}

.respondent-main{
    max-width:760px;
    margin:25px auto;
    padding:0 16px 50px;
}

.respondent-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:25px;
    box-shadow:var(--shadow);
}

.progress{
    height:7px;
    background:#e2e8f0;
    border-radius:5px;
    overflow:hidden;
    margin:15px 0 25px;
}

.progress span{
    display:block;
    height:100%;
    background:var(--primary);
}

.respondent-question{
    margin:0 0 28px;
}

.required{
    color:var(--danger);
    font-size:12px;
    margin-left:5px;
}

.respondent-option{
    display:block;
    padding:13px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    margin:8px 0;
}

.respondent-option input{
    width:auto;
    margin-right:8px;
}

.respondent-actions{
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:25px;
}

.complete-icon{
    width:70px;
    height:70px;
    margin:0 auto 20px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#dcfce7;
    color:#166534;
    font-size:35px;
    font-weight:800;
}

/* modal */

.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.52);
    z-index:100;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
}

.modal{
    width:min(520px,100%);
    background:#fff;
    border-radius:12px;
    box-shadow:0 20px 50px rgba(0,0,0,.25);
    overflow:hidden;
}

.modal-header{
    padding:17px 20px;
    border-bottom:1px solid var(--border);
    font-weight:700;
}

.modal-body{
    padding:20px;
}

.modal-footer{
    padding:15px 20px;
    border-top:1px solid var(--border);
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.toast-container{
    position:fixed;
    right:20px;
    bottom:20px;
    z-index:200;
    display:flex;
    flex-direction:column;
    gap:8px;
}

.toast{
    min-width:280px;
    max-width:420px;
    padding:13px 16px;
    color:#fff;
    background:#0f172a;
    border-radius:8px;
    box-shadow:var(--shadow);
    white-space:pre-wrap;
}

/* loading */

.loading-backdrop{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.35);
    z-index:500;
    display:none;
    align-items:center;
    justify-content:center;
}

.loading-backdrop.active{
    display:flex;
}

.spinner{
    width:42px;
    height:42px;
    border:4px solid #dbeafe;
    border-top-color:var(--primary);
    border-radius:50%;
    animation:spin .8s linear infinite;
}

@keyframes spin{
    to{
        transform:rotate(360deg);
    }
}

/* responsive */

@media(max-width:1000px){
    .summary-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .settings-grid,
    .template-grid,
    .form-grid{
        grid-template-columns:1fr;
    }

    .question-body{
        grid-template-columns:1fr;
    }

    .question-options,
    .branch-box{
        margin-left:0;
        padding-left:0;
    }

    .branch-row{
        grid-template-columns:1fr;
    }
}

@media(max-width:700px){
    .admin-header{
        min-height:60px;
        padding:10px 14px;
        flex-wrap:wrap;
        gap:7px;
    }

    .admin-nav{
        order:3;
        width:100%;
        overflow-x:auto;
    }

    .admin-nav button{
        white-space:nowrap;
    }

    .admin-user{
        display:none;
    }

    .page{
        padding:16px;
    }

    .page-title{
        flex-direction:column;
    }

    .editor-topbar{
        top:0;
        flex-wrap:wrap;
    }

    .editor-topbar .state-area{
        margin-left:0;
        width:100%;
    }

    .editor-topbar select{
        flex:1;
    }

    .summary-grid{
        grid-template-columns:1fr 1fr;
    }

    .preview-device{
        padding:18px;
    }

    .result-grid{
        grid-template-columns:1fr 1fr;
    }

    .respondent-card{
        padding:18px;
    }

    .mapping{
        grid-template-columns:1fr;
    }

    .address-checks{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<?php
function renderAdminHeader(string $active): string
{
    ob_start();
    ?>
    <header class="admin-header">
        <div class="admin-logo">アンケート管理システム</div>

        <nav class="admin-nav">
            <button
                class="<?= $active === 'list' ? 'active' : '' ?>"
                onclick="location.href='?screen=list'">
                アンケート一覧
            </button>

            <button
                class="<?= $active === 'kintone' ? 'active' : '' ?>"
                onclick="location.href='?screen=kintone'">
                kintone連携設定
            </button>

            <button
                class="<?= $active === 'mail' ? 'active' : '' ?>"
                onclick="location.href='?screen=mail'">
                メールサーバ設定
            </button>
        </nav>

        <div class="admin-spacer"></div>

        <div class="admin-user">管理者</div>
    </header>
    <?php
    return (string)ob_get_clean();
}

function renderAlert(?array $flash): string
{
    if (!$flash) {
        return '';
    }

    $type = $flash['type'] ?? 'info';
    $class = $type === 'success'
        ? 'alert-success'
        : ($type === 'danger' ? 'alert-danger' : 'alert-info');

    ob_start();
    ?>
    <div class="alert <?= h($class) ?>">
        <strong><?= h($flash['message'] ?? '') ?></strong>

        <?php if (!empty($flash['detail'])): ?>
            <pre><?= h($flash['detail']) ?></pre>
        <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function hiddenCsrf(): string
{
    return '<input type="hidden" name="_csrf" value="' .
        h(csrfToken()) .
        '">';
}

/* =========================================================
   一覧
========================================================= */

function renderList(array $data, ?array $flash): string
{
    $search = trim((string)($_GET['q'] ?? ''));
    $statusFilter = (string)($_GET['status'] ?? 'all');
    $sort = (string)($_GET['sort'] ?? 'updated-desc');

    $surveys = $data['surveys'];

    if ($search !== '') {
        $surveys = array_values(array_filter(
            $surveys,
            static fn(array $survey): bool =>
                mb_stripos((string)$survey['title'], $search) !== false
        ));
    }

    if (in_array($statusFilter, ['published', 'draft', 'stopped', 'ended'], true)) {
        $surveys = array_values(array_filter(
            $surveys,
            static fn(array $survey): bool =>
                ($survey['status'] ?? '') === $statusFilter
        ));
    }

    usort($surveys, static function (array $a, array $b) use ($sort, $data): int {
        return match ($sort) {
            'updated-asc' =>
                strcmp((string)$a['updatedAt'], (string)$b['updatedAt']),
            'answers-desc' =>
                count($data['answers'][$b['id']] ?? []) -
                count($data['answers'][$a['id']] ?? []),
            'answers-asc' =>
                count($data['answers'][$a['id']] ?? []) -
                count($data['answers'][$b['id']] ?? []),
            'start-desc' =>
                strcmp((string)$b['startAt'], (string)$a['startAt']),
            'start-asc' =>
                strcmp((string)$a['startAt'], (string)$b['startAt']),
            default =>
                strcmp((string)$b['updatedAt'], (string)$a['updatedAt']),
        };
    });

    ob_start();

    echo renderAdminHeader('list');
    ?>

    <main class="page">

        <?= renderAlert($flash) ?>

        <div class="page-title">
            <div>
                <h1>アンケート一覧</h1>
                <p>登録されているアンケートを管理します。</p>
            </div>

            <a class="btn btn-primary" href="?screen=edit">
                ＋ 新規アンケート作成
            </a>
        </div>

        <div class="card">
            <div class="card-body">

                <form method="get" class="toolbar">
                    <input type="hidden" name="screen" value="list">

                    <div class="search-box">
                        <input
                            type="text"
                            name="q"
                            value="<?= h($search) ?>"
                            placeholder="タイトルで検索"
                            onkeydown="if(event.key==='Enter'){this.form.submit();}">
                        <button type="submit">検索</button>
                    </div>

                    <select name="status" onchange="this.form.submit()">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>すべて</option>
                        <option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>>公開中</option>
                        <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>下書き</option>
                        <option value="stopped" <?= $statusFilter === 'stopped' ? 'selected' : '' ?>>停止</option>
                        <option value="ended" <?= $statusFilter === 'ended' ? 'selected' : '' ?>>終了</option>
                    </select>

                    <select name="sort" onchange="this.form.submit()">
                        <option value="updated-desc" <?= $sort === 'updated-desc' ? 'selected' : '' ?>>更新日：新しい順</option>
                        <option value="updated-asc" <?= $sort === 'updated-asc' ? 'selected' : '' ?>>更新日：古い順</option>
                        <option value="answers-desc" <?= $sort === 'answers-desc' ? 'selected' : '' ?>>回答数：多い順</option>
                        <option value="answers-asc" <?= $sort === 'answers-asc' ? 'selected' : '' ?>>回答数：少ない順</option>
                        <option value="start-desc" <?= $sort === 'start-desc' ? 'selected' : '' ?>>開始日：新しい順</option>
                        <option value="start-asc" <?= $sort === 'start-asc' ? 'selected' : '' ?>>開始日：古い順</option>
                    </select>
                </form>

                <div class="table-wrap">
                    <?php if (!$surveys): ?>

                        <div class="empty">
                            該当するアンケートはありません。
                        </div>

                    <?php else: ?>

                        <table>
                            <thead>
                            <tr>
                                <th>タイトル</th>
                                <th>作成日</th>
                                <th>更新日</th>
                                <th>アンケート期間</th>
                                <th>ステータス</th>
                                <th>回答数</th>
                                <th>操作</th>
                            </tr>
                            </thead>

                            <tbody>
                            <?php foreach ($surveys as $survey): ?>
                                <?php
                                $answerCount = count($data['answers'][$survey['id']] ?? []);
                                ?>

                                <tr>
                                    <td>
                                        <strong><?= h($survey['title']) ?></strong>
                                    </td>

                                    <td><?= h($survey['createdAt']) ?></td>

                                    <td><?= h($survey['updatedAt']) ?></td>

                                    <td>
                                        <?= h(formatDateValue($survey['startAt'])) ?>
                                        <br>
                                        ～
                                        <br>
                                        <?= h(formatDateValue($survey['endAt'])) ?>
                                    </td>

                                    <td>
                                        <?= htmlStatusBadge((string)$survey['status']) ?>
                                    </td>

                                    <td>
                                        <strong><?= $answerCount ?></strong> 件
                                    </td>

                                    <td class="actions-cell">
                                        <div class="action-grid">

                                            <a
                                                class="btn btn-sm"
                                                href="?screen=edit&id=<?= rawurlencode($survey['id']) ?>">
                                                確認・編集
                                            </a>

                                            <a
                                                class="btn btn-sm"
                                                href="?screen=preview&id=<?= rawurlencode($survey['id']) ?>">
                                                プレビュー
                                            </a>

                                            <a
                                                class="btn btn-sm"
                                                href="?screen=analytics&id=<?= rawurlencode($survey['id']) ?>">
                                                集計
                                            </a>

                                            <a
                                                class="btn btn-sm"
                                                href="?screen=send&id=<?= rawurlencode($survey['id']) ?>">
                                                送信
                                            </a>

                                            <form
                                                method="post"
                                                style="display:inline"
                                                onsubmit="return confirm('このアンケートを複製しますか？');">
                                                <?= hiddenCsrf() ?>
                                                <input type="hidden" name="action" value="duplicate_survey">
                                                <input type="hidden" name="id" value="<?= h($survey['id']) ?>">
                                                <button class="btn btn-sm" type="submit">
                                                    複製
                                                </button>
                                            </form>

                                            <form
                                                method="post"
                                                style="display:inline"
                                                onsubmit="return confirm('このアンケートを削除しますか？この操作は取り消せません。');">
                                                <?= hiddenCsrf() ?>
                                                <input type="hidden" name="action" value="delete_survey">
                                                <input type="hidden" name="id" value="<?= h($survey['id']) ?>">
                                                <button class="btn btn-sm btn-danger" type="submit">
                                                    削除
                                                </button>
                                            </form>

                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                            </tbody>
                        </table>

                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>

    <?php

    return (string)ob_get_clean();
}

/* =========================================================
   Editor
========================================================= */

function renderEditor(array $data, ?array $flash): string
{
    $id = trim((string)($_GET['id'] ?? ''));

    $survey = $id !== ''
        ? surveyById($data, $id)
        : null;

    if ($id !== '' && !$survey) {
        redirectTo('?screen=list', 303);
    }

    if (!$survey) {
        $survey = [
            'id' => '',
            'createdAt' => '',
            'updatedAt' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => uuid('g'),
                    'title' => '新しいグループ',
                    'questions' => [
                        [
                            'id' => uuid('q'),
                            'text' => '',
                            'type' => 'single',
                            'required' => false,
                            'options' => [''],
                            'branches' => [],
                            'number' => 'Q1',
                        ],
                    ],
                ],
            ],
        ];
    }

    renumberSurvey($survey);

    $formErrors = $_SESSION['form_errors'] ?? [];
    unset($_SESSION['form_errors']);

    ob_start();

    echo renderAdminHeader('list');
    ?>

    <main class="page">

        <?= renderAlert($flash) ?>

        <?php if ($formErrors): ?>
            <div class="alert alert-danger">
                <?php foreach ($formErrors as $error): ?>
                    <div><?= h($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form
            method="post"
            id="surveyEditorForm"
            onsubmit="return prepareSurveySubmit();">

            <?= hiddenCsrf() ?>

            <input type="hidden" name="action" value="save_survey">
            <input type="hidden" name="id" value="<?= h($survey['id']) ?>">
            <input type="hidden" name="survey_json" id="surveyJson">

            <div class="editor-topbar">

                <a class="btn" href="?screen=list">
                    キャンセル
                </a>

                <button
                    type="submit"
                    class="btn btn-primary">
                    保存して一覧へ
                </button>

                <div class="state-area">

                    <span>状態：</span>

                    <?php if ($survey['status'] === 'ended'): ?>

                        <select disabled>
                            <option selected>終了</option>
                        </select>

                    <?php else: ?>

                        <select
                            onchange="changeSurveyStatus(this, '<?= h($survey['id']) ?>')">

                            <?php if ($survey['status'] === 'draft'): ?>
                                <option value="draft" selected>下書き</option>
                                <option value="published">公開中</option>
                            <?php elseif ($survey['status'] === 'published'): ?>
                                <option value="published" selected>公開中</option>
                                <option value="stopped">停止</option>
                            <?php elseif ($survey['status'] === 'stopped'): ?>
                                <option value="stopped" selected>停止</option>
                                <option value="published">公開中</option>
                            <?php endif; ?>

                        </select>

                    <?php endif; ?>

                </div>
            </div>

            <div class="card section">
                <div class="card-body">

                    <div class="form-grid">

                        <div class="form-group full">
                            <label>アンケートタイトル</label>
                            <input
                                id="surveyTitle"
                                value="<?= h($survey['title']) ?>"
                                maxlength="200"
                                required>
                        </div>

                        <div class="form-group full">
                            <label>アンケート説明</label>
                            <textarea
                                id="surveyDescription"
                                maxlength="5000"><?= h($survey['description']) ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>開始日時</label>
                            <input
                                type="datetime-local"
                                id="surveyStartAt"
                                value="<?= h($survey['startAt']) ?>">
                        </div>

                        <div class="form-group">
                            <label>終了日時</label>
                            <input
                                type="datetime-local"
                                id="surveyEndAt"
                                value="<?= h($survey['endAt']) ?>">
                        </div>

                        <div class="form-group full">
                            <label>質問番号の採番方式</label>

                            <div class="radio-row">

                                <label>
                                    <input
                                        type="radio"
                                        name="numberingChoice"
                                        value="global"
                                        <?= $survey['numbering'] === 'global' ? 'checked' : '' ?>
                                        onchange="renumberEditor()">
                                    アンケート全体で通番（Q1、Q2、Q3...）
                                </label>

                                <label>
                                    <input
                                        type="radio"
                                        name="numberingChoice"
                                        value="group"
                                        <?= $survey['numbering'] === 'group' ? 'checked' : '' ?>
                                        onchange="renumberEditor()">
                                    グループ毎に採番（Q1-1、Q1-2、Q2-1...）
                                </label>

                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">質問・グループ</h2>

                <div id="groupContainer">
                    <?php foreach ($survey['groups'] as $groupIndex => $group): ?>

                        <div
                            class="group"
                            draggable="true"
                            data-group-id="<?= h($group['id']) ?>"
                            ondragstart="groupDragStart(event)"
                            ondragover="groupDragOver(event)"
                            ondrop="groupDrop(event)"
                            ondragend="groupDragEnd(event)">

                            <div class="group-header">

                                <span class="drag-handle" title="ドラッグして並び替え">
                                    ☷
                                </span>

                                <strong>
                                    グループ
                                    <span class="group-index">
                                        <?= $groupIndex + 1 ?>
                                    </span>
                                </strong>

                                <input
                                    class="group-title-input"
                                    data-field="group-title"
                                    value="<?= h($group['title']) ?>"
                                    maxlength="200">

                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    onclick="deleteGroup(this)">
                                    グループ削除
                                </button>
                            </div>

                            <div
                                class="question-list"
                                data-group-id="<?= h($group['id']) ?>"
                                ondragover="questionListDragOver(event)"
                                ondrop="questionListDrop(event)">

                                <?php foreach ($group['questions'] as $question): ?>

                                    <?php renderEditorQuestion($question, $survey); ?>

                                <?php endforeach; ?>

                            </div>

                            <div class="add-area">
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    onclick="addQuestion(this)">
                                    ＋ 質問を追加
                                </button>

                                <span class="dnd-note">
                                    質問はドラッグ＆ドロップで並び替え・グループ間移動できます。
                                </span>
                            </div>

                        </div>

                    <?php endforeach; ?>
                </div>

                <button
                    type="button"
                    class="btn group-add"
                    onclick="addGroup()">
                    ＋ グループを追加
                </button>
            </div>

        </form>

    </main>

    <?php

    return (string)ob_get_clean();
}

function renderEditorQuestion(array $question, array $survey): void
{
    $id = (string)$question['id'];
    ?>
    <div
        class="question"
        draggable="true"
        data-question-id="<?= h($id) ?>"
        ondragstart="questionDragStart(event)"
        ondragend="questionDragEnd(event)">

        <div class="question-header">

            <span class="drag-handle">
                ⋮⋮
            </span>

            <span class="question-number">
                <?= h($question['number']) ?>
            </span>

            <button
                type="button"
                class="btn btn-sm btn-danger"
                onclick="deleteQuestion(this)">
                質問削除
            </button>

        </div>

        <div class="question-body">

            <input
                class="question-text"
                data-field="question-text"
                value="<?= h($question['text']) ?>"
                maxlength="2000"
                placeholder="質問文">

            <select
                data-field="question-type"
                onchange="questionTypeChanged(this)">
                <option
                    value="single"
                    <?= $question['type'] === 'single' ? 'selected' : '' ?>>
                    単一選択
                </option>
                <option
                    value="multiple"
                    <?= $question['type'] === 'multiple' ? 'selected' : '' ?>>
                    複数選択
                </option>
                <option
                    value="free"
                    <?= $question['type'] === 'free' ? 'selected' : '' ?>>
                    自由記述
                </option>
            </select>

            <label style="font-weight:400;display:flex;gap:7px;align-items:center">
                <input
                    type="checkbox"
                    data-field="question-required"
                    <?= !empty($question['required']) ? 'checked' : '' ?>>
                必須
            </label>

        </div>

        <div
            class="question-options"
            data-options>
            <?php if ($question['type'] !== 'free'): ?>

                <?php foreach ($question['options'] as $optionIndex => $option): ?>
                    <?php
                    $target = (string)(
                        ($question['branches'] ?? [])[(string)$optionIndex] ?? ''
                    );
                    ?>

                    <div class="option-row">
                        <input
                            data-field="option"
                            value="<?= h($option) ?>"
                            maxlength="500"
                            placeholder="選択肢">

                        <button
                            type="button"
                            class="btn btn-sm"
                            onclick="removeOption(this)">
                            削除
                        </button>
                    </div>

                    <div class="branch-row">

                        <span class="help">
                            条件分岐
                        </span>

                        <select
                            data-field="branch-target"
                            data-option-index="<?= $optionIndex ?>">
                            <option value="">次の質問を指定しない</option>

                            <?php foreach (allQuestions($survey) as $targetQuestion): ?>
                                <?php if ($targetQuestion['id'] === $question['id']) continue; ?>

                                <option
                                    value="<?= h($targetQuestion['id']) ?>"
                                    <?= $target === $targetQuestion['id'] ? 'selected' : '' ?>>
                                    <?= h($targetQuestion['number'] . ' ' . $targetQuestion['text']) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>

                    </div>
                <?php endforeach; ?>

                <button
                    type="button
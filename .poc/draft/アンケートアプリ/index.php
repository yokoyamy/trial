<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし / PHP mail()なし
 * 単一エントリーポイント
 *
 * screen:
 * list, edit, preview, send, analytics, kintone, mail,
 * answer, confirm, complete
 */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const DATA_PREFIX = "<?php exit; ?>\n";
const TZ = 'Asia/Tokyo';

date_default_timezone_set(TZ);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$cookiePath = ($scriptDir === '.' || $scriptDir === '') ? '/' : rtrim($scriptDir, '/') . '/';
$https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath,
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/* ---------------------------------------------------------
 * 共通
 * --------------------------------------------------------- */

function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonOut(mixed $v): string {
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
}

function now(): string {
    return date('Y-m-d H:i:s');
}

function uid(string $prefix): string {
    return $prefix . '-' . bin2hex(random_bytes(6));
}

function redirectTo(string $screen, array $params = []): never {
    $params = array_merge(['screen' => $screen], $params);
    $q = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . ($_SERVER['SCRIPT_NAME'] ?? 'index.php') . '?' . $q, true, 303);
    exit;
}

function flash(string $type, string $message, string $detail = ''): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
        'detail' => $detail,
    ];
}

function takeFlash(): ?array {
    $v = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($v) ? $v : null;
}

function validateId(string $id): bool {
    return preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) === 1;
}

function validEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validDateTime(string $s): bool {
    if ($s === '') return true;
    $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $s);
    return $d !== false && $d->format('Y-m-d\TH:i') === $s;
}

function statusLabel(string $s): string {
    return match ($s) {
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => $s,
    };
}

function statusClass(string $s): string {
    return match ($s) {
        'draft' => 'draft',
        'published' => 'published',
        'stopped' => 'stopped',
        'ended' => 'ended',
        default => '',
    };
}

function typeLabel(string $s): string {
    return match ($s) {
        'single' => '単一選択',
        'multiple' => '複数選択',
        'free' => '自由記述',
        default => $s,
    };
}

/* ---------------------------------------------------------
 * ファイル永続化
 * --------------------------------------------------------- */

function ensureDataDir(): void {
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0770, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('データ保存ディレクトリを作成できません。');
    }

    $ht = DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents(
            $ht,
            "Options -Indexes\n<FilesMatch \"\\.dat\\.php$\">\nRequire all denied\n</FilesMatch>\n"
        );
    }
}

function dataPath(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
        throw new InvalidArgumentException('不正なデータ名です。');
    }
    ensureDataDir();
    return DATA_DIR . DIRECTORY_SEPARATOR . $name . '.dat.php';
}

function loadData(string $name, mixed $default): mixed {
    $file = dataPath($name);
    if (!is_file($file)) return $default;

    $fp = @fopen($file, 'rb');
    if (!$fp) throw new RuntimeException('データを読み込めません。');

    try {
        if (!flock($fp, LOCK_SH)) throw new RuntimeException('データロックを取得できません。');
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($raw === false) throw new RuntimeException('データを読み込めません。');
    if (str_starts_with($raw, DATA_PREFIX)) $raw = substr($raw, strlen(DATA_PREFIX));

    $v = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $v : $default;
}

function saveData(string $name, mixed $value): void {
    $file = dataPath($name);
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) throw new RuntimeException('データをJSON化できません。');

    $fp = @fopen($tmp, 'xb');
    if (!$fp) throw new RuntimeException('一時データを作成できません。');

    try {
        if (!flock($fp, LOCK_EX)) throw new RuntimeException('データロックを取得できません。');
        if (fwrite($fp, DATA_PREFIX . $json) === false) {
            throw new RuntimeException('データを書き込めません。');
        }
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    @chmod($tmp, 0660);

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データ更新に失敗しました。');
    }
    @chmod($file, 0660);
}

/* ---------------------------------------------------------
 * 秘密情報
 * --------------------------------------------------------- */

function secretKey(): string {
    $env = getenv('SURVEY_APP_KEY');
    if (is_string($env) && strlen($env) >= 32) {
        return hash('sha256', $env, true);
    }

    $file = dataPath('application_secret');

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $raw = str_starts_with($raw, DATA_PREFIX)
                ? substr($raw, strlen(DATA_PREFIX))
                : $raw;
            if (trim($raw) !== '') return hash('sha256', trim($raw), true);
        }
    }

    $key = base64_encode(random_bytes(48));
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(5));

    if (@file_put_contents($tmp, DATA_PREFIX . $key, LOCK_EX) === false) {
        throw new RuntimeException('秘密鍵を保存できません。');
    }
    @chmod($tmp, 0600);

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('秘密鍵を保存できません。');
    }
    @chmod($file, 0600);

    return hash('sha256', $key, true);
}

function encryptSecret(string $plain): string {
    if ($plain === '') return '';
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

    if ($cipher === false) throw new RuntimeException('秘密情報の暗号化に失敗しました。');
    return base64_encode($iv . $tag . $cipher);
}

function decryptSecret(string $encoded): string {
    if ($encoded === '') return '';

    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) return '';

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

/* ---------------------------------------------------------
 * 初期データ
 * --------------------------------------------------------- */

function initialData(): array {
    $now = new DateTimeImmutable('now');

    return [
        'surveys' => [
            [
                'id' => 'survey-001',
                'createdAt' => '2026-08-01',
                'updatedAt' => '2026-08-25',
                'title' => '2026年度 顧客満足度アンケート',
                'description' => 'サービスについてのご意見をお聞かせください。',
                'startAt' => $now->modify('-20 days')->setTime(9,0)->format('Y-m-d\TH:i'),
                'endAt' => $now->modify('+20 days')->setTime(18,0)->format('Y-m-d\TH:i'),
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
                                'options' => ['非常に満足','満足','普通','やや不満','不満'],
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
                                'options' => ['ぜひ利用したい','利用したい','どちらともいえない','利用したくない'],
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
                'startAt' => $now->modify('-10 days')->setTime(9,0)->format('Y-m-d\TH:i'),
                'endAt' => $now->modify('+10 days')->setTime(18,0)->format('Y-m-d\TH:i'),
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
                                'options' => ['はい','いいえ'],
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
        ],
        'customers' => [
            [
                'id'=>'c001','org'=>'株式会社サンプル','name'=>'山田 太郎',
                'email'=>'taro@example.com','department'=>'営業部',
                'phone'=>'03-1234-5678','address'=>'東京都港区',
                'lastSent'=>'2026-08-20 10:15','sendCount'=>2,
                'answerStatus'=>'answered','kintone'=>true,
            ],
            [
                'id'=>'c002','org'=>'株式会社テスト','name'=>'佐藤 花子',
                'email'=>'hanako@example.com','department'=>'総務部',
                'phone'=>'03-2345-6789','address'=>'東京都千代田区',
                'lastSent'=>'2026-08-21 11:30','sendCount'=>1,
                'answerStatus'=>'sent','kintone'=>true,
            ],
            [
                'id'=>'c003','org'=>'有限会社サンプル商事','name'=>'鈴木 一郎',
                'email'=>'ichiro@example.com','department'=>'',
                'phone'=>'03-3456-7890','address'=>'東京都新宿区',
                'lastSent'=>'','sendCount'=>0,
                'answerStatus'=>'unsent','kintone'=>false,
            ],
            [
                'id'=>'c004','org'=>'株式会社ABC','name'=>'田中 美咲',
                'email'=>'misaki@example.com','department'=>'企画部',
                'phone'=>'03-4567-8901','address'=>'東京都渋谷区',
                'lastSent'=>'2026-08-22 14:00','sendCount'=>1,
                'answerStatus'=>'sent','kintone'=>true,
            ],
        ],
        'answers' => [
            'survey-001' => [
                [
                    'id'=>'answer-001','customerId'=>'c001',
                    'customer'=>'山田 太郎','org'=>'株式会社サンプル',
                    'date'=>'2026-08-22 13:20',
                    'values'=>[
                        'q1'=>'非常に満足',
                        'q2'=>'サポートが丁寧でした。',
                        'q3'=>'ぜひ利用したい',
                        'q4'=>'特にありません。',
                    ],
                ],
            ],
            'survey-002' => [],
        ],
        'sendHistory' => [],
        'mailSettings' => [
            'smtp'=>'',
            'port'=>'587',
            'encryption'=>'TLS',
            'auth'=>true,
            'username'=>'',
            'password'=>'',
            'from'=>'survey@example.com',
            'fromName'=>'アンケート事務局',
            'replyTo'=>'',
            'connection'=>'未設定',
            'updatedAt'=>'',
        ],
        'kintone' => [
            'subdomain'=>'',
            'appId'=>'',
            'username'=>'',
            'password'=>'',
            'proxy'=>'',
            'sslVerify'=>true,
            'connection'=>'未テスト',
            'connectionDetail'=>'',
            'fields'=>[],
            'mappings'=>[
                'org'=>'','name'=>'','email'=>'','department'=>'',
                'phone'=>'','address'=>[],
            ],
            'syncedAt'=>'',
        ],
    ];
}

function loadApp(): array {
    $d = initialData();
    return [
        'surveys' => loadData('surveys', $d['surveys']),
        'customers' => loadData('customers', $d['customers']),
        'answers' => loadData('answers', $d['answers']),
        'sendHistory' => loadData('send_history', $d['sendHistory']),
        'mailSettings' => loadData('mail_settings', $d['mailSettings']),
        'kintone' => loadData('kintone', $d['kintone']),
    ];
}

function saveApp(array $d): void {
    saveData('surveys', $d['surveys']);
    saveData('customers', $d['customers']);
    saveData('answers', $d['answers']);
    saveData('send_history', $d['sendHistory']);
    saveData('mail_settings', $d['mailSettings']);
    saveData('kintone', $d['kintone']);
}

/* ---------------------------------------------------------
 * アンケート
 * --------------------------------------------------------- */

function surveyIndex(array $data, string $id): int {
    foreach ($data['surveys'] as $i => $s) {
        if (($s['id'] ?? '') === $id) return $i;
    }
    return -1;
}

function surveyById(array $data, string $id): ?array {
    $i = surveyIndex($data, $id);
    return $i >= 0 ? $data['surveys'][$i] : null;
}

function allQuestions(array $survey): array {
    $out = [];
    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $q) $out[] = $q;
    }
    return $out;
}

function renumberSurvey(array &$survey): void {
    $global = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        $qi = 1;
        foreach ($group['questions'] as &$q) {
            if (($survey['numbering'] ?? 'global') === 'group') {
                $q['number'] = 'Q' . ($gi + 1) . '-' . $qi;
            } else {
                $q['number'] = 'Q' . $global;
            }
            $qi++;
            $global++;
        }
        unset($q);
    }
    unset($group);
}

function normalizeSurvey(array $s): array {
    $s['title'] = trim((string)($s['title'] ?? ''));
    $s['description'] = trim((string)($s['description'] ?? ''));
    $s['startAt'] = trim((string)($s['startAt'] ?? ''));
    $s['endAt'] = trim((string)($s['endAt'] ?? ''));
    $s['numbering'] = in_array(($s['numbering'] ?? 'global'), ['global','group'], true)
        ? $s['numbering'] : 'global';

    if (!validDateTime($s['startAt']) || !validDateTime($s['endAt'])) {
        throw new InvalidArgumentException('日時の形式が正しくありません。');
    }

    if ($s['startAt'] !== '' && $s['endAt'] !== '' && $s['startAt'] >= $s['endAt']) {
        throw new InvalidArgumentException('終了日時は開始日時より後にしてください。');
    }

    if ($s['title'] === '') throw new InvalidArgumentException('タイトルを入力してください。');
    if (mb_strlen($s['title']) > 200) throw new InvalidArgumentException('タイトルは200文字以内です。');
    if (mb_strlen($s['description']) > 5000) throw new InvalidArgumentException('説明は5000文字以内です。');

    foreach ($s['groups'] as &$g) {
        $g['title'] = trim((string)($g['title'] ?? ''));
        if ($g['title'] === '') $g['title'] = '無題のグループ';

        foreach ($g['questions'] as &$q) {
            $q['text'] = trim((string)($q['text'] ?? ''));
            $q['type'] = in_array(($q['type'] ?? 'single'), ['single','multiple','free'], true)
                ? $q['type'] : 'single';
            $q['required'] = !empty($q['required']);
            $q['options'] = array_values(array_filter(
                array_map('trim', is_array($q['options'] ?? null) ? $q['options'] : []),
                static fn($v) => $v !== ''
            ));
            if ($q['type'] === 'free') $q['options'] = [];

            if ($q['text'] === '') throw new InvalidArgumentException('質問文を入力してください。');
            if (mb_strlen($q['text']) > 2000) throw new InvalidArgumentException('質問文は2000文字以内です。');

            if ($q['type'] !== 'free' && count($q['options']) < 1) {
                throw new InvalidArgumentException('選択式質問には選択肢が必要です。');
            }

            if ($q['type'] !== 'single') $q['branches'] = [];
            else {
                $branches = is_array($q['branches'] ?? null) ? $q['branches'] : [];
                $clean = [];
                foreach ($branches as $option => $target) {
                    if (in_array($option, $q['options'], true) && ($target === '' || validateId((string)$target))) {
                        $clean[$option] = (string)$target;
                    }
                }
                $q['branches'] = $clean;
            }
        }
        unset($q);
    }
    unset($g);

    renumberSurvey($s);
    return $s;
}

function updateAutomaticStatuses(array &$data): void {
    $now = new DateTimeImmutable('now');

    foreach ($data['surveys'] as &$s) {
        if (($s['status'] ?? '') !== 'published') continue;
        $end = (string)($s['endAt'] ?? '');
        if ($end === '') continue;

        $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $end);
        if ($d !== false && $now > $d) {
            $s['status'] = 'ended';
            $s['updatedAt'] = date('Y-m-d');
        }
    }
    unset($s);
}

function canTransition(string $from, string $to): bool {
    return match ($from) {
        'draft' => $to === 'published',
        'published' => $to === 'stopped',
        'stopped' => $to === 'published',
        'ended' => false,
        default => false,
    };
}

/* ---------------------------------------------------------
 * 回答フロー
 * --------------------------------------------------------- */

function surveyAvailableForAnswer(array $survey): bool {
    if (($survey['status'] ?? '') !== 'published') return false;

    $now = new DateTimeImmutable('now');

    if (!empty($survey['startAt'])) {
        $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $survey['startAt']);
        if ($d !== false && $now < $d) return false;
    }

    if (!empty($survey['endAt'])) {
        $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $survey['endAt']);
        if ($d !== false && $now > $d) return false;
    }

    return true;
}

function questionMap(array $survey): array {
    $m = [];
    foreach (allQuestions($survey) as $q) $m[$q['id']] = $q;
    return $m;
}

function visibleQuestionIds(array $survey, array $answers): array {
    $questions = allQuestions($survey);
    $visible = [];
    $map = questionMap($survey);

    foreach ($questions as $q) {
        $show = true;

        foreach ($questions as $parent) {
            if (($parent['type'] ?? '') !== 'single') continue;
            $branches = $parent['branches'] ?? [];
            if (!is_array($branches)) continue;

            $value = $answers[$parent['id']] ?? null;
            if ($value === null || $value === '') continue;

            $target = $branches[$value] ?? null;
            if ($target === null || $target === '') continue;

            if ($target === $q['id']) {
                $show = true;
                break;
            }

            $targets = array_values($branches);
            if (in_array($q['id'], $targets, true)) {
                $show = false;
            }
        }

        if ($show) $visible[] = $q['id'];
    }

    return array_values(array_unique($visible));
}

function validateAnswers(array $survey, array $answers): array {
    $errors = [];
    $map = questionMap($survey);
    $visible = visibleQuestionIds($survey, $answers);

    foreach ($visible as $qid) {
        if (!isset($map[$qid])) continue;
        $q = $map[$qid];
        if (!$q['required']) continue;

        $v = $answers[$qid] ?? '';
        $empty = is_array($v) ? count($v) === 0 : trim((string)$v) === '';

        if ($empty) {
            $errors[] = $q['number'] . '「' . $q['text'] . '」は必須です。';
            continue;
        }

        if ($q['type'] === 'single' && !in_array((string)$v, $q['options'], true)) {
            $errors[] = $q['number'] . 'の選択値が不正です。';
        }

        if ($q['type'] === 'multiple') {
            if (!is_array($v)) {
                $errors[] = $q['number'] . 'の回答形式が不正です。';
            } else {
                foreach ($v as $x) {
                    if (!in_array((string)$x, $q['options'], true)) {
                        $errors[] = $q['number'] . 'の選択値が不正です。';
                    }
                }
            }
        }
    }

    return $errors;
}

function answerUrl(string $surveyId, ?string $customerId = null): string {
    $scheme = $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');

    $url = $scheme . '://' . $host . $script .
        '?screen=answer&id=' . rawurlencode($surveyId);

    if ($customerId !== null && validateId($customerId)) {
        $url .= '&customer=' . rawurlencode($customerId);
    }
    return $url;
}
<?php
/* ---------------------------------------------------------
 * HTTPストリーム通信
 * --------------------------------------------------------- */

function httpRequest(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null,
    int $timeout = 15,
    bool $verifyTls = true,
    ?string $proxy = null
): array {
    if (!preg_match('#^https://#i', $url)) {
        throw new InvalidArgumentException('HTTPS URLのみ許可されています。');
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        throw new InvalidArgumentException('接続先URLが不正です。');
    }

    $context = [
        'http' => [
            'method' => strtoupper($method),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'header' => implode("\r\n", $headers),
        ],
        'ssl' => [
            'verify_peer' => $verifyTls,
            'verify_peer_name' => $verifyTls,
            'allow_self_signed' => !$verifyTls,
            'SNI_enabled' => true,
            'peer_name' => $parts['host'],
        ],
    ];

    if ($body !== null) {
        $context['http']['content'] = $body;
    }

    if ($proxy !== null && $proxy !== '') {
        if (!preg_match('/^[^:\s]+:\d{1,5}$/', $proxy)) {
            throw new InvalidArgumentException('Proxyはhost:port形式で指定してください。');
        }
        $context['http']['proxy'] = 'tcp://' . $proxy;
        $context['http']['request_fulluri'] = true;
    }

    $ctx = stream_context_create($context);
    $errno = 0;
    $errstr = '';

    $fp = @fopen($url, 'rb', false, $ctx);

    if (!$fp) {
        $last = error_get_last();
        $message = is_array($last) ? (string)($last['message'] ?? '') : '';

        throw new RuntimeException(
            '外部サービスへ接続できません。' .
            ($message !== '' ? ' 接続先またはネットワーク設定を確認してください。' : '')
        );
    }

    stream_set_timeout($fp, $timeout);

    $responseBody = stream_get_contents($fp);
    $meta = stream_get_meta_data($fp);
    fclose($fp);

    if ($responseBody === false) {
        return [
            'ok'=>false,
            'category'=>'response_error',
            'status'=>0,
            'body'=>'',
            'headers'=>[],
            'error'=>'レスポンスを取得できませんでした。',
        ];
    }

    $rawHeaders = $meta['wrapper_data'] ?? [];
    $headersOut = [];
    $status = 0;

    if (is_array($rawHeaders)) {
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
                $status = (int)$m[1];
            } elseif (str_contains($line, ':')) {
                [$k,$v] = explode(':', $line, 2);
                $headersOut[strtolower(trim($k))] = trim($v);
            }
        }
    }

    if (!empty($meta['timed_out'])) {
        return [
            'ok'=>false,'category'=>'timeout','status'=>$status,
            'body'=>$responseBody,'headers'=>$headersOut,
            'error'=>'外部サービスへの通信がタイムアウトしました。',
        ];
    }

    if ($status >= 300 && $status < 400) {
        return [
            'ok'=>false,'category'=>'redirect','status'=>$status,
            'body'=>$responseBody,'headers'=>$headersOut,
            'error'=>'外部サービスからリダイレクト応答が返されました。',
        ];
    }

    return [
        'ok'=>$status >= 200 && $status < 300,
        'category'=>($status >= 200 && $status < 300) ? 'success' : 'http_error',
        'status'=>$status,
        'body'=>$responseBody,
        'headers'=>$headersOut,
        'error'=>($status >= 200 && $status < 300) ? '' : 'HTTPエラーが返されました。',
    ];
}

/* ---------------------------------------------------------
 * kintone
 * --------------------------------------------------------- */

function normalizeKintoneHost(string $input): string {
    $input = trim($input);
    $input = preg_replace('#^https?://#i', '', $input);
    $input = preg_replace('#/.*$#', '', $input);
    $input = trim($input);

    if (!preg_match('/^[A-Za-z0-9.-]+$/', $input)) {
        throw new InvalidArgumentException('kintoneサブドメインが不正です。');
    }

    if (str_contains($input, '.cybozu.com')) {
        $host = $input;
    } else {
        $host = $input . '.cybozu.com';
    }

    return $host;
}

function kintoneAuth(string $username, string $password): string {
    if ($username === '' || $password === '') {
        throw new InvalidArgumentException('kintoneログイン名とパスワードを入力してください。');
    }
    return base64_encode($username . ':' . $password);
}

function kintoneRequest(
    array $settings,
    string $path,
    string $method,
    string $password,
    ?array $payload = null
): array {
    $host = normalizeKintoneHost((string)$settings['subdomain']);
    $appId = (string)$settings['appId'];

    if (!ctype_digit($appId) || (int)$appId < 1) {
        throw new InvalidArgumentException('顧客管理アプリIDが不正です。');
    }

    $url = 'https://' . $host . $path;
    $auth = kintoneAuth((string)$settings['username'], $password);

    $headers = [
        'X-Cybozu-Authorization: ' . $auth,
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: SurveyPOC/1.0',
    ];

    $body = $payload === null ? null : jsonOut($payload);

    return httpRequest(
        $url,
        $method,
        $headers,
        $body,
        20,
        !empty($settings['sslVerify']),
        (($settings['proxy'] ?? '') !== '') ? (string)$settings['proxy'] : null
    );
}

function kintoneErrorMessage(array $r): string {
    $body = json_decode((string)($r['body'] ?? ''), true);

    if (is_array($body)) {
        $code = (string)($body['code'] ?? '');
        $msg = (string)($body['message'] ?? '');
        if ($code !== '' || $msg !== '') {
            return 'kintoneエラー' .
                ($code !== '' ? ' [' . $code . ']' : '') .
                ($msg !== '' ? ': ' . $msg : '');
        }
    }

    return (string)($r['error'] ?? 'kintone通信に失敗しました。');
}

function kintoneTest(array $settings, string $password): array {
    $appId = (int)$settings['appId'];
    return kintoneRequest(
        $settings,
        '/k/v1/app.json?id=' . $appId,
        'GET',
        $password
    );
}

function kintoneFields(array $settings, string $password): array {
    $appId = (int)$settings['appId'];
    return kintoneRequest(
        $settings,
        '/k/v1/app/form/fields.json?app=' . $appId,
        'GET',
        $password
    );
}

function kintoneRecords(array $settings, string $password): array {
    $appId = (int)$settings['appId'];
    return kintoneRequest(
        $settings,
        '/k/v1/records.json?app=' . $appId . '&totalCount=true',
        'GET',
        $password
    );
}

function syncCustomersFromKintone(array $settings, string $password): array {
    $r = kintoneRecords($settings, $password);
    if (!$r['ok']) throw new RuntimeException(kintoneErrorMessage($r));

    $json = json_decode((string)$r['body'], true);
    if (!is_array($json) || !isset($json['records']) || !is_array($json['records'])) {
        throw new RuntimeException('kintoneの顧客レコードを取得できませんでした。');
    }

    $m = $settings['mappings'] ?? [];
    $customers = [];

    foreach ($json['records'] as $record) {
        $get = static function(string $code) use ($record): string {
            if ($code === '') return '';
            $v = $record[$code]['value'] ?? '';
            return is_scalar($v) ? (string)$v : '';
        };

        $addressParts = [];
        foreach (($m['address'] ?? []) as $code) {
            $v = $get((string)$code);
            if ($v !== '') $addressParts[] = $v;
        }

        $email = $get((string)($m['email'] ?? ''));
        $name = $get((string)($m['name'] ?? ''));

        if ($email === '' || !validEmail($email)) continue;
        if ($name === '') $name = '氏名未設定';

        $recordId = $get('$id');
        $customers[] = [
            'id' => $recordId !== '' ? 'k-' . $recordId : uid('k'),
            'org' => $get((string)($m['org'] ?? '')),
            'name' => $name,
            'email' => $email,
            'department' => $get((string)($m['department'] ?? '')),
            'phone' => $get((string)($m['phone'] ?? '')),
            'address' => implode(' ', $addressParts),
            'lastSent' => '',
            'sendCount' => 0,
            'answerStatus' => 'unsent',
            'kintone' => true,
        ];
    }

    return $customers;
}

/* ---------------------------------------------------------
 * SMTP
 * --------------------------------------------------------- */

function smtpRead($fp, int $timeout = 15): array {
    stream_set_timeout($fp, $timeout);
    $lines = '';

    while (($line = fgets($fp, 8192)) !== false) {
        $lines .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }

    if ($lines === '') {
        $meta = stream_get_meta_data($fp);
        if (!empty($meta['timed_out'])) {
            return ['ok'=>false,'code'=>0,'text'=>'タイムアウト'];
        }
        return ['ok'=>false,'code'=>0,'text'=>'応答を取得できません。'];
    }

    $code = (int)substr($lines, 0, 3);

    return [
        'ok' => $code >= 200 && $code < 400,
        'code' => $code,
        'text' => trim($lines),
    ];
}

function smtpCommand($fp, string $command, array $expected): array {
    if (@fwrite($fp, $command . "\r\n") === false) {
        return ['ok'=>false,'code'=>0,'text'=>'SMTPコマンド送信に失敗しました。'];
    }

    $r = smtpRead($fp);
    if (!in_array($r['code'], $expected, true)) {
        $r['ok'] = false;
    }
    return $r;
}

function smtpConnect(array $cfg, string $password): array {
   $host = trim((string)$cfg['smtp']);

    $port = (int)($cfg['port'] ?? 587);
    $enc = strtoupper((string)($cfg['encryption'] ?? 'TLS'));

    if ($host === '') throw new InvalidArgumentException('SMTPサーバを入力してください。');
    if ($port < 1 || $port > 65535) throw new InvalidArgumentException('SMTPポートが不正です。');

    $transport = ($enc === 'SSL' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        $transport,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $ctx
    );

    if (!$fp) {
        throw new RuntimeException('SMTPサーバへ接続できません。');
    }

    stream_set_timeout($fp, 15);

    $r = smtpRead($fp);
    if ($r['code'] !== 220) {
        fclose($fp);
        throw new RuntimeException('SMTPサーバの初期応答が不正です。');
    }

    $r = smtpCommand($fp, 'EHLO localhost', [250]);
    if (!$r['ok']) {
        fclose($fp);
        throw new RuntimeException('SMTP EHLOに失敗しました。');
    }

    if ($enc === 'TLS') {
        $r = smtpCommand($fp, 'STARTTLS', [220]);
        if (!$r['ok']) {
            fclose($fp);
            throw new RuntimeException('SMTP STARTTLSに失敗しました。');
        }

        $crypto = @stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($fp);
            throw new RuntimeException('SMTP TLS接続に失敗しました。');
        }

        $r = smtpCommand($fp, 'EHLO localhost', [250]);
        if (!$r['ok']) {
            fclose($fp);
            throw new RuntimeException('SMTP TLS後のEHLOに失敗しました。');
        }
    }

    if (!empty($cfg['auth'])) {
        $user = (string)($cfg['username'] ?? '');
        if ($user === '' || $password === '') {
            fclose($fp);
            throw new InvalidArgumentException('SMTP認証情報を入力してください。');
        }

        $r = smtpCommand($fp, 'AUTH LOGIN', [334]);
        if (!$r['ok']) {
            fclose($fp);
            throw new RuntimeException('SMTP AUTH LOGINを開始できません。');
        }

        $r = smtpCommand($fp, base64_encode($user), [334]);
        if (!$r['ok']) {
            fclose($fp);
            throw new RuntimeException('SMTPユーザー認証に失敗しました。');
        }

        $r = smtpCommand($fp, base64_encode($password), [235]);
        if (!$r['ok']) {
            fclose($fp);
            throw new RuntimeException('SMTPパスワード認証に失敗しました。');
        }
    }

    return $fp;
}

function smtpClose($fp): void {
    if (is_resource($fp)) {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
    }
}

function mimeHeader(string $s): string {
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function smtpSendOne(array $cfg, string $password, string $to, string $subject, string $body): array {
    if (!validEmail($to)) {
        return ['ok'=>false,'category'=>'input','message'=>'宛先メールアドレスが不正です。'];
    }

    $from = trim((string)($cfg['from'] ?? ''));
    if (!validEmail($from)) {
        return ['ok'=>false,'category'=>'input','message'=>'送信元メールアドレスが不正です。'];
    }

    try {
        $fp = smtpConnect($cfg, $password);

        $r = smtpCommand($fp, 'MAIL FROM:<' . $from . '>', [250]);
        if (!$r['ok']) {
            smtpClose($fp);
            return ['ok'=>false,'category'=>'smtp','message'=>'MAIL FROMに失敗しました。'];
        }

        $r = smtpCommand($fp, 'RCPT TO:<' . $to . '>', [250,251]);
        if (!$r['ok']) {
            smtpClose($fp);
            return ['ok'=>false,'category'=>'smtp','message'=>'RCPT TOに失敗しました。'];
        }

        $r = smtpCommand($fp, 'DATA', [354]);
        if (!$r['ok']) {
            smtpClose($fp);
            return ['ok'=>false,'category'=>'smtp','message'=>'DATAに失敗しました。'];
        }

        $fromName = trim((string)($cfg['fromName'] ?? ''));
        $headers = [];
        $headers[] = 'From: ' . ($fromName !== '' ? mimeHeader($fromName) . ' ' : '') . '<' . $from . '>';
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . mimeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $reply = trim((string)($cfg['replyTo'] ?? ''));
        if ($reply !== '' && validEmail($reply)) {
            $headers[] = 'Reply-To: <' . $reply . '>';
        }

        $payload = implode("\r\n", $headers) . "\r\n\r\n";
        $payload .= preg_replace('/^\./m', '..', str_replace(["\r\n","\r"], ["\n","\n"], $body));
        $payload = str_replace("\n", "\r\n", $payload);
        $payload .= "\r\n.\r\n";

        if (@fwrite($fp, $payload) === false) {
            smtpClose($fp);
            return ['ok'=>false,'category'=>'smtp','message'=>'メール本文の送信に失敗しました。'];
        }

        $r = smtpRead($fp);
        smtpClose($fp);

        if (!$r['ok'] || $r['code'] !== 250) {
            return ['ok'=>false,'category'=>'smtp','message'=>'SMTPサーバがメール送信を受理しませんでした。'];
        }

        return ['ok'=>true,'category'=>'success','message'=>'送信しました。'];
    } catch (InvalidArgumentException $e) {
        return ['ok'=>false,'category'=>'input','message'=>$e->getMessage()];
    } catch (Throwable $e) {
        return ['ok'=>false,'category'=>'connection','message'=>$e->getMessage()];
    }
}

/* ---------------------------------------------------------
 * CSV / PDF
 * --------------------------------------------------------- */

function csvCell(mixed $v): string {
    $s = is_array($v) ? implode(' / ', array_map('strval', $v)) : (string)$v;
    return '"' . str_replace('"', '""', $s) . '"';
}

function outputCsv(array $data, string $surveyId): never {
    $survey = surveyById($data, $surveyId);
    if (!$survey) {
        http_response_code(404);
        exit('アンケートが存在しません。');
    }

    $questions = allQuestions($survey);
    $rows = [];
    $head = ['回答ID','顧客ID','顧客名','組織名','回答日時'];

    foreach ($questions as $q) $head[] = $q['number'] . ' ' . $q['text'];
    $rows[] = $head;

    foreach (($data['answers'][$surveyId] ?? []) as $a) {
        $row = [
            $a['id'] ?? '',
            $a['customerId'] ?? '',
            $a['customer'] ?? '',
            $a['org'] ?? '',
            $a['date'] ?? '',
        ];
        foreach ($questions as $q) {
            $row[] = $a['values'][$q['id']] ?? '';
        }
        $rows[] = $row;
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="survey-' . rawurlencode($surveyId) . '.csv"');
    echo "\xEF\xBB\xBF";

    foreach ($rows as $row) {
        echo implode(',', array_map('csvCell', $row)) . "\r\n";
    }
    exit;
}

function pdfEscape(string $s): string {
    return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s);
}

function outputPdf(array $data, string $surveyId): never {
    $survey = surveyById($data, $surveyId);
    if (!$survey) {
        http_response_code(404);
        exit('アンケートが存在しません。');
    }

    $answers = $data['answers'][$surveyId] ?? [];
    $lines = [
        'Survey Results',
        'Title: ' . $survey['title'],
        'Answers: ' . count($answers),
        '',
    ];

    foreach (allQuestions($survey) as $q) {
        $lines[] = $q['number'] . ' ' . $q['text'];

        $counts = [];
        foreach ($answers as $a) {
            $v = $a['values'][$q['id']] ?? '';
            foreach (is_array($v) ? $v : [$v] as $x) {
                if ((string)$x !== '') {
                    $counts[(string)$x] = ($counts[(string)$x] ?? 0) + 1;
                }
            }
        }

        foreach ($counts as $label => $count) {
            $lines[] = '  ' . $label . ': ' . $count;
        }
        $lines[] = '';
    }

    $stream = "BT\n/F1 10 Tf\n50 800 Td\n";
    $first = true;

    foreach ($lines as $line) {
        if (!$first) $stream .= "0 -16 Td\n";
        $first = false;
        $safe = preg_replace('/[^\x20-\x7E]/', '?', $line);
        $stream .= '(' . pdfEscape((string)$safe) . ") Tj\n";
    }

    $stream .= "ET\n";

    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $obj) {
        $offsets[$i + 1] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xref . "\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="survey-' . $surveyId . '.pdf"');
    echo $pdf;
    exit;
}

/* ---------------------------------------------------------
 * POST処理
 * --------------------------------------------------------- */

function postString(string $key, string $default = ''): string {
    return trim((string)($_POST[$key] ?? $default));
}

function postArray(string $key): array {
    return is_array($_POST[$key] ?? null) ? $_POST[$key] : [];
}

function handlePost(array &$data): ?string {
    $action = postString('action');

    if ($action === '') return null;

    /* アンケート保存 */
    if ($action === 'save_survey') {
        $id = postString('id');
        $groups = [];

        $rawGroups = postArray('groups');

        foreach ($rawGroups as $g) {
            if (!is_array($g)) continue;

            $questions = [];
            foreach (($g['questions'] ?? []) as $q) {
                if (!is_array($q)) continue;

                $options = [];
                foreach (($q['options'] ?? []) as $opt) {
                    if (trim((string)$opt) !== '') $options[] = trim((string)$opt);
                }

                $branches = [];
                foreach (($q['branches'] ?? []) as $option => $target) {
                    $branches[trim((string)$option)] = trim((string)$target);
                }

                $questions[] = [
                    'id' => validateId((string)($q['id'] ?? '')) ? $q['id'] : uid('q'),
                    'text' => (string)($q['text'] ?? ''),
                    'type' => (string)($q['type'] ?? 'single'),
                    'required' => !empty($q['required']),
                    'options' => $options,
                    'branches' => $branches,
                    'number' => '',
                ];
            }

            $groups[] = [
                'id' => validateId((string)($g['id'] ?? '')) ? $g['id'] : uid('g'),
                'title' => (string)($g['title'] ?? ''),
                'questions' => $questions,
            ];
        }

        $idx = $id !== '' ? surveyIndex($data, $id) : -1;
        $old = $idx >= 0 ? $data['surveys'][$idx] : null;

        $survey = [
            'id' => $idx >= 0 ? $old['id'] : uid('survey'),
            'createdAt' => $idx >= 0 ? $old['createdAt'] : date('Y-m-d'),
            'updatedAt' => date('Y-m-d'),
            'title' => postString('title'),
            'description' => postString('description'),
            'startAt' => postString('startAt'),
            'endAt' => postString('endAt'),
            'status' => $idx >= 0 ? $old['status'] : 'draft',
            'numbering' => postString('numbering', 'global'),
            'groups' => $groups,
        ];

        $survey = normalizeSurvey($survey);

        if ($idx >= 0) $data['surveys'][$idx] = $survey;
        else $data['surveys'][] = $survey;

        saveApp($data);
        flash('success', 'アンケートを保存しました。');
        redirectTo('list');
    }

    /* 状態変更 */
    if ($action === 'transition') {
        $id = postString('id');
        $to = postString('to');
        $idx = surveyIndex($data, $id);

        if ($idx < 0) throw new RuntimeException('アンケートが存在しません。');

        $from = (string)$data['surveys'][$idx]['status'];

        if (!canTransition($from, $to)) {
            throw new InvalidArgumentException('指定された状態遷移は許可されていません。');
        }

        $data['surveys'][$idx]['status'] = $to;
        $data['surveys'][$idx]['updatedAt'] = date('Y-m-d');
        saveApp($data);

        flash('success', '状態を変更しました。');
        redirectTo('list');
    }

    /* 複製 */
    if ($action === 'duplicate') {
        $id = postString('id');
        $s = surveyById($data, $id);

        if (!$s) throw new RuntimeException('アンケートが存在しません。');

        $s['id'] = uid('survey');
        $s['title'] .= '（コピー）';
        $s['createdAt'] = date('Y-m-d');
        $s['updatedAt'] = date('Y-m-d');
        $s['status'] = 'draft';

        foreach ($s['groups'] as &$g) {
            $g['id'] = uid('g');
            foreach ($g['questions'] as &$q) $q['id'] = uid('q');
        }
        unset($g, $q);

        renumberSurvey($s);
        $data['surveys'][] = $s;
        saveApp($data);

        flash('success', 'アンケートを複製しました。');
        redirectTo('list');
    }

    /* 削除 */
    if ($action === 'delete_survey') {
        $id = postString('id');
        $idx = surveyIndex($data, $id);

        if ($idx < 0) throw new RuntimeException('アンケートが存在しません。');
        if ($data['surveys'][$idx]['status'] === 'published') {
            throw new InvalidArgumentException('公開中のアンケートは削除できません。');
        }

        array_splice($data['surveys'], $idx, 1);
        unset($data['answers'][$id]);

        saveApp($data);
        flash('success', 'アンケートを削除しました。');
        redirectTo('list');
    }

    /* 回答確認 */
    if ($action === 'answer_confirm') {
        $surveyId = postString('surveyId');
        $survey = surveyById($data, $surveyId);

        if (!$survey || !surveyAvailableForAnswer($survey)) {
            throw new RuntimeException('回答可能なアンケートではありません。');
        }

        $answers = postArray('answers');
        $errors = validateAnswers($survey, $answers);

        if ($errors) {
            $_SESSION['answer_errors'] = $errors;
            $_SESSION['answer_draft'] = $answers;
            redirectTo('answer', ['id'=>$surveyId]);
        }

        $_SESSION['answer_draft'] = $answers;
        $_SESSION['answer_survey'] = $surveyId;
        redirectTo('confirm', ['id'=>$surveyId]);
    }

    /* 回答送信 */
    if ($action === 'answer_submit') {
        $surveyId = postString('surveyId');
        $survey = surveyById($data, $surveyId);

        if (!$survey || !surveyAvailableForAnswer($survey)) {
            throw new RuntimeException('回答可能なアンケートではありません。');
        }

        $answers = $_SESSION['answer_draft'] ?? [];
        if (!is_array($answers)) $answers = [];

        $errors = validateAnswers($survey, $answers);
        if ($errors) {
            $_SESSION['answer_errors'] = $errors;
            redirectTo('answer', ['id'=>$surveyId]);
        }

        $customerId = validateId(postString('customerId'))
            ? postString('customerId') : '';

        $customer = null;
        if ($customerId !== '') {
            foreach ($data['customers'] as $c) {
                if (($c['id'] ?? '') === $customerId) {
                    $customer = $c;
                    break;
                }
            }
        }

        $data['answers'][$surveyId] ??= [];
        $data['answers'][$surveyId][] = [
            'id' => uid('answer'),
            'customerId' => $customerId,
            'customer' => $customer['name'] ?? '未登録回答者',
            'org' => $customer['org'] ?? '',
            'date' => now(),
            'values' => $answers,
        ];

        if ($customer) {
            foreach ($data['customers'] as &$c) {
                if (($c['id'] ?? '') === $customerId) {
                    $c['answerStatus'] = 'answered';
                    break;
                }
            }
            unset($c);
        }

        saveApp($data);

        $_SESSION['answer_complete'] = $surveyId;
        unset($_SESSION['answer_draft'], $_SESSION['answer_errors'], $_SESSION['answer_survey']);

        redirectTo('complete', ['id'=>$surveyId]);
    }

    /* kintone設定保存 */
    if ($action === 'save_kintone') {
        $data['kintone']['subdomain'] = postString('subdomain');
        $data['kintone']['appId'] = postString('appId');
        $data['kintone']['username'] = postString('username');
        $data['kintone']['proxy'] = postString('proxy');
        $data['kintone']['sslVerify'] = postString('sslVerify') === '1';

        $data['kintone']['connection'] = '未テスト';
        $data['kintone']['connectionDetail'] = '';

        $password = postString('password');
        if ($password !== '') $data['kintone']['password'] = encryptSecret($password);

        saveApp($data);
        flash('success', 'kintone設定を保存しました。');
        redirectTo('kintone');
    }

    /* kintone接続テスト */
    if ($action === 'test_kintone') {
        $settings = $data['kintone'];
        $password = postString('password');

        if ($password === '' && !empty($settings['password'])) {
            $password = decryptSecret((string)$settings['password']);
        }

        $r = kintoneTest($settings, $password);

        if ($r['ok']) {
            $data['kintone']['connection'] = '接続確認済み';
            $data['kintone']['connectionDetail'] = '接続テスト成功';
            saveApp($data);
            flash('success', 'kintone接続テストに成功しました。');
        } else {
            $data['kintone']['connection'] = '接続できません';
            $data['kintone']['connectionDetail'] = kintoneErrorMessage($r);
            saveApp($data);
            flash('danger', 'kintone接続テストに失敗しました。', kintoneErrorMessage($r));
        }

        redirectTo('kintone');
    }

    /* kintone項目取得 */
    if ($action === 'fetch_kintone_fields') {
        $settings = $data['kintone'];
        $password = postString('password');

        if ($password === '' && !empty($settings['password'])) {
            $password = decryptSecret((string)$settings['password']);
        }

        $r = kintoneFields($settings, $password);

        if (!$r['ok']) {
            flash('danger', 'kintone項目取得に失敗しました。', kintoneErrorMessage($r));
            redirectTo('kintone');
        }

        $json = json_decode((string)$r['body'], true);
        $fields = [];

        foreach (($json ?? []) as $code => $field) {
            if (!is_array($field)) continue;
            $fields[$code] = [
                'label' => (string)($field['label'] ?? $code),
                'type' => (string)($field['type'] ?? ''),
            ];
        }

        $data['kintone']['fields'] = $fields;
        saveApp($data);

        flash('success', 'kintone項目を取得しました。');
        redirectTo('kintone');
    }

    /* kintoneマッピング */
    if ($action === 'save_kintone_mapping') {
        $data['kintone']['mappings'] = [
            'org' => postString('map_org'),
            'name' => postString('map_name'),
            'email' => postString('map_email'),
            'department' => postString('map_department'),
            'phone' => postString('map_phone'),
            'address' => array_values(array_filter(
                array_map('trim', postArray('map_address')),
                static fn($x) => $x !== ''
            )),
        ];

        saveApp($data);
        flash('success', 'kintone項目マッピングを保存しました。');
        redirectTo('kintone');
    }

    /* 顧客同期 */
    if ($action === 'sync_kintone') {
        $password = postString('password');

        if ($password === '' && !empty($data['kintone']['password'])) {
            $password = decryptSecret((string)$data['kintone']['password']);
        }

        $customers = syncCustomersFromKintone($data['kintone'], $password);

        $old = [];
        foreach ($data['customers'] as $c) $old[$c['email']] = $c;

        foreach ($customers as &$c) {
            if (isset($old[$c['email']])) {
                $c['lastSent'] = $old[$c['email']]['lastSent'] ?? '';
                $c['sendCount'] = $old[$c['email']]['sendCount'] ?? 0;
                $c['answerStatus'] = $old[$c['email']]['answerStatus'] ?? 'unsent';
            }
        }
        unset($c);

        $data['customers'] = $customers;
        $data['kintone']['syncedAt'] = now();

        saveApp($data);
        flash('success', count($customers) . '件の顧客情報を同期しました。');
        redirectTo('kintone');
    }

    return null;
}
<?php
/* ---------------------------------------------------------
 * POST続き：メール
 * --------------------------------------------------------- */

function handleMailPost(array &$data): ?string {
    $action = postString('action');

    if ($action === 'save_mail') {
        $data['mailSettings']['smtp'] = postString('smtp');
        $data['mailSettings']['port'] = postString('port', '587');
        $data['mailSettings']['encryption'] = strtoupper(postString('encryption', 'TLS'));
        $data['mailSettings']['auth'] = postString('auth') === '1';
        $data['mailSettings']['username'] = postString('username');
        $data['mailSettings']['from'] = postString('from');
        $data['mailSettings']['fromName'] = postString('fromName');
        $data['mailSettings']['replyTo'] = postString('replyTo');

        $password = postString('password');
        if ($password !== '') {
            $data['mailSettings']['password'] = encryptSecret($password);
        }

        $data['mailSettings']['connection'] = '未設定';
        $data['mailSettings']['updatedAt'] = now();

        if (!validEmail($data['mailSettings']['from'])) {
            throw new InvalidArgumentException('送信元メールアドレスが不正です。');
        }

        if ($data['mailSettings']['replyTo'] !== '' &&
            !validEmail($data['mailSettings']['replyTo'])) {
            throw new InvalidArgumentException('返信先メールアドレスが不正です。');
        }

        saveApp($data);
        flash('success', 'メール設定を保存しました。');
        redirectTo('mail');
    }

    if ($action === 'test_mail_connection') {
        $password = postString('password');

        if ($password === '' && !empty($data['mailSettings']['password'])) {
            $password = decryptSecret((string)$data['mailSettings']['password']);
        }

        try {
            $fp = smtpConnect($data['mailSettings'], $password);
            smtpClose($fp);

            $data['mailSettings']['connection'] = '接続確認済み';
            saveApp($data);
            flash('success', 'SMTP接続・認証テストに成功しました。');
        } catch (Throwable $e) {
            $data['mailSettings']['connection'] = '接続できません';
            saveApp($data);
            flash('danger', 'SMTP接続テストに失敗しました。', $e->getMessage());
        }

        redirectTo('mail');
    }

    if ($action === 'test_mail_send') {
        $to = postString('testTo');
        if (!validEmail($to)) throw new InvalidArgumentException('テスト送信先メールアドレスが不正です。');

        $password = postString('password');
        if ($password === '' && !empty($data['mailSettings']['password'])) {
            $password = decryptSecret((string)$data['mailSettings']['password']);
        }

        $r = smtpSendOne(
            $data['mailSettings'],
            $password,
            $to,
            'アンケートアプリ SMTPテスト',
            "SMTP接続・送信テストです。\n\n送信日時: " . now()
        );

        if ($r['ok']) flash('success', 'テストメールを送信しました。');
        else flash('danger', 'テストメール送信に失敗しました。', $r['message']);

        redirectTo('mail');
    }

    return null;
}

function renderAlert(?array $f): string {
    if (!$f) return '';

    $cls = ($f['type'] ?? '') === 'success'
        ? 'success'
        : (($f['type'] ?? '') === 'danger' ? 'danger' : 'info');

    return '<div class="alert ' . h($cls) . '">' .
        '<strong>' . h($f['message'] ?? '') . '</strong>' .
        (!empty($f['detail']) ? '<div class="detail">' . h($f['detail']) . '</div>' : '') .
        '</div>';
}

function layout(string $title, string $body, bool $admin = true, ?array $flash = null): string {
    $nav = $admin ? '
        <nav class="nav">
          <a href="?screen=list">アンケート</a>
          <a href="?screen=kintone">kintone</a>
          <a href="?screen=mail">メール</a>
        </nav>' : '';

    return '<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . h($title) . ' - アンケートアプリ</title>
<style>
:root{
 --primary:#2563eb;--primary-dark:#1d4ed8;--bg:#f5f7fb;
 --text:#1f2937;--muted:#6b7280;--line:#e5e7eb;
 --white:#fff;--danger:#dc2626;--success:#15803d;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;line-height:1.6}
a{color:var(--primary);text-decoration:none}
header.top{height:64px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;padding:0 24px;gap:24px}
.logo{font-weight:700;font-size:18px}
.nav{display:flex;gap:18px;margin-left:auto}
.nav a{color:#374151;font-size:14px}
main{max-width:1240px;margin:0 auto;padding:28px 20px 60px}
h1{font-size:26px;margin:0 0 20px}
h2{font-size:20px;margin:0 0 16px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 2px 8px rgba(15,23,42,.04);padding:22px;margin-bottom:18px}
.toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.actions{display:flex;gap:8px;flex-wrap:wrap}
button,.btn{border:1px solid #d1d5db;background:#fff;color:#374151;border-radius:8px;padding:9px 14px;cursor:pointer;font-size:14px}
button.primary,.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
button.danger,.btn.danger{color:#fff;background:var(--danger);border-color:var(--danger)}
button:hover,.btn:hover{opacity:.9}
input,textarea,select{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;background:#fff;font:inherit}
textarea{min-height:110px;resize:vertical}
label{display:block;font-weight:600;font-size:14px;margin-bottom:6px}
.field{margin-bottom:16px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:900px}
th,td{padding:12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top;font-size:14px}
th{background:#f9fafb;font-weight:700}
.badge{display:inline-block;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:700}
.badge.draft{background:#eef2ff;color:#4338ca}
.badge.published{background:#dcfce7;color:#166534}
.badge.stopped{background:#fef3c7;color:#92400e}
.badge.ended{background:#e5e7eb;color:#374151}
.alert{padding:14px 16px;border-radius:10px;margin-bottom:18px;border:1px solid}
.alert.success{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
.alert.danger{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.alert.info{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}
.detail{margin-top:5px;font-size:13px;white-space:pre-wrap}
.muted{color:var(--muted);font-size:13px}
.question{border:1px solid var(--line);border-radius:10px;padding:16px;margin:12px 0;background:#fff}
.question-head{display:flex;justify-content:space-between;gap:10px;align-items:center}
.qnumber{font-weight:700;color:var(--primary)}
.group{border:1px solid #dbeafe;border-radius:12px;padding:16px;margin:18px 0;background:#fafdff}
.option-row{display:flex;gap:8px;align-items:center;margin:7px 0}
.option-row input{flex:1}
.answer-card{max-width:720px;margin:28px auto}
.answer-card h1{font-size:24px}
.choice{display:block;border:1px solid #d1d5db;border-radius:10px;padding:13px;margin:8px 0;cursor:pointer}
.choice:hover{border-color:var(--primary);background:#eff6ff}
.choice input{width:auto;margin-right:8px}
.sticky-actions{display:flex;justify-content:space-between;gap:12px;margin-top:24px}
.stat{font-size:28px;font-weight:700}
.drag-handle{cursor:grab;color:#9ca3af}
@media(max-width:700px){
 header.top{padding:0 14px;height:auto;min-height:58px;flex-wrap:wrap;padding-top:10px;padding-bottom:10px}
 .nav{width:100%;margin-left:0;overflow:auto}
 main{padding:18px 12px 40px}
 .grid2,.grid3{grid-template-columns:1fr}
 h1{font-size:22px}
 .card{padding:16px}
 .sticky-actions button,.sticky-actions .btn{flex:1}
}
</style>
</head>
<body>
' . ($admin ? '<header class="top"><div class="logo">アンケートアプリ</div>' . $nav . '</header>' : '') . '
<main>' . renderAlert($flash) . $body . '</main>
</body>
</html>';
}

/* ---------------------------------------------------------
 * 管理画面
 * --------------------------------------------------------- */

function renderList(array $data): string {
    $q = trim((string)($_GET['q'] ?? ''));
    $status = (string)($_GET['status'] ?? 'all');
    $sort = (string)($_GET['sort'] ?? 'updated-desc');

    $surveys = $data['surveys'];

    if ($q !== '') {
        $surveys = array_values(array_filter(
            $surveys,
            static fn($s) => mb_stripos((string)$s['title'], $q) !== false
        ));
    }

    if (in_array($status, ['draft','published','stopped','ended'], true)) {
        $surveys = array_values(array_filter(
            $surveys,
            static fn($s) => ($s['status'] ?? '') === $status
        ));
    }

    usort($surveys, static function($a,$b) use ($sort) {
        return match ($sort) {
            'updated-asc' => strcmp((string)$a['updatedAt'], (string)$b['updatedAt']),
            'answers-desc' => count($GLOBALS['__app']['answers'][$b['id']] ?? [])
                <=> count($GLOBALS['__app']['answers'][$a['id']] ?? []),
            'answers-asc' => count($GLOBALS['__app']['answers'][$a['id']] ?? [])
                <=> count($GLOBALS['__app']['answers'][$b['id']] ?? []),
            'start-desc' => strcmp((string)$b['startAt'], (string)$a['startAt']),
            'start-asc' => strcmp((string)$a['startAt'], (string)$b['startAt']),
            default => strcmp((string)$b['updatedAt'], (string)$a['updatedAt']),
        };
    });

    ob_start(); ?>
    <div class="toolbar">
      <div>
        <h1>アンケート一覧</h1>
        <div class="muted">管理業務の起点</div>
      </div>
      <a class="btn primary" href="?screen=edit">＋ 新規作成</a>
    </div>

    <div class="card">
      <form method="get">
        <input type="hidden" name="screen" value="list">
        <div class="grid3">
          <div>
            <label>タイトル検索</label>
            <input name="q" value="<?=h($q)?>" placeholder="タイトルを入力してEnter">
          </div>
          <div>
            <label>ステータス</label>
            <select name="status">
              <?php foreach(['all'=>'すべて','published'=>'公開中','draft'=>'下書き','stopped'=>'停止','ended'=>'終了'] as $k=>$v): ?>
                <option value="<?=h($k)?>" <?=$status===$k?'selected':''?>><?=h($v)?></option>
              <?php endforeach ?>
            </select>
          </div>
          <div>
            <label>ソート</label>
            <select name="sort">
              <option value="updated-desc" <?=$sort==='updated-desc'?'selected':''?>>更新日：新しい順</option>
              <option value="updated-asc" <?=$sort==='updated-asc'?'selected':''?>>更新日：古い順</option>
              <option value="answers-desc" <?=$sort==='answers-desc'?'selected':''?>>回答数：多い順</option>
              <option value="answers-asc" <?=$sort==='answers-asc'?'selected':''?>>回答数：少ない順</option>
              <option value="start-desc" <?=$sort==='start-desc'?'selected':''?>>開始日：新しい順</option>
              <option value="start-asc" <?=$sort==='start-asc'?'selected':''?>>開始日：古い順</option>
            </select>
          </div>
        </div>
        <div class="actions"><button class="primary">検索</button></div>
      </form>
    </div>

    <div class="card">
      <div class="table-wrap">
      <table>
        <thead><tr>
          <th>タイトル</th><th>作成日</th><th>更新日</th><th>期間</th>
          <th>状態</th><th>回答数</th><th>操作</th>
        </tr></thead>
        <tbody>
        <?php foreach($surveys as $s):
          $cnt=count($data['answers'][$s['id']] ?? []);
        ?>
          <tr>
            <td><strong><?=h($s['title'])?></strong><br><span class="muted"><?=h($s['description'])?></span></td>
            <td><?=h($s['createdAt'])?></td>
            <td><?=h($s['updatedAt'])?></td>
            <td><?=h($s['startAt'])?><br>～ <?=h($s['endAt'])?></td>
            <td><span class="badge <?=h(statusClass($s['status']))?>"><?=h(statusLabel($s['status']))?></span></td>
            <td><?=$cnt?></td>
            <td>
              <div class="actions">
                <a class="btn" href="?screen=edit&id=<?=rawurlencode($s['id'])?>">確認・編集</a>
                <a class="btn" href="?screen=preview&id=<?=rawurlencode($s['id'])?>">プレビュー</a>
                <a class="btn" href="?screen=analytics&id=<?=rawurlencode($s['id'])?>">集計</a>
                <a class="btn" href="?screen=send&id=<?=rawurlencode($s['id'])?>">送信</a>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="duplicate">
                  <input type="hidden" name="id" value="<?=h($s['id'])?>">
                  <button>複製</button>
                </form>
                <?php if($s['status']!=='published'): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？')">
                  <input type="hidden" name="action" value="delete_survey">
                  <input type="hidden" name="id" value="<?=h($s['id'])?>">
                  <button class="danger">削除</button>
                </form>
                <?php endif ?>
              </div>
            </td>
          </tr>
        <?php endforeach ?>
        <?php if(!$surveys): ?><tr><td colspan="7">該当するアンケートはありません。</td></tr><?php endif ?>
        </tbody>
      </table>
      </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

function renderEdit(array $data, ?array $survey): string {
    $new = !$survey;
    if (!$survey) {
        $survey = [
            'id'=>'','title'=>'','description'=>'','startAt'=>'','endAt'=>'',
            'numbering'=>'global','status'=>'draft',
            'groups'=>[
                ['id'=>uid('g'),'title'=>'新しいグループ','questions'=>[
                    ['id'=>uid('q'),'text'=>'','type'=>'single','required'=>false,
                     'options'=>['選択肢1','選択肢2'],'branches'=>[],'number'=>'Q1']
                ]]
            ]
        ];
        renumberSurvey($survey);
    }

    ob_start(); ?>
    <div class="toolbar">
      <div>
        <h1><?= $new ? 'アンケート作成' : 'アンケート編集' ?></h1>
        <div class="muted">質問番号は自動採番されます。</div>
      </div>
      <a class="btn" href="?screen=list" onclick="return confirm('編集内容を破棄しますか？')">キャンセル</a>
    </div>

    <form method="post" id="surveyForm">
      <input type="hidden" name="action" value="save_survey">
      <input type="hidden" name="id" value="<?=h($survey['id'])?>">

      <div class="card">
        <div class="field"><label>タイトル</label><input required maxlength="200" name="title" value="<?=h($survey['title'])?>"></div>
        <div class="field"><label>説明</label><textarea maxlength="5000" name="description"><?=h($survey['description'])?></textarea></div>
        <div class="grid2">
          <div class="field"><label>開始日時</label><input type="datetime-local" name="startAt" value="<?=h($survey['startAt'])?>"></div>
          <div class="field"><label>終了日時</label><input type="datetime-local" name="endAt" value="<?=h($survey['endAt'])?>"></div>
        </div>
        <div class="field">
          <label>質問番号</label>
          <select name="numbering" id="numbering">
            <option value="global" <?=$survey['numbering']==='global'?'selected':''?>>全体通番：Q1、Q2、Q3…</option>
            <option value="group" <?=$survey['numbering']==='group'?'selected':''?>>グループ単位：Q1-1、Q1-2、Q2-1…</option>
          </select>
        </div>
      </div>

      <div id="groups">
      <?php foreach($survey['groups'] as $gi=>$g): ?>
        <div class="group" data-group>
          <div class="question-head">
            <h2>グループ <?=($gi+1)?></h2>
            <button type="button" onclick="removeGroup(this)">グループを削除</button>
          </div>
          <div class="field">
            <label>グループタイトル</label>
            <input name="groups[<?=$gi?>][title]" value="<?=h($g['title'])?>">
            <input type="hidden" name="groups[<?=$gi?>][id]" value="<?=h($g['id'])?>">
          </div>

          <div class="questions">
          <?php foreach($g['questions'] as $qi=>$q): ?>
            <div class="question" data-question>
              <div class="question-head">
                <span class="qnumber"><?=h($q['number'])?></span>
                <button type="button" onclick="removeQuestion(this)">質問を削除</button>
              </div>
              <input type="hidden" name="groups[<?=$gi?>][questions][<?=$qi?>][id]" value="<?=h($q['id'])?>">
              <input type="hidden" name="groups[<?=$gi?>][questions][<?=$qi?>][number]" value="">
              <div class="field">
                <label>質問文</label>
                <textarea name="groups[<?=$gi?>][questions][<?=$qi?>][text]" required><?=h($q['text'])?></textarea>
              </div>
              <div class="grid2">
                <div class="field">
                  <label>回答形式</label>
                  <select name="groups[<?=$gi?>][questions][<?=$qi?>][type]" onchange="toggleOptions(this)">
                    <option value="single" <?=$q['type']==='single'?'selected':''?>>単一選択</option>
                    <option value="multiple" <?=$q['type']==='multiple'?'selected':''?>>複数選択</option>
                    <option value="free" <?=$q['type']==='free'?'selected':''?>>自由記述</option>
                  </select>
                </div>
                <div class="field">
                  <label>必須</label>
                  <select name="groups[<?=$gi?>][questions][<?=$qi?>][required]">
                    <option value="0" <?=empty($q['required'])?'selected':''?>>任意</option>
                    <option value="1" <?=!empty($q['required'])?'selected':''?>>必須</option>
                  </select>
                </div>
              </div>
              <div class="options">
                <label>選択肢</label>
                <?php foreach($q['options'] as $oi=>$opt): ?>
                  <div class="option-row">
                    <input name="groups[<?=$gi?>][questions][<?=$qi?>][options][<?=$oi?>]" value="<?=h($opt)?>">
                    <button type="button" onclick="this.parentElement.remove()">削除</button>
                  </div>
                <?php endforeach ?>
                <button type="button" onclick="addOption(this)">＋ 選択肢</button>
              </div>
            </div>
          <?php endforeach ?>
          </div>
          <button type="button" onclick="addQuestion(this)">＋ 質問を追加</button>
        </div>
      <?php endforeach ?>
      </div>

      <div class="actions">
        <button type="button" class="btn" onclick="addGroup()">＋ グループを追加</button>
        <button class="primary">保存して一覧へ</button>
      </div>
    </form>

<script>
function reindex(){
 const groups=[...document.querySelectorAll('[data-group]')];
 groups.forEach((g,gi)=>{
   g.querySelector('h2').textContent='グループ '+(gi+1);
   g.querySelectorAll('[data-question]').forEach((q,qi)=>{
     const num=document.getElementById('numbering').value==='group'
       ? 'Q'+(gi+1)+'-'+(qi+1)
       : 'Q'+(groups.slice(0,gi).reduce((n,x)=>n+x.querySelectorAll('[data-question]').length,0)+qi+1);
     q.querySelector('.qnumber').textContent=num;
     q.querySelectorAll('input,select,textarea').forEach(el=>{
       const name=el.getAttribute('name');
       if(!name)return;
       const m=name.match(/^groups\[[0-9]+\]\[questions\]\[[0-9]+\](.*)$/);
       if(m) el.name='groups['+gi+'][questions]['+qi+']'+m[1];
     });
   });
   g.querySelectorAll(':scope > input,.field > input[type=hidden]').forEach(el=>{
     const n=el.name||'';
     if(n.endsWith('[id]')) el.name='groups['+gi+'][id]';
     if(n.endsWith('[title]')) el.name='groups['+gi+'][title]';
   });
 });
}
function removeGroup(b){if(confirm('このグループを削除しますか？')){b.closest('[data-group]').remove();reindex()}}
function removeQuestion(b){if(confirm('この質問を削除しますか？')){b.closest('[data-question]').remove();reindex()}}
function toggleOptions(s){s.closest('[data-question]').querySelector('.options').style.display=s.value==='free'?'none':'block'}
function addOption(b){
 const box=b.parentElement;
 const q=b.closest('[data-question]');
 const idx=box.querySelectorAll('.option-row').length;
 const m=q.querySelector('[name*="[questions]"][name$="[id]"]').name.match(/groups\[(\d+)\]\[questions\]\[(\d+)\]/);
 if(!m)return;
 const row=document.createElement('div');row.className='option-row';
 row.innerHTML='<input name="groups['+m[1]+'][questions]['+m[2]+'][options]['+idx+']"><button type="button" onclick="this.parentElement.remove()">削除</button>';
 box.insertBefore(row,b);
}
function addQuestion(b){
 const g=b.closest('[data-group]'), gi=[...document.querySelectorAll('[data-group]')].indexOf(g);
 const qi=g.querySelectorAll('[data-question]').length;
 const id='q-'+Math.random().toString(16).slice(2);
 const q=document.createElement('div');q.className='question';q.setAttribute('data-question', '');
 q.innerHTML='<div class="question-head"><span class="qnumber"></span><button type="button" onclick="removeQuestion(this)">質問を削除</button></div>'+
 '<input type="hidden" name="groups['+gi+'][questions]['+qi+'][id]" value="'+id+'">'+
 '<input type="hidden" name="groups['+gi+'][questions]['+qi+'][number]" value="">'+
 '<div class="field"><label>質問文</label><textarea required name="groups['+gi+'][questions]['+qi+'][text]"></textarea></div>'+
 '<div class="grid2"><div class="field"><label>回答形式</label><select name="groups['+gi+'][questions]['+qi+'][type]" onchange="toggleOptions(this)"><option value="single">単一選択</option><option value="multiple">複数選択</option><option value="free">自由記述</option></select></div>'+
 '<div class="field"><label>必須</label><select name="groups['+gi+'][questions]['+qi+'][required]"><option value="0">任意</option><option value="1">必須</option></select></div></div>'+
 '<div class="options"><label>選択肢</label><div class="option-row"><input name="groups['+gi+'][questions]['+qi+'][options][0]"><button type="button" onclick="this.parentElement.remove()">削除</button></div><button type="button" onclick="addOption(this)">＋ 選択肢</button></div>';
 g.querySelector('.questions').appendChild(q);reindex();
}
function addGroup(){
 const box=document.getElementById('groups');
 const gi=box.querySelectorAll('[data-group]').length;
 const g=document.createElement('div');g.className='group';g.setAttribute('data-group','');
 g.innerHTML='<div class="question-head"><h2></h2><button type="button" onclick="removeGroup(this)">グループを削除</button></div>'+
 '<div class="field"><label>グループタイトル</label><input name="groups['+gi+'][title]" value="新しいグループ"><input type="hidden" name="groups['+gi+'][id]" value="g-'+Math.random().toString(16).slice(2)+'"></div>'+
 '<div class="questions"></div><button type="button" onclick="addQuestion(this)">＋ 質問を追加</button>';
 box.appendChild(g);addQuestion(g.querySelector('button:last-child'));reindex();
}
document.getElementById('numbering').addEventListener('change',reindex);
reindex();
</script>
    <?php
    return (string)ob_get_clean();
}

function renderPreview(array $survey): string {
    ob_start(); ?>
    <div class="toolbar">
      <div><h1>プレビュー</h1><div class="muted"><?=h($survey['title'])?></div></div>
      <a class="btn" href="?screen=edit&id=<?=rawurlencode($survey['id'])?>">編集へ戻る</a>
    </div>
    <div class="answer-card">
      <div class="card">
        <h1><?=h($survey['title'])?></h1>
        <p><?=nl2br(h($survey['description']))?></p>
      </div>
      <?php foreach($survey['groups'] as $g): ?>
        <div class="card">
          <h2><?=h($g['title'])?></h2>
          <?php foreach($g['questions'] as $q): ?>
            <div class="question">
              <div class="qnumber"><?=h($q['number'])?> <?=!empty($q['required'])?'*':''?></div>
              <p><?=nl2br(h($q['text']))?></p>
              <?php if($q['type']==='free'): ?>
                <textarea disabled></textarea>
              <?php else: foreach($q['options'] as $o): ?>
                <label class="choice"><input type="<?=$q['type']==='multiple'?'checkbox':'radio'?>" disabled> <?=h($o)?></label>
              <?php endforeach; endif ?>
            </div>
          <?php endforeach ?>
        </div>
      <?php endforeach ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function renderAnswer(array $survey, array $errors = []): string {
    $draft = $_SESSION['answer_draft'] ?? [];
    if (!is_array($draft)) $draft = [];

    ob_start(); ?>
    <div class="answer-card">
      <div class="card">
        <h1><?=h($survey['title'])?></h1>
        <p><?=nl2br(h($survey['description']))?></p>
      </div>

      <?php if($errors): ?>
      <div class="alert danger">
        <strong>入力内容を確認してください。</strong>
        <ul><?php foreach($errors as $e): ?><li><?=h($e)?></li><?php endforeach ?></ul>
      </div>
      <?php endif ?>

      <form method="post">
        <input type="hidden" name="action" value="answer_confirm">
        <input type="hidden" name="surveyId" value="<?=h($survey['id'])?>">
        <?php if(validateId((string)($_GET['customer'] ?? ''))): ?>
          <input type="hidden" name="customerId" value="<?=h($_GET['customer'])?>">
        <?php endif ?>

        <?php foreach($survey['groups'] as $g): ?>
        <div class="card">
          <h2><?=h($g['title'])?></h2>

          <?php foreach($g['questions'] as $q):
            $v=$draft[$q['id']]??'';
          ?>
          <div class="question" data-answer-question="<?=h($q['id'])?>">
            <div class="qnumber"><?=h($q['number'])?> <?=!empty($q['required'])?'*':''?></div>
            <p><strong><?=nl2br(h($q['text']))?></strong></p>

            <?php if($q['type']==='free'): ?>
              <textarea name="answers[<?=h($q['id'])?>]" maxlength="5000"><?=h(is_string($v)?$v:'')?></textarea>
            <?php elseif($q['type']==='single'): ?>
              <?php foreach($q['options'] as $o): ?>
                <label class="choice">
                  <input type="radio" name="answers[<?=h($q['id'])?>]" value="<?=h($o)?>" <?=$v===$o?'checked':''?>>
                  <?=h($o)?>
                </label>
              <?php endforeach ?>
            <?php else: ?>
              <?php $vv=is_array($v)?$v:[]; ?>
              <?php foreach($q['options'] as $o): ?>
                <label class="choice">
                  <input type="checkbox" name="answers[<?=h($q['id'])?>][]" value="<?=h($o)?>" <?=in_array($o,$vv,true)?'checked':''?>>
                  <?=h($o)?>
                </label>
              <?php endforeach ?>
            <?php endif ?>
          </div>
          <?php endforeach ?>
        </div>
        <?php endforeach ?>

        <div class="sticky-actions">
          <a class="btn" href="?screen=list">戻る</a>
          <button class="primary">回答を確認する</button>
        </div>
      </form>
    </div>
    <?php
    return (string)ob_get_clean();
}

function renderConfirm(array $survey): string {
    $draft = $_SESSION['answer_draft'] ?? [];
    if (!is_array($draft)) $draft=[];

    ob_start(); ?>
    <div class="answer-card">
      <div class="card">
        <h1>回答確認</h1>
        <p><?=h($survey['title'])?></p>
      </div>

      <div class="card">
      <?php foreach($survey['groups'] as $g): ?>
        <h2><?=h($g['title'])?></h2>
        <?php foreach($g['questions'] as $q): ?>
          <div class="question">
            <div class="qnumber"><?=h($q['number'])?></div>
            <p><strong><?=nl2br(h($q['text']))?></strong></p>
            <div>
              <?php
              $v=$draft[$q['id']]??'';
              echo nl2br(h(is_array($v)?implode(' / ',$v):(string)$v));
              ?>
            </div>
          </div>
        <?php endforeach ?>
      <?php endforeach ?>
      </div>

      <div class="sticky-actions">
        <a class="btn" href="?screen=answer&id=<?=rawurlencode($survey['id'])?>">修正する</a>
        <form method="post">
          <input type="hidden" name="action" value="answer_submit">
          <input type="hidden" name="surveyId" value="<?=h($survey['id'])?>">
          <?php if(validateId((string)($_GET['customer']??''))): ?>
          <input type="hidden" name="customerId" value="<?=h($_GET['customer'])?>">
          <?php endif ?>
          <button class="primary">回答を送信する</button>
        </form>
      </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

function renderComplete(array $survey): string {
    return '<div class="answer-card"><div class="card" style="text-align:center">' .
        '<h1>回答ありがとうございました</h1>' .
        '<p>' . h($survey['title']) . '</p>' .
        '<p>回答は正常に送信されました。</p>' .
        '<p class="muted">この画面で回答者フローは終了します。</p>' .
        '</div></div>';
}

/* ---------------------------------------------------------
 * 集計
 * --------------------------------------------------------- */

function renderAnalytics(array $data, array $survey): string {
    $answers=$data['answers'][$survey['id']]??[];
    $sent=0;

    foreach($data['sendHistory'] as $h) {
        if(($h['surveyId']??'')===$survey['id']) $sent+=(int)($h['count']??0);
    }

    $answered=count($answers);
    $registered=0;

    foreach($answers as $a) {
        if(($a['customerId']??'')!=='') $registered++;
    }

    $unregistered=$answered-$registered;
    $unanswered=max(0,$sent-$answered);
    $rate=$sent>0?round($answered/$sent*100,1):0;

    ob_start(); ?>
    <div class="toolbar">
      <div><h1>回答集計・分析</h1><div class="muted">対象：<?=h($survey['title'])?></div></div>
      <div class="actions">
        <a class="btn" href="?screen=analytics&id=<?=rawurlencode($survey['id'])?>&export=csv">CSV</a>
        <a class="btn" href="?screen=analytics&id=<?=rawurlencode($survey['id'])?>&export=pdf">PDF</a>
      </div>
    </div>

    <div class="grid3">
      <div class="card"><div class="muted">送信対象者数</div><div class="stat"><?=$sent?></div></div>
      <div class="card"><div class="muted">回答数</div><div class="stat"><?=$answered?></div></div>
      <div class="card"><div class="muted">回答率</div><div class="stat"><?=$rate?>%</div></div>
    </div>

    <div class="card">
      <h2>回答状況</h2>
      <p>未登録回答数：<?=$unregistered?></p>
      <p>未回答数：<?=$unanswered?></p>
    </div>

    <?php if(!$answers): ?>
      <div class="card">現在、回答データはありません</div>
    <?php else: ?>
      <?php foreach(allQuestions($survey) as $q):
        $counts=[];
        foreach($answers as $a) {
          $v=$a['values'][$q['id']]??'';
          foreach(is_array($v)?$v:[$v] as $x) {
            if((string)$x!=='') $counts[(string)$x]=($counts[(string)$x]??0)+1;
          }
        }
      ?>
      <div class="card">
        <h2><?=h($q['number'])?> <?=h($q['text'])?></h2>
        <?php if(!$counts): ?><p class="muted">回答なし</p>
        <?php else: foreach($counts as $label=>$count): ?>
          <p><?=h($label)?>：<strong><?=$count?></strong></p>
        <?php endforeach; endif ?>
      </div>
      <?php endforeach ?>

      <div class="card">
        <h2>個別回答</h2>
        <div class="table-wrap"><table>
          <thead><tr><th>日時</th><th>顧客</th><th>組織</th><th>回答</th></tr></thead>
          <tbody>
          <?php foreach($answers as $a): ?>
            <tr>
              <td><?=h($a['date'])?></td>
              <td><?=h($a['customer'])?></td>
              <td><?=h($a['org'])?></td>
              <td>
              <?php foreach(allQuestions($survey) as $q):
                $v=$a['values'][$q['id']]??'';
              ?>
                <div><strong><?=h($q['number'])?></strong> <?=h(is_array($v)?implode(' / ',$v):(string)$v)?></div>
              <?php endforeach ?>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table></div>
      </div>
    <?php endif ?>
    <?php
    return (string)ob_get_clean();
}

/* ---------------------------------------------------------
 * 送信画面
 * --------------------------------------------------------- */

function renderSend(array $data, array $survey, ?array $result=null): string {
    $search=trim((string)($_GET['customerQ']??''));
    $customers=$data['customers'];

    if($search!==''){
        $customers=array_values(array_filter($customers,static fn($c)=>
            mb_stripos(($c['name']??'').' '.($c['org']??').' '.($c['email']??''),$search)!==false
        ));
    }

    $subject=(string)($_POST['subject']??($survey['title'].'のお願い'));
    $body=(string)($_POST['body']??"{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}");

    ob_start(); ?>
    <div class="toolbar">
      <div><h1>顧客選択・メール送信</h1><div class="muted">対象：<?=h($survey['title'])?></div></div>
      <a class="btn" href="?screen=list">一覧へ</a>
    </div>

    <?php if($result): ?>
      <div class="alert <?=$result['ok']?'success':'danger'?>">
        <strong><?=h($result['message'])?></strong>
        <?php if(!empty($result['detail'])):?><div class="detail"><?=h($result['detail'])?></div><?php endif?>
      </div>
    <?php endif?>

    <div class="card">
      <h2>メール内容</h2>
      <form method="post">
        <input type="hidden" name="action" value="send_mail">
        <input type="hidden" name="surveyId" value="<?=h($survey['id'])?>">
        <div class="field"><label>件名</label><input name="subject" value="<?=h($subject)?>" required></div>
        <div class="field"><label>本文</label><textarea name="body" required><?=h($body)?></textarea></div>
        <p class="muted">利用可能な変数：{顧客名} / {アンケートURL}</p>

        <label>顧客を選択</label>
        <div class="field">
          <input type="text" name="customerQ" value="<?=h($search)?>" placeholder="顧客名・組織・メール">
        </div>

        <div class="table-wrap">
        <table>
          <thead><tr><th></th><th>顧客</th><th>組織</th><th>メール</th><th>回答状況</th></tr></thead>
          <tbody>
          <?php foreach($customers as $c): ?>
            <tr>
              <td><input type="checkbox" name="customers[]" value="<?=h($c['id'])?>"></td>
              <td><?=h($c['name'])?></td>
              <td><?=h($c['org'])?></td>
              <td><?=h($c['email'])?></td>
              <td><?=h($c['answerStatus'])?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
        </div>

        <div class="actions" style="margin-top:16px">
          <button class="primary">一括送信</button>
          <button name="sendMode" value="remind">リマインド送信</button>
          <button name="sendMode" value="resend">再送</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>送信履歴</h2>
      <?php
      $hist=array_reverse(array_values(array_filter(
          $data['sendHistory'],
          static fn($x)=>($x['surveyId']??'')===$survey['id']
      )));
      ?>
      <?php if(!$hist): ?><p class="muted">送信履歴はありません。</p>
      <?php else: ?>
        <div class="table-wrap"><table>
          <thead><tr><th>日時</th><th>種別</th><th>件数</th><th>件名</th><th>実行者</th></tr></thead>
          <tbody>
          <?php foreach($hist as $h): ?>
            <tr>
              <td><?=h($h['date'])?></td><td><?=h($h['type'])?></td>
              <td><?=h($h['count'])?></td><td><?=h($h['subject'])?></td><td>管理者</td>
            </tr>
          <?php endforeach?>
          </tbody>
        </table></div>
      <?php endif?>
    </div>
    <?php
    return (string)ob_get_clean();
}

/* ---------------------------------------------------------
 * 送信処理
 * --------------------------------------------------------- */

function processSend(array &$data): ?array {
    if(postString('action')!=='send_mail') return null;

    $surveyId=postString('surveyId');
    $survey=surveyById($data,$surveyId);
    if(!$survey) throw new RuntimeException('アンケートが存在しません。');

    $ids=postArray('customers');
    if(!$ids) throw new InvalidArgumentException('送信する顧客を選択してください。');

    $subject=postString('subject');
    $body=postString('body');
    if($subject==='') throw new InvalidArgumentException('件名を入力してください。');
    if($body==='') throw new InvalidArgumentException('本文を入力してください。');

    $password=postString('password');
    if($password===''&&!empty($data['mailSettings']['password'])){
        $password=decryptSecret((string)$data['mailSettings']['password']);
    }

    $sent=0;
    $failed=0;
    $errors=[];
    $selected=[];

    foreach($data['customers'] as $c){
        if(!in_array((string)$c['id'],$ids,true)) continue;

        $selected[]=$c['name'];
        $url=answerUrl($surveyId,(string)$c['id']);

        $msg=str_replace(
            ['{顧客名}','{アンケートURL}'],
            [(string)$c['name'],$url],
            $body
        );

        $r=smtpSendOne(
            $data['mailSettings'],
            $password,
            (string)$c['email'],
            $subject,
            $msg
        );

        if($r['ok']){
            $sent++;
            foreach($data['customers'] as &$cc){
                if($cc['id']===$c['id']){
                    $cc['lastSent']=now();
                    $cc['sendCount']=(int)($cc['sendCount']??0)+1;
                    $cc['answerStatus']='sent';
                    break;
                }
            }
            unset($cc);
        }else{
            $failed++;
            $errors[]=$c['name'].'：'.$r['message'];
        }
    }

    $mode=postString('sendMode');
    $type=$mode==='remind'?'リマインド':($mode==='resend'?'再送':'一括送信');

    if($sent>0){
        $data['sendHistory'][]=[
            'id'=>uid('history'),
            'surveyId'=>$surveyId,
            'date'=>now(),
            'type'=>$type,
            'count'=>$sent,
            'subject'=>$subject,
            'executor'=>'管理者',
            'customers'=>$selected,
            'body'=>$body,
        ];
    }

    saveApp($data);

    return [
        'ok'=>$failed===0,
        'message'=>$sent.'件送信、'.$failed.'件失敗しました。',
        'detail'=>implode("\n",$errors),
    ];
}

/* ---------------------------------------------------------
 * kintone画面
 * --------------------------------------------------------- */

function renderKintone(array $data): string {
    $k=$data['kintone'];

    ob_start(); ?>
    <h1>kintone設定</h1>

    <div class="card">
      <form method="post">
        <input type="hidden" name="action" value="save_kintone">
        <div class="grid2">
          <div class="field"><label>サブドメイン</label><input name="subdomain" value="<?=h($k['subdomain'])?>" placeholder="example / example.cybozu.com"></div>
          <div class="field"><label>顧客管理アプリID</label><input name="appId" value="<?=h($k['appId'])?>"></div>
          <div class="field"><label>ログイン名</label><input name="username" value="<?=h($k['username'])?>"></div>
          <div class="field"><label>Proxy</label><input name="proxy" value="<?=h($k['proxy'])?>" placeholder="host:port"></div>
        </div>
        <div class="field">
          <label>SSL証明書検証</label>
          <select name="sslVerify">
            <option value="1" <?=$k['sslVerify']?'selected':''?>>有効</option>
            <option value="0" <?=!$k['sslVerify']?'selected':''?>>無効（POC）</option>
          </select>
        </div>
        <div class="field"><label>パスワード</label><input type="password" name="password" autocomplete="new-password"></div>
        <button class="primary">設定保存</button>
      </form>
    </div>

    <div class="card">
      <h2>接続テスト</h2>
      <p>状態：<strong><?=h($k['connection'])?></strong></p>
      <?php if($k['connectionDetail']!==''): ?><p class="muted"><?=h($k['connectionDetail'])?></p><?php endif?>
      <form method="post">
        <input type="hidden" name="action" value="test_kintone">
        <div class="field"><label>パスワード</label><input type="password" name="password" autocomplete="off"></div>
        <button>接続テスト</button>
      </form>
    </div>

    <div class="card">
      <h2>項目一覧</h2>
      <form method="post">
        <input type="hidden" name="action" value="fetch_kintone_fields">
        <div class="field"><label>パスワード</label><input type="password" name="password" autocomplete="off"></div>
        <button>項目一覧再取得</button>
      </form>

      <?php if($k['fields']): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>フィールドコード</th><th>ラベル</th><th>型</th></tr></thead>
        <tbody>
        <?php foreach($k['fields'] as $code=>$f): ?>
          <tr><td><?=h($code)?></td><td><?=h($f['label'])?></td><td><?=h($f['type'])?></td></tr>
        <?php endforeach?>
        </tbody>
      </table></div>
      <?php endif?>
    </div>

    <div class="card">
      <h2>顧客情報マッピング</h2>
      <form method="post">
        <input type="hidden" name="action" value="save_kintone_mapping">
        <?php
        $map=$k['mappings'];
        foreach(['org'=>'組織名','name'=>'氏名','email'=>'メールアドレス','department'=>'部署名','phone'=>'電話番号'] as $key=>$label):
        ?>
        <div class="field">
          <label><?=h($label)?></label>
          <select name="map_<?=h($key)?>">
            <option value="">未設定</option>
            <?php foreach($k['fields'] as $code=>$f): ?>
              <option value="<?=h($code)?>" <?=$map[$key]===$code?'selected':''?>><?=h($code.' / '.$f['label'])?></option>
            <?php endforeach?>
          </select>
        </div>
        <?php endforeach?>

        <div class="field">
          <label>住所（複数可）</label>
          <?php foreach($k['fields'] as $code=>$f): ?>
            <label style="font-weight:400">
              <input type="checkbox" name="map_address[]" value="<?=h($code)?>" <?=in_array($code,$map['address']??[],true)?'checked':''?> style="width:auto">
              <?=h($code.' / '.$f['label'])?>
            </label>
          <?php endforeach?>
        </div>
        <button class="primary">マッピング保存</button>
      </form>
    </div>

    <div class="card">
      <h2>顧客情報同期</h2>
      <p class="muted">接続テスト・項目取得とは独立した操作です。</p>
      <form method="post">
        <input type="hidden" name="action" value="sync_kintone">
        <div class="field"><label>パスワード</label><input type="password" name="password" autocomplete="off"></div>
        <button>顧客情報同期</button>
      </form>
      <?php if($k['syncedAt']!==''): ?><p class="muted">最終同期：<?=h($k['syncedAt'])?></p><?php endif?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function renderMail(array $data): string {
    $m=$data['mailSettings'];

    ob_start(); ?>
    <h1>メールサーバ設定</h1>

    <div class="card">
      <form method="post">
        <input type="hidden" name="action" value="save_mail">
        <div class="grid2">
          <div class="field"><label>SMTPサーバ</label><input name="smtp" value="<?=h($m['smtp'])?>"></div>
          <div class="field"><label>SMTPポート</label><input type="number" min="1" max="65535" name="port" value="<?=h($m['port'])?>"></div>
          <div class="field"><label>暗号化方式</label>
            <select name="encryption">
              <option value="SSL" <?=$m['encryption']==='SSL'?'selected':''?>>SSL</option>
              <option value="TLS" <?=$m['encryption']==='TLS'?'selected':''?>>TLS</option>
              <option value="NONE" <?=$m['encryption']==='NONE'?'selected':''?>>なし</option>
            </select>
          </div>
          <div class="field"><label>SMTP認証</label>
            <select name="auth"><option value="1" <?=$m['auth']?'selected':''?>>あり</option><option value="0" <?=!$m['auth']?'selected':''?>>なし</option></select>
          </div>
          <div class="field"><label>SMTPユーザー名</label><input name="username" value="<?=h($m['username'])?>"></div>
          <div class="field"><label>送信元メールアドレス</label><input type="email" name="from" value="<?=h($m['from'])?>"></div>
          <div class="field"><label>送信元名</label><input name="fromName" value="<?=h($m['fromName'])?>"></div>
          <div class="field"><label>返信先メールアドレス</label><input type="email" name="replyTo" value="<?=h($m['replyTo'])?>"></div>
        </div>
        <div class="field"><label>SMTPパスワード</label><input type="password" name="password" autocomplete="new-password"></div>
        <button class="primary">設定保存</button>
      </form>
    </div>

    <div class="card">
      <h2>接続テスト</h2>
      <p>状態：<strong><?=h($m['connection'])?></strong></p>
      <form method="post">
        <input type="hidden" name="action" value="test_mail_connection">
        <div class="field"><label>SMTPパスワード</label><input type="password" name="password" autocomplete="off"></div>
        <button>接続テスト</button>
      </form>
    </div>

    <div class="card">
      <h2>テストメール送信</h2>
      <form method="post">
        <input type="hidden" name="action" value="test_mail_send">
        <div class="field"><label>テスト送信先</label><input type="email" name="testTo" required></div>
        <div class="field"><label>SMTPパスワード</label><input type="password" name="password" autocomplete="off"></div>
        <button>テストメール送信</button>
      </form>
    </div>
    <?php
    return (string)ob_get_clean();
}

/* ---------------------------------------------------------
 * メインディスパッチ
 * --------------------------------------------------------- */

try {
    ensureDataDir();

    $data=loadApp();
    $GLOBALS['__app']=$data;

    updateAutomaticStatuses($data);

    $screen=(string)($_GET['screen']??'list');

    /* 回答者画面を管理画面から完全分離 */
    if(in_array($screen,['answer','confirm','complete'],true)) {
        if($_SERVER['REQUEST_METHOD']==='POST'){
            handlePost($data);
            handleMailPost($data);
        }

        $id=(string)($_GET['id']??'');
        if(!validateId($id)){
            http_response_code(400);
            echo layout('エラー','<div class="answer-card"><div class="card"><h1>アンケートを指定してください</h1></div></div>',false);
            exit;
        }

        $survey=surveyById($data,$id);
        if(!$survey){
            http_response_code(404);
            echo layout('エラー','<div class="answer-card"><div class="card"><h1>アンケートが存在しません</h1></div></div>',false);
            exit;
        }

        if($screen!=='complete'&&!surveyAvailableForAnswer($survey)){
            echo layout('回答できません','<div class="answer-card"><div class="card"><h1>現在、回答できません</h1><p>公開期間またはステータスを確認してください。</p></div></div>',false);
            exit;
        }

        if($screen==='answer'){
            $errors=$_SESSION['answer_errors']??[];
            unset($_SESSION['answer_errors']);
            echo layout($survey['title'],renderAnswer($survey,is_array($errors)?$errors:[]),false);
            exit;
        }

        if($screen==='confirm'){
            echo layout('回答確認',renderConfirm($survey),false);
            exit;
        }

        echo layout('回答完了',renderComplete($survey),false);
        exit;
    }

    /* 外部通信処理はここで完了してから画面描画 */
    if($_SERVER['REQUEST_METHOD']==='POST'){
        handlePost($data);
        handleMailPost($data);
    }

    /* 再読み込み後の最新データ */
    $GLOBALS['__app']=$data;

    if(isset($_GET['export'])&&in_array($_GET['export'],['csv','pdf'],true)){
        $id=(string)($_GET['id']??'');
        if(!validateId($id)){
            http_response_code(400);
            exit('対象アンケートを指定してください。');
        }
        if($_GET['export']==='csv') outputCsv($data,$id);
        outputPdf($data,$id);
    }

    if($screen==='list'){
        echo layout('アンケート一覧',renderList($data),true,takeFlash());
        exit;
    }

    if($screen==='edit'){
        $id=(string)($_GET['id']??'');
        $survey=$id!==''?surveyById($data,$id):null;
        if($id!==''&&!$survey){
            http_response_code(404);
            echo layout('エラー','<div class="card"><h1>アンケートが存在しません。</h1></div>');
            exit;
        }
        echo layout('アンケート編集',renderEdit($data,$survey),true,takeFlash());
        exit;
    }

    if($screen==='preview'){
        $id=(string)($_GET['id']??'');
        $survey=surveyById($data,$id);
        if(!$survey) {
            http_response_code(404);
            echo layout('エラー','<div class="card"><h1>アンケートが存在しません。</h1></div>');
            exit;
        }
        echo layout('プレビュー',renderPreview($survey),true,takeFlash());
        exit;
    }

    if($screen==='analytics'){
        $id=(string)($_GET['id']??'');
        if(!validateId($id)){
            http_response_code(400);
            echo layout('エラー','<div class="card"><h1>対象アンケートを指定してください。</h1></div>');
            exit;
        }
        $survey=surveyById($data,$id);
        if(!$survey){
            http_response_code(404);
            echo layout('エラー','<div class="card"><h1>アンケートが存在しません。</h1></div>');
            exit;
        }
        echo layout('回答集計・分析',renderAnalytics($data,$survey),true,takeFlash());
        exit;
    }

    if($screen==='send'){
        $id=(string)($_GET['id']??'');
        if(!validateId($id)){
            http_response_code(400);
            echo layout('エラー','<div class="card"><h1>対象アンケートを指定してください。</h1></div>');
            exit;
        }
        $survey=surveyById($data,$id);
        if(!$survey){
            http_response_code(404);
            echo layout('エラー','<div class="card"><h1>アンケートが存在しません。</h1></div>');
            exit;
        }

        $result=null;
        if($_SERVER['REQUEST_METHOD']==='POST'){
            try {
                $result=processSend($data);
                $GLOBALS['__app']=$data;
            } catch(Throwable $e) {
                $result=['ok'=>false,'message'=>'メール送信処理に失敗しました。','detail'=>$e->getMessage()];
            }
        }

        echo layout('顧客選択・メール送信',renderSend($data,$survey,$result),true,takeFlash());
        exit;
    }

    if($screen==='kintone'){
        echo layout('kintone設定',renderKintone($data),true,takeFlash());
        exit;
    }

    if($screen==='mail'){
        echo layout('メールサーバ設定',renderMail($data),true,takeFlash());
        exit;
    }

    echo layout(
        'エラー',
        '<div class="card"><h1>画面が見つかりません</h1><p>指定されたscreenは利用できません。</p></div>',
        true,
        takeFlash()
    );
} catch(Throwable $e) {
    /* エラー表示処理自身が例外を起こさないよう固定文言を中心にする */
    error_log('SurveyPOC error: ' . $e->getMessage());

    http_response_code(500);

    $detail = '';
    if(ini_get('display_errors')) $detail = $e->getMessage();

    echo layout(
        '処理エラー',
        '<div class="card"><h1>処理を完了できませんでした</h1>' .
        '<p>入力値、設定値、外部サービスの接続状態を確認して再度お試しください。</p>' .
        ($detail !== '' ? '<pre>' . h($detail) . '</pre>' : '') .
        '</div>',
        true
    );
}


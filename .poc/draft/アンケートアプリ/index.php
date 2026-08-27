<?php
declare(strict_types=1);

/**
 * アンケートアプリ POC
 *
 * 実行環境:
 *   Apache 2.4
 *   PHP 8.5
 *   DBなし
 *   PHP cURLなし
 *
 * POCでは以下を実装しない:
 *   - CSRF
 *   - 管理者ログイン
 *   - 初回セットアップ
 *   - データ保存先設定
 *
 * データ:
 *   index.php と同じディレクトリへ保存する。
 *
 * 外部サービス:
 *   - kintone: 実サービスへ接続
 *   - SMTP: 実サービスへ接続
 *
 * 注意:
 *   POC用実装。
 *   本番化時には認証、CSRF、保存領域保護等を再設計する。
 */

const APP_NAME = 'アンケートアプリ';

const SCREEN_LIST      = 'list';
const SCREEN_EDIT      = 'edit';
const SCREEN_PREVIEW   = 'preview';
const SCREEN_SEND      = 'send';
const SCREEN_ANALYTICS = 'analytics';
const SCREEN_KINTONE   = 'kintone';
const SCREEN_MAIL      = 'mail';
const SCREEN_ANSWER    = 'answer';
const SCREEN_CONFIRM   = 'confirm';
const SCREEN_COMPLETE  = 'complete';

const STATUS_DRAFT    = 'draft';
const STATUS_OPEN     = 'open';
const STATUS_STOPPED  = 'stopped';
const STATUS_FINISHED = 'finished';

const ANSWER_SINGLE = 'single';
const ANSWER_MULTI  = 'multi';
const ANSWER_TEXT   = 'text';

/* =========================================================
 * 1. 基本
 * ========================================================= */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function get(string $name, mixed $default = null): mixed
{
    return $_GET[$name] ?? $default;
}

function post(string $name, mixed $default = null): mixed
{
    return $_POST[$name] ?? $default;
}

function requestMethod(): string
{
    return strtoupper(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
    );
}

function action(): string
{
    /*
     * POSTを優先。
     *
     * 今回のブラウザリクエスト:
     *
     *   ?screen=kintone
     *   POST:
     *     screen=kintone
     *     action=test_kintone
     *
     * の両方に対応する。
     */
    return trim((string)(
        $_POST['action']
        ?? $_GET['action']
        ?? ''
    ));
}

function screen(): string
{
    return trim((string)(
        $_POST['screen']
        ?? $_GET['screen']
        ?? SCREEN_LIST
    ));
}

function appUrl(array $params = []): string
{
    $base = 'index.php';

    if (!$params) {
        return $base;
    }

    return $base . '?' . http_build_query($params);
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/* =========================================================
 * 2. データ保存
 * ========================================================= */

/*
 * POCではindex.phpと同じディレクトリ。
 *
 * 保存先を環境変数や初期設定画面から受け取らない。
 */
function dataFile(string $name): string
{
    $allowed = [
        'surveys.json',
        'answers.json',
        'customers.json',
        'mail_logs.json',
        'settings.json',
    ];

    if (!in_array($name, $allowed, true)) {
        throw new InvalidArgumentException(
            '不正なデータファイルです。'
        );
    }

    return __DIR__ . DIRECTORY_SEPARATOR . $name;
}

function readJson(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }

    $fp = fopen($file, 'rb');

    if ($fp === false) {
        throw new RuntimeException(
            'データファイルを開けません。'
        );
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException(
                'データファイルをロックできません。'
            );
        }

        $contents = stream_get_contents($fp);

        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $decoded = json_decode(
        $contents,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    return is_array($decoded)
        ? $decoded
        : $default;
}

function writeJson(string $file, array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
    );

    $tmp =
        $file
        . '.tmp.'
        . bin2hex(random_bytes(8));

    if (
        file_put_contents(
            $tmp,
            $json,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            'データを保存できません。'
        );
    }

    if (!rename($tmp, $file)) {
        @unlink($tmp);

        throw new RuntimeException(
            'データ保存を確定できません。'
        );
    }
}

function ensureDataFiles(): void
{
    $files = [
        'surveys.json',
        'answers.json',
        'customers.json',
        'mail_logs.json',
        'settings.json',
    ];

    foreach ($files as $file) {
        $path = dataFile($file);

        if (!is_file($path)) {
            writeJson($path, []);
        }
    }
}

function surveys(): array
{
    return readJson(
        dataFile('surveys.json'),
        []
    );
}

function saveSurveys(array $items): void
{
    writeJson(
        dataFile('surveys.json'),
        array_values($items)
    );
}

function answers(): array
{
    return readJson(
        dataFile('answers.json'),
        []
    );
}

function saveAnswers(array $items): void
{
    writeJson(
        dataFile('answers.json'),
        array_values($items)
    );
}

function customers(): array
{
    return readJson(
        dataFile('customers.json'),
        []
    );
}

function saveCustomers(array $items): void
{
    writeJson(
        dataFile('customers.json'),
        array_values($items)
    );
}

function mailLogs(): array
{
    return readJson(
        dataFile('mail_logs.json'),
        []
    );
}

function saveMailLogs(array $items): void
{
    writeJson(
        dataFile('mail_logs.json'),
        array_values($items)
    );
}

function settings(): array
{
    return readJson(
        dataFile('settings.json'),
        []
    );
}

function saveSettings(array $items): void
{
    writeJson(
        dataFile('settings.json'),
        $items
    );
}

/* =========================================================
 * 3. セッション
 *
 * CSRF・管理者認証には使用しない。
 * 回答途中の状態保持だけに使用する。
 * ========================================================= */

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

startSession();

/* =========================================================
 * 4. ID
 * ========================================================= */

function makeId(string $prefix): string
{
    return $prefix
        . '_'
        . date('YmdHis')
        . '_'
        . bin2hex(random_bytes(5));
}

/* =========================================================
 * 5. アンケート
 * ========================================================= */

function emptySurvey(): array
{
    return [
        'id' => makeId('survey'),
        'title' => '',
        'description' => '',
        'created_at' => now(),
        'updated_at' => now(),
        'start_at' => '',
        'end_at' => '',
        'status' => STATUS_DRAFT,
        'numbering' => 'global',
        'groups' => [
            [
                'id' => makeId('group'),
                'title' => '基本情報',
                'questions' => [],
            ],
        ],
    ];
}

function effectiveStatus(array $survey): string
{
    $status = (string)(
        $survey['status']
        ?? STATUS_DRAFT
    );

    if (
        $status === STATUS_OPEN
        && !empty($survey['end_at'])
    ) {
        $end = strtotime(
            (string)$survey['end_at']
        );

        if (
            $end !== false
            && $end < time()
        ) {
            return STATUS_FINISHED;
        }
    }

    return $status;
}

function statusLabel(string $status): string
{
    return match ($status) {
        STATUS_DRAFT => '下書き',
        STATUS_OPEN => '公開中',
        STATUS_STOPPED => '停止',
        STATUS_FINISHED => '終了',
        default => '不明',
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        STATUS_OPEN => 'badge-success',
        STATUS_STOPPED => 'badge-warning',
        STATUS_FINISHED => 'badge-danger',
        default => 'badge-draft',
    };
}

function findSurvey(string $id): ?array
{
    foreach (surveys() as $survey) {
        if (($survey['id'] ?? '') === $id) {
            $survey['status'] =
                effectiveStatus($survey);

            return $survey;
        }
    }

    return null;
}

function renumberSurvey(array &$survey): void
{
    $mode =
        $survey['numbering']
        ?? 'global';

    $global = 0;

    foreach (
        $survey['groups']
        as $gi => &$group
    ) {
        $groupNo = $gi + 1;
        $questionNo = 0;

        foreach (
            $group['questions']
            as &$question
        ) {
            $global++;
            $questionNo++;

            $question['number'] =
                $mode === 'group'
                    ? 'Q' . $groupNo . '-' . $questionNo
                    : 'Q' . $global;
        }

        unset($question);
    }

    unset($group);
}

/* =========================================================
 * 6. 入力検証
 * ========================================================= */

function parseQuestionPayload(): array
{
    $raw = (string)post(
        'questions_json',
        '[]'
    );

    $decoded = json_decode(
        $raw,
        true
    );

    if (!is_array($decoded)) {
        throw new InvalidArgumentException(
            '質問データが不正です。'
        );
    }

    return $decoded;
}

function normalizeQuestions(
    array $groups
): array {
    $result = [];

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $groupId =
            trim((string)(
                $group['id'] ?? ''
            ));

        if ($groupId === '') {
            $groupId = makeId('group');
        }

        $groupTitle =
            trim((string)(
                $group['title'] ?? ''
            ));

        $questions = [];

        foreach (
            ($group['questions'] ?? [])
            as $question
        ) {
            if (!is_array($question)) {
                continue;
            }

            $text =
                trim((string)(
                    $question['text'] ?? ''
                ));

            if ($text === '') {
                continue;
            }

            $qid =
                trim((string)(
                    $question['id'] ?? ''
                ));

            if ($qid === '') {
                $qid = makeId('question');
            }

            $type =
                (string)(
                    $question['type']
                    ?? ANSWER_SINGLE
                );

            if (!in_array(
                $type,
                [
                    ANSWER_SINGLE,
                    ANSWER_MULTI,
                    ANSWER_TEXT,
                ],
                true
            )) {
                $type = ANSWER_SINGLE;
            }

            $choices = [];

            foreach (
                ($question['choices'] ?? [])
                as $choice
            ) {
                $choice = trim((string)$choice);

                if ($choice !== '') {
                    $choices[] = $choice;
                }
            }

            $questions[] = [
                'id' => $qid,
                'number' => '',
                'text' => $text,
                'type' => $type,
                'required' =>
                    !empty($question['required']),
                'choices' => $choices,
                'branching' =>
                    is_array(
                        $question['branching']
                        ?? null
                    )
                        ? $question['branching']
                        : [],
            ];
        }

        $result[] = [
            'id' => $groupId,
            'title' => $groupTitle,
            'questions' => $questions,
        ];
    }

    return $result;
}

/* =========================================================
 * 7. kintone HTTP
 *
 * PHP cURLを使用しない。
 * ========================================================= */

function validateKintoneConfig(
    array $config
): array {
    $subdomain =
        trim((string)(
            $config['subdomain'] ?? ''
        ));

    $appId =
        (int)(
            $config['app_id'] ?? 0
        );

    $username =
        trim((string)(
            $config['username'] ?? ''
        ));

    $password =
        (string)(
            $config['password'] ?? ''
        );

    if ($subdomain === '') {
        throw new InvalidArgumentException(
            'kintoneのサブドメインを指定してください。'
        );
    }

    /*
     * URLインジェクション防止。
     */
    if (!preg_match(
        '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
        $subdomain
    )) {
        throw new InvalidArgumentException(
            'kintoneのサブドメインが不正です。'
        );
    }

    if ($appId <= 0) {
        throw new InvalidArgumentException(
            'kintoneのアプリIDを指定してください。'
        );
    }

    if ($username === '') {
        throw new InvalidArgumentException(
            'kintoneのログイン名を指定してください。'
        );
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneのパスワードを指定してください。'
        );
    }

    $proxy =
        trim((string)(
            $config['proxy'] ?? ''
        ));

    if (
        $proxy !== ''
        && !preg_match(
            '/^[^:\s]+:\d{1,5}$/',
            $proxy
        )
    ) {
        throw new InvalidArgumentException(
            'Proxyは host:port 形式で指定してください。'
        );
    }

    return [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'password' => $password,
        'proxy' => $proxy,
        'verify_ssl' =>
            !isset($config['verify_ssl'])
            || !empty($config['verify_ssl']),
    ];
}

function kintoneRequest(
    string $method,
    string $path,
    array $config,
    ?array $body = null
): array {
    $config =
        validateKintoneConfig($config);

    $url =
        'https://'
        . $config['subdomain']
        . '.kintone.com'
        . $path;

    /*
     * kintoneのパスワード認証。
     *
     * X-Cybozu-Authorization:
     * Base64(login_name:password)
     *
     * 認証情報はレスポンス・HTML・URLには出さない。
     */
    $authorization = base64_encode(
        $config['username']
        . ':'
        . $config['password']
    );

    $headers = [
        'X-Cybozu-Authorization: '
            . $authorization,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $options = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers),
        'timeout' => 15,
        'ignore_errors' => true,
    ];

    if ($config['verify_ssl']) {
        $options['ssl'] = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ];
    } else {
        /*
         * POC設定としてOFFを許容する。
         * 本番ではOFFにしないこと。
         */
        $options['ssl'] = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];
    }

    if ($config['proxy'] !== '') {
        $options['proxy'] =
            'tcp://'
            . $config['proxy'];

        $options['request_fulluri'] = true;
    }

    if ($body !== null) {
        $options['content'] =
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
    }

    $context = stream_context_create([
        'http' => $options,
    ]);

    $response =
        @file_get_contents(
            $url,
            false,
            $context
        );

    $status = 0;

    foreach (
        ($http_response_header ?? [])
        as $header
    ) {
        if (preg_match(
            '#^HTTP/\S+\s+(\d+)#',
            $header,
            $m
        )) {
            $status = (int)$m[1];
            break;
        }
    }

    if ($response === false) {
        throw new RuntimeException(
            'kintoneサーバーへ接続できません。'
        );
    }

    $decoded = json_decode(
        $response,
        true
    );

    if (!is_array($decoded)) {
        $decoded = [
            'raw' => $response,
        ];
    }

    return [
        'status' => $status,
        'body' => $decoded,
    ];
}

/* =========================================================
 * 8. kintone設定
 * ========================================================= */

function saveKintoneConfig(): void
{
    $current = settings();

    $old =
        is_array($current['kintone'] ?? null)
            ? $current['kintone']
            : [];

    $password =
        (string)post(
            'password',
            ''
        );

    /*
     * パスワード空欄なら既存値を維持。
     */
    $current['kintone'] = [
        'subdomain' =>
            trim((string)post(
                'subdomain',
                ''
            )),
        'app_id' =>
            (int)post(
                'app_id',
                0
            ),
        'username' =>
            trim((string)post(
                'username',
                ''
            )),
        'password' =>
            $password !== ''
                ? $password
                : ($old['password'] ?? ''),
        'proxy' =>
            trim((string)post(
                'proxy',
                ''
            )),
        'verify_ssl' =>
            post(
                'verify_ssl',
                ''
            ) === '1',
        'mapping' =>
            is_array(
                post('mapping', null)
            )
                ? post('mapping')
                : (
                    is_array(
                        $old['mapping'] ?? null
                    )
                        ? $old['mapping']
                        : []
                ),
    ];

    validateKintoneConfig(
        $current['kintone']
    );

    saveSettings($current);
}

/* =========================================================
 * 9. kintone 接続テスト
 *
 * 今回の500対策の中心。
 * action=test_kintone を正式に処理する。
 * ========================================================= */

function testKintone(): string
{
    $config =
        settings()['kintone']
        ?? [];

    /*
     * 保存済み設定を使用。
     */
    validateKintoneConfig($config);

    $result = kintoneRequest(
        'GET',
        '/k/v1/app.json?'
        . http_build_query([
            'id' => (int)$config['app_id'],
        ]),
        $config
    );

    $status = $result['status'];

    if ($status >= 200 && $status < 300) {
        return 'kintoneへの接続に成功しました。';
    }

    $message =
        (string)(
            $result['body']['message']
            ?? ''
        );

    /*
     * kintoneのエラーメッセージに
     * 認証情報が混入することは通常ないが、
     * 念のため限定的な表示にする。
     */
    return match (true) {
        $status === 401 =>
            'kintone認証に失敗しました。ログイン名・パスワードを確認してください。',
        $status === 403 =>
            'kintone APIへの権限がありません。',
        $status === 404 =>
            '指定されたkintoneアプリが見つかりません。',
        $status >= 500 =>
            'kintoneサーバーでエラーが発生しました。'
            . ($message !== ''
                ? '（kintone: ' . e($message) . '）'
                : ''),
        default =>
            'kintone接続に失敗しました。HTTPステータス: '
            . $status,
    };
}

/* =========================================================
 * 10. kintone フィールド取得
 * ========================================================= */

function getKintoneFields(): array
{
    $config =
        settings()['kintone']
        ?? [];

    validateKintoneConfig($config);

    $result = kintoneRequest(
        'GET',
        '/k/v1/app/form/fields.json?'
        . http_build_query([
            'app' => (int)$config['app_id'],
        ]),
        $config
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {
        throw new RuntimeException(
            'kintone項目一覧を取得できませんでした。'
            . ' HTTP '
            . $result['status']
        );
    }

    return is_array(
        $result['body']['properties']
        ?? null
    )
        ? $result['body']['properties']
        : [];
}

/* =========================================================
 * 11. kintone 顧客同期
 * ========================================================= */

function kintoneFieldValue(
    array $record,
    string $code
): string {
    if ($code === '') {
        return '';
    }

    return (string)(
        $record[$code]['value']
        ?? ''
    );
}

function syncCustomers(): int
{
    $settingsAll = settings();

    $config =
        $settingsAll['kintone']
        ?? [];

    validateKintoneConfig($config);

    $mapping =
        is_array($config['mapping'] ?? null)
            ? $config['mapping']
            : [];

    $fields = [
        'organization' =>
            trim((string)(
                $mapping['organization'] ?? ''
            )),
        'name' =>
            trim((string)(
                $mapping['name'] ?? ''
            )),
        'email' =>
            trim((string)(
                $mapping['email'] ?? ''
            )),
        'department' =>
            trim((string)(
                $mapping['department'] ?? ''
            )),
        'phone' =>
            trim((string)(
                $mapping['phone'] ?? ''
            )),
        'address' =>
            is_array(
                $mapping['address'] ?? null
            )
                ? $mapping['address']
                : [],
    ];

    $query = [
        'app' =>
            (int)$config['app_id'],
        'totalCount' => 'true',
        'limit' => 500,
        'offset' => 0,
    ];

    $all = [];

    do {
        $result = kintoneRequest(
            'GET',
            '/k/v1/records.json?'
            . http_build_query($query),
            $config
        );

        if (
            $result['status'] < 200
            || $result['status'] >= 300
        ) {
            throw new RuntimeException(
                'kintone顧客情報を取得できませんでした。'
                . ' HTTP '
                . $result['status']
            );
        }

        $records =
            $result['body']['records']
            ?? [];

        if (!is_array($records)) {
            $records = [];
        }

        foreach ($records as $record) {
            $addressParts = [];

            foreach ($fields['address'] as $code) {
                $code = trim((string)$code);

                if ($code === '') {
                    continue;
                }

                $value =
                    kintoneFieldValue(
                        $record,
                        $code
                    );

                if ($value !== '') {
                    $addressParts[] = $value;
                }
            }

            $all[] = [
                'id' =>
                    kintoneFieldValue(
                        $record,
                        '$id'
                    ),
                'organization' =>
                    kintoneFieldValue(
                        $record,
                        $fields['organization']
                    ),
                'name' =>
                    kintoneFieldValue(
                        $record,
                        $fields['name']
                    ),
                'email' =>
                    kintoneFieldValue(
                        $record,
                        $fields['email']
                    ),
                'department' =>
                    kintoneFieldValue(
                        $record,
                        $fields['department']
                    ),
                'phone' =>
                    kintoneFieldValue(
                        $record,
                        $fields['phone']
                    ),
                'address' =>
                    implode(' ', $addressParts),
                'synced_at' => now(),
            ];
        }

        $count = count($records);

        $query['offset'] += $count;

        if ($count < 500) {
            break;
        }
    } while (true);

    saveCustomers($all);

    return count($all);
}

/* =========================================================
 * 12. SMTP
 *
 * PHP cURL / mail() に依存しない。
 * TCPソケットでSMTPを実装する。
 * ========================================================= */

final class SimpleSmtp
{
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly bool $auth,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 15
    ) {
    }

    private function readResponse(): array
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException(
                'SMTP接続がありません。'
            );
        }

        $lines = [];

        while (($line = fgets(
            $this->socket,
            8192
        )) !== false) {
            $lines[] = rtrim(
                $line,
                "\r\n"
            );

            /*
             * SMTP multiline response:
             *
             * 250-xxx
             * 250-xxx
             * 250 xxx
             */
            if (preg_match(
                '/^\d{3}\s/',
                $line
            )) {
                break;
            }

            if (count($lines) > 30) {
                break;
            }
        }

        if (!$lines) {
            throw new RuntimeException(
                'SMTPサーバーから応答がありません。'
            );
        }

        $last = end($lines);

        $code = (int)substr(
            $last,
            0,
            3
        );

        return [
            'code' => $code,
            'lines' => $lines,
        ];
    }

    private function command(
        string $command,
        array $expected
    ): array {
        if (!is_resource($this->socket)) {
            throw new RuntimeException(
                'SMTP接続がありません。'
            );
        }

        fwrite(
            $this->socket,
            $command . "\r\n"
        );

        $response =
            $this->readResponse();

        if (
            !in_array(
                $response['code'],
                $expected,
                true
            )
        ) {
            throw new RuntimeException(
                'SMTPエラー: '
                . implode(
                    ' / ',
                    $response['lines']
                )
            );
        }

        return $response;
    }

    public function connect(): void
    {
        $target =
            match ($this->encryption) {
                'ssl' =>
                    'ssl://' . $this->host
                    . ':' . $this->port,
                default =>
                    'tcp://' . $this->host
                    . ':' . $this->port,
            };

        $errno = 0;
        $errstr = '';

        $this->socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($this->socket)) {
            throw new RuntimeException(
                'SMTPサーバーへ接続できません。'
                . ($errstr !== ''
                    ? ' ' . $errstr
                    : '')
            );
        }

        stream_set_timeout(
            $this->socket,
            $this->timeout
        );

        $greeting =
            $this->readResponse();

        if (
            $greeting['code'] < 200
            || $greeting['code'] >= 400
        ) {
            throw new RuntimeException(
                'SMTPエラー: '
                . implode(
                    ' / ',
                    $greeting['lines']
                )
            );
        }

        $this->command(
            'EHLO localhost',
            [250]
        );

        if (
            $this->encryption === 'tls'
        ) {
            $this->command(
                'STARTTLS',
                [220]
            );

            $crypto = stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'SMTP STARTTLSを開始できません。'
                );
            }

            $this->command(
                'EHLO localhost',
                [250]
            );
        }

        if ($this->auth) {
            $this->authenticate();
        }
    }

    private function authenticate(): void
    {
        /*
         * AUTH PLAINを優先。
         *
         * サーバーによってはLOGINしかないため、
         * EHLO応答を見ていない単純POCとして
         * AUTH LOGINを使用する。
         */
        $this->command(
            'AUTH LOGIN',
            [334]
        );

        $this->command(
            base64_encode(
                $this->username
            ),
            [334]
        );

        $this->command(
            base64_encode(
                $this->password
            ),
            [235]
        );
    }

    public function send(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $body,
        string $replyTo = ''
    ): void {
        $this->command(
            'MAIL FROM:<'
            . $fromEmail
            . '>',
            [250]
        );

        $this->command(
            'RCPT TO:<'
            . $toEmail
            . '>',
            [250, 251]
        );

        $this->command(
            'DATA',
            [354]
        );

        $headers = [];

        $headers[] =
            'From: '
            . self::encodeHeader(
                $fromName
            )
            . ' <'
            . $fromEmail
            . '>';

        $headers[] =
            'To: <'
            . $toEmail
            . '>';

        $headers[] =
            'Subject: '
            . self::encodeHeader(
                $subject
            );

        if ($replyTo !== '') {
            $headers[] =
                'Reply-To: <'
                . $replyTo
                . '>';
        }

        $headers[] =
            'MIME-Version: 1.0';

        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';

        $headers[] =
            'Content-Transfer-Encoding: 8bit';

        $data =
            implode(
                "\r\n",
                $headers
            )
            . "\r\n\r\n"
            . str_replace(
                "\r\n.",
                "\r\n..",
                str_replace(
                    "\r",
                    '',
                    $body
                )
            )
            . "\r\n.";

        fwrite(
            $this->socket,
            $data . "\r\n"
        );

        $response =
            $this->readResponse();

        if ($response['code'] !== 250) {
            throw new RuntimeException(
                'SMTPエラー: '
                . implode(
                    ' / ',
                    $response['lines']
                )
            );
        }
    }

    public function quit(): void
    {
        if (!is_resource($this->socket)) {
            return;
        }

        try {
            $this->command(
                'QUIT',
                [221]
            );
        } catch (Throwable) {
            /*
             * QUIT失敗は送信成功後なら
             * 本来の送信結果を覆さない。
             */
        }

        fclose($this->socket);
        $this->socket = null;
    }

    public function __destruct()
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    private static function encodeHeader(
        string $value
    ): string {
        if ($value === '') {
            return '';
        }

        return '=?UTF-8?B?'
            . base64_encode($value)
            . '?=';
    }
}

/* =========================================================
 * 13. SMTP設定
 * ========================================================= */

function validateMailConfig(
    array $config
): array {
    $host =
        trim((string)(
            $config['host'] ?? ''
        ));

    $port =
        (int)(
            $config['port'] ?? 587
        );

    $encryption =
        (string)(
            $config['encryption']
            ?? 'tls'
        );

    $auth =
        !empty($config['auth']);

    $username =
        trim((string)(
            $config['username'] ?? ''
        ));

    $password =
        (string)(
            $config['password'] ?? ''
        );

    $fromEmail =
        trim((string)(
            $config['from_email'] ?? ''
        ));

    if ($host === '') {
        throw new InvalidArgumentException(
            'SMTPサーバを指定してください。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (!in_array(
        $encryption,
        [
            'none',
            'tls',
            'ssl',
        ],
        true
    )) {
        throw new InvalidArgumentException(
            'SMTP暗号化方式が不正です。'
        );
    }

    if ($auth) {
        if ($username === '') {
            throw new InvalidArgumentException(
                'SMTPユーザー名を指定してください。'
            );
        }

        if ($password === '') {
            throw new InvalidArgumentException(
                'SMTPパスワードを指定してください。'
            );
        }
    }

    if (
        $fromEmail === ''
        || !filter_var(
            $fromEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    return [
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'password' => $password,
        'from_email' => $fromEmail,
        'from_name' =>
            trim((string)(
                $config['from_name'] ?? ''
            )),
        'reply_to' =>
            trim((string)(
                $config['reply_to'] ?? ''
            )),
    ];
}

function saveMailConfig(): void
{
    $all = settings();

    $old =
        is_array($all['mail'] ?? null)
            ? $all['mail']
            : [];

    $password =
        (string)post(
            'password',
            ''
        );

    $config = [
        'host' =>
            trim((string)post(
                'host',
                ''
            )),
        'port' =>
            (int)post(
                'port',
                587
            ),
        'encryption' =>
            (string)post(
                'encryption',
                'tls'
            ),
        'auth' =>
            post(
                'auth',
                ''
            ) === '1',
        'username' =>
            trim((string)post(
                'username',
                ''
            )),
        'password' =>
            $password !== ''
                ? $password
                : ($old['password'] ?? ''),
        'from_email' =>
            trim((string)post(
                'from_email',
                ''
            )),
        'from_name' =>
            trim((string)post(
                'from_name',
                ''
            )),
        'reply_to' =>
            trim((string)post(
                'reply_to',
                ''
            )),
    ];

    validateMailConfig($config);

    $all['mail'] = $config;

    saveSettings($all);
}

/* =========================================================
 * 14. SMTP接続テスト
 * ========================================================= */

function testSmtp(): string
{
    $config =
        settings()['mail']
        ?? [];

    $config =
        validateMailConfig($config);

    $smtp = new SimpleSmtp(
        $config['host'],
        $config['port'],
        $config['encryption'],
        $config['auth'],
        $config['username'],
        $config['password']
    );

    try {
        $smtp->connect();

        return 'SMTPサーバーへの接続・認証に成功しました。';
    } finally {
        $smtp->quit();
    }
}

/* =========================================================
 * 15. メール送信
 * ========================================================= */

function sendSmtpMail(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var(
        $to,
        FILTER_VALIDATE_EMAIL
    )) {
        throw new InvalidArgumentException(
            '送信先メールアドレスが不正です。'
        );
    }

    $config =
        validateMailConfig($config);

    $smtp = new SimpleSmtp(
        $config['host'],
        $config['port'],
        $config['encryption'],
        $config['auth'],
        $config['username'],
        $config['password']
    );

    try {
        $smtp->connect();

        $smtp->send(
            $config['from_email'],
            $config['from_name'],
            $to,
            $subject,
            $body,
            $config['reply_to']
        );
    } finally {
        $smtp->quit();
    }
}

/* =========================================================
 * 16. HTML共通
 * ========================================================= */

function renderHeader(
    string $title,
    bool $answerer = false
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title><?= e($title) ?> - <?= e(APP_NAME) ?></title>

<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --success: #16a34a;
    --warning: #d97706;
    --danger: #dc2626;
    --gray: #64748b;
    --gray-light: #f1f5f9;
    --border: #dbe2ea;
    --text: #1e293b;
    --white: #ffffff;
    --background: #f8fafc;
    --header: #0f172a;
    --shadow: 0 4px 18px rgba(15,23,42,.08);
    --radius: 10px;
}

* {
    box-sizing: border-box;
}

html {
    font-size: 16px;
}

body {
    margin: 0;
    background: var(--background);
    color: var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    line-height: 1.6;
}

a {
    color: var(--primary);
    text-decoration: none;
}

a:hover {
    text-decoration: underline;
}

button,
input,
select,
textarea {
    font: inherit;
}

button {
    cursor: pointer;
}

h1 {
    font-size: 1.65rem;
    margin: 0 0 6px;
}

h2 {
    font-size: 1.2rem;
    margin: 0 0 18px;
}

h3 {
    font-size: 1rem;
    margin: 0 0 12px;
}

.app-header {
    background: var(--header);
    color: var(--white);
    min-height: 64px;
    display: flex;
    align-items: center;
    padding: 0 24px;
}

.app-header .brand {
    color: var(--white);
    font-weight: 700;
    font-size: 1.1rem;
}

.app-header .nav {
    margin-left: auto;
    display: flex;
    gap: 8px;
    align-items: center;
}

.app-header .nav a {
    color: #cbd5e1;
    padding: 8px 12px;
    border-radius: 6px;
}

.app-header .nav a:hover,
.app-header .nav a.active {
    color: var(--white);
    background: rgba(255,255,255,.08);
    text-decoration: none;
}

.container {
    width: min(1200px, calc(100% - 32px));
    margin: 0 auto;
    padding: 28px 0 48px;
}

.answer-container {
    width: min(760px, calc(100% - 32px));
    margin: 0 auto;
    padding: 28px 0 48px;
}

.page-title {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 22px;
}

.card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 22px;
    margin-bottom: 20px;
}

.grid-2 {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 20px;
}

.grid-3 {
    display: grid;
    grid-template-columns:
        repeat(3,minmax(0,1fr));
    gap: 20px;
}

.form-group,
.form-row {
    margin-bottom: 16px;
}

.form-label,
.form-row > label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
}

.form-help,
.muted {
    color: var(--gray);
    font-size: .875rem;
}

input[type="text"],
input[type="search"],
input[type="email"],
input[type="password"],
input[type="number"],
input[type="datetime-local"],
select,
textarea {
    width: 100%;
    min-height: 42px;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: var(--white);
    color: var(--text);
    outline: none;
}

textarea {
    min-height: 140px;
    resize: vertical;
}

input:focus,
select:focus,
textarea:focus {
    border-color: var(--primary);
    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.12);
}

.checkbox {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.checkbox input {
    width: 17px;
    height: 17px;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 40px;
    padding: 8px 14px;
    border: 1px solid transparent;
    border-radius: 7px;
    font-weight: 600;
    line-height: 1.3;
    text-decoration: none;
    white-space: nowrap;
}

.btn:hover {
    text-decoration: none;
}

.btn-primary {
    background: var(--primary);
    color: var(--white);
}

.btn-primary:hover {
    background: var(--primary-dark);
}

.btn-secondary {
    background: var(--white);
    color: var(--text);
    border-color: var(--border);
}

.btn-secondary:hover {
    background: var(--gray-light);
}

.btn-success {
    background: var(--success);
    color: var(--white);
}

.btn-warning {
    background: var(--warning);
    color: var(--white);
}

.btn-danger {
    background: var(--danger);
    color: var(--white);
}

.btn-sm {
    min-height: 34px;
    padding: 6px 10px;
    font-size: .875rem;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: var(--white);
}

th,
td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: middle;
}

th {
    background: var(--gray-light);
    font-weight: 700;
    white-space: nowrap;
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 700;
    white-space: nowrap;
}

.badge-success {
    background: #dcfce7;
    color: #166534;
}

.badge-warning {
    background: #fef3c7;
    color: #92400e;
}

.badge-danger {
    background: #fee2e2;
    color: #991b1b;
}

.badge-draft {
    background: #e0e7ff;
    color: #3730a3;
}

.alert {
    border-radius: 8px;
    padding: 13px 15px;
    margin-bottom: 18px;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.alert-info {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.group-card {
    border: 1px solid var(--border);
    border-radius: 9px;
    background: #fff;
    margin-bottom: 18px;
    overflow: hidden;
}

.group-header {
    padding: 14px 16px;
    background: var(--gray-light);
    border-bottom: 1px solid var(--border);
}

.group-body {
    padding: 16px;
}

.question-card {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    background: #fff;
}

.answer-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 22px;
    margin-bottom: 18px;
}

.question-number {
    color: var(--gray);
    font-weight: 700;
    margin-bottom: 4px;
}

.question-title {
    font-weight: 700;
    margin-bottom: 12px;
}

.option {
    display: block;
    padding: 7px 0;
}

.stat {
    padding: 18px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 9px;
}

.stat-value {
    font-size: 1.7rem;
    font-weight: 700;
}

.empty {
    padding: 30px;
    color: var(--gray);
    text-align: center;
}

pre.result {
    white-space: pre-wrap;
    word-break: break-word;
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 8px;
    padding: 14px;
}

@media (max-width: 900px) {
    .grid-3 {
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 720px) {
    .app-header {
        min-height: auto;
        padding: 12px 16px;
        flex-wrap: wrap;
        gap: 8px;
    }

    .app-header .nav {
        width: 100%;
        margin-left: 0;
        overflow-x: auto;
    }

    .container {
        width: min(100% - 20px,1200px);
        padding-top: 18px;
    }

    .answer-container {
        width: min(100% - 16px,760px);
        padding-top: 12px;
    }

    .page-title {
        flex-direction: column;
    }

    .grid-2,
    .grid-3 {
        grid-template-columns: 1fr;
    }

    .card,
    .answer-card {
        padding: 16px;
    }

    .actions .btn {
        max-width: 100%;
    }
}

@media print {
    .app-header,
    .actions,
    .btn {
        display: none !important;
    }

    body {
        background: #fff;
    }

    .container,
    .answer-container {
        width: 100%;
        max-width: none;
        padding: 0;
    }

    .card,
    .answer-card {
        box-shadow: none;
        border: 0;
    }
}
</style>
</head>

<body>

<?php if (!$answerer): ?>

<header class="app-header">
    <a class="brand"
       href="<?= e(appUrl([
           'screen' => SCREEN_LIST,
       ])) ?>">
        <?= e(APP_NAME) ?>
    </a>

    <nav class="nav">
        <a href="<?= e(appUrl([
            'screen' => SCREEN_LIST,
        ])) ?>">
            アンケート
        </a>

        <a href="<?= e(appUrl([
            'screen' => SCREEN_KINTONE,
        ])) ?>">
            kintone
        </a>

        <a href="<?= e(appUrl([
            'screen' => SCREEN_MAIL,
        ])) ?>">
            メール
        </a>
    </nav>
</header>

<?php endif; ?>

<?php
}

/* =========================================================
 * 17. フッター
 * ========================================================= */

function renderFooter(): void
{
?>
</body>
</html>
<?php
}

/* =========================================================
 * 18. エラー
 * ========================================================= */

function renderError(
    string $title,
    string $message
): void {
    renderHeader('エラー');
?>
<div class="container">

<div class="card">

<h1><?= e($title) ?></h1>

<div class="alert alert-error">
    <?= nl2br(e($message)) ?>
</div>

<a class="btn btn-secondary"
   href="<?= e(appUrl([
       'screen' => SCREEN_LIST,
   ])) ?>">
    一覧へ戻る
</a>

</div>

</div>
<?php
    renderFooter();
}

/* =========================================================
 * 19. 一覧
 * ========================================================= */

function renderList(): void
{
    $items = surveys();

    renderHeader('アンケート一覧');
?>
<div class="container">

<div class="page-title">
    <div>
        <h1>アンケート一覧</h1>
        <div class="muted">
            アンケートの作成・公開・集計・送信を行います。
        </div>
    </div>

    <a class="btn btn-primary"
       href="<?= e(appUrl([
           'screen' => SCREEN_EDIT,
       ])) ?>">
        新規作成
    </a>
</div>

<div class="card">

<?php if (!$items): ?>

<div class="empty">
    アンケートはありません。
</div>

<?php else: ?>

<div class="table-wrap">
<table>

<thead>
<tr>
    <th>タイトル</th>
    <th>状態</th>
    <th>開始日時</th>
    <th>終了日時</th>
    <th>回答数</th>
    <th>操作</th>
</tr>
</thead>

<tbody>

<?php foreach ($items as $survey): ?>

<?php
$status =
    effectiveStatus($survey);

$answerCount = 0;

foreach (answers() as $answer) {
    if (
        ($answer['survey_id'] ?? '')
        === ($survey['id'] ?? '')
    ) {
        $answerCount++;
    }
}
?>

<tr>

<td>
<?= e($survey['title'] ?? '') ?>
</td>

<td>
<span class="badge <?= e(
    statusClass($status)
) ?>">
<?= e(statusLabel($status)) ?>
</span>
</td>

<td>
<?= e($survey['start_at'] ?? '') ?>
</td>

<td>
<?= e($survey['end_at'] ?? '') ?>
</td>

<td>
<?= $answerCount ?>
</td>

<td>

<div class="actions">

<a class="btn btn-secondary btn-sm"
   href="<?= e(appUrl([
       'screen' => SCREEN_EDIT,
       'id' => $survey['id'],
   ])) ?>">
    編集
</a>

<a class="btn btn-secondary btn-sm"
   href="<?= e(appUrl([
       'screen' => SCREEN_PREVIEW,
       'id' => $survey['id'],
   ])) ?>">
    プレビュー
</a>

<a class="btn btn-secondary btn-sm"
   href="<?= e(appUrl([
       'screen' => SCREEN_ANALYTICS,
       'id' => $survey['id'],
   ])) ?>">
    集計
</a>

<a class="btn btn-secondary btn-sm"
   href="<?= e(appUrl([
       'screen' => SCREEN_SEND,
       'id' => $survey['id'],
   ])) ?>">
    送信
</a>

<form method="post"
      action="<?= e(appUrl()) ?>"
      style="display:inline"
      onsubmit="return confirm('削除しますか？');">

<input type="hidden"
       name="action"
       value="delete">

<input type="hidden"
       name="id"
       value="<?= e($survey['id']) ?>">

<button class="btn btn-danger btn-sm"
        type="submit">
    削除
</button>

</form>

</div>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>
</div>

<?php endif; ?>

</div>

</div>
<?php
renderFooter();
}

/* =========================================================
 * 20. 編集
 * ========================================================= */

function renderEdit(
    ?string $id = null
): void {
    $survey =
        $id !== null
            ? findSurvey($id)
            : null;

    if ($id !== null && $survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '対象アンケートを確認してください。'
        );
        return;
    }

    $survey ??= emptySurvey();

    $questionsJson = json_encode(
        $survey['groups'],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    renderHeader('アンケート作成・編集');
?>
<div class="container">

<div class="page-title">

<div>
<h1>アンケート作成・編集</h1>
</div>

<div class="actions">

<a class="btn btn-secondary"
   href="<?= e(appUrl([
       'screen' => SCREEN_LIST,
   ])) ?>">
    キャンセル
</a>

<button
    form="survey-form"
    class="btn btn-primary"
    type="submit">
    保存して一覧へ
</button>

</div>

</div>

<form id="survey-form"
      method="post"
      action="<?= e(appUrl()) ?>">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="id"
       value="<?= e($survey['id']) ?>">

<input type="hidden"
       id="questions_json"
       name="questions_json"
       value="<?= e($questionsJson) ?>">

<div class="card">

<div class="grid-2">

<div class="form-group">

<label class="form-label">
タイトル
</label>

<input type="text"
       name="title"
       required
       value="<?= e(
           $survey['title'] ?? ''
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
質問番号
</label>

<select name="numbering">

<option value="global"
    <?= ($survey['numbering'] ?? 'global')
        === 'global'
        ? 'selected'
        : '' ?>>
    アンケート全体で通番
</option>

<option value="group"
    <?= ($survey['numbering'] ?? '')
        === 'group'
        ? 'selected'
        : '' ?>>
    グループ単位で採番
</option>

</select>

</div>

</div>

<div class="form-group">

<label class="form-label">
説明
</label>

<textarea name="description"><?= e(
    $survey['description'] ?? ''
) ?></textarea>

</div>

<div class="grid-2">

<div class="form-group">

<label class="form-label">
開始日時
</label>

<input type="datetime-local"
       name="start_at"
       value="<?= e(
           $survey['start_at'] ?? ''
       ) ?>">

</div>

<div class="form-group">

<label class="form-label">
終了日時
</label>

<input type="datetime-local"
       name="end_at"
       value="<?= e(
           $survey['end_at'] ?? ''
       ) ?>">

</div>

</div>

<div class="form-group">

<label class="form-label">
状態
</label>

<span class="badge <?= e(
    statusClass(
        effectiveStatus($survey)
    )
) ?>">
<?= e(
    statusLabel(
        effectiveStatus($survey)
    )
) ?>
</span>

</div>

</div>

<div id="editor"></div>

<div class="actions">

<button
    type="button"
    class="btn btn-secondary"
    onclick="addGroup()">
    グループを追加
</button>

</div>

</form>

</div>

<script>
let groups =
    <?= $questionsJson ?: '[]' ?>;

const TYPE_SINGLE = 'single';
const TYPE_MULTI = 'multi';
const TYPE_TEXT = 'text';

function uid(prefix) {
    return prefix + '_' +
        Date.now() + '_' +
        Math.random()
            .toString(16)
            .slice(2);
}

function esc(value) {
    const div =
        document.createElement('div');

    div.textContent =
        value ?? '';

    return div.innerHTML;
}

function renderEditor() {

    const root =
        document.getElementById('editor');

    root.innerHTML = '';

    groups.forEach(
        (group, gi) => {

            const card =
                document.createElement('div');

            card.className =
                'group-card';

            let html = '';

            html +=
                '<div class="group-header">';

            html +=
                '<div class="form-group">';

            html +=
                '<label class="form-label">'
                + 'グループタイトル'
                + '</label>';

            html +=
                '<input type="text" '
                + 'value="'
                + esc(group.title || '')
                + '" '
                + 'oninput="groups['
                + gi
                + '].title=this.value">';

            html += '</div>';

            html +=
                '<div class="actions">';

            html +=
                '<button type="button" '
                + 'class="btn btn-danger btn-sm" '
                + 'onclick="removeGroup('
                + gi
                + ')">'
                + 'グループ削除'
                + '</button>';

            html += '</div>';

            html += '</div>';

            html +=
                '<div class="group-body">';

            (
                group.questions || []
            ).forEach(
                (question, qi) => {

                    html +=
                        '<div class="question-card">';

                    html +=
                        '<div class="muted">'
                        + '質問 '
                        + (qi + 1)
                        + '</div>';

                    html +=
                        '<div class="form-group">';

                    html +=
                        '<label class="form-label">'
                        + '質問文'
                        + '</label>';

                    html +=
                        '<input type="text" '
                        + 'value="'
                        + esc(question.text || '')
                        + '" '
                        + 'oninput="groups['
                        + gi
                        + '].questions['
                        + qi
                        + '].text=this.value">';

                    html += '</div>';

                    html +=
                        '<div class="grid-2">';

                    html +=
                        '<div class="form-group">';

                    html +=
                        '<label class="form-label">'
                        + '回答形式'
                        + '</label>';

                    html +=
                        '<select '
                        + 'onchange="groups['
                        + gi
                        + '].questions['
                        + qi
                        + '].type=this.value;renderEditor()">';

                    html += option(
                        TYPE_SINGLE,
                        '単一選択',
                        question.type
                    );

                    html += option(
                        TYPE_MULTI,
                        '複数選択',
                        question.type
                    );

                    html += option(
                        TYPE_TEXT,
                        '自由記述',
                        question.type
                    );

                    html += '</select>';

                    html += '</div>';

                    html +=
                        '<div class="form-group">';

                    html +=
                        '<label class="checkbox">';

                    html +=
                        '<input type="checkbox" '
                        + (
                            question.required
                                ? 'checked '
                                : ''
                        )
                        + 'onchange="groups['
                        + gi
                        + '].questions['
                        + qi
                        + '].required=this.checked">';

                    html += '必須';

                    html += '</label>';

                    html += '</div>';

                    html += '</div>';

                    if (
                        question.type ===
                            TYPE_SINGLE
                        ||
                        question.type ===
                            TYPE_MULTI
                    ) {

                        html +=
                            '<div class="form-group">';

                        html +=
                            '<label class="form-label">'
                            + '選択肢'
                            + '</label>';

                        html +=
                            '<textarea '
                            + 'oninput="groups['
                            + gi
                            + '].questions['
                            + qi
                            + '].choices=this.value.split(\'\\n\').map(v=>v.trim()).filter(Boolean)">'
                            + esc(
                                (
                                    question.choices
                                    || []
                                ).join('\n')
                            )
                            + '</textarea>';

                        html +=
                            '<div class="form-help">'
                            + '1行につき1つの選択肢'
                            + '</div>';

                        html += '</div>';
                    }

                    html +=
                        '<div class="actions">';

                    html +=
                        '<button type="button" '
                        + 'class="btn btn-danger btn-sm" '
                        + 'onclick="removeQuestion('
                        + gi + ',' + qi
                        + ')">'
                        + '質問削除'
                        + '</button>';

                    html += '</div>';

                    html += '</div>';
                }
            );

            html +=
                '<button type="button" '
                + 'class="btn btn-secondary" '
                + 'onclick="addQuestion('
                + gi
                + ')">'
                + '質問を追加'
                + '</button>';

            html += '</div>';

            html += '</div>';

            card.innerHTML =
                html;

            root.appendChild(card);
        }
    );
}

function option(
    value,
    label,
    selected
) {
    return '<option value="' +
        value + '"' +
        (
            value === selected
                ? ' selected'
                : ''
        ) +
        '>' +
        label +
        '</option>';
}

function addGroup() {

    groups.push({
        id: uid('group'),
        title: '新しいグループ',
        questions: []
    });

    renderEditor();
}

function removeGroup(index) {

    if (
        groups.length <= 1
    ) {
        alert(
            'グループは1つ以上必要です。'
        );
        return;
    }

    if (
        !confirm(
            'このグループを削除しますか？'
        )
    ) {
        return;
    }

    groups.splice(index, 1);

    renderEditor();
}

function addQuestion(groupIndex) {

    groups[groupIndex]
        .questions
        .push({
            id: uid('question'),
            number: '',
            text: '新しい質問',
            type: TYPE_SINGLE,
            required: false,
            choices: [
                '選択肢1',
                '選択肢2'
            ],
            branching: {}
        });

    renderEditor();
}

function removeQuestion(
    groupIndex,
    questionIndex
) {

    if (
        !confirm(
            'この質問を削除しますか？'
        )
    ) {
        return;
    }

    groups[groupIndex]
        .questions
        .splice(questionIndex, 1);

    renderEditor();
}

document
    .getElementById('survey-form')
    .addEventListener(
        'submit',
        function() {

            document
                .getElementById(
                    'questions_json'
                )
                .value =
                    JSON.stringify(groups);
        }
    );

renderEditor();
</script>

<?php
renderFooter();
}

/* =========================================================
 * 21. 保存
 * ========================================================= */

function handleSaveSurvey(): never
{
    $id =
        trim((string)post(
            'id',
            ''
        ));

    $title =
        trim((string)post(
            'title',
            ''
        ));

    if ($title === '') {
        throw new InvalidArgumentException(
            'タイトルを入力してください。'
        );
    }

    $items = surveys();

    $existingIndex = null;

    foreach ($items as $index => $item) {
        if (
            ($item['id'] ?? '')
            === $id
        ) {
            $existingIndex = $index;
            break;
        }
    }

    if ($existingIndex === null) {
        $survey = emptySurvey();
        $survey['id'] = $id !== ''
            ? $id
            : $survey['id'];

        $survey['created_at'] = now();
        $survey['status'] =
            STATUS_DRAFT;

    } else {
        $survey =
            $items[$existingIndex];
    }

    $survey['title'] = $title;

    $survey['description'] =
        trim((string)post(
            'description',
            ''
        ));

    $survey['start_at'] =
        trim((string)post(
            'start_at',
            ''
        ));

    $survey['end_at'] =
        trim((string)post(
            'end_at',
            ''
        ));

    $numbering =
        (string)post(
            'numbering',
            'global'
        );

    $survey['numbering'] =
        in_array(
            $numbering,
            ['global', 'group'],
            true
        )
            ? $numbering
            : 'global';

    $groups =
        normalizeQuestions(
            parseQuestionPayload()
        );

    $survey['groups'] =
        $groups;

    renumberSurvey($survey);

    $survey['updated_at'] =
        now();

    if ($existingIndex === null) {
        $items[] = $survey;
    } else {
        $items[$existingIndex] =
            $survey;
    }

    saveSurveys($items);

    redirect(appUrl([
        'screen' => SCREEN_LIST,
    ]));
}

/* =========================================================
 * 22. 状態変更
 * ========================================================= */

function handleChangeStatus(): never
{
    $id =
        trim((string)post(
            'id',
            ''
        ));

    $newStatus =
        trim((string)post(
            'status',
            ''
        ));

    $items = surveys();

    foreach ($items as &$survey) {

        if (
            ($survey['id'] ?? '')
            !== $id
        ) {
            continue;
        }

        $current =
            effectiveStatus($survey);

        if ($current === STATUS_FINISHED) {
            throw new InvalidArgumentException(
                '終了したアンケートは変更できません。'
            );
        }

        $valid =
            (
                $current === STATUS_DRAFT
                && $newStatus === STATUS_OPEN
            )
            ||
            (
                $current === STATUS_OPEN
                && $newStatus === STATUS_STOPPED
            )
            ||
            (
                $current === STATUS_STOPPED
                && $newStatus === STATUS_OPEN
            );

        if (!$valid) {
            throw new InvalidArgumentException(
                '許可されていない状態変更です。'
            );
        }

        $survey['status'] =
            $newStatus;

        $survey['updated_at'] =
            now();

        saveSurveys($items);

        redirect(appUrl([
            'screen' => SCREEN_LIST,
        ]));
    }

    unset($survey);

    throw new RuntimeException(
        '対象アンケートが見つかりません。'
    );
}

/* =========================================================
 * 23. 削除
 * ========================================================= */

function handleDelete(): never
{
    $id =
        trim((string)post(
            'id',
            ''
        ));

    $items = surveys();

    $found = false;

    $items = array_values(
        array_filter(
            $items,
            function (
                array $survey
            ) use (
                $id,
                &$found
            ): bool {

                if (
                    ($survey['id'] ?? '')
                    === $id
                ) {
                    $found = true;
                    return false;
                }

                return true;
            }
        )
    );

    if (!$found) {
        throw new RuntimeException(
            '削除対象が見つかりません。'
        );
    }

    saveSurveys($items);

    redirect(appUrl([
        'screen' => SCREEN_LIST,
    ]));
}

/* =========================================================
 * 24. プレビュー
 * ========================================================= */

function renderPreview(
    string $id
): void {
    $survey =
        findSurvey($id);

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '対象アンケートを確認してください。'
        );
        return;
    }

    renderHeader(
        'プレビュー'
    );
?>
<div class="answer-container">

<div class="page-title">
<div>
<h1><?= e($survey['title']) ?></h1>
<div class="muted">
<?= nl2br(
    e($survey['description'] ?? '')
) ?>
</div>
</div>

<a class="btn btn-secondary"
   href="<?= e(appUrl([
       'screen' => SCREEN_EDIT,
       'id' => $id,
   ])) ?>">
    編集へ戻る
</a>
</div>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="answer-card">

<h2>
<?= e($group['title'] ?? '') ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card">

<div class="question-number">
<?= e($question['number'] ?? '') ?>
</div>

<div class="question-title">
<?= e($question['text'] ?? '') ?>

<?php if (
    !empty($question['required'])
): ?>
<span class="badge badge-danger">
必須
</span>
<?php endif; ?>

</div>

<?php if (
    ($question['type'] ?? '')
    === ANSWER_TEXT
): ?>

<textarea disabled></textarea>

<?php else: ?>

<?php foreach (
    $question['choices'] ?? []
    as $choice
): ?>

<label class="option">
<input
    type="<?= ($question['type'] ?? '')
        === ANSWER_MULTI
        ? 'checkbox'
        : 'radio' ?>"
    disabled>
<?= e($choice) ?>
</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>
<?php
renderFooter();
}

/* =========================================================
 * 25. 回答
 * ========================================================= */

function renderAnswer(
    string $id
): void {
    $survey =
        findSurvey($id);

    if (
        $survey === null
        || $survey['status'] !== STATUS_OPEN
    ) {
        renderError(
            '回答できません。',
            'このアンケートは現在回答を受け付けていません。'
        );
        return;
    }

    renderHeader(
        'アンケート回答',
        true
    );
?>
<div class="answer-container">

<h1><?= e($survey['title']) ?></h1>

<div class="muted">
<?= nl2br(
    e($survey['description'] ?? '')
) ?>
</div>

<form method="post"
      action="<?= e(appUrl()) ?>">

<input type="hidden"
       name="action"
       value="prepare_answer">

<input type="hidden"
       name="survey_id"
       value="<?= e($id) ?>">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="answer-card">

<h2>
<?= e($group['title'] ?? '') ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="form-group">

<div class="question-number">
<?= e($question['number'] ?? '') ?>
</div>

<label class="form-label">

<?= e($question['text'] ?? '') ?>

<?php if (
    !empty($question['required'])
): ?>

<span class="badge badge-danger">
必須
</span>

<?php endif; ?>

</label>

<?php
$qid =
    (string)$question['id'];

$type =
    $question['type']
    ?? ANSWER_SINGLE;
?>

<?php if (
    $type === ANSWER_TEXT
): ?>

<textarea
    name="answers[<?= e($qid) ?>]"
    <?= !empty($question['required'])
        ? 'required'
        : '' ?>></textarea>

<?php else: ?>

<?php foreach (
    $question['choices'] ?? []
    as $choice
): ?>

<label class="option">

<input
    type="<?= $type === ANSWER_MULTI
        ? 'checkbox'
        : 'radio' ?>"
    name="answers[<?= e($qid) ?>]<?= $type === ANSWER_MULTI
        ? '[]'
        : '' ?>"
    value="<?= e($choice) ?>"
    <?= !empty($question['required'])
        ? 'required'
        : '' ?>>

<?= e($choice) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<button class="btn btn-primary"
        type="submit">
    回答を確認する
</button>

</form>

</div>
<?php
renderFooter();
}

/* =========================================================
 * 26. 回答準備
 * ========================================================= */

function handlePrepareAnswer(): never
{
    $surveyId =
        trim((string)post(
            'survey_id',
            ''
        ));

    $survey =
        findSurvey($surveyId);

    if (
        $survey === null
        || $survey['status'] !== STATUS_OPEN
    ) {
        throw new RuntimeException(
            'このアンケートは回答できません。'
        );
    }

    $submitted =
        post(
            'answers',
            []
        );

    if (!is_array($submitted)) {
        $submitted = [];
    }

    foreach (
        $survey['groups']
        as $group
    ) {
        foreach (
            $group['questions']
            as $question
        ) {

            if (
                empty($question['required'])
            ) {
                continue;
            }

            $value =
                $submitted[
                    $question['id']
                ]
                ?? null;

            $missing =
                $value === null
                || $value === ''
                || (
                    is_array($value)
                    && count($value) === 0
                );

            if ($missing) {
                throw new InvalidArgumentException(
                    '必須項目が未回答です。'
                );
            }
        }
    }

    $_SESSION['answer_draft'] = [
        'survey_id' => $surveyId,
        'answers' => $submitted,
    ];

    redirect(appUrl([
        'screen' => SCREEN_CONFIRM,
    ]));
}

/* =========================================================
 * 27. 確認
 * ========================================================= */

function renderConfirm(): void
{
    $draft =
        $_SESSION['answer_draft']
        ?? null;

    if (!is_array($draft)) {
        renderError(
            '回答情報がありません。',
            '回答画面からやり直してください。'
        );
        return;
    }

    $survey =
        findSurvey(
            (string)$draft['survey_id']
        );

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '回答情報を確認してください。'
        );
        return;
    }

    renderHeader(
        '回答確認',
        true
    );
?>
<div class="answer-container">

<h1>回答確認</h1>

<div class="answer-card">

<h2>
<?= e($survey['title']) ?>
</h2>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h3>
<?= e($group['title'] ?? '') ?>
</h3>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$value =
    $draft['answers'][
        $question['id']
    ]
    ?? '';

if (is_array($value)) {
    $value =
        implode(
            '、',
            array_map(
                'strval',
                $value
            )
        );
}
?>

<div class="form-group">

<div class="question-number">
<?= e($question['number'] ?? '') ?>
</div>

<strong>
<?= e($question['text'] ?? '') ?>
</strong>

<div>
<?= nl2br(e($value)) ?>
</div>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="actions">

<a class="btn btn-secondary"
   href="<?= e(appUrl([
       'screen' => SCREEN_ANSWER,
       'id' => $survey['id'],
   ])) ?>">
    修正する
</a>

<form method="post"
      action="<?= e(appUrl()) ?>">

<input type="hidden"
       name="action"
       value="submit_answer">

<button class="btn btn-primary"
        type="submit">
    回答を送信する
</button>

</form>

</div>

</div>

</div>
<?php
renderFooter();
}

/* =========================================================
 * 28. 回答送信
 * ========================================================= */

function handleSubmitAnswer(): never
{
    $draft =
        $_SESSION['answer_draft']
        ?? null;

    if (!is_array($draft)) {
        throw new RuntimeException(
            '回答情報がありません。'
        );
    }

    $surveyId =
        (string)$draft['survey_id'];

    $survey =
        findSurvey($surveyId);

    if (
        $survey === null
        || $survey['status'] !== STATUS_OPEN
    ) {
        throw new RuntimeException(
            'このアンケートは現在回答を受け付けていません。'
        );
    }

    $items = answers();

    $items[] = [
        'id' =>
            makeId('answer'),
        'survey_id' =>
            $surveyId,
        'customer_id' =>
            null,
        'created_at' =>
            now(),
        'answers' =>
            is_array(
                $draft['answers'] ?? null
            )
                ? $draft['answers']
                : [],
    ];

    saveAnswers($items);

    $_SESSION['answer_draft'] = null;

    $_SESSION['last_answer_id'] =
        $items[array_key_last($items)]['id'];

    redirect(appUrl([
        'screen' => SCREEN_COMPLETE,
    ]));
}

/* =========================================================
 * 29. 完了
 * ========================================================= */

function renderComplete(): void
{
    renderHeader(
        '回答完了',
        true
    );
?>
<div class="answer-container">

<div class="answer-card">

<h1>回答ありがとうございました。</h1>

<p>
アンケートの回答を受け付けました。
</p>

</div>

</div>
<?php
renderFooter();
}

/* =========================================================
 * 30. kintone画面
 * ========================================================= */

function renderKintone(
    ?string $message = null,
    ?string $error = null,
    array $fields = []
): void {
    $config =
        settings()['kintone']
        ?? [];

    renderHeader(
        'kintone連携設定'
    );
?>
<div class="container">

<div class="page-title">

<div>
<h1>kintone連携設定</h1>

<div class="muted">
顧客情報の取得元を設定します。
</div>
</div>

</div>

<?php if ($message !== null): ?>
<div class="alert alert-success">
<?= e($message) ?>
</div>
<?php endif; ?>

<?php if ($error !== null): ?>
<div class="alert alert-error">
<?= e($error) ?>
</div>
<?php endif; ?>

<div class="card">

<form method="post"
      action="<?= e(appUrl()) ?>">

<input type="hidden"
       name="action"
       value="save_kintone">

<input type="hidden"
       name="screen"
       value="kintone">

<div class="form-row">

<label>サブドメイン</label>

<input type="text"
       name="subdomain"
       value="<?= e(
           $config['subdomain'] ?? ''
       ) ?>"
       placeholder="example">

</div>

<div class="form-row">

<label>顧客管理アプリID</label>

<input type="number"
       name="app_id"
       min="1"
       value="<?= e(
           $config['app_id'] ?? ''
       ) ?>">

</div>

<div class="form-row">

<label>ログイン名</label>

<input type="text"
       name="username"
       value="<?= e(
           $config['username'] ?? ''
       ) ?>">

</div>

<div class="form-row">

<label>パスワード</label>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">

</div>

<div class="form-row">

<label>Proxy</label>

<input type="text"
       name="proxy"
       value="<?= e(
           $config['proxy'] ?? ''
       ) ?>"
       placeholder="host:port">

</div>

<div class="form-row">

<label class="checkbox">

<input type="checkbox"
       name="verify_ssl"
       value="1"
       <?= !isset(
           $config['verify_ssl']
       )
       || !empty(
           $config['verify_ssl']
       )
           ? 'checked'
           : '' ?>>

SSL証明書検証を有効にする

</label>

</div>

<button class="btn btn-primary"
        type="submit">
    設定を保存
</button>

</form>

</div>

<div class="card">

<h2>接続テスト</h2>

<p class="muted">
保存済みの設定を使用して、
実際のkintone APIへ接続します。
</p>

<form method="post"
      action="<?= e(appUrl()) ?>">

<input type="hidden"
       name="screen"
       value="kintone">

<input type="hidden"
       name="action"
       value="test_kintone">

<button class="btn btn-success"
        type="submit">
    kintoneへ接続テスト
</button>

</form>

</div>

<div class="card">

<h2>項目一覧</h2>

<form method="post"
      action="<?= e(appUrl()) ?>">

<input type="hidden"
       name="screen"
       value="kintone">

<input type="hidden"
       name="action"
       value="fetch_kintone_fields">

<button class="btn btn-secondary"
        type="submit">
    kintone項目を取得
</button>

</form>

<?php if ($fields): ?>

<hr>

<form method="post"
      action="<?= e(appUrl()) ?>">

<input type="hidden"
       name="screen"
       value="kintone">

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<?php
$mapping =
    is_array(
        $config['mapping'] ?? null
    )
        ? $config['mapping']
        : [];
?>

<div class="form-row">

<label>組織名</label>

<select name="organization">

<option value="">未設定</option>

<?php foreach (
    $fields as $code => $field
): ?>

<option
    value="<?= e($code) ?>"
    <?= ($mapping['organization'] ?? '')
        === $code
        ? 'selected'
        : '' ?>>
    <?= e(
        $field['label']
        ?? $code
    ) ?>
    [<?= e($code) ?>]
</option>

<?php endforeach; ?>

</select>

</div>

<div class="form-row">

<label>氏名</label>

<select name="name">

<option value="">未設定</option>

<?php foreach (
    $fields as $code => $field
): ?>

<option
    value="<?= e($code) ?>"
    <?= ($mapping['name'] ?? '')
        === $code
        ? 'selected'
        : '' ?>>
    <?= e(
        $field['label']
        ?? $code
    ) ?>
    [<?= e($code) ?>]
</option>

<?php endforeach; ?>

</select>

</div>

<div class="form-row">

<label>メールアドレス</label>

<select name="email">

<option value="">未設定</option>

<?php foreach (
    $fields as $code => $field
): ?>

<option
    value="<?= e($code) ?>"
    <?= ($mapping['email'] ?? '')
        === $code
        ? 'selected'
        : '' ?>>
    <?= e(
        $field['label']
        ?? $code
    ) ?>
    [<?= e($code) ?>]
</option>

<?php endforeach; ?>

</select>

</div>

<div class="form-row">

<label>部署名</label>

<select name="department">

<option value="">未設定</option>

<?php foreach (
    $fields as $code => $field
): ?>

<option
    value="<?= e($code) ?>"
    <?= ($mapping['department'] ?? '')
        === $code
        ? 'selected'
        : '' ?>>
    <?= e(
        $field['label']
        ?? $code
    ) ?>
    [<?= e($code) ?>]
</option>

<?php endforeach; ?>

</select>

</div>

<div class="form-row">

<label>電話番号</label>

<select name="phone">

<option value="">未設定</option>

<?php foreach (
    $fields as $code => $field
): ?>

<option
    value="<?= e($code) ?>"
    <?= ($mapping['phone'] ?? '')
        === $code
        ? 'selected'
        : '' ?>>
    <?= e(
        $field['label']
        ?? $code
    ) ?>
    [<?= e($code) ?>]
</option>

<?php endforeach; ?>

</select>

</div>

<div class="form-row">

<label>住所項目</label>

<select name="address[]" multiple>

<?php
$address =
    is_array(
        $mapping['address'] ?? null
    )
        ? $mapping['address']
        : [];
?>

<?php foreach (
    $fields as $code => $field
): ?>

<option
    value="<?= e($code) ?>"
    <?= in_array(
        $code,
        $address,
        true
    )
        ? 'selected'
        : '' ?>>
    <?= e(
        $field['label']
        ?? $code
    ) ?>
    [<?= e($code) ?>]
</option>

<?php endforeach; ?>

</select>

<div class="form-help">
複数選択した項目を連結して住所として保存します。
</div>

</div>

<button class="btn btn-primary"
        type="submit">
    項目マッピングを保存
</button>

</form>

<?php endif; ?>

</div>

<div class="card">

<h2>顧客情報同期</h2>

<form method="post"
      action="<?= e(appUrl()) ?>">

<input type="hidden"
       name="screen"
       value="kintone">

<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="btn btn-primary"
        type="submit">
    kintoneから顧客情報を同期
</button>

</form>

<?php
$count = count(customers());
?>

<p class="muted">
現在保存されている顧客数:
<?= $count ?>
</p>

</div>

</div>
<?php
renderFooter();
}

/* =========================================================
 * 31. メール設定画面
 * ========================================================= */

function renderMail(
    ?string $message = null,
    ?string $error = null
): void {
    $config =
        settings()['mail']
        ?? [];

    renderHeader(
        'メールサーバ設定'
    );
?>
<div class="container">

<div class="page-title">
<div>
<h1>メールサーバ設定</h1>
</div>
</div>

<?php if ($message !== null): ?>
<div class="alert alert-success">
<?= e($message) ?>
</div>
<?php endif; ?>

<?php if ($error !== null): ?>
<div class="alert alert-error">
<?= e($error) ?>
</div>
<?php endif; ?>

<div class="card">

<form method="post"
      action="<?= e(appUrl()) ?>">

<input type="hidden"
       name="action"
       value="save_mail">

<input type="hidden"
       name="screen"
       value="mail">

<div class="form-row">

<label>SMTPサーバ</label>

<input type="text"
       name="host"
       value="<?= e(
           $config['host'] ?? ''
       ) ?>">

</div>

<div class="form-row">

<label>SMTPポート</label>

<input type="number"
       name="port"
       min="1"
       max="65535"
       value="<?= e(
           $config['port'] ?? 587
       ) ?>">

</div>

<div class="form-row">

<label>暗号化方式</label>

<select name="encryption">

<option value="tls"
    <?= ($config['encryption'] ?? 'tls')
        === 'tls'
        ? 'selected'
        : '' ?>>
    STARTTLS
</option>

<option value="ssl"
    <?= ($config['encryption'] ?? '')
        === 'ssl'
        ? 'selected'
        : '' ?>>
    SSL/TLS
</option>

<option value="none"
    <?= ($config['encryption'] ?? '')
        === 'none'
        ? 'selected'
        : '' ?>>
    なし
</option>

</select>

</div>

<div class="form-row">

<label class="checkbox">

<input type="checkbox"
       name="auth"
       value="1"
       <?= !isset($config['auth'])
           || !empty($config['auth'])
           ? 'checked'
           : '' ?>>

SMTP認証を使用

</label>

</div>

<div class="form-row">

<label>SMTPユーザー名</label>

<input type="text"
       name="username"
       value="<?= e(
           $config['username'] ?? ''
       ) ?>">

</div>

<div class="form-row">

<label>SMTPパスワード</label>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更しない場合は空欄">

</div>

<div class="form-row">

<label>送信元メールアドレス</label>

<input type="email"
       name="from_email"
       value="<?= e(
           $config['from_email'] ?? ''
       ) ?>">

</div>

<div class="form-row">

<label>送信元名</label>

<input type="text"
       name="from_name"
       value="<?= e(
           $config['from_name'] ?? ''
       ) ?>">

</div>

<div class="form-row">

<label>返信先メールアドレス</label>

<input type="email"
       name="reply_to"
       value="<?= e(
           $config['reply_to'] ?? ''
       ) ?>">

</div>

<button class="btn btn-primary"
        type="submit">
    設定を保存
</button>

</form>

</div>

<div class="card">

<h2>SMTP接続確認</h2>

<form method="post"
      action="<?= e(appUrl()) ?>">

<input type="hidden"
       name="screen"
       value="mail">

<input type="hidden"
       name="action"
       value="test_smtp">

<button class="btn btn-success"
        type="submit">
    SMTP接続・認証テスト
</button>

</form>

</div>

</div>
<?php
renderFooter();
}

/* =========================================================
 * 32. 設定POST
 * ========================================================= */

function handleSaveKintone(): never
{
    saveKintoneConfig();

    redirect(appUrl([
        'screen' => SCREEN_KINTONE,
    ]));
}

function handleSaveKintoneMapping(): never
{
    $all = settings();

    $config =
        is_array($all['kintone'] ?? null)
            ? $all['kintone']
            : [];

    $config['mapping'] = [
        'organization' =>
            trim((string)post(
                'organization',
                ''
            )),
        'name' =>
            trim((string)post(
                'name',
                ''
            )),
        'email' =>
            trim((string)post(
                'email',
                ''
            )),
        'department' =>
            trim((string)post(
                'department',
                ''
            )),
        'phone' =>
            trim((string)post(
                'phone',
                ''
            )),
        'address' =>
            is_array(
                post('address', [])
            )
                ? array_values(
                    array_map(
                        'strval',
                        post('address', [])
                    )
                )
                : [],
    ];

    $all['kintone'] =
        $config;

    saveSettings($all);

    redirect(appUrl([
        'screen' => SCREEN_KINTONE,
    ]));
}

function handleSaveMail(): never
{
    saveMailConfig();

    redirect(appUrl([
        'screen' => SCREEN_MAIL,
    ]));
}

/* =========================================================
 * 33. 送信画面
 * ========================================================= */

function renderSend(
    string $surveyId
): void {
    $survey =
        findSurvey($surveyId);

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '対象アンケートを確認してください。'
        );
        return;
    }

    $customerList =
        customers();

    $logs =
        array_reverse(
            array_values(
                array_filter(
                    mailLogs(),
                    static function (
                        array $log
                    ) use (
                        $surveyId
                    ): bool {
                        return (
                            ($log['survey_id'] ?? '')
                            === $surveyId
                        );
                    }
                )
            )
        );

    renderHeader(
        '顧客選択・メール送信'
    );
?>
<div class="container">

<div class="page-title">

<div>
<h1>顧客選択・メール送信</h1>

<div class="muted">
<?= e($survey['title']) ?>
</div>
</div>

<a class="btn btn-secondary"
   href="<?= e(appUrl([
       'screen' => SCREEN_LIST,
   ])) ?>">
    一覧へ戻る
</a>

</div>

<div class="card">

<h2>顧客</h2>

<?php if (!$customerList): ?>

<div class="empty">
顧客データがありません。
<br>
先にkintoneから顧客情報を同期してください。
</div>

<?php else: ?>

<form method="post"
      action="<?= e(appUrl()) ?>">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="screen"
       value="send">

<input type="hidden"
       name="survey_id"
       value="<?= e($surveyId) ?>">

<div class="form-row">

<label>顧客検索</label>

<input type="search"
       id="customerSearch"
       placeholder="組織名・氏名・メールアドレス">

</div>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>選択</th>
<th>組織名</th>
<th>氏名</th>
<th>メールアドレス</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $customerList
    as $customer
): ?>

<tr class="customer-row">

<td>

<input type="checkbox"
       name="customer_ids[]"
       value="<?= e(
           $customer['id'] ?? ''
       ) ?>">

</td>

<td>
<?= e(
    $customer['organization'] ?? ''
) ?>
</td>

<td>
<?= e(
    $customer['name'] ?? ''
) ?>
</td>

<td>
<?= e(
    $customer['email'] ?? ''
) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<div class="form-row">

<label>件名</label>

<input type="text"
       name="subject"
       value="<?= e(
           $survey['title']
           . ' のご案内'
       ) ?>">

</div>

<div class="form-row">

<label>本文</label>

<textarea name="body">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>

</div>

<button class="btn btn-primary"
        type="submit">
    送信
</button>

</form>

<script>
const search =
    document.getElementById(
        'customerSearch'
    );

search.addEventListener(
    'input',
    function() {

        const word =
            this.value
                .toLowerCase()
                .trim();

        document
            .querySelectorAll(
                '.customer-row'
            )
            .forEach(function(row) {

                row.style.display =
                    row.textContent
                        .toLowerCase()
                        .includes(word)
                        ? ''
                        : 'none';
            });
    }
);
</script>

<?php endif; ?>

</div>

<div class="card">

<h2>送信履歴</h2>

<?php if (!$logs): ?>

<div class="empty">
送信履歴はありません。
</div>

<?php else: ?>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>送信日時</th>
<th>顧客</th>
<th>宛先</th>
<th>結果</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $logs
    as $log
): ?>

<tr>

<td>
<?= e(
    $log['created_at'] ?? ''
) ?>
</td>

<td>
<?= e(
    $log['customer_name'] ?? ''
) ?>
</td>

<td>
<?= e(
    $log['email'] ?? ''
) ?>
</td>

<td>

<?php if (
    !empty($log['success'])
): ?>

<span class="badge badge-success">
送信済み
</span>

<?php else: ?>

<span class="badge badge-danger">
失敗
</span>

<?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</div>

</div>
<?php
renderFooter();
}

/* =========================================================
 * 34. メール送信処理
 * ========================================================= */

function handleSendMail(): never
{
    $surveyId =
        trim((string)post(
            'survey_id',
            ''
        ));

    $survey =
        findSurvey($surveyId);

    if ($survey === null) {
        throw new RuntimeException(
            '対象アンケートが見つかりません。'
        );
    }

    $ids =
        post(
            'customer_ids',
            []
        );

    if (!is_array($ids)) {
        $ids = [];
    }

    if (!$ids) {
        throw new InvalidArgumentException(
            '送信先を選択してください。'
        );
    }

    $subject =
        trim((string)post(
            'subject',
            ''
        ));

    $body =
        (string)post(
            'body',
            ''
        );

    $mail =
        settings()['mail']
        ?? [];

    $mail =
        validateMailConfig($mail);

    $customers =
        customers();

    $url =
        appUrl([
            'screen' => SCREEN_ANSWER,
            'id' => $surveyId,
        ]);

    $logs =
        mailLogs();

    foreach ($customers as $customer) {

        $customerId =
            (string)(
                $customer['id']
                ?? ''
            );

        if (!in_array(
            $customerId,
            array_map(
                'strval',
                $ids
            ),
            true
        )) {
            continue;
        }

        $email =
            trim((string)(
                $customer['email']
                ?? ''
            ));

        $name =
            (string)(
                $customer['name']
                ?? ''
            );

        $finalSubject =
            str_replace(
                '{顧客名}',
                $name,
                $subject
            );

        $finalBody =
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

        $success = false;
        $errorMessage = '';

        try {

            sendSmtpMail(
                $mail,
                $email,
                $finalSubject,
                $finalBody
            );

            $success = true;

        } catch (Throwable $e) {

            /*
             * 認証情報はログへ保存しない。
             */
            $errorMessage =
                $e->getMessage();
        }

        $logs[] = [
            'id' =>
                makeId('mail'),
            'survey_id' =>
                $surveyId,
            'customer_id' =>
                $customerId,
            'customer_name' =>
                $name,
            'email' =>
                $email,
            'created_at' =>
                now(),
            'success' =>
                $success,
            'error' =>
                $errorMessage,
        ];
    }

    saveMailLogs($logs);

    redirect(appUrl([
        'screen' => SCREEN_SEND,
        'id' => $surveyId,
    ]));
}

/* =========================================================
 * 35. 集計
 * ========================================================= */

function renderAnalytics(
    string $surveyId
): void {
    $survey =
        findSurvey($surveyId);

    if ($survey === null) {
        renderError(
            'アンケートが見つかりません。',
            '対象アンケートを確認してください。'
        );
        return;
    }

    $surveyAnswers =
        array_values(
            array_filter(
                answers(),
                static function (
                    array $answer
                ) use (
                    $surveyId
                ): bool {
                    return (
                        ($answer['survey_id'] ?? '')
                        === $surveyId
                    );
                }
            )
        );

    $sentCount =
        count(
            array_filter(
                mailLogs(),
                static function (
                    array $log
                ) use (
                    $surveyId
                ): bool {
                    return (
                        ($log['survey_id'] ?? '')
                        === $surveyId
                        && !empty(
                            $log['success']
                        )
                    );
                }
            )
        );

    $answerCount =
        count($surveyAnswers);

    $rate =
        $sentCount > 0
            ? round(
                $answerCount
                / $sentCount
                * 100,
                1
            )
            : 0;

    renderHeader(
        '回答集計・分析'
    );
?>
<div class="container">

<div class="page-title">

<div>
<h1>回答集計・分析</h1>

<div class="muted">
<?= e($survey['title']) ?>
</div>
</div>

<div class="actions">

<a class="btn btn-secondary"
   href="<?= e(appUrl([
       'screen' => SCREEN_LIST,
   ])) ?>">
    一覧へ戻る
</a>

<a class="btn btn-secondary"
   href="<?= e(appUrl([
       'action' => 'export_csv',
       'id' => $surveyId,
   ])) ?>">
    CSV
</a>

<button class="btn btn-secondary"
        type="button"
        onclick="window.print()">
    PDF / 印刷
</button>

</div>

</div>

<div class="grid-3">

<div class="stat">
<div class="muted">送信対象者数</div>
<div class="stat-value">
<?= $sentCount ?>
</div>
</div>

<div class="stat">
<div class="muted">回答数</div>
<div class="stat-value">
<?= $answerCount ?>
</div>
</div>

<div class="stat">
<div class="muted">回答率</div>
<div class="stat-value">
<?= $rate ?>%
</div>
</div>

</div>

<?php if (!$surveyAnswers): ?>

<div class="card">
<div class="empty">
現在、回答データはありません
</div>
</div>

<?php else: ?>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">

<h2>
<?= e($group['title'] ?? '') ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$counts = [];

foreach (
    $question['choices'] ?? []
    as $choice
) {
    $counts[$choice] = 0;
}

foreach (
    $surveyAnswers
    as $answer
) {

    $value =
        $answer['answers'][
            $question['id']
        ]
        ?? null;

    if (is_array($value)) {

        foreach ($value as $item) {

            $item =
                (string)$item;

            if (
                isset($counts[$item])
            ) {
                $counts[$item]++;
            }
        }

    } elseif (
        $value !== null
        && $value !== ''
    ) {

        $value =
            (string)$value;

        if (
            isset($counts[$value])
        ) {
            $counts[$value]++;
        }
    }
}
?>

<h3>
<?= e(
    $question['number'] ?? ''
) ?>
<?= e(
    $question['text'] ?? ''
) ?>
</h3>

<?php if (
    $question['type']
    === ANSWER_TEXT
): ?>

<?php foreach (
    $surveyAnswers
    as $answer
): ?>

<?php
$value =
    $answer['answers'][
        $question['id']
    ] ?? '';
?>

<?php if (
    $value !== ''
): ?>

<div class="card">
<?= nl2br(e($value)) ?>
</div>

<?php endif; ?>

<?php endforeach; ?>

<?php else: ?>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>選択肢</th>
<th>回答数</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $counts as $choice => $count
): ?>

<tr>
<td><?= e($choice) ?></td>
<td><?= $count ?></td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>
<?php
renderFooter();
}

/* =========================================================
 * 36. CSV
 * ========================================================= */

function exportCsv(
    string $surveyId
): never {
    $survey =
        findSurvey($surveyId);

    if ($survey === null) {
        http_response_code(404);
        exit;
    }

    $rows = [];

    $header = [
        '回答ID',
        '回答日時',
    ];

    foreach (
        $survey['groups']
        as $group
    ) {
        foreach (
            $group['questions']
            as $question
        ) {
            $header[] =
                $question['number']
                . ' '
                . $question['text'];
        }
    }

    $rows[] = $header;

    foreach (
        answers()
        as $answer
    ) {

        if (
            ($answer['survey_id'] ?? '')
            !== $surveyId
        ) {
            continue;
        }

        $row = [
            $answer['id'] ?? '',
            $answer['created_at'] ?? '',
        ];

        foreach (
            $survey['groups']
            as $group
        ) {
            foreach (
                $group['questions']
                as $question
            ) {

                $value =
                    $answer['answers'][
                        $question['id']
                    ]
                    ?? '';

                if (is_array($value)) {
                    $value =
                        implode(
                            '、',
                            array_map(
                                'strval',
                                $value
                            )
                        );
                }

                $row[] =
                    (string)$value;
            }
        }

        $rows[] = $row;
    }

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="survey-'
        . rawurlencode($surveyId)
        . '.csv"'
    );

    /*
     * Excel向けUTF-8 BOM。
     */
    echo "\xEF\xBB\xBF";

    $fp = fopen(
        'php://output',
        'wb'
    );

    foreach ($rows as $row) {
        fputcsv(
            $fp,
            $row
        );
    }

    fclose($fp);

    exit;
}

/* =========================================================
 * 37. アクションディスパッチ
 *
 * 重要:
 *
 * action=test_kintone
 *
 * をここで明示的に処理する。
 * ========================================================= */

function dispatchAction(): bool
{
    $a = action();

    if ($a === '') {
        return false;
    }

    switch ($a) {

        case 'save_survey':
            handleSaveSurvey();
            return true;

        case 'delete':
            handleDelete();
            return true;

        case 'change_status':
            handleChangeStatus();
            return true;

        case 'prepare_answer':
            handlePrepareAnswer();
            return true;

        case 'submit_answer':
            handleSubmitAnswer();
            return true;

        case 'save_kintone':
            handleSaveKintone();
            return true;

        /*
         * ★今回の500対策
         *
         * ブラウザから
         *
         * POST
         * screen=kintone
         * action=test_kintone
         *
         * が来た場合、必ずここへ入る。
         */
        case 'test_kintone':

            try {

                $message =
                    testKintone();

                renderKintone(
                    $message,
                    null
                );

            } catch (Throwable $e) {

                renderKintone(
                    null,
                    safeExternalError(
                        $e
                    )
                );
            }

            return true;

        case 'fetch_kintone_fields':

            try {

                $fields =
                    getKintoneFields();

                renderKintone(
                    'kintoneの項目一覧を取得しました。',
                    null,
                    $fields
                );

            } catch (Throwable $e) {

                renderKintone(
                    null,
                    safeExternalError(
                        $e
                    )
                );
            }

            return true;

        case 'save_kintone_mapping':
            handleSaveKintoneMapping();
            return true;

        case 'sync_kintone':

            try {

                $count =
                    syncCustomers();

                renderKintone(
                    'kintoneから'
                    . $count
                    . '件の顧客情報を同期しました。',
                    null
                );

            } catch (Throwable $e) {

                renderKintone(
                    null,
                    safeExternalError(
                        $e
                    )
                );
            }

            return true;

        case 'save_mail':
            handleSaveMail();
            return true;

        case 'test_smtp':

            try {

                $message =
                    testSmtp();

                renderMail(
                    $message,
                    null
                );

            } catch (Throwable $e) {

                renderMail(
                    null,
                    safeExternalError(
                        $e
                    )
                );
            }

            return true;

        case 'send_mail':
            handleSendMail();
            return true;

        case 'export_csv':

            exportCsv(
                trim((string)get(
                    'id',
                    ''
                ))
            );

            return true;

        default:

            throw new InvalidArgumentException(
                '不明なアクションです: '
                . $a
            );
    }
}

/* =========================================================
 * 38. 外部サービスエラー
 * ========================================================= */

function safeExternalError(
    Throwable $e
): string {
    /*
     * POCでは原因把握に必要な範囲で
     * エラーを表示する。
     *
     * ただし認証情報は絶対に表示しない。
     */

    $message =
        $e->getMessage();

    $sensitive = [
        'password',
        'passwd',
        'authorization',
        'x-cybozu-authorization',
    ];

    foreach (
        $sensitive
        as $word
    ) {
        if (
            stripos(
                $message,
                $word
            ) !== false
        ) {
            return
                '外部サービスとの通信に失敗しました。'
                . '設定値を確認してください。';
        }
    }

    return $message !== ''
        ? $message
        : '外部サービスとの通信に失敗しました。';
}

/* =========================================================
 * 39. 画面ディスパッチ
 * ========================================================= */

function dispatchScreen(): void
{
    switch (screen()) {

        case SCREEN_LIST:
            renderList();
            return;

        case SCREEN_EDIT:

            renderEdit(
                get('id') !== null
                    ? (string)get('id')
                    : null
            );

            return;

        case SCREEN_PREVIEW:

            renderPreview(
                (string)get(
                    'id',
                    ''
                )
            );

            return;

        case SCREEN_SEND:

            renderSend(
                (string)get(
                    'id',
                    ''
                )
            );

            return;

        case SCREEN_ANALYTICS:

            renderAnalytics(
                (string)get(
                    'id',
                    ''
                )
            );

            return;

        case SCREEN_KINTONE:
            renderKintone();
            return;

        case SCREEN_MAIL:
            renderMail();
            return;

        case SCREEN_ANSWER:

            renderAnswer(
                (string)get(
                    'id',
                    ''
                )
            );

            return;

        case SCREEN_CONFIRM:
            renderConfirm();
            return;

        case SCREEN_COMPLETE:
            renderComplete();
            return;

        default:
            redirect(appUrl([
                'screen' => SCREEN_LIST,
            ]));
    }
}

/* =========================================================
 * 40. 起動
 * ========================================================= */

ensureDataFiles();

try {

    /*
     * actionはGETでもPOSTでも受け付ける。
     *
     * そのため
     *
     *   index.php?action=test_kintone
     *
     * と
     *
     *   POST
     *   action=test_kintone
     *
     * の両方に対応する。
     */
    if (action() !== '') {
        dispatchAction();
        exit;
    }

    dispatchScreen();

} catch (Throwable $e) {

    /*
     * POCでは500ページを出さず、
     * アプリケーション画面としてエラーを表示する。
     *
     * これにより今回のような
     *
     *   POST ... 500
     *
     * だけでは原因が分からない状態を避ける。
     */

    $message =
        $e->getMessage();

    /*
     * 認証情報が含まれる可能性がある
     * 例外は外部サービス用の安全な文言へ。
     */
    if (
        stripos(
            $message,
            'password'
        ) !== false
        ||
        stripos(
            $message,
            'authorization'
        ) !== false
    ) {
        $message =
            '処理に失敗しました。設定値を確認してください。';
    }

    renderError(
        '処理に失敗しました。',
        $message
    );
}
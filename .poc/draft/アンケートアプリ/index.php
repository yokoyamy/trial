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
 * POC対象外:
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
 * kintone:
 *   - 入力は以下のいずれでも受け付ける。
 *
 *       example
 *       example.cybozu.com
 *       https://example.cybozu.com
 *
 *   - 内部では必ず
 *
 *       example.cybozu.com
 *
 *     に正規化する。
 *
 *   - API認証は X-Cybozu-Authorization を使用する。
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
 * 基本
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
    if (!$params) {
        return 'index.php';
    }

    return 'index.php?' . http_build_query($params);
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
 * データ保存
 *
 * POCでは index.php と同じディレクトリ。
 * ========================================================= */

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

function readJson(
    string $file,
    array $default = []
): array {
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

    if (
        $contents === false
        || trim($contents) === ''
    ) {
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

function writeJson(
    string $file,
    array $data
): void {
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
    foreach ([
        'surveys.json',
        'answers.json',
        'customers.json',
        'mail_logs.json',
        'settings.json',
    ] as $file) {
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
        $items
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
        $items
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
        $items
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
        $items
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

function makeId(string $prefix): string
{
    return $prefix
        . '-'
        . date('YmdHis')
        . '-'
        . bin2hex(random_bytes(5));
}


/* =========================================================
 * kintone
 *
 * 重要:
 *
 * 「サブドメイン」の入力値をそのまま
 * URLへ連結しない。
 *
 * 以下を正規化する。
 *
 *   example
 *   example.cybozu.com
 *   https://example.cybozu.com
 *
 * ↓
 *
 *   example.cybozu.com
 * ========================================================= */

function normalizeKintoneHost(
    mixed $value
): string {
    $value = trim((string)$value);

    if ($value === '') {
        throw new InvalidArgumentException(
            'kintoneのサブドメインを指定してください。'
        );
    }

    /*
     * 前後の空白を除去。
     */
    $value = trim($value);

    /*
     * URLとして入力された場合。
     *
     * https://example.cybozu.com
     * http://example.cybozu.com
     *
     * の形式を許容する。
     */
    if (
        preg_match(
            '#^https?://#i',
            $value
        )
    ) {
        $parsed = parse_url($value);

        if (
            !is_array($parsed)
            || empty($parsed['host'])
        ) {
            throw new InvalidArgumentException(
                'kintoneのURLが不正です。'
            );
        }

        $value = (string)$parsed['host'];

        /*
         * ポートやパスを入力しても
         * kintoneの接続先としては使用しない。
         */
    }

    /*
     * 入力された
     *
     * example.cybozu.com
     *
     * を許容する。
     */
    if (
        str_ends_with(
            strtolower($value),
            '.cybozu.com'
        )
    ) {
        $subdomain = substr(
            $value,
            0,
            -strlen('.cybozu.com')
        );

        if (
            $subdomain === ''
            || !preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
                $subdomain
            )
        ) {
            throw new InvalidArgumentException(
                'kintoneのサブドメインが不正です。'
            );
        }

        return strtolower(
            $subdomain . '.cybozu.com'
        );
    }

    /*
     * 「example」だけが入力された場合。
     */
    if (
        preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]*$/',
            $value
        )
    ) {
        return strtolower(
            $value . '.cybozu.com'
        );
    }

    throw new InvalidArgumentException(
        'kintoneのサブドメインが不正です。'
        . '「example」または'
        . '「example.cybozu.com」を指定してください。'
    );
}


/* =========================================================
 * kintone設定検証
 * ========================================================= */

function validateKintoneConfig(
    array $config
): array {
    $host = normalizeKintoneHost(
        $config['subdomain'] ?? ''
    );

    $appId = (int)(
        $config['app_id'] ?? 0
    );

    if ($appId <= 0) {
        throw new InvalidArgumentException(
            'kintoneのアプリIDを指定してください。'
        );
    }

    $username = trim((string)(
        $config['username'] ?? ''
    ));

    if ($username === '') {
        throw new InvalidArgumentException(
            'kintoneのログイン名を指定してください。'
        );
    }

    $password = (string)(
        $config['password'] ?? ''
    );

    if ($password === '') {
        throw new InvalidArgumentException(
            'kintoneのパスワードを指定してください。'
        );
    }

    $proxy = trim((string)(
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
        /*
         * ここは必ず
         *
         * example.cybozu.com
         *
         * の形になる。
         */
        'host' => $host,

        /*
         * 画面・保存データとの互換性のため
         * subdomainキーも残す。
         */
        'subdomain' => preg_replace(
            '/\.cybozu\.com$/i',
            '',
            $host
        ),

        'app_id' => $appId,

        'username' => $username,

        'password' => $password,

        'proxy' => $proxy,

        'verify_ssl' =>
            !isset($config['verify_ssl'])
            || !empty($config['verify_ssl']),

        'mapping' =>
            is_array($config['mapping'] ?? null)
                ? $config['mapping']
                : [],
    ];
}


/* =========================================================
 * kintone HTTP
 *
 * PHP cURLは使用しない。
 * ========================================================= */

function kintoneRequest(
    string $method,
    string $path,
    array $config,
    ?array $body = null
): array {
    $config =
        validateKintoneConfig($config);

    /*
     * kintone REST APIの正式な接続先。
     *
     * https://{subdomain}.cybozu.com/k/v1/...
     */
    $url =
        'https://'
        . $config['host']
        . $path;

    /*
     * X-Cybozu-Authorization:
     *
     * Base64(login:password)
     */
    $authorization = base64_encode(
        $config['username']
        . ':'
        . $config['password']
    );

    $headers = [
        'X-Cybozu-Authorization: '
            . $authorization,
        'Accept: application/json',
    ];

    if ($body !== null) {
        $headers[] =
            'Content-Type: application/json';
    }

    $options = [
        'method' =>
            strtoupper($method),

        'header' =>
            implode("\r\n", $headers),

        'timeout' => 15,

        'ignore_errors' => true,
    ];

    /*
     * SSL証明書検証。
     */
    $options['ssl'] = [
        'verify_peer' =>
            $config['verify_ssl'],

        'verify_peer_name' =>
            $config['verify_ssl'],

        'allow_self_signed' =>
            !$config['verify_ssl'],
    ];

    /*
     * Proxy。
     */
    if ($config['proxy'] !== '') {
        $options['proxy'] =
            'tcp://'
            . $config['proxy'];

        $options['request_fulluri'] = true;
    }

    /*
     * GET/POST等のbody。
     */
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

    $httpResponseHeaders = [];

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    /*
     * file_get_contents() が使用した
     * HTTPレスポンスヘッダーを取得。
     */
    if (
        isset($http_response_header)
        && is_array($http_response_header)
    ) {
        $httpResponseHeaders =
            $http_response_header;
    }

    $status = 0;

    foreach (
        $httpResponseHeaders
        as $header
    ) {
        if (
            preg_match(
                '#^HTTP/\S+\s+(\d+)#',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
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
 * kintone設定保存
 * ========================================================= */

function saveKintoneConfig(): void
{
    $current = settings();

    $old =
        is_array(
            $current['kintone'] ?? null
        )
            ? $current['kintone']
            : [];

    $password =
        (string)post(
            'password',
            ''
        );

    $config = [
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

        /*
         * パスワード空欄なら既存値を維持。
         */
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
                post(
                    'mapping',
                    null
                )
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

    /*
     * ここで正規化・検証。
     *
     * 保存されるhost/subdomainは
     * kintone API通信に利用可能な値になる。
     */
    $validated =
        validateKintoneConfig(
            $config
        );

    /*
     * 元の画面項目名との互換性を維持。
     */
    $config['subdomain'] =
        $validated['subdomain'];

    $config['host'] =
        $validated['host'];

    $current['kintone'] =
        $config;

    saveSettings($current);
}


/* =========================================================
 * kintone 接続テスト
 * ========================================================= */

function testKintone(): string
{
    $config =
        settings()['kintone']
        ?? [];

    $config =
        validateKintoneConfig(
            $config
        );

    $result = kintoneRequest(
        'GET',
        '/k/v1/app.json?'
        . http_build_query([
            'id' => $config['app_id'],
        ]),
        $config
    );

    $status =
        (int)$result['status'];

    if (
        $status >= 200
        && $status < 300
    ) {
        return
            'kintoneへの接続に成功しました。';
    }

    /*
     * 認証情報は表示しない。
     */
    return match (true) {
        $status === 401 =>
            'kintone認証に失敗しました。'
            . 'ログイン名・パスワードを確認してください。',

        $status === 403 =>
            'kintone APIへの権限がありません。',

        $status === 404 =>
            '指定されたkintoneアプリが見つかりません。'
            . 'サブドメインとアプリIDを確認してください。',

        $status >= 500 =>
            'kintoneサーバーでエラーが発生しました。',

        $status === 0 =>
            'kintoneサーバーへ接続できません。'
            . 'ネットワーク、Proxy、SSL設定を確認してください。',

        default =>
            'kintone接続に失敗しました。'
            . 'HTTPステータス: '
            . $status,
    };
}


/* =========================================================
 * kintone フィールド取得
 * ========================================================= */

function getKintoneFields(): array
{
    $config =
        settings()['kintone']
        ?? [];

    $config =
        validateKintoneConfig(
            $config
        );

    $result = kintoneRequest(
        'GET',
        '/k/v1/app/form/fields.json?'
        . http_build_query([
            'app' => $config['app_id'],
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
 * kintone 顧客情報取得
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
    $allSettings =
        settings();

    $config =
        $allSettings['kintone']
        ?? [];

    $config =
        validateKintoneConfig(
            $config
        );

    $mapping =
        is_array(
            $config['mapping'] ?? null
        )
            ? $config['mapping']
            : [];

    $fields = [
        'organization' =>
            trim((string)(
                $mapping['organization']
                ?? ''
            )),

        'name' =>
            trim((string)(
                $mapping['name']
                ?? ''
            )),

        'email' =>
            trim((string)(
                $mapping['email']
                ?? ''
            )),

        'department' =>
            trim((string)(
                $mapping['department']
                ?? ''
            )),

        'phone' =>
            trim((string)(
                $mapping['phone']
                ?? ''
            )),

        'address' =>
            is_array(
                $mapping['address']
                ?? null
            )
                ? $mapping['address']
                : [],
    ];

    $query = [
        'app' =>
            $config['app_id'],

        'totalCount' =>
            'true',

        'limit' =>
            500,

        'offset' =>
            0,
    ];

    $all = [];

    do {
        $result =
            kintoneRequest(
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
            if (!is_array($record)) {
                continue;
            }

            $addressParts = [];

            foreach (
                $fields['address']
                as $code
            ) {
                $code =
                    trim((string)$code);

                if ($code === '') {
                    continue;
                }

                $value =
                    kintoneFieldValue(
                        $record,
                        $code
                    );

                if ($value !== '') {
                    $addressParts[] =
                        $value;
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
                    implode(
                        ' ',
                        $addressParts
                    ),

                'synced_at' =>
                    now(),
            ];
        }

        $count =
            count($records);

        $query['offset'] +=
            $count;

        if ($count < 500) {
            break;
        }

    } while (true);

    saveCustomers($all);

    return count($all);
}


/* =========================================================
 * 起動
 * ========================================================= */

try {
    ensureDataFiles();

    /*
     * ここから既存のアンケート処理、
     * 画面レンダリング、
     * SMTP処理、
     * 回答処理、
     * CSV/PDF処理等を続ける。
     *
     * 重要なのは、
     *
     *   CSRF処理を入れない
     *   管理者ログインを入れない
     *   初回設定を入れない
     *
     * 一方で、
     *
     *   kintone
     *   SMTP
     *
     * はPOCでも実サービスへ接続する、
     * という最新要件を維持すること。
     */

} catch (InvalidArgumentException $e) {

    /*
     * 入力エラーは500にしない。
     */
    http_response_code(400);

    echo '<!doctype html>';
    echo '<meta charset="UTF-8">';
    echo '<title>' . e(APP_NAME) . '</title>';
    echo '<style>
        body {
            margin: 0;
            padding: 40px;
            background: #f8fafc;
            color: #1e293b;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Noto Sans JP",
                "Hiragino Kaku Gothic ProN",
                Meiryo,
                sans-serif;
        }

        .error {
            max-width: 760px;
            margin: 40px auto;
            padding: 24px;
            background: #fff;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            box-shadow:
                0 4px 18px rgba(15, 23, 42, .08);
        }

        .error h1 {
            margin-top: 0;
            color: #dc2626;
            font-size: 20px;
        }
    </style>';

    echo '<div class="error">';
    echo '<h1>入力内容を確認してください</h1>';
    echo '<p>' . e($e->getMessage()) . '</p>';
    echo '</div>';

} catch (Throwable $e) {

    /*
     * POCでも認証情報をエラー画面に出さない。
     */
    error_log(
        '[survey-app] '
        . get_class($e)
        . ': '
        . $e->getMessage()
    );

    http_response_code(500);

    echo '<!doctype html>';
    echo '<meta charset="UTF-8">';
    echo '<title>' . e(APP_NAME) . '</title>';
    echo '<style>
        body {
            margin: 0;
            padding: 40px;
            background: #f8fafc;
            color: #1e293b;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Noto Sans JP",
                "Hiragino Kaku Gothic ProN",
                Meiryo,
                sans-serif;
        }

        .error {
            max-width: 760px;
            margin: 40px auto;
            padding: 24px;
            background: #fff;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            box-shadow:
                0 4px 18px rgba(15, 23, 42, .08);
        }

        .error h1 {
            margin-top: 0;
            color: #dc2626;
            font-size: 20px;
        }
    </style>';

    echo '<div class="error">';
    echo '<h1>システムエラー</h1>';
    echo '<p>処理中にエラーが発生しました。</p>';
    echo '</div>';
}
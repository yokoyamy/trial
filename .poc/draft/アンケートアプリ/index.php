<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR  = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SET_FILE  = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT    = 30;

/*
 * APP_ENCRYPTION_KEY
 *
 * kintone / SMTP のパスワードを平文保存しないための
 * アプリケーション暗号化キー。
 *
 * 環境変数からのみ取得する。
 * ソースコードやURL、settings.jsonには保存しない。
 */
function encryption_key(): string
{
    $key = getenv('APP_ENCRYPTION_KEY');

    if ($key === false || trim($key) === '') {
        throw new RuntimeException(
            'アプリケーション暗号化キーが設定されていません。'
        );
    }

    $key = trim($key);

    /*
     * AES-256-GCM用にSHA-256で固定長化する。
     * APP_ENCRYPTION_KEY自体はログ・画面には出力しない。
     */
    return hash('sha256', $key, true);
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $key = encryption_key();

    $iv = random_bytes(12);
    $tag = '';

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException(
            '機密情報の暗号化に失敗しました。'
        );
    }

    return base64_encode(
        json_encode(
            [
                'v'   => 1,
                'iv'  => base64_encode($iv),
                'tag' => base64_encode($tag),
                'data'=> base64_encode($cipher),
            ],
            JSON_UNESCAPED_SLASHES
        )
    );
}

function decrypt_secret(string $encrypted): string
{
    if ($encrypted === '') {
        return '';
    }

    /*
     * 旧形式との互換性を持たせず、
     * 暗号化された値だけを受け付ける。
     */
    $decoded = base64_decode($encrypted, true);

    if ($decoded === false) {
        throw new RuntimeException(
            '保存された機密情報を復号できません。'
        );
    }

    $payload = json_decode($decoded, true);

    if (!is_array($payload)
        || (int)($payload['v'] ?? 0) !== 1
        || !isset(
            $payload['iv'],
            $payload['tag'],
            $payload['data']
        )
    ) {
        throw new RuntimeException(
            '保存された機密情報の形式が不正です。'
        );
    }

    $key = encryption_key();

    $iv = base64_decode(
        (string)$payload['iv'],
        true
    );

    $tag = base64_decode(
        (string)$payload['tag'],
        true
    );

    $cipher = base64_decode(
        (string)$payload['data'],
        true
    );

    if (
        $iv === false ||
        $tag === false ||
        $cipher === false
    ) {
        throw new RuntimeException(
            '保存された機密情報を復号できません。'
        );
    }

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plain === false) {
        throw new RuntimeException(
            '暗号化キーが正しくないか、保存データが破損しています。'
        );
    }

    return $plain;
}

/*
 * kintone設定をWeb表示用に変換。
 *
 * パスワードそのものをHTMLへ出力しない。
 */
function kintone_view_config(array $config): array
{
    $out = $config;

    $out['password'] = '';

    return $out;
}

/*
 * settings.jsonへ保存する直前に機密情報を暗号化。
 */
function prepare_settings_for_save(array $settings): array
{
    if (isset($settings['kintone']['password'])) {
        $password =
            (string)$settings['kintone']['password'];

        if (
            $password !== '' &&
            !is_encrypted_secret($password)
        ) {
            $settings['kintone']['password'] =
                encrypt_secret($password);
        }
    }

    if (isset($settings['mail']['password'])) {
        $password =
            (string)$settings['mail']['password'];

        if (
            $password !== '' &&
            !is_encrypted_secret($password)
        ) {
            $settings['mail']['password'] =
                encrypt_secret($password);
        }
    }

    return $settings;
}

function is_encrypted_secret(string $value): bool
{
    if ($value === '') {
        return false;
    }

    $decoded = base64_decode($value, true);

    if ($decoded === false) {
        return false;
    }

    $payload = json_decode($decoded, true);

    return is_array($payload)
        && (int)($payload['v'] ?? 0) === 1
        && isset(
            $payload['iv'],
            $payload['tag'],
            $payload['data']
        );
}

function prepare_settings_for_runtime(
    array $settings
): array {
    if (
        isset($settings['kintone']['password']) &&
        is_encrypted_secret(
            (string)$settings['kintone']['password']
        )
    ) {
        $settings['kintone']['password'] =
            decrypt_secret(
                (string)$settings['kintone']['password']
            );
    }

    if (
        isset($settings['mail']['password']) &&
        is_encrypted_secret(
            (string)$settings['mail']['password']
        )
    ) {
        $settings['mail']['password'] =
            decrypt_secret(
                (string)$settings['mail']['password']
            );
    }

    return $settings;
}

/*
 * 設定保存。
 *
 * 実際に保存するのは暗号化済み設定。
 */
function save_settings(array $settings): void
{
    $settings =
        prepare_settings_for_save($settings);

    /*
     * ここでは既存実装のsave_json()を利用する。
     */
    save_json(
        SET_FILE,
        $settings
    );
}

/*
 * kintone通信は画面遷移を行わない。
 *
 * 成功・認証エラー・APIエラー・302/303・
 * 通信不能を呼び出し側へ返す。
 */
function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    /*
     * validate_kintone() は既存の共通検証関数を使用。
     */
    $errors = validate_kintone($config, true);

    if ($errors) {
        throw new RuntimeException(
            implode("\n", $errors)
        );
    }

    $subdomain =
        normalize_kintone_subdomain(
            (string)$config['subdomain']
        );

    $url =
        'https://' .
        $subdomain .
        '.cybozu.com' .
        $path;

    $authorization = base64_encode(
        (string)$config['username'] .
        ':' .
        (string)$config['password']
    );

    $headers = [
        'X-Cybozu-Authorization: ' .
            $authorization,
        'Accept: application/json',
        'Connection: close',
    ];

    /*
     * 以下は既存のstream_context方式を使用。
     * PHP cURLは禁止というprompt.txtの制約に適合。
     */

    // ...
}

/*
 * 顧客同期。
 *
 * 「取得して保存する」だけでなく、
 * 同期結果を呼び出し元へ返す。
 */
function sync_kintone_customers(
    array $config
): array {
    $response = kintone_records($config);

    $records =
        $response['body']['records'] ?? null;

    if (!is_array($records)) {
        throw new RuntimeException(
            'kintoneレコードを取得できませんでした。'
        );
    }

    $mapping =
        $config['mapping'] ?? [];

    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $customers[] = [
            'id' => uid('customer'),
            'organization' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['organization'] ?? ''
                    )
                ),
            'name' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['name'] ?? ''
                    )
                ),
            'email' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['email'] ?? ''
                    )
                ),
            'department' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['department'] ?? ''
                    )
                ),
            'phone' =>
                krecord(
                    $record,
                    (string)(
                        $mapping['phone'] ?? ''
                    )
                ),
            'address' =>
                implode(
                    ' ',
                    array_filter(
                        array_map(
                            static function ($code)
                                use ($record): string {
                                return krecord(
                                    $record,
                                    (string)$code
                                );
                            },
                            is_array(
                                $mapping['address'] ?? null
                            )
                                ? $mapping['address']
                                : []
                        )
                    )
                ),
            'syncedAt' => now(),
        ];
    }

    return $customers;
}

/*
 * POST処理：
 *
 * POST
 *  ↓
 * 入力検証
 *  ↓
 * kintone通信
 *  ↓
 * データ保存
 *  ↓
 * 処理結果確定
 *  ↓
 * 顧客一覧を表示
 *
 * 外部通信関数からheader(Location)は呼ばない。
 */
case 'sync_kintone':

    try {
        $customers =
            sync_kintone_customers(
                $settings['kintone']
            );

        $data['customers'] = $customers;

        $settings['kintone']['last_sync'] =
            now();

        save_data($data);
        save_settings($settings);

        /*
         * 同期結果そのものをPOST処理の結果として保持。
         * kintone設定画面へ戻すだけにはしない。
         */
        $_SESSION['sync_result'] = [
            'success' => true,
            'count'   => count($customers),
        ];

        /*
         * 顧客一覧画面を表示する。
         *
         * prompt.txtでは顧客同期後、
         * 同期した顧客情報を元に一覧表示することが要求される。
         */
        return [
            'screen' => 'customers'
        ];

    } catch (Throwable $e) {

        flash(
            'error',
            'kintone同期失敗：' .
            safe_external_error($e)
        );

        return [
            'screen' => 'kintone'
        ];
    }

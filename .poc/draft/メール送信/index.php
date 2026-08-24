<?php
declare(strict_types=1);

/**
 * SMTP Test Mailer
 * PHP 8.5 / Apache 2.4
 * 外部ライブラリ・データベース不要
 */

$result = null;
$error = null;

/**
 * SMTPサーバーから1行受信
 */
function smtp_read_line($socket): string
{
    $line = fgets($socket, 515);

    if ($line === false) {
        throw new RuntimeException('SMTPサーバーから応答を受信できませんでした。');
    }

    return rtrim($line, "\r\n");
}

/**
 * SMTP応答を最後の行まで読み取る
 */
function smtp_read_response($socket): string
{
    $response = '';

    while (true) {
        $line = smtp_read_line($socket);
        $response .= $line . "\n";

        // "250-" のような複数行応答の場合は継続
        if (isset($line[3]) && $line[3] === '-') {
            continue;
        }

        break;
    }

    return trim($response);
}

/**
 * SMTPコマンド送信
 */
function smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");

    $response = smtp_read_response($socket);

    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException(
            "SMTPエラー: {$response}"
        );
    }

    return $response;
}

/**
 * メールアドレスを簡易検証
 */
function validate_email(string $email, string $name): void
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            "{$name}のメールアドレスが正しくありません。"
        );
    }
}

/**
 * MIMEヘッダー用エンコード
 */
function encode_header(string $value): string
{
    if ($value === '') {
        return '';
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/**
 * SMTP DATA用のドットエスケープ
 */
function smtp_dot_escape(string $data): string
{
    return preg_replace(
        '/^\./m',
        '..',
        $data
    ) ?? $data;
}

/**
 * CRLFへ統一
 */
function normalize_crlf(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return str_replace("\n", "\r\n", $text);
}

/**
 * SMTP接続
 */
function smtp_connect(
    string $host,
    int $port,
    string $encryption,
    int $timeout = 15
) {
    $errno = 0;
    $errstr = '';

    /*
     * ssl:// は SMTPS（通常465）
     * STARTTLSの場合は通常のTCP接続後にSTARTTLS
     */
    if ($encryption === 'ssl') {
        $target = 'ssl://' . $host;
    } else {
        $target = $host;
    }

    $socket = @stream_socket_client(
        $target . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            "SMTPサーバーへ接続できませんでした。"
            . " ($errno: $errstr)"
        );
    }

    stream_set_timeout($socket, $timeout);

    return $socket;
}

/**
 * SMTP処理
 */
function send_smtp_mail(array $data): array
{
    $host       = trim($data['host'] ?? '');
    $port       = (int)($data['port'] ?? 587);
    $encryption = $data['encryption'] ?? 'starttls';
    $username   = trim($data['username'] ?? '');
    $password   = $data['password'] ?? '';
    $from       = trim($data['from'] ?? '');
    $to         = trim($data['to'] ?? '');
    $subject    = $data['subject'] ?? '';
    $body       = $data['body'] ?? '';

    if ($host === '') {
        throw new InvalidArgumentException('SMTPサーバーを入力してください。');
    }

    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('ポート番号が不正です。');
    }

    if (!in_array($encryption, ['none', 'starttls', 'ssl'], true)) {
        throw new InvalidArgumentException('暗号化方式が不正です。');
    }

    validate_email($from, '送信元');
    validate_email($to, '宛先');

    if ($subject === '') {
        throw new InvalidArgumentException('件名を入力してください。');
    }

    if ($body === '') {
        throw new InvalidArgumentException('本文を入力してください。');
    }

    $socket = null;

    try {
        $socket = smtp_connect(
            $host,
            $port,
            $encryption
        );

        /*
         * SMTPサーバーの初期応答
         */
        $response = smtp_read_response($socket);

        if ((int)substr($response, 0, 3) !== 220) {
            throw new RuntimeException(
                "SMTP初期応答エラー: {$response}"
            );
        }

        /*
         * EHLO
         */
        $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';

        $response = smtp_command(
            $socket,
            "EHLO {$hostname}",
            [250]
        );

        /*
         * STARTTLS
         */
        if ($encryption === 'starttls') {
            smtp_command(
                $socket,
                'STARTTLS',
                [220]
            );

            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;

            $result = @stream_socket_enable_crypto(
                $socket,
                true,
                $crypto
            );

            if ($result !== true) {
                throw new RuntimeException(
                    'STARTTLSによるTLS接続に失敗しました。'
                );
            }

            /*
             * TLS開始後に再度EHLO
             */
            smtp_command(
                $socket,
                "EHLO {$hostname}",
                [250]
            );
        }

        /*
         * SMTP AUTH
         *
         * AUTH LOGINを試す
         */
        if ($username !== '') {
            smtp_command(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            smtp_command(
                $socket,
                base64_encode($username),
                [334]
            );

            smtp_command(
                $socket,
                base64_encode($password),
                [235]
            );
        }

        /*
         * MAIL FROM
         */
        smtp_command(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        /*
         * RCPT TO
         */
        smtp_command(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        /*
         * DATA
         */
        smtp_command(
            $socket,
            'DATA',
            [354]
        );

        /*
         * メール本文
         */
        $headers = [];

        $headers[] = 'From: ' . $from;
        $headers[] = 'To: ' . $to;
        $headers[] = 'Subject: ' . encode_header($subject);
        $headers[] = 'Date: ' . date(DATE_RFC2822);
        $headers[] = 'Message-ID: <'
            . bin2hex(random_bytes(16))
            . '@'
            . preg_replace('/[^a-zA-Z0-9.-]/', '', $host)
            . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $mailData =
            implode("\r\n", $headers)
            . "\r\n\r\n"
            . normalize_crlf($body);

        $mailData = smtp_dot_escape($mailData);

        fwrite(
            $socket,
            $mailData . "\r\n.\r\n"
        );

        $response = smtp_read_response($socket);

        if ((int)substr($response, 0, 3) !== 250) {
            throw new RuntimeException(
                "メール送信エラー: {$response}"
            );
        }

        /*
         * QUIT
         */
        smtp_command(
            $socket,
            'QUIT',
            [221]
        );

        fclose($socket);

        return [
            'success' => true,
            'message' => 'テストメールを送信しました。',
            'smtp_response' => $response,
        ];

    } catch (Throwable $e) {

        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }

        throw $e;
    }
}

/**
 * POST処理
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
     * CSRF対策
     */
    if (
        !isset($_POST['csrf_token']) ||
        !isset($_SESSION['smtp_test_csrf'])
    ) {
        session_start();
    }

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals(
            $_SESSION['smtp_test_csrf'] ?? '',
            (string)$_POST['csrf_token']
        )
    ) {
        $error = '不正なリクエストです。ページを再読み込みしてください。';
    } else {

        $action = $_POST['action'] ?? '';

        try {

            /*
             * 接続テスト
             */
            if ($action === 'connect') {

                $host = trim($_POST['host'] ?? '');
                $port = (int)($_POST['port'] ?? 587);
                $encryption = $_POST['encryption'] ?? 'starttls';
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';

                if ($host === '') {
                    throw new InvalidArgumentException(
                        'SMTPサーバーを入力してください。'
                    );
                }

                if ($port < 1 || $port > 65535) {
                    throw new InvalidArgumentException(
                        'ポート番号が不正です。'
                    );
                }

                $socket = smtp_connect(
                    $host,
                    $port,
                    $encryption
                );

                $response = smtp_read_response($socket);

                if ((int)substr($response, 0, 3) !== 220) {
                    throw new RuntimeException(
                        "SMTP初期応答エラー: {$response}"
                    );
                }

                $hostname =
                    $_SERVER['SERVER_NAME'] ?? 'localhost';

                $ehlo = smtp_command(
                    $socket,
                    "EHLO {$hostname}",
                    [250]
                );

                if ($encryption === 'starttls') {

                    smtp_command(
                        $socket,
                        'STARTTLS',
                        [220]
                    );

                    $crypto =
                        STREAM_CRYPTO_METHOD_TLS_CLIENT;

                    $cryptoResult =
                        @stream_socket_enable_crypto(
                            $socket,
                            true,
                            $crypto
                        );

                    if ($cryptoResult !== true) {
                        throw new RuntimeException(
                            'STARTTLSによるTLS接続に失敗しました。'
                        );
                    }

                    $ehlo = smtp_command(
                        $socket,
                        "EHLO {$hostname}",
                        [250]
                    );
                }

                /*
                 * ユーザー名が入力されていれば認証テスト
                 */
                if ($username !== '') {

                    smtp_command(
                        $socket,
                        'AUTH LOGIN',
                        [334]
                    );

                    smtp_command(
                        $socket,
                        base64_encode($username),
                        [334]
                    );

                    smtp_command(
                        $socket,
                        base64_encode($password),
                        [235]
                    );

                    $result = [
                        'success' => true,
                        'message' =>
                            'SMTPサーバーへの接続と認証に成功しました。',
                        'smtp_response' => $ehlo,
                    ];

                } else {

                    $result = [
                        'success' => true,
                        'message' =>
                            'SMTPサーバーへの接続に成功しました。',
                        'smtp_response' => $ehlo,
                    ];
                }

                smtp_command(
                    $socket,
                    'QUIT',
                    [221]
                );

                fclose($socket);

            /*
             * メール送信
             */
            } elseif ($action === 'send') {

                $result = send_smtp_mail($_POST);

            } else {
                throw new InvalidArgumentException(
                    '不正な操作です。'
                );
            }

        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

/*
 * セッション開始
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    !isset($_SESSION['smtp_test_csrf'])
) {
    $_SESSION['smtp_test_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['smtp_test_csrf'];

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>SMTP テストメール送信</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 30px 15px;
    background: #f3f4f6;
    color: #222;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
}

.container {
    max-width: 850px;
    margin: 0 auto;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 25px;
    box-shadow:
        0 2px 10px rgba(0, 0, 0, .08);
}

h1 {
    margin-top: 0;
    margin-bottom: 25px;
    font-size: 24px;
}

h2 {
    margin-top: 30px;
    font-size: 18px;
    border-bottom: 1px solid #ddd;
    padding-bottom: 8px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 180px;
    gap: 15px;
}

.form-group {
    margin-bottom: 17px;
}

label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
}

input,
select,
textarea {
    width: 100%;
    border: 1px solid #cfd3d8;
    border-radius: 7px;
    padding: 10px 12px;
    font-size: 15px;
    background: #fff;
}

input:focus,
select:focus,
textarea:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow:
        0 0 0 3px rgba(37, 99, 235, .12);
}

textarea {
    min-height: 180px;
    resize: vertical;
}

.buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 25px;
}

button {
    border: 0;
    border-radius: 7px;
    padding: 11px 20px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}

.btn-test {
    background: #2563eb;
    color: white;
}

.btn-send {
    background: #16a34a;
    color: white;
}

button:hover {
    opacity: .9;
}

.result {
    margin-bottom: 20px;
    padding: 15px;
    border-radius: 8px;
}

.result.success {
    background: #ecfdf5;
    border: 1px solid #86efac;
    color: #166534;
}

.result.error {
    background: #fef2f2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}

.response {
    margin-top: 10px;
    background: #111827;
    color: #d1d5db;
    padding: 12px;
    border-radius: 6px;
    white-space: pre-wrap;
    word-break: break-all;
    font-family: monospace;
    font-size: 12px;
    max-height: 250px;
    overflow: auto;
}

.note {
    margin-top: 8px;
    color: #6b7280;
    font-size: 13px;
}

.warning {
    margin-top: 20px;
    padding: 12px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    color: #9a3412;
    border-radius: 7px;
    font-size: 13px;
}

@media (max-width: 600px) {
    body {
        padding: 10px;
    }

    .card {
        padding: 18px;
    }

    .form-row {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<div class="container">

<div class="card">

<h1>SMTP テストメール送信</h1>

<?php if ($result !== null): ?>

<div class="result success">

<strong>
<?= htmlspecialchars(
    $result['message'],
    ENT_QUOTES,
    'UTF-8'
) ?>
</strong>

<?php if (!empty($result['smtp_response'])): ?>

<div class="response"><?= htmlspecialchars(
    $result['smtp_response'],
    ENT_QUOTES,
    'UTF-8'
) ?></div>

<?php endif; ?>

</div>

<?php endif; ?>


<?php if ($error !== null): ?>

<div class="result error">

<strong>エラー</strong>

<div style="margin-top:6px;">
<?= htmlspecialchars(
    $error,
    ENT_QUOTES,
    'UTF-8'
) ?>
</div>

</div>

<?php endif; ?>


<form method="post">

<input
    type="hidden"
    name="csrf_token"
    value="<?= htmlspecialchars(
        $csrfToken,
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>


<h2>SMTP設定</h2>


<div class="form-row">

<div class="form-group">

<label for="host">
SMTPサーバー
</label>

<input
    type="text"
    id="host"
    name="host"
    placeholder="smtp.example.com"
    value="<?= htmlspecialchars(
        $_POST['host'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    required
>

</div>


<div class="form-group">

<label for="port">
ポート
</label>

<input
    type="number"
    id="port"
    name="port"
    min="1"
    max="65535"
    value="<?= htmlspecialchars(
        $_POST['port'] ?? '587',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    required
>

</div>

</div>


<div class="form-group">

<label for="encryption">
暗号化
</label>

<select
    id="encryption"
    name="encryption"
>

<option
    value="starttls"
    <?= ($_POST['encryption'] ?? 'starttls')
        === 'starttls'
        ? 'selected'
        : '' ?>
>
STARTTLS
</option>

<option
    value="ssl"
    <?= ($_POST['encryption'] ?? '')
        === 'ssl'
        ? 'selected'
        : '' ?>
>
SSL/TLS
</option>

<option
    value="none"
    <?= ($_POST['encryption'] ?? '')
        === 'none'
        ? 'selected'
        : '' ?>
>
暗号化なし
</option>

</select>

<div class="note">
587 = STARTTLS、465 = SSL/TLS が一般的です。
</div>

</div>


<div class="form-row">

<div class="form-group">

<label for="username">
SMTPユーザー名
</label>

<input
    type="text"
    id="username"
    name="username"
    autocomplete="off"
    value="<?= htmlspecialchars(
        $_POST['username'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>

</div>


<div class="form-group">

<label for="password">
SMTPパスワード
</label>

<input
    type="password"
    id="password"
    name="password"
    autocomplete="new-password"
>

</div>

</div>


<h2>メール設定</h2>


<div class="form-group">

<label for="from">
送信元
</label>

<input
    type="email"
    id="from"
    name="from"
    placeholder="sender@example.com"
    value="<?= htmlspecialchars(
        $_POST['from'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    required
>

</div>


<div class="form-group">

<label for="to">
宛先
</label>

<input
    type="email"
    id="to"
    name="to"
    placeholder="recipient@example.com"
    value="<?= htmlspecialchars(
        $_POST['to'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    required
>

</div>


<div class="form-group">

<label for="subject">
件名
</label>

<input
    type="text"
    id="subject"
    name="subject"
    value="<?= htmlspecialchars(
        $_POST['subject']
            ?? 'SMTPテストメール',
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
    required
>

</div>


<div class="form-group">

<label for="body">
本文
</label>

<textarea
    id="body"
    name="body"
    required
><?= htmlspecialchars(
    $_POST['body']
        ?? "これはSMTP接続テストメールです。\n\nメールサーバーへの接続・認証・送信を確認しました。",
    ENT_QUOTES,
    'UTF-8'
) ?></textarea>

</div>


<div class="buttons">

<button
    type="submit"
    name="action"
    value="connect"
    class="btn-test"
>
SMTP接続テスト
</button>


<button
    type="submit"
    name="action"
    value="send"
    class="btn-send"
>
テストメール送信
</button>

</div>


<div class="warning">
<strong>注意：</strong>
SMTPパスワードは保存されません。
通信中はHTTPSを使用してください。
このページをインターネットへ直接公開する場合は、
Basic認証などでアクセス制限することを推奨します。
</div>

</form>

</div>

</div>

</body>
</html>
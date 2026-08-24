<?php
/*
========================================================================
GUARD COMMENT — 固定名称一覧
※以下の名称は、今後の修正・再生成時も変更・削除禁止。

ストレージ:
- survey_storage_directory
- survey_storage_file
- survey_admin_session_v1

データトップキー:
- surveys
- responses
- customers
- settings
- mail_logs

アンケート項目:
- id
- title
- start_at
- end_at
- status
- created_at
- updated_at
- numbering_mode
- groups
- deleted

グループ項目:
- id
- name
- questions

質問項目:
- id
- text
- type
- required
- options
- other_enabled

質問形式:
- single
- multiple
- text

顧客項目:
- id
- company
- name
- email
- department
- phone
- address
- source
- sent_at
- send_count
- answer_status
- kintone_status

回答項目:
- id
- survey_id
- customer_id
- company
- name
- email
- answered_at
- answers

設定項目:
- subdomain
- login_name
- password
- app_id
- ssl_verify
- proxy
- field_company
- field_name
- field_email
- field_department
- field_phone
- field_address

POST/GETパラメータ:
- action
- survey_id
- customer_id
- response_id
- keyword
- status_filter
- sort
- survey_json
- settings_json
- csrf_token
- recipient_ids
- mail_subject
- mail_body
- template_type
- app_id

API/JSONキー:
- properties
- records
- label
- code
- type
- message
- ok
- fields

HTML DOM ID / JS参照名:
- app
- csrf_token
- survey_title
- survey_start_at
- survey_end_at
- survey_numbering_mode
- question_editor
- preview_modal
- preview_content
- response_modal
- response_detail
- response_filter
- response_table
- customer_filter
- customer_table
- select_all
- mail_subject
- mail_body
- template_type
- settings_form
- settings_json
- setting_subdomain
- setting_app_id
- setting_login_name
- setting_password
- setting_proxy
- setting_ssl_verify
- field_message

取り得る値:
- status: draft / active / ended
- numbering_mode: global / group
- type: single / multiple / text
- source: kintone / web
- answer_status: unanswered / answered
- kintone_status: unregistered / registered
- template_type: initial / reminder
========================================================================
*/

declare(strict_types=1);

const SURVEY_STORAGE_DIRECTORY =
    __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';

const SURVEY_STORAGE_FILE =
    SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';

const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

date_default_timezone_set('Asia/Tokyo');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* ================================================================
 * PHP utility
 * ================================================================ */

function survey_default_data(): array
{
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [
            'subdomain' => '',
            'login_name' => '',
            'password' => '',
            'app_id' => '',
            'ssl_verify' => false,
            'proxy' => '',
            'field_company' => '',
            'field_name' => '',
            'field_email' => '',
            'field_department' => '',
            'field_phone' => '',
            'field_address' => []
        ],
        'mail_logs' => []
    ];
}

function survey_load_data(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        $data = survey_default_data();
        survey_save_data($data);
        return $data;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);

    if ($raw === false || trim($raw) === '') {
        return survey_default_data();
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return survey_default_data();
    }

    $defaults = survey_default_data();

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    if (!is_array($data['surveys'])) {
        $data['surveys'] = [];
    }

    if (!is_array($data['responses'])) {
        $data['responses'] = [];
    }

    if (!is_array($data['customers'])) {
        $data['customers'] = [];
    }

    if (!is_array($data['mail_logs'])) {
        $data['mail_logs'] = [];
    }

    $data['settings'] = array_merge(
        $defaults['settings'],
        is_array($data['settings'] ?? null)
            ? $data['settings']
            : []
    );

    return $data;
}

function survey_save_data(array $data): bool
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_json_response(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function survey_id(string $prefix = 'id'): string
{
    try {
        return $prefix . '_' . bin2hex(random_bytes(10));
    } catch (Throwable) {
        return $prefix . '_' . uniqid('', true);
    }
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
}

function survey_csrf(): string
{
    if (
        !isset($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token']) ||
        $_SESSION['csrf_token'] === ''
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function survey_check_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (
        $token === '' ||
        !hash_equals(survey_csrf(), $token)
    ) {
        survey_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }
}

function survey_clean_host(string $host): string
{
    $host = trim($host);

    $host = preg_replace(
        '#^https?://#i',
        '',
        $host
    );

    $host = preg_replace(
        '#/.*$#',
        '',
        (string)$host
    );

    $host = trim((string)$host);

    if ($host === '') {
        return '';
    }

    /*
     * 以下をすべて許可:
     *
     * jacic
     * jacic.cybozu.com
     * https://jacic.cybozu.com
     */
    if (!preg_match(
        '/\.cybozu\.com$/i',
        $host
    )) {
        $host .= '.cybozu.com';
    }

    return $host;
}

/*
 * PHP 8.4 / 8.5対応。
 * HTTPレスポンスヘッダーからステータスを取得する。
 */
function survey_header_status(): int
{
    $headers = [];

    if (function_exists(
        'http_get_last_response_headers'
    )) {
        $headers = http_get_last_response_headers();
    }

    if (!is_array($headers)) {
        return 0;
    }

    $status = 0;

    foreach ($headers as $header) {
        if (preg_match(
            '/^HTTP\/[\d.]+\s+(\d+)/i',
            (string)$header,
            $matches
        )) {
            $status = (int)$matches[1];
        }
    }

    return $status;
}

/* ================================================================
 * kintone API
 * ================================================================ */

function survey_kintone_request(
    string $method,
    string $url,
    array $settings,
    ?array $body = null
): array {
    $host = survey_clean_host(
        (string)($settings['subdomain'] ?? '')
    );

    if ($host === '') {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'LOCAL_CONFIG',
            'message' => 'kintoneのサブドメインを入力してください。'
        ];
    }

    $path = $url;

    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    $targetUrl =
        'https://' .
        $host .
        $path;

    $login = (string)(
        $settings['login_name'] ?? ''
    );

    $password = (string)(
        $settings['password'] ?? ''
    );

    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'LOCAL_CONFIG',
            'message' =>
                'kintoneのログイン名またはパスワードが設定されていません。'
        ];
    }

    /*
     * X-Cybozu-Authorization:
     * Base64(login_name:password)
     */
    $authorization = base64_encode(
        $login . ':' . $password
    );

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' . $authorization
    ];

    $http = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 30
    ];

    /*
     * GETではContent-Typeを送らない。
     */
    if ($body !== null) {
        try {
            $json = json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => 500,
                'code' => 'LOCAL_JSON',
                'message' => 'JSON生成に失敗しました。'
            ];
        }

        $headers[] = 'Content-Type: application/json';

        $http['header'] = implode(
            "\r\n",
            $headers
        );

        $http['content'] = $json;
    }

    /*
     * Proxy:
     * host:port
     */
    $proxy = trim(
        (string)($settings['proxy'] ?? '')
    );

    if ($proxy !== '') {
        if (!preg_match(
            '/^[^:\/\s]+:\d+$/',
            $proxy
        )) {
            return [
                'ok' => false,
                'status' => 400,
                'code' => 'LOCAL_PROXY',
                'message' =>
                    'Proxyは host:port 形式で指定してください。'
            ];
        }

        $http['proxy'] =
            'tcp://' . $proxy;

        $http['request_fulluri'] = true;
    }

    $sslVerify =
        !empty($settings['ssl_verify']);

    $context = stream_context_create([
        'http' => $http,
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify
        ]
    ]);

    $result = @file_get_contents(
        $targetUrl,
        false,
        $context
    );

    $status = survey_header_status();

    if ($result === false && $status === 0) {
        return [
            'ok' => false,
            'status' => 0,
            'code' => 'LOCAL_CONNECTION',
            'message' =>
                'kintoneへ接続できませんでした。',
            'url' => $targetUrl
        ];
    }

    $decoded = json_decode(
        (string)$result,
        true
    );

    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($status >= 200 && $status < 300) {
        return [
            'ok' => true,
            'status' => $status,
            'data' => $decoded,
            'url' => $targetUrl
        ];
    }

    return [
        'ok' => false,
        'status' => $status,
        'code' => (string)(
            $decoded['code'] ?? ''
        ),
        'message' => (string)(
            $decoded['message'] ??
            'kintone API通信に失敗しました。'
        ),
        'data' => $decoded,
        'url' => $targetUrl
    ];
}

/* ================================================================
 * public URL
 * ================================================================ */

function survey_public_url(
    string $surveyId,
    string $customerId
): string {
    $scheme = (
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    )
        ? 'https'
        : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? 'localhost'
    );

    $script = (string)(
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
    );

    return $scheme .
        '://' .
        $host .
        $script .
        '?public=1' .
        '&survey_id=' .
        rawurlencode($surveyId) .
        '&customer_id=' .
        rawurlencode($customerId);
}

/* ================================================================
 * API
 * ================================================================ */

$action = (string)(
    $_REQUEST['action'] ?? ''
);

$data = survey_load_data();

/*
 * Public回答画面は管理APIより先に処理する。
 */
if (
    isset($_GET['public']) &&
    (string)$_GET['public'] === '1'
) {
    $publicSurveyId = (string)(
        $_GET['survey_id'] ?? ''
    );

    $publicCustomerId = (string)(
        $_GET['customer_id'] ?? ''
    );

    $publicSurvey = null;

    foreach ($data['surveys'] as $survey) {
        if (
            (string)($survey['id'] ?? '') ===
            $publicSurveyId &&
            empty($survey['deleted'])
        ) {
            $publicSurvey = $survey;
            break;
        }
    }

    if (
        !$publicSurvey ||
        ($publicSurvey['status'] ?? '') !== 'active'
    ) {
        http_response_code(404);
        header(
            'Content-Type: text/html; charset=UTF-8'
        );
        echo '<!doctype html><html lang="ja"><meta charset="UTF-8">';
        echo '<title>回答できません</title>';
        echo '<body style="font-family:sans-serif;padding:40px">';
        echo '<h1>このアンケートは現在回答できません。</h1>';
        echo '</body></html>';
        exit;
    }

    $publicCustomer = null;

    foreach ($data['customers'] as $customer) {
        if (
            $publicCustomerId !== '' &&
            (string)($customer['id'] ?? '') ===
            $publicCustomerId
        ) {
            $publicCustomer = $customer;
            break;
        }
    }

    header(
        'Content-Type: text/html; charset=UTF-8'
    );

    $h = static function (
        mixed $value
    ): string {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    };

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($publicSurvey['title'] ?? 'アンケート') ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800">
<div class="max-w-3xl mx-auto p-6">
<div class="bg-white rounded-2xl shadow-sm p-6">
<h1 class="text-2xl font-bold mb-2">
<?= $h($publicSurvey['title'] ?? '') ?>
</h1>

<?php if ($publicCustomer): ?>
<div class="mb-6 text-sm text-slate-500">
<?= $h($publicCustomer['company'] ?? '') ?>
<?= $h($publicCustomer['name'] ?? '') ?> 様
</div>
<?php endif; ?>

<form id="public_form" class="space-y-8">

<?php
$qIndex = 0;

foreach (
    ($publicSurvey['groups'] ?? [])
    as $group
):
?>
<section>
<h2 class="text-lg font-bold border-b pb-2 mb-4">
<?= $h($group['name'] ?? '') ?>
</h2>

<div class="space-y-6">

<?php foreach (
    ($group['questions'] ?? [])
    as $question
):
    $qIndex++;
    $qid = (string)($question['id'] ?? '');
    $required = !empty($question['required']);
?>
<div>
<label class="block font-semibold mb-2">
<span class="text-slate-500 mr-2">
Q<?= $qIndex ?>
</span>
<?= $h($question['text'] ?? '') ?>
<?php if ($required): ?>
<span class="text-red-500 ml-1">*</span>
<?php endif; ?>
</label>

<?php
$type = (string)(
    $question['type'] ?? 'text'
);

if ($type === 'single'):
?>

<div class="space-y-2">
<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>
<label class="flex gap-2 items-center">
<input
type="radio"
name="answers[<?= $h($qid) ?>]"
value="<?= $h($option) ?>"
class="h-4 w-4"
<?= $required ? 'required' : '' ?>>
<span><?= $h($option) ?></span>
</label>
<?php endforeach; ?>

<?php if (!empty($question['other_enabled'])): ?>
<label class="flex gap-2 items-center">
<input
type="radio"
name="answers[<?= $h($qid) ?>]"
value="その他"
class="h-4 w-4">
<span>その他</span>
</label>
<input
type="text"
name="other[<?= $h($qid) ?>]"
placeholder="その他の場合"
class="w-full border rounded-lg px-3 py-2">
<?php endif; ?>
</div>

<?php elseif ($type === 'multiple'): ?>

<div class="space-y-2">
<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>
<label class="flex gap-2 items-center">
<input
type="checkbox"
name="answers[<?= $h($qid) ?>][]"
value="<?= $h($option) ?>"
class="h-4 w-4">
<span><?= $h($option) ?></span>
</label>
<?php endforeach; ?>

<?php if (!empty($question['other_enabled'])): ?>
<label class="flex gap-2 items-center">
<input
type="checkbox"
name="answers[<?= $h($qid) ?>][]"
value="その他"
class="h-4 w-4">
<span>その他</span>
</label>
<?php endif; ?>
</div>

<?php else: ?>

<textarea
name="answers[<?= $h($qid) ?>]"
rows="5"
class="w-full border rounded-xl px-3 py-2"
<?= $required ? 'required' : '' ?>></textarea>

<?php endif; ?>

</div>
<?php endforeach; ?>

</div>
</section>
<?php endforeach; ?>

<div class="pt-4 border-t">
<button
type="submit"
class="w-full bg-indigo-600 text-white rounded-xl px-5 py-3 font-semibold hover:bg-indigo-700">
回答を送信する
</button>
</div>

</form>

<div
id="done"
class="hidden text-center py-12">
<div class="text-4xl mb-4">✓</div>
<h2 class="text-xl font-bold mb-2">
回答を受け付けました
</h2>
<p class="text-slate-500">
ご回答ありがとうございました。
</p>
</div>

</div>
</div>

<script>
document.getElementById('public_form')
.addEventListener('submit', async function(e) {
    e.preventDefault();

    const fd = new FormData(this);
    const answers = {};

    fd.forEach((value, key) => {
        const m = key.match(/^answers\[(.+?)\](?:\[\])?$/);
        if (!m) return;

        const id = m[1];

        if (key.endsWith('[]')) {
            if (!Array.isArray(answers[id])) {
                answers[id] = [];
            }
            answers[id].push(value);
        } else {
            answers[id] = value;
        }
    });

    const body = new URLSearchParams();

    body.set('action', 'public_answer');
    body.set('survey_id',
        <?= json_encode($publicSurveyId, JSON_UNESCAPED_UNICODE) ?>);
    body.set('customer_id',
        <?= json_encode($publicCustomerId, JSON_UNESCAPED_UNICODE) ?>);
    body.set('answers',
        JSON.stringify(answers));

    <?php if ($publicCustomer): ?>
    body.set('company',
        <?= json_encode(
            (string)($publicCustomer['company'] ?? ''),
            JSON_UNESCAPED_UNICODE
        ) ?>);

    body.set('name',
        <?= json_encode(
            (string)($publicCustomer['name'] ?? ''),
            JSON_UNESCAPED_UNICODE
        ) ?>);

    body.set('email',
        <?= json_encode(
            (string)($publicCustomer['email'] ?? ''),
            JSON_UNESCAPED_UNICODE
        ) ?>);
    <?php endif; ?>

    const response = await fetch(
        <?= json_encode(
            $_SERVER['SCRIPT_NAME'] ?? '/index.php'
        ) ?>,
        {
            method: 'POST',
            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded'
            },
            body
        }
    );

    const result = await response.json();

    if (!result.ok) {
        alert(result.message || '送信に失敗しました。');
        return;
    }

    this.classList.add('hidden');
    document.getElementById('done')
        .classList.remove('hidden');
});
</script>
</body>
</html>
<?php
    exit;
}

/* ================================================================
 * 管理API
 * ================================================================ */

if ($action !== '') {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        survey_check_csrf();
    }

    switch ($action) {

        case 'load':
            survey_json_response([
                'ok' => true,
                'data' => $data,
                'csrf_token' => survey_csrf()
            ]);
            break;

        case 'save_survey':

            $survey = json_decode(
                (string)($_POST['survey_json'] ?? ''),
                true
            );

            if (
                !is_array($survey) ||
                empty($survey['id'])
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アンケートデータが不正です。'
                ], 400);
            }

            $now = survey_now();
            $found = false;

            foreach (
                $data['surveys']
                as $index => $existing
            ) {
                if (
                    (string)($existing['id'] ?? '') ===
                    (string)$survey['id']
                ) {
                    $survey['created_at'] =
                        $existing['created_at'] ?? $now;

                    $survey['updated_at'] = $now;

                    $survey['deleted'] =
                        !empty($existing['deleted']);

                    $data['surveys'][$index] =
                        $survey;

                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $survey['created_at'] = $now;
                $survey['updated_at'] = $now;
                $survey['deleted'] = false;

                $data['surveys'][] = $survey;
            }

            if (!survey_save_data($data)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'データ保存に失敗しました。'
                ], 500);
            }

            survey_json_response([
                'ok' => true,
                'survey' => $survey
            ]);
            break;

        case 'status':

            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            $status = (string)(
                $_POST['status'] ?? ''
            );

            if (!in_array(
                $status,
                ['draft', 'active', 'ended'],
                true
            )) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        '不正なステータスです。'
                ], 400);
            }

            foreach (
                $data['surveys']
                as &$survey
            ) {
                if (
                    (string)($survey['id'] ?? '') ===
                    $surveyId
                ) {
                    $survey['status'] = $status;
                    $survey['updated_at'] =
                        survey_now();
                    break;
                }
            }

            unset($survey);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);
            break;

        case 'delete_survey':

            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            foreach (
                $data['surveys']
                as &$survey
            ) {
                if (
                    (string)($survey['id'] ?? '') ===
                    $surveyId
                ) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] =
                        survey_now();
                    break;
                }
            }

            unset($survey);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);
            break;

        case 'save_settings':

            $settings = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (!is_array($settings)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        '設定データが不正です。'
                ], 400);
            }

            $oldPassword =
                (string)($data['settings']['password'] ?? '');

            if (
                ($settings['password'] ?? '') === '' &&
                $oldPassword !== ''
            ) {
                $settings['password'] =
                    $oldPassword;
            }

            $data['settings'] = array_merge(
                survey_default_data()['settings'],
                $settings
            );

            if (!survey_save_data($data)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        '設定保存に失敗しました。'
                ], 500);
            }

            survey_json_response([
                'ok' => true
            ]);
            break;

        case 'kintone_fields':

            $settings = $data['settings'];

            $appId = trim(
                (string)(
                    $_POST['app_id'] ??
                    $settings['app_id'] ??
                    ''
                )
            );

            if ($appId === '') {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アプリIDを入力してください。'
                ], 400);
            }

            if (!preg_match(
                '/^\d+$/',
                $appId
            )) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アプリIDは数字で入力してください。'
                ], 400);
            }

            $query = http_build_query(
                ['app' => $appId],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

            $result = survey_kintone_request(
                'GET',
                '/k/v1/app/form/fields.json?' . $query,
                $settings
            );

            if (!$result['ok']) {
                survey_json_response(
                    $result,
                    400
                );
            }

            $fields = [];

            $properties =
                $result['data']['properties'] ?? [];

            if (!is_array($properties)) {
                survey_json_response([
                    'ok' => false,
                    'status' => 200,
                    'code' =>
                        'LOCAL_RESPONSE',
                    'message' =>
                        'kintoneからpropertiesを取得できませんでした。'
                ], 500);
            }

            /*
             * 顧客マッピングに使えないUI/システムフィールドのみ除外。
             * DROP_DOWN等は除外しない。
             */
            $excludedTypes = [
                'LABEL',
                'SPACER',
                'HR',
                'GROUP',
                'REFERENCE_TABLE',
                'SUBTABLE',
                'RECORD_NUMBER',
                'CREATOR',
                'CREATED_TIME',
                'MODIFIER',
                'UPDATED_TIME'
            ];

            foreach (
                $properties as $code => $field
            ) {
                if (!is_array($field)) {
                    continue;
                }

                $type = (string)(
                    $field['type'] ?? ''
                );

                if (in_array(
                    $type,
                    $excludedTypes,
                    true
                )) {
                    continue;
                }

                $fields[] = [
                    'label' => (string)(
                        $field['label'] ?? $code
                    ),
                    'code' => (string)$code,
                    'type' => $type
                ];
            }

            usort(
                $fields,
                static function (
                    array $a,
                    array $b
                ): int {
                    return strcmp(
                        $a['label'],
                        $b['label']
                    );
                }
            );

            survey_json_response([
                'ok' => true,
                'fields' => $fields,
                'app_id' => $appId
            ]);
            break;

        case 'register_customer':

            $customerId = (string)(
                $_POST['customer_id'] ?? ''
            );

            foreach (
                $data['customers']
                as &$customer
            ) {
                if (
                    (string)($customer['id'] ?? '') ===
                    $customerId
                ) {
                    $customer['kintone_status'] =
                        'registered';
                    break;
                }
            }

            unset($customer);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);
            break;

        case 'send_mail':

            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            $recipientIds = json_decode(
                (string)(
                    $_POST['recipient_ids'] ?? '[]'
                ),
                true
            );

            if (!is_array($recipientIds)) {
                $recipientIds = [];
            }

            $subject = trim(
                (string)(
                    $_POST['mail_subject'] ?? ''
                )
            );

            $body = (string)(
                $_POST['mail_body'] ?? ''
            );

            $templateType = (string)(
                $_POST['template_type'] ?? 'initial'
            );

            if (!in_array(
                $templateType,
                ['initial', 'reminder'],
                true
            )) {
                $templateType = 'initial';
            }

            if (
                $subject === '' ||
                trim($body) === ''
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        '件名と本文を入力してください。'
                ], 400);
            }

            $survey = null;

            foreach (
                $data['surveys']
                as $item
            ) {
                if (
                    (string)($item['id'] ?? '') ===
                    $surveyId
                ) {
                    $survey = $item;
                    break;
                }
            }

            if (!$survey) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アンケートが見つかりません。'
                ], 404);
            }

            $count = 0;
            $messages = [];

            foreach (
                $data['customers']
                as &$customer
            ) {
                $customerId =
                    (string)($customer['id'] ?? '');

                if (!in_array(
                    $customerId,
                    array_map(
                        'strval',
                        $recipientIds
                    ),
                    true
                )) {
                    continue;
                }

                if (
                    ($customer['source'] ?? 'kintone') ===
                    'web'
                ) {
                    continue;
                }

                $email = trim(
                    (string)($customer['email'] ?? '')
                );

                if (
                    $email === '' ||
                    !filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    continue;
                }

                $publicUrl =
                    survey_public_url(
                        $surveyId,
                        $customerId
                    );

                $replace = [
                    '{顧客名}' =>
                        (string)(
                            $customer['name'] ?? ''
                        ),
                    '{アンケートURL}' =>
                        $publicUrl
                ];

                $finalSubject =
                    strtr(
                        $subject,
                        $replace
                    );

                $finalBody =
                    strtr(
                        $body,
                        $replace
                    );

                /*
                 * PHP mail() を使用。
                 * 実運用では環境のSMTP設定が必要。
                 */
                $mailHeaders =
                    "MIME-Version: 1.0\r\n" .
                    "Content-Type: text/plain; charset=UTF-8\r\n";

                $sent = @mail(
                    $email,
                    '=?UTF-8?B?' .
                    base64_encode($finalSubject) .
                    '?=',
                    $finalBody,
                    $mailHeaders
                );

                $customer['sent_at'] =
                    survey_now();

                $customer['send_count'] =
                    (int)(
                        $customer['send_count'] ?? 0
                    ) + 1;

                $customer['answer_status'] =
                    'unanswered';

                $messages[] = [
                    'customer_id' =>
                        $customerId,
                    'subject' =>
                        $finalSubject,
                    'body' =>
                        $finalBody,
                    'sent' =>
                        $sent
                ];

                $count++;
            }

            unset($customer);

            $data['mail_logs'][] = [
                'id' => survey_id('mail'),
                'survey_id' => $surveyId,
                'sent_at' => survey_now(),
                'template_type' => $templateType,
                'count' => $count,
                'subject' => $subject,
                'messages' => $messages,
                'executor' => 'admin'
            ];

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'count' => $count
            ]);
            break;

        case 'public_answer':

            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            $customerId = (string)(
                $_POST['customer_id'] ?? ''
            );

            $survey = null;

            foreach (
                $data['surveys']
                as $item
            ) {
                if (
                    (string)($item['id'] ?? '') ===
                    $surveyId &&
                    empty($item['deleted'])
                ) {
                    $survey = $item;
                    break;
                }
            }

            if (
                !$survey ||
                ($survey['status'] ?? '') !==
                'active'
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'このアンケートは現在回答できません。'
                ], 400);
            }

            $answers = json_decode(
                (string)(
                    $_POST['answers'] ?? '{}'
                ),
                true
            );

            if (!is_array($answers)) {
                $answers = [];
            }

            $company = '';
            $name = '';
            $email = '';
            $found = false;

            foreach (
                $data['customers']
                as &$customer
            ) {
                if (
                    $customerId !== '' &&
                    (string)(
                        $customer['id'] ?? ''
                    ) === $customerId
                ) {
                    $company = (string)(
                        $customer['company'] ?? ''
                    );

                    $name = (string)(
                        $customer['name'] ?? ''
                    );

                    $email = (string)(
                        $customer['email'] ?? ''
                    );

                    $customer['answer_status'] =
                        'answered';

                    $found = true;
                    break;
                }
            }

            unset($customer);

            if (!$found) {
                $company = trim(
                    (string)(
                        $_POST['company'] ?? ''
                    )
                );

                $name = trim(
                    (string)(
                        $_POST['name'] ?? ''
                    )
                );

                $email = trim(
                    (string)(
                        $_POST['email'] ?? ''
                    )
                );

                $customerId =
                    survey_id('customer');

                $data['customers'][] = [
                    'id' => $customerId,
                    'company' => $company,
                    'name' => $name,
                    'email' => $email,
                    'department' => '',
                    'phone' => '',
                    'address' => '',
                    'source' => 'web',
                    'sent_at' => '',
                    'send_count' => 0,
                    'answer_status' => 'answered',
                    'kintone_status' => 'unregistered'
                ];
            }

            $response = [
                'id' => survey_id('response'),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'company' => $company,
                'name' => $name,
                'email' => $email,
                'answered_at' => survey_now(),
                'answers' => $answers
            ];

            $data['responses'][] =
                $response;

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'response_id' =>
                    $response['id']
            ]);
            break;

        case 'csv':

            /*
             * CSVはGET専用。
             * action=csv&survey_id=...
             */
            $surveyId = (string)(
                $_GET['survey_id'] ?? ''
            );

            $survey = null;

            foreach (
                $data['surveys']
                as $item
            ) {
                if (
                    (string)($item['id'] ?? '') ===
                    $surveyId
                ) {
                    $survey = $item;
                    break;
                }
            }

            if (!$survey) {
                http_response_code(404);
                exit('Survey not found');
            }

            $questions = [];

            foreach (
                ($survey['groups'] ?? [])
                as $group
            ) {
                foreach (
                    ($group['questions'] ?? [])
                    as $question
                ) {
                    $questions[] = $question;
                }
            }

            $fp = fopen(
                'php://temp',
                'w+'
            );

            $header = [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名'
            ];

            foreach ($questions as $question) {
                $header[] =
                    (string)(
                        $question['text'] ?? ''
                    );
            }

            fputcsv(
                $fp,
                $header
            );

            foreach (
                $data['responses']
                as $response
            ) {
                if (
                    (string)(
                        $response['survey_id'] ?? ''
                    ) !== $surveyId
                ) {
                    continue;
                }

                $row = [
                    $response['id'] ?? '',
                    $response['answered_at'] ?? '',
                    $response['customer_id'] ?? '',
                    $response['company'] ?? '',
                    $response['name'] ?? ''
                ];

                $answers =
                    $response['answers'] ?? [];

                foreach (
                    $questions
                    as $question
                ) {
                    $answer =
                        $answers[
                            $question['id']
                        ] ?? '';

                    if (is_array($answer)) {
                        $answer =
                            implode(
                                '、',
                                $answer
                            );
                    }

                    $row[] = $answer;
                }

                fputcsv(
                    $fp,
                    $row
                );
            }

            rewind($fp);

            $csv =
                stream_get_contents($fp);

            fclose($fp);

            header_remove(
                'Content-Type'
            );

            header(
                'Content-Type: text/csv; charset=UTF-8'
            );

            header(
                'Content-Disposition: attachment; filename="survey_' .
                rawurlencode($surveyId) .
                '.csv"'
            );

            echo "\xEF\xBB\xBF";
            echo $csv;
            exit;

        default:

            survey_json_response([
                'ok' => false,
                'message' =>
                    '不明なAPIアクションです。'
            ], 400);
    }
}

/* ================================================================
 * SPA
 * ================================================================ */

$csrf = survey_csrf();

header(
    'Content-Type: text/html; charset=UTF-8'
);

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
name="viewport"
content="width=device-width,initial-scale=1">

<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

</head>

<body class="bg-slate-100 text-slate-800">

<div id="app"></div>

<script>
window.App = {
    state: {
        data: null,
        csrf: <?= json_encode(
            $csrf,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?>,
        page: 'list',
        surveyId: null,
        editingSurvey: null,
        fields: [],
        responseSurveyId: null,
        customerSurveyId: null,
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        responseFilter: ''
    },

    api: {},

    actions: {},

    render: {},

    util: {},

    initDone: false
};

/* ================================================================
 * utility
 * ================================================================ */

App.util.escape = function(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.util.clone = function(value) {
    return JSON.parse(JSON.stringify(value));
};

App.util.id = function(prefix) {
    return prefix + '_' +
        Date.now().toString(36) +
        '_' +
        Math.random()
            .toString(36)
            .slice(2, 10);
};

App.util.now = function() {
    const d = new Date();

    const pad = n =>
        String(n).padStart(2, '0');

    return d.getFullYear() +
        '-' + pad(d.getMonth() + 1) +
        '-' + pad(d.getDate()) +
        ' ' + pad(d.getHours()) +
        ':' + pad(d.getMinutes()) +
        ':' + pad(d.getSeconds());
};

App.util.statusLabel = function(status) {
    return {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[status] || status;
};

App.util.statusClass = function(status) {
    return {
        draft:
            'bg-slate-100 text-slate-600',
        active:
            'bg-emerald-100 text-emerald-700',
        ended:
            'bg-amber-100 text-amber-700'
    }[status] ||
        'bg-slate-100 text-slate-600';
};

App.util.typeLabel = function(type) {
    return {
        single: '単一選択',
        multiple: '複数選択',
        text: '自由記述'
    }[type] || type;
};

App.util.findSurvey = function(id) {
    return App.state.data.surveys.find(
        s => String(s.id) === String(id)
    );
};

App.util.questionList = function(survey) {
    const list = [];

    (survey.groups || []).forEach(group => {
        (group.questions || []).forEach(q => {
            list.push(q);
        });
    });

    return list;
};

App.util.responseCount = function(surveyId) {
    return App.state.data.responses.filter(
        r => String(r.survey_id) ===
            String(surveyId)
    ).length;
};

/* ================================================================
 * API
 * ================================================================ */

App.api.request = async function(
    action,
    params = {},
    method = 'POST'
) {
    const body =
        new URLSearchParams();

    body.set('action', action);

    if (method === 'POST') {
        body.set(
            'csrf_token',
            App.state.csrf
        );
    }

    Object.entries(params).forEach(
        ([key, value]) => {
            if (
                typeof value === 'object'
            ) {
                body.set(
                    key,
                    JSON.stringify(value)
                );
            } else {
                body.set(
                    key,
                    String(value)
                );
            }
        }
    );

    const response = await fetch(
        location.pathname,
        {
            method,
            headers:
                method === 'POST'
                    ? {
                        'Content-Type':
                            'application/x-www-form-urlencoded'
                    }
                    : {},
            body:
                method === 'POST'
                    ? body
                    : undefined
        }
    );

    const result =
        await response.json();

    if (
        result.csrf_token
    ) {
        App.state.csrf =
            result.csrf_token;
    }

    if (!result.ok) {
        throw new Error(
            [
                result.message ||
                    '処理に失敗しました。',
                result.code
                    ? 'コード: ' +
                      result.code
                    : '',
                result.status
                    ? 'HTTP: ' +
                      result.status
                    : ''
            ]
            .filter(Boolean)
            .join('\n')
        );
    }

    return result;
};

App.api.load = async function() {
    const result =
        await App.api.request(
            'load',
            {},
            'POST'
        );

    App.state.data =
        result.data;

    if (result.csrf_token) {
        App.state.csrf =
            result.csrf_token;
    }
};

App.api.saveSurvey = async function(
    survey
) {
    await App.api.request(
        'save_survey',
        {
            survey_json:
                JSON.stringify(survey)
        }
    );
};

App.api.saveSettings = async function(
    settings
) {
    await App.api.request(
        'save_settings',
        {
            settings_json:
                JSON.stringify(settings)
        }
    );
};

App.api.status = async function(
    surveyId,
    status
) {
    await App.api.request(
        'status',
        {
            survey_id: surveyId,
            status
        }
    );
};

App.api.deleteSurvey = async function(
    surveyId
) {
    await App.api.request(
        'delete_survey',
        {
            survey_id: surveyId
        }
    );
};

App.api.registerCustomer =
    async function(customerId) {
        await App.api.request(
            'register_customer',
            {
                customer_id:
                    customerId
            }
        );
    };

/*
 * kintone fields取得
 */
App.api.fetchKintoneFields =
    async function(appId) {

        const result =
            await App.api.request(
                'kintone_fields',
                {
                    app_id: appId
                }
            );

        App.state.fields =
            result.fields || [];

        return App.state.fields;
    };

/* ================================================================
 * actions
 * ================================================================ */

App.actions.showList = function() {
    App.state.page = 'list';
    App.state.surveyId = null;
    App.render.main();
};

App.actions.showSettings = function() {
    App.state.page = 'settings';
    App.render.main();
};

App.actions.newSurvey = function() {
    App.state.editingSurvey = {
        id: App.util.id('survey'),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [
            {
                id: App.util.id('group'),
                name: 'グループ1',
                questions: []
            }
        ],
        deleted: false
    };

    App.state.page = 'editor';
    App.render.main();
};

App.actions.editSurvey = function(id) {
    const survey =
        App.util.findSurvey(id);

    if (!survey) return;

    App.state.editingSurvey =
        App.util.clone(survey);

    App.state.page = 'editor';
    App.render.main();
};

App.actions.saveSurvey =
    async function() {
        const survey =
            App.state.editingSurvey;

        survey.updated_at =
            App.util.now();

        if (!survey.created_at) {
            survey.created_at =
                App.util.now();
        }

        try {
            await App.api.saveSurvey(
                survey
            );

            await App.api.load();

            alert('保存しました。');

            App.actions.showList();

        } catch (error) {
            alert(error.message);
        }
    };

App.actions.cancelEditor =
    function() {
        if (
            !confirm(
                '変更を破棄して一覧へ戻りますか？'
            )
        ) {
            return;
        }

        App.actions.showList();
    };

App.actions.changeStatus =
    async function(id, status) {

        const survey =
            App.util.findSurvey(id);

        if (!survey) return;

        const label =
            App.util.statusLabel(status);

        if (
            !confirm(
                '「' +
                survey.title +
                '」を' +
                label +
                'に変更しますか？'
            )
        ) {
            return;
        }

        try {
            await App.api.status(
                id,
                status
            );

            await App.api.load();
            App.render.main();

        } catch (error) {
            alert(error.message);
        }
    };

App.actions.deleteSurvey =
    async function(id) {

        if (
            !confirm(
                'この下書きを削除しますか？'
            )
        ) {
            return;
        }

        try {
            await App.api.deleteSurvey(
                id
            );

            await App.api.load();
            App.render.main();

        } catch (error) {
            alert(error.message);
        }
    };

App.actions.duplicateSurvey =
    async function(id) {

        const original =
            App.util.findSurvey(id);

        if (!original) return;

        const copy =
            App.util.clone(original);

        copy.id =
            App.util.id('survey');

        copy.title =
            original.title +
            '（複製）';

        copy.status =
            'draft';

        copy.deleted =
            false;

        copy.created_at = '';
        copy.updated_at = '';

        copy.groups.forEach(group => {
            group.id =
                App.util.id('group');

            group.questions =
                group.questions || [];

            group.questions.forEach(q => {
                q.id =
                    App.util.id('question');
            });
        });

        try {
            await App.api.saveSurvey(
                copy
            );

            await App.api.load();
            App.render.main();

        } catch (error) {
            alert(error.message);
        }
    };

App.actions.addGroup = function() {

    const survey =
        App.state.editingSurvey;

    survey.groups.push({
        id: App.util.id('group'),
        name:
            'グループ' +
            (survey.groups.length + 1),
        questions: []
    });

    App.render.editor();
    App.actions.initSortables();
};

App.actions.deleteGroup =
    function(groupId) {

        const survey =
            App.state.editingSurvey;

        const group =
            survey.groups.find(
                g => g.id === groupId
            );

        if (!group) return;

        if (
            !confirm(
                'このグループと質問を削除しますか？'
            )
        ) {
            return;
        }

        survey.groups =
            survey.groups.filter(
                g => g.id !== groupId
            );

        if (!survey.groups.length) {
            App.actions.addGroup();
            return;
        }

        App.render.editor();
        App.actions.initSortables();
    };

App.actions.addQuestion =
    function(groupId) {

        const survey =
            App.state.editingSurvey;

        const group =
            survey.groups.find(
                g => g.id === groupId
            );

        if (!group) return;

        group.questions.push({
            id: App.util.id('question'),
            text: '新しい質問',
            type: 'single',
            required: false,
            options: [
                '選択肢1',
                '選択肢2'
            ],
            other_enabled: false
        });

        App.render.editor();
        App.actions.initSortables();
        App.actions.renumber();
    };

App.actions.deleteQuestion =
    function(groupId, questionId) {

        const survey =
            App.state.editingSurvey;

        const group =
            survey.groups.find(
                g => g.id === groupId
            );

        if (!group) return;

        group.questions =
            group.questions.filter(
                q => q.id !== questionId
            );

        App.render.editor();
        App.actions.initSortables();
        App.actions.renumber();
    };

App.actions.updateGroupName =
    function(groupId, value) {

        const group =
            App.state.editingSurvey.groups
                .find(
                    g => g.id === groupId
                );

        if (group) {
            group.name = value;
        }
    };

App.actions.updateQuestion =
    function(
        groupId,
        questionId,
        key,
        value
    ) {

        const group =
            App.state.editingSurvey.groups
                .find(
                    g => g.id === groupId
                );

        if (!group) return;

        const question =
            group.questions.find(
                q => q.id === questionId
            );

        if (!question) return;

        question[key] =
            value;

        if (
            key === 'type' &&
            value === 'text'
        ) {
            question.options = [];
        }

        if (
            key === 'type' &&
            value !== 'text' &&
            !question.options.length
        ) {
            question.options = [
                '選択肢1',
                '選択肢2'
            ];
        }
    };

App.actions.addOption =
    function(groupId, questionId) {

        const group =
            App.state.editingSurvey.groups
                .find(
                    g => g.id === groupId
                );

        const question =
            group?.questions.find(
                q => q.id === questionId
            );

        if (!question) return;

        question.options.push(
            '選択肢' +
            (question.options.length + 1)
        );

        App.render.editor();
        App.actions.initSortables();
    };

App.actions.removeOption =
    function(
        groupId,
        questionId,
        index
    ) {

        const group =
            App.state.editingSurvey.groups
                .find(
                    g => g.id === groupId
                );

        const question =
            group?.questions.find(
                q => q.id === questionId
            );

        if (!question) return;

        question.options.splice(
            index,
            1
        );

        App.render.editor();
        App.actions.initSortables();
    };

App.actions.updateOption =
    function(
        groupId,
        questionId,
        index,
        value
    ) {

        const group =
            App.state.editingSurvey.groups
                .find(
                    g => g.id === groupId
                );

        const question =
            group?.questions.find(
                q => q.id === questionId
            );

        if (!question) return;

        question.options[index] =
            value;
    };

/*
 * Q1, Q2...
 * または
 * Q1-1, Q1-2...
 */
App.actions.renumber =
    function() {

        const survey =
            App.state.editingSurvey;

        let global = 0;

        survey.groups.forEach(
            (group, groupIndex) => {

                group.questions.forEach(
                    (question, questionIndex) => {

                        global++;

                        question.number =
                            survey.numbering_mode ===
                            'group'
                                ? 'Q' +
                                  (groupIndex + 1) +
                                  '-' +
                                  (questionIndex + 1)
                                : 'Q' +
                                  global;
                    }
                );
            }
        );

        document
            .querySelectorAll(
                '[data-question-number]'
            )
            .forEach(el => {
                const id =
                    el.dataset.questionNumber;

                let question = null;

                survey.groups.forEach(g => {
                    const q =
                        g.questions.find(
                            x =>
                                x.id === id
                        );

                    if (q) {
                        question = q;
                    }
                });

                if (question) {
                    el.textContent =
                        question.number ||
                        '';
                }
            });
    };

/*
 * SortableJS
 */
App.actions.initSortables =
    function() {

        if (
            typeof Sortable ===
            'undefined'
        ) {
            return;
        }

        const groupList =
            document.getElementById(
                'question_editor'
            );

        if (!groupList) return;

        new Sortable(
            groupList,
            {
                animation: 180,
                handle: '.group-handle',
                ghostClass:
                    'opacity-40',
                onEnd: function(evt) {

                    const survey =
                        App.state.editingSurvey;

                    const moved =
                        survey.groups.splice(
                            evt.oldIndex,
                            1
                        )[0];

                    survey.groups.splice(
                        evt.newIndex,
                        0,
                        moved
                    );

                    App.actions.renumber();
                }
            }
        );

        groupList
            .querySelectorAll(
                '[data-question-list]'
            )
            .forEach(list => {

                const groupId =
                    list.dataset.questionList;

                new Sortable(
                    list,
                    {
                        group:
                            'survey_questions',
                        animation: 180,
                        handle:
                            '.question-handle',
                        ghostClass:
                            'opacity-40',

                        onEnd: function(evt) {

                            const survey =
                                App.state.editingSurvey;

                            let fromGroup =
                                survey.groups.find(
                                    g =>
                                        g.id ===
                                        groupId
                                );

                            let toGroup =
                                survey.groups.find(
                                    g =>
                                        g.id ===
                                        evt.to
                                            .dataset
                                            .questionList
                                );

                            if (
                                !fromGroup ||
                                !toGroup
                            ) {
                                return;
                            }

                            const moved =
                                fromGroup.questions
                                    .splice(
                                        evt.oldIndex,
                                        1
                                    )[0];

                            toGroup.questions
                                .splice(
                                    evt.newIndex,
                                    0,
                                    moved
                                );

                            App.actions.renumber();
                            App.render.editor();
                            App.actions.initSortables();
                        }
                    }
                );
            });
    };

/* ================================================================
 * settings
 * ================================================================ */

App.actions.fetchKintoneFields =
    async function() {

        const appId =
            document.getElementById(
                'setting_app_id'
            )?.value.trim();

        if (!appId) {
            alert(
                'アプリIDを入力してください。'
            );
            return;
        }

        const message =
            document.getElementById(
                'field_message'
            );

        if (message) {
            message.textContent =
                '取得中...';
        }

        try {

            const fields =
                await App.api.fetchKintoneFields(
                    appId
                );

            App.render.settingsFields(
                fields
            );

            if (message) {
                message.textContent =
                    fields.length +
                    '件取得しました。';
            }

        } catch (error) {

            if (message) {
                message.textContent =
                    error.message;
            }

            alert(error.message);
        }
    };

App.actions.saveSettings =
    async function() {

        const settings =
            App.state.data.settings;

        const form =
            document.getElementById(
                'settings_form'
            );

        if (!form) return;

        const fd =
            new FormData(form);

        settings.subdomain =
            String(
                fd.get(
                    'setting_subdomain'
                ) || ''
            ).trim();

        settings.app_id =
            String(
                fd.get(
                    'setting_app_id'
                ) || ''
            ).trim();

        settings.login_name =
            String(
                fd.get(
                    'setting_login_name'
                ) || ''
            ).trim();

        const password =
            String(
                fd.get(
                    'setting_password'
                ) || ''
            );

        if (password !== '') {
            settings.password =
                password;
        }

        settings.proxy =
            String(
                fd.get(
                    'setting_proxy'
                ) || ''
            ).trim();

        settings.ssl_verify =
            fd.get(
                'setting_ssl_verify'
            ) === '1';

        settings.field_company =
            String(
                fd.get(
                    'field_company'
                ) || ''
            );

        settings.field_name =
            String(
                fd.get(
                    'field_name'
                ) || ''
            );

        settings.field_email =
            String(
                fd.get(
                    'field_email'
                ) || ''
            );

        settings.field_department =
            String(
                fd.get(
                    'field_department'
                ) || ''
            );

        settings.field_phone =
            String(
                fd.get(
                    'field_phone'
                ) || ''
            );

        settings.field_address =
            Array.from(
                document.querySelectorAll(
                    '[name="field_address[]"]:checked'
                )
            ).map(
                el => el.value
            );

        try {

            await App.api.saveSettings(
                settings
            );

            await App.api.load();

            alert(
                'kintone連携設定を保存しました。'
            );

            App.render.settings();

        } catch (error) {
            alert(error.message);
        }
    };

/* ================================================================
 * preview
 * ================================================================ */

App.actions.preview =
    function() {

        const survey =
            App.state.editingSurvey;

        const modal =
            document.getElementById(
                'preview_modal'
            );

        const content =
            document.getElementById(
                'preview_content'
            );

        if (!modal || !content) return;

        let html = '';

        (survey.groups || [])
            .forEach(group => {

                html += `
                <section class="mb-8">
                <h3 class="text-lg font-bold border-b pb-2 mb-4">
                ${App.util.escape(group.name)}
                </h3>
                `;

                (group.questions || [])
                    .forEach(q => {

                        html += `
                        <div class="mb-6">
                        <div class="font-semibold mb-2">
                        ${App.util.escape(q.number || '')}
                        ${App.util.escape(q.text)}
                        ${q.required
                            ? '<span class="text-red-500">*</span>'
                            : ''}
                        </div>
                        `;

                        if (
                            q.type === 'text'
                        ) {
                            html += `
                            <textarea
                            class="w-full border rounded-lg p-3"
                            rows="4"></textarea>`;
                        } else {
                            (q.options || [])
                                .forEach(option => {

                                    const type =
                                        q.type ===
                                        'multiple'
                                            ? 'checkbox'
                                            : 'radio';

                                    html += `
                                    <label class="flex gap-2 mb-2">
                                    <input
                                    type="${type}">
                                    <span>
                                    ${App.util.escape(option)}
                                    </span>
                                    </label>`;
                                });

                            if (
                                q.other_enabled
                            ) {
                                html += `
                                <label class="flex gap-2 mb-2">
                                <input
                                type="${q.type === 'multiple'
                                    ? 'checkbox'
                                    : 'radio'}">
                                その他
                                </label>`;
                            }
                        }

                        html += `
                        </div>`;
                    });

                html += `
                </section>`;
            });

        html += `
        <button
        type="button"
        onclick="alert('プレビューのため実送信は行いません。')"
        class="w-full bg-indigo-600 text-white rounded-xl px-4 py-3">
        送信する
        </button>`;

        content.innerHTML =
            html;

        modal.classList.remove(
            'hidden'
        );
    };

App.actions.closePreview =
    function() {
        document.getElementById(
            'preview_modal'
        )?.classList.add('hidden');
    };

/* ================================================================
 * aggregate
 * ================================================================ */

App.actions.showAggregate =
    function(id) {

        App.state.surveyId = id;
        App.state.page = 'aggregate';

        App.render.main();
    };

App.actions.showResponses =
    function(
        surveyId,
        responseId
    ) {

        const response =
            App.state.data.responses.find(
                r =>
                    r.id === responseId &&
                    String(r.survey_id) ===
                    String(surveyId)
            );

        if (!response) return;

        const survey =
            App.util.findSurvey(
                surveyId
            );

        const questions =
            App.util.questionList(
                survey
            );

        let html = `
        <div class="space-y-4">
        <div>
        <div class="text-sm text-slate-500">
        会社名
        </div>
        <div class="font-semibold">
        ${App.util.escape(response.company)}
        </div>
        </div>

        <div>
        <div class="text-sm text-slate-500">
        氏名
        </div>
        <div class="font-semibold">
        ${App.util.escape(response.name)}
        </div>
        </div>

        <div>
        <div class="text-sm text-slate-500">
        回答日時
        </div>
        <div>
        ${App.util.escape(response.answered_at)}
        </div>
        </div>
        `;

        questions.forEach(q => {

            let answer =
                response.answers?.[q.id] ??
                '';

            if (Array.isArray(answer)) {
                answer =
                    answer.join('、');
            }

            html += `
            <div class="border-t pt-3">
            <div class="text-sm text-slate-500">
            ${App.util.escape(q.number || '')}
            ${App.util.escape(q.text)}
            </div>
            <div class="mt-1 whitespace-pre-wrap">
            ${App.util.escape(answer)}
            </div>
            </div>`;
        });

        html += `
        </div>`;

        document.getElementById(
            'response_detail'
        ).innerHTML = html;

        document.getElementById(
            'response_modal'
        ).classList.remove(
            'hidden'
        );
    };

App.actions.closeResponse =
    function() {
        document.getElementById(
            'response_modal'
        )?.classList.add('hidden');
    };

/* ================================================================
 * customer/mail
 * ================================================================ */

App.actions.showSend =
    function(id) {

        App.state.customerSurveyId =
            id;

        App.state.page = 'send';

        App.render.main();
    };

App.actions.toggleAll =
    function(checked) {

        document.querySelectorAll(
            '#customer_table input[type="checkbox"][data-customer]'
        ).forEach(el => {
            el.checked = checked;
        });
    };

App.actions.sendMail =
    async function() {

        const surveyId =
            App.state.customerSurveyId;

        const ids =
            Array.from(
                document.querySelectorAll(
                    '#customer_table input[data-customer]:checked'
                )
            ).map(
                el => el.dataset.customer
            );

        if (!ids.length) {
            alert(
                '送信先を選択してください。'
            );
            return;
        }

        const subject =
            document.getElementById(
                'mail_subject'
            ).value;

        const body =
            document.getElementById(
                'mail_body'
            ).value;

        const templateType =
            document.getElementById(
                'template_type'
            ).value;

        const customers =
            App.state.data.customers;

        const resent =
            ids.some(id => {
                const customer =
                    customers.find(
                        c =>
                            String(c.id) ===
                            String(id)
                    );

                return customer &&
                    Number(
                        customer.send_count || 0
                    ) > 0;
            });

        if (
            resent &&
            !confirm(
                '既に送信済みの宛先が含まれています。再送しますか？'
            )
        ) {
            return;
        }

        try {

            const result =
                await App.api.request(
                    'send_mail',
                    {
                        survey_id: surveyId,
                        recipient_ids: ids,
                        mail_subject: subject,
                        mail_body: body,
                        template_type:
                            templateType
                    }
                );

            alert(
                result.count +
                '件の送信処理を実行しました。'
            );

            await App.api.load();

            App.render.main();

        } catch (error) {
            alert(error.message);
        }
    };

App.actions.registerCustomer =
    async function(id) {

        if (
            !confirm(
                'kintone登録完了としてマークしますか？'
            )
        ) {
            return;
        }

        try {

            await App.api.registerCustomer(
                id
            );

            await App.api.load();
            App.render.main();

        } catch (error) {
            alert(error.message);
        }
    };

/* ================================================================
 * render header
 * ================================================================ */

App.render.header = function() {

    return `
    <header class="bg-white border-b sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-5 py-4
                flex items-center justify-between gap-4">

    <div>
    <div class="font-bold text-xl">
    アンケート管理システム
    </div>
    </div>

    <nav class="flex gap-2 flex-wrap">
    <button
    onclick="App.actions.showList()"
    class="px-3 py-2 rounded-lg text-sm
           hover:bg-slate-100">
    アンケート一覧
    </button>

    <button
    onclick="App.actions.showSettings()"
    class="px-3 py-2 rounded-lg text-sm
           hover:bg-slate-100">
    kintone連携設定
    </button>

    <button
    onclick="alert('ログアウト処理は環境側の認証方式に合わせて実装してください。')"
    class="px-3 py-2 rounded-lg text-sm
           hover:bg-slate-100">
    ログアウト
    </button>
    </nav>

    </div>
    </header>`;
};

/* ================================================================
 * list
 * ================================================================ */

App.render.list = function() {

    const data =
        App.state.data;

    let surveys =
        data.surveys.filter(
            s => !s.deleted
        );

    const keyword =
        App.state.keyword
            .trim()
            .toLowerCase();

    if (keyword) {
        surveys =
            surveys.filter(
                s =>
                    String(s.title)
                        .toLowerCase()
                        .includes(keyword)
            );
    }

    if (
        App.state.statusFilter !==
        'all'
    ) {
        surveys =
            surveys.filter(
                s =>
                    s.status ===
                    App.state.statusFilter
            );
    }

    surveys.sort((a, b) => {

        if (
            App.state.sort ===
            'answers_desc'
        ) {
            return App.util.responseCount(
                b.id
            ) -
            App.util.responseCount(
                a.id
            );
        }

        if (
            App.state.sort ===
            'answers_asc'
        ) {
            return App.util.responseCount(
                a.id
            ) -
            App.util.responseCount(
                b.id
            );
        }

        if (
            App.state.sort ===
            'start_desc'
        ) {
            return String(
                b.start_at || ''
            ).localeCompare(
                String(a.start_at || '')
            );
        }

        if (
            App.state.sort ===
            'start_asc'
        ) {
            return String(
                a.start_at || ''
            ).localeCompare(
                String(b.start_at || '')
            );
        }

        const result =
            String(
                b.updated_at || ''
            ).localeCompare(
                String(a.updated_at || '')
            );

        return App.state.sort ===
            'updated_asc'
                ? -result
                : result;
    });

    let rows = '';

    surveys.forEach(survey => {

        const count =
            App.util.responseCount(
                survey.id
            );

        const status =
            survey.status;

        let buttons = `
        <button
        onclick="App.actions.editSurvey('${survey.id}')"
        class="px-3 py-1.5 rounded-lg bg-slate-100 text-sm">
        確認・編集
        </button>`;

        if (status === 'active') {
            buttons += `
            <button
            onclick="App.actions.showAggregate('${survey.id}')"
            class="px-3 py-1.5 rounded-lg bg-slate-100 text-sm">
            集計
            </button>

            <button
            onclick="App.actions.showSend('${survey.id}')"
            class="px-3 py-1.5 rounded-lg bg-slate-100 text-sm">
            送信
            </button>

            <button
            onclick="App.actions.changeStatus('${survey.id}','ended')"
            class="px-3 py-1.5 rounded-lg bg-red-50 text-red-600 text-sm">
            停止
            </button>`;
        }

        if (status === 'draft') {
            buttons += `
            <button
            onclick="App.actions.deleteSurvey('${survey.id}')"
            class="px-3 py-1.5 rounded-lg bg-red-50 text-red-600 text-sm">
            削除
            </button>`;
        }

        if (status === 'ended') {
            buttons += `
            <button
            onclick="App.actions.showAggregate('${survey.id}')"
            class="px-3 py-1.5 rounded-lg bg-slate-100 text-sm">
            集計
            </button>`;
        }

        buttons += `
        <button
        onclick="App.actions.duplicateSurvey('${survey.id}')"
        class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-sm">
        複製
        </button>`;

        rows += `
        <tr class="border-t">
        <td class="px-4 py-4">
        <div class="text-xs text-slate-400">
        ${App.util.escape(
            String(
                survey.created_at || ''
            ).slice(0,10)
        )}
        </div>
        <div class="text-xs text-slate-500">
        更新:
        ${App.util.escape(
            String(
                survey.updated_at || ''
            ).slice(0,10)
        )}
        </div>
        </td>

        <td class="px-4 py-4 font-semibold">
        ${App.util.escape(
            survey.title
        )}
        </td>

        <td class="px-4 py-4 text-sm">
        ${App.util.escape(
            survey.start_at || '未設定'
        )}
        <br>
        <span class="text-slate-400">
        ～
        </span>
        <br>
        ${App.util.escape(
            survey.end_at || '未設定'
        )}
        </td>

        <td class="px-4 py-4">
        <span class="px-2.5 py-1 rounded-full text-xs font-semibold
        ${App.util.statusClass(status)}">
        ${App.util.statusLabel(status)}
        </span>
        </td>

        <td class="px-4 py-4 font-semibold">
        ${count} 件
        </td>

        <td class="px-4 py-4">
        <div class="flex gap-2 flex-wrap">
        ${buttons}
        </div>
        </td>
        </tr>`;
    });

    return `
    <main class="max-w-7xl mx-auto p-5">

    <div class="flex justify-between items-center mb-5">
    <div>
    <h1 class="text-2xl font-bold">
    アンケート一覧
    </h1>
    <p class="text-sm text-slate-500 mt-1">
    アンケートの作成・公開・集計・送信を管理します。
    </p>
    </div>

    <button
    onclick="App.actions.newSurvey()"
    class="bg-indigo-600 text-white px-5 py-3 rounded-xl font-semibold hover:bg-indigo-700">
    ＋ 新規アンケート作成
    </button>
    </div>

    <div class="bg-white rounded-2xl shadow-sm p-4 mb-5">
    <div class="grid md:grid-cols-3 gap-3">

    <input
    value="${App.util.escape(App.state.keyword)}"
    onkeydown="
    if(event.key==='Enter'){
        App.state.keyword=this.value;
        App.render.main();
    }"
    placeholder="タイトルを検索"
    class="border rounded-xl px-3 py-2">

    <select
    onchange="
    App.state.statusFilter=this.value;
    App.render.main();"
    class="border rounded-xl px-3 py-2">

    <option value="all"
    ${App.state.statusFilter==='all'?'selected':''}>
    すべて
    </option>

    <option value="active"
    ${App.state.statusFilter==='active'?'selected':''}>
    公開中
    </option>

    <option value="draft"
    ${App.state.statusFilter==='draft'?'selected':''}>
    下書き
    </option>

    <option value="ended"
    ${App.state.statusFilter==='ended'?'selected':''}>
    終了
    </option>
    </select>

    <select
    onchange="
    App.state.sort=this.value;
    App.render.main();"
    class="border rounded-xl px-3 py-2">

    <option value="updated_desc"
    ${App.state.sort==='updated_desc'?'selected':''}>
    更新日：新しい順
    </option>

    <option value="updated_asc"
    ${App.state.sort==='updated_asc'?'selected':''}>
    更新日：古い順
    </option>

    <option value="answers_desc"
    ${App.state.sort==='answers_desc'?'selected':''}>
    回答数：多い順
    </option>

    <option value="answers_asc"
    ${App.state.sort==='answers_asc'?'selected':''}>
    回答数：少ない順
    </option>

    <option value="start_desc"
    ${App.state.sort==='start_desc'?'selected':''}>
    期間開始：新しい順
    </option>

    <option value="start_asc"
    ${App.state.sort==='start_asc'?'selected':''}>
    期間開始：古い順
    </option>

    </select>

    </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm overflow-x-auto">

    <table class="w-full min-w-[1100px] text-sm">
    <thead class="bg-slate-50">
    <tr>
    <th class="text-left px-4 py-3">
    作成日 / 更新日
    </th>
    <th class="text-left px-4 py-3">
    タイトル
    </th>
    <th class="text-left px-4 py-3">
    アンケート期間
    </th>
    <th class="text-left px-4 py-3">
    ステータス
    </th>
    <th class="text-left px-4 py-3">
    回答数
    </th>
    <th class="text-left px-4 py-3">
    操作
    </th>
    </tr>
    </thead>

    <tbody>
    ${rows || `
    <tr>
    <td colspan="6"
    class="px-4 py-16 text-center text-slate-400">
    アンケートがありません。
    </td>
    </tr>`}
    </tbody>
    </table>
    </div>
    </main>`;
};

/* ================================================================
 * editor
 * ================================================================ */

App.render.editor = function() {

    const survey =
        App.state.editingSurvey;

    App.actions.renumber();

    let groupsHtml = '';

    survey.groups.forEach(
        (group, groupIndex) => {

            let questions = '';

            group.questions.forEach(
                question => {

                    let options = '';

                    (question.options || [])
                        .forEach(
                            (option, index) => {

                                options += `
                                <div class="flex gap-2">
                                <input
                                value="${App.util.escape(option)}"
                                onchange="
                                App.actions.updateOption(
                                '${group.id}',
                                '${question.id}',
                                ${index},
                                this.value)"
                                class="flex-1 border rounded-lg px-3 py-2">

                                <button
                                onclick="
                                App.actions.removeOption(
                                '${group.id}',
                                '${question.id}',
                                ${index})"
                                class="text-red-500 px-2">
                                ×
                                </button>
                                </div>`;
                            }
                        );

                    questions += `
                    <div
                    data-question="${question.id}"
                    class="bg-white border rounded-xl p-4 shadow-sm">

                    <div class="flex gap-3">

                    <div class="question-handle cursor-move text-slate-400 pt-2">
                    ⠿
                    </div>

                    <div class="flex-1 space-y-3">

                    <div class="flex justify-between gap-3">
                    <div
                    data-question-number="${question.id}"
                    class="font-bold text-indigo-600">
                    ${App.util.escape(
                        question.number || ''
                    )}
                    </div>

                    <button
                    onclick="
                    App.actions.deleteQuestion(
                    '${group.id}',
                    '${question.id}')"
                    class="text-red-500 text-sm">
                    削除
                    </button>
                    </div>

                    <input
                    value="${App.util.escape(question.text)}"
                    onchange="
                    App.actions.updateQuestion(
                    '${group.id}',
                    '${question.id}',
                    'text',
                    this.value)"
                    class="w-full border rounded-lg px-3 py-2 font-semibold">

                    <select
                    onchange="
                    App.actions.updateQuestion(
                    '${group.id}',
                    '${question.id}',
                    'type',
                    this.value)"
                    class="border rounded-lg px-3 py-2">

                    <option value="single"
                    ${question.type==='single'?'selected':''}>
                    単一選択
                    </option>

                    <option value="multiple"
                    ${question.type==='multiple'?'selected':''}>
                    複数選択
                    </option>

                    <option value="text"
                    ${question.type==='text'?'selected':''}>
                    自由記述
                    </option>

                    </select>

                    <label class="flex gap-2 items-center text-sm">
                    <input
                    type="checkbox"
                    ${question.required?'checked':''}
                    onchange="
                    App.actions.updateQuestion(
                    '${group.id}',
                    '${question.id}',
                    'required',
                    this.checked)">
                    必須回答
                    </label>

                    ${
                        question.type !== 'text'
                        ? `
                        <div class="space-y-2">
                        <div class="text-sm font-semibold">
                        選択肢
                        </div>
                        ${options}

                        <button
                        onclick="
                        App.actions.addOption(
                        '${group.id}',
                        '${question.id}')"
                        class="text-sm text-indigo-600">
                        ＋ 選択肢追加
                        </button>

                        <label class="flex gap-2 items-center text-sm">
                        <input
                        type="checkbox"
                        ${question.other_enabled?'checked':''}
                        onchange="
                        App.actions.updateQuestion(
                        '${group.id}',
                        '${question.id}',
                        'other_enabled',
                        this.checked)">
                        「その他」を追加
                        </label>
                        </div>
                        `
                        : ''
                    }

                    </div>
                    </div>
                    </div>`;
                }
            );

            groupsHtml += `
            <section
            class="bg-slate-50 border rounded-2xl p-4">

            <div class="flex gap-3 items-center mb-4">

            <div class="group-handle cursor-move text-slate-400">
            ⠿
            </div>

            <input
            value="${App.util.escape(group.name)}"
            onchange="
            App.actions.updateGroupName(
            '${group.id}',
            this.value)"
            class="flex-1 border rounded-lg px-3 py-2 font-bold">

            <button
            onclick="
            App.actions.deleteGroup(
            '${group.id}')"
            class="text-red-500 text-sm">
            グループ削除
            </button>

            </div>

            <div
            data-question-list="${group.id}"
            class="space-y-3">

            ${questions}

            </div>

            <button
            onclick="
            App.actions.addQuestion(
            '${group.id}')"
            class="mt-4 px-4 py-2 rounded-lg bg-white border text-sm">
            ＋ 質問追加
            </button>

            </section>`;
        }
    );

    return `
    <main class="max-w-5xl mx-auto p-5">

    <div class="flex justify-between items-center mb-5">
    <div>
    <h1 class="text-2xl font-bold">
    アンケート作成・編集
    </h1>
    </div>

    <div class="flex gap-2">
    <button
    onclick="App.actions.preview()"
    class="px-4 py-2 rounded-xl bg-white border">
    プレビュー
    </button>

    <button
    onclick="App.actions.cancelEditor()"
    class="px-4 py-2 rounded-xl bg-white border">
    キャンセル
    </button>

    <button
    onclick="App.actions.saveSurvey()"
    class="px-4 py-2 rounded-xl bg-indigo-600 text-white">
    保存して一覧へ戻る
    </button>
    </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm p-5 mb-5">

    <div class="grid md:grid-cols-3 gap-4">

    <div class="md:col-span-3">
    <label class="text-sm font-semibold">
    タイトル
    </label>

    <input
    id="survey_title"
    value="${App.util.escape(survey.title)}"
    onchange="
    App.state.editingSurvey.title=this.value"
    class="w-full mt-1 border rounded-xl px-3 py-2 text-lg font-semibold">
    </div>

    <div>
    <label class="text-sm font-semibold">
    開始日時
    </label>
    <input
    id="survey_start_at"
    type="datetime-local"
    value="${App.util.escape(survey.start_at)}"
    onchange="
    App.state.editingSurvey.start_at=this.value"
    class="w-full mt-1 border rounded-xl px-3 py-2">
    </div>

    <div>
    <label class="text-sm font-semibold">
    終了日時
    </label>
    <input
    id="survey_end_at"
    type="datetime-local"
    value="${App.util.escape(survey.end_at)}"
    onchange="
    App.state.editingSurvey.end_at=this.value"
    class="w-full mt-1 border rounded-xl px-3 py-2">
    </div>

    <div>
    <label class="text-sm font-semibold">
    質問番号形式
    </label>
    <select
    id="survey_numbering_mode"
    onchange="
    App.state.editingSurvey.numbering_mode=this.value;
    App.render.editor();
    App.actions.initSortables();"
    class="w-full mt-1 border rounded-xl px-3 py-2">

    <option value="global"
    ${survey.numbering_mode==='global'?'selected':''}>
    Q1, Q2, Q3...
    </option>

    <option value="group"
    ${survey.numbering_mode==='group'?'selected':''}>
    Q1-1, Q1-2...
    </option>

    </select>
    </div>

    </div>
    </div>

    <div
    id="question_editor"
    class="space-y-4">

    ${groupsHtml}

    </div>

    <button
    onclick="App.actions.addGroup()"
    class="mt-5 w-full py-3 rounded-xl border-2 border-dashed
           border-slate-300 text-slate-600 hover:bg-white">
    ＋ グループ追加
    </button>

    </main>

    <div
    id="preview_modal"
    class="hidden fixed inset-0 z-50 bg-black/50 p-5 overflow-auto">

    <div class="max-w-3xl mx-auto bg-white rounded-2xl p-6 mt-8">

    <div class="flex justify-between mb-5">
    <h2 class="text-xl font-bold">
    プレビュー
    </h2>

    <button
    onclick="App.actions.closePreview()"
    class="text-slate-500">
    ✕
    </button>
    </div>

    <div id="preview_content"></div>

    </div>
    </div>`;
};

/* ================================================================
 * settings render
 * ================================================================ */

App.render.settingsFields =
    function(fields) {

        const settings =
            App.state.data.settings;

        const makeSelect =
            function(
                name,
                current,
                multiple = false
            ) {

                const options = fields.map(
                    field => `
                    <option
                    value="${App.util.escape(field.code)}"
                    ${!multiple &&
                    current === field.code
                        ? 'selected'
                        : ''}>
                    ${App.util.escape(field.label)}
                    [${App.util.escape(field.code)}]
                    </option>`
                ).join('');

                return `
                <select
                name="${name}${multiple?'[]':''}"
                ${multiple?'multiple':''}
                class="w-full border rounded-lg px-3 py-2">
                <option value="">
                -- 選択してください --
                </option>
                ${options}
                </select>`;
            };

        const container =
            document.getElementById(
                'field_mapping'
            );

        if (!container) return;

        container.innerHTML = `
        <div class="grid md:grid-cols-2 gap-4">

        <div>
        <label class="text-sm font-semibold">
        会社名
        </label>
        ${makeSelect(
            'field_company',
            settings.field_company
        )}
        </div>

        <div>
        <label class="text-sm font-semibold">
        氏名
        </label>
        ${makeSelect(
            'field_name',
            settings.field_name
        )}
        </div>

        <div>
        <label class="text-sm font-semibold">
        メールアドレス
        </label>
        ${makeSelect(
            'field_email',
            settings.field_email
        )}
        </div>

        <div>
        <label class="text-sm font-semibold">
        部署名
        </label>
        ${makeSelect(
            'field_department',
            settings.field_department
        )}
        </div>

        <div>
        <label class="text-sm font-semibold">
        電話番号
        </label>
        ${makeSelect(
            'field_phone',
            settings.field_phone
        )}
        </div>

        <div>
        <label class="text-sm font-semibold">
        住所
        </label>
        ${makeSelect(
            'field_address',
            '',
            true
        )}
        <p class="text-xs text-slate-400 mt-1">
        複数選択したフィールドを連結して住所として扱います。
        </p>
        </div>

        </div>`;

        const selected =
            settings.field_address || [];

        container
            .querySelectorAll(
                '[name="field_address[]"] option'
            )
            .forEach(option => {
                option.selected =
                    selected.includes(
                        option.value
                    );
            });
    };

App.render.settings =
    function() {

        const settings =
            App.state.data.settings;

        return `
        <main class="max-w-5xl mx-auto p-5">

        <div class="mb-5">
        <h1 class="text-2xl font-bold">
        kintone連携設定
        </h1>
        <p class="text-sm text-slate-500 mt-1">
        kintone顧客管理アプリとの接続設定です。
        </p>
        </div>

        <form
        id="settings_form"
        onsubmit="
        event.preventDefault();
        App.actions.saveSettings();"
        class="space-y-5">

        <div class="bg-white rounded-2xl shadow-sm p-5">

        <h2 class="font-bold text-lg mb-4">
        接続・認証設定
        </h2>

        <div class="grid md:grid-cols-2 gap-4">

        <div>
        <label class="text-sm font-semibold">
        サブドメイン / FQDN
        </label>

        <input
        id="setting_subdomain"
        name="setting_subdomain"
        value="${App.util.escape(settings.subdomain)}"
        placeholder="jacic または jacic.cybozu.com"
        class="w-full mt-1 border rounded-lg px-3 py-2">

        <p class="text-xs text-slate-400 mt-1">
        jacic / jacic.cybozu.com / https://jacic.cybozu.com に対応
        </p>
        </div>

        <div>
        <label class="text-sm font-semibold">
        顧客管理アプリID
        </label>

        <input
        id="setting_app_id"
        name="setting_app_id"
        value="${App.util.escape(settings.app_id)}"
        class="w-full mt-1 border rounded-lg px-3 py-2">
        </div>

        <div>
        <label class="text-sm font-semibold">
        ログイン名
        </label>

        <input
        id="setting_login_name"
        name="setting_login_name"
        value="${App.util.escape(settings.login_name)}"
        class="w-full mt-1 border rounded-lg px-3 py-2">
        </div>

        <div>
        <label class="text-sm font-semibold">
        パスワード
        </label>

        <input
        id="setting_password"
        name="setting_password"
        type="password"
        placeholder="変更しない場合は空欄"
        class="w-full mt-1 border rounded-lg px-3 py-2">
        </div>

        <div>
        <label class="text-sm font-semibold">
        Proxyサーバ
        </label>

        <input
        id="setting_proxy"
        name="setting_proxy"
        value="${App.util.escape(settings.proxy)}"
        placeholder="proxy.example.local:8080"
        class="w-full mt-1 border rounded-lg px-3 py-2">
        </div>

        <div class="flex items-center pt-6">
        <label class="flex gap-2 items-center">
        <input
        id="setting_ssl_verify"
        name="setting_ssl_verify"
        value="1"
        type="checkbox"
        ${settings.ssl_verify?'checked':''}>
        SSL証明書を検証する
        </label>
        </div>

        </div>

        <div class="mt-5 flex gap-2 flex-wrap">

        <button
        type="button"
        onclick="App.actions.fetchKintoneFields()"
        class="px-4 py-2 rounded-lg bg-indigo-600 text-white">
        項目一覧を再取得
        </button>

        <span
        id="field_message"
        class="text-sm text-slate-500 py-2">
        </span>

        </div>

        </div>

        <div class="bg-white rounded-2xl shadow-sm p-5">

        <h2 class="font-bold text-lg mb-4">
        フィールドマッピング
        </h2>

        <div id="field_mapping">
        <p class="text-slate-400">
        「項目一覧を再取得」を押してください。
        </p>
        </div>

        </div>

        <div class="flex justify-end">

        <button
        type="submit"
        class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold">
        設定を保存
        </button>

        </div>

        </form>

        </main>`;
    };

/* ================================================================
 * aggregate render
 * ================================================================ */

App.render.aggregate =
    function() {

        const survey =
            App.util.findSurvey(
                App.state.surveyId
            );

        if (!survey) {
            return `
            <main class="p-5">
            アンケートが見つかりません。
            </main>`;
        }

        const responses =
            App.state.data.responses
                .filter(
                    r =>
                        String(r.survey_id) ===
                        String(survey.id)
                );

        const customers =
            App.state.data.customers;

        const targetCount =
            customers.filter(
                c =>
                    c.source !== 'web' &&
                    Number(
                        c.send_count || 0
                    ) > 0
            ).length;

        const webCount =
            responses.filter(
                r => {
                    const customer =
                        customers.find(
                            c =>
                                String(c.id) ===
                                String(r.customer_id)
                        );

                    return customer &&
                        customer.source === 'web';
                }
            ).length;

        const answeredTarget =
            responses.filter(
                r => {
                    const customer =
                        customers.find(
                            c =>
                                String(c.id) ===
                                String(r.customer_id)
                        );

                    return customer &&
                        customer.source !== 'web';
                }
            ).length;

        const unanswered =
            Math.max(
                0,
                targetCount -
                answeredTarget
            );

        const rate =
            targetCount
                ? (
                    answeredTarget /
                    targetCount *
                    100
                ).toFixed(1)
                : '0.0';

        let questionHtml = '';

        App.util.questionList(
            survey
        ).forEach(q => {

            if (
                App.state.responseFilter &&
                q.id !==
                App.state.responseFilter
            ) {
                return;
            }

            if (
                q.type === 'text'
            ) {
                const texts =
                    responses
                        .map(r => ({
                            r,
                            value:
                                r.answers?.[q.id]
                                ?? ''
                        }))
                        .filter(
                            x =>
                                String(
                                    x.value
                                ).trim() !== ''
                        );

                questionHtml += `
                <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">

                <div class="flex justify-between mb-4">
                <div>
                <div class="font-bold">
                ${App.util.escape(q.number || '')}
                ${App.util.escape(q.text)}
                </div>
                <span class="text-xs text-slate-400">
                自由記述
                </span>
                </div>
                </div>

                <div class="space-y-3 max-h-80 overflow-auto">
                ${
                    texts.length
                        ? texts.map(
                            x => `
                            <div class="border rounded-xl p-3">
                            <div class="text-xs text-slate-400">
                            ${App.util.escape(x.r.company)}
                            ${App.util.escape(x.r.name)}
                            </div>
                            <div class="mt-1 whitespace-pre-wrap">
                            ${App.util.escape(x.value)}
                            </div>
                            </div>`
                        ).join('')
                        : `
                        <div class="text-slate-400">
                        回答データはありません。
                        </div>`
                }
                </div>

                </div>`;
                return;
            }

            const total =
                responses.length;

            const values = {};

            (q.options || [])
                .forEach(
                    option => {
                        values[option] = 0;
                    }
                );

            let otherCount = 0;

            responses.forEach(r => {

                let value =
                    r.answers?.[q.id];

                if (!Array.isArray(value)) {
                    value =
                        value === ''
                            ? []
                            : [value];
                }

                value.forEach(v => {

                    if (
                        Object.prototype.hasOwnProperty
                            .call(
                                values,
                                v
                            )
                    ) {
                        values[v]++;
                    } else {
                        otherCount++;
                    }
                });
            });

            const bars =
                Object.entries(values)
                    .map(
                        ([label, count]) => {

                            const percent =
                                total
                                    ? (
                                        count /
                                        total *
                                        100
                                    )
                                    : 0;

                            return `
                            <div class="mb-4">
                            <div class="flex justify-between text-sm mb-1">
                            <span>
                            ${App.util.escape(label)}
                            </span>
                            <span>
                            ${count}件
                            (${percent.toFixed(1)}%)
                            </span>
                            </div>

                            <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                            <div
                            class="h-full bg-indigo-500 rounded-full"
                            style="width:${Math.min(
                                100,
                                percent
                            )}%">
                            </div>
                            </div>
                            </div>`;
                        }
                    )
                    .join('');

            questionHtml += `
            <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">

            <div class="font-bold mb-4">
            ${App.util.escape(q.number || '')}
            ${App.util.escape(q.text)}
            </div>

            ${bars}

            ${
                otherCount
                    ? `
                    <div class="mt-3 text-sm text-indigo-600">
                    その他 / 自由記述:
                    ${otherCount}件
                    </div>`
                    : ''
            }

            </div>`;
        });

        let responseRows = '';

        responses.forEach(
            response => {

                const keyword =
                    App.state.keyword
                        .trim()
                        .toLowerCase();

                if (
                    keyword &&
                    !(
                        String(
                            response.company
                        ).toLowerCase()
                            .includes(keyword) ||
                        String(
                            response.name
                        ).toLowerCase()
                            .includes(keyword)
                    )
                ) {
                    return;
                }

                responseRows += `
                <tr class="border-t">
                <td class="px-4 py-3">
                ${App.util.escape(response.company)}
                </td>

                <td class="px-4 py-3">
                ${App.util.escape(response.name)}
                </td>

                <td class="px-4 py-3">
                ${App.util.escape(response.answered_at)}
                </td>

                <td class="px-4 py-3">
                <button
                onclick="
                App.actions.showResponses(
                '${survey.id}',
                '${response.id}')"
                class="text-indigo-600 font-semibold">
                全回答を表示
                </button>
                </td>
                </tr>`;
            }
        );

        return `
        <main class="max-w-7xl mx-auto p-5">

        <div class="flex justify-between items-center mb-5">

        <div>
        <div class="text-sm text-slate-400">
        アンケート集計
        </div>

        <h1 class="text-2xl font-bold">
        ${App.util.escape(survey.title)}
        </h1>
        </div>

        <div class="flex gap-2">

        <a
        href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
        class="px-4 py-2 rounded-xl bg-indigo-600 text-white">
        CSV出力
        </a>

        <button
        onclick="App.actions.showList()"
        class="px-4 py-2 rounded-xl bg-white border">
        一覧へ戻る
        </button>

        </div>
        </div>

        <div class="grid md:grid-cols-5 gap-3 mb-5">

        ${[
            ['送信対象者数', targetCount + ' 人'],
            ['回答数', responses.length + ' 件'],
            ['未登録顧客からの回答数', webCount + ' 件'],
            ['未回答数', unanswered + ' 人'],
            ['回答率', rate + ' %']
        ].map(
            item => `
            <div class="bg-white rounded-2xl shadow-sm p-4">
            <div class="text-xs text-slate-400">
            ${item[0]}
            </div>
            <div class="text-2xl font-bold mt-1">
            ${item[1]}
            </div>
            </div>`
        ).join('')}

        </div>

        <div class="bg-white rounded-2xl shadow-sm p-4 mb-5">

        <div class="font-bold mb-3">
        設問絞り込み
        </div>

        <div class="flex gap-2 flex-wrap">

        <button
        onclick="
        App.state.responseFilter='';
        App.render.main();"
        class="px-3 py-2 rounded-lg bg-slate-100 text-sm">
        全設問
        </button>

        ${App.util.questionList(survey)
            .map(
                q => `
                <button
                onclick="
                App.state.responseFilter='${q.id}';
                App.render.main();"
                class="px-3 py-2 rounded-lg
                ${
                    App.state.responseFilter ===
                    q.id
                        ? 'bg-indigo-600 text-white'
                        : 'bg-slate-100'
                } text-sm">

                ${App.util.escape(
                    q.number || ''
                )}

                ${App.util.escape(
                    q.text
                )}

                </button>`
            )
            .join('')}

        </div>

        </div>

        <div>
        ${questionHtml ||
            `
            <div class="bg-white rounded-2xl p-10 text-center text-slate-400">
            現在、回答データはありません
            </div>`}
        </div>

        <div class="bg-white rounded-2xl shadow-sm mt-5">

        <div class="p-4 border-b">
        <div class="font-bold mb-2">
        個別回答一覧
        </div>

        <input
        value="${App.util.escape(App.state.keyword)}"
        oninput="
        App.state.keyword=this.value;
        App.render.main();"
        placeholder="会社名・氏名を検索"
        class="border rounded-lg px-3 py-2 w-full md:w-96">
        </div>

        <div class="overflow-x-auto">

        <table class="w-full min-w-[700px] text-sm">

        <thead class="bg-slate-50">
        <tr>
        <th class="text-left px-4 py-3">
        会社名
        </th>
        <th class="text-left px-4 py-3">
        氏名
        </th>
        <th class="text-left px-4 py-3">
        回答日時
        </th>
        <th class="text-left px-4 py-3">
        詳細
        </th>
        </tr>
        </thead>

        <tbody>
        ${responseRows ||
            `
            <tr>
            <td colspan="4"
            class="text-center text-slate-400 py-10">
            回答データはありません。
            </td>
            </tr>`}
        </tbody>

        </table>
        </div>
        </div>

        </main>

        <div
        id="response_modal"
        class="hidden fixed inset-0 z-50 bg-black/50 p-5 overflow-auto">

        <div class="max-w-3xl mx-auto bg-white rounded-2xl p-6 mt-8">

        <div class="flex justify-between mb-5">

        <h2 class="text-xl font-bold">
        回答詳細
        </h2>

        <button
        onclick="App.actions.closeResponse()"
        class="text-slate-500">
        ✕
        </button>

        </div>

        <div id="response_detail"></div>

        </div>
        </div>`;
    };

/* ================================================================
 * send render
 * ================================================================ */

App.render.send =
    function() {

        const survey =
            App.util.findSurvey(
                App.state.customerSurveyId
            );

        if (!survey) {
            return `
            <main class="p-5">
            アンケートが見つかりません。
            </main>`;
        }

        const keyword =
            App.state.keyword
                .trim()
                .toLowerCase();

        const customers =
            App.state.data.customers
                .filter(
                    customer =>
                        customer.source !==
                        'web'
                )
                .filter(
                    customer => {

                        if (!keyword) {
                            return true;
                        }

                        return [
                            customer.company,
                            customer.name,
                            customer.email
                        ].some(
                            value =>
                                String(
                                    value || ''
                                )
                                .toLowerCase()
                                .includes(keyword)
                        );
                    }
                );

        let rows = '';

        customers.forEach(
            customer => {

                const answered =
                    customer.answer_status ===
                    'answered';

                const registered =
                    customer.kintone_status ===
                    'registered';

                rows += `
                <tr class="border-t">

                <td class="px-4 py-3">
                <input
                type="checkbox"
                data-customer="${customer.id}"
                class="h-4 w-4">
                </td>

                <td class="px-4 py-3">

                <div class="font-semibold">
                ${App.util.escape(customer.company)}
                </div>

                <div>
                ${App.util.escape(customer.name)}
                </div>

                <div class="text-xs text-slate-400">
                ${App.util.escape(customer.email)}
                </div>

                </td>

                <td class="px-4 py-3">
                ${App.util.escape(customer.sent_at || '未送信')}
                <br>
                <span class="text-xs text-slate-400">
                ${customer.send_count || 0}回
                </span>
                </td>

                <td class="px-4 py-3">

                <span class="px-2 py-1 rounded-full text-xs
                ${
                    answered
                        ? 'bg-emerald-100 text-emerald-700'
                        : 'bg-amber-100 text-amber-700'
                }">
                ${
                    answered
                        ? '回答済み'
                        : '送信済み（未回答）'
                }
                </span>

                </td>

                <td class="px-4 py-3">

                ${
                    registered
                        ? `
                        <span class="text-emerald-600 text-sm">
                        ✓ kintone登録完了
                        </span>`
                        : `
                        <button
                        onclick="
                        App.actions.registerCustomer(
                        '${customer.id}')"
                        class="px-3 py-1.5 rounded-lg
                        bg-indigo-50 text-indigo-700 text-sm">
                        kintone登録完了
                        </button>`
                }

                </td>

                </tr>`;
            }
        );

        return `
        <main class="max-w-7xl mx-auto p-5">

        <div class="flex justify-between mb-5">

        <div>
        <div class="text-sm text-slate-400">
        ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
        </div>

        <h1 class="text-2xl font-bold mt-1">
        ${App.util.escape(survey.title)}
        </h1>
        </div>

        <button
        onclick="App.actions.showList()"
        class="px-4 py-2 rounded-xl bg-white border">
        一覧へ戻る
        </button>

        </div>

        <div class="grid lg:grid-cols-3 gap-5 mb-5">

        <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm p-5">

        <h2 class="font-bold mb-4">
        メールテンプレート
        </h2>

        <div class="space-y-3">

        <select
        id="template_type"
        class="border rounded-lg px-3 py-2">
        <option value="initial">
        初回送信用テンプレート
        </option>
        <option value="reminder">
        再送・リマインド用テンプレート
        </option>
        </select>

        <input
        id="mail_subject"
        placeholder="件名"
        class="w-full border rounded-lg px-3 py-2">

        <textarea
        id="mail_body"
        rows="9"
        placeholder="本文&#10;&#10;{顧客名} 様&#10;アンケートURL: {アンケートURL}"
        class="w-full border rounded-lg px-3 py-2"></textarea>

        <div class="text-xs text-slate-400">
        使用可能な変数：
        {顧客名} / {アンケートURL}
        </div>

        <button
        onclick="App.actions.sendMail()"
        class="w-full bg-indigo-600 text-white rounded-xl py-3 font-semibold">
        選択した顧客へ一括送信
        </button>

        </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm p-5">
        <div class="text-sm text-slate-400">
        対象アンケート
        </div>

        <div class="font-bold mt-1">
        ${App.util.escape(survey.title)}
        </div>

        <div class="mt-5 text-sm">
        メール送信対象：
        <span class="font-bold">
        ${customers.length}件
        </span>
        </div>
        </div>

        </div>

        <div class="bg-white rounded-2xl shadow-sm">

        <div class="p-4 border-b flex gap-3">

        <input
        id="customer_filter"
        value="${App.util.escape(App.state.keyword)}"
        oninput="
        App.state.keyword=this.value;
        App.render.main();"
        placeholder="顧客名・メールアドレス検索"
        class="border rounded-lg px-3 py-2 flex-1">

        </div>

        <div class="overflow-x-auto">

        <table
        id="customer_table"
        class="w-full min-w-[1000px] text-sm">

        <thead class="bg-slate-50">
        <tr>

        <th class="px-4 py-3 text-left">
        <input
        id="select_all"
        type="checkbox"
        onchange="
        App.actions.toggleAll(this.checked)">
        </th>

        <th class="px-4 py-3 text-left">
        会社名 / 氏名 / メール
        </th>

        <th class="px-4 py-3 text-left">
        送信履歴
        </th>

        <th class="px-4 py-3 text-left">
        回答ステータス
        </th>

        <th class="px-4 py-3 text-left">
        kintone対応
        </th>

        </tr>
        </thead>

        <tbody>
        ${rows ||
            `
            <tr>
            <td colspan="5"
            class="py-10 text-center text-slate-400">
            顧客データがありません。
            </td>
            </tr>`}
        </tbody>

        </table>
        </div>
        </div>

        </main>`;
    };

/* ================================================================
 * main
 * ================================================================ */

App.render.main = function() {

    if (!App.state.data) {
        document.getElementById(
            'app'
        ).innerHTML = `
        <div class="min-h-screen flex items-center justify-center">
        <div class="text-slate-500">
        読み込み中...
        </div>
        </div>`;
        return;
    }

    let content = '';

    switch (
        App.state.page
    ) {

        case 'editor':
            content =
                App.render.editor();
            break;

        case 'settings':
            content =
                App.render.settings();
            break;

        case 'aggregate':
            content =
                App.render.aggregate();
            break;

        case 'send':
            content =
                App.render.send();
            break;

        default:
            content =
                App.render.list();
    }

    document.getElementById(
        'app'
    ).innerHTML =
        App.render.header() +
        content;

    if (
        App.state.page ===
        'editor'
    ) {
        App.actions.initSortables();
    }

    if (
        App.state.page ===
        'settings' &&
        App.state.fields.length
    ) {
        App.render.settingsFields(
            App.state.fields
        );
    }
};

/* ================================================================
 * lifecycle
 * ================================================================ */

App.init = async function() {

    if (App.initDone) {
        return;
    }

    App.initDone = true;

    try {

        await App.api.load();

        /*
         * URLにsurvey_idがあれば編集対象を復元。
         */
        const params =
            new URLSearchParams(
                location.search
            );

        const surveyId =
            params.get('survey_id');

        if (
            surveyId &&
            App.state.page ===
            'list'
        ) {
            App.state.surveyId =
                surveyId;
        }

        App.render.main();

    } catch (error) {

        document.getElementById(
            'app'
        ).innerHTML = `
        <div class="min-h-screen flex items-center justify-center p-5">
        <div class="bg-white rounded-2xl shadow-sm p-6 max-w-xl w-full">
        <h1 class="text-xl font-bold text-red-600 mb-3">
        初期化エラー
        </h1>
        <pre class="whitespace-pre-wrap text-sm text-slate-600">${App.util.escape(error.message)}</pre>
        </div>
        </div>`;
    }
};

if (
    document.readyState ===
    'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        { once: true }
    );
} else {
    App.init();
}
</script>

</body>
</html>
<?php
declare(strict_types=1);

/*
============================================================
GUARD COMMENT - DO NOT RENAME / REMOVE
============================================================

Storage names:
  survey_storage_directory
  survey_storage_file
  survey_admin_session_v1

PHP constants:
  SURVEY_STORAGE_DIRECTORY
  SURVEY_STORAGE_FILE
  SURVEY_ADMIN_SESSION

JSON top keys:
  surveys
  responses
  customers
  settings
  mail_logs

Data fields:
  survey:
    id, title, start_at, end_at, status, created_at, updated_at,
    numbering_mode, groups, deleted

  group:
    id, name, questions

  question:
    id, text, type, required, options, other_enabled

  customer:
    id, company, name, email, department, phone, address, source,
    sent_at, send_count, answer_status, kintone_status

  response:
    id, survey_id, customer_id, company, name, email, answered_at, answers

  settings:
    subdomain, login_name, password, app_id, ssl_verify, proxy,
    field_company, field_name, field_email, field_department,
    field_phone, field_address

POST/GET parameters:
  action, survey_id, customer_id, response_id, keyword,
  status_filter, sort, survey_json, settings_json,
  csrf_token, recipient_ids, mail_subject, mail_body,
  template_type, app_id

API/JSON keys:
  properties, records, label, code, type, message, ok, fields

HTML DOM IDs:
  app
  csrf_token
  survey_title
  survey_start_at
  survey_end_at
  survey_numbering_mode
  question_editor
  preview_modal
  preview_content
  response_modal
  response_detail
  response_filter
  response_table
  customer_filter
  customer_table
  select_all
  mail_subject
  mail_body
  template_type
  settings_form
  settings_json
  setting_subdomain
  setting_app_id
  setting_login_name
  setting_password
  setting_proxy
  setting_ssl_verify
  field_message

JavaScript references:
  App
  App.init
  App.initSortable
  App.actions.addGroup
  App.actions.addQuestion
  App.actions.deleteGroup
  App.actions.deleteQuestion
  App.actions.moveQuestion
  App.actions.renumberQuestions
  App.actions.preview
  App.actions.saveSurvey
  App.actions.cancelEdit
  App.actions.stopSurvey
  App.actions.resumeSurvey
  App.actions.duplicateSurvey
  App.actions.deleteSurvey
  App.actions.filterSurveys
  App.actions.sortSurveys
  App.actions.fetchKintoneFields
  App.actions.syncCustomers
  App.actions.sendMail
  App.actions.sendReminder
  App.actions.showSentMail
  App.actions.showAllResponses
  App.actions.toggleResponseFilter

Values:
  status: draft, active, ended
  numbering_mode: global, group
  question type: single, multiple, text
  source: kintone, web
  answer_status: unanswered, answered
  kintone_status: unregistered, registered
  template_type: initial, reminder

Lengths / attributes:
  IDs are generated server-side and unique.
  Password fields are never rendered as plain text.
  CSRF is required for POST operations.
============================================================
*/

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header_remove('X-Powered-By');

function survey_app_initial_data(): array
{
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [],
        'mail_logs' => [],
    ];
}

function survey_app_ensure_storage(): void
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
    }

    if (!file_exists(SURVEY_STORAGE_FILE)) {
        survey_app_atomic_save(survey_app_initial_data());
    }
}

function survey_app_read_data(): array
{
    survey_app_ensure_storage();

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);

    if ($raw === false || trim($raw) === '') {
        return survey_app_initial_data();
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return survey_app_initial_data();
    }

    $initial = survey_app_initial_data();

    foreach ($initial as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function survey_app_atomic_save(array $data): bool
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        if (!mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true) && !is_dir(SURVEY_STORAGE_DIRECTORY)) {
            return false;
        }
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return false;
    }

    $tmp = SURVEY_STORAGE_FILE . '.' . bin2hex(random_bytes(8)) . '.tmp';

    $fp = @fopen($tmp, 'wb');

    if ($fp === false) {
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        @unlink($tmp);
        return false;
    }

    $written = fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($written === false || $written < strlen($json)) {
        @unlink($tmp);
        return false;
    }

    $check = json_decode((string)file_get_contents($tmp), true);

    if (!is_array($check)) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, SURVEY_STORAGE_FILE)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function survey_app_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function survey_app_now(): string
{
    return date('c');
}

function survey_app_id(string $prefix = 'id'): string
{
    return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6));
}

function survey_app_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function survey_app_verify_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals(survey_app_csrf(), $token)) {
        survey_app_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。',
        ], 403);
    }
}

function survey_app_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_app_request_json(string $key): ?array
{
    $raw = (string)($_POST[$key] ?? '');

    if ($raw === '') {
        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function survey_app_find_survey(array &$data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_app_find_survey_index(array &$data, string $id): int
{
    foreach ($data['surveys'] as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function survey_app_find_customer_index(array &$data, string $id): int
{
    foreach ($data['customers'] as $i => $customer) {
        if (($customer['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function survey_app_validate_survey(array $survey): array
{
    $errors = [];

    if (trim((string)($survey['title'] ?? '')) === '') {
        $errors[] = 'アンケートタイトルは必須です。';
    }

    $validStatuses = ['draft', 'active', 'ended'];
    $validNumbering = ['global', 'group'];

    if (!in_array(($survey['status'] ?? ''), $validStatuses, true)) {
        $errors[] = 'ステータスが不正です。';
    }

    if (!in_array(($survey['numbering_mode'] ?? ''), $validNumbering, true)) {
        $errors[] = '質問番号方式が不正です。';
    }

    if (!isset($survey['groups']) || !is_array($survey['groups'])) {
        $errors[] = 'グループ構造が不正です。';
    }

    foreach (($survey['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            $errors[] = 'グループデータが不正です。';
            continue;
        }

        foreach (($group['questions'] ?? []) as $question) {
            $type = $question['type'] ?? '';

            if (!in_array($type, ['single', 'multiple', 'text'], true)) {
                $errors[] = '質問形式が不正です。';
            }

            if (!isset($question['options']) || !is_array($question['options'])) {
                $errors[] = '選択肢データが不正です。';
            }
        }
    }

    return $errors;
}

function survey_app_renumber(array &$survey): void
{
    $global = 1;
    $groupNo = 1;

    foreach ($survey['groups'] as &$group) {
        $questionNo = 1;

        foreach ($group['questions'] as &$question) {
            if (($survey['numbering_mode'] ?? 'global') === 'group') {
                $question['number'] = 'Q' . $groupNo . '-' . $questionNo;
            } else {
                $question['number'] = 'Q' . $global;
            }

            $global++;
            $questionNo++;
        }

        unset($question);
        $groupNo++;
    }

    unset($group);
}

function survey_app_normalize_survey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? survey_app_id('survey'));
    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['start_at'] = (string)($survey['start_at'] ?? '');
    $survey['end_at'] = (string)($survey['end_at'] ?? '');
    $survey['status'] = (string)($survey['status'] ?? 'draft');
    $survey['created_at'] = (string)($survey['created_at'] ?? survey_app_now());
    $survey['updated_at'] = survey_app_now();
    $survey['numbering_mode'] = (string)($survey['numbering_mode'] ?? 'global');
    $survey['deleted'] = (bool)($survey['deleted'] ?? false);
    $survey['groups'] = is_array($survey['groups'] ?? null) ? $survey['groups'] : [];

    foreach ($survey['groups'] as &$group) {
        $group['id'] = (string)($group['id'] ?? survey_app_id('group'));
        $group['name'] = (string)($group['name'] ?? '新しいグループ');
        $group['questions'] = is_array($group['questions'] ?? null) ? $group['questions'] : [];

        foreach ($group['questions'] as &$question) {
            $question['id'] = (string)($question['id'] ?? survey_app_id('question'));
            $question['text'] = (string)($question['text'] ?? '');
            $question['type'] = (string)($question['type'] ?? 'text');
            $question['required'] = (bool)($question['required'] ?? false);
            $question['options'] = is_array($question['options'] ?? null) ? array_values($question['options']) : [];
            $question['other_enabled'] = (bool)($question['other_enabled'] ?? false);
        }

        unset($question);
    }

    unset($group);

    survey_app_renumber($survey);

    return $survey;
}

function survey_app_kintone_url(string $subdomain, string $path): string
{
    $subdomain = trim($subdomain);

    $subdomain = preg_replace('#^https?://#i', '', $subdomain);
    $subdomain = preg_replace('#/.*$#', '', (string)$subdomain);
    $subdomain = preg_replace('#\.cybozu\.com$#i', '', (string)$subdomain);

    $subdomain = trim((string)$subdomain);

    return 'https://' . $subdomain . '.cybozu.com' . $path;
}

function survey_app_http_request(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null,
    bool $sslVerify = true,
    string $proxy = ''
): array {
    $method = strtoupper($method);

    $headerText = implode("\r\n", $headers);

    $options = [
        'http' => [
            'method' => $method,
            'header' => $headerText,
            'ignore_errors' => true,
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify,
        ],
    ];

    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    if ($proxy !== '') {
        $options['http']['proxy'] = 'tcp://' . $proxy;
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $response = @file_get_contents($url, false, $context);

    $headersOut = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : [];

    $status = 0;

    foreach ($headersOut as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#i', $header, $m)) {
            $status = (int)$m[1];
        }
    }

    if ($status === 0 && $response !== false) {
        $status = 200;
    }

    $decoded = null;

    if ($response !== false && $response !== '') {
        $decoded = json_decode($response, true);
    }

    return [
        'success' => $status >= 200 && $status <= 299,
        'status' => $status,
        'body' => $response === false ? '' : $response,
        'json' => is_array($decoded) ? $decoded : null,
        'headers' => $headersOut,
    ];
}

function survey_app_kintone_headers(array $settings): array
{
    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    return [
        'X-Cybozu-Authorization: ' . base64_encode($login . ':' . $password),
        'Content-Type: application/json',
    ];
}

function survey_app_customer_from_record(
    array $record,
    array $settings,
    string $existingId = ''
): array {
    $get = static function (string $code) use ($record): string {
        if ($code === '') {
            return '';
        }

        $value = $record[$code]['value'] ?? '';

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                if (is_array($item) && isset($item['name'])) {
                    $parts[] = (string)$item['name'];
                } elseif (is_scalar($item)) {
                    $parts[] = (string)$item;
                }
            }

            return implode(' ', $parts);
        }

        return is_scalar($value) ? (string)$value : '';
    };

    $addressCodes = $settings['field_address'] ?? [];

    if (!is_array($addressCodes)) {
        $addressCodes = $addressCodes === '' ? [] : [(string)$addressCodes];
    }

    $addressParts = [];

    foreach ($addressCodes as $code) {
        $v = $get((string)$code);
        if ($v !== '') {
            $addressParts[] = $v;
        }
    }

    return [
        'id' => $existingId !== '' ? $existingId : survey_app_id('customer'),
        'company' => $get((string)($settings['field_company'] ?? '')),
        'name' => $get((string)($settings['field_name'] ?? '')),
        'email' => $get((string)($settings['field_email'] ?? '')),
        'department' => $get((string)($settings['field_department'] ?? '')),
        'phone' => $get((string)($settings['field_phone'] ?? '')),
        'address' => implode(' ', $addressParts),
        'source' => 'kintone',
        'sent_at' => '',
        'send_count' => 0,
        'answer_status' => 'unanswered',
        'kintone_status' => 'registered',
    ];
}

function survey_app_csv_safe(string $value): string
{
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }

    return $value;
}

function survey_app_mail_header_encode(string $value): string
{
    if ($value === '') {
        return '';
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/*
 * Minimal SMTP client implemented using PHP streams.
 * No mail(), sendmail or MTA dependency.
 */
function survey_app_smtp_send(
    array $settings,
    string $to,
    string $toName,
    string $subject,
    string $body
): array {
    $server = trim((string)($settings['smtp_server'] ?? ''));
    $port = (int)($settings['smtp_port'] ?? 587);
    $encryption = strtolower((string)($settings['smtp_encryption'] ?? 'tls'));
    $username = (string)($settings['smtp_username'] ?? '');
    $password = (string)($settings['smtp_password'] ?? '');
    $from = trim((string)($settings['smtp_from'] ?? ''));
    $fromName = (string)($settings['smtp_from_name'] ?? '');
    $timeout = (int)($settings['smtp_timeout'] ?? 20);

    if ($server === '' || $from === '' || $to === '') {
        return [
            'success' => false,
            'code' => 0,
            'error' => 'SMTP設定または宛先が不足しています。',
            'transcript' => '',
        ];
    }

    $remote = $server;

    if ($encryption === 'ssl' || $encryption === 'smtps') {
        $remote = 'ssl://' . $server;
    }

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        'tcp://' . ($remote === $server ? $server : $remote) . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if ($fp === false) {
        return [
            'success' => false,
            'code' => 0,
            'error' => 'SMTP TCP接続失敗: ' . $errstr,
            'transcript' => '',
        ];
    }

    stream_set_timeout($fp, $timeout);

    $transcript = '';

    $read = static function () use ($fp, &$transcript): array {
        $lines = [];

        while (($line = fgets($fp, 4096)) !== false) {
            $transcript .= $line;
            $lines[] = $line;

            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $last = end($lines);

        $code = 0;

        if (is_string($last) && preg_match('/^(\d{3})/', $last, $m)) {
            $code = (int)$m[1];
        }

        return [$code, $lines];
    };

    $write = static function (string $command) use ($fp, &$transcript): bool {
        $transcript .= 'C: ' . preg_replace('/AUTH\s+\S+.*/i', 'AUTH [REDACTED]', $command) . "\r\n";
        return fwrite($fp, $command . "\r\n") !== false;
    };

    [$code] = $read();

    if ($code < 200 || $code >= 400) {
        fclose($fp);

        return [
            'success' => false,
            'code' => $code,
            'error' => 'SMTP greeting error.',
            'transcript' => $transcript,
        ];
    }

    $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';

    $write('EHLO ' . $hostname);
    [$code] = $read();

    if ($code >= 200 && $code < 300 && $encryption === 'tls') {
        $write('STARTTLS');
        [$code] = $read();

        if ($code >= 200 && $code < 400) {
            $crypto = stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                fclose($fp);

                return [
                    'success' => false,
                    'code' => $code,
                    'error' => 'TLS確立に失敗しました。',
                    'transcript' => $transcript,
                ];
            }

            $write('EHLO ' . $hostname);
            [$code] = $read();
        }
    }

    if ($username !== '') {
        $write('AUTH LOGIN');
        [$code] = $read();

        if ($code >= 300 && $code < 400) {
            $write(base64_encode($username));
            [$code] = $read();
        }

        if ($code >= 300 && $code < 400) {
            $write(base64_encode($password));
            [$code] = $read();
        }

        if ($code < 200 || $code >= 300) {
            fclose($fp);

            return [
                'success' => false,
                'code' => $code,
                'error' => 'SMTP認証に失敗しました。',
                'transcript' => $transcript,
            ];
        }
    }

    $write('MAIL FROM:<' . $from . '>');
    [$code] = $read();

    if ($code < 200 || $code >= 300) {
        fclose($fp);

        return [
            'success' => false,
            'code' => $code,
            'error' => 'MAIL FROMに失敗しました。',
            'transcript' => $transcript,
        ];
    }

    $write('RCPT TO:<' . $to . '>');
    [$code] = $read();

    if ($code < 200 || $code >= 300) {
        fclose($fp);

        return [
            'success' => false,
            'code' => $code,
            'error' => 'RCPT TOに失敗しました。',
            'transcript' => $transcript,
        ];
    }

    $write('DATA');
    [$code] = $read();

    if ($code < 300 || $code >= 400) {
        fclose($fp);

        return [
            'success' => false,
            'code' => $code,
            'error' => 'DATA開始に失敗しました。',
            'transcript' => $transcript,
        ];
    }

    $encodedSubject = survey_app_mail_header_encode($subject);
    $encodedName = survey_app_mail_header_encode($fromName);

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . ($encodedName !== '' ? $encodedName . ' ' : '') . '<' . $from . '>',
        'To: ' . ($toName !== '' ? survey_app_mail_header_encode($toName) . ' ' : '') . '<' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $mailData = implode("\r\n", $headers) . "\r\n\r\n";
    $mailData .= str_replace(["\r\n", "\r"], "\n", $body);
    $mailData = str_replace("\n", "\r\n", $mailData);
    $mailData = preg_replace('/^\./m', '..', $mailData) ?? $mailData;

    fwrite($fp, $mailData . "\r\n.\r\n");
    [$code] = $read();

    $success = $code >= 200 && $code < 400;

    $write('QUIT');
    $read();

    fclose($fp);

    return [
        'success' => $success,
        'code' => $code,
        'error' => $success ? '' : 'SMTP送信に失敗しました。',
        'transcript' => $transcript,
    ];
}

function survey_app_send_api(array &$data): never
{
    survey_app_verify_csrf();

    $surveyId = (string)($_POST['survey_id'] ?? '');
    $recipientIds = json_decode((string)($_POST['recipient_ids'] ?? '[]'), true);

    if (!is_array($recipientIds)) {
        survey_app_json_response([
            'ok' => false,
            'message' => '送信対象が不正です。',
        ], 400);
    }

    $surveyIndex = survey_app_find_survey_index($data, $surveyId);

    if ($surveyIndex < 0) {
        survey_app_json_response([
            'ok' => false,
            'message' => 'アンケートが見つかりません。',
        ], 404);
    }

    $survey = $data['surveys'][$surveyIndex];
    $subject = trim((string)($_POST['mail_subject'] ?? ''));
    $templateType = (string)($_POST['template_type'] ?? 'initial');
    $templateBody = (string)($_POST['mail_body'] ?? '');

    $selected = [];
    $alreadySent = [];

    foreach ($recipientIds as $id) {
        $customerIndex = survey_app_find_customer_index($data, (string)$id);

        if ($customerIndex < 0) {
            continue;
        }

        $customer = $data['customers'][$customerIndex];

        $already = false;

        foreach ($data['mail_logs'] as $log) {
            if (
                ($log['survey_id'] ?? '') === $surveyId &&
                ($log['customer_id'] ?? '') === $customer['id'] &&
                ($log['success'] ?? false) === true
            ) {
                $already = true;
                break;
            }
        }

        if ($already) {
            $alreadySent[] = $customer['id'];
        }

        $selected[] = [
            'index' => $customerIndex,
            'customer' => $customer,
        ];
    }

    $allowResend = isset($_POST['allow_resend']) && $_POST['allow_resend'] === '1';

    if ($alreadySent !== [] && !$allowResend) {
        survey_app_json_response([
            'ok' => false,
            'requires_confirmation' => true,
            'already_sent' => count($alreadySent),
            'message' => '既に送信済みの宛先が含まれています。再送しますか？',
        ]);
    }

    $success = 0;
    $failed = 0;
    $details = [];

    foreach ($selected as $item) {
        $index = $item['index'];
        $customer = $item['customer'];

        if (in_array($customer['id'], $alreadySent, true) && !$allowResend) {
            continue;
        }

        $token = bin2hex(random_bytes(24));

        $url = rtrim(
            (string)($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' .
            (string)($_SERVER['HTTP_HOST'] ?? 'localhost') .
            dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'),
            '/'
        );

        $answerUrl = $url . '/index.php?answer=1&survey_id=' .
            rawurlencode($surveyId) . '&token=' . rawurlencode($token);

        $body = str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [(string)$customer['name'], $answerUrl],
            $templateBody
        );

        $smtp = survey_app_smtp_send(
            $data['settings'],
            (string)$customer['email'],
            (string)$customer['name'],
            $subject,
            $body
        );

        $successFlag = $smtp['success'];

        $data['mail_logs'][] = [
            'id' => survey_app_id('mail'),
            'survey_id' => $surveyId,
            'customer_id' => $customer['id'],
            'sent_at' => $successFlag ? survey_app_now() : '',
            'type' => $allowResend ? 'resend' : $templateType,
            'success' => $successFlag,
            'subject' => $subject,
            'body' => $body,
            'error' => $smtp['error'],
            'smtp_code' => $smtp['code'],
            'executed_by' => (string)($_SESSION['admin_user'] ?? 'admin'),
        ];

        if ($successFlag) {
            $data['customers'][$index]['sent_at'] = survey_app_now();
            $data['customers'][$index]['send_count'] =
                (int)$data['customers'][$index]['send_count'] + 1;
            $data['customers'][$index]['answer_status'] = 'unanswered';

            $success++;
        } else {
            $failed++;
        }

        $details[] = [
            'customer_id' => $customer['id'],
            'email' => $customer['email'],
            'success' => $successFlag,
            'error' => $smtp['error'],
        ];
    }

    $data['mail_logs'][] = [
        'id' => survey_app_id('batch'),
        'survey_id' => $surveyId,
        'customer_id' => '',
        'sent_at' => survey_app_now(),
        'type' => $allowResend ? 'resend' : $templateType,
        'success' => $failed === 0,
        'target_count' => count($selected),
        'success_count' => $success,
        'failed_count' => $failed,
        'subject' => $subject,
        'body' => '',
        'error' => '',
        'executed_by' => (string)($_SESSION['admin_user'] ?? 'admin'),
    ];

    if (!survey_app_atomic_save($data)) {
        survey_app_json_response([
            'ok' => false,
            'message' => '送信結果の保存に失敗しました。',
        ], 500);
    }

    survey_app_json_response([
        'ok' => true,
        'success_count' => $success,
        'failed_count' => $failed,
        'details' => $details,
    ]);
}

function survey_app_handle_api(): never
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

    $data = survey_app_read_data();

    if ($method === 'POST') {
        survey_app_verify_csrf();
    }

    switch ($action) {
        case 'get_data':
            survey_app_json_response([
                'ok' => true,
                'data' => $data,
                'csrf_token' => survey_app_csrf(),
            ]);

        case 'save_survey':
            $survey = survey_app_request_json('survey_json');

            if ($survey === null) {
                survey_app_json_response([
                    'ok' => false,
                    'message' => 'survey_jsonが不正です。',
                ], 400);
            }

            $survey = survey_app_normalize_survey($survey);
            $errors = survey_app_validate_survey($survey);

            if ($errors !== []) {
                survey_app_json_response([
                    'ok' => false,
                    'message' => implode("\n", $errors),
                ], 422);
            }

            $index = survey_app_find_survey_index($data, $survey['id']);

            if ($index >= 0) {
                $survey['created_at'] = $data['surveys'][$index]['created_at'];
                $data['surveys'][$index] = $survey;
            } else {
                $data['surveys'][] = $survey;
            }

            if (!survey_app_atomic_save($data)) {
                survey_app_json_response([
                    'ok' => false,
                    'message' => '保存に失敗しました。',
                ], 500);
            }

            survey_app_json_response([
                'ok' => true,
                'survey' => $survey,
            ]);

        case 'stop_survey':
            $id = (string)($_POST['survey_id'] ?? '');
            $index = survey_app_find_survey_index($data, $id);

            if ($index < 0) {
                survey_app_json_response([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $data['surveys'][$index]['status'] = 'ended';
            $data['surveys'][$index]['updated_at'] = survey_app_now();

            survey_app_atomic_save($data);

            survey_app_json_response(['ok' => true]);

        case 'resume_survey':
            $id = (string)($_POST['survey_id'] ?? '');
            $index = survey_app_find_survey_index($data, $id);

            if ($index < 0) {
                survey_app_json_response([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $data['surveys'][$index]['status'] = 'active';
            $data['surveys'][$index]['updated_at'] = survey_app_now();

            survey_app_atomic_save($data);

            survey_app_json_response(['ok' => true]);

        case 'delete_survey':
            $id = (string)($_POST['survey_id'] ?? '');
            $index = survey_app_find_survey_index($data, $id);

            if ($index < 0) {
                survey_app_json_response([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $data['surveys'][$index]['deleted'] = true;
            $data['surveys'][$index]['updated_at'] = survey_app_now();

            survey_app_atomic_save($data);

            survey_app_json_response(['ok' => true]);

        case 'duplicate_survey':
            $id = (string)($_POST['survey_id'] ?? '');
            $survey = survey_app_find_survey($data, $id);

            if ($survey === null) {
                survey_app_json_response([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $copy = $survey;
            $copy['id'] = survey_app_id('survey');
            $copy['title'] = $survey['title'] . '（複製）';
            $copy['status'] = 'draft';
            $copy['deleted'] = false;
            $copy['created_at'] = survey_app_now();
            $copy['updated_at'] = survey_app_now();

            foreach ($copy['groups'] as &$group) {
                $group['id'] = survey_app_id('group');

                foreach ($group['questions'] as &$question) {
                    $question['id'] = survey_app_id('question');
                }

                unset($question);
            }

            unset($group);

            survey_app_renumber($copy);
            $data['surveys'][] = $copy;

            survey_app_atomic_save($data);

            survey_app_json_response([
                'ok' => true,
                'survey' => $copy,
            ]);

        case 'save_settings':
            $settings = survey_app_request_json('settings_json');

            if ($settings === null) {
                survey_app_json_response([
                    'ok' => false,
                    'message' => 'settings_jsonが不正です。',
                ], 400);
            }

            if (
                isset($settings['password']) &&
                $settings['password'] === ''
            ) {
                unset($settings['password']);
                if (isset($data['settings']['password'])) {
                    $settings['password'] = $data['settings']['password'];
                }
            }

            if (
                isset($settings['smtp_password']) &&
                $settings['smtp_password'] === ''
            ) {
                unset($settings['smtp_password']);
                if (isset($data['settings']['smtp_password'])) {
                    $settings['smtp_password'] = $data['settings']['smtp_password'];
                }
            }

            $data['settings'] = $settings;

            if (!survey_app_atomic_save($data)) {
                survey_app_json_response([
                    'ok' => false,
                    'message' => '設定保存に失敗しました。',
                ], 500);
            }

            $safeSettings = $settings;
            unset($safeSettings['password'], $safeSettings['smtp_password']);

            survey_app_json_response([
                'ok' => true,
                'settings' => $safeSettings,
            ]);

        case 'kintone_fields':
            $settings = $data['settings'];

            $subdomain = (string)($settings['subdomain'] ?? '');
            $appId = (string)($_POST['app_id'] ?? $settings['app_id'] ?? '');

            if ($subdomain === '' || $appId === '') {
                survey_app_json_response([
                    'ok' => false,
                    'message' => 'kintoneサブドメインとアプリIDを指定してください。',
                ], 422);
            }

            $url = survey_app_kintone_url(
                $subdomain,
                '/k/v1/app/form/fields.json?app=' . rawurlencode($appId)
            );

            $result = survey_app_http_request(
                $url,
                'GET',
                survey_app_kintone_headers($settings),
                null,
                (bool)($settings['ssl_verify'] ?? true),
                (string)($settings['proxy'] ?? '')
            );

            if (!$result['success']) {
                $message = 'kintone APIエラー';

                if (is_array($result['json'])) {
                    $message .= ': ' . (string)($result['json']['message'] ?? '');
                }

                survey_app_json_response([
                    'ok' => false,
                    'message' => $message,
                    'status' => $result['status'],
                    'api_response' => $result['json'],
                ], 502);
            }

            $properties = $result['json']['properties'] ?? [];
            $fields = [];

            foreach ($properties as $code => $property) {
                $fields[] = [
                    'label' => (string)($property['label'] ?? ''),
                    'code' => (string)$code,
                    'type' => (string)($property['type'] ?? ''),
                ];
            }

            survey_app_json_response([
                'ok' => true,
                'properties' => $properties,
                'fields' => $fields,
            ]);

        case 'kintone_test':
            $settings = $data['settings'];

            $url = survey_app_kintone_url(
                (string)($settings['subdomain'] ?? ''),
                '/k/v1/app.json?app=' . rawurlencode((string)($settings['app_id'] ?? ''))
            );

            $result = survey_app_http_request(
                $url,
                'GET',
                survey_app_kintone_headers($settings),
                null,
                (bool)($settings['ssl_verify'] ?? true),
                (string)($settings['proxy'] ?? '')
            );

            survey_app_json_response([
                'ok' => $result['success'],
                'status' => $result['status'],
                'api_response' => $result['json'],
                'message' => $result['success']
                    ? 'kintone接続に成功しました。'
                    : 'kintone接続に失敗しました。',
            ], $result['success'] ? 200 : 502);

        case 'sync_customers':
            $settings = $data['settings'];

            $url = survey_app_kintone_url(
                (string)($settings['subdomain'] ?? ''),
                '/k/v1/records.json?app=' .
                rawurlencode((string)($settings['app_id'] ?? '')) .
                '&query=' . rawurlencode('limit 500')
            );

            $result = survey_app_http_request(
                $url,
                'GET',
                survey_app_kintone_headers($settings),
                null,
                (bool)($settings['ssl_verify'] ?? true),
                (string)($settings['proxy'] ?? '')
            );

            if (!$result['success']) {
                survey_app_json_response([
                    'ok' => false,
                    'message' => 'kintone顧客同期に失敗しました。',
                    'status' => $result['status'],
                    'api_response' => $result['json'],
                ], 502);
            }

            $records = $result['json']['records'] ?? [];
            $count = 0;

            foreach ($records as $record) {
                $emailCode = (string)($settings['field_email'] ?? '');
                $email = '';

                if ($emailCode !== '') {
                    $email = (string)($record[$emailCode]['value'] ?? '');
                }

                if ($email === '') {
                    continue;
                }

                $existingIndex = -1;

                foreach ($data['customers'] as $i => $customer) {
                    if (
                        ($customer['source'] ?? '') === 'kintone' &&
                        strcasecmp((string)$customer['email'], $email) === 0
                    ) {
                        $existingIndex = $i;
                        break;
                    }
                }

                $existingId = $existingIndex >= 0
                    ? (string)$data['customers'][$existingIndex]['id']
                    : '';

                $customer = survey_app_customer_from_record(
                    $record,
                    $settings,
                    $existingId
                );

                if ($existingIndex >= 0) {
                    $customer['sent_at'] = $data['customers'][$existingIndex]['sent_at'] ?? '';
                    $customer['send_count'] = $data['customers'][$existingIndex]['send_count'] ?? 0;
                    $customer['answer_status'] = $data['customers'][$existingIndex]['answer_status'] ?? 'unanswered';

                    $data['customers'][$existingIndex] = $customer;
                } else {
                    $data['customers'][] = $customer;
                }

                $count++;
            }

            survey_app_atomic_save($data);

            survey_app_json_response([
                'ok' => true,
                'count' => $count,
            ]);

        case 'send_mail':
            survey_app_send_api($data);

        case 'smtp_test':
            $settings = $data['settings'];
            $to = trim((string)($_POST['smtp_test_to'] ?? ''));

            $result = survey_app_smtp_send(
                $settings,
                $to,
                '',
                'アンケート管理システム SMTP送信テスト',
                "アンケート管理システムのSMTPテストメールです。\n\nSMTP接続・認証・送信の確認用です。"
            );

            survey_app_json_response([
                'ok' => $result['success'],
                'smtp_code' => $result['code'],
                'error' => $result['error'],
            ], $result['success'] ? 200 : 502);

        case 'response':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $token = (string)($_POST['token'] ?? '');

            $surveyIndex = survey_app_find_survey_index($data, $surveyId);

            if ($surveyIndex < 0 || $data['surveys'][$surveyIndex]['deleted']) {
                survey_app_json_response([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。',
                ], 404);
            }

            $answers = survey_app_request_json('answers');

            if ($answers === null) {
                $answers = [];
            }

            $email = trim((string)($_POST['email'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $company = trim((string)($_POST['company'] ?? ''));

            $customerId = '';

            foreach ($data['customers'] as $customer) {
                if (
                    $email !== '' &&
                    strcasecmp((string)$customer['email'], $email) === 0
                ) {
                    $customerId = (string)$customer['id'];
                    break;
                }
            }

            if ($customerId === '') {
                $customerId = survey_app_id('web_customer');

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
                    'kintone_status' => 'unregistered',
                ];
            } else {
                $ci = survey_app_find_customer_index($data, $customerId);

                if ($ci >= 0) {
                    $data['customers'][$ci]['answer_status'] = 'answered';
                }
            }

            $response = [
                'id' => survey_app_id('response'),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'company' => $company,
                'name' => $name,
                'email' => $email,
                'answered_at' => survey_app_now(),
                'answers' => $answers,
                'answer_token' => $token,
            ];

            $data['responses'][] = $response;

            survey_app_atomic_save($data);

            survey_app_json_response([
                'ok' => true,
                'response_id' => $response['id'],
            ]);

        case 'survey_stats':
            $surveyId = (string)($_POST['survey_id'] ?? '');

            $targetCustomers = [];

            foreach ($data['mail_logs'] as $log) {
                if (
                    ($log['survey_id'] ?? '') === $surveyId &&
                    ($log['customer_id'] ?? '') !== '' &&
                    ($log['success'] ?? false) === true
                ) {
                    $targetCustomers[(string)$log['customer_id']] = true;
                }
            }

            $responses = array_values(array_filter(
                $data['responses'],
                static fn(array $r): bool => ($r['survey_id'] ?? '') === $surveyId
            ));

            $registeredResponses = 0;
            $unregisteredResponses = 0;

            foreach ($responses as $response) {
                $ci = survey_app_find_customer_index($data, (string)$response['customer_id']);

                if (
                    $ci >= 0 &&
                    ($data['customers'][$ci]['kintone_status'] ?? '') === 'registered'
                ) {
                    $registeredResponses++;
                } else {
                    $unregisteredResponses++;
                }
            }

            $targetCount = count($targetCustomers);
            $responseCount = count($responses);

            survey_app_json_response([
                'ok' => true,
                'stats' => [
                    'target_count' => $targetCount,
                    'response_count' => $responseCount,
                    'unregistered_response_count' => $unregisteredResponses,
                    'unanswered_count' => max(0, $targetCount - $responseCount),
                    'response_rate' => $targetCount > 0
                        ? round(($registeredResponses / $targetCount) * 100, 2)
                        : 0,
                ],
            ]);

        case 'export_csv':
            $surveyId = (string)($_GET['survey_id'] ?? '');

            $survey = survey_app_find_survey($data, $surveyId);

            if ($survey === null) {
                http_response_code(404);
                exit('Not Found');
            }

            $questions = [];

            foreach ($survey['groups'] as $group) {
                foreach ($group['questions'] as $question) {
                    $questions[] = $question;
                }
            }

            header('Content-Type: text/csv; charset=UTF-8');
            header(
                'Content-Disposition: attachment; filename="survey_' .
                rawurlencode($surveyId) . '.csv"'
            );

            echo "\xEF\xBB\xBF";

            $fp = fopen('php://output', 'wb');

            $header = [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名',
            ];

            foreach ($questions as $question) {
                $header[] = (string)($question['number'] ?? '') . ' ' .
                    (string)$question['text'];
            }

            fputcsv($fp, array_map('survey_app_csv_safe', $header));

            foreach ($data['responses'] as $response) {
                if (($response['survey_id'] ?? '') !== $surveyId) {
                    continue;
                }

                $row = [
                    $response['id'] ?? '',
                    $response['answered_at'] ?? '',
                    $response['customer_id'] ?? '',
                    $response['company'] ?? '',
                    $response['name'] ?? '',
                ];

                foreach ($questions as $question) {
                    $value = $response['answers'][$question['id']] ?? '';

                    if (is_array($value)) {
                        $value = implode(', ', array_map('strval', $value));
                    }

                    $row[] = (string)$value;
                }

                fputcsv($fp, array_map('survey_app_csv_safe', $row));
            }

            fclose($fp);
            exit;

        default:
            survey_app_json_response([
                'ok' => false,
                'message' => 'Unknown action.',
            ], 400);
    }
}

/* Public answer page is handled separately. */
function survey_app_public_answer_page(): never
{
    $data = survey_app_read_data();

    $surveyId = (string)($_GET['survey_id'] ?? '');
    $token = (string)($_GET['token'] ?? '');

    $survey = survey_app_find_survey($data, $surveyId);

    if ($survey === null || $survey['deleted'] || $survey['status'] !== 'active') {
        http_response_code(404);
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>アンケート</title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-gray-100 min-h-screen flex items-center justify-center p-6">
            <div class="bg-white rounded-xl shadow p-8 max-w-lg w-full text-center">
                <h1 class="text-xl font-bold mb-3">アンケートを表示できません</h1>
                <p class="text-gray-600">このアンケートは公開されていないか、終了しています。</p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    $csrf = survey_app_csrf();
    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= survey_app_h($survey['title']) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-100 min-h-screen">
    <main class="max-w-3xl mx-auto p-4 sm:p-8">
        <div class="bg-white rounded-2xl shadow p-6 sm:p-8">
            <h1 class="text-2xl font-bold mb-8"><?= survey_app_h($survey['title']) ?></h1>

            <form id="publicAnswerForm" class="space-y-8">
                <input type="hidden" name="survey_id" value="<?= survey_app_h($surveyId) ?>">
                <input type="hidden" name="token" value="<?= survey_app_h($token) ?>">
                <input type="hidden" name="csrf_token" value="<?= survey_app_h($csrf) ?>">

                <div class="grid sm:grid-cols-2 gap-4">
                    <label class="block">
                        <span class="text-sm font-medium">会社名</span>
                        <input id="public_company"
                               class="mt-1 w-full border rounded-lg px-3 py-2"
                               required>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">氏名</span>
                        <input id="public_name"
                               class="mt-1 w-full border rounded-lg px-3 py-2"
                               required>
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium">メールアドレス</span>
                        <input id="public_email"
                               type="email"
                               class="mt-1 w-full border rounded-lg px-3 py-2"
                               required>
                    </label>
                </div>

                <?php foreach ($survey['groups'] as $group): ?>
                    <section class="space-y-5">
                        <h2 class="text-lg font-bold border-b pb-2">
                            <?= survey_app_h($group['name']) ?>
                        </h2>

                        <?php foreach ($group['questions'] as $question): ?>
                            <div class="border rounded-xl p-4">
                                <div class="font-medium mb-3">
                                    <?= survey_app_h($question['number'] ?? '') ?>
                                    <?= survey_app_h($question['text']) ?>
                                    <?php if ($question['required']): ?>
                                        <span class="text-red-500">*</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($question['type'] === 'text'): ?>
                                    <textarea
                                        data-question-id="<?= survey_app_h($question['id']) ?>"
                                        class="question-answer w-full border rounded-lg p-3 min-h-28"
                                        <?= $question['required'] ? 'required' : '' ?>
                                    ></textarea>
                                <?php else: ?>
                                    <div class="space-y-2">
                                        <?php foreach ($question['options'] as $option): ?>
                                            <?php $option = (string)$option; ?>
                                            <label class="flex gap-2 items-center">
                                                <input
                                                    type="<?= $question['type'] === 'multiple' ? 'checkbox' : 'radio' ?>"
                                                    name="q_<?= survey_app_h($question['id']) ?><?= $question['type'] === 'multiple' ? '[]' : '' ?>"
                                                    value="<?= survey_app_h($option) ?>"
                                                    data-question-id="<?= survey_app_h($question['id']) ?>"
                                                    class="question-answer"
                                                    <?= $question['required'] ? 'required' : '' ?>
                                                >
                                                <span><?= survey_app_h($option) ?></span>
                                            </label>
                                        <?php endforeach; ?>

                                        <?php if ($question['other_enabled']): ?>
                                            <label class="block mt-3">
                                                <span class="text-sm text-gray-600">その他</span>
                                                <input
                                                    data-other-question-id="<?= survey_app_h($question['id']) ?>"
                                                    class="w-full border rounded-lg px-3 py-2 mt-1"
                                                >
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>

                <button
                    id="publicSubmit"
                    type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg py-3">
                    回答を送信
                </button>

                <div id="publicMessage" class="hidden rounded-lg p-4"></div>
            </form>
        </div>
    </main>

    <script>
    (() => {
        const form = document.getElementById('publicAnswerForm');
        const message = document.getElementById('publicMessage');
        const submit = document.getElementById('publicSubmit');

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            submit.disabled = true;

            const answers = {};

            document.querySelectorAll('[data-question-id]').forEach((element) => {
                const id = element.dataset.questionId;

                if (element.type === 'checkbox') {
                    if (!answers[id]) answers[id] = [];

                    if (element.checked) {
                        answers[id].push(element.value);
                    }
                } else if (element.type === 'radio') {
                    if (element.checked) {
                        answers[id] = element.value;
                    }
                } else {
                    answers[id] = element.value;
                }
            });

            document.querySelectorAll('[data-other-question-id]').forEach((element) => {
                const id = element.dataset.otherQuestionId;

                if (element.value.trim() !== '') {
                    answers[id + '__other'] = element.value;
                }
            });

            const formData = new FormData();

            formData.append('action', 'response');
            formData.append('survey_id', form.elements.survey_id.value);
            formData.append('token', form.elements.token.value);
            formData.append('csrf_token', form.elements.csrf_token.value);
            formData.append('company', document.getElementById('public_company').value);
            formData.append('name', document.getElementById('public_name').value);
            formData.append('email', document.getElementById('public_email').value);
            formData.append('answers', JSON.stringify(answers));

            try {
                const response = await fetch(location.href, {
                    method: 'POST',
                    body: formData
                });

                const json = await response.json();

                if (!json.ok) {
                    throw new Error(json.message || '送信に失敗しました。');
                }

                message.className = 'rounded-lg p-4 bg-green-100 text-green-800';
                message.textContent = '回答を受け付けました。ありがとうございました。';
                message.classList.remove('hidden');

                form.querySelectorAll('input,textarea,button').forEach(el => {
                    el.disabled = true;
                });
            } catch (error) {
                message.className = 'rounded-lg p-4 bg-red-100 text-red-800';
                message.textContent = error.message;
                message.classList.remove('hidden');
                submit.disabled = false;
            }
        });
    })();
    </script>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['answer'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        survey_app_handle_api();
    }

    survey_app_public_answer_page();
}

if (
    isset($_GET['action']) ||
    isset($_POST['action'])
) {
    survey_app_handle_api();
}

survey_app_ensure_storage();
$csrf = survey_app_csrf();
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= survey_app_h($csrf) ?>">
    <title>アンケート管理システム</title>

    <!-- Development CDN.
         Production: replace with compiled Tailwind CSS. -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-gray-100 text-gray-900 min-h-screen">

<header class="sticky top-0 z-40 bg-white border-b shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">
        <div class="h-16 flex items-center justify-between">
            <div class="font-bold text-lg">
                アンケート管理システム
            </div>

            <nav class="flex items-center gap-2">
                <button
                    onclick="App.actions.goList()"
                    class="px-3 py-2 rounded-lg hover:bg-gray-100 text-sm">
                    アンケート一覧
                </button>

                <button
                    onclick="App.actions.goSettings()"
                    class="px-3 py-2 rounded-lg hover:bg-gray-100 text-sm">
                    kintone連携設定
                </button>

                <button
                    onclick="App.actions.logout()"
                    class="px-3 py-2 rounded-lg hover:bg-gray-100 text-sm">
                    ログアウト
                </button>
            </nav>
        </div>
    </div>
</header>

<input
    type="hidden"
    id="csrf_token"
    value="<?= survey_app_h($csrf) ?>">

<main id="app" class="max-w-7xl mx-auto px-4 sm:px-6 py-6"></main>

<!-- Preview -->
<div
    id="preview_modal"
    class="hidden fixed inset-0 z-50 bg-black/50 p-4"
    role="dialog"
    aria-modal="true">

    <div class="bg-white rounded-2xl shadow-xl max-w-5xl mx-auto h-full max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between p-4 border-b">
            <h2 class="font-bold">プレビュー</h2>

            <div class="flex gap-2">
                <button
                    onclick="App.actions.setPreviewMode('pc')"
                    class="px-3 py-2 border rounded-lg">
                    PC
                </button>
                <button
                    onclick="App.actions.setPreviewMode('mobile')"
                    class="px-3 py-2 border rounded-lg">
                    スマートフォン
                </button>
                <button
                    onclick="App.actions.closeModal('preview_modal')"
                    class="px-3 py-2 border rounded-lg">
                    閉じる
                </button>
            </div>
        </div>

        <div class="overflow-auto p-6 flex-1">
            <div id="preview_content"></div>
        </div>
    </div>
</div>

<!-- Response -->
<div
    id="response_modal"
    class="hidden fixed inset-0 z-50 bg-black/50 p-4"
    role="dialog"
    aria-modal="true">

    <div class="bg-white rounded-2xl shadow-xl max-w-4xl mx-auto max-h-[90vh] overflow-auto">
        <div class="sticky top-0 bg-white border-b p-4 flex justify-between">
            <h2 class="font-bold">全回答</h2>

            <button
                onclick="App.actions.closeModal('response_modal')"
                class="px-3 py-2 border rounded-lg">
                閉じる
            </button>
        </div>

        <div id="response_detail" class="p-6"></div>
    </div>
</div>

<script>
window.App = {
    state: {
        initialized: false,
        loading: false,
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },
        view: 'list',
        selectedSurveyId: null,
        editingSurvey: null,
        keyword: '',
        statusFilter: '',
        sort: 'updated_desc',
        previewMode: 'pc',
        selectedQuestions: [],
        settingsFields: []
    },

    render: {},

    actions: {},

    api: {},

    utils: {},

    init: function () {
        if (this.state.initialized) {
            return;
        }

        this.state.initialized = true;

        this.api.loadData()
            .then(() => {
                const params = new URLSearchParams(location.search);
                const surveyId = params.get('survey_id');
                const view = params.get('view');

                if (view === 'settings') {
                    this.actions.goSettings();
                } else if (surveyId) {
                    this.actions.editSurvey(surveyId);
                } else {
                    this.actions.goList();
                }
            })
            .catch((error) => {
                this.utils.notify(error.message, 'error');
            });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                document.querySelectorAll('[role="dialog"]').forEach((el) => {
                    el.classList.add('hidden');
                });
            }
        });
    },

    initSortable: function () {
        if (typeof Sortable === 'undefined') {
            return;
        }

        const groupContainer = document.getElementById('question_editor');

        if (!groupContainer) {
            return;
        }

        document.querySelectorAll('.sortable-groups').forEach((element) => {
            Sortable.create(element, {
                animation: 150,
                handle: '.group-handle',
                onEnd: () => {
                    const ids = [...element.children]
                        .map(child => child.dataset.groupId)
                        .filter(Boolean);

                    const groups = [];

                    ids.forEach(id => {
                        const group = this.state.editingSurvey.groups.find(
                            item => item.id === id
                        );

                        if (group) {
                            groups.push(group);
                        }
                    });

                    this.state.editingSurvey.groups = groups;

                    this.actions.renumberQuestions();
                    this.render.editor();
                    this.initSortable();
                }
            });
        });

        document.querySelectorAll('.sortable-questions').forEach((element) => {
            Sortable.create(element, {
                animation: 150,
                group: 'survey-questions',
                handle: '.question-handle',

                onEnd: () => {
                    this.actions.moveQuestion();
                }
            });
        });
    }
};

App.utils.escape = function (value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.utils.notify = function (message, type = 'success') {
    const color = type === 'error'
        ? 'bg-red-600'
        : 'bg-green-600';

    const element = document.createElement('div');

    element.className =
        'fixed right-4 bottom-4 z-[100] max-w-md text-white px-4 py-3 rounded-xl shadow-lg ' +
        color;

    element.textContent = message;

    document.body.appendChild(element);

    setTimeout(() => {
        element.remove();
    }, 4000);
};

App.utils.uuid = function (prefix) {
    return prefix + '_' +
        Date.now().toString(36) + '_' +
        Math.random().toString(36).slice(2, 10);
};

App.utils.getSurvey = function (id) {
    return App.state.data.surveys.find(
        survey => survey.id === id && !survey.deleted
    );
};

App.api.request = async function (action, payload = {}, method = 'POST') {
    if (App.state.loading) {
        throw new Error('処理中です。しばらくお待ちください。');
    }

    App.state.loading = true;

    try {
        const formData = new FormData();

        formData.append('action', action);
        formData.append(
            'csrf_token',
            document.getElementById('csrf_token').value
        );

        Object.entries(payload).forEach(([key, value]) => {
            if (typeof value === 'object') {
                formData.append(key, JSON.stringify(value));
            } else {
                formData.append(key, value == null ? '' : value);
            }
        });

        const response = await fetch(location.pathname, {
            method,
            body: formData,
            credentials: 'same-origin'
        });

        const json = await response.json();

        if (!json.ok) {
            const error = new Error(
                json.message || 'API処理に失敗しました。'
            );

            Object.assign(error, json);

            throw error;
        }

        return json;
    } finally {
        App.state.loading = false;
    }
};

App.api.loadData = async function () {
    const json = await fetch(
        location.pathname + '?action=get_data',
        { credentials: 'same-origin' }
    ).then(response => response.json());

    if (!json.ok) {
        throw new Error(json.message || 'データ取得に失敗しました。');
    }

    App.state.data = json.data;

    if (json.csrf_token) {
        document.getElementById('csrf_token').value = json.csrf_token;
    }
};

App.render.shell = function (breadcrumb, content) {
    return `
        <div class="mb-6">
            <div class="text-sm text-gray-500 mb-2">
                ホーム
                <span class="mx-2">›</span>
                ${App.utils.escape(breadcrumb)}
            </div>
        </div>

        ${content}
    `;
};

App.render.list = function () {
    const surveys = App.state.data.surveys
        .filter(s => !s.deleted)
        .filter(s => {
            if (!App.state.keyword) return true;

            return s.title.toLowerCase().includes(
                App.state.keyword.toLowerCase()
            );
        })
        .filter(s => {
            return !App.state.statusFilter ||
                s.status === App.state.statusFilter;
        });

    const getResponseCount = (surveyId) => {
        return App.state.data.responses.filter(
            r => r.survey_id === surveyId
        ).length;
    };

    surveys.sort((a, b) => {
        switch (App.state.sort) {
            case 'updated_asc':
                return a.updated_at.localeCompare(b.updated_at);

            case 'responses_desc':
                return getResponseCount(b.id) - getResponseCount(a.id);

            case 'responses_asc':
                return getResponseCount(a.id) - getResponseCount(b.id);

            case 'start_desc':
                return (b.start_at || '').localeCompare(a.start_at || '');

            case 'start_asc':
                return (a.start_at || '').localeCompare(b.start_at || '');

            default:
                return b.updated_at.localeCompare(a.updated_at);
        }
    });

    const rows = surveys.map((survey) => {
        const count = getResponseCount(survey.id);

        const badge =
            survey.status === 'active'
                ? 'bg-green-100 text-green-700'
                : survey.status === 'ended'
                    ? 'bg-gray-200 text-gray-700'
                    : 'bg-yellow-100 text-yellow-700';

        const statusText =
            survey.status === 'active'
                ? '公開中'
                : survey.status === 'ended'
                    ? '終了'
                    : '下書き';

        let operations = '';

        if (survey.status === 'active') {
            operations = `
                <button onclick="App.actions.editSurvey('${survey.id}')"
                    class="text-blue-600 hover:underline">確認・編集</button>
                <button onclick="App.actions.goStats('${survey.id}')"
                    class="text-blue-600 hover:underline">集計</button>
                <button onclick="App.actions.goSend('${survey.id}')"
                    class="text-blue-600 hover:underline">送信</button>
                <button onclick="App.actions.stopSurvey('${survey.id}')"
                    class="text-red-600 hover:underline">停止</button>
                <button onclick="App.actions.duplicateSurvey('${survey.id}')"
                    class="text-gray-600 hover:underline">複製</button>
            `;
        } else if (survey.status === 'draft') {
            operations = `
                <button onclick="App.actions.editSurvey('${survey.id}')"
                    class="text-blue-600 hover:underline">確認・編集</button>
                <button onclick="App.actions.deleteSurvey('${survey.id}')"
                    class="text-red-600 hover:underline">削除</button>
                <button onclick="App.actions.duplicateSurvey('${survey.id}')"
                    class="text-gray-600 hover:underline">複製</button>
            `;
        } else {
            operations = `
                <button onclick="App.actions.editSurvey('${survey.id}', true)"
                    class="text-blue-600 hover:underline">確認・編集</button>
                <button onclick="App.actions.goStats('${survey.id}')"
                    class="text-blue-600 hover:underline">集計</button>
                <button onclick="App.actions.resumeSurvey('${survey.id}')"
                    class="text-green-600 hover:underline">再開</button>
                <button onclick="App.actions.duplicateSurvey('${survey.id}')"
                    class="text-gray-600 hover:underline">複製</button>
            `;
        }

        return `
            <tr class="border-b hover:bg-gray-50">
                <td class="px-4 py-3 text-sm">${App.utils.escape(survey.created_at)}</td>
                <td class="px-4 py-3 text-sm">${App.utils.escape(survey.updated_at)}</td>
                <td class="px-4 py-3 font-medium">${App.utils.escape(survey.title)}</td>
                <td class="px-4 py-3 text-sm">${App.utils.escape(survey.start_at)}</td>
                <td class="px-4 py-3 text-sm">${App.utils.escape(survey.end_at)}</td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 rounded-full text-xs ${badge}">
                        ${statusText}
                    </span>
                </td>
                <td class="px-4 py-3 text-center">${count}</td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-3 text-sm">
                        ${operations}
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    App.state.view = 'list';

    document.getElementById('app').innerHTML = App.render.shell(
        'アンケート一覧',
        `
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold">アンケート一覧</h1>
                <p class="text-gray-500 mt-1">アンケートの作成・公開・集計を管理します。</p>
            </div>

            <button
                onclick="App.actions.createSurvey()"
                class="bg-blue-600 hover:bg-blue-700 text-white rounded-lg px-4 py-2 font-medium">
                ＋ 新規アンケート作成
            </button>
        </div>

        <div class="bg-white rounded-xl shadow-sm border p-4 mb-5">
            <div class="grid md:grid-cols-3 gap-3">
                <input
                    id="surveyFilterKeyword"
                    value="${App.utils.escape(App.state.keyword)}"
                    oninput="App.actions.filterSurveys(this.value)"
                    placeholder="タイトルを検索"
                    class="border rounded-lg px-3 py-2">

                <select
                    onchange="App.actions.toggleStatusFilter(this.value)"
                    class="border rounded-lg px-3 py-2">
                    <option value="">すべてのステータス</option>
                    <option value="draft" ${App.state.statusFilter === 'draft' ? 'selected' : ''}>下書き</option>
                    <option value="active" ${App.state.statusFilter === 'active' ? 'selected' : ''}>公開中</option>
                    <option value="ended" ${App.state.statusFilter === 'ended' ? 'selected' : ''}>終了</option>
                </select>

                <select
                    onchange="App.actions.sortSurveys(this.value)"
                    class="border rounded-lg px-3 py-2">
                    <option value="updated_desc" ${App.state.sort === 'updated_desc' ? 'selected' : ''}>更新日 新しい順</option>
                    <option value="updated_asc" ${App.state.sort === 'updated_asc' ? 'selected' : ''}>更新日 古い順</option>
                    <option value="responses_desc" ${App.state.sort === 'responses_desc' ? 'selected' : ''}>回答数 多い順</option>
                    <option value="responses_asc" ${App.state.sort === 'responses_asc' ? 'selected' : ''}>回答数 少ない順</option>
                    <option value="start_desc" ${App.state.sort === 'start_desc' ? 'selected' : ''}>開始日 新しい順</option>
                    <option value="start_asc" ${App.state.sort === 'start_asc' ? 'selected' : ''}>開始日 古い順</option>
                </select>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1100px]">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-4 py-3 text-sm">作成日</th>
                            <th class="text-left px-4 py-3 text-sm">更新日</th>
                            <th class="text-left px-4 py-3 text-sm">タイトル</th>
                            <th class="text-left px-4 py-3 text-sm">開始日時</th>
                            <th class="text-left px-4 py-3 text-sm">終了日時</th>
                            <th class="text-left px-4 py-3 text-sm">ステータス</th>
                            <th class="text-center px-4 py-3 text-sm">回答数</th>
                            <th class="text-left px-4 py-3 text-sm">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows || `
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-gray-500">
                                    アンケートがありません。
                                </td>
                            </tr>
                        `}
                    </tbody>
                </table>
            </div>
        </div>
        `
    );
};

App.actions.goList = function () {
    history.pushState({}, '', location.pathname);
    App.render.list();
};

App.actions.createSurvey = function () {
    const now = new Date();

    App.state.editingSurvey = {
        id: App.utils.uuid('survey'),
        title: '',
        start_at: now.toISOString().slice(0, 16),
        end_at: '',
        status: 'draft',
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        numbering_mode: 'global',
        groups: [
            {
                id: App.utils.uuid('group'),
                name: 'グループ1',
                questions: []
            }
        ],
        deleted: false
    };

    App.render.editor();
};

App.actions.editSurvey = function (id, readOnly = false) {
    const survey = App.utils.getSurvey(id);

    if (!survey) {
        App.utils.notify('アンケートが見つかりません。', 'error');
        return;
    }

    App.state.selectedSurveyId = id;
    App.state.editingSurvey = JSON.parse(JSON.stringify(survey));
    App.state.readOnly = readOnly;

    history.pushState(
        {},
        '',
        location.pathname + '?survey_id=' + encodeURIComponent(id)
    );

    App.render.editor();
};

App.render.editor = function () {
    const survey = App.state.editingSurvey;

    if (!survey) return;

    const disabled = App.state.readOnly ? 'disabled' : '';

    const groups = survey.groups.map((group) => `
        <section
            data-group-id="${App.utils.escape(group.id)}"
            class="bg-white border rounded-xl shadow-sm overflow-hidden">

            <div class="flex items-center gap-3 p-4 bg-gray-50 border-b">
                <span class="group-handle cursor-move text-gray-400">☷</span>

                <input
                    value="${App.utils.escape(group.name)}"
                    ${disabled}
                    onchange="App.actions.updateGroupName('${group.id}', this.value)"
                    class="font-bold bg-transparent border-0 focus:ring-0 flex-1">

                ${disabled ? '' : `
                    <button
                        onclick="App.actions.deleteGroup('${group.id}')"
                        class="text-red-600 text-sm">
                        削除
                    </button>
                `}
            </div>

            <div
                class="sortable-questions p-4 space-y-4 min-h-20"
                data-group-id="${App.utils.escape(group.id)}">

                ${group.questions.map((question) =>
                    App.render.question(group, question)
                ).join('')}

                ${disabled ? '' : `
                    <button
                        onclick="App.actions.addQuestion('${group.id}')"
                        class="w-full border-2 border-dashed rounded-lg py-3 text-gray-500 hover:text-blue-600">
                        ＋ 質問を追加
                    </button>
                `}
            </div>
        </section>
    `).join('');

    App.state.view = 'editor';

    document.getElementById('app').innerHTML = App.render.shell(
        'アンケート編集',
        `
        <div class="flex items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold">
                    ${App.state.readOnly ? 'アンケート確認' : 'アンケート編集'}
                </h1>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    onclick="App.actions.preview()"
                    class="border rounded-lg px-4 py-2">
                    プレビュー
                </button>

                ${App.state.readOnly ? `
                    <button
                        onclick="App.actions.goList()"
                        class="border rounded-lg px-4 py-2">
                        戻る
                    </button>
                ` : `
                    <button
                        onclick="App.actions.cancelEdit()"
                        class="border rounded-lg px-4 py-2">
                        キャンセル
                    </button>

                    <button
                        onclick="App.actions.saveSurvey()"
                        class="bg-blue-600 text-white rounded-lg px-4 py-2">
                        保存
                    </button>
                `}
            </div>
        </div>

        <div class="bg-white border rounded-xl shadow-sm p-5 mb-5">
            <div class="grid md:grid-cols-3 gap-4">
                <label class="block md:col-span-3">
                    <span class="text-sm font-medium">タイトル</span>
                    <input
                        id="survey_title"
                        ${disabled}
                        value="${App.utils.escape(survey.title)}"
                        oninput="App.actions.updateSurveyField('title', this.value)"
                        class="mt-1 w-full border rounded-lg px-3 py-2">
                </label>

                <label class="block">
                    <span class="text-sm font-medium">開始日時</span>
                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        ${disabled}
                        value="${App.utils.escape(survey.start_at)}"
                        onchange="App.actions.updateSurveyField('start_at', this.value)"
                        class="mt-1 w-full border rounded-lg px-3 py-2">
                </label>

                <label class="block">
                    <span class="text-sm font-medium">終了日時</span>
                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        ${disabled}
                        value="${App.utils.escape(survey.end_at)}"
                        onchange="App.actions.updateSurveyField('end_at', this.value)"
                        class="mt-1 w-full border rounded-lg px-3 py-2">
                </label>

                <label class="block">
                    <span class="text-sm font-medium">質問番号</span>
                    <select
                        id="survey_numbering_mode"
                        ${disabled}
                        onchange="App.actions.updateSurveyField('numbering_mode', this.value)"
                        class="mt-1 w-full border rounded-lg px-3 py-2">
                        <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>global（Q1, Q2...）</option>
                        <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>group（Q1-1, Q1-2...）</option>
                    </select>
                </label>

                <label class="block">
                    <span class="text-sm font-medium">公開状態</span>
                    <select
                        ${disabled}
                        onchange="App.actions.updateSurveyField('status', this.value)"
                        class="mt-1 w-full border rounded-lg px-3 py-2">
                        <option value="draft" ${survey.status === 'draft' ? 'selected' : ''}>下書き</option>
                        <option value="active" ${survey.status === 'active' ? 'selected' : ''}>公開中</option>
                        <option value="ended" ${survey.status === 'ended' ? 'selected' : ''}>終了</option>
                    </select>
                </label>
            </div>
        </div>

        ${disabled ? '' : `
            <div class="flex justify-between items-center mb-3">
                <h2 class="text-lg font-bold">質問・グループ</h2>

                <button
                    onclick="App.actions.addGroup()"
                    class="bg-blue-600 text-white rounded-lg px-4 py-2">
                    ＋ グループ追加
                </button>
            </div>
        `}

        <div
            id="question_editor"
            class="sortable-groups space-y-4">
            ${groups}
        </div>
        `
    );

    App.initSortable();
};

App.render.question = function (group, question) {
    const options = question.options || [];

    return `
        <article
            data-question-id="${App.utils.escape(question.id)}"
            class="border rounded-xl p-4 bg-white">

            <div class="flex items-center gap-3 mb-3">
                <span class="question-handle cursor-move text-gray-400">☷</span>

                <span class="font-bold text-blue-600">
                    ${App.utils.escape(question.number || '')}
                </span>

                <span class="text-xs px-2 py-1 bg-gray-100 rounded">
                    ${App.utils.escape(question.type)}
                </span>

                <div class="ml-auto">
                    <button
                        onclick="App.actions.deleteQuestion('${group.id}', '${question.id}')"
                        class="text-red-600 text-sm">
                        削除
                    </button>
                </div>
            </div>

            <div class="space-y-3">
                <textarea
                    onchange="App.actions.updateQuestion('${group.id}', '${question.id}', 'text', this.value)"
                    class="w-full border rounded-lg p-3"
                    placeholder="質問文">${App.utils.escape(question.text)}</textarea>

                <div class="grid md:grid-cols-3 gap-3">
                    <select
                        onchange="App.actions.updateQuestion('${group.id}', '${question.id}', 'type', this.value)"
                        class="border rounded-lg px-3 py-2">
                        <option value="single" ${question.type === 'single' ? 'selected' : ''}>単一選択</option>
                        <option value="multiple" ${question.type === 'multiple' ? 'selected' : ''}>複数選択</option>
                        <option value="text" ${question.type === 'text' ? 'selected' : ''}>自由記述</option>
                    </select>

                    <label class="flex items-center gap-2">
                        <input
                            type="checkbox"
                            ${question.required ? 'checked' : ''}
                            onchange="App.actions.updateQuestion('${group.id}', '${question.id}', 'required', this.checked)">
                        必須回答
                    </label>

                    <label class="flex items-center gap-2">
                        <input
                            type="checkbox"
                            ${question.other_enabled ? 'checked' : ''}
                            onchange="App.actions.updateQuestion('${group.id}', '${question.id}', 'other_enabled', this.checked)">
                        その他を許可
                    </label>
                </div>

                ${question.type !== 'text' ? `
                    <div>
                        <div class="font-medium text-sm mb-2">選択肢</div>

                        <div class="space-y-2">
                            ${options.map((option, index) => `
                                <div class="flex gap-2">
                                    <input
                                        value="${App.utils.escape(option)}"
                                        onchange="App.actions.updateOption('${group.id}', '${question.id}', ${index}, this.value)"
                                        class="flex-1 border rounded-lg px-3 py-2">

                                    <button
                                        onclick="App.actions.deleteOption('${group.id}', '${question.id}', ${index})"
                                        class="px-3 border rounded-lg text-red-600">
                                        削除
                                    </button>
                                </div>
                            `).join('')}
                        </div>

                        <button
                            onclick="App.actions.addOption('${group.id}', '${question.id}')"
                            class="mt-2 text-sm text-blue-600">
                            ＋ 選択肢追加
                        </button>
                    </div>
                ` : ''}
            </div>
        </article>
    `;
};

App.actions.updateSurveyField = function (field, value) {
    App.state.editingSurvey[field] = value;

    if (field === 'numbering_mode') {
        App.actions.renumberQuestions();
    }
};

App.actions.updateGroupName = function (id, value) {
    const group = App.state.editingSurvey.groups.find(g => g.id === id);

    if (group) {
        group.name = value;
    }
};

App.actions.addGroup = function () {
    App.state.editingSurvey.groups.push({
        id: App.utils.uuid('group'),
        name: '新しいグループ',
        questions: []
    });

    App.actions.renumberQuestions();
    App.render.editor();
};

App.actions.deleteGroup = function (id) {
    if (App.state.editingSurvey.groups.length <= 1) {
        App.utils.notify('グループは最低1つ必要です。', 'error');
        return;
    }

    if (!confirm('このグループを削除しますか？')) {
        return;
    }

    App.state.editingSurvey.groups =
        App.state.editingSurvey.groups.filter(g => g.id !== id);

    App.actions.renumberQuestions();
    App.render.editor();
};

App.actions.addQuestion = function (groupId) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    group.questions.push({
        id: App.utils.uuid('question'),
        text: '',
        type: 'text',
        required: false,
        options: [],
        other_enabled: false
    });

    App.actions.renumberQuestions();
    App.render.editor();
};

App.actions.deleteQuestion = function (groupId, questionId) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    group.questions = group.questions.filter(
        q => q.id !== questionId
    );

    App.actions.renumberQuestions();
    App.render.editor();
};

App.actions.updateQuestion = function (
    groupId,
    questionId,
    field,
    value
) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    const question = group.questions.find(
        q => q.id === questionId
    );

    if (!question) return;

    question[field] = value;

    if (field === 'type' && value === 'text') {
        question.options = [];
    }

    App.actions.renumberQuestions();
    App.render.editor();
};

App.actions.addOption = function (groupId, questionId) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    const question = group.questions.find(
        q => q.id === questionId
    );

    if (!question) return;

    question.options.push('');

    App.render.editor();
};

App.actions.updateOption = function (
    groupId,
    questionId,
    index,
    value
) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    const question = group.questions.find(
        q => q.id === questionId
    );

    if (!question) return;

    question.options[index] = value;
};

App.actions.deleteOption = function (
    groupId,
    questionId,
    index
) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    const question = group.questions.find(
        q => q.id === questionId
    );

    if (!question) return;

    question.options.splice(index, 1);

    App.render.editor();
};

App.actions.moveQuestion = function () {
    const containers =
        document.querySelectorAll('.sortable-questions');

    const groups = [];

    containers.forEach((container) => {
        const groupId = container.dataset.groupId;

        const group = App.state.editingSurvey.groups.find(
            g => g.id === groupId
        );

        if (!group) return;

        const ids = [...container.querySelectorAll('[data-question-id]')]
            .map(el => el.dataset.questionId);

        const questionMap = new Map();

        App.state.editingSurvey.groups.forEach((g) => {
            g.questions.forEach((q) => {
                questionMap.set(q.id, q);
            });
        });

        group.questions = ids
            .map(id => questionMap.get(id))
            .filter(Boolean);

        groups.push(group);
    });

    App.state.editingSurvey.groups = groups;

    App.actions.renumberQuestions();
    App.render.editor();
};

App.actions.renumberQuestions = function () {
    let globalNo = 1;

    App.state.editingSurvey.groups.forEach((group, groupIndex) => {
        group.questions.forEach((question, questionIndex) => {
            question.number =
                App.state.editingSurvey.numbering_mode === 'group'
                    ? `Q${groupIndex + 1}-${questionIndex + 1}`
                    : `Q${globalNo}`;

            globalNo++;
        });
    });
};

App.actions.preview = function () {
    const survey = App.state.editingSurvey;

    if (!survey) return;

    const content = document.getElementById('preview_content');

    content.innerHTML = App.render.previewContent(survey);

    document.getElementById('preview_modal').classList.remove('hidden');
};

App.render.previewContent = function (survey) {
    const width = App.state.previewMode === 'mobile'
        ? 'max-w-sm'
        : 'max-w-3xl';

    return `
        <div class="${width} mx-auto bg-white border rounded-xl p-6">
            <h1 class="text-2xl font-bold mb-8">
                ${App.utils.escape(survey.title)}
            </h1>

            ${survey.groups.map(group => `
                <section class="mb-8">
                    <h2 class="font-bold text-lg border-b pb-2 mb-5">
                        ${App.utils.escape(group.name)}
                    </h2>

                    ${group.questions.map(question => `
                        <div class="mb-6">
                            <div class="font-medium mb-2">
                                ${App.utils.escape(question.number)}
                                ${App.utils.escape(question.text)}
                                ${question.required
                                    ? '<span class="text-red-500">*</span>'
                                    : ''}
                            </div>

                            ${
                                question.type === 'text'
                                ? `
                                    <textarea
                                        class="w-full border rounded-lg p-3"
                                        disabled></textarea>
                                `
                                : `
                                    <div class="space-y-2">
                                        ${question.options.map(option => `
                                            <label class="flex gap-2">
                                                <input
                                                    type="${question.type === 'multiple' ? 'checkbox' : 'radio'}"
                                                    disabled>
                                                <span>${App.utils.escape(option)}</span>
                                            </label>
                                        `).join('')}
                                    </div>
                                `
                            }
                        </div>
                    `).join('')}
                </section>
            `).join('')}

            <button
                onclick="alert('プレビューのため送信されません')"
                class="w-full bg-blue-600 text-white rounded-lg py-3">
                送信
            </button>
        </div>
    `;
};

App.actions.setPreviewMode = function (mode) {
    App.state.previewMode = mode;
    App.actions.preview();
};

App.actions.closeModal = function (id) {
    document.getElementById(id)?.classList.add('hidden');
};

App.actions.saveSurvey = async function () {
    try {
        const json = await App.api.request(
            'save_survey',
            {
                survey_json: App.state.editingSurvey
            }
        );

        const index = App.state.data.surveys.findIndex(
            s => s.id === json.survey.id
        );

        if (index >= 0) {
            App.state.data.surveys[index] = json.survey;
        } else {
            App.state.data.surveys.push(json.survey);
        }

        App.utils.notify('アンケートを保存しました。');
        App.actions.goList();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.cancelEdit = function () {
    App.state.editingSurvey = null;
    App.actions.goList();
};

App.actions.stopSurvey = async function (id) {
    if (!confirm('このアンケートを停止しますか？')) {
        return;
    }

    try {
        await App.api.request('stop_survey', {
            survey_id: id
        });

        const survey = App.utils.getSurvey(id);

        if (survey) {
            survey.status = 'ended';
        }

        App.utils.notify('アンケートを停止しました。');
        App.render.list();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.resumeSurvey = async function (id) {
    if (!confirm('このアンケートを再開しますか？')) {
        return;
    }

    try {
        await App.api.request('resume_survey', {
            survey_id: id
        });

        const survey = App.utils.getSurvey(id);

        if (survey) {
            survey.status = 'active';
        }

        App.utils.notify('アンケートを再開しました。');
        App.render.list();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.duplicateSurvey = async function (id) {
    try {
        const json = await App.api.request('duplicate_survey', {
            survey_id: id
        });

        App.state.data.surveys.push(json.survey);

        App.utils.notify('アンケートを複製しました。');
        App.render.list();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.deleteSurvey = async function (id) {
    if (!confirm('この下書きを削除しますか？')) {
        return;
    }

    try {
        await App.api.request('delete_survey', {
            survey_id: id
        });

        const survey = App.utils.getSurvey(id);

        if (survey) {
            survey.deleted = true;
        }

        App.utils.notify('削除しました。');
        App.render.list();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.filterSurveys = function (value) {
    App.state.keyword = value;
    App.render.list();
};

App.actions.toggleStatusFilter = function (value) {
    App.state.statusFilter = value;
    App.render.list();
};

App.actions.sortSurveys = function (value) {
    App.state.sort = value;
    App.render.list();
};

App.actions.goStats = function (id) {
    App.state.selectedSurveyId = id;
    App.render.stats();
};

App.render.stats = function () {
    const survey = App.utils.getSurvey(App.state.selectedSurveyId);

    if (!survey) return;

    const responses = App.state.data.responses.filter(
        r => r.survey_id === survey.id
    );

    const customers = App.state.data.customers;

    const targetIds = new Set();

    App.state.data.mail_logs.forEach(log => {
        if (
            log.survey_id === survey.id &&
            log.customer_id &&
            log.success
        ) {
            targetIds.add(log.customer_id);
        }
    });

    const targetCount = targetIds.size;

    const unregistered = responses.filter(response => {
        const customer = customers.find(
            c => c.id === response.customer_id
        );

        return !customer ||
            customer.kintone_status === 'unregistered';
    }).length;

    const registeredResponseCount = responses.length - unregistered;

    const rate = targetCount
        ? ((registeredResponseCount / targetCount) * 100).toFixed(2)
        : '0.00';

    const questions = [];

    survey.groups.forEach(group => {
        group.questions.forEach(question => {
            questions.push(question);
        });
    });

    const questionStats = questions.map(question => {
        const counter = {};

        responses.forEach(response => {
            let value = response.answers?.[question.id];

            if (Array.isArray(value)) {
                value.forEach(v => {
                    counter[v] = (counter[v] || 0) + 1;
                });
            } else if (value !== undefined && value !== '') {
                counter[value] = (counter[value] || 0) + 1;
            }
        });

        const total = responses.length;

        return `
            <div class="bg-white border rounded-xl p-5">
                <div class="font-bold mb-4">
                    ${App.utils.escape(question.number)}
                    ${App.utils.escape(question.text)}
                </div>

                ${
                    question.type === 'text'
                    ? `
                        <div class="space-y-3">
                            ${responses.map(response => {
                                const value = response.answers?.[question.id];

                                if (!value) return '';

                                return `
                                    <div class="border-l-4 border-blue-500 pl-3">
                                        <div class="text-xs text-gray-500">
                                            ${App.utils.escape(response.company)}
                                            / ${App.utils.escape(response.name)}
                                            / ${App.utils.escape(response.answered_at)}
                                        </div>
                                        <div class="mt-1">
                                            ${App.utils.escape(value)}
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    `
                    : `
                        <div class="space-y-3">
                            ${Object.entries(counter).map(([key, count]) => {
                                const percent = total
                                    ? ((count / total) * 100).toFixed(1)
                                    : '0';

                                return `
                                    <div>
                                        <div class="flex justify-between text-sm">
                                            <span>${App.utils.escape(key)}</span>
                                            <span>${count}件 (${percent}%)</span>
                                        </div>
                                        <div class="h-3 bg-gray-100 rounded-full overflow-hidden mt-1">
                                            <div
                                                class="h-full bg-blue-600"
                                                style="width:${percent}%"></div>
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    `
                }
            </div>
        `;
    }).join('');

    document.getElementById('app').innerHTML = App.render.shell(
        'アンケート一覧 › 集計',
        `
        <div class="flex flex-wrap justify-between gap-3 mb-6">
            <div>
                <h1 class="text-2xl font-bold">回答集計</h1>
                <p class="text-gray-500">${App.utils.escape(survey.title)}</p>
            </div>

            <div class="flex gap-2">
                <a
                    href="?action=export_csv&survey_id=${encodeURIComponent(survey.id)}"
                    class="border rounded-lg px-4 py-2">
                    CSV出力
                </a>

                <button
                    onclick="window.print()"
                    class="border rounded-lg px-4 py-2">
                    PDF印刷
                </button>

                <button
                    onclick="App.actions.goList()"
                    class="border rounded-lg px-4 py-2">
                    戻る
                </button>
            </div>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            ${[
                ['送信対象者数', targetCount],
                ['回答数', responses.length],
                ['未登録顧客からの回答数', unregistered],
                ['未回答数', Math.max(0, targetCount - responses.length)],
                ['回答率', rate + '%']
            ].map(([label, value]) => `
                <div class="bg-white border rounded-xl p-5">
                    <div class="text-sm text-gray-500">${label}</div>
                    <div class="text-2xl font-bold mt-2">${value}</div>
                </div>
            `).join('')}
        </div>

        <div class="flex flex-wrap gap-2 mb-5">
            <button
                onclick="App.actions.showAllResponses()"
                class="bg-blue-600 text-white rounded-lg px-4 py-2">
                全回答を表示
            </button>
        </div>

        <div class="space-y-5">
            ${questionStats}
        </div>
        `
    );
};

App.actions.showAllResponses = function () {
    const survey = App.utils.getSurvey(App.state.selectedSurveyId);

    if (!survey) return;

    const responses = App.state.data.responses.filter(
        r => r.survey_id === survey.id
    );

    document.getElementById('response_detail').innerHTML =
        responses.length
        ? responses.map(response => {
            const answers = Object.entries(response.answers || {})
                .map(([key, value]) => {
                    if (Array.isArray(value)) {
                        value = value.join(', ');
                    }

                    return `
                        <div class="border-b py-3">
                            <div class="text-sm text-gray-500">
                                ${App.utils.escape(key)}
                            </div>
                            <div>${App.utils.escape(value)}</div>
                        </div>
                    `;
                }).join('');

            return `
                <article class="border rounded-xl p-5 mb-4">
                    <div class="font-bold">
                        ${App.utils.escape(response.company)}
                        / ${App.utils.escape(response.name)}
                    </div>

                    <div class="text-sm text-gray-500 mb-3">
                        ${App.utils.escape(response.answered_at)}
                    </div>

                    ${answers}
                </article>
            `;
        }).join('')
        : '<div class="text-gray-500">回答はありません。</div>';

    document.getElementById('response_modal').classList.remove('hidden');
};

App.actions.toggleResponseFilter = function () {
    const element = document.getElementById('response_filter');

    if (element) {
        element.classList.toggle('hidden');
    }
};

App.actions.goSend = function (id) {
    App.state.selectedSurveyId = id;
    App.render.send();
};

App.render.send = function () {
    const survey = App.utils.getSurvey(App.state.selectedSurveyId);

    if (!survey) return;

    const customers = App.state.data.customers;

    const rows = customers.map(customer => `
        <tr class="border-b">
            <td class="px-3 py-3">
                <input
                    type="checkbox"
                    class="customer-check"
                    value="${App.utils.escape(customer.id)}">
            </td>
            <td class="px-3 py-3">${App.utils.escape(customer.company)}</td>
            <td class="px-3 py-3">${App.utils.escape(customer.name)}</td>
            <td class="px-3 py-3">${App.utils.escape(customer.email)}</td>
            <td class="px-3 py-3">${App.utils.escape(customer.department)}</td>
            <td class="px-3 py-3">${App.utils.escape(customer.answer_status)}</td>
            <td class="px-3 py-3">${App.utils.escape(customer.send_count)}</td>
        </tr>
    `).join('');

    document.getElementById('app').innerHTML = App.render.shell(
        'アンケート一覧 › 顧客選択・送信・送信履歴',
        `
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold">顧客選択・送信</h1>
                <p class="text-gray-500">${App.utils.escape(survey.title)}</p>
            </div>

            <button
                onclick="App.actions.goList()"
                class="border rounded-lg px-4 py-2">
                戻る
            </button>
        </div>

        <div class="grid lg:grid-cols-2 gap-5 mb-6">
            <div class="bg-white border rounded-xl p-5">
                <h2 class="font-bold mb-4">メール設定</h2>

                <label class="block mb-4">
                    <span class="text-sm">テンプレート</span>
                    <select id="template_type"
                        class="mt-1 w-full border rounded-lg px-3 py-2">
                        <option value="initial">初回送信用</option>
                        <option value="reminder">リマインド用</option>
                    </select>
                </label>

                <label class="block mb-4">
                    <span class="text-sm">件名</span>
                    <input
                        id="mail_subject"
                        value="アンケートのお願い"
                        class="mt-1 w-full border rounded-lg px-3 py-2">
                </label>

                <label class="block">
                    <span class="text-sm">本文</span>
                    <textarea
                        id="mail_body"
                        class="mt-1 w-full border rounded-lg p-3 min-h-48">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>
                </label>
            </div>

            <div class="bg-white border rounded-xl p-5">
                <h2 class="font-bold mb-4">送信操作</h2>

                <div class="flex flex-wrap gap-2">
                    <button
                        onclick="App.actions.sendMail()"
                        class="bg-blue-600 text-white rounded-lg px-4 py-2">
                        選択した顧客へ送信
                    </button>

                    <button
                        onclick="App.actions.sendReminder()"
                        class="bg-indigo-600 text-white rounded-lg px-4 py-2">
                        未回答者へリマインド
                    </button>

                    <button
                        onclick="App.actions.showSentMail()"
                        class="border rounded-lg px-4 py-2">
                        送信履歴
                    </button>
                </div>

                <div class="mt-5 text-sm text-gray-500">
                    差し込み変数：{顧客名} / {アンケートURL}
                </div>
            </div>
        </div>

        <div class="bg-white border rounded-xl overflow-hidden">
            <div class="p-4 border-b flex items-center gap-3">
                <input
                    id="select_all"
                    type="checkbox"
                    onchange="App.actions.selectAllCustomers(this.checked)">
                <span class="font-medium">全選択</span>

                <input
                    id="customer_filter"
                    oninput="App.actions.filterCustomers(this.value)"
                    placeholder="会社名・氏名・メールで検索"
                    class="ml-auto border rounded-lg px-3 py-2">
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px]" id="customer_table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-3 text-left"></th>
                            <th class="px-3 py-3 text-left">会社名</th>
                            <th class="px-3 py-3 text-left">氏名</th>
                            <th class="px-3 py-3 text-left">メール</th>
                            <th class="px-3 py-3 text-left">部署</th>
                            <th class="px-3 py-3 text-left">回答状態</th>
                            <th class="px-3 py-3 text-left">送信回数</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
            </div>
        </div>
        `
    );
};

App.actions.selectAllCustomers = function (checked) {
    document.querySelectorAll('.customer-check').forEach(
        checkbox => checkbox.checked = checked
    );
};

App.actions.filterCustomers = function (keyword) {
    const lower = keyword.toLowerCase();

    document.querySelectorAll('#customer_table tbody tr').forEach(row => {
        row.classList.toggle(
            'hidden',
            !row.textContent.toLowerCase().includes(lower)
        );
    });
};

App.actions.sendMail = async function (allowResend = false) {
    const recipientIds = [...document.querySelectorAll('.customer-check:checked')]
        .map(el => el.value);

    if (!recipientIds.length) {
        App.utils.notify('送信対象を選択してください。', 'error');
        return;
    }

    const payload = {
        survey_id: App.state.selectedSurveyId,
        recipient_ids: recipientIds,
        mail_subject: document.getElementById('mail_subject').value,
        mail_body: document.getElementById('mail_body').value,
        template_type: document.getElementById('template_type').value
    };

    if (allowResend) {
        payload.allow_resend = '1';
    }

    try {
        const result = await App.api.request(
            'send_mail',
            payload
        );

        App.utils.notify(
            `送信完了：成功 ${result.success_count}件 / 失敗 ${result.failed_count}件`
        );

        await App.api.loadData();
        App.render.send();
    } catch (error) {
        if (error.requires_confirmation) {
            if (confirm(error.message)) {
                App.actions.sendMail(true);
            }

            return;
        }

        App.utils.notify(error.message, 'error');
    }
};

App.actions.sendReminder = async function () {
    const surveyId = App.state.selectedSurveyId;

    const recipientIds = [];

    App.state.data.customers.forEach(customer => {
        if (customer.answer_status === 'unanswered') {
            recipientIds.push(customer.id);
        }
    });

    if (!recipientIds.length) {
        App.utils.notify('未回答者はいません。', 'error');
        return;
    }

    document.getElementById('template_type').value = 'reminder';

    try {
        const result = await App.api.request(
            'send_mail',
            {
                survey_id: surveyId,
                recipient_ids: recipientIds,
                mail_subject: document.getElementById('mail_subject').value,
                mail_body: document.getElementById('mail_body').value,
                template_type: 'reminder'
            }
        );

        App.utils.notify(
            `リマインド完了：成功 ${result.success_count}件 / 失敗 ${result.failed_count}件`
        );

        await App.api.loadData();
        App.render.send();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.showSentMail = function () {
    const surveyId = App.state.selectedSurveyId;

    const logs = App.state.data.mail_logs.filter(
        log => log.survey_id === surveyId
    );

    const content = logs.length
        ? logs.map(log => `
            <div class="border rounded-xl p-4 mb-3">
                <div class="flex justify-between">
                    <div>
                        <span class="font-bold">${App.utils.escape(log.type || '')}</span>
                        <span class="text-sm text-gray-500 ml-2">
                            ${App.utils.escape(log.sent_at || '')}
                        </span>
                    </div>

                    <button
                        onclick="App.actions.showMailBody('${log.id}')"
                        class="text-blue-600">
                        送信文を確認
                    </button>
                </div>

                <div class="text-sm mt-2">
                    成功: ${log.success_count ?? (log.success ? 1 : 0)}
                    / 失敗: ${log.failed_count ?? (log.success ? 0 : 1)}
                </div>
            </div>
        `).join('')
        : '<div class="text-gray-500">送信履歴はありません。</div>';

    document.getElementById('app').innerHTML = App.render.shell(
        'アンケート一覧 › 送信履歴',
        `
        <div class="flex justify-between mb-5">
            <h1 class="text-2xl font-bold">送信履歴</h1>
            <button onclick="App.actions.goSend('${surveyId}')"
                class="border rounded-lg px-4 py-2">
                戻る
            </button>
        </div>

        <div class="bg-white border rounded-xl p-5">
            ${content}
        </div>
        `
    );
};

App.actions.showMailBody = function (id) {
    const log = App.state.data.mail_logs.find(
        item => item.id === id
    );

    if (!log) return;

    alert(
        `件名:\n${log.subject || ''}\n\n本文:\n${log.body || ''}`
    );
};

App.actions.goSettings = function () {
    history.pushState({}, '', location.pathname + '?view=settings');
    App.render.settings();
};

App.render.settings = function () {
    const settings = App.state.data.settings || {};

    document.getElementById('app').innerHTML = App.render.shell(
        'システム設定 › kintone・メール連携設定',
        `
        <div class="flex justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold">システム設定</h1>
                <p class="text-gray-500">
                    kintoneおよびSMTP接続情報を設定します。
                </p>
            </div>

            <button
                onclick="App.actions.goList()"
                class="border rounded-lg px-4 py-2">
                戻る
            </button>
        </div>

        <form id="settings_form" class="space-y-6">
            <input
                type="hidden"
                id="settings_json">

            <section class="bg-white border rounded-xl p-5">
                <h2 class="font-bold text-lg mb-5">kintone設定</h2>

                <div class="grid md:grid-cols-2 gap-4">
                    <label>
                        <span class="text-sm">サブドメイン</span>
                        <input
                            id="setting_subdomain"
                            value="${App.utils.escape(settings.subdomain || '')}"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm">アプリID</span>
                        <input
                            id="setting_app_id"
                            value="${App.utils.escape(settings.app_id || '')}"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm">ログイン名</span>
                        <input
                            id="setting_login_name"
                            value="${App.utils.escape(settings.login_name || '')}"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm">パスワード</span>
                        <input
                            id="setting_password"
                            type="password"
                            autocomplete="new-password"
                            class="mt-1 w-full border rounded-lg px-3 py-2"
                            placeholder="変更時のみ入力">
                    </label>

                    <label>
                        <span class="text-sm">Proxy</span>
                        <input
                            id="setting_proxy"
                            value="${App.utils.escape(settings.proxy || '')}"
                            placeholder="host:port"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label class="flex items-center gap-2 pt-7">
                        <input
                            id="setting_ssl_verify"
                            type="checkbox"
                            ${settings.ssl_verify !== false ? 'checked' : ''}>
                        SSL証明書を検証する
                    </label>
                </div>

                <div class="flex flex-wrap gap-2 mt-5">
                    <button
                        type="button"
                        onclick="App.actions.fetchKintoneFields()"
                        class="bg-blue-600 text-white rounded-lg px-4 py-2">
                        kintoneフィールド取得
                    </button>

                    <button
                        type="button"
                        onclick="App.actions.testKintone()"
                        class="border rounded-lg px-4 py-2">
                        kintone接続確認
                    </button>

                    <button
                        type="button"
                        onclick="App.actions.syncCustomers()"
                        class="border rounded-lg px-4 py-2">
                        顧客データを同期
                    </button>
                </div>

                <div id="field_message" class="mt-4"></div>

                <div
                    id="kintone_fields"
                    class="mt-5 grid md:grid-cols-2 gap-4">
                    ${App.render.fieldSelect(
                        'field_company',
                        '会社名',
                        settings.field_company,
                        App.state.settingsFields
                    )}

                    ${App.render.fieldSelect(
                        'field_name',
                        '氏名',
                        settings.field_name,
                        App.state.settingsFields
                    )}

                    ${App.render.fieldSelect(
                        'field_email',
                        'メールアドレス',
                        settings.field_email,
                        App.state.settingsFields
                    )}

                    ${App.render.fieldSelect(
                        'field_department',
                        '部署名',
                        settings.field_department,
                        App.state.settingsFields
                    )}

                    ${App.render.fieldSelect(
                        'field_phone',
                        '電話番号',
                        settings.field_phone,
                        App.state.settingsFields
                    )}

                    ${App.render.addressFields(
                        settings.field_address,
                        App.state.settingsFields
                    )}
                </div>
            </section>

            <section class="bg-white border rounded-xl p-5">
                <h2 class="font-bold text-lg mb-5">SMTP設定</h2>

                <div class="grid md:grid-cols-2 gap-4">
                    <label>
                        <span class="text-sm">SMTPサーバ</span>
                        <input
                            id="smtp_server"
                            value="${App.utils.escape(settings.smtp_server || '')}"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm">SMTPポート</span>
                        <input
                            id="smtp_port"
                            type="number"
                            value="${App.utils.escape(settings.smtp_port || 587)}"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm">暗号化方式</span>
                        <select
                            id="smtp_encryption"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                            <option value="none" ${settings.smtp_encryption === 'none' ? 'selected' : ''}>なし</option>
                            <option value="tls" ${(!settings.smtp_encryption || settings.smtp_encryption === 'tls') ? 'selected' : ''}>STARTTLS</option>
                            <option value="ssl" ${settings.smtp_encryption === 'ssl' ? 'selected' : ''}>SSL/TLS</option>
                        </select>
                    </label>

                    <label>
                        <span class="text-sm">SMTPユーザー名</span>
                        <input
                            id="smtp_username"
                            value="${App.utils.escape(settings.smtp_username || '')}"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm">SMTPパスワード</span>
                        <input
                            id="smtp_password"
                            type="password"
                            autocomplete="new-password"
                            placeholder="変更時のみ入力"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm">送信元メールアドレス</span>
                        <input
                            id="smtp_from"
                            type="email"
                            value="${App.utils.escape(settings.smtp_from || '')}"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm">送信元表示名</span>
                        <input
                            id="smtp_from_name"
                            value="${App.utils.escape(settings.smtp_from_name || '')}"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm">接続タイムアウト</span>
                        <input
                            id="smtp_timeout"
                            type="number"
                            value="${App.utils.escape(settings.smtp_timeout || 20)}"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>
                </div>

                <div class="mt-5 flex flex-wrap gap-2">
                    <input
                        id="smtp_test_to"
                        type="email"
                        placeholder="テスト送信先"
                        class="border rounded-lg px-3 py-2">

                    <button
                        type="button"
                        onclick="App.actions.testSMTP()"
                        class="border rounded-lg px-4 py-2">
                        SMTP接続確認
                    </button>

                    <button
                        type="button"
                        onclick="App.actions.testSMTPSend()"
                        class="border rounded-lg px-4 py-2">
                        テストメール送信
                    </button>
                </div>
            </section>

            <div class="flex justify-end">
                <button
                    type="button"
                    onclick="App.actions.saveSettings()"
                    class="bg-blue-600 text-white rounded-lg px-5 py-3">
                    設定を保存
                </button>
            </div>
        </form>
        `
    );
};

App.render.fieldSelect = function (
    key,
    label,
    value,
    fields
) {
    return `
        <label>
            <span class="text-sm">${App.utils.escape(label)}</span>
            <select
                data-setting-field="${App.utils.escape(key)}"
                class="mt-1 w-full border rounded-lg px-3 py-2">
                <option value="">未設定</option>
                ${fields.map(field => `
                    <option
                        value="${App.utils.escape(field.code)}"
                        ${value === field.code ? 'selected' : ''}>
                        ${App.utils.escape(field.label)}
                        (${App.utils.escape(field.code)})
                    </option>
                `).join('')}
            </select>
        </label>
    `;
};

App.render.addressFields = function (values, fields) {
    const selected = Array.isArray(values)
        ? values
        : values
            ? [values]
            : [];

    return `
        <label>
            <span class="text-sm">住所</span>
            <select
                multiple
                data-setting-field="field_address"
                class="mt-1 w-full border rounded-lg px-3 py-2 min-h-32">
                ${fields.map(field => `
                    <option
                        value="${App.utils.escape(field.code)}"
                        ${selected.includes(field.code) ? 'selected' : ''}>
                        ${App.utils.escape(field.label)}
                        (${App.utils.escape(field.code)})
                    </option>
                `).join('')}
            </select>
            <span class="text-xs text-gray-500">
                Ctrl / Command を押しながら複数選択できます。
            </span>
        </label>
    `;
};

App.actions.fetchKintoneFields = async function () {
    try {
        const result = await App.api.request(
            'kintone_fields',
            {
                app_id: document.getElementById('setting_app_id').value
            }
        );

        App.state.settingsFields = result.fields || [];

        document.getElementById('field_message').innerHTML = `
            <div class="bg-green-50 text-green-700 p-3 rounded-lg">
                ${App.utils.escape(App.state.settingsFields.length)}件のフィールドを取得しました。
            </div>
        `;

        App.render.settings();
    } catch (error) {
        document.getElementById('field_message').innerHTML = `
            <div class="bg-red-50 text-red-700 p-3 rounded-lg">
                ${App.utils.escape(error.message)}
                ${error.status ? `<br>HTTP: ${error.status}` : ''}
            </div>
        `;
    }
};

App.actions.testKintone = async function () {
    try {
        const result = await App.api.request('kintone_test', {});

        App.utils.notify(
            `kintone接続成功 HTTP ${result.status}`
        );
    } catch (error) {
        App.utils.notify(
            `kintone接続失敗 HTTP ${error.status || ''} ${error.message}`,
            'error'
        );
    }
};

App.actions.syncCustomers = async function () {
    try {
        const result = await App.api.request(
            'sync_customers',
            {}
        );

        await App.api.loadData();

        App.utils.notify(
            `${result.count}件の顧客を同期しました。`
        );
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.collectSettings = function () {
    const settings = {
        subdomain:
            document.getElementById('setting_subdomain').value,

        app_id:
            document.getElementById('setting_app_id').value,

        login_name:
            document.getElementById('setting_login_name').value,

        password:
            document.getElementById('setting_password').value,

        proxy:
            document.getElementById('setting_proxy').value,

        ssl_verify:
            document.getElementById('setting_ssl_verify').checked,

        field_company:
            document.querySelector('[data-setting-field="field_company"]')?.value || '',

        field_name:
            document.querySelector('[data-setting-field="field_name"]')?.value || '',

        field_email:
            document.querySelector('[data-setting-field="field_email"]')?.value || '',

        field_department:
            document.querySelector('[data-setting-field="field_department"]')?.value || '',

        field_phone:
            document.querySelector('[data-setting-field="field_phone"]')?.value || '',

        field_address:
            [...(
                document.querySelector(
                    '[data-setting-field="field_address"]'
                )?.selectedOptions || []
            )].map(option => option.value),

        smtp_server:
            document.getElementById('smtp_server').value,

        smtp_port:
            document.getElementById('smtp_port').value,

        smtp_encryption:
            document.getElementById('smtp_encryption').value,

        smtp_username:
            document.getElementById('smtp_username').value,

        smtp_password:
            document.getElementById('smtp_password').value,

        smtp_from:
            document.getElementById('smtp_from').value,

        smtp_from_name:
            document.getElementById('smtp_from_name').value,

        smtp_timeout:
            document.getElementById('smtp_timeout').value
    };

    return settings;
};

App.actions.saveSettings = async function () {
    try {
        const settings = App.actions.collectSettings();

        const result = await App.api.request(
            'save_settings',
            {
                settings_json: settings
            }
        );

        App.state.data.settings = {
            ...App.state.data.settings,
            ...result.settings
        };

        App.utils.notify('設定を保存しました。');

        App.render.settings();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.testSMTP = async function () {
    try {
        const settings = App.actions.collectSettings();

        await App.api.request(
            'save_settings',
            {
                settings_json: settings
            }
        );

        const result = await App.api.request(
            'smtp_test',
            {
                smtp_test_to:
                    document.getElementById('smtp_test_to').value
            }
        );

        App.utils.notify(
            `SMTP接続成功。応答コード: ${result.smtp_code}`
        );
    } catch (error) {
        App.utils.notify(
            error.message || 'SMTP接続に失敗しました。',
            'error'
        );
    }
};

App.actions.testSMTPSend = async function () {
    try {
        const settings = App.actions.collectSettings();

        await App.api.request(
            'save_settings',
            {
                settings_json: settings
            }
        );

        const result = await App.api.request(
            'smtp_test',
            {
                smtp_test_to:
                    document.getElementById('smtp_test_to').value
            }
        );

        App.utils.notify(
            result.ok
                ? 'テストメールを送信しました。'
                : 'テストメール送信に失敗しました。'
        );
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.logout = function () {
    /*
     * Authentication provider is intentionally external to the
     * survey data layer. This keeps the single-file application
     * compatible with an existing Apache authentication/session setup.
     */
    App.utils.notify('ログアウト処理はApache/PHP認証環境に合わせて設定してください。');
};

App.actions.showAllResponses = App.actions.showAllResponses;

if (document.readyState === 'loading') {
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
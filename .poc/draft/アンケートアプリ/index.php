<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 *
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし
 *
 * 重要な恒久対策:
 *
 * 1. POST処理と画面描画を分離
 * 2. 外部サービス通信と画面遷移を分離
 * 3. 外部サービス通信関数からLocationを出さない
 * 4. 外部サービスの302/303を成功扱いしない
 * 5. 外部通信結果を構造化して呼び出し元へ返す
 * 6. 業務処理結果を確定してから画面を決定する
 * 7. 回答送信成功後は必ず回答完了画面
 * 8. 回答者を管理者一覧へ戻さない
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_data';
const DATA_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'data.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const KINTONE_CONNECT_TIMEOUT = 10;
const KINTONE_READ_TIMEOUT = 30;


/* =========================================================
 * 共通HTTP結果
 *
 * 外部サービス通信結果と、
 * アプリケーション自身の画面遷移を絶対に混同しない。
 * ========================================================= */

final class ExternalResponse
{
    public function __construct(
        public readonly bool $transportOk,
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly ?string $error,
        public readonly bool $redirect
    ) {}

    public function isSuccess(): bool
    {
        return $this->transportOk
            && $this->status >= 200
            && $this->status < 300
            && !$this->redirect;
    }

    public function isUnknown(): bool
    {
        return !$this->transportOk
            || $this->status === 0;
    }
}


/* =========================================================
 * アプリケーション処理結果
 *
 * POST処理関数はLocationヘッダーを出さず、
 * この結果をmain側へ返す。
 * ========================================================= */

final class ActionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $screen,
        public readonly string $id = '',
        public readonly ?string $message = null,
        public readonly string $messageType = 'success'
    ) {}
}


/* =========================================================
 * 初期化
 * ========================================================= */

function start_app(): void
{
    if (
        !is_dir(DATA_DIR)
        && !mkdir(DATA_DIR, 0775, true)
        && !is_dir(DATA_DIR)
    ) {
        throw new RuntimeException(
            'データ保存フォルダを作成できません。'
        );
    }

    if (!is_file(DATA_FILE)) {
        save_json(DATA_FILE, default_data());
    }

    if (!is_file(SETTINGS_FILE)) {
        save_json(SETTINGS_FILE, default_settings());
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $https =
            (!empty($_SERVER['HTTPS'])
                && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

        session_name('survey_app_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => cookie_path(),
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException(
                'セッションを開始できません。'
            );
        }
    }
}


/* =========================================================
 * リダイレクト
 *
 * 外部通信関数からは絶対に呼び出さない。
 *
 * PRGを使用する場合も、
 * 業務処理成功後にmain側だけが呼び出す。
 * ========================================================= */

function redirect_screen(
    string $screen,
    array $params = []
): never {
    $params = array_merge(
        ['screen' => $screen],
        $params
    );

    $url = app_url($params);

    /*
     * 業務処理結果が確定した後だけ使用可能。
     *
     * 重要:
     * 外部サービス通信処理からは呼び出さない。
     */
    header(
        'Location: ' . $url,
        true,
        303
    );

    exit;
}


/* =========================================================
 * 外部通信
 *
 * PHP cURLは使用しない。
 * stream wrapperを使用する。
 *
 * follow_location = 0
 * max_redirects   = 0
 *
 * 302/303を勝手に追跡しない。
 * ========================================================= */

function external_http_request(
    string $url,
    string $method,
    array $headers = [],
    ?string $body = null,
    bool $verifySsl = false,
    ?string $proxy = null
): ExternalResponse {

    $ssl = [
        'verify_peer' => $verifySsl,
        'verify_peer_name' => $verifySsl,
        'allow_self_signed' => !$verifySsl,
        'SNI_enabled' => true,
    ];

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,

            /*
             * 外部302/303を自動追跡しない。
             */
            'follow_location' => 0,
            'max_redirects' => 0,

            'timeout' => KINTONE_READ_TIMEOUT,
        ],
        'ssl' => $ssl,
    ];

    if ($proxy !== null && $proxy !== '') {
        if (
            !preg_match(
                '/^[^:\s]+:\d{1,5}$/',
                $proxy
            )
        ) {
            return new ExternalResponse(
                false,
                0,
                '',
                [],
                'Proxyはhost:port形式で指定してください。',
                false
            );
        }

        [$host, $port] = explode(':', $proxy, 2);

        $options['http']['proxy'] =
            'tcp://' . $host . ':' . (int)$port;

        $options['http']['request_fulluri'] = true;
    }

    $context =
        stream_context_create($options);

    $response =
        @file_get_contents(
            $url,
            false,
            $context
        );

    $responseHeaders =
        $http_response_header ?? [];

    $status = 0;

    foreach ($responseHeaders as $header) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/',
                $header,
                $matches
            )
        ) {
            $status = (int)$matches[1];
            break;
        }
    }

    $headersMap = [];

    foreach ($responseHeaders as $header) {
        $pos = strpos($header, ':');

        if ($pos === false) {
            continue;
        }

        $name =
            strtolower(
                trim(substr($header, 0, $pos))
            );

        $value =
            trim(
                substr($header, $pos + 1)
            );

        $headersMap[$name] = $value;
    }

    $isRedirect =
        $status === 301
        || $status === 302
        || $status === 303
        || $status === 307
        || $status === 308;

    if ($response === false) {
        return new ExternalResponse(
            false,
            $status,
            '',
            $headersMap,
            '外部サービスからレスポンスを取得できませんでした。',
            $isRedirect
        );
    }

    return new ExternalResponse(
        true,
        $status,
        (string)$response,
        $headersMap,
        null,
        $isRedirect
    );
}


/* =========================================================
 * kintone通信
 *
 * この関数は画面遷移しない。
 * ========================================================= */

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {

    $errors =
        validate_kintone_config(
            $config,
            true
        );

    if ($errors) {
        return [
            'ok' => false,
            'kind' => 'config_error',
            'message' =>
                implode("\n", $errors),
        ];
    }

    $subdomain =
        normalize_kintone_subdomain(
            (string)$config['subdomain']
        );

    $url =
        'https://'
        . $subdomain
        . '.cybozu.com'
        . $path;

    $authorization =
        base64_encode(
            (string)$config['username']
            . ':'
            . (string)$config['password']
        );

    $headers = [
        'X-Cybozu-Authorization: '
            . $authorization,
        'Accept: application/json',
    ];

    $content = null;

    if ($body !== null) {
        $content =
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );

        if ($content === false) {
            return [
                'ok' => false,
                'kind' => 'request_error',
                'message' =>
                    'kintoneリクエストを生成できません。',
            ];
        }

        $headers[] =
            'Content-Type: application/json';

        $headers[] =
            'Content-Length: '
            . strlen($content);
    }

    $response =
        external_http_request(
            $url,
            $method,
            $headers,
            $content,
            !empty($config['verify_ssl']),
            trim((string)($config['proxy'] ?? ''))
        );

    /*
     * ここではLocationヘッダーを出さない。
     *
     * 302/303は「画面遷移」ではなく、
     * kintone通信上の異常応答として呼び出し元へ返す。
     */
    if ($response->redirect) {
        return [
            'ok' => false,
            'kind' => 'redirect',
            'status' => $response->status,
            'message' =>
                'kintone APIからHTTP '
                . $response->status
                . 'のリダイレクト応答が返されました。',
            'location' =>
                $response->headers['location'] ?? '',
            'raw' => $response->body,
        ];
    }

    if ($response->isUnknown()) {
        return [
            'ok' => false,
            'kind' => 'unknown',
            'status' => $response->status,
            'message' =>
                'kintone APIの通信結果を取得できませんでした。',
        ];
    }

    $json =
        json_decode(
            $response->body,
            true
        );

    /*
     * HTTPステータスだけでは判定しない。
     */
    if (
        $response->status < 200
        || $response->status >= 300
    ) {
        $code =
            is_array($json)
                ? (string)($json['code'] ?? '')
                : '';

        $message =
            is_array($json)
                ? (string)($json['message'] ?? '')
                : '';

        return [
            'ok' => false,
            'kind' => 'api_error',
            'status' => $response->status,
            'code' => $code,
            'message' =>
                'kintone APIエラー'
                . ($code !== ''
                    ? ' [' . $code . ']'
                    : '')
                . ($message !== ''
                    ? ' ' . $message
                    : '')
                . ' HTTP '
                . $response->status,
            'body' =>
                is_array($json)
                    ? $json
                    : [],
            'raw' =>
                $response->body,
        ];
    }

    if (!is_array($json)) {
        return [
            'ok' => false,
            'kind' => 'invalid_response',
            'status' => $response->status,
            'message' =>
                'kintone APIのレスポンス形式が不正です。',
            'raw' => $response->body,
        ];
    }

    return [
        'ok' => true,
        'kind' => 'success',
        'status' => $response->status,
        'body' => $json,
        'raw' => $response->body,
    ];
}


/* =========================================================
 * 回答送信
 *
 * ここでは絶対に管理者一覧へ遷移しない。
 * ========================================================= */

function submit_answer(
    array &$data,
    string $surveyId
): ActionResult {

    $survey =
        survey_by_id(
            $data['surveys'],
            $surveyId
        );

    if ($survey === null) {
        return new ActionResult(
            false,
            'answer',
            $surveyId,
            'アンケートが見つかりません。',
            'error'
        );
    }

    $draft =
        $_SESSION['answer_draft'] ?? null;

    if (!is_array($draft)) {
        return new ActionResult(
            false,
            'answer',
            $surveyId,
            '回答データが見つかりません。',
            'error'
        );
    }

    $answer = [
        'id' => uuid('answer'),
        'survey_id' => $surveyId,
        'answers' => $draft,
        'createdAt' =>
            date('Y-m-d H:i:s'),
    ];

    /*
     * 保存が成功するまで、
     * 「回答完了」とは判定しない。
     */
    try {
        $data['answers'][] = $answer;
        save_data($data);
    } catch (Throwable $e) {
        return new ActionResult(
            false,
            'confirm',
            $surveyId,
            '回答を保存できませんでした。',
            'error'
        );
    }

    unset(
        $_SESSION['answer_draft']
    );

    /*
     * 回答者フローの終点。
     *
     * screen=list
     * screen=edit
     * screen=analytics
     * screen=send
     * screen=kintone
     * screen=mail
     *
     * は絶対に返さない。
     */
    return new ActionResult(
        true,
        'complete',
        $surveyId,
        '回答を受け付けました。',
        'success'
    );
}


/* =========================================================
 * POSTメイン処理
 *
 * 重要:
 *
 * - Locationを出さない
 * - 外部通信結果を確認する
 * - 保存結果を確認する
 * - 最後に画面を決定する
 * ========================================================= */

function handle_post(
    array &$data,
    array &$settings
): ?ActionResult {

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        !== 'POST'
    ) {
        return null;
    }

    $action =
        post_string('action');

    switch ($action) {

        /*
         * -----------------------------------------
         * 回答送信
         * -----------------------------------------
         */
        case 'submit_answer':

            return submit_answer(
                $data,
                post_string('survey_id')
            );


        /*
         * -----------------------------------------
         * kintone接続テスト
         * -----------------------------------------
         */

        case 'test_kintone':

            $config =
                posted_kintone_config(
                    $settings['kintone'] ?? []
                );

            $result =
                kintone_request(
                    $config,
                    'GET',
                    '/k/v1/app.json?id='
                    . rawurlencode(
                        (string)$config['app_id']
                    )
                );

            if (!$result['ok']) {

                return new ActionResult(
                    false,
                    'kintone',
                    '',
                    'kintone接続テスト失敗：'
                    . (string)$result['message'],
                    'error'
                );
            }

            return new ActionResult(
                true,
                'kintone',
                '',
                'kintoneへの接続に成功しました。',
                'success'
            );


        /*
         * -----------------------------------------
         * その他のPOST処理
         *
         * 各処理も必ず
         *
         * 処理
         * ↓
         * 保存
         * ↓
         * 結果確定
         * ↓
         * ActionResult
         *
         * の順序にする。
         * -----------------------------------------
         */

        default:

            return new ActionResult(
                false,
                'list',
                '',
                '不明な操作です。',
                'error'
            );
    }
}


/* =========================================================
 * メイン
 *
 * POST → 業務処理 → 結果確定 → render
 *
 * 外部サービス → redirect
 *
 * という構造には絶対にしない。
 * ========================================================= */

try {

    start_app();

    $data =
        load_data();

    $settings =
        load_settings();

    /*
     * 終了状態の自動更新。
     *
     * publishedのみendedへ変更する。
     * draft / stoppedは変更しない。
     */
    if (
        refresh_statuses($data)
    ) {
        save_data($data);
    }

    $result =
        handle_post(
            $data,
            $settings
        );

    /*
     * POST後は保存済みの最新データを再読込。
     */
    $data =
        load_data();

    $settings =
        load_settings();

    /*
     * POST結果がある場合、
     * その結果を唯一の画面決定情報とする。
     */
    if ($result !== null) {

        if ($result->message !== null) {
            flash(
                $result->messageType,
                $result->message
            );
        }

        /*
         * 回答完了は必ず回答者画面。
         */
        if (
            $result->screen === 'complete'
        ) {
            $survey =
                survey_by_id(
                    $data['surveys'],
                    $result->id
                );

            if ($survey === null) {
                throw new RuntimeException(
                    '回答完了対象アンケートが見つかりません。'
                );
            }

            render_complete(
                $survey
            );

            exit;
        }

        /*
         * その他のPOST結果も、
         * 成否確認後に画面を描画する。
         *
         * 必要な場合のみ、
         * ここでPRGを使用する。
         *
         * 重要:
         * 外部サービス関数からは
         * redirect_screen()を呼ばない。
         */

        render_result_screen(
            $result,
            $data,
            $settings
        );

        exit;
    }


    /*
     * GET処理
     */
    $screen =
        get_string('screen');

    if ($screen === '') {
        $screen = 'list';
    }

    $id =
        get_string('id');


    /*
     * -----------------------------------------
     * 回答者画面
     * -----------------------------------------
     */

    if (
        in_array(
            $screen,
            [
                'answer',
                'confirm',
                'complete',
            ],
            true
        )
    ) {

        $survey =
            survey_by_id(
                $data['surveys'],
                $id
            );

        if ($survey === null) {
            render_answer_error(
                'アンケートが見つかりません。'
            );
            exit;
        }

        switch ($screen) {

            case 'answer':
                render_answer(
                    $survey
                );
                break;

            case 'confirm':
                render_confirm(
                    $survey
                );
                break;

            case 'complete':
                /*
                 * 回答完了画面から
                 * 管理者一覧へ自動遷移しない。
                 */
                render_complete(
                    $survey
                );
                break;
        }

        exit;
    }


    /*
     * -----------------------------------------
     * 管理者画面
     * -----------------------------------------
     */

    switch ($screen) {

        case 'edit':
            render_edit_by_id(
                $data,
                $id
            );
            break;

        case 'preview':
            render_preview_by_id(
                $data,
                $id
            );
            break;

        case 'send':
            /*
             * id必須。
             * 対象アンケートを固定する。
             */
            if ($id === '') {
                render_list(
                    $data
                );
                break;
            }

            render_send_by_id(
                $data,
                $settings,
                $id
            );
            break;

        case 'analytics':
            /*
             * id必須。
             * 対象アンケートを固定する。
             */
            if ($id === '') {
                render_list(
                    $data
                );
                break;
            }

            render_analytics_by_id(
                $data,
                $id
            );
            break;

        case 'kintone':
            render_kintone(
                $settings['kintone']
            );
            break;

        case 'mail':
            render_mail(
                $settings['mail']
            );
            break;

        case 'list':
        default:
            render_list(
                $data
            );
            break;
    }

} catch (Throwable $e) {

    /*
     * 白画面にしない。
     *
     * スタックトレース、
     * パスワード、
     * Authorization等は
     * ユーザーへ出さない。
     */
    http_response_code(500);

    render_system_error();
}
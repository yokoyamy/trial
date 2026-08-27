<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

/*
 * =========================================================
 * アンケートアプリ POC
 * =========================================================
 *
 * prompt.txt 準拠
 *
 * 実装しないもの:
 * - CSRF対策
 * - 管理者ログイン
 * - 管理者ログアウト
 * - DB
 * - PHP cURL
 * - PHP mail()
 * - kintone APIトークン認証
 *
 * 実装するもの:
 * - アンケート一覧
 * - 新規作成 / 編集
 * - プレビュー
 * - 質問 / グループ管理
 * - 条件分岐
 * - 顧客選択 / メール送信
 * - 送信履歴
 * - 集計
 * - kintone設定 / 接続テスト / 項目再取得 / 同期
 * - SMTP設定 / 接続テスト / テストメール
 * - 回答 / 確認 / 完了
 *
 * データ:
 *   サーバー側JSON
 *
 * 外部通信:
 *   PHP stream socket / stream_context
 * =========================================================
 */

const DATA_DIR       = __DIR__ . '/data';
const SETTINGS_FILE  = DATA_DIR . '/settings.json';
const SURVEYS_FILE   = DATA_DIR . '/surveys.json';
const CUSTOMERS_FILE = DATA_DIR . '/customers.json';
const ANSWERS_FILE   = DATA_DIR . '/answers.json';
const SEND_LOG_FILE  = DATA_DIR . '/send_logs.json';

const CONNECT_TIMEOUT = 10;
const READ_TIMEOUT    = 30;


/*
 * =========================================================
 * Session
 * =========================================================
 *
 * CSRFトークンは作らない。
 *
 * 通常のGETごとにsession_regenerate_id()を実行しない。
 *
 * iframe + cross-site POST環境で利用されるため、
 * SameSite=None + Secureを使用する。
 *
 * Cookie Pathに日本語を含むSCRIPT_NAMEを使用しない。
 * "/" に固定してセッションCookieのPath破綻を防ぐ。
 */

session_name('questionnaire_poc');

$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'None',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!session_start()) {
        http_response_code(500);
        exit('セッションを開始できません。');
    }
}


/*
 * =========================================================
 * Data initialization
 * =========================================================
 */

if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('データディレクトリを作成できません。');
    }
}

init_json_file(SETTINGS_FILE, [
    'kintone' => [
        'subdomain' => '',
        'app_id' => '',
        'username' => '',
        'password' => '',
        'proxy' => '',
        'verify_ssl' => false,
        'field_mapping' => [
            'organization' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => [],
        ],
        'connection_status' => '未設定',
        'last_test_at' => null,
    ],
    'mail' => [
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => '',
        'reply_to' => '',
        'connection_status' => '未設定',
        'last_test_at' => null,
    ],
]);

init_json_file(SURVEYS_FILE, []);
init_json_file(CUSTOMERS_FILE, []);
init_json_file(ANSWERS_FILE, []);
init_json_file(SEND_LOG_FILE, []);


/*
 * =========================================================
 * Routing
 * =========================================================
 */

$screen = (string)($_GET['screen'] ?? 'list');

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
 * =========================================================
 * POST
 * =========================================================
 *
 * 重要:
 *
 * POSTされたら「action」で処理を明確に分岐する。
 *
 * actionが不明な場合に一覧へ黙ってリダイレクトしない。
 * エラーを表示して現在画面へ戻す。
 *
 * CSRFチェックは行わない。
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string)($_POST['action'] ?? '');

    try {

        switch ($action) {

            case 'save_kintone':
                handle_save_kintone();
                break;

            case 'test_kintone':
                handle_test_kintone();
                break;

            case 'fetch_kintone_fields':
                handle_fetch_kintone_fields();
                break;

            case 'sync_kintone':
                handle_sync_kintone();
                break;

            case 'save_mail':
                handle_save_mail();
                break;

            case 'test_mail':
                handle_test_mail();
                break;

            case 'send_test_mail':
                handle_send_test_mail();
                break;

            case 'save_survey':
                handle_save_survey();
                break;

            case 'delete_survey':
                handle_delete_survey();
                break;

            case 'duplicate_survey':
                handle_duplicate_survey();
                break;

            case 'change_status':
                handle_change_status();
                break;

            case 'save_questions':
                handle_save_questions();
                break;

            case 'answer_next':
                handle_answer_next();
                break;

            case 'answer_back':
                handle_answer_back();
                break;

            case 'answer_submit':
                handle_answer_submit();
                break;

            case 'send_mail':
                handle_send_mail();
                break;

            case 'resend_mail':
                handle_resend_mail();
                break;

            case 'remind_mail':
                handle_remind_mail();
                break;

            case 'export_csv':
                handle_export_csv();
                break;

            default:
                flash(
                    'error',
                    '操作を特定できませんでした。'
                );

                /*
                 * action不明でも「一覧へ戻す」だけにしない。
                 * POST処理が失敗したことを画面に残す。
                 */
                redirect(
                    screen_url($screen)
                );
        }

    } catch (Throwable $e) {

        flash(
            'error',
            user_error_message($e)
        );

        redirect(
            screen_url($screen)
        );
    }
}


/*
 * =========================================================
 * Auto status update
 * =========================================================
 */

update_expired_surveys();


/*
 * =========================================================
 * Target survey
 * =========================================================
 */

$survey = null;

if (
    in_array(
        $screen,
        [
            'edit',
            'preview',
            'send',
            'analytics',
            'answer',
            'confirm',
            'complete',
        ],
        true
    )
) {

    $id = trim(
        (string)($_GET['id'] ?? '')
    );

    if ($id !== '') {
        $survey = find_survey($id);
    }

    if (
        in_array(
            $screen,
            ['send', 'analytics'],
            true
        )
        && $survey === null
    ) {

        flash(
            'error',
            '対象アンケートが指定されていません。'
        );

        redirect(
            'index.php?screen=list'
        );
    }
}


/*
 * =========================================================
 * Render
 * =========================================================
 */

render_header($screen);

switch ($screen) {

    case 'list':
        render_list();
        break;

    case 'edit':
        render_edit($survey);
        break;

    case 'preview':
        render_preview($survey);
        break;

    case 'send':
        render_send($survey);
        break;

    case 'analytics':
        render_analytics($survey);
        break;

    case 'kintone':
        render_kintone();
        break;

    case 'mail':
        render_mail();
        break;

    case 'answer':
        render_answer($survey);
        break;

    case 'confirm':
        render_confirm($survey);
        break;

    case 'complete':
        render_complete($survey);
        break;

    default:
        render_list();
        break;
}

render_footer();


/*
 * =========================================================
 * Survey list
 * =========================================================
 */

function render_list(): void
{
    $surveys = read_json(SURVEYS_FILE);

    $keyword = trim(
        (string)($_GET['q'] ?? '')
    );

    $status = trim(
        (string)($_GET['status'] ?? '')
    );

    $sort = trim(
        (string)($_GET['sort'] ?? 'updated_desc')
    );

    $filtered = [];

    foreach ($surveys as $survey) {

        $title = (string)(
            $survey['title'] ?? ''
        );

        if (
            $keyword !== ''
            && mb_stripos(
                $title,
                $keyword
            ) === false
        ) {
            continue;
        }

        if (
            $status !== ''
            && $status !== 'all'
            && ($survey['status'] ?? '') !== $status
        ) {
            continue;
        }

        $filtered[] = $survey;
    }

    usort(
        $filtered,
        function (array $a, array $b) use ($sort): int {

            if ($sort === 'updated_asc') {
                return strcmp(
                    (string)($a['updatedAt'] ?? ''),
                    (string)($b['updatedAt'] ?? '')
                );
            }

            if ($sort === 'answers_desc') {
                return answer_count_for_survey(
                    (string)($b['id'] ?? '')
                ) <=> answer_count_for_survey(
                    (string)($a['id'] ?? '')
                );
            }

            if ($sort === 'answers_asc') {
                return answer_count_for_survey(
                    (string)($a['id'] ?? '')
                ) <=> answer_count_for_survey(
                    (string)($b['id'] ?? '')
                );
            }

            if ($sort === 'start_desc') {
                return strcmp(
                    (string)($b['startAt'] ?? ''),
                    (string)($a['startAt'] ?? '')
                );
            }

            if ($sort === 'start_asc') {
                return strcmp(
                    (string)($a['startAt'] ?? ''),
                    (string)($b['startAt'] ?? '')
                );
            }

            return strcmp(
                (string)($b['updatedAt'] ?? ''),
                (string)($a['updatedAt'] ?? '')
            );
        }
    );

    echo '<h1>アンケート一覧</h1>';

    echo '<div class="actions">';
    echo '<a class="button primary" href="index.php?screen=edit">新規作成</a>';
    echo '<a class="button secondary" href="index.php?screen=kintone">kintone設定</a>';
    echo '<a class="button secondary" href="index.php?screen=mail">メール設定</a>';
    echo '</div>';

    echo '<div class="card">';

    echo '<form method="get">';
    echo '<input type="hidden" name="screen" value="list">';

    echo '<div class="form-grid">';

    form_row(
        '検索',
        '<input name="q" value="' .
        h($keyword) .
        '" placeholder="タイトルで検索">'
    );

    form_row(
        'ステータス',
        '<select name="status">' .
        option('all', 'すべて', $status === '' ? 'all' : $status) .
        option('published', '公開中', $status) .
        option('draft', '下書き', $status) .
        option('stopped', '停止', $status) .
        option('ended', '終了', $status) .
        '</select>'
    );

    form_row(
        'ソート',
        '<select name="sort">' .
        option('updated_desc', '更新日：新しい順', $sort) .
        option('updated_asc', '更新日：古い順', $sort) .
        option('answers_desc', '回答数：多い順', $sort) .
        option('answers_asc', '回答数：少ない順', $sort) .
        option('start_desc', '開始日：新しい順', $sort) .
        option('start_asc', '開始日：古い順', $sort) .
        '</select>'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="primary">検索</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="card">';
    echo '<div class="table-wrap">';
    echo '<table>';

    echo '<thead>';
    echo '<tr>';
    echo '<th>タイトル</th>';
    echo '<th>作成日</th>';
    echo '<th>更新日</th>';
    echo '<th>期間</th>';
    echo '<th>ステータス</th>';
    echo '<th>回答数</th>';
    echo '<th>操作</th>';
    echo '</tr>';
    echo '</thead>';

    echo '<tbody>';

    if (count($filtered) === 0) {

        echo '<tr>';
        echo '<td colspan="7">アンケートはありません。</td>';
        echo '</tr>';

    } else {

        foreach ($filtered as $survey) {

            $id = (string)(
                $survey['id'] ?? ''
            );

            echo '<tr>';

            echo '<td>' .
                h((string)(
                    $survey['title'] ?? ''
                )) .
                '</td>';

            echo '<td>' .
                h(format_datetime(
                    (string)(
                        $survey['createdAt'] ?? ''
                    )
                )) .
                '</td>';

            echo '<td>' .
                h(format_datetime(
                    (string)(
                        $survey['updatedAt'] ?? ''
                    )
                )) .
                '</td>';

            echo '<td>' .
                h(format_period($survey)) .
                '</td>';

            echo '<td>' .
                status_badge(
                    (string)(
                        $survey['status'] ?? ''
                    )
                ) .
                '</td>';

            echo '<td>' .
                h(
                    (string)answer_count_for_survey($id)
                ) .
                '</td>';

            echo '<td>';

            echo '<a class="button secondary" href="' .
                h(
                    'index.php?screen=edit&id=' .
                    rawurlencode($id)
                ) .
                '">確認・編集</a> ';

            echo '<a class="button secondary" href="' .
                h(
                    'index.php?screen=analytics&id=' .
                    rawurlencode($id)
                ) .
                '">集計</a> ';

            echo '<a class="button secondary" href="' .
                h(
                    'index.php?screen=send&id=' .
                    rawurlencode($id)
                ) .
                '">送信</a> ';

            echo '<form method="post" class="inline-form" data-confirm="このアンケートを複製しますか？">';

            echo '<input type="hidden" name="action" value="duplicate_survey">';
            echo '<input type="hidden" name="id" value="' .
                h($id) .
                '">';

            echo '<button class="secondary">複製</button>';

            echo '</form> ';

            echo '<form method="post" class="inline-form" data-confirm="このアンケートを削除しますか？">';

            echo '<input type="hidden" name="action" value="delete_survey">';
            echo '<input type="hidden" name="id" value="' .
                h($id) .
                '">';

            echo '<button class="danger">削除</button>';

            echo '</form>';

            echo '</td>';

            echo '</tr>';
        }
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}


/*
 * =========================================================
 * Kintone
 * =========================================================
 */

function handle_save_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);

    $k = &$settings['kintone'];

    $k['subdomain'] =
        trim((string)(
            $_POST['subdomain'] ?? ''
        ));

    $k['app_id'] =
        trim((string)(
            $_POST['app_id'] ?? ''
        ));

    $k['username'] =
        trim((string)(
            $_POST['username'] ?? ''
        ));

    if (
        isset($_POST['password'])
        && (string)$_POST['password'] !== ''
    ) {

        $k['password'] =
            (string)$_POST['password'];
    }

    $k['proxy'] =
        trim((string)(
            $_POST['proxy'] ?? ''
        ));

    $k['verify_ssl'] =
        isset($_POST['verify_ssl']);

    validate_kintone_settings($k);

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'kintone設定を保存しました。'
    );

    redirect(
        'index.php?screen=kintone'
    );
}


function handle_test_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);

    validate_kintone_settings(
        $settings['kintone']
    );

    try {

        $k = $settings['kintone'];

        $result = kintone_request(
            $k,
            '/v1/app.json?id=' .
            rawurlencode(
                (string)$k['app_id']
            ),
            'GET'
        );

        if (
            $result['status'] >= 200
            && $result['status'] < 300
        ) {

            $settings['kintone']
                ['connection_status']
                = '接続確認済み';

            $settings['kintone']
                ['last_test_at']
                = now_iso();

            write_json_atomic(
                SETTINGS_FILE,
                $settings
            );

            flash(
                'success',
                'kintoneへの接続に成功しました。'
            );

        } else {

            throw new RuntimeException(
                'kintoneからHTTP ' .
                (int)$result['status'] .
                ' が返されました。' .
                kintone_error_message($result)
            );
        }

    } catch (Throwable $e) {

        $settings['kintone']
            ['connection_status']
            = '接続できません';

        $settings['kintone']
            ['last_test_at']
            = now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'error',
            'kintone接続テストに失敗しました。' .
            user_error_message($e)
        );
    }

    redirect(
        'index.php?screen=kintone'
    );
}


/*
 * 項目一覧再取得。
 *
 * 接続テストとは別操作。
 */
function handle_fetch_kintone_fields(): void
{
    $settings = read_json(SETTINGS_FILE);

    validate_kintone_settings(
        $settings['kintone']
    );

    $k = $settings['kintone'];

    $result = kintone_request(
        $k,
        '/v1/app/form/fields.json?id=' .
        rawurlencode(
            (string)$k['app_id']
        ),
        'GET'
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {

        throw new RuntimeException(
            '項目一覧の取得に失敗しました。' .
            kintone_error_message($result)
        );
    }

    $data = json_decode(
        $result['body'],
        true
    );

    if (!is_array($data)) {

        throw new RuntimeException(
            'kintoneの項目情報を解析できませんでした。'
        );
    }

    $_SESSION['kintone_fields'] =
        $data['properties'] ?? [];

    flash(
        'success',
        'kintone項目一覧を再取得しました。'
    );

    redirect(
        'index.php?screen=kintone'
    );
}


/*
 * 顧客情報同期。
 */
function handle_sync_kintone(): void
{
    $settings = read_json(SETTINGS_FILE);

    validate_kintone_settings(
        $settings['kintone']
    );

    $k = $settings['kintone'];

    $appId =
        rawurlencode(
            (string)$k['app_id']
        );

    $query =
        '/v1/records.json?app=' .
        $appId .
        '&totalCount=true';

    $result = kintone_request(
        $k,
        $query,
        'GET'
    );

    if (
        $result['status'] < 200
        || $result['status'] >= 300
    ) {

        throw new RuntimeException(
            '顧客情報の同期に失敗しました。' .
            kintone_error_message($result)
        );
    }

    $data = json_decode(
        $result['body'],
        true
    );

    if (!is_array($data)) {

        throw new RuntimeException(
            'kintoneの顧客データを解析できませんでした。'
        );
    }

    $customers = [];

    foreach (
        ($data['records'] ?? []) as $record
    ) {

        if (!is_array($record)) {
            continue;
        }

        $customers[] =
            map_kintone_customer(
                $record,
                $k['field_mapping'] ?? []
            );
    }

    write_json_atomic(
        CUSTOMERS_FILE,
        $customers
    );

    flash(
        'success',
        count($customers) .
        '件の顧客情報を同期しました。'
    );

    redirect(
        'index.php?screen=kintone'
    );
}


/*
 * =========================================================
 * Mail
 * =========================================================
 */

function handle_save_mail(): void
{
    $settings = read_json(SETTINGS_FILE);

    $m = &$settings['mail'];

    $m['host'] =
        trim((string)(
            $_POST['host'] ?? ''
        ));

    $m['port'] =
        (int)($_POST['port'] ?? 0);

    $m['encryption'] =
        (string)(
            $_POST['encryption'] ?? 'tls'
        );

    $m['auth'] =
        isset($_POST['auth']);

    $m['username'] =
        trim((string)(
            $_POST['username'] ?? ''
        ));

    if (
        isset($_POST['password'])
        && (string)$_POST['password'] !== ''
    ) {

        $m['password'] =
            (string)$_POST['password'];
    }

    $m['from_email'] =
        trim((string)(
            $_POST['from_email'] ?? ''
        ));

    $m['from_name'] =
        trim((string)(
            $_POST['from_name'] ?? ''
        ));

    $m['reply_to'] =
        trim((string)(
            $_POST['reply_to'] ?? ''
        ));

    validate_mail_settings($m);

    write_json_atomic(
        SETTINGS_FILE,
        $settings
    );

    flash(
        'success',
        'メール設定を保存しました。'
    );

    redirect(
        'index.php?screen=mail'
    );
}


function handle_test_mail(): void
{
    $settings = read_json(SETTINGS_FILE);

    validate_mail_settings(
        $settings['mail']
    );

    try {

        smtp_test_connection(
            $settings['mail']
        );

        $settings['mail']
            ['connection_status']
            = '接続確認済み';

        $settings['mail']
            ['last_test_at']
            = now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'success',
            'SMTPサーバーへの接続に成功しました。'
        );

    } catch (Throwable $e) {

        $settings['mail']
            ['connection_status']
            = '接続できません';

        $settings['mail']
            ['last_test_at']
            = now_iso();

        write_json_atomic(
            SETTINGS_FILE,
            $settings
        );

        flash(
            'error',
            'SMTP接続テストに失敗しました。' .
            user_error_message($e)
        );
    }

    redirect(
        'index.php?screen=mail'
    );
}


/*
 * =========================================================
 * Survey handlers
 * =========================================================
 */

function handle_save_survey(): void
{
    $id =
        trim((string)(
            $_POST['id'] ?? ''
        ));

    $title =
        trim((string)(
            $_POST['title'] ?? ''
        ));

    $description =
        trim((string)(
            $_POST['description'] ?? ''
        ));

    $startAt =
        normalize_datetime(
            (string)(
                $_POST['startAt'] ?? ''
            )
        );

    $endAt =
        normalize_datetime(
            (string)(
                $_POST['endAt'] ?? ''
            )
        );

    $numbering =
        (string)(
            $_POST['numbering'] ?? 'global'
        );

    if ($title === '') {
        throw new InvalidArgumentException(
            'アンケートタイトルを入力してください。'
        );
    }

    if (
        !in_array(
            $numbering,
            ['global', 'group'],
            true
        )
    ) {

        throw new InvalidArgumentException(
            '採番方式が不正です。'
        );
    }

    if (
        $startAt !== null
        && $endAt !== null
        && strtotime($endAt) < strtotime($startAt)
    ) {

        throw new InvalidArgumentException(
            '終了日時は開始日時以降にしてください。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    if ($id === '') {

        $survey = [
            'id' => uuid(),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => 'draft',
            'numbering' => $numbering,
            'groups' => [
                [
                    'id' => uuid(),
                    'title' => 'グループ1',
                    'questions' => [],
                ],
            ],
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ];

        $surveys[] = $survey;

    } else {

        $found = false;

        foreach ($surveys as &$item) {

            if (
                (string)(
                    $item['id'] ?? ''
                ) !== $id
            ) {
                continue;
            }

            $found = true;

            /*
             * 終了状態を手動変更しない。
             */
            $item['title'] =
                $title;

            $item['description'] =
                $description;

            $item['startAt'] =
                $startAt;

            $item['endAt'] =
                $endAt;

            $item['numbering'] =
                $numbering;

            $item['updatedAt'] =
                now_iso();

            recalculate_question_numbers(
                $item
            );

            break;
        }

        unset($item);

        if (!$found) {

            throw new RuntimeException(
                '指定されたアンケートが存在しません。'
            );
        }
    }

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        'アンケートを保存しました。'
    );

    redirect(
        'index.php?screen=list'
    );
}


function handle_delete_survey(): void
{
    $id =
        trim((string)(
            $_POST['id'] ?? ''
        ));

    if ($id === '') {
        throw new InvalidArgumentException(
            '削除対象が指定されていません。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    $new = [];
    $deleted = false;

    foreach ($surveys as $survey) {

        if (
            (string)(
                $survey['id'] ?? ''
            ) === $id
        ) {

            $deleted = true;
            continue;
        }

        $new[] = $survey;
    }

    if (!$deleted) {

        throw new RuntimeException(
            '指定されたアンケートが存在しません。'
        );
    }

    write_json_atomic(
        SURVEYS_FILE,
        $new
    );

    flash(
        'success',
        'アンケートを削除しました。'
    );

    redirect(
        'index.php?screen=list'
    );
}


function handle_duplicate_survey(): void
{
    $id =
        trim((string)(
            $_POST['id'] ?? ''
        ));

    $source =
        find_survey($id);

    if ($source === null) {

        throw new RuntimeException(
            '複製対象が存在しません。'
        );
    }

    $copy =
        $source;

    $copy['id'] =
        uuid();

    $copy['title'] =
        (string)(
            $source['title'] ?? ''
        ) .
        '（コピー）';

    $copy['status'] =
        'draft';

    $copy['createdAt'] =
        now_iso();

    $copy['updatedAt'] =
        now_iso();

    $surveys =
        read_json(SURVEYS_FILE);

    $surveys[] =
        $copy;

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        'アンケートを複製しました。'
    );

    redirect(
        'index.php?screen=list'
    );
}


function handle_change_status(): void
{
    $id =
        trim((string)(
            $_POST['id'] ?? ''
        ));

    $next =
        (string)(
            $_POST['status'] ?? ''
        );

    $allowed = [
        'draft',
        'published',
        'stopped',
    ];

    if (
        !in_array(
            $next,
            $allowed,
            true
        )
    ) {

        throw new InvalidArgumentException(
            '指定された状態へ変更できません。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    $found = false;

    foreach ($surveys as &$survey) {

        if (
            (string)(
                $survey['id'] ?? ''
            ) !== $id
        ) {
            continue;
        }

        $found = true;

        $current =
            (string)(
                $survey['status'] ?? ''
            );

        if ($current === 'ended') {

            throw new RuntimeException(
                '終了状態のアンケートは変更できません。'
            );
        }

        $validTransitions = [
            'draft' => ['published'],
            'published' => ['stopped'],
            'stopped' => ['published'],
        ];

        if (
            !in_array(
                $next,
                $validTransitions[$current] ?? [],
                true
            )
        ) {

            throw new RuntimeException(
                '現在の状態からその状態へ変更できません。'
            );
        }

        $survey['status'] =
            $next;

        $survey['updatedAt'] =
            now_iso();

        break;
    }

    unset($survey);

    if (!$found) {

        throw new RuntimeException(
            '指定されたアンケートが存在しません。'
        );
    }

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        'アンケートの状態を変更しました。'
    );

    redirect(
        'index.php?screen=edit&id=' .
        rawurlencode($id)
    );
}


/*
 * =========================================================
 * Edit / Preview
 * =========================================================
 */

function render_edit(?array $survey): void
{
    $isNew =
        $survey === null;

    if ($isNew) {

        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => null,
            'endAt' => null,
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => '',
                    'title' => 'グループ1',
                    'questions' => [],
                ],
            ],
        ];
    }

    echo '<h1>アンケート作成・編集</h1>';

    echo '<div class="card">';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="save_survey">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';

    echo '<div class="form-grid">';

    form_row(
        'アンケートタイトル',
        '<input required name="title" value="' .
        h((string)(
            $survey['title'] ?? ''
        )) .
        '">'
    );

    form_row(
        'アンケート説明',
        '<textarea name="description">' .
        h((string)(
            $survey['description'] ?? ''
        )) .
        '</textarea>'
    );

    form_row(
        '開始日時',
        '<input type="datetime-local" name="startAt" value="' .
        h(datetime_local(
            (string)(
                $survey['startAt'] ?? ''
            )
        )) .
        '">'
    );

    form_row(
        '終了日時',
        '<input type="datetime-local" name="endAt" value="' .
        h(datetime_local(
            (string)(
                $survey['endAt'] ?? ''
            )
        )) .
        '">'
    );

    form_row(
        '質問番号の採番方式',
        '<select name="numbering">' .
        option(
            'global',
            'アンケート全体で通番：Q1、Q2、Q3...',
            (string)(
                $survey['numbering'] ?? 'global'
            )
        ) .
        option(
            'group',
            'グループ毎：Q1-1、Q1-2、Q2-1...',
            (string)(
                $survey['numbering'] ?? 'global'
            )
        ) .
        '</select>'
    );

    echo '</div>';

    echo '<div class="actions">';

    echo '<a class="button secondary" href="index.php?screen=list">キャンセル</a>';

    echo '<button class="primary">保存して一覧へ</button>';

    echo '</div>';

    echo '</form>';

    echo '</div>';

    /*
     * 既存アンケートのみ質問編集UIを表示。
     */
    if (!$isNew) {

        render_question_editor(
            $survey
        );

        render_status_controls(
            $survey
        );
    }
}


function render_status_controls(
    array $survey
): void {

    $status =
        (string)(
            $survey['status'] ?? 'draft'
        );

    echo '<div class="card">';
    echo '<h2>状態</h2>';

    echo '<p>' .
        status_badge($status) .
        '</p>';

    if ($status === 'ended') {

        echo '<p>終了状態では変更できません。</p>';

        echo '</div>';

        return;
    }

    echo '<div class="actions">';

    if ($status === 'draft') {

        echo '<form method="post" data-confirm="アンケートを公開しますか？">';
        echo '<input type="hidden" name="action" value="change_status">';
        echo '<input type="hidden" name="id" value="' .
            h((string)$survey['id']) .
            '">';
        echo '<input type="hidden" name="status" value="published">';
        echo '<button class="success">公開</button>';
        echo '</form>';
    }

    if ($status === 'published') {

        echo '<form method="post" data-confirm="アンケートを停止しますか？">';
        echo '<input type="hidden" name="action" value="change_status">';
        echo '<input type="hidden" name="id" value="' .
            h((string)$survey['id']) .
            '">';
        echo '<input type="hidden" name="status" value="stopped">';
        echo '<button class="danger">停止</button>';
        echo '</form>';
    }

    if ($status === 'stopped') {

        echo '<form method="post" data-confirm="アンケートを再開しますか？">';
        echo '<input type="hidden" name="action" value="change_status">';
        echo '<input type="hidden" name="id" value="' .
            h((string)$survey['id']) .
            '">';
        echo '<input type="hidden" name="status" value="published">';
        echo '<button class="success">再開</button>';
        echo '</form>';
    }

    echo '</div>';
    echo '</div>';
}


/*
 * =========================================================
 * Question editor
 * =========================================================
 */

function render_question_editor(
    array $survey
): void {

    echo '<div class="card">';
    echo '<h2>質問・グループ</h2>';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="save_questions">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';

    foreach (
        ($survey['groups'] ?? []) as $groupIndex => $group
    ) {

        echo '<div class="card">';

        echo '<h3>' .
            h((string)(
                $group['title'] ?? ''
            )) .
            '</h3>';

        echo '<input name="groups[' .
            (int)$groupIndex .
            '][title]" value="' .
            h((string)(
                $group['title'] ?? ''
            )) .
            '">';

        foreach (
            ($group['questions'] ?? []) as $questionIndex => $question
        ) {

            echo '<div class="card">';

            echo '<strong>' .
                h((string)(
                    $question['number'] ?? ''
                )) .
                '</strong>';

            echo '<input type="hidden" name="groups[' .
                (int)$groupIndex .
                '][questions][' .
                (int)$questionIndex .
                '][id]" value="' .
                h((string)(
                    $question['id'] ?? ''
                )) .
                '">';

            echo '<div class="form-grid">';

            form_row(
                '質問文',
                '<input name="groups[' .
                (int)$groupIndex .
                '][questions][' .
                (int)$questionIndex .
                '][text]" value="' .
                h((string)(
                    $question['text'] ?? ''
                )) .
                '">'
            );

            form_row(
                '回答形式',
                '<select name="groups[' .
                (int)$groupIndex .
                '][questions][' .
                (int)$questionIndex .
                '][type]">' .
                option(
                    'single',
                    '単一選択',
                    (string)(
                        $question['type'] ?? 'single'
                    )
                ) .
                option(
                    'multiple',
                    '複数選択',
                    (string)(
                        $question['type'] ?? ''
                    )
                ) .
                option(
                    'text',
                    '自由記述',
                    (string)(
                        $question['type'] ?? ''
                    )
                ) .
                '</select>'
            );

            $options =
                implode(
                    "\n",
                    array_map(
                        'strval',
                        $question['options'] ?? []
                    )
                );

            form_row(
                '選択肢',
                '<textarea name="groups[' .
                (int)$groupIndex .
                '][questions][' .
                (int)$questionIndex .
                '][options]">' .
                h($options) .
                '</textarea>'
            );

            form_row(
                '必須',
                '<label><input type="checkbox" name="groups[' .
                (int)$groupIndex .
                '][questions][' .
                (int)$questionIndex .
                '][required]" value="1" ' .
                (
                    !empty($question['required'])
                        ? 'checked'
                        : ''
                ) .
                '> 必須</label>'
            );

            echo '</div>';

            echo '</div>';
        }

        echo '</div>';
    }

    echo '<div class="actions">';
    echo '<button class="primary">質問・グループを保存</button>';

    echo '<a class="button secondary" href="' .
        h(
            'index.php?screen=preview&id=' .
            rawurlencode(
                (string)$survey['id']
            )
        ) .
        '">プレビュー</a>';

    echo '</div>';

    echo '</form>';
    echo '</div>';
}


function handle_save_questions(): void
{
    $id =
        trim((string)(
            $_POST['id'] ?? ''
        ));

    $groups =
        $_POST['groups'] ?? [];

    if (!is_array($groups)) {

        throw new InvalidArgumentException(
            '質問データが不正です。'
        );
    }

    $surveys =
        read_json(SURVEYS_FILE);

    $found = false;

    foreach ($surveys as &$survey) {

        if (
            (string)(
                $survey['id'] ?? ''
            ) !== $id
        ) {
            continue;
        }

        $found = true;

        $normalizedGroups = [];

        foreach ($groups as $groupData) {

            if (!is_array($groupData)) {
                continue;
            }

            $group = [
                'id' =>
                    trim((string)(
                        $groupData['id'] ?? uuid()
                    )) ?: uuid(),

                'title' =>
                    trim((string)(
                        $groupData['title'] ?? ''
                    )),

                'questions' => [],
            ];

            foreach (
                ($groupData['questions'] ?? []) as $questionData
            ) {

                if (!is_array($questionData)) {
                    continue;
                }

                $type =
                    (string)(
                        $questionData['type'] ?? 'text'
                    );

                if (
                    !in_array(
                        $type,
                        ['single', 'multiple', 'text'],
                        true
                    )
                ) {
                    $type = 'text';
                }

                $rawOptions =
                    preg_split(
                        '/\R/u',
                        (string)(
                            $questionData['options'] ?? ''
                        )
                    );

                $options = [];

                foreach (
                    ($rawOptions ?: []) as $option
                ) {

                    $option =
                        trim((string)$option);

                    if ($option !== '') {
                        $options[] = $option;
                    }
                }

                $group['questions'][] = [
                    'id' =>
                        trim((string)(
                            $questionData['id'] ?? ''
                        )) ?: uuid(),

                    'text' =>
                        trim((string)(
                            $questionData['text'] ?? ''
                        )),

                    'type' =>
                        $type,

                    'required' =>
                        isset(
                            $questionData['required']
                        ),

                    'options' =>
                        $options,

                    'branching' =>
                        $questionData['branching']
                        ?? [],
                ];
            }

            $normalizedGroups[] =
                $group;
        }

        $survey['groups'] =
            $normalizedGroups;

        recalculate_question_numbers(
            $survey
        );

        $survey['updatedAt'] =
            now_iso();

        break;
    }

    unset($survey);

    if (!$found) {

        throw new RuntimeException(
            '指定されたアンケートが存在しません。'
        );
    }

    write_json_atomic(
        SURVEYS_FILE,
        $surveys
    );

    flash(
        'success',
        '質問・グループを保存しました。'
    );

    redirect(
        'index.php?screen=edit&id=' .
        rawurlencode($id)
    );
}


/*
 * =========================================================
 * Preview
 * =========================================================
 */

function render_preview(
    ?array $survey
): void {

    if ($survey === null) {

        echo '<h1>プレビュー</h1>';
        echo '<div class="card">アンケートが存在しません。</div>';

        return;
    }

    echo '<h1>プレビュー</h1>';

    echo '<div class="card">';

    echo '<h2>' .
        h((string)$survey['title']) .
        '</h2>';

    echo '<p>' .
        nl2br(h((string)(
            $survey['description'] ?? ''
        ))) .
        '</p>';

    foreach (
        ($survey['groups'] ?? []) as $group
    ) {

        echo '<div class="card">';

        echo '<h3>' .
            h((string)(
                $group['title'] ?? ''
            )) .
            '</h3>';

        foreach (
            ($group['questions'] ?? []) as $question
        ) {

            echo '<div class="card">';

            echo '<strong>' .
                h((string)(
                    $question['number'] ?? ''
                )) .
                ' ' .
                h((string)(
                    $question['text'] ?? ''
                )) .
                '</strong>';

            if (!empty($question['required'])) {
                echo ' <span class="small">必須</span>';
            }

            echo '<div style="margin-top:10px">';
            render_question_input(
                $question,
                true
            );
            echo '</div>';

            echo '</div>';
        }

        echo '</div>';
    }

    echo '</div>';

    echo '<div class="actions">';
    echo '<a class="button secondary" href="' .
        h(
            'index.php?screen=edit&id=' .
            rawurlencode(
                (string)$survey['id']
            )
        ) .
        '">編集へ戻る</a>';
    echo '</div>';
}


/*
 * =========================================================
 * Send
 * =========================================================
 */

function render_send(
    ?array $survey
): void {

    if ($survey === null) {
        return;
    }

    $customers =
        read_json(CUSTOMERS_FILE);

    $logs =
        read_json(SEND_LOG_FILE);

    $surveyId =
        (string)$survey['id'];

    echo '<h1>顧客選択・メール送信</h1>';

    echo '<div class="card">';
    echo '<h2>対象アンケート</h2>';
    echo '<p>' .
        h((string)$survey['title']) .
        '</p>';
    echo '</div>';

    echo '<div class="card">';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="send_mail">';
    echo '<input type="hidden" name="surveyId" value="' .
        h($surveyId) .
        '">';

    echo '<h2>顧客選択</h2>';

    if (count($customers) === 0) {

        echo '<p>顧客情報がありません。kintoneから同期してください。</p>';

    } else {

        echo '<div class="table-wrap">';
        echo '<table>';

        echo '<thead><tr>';
        echo '<th>選択</th>';
        echo '<th>組織名</th>';
        echo '<th>氏名</th>';
        echo '<th>メール</th>';
        echo '<th>部署</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        foreach ($customers as $customer) {

            $customerId =
                (string)(
                    $customer['id'] ?? ''
                );

            echo '<tr>';

            echo '<td>';
            echo '<input type="checkbox" name="customer_ids[]" value="' .
                h($customerId) .
                '">';
            echo '</td>';

            echo '<td>' .
                h((string)(
                    $customer['organization'] ?? ''
                )) .
                '</td>';

            echo '<td>' .
                h((string)(
                    $customer['name'] ?? ''
                )) .
                '</td>';

            echo '<td>' .
                h((string)(
                    $customer['email'] ?? ''
                )) .
                '</td>';

            echo '<td>' .
                h((string)(
                    $customer['department'] ?? ''
                )) .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '<div class="form-grid" style="margin-top:20px">';

    form_row(
        '件名',
        '<input required name="subject" value="' .
        h((string)$survey['title']) .
        '">'
    );

    form_row(
        '本文',
        '<textarea required name="body">' .
        h(
            "アンケートへのご協力をお願いいたします。\n\n" .
            "{顧客名} 様\n\n" .
            "アンケートURL:\n" .
            "{アンケートURL}"
        ) .
        '</textarea>'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="success" data-confirm="選択した顧客へメールを送信しますか？">一括送信</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>送信履歴</h2>';

    $surveyLogs =
        array_values(
            array_filter(
                $logs,
                static function ($log) use ($surveyId): bool {
                    return
                        (string)(
                            $log['surveyId'] ?? ''
                        ) === $surveyId;
                }
            )
        );

    if (count($surveyLogs) === 0) {

        echo '<p>送信履歴はありません。</p>';

    } else {

        echo '<div class="table-wrap">';
        echo '<table>';

        echo '<thead><tr>';
        echo '<th>日時</th>';
        echo '<th>メール</th>';
        echo '<th>結果</th>';
        echo '<th>内容</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        foreach (
            array_reverse($surveyLogs) as $log
        ) {

            echo '<tr>';

            echo '<td>' .
                h(format_datetime(
                    (string)(
                        $log['createdAt'] ?? ''
                    )
                )) .
                '</td>';

            echo '<td>' .
                h((string)(
                    $log['email'] ?? ''
                )) .
                '</td>';

            echo '<td>' .
                h((string)(
                    $log['status'] ?? ''
                )) .
                '</td>';

            echo '<td>' .
                h((string)(
                    $log['message'] ?? ''
                )) .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';
}


/*
 * =========================================================
 * Analytics
 * =========================================================
 */

function render_analytics(
    ?array $survey
): void {

    if ($survey === null) {
        return;
    }

    $surveyId =
        (string)$survey['id'];

    $answers =
        array_values(
            array_filter(
                read_json(ANSWERS_FILE),
                static function ($answer) use ($surveyId): bool {
                    return
                        (string)(
                            $answer['surveyId'] ?? ''
                        ) === $surveyId;
                }
            )
        );

    $logs =
        array_values(
            array_filter(
                read_json(SEND_LOG_FILE),
                static function ($log) use ($surveyId): bool {
                    return
                        (string)(
                            $log['surveyId'] ?? ''
                        ) === $surveyId
                        &&
                        (string)(
                            $log['status'] ?? ''
                        ) === 'sent';
                }
            )
        );

    $sentCount =
        count($logs);

    $answerCount =
        count($answers);

    $unanswered =
        max(
            0,
            $sentCount - $answerCount
        );

    $rate =
        $sentCount > 0
            ? round(
                $answerCount /
                $sentCount *
                100,
                1
            )
            : 0;

    echo '<h1>回答集計・分析</h1>';

    echo '<div class="card">';

    echo '<h2>対象アンケート</h2>';

    echo '<p>' .
        h((string)$survey['title']) .
        '</p>';

    echo '<div class="form-grid">';

    form_row(
        '送信対象者数',
        (string)$sentCount
    );

    form_row(
        '回答数',
        (string)$answerCount
    );

    form_row(
        '未回答数',
        (string)$unanswered
    );

    form_row(
        '未登録回答数',
        '0'
    );

    form_row(
        '回答率',
        $rate . '%'
    );

    echo '</div>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>設問別集計</h2>';

    if ($answerCount === 0) {

        echo '<p>現在、回答データはありません</p>';

    } else {

        foreach (
            ($survey['groups'] ?? []) as $group
        ) {

            foreach (
                ($group['questions'] ?? []) as $question
            ) {

                $qid =
                    (string)(
                        $question['id'] ?? ''
                    );

                $values = [];

                foreach ($answers as $answer) {

                    if (
                        array_key_exists(
                            $qid,
                            $answer['answers'] ?? []
                        )
                    ) {

                        $values[] =
                            $answer['answers'][$qid];
                    }
                }

                echo '<div class="card">';

                echo '<strong>' .
                    h((string)(
                        $question['number'] ?? ''
                    )) .
                    ' ' .
                    h((string)(
                        $question['text'] ?? ''
                    )) .
                    '</strong>';

                if (count($values) === 0) {

                    echo '<p>回答なし</p>';

                } else {

                    $counts = [];

                    foreach ($values as $value) {

                        if (is_array($value)) {

                            foreach ($value as $item) {

                                $item =
                                    (string)$item;

                                $counts[$item] =
                                    ($counts[$item] ?? 0) + 1;
                            }

                        } else {

                            $item =
                                (string)$value;

                            $counts[$item] =
                                ($counts[$item] ?? 0) + 1;
                        }
                    }

                    echo '<table>';
                    echo '<thead><tr>';
                    echo '<th>回答</th>';
                    echo '<th>件数</th>';
                    echo '</tr></thead>';
                    echo '<tbody>';

                    foreach (
                        $counts as $value => $count
                    ) {

                        echo '<tr>';
                        echo '<td>' .
                            h($value) .
                            '</td>';
                        echo '<td>' .
                            h((string)$count) .
                            '</td>';
                        echo '</tr>';
                    }

                    echo '</tbody>';
                    echo '</table>';
                }

                echo '</div>';
            }
        }
    }

    echo '</div>';

    echo '<div class="actions">';

    echo '<a class="button secondary" href="' .
        h(
            'index.php?screen=analytics&id=' .
            rawurlencode($surveyId) .
            '&format=csv'
        ) .
        '">CSV出力</a>';

    echo '</div>';
}


/*
 * =========================================================
 * kintone screen
 * =========================================================
 */

function render_kintone(): void
{
    $settings =
        read_json(SETTINGS_FILE);

    $k =
        $settings['kintone'];

    $fields =
        $_SESSION['kintone_fields'] ?? [];

    echo '<h1>kintone連携設定</h1>';

    echo '<div class="card">';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="save_kintone">';

    echo '<div class="form-grid">';

    form_row(
        'サブドメイン',
        '<input name="subdomain" value="' .
        h((string)$k['subdomain']) .
        '" placeholder="https://xxxx.cybozu.com">'
    );

    form_row(
        '顧客管理アプリID',
        '<input type="number" min="1" name="app_id" value="' .
        h((string)$k['app_id']) .
        '">'
    );

    form_row(
        'ログイン名',
        '<input name="username" value="' .
        h((string)$k['username']) .
        '">'
    );

    form_row(
        'パスワード',
        '<input type="password" name="password" autocomplete="new-password" placeholder="変更する場合のみ入力">'
    );

    form_row(
        'Proxy',
        '<input name="proxy" value="' .
        h((string)$k['proxy']) .
        '" placeholder="host:port">'
    );

    form_row(
        'SSL証明書検証',
        '<label><input type="checkbox" name="verify_ssl" value="1" ' .
        (!empty($k['verify_ssl']) ? 'checked' : '') .
        '> 有効</label>'
    );

    echo '</div>';

    /*
     * 重要:
     *
     * 以下3操作を独立させる。
     */
    echo '<div class="actions">';

    echo '<button class="primary">設定保存</button>';

    echo '</form>';

    echo '<form method="post" data-busy>';
    echo '<input type="hidden" name="action" value="test_kintone">';
    echo '<button class="secondary">接続テスト</button>';
    echo '</form>';

    echo '<form method="post" data-busy>';
    echo '<input type="hidden" name="action" value="fetch_kintone_fields">';
    echo '<button class="secondary">項目一覧を再取得</button>';
    echo '</form>';

    echo '<form method="post" data-busy>';
    echo '<input type="hidden" name="action" value="sync_kintone">';
    echo '<button class="success">顧客情報を同期</button>';
    echo '</form>';

    echo '</div>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>接続状態</h2>';

    echo '<p>' .
        status_text(
            (string)(
                $k['connection_status'] ?? '未設定'
            )
        ) .
        '</p>';

    if (!empty($k['last_test_at'])) {

        echo '<p class="small">最終確認: ' .
            h(format_datetime(
                (string)$k['last_test_at']
            )) .
            '</p>';
    }

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>取得済み項目</h2>';

    if (count($fields) === 0) {

        echo '<p>項目一覧はまだ取得されていません。</p>';

    } else {

        echo '<div class="table-wrap">';
        echo '<table>';

        echo '<thead><tr>';
        echo '<th>フィールドコード</th>';
        echo '<th>ラベル</th>';
        echo '<th>タイプ</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        foreach ($fields as $code => $field) {

            echo '<tr>';

            echo '<td>' .
                h((string)$code) .
                '</td>';

            echo '<td>' .
                h((string)(
                    $field['label'] ?? ''
                )) .
                '</td>';

            echo '<td>' .
                h((string)(
                    $field['type'] ?? ''
                )) .
                '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    echo '</div>';
}


/*
 * =========================================================
 * Mail screen
 * =========================================================
 */

function render_mail(): void
{
    $settings =
        read_json(SETTINGS_FILE);

    $m =
        $settings['mail'];

    echo '<h1>メールサーバ設定</h1>';

    echo '<div class="card">';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="save_mail">';

    echo '<div class="form-grid">';

    form_row(
        'SMTPサーバ',
        '<input required name="host" value="' .
        h((string)$m['host']) .
        '">'
    );

    form_row(
        'SMTPポート',
        '<input required type="number" min="1" max="65535" name="port" value="' .
        h((string)$m['port']) .
        '">'
    );

    form_row(
        '暗号化方式',
        '<select name="encryption">' .
        option(
            'ssl',
            'SSL',
            (string)$m['encryption']
        ) .
        option(
            'tls',
            'TLS',
            (string)$m['encryption']
        ) .
        option(
            'none',
            'なし',
            (string)$m['encryption']
        ) .
        '</select>'
    );

    form_row(
        'SMTP認証',
        '<label><input type="checkbox" name="auth" value="1" ' .
        (!empty($m['auth']) ? 'checked' : '') .
        '> 使用する</label>'
    );

    form_row(
        'SMTPユーザー名',
        '<input name="username" value="' .
        h((string)$m['username']) .
        '">'
    );

    form_row(
        'SMTPパスワード',
        '<input type="password" name="password" autocomplete="new-password" placeholder="変更する場合のみ入力">'
    );

    form_row(
        '送信元メールアドレス',
        '<input required type="email" name="from_email" value="' .
        h((string)$m['from_email']) .
        '">'
    );

    form_row(
        '送信元名',
        '<input name="from_name" value="' .
        h((string)$m['from_name']) .
        '">'
    );

    form_row(
        '返信先メールアドレス',
        '<input type="email" name="reply_to" value="' .
        h((string)$m['reply_to']) .
        '">'
    );

    echo '</div>';

    /*
     * 設定保存は単独。
     */
    echo '<div class="actions">';
    echo '<button class="primary">設定保存</button>';
    echo '</div>';

    echo '</form>';

    /*
     * 接続テストは別form。
     */
    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="test_mail">';

    echo '<div class="actions">';
    echo '<button class="secondary">接続テスト</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';

    echo '<div class="card">';

    echo '<h2>接続状態</h2>';

    echo '<p>' .
        status_text(
            (string)(
                $m['connection_status'] ?? '未設定'
            )
        ) .
        '</p>';

    echo '</div>';

    /*
     * テストメール。
     */
    echo '<div class="card">';

    echo '<h2>テストメール送信</h2>';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="send_test_mail">';

    echo '<div class="form-grid">';

    form_row(
        'テスト送信先',
        '<input required type="email" name="test_to" placeholder="test@example.com">'
    );

    echo '</div>';

    echo '<div class="actions">';
    echo '<button class="success">テストメール送信</button>';
    echo '</div>';

    echo '</form>';

    echo '</div>';
}


/*
 * =========================================================
 * Answer
 * =========================================================
 */

function render_answer(
    ?array $survey
): void {

    if ($survey === null) {

        echo '<h1>アンケート回答</h1>';
        echo '<div class="card">アンケートが存在しません。</div>';

        return;
    }

    if (
        ($survey['status'] ?? '') !== 'published'
    ) {

        echo '<h1>アンケート回答</h1>';
        echo '<div class="card">現在回答できる状態ではありません。</div>';

        return;
    }

    echo '<h1>' .
        h((string)$survey['title']) .
        '</h1>';

    echo '<div class="card">';

    echo '<p>' .
        nl2br(h((string)(
            $survey['description'] ?? ''
        ))) .
        '</p>';

    echo '<form method="post" data-busy>';

    echo '<input type="hidden" name="action" value="answer_next">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';

    foreach (
        ($survey['groups'] ?? []) as $group
    ) {

        echo '<h2>' .
            h((string)(
                $group['title'] ?? ''
            )) .
            '</h2>';

        foreach (
            ($group['questions'] ?? []) as $question
        ) {

            echo '<div class="card">';

            echo '<p><strong>' .
                h((string)(
                    $question['number'] ?? ''
                )) .
                ' ' .
                h((string)(
                    $question['text'] ?? ''
                )) .
                '</strong>';

            if (!empty($question['required'])) {
                echo ' <span class="small">必須</span>';
            }

            echo '</p>';

            render_question_input(
                $question
            );

            echo '</div>';
        }
    }

    echo '<div class="actions">';
    echo '<button class="primary">次へ</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}


function handle_answer_next(): void
{
    $id =
        trim((string)(
            $_POST['id'] ?? ''
        ));

    $survey =
        find_survey($id);

    if ($survey === null) {

        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    validate_answer_payload(
        $survey,
        $_POST['answers'] ?? []
    );

    $_SESSION['answer_draft'][$id] =
        $_POST['answers'] ?? [];

    redirect(
        'index.php?screen=confirm&id=' .
        rawurlencode($id)
    );
}


function handle_answer_back(): void
{
    $id =
        trim((string)(
            $_POST['id'] ?? ''
        ));

    redirect(
        'index.php?screen=answer&id=' .
        rawurlencode($id)
    );
}


function handle_answer_submit(): void
{
    $id =
        trim((string)(
            $_POST['id'] ?? ''
        ));

    $survey =
        find_survey($id);

    if ($survey === null) {

        throw new RuntimeException(
            'アンケートが存在しません。'
        );
    }

    $answers =
        $_SESSION['answer_draft'][$id] ?? [];

    validate_answer_payload(
        $survey,
        $answers
    );

    $all =
        read_json(ANSWERS_FILE);

    $all[] = [
        'id' => uuid(),
        'surveyId' => $id,
        'answers' => $answers,
        'createdAt' => now_iso(),
    ];

    write_json_atomic(
        ANSWERS_FILE,
        $all
    );

    unset(
        $_SESSION['answer_draft'][$id]
    );

    redirect(
        'index.php?screen=complete&id=' .
        rawurlencode($id)
    );
}


function render_confirm(
    ?array $survey
): void {

    if ($survey === null) {

        echo '<h1>回答確認</h1>';
        echo '<div class="card">アンケートが存在しません。</div>';

        return;
    }

    $answers =
        $_SESSION['answer_draft'][
            (string)$survey['id']
        ] ?? [];

    echo '<h1>回答確認</h1>';

    foreach (
        ($survey['groups'] ?? []) as $group
    ) {

        foreach (
            ($group['questions'] ?? []) as $question
        ) {

            $qid =
                (string)(
                    $question['id'] ?? ''
                );

            $value =
                $answers[$qid] ?? '';

            if (is_array($value)) {
                $value =
                    implode(
                        ', ',
                        array_map(
                            'strval',
                            $value
                        )
                    );
            }

            echo '<div class="card">';

            echo '<strong>' .
                h((string)(
                    $question['number'] ?? ''
                )) .
                ' ' .
                h((string)(
                    $question['text'] ?? ''
                )) .
                '</strong>';

            echo '<p>' .
                nl2br(h((string)$value)) .
                '</p>';

            echo '</div>';
        }
    }

    echo '<div class="actions">';

    echo '<form method="post">';

    echo '<input type="hidden" name="action" value="answer_back">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';

    echo '<button class="secondary">戻る</button>';

    echo '</form>';

    echo '<form method="post" data-busy data-confirm="回答を送信しますか？">';

    echo '<input type="hidden" name="action" value="answer_submit">';
    echo '<input type="hidden" name="id" value="' .
        h((string)$survey['id']) .
        '">';

    echo '<button class="success">回答を送信</button>';

    echo '</form>';

    echo '</div>';
}


function render_complete(
    ?array $survey
): void {

    echo '<h1>回答完了</h1>';

    echo '<div class="card">';
    echo '<p>回答を受け付けました。</p>';
    echo '</div>';
}


/*
 * =========================================================
 * Question input
 * =========================================================
 */

function render_question_input(
    array $question,
    bool $preview = false
): void {

    $id =
        (string)(
            $question['id'] ?? ''
        );

    $type =
        (string)(
            $question['type'] ?? 'text'
        );

    $options =
        $question['options'] ?? [];

    $name =
        'answers[' .
        h($id) .
        ']';

    if ($type === 'single') {

        foreach ($options as $option) {

            echo '<label class="choice">';

            echo '<input type="radio" name="' .
                $name .
                '" value="' .
                h((string)$option) .
                '"' .
                (
                    $preview
                        ? ' disabled'
                        : ''
                ) .
                '>';

            echo ' ' .
                h((string)$option);

            echo '</label>';
        }

        return;
    }

    if ($type === 'multiple') {

        foreach ($options as $option) {

            echo '<label class="choice">';

            echo '<input type="checkbox" name="' .
                $name .
                '[]" value="' .
                h((string)$option) .
                '"' .
                (
                    $preview
                        ? ' disabled'
                        : ''
                ) .
                '>';

            echo ' ' .
                h((string)$option);

            echo '</label>';
        }

        return;
    }

    echo '<textarea name="' .
        $name .
        '"' .
        (
            $preview
                ? ' disabled'
                : ''
        ) .
        '></textarea>';
}


/*
 * =========================================================
 * Validation
 * =========================================================
 */

function validate_answer_payload(
    array $survey,
    mixed $answers
): void {

    if (!is_array($answers)) {

        throw new InvalidArgumentException(
            '回答データが不正です。'
        );
    }

    foreach (
        ($survey['groups'] ?? []) as $group
    ) {

        foreach (
            ($group['questions'] ?? []) as $question
        ) {

            if (
                empty($question['required'])
            ) {
                continue;
            }

            $id =
                (string)(
                    $question['id'] ?? ''
                );

            $value =
                $answers[$id] ?? null;

            $empty = false;

            if ($value === null) {
                $empty = true;
            } elseif (is_array($value)) {
                $empty =
                    count(
                        array_filter(
                            $value,
                            static fn($v) =>
                                trim((string)$v) !== ''
                        )
                    ) === 0;
            } else {
                $empty =
                    trim((string)$value) === '';
            }

            if ($empty) {

                throw new InvalidArgumentException(
                    '必須項目が未回答です。'
                );
            }
        }
    }
}


function validate_kintone_settings(
    array $k
): void {

    if (
        trim((string)(
            $k['subdomain'] ?? ''
        )) === ''
    ) {

        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (
        (int)($k['app_id'] ?? 0) <= 0
    ) {

        throw new InvalidArgumentException(
            '顧客管理アプリIDを入力してください。'
        );
    }

    if (
        trim((string)(
            $k['username'] ?? ''
        )) === ''
    ) {

        throw new InvalidArgumentException(
            'kintoneログイン名を入力してください。'
        );
    }

    if (
        trim((string)(
            $k['password'] ?? ''
        )) === ''
    ) {

        throw new InvalidArgumentException(
            'kintoneパスワードを設定してください。'
        );
    }

    $proxy =
        trim((string)(
            $k['proxy'] ?? ''
        ));

    if (
        $proxy !== ''
        && !preg_match(
            '/^[^:]+:\d+$/',
            $proxy
        )
    ) {

        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }
}


function validate_mail_settings(
    array $m
): void {

    if (
        trim((string)(
            $m['host'] ?? ''
        )) === ''
    ) {

        throw new InvalidArgumentException(
            'SMTPサーバを入力してください。'
        );
    }

    $port =
        (int)($m['port'] ?? 0);

    if (
        $port < 1
        || $port > 65535
    ) {

        throw new InvalidArgumentException(
            'SMTPポートが不正です。'
        );
    }

    if (
        !in_array(
            (string)($m['encryption'] ?? ''),
            ['ssl', 'tls', 'none'],
            true
        )
    ) {

        throw new InvalidArgumentException(
            '暗号化方式が不正です。'
        );
    }

    if (
        !filter_var(
            (string)($m['from_email'] ?? ''),
            FILTER_VALIDATE_EMAIL
        )
    ) {

        throw new InvalidArgumentException(
            '送信元メールアドレスが不正です。'
        );
    }

    if (
        !empty($m['reply_to'])
        && !filter_var(
            (string)$m['reply_to'],
            FILTER_VALIDATE_EMAIL
        )
    ) {

        throw new InvalidArgumentException(
            '返信先メールアドレスが不正です。'
        );
    }
}


/*
 * =========================================================
 * Storage
 * =========================================================
 */

function init_json_file(
    string $file,
    array $default
): void {

    if (!file_exists($file)) {
        write_json_atomic(
            $file,
            $default
        );
    }
}


function read_json(
    string $file
): array {

    if (!file_exists($file)) {
        return [];
    }

    $contents =
        file_get_contents($file);

    if (
        $contents === false
        || trim($contents) === ''
    ) {
        return [];
    }

    $data =
        json_decode(
            $contents,
            true
        );

    return is_array($data)
        ? $data
        : [];
}


function write_json_atomic(
    string $file,
    array $data
): void {

    $dir =
        dirname($file);

    if (!is_dir($dir)) {

        if (
            !mkdir(
                $dir,
                0775,
                true
            )
            && !is_dir($dir)
        ) {

            throw new RuntimeException(
                'データディレクトリを作成できません。'
            );
        }
    }

    $tmp =
        $file .
        '.' .
        bin2hex(
            random_bytes(8)
        ) .
        '.tmp';

    $json =
        json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

    if ($json === false) {

        throw new RuntimeException(
            'データをJSON化できませんでした。'
        );
    }

    $fp =
        fopen(
            $tmp,
            'wb'
        );

    if ($fp === false) {

        throw new RuntimeException(
            '一時ファイルを作成できませんでした。'
        );
    }

    try {

        if (!flock(
            $fp,
            LOCK_EX
        )) {

            throw new RuntimeException(
                'データファイルをロックできませんでした。'
            );
        }

        if (
            fwrite(
                $fp,
                $json
            ) === false
        ) {

            throw new RuntimeException(
                'データを書き込めませんでした。'
            );
        }

        fflush($fp);

        flock(
            $fp,
            LOCK_UN
        );

    } finally {

        fclose($fp);
    }

    if (!rename(
        $tmp,
        $file
    )) {

        @unlink($tmp);

        throw new RuntimeException(
            'データファイルを更新できませんでした。'
        );
    }
}


/*
 * =========================================================
 * kintone communication
 * =========================================================
 */

function kintone_request(
    array $settings,
    string $path,
    string $method = 'GET',
    ?string $body = null
): array {

    $host =
        normalize_kintone_host(
            (string)(
                $settings['subdomain'] ?? ''
            )
        );

    if ($host === '') {

        throw new InvalidArgumentException(
            'kintoneサブドメインが未設定です。'
        );
    }

    $authorization =
        base64_encode(
            (string)$settings['username'] .
            ':' .
            (string)$settings['password']
        );

    $headers = [
        'X-Cybozu-Authorization: ' .
        $authorization,
        'Accept: application/json',
    ];

    if ($body !== null) {
        $headers[] =
            'Content-Type: application/json';
    }

    $options = [
        'http' => [
            'method' =>
                strtoupper($method),

            'header' =>
                implode(
                    "\r\n",
                    $headers
                ),

            'content' =>
                $body ?? '',

            'timeout' =>
                READ_TIMEOUT,

            'ignore_errors' =>
                true,
        ],

        'ssl' => [
            'verify_peer' =>
                (bool)(
                    $settings['verify_ssl'] ?? false
                ),

            'verify_peer_name' =>
                (bool)(
                    $settings['verify_ssl'] ?? false
                ),

            'allow_self_signed' =>
                !(bool)(
                    $settings['verify_ssl'] ?? false
                ),
        ],
    ];

    $proxy =
        trim((string)(
            $settings['proxy'] ?? ''
        ));

    if ($proxy !== '') {

        $options['http']['proxy'] =
            'tcp://' . $proxy;

        $options['http']['request_fulluri'] =
            true;
    }

    $context =
        stream_context_create(
            $options
        );

    $url =
        'https://' .
        $host .
        $path;

    $response =
        @file_get_contents(
            $url,
            false,
            $context
        );

    $status = 0;

    foreach (
        ($http_response_header ?? []) as $header
    ) {

        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d+)/i',
                $header,
                $matches
            )
        ) {

            $status =
                (int)$matches[1];

            break;
        }
    }

    return [
        'status' => $status,
        'body' =>
            $response === false
                ? ''
                : $response,
    ];
}


function normalize_kintone_host(
    string $value
): string {

    $value =
        trim($value);

    if ($value === '') {
        return '';
    }

    $value =
        preg_replace(
            '#^https?://#i',
            '',
            $value
        );

    $value =
        trim(
            (string)$value,
            '/'
        );

    if (
        str_ends_with(
            $value,
            '.cybozu.com'
        )
    ) {
        return $value;
    }

    return $value .
        '.cybozu.com';
}


function kintone_error_message(
    array $result
): string {

    $data =
        json_decode(
            (string)(
                $result['body'] ?? ''
            ),
            true
        );

    if (
        is_array($data)
        && isset($data['message'])
    ) {

        return ' ' .
            (string)$data['message'];
    }

    return '';
}


function map_kintone_customer(
    array $record,
    array $mapping
): array {

    $get = static function (
        string $code
    ) use ($record): string {

        $value =
            $record[$code]['value'] ?? '';

        if (is_array($value)) {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE
            ) ?: '';
        }

        return (string)$value;
    };

    $addressValues = [];

    foreach (
        ($mapping['address'] ?? []) as $code
    ) {

        $value =
            $get((string)$code);

        if ($value !== '') {
            $addressValues[] =
                $value;
        }
    }

    return [
        'id' =>
            uuid(),

        'organization' =>
            $get(
                (string)(
                    $mapping['organization'] ?? ''
                )
            ),

        'name' =>
            $get(
                (string)(
                    $mapping['name'] ?? ''
                )
            ),

        'email' =>
            $get(
                (string)(
                    $mapping['email'] ?? ''
                )
            ),

        'department' =>
            $get(
                (string)(
                    $mapping['department'] ?? ''
                )
            ),

        'phone' =>
            $get(
                (string)(
                    $mapping['phone'] ?? ''
                )
            ),

        'address' =>
            implode(
                ' ',
                $addressValues
            ),
    ];
}


/*
 * =========================================================
 * SMTP
 * =========================================================
 */

function smtp_test_connection(
    array $m
): void {

    $transport =
        smtp_transport($m);

    $socket =
        @stream_socket_client(
            $transport,
            $errno,
            $errstr,
            CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

    if ($socket === false) {

        throw new RuntimeException(
            'SMTPサーバーへ接続できませんでした。' .
            (
                $errstr !== ''
                    ? ' ' . $errstr
                    : ''
            )
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    smtp_expect(
        $socket,
        220
    );

    smtp_command(
        $socket,
        'EHLO ' .
        gethostname(),
        250
    );

    if (
        ($m['encryption'] ?? '') === 'tls'
    ) {

        smtp_command(
            $socket,
            'STARTTLS',
            220
        );

        if (
            !stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        ) {

            fclose($socket);

            throw new RuntimeException(
                'STARTTLSを開始できませんでした。'
            );
        }

        smtp_command(
            $socket,
            'EHLO ' .
            gethostname(),
            250
        );
    }

    if (!empty($m['auth'])) {

        smtp_authenticate(
            $socket,
            $m
        );
    }

    smtp_command(
        $socket,
        'QUIT',
        221
    );

    fclose($socket);
}


function smtp_transport(
    array $m
): string {

    $host =
        trim((string)$m['host']);

    $port =
        (int)$m['port'];

    $encryption =
        (string)$m['encryption'];

    if ($encryption === 'ssl') {

        return 'ssl://' .
            $host .
            ':' .
            $port;
    }

    return 'tcp://' .
        $host .
        ':' .
        $port;
}


function smtp_authenticate(
    $socket,
    array $m
): void {

    smtp_command(
        $socket,
        'AUTH LOGIN',
        334
    );

    smtp_command(
        $socket,
        base64_encode(
            (string)$m['username']
        ),
        334
    );

    smtp_command(
        $socket,
        base64_encode(
            (string)$m['password']
        ),
        235
    );
}


function smtp_command(
    $socket,
    string $command,
    int $expected
): void {

    fwrite(
        $socket,
        $command . "\r\n"
    );

    smtp_expect(
        $socket,
        $expected
    );
}


function smtp_expect(
    $socket,
    int $expected
): string {

    $response = '';

    while (
        ($line = fgets(
            $socket,
            4096
        )) !== false
    ) {

        $response .=
            $line;

        if (
            preg_match(
                '/^(\d{3})(?:\s|\r?\n)/',
                $line,
                $m
            )
        ) {

            $code =
                (int)$m[1];

            if ($code !== $expected) {

                throw new RuntimeException(
                    'SMTPエラー: ' .
                    trim($response)
                );
            }

            return $response;
        }
    }

    throw new RuntimeException(
        'SMTPサーバーから応答がありません。'
    );
}


/*
 * =========================================================
 * Mail sending
 * =========================================================
 */

function handle_send_test_mail(): void
{
    $settings =
        read_json(SETTINGS_FILE);

    $m =
        $settings['mail'];

    validate_mail_settings($m);

    $to =
        trim((string)(
            $_POST['test_to'] ?? ''
        ));

    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        throw new InvalidArgumentException(
            'テスト送信先メールアドレスが不正です。'
        );
    }

    smtp_send(
        $m,
        $to,
        'アンケートアプリ テストメール',
        'SMTP接続およびメール送信のテストです。'
    );

    flash(
        'success',
        'テストメールを送信しました。'
    );

    redirect(
        'index.php?screen=mail'
    );
}


function smtp_send(
    array $m,
    string $to,
    string $subject,
    string $body
): void {

    /*
     * POCではSMTPを直接使用。
     * PHP mail()は使用しない。
     */

    $transport =
        smtp_transport($m);

    $socket =
        @stream_socket_client(
            $transport,
            $errno,
            $errstr,
            CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

    if ($socket === false) {

        throw new RuntimeException(
            'SMTP接続に失敗しました。' .
            (
                $errstr !== ''
                    ? ' ' . $errstr
                    : ''
            )
        );
    }

    stream_set_timeout(
        $socket,
        READ_TIMEOUT
    );

    smtp_expect(
        $socket,
        220
    );

    smtp_command(
        $socket,
        'EHLO ' .
        gethostname(),
        250
    );

    if (
        ($m['encryption'] ?? '') === 'tls'
    ) {

        smtp_command(
            $socket,
            'STARTTLS',
            220
        );

        if (
            !stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
        ) {

            fclose($socket);

            throw new RuntimeException(
                'STARTTLSに失敗しました。'
            );
        }

        smtp_command(
            $socket,
            'EHLO ' .
            gethostname(),
            250
        );
    }

    if (!empty($m['auth'])) {

        smtp_authenticate(
            $socket,
            $m
        );
    }

    smtp_command(
        $socket,
        'MAIL FROM:<' .
        $m['from_email'] .
        '>',
        250
    );

    smtp_command(
        $socket,
        'RCPT TO:<' .
        $to .
        '>',
        250
    );

    smtp_command(
        $socket,
        'DATA',
        354
    );

    $headers = [];

    $headers[] =
        'From: ' .
        mb_encode_mimeheader(
            (string)(
                $m['from_name'] ?? ''
            ) .
            ' <' .
            $m['from_email'] .
            '>',
            'UTF-8'
        );

    $headers[] =
        'To: ' .
        $to;

    $headers[] =
        'Subject: ' .
        mb_encode_mimeheader(
            $subject,
            'UTF-8'
        );

    if (
        !empty($m['reply_to'])
    ) {

        $headers[] =
            'Reply-To: ' .
            $m['reply_to'];
    }

    $headers[] =
        'MIME-Version: 1.0';

    $headers[] =
        'Content-Type: text/plain; charset=UTF-8';

    $headers[] =
        'Content-Transfer-Encoding: 8bit';

    fwrite(
        $socket,
        implode(
            "\r\n",
            $headers
        ) .
        "\r\n\r\n" .
        $body .
        "\r\n.\r\n"
    );

    smtp_expect(
        $socket,
        250
    );

    smtp_command(
        $socket,
        'QUIT',
        221
    );

    fclose($socket);
}


/*
 * =========================================================
 * Render helpers
 * =========================================================
 */

function render_header(
    string $screen
): void {

    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>アンケートアプリ</title>';

    echo <<<HTML
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
*{box-sizing:border-box}
body{
 margin:0;
 background:#f8fafc;
 color:var(--text);
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
 "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}
header{
 background:#0f172a;
 color:#fff;
 padding:16px 24px;
}
header a{
 color:#fff;
 text-decoration:none;
 margin-right:18px;
}
main{
 max-width:1400px;
 margin:0 auto;
 padding:24px;
}
.card{
 background:#fff;
 border:1px solid var(--border);
 border-radius:10px;
 box-shadow:var(--shadow);
 padding:20px;
 margin-bottom:20px;
}
.form-grid{
 display:grid;
 grid-template-columns:220px minmax(0,1fr);
 gap:14px 20px;
 align-items:center;
}
input,select,textarea{
 width:100%;
 padding:10px;
 border:1px solid var(--border);
 border-radius:6px;
 font:inherit;
}
textarea{min-height:120px}
button,.button{
 border:0;
 border-radius:6px;
 padding:10px 16px;
 cursor:pointer;
 text-decoration:none;
 display:inline-block;
 font:inherit;
}
.primary{background:var(--primary);color:#fff}
.success{background:var(--success);color:#fff}
.danger{background:var(--danger);color:#fff}
.secondary{background:#e2e8f0;color:#1e293b}
.actions{
 display:flex;
 gap:8px;
 flex-wrap:wrap;
 margin-top:18px;
}
.alert{
 padding:14px 16px;
 border-radius:8px;
 margin-bottom:18px;
}
.alert.success{
 background:#dcfce7;
 color:#166534;
}
.alert.error{
 background:#fee2e2;
 color:#991b1b;
}
.table-wrap{
 overflow-x:auto;
}
table{
 width:100%;
 border-collapse:collapse;
 min-width:900px;
}
th,td{
 padding:10px;
 border-bottom:1px solid var(--border);
 text-align:left;
 vertical-align:top;
}
.small{
 color:var(--gray);
 font-size:.9em;
}
.status{
 display:inline-block;
 padding:4px 9px;
 border-radius:999px;
 background:#e2e8f0;
}
.status.published{
 background:#dcfce7;
 color:#166534;
}
.status.stopped{
 background:#fef3c7;
 color:#92400e;
}
.status.ended{
 background:#e2e8f0;
 color:#374151;
}
.choice{
 display:block;
 padding:10px;
 margin:6px 0;
 border:1px solid var(--border);
 border-radius:6px;
}
.inline-form{
 display:inline;
}
.inline-form button{
 margin:0 2px;
}
@media(max-width:700px){
 .form-grid{
  grid-template-columns:1fr;
 }
 main{
  padding:14px;
 }
 table{
  min-width:900px;
 }
 header{
  overflow-x:auto;
  white-space:nowrap;
 }
}
</style>
HTML;

    echo '</head>';
    echo '<body>';

    echo '<header>';
    echo '<a href="index.php?screen=list">アンケート一覧</a>';
    echo '<a href="index.php?screen=kintone">kintone設定</a>';
    echo '<a href="index.php?screen=mail">メール設定</a>';
    echo '</header>';

    echo '<main>';

    render_flash();
}


function render_footer(): void
{
    echo '</main>';

    echo <<<HTML
<script>
document.querySelectorAll('form[data-busy]').forEach(function(form){
    form.addEventListener('submit',function(){
        var button=form.querySelector('button[type="submit"],button:not([type])');
        if(button){
            button.disabled=true;
            button.dataset.originalText=button.textContent;
            button.textContent='処理中...';
        }
    });
});

document.querySelectorAll('form[data-confirm]').forEach(function(form){
    form.addEventListener('submit',function(e){
        if(!window.confirm(form.dataset.confirm)){
            e.preventDefault();
        }
    });
});
</script>
HTML;

    echo '</body>';
    echo '</html>';
}


function render_flash(): void
{
    $messages =
        $_SESSION['_flash'] ?? [];

    unset(
        $_SESSION['_flash']
    );

    foreach ($messages as $message) {

        echo '<div class="alert ' .
            h((string)(
                $message['type'] ?? 'error'
            )) .
            '">' .
            h((string)(
                $message['message'] ?? ''
            )) .
            '</div>';
    }
}


function form_row(
    string $label,
    string $html
): void {

    echo '<label>' .
        h($label) .
        '</label>';

    echo '<div>' .
        $html .
        '</div>';
}


function option(
    string $value,
    string $label,
    string $selected
): string {

    return '<option value="' .
        h($value) .
        '"' .
        (
            $value === $selected
                ? ' selected'
                : ''
        ) .
        '>' .
        h($label) .
        '</option>';
}


function status_badge(
    string $status
): string {

    $labels = [
        'draft' =>
            '下書き',

        'published' =>
            '公開中',

        'stopped' =>
            '停止',

        'ended' =>
            '終了',
    ];

    return '<span class="status ' .
        h($status) .
        '">' .
        h(
            $labels[$status]
            ?? $status
        ) .
        '</span>';
}


function status_text(
    string $status
): string {

    return h($status);
}


/*
 * =========================================================
 * Survey utilities
 * =========================================================
 */

function find_survey(
    string $id
): ?array {

    if ($id === '') {
        return null;
    }

    foreach (
        read_json(SURVEYS_FILE) as $survey
    ) {

        if (
            (string)(
                $survey['id'] ?? ''
            ) === $id
        ) {
            return $survey;
        }
    }

    return null;
}


function answer_count_for_survey(
    string $surveyId
): int {

    $count = 0;

    foreach (
        read_json(ANSWERS_FILE) as $answer
    ) {

        if (
            (string)(
                $answer['surveyId'] ?? ''
            ) === $surveyId
        ) {
            $count++;
        }
    }

    return $count;
}


function recalculate_question_numbers(
    array &$survey
): void {

    $numbering =
        (string)(
            $survey['numbering'] ?? 'global'
        );

    $global = 1;

    foreach (
        ($survey['groups'] ?? []) as $groupIndex => &$group
    ) {

        $local = 1;

        foreach (
            ($group['questions'] ?? []) as &$question
        ) {

            if ($numbering === 'group') {

                $question['number'] =
                    'Q' .
                    ($groupIndex + 1) .
                    '-' .
                    $local;

            } else {

                $question['number'] =
                    'Q' .
                    $global;
            }

            $global++;
            $local++;
        }

        unset($question);
    }

    unset($group);
}


function update_expired_surveys(): void
{
    $surveys =
        read_json(SURVEYS_FILE);

    $changed = false;

    foreach ($surveys as &$survey) {

        if (
            (string)(
                $survey['status'] ?? ''
            ) !== 'published'
        ) {
            continue;
        }

        $endAt =
            (string)(
                $survey['endAt'] ?? ''
            );

        if ($endAt === '') {
            continue;
        }

        $timestamp =
            strtotime($endAt);

        if (
            $timestamp !== false
            && $timestamp < time()
        ) {

            $survey['status'] =
                'ended';

            $survey['updatedAt'] =
                now_iso();

            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {

        write_json_atomic(
            SURVEYS_FILE,
            $surveys
        );
    }
}


/*
 * =========================================================
 * General helpers
 * =========================================================
 */

function now_iso(): string
{
    return date('c');
}


function uuid(): string
{
    return bin2hex(
        random_bytes(16)
    );
}


function h(
    mixed $value
): string {

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES |
        ENT_SUBSTITUTE,
        'UTF-8'
    );
}


function datetime_local(
    string $value
): string {

    if ($value === '') {
        return '';
    }

    $timestamp =
        strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date(
        'Y-m-d\TH:i',
        $timestamp
    );
}


function normalize_datetime(
    string $value
): ?string {

    $value =
        trim($value);

    if ($value === '') {
        return null;
    }

    $timestamp =
        strtotime($value);

    if ($timestamp === false) {

        throw new InvalidArgumentException(
            '日時の形式が不正です。'
        );
    }

    return date(
        'c',
        $timestamp
    );
}


function format_datetime(
    string $value
): string {

    if ($value === '') {
        return '';
    }

    $timestamp =
        strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date(
        'Y/m/d H:i',
        $timestamp
    );
}


function format_period(
    array $survey
): string {

    $start =
        format_datetime(
            (string)(
                $survey['startAt'] ?? ''
            )
        );

    $end =
        format_datetime(
            (string)(
                $survey['endAt'] ?? ''
            )
        );

    if (
        $start === ''
        && $end === ''
    ) {

        return '指定なし';
    }

    return
        $start .
        ' ～ ' .
        $end;
}


function screen_url(
    string $screen
): string {

    return
        'index.php?screen=' .
        rawurlencode($screen);
}


function redirect(
    string $url
): never {

    header(
        'Location: ' .
        $url,
        true,
        303
    );

    exit;
}


function flash(
    string $type,
    string $message
): void {

    $_SESSION['_flash'][] = [
        'type' =>
            $type,

        'message' =>
            $message,
    ];
}


function user_error_message(
    Throwable $e
): string {

    /*
     * 機密情報をエラーメッセージへ出さない。
     *
     * POCなので外部サービスが返す
     * 一般的なエラー情報は表示する。
     */

    $message =
        trim(
            $e->getMessage()
        );

    return $message !== ''
        ? ' ' . $message
        : '';
}


/*
 * =========================================================
 * CSV
 * =========================================================
 */

function handle_export_csv(): void
{
    $surveyId =
        trim((string)(
            $_GET['id'] ?? ''
        ));

    $survey =
        find_survey($surveyId);

    if ($survey === null) {

        http_response_code(404);

        exit(
            'アンケートが存在しません。'
        );
    }

    $answers =
        read_json(ANSWERS_FILE);

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="answers.csv"'
    );

    $fp =
        fopen(
            'php://output',
            'wb'
        );

    if ($fp === false) {
        exit;
    }

    fwrite(
        $fp,
        "\xEF\xBB\xBF"
    );

    $header = [
        '回答ID',
        '回答日時',
    ];

    foreach (
        ($survey['groups'] ?? []) as $group
    ) {

        foreach (
            ($group['questions'] ?? []) as $question
        ) {

            $header[] =
                (string)(
                    $question['number'] ?? ''
                ) .
                ' ' .
                (string)(
                    $question['text'] ?? ''
                );
        }
    }

    fputcsv(
        $fp,
        $header
    );

    foreach ($answers as $answer) {

        if (
            (string)(
                $answer['surveyId'] ?? ''
            ) !== $surveyId
        ) {
            continue;
        }

        $row = [
            (string)(
                $answer['id'] ?? ''
            ),
            (string)(
                $answer['createdAt'] ?? ''
            ),
        ];

        foreach (
            ($survey['groups'] ?? []) as $group
        ) {

            foreach (
                ($group['questions'] ?? []) as $question
            ) {

                $qid =
                    (string)(
                        $question['id'] ?? ''
                    );

                $value =
                    $answer['answers'][$qid]
                    ?? '';

                if (is_array($value)) {

                    $value =
                        implode(
                            ', ',
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

        fputcsv(
            $fp,
            $row
        );
    }

    fclose($fp);
    exit;
}


/*
 * =========================================================
 * Additional mail operations
 * =========================================================
 */

function handle_send_mail(): void
{
    $surveyId =
        trim((string)(
            $_POST['surveyId'] ?? ''
        ));

    $survey =
        find_survey($surveyId);

    if ($survey === null) {

        throw new RuntimeException(
            '対象アンケートが存在しません。'
        );
    }

    $customerIds =
        $_POST['customer_ids'] ?? [];

    if (!is_array($customerIds)) {
        $customerIds = [];
    }

    if (count($customerIds) === 0) {

        throw new InvalidArgumentException(
            '送信対象の顧客を選択してください。'
        );
    }

    $subject =
        trim((string)(
            $_POST['subject'] ?? ''
        ));

    $body =
        (string)(
            $_POST['body'] ?? ''
        );

    if ($subject === '') {

        throw new InvalidArgumentException(
            'メール件名を入力してください。'
        );
    }

    if (trim($body) === '') {

        throw new InvalidArgumentException(
            'メール本文を入力してください。'
        );
    }

    $settings =
        read_json(SETTINGS_FILE);

    validate_mail_settings(
        $settings['mail']
    );

    $customers =
        read_json(CUSTOMERS_FILE);

    $logs =
        read_json(SEND_LOG_FILE);

    $count = 0;

    foreach ($customers as $customer) {

        $customerId =
            (string)(
                $customer['id'] ?? ''
            );

        if (
            !in_array(
                $customerId,
                array_map(
                    'strval',
                    $customerIds
                ),
                true
            )
        ) {
            continue;
        }

        $to =
            trim((string)(
                $customer['email'] ?? ''
            ));

        if (
            !filter_var(
                $to,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $logs[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'email' => $to,
                'status' => 'failed',
                'message' =>
                    'メールアドレスが不正です。',
                'createdAt' => now_iso(),
            ];

            continue;
        }

        $customerName =
            (string)(
                $customer['name'] ?? ''
            );

        $url =
            survey_answer_url(
                $surveyId
            );

        $actualSubject =
            str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    $customerName,
                    $url,
                ],
                $subject
            );

        $actualBody =
            str_replace(
                [
                    '{顧客名}',
                    '{アンケートURL}',
                ],
                [
                    $customerName,
                    $url,
                ],
                $body
            );

        try {

            smtp_send(
                $settings['mail'],
                $to,
                $actualSubject,
                $actualBody
            );

            $logs[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'email' => $to,
                'status' => 'sent',
                'message' => '送信成功',
                'createdAt' => now_iso(),
            ];

            $count++;

        } catch (Throwable $e) {

            $logs[] = [
                'id' => uuid(),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'email' => $to,
                'status' => 'failed',
                'message' =>
                    user_error_message($e),
                'createdAt' => now_iso(),
            ];
        }
    }

    write_json_atomic(
        SEND_LOG_FILE,
        $logs
    );

    flash(
        'success',
        $count .
        '件のメールを送信しました。'
    );

    /*
     * 要件:
     *
     * 送信後は別画面へ遷移せず、
     * 同じ送信画面に結果を表示する。
     */
    redirect(
        'index.php?screen=send&id=' .
        rawurlencode($surveyId)
    );
}


function handle_resend_mail(): void
{
    handle_send_mail();
}


function handle_remind_mail(): void
{
    handle_send_mail();
}


function survey_answer_url(
    string $id
): string {

    $scheme =
        (
            !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off'
        )
            ? 'https'
            : 'http';

    $host =
        (string)(
            $_SERVER['HTTP_HOST']
            ?? 'localhost'
        );

    return
        $scheme .
        '://' .
        $host .
        '/index.php?screen=answer&id=' .
        rawurlencode($id);
}


/*
 * =========================================================
 * End
 * =========================================================
 */
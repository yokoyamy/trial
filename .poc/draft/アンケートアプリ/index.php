<?php
declare(strict_types=1);

/*
 * ============================================================
 * アンケート管理システム
 * sample-app / Phase 3
 * ============================================================
 *
 * HTTP入口：index.php
 *
 * GET
 *   → 画面表示
 *
 * POST
 *   → データ変更
 *
 * 永続化
 *   → JSON
 *
 * fetch()
 *   → 使用しない
 *
 * apiCall()
 *   → 使用しない
 *
 * SQLite
 *   → 使用しない
 *
 * ============================================================
 */

date_default_timezone_set('Asia/Tokyo');


/* ============================================================
 * パス
 * ============================================================
 */

$APP_DIR  = __DIR__;
$DATA_DIR = $APP_DIR . DIRECTORY_SEPARATOR . 'data';

if (!is_dir($DATA_DIR)) {
    if (!mkdir($DATA_DIR, 0775, true) && !is_dir($DATA_DIR)) {
        http_response_code(500);
        exit('dataディレクトリを作成できません。');
    }
}

$SURVEYS_FILE   = $DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
$QUESTIONS_FILE = $DATA_DIR . DIRECTORY_SEPARATOR . 'questions.json';


/* ============================================================
 * 共通関数
 * ============================================================
 */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


function postString(
    string $key,
    string $default = ''
): string {
    if (!isset($_POST[$key])) {
        return $default;
    }

    return is_string($_POST[$key])
        ? $_POST[$key]
        : $default;
}


function createId(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}


function loadJson(
    string $file,
    array $default = []
): array {

    if (!is_file($file)) {
        return $default;
    }

    $content = file_get_contents($file);

    if ($content === false) {
        throw new RuntimeException(
            'JSONファイルを読み込めません。'
        );
    }

    if (trim($content) === '') {
        throw new RuntimeException(
            'JSONファイルが空です。'
        );
    }

    try {

        $data = json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

    } catch (JsonException) {

        throw new RuntimeException(
            'JSONファイルが不正です。'
        );
    }

    if (!is_array($data)) {
        throw new RuntimeException(
            'JSONの構造が不正です。'
        );
    }

    return $data;
}


function saveJson(
    string $file,
    array $data
): void {

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

    $tmp =
        $file .
        '.' .
        bin2hex(random_bytes(6)) .
        '.tmp';

    if (
        file_put_contents(
            $tmp,
            $json,
            LOCK_EX
        ) === false
    ) {

        throw new RuntimeException(
            'JSONファイルの一時保存に失敗しました。'
        );
    }

    if (!rename($tmp, $file)) {

        @unlink($tmp);

        throw new RuntimeException(
            'JSONファイルの置換に失敗しました。'
        );
    }
}


function redirectTo(
    array $params = []
): never {

    $base =
        strtok(
            $_SERVER['REQUEST_URI'] ?? '',
            '?'
        );

    if ($base === false || $base === '') {
        $base = './';
    }

    $query =
        http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

    $url =
        $base .
        ($query !== '' ? '?' . $query : '');

    header(
        'Location: ' . $url,
        true,
        303
    );

    exit;
}


function findSurvey(
    array $surveys,
    string $surveyId
): ?array {

    foreach ($surveys as $survey) {

        if (
            is_array($survey) &&
            ($survey['surveyId'] ?? '') === $surveyId
        ) {
            return $survey;
        }
    }

    return null;
}


function findQuestion(
    array $questions,
    string $questionId
): ?array {

    foreach ($questions as $question) {

        if (
            is_array($question) &&
            ($question['questionId'] ?? '') === $questionId
        ) {
            return $question;
        }
    }

    return null;
}


function questionTypeLabel(
    string $type
): string {

    return match ($type) {

        'text' =>
            '自由入力',

        'single' =>
            '単一選択',

        'multiple' =>
            '複数選択',

        default =>
            '不明',
    };
}


/* ============================================================
 * 初期データ
 * ============================================================
 */

if (!is_file($SURVEYS_FILE)) {

    saveJson(
        $SURVEYS_FILE,
        [
            [
                'surveyId'  => 'survey_demo',
                'title'     => 'サンプルアンケート',
                'status'    => 'draft',
                'endAt'     => null,
                'createdAt' => date('c'),
                'updatedAt' => date('c'),
            ]
        ]
    );
}


if (!is_file($QUESTIONS_FILE)) {

    saveJson(
        $QUESTIONS_FILE,
        [
            [
                'questionId' => 'question_demo_001',
                'surveyId'   => 'survey_demo',
                'text'       => 'このサービスに満足しましたか？',
                'type'       => 'single',
                'required'   => true,
                'sortOrder'  => 1,
                'choices'    => [
                    [
                        'choiceId' => 'choice_demo_001',
                        'label'    => '非常に満足',
                    ],
                    [
                        'choiceId' => 'choice_demo_002',
                        'label'    => '満足',
                    ],
                    [
                        'choiceId' => 'choice_demo_003',
                        'label'    => '普通',
                    ],
                    [
                        'choiceId' => 'choice_demo_004',
                        'label'    => '不満',
                    ],
                ],
            ],
        ]
    );
}


/* ============================================================
 * POST処理
 * ============================================================
 */

$error = '';
$message = '';

try {

    $surveys =
        loadJson(
            $SURVEYS_FILE
        );

    $questions =
        loadJson(
            $QUESTIONS_FILE
        );


    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $action =
            postString('action');


        /* ====================================================
         * アンケート追加
         * ====================================================
         */

        if ($action === 'survey_add') {

            $title =
                trim(
                    postString('title')
                );

            if ($title === '') {
                throw new RuntimeException(
                    'アンケート名を入力してください。'
                );
            }

            $now = date('c');

            $surveys[] = [
                'surveyId' =>
                    createId('survey'),

                'title' =>
                    $title,

                'status' =>
                    'draft',

                'endAt' =>
                    null,

                'createdAt' =>
                    $now,

                'updatedAt' =>
                    $now,
            ];

            saveJson(
                $SURVEYS_FILE,
                $surveys
            );

            redirectTo([
                'screen'  => 'admin',
                'message' => 'アンケートを作成しました。',
            ]);
        }


        /* ====================================================
         * アンケート編集
         * ====================================================
         */

        if ($action === 'survey_save') {

            $surveyId =
                postString('surveyId');

            $title =
                trim(
                    postString('title')
                );

            if ($surveyId === '') {
                throw new RuntimeException(
                    'surveyIdがありません。'
                );
            }

            if ($title === '') {
                throw new RuntimeException(
                    'アンケート名を入力してください。'
                );
            }

            $found = false;

            foreach ($surveys as &$survey) {

                if (
                    ($survey['surveyId'] ?? '')
                    === $surveyId
                ) {

                    $survey['title'] =
                        $title;

                    $survey['updatedAt'] =
                        date('c');

                    $found = true;

                    break;
                }
            }

            unset($survey);

            if (!$found) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            saveJson(
                $SURVEYS_FILE,
                $surveys
            );

            redirectTo([
                'screen'   => 'survey',
                'surveyId' => $surveyId,
                'message'  => 'アンケートを保存しました。',
            ]);
        }


        /* ====================================================
         * アンケート状態変更
         * ====================================================
         */

        if ($action === 'survey_status') {

            $surveyId =
                postString('surveyId');

            $newStatus =
                postString('status');

            $allowed =
                [
                    'draft',
                    'published',
                    'stopped',
                    'ended',
                ];

            if (!in_array(
                $newStatus,
                $allowed,
                true
            )) {
                throw new RuntimeException(
                    '不正な状態です。'
                );
            }

            $found = false;

            foreach ($surveys as &$survey) {

                if (
                    ($survey['surveyId'] ?? '')
                    !== $surveyId
                ) {
                    continue;
                }

                $current =
                    $survey['status'] ?? 'draft';


                /*
                 * 状態遷移
                 */

                $valid = false;

                if (
                    $current === 'draft' &&
                    $newStatus === 'published'
                ) {
                    $valid = true;
                }

                if (
                    $current === 'published' &&
                    $newStatus === 'stopped'
                ) {
                    $valid = true;
                }

                if (
                    $current === 'stopped' &&
                    $newStatus === 'published'
                ) {
                    $valid = true;
                }

                if (
                    $current === 'published' &&
                    $newStatus === 'ended'
                ) {
                    $valid = true;
                }


                if (!$valid) {

                    throw new RuntimeException(
                        '許可されていない状態遷移です。'
                    );
                }


                $survey['status'] =
                    $newStatus;

                $survey['updatedAt'] =
                    date('c');

                $found = true;

                break;
            }

            unset($survey);

            if (!$found) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }

            saveJson(
                $SURVEYS_FILE,
                $surveys
            );

            redirectTo([
                'screen'   => 'survey',
                'surveyId' => $surveyId,
                'message'  => '状態を変更しました。',
            ]);
        }


        /* ====================================================
         * 質問追加
         * ====================================================
         */

        if ($action === 'question_add') {

            $surveyId =
                postString('surveyId');

            $text =
                trim(
                    postString('text')
                );

            $type =
                postString('type');

            $required =
                isset($_POST['required']);


            if (
                findSurvey(
                    $surveys,
                    $surveyId
                ) === null
            ) {
                throw new RuntimeException(
                    '対象アンケートが存在しません。'
                );
            }


            if ($text === '') {
                throw new RuntimeException(
                    '質問文を入力してください。'
                );
            }


            if (
                !in_array(
                    $type,
                    ['text', 'single', 'multiple'],
                    true
                )
            ) {
                throw new RuntimeException(
                    '質問形式が不正です。'
                );
            }


            $maxOrder = 0;

            foreach ($questions as $question) {

                if (
                    ($question['surveyId'] ?? '')
                    === $surveyId
                ) {

                    $maxOrder =
                        max(
                            $maxOrder,
                            (int)(
                                $question['sortOrder']
                                ?? 0
                            )
                        );
                }
            }


            $question = [

                'questionId' =>
                    createId('question'),

                'surveyId' =>
                    $surveyId,

                'text' =>
                    $text,

                'type' =>
                    $type,

                'required' =>
                    $required,

                'sortOrder' =>
                    $maxOrder + 1,

                'choices' =>
                    [],
            ];


            /*
             * 選択式の場合
             */
            if (
                $type === 'single' ||
                $type === 'multiple'
            ) {

                $choicesText =
                    trim(
                        postString('choices')
                    );


                if ($choicesText !== '') {

                    $lines =
                        preg_split(
                            '/\r\n|\r|\n/',
                            $choicesText
                        );


                    foreach ($lines as $line) {

                        $line =
                            trim($line);

                        if ($line === '') {
                            continue;
                        }

                        $question['choices'][] = [

                            'choiceId' =>
                                createId('choice'),

                            'label' =>
                                $line,
                        ];
                    }
                }
            }


            $questions[] =
                $question;


            saveJson(
                $QUESTIONS_FILE,
                $questions
            );


            redirectTo([
                'screen'   => 'survey',
                'surveyId' => $surveyId,
                'message'  => '質問を追加しました。',
            ]);
        }


        /* ====================================================
         * 質問編集
         * ====================================================
         */

        if ($action === 'question_save') {

            $questionId =
                postString('questionId');

            $text =
                trim(
                    postString('text')
                );

            $type =
                postString('type');

            $required =
                isset($_POST['required']);


            if ($questionId === '') {
                throw new RuntimeException(
                    'questionIdがありません。'
                );
            }

            if ($text === '') {
                throw new RuntimeException(
                    '質問文を入力してください。'
                );
            }

            if (
                !in_array(
                    $type,
                    ['text', 'single', 'multiple'],
                    true
                )
            ) {
                throw new RuntimeException(
                    '質問形式が不正です。'
                );
            }


            $surveyId = '';


            foreach ($questions as &$question) {

                if (
                    ($question['questionId'] ?? '')
                    !== $questionId
                ) {
                    continue;
                }


                $surveyId =
                    $question['surveyId'] ?? '';


                $question['text'] =
                    $text;

                $question['type'] =
                    $type;

                $question['required'] =
                    $required;


                /*
                 * 選択肢
                 */
                $question['choices'] =
                    [];


                if (
                    $type === 'single' ||
                    $type === 'multiple'
                ) {

                    $choicesText =
                        trim(
                            postString('choices')
                        );


                    if ($choicesText !== '') {

                        $lines =
                            preg_split(
                                '/\r\n|\r|\n/',
                                $choicesText
                            );


                        foreach ($lines as $line) {

                            $line =
                                trim($line);

                            if ($line === '') {
                                continue;
                            }


                            $question['choices'][] = [

                                'choiceId' =>
                                    createId('choice'),

                                'label' =>
                                    $line,
                            ];
                        }
                    }
                }


                break;
            }

            unset($question);


            if ($surveyId === '') {

                throw new RuntimeException(
                    '対象質問が存在しません。'
                );
            }


            saveJson(
                $QUESTIONS_FILE,
                $questions
            );


            redirectTo([
                'screen'   => 'survey',
                'surveyId' => $surveyId,
                'message'  => '質問を保存しました。',
            ]);
        }


        /* ====================================================
         * 質問削除
         * ====================================================
         */

        if ($action === 'question_delete') {

            $questionId =
                postString('questionId');


            $surveyId = '';

            $found = false;


            foreach ($questions as $question) {

                if (
                    ($question['questionId'] ?? '')
                    === $questionId
                ) {

                    $surveyId =
                        $question['surveyId'] ?? '';

                    $found = true;

                    break;
                }
            }


            if (!$found) {

                throw new RuntimeException(
                    '対象質問が存在しません。'
                );
            }


            $questions =
                array_values(
                    array_filter(
                        $questions,
                        static function (
                            array $question
                        ) use ($questionId): bool {

                            return
                                ($question['questionId'] ?? '')
                                !== $questionId;
                        }
                    )
                );


            /*
             * 並び順を再計算
             */
            $order = 1;

            foreach ($questions as &$question) {

                if (
                    ($question['surveyId'] ?? '')
                    !== $surveyId
                ) {
                    continue;
                }

                $question['sortOrder'] =
                    $order++;

            }

            unset($question);


            saveJson(
                $QUESTIONS_FILE,
                $questions
            );


            redirectTo([
                'screen'   => 'survey',
                'surveyId' => $surveyId,
                'message'  => '質問を削除しました。',
            ]);
        }


        /*
         * 不正action
         */
        throw new RuntimeException(
            '不正な操作です。'
        );
    }


} catch (Throwable $e) {

    /*
     * ここでは画面に安全なエラーを表示する。
     */

    $error =
        $e->getMessage();


    $surveys =
        $surveys
        ?? loadJson(
            $SURVEYS_FILE,
            []
        );

    $questions =
        $questions
        ?? loadJson(
            $QUESTIONS_FILE,
            []
        );
}


/* ============================================================
 * GET画面状態
 * ============================================================
 */

$screen =
    isset($_GET['screen']) &&
    is_string($_GET['screen'])
        ? $_GET['screen']
        : 'admin';


$surveyId =
    isset($_GET['surveyId']) &&
    is_string($_GET['surveyId'])
        ? $_GET['surveyId']
        : '';


$message =
    isset($_GET['message']) &&
    is_string($_GET['message'])
        ? $_GET['message']
        : '';


/* ============================================================
 * 表示用データ
 * ============================================================
 */

$currentSurvey = null;

if ($surveyId !== '') {

    $currentSurvey =
        findSurvey(
            $surveys,
            $surveyId
        );
}


$currentQuestions = [];

if ($surveyId !== '') {

    foreach ($questions as $question) {

        if (
            ($question['surveyId'] ?? '')
            === $surveyId
        ) {

            $currentQuestions[] =
                $question;
        }
    }


    usort(
        $currentQuestions,
        static function (
            array $a,
            array $b
        ): int {

            return
                ((int)(
                    $a['sortOrder'] ?? 0
                ))
                <=>
                ((int)(
                    $b['sortOrder'] ?? 0
                ));
        }
    );
}


/* ============================================================
 * 画面
 * ============================================================
 */

?>
<!DOCTYPE html>

<html lang="ja">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>アンケート管理システム</title>


<style>

* {
    box-sizing: border-box;
}

body {

    margin: 0;

    background: #f3f4f6;

    color: #111827;

    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

header {

    background: #111827;

    color: white;

    padding: 18px 24px;
}

header .inner {

    max-width: 1200px;

    margin: auto;
}

main {

    max-width: 1200px;

    margin: 25px auto;

    padding: 0 20px;
}

.card {

    background: white;

    border: 1px solid #d1d5db;

    border-radius: 8px;

    padding: 20px;

    margin-bottom: 20px;
}

h1,
h2,
h3 {

    margin-top: 0;
}

input,
textarea,
select {

    width: 100%;

    padding: 10px;

    border: 1px solid #9ca3af;

    border-radius: 5px;

    font-size: 15px;
}

textarea {

    min-height: 100px;

    resize: vertical;
}

button,
.button {

    display: inline-block;

    border: 0;

    border-radius: 5px;

    padding: 9px 15px;

    background: #2563eb;

    color: white;

    text-decoration: none;

    cursor: pointer;

    font-size: 14px;
}

button:hover,
.button:hover {

    background: #1d4ed8;
}

.button.gray {

    background: #6b7280;
}

.button.green {

    background: #16a34a;
}

.button.orange {

    background: #ea580c;
}

.button.red {

    background: #dc2626;
}

button.red {

    background: #dc2626;
}

button.gray {

    background: #6b7280;
}

button.green {

    background: #16a34a;
}

button.orange {

    background: #ea580c;
}

.actions {

    display: flex;

    gap: 8px;

    flex-wrap: wrap;

    margin-top: 12px;
}

.form-grid {

    display: grid;

    gap: 15px;
}

.survey {

    border: 1px solid #d1d5db;

    border-radius: 7px;

    padding: 18px;

    margin-bottom: 12px;
}

.survey-title {

    font-size: 19px;

    font-weight: bold;
}

.meta {

    color: #6b7280;

    font-size: 13px;

    margin-top: 5px;
}

.status {

    display: inline-block;

    margin-top: 8px;

    padding: 4px 9px;

    border-radius: 20px;

    background: #e5e7eb;

    font-size: 12px;
}

.question {

    border: 1px solid #d1d5db;

    border-radius: 7px;

    padding: 18px;

    margin-bottom: 12px;
}

.question-number {

    color: #6b7280;

    font-size: 13px;
}

.question-text {

    font-size: 18px;

    font-weight: bold;

    margin: 5px 0 10px;
}

.choice {

    padding: 7px 10px;

    background: #f9fafb;

    border: 1px solid #e5e7eb;

    margin-bottom: 5px;

    border-radius: 4px;
}

.notice {

    padding: 12px 15px;

    background: #dcfce7;

    border: 1px solid #86efac;

    color: #166534;

    border-radius: 6px;

    margin-bottom: 20px;
}

.error {

    padding: 12px 15px;

    background: #fee2e2;

    border: 1px solid #fca5a5;

    color: #991b1b;

    border-radius: 6px;

    margin-bottom: 20px;
}

.back {

    margin-bottom: 15px;
}

.inline {

    display: inline;
}

.small {

    font-size: 13px;

    color: #6b7280;
}

@media (max-width: 700px) {

    main {
        padding: 0 10px;
    }

    .actions {
        flex-direction: column;
    }

    .actions > * {
        width: 100%;
    }
}

</style>

</head>


<body>


<header>

<div class="inner">

<strong>
    アンケート管理システム
</strong>

</div>

</header>


<main>


<?php if ($message !== ''): ?>

<div class="notice">

<?= h($message) ?>

</div>

<?php endif; ?>


<?php if ($error !== ''): ?>

<div class="error">

<?= h($error) ?>

</div>

<?php endif; ?>


<?php
/* ============================================================
 * ADMIN
 * ============================================================
 */

if ($screen === 'admin'):
?>


<div class="card">

<h1>
    アンケート一覧
</h1>

<p class="small">
    ここからアンケートを作成・編集します。
</p>

</div>


<div class="card">

<h2>
    新規アンケート
</h2>


<form
    method="post"
>

<input
    type="hidden"
    name="action"
    value="survey_add"
>


<div class="form-grid">

<label>

アンケート名

<input
    type="text"
    name="title"
    required
    placeholder="例：顧客満足度アンケート"
>

</label>


<button
    type="submit"
>

アンケートを作成

</button>

</div>

</form>

</div>


<div class="card">


<?php if (count($surveys) === 0): ?>

<p>
    アンケートがありません。
</p>

<?php endif; ?>


<?php foreach ($surveys as $survey): ?>

<div class="survey">


<div class="survey-title">

<?= h(
    $survey['title'] ?? ''
) ?>

</div>


<div class="meta">

ID:
<?= h(
    $survey['surveyId'] ?? ''
) ?>

</div>


<div class="status">

状態：
<?= h(
    $survey['status'] ?? ''
) ?>

</div>


<div class="actions">

<a
    class="button"
    href="?screen=survey&surveyId=<?= rawurlencode(
        (string)($survey['surveyId'] ?? '')
    ) ?>"
>
    管理
</a>


</div>


</div>

<?php endforeach; ?>


</div>


<?php
/* ============================================================
 * SURVEY
 * ============================================================
 */

elseif (
    $screen === 'survey'
):
?>


<?php if ($currentSurvey === null): ?>

<div class="error">

対象アンケートが存在しません。

</div>

<a
    class="button gray"
    href="?screen=admin"
>
    一覧へ戻る
</a>


<?php else: ?>


<div class="back">

<a
    class="button gray"
    href="?screen=admin"
>
    ← アンケート一覧
</a>

</div>


<div class="card">


<h1>

<?= h(
    $currentSurvey['title']
)

?>

</h1>


<div class="meta">

surveyId:

<?= h(
    $currentSurvey['surveyId']
) ?>

</div>


<div class="status">

状態：

<?= h(
    $currentSurvey['status']
) ?>

</div>


<div class="actions">


<?php
$currentStatus =
    $currentSurvey['status'];
?>


<?php if ($currentStatus === 'draft'): ?>

<form
    method="post"
    class="inline"
>

<input
    type="hidden"
    name="action"
    value="survey_status"
>

<input
    type="hidden"
    name="surveyId"
    value="<?= h($surveyId) ?>"
>

<input
    type="hidden"
    name="status"
    value="published"
>

<button
    type="submit"
    class="green"
>
    公開
</button>

</form>

<?php endif; ?>


<?php if ($currentStatus === 'published'): ?>

<form
    method="post"
    class="inline"
>

<input
    type="hidden"
    name="action"
    value="survey_status"
>

<input
    type="hidden"
    name="surveyId"
    value="<?= h($surveyId) ?>"
>

<input
    type="hidden"
    name="status"
    value="stopped"
>

<button
    type="submit"
    class="orange"
>
    停止
</button>

</form>

<?php endif; ?>


<?php if ($currentStatus === 'stopped'): ?>

<form
    method="post"
    class="inline"
>

<input
    type="hidden"
    name="action"
    value="survey_status"
>

<input
    type="hidden"
    name="surveyId"
    value="<?= h($surveyId) ?>"
>

<input
    type="hidden"
    name="status"
    value="published"
>

<button
    type="submit"
    class="green"
>
    再公開
</button>

</form>

<?php endif; ?>


</div>

</div>


<!-- ========================================================
     アンケート編集
     ========================================================
     -->

<div class="card">

<h2>
    アンケート設定
</h2>


<form
    method="post"
>

<input
    type="hidden"
    name="action"
    value="survey_save"
>


<input
    type="hidden"
    name="surveyId"
    value="<?= h($surveyId) ?>"
>


<div class="form-grid">


<label>

アンケート名

<input
    type="text"
    name="title"
    value="<?= h(
        $currentSurvey['title'] ?? ''
    ) ?>"
    required
>

</label>


<button
    type="submit"
>

保存

</button>


</div>

</form>

</div>


<!-- ========================================================
     質問追加
     ========================================================
     -->

<div class="card">

<h2>
    質問を追加
</h2>


<form
    method="post"
>

<input
    type="hidden"
    name="action"
    value="question_add"
>


<input
    type="hidden"
    name="surveyId"
    value="<?= h($surveyId) ?>"
>


<div class="form-grid">


<label>

質問文

<textarea
    name="text"
    required
    placeholder="質問を入力してください"
></textarea>

</label>


<label>

質問形式

<select
    name="type"
>

<option value="text">
    自由入力
</option>

<option value="single">
    単一選択
</option>

<option value="multiple">
    複数選択
</option>

</select>

</label>


<label>

選択肢

<textarea
    name="choices"
    placeholder="単一選択・複数選択の場合&#10;1行に1つ入力&#10;&#10;非常に満足&#10;満足&#10;普通&#10;不満"
></textarea>

</label>


<label>

<input
    type="checkbox"
    name="required"
    value="1"
    checked
>

必須回答

</label>


<button
    type="submit"
>

質問を追加

</button>


</div>

</form>

</div>


<!-- ========================================================
     質問一覧
     ========================================================
     -->

<div class="card">

<h2>
    質問一覧
</h2>


<?php if (
    count($currentQuestions) === 0
): ?>

<p>
    質問はまだありません。
</p>

<?php endif; ?>


<?php foreach (
    $currentQuestions
    as $index => $question
):
?>


<div class="question">


<div class="question-number">

Q<?= h(
    $index + 1
) ?>

・

<?= h(
    questionTypeLabel(
        (string)(
            $question['type']
            ?? ''
        )
    )
) ?>

<?php if (
    !empty($question['required'])
):
?>

・必須

<?php endif; ?>

</div>


<div class="question-text">

<?= h(
    $question['text']
    ?? ''
) ?>

</div>


<?php
$choices =
    $question['choices']
    ?? [];
?>


<?php if (
    is_array($choices)
    &&
    count($choices) > 0
):
?>


<?php foreach (
    $choices
    as $choice
):
?>

<div class="choice">

<?= h(
    $choice['label']
    ?? ''
) ?>

</div>

<?php endforeach; ?>


<?php endif; ?>


<div class="actions">


<details>

<summary class="button">
    編集
</summary>


<div
    class="card"
    style="margin-top:10px;"
>


<form
    method="post"
>

<input
    type="hidden"
    name="action"
    value="question_save"
>


<input
    type="hidden"
    name="questionId"
    value="<?= h(
        $question['questionId']
    ) ?>"
>


<div class="form-grid">


<label>

質問文

<textarea
    name="text"
    required
><?= h(
    $question['text']
    ?? ''
) ?></textarea>

</label>


<label>

質問形式

<select
    name="type"
>

<option
    value="text"
    <?= (
        ($question['type'] ?? '')
        === 'text'
    )
        ? 'selected'
        : ''
    ?>
>
    自由入力
</option>


<option
    value="single"
    <?= (
        ($question['type'] ?? '')
        === 'single'
    )
        ? 'selected'
        : ''
    ?>
>
    単一選択
</option>


<option
    value="multiple"
    <?= (
        ($question['type'] ?? '')
        === 'multiple'
    )
        ? 'selected'
        : ''
    ?>
>
    複数選択
</option>

</select>

</label>


<label>

選択肢

<textarea
    name="choices"
><?php

if (is_array($choices)) {

    echo h(
        implode(
            "\n",
            array_map(
                static function (
                    array $choice
                ): string {

                    return (string)(
                        $choice['label']
                        ?? ''
                    );
                },
                $choices
            )
        )
    );
}

?></textarea>

</label>


<label>

<input
    type="checkbox"
    name="required"
    value="1"
    <?= !empty(
        $question['required']
    )
        ? 'checked'
        : ''
    ?>
>

必須回答

</label>


<button
    type="submit"
>

質問を保存

</button>


</div>

</form>


</div>

</details>


<form
    method="post"
    class="inline"
    onsubmit="return confirm('この質問を削除しますか？');"
>

<input
    type="hidden"
    name="action"
    value="question_delete"
>


<input
    type="hidden"
    name="questionId"
    value="<?= h(
        $question['questionId']
    ) ?>"
>


<button
    type="submit"
    class="red"
>

削除

</button>

</form>


</div>


</div>


<?php endforeach; ?>


</div>


<?php endif; ?>


<?php
/* ============================================================
 * 未知画面
 * ============================================================
 */

else:
?>


<div class="error">

不明な画面です。

</div>


<a
    class="button"
    href="?screen=admin"
>

管理画面へ

</a>


<?php endif; ?>


</main>


</body>

</html>